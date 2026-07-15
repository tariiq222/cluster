# Codebase Concerns

**Analysis Date:** 2026-07-15

Scope: Only `/Users/tariq/code/R3/cluster` was analyzed. `.planning/archive/` is historical and non-authoritative.

## Tech Debt

**The product architecture has no implementation:**
- Issue: The checkout contains specifications, contracts, and documentation tooling but no Laravel or React application, product dependency manifests, database migrations, product tests, container build, or deployment manifests (`.planning/PROJECT.md:13-15`, `.planning/PROJECT.md:40-48`).
- Files: `.planning/PROJECT.md`, `docs/architecture/overview.md`, `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, `.gitlab-ci.yml`
- Impact: Authorization, organizational isolation, Outbox atomicity, idempotency, recovery, air-gap operation, localization, performance, and module boundaries are design requirements without executable guarantees.
- Fix approach: Deliver Phase 1 as a permanent walking skeleton with locked Laravel/React dependencies, one end-to-end journey, architecture and security guards, reproducible offline builds, and deployment/rollback evidence.

**Phase 1 has no executable plan and its entry gate is open:**
- Issue: All 25 phases and 88 requirements are pending, Phase 1 has `Plans: TBD`, and its Kubernetes, storage, MySQL matrix, offline intake/signing, key-custody, and operating-model decisions must close before implementation (`.planning/STATE.md:22-27`, `.planning/STATE.md:57-64`, `.planning/ROADMAP.md:37-51`).
- Files: `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `.planning/PROJECT.md`
- Impact: There are no implementation tasks, verification commands, owned artifact locations, or approved platform selections for the first deliverable.
- Fix approach: Discuss and plan Phase 1, close each entry-gate decision in a governed record, and trace every task and verification artifact to the Phase 1 requirement IDs.

**Draft and proposed documents are marked as sources of truth:**
- Issue: `docs/catalog.yaml` marks all 16 draft documents and all nine proposed documents as `source_of_truth: true`, including the unfilled ADR template (`docs/catalog.yaml:22-30`, `docs/catalog.yaml:62-76`, `docs/catalog.yaml:92`). Document control defines draft/proposed as unaccepted states, while the documentation index gives specialized source-of-truth documents precedence (`docs/governance/document-control.md:124-134`, `docs/README.md:19-34`).
- Files: `docs/catalog.yaml`, `docs/README.md`, `docs/governance/document-control.md`, `docs/adr/template.md`
- Impact: Implementers cannot reliably distinguish binding security, engineering, and operations policy from material awaiting approval; the template can be mistaken for a governing decision.
- Fix approach: Reserve `source_of_truth: true` for accepted material, mark templates non-authoritative, and define status-aware precedence in `docs/governance/document-control.md`.

**Accepted API authentication conflicts with the accepted browser-session boundary:**
- Issue: OpenAPI globally requires bearer JWT authentication and returns an `access_token` in the login response (`docs/contracts/api/openapi.yaml:7-19`, `docs/contracts/api/openapi.yaml:539-585`). ADR-012 requires short-lived `httpOnly` sessions and CSRF protection, and the security policy stores its JWT in a secure `httpOnly` cookie (`docs/adr/012-local-identity-and-session-security.md:27-47`, `docs/data-security/identity-session-security.md:114-147`).
- Files: `docs/contracts/api/openapi.yaml`, `docs/adr/012-local-identity-and-session-security.md`, `docs/data-security/identity-session-security.md`
- Impact: Backend and frontend work can implement incompatible authentication models. Returning a bearer token in a JSON body exposes it to application JavaScript and weakens the intended cookie boundary.
- Fix approach: Select one browser-session contract before scaffolding. For a cookie-based first-party shell, model secure cookie issuance, CSRF, refresh, logout, and expiry in OpenAPI and remove the token-bearing response.

**Accepted contracts contain unresolved or contradictory policy values:**
- Issue: OpenAPI accepts 12-character passwords while the security draft requires 14, permits 1 GiB files while file policy defaults to 200 MB, and permits risk likelihood/impact values from 1–10 before W3.0 approves a matrix whose accepted release plan defaults to 5×5 (`docs/contracts/api/openapi.yaml:584-610`, `docs/contracts/api/openapi.yaml:638`, `docs/data-security/identity-session-security.md:67-81`, `docs/data-security/file-security.md:178-190`, `docs/plans/release-3-risk.md:50-63`, `docs/plans/release-3-risk.md:85-109`).
- Files: `docs/contracts/api/openapi.yaml`, `docs/data-security/identity-session-security.md`, `docs/data-security/file-security.md`, `docs/plans/release-3-risk.md`, `.planning/ROADMAP.md`
- Impact: Generated clients and implementation can encode limits that violate policy or pre-empt the W3.0 governance gate, forcing breaking changes.
- Fix approach: Reconcile accepted contract constraints with approved policy, make configurable policy-owned limits explicit, and remove premature R3 value ranges until W3.0 closes.

**The planning decision log contradicts the approved planning state:**
- Issue: `.planning/STATE.md` says the roadmap is approved and ready for Phase 1 planning, while every entry in `.planning/PROJECT.md` under `Key Decisions` has an outcome of `Pending` (`.planning/STATE.md:22-27`, `.planning/PROJECT.md:65-75`).
- Files: `.planning/STATE.md`, `.planning/PROJECT.md`, `.planning/ROADMAP.md`
- Impact: Planning agents cannot tell whether roadmap scope, document authority, architecture, cross-cutting gates, and deferred-decision policy are settled or unresolved.
- Fix approach: Record the approved outcome and evidence for settled decisions; leave only genuinely open platform selections pending.

## Known Bugs

Not detected. There is no executable product implementation to exercise; current specification and governance defects are listed under the other sections.

## Security Considerations

**Security policy is unapproved:**
- Risk: The threat model, authorization model, identity/session policy, classification rules, file controls, audit/privacy policy, retention policy, and logical data model all have `draft` status, while security failures are release blockers (`docs/catalog.yaml:22-30`, `.planning/PROJECT.md:50-63`).
- Files: `docs/data-security/threat-model.md`, `docs/data-security/authorization-model.md`, `docs/data-security/identity-session-security.md`, `docs/data-security/classification-and-handling.md`, `docs/data-security/file-security.md`, `docs/data-security/audit-and-privacy.md`, `docs/data-security/retention-and-legal-hold.md`, `docs/data-security/logical-data-model.md`
- Current mitigation: Accepted ADRs define high-level fail-closed authorization, local identity, file controls, audit, supply-chain, and recovery boundaries in `docs/adr/004-authorization-and-isolation.md` and `docs/adr/012-local-identity-and-session-security.md` through `docs/adr/019-kubernetes-resilience-and-recovery.md`.
- Recommendations: Complete formal security review and acceptance before dependent implementation, resolving contract and plan conflicts in the same governed change.

**Administrative audit guidance can capture credentials and sensitive payloads:**
- Risk: The threat model requires the full administrative request and response in the audit log (`docs/data-security/threat-model.md:157-166`), despite forbidding full worker payloads (`docs/data-security/threat-model.md:146-155`). Full bodies can place credentials, PII, or confidential values in append-only audit storage.
- Files: `docs/data-security/threat-model.md`, `docs/data-security/audit-and-privacy.md`, `docs/operations/observability-and-slos.md`
- Current mitigation: The audit design separates encrypted payloads from event metadata and records hashes (`docs/data-security/audit-and-privacy.md:42-101`).
- Recommendations: Replace full-body logging with a schema-defined allowlist of actor/session, action, target IDs, changed-field names, policy/facts versions, outcome, reason code, and digest; prohibit credentials and raw bodies explicitly.

**CI does not enforce required air-gap supply-chain controls:**
- Risk: `.gitlab-ci.yml` defaults to the mutable public-style image tag `python:3.12-slim`, performs an unqualified `pip install`, and has no secret, license, vulnerability, SBOM, provenance, or signature job (`.gitlab-ci.yml:1-40`). This does not implement the required artifact flow in `docs/operations/air-gap-supply-chain.md:24-55`.
- Files: `.gitlab-ci.yml`, `requirements-docs.txt`, `docs/operations/air-gap-supply-chain.md`, `docs/engineering/ci-cd-and-release.md`, `docs/plans/release-1-platform.md`
- Current mitigation: Repository guidance requires approved internal dependency sources, and the target supply-chain design forbids external build access (`README.md`, `docs/operations/air-gap-supply-chain.md`).
- Recommendations: Pin an internally mirrored image by digest, configure an approved internal Python index, and add secret/license/vulnerability checks with signed SBOM and provenance evidence for releasable artifacts.

**Review ownership is incomplete and not deployment-ready:**
- Risk: `.gitlab/CODEOWNERS` says its aliases must be replaced and omits `docs/operations/`, `docs/engineering/`, `docs/contracts/`, `docs/adr/`, `docs/plans/`, and `.gitlab-ci.yml` (`.gitlab/CODEOWNERS:1-9`). Security-sensitive contracts, pipelines, operations, and plans lack complete repository-enforced ownership.
- Files: `.gitlab/CODEOWNERS`, `docs/governance/raci.md`, `.gitlab-ci.yml`
- Current mitigation: Governed Markdown lists owner and reviewer roles, but `scripts/validate-docs.sh` checks reviewer-list shape and selected catalog agreement rather than valid GitLab groups or CODEOWNERS coverage (`scripts/validate-docs.sh:280-287`, `scripts/validate-docs.sh:390-402`).
- Recommendations: Map every governed area and sensitive repository file to real GitLab groups, then require CODEOWNERS approvals on protected branches.

**Security reporting has no repository-discoverable route or response target:**
- Risk: Reporters are directed to an internal channel or contact directory, but no stable alias/portal identifier, acknowledgement target, or severity-based triage target is published (`SECURITY.md:1-7`).
- Files: `SECURITY.md`
- Current mitigation: Public issues and inclusion of credentials, secrets, or production data are explicitly prohibited (`SECURITY.md:3-5`).
- Recommendations: Publish a stable internal reporting identifier and severity-based acknowledgement and triage expectations without exposing personal details.

## Performance Bottlenecks

**Performance and capacity targets have no measurements:**
- Problem: The accepted targets include R1 read/write latency, R2 dashboard latency, R3 assessment latency, 20,000 accounts, 2,000 concurrent users, availability, projection rebuild, and recovery, but no benchmark or operating evidence exists (`docs/product/success-metrics.md:54-87`, `docs/architecture/non-functional-requirements.md:20-36`).
- Files: `.planning/REQUIREMENTS.md`, `docs/architecture/non-functional-requirements.md`, `docs/product/success-metrics.md`, `.planning/PROJECT.md`
- Cause: Product source, representative fixtures, load scripts, deployment manifests, and observability implementation are absent (`.planning/PROJECT.md:40-48`).
- Improvement path: Establish Phase 1 reference journeys, representative Arabic data, repeatable load profiles, query budgets, and versioned baselines before later modules and derived projections increase load.

## Fragile Areas

**Recovery-test cadence conflicts across governing documents:**
- Files: `.planning/REQUIREMENTS.md`, `docs/governance/traceability-matrix.md`, `docs/governance/assumptions-constraints.md`, `docs/operations/ha-dr-backup.md`, `docs/data-security/threat-model.md`
- Why fragile: `OPS-R1-005`, the traceability matrix, and accepted governance require a documented monthly restore test (`.planning/REQUIREMENTS.md:63-76`, `docs/governance/traceability-matrix.md:181-196`, `docs/governance/assumptions-constraints.md:112-123`), while proposed operations and draft threat guidance specify quarterly exercises (`docs/operations/ha-dr-backup.md:23-29`, `docs/operations/ha-dr-backup.md:58-60`, `docs/data-security/threat-model.md:179-188`).
- Safe modification: Keep monthly recovery evidence as the binding minimum unless governance formally changes `OPS-R1-005`; align all operations and security documents in one controlled update.
- Test coverage: No restore automation, backup configuration, recovery manifest, or drill evidence exists in the checkout.

**Documentation verification is partial and environment-dependent:**
- Files: `.gitlab-ci.yml`, `mkdocs.yml`, `scripts/validate-docs.sh`, `.markdownlint.json`, `requirements-docs.txt`, `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`
- Why fragile: The custom validator checks YAML/JSON syntax but not OpenAPI, AsyncAPI, or JSON Schema semantics (`scripts/validate-docs.sh:212-223`); Markdownlint is configured but not installed or invoked; and the inspected host's default `python3` has no MkDocs module, so `python3 -m mkdocs --version` cannot start even though CI defines a strict build (`.gitlab-ci.yml:11-31`).
- Safe modification: Lock documentation dependencies, provide a reproducible local command or container, invoke Markdownlint when intended as a gate, and add semantic/compatibility validators for all machine-readable contracts.
- Test coverage: `./scripts/validate-docs.sh` passes on the current content. A strict MkDocs build cannot be rerun with the host's default Python until `requirements-docs.txt` is installed from an approved source.

**Declared document-governance rules are not fully enforced:**
- Files: `docs/governance/document-control.md`, `docs/catalog.yaml`, `scripts/validate-docs.sh`
- Why fragile: Document control requires status-aware acceptance and an append-only `## سجل التغيير` in every governed document (`docs/governance/document-control.md:124-170`), while the validator checks allowed status values and catalog agreement but not acceptance evidence, `source_of_truth` compatibility, change-log presence/content, review-cycle vocabulary, or owner/reviewer role matrices (`scripts/validate-docs.sh:255-307`, `scripts/validate-docs.sh:390-402`).
- Safe modification: Encode these rules in focused validator functions and fixtures before relying on the catalog as an approval boundary.
- Test coverage: The embedded Python validator has no regression test suite; only its current end-to-end shell invocation is exercised through `.gitlab-ci.yml`.

**Most of the analyzed baseline is not committed:**
- Files: `docs/`, `.gitlab-ci.yml`, `scripts/`, `README.md`, `.planning/codebase/`, `.opencode/`
- Why fragile: `git status --porcelain=v1` reports 24 changed or untracked entries, including the documentation package, CI, scripts, codebase maps, and local tooling. Ordinary diffs and downstream clones cannot reproduce the analyzed state.
- Safe modification: Review intended project-owned files, exclude generated/local artifacts, run all gates, and commit a coherent non-secret baseline through the owning workflow.
- Test coverage: `git diff --check` passes for tracked changes, but it does not review or preserve untracked files.

**The local agent installation is a large untracked repository surface:**
- Files: `.opencode/`, `.opencode/.gitignore`, `.opencode/gsd-file-manifest.json`, `.gitignore`
- Why fragile: `.opencode/` occupies about 131 MB. Its nested ignore rules exclude some dependency artifacts but not the installed GSD distribution, making intended project ownership and accidental tooling vendoring difficult to audit.
- Safe modification: Decide which `.opencode/` configuration is project-owned, ignore or externally manage installed distributions, and retain only reproducible configuration and manifests intended for version control.
- Test coverage: No repository-hygiene check detects unexpectedly large untracked tooling trees or accidental vendoring.

## Scaling Limits

**Capacity depends on unresolved infrastructure choices:**
- Current capacity: No measured capacity exists; the design target is up to 20,000 accounts and 2,000 concurrent users (`.planning/PROJECT.md:40-46`, `docs/architecture/non-functional-requirements.md:20-25`).
- Limit: Kubernetes/storage, MySQL support matrix, queue/cache, search, logging, secrets, and backup products or versions are deferred, proposed, or absent from executable configuration (`.planning/ROADMAP.md:37-43`, `docs/plans/implementation-roadmap.md:181-190`, `docs/architecture/non-functional-requirements.md:38-40`).
- Scaling path: Close the Phase 1 platform and MySQL decisions, deploy a production-like staging topology, then validate load, failover, projection rebuild, and recovery with accepted fixtures.

## Dependencies at Risk

**Documentation dependencies are not reproducibly pinned:**
- Risk: `requirements-docs.txt` uses broad ranges without hashes, while `scripts/validate-docs.sh` directly imports PyYAML without declaring it directly (`requirements-docs.txt:1-3`, `scripts/validate-docs.sh:29-36`). The CI image is selected by mutable tag (`.gitlab-ci.yml:5-14`).
- Impact: Offline builds can resolve different transitive versions or fail when the internal mirror lacks an implicitly required package, contrary to reproducible air-gap delivery.
- Migration plan: Add PyYAML as a direct dependency, generate reviewed locked constraints with hashes, and pin the internally mirrored CI image by digest.

**Operational product selection is open:**
- Risk: Kubernetes/storage must close before W1.1, observability before W1.5, retention before the first production work type, backup storage before real data, and final security classification before R1 expansion (`docs/plans/implementation-roadmap.md:181-190`, `.planning/ROADMAP.md:37-43`).
- Impact: Images, operators, topology, licensing, backup/restore procedures, key custody, and support matrices cannot be frozen; selecting them implicitly during coding creates procurement and architecture rework.
- Migration plan: Record alternatives, internal availability, licensing/support, compatibility matrices, failure domains, key custody, and acceptance evidence before each entry gate.

## Missing Critical Features

**Phase 1 platform and product skeleton:**
- Problem: No login, organization scope, minimal work definition, `WorkRecord`, authorization decision, Outbox consumer, notification, audit trail, React shell, MySQL schema, or Kubernetes deployment exists (`.planning/PROJECT.md:13-15`, `.planning/ROADMAP.md:37-51`).
- Blocks: Every functional and release requirement in `.planning/REQUIREMENTS.md`; Phase 2 depends on a green Phase 1 exit gate.

**Operational delivery and recovery implementation:**
- Problem: There are no internal-mirror configurations, product image build, SBOM/signing/provenance pipeline, GitOps manifests, NetworkPolicy, backup jobs, restore automation, dashboards, alerts, or drill evidence (`.gitlab-ci.yml`, `docs/plans/release-1-platform.md:100-166`).
- Blocks: Phase 1 offline deployment, use of real data, and all R1 security and operations gates.

**R3 policy specification:**
- Problem: W3.0 owns the risk matrix, appetite, review cycles, KRI rules, capabilities, fields, escalation, and golden cases; no approved `RISK-SPEC.md` exists (`.planning/ROADMAP.md:306-319`, `docs/plans/release-3-risk.md:50-114`).
- Blocks: R3 implementation; the 1–10 ranges in `docs/contracts/api/openapi.yaml:638` cannot substitute for the governance decision.

## Test Coverage Gaps

**All product behavior:**
- What's not tested: Domain invariants, module DAG and data ownership, centralized authorization, field filtering, organizational isolation, Outbox atomicity, idempotency, API/UI flows, localization, accessibility, performance, air-gap operation, failover, backup, and recovery.
- Files: `docs/engineering/testing-strategy.md`, `.planning/REQUIREMENTS.md`, `.planning/PROJECT.md`
- Risk: Architecture and security can drift during scaffolding, and release-blocking defects can remain invisible until integration.
- Priority: High

**Contract semantics and compatibility:**
- What's not tested: OpenAPI/AsyncAPI semantic validity, external `$ref` resolution, JSON Schema meta-schema validity, authentication consistency, schema compatibility, event evolution, and agreement between contract values and policy.
- Files: `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, `docs/contracts/schemas/`, `scripts/validate-docs.sh`, `.gitlab-ci.yml`
- Risk: Syntactically valid YAML/JSON can be insecure, contradictory, or unusable by generated clients and consumers.
- Priority: High

**Documentation validator and governance rules:**
- What's not tested: The embedded Python helpers have no regression suite; required change logs, status-aware source-of-truth rules, role matrices, review-cycle vocabulary, and catalog fields beyond title/status/owner are not enforced (`scripts/validate-docs.sh:232-402`, `docs/governance/document-control.md:124-170`).
- Files: `scripts/validate-docs.sh`, `docs/governance/document-control.md`, `docs/catalog.yaml`
- Risk: Validator changes can silently regress metadata, links, or inventory checks, while documents pass without satisfying declared governance.
- Priority: High

**Repository security and publication gates:**
- What's not tested: Secret leakage, dependency/license/vulnerability state, Markdown style, CODEOWNERS coverage, and reproducibility of the committed documentation/tooling baseline.
- Files: `.gitlab-ci.yml`, `.markdownlint.json`, `.gitlab/CODEOWNERS`, `requirements-docs.txt`
- Risk: Sensitive content, unreviewed policy changes, dependency defects, or irreproducible tooling can enter while the documentation validator passes.
- Priority: High

---

*Concerns audit: 2026-07-15*
