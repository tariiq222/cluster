import { usePrincipal } from './principal-context'
import { useLocale } from './session-context'
import { shellCopy } from '../i18n'
import type { AppRoute } from '../routes'
import { NavigationProvider, useNavigate } from './navigation-context'
import { StateGate } from '../ui'
import { HomeDashboard } from '../features/dashboard/HomeDashboard'
import { TasksScreen } from '../features/tasks/TasksScreen'
import { TaskDetailScreen } from '../features/tasks/TaskDetailScreen'
import { TaskCreateScreen } from '../features/tasks/TaskCreateScreen'
import { DocumentsScreen } from '../features/documents/DocumentsScreen'
import { DocumentDetailScreen } from '../features/documents/DocumentDetailScreen'
import { OrganizationScreen } from '../features/organization/OrganizationScreen'
import { AccountsPermissionsScreen } from '../features/accounts/AccountsPermissionsScreen'
import { ReportsMonitoringScreen } from '../features/reports/ReportsMonitoringScreen'
import { PlatformManagementScreen } from '../features/platform/PlatformManagementScreen'
import { NotificationsScreen } from '../features/notifications/NotificationsScreen'
import { SearchScreen } from '../features/search/SearchScreen'
import { PersonalSecurityScreen } from '../features/identity/PersonalSecurityScreen'
import { AccessContextScreen } from '../features/identity/AccessContextScreen'
import { AuditScreen } from '../features/audit/AuditScreen'
import { ReportsScreen } from '../features/reports/ReportsScreen'
import { DashboardsScreen } from '../features/reports/DashboardsScreen'
import { ApiDocsScreen } from '../features/api-docs/ApiDocsScreen'

export function WorkspaceContent({ route, navigate }: { route: AppRoute; navigate: (path: string) => void }) {
  const locale = useLocale()
  const principal = usePrincipal()

  return (
    <NavigationProvider navigate={navigate}>
      <StateGate state={principal.state} locale={locale}>
        {renderRoute(route)}
      </StateGate>
    </NavigationProvider>
  )
}

function renderRoute(route: AppRoute) {
  switch (route.name) {
    case 'home':
      return <HomeDashboard />
    case 'tasks':
      return <TasksScreen />
    case 'task-detail':
      return <TaskDetailScreen taskId={route.taskId} />
    case 'task-create':
      return <TaskCreateScreen />
    case 'documents':
      return <DocumentsScreen />
    case 'document-detail':
      return <DocumentDetailScreen documentId={route.documentId} />
    case 'organization':
      return <OrganizationScreen />
    case 'accounts-permissions':
      return <AccountsPermissionsScreen />
    case 'reports-monitoring':
      return <ReportsMonitoringScreen />
    case 'platform-management':
      return <PlatformManagementScreen />
    case 'notifications':
      return <NotificationsScreen />
    case 'search':
      return <SearchScreen />
    case 'personal-security':
      return <PersonalSecurityScreen />
    case 'access-context':
      return <AccessContextScreen />
    case 'audit':
      return <AuditScreen />
    case 'reports':
      return <ReportsScreen />
    case 'dashboards':
      return <DashboardsScreen />
    case 'api-docs':
      return <ApiDocsScreen />
    default:
      return <NotFoundScreen />
  }
}

function NotFoundScreen() {
  const locale = useLocale()
  const navigate = useNavigate()
  const copy = shellCopy[locale]
  return (
    <div className="state-panel">
      <p>{copy.notFound}</p>
      <button type="button" className="button button--primary" onClick={() => navigate('/')}>
        {copy.home}
      </button>
    </div>
  )
}
