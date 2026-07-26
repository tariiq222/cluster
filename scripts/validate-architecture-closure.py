#!/usr/bin/env python3
"""Validate the architecture-closure register schema and contents.

The closure register is the single source of truth for the canonical
historical F findings plus the new C cycle findings. After the 2026-07-26
Scope Amendment (see docs/superpowers/plans/2026-07-26-cluster-complete-
architecture-closure.md) the canonical historical set is the explicitly
documented 19 IDs from `Verified scope and corrections` in the original
2026-07-25 remediation plan; the F001-F123 completeness claim is
superseded and the 104 unreachable findings are recorded as
``historical_findings_unrecoverable`` rather than as placeholders. New
cycle findings (C129+) must carry source/command evidence.

``validate(payload)`` is a pure helper so callers (notably the focused
PHP coverage) can exercise it with an in-memory dictionary. ``main()``
loads the register from disk (override path via ``sys.argv[1]``) and
returns a non-zero exit code on any violation, gating the documentation
validation pipeline.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path
from typing import Any, Mapping

import yaml

REGISTER = (
    Path(__file__).resolve().parents[1]
    / "docs"
    / "architecture"
    / "architecture-closure-register.yaml"
)
ALLOWED_STATUS = {"open", "blocked", "closed", "accepted-risk", "not-a-defect"}
ALLOWED_PRIORITY = {"P0", "P1", "P2"}
ALLOWED_DOMAIN = {
    "contracts",
    "boundaries",
    "data-integrity",
    "security",
    "web",
    "migrations",
    "tooling",
}
ALLOWED_EVIDENCE_KIND = {"source", "command"}

# Canonical historical set per the 2026-07-26 Scope Amendment. No F001-F123
# completeness claim; the 19 documented IDs are the only legacy entries.
CANONICAL_F_SET: frozenset[str] = frozenset({
    "F020", "F023", "F030", "F033", "F035",
    "F044", "F046", "F059", "F067", "F072",
    "F076", "F078", "F087", "F089",
    "F112", "F113", "F115", "F116", "F117",
})
# Cycle findings reserved during the closure cycle.
RESERVED_C_SET: frozenset[str] = frozenset({
    "C124", "C125", "C126", "C127", "C128",
})
REQUIRED_FINDING_SET = CANONICAL_F_SET | RESERVED_C_SET
REQUIRED_OWNER_TASK: Mapping[str, int] = {
    "F020": 6, "F023": 8, "F030": 7, "F033": 8, "F035": 8,
    "F044": 12, "F046": 12, "F059": 8, "F067": 8, "F072": 9,
    "F076": 8, "F078": 4, "F087": 10, "F089": 10,
    "F112": 10, "F113": 10, "F115": 10, "F116": 10,
    "F117": 11, "C124": 2, "C125": 3, "C126": 4,
    "C127": 5, "C128": 13,
}
TERMINAL_STATUSES = {"closed", "not-a-defect", "accepted-risk"}
ID_RE = re.compile(r"^[FC]\d{3,}$")


class ArchitectureClosureValidationError(RuntimeError):
    """Raised when a register payload violates the validator contract."""


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def _validate_scope_metadata(scope: Mapping[str, Any]) -> None:
    """Validate the top-level `scope` metadata block.

    The block must declare ``historical_findings_tracked``,
    ``historical_findings_unrecoverable``, the scope ``decision``,
    ``decision_date``, and the exact list of approved historical IDs.
    Wording must never imply all 123 historical IDs were tracked.
    """

    if not isinstance(scope, Mapping):
        raise ArchitectureClosureValidationError(
            "scope metadata block must be a mapping"
        )

    text = scope.get("decision")
    if text != "user-approved scope amendment":
        raise ArchitectureClosureValidationError(
            "scope.decision must equal 'user-approved scope amendment'"
        )

    tracked = scope.get("historical_findings_tracked")
    if tracked != len(CANONICAL_F_SET):
        raise ArchitectureClosureValidationError(
            f"scope.historical_findings_tracked must equal "
            f"{len(CANONICAL_F_SET)} (got {tracked!r})"
        )

    unrecoverable = scope.get("historical_findings_unrecoverable")
    if unrecoverable != 123 - len(CANONICAL_F_SET):
        raise ArchitectureClosureValidationError(
            "scope.historical_findings_unrecoverable must equal "
            f"{123 - len(CANONICAL_F_SET)} (got {unrecoverable!r})"
        )

    date = scope.get("decision_date")
    if not isinstance(date, str) or not date:
        raise ArchitectureClosureValidationError(
            "scope.decision_date is required"
        )

    approved = scope.get("approved_historical_ids")
    if not isinstance(approved, list):
        raise ArchitectureClosureValidationError(
            "scope.approved_historical_ids must be a list"
        )
    approved_set = {str(x) for x in approved}
    if approved_set != CANONICAL_F_SET:
        missing = sorted(CANONICAL_F_SET - approved_set)
        extra = sorted(approved_set - CANONICAL_F_SET)
        raise ArchitectureClosureValidationError(
            "scope.approved_historical_ids must match the canonical set. "
            f"missing={missing} extra={extra}"
        )

    statement = scope.get("closure_wording")
    if not isinstance(statement, str) or not statement:
        raise ArchitectureClosureValidationError(
            "scope.closure_wording is required (must never imply all 123 "
            "historical IDs were tracked)"
        )
    banned_phrases = (
        "all 123",
        "tracked 123",
        "f001-f123 are tracked",
        "f001..f123 are tracked",
        "f001..f123 tracked",
    )
    lowered = statement.lower()
    for phrase in banned_phrases:
        if phrase in lowered:
            raise ArchitectureClosureValidationError(
                f"scope.closure_wording must never state {phrase!r}"
            )


def validate(payload: Mapping[str, Any]) -> None:
    """Validate the decoded closure-register payload.

    Raises ``ArchitectureClosureValidationError`` with a precise ID-tagged
    message on the first violation.
    """

    if not isinstance(payload, dict):
        raise ArchitectureClosureValidationError(
            "closure register root must be a mapping"
        )

    scope = payload.get("scope")
    if not isinstance(scope, Mapping):
        raise ArchitectureClosureValidationError(
            "top-level scope metadata is required (Scope Amendment 2026-07-26)"
        )
    _validate_scope_metadata(scope)

    findings = payload.get("findings")
    if not isinstance(findings, list):
        raise ArchitectureClosureValidationError("findings must be a list")

    ids: list[str] = []
    for entry in findings:
        if not isinstance(entry, dict):
            raise ArchitectureClosureValidationError(
                "each finding must be a mapping"
            )
        ids.append(str(entry.get("id")))

    if len(ids) != len(set(ids)):
        duplicates = sorted({entry_id for entry_id in ids if ids.count(entry_id) > 1})
        raise ArchitectureClosureValidationError(
            "architecture closure finding IDs must be unique: "
            + ", ".join(duplicates)
        )
    missing_required = sorted(REQUIRED_FINDING_SET - set(ids))
    if missing_required:
        raise ArchitectureClosureValidationError(
            "required architecture closure findings are missing: "
            + ", ".join(missing_required)
        )

    for fid in ids:
        m = ID_RE.match(fid)
        if m is None:
            raise ArchitectureClosureValidationError(
                f"{fid}: invalid identifier (must match F### or C###)"
            )
        if fid.startswith("F") and fid not in CANONICAL_F_SET:
            raise ArchitectureClosureValidationError(
                f"{fid}: F### identifier not in the canonical historical set "
                f"({', '.join(sorted(CANONICAL_F_SET))}); the F001-F123 "
                "completeness claim is superseded"
            )
        if fid.startswith("C"):
            suffix = fid[1:]
            if fid not in RESERVED_C_SET and (
                suffix.startswith("0") or int(suffix) < 129
            ):
                raise ArchitectureClosureValidationError(
                    f"{fid}: cycle identifier must be reserved C124-C128 "
                    "or canonical C129+ without leading zeroes"
                )

    for entry in findings:
        finding_id = entry.get("id")
        status = entry.get("status")
        if status not in ALLOWED_STATUS:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: invalid status"
            )
        if entry.get("priority") not in ALLOWED_PRIORITY:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: invalid priority"
            )
        if entry.get("domain") not in ALLOWED_DOMAIN:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: invalid domain"
            )
        if not entry.get("claim") or not entry.get("exit_criteria"):
            raise ArchitectureClosureValidationError(
                f"{finding_id}: claim and exit criteria are required"
            )
        owner_task = entry.get("owner_task")
        if not isinstance(owner_task, int) or isinstance(owner_task, bool) or not 1 <= owner_task <= 14:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: owner_task must be an integer from 1 to 14"
            )
        expected_owner = REQUIRED_OWNER_TASK.get(str(finding_id))
        if expected_owner is not None and owner_task != expected_owner:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: owner_task must equal {expected_owner}"
            )
        sourced = entry.get("sourced")
        if not isinstance(sourced, bool):
            raise ArchitectureClosureValidationError(
                f"{finding_id}: sourced must be a boolean"
            )
        if sourced is False:
            if status in TERMINAL_STATUSES:
                raise ArchitectureClosureValidationError(
                    f"{finding_id}: sourced: false entries cannot be terminal"
                )
            claim_text = str(entry.get("claim") or "")
            if "UNSOURCED" not in claim_text:
                raise ArchitectureClosureValidationError(
                    f"{finding_id}: sourced: false claim must contain the UNSOURCED marker"
                )
            if not entry.get("exit_criteria"):
                raise ArchitectureClosureValidationError(
                    f"{finding_id}: sourced: false entries must retain a non-empty exit criterion"
                )
        evidence = entry.get("evidence")
        requires_strict_evidence = status in TERMINAL_STATUSES or (
            isinstance(finding_id, str)
            and finding_id.startswith("C")
            and finding_id not in RESERVED_C_SET
        )
        if requires_strict_evidence:
            if not isinstance(evidence, list) or not evidence:
                raise ArchitectureClosureValidationError(
                    f"{finding_id}: evidence requires at least one source/command mapping"
                )
            for item in evidence:
                if not isinstance(item, Mapping):
                    raise ArchitectureClosureValidationError(
                        f"{finding_id}: every evidence item must be a mapping"
                    )
                if item.get("kind") not in ALLOWED_EVIDENCE_KIND:
                    raise ArchitectureClosureValidationError(
                        f"{finding_id}: every evidence item kind must be "
                        f"one of {sorted(ALLOWED_EVIDENCE_KIND)}"
                    )
                value = item.get("value")
                if not isinstance(value, str) or not value.strip():
                    raise ArchitectureClosureValidationError(
                        f"{finding_id}: every evidence item requires a non-empty string value"
                    )
        if (
            isinstance(finding_id, str)
            and finding_id.startswith("C")
            and finding_id not in RESERVED_C_SET
            and sourced is not True
        ):
            raise ArchitectureClosureValidationError(
                f"{finding_id}: C129+ cycle finding must declare sourced: true"
            )
        if status == "accepted-risk" and entry.get("priority") in {"P0", "P1"}:
            raise ArchitectureClosureValidationError(
                f"{finding_id}: P0/P1 findings cannot be accepted risks"
            )


def _load_register(path: Path) -> Mapping[str, Any]:
    if not path.is_file():
        raise ArchitectureClosureValidationError(
            f"closure register is missing: {path}"
        )
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError) as error:
        raise ArchitectureClosureValidationError(
            f"invalid YAML in closure register: {error}"
        ) from error


def main() -> None:
    target = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else REGISTER
    try:
        validate(_load_register(target))
    except ArchitectureClosureValidationError as error:
        fail(str(error))


if __name__ == "__main__":
    main()
