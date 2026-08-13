# Backend and Database Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the confirmed authorization, migration, data-integrity, outbox, and production-operation defects in the task-only Cluster system.

**Architecture:** Resource authorization must always use facts derived from the target resource, with relationship rules applied only in addition to RBAC/ABAC. Database retirement is forward-only: preserve historical migrations, add guarded corrective migrations, clean derived data before source deletion, and require a verified backup acknowledgement before destructive production migration. Operational commands must have explicit bounded worker or scheduler ownership.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, SQLite/MySQL 8.4, Redis streams, shell/Bats, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-08-13-task-core-scope-reset.md`

## Global Constraints

- Retain only `cluster`, `facility`, `unit`, and `record_set` authorization scope types.
- Build authorization facts from the target resource or requested target parent, never from the caller as a substitute.
- Preserve concealment semantics: a target outside scope returns 404 where the existing endpoint conceals authorization.
- Explicit deny and revoked capability override task creator, assignee, participant, and manager relationships.
- Do not edit generated API code by hand.
- Do not execute destructive migrations against production or any user database.
- Do not commit, push, merge, delete branches, or discard unrelated work.

---

### Task 1: Enforce Organization target and collection scope

**Files:**
- Create: `apps/api/Modules/Organization/Features/Authorization/OrganizationResourceFacts.php`
- Modify: Organization unit and position get/create/update/list/reorder controllers and handlers under `apps/api/Modules/Organization/Features/`
- Test: `apps/api/Modules/Organization/Tests/OrganizationTreeHttpAdapterTest.php`
- Test: `apps/api/Modules/Organization/Tests/OrganizationUnitReorderHttpAdapterTest.php`

**Interfaces:**
- Consumes: authoritative `facilities`, `organization_units`, and `positions` rows.
- Produces: `factsForFacility(string): ?RecordFacts`, `factsForUnit(string): ?RecordFacts`, and `factsForPosition(string): ?RecordFacts`; null means nonexistent or ancestry cannot be proven.

- [ ] Add a cross-facility get/update regression test that grants Facility A scope, targets Facility B, and expects concealed denial.
- [ ] Run the focused test and verify it fails because caller Facility A facts are used.
- [ ] Add mixed-facility collection and reorder tests proving Facility A cannot list or mutate Facility B rows.
- [ ] Run the focused tests and verify their current cross-scope exposure.
- [ ] Implement one authoritative facts resolver and use it before target authorization.
- [ ] Scope list queries to authorized ancestry; reject global reorder unless the grant covers the affected root.
- [ ] Run Organization tests and `tests/Architecture/ModuleBoundariesTest.php` to green.

### Task 2: Enforce Task capabilities, participant scope, and canonical ownership

**Files:**
- Modify: `apps/api/Modules/Tasks/Features/Http/TaskController.php`
- Modify: `apps/api/Modules/Tasks/Features/Http/TaskEngagementController.php`
- Modify: `apps/api/Modules/Tasks/Features/TransitionTask/Handler/TransitionTaskHandler.php`
- Modify: `apps/api/Modules/Tasks/Application/TaskAccessPolicy.php`
- Modify: `apps/api/Modules/Tasks/Features/CreateTask/Handler/CreateTaskHandler.php`
- Modify: Task authorization and HTTP tests under `apps/api/Modules/Tasks/Tests/`

**Interfaces:**
- Consumes: canonical task facts from `TaskAccessPolicy::factsFor()` and active person/account organization ancestry.
- Produces: collection items authorized by `tasks.read`; mutations authorized by the exact `tasks.update`, `tasks.assign`, `tasks.start`, `tasks.complete`, or `tasks.cancel` capability; persisted task ownership equals the facts used for create authorization.

- [ ] Add tests showing revoked or explicitly denied `tasks.read` removes an otherwise related task from the list.
- [ ] Add tests showing creator/assignee relationship cannot bypass explicit `tasks.update`, `tasks.complete`, or `tasks.cancel` denial.
- [ ] Add tests rejecting creation or enrollment of a participant outside the task facility scope.
- [ ] Add a test showing omitted ownership persists the caller's authoritative facility owner and remains readable through show.
- [ ] Run each test before implementation and confirm the expected failure.
- [ ] Apply per-record read decisions with bounded over-fetch, exact mutation capability decisions, participant ancestry validation, and one canonical owner.
- [ ] Run all Tasks tests and the task-only journey to green.

### Task 3: Repair retirement and data migrations

**Files:**
- Modify: `apps/api/Shared/Infrastructure/Migrations/W27RetireWorkManagement.php`
- Modify: `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/W27RemoveWorkManagementLinks.php`
- Restore: historical seed behavior in `apps/api/Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php`
- Create: a forward W27 corrective migration for retained reporting seed and retirement-derived cleanup
- Modify: `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/W2AddOrganizationJobTitlesTable.php`
- Modify: `apps/api/config/module_migrations.php`
- Modify: `apps/api/phpunit.mysql.xml`
- Test: migration tests under `apps/api/tests/Feature/` and retained MySQL integration tests

**Interfaces:**
- Capability retirement matches literal prefixes `work_record.`, `work_definition.`, and `workflow.` only.
- Retired Search/Reporting rows and active WorkRecord document links are deleted or tombstoned before source tables disappear.
- Arabic job-title codes are deterministic and collision-safe.
- Existing migrations remain immutable; changed reporting seed is delivered by a new forward migration.

- [ ] Add negative capability fixtures such as `workXrecord.read` and prove W27 currently deletes them.
- [ ] Add legacy projection/document-link fixtures and prove W27 leaves them stale.
- [ ] Add two Arabic-only position titles and prove the current backfill links fewer than both.
- [ ] Add an upgrade fixture that starts from the retained pre-W27 schema and data.
- [ ] Replace wildcard deletion with escaped/exact prefix matching; clean derived rows and links transactionally before source removal.
- [ ] Generate deterministic job-title codes using transliteration when available and a stable hash suffix for empty/colliding slugs.
- [ ] Restore the historical reporting migration and add a new guarded data migration for `tasks-overview`.
- [ ] Replace deleted MySQL test paths with the retained upgrade and concurrency tests.
- [ ] Run SQLite migration tests, `phpunit --list-tests`, then the strict MySQL gate.

### Task 4: Close reporting, notification, and Task outbox gaps

**Files:**
- Modify: `apps/api/Modules/Reporting/Features/DownloadExportArtifact/Handler/DownloadExportArtifactHandler.php`
- Modify: `apps/api/Modules/Reporting/Features/CreateReportExport/Http/CreateReportExportController.php`
- Modify: `apps/api/Modules/Notifications/Infrastructure/Persistence/DatabaseRecordNotifications.php`
- Modify: `apps/api/Modules/Notifications/Contracts/RecordTaskNotifications.php`
- Modify: `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`
- Modify/Create: Task relay command and worker lane under Tasks/Shared and `apps/api/routes/console.php`
- Test: Reporting, Notifications, outbox catalog, and worker-loop tests

**Interfaces:**
- Artifact downloads require creator ownership unless an explicit sharing policy exists; CSV is regenerated from rows reauthorized at download time.
- Task notifications persist authoritative source facility/classification and fail closed when facts are unavailable.
- Task events use `com.cluster.tasks.<event>.v1` catalogue names and a bounded relay lane that marks successful rows published.

- [ ] Add failing cross-actor CSV and revoked-row CSV tests.
- [ ] Add a failing notification masking test with absent/current source facts.
- [ ] Add failing catalogue and relay tests for task-created and lifecycle events.
- [ ] Implement ownership and per-item CSV reauthorization, authoritative notification facts, canonical Task event names, and bounded relay ownership.
- [ ] Run Reporting, Notifications, outbox, and Bats worker-loop tests to green.

### Task 5: Make production configuration and destructive deployment fail closed

**Files:**
- Modify: `apps/api/config/documents.php`
- Modify: `apps/api/Modules/Documents/Providers/DocumentsServiceProvider.php`
- Modify: `infra/platform/production/compose.yaml`
- Modify: `infra/platform/production/.env.example`
- Modify: `scripts/production_bundle_policy.py`
- Modify: `infra/platform/production/deploy-vps.sh`
- Create: `docs/operations/ha-dr-backup.md`
- Modify: scheduler/worker ownership in `apps/api/routes/console.php` and `apps/api/docker/worker-loop.sh`
- Test: production policy tests, Documents configuration tests, worker-loop Bats tests

**Interfaces:**
- One `DOCUMENTS_PRODUCTION_RUNTIME_ENABLED` predicate controls validation and adapter selection.
- Production bundle validation requires storage, ClamAV, worker identity, audit enforcement, and destructive-migration acknowledgement inputs.
- Deployment refuses destructive W27 migration without a dated backup identifier and successful restore-validation identifier.
- Every bounded maintenance command has exactly one scheduler or worker owner.

- [ ] Add a configuration test proving missing production Documents variables fail at boot/policy validation.
- [ ] Add a deployment-policy test proving W27 cannot run without backup and restore evidence.
- [ ] Add schedule/worker tests covering platform operations, reporting purge, document retention, alerts, notification DLQ, audit integrity, and Task relay.
- [ ] Implement the single runtime predicate, required Compose variables, backup gate and runbook, and bounded schedules/lanes.
- [ ] Run production bundle policy, configuration tests, `schedule:list`, and Bats tests to green.

### Task 6: Dependency, stale-evidence, and full verification gate

**Files:**
- Modify: `apps/web/package.json` and `apps/web/package-lock.json` only through npm commands.
- Modify/delete: stale API, architecture, compliance, Redoc, and analysis artifacts that still present retired modules as active.
- Modify: `.github/workflows/ci.yml` to run both Composer and npm audits.

**Interfaces:**
- Runtime dependencies contain no CLI-only `shadcn` package.
- Generated/current documentation contains no active WorkRecords, WorkDefinitions, or Workflow route/worker claims.
- CI treats Composer audit, npm audit, MySQL upgrade, architecture, and secret-history scan as required gates.

- [ ] Move/remove CLI-only packages from runtime dependencies and update vulnerable transitive packages through supported direct versions.
- [ ] Run `npm audit` and classify any remaining advisory by runtime reachability and available fix.
- [ ] Regenerate current API/RBAC inventories and archive historical analysis explicitly.
- [ ] Add Composer audit to CI and verify the workflow syntax.
- [ ] Run `make verify-intake`, `make verify-core`, strict MySQL integration, `make test-web`, docs validation, dependency audits, production bundle validation, and full-history secret scan.
- [ ] Record exact residual failures; do not claim completion while any required gate is red.
