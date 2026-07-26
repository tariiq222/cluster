# Cluster Workspace Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: M07
status: blocked
depends_on:
  - M00
blocks:
  - P07:production-execution
shared_file_owner:
  - apps/web/src/shell/routes.ts (final aggregation token only)
  - apps/web/src/shell/navigation.tsx (final aggregation token only)
  - apps/web/src/app/WorkspaceContent.tsx (final aggregation token only)
  - apps/web/src/features/requests/RequestForm.tsx (final aggregation token only)
  - apps/web/src/shell/routes.test.ts (final aggregation token only)
  - apps/web/src/shell/routes.capabilities.test.ts (final aggregation token only)
  - apps/web/src/shell/navigation.test.tsx (final aggregation token only)
  - apps/web/src/app/AppWorkspace.navigation.test.tsx (final aggregation token only)
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Deliver a user-scoped Workspace at `/workspace` that combines capability-filtered, independently recoverable widgets from M01–M06 and the existing task, notification, search, and reporting APIs, while persisting only the user's presentation preferences.

**Architecture:** `Workspace` is a rank-11 aggregation module. Its API performs bounded live reads through the six M00-published lower-rank query Contracts and owns only `workspace_preferences`; it never copies producer facts or publishes a Contract or Event. Because `Notifications`, `Search`, and `Reporting` are also rank 11, the PHP module must not import them: the React Workspace composes those APIs, plus Tasks, as independent browser-side widgets with per-widget loading, stale, partial, denied, empty, and error states.

**Tech stack:** PHP 8.3, Laravel 13.8, MySQL/SQLite migrations, PHPUnit 12, React 19, TypeScript 6, Vite 8, Vitest 4, Orval 8, Playwright 1.61.1.

---

## 1. Status header and dependency fields

The YAML header above is canonical. `M00` approval is the only start dependency. `M01`–`M06` are **not** promoted into start dependencies: shell-independent models, preference persistence, the aggregator, UI state machinery, and tests may be built against deterministic test-only fakes after `M00`; the production adapter and final aggregation phase is explicitly blocked until all six producers are merged and verified.

The current plan `docs/superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md` remains `in_progress` and retains its declared shared surfaces. M07 owns and may request only its final `WEB-SHELL` aggregation token after the releasing Architecture Closure task records the surface, base commit, grant evidence, and token expiry. Registry, capability, API-route, OpenAPI, Orval, provider, and migration-list changes are integration payloads submitted to their canonical queue owners; M07 neither owns nor edits those shared surfaces. No commit, push, deployment, migration, external message, or cloud operation is authorized by this plan. An implementation commit may be recorded only after explicit user authorization.

## 2. Goal and user-visible outcome

An authenticated user with `workspace.read` can open `/workspace` and see only widgets they are currently authorized to view. The page preserves useful widgets when another source fails, clearly labels stale retained data, supports keyboard and screen-reader navigation in Arabic RTL and English LTR, and refreshes when the effective principal scope or capability revision changes.

The Workspace includes:

- M01 audit activity, M02 records-governance summary, M03 visible collaboration threads, M04 strategy snapshot, M05 authorized project summaries, and M06 risk work items from one Workspace API request;
- existing Tasks data from `/api/v1/tasks`;
- existing Notifications data from `/api/v1/notifications`, reusing the shell's loaded collection rather than issuing a duplicate request;
- existing Reporting/Dashboard data from `/api/v1/dashboards`;
- Search as an explicit user-submitted query to `/api/v1/search`, never an automatic keystroke fan-out;
- a saved order, collapsed-widget list, and density preference, available only when `workspace.preferences.update` is granted.

A source that is denied is omitted rather than represented by a count or title that could disclose its existence. A failed source cannot blank successful widgets. A refresh failure retains the last successful in-memory widget value with a visible “stale” state; no producer data is written to browser storage.

## 3. Current source evidence

- `docs/architecture/module-catalog.md:20-34,183-199` places planned `Workspace` at rank 11 beside implemented `Notifications`, `Search`, and `Reporting`; `:200-228` requires cross-module use through lower-rank `Contracts/` or `Events/` only.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php` currently treats Workspace as planned. Materializing `apps/api/Modules/Workspace/` therefore requires the serialized `MODULE-REGISTRY` application that removes `Workspace` from `PLANNED_MODULES` and assigns `workspace_preferences` to Workspace in `TABLE_OWNERS`; rank 11 is already reserved by M00 and must not be changed.
- `apps/web/src/app/AppWorkspaceShell.tsx:33-124` owns typed history navigation, global-search state, notification pagination, and read state; `:202-269` feeds capability-filtered sidebar, notifications, scope, and routes into `WorkspaceContent`.
- `apps/web/src/app/WorkspaceContent.tsx:89-121` currently renders `WorkDashboard` for `{name: 'list'}`; `:172-258` independently renders Tasks, Notifications, Search, Reports, and Dashboards.
- `apps/web/src/features/dashboard/WorkDashboard.tsx:68-125` currently performs three browser reads with `Promise.allSettled`, request epochs, and per-source loading/denied/error handling. This is the behavior to preserve and generalize under the Workspace feature.
- `apps/web/src/shell/routes.ts:1-39` has no `workspace` variant. `ROUTE_WORKSPACE` is a total `Record<AppRoute['name'], …>`, and `pathFromRoute`, `routeFromPath`, `routeCapabilities`, `primaryRoutes`, and `isRouteVisible` are exhaustive typed gates.
- `apps/web/src/shell/navigation.tsx:109-136` currently exposes “home” as authenticated-only at `{name:'list'}`; this is not parity with the reserved `workspace.read` capability.
- `apps/api/routes/web.php:113-166,267-276` exposes the existing session-protected notification, search, reporting, dashboard, and task reads under `/api/v1`; notification read mutation also uses CSRF.
- `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/ListMyNotificationsController.php` applies recipient scoping, cursor binding, and source authorization re-evaluation. Workspace must reuse its web result and must not query notification tables.
- `apps/api/Modules/Search/Http/SearchController.php`, `apps/api/Modules/Reporting/Features/ListDashboards/Http/ListDashboardsController.php`, and `apps/api/Modules/Tasks/Features/Http/TaskController.php` are current public application boundaries. M07 must not import their Domain or Infrastructure code.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php:33-66,99-121` already lists Tasks, Search, Reporting, Notifications, Strategy, PortfolioProjects, and Risk capability codes. M00 adds only `workspace.read` and `workspace.preferences.update` for M07.
- `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md:218-256` reserves shared surfaces and gives M07 only final shell aggregation; `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md:174-237` defines the serial token protocol.

## 4. Scope and explicit non-goals

### In scope

- Materialize the M07 backend under `apps/api/Modules/Workspace/` after the module-registry token is applied.
- Live, bounded aggregation of the six canonical M01–M06 read Contracts.
- `GET /api/v1/workspace` and `PUT /api/v1/workspace/preferences` using session authentication, correlation IDs, capability checks, CSRF on mutation, problem+json, and `If-Match` optimistic at-most-once concurrency. The preference endpoint is the sole M00-defined exception to the shared `Idempotency-Key` rule: it uses `If-Match` plus full-replacement optimistic concurrency and is deliberately not replay-safe; it does not accept, persist, or honor an `Idempotency-Key` header.
- Persist `workspace_preferences` exactly as approved by M00.
- Replace the current personal home/dashboard presentation with a dedicated `/workspace` feature and a clean typed route cutover.
- Browser-side composition of Tasks, Notifications, Search, and Reporting without same-rank PHP imports.
- Arabic/English copy, RTL/LTR layout, WCAG 2.2 AA interaction evidence, targeted API/UI/E2E tests, performance evidence, rollback rehearsal, and retained verification evidence.

### Non-goals

- Owning, duplicating, projecting, caching, joining, or migrating any M01–M06, Tasks, Notifications, Search, Reporting, Workflow, or Documents data.
- Creating a `workspace_items`, projection, inbox, checkpoint, outbox, audit, or second idempotency table. M00 approved only `workspace_preferences`.
- Importing `Modules\Notifications`, `Modules\Search`, or `Modules\Reporting` from PHP; they are same-rank peers.
- Calling another module's Domain, Infrastructure, repository, or tables, or adding cross-owner SQL, joins, or foreign keys.
- Publishing an M07 Contract or Event. M00 reserves none.
- Replacing the specialized `/tasks`, `/notifications`, `/search`, `/reports`, or `/dashboards` screens.
- Automatically querying Search while typing, storing query/result data in preferences, localStorage, sessionStorage, URLs beyond the existing non-sensitive search query contract, logs, or error bodies.
- Hand-editing `apps/web/src/api/generated/cluster.ts` or creating a competing client.
- Editing `Makefile`, CI workflows, production topology, worker/scheduler scripts, or console routes.
- Any `apps/web/src/shell/routes.ts` edit beyond the exact Workspace cutover enumerated in §12.

## 5. Architecture and ownership boundaries

### Backend flow

`GetWorkspaceController` → correlation/session and `workspace.read` check → scope query validation → `GetWorkspaceHandler` → six explicitly ordered module adapters implementing the M07-internal `WorkspaceWidgetSource` interface → the M01–M06 published query Contracts. One GET loads preferences once and returns all permitted M01–M06 widgets. Each adapter invocation is isolated with its own capability check and result/error capture; a producer exception becomes that widget's generic error while the handler continues to the next source. `UpdateWorkspacePreferencesController` → session and `workspace.preferences.update` check → header/body validation → `UpdateWorkspacePreferencesHandler` → `WorkspacePreferencesRepository` → `workspace_preferences`.

The internal value model is:

```php
enum WorkspaceWidgetState: string { case Ready = 'ready'; case Empty = 'empty'; case Error = 'error'; }

final readonly class WorkspaceItemData
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $meta,
        public ?string $status,
        public ?string $occurredAt,
        public string $href,
    ) {}
}

final readonly class WorkspaceWidgetData
{
    /** @param list<WorkspaceItemData> $items @param array<string, int|string|null> $summary */
    public function __construct(
        public string $key,
        public WorkspaceWidgetState $state,
        public array $items,
        public array $summary,
        public string $fetchedAt,
        public bool $retryable,
    ) {}
}
```

The six source keys are exactly `audit`, `records_governance`, `collaboration`, `strategy`, `portfolio`, and `risk`. `GET /api/v1/workspace` invokes each permitted source at most once in this fixed order and requests at most five items or one bounded summary from each. Each invocation catches expected source unavailability/denial at its boundary; programming and schema errors are recorded with the correlation ID and serialized as a generic widget error without exception text, after which aggregation continues. Overall HTTP status remains 200 with `status: partial` when any permitted widget fails, because successful widgets remain valid. Authentication, invalid correlation/scope, or failure to load M07-owned preferences returns a normal problem+json response.

The six keys above are the **backend producer keys**: `audit` consumes `Modules\Audit\Contracts\QueryAuditActivity`; `records_governance` consumes `Modules\RecordsGovernance\Contracts\QueryRecordsGovernanceSummary`; `collaboration` consumes `Modules\Collaboration\Contracts\ListVisibleCollaborationThreads`; `strategy` consumes `Modules\Strategy\Contracts\GetStrategySnapshot` with `Modules\Strategy\Contracts\StrategyAccessContext`; `portfolio` consumes `Modules\PortfolioProjects\Contracts\ListAuthorizedProjectSummaries`; and `risk` consumes `Modules\Risk\Contracts\QueryRiskWorkspaceItems`. The four **browser-composed keys** are `tasks`, `notifications`, `reporting`, and `search`; the PHP aggregator never issues them and no `WorkspaceWidgetSource` implements them. `WorkspaceScreen` fetches Tasks from the generated `/api/v1/tasks` client, Reporting from the generated `/api/v1/dashboards` client, reuses the shell-owned `/api/v1/notifications` collection, and sends `/api/v1/search` only after explicit submit. The preference arrays therefore accept exactly ten keys = six backend producer keys + four browser-composed keys, in canonical order `["tasks", "notifications", "audit", "records_governance", "collaboration", "strategy", "portfolio", "risk", "reporting", "search"]`; unknown or duplicate keys return `invalid-preferences` 422. Browser-composed states remain only in `WorkspaceScreen` memory and never appear in the aggregate response.

### Canonical consumed Contracts

M07 consumes exactly:

- `Modules\Audit\Contracts\QueryAuditActivity::query(AuditActivityQuery): AuditActivityPage`, item `AuditActivityItem`;
- `Modules\RecordsGovernance\Contracts\QueryRecordsGovernanceSummary::forScope(RecordsGovernanceSummaryQuery): RecordsGovernanceSummary`;
- `Modules\Collaboration\Contracts\ListVisibleCollaborationThreads::list(CollaborationThreadQuery): CollaborationThreadPage`, item `CollaborationThreadSummary`;
- `Modules\Strategy\Contracts\GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot`;
- `Modules\PortfolioProjects\Contracts\ListAuthorizedProjectSummaries::list(AuthorizedProjectSummaryQuery): AuthorizedProjectSummaryPage`, item `AuthorizedProjectSummary`;
- `Modules\Risk\Contracts\QueryRiskWorkspaceItems::query(RiskWorkspaceQuery): RiskWorkspacePage`, item `RiskWorkspaceItem`.
For `strategy`, `StrategyWorkspaceSource` constructs `new StrategyAccessContext(principalId: $principal->id, facilityId: $principal->facilityId, organizationUnitIds: $authorizedOrganizationUnitIds, correlationId: $correlationId)` from the authenticated request and calls `GetStrategySnapshot::forOrganizationUnit($context, $organizationUnitId, $periodId)`. It never passes a raw principal, derives authorization from preferences, or calls the forbidden context-free signature. M04 remains authoritative for organization-unit and record-level authorization: a requested unit absent from `$context->organizationUnitIds` returns the same empty `StrategySnapshot` shape as an authorized unit with no published facts.

Before writing production adapters, inspect the merged constructors/public accessors for those DTOs and record the exact safe display mapping in `artifacts/program/M07/workspace-contract-map.json`. The gate passes only if every item exposes a stable opaque ID, non-sensitive display label, status/summary fields, updated/occurred timestamp, scope selector, and bounded limit/cursor input where the contract is paged. If a required display or bounding field is absent, the phase becomes `blocked` and requires an M00-approved contract amendment; the executor must not use reflection, array casting, Domain imports, repository access, invented fallback labels, or an unbounded query. Runtime/query budgets are M07 verification evidence, not producer Contract requirements.

### Capability and privacy matrix

| Widget | M07 pre-check before source call | Producer contract | Web navigation |
|---|---|---|---|
| audit | `audit.event.read` | `QueryAuditActivity` | `/audit` |
| records governance | any of `records_governance.record.read`, `records_governance.hold.read`, `records_governance.disposition.read` | `QueryRecordsGovernanceSummary` | `/records-governance` |
| collaboration | any of `collaboration.thread.read`, `collaboration.thread.list` | `ListVisibleCollaborationThreads` | `/collaboration` |
| strategy | `strategy.impact.read` | `GetStrategySnapshot` with authenticated `StrategyAccessContext` | `/strategy` |
| portfolio | any of `portfolio_projects.portfolio.read`, `portfolio_projects.project.read`, `portfolio_projects.budget.read` | `ListAuthorizedProjectSummaries` | `/portfolio` |
| risk | `risk.risk.read` | `QueryRiskWorkspaceItems` | `/risk` |
| tasks | any of `tasks.read`, `tasks.list` | existing generated HTTP client | `/tasks` |
| notifications | any of `notifications.read`, `notifications.list` | shell-owned existing HTTP collection | `/notifications` |
| reporting | `reporting.dashboard` | existing generated HTTP client | `/dashboards` |
| search | `search.query` | existing generated HTTP client, submit only | `/search` |

Authorization occurs before scope/body detail validation and before any source call. M07 checks current principal capabilities to avoid unnecessary calls; each producer remains responsible for record-level authorization. An omitted/denied widget has no count, item, timestamp, error detail, or presence marker in the response. Widget DTOs contain only the minimum label/status/timestamp/link needed for rendering; no PHI/PII, free-form comment body, audit fact payload, risk narrative, document title, notification body, or search content is copied into `workspace_preferences`, URLs, logs, metrics, or problem bodies.

### Web state and performance model

`WorkspaceScreen` runs one M07 aggregate request, one Tasks request, and one Reporting request concurrently with `Promise.allSettled`; the maximum initial fan-out is three HTTP reads. It reuses Notifications already loaded by `AppWorkspaceShell`. Search remains `idle` until explicit submit. The aggregate, Tasks, and Reporting requests each use an `AbortController` with a 2,500 ms browser deadline. Each web source owns an epoch/request ID so a late response from an aborted or old-scope request cannot overwrite current state. The aggregate response updates M01–M06 widgets independently from their returned states. If the aggregate request itself times out/fails, previous successful M01–M06 values become `stale` together; on first load they become `error`; Tasks/Reporting keep independent states. Missing authorization removes a widget, zero rows is `empty`, and other successful web widgets remain interactive. Stale data is cleared on logout, principal user change, or scope change and is never persisted in browser storage.

No backend projections are permitted: M00 explicitly reserves only `workspace_preferences`. Performance comes from one bounded aggregate HTTP request, one invocation and at most five returned items per permitted M01–M06 source, no N+1 mapping, notification reuse, search-on-submit, and web request coalescing. M07 measures per-adapter query counts/timings and an overall seeded aggregate p95 budget of 500 ms; a budget failure blocks M07 verification for investigation but does not amend or redefine upstream Contracts. The 2,500 ms browser abort is the hard user-visible ceiling for a stalled synchronous aggregate.

## 6. Files to create, modify, move, or remove

### M07-owned backend files

- Create `apps/api/Modules/Workspace/Application/WorkspaceItemData.php` — safe display DTO.
- Create `apps/api/Modules/Workspace/Application/WorkspaceWidgetData.php` — widget result and state.
- Create `apps/api/Modules/Workspace/Application/WorkspaceWidgetSource.php` — internal adapter interface, not a published cross-module Contract.
- Create `apps/api/Modules/Workspace/Application/WorkspacePreferences.php` — validated preference value object and default layout.
- Create `apps/api/Modules/Workspace/Features/GetWorkspace/Handler/GetWorkspaceHandler.php` — capability-filtered bounded aggregation.
- Create `apps/api/Modules/Workspace/Features/GetWorkspace/Http/GetWorkspaceController.php` — GET adapter.
- Create `apps/api/Modules/Workspace/Features/UpdateWorkspacePreferences/Handler/UpdateWorkspacePreferencesHandler.php` — idempotent optimistic update.
- Create `apps/api/Modules/Workspace/Features/UpdateWorkspacePreferences/Http/UpdateWorkspacePreferencesController.php` — PUT adapter.
- Create `apps/api/Modules/Workspace/Infrastructure/Persistence/WorkspacePreferencesRepository.php` — row lock and optimistic predicate; no replay ledger or idempotency storage.
- Create `apps/api/Modules/Workspace/Infrastructure/Persistence/Migrations/CreateWorkspacePreferencesTable.php` — sole M07 table.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/AuditWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/RecordsGovernanceWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/CollaborationWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/StrategyWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/PortfolioWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Infrastructure/Sources/RiskWorkspaceSource.php`.
- Create `apps/api/Modules/Workspace/Providers/WorkspaceServiceProvider.php` — production bindings only after M01–M06 contract gate.
- Create `apps/api/Modules/Workspace/Tests/WorkspaceAggregatorTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspacePreferencesTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspaceHttpAdapterTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspaceUpstreamContractCompatibilityTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspacePerformanceTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspaceProductionBindingsTest.php`.
- Create `apps/api/Modules/Workspace/Tests/WorkspaceMySqlConcurrencyTest.php`.
- Create `apps/api/Modules/Workspace/Tests/Support/CaptureProductionBindings.php` — executable read-only probe that boots the shipped Laravel container and emits the validated real interface→class map; it registers no binding.
- Create `apps/api/Modules/Workspace/Tests/Support/FailingRecordAuditEvent.php` — test-only M01 failure injection; never production-bound.

### M07-owned web feature files

- Move `apps/web/src/features/dashboard/dashboard-model.ts` to `apps/web/src/features/workspace/workspace-model.ts` and extend the state union and widget registry.
- Move `apps/web/src/features/dashboard/dashboard-model.test.ts` to `apps/web/src/features/workspace/workspace-model.test.ts`.
- Move `apps/web/src/features/dashboard/WorkDashboard.tsx` to `apps/web/src/features/workspace/WorkspaceScreen.tsx` and replace direct three-source assumptions with the defined composition.
- Move `apps/web/src/features/dashboard/WorkDashboard.css` to `apps/web/src/features/workspace/WorkspaceScreen.css`.
- Move `apps/web/src/features/dashboard/WorkDashboard.test.tsx` to `apps/web/src/features/workspace/WorkspaceScreen.test.tsx`.
- Create `apps/web/src/features/workspace/workspace-api.ts` — generated-client facade only; no hand-written transport duplication.
- Create `apps/web/src/features/workspace/workspace-api.test.ts` — request headers, ETag/If-Match, no-`Idempotency-Key`, stale-retry 412, and problem mapping.
- Create `apps/web/src/features/workspace/WorkspaceWidget.tsx` — semantic widget shell and retry/stale rendering.
- Create `apps/web/src/features/workspace/WorkspaceWidget.test.tsx`.
- Create `apps/web/e2e/workspace.spec.ts` — user-visible, keyboard, scope-race, and partial-source journeys.

### Shared integration payloads (M07 does not own these surfaces)

M07 supplies exact, reviewable payloads and focused failing tests to the canonical integrators; its executor does **not** edit these files:

- `apps/api/tests/Architecture/ModuleBoundariesTest.php` — `MODULE-REGISTRY` owner applies: remove `Workspace` from `PLANNED_MODULES`; retain rank 11; add `'workspace_preferences' => 'Workspace'` to `TABLE_OWNERS`.
- `docs/architecture/module-catalog.md` — registry owner moves Workspace from planned to implemented and lists its one table, two routes, two capabilities, and no published Contract/Event.
- `apps/api/config/module_migrations.php` — registry owner appends the M07 migration after lower-rank module migrations.
- `apps/api/app/Providers/AppServiceProvider.php` — registry owner adds `WorkspaceServiceProvider` after M01–M06 providers.
 - `apps/api/phpunit.mysql.xml` — the serialized `MYSQL-SUITE` owner registers exactly one `<file>Modules/Workspace/Tests/WorkspaceMySqlConcurrencyTest.php</file>` entry; the existing runner proves class discovery and executes it. Do not edit `scripts/run-mysql-integration-tests.sh`.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php` and its focused test — M00 capability integrator adds exactly the two reserved M07 codes.
- `apps/api/routes/web.php` — `API-ROUTES` owner registers both reserved routes with session middleware; PUT also receives Identity CSRF middleware.
- `docs/contracts/api/openapi.yaml` — `OPENAPI` owner adds both operations and component schemas from §7.
- `apps/web/src/api/generated/cluster.ts` — `ORVAL` owner runs `npm run api:generate`; nobody hand-edits generated output.

M07's only shared edit is the final `WEB-SHELL` aggregation token. The canonical YAML metadata names only its ownership anchor, `apps/web/src/shell/routes.ts (final aggregation token only)`; that anchor does not narrow or broaden the grant. The grant payload is exactly these eight files and no wildcard: `apps/web/src/shell/routes.ts`, `apps/web/src/shell/navigation.tsx`, `apps/web/src/app/WorkspaceContent.tsx`, `apps/web/src/features/requests/RequestForm.tsx`, `apps/web/src/shell/routes.test.ts`, `apps/web/src/shell/routes.capabilities.test.ts`, `apps/web/src/shell/navigation.test.tsx`, and `apps/web/src/app/AppWorkspace.navigation.test.tsx`. Any ninth shared file requires a new recorded grant; M07-owned `features/workspace/**` moves and deletion of the emptied `features/dashboard/**` directory are outside the shared token.

Remove the now-empty `apps/web/src/features/dashboard/` directory after imports and tests move. Do not leave a re-export, alias, compatibility route, or second dashboard implementation.

## 7. Public Contracts, Events, routes, schemas, and capability names

M07 publishes **no** cross-module Contract and **no** Event. It consumes only the six exact Contracts listed in §5. It does not consume the listed M01–M06 Events and does not add an `OutboxEventType` case or JSON event schema.

### Routes

- `GET /api/v1/workspace?scope_id=<uuid-v7>` — session required; `workspace.read`; returns all permitted M01–M06 widgets plus preferences in one 200 complete/partial response with ETag and `X-Correlation-ID`.
- `PUT /api/v1/workspace/preferences` — session + CSRF required; `workspace.preferences.update`; requires `If-Match` only and no `Idempotency-Key`. A successful full replacement always increments `lock_version`, writes exactly one M01 audit record, returns the next strong ETag, and is at-most-once under optimistic concurrency. A retry carrying the now-stale `If-Match` returns 412 and writes no state, version, audit, or outbox effect; the client must read the current representation before another user-intended write.
- `/workspace` — typed React route gated by `workspace.read`.

### Response schema

```json
{
  "data": {
    "status": "complete",
    "scope_id": "018f6f7d-0c00-7000-8000-000000000001",
    "preferences": {
      "version": 1,
      "ordered_widgets": ["tasks", "notifications", "audit", "records_governance", "collaboration", "strategy", "portfolio", "risk", "reporting", "search"],
      "collapsed_widgets": [],
      "density": "comfortable",
      "lock_version": 0
    },
    "widgets": [
      {
        "key": "audit",
        "state": "ready",
        "items": [],
        "summary": {},
        "fetched_at": "2026-07-26T12:00:00Z",
        "retryable": true
      }
    ]
  }
}
```

`status` is `complete` or `partial`; widget `state` is `ready`, `empty`, or `error`. Denied widgets are omitted. Items use `id`, `title`, `meta`, `status`, `occurred_at`, and `href`; all nullable fields are explicit JSON null. Unknown fields are rejected on preference writes.

### Preference write schema

```json
{
  "data": {
    "version": 1,
    "ordered_widgets": ["tasks", "notifications", "audit", "records_governance", "collaboration", "strategy", "portfolio", "risk", "reporting", "search"],
    "collapsed_widgets": ["search"],
    "density": "compact"
  }
}
```

The two arrays contain only the ten canonical keys, contain no duplicates, and contain at most ten entries. Every collapsed key must occur in `ordered_widgets`. `density` is `comfortable` or `compact`. A missing preference row yields the default representation and ETag `"workspace-preferences-v0"` without inserting a row.

### Problem behavior

All failures use `application/problem+json`, `https://cluster.example/problems/<type>`, and the same valid correlation ID in response header and retained logs. Required types are: `invalid-correlation-id` 400, `invalid-query` 400, `invalid-preferences` 422, `authentication-required` 401, `access-denied` 403, `precondition-required` 428, `stale-write` 412, and `workspace-unavailable` 503. `Idempotency-Key` is not accepted or persisted for this endpoint, so idempotency-key-required/reused problems do not exist. No problem detail includes user, scope, source item, PHI/PII, SQL, or exception text.

## 8. Database tables, indexes, constraints, migration order, and rollback/recovery

`workspace_preferences` is the only M07 table:

| Column | Definition |
|---|---|
| `user_id` | UUID primary key; logical reference to Identity, no cross-module FK |
| `layout_json` | JSON, required; validated version/order/collapsed/density only |
| `lock_version` | unsigned bigint, required, default 0 |
| `created_at`, `updated_at` | UTC timestamps |

No secondary index is justified: every read/write is by the primary user ID. No producer identifier, widget item, query, notification, report, task, audit fact, or scope fact is persisted. The JSON constraint is enforced by MySQL/SQLite JSON column semantics plus application validation; preference ordering is the only stored business state.

Migration order is: M00 registry decisions → M01 → M02 → M03 → M04 → M05 → M06 → M07 migration, applied through the serialized module registry queue. `down()` drops only `workspace_preferences`. A rollback exports only the preference rows needed for support evidence, disables the two Workspace routes, restores the previous shell token, rolls back the M07 migration, removes provider/migration/registry entries, and confirms all producer tables and APIs are untouched. Recovery is deterministic: a missing row returns defaults, so loss of this table loses presentation customization only and never producer data.

## 9. TDD implementation tasks

### Task 1: Freeze executable M07 boundaries and test-only source fakes

**Files:** create the M07 `Application/*` value types/interfaces and `apps/api/Modules/Workspace/Tests/WorkspaceAggregatorTest.php`; create `artifacts/program/M07/workspace-contract-map.json` only when executing the plan.

- [ ] Write failing tests proving: only the six canonical source keys are registered; denied sources are never invoked; one source exception yields one generic retryable `error` widget while later sources still run and successful siblings survive; every permitted source receives the authenticated scope and a limit of five; each source is invoked at most once; output drops unapproved fields.
- [ ] Run `cd apps/api && php artisan test Modules/Workspace/Tests/WorkspaceAggregatorTest.php`.
  Expected: FAIL because `WorkspaceWidgetSource`, value types, and handler do not exist.
- [ ] Implement the minimal internal interface and immutable DTOs shown in §5, plus deterministic fakes under the test namespace only. No production fake binding is permitted.
- [ ] Run the same command.
  Expected: PASS for bounded aggregate selection, source isolation, partial results, and sanitized output.

### Task 2: Add preference persistence and concurrency semantics

**Files:** create migration, repository, preference value object/handler, and `WorkspacePreferencesTest.php`.

- [ ] Write failing tests for default-without-insert, valid insert from ETag v0, update predicate `user_id + lock_version`, concurrent v0 insert conflict, 412 stale write, one successful full replacement minting exactly one new version and one M01 audit row, stale retry writing nothing, absence of `Idempotency-Key`/fingerprint/replay state, and rollback/re-migrate. Add transaction-failure injection at each boundary: after preference state mutation but before version update, after version update but before `RecordAuditEvent::record`, and a `FailingRecordAuditEvent` that throws inside the shared transaction. Each injected failure must leave layout, `lock_version`, and M01 `audit_events` unchanged and append zero outbox rows.
- [ ] Run `cd apps/api && php artisan test Modules/Workspace/Tests/WorkspacePreferencesTest.php`.
  Expected: FAIL because table/repository/handler and the M01 audit integration do not exist.
- [ ] Implement one module-owned transaction. Lock the existing row; for a new row insert at version 1 and convert unique-key races to 412; for updates use `WHERE user_id = ? AND lock_version = ?`, increment exactly once, and fail with 412 if affected rows is not one. A successful full replacement invokes `Modules\Audit\Contracts\RecordAuditEvent::record(AuditEventInput $input)` synchronously before commit with action `workspace.preferences.update`, source module `workspace`, subject type `workspace_preferences`, subject ID `user_id`, current actor/correlation, `success` outcome, `Confidential` classification, and safe context limited to old/new lock versions plus changed preference field names. Store no request key, hash, replay ledger, or response snapshot.
- [ ] Run the focused test.
  Expected: PASS with preference state, optimistic version, at-most-once retry behavior, and exactly one M01 audit record committed atomically for each successful write; stale retries and every injected failure roll state/version/audit back; zero Workspace domain Events and zero outbox rows exist.

This is the explicit M00 Workspace exception. Strong `If-Match` provides optimistic at-most-once behavior without an `Idempotency-Key` or replay store; the endpoint is deliberately not replay-safe. A successful replacement atomically commits preference state, optimistic version, and the M01 audit record. It publishes no M07 domain Event and appends no outbox row because preference presentation has no downstream side effect; zero outbox is deliberate contract behavior, not an omitted effect or a no-op. The existing `DecideAccess` access-decision record is distinct from this required M01 mutation audit.

### Task 3: Implement the Workspace HTTP adapters

**Files:** create both controllers, provider, and `WorkspaceHttpAdapterTest.php`. Produce, but do not apply, the exact route/provider integration payloads for their canonical owners.

- [ ] Write failing HTTP tests that assert authorization precedes scope validation; invalid correlation IDs fail; one GET returns preferences and all permitted widgets with ETag/correlation; denied widgets are omitted; one source error yields 200 partial without suppressing successful widgets; PUT requires session, CSRF, capability, and `If-Match` but no `Idempotency-Key`; one successful write returns the next ETag and one M01 audit row; retry with the stale ETag returns 412 and writes nothing; missing `If-Match` returns 428; problem bodies contain no source exception.
- [ ] Run `cd apps/api && php artisan test Modules/Workspace/Tests/WorkspaceHttpAdapterTest.php`.
  Expected: FAIL because routes/controllers are absent.
- [ ] Implement controllers as thin adapters and handlers as the application boundary. Use the existing Identity session principal and `DecideAccess`; do not use `ResolveDevelopmentFixturePrincipal` as a production authentication substitute.
- [ ] Run the focused test.
  Expected: PASS and response `Content-Type`/correlation/ETag headers match the contract.

### Task 4: Integrate the six production source adapters — blocked phase

**Gate:** M01–M06 are merged; their focused tests pass; the exact six Contracts and DTO constructors/accessors match the M00 matrix; no producer uses a test fake in production.

- [ ] Write `WorkspaceUpstreamContractCompatibilityTest.php` using direct type construction and method calls for all six canonical signatures. Include one allowed, one empty, one denied, and one thrown-source fixture per adapter. For M04 construct `StrategyAccessContext` with exact `principalId`, `facilityId`, `organizationUnitIds`, and `correlationId`, call `forOrganizationUnit($context, $organizationUnitId, $periodId)`, prove an allowed unit returns published safe facts, and prove an absent unit returns the same empty snapshot as an authorized-empty unit. Reflection must reject the stale context-free signature.
- [ ] Run `cd apps/api && php artisan test Modules/Workspace/Tests/WorkspaceUpstreamContractCompatibilityTest.php`.
  Expected: FAIL until every production adapter is bound, `StrategyWorkspaceSource` passes the canonical authorization context, and all adapters return only `WorkspaceItemData`/summary allowlist fields.
- [ ] Implement the six adapters in `Infrastructure/Sources/` and bind them explicitly in `WorkspaceServiceProvider`. `StrategyWorkspaceSource` must construct `StrategyAccessContext` only from the authenticated principal/scope/correlation supplied by the handler and pass it to M04; it must never reconstruct authorization from raw organization-unit IDs. Do not discover sources dynamically and do not import producer Domain/Infrastructure.
- [ ] Run the focused compatibility and aggregator tests.
  Expected: PASS with exactly six lower-rank Contract imports, canonical M04 producer-side authorization, no context-free Strategy call, and no same-rank PHP import.

### Task 5: Hand the HTTP contract payload to canonical integrators

**Gate:** Architecture Closure releases master OpenAPI/generated-client ownership; the canonical `API-ROUTES`, `OPENAPI`, and `ORVAL` owners accept payloads in that order. M07 does not edit those shared files.

- [ ] Add failing M07-owned contract assertions to `WorkspaceHttpAdapterTest.php` for the exact §7 aggregate schema, headers, and problems.
- [ ] Produce exact route registration and OpenAPI operation/component payloads for the queue owners, including focused expected assertions and generation command. The API-route owner applies routes, the OpenAPI owner applies the contract, and the Orval owner runs `cd apps/web && npm run api:generate`.
- [ ] After the owners merge their payloads, run `cd apps/web && npm run api:check`.
  Expected: PASS with zero generated drift and no hand-written generated-client diff; token evidence identifies the canonical integrators, not M07, as owners.

### Task 6: Build the shell-independent Workspace web model

**Files:** move dashboard model/tests into `features/workspace/`, create widget component/tests and API facade/tests.

- [ ] Write failing Vitest cases for all state transitions: idle→loading→ready/empty; first error; previous success→refresh error→stale; stale→retry→ready; denied removal; partial page announcement; scope/principal epoch dropping late responses; capability-filtered preference ordering; no browser storage writes.
- [ ] Run `cd apps/web && npm run test:unit -- src/features/workspace`.
  Expected: FAIL because Workspace files are absent.
- [ ] Implement a discriminated `WidgetLoadable<T>` union (`idle | loading | ready | empty | stale | error`), pure layout filtering, and semantic `WorkspaceWidget` with a heading, status text, retry button, `aria-busy`, and non-color-only state label.
- [ ] Run the focused command.
  Expected: PASS in Arabic and English cases.

### Task 7: Compose API and shell-provided widgets

**Files:** create `WorkspaceScreen.tsx`, CSS, tests; consume generated Workspace/Tasks/Reporting clients and shell-provided Notifications.

- [ ] Write failing component tests proving one M07 aggregate, Tasks, and Reporting request launch concurrently; notification reuse causes no duplicate request; Search stays idle until submit; a 2,500 ms aggregate timeout changes retained M01–M06 widgets to stale together without affecting Tasks/Reporting; aggregate retry does not refetch Tasks/Reporting/Notifications; successful partial widgets render independently; only capability-permitted widgets render; preference update uses CSRF and the current ETag without `Idempotency-Key`; a stale 412 reloads preferences without overwriting and requires a fresh user intent before resend.
- [ ] Run `cd apps/web && npm run test:unit -- src/features/workspace/WorkspaceScreen.test.tsx`.
  Expected: FAIL before composition exists.
- [ ] Implement the minimal screen with the exact ten-key layout. Retain old data only in component memory, abort each of the three initial requests at 2,500 ms, epoch-suppress obsolete results, and refetch on principal revision, scope ID, or scope epoch change.
- [ ] Run the focused test.
  Expected: PASS with no localStorage/sessionStorage calls and no duplicate notifications fetch.

### Task 8: Apply the final web-shell aggregation token

**Gate:** M01–M06 route/OpenAPI/Orval integrations are merged; Architecture Closure released the shell; M07 holds the only granted `WEB-SHELL` token on a recorded base commit. The grant record lists exactly the eight-file payload in §6 and uses `apps/web/src/shell/routes.ts` as its metadata anchor.

- [ ] In the four token-owned tests — `apps/web/src/shell/routes.test.ts`, `apps/web/src/shell/routes.capabilities.test.ts`, `apps/web/src/shell/navigation.test.tsx`, and `apps/web/src/app/AppWorkspace.navigation.test.tsx` — expect a `workspace` route at `/workspace`, root `/` parsing to Workspace, no `list` route, `workspace.read` guard/sidebar parity, and denied/loading capability snapshots hiding the entry.
- [ ] Run `cd apps/web && npm run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx`.
  Expected: FAIL because the route does not exist and home remains authenticated-only.
- [ ] Within the same eight-file grant, apply only these `apps/web/src/shell/routes.ts` changes: replace `{name:'list'}` with `{name:'workspace'}`; replace its total `ROUTE_WORKSPACE` key; replace its `primaryRoutes` entry with `/workspace`; serialize `workspace` to `/workspace`; parse `/` and `/workspace` as `workspace`; classify it as `['workspace.read']`; verify neither Workspace capability is in `DEFERRED_CAPABILITIES`. Every other route mapping, regex, capability, and deferred entry is unchanged.
- [ ] Still within that grant, change `apps/web/src/shell/navigation.tsx` home to `{name:'workspace'}` with `anyOf(['workspace.read'])`; change `apps/web/src/app/WorkspaceContent.tsx` to render `WorkspaceScreen` for `workspace`; and change `apps/web/src/features/requests/RequestForm.tsx` back navigation to `workspace`. Touch no shared file outside the four implementation files and four tests enumerated in §6. The already M07-owned dashboard-to-workspace moves remove the old files; do not add a compatibility re-export or second `/home`/`/work-records` route.
- [ ] Run the focused command again.
  Expected: PASS with sidebar/direct-route guard parity and token evidence whose anchor is `routes.ts` and whose payload contains exactly the eight enumerated paths.

### Task 9: Prove privacy, performance, real bindings, MySQL execution, and live P07 journeys

**Status gate:** All M01–M06 production adapters, M01 audit integration, API/OpenAPI/Orval payloads, the eight-file `WEB-SHELL` token, the `MYSQL-SUITE` registration, and P07's bounded runner/fixture queue are merged on the recorded M07 commit. Failure of any step below keeps M07 in `in_progress` or returns it to `blocked`; no focused fake or local-only browser run may substitute.

- [ ] Seed deterministic allowed/denied/empty/error/slow data in module tests only and write `WorkspacePerformanceTest.php` to assert one invocation per permitted adapter, continued invocation after a source error, no producer-table query originates in M07, no per-item queries occur, per-adapter SQL query counts/timings are recorded, and the 30-item aggregate's seeded p95 is at most 500 ms. Write web fake-timer tests for the 2,500 ms aggregate deadline and aggregate-only retry. These fakes prove failure-state behavior only and are never production evidence.
- [ ] Run `cd apps/api && php artisan test Modules/Workspace/Tests/WorkspacePerformanceTest.php` and `cd apps/web && npm run test:unit -- src/features/workspace/WorkspaceScreen.test.tsx`.
  Expected: PASS for bounded aggregate fan-out, source-error isolation, measured query/runtime budget, aggregate timeout, aggregate-only retry, and late-result suppression.
- [ ] Implement `WorkspaceProductionBindingsTest::test_production_container_resolves_every_real_adapter` and the read-only `CaptureProductionBindings.php` probe, then run `cd apps/api && APP_ENV=production php artisan test Modules/Workspace/Tests/WorkspaceProductionBindingsTest.php --fail-on-warning`. Resolve the exact six M07 classes `AuditWorkspaceSource`, `RecordsGovernanceWorkspaceSource`, `CollaborationWorkspaceSource`, `StrategyWorkspaceSource`, `PortfolioWorkspaceSource`, and `RiskWorkspaceSource`, plus all six consumed Contract bindings and `Modules\Audit\Contracts\RecordAuditEvent`. Assert every resolved class is concrete and its namespace/class name contains none of `Tests`, `Fake`, `Fixture`, `InMemory`, `Null`, or `Noop`; assert Strategy resolves `DatabaseGetStrategySnapshot` and the adapter invokes it with `StrategyAccessContext`. The probe boots `/var/www/html/bootstrap/app.php`, performs the same assertions without PHPUnit, emits JSON, and exits nonzero on any mismatch.
  Expected: PASS in `APP_ENV=production`; any missing/null/fake binding, stale Strategy signature, or test service provider fails before M07 enters verification. Retain host test output at `artifacts/program/M07/production-bindings-test.txt`; P07 separately runs the probe inside the shipped API container.
- [ ] Create `WorkspaceMySqlConcurrencyTest.php` with an explicit `setUp()` assertion that the driver is exactly `mysql`, never a skip. Use two independent connections to prove one winner/one 412 loser for initial insert and update races, one version and one M01 audit row for the winner, stale retry writing nothing, and injected M01 audit failure rolling preference/version/audit back with zero outbox rows. The `MYSQL-SUITE` owner adds exactly one `<file>Modules/Workspace/Tests/WorkspaceMySqlConcurrencyTest.php</file>` entry to `apps/api/phpunit.mysql.xml`; the existing runner supplies discovery and execution.
- [ ] Run the existing MySQL runner with its `--list-tests`/class-discovery mode, then execute the suite. Expected: `Modules\Workspace\Tests\WorkspaceMySqlConcurrencyTest` is discovered and runs on driver `mysql` with `Skipped: 0`; no runner-script grep/count or literal registration count is used.
- [ ] Through P07's fixture queue, extend exact owner surfaces `apps/api/database/seeders/ProductionE2ESeeder.php`, `apps/web/e2e/fixtures/production-fixtures.ts`, and `apps/web/playwright.production.config.ts` with these inputs keyed by `P07_RUN_ID`: personas `p07-workspace-full`, `p07-workspace-limited`, and `p07-workspace-denied`; one `workspace_scope_id`, facility ID, authorized and denied organization-unit IDs, Strategy period ID, CSRF-capable session credentials, and initial Workspace ETag; one real persisted item/summary for each backend producer key `audit`, `records_governance`, `collaboration`, `strategy`, `portfolio`, and `risk`; one real Task, one worker-delivered Notification, one Reporting dashboard, and one searchable record for browser keys `tasks`, `notifications`, `reporting`, and `search`. `p07-workspace-full` receives `workspace.read`, `workspace.preferences.update`, and every widget read capability; `p07-workspace-limited` receives `workspace.read` but lacks `audit.event.read` and `strategy.impact.read`; `p07-workspace-denied` lacks `workspace.read`. Export only opaque IDs, persona keys, scope/unit IDs, initial ETag, and CSRF/session fixture handles through `$P07_CONNECTION_MANIFEST_PATH`; never invent IDs in the spec, bind a fake, intercept a request, or seed through cross-owner SQL.
- [ ] Hand P07's runner owner an exact preflight packet for `infra/platform/production/run-local-e2e.sh::run`: after the final health check and before Playwright discovery, execute `docker compose --project-name "$P07_COMPOSE_PROJECT" --file infra/platform/production/compose.yaml --file infra/platform/production/compose.test.yaml exec -T api php Modules/Workspace/Tests/Support/CaptureProductionBindings.php > "artifacts/production-e2e/$P07_RUN_ID/workspace/production-bindings.json"`. The probe must run inside the shipped `api` service, report the six concrete M07 adapters, six concrete producer Contract bindings, `DatabaseGetStrategySnapshot`, and the real M01 audit binding, and fail on any fake/null/no-op/test provider. Host-only `APP_ENV=production` output is not sufficient live-container proof.
- [ ] Write `apps/web/e2e/workspace.spec.ts` against `production-fixtures.ts` and the manifest only. It must prove: `/workspace` renders six backend results plus four browser-composed keys in the saved ten-key order for the full persona; the limited persona omits audit and strategy without count/timestamp disclosure; the denied persona lacks route/sidebar content; `StrategyWorkspaceSource` returns published facts only for the authorized organization unit; Tasks/Reporting load independently, Notifications reuse the real shell collection with one network request, and Search sends zero requests before explicit submit; one preference PUT increments ETag and creates the visible M01 audit activity, a second tab's stale ETag returns 412 without overwrite; scope switch never flashes old-scope data; and keyboard/focus/RTL/LTR/200%-zoom/reduced-motion checks pass. Network interception, `page.route`, mocked HTTP, test-only production bindings, and direct database calls are forbidden.
- [ ] Export the recorded 40-character `P07_COMMIT_SHA`, then run `P07_TEST_MATCH=workspace.spec.ts ./infra/platform/production/run-local-e2e.sh lifecycle -- npm --prefix apps/web run api:check`. Do not pass `run-local-e2e.sh run` as the dependent command: `lifecycle` itself owns `start`, manifest export, the dependent gate, its internal P07 `run`, and trap-driven `stop`. P07's internal run must select `workspace.spec.ts` from `P07_TEST_MATCH` and, before executing journeys, require `jq '[.suites[].specs[] | select(.file | endswith("workspace.spec.ts")) | .tests[]] | length' "artifacts/production-e2e/$P07_RUN_ID/workspace/playwright-list.json"` to return an integer `>= 1`.
  Expected: the outer lifecycle performs `start → export and dot-source $P07_CONNECTION_MANIFEST_ENV_PATH → run api:check against the same checked-out SHA → internally discover/run workspace.spec.ts → trap stop on success/failure → prove cleanup`. The connection manifest commit/run/scope match, Caddy/API/MySQL/Redis/worker/scheduler services are healthy, production binding evidence names all six real Workspace adapters and six real producer contracts, the discovered Workspace count is nonzero, every journey passes through Caddy with no interception, and cleanup records `containers=0`, `networks=0`, `volumes=0`. Retain `artifacts/production-e2e/$P07_RUN_ID/workspace/` containing `playwright-list.json`, results/report/trace, `connection-manifest.sha256`, `production-bindings.json`, per-journey artifacts, and `cleanup-proof.json`; any zero discovery, fake binding, local dev server, broad runner without `P07_TEST_MATCH`, nested `run` invocation, or residual resource blocks M07 verification.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

- **Failure isolation:** Expected producer denial/unavailability maps to omission or one generic widget error; no exception message crosses HTTP. The handler catches each source outcome and continues, so a failed source cannot suppress successful sibling results. If the aggregate HTTP request stalls or fails before a response reaches the browser, retained M01–M06 values become stale together; Tasks, Reporting, Notifications, and Search keep independent states. Authentication, invalid correlation/scope, or failure to load M07 preferences returns problem+json.
- **Retry:** Retry of an M01–M06 widget repeats the single aggregate `GET /api/v1/workspace`, because that is the only M07 read contract; the UI labels the action as refreshing Workspace data and does not claim a selected-source retry. Tasks/Reporting/Search retry their own existing endpoint; Notifications use the shell refresh. Retries preserve current scope/correlation lineage, abort at 2,500 ms, and never trigger unrelated web-source requests. Search retries only after explicit user action. Exponential background retry is not introduced.
- **Staleness:** The server returns `fetched_at`; the browser marks retained data stale immediately when a refresh fails and announces it. Data older than five minutes also displays stale until refreshed. Scope/principal changes clear retained data rather than showing it stale in a new scope.
- **Idempotency:** Workspace stores no idempotency key, fingerprint, replay row, ledger, or response snapshot. Full PUT is guarded by strong `If-Match`: one successful request increments `lock_version` and writes one M01 audit record; any retry with the stale ETag returns 412 and writes nothing. The endpoint is at-most-once under optimistic concurrency, not replay-safe; after 412 the client reads the current representation before offering a new user-intended write.
- **Concurrency:** GET ETag reflects preference lock version. PUT requires If-Match and writes with `user_id + lock_version`; zero affected rows is 412. Initial insert races on the primary key and loser becomes 412. No last-write-wins path exists.
- **Authorization:** `workspace.read`/`workspace.preferences.update` is decided before detailed input validation. Each widget capability is checked before invoking its producer, and the producer rechecks row/scope access. Missing capability removes the widget and its metadata. Production adapters fail closed and test fakes stay under tests.
- **Privacy:** No PHI/PII is persisted or included in URLs, problem details, logs, metrics, preference JSON, browser storage, or screenshots. Correlation IDs and opaque source IDs are the only diagnostic identifiers.
- **Atomicity:** preference state, optimistic version, and the required M01 audit record commit in one database transaction. Injected failures before or during `RecordAuditEvent::record` roll all three back. M07 publishes no domain Event and appends no outbox row because layout preferences have no downstream side effect; this is the explicit M00 exception, and tests assert zero outbox rows rather than treating the missing outbox as a no-op.

## 11. Targeted verification commands and smoke scenarios

Run only after implementation and all integration tokens are merged. These commands were not run while drafting this plan. Every command records untrimmed output and exit code under `artifacts/program/M07/`; the P07 lifecycle additionally writes the live artifacts named below.

1. Backend module, architecture, migration, rollback injection, and production binding:

```bash
cd apps/api
php artisan test Modules/Workspace/Tests tests/Architecture/ModuleBoundariesTest.php tests/Feature/MigrationReversibilityTest.php
APP_ENV=production php artisan test Modules/Workspace/Tests/WorkspaceProductionBindingsTest.php --fail-on-warning
```

Expected: PASS with zero skips; Workspace is rank 11, `workspace_preferences` is its only table, migration round-trip succeeds, rollback injection preserves preference/version/audit with zero outbox, and the production container resolves the six exact M07 source classes plus six real producer Contracts and real M01 audit binding. `StrategyWorkspaceSource` calls `DatabaseGetStrategySnapshot` with `StrategyAccessContext`; any test/fake/null/no-op binding fails.

2. MySQL concurrency evidence owned by the serialized `MYSQL-SUITE` queue:

The queue owner registers exactly one `<file>Modules/Workspace/Tests/WorkspaceMySqlConcurrencyTest.php</file>` in `apps/api/phpunit.mysql.xml`; M07 does not edit the runner script or assert a literal registration count. Run the existing MySQL runner's discovery mode, then execute the registered suite. Expected: `Modules\Workspace\Tests\WorkspaceMySqlConcurrencyTest` is discovered and runs on driver `mysql` with `Skipped: 0`, proving one winner/one 412 loser, one winner audit, stale retry no-write, audit-failure rollback, and zero Workspace outbox rows.

3. Focused web unit and token-payload suite:

```bash
cd apps/web
npm run test:unit -- src/features/workspace src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx
```

Expected: PASS for all six-backend/four-browser taxonomy, state/race/capability/navigation, `StrategyAccessContext` consumer mapping, stale-write reload, no browser storage, RTL/LTR, and the exact eight-file WEB-SHELL payload.

4. Contract generation and production bundle:

```bash
cd apps/web
npm run api:generate
npm run api:check
npm run build
```

Expected: PASS; generated client matches master OpenAPI, Workspace PUT has `If-Match` and no `Idempotency-Key`, no manual drift exists, exhaustive routes compile, and the production bundle succeeds.

5. Repository-wide integration gates:

```bash
make test-api
make test-web
```

Expected: both designated aggregate runners exit 0 on the same commit with zero failed, skipped, risky, or incomplete tests; no focused-suite success can waive either broad gate. Retain `test-api.txt` and `test-web.txt` with full command output.

6. Bounded P07 live topology:

```bash
: "${P07_COMMIT_SHA:?export the recorded 40-character M07 commit SHA}"
P07_TEST_MATCH=workspace.spec.ts ./infra/platform/production/run-local-e2e.sh lifecycle -- npm --prefix apps/web run api:check
```

Expected: `lifecycle` starts the P01–P03 topology, exports and dot-sources the exact connection/seed manifest, runs the dependent `api:check`, internally discovers and runs `workspace.spec.ts` through Caddy using `P07_TEST_MATCH`, stops through its trap on success or failure, and proves zero containers/networks/volumes. The final `jq` value is `>= 1`; the binding artifact names six real Workspace source adapters and real producer bindings; no route interception, mocked response, dev server, nested `run`, broad Playwright invocation, zero discovery, or cleanup residue exists.

Required user-visible evidence:

- full persona opens `/workspace`, sees the six backend producer keys plus four browser-composed keys in saved ten-key order, activates each permitted detail link, and returns without a full reload;
- limited persona has `workspace.read` but no `audit.event.read` or `strategy.impact.read`, so audit and strategy are absent without count/timestamp disclosure; denied persona sees neither sidebar entry nor route content;
- real M04 adapter receives authenticated `StrategyAccessContext`, returns published facts for the authorized unit, and returns the indistinguishable empty snapshot for the denied unit;
- Tasks and Reporting recover independently; Notifications reuse the worker-delivered shell collection with exactly one `/notifications` request; Search makes zero requests until submit;
- two tabs use one ETag: first preference write increments the ETag and produces one visible M01 audit activity; second returns 412, does not overwrite, reloads, and does not retry automatically; SQLite/MySQL evidence separately proves zero outbox rows;
- scope switch never flashes old-scope data; keyboard order, visible focus, single live announcements, Arabic RTL, English LTR, 200% zoom reflow, and reduced motion pass;
- the M03-503 partial-state and Tasks refresh-failure journeys remain focused deterministic component/API tests from Task 9, explicitly not live-production evidence and never powered by a production fake.

## 12. Shared-file integration token requirements

M07 owns and requests only the final `WEB-SHELL` token. All other shared requirements are payload handoffs whose canonical owners request, apply, verify, and release their own tokens:

1. hand the registry/catalog/migration-list/provider payload to the `MODULE-REGISTRY` integrator after M00/current-plan handoff and after M01–M06 queue entries;
2. hand the two capability codes to M00's capability-catalog integrator;
3. hand `WorkspaceMySqlConcurrencyTest` registration to the `MYSQL-SUITE` owner; that owner applies exactly one entry in `apps/api/phpunit.mysql.xml` and one in `scripts/run-mysql-integration-tests.sh`, verifies the literal count is `2`, records the base/merge SHA, then releases the surface;
4. hand the route registrations to the `API-ROUTES` owner after M01–M06;
5. hand schemas/operations to the `OPENAPI` owner, then the generation command to the `ORVAL` owner; generation uses `npm run api:generate` only;
6. request `WEB-SHELL` last among M01–M07 shell holders, after M01–M06 final producer integration. M07 owns this final aggregation token only. Its YAML anchor remains exactly `apps/web/src/shell/routes.ts (final aggregation token only)`, and the grant payload must equal this exact eight-path set: `apps/web/src/shell/routes.ts`; `apps/web/src/shell/navigation.tsx`; `apps/web/src/app/WorkspaceContent.tsx`; `apps/web/src/features/requests/RequestForm.tsx`; `apps/web/src/shell/routes.test.ts`; `apps/web/src/shell/routes.capabilities.test.ts`; `apps/web/src/shell/navigation.test.tsx`; `apps/web/src/app/AppWorkspace.navigation.test.tsx`. The grant record fails if any path is omitted, wildcarded, or added;
7. hand the exact Task 9 fixture/discovery/container-probe packet to P07's owners of `ProductionE2ESeeder.php`, `production-fixtures.ts`, `playwright.production.config.ts`, and `run-local-e2e.sh::run`. P07 alone applies those changes and runs the bounded lifecycle; M07 never edits P07-owned runner/fixture surfaces under its WEB-SHELL grant.

Each payload handoff record names its canonical requesting/applying owner, full base SHA, exact surfaces, evidence, expiry, and merge commit. M07 records those merged dependencies but never presents them as M07-owned grants. Its own `WEB-SHELL` grant follows the orchestration protocol; stale grants are revoked/rebased. M07 cannot enter verification until all payloads are merged by their owners, WEB-SHELL is released, the MySQL sentinel count is exactly two, P07's M07-specific runner packet is applied, and no production fake remains.

## 13. Rollback procedure

1. Ask the canonical API-route owner to stop new preference writes by removing/feature-disabling only the two Workspace registrations; do not alter producer routes.
2. Retain failing correlation IDs, manifest, preference row count, and sanitized schema/version evidence. Do not export unexpected layout fields.
3. M07 restores the prior `WEB-SHELL` token atomically so users do not land on a disabled API route.
4. Submit reversal payloads to the OpenAPI/Orval owners; the Orval owner regenerates and nobody hand-edits generated output.
5. Submit provider/API registration and migration rollback payloads to their canonical owners; the migration owner rolls back `CreateWorkspacePreferencesTable`.
6. Submit the module-registry reversal payload: remove table ownership and return Workspace to planned inventory while preserving rank 11.
7. Run the architecture/migration/route commands from §11. Expected: producer modules/tables unchanged, old shell loads, `/api/v1/workspace` is absent, and no orphan ownership remains.
8. Record `artifacts/program/M07/rollback-rehearsal.json` with commits, commands, exit codes, row-count-only evidence, and confirmation no upstream table changed.

Rollback loses only user layout customization. It never restores or mutates producer facts because M07 never owns them.

## 14. Exit criteria and required retained evidence

M07 may complete only when all are true on one recorded, user-authorized commit:

- M00 is approved; M01–M06 production Contracts/adapters are merged and pass compatibility tests.
- Workspace is rank 11, no same-rank PHP imports exist, `workspace_preferences` is the only M07 table, and every shared token is merged/released.
- Both API operations match OpenAPI and generated client with zero drift.
- Capability checks precede validation/disclosure; denied widgets are omitted; row-level producer authorization remains intact.
- Preference CSRF, If-Match, ETag, one-winner/two-tab conflict, stale-retry 412, state/version/M01-audit rollback injection, zero-outbox exception, absence of replay state, and migration reversal pass on SQLite and MySQL.
- Partial, stale, error, empty, retry, scope-race, logout clearing, and no-browser-storage tests pass.
- Browser smoke, Arabic/English, keyboard, visible focus, zoom/reflow, status announcements, and reduced-motion evidence pass; P05 may consume this evidence but does not waive M07's gate.
- Performance evidence proves each permitted adapter is invoked at most once per aggregate GET, source errors do not stop later adapters, per-adapter query counts/timings are measured, the 30-item seeded aggregate p95 is at most 500 ms, the browser aborts the aggregate at 2,500 ms, no N+1/cross-owner SQL or duplicate notification request occurs, and Search runs only on submit.
- `WorkspaceProductionBindingsTest` and the shipped-container probe both name six concrete M07 source adapters, six real producer Contract bindings, `DatabaseGetStrategySnapshot` with `StrategyAccessContext`, and the real M01 audit binding; no fake/null/no-op/test provider resolves.
- The MySQL runner executes the exact M07 sentinel with driver `mysql`, zero skips, and a literal registration count of two.
- P07 discovers `workspace.spec.ts` with count `>= 1`, consumes the exact persona/scope/ten-key fixture contract, runs through Caddy with no interception, and its trap proves zero containers, networks, and volumes. This requires only P07's runner/fixture handoff and M07-specific gate, not P07 plan completion or P08 acceptance.
- No skipped test, placeholder/no-op adapter, production fake, manual generated-client edit, PHI/PII leak, or unresolved token remains.

Retain immutable M07 completion evidence under `artifacts/program/M07/`; publish it when M07's own gates pass without waiting for P08:

- `completion-manifest.json` with schema:

```json
{
  "plan_id": "M07",
  "status": "completed",
  "commit": "<40-hex recorded commit>",
  "verified_at": "<UTC RFC3339>",
  "commands": [{"command": "<exact command>", "exit_code": 0, "output_path": "<relative path>"}],
  "tokens": [{"token": "WEB-SHELL", "state": "released", "base_commit": "<40-hex>", "merge_commit": "<40-hex>", "payload_count": 8, "evidence": "<relative path>"}],
  "mysql": {"sentinel": "Modules\\Workspace\\Tests\\WorkspaceMySqlConcurrencyTest", "registration_count": 2, "skipped": 0, "artifact": "mysql-sentinel.txt"},
  "bindings": {"result": "pass", "host_artifact": "production-bindings-test.txt", "container_artifact": "<relative P07 production-bindings.json>"},
  "p07": {"spec": "workspace.spec.ts", "discovered_tests_minimum": 1, "result": "pass", "cleanup": {"containers": 0, "networks": 0, "volumes": 0}, "artifact_root": "<relative P07 workspace artifact root>"},
  "smoke": [{"scenario": "<§11 exact scenario name>", "result": "pass", "artifact": "<relative path>"}],
  "privacy": {"result": "pass", "artifact": "privacy-review.json"},
  "accessibility": {"result": "pass", "artifact": "accessibility-report.json"},
  "performance": {"result": "pass", "artifact": "workspace-performance.json"},
  "rollback": {"result": "pass", "artifact": "rollback-rehearsal.json"}
}
```

Angle-bracket values above are evidence schema fields populated by the executor from observed results, not implementation placeholders.

- raw command outputs for all §11 commands;
- `workspace-contract-map.json` with each consumed Contract signature, DTO accessor mapping, source commit, and privacy classification;
- `workspace-performance.json` with aggregate request count, per-source adapter invocation/query counts, fixture size, per-adapter measured elapsed times, aggregate p50/p95, 2,500 ms browser deadline result, source-error continuation evidence, and duplicate-request count;
- `privacy-review.json`, `accessibility-report.json`, Playwright trace/screenshots for non-sensitive fixtures, and `rollback-rehearsal.json`;
- token grant/release records and generated-client drift proof.
- `production-bindings-test.txt`, P07's shipped-container `production-bindings.json`, `mysql-sentinel.txt`, P07 `playwright-list.json`/report/results/trace/per-journey artifacts, `connection-manifest.sha256`, and `cleanup-proof.json`;

## 15. Status transition rules

- `blocked → ready`: M00 is approved, its exact rank/table/capability/route/Contract decisions match this plan, M07-owned work may begin, and executor/worktree/base commit are recorded. Shared payload owners remain independent gates; only WEB-SHELL can be an M07-owned token.
- `ready → in_progress`: an authorized executor begins a checked M07-owned task in an isolated worktree and records the base commit. No commit is created without user authorization.
- `in_progress → blocked`: record the exact missing M01–M06 Contract/DTO field, M01 audit exception binding, failed owner-applied payload, MySQL sentinel mismatch, WEB-SHELL owner/handoff, P07 M07-specific fixture/runner prerequisite, or environment prerequisite; record the last safe commit and passing focused command. Producer-dependent work remains blocked without changing `depends_on`.
- `in_progress → verification`: M01–M06 final integration is complete; canonical owners merged registry, capability, MySQL, routes, OpenAPI, Orval, and P07 M07-specific packets; M07 merged/released only its final WEB-SHELL token; MySQL count is two; all production and shipped-container adapters are real; implementation is complete.
- `verification → completed`: every §14 criterion and M07-owned/live gate passes on the same recorded commit, retained evidence resolves, rollback rehearsal passes, and the user authorizes recording the implementation/verification commit. Publish immutable `completion-manifest.json` and update the orchestration plan's M07 summary. P07 and P08 later consume/replay it but neither P07 completion nor P08 acceptance is a prerequisite for M07 `completed`.
- `any → superseded`: a later user-approved plan names this file, records migration/rollback of M07 artifacts, and updates every downstream dependency including `P07:production-execution`.

A failed narrow command never justifies completion or a scope reduction. Planning completion leaves this plan `blocked` until its declared start gate is satisfied.
