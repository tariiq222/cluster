from __future__ import annotations

import copy
from typing import Any

import pytest


@pytest.fixture
def valid_host_inputs() -> dict[str, Any]:
  return {
    "contract_version": "1.0.0",
    "environment": "staging",
    "host": {
      "id": "cluster-staging-01",
      "owner": "platform-sre",
      "data_root": "/srv/cluster",
      "minimum_free_gib": 40,
      "timezone": "Asia/Riyadh",
    },
    "public_endpoint": {
      "origin": "https://cluster.staging.example.invalid",
      "ports": [443],
    },
    "management": {
      "origin": "https://dokploy.staging.example.invalid",
      "health_path": "/api/health",
      "allowed_cidrs": ["10.30.0.0/24"],
      "ports": [22, 443],
    },
    "registry": {
      "hostname": "registry.example.invalid",
      "port": 443,
      "repository": "third-health-cluster/platform",
    },
    "dokploy": {
      "project": "cluster-staging",
      "compose_name": "cluster",
      "probe_path": "/api/read-only/project-identity",
      "project_json_pointer": "/project/name",
      "compose_json_pointer": "/project/compose_name",
    },
    "storage": {
      "mysql_volume": "/srv/cluster/mysql",
      "valkey_volume": "/srv/cluster/valkey",
      "artifacts_volume": "/srv/cluster/artifacts",
    },
    "backup": {
      "endpoint": "https://backup.example.invalid",
      "probe_path": "/api/read-only/targets/cluster-backup-01",
      "identity_json_pointer": "/id",
      "target_id": "cluster-backup-01",
      "restore_endpoint": "https://restore.example.invalid",
      "restore_probe_path": "/api/read-only/targets/cluster-restore-01",
      "restore_identity_json_pointer": "/id",
      "restore_target_id": "cluster-restore-01",
    },
    "network": {
      "public_ports": [443],
      "state_ports": [3306, 6379],
    },
  }


@pytest.fixture
def valid_secret_manifest() -> dict[str, Any]:
  return {
    "manifest_version": "1.0.0",
    "secrets": [
      {"name": "APP_KEY", "owner": "platform-sre", "source": "dokploy"},
      {"name": "DB_PASSWORD", "owner": "database-sre", "source": "dokploy"},
      {"name": "DB_ROOT_PASSWORD", "owner": "database-sre", "source": "dokploy"},
      {"name": "REGISTRY_CREDENTIALS", "owner": "platform-sre", "source": "dokploy"},
      {"name": "VALKEY_PASSWORD", "owner": "platform-sre", "source": "dokploy"},
      {"name": "DOKPLOY_API_TOKEN", "owner": "platform-sre", "source": "host"},
      {"name": "BACKUP_CREDENTIALS", "owner": "platform-sre", "source": "host"},
      {"name": "BACKUP_ENCRYPTION_KEY", "owner": "security", "source": "host"},
      {"name": "RESTORE_PROBE_CREDENTIALS", "owner": "platform-sre", "source": "host"},
    ],
  }


@pytest.fixture
def live_probe_secrets() -> dict[str, str]:
  return {
    "DOKPLOY_API_TOKEN": "dokploy-probe-token",
    "REGISTRY_CREDENTIALS": "probe-user:registry-probe-password",
    "BACKUP_CREDENTIALS": "backup-probe-token",
    "RESTORE_PROBE_CREDENTIALS": "restore-probe-token",
  }


@pytest.fixture
def clone():
  return copy.deepcopy
