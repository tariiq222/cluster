import { type FormEvent, useEffect, useRef, useState } from 'react'

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
        notificationButtonRef.current?.focus()
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

  return (
    <div className="app-shell">
      <header className="site-header">
        <div className="brand">{copy.platform}</div>
        <div className="header-actions">
          <button type="button" className="quiet-button" onClick={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}>
            {copy.switchLanguage}
          </button>
          <button
            type="button"
            className="quiet-button"
            ref={notificationButtonRef}
            aria-expanded={notificationsOpen}
            aria-controls="notification-panel"
            onClick={() => setNotificationsOpen((open) => !open)}
          >
            {copy.notifications}
          </button>
          <button type="button" className="quiet-button" onClick={() => {
            setSession(null)
            setRecords([])
            setNotifications([])
            window.history.replaceState({}, '', '/')
            setView({ name: 'list' })
          }}>
            {copy.logout}
          </button>
        </div>
      </header>
      <div className="facility-strip">{copy.currentFacility}</div>
      <nav className="primary-navigation" aria-label={locale === 'ar' ? 'التنقل الرئيسي' : 'Primary navigation'}>
        <a
          href={primaryRoutes[0].path}
          aria-current={view.name === primaryRoutes[0].route.name ? 'page' : undefined}
          onClick={(event) => {
            event.preventDefault()
            navigate({ name: 'list' }, '/')
          }}
        >
          {copy.myRequests}
        </a>
        <a
          href={primaryRoutes[1].path}
          aria-current={view.name === primaryRoutes[1].route.name ? 'page' : undefined}
          onClick={(event) => {
            event.preventDefault()
            navigate({ name: 'create' }, '/work-records/new')
          }}
        >
          {copy.newRequest}
        </a>
        <a
          href={primaryRoutes[2].path}
          aria-current={view.name === primaryRoutes[2].route.name ? 'page' : undefined}
          onClick={(event) => {
            event.preventDefault()
            navigate({ name: 'organization' }, primaryRoutes[2].path)
          }}
        >
          {copy.organization}
        </a>
      </nav>

      <main className="main-content">
        {view.name === 'list' && (
          <RequestList
            locale={locale}
            records={records}
            loading={recordsLoading}
            error={recordsError}
            onRetry={() => void refreshRecords()}
            onCreate={() => navigate({ name: 'create' }, '/work-records/new')}
            onSelect={(recordId) => navigate({ name: 'detail', recordId }, `/work-records/${recordId}`)}
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
      </main>

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
    </div>
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

function RequestList({ locale, records, loading, error, onRetry, onCreate, onSelect }: {
  locale: Locale
  records: WorkRecord[]
  loading: boolean
  error: boolean
  onRetry: () => void
  onCreate: () => void
  onSelect: (recordId: string) => void
}) {
  const copy = text[locale]
  return (
    <section aria-labelledby="requests-heading">
      <div className="page-heading">
        <h1 id="requests-heading">{copy.myRequests}</h1>
        <a href="/work-records/new" className="primary-link" onClick={(event) => { event.preventDefault(); onCreate() }}>{copy.newRequest}</a>
      </div>
      {loading && <div className="skeleton-list" aria-label={copy.loadingRequests}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
      {!loading && error && <div className="state-panel" role="alert"><p>{copy.listError}</p><button type="button" className="secondary-button" onClick={onRetry}>{copy.retry}</button></div>}
      {!loading && !error && records.length === 0 && (
        <div className="state-panel">
          <h2>{copy.emptyTitle}</h2>
          <p>{copy.emptyBody}</p>
          <button type="button" className="primary-button" onClick={onCreate}>{copy.submit}</button>
        </div>
      )}
      {!loading && !error && records.length > 0 && (
        <ul className="request-list">
          {records.map((record) => (
            <li key={record.id}>
              <a href={`/work-records/${record.id}`} onClick={(event) => { event.preventDefault(); onSelect(record.id) }}>
                <span className="request-copy">
                  <strong>{record.payload.title ?? copy.noDescription}</strong>
                  <span>{record.payload.description ?? copy.noDescription}</span>
                </span>
                <span className="request-meta">
                  <span className="status-badge">{copy.submitted}</span>
                  <time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time>
                </span>
              </a>
            </li>
          ))}
        </ul>
      )}
    </section>
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
