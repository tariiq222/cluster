from __future__ import annotations

import hashlib
import json
import shutil
import subprocess
import sys
from pathlib import Path

import pytest


pytestmark = pytest.mark.integration
ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/signed_evidence.py"
REVISION = "a" * 40


def _sha(path: Path) -> str:
  return hashlib.sha256(path.read_bytes()).hexdigest()


def _fixture(tmp_path: Path) -> tuple[Path, Path, Path]:
  artifact = tmp_path / "host.json"
  artifact.write_text(json.dumps({"git_revision": REVISION, "status": "passed", "mode": "host-live", "perspective": "host"}) + "\n")
  signature = tmp_path / "host.sig"
  signature.write_bytes(b"sig")
  bundle = tmp_path / "host.bundle"
  bundle.write_bytes(b"bundle")
  key = tmp_path / "public.pem"
  key.write_text("PUBLIC KEY")
  cosign = tmp_path / "cosign"
  cosign.write_text("#!/bin/sh\nif [ \"$1\" = version ]; then echo 'cosign version v2.4.0'; exit 0; fi\nexit 0\n")
  cosign.chmod(0o755)
  envelope = tmp_path / "envelope.json"
  envelope.write_text(json.dumps({
    "contract_version": "1.0.0", "artifact_type": "host", "artifact_path": artifact.name,
    "artifact_sha256": _sha(artifact), "git_revision": REVISION, "observed_at": "2026-07-17T08:00:00Z",
    "signer": {"role": "host_operator", "key_id": "host-key-v1", "public_key_fingerprint": "sha256:" + _sha(key)},
    "signature": {"path": signature.name, "sha256": _sha(signature)}, "bundle": {"path": bundle.name, "sha256": _sha(bundle)},
    "status": "passed", "mode": "host-live", "perspective": "host",
  }))
  payload = json.loads(envelope.read_text())
  statement = tmp_path / "host.statement.json"
  statement.write_text(json.dumps({k: payload[k] for k in ("contract_version", "artifact_type", "artifact_path", "artifact_sha256", "git_revision", "observed_at", "signer", "status", "mode", "perspective")}, sort_keys=True, separators=(",", ":"), ensure_ascii=False))
  payload["statement"] = {"path": statement.name, "sha256": _sha(statement)}
  envelope.write_text(json.dumps(payload))
  return envelope, cosign, key


def _run(envelope: Path, root: Path, binary: Path, key: Path) -> subprocess.CompletedProcess[str]:
  return subprocess.run([
    sys.executable, str(SCRIPT), "--envelope", str(envelope), "--evidence-root", str(root),
    "--as-of", "2026-07-17T08:01:00Z", "--max-age-seconds", "3600", "--cosign-binary", str(binary),
    "--cosign-sha256", _sha(binary), "--cosign-version", "v2.4.0", "--public-key", str(key),
  ], capture_output=True, text=True, check=False)


def test_cli_verifies_with_explicit_cosign_and_public_key(tmp_path):
  envelope, cosign, key = _fixture(tmp_path)
  result = _run(envelope, tmp_path, cosign, key)

  assert result.returncode == 0, result.stderr
  assert json.loads(result.stdout)["status"] == "passed"


@pytest.mark.parametrize("binary", [sys.executable, shutil.which("true")])
def test_cli_rejects_python_true_and_arbitrary_executables(tmp_path, binary):
  envelope, cosign, key = _fixture(tmp_path)
  candidate = Path(binary)
  result = _run(envelope, tmp_path, candidate, key)

  assert result.returncode != 0
  assert "not_cosign_binary" in result.stderr or "unsafe_external_path" in result.stderr


def test_cli_rejects_private_key_material(tmp_path):
  envelope, cosign, key = _fixture(tmp_path)
  key.write_text("-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----\n")
  result = _run(envelope, tmp_path, cosign, key)

  assert result.returncode != 0
  assert "private_key_rejected" in result.stderr


def test_cli_rejects_arbitrary_extra_command_arguments(tmp_path):
  envelope, cosign, key = _fixture(tmp_path)
  result = subprocess.run([
    sys.executable, str(SCRIPT), "--envelope", str(envelope), "--evidence-root", str(tmp_path),
    "--as-of", "2026-07-17T08:01:00Z", "--max-age-seconds", "3600", "--cosign-binary", str(cosign),
    "--cosign-sha256", _sha(cosign), "--cosign-version", "v2.4.0", "--public-key", str(key), "--", "rm", "-rf", "/",
  ], capture_output=True, text=True, check=False)

  assert result.returncode != 0
