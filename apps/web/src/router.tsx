import { useMemo } from 'react'
import { createBrowserRouter, Navigate, RouterProvider, useParams } from 'react-router-dom'
import { AppShell } from './app/AppShell'
import { usePrincipal } from './app/principal-context'

import { HomeDashboard } from './features/dashboard/HomeDashboard'
import { TasksScreen } from './features/tasks/TasksScreen'
import { TaskDetailScreen } from './features/tasks/TaskDetailScreen'
import { TaskCreateScreen } from './features/tasks/TaskCreateScreen'
import { DocumentsScreen } from './features/documents/DocumentsScreen'
import { DocumentDetailScreen } from './features/documents/DocumentDetailScreen'
import { OrganizationScreen } from './features/organization/OrganizationScreen'
import { AccountsPermissionsScreen } from './features/accounts/AccountsPermissionsScreen'
import { ReportsMonitoringScreen } from './features/reports/ReportsMonitoringScreen'
import { PlatformManagementScreen } from './features/platform/PlatformManagementScreen'
import { NotificationsScreen } from './features/notifications/NotificationsScreen'
import { SearchScreen } from './features/search/SearchScreen'
import { PersonalSecurityScreen } from './features/identity/PersonalSecurityScreen'
import { ImportReviewScreen } from './features/imports/ImportReviewScreen'

function TaskDetailRoute() {
  const { taskId } = useParams()
  return taskId ? <TaskDetailScreen taskId={taskId} /> : null
}

function DocumentDetailRoute() {
  const { documentId } = useParams()
  return documentId ? <DocumentDetailScreen documentId={documentId} /> : null
}

function ImportReviewRoute() {
  const { jobId } = useParams()
  return <ImportReviewScreen jobId={jobId} />
}

function NotFoundScreen() {
  return <div className="state-panel">404</div>
}

function WorkspacePlaceholder({ title }: { title: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <p className="text-foreground font-medium">{title}</p>
      <p className="text-muted-foreground text-sm">قيد التطوير · Under construction</p>
    </div>
  )
}

/*
 * Feature-gated paths are registered ONLY when the flag is on. With the flag
 * off the API answers a non-disclosing 404, so the route must not exist at
 * all — an unregistered route cannot leak a loading skeleton.
 */
function workManagementRoutes() {
  return [
    { path: '/work-records', element: <WorkspacePlaceholder title="سجلات العمل · Work Records" /> },
    { path: '/work-records/:recordId', element: <WorkspacePlaceholder title="سجل العمل · Work Record" /> },
    { path: '/inbox', element: <WorkspacePlaceholder title="صندوق الموافقات · Approvals Inbox" /> },
    { path: '/workflow', element: <WorkspacePlaceholder title="سير العمل · Workflow" /> },
    { path: '/work-definitions', element: <WorkspacePlaceholder title="نماذج العمل · Work Definitions" /> },
  ]
}

const ROUTES = [
  { path: '/', element: <HomeDashboard /> },
  { path: '/tasks', element: <TasksScreen /> },
  { path: '/tasks/new', element: <TaskCreateScreen /> },
  { path: '/tasks/:taskId', element: <TaskDetailRoute /> },
  { path: '/documents', element: <DocumentsScreen /> },
  { path: '/documents/:documentId', element: <DocumentDetailRoute /> },
  { path: '/organization', element: <OrganizationScreen /> },
  { path: '/organization/import', element: <ImportReviewRoute /> },
  { path: '/organization/import/:jobId', element: <ImportReviewRoute /> },
  { path: '/access', element: <AccountsPermissionsScreen /> },
  { path: '/reports', element: <ReportsMonitoringScreen /> },
  { path: '/platform', element: <PlatformManagementScreen /> },
  { path: '/notifications', element: <NotificationsScreen /> },
  { path: '/search', element: <SearchScreen /> },
  { path: '/me', element: <PersonalSecurityScreen /> },
  { path: '/login', element: <Navigate to="/" replace /> },
]

/*
 * Retired paths redirect so bookmarks and existing journeys keep working.
 * They are compatibility shims, not destinations — routePaths() omits them.
 */
const REDIRECTS = [
  { path: '/accounts-permissions', element: <Navigate to="/access" replace /> },
  { path: '/reports-monitoring', element: <Navigate to="/reports" replace /> },
  { path: '/platform-management', element: <Navigate to="/platform" replace /> },
  { path: '/audit', element: <Navigate to="/reports" replace /> },
  { path: '/dashboards', element: <Navigate to="/reports" replace /> },
  { path: '/imports', element: <Navigate to="/organization/import" replace /> },
  { path: '/imports/:jobId', element: <Navigate to="/organization/import/:jobId" replace /> },
  { path: '/me/security', element: <Navigate to="/me" replace /> },
  { path: '/me/access', element: <Navigate to="/me" replace /> },
]

export function routePaths(): string[] {
  return ROUTES.map((route) => route.path)
}

interface RouterConfig {
  features: { work_management: boolean; tasks: boolean }
  onLogout: () => void
}

export function router({ features, onLogout }: RouterConfig) {
  const children = [
    ...ROUTES,
    ...REDIRECTS,
    ...(features.work_management ? workManagementRoutes() : []),
    { path: '*', element: <NotFoundScreen /> },
  ]
  return createBrowserRouter([
    {
      element: <AppShell onLogout={onLogout} />,
      children,
    },
  ])
}

/*
 * The router is built only after the principal resolves: features come from
 * /me, and feature-gated routes must be absent — not disabled — while the
 * flag is off. Returning null until then leaks no skeleton.
 */
export function AppRouter({ onLogout }: { onLogout: () => void }) {
  const { features } = usePrincipal()
  const workManagement = features?.work_management ?? false
  const tasks = features?.tasks ?? false
  const instance = useMemo(
    () => router({ features: { work_management: workManagement, tasks }, onLogout }),
    [workManagement, tasks, onLogout],
  )
  if (!features) return null
  return <RouterProvider router={instance} />
}
