---
phase: 01
slug: w1-1-walking-skeleton
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-15
---

# Phase 01 - Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Product test commands remain intentionally unselected until the approved internal artifact intake pins the toolchain.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | None installed. Wave 0 must establish the approved Laravel/Pest, PHPStan/Pint, React/TypeScript, lint, and E2E toolchain. |
| **Config file** | None - Wave 0 installs and locks the approved toolchain. |
| **Quick run command** | TBD after approved dependency intake and lockfiles. |
| **Full suite command** | TBD after Wave 0. Existing documentation baseline: `./scripts/validate-docs.sh`. |
| **Estimated runtime** | TBD after Wave 0 establishes the executable suites. |

---

## Sampling Rate

- **After every task commit:** Run the task's approved targeted command; run `./scripts/validate-docs.sh` whenever governed documentation changes.
- **After every plan wave:** Run the approved full product suite plus `./scripts/validate-docs.sh`.
- **Before `/gsd-verify-work`:** The full suite, architecture-boundary guard, two-facility E2E path, worker-replay proof, and required air-gap/GitOps evidence must be green.
- **Max feedback latency:** TBD in Wave 0; command selection must make targeted feedback practical during implementation.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-01-01 | 01 | 1 | FR-R1-013 | T-01-01 | Arabic/English and RTL/LTR request creation works for two facilities. | UI E2E | TBD: approved frontend E2E runner | No - Wave 0 | pending |
| 01-01-02 | 01 | 1 | SEC-R1-009 | T-01-02 | Business modules cannot cross-import, join, or own each other's persistence. | Architecture/static + integration | TBD: approved boundary guard | No - Wave 0 | pending |
| 01-01-03 | 01 | 1 | SEC-R1-001 | T-01-03 | Default-deny egress and environment isolation are enforced. | Kubernetes integration | TBD: approved cluster policy test | No - Wave 0 | pending |
| 01-01-04 | 01 | 1 | SEC-R1-011 | T-01-04 | Image provenance, signature, and SBOM are verified from internal sources. | Supply-chain integration | TBD: approved internal verification command | No - Wave 0 | pending |
| 01-01-05 | 01 | 1 | OPS-R1-006 | T-01-05 | GitOps controlled rollout and rollback have staging evidence. | Operational staging | TBD: approved GitOps controller verification | No - Wave 0 | pending |
| 01-01-06 | 01 | 1 | OPS-R1-007, OPS-R1-011, OPS-R1-012 | T-01-06 | Environments and internal registry/mirror-only build paths remain isolated. | Platform/build policy | TBD: approved policy and isolated-build checks | No - Wave 0 | pending |
| 01-01-07 | 01 | 1 | OPS-R1-008 | T-01-07 | Offline intake and upgrade provenance are recorded and verified. | Supply-chain audit | TBD: approved intake verification | No - Wave 0 | pending |

---

## Wave 0 Requirements

- [ ] Approved dependency manifest, exact versions, license evidence, internal mirror provenance, and lockfiles.
- [ ] Laravel/Pest, PHPStan/Pint, and module-boundary/SQL-ownership guard configuration.
- [ ] React/TypeScript type, lint, component, and E2E configuration with locale and direction assertions.
- [ ] OpenAPI, AsyncAPI, CloudEvent, and JSON Schema compatibility checks.
- [ ] Internal CI stages for test, build, verify, and publish; the current pipeline is documentation-only.
- [ ] Approved platform/GitOps, registry, signing/SBOM, key-custody, and storage choices documented as entry-gate evidence before permanent deployment work.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Permanent GitOps deployment and rollback | OPS-R1-006 | No approved Kubernetes distribution or GitOps controller is selected yet. | Collect approved platform decision, then perform staging rollout and GitOps-only rollback with retained evidence. |
| Offline artifact intake and signing custody | SEC-R1-011, OPS-R1-008, OPS-R1-011, OPS-R1-012 | Internal registry, mirror, signing tool, and key-custody model are unresolved entry gates. | Verify approved internal intake record, immutable digest, SBOM, signature, mirror-only resolve, and denied external egress. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies.
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify.
- [ ] Wave 0 covers all missing runners and platform evidence dependencies.
- [ ] No watch-mode flags.
- [ ] Feedback latency target is recorded after toolchain approval.
- [ ] `nyquist_compliant: true` set in frontmatter after validation coverage is verified.

**Approval:** pending
