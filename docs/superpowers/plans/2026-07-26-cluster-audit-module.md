# Cluster Audit Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task by task. Use an isolated worktree. Steps use checkbox (`- [ ]`) syntax for execution tracking.

```yaml
plan_id: M01
status: blocked
depends_on:
  - M00
  - ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX
blocks:
  - M02:audit-integration
  - M07:final-integration
  - P04:enforcement
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Implement a rank-3 Audit module that records immutable, privacy-safe, integrity-verifiable audit events and gives authorized users a bounded query and export experience without reading or writing another module's tables.

**Architecture:** Producers call the Audit-owned `RecordAuditEvent` contract inside their existing command transaction; the Audit adapter redacts context, appends the hash-chained `audit_events` row, and emits the canonical outbox event without importing producer internals. Audit HTTP adapters authorize before detailed validation or disclosure, delegate to module-owned handlers and persistence, and expose only redacted projections. Shared registry, route, OpenAPI/Orval, and shell changes wait for their serialized M01 integration tokens after the current architecture-closure owner hands each surface off.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, MySQL and SQLite, React 19, TypeScript 6, Vitest 4, OpenAPI 3.1, Orval 8.22, shared transactional outbox, UUIDv7, HMAC-SHA-256.

---

## 1. Status, dependencies, and handoff gates

M01 remains `blocked` until both start gates are evidenced:

1. `M00` is approved and its frozen rank, tables, capabilities, route prefixes, Contracts/DTOs/Events, and queue order match Section 7 of this plan.
2. `ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX` records completion of the current authorization ordering and transactional-outbox work. In concrete terms, the handoff must identify the integrated implementation of `Modules\Authorization\Contracts\DecideAccess`, the authoritative event-type catalog under `Shared\Contracts` or its approved replacement, the duplicate-event rule for `Shared\Contracts\TransactionalOutbox`, and the current ownership of `access_decisions` and `sensitive_access_events`.

The current architecture-closure plan remains `in_progress` and keeps its reservations until explicit handoff. M01 must not edit `Makefile`, either CI workflow, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, `apps/api/tests/Architecture/ModuleBoundariesTest.php`, or an actively reserved `apps/api/routes/web.php`. M01 owns no shared file. Module-owned work may start only after the two start gates; shared integration and final verification remain blocked until all token receipts in Section 12 are merged.

M01 completion releases three downstream gates: M02 may perform its audit integration, M07 may perform final aggregation through `QueryAuditActivity`, and P04 may collect enforcement evidence. Those downstream plans are not M01 start dependencies.

## 2. Goal and user-visible outcome

An authorized security auditor can open `/audit`, filter a stable event timeline by safe identifiers and time range, inspect actor, subject, outcome, correlation, classification, retention, and redacted context, move through opaque cursor pages, create a scope-bound export, download it, and run an integrity verification. A user lacking the relevant capability sees neither the navigation entry nor any resource detail. A valid but out-of-scope event returns the same 404 problem as a missing event.

Every accepted producer event has:

- a UUIDv7 identity and deterministic duplicate semantics;
- explicit actor and original actor, subject, source module, action, outcome, correlation ID, classification, and occurrence time;
- context sanitized before persistence and sanitized again before response/export;
- a configured retention deadline;
- a per-stream sequence, previous hash, and keyed event hash;
- an Audit-owned `AuditEventRecordedV1` outbox message committed in the same database transaction.

No password, credential, bearer token, session token, CSRF token, cookie, secret, raw medical identifier, document content, before/after body, exception message, or unrestricted request payload may enter Audit persistence, URLs, browser storage, response problems, logs, exports, or outbox payloads.

## 3. Current source evidence

The executor must re-read these exact sources at the implementation commit because the prerequisite plan is still changing them:

- `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS` already reserves `Audit => 3`; `::PLANNED_MODULES` still contains `Audit`; `::TABLE_OWNERS` assigns `access_decisions` and `sensitive_access_events` to Authorization and notes that no `audit_events` migration exists.
- `docs/architecture/ARCHITECTURE.md` and `docs/architecture/module-catalog.md` describe Audit as planned. The current closure register's F087 evidence only corrects the present-day stale `audit_events` claim; it does not prohibit this later, M00-approved implementation. New implementation defects discovered from raw `.minimax-flow` material must be independently validated and recorded with the next sourced `C` identifier; unsourced historical F entries must not be recreated.
- `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php::persist` writes `access_decisions` and confidential/top-secret `sensitive_access_events` atomically. `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php` makes `sensitive_access_events` append-only. M01 does not claim that table, query it, backfill it, or move it without a later explicit ownership handoff.
- `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php` and Documents handlers own `document_access_events`; that table also stays Documents-owned. Producer integration uses `RecordAuditEvent`, never cross-owner SQL.
- `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php::handle` validates a lowercase UUIDv7 correlation ID and attaches the session principal. `RequireIdentitySessionPrincipal.php::handle` enforces session/principal coherence.
- `apps/api/Modules/Authorization/Contracts/DecideAccess.php::decide`, `RecordFacts`, `AccessDecision`, and `AccessProjection` are the allowed authorization surface. `CapabilityCatalog.php` currently contains `authorization.audit.read`, which remains Authorization-owned for the existing coverage/API-docs routes; M01 neither removes it nor treats it as an alias for the three M00-frozen Audit capabilities.
- `apps/api/Shared/Contracts/TransactionalOutbox.php::append` and `apps/api/Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php::append` are the current outbox seam. `OutboxEventType.php` is currently a shared-infrastructure enum with schema enforcement; the closure handoff decides its final contract location and duplicate rule.
- `apps/api/Modules/Authorization/Http/AuthorizationApi.php`, `apps/api/Modules/Documents/Http/DocumentsApi.php`, and `apps/api/Shared/Http/HttpSupport.php` show the current UUIDv7 correlation, idempotency, ETag, envelope, link, and problem conventions. Their inline `If-Match` parsing is not copied: M01 depends on the single canonical Shared HTTP precondition parser and authenticated cursor codec produced by architecture-closure Tasks 7 and 11.
- `apps/api/Modules/WorkRecords/Features/ListAuthorizedWorkRecords/Handler/ListAuthorizedWorkRecordsHandler.php` demonstrates principal-bound opaque cursors, `limit + 1` collection semantics, row-level authorization, and field projection. M01 must use the shared authenticated cursor codec delivered by the closure plan rather than create a second cursor format.
- `docs/contracts/api/openapi.yaml` currently reserves `GET /audit` as `planned` with `operationId: listAuditEvents`; no live Audit route exists in `apps/api/routes/web.php`. The generated `listAuditEvents` client is therefore not implementation evidence.
- `apps/web/src/api/http.ts` requires domain wrappers over generated clients and central problem handling. `apps/web/src/shell/routes.ts` and `routes.capabilities.test.ts` own typed route and capability visibility. Shell wiring is shared and token-gated.

## 4. Scope and explicit non-goals

### In scope

- Audit-owned immutable event recording, deterministic redaction, integrity chaining, checkpoints, configured retention, checkpointed expiry purge, query, detail, export descriptor/download, and integrity verification.
- The M00-frozen tables, contracts, DTOs, events, route prefixes, and capability codes in Sections 7 and 8.
- Identity-session, CSRF, correlation, capability, record-scope, problem+json, idempotency, ETag/lock-version, and cursor behavior.
- Module-owned provider, migrations, handlers, controllers, console retention command, persistence, tests, web API wrapper, and `/audit` feature UI.
- Producer adoption through the published contract only, in a serialized integration phase after the producer and outbox handoffs.
- Retained privacy, migration, integrity, API, web, browser, and token evidence on one integrated commit.

### Non-goals

- No ownership of `access_decisions`, `sensitive_access_events`, `document_access_events`, producer aggregates, shared outbox storage, RecordsGovernance policy tables, technical logs, or report artifacts.
- No direct SQL, foreign key, model import, repository import, or Infrastructure/Domain import across module owners.
- No `audit_retention_policies` table and no `audit.retention.manage` capability. Audit retention is a fail-closed runtime configuration applied to Audit-owned rows; M02 owns records-governance retention policies.
- No raw request/response capture, payload diff store, key escrow, security-information-and-event-management product, user-behavior scoring, or search/reporting duplicate.
- No hand edit of generated clients and no alternate OpenAPI, cursor, authorization, idempotency, outbox, scheduler, or production topology.
- No compatibility alias for the new `/api/v1/audit` routes or the replaced planned `/audit` OpenAPI stub. The unrelated existing `authorization.audit.read` capability and its current coverage/API-docs routes remain unchanged.
- No fake or unavailable production adapter. Contract fakes are test-only and must not remain in a production binding.
- No commit, push, deployment, migration execution against a shared environment, cloud change, or external message without explicit user authorization.

## 5. Architecture and ownership boundaries

### Write path

`producer handler transaction → Modules\Audit\Contracts\RecordAuditEvent → DatabaseRecordAuditEvent → SensitiveValueRedactor → AuditIntegrityHasher → audit_events → Shared\Contracts\TransactionalOutbox`

`RecordAuditEvent::record` is synchronous and throws on persistence, redaction-policy, integrity-key, or outbox failure. Producers must call it inside the same `DB::transaction` that commits their state and idempotency effect. The Audit adapter must not swallow errors or start an independent after-commit write. The producer transaction therefore commits state + producer idempotency + Audit event + producer outbox + `AuditEventRecordedV1` atomically.

An `event_id` replay with the same canonical request hash returns the original `AuditEventReceipt` with `replayed=true` and emits no second row/outbox message. Reuse of the same `event_id` with different canonical content throws `AuditEventIdConflict`. The implementation uses one connection and locks the per-stream tail while allocating `stream_sequence`; it retries only a bounded deadlock/unique-race class and never retries validation, authorization, or conflict errors.

### Read path

`Audit controller → correlation/principal → audit capability check → safe query validation → Audit handler → Audit-owned query persistence → per-row RecordFacts/AccessProjection → redacted DTO`

Capability authorization occurs before detailed filter validation, event lookup, existence checks, or export disclosure. The base collection decision uses resource type `audit_event_collection`; each row uses `audit_event` facts scoped by the persisted facility/organization context. A denied row is skipped without changing totals or cursor semantics. The query over-fetches to fill a bounded authorized page and never returns unauthorized counts.

### Integrity and retention

`audit_events` is append-only during its retention interval. Events are partitioned logically by `stream_key = <source_module>:<subject_type>:<subject_id-or-global>` and form an HMAC-SHA-256 chain over canonical JSON, `stream_sequence`, and `previous_hash`. Key material comes from `AUDIT_INTEGRITY_KEYS` and active `AUDIT_INTEGRITY_KEY_VERSION`; production boot fails closed when absent, malformed, or when an event references an unavailable key version. Keys and raw MAC input are never logged or returned.

`AUDIT_RETENTION_DAYS` is a positive integer with an approved minimum of 2555 days unless P04 records a stricter value. `retention_until` is fixed at insert and never updated. `PurgeExpiredAuditEvents` may delete only a contiguous expired stream prefix after it writes an immutable `audit_integrity_checkpoints` record containing range, count, terminal hash, checkpoint hash, key version, and sanitized execution facts. Partial, unexpired, uncheckpointed, held, or middle-of-chain deletion fails closed. The purge event is recorded on the separate `audit:retention` stream so proof survives deletion. This controlled, checkpointed lifecycle deletion is the only exception to row non-deletion; in-place update is never allowed.

### Dependency direction

Audit rank 3 may import Identity rank 1 and Authorization rank 2 **Contracts only**, plus Shared Contracts. Higher-rank modules call Audit Contracts. Audit never imports M02–M07, even for retention. M02 may later register governance evidence by calling `RecordAuditEvent`; that is a producer-side integration inside M02.

## 6. File map

### Create: Audit-owned API files

- `apps/api/Modules/Audit/Contracts/RecordAuditEvent.php`
- `apps/api/Modules/Audit/Contracts/AuditEventInput.php`
- `apps/api/Modules/Audit/Contracts/AuditEventReceipt.php`
- `apps/api/Modules/Audit/Contracts/QueryAuditActivity.php`
- `apps/api/Modules/Audit/Contracts/AuditActivityQuery.php`
- `apps/api/Modules/Audit/Contracts/AuditActivityPage.php`
- `apps/api/Modules/Audit/Contracts/AuditActivityItem.php`
- `apps/api/Modules/Audit/Events/AuditEventRecordedV1.php`
- `apps/api/Modules/Audit/Events/AuditExportCompletedV1.php`
- `apps/api/Modules/Audit/Events/AuditIntegrityViolationDetectedV1.php`
- `apps/api/Modules/Audit/Domain/SensitiveValueRedactor.php`
- `apps/api/Modules/Audit/Domain/AuditIntegrityHasher.php`
- `apps/api/Modules/Audit/Domain/AuditRetentionPolicy.php`
- `apps/api/Modules/Audit/Domain/AuditEventIdConflict.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/Migrations/CreateAuditTables.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/DatabaseRecordAuditEvent.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/DatabaseQueryAuditActivity.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/AuditExportRepository.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/AuditIntegrityRepository.php`
- `apps/api/Modules/Audit/Infrastructure/Persistence/AuditIdempotencyStore.php`
- `apps/api/Modules/Audit/Features/ListAuditEvents/Handler/ListAuditEventsHandler.php`
- `apps/api/Modules/Audit/Features/ListAuditEvents/Http/ListAuditEventsController.php`
- `apps/api/Modules/Audit/Features/GetAuditEvent/Handler/GetAuditEventHandler.php`
- `apps/api/Modules/Audit/Features/GetAuditEvent/Http/GetAuditEventController.php`
- `apps/api/Modules/Audit/Features/CreateAuditExport/Handler/CreateAuditExportHandler.php`
- `apps/api/Modules/Audit/Features/CreateAuditExport/Http/CreateAuditExportController.php`
- `apps/api/Modules/Audit/Features/GetAuditExport/Http/GetAuditExportController.php`
- `apps/api/Modules/Audit/Features/DownloadAuditExport/Handler/DownloadAuditExportHandler.php`
- `apps/api/Modules/Audit/Features/DownloadAuditExport/Http/DownloadAuditExportController.php`
- `apps/api/Modules/Audit/Features/VerifyAuditIntegrity/Handler/VerifyAuditIntegrityHandler.php`
- `apps/api/Modules/Audit/Features/VerifyAuditIntegrity/Http/VerifyAuditIntegrityController.php`
- `apps/api/Modules/Audit/Features/Retention/Handler/PurgeExpiredAuditEvents.php`
- `apps/api/Modules/Audit/Features/Retention/Console/PurgeExpiredAuditEventsCommand.php`
- `apps/api/Modules/Audit/Http/AuditApi.php`
- `apps/api/Modules/Audit/Providers/AuditServiceProvider.php`
- `apps/api/config/audit.php`

### Create: Audit-owned tests

- `apps/api/Modules/Audit/Tests/AuditContractsTest.php`
- `apps/api/Modules/Audit/Tests/AuditRedactionTest.php`
- `apps/api/Modules/Audit/Tests/AuditMigrationTest.php`
- `apps/api/Modules/Audit/Tests/RecordAuditEventTest.php`
- `apps/api/Modules/Audit/Tests/AuditHttpAdapterTest.php`
- `apps/api/Modules/Audit/Tests/AuditAuthorizationTest.php`
- `apps/api/Modules/Audit/Tests/AuditExportTest.php`
- `apps/api/Modules/Audit/Tests/AuditIntegrityTest.php`
- `apps/api/Modules/Audit/Tests/AuditRetentionTest.php`
- `apps/api/Modules/Audit/Tests/AuditMySqlConcurrencyTest.php`
- `apps/api/Modules/Audit/Tests/AuditBoundaryTest.php`

### Create: plan-owned web feature files

- `apps/web/src/api/audit.ts`
- `apps/web/src/api/audit.test.ts`
- `apps/web/src/features/audit/AuditWorkspace.tsx`
- `apps/web/src/features/audit/AuditWorkspace.test.tsx`

### Modify only while holding the named serialized token

- `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` — module-registry token; add `AuditServiceProvider`.
- `apps/api/config/module_migrations.php` — module-registry token; register `CreateAuditTables.php` exactly once.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php::{PLANNED_MODULES,TABLE_OWNERS}` — `MODULE-REGISTRY`; keep rank 3, remove Audit from planned inventory, add exactly the four M00-owned tables.
- `apps/api/Modules/Authorization/**` capability bootstrap and producer integration files — `AUTHORIZATION-AUDIT-PRODUCER`; M01 publishes an immutable packet and Authorization's owner applies it.
- `apps/api/Modules/Documents/**` selected sensitive-access producer and tests — `DOCUMENTS-AUDIT-PRODUCER`; M01 publishes an immutable packet and Documents' owner applies it.
- authoritative outbox event catalog at the post-handoff Shared Contracts path and `docs/contracts/schemas/com-cluster-audit-auditeventrecorded-v1.schema.json`, `...-auditexportcompleted-v1.schema.json`, `...-auditintegrityviolationdetected-v1.schema.json` — module/contract token.
- `apps/api/routes/web.php` — `API-ROUTES` token.
- `docs/contracts/api/openapi.yaml` — `OPENAPI` token.
- `apps/web/src/api/generated/cluster.ts` — `ORVAL` token, generation command only.
- `apps/web/src/shell/routes.ts`, `apps/web/src/shell/routes.capabilities.test.ts`, `apps/web/src/app/WorkspaceContent.tsx`, navigation copy files — `WEB-SHELL` token; M07 retains final aggregation ownership only.
- `apps/api/phpunit.mysql.xml` — `AUDIT-MYSQL-SUITE`; add exactly one explicit Audit test `<file>` to the existing `MySQL integration` suite. Never edit either MySQL runner script.
- `docs/architecture/ARCHITECTURE.md` and `docs/architecture/module-catalog.md` — documentation integration after runtime proof; describe current ownership without rewriting the closure register's historical facts.

No file is moved or removed. Existing Authorization and Documents audit-shaped tables remain with their current owners.

## 7. Public Contracts, Events, routes, schemas, and capabilities

### Frozen M00 Contracts and DTOs

```php
namespace Modules\Audit\Contracts;

interface RecordAuditEvent
{
    public function record(AuditEventInput $input): AuditEventReceipt;
}

interface QueryAuditActivity
{
    public function query(AuditActivityQuery $query): AuditActivityPage;
}
```

`AuditEventInput` is an immutable readonly DTO with these constructor fields in order: `string $eventId`, `string $sourceModule`, `string $action`, `string $eventType`, `string $actorType`, `?string $actorId`, `?string $originalActorId`, `string $subjectType`, `?string $subjectId`, `string $correlationId`, `string $outcome`, `string $classification`, `array $context`, `DateTimeImmutable $occurredAt`, `string $retentionClass`. Allowed enums are actor type `user|service|system`, outcome `succeeded|denied|failed`, classification `public|internal|confidential|top_secret`, and retention class `standard|security|regulated`.

`AuditEventReceipt` contains `eventId`, `streamKey`, `streamSequence`, `eventHash`, `recordedAt`, and `replayed`.

`AuditActivityQuery` contains the trusted access context `principalId`, `facilityId`, `organizationUnitIds`, followed by nullable `cursor`, `sourceModule`, `action`, `actorId`, `subjectType`, `subjectId`, `correlationId`, `classification`, `occurredFrom`, `occurredTo`, and integer `limit`. `AuditActivityPage` contains `list<AuditActivityItem> $items` and `?string $nextCursor`. `AuditActivityItem` contains the public redacted event fields plus `accessDecisionId`, `retentionUntil`, `integrityStatus`, and `allowedActions`; it never exposes `event_hash`, `previous_hash`, raw integrity material, raw key versions, or unredacted context.

All DTO constructors validate UUIDv7 identifiers, catalog-format module/action/type names, UTC timestamps, bounded context depth/keys/bytes, and the declared enums. Contract consumers receive typed exceptions, not booleans or raw database rows.

### Frozen M00 events

- `AuditEventRecordedV1` → `com.cluster.audit.auditeventrecorded.v1`
- `AuditExportCompletedV1` → `com.cluster.audit.auditexportcompleted.v1`
- `AuditIntegrityViolationDetectedV1` → `com.cluster.audit.auditintegrityviolationdetected.v1`

The naming rule is exact: `com.cluster.<module-token>.<lowercase-event-class-name-without-V1>.v1`; M01's module token is `audit`, and event segments are unhyphenated, non-snake-case concatenations. Each event class exposes a typed `eventType(): string` and `payload(): array`. Payload schemas use `additionalProperties: false`, require UUIDv7 `event_id`/`correlation_id`, use UTC timestamps, and contain only identifiers, source/action/outcome/classification, counts, and integrity status. They never contain Audit context or export bytes. Every type must be registered in the post-handoff canonical event catalog and have its exact JSON Schema file before any producer reference merges.

`AuditExportCompletedV1` has exactly one meaning and emission point: successful synchronous creation of the export descriptor and frozen snapshot in `POST /audit/exports`. That transaction emits exactly one completion event. Download attempts are separate immutable Audit activity records recorded through `RecordAuditEvent`; successful, failed, or interrupted downloads never emit another `AuditExportCompletedV1`. Its payload identifies the descriptor/snapshot, format, bounded event count, actor/scope-safe identifiers, correlation ID, and completion timestamp, but contains neither export bytes nor download outcome.

### Frozen M00 capability namespace

- `audit.event.read` — collection/detail and export descriptor reads.
- `audit.event.export` — export creation and download; classified `sensitive`.
- `audit.integrity.verify` — integrity verification; classified `critical`.

Authorization bootstrap grants the three M01 codes only to the approved security-auditor role. `authorization.audit.read` remains intact for its existing Authorization-owned coverage/API-docs routes but never authorizes an M01 endpoint. Authorization is evaluated before query/body validation and again against record facts for detail/download.

### HTTP routes under `/api/v1/audit`

| Method and path | Capability | Contract |
|---|---|---|
| `GET /api/v1/audit/events` | `audit.event.read` | Safe filters; `{items,next_cursor}`; `Link` and `X-Correlation-ID`. |
| `GET /api/v1/audit/events/{eventId}` | `audit.event.read` | `{data: AuditActivityItem}`; out-of-scope and absent both 404. |
| `POST /api/v1/audit/exports` | `audit.event.export` | CSRF + `Idempotency-Key`; creates a principal/scope/query snapshot descriptor, returns 201 + ETag. |
| `GET /api/v1/audit/exports/{exportId}` | `audit.event.export` | Scope-bound descriptor, `{data: ...}` + ETag. |
| `GET /api/v1/audit/exports/{exportId}/download` | `audit.event.export` | Streams UTF-8 CSV or NDJSON from the frozen upper bound; `Cache-Control: no-store`; no browser persistence. |
| `POST /api/v1/audit/integrity-verifications` | `audit.integrity.verify` | CSRF + `Idempotency-Key`; verifies a bounded stream/time range and returns 201 checkpoint + ETag. |

The OpenAPI canonical prefix is `/audit`; the existing planned `GET /audit` operation is replaced cleanly by the routes above, not kept as an alias. All endpoints require `IdentitySessionMiddleware` and `RequireIdentitySessionPrincipal`; POST routes also require `IdentityCsrfMiddleware`. Errors are `application/problem+json` with `type`, `title`, `status`, safe `detail`, optional pointer/code errors, and `X-Correlation-ID`.

Safe URL filters are opaque cursor, UUID identifiers, catalog tokens, classification, and UTC timestamps. Names, email addresses, patient identifiers, free text, export reasons, and context values are body-only or prohibited. Export creation body is `{"format":"csv|ndjson","filters":{...},"reason":"..."}`; reason is redacted and bounded to 500 UTF-8 characters before persistence.

### Web route

`/audit` renders `AuditWorkspace` and is visible only with `audit.event.read`. Export controls require `audit.event.export`; integrity controls require `audit.integrity.verify`. Capability absence removes controls, but the API remains authoritative.

## 8. Database tables, indexes, constraints, migration order, and recovery

`CreateAuditTables.php` creates only the four M00-frozen Audit-owned tables, in this order:

### `audit_events`

- Columns: `id uuid PK`, `request_hash char(64)`, `stream_key varchar(160)`, `stream_sequence unsigned bigint`, `source_module varchar(64)`, `action varchar(128)`, `event_type varchar(160)`, `actor_type varchar(16)`, nullable `actor_id uuid`, nullable `original_actor_id uuid`, `subject_type varchar(64)`, nullable `subject_id uuid`, `correlation_id uuid`, `outcome varchar(16)`, `classification varchar(32)`, `context json`, `context_schema_version unsigned smallint`, `redaction_policy_version varchar(32)`, `occurred_at datetime(3)`, `recorded_at datetime(3)`, `retention_until datetime(3)`, nullable `previous_hash char(64)`, `event_hash char(64)`, `integrity_key_version varchar(32)`.
- Unique constraints: `id`, `(stream_key,stream_sequence)`, `event_hash`.
- Indexes: `(recorded_at,id)`, `(actor_id,recorded_at,id)`, `(subject_type,subject_id,recorded_at,id)`, `(correlation_id,recorded_at,id)`, `(source_module,action,recorded_at,id)`, `(classification,recorded_at,id)`, `(retention_until,stream_key,stream_sequence)`.
- Checks where supported and mirrored in DTO/domain validation: allowed actor/outcome/classification values; positive sequence; `retention_until > recorded_at`; 64-lowercase-hex hashes.
- No cross-owner foreign keys. A database guard rejects UPDATE. Retention deletion is reachable only through the checkpointed module repository path described in Section 5.

### `audit_export_jobs`

- Columns: `id uuid PK`, `principal_id uuid`, nullable `facility_id uuid`, `query json`, `query_hash char(64)`, `reason_redacted varchar(500)`, `format varchar(8)`, `snapshot_recorded_at datetime(3)`, `status varchar(16)`, `event_count unsigned bigint`, `lock_version unsigned bigint`, `expires_at datetime(3)`, timestamps with milliseconds.
- Non-unique index: `(principal_id,query_hash,snapshot_recorded_at)` for audit/reconciliation. Idempotency is owned by `audit_idempotency_keys`, so a later request with a new key may intentionally repeat the same export.
- Indexes: `(principal_id,status,created_at)`, `(expires_at,status)`.
- Status is `ready|expired`; synchronous descriptor creation either atomically commits `ready`, its Audit activity record, and exactly one `AuditExportCompletedV1`, or leaves none of them. Download generation never mutates the descriptor; every download attempt is an immutable Audit activity record and emits no completion event. Expiry uses CAS `WHERE id=? AND lock_version=?`.

### `audit_integrity_checkpoints`

- Columns: `id uuid PK`, `stream_key varchar(160)`, `kind varchar(24)`, `first_sequence unsigned bigint`, `last_sequence unsigned bigint`, `event_count unsigned bigint`, `terminal_event_hash char(64)`, nullable `previous_checkpoint_hash char(64)`, `checkpoint_hash char(64)`, `integrity_key_version varchar(32)`, `status varchar(16)`, nullable `actor_id uuid`, `correlation_id uuid`, `details json`, `verified_at datetime(3)`, `created_at datetime(3)`.
- Unique constraints: `(stream_key,kind,last_sequence)`, `checkpoint_hash`.
- Indexes: `(stream_key,last_sequence)`, `(status,verified_at)`, `correlation_id`.
- Allowed kind `verification|retention_purge`; status `verified|violated`. UPDATE and DELETE are rejected.

### `audit_idempotency_keys`

- Columns: `id uuid PK`, `principal_id uuid`, `operation varchar(96)`, `key_hash char(64)`, `request_hash char(64)`, `response_status unsigned smallint`, `response_payload json`, `resource_id uuid`, timestamps with milliseconds.
- Unique constraint: `(principal_id,operation,key_hash)`.
- Replay with equal request hash returns the stored status/payload; unequal hash returns 409 `idempotency-conflict` without side effects.

`down()` drops in reverse dependency order: idempotency keys, integrity checkpoints, export jobs, event guards, audit events. A disposable SQLite and MySQL round-trip must prove exact restoration. Production rollback after data exists uses application rollback first; the migration is reversed only after exporting/checkpointing all Audit rows and explicit user approval. Migration failure rolls back without leaving a partially registered module.

## 9. TDD implementation tasks

Every task starts with the named failing test and ends with focused green evidence. After each task, record a commit only if the user has explicitly authorized commits; otherwise retain the working-tree change and command evidence without committing.

### Task 1: Freeze contracts, redaction, retention configuration, and integrity canonicalization

**Files:** Contract DTOs/interfaces, event classes, `SensitiveValueRedactor.php`, `AuditIntegrityHasher.php`, `AuditRetentionPolicy.php`, `apps/api/config/audit.php`, `AuditContractsTest.php`, `AuditRedactionTest.php`.

- [ ] Write `AuditContractsTest::test_m00_contract_signatures_and_event_types_are_exact` and redaction cases for nested keys `password`, `token`, `authorization`, `cookie`, `secret`, `csrf`, `credential`, `medical_record_number`, `national_id`, `document_content`, and unknown oversized context. Assert allowed operational identifiers survive, sensitive values become the literal `[REDACTED]`, arrays preserve shape within limits, and input objects remain unchanged.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/AuditContractsTest.php Modules/Audit/Tests/AuditRedactionTest.php`; expect FAIL because the M01 classes do not exist.
- [ ] Implement the exact interfaces/DTOs from Section 7. Canonical JSON recursively sorts object keys, preserves list order, normalizes UTC timestamps to millisecond `Z`, rejects floats/non-finite values/resources/objects, limits depth to 6, keys to 100, and encoded context to 16 KiB. Redaction happens before request hashing, persistence, outbox construction, or logging.
- [ ] Implement `AuditIntegrityHasher::eventHash(array $canonicalEvent, ?string $previousHash, string $keyVersion): string` with HMAC-SHA-256 and `hash_equals` verification. `AuditRetentionPolicy::retentionUntil(DateTimeImmutable $recordedAt,string $class): DateTimeImmutable` maps `standard=2555`, `security=3650`, and `regulated=3650` days, never below `AUDIT_RETENTION_DAYS`.
- [ ] Re-run the two files; expect PASS with no secret-bearing assertion diagnostics.

### Task 2: Create module-owned schema and prove immutability/reversibility

**Files:** `CreateAuditTables.php`, persistence classes needed by schema tests, `AuditMigrationTest.php`, `AuditMySqlConcurrencyTest.php`.

- [ ] Write a failing migration test asserting all columns, indexes, unique constraints, no foreign keys, update rejection on `audit_events`, update/delete rejection on checkpoints, reverse-order `down()`, and absence of `audit_retention_policies`.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/AuditMigrationTest.php`; expect FAIL because no migration/tables exist.
- [ ] Implement the four tables exactly as Section 8. Use driver-specific guards consistent with `CreateAuthorizationFieldAuditTables.php`; do not weaken MySQL to accommodate SQLite. Registering the migration waits for the module-registry token.
- [ ] Put every required MySQL-only case in the single XML-registered `AuditMySqlConcurrencyTest.php`: two-connection inserts on one stream prove unique gap-free sequences and correct previous hashes after bounded retry; exception after event insert/before outbox append proves rollback; MySQL guards reject forbidden event/checkpoint mutation; a full `up()`/`down()`/`up()` round-trip proves exact schema restoration; and retention purge/checkpoint behavior proves atomic rollback and surviving checkpoint integrity on MySQL. Do not register `AuditMigrationTest.php` or `AuditRetentionTest.php` as additional XML files.
- [ ] Run the SQLite migration test; expect PASS. Defer the consolidated MySQL class to Section 11's declared MySQL environment and retain it as a required gate, not a skipped test.

### Task 3: Implement atomic, idempotent event recording and canonical outbox events

**Files:** `DatabaseRecordAuditEvent.php`, `AuditIdempotencyStore.php`, event classes, provider, `RecordAuditEventTest.php`.

- [ ] Write failing tests for first insert, exact replay, mismatched event-ID conflict, redaction-before-storage, absent integrity key, outbox exception rollback, stream-tail contention, actor/original-actor distinction, nullable system actor, and retention deadline.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/RecordAuditEventTest.php`; expect FAIL because `RecordAuditEvent` has no production binding.
- [ ] Implement `DatabaseRecordAuditEvent::record` so it validates/redacts first, derives canonical request hash and stream key, locks the stream tail, appends the immutable event, then calls `TransactionalOutbox::append` using `com.cluster.audit.auditeventrecorded.v1`. It must participate in the caller transaction and throw on any unavailable production dependency.
- [ ] Bind `RecordAuditEvent`, `QueryAuditActivity`, domain services, and the retention command in `AuditServiceProvider`. Do not override the shared `TransactionalOutbox` binding and do not bind a fake outside tests.
- [ ] Re-run the test; expect PASS and exactly one event/outbox row for an equal replay.

### Task 4: Implement authorized, bounded collection/detail queries

**Files:** query contract DTOs, `DatabaseQueryAuditActivity.php`, list/detail handlers/controllers, `AuditApi.php`, `AuditHttpAdapterTest.php`, `AuditAuthorizationTest.php`.

- [ ] Write HTTP tests proving 401 without session, 403 without `audit.event.read`, authorization before malformed-filter disclosure, 404 concealment for missing/out-of-scope IDs, invalid UUIDv7 correlation rejection, unknown query rejection, limits 1–100, opaque cursor tamper/scope/query mismatch rejection, `limit+1` next cursor, stable `(recorded_at,id)` ordering, per-row masking, no unauthorized total, correlation echo, and problem+json content type.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/AuditHttpAdapterTest.php Modules/Audit/Tests/AuditAuthorizationTest.php`; expect FAIL because routes/controllers are absent.
- [ ] Implement the controller→capability→handler→Audit persistence flow. Use the closure-delivered shared authenticated cursor codec with resource key `audit.events`, exact sort tuple `(recorded_at,id)`, filter fingerprint, limit, principal ID, and scope binding. Apply `DecideAccess`/`RecordFacts` before projection; set `accessDecisionId` from `AccessProjection`.
- [ ] Add list/detail routes only in the `API-ROUTES` phase. Until that token, exercise controllers through the Laravel container or test-local route registration so module-owned work does not touch `routes/web.php`.
- [ ] Re-run the focused tests after token integration; expect PASS.

### Task 5: Implement idempotent, scope-bound exports

**Files:** export repository/handlers/controllers, `AuditExportTest.php`, web API types later generated from OpenAPI.

- [ ] Write failing tests for required CSRF/idempotency/reason, equal replay, mismatched replay 409, capability denial, principal/scope concealment, fixed snapshot upper bound, CSV formula-injection escaping, NDJSON line validity, redaction at read time, no-store headers, event count, expired descriptor 410, and atomic export descriptor + Audit activity + exactly one `AuditExportCompletedV1` outbox event on successful POST creation.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/AuditExportTest.php`; expect FAIL because export handlers are absent.
- [ ] Implement POST creation as a synchronous descriptor snapshot, not a queued artifact: store sanitized filters and `snapshot_recorded_at`, atomically record the creation activity and single completion event, return 201 with ETag, and stream authorized rows on download only up to that bound. Prefix CSV cells beginning `=`, `+`, `-`, or `@` with a single quote; emit a UTF-8 header; never include internal hashes or redacted source values.
- [ ] Record each download attempt as immutable Audit activity with its observed outcome. A download success, failure, or interruption never emits `AuditExportCompletedV1` and never mutates the descriptor or creation activity. Do not cache export bytes in a table, object store, browser storage, or local filesystem.
- [ ] Re-run the focused test; expect PASS with byte-for-byte deterministic output for repeated downloads of the same descriptor, exactly one completion event for an idempotently replayed POST, and no completion event from download attempts.

### Task 6: Implement integrity verification and checkpointed retention purge

**Files:** integrity repository/handler/controller, retention handler/command, `AuditIntegrityTest.php`, `AuditRetentionTest.php`.

- [ ] Write failing tests for a valid chain, changed context, removed middle row, altered previous hash, unavailable key version, bounded verification, immutable checkpoint, equal idempotent verification replay, integrity-violation outbox emission, unexpired purge refusal, non-prefix purge refusal, checkpoint-before-delete rollback, legal/regulated minimum, and surviving purge proof.
- [ ] Run `cd apps/api && php artisan test Modules/Audit/Tests/AuditIntegrityTest.php Modules/Audit/Tests/AuditRetentionTest.php`; expect FAIL because verification/retention handlers are absent.
- [ ] Implement POST verification under `audit.integrity.verify`; it records a `verified|violated` checkpoint. On violation, append `AuditIntegrityViolationDetectedV1` in the same transaction and return a safe 409 `audit-integrity-violation` without revealing hashes.
- [ ] Implement `audit:retention:purge --before=<UTC timestamp> --stream=<stream key>` in the module provider. The handler locks the eligible contiguous prefix, verifies its chain, writes a `retention_purge` checkpoint, deletes only that prefix, records a sanitized retention event on `audit:retention`, and commits atomically. Missing key, violation, active retention, middle-range request, or checkpoint/outbox failure leaves all rows untouched.
- [ ] Re-run the two tests; expect PASS. Retention policy sourcing from M02 is not added; M02 may later call Audit contracts but M01 stays rank-correct.

### Task 7: Build the accessible Audit web workspace in module-owned files

**Files:** `apps/web/src/api/audit.ts`, its test, `AuditWorkspace.tsx`, its test.

- [ ] Write failing Vitest/Testing Library cases for loading, empty, ready, forbidden, error/retry, next page, filters, event detail, redacted context, export reason/format, export success/failure, integrity action visibility, bilingual labels, focus restoration, keyboard use, semantic table/list headings, status/alert live regions, and 44×44 CSS-pixel controls.
- [ ] Run `npm --prefix apps/web run test:unit -- src/api/audit.test.ts src/features/audit/AuditWorkspace.test.tsx`; expect FAIL because the feature does not exist.
- [ ] Implement `audit.ts` exclusively over generated Orval operations plus `requestInit`/`unwrap`; do not call `fetch` or construct security headers in the screen. Keep cursor in component memory, never `localStorage`, `sessionStorage`, URL history, analytics, or logs.
- [ ] Implement the capability-sensitive workspace with server-side filtering, an explicit redaction legend, copyable correlation ID, UTC `<time>` values, export confirmation/reason, and no rendering of hidden fields. Honor reduced motion and existing design-system components.
- [ ] Re-run the focused tests; expect PASS. Shell routing waits for `WEB-SHELL`.

### Task 8: Process the serialized shared integration queue

- [ ] Acquire `MODULE-REGISTRY` after the current architecture-closure handoff; add provider, migration, implemented inventory, four table owners, rank 3, capabilities, and three event types/schemas. Verify no same-rank/up-rank import and no ownership change for Authorization/Documents tables.
- [ ] Acquire `API-ROUTES`; add the six routes with the middleware/capabilities from Section 7. Route names use `audit.events.index`, `audit.events.show`, `audit.exports.store`, `audit.exports.show`, `audit.exports.download`, `audit.integrity-verifications.store`.
- [ ] Acquire `OPENAPI`; replace the planned `/audit` stub with exact `/audit/events`, `/audit/events/{eventId}`, `/audit/exports`, `/audit/exports/{exportId}`, `/audit/exports/{exportId}/download`, and `/audit/integrity-verifications` operations, schemas, examples, headers, security, and problems. Set implemented status only after live tests pass.
- [ ] Under the same contract token run `npm --prefix apps/web run api:generate`; never edit generated output. Retain the generated diff and `api:check` result.
- [ ] Acquire `WEB-SHELL`; wire `/audit`, `AuditWorkspace`, capability mapping, navigation copy, and shell tests. M07 remains the owner of the later final aggregation token; M01 does not modify Workspace aggregation.
- [ ] Update architecture docs only after integrated runtime proof. Record each token's releasing owner, base commit, grant, merge commit, and release.

### Task 9: Publish and apply owner-controlled representative-producer packets

After `ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX`, M01 publishes two immutable integration packets: Authorization bootstrap role grants plus its bootstrap mutation audit call/tests, and one Documents sensitive-access mutation audit call/tests. M01 does not edit any Authorization- or Documents-owned file. Each packet names the exact integrated-base files, allow-listed `AuditEventInput` mapping, expected event ID/idempotency derivation, focused command, and patch checksum.

- [ ] In M01-owned packet fixtures/tests, specify failing producer contract cases asserting producer state + producer idempotency + producer outbox + Audit event/outbox commit in the producer's existing DB transaction, and injected `RecordAuditEvent` failure rolls every one of those effects back. Equal producer replay creates no duplicate Audit event/outbox row.
- [ ] Request `AUTHORIZATION-AUDIT-PRODUCER`; the Authorization owner alone applies the immutable capability/bootstrap-role and bootstrap-grant producer packet to Authorization-owned code, runs its packet tests plus `AuditContractsTest`, records base/packet checksum/result/merge receipt, and releases the token.
- [ ] Request `DOCUMENTS-AUDIT-PRODUCER`; the Documents owner alone applies the immutable selected sensitive-access packet to Documents-owned code, runs its packet tests plus `AuditContractsTest`, records base/packet checksum/result/merge receipt, and releases the token.
- [ ] Both owners inject only `Modules\Audit\Contracts\RecordAuditEvent`, construct `AuditEventInput` from explicit allow-listed facts, and call `record()` inside the producer's existing DB transaction. Production code never imports Audit Domain/Infrastructure, reads Audit tables, or moves ownership of `access_decisions`, `sensitive_access_events`, or `document_access_events`.
- [ ] M01 verifies both immutable receipts and reruns the two owner-focused tests and Audit contract test on the integrated commit; expect atomic success, full rollback under injected Audit failure, and no duplicate event on replay.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

| Condition | Required behavior |
|---|---|
| Missing/invalid session | 401 `authentication-required`; no detailed validation or lookup. |
| Capability denied | 403 `access-denied` for collection/commands; detail/download use 404 when revealing existence would leak scope. |
| Invalid correlation ID | 400 `invalid-correlation-id`; lowercase UUIDv7 only. |
| Invalid safe filter/cursor/body | 400 or 422 problem with safe pointer/code entries; no raw submitted value echoed. |
| Missing resource or out-of-scope resource | Identical 404 `audit-event-not-found` or `audit-export-not-found`. |
| Equal event-ID replay | Same receipt, `replayed=true`, no new event/outbox row. |
| Different payload with same event ID | Typed conflict; HTTP command boundary maps to 409 `audit-event-id-conflict`. |
| Equal HTTP idempotency replay | Stored status/body/ETag; no repeated export/checkpoint/event. |
| Different request with same key | 409 `idempotency-conflict`. |
| Missing/stale If-Match on mutable export expiry state | 428/412 from the closure-delivered canonical Shared HTTP precondition primitive; M01 defines no parser, and CAS remains in the write predicate. |
| Stream-tail race/deadlock | Bounded retry only for detected transient DB race; one sequence winner per slot, no gaps after rollback. |
| Redaction/config/integrity key unavailable | Fail closed before persistence; safe 503 `audit-runtime-unavailable`; production boot rejects missing keys. |
| Outbox failure | Roll back the complete command transaction; never log raw context. |
| Cursor tamper/principal/scope/filter mismatch | 400 `invalid-pagination`; no fallback to first page. |
| Integrity mismatch | Immutable violated checkpoint + violation outbox atomically; safe 409; no hash/key disclosure. |
| Export stream interruption | No descriptor/creation-event mutation; retain one immutable failed download-attempt Audit activity record, emit no `AuditExportCompletedV1`, and require a fresh request for another attempt. |
| Retention purge failure | Transaction rollback leaves events and checkpoint unchanged. |

Query/export ordering is `(recorded_at DESC,id DESC)` with an exact cursor tuple and snapshot upper bound. Record-time integrity uses per-stream serialization, not a global lock. No endpoint accepts client-supplied actor identity, access decision, allowed actions, retention deadline, recorded time, hash, or scope; these come from trusted principal/config/server state.

## 11. Targeted verification commands and user-visible smoke scenarios

These commands belong to future execution. This drafting task does not run them.

### Focused red/green commands

```bash
cd apps/api && php artisan test Modules/Audit/Tests/AuditContractsTest.php Modules/Audit/Tests/AuditRedactionTest.php
cd apps/api && php artisan test Modules/Audit/Tests/AuditMigrationTest.php Modules/Audit/Tests/RecordAuditEventTest.php
cd apps/api && php artisan test Modules/Audit/Tests/AuditHttpAdapterTest.php Modules/Audit/Tests/AuditAuthorizationTest.php
cd apps/api && php artisan test Modules/Audit/Tests/AuditExportTest.php Modules/Audit/Tests/AuditIntegrityTest.php Modules/Audit/Tests/AuditRetentionTest.php
npm --prefix apps/web run test:unit -- src/api/audit.test.ts src/features/audit/AuditWorkspace.test.tsx src/shell/routes.capabilities.test.ts
```

Expected: each named suite passes; no skip, incomplete, risky, or environment-bypass result.

### Integrated gates

```bash
(cd apps/api && AUDIT_INTEGRITY_KEYS='v1:testing-only-32-byte-key-material' AUDIT_INTEGRITY_KEY_VERSION=v1 php artisan test Modules/Audit/Tests)
(cd apps/api && vendor/bin/phpunit -c phpunit.mysql.xml --list-tests | grep 'AuditMySqlConcurrencyTest')
./scripts/run-mysql-integration-tests.sh
make verify-boundaries
npm --prefix apps/web run api:generate
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit -- src/api/audit.test.ts src/features/audit/AuditWorkspace.test.tsx src/shell/routes.capabilities.test.ts
composer --working-dir=apps/api test
npm --prefix apps/web run build
```

Expected: all commands exit 0; the existing MySQL runner discovers `AuditMySqlConcurrencyTest` by class name through `--list-tests` and then executes the XML suite; generated client has zero drift after generation; MySQL proves concurrency, guards, and reversible schema; boundary inventory recognizes Audit as implemented rank 3 and exactly four Audit tables; full API and production web build pass without skipping Audit. M01 adds exactly one `<file>Modules/Audit/Tests/AuditMySqlConcurrencyTest.php</file>` to the existing `phpunit.mysql.xml` suite under `AUDIT-MYSQL-SUITE` and never edits a runner script.

### User-visible browser smoke

1. Log in as the seeded security auditor, open `/audit`, and observe a stable first page with actor, subject, action, outcome, correlation, time, retention, classification, and a visible redaction marker; no secret or raw classified value appears in DOM, browser storage, URL, console, or network problem bodies.
2. Filter by a known correlation UUID and time range, open one detail, copy the correlation ID, navigate to next page, and return; items do not duplicate or reorder.
3. Create a CSV export with a reason, download it, and confirm only authorized snapshot rows appear, dangerous spreadsheet-leading characters are escaped, exactly one completion event appears for descriptor creation, and the download attempt appears only as immutable Audit activity after refresh.
4. With `audit.integrity.verify`, verify a known stream and observe a successful checkpoint. In an isolated test fixture, alter one row and confirm a safe integrity-violation state without displayed hashes.
5. Log in as a user without `audit.event.read`; `/audit` is absent from navigation and direct access is forbidden. A user with read but without export/integrity sees neither control and receives API denial if calling those routes.
6. Switch Arabic/English and complete filter, detail, export dialog, pagination, and error recovery with keyboard only at 200% zoom; focus is visible and returns to the invoking control.

P05 owns final WCAG 2.2 AA evidence and P07 owns production E2E execution; their later gates do not replace M01's focused smoke evidence.

## 12. Shared-file integration token requirements

M01 requests, but never owns, shared surfaces. The first five tokens use fixed serial order `M01 → M02 → M03 → M04 → M05 → M06 → M07`; the remaining owner packets/suite token are independently serialized exactly once:

1. `MODULE-REGISTRY`: provider/migration registration, implemented inventory, rank/table ownership, capability/event catalog and schemas, after current `ModuleBoundariesTest.php` handoff.
2. `API-ROUTES`: `apps/api/routes/web.php`, after any active current-plan route task releases it.
3. `OPENAPI`: `docs/contracts/api/openapi.yaml`, after current Task 12/contract handoff.
4. `ORVAL`: `apps/web/src/api/generated/cluster.ts`, coupled to OPENAPI and changed only by `npm --prefix apps/web run api:generate`.
5. `WEB-SHELL`: typed route, content switch, navigation, and capability tests; M07 owns only the later final aggregation token.
6. `AUDIT-MYSQL-SUITE`: `apps/api/phpunit.mysql.xml`; the XML integration owner adds one explicit Audit `<file>` entry, with no runner-script change.
7. `AUTHORIZATION-AUDIT-PRODUCER`: Authorization-owned bootstrap capabilities/role grant producer and focused tests; Authorization's owner applies M01's immutable packet.
8. `DOCUMENTS-AUDIT-PRODUCER`: Documents-owned sensitive-access producer and focused tests; Documents' owner applies M01's immutable packet.

Every receipt records `token`, `requesting_plan: M01`, `releasing_owner`, full base commit, granted surfaces or immutable packet checksum, grant time, expiry, merge commit, focused results, and release. If the base changes, the token is revoked and reacquired after rebase/reverification. M01 never touches `Makefile`, CI, Authorization-owned code, Documents-owned code, or MySQL runner scripts; P08 alone owns closure CI after Task 13 handoff.

## 13. Rollback procedure

### Before shared integration

Remove only `apps/api/Modules/Audit/**`, `apps/api/config/audit.php`, `apps/web/src/api/audit.ts`, `apps/web/src/api/audit.test.ts`, and `apps/web/src/features/audit/**`. No shared surface should have changed.

### After shared integration but before production data

1. Remove web shell wiring under `WEB-SHELL`.
2. Restore OpenAPI to `planned` only if M01 runtime is removed, regenerate Orval, and prove zero generated drift.
3. Remove Audit routes under `API-ROUTES`.
4. Remove capability/event/provider/migration/implemented-inventory entries under `MODULE-REGISTRY`, then reverse `CreateAuditTables.php` on a disposable environment.
5. Re-run route, contract, boundary, API, and web gates. Never leave a route/spec/client/provider/table inventory half-registered.

### After Audit data exists

Disable new producer calls by rolling back the producer integrations in reverse order while retaining the Audit reader. Run integrity verification, create exports and checkpoints for every stream, retain their hashes/manifests, and stop writes. Only after explicit user authorization may the migration be reversed. If a rollback defect affects reading but not writing, preserve the tables and deploy the last verified Audit reader; do not delete evidence to make checks pass. Existing Authorization/Documents audit-shaped tables are never part of M01 rollback.

## 14. Exit criteria and retained evidence

M01 may enter `completed` only when all of the following are true on one recorded commit:

- M00 and Authorization/outbox handoffs are evidenced and still match the implemented contract.
- All four Audit tables exist with exact ownership, constraints, indexes, guards, reversible migration, and no cross-owner foreign keys.
- Contract recording is atomic with producer state/idempotency/outbox, redacts before persistence, has deterministic replay/conflict semantics, and fails closed.
- Query/detail/export/integrity routes enforce session, CSRF where applicable, capability before validation/disclosure, row scope, problem+json, correlation, opaque cursors, idempotency, ETag/CAS, and bounded results.
- Retention deadlines are fixed, minimums enforced, purge is contiguous/checkpointed/atomic, and integrity proof survives lifecycle deletion.
- All three events are registered with exact schemas; no raw context appears in their payloads; `AuditExportCompletedV1` occurs exactly once per successful descriptor/snapshot POST and never for download attempts.
- Web UI passes focused tests and the six smoke scenarios; `/audit` is correctly capability-gated.
- All eight shared/owner token receipts are merged/released; Authorization and Documents owners applied their immutable M01 packets; generated client has no manual edit or drift; no test fake is production-bound.
- Targeted, MySQL XML discovery and suite execution, producer atomicity/rollback, boundary, full API, API generation/check, web unit, and web build gates pass without skips.
- P04, M02, and M07 receive the final contract/evidence handoff names from Section 7.

Retain evidence under a directory named `docs/architecture/evidence/M01/` plus the exact lowercase full implementation commit SHA. Its `manifest.json` has this required shape:

```json
{
  "schema_version": 1,
  "plan_id": "M01",
  "commit": "40 lowercase hexadecimal characters",
  "verified_at": "UTC RFC3339 timestamp",
  "gates": [{"name": "stable gate name", "command": "exact command", "exit_code": 0, "stdout": "relative .log path", "stderr": "relative .log path", "sha256": "64 lowercase hexadecimal characters"}],
  "smoke_scenarios": [{"name": "stable scenario name", "status": "passed", "evidence": ["relative screenshot, trace, or HAR path"]}],
  "migration": {"sqlite_round_trip": "relative log path", "mysql_round_trip": "relative log path", "concurrency": "relative log path"},
  "privacy": {"redaction_test": "relative log path", "browser_storage_scan": "relative log path", "network_scan": "relative log path", "export_scan": "relative log path"},
  "integrity": {"valid_chain": "relative log path", "tamper_detection": "relative log path", "retention_checkpoint": "relative log path"},
  "contracts": {"openapi_diff": "relative patch path", "generated_diff": "relative patch path", "api_check": "relative log path"},
  "integration_tokens": ["relative token receipt path"],
  "rollback_rehearsal": "relative log path"
}
```

Every referenced file exists, is relative to the evidence directory, and has its own SHA-256 in either the gate entry or a sibling `checksums.sha256`. Secrets, cookies, session data, CSRF tokens, raw Audit context, and exported domain rows are excluded from retained artifacts. Commit identity may be read and evidence recorded only after the user authorizes a commit; planning completion does not authorize one.

## 15. Status transition rules

- `blocked → ready`: orchestration records approved M00 plus `ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX`; no stale shared token is assumed.
- `ready → in_progress`: an authorized executor starts in an isolated worktree and records the base commit. Module-owned files may be edited; shared files remain blocked.
- `in_progress → blocked`: any prerequisite changes, production binding is unavailable, M00 contract drifts, integrity/retention design cannot satisfy P04, or the next required shared token is unavailable. Record the exact gate and preserve passing module-owned evidence.
- `blocked → in_progress`: the named blocker is resolved and evidence records the new base; do not bypass or silently narrow the blocked phase.
- `in_progress → verification`: all module-owned work, both owner-applied representative producer packets, eight token merges, generated client, and web shell integration are complete; no fake production binding remains.
- `verification → in_progress`: any gate, smoke scenario, privacy scan, migration round-trip, or retained-evidence check fails; fix the source and rerun the affected plus downstream gates.
- `verification → completed`: every Section 14 criterion passes on the same recorded commit, the evidence manifest is complete, and the orchestration plan records M01 completion and releases M02/M07/P04 gates.
- `any → superseded`: only a later user-approved plan may replace M01; record its path and migration/rollback treatment.

A plan draft, module scaffold, SQLite-only pass, narrow test pass, unprocessed shared token, unresolved generated drift, missing MySQL evidence, or unrecorded commit is never `completed`.
