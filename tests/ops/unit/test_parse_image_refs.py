from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pytest


pytestmark = pytest.mark.unit
ROOT = Path(__file__).resolve().parents[3]
PARSER = ROOT / "scripts/parse_image_refs.py"
GOOD = (
  "API_IMAGE_DIGEST_REF=registry.internal/third-health-cluster/api@sha256:" + "a" * 64 + "\n"
  "WEB_IMAGE_DIGEST_REF=registry.internal/third-health-cluster/web@sha256:" + "b" * 64 + "\n"
)


def _run(tmp_path: Path, payload: str, key: str = "API_IMAGE_DIGEST_REF") -> subprocess.CompletedProcess[str]:
  artifact = tmp_path / "image-refs.env"
  artifact.write_text(payload, encoding="utf-8")
  return subprocess.run([sys.executable, str(PARSER), str(artifact), key], text=True, capture_output=True)


def test_parser_returns_only_validated_reference(tmp_path: Path):
  result = _run(tmp_path, GOOD)
  assert result.returncode == 0
  assert result.stdout == "registry.internal/third-health-cluster/api@sha256:" + "a" * 64 + "\n"


@pytest.mark.parametrize("payload", [
  GOOD.replace("\n", "\nAPI_IMAGE_DIGEST_REF=registry/x@sha256:" + "c" * 64 + "\n", 1),
  GOOD.replace("API_IMAGE_DIGEST_REF=", "API_IMAGE_DIGEST_REF=$(touch /tmp/pwned); ", 1),
  GOOD.replace("API_IMAGE_DIGEST_REF=", "API_IMAGE_DIGEST_REF=registry/x@sha256:" + "g" * 64 + " # comment\n# ", 1),
  GOOD.replace("WEB_IMAGE_DIGEST_REF=", "MALICIOUS=echo pwned\nWEB_IMAGE_DIGEST_REF=", 1),
  GOOD.replace("\nWEB_IMAGE_DIGEST_REF=", "\n\nWEB_IMAGE_DIGEST_REF=", 1),
])
def test_parser_rejects_duplicate_or_malicious_dotenv_payload(tmp_path: Path, payload: str):
  result = _run(tmp_path, payload)
  assert result.returncode != 0
  assert "FAIL:" in result.stderr
