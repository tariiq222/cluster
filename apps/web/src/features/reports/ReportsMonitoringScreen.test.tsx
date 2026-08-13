// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { ReportsMonitoringScreen } from './ReportsMonitoringScreen'
import { ExportsTab } from './ExportsTab'
import {
  EXPORT_POLL_INTERVAL_MS,
  clearTrackedExports,
  exportPollInterval,
  getTrackedExports,
  isTerminalExportStatus,
  registerExport,
  useExportStatus,
  useTrackedExports,
  type TrackedExport,
} from './export-tracker'
import { customFetch } from '../../api/fetcher'

vi.mock('sonner', () => ({ toast: vi.fn() }))
import { toast } from 'sonner'

/*
 * Task 9 — two behavior rules tested before the production migration:
 *
 * 1. Creating a report export answers 202 Accepted and must return control
 *    immediately: the format dialog closes, the trigger is re-enabled, a
 *    sonner preparation toast fires, and the export is registered in the
 *    session-local tracker so it appears in the Exports tab. No blocking
 *    overlay, no polling inside the Reports tab.
 * 2. Export polling stops the moment the export reaches a terminal status
 *    (ready/completed/available or failed/error/cancelled) — no eternal
 *    refetch loop.
 */

const REPORT_ID = '01980f50-5f0d-7000-8000-000000000101'
const EXPORT_ID = '01980f50-5f0d-7000-8000-000000000102'

const reportFixture = {
  id: REPORT_ID,
  resource_type: 'report',
  title: 'تقرير أداء المستشفى',
  status: 'published',
  classification: 'internal',
  version_number: 3,
  values: { open_records: 12, closed_records: 8 },
  lock_version: 1,
  created_at: '2026-08-01T08:00:00Z',
  updated_at: '2026-08-01T08:00:00Z',
} as const

const reportDetail = {
  ...reportFixture,
  description: 'ملخص أداء المرافق خلال الربع الثالث.',
}

const exportEntity = {
  id: EXPORT_ID,
  resource_type: 'export',
  status: 'available',
  classification: 'internal',
  lock_version: 1,
  created_at: '2026-08-01T09:00:00Z',
  updated_at: '2026-08-01T09:00:00Z',
}

const queryResult = (items: unknown[]) => ({
  data: { items, next_cursor: null },
  isLoading: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
})

vi.mock('../../api/hooks', () => ({
  useReportsList: () => queryResult([reportFixture]),
  useAuditEvents: () => queryResult([]),
}))

vi.mock('../../api/fetcher', () => ({ customFetch: vi.fn() }))

const principalState = vi.hoisted(() => ({
  capabilities: [] as string[],
  effectiveScope: null as { scopeType: string; scopeId: string; label: string } | null,
}))

vi.mock('../../app/principal-context', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../app/principal-context')>()
  return {
    ...actual,
    usePrincipal: () => ({
      state: 'ready',
      capabilities: principalState.capabilities,
      features: { tasks: true },
      effectiveScope: principalState.effectiveScope,
      availableScopes: [],
      revision: 0,
      scopeEpoch: 0,
      scopeReady: true,
      refresh: () => {},
      selectScope: async () => {},
    }),
  }
})

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function mount(node: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <MemoryRouter>{node}</MemoryRouter>
      </SessionProvider>
    </QueryClientProvider>,
  )
}

function fetcherResponse(payload: unknown, status = 200) {
  return { data: { data: payload }, status, headers: new Headers() }
}

beforeEach(() => {
  clearTrackedExports()
  principalState.capabilities = []
  principalState.effectiveScope = null
  vi.mocked(customFetch).mockReset()
  vi.mocked(toast).mockReset()
})

describe('report export creation stays non-blocking', () => {
  it('closes the format dialog on the 202, fires a preparation toast, and registers the export in the Exports tab', async () => {
    principalState.capabilities = ['reporting.read', 'reporting.export']
    vi.mocked(customFetch).mockImplementation(async (url: string) => {
      if (url.includes('/reports/') && url.includes('/exports')) return fetcherResponse(exportEntity, 202)
      if (url.includes('/exports/')) return fetcherResponse(exportEntity, 200)
      if (url.includes('/reports/')) return fetcherResponse(reportDetail, 200)
      return fetcherResponse({}, 200)
    })

    mount(<ReportsMonitoringScreen />)

    // The Reports tab lists the fixture report; select it to load the detail.
    fireEvent.click(await screen.findByRole('button', { name: 'تقرير أداء المستشفى' }))
    fireEvent.click(await screen.findByRole('button', { name: 'تصدير' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('button', { name: 'إنشاء التصدير' })).toBeEnabled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'إنشاء التصدير' }))

    // The 202 returns control immediately: the dialog closes, the trigger is
    // re-enabled, the preparation toast fires, and no blocking overlay exists.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(vi.mocked(toast)).toHaveBeenCalledWith('جارٍ تجهيز التصدير…')
    expect(screen.getByRole('button', { name: 'تصدير' })).toBeEnabled()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()

    // The export is tracked for the current session user and appears in the
    // Exports tab with its metadata. Radix Tabs activates on pointer-down,
    // which is how the workspace reacts to a real click.
    expect(getTrackedExports(session.userId)).toContainEqual(
      expect.objectContaining({ id: EXPORT_ID, kind: 'report', format: 'csv' }),
    )
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'التصديرات' }))
    await screen.findByText('تقرير أداء المستشفى')
    expect(screen.getByText('CSV')).toBeInTheDocument()
    // The export row polls through getExport (real timers here) and settles
    // on the terminal ready status, enabling the download affordance.
    expect(await screen.findByText('جاهز')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'تنزيل الملف' })).toBeEnabled()
  })

  it('keeps report reading available without the export capability and exposes no export trigger or dialog', async () => {
    principalState.capabilities = ['reporting.read']
    vi.mocked(customFetch).mockImplementation(async (url: string) => {
      if (url.includes('/reports/')) return fetcherResponse(reportDetail, 200)
      return fetcherResponse({}, 200)
    })

    mount(<ReportsMonitoringScreen />)

    // Report reading still works: the report lists, selects, and its detail
    // card resolves without the export capability.
    fireEvent.click(await screen.findByRole('button', { name: 'تقرير أداء المستشفى' }))
    expect(await screen.findByText('تفاصيل التقرير')).toBeInTheDocument()

    // The export trigger is gated on reporting.export: no button, no format
    // dialog, and no Exports tab appear for a read-only reporter.
    expect(screen.queryByRole('button', { name: 'تصدير' })).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'التصديرات' })).not.toBeInTheDocument()

    // No export request was ever issued against the fixture report.
    const exportCalls = vi.mocked(customFetch).mock.calls.filter(([url]) => url.includes('/exports'))
    expect(exportCalls).toHaveLength(0)
  })

  it('hides the Exports tab without an export/download capability and without tracked exports', async () => {
    principalState.capabilities = ['reporting.read']
    vi.mocked(customFetch).mockResolvedValue(fetcherResponse({}, 200))

    mount(<ReportsMonitoringScreen />)

    expect(screen.queryByRole('tab', { name: 'التصديرات' })).not.toBeInTheDocument()
  })

  it('shows the Exports tab when a tracked export exists even without an export capability', async () => {
    principalState.capabilities = ['reporting.read']
    registerExport({
      id: EXPORT_ID,
      kind: 'report',
      name: 'تقرير أداء المستشفى',
      format: 'csv',
      createdAt: '2026-08-01T09:00:00Z',
      ownerUserId: session.userId,
    })
    vi.mocked(customFetch).mockImplementation(async (url: string) => {
      if (url.includes('/exports/')) return fetcherResponse(exportEntity, 200)
      return fetcherResponse({}, 200)
    })

    mount(<ReportsMonitoringScreen />)

    expect(screen.getByRole('tab', { name: 'التصديرات' })).toBeInTheDocument()
  })
})

describe('export polling stops at terminal states', () => {
  it('does not refetch after the export resolves to a terminal ready status', async () => {
    const entry: TrackedExport = {
      id: EXPORT_ID,
      kind: 'report',
      name: 'تقرير أداء المستشفى',
      format: 'csv',
      createdAt: '2026-08-01T09:00:00Z',
      ownerUserId: session.userId,
    }
    const queued = { ...exportEntity, status: 'queued' }

    vi.useFakeTimers()
    try {
      vi.mocked(customFetch).mockResolvedValueOnce(fetcherResponse(queued, 200))

      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
      const { result } = renderHook(() => useExportStatus(entry), {
        wrapper: ({ children }) => (
          <QueryClientProvider client={client}>
            <SessionProvider session={session} locale="ar" setLocale={() => {}}>
              {children}
            </SessionProvider>
          </QueryClientProvider>
        ),
      })

      await act(async () => {
        await vi.advanceTimersByTimeAsync(0)
      })
      expect(customFetch).toHaveBeenCalledTimes(1)
      expect(result.current.data?.status).toBe('queued')

      vi.mocked(customFetch).mockResolvedValueOnce(fetcherResponse(exportEntity, 200))
      await act(async () => {
        await vi.advanceTimersByTimeAsync(EXPORT_POLL_INTERVAL_MS)
      })
      // react-query's notifyManager schedules observer notifications on a
      // setTimeout(0) chain; under fake timers those need an explicit flush
      // so the fresh data commits and the interval is cleared.
      await act(async () => {
        await vi.runAllTimersAsync()
      })
      expect(customFetch).toHaveBeenCalledTimes(2)
      expect(result.current.data?.status).toBe('available')
      expect(exportPollInterval({ state: { data: result.current.data } })).toBe(false)

      // The interval function returned false at the terminal status: further
      // elapsed time must not schedule another fetch.
      await act(async () => {
        await vi.advanceTimersByTimeAsync(EXPORT_POLL_INTERVAL_MS * 5)
      })
      expect(customFetch).toHaveBeenCalledTimes(2)
    } finally {
      vi.useRealTimers()
    }
  })

  it('returns the poll interval until the status turns terminal', () => {
    expect(exportPollInterval({ state: { data: { status: 'queued' } } })).toBe(EXPORT_POLL_INTERVAL_MS)
    expect(exportPollInterval({ state: { data: { status: 'preparing' } } })).toBe(EXPORT_POLL_INTERVAL_MS)
    expect(exportPollInterval({ state: { data: undefined } })).toBe(EXPORT_POLL_INTERVAL_MS)
    expect(exportPollInterval({ state: { data: { status: 'ready' } } })).toBe(false)
    expect(exportPollInterval({ state: { data: { status: 'completed' } } })).toBe(false)
    expect(exportPollInterval({ state: { data: { status: 'available' } } })).toBe(false)
    expect(exportPollInterval({ state: { data: { status: 'failed' } } })).toBe(false)
    expect(exportPollInterval({ state: { data: { status: 'cancelled' } } })).toBe(false)
  })

  it('treats ready/completed/available and failed/error/cancelled as terminal', () => {
    for (const status of ['ready', 'completed', 'available', 'failed', 'error', 'cancelled']) {
      expect(isTerminalExportStatus(status)).toBe(true)
    }
    expect(isTerminalExportStatus('queued')).toBe(false)
    expect(isTerminalExportStatus('preparing')).toBe(false)
    expect(isTerminalExportStatus(undefined)).toBe(false)
    expect(isTerminalExportStatus(null)).toBe(false)
  })
})

describe('export tracker is scoped to the session user', () => {
  it('exposes an empty tracker and never polls a previous user export', async () => {
    // User A created an export earlier in the same browser process.
    registerExport({
      id: EXPORT_ID,
      kind: 'report',
      name: 'تقرير أداء المستشفى',
      format: 'csv',
      createdAt: '2026-08-01T09:00:00Z',
      ownerUserId: 'user-a',
    })
    vi.mocked(customFetch).mockResolvedValue(fetcherResponse(exportEntity, 200))

    // User B renders the Exports tab in the same process: user A's export
    // name/ID must be absent and no poll request may be issued for it.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <SessionProvider
          session={{ csrfToken: 'y', userId: 'user-b', expiresAt: '2026-12-31T00:00:00Z', restricted: false }}
          locale="ar"
          setLocale={() => {}}
        >
          <ExportsTab />
        </SessionProvider>
      </QueryClientProvider>,
    )

    await screen.findByText('لا توجد تصديرات بعد.')
    expect(screen.queryByText('تقرير أداء المستشفى')).not.toBeInTheDocument()
    expect(screen.queryByText('CSV')).not.toBeInTheDocument()

    // Flush any microtask-scheduled polling: nothing may query user A's
    // export endpoint on behalf of user B.
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0))
    })
    const exportCalls = vi
      .mocked(customFetch)
      .mock.calls.filter(([url]) => url.includes(`/exports/${EXPORT_ID}`))
    expect(exportCalls).toHaveLength(0)
  })

  it('switching the session user empties the tracker snapshot immediately', async () => {
    registerExport({
      id: EXPORT_ID,
      kind: 'report',
      name: 'تقرير أداء المستشفى',
      format: 'csv',
      createdAt: '2026-08-01T09:00:00Z',
      ownerUserId: 'user-a',
    })

    // Direct store reads stay partitioned by owner.
    expect(getTrackedExports('user-a')).toHaveLength(1)
    expect(getTrackedExports('user-b')).toHaveLength(0)

    // The hook exposes an empty snapshot under a user-b session right away.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { result } = renderHook(() => useTrackedExports(), {
      wrapper: ({ children }) => (
        <QueryClientProvider client={client}>
          <SessionProvider
            session={{ csrfToken: 'y', userId: 'user-b', expiresAt: '2026-12-31T00:00:00Z', restricted: false }}
            locale="ar"
            setLocale={() => {}}
          >
            {children}
          </SessionProvider>
        </QueryClientProvider>
      ),
    })
    expect(result.current).toEqual([])
  })
})
