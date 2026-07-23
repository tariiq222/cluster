import {
  lazy,
  Suspense,
  type ReactNode,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'

import {
  AppShell,
  type SidebarNavigationGroup,
} from './AppShell'
import { NotificationList } from './NotificationList'
import { shellCopy, text, type Locale } from './copy'
import { SessionProvider } from './session-context'
import { PrincipalProvider, usePrincipal } from './principal-context'
import {
  buildNavigationGroups,
  buildUserMenuEntries,
} from '../shell/navigation'
import {
  pathFromRoute,
  routeFromPath,
  isRouteVisible,
  type AppRoute,
} from '../shell/routes'
import { RequestDetail } from '../features/requests/RequestDetail'
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
import { AccessContext } from '../features/authorization/AccessContext'
import { AccessDecisionWorkspace } from '../features/authorization/AccessDecisionWorkspace'
import { RolesCapabilitiesWorkspace } from '../features/authorization/RolesCapabilitiesWorkspace'
import { AccessScopesScreen } from '../features/authorization/AccessScopesScreen'
import { OrganizationWorkspace, PeopleAssignments, TemporaryAssignments } from '../features/organization'
import { ImportReview } from '../features/imports/ImportReview'
import { IdentityAccounts } from '../features/identity/IdentityAccounts'
import { AuthorizationAdmin } from '../features/authorization/AuthorizationAdmin'
import { Day2Workflow } from '../features/workflow/Day2Workflow'
import {
  ApiError,
  getWorkRecord,
  listNotifications,
  listWorkRecords,
  type Notification,
  type Session,
  type WorkRecord,
} from '../api'
import { getAuthorizedWorkRecord, markNotificationRead } from '../api/r1'
import { Page, PageHeader, Panel, SkeletonList } from '../ui'

const SwaggerUiScreen = lazy(async () => {
  const module = await import('../features/docs/SwaggerUiScreen')
  return { default: module.SwaggerUiScreen }
})

function routeToPath(route: AppRoute): string {
  return pathFromRoute(route)
}

function RouteNotFound({ locale }: { locale: Locale }) {
  const copy = text[locale]
  return (
    <Page>
      <section className="state-panel" aria-labelledby="not-found-heading">
        <PageHeader
          id="not-found-heading"
          title={copy.notFound}
          description={copy.notFoundBody}
        />
      </section>
    </Page>
  )
}

export function RouteAccessGuard({
  locale,
  route,
  capabilities,
  children,
}: {
  locale: Locale
  route: AppRoute
  capabilities: readonly string[] | null
  children: ReactNode
}) {
  if (isRouteVisible(route, capabilities)) return children

  const copy = text[locale]
  return (
    <Page aria-labelledby="route-access-denied-heading">
      <Panel id="route-access-denied" title={copy.accessDenied} level={2}>
        <p id="route-access-denied-heading">{copy.accessDeniedBody}</p>
      </Panel>
    </Page>
  )
}

function RequestDetailRoute({
  locale,
  token,
  recordId,
}: {
  locale: Locale
  token: string
  recordId: string
}) {
  const [record, setRecord] = useState<WorkRecord | null>(null)
  const [authorizedRecord, setAuthorizedRecord] = useState<Awaited<
    ReturnType<typeof getAuthorizedWorkRecord>
  > | null>(null)
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<'ready' | 'unavailable' | 'error'>('ready')

  const load = useCallback(async () => {
    setLoading(true)
    setState('ready')
    try {
      const [recordValue, authorizedValue] = await Promise.all([
        getWorkRecord(token, recordId),
        getAuthorizedWorkRecord(token, recordId).catch(() => null),
      ])
      setRecord(recordValue)
      setAuthorizedRecord(authorizedValue)
    } catch (error) {
      setRecord(null)
      setAuthorizedRecord(null)
      setState(
        error instanceof ApiError && [403, 404].includes(error.status)
          ? 'unavailable'
          : 'error',
      )
    } finally {
      setLoading(false)
    }
  }, [recordId, token])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <RequestDetail
      locale={locale}
      token={token}
      record={record}
      loading={loading}
      state={state}
      authorizedRecord={authorizedRecord ?? undefined}
      onRetry={load}
    />
  )
}

function NotificationsRoute({
  locale,
  notifications,
  loading,
  error,
  onMarkRead,
  hasMore,
  loadingMore,
  loadMoreError,
  onLoadMore,
}: {
  locale: Locale
  notifications: Notification[]
  loading: boolean
  error: boolean
  onMarkRead: (notificationId: string) => Promise<void>
  hasMore: boolean
  loadingMore: boolean
  loadMoreError: boolean
  onLoadMore: () => void
}) {
  const copy = text[locale]
  return (
    <Page>
      <section className="ui-page" aria-labelledby="notifications-heading">
        <PageHeader
          id="notifications-heading"
          title={copy.notifications}
          description={copy.loadedNotificationSource}
        />
        <NotificationList
          locale={locale}
          items={notifications}
          loading={loading}
          error={error}
          onMarkRead={onMarkRead}
          hasMore={hasMore}
          loadingMore={loadingMore}
          loadMoreError={loadMoreError}
          onLoadMore={onLoadMore}
        />
      </section>
    </Page>
  )
}

function ShellInner({
  locale,
  session,
  route,
  notifications,
  notificationsLoading,
  notificationsError,
  unreadNotifications,
  notificationsOpen,
  setNotificationsOpen,
  notificationButtonRef,
  globalSearchQuery,
  setGlobalSearchQuery,
  navigate,
  reloadNotifications: _reloadNotifications,
  loadMoreRecords: _loadMoreRecords,
  recordsHasMore: _recordsHasMore,
  recordsLoadingMore: _recordsLoadingMore,
  recordsLoadMoreError: _recordsLoadMoreError,
  loadMoreNotifications,
  notificationsHasMore,
  notificationsLoadingMore,
  notificationsLoadMoreError,
  handleMarkNotificationRead,
  onLocaleChange,
  onLogout,
}: {
  locale: Locale
  session: Session
  route: AppRoute
  records?: WorkRecord[]
  recordsLoading?: boolean
  recordsError?: boolean
  notifications: Notification[]
  notificationsLoading: boolean
  notificationsError: boolean
  unreadNotifications: number
  setNotificationsOpen: (open: boolean) => void
  notificationsOpen: boolean
  notificationsDialogOpen: boolean
  notificationButtonRef: React.RefObject<HTMLButtonElement | null>
  globalSearchQuery: string
  setGlobalSearchQuery: (query: string) => void
  navigate: (path: string) => void
  reloadRecords: () => void
  reloadNotifications: () => void
  loadMoreRecords: () => void
  recordsHasMore: boolean
  recordsLoadingMore: boolean
  recordsLoadMoreError: boolean
  loadMoreNotifications: () => void
  notificationsHasMore: boolean
  notificationsLoadingMore: boolean
  notificationsLoadMoreError: boolean
  handleMarkNotificationRead: (notificationId: string) => Promise<void>
  onLocaleChange: () => void
  onLogout: () => void | Promise<void>
}) {
  const principal = usePrincipal()
  const copy = text[locale]

  const navigationGroups: SidebarNavigationGroup[] = useMemo(() => {
    const built = buildNavigationGroups({ locale, capabilities: principal.capabilities })
    return built.map((group) => ({
      key: group.key,
      label: copy[group.labelKey],
      icon: group.icon,
      items: group.items.map((item) => {
        const target = item.path
        const active = isRouteInNavigationActive(route, target, locale)
        return {
          key: item.key,
          label: item.label,
          path: item.path,
          icon: item.icon,
          active,
          onSelect: () => navigate(target),
        }
      }),
    }))
  }, [copy, locale, navigate, principal.capabilities, route])

  const userMenu = useMemo(() => buildUserMenuEntries(locale).map((entry) => ({
    key: entry.key,
    label: entry.label,
    path: entry.path,
    onSelect: () => navigate(entry.path),
  })), [locale, navigate])

  const facilityName = principal.effectiveScope?.label ?? copy.organizationName
  const scopeSelector = {
    current: principal.effectiveScope
      ? `${principal.effectiveScope.scopeType}:${principal.effectiveScope.scopeId}`
      : null,
    options: principal.availableScopes.map((option) => ({
      value: `${option.scopeType}:${option.scopeId}`,
      label: option.label,
    })),
    disabled: principal.state !== 'ready',
    pending: principal.state === 'loading',
    stale: principal.state === 'stale' || principal.state === 'denied' || principal.state === 'error',
    onSelect: (value: string) => {
      const [scopeType, scopeId] = value.split(':')
      void principal.selectScope(scopeType as 'cluster' | 'facility' | 'unit', scopeId)
    },
    onRetry: () => { void principal.refresh() },
  }

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
            onCreateRequest={() => navigate(routeToPath({ name: 'create' }))}
            onBrowseServices={() => navigate(routeToPath({ name: 'procedure-guide' }))}
            onOpenApprovals={() => navigate(routeToPath({ name: 'approval-inbox' }))}
            onOpenRequests={() => navigate(routeToPath({ name: 'my-requests' }))}
            onOpenTasks={() => navigate(routeToPath({ name: 'tasks' }))}
            onOpenDocuments={() => navigate(routeToPath({ name: 'documents' }))}
            onOpenDashboards={() => navigate(routeToPath({ name: 'dashboards' }))}
            onOpenRequestInstance={(instanceId) => navigate(routeToPath({ name: 'my-request-detail', instanceId }))}
            onOpenApprovalStep={(stepId) => navigate(routeToPath({ name: 'approval-detail', stepId }))}
            onOpenTask={(taskId) => navigate(routeToPath({ name: 'task-detail', taskId }))}
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
              navigate(routeToPath({ name: 'detail', recordId: record.id }))
            }
            onBack={() => navigate(routeToPath({ name: 'list' }))}
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
            onJobOpen={(nextJobId) => navigate(routeToPath({ name: 'organization-import', jobId: nextJobId }))}
          />
        )
      case 'identity-accounts':
        return <IdentityAccounts />
      case 'authorization':
        if (route.resource === 'roles' || route.resource === 'capabilities') {
          return <RolesCapabilitiesWorkspace locale={locale} activeResource={route.resource} capabilities={principal.capabilities} navigate={navigate} />
        }
        return <AuthorizationAdmin resource={route.resource} />
      case 'access-scopes':
        return <AccessScopesScreen locale={locale} scopeReady={principal.scopeReady} scopeEpoch={principal.scopeEpoch} />
      case 'access-context':
        return <AccessContext />
      case 'access-explanation':
        return <AccessDecisionWorkspace locale={locale} decisionId={'decisionId' in route ? route.decisionId : undefined} />
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
            onMarkRead={handleMarkNotificationRead}
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
        return (
          <Suspense
            fallback={
              <Page>
                <SkeletonList
                  label={text[locale].loadingApiReference}
                />
              </Page>
            }
          >
            <SwaggerUiScreen locale={locale} />
          </Suspense>
        )
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
      case 'not-found':
        return <RouteNotFound locale={locale} />
    }
  }

  return (
    <AppShell
      locale={locale}
      copy={shellCopy(locale)}
      facilityName={facilityName}
      navigationGroups={navigationGroups}
      unreadNotifications={unreadNotifications}
      notificationButtonRef={notificationButtonRef}
      notificationsOpen={notificationsOpen}
      globalSearchLabel={text[locale].searchForARecordTask}
      onGlobalSearch={(query) => {
        setGlobalSearchQuery(query)
        navigate(routeToPath({ name: 'search' }))
      }}
      onLocaleChange={onLocaleChange}
      onNotificationsToggle={() => setNotificationsOpen(!notificationsOpen)}
      onLogout={onLogout}
      userMenu={userMenu}
      scopeSelector={scopeSelector}
      notificationPanel={
        <NotificationList
          locale={locale}
          items={notifications}
          loading={notificationsLoading}
          error={notificationsError}
          onMarkRead={handleMarkNotificationRead}
          hasMore={notificationsHasMore}
          loadingMore={notificationsLoadingMore}
          loadMoreError={notificationsLoadMoreError}
          onLoadMore={() => { void loadMoreNotifications() }}
        />
      }
    >
      <RouteAccessGuard locale={locale} route={route} capabilities={principal.capabilities}>
        {renderRoute()}
      </RouteAccessGuard>
    </AppShell>
  )
}

function isRouteInNavigationActive(current: AppRoute, targetPath: string, _locale: Locale): boolean {
  if (pathFromRoute(current) === targetPath) return true
  // Two-tab workspaces share their highlight across their two tabs.
  if (current.name === 'organization' && (targetPath === '/admin/organization/structure' || targetPath === '/admin/organization')) return true
  if (current.name === 'organization-structure' && (targetPath === '/admin/organization/structure' || targetPath === '/admin/organization')) return true
  if (current.name === 'authorization' && (current.resource === 'roles' || current.resource === 'capabilities')
    && (targetPath === '/admin/authorization/roles' || targetPath === '/admin/authorization/capabilities')) return true
  return false
}

export function AppWorkspace({
  locale,
  session,
  onLocaleChange,
  onLogout,
}: {
  locale: Locale
  session: Session
  onLocaleChange: () => void
  onLogout: () => void | Promise<void>
}) {
  const [route, setRoute] = useState<AppRoute>(() =>
    routeFromPath(window.location.pathname),
  )
  const [records, setRecords] = useState<WorkRecord[]>([])
  const [recordsLoading, setRecordsLoading] = useState(true)
  const [recordsError, setRecordsError] = useState(false)
  const [recordsNextCursor, setRecordsNextCursor] = useState<string | null>(
    null,
  )
  const [recordsLoadingMore, setRecordsLoadingMore] = useState(false)
  const [recordsLoadMoreError, setRecordsLoadMoreError] = useState(false)
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [notificationsLoading, setNotificationsLoading] = useState(true)
  const [notificationsError, setNotificationsError] = useState(false)
  const [notificationsNextCursor, setNotificationsNextCursor] = useState<
    string | null
  >(null)
  const [notificationsLoadingMore, setNotificationsLoadingMore] =
    useState(false)
  const [notificationsLoadMoreError, setNotificationsLoadMoreError] =
    useState(false)
  const [notificationsDialogOpen, _setNotificationsDialogOpen] = useState(false)
  const [globalSearchQuery, setGlobalSearchQuery] = useState('')
  const notificationButtonRef = useRef<HTMLButtonElement | null>(null)

  useEffect(() => {
    function handlePopState() {
      setRoute(routeFromPath(window.location.pathname))
    }

    handlePopState()
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  const navigate = useCallback((path: string) => {
    if (window.location.pathname !== path) {
      window.history.pushState({}, '', path)
    }
    setRoute(routeFromPath(path))
  }, [])

  const loadRecords = useCallback(async () => {
    setRecordsLoading(true)
    setRecordsError(false)
    setRecordsLoadMoreError(false)
    setRecordsNextCursor(null)
    try {
      const page = await listWorkRecords(session.access_token)
      setRecords(page.items ?? [])
      setRecordsNextCursor(page.next_cursor ?? null)
    } catch {
      setRecords([])
      setRecordsError(true)
    } finally {
      setRecordsLoading(false)
    }
  }, [session.access_token])

  const loadMoreRecords = useCallback(async () => {
    if (!recordsNextCursor || recordsLoadingMore || recordsLoading) return
    const cursor = recordsNextCursor
    setRecordsLoadingMore(true)
    setRecordsLoadMoreError(false)
    try {
      const page = await listWorkRecords(session.access_token, cursor)
      setRecords((current) => {
        const seen = new Set(current.map((item) => item.id))
        return [
          ...current,
          ...(page.items ?? []).filter((item) => !seen.has(item.id)),
        ]
      })
      setRecordsNextCursor(page.next_cursor ?? null)
    } catch {
      setRecordsLoadMoreError(true)
      setRecordsNextCursor(null)
    } finally {
      setRecordsLoadingMore(false)
    }
  }, [
    recordsLoading,
    recordsLoadingMore,
    recordsNextCursor,
    session.access_token,
  ])

  const loadNotifications = useCallback(async () => {
    setNotificationsLoading(true)
    setNotificationsError(false)
    setNotificationsLoadMoreError(false)
    setNotificationsNextCursor(null)
    try {
      const page = await listNotifications(session.access_token)
      setNotifications(page.items ?? [])
      setNotificationsNextCursor(page.next_cursor ?? null)
    } catch {
      setNotifications([])
      setNotificationsError(true)
    } finally {
      setNotificationsLoading(false)
    }
  }, [session.access_token])

  const loadMoreNotifications = useCallback(async () => {
    if (
      !notificationsNextCursor ||
      notificationsLoadingMore ||
      notificationsLoading
    )
      return
    const cursor = notificationsNextCursor
    setNotificationsLoadingMore(true)
    setNotificationsLoadMoreError(false)
    try {
      const page = await listNotifications(session.access_token, cursor)
      setNotifications((current) => {
        const seen = new Set(current.map((item) => item.id))
        return [
          ...current,
          ...(page.items ?? []).filter((item) => !seen.has(item.id)),
        ]
      })
      setNotificationsNextCursor(page.next_cursor ?? null)
    } catch {
      setNotificationsLoadMoreError(true)
      setNotificationsNextCursor(null)
    } finally {
      setNotificationsLoadingMore(false)
    }
  }, [
    notificationsLoading,
    notificationsLoadingMore,
    notificationsNextCursor,
    session.access_token,
  ])

  const handleMarkNotificationRead = useCallback(
    async (notificationId: string) => {
      await markNotificationRead(session.access_token, notificationId)
      setNotifications((current) =>
        current.map((item) =>
          item.id === notificationId ? { ...item, is_read: true } : item,
        ),
      )
    },
    [session.access_token],
  )

  useEffect(() => {
    void loadRecords()
    void loadNotifications()
  }, [loadNotifications, loadRecords])

  const unreadNotifications = notifications.filter((item) => !item.is_read).length

  return (
    <SessionProvider locale={locale} session={session}>
      <PrincipalProvider token={session.access_token}>
        <ShellInner
          locale={locale}
          session={session}
          route={route}
          records={records}
          recordsLoading={recordsLoading}
          recordsError={recordsError}
          notifications={notifications}
          notificationsLoading={notificationsLoading}
          notificationsError={notificationsError}
          unreadNotifications={unreadNotifications}
          notificationsOpen={notificationsDialogOpen}
          setNotificationsOpen={_setNotificationsDialogOpen}
          notificationsDialogOpen={notificationsDialogOpen}
          notificationButtonRef={notificationButtonRef}
          globalSearchQuery={globalSearchQuery}
          setGlobalSearchQuery={setGlobalSearchQuery}
          navigate={navigate}
          reloadRecords={() => { void loadRecords() }}
          reloadNotifications={() => { void loadNotifications() }}
          loadMoreRecords={loadMoreRecords}
          recordsHasMore={Boolean(recordsNextCursor)}
          recordsLoadingMore={recordsLoadingMore}
          recordsLoadMoreError={recordsLoadMoreError}
          loadMoreNotifications={loadMoreNotifications}
          notificationsHasMore={Boolean(notificationsNextCursor)}
          notificationsLoadingMore={notificationsLoadingMore}
          notificationsLoadMoreError={notificationsLoadMoreError}
          handleMarkNotificationRead={handleMarkNotificationRead}
          onLocaleChange={onLocaleChange}
          onLogout={onLogout}
        />
      </PrincipalProvider>
    </SessionProvider>
  )
}
