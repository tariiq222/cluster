# Feature Research: Third Health Cluster Administrative Work Platform

**Domain:** Arabic-first, air-gapped enterprise administrative work, strategy, portfolio, project, and risk platform  
**Scope:** Accepted R1-R3 baseline only  
**Researched:** 2026-07-15  
**Confidence:** HIGH for accepted scope and sequencing; no external-market claims are made

## Classification Rules

This document classifies only capabilities already accepted in the project and governance baseline. It does not propose additional scope.

- **Table stake:** required for the owning release to be usable and complete for its stated purpose. A capability can be a table stake for R2 or R3 even though it is not part of R1.
- **Differentiator:** an accepted, mandatory capability that expresses the platform's distinctive value. “Differentiator” does **not** mean optional.
- **Anti-feature:** a capability deliberately prohibited, assigned to another system, or deferred outside R1-R3. It must not enter requirements through implied convenience.
- **Complexity:** relative delivery difficulty for the documented team of 2-4 developers. `HIGH` indicates substantial domain rules, authorization, versioning, reliability, or cross-module contracts; it does not imply deferral.
- **Priority:** every listed table stake and differentiator is `P1` within its owning release. R2 is not allowed to bypass the R1 exit gate, and R3 is not allowed to bypass the R2 exit gate without an explicit sponsor decision.

## Feature Landscape

### Table Stakes — R1 General Administrative Platform

Missing any row below leaves the R1 platform incomplete or blocks its accepted release gate.

| Capability | Why Required | Complexity | Release / Wave | Traceability and measurable evidence |
|---|---|---:|---|---|
| Organization hierarchy, facilities, units, local accounts, lifecycle, and governed organization import | Every record, scope, role, and supervisory relationship depends on a trustworthy organization model and local identity in the air gap | HIGH | R1 / W1.2 | `FR-R1-001`, `002`, `017`-`020`; multi-depth tree; 100% of accounts under an active policy; critical import errors apply no changes |
| Roles, capabilities, delegations, and time-bound supervisory relationships | Users must act within explicit organizational authority; relationships must not grant hidden powers | HIGH | R1 / W1.3 | `FR-R1-003`, `004`; expired relationships withdraw capabilities automatically; all capabilities explicit (`SEC-R1-004`) |
| Backend-enforced RBAC + ABAC isolation | UI hiding is not a security boundary; the same decision must govern API, search, reports, exports, and file access | HIGH | R1 / W1.3 and cross-cutting | `FR-R1-014`, `SEC-R1-003`, `009`; 100% decisions enforced in Laravel; zero cross-department leakage in the security test |
| Dynamic work-type and form builder | The platform must replace one-off email/Excel processes without new code for each administrative type | HIGH | R1 / W1.4 | `FR-R1-005`; publish a usable version without code; at least five work types published at the R1 gate |
| Workflow engine | Administrative work requires approval, rejection, return, branching, escalation, deadlines, and auditable state transitions | HIGH | R1 / W1.5 | `FR-R1-006`; complex workflow scenarios pass; at least five workflows published |
| General internal request as a version-pinned `WorkRecord` | R1 needs a complete reference journey, not infrastructure alone | HIGH | R1 / W1.6 | `FR-R1-007`; draft-to-close journey; submit P95 <= 90 seconds; decision UI P95 <= 30 seconds; cycle P95 <= 5 working days |
| Tasks, ownership, participants, comments, mentions, and approval | Users need clear responsibility and an in-platform alternative to email follow-up | HIGH | R1 / W1.7 | `FR-R1-008`; full task lifecycle; at least 95% of tasks have an owner and due date; no lost update after worker failure |
| Classified, versioned documents attached to records | Administrative evidence must remain attributable, integrity-checked, and access-controlled | HIGH | R1 / W1.8 | `FR-R1-009`; version checksum retained; pilot includes at least 200 documents with multiple versions |
| Durable in-app notifications | Users need assignment, decision, deadline, and mention awareness without external messaging gateways | MEDIUM | R1 / W1.8 | `FR-R1-010`; zero notification loss after committed business changes; read state and grouping supported |
| Scoped search | A work platform is unusable if records cannot be found, and unsafe if forbidden titles or fields leak | HIGH | R1 / W1.9 | `FR-R1-011`; zero forbidden record titles in security tests; organization and field-level policy applied |
| Role-aware dashboards, reports, and secure export | Managers and officers need operational visibility, while every report and export must preserve field and scope restrictions | HIGH | R1 / W1.9 | `FR-R1-012`, `016`; configurable role dashboard; exports respect masking and authorization |
| Sensitive-action audit trail | State changes, privileged access, security events, and governance decisions must be reviewable | HIGH | R1 / cross-cutting | `FR-R1-015`, `SEC-R1-005`, `013`; every sensitive state change logged; secret/sensitive access recorded; hash-linked audit chain |
| Platform settings, language, time zone, and account self-service | Daily operation requires configurable settings and controlled password/session lifecycle without code changes | MEDIUM | R1 / W1.2-W1.9 | `FR-R1-018`, `019`; settings change without code; no administrator can read a user's password; disabling an account ends sessions and delegations immediately |
| Secure, observable, recoverable air-gapped operation | The product is not releasable if its feature journeys depend on the Internet or cannot be restored | HIGH | R1 / W1.1 and every wave | Deny-all egress; internal package/image sources; signed SBOM; P95 reads <= 1.5s; 2,000 concurrent users; RPO <= 15m; RTO <= 2h; >=99.5% monthly availability |

### Table Stakes — R2 Strategy, Indicators, Portfolios, and Projects

R2 begins only after R1 has passed its gate. R2 reuses R1 authorization, tasks, documents, workflows, search, reporting, notifications, and audit rather than rebuilding them.

| Capability | Why Required | Complexity | Release / Wave | Traceability and measurable evidence |
|---|---|---:|---|---|
| Strategic plans, pillars, objectives, and initiatives | Supplies the governed hierarchy to which indicators and later risks attach | HIGH | R2 / W2.1 | `FR-R2-001`; objective linked to a pillar and indicator |
| Indicator definitions, owners, formulas, baseline, periodicity, and immutable definition versions | Measurement is not trustworthy without ownership, formula, period, and version semantics | HIGH | R2 / W2.2 | `FR-R2-002`; indicator definition version retained |
| Distributed targets with mathematical validation | Cluster targets must reconcile with facility targets before approval | HIGH | R2 / W2.2 | `FR-R2-003`, `SEC-R2-001`; 100% of approved distributions pass the sum check |
| Evidence-backed readings and approval | Replaces spreadsheet submissions with a governed measurement cycle | HIGH | R2 / W2.2 | `FR-R2-004`; approved periods lock; reading entry target < 5 minutes; pilot includes >= 80 approved readings |
| Portfolio, program, and project hierarchy with template-pinned lifecycle | Provides the minimum management model for execution across cluster and facilities | HIGH | R2 / W2.3-W2.4 | `FR-R2-005`, `006`; project retains its template version; standard and improvement projects operate |
| Team roles, phases, milestones, evidence, and workflow gates | Projects need accountable execution and governed progression, not a static project list | HIGH | R2 / W2.3-W2.4 | `FR-R2-005`, `007`, `011`; milestones can bind to shared tasks; project gates use the shared workflow engine |
| Evidence-based progress, administrative budget, and guarded health | Managers need interpretable progress, variance, and health without allowing averages to hide critical projects | HIGH | R2 / W2.5 | `FR-R2-007`-`009`, `012`; progress is milestone/evidence based, not task count; variance calculated; critical classification documented |

### Table Stakes — R3 Enterprise Risk

R3 begins only after R2 has passed its gate and the W3.0 risk specification has been approved. Details of matrix, appetite, and review cycles must come from that specification, not implementation inference.

| Capability | Why Required | Complexity | Release / Wave | Traceability and measurable evidence |
|---|---|---:|---|---|
| Scoped risk register with owner and review date | A unified, owned, reviewable register is the base of the R3 value proposition | HIGH | R3 / W3.1 | `FR-R3-001`, `005`; >=95% of risks have owner and review date; pilot has >=60 active risks |
| Inherent and residual assessment using the approved likelihood-impact matrix | Risk levels must be consistently calculated rather than manually asserted | HIGH | R3 / W3.2 | `FR-R3-002`; 100% of new records evaluated by the approved matrix; evaluation P95 <= 2s |
| Control library, effectiveness, and residual-level linkage | Treatment decisions require evidence of what controls exist and how they affect residual risk | HIGH | R3 / W3.3 | `FR-R3-003`; >=90% of pilot risks have controls and a calculated residual level; >=120 controls in pilot |
| Treatment plans implemented through shared tasks | Risk work must become assigned, tracked action rather than duplicated checklist logic | HIGH | R3 / W3.4 | `FR-R3-004`; 100% of R3 treatment plans use `Tasks`; pilot has >=40 active plans |
| Governed response decisions: accept, mitigate, transfer, or avoid | Risk status is incomplete without a documented accountable decision | MEDIUM | R3 / W3.4-W3.6 | `FR-R3-007`, `SEC-R3-002`; 100% of acceptance decisions include reason and owner |
| Scope-aware risk dashboards | Risk officers, facilities, and the cluster need different authorized views over one governed model | HIGH | R3 / W3.7 | `FR-R3-010`; dashboard changes by scope; supports 5,000 active risks without performance regression |

## Differentiators — Mandatory Distinctive Value

These are not optional “nice-to-haves.” They are the accepted capabilities that most directly distinguish the platform from the current email, spreadsheet, and paper operating model.

| Differentiator | Value Proposition | Complexity | Release / Dependencies | Proof required |
|---|---|---:|---|---|
| Arabic-first unified shell with full English and equal RTL/LTR quality | Serves the dominant user language without splitting employees and administrators into separate products; all nine personas use one coherent workspace | HIGH | R1 / all user-facing capabilities | `FR-R1-013`, `NFR-R1-006`; both languages used in pilot; >=90% R1 field acceptance; core task success >=85% per release |
| Four-question work experience: what is mine, when is it due, who waits for me, where is it now | Directly targets lost ownership and status across email and spreadsheets | HIGH | R1 / WorkRecords + Workflow + Tasks + Notifications + Reporting | >=95% of records expose readable owner and state; >=95% of tasks have owner and due date; first request <=5 minutes |
| Explainable organizational access across cluster and facilities | Enables central oversight without dissolving facility isolation; access comes from explicit roles, attributes, supervisory relationships, and shares | HIGH | R1 / Organization + Identity before every business module | Zero data leakage; relationship expiry removes capability; cluster-to-department scope selector respects granted scope |
| No-code, signed, immutable published work definitions and workflows with pinned in-flight records | Allows governed process expansion without code releases while preventing silent behavior changes to active work | HIGH | R1 / Authorization -> WorkDefinitions -> Workflow -> WorkRecords | >=5 published types and workflows; published version remains immutable; existing records remain on their version; definition packages are signature-verified |
| Fully sovereign air-gapped delivery and operation | Preserves data sovereignty and operational independence: no runtime Internet, CDN, cloud license check, or public package/image dependency | HIGH | R1 foundation and every release | Deny-all egress; internal Registry/Composer/npm; signed images and SBOM; offline update record; recovery evidence |
| Governed strategy-to-execution chain | Connects plans, objectives, indicators, portfolios, programs, and projects in one authorized operational system | HIGH | R2 / R1 platform + W2.1 + W2.2 + W2.3 | >=95% projects linked to program/portfolio; >=90% with valid baseline and weights; indicator dashboard P95 <=2s |
| Mathematically bounded, approved improvement impact | Prevents projects from claiming more benefit than the measured indicator improvement and creates an auditable actual-impact record | HIGH | R2 / Indicators + project health and milestones -> W2.6 | >=70% of improvement projects have approved actual impact; attributed impact always <= observed improvement; approval journey <=15 minutes |
| Improvement-method templates with evidence-based progress and guardrail health | Supports accepted health-improvement methods while preventing task-count progress and portfolio averages from masking critical work | HIGH | R2 / W2.4-W2.5 | `FR-R2-006`-`009`, `012`; PDSA, DMAIC, and FOCUS-PDCA supported; pilot exit exercises at least PDSA and FOCUS-PDCA; critical project remains visible |
| Risk KRI reuse and appetite-driven escalation | Strategy owns indicator definition and measurement; Risk owns thresholds, alerts, and escalation, avoiding duplicate measurements | HIGH | R3 / approved W3.0 spec + R2 Indicators + R3 treatment | `FR-R3-006`, `008`, `SEC-R3-001`, `OPS-R3-001`; approved Strategy reading crossing a Risk threshold alerts within <=5 minutes |
| Cross-domain risk linkage to objectives, indicators, and projects | Shows how operational risk threatens strategy and delivery without violating module ownership | HIGH | R3 / R2 Strategy and Projects + R3 KRI | `FR-R3-009`; links validated across ownership boundaries and shown in authorized views |

## Deliberate Anti-Features

### Hard Boundaries Through R1-R3

| Anti-feature | Surface Appeal | Why It Is Deliberately Excluded | Approved Alternative / Rule |
|---|---|---|---|
| Clinical records or patient data | A health organization may expect one comprehensive health system | The platform is administrative, has a different safety/compliance boundary, and must not become an HIS/EMR | Keep clinical work and patient data in dedicated HIS/EMR systems |
| Payroll, leave, promotion, and official HR processing | Employees already perform related administrative work | These are owned by `Mawarid` and would duplicate an authoritative enterprise system | Use `Mawarid`; the platform may not become the HR system of record |
| Full finance, accounting, procurement, invoices, or payment orders | Projects expose administrative budget figures | Financial transactions require specialized controls and ownership beyond this platform | Keep only the accepted administrative project budget fields; use the financial system for authoritative finance/procurement |
| Unspecified external integrations during R1-R3 | Integration promises reduced re-entry | No system, data contract, direction, or security gateway has been approved; the runtime is air-gapped | Operate locally; consider only a named integration with owner, contract, security review, and explicit change decision |
| Runtime Internet, public CDN/font/script, or external SaaS/license check | Reduces hosting effort and eases dependency updates | Violates the hard air-gap and data-sovereignty constraints and can make production unavailable | Host all assets, services, images, packages, and license-validating components internally |
| Separate administrator and employee frontends | Can appear to simplify each UI | Duplicates navigation and policy behavior and contradicts the accepted unified React shell | One responsive, role-aware shell with an administration section |
| Silent migration of active records to a new work or workflow definition | Makes upgrades appear simpler | Changes the governing rules of in-flight work without an auditable decision | Pin records to their published version; migrate only by explicit request and compatibility check |
| Premature microservices, Event Sourcing, or independent event books | Sounds scalable and modern | Adds operational and consistency burden unsupported by the 2-4 person team or accepted separation criteria | Laravel modular monolith, transactional outbox, module contracts/events/IDs/read models; separate only when an approved criterion is met |
| New business modules after R3 by analogy | Reuse may make additions appear cheap | Ownership, boundaries, contracts, and governance are not defined | Treat post-R3 domains as candidates only; require formal evaluation, ownership decision, contracts, and ADR |

### Explicitly Deferred — Do Not Pull Into R1-R3 Requirements

| Anti-feature | Why It May Be Requested | Why It Is Deferred | Accepted Substitute / Revisit Trigger |
|---|---|---|---|
| Historical migration from email or Excel | Users want old history in one place | Cleansing, ownership, and semantics would endanger the initial rollout | Start pilots with new operational data; only the organization hierarchy has a governed CSV/XLSX import |
| Native mobile application | Managers and mobile staff use phones/tablets | Doubles delivery and security surface before the responsive web experience is validated | Responsive browser UI; reconsider after measured need |
| Email, SMS, or WhatsApp notifications | Familiar channels may improve attention | Requires approved gateways and introduces air-gap/security complexity | Durable in-app notifications; reconsider only when a secure gateway is approved |
| OCR, formal archive numbering, or legally recognized electronic signature | Documents are central to administrative work | Needs specialized infrastructure, retention, and legal services | Versioned classified documents with checksum; later specialist capability after governance approval |
| Semantic search, generative AI, or AI decision support | Promises faster discovery and automation | Not necessary for core value and introduces model, data, infrastructure, and explainability scope | Authorized keyword/filter search and configurable reports for R1-R3 |
| Subtasks | Users may want fine-grained decomposition | Explicitly excluded from the first-stage model and absent from accepted R2/R3 release capabilities | One accountable task with participants/comments, or multiple linked top-level tasks; require change control before adding subtasks |

## Feature Dependencies

```text
R1
Air-gapped walking skeleton
  -> Organization + local Identity + governed import
  -> Authorization + supervisory relationships
  -> Versioned WorkDefinitions/form builder
  -> Workflow engine
  -> WorkRecords/general internal request
  -> Tasks
  -> Documents + internal Notifications
  -> authorized Search + Reporting + dashboards + export
  -> R1 field trial and exit gate

R2 (requires passed R1 gate)
Strategy hierarchy -> Indicators + targets + readings -----------+
R1 Tasks -> Portfolio/Program/Project -> improvement templates    |
              -> progress + health + administrative budget ------+-> approved actual impact -> R2 gate

R3 (requires passed R2 gate)
Approved RISK-SPEC
  -> risk register -> assessment -> controls -> treatment via R1 Tasks
  -> KRI thresholds using R2 Indicators -> appetite escalation
  -> links to R2 objectives/indicators/projects -> risk dashboards -> R3 gate
```

### Dependency Notes

- **Authorization precedes every business surface:** search, reporting, exports, downloads, dashboards, and later modules must consume the same backend decision, not invent local policy.
- **Work definitions precede workflows and records:** a request is a published type owned at runtime by `WorkRecords`; there is no separate “Requests” business module.
- **Tasks, documents, notifications, search, and reporting are shared services:** R2 and R3 reuse them through module contracts; they must not build parallel implementations.
- **R2 impact requires both measurement and execution:** W2.6 cannot begin until approved indicator readings (W2.2) and evidence-based project health/progress (W2.5) exist.
- **R3 treatment requires R1 Tasks:** every treatment plan must become shared-service tasks.
- **R3 KRI requires R2 Indicators:** Strategy defines and measures the indicator; Risk owns the KRI link, threshold, alert, and escalation.
- **R3 details require W3.0:** matrix, appetite, and review-cycle rules remain a specification gate. Requirements must not invent them from the high-level feature list.
- **Cross-cutting work is not a final hardening phase:** Arabic/English UX, authorization, audit, air-gap verification, observability, performance, backup/restore, and traceability are part of each wave's definition of done.

## Release Ownership and Exit Gates

### R1 — General Platform

**Release owner:** Product Owner for functional adoption; Software Engineering Lead for performance and architecture; Information Security for isolation; Operations for recovery.  
**Pilot:** one cluster department plus a peer hospital department, 80-150 users, 500-1,500 configured accounts, >=50 real requests, >=100 tasks, >=200 versioned documents, four weeks, both languages.

| Exit evidence | Target | Accountable measurement role |
|---|---:|---|
| Reference request cycle P95 | <= 5 working days | Product Owner |
| Normal read response P95 | <= 1.5s | Software Engineering Lead |
| Published no-code work types | >= 5 | Product Owner |
| Field-test acceptance | >= 90% | Product Owner |
| Access decision enforced in Laravel | 100% | Software Engineering Lead |
| Cross-department leakage | 0 | Information Security |
| Concurrent-user load | 2,000 without collapse | Software Engineering Lead |
| Recovery | RPO <=15m; RTO <=2h | Operations |
| Module-boundary test coverage | >=90% | Software Engineering Lead |

### R2 — Strategy and Portfolios

**Entry gate:** R1 exit complete.  
**Pilot:** one cluster program plus one hospital program, >=10 projects, >=8 approved indicators, >=80 approved readings, >=6 approved target distributions, eight weeks including a complete measurement period.

| Exit evidence | Target | Accountable measurement role |
|---|---:|---|
| Improvement projects with approved actual impact | >=70% | Product Owner |
| Approved target distributions passing sum validation | 100% | Software Engineering Lead |
| Attributed impact vs. observed improvement | Never exceeds observed improvement | Product Owner |
| Improvement templates exercised at exit | At least PDSA and FOCUS-PDCA | Product Owner |
| Indicator dashboard P95 | <=2s | Software Engineering Lead |
| Dashboard rebuild after period | <=5m | Operations |
| Owner/manager field acceptance | >=85% | Product Owner |

**Scope reconciliation:** `FR-R2-006` requires operation of PDSA, DMAIC, and FOCUS-PDCA, while the release exit gate requires at least PDSA and FOCUS-PDCA to be exercised. Requirements should preserve all three accepted templates; the pilot gate's two-template minimum does not delete DMAIC.

### R3 — Enterprise Risk

**Entry gates:** R2 exit complete and W3.0 `RISK-SPEC.md` approved.  
**Pilot:** one cluster risk department plus one hospital risk department, >=60 active risks, >=120 controls, >=40 active treatment plans with tasks, >=10 KRI indicators, six weeks.

| Exit evidence | Target | Accountable measurement role |
|---|---:|---|
| New risks assessed by approved matrix | 100% | Software Engineering Lead |
| Risks with controls and calculated residual level | >=90% | Product Owner |
| R3 treatment plans implemented through Tasks | 100% | Software Engineering Lead |
| Appetite-threshold alert latency | <=5m | Operations |
| Acceptance decisions documenting owner and reason | 100% | Product Owner / Information Security metric |
| Risk-department field acceptance | >=85% | Product Owner |
| Risk evaluation P95 | <=2s | Software Engineering Lead |
| Active-risk capacity | 5,000 without regression | Software Engineering Lead |

## Release Prioritization Matrix

Priority is relative to the owning release; it is not a license to omit later accepted releases.

| Capability group | User / institutional value | Implementation cost | Owning release | Priority |
|---|---|---|---|---|
| Air-gapped skeleton, organization, identity, authorization, isolation | HIGH | HIGH | R1 | P1-R1 |
| Work definitions, workflows, requests, tasks | HIGH | HIGH | R1 | P1-R1 |
| Documents, notifications, search, reporting, audit, bilingual shell | HIGH | HIGH | R1 | P1-R1 |
| Strategy hierarchy, indicators, targets, readings | HIGH | HIGH | R2 | P1-R2 |
| Portfolios, programs, projects, templates, health, budget | HIGH | HIGH | R2 | P1-R2 |
| Approved project-to-indicator impact | HIGH | HIGH | R2 | P1-R2 |
| Risk register, assessment, controls, and treatment | HIGH | HIGH | R3 | P1-R3 |
| KRI, appetite escalation, cross-domain links, risk dashboards | HIGH | HIGH | R3 | P1-R3 |

## Current-State Displacement

The accepted documents identify email, Excel, and paper—not named commercial competitors—as the current alternatives. External competitor analysis is intentionally omitted to avoid speculative scope.

| Current behavior | Failure addressed | Accepted platform response | Success signal |
|---|---|---|---|
| Requests and approvals spread across email | Lost status, ownership, and decision history | Versioned work record + workflow + in-app notification + audit | >=80% of reference transactions start in platform; cycle P95 <=5 days |
| Follow-up through email and informal channels | Work and evidence detached from the record | Owned tasks, participants, comments, mentions, documents | >=50% of observed follow-ups occur in comments; >=95% tasks have owner/due date |
| Indicator readings in scattered spreadsheets | Inconsistent formulas, evidence, and approval | Versioned indicators, distributed targets, evidence-backed readings, locked periods | >=80% of indicators read from platform; 100% approved distributions pass validation |
| Projects claim progress from task counts or narrative | Progress and health are not comparable or explainable | Weighted evidence-backed milestones, guardrail health, administrative budget | >=90% valid baselines/weights; 100% program health explainable |
| Improvement projects claim unbounded benefit | Attributed benefit can exceed measured change | Owner-approved impact capped by observed indicator improvement | >=70% projects with actual impact; cap never exceeded |
| Risks maintained in isolated registers | No unified ownership, treatment, KRI, or strategy linkage | Scoped risk register, controls, treatment tasks, KRI alerts, cross-domain links | >=90% critical risks have active treatment; alert <=5m |

## Requirements Definition Guidance

1. Preserve the `FR`, `NFR`, `SEC`, and `OPS` identifiers in every derived requirement and acceptance test.
2. Treat feature, security, recovery, performance, localization, and field-adoption criteria as one release contract; none is post-launch hardening.
3. Keep each capability in its documented release. Do not pull R2/R3 modules into R1 or new shared capabilities into R3.
4. Reuse R1 platform services in R2/R3 through approved contracts. A later module must not create its own task, document, workflow, notification, search, reporting, or authorization subsystem.
5. Translate anti-features into explicit “shall not” scope statements where ambiguity could invite implementation.
6. Require a Change Request—and an ADR where architecture or module boundaries change—before admitting any deferred or excluded capability.
7. Use real pilot volumes and the listed exit targets as acceptance evidence, not subjective “feature complete” declarations.

## Confidence and Gaps

| Area | Confidence | Basis / limitation |
|---|---|---|
| Accepted R1-R3 capability inventory | HIGH | Cross-checked across accepted vision, roadmap, traceability, journeys, constraints, implementation roadmap, and success metrics |
| Release ownership and exit gates | HIGH | Explicit values and roles in accepted roadmap and metrics documents |
| Dependencies | HIGH | Explicit W1.1-W3.7 dependency graph in the accepted implementation roadmap |
| Anti-features | HIGH | Explicit out-of-scope and hard-constraint lists; alternatives are documented |
| External competitive differentiation | NOT ASSESSED | Deliberately excluded because the task prohibits speculative scope and the accepted baseline names current processes, not competitors |

The principal unresolved feature detail is the R3 matrix, appetite, and review-cycle specification. This is not a research omission: W3.0 explicitly requires an approved `RISK-SPEC.md` before R3 implementation.

## Sources

All sources are internal, accepted, and dated 2026-07-15.

- `.planning/PROJECT.md` — core value, active R1-R3 requirements, constraints, and out-of-scope summary.
- `cluster/docs/product/vision-and-scope.md` §§4-10 — principles, in-scope capabilities, release boundaries, success and failure criteria.
- `cluster/docs/product/personas-and-journeys.md` §§3-6 — nine personas, eleven core journeys, role capabilities, task-time and usability targets.
- `cluster/docs/product/releases-and-roadmap.md` §§4-10 — release capability ownership, pilots, exit gates, ordering, and readiness indicators.
- `cluster/docs/governance/traceability-matrix.md` §§4-8 — authoritative `FR/NFR/SEC/OPS` inventory and measurable verification.
- `cluster/docs/governance/assumptions-constraints.md` §§2-7 — hard constraints, explicit exclusions, approved substitutes, and adoption/security risks.
- `cluster/docs/product/success-metrics.md` §§3-7 — adoption, operational, security, strategy, project, risk, and UX outcomes.
- `cluster/docs/plans/implementation-roadmap.md` §§4-9 — W1.1-W3.7 dependency sequence, cross-cutting ownership, gates, and traceability policy.

---
*Feature research for the Third Health Cluster enterprise administrative platform, R1-R3 accepted scope.*
