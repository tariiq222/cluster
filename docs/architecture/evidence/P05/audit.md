# Cluster Accessibility Audit (P05 Phase A)
#
> Source-grounded inventory of every stable Cluster web route, every shared
> primitive, and every stylesheet that affects the user-facing surface. This
> audit is read-only; no source, contract, or runtime change.
>
> Approved plan: docs/superpowers/plans/2026-07-26-cluster-accessibility-wcag.md
> Source commit: df2588c

version: 1
baseline_date: '2026-07-27'
source_commit: df2588c
plan: docs/superpowers/plans/2026-07-26-cluster-accessibility-wcag.md
target: WCAG 2.2 Level AA

# ----------------------------------------------------------------------------
# 1. Stable route inventory
# ----------------------------------------------------------------------------

routes:
  - name: login
    path: /login
    file: apps/web/src/app/LoginScreen.tsx
    locale_ar: yes
    locale_en: yes
    notes: focus moves to error summary on failure
  - name: dashboard
    path: /dashboards
    file: apps/web/src/features/dashboard/WorkDashboard.tsx
    notes: skeleton, status, alert, button KPIs, live values
  - name: work-record-detail
    path: /work-records/{recordId}
    file: apps/web/src/features/work-records/DetailScreen.tsx
  - name: document-detail
    path: /documents/{documentId}
    file: apps/web/src/features/documents/DocumentsWorkspace.tsx
  - name: task-detail
    path: /tasks/{taskId}
  - name: organization-overview
    path: /admin/organization
    file: apps/web/src/features/organization/OrganizationOverview.tsx
  - name: organization-board
    path: /admin/organization/board
    file: apps/web/src/features/organization/OrganizationBoard.tsx
    notes: pointer-driven canvas; SC 2.5.7 dragging alternatives
  - name: organization-import
    path: /admin/imports/organization
    file: apps/web/src/features/imports/ImportReview.tsx
  - name: authorization
    path: /admin/authorization/{resource}
    file: apps/web/src/features/authorization/AuthorizationAdmin.tsx
  - name: access-explanation
    path: /admin/authorization/explain/{decisionId}
  - name: platform-settings
    path: /admin/platform
    file: apps/web/src/features/platform-settings/BusinessCalendarsScreen.tsx
  - name: api-docs
    path: /api-docs
    file: apps/web/src/features/docs/SwaggerUiScreen.tsx
    notes: third-party swagger-ui stylesheet
  - name: workspace-tabs
    path: /workspace
    file: apps/web/src/app/WorkspaceTabs.tsx
  - name: procedure-list
    path: /procedures
  - name: approval-detail
    path: /approvals/{stepId}
  - name: my-request-detail
    path: /my-requests/{instanceId}

# ----------------------------------------------------------------------------
# 2. Shared primitives
# ----------------------------------------------------------------------------

primitives:
  - name: Drawer
    file: apps/web/src/ui/Drawer.tsx
    notes: focus trap and restore; dialog semantics
  - name: Select
    file: apps/web/src/ui/Select.tsx
    notes: combobox/listbox; needs audit on open, filtered, empty, disabled, RTL, error-adjacent
  - name: Field
    file: apps/web/src/ui/Field.tsx
    notes: label/help/error IDs; association depends on child aria-describedby
  - name: Page
    file: apps/web/src/ui/Page.tsx
    notes: page, header, section, panel heading
  - name: Feedback
    file: apps/web/src/ui/Feedback.tsx
    notes: empty, alert, skeleton; status is not communicated by color alone
  - name: DashboardChart
    file: apps/web/src/charts/DashboardChart.tsx
    notes: canvas with caption; equivalent data and trends must be available in text

# ----------------------------------------------------------------------------
# 3. Stylesheet inventory
# ----------------------------------------------------------------------------

stylesheets:
  - apps/web/src/styles/tokens.css
  - apps/web/src/styles/base.css
  - apps/web/src/styles/screens.css
  - apps/web/src/ui/ui.css
  - apps/web/src/app/AppShell.css
  - apps/web/src/app/WorkspaceTabs.css
  - apps/web/src/features/dashboard/WorkDashboard.css
  - apps/web/src/features/organization/organization.css
  - apps/web/src/features/organization/board.css
  - apps/web/src/features/organization/organization-overview.css
  - apps/web/src/features/platform-settings/platform-settings.css
  - apps/web/src/features/requests/RequestDashboard.css
  - swagger-ui-react/swagger-ui.css (third-party)

# ----------------------------------------------------------------------------
# 4. Existing automated coverage
# ----------------------------------------------------------------------------

coverage:
  - apps/web/src/app/AppShell.test.tsx (mobile navigation focus, role="menu")
  - apps/web/e2e/shell.spec.ts (focus restoration, Escape)
  - apps/web/src/features/organization/OrganizationDrawers.test.tsx
  - apps/web/src/features/documents/DocumentsWorkspace.test.tsx
  - apps/web/src/features/imports/ImportReview.test.tsx
  - apps/web/src/features/authorization/AccessContext.test.tsx
  - apps/web/src/features/authorization/AccessScopesScreen.test.tsx
  - apps/web/src/features/authorization/AuthorizationAdmin.test.tsx
  missing: no axe integration; no @testing-library/user-event

# ----------------------------------------------------------------------------
# 5. Tools gap
# ----------------------------------------------------------------------------

tooling_gap:
  needed:
    - @axe-core/playwright
    - vitest-axe
    - @testing-library/user-event
  package_status: blocked; P05 needs the package integration token before
    touching apps/web/package.json or package-lock.json

# ----------------------------------------------------------------------------
# 6. Pending findings (placeholder; not yet A11Y-###)
# ----------------------------------------------------------------------------

pending_findings:
  - candidate_id: A11Y-001
    title: AppWorkspaceShell History API route change has no focus target
    source: apps/web/src/app/AppWorkspaceShell.tsx:57-66
    severity: investigate
  - candidate_id: A11Y-002
    title: AppShell user menu disclosure pattern needs audit
    source: apps/web/src/app/AppShell.tsx:481-515
    severity: investigate
  - candidate_id: A11Y-003
    title: Select rendered combobox/listbox states
    source: apps/web/src/ui/Select.tsx
    severity: investigate
  - candidate_id: A11Y-004
    title: OrganizationBoard dragging alternative
    source: apps/web/src/features/organization/OrganizationBoard.tsx:611-691
    severity: investigate
  - candidate_id: A11Y-005
    title: DashboardChart canvas equivalent text
    source: apps/web/src/charts/DashboardChart.tsx:53-57
    severity: investigate
  - candidate_id: A11Y-006
    title: Swagger UI stylesheet
    source: apps/web/src/features/docs/SwaggerUiScreen.tsx:4
    severity: investigate

# ----------------------------------------------------------------------------
# 7. Approval
# ----------------------------------------------------------------------------

approval:
  decision: pending-user-authorization
  recorded_in: docs/architecture/evidence/P05/audit.md
  not_authorized:
    - Phase B test/evidence harness
    - Phase C surface remediation
    - Phase D production browser evidence
    - Phase E final conformance decision
    - Adding dependencies to apps/web/package.json
    - Editing apps/web/src/ui/*
    - Editing apps/web/src/app/AppShell.tsx
