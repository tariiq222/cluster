# Cluster RecordsGovernance Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: M02
status: blocked
depends_on:
  - M00
blocks:
  - M03:governance-integration
  - M04:governance-integration
  - M07:final-integration
  - P04:enforcement
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Implement RecordsGovernance as the rank-4 authority for versioned retention schedules, immutable record classification snapshots, legal holds, disposition approval and source-owned execution, and tamper-evident governance evidence.

**Architecture:** A module-owned controller authorizes before disclosure or detailed validation, then calls a module application service, which commits module-owned state, idempotency, immutable evidence, M01 audit, and the shared transactional outbox atomically. Other modules identify records with opaque `RecordSourceReference` values and consume only M02 `Contracts/` or `Events/`; M02 never queries or deletes another module's tables and never imports Documents because Documents is rank 5. Shared registry, route, OpenAPI/Orval, capability-catalog, provider, migration registry, MySQL-suite, and web-shell changes are serialized integrations applied only after their current owners hand them off. The registry cutover is one indivisible integration change containing the real module directory, all four real migrations, runtime migration registration, the unchanged rank-4 entry, planned-list removal, and all seven table-owner entries; no registry mutation lands earlier.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, MySQL/SQLite, React 19, TypeScript 6, Vite 8, Vitest 4, OpenAPI 3.1, Orval 8.22, the Architecture Closure Task 12 Shared authenticated cursor codec, shared `TransactionalOutbox`, Identity session/CSRF middleware, and Authorization `DecideAccess`/`RecordFacts`.

## Global constraints

- The approved source of truth is `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`; M00's canonical reservation matrix fixes all names in this plan.
- The current architecture-closure plan remains `in_progress`. It retains `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, `apps/api/tests/Architecture/ModuleBoundariesTest.php`, and any active `apps/api/routes/web.php` edit until explicit handoff.
- M02 owns no shared file. Registry/API route/OpenAPI/Orval/web-shell integration runs in the canonical `M01 → M02 → M03 → M04 → M05 → M06 → M07` queue.
- `Makefile` and CI workflow integration belongs only to P08 after Architecture Closure Task 13 handoff.
- Prepare and validate the exact M02 registry delta before scaffolding, but do not apply any `MODULE_RANKS`, `PLANNED_MODULES`, or `TABLE_OWNERS` mutation then. The M02 candidate may create its module-owned directory and migrations on its isolated branch; the serialized integration must merge that candidate and apply the complete registry/runtime delta in one indivisible change. A registry-only change, ghost table owner, or integrated module directory without all four owned migrations is a blocking failure.
- Clean cutover only. Production bindings may not use an in-memory implementation, no-op adapter, or fake fallback. Test-only fakes stay under `Modules/RecordsGovernance/Tests/Support/`.
- Generated clients change only through `npm --prefix apps/web run api:generate`; never edit `apps/web/src/api/generated/cluster.ts` manually.
- Every mutation requires `X-Correlation-ID` and `Idempotency-Key`; publish, hold release, and disposition confirmation additionally require `If-Match` against `lock_version`.
- Authorization precedes detailed payload validation, record lookup disclosure, and state-transition disclosure. Mutations use Identity session, principal, and CSRF middleware.
- One command transaction includes state, the idempotency replay record, M02 evidence, M01 audit after its gate opens, and shared outbox append. A failure in any participant rolls back all participants.
- Optimistic concurrency is enforced in the SQL write predicate; stale or missing `If-Match` yields RFC 7807 `412`, and illegal current-state transitions yield RFC 7807 `409`.
- PHI/PII is excluded from URLs, source-reference payloads, evidence payloads, outbox events, error bodies, browser storage, and unsanitized logs. Source references contain identifiers and classification/scope facts, never source content.
- No commit, push, merge, migration execution outside a disposable test environment, deployment, cloud change, or external message is authorized by this plan. A commit is recorded only after explicit user authorization.

---

## 1. Status header and dependency gates

### Start gate

M02 remains `blocked` until all of the following are recorded:

1. M00 is `completed` and its canonical rank, table, contract, event, capability, route, and queue reservations match this plan.
2. Architecture Closure Tasks 4, 6, 7, and 12 have handed their relevant guard, provider/outbox, HTTP primitive, and route/OpenAPI decisions to M00.
3. M01's prior registry integration is merged/released, and the canonical `MODULE-REGISTRY` queue records M02 as the next eligible holder; the token is not granted and no registry delta is applied until the atomic-cutover gate in Task 10.
4. The queue record names the releasing owner, requesting plan `M02`, candidate base commit, exact shared surfaces, and the evidence required to grant the later atomic cutover.

### Phase gates that are not start dependencies

- The module-owned core may proceed after Task 1's read-only registry-delta validation without waiting for M01 audit integration, using deterministic audit fakes only in tests.
- Final audit integration is explicitly blocked on M01. M02 cannot enter `verification` until the real `RecordAuditEvent` production binding is present and atomic rollback tests pass.
- Capability-catalog integration waits for the current Authorization/Architecture Closure owner to release `CapabilityCatalog.php` and its seeder/test surfaces; this does not become a new M02 start dependency.
- API routes, master OpenAPI, Orval generation, web shell, provider registration, central migration list, outbox event enum, and MySQL suite list each wait for their current owner and M02's serialized token.
- Downstream M03/M04/M07/P04 consumers remain blocked only in their named integration phases; they are not promoted into M02 start gates.

## 2. Goal and user-visible outcome

Authorized records officers can:

- create a complete draft retention schedule and publish an immutable version;
- register a source-owned record under one pinned policy version, record type, classification code, facility/unit scope, retention start, computed due date, and disposition action;
- inspect governed-record status without seeing source payloads;
- place and release direct, facility, unit, or record-type holds with reasons and optional expiry;
- review disposition eligibility and receive an explicit conflict when any applicable hold is effective;
- request source-owned disposition, then confirm `disposed`, `retained`, or `failed` with an opaque source confirmation and optional evidence-document UUID;
- see stable cursor-paginated retention, record, hold, and disposition lists in `/records-governance`;
- rely on immutable, hash-chained evidence and M01 audit for every successful governance mutation.

Source modules can register records, read status, evaluate disposition under a caller-owned transaction, consume versioned events, and perform their own archive/destruction. M02 never reads source payloads or performs source deletion.

## 3. Current source evidence

- `apps/api/tests/Architecture/ModuleBoundariesTest.php:13-32` already fixes `RecordsGovernance` at rank 4 and `Documents` at rank 5; `:44-52` still lists RecordsGovernance as planned, and `:509-533` forbids rank 4 from importing same-or-higher-rank modules even through Contracts/Events.
- `docs/architecture/module-catalog.md:192-194` describes RecordsGovernance as rank 4 and responsible for retention and disclosure classification.
- `docs/contracts/api/openapi.yaml:4912-5223` already reserves the exact `/records-governance/*` paths, operation IDs, `RetentionPolicyCreate`, `GovernedRecordCreate`, `RecordHoldCreate`, `DispositionDecision`, and `DispositionConfirmation` schema names with `x-implementation-status: planned`.
- `docs/contracts/api/openapi.yaml:8507-8523` defines the opaque `SourceReference` shape (`source_module`, `record_type`, `record_id`).
- `apps/api/config/documents.php:75-97`, `Modules/Documents/Domain/DocumentRetentionPolicy.php`, and `Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php:20-25` show an existing Documents-local retention configuration and document legal-hold fields.
- `Modules/Documents/Features/DocumentLifecycle/Http/TransitionDocumentController.php:41-116` demonstrates present idempotency, `If-Match`, and hold transitions, but its controller performs direct persistence and does not establish M02 ownership.
- `Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php:15-35` establishes the accepted cross-module-reference precedent: source module/type/id columns without a source-module foreign key.
- `Modules/Authorization/Contracts/RecordFacts.php` already carries classification, legal-hold, and lock-version facts; `DecideAccess` is the required capability boundary.
- `apps/api/routes/web.php:113-305` applies Identity session/principal middleware to reads and CSRF to mutations.
- `Shared/Http/HttpSupport.php` provides current correlation, idempotency, ETag, and RFC 7807 primitives; Architecture Closure Task 7 owns their final handoff. Architecture Closure Task 12 owns the canonical Shared authenticated cursor codec and records its exact Shared path/interface; M02 consumes that production binding and never implements or edits a module-local cursor codec.
- `Shared/Contracts/TransactionalOutbox.php` and `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php` provide the shared outbox contract/table. M02 must not create a second outbox table.
- `Modules/Authorization/Contracts/CapabilityCatalog.php:8-129` is a hardcoded Authorization-owned catalog and currently contains none of the M02 capabilities.
- `apps/api/app/Providers/AppServiceProvider.php:24-46` centrally registers module providers and loads `config('module_migrations')`; `apps/api/config/module_migrations.php` centrally orders migrations.
- `apps/web/src/api/platform-settings.ts:1-18` is the current generated-client wrapper pattern; feature components do not build auth, CSRF, idempotency, or correlation headers themselves.

## 4. Scope and explicit non-goals

### In scope

- Versioned retention policies and immutable published rules.
- Registration-time record classification and policy pinning.
- Direct and scoped legal holds, release history, and effective-hold evaluation.
- Disposition eligibility review, execution request, source confirmation, retry, stale-write, and failure semantics.
- Immutable M02 evidence, shared outbox events, and final M01 audit integration.
- Opaque source and evidence-document references through M02 contracts/events and HTTP schemas.
- Module-owned API implementation, module-owned web feature, targeted SQLite/MySQL/concurrency tests, and serialized integration.

### Non-goals

- No direct query, foreign key, write, delete, archive, or purge against Documents, Collaboration, Strategy, or any other owner's table.
- No import of `Modules\Documents\*`; rank 4 cannot depend on rank 5. M02 stores `evidence_document_id` only as an opaque UUIDv7 attested by the confirming source.
- No replacement or mutation of Documents' current configuration or `documents.legal_hold` fields in this plan.
- No physical object deletion, malware scanning, S3 lifecycle work, backup orchestration, release topology, or worker/scheduler ownership.
- No shared-surface edit outside a granted serialized token, and no ownership claim over any shared surface.
- No hand-written generated client, cross-module persistence adapter, compatibility alias, duplicate audit ledger, or module-specific outbox table.

### Coexistence and precedence with Documents

- For unregistered Documents records, the existing Documents retention configuration remains the Documents authority.
- For a source registered as governed, M02 owns the governance schedule, effective M02 holds, eligibility review, and request/confirmation evidence. The source owner still owns the source row/object and the final action.
- `documents.legal_hold` and an effective M02 `RecordHold` are independent protections. They are not synchronized booleans. Either protection denies source disposition; neither can override the other.
- A Documents disposition adapter, when separately authorized by its owner, must evaluate its local hold and M02 `GuardDispositionExecution` inside the same database transaction immediately before its source mutation. M02 never reaches into Documents to reconcile these states.
- This plan's completion proves M02's contract/event side and fail-closed precedence. A later Documents-owned integration may consume the contracts/events; it cannot transfer Documents table ownership to M02.

## 5. Architecture and ownership boundaries

### Request flow

```text
Identity session/principal + CSRF (mutation)
  -> module controller
  -> capability check using DecideAccess + RecordFacts
  -> header and payload validation
  -> application service
  -> module persistence + idempotency + immutable evidence
     + M01 RecordAuditEvent (after M01 gate)
     + shared TransactionalOutbox
  -> RFC 7807 or typed entity/collection + X-Correlation-ID + ETag
```

### Aggregate boundaries

- `RetentionPolicyVersion` owns its rules. A published version and its rules are immutable.
- `GovernedRecord` owns the pinned source reference, classification snapshot, scope facts, policy/rule reference, due date, lifecycle state, and lock version.
- `RecordHold` is independent history; multiple holds may overlap. A record is held if any applicable direct/facility/unit/record-type hold is effective.
- `DispositionReview` records one explicit decision and, for eligible decisions, the eventual source outcome. One unconfirmed eligible review is permitted per governed record.
- `GovernanceEvidence` is an append-only hash chain per aggregate. It contains safe facts and hashes, not source content.

### Lifecycle invariants

1. Draft policy creation stores the complete rule set; no partial draft update endpoint exists.
2. Publication changes `draft → published` once with `If-Match`; published rows/rules cannot update or delete.
3. A governed record pins one published policy version and matching `(record_type, classification_code)` rule. Later policy publication never rewrites its rule, due date, or classification.
4. `retention_due_at = retention_start_at + retention_days`; disposition cannot be eligible before the due instant.
5. Source identity is unique on `(source_module, record_type, record_id)` and never contains source payload fields.
6. Effective hold means `released_at IS NULL` and (`expires_at IS NULL` or `expires_at > transaction_now`). Every eligibility check and execution guard uses the database transaction time.
7. Scoped holds (`facility`, `unit`, `record_type`) match many governed records and have no single row to lock; `RecordHoldService` therefore resolves the matching `governed_record` set inside a shared read snapshot, orders it ascending by `id` to canonicalize lock acquisition, locks every match with `lockForUpdate()` in that order, and only then inserts the hold row plus evidence/idempotency/audit/outbox. `GuardDispositionExecution::evaluate()` and the eligibility service use the same ascending `id` lock order, so a scoped hold always linearizes against any concurrent source disposition regardless of which matching record the source owns. A two-record opposite-order MySQL concurrency test must prove this.
8. Any effective applicable hold wins over retention due date, prior eligibility, retry, or deletion request. Eligibility returns `409 record-hold-active`; guard returns a denied `DispositionExecutionDecision` and never emits a new execution request.
9. `GuardDispositionExecution::evaluate()` must be called inside the source owner's open database transaction. Its production implementation `SELECT ... FOR UPDATE`s every matching governed-record row in ascending `id` order through commit, evaluates all four scopes at one transaction timestamp, and returns `transaction_required`, `record_unknown`, `not_due`, `hold_active`, `review_missing`, `review_stale`, or `allowed`; it fails closed on database or contract failure.
10. The source owner performs archive/destruction and its own outbox effects in that same transaction. A hold transaction linearizes before or after that source transaction; a hold committed first denies execution.
11. Confirmation cannot cause deletion. It only records the source-owned result and requires the review ETag, idempotency key, and a unique `source_confirmation_id`.
12. M02 evidence rows, published policy rows/rules, and terminal confirmations are not physically deleted. No cascading delete may erase evidence.
13. Each evidence entry hashes canonical JSON plus aggregate type/id, sequence, previous hash, event type, and occurred-at. Unique `(aggregate_type, aggregate_id, sequence)` and `event_id` prevent forks/replay.
14. Idempotency identity is `(principal_id, operation, sha256(idempotency_key))`. Same key and request hash replays the saved status/body/ETag; same key with a different request hash returns `409 idempotency-conflict` without side effects.

## 6. Files to create, modify, move, or remove

### M02-owned API files to create

- `apps/api/Modules/RecordsGovernance/Contracts/RecordSourceReference.php`
- `apps/api/Modules/RecordsGovernance/Contracts/GovernedRecordRegistration.php`
- `apps/api/Modules/RecordsGovernance/Contracts/GovernedRecordStatus.php`
- `apps/api/Modules/RecordsGovernance/Contracts/RegisterGovernedRecord.php`
- `apps/api/Modules/RecordsGovernance/Contracts/ReadGovernedRecordStatus.php`
- `apps/api/Modules/RecordsGovernance/Contracts/DispositionExecutionDecision.php`
- `apps/api/Modules/RecordsGovernance/Contracts/GuardDispositionExecution.php`
- `apps/api/Modules/RecordsGovernance/Contracts/RecordsGovernanceSummaryQuery.php`
- `apps/api/Modules/RecordsGovernance/Contracts/RecordsGovernanceSummary.php`
- `apps/api/Modules/RecordsGovernance/Contracts/QueryRecordsGovernanceSummary.php`
- `apps/api/Modules/RecordsGovernance/Events/RetentionPolicyVersionPublishedV1.php`
- `apps/api/Modules/RecordsGovernance/Events/GovernedRecordStatusChangedV1.php`
- `apps/api/Modules/RecordsGovernance/Events/RecordHoldChangedV1.php`
- `apps/api/Modules/RecordsGovernance/Events/DispositionExecutionRequestedV1.php`
- `apps/api/Modules/RecordsGovernance/Events/DispositionOutcomeConfirmedV1.php`
- `apps/api/Modules/RecordsGovernance/Domain/RetentionPolicyVersionStatus.php`
- `apps/api/Modules/RecordsGovernance/Domain/DispositionAction.php`
- `apps/api/Modules/RecordsGovernance/Domain/GovernedRecordLifecycle.php`
- `apps/api/Modules/RecordsGovernance/Domain/HoldScopeType.php`
- `apps/api/Modules/RecordsGovernance/Domain/DispositionDecisionCode.php`
- `apps/api/Modules/RecordsGovernance/Domain/DispositionOutcomeCode.php`
- `apps/api/Modules/RecordsGovernance/Application/RetentionPolicyService.php`
- `apps/api/Modules/RecordsGovernance/Application/GovernedRecordService.php`
- `apps/api/Modules/RecordsGovernance/Application/RecordHoldService.php`
- `apps/api/Modules/RecordsGovernance/Application/DispositionService.php`
- `apps/api/Modules/RecordsGovernance/Application/RecordsGovernanceQueryService.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseRetentionPolicyRepository.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseGovernedRecordRepository.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseRecordHoldRepository.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseDispositionReviewRepository.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseGovernanceEvidenceLedger.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/DatabaseRecordsGovernanceIdempotencyStore.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/Migrations/CreateRecordsGovernancePolicyTables.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/Migrations/CreateRecordsGovernanceRecordTables.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/Migrations/CreateRecordsGovernanceEvidenceAndIdempotencyTables.php`
- `apps/api/Modules/RecordsGovernance/Infrastructure/Persistence/Migrations/AddRecordsGovernanceImmutabilityGuards.php`
- `apps/api/Modules/RecordsGovernance/Http/RecordsGovernanceApi.php`
- `apps/api/Modules/RecordsGovernance/Features/RetentionPolicy/Http/ListRetentionPolicyVersionsController.php`
- `apps/api/Modules/RecordsGovernance/Features/RetentionPolicy/Http/CreateRetentionPolicyVersionController.php`
- `apps/api/Modules/RecordsGovernance/Features/RetentionPolicy/Http/PublishRetentionPolicyVersionController.php`
- `apps/api/Modules/RecordsGovernance/Features/GovernedRecord/Http/ListGovernedRecordsController.php`
- `apps/api/Modules/RecordsGovernance/Features/GovernedRecord/Http/RegisterGovernedRecordController.php`
- `apps/api/Modules/RecordsGovernance/Features/GovernedRecord/Http/GetGovernedRecordStatusController.php`
- `apps/api/Modules/RecordsGovernance/Features/RecordHold/Http/ListRecordHoldsController.php`
- `apps/api/Modules/RecordsGovernance/Features/RecordHold/Http/PlaceRecordHoldController.php`
- `apps/api/Modules/RecordsGovernance/Features/RecordHold/Http/ReleaseRecordHoldController.php`
- `apps/api/Modules/RecordsGovernance/Features/Disposition/Http/ListDispositionReviewsController.php`
- `apps/api/Modules/RecordsGovernance/Features/Disposition/Http/DecideDispositionEligibilityController.php`
- `apps/api/Modules/RecordsGovernance/Features/Disposition/Http/ConfirmDispositionOutcomeController.php`
- `apps/api/Modules/RecordsGovernance/Providers/RecordsGovernanceServiceProvider.php`

### M02-owned tests/support to create

- `apps/api/Modules/RecordsGovernance/Tests/Domain/RetentionPolicyLifecycleTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Persistence/RecordsGovernanceMigrationTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Application/GovernedRecordRegistrationTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Application/RecordHoldPrecedenceTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Application/DispositionLifecycleTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Application/RecordsGovernanceAtomicityTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Http/RecordsGovernanceHttpAdapterTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/MySql/RecordsGovernanceMySqlConcurrencyTest.php`
- `apps/api/Modules/RecordsGovernance/Tests/Support/InMemoryRecordAuditEvent.php`
- `apps/api/Modules/RecordsGovernance/Tests/Support/FailingRecordAuditEvent.php`
- `apps/api/Modules/RecordsGovernance/Tests/Support/RecordsGovernanceTestCase.php`

### M02-owned web files to create

- `apps/web/src/api/records-governance.ts`
- `apps/web/src/api/records-governance.test.ts`
- `apps/web/src/features/records-governance/records-governance-problem.ts`
- `apps/web/src/features/records-governance/RecordsGovernanceWorkspace.tsx`
- `apps/web/src/features/records-governance/RetentionPoliciesPanel.tsx`
- `apps/web/src/features/records-governance/GovernedRecordsPanel.tsx`
- `apps/web/src/features/records-governance/RecordHoldsPanel.tsx`
- `apps/web/src/features/records-governance/DispositionReviewsPanel.tsx`
- `apps/web/src/features/records-governance/RecordsGovernanceWorkspace.test.tsx`

### M02-owned contract schema files to create

- `docs/contracts/schemas/com-cluster-recordsgovernance-retentionpolicyversionpublished-v1.schema.json`
- `docs/contracts/schemas/com-cluster-recordsgovernance-governedrecordstatuschanged-v1.schema.json`
- `docs/contracts/schemas/com-cluster-recordsgovernance-recordholdchanged-v1.schema.json`
- `docs/contracts/schemas/com-cluster-recordsgovernance-dispositionexecutionrequested-v1.schema.json`
- `docs/contracts/schemas/com-cluster-recordsgovernance-dispositionoutcomeconfirmed-v1.schema.json`

### Shared files changed only by a granted integration token

- `apps/api/tests/Architecture/ModuleBoundariesTest.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/config/module_migrations.php`
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php`
- `apps/api/Modules/Authorization/Tests/CapabilityCatalogTest.php`
- `apps/api/database/seeders/AuthorizationCatalogSeeder.php`
- `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`
- `apps/api/routes/web.php`
- `apps/api/phpunit.mysql.xml`
- `docs/contracts/api/openapi.yaml`
- `apps/web/src/api/generated/cluster.ts` (generation command only)
- `apps/web/src/shell/routes.ts`
- `apps/web/src/shell/routes.test.ts`
- `apps/web/src/shell/routes.capabilities.test.ts`
- `apps/web/src/shell/navigation.tsx`
- `apps/web/src/shell/navigation.test.tsx`
- `apps/web/src/app/workspace-routes.tsx`

No file is moved or removed. M02 never modifies `Makefile`, a CI workflow, a Documents file, or another module's migration/table.

## 7. Public Contracts, Events, routes, schemas, and capabilities

### Canonical published Contracts

```php
interface RegisterGovernedRecord
{
    public function register(GovernedRecordRegistration $registration): GovernedRecordStatus;
}

interface ReadGovernedRecordStatus
{
    public function get(RecordSourceReference $source): ?GovernedRecordStatus;
}

interface GuardDispositionExecution
{
    public function evaluate(RecordSourceReference $source): DispositionExecutionDecision;
}

interface QueryRecordsGovernanceSummary
{
    public function forScope(RecordsGovernanceSummaryQuery $query): RecordsGovernanceSummary;
}
```

`RecordSourceReference` contains exactly `sourceModule`, `recordType`, and UUIDv7 `recordId`. `GovernedRecordRegistration` adds `retentionPolicyVersionId`, `classificationCode`, `retentionStartAt`, `facilityId`, optional `organizationUnitId`, `actorUserId`, `idempotencyKey`, and `correlationId`. `GovernedRecordStatus` exposes safe governance facts only: IDs, classification, policy version, retention due instant, disposition action, lifecycle, effective hold flag, lock version, and updated instant.
`classificationCode` and every policy-rule `classification_code` are restricted to the current canonical set `public | internal | confidential | top_secret`; accepting a new code requires an approved M00/Authorization contract revision, not an unvalidated free-form value.

`DispositionExecutionDecision` contains `allowed`, `reasonCode`, optional `dispositionReviewId`, and `governedRecordLockVersion`. `RecordsGovernanceSummaryQuery` contains authorized facility/unit scope and an `asOf` instant; `RecordsGovernanceSummary` contains only counts for retained, due, held, awaiting confirmation, and failed records.

### Canonical Events and exact event types

| Event class | Exact event type |
|---|---|
| `RetentionPolicyVersionPublishedV1` | `com.cluster.recordsgovernance.retentionpolicyversionpublished.v1` |
| `GovernedRecordStatusChangedV1` | `com.cluster.recordsgovernance.governedrecordstatuschanged.v1` |
| `RecordHoldChangedV1` | `com.cluster.recordsgovernance.recordholdchanged.v1` |
| `DispositionExecutionRequestedV1` | `com.cluster.recordsgovernance.dispositionexecutionrequested.v1` |
| `DispositionOutcomeConfirmedV1` | `com.cluster.recordsgovernance.dispositionoutcomeconfirmed.v1` |

Every event is CloudEvents-compatible, versioned `V1`, uses UUIDv7 event/aggregate IDs and UTC millisecond timestamps, and excludes source payloads, reasons containing PHI/PII, actor names, and document contents. Event types use the approved unhyphenated lowercase module token and concatenated lowercase event class name without `V1`.

### Exact HTTP routes and existing operation IDs

- `GET /api/v1/records-governance/retention-policy-versions` → `listRetentionPolicyVersions`
- `POST /api/v1/records-governance/retention-policy-versions` → `createRetentionPolicyVersion`
- `POST /api/v1/records-governance/retention-policy-versions/{versionId}/publish` → `publishRetentionPolicyVersion`
- `GET /api/v1/records-governance/governed-records` → `listGovernedRecords`
- `POST /api/v1/records-governance/governed-records` → `registerGovernedRecord`
- `GET /api/v1/records-governance/governed-records/{governedRecordId}` → `getGovernedRecordStatus`
- `GET /api/v1/records-governance/holds` → `listRecordHolds`
- `POST /api/v1/records-governance/holds` → `placeRecordHold`
- `POST /api/v1/records-governance/holds/{holdId}/release` → `releaseRecordHold`
- `GET /api/v1/records-governance/disposition-reviews` → `listDispositionReviews`
- `POST /api/v1/records-governance/disposition-reviews` → `decideDispositionEligibility`
- `POST /api/v1/records-governance/disposition-reviews/{reviewId}/confirm` → `confirmDispositionOutcome`

The authoritative OpenAPI keeps these exact paths, operation IDs, and existing schema names. The token-gated contract revision replaces generic Entity/Collection responses with typed `RetentionPolicyVersion`, `GovernedRecord`, `RecordHold`, `DispositionReview`, and cursor-page schemas; adds required `classification_code` and `facility_id` plus optional `organization_unit_id` to `GovernedRecordCreate`; and changes `RecordHoldCreate.scope_id` to a bounded string whose handler requires UUIDv7 for record/facility/unit and the source-token pattern for record_type. Only after API/implementation tests pass does the integrator flip each M02 `x-implementation-status` from `planned` to `implemented`.

### Canonical capability names

- `records_governance.retention-policy.read`
- `records_governance.retention-policy.manage`
- `records_governance.retention-policy.publish`
- `records_governance.record.read`
- `records_governance.record.register`
- `records_governance.hold.read`
- `records_governance.hold.manage`
- `records_governance.disposition.read`
- `records_governance.disposition.review`
- `records_governance.disposition.confirm`

List/get operations use the matching `.read`; draft creation uses `.manage`; publication uses `.publish`; registration uses `.register`; hold placement/release uses `.manage`; eligibility uses `.review`; source outcome confirmation uses `.confirm`. A denied capability returns generic `403` before detailed validation or resource disclosure.

## 8. Database tables, indexes, constraints, migration order, and recovery

M00 fixes these exact owned tables:

1. `records_governance_retention_policy_versions`
   - UUIDv7 `id` primary key; `code` varchar(64); `name` varchar(255); unsigned `version`; `status` varchar(16); nullable `effective_at`, `published_at`, `published_by_user_id`; `created_by_user_id`; unsigned `lock_version`; timestamps.
   - Unique `(code, version)`; index `(code, status, effective_at)`.
2. `records_governance_retention_policy_rules`
   - UUIDv7 `id`; own FK `retention_policy_version_id` with `restrictOnDelete`; `record_type` varchar(128); `classification_code` varchar(32); positive unsigned `retention_days`; `disposition_action` varchar(16); timestamps.
   - Unique `(retention_policy_version_id, record_type, classification_code)`; index `(record_type, classification_code)`.
3. `records_governance_governed_records`
   - UUIDv7 `id`; opaque `source_module` varchar(64), `record_type` varchar(128), UUIDv7 `record_id`; `classification_code`; opaque UUIDv7 `facility_id`, nullable `organization_unit_id`; own policy/rule FKs with `restrictOnDelete`; `retention_start_at`, `retention_due_at`; `disposition_action`; `lifecycle`; nullable `disposed_at`; `registered_by_user_id`; `lock_version`; timestamps.
   - Unique `(source_module, record_type, record_id)`; indexes `(lifecycle, retention_due_at, id)`, `(facility_id, lifecycle)`, `(organization_unit_id, lifecycle)`, `(record_type, classification_code)`.
4. `records_governance_holds`
   - UUIDv7 `id`; `scope_type`; bounded `scope_id`; reason; `placed_by_user_id`, `placed_at`; nullable `expires_at`, `released_at`, `released_by_user_id`, `release_reason`; `lock_version`; timestamps.
   - Index `(scope_type, scope_id, released_at, expires_at)` and `(placed_at, id)`. Multiple overlapping holds are allowed; all applicable holds must cease to be effective.
5. `records_governance_disposition_reviews`
   - UUIDv7 `id`; own `governed_record_id` FK with `restrictOnDelete`; decision, requested action, reason, reviewer/decision instant; status; nullable `active_slot`; nullable unique `source_confirmation_id`; nullable outcome, confirmed instant, confirming actor, opaque `evidence_document_id`, and safe detail; `lock_version`; timestamps.
   - Unique `(governed_record_id, active_slot)` so only one eligible unconfirmed review has `active_slot=1`; indexes `(status, created_at, id)` and `(governed_record_id, created_at)`.
6. `records_governance_evidence`
   - UUIDv7 `id` and unique `event_id`; `aggregate_type`, UUIDv7 `aggregate_id`, positive sequence, nullable `previous_hash`, `entry_hash`, event type, canonical JSON payload, nullable actor UUIDv7, correlation UUIDv7, occurred/created timestamps.
   - Unique `(aggregate_type, aggregate_id, sequence)`; indexes `(aggregate_type, aggregate_id, occurred_at)` and `(event_type, occurred_at)`; database triggers reject UPDATE and DELETE on MySQL and SQLite.
7. `records_governance_idempotency_keys`
   - UUIDv7 `id`; principal UUIDv7; operation; key hash; request hash; resource type/id; saved response status/body/ETag; timestamps.
   - Unique `(principal_id, operation, idempotency_key_hash)`; index `(resource_type, resource_id)`.

Migration order is exactly policy tables → record/hold/review tables → evidence/idempotency tables → immutability triggers. Central `module_migrations.php` registers those four paths in that order during serialized integration.

Published policy/version triggers reject update/delete of a published version and update/delete of its rules. Evidence triggers reject all update/delete. Local rollback runs the four `down()` methods in reverse only while every M02 table is empty. Once any evidence or governed record exists, production recovery is forward-only: keep all tables/triggers, restore from the P03-approved backup if data is damaged, and deploy corrected code. No rollback may cascade or truncate immutable evidence.

## 9. TDD implementation tasks

### Task 1: Prepare and validate the M02 registry delta without applying it

**Owner and read-only source surfaces:** M02 executor; `docs/architecture/planned-module-contracts.yaml::modules.RecordsGovernance` and `apps/api/tests/Architecture/ModuleBoundariesTest.php::{MODULE_RANKS,PLANNED_MODULES,TABLE_OWNERS}`. **Evidence:** `artifacts/verification/M02/registry-baseline.txt`, `artifacts/verification/M02/registry-delta.patch`, and `artifacts/verification/M02/registry-delta-check.txt`.

- [ ] Record that Architecture Closure has released the guard, M00 is completed, M01's earlier registry revision is merged/released, and M02 is next in the queue without granting `MODULE-REGISTRY`. Run `python3 scripts/validate-planned-module-contracts.py && cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php --filter='planned_modules|every_migrated_table_has_an_owner'`; expected PASS on the unchanged baseline, with `RecordsGovernance` rank 4 and still planned because its directory and migrations are not yet integrated. Save the complete output in `registry-baseline.txt`.
- [ ] Prepare, but do not apply, `registry-delta.patch`. It must preserve the existing `MODULE_RANKS['RecordsGovernance'] = 4`, remove only `RecordsGovernance` from `PLANNED_MODULES`, and add exactly the seven section-8 table names to `TABLE_OWNERS`, each mapped to `RecordsGovernance`; any rank edit, unrelated module/table edit, or eighth owner is invalid.
- [ ] Run `git apply --check artifacts/verification/M02/registry-delta.patch` followed by `git diff --exit-code -- apps/api/tests/Architecture/ModuleBoundariesTest.php`; expected exit 0 from both: the patch applies cleanly later, while all three registry constants remain byte-for-byte unchanged now. Save the commands, outputs, base SHA, and patch SHA-256 in `registry-delta-check.txt`.
- [ ] Task 1 reaches `completed` only with those three immutable evidence files and an unchanged registry source. Applying any part of the patch, granting/releasing the token, or removing the planned entry before Tasks 2–9 produce the real candidate is a ghost pre-registration and returns M02 to `blocked` with the partial change rejected.

### Task 2: Define the frozen domain, published Contracts, and Events

**Tests:** `RetentionPolicyLifecycleTest.php`, `GovernedRecordRegistrationTest.php`.

- [ ] Write failing tests for: invalid source token/UUIDv7; empty/oversized classifications; nonpositive retention; duplicate rule key; publication from non-draft; due-date computation; immutable registration snapshot; exact contract method signatures; and exact five event-type strings.
  Use these exact first assertions:

  ```php
  public function test_source_reference_rejects_non_uuid_v7_and_payload_like_tokens(): void
  {
      $this->expectException(InvalidArgumentException::class);
      new RecordSourceReference('documents', 'clinical_record', '{"patient":"secret"}');
  }

  public function test_event_types_use_the_frozen_unhyphenated_token(): void
  {
      self::assertSame(
          'com.cluster.recordsgovernance.retentionpolicyversionpublished.v1',
          RetentionPolicyVersionPublishedV1::TYPE,
      );
      self::assertSame(
          'com.cluster.recordsgovernance.dispositionoutcomeconfirmed.v1',
          DispositionOutcomeConfirmedV1::TYPE,
      );
  }
  ```
- [ ] Run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Domain/RetentionPolicyLifecycleTest.php Modules/RecordsGovernance/Tests/Application/GovernedRecordRegistrationTest.php`; expected FAIL because M02 classes do not exist.
- [ ] Implement the exact Contracts/DTOs/enums/events from sections 6 and 7. DTO constructors reject invalid values and expose readonly properties; event serializers output only schema-approved safe facts.
  The source-reference implementation is exact and deliberately has no Documents type:

  ```php
  final readonly class RecordSourceReference
  {
      public function __construct(
          public string $sourceModule,
          public string $recordType,
          public string $recordId,
      ) {
          if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $sourceModule) !== 1
              || trim($recordType) === ''
              || mb_strlen($recordType) > 128
              || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $recordId) !== 1) {
              throw new InvalidArgumentException('The governed record source reference is invalid.');
          }
      }
  }
  ```
- [ ] Create the five JSON Schemas with `additionalProperties: false`, required CloudEvent data fields, UUIDv7 patterns, UTC timestamps, fixed event type constants, and no arbitrary source payload object.
- [ ] Re-run the two tests; expected PASS and exact type strings under `com.cluster.recordsgovernance.*.v1`.

### Task 3: Create module-owned persistence and immutable evidence

**Tests:** `RecordsGovernanceMigrationTest.php`.

- [ ] Write a failing migration test that runs all four module migrations on SQLite, asserts exact columns/indexes/FKs, attempts to mutate published policy/rules, and attempts evidence UPDATE/DELETE.
  The failing immutability proof must execute real writes:

  ```php
  public function test_published_policy_and_evidence_are_database_immutable(): void
  {
      $this->runRecordsGovernanceMigrations();
      $this->seedPublishedPolicyAndEvidence();

      try {
          DB::table('records_governance_retention_policy_versions')
              ->where('id', self::POLICY_ID)
              ->update(['name' => 'rewritten']);
          self::fail('Published policy update was accepted.');
      } catch (QueryException) {
          self::assertSame('Published', DB::table('records_governance_retention_policy_versions')->value('name'));
      }

      try {
          DB::table('records_governance_evidence')->where('event_id', self::EVENT_ID)->delete();
          self::fail('Evidence deletion was accepted.');
      } catch (QueryException) {
          self::assertDatabaseHas('records_governance_evidence', ['event_id' => self::EVENT_ID]);
      }
  }
  ```
- [ ] Run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Persistence/RecordsGovernanceMigrationTest.php`; expected FAIL because migrations/tables are absent.
- [ ] Implement the seven exact tables, own-table FKs only, constraints, and both SQLite/MySQL immutability trigger variants.
  The migrations use these concrete table definitions; timestamps are millisecond precision and all UUIDs are stored without cross-owner foreign keys:

  ```php
  Schema::create('records_governance_retention_policy_versions', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->string('code', 64);
      $table->string('name', 255);
      $table->unsignedInteger('version');
      $table->string('status', 16);
      $table->dateTime('effective_at', 3)->nullable();
      $table->dateTime('published_at', 3)->nullable();
      $table->uuid('published_by_user_id')->nullable();
      $table->uuid('created_by_user_id');
      $table->unsignedInteger('lock_version')->default(1);
      $table->timestamps(3);
      $table->unique(['code', 'version'], 'rg_policy_code_version_uq');
      $table->index(['code', 'status', 'effective_at'], 'rg_policy_effective_idx');
  });

  Schema::create('records_governance_retention_policy_rules', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->foreignUuid('retention_policy_version_id')
          ->constrained('records_governance_retention_policy_versions')
          ->restrictOnDelete();
      $table->string('record_type', 128);
      $table->string('classification_code', 32);
      $table->unsignedInteger('retention_days');
      $table->string('disposition_action', 16);
      $table->timestamps(3);
      $table->unique(
          ['retention_policy_version_id', 'record_type', 'classification_code'],
          'rg_policy_rule_match_uq',
      );
      $table->index(['record_type', 'classification_code'], 'rg_policy_rule_lookup_idx');
  });

  Schema::create('records_governance_governed_records', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->string('source_module', 64);
      $table->string('record_type', 128);
      $table->uuid('record_id');
      $table->string('classification_code', 32);
      $table->uuid('facility_id');
      $table->uuid('organization_unit_id')->nullable();
      $table->foreignUuid('retention_policy_version_id')
          ->constrained('records_governance_retention_policy_versions')
          ->restrictOnDelete();
      $table->foreignUuid('retention_policy_rule_id')
          ->constrained('records_governance_retention_policy_rules')
          ->restrictOnDelete();
      $table->dateTime('retention_start_at', 3);
      $table->dateTime('retention_due_at', 3);
      $table->string('disposition_action', 16);
      $table->string('lifecycle', 24);
      $table->dateTime('disposed_at', 3)->nullable();
      $table->uuid('registered_by_user_id');
      $table->unsignedInteger('lock_version')->default(1);
      $table->timestamps(3);
      $table->unique(['source_module', 'record_type', 'record_id'], 'rg_source_record_uq');
      $table->index(['lifecycle', 'retention_due_at', 'id'], 'rg_record_due_idx');
      $table->index(['facility_id', 'lifecycle'], 'rg_record_facility_idx');
      $table->index(['organization_unit_id', 'lifecycle'], 'rg_record_unit_idx');
      $table->index(['record_type', 'classification_code'], 'rg_record_type_class_idx');
  });

  Schema::create('records_governance_holds', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->string('scope_type', 16);
      $table->string('scope_id', 128);
      $table->string('reason', 2000);
      $table->uuid('placed_by_user_id');
      $table->dateTime('placed_at', 3);
      $table->dateTime('expires_at', 3)->nullable();
      $table->dateTime('released_at', 3)->nullable();
      $table->uuid('released_by_user_id')->nullable();
      $table->string('release_reason', 2000)->nullable();
      $table->unsignedInteger('lock_version')->default(1);
      $table->timestamps(3);
      $table->index(['scope_type', 'scope_id', 'released_at', 'expires_at'], 'rg_hold_effective_idx');
      $table->index(['placed_at', 'id'], 'rg_hold_page_idx');
  });

  Schema::create('records_governance_disposition_reviews', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->foreignUuid('governed_record_id')
          ->constrained('records_governance_governed_records')
          ->restrictOnDelete();
      $table->string('decision', 16);
      $table->string('requested_action', 16);
      $table->string('reason', 2000);
      $table->uuid('reviewed_by_user_id');
      $table->dateTime('reviewed_at', 3);
      $table->string('status', 24);
      $table->boolean('active_slot')->nullable();
      $table->uuid('source_confirmation_id')->nullable()->unique('rg_confirmation_uq');
      $table->string('outcome', 16)->nullable();
      $table->dateTime('confirmed_at', 3)->nullable();
      $table->uuid('confirmed_by_user_id')->nullable();
      $table->uuid('evidence_document_id')->nullable();
      $table->string('detail', 2000)->nullable();
      $table->unsignedInteger('lock_version')->default(1);
      $table->timestamps(3);
      $table->unique(['governed_record_id', 'active_slot'], 'rg_one_active_review_uq');
      $table->index(['status', 'created_at', 'id'], 'rg_review_page_idx');
      $table->index(['governed_record_id', 'created_at'], 'rg_review_record_idx');
  });

  Schema::create('records_governance_evidence', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->uuid('event_id')->unique('rg_evidence_event_uq');
      $table->string('aggregate_type', 32);
      $table->uuid('aggregate_id');
      $table->unsignedBigInteger('sequence');
      $table->char('previous_hash', 64)->nullable();
      $table->char('entry_hash', 64);
      $table->string('event_type', 128);
      $table->json('payload');
      $table->uuid('actor_user_id')->nullable();
      $table->uuid('correlation_id');
      $table->dateTime('occurred_at', 3);
      $table->timestamp('created_at', 3);
      $table->unique(['aggregate_type', 'aggregate_id', 'sequence'], 'rg_evidence_chain_uq');
      $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'rg_evidence_aggregate_idx');
      $table->index(['event_type', 'occurred_at'], 'rg_evidence_type_idx');
  });

  Schema::create('records_governance_idempotency_keys', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->uuid('principal_id');
      $table->string('operation', 128);
      $table->char('idempotency_key_hash', 64);
      $table->char('request_hash', 64);
      $table->string('resource_type', 64);
      $table->uuid('resource_id');
      $table->unsignedSmallInteger('response_status');
      $table->json('response_payload');
      $table->string('response_etag', 32)->nullable();
      $table->timestamps(3);
      $table->unique(
          ['principal_id', 'operation', 'idempotency_key_hash'],
          'rg_idempotency_replay_uq',
      );
      $table->index(['resource_type', 'resource_id'], 'rg_idempotency_resource_idx');
  });
  ```

  `AddRecordsGovernanceImmutabilityGuards.php` must emit concrete driver-specific triggers. The SQLite evidence delete guard is:

  ```php
  DB::unprepared(
      "CREATE TRIGGER rg_evidence_no_delete
       BEFORE DELETE ON records_governance_evidence
       BEGIN
         SELECT RAISE(ABORT, 'records governance evidence is append-only');
       END",
  );
  ```

  The MySQL published-version update guard is:

  ```php
  DB::unprepared(
      "CREATE TRIGGER rg_published_policy_no_update
       BEFORE UPDATE ON records_governance_retention_policy_versions
       FOR EACH ROW
       BEGIN
         IF OLD.status = 'published' THEN
           SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'published retention policy versions are immutable';
         END IF;
       END",
  );
  ```

  Create and drop these exact trigger names in the fourth migration for both drivers: `rg_evidence_no_update` and `rg_evidence_no_delete` always reject; `rg_published_policy_no_update` and `rg_published_policy_no_delete` reject when `OLD.status = 'published'`; `rg_published_rule_no_update` and `rg_published_rule_no_delete` reject when the parent policy selected by `OLD.retention_policy_version_id` is published. The migration test asserts all six names and both operations.
- [ ] Implement evidence canonicalization with recursively sorted object keys, JSON flags `JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`, and SHA-256 over the invariant tuple from section 5.
- [ ] Re-run the migration test; expected PASS, with database errors on forbidden policy/evidence mutations and no source-module FK.

### Task 4: Implement policy publication and governed-record registration

**Tests:** `RetentionPolicyLifecycleTest.php`, `GovernedRecordRegistrationTest.php`, `RecordsGovernanceAtomicityTest.php`.

- [ ] Add failing tests for complete-draft creation, duplicate code/version conflict, publication ETag, registration against draft/unknown policy, no matching rule, idempotent replay, changed-payload conflict, stable policy pinning, authorization facts, evidence/outbox atomicity, and repository exception rollback.
  The atomic rollback test uses the real transaction boundary, not call-count mocks:

  ```php
  public function test_registration_rolls_back_state_idempotency_evidence_and_outbox_together(): void
  {
      $this->app->bind(TransactionalOutbox::class, FailingTransactionalOutbox::class);

      try {
          $this->registrar()->register($this->registration('atomic-key'));
          self::fail('Registration unexpectedly committed.');
      } catch (RuntimeException $exception) {
          self::assertSame('forced outbox failure', $exception->getMessage());
      }

      self::assertDatabaseCount('records_governance_governed_records', 0);
      self::assertDatabaseCount('records_governance_idempotency_keys', 0);
      self::assertDatabaseCount('records_governance_evidence', 0);
      self::assertDatabaseCount('outbox_events', 0);
  }
  ```
- [ ] Run the three test files; expected FAIL at missing services/repositories.
- [ ] Implement repositories and `RetentionPolicyService`/`GovernedRecordService`. Wrap each command in `DB::transaction`; enforce publication/update predicates with `where('lock_version', $expected)`; append evidence and the shared outbox before commit; persist replay response in the same transaction.
  The optimistic publication write remains inside the same closure:

  ```php
  return DB::transaction(function () use ($versionId, $expectedVersion, $actor, $command): array {
      $updated = DB::table('records_governance_retention_policy_versions')
          ->where('id', $versionId)
          ->where('status', 'draft')
          ->where('lock_version', $expectedVersion)
          ->update([
              'status' => 'published',
              'published_at' => now(),
              'published_by_user_id' => $actor->userId,
              'lock_version' => $expectedVersion + 1,
              'updated_at' => now(),
          ]);
      if ($updated !== 1) {
          throw StaleRetentionPolicyVersion::for($versionId, $expectedVersion);
      }

      $this->evidence->append($command->evidence());
      $this->outbox->append($command->eventId, $versionId, RetentionPolicyVersionPublishedV1::TYPE, $command->eventData());
      $this->idempotency->store($command->replay($expectedVersion + 1));

      return $this->policies->getPublished($versionId);
  });
  ```
- [ ] Use `RetentionPolicyVersionPublishedV1` on publication and `GovernedRecordStatusChangedV1` on registration. Never write a source table.
- [ ] Re-run the three files; expected PASS with one state row, one replay row, one evidence entry, and one outbox row per first command, and no duplicate on replay.

### Task 5: Implement legal holds and linearizable precedence

**Tests:** `RecordHoldPrecedenceTest.php`, `RecordsGovernanceMySqlConcurrencyTest.php`.
**Owner and exact paths/symbols:** M02 executor creates `apps/api/Modules/RecordsGovernance/Tests/MySql/RecordsGovernanceMySqlConcurrencyTest.php::RecordsGovernanceMySqlConcurrencyTest` and its sentinel method; the serialized `MYSQL-SUITE` owner later edits only `apps/api/phpunit.mysql.xml::<testsuite name="MySQL integration">` in Task 10. **Evidence/status gate:** retain `artifacts/verification/M02/mysql-list-tests.txt` and `artifacts/verification/M02/mysql-run.txt`; Task 5 may finish module-owned behavior before suite registration, but M02 cannot enter `verification` until Task 10 proves exact-class discovery, MySQL driver, nonzero tests/assertions, and no skip.

- [ ] Write failing cases for direct/facility/unit/record-type hold matching; unrelated hold nonmatching; future/expired/released hold behavior; overlapping holds; release ETag; replay/conflict; hold placement racing eligibility; and source guard racing hold placement.
  The hold-precedence test must prove the observable conflict and absence of execution:

  ```php
  public function test_effective_hold_always_blocks_eligible_disposition(): void
  {
      $record = $this->dueGovernedRecord(facilityId: self::FACILITY_ID);
      $this->holds()->place($this->facilityHold(self::FACILITY_ID, 'litigation'));

      $response = $this->postJson(
          '/api/v1/records-governance/disposition-reviews',
          ['governed_record_id' => $record->id, 'decision' => 'eligible', 'reason' => 'retention elapsed'],
          $this->commandHeaders('held-review'),
      );

      $response->assertStatus(409)
          ->assertHeader('Content-Type', 'application/problem+json')
          ->assertJsonPath('type', 'https://cluster.example/problems/record-hold-active');
      self::assertDatabaseMissing('records_governance_disposition_reviews', [
          'governed_record_id' => $record->id,
          'decision' => 'eligible',
      ]);
      self::assertDatabaseMissing('outbox_events', [
          'event_type' => DispositionExecutionRequestedV1::TYPE,
      ]);
  }
  ```
- [ ] In the MySQL test, declare the exact class `Modules\RecordsGovernance\Tests\MySql\RecordsGovernanceMySqlConcurrencyTest` and exact sentinel method `test_m02_mysql_lane_is_registered_and_uses_mysql`. The sentinel performs `self::assertSame('mysql', DB::connection()->getDriverName())` and must fail, never skip, under any other driver. Use two independent connections/processes for the concurrency cases. Transaction A locks the governed record through `GuardDispositionExecution::evaluate`; Transaction B attempts hold placement. Assert B cannot commit before A, then assert the transaction that linearizes first determines the outcome. No test may skip after the MySQL runner starts.
  Use two forked workers and two named connections; the parent owns the barriers so the test never relies on sleeps alone:

  ```php
  public function test_guard_lock_serializes_concurrent_hold_placement(): void
  {
      config([
          'database.connections.guard_a' => config('database.connections.mysql'),
          'database.connections.guard_b' => config('database.connections.mysql'),
      ]);
      $barrier = sys_get_temp_dir().'/rg-lock-'.Str::uuid7();
      mkdir($barrier);

      $guardPid = pcntl_fork();
      if ($guardPid === 0) {
          DB::connection('guard_a')->transaction(function () use ($barrier): void {
              $decision = $this->guard('guard_a')->evaluate($this->sourceReference());
              self::assertTrue($decision->allowed);
              file_put_contents($barrier.'/guard-locked', '1');
              while (! is_file($barrier.'/release-guard')) {
                  usleep(10_000);
              }
          });
          exit(0);
      }

      $this->waitForFile($barrier.'/guard-locked');
      $holdPid = pcntl_fork();
      if ($holdPid === 0) {
          $this->holds('guard_b')->place($this->directHold());
          file_put_contents($barrier.'/hold-committed', '1');
          exit(0);
      }

      usleep(200_000);
      self::assertFileDoesNotExist($barrier.'/hold-committed');
      file_put_contents($barrier.'/release-guard', '1');
      pcntl_waitpid($guardPid, $guardStatus);
      pcntl_waitpid($holdPid, $holdStatus);
      self::assertSame(0, pcntl_wexitstatus($guardStatus));
      self::assertSame(0, pcntl_wexitstatus($holdStatus));
      self::assertFileExists($barrier.'/hold-committed');
  }
  ```
- [ ] Run the SQLite test; expected FAIL at missing hold service. Run the MySQL file through the same environment contract as `scripts/run-mysql-integration-tests.sh`; expected FAIL before implementation.
- [ ] Implement `RecordHoldService` and `GuardDispositionExecution`. Both lock the governed-record row first. Guard requires an open DB transaction, evaluates all four scopes at one transaction timestamp, and returns a denial instead of throwing information-rich errors.
  The production guard has one lock order and fails closed:

  ```php
  public function evaluate(RecordSourceReference $source): DispositionExecutionDecision
  {
      if (DB::connection()->transactionLevel() < 1) {
          return DispositionExecutionDecision::denied('transaction_required');
      }

      try {
          $record = DB::table('records_governance_governed_records')
              ->where('source_module', $source->sourceModule)
              ->where('record_type', $source->recordType)
              ->where('record_id', $source->recordId)
              ->lockForUpdate()
              ->first();
          if ($record === null) {
              return DispositionExecutionDecision::denied('record_unknown');
          }
          if ($this->holds->hasEffectiveHold($record, now())) {
              return DispositionExecutionDecision::denied('hold_active', (int) $record->lock_version);
          }

          $review = $this->reviews->activeEligibleForRecord((string) $record->id);
          return $review === null
              ? DispositionExecutionDecision::denied('review_missing', (int) $record->lock_version)
              : DispositionExecutionDecision::allowed(
                  (string) $review->id,
                  (int) $record->lock_version,
              );
      } catch (Throwable) {
          return DispositionExecutionDecision::denied('governance_unavailable');
      }
  }
  ```
- [ ] Emit `RecordHoldChangedV1` for place/release; append evidence/idempotency/audit/outbox atomically. Hold placement never edits governed-record/source status simply to mirror the hold.
- [ ] Re-run SQLite and MySQL tests; expected PASS and deterministic hold-first/execution-first outcomes.

### Task 6: Implement disposition review, request, and confirmation

**Tests:** `DispositionLifecycleTest.php`, `RecordsGovernanceAtomicityTest.php`.

- [ ] Write failing cases for not-due records, active hold precedence, retained/rejected decisions, one active eligible review, `DispositionExecutionRequestedV1`, source confirmation outcomes, duplicate confirmation ID, stale ETag, retry replay, audit/outbox failure rollback, and opaque evidence-document reference preservation.
  Include this direct service invariant, separate from HTTP serialization:

  ```php
  public function test_confirmation_is_evidence_and_never_deletes_the_source(): void
  {
      $review = $this->eligibleReview();
      $this->sourceStore()->insert(['id' => self::SOURCE_ID, 'status' => 'archived']);

      $confirmed = $this->dispositions()->confirm(
          reviewId: $review->id,
          expectedVersion: $review->lockVersion,
          outcome: 'disposed',
          sourceConfirmationId: self::CONFIRMATION_ID,
          evidenceDocumentId: self::EVIDENCE_DOCUMENT_ID,
      );

      self::assertSame('disposed', $confirmed->outcome);
      self::assertDatabaseHas('synthetic_source_records', [
          'id' => self::SOURCE_ID,
          'status' => 'archived',
      ]);
      self::assertDatabaseHas('records_governance_disposition_reviews', [
          'id' => $review->id,
          'evidence_document_id' => self::EVIDENCE_DOCUMENT_ID,
      ]);
  }
  ```
- [ ] Run both files; expected FAIL at missing `DispositionService`.
- [ ] Implement eligibility under the governed-record lock. `eligible` is rejected with generic `409 record-hold-active` when any hold applies, even if due; `retained`/`rejected` close immediately; eligible creates `active_slot=1` and emits the request event.
- [ ] Implement confirmation as evidence only: it changes review/governed-record lifecycle, clears `active_slot`, emits `DispositionOutcomeConfirmedV1`, and never deletes a source/document. `disposed` and `retained` are terminal confirmations; `failed` remains queryable and requires a new authorized review for retry.
- [ ] Re-run both files; expected PASS with atomic state/idempotency/evidence/outbox behavior.

### Task 7: Add HTTP adapters, authorization, pagination, and production bindings

**Tests:** `RecordsGovernanceHttpAdapterTest.php`.
**Owner and exact paths/symbols:** M02 executor owns `apps/api/Modules/RecordsGovernance/Application/RecordsGovernanceQueryService.php`, the four list controllers named below, and `apps/api/Modules/RecordsGovernance/Tests/Http/RecordsGovernanceHttpAdapterTest.php`; Architecture Closure Task 12 owns the consumed Shared cursor codec path/interface, which M02 records from its handoff and never edits. **Evidence/status gate:** `artifacts/verification/M02/cursor-red.txt` and `cursor-green.txt`; Task 7 cannot complete until all named cursor tests pass against the production Shared codec binding.

- [ ] Write failing HTTP tests for all twelve operations, exact capability mapping, 401/session, CSRF, 403-before-validation, generic 404, problem+json 400/409/412, ETag, idempotent replay, correlation propagation, cursor limit bounds, authenticated-cursor tampering, cross-principal and cross-scope reuse, changed filters/sort/page size, and no source payload leakage.
  The authorization-order test sends an intentionally malformed body and proves validation did not run first:

  ```php
  public function test_denial_precedes_detailed_validation_and_resource_disclosure(): void
  {
      $this->denyCapability('records_governance.disposition.review');

      $this->postJson(
          '/api/v1/records-governance/disposition-reviews',
          ['governed_record_id' => 'not-a-uuid', 'decision' => 'unknown'],
          $this->commandHeaders('denied-before-validation'),
      )->assertStatus(403)
          ->assertJsonMissing(['governed_record_id', 'decision'])
          ->assertJsonPath('type', 'https://cluster.example/problems/forbidden');
  }
  ```
- [ ] Add named HTTP tests `test_cursor_rejects_tamper_without_querying`, `test_cursor_rejects_cross_principal_or_scope_reuse`, and `test_cursor_rejects_filter_sort_or_page_size_change`. Instrument `RecordsGovernanceQueryService` with the test connection query log and assert its list SELECT count remains zero after decode rejection. Run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Http/RecordsGovernanceHttpAdapterTest.php --filter='cursor_rejects|cursor_traverses_duplicate_sort_keys'`; expected FAIL because the Shared codec has not been injected and the list handlers do not enforce the bound context. Retain the red output under `artifacts/verification/M02/cursor-red.txt`.
- [ ] In test setup, register `RecordsGovernanceServiceProvider`, run the four M02 migrations directly, and bind deterministic `DecideAccess`, principal, outbox, and audit test implementations. Do not edit `AppServiceProvider.php` while its token is unavailable.
- [ ] Run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Http/RecordsGovernanceHttpAdapterTest.php`; expected FAIL because controllers/provider do not exist.
- [ ] Implement `RecordsGovernanceApi`, controllers, query service, and provider. Controllers authorize with coarse safe `RecordFacts` before detailed validation; application services alone coordinate persistence. Inject the exact canonical Shared authenticated cursor codec path/interface recorded by the Architecture Closure Task 12 handoff into `RecordsGovernanceQueryService`; do not add cursor encode/decode logic anywhere under `Modules/RecordsGovernance`.
  The queued route block is exact:

  ```php
  Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])
      ->prefix('records-governance')
      ->group(function (): void {
          Route::get('retention-policy-versions', ListRetentionPolicyVersionsController::class);
          Route::get('governed-records', ListGovernedRecordsController::class);
          Route::get('governed-records/{governedRecordId}', GetGovernedRecordStatusController::class);
          Route::get('holds', ListRecordHoldsController::class);
          Route::get('disposition-reviews', ListDispositionReviewsController::class);

          Route::middleware(IdentityCsrfMiddleware::class)->group(function (): void {
              Route::post('retention-policy-versions', CreateRetentionPolicyVersionController::class);
              Route::post('retention-policy-versions/{versionId}/publish', PublishRetentionPolicyVersionController::class);
              Route::post('governed-records', RegisterGovernedRecordController::class);
              Route::post('holds', PlaceRecordHoldController::class);
              Route::post('holds/{holdId}/release', ReleaseRecordHoldController::class);
              Route::post('disposition-reviews', DecideDispositionEligibilityController::class);
              Route::post('disposition-reviews/{reviewId}/confirm', ConfirmDispositionOutcomeController::class);
          });
      });
  ```
- [ ] Every cursor page uses the canonical Shared authenticated cursor codec, never bare base64url or a module-local encryption format. Use resource keys `records-governance.retention-policy-versions`, `records-governance.governed-records`, `records-governance.holds`, and `records-governance.disposition-reviews` with exact sort tuples `(code ASC, version DESC, id ASC)`, `(lifecycle ASC, retention_due_at ASC, id ASC)`, `(placed_at DESC, id DESC)`, and `(status ASC, created_at DESC, id DESC)` respectively. The authenticated payload binds codec version, resource key, exact sort tuple and directions, normalized fingerprint of every active filter, requested page size, principal UUID, and resolved authorization scope (facility/unit identifiers plus scope epoch). Decode must require exact equality with the current request context before issuing the page query. Tests prove a valid next page with duplicate leading sort values; one-byte tampering; principal A's cursor used by principal B; changed facility/unit scope or epoch; addition/removal/change of any filter; page-size change; and use against another list/sort all return `400 invalid-pagination` with no fallback to page one and no query side effect. After successful decode, query with the strict lexicographic tie-break predicate, fetch `limit + 1`, and return `next_cursor=null` at exhaustion. Limit is 1–100, default 50.
- [ ] Bind all four published contracts to database services and fail application boot if a required production binding is unavailable. Audit remains test-only until Task 9.
- [ ] Re-run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Http/RecordsGovernanceHttpAdapterTest.php --filter='cursor_rejects|cursor_traverses_duplicate_sort_keys'`; expected PASS with all named rejection cases returning `400 invalid-pagination`, zero list SELECTs after rejected decode, and the valid duplicate-sort traversal returning each ID once. Retain the green output under `artifacts/verification/M02/cursor-green.txt`.
- [ ] Re-run the HTTP test; expected PASS with `application/problem+json`, `X-Correlation-ID`, and quoted numeric ETags.

### Task 8: Build the module-owned web workspace

**Tests:** `records-governance.test.ts`, `RecordsGovernanceWorkspace.test.tsx`.

- [ ] Write failing API-wrapper tests that assert generated operation use, session/CSRF request initialization, unique idempotency keys, ETags, cursor forwarding, problem classification, and no raw `fetch` in feature components.
- [ ] Write failing workspace tests for capability-gated tabs/actions, labeled schedule form, governed-record status, effective-hold warning, release confirmation, hold-vs-disposition disabled state, stale-write refresh, conflict message, pagination, keyboard tab order, focus on error summary, and `aria-live` mutation result.
  The critical UI assertion is behavior-first:

  ```tsx
  it('prevents disposition while held and restores focus after releasing the final hold', async () => {
    const user = userEvent.setup()
    render(<RecordsGovernanceWorkspace capabilities={ALL_GOVERNANCE_CAPABILITIES} />)

    await user.click(await screen.findByRole('tab', { name: 'Disposition reviews' }))
    expect(await screen.findByRole('button', { name: 'Approve disposition' })).toBeDisabled()
    expect(screen.getByText('Disposition is blocked by 2 effective holds.')).toBeVisible()

    await user.click(screen.getByRole('tab', { name: 'Holds' }))
    const release = await screen.findByRole('button', { name: 'Release direct hold' })
    await user.click(release)
    await user.click(screen.getByRole('button', { name: 'Confirm release' }))
    expect(release).toHaveFocus()
    expect(screen.getByRole('status')).toHaveTextContent('One effective hold remains')
  })
  ```
- [ ] Run `npm --prefix apps/web run test:unit -- src/api/records-governance.test.ts src/features/records-governance/RecordsGovernanceWorkspace.test.tsx`; expected FAIL because files/generated operations are absent.
- [ ] Implement the wrapper and four panels. Use semantic headings, tabs/buttons, labeled controls, table captions, non-color status text/icons, 44×44 CSS-pixel action targets, visible focus, and confirmation dialogs that return focus to the invoking control.
  The wrapper delegates headers and generated types to the established boundary:

  ```ts
  import * as generated from './generated/cluster'
  import { requestInit, unwrap } from './http'

  export async function decideDispositionEligibility(
    token: string,
    body: generated.DispositionDecision,
  ): Promise<generated.DispositionReviewResponse> {
    return unwrap(
      await generated.decideDispositionEligibility(
        body,
        requestInit(token, {
          command: true,
          idempotency: crypto.randomUUID(),
        }),
      ),
    )
  }
  ```
- [ ] Keep feature routes shell-independent until the `WEB-SHELL` token. Tests mock the stable wrapper while Orval is still blocked.
- [ ] Re-run the two tests; expected PASS without browser persistence of source references or reasons.

### Task 9: Complete the explicitly blocked M01 audit integration

**Dependency:** M01 is integrated and publishes `RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` with a real production binding.

- [ ] If M01 is incomplete, set M02 to `blocked` with blocker `M01:audit-integration`; do not bind `InMemoryRecordAuditEvent` in production and do not enter verification.
- [ ] Add failing atomicity tests for each M02 mutation: exact action/resource/scope/classification/correlation/outcome facts reach M01, and a thrown audit exception leaves no M02 state, idempotency, evidence, or outbox row.
  Assert the exact M01 DTO, including regulated retention and safe context:

  ```php
  self::assertEquals(
      new AuditEventInput(
          eventId: self::AUDIT_EVENT_ID,
          sourceModule: 'records_governance',
          action: 'record_hold.place',
          eventType: RecordHoldChangedV1::TYPE,
          actorType: 'user',
          actorId: self::ACTOR_ID,
          originalActorId: null,
          subjectType: 'record_hold',
          subjectId: self::HOLD_ID,
          correlationId: self::CORRELATION_ID,
          outcome: 'succeeded',
          classification: 'confidential',
          context: ['scope_type' => 'record', 'scope_id' => self::GOVERNED_RECORD_ID],
          occurredAt: self::OCCURRED_AT,
          retentionClass: 'regulated',
      ),
      $audit->recorded[0],
  );
  ```
- [ ] Run `cd apps/api && php artisan test Modules/RecordsGovernance/Tests/Application/RecordsGovernanceAtomicityTest.php`; expected FAIL until services call the real M01 contract inside their transaction.
- [ ] Inject `Modules\Audit\Contracts\RecordAuditEvent`; record safe facts before commit for policy publication, registration, hold place/release, eligibility decision, and outcome confirmation. Do not duplicate M01 persistence or create an M02 audit table.
  Use exact actions `retention_policy.publish`, `governed_record.register`, `record_hold.place`, `record_hold.release`, `disposition.review`, and `disposition.confirm`. `AuditEventInput` fields remain in M01's canonical order: `eventId`, `sourceModule`, `action`, `eventType`, `actorType`, nullable `actorId`, nullable `originalActorId`, `subjectType`, nullable `subjectId`, `correlationId`, `outcome`, `classification`, safe `context`, `occurredAt`, and `retentionClass`. M01 is synchronous, joins the caller transaction, idempotently replays equal event ID/payload, conflicts on mismatch, and throws fail closed on validation, redaction, integrity, persistence, or outbox failure.
- [ ] Re-run the atomicity test; expected PASS with one M01 receipt and complete rollback on audit failure.

### Task 10: Process serialized shared integrations

- [ ] `MODULE-REGISTRY` + `MODULE-RUNTIME` atomic cutover: only after Tasks 2–9 pass on the candidate commit and `apps/api/Modules/RecordsGovernance/Providers/RecordsGovernanceServiceProvider.php` plus all four section-6 migration files exist, acquire both serialized tokens on one recorded clean base. In one indivisible integration change, merge the complete real `apps/api/Modules/RecordsGovernance/` directory, add the provider to `AppServiceProvider::MODULE_PROVIDERS`, add the four migrations to `config/module_migrations.php` in section-8 order, apply the validated Task 1 delta by preserving rank 4, removing only `RecordsGovernance` from `PLANNED_MODULES`, and adding exactly the seven real tables to `TABLE_OWNERS`. Run `python3 scripts/validate-planned-module-contracts.py && make verify-boundaries` plus the migration test on a fresh disposable database; expected PASS with the module directory, all owned migrations, migration manifest, rank, planned-list transition, and table owners present at the same merge SHA. A missing file/owner, registry-only commit, ghost owner, or separate registry/runtime merge revokes both tokens and rejects the entire cutover.
- [ ] `AUTH-CAPABILITIES`: after the Authorization owner handoff, add exactly the ten section-7 capabilities to `CapabilityCatalog`, its complete-fixture test, and `AuthorizationCatalogSeeder`; no M02 migration writes Authorization tables.
- [ ] `OUTBOX-CONTRACT`: add the five exact enum values to `OutboxEventType`; verify each resolves to the new schema file.
- [ ] `API-ROUTES`: after current route ownership release and M01's token, add the twelve routes under Identity session/principal middleware and CSRF on mutations.
- [ ] `OPENAPI`/`ORVAL`: after current Task 12 release and M01's token, apply the section-7 contract changes, flip only verified M02 operations to `implemented`, run `npm --prefix apps/web run api:generate`, and retain the generated diff. Never hand-edit the client.
- [ ] `WEB-SHELL`: add `/records-governance`, typed route parsing/serialization, capability-aware navigation, and workspace rendering after M01's shell token; do not claim M07's final aggregation token.
- [ ] `MYSQL-SUITE`: add exactly `<file>Modules/RecordsGovernance/Tests/MySql/RecordsGovernanceMySqlConcurrencyTest.php</file>` to `apps/api/phpunit.mysql.xml`. Run `cd apps/api && php vendor/bin/phpunit -c phpunit.mysql.xml --list-tests`; expected output includes exact class `Modules\RecordsGovernance\Tests\MySql\RecordsGovernanceMySqlConcurrencyTest`, exact method `test_m02_mysql_lane_is_registered_and_uses_mysql`, and a nonzero M02 test count. Then run `make verify-mysql-integration`; expected exit 0 with no `SKIP`, and the registered sentinel fails the suite unless the active driver is exactly `mysql`. Retain list output and runner output with class, method, parsed test count, parsed assertion count, and driver sentinel evidence; broad suite success without this registration/evidence is failure by omission.
- [ ] For each token, run its focused tests, record `requested → granted → merged → released`, and release before M03. A stale base revokes/rebases the token rather than resolving concurrent edits ad hoc.

### Task 11: Smoke and final verification

- [ ] Run every command in section 11 on the integrated recorded commit; any skipped, stale, missing, or failed critical result blocks completion.
- [ ] Execute every smoke scenario in section 11 against a production-like MySQL runtime with real production bindings; test fakes are forbidden.
- [ ] Write the fixed evidence manifest from section 14 and verify every referenced file and SHA-256 exists.
- [ ] Populate the manifest's `mysql_sentinel` only from the exact registered-class discovery and execution evidence; a missing class/method, zero test/assertion count, non-MySQL driver, skip marker, or broad runner result without the M02 sentinel keeps M02 in `in_progress`.
- [ ] Move to `verification`, then to `completed` only after section 14 exit criteria pass and an implementation commit is recorded after user authorization.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

| Condition | Required behavior |
|---|---|
| Missing/invalid session | Generic `401`; no resource lookup or write. |
| Missing capability | Generic `403` before detailed validation/disclosure. |
| Invalid correlation/idempotency/payload/cursor | RFC 7807 `400`; no side effect. |
| Cursor tamper or resource/principal/scope/filter/sort/page-size mismatch | RFC 7807 `400 invalid-pagination`; no fallback to the first page and no query/side effect. |
| Unknown or nonvisible resource after authorization | Generic `404`; no existence leak. |
| Same idempotency key, same request | Replay stored status/body/ETag; no new evidence/audit/outbox. |
| Same idempotency key, changed request | RFC 7807 `409 idempotency-conflict`. |
| Illegal lifecycle or duplicate open review | RFC 7807 `409`. |
| Effective hold during eligibility | RFC 7807 `409 record-hold-active`; no execution event. |
| Guard outside caller transaction or dependency unavailable | Denied `DispositionExecutionDecision`; fail closed. |
| Missing/malformed/stale `If-Match` | RFC 7807 `412`; write predicate changes zero rows. |
| Concurrent publish/release/confirm | Exactly one write succeeds; loser receives 412 or idempotent replay. |
| Audit/evidence/outbox failure | Entire command transaction rolls back, including idempotency. |
| Source disposition failure | Source remains owner; confirmation records `failed`; M02 never compensates by deleting. |
| Outbox redelivery | Event ID and consumer inbox semantics deduplicate; event payload/version is stable. |

Reasons are bounded and treated as sensitive free text: returned only to authorized readers, excluded from event summary fields and logs, and passed to M01 according to its classification contract. Capability checks use safe scope/classification facts and never trust caller-supplied ownership without repository resolution.

## 11. Targeted verification commands and smoke scenarios

### Commands

1. `cd apps/api && php artisan test Modules/RecordsGovernance/Tests`
   - Expected: PASS; zero skipped M02 tests, including cursor tamper/cross-principal/scope/filter/sort/page-size rejection.
2. `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
   - Expected: PASS; M02 rank 4, no planned-directory violation, exact table ownership, atomic directory+migrations+registry cutover, and no Documents import/direct SQL.
3. `cd apps/api && php vendor/bin/phpunit -c phpunit.mysql.xml --list-tests`
   - Expected: PASS; output contains `Modules\RecordsGovernance\Tests\MySql\RecordsGovernanceMySqlConcurrencyTest::test_m02_mysql_lane_is_registered_and_uses_mysql` and a parsed M02 test count greater than zero.
4. `make verify-mysql-integration`
   - Expected: PASS after prerequisites are present with no `SKIP`; the exact registered M02 class executes a nonzero test/assertion count and its sentinel proves `DB::connection()->getDriverName() === 'mysql'`. Omission, zero discovery, a non-MySQL driver, or a prerequisite skip blocks completion.
5. `npm --prefix apps/web run test:unit -- src/api/records-governance.test.ts src/features/records-governance/RecordsGovernanceWorkspace.test.tsx src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx`
   - Expected: PASS.
6. `npm --prefix apps/web run api:check`
   - Expected: PASS and zero generated-client drift after `api:generate`.
7. `npm --prefix apps/web run build`
   - Expected: PASS; typed M02 generated calls and workspace compile.
8. `composer --working-dir=apps/api analyse -- --memory-limit=512M`
   - Expected: PASS with no M02 static-analysis error.
9. `composer --working-dir=apps/api lint`
   - Expected: PASS with no M02 format violation.

### Smoke scenarios

1. **Publish and pin:** create a schedule, publish with ETag, register a confidential record, then publish a later version. The record retains its original version/rule/due date.
2. **Hold wins before review:** place a facility hold, attempt eligible disposition for a due record, and receive 409 with no request event.
3. **Hold wins before deletion:** approve an eligible review, place a hold before the source transaction calls the guard, and observe guard denial and no source mutation.
4. **Linearized execution wins after it starts:** source transaction acquires the guard lock first and performs its source-owned mutation; concurrent hold waits and linearizes afterward. Confirmation records the already completed outcome without deleting anything.
5. **Overlapping holds:** place direct and unit holds, release one, and confirm the record remains held until the second is released/expired.
6. **Idempotent confirmation:** submit the same confirmation twice with the same key and request; receive the same response/ETag and one evidence/audit/outbox effect. Reuse the key with a different outcome and receive 409.
7. **Atomic failure:** force M01 audit or outbox append to fail; confirm no state, idempotency, evidence, or outbox row commits.
8. **Opaque document evidence:** confirm with an evidence-document UUID; response/event carries only the UUID and safe metadata, with no Documents query/FK/import.
9. **Authorization order:** send malformed payload as an unauthorized principal and receive generic 403, not validation details.
10. **Authenticated cursor isolation:** fetch page one for each M02 list, follow its valid cursor, then replay one-byte-tampered, cross-principal, changed-scope, changed-filter, changed-sort/resource, and changed-page-size variants. Every invalid variant returns `400 invalid-pagination`, never page one, while the valid traversal has no duplicate/omitted tie-key row.
11. **Web accessibility:** keyboard-only create/publish/place/release/review flow preserves visible focus, announces results, labels every input/table, and does not rely on color.

## 12. Shared-file integration token requirements

Every token record uses the orchestration protocol fields: token, state, requesting plan, releasing owner, base commit, exact surface list, grant evidence, merge commit, and release result.

- `MODULE-REGISTRY` is prepared read-only in Task 1 and granted only for Task 10's indivisible cutover that integrates the real module directory, all four owned migrations, migration manifest, fixed rank, planned-list removal, and seven table owners at one merge SHA. It is never acquired for pre-registration or a registry-only correction.
- `MODULE-RUNTIME` covers `AppServiceProvider.php` and `config/module_migrations.php` after Architecture Closure Task 6 handoff and shares the Task 10 atomic merge SHA with `MODULE-REGISTRY`.
- `AUTH-CAPABILITIES` covers Authorization catalog/seeder/test after the active Authorization owner releases them.
- `OUTBOX-CONTRACT` covers only the shared enum; new M02 schema files are created on the M02 branch.
- `API-ROUTES` waits for `apps/api/routes/web.php` release.
- `OPENAPI` and `ORVAL` are one contract token; generation is the only allowed client change.
- `WEB-SHELL` covers typed route/navigation/workspace shared files; M07 retains final aggregation ownership.
- `MYSQL-SUITE` serializes `phpunit.mysql.xml` inclusion so modules do not overwrite one another.
- P08 alone may later add M02 commands to final Make/CI closure. M02 never takes `CLOSURE-CI`.

A module cannot be `completed` until every required token is released and final tests pass on the integrated branch. M02 releases its queue position before M03 begins its corresponding integration.

## 13. Rollback procedure

1. Stop new M02 mutations at the route integration layer; do not disable source-owner retention or holds.
2. Stop/park M02 outbox consumers without deleting unpublished or delivered events.
3. Deploy the last verified compatible application version. Leave all seven M02 tables and immutability triggers intact.
4. If a contract revision must roll back, retain backward-compatible V1 event readers until every emitted V1 event is drained; do not rename/reuse an event type.
5. If data corruption occurred, invoke the P03-approved database restore/recovery procedure and reconcile outbox delivery by event ID. Do not manually rewrite evidence hashes.
6. Only in an empty disposable environment may migrations run down in exact reverse order. If any governed record/evidence exists, destructive schema rollback is prohibited and the migration must fail closed.
7. Re-run status reads and hold/guard checks after recovery before reopening mutations. A missing M02 dependency leaves disposition denied.
8. Record rollback reason, commit, event backlog, table counts, evidence-chain verification, and reopen decision in the M02 evidence manifest.

## 14. Exit criteria and required retained evidence

M02 exits only when all are true:

- M00 reservations and exact metadata remain unchanged, and the integrated M02 directory, four migrations, runtime registration, fixed rank, planned-list removal, and seven table-owner entries form one atomic cutover with no ghost pre-registration.
- M01 audit integration is real and atomic; no production test fake is bound.
- All seven owned tables, constraints, indexes, migration order, and MySQL/SQLite immutability guards pass.
- All twelve HTTP operations and exact capabilities are integrated with session/CSRF, RFC 7807, correlation, idempotency, ETags, and bounded pagination through the canonical Shared authenticated cursor codec; tamper and resource/principal/scope/filter/sort/page-size mismatch tests pass.
- Hold-vs-disposition/deletion precedence passes SQLite service tests, MySQL concurrency tests, and smoke scenarios.
- M02 never imports Documents or reads/writes another owner's persistence; document references are opaque.
- Exact five V1 schemas/event types validate and shared outbox emission is atomic.
- OpenAPI says implemented only for passing operations; Orval has zero generated drift.
- `/records-governance` is capability-gated, keyboard usable, semantically labeled, and tested; P05 remains final WCAG evidence authority.
- Registry/runtime (one atomic merge SHA), capability, outbox, route, OpenAPI/Orval, shell, and MySQL-suite tokens are released; M03 may take the next queue position.
- Every section-11 command passes on one recorded commit; no critical command is skipped, and the exact registered M02 MySQL class/sentinel has nonzero test and assertion counts under the `mysql` driver.

Retain `artifacts/verification/M02/manifest.json` with this exact top-level schema:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "plan_id",
    "commit_sha",
    "verified_at",
    "environment",
    "commands",
    "smoke_scenarios",
    "shared_tokens",
    "migration_tables",
    "event_types",
    "generated_client_drift",
    "mysql_sentinel",
    "production_fakes"
  ],
  "properties": {
    "plan_id": {"const": "M02"},
    "commit_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
    "verified_at": {"type": "string", "format": "date-time"},
    "environment": {
      "type": "object",
      "additionalProperties": false,
      "required": ["php", "database", "node"],
      "properties": {
        "php": {"type": "string", "minLength": 1},
        "database": {"type": "string", "pattern": "^mysql .+"},
        "node": {"type": "string", "minLength": 1}
      }
    },
    "commands": {
      "type": "array",
      "minItems": 9,
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["command", "exit_code", "output_file", "sha256", "skipped"],
        "properties": {
          "command": {"type": "string", "minLength": 1},
          "exit_code": {"const": 0},
          "output_file": {"type": "string", "pattern": "^artifacts/verification/M02/.+\\.txt$"},
          "sha256": {"type": "string", "pattern": "^[0-9a-f]{64}$"},
          "skipped": {"const": false}
        }
      }
    },
    "smoke_scenarios": {
      "type": "array",
      "minItems": 11,
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["name", "result", "evidence_file"],
        "properties": {
          "name": {"type": "string", "minLength": 1},
          "result": {"const": "passed"},
          "evidence_file": {"type": "string", "pattern": "^artifacts/verification/M02/.+"}
        }
      }
    },
    "shared_tokens": {
      "type": "array",
      "minItems": 8,
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["token", "state", "base_commit", "merge_commit", "evidence_file"],
        "properties": {
          "token": {
            "enum": [
              "MODULE-REGISTRY",
              "MODULE-RUNTIME",
              "AUTH-CAPABILITIES",
              "OUTBOX-CONTRACT",
              "API-ROUTES",
              "OPENAPI-ORVAL",
              "WEB-SHELL",
              "MYSQL-SUITE"
            ]
          },
          "state": {"const": "released"},
          "base_commit": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
          "merge_commit": {"type": "string", "pattern": "^[0-9a-f]{40}$"},
          "evidence_file": {"type": "string", "pattern": "^artifacts/verification/M02/.+"}
        }
      }
    },
    "migration_tables": {
      "const": [
        "records_governance_retention_policy_versions",
        "records_governance_retention_policy_rules",
        "records_governance_governed_records",
        "records_governance_holds",
        "records_governance_disposition_reviews",
        "records_governance_evidence",
        "records_governance_idempotency_keys"
      ]
    },
    "event_types": {
      "const": [
        "com.cluster.recordsgovernance.retentionpolicyversionpublished.v1",
        "com.cluster.recordsgovernance.governedrecordstatuschanged.v1",
        "com.cluster.recordsgovernance.recordholdchanged.v1",
        "com.cluster.recordsgovernance.dispositionexecutionrequested.v1",
        "com.cluster.recordsgovernance.dispositionoutcomeconfirmed.v1"
      ]
    },
    "mysql_sentinel": {
      "type": "object",
      "additionalProperties": false,
      "required": ["class", "method", "suite_file", "driver", "test_count", "assertion_count", "list_output_file", "run_output_file"],
      "properties": {
        "class": {"const": "Modules\\RecordsGovernance\\Tests\\MySql\\RecordsGovernanceMySqlConcurrencyTest"},
        "method": {"const": "test_m02_mysql_lane_is_registered_and_uses_mysql"},
        "suite_file": {"const": "apps/api/phpunit.mysql.xml"},
        "driver": {"const": "mysql"},
        "test_count": {"type": "integer", "minimum": 1},
        "assertion_count": {"type": "integer", "minimum": 1},
        "list_output_file": {"type": "string", "pattern": "^artifacts/verification/M02/.+\\.txt$"},
        "run_output_file": {"type": "string", "pattern": "^artifacts/verification/M02/.+\\.txt$"}
      }
    },
    "generated_client_drift": {"const": false},
    "production_fakes": {"const": []}
  }
}
```

Retain the read-only registry baseline/delta/check artifacts, command logs, exact MySQL class/method discovery and driver/nonzero-count sentinel evidence, MySQL transaction timelines, HTTP smoke transcripts with secrets/redacted sensitive reason text, authenticated-cursor isolation transcripts, screenshots/accessibility evidence, migration/trigger inspection, evidence-chain verification, generated-client diff, and token receipts under `artifacts/verification/M02/`. A user-authorized implementation commit may be recorded only after these artifacts exist; this planning task authorizes no commit.

## 15. Status transition rules

- `blocked → ready`: M00 is completed; required Architecture Closure handoffs and the read-only Task 1 registry baseline/delta validation are recorded; no registry constant has changed and M01 audit is not required for module-core readiness.
- `ready → in_progress`: executor, isolated worktree, clean base commit, unapplied registry-delta artifact, and evidence location are recorded. The later registry/runtime cutover remains blocked until Task 10's atomic integration gate.
- `in_progress → blocked`: record the exact dependency/token/environment blocker and last safe commit. Use blocker `M01:audit-integration` when only final audit remains.
- `in_progress → verification`: module core, M01 audit, all shared integrations, generated client, web shell, and production bindings are complete; the registry/runtime cutover is one merge SHA, the exact MySQL class/sentinel executed under `mysql`, and no production fake/no-op remains.
- `verification → completed`: every exit criterion and non-skipped critical command passes on the same recorded commit, manifest paths resolve, hashes match, MySQL sentinel counts are nonzero, tokens are released, and the implementation commit was authorized by the user.
- Any status `→ superseded`: requires a later user-approved plan, replacement path, orchestration/dependency/token updates, and migration of downstream M03/M04/M07/P04 gates.
- Any dependency/ownership change updates the approved design amendment first, then this metadata, orchestration inventory/graph, token ledger, downstream plans, and the recorded user decision. Raw `.minimax-flow` material may create only newly validated `C` findings with current source/evidence/exit criteria; it never recreates unsourced historical F placeholders.
