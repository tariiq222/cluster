# Cluster Information Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Cluster's accordion-first shell with seven stable task-only destinations and consolidate each administrative domain into one capability-gated workspace.

**Architecture:** Keep `apps/web/src/shell/routes.ts` as the total route/workspace source and replace grouped sidebar data with a flat primary-navigation registry. Existing domain screens retain their data-fetching and authorization behavior; workspace components only consolidate discovery, active state, and local navigation. Retired frontend surfaces disappear cleanly while their backend domains and historical data remain unchanged.

**Tech Stack:** React 19, TypeScript 6, Vite 8, Vitest 4, Testing Library, Playwright 1.61, existing Cluster UI components and capability projection.

## Global Constraints

- Production defaults remain `work_management=false` and `tasks=true`.
- The seven primary destinations, in order, are Home, Tasks, Documents, Facilities and employees, Accounts and permissions, Reports and monitoring, and Platform management.
- Primary navigation is flat; no accordion state, single-child groups, or duplicate group/leaf labels remain.
- The API remains the authorization boundary; web visibility continues to fail closed while capabilities or feature projection are unresolved.
- Request, approval, procedure, Work Definition, Workflow, and Work Record backend code and data remain governed by the existing task-only design.
- Temporary Assignment and Authorization Delegation backend code and historical data remain; only their active frontend routes and management screens are removed.
- Employee import remains a nested route reached from Employees and job assignments.
- Search and notifications stay in the header. Personal security and `/me/access` stay in the user menu.
- Coverage is deleted from the web application. API reference remains capability-gated and moves into Platform management.
- Arabic, English, accessible labels, page headings, and tests use one naming contract.
- Do not edit generated API clients or the OpenAPI contract; this plan changes no API operation or schema.

---

### Task 1: Replace Accordion Navigation With Seven Direct Links

**Files:**
- Modify: `apps/web/src/shell/navigation.tsx:43-50,66-73,121-424`
- Modify: `apps/web/src/app/WorkspaceSidebar.tsx:1-66`
- Modify: `apps/web/src/app/AppWorkspaceShell.tsx:203-236`
- Modify: `apps/web/src/app/AppShell.tsx:22-37,80-118,120-198,220-250,350-370,535-555`
- Modify: `apps/web/src/app/AppShell.css:148-279,363-365`
- Modify: `apps/web/src/app/copy.ts:146-160,345-361`
- Test: `apps/web/src/shell/navigation.test.tsx`
- Test: `apps/web/src/app/AppShell.test.tsx`
- Test: `apps/web/src/app/AppWorkspace.navigation.test.tsx`

**Interfaces:**
- Produces: `PrimaryNavigationEntry`, `PrimaryNavigationItem`, `PRIMARY_NAVIGATION_ENTRIES`, and `buildPrimaryNavigationItems({ locale, capabilities, features })` in `shell/navigation.tsx`.
- Produces: `AppShell` prop `navigationItems: SidebarNavigationItem[]`.
- Produces: `useWorkspaceNavigation(...) : SidebarNavigationItem[]` in `WorkspaceSidebar.tsx`.
- Consumes: existing `AppRoute`, `pathFromRoute`, `isRouteActive`, capability projection, and feature projection.

- [ ] **Step 1: Write failing flat-registry tests**

Replace group-oriented assertions with the approved primary contract:

```tsx
const DISABLED = { work_management: false, tasks: true } as const

it('returns seven ordered primary destinations for a fully authorized task-only principal', () => {
  const items = buildPrimaryNavigationItems({
    locale: 'ar',
    capabilities: fullCapabilities,
    features: DISABLED,
  })

  expect(items.map(({ key, label, path }) => ({ key, label, path }))).toEqual([
    { key: 'home', label: 'الرئيسية', path: '/' },
    { key: 'tasks', label: 'مهامي', path: '/tasks' },
    { key: 'documents', label: 'المستندات', path: '/documents' },
    { key: 'organization', label: 'المنشآت والموظفون', path: '/admin/organization' },
    { key: 'accounts-permissions', label: 'الحسابات والصلاحيات', path: '/admin/identity/accounts' },
    { key: 'reports-monitoring', label: 'التقارير والمتابعة', path: '/reports' },
    { key: 'platform-management', label: 'إدارة المنصة', path: '/admin/platform' },
  ])
})

it('hides an administrative destination when none of its child capabilities is visible', () => {
  const items = buildPrimaryNavigationItems({
    locale: 'ar',
    capabilities: ['tasks.read'],
    features: DISABLED,
  })
  expect(items.map((item) => item.key)).toEqual(['home', 'tasks'])
})
it('uses the approved English names and keeps personal access in the user menu', () => {
  expect(buildPrimaryNavigationItems({
    locale: 'en',
    capabilities: fullCapabilities,
    features: DISABLED,
  }).map((item) => item.label)).toEqual([
    'Home',
    'My tasks',
    'Documents',
    'Facilities and employees',
    'Accounts and permissions',
    'Reports and monitoring',
    'Platform management',
  ])

  expect(buildUserMenuEntries('ar')).toContainEqual(expect.objectContaining({
    key: 'access-context',
    label: 'صلاحياتي ونطاق عملي',
    path: '/me/access',
  }))
})

```

In `AppShell.test.tsx`, pass a flat `navigationItems` fixture and assert links rather than group toggles:

```tsx
expect(screen.getByRole('link', { name: 'الرئيسية' })).toHaveAttribute('aria-current', 'page')
expect(screen.queryByRole('button', { name: 'مساحة عملي' })).toBeNull()
expect(screen.queryByRole('button', { expanded: true })).toBeNull()
```

- [ ] **Step 2: Run focused tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/shell/navigation.test.tsx src/app/AppShell.test.tsx src/app/AppWorkspace.navigation.test.tsx
```

Expected: FAIL because `buildPrimaryNavigationItems` and the `navigationItems` prop do not exist and the shell still renders accordion buttons.

- [ ] **Step 3: Implement the flat primary registry**

Add `FeatureProjection` to the existing type imports from `./routes`.

Replace `NavigationGroupKey`, `GROUP_LABELS`, `GROUP_ICONS`, `GROUP_ORDER`, and `buildNavigationGroups` with:

```tsx
export type PrimaryNavigationKey =
  | 'home'
  | 'tasks'
  | 'documents'
  | 'organization'
  | 'accounts-permissions'
  | 'reports-monitoring'
  | 'platform-management'

export type PrimaryNavigationEntry = {
  key: PrimaryNavigationKey
  route: AppRoute
  labelKey: NavigationLabelKey
  icon: ReactNode
  policy: NavigationPolicy
}

export type PrimaryNavigationItem = {
  key: PrimaryNavigationKey
  label: string
  path: string
  icon: ReactNode
  route: AppRoute
}

export const PRIMARY_NAVIGATION_ENTRIES: readonly PrimaryNavigationEntry[] = [
  { key: 'home', route: { name: 'list' }, labelKey: 'home', icon: ICONS.home, policy: { kind: 'authenticated' } },
  { key: 'tasks', route: { name: 'tasks' }, labelKey: 'myTasks', icon: ICONS.tasks, policy: anyOf(['tasks.read', 'tasks.list']) },
  { key: 'documents', route: { name: 'documents' }, labelKey: 'documents', icon: ICONS.documents, policy: anyOf(['documents.read', 'documents.list']) },
  { key: 'organization', route: { name: 'organization' }, labelKey: 'organizationAndWorkforce', icon: ICONS.organization, policy: anyOf(['organization.facility.read', 'organization.unit.read', 'organization.person.read', 'organization.import.read']) },
  { key: 'accounts-permissions', route: { name: 'identity-accounts' }, labelKey: 'accountsAndAccess', icon: ICONS.roles, policy: anyOf(['identity.account.read', 'authorization.role.read', 'authorization.capability.read', 'authorization.assignment.read', 'authorization.policy.read', 'authorization.decision.read']) },
  { key: 'reports-monitoring', route: { name: 'reports' }, labelKey: 'reportsAndIndicators', icon: ICONS.reports, policy: anyOf(['reporting.list', 'reporting.dashboard', 'audit.event.read']) },
  { key: 'platform-management', route: { name: 'platform-settings', section: 'overview' }, labelKey: 'platformManagement', icon: ICONS.apiDocs, policy: anyOf([...PLATFORM_SETTINGS_OVERVIEW_CAPABILITIES, 'authorization.audit.read']) },
]

export function buildPrimaryNavigationItems(args: {
  locale: Locale
  capabilities: readonly string[] | null
  features: FeatureProjection | null
}): PrimaryNavigationItem[] {
  return PRIMARY_NAVIGATION_ENTRIES
    .filter((entry) => isNavigationEntryVisible(entry, args.capabilities, args.features))
    .map((entry) => ({
      key: entry.key,
      label: text[args.locale][entry.labelKey],
      path: pathFromRoute(entry.route),
      icon: entry.icon,
      route: entry.route,
    }))
}
```

Use the approved Arabic values in `copy.ts`:

```ts
organizationAndWorkforce: 'المنشآت والموظفون',
accountsAndAccess: 'الحسابات والصلاحيات',
reportsAndIndicators: 'التقارير والمتابعة',
```

Use equivalent English values: `Facilities and employees`, `Accounts and permissions`, and `Reports and monitoring`.

- [ ] **Step 4: Flatten the shell renderer**

Change `WorkspaceSidebar.tsx` to map `buildPrimaryNavigationItems` into `SidebarNavigationItem[]` and compute `active` through `isRouteActive(route, item.route)`. Rename the hook to `useWorkspaceNavigation` and update its only production caller in `AppWorkspaceShell.tsx`.

Change `AppShell` to accept `navigationItems` and render one list:

```tsx
<nav className="primary-navigation" aria-label={copy.navigationTitle}>
  <ul className="primary-navigation-list">
    {navigationItems.map((item) => (
      <li key={item.key}>
        <a
          href={item.path}
          aria-label={item.label}
          aria-current={item.active ? 'page' : undefined}
          onClick={(event) => follow(event, item.onSelect)}
        >
          <span className="navigation-icon" aria-hidden="true">{item.icon}</span>
          <span className="navigation-item-label">{item.label}</span>
          {item.count ? <span className="navigation-item-count">{item.count}</span> : null}
        </a>
      </li>
    ))}
  </ul>
</nav>
```

Delete `openGroups`, `activeGroupKey`, `toggleGroup`, group chevrons, `aria-expanded`, and `hidden` lists. Replace group CSS with `.primary-navigation-list` and `.navigation-item-label`; keep active, hover, collapsed-icon, desktop, and mobile styles.

- [ ] **Step 5: Run focused tests and confirm they pass**

Run the Step 2 command.

Expected: PASS; seven direct links render in order, capability filtering is fail-closed, collapsed desktop mode keeps all visible icons, and mobile uses the same items.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/shell/navigation.tsx apps/web/src/shell/navigation.test.tsx apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.css apps/web/src/app/AppShell.test.tsx apps/web/src/app/WorkspaceSidebar.tsx apps/web/src/app/AppWorkspaceShell.tsx apps/web/src/app/AppWorkspace.navigation.test.tsx apps/web/src/app/copy.ts
git commit -m "feat(web): flatten primary navigation"
```

---

### Task 2: Reclassify Route Workspaces and Retire Obsolete Frontend Routes

**Files:**
- Modify: `apps/web/src/shell/routes.ts:1-52,99-167,169-271,273-501,523-625`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:7-45,166-211,298-324`
- Delete: `apps/web/src/features/workflow/Day2Workflow.tsx`
- Delete: `apps/web/src/features/workflow/Day2Workflow.test.tsx`
- Delete: `apps/web/src/features/workflow/ProcessWorkspace.tsx`
- Delete: `apps/web/src/features/workflow/ProcessWorkspace.test.tsx`
- Test: `apps/web/src/shell/routes.test.ts`
- Test: `apps/web/src/shell/routes.capabilities.test.ts`

**Interfaces:**
- Produces: `RouteWorkspace = 'tasks' | 'documents' | 'organization' | 'accounts-permissions' | 'reports-monitoring' | 'platform-management'`.
- Produces: total `ROUTE_WORKSPACE` mapping used by Task 1 and later workspace tasks.
- Removes from `AppRoute`: `temporary-assignments`, authorization resource `delegations`, `workflow-day2`, and `coverage`.
- Preserves: `/api-docs`, personal routes, and centrally feature-gated Work Management routes.

- [ ] **Step 1: Write failing route-cutover tests**

Add route assertions:

```ts
it('classifies every nested destination under one approved primary workspace', () => {
  expect(workspaceOfRoute({ name: 'task-detail', taskId: UUID })).toBe('tasks')
  expect(workspaceOfRoute({ name: 'document-detail', documentId: UUID })).toBe('documents')
  expect(workspaceOfRoute({ name: 'people-assignments' })).toBe('organization')
  expect(workspaceOfRoute({ name: 'organization-import' })).toBe('organization')
  expect(workspaceOfRoute({ name: 'authorization', resource: 'supervisory' })).toBe('organization')
  expect(workspaceOfRoute({ name: 'access-scopes' })).toBe('accounts-permissions')
  expect(workspaceOfRoute({ name: 'audit' })).toBe('reports-monitoring')
  expect(workspaceOfRoute({ name: 'dashboards' })).toBe('reports-monitoring')
  expect(workspaceOfRoute({ name: 'api-docs' })).toBe('platform-management')
})

it('returns not-found for retired frontend paths', () => {
  expect(routeFromPath('/admin/organization/temporary-assignments')).toEqual({ name: 'not-found' })
  expect(routeFromPath('/admin/authorization/delegations')).toEqual({ name: 'not-found' })
  expect(routeFromPath('/admin/workflow/day2')).toEqual({ name: 'not-found' })
  expect(routeFromPath('/coverage')).toEqual({ name: 'not-found' })
})
```

Assert that task detail, document detail, organization import, reports, audit, and API docs activate their landing primary route through `isRouteActive`.

- [ ] **Step 2: Run route tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts
```

Expected: FAIL because retired paths still parse and the expanded workspace classifications do not exist.

- [ ] **Step 3: Implement the total workspace map**

Use the exact workspace union from Interfaces and classify every surviving route name:

```ts
const ROUTE_WORKSPACE: Record<AppRoute['name'], RouteWorkspace | null> = {
  list: null,
  documents: 'documents',
  'document-detail': 'documents',
  create: null,
  detail: null,
  organization: 'organization',
  'organization-structure': 'organization',
  'people-assignments': 'organization',
  'organization-import': 'organization',
  'identity-accounts': 'accounts-permissions',
  authorization: null,
  'access-scopes': 'accounts-permissions',
  'access-context': null,
  'personal-security': null,
  'access-explanation': 'accounts-permissions',
  audit: 'reports-monitoring',
  tasks: 'tasks',
  'task-create': 'tasks',
  'task-detail': 'tasks',
  'work-definitions': null,
  'workflow-admin': null,
  'procedure-authoring': null,
  'procedure-office-review': null,
  'procedure-guide': null,
  'approval-inbox': null,
  'approval-detail': null,
  'my-requests': null,
  'my-request-detail': null,
  'new-procedure-request': null,
  search: null,
  reports: 'reports-monitoring',
  dashboards: 'reports-monitoring',
  'api-docs': 'platform-management',
  notifications: null,
  'platform-settings': 'platform-management',
  'not-found': null,
}
```

The Work Management route names mapped to `null` above remain centrally disabled by `WORK_MANAGEMENT_ROUTE_NAMES`; do not restore them to navigation or rendering.

Keep `workspaceOfRoute` special handling only for `authorization`: map `supervisory` to `organization`; map roles, capabilities, role assignments, classification policies, and field-access templates to `accounts-permissions`.

- [ ] **Step 4: Remove retired route variants and render cases**

Delete their `AppRoute` variants, `primaryRoutes` rows, `pathFromRoute` branches, `routeFromPath` parsers, capability branches, imports, and `WorkspaceContent` cases. Delete the orphan Day 2/Process workspace files. Do not remove backend API wrappers, generated types, database code, or historical data.

Keep Work Management routes already listed in `WORK_MANAGEMENT_ROUTE_NAMES`; the task-only design requires them to remain centrally disabled rather than deleted.

- [ ] **Step 5: Run route tests and web build**

Run:

```bash
npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts
npm --prefix apps/web run build
```

Expected: PASS; retired paths are not found, route totality compiles, and every retained route has a capability and workspace classification.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/shell/routes.ts apps/web/src/shell/routes.test.ts apps/web/src/shell/routes.capabilities.test.ts apps/web/src/app/WorkspaceContent.tsx apps/web/src/features/workflow/Day2Workflow.tsx apps/web/src/features/workflow/Day2Workflow.test.tsx apps/web/src/features/workflow/ProcessWorkspace.tsx apps/web/src/features/workflow/ProcessWorkspace.test.tsx
git commit -m "refactor(web): classify task-only workspaces"
```

---

### Task 3: Consolidate Facilities and Employees

**Files:**
- Modify: `apps/web/src/features/organization/OrganizationWorkspace.tsx:1-82`
- Modify: `apps/web/src/features/organization/OrganizationWorkspace.test.tsx`
- Modify: `apps/web/src/features/organization/PeopleAssignments.tsx:19-71,128-189`
- Modify: `apps/web/src/features/organization/PeopleAssignments.test.tsx`
- Modify: `apps/web/src/features/organization/index.ts`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:166-203`

**Interfaces:**
- Produces: `OrganizationWorkspaceProps.activeRoute: AppRoute` rather than a two-value route-name union.
- Produces: local section keys `organization`, `employees`, and `supervisory`.
- Consumes: `ImportReview`, `AuthorizationAdmin resource="supervisory"`, `PeopleAssignments`, existing organization screens, and `pathFromRoute`.

- [ ] **Step 1: Write failing workspace tests**

Assert capability-filtered sections and nested routes:

```tsx
expect(screen.getByRole('link', { name: 'المنشآت والهيكل التنظيمي' })).toBeVisible()
expect(screen.getByRole('link', { name: 'الموظفون والتكليفات الوظيفية' })).toBeVisible()
expect(screen.getByRole('link', { name: 'العلاقات الإشرافية' })).toBeVisible()
expect(screen.queryByRole('link', { name: 'التكليفات المؤقتة' })).toBeNull()
expect(screen.queryByRole('link', { name: 'استيراد البيانات' })).toBeNull()
```

In `PeopleAssignments.test.tsx`, pass `onImport` and assert the **استيراد موظفين** button invokes it once.

- [ ] **Step 2: Run focused tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/organization/OrganizationWorkspace.test.tsx src/features/organization/PeopleAssignments.test.tsx
```

Expected: FAIL because the current workspace has only overview/structure and PeopleAssignments has no import action.

- [ ] **Step 3: Expand `OrganizationWorkspace`**

Define one section mapping so nested views keep one local section active:

```tsx
type OrganizationSection = 'organization' | 'employees' | 'supervisory'

function sectionForRoute(route: AppRoute): OrganizationSection {
  if (route.name === 'people-assignments' || route.name === 'organization-import') return 'employees'
  if (route.name === 'authorization' && route.resource === 'supervisory') return 'supervisory'
  return 'organization'
}
```

Render three capability-filtered `WorkspaceTabs` entries. Within the organization section, `organization-structure` remains a nested view with the same section active. Within employees, `organization-import` renders `ImportReview`; otherwise render `PeopleAssignments` with:

```tsx
onImport={() => navigate(pathFromRoute({ name: 'organization-import' }))}
```

The supervisory section renders the existing server-backed supervisory read model. Do not move its API or change its capability.

- [ ] **Step 4: Add the employee import action and approved copy**

Change the Arabic title to `الموظفون والتكليفات الوظيفية` and English to `Employees and job assignments`. Add an optional `onImport?: () => void` prop and a secondary PageHeader button labelled `استيراد موظفين` / `Import employees`.

- [ ] **Step 5: Route all organization destinations through the workspace**

In `WorkspaceContent`, group organization, structure, people, import, and supervisory cases into one `OrganizationWorkspace` render. Remove standalone PeopleAssignments and ImportReview cases.

- [ ] **Step 6: Run focused tests and build**

Run the Step 2 command, then:

```bash
npm --prefix apps/web run build
```

Expected: PASS; employee import is contextual, all organization deep links keep the primary destination active, and no temporary-permission link exists.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/organization/OrganizationWorkspace.tsx apps/web/src/features/organization/OrganizationWorkspace.test.tsx apps/web/src/features/organization/PeopleAssignments.tsx apps/web/src/features/organization/PeopleAssignments.test.tsx apps/web/src/features/organization/index.ts apps/web/src/app/WorkspaceContent.tsx
git commit -m "feat(web): consolidate facilities and employees"
```

---

### Task 4: Simplify Accounts and Permissions

**Files:**
- Modify: `apps/web/src/features/authorization/AccessWorkspace.tsx:26-145`
- Modify: `apps/web/src/features/authorization/AccessWorkspace.test.tsx`
- Modify: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx:12-92`
- Modify: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx`
- Modify: `apps/web/src/features/authorization/AuthorizationAdmin.tsx:1-112,130-366`
- Modify: `apps/web/src/features/authorization/AuthorizationAdmin.test.tsx`
- Modify: `apps/web/src/features/authorization/AccessScopesScreen.tsx:1-90`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:189-211`
- Modify: `apps/web/src/features/organization/index.ts`
- Delete: `apps/web/src/features/organization/TemporaryAssignments.tsx`

**Interfaces:**
- Produces: five access section keys: `accounts`, `roles-permissions`, `role-assignments`, `policies-scopes`, `decision-inspector`.
- Produces: `accessSectionForRoute(route: AppRoute): AccessSectionKey`.
- Produces: `RolesCapabilitiesWorkspace` that renders roles and permissions together without a second tab strip.
- Preserves: standalone personal `AccessContext` at `/me/access` and backend delegation/temporary-assignment APIs.

- [ ] **Step 1: Write failing access-workspace tests**

Replace the current eight-tab expectation with:

```tsx
expect(sectionLabels()).toEqual([
  'Accounts',
  'Roles and permissions',
  'Role assignments',
  'Permission policies and scopes',
  'Permission decision inspector',
])
expect(screen.queryByRole('link', { name: 'Delegations' })).toBeNull()
expect(screen.queryByRole('link', { name: 'Supervisory relationships' })).toBeNull()
expect(screen.queryByRole('link', { name: 'Personal access' })).toBeNull()
expect(screen.getByRole('link', { name: 'Permission policies and scopes' })).toHaveAttribute(
  'href',
  '/admin/authorization/classification-policies',
)
```

Add a route-specific assertion that `/admin/authorization/access-scopes` activates `policies-scopes`, while `/me/access` never renders `AccessWorkspace`.

- [ ] **Step 2: Run focused tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/authorization/AccessWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/authorization/AuthorizationAdmin.test.tsx src/features/authorization/AccessScopesScreen.test.tsx
```

Expected: FAIL because Delegations, personal access, and supervisory tabs still render and RolesCapabilitiesWorkspace still adds nested tabs.

- [ ] **Step 3: Implement five access sections**

Use:

```tsx
function accessSectionForRoute(route: AppRoute): AccessSectionKey {
  if (route.name === 'identity-accounts') return 'accounts'
  if (route.name === 'authorization' && (route.resource === 'roles' || route.resource === 'capabilities')) return 'roles-permissions'
  if (route.name === 'authorization' && route.resource === 'role-assignments') return 'role-assignments'
  if (route.name === 'authorization' || route.name === 'access-scopes') return 'policies-scopes'
  return 'decision-inspector'
}
```

The visible tab targets are Accounts, Roles, Role assignments, Classification policies, and Access explanation. Map capabilities, field-access-templates, and access-scopes deep links to their owning section so active state remains stable.

Use Arabic labels exactly: `الحسابات`, `الأدوار والصلاحيات`, `إسناد الأدوار`, `سياسات ونطاقات الصلاحيات`, `فحص قرار الصلاحية`.

- [ ] **Step 4: Remove nested roles/capabilities navigation**

Refactor `RolesCapabilitiesWorkspace` to load both visible resources and render two panels on one page. Remove its `WorkspaceTabs`, `activeResource`, and `navigate` props. Preserve independent loading/denied/error states for each resource so a principal with only one capability sees only the authorized panel.

- [ ] **Step 5: Remove active delegation and temporary-permission UI**

Remove delegation-only imports, labels, creation branches, mutation branches, and tests from `AuthorizationAdmin`. Keep role assignment behavior unchanged. Delete `TemporaryAssignments.tsx` and its organization export. Do not edit `apps/web/src/api/r1.ts`, generated clients, backend routes, migrations, or API tests.

Render `AccessContext` directly from `WorkspaceContent` for `access-context`; it is no longer an AccessWorkspace case. Keep its read-only effective-access projection, including historical server data, unchanged.

- [ ] **Step 6: Correct administrative scopes discovery**

Ensure the policies/scopes section links to `/admin/authorization/access-scopes` and that `AccessScopesScreen` links back to role assignments through `navigate` rather than assigning `window.location.href`. This keeps SPA focus and active-state behavior intact.

- [ ] **Step 7: Run focused tests and build**

Run the Step 2 command, then:

```bash
npm --prefix apps/web run build
```

Expected: PASS; the workspace has five sections, personal access is user-menu-only, no temporary/delegation management route renders, and direct admin scopes remain available.

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/features/authorization/AccessWorkspace.tsx apps/web/src/features/authorization/AccessWorkspace.test.tsx apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx apps/web/src/features/authorization/AuthorizationAdmin.tsx apps/web/src/features/authorization/AuthorizationAdmin.test.tsx apps/web/src/features/authorization/AccessScopesScreen.tsx apps/web/src/features/authorization/AccessScopesScreen.test.tsx apps/web/src/features/organization/TemporaryAssignments.tsx apps/web/src/features/organization/index.ts apps/web/src/app/WorkspaceContent.tsx
git commit -m "feat(web): simplify accounts and permissions"
```

---

### Task 5: Create Reports and Monitoring Workspace

**Files:**
- Create: `apps/web/src/features/reporting/ReportsMonitoringWorkspace.tsx`
- Create: `apps/web/src/features/reporting/ReportsMonitoringWorkspace.test.tsx`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:204-227,306-314`

**Interfaces:**
- Produces: `ReportsMonitoringRoute = Extract<AppRoute, { name: 'reports' | 'dashboards' | 'audit' }>`.
- Produces: `ReportsMonitoringWorkspace` props `{ locale, route, session, capabilities, scopeId, revision, navigate }`.
- Consumes: `ReportsScreen`, `DashboardsScreen`, `AuditWorkspace`, `WorkspaceTabs`, and existing route/capability helpers.

- [ ] **Step 1: Write the failing workspace test**

```tsx
renderWorkspace({
  route: { name: 'reports' },
  capabilities: ['reporting.list', 'reporting.dashboard', 'audit.event.read'],
})
expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
  'التقارير',
  'لوحات المؤشرات',
  'سجل التدقيق',
])
expect(screen.getByRole('link', { name: 'التقارير' })).toHaveAttribute('aria-current', 'page')
```

Add one test that each tab is hidden when its exact capability is absent.

- [ ] **Step 2: Run the test and confirm it fails**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/reporting/ReportsMonitoringWorkspace.test.tsx
```

Expected: FAIL because the workspace file does not exist.

- [ ] **Step 3: Implement the workspace**

Create a capability-filtered tab registry:

```tsx
const sections = [
  { key: 'reports', route: { name: 'reports' } as const, capability: 'reporting.list', ar: 'التقارير', en: 'Reports' },
  { key: 'dashboards', route: { name: 'dashboards' } as const, capability: 'reporting.dashboard', ar: 'لوحات المؤشرات', en: 'Dashboards' },
  { key: 'audit', route: { name: 'audit' } as const, capability: 'audit.event.read', ar: 'سجل التدقيق', en: 'Audit ledger' },
] as const
```

Render the existing screen for the active route; do not merge their API calls, state, or export behavior.

- [ ] **Step 4: Route reports, dashboards, and audit through one workspace**

Replace the three standalone `WorkspaceContent` branches with one grouped branch. Preserve dashboard detail IDs, scope ID, principal revision, audit token, and capabilities.

- [ ] **Step 5: Run focused tests and build**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/reporting/ReportsMonitoringWorkspace.test.tsx src/features/reporting/DashboardsScreen.test.tsx src/features/audit/AuditWorkspace.test.tsx
npm --prefix apps/web run build
```

Expected: PASS; one primary destination owns all three routes and each screen retains its behavior.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/reporting/ReportsMonitoringWorkspace.tsx apps/web/src/features/reporting/ReportsMonitoringWorkspace.test.tsx apps/web/src/app/WorkspaceContent.tsx
git commit -m "feat(web): add reports and monitoring workspace"
```

---

### Task 6: Move API Reference Into Platform Management and Delete Coverage

**Files:**
- Modify: `apps/web/src/features/platform-settings/PlatformSettingsLayout.tsx:1-86`
- Modify: `apps/web/src/features/platform-settings/PlatformSettingsLayout.test.tsx`
- Modify: `apps/web/src/features/platform-settings/copy.ts`
- Modify: `apps/web/src/app/workspace-routes.tsx:92-116,218-231`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:298-324`
- Delete: `apps/web/src/features/portal/CoverageScreen.tsx`
- Delete: `apps/web/src/features/portal/CoverageScreen.test.tsx`
- Delete: `apps/web/src/features/portal/coverage-data.ts`

**Interfaces:**
- Produces: `PlatformWorkspaceSection = PlatformSettingsSection | 'api-reference'` in `PlatformSettingsLayout.tsx`.
- Produces: `PlatformApiDocsRoute({ locale, capabilities, navigate })` that wraps `ApiDocsRoute` with the platform layout.
- Consumes: existing `/api-docs` route and its current capability gate.

- [ ] **Step 1: Write failing platform-layout tests**

Add:

```tsx
it('shows API reference as a technical platform section only to its capability holder', () => {
  renderLayout({ section: 'overview', capabilities: ['platform_settings.read', 'authorization.audit.read'] })
  expect(screen.getByRole('link', { name: 'مرجع API' })).toHaveAttribute('href', '/api-docs')
})

it('does not advertise API reference without its capability', () => {
  renderLayout({ section: 'overview', capabilities: ['platform_settings.read'] })
  expect(screen.queryByRole('link', { name: 'مرجع API' })).toBeNull()
})
```

Update route tests from Task 2 to confirm `/coverage` is not found while `/api-docs` still maps to `platform-management`.

- [ ] **Step 2: Run focused tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/platform-settings/PlatformSettingsLayout.test.tsx src/shell/routes.test.ts src/shell/routes.capabilities.test.ts
```

Expected: FAIL because API reference is not in platform local navigation and Coverage still has frontend artifacts before deletion.

- [ ] **Step 3: Extend platform local navigation**

Build local navigation items from the existing settings sections plus:

```tsx
{
  key: 'api-reference' as const,
  path: '/api-docs',
  label: copy.sections.apiReference,
  visible: capabilities?.includes('authorization.audit.read') === true,
}
```

Add `apiReference: 'مرجع API'` and `apiReference: 'API reference'` to platform copy. `PlatformApiDocsRoute` must render the same PageHeader and local navigation as settings, with `api-reference` marked current, then lazy-load Swagger UI as before.

- [ ] **Step 4: Delete Coverage and remove stale imports/copy**

Delete the three Coverage files and remove every route, navigation, render, copy, and test reference. Do not replace it with another hand-maintained coverage page; OpenAPI and generated reference remain authoritative.

- [ ] **Step 5: Run focused tests and build**

Run the Step 2 command, then:

```bash
npm --prefix apps/web run build
```

Expected: PASS; API reference appears only inside Platform management and no Coverage bundle or route remains.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/platform-settings/PlatformSettingsLayout.tsx apps/web/src/features/platform-settings/PlatformSettingsLayout.test.tsx apps/web/src/features/platform-settings/copy.ts apps/web/src/app/workspace-routes.tsx apps/web/src/app/WorkspaceContent.tsx apps/web/src/features/portal/CoverageScreen.tsx apps/web/src/features/portal/CoverageScreen.test.tsx apps/web/src/features/portal/coverage-data.ts
git commit -m "feat(web): move API reference into platform management"
```

---

### Task 7: Hide Task Dashboard Content Without Task Access

**Files:**
- Modify: `apps/web/src/features/dashboard/dashboard-model.ts:21-29,87-101`
- Modify: `apps/web/src/features/dashboard/dashboard-model.test.ts`
- Modify: `apps/web/src/features/dashboard/WorkDashboard.tsx:15-45,74-155`
- Modify: `apps/web/src/features/dashboard/WorkDashboard.test.tsx`
- Modify: `apps/web/src/app/WorkspaceContent.tsx:93-135`

**Interfaces:**
- Produces: `DashboardFeatureFlags = { workManagement: boolean; tasks: boolean }`.
- Produces: `WorkDashboardProps.canViewTasks: boolean`.
- Consumes: principal capabilities and the existing server task feature projection.

- [ ] **Step 1: Write failing source and component tests**

```ts
expect(enabledDashboardSources({ workManagement: false, tasks: false })).toEqual([])
expect(enabledDashboardSources({ workManagement: false, tasks: true })).toEqual(['tasks'])
```

In the component test, render with `canViewTasks={false}` and assert `listTasks` is not called and task KPI/today headings are absent.

- [ ] **Step 2: Run focused tests and confirm they fail**

Run:

```bash
npm --prefix apps/web run test:unit -- src/features/dashboard/dashboard-model.test.ts src/features/dashboard/WorkDashboard.test.tsx
```

Expected: FAIL because Tasks is unconditional.

- [ ] **Step 3: Implement capability-aware task visibility**

Pass:

```tsx
canViewTasks={
  principal.features?.tasks === true &&
  principal.capabilities?.some((capability) => capability === 'tasks.read' || capability === 'tasks.list') === true
}
```

Memoize `{ workManagement: workManagementEnabled, tasks: canViewTasks }`. Do not fetch tasks or render task KPIs/panels when false. Preserve Work Management checks and dashboard visibility unchanged.

- [ ] **Step 4: Run focused tests and build**

Run the Step 2 command, then:

```bash
npm --prefix apps/web run build
```

Expected: PASS; platform administrators without task access see no empty task cards and no task API call.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/dashboard/dashboard-model.ts apps/web/src/features/dashboard/dashboard-model.test.ts apps/web/src/features/dashboard/WorkDashboard.tsx apps/web/src/features/dashboard/WorkDashboard.test.tsx apps/web/src/app/WorkspaceContent.tsx
git commit -m "fix(web): hide task dashboard without access"
```

---

### Task 8: Update Browser Journeys and Run the Web Gate

**Files:**
- Modify: `apps/web/e2e/capability-navigation.spec.ts`
- Modify: `apps/web/e2e/org-hierarchy-tree.spec.ts`
- Modify: `apps/web/e2e/platform-settings-comprehensive.spec.ts`
- Modify: `apps/web/e2e/w1-2-cookie-csrf.spec.ts`

**Interfaces:**
- Consumes: all prior task contracts.
- Produces: browser evidence for flat navigation, local workspace navigation, removed surfaces, RTL/LTR naming, collapsed/mobile behavior, and task-only absence coverage.

- [ ] **Step 1: Enumerate stale browser selectors before editing**

Use the Grep tool with:

- `path`: `apps/web/e2e;apps/web/src`
- `pattern`: `navigation-group-toggle|مساحة عملي|إدارة المنشآت والموظفين|الحوكمة والوصول|التقارير والمؤشرات|الأدوات الداخلية|التكليفات المؤقتة|تغطية العمليات`

Expected: only known stale selectors and assertions. Record every matching test before changing it; do not blanket-replace unrelated prose.

- [ ] **Step 2: Update capability-navigation browser contract**

For a fully authorized task-only principal, assert seven primary links by role and exact Arabic name. Assert no primary-navigation button has `aria-expanded`, no `.navigation-group-toggle` exists, and collapsed desktop/mobile modes preserve all visible destination icons and labels.

Also assert the removed names are absent:

```ts
for (const label of ['الطلبات والإجراءات', 'التكليفات المؤقتة', 'التفويضات', 'الأدوات الداخلية', 'تغطية العمليات']) {
  await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(0)
}
```

- [ ] **Step 3: Update organization and platform journeys**

Navigate directly through the primary links `المنشآت والموظفون` and `إدارة المنصة`, then use their local navigation. Remove accordion-opening helpers. In `w1-2-cookie-csrf.spec.ts`, delete the temporary-assignment UI segment; backend temporary-assignment coverage remains in API/module tests and is outside the active web journey.

- [ ] **Step 4: List E2E tests and run focused unit suites**

Run:

```bash
npm --prefix apps/web run test:e2e:list
npm --prefix apps/web run test:unit -- src/shell/navigation.test.tsx src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/app/AppShell.test.tsx src/app/AppWorkspace.navigation.test.tsx src/features/organization/OrganizationWorkspace.test.tsx src/features/authorization/AccessWorkspace.test.tsx src/features/reporting/ReportsMonitoringWorkspace.test.tsx src/features/platform-settings/PlatformSettingsLayout.test.tsx src/features/dashboard/WorkDashboard.test.tsx
```

Expected: E2E discovery succeeds with no focused/skipped additions; all focused unit tests pass.

- [ ] **Step 5: Run full static and unit verification**

Run:

```bash
npm --prefix apps/web run lint
npm --prefix apps/web run build
npm --prefix apps/web run test:unit
```

Expected: all commands exit 0. Existing warnings must not be relabelled as success; record any pre-existing warning separately.

- [ ] **Step 6: Smoke-test the changed user journey in a real browser**

Start the existing local API and Vite workflow using the repository-native local-dev process. Exercise, in Arabic RTL and English LTR:

1. Open each of the seven primary destinations.
2. Confirm task/detail, document/detail, organization/import, authorization, report/dashboard/audit, and API-reference deep links retain the correct primary current state.
3. Confirm search and notifications remain in the header.
4. Confirm Personal security and My permissions/work scope remain in the user menu.
5. Confirm removed routes resolve to not found and removed labels are absent.
6. Confirm keyboard traversal reaches every visible primary link and local workspace section.
7. Confirm desktop collapsed and mobile drawer layouts do not overflow.

Expected: the observed browser behavior matches all ten acceptance criteria and no console error is emitted on the exercised paths.

- [ ] **Step 7: Run the focused browser specs**

Run the repository's local E2E command with the changed specs:

```bash
npm --prefix apps/web run test:e2e:local -- capability-navigation.spec.ts org-hierarchy-tree.spec.ts platform-settings-comprehensive.spec.ts
```

Expected: all selected specs pass with one worker and zero retries.

- [ ] **Step 8: Commit**

```bash
git add apps/web/e2e/capability-navigation.spec.ts apps/web/e2e/org-hierarchy-tree.spec.ts apps/web/e2e/platform-settings-comprehensive.spec.ts apps/web/e2e/w1-2-cookie-csrf.spec.ts
git commit -m "test(web): verify simplified information architecture"
```

---

## Final Review Gate

Before handoff, compare the implementation against every acceptance criterion in `docs/superpowers/specs/2026-07-29-cluster-information-architecture-design.md`. Verify the final changed-file set contains no generated API edits, backend-domain deletions, new accordion state, temporary/delegation management UI, Coverage artifacts, or stale Arabic labels.

Run:

```bash
npm --prefix apps/web run lint
npm --prefix apps/web run build
npm --prefix apps/web run test:unit
npm --prefix apps/web run test:e2e:list
```

Then repeat the browser smoke scenario from Task 8. Do not merge, push, deploy, or alter production configuration as part of this plan.
