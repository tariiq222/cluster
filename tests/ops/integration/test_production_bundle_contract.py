from __future__ import annotations

import subprocess
import os
from pathlib import Path

import pytest
import yaml

from scripts.production_bundle_policy import validate_bundle


pytestmark = pytest.mark.integration


ROOT = Path(__file__).resolve().parents[3]
COMPOSE = ROOT / "infra/platform/production/compose.yaml"
API_DOCKERFILE = ROOT / "apps/api/Dockerfile"
WEB_DOCKERFILE = ROOT / "apps/web/Dockerfile"


def test_checked_in_production_bundle_passes_policy():
  assert validate_bundle(COMPOSE, API_DOCKERFILE, WEB_DOCKERFILE) == []


def test_compose_model_renders_without_interpolating_secrets():
  result = subprocess.run(
    ["docker", "compose", "--file", str(COMPOSE), "config", "--no-interpolate", "--quiet"],
    cwd=ROOT,
    capture_output=True,
    text=True,
    check=False,
  )

  assert result.returncode == 0, result.stderr


def test_interpolated_compose_preserves_php_healthcheck_variables():
  environment = os.environ.copy()
  environment.update({
    "API_IMAGE": "cluster-api:test",
    "WEB_IMAGE": "cluster-web:test",
    "APP_KEY": "base64:test-only",
    "APP_URL": "http://127.0.0.1:18080",
    "DB_PASSWORD": "test-only",
    "DB_ROOT_PASSWORD": "test-only",
    "VALKEY_PASSWORD": "test-only",
  })
  result = subprocess.run(
    ["docker", "compose", "--file", str(COMPOSE), "config"],
    cwd=ROOT,
    env=environment,
    capture_output=True,
    text=True,
    check=False,
  )

  assert result.returncode == 0, result.stderr
  assert result.stderr == ""
  document = yaml.safe_load(result.stdout)
  api_healthcheck = document["services"]["api"]["healthcheck"]["test"]
  assert "$socket=@fsockopen" in api_healthcheck[-1]


def test_compose_declares_expected_services_and_internal_state_network():
  document = yaml.safe_load(COMPOSE.read_text(encoding="utf-8"))

  assert set(document["services"]) == {"web", "api", "worker", "scheduler", "migrate", "mysql", "valkey"}
  assert document["networks"]["state"]["internal"] is True
  assert "ports" not in document["services"]["mysql"]
  assert "ports" not in document["services"]["valkey"]
  assert document["services"]["migrate"]["healthcheck"] == {"disable": True}
  assert all(service["pull_policy"] == "never" for service in document["services"].values())
