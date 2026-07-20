import { useEffect, useRef, useState } from 'react'
import {
  BarChart3,
  Bell,
  Building2,
  CalendarClock,
  ClipboardList,
  FileCog,
  FilePlus2,
  FileUp,
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
import { text, LOCALE_KEY, initialLocale } from './app/copy'
import { LoginScreen } from './app/LoginScreen'
import { NotificationList } from './app/NotificationList'
import { OrganizationOverview } from './features/organization/OrganizationOverview'
import { OrganizationStructure } from './features/organization/OrganizationStructure'
import { PeopleAssignments } from './features/organization/PeopleAssignments'
import { TemporaryAssignments } from './features/organization/TemporaryAssignments'
import { IdentityAccounts } from './features/identity/IdentityAccounts'
import { ImportReview } from './features/imports/ImportReview'
import { AccessExplanation, AuthorizationAdmin } from './features/authorization/AuthorizationAdmin'
import { AccessContext } from './features/authorization/AccessContext'
import { Day2Workflow } from './features/workflow/Day2Workflow'
import { RequestDashboard } from './features/requests/RequestDashboard'
import { RequestForm } from './features/requests/RequestForm'
import { RequestDetail } from './features/requests/RequestDetail'
import { AdaptiveDashboard, NotificationsScreen, ReportsScreen, SearchScreen, TasksScreen, WorkDefinitionsScreen, WorkflowAdminScreen } from './features/r1/R1Screens'
import { getAuthorizedWorkRecord, getReport as getR1Report, listTasks as listR1Tasks, listWorkDefinitions as listR1Definitions, listWorkflowDefinitions as listR1Workflows, searchRecords as searchR1Records } from './api/r1'

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



export default App
