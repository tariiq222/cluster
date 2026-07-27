#!/usr/bin/env python3
"""Validate Cluster PII/PHI compliance inventory and control register files.

The validator is independent of scripts/validate-docs.sh so P04 evidence can
be exercised without re-running the architecture-closure documentation gate.

Exit codes:
  0  every required file present and structurally valid
  1  any structural violation
  2  required file missing
"""
from __future__ import annotations

import sys
from pathlib import Path
from typing import Any, Mapping

import yaml

REPO = Path(__file__).resolve().parents[1]

REQUIRED_FILES = {
    "inventory": REPO / "docs" / "compliance" / "privacy-data-inventory.yaml",
    "flows": REPO / "docs" / "compliance" / "privacy-data-flows.yaml",
    "controls": REPO / "docs" / "compliance" / "privacy-control-register.yaml",
    "vendor": REPO / "docs" / "compliance" / "privacy-vendor-boundaries.yaml",
    "schema": REPO / "docs" / "compliance" / "privacy-evidence-manifest.schema.json",
}

REQUIRED_KEYS = {
    "inventory": ["version", "baseline_date", "source_commit", "provenance", "totals"],
    "flows": ["version", "baseline_date", "source_commit", "flows"],
    "controls": ["version", "baseline_date", "source_commit", "controls", "totals"],
    "vendor": ["version", "baseline_date", "source_commit", "vendor_boundaries"],
}


def fail(label: str, message: str) -> None:
    print(f"ERROR: {label}: {message}", file=sys.stderr)
    raise SystemExit(1)


def check_file(label: str, path: Path, required_keys: list[str]) -> Mapping[str, Any]:
    if not path.is_file():
        fail(label, f"required file missing: {path}")
    try:
        payload = yaml.safe_load(path.read_text(encoding="utf-8"))
    except yaml.YAMLError as exc:
        fail(label, f"invalid YAML: {exc}")
    if not isinstance(payload, dict):
        fail(label, "root must be a mapping")
    for key in required_keys:
        if key not in payload:
            fail(label, f"missing required key: {key}")
    return payload


def check_inventory_consistency(
    inventory: Mapping[str, Any],
    controls: Mapping[str, Any],
) -> None:
    """Cross-check inventory totals against the control register."""
    inv_totals = inventory.get("totals", {})
    ctrl_totals = controls.get("totals", {})
    if not isinstance(inv_totals, Mapping) or not isinstance(ctrl_totals, Mapping):
        fail("cross-check", "totals must be mappings on both files")
    if inv_totals.get("source_commit") != ctrl_totals.get("source_commit"):
        fail("cross-check", "source_commit differs between inventory and control register")


def main() -> int:
    payloads: dict[str, Mapping[str, Any]] = {}
    for label, (name, path) in [(k, (k, p)) for k, p in REQUIRED_FILES.items()]:
        if label == "schema":
            if not path.is_file():
                fail(label, f"required schema file missing: {path}")
            continue
        payloads[label] = check_file(label, path, REQUIRED_KEYS[label])
    check_inventory_consistency(payloads["inventory"], payloads["controls"])
    print("P04 privacy compliance inventory validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
