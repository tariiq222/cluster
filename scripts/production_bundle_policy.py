#!/usr/bin/env python3

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Sequence

try:
  import yaml
except ImportError:
  print("ERROR: PyYAML is required; install requirements-ops-test.txt.", file=sys.stderr)
  raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
DEFAULT_COMPOSE = ROOT / "infra/platform/production/compose.yaml"
DEFAULT_API_DOCKERFILE = ROOT / "apps/api/Dockerfile"
DEFAULT_WEB_DOCKERFILE = ROOT / "apps/web/Dockerfile"
SHA256_RE = re.compile(r"@sha256:[0-9a-f]{64}(?:\s|$)", re.IGNORECASE)
FROM_RE = re.compile(
  r"^\s*FROM\s+(?:--platform=\S+\s+)?(?P<image>\S+)(?:\s+AS\s+(?P<alias>\S+))?\s*$",
  re.IGNORECASE | re.MULTILINE,
)
USER_RE = re.compile(r"^\s*USER\s+(?P<user>\S+)", re.IGNORECASE | re.MULTILINE)
HEALTHCHECK_RE = re.compile(r"^\s*HEALTHCHECK\s+", re.IGNORECASE | re.MULTILINE)
DOCKER_RUNTIME_INSTALL_RE = re.compile(
  r"^\s*RUN\s+.*(?:apk\s+add|apt(?:-get)?\s+install|npm\s+(?:ci|install)|pnpm\s+install|yarn\s+install|composer\s+install|pip\s+install)",
  re.IGNORECASE | re.MULTILINE,
)
COMMAND_RUNTIME_INSTALL_RE = re.compile(
  r"(?:apk\s+add|apt(?:-get)?\s+install|npm\s+(?:ci|install)|pnpm\s+install|yarn\s+install|composer\s+install|pip\s+install)",
  re.IGNORECASE,
)
SENSITIVE_ENV_RE = re.compile(r"(?:PASSWORD|PASSWD|TOKEN|SECRET|CREDENTIAL|PRIVATE_KEY|APP_KEY)$", re.IGNORECASE)
REQUIRED_SERVICES = {"web", "api", "worker", "scheduler", "migrate", "mysql", "valkey"}
HEALTHCHECK_SERVICES = {"web", "api", "worker", "scheduler", "mysql", "valkey"}
HARDENED_SERVICES = {"web", "api", "worker", "scheduler", "migrate"}
EXPECTED_NETWORKS = {
  "web": {"frontend"},
  "api": {"frontend", "state"},
  "worker": {"state"},
  "scheduler": {"state"},
  "migrate": {"state"},
  "mysql": {"state"},
  "valkey": {"state"},
}


@dataclass(frozen=True)
class Failure:
  code: str
  path: str
  message: str

  def to_dict(self) -> dict[str, str]:
    return {"code": self.code, "path": self.path, "message": self.message}


def _failure(failures: list[Failure], code: str, path: str, message: str) -> None:
  failures.append(Failure(code=code, path=path, message=message))


def _mapping(value: Any) -> dict[str, Any]:
  return value if isinstance(value, dict) else {}


def _sequence(value: Any) -> list[Any]:
  return value if isinstance(value, list) else []


def validate_dockerfile(text: str, label: str) -> list[Failure]:
  failures: list[Failure] = []
  matches = list(FROM_RE.finditer(text))
  if len(matches) < 2:
    _failure(failures, "not_multistage", label, "must contain separate build and production stages")
  known_stages: set[str] = set()
  for index, match in enumerate(matches, start=1):
    image = match.group("image")
    if image.lower() not in known_stages:
      if re.search(r":latest(?:@|$)", image, re.IGNORECASE):
        _failure(failures, "latest_image", f"{label}:FROM[{index}]", "must not use the latest tag")
      if SHA256_RE.search(f"{image} ") is None:
        _failure(failures, "unpinned_base_image", f"{label}:FROM[{index}]", "base image must be pinned by sha256 digest")
    if match.group("alias"):
      known_stages.add(match.group("alias").lower())

  final_stage = text[matches[-1].start():] if matches else text
  users = [match.group("user") for match in USER_RE.finditer(final_stage)]
  if not users or users[-1].lower() in {"root", "0", "0:0"}:
    _failure(failures, "root_runtime", label, "final image must declare a non-root USER")
  if HEALTHCHECK_RE.search(final_stage) is None:
    _failure(failures, "missing_healthcheck", label, "final image must declare a HEALTHCHECK")
  if DOCKER_RUNTIME_INSTALL_RE.search(final_stage):
    _failure(failures, "runtime_install", label, "final image must not install packages")
  return failures


def _environment_items(value: Any) -> dict[str, Any]:
  if isinstance(value, dict):
    return value
  result: dict[str, Any] = {}
  for item in _sequence(value):
    if isinstance(item, str):
      key, separator, content = item.partition("=")
      result[key] = content if separator else None
  return result


def _command_text(value: Any) -> str:
  if isinstance(value, str):
    return value
  if isinstance(value, list):
    return " ".join(str(item) for item in value)
  return ""


def _networks(value: Any) -> set[str]:
  if isinstance(value, list):
    return {str(item) for item in value}
  if isinstance(value, dict):
    return set(value)
  return set()


def _has_bind_mount(value: Any) -> bool:
  for mount in _sequence(value):
    if isinstance(mount, dict):
      if mount.get("type") == "bind":
        return True
      continue
    if not isinstance(mount, str):
      continue
    source = mount.split(":", 1)[0]
    if source.startswith((".", "/", "~")):
      return True
  return False


def _valid_runtime_image(service_name: str, image: Any) -> bool:
  if not isinstance(image, str):
    return False
  if service_name == "web":
    return image.startswith("${WEB_IMAGE:?")
  if service_name in {"api", "worker", "scheduler", "migrate"}:
    return image.startswith("${API_IMAGE:?")
  return bool(re.search(r"@sha256:[0-9a-f]{64}$", image, re.IGNORECASE))


def validate_compose(document: Any) -> list[Failure]:
  failures: list[Failure] = []
  root = _mapping(document)
  services = _mapping(root.get("services"))
  for service_name in sorted(REQUIRED_SERVICES - set(services)):
    _failure(failures, "missing_service", f"services.{service_name}", "required production service is missing")

  for service_name, raw_service in services.items():
    path = f"services.{service_name}"
    service = _mapping(raw_service)
    image = service.get("image")
    if "build" in service:
      _failure(failures, "runtime_build", path, "production Compose must consume prebuilt images")
    if isinstance(image, str) and ":latest" in image.lower():
      _failure(failures, "latest_image", f"{path}.image", "must not use the latest tag")
    if service_name in REQUIRED_SERVICES and not _valid_runtime_image(service_name, image):
      _failure(failures, "unpinned_service_image", f"{path}.image", "must use the governed image variable or a sha256-pinned state image")
    if service_name in REQUIRED_SERVICES and service.get("pull_policy") != "never":
      _failure(failures, "runtime_pull_enabled", f"{path}.pull_policy", "production startup must use images already present on the host")

    if "ports" in service and service_name != "web":
      code = "state_port_public" if service_name in {"mysql", "valkey"} else "unexpected_public_port"
      _failure(failures, code, f"{path}.ports", "only the Web service may publish a host port")
    if service_name in HEALTHCHECK_SERVICES and "healthcheck" not in service:
      _failure(failures, "missing_healthcheck", path, "service must declare a healthcheck")

    if service_name in HARDENED_SERVICES:
      security_opt = {str(item) for item in _sequence(service.get("security_opt"))}
      cap_drop = {str(item).upper() for item in _sequence(service.get("cap_drop"))}
      if service.get("read_only") is not True or "no-new-privileges:true" not in security_opt or "ALL" not in cap_drop:
        _failure(failures, "missing_hardening", path, "application service must be read-only, drop all capabilities, and forbid privilege escalation")

    for key, value in _environment_items(service.get("environment")).items():
      if SENSITIVE_ENV_RE.search(str(key)):
        if not isinstance(value, str) or re.fullmatch(r"\$\{[A-Z0-9_]+:\?[^}]+\}", value) is None:
          _failure(failures, "literal_secret", f"{path}.environment.{key}", "sensitive values must use required runtime interpolation")

    command = f"{_command_text(service.get('entrypoint'))} {_command_text(service.get('command'))}"
    if COMMAND_RUNTIME_INSTALL_RE.search(command):
      _failure(failures, "runtime_install", path, "service command must not install packages at startup")
    if _has_bind_mount(service.get("volumes")):
      _failure(failures, "bind_mount", f"{path}.volumes", "production services must not use host bind mounts")
    if service.get("privileged") is True or service.get("network_mode") == "host" or service.get("pid") == "host":
      _failure(failures, "unsafe_host_access", path, "production services must not use privileged or host namespace modes")

    expected_networks = EXPECTED_NETWORKS.get(service_name)
    if expected_networks is not None and _networks(service.get("networks")) != expected_networks:
      _failure(failures, "network_boundary", f"{path}.networks", "service networks do not match the production boundary")

  state_network = _mapping(_mapping(root.get("networks")).get("state"))
  if state_network.get("internal") is not True:
    _failure(failures, "state_network_not_internal", "networks.state", "state network must be internal")
  return failures


def validate_bundle(compose_path: Path, api_dockerfile: Path, web_dockerfile: Path) -> list[Failure]:
  failures: list[Failure] = []
  try:
    compose_document = yaml.safe_load(compose_path.read_text(encoding="utf-8"))
  except (OSError, yaml.YAMLError) as error:
    return [Failure("invalid_compose", str(compose_path), f"cannot load production Compose: {error}")]
  failures.extend(validate_compose(compose_document))
  for path in [api_dockerfile, web_dockerfile]:
    try:
      text = path.read_text(encoding="utf-8")
    except OSError as error:
      failures.append(Failure("missing_dockerfile", str(path), f"cannot read Dockerfile: {error}"))
      continue
    failures.extend(validate_dockerfile(text, str(path)))
  return failures


def _parser() -> argparse.ArgumentParser:
  parser = argparse.ArgumentParser(description="Validate the W11-BLD-02 production image and Compose policy.")
  parser.add_argument("--compose", type=Path, default=DEFAULT_COMPOSE)
  parser.add_argument("--api-dockerfile", type=Path, default=DEFAULT_API_DOCKERFILE)
  parser.add_argument("--web-dockerfile", type=Path, default=DEFAULT_WEB_DOCKERFILE)
  return parser


def main(argv: Sequence[str] | None = None) -> int:
  args = _parser().parse_args(argv)
  failures = validate_bundle(args.compose, args.api_dockerfile, args.web_dockerfile)
  if failures:
    for failure in failures:
      print(f"ERROR [{failure.code}] {failure.path}: {failure.message}", file=sys.stderr)
    print(f"Production bundle validation failed with {len(failures)} error(s).", file=sys.stderr)
    return 1
  print("Production Dockerfiles and Compose policy validation passed.")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
