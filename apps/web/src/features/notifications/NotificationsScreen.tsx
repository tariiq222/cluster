import { useCallback } from 'react'
import { useInfiniteQuery, useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query'
import { Bell, Inbox } from 'lucide-react'
import * as generated from '../../api/generated/cluster'
import { requestInit, stateFromError, unwrap } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { formatDate } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { EmptyState, ResourceBoundary } from '@/components/states'
import { notificationsCopy } from './notifications-copy'

const PAGE_SIZE = 20

function notificationPath(notification: generated.Notification): string | null {
  const { record_type: recordType, record_id: recordId } = notification.source
  switch (recordType) {
    case 'task':
      return `/tasks/${recordId}`
    case 'document':
      return `/documents/${recordId}`
    case 'work_record':
      return `/work-records/${recordId}`
    default:
      return null
  }
}

export function NotificationsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { capabilities } = usePrincipal()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const text = notificationsCopy[locale]

  const canRead = capabilities?.includes('notifications.read') === true

  const notificationsQuery = useInfiniteQuery({
    queryKey: ['notifications'] as const,
    queryFn: async ({ pageParam }) => {
      const page = unwrap<generated.NotificationCollection>(
        await generated.listMyNotifications(
          { limit: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) },
          requestInit(csrfToken),
        ),
      )
      return page
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    enabled: canRead,
  })

  const items = notificationsQuery.data?.pages.flatMap((page) => page.items) ?? []

  const markRead = useMutation({
    mutationFn: async (id: string) =>
      unwrap(await generated.markNotificationRead(id, requestInit(csrfToken, { command: true }))),
    onSuccess: (_result, id) => {
      /*
       * The cached value is TanStack InfiniteData (pages + pageParams), not an
       * array of pages. Patch pages in place and keep pageParams untouched so
       * the optimistic read-mark survives until the refetch replaces the page.
       */
      queryClient.setQueryData<InfiniteData<generated.NotificationCollection, string | undefined>>(
        ['notifications'],
        (infinite) =>
          infinite && {
            ...infinite,
            pages: infinite.pages.map((page) => ({
              ...page,
              items: page.items.map((notification) =>
                notification.id === id ? { ...notification, is_read: true } : notification,
              ),
            })),
          },
      )
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const openNotification = useCallback(
    (notification: generated.Notification) => {
      if (!notification.is_read) {
        markRead.mutate(notification.id)
      }
      const path = notificationPath(notification)
      if (path) navigate(path)
    },
    [markRead, navigate],
  )

  let state: 'loading' | 'ready' | 'empty' | 'forbidden' | 'not-found' | 'conflict' | 'stale' | 'error' = 'loading'
  if (!canRead) {
    state = 'forbidden'
  } else if (notificationsQuery.isPending) {
    state = 'loading'
  } else if (notificationsQuery.isError) {
    state = stateFromError(notificationsQuery.error)
  } else if (items.length === 0) {
    state = 'empty'
  } else {
    state = 'ready'
  }

  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.description} />

      <ResourceBoundary
        state={state}
        locale={locale}
        onRetry={() => void notificationsQuery.refetch()}
        empty={<EmptyState icon={<Inbox aria-hidden="true" />} title={text.noNotifications} body={text.noNotificationsBody} />}
      >
        <div className="space-y-3">
          <ul className="divide-y rounded-lg border">
            {items.map((notification) => {
              const unread = !notification.is_read
              const path = notificationPath(notification)
              return (
                <li key={notification.id}>
                  <button
                    type="button"
                    onClick={() => openNotification(notification)}
                    className="flex w-full items-start gap-3 p-3 text-start hover:bg-accent/50 focus-visible:bg-accent/50"
                  >
                    <span
                      aria-hidden="true"
                      className={`mt-1.5 size-2 shrink-0 rounded-full ${unread ? 'bg-primary' : 'bg-transparent'}`}
                    />
                    <span className="min-w-0 flex-1">
                      <span className={`block truncate ${unread ? 'font-medium' : 'text-muted-foreground'}`}>
                        {notification.title}
                      </span>
                      <span className="text-muted-foreground text-xs">
                        {notification.source.source_module} / {notification.source.record_type} ·{' '}
                        {formatDate(notification.created_at, locale)}
                      </span>
                    </span>
                    {path && (
                      <span className="text-muted-foreground text-xs" aria-hidden="true">
                        <Bell className="size-4" />
                      </span>
                    )}
                  </button>
                </li>
              )
            })}
          </ul>
          {notificationsQuery.hasNextPage && (
            <div className="flex justify-center">
              <Button
                variant="outline"
                size="sm"
                disabled={notificationsQuery.isFetchingNextPage}
                onClick={() => void notificationsQuery.fetchNextPage()}
              >
                {notificationsQuery.isFetchingNextPage ? text.loadingMore : text.loadMore}
              </Button>
            </div>
          )}
        </div>
      </ResourceBoundary>
    </PageLayout>
  )
}
