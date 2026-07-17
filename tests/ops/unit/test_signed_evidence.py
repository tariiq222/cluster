from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path

import pytest
import scripts.signed_evidence as signed_evidence

from scripts.signed_evidence import (
  CommandResult,
  CosignTrust,
  DuplicateJSONKey,
  load_json_no_duplicates,
  validate_envelope,
  verify_signature,
  verify_envelope,
  _canonical_statement,
)


pytestmark = pytest.mark.unit


REVISION = "a" * 40
OBSERVED = "2026-07-17T08:00:00Z"


class FakeCosignBackend:
  """TEST-ONLY typed backend; never used by production CLI."""

  def __init__(self, returncode=0):
    self.returncode = returncode
    self.argv = None

  def verify(self, argv):
    self.argv = list(argv)
    return CommandResult(self.returncode, "", "")


def _sha(path: Path) -> str:
  return hashlib.sha256(path.read_bytes()).hexdigest()


def _cosign(root: Path) -> tuple[Path, str]:
  path = root / "cosign"
  path.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo cosign version v2.4.0; exit 0; fi\nexit 0\n")
  path.chmod(0o755)
  return path, _sha(path)


def _fixture(tmp_path: Path) -> tuple[dict, Path, Path]:
  artifact = tmp_path / "net-host.json"
  artifact.write_text(json.dumps({"git_revision": REVISION, "status": "passed", "mode": "host-live", "perspective": "host"}) + "\n")
  signature = tmp_path / "net-host.sig"
  signature.write_bytes(b"signature")
  bundle = tmp_path / "net-host.bundle"
  bundle.write_bytes(b"bundle")
  public_key = tmp_path / "public.pem"
  public_key.write_text("-----BEGIN PUBLIC KEY-----\nZmFrZQ==\n-----END PUBLIC KEY-----\n")
  envelope = {
    "contract_version": "1.0.0",
    "artifact_type": "net-host",
    "artifact_path": artifact.name,
    "artifact_sha256": _sha(artifact),
    "git_revision": REVISION,
    "observed_at": OBSERVED,
    "signer": {"role": "network_operator", "key_id": "net-key-v1", "public_key_fingerprint": "sha256:" + _sha(public_key)},
    "signature": {"path": signature.name, "sha256": _sha(signature)},
    "bundle": {"path": bundle.name, "sha256": _sha(bundle)},
    "status": "passed",
    "mode": "host-live",
    "perspective": "host",
  }
  statement = tmp_path / "net-host.statement.json"
  statement.write_bytes(_canonical_statement(envelope))
  envelope["statement"] = {"path": statement.name, "sha256": _sha(statement)}
  return envelope, public_key, artifact


def test_valid_envelope_binds_bytes_semantics_and_typed_cosign(tmp_path):
  envelope, public_key, _ = _fixture(tmp_path)
  cosign, cosign_hash = _cosign(tmp_path)
  backend = FakeCosignBackend()
  failures = verify_envelope(
    envelope,
    evidence_root=tmp_path,
    as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc),
    max_age_seconds=3600,
    cosign_binary=cosign,
    cosign_sha256=cosign_hash,
    cosign_version="v2.4.0",
    public_key=public_key,
    backend=backend,
  )
  assert failures == []
  assert backend.argv[1] == "verify-blob"
  assert "--key" in backend.argv and "--signature" in backend.argv and "--bundle" in backend.argv


@pytest.mark.parametrize(
  ("mutator", "code"),
  [
    (lambda e: e.update({"unexpected": True}), "unknown_field"),
    (lambda e: e.update({"git_revision": "b" * 40}), "artifact_revision_mismatch"),
    (lambda e: e["signer"].update({"role": "backup_operator"}), "wrong_signer_role"),
    (lambda e: e.update({"perspective": "management"}), "artifact_perspective_mismatch"),
    (lambda e: e.update({"observed_at": "2026-07-18T08:00:00Z"}), "future_evidence"),
    (lambda e: e.update({"artifact_path": "../net-host.json"}), "unsafe_path"),
  ],
)
def test_envelope_rejects_contract_and_temporal_mismatches(tmp_path, mutator, code):
  envelope, _, _ = _fixture(tmp_path)
  mutator(envelope)
  failures = validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)

  assert code in {failure.code for failure in failures}


def test_envelope_rejects_tampered_artifact_and_symlink(tmp_path):
  envelope, _, artifact = _fixture(tmp_path)
  artifact.write_text(artifact.read_text() + "tampered")
  assert "artifact_hash_mismatch" in {failure.code for failure in validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)}

  envelope, _, _ = _fixture(tmp_path)
  real = tmp_path / "real.json"
  real.write_text("{}")
  linked = tmp_path / "linked.json"
  linked.symlink_to(real)
  envelope["artifact_path"] = linked.name
  assert "symlink_path" in {failure.code for failure in validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)}


def test_verify_envelope_rejects_artifact_swap_after_cached_validation(tmp_path, monkeypatch):
  envelope, public_key, artifact = _fixture(tmp_path)
  cosign, cosign_hash = _cosign(tmp_path)
  backend = FakeCosignBackend()
  original = signed_evidence._read_stable_bytes
  swapped = False

  def swap_after_snapshot(path: Path):
    nonlocal swapped
    raw, digest = original(path)
    if path == artifact and not swapped:
      swapped = True
      artifact.write_text(json.dumps({"git_revision": "b" * 40, "status": "passed"}))
    return raw, digest

  monkeypatch.setattr(signed_evidence, "_read_stable_bytes", swap_after_snapshot)
  failures = verify_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600, cosign_binary=cosign, cosign_sha256=cosign_hash, cosign_version="v2.4.0", public_key=public_key, backend=backend)

  assert "artifact_hash_mismatch" in {failure.code for failure in failures}
  assert backend.argv is None


@pytest.mark.parametrize("field,value", [("artifact_type", "host"), ("observed_at", "2026-07-17T08:00:01Z")])
def test_signed_statement_binds_envelope_metadata(tmp_path, field, value):
  envelope, _, _ = _fixture(tmp_path)
  envelope[field] = value
  failures = validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)

  assert "statement_payload_mismatch" in {failure.code for failure in failures}


def test_signed_statement_binds_signer_role(tmp_path):
  envelope, _, _ = _fixture(tmp_path)
  envelope["signer"]["role"] = "backup_operator"
  failures = validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)

  assert "statement_payload_mismatch" in {failure.code for failure in failures}


def test_duplicate_json_keys_are_rejected_before_validation(tmp_path):
  path = tmp_path / "duplicate.json"
  path.write_text('{"artifact_type":"host","artifact_type":"restore"}')
  with pytest.raises(DuplicateJSONKey):
    load_json_no_duplicates(path)


def test_duplicate_json_keys_in_artifact_are_rejected(tmp_path):
  envelope, _, artifact = _fixture(tmp_path)
  artifact.write_text('{"git_revision":"' + REVISION + '","git_revision":"' + REVISION + '"}')
  envelope["artifact_sha256"] = _sha(artifact)

  failures = validate_envelope(envelope, evidence_root=tmp_path, as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc), max_age_seconds=3600)

  assert "duplicate_json_key" in {failure.code for failure in failures}


def test_optional_root_fields_match_schema_for_non_json_artifact(tmp_path):
  envelope, public_key, _ = _fixture(tmp_path)
  raw = tmp_path / "opaque.bin"
  raw.write_bytes(b"opaque evidence")
  envelope["artifact_path"] = raw.name
  envelope["artifact_sha256"] = _sha(raw)
  for field in ("status", "mode", "perspective"):
    envelope.pop(field)
  statement = tmp_path / envelope["statement"]["path"]
  statement.write_bytes(_canonical_statement(envelope))
  envelope["statement"]["sha256"] = _sha(statement)

  failures = validate_envelope(
    envelope,
    evidence_root=tmp_path,
    as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc),
    max_age_seconds=3600,
  )

  assert failures == []


def test_direct_signature_api_rejects_traversal_before_backend(tmp_path):
  envelope, public_key, _ = _fixture(tmp_path)
  outside = tmp_path.parent / "outside.statement"
  outside.write_bytes(b"outside")
  envelope["statement"] = {"path": f"../{outside.name}", "sha256": _sha(outside)}
  cosign, cosign_hash = _cosign(tmp_path)
  backend = FakeCosignBackend()

  failures = verify_signature(
    envelope,
    evidence_root=tmp_path,
    cosign_trust=CosignTrust(cosign, cosign_hash, "v2.4.0"),
    public_key=public_key,
    backend=backend,
  )

  assert "unsafe_path" in {failure.code for failure in failures}
  assert backend.argv is None


def test_cosign_version_output_requires_recognized_full_line(tmp_path):
  envelope, public_key, _ = _fixture(tmp_path)
  cosign, _ = _cosign(tmp_path)
  cosign.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo not-cosign version v2.4.0; exit 0; fi\nexit 0\n")
  cosign.chmod(0o755)
  cosign_hash = _sha(cosign)

  failures = verify_envelope(
    envelope,
    evidence_root=tmp_path,
    as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc),
    max_age_seconds=3600,
    cosign_trust=CosignTrust(cosign, cosign_hash, "v2.4.0"),
    public_key=public_key,
    backend=FakeCosignBackend(),
  )

  assert "cosign_version_mismatch" in {failure.code for failure in failures}


def test_signature_verification_rejects_post_verify_file_swap(tmp_path):
  envelope, public_key, artifact = _fixture(tmp_path)
  cosign, cosign_hash = _cosign(tmp_path)

  class SwappingBackend:
    def verify(self, argv):
      artifact.write_text(artifact.read_text() + "swapped")
      return CommandResult(0, "", "")

  failures = verify_envelope(
    envelope,
    evidence_root=tmp_path,
    as_of=datetime(2026, 7, 17, 8, 1, tzinfo=timezone.utc),
    max_age_seconds=3600,
    cosign_trust=CosignTrust(cosign, cosign_hash, "v2.4.0"),
    public_key=public_key,
    backend=SwappingBackend(),
  )

  assert "artifact_changed_after_verification" in {failure.code for failure in failures}
