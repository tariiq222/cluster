# Project Research Summary

**Project:** منصة التجمع الصحي الثالث (Third Health Cluster Administrative Platform)  
**Domain:** Arabic-first, air-gapped enterprise administrative work, strategy/portfolio/project, and risk platform  
**Researched:** 2026-07-15  
**Confidence:** HIGH for accepted scope, architecture, and wave order; MEDIUM for stack versions and deferred operational products

## Executive Summary

This is a regulated internal administrative platform, not a generic workflow SaaS and not a clinical, HR, finance, or integration system. The accepted product scope is R1 general administrative work, R2 strategy/indicators/portfolios/projects, and R3 enterprise risk. Experts should build it as the accepted production-shaped **Laravel modular monolith with 19 canonical modules**, one Arabic-first React/TypeScript shell, one MySQL operational database, centralized explainable RBAC+ABAC, immutable published definitions with pinned instances, caller-owned transactions, a transactional Outbox, and rebuildable derived read models. The air gap, recovery targets, localization, observability, security, and field adoption are product requirements in every wave—not later hardening.

The roadmap must preserve the accepted **25-wave order from W1.1 through W3.7**. Each implementation wave is a deployable vertical capability through the real React UI, Laravel API, owner module, MySQL transaction, Outbox/projection path, offline supply chain, observability, tests, rollback, and traceability evidence. W1.1 is the permanent walking skeleton; W1.10, W2.7, and W3.7 are release-validation waves rather than feature catch-up; W3.0 is intentionally a no-code governance gate. R2 cannot bypass the R1 gate, and R3 cannot bypass the R2 and W3.0 gates, except where the accepted governance process permits an explicit sponsor decision.

The principal risks are authorization leakage through search/reports/exports/files, stale organizational authority, mutation of published definitions, duplicate or missing asynchronous effects, an air gap that cannot reproduce builds or recovery, and an operating burden beyond a 2–4 developer team. Prevent them with executable negative gates from W1.1 onward, strict module ownership, atomic Outbox/inbox semantics, early isolated restore drills, representative Arabic-first user validation, and named platform/security/operations ownership. Exact ecosystem versions and named infrastructure products are **evaluation candidates only** until official compatibility, licensing/support, internal-mirror availability, security, capacity, backup, and air-gap validation close their documented gates.

## Authority and Reconciliation Rules

1. `.planning/PROJECT.md` and accepted documents under `cluster/docs/` are authoritative for scope, architecture, constraints, and ordering.
2. `STACK.md`, `FEATURES.md`, `ARCHITECTURE.md`, and `PITFALLS.md` interpret that baseline; they do not supersede accepted decisions or turn proposed products into approvals.
3. The accepted R1–R3 scope, 19 module boundaries, and 25-wave sequence are locked for roadmap creation.
4. Exact package patches, Kubernetes distribution, storage product, MySQL Operator matrix, Vault edition, observability sizing, retention, backup destination, and final security classification remain gated decisions.
5. Where detailed planning artifacts expose different pilot fixture counts or looser SLOs, phase planning must use the stricter accepted project constraint and reconcile the discrepancy through the canonical release/readiness source and CCB evidence; it must not silently choose the easier value.

## Key Findings

### Recommended Stack

The locked technology **shape** is a Laravel modular monolith, unified React/TypeScript SPA, MySQL 8.4 LTS operational database, internal queue/cache, object storage, search, observability, secrets, package mirrors, OCI registry, and GitOps delivery. Node is build/test only; browser assets are static and all production dependencies are internal. Laravel primitives should implement module boundaries, authorization semantics, workflow definitions, Outbox/inbox, audit, and PII handling rather than delegating those architectural concerns to convenience packages. (`PROJECT.md`; `STACK.md`; `ARCHITECTURE.md`)

The researched versions below are a **candidate intake baseline, not locked roadmap decisions**. Before freezing images or lockfiles, verify each direct and transitive dependency against official support matrices and the exact internal build/test environment, licensing policy, mirrored artifact availability, and security approval. Commit lockfiles only after that controlled validation. (`STACK.md` §§Executive Recommendation, Version Compatibility, Open Verification Gates)

**Candidate core baseline requiring validation:**

- **Laravel 13.20 / PHP 8.5.8** — candidate backend/runtime pair aligned with the accepted Laravel architecture; validate PHP extensions, Fortify/Sanctum/Horizon, test tooling, transitive packages, and supported patches internally.
- **React 19.2.7 / TypeScript 6.0.3 / Vite 8.1.4** — candidate unified SPA toolchain; TypeScript 6 is recommended because the researched lint matrix excludes TypeScript 7, but the complete React/MUI/Emotion/RTL/codegen/test matrix still needs intake testing.
- **Node 24 LTS** — candidate build/test image only; it must not enter the production PHP runtime image.
- **MySQL 8.4 LTS** — accepted database line; the exact server, Router, Shell, Operator, and backup-image combination is unresolved and must be approved as one tested matrix.
- **Valkey, OpenSearch, S3-compatible object storage, Loki/Prometheus/Grafana, OpenTelemetry, and Vault** — accepted capability roles or researched candidates; exact products/patches, support, licensing, HA, retention, sizing, and recovery remain gate decisions.
- **GitLab, Nexus, Harbor, Argo CD, Cosign, Syft, Trivy, and Kyverno** — researched offline supply-chain baseline; named versions require the same internal support/security/import validation and do not override accepted platform decisions.

**Stack rules that are already authoritative:**

- One deployable modular monolith and one React shell; no early microservices, Event Sourcing, SSR/Node runtime, public CDN/SaaS, or runtime public registries.
- Backend authorization is authoritative on API, lists, search, reports, exports, notifications, and downloads; frontend visibility is UX only.
- Business state and Outbox event commit atomically; consumers are idempotent and observable.
- Production artifacts come only from controlled internal mirrors, are pinned by lockfile/digest, scanned, SBOM-attested, signed, and promoted through GitOps.
- Use real MySQL for integration behavior; do not treat SQLite as equivalent for locking, JSON, collation, or transaction tests.

### Expected Features

All table stakes and differentiators in the owning release are P1; “differentiator” does not mean optional. (`FEATURES.md`)

**R1 must have — general administrative platform:**

- Organization hierarchy, facilities/units, governed import, local identity/session lifecycle, and time-bounded supervisory relationships.
- Centralized explainable RBAC+ABAC, delegations, classification/field policy, complete organizational isolation, and immutable sensitive-action audit.
- Versioned no-code work types/forms and workflows with signed immutable publication and pinned in-flight records.
- A complete general internal request as a `WorkRecord`, plus Tasks, Collaboration, classified/versioned Documents, durable in-app Notifications, Workspace, authorized Search, Reporting, dashboards, and secure export.
- One Arabic-first responsive shell with complete English and equal RTL/LTR quality.
- Air-gapped, observable, recoverable operation meeting R1 P95 ≤1.5s, 2,000 concurrent users, RPO ≤15 minutes, and RTO ≤2 hours, proven by a two-department field trial.

**R2 must have — strategy and execution:**

- Versioned strategic plans, pillars/objectives/initiatives, indicators, formulas, owners, periods, distributed targets, evidence-backed readings, approvals, and locked periods.
- Portfolio/program/project hierarchy, pinned project templates, team roles, phases, milestones, shared workflow/task/document evidence, improvement methods, weighted evidence-based progress, guarded health, and administrative budget.
- Approved project impact attributed to Strategy-owned measurements, mathematically bounded so claimed contribution never exceeds observed improvement except under an explicitly authorized documented rule.

**R3 must have — enterprise risk:**

- An approved W3.0 risk specification before code, then a scoped register, assessments, approved likelihood-impact matrix, controls/effectiveness, residual risk, treatment decisions and plans through shared Tasks, KRI thresholds over Strategy indicators, appetite-driven alerts/escalation, cross-domain links, and scope-aware dashboards.

**Mandatory differentiators:**

- Four-question work experience: what is mine, when is it due, who is waiting for me, and where is it now.
- Explainable cluster/facility access without dissolving organizational isolation.
- Governed no-code expansion without silently changing active work.
- Fully sovereign offline build, deployment, operation, patching, and recovery.
- Governed strategy-to-execution chain with bounded improvement attribution.
- Risk KRI reuse and objective/indicator/project linkage without duplicating source ownership.

**Explicitly exclude or defer beyond R1–R3:**

- Clinical/patient records; payroll/leave/promotion; authoritative finance/procurement; unspecified external integrations.
- Historical email/Excel migration other than governed organization import; native mobile app; email/SMS/WhatsApp notifications.
- OCR, formal archive numbering, legal e-signature, semantic search, generative AI, and AI decision support.
- Separate admin frontend, browser bearer tokens, silent active-record migration, premature microservices/Event Sourcing, and post-R3 modules without ownership/contracts/ADR approval.

### Architecture Approach

Use module-first vertical slices inside the accepted 19-module dependency DAG. The outer write handler owns one MySQL transaction; lower-rank owner contracts join it; source state and Outbox event commit together; remote or derived effects occur after commit through idempotent consumers. Modules never import another owner’s domain/infrastructure/ORM or join another owner’s tables. Cross-boundary collaboration uses stable contracts/DTOs, IDs, versioned events, or authorized rebuildable read models. (`ARCHITECTURE.md`)

**Major component groups:**

1. **Foundation:** `PlatformSettings`, `Organization`, `Identity`, `Authorization`, `Audit` — typed settings, organizational/identity truth, explainable access decisions, and immutable critical evidence.
2. **R1 work platform:** `Workflow`, `RecordsGovernance`, `WorkDefinitions`, `Documents`, `Collaboration`, `Tasks`, `WorkRecords` — immutable definitions, execution, records, evidence, participation, and accountable work.
3. **R2/R3 domain owners:** `Strategy`, `PortfolioProjects`, `Risk` — specialized first-class invariants; these must not be forced into generic WorkRecords.
4. **Derived terminal modules:** `Notifications`, `Search`, `Reporting`, `Workspace` — rebuildable, lag-observed, authorization-aware projections that never become source of truth.
5. **Unified delivery/runtime:** one React shell; replicated Laravel web/API and worker roles; MySQL truth/Outbox/Inbox; internal cache/queue, object storage, search, telemetry, and independent encrypted recovery store.

**Non-negotiable patterns:**

- Trusted `RecordFacts` are built server-side; authorization does not call business modules or trust client facts.
- Published definitions/templates and governed measurements are versioned; running instances remain pinned; migration is explicit, compatible, authorized, audited, and reversible.
- Search/reporting prefilter with derived authorization facts and reauthorize sensitive open/export/download paths; forbidden titles, snippets, counts, and facets must never leak.
- Every projection has schema version, Inbox/checkpoint, deterministic rebuild, freshness/lag metric, and replay tests.
- W1.1 uses final contracts and ownership seams; no temporary `Requests` module, cross-module model, or disposable delivery path.

### Critical Pitfalls

1. **Cross-surface authorization leakage** — enforce one fail-closed decision model and a shared negative scenario corpus across CRUD, collections, search, reports, exports, notification links, and files; reauthorize sensitive access.
2. **Stale organization or delegated authority** — use effective-dated facts, immediate session/cache invalidation, governed import, policy/facts versions, and temporal boundary tests.
3. **Mutable published definitions** — separate draft/publish, use copy-on-write immutable versions and digests, pin instances, and reject silent migration.
4. **Duplicate/missing asynchronous effects** — commit state plus Outbox atomically; enforce consumer `event_id` uniqueness/deterministic keys; test crash points, replay, DLQ, lag, and scheduler leadership.
5. **False air-gap or recovery confidence** — prove clean-cache offline build, patch, promotion, rollback, and isolated full restore including DB, objects, keys, configuration, signed artifacts, audit continuity, and projection reconciliation.
6. **Module-boundary erosion and late performance fixes** — architecture-test imports/SQL/FKs continuously; use owner-local indexes, authorized scope predicates, typed projections, pagination, read models, and representative load tests before schema shapes harden.
7. **Small-team operational overload and weak adoption** — provide named platform/SRE/security support, two-person runbook execution, one-wave WIP, and continuous Arabic-first user evidence; training attendance is not adoption.
8. **Unapproved domain semantics** — decide R2 formula/rounding/allocation rules before dependent work and keep W3.0 a hard no-code risk-governance gate with approved golden cases.

## Implications for Roadmap

### Required 25-Phase Structure

The roadmapper should map one accepted wave to one primary phase. Combining waves would hide dependencies and make acceptance too broad for the team. The order below is authoritative. (`implementation-roadmap.md`; `ARCHITECTURE.md`; `FEATURES.md`)

| # | Phase / wave | Delivers and rationale | Principal gate/pitfall to avoid |
|---:|---|---|---|
| 1 | **W1.1 Walking Skeleton** | Permanent offline React→Laravel→MySQL→Outbox→notification path with two isolated request instances, signed artifact, GitOps deploy/rollback | Close pre-W1.1 platform/intake decisions; no disposable path, temporary module, connected build, or non-idempotent worker |
| 2 | **W1.2 Organization + Identity + Import** | Multi-depth structure, local accounts/sessions, governed maker-checker import, scope selection | 14 organization invariants; temporal facts, cycles, disable/revocation, rollback |
| 3 | **W1.3 Authorization + Supervisory Relations** | Explained RBAC+ABAC decisions, scopes, fields, delegations, classification | 14 isolation scenarios and fail-closed negative matrix; no business-module policy forks |
| 4 | **W1.4 WorkDefinitions + Form Builder** | Draft/sandbox/sign/publish immutable work type and form versions | Old instances unchanged; constrained DSL only; no executable code or silent migration |
| 5 | **W1.5 Workflow Engine** | Versioned approval/reject/return/escalation/deadline/parallel-quorum execution | Monitoring decision closed; safe expression allowlist, scheduler leadership, pinning and retry semantics |
| 6 | **W1.6 WorkRecords Request Journey** | Complete general request from draft to close plus minimum governance and workspace projection | Owner-controlled completion, optimistic conflict, early restore drill, version/outbox/audit consistency |
| 7 | **W1.7 Tasks + Collaboration** | Independent/linked accountable tasks, comments, mentions, participants, evidence seam | Keep canonical `Tasks`/`Collaboration`/`Documents` ownership; task access never grants source access |
| 8 | **W1.8 Documents + Notifications** | Classified versioned files, quarantine/scan/download audit, durable grouped in-app events | Object-store/scanner validation, strongest linked restriction, duplicate/retry/DLQ and backup consistency |
| 9 | **W1.9 Search + Reporting + Workspace/Dashboards** | Authorized search, role/scope dashboards, reporting and secure export | No title/count/field leakage, projection rebuild/freshness, Arabic relevance, target-scale authorization |
| 10 | **W1.10 UAT + R1 Launch** | Two-department field trial and full functional/security/air-gap/load/recovery/adoption evidence | No new feature dumping; zero leakage, P95/RPO/RTO/load targets, penetration and Go/No-Go evidence |
| 11 | **W2.1 Strategy Foundation** | Versioned strategic hierarchy and Strategy-side project contract seam | Reuse R1 services; do not pre-implement PortfolioProjects or duplicate workflows/tasks |
| 12 | **W2.2 Indicators** | Versioned indicators, distributed targets, periods, evidence readings, approval/lock | Decimal/unit/period/formula/rounding/correction rules and approved-only reporting |
| 13 | **W2.3 Portfolio + Program + Project** | Pinned project lifecycle in portfolio/program hierarchy and real initiative-project proof | `PortfolioProjects` ownership, ID/contracts only, cross-organization authorization |
| 14 | **W2.4 Improvement Templates** | PDSA, DMAIC, FOCUS-PDCA and internal template operation | Immutable constrained templates, evidence gates, Arabic/RTL usability; no executable definitions |
| 15 | **W2.5 Progress + Health + Budget** | Weighted evidence-based progress, guardrail health, administrative budget and portfolio rollups | Decimal rules, weights=100%, critical project cannot be averaged away, representative load |
| 16 | **W2.6 Impact Linkage** | Strategy-approved project contribution to observed indicator improvement | Attribution bounded/deduplicated, source truth not copied, dual authorization and replay compatibility |
| 17 | **W2.7 UAT + R2 Launch** | Two-program strategy-to-impact pilot with R1 regression gates repeated | Approved readings/distributions/impact, P95≤2s, rebuild/recovery/security/adoption and Go evidence |
| 18 | **W3.0 Risk Specification** | No-code approval of taxonomy, matrix, appetite, controls, reviews, treatment, KRI, escalation and golden cases | Absolute entry gate; developers must not invent risk semantics |
| 19 | **W3.1 Risk Register** | Scoped risk registers, ownership, source references and lifecycle | Risk owns records; immutable security audit stays in `Audit`; no copied strategy/project truth |
| 20 | **W3.2 Assessment** | Versioned inherent/residual assessments and appetite comparison | Store governing spec and inputs; define control-recalculation seam without inventing W3.3 data |
| 21 | **W3.3 Control Library** | Reusable controls, effectiveness, multi-risk links and residual reassessment | Close W3.2 recalculation proof; keep one canonical `Risk` module |
| 22 | **W3.4 Treatment Plans** | Accept/mitigate/transfer/avoid decisions; mitigation through shared Tasks and Documents | High-risk authority/evidence, idempotent task links, no copied task state |
| 23 | **W3.5 KRI + Escalation** | Strategy measurement → Risk threshold → notification/workflow escalation | Strategy retains indicator truth; threshold deduplication, alert latency≤5m, scoped P95≤2s views |
| 24 | **W3.6 Objective/Indicator/Project Linkage** | Authorized bidirectional risk links and objective→risk→treatment journey | IDs/contracts/read models only, dual-side authorization, delete reason and no copied names as truth |
| 25 | **W3.7 UAT + R3 Launch** | Two-risk-department operational pilot, dashboards, tabletop escalation and full readiness rerun | Reconcile accepted pilot fixtures; 5,000-risk capacity, security/recovery/air-gap/adoption and Go evidence |

### Phase Ordering Rationale

- The dependency chain is architectural: sovereign delivery → organization/identity → authorization → immutable definitions → workflow → records → shared work services → derived reads.
- R2 reuses a proven R1 platform; Strategy owns initiatives/indicators before PortfolioProjects consumes them, and impact waits for both approved measurements and evidence-based project progress.
- R3 waits for R2 and approved risk semantics; treatment reuses R1 Tasks, while KRI behavior consumes approved R2 Strategy measurements.
- Cross-cutting security, architecture, data, async reliability, air gap, observability, localization, testing, recovery, and governance work belongs in every applicable phase exit contract.
- Release waves validate accumulated evidence and real operation; they cannot compensate for incomplete functional waves.

### Mandatory Decision Gates

| Deadline | Decision/evidence that must close | Consequence if unresolved |
|---|---|---|
| **Before W1.1** | Kubernetes distribution and storage choice; exact supported MySQL server/Router/Shell/Operator/backup matrix; candidate app/toolchain compatibility and internal artifact intake; air-gap import/signing/key custody; platform/SRE/security operating model | Do not freeze base images or start the permanent deployment path |
| **Before W1.5** | Internal monitoring/logging products and supported deployment/sizing baseline | Workflow timers/workers cannot receive an accepted operational gate |
| **Before first production work type** | Retention/disposition policy for that type (7 years is only the documented default) | Do not publish the type to production |
| **Before real data** | Independent encrypted WORM-capable backup destination, key recovery, complete recovery manifest, and successful isolated restore | Do not admit real data |
| **Before R1 generalization** | Formal final security classification/reclassification and resulting controls | Do not expand beyond approved pilot scope |
| **At W1.10** | Green R1 functional, isolation, security, performance, recovery, air-gap, usability, and adoption evidence | Do not start R2 unless an explicit sponsor exception is recorded under governance |
| **At W2.7** | Green R2 evidence with R1 regression gates repeated | Do not start R3 implementation |
| **At W3.0** | Governance-approved `RISK-SPEC.md`, decision record, capability/field matrix, and golden acceptance cases | No R3 production code |
| **After R3** | Ownership, boundaries, contracts, and an independent ADR for any candidate domain | Do not add a new canonical module by analogy |

### Research Flags

**Use `/gsd-plan-phase --research-phase` or equivalent focused research/validation:**

- **W1.1:** exact platform topology, product support/licensing, MySQL matrix, offline supply chain, PKI/signing, backup failure domain, and tested dependency matrix.
- **W1.4:** constrained definition DSL, signing, sandboxing, compatibility analysis, and migration governance.
- **W1.5:** workflow expression safety, parallel/quorum behavior, timers/time zones, scheduler leadership, and migration semantics.
- **W1.8:** selected object-store consistency/Object Lock, malware scanner/signature intake, quarantine, and recovery behavior.
- **W1.9:** Arabic analyzers/normalization/relevance corpus, authorization prefilter design, no-metadata-leak testing, rebuild and scale.
- **W2.2, W2.5, W2.6:** domain-approved decimal, formula, unit, period, rounding, correction, weighting, health, allocation, and attribution rules.
- **W3.0, W3.2, W3.3, W3.5:** formal risk semantics, golden calculations, control-driven reassessment, KRI thresholds, alert deduplication, and escalation.

**Well-documented patterns; focused planning can normally skip broad external research:**

- **W1.2, W1.3, W1.6, W1.7:** accepted invariants, ownership, flows, and gates are detailed; validate draft supporting documents before treating details as approved.
- **W2.1, W2.3, W2.4 and W3.1, W3.4, W3.6:** module ownership and cross-module contract patterns are explicit; research only unresolved domain detail.
- **W1.10, W2.7, W3.7:** use accepted readiness and pilot evidence; effort belongs in execution, testing, restore, security, usability, and Go/No-Go rather than stack exploration.

## Confidence Assessment

| Area | Confidence | Notes |
|---|---|---|
| Stack | MEDIUM | Architecture fit and capability roles align with accepted docs, but exact package patches and operational products require official/internal compatibility, licensing, mirror, security, and support validation. External version evidence in `STACK.md` is explicitly LOW under its provider classifier. |
| Features | HIGH | R1–R3 inventory, dependencies, anti-features, pilots, and gates are grounded in accepted internal product, traceability, constraints, metrics, and roadmap documents. |
| Architecture | HIGH | The 19 owners, DAG, centralized authorization, caller-owned transactions, Outbox, unified shell, and 25-wave order recur across accepted architecture, ADR, and roadmap sources. Exact platform products and a few phase seams remain MEDIUM. |
| Pitfalls | HIGH | Failure modes map directly to accepted constraints and executable gates. Some supporting threat/operations documents remain draft or proposed, and final R3 semantics are intentionally unavailable until W3.0. |

**Overall confidence:** HIGH for roadmap structure and scope; MEDIUM for implementation stack freeze and unresolved operational/domain decisions.

### Gaps to Address

- **Stack freeze:** Re-verify all candidate versions and transitive dependencies against official matrices and the controlled internal environment before lockfiles/base images are approved.
- **Platform products:** Resolve Kubernetes/storage, exact MySQL Operator matrix, Vault edition/support, object storage, observability retention/sizing, backup/WORM/KMS, and air-gap advisory/import SLAs at their gates.
- **Document status:** Formally approve or supersede supporting draft/proposed threat model, authorization detail, coding-boundary, and air-gap operating procedures before their optional detail becomes implementation policy.
- **Operational capacity:** Name platform/SRE/security owners beyond the 2–4 application developers and prove two-person deploy, restore, key-rotation, and incident response.
- **Workflow migration:** Define compatibility, dry-run, approval, rollback, task/timer mapping, and explicit refusal semantics; immutable pinning alone does not specify migration.
- **Arabic search:** Build a representative corpus and validate analyzer behavior, relevance, authorization filtering, highlight suppression, and rebuild/load performance.
- **R2 semantics:** Obtain owner-approved decimal/formula/rounding/correction/allocation rules before W2.2/W2.5/W2.6.
- **R3 semantics:** Produce the full approved W3.0 risk specification and golden cases; current certainty is only that this is mandatory.
- **Pilot fixture reconciliation:** Supporting research cites different detailed minimum/scenario volumes for some R2/R3 trials. Phase plans must reconcile them against the accepted release/readiness documents and retain the stricter applicable evidence rather than weaken a gate.
- **Adoption baselines:** Define completion, shadow-process, support-dependency, and notification-to-action baselines before each pilot; training attendance is insufficient.

## Sources

### Primary — accepted internal authority (HIGH confidence)

- `.planning/PROJECT.md` — R1–R3 scope, constraints, exclusions, performance/recovery targets, and authority of `cluster/docs/`.
- `cluster/docs/plans/implementation-roadmap.md` — accepted 25-wave order, dependencies, cross-cutting tracks, milestones, deferred decisions, gates, and traceability.
- `cluster/docs/product/vision-and-scope.md`, `releases-and-roadmap.md`, `personas-and-journeys.md`, `success-metrics.md` — product boundaries, users, pilots, outcomes, and acceptance.
- `cluster/docs/governance/traceability-matrix.md`, `assumptions-constraints.md` — FR/NFR/SEC/OPS evidence and hard constraints.
- `cluster/docs/architecture/overview.md`, `module-catalog.md`, `context-map.md`, `dependency-rules.md`, `c4-and-flows.md`, `non-functional-requirements.md` — canonical modules, ownership, data flows, and quality gates.
- Accepted ADRs including authorization/isolation, dynamic WorkRecords, transactional Outbox, air-gapped supply chain, and Kubernetes resilience/recovery.
- `cluster/docs/plans/release-1-platform.md`, `release-2-strategy-portfolio.md`, `release-3-risk.md`, `readiness-checklist.md` — detailed wave and release evidence.

### Research synthesis inputs

- `.planning/research/STACK.md` — candidate application/tooling/platform baselines, compatibility limits, offline supply chain, and open validation gates.
- `.planning/research/FEATURES.md` — accepted table stakes, mandatory differentiators, anti-features, dependencies, pilots, and metrics.
- `.planning/research/ARCHITECTURE.md` — canonical components, contracts, data flows, scaling, phase seams, and wave-level vertical slices.
- `.planning/research/PITFALLS.md` — critical failure modes, early warnings, prevention/recovery tests, and phase mapping.

### Secondary / validation-only

- Official project release/support pages and package registries listed in `STACK.md` — version candidates only; re-check through controlled intake.
- OWASP, SLSA, NIST, and CISA references listed in `PITFALLS.md` — corroborating security/supply-chain/recovery practices; accepted internal requirements remain authoritative.
- Draft/proposed engineering, threat, authorization-detail, and operations documents listed by the dimension files — usable only where consistent with accepted decisions until formally approved.

---
*Research completed: 2026-07-15*  
*Ready for roadmap: yes — preserve all 25 waves and close mandatory gates at their stated deadlines.*
