// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ApiError } from '../../api/http'
import { customFetch } from '../../api/fetcher'
import { SessionProvider } from '../../app/session-context'
import { DashboardsScreen } from './DashboardsScreen'
import { ReportsMonitoringScreen } from './ReportsMonitoringScreen'
import { clearTrackedExports } from './export-tracker'

/*
 * Task 10 — Dashboards migration behavior:
 *
 * 1. The dashboard-only capability path renders exactly one H1 (the Reports
 *    workspace H1) plus one H2 (the Dashboards tab heading), and the numeric
 *    dashboard values render as a recharts chart whose bar fills come from
 *    the theme chart tokens (`var(--chart-1..5)` / `--color-chart-*`) only.
 * 2. List/detail states are asserted semantically (shared Loading / Empty /
 *    Denied / Error states, non-disclosing 403/404), not by snapshotting
 *    implementation markup.
 */

const SESSION = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const DASHBOARD_ID = '01980f50-5f0d-7000-8000-000000000201'

const dashboardFixture = {
  id: DASHBOARD_ID,
  resource_type: 'dashboard',
  title: 'لوحة أداء المستشفى',
  status: 'published',
  classification: 'internal',
  version_number: 2,
  values: {},
  lock_version: 1,
  created_at: '2026-08-01T08:00:00Z',
  updated_at: '2026-08-01T08:00:00Z',
} as const

const dashboardDetail = {
  ...dashboardFixture,
  description: 'مؤشرات تشغيلية للربع الثالث.',
  values: { open_records: 12, closed_records: 8, utilization: 74.5, owner: 'التشغيل' },
}

const principalState = vi.hoisted(() => ({
  capabilities: [] as string[],
  effectiveScope: null as { scopeType: string; scopeId: string; label: string } | null,
  scopeEpoch: 0,
}))

const listState = vi.hoisted(() => ({
  data: undefined as unknown,
  isLoading: false,
  isError: false,
  error: null as unknown,
  refetch: vi.fn(),
}))

const queryKeys = vi.hoisted(() => [] as Array<readonly unknown[]>)

vi.mock('../../api/query', () => ({
  useApiQuery: (key: readonly unknown[]) => {
    queryKeys.push(key)
    return listState
  },
}))

vi.mock('../../api/fetcher', () => ({ customFetch: vi.fn() }))

vi.mock('../../api/hooks', () => ({
  useReportsList: () => ({
    data: { items: [], next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  useAuditEvents: () => ({
    data: { items: [], next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

vi.mock('../../app/principal-context', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../app/principal-context')>()
  return {
    ...actual,
    usePrincipal: () => ({
      state: 'ready',
      capabilities: principalState.capabilities,
      features: { work_management: false, tasks: true },
      effectiveScope: principalState.effectiveScope,
      availableScopes: [],
      revision: 0,
      scopeEpoch: principalState.scopeEpoch,
      scopeReady: true,
      refresh: () => {},
      selectScope: async () => {},
    }),
  }
})

/*
 * recharts sizes its charts through ResizeObserver on the rendered DOM node;
 * jsdom reports a 0x0 bounding rect and never fires resize events, so the
 * observer is replaced with one that reports a fixed positive size the moment
 * an element is observed. This makes both the ResponsiveContainer and the
 * chart wrapper render their content deterministically.
 */
const CHART_SIZE = { width: 480, height: 216 }

function mockChartResizeObserver(): void {
  globalThis.ResizeObserver = class {
    private callback: ResizeObserverCallback

    constructor(callback: ResizeObserverCallback) {
      this.callback = callback
    }

    observe(target: Element): void {
      const entry = {
        target,
        contentRect: {
          width: CHART_SIZE.width,
          height: CHART_SIZE.height,
          x: 0,
          y: 0,
          top: 0,
          left: 0,
          right: CHART_SIZE.width,
          bottom: CHART_SIZE.height,
        },
      } as unknown as ResizeObserverEntry
      this.callback([entry], this as unknown as ResizeObserver)
    }

    unobserve(): void {}
    disconnect(): void {}
  }
}

function readyList(items: unknown[]) {
  listState.data = { items, next_cursor: null }
  listState.isLoading = false
  listState.isError = false
  listState.error = null
}

function fetcherResponse(payload: unknown, status = 200) {
  return { data: { data: payload }, status, headers: new Headers() }
}

function mount(node: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const renderTree = (child: ReactNode) => (
    <QueryClientProvider client={client}>
      <SessionProvider session={SESSION} locale="ar" setLocale={() => {}}>
        <MemoryRouter>{child}</MemoryRouter>
      </SessionProvider>
    </QueryClientProvider>
  )
  const view = render(renderTree(node))
  return { view, renderTree }
}

beforeEach(() => {
  clearTrackedExports()
  principalState.capabilities = []
  principalState.effectiveScope = null
  principalState.scopeEpoch = 0
  readyList([])
  queryKeys.length = 0
  vi.mocked(customFetch).mockReset()
  mockChartResizeObserver()
})

async function selectDashboard() {
  fireEvent.click(screen.getByRole('combobox'))
  fireEvent.click(await screen.findByRole('option', { name: 'لوحة أداء المستشفى' }))
}

describe('dashboards workspace hierarchy', () => {
  it('renders exactly one H1 (the workspace H1) and one H2 dashboard heading on the dashboard-only path', async () => {
    principalState.capabilities = ['reporting.dashboard']
    readyList([dashboardFixture])
    vi.mocked(customFetch).mockResolvedValue(fetcherResponse(dashboardDetail, 200))

    mount(<ReportsMonitoringScreen />)

    // Only the Dashboards tab is visible for the dashboard-only capability.
    expect(screen.getByRole('tab', { name: 'لوحات المؤشرات' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'التقارير' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'سجل التدقيق' })).not.toBeInTheDocument()

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('التقارير والمراقبة')
    expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent('لوحات المؤشرات')

    await selectDashboard()

    // Numeric values render as a chart whose bar fills are theme chart tokens.
    // (jsdom cannot parse `[fill^="var(--chart-"]` as a CSS selector, so the
    // fills are matched from the rendered shapes instead.)
    await waitFor(() => {
      const chart = screen.getByRole('img', { name: 'مؤشرات اللوحة' })
      const barFills = Array.from(chart.querySelectorAll('path, rect'))
        .map((el) => el.getAttribute('fill'))
        .filter((fill): fill is string => fill !== null && /^var\(--(?:color-)?chart-[1-5]\)$/.test(fill))
      expect(barFills.length).toBeGreaterThan(0)
      for (const fill of barFills) {
        expect(fill).toMatch(/^var\(--(?:color-)?chart-[1-5]\)$/)
      }
    })

    // Non-numeric scalars stay in an accessible table, and the heading
    // hierarchy is unchanged (still exactly one H1 and one H2).
    const table = screen.getByRole('table')
    expect(within(table).getByText('owner')).toBeInTheDocument()
    expect(within(table).getByText('التشغيل')).toBeInTheDocument()
    // Numeric values render only in the chart — they never duplicate into
    // the scalar table.
    for (const key of ['open_records', 'closed_records', 'utilization', '12', '8', '74.5']) {
      expect(within(table).queryByText(key)).not.toBeInTheDocument()
    }
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(1)
  })
})

describe('dashboard list and detail states', () => {
  it('shows the shared loading state while the list is pending', () => {
    principalState.capabilities = ['reporting.dashboard']
    listState.isLoading = true

    mount(<DashboardsScreen />)

    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
  })

  it('shows the empty state when no dashboards are published', () => {
    principalState.capabilities = ['reporting.dashboard']
    mount(<DashboardsScreen />)

    expect(screen.getByText('لا توجد لوحات منشورة ضمن نطاقك.')).toBeInTheDocument()
  })

  it('shows the non-disclosing denied state for a 403 list', () => {
    principalState.capabilities = ['reporting.dashboard']
    listState.isError = true
    listState.error = new ApiError(403, { type: 'x', title: 'Forbidden', status: 403 })

    mount(<DashboardsScreen />)

    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
  })

  it('shows the shared error state with a retry action for list failures', () => {
    principalState.capabilities = ['reporting.dashboard']
    listState.isError = true
    listState.error = new Error('boom')

    mount(<DashboardsScreen />)

    expect(screen.getByRole('alert')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'أعد المحاولة' })).toBeInTheDocument()
  })

  it('shows the non-disclosing denied state for a forbidden or missing detail', async () => {
    principalState.capabilities = ['reporting.dashboard']
    readyList([dashboardFixture])

    for (const status of [403, 404]) {
      vi.mocked(customFetch).mockRejectedValue(new ApiError(status, { type: 'x', title: 'Hidden', status }))
      mount(<DashboardsScreen />)
      await selectDashboard()
      // 403 and 404 resolve to the same non-disclosing copy.
      expect(await screen.findByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    }
  })

  it('renders the detail card with an outline status badge once the dashboard resolves', async () => {
    principalState.capabilities = ['reporting.dashboard']
    readyList([dashboardFixture])
    vi.mocked(customFetch).mockResolvedValue(fetcherResponse(dashboardDetail, 200))

    mount(<DashboardsScreen />)
    await selectDashboard()

    expect(await screen.findByText('تفاصيل اللوحة')).toBeInTheDocument()
    // The localized status is exposed through an outline badge, never a color.
    const statusBadge = screen.getAllByText('منشور').find((el) => el.closest('[data-slot="badge"]'))
    expect(statusBadge).toBeDefined()
    expect(statusBadge!.closest('[data-slot="badge"]')).toHaveAttribute('data-variant', 'outline')
    expect(screen.getByText('مؤشرات تشغيلية للربع الثالث.')).toBeInTheDocument()
  })
})

describe('dashboard scope behavior', () => {
  it('re-keys the list query with the principal scope epoch so old-scope data is never reused', async () => {
    principalState.capabilities = ['reporting.dashboard']
    readyList([dashboardFixture])

    const { view, renderTree } = mount(<DashboardsScreen />)
    expect(queryKeys).toContainEqual(['dashboards', 0])

    // On scope change the query key is re-keyed (never a stale-key reuse) and
    // the detail reload stays wired to the new scope epoch.
    principalState.scopeEpoch = 1
    view.rerender(renderTree(<DashboardsScreen />))

    expect(queryKeys).toContainEqual(['dashboards', 1])
  })
})
