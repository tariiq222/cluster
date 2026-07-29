// @vitest-environment node
export type Loadable<T> =
  | { state: 'loading' }
  | { state: 'ready'; data: T }
  | { state: 'denied' }
  | { state: 'error' }

export type DashboardKpis = {
  awaitingDecision: number | null
  dueToday: number | null
  overdue: number | null
  activeRequests: number | null
}

export type DashboardSources = {
  inbox: Loadable<Array<{ id: string; due_at?: string | null; updated_at?: string | null }>>
  tasks: Loadable<Array<{ id: string; due_at?: string | null; updated_at?: string | null; state?: string }>>
  requests: Loadable<Array<{ id: string; updated_at?: string | null; state?: string }>>
}

/**
 * Feature flags that gate which dashboard sources are allowed. When
 * `workManagement` is false (or null while loading), the inbox/requests
 * sources are NOT loaded: their KPIs stay `null` and the UI never issues
 * any work-management API call. When `tasks` is false the task source is
 * not loaded and its KPIs/panels stay hidden — the dashboard fails
 * closed for principals without task read/list access.
 */
export type DashboardFeatureFlags = {
  workManagement: boolean
  tasks: boolean
}

export function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

export function metricValue<T>(loadable: Loadable<T[]>): number | null {
  if (loadable.state === 'ready') return loadable.data.length
  return null
}

const ACTIVE_REQUEST_STATES = new Set([
  'active',
  'running',
  'waiting',
  'submitted',
  'pending',
  'pending_review',
  'in_progress',
])

export function isActiveRequest(request: { state?: string }): boolean {
  return typeof request.state === 'string' && ACTIVE_REQUEST_STATES.has(request.state)
}

export function filterTasksDueToday<T extends { due_at?: string | null }>(tasks: T[], now: Date): T[] {
  return tasks.filter((task) => {
    if (!task.due_at) return false
    const dueAt = new Date(task.due_at)
    return !Number.isNaN(dueAt.getTime()) && isSameDay(dueAt, now)
  })
}

export function buildDashboardKpis(sources: DashboardSources, now: Date): DashboardKpis {
  const inboxCount = metricValue(sources.inbox)
  const requestsCount = sources.requests.state === 'ready'
    ? sources.requests.data.filter(isActiveRequest).length
    : null
  let dueTodayCount: number | null = null
  let overdueCount: number | null = null
  if (sources.tasks.state === 'ready') {
    dueTodayCount = 0
    overdueCount = 0
    for (const task of sources.tasks.data) {
      const dueAt = task.due_at ? new Date(task.due_at) : null
      if (!dueAt || Number.isNaN(dueAt.getTime())) continue
      if (isSameDay(dueAt, now)) dueTodayCount += 1
      else if (dueAt.getTime() < now.getTime()) overdueCount += 1
    }
  }
  return {
    awaitingDecision: inboxCount,
    dueToday: dueTodayCount,
    overdue: overdueCount,
    activeRequests: requestsCount,
  }
}

/**
 * Returns the set of source keys the dashboard should actually fetch given
 * the feature flags. Treats `workManagement === true` as enabling the
 * inbox/requests sources and `tasks === true` as enabling the task source;
 * any missing/false flag keeps its source unloaded so the UI fails closed
 * until the server projection lands.
 */
export function enabledDashboardSources(
  flags: DashboardFeatureFlags | null,
): Array<'inbox' | 'tasks' | 'requests'> {
  const workManagement = flags?.workManagement === true
  const tasks = flags?.tasks === true
  const sources: Array<'inbox' | 'tasks' | 'requests'> = []
  if (workManagement) sources.push('inbox', 'requests')
  if (tasks) sources.push('tasks')
  return sources
}
