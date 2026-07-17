from __future__ import annotations

import json
from pathlib import Path

import pytest

from scripts.net04_network_policy import (
  CommandResult,
  build_receipt,
  run_edge_exposure_verifier,
  run_host_exposure_verifier,
)


pytestmark = pytest.mark.integration


ROOT = Path(__file__).resolve().parents[3]


def policy() -> dict:
  return json.loads((ROOT / "infra/platform/network/net04-network-policy.example.json").read_text(encoding="utf-8"))


def nft_rule(expressions):
  return {"rule": {"family": "inet", "table": "filter", "chain": "input", "expr": expressions}}


def port(port_number):
  return {"match": {"op": "==", "left": {"payload": {"protocol": "tcp", "field": "dport"}}, "right": port_number}}


def source(cidr):
  return {"match": {"op": "==", "left": {"payload": {"protocol": "ip", "field": "saddr"}}, "right": cidr}}


class FakeHostBackend:
  def __init__(self):
    services = {
      "web": ["cluster_frontend"],
      "api": ["cluster_frontend", "cluster_state"],
      "worker": ["cluster_state"],
      "scheduler": ["cluster_state"],
      "mysql": ["cluster_state"],
      "valkey": ["cluster_state"],
    }
    self.rows = [
      {"ID": f"id-{service}", "Ports": "127.0.0.1:8080->8080/tcp" if service == "web" else ""}
      for service in services
    ]
    self.ids = [row["ID"] for row in self.rows]
    self.inspections = [
      {
        "Id": f"id-{service}",
        "Config": {"Labels": {"com.docker.compose.project": "cluster", "com.docker.compose.service": service}},
        "NetworkSettings": {"Networks": {network: {} for network in networks}},
        "Mounts": [],
      }
      for service, networks in services.items()
    ]
    self.nft = {
      "nftables": [
        {"chain": {"family": "inet", "table": "filter", "name": "input", "type": "filter", "hook": "input", "policy": "drop"}},
        nft_rule([port(443), {"accept": None}]),
        nft_rule([source("10.0.0.0/24"), port(22), {"accept": None}]),
        nft_rule([
          {"match": {"op": "in", "left": {"ct": {"key": "state"}}, "right": ["established", "related"]}},
          {"accept": None},
        ]),
      ]
    }

  def run(self, argv):
    command = tuple(argv)
    if command == ("nft", "-j", "list", "ruleset"):
      return CommandResult(0, json.dumps(self.nft))
    if command == ("docker", "ps", "--format", "{{json .}}"):
      return CommandResult(0, "\n".join(json.dumps(row) for row in self.rows))
    if command == ("docker", "ps", "--filter", "label=com.docker.compose.project=cluster", "--format", "{{.ID}}"):
      return CommandResult(0, "\n".join(self.ids))
    if command == ("docker", "inspect", *self.ids):
      return CommandResult(0, json.dumps(self.inspections))
    if command == ("docker", "network", "inspect", "cluster_state"):
      containers = {f"id-{service}": {} for service in ("api", "worker", "scheduler", "mysql", "valkey")}
      return CommandResult(0, json.dumps([{"Name": "cluster_state", "Internal": True, "Containers": containers}]))
    return CommandResult(1, "")


class FakeEdgeBackend:
  def __init__(self, perspective):
    self.perspective = perspective

  def source_addresses(self, hostname, port):
    return ["10.0.0.20"] if self.perspective == "management" else ["192.168.50.20"]

  def source_address(self, hostname, port):
    return self.source_addresses(hostname, port)[0]

  def tcp_states(self, hostname, port):
    return [self.tcp_state(hostname, port)]

  def tcp_state(self, hostname, port):
    if port == 443:
      return "open"
    if port == 22:
      return "open" if self.perspective == "management" else "filtered"
    return "filtered"

  def https_status(self, origin, path):
    return 200 if self.perspective == "management" else 403

  def https_statuses(self, origin, path):
    if "cluster.example.invalid" in origin:
      return [200]
    return [self.https_status(origin, path)]


def statuses(checks):
  return {check.check_id: check.status for check in checks}


def test_read_only_host_verifier_accepts_only_the_approved_runtime_evidence():
  checks = run_host_exposure_verifier(policy(), FakeHostBackend())

  assert set(statuses(checks).values()) == {"passed"}


def test_host_verifier_fails_when_mysql_is_externally_bound():
  backend = FakeHostBackend()
  backend.rows.append({"ID": "rogue", "Ports": "0.0.0.0:3306->3306/tcp"})

  assert statuses(run_host_exposure_verifier(policy(), backend))["docker.forbidden_public_ports"] == "failed"


@pytest.mark.parametrize("ports", ["", "127.0.0.1:8080->8080/tcp,127.0.0.1:8081->8081/tcp"])
def test_host_verifier_requires_exactly_one_web_binding(ports):
  backend = FakeHostBackend()
  backend.rows[0]["Ports"] = ports

  assert statuses(run_host_exposure_verifier(policy(), backend))["docker.forbidden_public_ports"] == "failed"


@pytest.mark.parametrize(
  "ports,check_id",
  [
    ("0.0.0.0:9999->9999/tcp", "docker.forbidden_public_ports"),
    ("0.0.0.0:9999", "docker.global_port_inventory"),
    ("127.0.0.1:8080->8080/udp", "docker.forbidden_public_ports"),
  ],
)
def test_host_verifier_rejects_unapproved_or_malformed_runtime_bindings(ports, check_id):
  backend = FakeHostBackend()
  backend.rows[0]["Ports"] = ports

  assert statuses(run_host_exposure_verifier(policy(), backend))[check_id] == "failed"


def test_host_verifier_rejects_duplicate_service_identity():
  backend = FakeHostBackend()
  backend.rows.append({"ID": "id-web-2", "Ports": ""})
  backend.ids.append("id-web-2")
  backend.inspections.append({
    "Id": "id-web-2",
    "Config": {"Labels": {"com.docker.compose.project": "cluster", "com.docker.compose.service": "web"}},
    "NetworkSettings": {"Networks": {"cluster_frontend": {}}},
    "Mounts": [],
  })

  assert statuses(run_host_exposure_verifier(policy(), backend))["docker.compose_network_topology"] == "failed"


def test_host_verifier_rejects_runtime_docker_socket_mount():
  backend = FakeHostBackend()
  backend.inspections[0]["Mounts"] = [{"Type": "bind", "Source": "/tmp/alt.sock", "Destination": "/var/run/docker.sock"}]

  assert statuses(run_host_exposure_verifier(policy(), backend))["docker.compose_network_topology"] == "failed"


def test_host_verifier_fails_when_management_rule_is_broader_than_the_cidr_contract():
  backend = FakeHostBackend()
  backend.nft["nftables"][2] = nft_rule([source("10.0.0.0/8"), port(22), {"accept": None}])

  assert statuses(run_host_exposure_verifier(policy(), backend))["firewall.management_cidrs"] == "failed"


@pytest.mark.parametrize("perspective", ["user", "management"])
def test_edge_verifier_checks_both_required_vantage_points(perspective):
  checks = run_edge_exposure_verifier(policy(), perspective, FakeEdgeBackend(perspective))

  assert set(statuses(checks).values()) == {"passed"}


def test_edge_verifier_rejects_public_redirect_or_bad_tls_status():
  backend = FakeEdgeBackend("user")
  backend.https_statuses = lambda origin, path: [302] if "cluster.example.invalid" in origin else [403]

  assert statuses(run_edge_exposure_verifier(policy(), "user", backend))["edge.public_https"] == "failed"


def test_edge_verifier_fails_when_any_resolved_address_is_inconclusive():
  backend = FakeEdgeBackend("user")
  backend.source_addresses = lambda hostname, port: ["192.168.50.20", "2001:db8::20"]
  backend.tcp_states = lambda hostname, port: ["open", "filtered"] if port == 443 else ["filtered"]
  backend.https_statuses = lambda origin, path: [200, 0] if "cluster.example.invalid" in origin else [403, 403]

  assert statuses(run_edge_exposure_verifier(policy(), "user", backend))["edge.public_https"] == "failed"


def test_live_receipt_redacts_endpoint_and_source_addresses():
  receipt = build_receipt("host-live", policy(), run_host_exposure_verifier(policy(), FakeHostBackend()), "2026-07-17T00:00:00+00:00", revision="a" * 40, perspective="host", compose_sha256="a" * 64)
  serialized = json.dumps(receipt, sort_keys=True)

  assert "example.invalid" not in serialized
  assert "10.0.0.20" not in serialized
  assert receipt["summary"]["status"] == "passed"
