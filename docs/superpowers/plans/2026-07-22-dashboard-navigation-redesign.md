---
doc_id: PLN-UI-003
title: Dashboard and Capability-Adaptive Navigation Implementation Plan
type: plans
status: accepted
version: 1.1.0
date: 2026-07-22
owner: Software Engineering Lead
reviewers:
- Product Lead
- Software Engineering Lead
classification: internal
review_cycle: At the completion of each wave or change to a navigation or authorization contract
sources:
- docs/superpowers/specs/2026-07-22-dashboard-navigation-redesign-design.md
- docs/design-system.md
- docs/plans/approvals-and-requests.md
references:
- docs/data-security/authorization-model.md
- docs/engineering/delivery-workflow.md
- docs/domain/notifications-search-reporting.md
---

# Dashboard and Capability-Adaptive Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the dashboard, sidebar, and page layout around the user's
work, show links according to actual capabilities and scope, separate the
independent pages, and remove nested tabs.

**Architecture:** `/` stays a unified dashboard; `routes.ts` becomes the source
of routes and `navigation.tsx` the source of navigation entries.
`PrincipalProvider` carries capabilities and scope from the server, but the API
remains the owner of the final authorization decision. The "Awaiting My Action",
"My Requests", and "My Tasks" pages rely on server-filtered queries, and the
dashboard composes their data without bulk fetching or any security filtering
in React.

**Tech Stack:** Laravel modular monolith, PHP 8, MySQL/SQLite for tests,
OpenAPI + Redocly + Orval, React 19, TypeScript 6, Vite, Vitest, Testing
Library, Playwright, and the unified components from `apps/web/src/ui/`.

## Global Constraints

- The current work tree is not clean and has ongoing work on Workflow, OpenAPI,
  routes, and the `ApprovalInbox` and `MyRequests` pages. Execution starts by
  reconciling these changes and does not delete or overwrite them.
- A new worktree is created only at execution start, after using
  `superpowers:using-git-worktrees` and verifying where the in-flight work lives.
  Do not use `git reset` or `git checkout --`.
- The sidebar is capability-built for UX only; every endpoint, detail, search,
  report, export, and download reapplies RBAC + ABAC on the server.
- The interface does not use `session.user_id` to filter a full collection.
  `me`, scope, owner, and assignee are server-side filters.
- Any API change starts from `docs/contracts/api/openapi.yaml` and its
  schemas, then `api:generate`, then the generated types are consumed. Do not
  edit `apps/web/src/api/generated/cluster.ts` by hand.
- Screens use `Page`, `PageHeader`, `Panel`, `Button`, `Field`, `Select`,
  `Drawer`, and `Feedback` from `apps/web/src/ui/`. If behavior is missing,
  add it to the unified library with tests.
- A maximum of two tabs; no sidebar link then tabs then sub-tabs. Details are
  deep links, not tabs.
- Required applicable states: loading, empty, denied, error, success, and
  409/412 stale-conflict.
- Do not show zero during loading, no fixture numbers, and no duplicated
  notifications card inside the dashboard.
- `docs/plans/active-delivery-status.md` is changed only at the user's request.
- The commit steps below run only if the user authorized creating commits.
  Otherwise the steps stay as logical grouping boundaries for the changes.
- The server does not stop between waves; browser verification is the final
  stage after the slices complete.

## Target Route Map

| Location | Page | Path | Sidebar visibility policy |
|---|---|---|---|
| My Work | Home | `/` | Every authenticated user |
| My Work | Awaiting My Action | `/approvals` | Any of `workflow.decide`, `workflow.reassign`, `workflow.escalate` |
| My Work | My Requests | `/my-requests` | Any of `workflow.read`, `workflow.list` |
| My Work | My Tasks | `/tasks` | Any of `tasks.read`, `tasks.list` |
| My Work | Procedures and Services | `/procedures` | Any of `work_definition.read`, `work_definition.list` |
| My Work | Documents | `/documents` | Any of `documents.read`, `documents.list` |
| Facilities and Workforce | Facilities and Structure | `/admin/organization` | Any of `organization.facility.read`, `organization.unit.read` |
| Facilities and Workforce | People | `/admin/organization/people` | `organization.person.read` |
| Facilities and Workforce | Temporary Assignments | `/admin/organization/temporary-assignments` | `organization.temporary-assignment.read` |
| Facilities and Workforce | Data Import | `/admin/imports/organization` | `organization.import.read` |
| Facilities and Workforce | Supervisory Relationships | `/admin/relationships/supervisory` | `organization.unit.read` |
| Procedures and Workflow | Request Types | `/admin/work-definitions` | Any of `work_definition.read`, `work_definition.list` |
| Procedures and Workflow | Approval Paths | `/admin/workflow` | Any of `workflow.read`, `workflow.list`, `workflow.manage` |
| Procedures and Workflow | Procedure Review and Publish | `/admin/procedures/review` | Any of `workflow.approve`, `work_definition.publish` |
| Accounts and Permissions | Accounts | `/admin/identity/accounts` | `identity.account.read` |
| Accounts and Permissions | Roles and Capabilities | `/admin/authorization/roles` | Any of `authorization.role.read`, `authorization.capability.read` |
| Accounts and Permissions | Role Assignments | `/admin/authorization/role-assignments` | `authorization.assignment.read` |
| Accounts and Permissions | Access Scopes | `/admin/authorization/access-scopes` | `authorization.assignment.read` |
| Accounts and Permissions | Delegations | `/admin/authorization/delegations` | `authorization.delegation.read` |
| Accounts and Permissions | Classification Policies | `/admin/authorization/classification-policies` | `authorization.policy.read` |
| Accounts and Permissions | Field Policies | `/admin/authorization/field-access-templates` | `authorization.policy.read` |
| Reports and Indicators | Reports | `/reports` | `reporting.list` |
| Reports and Indicators | Indicator Dashboards | `/dashboards` | `reporting.dashboard` |
| Internal Tools | Inspect Access Decision | `/admin/authorization/explain` | `authorization.decision.read` |
| Internal Tools | Coverage | `/coverage` | `authorization.audit.read` |
| Internal Tools | API Reference | `/api-docs` | `authorization.audit.read` |
| User Menu | Personal Security | `/me/security` | Every authenticated user |
| User Menu | My Access Context | `/me/access` | Every authenticated user |

Critical notes:

- Only `/admin/organization` allows the two tabs "Facilities" and "Structure".
- `/admin/authorization/roles` allows the two tabs "Roles" and "Capabilities",
  and `/admin/authorization/capabilities` stays the second tab link.
- `/admin/authorization/access-scopes` is an independent read view above the
  filtered role-assignments and does not create a new authorization area.
  Scope editing happens from the role-assignment page; personal scope selection
  remains on `/me/access` and the top bar.
- `/admin/workflow/day2` stays a direct compatibility link to `Day2Workflow`
  with no tabs and no sidebar entry until all its functions are moved; it is
  not deleted or redirected to a non-equivalent page.
- New details: `/approvals/:stepId`, `/my-requests/:instanceId`,
  `/tasks/:taskId`, with identifier validation in the parser.

## Lane Matrix

| Wave | Backend | Contract | Frontend | Security | Verification |
|---|---|---|---|---|---|
| 1. Foundation | No domain change | Lock in current Principal | Routes, navigation, and PrincipalProvider | Hide fail-closed | route/navigation/provider unit |
| 2. My Work | Inbox + ownership queries | Workflow inbox/tracking | approvals/requests/tasks/details | Prevent leakage and N+1 | feature + generated API + unit |
| 3. Dashboard | Reuse filtered sources | No aggregate fake contract | dashboard composition | No scope mixing | dashboard unit + integration |
| 4. Administration | No change except proven gap | Existing contracts | Split Organization/Workflow/Authorization | Capability gates per page | workspace unit |
| 5. Reports and Tools | Existing contracts | No change | dashboards page + internal tools | Hide tools from regular user | reporting/navigation unit |
| 6. Close | No new | OpenAPI consistency | responsive/RTL/LTR/a11y | direct URL negative journeys | build + E2E + browser QA |

## Execution Status — 2026-07-23

The user adopted the sidebar layout by work domain, and the eleven tasks were
executed. The detailed step checkboxes below describe the original execution
method and are not a claim that commits exist; the user did not authorize
commit, push, or merge, so commit steps stayed optional and unexecuted.

- [x] Task 1: Routes registry and capability-adaptive navigation registry.
- [x] Task 2: PrincipalProvider, scope-change barrier, and old-response invalidation.
- [x] Task 3: Server-filtered approval and personal request inbox with cursor, state, and limit.
- [x] Task 4: Lists and details for approvals, requests, and tasks, with the correct step-contract.
- [x] Task 5: Dashboard composition from independent sources in the adopted order.
- [x] Task 6: Split the facilities, people, temporary assignments, and import pages.
- [x] Task 7: Split request types, approval paths, and procedure review.
- [x] Task 8: Split accounts, roles and capabilities, scopes, and policies.
- [x] Task 9: Split reports, indicator dashboards, and internal tools.
- [x] Task 10: responsive, RTL/LTR, accessibility, and direct AppShell tests.
- [x] Task 11: E2E journeys for personas, scope, details, decisions, and direct links.

### Fresh verification evidence

Last verification: `2026-07-23 03:03:04 +03`.

| Gate | Result |
|---|---|
| `npm --prefix apps/web run test:unit` | exit 0 — 53 files, 295 tests |
| `npm --prefix apps/web run build` | exit 0 — production build |
| `npm --prefix apps/web run lint` | exit 0 — only old Fast Refresh warnings, no errors |
| `npm --prefix apps/web run api:check` | exit 0 — contract valid and generated client matches; only known Redocly warnings |
| `composer test` | exit 0 — 482 tests, 477 passed, 5 skipped, 3923 assertions |
| `composer lint` and `composer analyse` | exit 0 — Pint clean and PHPStan zero errors |
| `python3 scripts/inventory-routes.py --check` | exit 0 — 119 routes |
| `make verify-boundaries` | exit 0 — 4 tests, 6 assertions |
| `./infra/dev/run-approvals-e2e.sh` | exit 0 — 22 Playwright journeys |
| `./infra/dev/run-w1-3-e2e.sh` | exit 0 — W1.3 security journey |
| Browser QA | desktop, collapsed group icons, 200% reflow, 320px, RTL, LTR, drawer focus, no horizontal overflow |

The visual outputs are saved in `artifacts/dashboard-navigation-qa-*.png` and
`artifacts/sidebar-work-groups-*.png`, including
`artifacts/sidebar-work-groups-collapsed.png`.

The last verification closed `waiting` and `running` gaps, scope-revision
feedback copy, collapsed group icons, and the `approve/reject/return/reassign/escalate`
surfaces based on `allowed_actions` with step locking.

A scaffolding search found no `TO[D]O`, `FIX[M]E`, or permanent mock. The
names `RequestDashboard`, `ProcessWorkspace`, and `AccessWorkspace` remain only
in compatibility files and their tests; they are not imported by
`AppWorkspace` nor used by new product routes, and removing them is out of
scope for this execution because it would break legacy test surfaces.

---

### Task 1: Reconcile In-Flight Work and Freeze the Route Registry

**Files:**

- Modify: `apps/web/src/shell/routes.ts`
- Modify: `apps/web/src/shell/routes.test.ts`
- Create: `apps/web/src/shell/navigation.tsx`
- Create: `apps/web/src/shell/navigation.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.navigation.test.tsx`
- Preserve and reconcile: `apps/web/src/app/AppWorkspace.tsx`, `docs/contracts/api/openapi.yaml`, `apps/web/src/features/workflow/ApprovalInbox.tsx`, `apps/web/src/features/workflow/MyRequests.tsx`

**Consumes:** `AppRoute`, `pathFromRoute`, `routeFromPath`, capability codes returned by `GET /me`.

**Produces:** one exhaustive route registry; one navigation registry with placement and `anyOf` capability policies; exact compatibility behavior for old links.

- [ ] Before any modification, run `git status --short` and `git diff --check` and review diffs for the preserved files above. Record any block that belongs to other ongoing work in execution notes; do not replace it.
- [ ] Run the targeted baseline:

  ```bash
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/app/AppWorkspace.navigation.test.tsx src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/MyRequests.test.tsx
  ```

  Expected in the current state: parse failure for `/approvals`, `/my-requests`, and `/procedures/new`, or sidebar-test inconsistency with `shellNavigation`. Do not fix tests by deleting expectations.
- [ ] Add route parsing tests before execution, including details, indicator dashboards, access scopes, and old compatibility:

  ```ts
  it('round-trips the direct work and administration routes', () => {
    const stepId = '01980f50-5f0d-7000-8000-000000000101'
    const instanceId = '01980f50-5f0d-7000-8000-000000000102'
    const taskId = '01980f50-5f0d-7000-8000-000000000103'
    expect(routeFromPath('/approvals')).toEqual({ name: 'approval-inbox' })
    expect(routeFromPath(`/approvals/${stepId}`)).toEqual({ name: 'approval-detail', stepId })
    expect(routeFromPath(`/my-requests/${instanceId}`)).toEqual({ name: 'my-request-detail', instanceId })
    expect(routeFromPath(`/tasks/${taskId}`)).toEqual({ name: 'task-detail', taskId })
    expect(routeFromPath('/dashboards')).toEqual({ name: 'dashboards' })
    expect(routeFromPath('/admin/authorization/access-scopes')).toEqual({ name: 'access-scopes' })
    expect(pathFromRoute({ name: 'dashboards' })).toBe('/dashboards')
    expect(routeFromPath('/admin/workflow/day2')).toEqual({ name: 'workflow-day2' })
  })
  ```
- [ ] Re-classify `ROUTE_WORKSPACE` so it does not bundle independent pages. The only non-`null` values are the facilities/structure and roles/capabilities tabs. The approvals, requests, procedures, and other administration pages must stay independent in active state.
- [ ] Create `navigation.tsx` with an explicit contract that does not rely on a switch inside `AppWorkspace.tsx`:

  ```tsx
  export type NavigationPolicy =
    | { kind: 'authenticated' }
    | { kind: 'anyOf'; capabilities: readonly string[] }

  export type NavigationEntry = {
    key: string
    route: AppRoute
    group:
      | 'my-work'
      | 'organization-workforce'
      | 'processes-workflow'
      | 'accounts-access'
      | 'reports-insights'
      | 'internal'
    labelKey: NavigationLabelKey
    icon: LucideIcon
    policy: NavigationPolicy
  }

  export function isNavigationEntryVisible(
    entry: NavigationEntry,
    capabilities: readonly string[] | null,
  ): boolean {
    if (entry.policy.kind === 'authenticated') return true
    return capabilities !== null
      && entry.policy.capabilities.some((code) => capabilities.includes(code))
  }
  ```
- [ ] Define every Target Route Map item exactly once in `NAVIGATION_ENTRIES`. Do not place `/me/*` there; define a separate `USER_MENU_ENTRIES`. Remove a group if it ends up with no entries.
- [ ] Add fail-safe tests and group distribution:

  ```tsx
  it('hides gated and empty groups while principal capabilities are unresolved', () => {
    const groups = buildNavigationGroups({ route: { name: 'list' }, locale: 'ar', capabilities: null, navigate: vi.fn() })
    expect(groups.map((group) => group.key)).toEqual(['my-work'])
    expect(groups.flatMap((group) => group.items.map((item) => item.path))).toEqual(['/'])
  })

  it('puts documents in My Work and tools in Internal Tools', () => {
    const paths = buildNavigationGroups({
      route: { name: 'list' }, locale: 'ar',
      capabilities: ['documents.read', 'authorization.audit.read'], navigate: vi.fn(),
    })
    expect(paths.find((group) => group.key === 'my-work')?.items.map((item) => item.path)).toContain('/documents')
    expect(paths.find((group) => group.key === 'internal')?.items.map((item) => item.path)).toEqual(['/coverage', '/api-docs'])
  })
  ```
- [ ] Run the targeted tests and confirm they are green:

  ```bash
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/shell/routes.ts apps/web/src/shell/routes.test.ts apps/web/src/shell/navigation.tsx apps/web/src/shell/navigation.test.tsx apps/web/src/app/AppWorkspace.navigation.test.tsx && git commit -m "feat(web): define capability adaptive navigation registry"`

### Task 2: Load Principal Capabilities and Effective Scope Once

**Files:**

- Create: `apps/web/src/app/principal-context.tsx`
- Create: `apps/web/src/app/principal-context.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/app/AppShell.css`
- Modify: `apps/web/src/features/authorization/AccessContext.tsx`
- Modify: `apps/web/src/api/r1.ts`

**Consumes:** `GET /me`, `GET /me/scopes`, `PUT /me/scope`, session CSRF token.

**Produces:** `PrincipalSnapshot` with fail-closed capabilities, effective scope, a `revision` that invalidates scope-bound reads, and one shared scope selector.

- [ ] Add a provider test first:

  ```tsx
  it('exposes capabilities and increments revision after a scope change', async () => {
    getMyAccessContextMock.mockResolvedValue(principal({ capabilities: ['tasks.read'] }))
    listMyAccessScopesMock.mockResolvedValue(scopeSnapshot('facility', FACILITY_A, 3))
    selectMyAccessScopeMock.mockResolvedValue(scopeSnapshot('unit', UNIT_A, 4))
    render(<PrincipalProvider session={session}><Probe /></PrincipalProvider>)
    expect(await screen.findByText('tasks.read')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'select-unit' }))
    expect(await screen.findByText('revision:1')).toBeTruthy()
  })
  ```
- [ ] Apply the following contract in `principal-context.tsx`:

  ```ts
  export type PrincipalSnapshot = {
    state: 'loading' | 'ready' | 'denied' | 'error'
    capabilities: readonly string[] | null
    effectiveScope: ScopeOptionView | null
    availableScopes: readonly ScopeOptionView[]
    revision: number
    refresh: () => Promise<void>
    selectScope: (scopeType: ScopeSelectionUpdate['scope_type'], scopeId: string) => Promise<void>
  }
  ```

  During `loading/error/denied` keep `capabilities = null`. Do not use named
  roles such as manager/admin to decide visibility.
- [ ] Make the provider run `getMyAccessContext` and `listMyAccessScopes` once per session. On scope change: apply `PUT /me/scope` with ETag, update the scope, clear old data, then increment `revision`.
- [ ] Move capabilities and scope normalization into shared helpers used by `AccessContext` instead of a second copy. Add `capabilities` to `PrincipalView` so the "My Access Context" page shows what the server returns without a local decision.
- [ ] Wire `AppWorkspace` to `usePrincipal()`, and build the sidebar through `buildNavigationGroups`. Do not build the sidebar before the gated capabilities arrive.
- [ ] Add the scope selector to the top bar using `Select`. It does not appear when only one scope exists. On 412, show a stale state and reload the scopes list before allowing another attempt.
- [ ] Keep the user menu limited to personal security, my access context, language, and sign out. Do not duplicate `/me/access` in the sidebar.
- [ ] Add a test that proves scope change rebuilds the sidebar and does not leave the old scope name or its data:

  ```tsx
  expect(onScopeRevision).toHaveBeenLastCalledWith(1)
  expect(screen.getByRole('combobox', { name: 'Current scope' })).toHaveValue(`unit:${UNIT_A}`)
  ```
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/app/principal-context.test.tsx src/features/authorization/AccessContext.test.tsx src/app/AppWorkspace.navigation.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/app/principal-context.tsx apps/web/src/app/principal-context.test.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.css apps/web/src/features/authorization/AccessContext.tsx apps/web/src/api/r1.ts && git commit -m "feat(web): load principal capabilities and effective scope"`

### Task 3: Complete Server-Filtered Approval Inbox and Request Tracking Contracts

**Files:**

- Modify: `docs/contracts/api/openapi.yaml`
- Create: `apps/api/Modules/Workflow/Features/ListApprovalInbox/Query/ListApprovalInbox.php`
- Create: `apps/api/Modules/Workflow/Features/GetVisibleWorkflowInstance/Query/GetVisibleWorkflowInstance.php`
- Create: `apps/api/Modules/Workflow/Tests/ListApprovalInboxTest.php`
- Modify: `apps/api/app/Http/Controllers/Api/WorkflowController.php`
- Modify: `apps/api/routes/web.php`
- Create: `apps/api/tests/Feature/WorkflowPersonalQueuesHttpTest.php`
- Regenerate: `apps/web/src/api/generated/cluster.ts`
- Modify: `apps/web/src/features/workflow/workflow-api.ts`
- Create: `apps/web/src/features/workflow/workflow-api.test.ts`

**Consumes:** trusted principal from identity middleware, Workflow-owned `workflow_instances` and `workflow_step_instances`, current `workflow.read` and `workflow.decide` decisions.

**Produces:** `GET /workflow/steps?assignee=me`, owner-filtered `GET /workflow/instances`, protected `GET /workflow/instances/{id}`, generated typed client.

- [ ] Write a failing HTTP test before the controller:

  ```php
  public function test_inbox_returns_only_current_principal_steps(): void
  {
      $this->seedInstanceWithStep(self::USER_A, self::USER_A, 'active');
      $this->seedInstanceWithStep(self::USER_B, self::USER_B, 'active');

      $response = $this->asUser(self::USER_A)
          ->getJson('/api/v1/workflow/steps?assignee=me&state=active', $this->headers())
          ->assertOk();

      $this->assertCount(1, $response->json('items'));
      $this->assertSame(self::USER_A, $response->json('items.0.assignee_user_id'));
  }

  public function test_instance_detail_does_not_disclose_another_users_request(): void
  {
      $instance = $this->seedInstanceWithStep(self::USER_B, self::USER_B, 'active');
      $this->asUser(self::USER_A)
          ->getJson('/api/v1/workflow/instances/'.$instance, $this->headers())
          ->assertNotFound();
  }
  ```
- [ ] Add tests for 401, 403, `state=all`, cursor, and that the owner can read their instance and an assignee can only read the linked step. Do not return names or IDs in 403/404 problem responses.
- [ ] Correct OpenAPI to use real projection instead of a generic `Entity`:

  ```yaml
  WorkflowInboxItem:
    type: object
    additionalProperties: false
    required: [step_id, workflow_instance_id, source_type, source_id, state, assignee_user_id, created_at, lock_version, allowed_actions]
    properties:
      step_id: { $ref: '#/components/schemas/UUIDv7' }
      workflow_instance_id: { $ref: '#/components/schemas/UUIDv7' }
      source_type: { type: string }
      source_id: { type: string }
      state: { type: string, enum: [waiting, active, completed, rejected, returned, cancelled] }
      assignee_user_id: { $ref: '#/components/schemas/UUIDv7' }
      created_at: { $ref: '#/components/schemas/UtcDateTime' }
      lock_version: { type: integer, minimum: 1 }
      allowed_actions:
        type: array
        uniqueItems: true
        items: { type: string, enum: [approve, reject, return, reassign, escalate] }
  ```

  Do not add `subject` or `due_at` if Workflow does not own that data. The
  interface shows `source_type/source_id` or a link to the authorized resource
  instead of joining across another module.
- [ ] Implement `ListApprovalInbox` inside the Workflow module. Allow `assignee=me` only for the regular user, and accept `assignee_user_id` only after the matching `workflow.approve` decision. Apply `state` and cursor before returning.
- [ ] Add `Route::get('workflow/steps', [WorkflowController::class, 'steps'])` to the read-session group.
- [ ] Make `showInstance` use `GetVisibleWorkflowInstance`: only the owner or an assignee on an instance step. Any wider view for the Operations Office must pass a `workflow.approve` decision and an explicit scope, not just knowledge of the ID.
- [ ] Keep `GET /workflow/instances` filtered by `started_by_user_id` in SQL and add its test; do not rely on the existing React filter.
- [ ] Run from `apps/api`:

  ```bash
  php artisan test Modules/Workflow/Tests/ListApprovalInboxTest.php tests/Feature/WorkflowPersonalQueuesHttpTest.php
  ```

  Expected after implementation: green, and no query returns USER_B data to USER_A.
- [ ] Update the generated client only through:

  ```bash
  npm --prefix apps/web run api:lint
  npm --prefix apps/web run api:generate
  npm --prefix apps/web run api:check
  ```
- [ ] Make `workflow-api.ts` a thin wrapper:

  ```ts
  export async function listMyApprovalSteps(token: string, state: WorkflowInboxState = 'active') {
    return unwrap(await generated.listWorkflowStepsInbox(
      { assignee: 'me', state, limit: 50 }, requestInit(token),
    ))
  }

  export async function listMyWorkflowInstances(token: string) {
    return unwrap(await generated.listWorkflowInstances({ limit: 50 }, requestInit(token)))
  }
  ```
- [ ] Add a wrapper test that locks down query parameters and proves no `assignee_user_id` is sent from the browser.
- [ ] Run `make verify-boundaries` because the Query classes and Workflow boundaries changed.
- [ ] If commits are authorized: `git add docs/contracts/api/openapi.yaml apps/api/Modules/Workflow/Features apps/api/Modules/Workflow/Tests/ListApprovalInboxTest.php apps/api/app/Http/Controllers/Api/WorkflowController.php apps/api/routes/web.php apps/api/tests/Feature/WorkflowPersonalQueuesHttpTest.php apps/web/src/api/generated/cluster.ts apps/web/src/features/workflow/workflow-api.ts apps/web/src/features/workflow/workflow-api.test.ts && git commit -m "feat(workflow): expose server filtered personal queues"`

### Task 4: Finish My Work Pages and Their Deep Links

**Files:**

- Modify: `apps/web/src/features/workflow/ApprovalInbox.tsx`
- Modify: `apps/web/src/features/workflow/ApprovalInbox.test.tsx`
- Modify: `apps/web/src/features/workflow/MyRequests.tsx`
- Modify: `apps/web/src/features/workflow/MyRequests.test.tsx`
- Create: `apps/web/src/features/workflow/ApprovalDetail.tsx`
- Create: `apps/web/src/features/workflow/ApprovalDetail.test.tsx`
- Create: `apps/web/src/features/workflow/MyRequestDetail.tsx`
- Create: `apps/web/src/features/workflow/MyRequestDetail.test.tsx`
- Create: `apps/web/src/features/tasks/TaskDetail.tsx`
- Create: `apps/web/src/features/tasks/TaskDetail.test.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/features/workflow/workflow-copy.ts`

**Consumes:** typed personal queue wrappers from Task 3 and existing `GET /tasks`/`GET /tasks/{id}`.

**Produces:** direct list/detail journeys for approvals, requests, and tasks; complete state handling; no client-side identity filtering.

- [ ] Replace the current ApprovalInbox test with one that rejects the old pattern:

  ```tsx
  it('loads the server-filtered inbox without listing every workflow instance', async () => {
    listMyApprovalStepsMock.mockResolvedValue({ items: [inboxItem()], next_cursor: null })
    render(<ApprovalInbox locale="ar" session={session} onOpen={onOpen} />)
    expect(await screen.findByText('Work record · WR-17')).toBeTruthy()
    expect(listMyApprovalStepsMock).toHaveBeenCalledWith('test-token', 'active')
    expect(listWorkflowInstancesMock).not.toHaveBeenCalled()
  })
  ```
- [ ] Remove `Promise.all(getWorkflowInstance)` and the `step.assignee_user_id === session.user_id` guard. Render the filtered items directly, and show actions only from `allowed_actions`.
- [ ] In `MyRequests` remove `.filter(instance.started_by_user_id === session.user_id)`. The test must prove that every item the endpoint returns appears because the contract itself is personal.
- [ ] Make list rows permanent links to `ApprovalDetail`, `MyRequestDetail`, and `TaskDetail`. Do not use a Drawer as a full detail; the Drawer stays for short actions such as a rejection reason only.
- [ ] Implement states for every page: skeleton, empty, 403 denied, error/retry, success aria-live, 409 conflict, 412 stale/reload.
- [ ] Use `allowed_actions` in `ApprovalDetail`, and the authorized task data in `TaskDetail`. If 404 is returned, do not show an ID or owner from old state.
- [ ] Wire new routes in `AppWorkspace.renderRoute()` without any umbrella workspace or tabs.
- [ ] Pass `principal.revision` to loaders or use it as a dependency so they reload after a scope change.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/ApprovalDetail.test.tsx src/features/workflow/MyRequests.test.tsx src/features/workflow/MyRequestDetail.test.tsx src/features/tasks/TaskDetail.test.tsx src/shell/routes.test.ts
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/workflow apps/web/src/features/tasks apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx && git commit -m "feat(web): complete personal work queues and details"`

### Task 5: Compose the Adaptive Home Dashboard from Authorized Sources

**Files:**

- Create: `apps/web/src/features/dashboard/dashboard-model.ts`
- Create: `apps/web/src/features/dashboard/dashboard-model.test.ts`
- Create: `apps/web/src/features/dashboard/WorkDashboard.tsx`
- Create: `apps/web/src/features/dashboard/WorkDashboard.test.tsx`
- Create: `apps/web/src/features/dashboard/WorkDashboard.css`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.tsx`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Delete after replacement: `apps/web/src/features/requests/RequestDashboard.tsx`
- Delete after replacement: `apps/web/src/features/requests/RequestDashboard.css`

**Consumes:** `listMyApprovalSteps`, `listMyWorkflowInstances`, `listTasks`, `listDashboards/getDashboard`, effective scope and principal revision.

**Produces:** one `/` dashboard with four real KPIs, priority queue, request tracking, today summary, and optional scoped manager indicators.

- [ ] Write model tests first:

  ```ts
  it('derives the four KPIs from the same authorized collections', () => {
    expect(buildDashboardSummary({ inbox, tasks, requests, now: NOW })).toEqual({
      awaitingDecision: 2,
      dueToday: 1,
      overdue: 1,
      activeRequests: 3,
    })
  })

  it('does not turn loading or failed sources into zero', () => {
    expect(metricValue({ state: 'loading' })).toBeNull()
    expect(metricValue({ state: 'error' })).toBeNull()
  })
  ```
- [ ] Define `DashboardData` with independent sources, not a single boolean:

  ```ts
  type Loadable<T> =
    | { state: 'loading' }
    | { state: 'ready'; data: T }
    | { state: 'denied' }
    | { state: 'error' }

  export type DashboardData = {
    inbox: Loadable<WorkflowInboxItem[]>
    tasks: Loadable<Task[]>
    requests: Loadable<WorkflowInstanceTracking[]>
    dashboards: Loadable<DashboardCard[]>
  }
  ```
- [ ] Load the sources in parallel with isolated failures. A 403 or failure in `dashboards` is normal for an employee and does not drop inbox/tasks/requests.
- [ ] Build `WorkDashboard` in the adopted order: compact header with scope, priority bar opening `/approvals`, four KPIs, "What Needs You Now", "My Request Tracking", "Today", then `PrincipalDashboards` when `reporting.dashboard` is available.
- [ ] Use 2×2 for the indicators on mobile and a single column for the content. Remove the large hero and the duplicated status/notifications and quick-actions cards.
- [ ] Do not pass `notifications` to the new dashboard. The notification count and the drawer stay in `AppShell` only.
- [ ] Adjust `PrincipalDashboards` to take `scopeId` and `revision`, and use `Promise.allSettled`. The detail button opens `/dashboards` not `/reports`.
- [ ] Add a component test that proves a 403 in dashboards does not hide "What Needs You Now" and that the priority-bar CTA opens approvals, not the new-work-record form.
- [ ] Switch route `/` to `WorkDashboard`, then delete `RequestDashboard` and its CSS after confirming no imports remain through `rg -n "RequestDashboard" apps/web/src`.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/dashboard/dashboard-model.test.ts src/features/dashboard/WorkDashboard.test.tsx src/features/reporting/PrincipalDashboards.test.tsx
  npm --prefix apps/web run build
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/dashboard apps/web/src/features/reporting/PrincipalDashboards.tsx apps/web/src/features/reporting/PrincipalDashboards.test.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/features/requests/RequestDashboard.tsx apps/web/src/features/requests/RequestDashboard.css && git commit -m "feat(web): build adaptive authorized home dashboard"`

### Task 6: Split Organization Administration into Direct Pages

**Files:**

- Modify: `apps/web/src/features/organization/OrganizationWorkspace.tsx`
- Create: `apps/web/src/features/organization/OrganizationWorkspace.test.tsx`
- Modify: `apps/web/src/features/organization/PeopleAssignments.tsx`
- Modify: `apps/web/src/features/organization/PeopleAssignments.test.tsx`
- Modify: `apps/web/src/features/organization/TemporaryAssignments.tsx`
- Modify: `apps/web/src/features/imports/ImportReview.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/e2e/shell.spec.ts`

**Consumes:** current organization components and current routes/APIs.

**Produces:** one two-tab organization page plus independent employees, temporary assignments, and import pages.

- [ ] Write a structure test:

  ```tsx
  it('keeps only facilities and structure as tabs', () => {
    render(<OrganizationWorkspace locale="ar" activeRouteName="organization" navigate={vi.fn()} />)
    const tabs = within(screen.getByRole('navigation', { name: 'Facilities and structure sections' }))
    expect(tabs.getAllByRole('link')).toHaveLength(2)
    expect(tabs.queryByRole('link', { name: 'People' })).toBeNull()
  })
  ```
- [ ] Change `OrganizationWorkspaceRoute` to only `'organization' | 'organization-structure'`, and keep `OrganizationOverview` and `OrganizationStructure` as the page tabs.
- [ ] Render `PeopleAssignments`, `TemporaryAssignments`, and `ImportReview` directly from `AppWorkspace`, with a per-journey `PageHeader` if the component does not have one.
- [ ] Preserve the current paths and deep links for import. The import job detail must keep working after refresh/back/forward.
- [ ] Make sure the navigation registry gives each page its own entry with the right capability and that the active state does not light "Facilities" when "People" is open.
- [ ] Update the legacy E2E test from 5 tabs to 2 tabs, then test the direct transitions to the rest of the pages from the sidebar.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/organization/OrganizationWorkspace.test.tsx src/features/organization/OrganizationOverview.test.tsx src/features/organization/OrganizationStructure.test.tsx src/features/organization/PeopleAssignments.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/organization apps/web/src/features/imports/ImportReview.tsx apps/web/src/app/AppWorkspace.tsx apps/web/e2e/shell.spec.ts && git commit -m "refactor(web): split organization administration pages"`

### Task 7: Split Request Types, Approval Paths, and Procedure Review

**Files:**

- Modify: `apps/web/src/features/workflow/ProcessWorkspace.tsx`
- Modify: `apps/web/src/features/workflow/ProcessWorkspace.test.tsx`
- Modify: `apps/web/src/features/workflow/ProcedureAuthoring.tsx`
- Modify: `apps/web/src/features/workflow/ProcedureOfficeReview.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/app/copy.ts`

**Consumes:** current `WorkDefinitionsScreen`, `WorkflowAdminScreen`, `ProcedureAuthoring`, `ProcedureOfficeReview`, and compatibility `Day2Workflow`.

**Produces:** independent request type, approval path, review/publish pages; no three-tab process workspace.

- [ ] Change the test first to assert the absence of `WorkspaceTabs` from the independent pages:

  ```tsx
  it('renders request types as a direct page without process tabs', () => {
    render(<WorkDefinitionsScreen />)
    expect(screen.getByRole('heading', { name: 'Request Types' })).toBeTruthy()
    expect(screen.queryByRole('navigation', { name: 'Procedures and workflow sections' })).toBeNull()
  })
  ```
- [ ] Make `/admin/work-definitions` render `WorkDefinitionsScreen` directly with the "Request Types" heading, and `/admin/workflow` render `WorkflowAdminScreen` with the "Approval Paths" heading.
- [ ] Make `/admin/procedures/review` an independent review and publish page. Keep `/admin/procedures/authoring` as a deep link for the authoring tool if a request-type journey needs it, but do not show it as a duplicate item when "Request Types" is the primary entry.
- [ ] Keep `/admin/workflow/day2` as a direct compatibility link to `Day2Workflow` without `ProcessWorkspace` and without a sidebar entry.
- [ ] Remove the `ProcessWorkspace` call from `AppWorkspace`. Delete the file only if no consumer remains after `rg -n "ProcessWorkspace" apps/web/src`; otherwise turn it into a compatibility wrapper without tabs.
- [ ] Test capabilities separately: `work_definition.read` does not show review/publish, and `workflow.approve` does not show accounts or Organization.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/workflow/ProcessWorkspace.test.tsx src/features/workflow/ProcedureAuthoring.test.tsx src/features/workflow/ProcedureOfficeReview.test.tsx src/features/r1/R1Screens.test.ts src/shell/navigation.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/workflow apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/app/copy.ts && git commit -m "refactor(web): split workflow administration journeys"`

### Task 8: Flatten Identity and Authorization Administration

**Files:**

- Modify: `apps/web/src/features/authorization/AccessWorkspace.tsx`
- Modify: `apps/web/src/features/authorization/AccessWorkspace.test.tsx`
- Create: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx`
- Create: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx`
- Create: `apps/web/src/features/authorization/AccessScopesScreen.tsx`
- Create: `apps/web/src/features/authorization/AccessScopesScreen.test.tsx`
- Modify: `apps/web/src/features/authorization/AuthorizationAdmin.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`

**Consumes:** `IdentityAccounts`, `AuthorizationAdmin`, `AccessContext`, existing authorization list API.

**Produces:** exactly one two-tab roles/capabilities page; all other authorization resources as direct pages; personal access context in user menu.

- [ ] Write a structure test before the change:

  ```tsx
  it('offers exactly two tabs on the roles and capabilities object', () => {
    render(<RolesCapabilitiesWorkspace locale="ar" activeResource="roles" navigate={vi.fn()} />)
    const nav = screen.getByRole('navigation', { name: 'Roles and capabilities' })
    expect(within(nav).getAllByRole('link')).toHaveLength(2)
    expect(within(nav).queryByRole('link', { name: 'Delegations' })).toBeNull()
  })
  ```
- [ ] Create `RolesCapabilitiesWorkspace` for only the `roles` and `capabilities` tabs. Each tab applies its own capability so the capabilities tab is not exposed to a user with `role.read` only.
- [ ] Render `IdentityAccounts`, `role-assignments`, `delegations`, policies, and supervisory relationships directly from `AppWorkspace` without primary or secondary tabs.
- [ ] Create `/admin/authorization/access-scopes` as an independent read page that calls `listAuthorization('role-assignments')` and shows `scope_type`, `scope_id`, the user, the role, and the period. The only action is "Open role assignment"; no new scope-management endpoint.
- [ ] Move `AccessContext` to `/me/access` only, and `AccessExplanation` to the internal-tools group only.
- [ ] Reduce `AccessWorkspace` to a temporary compatibility wrapper or delete it if no import remains. Do not leave navigation with 4 tabs followed by 7 tabs.
- [ ] Test denied/error/empty in `AccessScopesScreen`, and that the policy and delegation pages are independent in active state.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/authorization/AccessWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/authorization/AccessScopesScreen.test.tsx src/features/authorization/AccessContext.test.tsx src/shell/navigation.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/authorization apps/web/src/app/AppWorkspace.tsx && git commit -m "refactor(web): flatten identity and authorization administration"`

### Task 9: Separate Reports, Dashboards, and Internal Tools

**Files:**

- Create: `apps/web/src/features/reporting/DashboardsScreen.tsx`
- Create: `apps/web/src/features/reporting/DashboardsScreen.test.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/shell/navigation.tsx`
- Modify: `apps/web/src/app/copy.ts`

**Consumes:** `GET /reports`, `GET /dashboards`, `GET /dashboards/{id}`, current Coverage and Swagger screens.

**Produces:** direct reports page, direct dashboards page, and bottom internal-tools group hidden from ordinary users.

- [ ] Write the following tests before wiring:

  ```tsx
  it('renders only dashboards returned by the authorized list endpoint', async () => {
    listDashboardsMock.mockResolvedValue({ items: [dashboard('d-1')], total: 1 })
    render(<DashboardsScreen />)
    expect(await screen.findByRole('link', { name: /Overdue Transactions/ })).toBeTruthy()
  })

  it('does not expose internal tools without audit capability', () => {
    expect(pathsFor(['reporting.list'])).not.toContain('/coverage')
    expect(pathsFor(['authorization.audit.read'])).toContain('/api-docs')
  })
  ```
- [ ] Create `/dashboards` listing the authorized dashboards, with detail inside the page or via `/dashboards/:dashboardId` if a permanent link is needed. Do not mix them into `ReportsScreen`.
- [ ] Display source/freshness/last-updated when the contract returns them. If it does not, do not invent a value; record the need as a separate contract improvement only if it is required for acceptance.
- [ ] Move Coverage, API docs, and Access Explanation to the `internal` group at the bottom of the sidebar. Hide the whole group without `authorization.audit.read`/`authorization.decision.read`.
- [ ] Add a client route guard for the tools pages that shows denied on direct navigation without capability, and keep any protected data behind the API as well.
- [ ] Wire `PrincipalDashboards` to `/dashboards` and verify that an employee without dashboards does not see an empty area.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/reporting/DashboardsScreen.test.tsx src/features/reporting/PrincipalDashboards.test.tsx src/shell/navigation.test.tsx
  ```
- [ ] If commits are authorized: `git add apps/web/src/features/reporting apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/shell/navigation.tsx apps/web/src/app/copy.ts && git commit -m "feat(web): separate reports dashboards and internal tools"`

### Task 10: Finish Shell Density, Responsive Behavior, RTL/LTR, and Accessibility

**Files:**

- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/app/AppShell.css`
- Modify: `apps/web/src/ui/ui.css`
- Modify: `apps/web/src/features/dashboard/WorkDashboard.css`
- Create: `apps/web/src/app/AppShell.test.tsx`
- Modify: `apps/web/e2e/shell.spec.ts`

**Consumes:** final navigation groups, user menu entries, scope selector, dashboard structure.

**Produces:** calm operations-room shell, compact dashboard, accessible collapsed sidebar and mobile drawer in both directions.

- [ ] Add component tests for collapse, tooltips, the user menu, and the scope selector before the final CSS. Verify `aria-current`, `aria-expanded`, button names, and focus restoration.
- [ ] Keep the desktop sidebar at 264px and the collapsed state at 68px unless browser QA proves the adopted copy needs an adjustment. In the collapsed state, items show a tooltip accessible by keyboard, not a visual title only.
- [ ] Keep the mobile drawer real: Escape closes it, the background is inert, focus returns to the menu button, and `inline-start` switches with RTL/LTR.
- [ ] Apply logical CSS properties (`inline-size`, `margin-inline-*`, `inset-inline-*`) and isolate English numbers/IDs locally with `dir="ltr"`.
- [ ] Review contrast and focus against WCAG 2.2 AA and respect `prefers-reduced-motion`. Color does not carry state meaning alone.
- [ ] Add assertions that prevent horizontal overflow at 320px and lock a 2×2 KPI grid then a single column for the content.
- [ ] Run:

  ```bash
  npm --prefix apps/web run test:unit -- src/app/AppShell.test.tsx src/features/dashboard/WorkDashboard.test.tsx
  npm --prefix apps/web run lint
  npm --prefix apps/web run build
  ```
- [ ] If commits are authorized: `git add apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.css apps/web/src/ui/ui.css apps/web/src/features/dashboard/WorkDashboard.css apps/web/src/app/AppShell.test.tsx apps/web/e2e/shell.spec.ts && git commit -m "feat(web): finish responsive accessible application shell"`

### Task 11: Add Persona-Based E2E Journeys and Close the Delivery Gate

**Files:**

- Modify: `apps/web/e2e/shell.spec.ts`
- Create: `apps/web/e2e/capability-navigation.spec.ts`
- Create: `apps/web/e2e/personal-work.spec.ts`
- Modify only if fixture gaps are proven: `apps/api/database/seeders/DevelopmentJourneyAuthorizationSeeder.php`
- Modify only if fixture gaps are proven: `apps/api/app/Console/Commands/SeedW12E2EFixture.php`
- Track checkboxes in: `docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md`

**Consumes:** all previous slices.

**Produces:** evidence for employee, manager, and platform owner journeys across desktop/mobile and RTL/LTR, including negative direct URLs and refresh/history behavior.

- [ ] Update the shell fixture to return `/api/v1/me` with the correct `capabilities` and `/me/scopes` with an actual scope. Do not let a route wildcard return 200 for everything because that hides contract bugs.
- [ ] Write the E2E matrix:

  | Persona | Must see | Must not see |
  |---|---|---|
  | Employee | Home, my requests, my tasks, procedures, documents | Administration, internal tools, manager indicators |
  | Manager / approver | Employee + awaiting my action + own-scope indicators | Unauthorized platform tools |
  | Platform owner | Authorized administration pages + internal tools | Any link without the actual capability |
- [ ] Add the approvals journey: the list does not show USER_B's step, a successful decision removes the item, 412 shows stale and reloads, and refresh does not bring back a resolved item.
- [ ] Add the My Requests journey: shows only what the user started, detail works after reload, and a direct ID for another user returns denied/404 without disclosure.
- [ ] Add the scope-change journey: changing scope zeros old content during loading, refreshes KPIs/lists/dashboard, and does not leave counters from the previous scope.
- [ ] Add the navigation journey: every visible link opens a non-403 page in that persona's fixture, and empty groups are not present. Test back/forward and detail links.
- [ ] Run E2E at 1280×800 and 320×720, once in Arabic RTL and once in English LTR for the core journeys, with screenshots/traces on failure.
- [ ] Run the final staggered verification:

  ```bash
  npm --prefix apps/web run api:check
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/principal-context.test.tsx src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/MyRequests.test.tsx src/features/dashboard/WorkDashboard.test.tsx src/features/organization/OrganizationWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/reporting/DashboardsScreen.test.tsx
  npm --prefix apps/web run lint
  npm --prefix apps/web run build
  make verify-boundaries
  npm --prefix apps/web run test:e2e:local -- e2e/shell.spec.ts e2e/capability-navigation.spec.ts e2e/personal-work.spec.ts
  ./scripts/validate-docs.sh
  ```
- [ ] Use `superpowers:verification-before-completion`. For each command record the timestamp, exit code, and tests executed. Do not claim "complete" if E2E is blocked by the environment; say precisely what is green and what is blocked.
- [ ] Run manual browser QA after the tests: full/collapsed sidebar, drawer, dashboard above the fold, scope switch, approvals, direct links, RTL/LTR, keyboard-only, focus, 200% zoom, and 320px overflow.
- [ ] Run `rg -n "TO[D]O|FIX[M]E|mock permanent|RequestDashboard|ProcessWorkspace|AccessWorkspace" apps/web/src apps/api docs/contracts` and explain any remaining match; do not leave scaffolding or permanent mocks.
- [ ] Review spec coverage point by point: no 3 tabs, no third level, no duplicated notifications, no client filtering, no static KPIs, no internal tools for the regular user.
- [ ] Request an independent review with `superpowers:requesting-code-review` on the full diff, and address the comments before closing.
- [ ] If commits are authorized: `git add apps/web/e2e apps/api/database/seeders/DevelopmentJourneyAuthorizationSeeder.php apps/api/app/Console/Commands/SeedW12E2EFixture.php docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md && git commit -m "test: cover adaptive dashboard and navigation journeys"`

## Implementation Order and Stop Conditions

1. Tasks 1–3 are mandatory sequentially because they lock in routes, context,
   and the security contract.
2. Task 4 completes before Task 5 so the dashboard uses the correct personal sources.
3. Tasks 6–9 can each have their tests and components prepared independently,
   but editing `AppWorkspace.tsx` and `routes.ts` is merged sequentially by one
   executor.
4. Task 10 runs after the page structure stabilizes so CSS is not rewritten twice.
5. Task 11 is the only close gate.

Execution stops and asks for a user decision only when:

- The in-flight Workflow changes belong to another route that cannot be merged
  without replacing their work.
- "Access Scopes" needs a new management area instead of the independent read
  view above role assignments; this is a product expansion, not an
  implementation assumption.
- A required capability is missing from `CapabilityCatalog` and cannot be safely
  expressed with an existing one.
- Old links would require data deletion or an irreversible migration.

## Definition of Done

- Employee, manager, and platform owner see different sidebars built on their
  capabilities, and every visible link opens a valid journey for them.
- The server blocks unauthorized direct links, and personal queues do not leak
  another user's data.
- The sidebar does not contain "Product Review"; documents live under My Work
  and internal tools live in a restricted bottom section.
- Organization and Roles/Capabilities are the only pages with two tabs; the
  rest are independent pages.
- The dashboard does not repeat notifications, does not show generic logs as
  personal decisions, and uses at most four real KPIs.
- Scope change refreshes all scope-bound data and does not display a stale
  snapshot.
- Build, targeted tests, API check, module boundaries, E2E, and docs
  validation are green with recent evidence.
- Keyboard, focus, RTL/LTR, and mobile were reviewed manually, with no
  horizontal overflow at 320px.
