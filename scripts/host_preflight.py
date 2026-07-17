#!/usr/bin/env python3

from __future__ import annotations

import argparse
import base64
import hashlib
import ipaddress
import json
import os
import re
import shutil
import socket
import ssl
import subprocess
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any, Mapping, Protocol, Sequence
from urllib.parse import quote, urlsplit


CONTRACT_VERSION = "1.0.0"
RECEIPT_VERSION = "1.0.0"
STATE_PORTS = {3306, 6379}
PRIVATE_MANAGEMENT_SUPERNETS = tuple(
  ipaddress.ip_network(cidr)
  for cidr in ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7"]
)
REQUIRED_SECRET_NAMES = {
  "APP_KEY",
  "BACKUP_CREDENTIALS",
  "BACKUP_ENCRYPTION_KEY",
  "DB_PASSWORD",
  "DB_ROOT_PASSWORD",
  "DOKPLOY_API_TOKEN",
  "REGISTRY_CREDENTIALS",
  "RESTORE_PROBE_CREDENTIALS",
  "VALKEY_PASSWORD",
}
LIVE_PROBE_SECRET_NAMES = {
  "dokploy": "DOKPLOY_API_TOKEN",
  "registry": "REGISTRY_CREDENTIALS",
  "backup": "BACKUP_CREDENTIALS",
  "backup.restore": "RESTORE_PROBE_CREDENTIALS",
}
PREFLIGHT_CHECK_IDS = frozenset({
  "host_inputs.contract", "secret_manifest.contract",
  "docker.cli", "docker.daemon", "docker.compose",
  "host.disk_free", "host.time_sync", "host.timezone",
  "public_endpoint.dns", "public_endpoint.tls",
  "management.dns", "management.tls",
  "dokploy.health", "dokploy.credentials", "dokploy.identity",
  "registry.dns", "registry.tls", "registry.credentials", "registry.repository_access",
  "backup.dns", "backup.tls", "backup.credentials", "backup.target_access",
  "backup.restore.dns", "backup.restore.tls", "backup.restore.credentials", "backup.restore_target_access",
})
SENSITIVE_KEY_RE = re.compile(r"(?:password|passwd|token|secret|credential|private[_-]?key)", re.IGNORECASE)
AUTHORIZATION_RE = re.compile(r"(?i)((?:proxy-)?authorization\s*:\s*(?:bearer|basic)\s+)[^\s,;]+")
API_KEY_RE = re.compile(r"(?i)((?:x-)?api[-_]?key\s*:\s*)[^\s,;]+")
COOKIE_RE = re.compile(r"(?i)((?:set-)?cookie\s*:\s*)[^\r\n]+")
URL_USERINFO_RE = re.compile(r"(https?://)[^/@\s]+@", re.IGNORECASE)
ASSIGNMENT_RE = re.compile(
  r"(?i)((?:password|passwd|token|secret|credential|private[_-]?key)\s*[=:]\s*)[^\s,;]+"
)


@dataclass(frozen=True)
class ValidationFailure:
  code: str
  path: str
  message: str

  def to_dict(self) -> dict[str, str]:
    return {"code": self.code, "path": self.path, "message": self.message}


@dataclass(frozen=True)
class Check:
  check_id: str
  status: str
  detail: str

  def to_dict(self) -> dict[str, str]:
    return {"id": self.check_id, "status": self.status, "detail": self.detail}


class ProbeBackend(Protocol):
  def command_available(self, command: str) -> bool:
    ...

  def run_command(self, argv: Sequence[str]) -> bool:
    ...

  def disk_free_bytes(self, path: str) -> int:
    ...

  def time_state(self) -> tuple[bool, str]:
    ...

  def resolve(self, hostname: str) -> bool:
    ...

  def tls_connect(self, hostname: str, port: int) -> bool:
    ...

  def http_head(self, url: str) -> int:
    ...

  def http_json(self, url: str, headers: Mapping[str, str]) -> tuple[int, Any]:
    ...


def _failure(failures: list[ValidationFailure], code: str, path: str, message: str) -> None:
  failures.append(ValidationFailure(code=code, path=path, message=message))


def _closed_object(
  value: Any,
  path: str,
  fields: set[str],
  failures: list[ValidationFailure],
) -> dict[str, Any]:
  if not isinstance(value, dict):
    _failure(failures, "invalid_type", path, "must be an object")
    return {}
  for field in sorted(fields - set(value)):
    _failure(failures, "missing_field", f"{path}.{field}", "required field is missing")
  for field in sorted(set(value) - fields):
    _failure(failures, "unknown_field", f"{path}.{field}", "field is not allowed by the closed contract")
  return value


def _nonempty_string(value: Any, path: str, failures: list[ValidationFailure]) -> bool:
  if isinstance(value, str) and value.strip():
    return True
  _failure(failures, "invalid_string", path, "must be a non-empty string")
  return False


def _identifier(value: Any, path: str, failures: list[ValidationFailure]) -> bool:
  if _nonempty_string(value, path, failures) and re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,63}", value):
    return True
  if isinstance(value, str) and value.strip():
    _failure(failures, "invalid_identifier", path, "must use 3-64 lowercase identifier characters")
  return False


def _positive_int(value: Any, path: str, failures: list[ValidationFailure]) -> bool:
  if type(value) is int and value > 0:
    return True
  _failure(failures, "invalid_integer", path, "must be a positive integer")
  return False


def _ports(value: Any, path: str, failures: list[ValidationFailure]) -> list[int]:
  if not isinstance(value, list) or not value:
    _failure(failures, "invalid_ports", path, "must be a non-empty port list")
    return []
  ports: list[int] = []
  for index, port in enumerate(value):
    if type(port) is not int or not 1 <= port <= 65535:
      _failure(failures, "invalid_port", f"{path}[{index}]", "must be an integer from 1 to 65535")
      continue
    ports.append(port)
  if len(ports) != len(set(ports)):
    _failure(failures, "duplicate_port", path, "must not contain duplicate ports")
  return ports


def _https_url(value: Any, path: str, failures: list[ValidationFailure], allow_path: bool = False) -> Any:
  if not _nonempty_string(value, path, failures):
    return None
  try:
    parsed = urlsplit(value)
    _ = parsed.port
  except ValueError:
    _failure(failures, "invalid_https_url", path, "must be a valid HTTPS URL")
    return None
  unsafe = (
    parsed.scheme != "https"
    or not parsed.hostname
    or parsed.username is not None
    or parsed.password is not None
    or bool(parsed.query)
    or bool(parsed.fragment)
    or (not allow_path and parsed.path not in {"", "/"})
  )
  if unsafe:
    _failure(failures, "invalid_https_url", path, "must be an HTTPS URL without credentials, query, or fragment")
    return None
  return parsed


def _absolute_path(value: Any, path: str, failures: list[ValidationFailure]) -> Any:
  if not _nonempty_string(value, path, failures):
    return None
  candidate = PurePosixPath(value)
  if not candidate.is_absolute() or ".." in candidate.parts:
    _failure(failures, "invalid_absolute_path", path, "must be a normalized absolute POSIX path")
    return None
  return candidate


def _safe_url_path(value: Any, path: str, failures: list[ValidationFailure]) -> bool:
  if isinstance(value, str) and value.startswith("/") and ".." not in value and "?" not in value and "#" not in value:
    return True
  _failure(failures, "invalid_probe_path", path, "must be an absolute URL path without traversal, query, or fragment")
  return False


def _json_pointer(value: Any, path: str, failures: list[ValidationFailure]) -> bool:
  if isinstance(value, str) and value.startswith("/") and len(value) > 1:
    return True
  _failure(failures, "invalid_json_pointer", path, "must be a non-root RFC 6901 JSON pointer")
  return False


def validate_host_inputs(document: Any) -> list[ValidationFailure]:
  failures: list[ValidationFailure] = []
  root = _closed_object(
    document,
    "$",
    {
      "contract_version",
      "environment",
      "host",
      "public_endpoint",
      "management",
      "registry",
      "dokploy",
      "storage",
      "backup",
      "network",
    },
    failures,
  )
  if not root:
    return failures

  if root.get("contract_version") != CONTRACT_VERSION:
    _failure(failures, "unsupported_contract_version", "$.contract_version", "must use the supported contract version")
  if root.get("environment") not in {"staging", "production"}:
    _failure(failures, "invalid_environment", "$.environment", "must be staging or production")

  host = _closed_object(root.get("host"), "$.host", {"id", "owner", "data_root", "minimum_free_gib", "timezone"}, failures)
  _identifier(host.get("id"), "$.host.id", failures)
  _identifier(host.get("owner"), "$.host.owner", failures)
  data_root = _absolute_path(host.get("data_root"), "$.host.data_root", failures)
  _positive_int(host.get("minimum_free_gib"), "$.host.minimum_free_gib", failures)
  _nonempty_string(host.get("timezone"), "$.host.timezone", failures)

  public_endpoint = _closed_object(root.get("public_endpoint"), "$.public_endpoint", {"origin", "ports"}, failures)
  _https_url(public_endpoint.get("origin"), "$.public_endpoint.origin", failures)
  endpoint_ports = _ports(public_endpoint.get("ports"), "$.public_endpoint.ports", failures)
  if endpoint_ports and set(endpoint_ports) != {443}:
    _failure(failures, "non_https_public_port", "$.public_endpoint.ports", "only HTTPS port 443 may be public")

  management = _closed_object(
    root.get("management"),
    "$.management",
    {"origin", "health_path", "allowed_cidrs", "ports"},
    failures,
  )
  _https_url(management.get("origin"), "$.management.origin", failures)
  health_path = management.get("health_path")
  if not isinstance(health_path, str) or not health_path.startswith("/") or ".." in health_path or "?" in health_path:
    _failure(failures, "invalid_health_path", "$.management.health_path", "must be an absolute URL path without traversal or query")
  allowed_cidrs = management.get("allowed_cidrs")
  if not isinstance(allowed_cidrs, list) or not allowed_cidrs:
    _failure(failures, "invalid_management_cidrs", "$.management.allowed_cidrs", "must contain at least one restricted CIDR")
  else:
    for index, cidr in enumerate(allowed_cidrs):
      path = f"$.management.allowed_cidrs[{index}]"
      try:
        network = ipaddress.ip_network(cidr, strict=True)
      except (TypeError, ValueError):
        _failure(failures, "invalid_management_cidr", path, "must be a canonical IPv4 or IPv6 CIDR")
        continue
      if not any(network.version == allowed.version and network.subnet_of(allowed) for allowed in PRIVATE_MANAGEMENT_SUPERNETS):
        _failure(failures, "public_management_cidr", path, "must not expose management to a public network")
  management_ports = _ports(management.get("ports"), "$.management.ports", failures)
  if STATE_PORTS.intersection(management_ports):
    _failure(failures, "state_port_management", "$.management.ports", "state-service ports must not be management ports")

  registry = _closed_object(root.get("registry"), "$.registry", {"hostname", "port", "repository"}, failures)
  hostname = registry.get("hostname")
  if not isinstance(hostname, str) or not re.fullmatch(r"[A-Za-z0-9.-]+", hostname) or "." not in hostname:
    _failure(failures, "invalid_registry_hostname", "$.registry.hostname", "must be a hostname without scheme or credentials")
  registry_port = registry.get("port")
  if type(registry_port) is not int or registry_port != 443:
    _failure(failures, "invalid_registry_port", "$.registry.port", "must use TLS port 443")
  repository = registry.get("repository")
  if not isinstance(repository, str) or not re.fullmatch(r"[a-z0-9]+(?:[._/-][a-z0-9]+)*", repository):
    _failure(failures, "invalid_registry_repository", "$.registry.repository", "must be a lowercase registry repository path")

  dokploy = _closed_object(
    root.get("dokploy"),
    "$.dokploy",
    {"project", "compose_name", "probe_path", "project_json_pointer", "compose_json_pointer"},
    failures,
  )
  _identifier(dokploy.get("project"), "$.dokploy.project", failures)
  _identifier(dokploy.get("compose_name"), "$.dokploy.compose_name", failures)
  _safe_url_path(dokploy.get("probe_path"), "$.dokploy.probe_path", failures)
  _json_pointer(dokploy.get("project_json_pointer"), "$.dokploy.project_json_pointer", failures)
  _json_pointer(dokploy.get("compose_json_pointer"), "$.dokploy.compose_json_pointer", failures)

  storage = _closed_object(
    root.get("storage"),
    "$.storage",
    {"mysql_volume", "valkey_volume", "artifacts_volume"},
    failures,
  )
  storage_paths = []
  for field in ["mysql_volume", "valkey_volume", "artifacts_volume"]:
    candidate = _absolute_path(storage.get(field), f"$.storage.{field}", failures)
    if candidate is not None:
      storage_paths.append(candidate)
      if data_root is not None:
        try:
          candidate.relative_to(data_root)
        except ValueError:
          _failure(failures, "volume_outside_data_root", f"$.storage.{field}", "must be located below host.data_root")
  if len(storage_paths) != len(set(storage_paths)):
    _failure(failures, "duplicate_volume_path", "$.storage", "volume paths must be distinct")

  backup = _closed_object(
    root.get("backup"),
    "$.backup",
    {
      "endpoint",
      "probe_path",
      "identity_json_pointer",
      "target_id",
      "restore_endpoint",
      "restore_probe_path",
      "restore_identity_json_pointer",
      "restore_target_id",
    },
    failures,
  )
  backup_endpoint = backup.get("endpoint")
  parsed_backup = _https_url(backup_endpoint, "$.backup.endpoint", failures, allow_path=True)
  if parsed_backup is None:
    existing = [failure for failure in failures if failure.path == "$.backup.endpoint"]
    if existing:
      failures[:] = [failure for failure in failures if failure.path != "$.backup.endpoint"]
      _failure(
        failures,
        "unsafe_backup_endpoint",
        "$.backup.endpoint",
        "must be a remote HTTPS endpoint without embedded credentials or query values",
      )
  _safe_url_path(backup.get("probe_path"), "$.backup.probe_path", failures)
  _json_pointer(backup.get("identity_json_pointer"), "$.backup.identity_json_pointer", failures)
  restore_endpoint = backup.get("restore_endpoint")
  parsed_restore = _https_url(restore_endpoint, "$.backup.restore_endpoint", failures, allow_path=True)
  if parsed_restore is None:
    existing = [failure for failure in failures if failure.path == "$.backup.restore_endpoint"]
    if existing:
      failures[:] = [failure for failure in failures if failure.path != "$.backup.restore_endpoint"]
      _failure(
        failures,
        "unsafe_restore_endpoint",
        "$.backup.restore_endpoint",
        "must be a remote HTTPS endpoint without embedded credentials or query values",
      )
  _safe_url_path(backup.get("restore_probe_path"), "$.backup.restore_probe_path", failures)
  _json_pointer(backup.get("restore_identity_json_pointer"), "$.backup.restore_identity_json_pointer", failures)
  _identifier(backup.get("target_id"), "$.backup.target_id", failures)
  _identifier(backup.get("restore_target_id"), "$.backup.restore_target_id", failures)
  host_id = host.get("id")
  target_id = backup.get("target_id")
  restore_target_id = backup.get("restore_target_id")
  if isinstance(host_id, str) and target_id == host_id:
    _failure(failures, "backup_target_not_separate", "$.backup.target_id", "must identify storage separate from the production host")
  if isinstance(host_id, str) and restore_target_id == host_id:
    _failure(failures, "restore_target_not_separate", "$.backup.restore_target_id", "must identify a restore host separate from production")
  if isinstance(target_id, str) and restore_target_id == target_id:
    _failure(failures, "restore_target_not_independent", "$.backup.restore_target_id", "must differ from the backup storage target")
  public_url = urlsplit(public_endpoint.get("origin", ""))
  management_url = urlsplit(management.get("origin", ""))
  protected_hosts = {public_url.hostname, management_url.hostname, hostname}
  protected_hosts.discard(None)
  if parsed_backup is not None and parsed_backup.hostname in protected_hosts:
    _failure(
      failures,
      "backup_endpoint_not_independent",
      "$.backup.endpoint",
      "must use a host independent from public, management, and registry endpoints",
    )
  if parsed_restore is not None and (
    parsed_restore.hostname in protected_hosts
    or (parsed_backup is not None and parsed_restore.hostname == parsed_backup.hostname)
  ):
    _failure(
      failures,
      "restore_endpoint_not_independent",
      "$.backup.restore_endpoint",
      "must use a host independent from production and backup endpoints",
    )

  network = _closed_object(root.get("network"), "$.network", {"public_ports", "state_ports"}, failures)
  public_ports = _ports(network.get("public_ports"), "$.network.public_ports", failures)
  state_ports = _ports(network.get("state_ports"), "$.network.state_ports", failures)
  if public_ports and set(public_ports) != {443}:
    _failure(failures, "non_https_public_port", "$.network.public_ports", "only HTTPS port 443 may be public")
  if public_ports and endpoint_ports and set(public_ports) != set(endpoint_ports):
    _failure(failures, "public_port_mismatch", "$.network.public_ports", "must match public_endpoint.ports")
  if set(public_ports).intersection(state_ports):
    _failure(failures, "state_port_public", "$.network.public_ports", "state-service ports must never be public")
  if state_ports and set(state_ports) != STATE_PORTS:
    _failure(failures, "state_port_contract", "$.network.state_ports", "must declare the internal MySQL and Valkey ports")

  return failures


def validate_secret_manifest(document: Any) -> list[ValidationFailure]:
  failures: list[ValidationFailure] = []
  root = _closed_object(document, "$", {"manifest_version", "secrets"}, failures)
  if not root:
    return failures
  if root.get("manifest_version") != CONTRACT_VERSION:
    _failure(failures, "unsupported_manifest_version", "$.manifest_version", "must use the supported manifest version")
  secrets = root.get("secrets")
  if not isinstance(secrets, list) or not secrets:
    _failure(failures, "invalid_secret_manifest", "$.secrets", "must list required secret names and owners")
    return failures

  names: list[str] = []
  for index, item in enumerate(secrets):
    path = f"$.secrets[{index}]"
    secret = _closed_object(item, path, {"name", "owner", "source"}, failures)
    name = secret.get("name")
    if not isinstance(name, str) or not re.fullmatch(r"[A-Z][A-Z0-9_]{2,63}", name):
      _failure(failures, "invalid_secret_name", f"{path}.name", "must be an uppercase environment variable name")
    else:
      names.append(name)
    _identifier(secret.get("owner"), f"{path}.owner", failures)
    if secret.get("source") not in {"dokploy", "host"}:
      _failure(failures, "invalid_secret_source", f"{path}.source", "must be dokploy or host")
  if len(names) != len(set(names)):
    _failure(failures, "duplicate_secret_name", "$.secrets", "must not repeat secret names")
  missing = REQUIRED_SECRET_NAMES - set(names)
  extra = set(names) - REQUIRED_SECRET_NAMES
  for name in sorted(missing):
    _failure(failures, "missing_secret_name", "$.secrets", f"required secret name {name} is missing")
  for name in sorted(extra):
    _failure(failures, "unexpected_secret_name", "$.secrets", "a secret name is not part of this closed contract")
  return failures


def _is_sensitive_key(key: str) -> bool:
  if key == "secret_values_included":
    return False
  return bool(SENSITIVE_KEY_RE.search(key))


def redact(value: Any, key: str = "") -> Any:
  if key and _is_sensitive_key(key):
    return "<redacted>"
  if isinstance(value, dict):
    return {item_key: redact(item_value, item_key) for item_key, item_value in value.items()}
  if isinstance(value, list):
    return [redact(item) for item in value]
  if isinstance(value, str):
    redacted = AUTHORIZATION_RE.sub(r"\1<redacted>", value)
    redacted = API_KEY_RE.sub(r"\1<redacted>", redacted)
    redacted = COOKIE_RE.sub(r"\1<redacted>", redacted)
    redacted = URL_USERINFO_RE.sub(r"\1<redacted>@", redacted)
    return ASSIGNMENT_RE.sub(r"\1<redacted>", redacted)
  return value


class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
  def redirect_request(self, req, fp, code, msg, headers, newurl):
    return None


class SystemProbeBackend:
  def command_available(self, command: str) -> bool:
    return shutil.which(command) is not None

  def run_command(self, argv: Sequence[str]) -> bool:
    try:
      result = subprocess.run(
        list(argv),
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=15,
        check=False,
      )
      return result.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
      return False

  def disk_free_bytes(self, path: str) -> int:
    return shutil.disk_usage(path).free

  def time_state(self) -> tuple[bool, str]:
    if not self.command_available("timedatectl"):
      return False, "unknown"
    try:
      synchronized = subprocess.run(
        ["timedatectl", "show", "--property=NTPSynchronized", "--value"],
        capture_output=True,
        text=True,
        timeout=10,
        check=False,
      )
      timezone_result = subprocess.run(
        ["timedatectl", "show", "--property=Timezone", "--value"],
        capture_output=True,
        text=True,
        timeout=10,
        check=False,
      )
    except (OSError, subprocess.TimeoutExpired):
      return False, "unknown"
    return (
      synchronized.returncode == 0 and synchronized.stdout.strip().lower() == "yes",
      timezone_result.stdout.strip() if timezone_result.returncode == 0 else "unknown",
    )

  def resolve(self, hostname: str) -> bool:
    try:
      socket.getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
      return True
    except OSError:
      return False

  def tls_connect(self, hostname: str, port: int) -> bool:
    try:
      context = ssl.create_default_context()
      with socket.create_connection((hostname, port), timeout=10) as raw_socket:
        with context.wrap_socket(raw_socket, server_hostname=hostname):
          return True
    except (OSError, ssl.SSLError):
      return False

  def http_head(self, url: str) -> int:
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "cluster-host-preflight/1"})
    opener = urllib.request.build_opener(_NoRedirectHandler())
    try:
      with opener.open(request, timeout=10) as response:
        return int(response.status)
    except urllib.error.HTTPError as error:
      return int(error.code)
    except (OSError, urllib.error.URLError):
      return 0

  def http_json(self, url: str, headers: Mapping[str, str]) -> tuple[int, Any]:
    safe_headers = {"Accept": "application/json", "User-Agent": "cluster-host-preflight/1", **dict(headers)}
    request = urllib.request.Request(url, method="GET", headers=safe_headers)
    opener = urllib.request.build_opener(_NoRedirectHandler())
    try:
      with opener.open(request, timeout=10) as response:
        if int(response.status) != 200:
          return int(response.status), {}
        payload = response.read(65537)
        if len(payload) > 65536:
          return 0, {}
        return 200, json.loads(payload.decode("utf-8"))
    except urllib.error.HTTPError as error:
      return int(error.code), {}
    except (OSError, UnicodeError, ValueError, urllib.error.URLError):
      return 0, {}


def _check(check_id: str, passed: bool, passed_detail: str, failed_detail: str) -> Check:
  return Check(check_id=check_id, status="passed" if passed else "failed", detail=passed_detail if passed else failed_detail)


def _safe_probe(callback, default):
  try:
    return callback()
  except Exception:
    return default


def _json_pointer_get(document: Any, pointer: str) -> tuple[bool, Any]:
  current = document
  for encoded_part in pointer.lstrip("/").split("/"):
    part = encoded_part.replace("~1", "/").replace("~0", "~")
    if isinstance(current, dict) and part in current:
      current = current[part]
      continue
    if isinstance(current, list) and part.isdigit() and int(part) < len(current):
      current = current[int(part)]
      continue
    return False, None
  return True, current


def run_preflight(
  host_inputs: dict[str, Any],
  backend: ProbeBackend | None = None,
  secrets: Mapping[str, str] | None = None,
) -> list[Check]:
  probes = backend or SystemProbeBackend()
  runtime_secrets = dict(secrets) if secrets is not None else {
    name: os.environ.get(name, "") for name in LIVE_PROBE_SECRET_NAMES.values()
  }
  checks: list[Check] = []

  docker_available = _safe_probe(lambda: probes.command_available("docker"), False)
  checks.append(_check("docker.cli", docker_available, "Docker CLI is available", "Docker CLI is unavailable"))
  docker_daemon = docker_available and _safe_probe(
    lambda: probes.run_command(["docker", "info", "--format", "{{json .ServerVersion}}"]),
    False,
  )
  checks.append(_check("docker.daemon", docker_daemon, "Docker daemon answered a read-only query", "Docker daemon did not answer the read-only query"))
  compose_available = docker_available and _safe_probe(
    lambda: probes.run_command(["docker", "compose", "version", "--short"]),
    False,
  )
  checks.append(_check("docker.compose", compose_available, "Docker Compose plugin is available", "Docker Compose plugin is unavailable"))

  minimum_free_gib = host_inputs["host"]["minimum_free_gib"]
  free_bytes = _safe_probe(lambda: probes.disk_free_bytes(host_inputs["host"]["data_root"]), -1)
  minimum_free_bytes = minimum_free_gib * 1024**3
  disk_ok = free_bytes >= minimum_free_bytes
  actual_free_gib = max(free_bytes, 0) // 1024**3
  checks.append(
    _check(
      "host.disk_free",
      disk_ok,
      f"data root has {actual_free_gib} GiB free",
      f"data root has less than the required {minimum_free_gib} GiB free",
    )
  )

  synchronized, actual_timezone = _safe_probe(probes.time_state, (False, "unknown"))
  checks.append(_check("host.time_sync", synchronized, "host clock reports synchronized", "host clock does not report synchronized"))
  timezone_ok = actual_timezone == host_inputs["host"]["timezone"]
  checks.append(_check("host.timezone", timezone_ok, "host timezone matches the contract", "host timezone does not match the contract"))

  def endpoint_checks(prefix: str, hostname: str, port: int) -> tuple[bool, bool]:
    dns_ok = _safe_probe(lambda: probes.resolve(hostname), False)
    checks.append(_check(f"{prefix}.dns", dns_ok, "hostname resolves", "hostname does not resolve"))
    tls_ok = dns_ok and _safe_probe(lambda: probes.tls_connect(hostname, port), False)
    checks.append(_check(f"{prefix}.tls", tls_ok, "verified TLS connection succeeded", "verified TLS connection failed"))
    return dns_ok, tls_ok

  public_url = urlsplit(host_inputs["public_endpoint"]["origin"])
  endpoint_checks("public_endpoint", public_url.hostname or "", public_url.port or 443)

  management_url = urlsplit(host_inputs["management"]["origin"])
  _, management_tls = endpoint_checks("management", management_url.hostname or "", management_url.port or 443)
  health_url = host_inputs["management"]["origin"].rstrip("/") + host_inputs["management"]["health_path"]
  management_status = _safe_probe(lambda: probes.http_head(health_url), 0) if management_tls else 0
  checks.append(
    _check(
      "dokploy.health",
      management_status == 200,
      "Dokploy health endpoint answered the read-only request",
      "Dokploy health endpoint did not answer successfully",
    )
  )

  def credential_check(prefix: str, secret_name: str, require_basic_pair: bool = False) -> tuple[bool, str]:
    value = runtime_secrets.get(secret_name, "")
    valid = bool(value) and "\n" not in value and "\r" not in value
    if require_basic_pair:
      valid = valid and ":" in value
    checks.append(
      _check(
        f"{prefix}.credentials",
        valid,
        "runtime read-only probe credential is available",
        "runtime read-only probe credential is missing or malformed",
      )
    )
    return valid, value

  dokploy_credentials_ok, dokploy_token = credential_check("dokploy", LIVE_PROBE_SECRET_NAMES["dokploy"])
  dokploy_url = host_inputs["management"]["origin"].rstrip("/") + host_inputs["dokploy"]["probe_path"]
  dokploy_status, dokploy_document = (
    _safe_probe(
      lambda: probes.http_json(dokploy_url, {"Authorization": f"Bearer {dokploy_token}"}),
      (0, {}),
    )
    if management_tls and dokploy_credentials_ok
    else (0, {})
  )
  project_found, project_value = _json_pointer_get(dokploy_document, host_inputs["dokploy"]["project_json_pointer"])
  compose_found, compose_value = _json_pointer_get(dokploy_document, host_inputs["dokploy"]["compose_json_pointer"])
  dokploy_identity_ok = (
    dokploy_status == 200
    and project_found
    and project_value == host_inputs["dokploy"]["project"]
    and compose_found
    and compose_value == host_inputs["dokploy"]["compose_name"]
  )
  checks.append(
    _check(
      "dokploy.identity",
      dokploy_identity_ok,
      "authenticated read-only response matched the Dokploy project and Compose identity",
      "authenticated Dokploy identity did not match the contract",
    )
  )

  _, registry_tls = endpoint_checks("registry", host_inputs["registry"]["hostname"], host_inputs["registry"]["port"])
  registry_credentials_ok, registry_credentials = credential_check(
    "registry",
    LIVE_PROBE_SECRET_NAMES["registry"],
    require_basic_pair=True,
  )
  encoded_registry_credentials = base64.b64encode(registry_credentials.encode("utf-8")).decode("ascii") if registry_credentials_ok else ""
  repository = host_inputs["registry"]["repository"]
  registry_url = (
    f"https://{host_inputs['registry']['hostname']}"
    f"/v2/{quote(repository, safe='/')}/tags/list?n=1"
  )
  registry_status, registry_document = (
    _safe_probe(
      lambda: probes.http_json(registry_url, {"Authorization": f"Basic {encoded_registry_credentials}"}),
      (0, {}),
    )
    if registry_tls and registry_credentials_ok
    else (0, {})
  )
  registry_access_ok = registry_status == 200 and isinstance(registry_document, dict) and registry_document.get("name") == repository
  checks.append(
    _check(
      "registry.repository_access",
      registry_access_ok,
      "authenticated read-only registry response matched the repository",
      "authenticated registry repository access was not proved",
    )
  )

  backup_url = urlsplit(host_inputs["backup"]["endpoint"])
  _, backup_tls = endpoint_checks("backup", backup_url.hostname or "", backup_url.port or 443)
  backup_credentials_ok, backup_credentials = credential_check("backup", LIVE_PROBE_SECRET_NAMES["backup"])
  backup_probe_url = host_inputs["backup"]["endpoint"].rstrip("/") + host_inputs["backup"]["probe_path"]
  backup_status, backup_document = (
    _safe_probe(
      lambda: probes.http_json(backup_probe_url, {"Authorization": f"Bearer {backup_credentials}"}),
      (0, {}),
    )
    if backup_tls and backup_credentials_ok
    else (0, {})
  )
  backup_identity_found, backup_identity = _json_pointer_get(
    backup_document,
    host_inputs["backup"]["identity_json_pointer"],
  )
  backup_access_ok = backup_status == 200 and backup_identity_found and backup_identity == host_inputs["backup"]["target_id"]
  checks.append(
    _check(
      "backup.target_access",
      backup_access_ok,
      "authenticated read-only response matched the backup target identity",
      "authenticated backup target access was not proved",
    )
  )

  restore_url = urlsplit(host_inputs["backup"]["restore_endpoint"])
  _, restore_tls = endpoint_checks("backup.restore", restore_url.hostname or "", restore_url.port or 443)
  restore_credentials_ok, restore_credentials = credential_check(
    "backup.restore",
    LIVE_PROBE_SECRET_NAMES["backup.restore"],
  )
  restore_probe_url = host_inputs["backup"]["restore_endpoint"].rstrip("/") + host_inputs["backup"]["restore_probe_path"]
  restore_status, restore_document = (
    _safe_probe(
      lambda: probes.http_json(restore_probe_url, {"Authorization": f"Bearer {restore_credentials}"}),
      (0, {}),
    )
    if restore_tls and restore_credentials_ok
    else (0, {})
  )
  restore_identity_found, restore_identity = _json_pointer_get(
    restore_document,
    host_inputs["backup"]["restore_identity_json_pointer"],
  )
  restore_access_ok = (
    restore_status == 200
    and restore_identity_found
    and restore_identity == host_inputs["backup"]["restore_target_id"]
  )
  checks.append(
    _check(
      "backup.restore_target_access",
      restore_access_ok,
      "authenticated read-only response matched the independent restore target identity",
      "authenticated independent restore-target access was not proved",
    )
  )
  return checks


def _canonical_json(value: Any) -> bytes:
  return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _validation_checks(prefix: str, failures: list[ValidationFailure]) -> list[Check]:
  if not failures:
    return [Check(check_id=f"{prefix}.contract", status="passed", detail="closed non-secret contract is valid")]
  return [
    Check(
      check_id=f"{prefix}.{failure.code}.{index}",
      status="failed",
      detail=f"{failure.path}: {failure.message}",
    )
    for index, failure in enumerate(failures, start=1)
  ]


def build_receipt(mode: str, host_inputs: dict[str, Any], checks: list[Check]) -> dict[str, Any]:
  failed = sum(check.status == "failed" for check in checks)
  revision_candidate = os.environ.get("CI_COMMIT_SHA", "")
  source_revision = revision_candidate if re.fullmatch(r"[0-9a-fA-F]{40,64}", revision_candidate) else "local-unverified"
  receipt: dict[str, Any] = {
    "receipt_version": RECEIPT_VERSION,
    "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    "mode": mode,
    "source_revision": source_revision,
    "input_sha256": hashlib.sha256(_canonical_json(host_inputs)).hexdigest(),
    "target": {
      "environment": host_inputs.get("environment", "unknown"),
      "host_id": host_inputs.get("host", {}).get("id", "unknown") if isinstance(host_inputs.get("host"), dict) else "unknown",
    },
    "summary": {
      "status": "failed" if failed else "passed",
      "passed": len(checks) - failed,
      "failed": failed,
    },
    "checks": [check.to_dict() for check in checks],
    "redaction": {
      "policy": "sensitive keys, authorization, API keys, cookies, URL userinfo, and secret assignments",
      "secret_values_included": False,
    },
    "signature": {
      "status": "not-signed",
      "reason": "live staging-host acceptance signs this digest outside Git",
    },
  }
  receipt = redact(receipt)
  receipt["receipt_sha256"] = hashlib.sha256(_canonical_json(receipt)).hexdigest()
  return receipt


def _load_json(path: Path) -> Any:
  try:
    return json.loads(path.read_text(encoding="utf-8"))
  except OSError as error:
    raise ValueError(f"cannot read {path}: {error.strerror or 'I/O error'}") from error
  except json.JSONDecodeError as error:
    raise ValueError(f"invalid JSON in {path} at line {error.lineno}") from error


def _write_receipt(path: Path, receipt: dict[str, Any]) -> None:
  path.parent.mkdir(parents=True, exist_ok=True)
  path.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
  path.chmod(0o600)


def _parser() -> argparse.ArgumentParser:
  parser = argparse.ArgumentParser(
    description="Validate non-secret host inputs and run a read-only W1.1 host preflight.",
  )
  subparsers = parser.add_subparsers(dest="mode", required=True)
  for mode in ["validate", "preflight"]:
    command = subparsers.add_parser(mode)
    command.add_argument("--inputs", required=True, type=Path, help="Non-secret host input JSON outside Git for live environments.")
    command.add_argument("--secrets", required=True, type=Path, help="Required secret-name manifest; values are forbidden.")
    command.add_argument("--receipt", type=Path, help="Optional receipt output. Parent directories are created explicitly.")
  return parser


def main(argv: Sequence[str] | None = None) -> int:
  args = _parser().parse_args(argv)
  try:
    host_inputs = _load_json(args.inputs)
    secret_manifest = _load_json(args.secrets)
  except ValueError as error:
    print(f"ERROR: {error}", file=sys.stderr)
    return 2

  host_failures = validate_host_inputs(host_inputs)
  secret_failures = validate_secret_manifest(secret_manifest)
  checks = _validation_checks("host_inputs", host_failures) + _validation_checks("secret_manifest", secret_failures)
  if args.mode == "preflight" and not host_failures and not secret_failures:
    checks.extend(run_preflight(host_inputs))

  receipt = build_receipt(args.mode, host_inputs if isinstance(host_inputs, dict) else {}, checks)
  if args.receipt:
    _write_receipt(args.receipt, receipt)
    print(f"Receipt written to {args.receipt}")
  else:
    print(json.dumps(receipt, ensure_ascii=False, indent=2))

  if receipt["summary"]["status"] != "passed":
    print(f"FAIL: host {args.mode} reported {receipt['summary']['failed']} failed check(s).", file=sys.stderr)
    return 1
  print(f"PASS: host {args.mode} completed with a redacted receipt.")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
