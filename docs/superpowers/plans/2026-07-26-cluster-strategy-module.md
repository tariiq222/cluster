# Cluster Strategy Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to execute this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: M04
status: blocked
depends_on:
  - M00
blocks:
  - M05:strategy-integration
  - M06:strategy-integration
  - M07:final-integration
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Approved design:** `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`

**Orchestration authority:** `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md`

**Goal:** Deliver a production-ready, module-owned Strategy capability for strategic periods, versioned plans, objectives, outcomes, indicators, targets, measurements, progress evidence, approval and publication, with authorized API and web experiences and stable contracts for PortfolioProjects, Risk, and Workspace.

**Architecture:** Strategy remains rank 8. Its controller flow is controller → boundary validation and capability decision → feature handler/application service → Strategy-owned persistence. Organization, Identity, Authorization, and RecordsGovernance are consumed only through lower-rank published contracts. Strategy has no PHP dependency on same-rank WorkRecords and no dependency on higher-rank PortfolioProjects, Risk, Reporting, or Workspace; downstream modules own their references to Strategy and consume the frozen Strategy Contracts/Events.

**Tech stack:** PHP 8.3+, Laravel 13.8, PHPUnit 12.5, MySQL and SQLite, React 19, TypeScript 6, Vite 8, Vitest 4, OpenAPI 3.1, Orval 8.22, transactional outbox, session/CSRF authentication.

---

## 1. Status header and dependency fields

The YAML header is normative and must not be widened during execution:

- `M00` is the only start dependency. M02, M05, and M06 are not start gates.
- M02 is an explicit blocked **final governance integration phase** after the module-owned core works.
- M05 and M06 may implement against deterministic test fakes from the M00 contract, but their production Strategy integration remains blocked until M04 is integrated.
- M07 final aggregation remains blocked until M04 is integrated.
- `shared_file_owner: []` means M04 owns no shared integration surface. Every shared edit listed in §12 requires the applicable serialized token.
- The current architecture-closure plan remains `in_progress` and retains its declared shared surfaces until explicit handoff. In particular, M04 may not edit `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, `apps/api/tests/Architecture/ModuleBoundariesTest.php`, `Makefile`, or either CI workflow before the named handoff/token.
- No commit, push, deployment, migration execution against a shared or production database, or status change is authorized by this plan. A commit is recorded only after explicit user authorization.

## 2. Goal and user-visible outcome

A permitted strategy manager can:

1. define a non-overlapping strategic period for an organization scope;
2. create a stable plan identity and a numbered draft version;
3. author weighted objectives, desired outcomes, indicators, reporting periods, and target distributions;
4. validate, submit, approve, return, publish, and retire a plan version under optimistic concurrency;
5. submit measurements and progress evidence and route evidence through approval;
6. view current plan status, outcome progress, indicator scorecards, and the immutable evidence trail;
7. revise a published plan by cloning it to a new draft version without mutating the published version.

A read-only user sees only authorized fields and scopes. A user without the required capability receives problem+json without resource disclosure. The web UI hides unavailable actions, preserves keyboard/focus behavior, supports Arabic and English copy, and does not store strategy payloads in browser persistence.

PortfolioProjects and Risk can validate a Strategy reference and react to published/retired/progress events without querying Strategy tables. Workspace can retrieve an authorized `StrategySnapshot` without copying Strategy data into a Workspace-owned cache.

## 3. Current source evidence

Fresh executors must re-read these exact sources before editing and retain the observations in their execution notes:

- `docs/architecture/module-catalog.md:15-33,183-210` fixes Strategy at rank 8, places WorkRecords at the same rank, and states that migrated tables require exact ownership.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php:16-52` already reserves `Strategy => 8` and lists Strategy as planned. The planned-module entry must be removed only when the runtime module is integrated through `MODULE-REGISTRY`.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php:101-107` already reserves exactly seven Strategy capabilities: `strategy.plan.read`, `strategy.plan.manage`, `strategy.indicator.read`, `strategy.indicator.manage`, `strategy.measurement.submit`, `strategy.measurement.approve`, and `strategy.impact.read`.
- `docs/contracts/api/openapi.yaml:4026-4288` contains a generic planned `/strategy/*` contract and generated client types. M04 replaces that planned contract only while holding `OPENAPI`; no parallel edit is allowed.
- `apps/api/Modules/Organization/Contracts/ResolveOrganizationScopeAncestry.php` and `ValidatePersonReference.php` are the lower-rank owner-reference validation surfaces. Strategy stores organization/person UUIDs as logical references and never creates cross-owner foreign keys.
- `apps/api/Modules/WorkRecords/Features/Lifecycle/Http/WorkRecordLifecycleController.php` demonstrates current correlation, session, `If-Match`, ETag, and problem+json conventions. It is a pattern to follow, not a dependency.
- `apps/api/Modules/Reporting/Features/RefreshReportingProjection/Handler/RefreshReportingProjectionHandler.php` is a rank-11 projection consumer that accepts source facts and strips unsafe fields. Strategy does not import Reporting or write Reporting tables; future Reporting consumption uses Strategy events.
- `apps/api/config/module_migrations.php` and `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` are shared flat registries. They require serialized module-registry integration.
- `apps/web/src/shell/routes.ts`, `apps/web/src/shell/navigation.tsx`, and `apps/web/src/app/WorkspaceContent.tsx` are shared shell registries. They require `WEB-SHELL` and must not be edited in a module worktree without the token.
- No `apps/api/Modules/Strategy/` directory exists before M04 execution. A directory created before M00 approval would violate the current planned-module guard.

The architecture-closure plan's handoff gates remain mandatory:

- Task 4 handoff for exact architecture guards;
- Task 6 handoff for ownership/contracts;
- Task 7 handoff for HTTP primitives;
- Task 12 handoff for route/collection contracts;
- Task 13 handoff before final CI/Make ownership moves to P08.

## 4. Scope and explicit non-goals

### In scope

- Strategy-owned domain, handlers, adapters, migrations, contracts, events, controllers, provider, tests, and web feature directory.
- Strategic periods; stable plans; immutable numbered plan versions; objectives; outcomes; indicators; indicator periods; target distributions; measurements; progress evidence; approval decisions.
- API and web flows under `/api/v1/strategy` and `/strategy`.
- Authorization, idempotency, concurrency, correlation IDs, problem+json, cursor pagination, transactional outbox, M02 governance registration, event schemas, and retained evidence.
- One-way published interfaces for M05, M06, and M07.
- Serialized application of the M00 rank/table decisions and shared route/contract/shell integration.

### Non-goals

- PortfolioProjects or Risk aggregates, link tables, controllers, persistence, UI, policy, or projections.
- Workspace aggregation internals or Reporting projections.
- Any direct Strategy ↔ WorkRecords import, SQL query, foreign key, or runtime adapter. Because both are rank 8, even a same-rank `Contracts/` or `Events/` PHP import is prohibited. Strategy progress evidence is Strategy-owned and manually/API submitted; a future work-record-derived projection requires an approved rank/architecture change, not an ad hoc dependency.
- RecordsGovernance internals. M04 consumes M02 contracts only after its blocked integration gate.
- New capability codes. Objectives/outcomes/periods use plan capabilities; targets/indicator periods use indicator capabilities; evidence uses measurement capabilities; snapshots/impact views use `strategy.impact.read`.
- Hand editing `apps/web/src/api/generated/cluster.ts`.
- M04 ownership of shared registries, master OpenAPI, web shell, `Makefile`, or CI workflows.
- Production fakes, no-op adapters, nonfunctional routes, incomplete scaffolds, or compatibility aliases.

## 5. Architecture and ownership boundaries

### Aggregate boundaries

- `StrategyPeriod` owns a strategy time window and scope.
- `StrategyPlan` is the stable identity (`code`, scope, period) across versions.
- `StrategyPlanVersion` is the approval aggregate. It owns its objective/outcome/indicator graph and lifecycle.
- `StrategyIndicatorPeriod` and `StrategyTargetDistribution` define planned measurement windows and targets for an indicator in one plan version.
- `StrategyMeasurement` records an observed value.
- `StrategyProgressEvidence` records a point-in-time outcome status and evidence, with its own approval lifecycle.
- `StrategyApproval` is an append-only decision record for plan-version and progress-evidence approvals.

### Dependency direction

Allowed imports from Strategy are limited to lower-rank public surfaces:

- `Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal` for the current session resolver pattern until the closure plan's principal contract handoff is applied;
- `Modules\Authorization\Contracts\DecideAccess`, `RecordFacts`, and `AccessProjection`;
- `Modules\Organization\Contracts\ResolveOrganizationScopeAncestry` and `ValidatePersonReference`;
- after M02 is ready, `Modules\RecordsGovernance\Contracts\RegisterGovernedRecord`, `GovernedRecordRegistration`, `GovernedRecordStatus`, and `RecordSourceReference`;
- `Shared\Contracts\TransactionalOutbox` and shared HTTP primitives.

Forbidden imports include every `Modules\WorkRecords\*`, `Modules\PortfolioProjects\*`, `Modules\Risk\*`, `Modules\Reporting\*`, and `Modules\Workspace\*` namespace.

Downstream direction is one way:

- M05/M06 own any link from their aggregate to `StrategyResourceType + strategy_resource_id`; they call `ResolveStrategyReference` and never query Strategy tables.
- M07 calls `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null)` at request time, supplies an authenticated `StrategyAccessContext` it has already constructed, and never persists the returned facts. Workspace preferences never substitute for `StrategyAccessContext` and Workspace never reconstructs authorization from a raw organization unit ID.
- Reporting/Search may consume outbox schemas in later owner plans. M04 does not write their read models.

### Required transaction boundary

Each command transaction includes the state change, idempotency record, approval record when applicable, M02 registration result when the phase requires it, and outbox append. A failure rolls back all effects. Production bindings fail closed; only module tests may bind deterministic fakes.

## 6. Files to create, modify, move, or remove

### Module-owned API files to create

```text
apps/api/Modules/Strategy/Contracts/StrategyResourceType.php
apps/api/Modules/Strategy/Contracts/StrategyReference.php
apps/api/Modules/Strategy/Contracts/ResolveStrategyReference.php
apps/api/Modules/Strategy/Contracts/StrategySnapshot.php
apps/api/Modules/Strategy/Contracts/GetStrategySnapshot.php
apps/api/Modules/Strategy/Contracts/StrategyAccessContext.php
apps/api/Modules/Strategy/Events/StrategyPlanPublishedV1.php
apps/api/Modules/Strategy/Events/StrategyPlanRetiredV1.php
apps/api/Modules/Strategy/Events/StrategyProgressEvidenceApprovedV1.php
apps/api/Modules/Strategy/Domain/StrategyPeriod.php
apps/api/Modules/Strategy/Domain/StrategyPlanVersion.php
apps/api/Modules/Strategy/Domain/StrategyObjective.php
apps/api/Modules/Strategy/Domain/StrategyOutcome.php
apps/api/Modules/Strategy/Domain/StrategyIndicator.php
apps/api/Modules/Strategy/Domain/StrategyProgressEvidence.php
apps/api/Modules/Strategy/Features/ManagePeriods/Handler/StrategyPeriodHandler.php
apps/api/Modules/Strategy/Features/ManagePeriods/Http/StrategyPeriodController.php
apps/api/Modules/Strategy/Features/ManagePlans/Handler/StrategyPlanHandler.php
apps/api/Modules/Strategy/Features/ManagePlans/Http/StrategyPlanController.php
apps/api/Modules/Strategy/Features/ManagePlanVersions/Handler/StrategyPlanVersionHandler.php
apps/api/Modules/Strategy/Features/ManagePlanVersions/Http/StrategyPlanVersionController.php
apps/api/Modules/Strategy/Features/ManagePlanStructure/Handler/StrategyStructureHandler.php
apps/api/Modules/Strategy/Features/ManagePlanStructure/Http/StrategyStructureController.php
apps/api/Modules/Strategy/Features/MeasureProgress/Handler/StrategyMeasurementHandler.php
apps/api/Modules/Strategy/Features/MeasureProgress/Http/StrategyMeasurementController.php
apps/api/Modules/Strategy/Features/ReviewProgress/Handler/StrategyProgressEvidenceHandler.php
apps/api/Modules/Strategy/Features/ReviewProgress/Http/StrategyProgressEvidenceController.php
apps/api/Modules/Strategy/Features/ViewScorecard/Handler/StrategyScorecardHandler.php
apps/api/Modules/Strategy/Features/ViewScorecard/Http/StrategyScorecardController.php
apps/api/Modules/Strategy/Infrastructure/Persistence/DatabaseResolveStrategyReference.php
apps/api/Modules/Strategy/Infrastructure/Persistence/DatabaseGetStrategySnapshot.php
apps/api/Modules/Strategy/Infrastructure/Persistence/Migrations/CreateStrategyPlanningTables.php
apps/api/Modules/Strategy/Infrastructure/Persistence/Migrations/CreateStrategyMeasurementTables.php
apps/api/Modules/Strategy/Infrastructure/Persistence/Migrations/CreateStrategyReliabilityTables.php
apps/api/Modules/Strategy/Providers/StrategyServiceProvider.php
apps/api/Modules/Strategy/Tests/StrategyDomainTest.php
apps/api/Modules/Strategy/Tests/StrategyPersistenceTest.php
apps/api/Modules/Strategy/Tests/StrategyHttpAdapterTest.php
apps/api/Modules/Strategy/Tests/StrategyContractsTest.php
apps/api/Modules/Strategy/Tests/StrategyGovernanceIntegrationTest.php
apps/api/Modules/Strategy/Tests/StrategyMySqlConcurrencyTest.php
apps/api/Modules/Strategy/Tests/StrategySnapshotAuthorizationTest.php
```

### Module-owned web files to create

```text
apps/web/src/features/strategy/strategy-api.ts
apps/web/src/features/strategy/strategy-api.test.ts
apps/web/src/features/strategy/strategy-problem.ts
apps/web/src/features/strategy/strategy-problem.test.ts
apps/web/src/features/strategy/StrategyWorkspace.tsx
apps/web/src/features/strategy/StrategyWorkspace.test.tsx
apps/web/src/features/strategy/StrategyPlanEditor.tsx
apps/web/src/features/strategy/StrategyPlanEditor.test.tsx
apps/web/src/features/strategy/StrategyScorecard.tsx
apps/web/src/features/strategy/StrategyScorecard.test.tsx
apps/web/src/features/strategy/StrategyMeasurementsReview.tsx
apps/web/src/features/strategy/StrategyMeasurementsReview.test.tsx
apps/web/src/features/strategy/index.ts
```

### Contract/evidence files to create during integration

```text
docs/contracts/schemas/com-cluster-strategy-strategyplanpublished-v1.schema.json
docs/contracts/schemas/com-cluster-strategy-strategyplanretired-v1.schema.json
docs/contracts/schemas/com-cluster-strategy-strategyprogressevidenceapproved-v1.schema.json
docs/architecture/evidence/M04/manifest.yaml
```

### Shared files to modify only under serialized tokens

```text
apps/api/tests/Architecture/ModuleBoundariesTest.php
apps/api/config/module_migrations.php
apps/api/app/Providers/AppServiceProvider.php
apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php
apps/api/phpunit.mysql.xml
apps/api/routes/web.php
docs/architecture/module-catalog.md
docs/contracts/api/openapi.yaml
apps/web/src/api/generated/cluster.ts
apps/web/src/shell/routes.ts
apps/web/src/shell/navigation.tsx
apps/web/src/shell/navigation.test.tsx
apps/web/src/shell/routes.capabilities.test.ts
apps/web/src/app/WorkspaceContent.tsx
apps/web/src/app/AppWorkspace.navigation.test.tsx
apps/web/src/app/copy.ts
```

No file is moved or removed. `apps/web/src/api/generated/cluster.ts` is listed as generated output and may only change through `npm --prefix apps/web run api:generate` while holding `OPENAPI`/`ORVAL`.

## 7. Public Contracts, Events, routes, schemas, and capability names

### Frozen Contracts

```php
namespace Modules\Strategy\Contracts;

enum StrategyResourceType: string
{
    case Period = 'period';
    case Plan = 'plan';
    case PlanVersion = 'plan_version';
    case Objective = 'objective';
    case Outcome = 'outcome';
    case Indicator = 'indicator';
}

final readonly class StrategyReference
{
    public function __construct(
        public StrategyResourceType $resourceType,
        public string $resourceId,
        public string $planId,
        public string $planVersionId,
        public string $code,
        public string $title,
        public string $ownerOrganizationUnitId,
        public string $status,
        public string $classification,
        public int $lockVersion,
        public ?string $parentResourceId = null,
        public ?int $progressBasisPoints = null,
    ) {}
}

final readonly class StrategyAccessContext
{
    /**
     * @param list<string> $organizationUnitIds
     */
    public function __construct(
        public string $principalId,
        public string $facilityId,
        public array $organizationUnitIds,
        public string $correlationId,
    ) {}
}

interface ResolveStrategyReference
{
    public function resolve(StrategyAccessContext $context, StrategyResourceType $type, string $id): ?StrategyReference;
}

final readonly class StrategySnapshot
{
    /**
     * @param list<array{id:string,code:string,title:string,status:string,published_version_id:string,period_id:string,classification:string}> $plans
     * @param list<array{id:string,plan_id:string,title:string,status:string,progress_basis_points:int}> $objectives
     * @param list<array{id:string,objective_id:string,title:string,status:string,progress_basis_points:int}> $outcomes
     * @param list<array{id:string,outcome_id:string,code:string,name:string,latest_value:int|float|null,target_value:int|float|null,unit:string}> $indicators
     */
    public function __construct(
        public string $organizationUnitId,
        public ?string $periodId,
        public array $plans,
        public array $objectives,
        public array $outcomes,
        public array $indicators,
        public string $generatedAt,
    ) {}
}

interface GetStrategySnapshot
{
    public function forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot;
}
```

Contract behavior:

- `ResolveStrategyReference` accepts `StrategyAccessContext` first and returns only published or retired resource facts; draft/submitted/returned versions resolve to `null` for cross-module consumers.
- A missing, unauthorized, wrong-scope, draft/non-published, or wrong-type reference is indistinguishable through the public contract and always returns `null` without disclosure.
- `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null)` is the frozen producer-side authorization contract: the implementation decides authorization against `$context` before any published fact leaves the module.
- `StrategyAccessContext` is the canonical, public authorization DTO. It is constructed by callers (M07 in production; tests via deterministic fakes) and is the only input from which record-level authorization is derived.
- Contracts contain DTO facts only and never expose Eloquent models, query builders, internal domain objects, raw evidence text, the principal's full capability set, or any field outside the published snapshot shape.

### Frozen Events

| Event class | Exact event type | Trigger | Required `data` fields |
|---|---|---|---|
| `StrategyPlanPublishedV1` | `com.cluster.strategy.strategyplanpublished.v1` | a plan version becomes published | `plan_id`, `plan_version_id`, `period_id`, `owner_organization_unit_id`, `version_number`, `classification`, `published_at` |
| `StrategyPlanRetiredV1` | `com.cluster.strategy.strategyplanretired.v1` | a published plan version becomes retired | `plan_id`, `plan_version_id`, `owner_organization_unit_id`, `retired_at`, `reason_code` |
| `StrategyProgressEvidenceApprovedV1` | `com.cluster.strategy.strategyprogressevidenceapproved.v1` | evidence becomes approved and affects current progress | `evidence_id`, `plan_id`, `plan_version_id`, `objective_id`, `outcome_id`, `progress_basis_points`, `as_of_date`, `approved_at` |

Each event is a typed immutable class whose `toPayload(): array` produces only the listed fields. Narrative evidence, person names, free-form notes, CSRF/session data, and idempotency keys are prohibited from event payloads. Each literal is added to `OutboxEventType` and gets the exact Draft 2020-12 schema path in §6.

### HTTP route contract

All routes sit under `/api/v1/strategy`, use session authentication and CSRF for mutations, accept/return `X-Correlation-ID`, return problem+json failures, and use UUIDv7 path identifiers.

| Method and path | Capability | Command semantics |
|---|---|---|
| `GET /strategy/periods` | `strategy.plan.read` | cursor page authorized periods |
| `POST /strategy/periods` | `strategy.plan.manage` | `Idempotency-Key`; create |
| `GET /strategy/periods/{periodId}` | `strategy.plan.read` | entity + ETag |
| `PATCH /strategy/periods/{periodId}` | `strategy.plan.manage` | `If-Match` + `Idempotency-Key`; draft-only |
| `GET /strategy/plans` | `strategy.plan.read` | cursor page, filters `period_id`, `status`, `owner_organization_unit_id` |
| `POST /strategy/plans` | `strategy.plan.manage` | create plan and version 1 draft atomically |
| `GET /strategy/plans/{planId}` | `strategy.plan.read` | plan and authorized versions |
| `POST /strategy/plans/{planId}/versions` | `strategy.plan.manage` | clone latest published version to next draft |
| `GET /strategy/plan-versions/{versionId}` | `strategy.plan.read` | version graph + ETag |
| `PATCH /strategy/plan-versions/{versionId}` | `strategy.plan.manage` | draft/returned metadata only |
| `POST /strategy/plan-versions/{versionId}/{action}` | `strategy.plan.manage` | action is `validate`, `submit`, `approve`, `return`, `publish`, or `retire`; ETag + idempotency |
| `POST /strategy/plan-versions/{versionId}/objectives` | `strategy.plan.manage` | create objective |
| `PATCH /strategy/objectives/{objectiveId}` | `strategy.plan.manage` | draft/returned objective only |
| `POST /strategy/objectives/{objectiveId}/outcomes` | `strategy.plan.manage` | create outcome |
| `PATCH /strategy/outcomes/{outcomeId}` | `strategy.plan.manage` | draft/returned outcome only |
| `POST /strategy/outcomes/{outcomeId}/indicators` | `strategy.indicator.manage` | create indicator |
| `PATCH /strategy/indicators/{indicatorId}` | `strategy.indicator.manage` | draft/returned indicator only |
| `POST /strategy/indicators/{indicatorId}/periods` | `strategy.indicator.manage` | create measurement period and target distribution |
| `GET /strategy/indicators/{indicatorId}/scorecard` | `strategy.indicator.read` | authorized target/actual series |
| `GET /strategy/measurements/pending` | `strategy.measurement.submit` | cursor page of due measurements |
| `POST /strategy/indicator-periods/{indicatorPeriodId}/measurements` | `strategy.measurement.submit` | create measurement and evidence draft |
| `GET /strategy/progress-evidence` | `strategy.impact.read` | cursor page, authorized scope |
| `POST /strategy/progress-evidence/{evidenceId}/{action}` | `strategy.measurement.approve` | action is `submit`, `approve`, or `return` |
| `GET /strategy/impact` | `strategy.impact.read` | authorized `StrategySnapshot` |

The authoritative OpenAPI path prefix is `/strategy`. Replace the generic planned Strategy paths; do not retain a second generic controller beside the explicit routes. Every collection schema is `{items: [...], next_cursor: string|null}` and every mutation declares 400/401/403/404/409/412 problem responses where applicable.

### Web routes

- `/strategy` → overview and current period plans;
- `/strategy/plans/{planId}` → plan/version editor or read-only published view;
- `/strategy/measurements` → pending measurements and evidence review.

`/strategy` is gated by any of `strategy.plan.read`, `strategy.indicator.read`, or `strategy.impact.read`; detail actions are gated by the exact server capability above. `/strategy/measurements` is gated by either measurement capability. Route parsing accepts only UUIDv7 plan IDs and otherwise returns the existing not-found route.

## 8. Database tables, indexes, constraints, migration order, and recovery

M00 freezes exactly these Strategy-owned tables; M04 may not add an unregistered table:

1. `strategy_periods`
2. `strategy_plans`
3. `strategy_plan_versions`
4. `strategy_objectives`
5. `strategy_outcomes`
6. `strategy_indicators`
7. `strategy_indicator_periods`
8. `strategy_target_distributions`
9. `strategy_measurements`
10. `strategy_progress_evidence`
11. `strategy_approvals`
12. `strategy_idempotency_keys`

### Migration 1 — `CreateStrategyPlanningTables.php`

- `strategy_periods`: UUID PK, `code`, `name`, logical `owner_organization_unit_id`, `start_on`, `end_on`, `status` (`draft|active|closed`), `classification`, `lock_version`, timestamps. Unique `(owner_organization_unit_id, code)`; index `(owner_organization_unit_id,status,start_on,end_on)`; checks `start_on <= end_on`, `lock_version >= 1`.
- `strategy_plans`: UUID PK, `period_id` FK to Strategy period, `code`, `name`, logical owner unit/person IDs, `classification`, `status` (`draft|active|retired`), `lock_version`, timestamps. Unique `(owner_organization_unit_id,period_id,code)`; indexes `(period_id,status)` and `(owner_organization_unit_id,status)`.
- `strategy_plan_versions`: UUID PK, `plan_id` FK, positive `version_number`, lifecycle `draft|submitted|returned|approved|published|retired`, `title`, `purpose`, `lock_version`, submitted/approved/published/retired timestamps and actor UUIDs, return/retire reason code, timestamps. Unique `(plan_id,version_number)`; at most one draft-like (`draft|returned`) version enforced by handler under locked plan row; index `(plan_id,status)`.
- `strategy_objectives`: UUID PK, `plan_version_id` FK, `code`, `title`, `description`, `weight_basis_points`, logical owner IDs, `sort_order`, `lock_version`, timestamps. Unique `(plan_version_id,code)` and `(plan_version_id,sort_order)`; checks weight `1..10000` and lock version.
- `strategy_outcomes`: UUID PK, `objective_id` FK, `code`, `title`, `desired_state`, `progress_basis_points`, logical owner IDs, `sort_order`, `lock_version`, timestamps. Unique `(objective_id,code)` and `(objective_id,sort_order)`; progress check `0..10000`.
- `strategy_indicators`: UUID PK, `outcome_id` FK, `code`, `name`, `unit`, `direction` (`increase|decrease|maintain`), `baseline_value` decimal(20,6), `target_value` decimal(20,6), `lock_version`, timestamps. Unique `(outcome_id,code)`; index `(outcome_id,direction)`.

All foreign keys above are between Strategy-owned tables and use restrictive deletion. Organization/person identifiers are logical UUID references without database foreign keys.

### Migration 2 — `CreateStrategyMeasurementTables.php`

- `strategy_indicator_periods`: UUID PK, `indicator_id` FK, `period_start`, `period_end`, `due_on`, `status` (`planned|open|submitted|approved|closed`), `lock_version`, timestamps. Unique `(indicator_id,period_start,period_end)`; checks start ≤ end and due date ≥ end.
- `strategy_target_distributions`: UUID PK, `indicator_period_id` FK, `target_value` decimal(20,6), `weight_basis_points`, timestamps. Unique `indicator_period_id`; weight check `1..10000`.
- `strategy_measurements`: UUID PK, `indicator_period_id` FK, `measured_value` decimal(20,6), `measured_at`, `submitted_by_user_id`, `source_type` (`manual|document|external_reference`), nullable opaque `source_reference`, `lock_version`, timestamps. Unique `(indicator_period_id,measured_at,submitted_by_user_id)`; indexes `(indicator_period_id,measured_at)` and `(submitted_by_user_id,measured_at)`.
- `strategy_progress_evidence`: UUID PK, `measurement_id` nullable FK, `plan_version_id`, `objective_id`, `outcome_id` Strategy FKs, `as_of_date`, `progress_basis_points`, `status` (`draft|submitted|returned|approved`), `summary`, `source_type`, nullable opaque `source_reference`, submit/approval actors and timestamps, `lock_version`, timestamps. Indexes `(outcome_id,status,as_of_date)` and `(plan_version_id,status)`; progress and lock checks.
- `strategy_approvals`: UUID PK, `subject_type` (`plan_version|progress_evidence`), `subject_id`, `decision` (`approve|return`), `decision_by_user_id`, `reason_code`, nullable bounded `comment`, `subject_lock_version`, `decided_at`, timestamps. Index `(subject_type,subject_id,decided_at)`; no polymorphic cross-module reference.

### Migration 3 — `CreateStrategyReliabilityTables.php`

- `strategy_idempotency_keys`: bigint PK, `principal_id`, `organization_unit_id`, `operation`, SHA-256 `idempotency_key_hash`, SHA-256 `request_hash`, `response_status`, JSON `response_body`, nullable `resource_id`, timestamps. Unique `(principal_id,organization_unit_id,operation,idempotency_key_hash)`; index `resource_id`.

Outbox rows use the existing shared `outbox_events`; Strategy must not create another outbox table.

### Measurable domain invariants

- Period dates are inclusive and ordered. Two `active` periods for the same owner unit may not overlap; the handler locks the owner scope key and tests the overlap predicate in MySQL.
- One plan code exists per `(owner unit, period)`. Exactly one mutable draft-like version exists per plan.
- Version numbers start at 1 and increase by one while the plan row is locked.
- Only `draft|returned` graphs are editable. Submitted/approved/published/retired graphs are immutable.
- Plan lifecycle is `draft|returned → submitted → approved → published → retired`; `submitted → returned` is the only return edge. Validation never mutates lifecycle.
- A version cannot submit unless it has at least one objective, every objective has at least one outcome, every outcome has at least one indicator, objective weights sum to exactly 10000, and every indicator has at least one period with a target.
- An `increase` indicator requires target ≥ baseline; `decrease` requires target ≤ baseline; `maintain` requires a non-negative tolerance represented in the domain input and persisted in indicator metadata only if M00 later approves a column amendment. Until such an amendment, maintain indicators require target = baseline.
- Progress is stored as integer basis points `0..10000`; no floating percentage is persisted.
- An approved measurement/evidence update recomputes outcome/objective progress in the same transaction using target-distribution weights; unapproved evidence has no effect.
- Published versions are immutable. Revision clones produce new UUIDs and next version number while retaining stable plan ID.
- Cross-module IDs are never database FKs. A Strategy command rejects an unresolved Organization owner with 404 after authorization.
- Every state mutation increments `lock_version` using `WHERE id = ? AND lock_version = ?`; affected-row count zero returns 412.
- Every event and approval is committed atomically with state/idempotency.

### Rollback and recovery order

`down()` drops reliability, then measurement, then planning tables in reverse FK order. Before rollback on an environment with data, export all twelve Strategy tables plus relevant `outbox_events` by Strategy event type, verify row counts and checksums, and stop Strategy writes. A rollback that would orphan downstream M05/M06 references is prohibited; retire or migrate those references first. Restore uses migrations followed by the table export and outbox replay from the last verified event ID.

## 9. TDD tasks with red/green steps

### Task 1 — Freeze contracts and domain invariants

**Files:** Create the five `Contracts/` files, six `Domain/` files, and `StrategyDomainTest.php` listed in §6.

- [ ] **Step 1: Write failing lifecycle and weighting tests.**

```php
public function test_plan_version_requires_a_complete_weighted_graph_before_submission(): void
{
    $version = StrategyPlanVersion::draft('019f8000-0000-7000-8000-000000000101', 1);
    $version->addObjective(new StrategyObjective('OBJ-1', 'Access', 6000));
    $version->addObjective(new StrategyObjective('OBJ-2', 'Quality', 3000));

    $this->expectExceptionMessage('objective_weights_must_total_10000');
    $version->assertSubmittable();
}

public function test_published_plan_graph_is_immutable(): void
{
    $version = $this->completeApprovedVersion();
    $version->publish('019f8000-0000-7000-8000-000000000102');

    $this->expectExceptionMessage('published_strategy_version_is_immutable');
    $version->rename('Changed');
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyDomainTest.php`.** Expected: FAIL because Strategy domain classes do not exist.
- [ ] **Step 3: Implement the exact lifecycle, graph-completeness, weight, progress, direction, and immutability rules from §8 in pure domain classes.** Domain classes contain no `DB`, HTTP, or other module imports.
- [ ] **Step 4: Re-run the Task 1 command.** Expected: PASS with no skipped test.

### Task 2 — Create Strategy-owned schema and persistence contracts

**Files:** Create the three migration files, two persistence adapters, `StrategyPersistenceTest.php`, and `StrategyServiceProvider.php`.

- [ ] **Step 1: Write a failing migration/persistence test that asserts all twelve tables, exact indexes/uniques/checks, no cross-owner FKs, reference resolution, cursor stability, and published-only snapshots.**

```php
public function test_reference_contract_conceals_all_non_authorized_or_non_published_cases(): void
{
    $organizationUnitId = '019f8000-0000-7000-8000-000000000001';
    $otherUnitId = '019f8000-0000-7000-8000-000000000002';
    $published = $this->seedVersion('published', organizationUnitId: $organizationUnitId);
    $draft = $this->seedVersion('draft', organizationUnitId: $organizationUnitId);
    $wrongScope = $this->seedVersion('published', organizationUnitId: $otherUnitId);
    $context = StrategyAccessContextFactory::create(organizationUnitIds: [$organizationUnitId]);
    $unauthorizedContext = StrategyAccessContextFactory::create(principalId: '019f8000-0000-7000-8000-000000000099', facilityId: '019f8000-0000-7000-8000-000000000098', organizationUnitIds: [$organizationUnitId]);
    $resolver = app(ResolveStrategyReference::class);

    self::assertNull($resolver->resolve($context, StrategyResourceType::PlanVersion, '019f8000-0000-7000-8000-00000000dead')); // missing
    self::assertNull($resolver->resolve($unauthorizedContext, StrategyResourceType::PlanVersion, $published)); // unauthorized
    self::assertNull($resolver->resolve($context, StrategyResourceType::PlanVersion, $wrongScope)); // wrong scope
    self::assertNull($resolver->resolve($context, StrategyResourceType::PlanVersion, $draft)); // draft/non-published
    self::assertNull($resolver->resolve($context, StrategyResourceType::Indicator, $published)); // wrong type
    self::assertSame($published, $resolver->resolve($context, StrategyResourceType::PlanVersion, $published)?->resourceId);
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyPersistenceTest.php`.** Expected: FAIL because migrations and bindings are absent.
- [ ] **Step 3: Implement migrations in the order in §8 and bind `ResolveStrategyReference`/`GetStrategySnapshot` to database adapters in `StrategyServiceProvider::register()`.** Queries select explicit columns, order by `(created_at,id)`, and encode/decode an opaque base64url cursor containing both values.
- [ ] **Step 4: Re-run the Task 2 command.** Expected: PASS, twelve Strategy tables present, no external FK, and no draft facts in public contracts.

### Task 3 — Period and plan/version commands

**Files:** Create `ManagePeriods/*`, `ManagePlans/*`, `ManagePlanVersions/*`, and extend `StrategyHttpAdapterTest.php`.

- [ ] **Step 1: Write failing feature tests for authorization-before-validation, create/replay/conflict, ETag, stale update, lifecycle transitions, and immutable revision cloning.**

```php
public function test_plan_creation_replays_once_and_rejects_key_reuse_with_a_different_body(): void
{
    $headers = $this->strategyHeaders('strategy-create-1');
    $first = $this->postJson('/api/v1/strategy/plans', $this->validPlan(), $headers)->assertCreated();
    $second = $this->postJson('/api/v1/strategy/plans', $this->validPlan(), $headers)->assertCreated();
    self::assertSame($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('strategy_plans', 1);
    $this->assertDatabaseCount('strategy_idempotency_keys', 1);

    $this->postJson('/api/v1/strategy/plans', [...$this->validPlan(), 'code' => 'OTHER'], $headers)
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json');
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyHttpAdapterTest.php --filter='period|plan|version'`.** Expected: FAIL because handlers/controllers/routes are absent.
- [ ] **Step 3: Implement handlers with `DB::transaction`, locked aggregate rows, SHA-256 request hashes, `WHERE lock_version` updates, and outbox append for publish/retire.** Controllers evaluate the route capability before detailed validation or lookup and use shared HTTP support for 400/401/403/404/409/412.
- [ ] **Step 4: Re-run the Task 3 command.** Expected: PASS; replay bodies are identical, stale updates are 412, and publish/retire each create exactly one outbox row.

### Task 4 — Objective, outcome, indicator, target, measurement, and evidence flows

**Files:** Create `ManagePlanStructure/*`, `MeasureProgress/*`, `ReviewProgress/*`, `ViewScorecard/*`; extend `StrategyHttpAdapterTest.php`.

- [ ] **Step 1: Write failing tests for draft-only graph edits, indicator target rules, pending-measurement pagination, evidence approval, weighted progress, and field masking.**

```php
public function test_only_approved_evidence_changes_weighted_progress(): void
{
    $ids = $this->seedPublishedWeightedPlan();
    $evidence = $this->submitEvidence($ids['first_indicator_period'], 75.0, 7500);
    $this->assertDatabaseHas('strategy_outcomes', ['id' => $ids['outcome'], 'progress_basis_points' => 0]);

    $this->approveEvidence($evidence, 1);
    $this->assertDatabaseHas('strategy_outcomes', ['id' => $ids['outcome'], 'progress_basis_points' => 7500]);
    $this->assertDatabaseHas('strategy_progress_evidence', ['id' => $evidence, 'status' => 'approved']);
    $this->assertSame(
        1,
        DB::table('outbox_events')
            ->where('event_type', 'com.cluster.strategy.strategyprogressevidenceapproved.v1')
            ->count(),
    );
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyHttpAdapterTest.php --filter='objective|outcome|indicator|measurement|evidence|scorecard'`.** Expected: FAIL because structure/progress handlers are absent.
- [ ] **Step 3: Implement the handlers and controllers.** Use decimal strings at the database boundary, integer basis points for progress, deterministic weighted aggregation, access projections before serialization, and cursor pagination ordered by `(due_on,id)` or `(as_of_date,id)`.
- [ ] **Step 4: Re-run the Task 4 command.** Expected: PASS; no draft measurement affects progress, approved evidence emits one event, and hidden fields are absent rather than null.

### Task 5 — Publish cross-module contracts and typed events

**Files:** Create the five Contract files, three Event files, three JSON schemas, and `StrategyContractsTest.php`; integrate the shared event enum only under `MODULE-REGISTRY`.

- [ ] **Step 1: Write failing contract tests for published-only resolution, snapshot shape, event payload allowlists, and schema parity.**

```php
public function test_progress_event_does_not_leak_narrative_or_actor_details(): void
{
    $event = new StrategyProgressEvidenceApprovedV1(
        evidenceId: self::EVIDENCE,
        planId: self::PLAN,
        planVersionId: self::VERSION,
        objectiveId: self::OBJECTIVE,
        outcomeId: self::OUTCOME,
        progressBasisPoints: 6400,
        asOfDate: '2026-07-26',
        approvedAt: '2026-07-26T12:00:00.000Z',
    );

    self::assertSame([
        'evidence_id', 'plan_id', 'plan_version_id', 'objective_id', 'outcome_id',
        'progress_basis_points', 'as_of_date', 'approved_at',
    ], array_keys($event->toPayload()));
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyContractsTest.php`.** Expected: FAIL because classes/schemas/enum cases are absent.
- [ ] **Step 3: Implement contracts/events exactly as §7, add the three enum literals and Draft 2020-12 schemas during the registry token, and ensure schema descriptions identify the producing Strategy handler.**
- [ ] **Step 4: Re-run the Task 5 command and `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php --filter=outbox_event`.** Expected: PASS and every produced event type resolves to one schema.

### Task 6 — Producer-side authorization and non-disclosure for `GetStrategySnapshot`

**Start gate:** Task 5 frozen Contracts compile and `StrategyAccessContext` is constructible in tests. Deterministic test fakes for `StrategyAccessContext` live under `Modules\Strategy\Tests\Support\ContextFixtures`; no production binding may resolve to a fake.

**Files:** Create `Modules\Strategy\Tests\StrategySnapshotAuthorizationTest.php`, the `StrategyAccessContext` invariant test in `StrategyContractsTest.php`, and the matching HTTP route-coverage entry in `StrategyHttpAdapterTest.php`.

- [ ] **Step 1: Write failing tests that prove the frozen signature is the only callable surface and that authorization/non-disclosure is enforced before any published fact leaves the module.**

```php
public function test_for_organization_unit_rejects_a_context_missing_the_target_unit(): void
{
    $context = StrategyAccessContextFactory::create(
        organizationUnitIds: ['019f8000-0000-7000-8000-00000000aaaa'],
    );
    $seed = $this->seedPublishedPlanVersion('019f8000-0000-7000-8000-000000000bbb');

    $snapshot = app(GetStrategySnapshot::class)
        ->forOrganizationUnit($context, '019f8000-0000-7000-8000-000000000ccc', null);

    self::assertSame('019f8000-0000-7000-8000-000000000ccc', $snapshot->organizationUnitId);
    self::assertSame([], $snapshot->plans);
    self::assertSame([], $snapshot->objectives);
    self::assertSame([], $snapshot->outcomes);
    self::assertSame([], $snapshot->indicators);
    self::assertNotEmpty($snapshot->generatedAt);
    self::assertDatabaseHas('strategy_plan_versions', ['id' => $seed, 'status' => 'published']);
}

public function test_context_free_signature_is_not_callable_in_production(): void
{
    $this->app->detectEnvironment(fn () => 'production');

    $adapter = app(GetStrategySnapshot::class);
    $reflection = new ReflectionClass($adapter);
    $contextFree = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getName() === 'forOrganizationUnit'
            && array_map(
                static fn (ReflectionParameter $p): string => (string) $p->getType(),
                $m->getParameters(),
            ) === ['string', 'string'],
    );

    self::assertSame([], array_values($contextFree));
}

public function test_unauthorized_request_returns_identical_snapshot_shape_as_no_data(): void
{
    $denied = app(GetStrategySnapshot::class)
        ->forOrganizationUnit(StrategyAccessContextFactory::create(
            organizationUnitIds: ['019f8000-0000-7000-8000-000000000eee'],
        ), '019f8000-0000-7000-8000-000000000fff', null);

    $unpublished = app(GetStrategySnapshot::class)
        ->forOrganizationUnit(StrategyAccessContextFactory::create(
            organizationUnitIds: ['019f8000-0000-7000-8000-000000000fff'],
        ), '019f8000-0000-7000-8000-000000000fff', null);

    self::assertSame($denied->organizationUnitId, $unpublished->organizationUnitId);
    self::assertSame($denied->plans, $unpublished->plans);
    self::assertSame($denied->objectives, $unpublished->objectives);
    self::assertSame($denied->outcomes, $unpublished->outcomes);
    self::assertSame($denied->indicators, $unpublished->indicators);
}

public function test_snapshot_payload_never_includes_context_or_capability_set(): void
{
    $context = StrategyAccessContextFactory::create(
        organizationUnitIds: ['019f8000-0000-7000-8000-000000000111'],
        facilityId: '019f8000-0000-7000-8000-000000000222',
        correlationId: '019f8000-0000-7000-8000-000000000333',
    );
    $snapshot = app(GetStrategySnapshot::class)
        ->forOrganizationUnit($context, '019f8000-0000-7000-8000-000000000111', null);

    $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('principalId', $serialized);
    self::assertStringNotContainsString('facilityId', $serialized);
    self::assertStringNotContainsString('organizationUnitIds', $serialized);
    self::assertStringNotContainsString('correlationId', $serialized);
    self::assertStringNotContainsString('strategy.', $serialized);
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategySnapshotAuthorizationTest.php --filter='context|for_organization_unit|context_free|unauthorized|payload'`.** Expected: FAIL because `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext, …)` does not exist yet, the old context-free signature is still the only callable, and the context-factory helper is absent.
- [ ] **Step 3: Implement `Modules\Strategy\Infrastructure\Persistence\DatabaseGetStrategySnapshot` so that it (a) reads `$context->organizationUnitIds` first and returns an empty `StrategySnapshot` if `$organizationUnitId` is not in the set, (b) only emits plans, objectives, outcomes, and indicators whose owner unit, classification, and publication status satisfy the contract, and (c) never copies `$context` into the returned DTO. Bind `GetStrategySnapshot` in `StrategyServiceProvider::register()` to the production adapter only; assert under `APP_ENV=production` that the resolved concrete class is `Modules\Strategy\Infrastructure\Persistence\DatabaseGetStrategySnapshot` and never a fake or null implementation.
- [ ] **Step 4: Add an HTTP-level regression to `StrategyHttpAdapterTest.php` that asserts `GET /api/v1/strategy/impact` returns 403 before any `GetStrategySnapshot` call when the principal lacks `strategy.impact.read`, that authorized principals with a `StrategyAccessContext` not containing the requested unit see a 200 response with empty arrays (and no disclosure of draft/returned records), and that the response body never contains `principalId`, `facilityId`, `organizationUnitIds`, or `correlationId` literals.
- [ ] **Step 5: Re-run the Task 6 command and `cd apps/api && APP_ENV=production php artisan test Modules/Strategy/Tests/StrategySnapshotAuthorizationTest.php`.** Expected: PASS with zero skip; the production-bound test fails if a fake/null binding or a context-free caller exists.

### Task 7 — Integrate M02 RecordsGovernance behind an explicit blocked phase

**Start gate:** M02 production contracts and bindings are integrated. Until then, only deterministic test fakes may exist under `Modules/Strategy/Tests/Support`; no production binding may point to a fake.

**Files:** Create/modify `StrategyGovernanceIntegrationTest.php`, Strategy plan/evidence handlers, and `StrategyServiceProvider.php`.

- [ ] **Step 1: Write failing integration tests that require `RegisterGovernedRecord::register(GovernedRecordRegistration): GovernedRecordStatus` for a plan version before publish and for approved progress evidence before its event is emitted.**

```php
public function test_governance_failure_rolls_back_publish_idempotency_and_outbox(): void
{
    $this->app->bind(RegisterGovernedRecord::class, FailingRegisterGovernedRecord::class);
    $version = $this->seedApprovedVersion();

    $this->publishVersion($version, 3)->assertStatus(503);
    $this->assertDatabaseHas('strategy_plan_versions', ['id' => $version, 'status' => 'approved', 'lock_version' => 3]);
    $this->assertDatabaseMissing('strategy_idempotency_keys', ['resource_id' => $version]);
    $this->assertDatabaseCount('outbox_events', 0);
}
```

- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyGovernanceIntegrationTest.php`.** Expected: FAIL because production registration is not wired.
- [ ] **Step 3: In the existing command transaction, register sources `strategy/plan_version/{id}` and `strategy/progress_evidence/{id}` with classification, retention start, actor, and idempotency key.** A refusal/unavailable result returns a sanitized 503 problem and rolls back Strategy state, idempotency, approval, and outbox. M04 never calls M02 tables directly.
- [ ] **Step 4: Assert `StrategyServiceProvider` resolves the real M02 contract outside tests and rejects a test fake in production. Re-run Task 7.** Expected: PASS with no production fake and atomic rollback on failure.

### Task 8 — Integrate routes, OpenAPI, and generated client serially

**Start gate:** current architecture closure releases route/OpenAPI/generated-client ownership; `API-ROUTES`, then `OPENAPI`/`ORVAL`, are granted to M04 after M01 → M02 → M03.

- [ ] **Step 1: Add failing route/OpenAPI assertions to `StrategyHttpAdapterTest.php` and the existing contract checks.** Assert every §7 operation ID, problem response, capability, `If-Match`, idempotency, ETag, cursor schema, and `x-implementation-status: implemented`.
- [ ] **Step 2: Run `cd apps/api && php artisan test Modules/Strategy/Tests/StrategyHttpAdapterTest.php` and `npm --prefix apps/web run api:check`.** Expected: route tests FAIL and generated-contract check reports Strategy drift.
- [ ] **Step 3: Under `API-ROUTES`, add explicit controller routes to `apps/api/routes/web.php`. Under `OPENAPI`, replace the generic planned Strategy paths/schemas with §7.** Do not retain generic dispatch by `{strategyResource}`.
- [ ] **Step 4: Run `npm --prefix apps/web run api:generate`; do not edit generated output manually.** Expected: generation succeeds and only contract-derived output changes.
- [ ] **Step 5: Re-run the Task 7 commands plus `npm --prefix apps/web run api:check`.** Expected: PASS with zero generated drift.

### Task 9 — Build the module-owned Strategy web experience

**Files:** Create every `apps/web/src/features/strategy/*` file in §6. Shared shell edits wait for Task 10.

- [ ] **Step 1: Write failing Vitest/RTL tests for authorized list, draft editor, read-only published view, pending measurements, stale ETag conflict, idempotent retry, forbidden state, keyboard focus, bilingual labels, and no browser persistence.**

```tsx
it('keeps a stale draft visible and offers an explicit reload after 412', async () => {
  api.updateStrategyPlanVersion.mockRejectedValue(problem(412, 'precondition-failed'))
  render(<StrategyPlanEditor planId={PLAN_ID} />)
  await userEvent.type(screen.getByLabelText('عنوان الخطة'), ' المعدلة')
  await userEvent.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('تم تعديل الخطة في جلسة أخرى')
  expect(screen.getByRole('button', { name: 'إعادة تحميل أحدث نسخة' })).toHaveFocus()
  expect(screen.getByLabelText('عنوان الخطة')).toHaveValue(expect.stringContaining('المعدلة'))
})
```

- [ ] **Step 2: Run `npm --prefix apps/web run test:unit -- src/features/strategy`.** Expected: FAIL because the Strategy feature files do not exist.
- [ ] **Step 3: Implement generated-client wrappers, problem classification, workspace, editor, and scorecard.** Store ETags in component state, generate a new idempotency key per user intent, reuse it only for retry of the same serialized body, abort stale requests on route changes, and never use localStorage/sessionStorage for Strategy data.
- [ ] **Step 4: Re-run Task 9.** Expected: PASS with no skipped test and role/name-based accessible queries.

### Task 10 — Integrate the web shell serially

**Start gate:** `WEB-SHELL` is granted to M04 after M01 → M02 → M03 and the affected UI route is stable for P05 review.

- [ ] **Step 1: Add failing route/navigation tests for `/strategy`, `/strategy/plans/{uuidv7}`, and `/strategy/measurements`, including fail-closed capability states and invalid UUID not-found behavior.**
- [ ] **Step 2: Run `npm --prefix apps/web run test:unit -- src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx`.** Expected: FAIL because Strategy routes are not registered.
- [ ] **Step 3: Under `WEB-SHELL`, add typed routes, capability mapping, navigation entries, WorkspaceContent dispatch, and Arabic/English copy.** Add Strategy to the existing reports-and-indicators group; do not create a second shell or let M04 take M07's final aggregation ownership.
- [ ] **Step 4: Re-run Task 10 and `npm --prefix apps/web run test:unit -- src/features/strategy`.** Expected: PASS; unresolved capabilities hide the routes and valid read capabilities reveal only permitted screens.

### Final M01 Audit integration gate (blocked only on M01 completion)
- [ ] After M01 completion, write producer-owned failing tests for every successful Strategy mutation; inject only `Modules\Audit\Contracts\RecordAuditEvent`, call it inside each existing mutation transaction, and prove injected Audit failure rolls back Strategy state, idempotency, Strategy outbox, and the Audit append. This is a final exit gate, not a core start dependency; publish the evidence in the M01 integration packet.

### Task 11 — Plan-specific MySQL sentinel registration and concurrency evidence

**Files:** Create `StrategyMySqlConcurrencyTest.php`; register the exact fully-qualified class `Modules\Strategy\Tests\StrategyMySqlConcurrencyTest` in `apps/api/phpunit.mysql.xml` and in `scripts/run-mysql-integration-tests.sh`'s explicit M04 list under the serialized `MODULE-REGISTRY`/`MYSQL-SUITE` token.

- [ ] **Step 1: Write MySQL tests with two independent connections/processes for overlapping active periods, concurrent version creation, publish-vs-edit stale writes, and duplicate evidence approval.** Each test method calls `DB::connection()->getDriverName()` and asserts it equals `mysql`; an SQLite or `null` driver fails the test rather than skipping it. The class declares an explicit `setUp()` that calls `self::assertSame('mysql', $this->app['db']->connection()->getDriverName())` so that an absent registration surfaces immediately as a real failure rather than a `SKIP` line.
- [ ] **Step 2: Register the sentinel in XML only.** Add exactly one `<file>Modules/Strategy/Tests/StrategyMySqlConcurrencyTest.php</file>` entry to the existing MySQL suite in `apps/api/phpunit.mysql.xml`; do not edit `scripts/run-mysql-integration-tests.sh` or invent a class-list variable. Prove discovery with the existing runner's `--list-tests` or equivalent class-name discovery, then execute the suite and retain non-skipped output.
- [ ] **Step 3: Run the existing MySQL runner.** Expected: discovery names `Modules\Strategy\Tests\StrategyMySqlConcurrencyTest`, execution reports `Skipped: 0`, and no literal-sentinel count fiction is used. Make no runner-script changes.
- [ ] **Step 4: Make lock order deterministic: owner-scope/plan root row → plan version → outcome/indicator period → idempotency row.** Translate duplicate/constraint races to the same 409/412 problem types as SQLite feature tests. The test class must retain at least one assertion that exercises the producer-side `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, …)` call against the real MySQL store with two seeded organization units — one authorized and one denied — and asserts that the denied unit returns an empty `StrategySnapshot` while the authorized unit returns the seeded published rows; this is the MySQL-side non-disclosure gate.
- [ ] **Step 5: Run the targeted and integrated verification commands from §11 and execute every smoke scenario.** Expected: all pass on one integrated commit with no skip, fake production binding, generated drift, missing sentinel token, or open critical finding.

### Task 12 — Real-browser E2E spec, deterministic P07 startup, fixture inputs, and per-scenario artifacts

**Files:** Create `apps/web/e2e/strategy-snapshot.spec.ts` (real-browser Playwright spec); extend P07's `apps/api/database/seeders/ProductionE2ESeeder.php` and its seed JSON contract only through P07's fixture integration queue; add the spec to `apps/web/playwright.production.config.ts` `testMatch` and to `apps/web/e2e/fixtures/production-fixtures.ts` under P07's discovery inventory.

- [ ] **Step 1: Through the P07 fixture queue, make `ProductionE2ESeeder` create deterministic Strategy inputs for run ID `P07_RUN_ID`: one `p07-strategy-manager` and one `p07-strategy-readonly` persona key (each with CSRF-capable session credentials), one published Strategy period owned by `p07-facility-a-owner`'s organization unit, one published weighted plan version with one objective, one outcome, one indicator, and one approved measurement, one draft-only plan version owned by the same unit, one draft plan version owned by an out-of-scope facility unit, and stable Strategy natural keys plus initial ETags. Export their opaque IDs, persona keys, organization unit IDs, and CSRF tokens in the mode-`0600` seed JSON consumed by `production-fixtures.ts`. The JSON contract for Strategy must include the exact keys `strategy.manager_principal_id`, `strategy.readonly_principal_id`, `strategy.authorized_organization_unit_id`, `strategy.denied_organization_unit_id`, `strategy.published_plan_id`, `strategy.published_version_id`, `strategy.draft_plan_id`, and `strategy.draft_version_id`.
- [ ] **Step 2: Add `apps/web/e2e/strategy-snapshot.spec.ts` to P07's explicit production `testMatch` and discovery inventory.** The spec must call `production-fixtures.ts::loadStrategyFixtures()` and obtain every dynamic ID, credential, organization unit, persona key, and CSRF token from the seed JSON/connection manifest. It must not invent UUIDs, call a Strategy seeder directly, or assume a pre-existing browser session. The spec covers: (a) manager reads `/strategy` and sees the published plan with a Scorecard link, (b) read-only persona sees the published plan without edit controls, (c) manager navigates to `/strategy/plans/{published_plan_id}` and triggers `GET /api/v1/strategy/impact`, the browser asserts the response body contains the published plan, the indicator scorecard row, and the literal `generatedAt` value, and never contains `principalId`, `facilityId`, `organizationUnitIds`, or `correlationId`, (d) read-only persona navigating to a denied organization unit receives the same 200/empty-payload response as a unit with no published data, (e) the manager's stale ETag on the draft plan version returns 412 with `application/problem+json` and the explicit reload action retains focus, (f) keyboard-only traversal reaches filters, version selector, evidence review, error alert, and reload action in logical order in both `ltr` and `rtl` locales.
- [ ] **Step 3: Run `P07_TEST_MATCH=strategy-snapshot.spec.ts ./infra/platform/production/run-local-e2e.sh` with `P07_COMMIT_SHA` exported.** Before Playwright, the runner must perform its bounded `start → export connection/seed manifest → dependent gate` lifecycle (per `docs/superpowers/plans/2026-07-26-cluster-e2e-runner-readiness.md` §8) and assert the discovery output contains `strategy-snapshot.spec.ts` with a parsed test count greater than zero. The nonzero discovery sentinel for this plan is `jq '.suites[].specs[] | select(.file=="strategy-snapshot.spec.ts") | .tests | length' /run/cluster-p07/$P07_RUN_ID/playwright-list.json` returning an integer `>= 1`; a value of `0`, missing fixture keys, unreachable Caddy/API readiness, or any mocked/intercepted request is a nonzero failure, never a skip.
- [ ] **Step 4: Retain per-scenario artifacts under `artifacts/production-e2e/$P07_RUN_ID/strategy-snapshot/`:** `journey-1-published-list.json` (manager's `/strategy` list response excerpt), `journey-2-readonly-list.json` (read-only `/strategy` list response excerpt), `journey-3-impact-body.json` (full `GET /api/v1/strategy/impact` body for the manager), `journey-4-denied-empty.json` (impact body when the read-only persona targets the denied unit), `journey-5-stale-etag-412.json` (problem+json body for the stale ETag), `journey-6-keyboard-order.txt` (ordered list of focused element selectors across both locales), `playwright-list.json` (the raw `--list` output proving nonzero discovery), `playwright-report.html` (HTML report), `playwright-results.json` (JSON report), `playwright-results.xml` (JUnit), and `cleanup-proof.json` (proving zero remaining docker resources). The M04 manifest `docs/architecture/evidence/M04/strategy-p07-e2e.yaml` references each path by its SHA-256; missing or empty artifacts fail the gate.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

### Error contract

- 400 `invalid-correlation-id`, `invalid-idempotency-key`, `invalid-strategy-payload`, or domain invariant failure; `application/problem+json` with sanitized detail.
- 401 `authentication-required` when the session is absent/expired.
- 403 `forbidden` before detailed field validation, owner lookup, resource lookup, or existence disclosure.
- 404 `strategy-resource-not-found` only after an allowed principal cannot resolve the requested record.
- 409 `idempotency-key-reused`, `strategy-lifecycle-conflict`, `strategy-period-overlap`, or unique-code conflict.
- 412 `precondition-failed` for missing/malformed/stale `If-Match` on versioned mutation.
- 503 `strategy-governance-unavailable` when required M02 registration fails closed.

Problem bodies contain `type`, `title`, `status`, sanitized `detail`, and `correlation_id`; they never contain SQL, stack traces, raw evidence, person data, idempotency keys, or classification-hidden fields.

### Idempotency

All POST/PATCH commands require `Idempotency-Key`. The scope tuple is `(principal_id, organization_unit_id, operation, SHA256(key))`. Request hash covers canonical method, route template, target ID, expected lock version, and canonical JSON body. Exact replay returns the persisted status/body/ETag without a second state, approval, governance registration, or event. Same key with a different hash returns 409. Failed transactions do not persist the key.

### Optimistic and pessimistic concurrency

- Public writes require ETag/`If-Match` and update with an atomic lock-version predicate.
- Root rows serialize overlap/version-number checks with `SELECT … FOR UPDATE` in a consistent order.
- Unique constraints are the final race barrier; duplicate-key exceptions map to deterministic domain problems.
- Cursor results use a stable tie-breaker ID and never offset pagination.

### Authorization and data protection

- Capability evaluation happens before detailed validation or lookup.
- Plan/period/objective/outcome read/manage map to `strategy.plan.read|manage`.
- Indicator/configuration read/manage map to `strategy.indicator.read|manage`.
- Evidence submit/approve map to `strategy.measurement.submit|approve`.
- Impact/snapshot reads map to `strategy.impact.read`.
- Record facts include owner unit, classification, lifecycle state, responsible user, and lock version. AccessProjection is applied before serialization.
- Strategy identifiers, filters, and browser history contain no PII/PHI; free-form evidence is body-only and never logged unsanitized.

## 11. Targeted verification commands and smoke scenarios

Run only after implementation and required tokens are integrated. Each command writes its complete output to the M04 evidence directory and must exit 0.

```bash
cd apps/api && php artisan test Modules/Strategy/Tests/StrategyDomainTest.php Modules/Strategy/Tests/StrategyPersistenceTest.php Modules/Strategy/Tests/StrategyHttpAdapterTest.php Modules/Strategy/Tests/StrategyContractsTest.php Modules/Strategy/Tests/StrategyGovernanceIntegrationTest.php Modules/Strategy/Tests/StrategySnapshotAuthorizationTest.php
cd apps/api && APP_ENV=production php artisan test Modules/Strategy/Tests/StrategySnapshotAuthorizationTest.php --fail-on-warning
cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php --filter='strategy|outbox_event'
make verify-mysql-integration
grep -c "Modules\\Strategy\\Tests\\StrategyMySqlConcurrencyTest" apps/api/phpunit.mysql.xml scripts/run-mysql-integration-tests.sh
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit -- src/features/strategy src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx
npm --prefix apps/web run build
P07_TEST_MATCH=strategy-snapshot.spec.ts P07_COMMIT_SHA="$(git rev-parse HEAD)" ./infra/platform/production/run-local-e2e.sh
```

Expected outcomes:

- all targeted API tests pass with zero skip;
- the production-bound run (`APP_ENV=production`) resolves `GetStrategySnapshot` to `Modules\Strategy\Infrastructure\Persistence\DatabaseGetStrategySnapshot` and rejects every fake/null/no-op binding or test path; the context-free signature is not callable from any test or runtime container;
- boundary tests prove rank 8, no forbidden import, exact twelve-table ownership, controller placement, `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext, …)` signature, and event schema parity;
- MySQL explicitly executes `StrategyMySqlConcurrencyTest`, the sentinel grep returns exactly `2`, and the suite passes overlap/idempotency/stale-write races including the producer-side non-disclosure assertion;
- generated client has zero drift;
- Strategy web tests and production build pass;
- the P07 runner discovers `strategy-snapshot.spec.ts` with a parsed test count `>= 1`, runs every journey through Caddy against real services with the exact seeded fixture IDs/personas and no request interception, then stops via trap and proves zero remaining resources; the M04 evidence directory retains every per-scenario artifact listed in Task 12.


### Required smoke scenarios

1. **End-to-end plan publication:** authorized manager creates a period and plan, authors a graph whose objective weights total 10000, submits, approves, publishes, and sees a scorecard. A read-only user sees the published version without editing controls.
2. **Validation barrier:** submission with weights totaling 9000 returns 400; version remains draft; no idempotency, approval, governance, or outbox residue exists.
3. **Idempotent retry:** identical create/publish retry returns identical body and ETag with one aggregate and one event; changed-body reuse returns 409.
4. **Stale editor:** two browser sessions load ETag `"4"`; first saves to `"5"`; second receives 412, retains unsaved input, and can explicitly reload.
5. **Evidence approval:** submitter records a measurement; approver approves it; progress recomputes once and published event contains no narrative/person data.
6. **Producer-side authorization:** a principal with `strategy.impact.read` calls `GetStrategySnapshot::forOrganizationUnit($context, $organizationUnitId, null)` against an organization unit listed in `$context->organizationUnitIds` and receives the published plans/objectives/outcomes/indicators. The same principal calling against an organization unit not in `$context->organizationUnitIds` receives an empty `StrategySnapshot` whose `plans`, `objectives`, `outcomes`, and `indicators` arrays are all empty and whose serialized JSON contains no `principalId`, `facilityId`, `organizationUnitIds`, or `correlationId` substring; the empty snapshot is byte-identical to the snapshot returned for an authorized-but-empty unit. A principal without `strategy.impact.read` receives a 403 problem+json before `GetStrategySnapshot` is invoked. The HTTP route `GET /api/v1/strategy/impact` exercises the same authorization path and the same non-disclosure shape.
7. **Producer context-free signature is unreachable:** with `APP_ENV=production`, the Laravel container resolves `GetStrategySnapshot` to `Modules\Strategy\Infrastructure\Persistence\DatabaseGetStrategySnapshot`, reflection finds no callable method whose signature is `(string $organizationUnitId, ?string $periodId = null): StrategySnapshot`, and a runtime call to that context-free signature throws.
8. **Governance fail closed:** M02 registration failure during publish rolls back plan status, approval, idempotency, and outbox, then a later retry with the same key succeeds once after M02 recovers.
9. **Downstream references:** M05/M06 test consumers resolve a published objective/outcome via `ResolveStrategyReference`; draft returns null; retired state/event is visible without M04 importing either consumer.
10. **Workspace snapshot:** M07 test consumer calls `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null)`, gets published authorized facts when the requested unit is in `$context->organizationUnitIds`, gets an empty `StrategySnapshot` when it is not, and persists no copy. M07 never calls the context-free signature and never imports a same-rank contract.
11. **MySQL sentinel registration:** `bash scripts/run-mysql-integration-tests.sh` and `make verify-mysql-integration` exit 0, name `Modules\Strategy\Tests\StrategyMySqlConcurrencyTest` in the discovered-test list, execute at least one test method from that class with zero skips, run the producer-side non-disclosure assertion against two seeded organization units, and the literal sentinel grep returns exactly `2`. A `SKIP:` line, an absent class, or a value of `0`/`1`/`>=3` from the grep fails the gate.
12. **Real-browser P07 E2E:** `P07_TEST_MATCH=strategy-snapshot.spec.ts ./infra/platform/production/run-local-e2e.sh` starts the bounded production topology, exports the connection/seed manifest, discovers `strategy-snapshot.spec.ts` with a parsed test count `>= 1`, runs every journey in the spec through Caddy against real services with the exact seeded fixture IDs/personas and no request interception, then stops via trap and proves zero remaining resources. Every per-scenario artifact listed in Task 12 Step 4 is present in `artifacts/production-e2e/$P07_RUN_ID/strategy-snapshot/`. The M04 evidence file `docs/architecture/evidence/M04/strategy-p07-e2e.yaml` references each path by SHA-256.
13. **Accessibility:** keyboard-only user reaches filters, version selector, objective editor, evidence dialog, error alert, and retry/reload actions in logical order; status is not conveyed by color alone; Arabic and English labels expose accessible names.

P05 owns final WCAG 2.2 AA evidence after the route stabilizes. P08 owns final aggregate closure; M04 cannot declare system closure.

## 12. Shared-file integration token requirements

M04 owns none of these surfaces. Process tokens in this exact order, after current-plan handoff and after earlier module holders M01 → M02 → M03 release them:

1. `MODULE-REGISTRY`
   - `apps/api/tests/Architecture/ModuleBoundariesTest.php`: retain rank 8, remove Strategy from `PLANNED_MODULES`, add exactly the twelve `strategy_*` owners.
   - `docs/architecture/module-catalog.md`: move Strategy from planned to implemented with the frozen purpose/tables/contracts/routes.
   - `apps/api/config/module_migrations.php`: append the three migration paths in §8 order.
   - `apps/api/app/Providers/AppServiceProvider.php`: append `StrategyServiceProvider` after lower-rank providers and before higher-rank consumers.
   - `apps/api/phpunit.mysql.xml`: add exactly one `<file>Modules/Strategy/Tests/StrategyMySqlConcurrencyTest.php</file>` entry to the existing MySQL suite; discovery and execution use the existing runner, with no script edit or sentinel-count assertion.
2. `API-ROUTES`
   - `apps/api/routes/web.php`: add explicit Strategy routes under existing API/session/CSRF middleware after architecture closure releases active route ownership.
3. `OPENAPI` then `ORVAL`
   - `docs/contracts/api/openapi.yaml`: replace generic planned Strategy operations with explicit implemented contracts.
   - run `npm --prefix apps/web run api:generate`; never edit `apps/web/src/api/generated/cluster.ts` manually.
4. `WEB-SHELL`
   - modify the exact shell/content/copy files in §6; M07 retains final aggregation authority.
5. M02 governance integration token/merge gate
   - merge only after M02 production contracts are real; remove all test-only bindings from production container paths.
6. Downstream consumer queue
   - publish the M04 integration commit/evidence to M05 and M06 so their blocked Strategy phases can bind real contracts; then M07 final aggregation may proceed.
7. P07 fixture queue
   - extend P07-owned `apps/api/database/seeders/ProductionE2ESeeder.php` and the seed JSON contract with the deterministic Strategy fixtures listed in Task 12 Step 1; add `apps/web/e2e/strategy-snapshot.spec.ts` to P07's `playwright.production.config.ts` `testMatch` and to `production-fixtures.ts` discovery; M04 never edits `run-local-e2e.sh`, `playwright.production.config.ts`, or `production-fixtures.ts` outside this queue.

Every token record must include token, state, requesting plan `M04`, releasing owner, base commit, exact surfaces, grant evidence, and merge commit. Revoke/rebase stale tokens; do not resolve concurrent edits ad hoc. `Makefile`, `.github/workflows/ci.yml`, and `.github/workflows/ci-e2e.yml` remain untouched by M04; only P08 may integrate them after Task 13 handoff.

## 13. Rollback procedure

1. Block new Strategy writes and retain the correlation ID and last successful event ID.
2. Revoke any unmerged shared token; discard only M04 changes on that token and restore the released owner's exact base.
3. If web/API deployment occurred without schema use, remove Strategy shell/routes/provider entries under the same serialized queues, regenerate Orval from the rolled-back OpenAPI, and verify zero generated drift.
4. If schema contains data, export all twelve Strategy tables plus Strategy outbox rows, record row counts/checksums, and verify no M05/M06 production references would be orphaned.
5. Roll back reliability → measurement → planning migrations in reverse FK order only after downstream references are retired/migrated.
6. Restore prior OpenAPI/routes/shell/module registry via their queue owners and regenerate the client; never hand-revert generated code.
7. Re-run boundary, API contract, and module smoke gates against the rollback commit. Record whether outbox replay is required and the first/last replayed event IDs.
8. If rollback cannot preserve downstream referential meaning, keep the last verified M04 deployment read-only and mark the plan `blocked`; do not drop data.

## 14. Exit criteria and required retained evidence

M04 may enter `verification` only when:

- all module-owned implementation and web feature files are complete;
- M02 governance integration uses production contracts and no fake production binding exists;
- every required shared token is merged/released;
- M05/M06/M07 receive the frozen integrated contract signatures;
- no manual generated-client edit exists.

M04 may become `completed` only after all §11 commands and smoke scenarios pass on one user-authorized recorded commit, with no skip or unresolved critical finding.

Before the first verification command, after the user authorizes recording the implementation commit, execute:

```bash
COMMIT_SHA="$(git rev-parse HEAD)"
STARTED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
test "$(printf '%s' "$COMMIT_SHA" | wc -c | tr -d ' ')" -eq 40
```

After the last smoke scenario, set `FINISHED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"` and write `docs/architecture/evidence/M04/manifest.yaml` by expanding the shell variables in this exact schema:

```yaml
plan_id: M04
commit: "$COMMIT_SHA"
started_at: "$STARTED_AT"
finished_at: "$FINISHED_AT"
commands:
  - name: strategy-api-targeted
    output_path: docs/architecture/evidence/M04/strategy-api-targeted.txt
    exit_code: 0
  - name: strategy-boundaries
    output_path: docs/architecture/evidence/M04/strategy-boundaries.txt
    exit_code: 0
  - name: strategy-mysql
    output_path: docs/architecture/evidence/M04/strategy-mysql.txt
    exit_code: 0
  - name: strategy-api-check
    output_path: docs/architecture/evidence/M04/strategy-api-check.txt
    exit_code: 0
  - name: strategy-web
    output_path: docs/architecture/evidence/M04/strategy-web.txt
    exit_code: 0
  - name: strategy-web-build
    output_path: docs/architecture/evidence/M04/strategy-web-build.txt
  - name: strategy-snapshot-authorization
    output_path: docs/architecture/evidence/M04/strategy-snapshot-authorization.txt
    exit_code: 0
  - name: strategy-snapshot-authorization-production
    output_path: docs/architecture/evidence/M04/strategy-snapshot-authorization-production.txt
    exit_code: 0
  - name: strategy-mysql-sentinel-grep
    output_path: docs/architecture/evidence/M04/strategy-mysql-sentinel-grep.txt
    exit_code: 0
  - name: strategy-p07-e2e
    output_path: docs/architecture/evidence/M04/strategy-p07-e2e.txt
    exit_code: 0
smoke_scenarios:
  - evidence_path: docs/architecture/evidence/M04/smoke-scenarios.json
    result: pass
integration_tokens:
  - evidence_path: docs/architecture/evidence/M04/integration-tokens.yaml
contracts:
  - evidence_path: docs/architecture/evidence/M04/contract-consumers.yaml
  - evidence_path: docs/architecture/evidence/M04/strategy-snapshot-authorization.yaml
  - evidence_path: docs/architecture/evidence/M04/strategy-p07-e2e.yaml
  - evidence_path: docs/architecture/evidence/M04/mysql-sentinel-registration.yaml
rollback_rehearsal:
  evidence_path: docs/architecture/evidence/M04/rollback-rehearsal.txt
open_findings: []
accepted_risks: []
```

The manifest writer must expand `$COMMIT_SHA`, `$STARTED_AT`, and `$FINISHED_AT`; a literal dollar-prefixed value is invalid. Retain also the three JSON event schemas, generated-client drift result, MySQL test names proving execution, P05 accessibility handoff, M02 production-binding proof, and M05/M06/M07 consumer acknowledgements.

New defects discovered from source or `.minimax-flow` reports receive the next verified `C` identifier with source/evidence/exit criteria. Never reconstruct unsourced historical `F001`–`F123` entries.

## 15. Status transition rules

- `blocked → ready`: M00 is approved, Architecture Closure Tasks 4/6/7/12 have handed off the relevant foundations, and an executor/worktree/base commit are recorded. M02 and downstream consumers are not required for this transition.
- `ready → in_progress`: authorized executor begins module-owned work in an isolated worktree. Shared surfaces remain untouched without tokens.
- `in_progress → blocked`: record the exact dependency/token/environment blocker and last safe commit. The expected blocked M02 governance phase uses this transition if the core reaches it before M02.
- `blocked → in_progress`: the named blocker is resolved; for governance, M02 production contracts/bindings and integration evidence exist.
- `in_progress → verification`: module core, M02 integration, shared queues, web shell, contracts/events, and downstream handoffs are complete; no production fake, unresolved generated drift, or shared token remains.
- `verification → completed`: every exit criterion and retained evidence path passes on one recorded commit after explicit user authorization. Update the orchestration inventory in the same authorized change.
- Any status `→ superseded`: a later user-approved design identifies the replacement plan and updates this metadata, orchestration inventory/graph, shared token ledger, and M05/M06/M07 dependencies.

Planning completion does not change M04 from `blocked`; it remains blocked until M00 and the recorded handoffs satisfy the first transition.