from __future__ import annotations

import hashlib
import json
import shlex
from datetime import datetime, timezone
from pathlib import Path

import pytest
import scripts.release_descriptor as release_descriptor

from scripts.release_descriptor import DescriptorError, bind_sbom, canonical_json, scan_sbom, validate_descriptor, verify_descriptor, verify_grype_db


pytestmark = pytest.mark.unit
REVISION = "a" * 40
API_DIGEST = "sha256:" + "1" * 64
WEB_DIGEST = "sha256:" + "2" * 64
LICENSE_POLICY = Path(__file__).resolve().parents[3] / "infra/platform/release/license-policy.json"


def _hash(content: bytes) -> str:
  return "sha256:" + hashlib.sha256(content).hexdigest()


def _pins(binary: Path, public_key: Path) -> dict[str, str]:
  return {
    "cosign_sha256": hashlib.sha256(binary.read_bytes()).hexdigest(),
    "public_key_fingerprint": _hash(public_key.read_bytes()),
  }


def _write(root: Path, name: str, content: str) -> tuple[str, str]:
  path = root / name
  path.write_text(content, encoding="utf-8")
  return name, _hash(content.encode("utf-8"))


def _json(root: Path, name: str, value: object) -> tuple[str, str]:
  return _write(root, name, canonical_json(value))


def _artifact(path: str, digest: str, **extra: str) -> dict[str, str]:
  return {"path": path, "sha256": digest, **extra}


def _sbom(reference: str, digest: str) -> dict[str, object]:
  return {
    "bomFormat": "CycloneDX",
    "specVersion": "1.5",
    "metadata": {
      "component": {
        "type": "container",
        "bom-ref": reference,
        "name": reference.rsplit("@", 1)[0],
        "version": digest,
        "properties": [
          {"name": "org.third-health-cluster.release.image-reference", "value": reference},
          {"name": "org.third-health-cluster.release.image-digest", "value": digest},
        ],
      }
    },
    "components": [{"bom-ref": "pkg:generic/runtime@1", "type": "library", "name": "runtime", "version": "1", "licenses": [{"license": {"id": "MIT"}}]}],
  }


def _provenance(reference: str, digest: str) -> dict[str, object]:
  return {
    "_type": "https://in-toto.io/Statement/v1",
    "predicateType": "https://slsa.dev/provenance/v1",
    "subject": [{"name": reference.rsplit("@", 1)[0], "digest": {"sha256": digest[7:]}}],
    "predicate": {
      "buildDefinition": {
        "externalParameters": {"gitRevision": REVISION},
        "resolvedDependencies": [reference],
      }
    },
  }


def _payload(document: dict) -> dict:
  return {
    "manifest_version": "1",
    "release_id": document["release_id"],
    "git_revision": document["git_revision"],
    "images": document["images"],
    "compose": document["compose"],
    "migration_plan": document["migration_plan"],
    "rollback_plan": document["rollback_plan"],
  }


def _rebind_manifest(root: Path, document: dict) -> None:
  manifest_path, manifest_hash = _json(root, "release-manifest.json", _payload(document))
  document["manifest"] = _artifact(manifest_path, manifest_hash, format="release-manifest-v1")
  document["signature"]["subject_sha256"] = manifest_hash
  document["bundle"]["subject_sha256"] = manifest_hash


def descriptor(root: Path) -> dict:
  api_reference = f"registry.example/api@{API_DIGEST}"
  web_reference = f"registry.example/web@{WEB_DIGEST}"
  compose, compose_hash = _write(root, "compose.yaml", "services: {}\n")
  api_sbom, api_sbom_hash = _json(root, "api.sbom.json", _sbom(api_reference, API_DIGEST))
  web_sbom, web_sbom_hash = _json(root, "web.sbom.json", _sbom(web_reference, WEB_DIGEST))
  api_prov, api_prov_hash = _json(root, "api.provenance.json", _provenance(api_reference, API_DIGEST))
  web_prov, web_prov_hash = _json(root, "web.provenance.json", _provenance(web_reference, WEB_DIGEST))
  migration, migration_hash = _json(root, "migration-plan.json", {"plan_version": "1", "git_revision": REVISION, "strategy": "forward-only", "destructive": False, "requires_pre_backup": True})
  rollback, rollback_hash = _json(root, "rollback-plan.json", {"plan_version": "1", "git_revision": REVISION, "strategy": "redeploy-known-good-digest", "allows_destructive_down_migration": False})
  signature, signature_hash = _write(root, "release.sig", "test-signature\n")
  bundle, bundle_hash = _write(root, "release.bundle.json", "{\"bundle\":\"test\"}\n")
  document = {
    "descriptor_version": "2",
    "release_id": "r1.0.0",
    "git_revision": REVISION,
    "images": {
      "api": {"reference": api_reference, "digest": API_DIGEST, "sbom": _artifact(api_sbom, api_sbom_hash, format="cyclonedx", image_reference=api_reference, image_digest=API_DIGEST), "provenance": _artifact(api_prov, api_prov_hash, image_digest=API_DIGEST)},
      "web": {"reference": web_reference, "digest": WEB_DIGEST, "sbom": _artifact(web_sbom, web_sbom_hash, format="cyclonedx", image_reference=web_reference, image_digest=WEB_DIGEST), "provenance": _artifact(web_prov, web_prov_hash, image_digest=WEB_DIGEST)},
    },
    "compose": _artifact(compose, compose_hash),
    "migration_plan": _artifact(migration, migration_hash),
    "rollback_plan": _artifact(rollback, rollback_hash),
    "signature": _artifact(signature, signature_hash, format="cosign-blob-signature", subject_sha256="sha256:" + "0" * 64),
    "bundle": _artifact(bundle, bundle_hash, format="cosign-bundle", subject_sha256="sha256:" + "0" * 64),
  }
  _rebind_manifest(root, document)
  return document


def test_valid_descriptor_is_structurally_accepted_and_canonical_json_is_stable(tmp_path: Path):
  document = descriptor(tmp_path)
  validate_descriptor(document, tmp_path)
  assert canonical_json(document) == canonical_json(json.loads(canonical_json(document)))
  assert list(json.loads(canonical_json(document))) == [
    "bundle", "compose", "descriptor_version", "git_revision", "images", "manifest", "migration_plan", "release_id", "rollback_plan", "signature",
  ]


def test_structural_validation_cannot_be_mistaken_for_cryptographic_verification(tmp_path: Path):
  document = descriptor(tmp_path)
  with pytest.raises(TypeError, match="require_verified"):
    validate_descriptor(document, tmp_path, require_verified=True)  # type: ignore[call-arg]


def test_image_tag_credentials_and_digest_mismatch_fail_closed(tmp_path: Path):
  document = descriptor(tmp_path)
  document["images"]["api"]["reference"] = "registry.example/api:r1"
  with pytest.raises(DescriptorError, match="safe immutable OCI reference"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["images"]["api"]["reference"] = f"oci://user:password@registry.example/api@{API_DIGEST}"
  with pytest.raises(DescriptorError, match="safe immutable OCI reference"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["images"]["api"]["reference"] = f"registry.example/user:password/api@{API_DIGEST}"
  with pytest.raises(DescriptorError, match="credentials"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["images"]["api"]["reference"] = f"registry.example/api?token=x@{API_DIGEST}"
  with pytest.raises(DescriptorError, match="safe immutable OCI reference"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["images"]["web"]["digest"] = API_DIGEST
  with pytest.raises(DescriptorError, match="does not match image reference"):
    validate_descriptor(document, tmp_path)


def test_semantic_sbom_provenance_and_plan_bindings_are_checked(tmp_path: Path):
  document = descriptor(tmp_path)
  wrong_sbom = _sbom(document["images"]["api"]["reference"], WEB_DIGEST)
  path, digest = _json(tmp_path, "api.sbom.json", wrong_sbom)
  document["images"]["api"]["sbom"]["path"] = path
  document["images"]["api"]["sbom"]["sha256"] = digest
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="image name or digest does not match"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["migration_plan"]["sha256"] = "sha256:" + "f" * 64
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="hash does not match"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["images"]["web"]["provenance"]["image_digest"] = API_DIGEST
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="image_digest"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  wrong_subject = _provenance(document["images"]["api"]["reference"], API_DIGEST)
  wrong_subject["subject"][0]["digest"]["sha256"] = WEB_DIGEST[7:]
  path, digest = _json(tmp_path, "api.provenance.json", wrong_subject)
  document["images"]["api"]["provenance"]["path"] = path
  document["images"]["api"]["provenance"]["sha256"] = digest
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="subject does not bind"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  wrong_revision = _provenance(document["images"]["web"]["reference"], WEB_DIGEST)
  wrong_revision["predicate"]["buildDefinition"]["externalParameters"]["gitRevision"] = "b" * 40
  path, digest = _json(tmp_path, "web.provenance.json", wrong_revision)
  document["images"]["web"]["provenance"]["path"] = path
  document["images"]["web"]["provenance"]["sha256"] = digest
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="git revision"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  arbitrary = {"bomFormat": "CycloneDX", "metadata": {"note": API_DIGEST}, "components": [{"name": "runtime"}]}
  path, digest = _json(tmp_path, "api.sbom.json", arbitrary)
  document["images"]["api"]["sbom"]["path"] = path
  document["images"]["api"]["sbom"]["sha256"] = digest
  _rebind_manifest(tmp_path, document)
  with pytest.raises(DescriptorError, match="designated metadata.component subject"):
    validate_descriptor(document, tmp_path)


def test_license_policy_rejects_missing_unapproved_and_denied_evidence(tmp_path: Path):
  document = descriptor(tmp_path)
  sbom = _sbom(document["images"]["api"]["reference"], API_DIGEST)
  sbom["components"][0].pop("licenses")
  path, _ = _json(tmp_path, "api.sbom.json", sbom)
  with pytest.raises(DescriptorError, match="missing license evidence"):
    scan_sbom(tmp_path / path, document["images"]["api"]["reference"], API_DIGEST, LICENSE_POLICY)

  sbom = _sbom(document["images"]["api"]["reference"], API_DIGEST)
  sbom["components"][0]["licenses"] = [{"license": {"id": "LicenseRef-Unapproved"}}]
  path, _ = _json(tmp_path, "api.sbom.json", sbom)
  with pytest.raises(DescriptorError, match="unapproved license"):
    scan_sbom(tmp_path / path, document["images"]["api"]["reference"], API_DIGEST, LICENSE_POLICY)

  sbom = _sbom(document["images"]["api"]["reference"], API_DIGEST)
  sbom["components"][0]["licenses"] = [{"license": {"id": "GPL-3.0-only"}}]
  path, _ = _json(tmp_path, "api.sbom.json", sbom)
  with pytest.raises(DescriptorError, match="denied license"):
    scan_sbom(tmp_path / path, document["images"]["api"]["reference"], API_DIGEST, LICENSE_POLICY)


def test_spdx_subject_binding_requires_a_distinct_container_and_nonempty_package_graph(tmp_path: Path):
  reference = f"registry.example/api@{API_DIGEST}"
  path, _ = _json(tmp_path, "api.spdx.json", {
    "spdxVersion": "SPDX-2.3",
    "packages": [{
      "SPDXID": "SPDXRef-runtime",
      "name": "runtime",
      "versionInfo": "1",
      "licenseDeclared": "MIT",
      "licenseConcluded": "MIT",
    }],
  })
  sbom_path = tmp_path / path
  bind_sbom(sbom_path, reference)
  scan_sbom(sbom_path, reference, API_DIGEST, LICENSE_POLICY)

  document = json.loads(sbom_path.read_text(encoding="utf-8"))
  subject = next(package for package in document["packages"] if package["SPDXID"] == "SPDXRef-ThirdHealthClusterReleaseImage")
  subject["externalRefs"][0]["referenceLocator"] = f"registry.example/api@{WEB_DIGEST}"
  _json(tmp_path, path, document)
  with pytest.raises(DescriptorError, match="designated immutable image reference"):
    scan_sbom(sbom_path, reference, API_DIGEST, LICENSE_POLICY)


def test_grype_db_evidence_requires_matching_hash_version_and_freshness(tmp_path: Path):
  database = tmp_path / "database.tar.gz"
  database.write_bytes(b"pinned grype database")
  digest = "sha256:" + hashlib.sha256(database.read_bytes()).hexdigest()
  evidence = tmp_path / "evidence.json"
  now = datetime(2026, 7, 17, 12, tzinfo=timezone.utc)
  _json(tmp_path, "evidence.json", {"evidence_version": "1", "grype_version": "0.87.0", "database_sha256": digest, "built_at": "2026-07-17T11:00:00Z"})
  verify_grype_db(evidence, database, "0.87.0", digest, "2026-07-17T11:00:00Z", 24, now=now)
  with pytest.raises(DescriptorError, match="stale"):
    verify_grype_db(evidence, database, "0.87.0", digest, "2026-07-17T11:00:00Z", 1, now=datetime(2026, 7, 17, 14, tzinfo=timezone.utc))


def test_manifest_signature_bundle_and_unknown_or_secret_data_are_rejected(tmp_path: Path):
  document = descriptor(tmp_path)
  (tmp_path / "release-manifest.json").write_text("{}\n", encoding="utf-8")
  with pytest.raises(DescriptorError, match="manifest hash"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["signature"]["subject_sha256"] = "sha256:" + "f" * 64
  with pytest.raises(DescriptorError, match="does not bind"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["verification_state"] = "verified"
  with pytest.raises(DescriptorError, match="exactly"):
    validate_descriptor(document, tmp_path)

  document = descriptor(tmp_path)
  document["release_id"] = "token=must-not-appear"
  with pytest.raises(DescriptorError, match="secret-like"):
    validate_descriptor(document, tmp_path)


def test_verify_executes_cosign_with_public_key_and_no_private_key(tmp_path: Path):
  document = descriptor(tmp_path)
  log = tmp_path / "cosign.log"
  cosign = tmp_path / "cosign"
  cosign.write_text(f"#!/bin/sh\nif test \"$1\" = version; then printf 'GitVersion: v2.4.3\\n'; exit 0; fi\nprintf '%s\\n' \"$*\" >> {shlex.quote(str(log))}\nexit 0\n", encoding="utf-8")
  cosign.chmod(0o755)
  public_key = tmp_path / "cosign.pub"
  public_key.write_text("public key only\n", encoding="utf-8")

  verify_descriptor(document, tmp_path, cosign_binary=cosign, public_key=public_key, cosign_version="v2.4.3", **_pins(cosign, public_key))

  commands = log.read_text(encoding="utf-8").splitlines()
  assert len(commands) == 3
  assert commands[0].startswith("verify-blob --key")
  assert all(str(public_key) in command for command in commands)
  assert sum(command.startswith("verify --key") for command in commands) == 2


def test_verify_fails_closed_when_public_key_or_cosign_execution_is_unavailable(tmp_path: Path):
  document = descriptor(tmp_path)
  missing = tmp_path / "missing-cosign"
  with pytest.raises(DescriptorError, match="public key"):
    verify_descriptor(document, tmp_path, cosign_binary=missing, cosign_sha256="0" * 64, public_key=tmp_path / "missing-public-key", public_key_fingerprint="sha256:" + "0" * 64, cosign_version="v2.4.3")

  public_key = tmp_path / "cosign.pub"
  public_key.write_text("public key only\n", encoding="utf-8")
  failing = tmp_path / "cosign-failing"
  failing.write_text("#!/bin/sh\nif test \"$1\" = version; then printf 'GitVersion: v2.4.3\\n'; exit 0; fi\nexit 1\n", encoding="utf-8")
  failing.chmod(0o755)
  with pytest.raises(DescriptorError, match="Cosign verification failed"):
    verify_descriptor(document, tmp_path, cosign_binary=failing, public_key=public_key, cosign_version="v2.4.3", **_pins(failing, public_key))

  wrong_version = tmp_path / "cosign-wrong-version"
  wrong_version.write_text("#!/bin/sh\nprintf 'GitVersion: v0.0.0\\n'\n", encoding="utf-8")
  wrong_version.chmod(0o755)
  with pytest.raises(DescriptorError, match="pinned verification version"):
    verify_descriptor(document, tmp_path, cosign_binary=wrong_version, public_key=public_key, cosign_version="v2.4.3", **_pins(wrong_version, public_key))


def test_verify_binds_external_cosign_hash_key_fingerprint_and_detects_swap(tmp_path: Path, monkeypatch):
  document = descriptor(tmp_path)
  cosign = tmp_path / "cosign"
  cosign.write_text("#!/bin/sh\nif test \"$1\" = version; then printf 'GitVersion: v2.4.3\\n'; fi\nexit 0\n")
  cosign.chmod(0o755)
  public_key = tmp_path / "cosign.pub"
  public_key.write_text("public key only\n")
  pins = _pins(cosign, public_key)

  with pytest.raises(DescriptorError, match="pinned SHA-256"):
    verify_descriptor(document, tmp_path, cosign_binary=cosign, cosign_sha256="0" * 64, public_key=public_key, public_key_fingerprint=pins["public_key_fingerprint"], cosign_version="v2.4.3")
  with pytest.raises(DescriptorError, match="pinned fingerprint"):
    verify_descriptor(document, tmp_path, cosign_binary=cosign, cosign_sha256=pins["cosign_sha256"], public_key=public_key, public_key_fingerprint="sha256:" + "0" * 64, cosign_version="v2.4.3")

  original_run = release_descriptor.subprocess.run

  def mutate_key_after_version(argv, **kwargs):
    result = original_run(argv, **kwargs)
    if list(argv)[1:] == ["version"]:
      public_key.write_text("swapped public key\n")
    return result

  monkeypatch.setattr(release_descriptor.subprocess, "run", mutate_key_after_version)
  with pytest.raises(DescriptorError, match="configuration changed"):
    verify_descriptor(document, tmp_path, cosign_binary=cosign, public_key=public_key, cosign_version="v2.4.3", **pins)


def test_release_schemas_declare_signed_manifest_and_never_a_trusted_verification_enum():
  descriptor_schema = json.loads((Path(__file__).resolve().parents[3] / "infra/platform/release/release-descriptor.schema.json").read_text(encoding="utf-8"))
  manifest_schema = json.loads((Path(__file__).resolve().parents[3] / "infra/platform/release/release-manifest.schema.json").read_text(encoding="utf-8"))
  assert descriptor_schema["properties"]["descriptor_version"] == {"const": "2"}
  assert {"manifest", "signature", "bundle", "migration_plan", "rollback_plan"} <= set(descriptor_schema["required"])
  assert "verification_state" not in canonical_json(descriptor_schema)
  assert manifest_schema["properties"]["manifest_version"] == {"const": "1"}
  sbom = manifest_schema["$defs"]["sbom"]
  assert "image_reference" in sbom["required"]
