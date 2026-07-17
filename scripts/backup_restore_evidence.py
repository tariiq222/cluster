#!/usr/bin/env python3
"""Validate encrypted off-host backup metadata and separate-target restore proof."""

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
from typing import Any, Callable, Protocol, Sequence


CONTRACT_VERSION = "1.0.0"
RECEIPT_VERSION = "1.0.0"
RPO_LIMIT_SECONDS = 15 * 60
RTO_LIMIT_SECONDS = 2 * 60 * 60
HEX_RE = re.compile(r"^[0-9a-fA-F]{64,128}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
EVIDENCE_REF_RE = re.compile(r"^[^\s\x00\r\n]{3,512}$")
MAX_EVIDENCE_AGE_SECONDS = 7 * 24 * 60 * 60
MAX_FUTURE_SKEW_SECONDS = 5 * 60
ALLOWED_COSIGN_BINARY_NAMES = {"cosign"}
COSIGN_VERSION_RE = re.compile(r"^(?:GitVersion:\s*|cosign version\s+)?(v[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?)\s*$")


class SignatureVerifierBackend(Protocol):
    def verify(self, command: Sequence[str], binding: dict[str, str]) -> dict[str, Any]: ...


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


def _text(value: Any, path: str, out: list[Failure]) -> bool:
    if isinstance(value, str) and value.strip():
        return True
    _fail(out, "invalid_string", path, "must be a non-empty string")
    return False


def _safe_file(path_value: Any, root: Path, path: str, out: list[Failure]) -> Path | None:
    if not isinstance(path_value, str) or not path_value or Path(path_value).is_absolute() or ".." in Path(path_value).parts or not EVIDENCE_REF_RE.fullmatch(path_value):
        _fail(out, "unsafe_path", path, "must be a relative path without traversal or whitespace")
        return None
    root = root.resolve()
    candidate = root / path_value
    try:
        candidate.relative_to(root)
        current = root
        for part in Path(path_value).parts:
            current /= part
            if current.is_symlink():
                _fail(out, "symlink_path", path, "symlink paths are not accepted")
                return None
        fd = os.open(candidate, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            file_stat = os.fstat(fd)
        finally:
            os.close(fd)
    except ValueError:
        _fail(out, "unsafe_path", path, "must remain under the selected root")
        return None
    except (FileNotFoundError, NotADirectoryError):
        _fail(out, "missing_file", path, "referenced file does not exist")
        return None
    except OSError:
        _fail(out, "unsafe_path", path, "referenced file is not readable regular data")
        return None
    if not stat.S_ISREG(file_stat.st_mode):
        _fail(out, "missing_file", path, "referenced path must be a regular file")
        return None
    if file_stat.st_nlink != 1:
        _fail(out, "hardlink_path", path, "hard-linked files are not accepted")
        return None
    return candidate


def _stable_regular_file_stat(fd: int) -> os.stat_result:
    file_stat = os.fstat(fd)
    if not stat.S_ISREG(file_stat.st_mode):
        raise OSError("file is not regular data")
    if file_stat.st_nlink != 1:
        raise OSError("hard-linked files are not accepted")
    return file_stat


def _stat_binding(file_stat: os.stat_result) -> tuple[int, int, int, int, int, int]:
    return (file_stat.st_dev, file_stat.st_ino, file_stat.st_size, file_stat.st_mtime_ns, file_stat.st_ctime_ns, file_stat.st_nlink)


def _hash_file(path: Path, algorithm: str) -> tuple[bytes, str]:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        with os.fdopen(fd, "rb") as stream:
            before = _stable_regular_file_stat(stream.fileno())
            digest = hashlib.new(algorithm)
            chunks: list[bytes] = []
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                chunks.append(chunk)
                digest.update(chunk)
            after = _stable_regular_file_stat(stream.fileno())
    except OSError:
        raise
    if _stat_binding(before) != _stat_binding(after):
        raise OSError("file changed while hashing")
    return b"".join(chunks), digest.hexdigest()


def _read_hash(path: Path) -> tuple[bytes, str]:
    return _hash_file(path, "sha256")


def _proof(value: Any, path: str, out: list[Failure], evidence_root: Path | None) -> bytes | None:
    if not isinstance(value, dict):
        _fail(out, "invalid_proof", path, "must be an evidence proof object")
        return None
    for key in ("evidence_ref", "evidence_sha256"):
        if key not in value:
            _fail(out, "missing_field", f"{path}.{key}", "required field is missing")
    reference = value.get("evidence_ref")
    expected = value.get("evidence_sha256")
    if evidence_root is None:
        _fail(out, "evidence_root_required", f"{path}.evidence_ref", "an explicit evidence_root is required")
        return None
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-fA-F]{64}", expected):
        _fail(out, "invalid_evidence_sha256", f"{path}.evidence_sha256", "must be a SHA-256 evidence hash")
    candidate = _safe_file(reference, evidence_root, f"{path}.evidence_ref", out)
    if candidate is None:
        return None
    try:
        data, actual = _read_hash(candidate)
    except OSError:
        _fail(out, "evidence_read_failed", f"{path}.evidence_ref", "evidence could not be read safely")
        return None
    if isinstance(expected, str) and actual != expected.lower():
        _fail(out, "evidence_hash_mismatch", f"{path}.evidence_sha256", "does not match the rooted evidence bytes")
    return data


def _strict_json_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def _identity(value: Any, path: str, out: list[Failure], evidence_root: Path | None, environment: str | None) -> dict[str, Any]:
    """Bind exact identity-attestation content; authenticity comes from the final trusted envelope."""
    identity = _obj(value, path, {"host_id", "storage_id", "evidence_ref", "evidence_sha256"}, out)
    _text(identity.get("host_id"), f"{path}.host_id", out)
    _text(identity.get("storage_id"), f"{path}.storage_id", out)
    data = _proof(identity, path, out, evidence_root)
    if data is not None:
        try:
            attestation = json.loads(data.decode("utf-8"), object_pairs_hook=_strict_json_object)
        except (UnicodeDecodeError, json.JSONDecodeError, ValueError):
            _fail(out, "invalid_identity_attestation", f"{path}.evidence_ref", "identity evidence must be an exact JSON attestation")
        else:
            required = {"attestation_type", "host_id", "storage_id", "environment"}
            if not isinstance(attestation, dict) or set(attestation) != required or attestation.get("attestation_type") != "host-storage-identity":
                _fail(out, "invalid_identity_attestation", f"{path}.evidence_ref", "identity attestation must contain only the canonical identity fields")
            elif attestation.get("host_id") != identity.get("host_id") or attestation.get("storage_id") != identity.get("storage_id") or attestation.get("environment") != environment:
                _fail(out, "identity_attestation_mismatch", f"{path}.evidence_ref", "attested identity does not match manifest")
    return identity


def parse_timestamp(value: Any, path: str = "$") -> tuple[datetime | None, list[Failure]]:
    failures: list[Failure] = []
    if not _text(value, path, failures):
        return None, failures
    try:
        timestamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        _fail(failures, "invalid_timestamp", path, "must be RFC 3339")
        return None, failures
    if timestamp.tzinfo is None:
        _fail(failures, "naive_timestamp", path, "must include a timezone")
        return None, failures
    return timestamp.astimezone(timezone.utc), failures


def _check_timestamp_window(timestamp: datetime | None, path: str, failures: list[Failure], now: datetime) -> None:
    if timestamp is None:
        return
    age = (now - timestamp).total_seconds()
    if age < -MAX_FUTURE_SKEW_SECONDS:
        _fail(failures, "future_timestamp", path, "evidence timestamp is in the future")
    elif age > MAX_EVIDENCE_AGE_SECONDS:
        _fail(failures, "stale_timestamp", path, "evidence timestamp is stale")


def _digest_file(path: Path, algorithm: str) -> str:
    _, digest = _hash_file(path, algorithm)
    return digest


def _validate_verification(value: dict[str, Any] | None, expected: dict[str, str], out: list[Failure]) -> None:
    if value is None:
        _fail(out, "signature_verification_required", "$.signature", "a typed Cosign verification result is required")
        return
    if value.get("returncode") != 0 or value.get("verified") is not True:
        _fail(out, "signature_verification_failed", "$.signature", "Cosign verification did not prove the exact artifact")
    for key in ("artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256", "cosign_version", "command_sha256"):
        if value.get(key) != expected.get(key):
            _fail(out, "signature_binding_mismatch", f"$.signature.{key}", "verification result is not bound to the manifest files")


def validate_backup_manifest(document: Any, *, artifact_root: Path | str = ".", evidence_root: Path | str | None = None, signature_verification: dict[str, Any] | None = None, verification_binding: dict[str, str] | None = None, as_of: datetime | None = None) -> list[Failure]:
    failures: list[Failure] = []
    artifacts_root = Path(artifact_root).resolve()
    proofs_root = Path(evidence_root).resolve() if evidence_root is not None else None
    root = _obj(document, "$", {"contract_version", "backup_id", "environment", "source_host_id", "source_storage_id", "source_identity", "target_id", "target_host_id", "target_storage_id", "target_identity", "created_at", "completed_at", "last_write_at", "encryption", "checksum", "signature", "immutable_retention_until", "artifacts"}, failures)
    if not root:
        return failures
    if root.get("contract_version") != CONTRACT_VERSION:
        _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
    for key in ("backup_id", "source_host_id", "source_storage_id", "target_id", "target_host_id", "target_storage_id"):
        _text(root.get(key), f"$.{key}", failures)
    source_identity = _identity(root.get("source_identity"), "$.source_identity", failures, proofs_root, root.get("environment"))
    target_identity = _identity(root.get("target_identity"), "$.target_identity", failures, proofs_root, root.get("environment"))
    if source_identity.get("host_id") != root.get("source_host_id") or source_identity.get("storage_id") != root.get("source_storage_id"):
        _fail(failures, "source_identity_mismatch", "$.source_identity", "source identity must match manifest IDs")
    if target_identity.get("host_id") != root.get("target_host_id") or target_identity.get("storage_id") != root.get("target_storage_id"):
        _fail(failures, "target_identity_mismatch", "$.target_identity", "target identity must match manifest IDs")
    if root.get("target_host_id") == root.get("source_host_id") or root.get("target_storage_id") == root.get("source_storage_id"):
        _fail(failures, "backup_target_not_separate", "$.target_identity", "backup host and storage must both differ from production")
    if root.get("environment") not in {"staging", "production"}:
        _fail(failures, "invalid_environment", "$.environment", "must be staging or production")
    timestamps: dict[str, datetime | None] = {}
    for key in ("created_at", "completed_at", "last_write_at", "immutable_retention_until"):
        timestamps[key], current = parse_timestamp(root.get(key), f"$.{key}")
        failures.extend(current)
    if timestamps["created_at"] and timestamps["completed_at"] and timestamps["completed_at"] < timestamps["created_at"]:
        _fail(failures, "backup_time_order", "$.completed_at", "must not precede created_at")
    if timestamps["last_write_at"] and timestamps["completed_at"] and timestamps["last_write_at"] > timestamps["completed_at"]:
        _fail(failures, "last_write_after_backup", "$.last_write_at", "last known write must not follow backup completion")
    if timestamps["immutable_retention_until"] and timestamps["completed_at"] and timestamps["immutable_retention_until"] < timestamps["completed_at"]:
        _fail(failures, "retention_before_completion", "$.immutable_retention_until", "immutable retention must extend beyond completion")
    now = as_of or datetime.now(timezone.utc)
    for key in ("created_at", "completed_at", "last_write_at"):
        _check_timestamp_window(timestamps[key], f"$.{key}", failures, now)
    encryption = _obj(root.get("encryption"), "$.encryption", {"algorithm", "key_id", "encrypted_at_rest"}, failures)
    if encryption.get("algorithm") not in {"AES-256-GCM", "age", "SSE-KMS"}:
        _fail(failures, "unsupported_encryption", "$.encryption.algorithm", "must use an approved encryption method")
    _text(encryption.get("key_id"), "$.encryption.key_id", failures)
    if encryption.get("encrypted_at_rest") is not True:
        _fail(failures, "backup_not_encrypted", "$.encryption.encrypted_at_rest", "must be true")
    checksum = _obj(root.get("checksum"), "$.checksum", {"algorithm", "value", "artifact_path"}, failures)
    if checksum.get("algorithm") not in {"sha256", "sha512"}:
        _fail(failures, "unsupported_checksum", "$.checksum.algorithm", "must be sha256 or sha512")
    if not isinstance(checksum.get("value"), str) or not HEX_RE.fullmatch(checksum["value"]):
        _fail(failures, "invalid_checksum", "$.checksum.value", "must be a hexadecimal digest")
    artifact = _safe_file(checksum.get("artifact_path"), artifacts_root, "$.checksum.artifact_path", failures)
    if artifact is not None and checksum.get("algorithm") in {"sha256", "sha512"} and _digest_file(artifact, checksum["algorithm"]).lower() != checksum.get("value", "").lower():
        _fail(failures, "checksum_mismatch", "$.checksum.value", "checksum does not match the rooted artifact")
    signature = _obj(root.get("signature"), "$.signature", {"algorithm", "key_id", "signature_path", "bundle_path", "public_key_path", "verification_status"}, failures)
    if signature.get("algorithm") != "cosign":
        _fail(failures, "unsupported_signature", "$.signature.algorithm", "only Cosign signatures are accepted")
    _text(signature.get("key_id"), "$.signature.key_id", failures)
    if not isinstance(signature.get("key_id"), str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{2,127}", signature.get("key_id", "")):
        _fail(failures, "non_opaque_key_id", "$.signature.key_id", "must be an opaque key identifier")
    for key in ("signature_path", "bundle_path", "public_key_path"):
        _safe_file(signature.get(key), artifacts_root, f"$.signature.{key}", failures)
    public_key = _safe_file(signature.get("public_key_path"), artifacts_root, "$.signature.public_key_path", failures)
    if public_key is not None:
        try:
            key_data, _ = _read_hash(public_key)
            if b"PRIVATE KEY" in key_data:
                _fail(failures, "private_key_rejected", "$.signature.public_key_path", "private keys are never accepted")
        except OSError:
            _fail(failures, "public_key_unreadable", "$.signature.public_key_path", "public key could not be read")
    if signature.get("verification_status") != "verified":
        _fail(failures, "invalid_signature_status", "$.signature.verification_status", "manifest must be verified")
    expected = {"artifact_sha256": str(checksum.get("value", "")).lower()}
    for key in ("signature_path", "bundle_path", "public_key_path"):
        file = _safe_file(signature.get(key), artifacts_root, f"$.signature.{key}", failures)
        if file is not None:
            try:
                _, digest = _read_hash(file)
                expected[key.replace("_path", "_sha256")] = digest
            except OSError:
                pass
    expected["public_key_sha256"] = expected.get("public_key_sha256", "")
    if verification_binding:
        for key in ("cosign_sha256", "cosign_version", "command_sha256"):
            expected[key] = verification_binding.get(key, "")
    else:
        expected["cosign_sha256"] = ""
        expected["cosign_version"] = ""
        expected["command_sha256"] = ""
    _validate_verification(signature_verification, expected, failures)
    artifact_meta = _obj(root.get("artifacts"), "$.artifacts", {"database", "files", "binlog_position"}, failures)
    for key in ("database", "files"):
        item = _obj(artifact_meta.get(key), f"$.artifacts.{key}", {"included", "object_id", "evidence_ref", "evidence_sha256"}, failures)
        if item.get("included") is not True:
            _fail(failures, "artifact_missing", f"$.artifacts.{key}.included", "artifact must be included")
        _text(item.get("object_id"), f"$.artifacts.{key}.object_id", failures)
        _proof(item, f"$.artifacts.{key}", failures, proofs_root)
    _text(artifact_meta.get("binlog_position"), "$.artifacts.binlog_position", failures)
    return failures


def measure_rpo_rto(*, last_write_at: str, backup_completed_at: str, restore_started_at: str, restore_completed_at: str) -> dict[str, Any]:
    parsed: dict[str, datetime] = {}
    failures: list[Failure] = []
    for key, value in (("last_write_at", last_write_at), ("backup_completed_at", backup_completed_at), ("restore_started_at", restore_started_at), ("restore_completed_at", restore_completed_at)):
        parsed_value, current = parse_timestamp(value, f"$.{key}")
        failures.extend(current)
        if parsed_value is not None:
            parsed[key] = parsed_value
    if failures:
        raise ValueError(json.dumps([failure.to_dict() for failure in failures], ensure_ascii=False))
    if parsed["backup_completed_at"] < parsed["last_write_at"] or parsed["restore_started_at"] < parsed["backup_completed_at"] or parsed["restore_completed_at"] < parsed["restore_started_at"]:
        raise ValueError("restore timing order is invalid")
    rpo = (parsed["backup_completed_at"] - parsed["last_write_at"]).total_seconds()
    rto = (parsed["restore_completed_at"] - parsed["restore_started_at"]).total_seconds()
    return {"rpo_seconds": int(rpo), "rto_seconds": int(rto), "rpo_limit_seconds": RPO_LIMIT_SECONDS, "rto_limit_seconds": RTO_LIMIT_SECONDS, "rpo_within_limit": rpo <= RPO_LIMIT_SECONDS, "rto_within_limit": rto <= RTO_LIMIT_SECONDS}


def validate_restore_receipt(document: Any, manifest: dict[str, Any], *, evidence_root: Path | str | None = None, as_of: datetime | None = None) -> list[Failure]:
    failures: list[Failure] = []
    root = _obj(document, "$", {"contract_version", "restore_id", "backup_id", "environment", "source_host_id", "source_storage_id", "restore_target_id", "restore_target_host_id", "restore_target_storage_id", "target_identity", "status", "observed_at", "checks", "data_proof", "critical_journeys", "timing"}, failures)
    if not root:
        return failures
    if root.get("contract_version") != CONTRACT_VERSION:
        _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
    if root.get("environment") != manifest.get("environment"):
        _fail(failures, "environment_mismatch", "$.environment", "restore environment must match backup environment")
    _text(root.get("restore_id"), "$.restore_id", failures)
    for key in ("backup_id", "source_host_id", "source_storage_id"):
        if root.get(key) != manifest.get(key):
            _fail(failures, f"{key}_mismatch", f"$.{key}", "must match the validated backup manifest")
    for key in ("restore_target_id", "restore_target_host_id", "restore_target_storage_id"):
        _text(root.get(key), f"$.{key}", failures)
    identity = _identity(root.get("target_identity"), "$.target_identity", failures, Path(evidence_root).resolve() if evidence_root is not None else None, root.get("environment"))
    if identity.get("host_id") != root.get("restore_target_host_id") or identity.get("storage_id") != root.get("restore_target_storage_id"):
        _fail(failures, "restore_target_identity_mismatch", "$.target_identity", "restore target identity must match target IDs")
    if root.get("restore_target_host_id") in {manifest.get("source_host_id"), manifest.get("target_host_id")} or root.get("restore_target_storage_id") in {manifest.get("source_storage_id"), manifest.get("target_storage_id")}:
        _fail(failures, "restore_target_not_separate", "$.target_identity", "restore host and storage must differ from production and backup targets")
    if root.get("status") != "passed":
        _fail(failures, "restore_not_passed", "$.status", "restore must be explicitly passed")
    observed, current = parse_timestamp(root.get("observed_at"), "$.observed_at")
    failures.extend(current)
    checks = _obj(root.get("checks"), "$.checks", {"checksum", "signature", "database", "files", "schema", "health"}, failures)
    for key in checks:
        if checks.get(key) != "passed":
            _fail(failures, "restore_check_failed", f"$.checks.{key}", "must be passed")
    evidence_root_path = Path(evidence_root).resolve() if evidence_root is not None else None
    data = _obj(root.get("data_proof"), "$.data_proof", {"evidence_ref", "evidence_sha256", "sample_hash"}, failures)
    _proof(data, "$.data_proof", failures, evidence_root_path)
    if not isinstance(data.get("sample_hash"), str) or not HEX_RE.fullmatch(data.get("sample_hash", "")):
        _fail(failures, "invalid_sample_hash", "$.data_proof.sample_hash", "must be a hexadecimal sample hash")
    journeys = root.get("critical_journeys")
    if not isinstance(journeys, list) or not journeys:
        _fail(failures, "missing_critical_journeys", "$.critical_journeys", "at least one journey is required")
    else:
        locales: set[str] = set()
        ids: set[str] = set()
        for index, journey in enumerate(journeys):
            item = _obj(journey, f"$.critical_journeys[{index}]", {"id", "locale", "status", "duration_ms", "evidence_ref", "evidence_sha256"}, failures)
            _text(item.get("id"), f"$.critical_journeys[{index}].id", failures)
            if item.get("id") in ids:
                _fail(failures, "duplicate_journey_id", f"$.critical_journeys[{index}].id", "journey IDs must be unique")
            ids.add(item.get("id"))
            if item.get("locale") not in {"ar", "en"}:
                _fail(failures, "invalid_locale", f"$.critical_journeys[{index}].locale", "must be ar or en")
            else:
                locales.add(item["locale"])
            if item.get("status") != "passed":
                _fail(failures, "journey_failed", f"$.critical_journeys[{index}].status", "must be passed")
            _proof(item, f"$.critical_journeys[{index}]", failures, evidence_root_path)
            if type(item.get("duration_ms")) is not int or item["duration_ms"] < 0 or item["duration_ms"] >= 5000:
                _fail(failures, "journey_too_slow", f"$.critical_journeys[{index}].duration_ms", "must be below five seconds")
        if locales != {"ar", "en"}:
            _fail(failures, "missing_locale_coverage", "$.critical_journeys", "Arabic and English journeys are both required")
    timing = _obj(root.get("timing"), "$.timing", {"last_write_at", "backup_completed_at", "restore_started_at", "restore_completed_at"}, failures)
    now = as_of or datetime.now(timezone.utc)
    _check_timestamp_window(observed, "$.observed_at", failures, now)
    parsed_timing: dict[str, datetime | None] = {}
    for key, value in timing.items():
        parsed_timing[key], current = parse_timestamp(value, f"$.timing.{key}")
        failures.extend(current)
        _check_timestamp_window(parsed_timing[key], f"$.timing.{key}", failures, now)
    for key, manifest_key in (("last_write_at", "last_write_at"), ("backup_completed_at", "completed_at")):
        if timing.get(key) != manifest.get(manifest_key):
            _fail(failures, f"{key}_mismatch", f"$.timing.{key}", f"must exactly match backup manifest {manifest_key}")
    if parsed_timing.get("restore_completed_at") and observed and observed < parsed_timing["restore_completed_at"]:
        _fail(failures, "event_order", "$.observed_at", "receipt observation must follow restore completion")
    try:
        measured = measure_rpo_rto(**timing)
    except (TypeError, ValueError) as exc:
        _fail(failures, "invalid_timing", "$.timing", str(exc))
    else:
        if not measured["rpo_within_limit"]:
            _fail(failures, "rpo_exceeded", "$.timing", "RPO exceeds 15 minutes")
        if not measured["rto_within_limit"]:
            _fail(failures, "rto_exceeded", "$.timing", "RTO exceeds 2 hours")
    return failures


def _canonical(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def _cosign_binary_binding(binary: Path, expected_sha256: str, expected_version: str) -> tuple[list[str] | None, dict[str, str], str | None]:
    if binary.name not in ALLOWED_COSIGN_BINARY_NAMES:
        return None, {}, "Cosign binary name is not allowed"
    if not binary.is_absolute() or binary.is_symlink() or not binary.is_file() or not os.access(binary, os.X_OK):
        return None, {}, "Cosign binary must be an absolute, executable, non-symlink file"
    try:
        _, digest = _read_hash(binary)
    except OSError:
        return None, {}, "Cosign binary could not be hashed"
    if not isinstance(expected_sha256, str) or not SHA256_RE.fullmatch(expected_sha256) or not isinstance(expected_version, str) or not COSIGN_VERSION_RE.fullmatch(expected_version) or digest != expected_sha256:
        return None, {}, "Cosign binary SHA-256 does not match expected pin"
    try:
        result = subprocess.run([str(binary), "version"], capture_output=True, text=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return None, {}, "Cosign version could not be verified"
    reported_versions = [match.group(1) for line in (result.stdout + result.stderr).splitlines() if (match := COSIGN_VERSION_RE.fullmatch(line.strip()))]
    if result.returncode != 0 or len(reported_versions) != 1 or reported_versions[0] != expected_version:
        return None, {}, "Cosign version does not match expected pin"
    return [str(binary)], {"cosign_sha256": digest, "cosign_version": expected_version}, None


def _cosign_command(command_prefix: Sequence[str], binding: dict[str, str]) -> list[str]:
    return list(command_prefix) + ["verify-blob", "--key", binding["public_key_path"], "--signature", binding["signature_path"], "--bundle", binding["bundle_path"], binding["artifact_path"]]


def _run_cosign_verifier(command: Sequence[str], binding: dict[str, str]) -> dict[str, Any]:
    try:
        result = subprocess.run(command, stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=120, check=False)
        return {"returncode": result.returncode, "verified": result.returncode == 0, "command_sha256": hashlib.sha256(_canonical(list(command))).hexdigest(), "stdout_sha256": hashlib.sha256(result.stdout.encode()).hexdigest(), "stderr_sha256": hashlib.sha256(result.stderr.encode()).hexdigest(), **{key: binding[key] for key in ("artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256", "cosign_version")}}
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {"returncode": 125, "verified": False, "command_sha256": hashlib.sha256(_canonical(list(command))).hexdigest(), "stdout_sha256": "", "stderr_sha256": hashlib.sha256(str(exc).encode()).hexdigest(), **{key: binding[key] for key in ("artifact_sha256", "signature_sha256", "bundle_sha256", "public_key_sha256", "cosign_sha256", "cosign_version")}}


def _assert_bound_files_unchanged(paths: dict[str, str], expected: dict[str, str], root: Path) -> None:
    """Detect replacement or mutation between the file binding and verifier execution."""
    for key, path in paths.items():
        failures: list[Failure] = []
        candidate = _safe_file(path, root, f"$.{key}", failures)
        if candidate is None:
            raise ValueError(json.dumps([failure.to_dict() for failure in failures]))
        _, actual = _read_hash(candidate)
        if actual != expected[key.replace("_path", "_sha256")]:
            raise ValueError(f"bound {key} changed during Cosign verification")


def build_restore_receipt(manifest: dict[str, Any], restore: dict[str, Any], *, artifact_root: Path | str, evidence_root: Path | str, cosign_binary: Path | None = None, cosign_sha256: str | None = None, cosign_version: str | None = None, verifier_backend: SignatureVerifierBackend | None = None, artifact_path: str | None = None, signature_path: str | None = None, bundle_path: str | None = None, public_key_path: str | None = None, as_of: datetime | None = None) -> dict[str, Any]:
    root = Path(artifact_root).resolve()
    evidence = Path(evidence_root).resolve()
    signature = manifest.get("signature", {})
    paths = {"artifact_path": artifact_path or manifest.get("checksum", {}).get("artifact_path"), "signature_path": signature_path or signature.get("signature_path"), "bundle_path": bundle_path or signature.get("bundle_path"), "public_key_path": public_key_path or signature.get("public_key_path")}
    if any(not isinstance(value, str) or value != manifest.get("checksum", {}).get("artifact_path", value) if key == "artifact_path" else not isinstance(value, str) or value != signature.get(key) for key, value in paths.items()):
        raise ValueError("CLI artifact/signature/bundle/key paths must exactly match the manifest bindings")
    files: dict[str, str] = {}
    for key, path in paths.items():
        failures: list[Failure] = []
        candidate = _safe_file(path, root, f"$.{key}", failures)
        if candidate is None:
            raise ValueError(json.dumps([failure.to_dict() for failure in failures]))
        _, files[key.replace("_path", "_sha256")] = _read_hash(candidate)
    if cosign_binary is None or cosign_sha256 is None or cosign_version is None:
        raise ValueError("a pinned absolute Cosign binary, SHA-256, and version are required")
    command_prefix, cosign_binding, error = _cosign_binary_binding(Path(cosign_binary), cosign_sha256, cosign_version)
    if error:
        raise ValueError(error)
    binding = {**files, **cosign_binding, **paths}
    command = _cosign_command(command_prefix or [], binding)
    binding["command_sha256"] = hashlib.sha256(_canonical(command)).hexdigest()
    if verifier_backend is not None:
        verification = verifier_backend.verify(command, binding)
    else:
        verification = _run_cosign_verifier(command, binding)
    _assert_bound_files_unchanged(paths, files, root)
    failures = validate_backup_manifest(manifest, artifact_root=root, evidence_root=evidence, signature_verification=verification, verification_binding=binding, as_of=as_of)
    failures += validate_restore_receipt(restore, manifest, evidence_root=evidence, as_of=as_of)
    if failures:
        raise ValueError(json.dumps([failure.to_dict() for failure in failures], ensure_ascii=False))
    receipt = {
        "receipt_version": RECEIPT_VERSION,
        "contract": "W11-DR-06",
        "status": "passed",
        "acceptance": {"status": "unsigned", "acceptable": False},
        "environment": manifest["environment"],
        "backup_id": manifest["backup_id"],
        "backup_manifest_sha256": hashlib.sha256(_canonical(manifest)).hexdigest(),
        "restore_id": restore["restore_id"],
        "source_host_id": manifest["source_host_id"],
        "source_storage_id": manifest["source_storage_id"],
        "source_identity": manifest["source_identity"],
        "backup_target_id": manifest["target_id"],
        "backup_target_host_id": manifest["target_host_id"],
        "backup_target_storage_id": manifest["target_storage_id"],
        "backup_target_identity": manifest["target_identity"],
        "restore_target_id": restore["restore_target_id"],
        "restore_target_host_id": restore["restore_target_host_id"],
        "restore_target_storage_id": restore["restore_target_storage_id"],
        "restore_target_identity": restore["target_identity"],
        "checks": restore["checks"],
        "data_proof": restore["data_proof"],
        "backup_encryption": {"algorithm": manifest["encryption"]["algorithm"], "key_id": manifest["encryption"]["key_id"]},
        "checksum_algorithm": manifest["checksum"]["algorithm"],
        "signature_verification": verification,
        "raw_timing": dict(restore["timing"]),
        "timing": measure_rpo_rto(**restore["timing"]),
        "critical_journeys": restore["critical_journeys"],
        "observed_at": restore["observed_at"],
    }
    receipt["receipt_sha256"] = hashlib.sha256(_canonical(receipt)).hexdigest()
    return receipt


def verify_restore_receipt_integrity(receipt: dict[str, Any]) -> bool:
    expected = receipt.get("receipt_sha256")
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-f]{64}", expected):
        return False
    unsigned = dict(receipt)
    unsigned.pop("receipt_sha256", None)
    return hashlib.sha256(_canonical(unsigned)).hexdigest() == expected


def _cli() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--restore", type=Path, required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    parser.add_argument("--artifact-root", type=Path, required=True)
    parser.add_argument("--evidence-root", type=Path, required=True)
    parser.add_argument("--artifact-path", required=True)
    parser.add_argument("--signature-path", required=True)
    parser.add_argument("--bundle-path", required=True)
    parser.add_argument("--public-key", required=True)
    parser.add_argument("--cosign-binary", required=True, type=Path)
    parser.add_argument("--cosign-sha256", required=True)
    parser.add_argument("--cosign-version", required=True)
    parser.add_argument("--as-of")
    args = parser.parse_args()
    try:
        manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
        restore = json.loads(args.restore.read_text(encoding="utf-8"))
        as_of = datetime.fromisoformat(args.as_of.replace("Z", "+00:00")) if args.as_of else None
        receipt = build_restore_receipt(manifest, restore, artifact_root=args.artifact_root, evidence_root=args.evidence_root, cosign_binary=args.cosign_binary, cosign_sha256=args.cosign_sha256, cosign_version=args.cosign_version, artifact_path=args.artifact_path, signature_path=args.signature_path, bundle_path=args.bundle_path, public_key_path=args.public_key, as_of=as_of)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FAIL: no restore receipt written: {exc}", file=sys.stderr)
        return 2
    args.receipt.parent.mkdir(parents=True, exist_ok=True)
    args.receipt.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Receipt written to {args.receipt}")
    return 0


if __name__ == "__main__":
    raise SystemExit(_cli())
