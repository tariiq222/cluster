import { BellRing, CalendarDays, CheckCircle2, Clock3, FileText, FolderSearch, Plus, Sparkles, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { recordStatusText, text, type Locale } from '../../app/copy'
import { formatDate } from '../../app/NotificationList'
import type { Notification, WorkRecord } from '../../api'
import { Button, EmptyState, InlineError, Page, Panel, SkeletonList, StatusBadge } from '../../ui'
import './RequestDashboard.css'

export function RequestDashboard({ locale, records, notifications, loading, error, notificationsLoading, notificationsError, facilityName, onRetry, onCreate, onSelect, onOpenNotifications, hasMore = false, loadingMore = false, loadMoreError = false, onLoadMore, notificationsHasMore = false, notificationsLoadingMore = false, notificationsLoadMoreError = false, onLoadMoreNotifications }: {
  locale: Locale; records: WorkRecord[]; notifications: Notification[]; loading: boolean; error: boolean; notificationsLoading: boolean; notificationsError: boolean; facilityName: string; onRetry: () => void; onCreate: () => void; onSelect: (recordId: string) => void; onOpenNotifications: () => void
  hasMore?: boolean; loadingMore?: boolean; loadMoreError?: boolean; onLoadMore?: () => void
  notificationsHasMore?: boolean; notificationsLoadingMore?: boolean; notificationsLoadMoreError?: boolean; onLoadMoreNotifications?: () => void
}) {
  const copy = text[locale]
  const ar = locale === 'ar'
  const formatter = new Intl.NumberFormat(ar ? 'ar-SA' : 'en-GB')
  const activeStatuses = new Set(['submitted', 'in_review', 'returned'])
  const completedStatuses = new Set(['approved', 'completed'])
  const activeCount = records.filter((record) => activeStatuses.has(record.status)).length
  const completedCount = records.filter((record) => completedStatuses.has(record.status)).length
  const otherCount = Math.max(0, records.length - activeCount - completedCount)
  const unreadCount = notifications.filter((notification) => !notification.is_read).length
  const [recordsExpanded, setRecordsExpanded] = useState(false)
  const metricValue = (value: number, unavailable = loading || error) => unavailable ? '—' : formatter.format(value)
  const greeting = ar ? 'صباح الخير' : 'Good morning'
  const priorityTitle = ar ? `لديك ${formatter.format(Math.min(3, activeCount))} قرارات تستحق اليوم` : `${Math.min(3, activeCount)} decisions deserve your attention today`
  const priorityBody = ar ? 'ابدأ من الأعلى أولوية، وسيتولى النظام ترتيب الخطوات التالية.' : 'Start with the highest priority and the system will arrange the next steps.'
  const stats = [
    { icon: <TriangleAlert />, tone: 'warning', value: metricValue(activeCount), label: ar ? 'طلبات قيد الإجراء' : copy.activeRequests },
    { icon: <FileText />, tone: 'primary', value: metricValue(records.length), label: ar ? 'سجلات العمل ضمن نطاقك' : copy.loadedRequests },
    { icon: <BellRing />, tone: 'danger', value: metricValue(unreadCount, notificationsLoading || notificationsError), label: ar ? 'تنبيهات تنتظر الإجراء' : copy.unreadNotifications },
    { icon: <CheckCircle2 />, tone: 'success', value: metricValue(completedCount), label: ar ? 'طلبات مكتملة' : copy.completedRequests },
  ] as const
  const statusGroups = [
    { label: copy.activeStatus, count: activeCount, tone: 'accent' },
    { label: copy.completedStatus, count: completedCount, tone: 'success' },
    { label: copy.otherStatus, count: otherCount, tone: 'muted' },
  ] as const

  return <Page className="request-dashboard">
    <header className="request-dashboard-header">
      <div><p className="request-dashboard-eyebrow">{facilityName} · {ar ? 'مساحة عملي' : 'My workspace'}</p><h1>{greeting}</h1><p>{ar ? 'هذه الأولويات التي تحتاج قرارك، والباقي ينتظر في مكانه الصحيح.' : copy.dashboardSummary}</p></div>
      <span className="dashboard-range"><CalendarDays aria-hidden="true" />{copy.dashboardRange}</span>
    </header>

    <section className="request-focus-banner" aria-labelledby="request-focus-title">
      <div className="request-focus-content"><p>{ar ? 'الأولوية الآن' : 'Priority now'}</p><h2 id="request-focus-title">{priorityTitle}</h2><span>{priorityBody}</span><Button onClick={onCreate}><Plus aria-hidden="true" />{ar ? 'إنشاء سجل عمل' : copy.submit}</Button></div>
      <div className="request-focus-visual" aria-hidden="true"><strong>{formatter.format(Math.min(3, activeCount))}</strong><span>{ar ? 'قرارات تفصل بين المعاملات وخطوتها التالية' : 'decisions between work and its next step'}</span></div>
    </section>

    <section className="request-stat-grid" aria-label={copy.overview}>{stats.map((stat) => <article className="request-stat-card" key={stat.label}><span className={`request-stat-icon ${stat.tone}`}>{stat.icon}</span><div><strong>{stat.value}</strong><span>{stat.label}</span></div></article>)}</section>

    <div className="request-dashboard-grid">
      <div className="request-dashboard-stack">
        <Panel id="requests-heading" title={ar ? 'ما يحتاجك الآن' : copy.myRequests} actions={<Button variant="quiet" className="ui-panel-link" onClick={onCreate}>{copy.newRequest}</Button>}>
          <p className="request-panel-intro">{ar ? 'مرتبة حسب الاستحقاق وتأثير التأخير.' : copy.currentPageSource}</p>
          {loading && <SkeletonList label={copy.loadingRequests} />}
          {!loading && error && <InlineError message={copy.listError} retryLabel={copy.retry} onRetry={onRetry} />}
          {!loading && !error && records.length === 0 && <EmptyState icon={<FolderSearch />} title={copy.emptyTitle} body={copy.emptyBody} action={<Button onClick={onCreate}>{copy.submit}</Button>} />}
          {!loading && !error && records.length > 0 && <>
            <ul className="request-list dashboard-request-list">{(recordsExpanded ? records : records.slice(0, 4)).map((record) => <li key={record.id}><a href={`/work-records/${record.id}`} onClick={(event) => { event.preventDefault(); onSelect(record.id) }}><span className="request-work-icon"><FileText aria-hidden="true" /></span><span className="request-copy"><strong>{record.payload.title ?? copy.noDescription}</strong><span>{record.payload.description ?? copy.noDescription}</span></span><span className="request-meta"><StatusBadge>{recordStatusText[locale][record.status]}</StatusBadge><time dateTime={record.created_at}>{formatDate(record.created_at, locale)}</time></span></a></li>)}</ul>
            {((!recordsExpanded && records.length > 4) || hasMore) && <Button variant="quiet" disabled={loadingMore} onClick={() => { setRecordsExpanded(true); onLoadMore?.() }}>
              {loadingMore ? (ar ? 'جارٍ تحميل المزيد…' : 'Loading more…') : (ar ? 'تحميل المزيد من الطلبات' : 'Load more requests')}
            </Button>}
            {loadMoreError && <InlineError message={ar ? 'تعذر تحميل المزيد من الطلبات.' : 'Could not load more requests.'} />}
          </>}
        </Panel>
        <Panel id="status-heading" title={ar ? 'إجراءات قيد التنفيذ' : copy.statusBreakdown}>
          <p className="request-panel-intro">{ar ? 'صورة سريعة عن موقع المعاملات داخل سير العمل.' : copy.dashboardSummary}</p>
          {!loading && !error && records.length > 0 ? <div className="dashboard-status-list">{statusGroups.map((group) => { const percentage = Math.round(group.count / records.length * 100); return <div className="dashboard-status-row" key={group.label}><div><span>{group.label}</span><strong>{formatter.format(group.count)}</strong></div><div className="dashboard-progress" role="progressbar" aria-label={group.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percentage}><span data-tone={group.tone} style={{ inlineSize: `${percentage}%` }} /></div><small>{formatter.format(percentage)}%</small></div> })}</div> : loading ? <SkeletonList label={copy.loadingRequests} /> : error ? <InlineError message={copy.listError} retryLabel={copy.retry} onRetry={onRetry} /> : <EmptyState icon={<FolderSearch />} title={copy.noStatusTitle} body={copy.noStatusBody} />}
        </Panel>
      </div>
      <div className="request-dashboard-stack">
        <Panel id="quick-actions-heading" title={ar ? 'إجراء سريع' : 'Quick action'}><p className="request-panel-intro">{ar ? 'ابدأ من الهدف، والنظام يرتب الخطوات.' : 'Start from the outcome and keep work moving.'}</p><div className="request-quick-grid"><Button variant="quiet" onClick={onCreate}><Plus aria-hidden="true" /><strong>{copy.newRequest}</strong></Button><Button variant="quiet" onClick={onOpenNotifications}><BellRing aria-hidden="true" /><strong>{copy.openNotifications}</strong></Button><Button variant="quiet" onClick={onRetry}><Clock3 aria-hidden="true" /><strong>{ar ? 'تحديث البيانات' : 'Refresh data'}</strong></Button><Button variant="quiet" onClick={onOpenNotifications}><Sparkles aria-hidden="true" /><strong>{ar ? 'آخر التحديثات' : 'Recent updates'}</strong></Button></div></Panel>
        <Panel id="notifications-dashboard-heading" title={copy.notifications} actions={<Button variant="quiet" className="ui-panel-link" onClick={onOpenNotifications}>{copy.openNotifications}</Button>}>
          {notificationsLoading && <SkeletonList label={copy.refreshingNotifications} />}
          {!notificationsLoading && notificationsError && <p role="alert" className="field-error">{copy.notificationError}</p>}
          {!notificationsLoading && !notificationsError && notifications.length === 0 && <EmptyState icon={<BellRing />} title={copy.noNotifications} body={copy.noNotificationBody} />}
          {!notificationsLoading && !notificationsError && notifications.length > 0 && <>
            <ul className="dashboard-notification-list">{notifications.slice(0, 4).map((notification) => <li key={notification.id}><span className="dashboard-notification-copy"><strong>{notification.title}</strong><small>{notification.is_read ? copy.read : copy.unread}</small></span><time dateTime={notification.created_at}>{formatDate(notification.created_at, locale)}</time></li>)}</ul>
            {notificationsHasMore && onLoadMoreNotifications && <Button variant="quiet" disabled={notificationsLoadingMore} onClick={onLoadMoreNotifications}>{notificationsLoadingMore ? (ar ? 'جارٍ تحميل المزيد…' : 'Loading more…') : (ar ? 'تحميل المزيد من التنبيهات' : 'Load more notifications')}</Button>}
            {notificationsLoadMoreError && <InlineError message={ar ? 'تعذر تحميل المزيد من التنبيهات.' : 'Could not load more notifications.'} />}
          </>}
        </Panel>
      </div>
    </div>
  </Page>
}
