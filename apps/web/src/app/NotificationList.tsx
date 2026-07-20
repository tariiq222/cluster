import type { Notification } from '../api'
import { text, type Locale } from './copy'

export function formatDate(value: string, locale: Locale): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function NotificationList({ locale, items, loading, error }: { locale: Locale; items: Notification[]; loading: boolean; error: boolean }) {
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