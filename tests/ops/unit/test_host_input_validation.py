from __future__ import annotations

import json
from pathlib import Path

import pytest

from scripts.host_preflight import redact, validate_host_inputs, validate_secret_manifest


pytestmark = pytest.mark.unit


def error_codes(failures):
  return {failure.code for failure in failures}


def test_valid_contract_and_secret_manifest_are_accepted(valid_host_inputs, valid_secret_manifest):
  assert validate_host_inputs(valid_host_inputs) == []
  assert validate_secret_manifest(valid_secret_manifest) == []


def test_missing_required_field_is_rejected(valid_host_inputs, clone):
  candidate = clone(valid_host_inputs)
  del candidate["host"]["owner"]

  assert "missing_field" in error_codes(validate_host_inputs(candidate))


@pytest.mark.parametrize(
  "cidr",
  ["0.0.0.0/0", "0.0.0.0/1", "128.0.0.0/1", "::/0", "2000::/3", "8.8.8.0/24"],
)
def test_public_management_cidr_is_rejected(valid_host_inputs, clone, cidr):
  candidate = clone(valid_host_inputs)
  candidate["management"]["allowed_cidrs"] = [cidr]

  assert "public_management_cidr" in error_codes(validate_host_inputs(candidate))


def test_private_ipv4_and_ula_management_cidrs_are_accepted(valid_host_inputs, clone):
  candidate = clone(valid_host_inputs)
  candidate["management"]["allowed_cidrs"] = ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7"]

  assert validate_host_inputs(candidate) == []


@pytest.mark.parametrize("port", [3306, 6379])
def test_state_port_cannot_be_public(valid_host_inputs, clone, port):
  candidate = clone(valid_host_inputs)
  candidate["network"]["public_ports"] = [443, port]

  assert "state_port_public" in error_codes(validate_host_inputs(candidate))


def test_embedded_secret_field_is_rejected(valid_host_inputs, clone):
  candidate = clone(valid_host_inputs)
  candidate["registry"]["password"] = "must-not-enter-git"

  failures = validate_host_inputs(candidate)

  assert "unknown_field" in error_codes(failures)
  assert "must-not-enter-git" not in json.dumps([failure.to_dict() for failure in failures])


@pytest.mark.parametrize(
  "endpoint",
  [
    "file:///srv/cluster/backups",
    "https://user:password@backup.example.invalid",
    "https://backup.example.invalid?token=must-not-enter-git",
  ],
)
def test_unsafe_backup_target_is_rejected(valid_host_inputs, clone, endpoint):
  candidate = clone(valid_host_inputs)
  candidate["backup"]["endpoint"] = endpoint

  assert "unsafe_backup_endpoint" in error_codes(validate_host_inputs(candidate))


def test_backup_and_restore_targets_must_be_separate(valid_host_inputs, clone):
  candidate = clone(valid_host_inputs)
  candidate["backup"]["target_id"] = candidate["host"]["id"]
  candidate["backup"]["restore_target_id"] = candidate["host"]["id"]

  codes = error_codes(validate_host_inputs(candidate))

  assert "backup_target_not_separate" in codes
  assert "restore_target_not_separate" in codes


def test_backup_endpoints_must_be_independent_from_product_and_each_other(valid_host_inputs, clone):
  candidate = clone(valid_host_inputs)
  candidate["backup"]["endpoint"] = candidate["public_endpoint"]["origin"]
  candidate["backup"]["restore_endpoint"] = candidate["public_endpoint"]["origin"]

  codes = error_codes(validate_host_inputs(candidate))

  assert "backup_endpoint_not_independent" in codes
  assert "restore_endpoint_not_independent" in codes


def test_secret_manifest_rejects_values(valid_secret_manifest, clone):
  candidate = clone(valid_secret_manifest)
  candidate["secrets"][0]["value"] = "must-not-enter-git"

  failures = validate_secret_manifest(candidate)

  assert "unknown_field" in error_codes(failures)
  assert "must-not-enter-git" not in json.dumps([failure.to_dict() for failure in failures])


def test_redaction_masks_sensitive_keys_bearer_tokens_and_url_userinfo():
  candidate = {
    "password": "must-not-enter-git",
    "detail": "Authorization: Bearer abc.def.ghi",
    "basic": "Authorization: Basic dXNlcjpwYXNzd29yZA==",
    "api_key_header": "X-Api-Key: clear-api-key",
    "cookie_header": "Cookie: session=clear-cookie",
    "url": "https://user:password@example.invalid/path",
    "nested": {"api_token": "must-not-enter-git"},
  }

  serialized = json.dumps(redact(candidate))

  assert "must-not-enter-git" not in serialized
  assert "abc.def.ghi" not in serialized
  assert "dXNlcjpwYXNzd29yZA==" not in serialized
  assert "clear-api-key" not in serialized
  assert "clear-cookie" not in serialized
  assert "user:password" not in serialized
  assert serialized.count("<redacted>") >= 3


def test_checked_in_example_matches_closed_schema(valid_secret_manifest):
  root = Path(__file__).resolve().parents[3]
  inputs = json.loads((root / "infra/platform/environments/host.example.json").read_text(encoding="utf-8"))
  secrets = json.loads((root / "infra/platform/contracts/required-secrets.json").read_text(encoding="utf-8"))
  schema = json.loads((root / "infra/platform/contracts/host-inputs.schema.json").read_text(encoding="utf-8"))

  assert schema["$schema"] == "https://json-schema.org/draft/2020-12/schema"
  assert schema["additionalProperties"] is False
  assert validate_host_inputs(inputs) == []
  assert validate_secret_manifest(secrets) == []
  assert {item["name"] for item in secrets["secrets"]} == {item["name"] for item in valid_secret_manifest["secrets"]}
