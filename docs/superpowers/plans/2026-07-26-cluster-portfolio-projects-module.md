# Cluster PortfolioProjects Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: M05
status: blocked
depends_on:
  - M00
blocks:
  - M06:project-integration
  - M07:final-integration
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Deliver a production-complete PortfolioProjects module for portfolio, program, project, and project-template records; project ownership and lifecycle; milestones and dependencies; append-only health, progress, and budget reporting; authorized cross-module reads; Strategy indicator linkage through M04's published contract; and accessible `/portfolio` web journeys.

**Architecture:** Rank-9 `PortfolioProjects` owns every command, transaction, persistence query, and projection over its canonical tables. HTTP adapters perform session/correlation/header parsing and coarse authorization before detailed validation, then call module-owned handlers. M04 integration is one-way through `Modules\Strategy\Contracts\ResolveStrategyReference` and M04 events; M06 and M07 consume only M05's frozen Contracts/Events. Shared registries, Laravel routes, OpenAPI/Orval output, and web shell files are changed only in M05's serialized integration tokens after their current owners hand them off.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, MySQL and SQLite, shared transactional outbox, React 19, TypeScript 6, Vite 8, Vitest 4, OpenAPI 3.1, Orval, WCAG 2.2 AA.

## Global constraints

- The approved design is `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`; M00's frozen reservations are authoritative for rank, tables, route prefixes, capabilities, Contracts, and Events.
- The current architecture-closure plan remains `in_progress`. M05 may not take a shared token until the owning closure task records a handoff.
- Clean cutover only: no compatibility aliases, production no-ops, or duplicate APIs.
- Generated `apps/web/src/api/generated/cluster.ts` changes only through `npm --prefix apps/web run api:generate` in the contract queue.
- A controller calls boundary parsing/coarse capability evaluation, detailed validation, a handler/application service, and module-owned persistence in that order. Authorization precedes detailed validation and resource disclosure.
- State, idempotency receipt, audit/outbox effects, and emitted events for one command commit in one database transaction.
- Every versioned write uses compare-and-swap in its SQL write predicate. Checking a version before an unconditional update is not sufficient.
- Production cross-module calls use published `Contracts/` or `Events/`; no M05 file imports another module's `Domain/` or `Infrastructure/`.
- Cross-module IDs are UUIDv7 values without cross-owner database foreign keys. M05 may use foreign keys among M05-owned tables.
- Problem responses use `application/problem+json`, carry the request correlation ID, do not expose exception text, and contain no PII/PHI.
- No commit, push, deployment, migration, or cloud action is authorized by this plan. A commit is recorded in metadata only after explicit user authorization.

---

## 1. Status header and dependency fields

M05 remains `blocked` until M00 is approved after Architecture Closure Tasks 4, 6, 7, and 12. Once M00 is approved, module-owned implementation may proceed in an isolated worktree. The Strategy-link phase remains explicitly blocked until M04 publishes and binds its frozen contract. M04 is not a start dependency and must not be added to `depends_on`.

The following handoffs are mandatory before the named shared phase:

| Gate | Required evidence |
|---|---|
| `ARCHITECTURE-CLOSURE:T4-HANDOFF` | Releases `apps/api/tests/Architecture/ModuleBoundariesTest.php` to the module registry queue. |
| `ARCHITECTURE-CLOSURE:T6-HANDOFF` | Releases composition-root/provider and ownership surfaces, including `apps/api/app/Providers/AppServiceProvider.php`. |
| `ARCHITECTURE-CLOSURE:T12-HANDOFF` | Releases `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, and route/contract reconciliation. |
| `ARCHITECTURE-CLOSURE:T13-HANDOFF` | Releases generated-client verification and broad shared verification surfaces; M05 still does not own Make/CI files. |
| `M00:APPROVED` | Freezes rank 9, table ownership, capabilities, prefixes, Contracts/Events, and queue order. |
| `M04:CONTRACT-PUBLISHED` | Makes `ResolveStrategyReference`, `StrategyResourceType`, and `StrategyReference` available with a production binding. |

M05 never owns `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, worker/scheduler scripts, or final shell aggregation. P08 remains the only final CI/Make owner, and M07 owns only the final `apps/web/src/shell/routes.ts` aggregation token after every module token has landed.

## 2. Goal and user-visible outcome

An authorized user can:

1. list, create, view, and edit portfolios, programs, projects, and project templates under `/portfolio`;
2. assign the owning organization unit and accountable user without leaking denied identity or organization facts;
3. move a project through a deterministic lifecycle and receive clear conflict or stale-write feedback;
4. create, submit, approve, complete, and cancel weighted milestones;
5. add or remove project-to-project dependencies while preventing self-links and directed cycles;
6. record append-only health/progress and budget snapshots and view a bounded chronological report;
7. link a project to an authorized Strategy indicator after M04 integration without accessing Strategy tables or internals;
8. see cursor-paginated, capability-filtered results and accessible loading, empty, denied, stale, retry, and success states in Arabic and English.

The portfolio summary shows the latest health (`green|amber|red|unknown`), reported completion percentage, milestone-derived completion percentage, overdue milestone count, unresolved dependency count, owner, and current project status. Raw budget values render only for principals holding `portfolio_projects.budget.read`.

## 3. Current source evidence

| Evidence | Current fact and planning consequence |
|---|---|
| `apps/api/tests/Architecture/ModuleBoundariesTest.php:13-52` | `PortfolioProjects` is already rank 9 and listed as planned. Creating its directory before the registry token makes the boundary test fail; the serialized token must remove only this module from `PLANNED_MODULES`, retain rank 9, and add exactly the M00 tables. |
| `docs/architecture/module-catalog.md:29-32,194-197` | The catalog reserves rank 9 for portfolio aggregates, between Strategy rank 8 and Risk rank 10. M05 may consume M04 Contracts; M06 may consume M05 Contracts. |
| `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php:108-114` | All seven M05 capability codes already exist. M05 does not edit the catalog. `portfolio_projects.milestone.approve` is classified sensitive by the authorization catalog tests. |
| `docs/contracts/api/openapi.yaml:4289-4637` | The authoritative contract already reserves `/portfolio/{portfolioResource}`, item reads/patches, project lifecycle actions, milestones, budget/health snapshots, and indicator links. M05 implements these paths rather than creating a competing prefix. |
| `docs/contracts/api/openapi.yaml:9390-9506` | `PortfolioResourceCreate`, `PortfolioResourcePatch`, `MilestoneCreate`, `ProjectSnapshotCreate`, and `IndicatorLinkCreate` are the contract baseline. Contract deltas for dependency IDs and typed progress values go through the M05 OpenAPI token. |
| `apps/api/Modules/Tasks/Infrastructure/Persistence/TaskHttpStore.php:45-65` | Existing task writes demonstrate `lock_version` compare-and-swap. M05 keeps CAS inside each update predicate and returns 412 when zero rows update. |
| `apps/api/Modules/Tasks/Features/Http/TaskController.php:40-174,230-275` | Existing task adapters demonstrate correlation, idempotency, ETag, and lifecycle response conventions. M05 corrects the ordering by authorizing before detailed body validation. |
| `apps/api/Modules/WorkRecords/Features/Lifecycle/Handler/WorkRecordLifecycleMutator.php:88-116` | Existing work-record transitions demonstrate state plus outbox in one transaction. M05 also stores the idempotency receipt in that transaction and never exposes caught exception messages. |
| `apps/api/Modules/Reporting/Features/RefreshReportingProjection/Handler/RefreshReportingProjectionHandler.php:16-66` | Reporting accepts safe, classified event projections. M05 publishes sanitized progress/health events; it never imports Reporting internals or writes Reporting tables. |
| `apps/api/config/module_migrations.php:3-51` and `apps/api/app/Providers/AppServiceProvider.php:24-46` | Migrations and module providers are centrally registered. Both edits require the serialized registry/composition token; module-owned files alone are not a production binding. |
| `apps/api/routes/web.php:113-306` | Authenticated GET routes use Identity session middleware; mutations additionally use Identity CSRF middleware. M05 routes must join the matching groups. |
| `apps/web/src/shell/routes.ts`, `navigation.tsx`, and `apps/web/src/app/WorkspaceContent.tsx` | Route parsing, capability gating, navigation, and content composition are shared shell surfaces. M05 feature components remain module-owned; shell edits wait for the M05 shell token. |

Fresh execution evidence supersedes this inventory. A newly validated defect is registered as the next `C###` finding with source/command evidence and exit criteria; raw `.minimax-flow` IDs are never promoted into historical `F` identifiers.

## 4. Scope and explicit non-goals

### In scope

- All four existing resource kinds: `portfolio`, `program`, `project`, `project_template`.
- Project ownership fields (`owner_organization_unit_id`, `accountable_user_id`) and milestone owner fields stored in M05 tables as un-enforced cross-module UUIDv7 references.
- Project status, lifecycle invariants, weighted milestones, same-module project dependencies, health/progress/budget snapshots, indicator links, summaries, Contracts/Events, API, and web UI.
- SQLite behavior tests plus non-skipped MySQL transaction, rollback, uniqueness, cycle, idempotency, and two-connection CAS evidence.
- Module-owned report queries and safe outbox events for downstream Reporting/Search/Workspace consumers.

### Non-goals

- No query, write, model import, or foreign key into Strategy internals. M05 validates indicator references only through `ResolveStrategyReference` and reacts only to published M04 Events.
- No direct SQL or model import into Tasks, WorkRecords, Reporting, Search, Organization, Identity, Authorization, Risk, or Workspace. Their source patterns inform design but do not become dependencies.
- No new capability code; no edit to `CapabilityCatalog.php`.
- No M05-owned global dashboard, Reporting projection consumer, Search indexer, Risk relation, or Workspace cache.
- No financial ledger, cost approval, resource-capacity scheduler, Gantt engine, document attachment store, or time-sheet implementation.
- No Make/CI target, final shell aggregation, production topology, or generated-client hand edit.

## 5. Architecture and ownership boundaries

### Command/read flow

```text
Identity session + correlation/header parser
  -> coarse capability decision using only route/scope facts
  -> detailed request validator
  -> PortfolioProjects handler/application service
  -> PortfolioProjects repository and transaction
  -> shared TransactionalOutbox append
  -> response projection constrained by AccessProjection
```

For an item request, the repository returns a minimal authorization envelope (`id`, owner scope, classification, accountable user, lifecycle, lock version) before the full row is projected. A missing or denied item produces the same 404 detail. List handlers push authorized scope predicates into SQL before cursor/limit, then apply `AccessProjection`; they do not fetch an unbounded set and filter it in PHP.

### Frozen published M05 surface

Production implementations live in M05 Infrastructure and are bound by `PortfolioProjectsServiceProvider`; test doubles are registered only inside tests.

```php
namespace Modules\PortfolioProjects\Contracts;

interface ResolveAuthorizedProjectReference
{
    public function resolve(ProjectAccessContext $context, string $projectId): ?ProjectReference;
}

interface ListAuthorizedProjectSummaries
{
    public function list(AuthorizedProjectSummaryQuery $query): AuthorizedProjectSummaryPage;
}
```

`ProjectAccessContext` contains `userId`, nullable `facilityId`, `organizationUnitIds`, and `correlationId`. `ProjectReference` contains `id`, nullable `portfolioId`, `ownerOrganizationUnitId`, `accountableUserId`, `status`, `classification`, and `lockVersion`. Denied and absent references both return `null`.

`AuthorizedProjectSummaryQuery` contains the same principal/scope facts plus nullable opaque `cursor` and `limit` in `1..100`. `AuthorizedProjectSummaryPage` contains `list<AuthorizedProjectSummary> $items` and nullable `$nextCursor`; it never exposes a total count. Each summary contains only authorized safe fields: IDs, code, name, status, classification, owner IDs, planned dates, latest health, reported and milestone progress, overdue milestone count, and lock version. Budget is absent from this contract.

### Frozen consumed M04 surface

```php
use Modules\Strategy\Contracts\ResolveStrategyReference;
use Modules\Strategy\Contracts\StrategyAccessContext;
use Modules\Strategy\Contracts\StrategyReference;
use Modules\Strategy\Contracts\StrategyResourceType;
    $context = new StrategyAccessContext(principalId: $principalId, facilityId: $facilityId, organizationUnitIds: $organizationUnitIds, correlationId: $correlationId);
    $reference = $resolver->resolve($context, StrategyResourceType::Indicator, $indicatorId);
```

M05 owns the link row. `null` maps to the same 404 used for an absent/denied indicator. A valid reference must be published/active and within the authorized organization scope exposed by `StrategyReference`. M05 imports no M04 Domain or Infrastructure class.

### Lifecycle invariants

Project transitions are exactly:

```text
draft --baseline--> baselined --start--> active --hold--> on_hold
                                      active <--resume-- on_hold
                                      active/on_hold --close--> closed
              draft/baselined/active/on_hold --cancel--> cancelled
```

- `closed` and `cancelled` are terminal.
- `baseline` requires non-empty owner organization unit and accountable user, valid planned start/end (`start < end`), at least one milestone, milestone weights totaling exactly `100.00`, and an acyclic dependency set.
- `start` requires `baselined`; `hold` requires `active`; `resume` requires `on_hold`.
- `close` requires every non-cancelled milestone to be `completed`, a latest progress snapshot at `100.00`, and no predecessor project outside `closed|cancelled`.
- `cancel` requires a non-blank reason of at most 2000 characters. It never cascades cancellation to dependent projects.
- Lifecycle transitions require `portfolio_projects.project.manage`, `If-Match`, and `Idempotency-Key`.

Milestone transitions are exactly:

```text
draft --submit--> submitted --approve--> approved --complete--> completed
  draft/submitted/approved --cancel--> cancelled
```

A milestone is editable only in `draft`, approval requires `portfolio_projects.milestone.approve`, completion requires an `active|on_hold` project, and `completed|cancelled` are terminal. A project cannot baseline if any milestone is cancelled or weights of remaining milestones differ from 100.00.

Project dependencies are a sorted, unique JSON array of predecessor project UUIDv7 values stored on `portfolio_projects_projects.dependency_project_ids`. This choice is fixed by M00's table inventory: no additional dependency table is permitted. Dependency replacement uses project `If-Match` CAS, rejects self-reference, checks every predecessor through M05 persistence, locks affected M05 project rows in stable UUID order, and rejects any directed cycle with 409 `project-dependency-cycle`. Reads return IDs only after project authorization; no predecessor details are disclosed by this mutation.

Health/progress snapshots are append-only rows in `portfolio_projects_health_snapshots` with `snapshot_kind=health|progress`. Health rows contain RAG status plus narrative. Progress rows contain `reported_percent` (`0.00..100.00`), period start/end, summary, blockers, and next steps. The derived milestone percent is computed from completed milestone weights and is retained beside the report for historical reproducibility. Budget snapshots are append-only and only projected with `portfolio_projects.budget.read`.

## 6. Files to create, modify, move, or remove

### Module-owned files to create

```text
apps/api/Modules/PortfolioProjects/
  Contracts/
    ProjectAccessContext.php
    ProjectReference.php
    ResolveAuthorizedProjectReference.php
    AuthorizedProjectSummaryQuery.php
    AuthorizedProjectSummary.php
    AuthorizedProjectSummaryPage.php
    ListAuthorizedProjectSummaries.php
  Events/
    ProjectLifecycleChangedV1.php
    ProjectHealthSnapshotRecordedV1.php
    ProjectProgressReportedV1.php
    ProjectIndicatorLinkChangedV1.php
  Domain/
    ProjectLifecycle.php
    MilestoneLifecycle.php
    ProjectDependencySet.php
    ProjectProgress.php
  Features/Resources/Http/PortfolioResourceController.php
  Features/Resources/Handler/PortfolioResourceHandler.php
  Features/Projects/Http/ProjectLifecycleController.php
  Features/Projects/Handler/ProjectLifecycleHandler.php
  Features/Milestones/Http/ProjectMilestoneController.php
  Features/Milestones/Handler/ProjectMilestoneHandler.php
  Features/Snapshots/Http/ProjectSnapshotController.php
  Features/Snapshots/Handler/ProjectSnapshotHandler.php
  Features/IndicatorLinks/Http/ProjectIndicatorLinkController.php
  Features/IndicatorLinks/Handler/ProjectIndicatorLinkHandler.php
  Http/PortfolioProjectsHttp.php
  Infrastructure/Persistence/Migrations/CreatePortfolioProjectsTables.php
  Infrastructure/Persistence/PortfolioResourceRepository.php
  Infrastructure/Persistence/ProjectLifecycleRepository.php
  Infrastructure/Persistence/ProjectReportingRepository.php
  Infrastructure/Persistence/DatabaseResolveAuthorizedProjectReference.php
  Infrastructure/Persistence/DatabaseListAuthorizedProjectSummaries.php
  Providers/PortfolioProjectsServiceProvider.php
  Tests/Domain/ProjectLifecycleTest.php
  Tests/Domain/MilestoneLifecycleTest.php
  Tests/Domain/ProjectDependencySetTest.php
  Tests/Persistence/PortfolioProjectsMigrationTest.php
  Tests/PortfolioProjectsHttpAdapterTest.php
  Tests/PortfolioProjectsAuthorizationTest.php
  Tests/PortfolioProjectsAtomicityTest.php
  Tests/PortfolioProjectsContractsTest.php
  Tests/Integration/PortfolioProjectsConcurrencyTest.php
```

```text
apps/web/src/api/portfolio-projects.ts
apps/web/src/features/portfolio-projects/portfolio-projects-copy.ts
apps/web/src/features/portfolio-projects/PortfolioProjectsScreen.tsx
apps/web/src/features/portfolio-projects/PortfolioProjectsScreen.test.tsx
apps/web/src/features/portfolio-projects/ProjectDetailScreen.tsx
apps/web/src/features/portfolio-projects/ProjectDetailScreen.test.tsx
apps/web/src/features/portfolio-projects/ProjectMilestonesPanel.tsx
apps/web/src/features/portfolio-projects/ProjectDependenciesPanel.tsx
apps/web/src/features/portfolio-projects/ProjectProgressPanel.tsx
```

```text
docs/architecture/evidence/M05/manifest.yaml
docs/architecture/evidence/M05/outputs/
docs/architecture/evidence/M05/smoke/
```

### Serialized shared files to modify, never concurrently

| Queue | File | Exact M05 edit |
|---|---|---|
| Module registry | `apps/api/tests/Architecture/ModuleBoundariesTest.php` | Remove only `PortfolioProjects` from `PLANNED_MODULES`; retain rank 9; add exactly M00's nine table owners. |
| Migration registry | `apps/api/config/module_migrations.php` | Append `CreatePortfolioProjectsTables.php` in the M01→…→M07 queue after the M04 entry and before M06. |
| Composition root | `apps/api/app/Providers/AppServiceProvider.php` | Append `PortfolioProjectsServiceProvider::class` after M04 and before M06 in `MODULE_PROVIDERS`. |
| Laravel routes | `apps/api/routes/web.php` | Add `/api/v1/portfolio` GET routes to the session group and mutations to the session+CSRF group. |
| Contract | `docs/contracts/api/openapi.yaml` | Reconcile existing `/portfolio` operations and schemas with implemented dependency/progress fields and response/header semantics. |
| Generated contract | `apps/web/src/api/generated/cluster.ts` | Regenerate with Orval; never edit directly. |
| Web shell | `apps/web/src/shell/routes.ts`, `navigation.tsx`, their tests; `apps/web/src/app/WorkspaceContent.tsx`; `apps/web/src/app/copy.ts` | Add `/portfolio`, `/portfolio/projects/{projectId}`, capability-gated navigation, bilingual labels, and feature composition in the M05 shell slot. |
| Architecture docs | `docs/architecture/module-catalog.md` | Mark M05 implemented and list its frozen Contracts/Events/tables after runtime verification. |

No file is moved or removed. If M00's applied baseline already made one registry edit, M05 verifies it and does not repeat it.

## 7. Public Contracts, Events, routes, schemas, and capabilities

### Capabilities

Use exactly the existing codes:

| Capability | Operations |
|---|---|
| `portfolio_projects.portfolio.read` | list/get portfolio, program, and project-template resources. |
| `portfolio_projects.portfolio.manage` | create/patch portfolio, program, and project-template resources. |
| `portfolio_projects.project.read` | list/get projects, milestones, dependencies, safe health/progress reports, and indicator links. |
| `portfolio_projects.project.manage` | create/patch projects, replace dependencies, lifecycle except milestone approval, create/edit milestones, and record health/progress. |
| `portfolio_projects.milestone.approve` | approve a submitted milestone; treated as sensitive. |
| `portfolio_projects.impact.submit` | create or remove an indicator link and publish project impact/progress evidence. |
| `portfolio_projects.budget.read` | read budget snapshot fields; `project.manage` is still required to record a budget snapshot. |

### Routes and operation IDs

The prefix is exactly `/api/v1/portfolio` at runtime and `/portfolio` in the web shell. Preserve these authoritative operations:

- `GET|POST /portfolio/{portfolioResource}` → `listPortfolioResources`, `createPortfolioResource`; resource enum `portfolios|programs|projects|project-templates`.
- `GET|PATCH /portfolio/{portfolioResource}/{resourceId}` → `getPortfolioResource`, `updatePortfolioResource`.
- `POST /portfolio/projects/{projectId}/{projectAction}` → `transitionProject`; action enum `baseline|start|hold|resume|close|cancel`.
- `GET|POST /portfolio/projects/{projectId}/milestones` → `listProjectMilestones`, `createProjectMilestone`.
- `POST /portfolio/projects/{projectId}/{snapshotType}-snapshots` → `recordProjectSnapshot`; enum `budget|health`. `health` accepts the discriminated `health|progress` payload.
- `GET|POST /portfolio/projects/{projectId}/indicator-links` → `listProjectIndicatorLinks`, `createProjectIndicatorLink`.

The M05 contract token adds only the operations required to make existing aggregates operable:

- `PATCH /portfolio/projects/{projectId}/milestones/{milestoneId}` → `updateProjectMilestone` with milestone ETag.
- `POST /portfolio/projects/{projectId}/milestones/{milestoneId}/{milestoneAction}` → `transitionProjectMilestone`, actions `submit|approve|complete|cancel`, with `If-Match` and `Idempotency-Key`.
- `GET /portfolio/projects/{projectId}/health-snapshots` → `listProjectHealthSnapshots`, cursor/limit.
- `GET /portfolio/projects/{projectId}/budget-snapshots` → `listProjectBudgetSnapshots`, cursor/limit.
- `DELETE /portfolio/projects/{projectId}/indicator-links/{linkId}` → `deleteProjectIndicatorLink` with `Idempotency-Key`.

Dependencies are replaced through `PATCH /portfolio/projects/{projectId}` field `dependency_project_ids`; no second dependency route or table is introduced. Project ownership fields are `owner_organization_unit_id` and `accountable_user_id`. Progress reporting uses `ProjectSnapshotCreate.snapshot_kind=progress` on the existing health-snapshot command.

### Schema deltas

- `PortfolioResourceCreate` adds required `accountable_user_id` for projects and optional `dependency_project_ids` (unique UUIDv7 array, max 100). Other resource types reject project-only fields.
- `PortfolioResourcePatch` adds `accountable_user_id` and `dependency_project_ids`; project-only fields are rejected for other resource kinds.
- `MilestoneCreate` adds `owner_user_id`, `description`, and `planned_at`; weight is decimal `>0` and `<=100`.
- `ProjectSnapshotCreate` becomes a `oneOf` discriminated by `snapshot_kind=health|progress|budget`. Health requires `health_status` and narrative; progress requires period, reported percentage, summary, blockers, and next steps; budget requires currency and decimal amounts.
- Every entity response includes `ETag: "<lock_version>"`; every collection has bounded `items` and nullable opaque `next_cursor`, never `total`.

### Events

All event classes are immutable version-1 payloads and are appended through `Shared\Contracts\TransactionalOutbox`:

| Class | Type | Safe payload |
|---|---|---|
| `ProjectLifecycleChangedV1` | `com.cluster.portfolioprojects.projectlifecyclechanged.v1` | project ID, owner scope, old/new status, actor ID, lock version, occurred-at, correlation ID. |
| `ProjectHealthSnapshotRecordedV1` | `com.cluster.portfolioprojects.projecthealthsnapshotrecorded.v1` | project ID, owner scope, RAG status, derived progress, snapshot ID/time, classification. No narrative or budget. |
| `ProjectProgressReportedV1` | `com.cluster.portfolioprojects.projectprogressreported.v1` | project ID, owner scope, reported/derived percentages, period, snapshot ID/time, classification. No blockers or free text. |
| `ProjectIndicatorLinkChangedV1` | `com.cluster.portfolioprojects.projectindicatorlinkchanged.v1` | project ID, indicator ID, action `linked|unlinked`, baseline, expected impact, period ID, actor, occurred-at. |

M06 may consume M05 Contracts/Events only after `M06:project-integration`; M07 consumes `ListAuthorizedProjectSummaries` only after `M07:final-integration`. M05 does not add consumer bindings to either module.

## 8. Database tables, indexes, constraints, migration order, and recovery

`CreatePortfolioProjectsTables.php` creates exactly the M00-owned tables in this order:

1. `portfolio_projects_portfolios`
2. `portfolio_projects_programs`
3. `portfolio_projects_project_templates`
4. `portfolio_projects_projects`
5. `portfolio_projects_milestones`
6. `portfolio_projects_health_snapshots`
7. `portfolio_projects_budget_snapshots`
8. `portfolio_projects_indicator_links`
9. `portfolio_projects_idempotency_keys`

The canonical inventory is eight domain tables plus one idempotency table, nine M05-owned tables total; no module outbox or dependency/progress table is created.

### Required columns and constraints

- Every aggregate uses UUIDv7 `id`, `classification`, `created_at`, `updated_at`; mutable aggregates have unsigned `lock_version default 1`.
- Portfolios/programs/templates have `code`, `name`, `owner_organization_unit_id`, status, and lock version. Unique indexes are `(owner_organization_unit_id, code)` for portfolios, `(portfolio_id, code)` for programs, and `(owner_organization_unit_id, code)` for templates.
- Projects have nullable same-module `portfolio_id`, `program_id`, and `template_id`; required `code`, `name`, owner organization unit, accountable user, status, classification; planned/actual dates; `dependency_project_ids` JSON; cancellation reason; lock version. Indexes: unique `(owner_organization_unit_id, code)`, `(owner_organization_unit_id,status,updated_at,id)`, `(accountable_user_id,status,updated_at,id)`, and `(portfolio_id,status,id)`.
- Milestones have project FK, code, owner user, status, decimal weight `(5,2)`, due/planned/completed timestamps, lock version; unique `(project_id,code)` and index `(project_id,status,due_at,id)`.
- Health snapshots store project FK, `snapshot_kind`, captured/period times, RAG status, reported and derived decimal percentages, bounded text fields, actor and classification. Index `(project_id,snapshot_kind,captured_at,id)`.
- Budget snapshots store project FK, captured time, ISO-4217 currency, decimal approved/forecast/actual amounts, actor and classification. Index `(project_id,captured_at,id)`.
- Indicator links store project FK plus un-enforced cross-module `indicator_id`, nullable `period_id`, baseline/expected impact decimals, actor and timestamps; unique `(project_id,indicator_id,period_id)`.
- Idempotency rows contain principal ID, operation, SHA-256 key hash, request hash, response status/body, resource ID, and timestamps; unique `(principal_id,operation,key_hash)`. Raw idempotency keys are never stored or logged.
- MySQL JSON columns and SQLite JSON text must decode to a unique sorted UUIDv7 list in repository tests. Business validation enforces maximum 100 dependencies and cycle prevention.

`down()` drops tables in exact reverse order. Forward migration, rollback, second forward migration, row-count/schema evidence, and module registry exactness run against disposable databases. A production rollback after writes does not use destructive `migrate:rollback`; it uses application rollback plus forward-compatible schema until the recovery decision in section 13.

## 9. TDD implementation tasks

Every task begins with a failing observable test. Keep each review gate independent; do not run broad project suites until Task 11.

### Task 1: Freeze domain state machines and public DTOs

**Files:** Create `Domain/*.php`, `Contracts/*.php`, `Events/*.php`, and domain/contract tests listed in section 6.

- [ ] Write `ProjectLifecycleTest` cases for every allowed edge, every rejected edge, terminal states, baseline prerequisites, and close prerequisites. Write `MilestoneLifecycleTest` for its exact graph. Write `ProjectDependencySetTest` for normalization, self-link, duplicate, missing predecessor, 101-item limit, and multi-hop cycle.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/Domain Modules/PortfolioProjects/Tests/PortfolioProjectsContractsTest.php`.
  Expected: FAIL because the classes do not exist.
- [ ] Implement enum/value-object transitions and the exact frozen Contract/Event signatures from sections 5 and 7. DTO constructors reject malformed UUIDv7, cursor limit outside `1..100`, invalid status, or unsorted/duplicate summary items.
- [ ] Re-run the command. Expected: PASS with no database access.

### Task 2: Create schema, repositories, idempotency ledger, and provider

**Files:** Create the migration, four persistence adapters, provider, persistence/atomicity tests. Shared registry edits wait for Task 2's token.

- [ ] Write migration tests asserting the exact M00 tables, indexes, foreign keys only between M05 tables, JSON dependency round-trip, exact reverse rollback, and no extra `portfolio_projects_*` table.
- [ ] Write atomicity injection tests that fail after aggregate write, after idempotency insert, and before outbox append; every failure must leave zero partial rows.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/Persistence Modules/PortfolioProjects/Tests/PortfolioProjectsAtomicityTest.php`. Expected: FAIL because schema and repositories are absent.
- [ ] Implement one transaction per command, insert-or-replay idempotency behavior, CAS updates, deterministic cursor `(updated_at,id)` signing/verification using the repository's existing cursor convention, and production contract bindings in `PortfolioProjectsServiceProvider`.
- [ ] Acquire the M05 module-registry token after M00 and closure handoff. Apply the exact `ModuleBoundariesTest.php`, `module_migrations.php`, and `AppServiceProvider.php` edits from section 6 in one serialized slot.
- [ ] Re-run the targeted tests and `make verify-boundaries`. Expected: PASS; the directory is implemented, rank remains 9, every canonical table has exactly one owner, and no cross-owner SQL/import is reported.

### Task 3: Implement authorized resource CRUD and ownership

**Files:** Create `PortfolioResourceController`, `PortfolioResourceHandler`, `PortfolioProjectsHttp`, repository methods, and HTTP/authorization tests.

- [ ] Write failing tests for each resource enum, required ownership, project-only field rejection, duplicate code 409, denied/missing indistinguishable 404, bounded authorized list, ETag response, stale PATCH 412, CSRF 419, missing session 401, invalid correlation 400, and authorization-before-detailed-validation.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsHttpAdapterTest.php --filter=resource`. Expected: FAIL because routes/controllers do not exist.
- [ ] Implement handlers and direct test routes. Coarse authorization uses the owner scope from route/minimal body only; detailed errors are returned only after allow. Repository list predicates apply scope/classification before cursor and limit.
- [ ] Re-run the filtered tests. Expected: PASS; an unauthorized malformed request returns 403/404 rather than a validation oracle.

### Task 4: Implement project lifecycle and two-connection CAS

**Files:** Create project lifecycle controller/handler and concurrency tests; extend repository.

- [ ] Write failing HTTP tests for each transition, all prerequisites, reason requirement, terminal state, If-Match quoting, idempotent replay, same key/different body 409, and typed 409/412 problems.
- [ ] Write a MySQL test in which two connections read version N and concurrently start the same baselined project; assert one 200, one 412, lock version N+1, one lifecycle event, one audit effect, and one idempotency receipt for the winner.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsHttpAdapterTest.php --filter=lifecycle`. Expected: FAIL before handler implementation.
- [ ] Implement lifecycle transaction with `WHERE id=? AND lock_version=? AND status=?`, sanitized outbox event, and response ETag.
- [ ] Re-run the filtered test. Expected: PASS. Defer the MySQL command to Task 11's non-skipped gate.

### Task 5: Implement milestones and dependency replacement

**Files:** Create milestone controller/handler, extend resource handler/repositories, and tests.

- [ ] Write failing tests for milestone weight/dates, unique code, mutable-draft rule, approve capability, complete project-state guard, terminal behavior, CAS/idempotency, and baseline weight exactly 100.00.
- [ ] Write failing dependency tests for normalized order, duplicate removal rejection, missing/denied predecessor 404, self-link 409, two-node and deep cycles 409, maximum 100, stable lock order, and stale project ETag 412.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsHttpAdapterTest.php --filter='milestone|dependency'`. Expected: FAIL before handlers exist.
- [ ] Implement milestone commands and dependency replacement inside M05 persistence. Cycle detection traverses only M05 project dependency JSON under a transaction and locks affected project rows by ascending UUID; it never reads another module table.
- [ ] Re-run the filtered tests. Expected: PASS; failed cycles leave project JSON/version/outbox unchanged.

### Task 6: Implement health, progress, budget, and module-owned report reads

**Files:** Create snapshot controller/handler, reporting repository methods, tests.

- [ ] Write failing tests for all discriminated payloads, append-only behavior, invalid periods/percent/currency/decimal values, snapshot replay, chronological cursor pagination, latest snapshot selection, milestone-derived progress, budget field masking, and safe event payloads without narrative/blockers/budget.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsHttpAdapterTest.php --filter='snapshot|progress|budget|summary'`. Expected: FAIL before snapshot implementation.
- [ ] Implement health/progress in `portfolio_projects_health_snapshots`, budget in its canonical table, and summary queries through authorized repositories. Emit `ProjectHealthSnapshotRecordedV1` or `ProjectProgressReportedV1` according to `snapshot_kind`.
- [ ] Re-run the filtered tests. Expected: PASS; a reader without `budget.read` cannot infer amounts from list, error, event, or summary.

### Task 7: Integrate Strategy indicator links after M04

**Gate:** Remain `blocked` until `M04:CONTRACT-PUBLISHED`. Module-owned work before this phase may use a deterministic M04 contract implementation only in tests; no production fake binding may merge.

**Files:** Create indicator-link controller/handler, extend provider/repository/tests.

- [ ] Write contract tests with a test-only `ResolveStrategyReference` implementation for valid active indicator, absent/denied indicator, non-indicator type, retired indicator, scope mismatch, duplicate link, unlink replay, and event payload.
- [ ] After M04 production binding exists, construct the authenticated `StrategyAccessContext`, inject `ResolveStrategyReference`, and call `resolve($context, StrategyResourceType::Indicator, $indicatorId)` before the M05 transaction; absent, unauthorized, wrong-scope, draft/non-published, and wrong-type all return `null` without disclosure. Then revalidate immutable reference facts used by the command. Own only `portfolio_projects_indicator_links`.
- [ ] Re-run the test with both test double and production container binding assertion. Expected: PASS; the production container resolves M04's real adapter, and no M05 source imports `Modules\Strategy\Domain` or `Infrastructure`.

### Task 8: Process Laravel route and authoritative contract tokens

**Gate:** `ARCHITECTURE-CLOSURE:T12-HANDOFF`, M00 contract freeze, M01→M04 tokens merged, and M05 tokens granted.

- [ ] Add failing route inventory tests for every path/method/operation and middleware tests proving GET requires session while every mutation requires session plus CSRF.
- [ ] Acquire the Laravel route token and add exact routes/controllers to `apps/api/routes/web.php`; use route `whereIn` constraints for resource, action, snapshot, and milestone enums.
- [ ] Run `cd apps/api && php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsHttpAdapterTest.php`. Expected: PASS with real routes.
- [ ] Acquire the contract token, reconcile existing schemas/operations plus section 7 deltas in `docs/contracts/api/openapi.yaml`, then run `npm --prefix apps/web run api:generate` exactly once to update the generated client.
- [ ] Run `npm --prefix apps/web run api:check`. Expected: PASS and a second generation produces zero drift.

### Task 9: Build module-owned web API and accessible feature UI

**Files:** Create all M05 web files from section 6. Shared shell files remain untouched.

- [ ] Write failing Vitest/RTL tests for authorized list/detail, cursor load-more, portfolio/project create/edit, owner fields, lifecycle controls by status/capability, stale 412 reload choice, milestone approval, cycle conflict, health/progress report, budget masking, strategy link, scope-epoch refresh, denied/not-found parity, retry, focus restoration, keyboard operation, accessible names/live regions, RTL Arabic, and no sensitive browser persistence.
- [ ] Run `npm --prefix apps/web run test:unit -- src/features/portfolio-projects`. Expected: FAIL because feature files do not exist.
- [ ] Implement `portfolio-projects.ts` using generated operation functions and response types. Implement feature components with semantic headings/tables/forms, labelled controls, focus on the error summary, non-color-only health labels, 44×44 CSS-pixel targets, reduced-motion-safe feedback, and localized Arabic/English copy.
- [ ] Re-run the targeted command. Expected: PASS with zero unhandled promise rejections and no `localStorage`/`sessionStorage` use for project data.

### Task 10: Process the M05 web shell token

**Gate:** M01→M04 shell tokens merged, M05 shell token granted, and M05 feature tests passing.

- [ ] Add failing route/navigation tests for `/portfolio`, `/portfolio/projects/{uuidv7}`, malformed UUID to not-found, route round-trip, direct-route capability denial, active navigation, and bilingual labels.
- [ ] Modify only the shared shell files listed in section 6. Gate the overview on `portfolio_projects.portfolio.read|portfolio_projects.project.read`; gate detail on `portfolio_projects.project.read`; mutation buttons remain capability-gated inside features.
- [ ] Run `npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx src/features/portfolio-projects`. Expected: PASS.
- [ ] Release the shell token. Do not perform M07's final aggregation edit.

### Final M01 Audit integration gate (blocked only on M01 completion)
- [ ] After M01 completion, write producer-owned failing tests for every successful PortfolioProjects mutation; inject only `Modules\Audit\Contracts\RecordAuditEvent`, call it inside each existing mutation transaction, and prove injected Audit failure rolls back producer state, idempotency, PortfolioProjects outbox, and the Audit append. Keep this as a final integration/exit gate, not a core start dependency, and release the M01 integration packet.

### Task 11: Integrated verification, smoke test, evidence, and docs

- [ ] Create `apps/api/Modules/PortfolioProjects/Tests/PortfolioProjectsMySqlConcurrencyTest.php` and, under the serialized `MYSQL-SUITE` token, add exactly `<file>Modules/PortfolioProjects/Tests/PortfolioProjectsMySqlConcurrencyTest.php</file>` to `apps/api/phpunit.mysql.xml`. The class must fail unless `DB::connection()->getDriverName() === 'mysql'` and must cover forward/down/forward migration, transaction rollback, duplicate-code/idempotency races, deep dependency-cycle locking, and the two-connection lifecycle CAS from Task 4. Run `make verify-mysql-integration`; expected: exit 0, output names `Modules\\PortfolioProjects\\Tests\\PortfolioProjectsMySqlConcurrencyTest`, and that class reports a parsed test count and assertion count both greater than zero. Any absent class, SQLite driver, `SKIP`, warning-only discovery, or zero count exits nonzero and cannot populate the M05 MySQL manifest entry.
- [ ] Through P07's fixture integration queue, extend `apps/api/database/seeders/ProductionE2ESeeder.php` and P07's mode-`0600` seed JSON with the exact M05 schema below. P07 exports `P07_CONNECTION_MANIFEST_PATH=/run/cluster-p07/$P07_RUN_ID/connection-manifest.json`; dependent gates source its mode-`0600` companion `/run/cluster-p07/$P07_RUN_ID/connection-manifest.json.env`, and `P07_SEED_MANIFEST_PATH` names P07's mode-`0600` temporary seed JSON. `portfolio-projects.spec.ts` requires those runtime inputs plus `P07_RUN_ID`, `P07_COMMIT_SHA`, `P07_TEST_MATCH=portfolio-projects.spec.ts`, and `W1_1_ALLOW_SELF_SIGNED=1`; it reads no other connection source and never copies either connection file into the artifacts tree. Consume P07's canonical connection-manifest fields for HTTPS web/API origins, loopback port, Caddy CA bundle path/fingerprint, redacted MySQL/Redis/S3 endpoints, `P07_SCOPE`, route inventory, `P07_COMMIT_SHA`, and `P07_RUN_ID`; do not redefine that schema. Extend only the seed manifest with `schema_version`, `run_id`, `personas.{m05_manager,m05_project_reader,m05_budget_reader,m05_no_budget_reader,m05_under_capable}.{username,password,capabilities,organization_unit_id,facility_id}`, `m05.strategy_indicators.{authorized_active_id,denied_id,retired_id}`, `m05.portfolios.primary.{id,natural_key,etag}`, and `m05.projects.{primary,stale_writer,dependency_a,dependency_b,progress_health,budget}.{id,natural_key,etag}`. Passwords remain only in the temporary seed manifest; P07 `stop` deletes the seed manifest, connection manifest, and `.env` companion. The spec rejects absent/extra-version/mismatched-run-or-commit keys before login and must not invent IDs, invoke seeders, mock/intercept routes, bind a fake M04 resolver, or source inputs from prose.
- [ ] Create the exact real-browser spec `apps/web/e2e/portfolio-projects.spec.ts`, add only that filename to P07's production `testMatch` and live inventory under P07's spec-integration token, then execute `P07_TEST_MATCH=portfolio-projects.spec.ts ./infra/platform/production/run-local-e2e.sh` with `P07_COMMIT_SHA` exported. P07 owns the bounded `start → export connection/seed manifest → run dependent gates → run spec → stop with trap → prove cleanup` lifecycle. Before execution, parse Playwright `--list` output and require `portfolio-projects.spec.ts` with a test count greater than zero; missing fixture keys, unreachable live origins/readiness, route interception, zero discovery, skip/fixme/retry, or residual Compose resources is a nonzero failure, never a manifestable omission.
- [ ] Run every command in section 11 on one candidate commit. Any skip, stale output, generated diff, failure, missing M05 MySQL sentinel, or missing M05 browser discovery sentinel returns M05 to `in_progress` or `blocked`.
- [ ] Execute every named scenario in `apps/web/e2e/portfolio-projects.spec.ts` through Chromium → Caddy → production web/API containers → MySQL with the production container bindings. Retain one directory per scenario named exactly as section 11 under `docs/architecture/evidence/M05/smoke/`; each directory contains `result.json`, redacted `http-trace.json`, `browser-console.txt`, `screenshot.png`, and scenario-specific `assertions.json`; accessibility additionally contains `axe.json`. Database/outbox assertions go in `assertions.json`, never in prose.
- [ ] Write `docs/architecture/evidence/M05/manifest.yaml` with the schema in section 14 and copy command output under `outputs/`. Populate MySQL only from the registered-class nonzero sentinel and smoke only from the exact P07 spec's per-scenario artifacts; broad runner success or narrative cannot satisfy either entry.
- [ ] In the architecture-doc token, update `docs/architecture/module-catalog.md` with implemented status, canonical tables, Contracts/Events, and evidence manifest path. No unrelated documentation changes.
- [ ] After explicit user authorization only, record the resulting full commit SHA in `implementation_commit` and `last_verified_commit`. Without authorization, remain `verification` even if all local checks pass.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

| Condition | Required behavior |
|---|---|
| Missing/invalid session | 401 `authentication-required`; no database mutation. |
| Missing/invalid CSRF on mutation | 419 using existing Identity middleware; handler is not called. |
| Invalid/missing correlation UUIDv7 | 400 `invalid-correlation-id`; response generates no sensitive detail. |
| Capability denied | 403 before detailed create validation; item denial may be 404 to prevent enumeration. |
| Missing/denied item or M04 reference | Uniform 404 `resource-not-found`. |
| Invalid allowed request | 422 typed validation problem with field codes, not raw submitted values. |
| Invalid lifecycle/dependency state | 409 typed problem (`invalid-project-transition`, `project-dependency-cycle`, `milestone-weight-conflict`). |
| Missing or stale `If-Match` | 428 for missing precondition; 412 `precondition-failed` for stale CAS. Return current ETag only when authorization permits it. |
| Idempotency key first use | Hash key/body; commit state, receipt, audit/outbox atomically. |
| Same principal/operation/key/body replay | Return stored status/body/ETag and emit no second event. |
| Same key with different body | 409 `idempotency-key-reused`; no mutation. |
| Transaction/outbox failure | Roll back all aggregate, child, receipt, and outbox writes; return sanitized 503/500 correlation problem according to existing error policy. |
| M04 resolver unavailable in production | Fail closed with 503 `strategy-reference-unavailable`; never accept an unchecked link. |
| Cursor tamper/scope reuse | 400 `invalid-cursor`; cursor binds query kind, scope, sort position, and filters. |
| Retryable client failure | UI preserves non-sensitive form state in React memory only, announces error, and requires an explicit retry. |

Authorization facts use project owner scope, classification, accountable user, lifecycle, participants where applicable, and lock version. Explicit deny and field projection from `DecideAccess` override grants. Every list and report is bounded to 100. Budget, free-text health narrative, blockers, and next steps are excluded from Events and cross-module DTOs. Logs retain correlation ID, operation, actor ID, aggregate ID, decision ID, and result code only.

## 11. Targeted verification commands and smoke scenarios

Run commands from repository root unless the command contains `cd`. Retain complete output and exact exit status. Expected for each is exit 0; MySQL, browser, and accessibility gates must execute rather than report a skip.

```bash
cd apps/api && php artisan test Modules/PortfolioProjects/Tests
cd apps/api && APP_ENV=production php artisan test Modules/PortfolioProjects/Tests/PortfolioProjectsProductionBindingTest.php --fail-on-warning
make verify-boundaries
make verify-mysql-integration
composer --working-dir=apps/api lint
composer --working-dir=apps/api analyse -- --memory-limit=512M
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit -- src/features/portfolio-projects src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx
npm --prefix apps/web run build
P07_TEST_MATCH=portfolio-projects.spec.ts ./infra/platform/production/run-local-e2e.sh
make docs-validate
```

Expected outcomes:

- API M05 tests pass; rollback injection leaves no partial rows.
- `PortfolioProjectsProductionBindingTest` proves the production container resolves M04's real `ResolveStrategyReference` adapter and M05's real stores; authorized active references resolve, denied/retired references return `null`, and no fake/null/no-op or M04 Domain/Infrastructure import is reachable.
- Boundary gate reports rank 9, exact M00 table ownership, existing files, no forbidden imports/SQL.
- MySQL output names `Modules\\PortfolioProjects\\Tests\\PortfolioProjectsMySqlConcurrencyTest`, confirms driver `mysql`, and records nonzero tests/assertions; one CAS winner and one 412 occur. Absence, `SKIP`, zero discovery, or SQLite is failure.
- Lint/static analysis report zero M05 errors.
- `api:check` reports zero generated drift after a second generation check.
- Web tests and production build pass; no M05 bundle import crosses module boundaries.
- The P07 command starts the live topology, exports the connection/seed manifest, discovers only `apps/web/e2e/portfolio-projects.spec.ts` with a parsed count greater than zero, executes all named scenarios with zero skip/fixme/retry or interception, and proves trap cleanup.
- Docs validation resolves the M05 manifest, every per-scenario artifact, the nonzero MySQL sentinel, and module catalog evidence.

Required user-visible smoke scenarios, each implemented as a separately named Playwright test and evidence directory:

1. **`authorized-project-journey`:** Sign in with portfolio/project manage/read capabilities, create portfolio and project with an idempotency key, create milestones totaling 100, baseline and start using successive ETags, then see `active` status and the correct owner in `/portfolio/projects/{id}`. `assertions.json` records resource IDs, status, owner ID, successive ETags, idempotency replay count, and lifecycle/outbox counts.
2. **`stale-writer`:** Open one project in two browser contexts, edit in A, edit with stale ETag in B, observe 412 UI with reload action, and confirm A's data was not overwritten. `assertions.json` records A/B ETags, 412 problem type, final version, and unchanged winning values.
3. **`dependency-cycle`:** Create A→B, attempt B→A, observe localized cycle conflict, and confirm both versions/dependency sets remain unchanged after the rejected command. `assertions.json` records before/after dependency sets, versions, 409 problem type, and zero rejected-command outbox rows.
4. **`progress-and-health`:** Record amber health and 40% progress, reload, and observe latest RAG text plus reported/milestone progress. `assertions.json` records rendered values and the persisted/outbox payload key set, proving no narrative/blocker text.
5. **`budget-masking`:** A project reader without `budget.read` sees no budget field, masked amount, request, or error clue; a reader with it sees the retained snapshot. `assertions.json` records both persona keys, response field sets, network request inventory, and retained amount visibility only for the authorized reader.
6. **`strategy-link`:** Using the production M04 adapter, link the seeded authorized active indicator, reject seeded denied and retired indicators with identical 404 bodies, unlink once, replay the same key, and observe one link-change event per effective change. `assertions.json` records adapter class, opaque indicator IDs, normalized problem hashes, replay response, link-row count, and event counts.
7. **`authorization-and-csrf`:** A direct-route reader without capability receives the unified denied view; a mutation without CSRF is rejected; malformed secret fields do not appear in response or retained logs. `assertions.json` records status/problem types and redaction scan results.
8. **`accessibility-en-ar`:** Complete keyboard-only create/edit/lifecycle journeys in English and Arabic; verify visible focus, programmatic labels, error-summary focus, live status announcement, non-color-only RAG status, RTL layout, and automated WCAG 2.2 AA scan with zero serious/critical issues. Retain `axe.json` plus `assertions.json` listing keyboard steps, focused roles, locale/direction, and violation counts.

## 12. Shared-file integration token requirements

M05 has `shared_file_owner: []`; every shared edit is a temporary serialized token, not ownership. Queue order is M01 → M02 → M03 → M04 → M05 → M06 → M07.

For each token, the executor records `requested_at`, prerequisite handoff, base commit, granted owner, exact files/symbols, merge commit, and `released_at` in the orchestration status. A token is invalid if another branch holds it or if its base does not include prior module tokens.

1. **Registry token:** `ModuleBoundariesTest::PLANNED_MODULES`, `MODULE_RANKS`, `TABLE_OWNERS`; `config/module_migrations.php`; `AppServiceProvider::MODULE_PROVIDERS`. Requires closure T4/T6 handoff and M00 approval.
2. **MySQL-suite token:** `apps/api/phpunit.mysql.xml`; add exactly `Modules/PortfolioProjects/Tests/PortfolioProjectsMySqlConcurrencyTest.php` without replacing prior entries, then release after the named-class/nonzero sentinel passes.
3. **Laravel route token:** `apps/api/routes/web.php`. Requires closure T12 route handoff.
4. **Contract token:** master OpenAPI, then generated Orval output by command only. Requires closure T12/T13 handoff and prior module contract tokens.
5. **Web shell token:** route parser, navigation, WorkspaceContent, copy, and tests. M07 later owns final aggregation only.
6. **P07 fixture/spec token:** `ProductionE2ESeeder.php`, seed-manifest contract, production `testMatch`, and inventory; P07 retains runner/topology ownership and performs the single bounded live lifecycle.
7. **Architecture-doc token:** module catalog status/evidence only after verification.

M05 never requests production topology or CI/Make tokens. A conflict, stale base, or absent handoff moves only the affected phase to `blocked`; completed module-owned work remains reviewable.

## 13. Rollback procedure

### Before integration

Delete only the isolated M05 module-owned files and test-only bindings. No shared branch or database is affected.

### After shared integration but before production writes

Revert M05's serialized tokens in reverse order: shell, generated client through regeneration from the reverted OpenAPI, OpenAPI, routes, provider/migration registry, module boundary inventory. Revert module-owned API/web files in the same release. Do not leave aliases or routes pointing at removed handlers.

### After migrations contain data

1. Disable M05 navigation and mutation routing through a release rollback, not an improvised runtime flag.
2. Preserve M05 tables while the previous application release runs; do not invoke destructive `down()` against retained production data.
3. Export row counts and checksums for every canonical table and retain outbox/idempotency high-water marks.
4. If schema rollback is approved after backup verification, migrate data to an approved recovery artifact, execute `down()` in reverse order on a disposable restored copy first, verify non-M05 tables are unchanged, then run the authorized production recovery.
5. Replaying events uses outbox IDs and consumer idempotency; it never recreates command idempotency receipts or duplicates snapshots.

Rollback is complete only when `/api/v1/portfolio` no longer resolves in the reverted release, generated client matches reverted OpenAPI, boundary/route inventories pass, and no shared import references M05. A rollback failure leaves the plan `blocked`, never `completed`.

## 14. Exit criteria and required retained evidence

M05 can exit only when all conditions hold:

- M00 is approved; every M05 table/Contract/Event/capability/prefix exactly matches its frozen reservation.
- Module-owned implementation and every shared token are integrated and released in order.
- M04 production contract integration passes through `PortfolioProjectsProductionBindingTest`; the resolved class is M04's real adapter and no test contract implementation is bound in production.
- All commands in section 11 pass on one recorded commit; MySQL evidence names the registered M05 class with driver `mysql` and nonzero test/assertion counts, and accessibility evidence is not skipped.
- All eight separately named scenarios from `apps/web/e2e/portfolio-projects.spec.ts` pass against production container bindings inside P07's one bounded live topology, with nonzero discovery and complete per-scenario artifacts.
- OpenAPI, live routes, generated client, web wrappers, and UI callsites have zero drift.
- No forbidden cross-module import, SQL, FK, transaction ownership, raw outbox write, manual generated edit, or sensitive field leakage exists.
- M06 and M07 acknowledge the exact M05 published surface without requiring M05 internal access.
- Required evidence is retained under `docs/architecture/evidence/M05/` and resolves from the commit.
- There are no unapproved open findings or accepted risks. Any accepted risk includes user, scope, reason, expiry, and compensating control.

`manifest.yaml` uses the orchestration schema exactly:

```yaml
plan_id: M05
commit: '<full recorded commit SHA>'
started_at: '<ISO-8601 UTC>'
finished_at: '<ISO-8601 UTC>'
commands:
  - command: 'cd apps/api && php artisan test Modules/PortfolioProjects/Tests'
    exit_code: 0
    output_path: 'docs/architecture/evidence/M05/outputs/api-tests.txt'
  - command: 'make verify-mysql-integration'
    exit_code: 0
    output_path: 'docs/architecture/evidence/M05/outputs/mysql-integration.txt'
    sentinel:
      class: 'Modules\\PortfolioProjects\\Tests\\PortfolioProjectsMySqlConcurrencyTest'
      driver: mysql
      tests_gt: 0
      assertions_gt: 0
  - command: 'P07_TEST_MATCH=portfolio-projects.spec.ts ./infra/platform/production/run-local-e2e.sh'
    exit_code: 0
    output_path: 'docs/architecture/evidence/M05/outputs/portfolio-projects-e2e.txt'
    discovery:
      spec: 'apps/web/e2e/portfolio-projects.spec.ts'
      tests_gt: 0
      skipped: 0
      retried: 0
smoke_scenarios:
  - name: 'authorized-project-journey'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'authorized-project-journey', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/authorized-project-journey/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/authorized-project-journey/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/authorized-project-journey/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/authorized-project-journey/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/authorized-project-journey/assertions.json'
  - name: 'stale-writer'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'stale-writer', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/stale-writer/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/stale-writer/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/stale-writer/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/stale-writer/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/stale-writer/assertions.json'
  - name: 'dependency-cycle'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'dependency-cycle', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/dependency-cycle/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/dependency-cycle/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/dependency-cycle/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/dependency-cycle/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/dependency-cycle/assertions.json'
  - name: 'progress-and-health'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'progress-and-health', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/progress-and-health/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/progress-and-health/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/progress-and-health/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/progress-and-health/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/progress-and-health/assertions.json'
  - name: 'budget-masking'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'budget-masking', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/budget-masking/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/budget-masking/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/budget-masking/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/budget-masking/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/budget-masking/assertions.json'
  - name: 'strategy-link'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'strategy-link', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/strategy-link/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/strategy-link/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/strategy-link/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/strategy-link/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/strategy-link/assertions.json'
  - name: 'authorization-and-csrf'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'authorization-and-csrf', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/authorization-and-csrf/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/authorization-and-csrf/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/authorization-and-csrf/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/authorization-and-csrf/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/authorization-and-csrf/assertions.json'
  - name: 'accessibility-en-ar'
    result: pass
    provenance: { spec: 'apps/web/e2e/portfolio-projects.spec.ts', test_title: 'accessibility-en-ar', discovered: 1, executed: 1 }
    artifacts:
      result: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/result.json'
      http_trace: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/http-trace.json'
      browser_console: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/browser-console.txt'
      screenshot: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/screenshot.png'
      assertions: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/assertions.json'
      axe: 'docs/architecture/evidence/M05/smoke/accessibility-en-ar/axe.json'
open_findings: []
accepted_risks: []
```

The final manifest contains one entry for every section 11 command and all eight exact scenario names, plus token records, migration forward/down/forward evidence, the registered MySQL class/driver/nonzero test-and-assertion sentinel, M04 real-adapter class proof, P07 connection/seed manifest reference, exact-spec nonzero discovery, two-connection assertions, outbox payload redaction checks, browser console output, screenshots, and accessibility reports. Each smoke entry resolves to its required `result.json`, `http-trace.json`, `browser-console.txt`, `screenshot.png`, and `assertions.json`; `accessibility-en-ar` also resolves `axe.json`. Broad runner output, prose, evidence from another commit, missing output, or a hidden skip cannot populate the manifest and blocks completion.

## 15. Status transition rules

| Transition | Exact condition |
|---|---|
| `blocked → ready` | M00 approved, isolated worktree prepared, no conflicting module-owned path, and registry start gate recorded. M04 may remain a blocked later phase. |
| `ready → in_progress` | Executor and start commit recorded; first failing M05 test reproduced. |
| `in_progress → blocked` | The next required step lacks M04, current-closure handoff, a serialized token, environment prerequisite, or approval. Record the exact gate and continue independent M05 work. |
| `blocked → in_progress` | Named gate is satisfied on the current base and its evidence path is recorded. |
| `in_progress → verification` | Module-owned work, M04 integration, and all shared tokens are integrated; targeted red/green tests pass; no production fake binding remains. |
| `verification → in_progress` | A test, smoke scenario, drift check, review, or evidence audit fails and the defect is M05-owned. |
| `verification → blocked` | Verification cannot execute because of an external environment, handoff, authorization, or downstream merge prerequisite. Skipping is not success. |
| `verification → completed` | Section 14 is fully satisfied on one user-authorized recorded commit and orchestration records all M05 tokens released. |
| `* → superseded` | A later user-approved plan names this file, replacement scope, dependency/status changes, and migration/evidence disposition. |

Planning completion does not change the header status. This file remains `blocked` until an authorized executor satisfies the recorded start gate.
