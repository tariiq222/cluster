import { type FormEvent, useEffect, useRef, useState } from 'react'
import { BellRing, CalendarDays, FolderSearch } from 'lucide-react'

import { AppShell } from './app/AppShell'

import {
  ApiError,
  createWorkRecord,
  getWorkRecord,
  listNotifications,
  listWorkRecords,
  login,
  type Notification,
  type Session,
  type WorkRecord,
} from './api'
import { primaryRoutes, routeFromPath, type AppRoute } from './shell/routes'
import { OrganizationOverview } from './features/organization/OrganizationOverview'
import { OrganizationStructure } from './features/organization/OrganizationStructure'
import { PeopleAssignments } from './features/organization/PeopleAssignments'
import { TemporaryAssignments } from './features/organization/TemporaryAssignments'
import { IdentityAccounts } from './features/identity/IdentityAccounts'
import { ImportReview } from './features/imports/ImportReview'
import { AccessExplanation, AuthorizationAdmin } from './features/authorization/AuthorizationAdmin'

type Locale = 'ar' | 'en'

const text = {
  ar: {
    platform: 'منصة التجمع الصحي الثالث',
    switchLanguage: 'English',
    signIn: 'تسجيل الدخول',
    username: 'اسم المستخدم',
    password: 'كلمة المرور',
    signingIn: 'جارٍ تسجيل الدخول…',
    loginError: 'تعذر تسجيل الدخول. تحقق من بيانات الدخول ثم أعد المحاولة.',
    requiredLogin: 'أكمل اسم المستخدم وكلمة المرور.',
    currentFacility: 'نطاق المنشأة الحالية',
    myRequests: 'طلباتي',
    newRequest: 'طلب جديد',
    organization: 'التنظيم',
    organizationStructure: 'الهيكل والمناصب',
    peopleAssignments: 'الأشخاص والتكليفات',
    temporaryAssignments: 'التكليفات المؤقتة',
    identityAccounts: 'حسابات الهوية',
    administrationNavigation: 'تنقل الإدارة',
    importReview: 'مراجعة الاستيراد',
    roles: 'الأدوار',
    capabilities: 'الصلاحيات',
    roleAssignments: 'إسنادات الأدوار',
    delegations: 'التفويضات',
    supervisoryRelationships: 'العلاقات الإشرافية',
    accessExplanation: 'شرح قرار الوصول',
    notifications: 'الإشعارات',
    closeNotifications: 'إغلاق الإشعارات',
    logout: 'تسجيل الخروج',
    loadingRequests: 'جارٍ تحميل طلباتك…',
    listError: 'تعذر تحميل طلباتك. أعد المحاولة.',
    retry: 'إعادة المحاولة',
    emptyTitle: 'لا توجد طلبات بعد',
    emptyBody: 'أنشئ أول طلب للمنشأة الحالية لبدء المسار.',
    noDescription: 'لا يوجد وصف',
    submitted: 'تم الإرسال',
    requestTitle: 'عنوان الطلب (مطلوب)',
    requestDescription: 'وصف الطلب (مطلوب)',
    titleHelp: 'اكتب عنواناً موجزاً يوضح الغرض من الطلب.',
    descriptionHelp: 'أضف التفاصيل اللازمة لمعالجة الطلب.',
    submit: 'إرسال الطلب',
    submitting: 'جارٍ إرسال الطلب…',
    validationError: 'أكمل الحقول المطلوبة ثم أعد الإرسال.',
    titleRequired: 'عنوان الطلب مطلوب.',
    descriptionRequired: 'وصف الطلب مطلوب.',
    submitError: 'لم يتم حفظ الطلب. تحقق من اتصالك الداخلي ثم أعد المحاولة.',
    success: 'تم إرسال طلبك',
    successBody: 'حُفظ الطلب. سيظهر إشعار داخلي هنا بعد اكتمال المعالجة.',
    backToRequests: 'العودة إلى طلباتي',
    loadingDetail: 'جارٍ تحميل الطلب…',
    detailError: 'تعذر تحميل الطلب. أعد المحاولة.',
    unavailable: 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
    refreshNotifications: 'تحديث الإشعارات',
    refreshingNotifications: 'جارٍ تحديث الإشعارات…',
    notificationError: 'تعذر تحميل الإشعارات. أعد المحاولة.',
    noNotifications: 'لا توجد إشعارات جديدة',
    read: 'مقروء',
    unread: 'غير مقروء',
    sessionExpired: 'انتهت جلستك. سجّل الدخول للمتابعة.',
    notFound: 'الصفحة غير موجودة',
    notFoundBody: 'تحقق من الرابط أو عد إلى طلباتك.',
    openNavigation: 'فتح القائمة الرئيسية',
    closeNavigation: 'إغلاق القائمة الرئيسية',
    navigationTitle: 'التنقل الرئيسي',
    services: 'الخدمات',
    platformUser: 'مستخدم المنصة',
    internalSystem: 'نظام العمليات الداخلي',
    rightsReserved: 'جميع الحقوق محفوظة © 2026',
    organizationName: 'مجمع إرادة والصحة النفسية',
    officeName: 'مكتب إدارة المشاريع والتحول المؤسسي',
    ownerName: 'طارق الوليدي',
    collapseNavigation: 'طي القائمة الجانبية',
    expandNavigation: 'توسيع القائمة الجانبية',
    facilityA: 'المنشأة أ',
    facilityB: 'المنشأة ب',
    dashboardWelcome: 'مرحباً بك',
    dashboardSummary: 'إليك ملخص الطلبات والإشعارات ضمن نطاق المنشأة الحالية.',
    dashboardRange: 'حد العرض: 20 طلباً',
    overview: 'نظرة عامة',
    loadedRequests: 'الطلبات المحمّلة',
    activeRequests: 'طلبات قيد الإجراء',
    completedRequests: 'طلبات مكتملة',
    unreadNotifications: 'غير المقروءة المحمّلة',
    currentPageSource: 'من النتائج المحمّلة',
    loadedNotificationSource: 'من إشعارات المستخدم المحمّلة',
    analytics: 'التحليلات',
    timelineTitle: 'التحليلات الزمنية',
    timelineUnavailableTitle: 'لا تتوفر سلسلة زمنية بعد',
    timelineUnavailableBody: 'ستظهر اتجاهات الطلبات عند نشر مصدر التجميع الزمني المعتمد.',
    statusBreakdown: 'الطلبات حسب الحالة',
    activeStatus: 'قيد الإجراء',
    completedStatus: 'مكتملة',
    otherStatus: 'حالات أخرى',
    noStatusTitle: 'لا توجد حالات لعرضها',
    noStatusBody: 'أنشئ أول طلب لتظهر هنا قراءة موجزة لتوزيع الحالات.',
    recentActivity: 'النشاط المتاح',
    openNotifications: 'عرض الإشعارات',
    noNotificationBody: 'ستظهر هنا أحدث التنبيهات المرتبطة بطلبات المنشأة.',
    noData: 'لا توجد بيانات',
  },
  en: {
    platform: 'Third Health Cluster Platform',
    switchLanguage: 'العربية',
    signIn: 'Sign in',
    username: 'Username',
    password: 'Password',
    signingIn: 'Signing in…',
    loginError: 'We could not sign you in. Check your credentials and try again.',
    requiredLogin: 'Complete the username and password fields.',
    currentFacility: 'Current facility scope',
    myRequests: 'My requests',
    newRequest: 'New request',
    organization: 'Organization',
    organizationStructure: 'Structure and positions',
    peopleAssignments: 'People and assignments',
    temporaryAssignments: 'Temporary assignments',
    identityAccounts: 'Identity accounts',
    administrationNavigation: 'Administration navigation',
    importReview: 'Import review',
    roles: 'Roles',
    capabilities: 'Capabilities',
    roleAssignments: 'Role assignments',
    delegations: 'Delegations',
    supervisoryRelationships: 'Supervisory relationships',
    accessExplanation: 'Access explanation',
    notifications: 'Notifications',
    closeNotifications: 'Close notifications',
    logout: 'Sign out',
    loadingRequests: 'Loading your requests…',
    listError: 'We could not load your requests. Try again.',
    retry: 'Try again',
    emptyTitle: 'No requests yet',
    emptyBody: 'Create the first request for your current facility to begin.',
    noDescription: 'No description',
    submitted: 'Submitted',
    requestTitle: 'Request title (required)',
    requestDescription: 'Request description (required)',
    titleHelp: 'Write a short title that explains the purpose of the request.',
    descriptionHelp: 'Add the details needed to process the request.',
    submit: 'Submit request',
    submitting: 'Submitting request…',
    validationError: 'Complete the required fields, then submit again.',
    titleRequired: 'Request title is required.',
    descriptionRequired: 'Request description is required.',
    submitError: 'The request was not saved. Check your internal connection and try again.',
    success: 'Your request was submitted',
    successBody: 'The request was saved. An in-app notification will appear here after processing completes.',
    backToRequests: 'Back to my requests',
    loadingDetail: 'Loading request…',
    detailError: 'We could not load the request. Try again.',
    unavailable: 'You cannot open this request, or it is no longer available.',
    refreshNotifications: 'Refresh notifications',
    refreshingNotifications: 'Refreshing notifications…',
    notificationError: 'We could not load notifications. Try again.',
    noNotifications: 'No new notifications',
    read: 'Read',
    unread: 'Unread',
    sessionExpired: 'Your session has expired. Sign in to continue.',
    notFound: 'Page not found',
    notFoundBody: 'Check the address or return to your requests.',
    openNavigation: 'Open primary navigation',
    closeNavigation: 'Close primary navigation',
    navigationTitle: 'Primary navigation',
    services: 'Services',
    platformUser: 'Platform user',
    internalSystem: 'Internal operations system',
    rightsReserved: 'All rights reserved © 2026',
    organizationName: 'Eradah and Mental Health Complex',
    officeName: 'Project Management and Institutional Transformation Office',
    ownerName: 'Tariq Alwalidi',
    collapseNavigation: 'Collapse sidebar',
    expandNavigation: 'Expand sidebar',
    facilityA: 'Facility A',
    facilityB: 'Facility B',
    dashboardWelcome: 'Welcome',
    dashboardSummary: 'Here is a summary of requests and notifications in the current facility scope.',
    dashboardRange: 'Display limit: 20 requests',
    overview: 'Overview',
    loadedRequests: 'Loaded requests',
    activeRequests: 'Requests in progress',
    completedRequests: 'Completed requests',
    unreadNotifications: 'Loaded unread notifications',
    currentPageSource: 'From the loaded results',
    loadedNotificationSource: 'From the user notifications loaded',
    analytics: 'Analytics',
    timelineTitle: 'Timeline analytics',
    timelineUnavailableTitle: 'No timeline is available yet',
    timelineUnavailableBody: 'Request trends will appear when the governed aggregation source is published.',
    statusBreakdown: 'Requests by status',
    activeStatus: 'In progress',
    completedStatus: 'Completed',
    otherStatus: 'Other statuses',
    noStatusTitle: 'No statuses to display',
    noStatusBody: 'Create the first request to see a concise status distribution here.',
    recentActivity: 'Available activity',
    openNotifications: 'View notifications',
    noNotificationBody: 'The latest alerts related to facility requests will appear here.',
    noData: 'No data',
  },
} as const

const recordStatusText = {
  ar: {
    draft: 'مسودة',
    submitted: 'تم الإرسال',
    in_review: 'قيد المراجعة',
    returned: 'معاد للتعديل',
    approved: 'معتمد',
    rejected: 'مرفوض',
    completed: 'مكتمل',
    cancelled: 'ملغي',
    archived: 'مؤرشف',
  },
  en: {
    draft: 'Draft',
    submitted: 'Submitted',
    in_review: 'In review',
    returned: 'Returned',
    approved: 'Approved',
    rejected: 'Rejected',
    completed: 'Completed',
    cancelled: 'Cancelled',
    archived: 'Archived',
  },
} as const

const LOCALE_KEY = 'cluster.presentation-locale'

function initialLocale(): Locale {
  try {
    return window.localStorage.getItem(LOCALE_KEY) === 'en' ? 'en' : 'ar'
  } catch {
    return 'ar'
  }
}

function App() {
  const [locale, setLocale] = useState<Locale>(initialLocale)
  const [session, setSession] = useState<Session | null>(null)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [view, setView] = useState<AppRoute>(() => routeFromPath(window.location.pathname))
  const [records, setRecords] = useState<WorkRecord[]>([])
  const [recordsLoading, setRecordsLoading] = useState(false)
  const [recordsError, setRecordsError] = useState(false)
  const [detail, setDetail] = useState<WorkRecord | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailState, setDetailState] = useState<'ready' | 'unavailable' | 'error'>('ready')
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [notificationsLoading, setNotificationsLoading] = useState(false)
  const [notificationsError, setNotificationsError] = useState(false)
  const notificationButtonRef = useRef<HTMLButtonElement>(null)
  const notificationPanelRef = useRef<HTMLDivElement>(null)
  const copy = text[locale]
  const adminView = ['organization', 'organization-structure', 'people-assignments', 'temporary-assignments', 'identity-accounts', 'organization-import'].includes(view.name)

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
    setNotifications([])
    setNotificationsOpen(false)
    setSessionExpired(true)
    window.history.replaceState({}, '', '/')
    setView({ name: 'list' })
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

  const facilityName = session.facility === 'facility-a' ? copy.facilityA : copy.facilityB
  const unreadNotifications = notifications.filter((notification) => !notification.is_read).length
  const authorizationNav: Array<[string, string, AppRoute]> = [
    [copy.roles ?? '', '/admin/authorization/roles', { name: 'authorization', resource: 'roles' }],
    [copy.capabilities ?? '', '/admin/authorization/capabilities', { name: 'authorization', resource: 'capabilities' }],
    [copy.roleAssignments ?? '', '/admin/authorization/role-assignments', { name: 'authorization', resource: 'role-assignments' }],
    [copy.delegations ?? '', '/admin/authorization/delegations', { name: 'authorization', resource: 'delegations' }],
    [copy.supervisoryRelationships ?? '', '/admin/organization/supervisory-relationships', { name: 'authorization', resource: 'supervisory' }],
    [copy.accessExplanation ?? '', '/admin/authorization/access-explanation', { name: 'access-explanation' }],
  ]

  return (
    <>
      <AppShell
        locale={locale}
        copy={copy}
        facilityName={facilityName}
        activeNavigation={adminView ? 'organization' : view.name === 'create' ? 'create' : 'requests'}
        unreadNotifications={unreadNotifications}
        notificationButtonRef={notificationButtonRef}
        notificationsOpen={notificationsOpen}
        onLocaleChange={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}
        onNotificationsToggle={() => setNotificationsOpen((open) => !open)}
        onLogout={() => {
          setSession(null)
          setRecords([])
          setNotifications([])
          window.history.replaceState({}, '', '/')
          setView({ name: 'list' })
        }}
        onNavigateRequests={() => navigate({ name: 'list' }, '/')}
        onNavigateCreate={() => navigate({ name: 'create' }, '/work-records/new')}
        onNavigateOrganization={() => navigate({ name: 'organization' }, primaryRoutes[2].path)}
      >
        {adminView && <nav className="secondary-navigation" aria-label={copy.administrationNavigation}>
          {[
            [2, copy.organization],
            [3, copy.organizationStructure],
            [4, copy.peopleAssignments],
            [5, copy.temporaryAssignments],
            [6, copy.identityAccounts],
            [7, copy.importReview],
          ].map(([index, label]) => {
            const item = primaryRoutes[index as number]
            return <a key={item.path} href={item.path} aria-current={view.name === item.route.name ? 'page' : undefined} onClick={(event) => { event.preventDefault(); navigate(item.route, item.path) }}>{label}</a>
          })}
          {authorizationNav.map(([label, path, route]) => <a key={path} href={path} aria-current={view.name === route.name ? 'page' : undefined} onClick={(event) => { event.preventDefault(); navigate(route, path) }}>{label}</a>)}
        </nav>}
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
            record={detail}
            loading={detailLoading}
            state={detailState}
            onRetry={() => setView({ ...view })}
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
        {view.name === 'access-explanation' && <AccessExplanation locale={locale} token={session.access_token} decisionId={view.decisionId} onSessionExpired={expireSession} />}
        {view.name === 'authorization' && <AuthorizationAdmin locale={locale} token={session.access_token} resource={view.resource} onSessionExpired={expireSession} />}
        {view.name === 'access-explanation' && <AccessExplanation locale={locale} token={session.access_token} decisionId={view.decisionId} onSessionExpired={expireSession} />}
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

function LoginScreen({ locale, sessionExpired, onLocaleChange, onAuthenticated }: {
  locale: Locale
  sessionExpired: boolean
  onLocaleChange: () => void
  onAuthenticated: (session: Session) => void
}) {
  const copy = text[locale]
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!username.trim() || !password) {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(false)
    try {
      onAuthenticated(await login(username.trim(), password))
      setPassword('')
    } catch {
      setError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-card" aria-labelledby="login-heading">
        <button type="button" className="language-button" onClick={onLocaleChange}>{copy.switchLanguage}</button>
        <div className="brand login-brand">{copy.platform}</div>
        <h1 id="login-heading">{copy.signIn}</h1>
        {sessionExpired && <p className="status-message" role="status">{copy.sessionExpired}</p>}
        {error && <p id="login-error" className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{username.trim() && password ? copy.loginError : copy.requiredLogin}</p>}
        <form onSubmit={(event) => void submit(event)} noValidate>
          <div className="field">
            <label htmlFor="username">{copy.username}</label>
            <input id="username" name="username" autoComplete="username" required aria-required="true" value={username} aria-invalid={error} aria-describedby={error ? 'login-error' : undefined} onChange={(event) => setUsername(event.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="password">{copy.password}</label>
            <input id="password" name="password" type="password" autoComplete="current-password" required aria-required="true" value={password} aria-invalid={error} aria-describedby={error ? 'login-error' : undefined} onChange={(event) => setPassword(event.target.value)} />
          </div>
          <button type="submit" className="primary-button full-width" disabled={submitting}>
            {submitting ? copy.signingIn : copy.signIn}
          </button>
        </form>
      </section>
    </main>
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

function RequestDetail({ locale, record, loading, state, onRetry }: {
  locale: Locale
  record: WorkRecord | null
  loading: boolean
  state: 'ready' | 'unavailable' | 'error'
  onRetry: () => void
}) {
  const copy = text[locale]
  if (loading) return <section><h1>{copy.loadingDetail}</h1></section>
  if (state === 'unavailable') return <section className="state-panel"><h1>{copy.unavailable}</h1></section>
  if (state === 'error') return <section className="state-panel" role="alert"><h1>{copy.detailError}</h1><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></section>
  if (!record) return <section><h1>{copy.loadingDetail}</h1></section>
  return (
    <article className="detail-panel">
      <h1>{record.payload.title ?? copy.noDescription}</h1>
      <p>{record.payload.description ?? copy.noDescription}</p>
      <div className="detail-meta"><span className="status-badge">{copy.submitted}</span><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time></div>
    </article>
  )
}

function NotificationList({ locale, items, loading, error }: { locale: Locale; items: Notification[]; loading: boolean; error: boolean }) {
  const copy = text[locale]
  if (loading) return <div className="skeleton-list" aria-label={copy.refreshingNotifications}>{[0, 1].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>
  if (error) return <p role="alert" className="field-error">{copy.notificationError}</p>
  if (items.length === 0) return <p>{copy.noNotifications}</p>
  return (
    <ul className="notification-list" aria-live="polite">
      {items.map((item) => (
        <li key={item.id}>
          <strong>{item.title}</strong>
          <span>{item.is_read ? copy.read : copy.unread}</span>
          <time dateTime={item.created_at}>{formatDate(item.created_at, locale)}</time>
        </li>
      ))}
    </ul>
  )
}

function formatDate(value: string, locale: Locale): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export default App
