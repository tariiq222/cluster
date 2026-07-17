from __future__ import annotations

import json

import pytest

from scripts.host_preflight import PREFLIGHT_CHECK_IDS, run_preflight


pytestmark = pytest.mark.integration


class FakeBackend:
  def __init__(self):
    self.command_failures = set()
    self.free_bytes = 100 * 1024**3
    self.time_synchronized = True
    self.timezone = "Asia/Riyadh"
    self.dns_failures = set()
    self.tls_failures = set()
    self.http_status = 200
    self.http_documents = {
      "https://dokploy.staging.example.invalid/api/read-only/project-identity": {
        "project": {"name": "cluster-staging", "compose_name": "cluster"},
      },
      "https://registry.example.invalid/v2/third-health-cluster/platform/tags/list?n=1": {
        "name": "third-health-cluster/platform",
      },
      "https://backup.example.invalid/api/read-only/targets/cluster-backup-01": {
        "id": "cluster-backup-01",
      },
      "https://restore.example.invalid/api/read-only/targets/cluster-restore-01": {
        "id": "cluster-restore-01",
      },
    }

  def command_available(self, command):
    return command not in self.command_failures

  def run_command(self, argv):
    command = " ".join(argv[:2]) if len(argv) > 1 else argv[0]
    return argv[0] not in self.command_failures and command not in self.command_failures

  def disk_free_bytes(self, path):
    return self.free_bytes

  def time_state(self):
    return self.time_synchronized, self.timezone

  def resolve(self, hostname):
    return hostname not in self.dns_failures

  def tls_connect(self, hostname, port):
    return (hostname, port) not in self.tls_failures

  def http_head(self, url):
    return self.http_status

  def http_json(self, url, headers):
    return self.http_status, self.http_documents.get(url, {})


def status_by_id(checks):
  return {check.check_id: check.status for check in checks}


def test_all_read_only_probes_pass(valid_host_inputs, live_probe_secrets):
  checks = run_preflight(valid_host_inputs, FakeBackend(), live_probe_secrets)

  assert set(status_by_id(checks).values()) == {"passed"}
  assert {"host_inputs.contract", "secret_manifest.contract", *status_by_id(checks)} == PREFLIGHT_CHECK_IDS


def test_low_disk_fails_closed(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.free_bytes = 10 * 1024**3

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["host.disk_free"] == "failed"


def test_dns_failure_is_reported(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.dns_failures.add("cluster.staging.example.invalid")

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["public_endpoint.dns"] == "failed"


def test_registry_tls_failure_is_reported(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.tls_failures.add(("registry.example.invalid", 443))

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["registry.tls"] == "failed"


def test_backup_tls_failure_is_reported(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.tls_failures.add(("backup.example.invalid", 443))

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["backup.tls"] == "failed"


def test_docker_and_compose_failures_are_independent(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.command_failures.add("docker")

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["docker.cli"] == "failed"
  assert checks["docker.daemon"] == "failed"
  assert checks["docker.compose"] == "failed"


def test_compose_can_fail_while_docker_daemon_passes(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.command_failures.add("docker compose")

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["docker.cli"] == "passed"
  assert checks["docker.daemon"] == "passed"
  assert checks["docker.compose"] == "failed"


def test_probe_results_never_contain_environment_secret(valid_host_inputs, live_probe_secrets, monkeypatch):
  monkeypatch.setenv("BACKUP_CREDENTIALS", "must-not-enter-receipt")

  serialized = json.dumps([check.to_dict() for check in run_preflight(valid_host_inputs, FakeBackend(), live_probe_secrets)])

  assert "must-not-enter-receipt" not in serialized


def test_missing_probe_credentials_fail_closed(valid_host_inputs):
  checks = status_by_id(run_preflight(valid_host_inputs, FakeBackend(), {}))

  assert checks["dokploy.credentials"] == "failed"
  assert checks["registry.credentials"] == "failed"
  assert checks["backup.credentials"] == "failed"
  assert checks["backup.restore.credentials"] == "failed"


def test_redirect_status_is_never_accepted_as_authenticated_identity(valid_host_inputs, live_probe_secrets):
  backend = FakeBackend()
  backend.http_status = 302

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks["dokploy.health"] == "failed"
  assert checks["dokploy.identity"] == "failed"
  assert checks["registry.repository_access"] == "failed"
  assert checks["backup.target_access"] == "failed"
  assert checks["backup.restore_target_access"] == "failed"


@pytest.mark.parametrize(
  ("url", "check_id"),
  [
    ("https://dokploy.staging.example.invalid/api/read-only/project-identity", "dokploy.identity"),
    ("https://registry.example.invalid/v2/third-health-cluster/platform/tags/list?n=1", "registry.repository_access"),
    ("https://backup.example.invalid/api/read-only/targets/cluster-backup-01", "backup.target_access"),
    ("https://restore.example.invalid/api/read-only/targets/cluster-restore-01", "backup.restore_target_access"),
  ],
)
def test_authenticated_probe_identity_mismatch_fails_closed(valid_host_inputs, live_probe_secrets, url, check_id):
  backend = FakeBackend()
  backend.http_documents[url] = {"id": "unexpected", "name": "unexpected", "project": {}}

  checks = status_by_id(run_preflight(valid_host_inputs, backend, live_probe_secrets))

  assert checks[check_id] == "failed"
