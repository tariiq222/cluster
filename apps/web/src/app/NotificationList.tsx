import { Inbox } from 'lucide-react'
import { useState } from 'react'

import type { Notification } from '../api'
import { Button, EmptyState, InlineError, SkeletonList, StatusBadge } from '../ui'
import { text, type Locale, formattingLocale } from './copy'

export function formatDate(value: string, locale: Locale): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(formattingLocale(locale), { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function NotificationList({ locale, items, loading, error, onMarkRead, hasMore = false, loadingMore = false, loadMoreError = false, onLoadMore }: {
  locale: Locale
  items: Notification[]
  loading: boolean
  error: boolean
  onMarkRead?: (notificationId: string) => Promise<void>
  hasMore?: boolean
  loadingMore?: boolean
  loadMoreError?: boolean
  onLoadMore?: () => void
}) {
  const copy = text[locale]
  const [busyId, setBusyId] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  if (loading) return <SkeletonList label={copy.refreshingNotifications} rows={2} />
  if (error) return <InlineError message={copy.notificationError} />
  if (items.length === 0) return <EmptyState icon={<Inbox />} title={copy.noNotifications} />
  return (
    <>
      {actionError && <InlineError message={actionError} />}
      <ul className="notification-list" aria-live="polite">
      {items.map((item) => (
        <li key={item.id}>
          <strong>{item.title}</strong>
          <StatusBadge>{item.is_read ? copy.read : copy.unread}</StatusBadge>
          <time dateTime={item.created_at}>{formatDate(item.created_at, locale)}</time>
          {!item.is_read && onMarkRead && (
            <Button
              variant="quiet"
              disabled={busyId !== null}
              aria-label={text[locale].markNotificationRead(item.title)}
              onClick={async () => {
                setActionError(null)
                setBusyId(item.id)
                try {
                  await onMarkRead(item.id)
                } catch {
                  setActionError(text[locale].couldNotMarkTheNotification)
                } finally {
                  setBusyId(null)
                }
              }}
            >
              {busyId === item.id ? (text[locale].saving) : (text[locale].markAsRead)}
            </Button>
          )}
        </li>
      ))}
      </ul>
      {loadMoreError && <InlineError message={text[locale].couldNotLoadMoreNotifications} />}
      {hasMore && onLoadMore && <Button variant="quiet" disabled={loadingMore} onClick={onLoadMore}>
        {loadingMore ? (text[locale].loadingMore) : (text[locale].loadMore)}
      </Button>}
    </>
  )
}
