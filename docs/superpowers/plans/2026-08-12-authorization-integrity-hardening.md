# Authorization Integrity Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce organization-tree ownership and make cluster, facility, and unit authorization decisions consume correct, module-owned resource ancestry facts.

**Architecture:** Keep the existing RBAC/ABAC model and scope vocabulary. Organization remains the only module that resolves organizational ancestry; each resource-owning module uses the `ResolveOrganizationScopeAncestry` contract and returns its own `RecordFacts` without reading another module's tables. Tree ownership is derived from the root reached by the authoritative parent chain, so cross-root moves are rejected without adding a second mutable ownership source.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12, SQLite test persistence, MySQL-compatible query semantics.

## Global Constraints

- Preserve the only supported authorization scopes: `cluster`, `facility`, `unit`, and `record_set`.
- `cluster` covers a resource only when the resource facts carry the matching owning `clusterId`.
- Do not add `cluster_tree`, `selected_entities`, or `all_platform` scope types.
- Authorization must not query Tasks, Documents, WorkRecords, or Organization business tables to build facts.
- Resource-owning modules build their own facts and may consume only Organization `Contracts` to resolve ancestry.
- The effective session context remains employment-derived and never grants authority by itself.
- Preserve all pre-existing dirty worktree changes, especially the in-progress Person authorization files.
- Follow strict TDD: add one failing behavior test, observe the expected failure, then write minimal production code.
- Do not commit, push, merge, delete branches, or discard changes; the user authorized implementation only.

---

### Task 1: Enforce organization-unit owner-root invariants

**Files:**
- Modify: `apps/api/Modules/Organization/Features/OrganizationUnit/Handler/OrganizationUnitHandler.php`
- Modify: `apps/api/Modules/Organization/Features/OrganizationUnit/Http/UpdateOrganizationUnitController.php`
- Test: `apps/api/Modules/Organization/Tests/OrganizationTreeHttpAdapterTest.php`

**Interfaces:**
- Consumes: authoritative `organization_units.parent_type`, `parent_id`, and `cluster_id` rows.
- Produces: `OrganizationUnitHandler::resolveOwnerRoot(string $clusterId, string $parentType, string $parentId): array{type: 'cluster'|'facility', id: string}` and a rejected update with domain code `organization_unit_owner_root_mismatch` when the old and new roots differ.

- [ ] **Step 1: Write failing cross-root move tests**

Add two HTTP behaviors to `OrganizationTreeHttpAdapterTest`: a unit rooted at Facility A cannot move below a unit rooted at Facility B, and a facility-rooted unit cannot move below a cluster-rooted unit. Both requests use a valid current `If-Match` and assert `409` with problem type `https://cluster.example/problems/organization-unit-owner-root-mismatch`; the stored parent and path remain unchanged.

- [ ] **Step 2: Run the focused tests and observe RED**

Run:

```sh
cd apps/api && php artisan test Modules/Organization/Tests/OrganizationTreeHttpAdapterTest.php --filter=owner_root
```

Expected: both moves succeed or fail with a response other than the new owner-root conflict, proving the invariant is absent.

- [ ] **Step 3: Implement root derivation and update validation**

In `OrganizationUnitHandler::update`, derive the current row's root from its current parent chain and the proposed parent's root. Before path mutation, throw:

```php
if ($currentOwnerRoot !== $proposedOwnerRoot) {
    throw new DomainException('organization_unit_owner_root_mismatch');
}
```

Root derivation must be cycle-guarded, bounded to 32 unit ancestors, verify every unit belongs to `$clusterId`, return the matching facility for a facility-rooted chain, and return the cluster for a cluster-rooted chain. Missing/corrupt ancestry fails closed with `organization_unit_owner_root_unresolved`.

- [ ] **Step 4: Map the domain error to the contracted HTTP conflict**

Update the existing Organization unit HTTP error mapping so `organization_unit_owner_root_mismatch` becomes the asserted non-disclosing `409` problem response; treat unresolved ownership as a conflict rather than accepting the move.

- [ ] **Step 5: Run the focused test file and verify GREEN**

```sh
cd apps/api && php artisan test Modules/Organization/Tests/OrganizationTreeHttpAdapterTest.php
```

Expected: all tests in the file pass with zero failures.

### Task 2: Normalize Task authorization facts

**Files:**
- Modify: `apps/api/Modules/Tasks/Application/TaskAccessPolicy.php`
- Modify: `apps/api/Modules/Tasks/Application/TaskAuthorizationFacts.php`
- Modify: `apps/api/Modules/Tasks/Providers/TasksServiceProvider.php` only if explicit binding is required
- Test: `apps/api/Modules/Tasks/Tests/TaskAuthorizationScopeTest.php`

**Interfaces:**
- Consumes: `Modules\Organization\Contracts\ResolveOrganizationScopeAncestry::ancestry('unit', $unitId)`.
- Produces: Task `RecordFacts` where `organizationUnitId` is the owner unit, `ownerFacilityId` is its owning facility or `null`, and `clusterId` is its owning cluster. Unresolved ancestry supplies no organizational scope identifiers and therefore fails closed for cluster/facility/unit grants.

- [ ] **Step 1: Write a failing Task facts matrix**

Create real-database tests with one task in Cluster A / Facility A1 / Unit X and assert the facts literal values. Add decisions proving: Cluster A allows, Cluster B denies, Facility A1 allows, Facility A2 denies, Unit X allows, and Unit Y denies.

- [ ] **Step 2: Run the Task scope test and observe RED**

```sh
cd apps/api && php artisan test Modules/Tasks/Tests/TaskAuthorizationScopeTest.php
```

Expected: current code exposes the unit UUID as `ownerFacilityId` and omits `clusterId`.

- [ ] **Step 3: Inject ancestry resolution and build facts once**

Both Task facts paths must call the Organization contract and construct `RecordFacts` with named arguments. Do not set `ownerFacilityId` from `owner_organization_unit_id` directly. Preserve record, creator, assignee, participant, lifecycle, classification, and lock-version facts.

- [ ] **Step 4: Run Task tests and verify GREEN**

```sh
cd apps/api && php artisan test Modules/Tasks/Tests/TaskAuthorizationScopeTest.php Modules/Tasks/Tests
```

Expected: scope matrix and existing Task tests pass.

### Task 3: Centralize Document authorization facts inside Documents

**Files:**
- Create: `apps/api/Modules/Documents/Application/DocumentAuthorizationFacts.php`
- Modify: `apps/api/Modules/Documents/Features/DocumentAccess/Http/DocumentAccessSupport.php`
- Modify: `apps/api/Modules/Documents/Application/DocumentLinkService.php`
- Modify: `apps/api/Modules/Documents/Application/DocumentDownloadService.php`
- Test: `apps/api/Modules/Documents/Tests/DocumentAuthorizationScopeTest.php`
- Modify affected constructor call sites in `apps/api/Modules/Documents/Tests/` only as required by dependency injection.

**Interfaces:**
- Consumes: a Documents-owned document row and `ResolveOrganizationScopeAncestry`.
- Produces: `DocumentAuthorizationFacts::forDocument(stdClass $document): RecordFacts` with correct cluster/facility/unit ancestry and existing document governance facts.

- [ ] **Step 1: Write failing document facts and access tests**

Test one document owned by a facility unit and assert literal ancestry values plus the same six cluster/facility/unit allow-deny cases used for Tasks. Add a corrupt/missing unit case that does not inherit the actor's facility.

- [ ] **Step 2: Run the focused test and observe RED**

```sh
cd apps/api && php artisan test Modules/Documents/Tests/DocumentAuthorizationScopeTest.php
```

Expected: `owner_organization_unit_id` is currently copied into `ownerFacilityId`, and cluster scope does not match.

- [ ] **Step 3: Implement the module-owned builder and replace duplicated construction**

The builder resolves `ancestry('unit', owner_organization_unit_id)` and constructs facts with named arguments. All document read, link, and download decisions use this builder. It must not accept actor facility as resource ownership.

- [ ] **Step 4: Run focused and module tests**

```sh
cd apps/api && php artisan test Modules/Documents/Tests/DocumentAuthorizationScopeTest.php Modules/Documents/Tests
```

Expected: focused scope tests and existing Documents tests pass.

### Task 4: Unify WorkRecord facts across every decision path

**Files:**
- Create: `apps/api/Modules/WorkRecords/Application/WorkRecordResourceFacts.php`
- Modify: `apps/api/Modules/WorkRecords/Application/WorkRecordAuthorizationFacts.php`
- Modify: `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Http/SubmitWorkRecordController.php`
- Modify: `apps/api/Modules/WorkRecords/Features/ListAuthorizedWorkRecords/Handler/ListAuthorizedWorkRecordsHandler.php`
- Modify: `apps/api/Modules/WorkRecords/Features/GetAuthorizedWorkRecord/Handler/GetAuthorizedWorkRecordHandler.php`
- Modify additional WorkRecords decision paths only when they construct WorkRecord `RecordFacts` directly
- Test: `apps/api/Modules/WorkRecords/Tests/WorkRecordAuthorizationScopeTest.php`

**Interfaces:**
- Consumes: a WorkRecords-owned row or explicit owner facility plus `ResolveOrganizationScopeAncestry::ancestry('facility', $facilityId)`.
- Produces: one module-owned facts builder that always includes the facility's owning `clusterId` and preserves record governance metadata.

- [ ] **Step 1: Write failing WorkRecord path-consistency tests**

Create a record in Cluster A / Facility A1 and assert list, get, submit/read response, lifecycle, and linked-resource paths build facts with the same cluster and facility values. The authorization matrix must deny Cluster B and Facility A2.

- [ ] **Step 2: Run the focused tests and observe RED**

```sh
cd apps/api && php artisan test Modules/WorkRecords/Tests/WorkRecordAuthorizationScopeTest.php
```

Expected: list/get already derive cluster in some paths, while submit and linked-resource paths omit it.

- [ ] **Step 3: Implement and adopt `WorkRecordResourceFacts`**

Use named `RecordFacts` arguments and preserve classification, record ID, creator, lifecycle, field policy, work type version, and lock version. An unresolved facility ancestry must not borrow the actor's cluster.

- [ ] **Step 4: Run focused and module tests**

```sh
cd apps/api && php artisan test Modules/WorkRecords/Tests/WorkRecordAuthorizationScopeTest.php Modules/WorkRecords
```

Expected: focused scope tests and existing WorkRecords tests pass.

### Task 5: Complete Person facts integration without overwriting local work

**Files:**
- Modify only as needed: `apps/api/Modules/Organization/Features/Person/Authorization/PersonAuthorizationFacts.php`
- Modify only as needed: `apps/api/Modules/Organization/Features/Person/Handler/PersonHandler.php`
- Modify only as needed: Person HTTP controllers already changed in the worktree
- Test: `apps/api/Modules/Organization/Tests/PersonAuthorizationScopeTest.php`

**Interfaces:**
- Consumes: `ResolvePersonOrganizationScope` plus `ResolveOrganizationScopeAncestry`.
- Produces: per-target Person facts derived from the target person's assignments, never from the actor's selected/current facility.

- [x] **Step 1: Preserve and inspect the existing uncommitted implementation**

Confirm the current local test names cover facility same-scope allow, cross-facility concealment, cross-cluster concealment, cluster scope bounded to its real cluster, unassigned-person fail-closed behavior, and list pagination across hidden rows.

- [x] **Step 2: Run the focused Person test and capture the actual state**

```sh
cd apps/api && php artisan test Modules/Organization/Tests/PersonAuthorizationScopeTest.php
```

Expected: use the output as the baseline; do not claim the uncommitted implementation is complete without it.

- [x] **Step 3: Add only missing failing cases, then make minimal fixes**

Any new production change must be preceded by a failing focused test. Preserve the target-derived facts boundary and non-disclosing `404` behavior.

- [x] **Step 4: Run Person and Organization scope tests**

```sh
cd apps/api && php artisan test Modules/Organization/Tests/PersonAuthorizationScopeTest.php Modules/Organization/Tests/OrganizationScopeFactsTest.php
```

Expected: all focused assertions pass.

### Task 6: Lock the engine semantics with a scope matrix and run boundaries

**Files:**
- Modify: `apps/api/Modules/Authorization/Tests/RbacAbacDecideAccessTest.php`
- Modify documentation only if current docs contradict tested semantics: `docs/api/rbac-matrix.md`

**Interfaces:**
- Consumes: `AuthorizationScope::covers(RecordFacts)` and the corrected module builders.
- Produces: a literal table-driven regression matrix proving exact cluster, facility, unit, and record-set coverage without new scope types.

- [x] **Step 1: Add the literal authorization matrix test**

The cases must cover matching and mismatching Cluster, Facility, Unit, and Record Set IDs. Expectations are hand-written literals, not generated by `AuthorizationScope` helpers.

- [x] **Step 2: Run the matrix test and confirm it protects semantics**

```sh
cd apps/api && php artisan test Modules/Authorization/Tests/RbacAbacDecideAccessTest.php --filter=scope_matrix
```

Expected: pass if the current engine already implements the approved semantics; perform a mutation check by temporarily reversing one expected scope comparison, observe failure, then restore it.

- [x] **Step 3: Run focused integration verification**

```sh
cd apps/api && php artisan test \
  Modules/Organization/Tests/OrganizationTreeHttpAdapterTest.php \
  Modules/Organization/Tests/PersonAuthorizationScopeTest.php \
  Modules/Tasks/Tests/TaskAuthorizationScopeTest.php \
  Modules/Documents/Tests/DocumentAuthorizationScopeTest.php \
  Modules/WorkRecords/Tests/WorkRecordAuthorizationScopeTest.php \
  Modules/Authorization/Tests/RbacAbacDecideAccessTest.php
```

Expected: zero failures and zero errors.

- [x] **Step 4: Run architecture and static verification**

```sh
make verify-boundaries
composer --working-dir=apps/api analyse
composer --working-dir=apps/api lint
```

Expected: all commands exit zero. If an unrelated pre-existing failure remains, report the exact split and do not claim the feature is fully green.

- [x] **Step 5: Review the final diff against the approved constraints**

Confirm no new scope types, no cross-module table reads, no actor-derived resource ownership, no overwritten user changes, and no commits or pushes.

### Task 5/6 progress report — 2026-08-12

- **Task 5 — verified:** Added the Organization-owned `forPeople()` scope path, per-target batch facts, chunk authorization, and the hidden-row/query-budget regression. The RED run measured 1,627 queries; the GREEN run passed with 24 or fewer queries for the session plus two bounded chunks. Person and Organization scope tests passed at 18/18 with 117 assertions.
- **Task 6 — integration/static verified:** The literal scope matrix passed 8/8 tests; focused integration passed 100/100 tests with 653 assertions; architecture passed 30/30; PHPStan and Pint passed.
- **Mutation check verified:** The cluster-match literal was temporarily inverted, the focused case failed as expected, and the original expectation was restored with `apply_patch`. The restored matrix passes 8/8.

### Task 7: Close reviewer findings for Organization scope provenance and Person scan bounds

**Files:**
- Modify: `apps/api/Modules/Organization/Infrastructure/Persistence/DatabaseResolvePersonOrganizationScope.php`
- Modify: `apps/api/Modules/Organization/Features/Person/Handler/PersonHandler.php`
- Test: `apps/api/Modules/Organization/Tests/OrganizationScopeFactsTest.php`
- Test: `apps/api/Modules/Organization/Tests/PersonAuthorizationScopeTest.php`

- [x] Add RED coverage for missing/cross-cluster facilities and observe the resolver incorrectly accepting them.
- [x] Add GREEN batch facility loading and fail-closed provenance checks without N+1 reads.
- [x] Add RED coverage for a hidden table larger than the scan budget, empty bounded pages, resumable cursors, and per-request query bounds.
- [x] Add GREEN `MAX_RAW_ROWS_PER_REQUEST` probing and cursors based on `lastScannedId` when raw rows remain.
- [x] Add RED coverage for the combined `hasMoreVisibleRows` and `hasMoreRawRows` case; the pre-fix cursor skipped the visible row beyond the returned page.
- [x] Add GREEN cursor precedence: when visible rows continue, resume from the last returned item; use `lastScannedId` only for hidden-only raw continuation.
- [x] Run focused suites, integration, boundaries, lint, and diff checks; record the PHPStan cache-environment split in the SDD report.

### Task 7 final verification — 2026-08-12

- **RED/GREEN:** The new Person pagination regression failed before the fix with the expected skipped IDs, then passed after the one-branch cursor precedence change.
- **Focused evidence:** Person domain test 14/14; Person authorization integration 12/12 with 170 assertions; final focused authorization integration including Organization scope provenance passed 104/104 with 737 assertions.
- **Static evidence:** `make verify-boundaries` passed 30/30 with 205 assertions; Pint passed; clean-cache PHPStan passed with 0 errors; `git diff --check` passed. The standard Composer wrapper remains affected by the existing system PHPStan result cache.
- **Git constraint:** No commit, stage, push, reset, checkout, or permanent config change was performed.
