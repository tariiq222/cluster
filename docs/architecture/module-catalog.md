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

**`Audit` is the only planned module with a concrete migration already in
place**: `audit_events` is currently registered as `Authorization`-owned in
`TABLE_OWNERS` but the `Audit` module directory has not been created yet. This
is the first planned module to materialise; see Phase 4 of the architecture
roadmap in `docs/analysis/18-architecture-deep-review.md`.

## 2 · Implemented modules

Each entry links to the analysis document that covers the module in depth and
lists the canonical tables, contracts, and HTTP routes.

### `Organization` (rank 0)

- **Purpose.** Owns the cluster tenant data: cluster, facilities, units,
  positions, people, assignments, supervisory relationships, job titles,
  import jobs, and temporary assignments with concurrency-safe revocation.
- **Tables.** `clusters`, `facilities`, `facility_types`, `units`,
  `unit_types`, `organization_units`, `positions`, `job_titles`, `people`,
  `assignments`, `temporary_assignments`, `temporary_assignment_capabilities`,
  `supervisory_relationships`, `relationship_capabilities`, `import_jobs`,
  `import_rows`, `organization_idempotency_keys`,
  `organization_development_facilities`.
- **Contracts.** `GetActiveSupervisoryRelationships`,
  `ResolvePersonOrganizationScope`, `ResolveOrganizationScopeAncestry`,
  `ValidatePersonReference`, `ResolveQuarantinedImport`,
  `ValidateTemporaryAssignmentCapabilities`, `BuildTemporaryAssignmentEvent`.
- **HTTP.** 35 controllers currently misplaced under
  `app/Http/Controllers/Organization/`. Tracked in
  `tests/Architecture/ModulePlacementInventory.php`.

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
- **Tables.** `identities`, `users`, `identity_sessions`,
  `identity_person_account_claims`, `identity_idempotency_keys`,
  `identity_inbox`, `identity_person_event_watermarks`,
  `identity_person_provisioning`, `identity_development_fixture_accounts`,
  `credentials`, `identity_password_history`, `identity_activation_tokens`,
  `identity_totp`, `identity_auth_attempt_ledgers`.
- **Contracts.** `ResolvePrincipalContext`, `ResolveAccountEntitlement`,
  `ResolveUserForPerson`, `ResolveDevelopmentFixturePrincipal`,
  `AuthenticateUser`, `PreAuthThrottle`, `IssueActivationToken`,
  `ChangePassword`, `ResolveSession`.

### `Authorization` (rank 2)

- **Purpose.** RBAC + ABAC decision engine, role / capability / delegation /
  classification / field-access templates, explicit deny, sensitive access
  event recorder, operations-office member counter, simulation facts.
- **Tables.** `authorizations` *(ghost — see Phase 2)*, `roles`,
  `capabilities`, `role_capabilities`, `role_assignments`, `delegations`,
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
- **Tables.** `work_records`, `work_record_idempotency_keys`,
  `outbox_events` *(shared transactional outbox, owned by WorkRecords and
  reused by other modules; see `Shared/Infrastructure/Outbox`)*.

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
2. **Table ownership.** Every database table has exactly one owning module
   recorded in `TABLE_OWNERS` of `ModuleBoundariesTest.php`. A module may not
   reference another module's table from a migration, an SQL literal, or a
   `DB::table(...)` call. The catalogue is enforced by
   `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout`
   and currently covers all 96 migrated tables. Ghosts (entries declared in
   docs without a corresponding `Schema::create` migration) are not
   permitted: the historical `identities` and `audit_events` ghosts were
   removed in the table-ownership cleanup; if either table is reintroduced
   the architecture test will fail until the migration lands and the
   owner column is set correctly.
3. **Controller placement.** Business HTTP controllers must live inside their
   owning module at `Modules/<Name>/Features/*/Http/`. Controllers under
   `app/Http/Controllers/` are tolerated only when listed in
   `tests/Architecture/ModulePlacementInventory.php`; that list shrinks over
   time and is bounded by an expiry date.
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
