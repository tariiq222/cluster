from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

import pytest


pytestmark = pytest.mark.e2e


def test_validate_cli_emits_redacted_local_receipt(tmp_path):
  root = Path(__file__).resolve().parents[3]
  receipt_path = tmp_path / "host-preflight-receipt.json"

  result = subprocess.run(
    [
      sys.executable,
      str(root / "scripts/host_preflight.py"),
      "validate",
      "--inputs",
      str(root / "infra/platform/environments/host.example.json"),
      "--secrets",
      str(root / "infra/platform/contracts/required-secrets.json"),
      "--receipt",
      str(receipt_path),
    ],
    cwd=root,
    capture_output=True,
    text=True,
    check=False,
  )

  assert result.returncode == 0, result.stderr
  receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
  assert receipt["mode"] == "validate"
  assert receipt["summary"]["status"] == "passed"
  assert receipt["redaction"]["secret_values_included"] is False
  assert "password" not in json.dumps(receipt).lower()
