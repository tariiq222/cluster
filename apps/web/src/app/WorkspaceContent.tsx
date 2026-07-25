import type { ReactNode } from 'react'

import type { Locale } from './copy'
import { usePrincipal } from './principal-context'
import {
  pathFromRoute,
  type AppRoute,
} from '../shell/routes'
import type { Notification, Session } from '../api'
import { RequestForm } from '../features/requests/RequestForm'
import {
  ReportsScreen,
  SearchScreen,
  TasksScreen,
  WorkDefinitionsScreen,
  WorkflowAdminScreen,
} from '../features/r1/R1Screens'
import { CoverageScreen } from '../features/portal/CoverageScreen'
import { PersonalSecurity } from '../features/identity/PersonalSecurity'
import { DocumentsWorkspace } from '../features/documents/DocumentsWorkspace'
import { ProcedureAuthoring } from '../features/workflow/ProcedureAuthoring'
import { ProcedureOfficeReview } from '../features/workflow/ProcedureOfficeReview'
import { ProcedureGuide } from '../features/workflow/ProcedureGuide'
import { ApprovalInbox } from '../features/workflow/ApprovalInbox'
import { MyRequests } from '../features/workflow/MyRequests'
import { NewProcedureRequest } from '../features/workflow/NewProcedureRequest'
import { ApprovalDetail } from '../features/workflow/ApprovalDetail'
import { MyRequestDetail } from '../features/workflow/MyRequestDetail'
import { TaskDetail } from '../features/tasks/TaskDetail'
import { WorkDashboard } from '../features/dashboard/WorkDashboard'
import { DashboardsScreen } from '../features/reporting/DashboardsScreen'
import { AccessWorkspace } from '../features/authorization/AccessWorkspace'
import { OrganizationWorkspace, PeopleAssignments, TemporaryAssignments } from '../features/organization'
import { ImportReview } from '../features/imports/ImportReview'
import { Day2Workflow } from '../features/workflow/Day2Workflow'
import {
  ApiDocsRoute,
  NotificationsRoute,
  PlatformSettingsRoute,
  RequestDetailRoute,
  RouteAccessGuard,
  RouteNotFound,
} from './workspace-routes'

export type WorkspaceContentProps = {
  locale: Locale
  session: Session
  route: AppRoute
  globalSearchQuery: string
  scopeReady: boolean
  scopeEpoch: number
  navigate: (path: string) => void
  onRouteNavigate: (route: AppRoute) => void
  notifications: Notification[]
  notificationsLoading: boolean
  notificationsError: boolean
  notificationsHasMore: boolean
  notificationsLoadingMore: boolean
  notificationsLoadMoreError: boolean
  loadMoreNotifications: () => void
  onMarkNotificationRead: (notificationId: string) => Promise<void>
}

export { RouteAccessGuard }

/**
 * Renders the routed page for the current `route`. The principal's capability
 * snapshot is consulted only to gate the authorization surface; everything else
 * is driven by the route variant.
 */
export function WorkspaceContent({
  locale,
  session,
  route,
  globalSearchQuery,
  navigate,
  onRouteNavigate,
  notifications,
  notificationsLoading,
  notificationsError,
  notificationsHasMore,
  notificationsLoadingMore,
  notificationsLoadMoreError,
  loadMoreNotifications,
  onMarkNotificationRead,
}: WorkspaceContentProps) {
  const principal = usePrincipal()

  function renderRoute(): ReactNode {
    switch (route.name) {
      case 'list':
        return (
          <WorkDashboard
            locale={locale}
            session={session}
            principalRevision={principal.revision}
            effectiveScopeId={principal.effectiveScope?.scopeId}
            effectiveScopeLabel={principal.effectiveScope?.label}
            scopeEpoch={principal.scopeEpoch}
            scopeReady={principal.scopeReady}
            canViewDashboards={principal.capabilities?.includes('reporting.dashboard') === true}
            canCreateRequest={principal.capabilities?.includes('work_record.create') === true}
            canBrowseServices={principal.capabilities?.some((capability) => capability === 'work_definition.read' || capability === 'work_definition.list') === true}
            onCreateRequest={() => onRouteNavigate({ name: 'create' })}
            onBrowseServices={() => onRouteNavigate({ name: 'procedure-guide' })}
            onOpenApprovals={() => onRouteNavigate({ name: 'approval-inbox' })}
            onOpenRequests={() => onRouteNavigate({ name: 'my-requests' })}
            onOpenTasks={() => onRouteNavigate({ name: 'tasks' })}
            onOpenDocuments={() => onRouteNavigate({ name: 'documents' })}
            onOpenDashboards={() => onRouteNavigate({ name: 'dashboards' })}
            onOpenRequestInstance={(instanceId) => onRouteNavigate({ name: 'my-request-detail', instanceId })}
            onOpenApprovalStep={(stepId) => onRouteNavigate({ name: 'approval-detail', stepId })}
            onOpenTask={(taskId) => onRouteNavigate({ name: 'task-detail', taskId })}
          />
        )
      case 'documents':
      case 'document-detail':
        return (
          <DocumentsWorkspace
            locale={locale}
            token={session.access_token}
            documentId={route.name === 'document-detail' ? route.documentId : undefined}
            onNavigate={navigate}
          />
        )
      case 'create':
        return (
          <RequestForm
            onCreated={(record) =>
              navigate(pathFromRoute({ name: 'detail', recordId: record.id }))
            }
            onBack={() => navigate(pathFromRoute({ name: 'list' }))}
          />
        )
      case 'detail':
        return (
          <RequestDetailRoute
            locale={locale}
            token={session.access_token}
            recordId={route.recordId}
          />
        )
      case 'organization':
      case 'organization-structure':
        return (
          <OrganizationWorkspace
            locale={locale}
            activeRouteName={route.name}
            capabilities={principal.capabilities}
            navigate={navigate}
          />
        )
      case 'people-assignments':
        return <PeopleAssignments />
      case 'temporary-assignments':
        return <TemporaryAssignments />
      case 'organization-import':
        return (
          <ImportReview
            jobId={'jobId' in route ? route.jobId : undefined}
            onJobOpen={(nextJobId) => onRouteNavigate({ name: 'organization-import', jobId: nextJobId })}
          />
        )
      case 'identity-accounts':
      case 'authorization':
      case 'access-scopes':
      case 'access-explanation':
      case 'access-context':
        return <AccessWorkspace locale={locale} activeRoute={route} navigate={navigate} scopeReady={principal.scopeReady} scopeEpoch={principal.scopeEpoch} />
      case 'workflow-day2':
        return <Day2Workflow session={session} />
      case 'tasks':
        return <TasksScreen />
      case 'task-detail':
        return (
          <TaskDetail
            locale={locale}
            session={session}
            taskId={route.taskId}
            scopeReady={principal.scopeReady}
            scopeEpoch={principal.scopeEpoch}
          />
        )
      case 'work-definitions':
        return <WorkDefinitionsScreen />
      case 'workflow-admin':
        return <WorkflowAdminScreen />
      case 'procedure-authoring':
        return <ProcedureAuthoring locale={locale} session={session} />
      case 'procedure-office-review':
        return <ProcedureOfficeReview locale={locale} session={session} />
      case 'procedure-guide':
        return (
          <ProcedureGuide
            locale={locale}
            session={session}
            highlightedProcedureId={route.name === 'procedure-guide' ? route.procedureId : undefined}
          />
        )
      case 'approval-inbox':
        return <ApprovalInbox locale={locale} session={session} scopeReady={principal.scopeReady} scopeEpoch={principal.scopeEpoch} />
      case 'approval-detail':
        return (
          <ApprovalDetail
            locale={locale}
            session={session}
            stepId={route.stepId}
            scopeReady={principal.scopeReady}
            scopeEpoch={principal.scopeEpoch}
          />
        )
      case 'my-requests':
        return <MyRequests locale={locale} session={session} scopeReady={principal.scopeReady} scopeEpoch={principal.scopeEpoch} />
      case 'my-request-detail':
        return (
          <MyRequestDetail
            locale={locale}
            session={session}
            instanceId={route.instanceId}
            scopeReady={principal.scopeReady}
            scopeEpoch={principal.scopeEpoch}
          />
        )
      case 'new-procedure-request':
        return <NewProcedureRequest locale={locale} />
      case 'notifications':
        return (
          <NotificationsRoute
            locale={locale}
            notifications={notifications}
            loading={notificationsLoading}
            error={notificationsError}
            onMarkRead={onMarkNotificationRead}
            hasMore={notificationsHasMore}
            loadingMore={notificationsLoadingMore}
            loadMoreError={notificationsLoadMoreError}
            onLoadMore={() => { void loadMoreNotifications() }}
          />
        )
      case 'personal-security':
        return <PersonalSecurity />
      case 'coverage':
        return <CoverageScreen locale={locale} />
      case 'api-docs':
        return <ApiDocsRoute locale={locale} />
      case 'search':
        return <SearchScreen initialQuery={globalSearchQuery} />
      case 'reports':
        return <ReportsScreen />
      case 'dashboards':
        return (
          <DashboardsScreen
            locale={locale}
            dashboardId={'dashboardId' in route ? route.dashboardId : undefined}
            scopeId={principal.effectiveScope?.scopeId}
            revision={principal.revision}
          />
        )
      case 'platform-settings':
        return <PlatformSettingsRoute locale={locale} section={route.section} capabilities={principal.capabilities} navigate={navigate} session={session} />
      case 'not-found':
        return <RouteNotFound locale={locale} />
    }
  }

  return (
    <RouteAccessGuard locale={locale} route={route} capabilities={principal.capabilities}>
      {renderRoute()}
    </RouteAccessGuard>
  )
}
