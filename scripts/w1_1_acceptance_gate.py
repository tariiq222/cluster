#!/usr/bin/env python3
"""Fail-closed W1.1 Gate-07 over path-oriented, signed evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Mapping

# Keep the documented `python3 scripts/w1_1_acceptance_gate.py ...` entrypoint
# equivalent to module execution without requiring an installed package.
if __package__ in {None, ""}:
  _repo_root = Path(__file__).resolve().parent.parent
  if str(_repo_root) not in sys.path:
    sys.path.insert(0, str(_repo_root))

from scripts.backup_restore_evidence import verify_restore_receipt_integrity
from scripts.deployment_evidence import validate_release_evidence
from scripts.host_preflight import PREFLIGHT_CHECK_IDS
from scripts.net04_network_policy import EDGE_CHECK_IDS, HOST_CHECK_IDS
from scripts.release_descriptor import DescriptorError, validate_descriptor, verify_descriptor
from scripts.signed_evidence import (
  ARTIFACT_TYPES,
  CommandResult,
  DuplicateJSONKey,
  load_json_no_duplicates,
  validate_envelope,
  verify_envelope,
)


CONTRACT_VERSION = "1.0.0"
RECEIPT_VERSION = "1.0.0"
REVISION_RE = re.compile(r"^[0-9a-f]{40}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
FINGERPRINT_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
TEST_IDS = tuple(f"TEST-R1-W1.1-{index:02d}" for index in range(1, 9))
APPROVAL_ROLES = ("technology_lead", "sre_lead", "security_lead")
ENVELOPE_KEYS = ("host", "net-host", "net-user", "net-management", "deployment", "restore", "ci")
REQUIRED_CI_JOBS = (
  "test-api", "test-web", "test-e2e-w1-1", "verify-boundaries", "verify-ci-config",
  "release-build-images", "release-sbom-provenance", "release-sign-verify", "verify-build",
)
MAX_AGE_LIMIT_SECONDS = 7 * 24 * 60 * 60
DR_CHECK_IDS = ("checksum", "signature", "database", "files", "schema", "health")
HOST_REDACTION_POLICY = "sensitive keys, authorization, API keys, cookies, URL userinfo, and secret assignments"
HOST_SIGNATURE_REASON = "live staging-host acceptance signs this digest outside Git"
SENSITIVE_KEY_RE = re.compile(r"(?:password|passwd|token|secret|credential|private[_-]?key|api[_-]?key)", re.IGNORECASE)
PRIVATE_KEY_RE = re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----", re.IGNORECASE)


@dataclass(frozen=True)
class Failure:
  code: str
  path: str
  message: str

  def to_dict(self) -> dict[str, str]:
    return {"code": self.code, "path": self.path, "message": self.message}


def _fail(out: list[Failure], code: str, path: str, message: str) -> None:
  out.append(Failure(code, path, message))


def _obj(value: Any, path: str, fields: set[str], out: list[Failure]) -> dict[str, Any]:
  if not isinstance(value, dict):
    _fail(out, "invalid_type", path, "must be an object")
    return {}
  for key in sorted(fields - set(value)):
    _fail(out, "missing_field", f"{path}.{key}", "required field is missing")
  for key in sorted(set(value) - fields):
    _fail(out, "unknown_field", f"{path}.{key}", "field is not allowed")
  return value


def _reject_sensitive_fields(value: Any, path: str, failures: list[Failure]) -> None:
  if isinstance(value, dict):
    for key, item in value.items():
      child = f"{path}.{key}"
      if SENSITIVE_KEY_RE.search(str(key)) and key != "secret_values_included":
        _fail(failures, "secret_field", child, "secret-like fields are forbidden in Gate evidence")
      _reject_sensitive_fields(item, child, failures)
  elif isinstance(value, list):
    for index, item in enumerate(value):
      _reject_sensitive_fields(item, f"{path}[{index}]", failures)
  elif isinstance(value, str) and PRIVATE_KEY_RE.search(value):
    _fail(failures, "private_key_material", path, "private key material is forbidden")


def _timestamp(value: Any, path: str, out: list[Failure]) -> datetime | None:
  if not isinstance(value, str) or not value.strip():
    _fail(out, "invalid_timestamp", path, "must be RFC 3339 with timezone")
    return None
  try:
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
  except ValueError:
    _fail(out, "invalid_timestamp", path, "must be RFC 3339 with timezone")
    return None
  if parsed.tzinfo is None:
    _fail(out, "naive_timestamp", path, "timezone is required")
    return None
  return parsed.astimezone(timezone.utc)


def _canonical(value: Any) -> bytes:
  return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def _sha256(path: Path) -> str:
  digest = hashlib.sha256()
  fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
  with os.fdopen(fd, "rb") as stream:
    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
      digest.update(chunk)
  return digest.hexdigest()


def _read_stable_bytes(path: Path) -> tuple[bytes, str]:
  fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
  try:
    before = os.fstat(fd)
    if not stat.S_ISREG(before.st_mode):
      raise OSError("not a regular file")
    chunks: list[bytes] = []
    digest = hashlib.sha256()
    while True:
      chunk = os.read(fd, 1024 * 1024)
      if not chunk:
        break
      chunks.append(chunk)
      digest.update(chunk)
    after = os.fstat(fd)
  finally:
    os.close(fd)
  before_binding = (before.st_dev, before.st_ino, before.st_mode, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
  after_binding = (after.st_dev, after.st_ino, after.st_mode, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
  if before_binding != after_binding:
    raise OSError("file changed while being read")
  return b"".join(chunks), digest.hexdigest()


def _json_from_bytes(raw: bytes) -> Any:
  return json.loads(raw.decode("utf-8"), object_pairs_hook=_pairs_without_duplicates)


def _pairs_without_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
  result: dict[str, Any] = {}
  for key, value in pairs:
    if key in result:
      raise DuplicateJSONKey(f"duplicate JSON key: {key}")
    result[key] = value
  return result


def _safe_file(reference: Any, root: Path, path: str, failures: list[Failure]) -> Path | None:
  if not isinstance(reference, str) or not reference or Path(reference).is_absolute() or ".." in Path(reference).parts or "://" in reference or "\\" in reference or not re.fullmatch(r"[^\s\x00\r\n]+", reference):
    _fail(failures, "unsafe_path", path, "must be a safe relative path without traversal or URI syntax")
    return None
  root = root.resolve()
  candidate = root / reference
  try:
    candidate.relative_to(root)
    current = root
    for part in Path(reference).parts:
      current /= part
      if current.is_symlink():
        _fail(failures, "symlink_path", path, "symlink paths are not accepted")
        return None
    fd = os.open(candidate, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    os.close(fd)
  except (FileNotFoundError, NotADirectoryError):
    _fail(failures, "missing_path", path, "referenced file does not exist")
    return None
  except (OSError, ValueError):
    _fail(failures, "unsafe_path", path, "referenced path is not a readable regular file")
    return None
  if not candidate.is_file():
    _fail(failures, "missing_path", path, "referenced path must be a regular file")
    return None
  return candidate


def _proof(value: Any, path: str, root: Path, failures: list[Failure]) -> tuple[Path | None, str | None]:
  item = _obj(value, path, {"path", "sha256"}, failures)
  expected = item.get("sha256")
  if not isinstance(expected, str) or not SHA256_RE.fullmatch(expected):
    _fail(failures, "invalid_sha256", f"{path}.sha256", "must be a lowercase SHA-256 digest")
  candidate = _safe_file(item.get("path"), root, f"{path}.path", failures)
  if candidate is None:
    return None, expected if isinstance(expected, str) else None
  try:
    actual = _sha256(candidate)
  except OSError:
    _fail(failures, "hash_read_failed", f"{path}.path", "referenced file could not be hashed")
    return candidate, expected if isinstance(expected, str) else None
  if isinstance(expected, str) and actual != expected:
    _fail(failures, "hash_mismatch", f"{path}.sha256", "does not match rooted bytes")
  return candidate, actual


def _revision(value: Any, expected: str, path: str, failures: list[Failure]) -> None:
  if not isinstance(value, str) or not REVISION_RE.fullmatch(value):
    _fail(failures, "revision_invalid", path, "must be the exact lowercase 40-character gate revision")
  elif value != expected:
    _fail(failures, "revision_mismatch", path, "does not match the gate revision")


def _fresh(timestamp: datetime | None, as_of: datetime, max_age: int, path: str, failures: list[Failure]) -> None:
  if timestamp is None:
    return
  age = (as_of - timestamp).total_seconds()
  if age < 0:
    _fail(failures, "future_evidence", path, "evidence is in the future")
  elif age > max_age:
    _fail(failures, "stale_evidence", path, "evidence exceeds the freshness window")


def _validate_trust_policy(document: Any, *, as_of_root: Path, failures: list[Failure]) -> dict[str, dict[str, Any]]:
  root = _obj(document, "$", {"contract_version", "keys"}, failures)
  if root.get("contract_version") != CONTRACT_VERSION:
    _fail(failures, "unsupported_trust_policy", "$.contract_version", "must be 1.0.0")
  keys = root.get("keys")
  if not isinstance(keys, dict) or not keys:
    _fail(failures, "missing_trust_keys", "$.keys", "must contain at least one key")
    return {}
  result: dict[str, dict[str, Any]] = {}
  for key_id, item in keys.items():
    path = f"$.keys.{key_id}"
    if not isinstance(key_id, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{2,127}", key_id):
      _fail(failures, "invalid_key_id", path, "key ID is not opaque and valid")
    entry = _obj(item, path, {"public_key_path", "public_key_fingerprint", "allowed_roles", "allowed_artifact_types"}, failures)
    public_path = entry.get("public_key_path")
    if not isinstance(public_path, str) or not Path(public_path).is_absolute() or Path(public_path).is_symlink() or not Path(public_path).is_file() or "private" in Path(public_path).name.lower():
      _fail(failures, "unsafe_public_key", f"{path}.public_key_path", "public key must be an absolute non-symlink file")
    elif "PRIVATE KEY" in Path(public_path).read_text(encoding="utf-8", errors="ignore").upper():
      _fail(failures, "private_key_rejected", f"{path}.public_key_path", "private key material is forbidden")
    fingerprint = entry.get("public_key_fingerprint")
    if not isinstance(fingerprint, str) or not FINGERPRINT_RE.fullmatch(fingerprint):
      _fail(failures, "invalid_key_fingerprint", f"{path}.public_key_fingerprint", "must be sha256:<64 lowercase hex>")
    elif isinstance(public_path, str) and Path(public_path).is_file() and "private" not in Path(public_path).name.lower() and "PRIVATE KEY" not in Path(public_path).read_text(encoding="utf-8", errors="ignore").upper():
      if "sha256:" + _sha256(Path(public_path)) != fingerprint:
        _fail(failures, "public_key_fingerprint_mismatch", f"{path}.public_key_fingerprint", "does not match public key bytes")
    for field in ("allowed_roles", "allowed_artifact_types"):
      values = entry.get(field)
      if not isinstance(values, list) or not values or not all(isinstance(value, str) and value for value in values):
        _fail(failures, f"invalid_{field}", f"{path}.{field}", "must be a non-empty string list")
      elif len(values) != len(set(values)):
        _fail(failures, f"invalid_{field}", f"{path}.{field}", "must contain unique values")
      elif field == "allowed_artifact_types" and not set(values) <= ARTIFACT_TYPES | {"release-descriptor"}:
        _fail(failures, "unknown_artifact_type", f"{path}.{field}", "contains an unsupported artifact type")
    result[key_id] = entry
  return result


def _cosign_pin(binary: Path, expected_hash: str, expected_version: str, failures: list[Failure]) -> None:
  if not binary.is_absolute() or binary.is_symlink() or not binary.is_file() or not os.access(binary, os.X_OK):
    _fail(failures, "unsafe_cosign_binary", "$.cosign_binary", "must be an absolute executable non-symlink")
    return
  if binary.name not in {"cosign", "cosign-linux-amd64", "cosign-darwin-amd64", "cosign-darwin-arm64"}:
    _fail(failures, "not_cosign_binary", "$.cosign_binary", "binary name must be cosign")
  if not isinstance(expected_hash, str) or not SHA256_RE.fullmatch(expected_hash) or _sha256(binary) != expected_hash:
    _fail(failures, "cosign_hash_mismatch", "$.cosign_sha256", "binary bytes do not match the pinned hash")
  try:
    result = subprocess.run([str(binary), "version"], stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=30, check=False)
  except (OSError, subprocess.TimeoutExpired):
    _fail(failures, "cosign_version_failed", "$.cosign_version", "Cosign version could not be read")
    return
  reported: list[str] = []
  for line in (result.stdout + "\n" + result.stderr).splitlines():
    match = re.fullmatch(r"\s*cosign\s+version\s+(v?\d+\.\d+\.\d+(?:[-+][\w.-]+)?)\s*", line, flags=re.IGNORECASE)
    if match is None:
      match = re.fullmatch(r"\s*GitVersion:\s*(v?\d+\.\d+\.\d+(?:[-+][\w.-]+)?)\s*", line, flags=re.IGNORECASE)
    if match is not None:
      reported.append(match.group(1))
  if result.returncode != 0 or len(reported) != 1 or reported[0] != expected_version:
    _fail(failures, "cosign_version_mismatch", "$.cosign_version", "Cosign version is not exactly the pinned version")


def _load_proof_json(proof: Any, root: Path, path: str, failures: list[Failure]) -> tuple[dict[str, Any] | None, Path | None, str | None]:
  candidate, _ = _proof(proof, path, root, failures)
  if candidate is None:
    return None, None, None
  try:
    raw, actual = _read_stable_bytes(candidate)
    expected = proof.get("sha256") if isinstance(proof, dict) else None
    if actual != expected:
      _fail(failures, "hash_mismatch", f"{path}.sha256", "changed before JSON parsing")
      return None, candidate, actual
    document = _json_from_bytes(raw)
  except (OSError, UnicodeError, ValueError, DuplicateJSONKey) as error:
    _fail(failures, "invalid_json_artifact", path, str(error))
    return None, candidate, None
  if not isinstance(document, dict):
    _fail(failures, "invalid_artifact", path, "artifact must be a JSON object")
    return None, candidate, actual
  return document, candidate, actual


def _verify_envelope_item(
  proof: Any,
  artifact_type: str,
  *,
  evidence_root: Path,
  gate_revision: str,
  as_of: datetime,
  max_age: int,
  trust_keys: Mapping[str, Mapping[str, Any]],
  cosign_binary: Path,
  cosign_sha256: str,
  cosign_version: str,
  failures: list[Failure],
  backend: Any = None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None, Path | None]:
  envelope, envelope_path, envelope_hash = _load_proof_json(proof, evidence_root, f"$.evidence.{artifact_type}", failures)
  if envelope is None:
    return None, None, None
  if envelope.get("artifact_type") != artifact_type:
    _fail(failures, "artifact_type_mismatch", f"$.evidence.{artifact_type}.artifact_type", "envelope type does not match manifest slot")
  signer = envelope.get("signer") if isinstance(envelope.get("signer"), dict) else {}
  key_id = signer.get("key_id")
  policy = trust_keys.get(key_id) if isinstance(key_id, str) else None
  if policy is None:
    _fail(failures, "unknown_signing_key", f"$.evidence.{artifact_type}.signer.key_id", "key is not in the closed trust policy")
  else:
    if artifact_type not in policy.get("allowed_artifact_types", []) or signer.get("role") not in policy.get("allowed_roles", []):
      _fail(failures, "trust_policy_role_type_mismatch", f"$.evidence.{artifact_type}.signer", "key is not allowed for role or artifact type")
  structural = validate_envelope(envelope, evidence_root=evidence_root, as_of=as_of, max_age_seconds=max_age)
  failures.extend(Failure(f.code, f"$.evidence.{artifact_type}{f.path[1:]}", f.message) for f in structural)
  _revision(envelope.get("git_revision"), gate_revision, f"$.evidence.{artifact_type}.git_revision", failures)
  observed = _timestamp(envelope.get("observed_at"), f"$.evidence.{artifact_type}.observed_at", failures)
  _fresh(observed, as_of, max_age, f"$.evidence.{artifact_type}.observed_at", failures)
  if policy is not None and not structural:
    key_path = Path(policy["public_key_path"])
    crypto = verify_envelope(
      envelope,
      evidence_root=evidence_root,
      as_of=as_of,
      max_age_seconds=max_age,
      cosign_binary=cosign_binary,
      cosign_sha256=cosign_sha256,
      cosign_version=cosign_version,
      public_key=key_path,
      backend=backend,
    )
    failures.extend(Failure(f.code, f"$.evidence.{artifact_type}{f.path[1:]}", f.message) for f in crypto)
    if envelope_path is not None and envelope_hash is not None:
      try:
        if _sha256(envelope_path) != envelope_hash:
          _fail(failures, "envelope_changed_after_crypto", f"$.evidence.{artifact_type}", "signed envelope changed after cryptographic verification")
          return None, None, envelope_path
      except OSError:
        _fail(failures, "envelope_rehash_failed", f"$.evidence.{artifact_type}", "signed envelope could not be rehashed")
        return None, None, envelope_path
  raw_artifact: dict[str, Any] | None = None
  if not structural:
    raw_path = _safe_file(envelope.get("artifact_path"), evidence_root, f"$.evidence.{artifact_type}.artifact_path", failures)
    if raw_path is not None:
      try:
        raw_bytes, raw_hash = _read_stable_bytes(raw_path)
        if raw_hash != envelope.get("artifact_sha256"):
          _fail(failures, "artifact_hash_mismatch", f"$.evidence.{artifact_type}.artifact_sha256", "raw artifact hash changed")
          return envelope, None, envelope_path
        raw_value = _json_from_bytes(raw_bytes)
        if not isinstance(raw_value, dict):
          _fail(failures, "invalid_raw_artifact", f"$.evidence.{artifact_type}.artifact_path", "raw artifact must be a JSON object")
        else:
          raw_artifact = raw_value
      except (OSError, ValueError, DuplicateJSONKey) as error:
        _fail(failures, "invalid_raw_artifact", f"$.evidence.{artifact_type}.artifact_path", str(error))
  return envelope, raw_artifact, envelope_path


def _receipt_self_hash(document: Mapping[str, Any]) -> bool:
  expected = document.get("receipt_sha256")
  if not isinstance(expected, str) or not SHA256_RE.fullmatch(expected):
    return False
  unsigned = dict(document)
  unsigned.pop("receipt_sha256", None)
  return hashlib.sha256(_canonical(unsigned)).hexdigest() == expected


def _validate_host(document: dict[str, Any], revision: str, envelope: Mapping[str, Any], failures: list[Failure]) -> None:
  expected = {"receipt_version", "generated_at", "mode", "source_revision", "input_sha256", "target", "summary", "checks", "redaction", "signature", "receipt_sha256"}
  _obj(document, "$.host.artifact", expected, failures)
  _reject_sensitive_fields(document, "$.host.artifact", failures)
  if document.get("receipt_version") != "1.0.0" or document.get("mode") != "preflight":
    _fail(failures, "host_not_preflight", "$.host.artifact.mode", "host artifact must be preflight")
  _revision(document.get("source_revision"), revision, "$.host.artifact.source_revision", failures)
  if not isinstance(document.get("input_sha256"), str) or not SHA256_RE.fullmatch(document.get("input_sha256", "")):
    _fail(failures, "host_input_hash_invalid", "$.host.artifact.input_sha256", "host input hash must be exact SHA-256")
  target = _obj(document.get("target"), "$.host.artifact.target", {"environment", "host_id"}, failures)
  if not all(isinstance(target.get(key), str) and target.get(key, "").strip() and target.get(key) != "unknown" for key in ("environment", "host_id")):
    _fail(failures, "host_target_invalid", "$.host.artifact.target", "host target environment and identity must be explicit")
  summary = _obj(document.get("summary"), "$.host.artifact.summary", {"status", "passed", "failed"}, failures)
  if summary.get("status") != "passed" or summary.get("passed") != len(PREFLIGHT_CHECK_IDS) or summary.get("failed") != 0:
    _fail(failures, "host_not_passed", "$.host.artifact.summary.status", "host preflight must pass")
  checks = document.get("checks")
  check_ids = [item.get("id") for item in checks] if isinstance(checks, list) and all(isinstance(item, dict) for item in checks) else []
  if not isinstance(checks, list) or len(checks) != len(PREFLIGHT_CHECK_IDS) or any(not isinstance(value, str) for value in check_ids) or set(check_ids) != PREFLIGHT_CHECK_IDS or any(set(item) != {"id", "status", "detail"} or item.get("status") != "passed" or not isinstance(item.get("detail"), str) or not item.get("detail", "").strip() for item in checks if isinstance(item, dict)):
    _fail(failures, "host_check_failed", "$.host.artifact.checks", "exact host preflight checks must all pass")
  redaction = _obj(document.get("redaction"), "$.host.artifact.redaction", {"policy", "secret_values_included"}, failures)
  if redaction.get("policy") != HOST_REDACTION_POLICY or redaction.get("secret_values_included") is not False:
    _fail(failures, "host_redaction_failed", "$.host.artifact.redaction", "host receipt must prove secret redaction")
  signature = _obj(document.get("signature"), "$.host.artifact.signature", {"status", "reason"}, failures)
  if signature.get("status") != "not-signed" or signature.get("reason") != HOST_SIGNATURE_REASON:
    _fail(failures, "host_signature_invalid", "$.host.artifact.signature", "host receipt external-signature marker is invalid")
  if not _receipt_self_hash(document):
    _fail(failures, "host_self_hash_invalid", "$.host.artifact.receipt_sha256", "host receipt self-hash is invalid")
  observed = _timestamp(document.get("generated_at"), "$.host.artifact.generated_at", failures)
  envelope_observed = _timestamp(envelope.get("observed_at"), "$.host.observed_at", failures)
  if observed and envelope_observed and observed != envelope_observed:
    _fail(failures, "host_observed_at_mismatch", "$.host.observed_at", "envelope observation must match host artifact")


def _validate_net(document: dict[str, Any], artifact_type: str, revision: str, policy_hash: str | None, descriptor: Mapping[str, Any], failures: list[Failure], envelope_observed: datetime | None = None, as_of: datetime | None = None, max_age: int = 0) -> str | None:
  expected = {"receipt_version", "verifier_version", "task_id", "mode", "perspective", "git_revision", "policy_sha256", "redaction", "checks", "summary", "signature", "receipt_sha256", "observed_at"}
  if artifact_type == "net-host":
    expected.add("compose_sha256")
  _obj(document, f"$.{artifact_type}.artifact", expected, failures)
  if document.get("receipt_version") != "1.1.0" or document.get("verifier_version") != "1.1.0" or document.get("task_id") != "W11-NET-04":
    _fail(failures, "net_contract_identity_mismatch", f"$.{artifact_type}.artifact", "NET receipt contract identity is not exact")
  expected_mode = "host-live" if artifact_type == "net-host" else "edge-live"
  expected_perspective = {"net-host": "host", "net-user": "user", "net-management": "management"}[artifact_type]
  if document.get("mode") != expected_mode or document.get("perspective") != expected_perspective:
    _fail(failures, "net_mode_perspective_mismatch", f"$.{artifact_type}.artifact", "NET mode and perspective are not exact")
  _revision(document.get("git_revision"), revision, f"$.{artifact_type}.artifact.git_revision", failures)
  current_hash = document.get("policy_sha256")
  if not isinstance(current_hash, str) or not SHA256_RE.fullmatch(current_hash):
    _fail(failures, "net_policy_hash_invalid", f"$.{artifact_type}.artifact.policy_sha256", "must be a SHA-256 hash")
  elif policy_hash is not None and current_hash != policy_hash:
    _fail(failures, "net_policy_hash_mismatch", f"$.{artifact_type}.artifact.policy_sha256", "NET policy hash differs")
  expected_check_ids = HOST_CHECK_IDS if artifact_type == "net-host" else EDGE_CHECK_IDS
  summary = _obj(document.get("summary"), f"$.{artifact_type}.artifact.summary", {"status", "check_count"}, failures)
  if summary.get("status") != "passed" or summary.get("check_count") != len(expected_check_ids):
    _fail(failures, "net_not_passed", f"$.{artifact_type}.artifact.summary.status", "NET receipt must pass")
  checks = document.get("checks")
  check_ids = [item.get("id") for item in checks] if isinstance(checks, list) and all(isinstance(item, dict) for item in checks) else []
  if not isinstance(checks, list) or len(checks) != len(expected_check_ids) or any(not isinstance(value, str) for value in check_ids) or set(check_ids) != expected_check_ids or any(not isinstance(item, dict) or set(item) != {"id", "status", "detail"} or item.get("status") != "passed" or not isinstance(item.get("detail"), str) or not item.get("detail", "").strip() for item in checks):
    _fail(failures, "net_checks_failed", f"$.{artifact_type}.artifact.checks", "NET checks must be non-empty and all passed")
  redaction = _obj(document.get("redaction"), f"$.{artifact_type}.artifact.redaction", {"secret_values_included", "endpoint_addresses_included", "source_addresses_included", "raw_command_output_included"}, failures)
  if any(redaction.get(key) is not False for key in ("secret_values_included", "endpoint_addresses_included", "source_addresses_included", "raw_command_output_included")):
    _fail(failures, "net_redaction_failed", f"$.{artifact_type}.artifact.redaction", "NET receipt redaction must be complete")
  signature = _obj(document.get("signature"), f"$.{artifact_type}.artifact.signature", {"status"}, failures)
  if signature.get("status") != "not-signed":
    _fail(failures, "net_signature_invalid", f"$.{artifact_type}.artifact.signature", "NET receipt signature status must be not-signed")
  if not _receipt_self_hash(document):
    _fail(failures, "net_self_hash_invalid", f"$.{artifact_type}.artifact.receipt_sha256", "NET receipt self-hash is invalid")
  raw_observed = _timestamp(document.get("observed_at"), f"$.{artifact_type}.artifact.observed_at", failures)
  if raw_observed is not None and envelope_observed is not None and raw_observed != envelope_observed:
    _fail(failures, "net_observed_at_mismatch", f"$.{artifact_type}.artifact.observed_at", "raw NET observation must match its signed envelope")
  if raw_observed is not None and as_of is not None and max_age:
    _fresh(raw_observed, as_of, max_age, f"$.{artifact_type}.artifact.observed_at", failures)
  if artifact_type == "net-host":
    compose = document.get("compose_sha256")
    expected_compose = descriptor.get("compose", {}).get("sha256") if isinstance(descriptor.get("compose"), dict) else None
    if not isinstance(compose, str) or not SHA256_RE.fullmatch(compose.replace("sha256:", "")) or compose.removeprefix("sha256:") != str(expected_compose).removeprefix("sha256:"):
      _fail(failures, "net_compose_hash_mismatch", "$.net-host.artifact.compose_sha256", "NET host Compose hash differs from release descriptor")
  return current_hash if isinstance(current_hash, str) else policy_hash


def _validate_deployment(document: dict[str, Any], descriptor: Mapping[str, Any], revision: str, evidence_root: Path, as_of: datetime, failures: list[Failure], envelope_observed: datetime | None = None, max_age: int = 0) -> None:
  for failure in validate_release_evidence(document, evidence_root=evidence_root, as_of=as_of):
    _fail(failures, "invalid_deployment_evidence", f"$.deployment.artifact.{failure.path.lstrip('$.')}", failure.message)
  n = document.get("release_n") if isinstance(document.get("release_n"), dict) else {}
  n1 = document.get("release_n_plus_1") if isinstance(document.get("release_n_plus_1"), dict) else {}
  descriptor_images = descriptor.get("images", {})
  expected_images = {name: item.get("digest") for name, item in descriptor_images.items() if isinstance(item, dict)}
  if n1.get("git_commit") != revision or n1.get("images") != expected_images:
    _fail(failures, "deployment_descriptor_mismatch", "$.deployment.artifact.release_n_plus_1", "N+1 revision or image digests differ from descriptor")
  expected_compose = descriptor.get("compose", {}).get("sha256") if isinstance(descriptor.get("compose"), dict) else None
  if n1.get("compose_sha256") != expected_compose:
    _fail(failures, "deployment_compose_mismatch", "$.deployment.artifact.release_n_plus_1.compose_sha256", "N+1 Compose hash differs from descriptor")
  migration = n1.get("migration") if isinstance(n1.get("migration"), dict) else {}
  if migration.get("destructive") is True:
    _fail(failures, "destructive_migration_not_gateable", "$.deployment.artifact.release_n_plus_1.migration", "destructive N+1 migrations require separately bound backup evidence")
  event_migration = _obj(document.get("migration"), "$.deployment.artifact.migration", {"compatibility_status", "pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment", "observed_at"}, failures)
  if not isinstance(event_migration.get("pre_backup_id"), str) or not event_migration.get("pre_backup_id", "").strip():
    _fail(failures, "pre_backup_binding_missing", "$.deployment.artifact.migration.pre_backup_id", "pre-backup identity must be explicitly bound")
  if not isinstance(event_migration.get("pre_backup_manifest_sha256"), str) or not SHA256_RE.fullmatch(event_migration.get("pre_backup_manifest_sha256", "")):
    _fail(failures, "pre_backup_manifest_missing", "$.deployment.artifact.migration.pre_backup_manifest_sha256", "pre-backup manifest hash must be a SHA-256 digest")
  completed = _timestamp(event_migration.get("pre_backup_completed_at"), "$.deployment.artifact.migration.pre_backup_completed_at", failures)
  migration_observed = _timestamp(event_migration.get("observed_at"), "$.deployment.artifact.migration.observed_at", failures)
  for path, timestamp in (("$.deployment.artifact.migration.pre_backup_completed_at", completed), ("$.deployment.artifact.migration.observed_at", migration_observed)):
    if timestamp is not None:
      _fresh(timestamp, as_of, max_age, path, failures)
      if envelope_observed is not None and timestamp > envelope_observed:
        _fail(failures, "deployment_observed_at_mismatch", path, "deployment raw timestamp cannot follow its signed envelope observation")
  for key in ("post_deploy", "rollback"):
    item = document.get(key)
    if isinstance(item, dict) and isinstance(item.get("observed_at"), str):
      observed = _timestamp(item.get("observed_at"), f"$.deployment.artifact.{key}.observed_at", failures)
      if observed is not None:
        _fresh(observed, as_of, max_age, f"$.deployment.artifact.{key}.observed_at", failures)
        if envelope_observed is not None and observed > envelope_observed:
          _fail(failures, "deployment_observed_at_mismatch", f"$.deployment.artifact.{key}.observed_at", "deployment raw timestamp cannot follow its signed envelope observation")
  if event_migration.get("pre_backup_environment") not in {"staging", "production"}:
    _fail(failures, "pre_backup_environment_invalid", "$.deployment.artifact.migration.pre_backup_environment", "pre-backup environment must be explicit")
  if migration.get("compatibility") == "requires_pre_backup" and not event_migration.get("pre_backup_id"):
    _fail(failures, "pre_backup_binding_missing", "$.deployment.artifact.migration.pre_backup_id", "pre-backup identity must be explicitly bound")
  rollback = document.get("rollback") if isinstance(document.get("rollback"), dict) else {}
  if rollback.get("to_git_commit") != n.get("git_commit") or rollback.get("to_images") != n.get("images") or rollback.get("to_compose_sha256") != n.get("compose_sha256") or rollback.get("from_git_commit") != n1.get("git_commit") or rollback.get("from_images") != n1.get("images") or rollback.get("from_compose_sha256") != n1.get("compose_sha256"):
    _fail(failures, "rollback_not_exact_n", "$.deployment.artifact.rollback", "rollback must bind exactly N+1 to N")


def _validate_restore(document: dict[str, Any], failures: list[Failure], envelope_observed: datetime | None = None, as_of: datetime | None = None, max_age: int = 0, evidence_root: Path | None = None, cosign_sha256: str | None = None, cosign_version: str | None = None, signer_fingerprint: str | None = None) -> None:
  expected = {"receipt_version", "contract", "status", "acceptance", "environment", "backup_id", "backup_manifest_sha256", "restore_id", "source_host_id", "source_storage_id", "source_identity", "backup_target_id", "backup_target_host_id", "backup_target_storage_id", "backup_target_identity", "restore_target_id", "restore_target_host_id", "restore_target_storage_id", "restore_target_identity", "checks", "data_proof", "backup_encryption", "checksum_algorithm", "signature_verification", "timing", "raw_timing", "critical_journeys", "observed_at", "receipt_sha256"}
  _obj(document, "$.restore.artifact", expected, failures)
  if document.get("receipt_version") != "1.0.0" or document.get("contract") != "W11-DR-06":
    _fail(failures, "restore_contract_identity_mismatch", "$.restore.artifact", "DR receipt contract identity is not exact")
  if document.get("status") != "passed" or document.get("environment") != "production":
    _fail(failures, "restore_not_acceptable", "$.restore.artifact", "restore must be passed in production")
  if not verify_restore_receipt_integrity(document):
    _fail(failures, "restore_self_hash_invalid", "$.restore.artifact.receipt_sha256", "restore receipt self-hash is invalid")
  acceptance = document.get("acceptance")
  if not isinstance(acceptance, dict) or set(acceptance) != {"status", "acceptable"} or acceptance.get("status") != "unsigned" or acceptance.get("acceptable") is not False:
    _fail(failures, "restore_acceptance_wrapper", "$.restore.artifact.acceptance", "unsigned/self-asserted acceptance is not trusted")
  signature = document.get("signature_verification")
  signature_fields = {"returncode", "verified", "command_sha256", "stdout_sha256", "stderr_sha256", "artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256", "cosign_version"}
  hash_fields = ("command_sha256", "stdout_sha256", "stderr_sha256", "artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256")
  if not isinstance(signature, dict) or set(signature) != signature_fields or signature.get("returncode") != 0 or signature.get("verified") is not True or not all(isinstance(signature.get(key), str) and SHA256_RE.fullmatch(signature[key]) for key in hash_fields) or not isinstance(signature.get("cosign_version"), str) or not signature.get("cosign_version"):
    _fail(failures, "restore_signature_invalid", "$.restore.artifact.signature_verification", "fixed Cosign binding must be verified")
  elif cosign_sha256 is not None and signature.get("cosign_sha256") != cosign_sha256:
    _fail(failures, "restore_cosign_hash_mismatch", "$.restore.artifact.signature_verification.cosign_sha256", "DR signature must bind the pinned Cosign binary")
  elif cosign_version is not None and signature.get("cosign_version") != cosign_version:
    _fail(failures, "restore_cosign_version_mismatch", "$.restore.artifact.signature_verification.cosign_version", "DR signature must bind the pinned Cosign version")
  elif signer_fingerprint is not None and signature.get("public_key_sha256") != signer_fingerprint.removeprefix("sha256:"):
    _fail(failures, "restore_public_key_binding_mismatch", "$.restore.artifact.signature_verification.public_key_sha256", "DR signature must bind the envelope signer key")
  if not isinstance(document.get("backup_manifest_sha256"), str) or not SHA256_RE.fullmatch(document.get("backup_manifest_sha256", "")):
    _fail(failures, "backup_manifest_hash_invalid", "$.restore.artifact.backup_manifest_sha256", "backup manifest hash must be a SHA-256 digest")
  source_ids = {document.get("source_host_id"), document.get("source_storage_id")}
  backup_ids = {document.get("backup_target_id"), document.get("backup_target_host_id"), document.get("backup_target_storage_id")}
  target_ids = {document.get("restore_target_id"), document.get("restore_target_host_id"), document.get("restore_target_storage_id")}
  if not all(isinstance(value, str) and value.strip() for value in source_ids | backup_ids | target_ids):
    _fail(failures, "restore_target_ids_missing", "$.restore.artifact", "source, backup, and restore target IDs are required")
  source_identity = _obj(document.get("source_identity"), "$.restore.artifact.source_identity", {"host_id", "storage_id", "evidence_ref", "evidence_sha256"}, failures)
  backup_identity = _obj(document.get("backup_target_identity"), "$.restore.artifact.backup_target_identity", {"host_id", "storage_id", "evidence_ref", "evidence_sha256"}, failures)
  restore_identity = _obj(document.get("restore_target_identity"), "$.restore.artifact.restore_target_identity", {"host_id", "storage_id", "evidence_ref", "evidence_sha256"}, failures)
  for name, identity, host, storage in (("source", source_identity, document.get("source_host_id"), document.get("source_storage_id")), ("backup", backup_identity, document.get("backup_target_host_id"), document.get("backup_target_storage_id")), ("restore", restore_identity, document.get("restore_target_host_id"), document.get("restore_target_storage_id"))):
    if identity.get("host_id") != host or identity.get("storage_id") != storage:
      _fail(failures, "restore_identity_mismatch", f"$.restore.artifact.{name}_identity", "identity must match its bound host and storage IDs")
    if not isinstance(identity.get("evidence_sha256"), str) or not SHA256_RE.fullmatch(identity.get("evidence_sha256", "")):
      _fail(failures, "restore_identity_proof_invalid", f"$.restore.artifact.{name}_identity.evidence_sha256", "identity evidence hash must be a SHA-256 digest")
    elif evidence_root is not None:
      proof_path = _safe_file(identity.get("evidence_ref"), evidence_root, f"$.restore.artifact.{name}_identity.evidence_ref", failures)
      if proof_path is not None and _sha256(proof_path) != identity.get("evidence_sha256"):
        _fail(failures, "restore_identity_proof_mismatch", f"$.restore.artifact.{name}_identity.evidence_sha256", "identity evidence hash does not match rooted bytes")
  hosts = {document.get("source_host_id"), document.get("backup_target_host_id"), document.get("restore_target_host_id")}
  storages = {document.get("source_storage_id"), document.get("backup_target_storage_id"), document.get("restore_target_storage_id")}
  ids = {document.get("backup_id"), document.get("backup_target_id"), document.get("restore_id"), document.get("restore_target_id")}
  if len(hosts) != 3 or len(storages) != 3 or len(ids) != 4:
    _fail(failures, "restore_targets_not_distinct", "$.restore.artifact", "source, backup, and restore identities must be distinct")
  observed = _timestamp(document.get("observed_at"), "$.restore.artifact.observed_at", failures)
  if observed is not None and envelope_observed is not None and observed != envelope_observed:
    _fail(failures, "restore_observed_at_mismatch", "$.restore.artifact.observed_at", "raw restore observation must match its signed envelope")
  if observed is not None and as_of is not None and max_age:
    _fresh(observed, as_of, max_age, "$.restore.artifact.observed_at", failures)
  raw_timing = _obj(document.get("raw_timing"), "$.restore.artifact.raw_timing", {"last_write_at", "backup_completed_at", "restore_started_at", "restore_completed_at"}, failures)
  times = {key: _timestamp(raw_timing.get(key), f"$.restore.artifact.raw_timing.{key}", failures) for key in raw_timing}
  if all(times.get(key) is not None for key in ("last_write_at", "backup_completed_at", "restore_started_at", "restore_completed_at")):
    if as_of is not None and max_age:
      for key, timestamp in times.items():
        _fresh(timestamp, as_of, max_age, f"$.restore.artifact.raw_timing.{key}", failures)
    rpo = int((times["backup_completed_at"] - times["last_write_at"]).total_seconds())
    rto = int((times["restore_completed_at"] - times["restore_started_at"]).total_seconds())
    if times["backup_completed_at"] < times["last_write_at"] or times["restore_started_at"] < times["backup_completed_at"] or times["restore_completed_at"] < times["restore_started_at"]:
      _fail(failures, "restore_timing_order_invalid", "$.restore.artifact.raw_timing", "raw restore timing order is invalid")
    if observed is not None and times["restore_completed_at"] > observed:
      _fail(failures, "restore_observed_before_completion", "$.restore.artifact.observed_at", "observation must follow restore completion")
  else:
    rpo = rto = -1
  timing = document.get("timing") if isinstance(document.get("timing"), dict) else {}
  if type(timing.get("rpo_seconds")) is not int or type(timing.get("rto_seconds")) is not int or timing.get("rpo_seconds") != rpo or timing.get("rto_seconds") != rto or rpo < 0 or rto < 0 or rpo > 900 or rto > 7200 or timing.get("rpo_within_limit") is not (rpo <= 900) or timing.get("rto_within_limit") is not (rto <= 7200):
    _fail(failures, "restore_rpo_rto_invalid", "$.restore.artifact.timing", "RPO/RTO numeric limits and derived booleans must pass")
  checks = _obj(document.get("checks"), "$.restore.artifact.checks", set(DR_CHECK_IDS), failures)
  if not isinstance(checks, dict) or any(checks.get(key) != "passed" for key in DR_CHECK_IDS):
    _fail(failures, "restore_checks_invalid", "$.restore.artifact.checks", "exact six DR checks must all pass")
  encryption = document.get("backup_encryption")
  if not isinstance(encryption, dict) or set(encryption) != {"algorithm", "key_id"} or encryption.get("algorithm") not in {"AES-256-GCM", "age", "SSE-KMS"} or not isinstance(encryption.get("key_id"), str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{2,127}", encryption.get("key_id", "")):
    _fail(failures, "restore_encryption_invalid", "$.restore.artifact.backup_encryption", "backup encryption binding is invalid")
  data = _obj(document.get("data_proof"), "$.restore.artifact.data_proof", {"evidence_ref", "evidence_sha256", "sample_hash"}, failures)
  if not isinstance(data.get("evidence_sha256"), str) or not re.fullmatch(r"[0-9a-fA-F]{64}", data.get("evidence_sha256", "")) or not isinstance(data.get("sample_hash"), str) or not re.fullmatch(r"[0-9a-fA-F]{64,128}", data.get("sample_hash", "")):
    _fail(failures, "restore_data_proof_invalid", "$.restore.artifact.data_proof", "data proof hashes are invalid")
  elif evidence_root is not None:
    proof_path = _safe_file(data.get("evidence_ref"), evidence_root, "$.restore.artifact.data_proof.evidence_ref", failures)
    if proof_path is not None and _sha256(proof_path).lower() != data.get("evidence_sha256", "").lower():
      _fail(failures, "restore_data_proof_mismatch", "$.restore.artifact.data_proof.evidence_sha256", "data proof hash does not match rooted bytes")
  journeys = document.get("critical_journeys")
  locales = {item.get("locale") for item in journeys} if isinstance(journeys, list) and all(isinstance(item, dict) for item in journeys) else set()
  journey_ids = [item.get("id") for item in journeys] if isinstance(journeys, list) and all(isinstance(item, dict) for item in journeys) else []
  if not isinstance(journeys, list) or len(journeys) < 2 or locales != {"ar", "en"} or any(not isinstance(item, str) for item in journey_ids) or len(journey_ids) != len(set(journey_ids)) or any(set(item) != {"id", "locale", "status", "duration_ms", "evidence_ref", "evidence_sha256"} or item.get("status") != "passed" or type(item.get("duration_ms")) is not int or item.get("duration_ms") < 0 or item.get("duration_ms") >= 5000 for item in journeys if isinstance(item, dict)):
    _fail(failures, "restore_journey_coverage", "$.restore.artifact.critical_journeys", "Arabic and English passed journeys are required")
  elif evidence_root is not None:
    for index, item in enumerate(journeys):
      if not isinstance(item.get("evidence_sha256"), str) or not re.fullmatch(r"[0-9a-fA-F]{64}", item.get("evidence_sha256", "")):
        _fail(failures, "restore_journey_proof_invalid", f"$.restore.artifact.critical_journeys[{index}]", "journey evidence hash is invalid")
        continue
      proof_path = _safe_file(item.get("evidence_ref"), evidence_root, f"$.restore.artifact.critical_journeys[{index}].evidence_ref", failures)
      if proof_path is not None and _sha256(proof_path).lower() != item["evidence_sha256"].lower():
        _fail(failures, "restore_journey_proof_mismatch", f"$.restore.artifact.critical_journeys[{index}].evidence_sha256", "journey evidence hash does not match rooted bytes")


def _cross_bind_deployment_restore(deployment: Mapping[str, Any], restore: Mapping[str, Any], failures: list[Failure]) -> None:
  migration = deployment.get("migration") if isinstance(deployment.get("migration"), dict) else {}
  raw_timing = restore.get("raw_timing") if isinstance(restore.get("raw_timing"), dict) else {}
  bindings = (
    (migration.get("pre_backup_id"), restore.get("backup_id"), "pre_backup_id", "backup_id"),
    (migration.get("pre_backup_manifest_sha256"), restore.get("backup_manifest_sha256"), "pre_backup_manifest_sha256", "backup_manifest_sha256"),
    (migration.get("pre_backup_environment"), restore.get("environment"), "pre_backup_environment", "environment"),
  )
  for expected, actual, left, right in bindings:
    if expected != actual:
      _fail(failures, "deployment_restore_binding_mismatch", f"$.restore.artifact.{right}", f"must match deployment migration {left}")
  completed = _timestamp(migration.get("pre_backup_completed_at"), "$.deployment.artifact.migration.pre_backup_completed_at", failures)
  backup_completed = _timestamp(raw_timing.get("backup_completed_at"), "$.restore.artifact.raw_timing.backup_completed_at", failures)
  if completed is not None and backup_completed is not None and completed != backup_completed:
    _fail(failures, "deployment_restore_timing_mismatch", "$.restore.artifact.raw_timing.backup_completed_at", "backup completion must match deployment pre-backup completion")


def _validate_ci(document: dict[str, Any], descriptor_hash: str, revision: str, failures: list[Failure], envelope_observed: datetime | None = None, as_of: datetime | None = None, max_age: int = 0) -> None:
  expected = {"status", "mode", "live_pipeline", "observed_at", "git_revision", "descriptor_sha256", "jobs"}
  _obj(document, "$.ci.artifact", expected, failures)
  if document.get("status") != "passed" or document.get("mode") != "ci" or document.get("live_pipeline") is not True:
    _fail(failures, "ci_not_live", "$.ci.artifact", "CI artifact must be a live passed pipeline")
  _revision(document.get("git_revision"), revision, "$.ci.artifact.git_revision", failures)
  observed = _timestamp(document.get("observed_at"), "$.ci.artifact.observed_at", failures)
  if observed is not None and envelope_observed is not None and observed != envelope_observed:
    _fail(failures, "ci_observed_at_mismatch", "$.ci.artifact.observed_at", "raw CI observation must match its signed envelope")
  if observed is not None and as_of is not None and max_age:
    _fresh(observed, as_of, max_age, "$.ci.artifact.observed_at", failures)
  if document.get("descriptor_sha256") != descriptor_hash:
    _fail(failures, "ci_descriptor_hash_mismatch", "$.ci.artifact.descriptor_sha256", "CI descriptor hash differs")
  jobs = document.get("jobs")
  if not isinstance(jobs, dict) or set(jobs) != set(REQUIRED_CI_JOBS):
    _fail(failures, "ci_job_set_mismatch", "$.ci.artifact.jobs", "required live CI job set is not exact")
  elif any(not isinstance(jobs[name], dict) or jobs[name].get("status") != "passed" for name in REQUIRED_CI_JOBS):
    _fail(failures, "ci_job_failed", "$.ci.artifact.jobs", "all required CI jobs must pass")


def _validate_test(document: dict[str, Any], test_id: str, revision: str, envelope_hash: str, failures: list[Failure], envelope_observed: datetime | None = None, as_of: datetime | None = None, max_age: int = 0) -> None:
  expected = {"test_id", "status", "git_revision", "observed_at", "owner", "evidence_sha256"}
  _obj(document, f"$.tests.{test_id}.artifact", expected, failures)
  if document.get("test_id") != test_id or document.get("status") != "passed":
    _fail(failures, "test_identity_or_status", f"$.tests.{test_id}.artifact", "test ID and status must be exact")
  _revision(document.get("git_revision"), revision, f"$.tests.{test_id}.artifact.git_revision", failures)
  observed = _timestamp(document.get("observed_at"), f"$.tests.{test_id}.artifact.observed_at", failures)
  if observed is not None and envelope_observed is not None and observed != envelope_observed:
    _fail(failures, "test_observed_at_mismatch", f"$.tests.{test_id}.artifact.observed_at", "raw test observation must match its signed envelope")
  if observed is not None and as_of is not None and max_age:
    _fresh(observed, as_of, max_age, f"$.tests.{test_id}.artifact.observed_at", failures)
  if not isinstance(document.get("owner"), str) or not document["owner"].strip() or not isinstance(document.get("evidence_sha256"), str) or not SHA256_RE.fullmatch(document["evidence_sha256"]):
    _fail(failures, "test_evidence_hash_invalid", f"$.tests.{test_id}.artifact", "owner and exact evidence hash are required")


def _validate_approval(document: dict[str, Any], role: str, revision: str, machine_digest: str, latest_machine: datetime, as_of: datetime, max_age: int, failures: list[Failure]) -> None:
  expected = {"decision", "status", "role", "name", "git_revision", "approved_at", "machine_evidence_sha256"}
  _obj(document, f"$.approvals.{role}.artifact", expected, failures)
  if document.get("decision") != "go" or document.get("status") != "approved" or document.get("role") != role or document.get("machine_evidence_sha256") != machine_digest:
    _fail(failures, "approval_binding_mismatch", f"$.approvals.{role}.artifact", "approval must bind Go, role, and machine evidence digest")
  _revision(document.get("git_revision"), revision, f"$.approvals.{role}.artifact.git_revision", failures)
  approved = _timestamp(document.get("approved_at"), f"$.approvals.{role}.artifact.approved_at", failures)
  if approved and approved <= latest_machine:
    _fail(failures, "approval_before_machine_evidence", f"$.approvals.{role}.artifact.approved_at", "approval must follow latest machine observation")
  if approved:
    _fresh(approved, as_of, max_age, f"$.approvals.{role}.artifact.approved_at", failures)
  if not isinstance(document.get("name"), str) or not document["name"].strip():
    _fail(failures, "approval_name_missing", f"$.approvals.{role}.artifact.name", "approver name is required")


def _machine_digest(descriptor_hash: str, envelopes: Mapping[str, Mapping[str, Any]]) -> str:
  entries = [{"name": "release_descriptor", "artifact_sha256": descriptor_hash}]
  for name, envelope in sorted(envelopes.items()):
    entries.append({"name": name, "statement_sha256": envelope["statement"]["sha256"], "artifact_sha256": envelope["artifact_sha256"]})
  return hashlib.sha256(_canonical(entries)).hexdigest()


def validate_trust_policy(document: Any, *, failures: list[Failure] | None = None) -> list[Failure]:
  output = failures if failures is not None else []
  _validate_trust_policy(document, as_of_root=Path.cwd(), failures=output)
  return output


def validate_gate_manifest(
  document: Any,
  *,
  release_root: Path,
  evidence_root: Path,
  trust_policy: Any,
  cosign_binary: Path,
  cosign_sha256: str,
  cosign_version: str,
  as_of: datetime | None = None,
  envelope_backend: Any = None,
  descriptor_verifier: Callable[..., None] | None = None,
  _result: dict[str, Any] | None = None,
) -> list[Failure]:
  failures: list[Failure] = []
  root = _obj(document, "$", {"contract_version", "git_revision", "as_of", "max_age_seconds", "release_descriptor", "evidence"}, failures)
  if not root:
    return failures
  if root.get("contract_version") != CONTRACT_VERSION:
    _fail(failures, "unsupported_contract_version", "$.contract_version", "must be 1.0.0")
  revision = root.get("git_revision")
  if not isinstance(revision, str) or not REVISION_RE.fullmatch(revision):
    _fail(failures, "invalid_gate_revision", "$.git_revision", "must be full lowercase 40-character revision")
    revision = "0" * 40
  manifest_as_of = _timestamp(root.get("as_of"), "$.as_of", failures)
  if as_of is not None and manifest_as_of is not None and manifest_as_of != as_of.astimezone(timezone.utc):
    _fail(failures, "as_of_mismatch", "$.as_of", "manifest as_of differs from explicit gate as_of")
  as_of = as_of or manifest_as_of or datetime.now(timezone.utc)
  max_age = root.get("max_age_seconds")
  if type(max_age) is not int or not 0 < max_age <= MAX_AGE_LIMIT_SECONDS:
    _fail(failures, "invalid_freshness_window", "$.max_age_seconds", "must be a positive window no greater than seven days")
    max_age = MAX_AGE_LIMIT_SECONDS
  _cosign_pin(cosign_binary, cosign_sha256, cosign_version, failures)
  if failures:
    return failures
  trust_keys = _validate_trust_policy(trust_policy, as_of_root=evidence_root, failures=failures)
  release_proof = _obj(root.get("release_descriptor"), "$.release_descriptor", {"path", "sha256", "key_id"}, failures)
  descriptor, descriptor_path, descriptor_hash = _load_proof_json({"path": release_proof.get("path"), "sha256": release_proof.get("sha256")}, release_root, "$.release_descriptor", failures)
  if descriptor is None or descriptor_path is None or descriptor_hash is None:
    descriptor = {}
    descriptor_hash = ""
  else:
    try:
      validate_descriptor(descriptor, release_root)
      descriptor_public_key_id = release_proof.get("key_id")
      descriptor_key = trust_keys.get(descriptor_public_key_id) if isinstance(descriptor_public_key_id, str) else None
      if descriptor_key is None or "release-descriptor" not in descriptor_key.get("allowed_artifact_types", []):
        _fail(failures, "descriptor_trust_key_missing", "$.release_descriptor", "descriptor key is absent or not allowed")
      else:
        public_key = Path(descriptor_key["public_key_path"])
        verifier = descriptor_verifier or verify_descriptor
        verifier(
          descriptor,
          release_root,
          cosign_binary=cosign_binary,
          cosign_sha256=cosign_sha256,
          public_key=public_key,
          public_key_fingerprint=descriptor_key["public_key_fingerprint"],
          cosign_version=cosign_version,
        )
        if _sha256(descriptor_path) != descriptor_hash:
          _fail(failures, "descriptor_changed_after_crypto", "$.release_descriptor", "release descriptor changed after cryptographic verification")
    except (DescriptorError, OSError, ValueError) as error:
      _fail(failures, "invalid_release_descriptor", "$.release_descriptor", str(error))
    _revision(descriptor.get("git_revision"), revision, "$.release_descriptor.artifact.git_revision", failures)
  evidence = _obj(root.get("evidence"), "$.evidence", set(ENVELOPE_KEYS) | {"tests", "approvals"}, failures)
  if not isinstance(evidence.get("tests"), dict) or set(evidence.get("tests", {})) != set(TEST_IDS):
    _fail(failures, "test_set_mismatch", "$.evidence.tests", "all exact W1.1 test IDs are required once")
  if not isinstance(evidence.get("approvals"), dict) or set(evidence.get("approvals", {})) != set(APPROVAL_ROLES):
    _fail(failures, "approval_set_mismatch", "$.evidence.approvals", "all exact approval roles are required once")
  envelopes: dict[str, dict[str, Any]] = {}
  raw_artifacts: dict[str, dict[str, Any]] = {}
  for artifact_type in ENVELOPE_KEYS:
    envelope, raw, _ = _verify_envelope_item(evidence.get(artifact_type), artifact_type, evidence_root=evidence_root, gate_revision=revision, as_of=as_of, max_age=max_age, trust_keys=trust_keys, cosign_binary=cosign_binary, cosign_sha256=cosign_sha256, cosign_version=cosign_version, failures=failures, backend=envelope_backend)
    if envelope is not None:
      envelopes[artifact_type] = envelope
    if raw is not None:
      raw_artifacts[artifact_type] = raw
  for test_id in TEST_IDS:
    envelope, raw, _ = _verify_envelope_item(evidence.get("tests", {}).get(test_id) if isinstance(evidence.get("tests"), dict) else None, "test-result", evidence_root=evidence_root, gate_revision=revision, as_of=as_of, max_age=max_age, trust_keys=trust_keys, cosign_binary=cosign_binary, cosign_sha256=cosign_sha256, cosign_version=cosign_version, failures=failures, backend=envelope_backend)
    if envelope is not None:
      envelopes[test_id] = envelope
    if raw is not None:
      raw_artifacts[test_id] = raw
  machine_digest = _machine_digest(descriptor_hash, envelopes)
  if _result is not None:
    _result.clear()
    _result.update({"machine_digest": machine_digest, "descriptor_hash": descriptor_hash, "envelopes": envelopes.copy()})
  observations: list[datetime] = []
  for name, envelope in envelopes.items():
    if name in APPROVAL_ROLES or name in {"test-result"}:
      continue
    observed = _timestamp(envelope.get("observed_at"), f"$.evidence.{name}.observed_at", failures)
    if observed:
      observations.append(observed)
  latest_machine = max(observations) if observations else as_of
  host_envelope = envelopes.get("host")
  if host_envelope:
    host_doc = raw_artifacts.get("host")
    if host_doc:
      _validate_host(host_doc, revision, host_envelope, failures)
  net_policy_hash: str | None = None
  for artifact_type in ("net-host", "net-user", "net-management"):
    envelope = envelopes.get(artifact_type)
    if envelope:
      raw = raw_artifacts.get(artifact_type)
      if raw:
        net_observed = _timestamp(envelope.get("observed_at"), f"$.evidence.{artifact_type}.observed_at", failures)
        net_policy_hash = _validate_net(raw, artifact_type, revision, net_policy_hash, descriptor, failures, net_observed, as_of, max_age)
  deployment = envelopes.get("deployment")
  if deployment:
    raw = raw_artifacts.get("deployment")
    if raw:
      deployment_observed = _timestamp(deployment.get("observed_at"), "$.evidence.deployment.observed_at", failures)
      _validate_deployment(raw, descriptor, revision, evidence_root, as_of, failures, deployment_observed, max_age)
  restore = envelopes.get("restore")
  if restore:
    raw = raw_artifacts.get("restore")
    if raw:
      restore_observed = _timestamp(restore.get("observed_at"), "$.evidence.restore.observed_at", failures)
      restore_signer = restore.get("signer") if isinstance(restore.get("signer"), dict) else {}
      _validate_restore(raw, failures, restore_observed, as_of, max_age, evidence_root, cosign_sha256, cosign_version, restore_signer.get("public_key_fingerprint"))
      deployment_raw = raw_artifacts.get("deployment")
      if deployment_raw:
        _cross_bind_deployment_restore(deployment_raw, raw, failures)
  ci = envelopes.get("ci")
  if ci:
    raw = raw_artifacts.get("ci")
    if raw:
      ci_observed = _timestamp(ci.get("observed_at"), "$.evidence.ci.observed_at", failures)
      _validate_ci(raw, descriptor_hash, revision, failures, ci_observed, as_of, max_age)
  for test_id in TEST_IDS:
    envelope = envelopes.get(test_id)
    if envelope:
      raw = raw_artifacts.get(test_id)
      if raw:
        test_observed = _timestamp(envelope.get("observed_at"), f"$.evidence.tests.{test_id}.observed_at", failures)
        _validate_test(raw, test_id, revision, envelope["artifact_sha256"], failures, test_observed, as_of, max_age)
  approvals = evidence.get("approvals", {}) if isinstance(evidence.get("approvals"), dict) else {}
  for role in APPROVAL_ROLES:
    envelope, raw, _ = _verify_envelope_item(approvals.get(role), "approval", evidence_root=evidence_root, gate_revision=revision, as_of=as_of, max_age=max_age, trust_keys=trust_keys, cosign_binary=cosign_binary, cosign_sha256=cosign_sha256, cosign_version=cosign_version, failures=failures, backend=envelope_backend)
    if envelope:
      if raw:
        _validate_approval(raw, role, revision, machine_digest, latest_machine, as_of, max_age, failures)
  return failures


def build_gate_receipt(document: dict[str, Any], **kwargs: Any) -> dict[str, Any]:
  result: dict[str, Any] = {}
  failures = validate_gate_manifest(document, **kwargs, _result=result)
  if failures:
    raise ValueError(json.dumps([failure.to_dict() for failure in failures], ensure_ascii=False))
  machine = result.get("machine_digest")
  if not isinstance(machine, str) or not SHA256_RE.fullmatch(machine):
    raise ValueError("validated machine evidence digest is unavailable")
  receipt = {"receipt_version": RECEIPT_VERSION, "contract": "W11-GATE-07", "status": "passed", "decision": "go", "git_revision": document["git_revision"], "machine_evidence_sha256": machine}
  receipt["receipt_sha256"] = hashlib.sha256(_canonical(receipt)).hexdigest()
  return receipt


def _cli() -> int:
  parser = argparse.ArgumentParser(description=__doc__)
  parser.add_argument("--manifest", type=Path, required=True)
  parser.add_argument("--trust-policy", type=Path, required=True)
  parser.add_argument("--release-root", type=Path, required=True)
  parser.add_argument("--evidence-root", type=Path, required=True)
  parser.add_argument("--receipt", type=Path, required=True)
  parser.add_argument("--cosign-binary", type=Path, required=True)
  parser.add_argument("--cosign-sha256", required=True)
  parser.add_argument("--cosign-version", required=True)
  parser.add_argument("--as-of", required=True)
  args = parser.parse_args()
  try:
    as_of = _timestamp(args.as_of, "--as-of", [])
    if as_of is None:
      raise ValueError("invalid --as-of")
    manifest = load_json_no_duplicates(args.manifest)
    trust = load_json_no_duplicates(args.trust_policy)
    result: dict[str, Any] = {}
    failures = validate_gate_manifest(manifest, release_root=args.release_root, evidence_root=args.evidence_root, trust_policy=trust, cosign_binary=args.cosign_binary, cosign_sha256=args.cosign_sha256, cosign_version=args.cosign_version, as_of=as_of, _result=result)
    if failures:
      raise ValueError(json.dumps([failure.to_dict() for failure in failures], ensure_ascii=False))
    machine_digest = result.get("machine_digest")
    if not isinstance(machine_digest, str) or not SHA256_RE.fullmatch(machine_digest):
      raise ValueError("validated machine evidence digest is unavailable")
    receipt = {"receipt_version": RECEIPT_VERSION, "contract": "W11-GATE-07", "status": "passed", "decision": "go", "git_revision": manifest["git_revision"], "machine_evidence_sha256": machine_digest}
    receipt["receipt_sha256"] = hashlib.sha256(_canonical(receipt)).hexdigest()
    args.receipt.parent.mkdir(parents=True, exist_ok=True)
    args.receipt.write_text(json.dumps(receipt, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({"status": "passed", "git_revision": manifest["git_revision"]}, sort_keys=True))
    return 0
  except (OSError, ValueError, DuplicateJSONKey) as error:
    print(f"FAIL: W11-GATE-07 not passed: {error}", file=sys.stderr)
    return 2


if __name__ == "__main__":
  raise SystemExit(_cli())
