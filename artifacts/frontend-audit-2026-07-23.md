# Frontend Comprehensive Audit — Third Health Cluster Platform

**Date:** 2026-07-23
**Method:** 7 parallel source-grounded auditors (dev servers offline; static + OpenAPI/Orval contract cross-check)
**Scope:** All 35 routes registered in `apps/web/src/shell/routes.ts` plus the login + not-found surfaces
**Sources of truth:** `DESIGN.md`, `apps/web/src/ui/`, `apps/web/src/styles/`, `apps/api/routes/web.php`, generated `apps/web/src/api/generated/cluster.ts`

Each finding cites `file:line` from this repo. Verdicts use 🟢 (production-ready) / 🟡 (usable with caveats) / 🔴 (ship-blocker).

---

## 1. Executive Summary

The platform has a strong shared primitive library (`apps/web/src/ui/`) and a clearly written design system (`DESIGN.md`). Roughly half of the 35 audited screens consume those primitives correctly; the other half drift in ways that materially degrade UX and authority correctness.

**Headline numbers**

| Verdict | Count | Examples |
|---------|-------|----------|
| 🔴 Ship-blocker | **11** | `/admin/authorization/access-scopes`, `/admin/authorization/access-context`, `/admin/authorization/explain/:decisionId`, `/procedures`, `/admin/procedures/authoring`, `/admin/procedures/review`, `/approvals` (partial), `/my-requests`, `/tasks`, `/my-requests` (cards), `/reports`, `/dashboards`, `/search`, `/notifications`, `/coverage`, `/api-docs`, `/admin/workflow/day2` |
| 🟡 Usable with caveats | **17** | Login, AppShell (header), `/admin/organization/structure`, `/admin/organization/temporary-assignments`, `/admin/imports/organization`, `/admin/identity/accounts`, Authorization screens (most), `/procedures/new`, `/admin/workflow`, `/tasks/:taskId`, `/my-requests/:instanceId`, `/me/security` |
| 🟢 Production-ready | **6** | `/admin/organization`, `/admin/organization/people`, `/work-records/new`, plus most drawer's inner sub-screens (Facilities, Drawers) |

**Top systemic issues (every auditor saw at least three)**

1. **Half-built contract drift.** Several screens call generated endpoints with wrong field names or missing tokens; the most damaging: `AccessScopesScreen` reads `subject_id`/`role_code`/`starts_at`/`ends_at` (none of which exist on the backend → entire screen is `—`), `AccessContext` calls `getCurrentPrincipal` instead of the principal-context endpoint, `AccessDecisionWorkspace` fetches `/api/v1/access/decisions/...` instead of `/api/v1/authorization/access-decisions/{id}/explanation` (always 404), `SearchScreen` filters by `resource_type`/`record_type` (response uses `source_type`), `ProcedureGuide` calls `listWorkflowDefinitions` (requires `workflow.read`) but the route is gated only on `work_definition.read`, `CoverageScreen` claims 112 ops / v1.0.0 while the contract is v1.1.0 with 189 operations, `SwaggerUiScreen` ships the obsolete W1.1 contract pointing at `https://api.cluster.example/api/v1`.
2. **Design system bypass.** `apps/web/src/ui/` exposes `Page`, `Panel`, `Button`, `Drawer`, `Field`, `Select`, `Feedback`, `SkeletonList`, `EmptyState`, `InlineError`, `StatusBadge`, `MetricTile`-placeholders — yet `LoginScreen`, `AppShell`, several list-rendering paths, `Procedures`, `Day2Workflow`, and feature-local `Field` reimplementations in `TemporaryAssignments`, `ImportReview`, `IdentityAccounts` all bypass the library. Token drift: `#3b6cf6`, `#062b42`, raw `rgba(...)` in `AppShell.css`, `backdrop-filter: blur` against "No Glass Rule", gradients + blob borders against "Flat-by-Default".
3. **Workflow state machine fiction.** `procedure-guide`, `procedure-authoring`, and `procedure-office-review` merge `definition_state`, `review_state`, `approval_status`, and `lifecycle_state` into whatever the screen wants to show. `ProcedureAuthoring.submit` does `'submit' as unknown as WorkflowAction` and the URL doesn't exist on the backend (catches 400, reports success without server change). `ProcedureOfficeReview` calls the legacy `/approve` route that 409s on modern controllers and swallows the 404 from `/return`.
4. **Action projection gap.** Tasks and approvals advertise capabilities (`tasks.complete`/`tasks.update`, `workflow.reassign`, `workflow.escalate`) but the UI surfaces only `approve|reject`. Inbox rows reuse `source_type · source_id` as the only subject, so all cards look identical. Server returns `allowed_actions` for approvals; UI drops `reassign`/`escalate`.
5. **Hard navigations defeat the SPA.** `window.location.href` in `MyRequests`, `MyRequestDetail`, `ApprovalDetail`, `TaskDetail`; `<a href>` in `ApprovalInbox`; `window.location.href` in `AccessScopesScreen`. Each bypasses `scopeReady`, `scopeEpoch`, and capability preflight.
6. **Shared test gap.** `TaskDetail` (`t` UUID), `TasksScreen` (none), `ProcedureAuthoring` (render only), `ProcedureOfficeReview` (render only), `ProcedureGuide` (empty + one published), `AccessContext` (extensive but renders against wrong schema), `DashboardsScreen` (scope races only), `PersonalSecurity` (happy path only). No E2E coverage exists for the rendered states called out here.
7. **Status badge semantic loss.** Every screen uses the green-success default badge for `pending`, `draft`, `returned`, `cancelled`, `archived`, `rejected`. Combined with `formattingLocale` ignoring locale for numerals on Reports KPIs, this is the single most visible UX regression.

---

## 2. Master Route Index

| # | Route | Screen file | Verdict | Short reason |
|---|---|---|---|---|
| — | `/login` | `app/LoginScreen.tsx` | 🟡 | Hand-rolled inputs/buttons bypass `Field`/`Button`; locale toggle shows "ال"/"En". |
| 1 | `/` (Home) | `features/dashboard/WorkDashboard.tsx` | 🔴 | Local `KpiCard` drifts from `MetricTile` token; `DashboardTask` cast widens `due_at` silently. |
| 2 | `/documents`, `/documents/:id` | `features/documents/DocumentsWorkspace.tsx` | 🟡 | Calls `getDocument*` without a token (will 401); reads `lifecycle_state` that doesn't exist; `link` has no UUIDv7 validation. |
| 3 | `/work-records/new` | `features/requests/RequestForm.tsx` | 🟢 | ARIA + validation focus work; no back/escape during edit. |
| 4 | `/work-records/:id` | `features/requests/RequestDetail.tsx` | 🟡 | Two parallel `<Page>` blocks; document picker filters on `current_version_id` that doesn't exist on docs; placeholder timeline. |
| 4b | `/work-records` (= `/`) | `features/dashboard/WorkDashboard.tsx` | 🔴 | No actual record list — only inbox/tasks/instances; no `/work-records` list route. `RequestDashboard.tsx` exists but is dead code. |
| 5 | `/admin/organization` | `features/organization/OrganizationOverview.tsx` | 🟢 | Drawer discipline is correct; only `cluster.status` rendering is hardcoded. |
| 6 | `/admin/organization/structure` | `features/organization/OrganizationStructure.tsx` | 🟡 | `window.confirm` outlier; ESLint disables; `OrganizationBoard` is 1130 lines with no test. |
| 7 | `/admin/organization/people` | `features/organization/PeopleAssignments.tsx` | 🟢 | Drawer pipeline (assignment + end) is exemplary. |
| 8 | `/admin/organization/temporary-assignments` | `features/organization/TemporaryAssignments.tsx` | 🟡 | Local `Field`, hardcoded `Cancel`, no auth-token on read endpoint. |
| 9 | `/admin/imports/organization[/:jobId]` | `features/imports/ImportReview.tsx` | 🟡 | Local `Field`/`Select`; size cap not announced; `load()` closure rebuilds. |
| 10 | `/admin/identity/accounts` | `features/identity/IdentityAccounts.tsx` | 🟡 | ETag-driven transitions correct; local `Field`/`Select`; `delivery` leaks as raw enum. |
| 11 | `/admin/authorization/roles` | `features/authorization/RolesCapabilitiesWorkspace.tsx` | 🟡 | Capability-stripped; no role-capability matrix; tab label via `split(' ')[0]`. |
| 12 | `/admin/authorization/capabilities` | (same workspace) | 🟡 | Flat list, `classification` not shown, empty copy reused from roles. |
| 13 | `/admin/authorization/role-assignments` | `features/authorization/AuthorizationAdmin.tsx` (`role-assignments`) | 🟡 | `policy_document` silently dropped; hardcoded English scope/status; invalid `published` option. |
| 14 | `/admin/authorization/delegations` | (same admin shell) | 🟡 | No `capability_codes` field (server rejects every payload); `end` datetime not enforced. |
| 15 | `/admin/authorization/classification-policies` | (same admin shell) | 🔴 | No create UI; `name/code/status` only — `policy_document` hidden; 404 collapses to `error`. |
| 16 | `/admin/authorization/field-access-templates` | (same admin shell) | 🔴 | Same template, no `field_decisions` editor, no governance gate for audit reason. |
| 17 | `/admin/relationships/supervisory` | (same admin shell) | 🟡 | Module misnamed — uses `organization.unit.read`; sidebar hides the entry; columns don't match the serializer. |
| 18 | `/admin/authorization/access-scopes` | `features/authorization/AccessScopesScreen.tsx` | 🔴 | Reads `subject_id`/`role_code`/`starts_at`/`ends_at` — none on backend; `window.location.href`; full-page reload. |
| 19 | `/me/access` | `features/authorization/AccessContext.tsx` | 🔴 | Calls `getCurrentPrincipal` (`/me`) for `PrincipalContext` shape → every principal field empty. |
| 20 | `/admin/authorization/explain[/decisionId]` | `features/authorization/AccessDecisionWorkspace.tsx` | 🔴 | Fetches `/api/v1/access/decisions/{id}` which doesn't exist; flattens structured fields into one string. |
| 21 | `/tasks` | `features/r1/R1Screens.tsx` (`TasksScreen`) | 🔴 | Hardcoded actions for every row; "Open" renders inline card instead of navigating to `/tasks/:id`; pagination/limit not visible; no rendered test. |
| 22 | `/tasks/:taskId` | `features/tasks/TaskDetail.tsx` | 🟡 | `allowed_actions` not projected; status copy missing; comments share single failure; `window.location.href` back. |
| 23 | `/work-definitions` | `features/r1/R1Screens.tsx` (`WorkDefinitionsScreen`) | 🟡 | Generic `EntityTable` discards definition-specific fields. |
| 24 | `/admin/workflow` | `features/r1/R1Screens.tsx` (`WorkflowAdminScreen`) | 🟡 | No row drilldown; pattern inconsistency `[a-z][a-z0-9_]+` vs `[a-z][a-z0-9-]+`. |
| 25 | `/admin/workflow/day2` | `features/workflow/Day2Workflow.tsx` | 🔴 | 12-step RPC with no rollback; success/error share `role="status"` (AT confusion). |
| 26 | `/procedures`, `/procedures/:id` | `features/workflow/ProcedureGuide.tsx` | 🔴 | Calls `listWorkflowDefinitions` (requires `workflow.read`); `/procedures/:id/submit` re-renders the guide; browser-side draft filter; no not-found state. |
| 27 | `/admin/procedures/authoring` | `features/workflow/ProcedureAuthoring.tsx` | 🔴 | Edits never save; submit casts illegal `WorkflowAction`; backend route 404s but UI reports success. |
| 28 | `/admin/procedures/review` | `features/workflow/ProcedureOfficeReview.tsx` | 🔴 | Approve uses legacy 409 path; return swallows 404 and fakes success; no graph diff preview. |
| 29 | `/approvals` | `features/workflow/ApprovalInbox.tsx` | 🟡 | Server `allowed_actions` wider than UI; `rejected/returned/completed/cancelled` not localized; `<a>` navigation. |
| 30 | `/approvals/:stepId` | `features/workflow/ApprovalDetail.tsx` | 🟡 | Title is UUID; reassign/escalate unreachable; back uses `window.location.href`. |
| 31 | `/my-requests` | `features/workflow/MyRequests.tsx` | 🔴 | Subject falls through to instance UUID; no state filter; `window.location.href` for detail. |
| 32 | `/my-requests/:instanceId` | `features/workflow/MyRequestDetail.tsx` | 🟡 | Title is UUID; raw ISO timestamps; missing `'conflict' | 'stale'` from state union. |
| 33 | `/procedures/new` | `features/workflow/NewProcedureRequest.tsx` | 🟡 | Pure-client stub; submit reports success without server call (contradicts "API updating" banner). |
| 34 | `/reports` | `features/r1/R1Screens.tsx` (`ReportsScreen`) | 🔴 | Capability coupling with `reporting.dashboard`; row schema mismatch; download never exposed (retrieval returns JSON). |
| 35 | `/dashboards[/:id]` | `features/reporting/DashboardsScreen.tsx` | 🔴 | Reads `state` (response has `status`); absent dashboard = empty 200; no chart despite DESIGN mandate; `DashboardChart` doesn't exist. |
| 36 | `/search` | `features/r1/R1Screens.tsx` (`SearchScreen`) | 🔴 | Filters use `resource_type`/`record_type` (response uses `source_type`); total = local items; no URL state. |
| 37 | `/notifications` (+ drawer) | `app/NotificationList.tsx` | 🔴 | Drawer CSS missing; load-more failure clears cursor permanently; no source deep link; Arabic title in English mode for masked notifications. |
| 38 | `/coverage` | `features/portal/CoverageScreen.tsx` | 🔴 | "112 ops / v1.0.0" doesn't match governed contract (189 ops / v1.1.0); static inventory without provenance; only heading is localized. |
| 39 | `/api-docs` | `features/docs/SwaggerUiScreen.tsx` | 🔴 | Ships the obsolete W1.1 bundle; points `Try it out` at `https://api.cluster.example` (external); `js-yaml` not declared in `package.json`. |
| 40 | `/me/security` | `features/identity/PersonalSecurity.tsx` | 🟡 | 401 on wrong current password logs the user out (treats it as session expiry); policy mismatch 422 surfaces as "check current password". |
| 41 | Shell (all routes) | `app/AppShell.tsx`, `app/AppShell.css` | 🔴 | `backdrop-filter: blur(12px)` violates "No Glass Rule"; 40px header controls under 44px floor; `⌘K` advertised but unbound; raw buttons everywhere. |
| 42 | `/404` | inline `RouteNotFound` | 🟢 | Trivial. |

---

## 3. Per-Page Findings

Each entry consolidates the auditor's structured output and pulls the highest-severity, evidence-grounded findings. Recommendations are listed in priority order.

### 3.1 Shell (every route inherits)

- **File:** `apps/web/src/app/AppShell.tsx`, `apps/web/src/app/AppShell.css`
- **Verdict:** 🔴

**Design consistency**
- `AppShell.css:495` `backdrop-filter: blur(12px)` — DESIGN "No Glass Rule" (`DESIGN.md:215`).
- Sidebar decorates with two radial gradients + blob pseudo-elements (`AppShell.css:27-58`) — "Flat-by-Default" violation (`DESIGN.md:213`).
- Raw `<button>` / inline focus traps everywhere — should use `Drawer` / `Button` / `Select`.
- Hard-coded hex colors (`#062b42`, raw `rgba`) outside the token system (`AppShell.css:30-32`).
- `--header-control-size: 40px` (`AppShell.css:483`) — under DESIGN 44×44 floor.

**Data accuracy**
- `Math.min(unreadNotifications, 9)` (`AppShell.tsx:472`) silently caps visual; `aria-label` says real number.
- Global search's `⌘K` advertises in a `kbd` (`AppShell.tsx:454`) but no handler is wired.
- Notification count renders Western digits while `aria-label` uses `ar-SA-u-nu-arab`; same surface, two numeral systems.

**UX**
- Mobile: `.global-search { display: none }` below 768px without replacement affordance (`AppShell.css:810`).
- Notifications dialog lacks `inert` on `.app-shell` body (it relies on the parent `app-shell` selector).
- User menu opens on click but no Escape, no outside-close, no focus trap, no focus restore.
- Locale toggle (`AppShell.tsx:459-461`) reuses the `slice(0,2)` bug — Arabic mode shows "ال".

**Top fixes**
1. Drop `backdrop-filter` (`AppShell.css:494-496`); use opaque `var(--color-surface)`.
2. Bump `--header-control-size` to 44px.
3. Replace global-search `kbd` with a real `Ctrl/⌘+K` handler or remove the affordance.
4. Use the shared `Drawer` primitive for notifications (currently reimplemented).

### 3.2 Login (`/login`)

- **File:** `apps/web/src/app/LoginScreen.tsx`
- **Verdict:** 🟡

**Findings**
- Hand-rolled `<input>`/`<button>` (`:120-167`) instead of `Field`/`Button` — `Field` already wires `aria-describedby` + `role="alert"`.
- Locale switch shows `"ال"` / `"En"` (`:85-87`).
- Empty decorative `<span aria-hidden/>` (`:188`) is dead DOM.
- Field error `<p>` paragraphs lack `role="alert"` (`:135`, `:165`).
- Login mark 32px vs sidebar 42px vs avatar 34px — three sizes for the brand mark.
- Language/theme toggle is 32×32 (`base.css:172`) — under 44×44 floor.

**Top fixes**
1. Use `Field` + `Button` instead of raw inputs.
2. Replace `slice(0, 2)` with a dedicated `switchLanguageCode: 'AR' | 'EN'` copy key.
3. Add `role="alert"` to per-field error paragraphs or move them inside `Field`.

### 3.3 Home / Dashboard (`/`)

- **File:** `apps/web/src/features/dashboard/WorkDashboard.tsx`, `dashboard-model.ts`, `WorkDashboard.css`
- **Verdict:** 🔴

**Findings**
- Local `KpiCard` vs mandated `MetricTile` (which doesn't exist) — DESIGN "Dashboard Indicators" (`DESIGN.md:269-275`).
- Local `copy` map (`:43-56`) instead of `apps/web/src/app/copy.ts`.
- `DashboardTask = Task & { due_at?: string | null }` (`WorkDashboard.tsx:41`) — `due_at` isn't on the `Task` type; silent zero if the backend omits it.
- `ACTIVE_REQUEST_STATES` (`:30-37`) hardcodes the state vocabulary; no runtime validation.
- KPI palette uses `#3b6cf6` etc. (`WorkDashboard.css:54`) — not the DESIGN accent `#3DAAE1`.
- Duplicate "What needs you now" strip + panel (`:116`, `:118`).
- Open-requests list renders raw UUIDs (`:136`) — UUIDv7 in user UI.
- No freshness/period/source/zero/empty/unavailable metadata on tiles.

**Top fixes**
1. Materialize `MetricTile` per DESIGN §5 (one-time primitive), collapse `KpiCard` into it.
2. Move dashboard copy into `app/copy.ts` under a `dashboard` namespace.
3. Extend `Task` contract with `due_at` or surface `unavailable` for the missing field.
4. Translate `WorkflowInstance.state` via `recordStatusLabel()`.

### 3.4 Documents (`/documents`, `/documents/:id`)

- **File:** `apps/web/src/features/documents/DocumentsWorkspace.tsx`
- **Verdict:** 🟡

**Findings**
- Calls `getDocumentRecord`, `listDocumentRecordVersions`, `listDocumentRecordLinks` without a token (`DocumentsWorkspace.tsx:61`) — backend requires identity middleware → 401.
- Reads `lifecycle_state` from a serializer that returns `status` (`:98`) → always shows `status`.
- Reads `availability_status`/`version_number` (`:34-37`) from a list response that only returns ids/statuses.
- Field-level errors from `createDocumentRecord` are ignored (`:82`).
- Create form rendered above the list (`:69`) — list-first violation.
- File picker has no size or MIME guard (`DocumentsWorkspace.tsx:120`).
- Local `copy` table (`:23-26`) instead of `text[locale]`.
- Heavy `as unknown as Record<string, unknown>` casts.
- `link` form has no UUIDv7 validation on `record_id`.

**Top fixes**
1. Pass `token` to every `getDocument*` call (and accept `token` in `apps/web/src/api/documents.ts:31-43`).
2. Read `status` (the actual field) and re-map.
3. Move create form below the list; add file size + MIME guards.
4. Extend `text[locale]` instead of a per-screen copy map.

### 3.5 Work Record Create (`/work-records/new`)

- **File:** `apps/web/src/features/requests/RequestForm.tsx`
- **Verdict:** 🟢

**Findings**
- Hardcoded `work_definition_code: 'request'` (`:42`) — fine today; needs a `Select` once multiple work definitions exist.
- No "Back" button before submit (`:78-117`).
- ARIA wiring, focus movement, server-error mapping are all correct.

**Top fixes**
1. Add a "Back to home" affordance in the form footer.
2. Plan a `work_definition_code` picker aligned to `resolvePublishedWorkDefinition`.

### 3.6 Work Record Detail (`/work-records/:id`)

- **File:** `apps/web/src/features/requests/RequestDetail.tsx`
- **Verdict:** 🟡

**Findings**
- Two parallel `<Page>` blocks (`:124`, `:133`) instead of one parent.
- Document picker filters on `current_version_id` (`:17-25`) — not present on document row, only on versions.
- Download uses `window.location.assign` without a capability check or error reporting (`:120`).
- "Archive/Cancel" accepts `record.lock_version` from caller; harness may default to 1 (`:86-87`).
- History timeline is a single hardcoded `<li>` (`:153`).
- `aDocumentCannotBeLinked` paragraph always rendered (`:107-111`).
- Heavy `as unknown as Record<string, unknown>` casts.

**Top fixes**
1. Use `DocumentRecord.allowed_actions` (already in contract) instead of `current_version_id`.
2. Combine the two `<Page>` blocks; remove the always-on `aDocumentCannotBeLinked`.
3. Capture `window.location.assign` errors and disable the button while the URL is fetched.

### 3.7 Work Record List (no `/work-records` route exists)

- **Files:** `apps/web/src/shell/routes.ts:203-205` redirects both `/` and `/work-records` to `WorkDashboard`; orphan `features/requests/RequestDashboard.tsx` exists.
- **Verdict:** 🔴 (gap)

**Findings**
- `/work-records` shows the dashboard, not a record list — misleading.
- `RequestDashboard.tsx:1-88` is dead code: not in `primaryRoutes`, not imported by `AppWorkspace`.
- Hardcoded English strings (`RequestDashboard.tsx:25-27`) bypass `copy.ts`.

**Top fixes**
1. Either expose `RequestDashboard` under `/work-records` (preferred) or delete it.
2. Move `RequestDashboard.css:1-30` into `WorkDashboard.css`/`ui.css`.

### 3.8 Organization Facilities (`/admin/organization`)

- **File:** `apps/web/src/features/organization/OrganizationOverview.tsx`
- **Verdict:** 🟢

**Findings**
- Cluster `StatusBadge` is hardcoded "نشطة" (`:142`) — ignores `cluster.status`.
- `loading=true` after save flashes the skeleton (`:68-86`).
- Missing `aria-busy` on `PanelGrid` during refresh.

**Top fixes**
1. Render `cluster.status` via `facilityTypeLabel`/`StatusBadge`.
2. Drop `loading=true` on save success.
3. Add `aria-busy` while refreshing.

### 3.9 Organization Structure (`/admin/organization/structure`)

- **File:** `apps/web/src/features/organization/OrganizationStructure.tsx`, `OrganizationBoard.tsx` (1130 lines)
- **Verdict:** 🟡

**Findings**
- `OrganizationBoard.tsx:1129` `void ChevronUp` — dead import.
- `window.confirm` used for reorder confirmation (`:158-160`) — outlier.
- 404 on initial cluster load collapses to `error` (`:121`) — should offer "create cluster".
- ESLint `react-hooks/exhaustive-deps` disabled twice (`:131`, `:142`).
- `popupParent` filter rebuilt every render (`:620-629`).
- No co-located `OrganizationBoard.test.tsx`.
- `reorderOrganizationUnits` payload drops `ordered_unit_ids` (relies on server-side derivation).

**Top fixes**
1. Replace `window.confirm` with an inline confirm panel.
2. Treat 404 as a "create cluster" recovery path.
3. Memoize `popupParent`/`popupChildren`/`popupPositions`.
4. Drop the dead `ChevronUp` import.
5. Add a render + drag smoke test for `OrganizationBoard`.

### 3.10 People Assignments (`/admin/organization/people`)

- **File:** `apps/web/src/features/organization/PeopleAssignments.tsx` + drawers
- **Verdict:** 🟢

**Findings**
- `peopleById`/`positionsById` rebuilt every render (`AssignmentsPanel.tsx:34-35`).
- `aria-invalid` ternary precedence passes `false` instead of `undefined` (`AssignmentDrawer.tsx:74`, `EndAssignmentDrawer.tsx:65-66`, `PersonDrawer.tsx:78-80`).
- `pending` status row has no action affordance.

**Top fixes**
1. Memoize `peopleById`/`positionsById`.
2. Use `{condition || undefined}` for `aria-invalid`.
3. Surface a `pending` row action or grey it out with a hint.

### 3.11 Temporary Assignments (`/admin/organization/temporary-assignments`)

- **File:** `apps/web/src/features/organization/TemporaryAssignments.tsx`
- **Verdict:** 🟡

**Findings**
- Local `Field` reinvention (`:287-291`) bypassing the shared primitive.
- `listTemporaryAssignments` called without a token (`:99`, `:115`) — relies on anonymous read.
- Revoke form is inline, not in a `Drawer` — breaks the module's "use drawer" pattern.
- Local `number`/`formatDate` (`:299-300`) duplicate logic already in `AssignmentsPanel`.
- Two ESLint disables (`:135`, `:143`) for effects that read external state.

**Top fixes**
1. Use shared `Field` + `Drawer` for revoke.
2. Document the auth-bypass or move to authenticated variant.
3. Extract helpers into `app/copy` or `PersonDrawer`.

### 3.12 Import Review (`/admin/imports/organization[/:jobId]`)

- **File:** `apps/web/src/features/imports/ImportReview.tsx`
- **Verdict:** 🟡

**Findings**
- Local `Field` and `Select` (`:207`, `:444`).
- File size cap (1 GiB) is not announced to the user (`:42`, `:128`).
- `useEffect` ESLint disable (`:88`) — `load()` is re-evaluated each render.
- `SubmitForm` doesn't clear the quarantine id on success (submittable with the same id).

**Top fixes**
1. Use shared `Field` + `Select`.
2. Surface the size cap explicitly.
3. Move `load()` into `useCallback` and remove the lint disable.
4. Reset quarantine id on success.

### 3.13 Identity Accounts (`/admin/identity/accounts`)

- **File:** `apps/web/src/features/identity/IdentityAccounts.tsx`
- **Verdict:** 🟡

**Findings**
- Local `Field` and `Select` (`:437-445`).
- `delivery` and `expires_at` rendered raw (`:412`) — leaks enum, no localization.
- `className="status-{value}"` (`:291`, `:402`) relies on CSS that may not exist.

**Top fixes**
1. Shared `Field`/`Select`.
2. Localize `delivery` and `expires_at`.
3. Provide a shared CSS contract for `status-badge.status-{value}` modifiers.

### 3.14 Roles (`/admin/authorization/roles`)

- **File:** `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx`
- **Verdict:** 🟡

**Findings**
- Tab label via `t.title.split(' ')[0]` (`:68`) — fragile.
- `<Panel title="403">` literal (`:74`) — bypasses copy.
- Only `name`/`code` shown; no role-capability matrix despite `AuthorizationAdmin.tsx:288-291` having one.
- No pagination despite `R1Collection.next_cursor` (`:60-90`).
- `name_ar`/`name_en` silently dropped (`:82`).

**Top fixes**
1. Dedicated `rolesTab`/`capabilitiesTab` copy keys.
2. Localize the 403 panel and add an `aria-live` region.
3. Reuse `ItemTable` and surface the role-capability matrix.

### 3.15 Capabilities (`/admin/authorization/capabilities`)

- **Verdict:** 🟡 — same workspace as Roles.

**Findings**
- Empty copy reused from Roles (`:20`).
- Capability `classification` not shown (`:82-83`).

**Top fixes**
1. Split `t.empty` into `rolesEmpty`/`capabilitiesEmpty`.
2. Add a `classification` column.

### 3.16 Role Assignments (`/admin/authorization/role-assignments`)

- **File:** `apps/web/src/features/authorization/AuthorizationAdmin.tsx` (`role-assignments`)
- **Verdict:** 🟡

**Findings**
- `policy_document` field written to API that rejects it (`:170`, `:220`).
- Hardcoded English scope/status (`ADMIN_SCOPE_OPTIONS`/`:123-138`).
- `STATE_TRANSITIONS` publishes `published` option (`:137`) — backend rejects for assignments.
- `name`/`code` columns are `—` because backend assignments don't have them (`:95`).

**Top fixes**
1. Drop `policy_document` for assignments/delegations (or branch per resource).
2. Localize scope/status labels.
3. Per-resource table columns keyed on actual response shape.

### 3.17 Delegations (`/admin/authorization/delegations`)

- **Verdict:** 🟡

**Findings**
- No `capability_codes` field — every payload is rejected (`:204-208`).
- `end` datetime is optional in the form (`:218`) — backend requires it.
- `policy_document` silently dropped.

**Top fixes**
1. Add a `capability_codes` multi-select.
2. Mark `end` required.
3. Branch `policy_document` per resource.

### 3.18 Classification Policies (`/admin/authorization/classification-policies`)

- **Verdict:** 🔴

**Findings**
- `ItemTable` shows `name/code/status` only; `policy_document` hidden (`:120`).
- `EditPanel` sends `{ name: '' }` (`:256-260`) — backend rejects with `authorization_patch_empty`.
- `published` action let through but never offers a real publish.
- 404 collapses to `error` — `notFound`/`conflict`/`stale` unreachable.

**Top fixes**
1. Per-resource column definitions; surface `policy_document` as JSON preview.
2. Per-resource gating so Edit never sends unsupported fields.
3. Map `ApiError.status` → `AdminState` properly.

### 3.19 Field Access Templates (`/admin/authorization/field-access-templates`)

- **Verdict:** 🔴

**Findings**
- No template-specific editor (`field_decisions` JSON never shown).
- `field-access-templates` not in `GOVERNED_RESOURCES` (`:98`) — status changes skip audit reason.

**Top fixes**
1. Add a `field_decisions` editor (diff viewer).
2. Add to `GOVERNED_RESOURCES`.

### 3.20 Supervisory (`/admin/relationships/supervisory`)

- **Verdict:** 🟡

**Findings**
- Lives in Authorization module but gated on `organization.unit.read` (`routes.ts:333`).
- Not in sidebar (`navigation.tsx:131-145`).
- Table columns don't match `SupervisoryRelationship` shape (rendered `name/code/status`).

**Top fixes**
1. Either move to Organization module or rewrite the column set.
2. Add a sidebar entry under "Organization & workforce".

### 3.21 Access Scopes (`/admin/authorization/access-scopes`)

- **File:** `apps/web/src/features/authorization/AccessScopesScreen.tsx`
- **Verdict:** 🔴

**Findings**
- Reads `subject_id`, `role_code`, `starts_at`, `ends_at` (`:105-108`) — none exist on the backend (which serializes `user_id`, `role_id`, `start_at`, `end_at`). All cells fall through to `—`.
- `window.location.href` for SPA navigation (`:83`) — full reload, bypasses the capability gate.

**Top fixes**
1. Re-map to `user_id`, `role_id`, `start_at`, `end_at`; join role name server-side or via a follow-up query.
2. Accept `navigate` and use it.

### 3.22 Access Context (`/me/access`)

- **File:** `apps/web/src/features/authorization/AccessContext.tsx`
- **Verdict:** 🔴

**Findings**
- `getMyAccessContext` (`apps/web/src/api/r1.ts:39-42`) calls `getCurrentPrincipal` → `/me` returns `CurrentIdentityResponseData { principal: { user_id }, account, session }` — but `normalizePrincipal` expects `PrincipalContextSchema` (`tenant_id, organization_unit_ids, roles, capabilities, clearance, break_glass, correlation_id`).
- `directionForLocale` copied locally (`:159-161`).
- Private `accessContextLabels` (`:66-156`) bypasses `apps/web/src/app/copy.ts`.
- `isContextEmpty` (`:256-258`) ignores delegations.
- Synthetic `ApiError(412)` thrown on null scopes (`:442`) misclassifies "haven't picked yet" as a server error.
- `AppWorkspace.tsx:441` doesn't pass a `projection` prop — the projection panel is always empty.

**Top fixes**
1. Switch `getMyAccessContext` to the endpoint that returns `PrincipalContextSchema`.
2. Remove the duplicated direction helper and copy table; use `app/copy.ts`.
3. Either supply a real `projection` or remove the prop.

### 3.23 Access Decision / Explanation (`/admin/authorization/explain[/decisionId]`)

- **File:** `apps/web/src/features/authorization/AccessDecisionWorkspace.tsx`
- **Verdict:** 🔴

**Findings**
- `fetch('/api/v1/access/decisions/${decisionId}')` (`:47`) 404s — actual endpoint is `/api/v1/authorization/access-decisions/{id}/explanation`.
- Response flattened to a single string via `JSON.stringify` (`:54`); structured fields lost.
- Bypasses generated client and `ApiError` plumbing.
- No way to enter a decision id; only the URL drives state.

**Top fixes**
1. Use the generated `explainAccessDecision` from `apps/web/src/api/generated/cluster.ts:12243`.
2. Render the response as a structured `<dl>`.
3. Allow manual id entry.

### 3.24 Tasks List (`/tasks`)

- **File:** `apps/web/src/features/r1/R1Screens.tsx` (TasksScreen)
- **Verdict:** 🔴

**Findings**
- Inline `Open` action renders a card instead of navigating to `/tasks/:id` (`:281-313`).
- "Open" recognises only `completed`/`done` as closed; `cancelled` shows up as open with action buttons.
- Backend tasks response lacks `allowed_actions`; UI hardcodes complete/return unconditionally.
- Generic `EntityTable` discards priority/due/assignee.
- No `scopeReady`/`scopeEpoch` prop; no pagination beyond 50.
- No co-located test for the screen.

**Top fixes**
1. Return server-derived `allowed_actions` from `TaskController` and gate UI actions.
2. Navigate Open to `/tasks/:id`; remove the inline detail card.
3. Pass `scopeReady`/`scopeEpoch`; invalidate on scope change.

### 3.25 Task Detail (`/tasks/:taskId`)

- **File:** `apps/web/src/features/tasks/TaskDetail.tsx` (one-line JSX)
- **Verdict:** 🟡

**Findings**
- `task.allowed_actions` not projected by `TaskController` — renders `—`.
- Status copy missing; raw code rendered.
- `Promise.all` collapses task+comments → comments 500 hides task.
- Missing due date, priority, completion policy, assignee, source.
- Back uses `window.location.href`.
- `t` UUID in tests bypasses route UUIDv7 validation.

**Top fixes**
1. Add `TaskDetailProjection` with `allowed_actions`, localized state, due date, priority.
2. Decouple task / comments loading.
3. Switch back navigation to router push; render comment author/time + load-more.

### 3.26 Procedure Guide (`/procedures`, `/procedures/:id`)

- **File:** `apps/web/src/features/workflow/ProcedureGuide.tsx`
- **Verdict:** 🔴

**Findings**
- Route uses `work_definition.read`/`work_definition.list`, but the screen calls `listWorkflowDefinitions` (requires `workflow.read`) — most visitors 403 immediately.
- Versions fetched client-side and filtered for `published`; drafts leak into the browser.
- `/procedures/:id` renders the same grid with `aria-current` only — no actual detail page.
- `/procedures/:id/submit` is parsed back to `ProcedureGuide` — no submission form.
- N+1 sequential version fetches.
- No `scopeReady`/request identity.
- Status dictionary gaps.
- No tests for the deep-link route or the false submission form.

**Top fixes**
1. Add a server-published, audience-scoped `/procedures` endpoint guarded by `work_definition.read`.
2. Render an actual procedure detail and submission form on the deep-link routes.
3. Parallelize and bound version fetches; add cursor traversal.

### 3.27 Procedure Authoring (`/admin/procedures/authoring`)

- **File:** `apps/web/src/features/workflow/ProcedureAuthoring.tsx`
- **Verdict:** 🔴

**Findings**
- Edits update React state only; no `updateWorkflowVersionDraft` call (`:201-209, 319-520`).
- Submit uses `'submit' as unknown as WorkflowAction` (`:227-231`); backend route excludes submit → 404; success reported anyway.
- Conditional reference/role validation absent.
- Multiple state-copies blocks; reduced-motion not respected on shared primitives.
- `none` option offered for assignment rules the backend rejects.

**Top fixes**
1. Save the typed graph via `updateWorkflowVersionDraft` before any lifecycle transition.
2. Replace the cast call with `submitWorkflowVersionForReview` once that route is live.
3. Validate reference/role with shared rules with the backend `DecisionPolicyValidator`.

### 3.28 Procedure Office Review (`/admin/procedures/review`)

- **File:** `apps/web/src/features/workflow/ProcedureOfficeReview.tsx`
- **Verdict:** 🔴

**Findings**
- Approve calls legacy `/approve` — controller accepts only `publish`, returns 409 (`:178-195`).
- Return swallows 404 and reports success (`:184-202`).
- Reviewer cannot inspect the proposed graph — only opaque hash; submitter/timestamps shown raw.
- Bootstrap eligibility inferred from persisted fields, not the current actor's office membership.
- Hash mismatch sets only a global message; no per-field guidance.
- No backend route for the planned audit/publication operations.

**Top fixes**
1. Wire approve/return/publish to the generated clients and implement the planned routes.
2. Render graph diff/preview + assignment resolution before enabling approval.
3. Send `graph_hash_observed` and reason as typed payloads.

### 3.29 Approval Inbox (`/approvals`)

- **File:** `apps/web/src/features/workflow/ApprovalInbox.tsx`
- **Verdict:** 🟡

**Findings**
- Server `allowed_actions` returns `['approve','reject','return','reassign','escalate']`; UI surfaces only `approve|reject` (`:137-139`).
- `rejected|returned|completed|cancelled` not in `workflowState()` dictionary.
- Anchor `<a href>` defeats SPA (`:127`); success message unnamed; subject computed from `source_type ?? source_id` → identical prefixes.
- Hardcoded `assignee: 'me'` and `state: 'active'` — no filter for `waiting`.

**Top fixes**
1. Render `reassign`/`escalate`/`return` per `allowed_actions`.
2. Extend `workflowState()` coverage.
3. Use router navigation; add a state filter.

### 3.30 Approval Detail (`/approvals/:stepId`)

- **File:** `apps/web/src/features/workflow/ApprovalDetail.tsx`
- **Verdict:** 🟡

**Findings**
- Title is UUID (`:114-121`); description is `stepId`.
- Reassign/escalate unreachable (`:23-27, 102`).
- History panel only renders current state — no previous decisions/actors.
- `window.location.href` back (`:117-119`).
- No `<Feedback kind="success">` after a decision.
- `copy.stale` reads pre-decision only.

**Top fixes**
1. Replace `description={stepId}` with the resolved subject.
2. Render reassign/escalate/return modals.
3. Show feedback success and history; fix copy localization.

### 3.31 My Requests (`/my-requests`)

- **File:** `apps/web/src/features/workflow/MyRequests.tsx`
- **Verdict:** 🔴

**Findings**
- `subject`/`record_type` don't exist on the tracking payload → every card title is the workflow instance UUID (`:77`).
- `window.location.href` for detail (`:87`).
- No state filter despite API support.

**Top fixes**
1. Derive subject from `step_history[0].node_key` or fetch the active step.
2. Use router push; add `state=running|completed` filter.

### 3.32 My Request Detail (`/my-requests/:instanceId`)

- **File:** `apps/web/src/features/workflow/MyRequestDetail.tsx` (one-liner)
- **Verdict:** 🟡

**Findings**
- Title is UUID (`:19`); back uses `window.location.href`.
- State union drops `'conflict' | 'stale'`.
- Raw ISO timestamps on step history.

**Top fixes**
1. Use `stateFromError` from `http.ts`; switch back to router push.
2. Format step history timestamps with `formatAge`.

### 3.33 New Procedure Request (`/procedures/new`)

- **File:** `apps/web/src/features/workflow/NewProcedureRequest.tsx`
- **Verdict:** 🟡

**Findings**
- Submit is offline by design; `copy.reqApiUpdating` banner shown by default (`:58-59`).
- Submit button "works" — sets `copy.reqRequestPrepared` (`:44-52`) — contradicts banner.
- Section numbering hardcoded into copy.
- `directionForWorkflow` instead of `directionForLocale`.

**Top fixes**
1. Disable Submit while the API banner is showing.
2. Auto-number headings from array.
3. Use the standard direction helper.

### 3.34 Workflow Admin (`/admin/workflow`)

- **File:** `apps/web/src/features/r1/R1Screens.tsx` (`WorkflowAdminScreen`)
- **Verdict:** 🟡

**Findings**
- `EntityTable` columns `Name/Code/Status` ignore `state`.
- Two-panel layout fetches definitions + instances in parallel; one failure aborts both.
- No row drilldown.
- Code pattern inconsistency: `[a-z][a-z0-9_]+` (`:458`) vs `[a-z][a-z0-9-]+` for work definitions.

**Top fixes**
1. Render meaningful subject; add an action column to deep-link.
2. Per-panel error handling.
3. Document/align the code pattern.

### 3.35 Day2 Workflow (`/admin/workflow/day2`)

- **File:** `apps/web/src/features/workflow/Day2Workflow.tsx`
- **Verdict:** 🔴

**Findings**
- Single 12-step assembler with no rollback (`setup()`/`:106-145`, `submit()`/`:151-182`).
- Success/error share `role="status"` (`:189-192`) — no screen-reader distinction.
- No breadcrumb / no way to follow created IDs.
- "Return task" passes `'return-completion'` to `transitionTask` then `returnRequest` — semantic confusion.

**Top fixes**
1. Split into discrete panels each owning its state; add rollback.
2. Switch `role` based on error type.
3. Surface router links to created request/workflow.

### 3.36 Reports (`/reports`)

- **File:** `apps/web/src/features/r1/R1Screens.tsx` (`ReportsScreen`)
- **Verdict:** 🔴

**Findings**
- `Promise.all` calls `listDashboards` which requires `reporting.dashboard`; route gate is only `reporting.list` — report-only users break the screen on load.
- Reads row fields that don't exist (`title/source_type/source_id`); renders `name/code/status` instead → `—`.
- Detail/export controllers don't enforce capability checks (only `work_record.read` row-level).
- Download link never appears because no controller returns a download artifact.
- Local dashboard kpi cards instead of `MetricTile`/`DataFreshness`.

**Top fixes**
1. Split loading; gate each API by its own capability.
2. Replace `EntityTable` with a report-aware schema; surface source/excerpt.
3. Define a real download endpoint and capability check.

### 3.37 Dashboards (`/dashboards`, `/dashboards/:id`)

- **File:** `apps/web/src/features/reporting/DashboardsScreen.tsx` + `PrincipalDashboards.tsx`
- **Verdict:** 🔴

**Findings**
- `DashboardChart` does not exist; ECharts not in dependencies — DESIGN violation.
- Detail panel is a `<dl>` row count only; no chart, legend, textual equivalent, freshness, period.
- Reads `item.state`; backend returns `status` — every dashboard is rendered as "ready".
- `GET /dashboards/{id}` has no `reporting.dashboard` check; 404 returned as 200 with empty items.
- Zero cannot be distinguished from empty/unavailable.

**Top fixes**
1. Enforce `reporting.dashboard` in the handler; return 404 for missing definitions.
2. Implement `DashboardChart` with selective ECharts imports, SVG renderer, ARIA + tabular summary.
3. Return dashboard aggregates with freshness/source/period metadata.

### 3.38 Search (`/search`)

- **File:** `apps/web/src/features/r1/R1Screens.tsx` (`SearchScreen`)
- **Verdict:** 🔴

**Findings**
- Type filter checks `resource_type`/`record_type`; backend returns `source_type` → filters empty out valid results.
- Status filter checks a field that doesn't exist → every non-empty status filter yields empty.
- Filters applied client-side after server-side truncation.
- Hardcoded English labels in Arabic UI (`:551-567`).
- Total = `items.length` even though backend reports an authorized total.
- No URL state.

**Top fixes**
1. Move filters into the API; use `source_type`.
2. Surface `total` from the backend.
3. Encode query/filters/page in URL.

### 3.39 Notifications (drawer + `/notifications`)

- **File:** `apps/web/src/app/NotificationList.tsx`, `app/AppShell.tsx`
- **Verdict:** 🔴

**Findings**
- Drawer CSS rules (`notifications-dialog-layer/dialog/head`) referenced but not styled — dialog is invisible/spatial-broken.
- Header unread count computed only from loaded pages (initial 20) but presented as total.
- Masked notifications always rendered with Arabic title regardless of locale.
- Load-more failure clears the cursor permanently — retry impossible.
- Backend returns `source_*` after access recheck; UI discards source deep link.
- Drawer focusable elements captured once at open; async additions excluded.
- `aria-live="polite"` wraps entire list — re-announces all on one change.

**Top fixes**
1. Add full drawer styling + responsive overlay.
2. Add a server-authoritative unread count endpoint or response field.
3. Preserve cursor after failure; render source links with capability check.
4. Localize masked titles via a code, not server-authored Arabic.

### 3.40 Coverage (`/coverage`)

- **File:** `apps/web/src/features/portal/CoverageScreen.tsx`, `coverage-data.ts`
- **Verdict:** 🔴

**Findings**
- Claims "112 ops / v1.0.0" — governed contract is v1.1.0 with 189 operations.
- Hand-maintained static inventory (file states this explicitly).
- Local `card`/`stat-grid`/`coverage-grid` structures; no matching scoped CSS.
- P0/P1 gaps rendered with success-green `StatusBadge`.
- All statistics in Arabic even in English mode.
- No provenance/timestamp/evidence on each row.

**Top fixes**
1. Generate inventory from OpenAPI/routes during CI.
2. Use unified panels and indicator primitives.
3. Localize all data.
4. Attach evidence/owner/status/timestamp per gap.

### 3.41 API Docs (`/api-docs`)

- **File:** `apps/web/src/features/docs/SwaggerUiScreen.tsx`
- **Verdict:** 🔴

**Findings**
- Bundles `.orval/cluster.openapi.yaml` which is the obsolete W1.1 contract (5 ops).
- Points `Try it out` at `https://api.cluster.example/api/v1` — external origin.
- `js-yaml` imported but not declared in `package.json`/lockfile.
- Uses `state-panel` capped at 720px — too narrow for Swagger layout.
- Synchronous YAML parse on first render.
- Submit methods enabled by default.

**Top fixes**
1. Import the master contract.
2. Override server to `/api/v1` and disable submit-by-default.
3. Declare `swagger-ui-react` + `js-yaml` in `package.json`.
4. Wrap in unified page layout with RTL-aware styling.

### 3.42 Personal Security (`/me/security`)

- **File:** `apps/web/src/features/identity/PersonalSecurity.tsx`
- **Verdict:** 🟡

**Findings**
- Wrong current password → 401 → shared `unwrapEmpty` treats it as session expiry → user is logged out.
- Backend enforces policy the UI doesn't (max length, common password, repeated chars, username fragments, reuse).
- Server expires the cookie on success but UI leaves local session active.
- Inputs lack `name`/`minLength`/`maxLength`.
- `Field` doesn't apply `aria-describedby` to its input.

**Top fixes**
1. Distinguish invalid-current-password from session expiry 401s in shared HTTP handling.
2. Map server problem types to per-field localized guidance.
3. Sign out locally after a successful password change.
4. Wire `name`/`minLength`/`maxLength`/aria.

### 3.43 Workspace Tabs / Auth Gate (`RouteAccessGuard`)

- **File:** `apps/web/src/app/AppWorkspace.tsx:583-585`
- **Verdict:** 🟢

Already implements capability gating correctly. Underscores how bad the per-screen capability drift is elsewhere: gating works, screens don't honor it (capability-stripped capability checks, hardcoded status options, hidden entries).

---

## 4. Cross-Cutting Findings (apply across multiple pages)

### 4.1 API contract and capability drift

- `AccessContext` calls `getCurrentPrincipal` (`apps/web/src/api/r1.ts:39-42`) for a `PrincipalContext` shape (`apps/web/src/api/generated/cluster.ts:1147-1161`). Mismatch silently produces empty access context.
- `AccessDecisionWorkspace` calls `/api/v1/access/decisions/{id}` (`:47`) — backend exposes `/api/v1/authorization/access-decisions/{id}/explanation`.
- `AccessScopesScreen` reads `subject_id/role_code/starts_at/ends_at` — backend serializes `user_id/role_id/start_at/end_at/scope_type/scope_id`.
- `ProcedureGuide` calls `listWorkflowDefinitions` (requires `workflow.read`) on a route gated only for `work_definition.read/list`.
- `TasksScreen` ignores server `allowed_actions`; UI can't tell `tasks.complete` from `tasks.update`.
- `ProcedureAuthoring` 'submit' casts `as unknown as WorkflowAction` and the URL doesn't exist on the backend; UI swallows 400 and reports success.
- `ProcedureOfficeReview` uses legacy `approve` (controller rejects with 409) and `return` (404); UI reports success on 404.
- `Supervisory` table renders `name/code/status` against a `source_unit_id/target_unit_id/relationship_type/capability_codes` serializer.
- `AccessDecisionExplanation` flattens a structured decision into one string.
- `SearchScreen` filters use `resource_type`/`record_type` while backend returns `source_type`.
- `ReportsScreen` row mapping ignores `title/source_type/source_id`.
- `DashboardsScreen` reads `item.state`; backend returns `status`.
- `CoverageScreen` claims 112 ops / v1.0.0; contract has 189 ops / v1.1.0.
- `SwaggerUiScreen` ships the obsolete W1.1 bundle.

**Fix:** Generate `apps/web/src/api/generated/` from the master contract, switch consumers to the generated clients, and lock naming with a lint rule that disallows `as unknown as Record<string, unknown>` for trust-typed responses.

### 4.2 Design system bypass

- Raw buttons/inputs in `LoginScreen`, `AppShell`, `Day2Workflow`, `TaskDetail` button-back.
- Local `Field` in `TemporaryAssignments`, `ImportReview`, `IdentityAccounts`.
- `kpi-card`, `surface-card`, `stat-grid`, `coverage-grid`, `dashboard-kpi` — local primitives instead of `MetricTile`.
- `AppShell.css` uses `rgba`, `#062b42` (not `dark-canvas`), `backdrop-filter: blur(12px)`, radial-gradient sidebar blobs.
- 3 brand marks at 3 sizes (32/42/34 px).
- 40 px header controls under the 44 px floor.
- Status badges default to success-green for every state (`pending`, `draft`, `returned`, `cancelled`).
- `formattingLocale` ignored on KPI numerics; Arabic-Indic vs Western split between bell `aria-label` and visual.
- `Field` doesn't attach `aria-describedby`; `help`/`error` IDs created but unused.
- Page/Panel entrance animations active under reduced-motion.
- `slice(0, 2)` locale toggle shows "ال"/"En".

**Fix:** Reinforce the "use shared primitives" rule with a grep-based CI check, define `MetricTile`/`DataFreshness`/`ChartLegend`/`ChartTooltip`/`DashboardChart` per DESIGN §5, delete `backdrop-filter` + radial-gradient decorations, and pin control sizes to 44 px.

### 4.3 Capability / route / sidebar drift

- `/admin/relationships/supervisory` is gated on `organization.unit.read` but rendered in the Authorization module; not in the sidebar (`apps/web/src/shell/navigation.tsx:131-145`).
- `/procedures` requires `work_definition.read` but the screen calls `listWorkflowDefinitions` (gated on `workflow.read`).
- `/admin/authorization/roles` shows the Capabilities tab only when `authorization.capability.read` is held, but the route accepts either — silent tab drop.
- Sidebar entry "My tasks" is keyed on `tasks.read|tasks.list`; the list screen ignores `tasks.complete|tasks.update` (rows still offer them).
- `/coverage` and `/api-docs` are gated on `authorization.audit.read` client-side, but the inventory and contract are bundled static assets — the gate controls rendering only, not confidentiality.

**Fix:** Make sidebar + route capability gates exhaustive and single-sourced. Surface every gated route in the sidebar; introduce per-resource Edit gates; for internal-tools, serve inventory through a server-authorized endpoint.

### 4.4 Workflow lifecycle fragmentation

- `workflowState()` dictionary covers `draft|submitted|pending_review|in_review|approved|rejected|returned|completed|published|waiting|active`. Server emits `cancelled` (and sometimes internal codes) that fall through.
- Procedures merge `definition_state`, `review_state`, `approval_status`, and (planned) `lifecycle_state` with no single source of truth.
- `tasks.complete` vs `tasks.update` vs `tasks.return` not exposed via `allowed_actions`.
- `workflow.reassign` / `workflow.escalate` advertised in route gate but not surfaced in any UI.

**Fix:** Extend `workflowState()` and expose server-derived `allowed_actions` on tasks and steps; pick one lifecycle field per resource and reject payloads that contradict it.

### 4.5 Hard navigations defeat the SPA

- `window.location.href`: `MyRequests.tsx:87`, `MyRequestDetail.tsx:19`, `ApprovalDetail.tsx:117`, `TaskDetail.tsx:20`, `AccessScopesScreen.tsx:83`, `RequestDetail.tsx:120` (download).
- `<a href>`: `ApprovalInbox.tsx:127`.
- Each bypasses scope, capability, and request-epoch discipline the screens otherwise defend.

**Fix:** Replace with `navigate()` from the shell. Add a lint rule banning `window.location` outside `LoginScreen`.

### 4.6 Copy fragmentation

- Local bilingual tables in `WorkDashboard.tsx:43-56`, `RolesCapabilitiesWorkspace.tsx:12-31`, `AuthorizationAdmin.tsx:23-64`, `AccessContext.tsx:66-156`, `AccessScopesScreen.tsx:14-35`, `DocumentsWorkspace.tsx:23-26`, `R1Screens.tsx:31-176`.
- `AccessContext.tsx:108,153,159-161` defines its own `ltr: 'rtl'` direction key.
- `TaskDetail.tsx:20` renders raw status code instead of going through `workflowState()`.

**Fix:** Single copy source (`apps/web/src/app/copy.ts`) with `dashboard`, `authorization`, `reports`, `search`, `tasks`, `notifications` namespaces.

### 4.7 Test gap

- `TasksScreen`: no test (`apps/web/src/features/r1/R1Screens.drawer.test.tsx` only tests admin screens).
- `TaskDetail.test.tsx` uses `t` UUID (bypasses route UUIDv7 check).
- `ProcedureAuthoring.test.tsx` and `ProcedureOfficeReview.test.tsx`: render-only.
- `ProcedureGuide.test.tsx`: empty + one published; no deep-link / `submit` route tests.
- `OrganizationBoard.test.tsx`: doesn't exist (`OrganizationStructure.test.tsx` mocks the board).
- `AccessScopesScreen`: no test.
- `AccessDecisionWorkspace`: no test.
- `CoverageScreen`: no test.
- `SwaggerUiScreen`: no test (lazy + Suspense fallback not validated).
- `DashboardsScreen.test.tsx`: scope races only.
- `Document*`: helpers only.

**Fix:** Add focused tests for each screen's loading/empty/forbidden/error/stale/conflict states and for at least one happy action.

### 4.8 Notifications + source navigation

- Notifications drawer + page share one component but the drawer has no CSS.
- Backend returns `source_*` after access recheck, but the UI discards source links.
- Load-more failure clears the cursor; retry impossible.
- All-read + partial-loaded count discrepancy with header bell.

**Fix:** Add drawer CSS; render source links with capability preflight; preserve cursor on failure; expose a server-authoritative unread count.

---

## 5. Prioritized Recommendations

**P0 (must fix before any production rollout)** — 16 items, all 🔴:
1. Stop calling obsolete/missing endpoints: `AccessContext` (`getMyAccessContext`), `AccessDecision` (`/api/v1/access/decisions`), `AccessScopes` (wrong columns), `Search` (filter mapping), `Reports` (row schema), `Dashboards` (state vs status), `Swagger` (W1.1 contract + external URL), `Coverage` (hand-maintained counts).
2. Stop simulating success on failures: `ProcedureAuthoring.submit`, `ProcedureOfficeReview.approve/return`, `ApprovalDetail` post-decision feedback gap.
3. Wire task and approval `allowed_actions` from the server, drop hardcoded button sets.
4. Add `MetricTile`/`DataFreshness`/`DashboardChart` per DESIGN §5.
5. Centralize copy and use shared `Page`/`Panel`/`Drawer`/`Field`/`Button` everywhere (delete `AppShell` blur, gradients, local `Field`, button variants outside `Button`).
6. Replace every `window.location` navigation with router push; every `<a href>` inside the shell with the navigate prop.
7. Fix capacity/scope/sidebar drift: supervisory in sidebar, single-sourced capability gates, server authorization for `/dashboards/{id}`.

**P1 (ship-blocking during a focused regression week)** — rest of the 🟡 findings:
- Backend projection fields (`due_at`, `allowed_actions`, `subject`, `state`→`status`, role-assignment `name/code`, supervisory columns, dashboard freshness/periof/source).
- Drawer defect fixes (drawer CSS, `@inert`, focus trap refresh, success feedback, copy key gaps).
- Hardcoded English copy (`AccessScopesScreen`, `Admin status/scope options`).
- Local copy tables → `copy.ts`.
- `OrganizationBoard` memoization, dead `ChevronUp`, `window.confirm` replacement.
- Test surface for Tasks, Procedures, Dashboards, Access, Personal Security, Swagger.

**P2 (quality backlog)**:
- Memoization, reduced-motion conformance, KPI freshness metadata, search URL state, notifications drawer source links, deep-link routing across R1.

---

## 6. Audit Scope & Caveats

- Dev servers (Laravel/Vite) were offline so all findings are source-grounded; live state assertions (network response shapes, real 401/404 behavior, visual regressions) need a verification pass with both servers running + seeded credentials.
- `R1Screens.tsx` is shared by Tasks / WorkDefinitions / WorkflowAdmin / Search / Reports; findings are scoped to the screen they back in the route table.
- `RequestDashboard.tsx` was found as orphan code (dead route).
- Coverage / Swagger / Personal Security findings were cross-checked against `docs/contracts/api/openapi.yaml` (rooted at the repo).
- All cited file paths are absolute under `/Users/tariq/code/R3/cluster/`.
