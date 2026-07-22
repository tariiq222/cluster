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
  BarChart3,
  BookOpenText,
  Building2,
  ClipboardList,
  Home,
  Shield,
  TabletSmartphone,
  Workflow,
  FileText,
} from 'lucide-react'

import {
  AppShell,
  type AppShellCopy,
  type SidebarNavigationGroup,
} from './AppShell'
import { NotificationList } from './NotificationList'
import { text, type Locale } from './copy'
import { SessionProvider } from './session-context'
import {
  isRouteActive,
  pathFromRoute,
  routeFromPath,
  type AppRoute,
} from '../shell/routes'
import { AccessWorkspace } from '../features/authorization/AccessWorkspace'
import { ProcessWorkspace } from '../features/workflow/ProcessWorkspace'
import { OrganizationWorkspace } from '../features/organization/OrganizationWorkspace'
import { RequestDashboard } from '../features/requests/RequestDashboard'
import { RequestDetail } from '../features/requests/RequestDetail'
import { RequestForm } from '../features/requests/RequestForm'
import {
  ReportsScreen,
  SearchScreen,
  TasksScreen,
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
import { Page, PageHeader, SkeletonList } from '../ui'

const SwaggerUiScreen = lazy(async () => {
  const module = await import('../features/docs/SwaggerUiScreen')
  return { default: module.SwaggerUiScreen }
})

function shellCopy(locale: Locale): AppShellCopy {
  const copy = text[locale]
  return {
    platform: copy.platform,
    switchLanguage: copy.switchLanguage,
    currentFacility: copy.currentFacility,
    notifications: copy.notifications,
    profile: copy.profile,
    logout: copy.logout,
    rightsReserved: copy.rightsReserved,
    organizationName: copy.organizationName,
    officeName: copy.officeName,
    ownerName: copy.ownerName,
    openNavigation: copy.openNavigation,
    closeNavigation: copy.closeNavigation,
    closeNotifications: copy.closeNotifications,
    navigationTitle: copy.navigationTitle,
    platformUser: copy.platformUser,
    internalSystem: copy.internalSystem,
    collapseNavigation: copy.collapseNavigation,
    expandNavigation: copy.expandNavigation,
  }
}

function shellNavigation(
  route: AppRoute,
  locale: Locale,
  navigate: (path: string) => void,
): SidebarNavigationGroup[] {
  const copy = text[locale]
  const workRoutes: Array<{
    route: AppRoute
    label: string
    icon: ReactNode
    count?: string
  }> = [
    {
      route: { name: 'list' },
      label: text[locale].home,
      icon: <Home aria-hidden="true" />,
    },
    {
      route: { name: 'tasks' },
      label: copy.myTasks,
      icon: <ClipboardList aria-hidden="true" />,
    },
  ]
  const operationsRoutes: Array<{
    route: AppRoute
    label: string
    icon: ReactNode
    count?: string
  }> = [
    {
      route: { name: 'work-definitions' },
      label:
        text[locale].processesAndWorkflow,
      icon: <Workflow aria-hidden="true" />,
    },
    {
      route: { name: 'organization' },
      label: text[locale].organization2,
      icon: <Building2 aria-hidden="true" />,
    },
    {
      route: { name: 'identity-accounts' },
      label: text[locale].accountsAndAccess,
      icon: <Shield aria-hidden="true" />,
    },
    {
      route: { name: 'reports' },
      label: copy.reportsScreen,
      icon: <BarChart3 aria-hidden="true" />,
    },
  ]
  const reviewRoutes: Array<{
    route: AppRoute
    label: string
    icon: ReactNode
    count?: string
  }> = [
    {
      route: { name: 'documents' },
      label: copy.documents,
      icon: <FileText aria-hidden="true" />,
    },
    {
      route: { name: 'coverage' },
      label: text[locale].coverage,
      icon: <TabletSmartphone aria-hidden="true" />,
    },
    {
      route: { name: 'api-docs' },
      label: text[locale].apiReference,
      icon: <BookOpenText aria-hidden="true" />,
    },
  ]

  return [
    {
      key: 'work',
      label: text[locale].myWork,
      icon: <Home aria-hidden="true" />,
      items: workRoutes.map(({ route: target, label, icon, count }) => ({
        key: `work-${target.name}`,
        label,
        path: pathFromRoute(target),
        icon,
        count,
        active: isRouteActive(route, target),
        onSelect: () => navigate(pathFromRoute(target)),
      })),
    },
    {
      key: 'operations',
      label: text[locale].operations,
      icon: <Building2 aria-hidden="true" />,
      items: operationsRoutes.map(({ route: target, label, icon, count }) => ({
        key: `operations-${target.name}${'resource' in target ? `-${target.resource}` : ''}`,
        label,
        path: pathFromRoute(target),
        icon,
        count,
        active: isRouteActive(route, target),
        onSelect: () => navigate(pathFromRoute(target)),
      })),
    },
    {
      key: 'review',
      label: text[locale].productReview,
      icon: <TabletSmartphone aria-hidden="true" />,
      items: reviewRoutes.map(({ route: target, label, icon, count }) => ({
        key: `review-${target.name}`,
        label,
        path: pathFromRoute(target),
        icon,
        count,
        active: isRouteActive(route, target),
        onSelect: () => navigate(pathFromRoute(target)),
      })),
    },
  ]
}

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
        // The authorized projection is optional: the plain record still renders without it.
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

function DashboardRoute({
  locale,
  records,
  recordsLoading,
  recordsError,
  notifications,
  notificationsLoading,
  notificationsError,
  facilityName,
  navigate,
  reloadRecords,
  reloadNotifications,
  loadMoreRecords,
  recordsHasMore,
  recordsLoadingMore,
  recordsLoadMoreError,
  loadMoreNotifications,
  notificationsHasMore,
  notificationsLoadingMore,
  notificationsLoadMoreError,
}: {
  locale: Locale
  records: WorkRecord[]
  recordsLoading: boolean
  recordsError: boolean
  notifications: Notification[]
  notificationsLoading: boolean
  notificationsError: boolean
  facilityName: string
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
}) {
  return (
    <RequestDashboard
      locale={locale}
      records={records}
      notifications={notifications}
      loading={recordsLoading}
      error={recordsError}
      notificationsLoading={notificationsLoading}
      notificationsError={notificationsError}
      facilityName={facilityName}
      onRetry={() => {
        reloadRecords()
        reloadNotifications()
      }}
      onCreate={() => navigate(routeToPath({ name: 'create' }))}
      onSelect={(recordId) =>
        navigate(routeToPath({ name: 'detail', recordId }))
      }
      onOpenNotifications={() =>
        navigate(routeToPath({ name: 'notifications' }))
      }
      onLoadMore={loadMoreRecords}
      hasMore={recordsHasMore}
      loadingMore={recordsLoadingMore}
      loadMoreError={recordsLoadMoreError}
      onLoadMoreNotifications={loadMoreNotifications}
      notificationsHasMore={notificationsHasMore}
      notificationsLoadingMore={notificationsLoadingMore}
      notificationsLoadMoreError={notificationsLoadMoreError}
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
  const [notificationsDialogOpen, setNotificationsDialogOpen] = useState(false)
  const [globalSearchQuery, setGlobalSearchQuery] = useState('')
  const notificationButtonRef = useRef<HTMLButtonElement | null>(null)
  const copy = text[locale]
  const facilityName = copy.organizationName

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

  const shellProps = useMemo(() => shellCopy(locale), [locale])
  const navigationGroups = useMemo(
    () => shellNavigation(route, locale, navigate),
    [locale, navigate, route],
  )

  function renderRoute() {
    switch (route.name) {
      case 'list':
        return (
          <DashboardRoute
            locale={locale}
            records={records}
            recordsLoading={recordsLoading}
            recordsError={recordsError}
            notifications={notifications}
            notificationsLoading={notificationsLoading}
            notificationsError={notificationsError}
            facilityName={facilityName}
            navigate={navigate}
            reloadRecords={() => {
              void loadRecords()
            }}
            reloadNotifications={() => {
              void loadNotifications()
            }}
            loadMoreRecords={() => {
              void loadMoreRecords()
            }}
            recordsHasMore={Boolean(recordsNextCursor)}
            recordsLoadingMore={recordsLoadingMore}
            recordsLoadMoreError={recordsLoadMoreError}
            loadMoreNotifications={() => {
              void loadMoreNotifications()
            }}
            notificationsHasMore={Boolean(notificationsNextCursor)}
            notificationsLoadingMore={notificationsLoadingMore}
            notificationsLoadMoreError={notificationsLoadMoreError}
          />
        )
      case 'documents':
      case 'document-detail':
        return <DocumentsWorkspace locale={locale} token={session.access_token} documentId={route.name === 'document-detail' ? route.documentId : undefined} onNavigate={navigate} />
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
      case 'people-assignments':
      case 'temporary-assignments':
      case 'organization-import':
        return (
          <OrganizationWorkspace
            locale={locale}
            activeRouteName={route.name}
            jobId={
              route.name === 'organization-import' ? route.jobId : undefined
            }
            navigate={navigate}
          />
        )
      case 'identity-accounts':
      case 'authorization':
      case 'access-context':
      case 'access-explanation':
        return (
          <AccessWorkspace
            locale={locale}
            activeRoute={route}
            navigate={navigate}
          />
        )
      case 'workflow-day2':
      case 'work-definitions':
      case 'workflow-admin':
        return (
          <ProcessWorkspace
            locale={locale}
            session={session}
            activeRouteName={route.name}
            navigate={navigate}
          />
        )
      case 'tasks':
        return <TasksScreen />
      case 'search':
        return (
          <SearchScreen initialQuery={globalSearchQuery} />
        )
      case 'reports':
        return <ReportsScreen />
      case 'notifications':
        return (
          <NotificationsRoute
            locale={locale}
            notifications={notifications}
            loading={notificationsLoading}
            error={notificationsError}
            onMarkRead={handleMarkNotificationRead}
            hasMore={Boolean(notificationsNextCursor)}
            loadingMore={notificationsLoadingMore}
            loadMoreError={notificationsLoadMoreError}
            onLoadMore={() => {
              void loadMoreNotifications()
            }}
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
                  label={
                    text[locale].loadingApiReference
                  }
                />
              </Page>
            }
          >
            <SwaggerUiScreen locale={locale} />
          </Suspense>
        )
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
        return <ApprovalInbox locale={locale} session={session} />
      case 'my-requests':
        return <MyRequests locale={locale} session={session} />
      case 'new-procedure-request':
        return <NewProcedureRequest locale={locale} />
      case 'not-found':
        return <RouteNotFound locale={locale} />
    }
  }

  return (
    <SessionProvider locale={locale} session={session}>
      <AppShell
        locale={locale}
        copy={shellProps}
        facilityName={facilityName}
        navigationGroups={navigationGroups}
        unreadNotifications={
          notifications.filter((item) => !item.is_read).length
        }
        notificationButtonRef={notificationButtonRef}
        notificationsOpen={notificationsDialogOpen}
        globalSearchLabel={
          text[locale].searchForARecordTask
        }
        onGlobalSearch={(query) => {
          setGlobalSearchQuery(query)
          navigate(routeToPath({ name: 'search' }))
        }}
        onLocaleChange={onLocaleChange}
        onNotificationsToggle={() =>
          setNotificationsDialogOpen((current) => !current)
        }
        onLogout={onLogout}
        personalSecurity={{
          path: routeToPath({ name: 'personal-security' }),
          onSelect: () => navigate(routeToPath({ name: 'personal-security' })),
        }}
        notificationPanel={
          <NotificationList
            locale={locale}
            items={notifications}
            loading={notificationsLoading}
            error={notificationsError}
            onMarkRead={handleMarkNotificationRead}
            hasMore={Boolean(notificationsNextCursor)}
            loadingMore={notificationsLoadingMore}
            loadMoreError={notificationsLoadMoreError}
            onLoadMore={() => {
              void loadMoreNotifications()
            }}
          />
        }
      >
        {renderRoute()}
      </AppShell>
    </SessionProvider>
  )
}
