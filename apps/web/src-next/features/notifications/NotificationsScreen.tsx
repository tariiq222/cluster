import { useCallback, useEffect, useState } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import { requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { formatDate, shellCopy, statusLabel } from '../../i18n'
import { Button, EmptyState, InlineError, Page, PageHeader, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    title: 'الإشعارات',
    description: 'آخر الإشعارات الموجهة إليك في المنصة.',
    noNotifications: 'لا توجد إشعارات',
    markRead: 'تحديد كمقروء',
    marking: 'جارٍ التحديث…',
    loadMore: 'عرض المزيد',
    loadingMore: 'جارٍ التحميل…',
    empty: 'لا توجد بيانات.',
    error: 'حدث خطأ غير متوقع.',
    retry: 'إعادة المحاولة',
    loadMoreFailed: 'تعذر تحميل المزيد من الإشعارات.',
    markFailed: 'تعذر تحديث الإشعار.',
    from: 'من',
    count: 'إشعار',
  },
  en: {
    title: 'Notifications',
    description: 'Latest notifications addressed to you on the platform.',
    noNotifications: 'No notifications',
    markRead: 'Mark as read',
    marking: 'Updating…',
    loadMore: 'Load more',
    loadingMore: 'Loading…',
    empty: 'No data.',
    error: 'Something went wrong.',
    retry: 'Retry',
    loadMoreFailed: 'Could not load more notifications.',
    markFailed: 'Could not update the notification.',
    from: 'From',
    count: 'notifications',
  },
} as const

type CopyKey = keyof (typeof copy)['ar']

function t(locale: 'ar' | 'en', key: CopyKey): string {
  return copy[locale][key]
}

export function NotificationsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { capabilities } = usePrincipal()

  const [state, setState] = useState<ResourceState>('loading')
  const [items, setItems] = useState<generated.Notification[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadMoreFailed, setLoadMoreFailed] = useState(false)
  const [marking, setMarking] = useState<Set<string>>(() => new Set())
  const [markFailed, setMarkFailed] = useState(false)

  const canRead = capabilities?.includes('notifications.read') === true

  const load = useCallback(
    async (cursor?: string) => {
      if (cursor) {
        setLoadingMore(true)
        setLoadMoreFailed(false)
      } else {
        setState('loading')
      }
      try {
        const response = await generated.listMyNotifications({ limit: 20, cursor }, requestInit(csrfToken))
        const collection = unwrap<generated.NotificationCollection>(response)
        setItems((current) => (cursor ? [...current, ...collection.items] : collection.items))
        setNextCursor(collection.next_cursor)
        if (!cursor && collection.items.length === 0) {
          setState('empty')
        } else {
          setState('ready')
        }
      } catch (error) {
        if (cursor) {
          setLoadMoreFailed(true)
        } else {
          setState(stateFromError(error))
        }
      } finally {
        if (cursor) setLoadingMore(false)
      }
    },
    [csrfToken],
  )

  useEffect(() => {
    if (canRead) void load()
  }, [canRead, load])

  const markRead = useCallback(
    async (id: string) => {
      setMarkFailed(false)
      setMarking((current) => {
        const next = new Set(current)
        next.add(id)
        return next
      })
      try {
        await unwrap(await generated.markNotificationRead(id, requestInit(csrfToken, { command: true })))
        setItems((current) => current.map((n) => (n.id === id ? { ...n, is_read: true } : n)))
      } catch {
        setMarkFailed(true)
      } finally {
        setMarking((current) => {
          const next = new Set(current)
          next.delete(id)
          return next
        })
      }
    },
    [csrfToken],
  )

  if (!canRead) {
    return <EmptyState title={shellCopy[locale].denied} />
  }

  return (
    <Page>
      <PageHeader id="notifications-title" title={t(locale, 'title')} description={t(locale, 'description')} />
      {state === 'loading' && <SkeletonList rows={4} />}
      {state === 'forbidden' && <EmptyState title={shellCopy[locale].denied} />}
      {(state === 'error' || state === 'stale' || state === 'conflict') && (
        <InlineError message={t(locale, 'error')} retryLabel={t(locale, 'retry')} onRetry={() => void load()} />
      )}
      {state === 'empty' && <EmptyState title={t(locale, 'noNotifications')} />}
      {state === 'ready' && (
        <>
          {markFailed && <InlineError message={t(locale, 'markFailed')} />}
          <ul className="screen-list">
            {items.map((notification) => {
              const isMarking = marking.has(notification.id)
              return (
                <li key={notification.id} className="screen-list__row">
                  <div>
                    <div className="screen-list__row-title">{notification.title}</div>
                    <div className="screen-list__row-meta">
                      {t(locale, 'from')} {notification.source.source_module} / {notification.source.record_type} ·{' '}
                      {formatDate(notification.created_at, locale)}
                    </div>
                  </div>
                  <div className="screen-list__row-actions">
                    <StatusBadge variant={notification.is_read ? 'neutral' : 'info'}>
                      {statusLabel(notification.is_read ? 'read' : 'unread', locale)}
                    </StatusBadge>
                    {!notification.is_read && (
                      <Button
                        variant="secondary"
                        disabled={isMarking}
                        onClick={() => void markRead(notification.id)}
                      >
                        {isMarking ? t(locale, 'marking') : t(locale, 'markRead')}
                      </Button>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
          {loadMoreFailed && <InlineError message={t(locale, 'loadMoreFailed')} onRetry={() => void load(nextCursor ?? undefined)} retryLabel={t(locale, 'retry')} />}
          {nextCursor && (
            <div className="pagination-bar">
              <p className="pagination-bar__info">
                {items.length} {t(locale, 'count')}
              </p>
              <Button variant="secondary" disabled={loadingMore} onClick={() => void load(nextCursor)}>
                {loadingMore ? t(locale, 'loadingMore') : t(locale, 'loadMore')}
              </Button>
            </div>
          )}
        </>
      )}
    </Page>
  )
}
