#!/usr/bin/env python3
"""Fail-closed validation of externally captured DEP-05 release evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shlex
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Sequence


CONTRACT_VERSION = "1.0.0"
RECEIPT_VERSION = "1.0.0"
ALLOWED_STATUSES = {"passed", "failed", "skipped"}
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
HEX_RE = re.compile(r"^[0-9a-fA-F]{64,128}$")
SHA256_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
CANONICAL_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
EVIDENCE_REF_RE = re.compile(r"^[^\s\x00\r\n]{3,512}$")
MAX_EVIDENCE_AGE_SECONDS = 7 * 24 * 60 * 60
MAX_FUTURE_SKEW_SECONDS = 5 * 60


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


def _obj_with_optional(value: Any, path: str, required: set[str], optional: set[str], out: list[Failure]) -> dict[str, Any]:
    """Validate an object whose evidence bindings are conditional at runtime."""
    if not isinstance(value, dict):
        _fail(out, "invalid_type", path, "must be an object")
        return {}
    for key in sorted(required - set(value)):
        _fail(out, "missing_field", f"{path}.{key}", "required field is missing")
    allowed = required | optional
    for key in sorted(set(value) - allowed):
        _fail(out, "unknown_field", f"{path}.{key}", "field is not allowed")
    return value


def _text(value: Any, path: str, out: list[Failure]) -> bool:
    if isinstance(value, str) and value.strip():
        return True
    _fail(out, "invalid_string", path, "must be a non-empty string")
    return False


def _status(value: Any, path: str, out: list[Failure], required: str = "passed") -> bool:
    if value not in ALLOWED_STATUSES:
        _fail(out, "invalid_status", path, "must be passed, failed, or skipped")
        return False
    if required and value != required:
        _fail(out, "evidence_not_passed", path, f"must be {required}")
        return False
    return True


def _timestamp(value: Any, path: str, out: list[Failure]) -> datetime | None:
    if not _text(value, path, out):
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        _fail(out, "invalid_timestamp", path, "must be RFC 3339")
        return None
    if parsed.tzinfo is None:
        _fail(out, "naive_timestamp", path, "must include a timezone")
        return None
    return parsed.astimezone(timezone.utc)


def _identifier(value: Any, path: str, out: list[Failure]) -> bool:
    if _text(value, path, out) and re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,63}", value):
        return True
    if isinstance(value, str) and value.strip():
        _fail(out, "invalid_identifier", path, "must be a lowercase identifier")
    return False


def validate_release_descriptor(document: Any) -> list[Failure]:
    failures: list[Failure] = []
    root = _obj(document, "$", {"contract_version", "release_id", "environment", "git_commit", "compose_revision", "compose_sha256", "images", "migration", "healthcheck"}, failures)
    if not root:
        return failures
    if root.get("contract_version") != CONTRACT_VERSION:
        _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
    _identifier(root.get("release_id"), "$.release_id", failures)
    if root.get("environment") not in {"staging", "production"}:
        _fail(failures, "invalid_environment", "$.environment", "must be staging or production")
    if not isinstance(root.get("git_commit"), str) or not SHA_RE.fullmatch(root["git_commit"]):
        _fail(failures, "invalid_git_commit", "$.git_commit", "must be a full 40-character hexadecimal commit SHA")
    _text(root.get("compose_revision"), "$.compose_revision", failures)
    if not isinstance(root.get("compose_sha256"), str) or not SHA256_RE.fullmatch(root["compose_sha256"]):
        _fail(failures, "invalid_compose_sha256", "$.compose_sha256", "must be sha256:<64 lowercase hex characters>")
    images = root.get("images")
    if not isinstance(images, dict) or not images:
        _fail(failures, "invalid_images", "$.images", "must contain pinned image digests")
    else:
        for name, digest in images.items():
            if not re.fullmatch(r"[a-z][a-z0-9_-]{1,31}", str(name)) or not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
                _fail(failures, "unpinned_image", f"$.images.{name}", "must be a sha256 digest")
    migration = _obj(root.get("migration"), "$.migration", {"version", "compatibility", "destructive", "pre_backup_required"}, failures)
    _text(migration.get("version"), "$.migration.version", failures)
    if migration.get("compatibility") not in {"compatible", "requires_pre_backup", "incompatible"}:
        _fail(failures, "invalid_migration_compatibility", "$.migration.compatibility", "unsupported compatibility value")
    elif migration.get("compatibility") == "incompatible":
        _fail(failures, "invalid_migration_compatibility", "$.migration.compatibility", "release cannot be deployed with an incompatible migration")
    if type(migration.get("destructive")) is not bool:
        _fail(failures, "invalid_boolean", "$.migration.destructive", "must be boolean")
    if type(migration.get("pre_backup_required")) is not bool:
        _fail(failures, "invalid_boolean", "$.migration.pre_backup_required", "must be boolean")
    if migration.get("destructive") is True and migration.get("pre_backup_required") is not True:
        _fail(failures, "destructive_without_backup", "$.migration.pre_backup_required", "destructive migration requires a pre-deploy backup")
    health = _obj(root.get("healthcheck"), "$.healthcheck", {"path", "expected_status", "timeout_seconds"}, failures)
    if not isinstance(health.get("path"), str) or not re.fullmatch(r"^/[^?#]*$", health.get("path", "")):
        _fail(failures, "invalid_health_path", "$.healthcheck.path", "must be an absolute local path without query or fragment")
    if type(health.get("expected_status")) is not int or not 100 <= health["expected_status"] <= 599:
        _fail(failures, "invalid_http_status", "$.healthcheck.expected_status", "must be an HTTP status")
    if type(health.get("timeout_seconds")) is not int or health["timeout_seconds"] <= 0:
        _fail(failures, "invalid_timeout", "$.healthcheck.timeout_seconds", "must be positive")
    return failures


def _safe_evidence_file(reference: Any, root: Path | None, path: str, failures: list[Failure]) -> Path | None:
    if root is None:
        _fail(failures, "evidence_root_required", path, "an explicit evidence_root is required")
        return None
    if not isinstance(reference, str) or not reference or Path(reference).is_absolute() or ".." in Path(reference).parts or not EVIDENCE_REF_RE.fullmatch(reference):
        _fail(failures, "unsafe_evidence_ref", path, "must be a relative path without traversal, whitespace, or URI syntax")
        return None
    root = root.resolve()
    candidate = root / reference
    try:
        candidate.relative_to(root)
        current = root
        for part in Path(reference).parts:
            current /= part
            if current.is_symlink():
                _fail(failures, "symlink_evidence_ref", path, "symlink evidence is not accepted")
                return None
        fd = os.open(candidate, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        os.close(fd)
    except ValueError:
        _fail(failures, "unsafe_evidence_ref", path, "must remain under evidence_root")
        return None
    except (FileNotFoundError, NotADirectoryError):
        _fail(failures, "missing_evidence_ref", path, "referenced evidence file does not exist")
        return None
    except OSError:
        _fail(failures, "unsafe_evidence_ref", path, "evidence file is not a regular readable file")
        return None
    if not candidate.is_file():
        _fail(failures, "missing_evidence_ref", path, "referenced evidence file must be regular")
        return None
    return candidate


def _proof(item: dict[str, Any], path: str, failures: list[Failure], evidence_root: Path | None) -> None:
    reference = item.get("evidence_ref")
    expected = item.get("evidence_sha256")
    if not isinstance(reference, str) or not EVIDENCE_REF_RE.fullmatch(reference):
        _fail(failures, "invalid_evidence_ref", f"{path}.evidence_ref", "must be a safe relative evidence path")
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-fA-F]{64}", expected):
        _fail(failures, "invalid_evidence_sha256", f"{path}.evidence_sha256", "must be a SHA-256 evidence hash")
    candidate = _safe_evidence_file(reference, evidence_root, f"{path}.evidence_ref", failures)
    if candidate is None:
        return
    try:
        fd = os.open(candidate, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        with os.fdopen(fd, "rb") as stream:
            digest = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
        if digest.hexdigest() != expected.lower():
            _fail(failures, "evidence_hash_mismatch", f"{path}.evidence_sha256", "does not match the rooted evidence bytes")
    except OSError:
        _fail(failures, "evidence_read_failed", f"{path}.evidence_ref", "evidence could not be read safely")


def _check_timestamp_window(timestamp: datetime | None, path: str, failures: list[Failure], now: datetime) -> None:
    if timestamp is None:
        return
    age = (now - timestamp).total_seconds()
    if age < -MAX_FUTURE_SKEW_SECONDS:
        _fail(failures, "future_timestamp", path, "evidence timestamp is in the future")
    elif age > MAX_EVIDENCE_AGE_SECONDS:
        _fail(failures, "stale_timestamp", path, "evidence timestamp is stale")


def validate_release_evidence(document: Any, *, evidence_root: Path | str | None = None, as_of: datetime | None = None) -> list[Failure]:
    failures: list[Failure] = []
    root = _obj(document, "$", {"contract_version", "release_n", "release_n_plus_1", "post_deploy", "rollback", "migration", "critical_journeys"}, failures)
    if not root:
        return failures
    if root.get("contract_version") != CONTRACT_VERSION:
        _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
    descriptors: dict[str, Any] = {}
    for key in ("release_n", "release_n_plus_1"):
        descriptor = root.get(key)
        descriptors[key] = descriptor
        failures.extend(Failure(f.code, f"$.{key}{f.path[1:]}", f.message) for f in validate_release_descriptor(descriptor))
    n_id = descriptors["release_n"].get("release_id") if isinstance(descriptors["release_n"], dict) else None
    n1_id = descriptors["release_n_plus_1"].get("release_id") if isinstance(descriptors["release_n_plus_1"], dict) else None
    if n_id == n1_id and n_id is not None:
        _fail(failures, "release_ids_not_distinct", "$.release_n_plus_1.release_id", "N and N+1 must differ")
    n = descriptors["release_n"] if isinstance(descriptors["release_n"], dict) else {}
    n1 = descriptors["release_n_plus_1"] if isinstance(descriptors["release_n_plus_1"], dict) else {}
    if n.get("environment") != n1.get("environment"):
        _fail(failures, "environment_mismatch", "$.release_n_plus_1.environment", "N and N+1 must target the same environment")
    if n.get("git_commit") == n1.get("git_commit"):
        _fail(failures, "release_commits_not_changed", "$.release_n_plus_1.git_commit", "N+1 must use a different Git revision")
    if n.get("images") == n1.get("images") and n.get("compose_sha256") == n1.get("compose_sha256"):
        _fail(failures, "release_artifacts_not_changed", "$.release_n_plus_1", "N+1 must change an image digest or Compose hash")
    post = _obj(root.get("post_deploy"), "$.post_deploy", {"status", "release_id", "health_status", "observed_at"}, failures)
    _status(post.get("status"), "$.post_deploy.status", failures)
    _status(post.get("health_status"), "$.post_deploy.health_status", failures)
    if post.get("release_id") != n1_id:
        _fail(failures, "post_deploy_release_mismatch", "$.post_deploy.release_id", "must identify release N+1")
    post_time = _timestamp(post.get("observed_at"), "$.post_deploy.observed_at", failures)
    migration = _obj_with_optional(
        root.get("migration"),
        "$.migration",
        {"compatibility_status", "observed_at"},
        {"pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"},
        failures,
    )
    _status(migration.get("compatibility_status"), "$.migration.compatibility_status", failures)
    migration_time = _timestamp(migration.get("observed_at"), "$.migration.observed_at", failures)
    n1_migration = n1.get("migration") if isinstance(n1.get("migration"), dict) else {}
    pre_backup_required = n1_migration.get("destructive") is True or n1_migration.get("pre_backup_required") is True
    pre_backup_fields = {"pre_backup_id", "pre_backup_manifest_sha256", "pre_backup_completed_at", "pre_backup_environment"}
    provided_pre_backup_fields = pre_backup_fields & set(migration)
    if pre_backup_required or provided_pre_backup_fields:
        for field in sorted(pre_backup_fields - set(migration)):
            reason = "destructive or pre-backup-required migration needs a bound completed backup" if pre_backup_required else "pre-backup bindings must be complete when any binding is supplied"
            _fail(failures, "missing_field", f"$.migration.{field}", reason)
        _text(migration.get("pre_backup_id"), "$.migration.pre_backup_id", failures)
        if not isinstance(migration.get("pre_backup_manifest_sha256"), str) or not CANONICAL_SHA256_RE.fullmatch(migration.get("pre_backup_manifest_sha256", "")):
            _fail(failures, "invalid_pre_backup_manifest_sha256", "$.migration.pre_backup_manifest_sha256", "must be a canonical 64-character lowercase SHA-256")
        pre_backup_time = _timestamp(migration.get("pre_backup_completed_at"), "$.migration.pre_backup_completed_at", failures)
        if migration.get("pre_backup_environment") not in {"staging", "production"}:
            _fail(failures, "invalid_environment", "$.migration.pre_backup_environment", "must be staging or production")
        elif migration.get("pre_backup_environment") != n1.get("environment"):
            _fail(failures, "pre_backup_environment_mismatch", "$.migration.pre_backup_environment", "must match release N+1 environment")
        if pre_backup_time and migration_time and pre_backup_time >= migration_time:
            _fail(failures, "pre_backup_not_completed_before_migration", "$.migration.pre_backup_completed_at", "pre-backup completion must be strictly before migration observation")
    else:
        pre_backup_time = None
    rollback = _obj(root.get("rollback"), "$.rollback", {"status", "from_release_id", "to_release_id", "from_git_commit", "to_git_commit", "from_images", "to_images", "from_compose_sha256", "to_compose_sha256", "health_status", "data_preserved", "observed_at"}, failures)
    _status(rollback.get("status"), "$.rollback.status", failures)
    _status(rollback.get("health_status"), "$.rollback.health_status", failures)
    if rollback.get("from_release_id") != n1_id or rollback.get("to_release_id") != n_id:
        _fail(failures, "rollback_release_mismatch", "$.rollback", "rollback must move from N+1 to N")
    if rollback.get("from_git_commit") != n1.get("git_commit") or rollback.get("to_git_commit") != n.get("git_commit"):
        _fail(failures, "rollback_revision_mismatch", "$.rollback", "rollback revisions must exactly match N+1 to N")
    if rollback.get("from_images") != n1.get("images") or rollback.get("to_images") != n.get("images"):
        _fail(failures, "rollback_images_mismatch", "$.rollback", "rollback image digests must exactly match N+1 to N")
    if rollback.get("from_compose_sha256") != n1.get("compose_sha256") or rollback.get("to_compose_sha256") != n.get("compose_sha256"):
        _fail(failures, "rollback_compose_mismatch", "$.rollback", "rollback Compose hashes must exactly match N+1 to N")
    data = _obj(rollback.get("data_preserved"), "$.rollback.data_preserved", {"status", "evidence_ref", "evidence_sha256", "sample_hash"}, failures)
    _status(data.get("status"), "$.rollback.data_preserved.status", failures)
    _proof(data, "$.rollback.data_preserved", failures, Path(evidence_root).resolve() if evidence_root is not None else None)
    if not isinstance(data.get("sample_hash"), str) or not HEX_RE.fullmatch(data.get("sample_hash", "")):
        _fail(failures, "invalid_sample_hash", "$.rollback.data_preserved.sample_hash", "must be a hexadecimal sample hash")
    rollback_time = _timestamp(rollback.get("observed_at"), "$.rollback.observed_at", failures)
    now = as_of or datetime.now(timezone.utc)
    for path, timestamp in (("$.migration.pre_backup_completed_at", pre_backup_time), ("$.migration.observed_at", migration_time), ("$.post_deploy.observed_at", post_time), ("$.rollback.observed_at", rollback_time)):
        _check_timestamp_window(timestamp, path, failures, now)
    if migration_time and post_time and post_time < migration_time:
        _fail(failures, "event_order", "$.post_deploy.observed_at", "post-deploy must follow migration evidence")
    if post_time and rollback_time and rollback_time < post_time:
        _fail(failures, "event_order", "$.rollback.observed_at", "rollback must follow post-deploy evidence")
    journeys = root.get("critical_journeys")
    if not isinstance(journeys, list) or not journeys:
        _fail(failures, "missing_critical_journeys", "$.critical_journeys", "at least one journey result is required")
    else:
        locales: set[str] = set()
        journey_ids: set[str] = set()
        for index, journey in enumerate(journeys):
            item = _obj(journey, f"$.critical_journeys[{index}]", {"id", "locale", "status", "duration_ms", "evidence_ref", "evidence_sha256"}, failures)
            _identifier(item.get("id"), f"$.critical_journeys[{index}].id", failures)
            if item.get("id") in journey_ids:
                _fail(failures, "duplicate_journey_id", f"$.critical_journeys[{index}].id", "journey IDs must be unique")
            journey_ids.add(item.get("id"))
            if item.get("locale") not in {"ar", "en"}:
                _fail(failures, "invalid_locale", f"$.critical_journeys[{index}].locale", "must be ar or en")
            else:
                locales.add(item["locale"])
            _status(item.get("status"), f"$.critical_journeys[{index}].status", failures)
            _proof(item, f"$.critical_journeys[{index}]", failures, Path(evidence_root).resolve() if evidence_root is not None else None)
            if type(item.get("duration_ms")) is not int or item["duration_ms"] < 0 or item["duration_ms"] >= 5000:
                _fail(failures, "journey_too_slow", f"$.critical_journeys[{index}].duration_ms", "must be below five seconds")
        if locales != {"ar", "en"}:
            _fail(failures, "missing_locale_coverage", "$.critical_journeys", "Arabic and English journeys are both required")
    return failures


def _canonical(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def build_release_receipt(evidence: dict[str, Any], *, evidence_root: Path | str | None = None, as_of: datetime | None = None) -> dict[str, Any]:
    failures = validate_release_evidence(evidence, evidence_root=evidence_root, as_of=as_of)
    if failures:
        raise ValueError(json.dumps([failure.to_dict() for failure in failures], ensure_ascii=False))
    receipt = {
        "receipt_version": RECEIPT_VERSION,
        "contract": "W11-DEP-05",
        "mode": "evidence",
        "status": "passed",
        "acceptance": {"status": "unsigned", "acceptable": False},
        "release_n": evidence["release_n"]["release_id"],
        "release_n_plus_1": evidence["release_n_plus_1"]["release_id"],
        "bindings": {"release_n": evidence["release_n"], "release_n_plus_1": evidence["release_n_plus_1"], "rollback": evidence["rollback"]},
        "post_deploy_release": evidence["post_deploy"]["release_id"],
        "rollback_target": evidence["rollback"]["to_release_id"],
        "observed_at": evidence["rollback"]["observed_at"],
        "critical_journeys": evidence["critical_journeys"],
    }
    receipt["receipt_sha256"] = hashlib.sha256(_canonical(receipt)).hexdigest()
    return receipt


def verify_release_receipt(receipt: dict[str, Any]) -> bool:
    """Verify local tamper detection only; an unsigned receipt is not acceptance evidence."""
    expected = receipt.get("receipt_sha256")
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-f]{64}", expected):
        return False
    unsigned = dict(receipt)
    unsigned.pop("receipt_sha256", None)
    return hashlib.sha256(_canonical(unsigned)).hexdigest() == expected


def execute_injected_command(command: Sequence[str], *, allow_mutation: bool = False) -> dict[str, Any]:
    if not command:
        raise ValueError("an injected command is required")
    if not allow_mutation:
        raise PermissionError("refusing external command without --allow-mutation")
    completed = subprocess.run(list(command), capture_output=True, text=True, check=False)
    return {"command": shlex.quote(command[0]), "returncode": completed.returncode, "stdout_sha256": hashlib.sha256(completed.stdout.encode()).hexdigest(), "stderr_sha256": hashlib.sha256(completed.stderr.encode()).hexdigest()}


def _cli() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--evidence", type=Path, required=True)
    parser.add_argument("--evidence-root", type=Path, required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--allow-mutation", action="store_true")
    parser.add_argument("--command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.dry_run and args.execute:
        parser.error("--dry-run cannot be combined with --execute")
    if args.execute:
        if not args.allow_mutation or not args.command:
            parser.error("--execute requires --allow-mutation and --command")
        if execute_injected_command(args.command, allow_mutation=True)["returncode"] != 0:
            print("FAIL: injected deployment command failed; no release receipt written", file=sys.stderr)
            return 2
    if args.dry_run:
        plan = {"receipt_version": RECEIPT_VERSION, "contract": "W11-DEP-05", "mode": "dry-run", "status": "not-acceptable", "acceptance": {"status": "unsigned", "acceptable": False}}
        plan["receipt_sha256"] = hashlib.sha256(_canonical(plan)).hexdigest()
        args.receipt.parent.mkdir(parents=True, exist_ok=True)
        args.receipt.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"Dry-run plan written to {args.receipt}; no acceptance receipt was produced")
        return 0
    try:
        evidence = json.loads(args.evidence.read_text(encoding="utf-8"))
        receipt = build_release_receipt(evidence, evidence_root=args.evidence_root)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FAIL: no release receipt written: {exc}", file=sys.stderr)
        return 2
    args.receipt.parent.mkdir(parents=True, exist_ok=True)
    args.receipt.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Receipt written to {args.receipt}")
    return 0


if __name__ == "__main__":
    raise SystemExit(_cli())
