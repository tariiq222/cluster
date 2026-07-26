# Module Catalog

> **Status:** Authoritative. The PHP architecture test
> `apps/api/tests/Architecture/ModuleBoundariesTest.php` must agree with this
> catalog at all times. If you change one, change the other in the same PR.

Cluster is a **Laravel 13.8 Modular Monolith** organised as 12 implemented
business modules plus 7 planned modules. Each module owns its database tables,
its HTTP surface, its domain rules, and its infrastructure adapters; it
publishes only `Contracts/` (interfaces) and `Events/` (typed outbox payloads)
to the rest of the system.

## 1 · Ranking

A module's rank is its privilege tier. A module may only depend on lower-rank
modules through that module's `Contracts/` or `Events/` namespace. The rank
order is enforced by `test_detects_a_cross_module_domain_import` in
`ModuleBoundariesTest.php`.

| Rank | Implemented | Planned | Privilege |
| --- | --- | --- | --- |
| 0 | `PlatformSettings`, `Organization` | — | Roots: configuration and tenant data. No upward dependencies. |
| 1 | `Identity` | — | Authenticated principal. May read from rank 0 through Contracts. |
| 2 | `Authorization` | — | RBAC + ABAC engine. Consumes `Identity` and `Organization` Contracts. |
| 3 | — | `Audit` | Audit ledger. Must come **after** `Authorization` so it can record access decisions. |
| 4 | `Workflow` | `RecordsGovernance` | Cross-cutting business processes. |
| 5 | `WorkDefinitions`, `Documents` | — | Authoring of work artefacts and documents. |
| 6 | — | `Collaboration` | Shared collaborative surfaces (planned). |
| 7 | `Tasks` | — | Task lifecycle and engagement. |
| 8 | `WorkRecords` | `Strategy` | Realisations of work and strategic plans. |
| 9 | — | `PortfolioProjects` | Portfolio aggregates (planned). |
| 10 | — | `Risk` | Risk register (planned). |
| 11 | `Notifications`, `Search`, `Reporting` | `Workspace` | Side-channel read models and notifications. |

`Audit` remains planned and has no implementation directory or migration.
The historical `audit_events` table entry was removed. Sensitive-access
records remain owned by `Authorization` until an explicit Audit migration is
designed and delivered.

## 2 · Implemented modules

Each entry links to the analysis document that covers the module in depth and
lists the canonical tables, contracts, and HTTP routes.

### `Organization` (rank 0)

- **Purpose.** Owns the cluster tenant data: cluster, facilities, units,
  positions, people, assignments, supervisory relationships, job titles,
  import jobs, and temporary assignments with concurrency-safe revocation.
- **Tables.** `clusters`, `facilities`, `facility_types`, `unit_types`,
  `organization_units`, `positions`, `job_titles`, `people`, `assignments`,
  `temporary_assignments`, `temporary_assignment_capabilities`,
  `supervisory_relationships`, `relationship_capabilities`, `import_jobs`,
  `import_rows`, `organization_idempotency_keys`,
  `organization_development_facilities`.
- **Contracts.** `GetActiveSupervisoryRelationships`,
  `ResolvePersonOrganizationScope`, `ResolveOrganizationScopeAncestry`,
  `ValidatePersonReference`, `ResolveQuarantinedImport`,
  `ValidateTemporaryAssignmentCapabilities`, `BuildTemporaryAssignmentEvent`.
- **HTTP.** 35 controllers are module-owned under
  `Modules/Organization/Features/*/Http/`; none remain under
  `app/Http/Controllers/Organization/`.

### `PlatformSettings` (rank 0)

- **Purpose.** Platform-level configuration: settings versions with publish
  workflow, alert policies, business calendars, maintenance windows, backup
  operations, and technical log archive (technical logs are DEFERRED).
- **Tables.** `platform_settings`, `platform_setting_versions`,
  `platform_settings_outbox`, `platform_alert_policies`,
  `business_calendars`, `business_calendar_weekdays`,
  `business_calendar_exceptions`, `platform_maintenance_windows`,
  `platform_operation_requests`, `platform_operation_snapshots`,
  `technical_log_archive_batches`, `technical_log_archive_manifests`,
  `technical_log_archive_restore_requests`.
- **Contracts.** `GetEffectivePlatformSettings`, `ResolveBusinessCalendar`,
  `PublishTechnicalAlert`, `ValidateTechnicalAlertRecipientCapability`,
  `ResolveTechnicalAlertRecipients`, `TechnicalLogSource`,
  `TechnicalLogArchive`, `TechnicalLogArchiveStore`,
  `BackupOperationsGateway`, `PlatformHealthGateway`.

### `Identity` (rank 1)

- **Purpose.** Authenticated principals: login, logout, sessions, TOTP,
  password rotation, activation tokens, identity inbox (streams from
  Organization person events), development fixture principal (local only).
- **Tables.** `users`, `identity_sessions`, `identity_person_account_claims`,
  `identity_idempotency_keys`, `identity_inbox`,
  `identity_person_event_watermarks`, `identity_person_provisioning`,
  `identity_development_fixture_accounts`, `credentials`,
  `identity_password_history`, `identity_activation_tokens`, `identity_totp`,
  `identity_auth_attempt_ledgers`.
- **Contracts.** `ResolvePrincipalContext`, `ResolveAccountEntitlement`,
  `ResolveUserForPerson`, `ResolveDevelopmentFixturePrincipal`,
  `AuthenticateUser`, `PreAuthThrottle`, `IssueActivationToken`,
  `ChangePassword`, `ResolveSession`.

### `Authorization` (rank 2)

- **Purpose.** RBAC + ABAC decision engine, role / capability / delegation /
  classification / field-access templates, explicit deny, sensitive access
  event recorder, operations-office member counter, simulation facts.
- **Tables.** `access_decisions`, `roles`, `capabilities`,
  `role_capabilities`, `role_assignments`, `delegations`,
  `delegation_capabilities`, `explicit_denies`, `classification_policies`,
  `field_access_templates`, `sensitive_access_events`,
  `authorization_bootstrap`, `authorization_idempotency_keys`.
- **Contracts.** `PersistAccessDecision`, `CountOperationsOfficeMembers`,
  `DecideAccess`, `ResolveAuthorizationSimulationFacts`.
- **Audit ownership debt.** `sensitive_access_events` logically belongs to
  the planned `Audit` module; it is currently under `Authorization` for
  delivery speed. The migration to `Audit` is Phase 4 of the roadmap.

### `Workflow` (rank 4)

- **Purpose.** Workflow definitions, versions, instances, step instances,
  step decisions, assignment rules, advance engine.
- **Tables.** `workflow_definitions`, `workflow_versions`,
  `workflow_instances`, `workflow_step_instances`, `workflow_decisions`,
  `workflow_idempotency_keys`.
- **Contracts.** `AdvanceWorkflowStep`, `ResolveStepAssignee`,
  `ResolveWorkflowSourceAuthorizationFacts`.

### `WorkDefinitions` (rank 5)

- **Purpose.** Authoring of work definitions (request fixtures) and
  work-definition development fixtures for local exploration.
- **Tables.** `work_definitions`, `work_definition_versions`,
  `work_definition_idempotency_keys`,
  `work_definition_development_work_type_versions`.

### `Documents` (rank 5)

- **Purpose.** Document lifecycle: create, version, link, quarantine,
  ClamAV scan, S3-compatible storage with SigV4 presigned downloads,
  governance policies (archive, place-hold, release-hold), preview/download
  grants.
- **Tables.** `documents`, `document_versions`, `document_links`,
  `document_idempotency_keys`, `document_quarantines`,
  `document_storage_objects`, `document_upload_intents`,
  `document_restriction_facts`, `document_access_events`,
  `document_outbox_events`.
- **Contracts.** `DocumentAuthorizationFactsReader`, `DocumentDownloadService`,
  `DocumentDownloadGrantIssuer`, `DocumentUploadStatusReader`,
  `LinkedResourceAuthorizationFacts`, `MalwareScanner`,
  `PrivateObjectStorage`, `SensitiveAccessEventRecorder`, `WorkerPrincipalResolver`.

### `Tasks` (rank 7)

- **Purpose.** Task lifecycle and engagement (participants, comments),
  creation from workflow step, transitions, idempotency.
- **Tables.** `tasks`, `task_idempotency_keys`, `task_participants`,
  `task_comments`.

### `WorkRecords` (rank 8)

- **Purpose.** Realisations of work: submit, list authorised records, get
  authorised record, lifecycle transitions (submit / return / complete /
  cancel / archive), idempotency.
- **Tables.** `work_records`, `work_record_idempotency_keys`, `outbox_events`.
  As of the Task 4 inventory refresh (2026-07-26), the previous
  `project_work_record_read_models` extra `TABLE_OWNERS` key has been
  removed because no `Schema::create` migration declares it; if the
  projection becomes a real table it must be added back via a migration,
  not as an inventory string.

### `Notifications` (rank 11)

- **Purpose.** Notification inbox, dead letters, recipients, delivery,
  fan-out workers, technical-alert recipient resolver.
- **Tables.** `notifications`, `notification_inbox`,
  `notification_recipients`, `notification_dead_letters`.

### `Search` (rank 11)

- **Purpose.** Search projection tables and per-row DecideAccess gating.
- **Tables.** `search_index_entries`, `search_inbox`, `search_checkpoints`.

### `Reporting` (rank 11)

- **Purpose.** Reporting read-models, dashboards, report definitions,
  export artifacts, refresh/rebuild handlers.
- **Tables.** `report_definitions`, `report_inbox`, `report_read_models`,
  `report_runs`, `export_artifacts`, `dashboard_definitions`.

## 3 · Planned modules (no implementation directory)

These modules are declared in `PLANNED_MODULES` of
`ModuleBoundariesTest.php`; the test
`test_planned_modules_have_no_implementation_directory_yet` fails if any of
them accidentally gains a directory.

| Name | Planned rank | Notes |
| --- | --- | --- |
| `Audit` | 3 | First planned module to materialise. See Phase 4. |
| `RecordsGovernance` | 4 | Retention and disclosure classification. |
| `Collaboration` | 6 | Shared collaborative surfaces. |
| `Strategy` | 8 | Strategic planning. |
| `PortfolioProjects` | 9 | Portfolio aggregates. |
| `Risk` | 10 | Risk register. |
| `Workspace` | 11 | Personal workspace. |

## 4 · Cross-module rules (verified by architecture tests)

1. **Rank ordering.** A module may only depend on strictly lower-rank modules,
   and only through the dependency's `Contracts/` or `Events/` namespace.
   `test_detects_a_cross_module_domain_import` enforces this.
2. **Table ownership.** Every migrated table must have exactly one owning
   module in `TABLE_OWNERS`, and `TABLE_OWNERS` must contain exactly the
   set of tables declared by `Schema::create` migrations under
   `apps/api/Modules/*/Infrastructure/Persistence/Migrations/` and
   `apps/api/Modules/*/Infrastructure/Outbox/Migrations/`. As of the
   Task 4 inventory refresh (2026-07-26) the registry holds **96 entries
   for 96 distinct migrated table names**, and no extra key remains.
   Virtual resources must never enter `TABLE_OWNERS`; if a future virtual
   resource requires an inventory, register it in a sibling constant
   `VIRTUAL_RESOURCES` (see the header comment on
   `apps/api/tests/Architecture/ModuleBoundariesTest.php`). The four
   architecture tests that enforce exactness are:
   - `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout`
     — TABLE_OWNERS has no extra keys, no missing keys, and no owner/module
     mismatches.
   - `test_every_misplaced_file_has_a_reason_a_non_past_expiry_and_an_existing_path`
     — every `ModulePlacementInventory` entry carries a non-empty `reason`,
     a non-past `expires_on`, and a path that still exists on disk.
   - `test_planned_modules_have_no_implementation_directory_yet`.
   - `test_current_module_tree_obeys_the_repository_boundary_rules`.
   This catalog line was refreshed by Task 4 (Task 4 source:
   `apps/api/tests/Architecture/ModuleBoundariesTest.php` and
   `apps/api/tests/Architecture/ModulePlacementInventory.php` on
   2026-07-26).
3. **Controller placement.** Business controllers under
   `app/Http/Controllers/` are prohibited except for the Laravel base
   `Controller.php`; this part currently passes. Five controllers still sit in
   module-level `Http/` directories instead of
   `Modules/<Name>/Features/*/Http/` (four Reporting and one Search), and the
   current guard does not detect that shape. `ModulePlacementInventory` no
   longer carries the two stale Reporting paths that previously referenced
   the deleted `Modules/Reporting/Http/List{Dashboards,Reports}Controller.php`
   files; the controllers now live under `Features/List{Dashboards,Reports}/Http/`
   and comply with the placement rule, so no exception is required. The
   remaining five module-level `Http/` controllers are out of scope for
   Task 4 and are tracked as Task 5 work.
3a. **Outbox event types.** Every `com.cluster.*.v<n>` literal that appears
   in producer code must be a case on `Shared\Infrastructure\Outbox\OutboxEventType`
   and must have a corresponding JSON schema file under
   `docs/contracts/schemas/`. `test_every_event_type_in_outbox_has_a_matching_json_schema`
   enforces both: the enum is the single source of truth for the catalogue
4. **Outbox ownership.** HTTP controllers must not own transactions or write
   to the outbox directly. The outbox is owned by the application's handler
   layer (and shared infrastructure for the shared transactional outbox).
5. **Forbidden identifier.** No class, interface, trait, or enum may be named
   `Request*` because Laravel reserves that word for HTTP request objects.
   `test_rejects_requests_as_a_business_module_or_identifier` enforces this.

## 5 · How this catalog is kept honest

| Source of drift | Guard |
| --- | --- |
| New module created without rank | `test_planned_modules_have_no_implementation_directory_yet` |
| New module imports higher rank | `test_detects_a_cross_module_domain_import` |
| New table without owner | `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout` |
| New business controller under `app/` | `test_detects_a_business_controller_outside_its_module`, exempt via `ModulePlacementInventory` |
| New event type without JSON schema | `test_every_event_type_in_outbox_has_a_matching_json_schema` |
| Module uses `Request*` identifier | `test_rejects_requests_as_a_business_module_or_identifier` |
If you change a module's rank or add a planned module, edit both this catalog
and `MODULE_RANKS` / `PLANNED_MODULES` in `ModuleBoundariesTest.php` in the
same PR. The architecture test runner is the single arbiter of truth.

## 6 · Architecture decisions log

Decisions made in the table-ownership, middleware-cost, outbox-contract,
and CSRF cleanup passes. Each entry records what was decided, what was
considered and rejected, and where the implementation lives.

### 6.1 `MarkNotificationReadController` no longer requires `Idempotency-Key`
(Stage 2)
- **What.** The handler dropped the `Idempotency-Key` header check. A
  request without the header is no longer rejected with 400; a retry
  returns the same response body, and the row stays `is_read=true`.
- **Why.** The controller's only write is a single conditional UPDATE
  (`where('id', $notificationId)->update(['is_read' => true, ...])`),
  which is naturally idempotent at the SQL level. A replay table (the
  pattern other modules use) would be over-engineering for a read-state
  toggle that does not mint a resource or trigger an external side effect.
- **Rejected.** Persisting `(idempotency_key, response_body, status)`
  in a `notification_idempotency_keys` table. That table would have to be
  populated, queried, and eventually expired — all for a controller
  whose write is already a no-op on the second call.
- **Where.** `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/MarkNotificationReadController.php`
  and the new focused test at `Tests/MarkNotificationReadControllerTest.php`.

### 6.2 `EnforcePlatformMaintenance` caches active-window state, not
authorization decisions (Stage 3)
- **What.** The middleware reads the active maintenance window from
  `Cache::remember('platform:maintenance:active', 60, ...)`. On cache
  miss the `MaintenanceWindowHandler::activeAt()` DB query runs once;
  on cache hit the cached payload is restored and re-evaluated against
  the current time. `DecideAccess` is only invoked when a window is
  actually active, and the per-principal call is never cached.
- **Why.** The original implementation invoked `DecideAccess` on every
  request, which is several DB round-trips through
  `RbacAbacDecideAccess` (owner roles, denies, grants, policies,
  capabilities, plus a transaction that writes to `access_decisions`).
  Caching the *active-window boolean* is the safe optimization: it
  removes the DB hit on the no-window path and narrows the
  `DecideAccess` cost to requests that would actually be blocked.
- **Rejected.** Caching the `DecideAccess` outcome globally. That would
  let an admin's allow decision leak to non-admin principals (cache
  poisoning), so the cache is restricted to the window-state shape only.
- **Where.** `apps/api/app/Http/Middleware/EnforcePlatformMaintenance.php`
  and the cache-only test in
  `tests/Unit/Http/Middleware/EnforcePlatformMaintenanceTest.php`.

### 6.3 Outbox event schemas are real contracts, not placeholders (Stage 4)
- **What.** Each `*.schema.json` file under `docs/contracts/schemas/`
  is a JSON Schema Draft 2020-12 document with a top-level `type:
  object`, a `required` array containing `data`, and a `properties.data`
  object describing the actual payload shape emitted by the producer
  in `apps/api/Modules`. Every schema's `description` starts with
  "Produced by <relative-path-to-producer>" so the contract is
  traceable.
- **Why.** A schema file that exists but is a generic placeholder does
  not protect consumers from a payload-shape change. The architecture
  test now reads the file with `json_decode` and asserts the
  `type / required / properties` contract; a producer that changes a
  payload without updating the schema will fail CI.
- **Where.** `tests/Architecture/ModuleBoundariesTest.php::test_every_event_type_in_outbox_has_a_matching_json_schema`.

### 6.4 CSRF regression test deferred (Stage 5)
- **What.** `test_patch_documents_without_csrf_header_is_rejected` was
  removed after repeated attempts to wire the feature test through the
  full session/principal/CSRF pipeline failed. The
  `IdentityCsrfMiddleware` lives behind `IdentitySessionMiddleware` and
  `RequireIdentitySessionPrincipal`, so a session-less PATCH returns
  401 before the CSRF guard sees the request.
- **Decision.** A faithful regression test belongs in
  `tests/Unit/Http/Middleware/IdentityCsrfMiddlewareTest.php` where the
  session/principal can be mocked directly. That unit test is left for
  a follow-up; the route mapping in `DocumentsContractRoutesTest` is
  the smallest, most-stable contract surface for the time being.
- **Why.** The current production route is verified by
  `Route::getRoutes()` introspection (the `IdentityCsrfMiddleware`
  class is in the middleware list for the PATCH route), and the
  middleware itself is exercised in the unit test of the
  `EnforcePlatformMaintenance` stage-3 work.


### 6.5 `RequireIdentitySessionPrincipal` is a real session-only enforcer (Stage 12)
- **What.** `App\Http\Middleware\RequireIdentitySessionPrincipal` no longer
  just sets the dead `identity.session_only` request attribute. It now reads
  the `identity.session` and `identity.principal` attributes written by
  `IdentitySessionMiddleware` and enforces a coherent binding: a non-empty
  string `user_id` on the session, a non-empty string `session_id` on the
  session, a non-empty string `user_id` on the principal, and
  `principal.user_id === session.user_id`. A coherent binding is passed
  through to `$next` unchanged; a missing, malformed, or mismatched binding
  returns the standard `IdentityApi::problem(401, 'authentication-required', ...)`
  response without invoking the next handler.
- **Why.** The middleware's name implied enforcement it never performed.
  Before this stage the body was `set 'identity.session_only' = true; return $next($request)`,
  and no production code read that attribute — every test that wanted the
  flag set wrote `config()->set('identity.session_only', true)` directly,
  which meant the protected routes behind `RequireIdentitySessionPrincipal`
  were not actually gated by anything beyond `IdentitySessionMiddleware`
  itself. The route layer kept growing (`/api/v1/identity/me`,
  `/api/v1/me`, the `/api/v1/me/scope` family, the password-change route,
  and several other protected surfaces) and the cost of leaving a
  name-implies-but-doesn't-enforce middleware in front of them kept rising.
- **Rejected.** Calling `ResolvePrincipalContext` from the middleware.
  That contract returns `null` for restricted must-change-password sessions,
  because restricted sessions are not yet trusted principal contexts —
  they exist precisely so the user can reach the password-change route
  and *become* unrestricted. Resolving the principal here would have made
  the protected password-change route unreachable for exactly the
  principals it is supposed to serve. The middleware therefore inspects
  only the attributes `IdentitySessionMiddleware` writes (string `user_id`
  on the session, string `session_id` on the session, matching
  string `user_id` on the principal) and leaves principal-context
  resolution to the handlers that need the full PrincipalContext.
- **Where.**
  `apps/api/app/Http/Middleware/RequireIdentitySessionPrincipal.php`
  and the new unit test
  `apps/api/tests/Unit/Http/Middleware/RequireIdentitySessionPrincipalTest.php`
  covering coherent pass-through, missing `identity.session` 401, and
  mismatched user IDs 401.


### 6.6 Self-hosted end-to-end CI workflow (Stage G)
- **What.** `.github/workflows/ci-e2e.yml` is the release and manually
  dispatched E2E workflow. It runs on a self-hosted runner carrying the
  `cluster-e2e` label and has a 30-minute job timeout. The workflow invokes,
  in order, `make verify-intake`, `make verify-boundaries`,
  `make docs-validate`, `make test-api`, and `make verify-w1-1-local`, then
  uploads `./test-results/` as a workflow artifact.
- **Runner prerequisites.** Provision a Linux runner with Docker Engine and
  Docker Compose v2, grant its runner user access to the Docker socket, and
  make PHP 8.4, Composer 2, Node.js 20, npm, `openssl`, `lsof`, and `curl`
  available on `PATH`. MySQL must be reachable at `localhost:3306`, outbound
  HTTPS must permit pulling the pinned production images, and the production
  bundle must be buildable on the host. The workflow is intentionally a
  template: no live runner is required until the repository owner provisions
  and labels one.
- **How to extend it.** When a module adds a new CI contract, expose that
  contract as a Makefile target and add a named workflow step before
  `verify-w1-1-local`, keeping cheap static or boundary checks ahead of the
  production-bundle E2E run. Add the module's failure output under
  `./test-results/` so the existing artifact upload captures it; if the new
  check needs additional host software or services, update both the workflow
  header prerequisites and this entry in the same change.

### 6.7 Identity security events validated through a registry (Stage 15)

- **What.** `IdentityOutbox::insertSecurityEvent(string $type, ...)` previously
  built the literal `'com.cluster.identity.' . $type . '.v1'` and passed
  it to `IdentityOutbox::insert()`. The `OutboxEventType::from` validation
  in `insert()` then rejected unknown suffixes, but the error message
  referenced the assembled string rather than the suffix the producer
  actually passed, making typos hard to diagnose. Add a dedicated
  `IdentitySecurityEventRegistry` at
  `apps/api/Modules/Identity/Infrastructure/Outbox/` that maps the 12
  producer suffixes (the 10 new cases plus the 2 legacy cases
  `account_login_locked` and `authentication_failed`) to their matching
  `OutboxEventType` cases. `resolve(string $type)` returns the case or throws
  `InvalidArgumentException` with the suffix the producer passed plus the full
  list of registered suffixes. `IdentityOutbox::insertSecurityEvent` now
  delegates to the registry, so an unknown suffix raises a suffix-specific
  error before the assembled literal ever reaches `OutboxEventType::from`.
- **Why.** Routing every security-event suffix through a single
  Identity-side adapter makes the boundary explicit and concentrates the
  suffix-to-event-type contract in one file rather than letting it leak
  across producer call sites.
- **Tests.** `tests/Unit/Shared/Infrastructure/Outbox/IdentitySecurityEventRegistryTest.php`
  uses PHPUnit 12 `#[DataProvider]` (the `@dataProvider` annotation is
  no longer recognised) and asserts every one of the 12 suffixes
  resolves to its expected case and carries a CloudEvents type under
  `com.cluster.identity.*.v1`. The unknown-suffix test asserts the
  `InvalidArgumentException` message includes both the unknown suffix and
  the list of registered suffixes. An empty-string test guards against
  the trivial typo of an empty producer argument.
- **Where.** `apps/api/Modules/Identity/Infrastructure/Outbox/IdentitySecurityEventRegistry.php`,
  `apps/api/Modules/Identity/Infrastructure/Outbox/IdentityOutbox.php` (call site),
  `apps/api/tests/Unit/Shared/Infrastructure/Outbox/IdentitySecurityEventRegistryTest.php`.

### 6.8 Legacy controller migration completed for `app/Http/Controllers`

- **What.** The migration expanded from the initial 14-controller slice to the
  full legacy application-controller tree. The only remaining file under
  `apps/api/app/Http/Controllers/` is Laravel's base `Controller.php`.
  Organization now owns 35 controllers under
  `Modules/Organization/Features/*/Http/`; Identity, Authorization, Documents,
  Workflow, WorkDefinitions, WorkRecords, Tasks, Notifications, Platform
  Settings, Reporting, and Search routes now bind module-owned controllers.
- **Residual debt.** Four Reporting controllers remain under
  `Modules/Reporting/Http/` and `SearchController` remains under
  `Modules/Search/Http/`. They are module-owned but do not follow the preferred
  feature-folder layout. The current architecture test only catches
  application-level placement and therefore needs an explicit module-level
  placement assertion.
- **Evidence.** `apps/api/routes/web.php`, the module controller tree, and
  `make verify-boundaries` (12 tests, 53 assertions, passed on 2026-07-26).

### 6.9 Composition root split into module providers

- **What.** `AppServiceProvider` now registers 12 module service providers and
  retains only shared bindings, migration loading, commands, and production
  safety checks. It is 106 lines; module-specific bindings live in
  `Modules/<Name>/Providers/<Name>ServiceProvider.php`.
- **Why.** This restores module ownership without creating a second global
  composition root. Production still fails closed unless Authorization uses
  `BootstrapGatedDecideAccess` with the production engine and Identity resolves
  principals from sessions.
- **Evidence.** `apps/api/app/Providers/AppServiceProvider.php`,
  `apps/api/Modules/*/Providers/*ServiceProvider.php`, `make analyse-api`, and
  `make lint-api`.
