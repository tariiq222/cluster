from __future__ import annotations

from pathlib import Path

import pytest
import yaml


pytestmark = pytest.mark.unit
ROOT = Path(__file__).resolve().parents[3]
RELEASE_JOBS = {"release-build-images", "release-sbom-provenance", "release-sign-verify", "verify-build"}
REQUIRED_GATES = {"test-api", "test-web", "test-e2e-w1-1", "verify-boundaries", "verify-ci-config"}


def _needs(job: dict) -> set[str]:
  return {item["job"] if isinstance(item, dict) else item for item in job.get("needs", [])}


def test_ci_uses_literal_pinned_runners_and_fail_closed_protected_release_flow():
  config = yaml.safe_load((ROOT / ".gitlab-ci.yml").read_text(encoding="utf-8"))
  assert config["stages"] == ["validate", "build", "test", "verify", "release-build", "release-artifacts", "release-sign", "release-verify"]
  assert RELEASE_JOBS <= config.keys()
  for name, job in config.items():
    if not isinstance(job, dict) or "image" not in job:
      continue
    image = job["image"]
    assert image.startswith("registry.internal/third-health-cluster/")
    assert image.count("@sha256:") == 1
    assert image.rsplit("@sha256:", 1)[1] == "0" * 64
    assert "$" not in image
  assert config["release-build-images"]["services"][0]["name"].endswith("@sha256:" + "0" * 64)
  for name in RELEASE_JOBS:
    rules = config[name]["rules"]
    assert {"if": '$CI_COMMIT_REF_PROTECTED == "true" && $CI_COMMIT_TAG'} in rules
    assert {"when": "never"} in rules
  assert config["release-build-images"]["environment"]["name"] == "release-artifacts"
  assert config["release-sbom-provenance"]["environment"]["name"] == "release-artifacts"
  assert config["release-sign-verify"]["environment"]["name"] == "release-signing"
  assert config["verify-build"]["environment"]["name"] == "release-verification"
  for name in RELEASE_JOBS:
    assert config[name]["tags"] == [config[name]["environment"]["name"]]
  assert REQUIRED_GATES <= _needs(config["release-build-images"])
  assert REQUIRED_GATES <= _needs(config["release-sign-verify"])


def test_ci_binds_build_metadata_artifacts_and_uses_separate_public_verifier():
  config = yaml.safe_load((ROOT / ".gitlab-ci.yml").read_text(encoding="utf-8"))
  build = "\n".join(config["release-build-images"]["script"])
  assert "docker buildx build" in build
  assert "--metadata-file" in build
  assert "containerimage.digest" in build
  assert "RepoDigests" not in build

  artifacts = "\n".join(config["release-sbom-provenance"]["script"])
  assert "docker login" in artifacts
  assert "bind-sbom" in artifacts and "scan-sbom" in artifacts and "license-policy.json" in artifacts
  assert "verify-grype-db" in artifacts and "grype db import" in artifacts
  assert "generate-manifest" in artifacts
  assert "migration-plan.json" in artifacts and "rollback-plan.json" in artifacts
  assert "must not receive COSIGN_PRIVATE_KEY" in artifacts
  assert "must not receive COSIGN_PRIVATE_KEY" in "\n".join(config["release-build-images"]["script"])

  signing = "\n".join(config["release-sign-verify"]["script"])
  assert "COSIGN_PRIVATE_KEY" in signing
  assert "COSIGN_PUBLIC_KEY" in signing
  assert "sign-blob" in signing and "verify-blob" in signing
  assert "generate-descriptor" in signing
  verifier = "\n".join(config["verify-build"]["script"])
  assert "COSIGN_PUBLIC_KEY" in verifier
  assert "COSIGN_VERSION" in verifier
  assert "make verify-build" in verifier
  assert '"$COSIGN_PRIVATE_KEY"' not in verifier
  assert "COSIGN_KEY" not in (ROOT / ".gitlab-ci.yml").read_text(encoding="utf-8")
  assert ". release/image-refs.env" not in artifacts
  assert "parse_image_refs.py release/image-refs.env API_IMAGE_DIGEST_REF" in artifacts
  signing_script = "\n".join(config["release-sign-verify"]["script"])
  assert ". release/image-refs.env" not in signing_script
  assert "parse_image_refs.py release/image-refs.env WEB_IMAGE_DIGEST_REF" in signing_script

  tool_checks = "\n".join(config[".supply-chain-job"]["before_script"])
  for tool, variable in (("cosign", "SC_COSIGN_VERSION"), ("syft", "SC_SYFT_VERSION"), ("grype", "SC_GRYPE_VERSION")):
    assert f'test "$(%s version' % tool in tool_checks
    assert f'= "${variable}"' in tool_checks


def test_makefile_has_no_keyless_or_enum_based_release_verification_path():
  makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
  assert "verify-build:" in makefile
  assert "scripts/release_descriptor.py verify" in makefile
  assert "COSIGN_BINARY" in makefile and "COSIGN_PUBLIC_KEY" in makefile
  assert "--allow-unverified" not in makefile
  assert "RELEASE_REQUIRE_VERIFIED" not in makefile
  assert "test-release-descriptor-contract:" in makefile
  assert "verify-build-structure:" not in makefile
