---
doc_id: PLN-PLS-001
title: Platform Settings and Operational Control Center V1 Implementation Plan
type: plans
status: accepted
version: 1.0.0
date: 2026-07-23
owner: Software Engineering Lead
reviewers:
- Product Lead
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: At the completion of each task or change to an operational contract
sources:
- docs/superpowers/specs/2026-07-23-platform-settings-v1-design.md
- docs/domain/platform-settings.md
- docs/operations/ha-dr-backup.md
references:
- docs/data-security/authorization-model.md
- docs/engineering/delivery-workflow.md
- docs/engineering/testing-strategy.md
---

# Platform Settings V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a complete operational control center inside the dashboard for
security settings, business calendar, backups, logs, health, alerts, and
maintenance, applying permissions and module boundaries from the screen to the
operational layer.

**Architecture:** `PlatformSettings` owns general settings, calendar, alert and
maintenance policy, and operational references, and exposes small gateways for
backups, health, and logs from their actual owners. Every mutation uses the
identity session, CSRF, an `Authorization` decision, and idempotency/ETag, and
long-running work stays asynchronous. The React interface groups the sections
under `/admin/platform` without transferring ownership of `Audit`,
`Notifications`, or infrastructure secrets.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL/SQLite for tests, Redis, React 19,
TypeScript, Vite, Vitest, Playwright, OpenAPI 3.1, Orval.

## Global Constraints

- Arabic is the default language; English is a user-owned choice handled by `Identity`.
- Time zone is fixed to `Asia/Riyadh`; all stored timestamps are UTC.
- Brand identity is fixed in code; there is no Theme Builder.
- Each module owns its own settings; no join or direct query across business-module tables.
- `PlatformSettings` does not store or display secrets and does not implement a backup engine or archive store.
- Logs are never deleted; they stay active 12 months by default with a 90-day minimum, then are archived permanently.
- No Feature Flags and no application release management in V1.
- Every interface uses the unified components from `apps/web/src/ui/`.
- No commit, push, or merge during execution without explicit user authorization.
- The user's current changes in `apps/api/Modules/WorkRecords/Features/ListAuthorizedWorkRecords/Handler/ListAuthorizedWorkRecordsHandler.php`, `apps/api/routes/console.php`, `apps/api/tests/Feature/SecurityJourneyW13Test.php`, and untracked files are preserved and are not accidentally merged into this package.

---

## File Structure

### Backend ownership

- `apps/api/Modules/PlatformSettings/Domain/` — pure values, states, and policies.
- `apps/api/Modules/PlatformSettings/Contracts/` — read contracts and operational gateways.
- `apps/api/Modules/PlatformSettings/Features/` — vertical slices per operation.
- `apps/api/Modules/PlatformSettings/Http/` — Problem Details and request/response conversion.
- `apps/api/Modules/PlatformSettings/Infrastructure/` — persistence, Outbox, and operational adapters.
- `apps/api/Modules/PlatformSettings/Tests/` — domain, HTTP, and integration tests.
- `apps/api/app/Integrations/PlatformOperations/` — wires Laravel and external services to module contracts; no domain logic here.
- `apps/api/Modules/Identity/` — consumes the published security policy only.
- `apps/api/Modules/Authorization/` — capabilities, roles, and access decisions.
- `apps/api/Modules/Notifications/` — technical alert delivery.

### Frontend ownership

- `apps/web/src/features/platform-settings/` — screens, forms, copy, and layout-specific styles only.
- `apps/web/src/api/platform-settings.ts` — typed wrapper over the generated client and error conversion.
- `apps/web/src/shell/routes.ts` and `navigation.tsx` — routes and the capability-gated sidebar entry.
- `apps/web/src/app/AppWorkspace.tsx` — routes requests to module screens.
- `apps/web/e2e/platform-settings.spec.ts` — the full visual journey.

---

### Task 1: Lock in capabilities, module boundary, and core database

**Files:**
- Modify: `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php`
- Modify: `apps/api/database/seeders/AuthorizationCatalogSeeder.php`
- Modify: `apps/api/Modules/Authorization/Tests/AuthorizationCatalogSeederTest.php`
- Modify: `apps/api/Modules/Authorization/Tests/PlatformOwnerRoleTest.php`
- Create: `apps/api/Modules/PlatformSettings/Infrastructure/Persistence/Migrations/CreatePlatformSettingsTables.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformSettingsSchemaTest.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php`
- Modify: `apps/api/tests/Architecture/ModuleBoundariesTest.php`

**Interfaces:**
- Produces: the `platform_settings.*` and `platform_operations.*` capabilities adopted in the specification.
- Produces: tables `platform_setting_versions`, `platform_settings`, `business_calendars`, `business_calendar_weekdays`, `business_calendar_exceptions`, `platform_maintenance_windows`, `platform_alert_policies`, `platform_operation_requests`, `platform_operation_snapshots`, `platform_settings_outbox`.
- Consumes: UUIDv7, lock version, and existing migration patterns.

- [ ] **Step 1: Write a failing test for capabilities**

Add explicit assertions to `AuthorizationCatalogSeederTest.php`:

```php
$this->assertDatabaseHas('capabilities', [
    'module_code' => 'platform_settings',
    'capability_code' => 'platform_settings.manage',
    'sensitivity' => 'sensitive',
]);
$this->assertDatabaseHas('capabilities', [
    'module_code' => 'platform_operations',
    'capability_code' => 'platform_operations.restore.confirm',
    'sensitivity' => 'sensitive',
]);
```

- [ ] **Step 2: Run the test and prove it fails**

Run:

```bash
cd apps/api && php artisan test Modules/Authorization/Tests/AuthorizationCatalogSeederTest.php Modules/Authorization/Tests/PlatformOwnerRoleTest.php
```

Expected: FAIL because the new capabilities do not exist in `CapabilityCatalog`.

- [ ] **Step 3: Add all capabilities**

Add the fourteen codes from the Capabilities section of the specification to
`CapabilityCatalog::CAPABILITIES`. Classify every `manage`, `run`, `restore`,
and `maintenance` as sensitive in the seeder, and keep read capabilities regular
except for log reads.

- [ ] **Step 4: Write a failing schema test**

`PlatformSettingsSchemaTest` must check:

```php
Schema::hasColumns('platform_setting_versions', [
    'id', 'status', 'content_hash', 'lock_version', 'published_at',
]);
Schema::hasColumns('business_calendars', [
    'id', 'scope_type', 'scope_id', 'parent_calendar_id', 'status', 'lock_version',
]);
Schema::hasColumns('platform_operation_requests', [
    'id', 'operation_type', 'status', 'requested_by', 'confirmed_by', 'reason',
]);
```

And check a single unique published version inside publish logic, not by
assuming a partial index that is incompatible between MySQL and SQLite.

- [ ] **Step 5: Create one module-owned migration**

Use the output table names above, UUIDv7 string columns, UTC timestamps,
`lock_version` starting at 1, and JSON only for the typed payload validated by
the domain layer. Do not add foreign keys to `clusters`, `facilities`, `users`,
or any other module's tables; store external IDs and validate them through
contracts.

- [ ] **Step 6: Register the migration and update the ownership guard**

Add the path to `AppServiceProvider::loadMigrationsFrom()`, and add every
`platform_*` and `business_calendar_*` table to `TABLE_OWNERS` with the value
`PlatformSettings`.

- [ ] **Step 7: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/PlatformSettingsSchemaTest.php Modules/Authorization/Tests/AuthorizationCatalogSeederTest.php Modules/Authorization/Tests/PlatformOwnerRoleTest.php tests/Architecture/ModuleBoundariesTest.php
```

Expected: PASS.

---

### Task 2: Settings version lifecycle and typed security policy

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Domain/SettingKey.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/SecurityPolicy.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/SettingsVersion.php`
- Create: `apps/api/Modules/PlatformSettings/Contracts/GetEffectivePlatformSettings.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Settings/Handler/PlatformSettingsHandler.php`
- Create: `apps/api/Modules/PlatformSettings/Infrastructure/Persistence/DatabasePlatformSettings.php`
- Create: `apps/api/Modules/PlatformSettings/Infrastructure/Outbox/PlatformSettingsOutbox.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformSettingsDomainTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformSettingsLifecycleTest.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces:

```php
interface GetEffectivePlatformSettings
:{
    /** @return array{default_locale:'ar', timezone:'Asia/Riyadh', security:array<string,int>} */
    public function current(): array;
}
```

- Produces: `PlatformSettingsHandler::createDraft()`, `setValue()`,
  `validate()`, `publish()`, `current()`, `listVersions()`.
- Produces Outbox event: `com.cluster.platform-settings.version-published.v1`.

- [ ] **Step 1: Write failing domain tests**

Cover the following values:

```php
SecurityPolicy::fromArray([
    'idle_timeout_minutes' => 30,
    'absolute_session_hours' => 12,
    'minimum_password_length' => 12,
    'password_history_count' => 5,
    'failed_login_attempts' => 5,
    'failed_login_window_minutes' => 15,
    'lockout_minutes' => 30,
]);
```

And test rejection of `minimum_password_length=7`, rejection of a locale other
than `ar|en`, rejection of a time zone other than `Asia/Riyadh`, and prevention
of editing a `published` version.

- [ ] **Step 2: Implement a limited registry**

`SettingKey` contains only an allow-list:

```php
enum SettingKey: string
:{
    case DefaultLocale = 'localization.default_locale';
    case IdleTimeoutMinutes = 'security.idle_timeout_minutes';
    case AbsoluteSessionHours = 'security.absolute_session_hours';
    case MinimumPasswordLength = 'security.minimum_password_length';
    case PasswordHistoryCount = 'security.password_history_count';
    case FailedLoginAttempts = 'security.failed_login_attempts';
    case FailedLoginWindowMinutes = 'security.failed_login_window_minutes';
    case LockoutMinutes = 'security.lockout_minutes';
    case ActiveLogMonths = 'operations.active_log_months';
}
```

Pin `timezone` in the response and do not store it as a changeable key. Keep
security limits as `SecurityPolicy` constants, not in the database.

- [ ] **Step 3: Implement the lifecycle in one transaction**

`publish()` must:

1. Accept only `validated` and a matching `If-Match`.
2. Check that no other non-retired published version exists inside the lock.
3. Retire the previous version.
4. Publish the new one and compute its SHA-256 canonical hash.
5. Write the Outbox event in the same transaction.

- [ ] **Step 4: Test conflict and failure**

Add 409 tests for a second non-allowed draft, 412 for a stale ETag, rollback on
Outbox failure, and reading the latest `published` only.

- [ ] **Step 5: Wire the contract in the container**

Bind `GetEffectivePlatformSettings::class` to `DatabasePlatformSettings::class`
in `AppServiceProvider::register()`.

- [ ] **Step 6: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/PlatformSettingsDomainTest.php Modules/PlatformSettings/Tests/PlatformSettingsLifecycleTest.php
```

Expected: PASS.

---

### Task 3: Business calendar, inheritance, holidays, and Ramadan

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Domain/CalendarScope.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/WorkingWeek.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/CalendarException.php`
- Create: `apps/api/Modules/PlatformSettings/Contracts/ResolveBusinessCalendar.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Calendars/Handler/BusinessCalendarHandler.php`
- Create: `apps/api/Modules/PlatformSettings/Infrastructure/Persistence/DatabaseBusinessCalendars.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/BusinessCalendarDomainTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/BusinessCalendarInheritanceTest.php`

**Interfaces:**
- Produces:

```php
interface ResolveBusinessCalendar
:{
    public function forDate(
        string $scopeType,
        string $scopeId,
        \DateTimeImmutable $date,
    ): EffectiveBusinessDay;
}
```

- `EffectiveBusinessDay` contains `isWorkingDay`, `startsAt`, `endsAt`,
  `sourceScopeType`, `sourceScopeId`, `reason`.

- [ ] **Step 1: Write failing inheritance tests**

Cover:

- A facility inherits the cluster calendar.
- A cluster inherits the platform calendar.
- A facility exception overrides cluster hours on the same date.
- A central public holiday remains a holiday at the facility.
- Work during a central public holiday is rejected without
  `platform_settings.calendar.override_official_holiday`.
- The Ramadan window changes start and end only within its Gregorian range.
- A second working-hours window for the same day is rejected.

- [ ] **Step 2: Implement the scope model**

Allow only `platform`, `cluster`, `facility`. Use `scope_id='platform'` for the
top scope. Validate cluster/facility existence through `Organization`
contracts; do not add FKs or direct queries to its tables.

- [ ] **Step 3: Implement the inheritance resolver**

Read each layer with an independent query against module tables, then apply:

```text
platform weekly schedule
-> cluster weekly override
-> facility weekly override
-> central holiday
-> local closure
-> approved official-holiday work override
-> Ramadan seasonal hours
```

This contract does not set the duration of any task or approval.

- [ ] **Step 4: Test dates in Riyadh time zone**

Use fixed dates that cover a weekend, a central holiday, the first and last
entered Ramadan day, and the day after the range. Verify that the response
returns ISO-8601 with the Riyadh offset while stored timestamps remain UTC.

- [ ] **Step 5: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/BusinessCalendarDomainTest.php Modules/PlatformSettings/Tests/BusinessCalendarInheritanceTest.php tests/Architecture/ModuleBoundariesTest.php
```

Expected: PASS.

---

### Task 4: Identity consumes the published security policy

**Files:**
- Modify: `apps/api/Modules/Identity/Domain/PasswordPolicy.php`
- Modify: `apps/api/Modules/Identity/Features/Authentication/Handler/AuthenticationHandler.php`
- Modify: `apps/api/Modules/Identity/Features/Sessions/Handler/SessionHandler.php`
- Modify: `apps/api/Modules/Identity/Infrastructure/Security/PersistentPreAuthThrottle.php`
- Modify: `apps/api/Modules/Identity/Tests/IdentityCredentialCoreTest.php`
- Create: `apps/api/Modules/Identity/Tests/PlatformSecurityPolicyIntegrationTest.php`

**Interfaces:**
- Consumes: `GetEffectivePlatformSettings::current()`.
- Produces: actual application of the published values to password, session,
  and lockout.

- [ ] **Step 1: Write failing integration tests**

Verify that a published version with `minimum_password_length=14` rejects a
12-character password, that drafts do not affect it, that
`idle_timeout_minutes` ends an idle session, and that the throttle uses the
published count, window, and lockout duration.

- [ ] **Step 2: Inject the contract, not the database**

Add `GetEffectivePlatformSettings` to the relevant handlers. Do not import
`DatabasePlatformSettings` and do not use `DB::table('platform_settings')`
inside `Identity`.

- [ ] **Step 3: Add a safe fallback**

If no published version exists in the bootstrap environment, use the current
fixed values or stricter ones. If the read fails after a published version
exists, use the last valid short-lived memory snapshot and do not use weaker
values.

- [ ] **Step 4: Run Identity tests**

Run:

```bash
cd apps/api && php artisan test Modules/Identity/Tests/PlatformSecurityPolicyIntegrationTest.php Modules/Identity/Tests/IdentityCredentialCoreTest.php Modules/Identity/Tests/IdentityAccountHttpAdapterTest.php
```

Expected: PASS.

---

### Task 5: Health, backup, and restore gateways

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Contracts/PlatformHealthGateway.php`
- Create: `apps/api/Modules/PlatformSettings/Contracts/BackupOperationsGateway.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/PlatformHealthSnapshot.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Operations/Handler/PlatformOperationsHandler.php`
- Create: `apps/api/app/Integrations/PlatformOperations/LaravelPlatformHealthGateway.php`
- Create: `apps/api/app/Integrations/PlatformOperations/CommandBackupOperationsGateway.php`
- Create: `apps/api/config/platform_operations.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformHealthHandlerTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/BackupOperationsHandlerTest.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php`

**Interfaces:**

```php
interface PlatformHealthGateway
:{
    /** @return list<HealthCheckResult> */
    public function snapshot(): array;
}

interface BackupOperationsGateway
:{
    public function status(): BackupStatus;
    public function requestBackup(string $operationId): void;
    public function requestRestoreValidation(string $operationId, string $backupId): void;
}
```

- [ ] **Step 1: Write tests for the mock adapters**

Test a fully green snapshot, a partial snapshot on Redis timeout, and a
rejected response that includes DSN, username, or password. Verify that
repeating `Idempotency-Key` returns the same operation ID and does not run two
backups.

- [ ] **Step 2: Implement the health gateway with bounded timeouts**

Probe database, Redis, storage, queue, Outbox, file scanning, notifications,
and backups. Return only:

```php
new HealthCheckResult(
    code: 'database',
    status: 'healthy',
    checkedAt: $clock->now(),
    latencyMs: 8,
    messageCode: 'reachable',
);
```

Do not return config values or exception stack traces.

- [ ] **Step 3: Implement the backup gateway as a fixed command list**

Read command paths from the environment in `config/platform_operations.php`,
and use `Symfony Process` with a fixed argv from config; do not pass shell
strings or user input to the command. Use a fake gateway in testing. Record
the request and dispatch asynchronously, returning `202`.

- [ ] **Step 4: Implement the separation between restore request and confirmation**

States:

```text
requested -> awaiting_confirmation -> confirmed -> validation_running
-> ready_for_operator | failed | cancelled
```

Require `confirmed_by !== requested_by`, a reason between 10 and 1000
characters, and the separate `restore.request` and `restore.confirm`
capabilities. Do not execute a production restore from HTTP; the
`ready_for_operator` result hands over the runbook and reference only.

- [ ] **Step 5: Wire the adapters**

Bind both contracts in `AppServiceProvider`. Fail boot in production if the
operational property is enabled and there are no allow-listed commands, but
allow health reads even when backup commands are not configured.

- [ ] **Step 6: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/PlatformHealthHandlerTest.php Modules/PlatformSettings/Tests/BackupOperationsHandlerTest.php
```

Expected: PASS.

---

### Task 6: Log search and permanent archiving

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Contracts/TechnicalLogSource.php`
- Create: `apps/api/Modules/PlatformSettings/Contracts/TechnicalLogArchive.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/TechnicalLogEntry.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Logs/Handler/TechnicalLogsHandler.php`
- Create: `apps/api/app/Integrations/PlatformOperations/CompositeTechnicalLogSource.php`
- Create: `apps/api/app/Integrations/PlatformOperations/ObjectStorageTechnicalLogArchive.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/TechnicalLogsHandlerTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/TechnicalLogArchiveTest.php`

**Interfaces:**

```php
interface TechnicalLogSource
:{
    public function search(TechnicalLogFilter $filter): TechnicalLogPage;
}

interface TechnicalLogArchive
:{
    public function archive(ArchiveBatch $batch): ArchiveManifest;
    public function requestRestore(string $manifestId, string $actorId, string $reason): string;
}
```

V1 implementation note: `MockTechnicalLogSource` is the only log source wired
in the first delivery. It returns deterministic redacted fixtures for audit,
security, system, and operations categories and is intentionally replaceable by
the future `Audit` adapter without changing the API or React screens.

- [ ] **Step 1: Write redaction and isolation tests**

Use fixtures that contain `password`, `token`, `authorization`, `cookie`,
`document_content`, `national_id`. Verify that the values do not appear in
`TechnicalLogEntry` and that source, classification, and correlation id remain
visible.

- [ ] **Step 2: Implement the mock source and aggregation without joins**

`MockTechnicalLogSource` returns fixed redacted fixtures. Then
`CompositeTechnicalLogSource` calls the sources one after another via read
contracts: audit, security, system, operations. It merges DTOs in memory,
orders by `occurred_at,id`, and returns a positioned composite cursor. It does
not read two tables in one query.

- [ ] **Step 3: Implement archiving**

Each batch:

- Is older than `active_log_months`.
- Writes a compressed, encrypted object via the storage adapter.
- Writes a manifest containing count, first/last timestamp, SHA-256, and
  storage reference.
- Verifies the hash before marking the batch `archived`.
- Does not delete the source until verification succeeds; after that, moves
  from active to archive semantics without a permanent-delete endpoint.

- [ ] **Step 4: Implement time-bounded restore**

Requires `platform_operations.logs.restore` and a reason, returns a job id.
Restore results are available as a read model for a bounded time, then the
temporary copy is removed and the archive remains.

- [ ] **Step 5: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/TechnicalLogsHandlerTest.php Modules/PlatformSettings/Tests/TechnicalLogArchiveTest.php
```

Expected: PASS.

---

### Task 7: Alert policies and maintenance mode

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Domain/AlertPolicy.php`
- Create: `apps/api/Modules/PlatformSettings/Domain/MaintenanceWindow.php`
- Create: `apps/api/Modules/PlatformSettings/Contracts/PublishTechnicalAlert.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Alerts/Handler/AlertPolicyHandler.php`
- Create: `apps/api/Modules/PlatformSettings/Features/Maintenance/Handler/MaintenanceWindowHandler.php`
- Create: `apps/api/app/Http/Middleware/EnforcePlatformMaintenance.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/AlertPolicyTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/MaintenanceWindowTest.php`
- Modify: `apps/api/bootstrap/app.php`
- Modify: `apps/api/Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationInboxTable.php` only if the existing schema lacks technical recipient capability metadata; otherwise leave unchanged.

**Interfaces:**
- Produces alert recipient selectors as role/capability codes, never user ids.
- Produces `MaintenanceWindow::isActiveAt(DateTimeImmutable $now): bool`.

- [ ] **Step 1: Write policy tests**

Cover severity `info|warning|critical`, channels `in_app|email`, escalation
minutes > 0, recipient capability existing in the catalog, and prevention of
storing user ids.

- [ ] **Step 2: Wire Notifications through a contract**

Implement `PublishTechnicalAlert` via an Outbox event consumed by
`Notifications`. The event must include only `alert_code`, `severity`,
`recipient_capability`, `occurred_at`, and `correlation_id`, and must not
include a secret or stack trace.

- [ ] **Step 3: Write maintenance tests**

Cover:

- Rejecting an end before a start.
- Rejecting an empty Arabic or English message.
- Automatic end.
- GET and login available per policy.
- Normal mutation returning 503 Problem Details with `Retry-After`.
- An admin with `platform_operations.maintenance.manage` bypasses the block.
- Safe background workers are not blocked.

- [ ] **Step 4: Register the middleware**

Add an alias/append in `bootstrap/app.php` after session resolution and before
controllers. Do not block the internal health endpoint required for monitoring.

- [ ] **Step 5: Run the task checks**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/AlertPolicyTest.php Modules/PlatformSettings/Tests/MaintenanceWindowTest.php
```

Expected: PASS.

---

### Task 8: HTTP API, Authorization, and OpenAPI

**Files:**
- Create: `apps/api/Modules/PlatformSettings/Http/PlatformSettingsApi.php`
- Create controllers under:
  - `apps/api/Modules/PlatformSettings/Features/Settings/Http/`
  - `apps/api/Modules/PlatformSettings/Features/Calendars/Http/`
  - `apps/api/Modules/PlatformSettings/Features/Operations/Http/`
  - `apps/api/Modules/PlatformSettings/Features/Logs/Http/`
  - `apps/api/Modules/PlatformSettings/Features/Alerts/Http/`
  - `apps/api/Modules/PlatformSettings/Features/Maintenance/Http/`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformSettingsHttpAdapterTest.php`
- Create: `apps/api/Modules/PlatformSettings/Tests/PlatformOperationsHttpAdapterTest.php`
- Modify: `apps/api/routes/web.php`
- Modify: `docs/contracts/api/openapi.yaml`
- Modify: `docs/api/endpoints.md`
- Modify: `docs/api/rbac-matrix.md`
- Modify generated files through: `npm --prefix apps/web run api:generate`

**Interfaces:**
- Produces paths under `/api/v1/platform-settings/*` and
  `/api/v1/platform-operations/*`.
- Every entity response returns `ETag`; mutations require CSRF and use
  `If-Match` or `Idempotency-Key`.
- Snapshot response contains `status: healthy|degraded|critical`,
  `updated_at`, `issues`, `metrics`, `allowed_actions`.

- [ ] **Step 1: Write failing HTTP tests**

Cover 401 without a session, 403 without capability, 404 outside scope, 409
conflict, 412 ETag, 422 invalid typed value, 202 for long operations, and 200
for partial snapshot.

- [ ] **Step 2: Add explicit routes**

Do not use a generic `{resource}` controller. Use separate controllers for
these routes:

```text
GET  /platform-settings/current
GET  /platform-settings/versions
POST /platform-settings/versions
PUT  /platform-settings/versions/{versionId}/settings/{settingKey}
POST /platform-settings/versions/{versionId}/validate
POST /platform-settings/versions/{versionId}/publish
GET  /platform-settings/calendars
POST /platform-settings/calendars
PUT  /platform-settings/calendars/{calendarId}/weekdays/{weekday}
PUT  /platform-settings/calendars/{calendarId}/exceptions/{date}
POST /platform-settings/calendars/{calendarId}/publish
GET  /platform-operations/overview
GET  /platform-operations/health
GET  /platform-operations/backups
POST /platform-operations/backups
POST /platform-operations/restores
POST /platform-operations/restores/{requestId}/confirm
GET  /platform-operations/logs
POST /platform-operations/log-restores
GET  /platform-operations/alerts/policies
PUT  /platform-operations/alerts/policies/{policyId}
GET  /platform-operations/maintenance
POST /platform-operations/maintenance
POST /platform-operations/maintenance/{windowId}/cancel
```

- [ ] **Step 3: Apply the access decision**

Each controller builds trusted `RecordFacts` from its own record or scope. It
does not accept scope facts from the body. It returns `allowed_actions` from
the decision and writes a sensitive-access record when reading logs or backup
reports.

- [ ] **Step 4: Update OpenAPI from planned to implemented**

Use named schemas closed with `additionalProperties: false` where needed.
Remove `x-implementation-status: planned` only for routes that became real
routes. Add 401/403/404/409/412/422/503 problem responses and headers.

- [ ] **Step 5: Generate the client and check for drift**

Run:

```bash
./scripts/validate-docs.sh
python3 scripts/inventory-routes.py --mode reconcile --write
npm --prefix apps/web run api:generate
npm --prefix apps/web run api:lint
npm --prefix apps/web run api:check
```

Expected: all commands PASS and no implemented path remains tagged as planned.

- [ ] **Step 6: Run HTTP tests**

Run:

```bash
cd apps/api && php artisan test Modules/PlatformSettings/Tests/PlatformSettingsHttpAdapterTest.php Modules/PlatformSettings/Tests/PlatformOperationsHttpAdapterTest.php
```

Expected: PASS.

---

### Task 9: Web routes, sidebar entry, and internal layout

**Files:**
- Create: `apps/web/src/api/platform-settings.ts`
- Create: `apps/web/src/api/platform-settings.test.ts`
- Create: `apps/web/src/features/platform-settings/PlatformSettingsLayout.tsx`
- Create: `apps/web/src/features/platform-settings/platform-settings.css`
- Create: `apps/web/src/features/platform-settings/copy.ts`
- Create: `apps/web/src/features/platform-settings/PlatformSettingsLayout.test.tsx`
- Modify: `apps/web/src/shell/routes.ts`
- Modify: `apps/web/src/shell/routes.test.ts`
- Modify: `apps/web/src/shell/routes.capabilities.test.ts`
- Modify: `apps/web/src/shell/navigation.tsx`
- Modify: `apps/web/src/shell/navigation.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`

**Interfaces:**
- Produces route union `platform-settings` with section:
  `overview|security|calendars|backups|logs|health|maintenance`.
- Navigation appears with any V1 read capability; each inner item appears only
  with its specific capability.

- [ ] **Step 1: Write failing route tests**

Test round-trip for every route from the specification, `not-found` for any
unknown section, and active state for every page in the center.

- [ ] **Step 2: Add the sidebar entry**

Place "Platform Settings" inside the administration group of the existing
sidebar, not as a separate dashboard. Use an existing icon from
`lucide-react`. Hide the entry fail-closed when capabilities are `null`.

- [ ] **Step 3: Build the internal layout**

`PlatformSettingsLayout` uses `Page`, `PageHeader`, `Panel`, `Button`, and
`Feedback`. The internal menu uses a semantic `<nav aria-label>` and supports
the keyboard, collapsing into an appropriate select/list on narrow screens
without a raw `<select>`; use the unified `Select` if needed.

- [ ] **Step 4: Wrap the generated client**

`platform-settings.ts` passes the session, CSRF, idempotency, and ETag through
the current HTTP layer and converts Problem Details into `ApiError`. It does
not duplicate generated types manually.

- [ ] **Step 5: Run the task checks**

Run:

```bash
npm --prefix apps/web run test:unit -- src/api/platform-settings.test.ts src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/features/platform-settings/PlatformSettingsLayout.test.tsx
npm --prefix apps/web run lint
npm --prefix apps/web run build
```

Expected: PASS.

---

### Task 10: Control center and V1 pages

**Files:**
- Create:
  - `apps/web/src/features/platform-settings/PlatformOverviewScreen.tsx`
  - `apps/web/src/features/platform-settings/SecuritySettingsScreen.tsx`
  - `apps/web/src/features/platform-settings/BusinessCalendarsScreen.tsx`
  - `apps/web/src/features/platform-settings/BackupsScreen.tsx`
  - `apps/web/src/features/platform-settings/TechnicalLogsScreen.tsx`
  - `apps/web/src/features/platform-settings/PlatformHealthScreen.tsx`
  - `apps/web/src/features/platform-settings/MaintenanceScreen.tsx`
- Create matching `*.test.tsx` files for every screen.
- Modify: `apps/web/src/features/platform-settings/platform-settings.css`
- Modify: `apps/web/src/app/AppWorkspace.tsx`

**Interfaces:**
- Consumes generated API wrappers from Task 9.
- All mutation buttons render from server `allowed_actions`.

- [ ] **Step 1: Build the control center with state tests**

Test loading, fully healthy, degraded source, critical issue, denied, error,
stale, and empty activity. Adopted order:

1. The action that needs intervention.
2. Four metrics: services, last backup, alerts, storage.
3. Service status.
4. Safe quick actions.
5. Recent activity.

Do not place restore or maintenance activation as a quick action.

- [ ] **Step 2: Build the Security screen**

Show the active version, the draft, the hard limits beside each field, and
create/validate/publish buttons per `allowed_actions`. Use the unified
`Field`, `Select`, and `Button`, and require confirmation before publishing.
Show Arabic default and Riyadh time zone as readable values only.

- [ ] **Step 3: Build the Calendar screen**

Show the platform/cluster/facility scope selector, the source of every
inherited value, the working week, the single daily window, holidays, and the
annual Ramadan entry. Working during a central public holiday requires a Drawer
with a reason, confirmation, and an independent capability.

- [ ] **Step 4: Build the Backups screen**

Show last success, last failure, verification, schedule, and retention.
`Run Backup Now` uses idempotency and shows operation progress. A restore
request uses a justified Drawer and confirmation appears only for a second
user. Do not expose storage paths, credentials, or backup-file links.

- [ ] **Step 5: Build the Logs screen**

Use filters: type, severity, from/to, correlation id, free text. Add cursor
pagination, a source badge, and an archive-restore request. Do not show raw
JSON before server-side redaction.

- [ ] **Step 6: Build Health and Alerts**

Show every service status, probe time, and safe latency, then routing
policies by capability/channel/escalation. Do not show exception traces.

- [ ] **Step 7: Build Maintenance**

Show the current state and upcoming windows. The create form contains start,
end, reason, and both messages. Show the maintenance impact clearly, with
independent confirmation for cancellation.

- [ ] **Step 8: Test language and accessibility**

For every screen test Arabic and English copy, `dir=rtl|ltr`, focus order,
accessible names, the absence of raw `<button>`, `<input>`, and `<select>`
outside the allowed UI components, and the absence of disallowed actions.

- [ ] **Step 9: Run web checks**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/platform-settings
npm --prefix apps/web run lint
npm --prefix apps/web run build
```

Expected: PASS.

---

### Task 11: Integrated journey and delivery gate

**Files:**
- Create: `apps/web/e2e/platform-settings.spec.ts`
- Create: `infra/dev/run-platform-settings-e2e.sh`
- Modify: `Makefile`
- Modify: `docs/plans/active-delivery-status.md` only if the user explicitly requests status documentation during execution.

**Interfaces:**
- Produces Make target: `verify-platform-settings`.

- [ ] **Step 1: Prepare realistic fixtures**

Create a platform-operator user, a security-auditor user, and a user with no
capability. Create a published settings version and a platform/cluster/facility
calendar, a fake backup gateway, a degraded health source, and redacted logs.
Do not depend on production credentials.

- [ ] **Step 2: Write Playwright journeys**

Cover:

1. An unauthorized user does not see the menu and receives 403 from the API.
2. The administrator opens the center and sees the snapshot.
3. They create a security draft, fail on a weaker value, then validate and
   publish a valid value.
4. `Identity` applies the published policy in a new session.
5. A facility inherits the calendar then adds Ramadan and a local closure.
6. Running an immediate backup shows progress and does not repeat on
   double-click.
7. A restore request is not confirmed by the requester and is confirmed by a
   second actor.
8. Log search does not disclose fixture secrets.
9. A degraded health state shows a warning without dropping the page.
10. Maintenance mode blocks normal mutations and ends automatically.
11. The core visual journeys are repeated in Arabic RTL and English LTR.
12. Every deep link reloads successfully.

- [ ] **Step 3: Add the Make gate**

```make
verify-platform-settings:
	./scripts/validate-docs.sh
	cd apps/api && php artisan test Modules/PlatformSettings/Tests Modules/Identity/Tests/PlatformSecurityPolicyIntegrationTest.php tests/Architecture/ModuleBoundariesTest.php
	composer --working-dir=apps/api lint
	composer --working-dir=apps/api analyse -- --memory-limit=512M
	npm --prefix apps/web run api:check
	npm --prefix apps/web run test:unit -- src/features/platform-settings src/api/platform-settings.test.ts src/shell/routes.test.ts src/shell/routes.capabilities.test.ts
	npm --prefix apps/web run lint
	npm --prefix apps/web run build
	./infra/dev/run-platform-settings-e2e.sh
```

- [ ] **Step 4: Run the targeted gate**

Run:

```bash
make verify-platform-settings
```

Expected: all contract, API, boundary, web, and E2E stages end with exit 0.

- [ ] **Step 5: Run proportional regression checks**

Run:

```bash
make verify-w1-1
make verify-w1-2
make verify-w1-3
make verify-day2
make verify-day3
```

Expected: PASS. If a failure is environmental, record the command, error, and
evidence and do not describe the module as complete until the failure is gone
or is proven unrelated to the change.

- [ ] **Step 6: Independent review**

Review the diff for:

- No cross-module table reads.
- No leaks of secrets/PII.
- Separation between restore request and confirmation.
- Server-driven `allowed_actions` application.
- Coverage of 401/403/404/409/412/422/503.
- RTL/LTR and keyboard correctness.
- No modification of unrelated user files.

- [ ] **Step 7: Close execution with evidence**

Hand over the file list, the actual command results, any N/A with its reason,
and CI state if available. Do not declare completeness from code presence alone.

---

## Delivery Order and Review Gates

1. Tasks 1–3: module foundation, settings, and calendar.
2. Task 4: prove that security settings drive actual behavior.
3. Tasks 5–7: independent operational capabilities.
4. Task 8: lock in HTTP/OpenAPI/Orval after behavior stabilizes.
5. Tasks 9–10: the full interface inside the dashboard.
6. Task 11: E2E, regression, review, and close.

After every Task the diff is reviewed and the task's commands run before
moving on. The interface is never built on a permanent mock; fakes are allowed
in testing only, and the screen is wired to the real API contract when closing
every vertical slice.
