import { createBrowserRouter, Outlet, useParams } from 'react-router-dom'
import type { Session } from './api/session'
import type { Locale } from './i18n'
import { SessionProvider } from './app/session-context'
import { PrincipalProvider } from './app/principal-context'
import { AppShell } from './app/AppShell'

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
import { AccessContextScreen } from './features/identity/AccessContextScreen'
import { AuditScreen } from './features/audit/AuditScreen'
import { ReportsScreen } from './features/reports/ReportsScreen'
import { DashboardsScreen } from './features/reports/DashboardsScreen'
import { ApiDocsScreen } from './features/api-docs/ApiDocsScreen'
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

interface RouterConfig {
  session: Session
  locale: Locale
  setLocale: (locale: Locale) => void
  onLogout: () => void
}

export function router({ session, locale, setLocale, onLogout }: RouterConfig) {
  return createBrowserRouter([
    {
      element: (
        <SessionProvider session={session} locale={locale} setLocale={setLocale}>
          <PrincipalProvider>
            <AppShell onLogout={onLogout} />
          </PrincipalProvider>
        </SessionProvider>
      ),
      children: [
        { path: '/', element: <HomeDashboard /> },
        { path: '/tasks', element: <TasksScreen /> },
        { path: '/tasks/new', element: <TaskCreateScreen /> },
        { path: '/tasks/:taskId', element: <TaskDetailRoute /> },
        { path: '/documents', element: <DocumentsScreen /> },
        { path: '/documents/:documentId', element: <DocumentDetailRoute /> },
        { path: '/organization', element: <OrganizationScreen /> },
        { path: '/accounts-permissions', element: <AccountsPermissionsScreen /> },
        { path: '/reports-monitoring', element: <ReportsMonitoringScreen /> },
        { path: '/platform-management', element: <PlatformManagementScreen /> },
        { path: '/notifications', element: <NotificationsScreen /> },
        { path: '/search', element: <SearchScreen /> },
        { path: '/me/security', element: <PersonalSecurityScreen /> },
        { path: '/me/access', element: <AccessContextScreen /> },
        { path: '/audit', element: <AuditScreen /> },
        { path: '/reports', element: <ReportsScreen /> },
        { path: '/dashboards', element: <DashboardsScreen /> },
        { path: '/api-docs', element: <ApiDocsScreen /> },
        { path: '/imports', element: <ImportReviewRoute /> },
        { path: '/imports/:jobId', element: <ImportReviewRoute /> },
        { path: '*', element: <NotFoundScreen /> },
      ],
    },
  ])
}

function NotFoundScreen() {
  return <div className="state-panel">404</div>
}

export { Outlet }
