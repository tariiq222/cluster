from pathlib import Path

import pytest
import yaml


pytestmark = pytest.mark.unit
ROOT = Path(__file__).resolve().parents[3]
WORKFLOW = ROOT / ".github/workflows/ci.yml"
RELEASE_JOBS = {
    "release-build-images",
    "release-sbom-provenance",
    "release-sign-verify",
    "verify-build",
}
REQUIRED_GATES = {
    "test-api",
    "test-web",
    "test-e2e-w1-1",
    "verify-boundaries",
    "verify-ci-config",
}


def _workflow() -> dict:
    return yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))


def _commands(job: dict) -> str:
    return "\n".join(step["run"] for step in job["steps"] if "run" in step)


def test_ci_uses_literal_pinned_runners_and_fail_closed_protected_release_flow():
    config = _workflow()
    jobs = config["jobs"]
    assert jobs.keys() >= RELEASE_JOBS
    assert config["permissions"] == {"contents": "read"}
    assert (
        config["env"]["SC_LIVE_TOOLING_APPROVED"]
        == "${{ vars.SC_LIVE_TOOLING_APPROVED || 'false' }}"
    )
    image_digests = []
    for name, job in jobs.items():
        image = job["container"]["image"]
        assert image.startswith("registry.internal/third-health-cluster/")
        assert image.count("@sha256:") == 1
        image_digests.append(image.rsplit("@sha256:", 1)[1])
        assert "$" not in image
        assert "SC_LIVE_TOOLING_APPROVED" in _commands(job), name
    image_digests.append(
        jobs["release-build-images"]["services"]["docker"]["image"].rsplit(
            "@sha256:", 1
        )[1]
    )
    assert all(digest == "0" * 64 for digest in image_digests) or all(
        digest != "0" * 64 for digest in image_digests
    )
    for name in RELEASE_JOBS:
        assert jobs[name]["if"] == "github.ref_type == 'tag' && github.ref_protected"
        assert jobs[name]["runs-on"] == ["self-hosted", jobs[name]["environment"]]
    assert jobs["release-build-images"]["environment"] == "release-artifacts"
    assert jobs["release-sbom-provenance"]["environment"] == "release-artifacts"
    assert jobs["release-sign-verify"]["environment"] == "release-signing"
    assert jobs["verify-build"]["environment"] == "release-verification"
    assert set(jobs["release-build-images"]["needs"]) >= REQUIRED_GATES
    assert set(jobs["release-sign-verify"]["needs"]) >= REQUIRED_GATES


def test_ci_binds_build_metadata_artifacts_and_uses_separate_public_verifier():
    jobs = _workflow()["jobs"]
    build = _commands(jobs["release-build-images"])
    assert "docker buildx build" in build
    assert "--metadata-file" in build
    assert "containerimage.digest" in build
    assert "RepoDigests" not in build

    artifacts = _commands(jobs["release-sbom-provenance"])
    assert "docker login" in artifacts
    assert (
        "bind-sbom" in artifacts
        and "scan-sbom" in artifacts
        and "license-policy.json" in artifacts
    )
    assert "verify-grype-db" in artifacts and "grype db import" in artifacts
    assert "generate-manifest" in artifacts
    assert "migration-plan.json" in artifacts and "rollback-plan.json" in artifacts
    assert "must not receive COSIGN_PRIVATE_KEY" in artifacts
    assert "must not receive COSIGN_PRIVATE_KEY" in build

    signing = _commands(jobs["release-sign-verify"])
    assert "COSIGN_PRIVATE_KEY" in signing
    assert "COSIGN_PUBLIC_KEY" in signing
    assert "sign-blob" in signing and "verify-blob" in signing
    assert "generate-descriptor" in signing
    verifier = _commands(jobs["verify-build"])
    assert "COSIGN_PUBLIC_KEY" in verifier
    assert "COSIGN_VERSION" in verifier
    assert "make verify-build" in verifier
    assert '"$COSIGN_PRIVATE_KEY"' not in verifier
    workflow = WORKFLOW.read_text(encoding="utf-8")
    assert "COSIGN_KEY" not in workflow
    assert ". release/image-refs.env" not in artifacts
    assert (
        "parse_image_refs.py release/image-refs.env API_IMAGE_DIGEST_REF" in artifacts
    )
    assert ". release/image-refs.env" not in signing
    assert "parse_image_refs.py release/image-refs.env WEB_IMAGE_DIGEST_REF" in signing
    assert jobs["release-sign-verify"]["env"] == {
        "COSIGN_PRIVATE_KEY": "${{ secrets.COSIGN_PRIVATE_KEY }}",
        "COSIGN_PUBLIC_KEY": "${{ secrets.COSIGN_PUBLIC_KEY }}",
    }
    assert jobs["verify-build"]["env"] == {
        "COSIGN_PUBLIC_KEY": "${{ secrets.COSIGN_PUBLIC_KEY }}"
    }
    assert jobs["release-sbom-provenance"]["env"]["SC_GRYPE_DB_SHA256"] == (
        "${{ vars.SC_GRYPE_DB_SHA256 || '' }}"
    )
    assert jobs["release-sbom-provenance"]["env"]["SC_GRYPE_DB_BUILT_AT"] == (
        "${{ vars.SC_GRYPE_DB_BUILT_AT || '' }}"
    )


def test_makefile_has_no_keyless_or_enum_based_release_verification_path():
    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    assert "verify-build:" in makefile
    assert "scripts/release_descriptor.py verify" in makefile
    assert "COSIGN_BINARY" in makefile and "COSIGN_PUBLIC_KEY" in makefile
    assert "--allow-unverified" not in makefile
    assert "RELEASE_REQUIRE_VERIFIED" not in makefile
    assert "test-release-descriptor-contract:" in makefile
    assert "verify-build-structure:" not in makefile
