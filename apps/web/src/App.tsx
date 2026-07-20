import { type FormEvent, useEffect, useRef, useState } from 'react'
import {
  BarChart3,
  Bell,
  BellRing,
  Building2,
  CalendarClock,
  CalendarDays,
  ClipboardList,
  FileCog,
  FilePlus2,
  FileUp,
  FolderSearch,
  GitBranch,
  Handshake,
  IdCard,
  Inbox,
  KeyRound,
  KeySquare,
  LayoutDashboard,
  Network,
  Route,
  Search,
  Settings2,
  ShieldCheck,
  ShieldQuestion,
  UserCheck,
  UserCog,
  UserRound,
  Users,
  Workflow,
} from 'lucide-react'

import { AppShell, type SidebarNavigationGroup } from './app/AppShell'

import {
  ApiError,
  createWorkRecord,
  clearSessionMetadata,
  getWorkRecord,
  identityLogout,
  listNotifications,
  listWorkRecords,
  restoreSession,
  type Notification,
  type Session,
  type WorkRecord,
} from './api'
import { routeFromPath, type AppRoute } from './shell/routes'
import { text, recordStatusText, LOCALE_KEY, initialLocale } from './app/copy'
import { LoginScreen } from './app/LoginScreen'
import { NotificationList, formatDate } from './app/NotificationList'
import { OrganizationOverview } from './features/organization/OrganizationOverview'
import { OrganizationStructure } from './features/organization/OrganizationStructure'
import { PeopleAssignments } from './features/organization/PeopleAssignments'
import { TemporaryAssignments } from './features/organization/TemporaryAssignments'
import { IdentityAccounts } from './features/identity/IdentityAccounts'
import { ImportReview } from './features/imports/ImportReview'
import { AccessExplanation, AuthorizationAdmin } from './features/authorization/AuthorizationAdmin'
import { AccessContext } from './features/authorization/AccessContext'
import { RecordProjection } from './features/work-records/RecordProjection'
import { Day2Workflow } from './features/workflow/Day2Workflow'
import { AdaptiveDashboard, NotificationsScreen, ReportsScreen, SearchScreen, TasksScreen, WorkDefinitionsScreen, WorkflowAdminScreen } from './features/r1/R1Screens'
import { getAuthorizedWorkRecord, getDocumentDownloadUrl, getReport as getR1Report, linkDocument as linkR1Document, listTasks as listR1Tasks, listWorkDefinitions as listR1Definitions, listWorkflowDefinitions as listR1Workflows, searchRecords as searchR1Records, transitionRequest } from './api/r1'

type Locale = 'ar' | 'en'

function App() {
  const [locale, setLocale] = useState<Locale>(initialLocale)
  const [session, setSession] = useState<Session | null>(null)
  const [authChecked, setAuthChecked] = useState(false)
  const [logoutError, setLogoutError] = useState(false)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [view, setView] = useState<AppRoute>(() => routeFromPath(window.location.pathname))
  const [records, setRecords] = useState<WorkRecord[]>([])
  const [recordsLoading, setRecordsLoading] = useState(false)
  const [recordsError, setRecordsError] = useState(false)
  const [detail, setDetail] = useState<WorkRecord | null>(null)
  const [authorizedDetail, setAuthorizedDetail] = useState<import('./api/r1').AuthorizedWorkRecord | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailState, setDetailState] = useState<'ready' | 'unavailable' | 'error'>('ready')
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [notificationsLoading, setNotificationsLoading] = useState(false)
  const [notificationsError, setNotificationsError] = useState(false)
  const [allowedScreens, setAllowedScreens] = useState<Set<string>>(new Set())
  const notificationButtonRef = useRef<HTMLButtonElement>(null)
  const notificationPanelRef = useRef<HTMLDivElement>(null)
  const copy = text[locale]

  useEffect(() => {
    void restoreSession().then(setSession).finally(() => setAuthChecked(true))
  }, [])

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
    try {
      window.localStorage.setItem(LOCALE_KEY, locale)
    } catch {
      // The language still changes when browser preference storage is unavailable.
    }
  }, [locale])

  useEffect(() => {
    const onPopState = () => setView(routeFromPath(window.location.pathname))
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  function expireSession() {
    setSession(null)
    setRecords([])
    setDetail(null)
    setAuthorizedDetail(null)
    setNotifications([])
    setNotificationsOpen(false)
    setSessionExpired(true)
    window.history.replaceState({}, '', '/')
    setView({ name: 'list' })
  }

  async function logout() {
    if (!session) return
    setLogoutError(false)
    try {
      await identityLogout(session.csrf_token)
      clearSessionMetadata()
      setSession(null)
      setRecords([])
      setNotifications([])
      window.history.replaceState({}, '', '/')
      setView({ name: 'list' })
    } catch {
      setLogoutError(true)
    }
  }

  async function refreshRecords(activeSession = session) {
    if (!activeSession) return
    setRecordsLoading(true)
    setRecordsError(false)
    try {
      const collection = await listWorkRecords(activeSession.access_token)
      setRecords(collection.items)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        expireSession()
      } else {
        setRecords([])
        setRecordsError(true)
      }
    } finally {
      setRecordsLoading(false)
    }
  }

  async function refreshNotifications(activeSession = session) {
    if (!activeSession) return
    setNotificationsLoading(true)
    setNotificationsError(false)
    try {
      const collection = await listNotifications(activeSession.access_token)
      setNotifications(collection.items)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        expireSession()
      } else {
        setNotifications([])
        setNotificationsError(true)
      }
    } finally {
      setNotificationsLoading(false)
    }
  }

  useEffect(() => {
    if (!session) return
    void refreshRecords(session)
    void refreshNotifications(session)
    const probes: Array<[string, () => Promise<unknown>]> = [
      ['tasks', () => listR1Tasks(session.access_token)],
      ['work-definitions', () => listR1Definitions(session.access_token)],
      ['workflow-admin', () => listR1Workflows(session.access_token)],
      ['search', () => searchR1Records(session.access_token, '__navigation_probe__')],
      ['reports', () => getR1Report(session.access_token, '019f7000-0000-7000-8000-000000000901')],
    ]
    void Promise.all(probes.map(async ([name, probe]) => { try { await probe(); return name } catch (error) { return error instanceof ApiError && error.status === 403 ? null : name } })).then((values) => setAllowedScreens(new Set(values.filter((value): value is string => value !== null))))
    // Data is reloaded only when a new in-memory session is established.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session])

  useEffect(() => {
    if (!session || view.name !== 'detail') return
    setDetail(null)
    setDetailState('ready')
    setDetailLoading(true)
    void getWorkRecord(session.access_token, view.recordId)
      .then((record) => {
        setDetail(record)
        setDetailState('ready')
      })
      .catch((error: unknown) => {
        setDetail(null)
        if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
          setDetailState('unavailable')
        } else if (error instanceof ApiError && error.status === 401) {
          expireSession()
        } else {
          setDetailState('error')
        }
      })
      .finally(() => setDetailLoading(false))
    // The selected record is loaded only from the authenticated backend.
    // eslint-disable-next-line react-hooks/exhaustive-deps
      }, [session, view])

  useEffect(() => {
    if (!session || view.name !== 'detail') return
    void getAuthorizedWorkRecord(session.access_token, view.recordId)
      .then(setAuthorizedDetail)
      .catch((error: unknown) => {
        setAuthorizedDetail(null)
        if (error instanceof ApiError && error.status === 401) expireSession()
      })
  }, [session, view])

  useEffect(() => {
    if (!notificationsOpen) return
    const panel = notificationPanelRef.current
    const focusable = panel?.querySelectorAll<HTMLElement>('button, a[href], input, textarea, [tabindex]:not([tabindex="-1"])')
    focusable?.[0]?.focus()

    function onKeyDown(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setNotificationsOpen(false)
        window.requestAnimationFrame(() => notificationButtonRef.current?.focus())
        return
      }
      if (event.key !== 'Tab' || !focusable?.length) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [notificationsOpen])

  function navigate(nextView: AppRoute, path: string) {
    window.history.pushState({}, '', path)
    setView(nextView)
  }

  function closeNotifications() {
    setNotificationsOpen(false)
    window.requestAnimationFrame(() => notificationButtonRef.current?.focus())
  }

  if (!authChecked) return <section className="state-panel" aria-live="polite"><p>{copy.signingIn}</p></section>
  if (!session) {
    return (
      <LoginScreen
        locale={locale}
        sessionExpired={sessionExpired}
        onLocaleChange={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}
        onAuthenticated={(authenticatedSession) => {
          setSessionExpired(false)
          setSession(authenticatedSession)
          setView(routeFromPath(window.location.pathname))
        }}
      />
    )
  }

  const facilityName = session.facility === 'facility-a' ? copy.facilityA : session.facility === 'facility-b' ? copy.facilityB : copy.platform
  const unreadNotifications = notifications.filter((notification) => !notification.is_read).length
  const navigationItem = (key: string, label: string, path: string, icon: SidebarNavigationGroup['items'][number]['icon'], active: boolean, route: AppRoute) => (
    { key, label, path, icon, active, onSelect: () => navigate(route, path) }
  )
  const navigationGroups: SidebarNavigationGroup[] = [
    {
      key: 'services',
      label: copy.services,
      icon: <Inbox />,
      items: [
        navigationItem('requests', copy.myRequests, '/', <Inbox />, view.name === 'list' || view.name === 'detail', { name: 'list' }),
        navigationItem('create', copy.newRequest, '/work-records/new', <FilePlus2 />, view.name === 'create', { name: 'create' }),
        ...(allowedScreens.has('tasks') ? [navigationItem('tasks', copy.myTasks, '/tasks', <ClipboardList />, view.name === 'tasks', { name: 'tasks' })] : []),
        ...(allowedScreens.has('search') ? [navigationItem('search', copy.searchScreen, '/search', <Search />, view.name === 'search', { name: 'search' })] : []),
        ...(allowedScreens.has('reports') ? [navigationItem('reports', copy.reportsScreen, '/reports', <BarChart3 />, view.name === 'reports', { name: 'reports' })] : []),
        navigationItem('notifications', copy.notifications, '/notifications', <Bell />, view.name === 'notifications', { name: 'notifications' }),
      ],
    },
    {
      key: 'organization',
      label: copy.organization,
      icon: <Building2 />,
      items: [
        navigationItem('organization-overview', copy.overview, '/admin/organization', <LayoutDashboard />, view.name === 'organization', { name: 'organization' }),
        navigationItem('organization-structure', copy.organizationStructure, '/admin/organization/structure', <Network />, view.name === 'organization-structure', { name: 'organization-structure' }),
        navigationItem('people-assignments', copy.peopleAssignments, '/admin/organization/people', <Users />, view.name === 'people-assignments', { name: 'people-assignments' }),
        navigationItem('temporary-assignments', copy.temporaryAssignments, '/admin/organization/temporary-assignments', <CalendarClock />, view.name === 'temporary-assignments', { name: 'temporary-assignments' }),
        navigationItem('identity-accounts', copy.identityAccounts, '/admin/identity/accounts', <IdCard />, view.name === 'identity-accounts', { name: 'identity-accounts' }),
        navigationItem('organization-import', copy.importReview, '/admin/imports/organization', <FileUp />, view.name === 'organization-import', { name: 'organization-import' }),
      ],
    },
    {
      key: 'authorization',
      label: copy.authorizationGroup,
      icon: <KeyRound />,
      items: [
        navigationItem('roles', copy.roles, '/admin/authorization/roles', <UserCog />, view.name === 'authorization' && view.resource === 'roles', { name: 'authorization', resource: 'roles' }),
        navigationItem('capabilities', copy.capabilities, '/admin/authorization/capabilities', <KeySquare />, view.name === 'authorization' && view.resource === 'capabilities', { name: 'authorization', resource: 'capabilities' }),
        navigationItem('role-assignments', copy.roleAssignments, '/admin/authorization/role-assignments', <UserCheck />, view.name === 'authorization' && view.resource === 'role-assignments', { name: 'authorization', resource: 'role-assignments' }),
        navigationItem('delegations', copy.delegations, '/admin/authorization/delegations', <Handshake />, view.name === 'authorization' && view.resource === 'delegations', { name: 'authorization', resource: 'delegations' }),
        navigationItem('classification-policies', copy.classificationPolicies, '/admin/authorization/classification-policies', <ShieldCheck />, view.name === 'authorization' && view.resource === 'classification-policies', { name: 'authorization', resource: 'classification-policies' }),
        navigationItem('field-access-templates', copy.fieldAccessTemplates, '/admin/authorization/field-access-templates', <KeySquare />, view.name === 'authorization' && view.resource === 'field-access-templates', { name: 'authorization', resource: 'field-access-templates' }),
        navigationItem('supervisory', copy.supervisoryRelationships, '/admin/relationships/supervisory', <GitBranch />, view.name === 'authorization' && view.resource === 'supervisory', { name: 'authorization', resource: 'supervisory' }),
        navigationItem('access-explanation', copy.accessExplanation, '/admin/authorization/explain', <ShieldQuestion />, view.name === 'access-explanation', { name: 'access-explanation' }),
        navigationItem('personal-access', copy.personalAccess, '/me/access', <UserRound />, view.name === 'access-context', { name: 'access-context' }),
      ],
    },
    {
      key: 'workflow',
      label: copy.workflowGroup,
      icon: <Workflow />,
      items: [
        navigationItem('workflow-day2', copy.workflowTasks, '/admin/workflow/day2', <Route />, view.name === 'workflow-day2', { name: 'workflow-day2' }),
        ...(allowedScreens.has('work-definitions') ? [navigationItem('work-definitions', copy.workDefinitions, '/admin/work-definitions', <FileCog />, view.name === 'work-definitions', { name: 'work-definitions' })] : []),
        ...(allowedScreens.has('workflow-admin') ? [navigationItem('workflow-admin', copy.workflowAdmin, '/admin/workflow', <Settings2 />, view.name === 'workflow-admin', { name: 'workflow-admin' })] : []),
      ],
    },
  ]

  return (
    <>
      <AppShell
        locale={locale}
        copy={copy}
        facilityName={facilityName}
        navigationGroups={navigationGroups}
        unreadNotifications={unreadNotifications}
        notificationButtonRef={notificationButtonRef}
        notificationsOpen={notificationsOpen}
        onLocaleChange={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}
        onNotificationsToggle={() => setNotificationsOpen((open) => !open)}
        onLogout={() => void logout()}
      >
        {logoutError && <div className="state-panel" role="alert"><p>{locale === 'ar' ? 'تعذر تسجيل الخروج من الخادم.' : 'The server could not complete sign out.'}</p><button type="button" className="secondary-button" onClick={() => void logout()}>{copy.retry}</button></div>}
        {view.name === 'list' && (
          <RequestDashboard
            locale={locale}
            records={records}
            notifications={notifications}
            loading={recordsLoading}
            error={recordsError}
            notificationsLoading={notificationsLoading}
            notificationsError={notificationsError}
            facilityName={facilityName}
            onRetry={() => void refreshRecords()}
            onCreate={() => navigate({ name: 'create' }, '/work-records/new')}
            onSelect={(recordId) => navigate({ name: 'detail', recordId }, `/work-records/${recordId}`)}
            onOpenNotifications={() => setNotificationsOpen(true)}
          />
        )}
        {view.name === 'list' && <AdaptiveDashboard locale={locale} token={session.access_token} scopeId={session.facility ?? ''} onSessionExpired={expireSession} />}
        {view.name === 'create' && (
          <RequestForm
            locale={locale}
            token={session.access_token}
            onSessionExpired={expireSession}
            onCreated={(record) => {
              setRecords((current) => [record, ...current.filter((item) => item.id !== record.id)])
              void refreshRecords()
            }}
            onBack={() => navigate({ name: 'list' }, '/')}
          />
        )}
        {view.name === 'detail' && (
          <RequestDetail
            locale={locale}
            token={session.access_token}
            record={detail}
            loading={detailLoading}
            state={detailState}
            authorizedRecord={authorizedDetail}
            onRetry={() => setView({ ...view })}
            onSessionExpired={expireSession}
          />
        )}
        {view.name === 'not-found' && (
          <section className="state-panel" aria-labelledby="not-found-heading">
            <h1 id="not-found-heading">{copy.notFound}</h1>
            <p>{copy.notFoundBody}</p>
            <a href="/" className="primary-link" onClick={(event) => {
              event.preventDefault()
              navigate({ name: 'list' }, '/')
            }}>{copy.backToRequests}</a>
          </section>
        )}
        {view.name === 'organization' && (
          <OrganizationOverview locale={locale} token={session.access_token} onSessionExpired={expireSession} />
        )}
        {view.name === 'organization-structure' && (
          <OrganizationStructure locale={locale} token={session.access_token} onSessionExpired={expireSession} />
        )}
        {view.name === 'people-assignments' && (
          <PeopleAssignments locale={locale} token={session.access_token} onSessionExpired={expireSession} />
        )}
        {view.name === 'temporary-assignments' && (
          <TemporaryAssignments locale={locale} token={session.access_token} onSessionExpired={expireSession} />
        )}
        {view.name === 'identity-accounts' && (
          <IdentityAccounts locale={locale} token={session.access_token} onSessionExpired={expireSession} />
        )}
        {view.name === 'organization-import' && (
          <ImportReview
            locale={locale}
            token={session.access_token}
            jobId={view.jobId}
            onSessionExpired={expireSession}
            onJobOpen={(jobId) => navigate({ name: 'organization-import', jobId }, `/admin/imports/organization/${jobId}`)}
          />
        )}
        {view.name === 'authorization' && <AuthorizationAdmin locale={locale} token={session.access_token} resource={view.resource} onSessionExpired={expireSession} />}
        {view.name === 'access-context' && <AccessContext locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'access-explanation' && <AccessExplanation locale={locale} token={session.access_token} decisionId={view.decisionId} onSessionExpired={expireSession} />}
        {view.name === 'workflow-day2' && <Day2Workflow locale={locale} session={session} onSessionExpired={expireSession} />}
        {view.name === 'tasks' && <TasksScreen locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'work-definitions' && <WorkDefinitionsScreen locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'workflow-admin' && <WorkflowAdminScreen locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'search' && <SearchScreen locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'reports' && <ReportsScreen locale={locale} token={session.access_token} onSessionExpired={expireSession} />}
        {view.name === 'notifications' && <NotificationsScreen locale={locale} token={session.access_token} notifications={notifications} onSessionExpired={expireSession} onRead={() => void refreshNotifications()} onOpen={(recordId) => navigate({ name: 'detail', recordId }, `/work-records/${recordId}`)} />}
      </AppShell>

      {notificationsOpen && (
        <div className="panel-backdrop" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeNotifications()
        }}>
          <div
            id="notification-panel"
            className="notification-panel"
            ref={notificationPanelRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="notification-heading"
          >
            <div className="panel-heading">
              <h2 id="notification-heading">{copy.notifications}</h2>
              <button type="button" className="quiet-button" onClick={closeNotifications}>{copy.closeNotifications}</button>
            </div>
            <NotificationList locale={locale} items={notifications} loading={notificationsLoading} error={notificationsError} />
            <button type="button" className="secondary-button panel-refresh" disabled={notificationsLoading} onClick={() => void refreshNotifications()}>
              {notificationsLoading ? copy.refreshingNotifications : copy.refreshNotifications}
            </button>
          </div>
        </div>
      )}
    </>
  )
}



function RequestDashboard({
  locale,
  records,
  notifications,
  loading,
  error,
  notificationsLoading,
  notificationsError,
  facilityName,
  onRetry,
  onCreate,
  onSelect,
  onOpenNotifications,
}: {
  locale: Locale
  records: WorkRecord[]
  notifications: Notification[]
  loading: boolean
  error: boolean
  notificationsLoading: boolean
  notificationsError: boolean
  facilityName: string
  onRetry: () => void
  onCreate: () => void
  onSelect: (recordId: string) => void
  onOpenNotifications: () => void
}) {
  const copy = text[locale]
  const formatter = new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB')
  const activeStatuses = new Set(['submitted', 'in_review', 'returned'])
  const completedStatuses = new Set(['approved', 'completed'])
  const activeCount = records.filter((record) => activeStatuses.has(record.status)).length
  const completedCount = records.filter((record) => completedStatuses.has(record.status)).length
  const otherCount = Math.max(0, records.length - activeCount - completedCount)
  const unreadCount = notifications.filter((notification) => !notification.is_read).length
  const metricValue = (value: number) => loading || error ? '—' : formatter.format(value)
  const metrics = [
    { label: copy.loadedRequests, value: metricValue(records.length), source: copy.currentPageSource, tone: 'primary' },
    { label: copy.activeRequests, value: metricValue(activeCount), source: copy.currentPageSource, tone: 'accent' },
    { label: copy.completedRequests, value: metricValue(completedCount), source: copy.currentPageSource, tone: 'success' },
    { label: copy.unreadNotifications, value: notificationsLoading || notificationsError ? '—' : formatter.format(unreadCount), source: copy.loadedNotificationSource, tone: 'muted' },
  ] as const
  const statusGroups = [
    { label: copy.activeStatus, count: activeCount, tone: 'accent' },
    { label: copy.completedStatus, count: completedCount, tone: 'success' },
    { label: copy.otherStatus, count: otherCount, tone: 'muted' },
  ] as const

  return (
    <div className="dashboard-page">
      <section className="dashboard-welcome" aria-labelledby="dashboard-heading">
        <div>
          <h1 id="dashboard-heading">{copy.dashboardWelcome}</h1>
          <p><span className="dashboard-scope-badge">{facilityName}</span>{copy.dashboardSummary}</p>
        </div>
        <span className="dashboard-range"><CalendarDays aria-hidden="true" />{copy.dashboardRange}</span>
      </section>

      <section aria-labelledby="overview-heading">
        <div className="dashboard-section-heading">
          <h2 id="overview-heading">{copy.overview}</h2>
        </div>
        <div className="dashboard-kpi-grid" aria-label={copy.overview}>
          {metrics.map((metric) => (
            <article className="dashboard-kpi" key={metric.label}>
              <span className="dashboard-kpi-label"><span className="dashboard-kpi-dot" data-tone={metric.tone} />{metric.label}</span>
              <strong>{metric.value}</strong>
              <small>{metric.source}</small>
            </article>
          ))}
        </div>
      </section>

      <section aria-labelledby="analytics-heading">
        <div className="dashboard-section-heading">
          <h2 id="analytics-heading">{copy.analytics}</h2>
        </div>
        <div className="dashboard-panel-grid">
          <article className="dashboard-panel" aria-labelledby="timeline-heading">
            <div className="dashboard-panel-heading"><h3 id="timeline-heading">{copy.timelineTitle}</h3></div>
            <div className="dashboard-empty-state">
              <span className="dashboard-empty-icon" aria-hidden="true"><FolderSearch /></span>
              <strong>{copy.timelineUnavailableTitle}</strong>
              <p>{copy.timelineUnavailableBody}</p>
            </div>
          </article>

          <article className="dashboard-panel" aria-labelledby="status-heading">
            <div className="dashboard-panel-heading"><h3 id="status-heading">{copy.statusBreakdown}</h3></div>
            {loading && <div className="skeleton-list" aria-label={copy.loadingRequests}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
            {!loading && error && <div className="dashboard-inline-error" role="alert"><p>{copy.listError}</p><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></div>}
            {!loading && !error && records.length === 0 && (
              <div className="dashboard-empty-state">
                <span className="dashboard-empty-icon" aria-hidden="true"><FolderSearch /></span>
                <strong>{copy.noStatusTitle}</strong>
                <p>{copy.noStatusBody}</p>
              </div>
            )}
            {!loading && !error && records.length > 0 && (
              <div className="dashboard-status-list">
                {statusGroups.map((group) => {
                  const percentage = Math.round((group.count / records.length) * 100)
                  return (
                    <div className="dashboard-status-row" key={group.label}>
                      <div><span>{group.label}</span><strong>{formatter.format(group.count)}</strong></div>
                      <div className="dashboard-progress" role="progressbar" aria-label={group.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percentage}>
                        <span data-tone={group.tone} style={{ inlineSize: `${percentage}%` }} />
                      </div>
                      <small>{formatter.format(percentage)}%</small>
                    </div>
                  )
                })}
              </div>
            )}
          </article>
        </div>
      </section>

      <section aria-labelledby="activity-heading">
        <div className="dashboard-section-heading">
          <h2 id="activity-heading">{copy.recentActivity}</h2>
        </div>
        <div className="dashboard-panel-grid">
          <article className="dashboard-panel" aria-labelledby="requests-heading">
            <div className="dashboard-panel-heading">
              <h3 id="requests-heading">{copy.myRequests}</h3>
              <a href="/work-records/new" className="dashboard-panel-link" onClick={(event) => { event.preventDefault(); onCreate() }}>{copy.newRequest}</a>
            </div>
            {loading && <div className="skeleton-list" aria-label={copy.loadingRequests}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
            {!loading && error && <div className="dashboard-inline-error" role="alert"><p>{copy.listError}</p><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></div>}
            {!loading && !error && records.length === 0 && (
              <div className="dashboard-empty-state">
                <span className="dashboard-empty-icon" aria-hidden="true"><FolderSearch /></span>
                <strong>{copy.emptyTitle}</strong>
                <p>{copy.emptyBody}</p>
                <button type="button" className="primary-button dashboard-empty-action" onClick={onCreate}>{copy.submit}</button>
              </div>
            )}
            {!loading && !error && records.length > 0 && (
              <ul className="request-list dashboard-request-list">
                {records.slice(0, 4).map((record) => (
                  <li key={record.id}>
                    <a href={`/work-records/${record.id}`} onClick={(event) => { event.preventDefault(); onSelect(record.id) }}>
                      <span className="request-copy">
                        <strong>{record.payload.title ?? copy.noDescription}</strong>
                        <span>{record.payload.description ?? copy.noDescription}</span>
                      </span>
                      <span className="request-meta">
                        <span className="status-badge">{recordStatusText[locale][record.status]}</span>
                        <time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time>
                      </span>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </article>

          <article className="dashboard-panel" aria-labelledby="notifications-dashboard-heading">
            <div className="dashboard-panel-heading">
              <h3 id="notifications-dashboard-heading">{copy.notifications}</h3>
              <button type="button" className="dashboard-panel-link" onClick={onOpenNotifications}>{copy.openNotifications}</button>
            </div>
            {notificationsLoading && <div className="skeleton-list" aria-label={copy.refreshingNotifications}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
            {!notificationsLoading && notificationsError && <p role="alert" className="field-error">{copy.notificationError}</p>}
            {!notificationsLoading && !notificationsError && notifications.length === 0 && (
              <div className="dashboard-empty-state">
                <span className="dashboard-empty-icon" aria-hidden="true"><BellRing /></span>
                <strong>{copy.noNotifications}</strong>
                <p>{copy.noNotificationBody}</p>
              </div>
            )}
            {!notificationsLoading && !notificationsError && notifications.length > 0 && (
              <ul className="dashboard-notification-list">
                {notifications.slice(0, 4).map((notification) => (
                  <li key={notification.id}>
                    <span className="dashboard-notification-copy"><strong>{notification.title}</strong><small>{notification.is_read ? copy.read : copy.unread}</small></span>
                    <time dateTime={notification.created_at}>{formatDate(notification.created_at, locale)}</time>
                  </li>
                ))}
              </ul>
            )}
          </article>
        </div>
      </section>
    </div>
  )
}

function RequestForm({ locale, token, onSessionExpired, onCreated, onBack }: {
  locale: Locale
  token: string
  onSessionExpired: () => void
  onCreated: (record: WorkRecord) => void
  onBack: () => void
}) {
  const copy = text[locale]
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [errors, setErrors] = useState<{ title?: boolean; description?: boolean }>({})
  const [formError, setFormError] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [created, setCreated] = useState<WorkRecord | null>(null)
  const summaryRef = useRef<HTMLDivElement>(null)
  const successRef = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    if (created) successRef.current?.focus()
  }, [created])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const nextErrors = {
      title: title.trim() ? undefined : true,
      description: description.trim() ? undefined : true,
    }
    setErrors(nextErrors)
    setFormError(false)
    if (nextErrors.title || nextErrors.description) {
      window.requestAnimationFrame(() => summaryRef.current?.focus())
      return
    }
    setSubmitting(true)
    try {
      const record = await createWorkRecord(token, {
        work_definition_code: 'request',
        title: title.trim(),
        description: description.trim(),
      })
      setCreated(record)
      onCreated(record)
      window.requestAnimationFrame(() => successRef.current?.focus())
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        onSessionExpired()
        return
      }
      if (error instanceof ApiError && error.problem.errors?.length) {
        const fieldErrors: { title?: boolean; description?: boolean } = {}
        for (const fieldError of error.problem.errors) {
          if (fieldError.pointer.endsWith('/title')) fieldErrors.title = true
          if (fieldError.pointer.endsWith('/description')) fieldErrors.description = true
        }
        setErrors(fieldErrors)
      }
      setFormError(true)
      window.requestAnimationFrame(() => summaryRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  if (created) {
    return (
      <section className="success-panel" aria-labelledby="success-heading" aria-live="polite">
        <h1 id="success-heading" ref={successRef} tabIndex={-1}>{copy.success}</h1>
        <p className="submitted-title">{created.payload.title}</p>
        <p>{copy.successBody}</p>
        <a href="/" className="primary-link" onClick={(event) => { event.preventDefault(); onBack() }}>{copy.backToRequests}</a>
      </section>
    )
  }

  return (
    <section className="request-form-section" aria-labelledby="new-request-heading">
      <h1 id="new-request-heading">{locale === 'ar' ? 'إرسال طلب جديد' : 'Submit a new request'}</h1>
      {(formError || errors.title || errors.description) && (
        <div className="error-summary" role="alert" tabIndex={-1} ref={summaryRef}>
          <strong>{copy.validationError}</strong>
          {formError && <p>{copy.submitError}</p>}
        </div>
      )}
      <form onSubmit={(event) => void submit(event)} noValidate>
        <div className="field">
          <label htmlFor="request-title">{copy.requestTitle}</label>
          <input
            id="request-title"
            value={title}
            required
            aria-required="true"
            aria-invalid={Boolean(errors.title)}
            aria-describedby={`request-title-help${errors.title ? ' request-title-error' : ''}`}
            onChange={(event) => setTitle(event.target.value)}
          />
          <p id="request-title-help" className="field-help">{copy.titleHelp}</p>
          {errors.title && <p id="request-title-error" className="field-error">{copy.titleRequired}</p>}
        </div>
        <div className="field">
          <label htmlFor="request-description">{copy.requestDescription}</label>
          <textarea
            id="request-description"
            value={description}
            rows={6}
            required
            aria-required="true"
            aria-invalid={Boolean(errors.description)}
            aria-describedby={`request-description-help${errors.description ? ' request-description-error' : ''}`}
            onChange={(event) => setDescription(event.target.value)}
          />
          <p id="request-description-help" className="field-help">{copy.descriptionHelp}</p>
          {errors.description && <p id="request-description-error" className="field-error">{copy.descriptionRequired}</p>}
        </div>
        <button type="submit" className="primary-button" disabled={submitting}>{submitting ? copy.submitting : copy.submit}</button>
      </form>
    </section>
  )
}

function RequestDetail({ locale, token, record, loading, state, authorizedRecord, onRetry, onSessionExpired }: {
  locale: Locale
  token: string
  record: WorkRecord | null
  loading: boolean
  state: 'ready' | 'unavailable' | 'error'
  authorizedRecord?: import('./api/r1').AuthorizedWorkRecord | null
  onRetry: () => void
  onSessionExpired: () => void
}) {
  const copy = text[locale]
  const [busy, setBusy] = useState(false)
  const [actionState, setActionState] = useState<'idle' | 'done' | 'error' | 'stale'>('idle')
  const [attachedDocumentId, setAttachedDocumentId] = useState<string | null>(null)
  if (loading) return <section><h1>{copy.loadingDetail}</h1></section>
  if (state === 'unavailable') return <section className="state-panel"><h1>{copy.unavailable}</h1></section>
  if (state === 'error') return <section className="state-panel" role="alert"><h1>{copy.detailError}</h1><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></section>
  if (!record) return <section><h1>{copy.loadingDetail}</h1></section>
  async function act(action: 'submit' | 'return' | 'complete') {
    if (!record) return
    setBusy(true); setActionState('idle')
    try { await transitionRequest(token, record.id, action, record.lock_version); setActionState('done'); onRetry() }
    catch (error) { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  async function attach(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!record) return
    const documentId = String(new FormData(event.currentTarget).get('document_id') ?? '')
    setBusy(true); setActionState('idle')
    try { await linkR1Document(token, record.id, documentId); setAttachedDocumentId(documentId); setActionState('done'); event.currentTarget.reset(); onRetry() }
    catch (error) { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') }
    finally { setBusy(false) }
  }
  if (authorizedRecord) {
    return <RecordProjection
      record={authorizedRecord}
      locale={locale}
      onRefresh={onRetry}
      onAction={(action) => {
        if (action === 'submit' || action === 'return' || action === 'complete') void act(action)
      }}
    />
  }
  return (
    <article className="detail-panel">
      <h1>{record.payload.title ?? copy.noDescription}</h1>
      <p>{record.payload.description ?? copy.noDescription}</p>
      <div className="detail-meta"><span className="status-badge">{copy.submitted}</span><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time></div>
      <section className="surface-card" aria-labelledby="record-actions-heading"><h2 id="record-actions-heading">{locale === 'ar' ? 'إجراءات الطلب' : 'Request actions'}</h2><div className="table-actions"><button disabled={busy} type="button" className="primary-button" onClick={() => void act('submit')}>{locale === 'ar' ? 'إرسال' : 'Submit'}</button><button disabled={busy} type="button" className="secondary-button" onClick={() => void act('return')}>{locale === 'ar' ? 'إعادة' : 'Return'}</button><button disabled={busy} type="button" className="primary-button" onClick={() => void act('complete')}>{locale === 'ar' ? 'إكمال' : 'Complete'}</button></div></section>
      <section className="surface-card" aria-labelledby="record-documents-heading"><h2 id="record-documents-heading">{locale === 'ar' ? 'المستندات المرتبطة' : 'Linked documents'}</h2><p>{locale === 'ar' ? 'لا يصبح المستند قابلاً للربط والتنزيل حتى يمر بالحجر والفحص ويصبح متاحاً.' : 'A document cannot be linked or downloaded until quarantine and scanning finish and it becomes available.'}</p><form className="inline-form" onSubmit={(event) => void attach(event)}><label>{locale === 'ar' ? 'معرّف المستند المتاح' : 'Available document ID'}<input name="document_id" required pattern="[0-9a-f-]{36}" /></label><button disabled={busy} className="primary-button">{locale === 'ar' ? 'إرفاق' : 'Attach'}</button></form>{attachedDocumentId && <button type="button" className="secondary-button" onClick={() => void getDocumentDownloadUrl(token, attachedDocumentId).then((url) => window.location.assign(url)).catch((error) => { if (error instanceof ApiError && error.status === 401) onSessionExpired(); else setActionState(error instanceof ApiError && (error.status === 409 || error.status === 412) ? 'stale' : 'error') })}>{locale === 'ar' ? 'تنزيل عبر قرار الوصول' : 'Download through access decision'}</button>}</section>
      <section className="surface-card" aria-labelledby="record-timeline-heading"><h2 id="record-timeline-heading">{locale === 'ar' ? 'الخط الزمني للنشاط' : 'Activity timeline'}</h2><ol><li><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time> — {String(record.status)}</li></ol></section>
      {actionState !== 'idle' && <p className={actionState === 'done' ? 'status-message' : 'error-summary'} role="status">{actionState === 'done' ? (locale === 'ar' ? 'اكتمل الإجراء.' : 'Action completed.') : actionState === 'stale' ? (locale === 'ar' ? 'البيانات قديمة؛ تم طلب التحديث.' : 'The data is stale; refresh requested.') : (locale === 'ar' ? 'تعذر تنفيذ الإجراء.' : 'The action failed.')}</p>}
    </article>
  )
}

export default App
