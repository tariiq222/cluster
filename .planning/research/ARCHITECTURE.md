# Architecture Research: Third Health Cluster Administrative Platform

**Domain:** Air-gapped enterprise administrative work, strategy/portfolio, and risk platform  
**Researched:** 2026-07-15  
**Confidence:** HIGH for accepted architecture and wave ordering; MEDIUM for operational product choices and plan seams explicitly still requiring a gate decision

## Research Position

This document decomposes the **accepted** architecture; it does not propose a replacement architecture. The roadmap should preserve the 19 canonical modules, their ownership, the dependency DAG, caller-owned transactions, centralized RBAC + ABAC authorization, transactional Outbox delivery, the unified React application, and the three-release/25-wave sequence.

The strongest implementation unit is **one roadmap wave implemented as one deployable vertical capability**. A wave may compose several canonical modules, but it must not create a cross-module “feature module,” shared business service, or data ownership shortcut. The module remains the ownership boundary; a vertical slice is a business use case inside the owning module. Each implementation wave should produce one immutable application release that can be deployed to Staging, demonstrated through the React UI and API, observed, rolled back, and accepted against its exit gate.

Two qualifications matter:

1. **W1.1 is a walking skeleton, not a disposable prototype.** Its thin implementations must already sit behind the final module contracts, transaction boundary, authorization seam, Outbox, deployment path, and air-gap controls. Later waves deepen those implementations without moving ownership.
2. **W3.0 is intentionally a governance/specification gate with no production code.** It should remain the only non-deployment wave rather than creating artificial code merely to satisfy a “deploy every phase” rule.

## Locked Architecture and Deployment Shape

### System Overview

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Users: employee, manager, facility/cluster admin, strategy, PMO, risk       │
└───────────────────────────────┬──────────────────────────────────────────────┘
                                │ Internal HTTPS, Arabic-first RTL/LTR
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ Unified React + TypeScript Web Application                                 │
│ Session/navigation │ scope selector │ module feature UI │ authorized views │
└───────────────────────────────┬──────────────────────────────────────────────┘
                                │ Versioned HTTP API; browser is not trust boundary
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ Laravel Modular Monolith — horizontally replicated Web/API                 │
│                                                                              │
│ Roots/Foundation: PlatformSettings, Organization, Identity, Authorization, │
│                   Audit                                                     │
│ Work platform:    Workflow, RecordsGovernance, WorkDefinitions, Documents, │
│                   Collaboration, Tasks, WorkRecords                         │
│ R2/R3 domains:     Strategy, PortfolioProjects, Risk                        │
│ Derived edges:     Notifications, Search, Reporting, Workspace             │
│                                                                              │
│ Module-first vertical slices; synchronous owner contracts; no cross-table  │
│ joins; source change + Outbox written in one caller-owned transaction       │
└───────────────┬───────────────────────┬──────────────────────┬───────────────┘
                │                       │                      │
        ┌───────▼────────┐      ┌───────▼────────┐     ┌──────▼─────────┐
        │ Queue Workers  │      │ Scheduler      │     │ Outbox Workers │
        │ multiple pods  │      │ singleton/LE   │     │ retry + DLQ    │
        └───────┬────────┘      └───────┬────────┘     └──────┬─────────┘
                └───────────────────────┴──────────────────────┘
                                        │
┌───────────────────────────────────────▼──────────────────────────────────────┐
│ Internal state and operations                                               │
│ MySQL HA (truth + Outbox + Inbox) │ Valkey HA (cache/queue)                 │
│ S3-compatible object storage          │ OpenSearch (rebuildable search)      │
│ Metrics/alerts + Loki logs            │ independent encrypted backup/WORM   │
└──────────────────────────────────────────────────────────────────────────────┘
```

Deployment remains a single product release: one React application, one Laravel application image/role set, and separately scalable web/API and worker processes. The 19 modules are code/data boundaries, **not 19 deployables**. Do not split them into microservices during R1–R3.

### Runtime and Trust Boundaries

| Boundary | Responsibility | Required enforcement |
|---|---|---|
| Browser → Laravel | User interaction and API requests | Authenticated session, server-side authorization on every capability, input validation, CSRF/session controls; React visibility is UX only |
| Laravel module → owner module | Immediate result or shared invariant | Published synchronous contract and stable DTO; dependency points down the DAG; caller owns transaction |
| Source module → derived consumer | Notifications, indexing, workspace, reporting, non-critical audit | Versioned past-tense event in Outbox; at-least-once delivery; consumer Inbox/idempotency; lag/DLQ monitoring |
| Laravel/worker → MySQL | Operational truth and transactional event record | Table/migration ownership by module; same-transaction source + Outbox; optimistic locking where concurrent edits matter |
| Documents → object storage/AV | Binary storage and quarantine | Metadata/checksum/classification in `Documents`; internal malware scan; no public links; reauthorize download |
| Search/reporting → user | Derived cross-module reads | Pre-filter with derived authorization facts, field filtering, reauthorization on source open/export; never become source of truth |
| Cluster → external network | Air-gap boundary | Default-deny egress, internal DNS/resources only, no CDN/SaaS/public registry/runtime download |
| Kubernetes → backup repository | Recovery boundary | Separate account, keys, and failure domain; encrypted PITR/object backups; WORM where retained; tested restore |

## Canonical Component Boundaries

The following ownership is fixed. A wave may expose a composed user journey, but persistence and domain decisions stay with the listed owner.

| Rank | Module | Owns | Must not own or do | First meaningful wave |
|---:|---|---|---|---|
| 0 | `PlatformSettings` | Versioned platform-wide typed settings | Domain-specific work, indicator, project, or risk configuration | W1.1 minimum; hardened continuously |
| 0 | `Organization` | Facilities, org units, positions, assignments, supervisory relationships | Accounts, roles, work records | W1.1 minimum seeded facility scope; full W1.2; supervisory expansion W1.3 |
| 1 | `Identity` | Local accounts, credentials, sessions, operational profile | Org structure or business roles | W1.1 minimum login; full W1.2 |
| 2 | `Authorization` | Roles, capabilities, delegations, classification/field policy, `RecordFacts` schema, explained decisions | Read business tables or depend on business modules | W1.1 thin isolation; full W1.3 |
| 3 | `Audit` | Append-only critical/security audit and sensitive access | User-facing domain activity or mutable business history | W1.1 foundation; expanded every wave |
| 4 | `Workflow` | Immutable workflow versions, instances, steps, decisions, escalation | Meaning of completion in a source domain | Contract seam by W1.4; engine W1.5 |
| 4 | `RecordsGovernance` | Retention, holds, disposition decisions and evidence | Source payload or direct deletion of source records | Minimum policy/registration by W1.6; enforced thereafter |
| 5 | `WorkDefinitions` | Work type/form/field/list/projection definitions and immutable versions | Work instances | W1.1 minimum request definition; full W1.4 |
| 5 | `Documents` | Document metadata, versions, checksum, classification, storage links, download access | Embed files in business tables or inherit access blindly | Minimum linked evidence W1.7; full W1.8 |
| 6 | `Collaboration` | Threads, comments, mentions, participants, subscriptions | Task or source state | W1.7 |
| 7 | `Tasks` | Task, one assignee, source reference, due date, priority, lifecycle, close rule | Source payload, comments, mentions, or attachments | W1.7 |
| 8 | `WorkRecords` | Dynamic work instance envelope, payload, state, participants, typed projections, `lock_version` | Work definition, workflow execution, task, or document | W1.1 thin instance; full W1.6 |
| 8 | `Strategy` | Plans, objectives, initiatives, indicators, periods, targets, measurements, approved project impact | Project or risk records | W2.1; indicators W2.2; impact W2.6 |
| 9 | `PortfolioProjects` | Portfolios, programs, projects, templates, milestones, baseline, progress/health/budget snapshots | Initiatives or indicators/measurements | W2.3–W2.6 |
| 10 | `Risk` | Risks, assessments, controls, treatments, KRI links/thresholds/alerts, escalation state | Indicator definitions/measurements, projects, objectives, or tasks | W3.1–W3.6 after W3.0 |
| 11 | `Notifications` | In-app notification, recipient/read state, preferences, event Inbox | Source authorization or source payload copy | Thin W1.1; full W1.8; extended by later producers |
| 11 | `Search` | Rebuildable index documents, checkpoints, index Inbox, derived auth facts | Operational truth or unauthorized snippets | W1.9; extended in R2/R3 |
| 11 | `Reporting` | Report/dashboard definitions, rebuildable read models, authorized export | Indicators or writes to business sources | W1.9; extended each domain wave |
| 11 | `Workspace` | Personal/organization inbox projections and saved views | Transition source state on behalf of owner | First projection W1.6; task expansion W1.7; unified W1.9 |

### Non-negotiable dependency rules

1. Synchronous imports may target only a lower rank and only the owner’s `Contracts`/published DTOs.
2. Modules at the same rank do not depend on one another.
3. No module imports another module’s `Domain`, `Features`, `Infrastructure`, ORM model, migration, table name, or query builder.
4. No cross-owner join, subquery, or business foreign key. Cross-boundary links use IDs or `{record_type, record_id}` and validate through contracts when needed.
5. Cross-domain reads use a narrow synchronous contract, a local event projection, or a `Reporting` read model/projection feed.
6. `Shared` contains only neutral primitives such as clock, identifiers, transaction/Outbox support, correlation metadata, and technical result types—never business policies or owner DTOs.
7. Derived modules are terminal consumers. A source module never calls `Search`, `Reporting`, `Workspace`, or `Notifications` synchronously inside its write transaction.

## Recommended Monorepo Structure

Exact top-level names may follow the selected Laravel/React tooling, but the ownership shape should be visible in the filesystem and testable in CI:

```text
repository/
├── backend/
│   ├── app/
│   │   ├── Modules/
│   │   │   ├── Organization/
│   │   │   │   ├── Domain/
│   │   │   │   ├── Contracts/
│   │   │   │   ├── Events/
│   │   │   │   ├── Infrastructure/
│   │   │   │   └── Features/<BusinessVerb>/
│   │   │   │       ├── Command.php | Query.php
│   │   │   │       ├── Handler.php
│   │   │   │       ├── Http/
│   │   │   │       └── Tests/
│   │   │   └── <all other canonical modules>/
│   │   └── Shared/                 # technical primitives only
│   ├── database/migrations/<owner>/ # ownership mechanically visible
│   └── tests/Architecture/          # DAG/import/SQL/table ownership guards
├── frontend/
│   └── src/
│       ├── app/                     # shell, session, routing, i18n, scope
│       ├── features/<business-use-case>/
│       ├── modules/<canonical-module>/
│       ├── api/                     # generated/typed API clients
│       └── design-system/           # direction-neutral UI primitives
├── deploy/
│   ├── base/                        # immutable workload definitions
│   └── overlays/{dev,test,staging,prod}/
├── ops/                             # alerts, dashboards, runbooks, restore tests
├── tests/e2e/                       # wave journeys and isolation regressions
├── docs/                            # accepted specifications and ADRs
└── Makefile                         # verify-build, verify-airgap, boundary gates
```

**Rationale:** Backend structure follows the accepted module-first vertical-slice pattern. Frontend features may compose APIs from several owners, but the frontend never becomes a domain owner. Keeping migrations under an owner and architecture tests centrally discoverable makes forbidden coupling fail in CI rather than in review. Deployment and operational evidence live beside code because air-gap, recovery, and observability are release properties, not post-release work.

## Core Architectural Patterns

### 1. Module-first vertical slice

**What:** A business verb such as `SubmitWorkRecord`, `ApproveIndicatorMeasurement`, or `AssessRisk` is implemented end-to-end inside its owner: HTTP input, command/query, handler, domain invariant, persistence, event, and tests. The React feature invokes the API and displays the result.

**Use:** Every roadmap capability. A phase can include several owner slices to complete one journey, but each slice remains in its owner.

**Trade-off:** More explicit contracts and composition work than a shared service layer, but ownership remains stable through R2/R3 and later extraction remains possible if evidence supports it.

### 2. Caller-owned transaction with synchronous owner contracts

**What:** The outermost write handler opens the MySQL transaction and decides commit/rollback. Any lower-rank contract called synchronously joins that transaction and never commits independently. Required invariants are completed before commit.

```text
SubmitWorkRecord handler (transaction owner)
  ├─ WorkDefinitions.GetPublishedWorkTypeSchema()
  ├─ Authorization.DecideAccess(actor, capability, trusted RecordFacts)
  ├─ Workflow.StartWorkflow(subject_ref, context)       # joins transaction
  ├─ persist WorkRecords-owned state
  ├─ append WorkRecordSubmitted to Outbox
  └─ commit once
```

**Use:** Immediate authorization, reference validation, manager resolution, workflow start, or atomic task creation.

**Do not use:** Remote/network calls, search indexing, notifications, dashboards, or other eventually consistent side effects.

### 3. Transactional Outbox and idempotent terminal consumers

**What:** A source stores its change and versioned event in the same MySQL transaction. After commit, workers deliver at least once. Each consumer records `event_id` in an Inbox/checkpoint before applying an idempotent effect.

```text
source transaction: [business row(s)] + [outbox event]
                               │ commit
                               ▼
outbox worker → event schema → consumer Inbox → projection/message
                    retry/DLQ/lag metrics        └─ safe on duplicate
```

**Use:** Notifications, search documents, workspace items, reporting projections, and non-critical audit.

**Constraint:** An event describes a fact that already happened; it is not a hidden command. A delayed event cannot be the only mechanism protecting a business invariant.

### 4. Central authorization without a dependency cycle

**What:** The owner loads its minimal non-sensitive envelope, builds trusted `RecordFacts`, and calls `Authorization`. `Authorization` resolves identity/org facts through lower-rank modules and returns decision, allowed fields, scope predicate, and explanation code. It never calls back into the business owner.

**Use:** Single-record commands/queries, collection queries, downloads, search, reports, dashboards, and exports.

**Constraint:** Clients never supply authoritative `RecordFacts`. Collection paths use `BuildAuthorizedScopePredicate`/authorized scope sets, not per-row browser filtering. Explicit deny and higher classification override general allow.

### 5. Immutable published definitions with pinned running instances

Published work type, workflow, strategic plan/indicator where specified, and project template versions are immutable. An instance stores its version ID at creation/start. A new version affects only new instances unless a separately authorized, compatibility-checked migration is defined. Never silently rewrite running work.

### 6. Derived read models with rebuild contracts

`Search`, `Reporting`, `Workspace`, and `Notifications` store only rebuildable derivatives. Each projection has an event schema version, Inbox/checkpoint, rebuild command, freshness/lag metric, and authorization-aware output contract. Large rebuilds use paginated owner-provided projection feeds rather than direct source-table access.

## Explicit Data Flows

### Flow A — Authorized command/write

```text
React feature
  → Laravel HTTP endpoint in owning module
  → validate request and authenticate actor
  → owner loads trusted envelope / builds RecordFacts
  → Authorization.DecideAccess
  → command handler opens transaction
  → domain invariant + lower-rank synchronous contracts
  → owner tables + Outbox event
  → commit
  → response DTO filtered by allowed fields
  → after-commit workers update terminal projections
```

Failure before commit rolls back source and Outbox together. Failure after commit is retried by the relevant consumer and does not reverse the source truth.

### Flow B — Authorized collection/read

```text
React list/dashboard request
  → owner query / Search / Reporting
  → Authorization.BuildAuthorizedScopePredicate or derived auth pre-filter
  → query owner-local store or authorized read model
  → apply field policy and classification
  → return stable DTO
  → source endpoint reauthorizes when record/link is opened
```

Search results must not leak title, snippet, count, or facet from an unauthorized record. Export repeats field-level authorization; it does not inherit permission merely because a dashboard was visible.

### Flow C — Work record submission and workflow

1. `WorkRecords` loads the pinned schema from `WorkDefinitions` and validates the dynamic payload.
2. `WorkRecords` builds `RecordFacts` and obtains the submit decision from `Authorization`.
3. The `WorkRecords` handler owns the transaction; it asks `Workflow` to start against a generic `subject_ref` and resolution context.
4. `Workflow` owns its instance/steps; `WorkRecords` owns the business state. `WorkflowCompleted` does not secretly complete a work record.
5. An explicit coordinator/command transitions `WorkRecords` when workflow outcome requires a business-state change.
6. `WorkRecordSubmitted/StateChanged` events update `Workspace`, `Notifications`, `Search`, and `Reporting` after commit.

### Flow D — Document upload and download

```text
Upload: owner endpoint authorizes link target
  → Documents.CreateDocument/AddVersion
  → object stored internally in quarantine + checksum/metadata
  → internal AV scan
  → available only after clean result
  → DocumentLinked event after commit

Download: request Documents.AuthorizeDocumentDownload
  → evaluate document classification + strongest linked-record restriction
  → reauthorize source access
  → record sensitive access in Audit
  → issue short-lived internal delivery response; never a public URL
```

### Flow E — Project impact on an indicator (R2)

1. `PortfolioProjects` owns expected impact and its local project planning link by `indicator_id`.
2. It validates/reads the indicator through a `Strategy` contract; it never copies definition or measurements.
3. `PortfolioProjects.SubmitProjectImpactToStrategy` submits evidence and attribution.
4. `Strategy` owns approval and enforces that attributed impact does not exceed observed improvement unless an authorized documented exception applies.
5. `ProjectIndicatorImpactApproved` updates both project and indicator read models after commit.

### Flow F — KRI threshold and risk escalation (R3)

1. `Strategy` approves an indicator measurement and emits a versioned fact/reference.
2. `Risk` consumes the approved measurement reference, resolves the authoritative value through the published `Strategy` contract/feed as specified, and evaluates its own KRI threshold.
3. `Risk` owns threshold status, alert history, and escalation state—not indicator definition or measurement.
4. A breach emits `RiskIndicatorThresholdBreached`; `Notifications` creates in-app alerts.
5. `Risk` starts its configured `Workflow` escalation through a synchronous owner contract and records the resulting risk transition/audit trail.

## Integration Contract Catalog

| Contract style | Owner | Payload rule | Consistency | Required tests |
|---|---|---|---|---|
| Synchronous published contract | Provider module | Stable DTO/errors; no ORM/query builder/table name | Same process; joins caller transaction for writes | Provider contract tests + consumer compatibility tests |
| Published event | Source module | Past tense, minimum data, `event_id`, source ref, occurred time, schema version, correlation/causation | At-least-once after commit | Schema compatibility, duplicate delivery, retry and DLQ |
| Projection feed | Source module | Authorized, paginated, versioned rebuild DTO | Eventual; resumable | Pagination, checkpoint resume, authorization, rebuild parity |
| Reference by ID | Referenced owner | Stable ID only; summary resolved via contract | Validate when business invariant requires it | Missing/retired/unauthorized reference cases |
| Generic record reference | Source owner | `{record_type, record_id}` plus non-sensitive relation metadata | Reauthorize on open | Cross-scope denial and stale-link behavior |
| HTTP API | Owning module | Versioned request/response DTO and explanation/error codes | Command or query semantics explicit | HTTP positive/negative auth, invariant, localization |

Contract evolution rules:

- Additive compatible change is preferred; incompatible change gets a new version and an explicit overlap/removal window.
- The producer owns event meaning and schema; consumers never infer source tables.
- Every event-producing wave includes at least one consumer duplicate/replay test.
- Correlation and causation IDs cross HTTP, transaction, Outbox, worker, audit, and logs without copying sensitive payloads.
- API/event contracts must support Arabic/English presentation without embedding translated display text as domain truth.

## Deployable Build Order Mapped to the 25 Waves

### R1 — Establish the reusable administrative platform

| Wave | Deployable vertical capability | Owning modules and integration | Exit evidence and cross-cutting gate |
|---|---|---|---|
| **W1.1 Walking Skeleton** | Offline-built signed release: login → publish minimal `request` definition → create two work records in separate facilities → prove isolation → consume Outbox into an in-app notification | Minimum real slices in `Organization` (seeded facility scope), `Identity`, `WorkDefinitions`, `WorkRecords`, `Authorization`, `Notifications`; `Audit`, `PlatformSettings`, Outbox primitives; empty canonical module shells only where no behavior exists | Browser-to-DB-to-worker E2E on Staging; signed image + SBOM; no external fetch; default-deny egress; architecture DAG test; GitOps deploy/rollback. Do not use a temporary “Requests” table/module |
| **W1.2 Organization + Identity + Import** | Administrator imports and approves structure/accounts; user logs in and changes effective scope | `Organization` owns tree/positions/assignments/import references; `Identity` owns account/session; React scope selector composes owner APIs | Fourteen org invariants; maker-checker import; account disable revokes sessions; UTC storage; migration rollback/restore sample; no `Organization → Identity` dependency |
| **W1.3 Authorization + supervisory relations** | Explained allow/deny across unit, facility, relationship, classification, field, and delegation | `Authorization` publishes decision/scope/field contracts; `Organization` adds supervisory relationships; `Audit` records sensitive decisions/access | Fourteen isolation scenarios, fail-closed tests, API/field/export negatives, delegated actor + principal evidence. Authorization must remain independent of business modules |
| **W1.4 WorkDefinitions + form builder** | Admin drafts, sandboxes, validates, signs, and publishes a work type version; existing instances remain pinned | `WorkDefinitions`; optional workflow association only through `Workflow.ValidateWorkflowVersion`; `Authorization` field templates; `Audit` publication | Immutable version tests, signed definition package, no free code/expression, RTL/LTR builder, production/sandbox separation. A type may publish without full workflow until W1.5 |
| **W1.5 Workflow Engine** | Admin publishes a workflow; a reference subject completes approval/reject/return/escalation/timeouts including parallel/quorum cases | `Workflow` owns definitions/execution; `Organization` resolves approvers; `Authorization` authorizes decisions; `Audit` critical decisions | Ten workflow scenarios, safe expression allowlist, pinned version, vacancy fallback, scheduler singleton/leader election, Outbox failure does not lose source transaction |
| **W1.6 WorkRecords request journey** | General internal request moves draft → submit → approval/processing → return/reject/complete across the three reference organization journeys | `WorkRecords` orchestrates `WorkDefinitions`, `Workflow`, and auth; minimum `RecordsGovernance` registration/retention resolution; `Workspace` creates My Requests/department inbox projections | Three end-to-end journeys, optimistic conflict test, pinned definition/workflow, isolation/field negatives, projection rebuild, no workflow-owned business completion |
| **W1.7 Tasks + collaboration** | Independent/linked task can be assigned, discussed, mentioned, evidenced, submitted, accepted, and completed | `Tasks` owns task state; `Collaboration` owns comments/mentions/participants; `Documents` supplies minimum evidence/link contract; `Organization`/`Identity` resolve assignment; `Workspace` adds task inbox | Full task lifecycle and out-of-chain denial; mention changes participation, not assignee/state; source access does not flow from task access. Resolve plan wording in favor of canonical `Collaboration` ownership |
| **W1.8 Documents + Notifications** | Classified versioned document is scanned, linked, securely downloaded/audited; event notifications aggregate and survive worker failure | Full `Documents`, `Notifications`; `RecordsGovernance`, `Authorization`, `Audit`; internal object storage/AV | Secret-document journey, strongest-restriction rule, malware quarantine, checksum/version tests, reauthorization on notification open/download, duplicate/retry/DLQ test, backup includes object consistency |
| **W1.9 Search + Reporting + unified workspace/dashboard** | Authorized search and role/scope dashboards over R1 work; exports respect field policy | `Search`, `Reporting`, mature `Workspace`; consume source events/projection feeds; `Authorization` for scope/fields | No title/snippet/count leakage; authorized export test; read-model rebuild/freshness; no raw JSON heavy reporting; P95 and load baseline; scope selector changes all views |
| **W1.10 UAT + R1 launch** | Deploy R1 to one cluster department and one hospital peer department and operate it | No new domain ownership; harden all R1 modules and operational system | Readiness 100% evidence, external penetration test acceptable, 2,000 concurrent-user test, node failure, restore with RPO ≤15m/RTO ≤2h, eight-user usability, runbooks/training/Go signatures |

**R1 ordering implication:** Foundation must be delivered through a thin end-to-end skeleton first, then deepened in dependency-rank order. `Audit`, `RecordsGovernance`, `Workspace`, localization, and operations cannot be postponed simply because they lack standalone waves. They enter at the first dependent journey and grow incrementally.

### R2 — Extend through new owners, not through R1 schema changes

| Wave | Deployable vertical capability | Owning modules and integration | Exit evidence and cross-cutting gate |
|---|---|---|---|
| **W2.1 Strategy foundation** | Create, approve, activate, and retire a versioned strategic plan with axes, objectives, and initiatives | `Strategy` uses existing `Workflow`, `Tasks`, `Collaboration`, `Documents`, governance, auth, and audit contracts | Plan CRUD/version journey, hierarchy invariants, owner access, source events projected into workspace/reporting. Project-link proof cannot precede W2.3; test the contract seam now, full bidirectional journey later |
| **W2.2 Indicators** | Define/version indicators, distribute targets, open periods, submit evidence, approve and lock measurements | `Strategy` remains sole indicator owner; reuse `Organization`, `Workflow`, `Documents`; extend derived consumers | Distribution/aggregation arithmetic, evidence required, approval/lock audit, scope isolation, formula allowlist, dashboard P95 ≤2s target from project constraints |
| **W2.3 Portfolio + Program + Project** | Create a cross-organization project from a pinned template inside portfolio → program → project hierarchy | `PortfolioProjects` owns hierarchy/template/project; references `Strategy` initiatives through contract/ID; reuses R1 services | Cross-organization project journey, one owning org unit, configurable roles, pinned template, workflow gates. Complete W2.1’s deferred bidirectional initiative-project proof here |
| **W2.4 Improvement templates** | Publish and run PDSA first, then DMAIC, FOCUS-PDCA, and internal templates with evidence gates | `PortfolioProjects` template/version slices; `Workflow` approval; `Documents` evidence | PDSA end-to-end before broader templates; immutable template versions; no free-form executable code; RTL/LTR usability review |
| **W2.5 Progress, health, budget** | Baseline project, approve milestones, calculate weighted progress/health and administrative budget; aggregate portfolio with guardrails | `PortfolioProjects` owns calculations/snapshots; `Reporting` owns dashboards only | Weights =100%, progress from approved milestones not task count, red critical project cannot be averaged away, override reason/audit, 1,000-project calculation/load evidence |
| **W2.6 Impact linkage** | Submit and approve actual project contribution to an indicator; navigate project ↔ indicator dashboards | `PortfolioProjects` owns planning/expected impact; `Strategy` owns observed measurement and approved attribution; read models project both | Attribution ≤ observed improvement unless documented authorized exception; no copied indicator truth; contract/event compatibility and replay; dual authorization on links |
| **W2.7 UAT + R2 launch** | Operate two programs, 25 indicators, target distribution, 10 projects, and approved impact | Harden `Strategy`, `PortfolioProjects`, derived consumers, and reused R1 capabilities | Green readiness, 500 concurrent PM simulation, R2 load focus, restore drill, penetration test, training/usability, complete strategy-to-impact cycle and Go signatures |

**R2 ordering implication:** Build `Strategy` before `PortfolioProjects` because Strategy owns initiatives and indicators consumed by projects. Even where dependency notation permits parallel preparation, the small team should preserve wave gates and limit parallel work to non-overlapping UI, projection, test, and operational tasks. Do not generalize R1 `WorkRecords` into projects or indicators; R2 introduces its accepted first-class owners while reusing platform contracts.

### R3 — Add risk only after its policy model is approved

| Wave | Deployable vertical capability | Owning modules and integration | Exit evidence and cross-cutting gate |
|---|---|---|---|
| **W3.0 Risk specification** | **No deployment by design.** Approve matrix, appetite, categories, review cadence, escalation, acceptance authority, required metadata, and dashboard expectations | Governance artifact constrains the already-approved `Risk` module; no new module or boundary | Approved `RISK-SPEC.md`, decision record, capability/field matrix, examples usable as acceptance fixtures. No production code starts before approval |
| **W3.1 Risk register** | Create and govern cluster/facility/unit risk registers and risks with owner, source references, state, and lifecycle | `Risk` owns records; references `Organization`; reuses auth/audit/governance/search contracts | CRUD/invariants, soft disposition only through governance, scoped visibility, source refs without copied goal/project data. Immutable security audit stays in `Audit`; Risk may keep user-facing domain activity |
| **W3.2 Assessment** | Record inherent/residual assessments, compare with approved appetite, snapshot and reassess | `Risk` domain slices based on W3.0 policy | Matrix arithmetic, appetite warning, reasoned reassessment, historical snapshot, export projection. Control-driven recalculation seam must be specified now and proven fully in W3.3 |
| **W3.3 Control library** | Create reusable controls, assess effectiveness, link to multiple risks, and trigger residual reassessment | Still inside `Risk`; no extra Control module | Self/independent review, expiry, effectiveness changes, multi-risk link, residual recalculation regression. This closes the W3.2 control-change acceptance dependency |
| **W3.4 Treatment plans** | Create Accept/Mitigate/Transfer/Avoid treatment; mitigation creates/links R1 tasks and closes only with completed evidence | `Risk` owns treatment; `Tasks` owns task lifecycle; `Documents` evidence; `Authorization` enforces high-risk acceptance | Task completion contract, evidence, authorized acceptance, reassessment after treatment, idempotent links, no task state copied into Risk |
| **W3.5 KRI and escalation** | Link Strategy indicators as KRIs, evaluate approved measurements against Risk thresholds, alert and escalate critical risk | `Strategy` owns indicator/measurement; `Risk` owns KRI link/threshold/breach/escalation; `Workflow` and `Notifications` reused | Approved-measurement breach → Risk threshold → notification → escalation journey; no indicator/measurement tables in Risk; alert grouping/silence; scoped dashboards/P95 ≤2s target |
| **W3.6 Objective/project linkage** | Link risk to strategy objective/indicator/project and navigate bidirectionally to treatments | `Risk` owns relationship meaning; `Strategy`/`PortfolioProjects` own referenced records; `Reporting` projects links | ID/contract only, delete reason, dual-side authorization, no copied names/definitions as truth, objective → risk → treatment journey |
| **W3.7 UAT + R3 launch** | Operate 100 risks across target units with controls, treatments, KRIs, links, dashboards, and tabletop escalation | Harden all modules; no architectural expansion | Green readiness, end-to-end escalation tabletop, R3-focused load/recovery/security tests, training/usability, 100-risk/5-control/10-treatment/15-KRI evidence, Go signatures |

**R3 ordering implication:** W3.0 is a hard policy gate, not a reason to reconsider the canonical `Risk` boundary. W3.2 and W3.3 are two vertical increments within that same module: first assessment and appetite, then reusable controls and control-driven residual reassessment. Strategy remains the only indicator owner throughout.

## Cross-Cutting Work Required in Every Implementation Wave

Every phase plan should contain these work packages and evidence. They are acceptance criteria, not a final hardening backlog.

| Gate | Every-wave requirement | Release-level escalation |
|---|---|---|
| Architecture | New slice under canonical owner; DAG/import/table/SQL guard passes; contract/event test updated | Full dependency and ownership audit |
| Security | Positive and negative server authorization; field/classification checks; trusted `RecordFacts`; sensitive access audit where applicable | Penetration test and formal security sign-off |
| Data | Owner migration with forward/backward strategy; optimistic locking where concurrent; no destructive version change; test fixtures | Restore drill, retention/legal hold review, RPO/RTO evidence |
| Async reliability | Source + Outbox rollback test; consumer duplicate/retry test; lag/DLQ metric and runbook | Worker restart/node-failure and projection rebuild exercise |
| Air gap/supply chain | Build only from internal mirrors; lockfile/digest; signed image; SBOM; no external URL/resource; default-deny egress stays green | Offline build/deploy proof and admission verification |
| Observability | Structured correlation/causation without sensitive payload; use-case latency/error metric; queue/projection health where added | SLO dashboard, alert drill, capacity/load report |
| UX/localization | React path in unified shell; Arabic default; English and RTL/LTR; accessible errors; scope selector compatibility | Eight-user usability report and training material |
| Testing | Unit + handler + HTTP + contract + architecture + at least one journey; explicit failure cases | Release E2E suite, performance, failover, rollback |
| Governance | REQ-ID/TEST-ID traceability, architecture deviation logged, deferred decision checked before dependent work | Readiness checklist, CCB evidence, signed Go/No-Go |

### Phase “done” contract

A wave is deployable only when all are true:

1. A user can complete the promised vertical outcome in the unified React shell against the real Laravel API.
2. The outcome persists only in owner tables and can be retrieved through an authorized owner/derived read path.
3. Success, forbidden access, invariant failure, duplicate event, and rollback behavior are automated.
4. The immutable artifact is built offline, signed, has an SBOM, is promoted through GitOps, and can roll back without manually mutating stateful resources.
5. Metrics/logs/alerts identify failure without exposing sensitive payloads.
6. Migrations, projections, runbooks, and traceability evidence are part of the same wave.
7. The wave exit gate is demonstrated on Staging; release waves additionally pass the full readiness checklist.

## Air-Gap, Resilience, and Recovery Build Strategy

### W1.1 must establish the permanent path

- Controlled dependency intake → internal Composer/npm/base-image/AV mirrors → offline CI.
- CI produces immutable image digest, SBOM, scan/license results, provenance, and internal signature.
- GitOps is the only write path to Staging/Prod; admission rejects unsigned/unapproved images.
- Production is network/account/permission-separated from lower environments.
- Web/API and workers run as multiple replicas; scheduler is singleton or leader-elected.
- MySQL HA, Valkey HA, internal object storage, search, logs, metrics, alerts, and secrets/PKI remain internal.
- Backup repository is encrypted and independent of the Kubernetes failure domain.

### Every subsequent wave uses—not recreates—the path

New dependencies must pass controlled intake before coding relies on them. New event consumers add lag/DLQ alerts. New stored data joins backup/restore verification. New document types join object consistency and retention checks. New read models add deterministic rebuild procedures. No release is accepted merely because Kubernetes reports healthy pods; the business journey, authorization, projection freshness, and recovery evidence must also be healthy.

## Scaling Within the Accepted Architecture

| Concern | Initial/pilot | Target 5k–20k accounts / 2k concurrent | Trigger for later architectural review |
|---|---|---|---|
| Web/API | Multiple stateless Laravel replicas | HPA from measured CPU/latency; connection pool and query tuning | Only consider service extraction after measured independent scaling/team/reliability need and a new ADR |
| Workers | Separate queues/process roles | Scale by queue depth/lag; isolate expensive projection/rebuild queues | Persistent noisy-neighbor or deployment independence need |
| MySQL | HA from first deploy | Proper owner-local indexes, pagination, short transactions, PITR; no reporting over raw JSON | Proven write/size limit after query/schema/archival optimization |
| Search | Small rebuildable index | Shards/replicas sized from measured corpus; auth pre-filter; reindex versioning | Product choice/capacity decision before production gate |
| Reporting | R1 projections | Incremental read models and paginated feeds for R2/R3; cache authorized aggregates | Separate analytics platform only after R3 scope/ADR |
| Object storage | Internal compatible store | Version/checksum/scan lifecycle, replication, backup consistency | Capacity/failure-domain evidence, not domain decomposition |
| Authorization | Direct decisions with tested scope predicates | Cache only safe/invalidation-aware facts; batch/scope predicates for lists | Never bypass for performance; optimize contract implementation |

The first likely bottlenecks are unauthorized N+1 authorization/query patterns, broad WorkRecord JSON scans, projection lag, and report queries on operational tables—not the modular monolith process boundary. Address them with scope predicates, typed projections, indexes, pagination, read models, queue isolation, and load tests before considering microservices.

## Plan Seams That Must Be Resolved During Phase Planning

These are not requests to redesign accepted ADRs. They are places where detailed wave wording must be interpreted according to the higher-authority architecture so implementation does not violate ownership.

| Seam | Required interpretation |
|---|---|
| W1.1 creates WorkRecords before later dependencies are feature-complete | Implement the minimum real owner slices and stable contracts; optional capabilities remain unused. Do not create a temporary cross-module model or bypass authorization/Outbox |
| W1.4 precedes full Workflow in W1.5 | Work type publication may be workflow-optional, or validate against a minimal published workflow contract already present. W1.6 publishes/pins the fully linked request version |
| W1.7 plan wording places comments/mentions/attachments near Tasks | The user-facing task journey composes `Tasks` + `Collaboration` + `Documents`; canonical ownership remains with the latter two |
| `RecordsGovernance` and `Workspace` have no standalone waves | Introduce their minimum deployable slices at first dependency (W1.6), then increment projections/policies in later waves |
| W2.1 mentions bidirectional initiative-project proof before W2.3 projects | Define/test the Strategy-side reference contract in W2.1; complete real bidirectional acceptance in W2.3 |
| W3.1 mentions an AuditLog in Risk | Risk may own user-facing domain activity; immutable critical/security audit remains in `Audit` |
| W3.2 expects control-change behavior before W3.3 control library | Define the assessment/control-effect seam in W3.2; W3.3 supplies the real control aggregate and closes the control-driven recalculation proof. Do not invent a new module |
| Some detailed release SLOs are looser than PROJECT constraints | Plan and gate to the stricter accepted project constraint: R1 reads P95 ≤1.5s and R2 dashboards/R3 assessment P95 ≤2s, while retaining journey-specific tests |
| Operational documents name products but mark choices proposed/deferred | Treat capability/air-gap/recovery requirements as locked; close actual Kubernetes/storage/search/logging product decisions at their documented gates before production dependency |

## Anti-Patterns to Prevent

### Horizontal foundation phases with no user journey

**Mistake:** Build database, generic services, auth, then UI in separate horizontal phases.  
**Consequence:** Integration and isolation failures surface late; no phase is deployable.  
**Instead:** Each wave includes React → API → owner domain → persistence → Outbox/projection → observability and acceptance.

### A “Requests” module or project/risk as generic WorkRecords

**Mistake:** Create a `Requests` aggregate or force R2/R3 specialized domains into dynamic records.  
**Consequence:** Violates canonical ownership and loses specialized invariants.  
**Instead:** Request is a published `request` type plus `WorkRecord`; Strategy, PortfolioProjects, and Risk remain first-class modules.

### Shared business kernel / generic repository / generic workflow service

**Mistake:** Put DTOs, policies, repositories, or domain services in `Shared`.  
**Consequence:** Hidden cycles and ownerless truth.  
**Instead:** Owner-published contracts and neutral shared technical primitives only.

### Direct cross-module joins and ORM leakage

**Mistake:** “It is one database, so join it.”  
**Consequence:** Schema coupling defeats modular ownership and makes later R2/R3 evolution unsafe.  
**Instead:** Owner contract, ID reference, local projection, or Reporting read model.

### Authorization only in UI or source endpoint

**Mistake:** Hide controls in React or assume search/report/export/download inherits source permission.  
**Consequence:** Organizational/classification data leak.  
**Instead:** The same centralized decision semantics on every output path, with reauthorization on source open and sensitive export/download.

### Event as hidden command

**Mistake:** Let a delayed consumer make a mandatory domain transition.  
**Consequence:** Invariant depends on queue health and duplicate ordering.  
**Instead:** Explicit command/coordinator for business transition; events update terminal side effects after commit.

### Treating derived stores as truth

**Mistake:** Update WorkRecords from Search/Workspace or approve impact from a reporting snapshot.  
**Consequence:** Stale projections corrupt business decisions.  
**Instead:** Return to the owner contract for transitions and authoritative values.

### Deferring air-gap, restore, localization, or observability to release wave

**Mistake:** Make them W1.10/W2.7/W3.7 hardening.  
**Consequence:** Unsigned/unbuildable dependencies, unrecoverable data, late RTL rework, and unmeasurable gates.  
**Instead:** Establish in W1.1 and increment evidence in every wave.

## Roadmap Recommendations

1. **Represent the accepted 25 waves as the primary roadmap phases.** Combining several waves into one planning phase would hide gate dependencies and make vertical acceptance too broad for a 2–4 developer team.
2. **Use one canonical end-to-end journey per wave as its spine.** Add only the owner slices and cross-cutting work necessary to make that journey production-shaped.
3. **Keep R1’s platform modules reusable but not speculative.** Implement the minimum contract needed by the current journey; do not prebuild R2/R3 tables or generic abstraction layers.
4. **Treat release UAT waves as deployment/operational validation phases, not feature dumping grounds.** Functional work must already be complete before W1.10, W2.7, and W3.7.
5. **Carry an architecture conformance checklist into every phase plan:** owner, rank, allowed dependencies, contract/event version, transaction owner, authorization facts, table ownership, projection rebuild, air-gap artifact, and recovery impact.
6. **Require an explicit decision record at deferred gates** for Kubernetes/storage before W1.1, monitoring before W1.5, retention before first production type, backup before real data, security classification before R1 generalization, and RISK-SPEC before R3 code.

## Confidence and Research Gaps

| Area | Confidence | Basis / gap |
|---|---|---|
| Module ownership and DAG | HIGH | Repeated consistently in accepted overview, module catalog, context map, dependency rules, and ADRs |
| Transaction, Outbox, authorization data flows | HIGH | Explicit accepted architecture and ADRs with enforcement rules |
| 25-wave build order | HIGH | Accepted implementation roadmap and accepted R1–R3 release plans |
| Vertical phase decomposition | HIGH | Direct synthesis of accepted waves plus module-first vertical-slice engineering rules |
| Exact Kubernetes/storage/search/logging products | MEDIUM | Capabilities are locked; product selections are proposed/deferred and must close at gates |
| W1.4/W1.5, W2.1/W2.3, W3.2/W3.3 plan seams | MEDIUM | Architecture resolves ownership, but phase planners must make acceptance timing explicit |
| Capacity tuning values | MEDIUM | Targets are accepted, but sizing must be proven by representative load and restore tests |

## Sources

Primary accepted sources (highest authority for this research):

- `docs/architecture/overview.md` — accepted architecture, runtime shape, legal modules, transactions, access, deployment.
- `docs/architecture/module-catalog.md` — canonical ownership, contracts, events, ranks, and exclusions for all 19 modules.
- `docs/architecture/context-map.md` — direct dependency matrix, owner facts, integration patterns, and key flows.
- `docs/architecture/dependency-rules.md` — DAG, allowed imports/queries, caller-owned transaction, and CI enforcement.
- `docs/architecture/c4-and-flows.md` — interpretation of synchronous/asynchronous flows and transaction ownership.
- `docs/architecture/non-functional-requirements.md` — capacity, recovery, security, air-gap, scaling, observability, and maintenance gates.
- `docs/plans/implementation-roadmap.md` — accepted 25-wave sequence, cross-cutting tracks, deferred-decision gates, and delivery governance.
- `docs/plans/release-1-platform.md`, `release-2-strategy-portfolio.md`, `release-3-risk.md` — wave-level scope and acceptance.
- `docs/plans/readiness-checklist.md` — functional, operational, security, data, performance, air-gap, UX, legal, and Go/No-Go evidence.
- `docs/adr/004-authorization-and-isolation.md`, `007-transactional-outbox.md`, `018-air-gapped-supply-chain.md`, `019-kubernetes-resilience-and-recovery.md` — accepted decisions.

Supporting draft/proposed sources (used only where consistent with accepted architecture):

- `docs/engineering/vertical-slices.md` and `docs/engineering/coding-and-module-boundaries.md` — implementation shape and architecture tests.
- `docs/operations/air-gap-supply-chain.md`, `kubernetes-platform.md`, `ha-dr-backup.md` — operational process detail; named product choices remain subject to documented gates.

---
*Architecture research for: Third Health Cluster enterprise administrative platform*  
*Researched: 2026-07-15*
