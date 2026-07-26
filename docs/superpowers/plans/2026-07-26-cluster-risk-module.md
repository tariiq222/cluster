# Cluster Risk Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## 1. Status and dependencies

```yaml
plan_id: M06
status: blocked
depends_on:
  - M00
blocks:
  - M07:final-integration
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Start gate:** `M00` is approved and its Risk rank, table inventory, capabilities, routes, Contracts, Events, and queue order are frozen.

**Blocked integration gates:** module-owned Risk work may proceed against deterministic test fakes after the start gate. Strategy linkage cannot use its production binding until `M04` has merged `ResolveStrategyReference`; project linkage cannot use its production binding until `M05` has merged `ResolveAuthorizedProjectReference`. The branch cannot enter `verification` while either fake remains bound. Shared integration also waits for the current architecture-closure owner to release its named surfaces and for the M06 tokens in §12.

**Goal:** Deliver a production-grade Risk module that owns risk registers and their policy versions, risk entries, quantitative assessments, controls, treatments and actions, owners, review cadence, immutable lifecycle evidence, KRIs, strategy/project references, and authorization-filtered workspace reads.

**Architecture:** Risk is rank 10. Every request follows Risk-owned HTTP controller → request parsing and capability decision → Risk application handler → Risk-owned persistence. Risk consumes Strategy and PortfolioProjects only through the exact M00 Contracts, publishes typed Risk Events through the transactional outbox, and exposes narrow read Contracts for Workspace; it never queries another module's tables.

**Tech stack:** PHP 8.3, Laravel 13.8, UUIDv7, MySQL and SQLite test lanes, the shared transactional outbox, React 19, TypeScript 6, Vite 8, Vitest 4, Playwright 1.61, OpenAPI, and Orval.

## 2. User-visible outcome

An authorized user can:

1. open `/risk` and see only accessible registers and risks, with current score, band, owner, next review, and overdue state;
2. create a risk entry, assign an accountable owner, choose a review cadence, and link it to authorized Strategy resources or PortfolioProjects projects without exposing either producer's persistence;
3. record inherent and residual likelihood/impact assessments and immediately see the deterministic score and heatmap position;
4. create and progress controls, treatments, and treatment actions, with due dates and clear ownership;
5. accept, escalate, reopen, or close a risk only when policy and lifecycle invariants permit;
6. perform a due review and inspect an immutable, chronological history of assessments, ownership, links, treatment transitions, and status transitions;
7. maintain KRIs and readings and see threshold breaches without confusing a missing reading with a zero value;
8. recover from stale forms with a visible refresh action instead of overwriting another user's work.

Read-only users never see mutation controls. Denied or out-of-scope resources are not disclosed. All API errors use `application/problem+json` and preserve the accepted `X-Correlation-ID`.

## 3. Current source evidence

The executor must re-read these sources at the recorded base commit before editing; the observations below are the current baseline, not permission to bypass a changed source:

- `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md` fixes M06 as `blocked`, rank 10, start dependency `M00`, final Strategy/project gates `M04` and `M05`, and serialized shared queues. Its cross-cutting rules require capability-first authorization, transactional state/idempotency/outbox effects, write-predicate optimistic concurrency, production fail-closed bindings, generated-client regeneration, and WCAG 2.2 AA.
- `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md` §6 reserves `apps/api/routes/web.php`, master OpenAPI, generated Orval output, architecture guards, Make/CI surfaces, and defines `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, and `WEB-SHELL` tokens.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS` already reserves `Risk => 10`, while `PLANNED_MODULES` rejects a runtime `Modules/Risk` directory and `TABLE_OWNERS` has no Risk tables. M00 defines the later canonical cutover; M06 may apply it only with its serialized registry token.
- `docs/architecture/module-catalog.md` states that a module may import a lower-rank module only through `Contracts/` or `Events/`, owns its HTTP and tables, and publishes only those public namespaces.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php` already contains exactly `risk.risk.read`, `risk.risk.manage`, `risk.assess`, `risk.control.manage`, `risk.treatment.manage`, `risk.accept`, and `risk.kri.manage`.
- `apps/api/Modules/Authorization/Contracts/DecideAccess.php`, `RecordFacts.php`, and `AccessDecision.php` are the authorization boundary. Risk may import these Contracts, but no Authorization Domain or Infrastructure class.
- `apps/api/Modules/Tasks/Features/Http/TaskHttpSupport.php` demonstrates strict correlation UUIDv7 parsing, printable `Idempotency-Key`, quoted integer `If-Match`, ETags, and problem responses. `CreateTaskFromWorkflowStepHandler.php` demonstrates task ownership and atomic outbox work, but it is not a public Contract and must not be imported by Risk.
- `apps/api/Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Handler/ConsumeWorkRecordSubmittedHandler.php` demonstrates CloudEvent validation and inbox deduplication. Risk publishes the M00 events; it does not write Notification tables or import a Notifications handler.
- `apps/api/Modules/Reporting/Features/RefreshReportingProjection/Handler/RefreshReportingProjectionHandler.php` demonstrates sanitized, versioned read projections. Risk owns its operational heatmap and due-review query; downstream Reporting may project public Risk events but Risk never writes Reporting tables.
- `apps/api/Shared/Contracts/TransactionalOutbox.php::append(string,string,string,array)` is the only outbox port used by Risk commands.
- `docs/contracts/api/openapi.yaml` currently marks `/risk/{riskResource}`, `/risk/risks/{riskId}`, lifecycle, indicator-reading, heatmap, and due-review operations as `x-implementation-status: planned`. The current `RiskAction` scale is 1–10. These planned generic shapes are replaced by the explicit resource contract in §7; no compatibility route is retained.
- `apps/web/src/api/generated/cluster.ts` already contains generated planned Risk symbols. It is generated output and must be changed only by `npm --prefix apps/web run api:generate` while the `OPENAPI`/`ORVAL` token is held.
- `apps/api/config/module_migrations.php` and `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` are shared registries. They are touched only in the serialized `MODULE-REGISTRY` integration slot.

## 4. Scope and explicit non-goals

### In scope

- risk registers and immutable policy versions;
- risk entries with accountable user and organization-unit ownership;
- inherent, residual, and periodic review assessments;
- controls, treatments/mitigations, treatment actions, and their owners/dates/states;
- review cadence, overdue dispatch, status/treatment/ownership/link history;
- KRI definitions, thresholds, and immutable readings;
- Strategy and Project links validated by M04/M05 Contracts and stored as Risk-owned opaque references;
- REST API, OpenAPI contract, generated client, `/risk` web feature, and browser journey;
- authorization, classification, cursor pagination, idempotency, CAS, outbox, MySQL concurrency, retry, and recovery evidence;
- `ResolveRiskReference` and `QueryRiskWorkspaceItems` for higher-rank consumers.

### Non-goals

- no SQL, Eloquent relationship, foreign key, or Infrastructure/Domain import targeting Strategy or PortfolioProjects;
- no Risk write into Tasks, Notifications, Reporting, Workspace, Audit, Documents, or Authorization tables;
- no import of the current Tasks feature handler as if it were a public Contract; `risk_treatment_actions` is M06's canonical action aggregate;
- no hand-edit of `apps/web/src/api/generated/cluster.ts`;
- no independent edit of routes, OpenAPI, shell, module registry, architecture guards, Makefile, or workflows; §12 routes these surfaces through their exclusive queues;
- no new Risk table beyond M00's exact inventory;
- no mutable or deletable assessment, KRI-reading, or history row;
- no production fake, optional no-op consumer, permissive unresolved-reference fallback, or skipped critical verification;
- no commit, push, migration execution against a shared environment, deployment, notification send, or cloud change without explicit user authorization.

## 5. Architecture, ownership, and invariants

### Module boundary

```text
Risk HTTP controller
  -> parse correlation/session and the minimum routing identifiers
  -> DecideAccess(capability, RecordFacts)
  -> validate the authorized command/query
  -> Risk handler
  -> DatabaseRiskStore (Risk tables only)
  -> TransactionalOutbox (same DB transaction for mutations)
```

Cross-module links are one-way:

```text
Risk -> Modules\Strategy\Contracts\ResolveStrategyReference
Risk -> Modules\PortfolioProjects\Contracts\ResolveAuthorizedProjectReference
Workspace -> Modules\Risk\Contracts\QueryRiskWorkspaceItems
Other consumers <- Modules\Risk\Events\*V1 / JSON schemas
```

### Scoring invariants

1. `likelihood` and `impact` are integers in `[1,10]`; `score = likelihood * impact` and is therefore `[1,100]`.
2. A published `risk_policy_version` freezes `low_max`, `moderate_max`, `high_max`, and `acceptance_max_score` with `1 <= low_max < moderate_max < high_max < 100` and `acceptance_max_score <= moderate_max`.
3. Bands are `low` for `score <= low_max`, `moderate` through `moderate_max`, `high` through `high_max`, and `critical` above `high_max`. The assessment stores the policy version and computed band; later policy publication never rewrites history.
4. An inherent assessment requires both likelihood and impact. A residual assessment also requires both, names the control IDs evaluated, and cannot precede an inherent assessment. Residual score may exceed the earlier inherent score; the system records observed risk rather than silently clipping it.
5. `risks.current_assessment_id`, `current_score`, and `current_band` change atomically with the append-only assessment and history row.
6. KRI thresholds require `warning_threshold < critical_threshold`. A reading's breach is computed against the threshold snapshot captured on the reading; no client-supplied breach state is trusted.

### Lifecycle invariants

- Risk: `draft -> open` by first assessment; `open|under_treatment|accepted -> escalated`; `open|escalated -> under_treatment` when an approved treatment starts; `open|under_treatment|escalated -> accepted` only through `risk.accept` and only when current score is at or below the assessment policy's acceptance maximum; `accepted|escalated|under_treatment -> open` through `reopen`; `open|under_treatment|accepted|escalated -> closed` only when every non-cancelled treatment action is completed and a current review supplies a closure reason. `closed -> open` requires `reopen`; no other transition leaves `closed`.
- Control: `planned -> implemented -> effective|ineffective`; `planned|implemented|effective|ineffective -> retired`. A retired control cannot appear in a new residual assessment.
- Treatment: `proposed -> approved -> in_progress -> implemented -> verified`; `proposed|approved|in_progress -> cancelled`. `verified` and `cancelled` are terminal.
- Treatment action: `open -> in_progress -> completed`; `open|in_progress -> cancelled`. `completed` and `cancelled` are terminal.
- Every state, owner, reference, control, treatment, and action transition appends an immutable `risk_reviews` ledger row in the same transaction. The row has `entry_kind`, target type/id, from/to snapshots, reason, actor, correlation ID, aggregate lock version, and timestamp.
- `review_cadence_days` is an integer `[1,365]`. Completing a scheduled review sets `last_reviewed_at` and `next_review_at = reviewed_at + review_cadence_days`, clears `review_due_event_emitted_at`, and appends a `scheduled_review` ledger row. Due dispatch uses a CAS update so each due cycle emits once.
- Current Strategy/project references are canonicalized, sorted unique JSON arrays on `risks`; M00 owns no separate Risk link table. Each element stores only `{resource_type,id,source_version}`. Addition/removal is validated against the producer Contract and recorded as a `link_change` ledger row. There are no external foreign keys.

## 6. Exact file map

### Create: Risk-owned API files

- `apps/api/Modules/Risk/Providers/RiskServiceProvider.php` — internal bindings, migration loading after registry integration, and module-owned due-dispatch schedule registration.
- `apps/api/Modules/Risk/Contracts/ResolveRiskReference.php`
- `apps/api/Modules/Risk/Contracts/RiskAccessContext.php`
- `apps/api/Modules/Risk/Contracts/RiskReference.php`
- `apps/api/Modules/Risk/Contracts/QueryRiskWorkspaceItems.php`
- `apps/api/Modules/Risk/Contracts/RiskWorkspaceQuery.php`
- `apps/api/Modules/Risk/Contracts/RiskWorkspaceItem.php`
- `apps/api/Modules/Risk/Contracts/RiskWorkspacePage.php`
- `apps/api/Modules/Risk/Events/RiskChangedV1.php`
- `apps/api/Modules/Risk/Events/RiskReviewDueV1.php`
- `apps/api/Modules/Risk/Events/RiskTreatmentActionDueV1.php`
- `apps/api/Modules/Risk/Domain/RiskScore.php`
- `apps/api/Modules/Risk/Domain/RiskLifecycle.php`
- `apps/api/Modules/Risk/Domain/ControlLifecycle.php`
- `apps/api/Modules/Risk/Domain/TreatmentLifecycle.php`
- `apps/api/Modules/Risk/Domain/TreatmentActionLifecycle.php`
- `apps/api/Modules/Risk/Application/RiskStore.php`
- `apps/api/Modules/Risk/Infrastructure/Persistence/DatabaseRiskStore.php`
- `apps/api/Modules/Risk/Infrastructure/Persistence/Migrations/CreateRiskTables.php`
- `apps/api/Modules/Risk/Features/HttpSupport/RiskHttpSupport.php`
- `apps/api/Modules/Risk/Features/Register/Handler/RiskRegisterHandler.php`
- `apps/api/Modules/Risk/Features/Register/Http/RiskRegisterController.php`
- `apps/api/Modules/Risk/Features/RiskEntry/Handler/RiskEntryHandler.php`
- `apps/api/Modules/Risk/Features/RiskEntry/Http/RiskEntryController.php`
- `apps/api/Modules/Risk/Features/Assessment/Handler/RiskAssessmentHandler.php`
- `apps/api/Modules/Risk/Features/Assessment/Http/RiskAssessmentController.php`
- `apps/api/Modules/Risk/Features/Control/Handler/RiskControlHandler.php`
- `apps/api/Modules/Risk/Features/Control/Http/RiskControlController.php`
- `apps/api/Modules/Risk/Features/Treatment/Handler/RiskTreatmentHandler.php`
- `apps/api/Modules/Risk/Features/Treatment/Http/RiskTreatmentController.php`
- `apps/api/Modules/Risk/Features/TreatmentAction/Handler/RiskTreatmentActionHandler.php`
- `apps/api/Modules/Risk/Features/TreatmentAction/Http/RiskTreatmentActionController.php`
- `apps/api/Modules/Risk/Features/Review/Handler/RiskReviewHandler.php`
- `apps/api/Modules/Risk/Features/Review/Http/RiskReviewController.php`
- `apps/api/Modules/Risk/Features/Indicator/Handler/RiskIndicatorHandler.php`
- `apps/api/Modules/Risk/Features/Indicator/Http/RiskIndicatorController.php`
- `apps/api/Modules/Risk/Features/IndicatorReading/Handler/RiskIndicatorReadingHandler.php`
- `apps/api/Modules/Risk/Features/IndicatorReading/Http/RiskIndicatorReadingController.php`
- `apps/api/Modules/Risk/Features/Link/Handler/RiskLinkHandler.php`
- `apps/api/Modules/Risk/Features/Heatmap/Handler/GetRiskHeatmapHandler.php`
- `apps/api/Modules/Risk/Features/Heatmap/Http/GetRiskHeatmapController.php`
- `apps/api/Modules/Risk/Features/DispatchDueEvents/Handler/DispatchRiskDueEventsHandler.php`
- `apps/api/Modules/Risk/Features/DispatchDueEvents/Console/DispatchRiskDueEventsCommand.php`

### Create: Risk tests

- `apps/api/Modules/Risk/Tests/RiskScoreAndLifecycleTest.php`
- `apps/api/Modules/Risk/Tests/RiskMigrationTest.php`
- `apps/api/Modules/Risk/Tests/RiskAuthorizationHttpTest.php`
- `apps/api/Modules/Risk/Tests/RiskCommandAtomicityTest.php`
- `apps/api/Modules/Risk/Tests/RiskConcurrencyIdempotencyTest.php`
- `apps/api/Modules/Risk/Tests/RiskReferenceIntegrationTest.php`
- `apps/api/Modules/Risk/Tests/RiskDueEventDispatchTest.php`
- `apps/api/Modules/Risk/Tests/RiskWorkspaceContractTest.php`
- `apps/api/Modules/Risk/Tests/RiskMySqlConcurrencyTest.php`

### Create: Risk-owned web files

- `apps/web/src/features/risk/riskApi.ts`
- `apps/web/src/features/risk/RiskRegisterScreen.tsx`
- `apps/web/src/features/risk/RiskRegisterScreen.test.tsx`
- `apps/web/src/features/risk/RiskDetailScreen.tsx`
- `apps/web/src/features/risk/RiskDetailScreen.test.tsx`
- `apps/web/src/features/risk/RiskHeatmap.tsx`
- `apps/web/src/features/risk/RiskHeatmap.test.tsx`
- `apps/web/src/features/risk/RiskReviewQueue.tsx`
- `apps/web/src/features/risk/RiskReviewQueue.test.tsx`
- `apps/web/src/features/risk/risk.css`
- `apps/web/e2e/risk.spec.ts`

### Serialized shared changes; never M06-owned

- `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` — add provider in the M06 `MODULE-REGISTRY` slot.
- `apps/api/config/module_migrations.php` — add `CreateRiskTables.php` in the same slot.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php::{PLANNED_MODULES,TABLE_OWNERS}` — remove only `Risk` from planned inventory and add the exact eleven M00 tables; do not alter rank 10.
- `docs/architecture/module-catalog.md` — apply M00's implemented-module entry in the registry slot.
- `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php` and the three schemas in §7 — serialized event-catalog integration; no parallel ownership claim.
- `apps/api/routes/web.php` — `API-ROUTES` token only.
- `docs/contracts/api/openapi.yaml` — `OPENAPI` token only.
- `apps/web/src/api/generated/cluster.ts` — `ORVAL` token and generation command only.
- `apps/web/src/shell/routes.ts` and `apps/web/src/shell/navigation.tsx` — `WEB-SHELL` token only; M07 retains only the final aggregation role.

No Makefile or workflow edit belongs to M06.

## 7. Public Contracts, Events, routes, schemas, and capabilities

### Published PHP Contracts

```php
namespace Modules\Risk\Contracts;

interface ResolveRiskReference
{
    public function resolve(RiskAccessContext $context, string $riskId): ?RiskReference;
}

interface QueryRiskWorkspaceItems
{
    public function query(RiskWorkspaceQuery $query): RiskWorkspacePage;
}
```

`RiskAccessContext` fields are `actorId`, `facilityId`, `organizationUnitIds`, and `correlationId`. `RiskReference` fields are `id`, `registerId`, `code`, `title`, `status`, `ownerOrganizationUnitId`, `ownerUserId`, `classification`, `lockVersion`, and `sourceVersion`. A denied or nonexistent reference returns `null`; it never returns a partially populated object.

`RiskWorkspaceQuery` fields are the same actor/scope facts plus `cursor`, `limit` (`1..100`), and optional `dueBefore`. `RiskWorkspaceItem` contains `riskId`, `title`, `status`, `currentScore`, `currentBand`, `ownerUserId`, `nextReviewAt`, `overdueActionCount`, `classification`, and `etag`. `RiskWorkspacePage` contains `items` and an opaque `nextCursor`; ordering is `(next_review_at ASC, id ASC)` and the cursor encodes exactly that tuple.

### Consumed producer Contracts

```php
Modules\Strategy\Contracts\ResolveStrategyReference::resolve(
    Modules\Strategy\Contracts\StrategyAccessContext $context,
    Modules\Strategy\Contracts\StrategyResourceType $type,
    string $id,
): ?Modules\Strategy\Contracts\StrategyReference;

Modules\PortfolioProjects\Contracts\ResolveAuthorizedProjectReference::resolve(
    Modules\PortfolioProjects\Contracts\ProjectAccessContext $context,
    string $projectId,
): ?Modules\PortfolioProjects\Contracts\ProjectReference;
```

Strategy resource types accepted by Risk are the M04 enum cases for plans and objectives. Project references are authorized in the actor context supplied to M05. `null` maps to a non-disclosing `404`; producer failure maps to `503 dependency-unavailable` and no Risk row changes.

### Events and schema paths

| PHP event class | Event type | Schema path | Minimum safe payload |
|---|---|---|---|
| `Modules\Risk\Events\RiskChangedV1` | `com.cluster.risk.riskchanged.v1` | `docs/contracts/schemas/com-cluster-risk-riskchanged-v1.schema.json` | `risk_id`, `scope_id`, `owner_user_id`, `status`, `current_score`, `current_band`, `next_review_at`, `lock_version`, `classification` |
| `Modules\Risk\Events\RiskReviewDueV1` | `com.cluster.risk.riskreviewdue.v1` | `docs/contracts/schemas/com-cluster-risk-riskreviewdue-v1.schema.json` | `risk_id`, `scope_id`, `owner_user_id`, `due_at`, `due_cycle`, `classification` |
| `Modules\Risk\Events\RiskTreatmentActionDueV1` | `com.cluster.risk.risktreatmentactiondue.v1` | `docs/contracts/schemas/com-cluster-risk-risktreatmentactiondue-v1.schema.json` | `risk_id`, `action_id`, `scope_id`, `assignee_user_id`, `due_at`, `due_cycle`, `classification` |

All three are CloudEvents 1.0-compatible typed payloads. No description, rationale, health note, personal name, Strategy title, Project title, or free text enters an event. Event IDs and correlation IDs are lowercase UUIDv7. Event append is atomic with the state/idempotency/history write. Consumers deduplicate by event ID.

### Capability map

| Operation | Required capability |
|---|---|
| list/get/heatmap/due/history | `risk.risk.read` |
| create/update register, policy, risk, owner, links | `risk.risk.manage` |
| record assessment or review | `risk.assess` |
| create/update/transition control | `risk.control.manage` |
| create/update/transition treatment or action | `risk.treatment.manage` |
| accept risk | `risk.accept` in addition to object access |
| create/update KRI or record reading | `risk.kri.manage` |

### API routes and operation IDs

All routes are below `/api/v1`, require `IdentitySessionMiddleware` and `RequireIdentitySessionPrincipal`, and all mutations additionally require `IdentityCsrfMiddleware`. Collection reads use opaque cursor pagination with `limit <= 100`.

- `GET|POST /risk/registers` → `listRiskRegisters`, `createRiskRegister`
- `GET|PATCH /risk/registers/{registerId}` → `getRiskRegister`, `updateRiskRegister`
- `GET|POST /risk/registers/{registerId}/policy-versions` → `listRiskPolicyVersions`, `createRiskPolicyVersion`
- `POST /risk/policy-versions/{policyVersionId}/publish` → `publishRiskPolicyVersion`
- `GET|POST /risk/risks` → `listRisks`, `createRisk`
- `GET|PATCH /risk/risks/{riskId}` → `getRisk`, `updateRisk`
- `POST /risk/risks/{riskId}/{riskAction}` with `accept|escalate|reopen|close` → `transitionRisk`
- `GET|POST /risk/risks/{riskId}/assessments` → `listRiskAssessments`, `createRiskAssessment`
- `GET|POST /risk/risks/{riskId}/controls` → `listRiskControls`, `createRiskControl`
- `PATCH /risk/controls/{controlId}` and `POST /risk/controls/{controlId}/{controlAction}` → `updateRiskControl`, `transitionRiskControl`
- `GET|POST /risk/risks/{riskId}/treatments` → `listRiskTreatments`, `createRiskTreatment`
- `PATCH /risk/treatments/{treatmentId}` and `POST /risk/treatments/{treatmentId}/{treatmentAction}` → `updateRiskTreatment`, `transitionRiskTreatment`
- `GET|POST /risk/treatments/{treatmentId}/actions` → `listRiskTreatmentActions`, `createRiskTreatmentAction`
- `PATCH /risk/treatment-actions/{actionId}` and `POST /risk/treatment-actions/{actionId}/{actionAction}` → `updateRiskTreatmentAction`, `transitionRiskTreatmentAction`
- `GET|POST /risk/risks/{riskId}/reviews` → `listRiskReviews`, `completeRiskReview`
- `PUT|DELETE /risk/risks/{riskId}/strategy-links/{resourceType}/{resourceId}` → `putRiskStrategyLink`, `deleteRiskStrategyLink`
- `PUT|DELETE /risk/risks/{riskId}/project-links/{projectId}` → `putRiskProjectLink`, `deleteRiskProjectLink`
- `GET|POST /risk/risks/{riskId}/indicators` → `listRiskIndicators`, `createRiskIndicator`
- `PATCH /risk/indicators/{indicatorId}` → `updateRiskIndicator`
- `GET|POST /risk/indicators/{indicatorId}/readings` → `listRiskIndicatorReadings`, `createRiskIndicatorReading`
- `GET /risk/heatmap?scope_id={uuid}` → `getRiskHeatmap`
- `GET /risk/reviews/due` → `listDueRiskReviews`

Creates and transitions require `Idempotency-Key`; PATCH, PUT, DELETE, and transitions require a quoted integer `If-Match`. Successful entity reads/writes return `ETag: "<lock_version>"`. Successful mutations return `data` plus the accepted correlation header. Errors are problem+json with stable types: `invalid-request` 400, `authentication-required` 401, `forbidden` 403, `not-found` 404, `idempotency-conflict` or `invalid-transition` 409, `stale-write` 412, `unprocessable-content` 422, and `dependency-unavailable` 503.

### Web route

`/risk` is guarded by `risk.risk.read`. Its internal view state selects register, heatmap, due reviews, or risk detail without placing classification, owner identity, rationale, or search text in the URL or browser persistence.

## 8. Database design, migration order, and recovery

M00 owns exactly these eleven tables; the migration creates them in this order and drops them in reverse:

1. `risk_registers`
2. `risk_policy_versions`
3. `risks`
4. `risk_assessments`
5. `risk_controls`
6. `risk_treatments`
7. `risk_treatment_actions`
8. `risk_reviews`
9. `risk_indicators`
10. `risk_indicator_readings`
11. `risk_idempotency_keys`

### Required columns and constraints

- `risk_registers`: UUIDv7 `id`; `cluster_id`, `owner_facility_id`, `owner_organization_unit_id`; `code`, `name`, `classification`, `status`; `lock_version`; timestamps. Unique `(cluster_id, code)`; indexes `(owner_organization_unit_id,status,id)` and `(classification,status,id)`.
- `risk_policy_versions`: UUIDv7 `id`; local FK `register_id`; positive `version`; `status` (`draft|published|retired`); nullable `is_current`; four band/acceptance limits; `effective_from`; `published_at`, `published_by_user_id`; `lock_version`; timestamps. Unique `(register_id,version)` and `(register_id,is_current)`. Draft/retired rows store `is_current = null`; the sole published row stores `is_current = 1`. Publication retires the prior row and claims `is_current = 1` in one transaction, so both MySQL and SQLite enforce one current policy.
- `risks`: UUIDv7 `id`; local FK `register_id`; `code`, `title`, `description`, `category`; external opaque owner/scope UUIDs; `status`, `classification`, cadence timestamps and due-dispatch marker; nullable local current assessment reference; denormalized current score/band; normalized `strategy_references` and `project_references` JSON; `lock_version`; timestamps. Unique `(register_id,code)`; indexes `(owner_organization_unit_id,status,next_review_at,id)`, `(owner_user_id,status,next_review_at,id)`, and `(register_id,current_band,status,id)`.
- `risk_assessments`: UUIDv7 `id`; local FKs `risk_id`, `policy_version_id`; `assessment_type`; likelihood, impact, computed score/band; canonical JSON list `evaluated_control_ids`; rationale; actor; `risk_lock_version`; `created_at`. Append-only. Index `(risk_id,created_at,id)`.
- `risk_controls`: UUIDv7 `id`; local FK `risk_id`; code/title/type, owner IDs, status, effectiveness percentage, review dates, due marker, `lock_version`, timestamps. Unique `(risk_id,code)` and index `(owner_user_id,next_review_at,status,id)`.
- `risk_treatments`: UUIDv7 `id`; local FK `risk_id`; code/title/strategy/rationale, owner IDs, status, target score, due date, `lock_version`, timestamps. Unique `(risk_id,code)` and index `(owner_user_id,status,due_at,id)`.
- `risk_treatment_actions`: UUIDv7 `id`; local FK `treatment_id`; title, assignee, status, due/completed timestamps, due-dispatch marker, `lock_version`, timestamps. Index `(assignee_user_id,status,due_at,id)`.
- `risk_reviews`: UUIDv7 `id`; local FK `risk_id`; nullable local `control_id`, `treatment_id`, `treatment_action_id`; `entry_kind`; `target_type`, `target_id`; canonical JSON `from_snapshot`, `to_snapshot`; reason, actor, correlation UUIDv7, aggregate lock version, `reviewed_at`, `next_review_at`, `created_at`. A check enforces exactly this target shape: `risk` has all three child FKs null; `control` has only `control_id`; `treatment` has only `treatment_id`; `treatment_action` has only `treatment_action_id`. Append-only; index `(risk_id,created_at,id)` and `(entry_kind,next_review_at,id)`.
- `risk_indicators`: UUIDv7 `id`; local FK `risk_id`; code/name/unit/direction; warning/critical thresholds; owner; status; `lock_version`; timestamps. Unique `(risk_id,code)`.
- `risk_indicator_readings`: UUIDv7 `id`; local FK `indicator_id`; observed time/value; threshold snapshot and computed breach level; actor; canonical evidence document UUID array; `created_at`. Append-only; unique `(indicator_id,observed_at,id)` and index `(indicator_id,observed_at,id)`.
- `risk_idempotency_keys`: numeric PK; principal UUID; operation; SHA-256 key hash and request hash; resource type/id; response status and canonical response JSON; timestamps. Unique `(principal_id,operation,key_hash)`.

Only Risk-local foreign keys are allowed. User, facility, organization-unit, Strategy, Project, and Document IDs remain opaque UUIDv7 values validated through the appropriate public contract or authorization facts. Every JSON column is written from a recursively key-sorted canonical representation and validated on read; malformed stored JSON fails closed and raises an operator-visible error rather than returning partial data.

The migration test must run on SQLite and MySQL. MySQL must prove check/unique behavior and real concurrent CAS. Rollback is reversible only while no production Risk data exists; production rollback retains tables and rolls application code back first as specified in §13.

## 9. TDD implementation tasks

No implementation step starts before its named red test has failed for the expected reason. No commit step is included because commits are not authorized.

### Task 1: Domain scoring and lifecycle

**Files:** create the five Domain files and `RiskScoreAndLifecycleTest.php` from §6.

- [ ] Write parameterized failing tests for scores `1*1=1`, `5*6=30`, and `10*10=100`; reject 0, 11, non-integers, invalid policy band ordering, and acceptance maximum above moderate maximum.
- [ ] Write table-driven failing tests for every allowed and forbidden Risk, Control, Treatment, and Treatment Action transition in §5.
- [ ] Include this exact acceptance example:

```php
$policy = RiskScore::policy(lowMax: 20, moderateMax: 50, highMax: 75, acceptanceMaxScore: 20);
$score = RiskScore::assess($policy, likelihood: 8, impact: 9);
self::assertSame(72, $score->value);
self::assertSame('high', $score->band);
```

- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskScoreAndLifecycleTest.php`; expect failure because the Risk Domain classes do not exist.
- [ ] Implement immutable value objects and explicit transition maps. Do not accept string states outside the listed enums.
- [ ] Re-run the same command; expect all tests in the file to pass.

### Task 2: Persistence and immutable evidence

**Files:** create `CreateRiskTables.php`, `RiskStore.php`, `DatabaseRiskStore.php`, and `RiskMigrationTest.php`.

- [ ] Write the failing migration test asserting all eleven tables, required columns, indexes, local FKs, unique constraints, and absence of `risk_links`, `risk_history`, and any external FK.
- [ ] Write failing repository tests that append assessments/readings/reviews, reject their update/delete paths, canonicalize link JSON, and return stable cursor pages.
- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskMigrationTest.php`; expect failure with missing `risk_registers`.
- [ ] Implement the migration in the exact §8 order and the store with named query methods; every mutation accepts `expectedLockVersion` and applies it in `WHERE id = ? AND lock_version = ?`.
- [ ] Re-run the migration test; expect pass on SQLite. Retain MySQL execution for the explicit lane in §11.

### Task 3: Authorization, commands, idempotency, and atomic outbox

**Files:** create the Register, RiskEntry, Assessment, Control, Treatment, TreatmentAction, Review, Indicator, IndicatorReading, and Link handlers; create `RiskAuthorizationHttpTest.php`, `RiskCommandAtomicityTest.php`, and `RiskConcurrencyIdempotencyTest.php`.

- [ ] Write a failing test proving a principal without `risk.risk.manage` receives the same non-disclosing response for malformed, missing, and out-of-scope target IDs, and that detailed body validation was not invoked.
- [ ] Write failing tests for every capability row in §7, including the additional `risk.accept` decision.
- [ ] Write failing tests proving same key/same canonical request replays the original status/body/resource without a second state/history/outbox write; same key/different request returns 409.
- [ ] Write a failing transaction fault test whose outbox append throws; assert state, history, and idempotency rows all roll back.
- [ ] Write a failing stale-write test in which two handlers use ETag `"1"`; exactly one update succeeds and the other raises the typed stale exception mapped to 412.
- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskAuthorizationHttpTest.php Modules/Risk/Tests/RiskCommandAtomicityTest.php Modules/Risk/Tests/RiskConcurrencyIdempotencyTest.php`; expect failures from missing handlers.
- [ ] Implement capability-first handlers, SHA-256 idempotency storage, CAS writes, immutable evidence, and `TransactionalOutbox::append()` inside one `DB::transaction()`.
- [ ] Re-run the command; expect all three files to pass.

### Task 4: Strategy and project linkage behind producer gates

**Files:** create `RiskLinkHandler.php`, `RiskReferenceIntegrationTest.php`, `RiskProductionBindingTest.php`, and production bindings in `RiskServiceProvider.php` only after M04/M05 gates.

- [ ] Before M04/M05 merge, write deterministic test fakes implementing the exact M00 producer interfaces and tests for authorized existing, denied/nonexistent, retired/closed producer, timeout, duplicate link, remove link, and producer version refresh cases. Fakes stay in `Modules/Risk/Tests/Fakes/` and are never production bindings.
- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskReferenceIntegrationTest.php`; expect failure because `RiskLinkHandler` is absent.
- [ ] Re-run `cd apps/api && php artisan test Modules/Risk/Tests/RiskReferenceIntegrationTest.php`; expect fake-backed handler cases to pass, with every Strategy resolver call constructing and passing `StrategyAccessContext` first; retain only unit/integration evidence and keep production-binding phase blocked.
- [ ] After M04/M05 merge, add `RiskProductionBindingTest.php` that boots the unmodified Laravel application container with `APP_ENV=production`, resolves `ResolveStrategyReference`, `ResolveAuthorizedProjectReference`, and `RiskStore`, and asserts exact concrete classes and context-bearing Strategy calls; absent, unauthorized, wrong-scope, draft/non-published, and wrong-type references all return `null` without disclosure.

### Final M01 Audit integration gate (blocked only on M01 completion)
- [ ] After M01 completion, write producer-owned failing tests for every successful Risk mutation; inject only `Modules\Audit\Contracts\RecordAuditEvent`, call it inside each existing mutation transaction, and prove injected Audit failure rolls back Risk state, idempotency, Risk outbox, and the Audit append. Keep this final integration/exit gate separate from core start dependencies and release the M01 integration packet.

### Task 5: HTTP adapters and exact API semantics

**Files:** create `RiskHttpSupport.php`, every controller in §6, and extend `RiskAuthorizationHttpTest.php`.

- [ ] Write failing HTTP tests for session 401, missing CSRF, 403, 404 anti-enumeration, invalid correlation 400, problem media type, correlation echo, cursor bounds, missing/invalid idempotency key, missing/malformed If-Match, ETag response, 409 replay mismatch/invalid transition, 412 stale write, and 503 producer failure.
- [ ] Write failing route tests for every method/path/operation pair in §7 and assert no generic `/risk/{riskResource}` route remains.
- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskAuthorizationHttpTest.php`; expect missing controllers/routes.
- [ ] Implement thin controllers. They may translate request/response types, but transaction, scoring, lifecycle, persistence, idempotency, and outbox logic remains in handlers/store.
- [ ] Re-run the test file after the `API-ROUTES` token merge; expect pass.

### Task 6: Due dispatch and public read Contracts

**Files:** create the due-dispatch handler/command, all M06 Contracts/Events, `RiskDueEventDispatchTest.php`, and `RiskWorkspaceContractTest.php`.

- [ ] Write failing frozen-clock tests for review/action not due, newly due, already emitted, new cadence cycle, two concurrent dispatchers, and retry after outbox failure. In the newly-due case, invoke the same handler/command twice without advancing the clock: assert the first invocation appends exactly one review event and one action event and marks both due cycles, then assert the second invocation reports `review_events=0` and `action_events=0`, appends zero outbox rows/events, creates zero additional work records, and leaves the first-run marker/history counts unchanged.
- [ ] Write a failing workspace-contract test with two principals and mixed scopes/classifications; assert stable cursor order, masked denial, exact item fields, query ceiling 100, and no cache/write outside Risk.
- [ ] Run `cd apps/api && php artisan test Modules/Risk/Tests/RiskDueEventDispatchTest.php Modules/Risk/Tests/RiskWorkspaceContractTest.php`; expect missing implementations.
- [ ] Implement `risk:dispatch-due --once --limit=100`. Claim rows with a write-predicate lock/due-cycle condition, append the exact events, and set emitted timestamps atomically. Register the command and its five-minute non-overlapping schedule in `RiskServiceProvider`; do not edit P01-owned loop or `routes/console.php`.
- [ ] Implement `ResolveRiskReference` and `QueryRiskWorkspaceItems` using capability-filtered Risk queries only.
- [ ] Re-run both files; expect pass, exactly one event per due cycle under concurrency, and the immediate second dispatch to report zero work/events.

### Task 7: Risk web feature

**Files:** create all `apps/web/src/features/risk/*` files.

- [ ] Write failing unit tests for authorized list, score/band rendering, heatmap keyboard access and non-color labels, due-review ordering, permission-hidden mutations, Arabic/English labels, loading/empty/error/zero states, idempotent submit disablement, and 412 refresh/reapply flow.
- [ ] Run `npm --prefix apps/web run test:unit -- src/features/risk`; expect failure because the feature files do not exist.
- [ ] Implement the screens with semantic headings, labeled controls, visible focus, 44×44 CSS-pixel targets, live regions for mutation results, table/card alternatives for the heatmap, and `prefers-reduced-motion` behavior. Never persist sensitive form or filter state.
- [ ] Use only generated API functions from `riskApi.ts`; no handwritten duplicate wire types.
- [ ] Re-run the unit command; expect all Risk feature tests to pass.

### Task 8: Contract and serialized shared integration

**Files:** only the shared surfaces listed in §6, and only under granted tokens.

- [ ] With `MODULE-REGISTRY`, integrate provider, migration, module catalog, event enum/schema cases, and exact table ownership. Run the red architecture test first; it must fail because Risk is still planned or its tables/events are unowned.
- [ ] With `API-ROUTES`, add the exact §7 routes and middleware; the route contract test must turn green.
- [ ] With `OPENAPI`, replace planned generic Risk paths/schemas with explicit operations, problem responses, headers, enums, cursor shapes, and lifecycle bodies. Set implemented operations to the repository's implemented status convention.
- [ ] Run `npm --prefix apps/web run api:lint`; expect success before generation.
- [ ] With the same contract grant, run `npm --prefix apps/web run api:generate`; expect only generated output to change under `apps/web/src/api/generated/`.
- [ ] Run `npm --prefix apps/web run api:check`; expect zero generated drift.
- [ ] With `WEB-SHELL`, register `/risk` and its navigation capability without changing M07's final aggregation ownership.
- [ ] Run targeted API and web tests before releasing each token; retain the token record and output in §14's manifest.

### Task 9: Browser and real-database proof

**Files:** create `RiskMySqlConcurrencyTest.php` and `apps/web/e2e/risk.spec.ts`; extend P07-owned `ProductionE2ESeeder.php` and its seed JSON contract only through P07's fixture integration queue.

- [ ] Register the exact class `Modules\Risk\Tests\RiskMySqlConcurrencyTest` in the existing `make verify-mysql-integration` runner's explicit MySQL class list. The class must assert the active driver is `mysql`, fail rather than skip otherwise, and exercise concurrent risk assessment, treatment transition, due-event claim, and idempotency insert. Run `make verify-mysql-integration`; expect exit 0 with `Modules\Risk\Tests\RiskMySqlConcurrencyTest` named in output, at least one executed test/assertion from that class, and no `SKIP`/zero-test result.
- [ ] Through the P07 fixture queue, make `ProductionE2ESeeder` create deterministic Risk inputs for run ID `P07_RUN_ID`: manager and read-only persona keys, CSRF-capable credentials, one register/published `20/50/75` policy, one authorized Strategy objective, one authorized PortfolioProjects project, one out-of-scope reference of each kind, and stable Risk natural keys/initial ETags. Export their opaque IDs and persona keys in the mode-`0600` seed JSON consumed by `production-fixtures.ts`; `risk.spec.ts` must obtain every dynamic ID, credential, and origin from that JSON/connection manifest and must not invent UUIDs, call a module seeder directly, or assume a pre-existing browser session.
- [ ] Add `apps/web/e2e/risk.spec.ts` to P07's explicit production `testMatch` and discovery inventory, then run `P07_TEST_MATCH=risk.spec.ts ./infra/platform/production/run-local-e2e.sh` with `P07_COMMIT_SHA` exported. Before Playwright, the runner must perform its bounded `start → export connection/seed manifest → dependent gate` lifecycle and assert discovery output contains `risk.spec.ts` with a parsed test count greater than zero; zero matches, missing fixture keys, unreachable Caddy/API readiness, or a mocked/intercepted request is a nonzero failure, never a skip. The journeys cover create/assess/control/treatment/action/review, read-only visibility, stale form recovery, authorized and denied Strategy/Project linkage, and keyboard-only heatmap/detail navigation.
- [ ] Retain the MySQL runner output and P07 manifest/Playwright report. Every declared scenario must pass on the same recorded commit; a skipped, zero-discovery, missing-fixture, SQLite-backed, or browser-mocked scenario is a failed gate.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

- **Authorization order:** validate only correlation/header syntax and route UUID shape, resolve the principal, then decide the capability against minimal Risk facts before detailed body validation or response disclosure. Object denial and absence use the same 404 body after the principal has the coarse capability.
- **Classification:** `RecordFacts` includes facility, organization unit, owner, resource type `risk`, classification, lifecycle, record ID, and lock version. List, heatmap, due, Contracts, and event payloads are authorization-filtered or data-minimized.
- **Idempotency:** canonicalize JSON recursively, excluding correlation and transport headers; hash the canonical body plus route IDs and action. A committed replay returns the stored response and ETag. An in-flight duplicate resolves under the unique constraint. Key reuse with another hash returns 409. Failed transactions leave no key.
- **Concurrency:** all mutable aggregates use integer `lock_version`. The version check and increment occur in the update predicate. A pre-read never substitutes for CAS. Child mutation locks/CASes the parent Risk so history order and `RiskChangedV1.lock_version` are total per risk.
- **Dependency failures:** M04/M05 unavailable or malformed responses fail closed with 503. No unresolved reference is stored. Existing opaque references remain readable as IDs if a producer is temporarily unavailable; title hydration is not persisted by Risk.
- **Outbox failure:** the entire command rolls back. Relay retry does not repeat the Risk command. Event consumers use inbox/event-ID dedupe.
- **Due retry:** a failed event append does not set the emitted marker. A successful retry emits the same logical `due_cycle` with a new event ID once; consumers group on `(event type, source ID, due_cycle)`.
- **Validation:** invalid scores, thresholds, cadence, lifecycle, terminal-state mutation, unresolved links, and close-with-open-actions return stable 409/422 problems without partial writes.
- **Privacy:** free text, evidence content, personal names, and producer titles do not enter URLs, browser storage, problem bodies, outbox payloads, or unsanitized logs. Logs contain correlation ID, operation, result code, and opaque IDs only.
- **Production binding:** `RiskServiceProvider` must throw during production boot if either producer Contract or RiskStore resolves to a test fake or null implementation.

## 11. Targeted verification commands and smoke scenarios

These commands are written for the future executor. They are not run while drafting this plan.

### Targeted commands

1. `cd apps/api && php artisan test Modules/Risk/Tests`
   - Expected: all M06 unit/HTTP/contract/atomicity tests pass; fake-backed `RiskReferenceIntegrationTest` output is unit/integration evidence only and is not production-binding proof.
2. `cd apps/api && APP_ENV=production php artisan test Modules/Risk/Tests/RiskProductionBindingTest.php --fail-on-warning`
   - Expected: the production container resolves exactly `DatabaseResolveStrategyReference`, `DatabaseResolveAuthorizedProjectReference`, and `DatabaseRiskStore`; no fake/null/no-op binding or test path is reachable; authorized M04/M05 references resolve and denied/out-of-scope consumer contexts return `null`.
3. `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
   - Expected: Risk rank 10, implemented inventory, exact eleven table owners, Contract/Event-only imports, and event schemas pass.
4. `make verify-mysql-integration`
   - Expected: exit 0 with the real MySQL runner explicitly executing `Modules\Risk\Tests\RiskMySqlConcurrencyTest` with a nonzero test/assertion count. Any SQLite driver, absent class, zero-test result, or `SKIP` line invalidates M06 verification.
5. `npm --prefix apps/web run test:unit -- src/features/risk`
   - Expected: all Risk component tests pass.
6. `npm --prefix apps/web run api:check`
   - Expected: OpenAPI lint passes and generated client has zero drift.
7. `npm --prefix apps/web run build`
   - Expected: TypeScript and Vite production build pass with the Risk route included.
8. `P07_TEST_MATCH=risk.spec.ts ./infra/platform/production/run-local-e2e.sh` with `P07_COMMIT_SHA` exported
   - Expected: P07 starts the bounded production topology, exports its connection/seed manifest, discovers `risk.spec.ts` with a test count greater than zero, runs every journey through Caddy against real services with the exact seeded fixture IDs/personas and no request interception, then stops via trap and proves zero remaining resources.
9. `cd apps/api && php artisan risk:dispatch-due --once --limit=100 && php artisan risk:dispatch-due --once --limit=100`
   - Expected: the first run reports the exact seeded review/action emission counts; the immediate second run reports zero review/action work and zero new outbox events for the same cycles.

### User-visible smoke scenarios

- **Risk manager:** sign in through the real session/CSRF flow, create `RISK-SMOKE-001`, assess likelihood 8 and impact 9 under a `20/50/75` policy, and observe score 72 and band High in list, detail, and heatmap.
- **Treatment owner:** add an implemented control, record a residual assessment, create a reduce treatment with two actions, complete both, verify the treatment, complete a review, and close the risk. The history shows every step in order.
- **Policy enforcement:** attempt to accept score 72 with acceptance maximum 20; observe a 409 policy conflict. Record an authorized score 20 assessment and accept successfully with `risk.accept`.
- **Producer linkage:** link one authorized Strategy objective and one authorized project; denied/nonexistent references yield identical non-disclosing 404s and no link/history/outbox change.
- **Concurrency:** open the same risk in two sessions, save from the first, then save from the second; the second sees a stale-data explanation and refresh action, and the first value remains.
- **Least privilege:** a read-only user sees list/detail/heatmap and no create, assess, accept, control, treatment, KRI, or edit controls. A user outside the scope cannot infer the risk through direct URL, workspace Contract, or cursor changes.
- **Due work:** advance the test clock past review/action due dates, dispatch once, observe due badges/queues and one event of each applicable type; dispatch again and observe no duplicate.
- **Accessibility:** traverse register, heatmap alternative, risk tabs, forms, error summary, stale recovery, and history using keyboard only at 200% zoom in RTL and LTR; focus order, names, state, errors, and non-color score labels remain perceivable.

## 12. Shared-file integration token requirements

M06 owns no shared file. Its integration record must use the orchestration ledger schema and the serialized module order `M01 → M02 → M03 → M04 → M05 → M06 → M07`.

1. **Current architecture-closure handoff gates:** obtain explicit release of `apps/api/tests/Architecture/ModuleBoundariesTest.php`, `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, and `apps/web/src/api/generated/cluster.ts`. Current Task 13 retains Make/CI ownership; M06 never requests `CLOSURE-CI`.
2. **`MODULE-REGISTRY`:** base on the merged M05 registry state; apply only M06 provider/migration/catalog/planned-inventory/table-owner/event-catalog changes. Include `AppServiceProvider.php`, `module_migrations.php`, architecture guard, module catalog, `OutboxEventType.php`, and the three exact event schemas in the token surface.
3. **`API-ROUTES`:** base on merged M05 routes and add only §7's Risk imports/routes/middleware.
4. **`OPENAPI` then `ORVAL`:** base on merged M05 contract, replace the generic planned Risk contract, lint, generate, prove zero drift, and release both together.
5. **`WEB-SHELL`:** base on merged M05 shell, add `/risk` capability/navigation only. Do not claim M07's final aggregation token.
6. A stale base revokes the token and requires rebase/retest; it is never resolved by parallel editing.
7. Every token record names token, state, requesting plan `M06`, releasing owner, full base commit, exact surfaces, grant evidence, and merge commit. The plan remains blocked if any required record is missing.

## 13. Rollback procedure

1. Stop new Risk mutations and `risk:dispatch-due`; allow an in-flight DB transaction to finish. Do not stop the shared outbox relay globally.
2. Record the failing commit, correlation IDs, migration state, outbox IDs, last successful due cycle, token merge commits, and row counts for all eleven Risk tables.
3. Roll application and web code back to the last verified integrated commit. Shared surfaces are reverted only by their current token owner in reverse order `WEB-SHELL → ORVAL/OPENAPI → API-ROUTES → MODULE-REGISTRY`; M06 does not edit them out of band.
4. Leave Risk tables intact in production so history, idempotency, and outbox references remain recoverable. Run migration `down()` only in an isolated environment after proving every Risk table is empty or after restoring a verified backup elsewhere.
5. If a migration partially failed, use Laravel's migration record plus table existence/constraint inspection to resume forward or drop only empty newly created tables in reverse §8 order. Never truncate assessments, reviews, readings, idempotency, or outbox rows to make a retry pass.
6. If producer bindings fail, disable Risk link mutations and keep existing opaque links readable; do not replace the bindings with fakes or accept unchecked IDs.
7. If an event schema/consumer is rejected, retain committed state and outbox rows, fix forward under the event-catalog token, and replay from the outbox. Consumer inbox dedupe prevents duplicate user effects.
8. Re-run the targeted API, MySQL, contract, web build, and browser commands before restoring traffic. Attach rollback and recovery output to §14's manifest.

## 14. Exit criteria and retained evidence

M06 may enter `completed` only when all statements are true on one recorded full commit SHA:

- M00's rank, exact eleven tables, capabilities, prefixes, Contracts, Events, and order are implemented without drift.
- All Risk APIs and `/risk` journeys in §7 and §11 work through real session/CSRF/capability middleware.
- Scoring, lifecycle, acceptance, review cadence, ownership, links, KRI, treatment/action, history, idempotency, CAS, and atomic outbox invariants pass.
- Production container proof resolves the exact M04/M05 database adapters and M06 database store under `APP_ENV=production`, rejects every fake/null/no-op/test binding or factory, and proves authorized versus denied consumer contexts through the real Contracts.
- All required shared tokens are merged/released and the current architecture-closure handoff evidence is retained.
- SQLite targeted tests, explicitly registered real MySQL/concurrency with nonzero class execution, boundary tests, OpenAPI/Orval drift, web unit/build, and the P07-started fixture-manifest-driven `risk.spec.ts` browser smoke with nonzero discovery pass; none is skipped.
- P05 has an evidence handoff for the stabilized `/risk` route; PHI/PII-sensitive fields are absent from URL, storage, events, problems, and logs.
- Rollback and due-dispatch retry are rehearsed in an isolated environment.
- `implementation_commit` and `last_verified_commit` are set only after the user authorizes recording a commit.

Retain `artifacts/verification/M06/<full-sha>/manifest.json` with this exact top-level shape:

```json
{
  "plan_id": "M06",
  "status": "verification",
  "commit": "<40-hex-sha>",
  "started_at": "<UTC RFC3339>",
  "finished_at": "<UTC RFC3339>",
  "commands": [
    {
      "command": "<exact command>",
      "exit_code": 0,
      "skipped": false,
      "stdout_path": "<relative artifact path>",
      "stderr_path": "<relative artifact path>"
    }
  ],
  "smoke_scenarios": [
    {"name": "<scenario name>", "result": "passed", "evidence_path": "<relative artifact path>"}
  ],
  "token_records": [
    {"token": "MODULE-REGISTRY", "state": "released", "base_commit": "<40-hex-sha>", "merge_commit": "<40-hex-sha>", "grant_evidence": "<relative artifact path>"}
  ],
  "migration_rehearsal": {"result": "passed", "evidence_path": "<relative artifact path>"},
  "rollback_rehearsal": {"result": "passed", "evidence_path": "<relative artifact path>"}
}
```

Angle-bracket values above describe runtime evidence fields, not implementation defaults; the manifest is invalid until populated with observed values. Retain raw stdout/stderr, PHPUnit/JUnit output, MySQL runner log, route list, OpenAPI lint/generation diff check, production build log, Playwright report/screenshots, accessibility notes, event schema validation, migration up/down rehearsal, token grants, and rollback rehearsal beneath the same SHA directory.

## 15. Status transition rules

- `blocked → ready`: M00 is approved, its M06 reservations match this plan, an executor/worktree and clean full base commit are recorded, and the architecture-closure plan has not asserted overlapping ownership of module-owned files.
- `ready → in_progress`: the executor records the status change and starts the first red test. Strategy/project production integration may remain a named blocked phase while independent Risk core work proceeds.
- `in_progress → blocked`: record the exact dependency, token, environment, or producer blocker plus last safe commit. If M04 or M05 is not merged, only the production link-binding phase is blocked; unrelated M06 tasks continue.
- `in_progress → verification`: every module-owned implementation task is green, real M04/M05 bindings replace test fakes, all required tokens are merged/released, and every shared surface has explicit handoff evidence.
- `verification → completed`: every §14 exit criterion passes on the same recorded commit, the evidence manifest is complete, and the user has authorized the commit record.
- `verification → in_progress`: any failed, skipped, stale, or mismatched check reopens implementation with its evidence; no narrowed rerun preserves verification status.
- `completed → in_progress`: any regression in score/lifecycle, authorization, CAS/idempotency, event schema, generated drift, producer binding, MySQL, browser, privacy, or accessibility reopens M06 and blocks M07 final integration.
- `* → superseded`: only a later user-approved plan may supersede M06; record the replacement path, dependency and token changes, and update the orchestration inventory in the same authorized change.
- A plan-file status edit alone proves nothing. Every transition requires the evidence named by `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md`; no commit is created without explicit user authorization.
