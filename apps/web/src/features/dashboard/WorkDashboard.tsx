// @vitest-environment jsdom
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ArrowRight, ClipboardList, Clock, ListTodo, Send } from 'lucide-react'

import type { Locale } from '../../app/copy'
import { directionForLocale } from '../../app/copy'
import type { Session } from '../../api'
import { ApiError } from '../../api/http'
import { Button, EmptyState, InlineError, Page, PageHeader, Panel, SkeletonList } from '../../ui'
import { buildDashboardKpis, enabledDashboardSources, filterTasksDueToday, type DashboardFeatureFlags, type DashboardSources, type Loadable } from './dashboard-model'
import { listActionableWorkflowStepsInbox, listTasks, listWorkflowInstances, type Task, type WorkflowInboxItem, type WorkflowInstance } from '../workflow/workflow-api'
import './WorkDashboard.css'

type WorkDashboardProps = {
  locale: Locale
  session: Session
  principalRevision: number
  /** Effective scope supplied by PrincipalProvider; reads remain session-scoped except dashboard detail. */
  effectiveScopeId?: string | null
  effectiveScopeLabel?: string | null
  scopeEpoch: number
  scopeReady: boolean
  /**
   * Whether the principal's tenant has the work_management feature enabled.
   * Defaults to `false` so the dashboard fails closed until the projection
   * lands. When false the dashboard drops its inbox/workflow-instances/work-
   * records fetches and hides the approvals/requests KPIs and panels.
   */
  workManagementEnabled?: boolean
  /**
   * Whether the principal is allowed to view tasks. Combines the server
   * `tasks` feature projection with the principal's `tasks.read` or
   * `tasks.list` capability. Defaults to `false` so the dashboard fails
   * closed until the integration lane threads the prop. When false the
   * task source never loads and the Today panel + dueToday/overdue KPIs
   * stay hidden.
   */
  canViewTasks?: boolean
  /** Fail closed until the principal snapshot confirms reporting.dashboard. */
  canViewDashboards: boolean
  canCreateRequest: boolean
  canBrowseServices: boolean
  onCreateRequest: () => void
  onBrowseServices: () => void
  onOpenApprovals: () => void
  onOpenRequests: () => void
  onOpenTasks: () => void
  onOpenDocuments: () => void
  onOpenDashboards: () => void
  onOpenRequestInstance: (instanceId: string) => void
  onOpenApprovalStep: (stepId: string) => void
  onOpenTask: (taskId: string) => void
}
type SourceKey = keyof DashboardSources
type DashboardTask = Task & { due_at?: string | null }

const copy = {
  ar: {
    title: 'الرئيسية', subtitle: 'أولوياتك ومتابعاتك اليومية في مكان واحد.', currentScope: 'النطاق الحالي', scopeUnavailable: 'جارٍ تحديد النطاق',
    prioritySummary: 'ملخص الأولويات', priorityCta: 'افتح صندوق الاعتمادات', newRequest: 'طلب جديد', browseServices: 'استعراض الخدمات', awaitingDecision: 'بانتظار قراري', dueToday: 'مستحقة اليوم', overdue: 'متأخرة', activeRequests: 'طلباتي الجارية',
    whatNeedsYou: 'ما يحتاجك الآن', trackRequests: 'متابعة طلباتي', today: 'اليوم', noDueTasks: 'لا توجد مهام مستحقة اليوم.', noApprovals: 'لا توجد اعتمادات تنتظرك.', noRequests: 'لا توجد طلبات جارية.',
    openRequests: 'طلباتي', loading: 'جارٍ تحميل بيانات العمل…', denied: 'لا تملك صلاحية عرض هذا القسم.', inboxError: 'تعذر تحميل الاعتمادات. أعد المحاولة.', tasksError: 'تعذر تحميل المهام. أعد المحاولة.', requestsError: 'تعذر تحميل الطلبات. أعد المحاولة.', retry: 'إعادة المحاولة',
  },
  en: {
    title: 'Home', subtitle: 'Your priorities and daily follow-ups in one place.', currentScope: 'Current scope', scopeUnavailable: 'Determining scope',
    prioritySummary: 'Priority summary', priorityCta: 'Open approvals inbox', newRequest: 'New request', browseServices: 'Browse services', awaitingDecision: 'Awaiting my decision', dueToday: 'Due today', overdue: 'Overdue', activeRequests: 'My active requests',
    whatNeedsYou: 'What needs you now', trackRequests: 'Tracking my requests', today: 'Today', noDueTasks: 'No tasks are due today.', noApprovals: 'No approvals are waiting for you.', noRequests: 'No active requests yet.',
    openRequests: 'My requests', loading: 'Loading work data…', denied: 'You do not have access to this section.', inboxError: 'We could not load approvals. Try again.', tasksError: 'We could not load tasks. Try again.', requestsError: 'We could not load requests. Try again.', retry: 'Try again',
  },
} as const satisfies Record<Locale, Record<string, string>>

const initialSources = (): DashboardSources => ({ inbox: { state: 'loading' }, tasks: { state: 'loading' }, requests: { state: 'loading' } })

function isItem(value: unknown): value is { id: string; due_at?: string | null; updated_at?: string | null; state?: string } {
  return !!value && typeof value === 'object' && typeof (value as { id?: unknown }).id === 'string'
}

function sourceError<T>(error: unknown): Loadable<T> {
  return error instanceof ApiError && (error.status === 401 || error.status === 403) ? { state: 'denied' } : { state: 'error' }
}

export function WorkDashboard(props: WorkDashboardProps) {
  const {
    locale,
    session,
    principalRevision,
    effectiveScopeId,
    effectiveScopeLabel,
    scopeEpoch,
    scopeReady,
    workManagementEnabled = false,
    canViewTasks = false,
    canCreateRequest,
    canBrowseServices,
    onCreateRequest,
    onBrowseServices,
    onOpenApprovals,
    onOpenRequests,
    onOpenTasks,
    onOpenRequestInstance,
    onOpenApprovalStep,
    onOpenTask,
    // canViewDashboards, onOpenDocuments, onOpenDashboards are accepted by the
    // workspace contract but the dashboard implementation does not consume them
    // directly today. Renaming would break callers, so we silence the warning.
    canViewDashboards: _canViewDashboards,
    onOpenDocuments: _onOpenDocuments,
    onOpenDashboards: _onOpenDashboards,
  } = props
  const t = copy[locale]
  const [sources, setSources] = useState<DashboardSources>(initialSources)
  const featureFlags: DashboardFeatureFlags = { workManagement: workManagementEnabled, tasks: canViewTasks }
  // Memoized: a fresh object every render would retrigger the loading effect
  // below in an infinite loop. Both flags are derived from server-projected
  // values that only change when the principal projection updates.
  const enabledSources = useMemo(
    () => enabledDashboardSources(featureFlags),
    [workManagementEnabled, canViewTasks],
  )
  const sourceEpoch = useRef(0)
  const sourceRequest = useRef<Record<SourceKey, number>>({ inbox: 0, tasks: 0, requests: 0 })

  const loadSource = useCallback(async (source: SourceKey, epoch: number) => {
    if (source === 'tasks' && !canViewTasks) {
      // Fail closed: never call the task API when the principal lacks task
      // read/list access. Leave the source in its initial loading state so
      // the KPI stays null and the panel hides rather than rendering an
      // empty box.
      return
    }
    if (source !== 'tasks' && !workManagementEnabled) {
      // Fail closed: never call the work-management API when the feature is
      // off. Leave the source in its initial loading state so the KPI stays
      // null and the panel hides rather than rendering an empty box.
      return
    }
    const request = ++sourceRequest.current[source]
    setSources((previous) => ({ ...previous, [source]: { state: 'loading' } }))
    try {
      let result: DashboardSources[SourceKey]
      if (source === 'inbox') {
        const items = await listActionableWorkflowStepsInbox(session.access_token)
        result = { state: 'ready', data: items.filter(isItem) as WorkflowInboxItem[] }
      } else if (source === 'tasks') {
        const collection = await listTasks(session.access_token)
        result = { state: 'ready', data: ((collection.items ?? []) as Task[]).filter(isItem) }
      } else {
        const collection = await listWorkflowInstances(session.access_token)
        result = { state: 'ready', data: ((collection.items ?? []) as WorkflowInstance[]).filter(isItem) }
      }
      if (sourceEpoch.current !== epoch || sourceRequest.current[source] !== request) return
      setSources((previous) => ({ ...previous, [source]: result }))
    } catch (error) {
      if (sourceEpoch.current !== epoch || sourceRequest.current[source] !== request) return
      setSources((previous) => ({ ...previous, [source]: sourceError(error) }))
    }
  }, [session.access_token, workManagementEnabled, canViewTasks])
  useEffect(() => {
    const epoch = ++sourceEpoch.current
    setSources(initialSources())
    if (!scopeReady || !effectiveScopeId) return
    const targets = enabledSources.filter((source): source is SourceKey => source === 'inbox' || source === 'tasks' || source === 'requests')
    void Promise.allSettled(targets.map((source) => loadSource(source, epoch)))
  }, [effectiveScopeId, enabledSources, loadSource, principalRevision, scopeEpoch, scopeReady])

  const kpis = useMemo(() => buildDashboardKpis(sources, new Date()), [sources])
  const inboxItems = sources.inbox.state === 'ready' ? (sources.inbox.data as WorkflowInboxItem[]) : []
  const requestsItems = sources.requests.state === 'ready' ? (sources.requests.data as WorkflowInstance[]) : []
  const todayTasks = sources.tasks.state === 'ready' ? filterTasksDueToday<DashboardTask>(sources.tasks.data as DashboardTask[], new Date()) : []
  const retry = (source: SourceKey) => { if (scopeReady && effectiveScopeId) void loadSource(source, sourceEpoch.current) }

  return (
    <div dir={directionForLocale(locale)}>
      <Page aria-labelledby="work-dashboard-heading">
        <PageHeader
          id="work-dashboard-heading"
          title={t.title}
          description={t.subtitle}
          actions={
            <div className="work-dashboard-actions">
              <span className="work-dashboard-scope">{t.currentScope}: {effectiveScopeLabel ?? t.scopeUnavailable}</span>
              {workManagementEnabled && canCreateRequest ? <Button variant="primary" onClick={onCreateRequest}>{t.newRequest}</Button> : null}
              {workManagementEnabled && canBrowseServices ? <Button variant="secondary" onClick={onBrowseServices}>{t.browseServices}</Button> : null}
            </div>
          }
        />
        <div className="work-dashboard-content">
          {workManagementEnabled ? (
            <section className="work-dashboard-priority" aria-label={t.prioritySummary}><Panel id="work-dashboard-priority-strip" title={t.prioritySummary} level={2}><strong>{kpis.awaitingDecision ?? '…'}</strong><Button variant="primary" onClick={onOpenApprovals}><ClipboardList aria-hidden="true" />{t.priorityCta}</Button></Panel></section>
          ) : null}
          <section className="work-dashboard-kpis" aria-label={t.title}>{workManagementEnabled ? <KpiCard label={t.awaitingDecision} value={kpis.awaitingDecision} onOpen={onOpenApprovals} icon={<ClipboardList aria-hidden="true" />} /> : null}{canViewTasks ? <KpiCard label={t.dueToday} value={kpis.dueToday} onOpen={onOpenTasks} icon={<Clock aria-hidden="true" />} /> : null}{canViewTasks ? <KpiCard label={t.overdue} value={kpis.overdue} onOpen={onOpenTasks} icon={<Clock aria-hidden="true" />} /> : null}{workManagementEnabled ? <KpiCard label={t.activeRequests} value={kpis.activeRequests} onOpen={onOpenRequests} icon={<Send aria-hidden="true" />} /> : null}</section>
          {workManagementEnabled ? (
            <section className="work-dashboard-section"><Panel id="work-dashboard-priority-panel" title={t.whatNeedsYou} level={2}><SourceContent source={sources.inbox} loadingLabel={t.loading} deniedLabel={t.denied} errorLabel={t.inboxError} retryLabel={t.retry} onRetry={() => retry('inbox')}>{inboxItems.length === 0 ? <EmptyState icon={<ClipboardList aria-hidden="true" />} title={t.noApprovals} /> : <WorkList items={inboxItems} title={t.whatNeedsYou} onOpen={onOpenApprovalStep} label={(item) => item.source_type ?? item.id} />}</SourceContent></Panel></section>
          ) : null}
          {workManagementEnabled ? (
            <section className="work-dashboard-section"><Panel id="work-dashboard-requests-panel" title={t.trackRequests} level={2}><SourceContent source={sources.requests} loadingLabel={t.loading} deniedLabel={t.denied} errorLabel={t.requestsError} retryLabel={t.retry} onRetry={() => retry('requests')}>{requestsItems.length === 0 ? <EmptyState icon={<Send aria-hidden="true" />} title={t.noRequests} /> : <WorkList items={requestsItems} title={t.trackRequests} onOpen={onOpenRequestInstance} label={(item) => item.id} />}</SourceContent></Panel></section>
          ) : null}
          {canViewTasks ? (
            <section className="work-dashboard-section"><Panel id="work-dashboard-today-panel" title={t.today} level={2}><SourceContent source={sources.tasks} loadingLabel={t.loading} deniedLabel={t.denied} errorLabel={t.tasksError} retryLabel={t.retry} onRetry={() => retry('tasks')}>{todayTasks.length === 0 ? <EmptyState icon={<ListTodo aria-hidden="true" />} title={t.noDueTasks} /> : <WorkList items={todayTasks} title={t.today} onOpen={onOpenTask} label={(item) => item.title ?? item.id} />}</SourceContent></Panel></section>
          ) : null}
        </div>
      </Page>
    </div>
  )
}

function SourceContent<T extends { id: string }>({ source, loadingLabel, deniedLabel, errorLabel, retryLabel, onRetry, children }: { source: Loadable<T[]>; loadingLabel: string; deniedLabel: string; errorLabel: string; retryLabel: string; onRetry: () => void; children: React.ReactNode }) {
  if (source.state === 'loading') return <SkeletonList label={loadingLabel} />
  if (source.state === 'denied') return <p role="status">{deniedLabel}</p>
  if (source.state === 'error') return <InlineError message={errorLabel} retryLabel={retryLabel} onRetry={onRetry} />
  return <>{children}</>
}

function WorkList<T extends { id: string; state?: string }>({ items, title, onOpen, label }: { items: T[]; title: string; onOpen: (id: string) => void; label: (item: T) => string }) {
  return <ol className="work-dashboard-list" aria-label={title}>{items.slice(0, 5).map((item) => <li key={item.id}><Button variant="quiet" className="work-dashboard-list-item" onClick={() => onOpen(item.id)}><span className="work-dashboard-list-title">{label(item)}</span><span className="work-dashboard-list-meta">{item.state ?? '—'}</span><ArrowRight aria-hidden="true" /></Button></li>)}</ol>
}

function KpiCard({ label, value, onOpen, icon }: { label: string; value: number | null; onOpen: () => void; icon: React.ReactNode }) {
  return <Button variant="quiet" className="work-dashboard-kpi" onClick={onOpen}><span className="work-dashboard-kpi-icon">{icon}</span><span className="work-dashboard-kpi-value" aria-live="polite">{value === null ? '…' : value}</span><span className="work-dashboard-kpi-label">{label}</span></Button>
}
