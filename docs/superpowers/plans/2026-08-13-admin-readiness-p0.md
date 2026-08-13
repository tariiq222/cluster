# Admin Readiness P0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide contract-accurate, authorization-safe administrative task listing and correct organization navigation for non-technical administrators.

**Architecture:** OpenAPI is corrected first and Orval output is regenerated. Tasks then adds a scope-aware query path that resolves Organization descendants through a published contract and still authorizes every returned row. Human labels are composed through Identity and Organization contracts, and the React task workspace consumes the typed task projection. Navigation remains capability-derived.

**Tech Stack:** Laravel 13/PHP 8.3, MySQL/SQLite tests, OpenAPI 3.1, Orval fetch client, React 19, TypeScript 6, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-13-admin-readiness-p0.md`

## Global Constraints

- `docs/contracts/api/openapi.yaml` is the only editable API contract source.
- Never hand-edit `apps/web/src/api/generated/cluster.ts` or `.orval` output.
- Authorization scope types remain exactly `cluster | facility | unit | record_set`.
- Session context never grants access; the server authorizes the requested scope and every returned task.
- Modules access other modules only through `Contracts` or `Events`; Tasks never reads Identity or Organization tables directly.
- Preserve all unrelated dirty-worktree changes and do not commit, merge, push, or apply production migrations.
- Every behavior change follows RED → GREEN with the focused test command recorded.

---

### Task 1: Make OpenAPI the accurate task and explicit-deny contract

**Files:**
- Modify: `docs/contracts/api/openapi.yaml`
- Modify: `apps/api/tests/Feature/TaskContractAlignmentTest.php`
- Generated: `apps/web/src/api/generated/cluster.ts`

**Interfaces:**
- Produces: `ListTasksView = mine | scope`, query fields `view`, `scope_type`, `scope_id`; `TaskCreate.owner_organization_unit_id`; priority `urgent`; `explicit-denies` admin resource.
- Consumes: existing `/tasks`, `/authorization/{adminResource}`, `Task`, and Orval generation conventions.

- [ ] **Step 1: Write the failing contract assertions**

Add a test that loads `docs/contracts/api/openapi.yaml` with `yaml_parse_file` when available, otherwise Symfony YAML is not assumed; read the text and assert exact contract fragments with ordered regular expressions. Assert that `TaskCreate` contains `owner_organization_unit_id`, `TaskPatch` contains `urgent` and not `critical`, `/tasks` exposes the three new query names, every authorization admin enum includes `explicit-denies`, and the create summary no longer contains `source-linked`.

- [ ] **Step 2: Verify RED**

Run: `cd apps/api && php artisan test tests/Feature/TaskContractAlignmentTest.php --compact`

Expected: FAIL because the current contract omits the owner and scope query fields, uses `critical` in `TaskPatch`, omits `explicit-denies`, and says source-linked.

- [ ] **Step 3: Correct the contract minimally**

Add these query parameters to `GET /tasks`:

```yaml
- name: view
  in: query
  schema:
    type: string
    enum: [mine, scope]
    default: mine
- name: scope_type
  in: query
  schema:
    type: string
    enum: [cluster, facility, unit]
- name: scope_id
  in: query
  schema:
    $ref: '#/components/schemas/UUIDv7'
```

Add `owner_organization_unit_id` to `TaskCreate`, replace `critical` with `urgent` in `TaskPatch`, change the create summary to `Create a standalone task`, and add `explicit-denies` to every `adminResource` enum for list/create/get/update/action operations.

- [ ] **Step 4: Regenerate and verify GREEN**

Run:

```bash
npm --prefix apps/web run api:generate
cd apps/api && php artisan test tests/Feature/TaskContractAlignmentTest.php --compact
npm --prefix apps/web run api:check
```

Expected: contract test PASS and generation check exits 0.

### Task 2: Add authorization-safe scope task listing

**Files:**
- Modify: `apps/api/Modules/Tasks/Features/Http/TaskController.php`
- Modify: `apps/api/Modules/Tasks/Infrastructure/Persistence/TaskHttpStore.php`
- Modify: `apps/api/Modules/Tasks/Application/TaskAccessPolicy.php`
- Test: `apps/api/Modules/Tasks/Tests/TasksPermissionsTest.php`

**Interfaces:**
- Consumes: Task 1 query contract; `Modules\Organization\Contracts\ResolveScopeDescendants`; `ResolveOrganizationScopeAncestry`.
- Produces: `TaskHttpStore::listForOwnerScopeIds(array $ownerScopeIds, ?string $state, ?string $cursor, int $limit): array` and `TaskAccessPolicy::factsForRequestedScope(string $scopeType, string $scopeId): ?RecordFacts`.

- [ ] **Step 1: Write failing HTTP tests**

Cover four independent cases: facility-scoped manager sees tasks owned by the facility and descendant units; cluster manager sees tasks in two facilities; unit grant sees only the exact unit; a caller without `tasks.read` on the requested scope gets 403 before an empty result can be returned. Add validation cases for missing/extra scope parameters returning 400.

- [ ] **Step 2: Verify RED**

Run: `cd apps/api && php artisan test Modules/Tasks/Tests/TasksPermissionsTest.php --filter='scope task list' --compact`

Expected: FAIL because `view=scope` is ignored and the store only selects relationship-visible tasks.

- [ ] **Step 3: Implement the minimal scoped path**

Validate `view` and the scope pair in `TaskController::index`. Build requested scope facts through authoritative ancestry and call `tasks.read` before querying. Build owner IDs as follows:

```php
$ownerIds = match ($scopeType) {
    'unit' => [$scopeId],
    'facility' => [$scopeId, ...$unitDescendantIds],
    'cluster' => $facilityAndUnitDescendantIds,
};
```

Query only Tasks-owned `owner_organization_unit_id` values, retain cursor/state filters, and run each candidate through the existing RecordFacts decision before serialization. `relationship` is valid only for `view=mine`.

- [ ] **Step 4: Verify GREEN and boundaries**

Run:

```bash
cd apps/api && php artisan test Modules/Tasks/Tests/TasksPermissionsTest.php --compact
make verify-boundaries
```

Expected: focused tests and architecture boundary checks PASS.

### Task 3: Return human task labels and consume them in the task list

**Files:**
- Create: `apps/api/Modules/Identity/Contracts/ListUserDisplayLabels.php`
- Create: `apps/api/Modules/Identity/Infrastructure/Persistence/DatabaseListUserDisplayLabels.php`
- Modify: Identity service provider to bind the contract.
- Modify: `apps/api/Modules/Tasks/Features/Http/TaskController.php`
- Modify: `docs/contracts/api/openapi.yaml`
- Generated: `apps/web/src/api/generated/cluster.ts`
- Modify: `apps/web/src/features/tasks/TasksScreen.tsx`
- Test: Tasks HTTP test and `apps/web/src/features/tasks/TasksScreen.test.tsx`

**Interfaces:**
- Produces: batch map `array<string,string>` from user→display label; task fields `owner_scope`, `assignee`, `creator`.
- Consumes: Identity-owned replicated account display labels, Task rows, Organization scope labels, and Task 1 generated client.

- [ ] **Step 1: Write failing API and component tests**

API test asserts task list items include `owner_scope.label`, `assignee.display_name`, and `creator.display_name` without direct cross-module SQL. Component test asserts names are primary text and UUIDs are not rendered when labels exist.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd apps/api && php artisan test Modules/Tasks/Tests --filter='display labels' --compact
npm --prefix apps/web run test:unit -- TasksScreen.test.tsx
```

Expected: FAIL because the projection currently returns only raw user IDs and no owner label.

- [ ] **Step 3: Implement batch label contracts and projection**

Identity maps user IDs directly from its owned `users.display_name_ar` projection in one batch. Tasks composes that published contract with `ListOrganizationScopeTargets` for the owner label. Missing labels fall back to the existing UUID field but never fail the task response. Update OpenAPI task schemas and regenerate Orval before updating the screen.

- [ ] **Step 4: Verify GREEN**

Run:

```bash
npm --prefix apps/web run api:generate
cd apps/api && php artisan test Modules/Tasks/Tests --compact
npm --prefix apps/web run test:unit -- TasksScreen.test.tsx
npm --prefix apps/web run api:check
make verify-boundaries
```

Expected: focused API/web tests, generation check, and boundaries PASS.

### Task 4: Correct organization navigation capability mapping

**Files:**
- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/features/organization/OrganizationScreen.tsx`
- Test: `apps/web/src/app/AppShell.test.tsx`
- Test: `apps/web/src/features/organization/OrganizationScreen.test.tsx`

**Interfaces:**
- Consumes: principal capability codes.
- Produces: organization entry visible for any supported organization read capability; capability-to-tab mapping aligned with the server catalog and guards.

- [ ] **Step 1: Write failing persona tests**

Assert that a principal with only `organization.unit.read` sees the organization navigation entry plus structure/supervisory; `organization.position.read` exposes positions/job titles; and unrelated principals see neither.

- [ ] **Step 2: Verify RED**

Run: `npm --prefix apps/web run test:unit -- AppShell.test.tsx OrganizationScreen.test.tsx`

Expected: FAIL because the shell only checks cluster/facility, so unit/position/person administrators can own real server capabilities without seeing the organization entry.

- [ ] **Step 3: Implement the exact capability matrix**

Define one shared list or helper for supported organization read capabilities from the actual server catalog. Keep job titles under `organization.position.read` and supervisory relationships under `organization.unit.read`, matching their API guards. Do not invent frontend-only capabilities, add role-name checks, or treat client filtering as authorization.

- [ ] **Step 4: Verify GREEN**

Run:

```bash
npm --prefix apps/web run test:unit -- AppShell.test.tsx OrganizationScreen.test.tsx
npm --prefix apps/web run lint
npm --prefix apps/web run build
```

Expected: focused tests, lint, and build PASS.

### Task 5: Integrated verification and production-migration boundary

**Files:**
- Verify only: `apps/api/Shared/Infrastructure/Migrations/W27RetireWorkManagement.php`
- Verify only: `docs/operations/ha-dr-backup.md`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: fresh local evidence and an explicit external blocker for production W27.

- [ ] **Step 1: Run integrated gates**

Run:

```bash
make verify-core
make verify-mysql-integration REQUIRE_MYSQL_INTEGRATION=1
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
npm --prefix apps/web run lint
```

- [ ] **Step 2: Verify W27 remains externally gated**

Confirm the production path rejects absent backup and restore evidence identifiers and that the runbook still requires restoring to isolated MySQL. Do not manufacture or insert evidence IDs.

- [ ] **Step 3: Record the result honestly**

Report local pass/fail counts separately from production readiness. Production remains blocked until an operator supplies a real backup ID and a successful isolated restore-validation ID.

### Task 6: Add the human task-scope switcher

**Files:**
- Modify: `apps/web/src/features/tasks/TasksScreen.tsx`
- Modify: `apps/web/src/features/tasks/TasksScreen.test.tsx`

**Interfaces:**
- Consumes: `usePrincipal().availableScopes`, the approved `ListTasksParams`, and the server's scope authorization.
- Produces: a named «مهامي / مهام نطاقي» view switch and named scope selector; it never treats session context as authority.

- [ ] **Step 1: Write failing persona tests**

Assert that a regular user with no available organizational scope sees only the personal list; an administrator with named facility/unit scopes can select «مهام نطاقي», sends `view=scope` plus the selected type/id, and sees scope labels rather than UUIDs; returning to «مهامي» removes all scope parameters and restores the relationship filters.

- [ ] **Step 2: Implement the minimal switcher**

Use only `cluster|facility|unit` entries supplied by PrincipalContext. Default to the effective supported scope, then the first supported option. Hide the scope mode when no named supported option exists. Do not infer authorization from role names; a 403 from the server remains authoritative and renders the standard denied state.

- [ ] **Step 3: Verify GREEN**

Run the focused TasksScreen tests, web lint/build, and `git diff --check` for the two files.
