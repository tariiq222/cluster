// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { buildDashboardKpis, filterTasksDueToday, metricValue, type DashboardSources } from './dashboard-model'

const NOW = new Date('2026-07-23T08:00:00Z')

function sources(inbox: DashboardSources['inbox'], tasks: DashboardSources['tasks'], requests: DashboardSources['requests']): DashboardSources {
  return { inbox, tasks, requests }
}

const readyInbox = { state: 'ready' as const, data: [{ id: 'a' }, { id: 'b' }] }
const readyTasks = {
  state: 'ready' as const,
  data: [
    { id: 't1', due_at: '2026-07-23T18:00:00Z' },
    { id: 't2', due_at: '2026-07-20T18:00:00Z' },
    { id: 't3', due_at: '2026-07-25T18:00:00Z' },
  ],
}
const readyRequests = {
  state: 'ready' as const,
  data: [
    { id: 'r1', state: 'active' },
    { id: 'r0', state: 'running' },
    { id: 'r2', state: 'completed' },
    { id: 'r3', state: 'cancelled' },
  ],
}

describe('dashboard-model', () => {
  it('derives the four KPIs from the same authorized collections', () => {
    expect(buildDashboardKpis(sources(readyInbox, readyTasks, readyRequests), NOW)).toEqual({
      awaitingDecision: 2,
      dueToday: 1,
      overdue: 1,
      activeRequests: 2,
    })
  })

  it('does not turn loading or failed sources into zero', () => {
    expect(metricValue({ state: 'loading' })).toBeNull()
    expect(metricValue({ state: 'error' })).toBeNull()
  })

  it('keeps KPIs null while any source is loading', () => {
    const kpis = buildDashboardKpis(
      sources({ state: 'loading' }, readyTasks, readyRequests),
      NOW,
    )
    expect(kpis.awaitingDecision).toBeNull()
    expect(kpis.dueToday).toBe(1)
    expect(kpis.overdue).toBe(1)
    expect(kpis.activeRequests).toBe(2)
  })

  it('keeps the Today list limited to tasks due on the current calendar day', () => {
    expect(filterTasksDueToday(readyTasks.data, NOW).map((task) => task.id)).toEqual(['t1'])
  })

  it('counts an earlier time today as due today rather than overdue', () => {
    const tasks = {
      state: 'ready' as const,
      data: [{ id: 'earlier-today', due_at: '2026-07-23T06:00:00Z' }],
    }

    expect(buildDashboardKpis(sources(readyInbox, tasks, readyRequests), NOW)).toMatchObject({
      dueToday: 1,
      overdue: 0,
    })
  })
})
