import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import * as generated from '../../api/generated/cluster'
import { requestInit, stateFromError, unwrap, type ResourceState } from '../../api/http'
import { useNotificationsList } from '../../api/hooks'
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
  const queryClient = useQueryClient()

  const [items, setItems] = useState<generated.Notification[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadMoreFailed, setLoadMoreFailed] = useState(false)
  const [marking, setMarking] = useState<Set<string>>(() => new Set())
  const [markFailed, setMarkFailed] = useState(false)

  const canRead = capabilities?.includes('notifications.read') === true

  const notificationsQuery = useNotificationsList(20)
  const data = notificationsQuery.data as generated.NotificationCollection | undefined

  const appliedData = useRef<generated.NotificationCollection | null>(null)
  useEffect(() => {
    if (data === undefined) return
    if (appliedData.current === data) return
    appliedData.current = data
    setNextCursor(data.next_cursor ?? null)
    setLoadMoreFailed(false)
    setItems((current) => {
      if (current.length === 0) return data.items
      const byId = new Map(data.items.map((notification) => [notification.id, notification]))
      return current.map((notification) => byId.get(notification.id) ?? notification)
    })
  }, [data])

  let state: ResourceState = 'loading'
  if (!notificationsQuery.isFetching || data !== undefined) {
    if (notificationsQuery.error !== null) {
      state = stateFromError(notificationsQuery.error)
    } else if (data === undefined || data.items.length === 0) {
      state = 'empty'
    } else {
      state = 'ready'
    }
  }

  const loadMore = useCallback(async () => {
    if (nextCursor === null) return
    setLoadingMore(true)
    setLoadMoreFailed(false)
    try {
      const response = await generated.listMyNotifications({ limit: 20, cursor: nextCursor }, requestInit(csrfToken))
      const collection = unwrap<generated.NotificationCollection>(response)
      setItems((current) => [...current, ...collection.items])
      setNextCursor(collection.next_cursor)
    } catch {
      setLoadMoreFailed(true)
    } finally {
      setLoadingMore(false)
    }
  }, [csrfToken, nextCursor])

  const markReadMutation = useMutation({
    mutationFn: async (id: string) =>
      unwrap(await generated.markNotificationRead(id, requestInit(csrfToken, { command: true }))),
    onSuccess: (_result, id) => {
      setItems((current) => current.map((notification) => (notification.id === id ? { ...notification, is_read: true } : notification)))
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
    onError: () => setMarkFailed(true),
  })

  const markRead = useCallback((id: string) => {
    setMarkFailed(false)
    setMarking((current) => {
      const next = new Set(current)
      next.add(id)
      return next
    })
    markReadMutation.mutate(id, {
      onSettled: () => {
        setMarking((current) => {
          const next = new Set(current)
          next.delete(id)
          return next
        })
      },
    })
  }, [markReadMutation])

  if (!canRead) {
    return <EmptyState title={shellCopy[locale].denied} />
  }

  return (
    <Page>
      <PageHeader id="notifications-title" title={t(locale, 'title')} description={t(locale, 'description')} />
      {state === 'loading' && <SkeletonList rows={4} />}
      {state === 'forbidden' && <EmptyState title={shellCopy[locale].denied} />}
      {(state === 'error' || state === 'stale' || state === 'conflict') && (
        <InlineError message={t(locale, 'error')} retryLabel={t(locale, 'retry')} onRetry={() => void notificationsQuery.refetch()} />
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
          {loadMoreFailed && <InlineError message={t(locale, 'loadMoreFailed')} onRetry={() => void loadMore()} retryLabel={t(locale, 'retry')} />}
          {nextCursor && (
            <div className="pagination-bar">
              <p className="pagination-bar__info">
                {items.length} {t(locale, 'count')}
              </p>
              <Button variant="secondary" disabled={loadingMore} onClick={() => void loadMore()}>
                {loadingMore ? t(locale, 'loadingMore') : t(locale, 'loadMore')}
              </Button>
            </div>
          )}
        </>
      )}
    </Page>
  )
}
