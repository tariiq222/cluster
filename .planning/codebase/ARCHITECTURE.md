<!-- refreshed: 2026-07-15 -->
# Architecture

**Analysis Date:** 2026-07-15

## System Overview

This repository currently implements an authoritative documentation and contract package, not the Laravel/React product described by that package. The authority chain starts at `docs/README.md`, identifies the architectural source of truth in `docs/architecture/overview.md`, and catalogs document status in `docs/catalog.yaml`. No PHP, TypeScript, Laravel manifest, frontend manifest, container manifest, or product test source exists in the repository root.

```text
CURRENT IMPLEMENTED REPOSITORY
┌─────────────────────────────────────────────────────────────┐
│                 Governed documentation package              │
│  `README.md` → `docs/README.md` → `docs/catalog.yaml`       │
├──────────────────┬──────────────────┬───────────────────────┤
│ Architecture     │ Domain/security  │ Plans/operations      │
│ `docs/architecture/` │ `docs/domain/`  │ `docs/plans/`       │
│ `docs/adr/`      │ `docs/data-security/` │ `docs/operations/`│
└────────┬─────────┴────────┬─────────┴──────────┬────────────┘
         │                  │                     │
         ▼                  ▼                     ▼
┌─────────────────────────────────────────────────────────────┐
│ Executable contracts and diagram sources                    │
│ `docs/contracts/` and `docs/architecture/diagrams/`         │
└─────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────┐
│ Documentation validation, rendering, and publication        │
│ `scripts/`, `.gitlab-ci.yml`, `mkdocs.yml`, `build/`        │
└─────────────────────────────────────────────────────────────┘
```

```text
DOCUMENTED TARGET PRODUCT (NOT IMPLEMENTED)
┌─────────────────────────────────────────────────────────────┐
│ Unified Arabic-first React + TypeScript web application     │
│ Logical shell and module route contributions                │
└──────────────────────────────┬──────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ Laravel modular monolith                                    │
│ 19 canonical modules, module-first vertical slices, CQRS    │
│ Logical module shape: `Modules/<Module>/`                   │
└───────────────┬────────────────────┬────────────────────────┘
                │                    │
                ▼                    ▼
┌──────────────────────────┐  ┌───────────────────────────────┐
│ MySQL source of truth    │  │ Outbox → Valkey Streams      │
│ module-owned tables      │  │ → idempotent consumers       │
└──────────────────────────┘  └──────────────┬────────────────┘
                                             ▼
                              ┌───────────────────────────────┐
                              │ Search/reporting/workspace,   │
                              │ notifications, object storage│
                              └───────────────────────────────┘
```

The documented target overview and binding decisions are in `docs/architecture/overview.md`; the target containers and deployment shape are in `docs/architecture/diagrams/containers.mmd` and `docs/architecture/diagrams/deployment.mmd`. The logical module folder shape is defined in `docs/engineering/vertical-slices.md`, but no authoritative document assigns that shape to a concrete Laravel filesystem root such as `app/Modules/`.

## Component Responsibilities

### Implemented Repository Components

| Component | Responsibility | File |
|-----------|----------------|------|
| Documentation entry point | Declares `docs/` as the current package and directs readers to architecture and governance | `README.md` |
| Documentation index | Provides the sole entry point to approved documentation and its subject-specific sources of truth | `docs/README.md` |
| Catalog | Records status, owner, phase, source-of-truth status, and generated status for governed artifacts | `docs/catalog.yaml` |
| Architecture package | Defines target boundaries, module DAG, deployment, flows, and non-functional verification goals | `docs/architecture/README.md` |
| ADR registry | Records accepted, superseded, proposed, and rejected architectural decisions | `docs/adr/README.md` |
| Domain specifications | Defines commands, queries, invariants, events, ownership, and failure behavior for target modules | `docs/domain/README.md` |
| Contract package | Defines the target HTTP API, event transport, and JSON schemas | `docs/contracts/README.md` |
| Engineering package | Converts architecture decisions into target coding, testing, migration, and release rules | `docs/engineering/README.md` |
| Operations package | Defines the proposed air-gapped runtime, resilience, observability, and recovery design | `docs/operations/README.md` |
| Documentation validator | Validates front matter, catalog coverage, links, YAML/JSON, shell syntax, and repository documentation rules | `scripts/validate-docs.sh` |
| Diagram renderer | Converts authoritative Mermaid sources into local SVG output | `scripts/render-diagrams.sh` |
| Documentation CI | Runs validation, strict MkDocs build, and optional Mermaid rendering | `.gitlab-ci.yml` |

### Documented Target Components

| Component | Responsibility | File |
|-----------|----------------|------|
| Unified React shell | Serves all roles from one Arabic-first, bilingual, RTL/LTR application; module routes and navigation contribute through contracts | `docs/adr/009-unified-react-shell.md` |
| Laravel modular monolith | Hosts all business and platform modules in one horizontally scalable application | `docs/architecture/overview.md` |
| Canonical module set | Owns the only 19 legal module names, responsibilities, contracts, events, tables, migrations, and tests | `docs/architecture/module-catalog.md` |
| Authorization | Produces explainable RBAC + ABAC decisions from actor, capability, organization, classification, state, delegation, and trusted record facts | `docs/architecture/module-catalog.md` |
| WorkDefinitions | Owns immutable published work-type schemas and typed projection metadata | `docs/architecture/module-catalog.md` |
| WorkRecords | Owns dynamic work instances, including general requests; persists relational envelope, version-bound payload, state, and lock version | `docs/architecture/module-catalog.md` |
| Workflow | Owns immutable workflow definitions and execution instances, but not the source module's business completion meaning | `docs/architecture/module-catalog.md` |
| Documents | Owns document metadata, versions, classification, storage references, links, and authorized download | `docs/architecture/module-catalog.md` |
| Derived consumers | `Notifications`, `Search`, `Reporting`, and `Workspace` consume events/projection feeds without owning or mutating source truth | `docs/architecture/context-map.md` |
| Runtime data services | Provide MySQL HA, internal cache/queue, S3-compatible object storage, internal search, and observability | `docs/architecture/diagrams/containers.mmd` |

## Pattern Overview

**Overall:** Documentation-first target for a module-first Laravel modular monolith with vertical slices, light DDD/CQRS, contract-based module collaboration, and a unified React shell. The executable repository remains a governed documentation system until product scaffolding exists (`docs/architecture/overview.md`, `README.md`).

**Key Characteristics:**
- Use one deployable Laravel application and one React application; do not split business modules into services merely because module boundaries exist (`docs/adr/001-modular-monolith.md`, `docs/adr/009-unified-react-shell.md`).
- Treat the module as the highest code/data ownership boundary and a feature slice as one complete use case inside it (`docs/engineering/vertical-slices.md`).
- Keep synchronous module dependencies acyclic and directed only toward lower-ranked canonical modules (`docs/architecture/dependency-rules.md`).
- Use Commands/Handlers for writes and Queries/Read Models for reads without event sourcing (`docs/architecture/overview.md`).
- Persist source state and its Outbox event in the same caller-owned MySQL transaction; deliver events at least once to idempotent consumers (`docs/architecture/dependency-rules.md`).
- Keep search, reports, workspace, and notifications derived and rebuildable; never use them as write-side truth (`docs/architecture/context-map.md`).
- Centralize authorization in the backend and apply the same decision to API, search, reporting, exports, document downloads, and field serialization (`docs/architecture/overview.md`).

## Layers

### Current Documentation Layer

- Purpose: Maintain the accepted architecture, domain, security, delivery, and operations decisions that future implementation must follow.
- Location: `docs/`
- Contains: Markdown specifications, Mermaid sources, OpenAPI, AsyncAPI, and JSON Schema documents cataloged by `docs/catalog.yaml`.
- Depends on: Governance precedence in `docs/README.md` and accepted/superseding decisions in `docs/adr/README.md`.
- Used by: Documentation validation in `scripts/validate-docs.sh`, publication through `mkdocs.yml`, and future product implementation planning.

### Target Presentation Layer

- Purpose: Provide one session, router, navigation system, design system, and Arabic-first bilingual experience for all roles.
- Location: Not assigned to a concrete source directory; the monorepo and unified-app decision are binding in `docs/architecture/overview.md` and `docs/adr/009-unified-react-shell.md`.
- Contains: React + TypeScript shell plus module-owned route and navigation contributions.
- Depends on: Authorization-filtered Laravel HTTP responses defined by `docs/contracts/api/openapi.yaml`.
- Used by: Internal users over the cluster network shown in `docs/architecture/diagrams/containers.mmd`.

### Target Feature Slice Layer

- Purpose: Implement one business action end-to-end inside its owning module.
- Location: Logical `Modules/<Module>/Features/<BusinessVerb>/` from `docs/engineering/vertical-slices.md`; concrete Laravel root not yet specified.
- Contains: `Command` or `Query`, `Handler`, `Http`, and co-owned `Tests`.
- Depends on: The owning module's `Domain/`, published lower-rank `Contracts/`, and neutral technical primitives only (`docs/engineering/coding-and-module-boundaries.md`).
- Used by: HTTP endpoints, internal command/query dispatch, and module-owned workers.

### Target Domain and Contract Layer

- Purpose: Protect module invariants and publish stable collaboration boundaries.
- Location: Logical `Modules/<Module>/Domain/`, `Modules/<Module>/Contracts/`, and `Modules/<Module>/Events/` from `docs/engineering/vertical-slices.md`.
- Contains: Aggregates/value objects where warranted, contract DTOs/interfaces, and versioned past-tense events.
- Depends on: No other module internals; cross-module imports are limited to published contracts/events (`docs/engineering/coding-and-module-boundaries.md`).
- Used by: Feature handlers and authorized consumers.

### Target Infrastructure Layer

- Purpose: Adapt module-owned contracts to MySQL, event delivery, object storage, search, clocks, and identifiers.
- Location: Logical `Modules/<Module>/Infrastructure/` from `docs/engineering/vertical-slices.md`.
- Contains: Persistence adapters, module-owned migrations, Outbox/Inbox integration, and external service adapters.
- Depends on: Internal runtime services depicted in `docs/architecture/diagrams/containers.mmd`.
- Used by: The owning module's handlers; never imported directly by another module (`docs/architecture/dependency-rules.md`).

### Target Derived Read Layer

- Purpose: Provide user-facing search, dashboards, reports, notifications, and personal workspace projections.
- Location: Canonical `Search`, `Reporting`, `Notifications`, and `Workspace` modules from `docs/architecture/module-catalog.md`; concrete filesystem root not yet implemented.
- Contains: Event Inbox/deduplication state, projection checkpoints, authorized read models, and rebuild operations.
- Depends on: Published events/projection feeds plus `Authorization`; never source tables (`docs/architecture/context-map.md`).
- Used by: Authorized query endpoints and the unified React application.

## Data Flow

### Primary Write Request Path

1. The React shell sends a command such as `SubmitWorkRecord` to the owning feature slice (`docs/architecture/diagrams/workflow-sequence.mmd:12`).
2. The owning Handler starts and owns the transaction, loads trusted source facts, and requests `DecideAccess` from `Authorization` (`docs/architecture/diagrams/workflow-sequence.mmd:14`).
3. Required synchronous contracts such as `StartWorkflow` and organization resolution join the caller's transaction and do not commit independently (`docs/architecture/diagrams/workflow-sequence.mmd:17`).
4. The owning module persists business state and the versioned Outbox event atomically; critical audit is appended when policy requires it (`docs/architecture/diagrams/workflow-sequence.mmd:22`).
5. The response is returned only after commit; a later workflow publication cannot silently alter the pinned running version (`docs/architecture/diagrams/workflow-sequence.mmd:25`).

### Authorized Read Path

1. The browser requests a record from its owning module, not from a derived store (`docs/architecture/diagrams/authorization-sequence.mmd:10`).
2. The owner loads the minimum trusted envelope and builds `RecordFacts`; client-provided facts are never trusted (`docs/architecture/diagrams/authorization-sequence.mmd:12`).
3. `Authorization` resolves active identity and organization scope, then applies capability, relationships, classification, state, delegation, and field policy (`docs/architecture/diagrams/authorization-sequence.mmd:13`).
4. Sensitive access is audited when required, and the owner serializes only allowed fields (`docs/architecture/diagrams/authorization-sequence.mmd:20`).

### Asynchronous Outbox Flow

1. A feature Handler writes source state and an event envelope to MySQL before commit (`docs/architecture/diagrams/outbox-sequence.mmd:13`).
2. An Outbox reader dispatches committed pending events to the internal queue and records attempts (`docs/architecture/diagrams/outbox-sequence.mmd:19`).
3. Each consumer checks `event_id` in its Inbox/deduplication store before applying effects (`docs/architecture/diagrams/outbox-sequence.mmd:24`).
4. First delivery updates derived consumers; duplicate delivery acknowledges without repeating effects, and exhausted failures enter reviewable dead-letter handling (`docs/architecture/diagrams/outbox-sequence.mmd:26`).

### Document Upload and Download Flow

1. The owning module coordinates document creation and supplies current authorization facts for every active source link (`docs/architecture/diagrams/document-sequence.mmd:11`).
2. `Documents` combines document and linked-source restrictions, fails closed when any facts are unavailable, and asks `Authorization` for one effective decision (`docs/architecture/diagrams/document-sequence.mmd:14`).
3. Allowed upload stores the binary, then persists metadata, link, and Outbox state atomically; denied upload returns no storage success (`docs/architecture/diagrams/document-sequence.mmd:20`).
4. Download re-evaluates all current links, records sensitive access when required, and issues a short-lived signed URL only after an allow decision (`docs/architecture/diagrams/document-sequence.mmd:32`).

**State Management:**
- Store operational truth in module-owned MySQL tables and use optimistic concurrency through `lock_version`/`ETag`/`If-Match` (`docs/architecture/non-functional-requirements.md`, `docs/contracts/module-contracts.md`).
- Pin running records to immutable published work-definition and workflow versions (`docs/architecture/overview.md`).
- Keep client state presentational; backend decisions remain authoritative (`docs/adr/009-unified-react-shell.md`).
- Treat derived projections as eventually consistent and rebuildable (`docs/architecture/context-map.md`).

## Key Abstractions

### Canonical Module

- Purpose: Assign exactly one owner to domain meaning, tables, migrations, contracts, events, and tests.
- Examples: `WorkRecords`, `Strategy`, and `Risk` in `docs/architecture/module-catalog.md`.
- Pattern: Bounded context inside one modular monolith, constrained by the rank DAG in `docs/architecture/dependency-rules.md`.

### Vertical Slice

- Purpose: Co-locate one business outcome rather than grouping application code into cross-module horizontal service buckets.
- Examples: Logical `Modules/WorkRecords/Features/SubmitWorkRecord/` and `Modules/PortfolioProjects/Features/ApproveMilestone/` based on `docs/engineering/vertical-slices.md`.
- Pattern: Command/Query + Handler + HTTP adapter + tests, with shared module invariants in `Domain/`.

### Published Contract

- Purpose: Support an immediate result or shared invariant without exposing persistence or module internals.
- Examples: `DecideAccess`, `ResolveOrganizationScope`, and `StartWorkflow` in `docs/architecture/module-catalog.md`.
- Pattern: Owner-published interface with stable DTOs and declared errors; never ORM models or query builders (`docs/engineering/coding-and-module-boundaries.md`).

### RecordFacts and AccessDecision

- Purpose: Keep centralized authorization independent from business-module tables while still using trusted record context.
- Examples: Schema at `docs/contracts/schemas/record-facts.schema.json` and decision schema at `docs/contracts/schemas/access-decision.schema.json`.
- Pattern: Owner builds facts; `Authorization` returns an explainable decision and field set; no callback from `Authorization` to the business owner (`docs/architecture/context-map.md`).

### Transactional Outbox and Consumer Inbox

- Purpose: Preserve source consistency while enabling reliable asynchronous projections and notifications.
- Examples: Event envelope at `docs/contracts/schemas/event-envelope.schema.json` and channels at `docs/contracts/events/asyncapi.yaml`.
- Pattern: Atomic source + Outbox write, at-least-once Valkey-compatible Streams delivery, Inbox deduplication, explicit acknowledgement, and DLQ (`docs/contracts/module-contracts.md`).

### WorkRecord

- Purpose: Represent dynamic administrative work, including general requests, without creating a separate Requests module.
- Examples: API resource at `docs/contracts/api/openapi.yaml` and schema at `docs/contracts/schemas/work-record.schema.json`.
- Pattern: Relational envelope + version-bound payload + typed projections + explicit references (`docs/architecture/overview.md`).

## Entry Points

### Current Documentation Entry

- Location: `README.md`
- Triggers: Developer, reviewer, operator, or planner opens the repository.
- Responsibilities: Identify `docs/` as the current package and direct readers to `docs/architecture/overview.md`, `docs/governance/document-control.md`, and `docs/README.md`.

### Current Architecture Entry

- Location: `docs/architecture/overview.md`
- Triggers: Any architecture, module-boundary, deployment, or implementation decision.
- Responsibilities: Define binding target decisions and precedence over non-authoritative material.

### Current Contract Entry

- Location: `docs/contracts/README.md`
- Triggers: HTTP client, synchronous module boundary, event producer, or event consumer design.
- Responsibilities: Direct implementation to OpenAPI, AsyncAPI, JSON Schema, UUIDv7, correlation, classification, concurrency, and idempotency requirements.

### Current Validation Entry

- Location: `scripts/validate-docs.sh`
- Triggers: Local validation or the `validate-docs` GitLab job in `.gitlab-ci.yml`.
- Responsibilities: Fail invalid documentation structure, metadata, links, catalog coverage, schemas, and repository rules.

### Target HTTP Entry

- Location: Contract-only `docs/contracts/api/openapi.yaml`; no Laravel route/controller implementation exists.
- Triggers: Authenticated calls below `/api/v1` plus unauthenticated login.
- Responsibilities: Correlation, authentication, idempotency, optimistic concurrency, command/query dispatch, authorization-filtered serialization, and RFC 7807 errors.

### Target Worker Entry

- Location: Contract-only `docs/contracts/events/asyncapi.yaml`; no queue worker implementation exists.
- Triggers: Valkey-compatible Stream delivery and scheduled Outbox relay work.
- Responsibilities: Inbox deduplication, derived updates, explicit acknowledgement, retry, and DLQ routing.

## Architectural Constraints

- **Implementation status:** Product code is absent; only documentation tooling and machine-readable contracts are implemented (`README.md`, `docs/contracts/README.md`).
- **Threading:** Target HTTP work scales through multiple Web/API replicas; async work runs in worker replicas; the scheduler is singleton or leader-elected (`docs/architecture/diagrams/deployment.mmd`).
- **Global state:** Target operational state is one MySQL source of truth with module ownership, plus shared queue/cache and derived stores; no product global mutable state exists yet (`docs/architecture/overview.md`).
- **Circular imports:** Synchronous dependencies must follow the rank DAG and may target only a lower rank; same-rank dependencies are forbidden (`docs/architecture/dependency-rules.md`).
- **Transactions:** The write Handler owns begin/commit/rollback; synchronous contracts join it; workers, search, storage, and network services are never inside it (`docs/architecture/dependency-rules.md`).
- **Data ownership:** Never query, join, constrain, or migrate another module's business tables; collaborate through contracts, events, IDs, references, or governed read models (`docs/engineering/coding-and-module-boundaries.md`).
- **Module names:** Use only the 19 canonical names; `Requests` and `Indicators` are explicitly invalid module boundaries (`docs/architecture/overview.md`, `docs/architecture/module-catalog.md`).
- **Security boundary:** React visibility is not authorization; apply backend decisions consistently to API, search, reporting, export, and document download (`docs/architecture/overview.md`).
- **Runtime isolation:** Target build and runtime cannot depend on Internet, CDN, public package registries, or public images (`docs/engineering/ci-cd-and-release.md`).
- **Recovery:** Verify `RPO <= 15 minutes` and `RTO <= 2 hours` by actual restore, with backups outside the Kubernetes failure domain (`docs/architecture/non-functional-requirements.md`).
- **Repository authority:** Treat `docs/` as authoritative and `.planning/archive/` as historical/non-authoritative; document precedence is defined by `docs/README.md` and `docs/architecture/overview.md`.

## Anti-Patterns

### Treating the Target as Implemented

**What happens:** Plans refer to Laravel modules, React source, migrations, workers, or Kubernetes manifests as if those files exist.
**Why it's wrong:** The repository contains no product PHP/TypeScript or runtime manifests; this hides the scaffolding gap and creates invalid file references (`README.md`, `docs/plans/implementation-roadmap.md`).
**Do this instead:** Label every product statement as documented target until actual source appears; use `docs/architecture/overview.md` and contract artifacts as implementation inputs.

### Application-Wide Horizontal Service Buckets

**What happens:** Controllers, services, repositories, or handlers from multiple domains are grouped by technical layer.
**Why it's wrong:** It weakens ownership and encourages cross-module imports and persistence access (`docs/engineering/vertical-slices.md`).
**Do this instead:** Put each use case under logical `Modules/<Module>/Features/<BusinessVerb>/` and keep reusable domain rules within that module.

### Cross-Module Persistence Access

**What happens:** A module imports another module's ORM model, query builder, migration, `Domain/`, or `Infrastructure/`, or joins its tables.
**Why it's wrong:** It violates single ownership and makes module evolution unsafe (`docs/engineering/coding-and-module-boundaries.md`).
**Do this instead:** Consume the owner's `Contracts/`, published events, IDs, record references, or a `Reporting` read model (`docs/architecture/dependency-rules.md`).

### Frontend Authorization

**What happens:** A hidden button or route guard is treated as the security decision.
**Why it's wrong:** Clients are untrusted and other channels can bypass presentation controls (`docs/adr/009-unified-react-shell.md`).
**Do this instead:** Build trusted facts in the owner and invoke `Authorization` for every protected read/write channel (`docs/architecture/context-map.md`).

### Events as Hidden Commands

**What happens:** A consumer silently performs a source-domain transition because an event arrived.
**Why it's wrong:** A mandatory invariant becomes eventually consistent and transaction ownership becomes unclear (`docs/architecture/context-map.md`).
**Do this instead:** Use an explicit Command coordinated within the owning use case for mandatory transitions; reserve events for past-tense facts and derived reactions.

### Derived Stores as Sources of Truth

**What happens:** Search, reports, workspace, or notifications decide source state or mutate business records.
**Why it's wrong:** Projections are delayed, rebuildable, and intentionally terminal consumers (`docs/architecture/context-map.md`).
**Do this instead:** Re-authorize and execute through the source module's endpoint/contract; keep derived stores read-only with respect to source truth.

### Unbounded Shared Folder

**What happens:** Domain DTOs, policies, repositories, or generic business helpers accumulate in `Shared`.
**Why it's wrong:** Ownership becomes ambiguous and hidden coupling bypasses the module DAG (`docs/architecture/module-catalog.md`).
**Do this instead:** Limit shared code to clocks, identifiers, transaction/Outbox primitives, and neutral technical types; place all business meaning in its owner module.

## Error Handling

**Strategy:** Fail closed for security and destructive governance decisions, fail atomically for source writes, use explicit contract errors for expected client failures, and retry asynchronous side effects without undoing committed source truth (`docs/contracts/module-contracts.md`, `docs/architecture/diagrams/outbox-sequence.mmd`).

**Patterns:**
- Return RFC 7807 `application/problem+json` errors and preserve `X-Correlation-ID` across request/response boundaries (`docs/contracts/README.md`).
- Return `409` for conflicting idempotency replays and `412` for stale `If-Match` values (`docs/contracts/module-contracts.md`).
- Roll back source state and Outbox together when validation, invariant, authorization, required synchronous contract, or Outbox persistence fails (`docs/architecture/dependency-rules.md`).
- Deny access when trusted authorization facts are unavailable, especially for linked documents (`docs/architecture/diagrams/document-sequence.mmd`).
- Acknowledge duplicate events without repeating effects; route invalid or exhausted events to a reviewable DLQ rather than discarding them (`docs/contracts/module-contracts.md`).
- Use expand-migrate-verify-contract and forward fixes rather than destructive production down migrations (`docs/engineering/database-migrations.md`).

## Cross-Cutting Concerns

**Logging:** Target services emit centralized logs, metrics, traces, and alerts without sensitive payloads; implemented documentation CI exposes validation output through `.gitlab-ci.yml` (`docs/architecture/non-functional-requirements.md`).

**Validation:** Current artifacts are checked by `scripts/validate-docs.sh`; target transport uses OpenAPI/JSON Schema, feature validation, domain invariants, contract tests, event compatibility tests, and architecture boundary tests (`docs/engineering/testing-strategy.md`).

**Authentication:** Target identity is local and API-wide bearer authentication is declared in `docs/contracts/api/openapi.yaml`; only `/auth/login` removes the global security requirement there.

**Authorization:** Central RBAC + ABAC returns explainable decisions and allowed fields from trusted owner-built facts (`docs/architecture/context-map.md`).

**Audit:** Critical changes and sensitive access are append-only policy-driven records; async authorization audit avoids a cycle back from `Authorization` to `Audit` (`docs/architecture/module-catalog.md`).

**Localization:** The target is Arabic-first with full English and RTL/LTR support in one React shell (`docs/architecture/overview.md`).

**Contracts:** HTTP and events use UUIDv7 identifiers, UTC timestamps, correlation IDs, classification propagation, idempotency, explicit schema versions, and compatibility rules (`docs/contracts/README.md`).

**Deployment:** Target replicas, data services, internal supply chain, and independent backup are depicted in `docs/architecture/diagrams/deployment.mmd`; no deployment manifests are implemented.

---

*Architecture analysis: 2026-07-15*
