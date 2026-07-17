#!/usr/bin/env python3
"""Fail-closed W11-NET-04 policy and read-only exposure verification.

This module deliberately separates static Compose policy checks from evidence
gathered on a real host or a real edge-probe vantage point.  The CLI has no
fixture or observation-input mode: a successful live receipt can only result
from the read-only commands and network probes in ``SystemCommandBackend`` and
``SystemEndpointBackend``.
"""

from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import re
import shutil
import socket
import ssl
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping, Protocol, Sequence
from urllib.parse import urlsplit

import yaml


CONTRACT_VERSION = "1.0.0"
RECEIPT_VERSION = "1.1.0"
VERIFIER_VERSION = "1.1.0"
TASK_ID = "W11-NET-04"
EXPECTED_SERVICES = {"web", "api", "worker", "scheduler", "migrate", "mysql", "valkey"}
PRIVATE_MANAGEMENT_SUPERNETS = tuple(
  ipaddress.ip_network(value)
  for value in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7")
)
MANAGEMENT_MIN_PREFIX = {4: 24, 6: 64}
SENSITIVE_KEY_RE = re.compile(r"(?:password|passwd|token|secret|credential|private[_-]?key)", re.IGNORECASE)
PORT_BINDING_RE = re.compile(
  r"(?P<host>\[[^]]+\]|[^\s,]+):(?P<host_port>\d+)->(?P<container_port>\d+)/(?P<protocol>tcp|udp)"
)
EXPOSED_ONLY_RE = re.compile(r"^\d+/(?:tcp|udp)$")
DOCKER_SOCKET_RE = re.compile(r"(?:^|/)docker\.sock$")
SOCKET_PATH_RE = re.compile(r"(?:^|/)[^/]+\.sock$")


@dataclass(frozen=True)
class PublishedBinding:
  container_id: str
  host: str
  host_port: int
  container_port: int
  protocol: str


@dataclass(frozen=True)
class Failure:
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


@dataclass(frozen=True)
class CommandResult:
  returncode: int
  stdout: str


class CommandBackend(Protocol):
  def run(self, argv: Sequence[str]) -> CommandResult:
    ...


class EndpointBackend(Protocol):
  def source_addresses(self, hostname: str, port: int) -> list[str]:
    ...

  def source_address(self, hostname: str, port: int) -> str | None:
    ...

  def tcp_states(self, hostname: str, port: int) -> list[str]:
    ...

  def tcp_state(self, hostname: str, port: int) -> str:
    ...

  def https_statuses(self, origin: str, path: str) -> list[int]:
    ...

  def https_status(self, origin: str, path: str) -> int:
    ...


class SystemCommandBackend:
  """Runs only the fixed read-only host inventory commands used below."""

  def run(self, argv: Sequence[str]) -> CommandResult:
    try:
      result = subprocess.run(
        list(argv),
        stdin=subprocess.DEVNULL,
        capture_output=True,
        text=True,
        timeout=15,
        check=False,
      )
      return CommandResult(returncode=result.returncode, stdout=result.stdout[:1_000_000])
    except (OSError, subprocess.TimeoutExpired):
      return CommandResult(returncode=1, stdout="")


class SystemEndpointBackend:
  """Performs unauthenticated, read-only TCP and HTTPS reachability probes."""

  def _addresses(self, hostname: str, port: int) -> list[tuple[int, tuple[Any, ...]]]:
    try:
      resolved = socket.getaddrinfo(hostname, port, type=socket.SOCK_STREAM)
    except OSError:
      return []
    addresses: list[tuple[int, tuple[Any, ...]]] = []
    seen: set[tuple[int, tuple[Any, ...]]] = set()
    for family, _, _, _, sockaddr in resolved:
      key = (family, tuple(sockaddr))
      if key not in seen:
        seen.add(key)
        addresses.append((family, tuple(sockaddr)))
    return addresses

  def source_addresses(self, hostname: str, port: int) -> list[str]:
    sources: list[str] = []
    addresses = self._addresses(hostname, port)
    if not addresses:
      return []
    for family, sockaddr in addresses:
      try:
        with socket.socket(family, socket.SOCK_DGRAM) as probe:
          probe.connect(sockaddr)
          address = str(probe.getsockname()[0])
          if address not in sources:
            sources.append(address)
      except OSError:
        return []
    return sources

  def source_address(self, hostname: str, port: int) -> str | None:
    addresses = self.source_addresses(hostname, port)
    return addresses[0] if addresses else None

  def tcp_states(self, hostname: str, port: int) -> list[str]:
    states: list[str] = []
    for family, sockaddr in self._addresses(hostname, port):
      try:
        with socket.socket(family, socket.SOCK_STREAM) as connection:
          connection.settimeout(8)
          connection.connect(sockaddr)
          states.append("open")
      except ConnectionRefusedError:
        states.append("closed")
      except socket.timeout:
        states.append("filtered")
      except OSError:
        states.append("unreachable")
    return states

  def tcp_state(self, hostname: str, port: int) -> str:
    states = self.tcp_states(hostname, port)
    return states[0] if states else "unreachable"

  def _https_status_address(self, hostname: str, family: int, sockaddr: tuple[Any, ...], path: str) -> int:
    context = ssl.create_default_context()
    try:
      with socket.socket(family, socket.SOCK_STREAM) as raw:
        raw.settimeout(10)
        raw.connect(sockaddr)
        with context.wrap_socket(raw, server_hostname=hostname) as tls:
          request = (
            f"HEAD {path} HTTP/1.1\r\n"
            f"Host: {hostname}\r\n"
            "User-Agent: cluster-net04-exposure-verifier/1\r\n"
            "Connection: close\r\n\r\n"
          ).encode("ascii")
          tls.sendall(request)
          response = tls.recv(4096).split(b"\r\n", 1)[0]
          match = re.match(rb"HTTP/\d(?:\.\d)?\s+(\d{3})", response)
          return int(match.group(1)) if match else 0
    except (OSError, ssl.SSLError):
      return 0

  def https_statuses(self, origin: str, path: str) -> list[int]:
    parsed = urlsplit(origin)
    hostname = parsed.hostname
    if not hostname:
      return []
    statuses: list[int] = []
    for family, sockaddr in self._addresses(hostname, parsed.port or 443):
      statuses.append(self._https_status_address(hostname, family, sockaddr, parsed.path.rstrip("/") + path))
    return statuses

  def https_status(self, origin: str, path: str) -> int:
    statuses = self.https_statuses(origin, path)
    return statuses[0] if statuses else 0


def _failure(failures: list[Failure], code: str, path: str, message: str) -> None:
  failures.append(Failure(code=code, path=path, message=message))


def _closed_object(value: Any, path: str, fields: set[str], failures: list[Failure]) -> dict[str, Any]:
  if not isinstance(value, dict):
    _failure(failures, "invalid_type", path, "must be an object")
    return {}
  for field in sorted(fields - set(value)):
    _failure(failures, "missing_field", f"{path}.{field}", "required field is missing")
  for field in sorted(set(value) - fields):
    _failure(failures, "unknown_field", f"{path}.{field}", "field is not allowed by the closed contract")
  return value


def _identifier(value: Any, path: str, failures: list[Failure]) -> bool:
  if isinstance(value, str) and re.fullmatch(r"[a-z0-9][a-z0-9._-]{1,63}", value):
    return True
  _failure(failures, "invalid_identifier", path, "must be a lowercase identifier")
  return False


def _ports(value: Any, path: str, failures: list[Failure]) -> list[int]:
  if not isinstance(value, list) or not value:
    _failure(failures, "invalid_ports", path, "must be a non-empty list of TCP ports")
    return []
  parsed: list[int] = []
  for index, port in enumerate(value):
    if type(port) is not int or not 1 <= port <= 65535:
      _failure(failures, "invalid_port", f"{path}[{index}]", "must be an integer from 1 to 65535")
      continue
    parsed.append(port)
  if len(parsed) != len(set(parsed)):
    _failure(failures, "duplicate_port", path, "must not contain duplicate ports")
  return parsed


def _https_origin(value: Any, path: str, failures: list[Failure]) -> tuple[str, int] | None:
  if not isinstance(value, str):
    _failure(failures, "invalid_https_origin", path, "must be an HTTPS origin")
    return None
  try:
    parsed = urlsplit(value)
    port = parsed.port or 443
  except ValueError:
    _failure(failures, "invalid_https_origin", path, "must be a valid HTTPS origin")
    return None
  if (
    parsed.scheme != "https"
    or not parsed.hostname
    or parsed.username is not None
    or parsed.password is not None
    or parsed.query
    or parsed.fragment
    or parsed.path not in {"", "/"}
    or port != 443
  ):
    _failure(failures, "invalid_https_origin", path, "must be a credential-free HTTPS origin on port 443")
    return None
  return parsed.hostname, port


def _private_cidrs(value: Any, path: str, failures: list[Failure]) -> list[ipaddress._BaseNetwork]:
  if not isinstance(value, list) or not value:
    _failure(failures, "invalid_management_cidrs", path, "must list at least one restricted CIDR")
    return []
  parsed: list[ipaddress._BaseNetwork] = []
  for index, cidr in enumerate(value):
    item_path = f"{path}[{index}]"
    try:
      network = ipaddress.ip_network(cidr, strict=True)
    except (TypeError, ValueError):
      _failure(failures, "invalid_management_cidr", item_path, "must be a canonical IPv4 or IPv6 CIDR")
      continue
    if not any(network.version == allowed.version and network.subnet_of(allowed) for allowed in PRIVATE_MANAGEMENT_SUPERNETS):
      _failure(failures, "public_management_cidr", item_path, "must be contained in RFC1918 or IPv6 ULA space")
      continue
    if network.prefixlen < MANAGEMENT_MIN_PREFIX[network.version]:
      bound = f"/{MANAGEMENT_MIN_PREFIX[network.version]}"
      _failure(failures, "management_cidr_too_broad", item_path, f"management CIDR must be {bound} or narrower")
      continue
    parsed.append(network)
  if len(parsed) != len(set(parsed)):
    _failure(failures, "duplicate_management_cidr", path, "must not contain duplicate CIDRs")
  return parsed


def _contains_sensitive_key(value: Any, path: str, failures: list[Failure]) -> None:
  if isinstance(value, dict):
    for key, item in value.items():
      item_path = f"{path}.{key}"
      if SENSITIVE_KEY_RE.search(key):
        _failure(failures, "sensitive_field", item_path, "secrets and credentials are not allowed in this policy")
      _contains_sensitive_key(item, item_path, failures)
  elif isinstance(value, list):
    for index, item in enumerate(value):
      _contains_sensitive_key(item, f"{path}[{index}]", failures)


def validate_policy(document: Any) -> list[Failure]:
  """Validate the non-secret NET-04 policy without contacting a host."""
  failures: list[Failure] = []
  root = _closed_object(
    document,
    "$",
    {"contract_version", "compose", "firewall", "forbidden_public", "endpoints"},
    failures,
  )
  if not root:
    return failures
  _contains_sensitive_key(root, "$", failures)
  if root.get("contract_version") != CONTRACT_VERSION:
    _failure(failures, "unsupported_contract_version", "$.contract_version", "must use the supported contract version")

  compose = _closed_object(
    root.get("compose"),
    "$.compose",
    {
      "project",
      "frontend_network",
      "state_network",
      "runtime_frontend_network",
      "runtime_state_network",
      "proxy_service",
      "proxy_loopback_tcp_ports",
      "service_networks",
      "required_running_services",
    },
    failures,
  )
  for field in (
    "project",
    "frontend_network",
    "state_network",
    "runtime_frontend_network",
    "runtime_state_network",
    "proxy_service",
  ):
    _identifier(compose.get(field), f"$.compose.{field}", failures)
  if compose.get("frontend_network") == compose.get("state_network"):
    _failure(failures, "network_names_not_separate", "$.compose", "frontend and state networks must be distinct")
  if compose.get("runtime_frontend_network") == compose.get("runtime_state_network"):
    _failure(failures, "runtime_network_names_not_separate", "$.compose", "runtime frontend and state networks must be distinct")
  proxy_ports = _ports(compose.get("proxy_loopback_tcp_ports"), "$.compose.proxy_loopback_tcp_ports", failures)
  if set(proxy_ports) != {8080}:
    _failure(failures, "proxy_port_contract", "$.compose.proxy_loopback_tcp_ports", "must allow only loopback TCP port 8080")

  service_networks = compose.get("service_networks")
  if not isinstance(service_networks, dict):
    _failure(failures, "invalid_service_networks", "$.compose.service_networks", "must map every production service to networks")
  else:
    services = set(service_networks)
    if services != EXPECTED_SERVICES:
      _failure(failures, "service_set_mismatch", "$.compose.service_networks", "must declare exactly the approved production services")
    for service, networks in service_networks.items():
      _identifier(service, f"$.compose.service_networks.{service}", failures)
      if not isinstance(networks, list) or not networks:
        _failure(failures, "invalid_service_networks", f"$.compose.service_networks.{service}", "must be a non-empty network list")
        continue
      if len(networks) != len(set(networks)):
        _failure(failures, "duplicate_service_network", f"$.compose.service_networks.{service}", "must not repeat a network")
      for index, network in enumerate(networks):
        _identifier(network, f"$.compose.service_networks.{service}[{index}]", failures)
    frontend = compose.get("frontend_network")
    state = compose.get("state_network")
    if isinstance(frontend, str) and isinstance(state, str):
      if service_networks.get("web") != [frontend]:
        _failure(failures, "web_network_boundary", "$.compose.service_networks.web", "web may use only the frontend network")
      if service_networks.get("api") != [frontend, state]:
        _failure(failures, "api_network_boundary", "$.compose.service_networks.api", "API must bridge frontend and state only")
      for service in ("worker", "scheduler", "migrate", "mysql", "valkey"):
        if service_networks.get(service) != [state]:
          _failure(failures, "state_network_boundary", f"$.compose.service_networks.{service}", "service may use only the state network")

  required_running = compose.get("required_running_services")
  if not isinstance(required_running, list) or set(required_running) != EXPECTED_SERVICES - {"migrate"}:
    _failure(
      failures,
      "required_running_services_contract",
      "$.compose.required_running_services",
      "must list every long-running approved service and exclude one-shot migrate",
    )

  firewall = _closed_object(
    root.get("firewall"),
    "$.firewall",
    {"family", "table", "input_chain", "public_https_ports", "management_tcp_ports", "management_allowed_cidrs"},
    failures,
  )
  if firewall.get("family") not in {"inet", "ip", "ip6"}:
    _failure(failures, "invalid_firewall_family", "$.firewall.family", "must be inet, ip, or ip6")
  _identifier(firewall.get("table"), "$.firewall.table", failures)
  _identifier(firewall.get("input_chain"), "$.firewall.input_chain", failures)
  public_ports = _ports(firewall.get("public_https_ports"), "$.firewall.public_https_ports", failures)
  management_ports = _ports(firewall.get("management_tcp_ports"), "$.firewall.management_tcp_ports", failures)
  if set(public_ports) != {443}:
    _failure(failures, "public_https_port_contract", "$.firewall.public_https_ports", "only HTTPS port 443 may be public")
  if 443 in management_ports:
    _failure(
      failures,
      "management_https_must_be_edge_checked",
      "$.firewall.management_tcp_ports",
      "management HTTPS is verified from user and management edge perspectives, not as a broad host port",
    )
  _private_cidrs(firewall.get("management_allowed_cidrs"), "$.firewall.management_allowed_cidrs", failures)

  forbidden = _closed_object(root.get("forbidden_public"), "$.forbidden_public", {"tcp_ports", "mount_sources"}, failures)
  forbidden_ports = _closed_object(
    forbidden.get("tcp_ports"),
    "$.forbidden_public.tcp_ports",
    {"mysql", "valkey", "docker", "dokploy"},
    failures,
  )
  expected_forbidden = {"mysql": {3306}, "valkey": {6379}, "docker": {2375, 2376}}
  for category in ("mysql", "valkey", "docker", "dokploy"):
    ports = _ports(forbidden_ports.get(category), f"$.forbidden_public.tcp_ports.{category}", failures)
    if category in expected_forbidden and set(ports) != expected_forbidden[category]:
      _failure(failures, "forbidden_port_contract", f"$.forbidden_public.tcp_ports.{category}", "must list the approved forbidden ports")
    if set(ports).intersection(set(public_ports) | set(management_ports)):
      _failure(failures, "forbidden_port_approved", f"$.forbidden_public.tcp_ports.{category}", "forbidden ports cannot be approved")
  mount_sources = forbidden.get("mount_sources")
  if not isinstance(mount_sources, list) or set(mount_sources) != {"/var/run/docker.sock"}:
    _failure(failures, "docker_socket_mount_contract", "$.forbidden_public.mount_sources", "must forbid only the Docker socket source")

  endpoints = _closed_object(
    root.get("endpoints"),
    "$.endpoints",
    {
      "public_https_origin",
      "management_https_origin",
      "management_health_path",
      "user_denied_management_statuses",
      "management_success_status",
    },
    failures,
  )
  _https_origin(endpoints.get("public_https_origin"), "$.endpoints.public_https_origin", failures)
  _https_origin(endpoints.get("management_https_origin"), "$.endpoints.management_https_origin", failures)
  health_path = endpoints.get("management_health_path")
  if not isinstance(health_path, str) or not health_path.startswith("/") or ".." in health_path or "?" in health_path or "#" in health_path:
    _failure(failures, "invalid_management_health_path", "$.endpoints.management_health_path", "must be an absolute path without traversal, query, or fragment")
  denied_statuses = endpoints.get("user_denied_management_statuses")
  if not isinstance(denied_statuses, list) or not denied_statuses or any(type(code) is not int or not 400 <= code <= 499 for code in denied_statuses):
    _failure(failures, "invalid_user_denied_statuses", "$.endpoints.user_denied_management_statuses", "must be a non-empty list of 4xx statuses")
  elif len(denied_statuses) != len(set(denied_statuses)):
    _failure(failures, "duplicate_user_denied_status", "$.endpoints.user_denied_management_statuses", "must not repeat statuses")
  success_status = endpoints.get("management_success_status")
  if type(success_status) is not int or not 200 <= success_status <= 299:
    _failure(failures, "invalid_management_success_status", "$.endpoints.management_success_status", "must be a 2xx status")
  return failures


def _service_networks(value: Any) -> list[str] | None:
  if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
    return None
  return value


def _volume_source(volume: Any) -> str | None:
  if isinstance(volume, str):
    return volume.split(":", 1)[0]
  if isinstance(volume, dict) and volume.get("type") == "bind" and isinstance(volume.get("source"), str):
    return volume["source"]
  return None


def _volume_mapping(volume: Any) -> tuple[str | None, str | None]:
  if isinstance(volume, str):
    parts = volume.split(":")
    if len(parts) < 2:
      return parts[0], None
    return parts[0], parts[1]
  if isinstance(volume, dict) and str(volume.get("type", volume.get("Type", ""))).lower() == "bind":
    source_value = volume.get("source", volume.get("Source"))
    target_value = volume.get("target", volume.get("Destination"))
    source = source_value if isinstance(source_value, str) else None
    target = target_value if isinstance(target_value, str) else None
    return source, target
  return None, None


def _is_docker_socket_mount(volume: Any) -> bool:
  source, target = _volume_mapping(volume)
  source_is_socket = False
  if isinstance(source, str):
    try:
      source_is_socket = Path(source).is_socket()
    except OSError:
      source_is_socket = False
  return bool(
    (isinstance(source, str) and (SOCKET_PATH_RE.search(source) or source_is_socket))
    or (isinstance(target, str) and DOCKER_SOCKET_RE.search(target))
  )


def _literal_loopback_port(port: Any, allowed_ports: set[int]) -> bool:
  if isinstance(port, str):
    if "${" in port:
      return False
    parts = port.rsplit(":", 2)
    if len(parts) != 3:
      return False
    host, published, target = parts
    host = host.strip("[]")
    try:
      return ipaddress.ip_address(host).is_loopback and int(published) in allowed_ports and int(target) in allowed_ports
    except ValueError:
      return False
  if isinstance(port, dict):
    if set(port) - {"target", "published", "host_ip", "protocol"}:
      return False
    host = port.get("host_ip")
    published = port.get("published")
    target = port.get("target")
    if isinstance(published, str):
      if published.isdigit():
        published_port = int(published)
      else:
        match = re.fullmatch(r"\$\{PUBLIC_PORT:-(\d+)\}", published)
        published_port = int(match.group(1)) if match else None
    elif type(published) is int:
      published_port = published
    else:
      published_port = None
    try:
      return (
        isinstance(host, str)
        and ipaddress.ip_address(host).is_loopback
        and published_port is not None
        and published_port in allowed_ports
        and int(target) in allowed_ports
        and port.get("protocol", "tcp") == "tcp"
      )
    except (TypeError, ValueError):
      return False
  return False


def validate_compose(document: Any, policy: Mapping[str, Any]) -> list[Failure]:
  """Validate a rendered-or-source Compose document against a valid policy."""
  failures: list[Failure] = []
  if not isinstance(document, dict):
    return [Failure("invalid_compose", "$", "Compose document must be an object")]
  compose_policy = policy["compose"]
  services = document.get("services")
  networks = document.get("networks")
  if not isinstance(services, dict):
    _failure(failures, "missing_compose_services", "$.services", "Compose document must define services")
    return failures
  if not isinstance(networks, dict):
    _failure(failures, "missing_compose_networks", "$.networks", "Compose document must define networks")
    return failures
  expected_top_level_networks = {compose_policy["frontend_network"], compose_policy["state_network"]}
  if set(networks) != expected_top_level_networks:
    _failure(failures, "compose_network_set_mismatch", "$.networks", "Compose networks must exactly match the policy")
  expected_networks: Mapping[str, list[str]] = compose_policy["service_networks"]
  if set(services) != set(expected_networks):
    _failure(failures, "compose_service_set_mismatch", "$.services", "Compose services must exactly match the policy")
  frontend = compose_policy["frontend_network"]
  state = compose_policy["state_network"]
  state_config = networks.get(state)
  if not isinstance(state_config, dict) or state_config.get("internal") is not True:
    _failure(failures, "state_network_not_internal", f"$.networks.{state}", "state network must be explicitly internal")
  frontend_config = networks.get(frontend)
  if not isinstance(frontend_config, dict) or frontend_config.get("internal") is True:
    _failure(failures, "frontend_network_invalid", f"$.networks.{frontend}", "frontend network must not be internal")

  forbidden_mounts = set(policy["forbidden_public"]["mount_sources"])
  proxy_service = compose_policy["proxy_service"]
  allowed_proxy_ports = set(compose_policy["proxy_loopback_tcp_ports"])
  for service_name, expected in expected_networks.items():
    service = services.get(service_name)
    if not isinstance(service, dict):
      _failure(failures, "missing_compose_service", f"$.services.{service_name}", "required service is missing or invalid")
      continue
    actual_networks = _service_networks(service.get("networks"))
    if actual_networks != expected:
      _failure(failures, "compose_network_boundary", f"$.services.{service_name}.networks", "service networks do not match the policy")
    for index, volume in enumerate(service.get("volumes", [])):
      if _is_docker_socket_mount(volume) or _volume_source(volume) in forbidden_mounts:
        _failure(failures, "docker_socket_mount", f"$.services.{service_name}.volumes[{index}]", "Docker socket mounts are forbidden")
    published_ports = service.get("ports", [])
    if service_name != proxy_service and published_ports:
      _failure(failures, "unexpected_published_port", f"$.services.{service_name}.ports", "only the loopback proxy service may publish a port")
    if service_name == proxy_service:
      if not isinstance(published_ports, list) or not published_ports:
        _failure(failures, "missing_proxy_loopback_port", f"$.services.{service_name}.ports", "proxy service must publish its HTTP port to loopback")
      else:
        for index, published in enumerate(published_ports):
          if isinstance(published, str) and "${" in published:
            _failure(
              failures,
              "unresolved_host_binding",
              f"$.services.{service_name}.ports[{index}]",
              "host binding must be a literal loopback address, not an overrideable variable",
            )
          elif isinstance(published, dict) and isinstance(published.get("host_ip"), str) and "${" in published["host_ip"]:
            _failure(
              failures,
              "unresolved_host_binding",
              f"$.services.{service_name}.ports[{index}].host_ip",
              "host binding must be a literal loopback address, not an overrideable variable",
            )
          elif not _literal_loopback_port(published, allowed_proxy_ports):
            _failure(
              failures,
              "proxy_not_loopback_only",
              f"$.services.{service_name}.ports[{index}]",
              "proxy HTTP port must be published only to literal loopback",
            )
  return failures


def _check(check_id: str, passed: bool, passed_detail: str, failed_detail: str) -> Check:
  return Check(check_id, "passed" if passed else "failed", passed_detail if passed else failed_detail)


def _parse_json_lines(result: CommandResult) -> tuple[bool, list[dict[str, Any]]]:
  if result.returncode != 0:
    return False, []
  documents: list[dict[str, Any]] = []
  for line in result.stdout.splitlines():
    if not line.strip():
      continue
    try:
      item = json.loads(line)
    except ValueError:
      return False, []
    if not isinstance(item, dict):
      return False, []
    documents.append(item)
  return bool(documents), documents


def _parse_json_document(result: CommandResult) -> tuple[bool, Any]:
  if result.returncode != 0:
    return False, None
  try:
    return True, json.loads(result.stdout)
  except ValueError:
    return False, None


def _public_bindings(container_rows: Sequence[Mapping[str, Any]]) -> tuple[bool, list[PublishedBinding]]:
  bindings: list[PublishedBinding] = []
  for row in container_rows:
    container_id = row.get("ID")
    if not isinstance(container_id, str) or not container_id:
      return False, []
    ports = row.get("Ports", "")
    if not isinstance(ports, str):
      return False, []
    for segment in ports.split(","):
      segment = segment.strip()
      if not segment:
        continue
      if "->" not in segment:
        if EXPOSED_ONLY_RE.fullmatch(segment):
          continue
        return False, []
      match = PORT_BINDING_RE.fullmatch(segment)
      if match is None:
        return False, []
      host = match.group("host").strip("[]")
      try:
        ipaddress.ip_address(host)
        host_port = int(match.group("host_port"))
        container_port = int(match.group("container_port"))
      except ValueError:
        return False, []
      bindings.append(PublishedBinding(container_id, host, host_port, container_port, match.group("protocol")))
  return True, bindings


def _is_loopback_bind(host: str) -> bool:
  try:
    return ipaddress.ip_address(host).is_loopback
  except ValueError:
    return False


def _nft_chain(document: Any, policy: Mapping[str, Any]) -> tuple[dict[str, Any] | None, list[dict[str, Any]]]:
  if not isinstance(document, dict) or not isinstance(document.get("nftables"), list):
    return None, []
  firewall = policy["firewall"]
  matching_chain = None
  rules: list[dict[str, Any]] = []
  for item in document["nftables"]:
    if not isinstance(item, dict):
      continue
    chain = item.get("chain")
    if isinstance(chain, dict) and all(
      chain.get(field) == firewall[key]
      for field, key in (("family", "family"), ("table", "table"), ("name", "input_chain"))
    ):
      matching_chain = chain
    rule = item.get("rule")
    if isinstance(rule, dict) and all(
      rule.get(field) == firewall[key]
      for field, key in (("family", "family"), ("table", "table"), ("chain", "input_chain"))
    ):
      rules.append(rule)
  return matching_chain, rules


def _right_values(value: Any) -> set[str]:
  if isinstance(value, (str, int)):
    return {str(value)}
  if isinstance(value, list):
    return {str(item) for item in value if isinstance(item, (str, int))}
  if isinstance(value, dict):
    for key in ("set", "elem"):
      if key in value:
        return _right_values(value[key])
  return set()


def _match_field(expression: Any, protocol: str, field: str) -> tuple[bool, set[str]]:
  if not isinstance(expression, dict) or not isinstance(expression.get("match"), dict):
    return False, set()
  match = expression["match"]
  left = match.get("left")
  if not isinstance(left, dict) or not isinstance(left.get("payload"), dict):
    return False, set()
  payload = left["payload"]
  if payload.get("protocol") != protocol or payload.get("field") != field:
    return False, set()
  return True, _right_values(match.get("right"))


def _rule_accepts(rule: Mapping[str, Any]) -> bool:
  expressions = rule.get("expr")
  return isinstance(expressions, list) and any(isinstance(item, dict) and "accept" in item for item in expressions)


def _rule_ports(rule: Mapping[str, Any]) -> set[int]:
  ports: set[int] = set()
  expressions = rule.get("expr")
  if not isinstance(expressions, list):
    return ports
  for expression in expressions:
    matched, values = _match_field(expression, "tcp", "dport")
    if matched:
      ports.update(int(value) for value in values if value.isdigit())
  return ports


def _rule_sources(rule: Mapping[str, Any]) -> set[str]:
  sources: set[str] = set()
  expressions = rule.get("expr")
  if not isinstance(expressions, list):
    return sources
  for expression in expressions:
    for protocol in ("ip", "ip6"):
      matched, values = _match_field(expression, protocol, "saddr")
      if matched:
        sources.update(values)
  return sources


def _rule_loopback_scoped(rule: Mapping[str, Any]) -> bool:
  sources = _rule_sources(rule)
  if sources:
    try:
      return all(ipaddress.ip_network(source, strict=True).is_loopback for source in sources)
    except ValueError:
      return False
  expressions = rule.get("expr")
  if not isinstance(expressions, list):
    return False
  for expression in expressions:
    if not isinstance(expression, dict) or not isinstance(expression.get("match"), dict):
      continue
    match = expression["match"]
    left = match.get("left")
    if isinstance(left, dict) and left.get("meta", {}).get("key") == "iifname" and _right_values(match.get("right")) == {"lo"}:
      return True
  return False


def _is_stateful_return_accept(rule: Mapping[str, Any]) -> bool:
  expressions = rule.get("expr")
  if not isinstance(expressions, list):
    return False
  for expression in expressions:
    if not isinstance(expression, dict) or not isinstance(expression.get("match"), dict):
      continue
    match = expression["match"]
    left = match.get("left")
    if isinstance(left, dict) and left.get("ct", {}).get("key") == "state":
      return _right_values(match.get("right")).issubset({"established", "related"})
  return False


def _firewall_checks(document: Any, policy: Mapping[str, Any]) -> list[Check]:
  chain, rules = _nft_chain(document, policy)
  if chain is None:
    return [
      _check("firewall.ruleset", False, "nftables input chain was found", "required nftables input chain was not found"),
      _check("firewall.default_deny", False, "input policy is default-deny", "input policy could not be verified"),
    ]
  checks = [
    _check("firewall.ruleset", True, "nftables input chain was read", "nftables input chain was unavailable"),
    _check(
      "firewall.default_deny",
      chain.get("type") == "filter" and chain.get("hook") == "input" and chain.get("policy") == "drop",
      "input chain is an explicit default-deny filter",
      "input chain is not an explicit default-deny filter",
    ),
  ]
  accepted = [rule for rule in rules if _rule_accepts(rule)]
  public_ports = set(policy["firewall"]["public_https_ports"])
  public_ok = any(public_ports.intersection(_rule_ports(rule)) and not _rule_sources(rule) for rule in accepted)
  checks.append(
    _check(
      "firewall.public_https",
      public_ok,
      "approved public HTTPS accept rule is present",
      "approved public HTTPS accept rule is absent or source-restricted",
    )
  )
  management_ports = set(policy["firewall"]["management_tcp_ports"])
  expected_sources = set(policy["firewall"]["management_allowed_cidrs"])
  management_rules = [rule for rule in accepted if management_ports.intersection(_rule_ports(rule))]
  management_sources = set().union(*(_rule_sources(rule) for rule in management_rules)) if management_rules else set()
  management_ok = bool(management_rules) and management_sources == expected_sources and all(
    _rule_sources(rule) and _rule_sources(rule).issubset(expected_sources) for rule in management_rules
  )
  checks.append(
    _check(
      "firewall.management_cidrs",
      management_ok,
      "management TCP accepts are restricted to approved CIDRs",
      "management TCP accepts are missing, broad, or outside approved CIDRs",
    )
  )
  forbidden_ports = {
    port
    for ports in policy["forbidden_public"]["tcp_ports"].values()
    for port in ports
  }
  forbidden_ok = not any(
    forbidden_ports.intersection(_rule_ports(rule)) and not _rule_loopback_scoped(rule)
    for rule in accepted
  )
  checks.append(
    _check(
      "firewall.forbidden_ports",
      forbidden_ok,
      "no forbidden public service port is accepted by the input chain",
      "a forbidden service port is accepted outside loopback",
    )
  )
  unbounded_tcp_accept = any(
    not _rule_ports(rule) and not _is_stateful_return_accept(rule) and not _rule_loopback_scoped(rule)
    for rule in accepted
  )
  checks.append(
    _check(
      "firewall.bounded_tcp_accepts",
      not unbounded_tcp_accept,
      "all non-returning input accepts are bounded by an inspected port or loopback",
      "an unbounded input accept prevents a closed exposure conclusion",
    )
  )
  return checks


def _docker_checks(policy: Mapping[str, Any], backend: CommandBackend) -> list[Check]:
  checks: list[Check] = []
  rows_ok, rows = _parse_json_lines(backend.run(["docker", "ps", "--format", "{{json .}}"] ))
  ports_ok, bindings = _public_bindings(rows) if rows_ok else (False, [])
  checks.append(
    _check(
      "docker.global_port_inventory",
      rows_ok and ports_ok,
      "Docker published-port inventory was read without recording raw output",
      "Docker published-port inventory was unavailable or malformed",
    )
  )
  compose = policy["compose"]
  project = compose["project"]
  product_ids_result = backend.run(
    ["docker", "ps", "--filter", f"label=com.docker.compose.project={project}", "--format", "{{.ID}}"]
  )
  product_ids = [line.strip() for line in product_ids_result.stdout.splitlines() if line.strip()]
  product_ids_ok = product_ids_result.returncode == 0 and bool(product_ids)
  checks.append(
    _check(
      "docker.compose_project_inventory",
      product_ids_ok,
      "approved Compose project has running containers",
      "approved Compose project has no readable running-container inventory",
    )
  )
  inspected: list[dict[str, Any]] = []
  inspect_ok = False
  if product_ids_ok:
    inspect_ok, document = _parse_json_document(backend.run(["docker", "inspect", *product_ids]))
    if inspect_ok and isinstance(document, list) and all(isinstance(item, dict) for item in document):
      inspected = document
    else:
      inspect_ok = False
  service_to_id: dict[str, str] = {}
  seen_ids: set[str] = set()
  seen_services: set[str] = set()
  expected_runtime_networks = {
    compose["frontend_network"]: compose["runtime_frontend_network"],
    compose["state_network"]: compose["runtime_state_network"],
  }
  topology_ok = inspect_ok
  for item in inspected:
    labels = item.get("Config", {}).get("Labels", {}) if isinstance(item.get("Config"), dict) else {}
    networks = item.get("NetworkSettings", {}).get("Networks", {}) if isinstance(item.get("NetworkSettings"), dict) else {}
    service = labels.get("com.docker.compose.service") if isinstance(labels, dict) else None
    actual_project = labels.get("com.docker.compose.project") if isinstance(labels, dict) else None
    container_id = item.get("Id")
    if not isinstance(service, str) or not isinstance(actual_project, str) or actual_project != project or not isinstance(container_id, str):
      topology_ok = False
      continue
    if container_id in seen_ids or service in seen_services:
      topology_ok = False
      continue
    seen_ids.add(container_id)
    seen_services.add(service)
    service_to_id[service] = container_id
    expected_logical = compose["service_networks"].get(service)
    if not isinstance(expected_logical, list):
      topology_ok = False
      continue
    expected_actual = {expected_runtime_networks[name] for name in expected_logical}
    if not isinstance(networks, dict) or set(networks) != expected_actual:
      topology_ok = False
    mounts = item.get("Mounts")
    if not isinstance(mounts, list) or any(_is_docker_socket_mount(mount) for mount in mounts):
      topology_ok = False
  required_services = set(compose["required_running_services"])
  if set(service_to_id) != required_services or len(inspected) != len(required_services):
    topology_ok = False
  checks.append(
    _check(
      "docker.compose_network_topology",
      topology_ok,
      "running Compose services match the approved network topology",
      "running Compose services do not match the approved network topology",
    )
  )

  forbidden_by_category = policy["forbidden_public"]["tcp_ports"]
  externally_bound = [
    binding
    for binding in bindings
    if not _is_loopback_bind(binding.host)
    or binding.protocol != "tcp"
    or binding.container_port != 8080
    or binding.host_port != 8080
    or binding.container_id != service_to_id.get("web")
  ]
  exact_web_binding = len(bindings) == 1 and not externally_bound
  exposed_categories = sorted(
    category
    for category, ports in forbidden_by_category.items()
    if any(binding.host_port in set(ports) and not _is_loopback_bind(binding.host) for binding in bindings)
  )
  checks.append(
    _check(
      "docker.forbidden_public_ports",
      rows_ok and ports_ok and topology_ok and exact_web_binding and not exposed_categories,
      "only the approved loopback web binding is externally published",
      "an unexpected or externally bound Docker port was found",
    )
  )

  runtime_state = compose["runtime_state_network"]
  network_ok, network_document = _parse_json_document(backend.run(["docker", "network", "inspect", runtime_state]))
  membership_ok = network_ok and isinstance(network_document, list) and len(network_document) == 1 and isinstance(network_document[0], dict)
  if membership_ok:
    state_document = network_document[0]
    containers = state_document.get("Containers")
    expected_state_ids = {
      service_to_id[service]
      for service in required_services
      if compose["state_network"] in compose["service_networks"][service] and service in service_to_id
    }
    membership_ok = (
      state_document.get("Name") == runtime_state
      and state_document.get("Internal") is True
      and isinstance(containers, dict)
      and set(containers) == expected_state_ids
      and bool(expected_state_ids)
    )
  checks.append(
    _check(
      "docker.internal_state_network",
      bool(membership_ok),
      "runtime state network is internal and has only approved state members",
      "runtime state network is missing, non-internal, or has unexpected members",
    )
  )
  return checks


def run_host_exposure_verifier(policy: Mapping[str, Any], backend: CommandBackend | None = None) -> list[Check]:
  """Read host firewall and Docker runtime state without mutating either."""
  commands = backend or SystemCommandBackend()
  nft_ok, nft_document = _parse_json_document(commands.run(["nft", "-j", "list", "ruleset"]))
  firewall_checks = _firewall_checks(nft_document, policy) if nft_ok else [
    _check("firewall.ruleset", False, "nftables ruleset was read", "nftables ruleset query failed"),
    _check("firewall.default_deny", False, "input policy is default-deny", "input policy could not be verified"),
  ]
  return firewall_checks + _docker_checks(policy, commands)


def _source_in_management_cidr(source: str | None, allowed: Sequence[str]) -> bool:
  if source is None:
    return False
  try:
    address = ipaddress.ip_address(source)
    return any(address.version == ipaddress.ip_network(cidr).version and address in ipaddress.ip_network(cidr) for cidr in allowed)
  except ValueError:
    return False


def _source_addresses(probes: EndpointBackend, hostname: str, port: int) -> list[str]:
  method = getattr(probes, "source_addresses", None)
  if not callable(method):
    return []
  values = method(hostname, port)
  return sorted({value for value in values if isinstance(value, str) and value})


def _tcp_states(probes: EndpointBackend, hostname: str, port: int) -> list[str]:
  method = getattr(probes, "tcp_states", None)
  if not callable(method):
    return []
  values = method(hostname, port)
  return [value for value in values if isinstance(value, str)]


def _https_statuses(probes: EndpointBackend, origin: str, path: str) -> list[int]:
  method = getattr(probes, "https_statuses", None)
  if not callable(method):
    return []
  values = method(origin, path)
  return [value for value in values if type(value) is int]


def run_edge_exposure_verifier(
  policy: Mapping[str, Any],
  perspective: str,
  backend: EndpointBackend | None = None,
) -> list[Check]:
  """Run a real, unauthenticated user or management edge probe from this host."""
  if perspective not in {"user", "management"}:
    raise ValueError("perspective must be user or management")
  probes = backend or SystemEndpointBackend()
  endpoints = policy["endpoints"]
  firewall = policy["firewall"]
  public_host, public_port = _https_origin(endpoints["public_https_origin"], "$.endpoints.public_https_origin", []) or ("", 443)
  management_host, management_port = _https_origin(endpoints["management_https_origin"], "$.endpoints.management_https_origin", []) or ("", 443)
  sources = _source_addresses(probes, management_host, management_port)
  source_membership = [_source_in_management_cidr(source, firewall["management_allowed_cidrs"]) for source in sources]
  expected_source = bool(sources) and all(source_membership) if perspective == "management" else bool(sources) and all(not item for item in source_membership)
  public_statuses = _https_statuses(probes, endpoints["public_https_origin"], "/")
  public_tcp_states = _tcp_states(probes, public_host, public_port)
  checks = [
    _check(
      "edge.source_perspective",
      expected_source,
      "probe source belongs to the declared perspective without recording its address",
      "probe source does not belong to the declared perspective",
    ),
    _check(
      "edge.public_https",
      bool(public_tcp_states)
      and all(state == "open" for state in public_tcp_states)
      and bool(public_statuses)
      and all(200 <= status <= 299 for status in public_statuses),
      "all resolved public HTTPS addresses completed TLS and returned a 2xx response",
      "one or more resolved public HTTPS addresses lacked valid TLS or a 2xx response",
    ),
  ]
  management_statuses = _https_statuses(probes, endpoints["management_https_origin"], endpoints["management_health_path"])
  if perspective == "management":
    management_ok = bool(management_statuses) and all(status == endpoints["management_success_status"] for status in management_statuses)
    success_detail = "all resolved management HTTPS addresses returned the approved success status"
    failure_detail = "one or more resolved management HTTPS addresses did not return the approved success status"
  else:
    management_ok = bool(management_statuses) and all(status in set(endpoints["user_denied_management_statuses"]) for status in management_statuses)
    success_detail = "all resolved management HTTPS addresses returned an explicit management denial"
    failure_detail = "one or more resolved management HTTPS addresses did not return an explicit management denial"
  checks.append(_check("edge.management_https", management_ok, success_detail, failure_detail))

  forbidden_ports = sorted({port for ports in policy["forbidden_public"]["tcp_ports"].values() for port in ports})
  hosts = {public_host, management_host}
  closed = all(
    states and all(state in {"closed", "filtered"} for state in states)
    for host in hosts for port in forbidden_ports
    for states in [_tcp_states(probes, host, port)]
  )
  checks.append(
    _check(
      "edge.forbidden_tcp_ports",
      closed,
      "forbidden TCP service ports are not open from this perspective",
      "a forbidden TCP service port is open or inconclusive from this perspective",
    )
  )
  if perspective == "management":
    management_tcp_open = all(
      (states := _tcp_states(probes, management_host, port)) and all(state == "open" for state in states)
      for port in firewall["management_tcp_ports"]
    )
    checks.append(
      _check(
        "edge.management_tcp",
        management_tcp_open,
        "management TCP ports are reachable from the management perspective",
        "management TCP ports are not reachable from the management perspective",
      )
    )
  else:
    management_tcp_closed = all(
      (states := _tcp_states(probes, management_host, port)) and all(state in {"closed", "filtered"} for state in states)
      for port in firewall["management_tcp_ports"]
    )
    checks.append(
      _check(
        "edge.management_tcp",
        management_tcp_closed,
        "management TCP ports are not open from the user perspective",
        "management TCP ports are open or inconclusive from the user perspective",
      )
    )
  return checks


def _canonical_digest(value: Any) -> str:
  encoded = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
  return hashlib.sha256(encoded).hexdigest()


def _is_full_revision(value: str | None) -> bool:
  return isinstance(value, str) and bool(re.fullmatch(r"[0-9a-f]{40}", value))


def _file_sha256(path: Path) -> str:
  return hashlib.sha256(path.read_bytes()).hexdigest()


OFFLINE_CHECK_IDS = {"offline.policy_contract", "offline.compose_policy"}
HOST_CHECK_IDS = {
  "firewall.ruleset", "firewall.default_deny", "firewall.public_https", "firewall.management_cidrs",
  "firewall.forbidden_ports", "firewall.bounded_tcp_accepts", "docker.global_port_inventory",
  "docker.compose_project_inventory", "docker.compose_network_topology", "docker.forbidden_public_ports",
  "docker.internal_state_network",
}
EDGE_CHECK_IDS = {"edge.source_perspective", "edge.public_https", "edge.management_https", "edge.forbidden_tcp_ports", "edge.management_tcp"}


def _valid_timestamp(value: Any) -> bool:
  if not isinstance(value, str):
    return False
  try:
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
  except ValueError:
    return False
  return parsed.tzinfo is not None


def _validate_receipt_inputs(
  mode: str,
  checks: Sequence[Check],
  observed_at: str | None,
  revision: str | None,
  perspective: str | None,
  compose_sha256: str | None,
) -> None:
  expected_perspectives = {"offline-policy": "offline", "host-live": "host", "edge-live": {"user", "management"}}
  if mode not in expected_perspectives and mode != "unavailable":
    raise ValueError("unsupported receipt mode")
  if not checks:
    raise ValueError("receipt checks must be non-empty")
  if any(not isinstance(check, Check) for check in checks):
    raise ValueError("receipt checks must use the Check type")
  if len(checks) != len({check.check_id for check in checks}):
    raise ValueError("receipt checks must not contain duplicate IDs")
  if any(not isinstance(check.check_id, str) or not check.check_id.strip() or check.status not in {"passed", "failed"} or not check.detail.strip() for check in checks):
    raise ValueError("receipt checks must be typed passed/failed checks with details")
  check_ids = {check.check_id for check in checks}
  expected_ids = {"offline-policy": OFFLINE_CHECK_IDS, "host-live": HOST_CHECK_IDS, "edge-live": EDGE_CHECK_IDS}.get(mode)
  if expected_ids is not None and check_ids != expected_ids:
    raise ValueError("receipt checks do not exactly match the selected verifier mode")
  if mode == "offline-policy":
    if perspective != "offline" or revision not in {None, "unverified"} or observed_at is not None or compose_sha256 is not None:
      raise ValueError("offline receipts cannot claim live provenance")
  elif mode == "host-live":
    if perspective != "host" or not _is_full_revision(revision) or not _valid_timestamp(observed_at) or not isinstance(compose_sha256, str) or not re.fullmatch(r"[0-9a-f]{64}", compose_sha256):
      raise ValueError("host-live receipts require host perspective, full revision, timestamp, and Compose digest")
  elif mode == "edge-live":
    if perspective not in {"user", "management"} or not _is_full_revision(revision) or not _valid_timestamp(observed_at) or compose_sha256 is not None:
      raise ValueError("edge-live receipts require user/management perspective, full revision, and timestamp")
  else:
    if any(check.status == "passed" for check in checks):
      raise ValueError("unavailable receipts cannot contain passed checks")


def build_receipt(
  mode: str,
  policy: Mapping[str, Any],
  checks: Sequence[Check],
  observed_at: str | None = None,
  *,
  revision: str | None = None,
  perspective: str | None = None,
  compose_sha256: str | None = None,
) -> dict[str, Any]:
  receipt_perspective = perspective or {"host-live": "host", "edge-live": "unknown", "offline-policy": "offline"}.get(mode, "unknown")
  _validate_receipt_inputs(mode, checks, observed_at, revision, receipt_perspective, compose_sha256)
  ordered = sorted(checks, key=lambda check: check.check_id)
  passed = bool(ordered) and all(check.status == "passed" for check in ordered)
  receipt: dict[str, Any] = {
    "receipt_version": RECEIPT_VERSION,
    "verifier_version": VERIFIER_VERSION,
    "task_id": TASK_ID,
    "mode": mode,
    "perspective": receipt_perspective,
    "git_revision": revision or "unverified",
    "policy_sha256": _canonical_digest(policy),
    "redaction": {
      "secret_values_included": False,
      "endpoint_addresses_included": False,
      "source_addresses_included": False,
      "raw_command_output_included": False,
    },
    "checks": [check.to_dict() for check in ordered],
    "summary": {"status": "passed" if passed else "failed", "check_count": len(ordered)},
    "signature": {"status": "not-signed"},
  }
  if compose_sha256 is not None:
    receipt["compose_sha256"] = compose_sha256
  if observed_at is not None:
    receipt["observed_at"] = observed_at
  receipt["receipt_sha256"] = _canonical_digest(receipt)
  return receipt


def _offline_checks(policy: Mapping[str, Any], policy_failures: Sequence[Failure], compose_failures: Sequence[Failure]) -> list[Check]:
  return [
    _check(
      "offline.policy_contract",
      not policy_failures,
      "closed non-secret network policy is valid",
      "closed non-secret network policy has validation failures",
    ),
    _check(
      "offline.compose_policy",
      not policy_failures and not compose_failures,
      "Compose network boundaries satisfy the policy",
      "Compose network boundaries do not satisfy the policy",
    ),
  ]


def _load_json(path: Path) -> Any:
  return json.loads(path.read_text(encoding="utf-8"))


def _load_compose(path: Path) -> Any:
  return yaml.safe_load(path.read_text(encoding="utf-8"))


def _write_receipt(path: Path, receipt: Mapping[str, Any]) -> None:
  path.parent.mkdir(parents=True, exist_ok=True)
  path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def main(argv: Sequence[str] | None = None) -> int:
  parser = argparse.ArgumentParser(description="W11-NET-04 fail-closed network exposure verification")
  subparsers = parser.add_subparsers(dest="command", required=True)
  for command in ("validate", "verify-host"):
    command_parser = subparsers.add_parser(command)
    command_parser.add_argument("--policy", required=True, type=Path)
    command_parser.add_argument("--compose", required=True, type=Path)
    command_parser.add_argument("--receipt", required=True, type=Path)
    if command == "verify-host":
      command_parser.add_argument("--revision", required=True, type=str)
  edge_parser = subparsers.add_parser("verify-edge")
  edge_parser.add_argument("--policy", required=True, type=Path)
  edge_parser.add_argument("--perspective", required=True, choices=("user", "management"))
  edge_parser.add_argument("--receipt", required=True, type=Path)
  edge_parser.add_argument("--revision", required=True, type=str)
  args = parser.parse_args(argv)
  if args.command in {"verify-host", "verify-edge"} and not _is_full_revision(args.revision):
    parser.error("live verification requires a full 40-character lowercase git revision")
  try:
    policy = _load_json(args.policy)
    policy_failures = validate_policy(policy)
    if args.command == "verify-edge":
      checks = _offline_checks(policy, policy_failures, [])
      if not policy_failures:
        checks.extend(run_edge_exposure_verifier(policy, args.perspective))
      receipt = build_receipt(
        "edge-live",
        policy,
        checks,
        datetime.now(timezone.utc).isoformat(),
        revision=args.revision,
        perspective=args.perspective,
      )
    else:
      compose = _load_compose(args.compose)
      compose_failures = validate_compose(compose, policy) if not policy_failures else []
      checks = _offline_checks(policy, policy_failures, compose_failures)
      if args.command == "verify-host" and not policy_failures and not compose_failures:
        checks.extend(run_host_exposure_verifier(policy))
      receipt = build_receipt(
        "host-live" if args.command == "verify-host" else "offline-policy",
        policy,
        checks,
        datetime.now(timezone.utc).isoformat() if args.command == "verify-host" else None,
        revision=args.revision if args.command == "verify-host" else None,
        perspective="host" if args.command == "verify-host" else "offline",
        compose_sha256=_file_sha256(args.compose) if args.command == "verify-host" else None,
      )
  except (OSError, ValueError, yaml.YAMLError) as error:
    policy = {"unreadable": True}
    receipt = build_receipt(
      "unavailable",
      policy,
      [_check("verifier.inputs", False, "inputs were read", "policy or Compose input could not be read safely")],
      datetime.now(timezone.utc).isoformat(),
      revision=getattr(args, "revision", None),
      perspective=getattr(args, "perspective", "unknown"),
    )
    print(f"W11-NET-04 verifier input error: {error}", file=sys.stderr)
  _write_receipt(args.receipt, receipt)
  print(json.dumps(receipt["summary"], sort_keys=True))
  return 0 if receipt["summary"]["status"] == "passed" else 1


if __name__ == "__main__":
  raise SystemExit(main())
