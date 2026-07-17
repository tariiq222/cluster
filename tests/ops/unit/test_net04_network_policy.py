from __future__ import annotations

import copy
import json
from pathlib import Path

import pytest

from scripts.net04_network_policy import Check, build_receipt, validate_compose, validate_policy


pytestmark = pytest.mark.unit


ROOT = Path(__file__).resolve().parents[3]


def policy() -> dict:
  return json.loads((ROOT / "infra/platform/network/net04-network-policy.example.json").read_text(encoding="utf-8"))


def compose_fixture() -> dict:
  service_networks = policy()["compose"]["service_networks"]
  services = {
    service: {"networks": copy.deepcopy(networks)}
    for service, networks in service_networks.items()
  }
  services["web"]["ports"] = ["127.0.0.1:8080:8080"]
  return {"services": services, "networks": {"frontend": {}, "state": {"internal": True}}}


def codes(failures):
  return {failure.code for failure in failures}


def test_closed_policy_and_literal_loopback_compose_are_accepted():
  approved_policy = policy()

  assert validate_policy(approved_policy) == []
  assert validate_compose(compose_fixture(), approved_policy) == []


@pytest.mark.parametrize(
  ("mutator", "expected_code"),
  [
    (lambda candidate: candidate["firewall"].update({"management_allowed_cidrs": ["0.0.0.0/0"]}), "public_management_cidr"),
    (lambda candidate: candidate["firewall"].update({"management_tcp_ports": [443]}), "management_https_must_be_edge_checked"),
    (lambda candidate: candidate["forbidden_public"]["tcp_ports"].update({"mysql": [3306, 443]}), "forbidden_port_approved"),
    (lambda candidate: candidate.update({"token": "must-not-enter-policy"}), "unknown_field"),
  ],
)
def test_policy_rejects_broad_or_sensitive_exposure_rules(mutator, expected_code):
  candidate = policy()
  mutator(candidate)

  assert expected_code in codes(validate_policy(candidate))


@pytest.mark.parametrize("cidr", ["10.0.0.0/8", "fc00::/7"])
def test_policy_rejects_management_cidr_that_is_too_broad(cidr):
  candidate = policy()
  candidate["firewall"]["management_allowed_cidrs"] = [cidr]

  assert "management_cidr_too_broad" in codes(validate_policy(candidate))


@pytest.mark.parametrize(
  ("mutator", "expected_code"),
  [
    (lambda candidate: candidate["networks"]["state"].update({"internal": False}), "state_network_not_internal"),
    (lambda candidate: candidate["services"]["mysql"].update({"ports": ["0.0.0.0:3306:3306"]}), "unexpected_published_port"),
    (lambda candidate: candidate["services"]["api"].update({"volumes": ["/var/run/docker.sock:/var/run/docker.sock"]}), "docker_socket_mount"),
    (lambda candidate: candidate["services"]["web"].update({"ports": ["0.0.0.0:8080:8080"]}), "proxy_not_loopback_only"),
  ],
)
def test_compose_rejects_network_and_exposure_escape_hatches(mutator, expected_code):
  candidate = compose_fixture()
  mutator(candidate)

  assert expected_code in codes(validate_compose(candidate, policy()))


def test_checked_in_compose_uses_literal_loopback_with_only_the_host_port_overrideable():
  import yaml

  document = yaml.safe_load((ROOT / "infra/platform/production/compose.yaml").read_text(encoding="utf-8"))

  assert validate_compose(document, policy()) == []


def test_compose_rejects_an_overrideable_long_syntax_host_binding():
  candidate = compose_fixture()
  candidate["services"]["web"]["ports"] = [
    {"target": 8080, "published": "${PUBLIC_PORT:-8080}", "host_ip": "${PUBLIC_BIND_ADDRESS:-127.0.0.1}"}
  ]

  assert "unresolved_host_binding" in codes(validate_compose(candidate, policy()))


def test_compose_rejects_unreferenced_top_level_network():
  candidate = compose_fixture()
  candidate["networks"]["rogue"] = {}

  assert "compose_network_set_mismatch" in codes(validate_compose(candidate, policy()))


@pytest.mark.parametrize("mode", ["host", "ingress"])
def test_compose_rejects_explicit_publish_mode_for_non_swarm_compose(mode):
  candidate = compose_fixture()
  candidate["services"]["web"]["ports"] = [{"target": 8080, "published": 8080, "host_ip": "127.0.0.1", "protocol": "tcp", "mode": mode}]

  assert "proxy_not_loopback_only" in codes(validate_compose(candidate, policy()))


@pytest.mark.parametrize(
  "volume",
  [
    "/tmp/alternate.sock:/var/run/docker.sock",
    "/var/run/docker.sock:/tmp/api.sock",
    {"type": "bind", "source": "/opt/docker-api.sock", "target": "/tmp/socket"},
  ],
)
def test_compose_rejects_docker_socket_by_source_or_destination(volume):
  candidate = compose_fixture()
  candidate["services"]["web"]["volumes"] = [volume]

  assert "docker_socket_mount" in codes(validate_compose(candidate, policy()))


@pytest.mark.parametrize(
  "port",
  [
    {"target": 8080, "published": "${PUBLIC_PORT:-8080}", "host_ip": "127.0.0.1", "protocol": "udp"},
    {"target": 8080, "published": "${PUBLIC_PORT:-9999}", "host_ip": "127.0.0.1", "protocol": "tcp"},
  ],
)
def test_compose_rejects_non_tcp_or_unapproved_default_proxy_ports(port):
  candidate = compose_fixture()
  candidate["services"]["web"]["ports"] = [port]

  assert "proxy_not_loopback_only" in codes(validate_compose(candidate, policy()))


def test_receipt_is_sorted_and_never_contains_policy_endpoints_or_secret_values():
  approved_policy = policy()
  approved_policy["unexpected_token"] = "must-not-enter-receipt"
  receipt = build_receipt("offline-policy", approved_policy, [Check("offline.policy_contract", "failed", "policy fixture contains an unknown field"), Check("offline.compose_policy", "failed", "policy fixture contains an unknown field")])
  serialized = json.dumps(receipt, sort_keys=True)

  assert receipt["summary"] == {"status": "failed", "check_count": 2}
  assert "example.invalid" not in serialized
  assert "must-not-enter-receipt" not in serialized
  assert receipt["redaction"]["raw_command_output_included"] is False


def test_empty_check_set_never_produces_a_passed_receipt():
  with pytest.raises(ValueError):
    build_receipt("offline-policy", policy(), [])


def test_receipt_builder_rejects_forged_live_metadata_and_requires_exact_checks():
  with pytest.raises(ValueError):
    build_receipt("host-live", policy(), [type("FakeCheck", (), {"check_id": "x", "status": "passed", "detail": "ok"})()], revision="a" * 40, perspective="host", compose_sha256="0" * 64, observed_at="2026-07-17T00:00:00Z")
  with pytest.raises(ValueError):
    build_receipt("host-live", policy(), [], revision=None, perspective="host", compose_sha256="0" * 64, observed_at="2026-07-17T00:00:00Z")


def test_policy_and_receipt_schemas_are_closed_and_bind_runtime_contracts():
  policy_schema = json.loads((ROOT / "infra/platform/contracts/net04-network-policy.schema.json").read_text(encoding="utf-8"))
  receipt_schema = json.loads((ROOT / "infra/platform/contracts/net04-receipt.schema.json").read_text(encoding="utf-8"))
  assert policy_schema["additionalProperties"] is False
  assert policy_schema["properties"]["compose"]["properties"]["proxy_loopback_tcp_ports"] == {"const": [8080]}
  assert policy_schema["properties"]["compose"]["properties"]["required_running_services"]["const"] == ["web", "api", "worker", "scheduler", "mysql", "valkey"]
  assert policy_schema["properties"]["forbidden_public"]["properties"]["mount_sources"]["const"] == ["/var/run/docker.sock"]
  assert receipt_schema["additionalProperties"] is False
  assert receipt_schema["properties"]["signature"]["const"] == {"status": "not-signed"}
