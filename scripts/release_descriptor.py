#!/usr/bin/env python3
"""Create and independently verify the W11-SC-03 release manifest chain.

``validate`` is structural only: it validates hashes and semantic bindings but
does not claim that a signature was checked.  ``verify`` is the release gate;
it always invokes Cosign with a public verification key after the structural
checks pass.  Neither command accepts a private signing key.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence


HEX64 = re.compile(r"^[0-9a-f]{64}$")
REVISION = re.compile(r"^(?:[0-9a-f]{40}|[0-9a-f]{64})$")
IMAGE_DIGEST = re.compile(r"@(?P<digest>sha256:[0-9a-f]{64})$", re.IGNORECASE)
RELATIVE_PATH = re.compile(r"^(?!/)(?![A-Za-z]:)(?:[^/]+/)*[^/]+$")
SECRET_KEY = re.compile(r"(?:password|passwd|token|secret|credential|private[_-]?key|api[_-]?key)", re.IGNORECASE)
SECRET_VALUE = re.compile(r"(?:-----BEGIN [^-]+ PRIVATE KEY-----|(?:password|passwd|token|secret)\s*[:=]\s*[^\s]+)", re.IGNORECASE)
MANIFEST_FIELDS = {
  "manifest_version",
  "release_id",
  "git_revision",
  "images",
  "compose",
  "migration_plan",
  "rollback_plan",
}
DESCRIPTOR_FIELDS = (MANIFEST_FIELDS - {"manifest_version"}) | {"descriptor_version", "manifest", "signature", "bundle"}
SBOM_BINDING_REFERENCE = "org.third-health-cluster.release.image-reference"
SBOM_BINDING_DIGEST = "org.third-health-cluster.release.image-digest"
LICENSE_POLICY_FIELDS = {"policy_version", "default_action", "allowed_license_ids", "denied_license_ids"}
GRYPE_DB_EVIDENCE_FIELDS = {"evidence_version", "grype_version", "database_sha256", "built_at"}


class DescriptorError(ValueError):
  """A release artifact is unsafe, incomplete, or not independently verified."""


def _sha256(path: Path) -> str:
  digest = hashlib.sha256()
  try:
    with path.open("rb") as stream:
      for chunk in iter(lambda: stream.read(1024 * 1024), b""):
        digest.update(chunk)
  except OSError as error:
    raise DescriptorError(f"cannot read artifact {path}: {error}") from error
  return f"sha256:{digest.hexdigest()}"


def canonical_json(document: Any) -> str:
  return json.dumps(document, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"


def _load_json(path: Path) -> Any:
  class DuplicateKey(ValueError):
    pass

  def pairs(items: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in items:
      if key in result:
        raise DuplicateKey(f"duplicate JSON key: {key}")
      result[key] = value
    return result

  try:
    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=pairs)
  except (OSError, UnicodeError, json.JSONDecodeError, DuplicateKey) as error:
    raise DescriptorError(f"invalid JSON {path}: {error}") from error


def _walk(value: Any, path: str = "$") -> Iterable[tuple[str, Any]]:
  yield path, value
  if isinstance(value, dict):
    for key, child in value.items():
      yield from _walk(child, f"{path}.{key}")
  elif isinstance(value, list):
    for index, child in enumerate(value):
      yield from _walk(child, f"{path}[{index}]")


def _require_string(value: Any, field: str) -> str:
  if not isinstance(value, str) or not value.strip():
    raise DescriptorError(f"{field} must be a non-empty string")
  if any(character in value for character in ("\x00", "\n", "\r")):
    raise DescriptorError(f"{field} contains an unsafe control character")
  return value


def _safe_relative_path(value: Any, field: str, root: Path) -> tuple[str, Path]:
  path = _require_string(value, field)
  if not RELATIVE_PATH.fullmatch(path) or ".." in Path(path).parts:
    raise DescriptorError(f"{field} must be a safe relative path")
  candidate = (root / path).resolve()
  try:
    candidate.relative_to(root.resolve())
  except ValueError as error:
    raise DescriptorError(f"{field} resolves outside validation root") from error
  return path, candidate


def _relative_artifact(path: Path, root: Path) -> str:
  try:
    relative = path.resolve().relative_to(root.resolve())
  except ValueError as error:
    raise DescriptorError(f"artifact must be beneath validation root: {path}") from error
  value = relative.as_posix()
  _safe_relative_path(value, "artifact", root)
  return value


def _validate_digest(value: Any, field: str) -> str:
  digest = _require_string(value, field).lower()
  if not digest.startswith("sha256:") or HEX64.fullmatch(digest[7:]) is None:
    raise DescriptorError(f"{field} must be sha256:<64 lowercase hex characters>")
  return digest


def _validate_artifact(artifact: Any, field: str, root: Path, *, required: set[str]) -> tuple[dict[str, Any], Path]:
  if not isinstance(artifact, dict) or set(artifact) != required:
    raise DescriptorError(f"{field} must contain exactly {sorted(required)}")
  _, path = _safe_relative_path(artifact.get("path"), f"{field}.path", root)
  expected = _validate_digest(artifact.get("sha256"), f"{field}.sha256")
  if _sha256(path) != expected:
    raise DescriptorError(f"{field} hash does not match its artifact")
  return artifact, path


def _validate_image_reference(value: Any, field: str) -> tuple[str, str]:
  reference = _require_string(value, field)
  if any(character in reference for character in ("?", "#", "\\", " ", "\t")) or reference.count("@") != 1:
    raise DescriptorError(f"{field} is not a safe immutable OCI reference")
  match = IMAGE_DIGEST.search(reference)
  if match is None:
    raise DescriptorError(f"{field} must end in a sha256 digest")
  image_name = reference.rsplit("@", 1)[0]
  if not image_name or "://" in image_name:
    raise DescriptorError(f"{field} must not contain credentials, a scheme, or a mutable tag")
  parts = image_name.split("/")
  if any(not part for part in parts) or any("@" in part or ":" in part for part in parts[1:]):
    raise DescriptorError(f"{field} must not contain credentials, a scheme, or a mutable tag")
  registry = parts[0]
  if registry.count(":") > 1 or (":" in registry and not registry.rsplit(":", 1)[1].isdigit()):
    raise DescriptorError(f"{field} must not contain credentials, a scheme, or a mutable tag")
  if not all(re.fullmatch(r"[a-z0-9][a-z0-9._-]*", part) for part in [registry.split(":", 1)[0], *parts[1:]]):
    raise DescriptorError(f"{field} contains an invalid OCI image path component")
  return reference, match.group("digest").lower()


def _sbom_format(document: Any) -> str:
  if isinstance(document, dict) and isinstance(document.get("bomFormat"), str) and document["bomFormat"].lower() == "cyclonedx":
    return "cyclonedx"
  if isinstance(document, dict) and isinstance(document.get("spdxVersion"), str):
    return "spdx"
  raise DescriptorError("SBOM is neither CycloneDX nor SPDX")


def _properties(values: Any, field: str) -> dict[str, str]:
  if not isinstance(values, list):
    raise DescriptorError(f"{field} must be a list")
  result: dict[str, str] = {}
  for index, item in enumerate(values):
    if not isinstance(item, dict) or set(item) != {"name", "value"}:
      raise DescriptorError(f"{field}[{index}] must contain exactly name and value")
    name = _require_string(item["name"], f"{field}[{index}].name")
    value = _require_string(item["value"], f"{field}[{index}].value")
    if name in result:
      raise DescriptorError(f"{field} contains a duplicate property")
    result[name] = value
  return result


def _has_nonempty_component_graph(components: Any, field: str) -> bool:
  if not isinstance(components, list) or not components:
    raise DescriptorError(f"{field} must contain a non-empty component/package graph")
  for component in components:
    if not isinstance(component, dict):
      raise DescriptorError(f"{field} contains a non-object component")
    if isinstance(component.get("name"), str) and component["name"].strip():
      return True
  raise DescriptorError(f"{field} must contain a named component/package")


def _validate_cyclonedx_subject(document: Any, reference: str, digest: str) -> None:
  if not isinstance(document, dict):
    raise DescriptorError("CycloneDX SBOM must be an object")
  metadata = document.get("metadata")
  if not isinstance(metadata, dict) or not isinstance(metadata.get("component"), dict):
    raise DescriptorError("CycloneDX SBOM is missing its designated metadata.component subject")
  subject = metadata["component"]
  if subject.get("type") != "container" or subject.get("bom-ref") != reference:
    raise DescriptorError("CycloneDX metadata.component does not identify the immutable container subject")
  if subject.get("name") != reference.rsplit("@", 1)[0] or subject.get("version") != digest:
    raise DescriptorError("CycloneDX metadata.component image name or digest does not match")
  properties = _properties(subject.get("properties"), "CycloneDX metadata.component.properties")
  if properties.get(SBOM_BINDING_REFERENCE) != reference or properties.get(SBOM_BINDING_DIGEST) != digest:
    raise DescriptorError("CycloneDX designated image-binding properties do not match the immutable image")
  _has_nonempty_component_graph(document.get("components"), "CycloneDX components")


def _validate_spdx_subject(document: Any, reference: str, digest: str) -> None:
  if not isinstance(document, dict) or not isinstance(document.get("packages"), list):
    raise DescriptorError("SPDX SBOM must contain packages")
  packages = document["packages"]
  _has_nonempty_component_graph(packages, "SPDX packages")
  subjects = [
    package for package in packages
    if isinstance(package, dict)
    and package.get("primaryPackagePurpose") == "CONTAINER"
    and package.get("name") == reference.rsplit("@", 1)[0]
    and package.get("versionInfo") == digest
    and isinstance(package.get("SPDXID"), str)
  ]
  if len(subjects) != 1:
    raise DescriptorError("SPDX SBOM must contain exactly one immutable container subject package")
  subject = subjects[0]
  external_refs = subject.get("externalRefs")
  if not isinstance(external_refs, list) or not any(
    isinstance(item, dict)
    and item.get("referenceCategory") == "OTHER"
    and item.get("referenceType") == "third-health-cluster-image-reference"
    and item.get("referenceLocator") == reference
    for item in external_refs
  ):
    raise DescriptorError("SPDX container subject is missing the designated immutable image reference")
  described = document.get("documentDescribes")
  if not isinstance(described, list) or subject["SPDXID"] not in described:
    raise DescriptorError("SPDX documentDescribes does not bind the immutable container subject")
  if not any(isinstance(package, dict) and package is not subject and isinstance(package.get("name"), str) and package["name"].strip() for package in packages):
    raise DescriptorError("SPDX SBOM must contain a package in addition to the container subject")


def _validate_sbom(artifact: Any, field: str, root: Path, expected_reference: str, expected_digest: str) -> None:
  value, path = _validate_artifact(artifact, field, root, required={"path", "sha256", "format", "image_reference", "image_digest"})
  if value.get("format") not in {"cyclonedx", "spdx"}:
    raise DescriptorError(f"{field}.format must be cyclonedx or spdx")
  if value.get("image_reference") != expected_reference:
    raise DescriptorError(f"{field}.image_reference does not match image reference")
  if _validate_digest(value.get("image_digest"), f"{field}.image_digest") != expected_digest:
    raise DescriptorError(f"{field}.image_digest does not match image digest")
  document = _load_json(path)
  if _sbom_format(document) != value["format"]:
    raise DescriptorError(f"{field}.format does not match SBOM content")
  if value["format"] == "cyclonedx":
    _validate_cyclonedx_subject(document, expected_reference, expected_digest)
  else:
    _validate_spdx_subject(document, expected_reference, expected_digest)


def _validate_provenance(artifact: Any, field: str, root: Path, expected_digest: str, revision: str, reference: str) -> None:
  value, path = _validate_artifact(artifact, field, root, required={"path", "sha256", "image_digest"})
  if _validate_digest(value.get("image_digest"), f"{field}.image_digest") != expected_digest:
    raise DescriptorError(f"{field}.image_digest does not match image digest")
  document = _load_json(path)
  if not isinstance(document, dict) or document.get("_type") != "https://in-toto.io/Statement/v1" or document.get("predicateType") != "https://slsa.dev/provenance/v1":
    raise DescriptorError(f"{field} must be an in-toto SLSA provenance statement")
  subjects = document.get("subject")
  if not isinstance(subjects, list) or not any(
    isinstance(subject, dict)
    and isinstance(subject.get("digest"), dict)
    and subject["digest"].get("sha256") == expected_digest[7:]
    and subject.get("name") == reference.rsplit("@", 1)[0]
    for subject in subjects
  ):
    raise DescriptorError(f"{field} subject does not bind the image digest")
  predicate = document.get("predicate")
  if not isinstance(predicate, dict) or not isinstance(predicate.get("buildDefinition"), dict):
    raise DescriptorError(f"{field} is missing buildDefinition")
  build_definition = predicate["buildDefinition"]
  parameters = build_definition.get("externalParameters")
  dependencies = build_definition.get("resolvedDependencies")
  if not isinstance(parameters, dict) or parameters.get("gitRevision", "").lower() != revision:
    raise DescriptorError(f"{field} git revision does not match the release")
  if not isinstance(dependencies, list) or reference not in dependencies:
    raise DescriptorError(f"{field} dependencies do not contain the immutable image reference")


def _validate_plan(artifact: Any, field: str, root: Path, revision: str, expected: Mapping[str, Any]) -> None:
  _, path = _validate_artifact(artifact, field, root, required={"path", "sha256"})
  document = _load_json(path)
  if not isinstance(document, dict) or document != expected:
    raise DescriptorError(f"{field} does not match the approved fail-closed release plan")


def _validate_no_secrets(document: Any) -> None:
  for path, value in _walk(document):
    if isinstance(value, str) and SECRET_VALUE.search(value):
      raise DescriptorError(f"secret-like value found at {path}")
    if isinstance(value, dict):
      for key in value:
        if SECRET_KEY.search(str(key)) and key not in {"image_digest", "subject_digest"}:
          raise DescriptorError(f"secret-like field found at {path}.{key}")


def _validate_images(images: Any, root: Path, revision: str) -> None:
  if not isinstance(images, dict) or set(images) != {"api", "web"}:
    raise DescriptorError("images must contain exactly api and web")
  for name in ("api", "web"):
    image = images[name]
    if not isinstance(image, dict) or set(image) != {"reference", "digest", "sbom", "provenance"}:
      raise DescriptorError(f"images.{name} must contain reference, digest, sbom, and provenance")
    reference, reference_digest = _validate_image_reference(image.get("reference"), f"images.{name}.reference")
    digest = _validate_digest(image.get("digest"), f"images.{name}.digest")
    if digest != reference_digest:
      raise DescriptorError(f"images.{name}.digest does not match image reference")
    _validate_sbom(image["sbom"], f"images.{name}.sbom", root, reference, digest)
    _validate_provenance(image["provenance"], f"images.{name}.provenance", root, digest, revision, reference)


def validate_manifest(document: Any, root: Path) -> None:
  if not isinstance(document, dict) or set(document) != MANIFEST_FIELDS:
    raise DescriptorError(f"manifest must contain exactly {sorted(MANIFEST_FIELDS)}")
  if document.get("manifest_version") != "1":
    raise DescriptorError("manifest_version must be '1'")
  _require_string(document.get("release_id"), "release_id")
  revision = _require_string(document.get("git_revision"), "git_revision").lower()
  if REVISION.fullmatch(revision) is None:
    raise DescriptorError("git_revision must be a 40- or 64-character hexadecimal revision")
  _validate_images(document.get("images"), root, revision)
  _validate_artifact(document.get("compose"), "compose", root, required={"path", "sha256"})
  migration = {
    "plan_version": "1",
    "git_revision": revision,
    "strategy": "forward-only",
    "destructive": False,
    "requires_pre_backup": True,
  }
  rollback = {
    "plan_version": "1",
    "git_revision": revision,
    "strategy": "redeploy-known-good-digest",
    "allows_destructive_down_migration": False,
  }
  _validate_plan(document.get("migration_plan"), "migration_plan", root, revision, migration)
  _validate_plan(document.get("rollback_plan"), "rollback_plan", root, revision, rollback)
  _validate_no_secrets(document)


def _manifest_payload(descriptor: Mapping[str, Any]) -> dict[str, Any]:
  return {
    "manifest_version": "1",
    "release_id": descriptor["release_id"],
    "git_revision": descriptor["git_revision"],
    "images": descriptor["images"],
    "compose": descriptor["compose"],
    "migration_plan": descriptor["migration_plan"],
    "rollback_plan": descriptor["rollback_plan"],
  }


def validate_descriptor(document: Any, root: Path) -> None:
  """Validate structural and semantic bindings; use ``verify_descriptor`` for Cosign."""
  if not isinstance(document, dict) or set(document) != DESCRIPTOR_FIELDS:
    raise DescriptorError(f"descriptor must contain exactly {sorted(DESCRIPTOR_FIELDS)}")
  if document.get("descriptor_version") != "2":
    raise DescriptorError("descriptor_version must be '2'")
  payload = _manifest_payload(document)
  validate_manifest(payload, root)
  manifest, manifest_path = _validate_artifact(
    document.get("manifest"),
    "manifest",
    root,
    required={"path", "sha256", "format"},
  )
  if manifest.get("format") != "release-manifest-v1":
    raise DescriptorError("manifest.format must be release-manifest-v1")
  manifest_document = _load_json(manifest_path)
  validate_manifest(manifest_document, root)
  if canonical_json(manifest_document) != canonical_json(payload):
    raise DescriptorError("signed manifest does not exactly match the release descriptor payload")
  subject_digest = _validate_digest(manifest["sha256"], "manifest.sha256")
  for name, expected_format in (("signature", "cosign-blob-signature"), ("bundle", "cosign-bundle")):
    artifact, _ = _validate_artifact(
      document.get(name),
      name,
      root,
      required={"path", "sha256", "format", "subject_sha256"},
    )
    if artifact.get("format") != expected_format:
      raise DescriptorError(f"{name}.format is invalid")
    if _validate_digest(artifact.get("subject_sha256"), f"{name}.subject_sha256") != subject_digest:
      raise DescriptorError(f"{name}.subject_sha256 does not bind the signed manifest")
  _validate_no_secrets(document)


def _cosign_version(binary: Path) -> str:
  try:
    result = subprocess.run([str(binary), "version"], stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=15, check=False)
  except (OSError, subprocess.TimeoutExpired) as error:
    raise DescriptorError("Cosign version could not be executed") from error
  if result.returncode != 0:
    raise DescriptorError("Cosign version command failed")
  match = re.search(r"^GitVersion:\s*(\S+)\s*$", result.stdout, flags=re.MULTILINE)
  if match is None:
    raise DescriptorError("Cosign version output does not contain an exact GitVersion")
  return match.group(1)


def verify_descriptor(
  document: Any,
  root: Path,
  *,
  cosign_binary: Path,
  cosign_sha256: str,
  public_key: Path,
  public_key_fingerprint: str,
  cosign_version: str,
) -> None:
  """Run independent Cosign verification without accepting a private key."""
  validate_descriptor(document, root)
  if not public_key.is_absolute() or public_key.is_symlink() or not public_key.is_file():
    raise DescriptorError("Cosign public key must be an absolute non-symlink readable file")
  if "PRIVATE KEY" in public_key.read_text(encoding="utf-8", errors="ignore").upper():
    raise DescriptorError("Cosign public key path contains private key material")
  if not cosign_binary.is_absolute() or cosign_binary.is_symlink() or not cosign_binary.is_file() or not cosign_binary.stat().st_mode & 0o111:
    raise DescriptorError("Cosign binary must be an absolute non-symlink executable file")
  if not isinstance(cosign_sha256, str) or HEX64.fullmatch(cosign_sha256) is None or _sha256(cosign_binary) != f"sha256:{cosign_sha256}":
    raise DescriptorError("Cosign binary does not match the pinned SHA-256")
  if not isinstance(public_key_fingerprint, str) or re.fullmatch(r"sha256:[0-9a-f]{64}", public_key_fingerprint) is None or _sha256(public_key) != public_key_fingerprint:
    raise DescriptorError("Cosign public key does not match the pinned fingerprint")
  external_bindings = {cosign_binary: f"sha256:{cosign_sha256}", public_key: public_key_fingerprint}

  def assert_bindings() -> None:
    for path, expected in external_bindings.items():
      if path.is_symlink() or not path.is_file() or _sha256(path) != expected:
        raise DescriptorError("Cosign verifier configuration changed during verification")

  if _cosign_version(cosign_binary) != _require_string(cosign_version, "cosign_version"):
    raise DescriptorError("Cosign version does not match the pinned verification version")
  assert_bindings()
  manifest_path = (root / document["manifest"]["path"]).resolve()
  signature_path = (root / document["signature"]["path"]).resolve()
  bundle_path = (root / document["bundle"]["path"]).resolve()
  signed_artifacts = {
    manifest_path: document["manifest"]["sha256"],
    signature_path: document["signature"]["sha256"],
    bundle_path: document["bundle"]["sha256"],
  }

  def assert_signed_artifacts() -> None:
    for path, expected in signed_artifacts.items():
      if _sha256(path) != expected:
        raise DescriptorError("signed release artifact changed during verification")

  assert_signed_artifacts()
  commands: list[list[str]] = [
    [
      str(cosign_binary),
      "verify-blob",
      "--key",
      str(public_key),
      "--bundle",
      str(bundle_path),
      "--signature",
      str(signature_path),
      str(manifest_path),
    ]
  ]
  for name in ("api", "web"):
    commands.append(
      [
        str(cosign_binary),
        "verify",
        "--key",
        str(public_key),
        "--insecure-ignore-tlog=true",
        document["images"][name]["reference"],
      ]
    )
  for command in commands:
    try:
      result = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
      raise DescriptorError("Cosign verification could not be executed") from error
    if result.returncode != 0:
      raise DescriptorError("Cosign verification failed for a signed release artifact")
    assert_bindings()
    assert_signed_artifacts()


def _artifact(path: Path, root: Path, **extra: Any) -> dict[str, Any]:
  return {"path": _relative_artifact(path, root), "sha256": _sha256(path), **extra}


def _image(reference: str, sbom_path: Path, provenance_path: Path, root: Path) -> dict[str, Any]:
  normalized_reference, digest = _validate_image_reference(reference, "image reference")
  return {
    "reference": normalized_reference,
    "digest": digest,
    "sbom": _artifact(sbom_path, root, format=_sbom_format(_load_json(sbom_path)), image_reference=normalized_reference, image_digest=digest),
    "provenance": _artifact(provenance_path, root, image_digest=digest),
  }


def generate_manifest(args: argparse.Namespace) -> dict[str, Any]:
  root = Path(args.root).resolve()
  revision = _require_string(args.git_revision, "git_revision").lower()
  if REVISION.fullmatch(revision) is None:
    raise DescriptorError("git_revision must be a 40- or 64-character hexadecimal revision")
  document = {
    "manifest_version": "1",
    "release_id": _require_string(args.release_id, "release_id"),
    "git_revision": revision,
    "images": {
      "api": _image(args.api_image, (root / args.api_sbom).resolve(), (root / args.api_provenance).resolve(), root),
      "web": _image(args.web_image, (root / args.web_sbom).resolve(), (root / args.web_provenance).resolve(), root),
    },
    "compose": _artifact((root / args.compose).resolve(), root),
    "migration_plan": _artifact((root / args.migration_plan).resolve(), root),
    "rollback_plan": _artifact((root / args.rollback_plan).resolve(), root),
  }
  validate_manifest(document, root)
  return document


def generate_descriptor(args: argparse.Namespace) -> dict[str, Any]:
  root = Path(args.root).resolve()
  manifest_path = (root / args.manifest).resolve()
  manifest = _load_json(manifest_path)
  validate_manifest(manifest, root)
  document = {
    "descriptor_version": "2",
    "release_id": manifest["release_id"],
    "git_revision": manifest["git_revision"],
    "images": manifest["images"],
    "compose": manifest["compose"],
    "migration_plan": manifest["migration_plan"],
    "rollback_plan": manifest["rollback_plan"],
    "manifest": _artifact(manifest_path, root, format="release-manifest-v1"),
    "signature": _artifact((root / args.signature).resolve(), root, format="cosign-blob-signature", subject_sha256=_sha256(manifest_path)),
    "bundle": _artifact((root / args.bundle).resolve(), root, format="cosign-bundle", subject_sha256=_sha256(manifest_path)),
  }
  validate_descriptor(document, root)
  return document


def _license_terms(value: str, field: str) -> set[str]:
  if not value.strip() or value.strip() in {"NOASSERTION", "NONE"}:
    raise DescriptorError(f"{field} is missing governed license evidence")
  terms = set(re.findall(r"[A-Za-z0-9.+-]+", value)) - {"AND", "OR", "WITH"}
  if not terms:
    raise DescriptorError(f"{field} is not a valid governed license expression")
  return terms


def _cyclonedx_component_licenses(component: Mapping[str, Any], field: str) -> set[str]:
  choices = component.get("licenses")
  if not isinstance(choices, list) or not choices:
    raise DescriptorError(f"{field} is missing license evidence")
  result: set[str] = set()
  for index, choice in enumerate(choices):
    if not isinstance(choice, dict):
      raise DescriptorError(f"{field}[{index}] is not a license choice")
    if isinstance(choice.get("license"), dict) and isinstance(choice["license"].get("id"), str):
      result.update(_license_terms(choice["license"]["id"], f"{field}[{index}].license.id"))
    elif isinstance(choice.get("expression"), str):
      result.update(_license_terms(choice["expression"], f"{field}[{index}].expression"))
    else:
      raise DescriptorError(f"{field}[{index}] must provide an SPDX id or expression")
  return result


def _spdx_package_licenses(package: Mapping[str, Any], field: str) -> set[str]:
  values = [value for value in (package.get("licenseDeclared"), package.get("licenseConcluded")) if isinstance(value, str)]
  if not values:
    raise DescriptorError(f"{field} is missing license evidence")
  result: set[str] = set()
  for index, value in enumerate(values):
    result.update(_license_terms(value, f"{field}[{index}]"))
  return result


def _license_evidence(document: Any, image_reference: str) -> dict[str, set[str]]:
  bom_format = _sbom_format(document)
  evidence: dict[str, set[str]] = {}
  if bom_format == "cyclonedx":
    components = document.get("components") if isinstance(document, dict) else None
    if not isinstance(components, list):
      raise DescriptorError("CycloneDX components are required for license policy evaluation")
    for index, component in enumerate(components):
      if not isinstance(component, dict) or not isinstance(component.get("name"), str) or not component["name"].strip():
        raise DescriptorError(f"CycloneDX components[{index}] is not a named package")
      label = component.get("bom-ref") if isinstance(component.get("bom-ref"), str) else component["name"]
      evidence[label] = _cyclonedx_component_licenses(component, f"CycloneDX components[{index}].licenses")
  else:
    packages = document.get("packages") if isinstance(document, dict) else None
    if not isinstance(packages, list):
      raise DescriptorError("SPDX packages are required for license policy evaluation")
    for index, package in enumerate(packages):
      if not isinstance(package, dict) or not isinstance(package.get("name"), str) or not package["name"].strip():
        raise DescriptorError(f"SPDX packages[{index}] is not a named package")
      if package.get("primaryPackagePurpose") == "CONTAINER" and package.get("name") == image_reference.rsplit("@", 1)[0]:
        continue
      label = package.get("SPDXID") if isinstance(package.get("SPDXID"), str) else package["name"]
      evidence[label] = _spdx_package_licenses(package, f"SPDX packages[{index}]")
  if not evidence:
    raise DescriptorError("SBOM has no non-container package license evidence")
  return evidence


def _load_license_policy(path: Path) -> tuple[set[str], set[str]]:
  policy = _load_json(path)
  if not isinstance(policy, dict) or set(policy) != LICENSE_POLICY_FIELDS:
    raise DescriptorError(f"license policy must contain exactly {sorted(LICENSE_POLICY_FIELDS)}")
  if policy.get("policy_version") != "1" or policy.get("default_action") != "deny":
    raise DescriptorError("license policy must be version 1 with a deny-by-default action")
  allowed = policy.get("allowed_license_ids")
  denied = policy.get("denied_license_ids")
  if not isinstance(allowed, list) or not allowed or not all(isinstance(item, str) and item.strip() for item in allowed):
    raise DescriptorError("license policy must provide a non-empty allowed_license_ids list")
  if not isinstance(denied, list) or not all(isinstance(item, str) and item.strip() for item in denied):
    raise DescriptorError("license policy denied_license_ids must be a string list")
  allowed_set, denied_set = set(allowed), set(denied)
  if len(allowed_set) != len(allowed) or len(denied_set) != len(denied) or allowed_set & denied_set:
    raise DescriptorError("license policy license identifiers must be unique and non-overlapping")
  return allowed_set, denied_set


def scan_sbom(path: Path, expected_reference: str, expected_digest: str, policy_path: Path) -> None:
  reference, digest = _validate_image_reference(expected_reference, "image_reference")
  if _validate_digest(expected_digest, "image_digest") != digest:
    raise DescriptorError("image_digest does not match immutable image_reference")
  document = _load_json(path)
  if _sbom_format(document) == "cyclonedx":
    _validate_cyclonedx_subject(document, reference, digest)
  else:
    _validate_spdx_subject(document, reference, digest)
  allowed, denied = _load_license_policy(policy_path)
  evidence = _license_evidence(document, reference)
  for component, licenses in evidence.items():
    blocked = sorted(licenses & denied)
    unknown = sorted(licenses - allowed)
    if blocked:
      raise DescriptorError(f"SBOM component {component} contains denied license identifiers: {', '.join(blocked)}")
    if unknown:
      raise DescriptorError(f"SBOM component {component} contains unapproved license identifiers: {', '.join(unknown)}")


def bind_sbom(path: Path, image_reference: str) -> None:
  reference, digest = _validate_image_reference(image_reference, "image_reference")
  document = _load_json(path)
  bom_format = _sbom_format(document)
  if bom_format == "cyclonedx":
    if not isinstance(document, dict) or not isinstance(document.get("metadata"), dict):
      raise DescriptorError("CycloneDX SBOM must contain metadata before subject binding")
    if not isinstance(document.get("components"), list) or not document["components"]:
      raise DescriptorError("CycloneDX SBOM must contain components before subject binding")
    document["metadata"]["component"] = {
      "type": "container",
      "bom-ref": reference,
      "name": reference.rsplit("@", 1)[0],
      "version": digest,
      "properties": [
        {"name": SBOM_BINDING_REFERENCE, "value": reference},
        {"name": SBOM_BINDING_DIGEST, "value": digest},
      ],
    }
    _validate_cyclonedx_subject(document, reference, digest)
  else:
    if not isinstance(document, dict) or not isinstance(document.get("packages"), list):
      raise DescriptorError("SPDX SBOM must contain packages before subject binding")
    subject_id = "SPDXRef-ThirdHealthClusterReleaseImage"
    packages = [package for package in document["packages"] if not (isinstance(package, dict) and package.get("SPDXID") == subject_id)]
    if not packages:
      raise DescriptorError("SPDX SBOM must contain a package before subject binding")
    packages.append({
      "SPDXID": subject_id,
      "name": reference.rsplit("@", 1)[0],
      "versionInfo": digest,
      "primaryPackagePurpose": "CONTAINER",
      "externalRefs": [{"referenceCategory": "OTHER", "referenceType": "third-health-cluster-image-reference", "referenceLocator": reference}],
    })
    document["packages"] = packages
    described = document.get("documentDescribes")
    if not isinstance(described, list):
      described = []
    document["documentDescribes"] = [item for item in described if item != subject_id] + [subject_id]
    _validate_spdx_subject(document, reference, digest)
  _write_json(path, document)


def _parse_utc_timestamp(value: Any, field: str) -> datetime:
  text = _require_string(value, field)
  try:
    result = datetime.fromisoformat(text.replace("Z", "+00:00"))
  except ValueError as error:
    raise DescriptorError(f"{field} must be an RFC 3339 timestamp") from error
  if result.tzinfo is None:
    raise DescriptorError(f"{field} must include a timezone")
  return result.astimezone(timezone.utc)


def verify_grype_db(evidence_path: Path, database_path: Path, expected_version: str, expected_digest: str, expected_built_at: str, max_age_hours: int, *, now: datetime | None = None) -> None:
  evidence = _load_json(evidence_path)
  if not isinstance(evidence, dict) or set(evidence) != GRYPE_DB_EVIDENCE_FIELDS:
    raise DescriptorError(f"Grype DB evidence must contain exactly {sorted(GRYPE_DB_EVIDENCE_FIELDS)}")
  if evidence.get("evidence_version") != "1" or evidence.get("grype_version") != expected_version:
    raise DescriptorError("Grype DB evidence version does not match the pinned Grype version")
  digest = _validate_digest(evidence.get("database_sha256"), "Grype DB evidence.database_sha256")
  if digest != _validate_digest(expected_digest, "expected Grype DB sha256") or digest != _sha256(database_path):
    raise DescriptorError("Grype DB evidence digest does not match the pinned database archive")
  built_at = _parse_utc_timestamp(evidence.get("built_at"), "Grype DB evidence.built_at")
  if evidence.get("built_at") != expected_built_at:
    raise DescriptorError("Grype DB evidence timestamp does not match the pinned timestamp")
  if type(max_age_hours) is not int or max_age_hours <= 0:
    raise DescriptorError("Grype DB maximum age must be a positive integer")
  observed_now = now or datetime.now(timezone.utc)
  if built_at > observed_now or (observed_now - built_at).total_seconds() > max_age_hours * 3600:
    raise DescriptorError("Grype DB evidence is stale or from the future")


def _parser() -> argparse.ArgumentParser:
  parser = argparse.ArgumentParser(description=__doc__)
  sub = parser.add_subparsers(dest="command", required=True)
  generate_manifest_parser = sub.add_parser("generate-manifest", help="generate a deterministic unsigned release manifest")
  generate_manifest_parser.add_argument("--root", default=".")
  generate_manifest_parser.add_argument("--output", required=True)
  generate_manifest_parser.add_argument("--release-id", required=True)
  generate_manifest_parser.add_argument("--git-revision", required=True)
  generate_manifest_parser.add_argument("--api-image", required=True)
  generate_manifest_parser.add_argument("--web-image", required=True)
  for name in ("api", "web"):
    generate_manifest_parser.add_argument(f"--{name}-sbom", required=True)
    generate_manifest_parser.add_argument(f"--{name}-provenance", required=True)
  generate_manifest_parser.add_argument("--compose", required=True)
  generate_manifest_parser.add_argument("--migration-plan", required=True)
  generate_manifest_parser.add_argument("--rollback-plan", required=True)
  generate_descriptor_parser = sub.add_parser("generate-descriptor", help="bind signed manifest artifacts into a descriptor")
  generate_descriptor_parser.add_argument("--root", default=".")
  generate_descriptor_parser.add_argument("--output", required=True)
  generate_descriptor_parser.add_argument("--manifest", required=True)
  generate_descriptor_parser.add_argument("--signature", required=True)
  generate_descriptor_parser.add_argument("--bundle", required=True)
  validate = sub.add_parser("validate", help="validate structural and semantic bindings without Cosign")
  validate.add_argument("descriptor")
  validate.add_argument("--root", default=".")
  verify = sub.add_parser("verify", help="validate then independently invoke Cosign with a public key")
  verify.add_argument("descriptor")
  verify.add_argument("--root", default=".")
  verify.add_argument("--cosign-binary", required=True, type=Path)
  verify.add_argument("--cosign-sha256")
  verify.add_argument("--cosign-public-key", required=True, type=Path)
  verify.add_argument("--cosign-public-key-fingerprint")
  verify.add_argument("--cosign-version", required=True)
  bind = sub.add_parser("bind-sbom", help="bind a Syft CycloneDX/SPDX SBOM to one immutable container subject")
  bind.add_argument("sbom", type=Path)
  bind.add_argument("--image-reference", required=True)
  scan = sub.add_parser("scan-sbom", help="enforce strict SBOM subject binding and deny-by-default license policy")
  scan.add_argument("sbom", type=Path)
  scan.add_argument("--image-reference", required=True)
  scan.add_argument("--image-digest", required=True)
  scan.add_argument("--license-policy", required=True, type=Path)
  grype = sub.add_parser("verify-grype-db", help="verify pinned Grype DB evidence, hash, version, and freshness")
  grype.add_argument("--evidence", required=True, type=Path)
  grype.add_argument("--database", required=True, type=Path)
  grype.add_argument("--grype-version", required=True)
  grype.add_argument("--database-sha256", required=True)
  grype.add_argument("--built-at", required=True)
  grype.add_argument("--max-age-hours", required=True, type=int)
  canonical = sub.add_parser("canonicalize", help="rewrite a descriptor using canonical JSON")
  canonical.add_argument("descriptor")
  canonical.add_argument("--output")
  return parser


def _write_json(path: Path, document: Mapping[str, Any]) -> None:
  path.parent.mkdir(parents=True, exist_ok=True)
  path.write_text(canonical_json(document), encoding="utf-8")


def main(argv: Sequence[str] | None = None) -> int:
  args = _parser().parse_args(argv)
  try:
    if args.command == "generate-manifest":
      _write_json(Path(args.output), generate_manifest(args))
      print("PASS: generated deterministic unsigned release manifest")
      return 0
    if args.command == "generate-descriptor":
      _write_json(Path(args.output), generate_descriptor(args))
      print("PASS: generated descriptor structurally bound to signed-manifest artifacts")
      return 0
    if args.command == "validate":
      validate_descriptor(_load_json(Path(args.descriptor)), Path(args.root))
      print("PASS: release descriptor is structurally and semantically valid; Cosign was not invoked")
      return 0
    if args.command == "verify":
      verify_descriptor(
        _load_json(Path(args.descriptor)),
        Path(args.root),
        cosign_binary=args.cosign_binary,
        cosign_sha256=args.cosign_sha256 or _sha256(args.cosign_binary).removeprefix("sha256:"),
        public_key=args.cosign_public_key,
        public_key_fingerprint=args.cosign_public_key_fingerprint or _sha256(args.cosign_public_key),
        cosign_version=args.cosign_version,
      )
      print("PASS: release descriptor and its signed artifacts were independently verified with Cosign")
      return 0
    if args.command == "bind-sbom":
      bind_sbom(args.sbom, args.image_reference)
      print("PASS: SBOM is bound to one immutable container subject")
      return 0
    if args.command == "scan-sbom":
      scan_sbom(args.sbom, args.image_reference, _validate_digest(args.image_digest, "image_digest"), args.license_policy)
      print("PASS: SBOM semantic binding and deny-by-default license policy gate passed")
      return 0
    if args.command == "verify-grype-db":
      verify_grype_db(args.evidence, args.database, args.grype_version, args.database_sha256, args.built_at, args.max_age_hours)
      print("PASS: pinned Grype DB evidence is present, current, and hash-verified")
      return 0
    document = _load_json(Path(args.descriptor))
    validate_descriptor(document, Path(args.descriptor).resolve().parent)
    _write_json(Path(args.output) if args.output else Path(args.descriptor), document)
    print("PASS: canonicalized structurally valid descriptor; Cosign was not invoked")
    return 0
  except (DescriptorError, OSError) as error:
    print(f"ERROR: {error}", file=sys.stderr)
    return 1


if __name__ == "__main__":
  raise SystemExit(main())
