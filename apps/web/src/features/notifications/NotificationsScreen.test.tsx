// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { NotificationsScreen } from './NotificationsScreen'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const navigateMock = vi.hoisted(() => vi.fn())

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: ['notifications.read'],
    features: { work_management: false, tasks: true },
    effectiveScope: { scopeType: 'facility', scopeId: 'f1', label: 'منشأة أ' },
    availableScopes: [],
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
}))

vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const generated = vi.hoisted(() => ({
  listMyNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
}))

vi.mock('../../api/generated/cluster', () => generated)

const response = (data: unknown, status = 200) => ({ status, data, headers: new Headers() })

const notification = (overrides: Record<string, unknown>) => ({
  id: 'n1',
  title: 'إشعار مهم',
  source: { source_module: 'tasks', record_type: 'task', record_id: 'task-1' },
  is_read: false,
  created_at: '2026-07-01T09:00:00Z',
  ...overrides,
})

function mount(client?: QueryClient) {
  cleanup()
  const queryClient = client ?? new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <NotificationsScreen />
      </SessionProvider>
    </QueryClientProvider>,
  )
  return queryClient
}

beforeEach(() => {
  navigateMock.mockReset()
  generated.listMyNotifications.mockReset()
  generated.markNotificationRead.mockReset()
})

describe('notifications screen', () => {
  it('renders a vertical semantic list with unread dot and heavier weight, never a tinted row', async () => {
    generated.listMyNotifications.mockResolvedValue(
      response({ items: [notification({}), notification({ id: 'n2', title: 'مقروء', is_read: true })], next_cursor: null }),
    )
    mount()

    const list = await screen.findByRole('list')
    expect(list).toBeInTheDocument()
    const rows = screen.getAllByRole('listitem')
    expect(rows).toHaveLength(2)

    const unreadRow = screen.getByText('إشعار مهم').closest('li')!
    const readRow = screen.getByText('مقروء').closest('li')!

    // Unread marker is a bg-primary dot, read state is never a tinted background.
    expect(unreadRow.querySelector('.bg-primary')).not.toBeNull()
    expect(readRow.querySelector('.bg-primary')).toBeNull()
    expect(readRow.className).not.toMatch(/bg-muted|bg-card|bg-accent|bg-primary/)
    expect(screen.getByText('إشعار مهم').className).toContain('font-medium')
    expect(screen.getByText('مقروء').className).not.toContain('font-medium')
  })

  it('marks a notification read and navigates to its destination in one action', async () => {
    generated.listMyNotifications.mockResolvedValue(
      response({ items: [notification({})], next_cursor: null }),
    )
    generated.markNotificationRead.mockResolvedValue(response({}))
    mount()

    fireEvent.click(await screen.findByRole('button', { name: /إشعار مهم/ }))

    await waitFor(() => {
      expect(generated.markNotificationRead).toHaveBeenCalledWith('n1', expect.anything())
      expect(navigateMock).toHaveBeenCalledWith('/tasks/task-1')
    })
  })

  it('updates the cached row as InfiniteData without throwing before the refetch', async () => {
    // First page arrives unread; any background refetch after invalidation
    // reflects the server-side read state so the assertion is not racy.
    generated.listMyNotifications
      .mockResolvedValueOnce(response({ items: [notification({})], next_cursor: null }))
      .mockResolvedValue(response({ items: [notification({ is_read: true })], next_cursor: null }))
    generated.markNotificationRead.mockResolvedValue(response({}))
    const client = mount()

    fireEvent.click(await screen.findByRole('button', { name: /إشعار مهم/ }))

    await waitFor(() => {
      const cached = client.getQueryData(['notifications'])
      expect(cached).not.toBeUndefined()
      if (!cached) return
      // The cache entry keeps the InfiniteData shape (pages + pageParams), and
      // the target row is already marked read — never replaced by undefined.
      expect(cached).toMatchObject({
        pages: [{ items: [{ id: 'n1', is_read: true }] }],
        pageParams: [undefined],
      })
    })
    expect(navigateMock).toHaveBeenCalledWith('/tasks/task-1')
  })

  it('loads the next page with a More button, not infinite scroll', async () => {
    generated.listMyNotifications
      .mockResolvedValueOnce(response({ items: [notification({})], next_cursor: 'c2' }))
      .mockResolvedValue(response({ items: [notification({ id: 'n3', title: 'الصفحة الثانية' })], next_cursor: null }))
    mount()

    const more = await screen.findByRole('button', { name: 'عرض المزيد' })
    fireEvent.click(more)

    await waitFor(() => {
      expect(screen.getByText('الصفحة الثانية')).toBeInTheDocument()
    })
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'عرض المزيد' })).not.toBeInTheDocument()
    })
    expect(generated.listMyNotifications).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: 'c2' }),
      expect.anything(),
    )
  })

  it('renders the empty state when there are no notifications', async () => {
    generated.listMyNotifications.mockResolvedValue(response({ items: [], next_cursor: null }))
    mount()
    expect(await screen.findByText('لا توجد إشعارات')).toBeInTheDocument()
  })

  it('renders through the shared PageLayout + PageHeader shell with exactly one H1', async () => {
    generated.listMyNotifications.mockResolvedValue(response({ items: [], next_cursor: null }))
    mount()
    const shell = document.querySelector('.mx-auto.w-full.max-w-6xl.min-w-0.space-y-6')
    expect(shell).not.toBeNull()
    const heading = screen.getByRole('heading', { level: 1 })
    expect(heading).toHaveTextContent('الإشعارات')
    expect(shell!.contains(heading)).toBe(true)
  })
})
