#!/usr/bin/env python3
"""Verify signed W1.1 evidence envelopes without signing or shell execution.

The envelope authenticates exact bytes and their provenance.  It intentionally
does not replace the semantic validators used by the acceptance gate.
"""

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
from typing import Any, Mapping, Protocol, Sequence


CONTRACT_VERSION = "1.0.0"
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
REVISION_RE = re.compile(r"^[0-9a-f]{40}$")
FINGERPRINT_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
KEY_ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$")
SAFE_PATH_RE = re.compile(r"^[^\s\x00\r\n/][^\s\x00\r\n]*$")
SECRET_KEY_RE = re.compile(r"(?:password|passwd|token|secret|credential|private[_-]?key)", re.IGNORECASE)
PRIVATE_KEY_RE = re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----", re.IGNORECASE)
PRIVATE_KEY_TEXT_RE = re.compile(r"PRIVATE\s+KEY", re.IGNORECASE)
COSIGN_VERSION_LINE_PATTERNS = (
  re.compile(r"^cosign\s+version\s+(v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$", re.IGNORECASE),
  re.compile(r"^version:\s*(v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$", re.IGNORECASE),
  re.compile(r"^gitversion:\s*(v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$", re.IGNORECASE),
)
ARTIFACT_TYPES = {
  "host",
  "net-host",
  "net-user",
  "net-management",
  "deployment",
  "restore",
  "ci",
  "test-result",
  "approval",
}
ROLE_BY_ARTIFACT = {
  "host": {"host_operator"},
  "net-host": {"network_operator"},
  "net-user": {"network_operator"},
  "net-management": {"network_operator"},
  "deployment": {"release_operator"},
  "restore": {"backup_operator"},
  "ci": {"ci_system"},
  "test-result": {"test_operator", "ci_system"},
  "approval": {"approver", "technology_lead", "sre_lead", "security_lead"},
}
SEMANTIC_MODES = {
  "host": {"host", "host-live", "preflight"},
  "net-host": {"host", "host-live"},
  "net-user": {"edge-live"},
  "net-management": {"edge-live"},
  "deployment": {"deployment", "evidence", "live"},
  "restore": {"restore", "evidence", "live"},
  "ci": {"ci", "pipeline", "build"},
  "test-result": {"test", "test-result", "ci"},
  "approval": {"approval", "gate"},
}
SEMANTIC_PERSPECTIVES = {
  "host": {"host"},
  "net-host": {"host"},
  "net-user": {"user"},
  "net-management": {"management"},
}


@dataclass(frozen=True)
class Failure:
  code: str
  path: str
  message: str

  def to_dict(self) -> dict[str, str]:
    return {"code": self.code, "path": self.path, "message": self.message}


@dataclass(frozen=True)
class CommandResult:
  returncode: int
  stdout: str
  stderr: str = ""


@dataclass(frozen=True)
class CosignTrust:
  """Closed Cosign verifier pins supplied by an external trust root.

  The path, digest, and version are configuration inputs, not evidence.  The
  caller (for example the Gate trust policy or an immutable deployment config)
  is responsible for authenticating this object before passing it here.
  """

  binary: Path
  sha256: str
  version: str


class SignatureVerifierBackend(Protocol):
  """Typed verification seam; production uses :class:`CosignBackend`."""

  def verify(self, argv: Sequence[str]) -> CommandResult:
    ...


class CosignBackend:
  """Production backend with a fixed, read-only argv contract."""

  def verify(self, argv: Sequence[str]) -> CommandResult:
    return _run_fixed(argv)


class DuplicateJSONKey(ValueError):
  pass


def _pairs_without_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
  result: dict[str, Any] = {}
  for key, value in pairs:
    if key in result:
      raise DuplicateJSONKey(f"duplicate JSON key: {key}")
    result[key] = value
  return result


def load_json_no_duplicates(path: Path) -> Any:
  raw, _ = _read_stable_bytes(path)
  return json.loads(raw.decode("utf-8"), object_pairs_hook=_pairs_without_duplicates)


def _fail(out: list[Failure], code: str, path: str, message: str) -> None:
  out.append(Failure(code, path, message))


def _obj(
  value: Any,
  path: str,
  fields: set[str],
  out: list[Failure],
  *,
  required: set[str] | None = None,
) -> dict[str, Any]:
  if not isinstance(value, dict):
    _fail(out, "invalid_type", path, "must be an object")
    return {}
  for key in sorted((required if required is not None else fields) - set(value)):
    _fail(out, "missing_field", f"{path}.{key}", "required field is missing")
  for key in sorted(set(value) - fields):
    _fail(out, "unknown_field", f"{path}.{key}", "field is not allowed")
  return value


def _safe_text(value: Any, path: str, out: list[Failure]) -> bool:
  if isinstance(value, str) and value.strip() and not PRIVATE_KEY_RE.search(value):
    return True
  _fail(out, "invalid_string", path, "must be a non-empty safe string")
  return False


def _timestamp(value: Any, path: str, out: list[Failure]) -> datetime | None:
  if not _safe_text(value, path, out):
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


def _safe_reference(value: Any, path: str, root: Path | None, out: list[Failure]) -> Path | None:
  if (
    not isinstance(value, str)
    or not SAFE_PATH_RE.fullmatch(value)
    or re.match(r"^[A-Za-z]:", value) is not None
    or "://" in value
    or "\\" in value
    or Path(value).is_absolute()
    or ".." in Path(value).parts
  ):
    _fail(out, "unsafe_path", path, "must be a relative path without traversal or whitespace")
    return None
  if root is None:
    _fail(out, "evidence_root_required", path, "an explicit evidence root is required")
    return None
  root = root.resolve()
  candidate = root / value
  try:
    candidate.relative_to(root)
    current = root
    for part in Path(value).parts:
      current /= part
      if current.is_symlink():
        _fail(out, "symlink_path", path, "symlink paths are not accepted")
        return None
    fd = os.open(candidate, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    os.close(fd)
  except ValueError:
    _fail(out, "unsafe_path", path, "path must remain under the evidence root")
    return None
  except (FileNotFoundError, NotADirectoryError):
    _fail(out, "missing_path", path, "referenced file does not exist")
    return None
  except OSError:
    _fail(out, "unsafe_path", path, "referenced path is not a readable regular file")
    return None
  if not candidate.is_file():
    _fail(out, "missing_path", path, "referenced path must be a regular file")
    return None
  return candidate


def _sha256_file(path: Path) -> str:
  digest = hashlib.sha256()
  fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
  with os.fdopen(fd, "rb") as stream:
    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
      digest.update(chunk)
  return digest.hexdigest()


def _read_stable_bytes(path: Path) -> tuple[bytes, str]:
  """Read one regular-file snapshot without reopening between hash and parse."""

  fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
  try:
    before = os.fstat(fd)
    if not stat.S_ISREG(before.st_mode):
      raise OSError("not a regular file")
    chunks: list[bytes] = []
    digest = hashlib.sha256()
    while True:
      chunk = os.read(fd, 1024 * 1024)
      if not chunk:
        break
      chunks.append(chunk)
      digest.update(chunk)
    after = os.fstat(fd)
  finally:
    os.close(fd)
  binding = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
  if binding(before) != binding(after):
    raise OSError("file changed while being read")
  return b"".join(chunks), digest.hexdigest()


def _hash_field(value: Any, path: str, out: list[Failure]) -> bool:
  if isinstance(value, str) and SHA256_RE.fullmatch(value):
    return True
  _fail(out, "invalid_sha256", path, "must be 64 lowercase hexadecimal characters")
  return False


def _contains_secrets(value: Any, path: str, out: list[Failure]) -> None:
  if isinstance(value, dict):
    for key, item in value.items():
      child = f"{path}.{key}"
      if SECRET_KEY_RE.search(str(key)) and str(key).lower() not in {"key_id"}:
        _fail(out, "secret_field", child, "secrets and credentials are not allowed")
      _contains_secrets(item, child, out)
  elif isinstance(value, list):
    for index, item in enumerate(value):
      _contains_secrets(item, f"{path}[{index}]", out)
  elif isinstance(value, str) and PRIVATE_KEY_RE.search(value):
    _fail(out, "private_key_material", path, "private key material is not allowed")


def _semantic_artifact(path: Path, raw: bytes, envelope: Mapping[str, Any], artifact_type: str, out: list[Failure]) -> None:
  try:
    if not (path.suffix.lower() == ".json" or raw.lstrip().startswith(b"{")):
      return
    document = json.loads(raw, object_pairs_hook=_pairs_without_duplicates)
  except DuplicateJSONKey:
    _fail(out, "duplicate_json_key", "$.artifact_path", "artifact JSON contains duplicate keys")
    return
  except json.JSONDecodeError:
    if path.suffix.lower() == ".json":
      _fail(out, "invalid_artifact_json", "$.artifact_path", "JSON artifact could not be parsed")
    return
  except UnicodeDecodeError:
    return
  if not isinstance(document, dict):
    return
  artifact_revision = document.get("git_revision", document.get("source_revision"))
  if artifact_revision is not None:
    if artifact_revision != envelope.get("git_revision"):
      _fail(out, "artifact_revision_mismatch", "$.git_revision", "does not match artifact revision")
  for field in ("status", "mode", "perspective"):
    if field in document:
      envelope_value = envelope.get(field)
      if envelope_value is None:
        _fail(out, f"artifact_{field}_unbound", f"$.{field}", "artifact metadata must be bound by the envelope")
      elif envelope_value != document[field]:
        _fail(out, f"artifact_{field}_mismatch", f"$.{field}", "does not match artifact metadata")
  mode = envelope.get("mode")
  if mode is not None and mode not in SEMANTIC_MODES[artifact_type] and mode != document.get("mode"):
    _fail(out, "artifact_mode_mismatch", "$.mode", "mode is not valid for artifact_type")
  perspective = envelope.get("perspective")
  if (
    perspective is not None
    and artifact_type in SEMANTIC_PERSPECTIVES
    and perspective not in SEMANTIC_PERSPECTIVES[artifact_type]
    and perspective != document.get("perspective")
  ):
    _fail(out, "artifact_perspective_mismatch", "$.perspective", "perspective is not valid for artifact_type")


def _signed_payload(envelope: Mapping[str, Any]) -> dict[str, Any]:
  return {
    key: envelope[key]
    for key in (
      "contract_version", "artifact_type", "artifact_path", "artifact_sha256", "git_revision", "observed_at",
      "signer", "status", "mode", "perspective",
    )
    if key in envelope
  }


def _canonical_statement(envelope: Mapping[str, Any]) -> bytes:
  return json.dumps(_signed_payload(envelope), sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def validate_envelope(
  document: Any,
  *,
  evidence_root: Path | str | None,
  as_of: datetime | None,
  max_age_seconds: int | None,
) -> list[Failure]:
  failures: list[Failure] = []
  required_fields = {"contract_version", "artifact_type", "artifact_path", "artifact_sha256", "git_revision", "observed_at", "signer", "statement", "signature", "bundle"}
  fields = required_fields | {"status", "mode", "perspective"}
  if not isinstance(document, dict):
    _fail(failures, "invalid_type", "$", "must be an object")
    return failures
  root = document
  for key in sorted(required_fields - set(document)):
    _fail(failures, "missing_field", f"$.{key}", "required field is missing")
  for key in sorted(set(document) - fields):
    _fail(failures, "unknown_field", f"$.{key}", "field is not allowed")
  if not root:
    return failures
  _contains_secrets(root, "$", failures)
  if root.get("contract_version") != CONTRACT_VERSION:
    _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
  artifact_type = root.get("artifact_type")
  if artifact_type not in ARTIFACT_TYPES:
    _fail(failures, "invalid_artifact_type", "$.artifact_type", "unsupported artifact type")
  if not isinstance(root.get("git_revision"), str) or not REVISION_RE.fullmatch(root.get("git_revision", "")):
    _fail(failures, "invalid_git_revision", "$.git_revision", "must be a full lowercase 40-character revision")
  observed = _timestamp(root.get("observed_at"), "$.observed_at", failures)
  if "status" in root and (not isinstance(root.get("status"), str) or root.get("status") not in {"passed", "failed", "skipped", "approved"}):
    _fail(failures, "invalid_status", "$.status", "must be passed, failed, skipped, or approved")
  if "mode" in root and (not isinstance(root.get("mode"), str) or not root.get("mode")):
    _fail(failures, "invalid_mode", "$.mode", "must be a non-empty string")
  if "perspective" in root and (not isinstance(root.get("perspective"), str) or root.get("perspective") not in {"host", "user", "management"}):
    _fail(failures, "invalid_perspective", "$.perspective", "must be host, user, or management")
  if as_of is None:
    _fail(failures, "as_of_required", "$.observed_at", "explicit as_of is required")
  elif as_of.tzinfo is None:
    _fail(failures, "as_of_timezone_required", "$.observed_at", "as_of must include a timezone")
  if type(max_age_seconds) is not int or max_age_seconds <= 0:
    _fail(failures, "max_age_required", "$.observed_at", "explicit positive max_age_seconds is required")
  if observed is not None and as_of is not None and type(max_age_seconds) is int and max_age_seconds > 0:
    now = as_of.astimezone(timezone.utc)
    age = (now - observed).total_seconds()
    if age < -300:
      _fail(failures, "future_evidence", "$.observed_at", "evidence is in the future")
    elif age > max_age_seconds:
      _fail(failures, "stale_evidence", "$.observed_at", "evidence exceeds max age")
  _hash_field(root.get("artifact_sha256"), "$.artifact_sha256", failures)
  artifact_root: Path | None = None
  if evidence_root is not None:
    root_candidate = Path(evidence_root)
    if not root_candidate.is_absolute():
      _fail(failures, "evidence_root_absolute", "$.artifact_path", "evidence root must be absolute")
    else:
      artifact_root = root_candidate.resolve()
  artifact = _safe_reference(root.get("artifact_path"), "$.artifact_path", artifact_root, failures)
  artifact_bytes: bytes | None = None
  if artifact is not None and isinstance(root.get("artifact_sha256"), str) and SHA256_RE.fullmatch(root["artifact_sha256"]):
    try:
      artifact_bytes, artifact_hash = _read_stable_bytes(artifact)
      if artifact_hash != root["artifact_sha256"]:
        _fail(failures, "artifact_hash_mismatch", "$.artifact_sha256", "does not match artifact bytes")
    except OSError:
      _fail(failures, "artifact_read_failed", "$.artifact_path", "artifact could not be read as one stable snapshot")
  signer = _obj(root.get("signer"), "$.signer", {"role", "key_id", "public_key_fingerprint"}, failures)
  role = signer.get("role")
  if not isinstance(role, str) or artifact_type in ARTIFACT_TYPES and role not in ROLE_BY_ARTIFACT[artifact_type]:
    _fail(failures, "wrong_signer_role", "$.signer.role", "signer role is not approved for artifact type")
  if not isinstance(signer.get("key_id"), str) or not KEY_ID_RE.fullmatch(signer.get("key_id", "")):
    _fail(failures, "invalid_key_id", "$.signer.key_id", "must be an opaque key identifier")
  if not isinstance(signer.get("public_key_fingerprint"), str) or not FINGERPRINT_RE.fullmatch(signer.get("public_key_fingerprint", "")):
    _fail(failures, "invalid_key_fingerprint", "$.signer.public_key_fingerprint", "must be sha256:<64 lowercase hex>")
  if "status" in document and (not isinstance(document.get("status"), str) or document.get("status") not in {"passed", "failed", "skipped", "approved"}):
    _fail(failures, "invalid_status", "$.status", "must be passed, failed, skipped, or approved")
  if "mode" in document and (not isinstance(document.get("mode"), str) or not document.get("mode")):
    _fail(failures, "invalid_mode", "$.mode", "must be a non-empty string")
  if "perspective" in document and (not isinstance(document.get("perspective"), str) or document.get("perspective") not in {"host", "user", "management"}):
    _fail(failures, "invalid_perspective", "$.perspective", "must be host, user, or management")
  statement = _obj(root.get("statement"), "$.statement", {"path", "sha256"}, failures)
  _hash_field(statement.get("sha256"), "$.statement.sha256", failures)
  statement_file = _safe_reference(statement.get("path"), "$.statement.path", artifact_root, failures)
  if statement_file is not None and isinstance(statement.get("sha256"), str) and SHA256_RE.fullmatch(statement["sha256"]):
    try:
      statement_bytes, statement_hash = _read_stable_bytes(statement_file)
      if statement_hash != statement["sha256"]:
        _fail(failures, "statement_hash_mismatch", "$.statement.sha256", "does not match rooted bytes")
      if statement_bytes != _canonical_statement(root):
        _fail(failures, "statement_payload_mismatch", "$.statement", "signed statement does not match envelope payload")
    except OSError:
      _fail(failures, "statement_read_failed", "$.statement.path", "statement could not be read")
  for name in ("signature", "bundle"):
    item = _obj(root.get(name), f"$.{name}", {"path", "sha256"}, failures)
    _hash_field(item.get("sha256"), f"$.{name}.sha256", failures)
    ref = _safe_reference(item.get("path"), f"$.{name}.path", artifact_root, failures)
    if ref is not None and isinstance(item.get("sha256"), str) and SHA256_RE.fullmatch(item["sha256"]):
      try:
        _, actual = _read_stable_bytes(ref)
        if actual != item["sha256"]:
          _fail(failures, f"{name}_hash_mismatch", f"$.{name}.sha256", "does not match rooted bytes")
      except OSError:
        _fail(failures, f"{name}_read_failed", f"$.{name}.path", "referenced file could not be read as one stable snapshot")
  if artifact is not None and artifact_bytes is not None and artifact_type in ARTIFACT_TYPES:
    _semantic_artifact(artifact, artifact_bytes, root, artifact_type, failures)
  return failures


def _run_fixed(argv: Sequence[str]) -> CommandResult:
  try:
    result = subprocess.run(list(argv), stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=30, check=False)
    return CommandResult(result.returncode, result.stdout[:1_000_000], result.stderr[:16_000])
  except (OSError, subprocess.TimeoutExpired):
    return CommandResult(1, "", "verification command failed")


def _reported_cosign_version(output: str) -> str | None:
  """Parse only a complete, recognized Cosign version line."""

  for line in output.splitlines():
    candidate = line.strip()
    for pattern in COSIGN_VERSION_LINE_PATTERNS:
      match = pattern.fullmatch(candidate)
      if match is not None:
        return match.group(1)
  return None


def _absolute_regular(path: Path, label: str, failures: list[Failure]) -> Path | None:
  if not path.is_absolute() or path.is_symlink() or not path.is_file():
    _fail(failures, "unsafe_external_path", label, "must be an absolute non-symlink regular file")
    return None
  return path


def _signature_references(
  document: Mapping[str, Any],
  evidence_root: Path | str,
  failures: list[Failure],
) -> tuple[Path | None, dict[str, tuple[Path, str]]]:
  """Recheck the closed rooted proof shape for direct signature callers."""

  if not isinstance(document, Mapping):
    _fail(failures, "invalid_type", "$", "signed evidence envelope must be an object")
    return None, {}
  fields = {
    "contract_version", "artifact_type", "artifact_path", "artifact_sha256", "git_revision", "observed_at",
    "signer", "statement", "signature", "bundle", "status", "mode", "perspective",
  }
  unknown = set(document) - fields
  for key in sorted(unknown):
    _fail(failures, "unknown_field", f"$.{key}", "field is not allowed")
  required = {
    "contract_version", "artifact_type", "artifact_path", "artifact_sha256", "git_revision", "observed_at",
    "signer", "statement", "signature", "bundle",
  }
  for key in sorted(required - set(document)):
    _fail(failures, "missing_field", f"$.{key}", "required field is missing")
  if document.get("contract_version") != CONTRACT_VERSION:
    _fail(failures, "unsupported_contract_version", "$.contract_version", "must use 1.0.0")
  artifact_type = document.get("artifact_type")
  if artifact_type not in ARTIFACT_TYPES:
    _fail(failures, "invalid_artifact_type", "$.artifact_type", "unsupported artifact type")
  if not isinstance(document.get("git_revision"), str) or not REVISION_RE.fullmatch(document.get("git_revision", "")):
    _fail(failures, "invalid_git_revision", "$.git_revision", "must be a full lowercase 40-character revision")
  _timestamp(document.get("observed_at"), "$.observed_at", failures)
  signer = _obj(document.get("signer"), "$.signer", {"role", "key_id", "public_key_fingerprint"}, failures)
  role = signer.get("role")
  if artifact_type in ARTIFACT_TYPES and (not isinstance(role, str) or role not in ROLE_BY_ARTIFACT[artifact_type]):
    _fail(failures, "wrong_signer_role", "$.signer.role", "signer role is not approved for artifact type")
  if not isinstance(signer.get("key_id"), str) or not KEY_ID_RE.fullmatch(signer.get("key_id", "")):
    _fail(failures, "invalid_key_id", "$.signer.key_id", "must be an opaque key identifier")
  if not isinstance(signer.get("public_key_fingerprint"), str) or not FINGERPRINT_RE.fullmatch(signer.get("public_key_fingerprint", "")):
    _fail(failures, "invalid_key_fingerprint", "$.signer.public_key_fingerprint", "must be sha256:<64 lowercase hex>")
  root_candidate = Path(evidence_root)
  if not root_candidate.is_absolute():
    _fail(failures, "evidence_root_absolute", "$.artifact_path", "evidence root must be absolute")
    return None, {}
  root = root_candidate.resolve()
  _contains_secrets(document, "$", failures)
  references: dict[str, tuple[Path, str]] = {}
  artifact_sha = document.get("artifact_sha256")
  _hash_field(artifact_sha, "$.artifact_sha256", failures)
  artifact = _safe_reference(document.get("artifact_path"), "$.artifact_path", root, failures)
  if artifact is not None and isinstance(artifact_sha, str) and SHA256_RE.fullmatch(artifact_sha):
    references["artifact"] = (artifact, artifact_sha)
  for name in ("statement", "signature", "bundle"):
    item = _obj(document.get(name), f"$.{name}", {"path", "sha256"}, failures)
    expected = item.get("sha256")
    _hash_field(expected, f"$.{name}.sha256", failures)
    reference = _safe_reference(item.get("path"), f"$.{name}.path", root, failures)
    if reference is not None and isinstance(expected, str) and SHA256_RE.fullmatch(expected):
      references[name] = (reference, expected)
  statement_reference = references.get("statement")
  if statement_reference is not None:
    try:
      statement_bytes, statement_hash = _read_stable_bytes(statement_reference[0])
      if statement_hash != statement_reference[1]:
        _fail(failures, "statement_hash_mismatch", "$.statement.sha256", "does not match rooted bytes")
      if statement_bytes != _canonical_statement(document):
        _fail(failures, "statement_payload_mismatch", "$.statement", "signed statement does not match envelope payload")
    except OSError:
      _fail(failures, "statement_read_failed", "$.statement.path", "statement could not be read")
  return root, references


def _resolve_cosign_trust(
  cosign_trust: CosignTrust | None,
  cosign_binary: Path | None,
  cosign_sha256: str | None,
  cosign_version: str | None,
  failures: list[Failure],
) -> CosignTrust | None:
  if cosign_trust is not None:
    if any(value is not None for value in (cosign_binary, cosign_sha256, cosign_version)):
      _fail(failures, "invalid_cosign_trust", "$.cosign_trust", "use the closed trust object or legacy pins, not both")
      return None
    if not isinstance(cosign_trust, CosignTrust):
      _fail(failures, "invalid_cosign_trust", "$.cosign_trust", "must be a CosignTrust object")
      return None
    return cosign_trust
  if cosign_binary is None or cosign_sha256 is None or cosign_version is None:
    _fail(failures, "missing_cosign_trust", "$.cosign_trust", "closed Cosign binary, digest, and version pins are required")
    return None
  # Legacy keyword pins are adapted into the same closed object.  Their
  # authenticity remains the caller's external trust responsibility.
  return CosignTrust(cosign_binary, cosign_sha256, cosign_version)


def verify_signature(
  document: Mapping[str, Any],
  *,
  evidence_root: Path | str,
  cosign_binary: Path | None = None,
  cosign_sha256: str | None = None,
  cosign_version: str | None = None,
  cosign_trust: CosignTrust | None = None,
  public_key: Path,
  backend: SignatureVerifierBackend | None = None,
) -> list[Failure]:
  failures: list[Failure] = []
  root, references = _signature_references(document, evidence_root, failures)
  trust = _resolve_cosign_trust(cosign_trust, cosign_binary, cosign_sha256, cosign_version, failures)
  binary = _absolute_regular(trust.binary, "$.cosign_binary", failures) if trust is not None else None
  key = _absolute_regular(public_key, "$.public_key", failures)
  key_expected_hash: str | None = None
  if binary is not None:
    if binary.name not in {"cosign", "cosign-linux-amd64", "cosign-darwin-amd64", "cosign-darwin-arm64"}:
      _fail(failures, "not_cosign_binary", "$.cosign_binary", "binary name must be cosign")
    if not isinstance(trust.sha256, str) or not SHA256_RE.fullmatch(trust.sha256) or _sha256_file(binary) != trust.sha256:
      _fail(failures, "cosign_hash_mismatch", "$.cosign_sha256", "does not match cosign bytes")
    if not isinstance(trust.version, str) or not trust.version.strip():
      _fail(failures, "invalid_cosign_version", "$.cosign_version", "expected version is required")
    else:
      version_result = _run_fixed([str(binary), "version"])
      reported = _reported_cosign_version(version_result.stdout + version_result.stderr)
      if version_result.returncode != 0 or reported != trust.version:
        _fail(failures, "cosign_version_mismatch", "$.cosign_version", "cosign version output differs")
  if key is not None:
    if "private" in key.name.lower() or PRIVATE_KEY_TEXT_RE.search(key.read_text(encoding="utf-8", errors="ignore")):
      _fail(failures, "private_key_rejected", "$.public_key", "private key material is forbidden")
    fingerprint = "sha256:" + _sha256_file(key)
    signer = document.get("signer") if isinstance(document.get("signer"), dict) else {}
    if signer.get("public_key_fingerprint") != fingerprint:
      _fail(failures, "public_key_fingerprint_mismatch", "$.signer.public_key_fingerprint", "does not match public key bytes")
    elif isinstance(signer.get("public_key_fingerprint"), str):
      key_expected_hash = signer["public_key_fingerprint"].removeprefix("sha256:")
  if failures:
    return failures
  if root is None or trust is None or binary is None or key is None:
    return failures
  before: dict[str, str] = {}
  if key_expected_hash is None:
    _fail(failures, "invalid_key_fingerprint", "$.signer.public_key_fingerprint", "a trusted public key fingerprint is required")
    return failures
  for label, (path, expected) in {**references, "cosign": (binary, trust.sha256), "public_key": (key, key_expected_hash)}.items():
    try:
      actual = _sha256_file(path)
      expected_hash = expected.removeprefix("sha256:")
      if actual != expected_hash:
        _fail(failures, f"{label}_hash_mismatch", f"$.{label}_sha256", "bytes changed before signature verification")
      before[label] = actual
    except OSError:
      _fail(failures, f"{label}_read_failed", f"$.{label}_path", "file could not be reread safely")
  if failures:
    return failures
  statement = references["statement"][0]
  signature = references["signature"][0]
  bundle = references["bundle"][0]
  argv = [str(binary), "verify-blob", "--key", str(key), "--signature", str(signature), "--bundle", str(bundle), str(statement)]
  result = (backend or CosignBackend()).verify(argv)
  if result.returncode != 0:
    _fail(failures, "signature_verification_failed", "$.signature", "Cosign verify-blob rejected the artifact")
  for label, (path, _expected) in {**references, "cosign": (binary, trust.sha256), "public_key": (key, "")}.items():
    try:
      actual = _sha256_file(path)
      if actual != before.get(label):
        _fail(failures, f"{label}_changed_after_verification", f"$.{label}_path", "bytes changed during signature verification")
    except OSError:
      _fail(failures, f"{label}_read_failed_after_verification", f"$.{label}_path", "file could not be reread after verification")
  return failures


def verify_envelope(
  document: Any,
  *,
  evidence_root: Path | str,
  as_of: datetime,
  max_age_seconds: int,
  cosign_binary: Path | None = None,
  cosign_sha256: str | None = None,
  cosign_version: str | None = None,
  cosign_trust: CosignTrust | None = None,
  public_key: Path,
  backend: SignatureVerifierBackend | None = None,
) -> list[Failure]:
  failures = validate_envelope(document, evidence_root=evidence_root, as_of=as_of, max_age_seconds=max_age_seconds)
  if not failures:
    failures.extend(verify_signature(document, evidence_root=evidence_root, cosign_binary=cosign_binary, cosign_sha256=cosign_sha256, cosign_version=cosign_version, cosign_trust=cosign_trust, public_key=public_key, backend=backend))
  return failures


# Descriptive aliases kept as the public API for callers that name the contract.
validate_signed_evidence_envelope = validate_envelope
verify_signed_evidence_envelope = verify_envelope


def _parse_as_of(value: str) -> datetime:
  parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
  if parsed.tzinfo is None:
    raise ValueError("as_of must include a timezone")
  return parsed.astimezone(timezone.utc)


def main(argv: Sequence[str] | None = None) -> int:
  parser = argparse.ArgumentParser(description="Verify a W1.1 signed evidence envelope")
  parser.add_argument("--envelope", required=True, type=Path)
  parser.add_argument("--evidence-root", required=True, type=Path)
  parser.add_argument("--as-of", required=True, type=_parse_as_of)
  parser.add_argument("--max-age-seconds", required=True, type=int)
  parser.add_argument("--cosign-binary", required=True, type=Path)
  parser.add_argument("--cosign-sha256", required=True)
  parser.add_argument("--cosign-version", required=True)
  parser.add_argument("--public-key", required=True, type=Path)
  args = parser.parse_args(argv)
  try:
    document = load_json_no_duplicates(args.envelope)
    failures = verify_envelope(document, evidence_root=args.evidence_root, as_of=args.as_of, max_age_seconds=args.max_age_seconds, cosign_binary=args.cosign_binary, cosign_sha256=args.cosign_sha256, cosign_version=args.cosign_version, public_key=args.public_key)
  except (OSError, ValueError, DuplicateJSONKey) as error:
    failures = [Failure("input_error", "$", str(error))]
  if failures:
    for failure in failures:
      print(json.dumps(failure.to_dict(), sort_keys=True), file=sys.stderr)
    return 1
  print(json.dumps({"status": "passed", "artifact_type": document["artifact_type"]}, sort_keys=True))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
