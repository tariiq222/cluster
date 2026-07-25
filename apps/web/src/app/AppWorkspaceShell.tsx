import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from 'react'

import { AppShell } from './AppShell'
import { shellCopy, type Locale } from './copy'
import { SessionProvider } from './session-context'
import { PrincipalProvider, usePrincipal } from './principal-context'
import { WorkspaceHeader } from './WorkspaceHeader'
import { WorkspaceContent, RouteAccessGuard } from './WorkspaceContent'
import { useWorkspaceSidebar } from './WorkspaceSidebar'
import { routeFromPath, pathFromRoute, type AppRoute } from '../shell/routes'
import {
  listNotifications,
  markNotificationRead,
  type Notification,
  type Session,
} from '../api'
import { NotificationList } from './NotificationList'

export type AppWorkspaceProps = {
  locale: Locale
  session: Session
  onLocaleChange: () => void
  onLogout: () => void | Promise<void>
}

export { RouteAccessGuard }

export default function AppWorkspaceShell({ locale, session, onLocaleChange, onLogout }: AppWorkspaceProps) {
  const [route, setRoute] = useState<AppRoute>(() =>
    routeFromPath(window.location.pathname),
  )
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [notificationsLoading, setNotificationsLoading] = useState(true)
  const [notificationsError, setNotificationsError] = useState(false)
  const [notificationsNextCursor, setNotificationsNextCursor] = useState<string | null>(null)
  const [notificationsLoadingMore, setNotificationsLoadingMore] = useState(false)
  const [notificationsLoadMoreError, setNotificationsLoadMoreError] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
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

  const onRouteNavigate = useCallback((next: AppRoute) => {
    navigate(pathFromRoute(next))
  }, [navigate])

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
    if (!notificationsNextCursor || notificationsLoadingMore || notificationsLoading) return
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
  }, [notificationsLoading, notificationsLoadingMore, notificationsNextCursor, session.access_token])

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
    void loadNotifications()
  }, [loadNotifications])

  const unreadNotifications = notifications.filter((item) => !item.is_read).length

  return (
    <SessionProvider locale={locale} session={session}>
      <PrincipalProvider token={session.access_token}>
        <ShellInner
          locale={locale}
          session={session}
          route={route}
          notifications={notifications}
          notificationsLoading={notificationsLoading}
          notificationsError={notificationsError}
          unreadNotifications={unreadNotifications}
          notificationsOpen={notificationsOpen}
          setNotificationsOpen={setNotificationsOpen}
          notificationButtonRef={notificationButtonRef}
          globalSearchQuery={globalSearchQuery}
          setGlobalSearchQuery={setGlobalSearchQuery}
          navigate={navigate}
          onRouteNavigate={onRouteNavigate}
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
  onRouteNavigate,
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
  notifications: Notification[]
  notificationsLoading: boolean
  notificationsError: boolean
  unreadNotifications: number
  notificationsOpen: boolean
  setNotificationsOpen: (open: boolean) => void
  notificationButtonRef: React.RefObject<HTMLButtonElement | null>
  globalSearchQuery: string
  setGlobalSearchQuery: (query: string) => void
  navigate: (path: string) => void
  onRouteNavigate: (route: AppRoute) => void
  loadMoreNotifications: () => void
  notificationsHasMore: boolean
  notificationsLoadingMore: boolean
  notificationsLoadMoreError: boolean
  handleMarkNotificationRead: (notificationId: string) => Promise<void>
  onLocaleChange: () => void
  onLogout: () => void | Promise<void>
}) {
  const principal = usePrincipal()

  const navigationGroups = useWorkspaceSidebar({
    locale,
    route,
    capabilities: principal.capabilities,
    onNavigate: navigate,
  })

  const header = WorkspaceHeader({
    locale,
    onUserMenuNavigate: navigate,
  })

  const notificationPanel = (
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
  )

  return (
    <AppShell
      locale={locale}
      copy={shellCopy(locale)}
      facilityName={header.facilityName}
      navigationGroups={navigationGroups}
      unreadNotifications={unreadNotifications}
      notificationButtonRef={notificationButtonRef}
      notificationsOpen={notificationsOpen}
      onLocaleChange={onLocaleChange}
      onNotificationsToggle={() => setNotificationsOpen(!notificationsOpen)}
      onLogout={onLogout}
      userMenu={header.userMenu}
      scopeSelector={header.scopeSelector}
      notificationPanel={notificationPanel}
      globalSearchLabel={header.searchLabel}
      onGlobalSearch={(query) => {
        setGlobalSearchQuery(query)
        navigate(pathFromRoute({ name: 'search' }))
      }}
    >
      <WorkspaceContent
        locale={locale}
        session={session}
        route={route}
        globalSearchQuery={globalSearchQuery}
        scopeReady={principal.scopeReady}
        scopeEpoch={principal.scopeEpoch}
        navigate={navigate}
        onRouteNavigate={onRouteNavigate}
        notifications={notifications}
        notificationsLoading={notificationsLoading}
        notificationsError={notificationsError}
        notificationsHasMore={notificationsHasMore}
        notificationsLoadingMore={notificationsLoadingMore}
        notificationsLoadMoreError={notificationsLoadMoreError}
        loadMoreNotifications={loadMoreNotifications}
        onMarkNotificationRead={handleMarkNotificationRead}
      />
    </AppShell>
  )
}
