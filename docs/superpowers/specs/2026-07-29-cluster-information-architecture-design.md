# Cluster Information Architecture Design

> **Date:** 2026-07-29  
> **Design status:** Approved through the current user decision session  
> **Implementation status:** Planned  
> **Related design:** `docs/superpowers/specs/2026-07-28-cluster-task-only-workspace-design.md`

## 1. Decision

Cluster will use a task-first information architecture with seven stable primary destinations and no accordion-first hierarchy:

1. Home
2. Tasks
3. Documents
4. Facilities and employees
5. Accounts and permissions
6. Reports and monitoring
7. Platform management

The first three destinations are daily work surfaces. The remaining four are capability-gated administrative workspaces. Search, notifications, and personal settings remain outside the primary navigation.

The target reflects the production feature projection:

```json
{
  "work_management": false,
  "tasks": true
}
```

Request, approval, procedure, Work Definition, Work Record, and Workflow surfaces do not appear in the active information architecture. Their code and historical data remain governed by the existing task-only workspace design.

## 2. Goals

1. Organize pages by the user's job rather than backend module names.
2. Remove duplicate and misleading navigation labels.
3. Reduce the shell from six accordion groups and fifteen active sidebar leaves to seven stable primary destinations.
4. Keep one primary navigation level and at most one workspace-local navigation level.
5. Distinguish job assignments from system permissions.
6. Preserve capability-based visibility and fail-closed route behavior.
7. Keep personal, operational, administrative, and technical surfaces in clearly separate locations.

## 3. Non-goals

- Re-enabling Work Management, requests, approvals, procedures, or Workflow.
- Deleting disabled Work Management modules, contracts, tables, migrations, or historical data.
- Redesigning task, document, organization, authorization, reporting, audit, or platform-setting business rules.
- Changing API authorization decisions or treating browser visibility as an authorization boundary.
- Decommissioning the Temporary Assignment or Authorization Delegation backend domains or deleting their historical data. This design removes their active web surfaces because task reassignment is the accepted absence-coverage mechanism.
- Changing the visual design system beyond navigation structure and labels.

## 4. Design principles

### 4.1 One primary destination per user purpose

A primary navigation item opens a complete work area. The sidebar does not expose every route, detail page, wizard, or technical diagnostic as an equal destination.

### 4.2 No single-child accordions

A domain with one workspace appears as a direct primary link. The shell must not render a group label followed by an identically named child link.

### 4.3 One secondary navigation level

Administrative workspaces may use one local navigation control for their sections. They must not combine a sidebar accordion, a tab strip, and a third resource selector for the same hierarchy.

### 4.4 User terminology over architecture terminology

Arabic labels describe the object or task familiar to an administrative user. Labels such as “governance,” “capabilities,” and “internal tools” are not primary navigation terms.

### 4.5 Hidden is not authorized

The API remains the authorization boundary. Navigation and route guards only prevent discovery and fail closed while the principal projection is unavailable.

## 5. Primary navigation

| Order | Arabic label | English label | Landing route | Visibility |
|---|---|---|---|---|
| 1 | الرئيسية | Home | `/` | Every authenticated user |
| 2 | مهامي | My tasks | `/tasks` | Tasks feature and relevant task capability |
| 3 | المستندات | Documents | `/documents` | Relevant document capability |
| 4 | المنشآت والموظفون | Facilities and employees | `/admin/organization` | At least one visible organization section |
| 5 | الحسابات والصلاحيات | Accounts and permissions | `/admin/identity/accounts` | At least one visible identity or authorization section |
| 6 | التقارير والمتابعة | Reports and monitoring | `/reports` | At least one report, dashboard, or audit capability |
| 7 | إدارة المنصة | Platform management | `/admin/platform` | At least one platform-setting or API-reference capability |

The shell computes each administrative destination from the visibility of its child sections. An empty administrative workspace is not advertised.

## 6. Daily work surfaces

### 6.1 Home

Home remains a personal summary, not an administrative module index. Task cards and task activity render only when the principal can read tasks. A platform administrator without task access must not see an empty or denied task panel.

### 6.2 Tasks

The primary Tasks destination owns:

- task list and filters;
- task creation;
- task detail;
- assignment and reassignment;
- participants, comments, documents, and lifecycle actions.

Creation and detail routes remain nested routes and never become sidebar entries. Reassignment is the approved mechanism for covering employee absence in the active task-only system.

### 6.3 Documents

The Documents destination owns document list, creation, detail, versions, and links. Detail routes remain nested and do not appear in the sidebar.

## 7. Facilities and employees workspace

The workspace contains three local sections:

1. **المنشآت والهيكل التنظيمي / Facilities and organization structure**  
   Keeps the existing facilities overview and organization-structure views within one sectioned workspace.
2. **الموظفون والتكليفات الوظيفية / Employees and job assignments**  
   Manages employee records and dated assignments to positions. “Job assignments” is explicit to avoid confusion with permission grants.
3. **العلاقات الإشرافية / Supervisory relationships**  
   Moves from the authorization workspace because it relates source and target organization units and is served by the Organization domain.

### 7.1 Employee import

Organization import is not a permanent sidebar destination. The current import flow is specific to the `people_assignments` template, so it becomes an **استيراد موظفين / Import employees** action inside Employees and job assignments. Its route remains a nested wizard route reached only from the employee context.

### 7.2 Temporary permission grants

The current “temporary assignments” screen does not manage job assignments. It grants capability codes to an employee inside one organization unit for a bounded window. Its frontend route, rendering case, navigation entry, and screen are removed from the active web application.

No existing backend data or backend domain code is deleted by this information-architecture change.

## 8. Accounts and permissions workspace

The workspace contains the following local sections:

1. **الحسابات / Accounts**
2. **الأدوار والصلاحيات / Roles and permissions**
3. **إسناد الأدوار / Role assignments**
4. **سياسات ونطاقات الصلاحيات / Permission policies and scopes**
5. **فحص قرار الصلاحية / Permission decision inspector**, visible only to its specialized administrative audience

Roles and capabilities remain distinct routes and read models, but they share one visible **Roles and permissions** section. Classification policies and field-access templates share the policies section. Administrative access scopes use `/admin/authorization/access-scopes`.

### 8.1 Personal access is not an admin tab

`/me/access` is advertised exclusively in the user menu under **صلاحياتي ونطاق عملي / My permissions and work scope**. Direct links remain valid for the current user, but the route must not appear as the administrative “scopes” tab.

### 8.2 Delegations

Authorization delegation transfers capabilities the delegator already holds to another user for a bounded scope and window. The user confirmed that task reassignment is sufficient for absence coverage, so the Delegations frontend route, rendering case, navigation entry, and screen are removed from the active web application.

This decision does not delete delegation history or backend domain code.

### 8.3 Diagnostics

The permission decision inspector moves from the primary “internal tools” group into this workspace. It must not have a second primary-sidebar entry.

## 9. Reports and monitoring workspace

One primary destination contains three local sections:

1. **التقارير / Reports**
2. **لوحات المؤشرات / Dashboards**
3. **سجل التدقيق / Audit ledger**

The sections keep their independent capabilities, loading states, export behavior, and routes. The workspace only consolidates discovery and active-state behavior; it does not merge their data models.

“Reports and monitoring” replaces the separate Reports/Insights and Governance/Audit placements with a term that describes how administrative users consume these surfaces.

## 10. Platform management workspace

Platform management keeps the existing platform-setting sections and adds a capability-gated technical section for **مرجع API / API reference**. API reference no longer appears in a primary “internal tools” group.

### 10.1 Coverage page removal

The hand-maintained Coverage screen is removed from the web application, its route registry, navigation registry, and route tests. It is a product-review artifact rather than an operational page and can drift from the authoritative OpenAPI contract.

The OpenAPI contract and generated API reference remain the sources for API coverage and documentation.

## 11. Header and user menu

### Header utilities

- Search
- Notifications
- Current scope selector

Search and notifications remain contextual utilities and do not become primary navigation entries.

### User menu

- **الأمان الشخصي / Personal security**
- **صلاحياتي ونطاق عملي / My permissions and work scope**
- Sign out

Personal destinations do not appear inside administrative workspaces.

## 12. Retired and hidden web surfaces

The target information architecture excludes:

- approvals and approval detail;
- requests and request detail;
- procedures, procedure authoring, and procedure review;
- Work Definitions and Workflow administration;
- Work Record creation and detail;
- temporary permission grants;
- authorization delegations;
- Coverage;
- the primary “Internal tools” group.

`workflow-day2` is not currently included in `WORK_MANAGEMENT_ROUTE_NAMES` and has no active `WorkspaceContent` destination. Its frontend route is removed from the active route registry so it cannot remain as an orphan destination. The disabled Work Management module code remains governed by the task-only workspace design.

Disabled Work Management backend behavior continues to follow the task-only workspace design.

## 13. Naming contract

| Current label | Target label |
|---|---|
| إدارة المنشآت والموظفين | المنشآت والموظفون |
| الأشخاص والتكليفات | الموظفون والتكليفات الوظيفية |
| الحوكمة والوصول | الحسابات والصلاحيات |
| الهوية والصلاحيات | الحسابات والصلاحيات |
| الأدوار والقدرات | الأدوار والصلاحيات |
| الإسنادات | إسناد الأدوار |
| السياسات والقوالب | سياسات ونطاقات الصلاحيات |
| النطاقات | نطاقات الصلاحيات |
| سياق الوصول الشخصي | صلاحياتي ونطاق عملي |
| فحص قرار الوصول | فحص قرار الصلاحية |
| التقارير والمؤشرات | التقارير والمتابعة |
| الأدوات الداخلية | Removed as a primary group |
| التكليفات المؤقتة | Removed from the active web surface |
| التفويضات | Removed from the active web surface |
| تغطية العمليات | Removed from the web application |

Arabic and English labels must use one translation key per concept across sidebar entries, workspace headings, page headings, accessible labels, and tests.

## 14. Route and active-state rules

1. Every registered active route is classified under exactly one primary destination or one shell utility.
2. Detail and wizard routes inherit the active state of their parent destination.
3. A workspace local section owns all of its nested route variants.
4. Personal routes never activate an administrative workspace.
5. Removed or feature-disabled routes do not appear in the target primary-route inventory.
6. Adding a route without a workspace classification remains a compile-time failure.
7. The administrative scopes section resolves to `/admin/authorization/access-scopes`, never `/me/access`.

## 15. Accessibility and responsive behavior

- Primary navigation uses links with an explicit current-page state.
- Workspace-local navigation has an accessible label distinct from the primary navigation label.
- Keyboard and screen-reader users can reach every visible destination without expanding hidden accordion layers.
- Mobile navigation preserves the same ordering and labels as desktop.
- Route changes move focus to the page heading according to the existing shell behavior.
- Hidden capability-gated destinations are not left as disabled, focusable controls.

## 16. Acceptance criteria

For a fully authorized principal with `work_management=false` and `tasks=true`:

1. The primary sidebar exposes exactly the seven destinations in Section 5.
2. No request, approval, procedure, Workflow, Work Definition, Work Record, temporary-permission, delegation, Coverage, or Internal Tools entry appears.
3. Facilities and employees exposes the three sections in Section 7; employee import starts from the employee section.
4. Accounts and permissions exposes the five sections in Section 8; administrative scopes opens `/admin/authorization/access-scopes`.
5. `/me/access` is advertised only in the user menu, remains directly addressable for the current user, and is labelled “صلاحياتي ونطاق عملي.”
6. Reports and monitoring exposes Reports, Dashboards, and Audit without duplicating them in the primary sidebar.
7. Platform management exposes API reference through a technical section and has no Coverage destination.
8. Home does not render task summaries for a principal who cannot read tasks.
9. Arabic and English navigation, headings, accessible labels, and tests use the naming contract in Section 13.
10. Direct navigation to a removed frontend route resolves through the not-found behavior; feature-disabled Work Management routes preserve their existing feature-disabled behavior.

Verification must cover route visibility, active-state inheritance, capability-filtered workspace sections, direct deep links, Arabic and English labels, keyboard navigation, mobile navigation, and the focused task-only browser journey.

## 17. Delivery boundaries

- The web information architecture changes first; no API contract regeneration is required unless implementation changes an API operation or schema.
- Existing capability checks remain authoritative and are reused rather than replaced by client-side role assumptions.
- Backend decommissioning of temporary assignments or delegations requires a separate decision and migration plan.
- No merge, push, deployment, production migration, or remote branch operation is part of this design session.
