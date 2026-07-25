import {
  Suspense,
  useCallback,
  useEffect,
  useMemo,
  useState,
  lazy,
  type ReactNode,
} from 'react'

import {
  Page,
  PageHeader,
  Panel,
  SkeletonList,
} from '../ui'
import { text, type Locale } from './copy'
import { NotificationList } from './NotificationList'
import {
  ApiError,
  getWorkRecord,
  type Notification,
  type Session,
  type WorkRecord,
} from '../api'
import {
  type AuthorizedWorkRecord,
  getAuthorizedWorkRecord,
} from '../api/r1'
import {
  type AppRoute,
  type PlatformSettingsSection,
} from '../shell/routes'
import { isRouteVisible } from '../shell/routes'
import { RequestDetail } from '../features/requests/RequestDetail'
import { PlatformSettingsLayout } from '../features/platform-settings/PlatformSettingsLayout'
import { PlatformOverviewScreen } from '../features/platform-settings/PlatformOverviewScreen'
import { SecuritySettingsScreen } from '../features/platform-settings/SecuritySettingsScreen'
import { BusinessCalendarsScreen } from '../features/platform-settings/BusinessCalendarsScreen'
import { BackupsScreen } from '../features/platform-settings/BackupsScreen'
import { TechnicalLogsScreen } from '../features/platform-settings/TechnicalLogsScreen'
import { PlatformHealthScreen } from '../features/platform-settings/PlatformHealthScreen'
import { MaintenanceScreen } from '../features/platform-settings/MaintenanceScreen'
import { usePlatformSettingsLive } from '../features/platform-settings/usePlatformSettingsLive'
import { createLivePlatformSettingsDataSource } from '../features/platform-settings/PlatformSettingsMockData'

const SwaggerUiScreen = lazy(async () => {
  const module = await import('../features/docs/SwaggerUiScreen')
  return { default: module.SwaggerUiScreen }
})

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

export function RouteNotFound({ locale }: { locale: Locale }) {
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

export function PlatformSettingsRoute({ locale, section, capabilities, navigate, session }: { locale: Locale; section: PlatformSettingsSection; capabilities: readonly string[] | null; navigate: (path: string) => void; session: Session }) {
  const [logCursor, setLogCursor] = useState<string | null>(null)
  useEffect(() => {
    if (section !== 'logs') setLogCursor(null)
  }, [section])
  const source = useMemo(() => createLivePlatformSettingsDataSource(session.access_token), [session.access_token])
  const { state } = usePlatformSettingsLive(source, { section, capabilities, cursor: section === 'logs' ? logCursor : null })
  const screen = (state.kind === 'success' || state.kind === 'denied' || state.kind === 'unsupported')
    ? state.screen
    : state.kind === 'error'
      ? { state: 'error' as const, resource: { id: section, items: [], next_cursor: null } as never, allowedActions: [] }
      : state.kind === 'loading' || state.kind === 'idle'
        ? { state: 'loading' as const, resource: { id: section, items: [], next_cursor: null } as never, allowedActions: [] }
        : { state: 'empty' as const, resource: { id: section, items: [], next_cursor: null } as never, allowedActions: [] }
  const props = { locale, state: screen.state, allowedActions: screen.allowedActions, resource: screen.resource }
  return <PlatformSettingsLayout locale={locale} section={section} capabilities={capabilities} navigate={navigate}>
    {section === 'overview' ? <PlatformOverviewScreen {...props} token={session.access_token} />
      : section === 'security' ? <SecuritySettingsScreen {...props} token={session.access_token} />
        : section === 'calendars' ? <BusinessCalendarsScreen {...props} token={session.access_token} />
          : section === 'backups' ? <BackupsScreen {...props} token={session.access_token} />
            : section === 'logs' ? <TechnicalLogsScreen {...props} token={session.access_token} logs={'items' in screen.resource ? screen.resource : undefined} onCursorChange={setLogCursor} />
              : section === 'health' ? <PlatformHealthScreen {...props} token={session.access_token} />
                : <MaintenanceScreen {...props} token={session.access_token} />}
  </PlatformSettingsLayout>
}

export function RequestDetailRoute({
  locale,
  token,
  recordId,
}: {
  locale: Locale
  token: string
  recordId: string
}) {
  const [record, setRecord] = useState<WorkRecord | null>(null)
  const [authorizedRecord, setAuthorizedRecord] = useState<AuthorizedWorkRecord | null>(null)
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

export function NotificationsRoute({
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

/** Renders the lazy-loaded API reference screen with a skeleton fallback. */
export function ApiDocsRoute({ locale }: { locale: Locale }) {
  return (
    <Suspense
      fallback={
        <Page>
          <SkeletonList label={text[locale].loadingApiReference} />
        </Page>
      }
    >
      <SwaggerUiScreen locale={locale} />
    </Suspense>
  )
}
