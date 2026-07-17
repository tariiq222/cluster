from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest

from scripts.release_descriptor import canonical_json


pytestmark = pytest.mark.integration
ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/release_descriptor.py"
LICENSE_POLICY = ROOT / "infra/platform/release/license-policy.json"
REVISION = "a" * 40
API_DIGEST = "sha256:" + "1" * 64
WEB_DIGEST = "sha256:" + "2" * 64


def _json(root: Path, name: str, value: object) -> None:
  (root / name).write_text(canonical_json(value), encoding="utf-8")


def _fixture(root: Path) -> None:
  api = f"registry.example/api@{API_DIGEST}"
  web = f"registry.example/web@{WEB_DIGEST}"
  (root / "compose.yaml").write_text("services: {}\n", encoding="utf-8")
  for name, reference, digest in (("api", api, API_DIGEST), ("web", web, WEB_DIGEST)):
    _json(root, f"{name}.sbom.json", {
      "bomFormat": "CycloneDX", "specVersion": "1.5",
      "metadata": {"component": {"type": "container", "bom-ref": reference, "name": reference.rsplit("@", 1)[0], "version": digest, "properties": [{"name": "org.third-health-cluster.release.image-reference", "value": reference}, {"name": "org.third-health-cluster.release.image-digest", "value": digest}]}},
      "components": [{"bom-ref": "pkg:generic/runtime@1", "type": "library", "name": "runtime", "version": "1", "licenses": [{"license": {"id": "MIT"}}]}],
    })
  for name, reference, digest in (("api", api, API_DIGEST), ("web", web, WEB_DIGEST)):
    _json(root, f"{name}.provenance.json", {
      "_type": "https://in-toto.io/Statement/v1",
      "predicateType": "https://slsa.dev/provenance/v1",
      "subject": [{"name": reference.rsplit("@", 1)[0], "digest": {"sha256": digest[7:]}}],
      "predicate": {"buildDefinition": {"externalParameters": {"gitRevision": REVISION}, "resolvedDependencies": [reference]}},
    })
  _json(root, "migration-plan.json", {"plan_version": "1", "git_revision": REVISION, "strategy": "forward-only", "destructive": False, "requires_pre_backup": True})
  _json(root, "rollback-plan.json", {"plan_version": "1", "git_revision": REVISION, "strategy": "redeploy-known-good-digest", "allows_destructive_down_migration": False})
  (root / "release.sig").write_text("signature\n", encoding="utf-8")
  (root / "release.bundle.json").write_text('{"bundle":"test"}\n', encoding="utf-8")


def _run(*args: str, cwd: Path) -> subprocess.CompletedProcess[str]:
  return subprocess.run(["python3", str(SCRIPT), *args], cwd=cwd, capture_output=True, text=True, check=False)


def _generate(root: Path) -> subprocess.CompletedProcess[str]:
  return _run(
    "generate-manifest", "--root", str(root), "--output", str(root / "release-manifest.json"),
    "--release-id", "r1.0.0", "--git-revision", REVISION,
    "--api-image", f"registry.example/api@{API_DIGEST}", "--web-image", f"registry.example/web@{WEB_DIGEST}",
    "--compose", "compose.yaml", "--api-sbom", "api.sbom.json", "--web-sbom", "web.sbom.json",
    "--api-provenance", "api.provenance.json", "--web-provenance", "web.provenance.json",
    "--migration-plan", "migration-plan.json", "--rollback-plan", "rollback-plan.json",
    cwd=root,
  )


def test_cli_generates_deterministic_manifest_then_bound_descriptor(tmp_path: Path):
  _fixture(tmp_path)
  generated = _generate(tmp_path)
  assert generated.returncode == 0, generated.stderr
  first = (tmp_path / "release-manifest.json").read_bytes()
  assert _generate(tmp_path).returncode == 0
  assert first == (tmp_path / "release-manifest.json").read_bytes()

  descriptor = _run("generate-descriptor", "--root", str(tmp_path), "--output", str(tmp_path / "release-descriptor.json"), "--manifest", "release-manifest.json", "--signature", "release.sig", "--bundle", "release.bundle.json", cwd=tmp_path)
  assert descriptor.returncode == 0, descriptor.stderr
  validated = _run("validate", str(tmp_path / "release-descriptor.json"), "--root", str(tmp_path), cwd=tmp_path)
  assert validated.returncode == 0, validated.stderr
  document = json.loads((tmp_path / "release-descriptor.json").read_text(encoding="utf-8"))
  assert document["manifest"]["path"] == "release-manifest.json"
  assert "verification_state" not in document


def test_cli_rejects_modified_signed_manifest_and_cannot_claim_verification(tmp_path: Path):
  _fixture(tmp_path)
  assert _generate(tmp_path).returncode == 0
  assert _run("generate-descriptor", "--root", str(tmp_path), "--output", str(tmp_path / "release-descriptor.json"), "--manifest", "release-manifest.json", "--signature", "release.sig", "--bundle", "release.bundle.json", cwd=tmp_path).returncode == 0
  (tmp_path / "release-manifest.json").write_text("{}\n", encoding="utf-8")
  result = _run("validate", str(tmp_path / "release-descriptor.json"), "--root", str(tmp_path), cwd=tmp_path)
  assert result.returncode != 0
  assert "manifest hash" in result.stderr

  unsupported = _run("validate", str(tmp_path / "release-descriptor.json"), "--allow-unverified", cwd=tmp_path)
  assert unsupported.returncode != 0
  assert "unrecognized arguments" in unsupported.stderr


def test_cli_scan_sbom_rejects_unbound_digest_and_denied_license(tmp_path: Path):
  _fixture(tmp_path)
  reference = f"registry.example/api@{API_DIGEST}"
  denied = _run("scan-sbom", str(tmp_path / "api.sbom.json"), "--image-reference", reference, "--image-digest", API_DIGEST, "--license-policy", str(LICENSE_POLICY), cwd=tmp_path)
  assert denied.returncode == 0, denied.stderr

  _json(tmp_path, "api.sbom.json", {"bomFormat": "CycloneDX", "components": [{"licenses": [{"license": {"id": "GPL-3.0-only"}}]}]})
  unbound = _run("scan-sbom", str(tmp_path / "api.sbom.json"), "--image-reference", reference, "--image-digest", API_DIGEST, "--license-policy", str(LICENSE_POLICY), cwd=tmp_path)
  assert unbound.returncode != 0
  assert "designated metadata.component subject" in unbound.stderr

  _fixture(tmp_path)
  document = json.loads((tmp_path / "api.sbom.json").read_text(encoding="utf-8"))
  document["components"][0]["licenses"] = [{"license": {"id": "GPL-3.0-only"}}]
  _json(tmp_path, "api.sbom.json", document)
  denied = _run("scan-sbom", str(tmp_path / "api.sbom.json"), "--image-reference", reference, "--image-digest", API_DIGEST, "--license-policy", str(LICENSE_POLICY), cwd=tmp_path)
  assert denied.returncode != 0
  assert "denied license" in denied.stderr


def test_cli_binds_a_syft_bom_to_the_designated_container_subject(tmp_path: Path):
  reference = f"registry.example/api@{API_DIGEST}"
  _json(tmp_path, "api.sbom.json", {"bomFormat": "CycloneDX", "metadata": {}, "components": [{"name": "runtime", "version": "1", "licenses": [{"license": {"id": "MIT"}}]}]})
  bound = _run("bind-sbom", str(tmp_path / "api.sbom.json"), "--image-reference", reference, cwd=tmp_path)
  assert bound.returncode == 0, bound.stderr
  scan = _run("scan-sbom", str(tmp_path / "api.sbom.json"), "--image-reference", reference, "--image-digest", API_DIGEST, "--license-policy", str(LICENSE_POLICY), cwd=tmp_path)
  assert scan.returncode == 0, scan.stderr
