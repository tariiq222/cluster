# Remove Work Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce Cluster to the approved organization-scoped task core by removing WorkRecords, WorkDefinitions, Workflow, and every product/runtime contract that exposes them.

**Architecture:** Retire the three modules as one bounded subsystem, first making Tasks explicitly source-independent, then removing runtime registrations and integrations, and finally shrinking OpenAPI and the React surface. A dedicated retirement migration handles existing databases while fresh databases omit the retired creation migrations.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit, MySQL/SQLite migrations, OpenAPI, Orval, React 19, TypeScript, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-13-task-core-scope-reset.md`

## Global Constraints

- Preserve `Organization`, `Identity`, `Authorization`, `Tasks`, `Documents`, `Notifications`, `Audit`, and `PlatformSettings` behavior.
- Retain Search and Reporting only for retained resources.
- Preserve `Shared/Infrastructure/Outbox` and `outbox_events`.
- Do not modify generated API code by hand; edit `docs/contracts/api/openapi.yaml`, run Orval, then format generated output.
- Do not run any production migration or delete production data.
- Do not commit, push, merge, or delete branches without fresh user authorization.

---

### Task 1: Add executable scope guards

**Files:**
- Create: `apps/api/tests/Architecture/TaskCoreScopeTest.php`
- Modify: `apps/api/tests/Feature/TaskOnlyWorkspaceJourneyTest.php`

**Interfaces:**
- Consumes: current Laravel route collection, `CapabilityCatalog`, `config('module_migrations')`, and filesystem module locations.
- Produces: guards that fail while any retired module/provider/route/capability/schema coupling remains.

- [ ] **Step 1: Write failing architecture tests**

```php
public function test_retired_work_management_modules_and_runtime_surface_are_absent(): void
{
    foreach (['WorkRecords', 'WorkDefinitions', 'Workflow'] as $module) {
        self::assertDirectoryDoesNotExist(base_path('Modules/'.$module));
    }
    self::assertArrayNotHasKey('work_management', config('features'));
}

public function test_task_schema_has_no_generic_source_or_workflow_columns(): void
{
    foreach (['workflow_step_id', 'source_module', 'source_type', 'source_id'] as $column) {
        self::assertFalse(Schema::hasColumn('tasks', $column));
    }
}
```

- [ ] **Step 2: Assert retired route and capability prefixes are absent**

Build route URI and capability-code lists from real runtime objects and assert no value starts with `work-records`, `work-definitions`, `workflow`, `work_record.`, `work_definition.`, `workflow.`, or equals `work_management.history.read`.

- [ ] **Step 3: Run RED tests**

Run: `cd apps/api && php artisan test tests/Architecture/TaskCoreScopeTest.php tests/Feature/TaskOnlyWorkspaceJourneyTest.php`

Expected: FAIL because the modules, routes, feature flag, capabilities, and task columns still exist.

### Task 2: Make Tasks a source-independent aggregate

**Files:**
- Modify: `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/CreateTasksTable.php`
- Create: `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/RetireTaskWorkManagementLinks.php`
- Modify: `apps/api/Modules/Tasks/Infrastructure/Persistence/TaskHttpStore.php`
- Modify: `apps/api/Modules/Tasks/Features/CreateTask/Handler/CreateTaskHandler.php`
- Modify: `apps/api/Modules/Tasks/Features/Http/TaskController.php`
- Modify: `docs/contracts/api/openapi.yaml`
- Test: existing `apps/api/Modules/Tasks/Tests/TasksHttpControllerTest.php` and task feature tests

**Interfaces:**
- Consumes: direct task request fields and organization-scoped authorization facts.
- Produces: task DTOs without `source_*` or `workflow_step_id`; additive retirement migration drops legacy columns only when present.

- [ ] **Step 1: Extend task contract tests to reject legacy source fields**

Assert direct task creation ignores/rejects `source` input and responses contain none of `source_module`, `source_type`, `source_id`, or `workflow_step_id`.

- [ ] **Step 2: Run RED task tests**

Run: `cd apps/api && php artisan test Modules/Tasks tests/Feature/TaskOnlyWorkspaceJourneyTest.php`

Expected: FAIL because task serialization and schema still expose legacy links.

- [ ] **Step 3: Remove legacy task fields and add retirement migration**

Remove the four fields from new schema, store input, request hashing, serialization, and OpenAPI task schemas. In `RetireTaskWorkManagementLinks`, use `Schema::hasColumn` guards and drop `workflow_step_id` before the three source fields.

- [ ] **Step 4: Run GREEN task tests**

Run: `cd apps/api && php artisan test Modules/Tasks tests/Feature/TaskOnlyWorkspaceJourneyTest.php`

Expected: PASS with task create/assign/lifecycle/engagement/document behavior intact.

### Task 3: Retire backend modules and integrations

**Files:**
- Delete: `apps/api/Modules/WorkRecords/`
- Delete: `apps/api/Modules/WorkDefinitions/`
- Delete: `apps/api/Modules/Workflow/`
- Create: `apps/api/Shared/Infrastructure/Migrations/RetireWorkManagementTables.php`
- Modify: `apps/api/config/module_migrations.php`
- Modify: `apps/api/config/features.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php`
- Modify: `apps/api/routes/web.php`
- Delete: `apps/api/app/Http/Middleware/EnforceWorkManagementFeature.php`
- Delete: `apps/api/app/Http/Middleware/ProjectWorkRecordReadModels.php`
- Modify/Delete: work-record console commands and notification consumer under `apps/api/routes/console.php` and `Modules/Notifications/Features/ConsumeWorkRecordSubmitted/`
- Modify: retained module integrations found by `rg 'work_record|work_definition|workflow|work_management|WorkRecord|WorkDefinition' apps/api`

**Interfaces:**
- Consumes: Task 2 source-independent task contract.
- Produces: Laravel application that boots without retired providers/contracts and a migration that drops only retired tables.

- [ ] **Step 1: Implement guarded retirement migration**

Drop child tables before parents: workflow decisions/steps/instances/versions/definitions, work-record idempotency/records, and work-definition fixtures/idempotency/versions/definitions. Never drop shared `outbox_events`.

- [ ] **Step 2: Remove registrations, routes, providers, workers, and module trees**

Remove the retired migration paths from `module_migrations.php`, append the retirement migration and task-link migration, and remove all retired imports from the composition root and routes.

- [ ] **Step 3: Remove retained-module special cases**

Remove WorkRecords search backfill, reporting default capability, notifications authorization/linking, document source normalization, capability catalog entries, fixtures, and seed data while retaining generic `RecordFacts.workflowState` only if another retained resource uses it; otherwise remove it consistently.

- [ ] **Step 4: Run architecture RED-to-GREEN gate**

Run: `cd apps/api && php artisan test tests/Architecture/TaskCoreScopeTest.php tests/Architecture/ModuleBoundariesTest.php`

Expected: PASS; no retired module directory, route, provider, migration registration, or capability remains.

### Task 4: Shrink OpenAPI and React to the task core

**Files:**
- Modify: `docs/contracts/api/openapi.yaml`
- Regenerate: `apps/web/src/api/generated/cluster.ts`
- Modify: `apps/web/src/router.tsx`
- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/app/principal-context.tsx`
- Modify: `apps/web/src/api/hooks.ts`
- Modify: `apps/web/src/features/dashboard/HomeDashboard.tsx`
- Modify: `apps/web/src/features/search/SearchScreen.tsx`
- Modify: `apps/web/src/features/notifications/NotificationsScreen.tsx`
- Modify: `apps/web/src/components/command-menu.tsx`
- Modify: affected web tests and copy catalogs

**Interfaces:**
- Consumes: authoritative retained Laravel route surface and task-only feature projection.
- Produces: generated client and React route tree with no retired operations, types, navigation, or links.

- [ ] **Step 1: Add/adjust failing web expectations**

Assert the principal feature shape is `{ tasks: boolean }`, route configuration never contains work-record/workflow paths, and search/notification routing handles only retained resource types.

- [ ] **Step 2: Run RED web tests**

Run: `npm --prefix apps/web run test:unit -- src/app/AppShell.test.tsx src/router.test.tsx src/app/principal-context.test.tsx`

Expected: FAIL while `work_management` remains in types and routes.

- [ ] **Step 3: Remove OpenAPI paths/components and regenerate**

Delete all feature-gated work-management paths, component schemas/responses/parameters, feature descriptor, and task source/workflow fields. Run `npm --prefix apps/web run api:generate` and `npm --prefix apps/web run api:format`.

- [ ] **Step 4: Remove React surface and run GREEN tests**

Remove feature branches, placeholder routes, hooks, copy, and resource links. Run `npm --prefix apps/web run test:unit` and `npm --prefix apps/web run api:check`.

Expected: PASS with Tasks still navigable and buildable.

### Task 5: Align product explanation, documentation, and complete verification

**Files:**
- Modify: `docs/product/system-user-experience.html`
- Modify: `.ai/NORTH_STAR.md`
- Modify: `.ai/CURRENT_STATE.md`
- Modify: `PRODUCT.md`
- Modify: `docs/architecture/module-catalog.md`
- Modify: `docs/design/PAGES.md`
- Modify: generated API inventories via repository scripts
- Modify/Delete: analysis and evidence references that claim retired modules are active

**Interfaces:**
- Consumes: final task-core module and route inventory.
- Produces: user-facing and engineering documentation that describes the same product.

- [ ] **Step 1: Rewrite the standalone HTML around scoped tasks**

Replace record/approval examples with task creation, assignment, comments, attachments, lifecycle, notification, and audit examples. Keep the three role/scope simulator and explain that role answers “what” while scope answers “where.”

- [ ] **Step 2: Reconcile architecture and API inventories**

Run the repository route/doc generation scripts, then update the module catalog counts/ranks and product documents so retired modules are absent rather than marked disabled.

- [ ] **Step 3: Run focused product smoke**

Serve `docs/product/system-user-experience.html` locally and use Chrome to verify all three role switches, task examples, journey controls, RTL, mobile viewport, no horizontal overflow, and no console errors.

- [ ] **Step 4: Run full verification**

Run:

```sh
make test-api
make test-web
make verify-boundaries
make lint-api
make analyse-api
make verify-intake
bash scripts/validate-docs.sh
npm --prefix apps/web run api:check
```

Expected: every command exits `0`; report exact passed/skipped counts and any environment-dependent skips.
