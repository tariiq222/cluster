from __future__ import annotations

import copy
import hashlib
import json
import stat
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

import scripts.w1_1_acceptance_gate as gate
from scripts.signed_evidence import CommandResult, _canonical_statement


pytestmark = pytest.mark.integration
REVISION = "a" * 40
WHEN = "2026-07-17T08:00:00Z"
TEST_IDS = tuple(f"TEST-R1-W1.1-{i:02d}" for i in range(1, 9))
ROLES = ("technology_lead", "sre_lead", "security_lead")


class FakeCosignBackend:
  """TEST-ONLY typed signature backend; production never injects this."""

  def verify(self, argv):
    return CommandResult(0, "", "")


def sha(path: Path) -> str:
  return hashlib.sha256(path.read_bytes()).hexdigest()


def write(root: Path, name: str, value: object, *, raw: bool = False) -> tuple[str, str]:
  path = root / name
  path.write_bytes(value if raw else (json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode() + b"\n"))
  return name, sha(path)


def receipt_hash(document: dict) -> dict:
  value = copy.deepcopy(document)
  value["receipt_sha256"] = hashlib.sha256(gate._canonical({k: v for k, v in value.items() if k != "receipt_sha256"})).hexdigest()
  return value


def make_envelope(root: Path, name: str, artifact_type: str, raw: dict, signer_role: str, key_id: str, *, mode=None, perspective=None, status=None) -> tuple[str, str]:
  artifact_name, artifact_hash = write(root, f"{name}.artifact.json", raw)
  signature_name, signature_hash = write(root, f"{name}.sig", b"signature", raw=True)
  bundle_name, bundle_hash = write(root, f"{name}.bundle", b"bundle", raw=True)
  envelope = {
    "contract_version": "1.0.0", "artifact_type": artifact_type, "artifact_path": artifact_name,
    "artifact_sha256": artifact_hash, "git_revision": REVISION, "observed_at": WHEN,
    "signer": {"role": signer_role, "key_id": key_id, "public_key_fingerprint": "sha256:" + PUBLIC_KEY_HASH},
  }
  if status is not None: envelope["status"] = status
  if mode is not None: envelope["mode"] = mode
  if perspective is not None: envelope["perspective"] = perspective
  statement_path = root / f"{name}.statement.json"
  statement_path.write_bytes(_canonical_statement(envelope))
  envelope["statement"] = {"path": statement_path.name, "sha256": sha(statement_path)}
  envelope["signature"] = {"path": signature_name, "sha256": signature_hash}
  envelope["bundle"] = {"path": bundle_name, "sha256": bundle_hash}
  return write(root, f"{name}.envelope.json", envelope)


def fixture(tmp_path: Path) -> tuple[dict, dict, Path, Path, Path]:
  global PUBLIC_KEY_HASH
  evidence = tmp_path / "evidence"
  release = tmp_path / "release"
  evidence.mkdir(); release.mkdir()
  proof_path, proof_hash = write(evidence, "identity.json", b"identity-proof\n", raw=True)
  public_key = tmp_path / "public.pem"
  public_key.write_text("PUBLIC KEY")
  PUBLIC_KEY_HASH = sha(public_key)
  cosign = tmp_path / "cosign"
  cosign.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo 'cosign version v2.4.3'; exit 0; fi\nexit 0\n")
  cosign.chmod(cosign.stat().st_mode | stat.S_IXUSR)

  compose_name, compose_hash = write(release, "compose.yaml", b"services: {}\n", raw=True)
  descriptor = {"descriptor_version": "2", "git_revision": REVISION, "images": {"api": {"digest": "sha256:" + "1" * 64}, "web": {"digest": "sha256:" + "2" * 64}}, "compose": {"sha256": compose_hash}}
  descriptor_name, descriptor_hash = write(release, "release-descriptor.json", descriptor)

  host_checks = [{"id": check_id, "status": "passed", "detail": "ok"} for check_id in sorted(gate.PREFLIGHT_CHECK_IDS)]
  host = receipt_hash({"receipt_version": "1.0.0", "generated_at": WHEN, "mode": "preflight", "source_revision": REVISION, "input_sha256": "1" * 64, "target": {"environment": "production", "host_id": "host-01"}, "summary": {"status": "passed", "passed": len(host_checks), "failed": 0}, "checks": host_checks, "redaction": {"policy": gate.HOST_REDACTION_POLICY, "secret_values_included": False}, "signature": {"status": "not-signed", "reason": gate.HOST_SIGNATURE_REASON}})
  net_checks = lambda ids: [{"id": check_id, "status": "passed", "detail": "ok"} for check_id in sorted(ids)]
  net_base = {"receipt_version": "1.1.0", "verifier_version": "1.1.0", "task_id": "W11-NET-04", "git_revision": REVISION, "policy_sha256": "3" * 64, "redaction": {"secret_values_included": False, "endpoint_addresses_included": False, "source_addresses_included": False, "raw_command_output_included": False}, "checks": net_checks(gate.HOST_CHECK_IDS), "summary": {"status": "passed", "check_count": len(gate.HOST_CHECK_IDS)}, "signature": {"status": "not-signed"}, "observed_at": WHEN}
  net_host = receipt_hash({**net_base, "mode": "host-live", "perspective": "host", "compose_sha256": compose_hash})
  net_user = receipt_hash({**net_base, "mode": "edge-live", "perspective": "user", "checks": net_checks(gate.EDGE_CHECK_IDS), "summary": {"status": "passed", "check_count": len(gate.EDGE_CHECK_IDS)}})
  net_management = receipt_hash({**net_base, "mode": "edge-live", "perspective": "management", "checks": net_checks(gate.EDGE_CHECK_IDS), "summary": {"status": "passed", "check_count": len(gate.EDGE_CHECK_IDS)}})
  deployment = {"contract_version": "1.0.0", "release_n_plus_1": {"git_commit": REVISION, "images": {"api": "sha256:" + "1" * 64, "web": "sha256:" + "2" * 64}, "compose_sha256": compose_hash}, "release_n": {"git_commit": "b" * 40, "images": {}, "compose_sha256": "sha256:" + "4" * 64}, "migration": {"compatibility_status": "passed", "pre_backup_id": "b", "pre_backup_manifest_sha256": "f" * 64, "pre_backup_completed_at": "2026-07-17T07:50:00Z", "pre_backup_environment": "production", "observed_at": WHEN}, "rollback": {"to_git_commit": "b" * 40, "to_images": {}, "to_compose_sha256": "sha256:" + "4" * 64, "from_git_commit": REVISION, "from_images": {"api": "sha256:" + "1" * 64, "web": "sha256:" + "2" * 64}, "from_compose_sha256": compose_hash}}
  identity = lambda host, storage: {"host_id": host, "storage_id": storage, "evidence_ref": proof_path, "evidence_sha256": proof_hash}
  journey = lambda locale: {"id": f"journey-{locale}", "locale": locale, "status": "passed", "duration_ms": 100, "evidence_ref": proof_path, "evidence_sha256": proof_hash}
  restore = receipt_hash({"receipt_version": "1.0.0", "contract": "W11-DR-06", "status": "passed", "acceptance": {"status": "unsigned", "acceptable": False}, "environment": "production", "backup_id": "b", "backup_manifest_sha256": "f" * 64, "restore_id": "r", "source_host_id": "source-h", "source_storage_id": "source-s", "source_identity": identity("source-h", "source-s"), "backup_target_id": "backup", "backup_target_host_id": "backup-h", "backup_target_storage_id": "backup-s", "backup_target_identity": identity("backup-h", "backup-s"), "restore_target_id": "target", "restore_target_host_id": "target-h", "restore_target_storage_id": "target-s", "restore_target_identity": identity("target-h", "target-s"), "checks": {key: "passed" for key in gate.DR_CHECK_IDS}, "data_proof": {"evidence_ref": proof_path, "evidence_sha256": proof_hash, "sample_hash": "a" * 64}, "backup_encryption": {"algorithm": "AES-256-GCM", "key_id": "kms-v3"}, "checksum_algorithm": "sha256", "signature_verification": {"returncode": 0, "verified": True, "command_sha256": "0" * 64, "stdout_sha256": "1" * 64, "stderr_sha256": "2" * 64, "artifact_sha256": "a" * 64, "signature_sha256": "b" * 64, "bundle_sha256": "c" * 64, "public_key_sha256": PUBLIC_KEY_HASH, "cosign_sha256": sha(cosign), "cosign_version": "v2.4.3"}, "timing": {"rpo_seconds": 600, "rto_seconds": 240, "rpo_limit_seconds": 900, "rto_limit_seconds": 7200, "rpo_within_limit": True, "rto_within_limit": True}, "raw_timing": {"last_write_at": "2026-07-17T07:40:00Z", "backup_completed_at": "2026-07-17T07:50:00Z", "restore_started_at": "2026-07-17T07:51:00Z", "restore_completed_at": "2026-07-17T07:55:00Z"}, "critical_journeys": [journey("ar"), journey("en")], "observed_at": WHEN})
  ci = {"status": "passed", "mode": "ci", "live_pipeline": True, "observed_at": WHEN, "git_revision": REVISION, "descriptor_sha256": descriptor_hash, "jobs": {job: {"status": "passed"} for job in gate.REQUIRED_CI_JOBS}}
  tests = {test_id: {"test_id": test_id, "status": "passed", "git_revision": REVISION, "observed_at": WHEN, "owner": "qa", "evidence_sha256": "9" * 64} for test_id in TEST_IDS}
  raw_by_name = {"host": host, "net-host": net_host, "net-user": net_user, "net-management": net_management, "deployment": deployment, "restore": restore, "ci": ci}
  proofs = {}
  for name, raw in raw_by_name.items():
    role = "host_operator" if name == "host" else "network_operator" if name.startswith("net-") else "release_operator" if name == "deployment" else "backup_operator" if name == "restore" else "ci_system"
    atype = name
    mode = raw.get("mode"); perspective = raw.get("perspective"); status = raw.get("status")
    envelope_path, envelope_hash = make_envelope(evidence, name, atype, raw, role, "key-v1", mode=mode, perspective=perspective, status=status)
    proofs[name] = {"path": envelope_path, "sha256": envelope_hash}
  for test_id, raw in tests.items():
    proofs["tests"] = proofs.get("tests", {})
    p = make_envelope(evidence, test_id, "test-result", raw, "test_operator", "key-v1", status="passed")
    proofs["tests"][test_id] = {"path": p[0], "sha256": p[1]}
  nonapproval_envelopes = {name: json.loads((evidence / proof["path"]).read_text()) for name, proof in {**{k: proofs[k] for k in gate.ENVELOPE_KEYS}, **proofs["tests"]}.items()}
  machine_digest = gate._machine_digest(descriptor_hash, nonapproval_envelopes)
  approvals = {}
  for role in ROLES:
    raw = {"decision": "go", "status": "approved", "role": role, "name": role, "git_revision": REVISION, "approved_at": "2026-07-17T08:10:00Z", "machine_evidence_sha256": machine_digest}
    approval_path, approval_hash = make_envelope(evidence, role, "approval", raw, role, "key-v1", status="approved")
    approvals[role] = {"path": approval_path, "sha256": approval_hash}
  proofs["approvals"] = approvals
  manifest = {"contract_version": "1.0.0", "git_revision": REVISION, "as_of": "2026-07-17T08:11:00Z", "max_age_seconds": 3600, "release_descriptor": {"path": descriptor_name, "sha256": descriptor_hash, "key_id": "key-v1"}, "evidence": proofs}
  trust = {"contract_version": "1.0.0", "keys": {"key-v1": {"public_key_path": str(public_key), "public_key_fingerprint": "sha256:" + PUBLIC_KEY_HASH, "allowed_roles": ["host_operator", "network_operator", "release_operator", "backup_operator", "ci_system", "test_operator", *ROLES], "allowed_artifact_types": ["release-descriptor", *gate.ENVELOPE_KEYS, "test-result", "approval"]}}}
  return manifest, trust, release, evidence, cosign


def test_gate_consumes_verified_raw_artifacts_and_computes_machine_digest(tmp_path, monkeypatch):
  manifest, trust, release, evidence, cosign = fixture(tmp_path)
  calls = {"deployment": [], "restore": []}
  monkeypatch.setattr(gate, "validate_descriptor", lambda *_args, **_kwargs: None)
  monkeypatch.setattr(gate, "validate_release_evidence", lambda document, **_kwargs: (calls["deployment"].append(document), [])[1])
  monkeypatch.setattr(gate, "verify_restore_receipt_integrity", lambda document: (calls["restore"].append(document), True)[1])
  failures = gate.validate_gate_manifest(manifest, release_root=release, evidence_root=evidence, trust_policy=trust, cosign_binary=cosign, cosign_sha256=sha(cosign), cosign_version="v2.4.3", as_of=datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), envelope_backend=FakeCosignBackend(), descriptor_verifier=lambda *_args, **_kwargs: None)
  assert not failures, [failure.to_dict() for failure in failures]
  assert calls["deployment"] and calls["restore"], "DEP/DR validators must receive verified raw artifact documents"
  receipt = gate.build_gate_receipt(manifest, release_root=release, evidence_root=evidence, trust_policy=trust, cosign_binary=cosign, cosign_sha256=sha(cosign), cosign_version="v2.4.3", as_of=datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), envelope_backend=FakeCosignBackend(), descriptor_verifier=lambda *_args, **_kwargs: None)
  assert receipt["status"] == "passed" and len(receipt["machine_evidence_sha256"]) == 64


def test_gate_rejects_wrong_key_role_revision_or_approval_digest(tmp_path, monkeypatch):
  manifest, trust, release, evidence, cosign = fixture(tmp_path)
  manifest["git_revision"] = "b" * 40
  manifest["evidence"]["approvals"]["technology_lead"]["path"] = manifest["evidence"]["approvals"]["sre_lead"]["path"]
  monkeypatch.setattr(gate, "validate_descriptor", lambda *_args, **_kwargs: None)
  failures = gate.validate_gate_manifest(manifest, release_root=release, evidence_root=evidence, trust_policy=trust, cosign_binary=cosign, cosign_sha256=sha(cosign), cosign_version="v2.4.3", as_of=datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), envelope_backend=FakeCosignBackend(), descriptor_verifier=lambda *_args, **_kwargs: None)
  codes = {failure.code for failure in failures}
  assert "revision_mismatch" in codes or "hash_mismatch" in codes


def test_gate_direct_cli_help_and_missing_input_fail_closed(tmp_path):
  repo_root = Path(__file__).resolve().parents[3]
  help_result = subprocess.run(
    [sys.executable, "scripts/w1_1_acceptance_gate.py", "--help"],
    cwd=repo_root,
    capture_output=True,
    text=True,
    check=False,
  )
  assert help_result.returncode == 0
  assert "--manifest" in help_result.stdout

  receipt = tmp_path / "receipt.json"
  missing_result = subprocess.run(
    [
      sys.executable, "scripts/w1_1_acceptance_gate.py",
      "--manifest", str(tmp_path / "missing-manifest.json"),
      "--trust-policy", str(tmp_path / "missing-trust.json"),
      "--release-root", str(tmp_path / "release"),
      "--evidence-root", str(tmp_path / "evidence"),
      "--receipt", str(receipt),
      "--cosign-binary", str(tmp_path / "cosign"),
      "--cosign-sha256", "0" * 64,
      "--cosign-version", "v2.4.3",
      "--as-of", "2026-07-17T08:11:00Z",
    ],
    cwd=repo_root,
    capture_output=True,
    text=True,
    check=False,
  )
  assert missing_result.returncode == 2
  assert "not passed" in missing_result.stderr
  assert not receipt.exists()


def test_gate_rejects_adversarial_net_dr_approval_and_cosign_inputs(tmp_path):
  manifest, trust, release, evidence, cosign = fixture(tmp_path)
  host = json.loads((evidence / "host.artifact.json").read_text())
  host["checks"][0]["id"] = "host.ok"
  host["target"]["api_token"] = "must-not-appear"
  failures: list[gate.Failure] = []
  gate._validate_host(host, REVISION, json.loads((evidence / "host.envelope.json").read_text()), failures)
  assert {"host_check_failed", "secret_field"} <= {item.code for item in failures}

  net = json.loads((evidence / "net-host.artifact.json").read_text())
  net["checks"][0]["id"] = "ok"
  failures = []
  gate._validate_net(net, "net-host", REVISION, None, {"compose": {"sha256": "0" * 64}}, failures)
  assert any(item.code == "net_checks_failed" for item in failures)

  restore = json.loads((evidence / "restore.artifact.json").read_text())
  restore["data_proof"] = {"evidence_ref": "../escape", "evidence_sha256": "a" * 64, "sample_hash": "b" * 64}
  restore["receipt_sha256"] = hashlib.sha256(gate._canonical({key: value for key, value in restore.items() if key != "receipt_sha256"})).hexdigest()
  failures = []
  gate._validate_restore(restore, failures, datetime(2026, 7, 17, 8, 0, tzinfo=timezone.utc), datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), 3600, evidence)
  assert any(item.code in {"unsafe_path", "restore_data_proof_mismatch"} for item in failures)

  restore = json.loads((evidence / "restore.artifact.json").read_text())
  restore["signature_verification"]["returncode"] = 1
  failures = []
  gate._validate_restore(restore, failures, datetime(2026, 7, 17, 8, 0, tzinfo=timezone.utc), datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), 3600, evidence)
  assert any(item.code == "restore_signature_invalid" for item in failures)

  approval = {"decision": "go", "status": "approved", "role": "technology_lead", "name": "technology_lead", "git_revision": REVISION, "approved_at": WHEN, "machine_evidence_sha256": "a" * 64}
  failures = []
  gate._validate_approval(approval, "technology_lead", REVISION, "a" * 64, datetime(2026, 7, 17, 8, 0, tzinfo=timezone.utc), datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), 3600, failures)
  assert any(item.code == "approval_before_machine_evidence" for item in failures)

  spoof = tmp_path / "cosign"
  spoof.write_text("#!/bin/sh\necho 'not-cosign version v2.4.3'\n")
  spoof.chmod(spoof.stat().st_mode | stat.S_IXUSR)
  failures = []
  gate._cosign_pin(spoof, sha(spoof), "v2.4.3", failures)
  assert any(item.code == "cosign_version_mismatch" for item in failures)


def test_gate_rejects_envelope_swap_after_crypto_and_unhashable_trust_lists(tmp_path, monkeypatch):
  manifest, trust, release, evidence, cosign = fixture(tmp_path)
  monkeypatch.setattr(gate, "validate_descriptor", lambda *_args, **_kwargs: None)
  monkeypatch.setattr(gate, "validate_release_evidence", lambda *_args, **_kwargs: [])
  monkeypatch.setattr(gate, "verify_restore_receipt_integrity", lambda _document: True)
  original_verify = gate.verify_envelope
  swapped = {"done": False}

  def swap_after_verify(envelope, **kwargs):
    result = original_verify(envelope, **kwargs)
    if not swapped["done"]:
      swapped["done"] = True
      path = evidence / manifest["evidence"]["host"]["path"]
      path.write_text("{}\n")
    return result

  monkeypatch.setattr(gate, "verify_envelope", swap_after_verify)
  failures = gate.validate_gate_manifest(manifest, release_root=release, evidence_root=evidence, trust_policy=trust, cosign_binary=cosign, cosign_sha256=sha(cosign), cosign_version="v2.4.3", as_of=datetime(2026, 7, 17, 8, 11, tzinfo=timezone.utc), envelope_backend=FakeCosignBackend(), descriptor_verifier=lambda *_args, **_kwargs: None)
  assert any(item.code == "envelope_changed_after_crypto" for item in failures)

  bad_trust = copy.deepcopy(trust)
  bad_trust["keys"]["key-v1"]["allowed_roles"] = [{}]
  failures = gate.validate_trust_policy(bad_trust)
  assert any(item.code == "invalid_allowed_roles" for item in failures)
