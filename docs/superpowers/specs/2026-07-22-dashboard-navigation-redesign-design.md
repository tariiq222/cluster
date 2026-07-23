---
doc_id: DSN-UI-002
title: Design of Dashboard and Navigation Reorganization for the Enterprise Work Portal
type: plans
status: accepted
version: 1.0.0
date: 2026-07-22
owner: Platform Engineering Office
reviewers:
- Product Lead
- Software Engineering Lead
classification: internal
review_cycle: With every major change in navigation, permissions, or dashboard
sources:
- docs/design-system.md
- docs/product/personas-and-journeys.md
- docs/plans/approvals-and-requests.md
- docs/domain/notifications-search-reporting.md
references:
- docs/governance/glossary.md
- docs/data-security/authorization-model.md
- docs/engineering/delivery-workflow.md
---

# Design of Dashboard and Navigation Reorganization for the Enterprise Work Portal

## 1. Adopted Decision

The interface is restructured around **the user's work**, not around backend modules.
The application retains a single primary route for the dashboard and a single
sidebar, but their content adapts to the user's actual capabilities and active
scope. No separate portals are created for employee, manager, and platform owner,
and no links are shown that lead to an expected denial.

The user adopted this direction, the sidebar and dashboard layout, the page map,
the permission rules, and the responsive behavior on 2026-07-22.

## 2. Current State Boundaries

This document is a target design, not a claim of execution completeness. At the
time it was prepared, the actual state was as follows:

- `shellNavigation` in `AppWorkspace.tsx` builds three fixed groups: "My Work",
  "Administration and Operations", and "Product Review".
- "Documents" appears under "Product Review" together with the coverage screen
  and API reference, even though it is an operational function for the user.
- `routes.ts` contains emerging logic that links routes to capabilities, but by
  itself it is not proof that the actual sidebar has become capability-filtered.
- `ProcessWorkspace` bundles operations, work definitions, and workflow
  definitions into three tabs.
- `OrganizationWorkspace` bundles five functions, and `AccessWorkspace` adds a
  first tab level and then seven sub-tabs for authorization.
- The current dashboard displays a large visual bar and four cards, then
  re-summarizes states elsewhere, and uses generic work logs under a heading
  suggesting they are decisions waiting for the user.
- Emerging components and directions exist for the approval inbox, my requests,
  and the procedures directory, but they are not complete until linked to
  server-filtered contracts, visible routes, and operational tests.

Any concurrent change in the work tree while this specification is being executed
must be reviewed and merged, not overwritten or treated as automatic completion.

## 3. Goals and Out of Scope

### Goals

- Make the first screen and the first navigation group answer: "What needs me now?".
- Separate decisions and approvals from execution tasks and from requests the
  user initiated.
- Make each independent object or journey an independent page, with a maximum
  of two navigation levels.
- Show administration pages based on capability and scope, while keeping the
  final denial decision on the server.
- Keep search, notifications, and personal context in fixed positions that do
  not compete with the sidebar.
- Unify list, detail, form, and state templates across Arabic and English.

### Out of Scope

- Changing business-module boundaries or the ownership of their tables.
- Creating a new permission system or replacing the existing RBAC and ABAC.
- Creating a separate dashboard for each role or cloning screens per persona.
- Introducing static data or fake indicators to compensate for a missing backend
  contract.
- Including the coverage screen or API reference in the regular operational user
  experience.

## 4. Information Architecture Principles

1. Navigation starts from the goal: decision, request, task, procedure, document.
2. The group in the sidebar is a visual heading only; each item under it is a
   direct link to a page.
3. A tab is used only if both items work on the same object and share the data
   source or component. The maximum is two tabs.
4. Detail pages have deep links and do not need a separate sidebar entry.
5. Personal screens under `/me/*` live in the user menu.
6. Search and notifications live in the top bar.
7. Internal tools live in a separate lower section and appear only for the
   appropriate platform or development capabilities.
8. Hiding a link is a UX improvement, not a substitute for the server's
   authorization decision.

## 5. Target Sidebar

### My Work

| Page | Purpose | Visibility |
|---|---|---|
| Home | Priorities, follow-up, authorized indicators | When the user has any work journey |
| Awaiting My Action | Decision and approval steps assigned to the user | When decision capability or assigned items exist |
| My Requests | Requests or workflow instances the user started | When read/create request capability exists |
| My Tasks | Execution tasks assigned to the user | When `tasks.read` or equivalent exists |
| Procedures and Services | Published procedures the user can start | When published, authorized definitions exist |
| Documents | Documents available to the user within their scope | When the appropriate document capability exists |

### Administration Groups by Work Domain

Administration is not gathered into one long sidebar. Each independent link is
split by the task the user performs, and any group with no remaining allowed
item is hidden:

- **Facilities and Workforce Administration:** facilities and structure, people,
  temporary assignments, data import, and supervisory relationships.
- **Procedures and Workflow:** request types, approval paths, and procedure
  review and publishing.
- **Accounts and Permissions:** accounts, roles and capabilities, role
  assignments, access scopes, delegations, classification policies, and field
  policies.
- **Reports and Indicators:** reports and indicator dashboards.

"Access Scopes" is not merged with "Delegations", and "Reports" is not merged with
"Indicator Dashboards", because each pair owns a different operational object and
journey.

### Internal Tools

- Inspect and explain an access decision.
- Product coverage.
- API reference.

This group appears at the bottom of the sidebar for the platform owner or an
authorized developer only. It is not used as a stand-in for missing operational
screens.

### User Menu and Top Bar

- User menu: personal security, "My Access Context", language switch, and sign
  out.
- Top bar: scope selector, global search, and notifications.
- Changing the scope reloads the dashboard data, counters, and related sidebars.

## 6. Main Dashboard

The route `/` remains the application's only dashboard. It is composed, in order, of:

1. A compact header showing the current scope and a short description, with
   "New Request" and "Browse Services" if they are allowed.
2. A short priority bar showing the count of "Awaiting My Action" items and a
   button that opens their inbox. A new-request button is not used when the
   text refers to decisions required.
3. At most four indicators above the fold:
   - Awaiting My Decision.
   - Tasks due today.
   - Overdue work.
   - My active requests.
4. A "What Needs You Now" list ordered by due date and delay risk, sourced from
   the server-filtered procedure inbox.
5. A "My Request Tracking" list with current owner, last update, and status.
6. A "Today" summary of appointments and due items.
7. A "My Scope Indicators" block for managers or anyone with reporting
   capability, sourced from the published, authorization-filtered dashboard
   definitions.

The page does not repeat the notification list; the bell and the drawer keep
their primary place. `PrincipalDashboards` (or what it leaves behind) is not
shown as an empty state for users without dashboards, and a single dashboard
failure does not drop the rest of the dashboard.

## 7. Page Map and Tab Rules

### Personal Work Routes

- Awaiting My Action: list then detail of the decision or related request.
- My Requests: list then detail of the request and its step history.
- My Tasks: list then task detail.
- Procedures and Services: published directory then a guided create form.
- Documents: list then a permanent document-detail route; detail is not a
  general-purpose tab.

### Administration Pages

- "Facilities and Structure" allows two tabs: facilities and structure, because
  both work on the same organizational chain.
- "Roles and Capabilities" allows two tabs: roles and capabilities, because both
  work on the same role-capability matrix.
- People, temporary assignments, and import are independent pages.
- Request types, approval paths, and review/publish are independent pages.
- Accounts, role assignments, scopes, delegations, policies, and supervisory
  relationships are independent pages.
- Reports and indicator dashboards are two independent pages.

No internal area is built with: sidebar link then tabs then sub-tabs.

## 8. Unified Screen Templates

### List Page

- `PageHeader` with a title, description, and one primary action.
- Search and filters bound to the URL when needed.
- A scannable table or list with real pagination or cursor.
- Each row opens a permanent detail link.
- Only limited quick actions use `Drawer`.

### Detail Page

- Status and allowed actions at the top.
- Core content in one clear area.
- Owner, scope, and date information in a supporting area.
- A timeline of events or workflow steps when applicable.
- Actions come from `allowed_actions` or an equivalent server decision, not
  from UI guesswork.

### Guided Create Form

- Starts from a published procedure or request type.
- Shows only the fields valid for that version.
- Supports draft, validate, and submit when contracts allow.
- Pins the live record to the published version of the type and approval path.

All templates use `Page`, `PageHeader`, `Panel`, `Button`, `Field`, `Select`,
`Drawer`, and `Feedback` from `apps/web/src/ui`, and no local primitives are
created inside `features/`.

## 9. Authorization and Data Flow

1. The session carries effective capabilities, user identity, and active scope.
2. `AppWorkspace` builds sidebar items from a single route registry containing
   path, name, icon, required capability, and group.
3. Gated items remain hidden until capabilities finish loading, then empty
   groups are removed.
4. On navigation or direct link, the API reapplies the same decision to the
   sidebar, detail, search, reports, export, and download.
5. A 401 response is handled as an expired session, 403 as an explicit denial,
   404 as a record not found or not disclosable per the security contract, and
   409/412 as conflict or stale version.
6. Changing the scope logically cancels older in-flight requests, re-runs the
   query, and zeros temporary counters before showing new-scope data.

Primary sources for the dashboard and personal pages:

- Procedure inbox: `GET /workflow/steps?assignee=me` with the appropriate state.
- My Requests: server-filtered workflow instances list for the current user.
- My Tasks: `GET /tasks` filtered for the current assignee.
- Manager indicators: `GET /dashboards` then the dashboard authorized within
  the scope.
- Notifications, documents, and work logs from their current authorization-
  filtered contracts.

Fetching every workflow instance or step and filtering it in React by
`user_id` is forbidden. Personal and security filtering is the server's
responsibility, and counters use the same filtered source the sidebar opens.
If an API contract changes, update OpenAPI then Orval before the interface
consumes it.

## 10. States and Errors

Every page and widget applies these states:

- **Loading:** skeleton that preserves the page's dimensions and does not show a
  misleading zero.
- **Empty:** explains why there is no data and what the next step is, if any.
- **Denied:** clear message with no names or numbers from the protected resource.
- **Error:** understandable message and a scoped retry.
- **Success:** visible confirmation with `aria-live` and a clear next destination.
- **Stale/Conflict:** safe reload before repeating the decision or update.

Reports and indicators display the source, period, freshness state, and last
update time when applicable. No fixture numbers or text are used in any
interface not dedicated to development.

## 11. Visual Direction, Responsiveness, and Accessibility

- Tone: "calm operations room": useful density, flat surfaces, limited semantic color.
- The large hero is removed from the dashboard and replaced with a compact
  priority bar.
- At most four indicators remain above the fold, and cards are never placed
  inside cards.
- Desktop uses a full-width collapsible sidebar with a clear tooltip in the
  collapsed state.
- Below the appropriate breakpoint the sidebar becomes a real drawer that
  supports Escape and returns focus.
- On mobile the dashboard becomes a single column, indicators become 2×2, and
  primary actions remain reachable without horizontal scrolling.
- The interface works in RTL and LTR, uses logical CSS properties and local
  `dir` for mixed text and digits.
- Text, selection, and focus meet WCAG 2.2 AA, and color does not carry
  meaning alone.
- `prefers-reduced-motion` is respected, and motion is used only to clarify a
  transition or state.

## 12. Route Compatibility and Migration

- Every currently valid deep link either keeps its path or gets a clear redirect
  to the replacement page.
- `/` stays the home page, and explicit routes are added for the procedure inbox,
  my requests, the procedures directory, and the independent administration pages.
- No route is deleted before its replacement exists and direct links plus
  back/forward history are tested.
- The "Product Review" category is removed from operational navigation, and its
  tools move to the internal section.
- `OrganizationWorkspace`, `ProcessWorkspace`, and `AccessWorkspace` are
  dismantled incrementally while keeping current components reusable, not via a
  one-shot rewrite.

## 13. Proposed Implementation Slices

The specification is executed in independent vertical slices to minimize
conflict with ongoing work:

1. **Foundation:** route and capability registry, sidebar filtering, empty
   groups, links and redirects, and the user menu and internal tools.
2. **My Work:** procedure inbox, my requests, tasks, and procedures directory
   with server-filtered contracts.
3. **Dashboard:** composing priorities, counters, follow-up, and authorized
   indicators.
4. **Organization Administration:** splitting facilities, people, temporary
   assignments, and import.
5. **Procedures:** splitting request types, approval paths, and review/publish.
6. **Identity and Access:** splitting accounts, assignments, scopes,
   delegations, and policies.
7. **Reports and Tools:** separating reports from dashboards and isolating the
   internal tools.

Each slice includes backend, contracts, interface, localization, accessibility,
and related tests before moving to the next.

## 14. Acceptance and Verification Criteria

The design is considered executed when all the following conditions are met:

- Employee, manager, and platform owner see different sidebars built on
  capabilities and scope, with no visible link that returns 403 in regular
  journeys.
- No user can reach a protected resource via a direct link, search, report, or
  export when the backend denies them.
- "Awaiting My Action" shows only the steps assigned to the user; "My Requests"
  shows only what they started; "My Tasks" shows only tasks assigned to them
  per the contract.
- The dashboard does not repeat notifications and does not show generic logs
  under a personal-decisions heading.
- No page has three tabs and no third navigation level exists.
- Every non-detail route has exactly one visible entry when its capability is held.
- Internal-tools pages do not appear for the regular operational user.
- Route, capability, active-group, and direct-link tests pass.
- Targeted tests for the procedure inbox, my requests, my tasks, the dashboard,
  and 401/403/404/409/412 cases pass.
- `npm --prefix apps/web run build` and the affected targeted tests pass.
- E2E covers an employee, a manager, and a platform owner account on desktop
  and mobile, in RTL and LTR, with success, denial, error, reload, back, and
  direct-link scenarios.
- Automated accessibility checks pass, then keyboard and focus are inspected
  manually; automated success alone does not prove accessibility completeness.

## 15. Risks and Controls

- **Data leakage from client-side filtering:** prevented by making `me`, scope,
  owner, and assignee server-side filters, and by testing that other users'
  data is not visible.
- **Conflict with ongoing work:** the Git tree is inspected before each slice,
  concurrent changes are preserved, and merged selectively.
- **Long sidebar for the platform owner:** mitigated with groups, collapse,
  and search, without merging independent objects into deep tabs.
- **Breaking saved links:** prevented with a redirect map and route parsing and
  history tests.
- **Inconsistent counters:** pages and counters use the same filtered query
  source.
- **Polish without completeness:** screenshots alone are not accepted; a working
  contract, full states, tests, and an actual browser journey are required.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-22 | Platform Engineering Office | Adopt the dashboard, sidebar, and page reorganization by user work and capabilities |
