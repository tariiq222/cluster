import { BellRing, CalendarDays, FolderSearch } from 'lucide-react'
import { recordStatusText, text, type Locale } from '../../app/copy'
import { formatDate } from '../../app/NotificationList'
import type { Notification, WorkRecord } from '../../api'

export function RequestDashboard({
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
