from __future__ import annotations

import hashlib
import json
import os
import stat
import subprocess
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from scripts.backup_restore_evidence import build_restore_receipt, validate_backup_manifest, validate_restore_receipt, verify_restore_receipt_integrity
from scripts.deployment_evidence import build_release_receipt, execute_injected_command, validate_release_descriptor, validate_release_evidence, verify_release_receipt


pytestmark = pytest.mark.unit


def now() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


def ts(value: datetime) -> str:
    return value.isoformat().replace("+00:00", "Z")


def write_file(root: Path, name: str, data: bytes) -> str:
    path = root / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    return name


def proof(root: Path, name: str, data: bytes = b"evidence") -> dict[str, str]:
    ref = write_file(root, name, data)
    return {"evidence_ref": ref, "evidence_sha256": hashlib.sha256(data).hexdigest()}


def identity(root: Path, host: str, storage: str, environment: str, name: str) -> dict[str, str]:
    data = json.dumps({"attestation_type": "host-storage-identity", "host_id": host, "storage_id": storage, "environment": environment}, sort_keys=True).encode()
    result = proof(root, name, data)
    return {"host_id": host, "storage_id": storage, **result}


def release(release_id: str, commit: str, evidence_root: Path, base: datetime) -> dict:
    return {"contract_version": "1.0.0", "release_id": release_id, "environment": "staging", "git_commit": commit, "compose_revision": f"compose-{release_id}", "compose_sha256": "sha256:" + ("3" if release_id == "release-n" else "4") * 64, "images": {"api": "sha256:" + "1" * 64, "web": "sha256:" + "2" * 64}, "migration": {"version": "20260717.1", "compatibility": "compatible", "destructive": False, "pre_backup_required": True}, "healthcheck": {"path": "/up", "expected_status": 200, "timeout_seconds": 5}}


def deployment_evidence(evidence_root: Path | None = None, base: datetime | None = None) -> dict:
    evidence_root = evidence_root or Path(tempfile.mkdtemp(prefix="dep-evidence-"))
    base = base or now()
    p1 = proof(evidence_root, "rollback.json", b"rollback evidence")
    p2 = proof(evidence_root, "journey-ar.json", b"ar journey")
    p3 = proof(evidence_root, "journey-en.json", b"en journey")
    n = release("release-n", "a" * 40, evidence_root, base)
    n1 = release("release-n-plus-1", "b" * 40, evidence_root, base)
    return {"contract_version": "1.0.0", "release_n": n, "release_n_plus_1": n1, "post_deploy": {"status": "passed", "release_id": "release-n-plus-1", "health_status": "passed", "observed_at": ts(base + timedelta(minutes=2))}, "migration": {"compatibility_status": "passed", "pre_backup_id": "backup-before-n-plus-1", "pre_backup_manifest_sha256": "a" * 64, "pre_backup_completed_at": ts(base - timedelta(minutes=1)), "pre_backup_environment": "staging", "observed_at": ts(base)}, "rollback": {"status": "passed", "from_release_id": "release-n-plus-1", "to_release_id": "release-n", "from_git_commit": "b" * 40, "to_git_commit": "a" * 40, "from_images": n1["images"], "to_images": n["images"], "from_compose_sha256": n1["compose_sha256"], "to_compose_sha256": n["compose_sha256"], "health_status": "passed", "data_preserved": {"status": "passed", **p1, "sample_hash": "e" * 64}, "observed_at": ts(base + timedelta(minutes=4))}, "critical_journeys": [{"id": "signin-ar", "locale": "ar", "status": "passed", "duration_ms": 800, **p2}, {"id": "signin-en", "locale": "en", "status": "passed", "duration_ms": 700, **p3}]}


def manifest() -> dict:
    return {"contract_version": "1.0.0", "backup_id": "legacy-fixture", "environment": "production"}


def restore() -> dict:
    return {"contract_version": "1.0.0", "restore_id": "legacy-restore", "backup_id": "legacy-fixture", "environment": "production"}


class FakeVerifier:
    def verify(self, command, binding):
        return {"returncode": 0, "verified": True, "command_sha256": binding["command_sha256"], "stdout_sha256": hashlib.sha256(b"verified").hexdigest(), "stderr_sha256": hashlib.sha256(b"").hexdigest(), **{key: binding[key] for key in ("artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256", "cosign_version")}}


class MutatingVerifier:
    def __init__(self, artifact: Path):
        self.artifact = artifact

    def verify(self, command, binding):
        self.artifact.write_bytes(b"changed-after-binding")
        return FakeVerifier().verify(command, binding)


def make_dr_fixture(tmp_path: Path):
    artifact_root = tmp_path / "artifacts"
    evidence_root = tmp_path / "evidence"
    artifact_root.mkdir(parents=True)
    evidence_root.mkdir(parents=True)
    artifact = write_file(artifact_root, "backup.tar", b"backup-bytes")
    signature_path = write_file(artifact_root, "backup.sig", b"signature-bytes")
    bundle_path = write_file(artifact_root, "backup.bundle", b"bundle-bytes")
    public_key_path = write_file(artifact_root, "cosign.pub", b"-----BEGIN PUBLIC KEY-----\nkey\n-----END PUBLIC KEY-----\n")
    base = now()
    manifest = {"contract_version": "1.0.0", "backup_id": "backup-20260717", "environment": "production", "source_host_id": "prod-host-01", "source_storage_id": "prod-storage-01", "target_id": "backup-store-01", "target_host_id": "backup-host-01", "target_storage_id": "backup-storage-01", "source_identity": identity(evidence_root, "prod-host-01", "prod-storage-01", "production", "source.json"), "target_identity": identity(evidence_root, "backup-host-01", "backup-storage-01", "production", "target.json"), "created_at": ts(base - timedelta(minutes=55)), "completed_at": ts(base - timedelta(minutes=50)), "last_write_at": ts(base - timedelta(minutes=55)), "immutable_retention_until": ts(base + timedelta(days=30)), "encryption": {"algorithm": "AES-256-GCM", "key_id": "kms-backup-v3", "encrypted_at_rest": True}, "checksum": {"algorithm": "sha256", "value": hashlib.sha256(b"backup-bytes").hexdigest(), "artifact_path": artifact}, "signature": {"algorithm": "cosign", "key_id": "backup-signing-v2", "signature_path": signature_path, "bundle_path": bundle_path, "public_key_path": public_key_path, "verification_status": "verified"}, "artifacts": {"database": {"included": True, "object_id": "obj-db-1", **proof(evidence_root, "db.json", b"db")}, "files": {"included": True, "object_id": "obj-files-1", **proof(evidence_root, "files.json", b"files")}, "binlog_position": "mysql-bin.000007:12345"}}
    restore = {"contract_version": "1.0.0", "restore_id": "restore-drill-01", "backup_id": manifest["backup_id"], "environment": "production", "source_host_id": manifest["source_host_id"], "source_storage_id": manifest["source_storage_id"], "restore_target_id": "restore-target-01", "restore_target_host_id": "restore-host-01", "restore_target_storage_id": "restore-storage-01", "target_identity": identity(evidence_root, "restore-host-01", "restore-storage-01", "production", "restore-target.json"), "status": "passed", "observed_at": ts(base), "checks": {key: "passed" for key in ("checksum", "signature", "database", "files", "schema", "health")}, "data_proof": {**proof(evidence_root, "restore-data.json", b"restore-data"), "sample_hash": "7" * 64}, "critical_journeys": [{"id": "read-record-ar", "locale": "ar", "status": "passed", "duration_ms": 900, **proof(evidence_root, "restore-ar.json", b"restore ar")}, {"id": "read-record-en", "locale": "en", "status": "passed", "duration_ms": 950, **proof(evidence_root, "restore-en.json", b"restore en")}], "timing": {"last_write_at": ts(base - timedelta(minutes=55)), "backup_completed_at": ts(base - timedelta(minutes=50)), "restore_started_at": ts(base - timedelta(minutes=35)), "restore_completed_at": ts(base - timedelta(minutes=10))}}
    cosign = tmp_path / "cosign"
    cosign.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo v2.0.0; exit 0; fi\nexit 0\n", encoding="utf-8")
    cosign.chmod(cosign.stat().st_mode | stat.S_IXUSR)
    cosign_hash = hashlib.sha256(cosign.read_bytes()).hexdigest()
    return artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base


def test_release_evidence_hashes_files_and_requires_full_revision(tmp_path: Path):
    evidence_root = tmp_path / "evidence"
    evidence_root.mkdir()
    evidence = deployment_evidence(evidence_root)
    assert validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now()) == []
    receipt = build_release_receipt(evidence, evidence_root=evidence_root, as_of=now())
    assert receipt["status"] == "passed" and receipt["acceptance"]["acceptable"] is False
    evidence["rollback"]["data_preserved"]["evidence_sha256"] = "0" * 64
    assert any(f.code == "evidence_hash_mismatch" for f in validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now()))
    evidence["release_n"]["git_commit"] = "abcdefa"
    assert any(f.code == "invalid_git_commit" for f in validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now()))


def test_destructive_or_prebackup_release_binds_completed_backup_hash_time_and_environment(tmp_path: Path):
    evidence_root = tmp_path / "evidence"
    evidence_root.mkdir()
    evidence = deployment_evidence(evidence_root)
    migration = evidence["migration"]
    for key in ("pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"):
        migration.pop(key)
    failures = validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now())
    assert {failure.path for failure in failures if failure.code == "missing_field"} >= {
        "$.migration.pre_backup_manifest_sha256",
        "$.migration.pre_backup_completed_at",
        "$.migration.pre_backup_environment",
    }

    evidence = deployment_evidence(evidence_root)
    evidence["migration"]["pre_backup_manifest_sha256"] = "A" * 64
    evidence["migration"]["pre_backup_completed_at"] = evidence["migration"]["observed_at"]
    evidence["migration"]["pre_backup_environment"] = "production"
    codes = {failure.code for failure in validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now())}
    assert {"invalid_pre_backup_manifest_sha256", "pre_backup_not_completed_before_migration", "pre_backup_environment_mismatch"} <= codes

    evidence = deployment_evidence(evidence_root)
    evidence["release_n_plus_1"]["migration"].update({"destructive": False, "pre_backup_required": False})
    for key in ("pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"):
        evidence["migration"].pop(key)
    assert validate_release_evidence(evidence, evidence_root=evidence_root, as_of=now()) == []


def test_dep_dr_schemas_match_the_runtime_evidence_bindings():
    root = Path(__file__).resolve().parents[3]
    deployment_schema = json.loads((root / "infra/platform/contracts/dokploy-release-evidence.schema.json").read_text(encoding="utf-8"))
    migration = deployment_schema["properties"]["migration"]
    assert {"pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"} <= set(migration["properties"])
    assert migration["properties"]["pre_backup_manifest_sha256"]["pattern"] == "^[0-9a-f]{64}$"
    assert migration["properties"]["pre_backup_environment"]["enum"] == ["staging", "production"]
    assert {"pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"} <= set(deployment_schema["allOf"][0]["then"]["properties"]["migration"]["required"])
    assert "status" in deployment_schema["$defs"]["dataProof"]["required"]
    assert deployment_schema["$defs"]["dataProof"]["properties"]["status"] == {"const": "passed"}

    restore_schema = json.loads((root / "infra/platform/contracts/restore-receipt.schema.json").read_text(encoding="utf-8"))
    required = set(restore_schema["required"])
    assert {"backup_manifest_sha256", "source_identity", "backup_target_id", "backup_target_host_id", "backup_target_storage_id", "backup_target_identity", "restore_target_identity", "raw_timing"} <= required
    assert "target_identity" not in restore_schema["properties"]
    assert restore_schema["properties"]["raw_timing"]["$ref"] == "#/$defs/rawTiming"


def test_release_rejects_uri_health_path_and_unsafe_proofs(tmp_path: Path):
    root = tmp_path / "evidence"
    root.mkdir()
    candidate = release("release-n", "a" * 40, root, now())
    candidate["healthcheck"]["path"] = "https://attacker.invalid/health"
    assert any(f.code == "invalid_health_path" for f in validate_release_descriptor(candidate))
    evidence = deployment_evidence(root)
    evidence["critical_journeys"][0]["evidence_ref"] = "../secret"
    assert any(f.code == "unsafe_evidence_ref" for f in validate_release_evidence(evidence, evidence_root=root, as_of=now()))


def test_dry_run_never_emits_passed_receipt(tmp_path: Path):
    evidence_root = tmp_path / "evidence"
    evidence_root.mkdir()
    evidence_path = tmp_path / "evidence.json"
    receipt_path = tmp_path / "receipt.json"
    evidence_path.write_text(json.dumps(deployment_evidence(evidence_root)), encoding="utf-8")
    result = subprocess.run(["python3", "scripts/deployment_evidence.py", "--evidence", str(evidence_path), "--evidence-root", str(evidence_root), "--receipt", str(receipt_path), "--dry-run"], capture_output=True, text=True, check=False)
    assert result.returncode == 0
    output = json.loads(receipt_path.read_text())
    assert output["status"] == "not-acceptable" and output["mode"] == "dry-run"


def test_dr_accepts_only_typed_fake_backend_and_hash_bound_files(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    receipt = build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=cosign, cosign_sha256=cosign_hash, cosign_version="v2.0.0", verifier_backend=FakeVerifier(), as_of=base)
    assert receipt["status"] == "passed" and receipt["signature_verification"]["verified"] is True
    assert receipt["environment"] == "production" and receipt["acceptance"]["acceptable"] is False
    assert receipt["backup_manifest_sha256"] == hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    assert receipt["backup_target_id"] == manifest["target_id"]
    assert receipt["backup_target_host_id"] == manifest["target_host_id"]
    assert receipt["backup_target_storage_id"] == manifest["target_storage_id"]
    assert receipt["source_identity"] == manifest["source_identity"]
    assert receipt["backup_target_identity"] == manifest["target_identity"]
    assert receipt["restore_target_identity"] == restore["target_identity"]
    assert receipt["raw_timing"] == restore["timing"]
    assert "target_identity" not in receipt


def test_dr_cli_builds_fixed_cosign_argv_and_python_cannot_pass(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    manifest_path = tmp_path / "manifest.json"
    restore_path = tmp_path / "restore.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
    restore_path.write_text(json.dumps(restore), encoding="utf-8")
    receipt_path = tmp_path / "receipt.json"
    command = ["python3", "scripts/backup_restore_evidence.py", "--manifest", str(manifest_path), "--restore", str(restore_path), "--receipt", str(receipt_path), "--artifact-root", str(artifact_root), "--evidence-root", str(evidence_root), "--artifact-path", manifest["checksum"]["artifact_path"], "--signature-path", manifest["signature"]["signature_path"], "--bundle-path", manifest["signature"]["bundle_path"], "--public-key", manifest["signature"]["public_key_path"], "--cosign-binary", str(cosign), "--cosign-sha256", cosign_hash, "--cosign-version", "v2.0.0", "--as-of", ts(base)]
    result = subprocess.run(command, capture_output=True, text=True, check=False)
    assert result.returncode == 0
    assert json.loads(receipt_path.read_text())["status"] == "passed"

    python_hash = hashlib.sha256(Path(os.sys.executable).read_bytes()).hexdigest()
    blocked = tmp_path / "python-receipt.json"
    bad_command = command.copy()
    bad_command[bad_command.index("--receipt") + 1] = str(blocked)
    bad_command[bad_command.index("--cosign-binary") + 1] = os.sys.executable
    bad_command[bad_command.index("--cosign-sha256") + 1] = python_hash
    bad_command[bad_command.index("--cosign-version") + 1] = "Python"
    bad = subprocess.run(bad_command, capture_output=True, text=True, check=False)
    assert bad.returncode != 0 and not blocked.exists()


def test_dr_rejects_spoofed_cosign_name_and_version(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    spoofed_name = tmp_path / "not-cosign"
    spoofed_name.write_bytes(cosign.read_bytes())
    spoofed_name.chmod(spoofed_name.stat().st_mode | stat.S_IXUSR)
    with pytest.raises(ValueError, match="name is not allowed"):
        build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=spoofed_name, cosign_sha256=hashlib.sha256(spoofed_name.read_bytes()).hexdigest(), cosign_version="v2.0.0", verifier_backend=FakeVerifier(), as_of=base)

    cosign.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo v2.0.0-evil; exit 0; fi\nexit 0\n", encoding="utf-8")
    cosign.chmod(cosign.stat().st_mode | stat.S_IXUSR)
    with pytest.raises(ValueError, match="version does not match"):
        build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=cosign, cosign_sha256=hashlib.sha256(cosign.read_bytes()).hexdigest(), cosign_version="v2.0.0", verifier_backend=FakeVerifier(), as_of=base)


def test_dr_rejects_manifest_commands_private_keys_and_fake_success(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    manifest["signature"]["verification_command"] = ["python3", "-c", "exit(0)"]
    assert any(f.code == "unknown_field" for f in validate_backup_manifest(manifest, artifact_root=artifact_root, evidence_root=evidence_root, signature_verification=None, as_of=base))
    (artifact_root / "cosign.pub").write_bytes(b"-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----\n")
    manifest["signature"].pop("verification_command")
    assert any(f.code == "private_key_rejected" for f in validate_backup_manifest(manifest, artifact_root=artifact_root, evidence_root=evidence_root, signature_verification=None, as_of=base))


def test_dr_rejects_hash_mismatch_traversal_symlink_and_environment(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    manifest["artifacts"]["files"]["evidence_sha256"] = "0" * 64
    with pytest.raises(ValueError, match="evidence_hash_mismatch"):
        build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=cosign, cosign_sha256=cosign_hash, cosign_version="v2.0.0", verifier_backend=FakeVerifier(), as_of=base)
    _, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path / "second")
    restore["environment"] = "staging"
    assert any(f.code == "environment_mismatch" for f in validate_restore_receipt(restore, manifest, evidence_root=evidence_root, as_of=base))
    outside = tmp_path / "outside-proof.json"
    outside.write_bytes(b"outside")
    os.symlink(outside, evidence_root / "escape.json")
    restore["environment"] = "production"
    restore["data_proof"]["evidence_ref"] = "escape.json"
    restore["data_proof"]["evidence_sha256"] = hashlib.sha256(b"outside").hexdigest()
    assert any(f.code == "symlink_path" for f in validate_restore_receipt(restore, manifest, evidence_root=evidence_root, as_of=base))


def test_dr_rejects_declaration_only_identity_hardlinks_and_post_binding_mutation(tmp_path: Path):
    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path)
    attestation_path = evidence_root / manifest["source_identity"]["evidence_ref"]
    declaration = json.loads(attestation_path.read_text(encoding="utf-8"))
    declaration["signature_status"] = "verified"
    attestation_path.write_text(json.dumps(declaration), encoding="utf-8")
    manifest["source_identity"]["evidence_sha256"] = hashlib.sha256(attestation_path.read_bytes()).hexdigest()
    assert any(f.code == "invalid_identity_attestation" for f in validate_backup_manifest(manifest, artifact_root=artifact_root, evidence_root=evidence_root, as_of=base))

    attestation_path.write_text('{"attestation_type":"host-storage-identity","host_id":"prod-host-01","host_id":"prod-host-01","storage_id":"prod-storage-01","environment":"production"}', encoding="utf-8")
    manifest["source_identity"]["evidence_sha256"] = hashlib.sha256(attestation_path.read_bytes()).hexdigest()
    assert any(f.code == "invalid_identity_attestation" for f in validate_backup_manifest(manifest, artifact_root=artifact_root, evidence_root=evidence_root, as_of=base))

    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path / "hardlink")
    os.link(artifact_root / "backup.tar", artifact_root / "backup-hardlink.tar")
    manifest["checksum"]["artifact_path"] = "backup-hardlink.tar"
    with pytest.raises(ValueError, match="hardlink_path"):
        build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=cosign, cosign_sha256=cosign_hash, cosign_version="v2.0.0", verifier_backend=FakeVerifier(), as_of=base)

    artifact_root, evidence_root, manifest, restore, cosign, cosign_hash, base = make_dr_fixture(tmp_path / "mutation")
    with pytest.raises(ValueError, match="bound artifact_path changed during Cosign verification"):
        build_restore_receipt(manifest, restore, artifact_root=artifact_root, evidence_root=evidence_root, cosign_binary=cosign, cosign_sha256=cosign_hash, cosign_version="v2.0.0", verifier_backend=MutatingVerifier(artifact_root / "backup.tar"), as_of=base)


def test_receipt_self_hash_is_only_tamper_detection():
    fake = {"status": "passed", "acceptance": {"status": "unsigned", "acceptable": False}}
    fake["receipt_sha256"] = hashlib.sha256(json.dumps(fake, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    assert verify_release_receipt(fake)
    assert verify_restore_receipt_integrity(fake)


def test_external_commands_require_explicit_mutation_flag():
    with pytest.raises(PermissionError):
        execute_injected_command(["true"])
