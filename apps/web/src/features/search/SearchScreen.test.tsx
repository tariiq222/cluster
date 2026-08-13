// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { SearchScreen } from './SearchScreen'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

/*
 * Mutable principal snapshot: scopeEpoch starts at 0 and is bumped by tests to
 * simulate a real scope switch, then a rerender re-reads the new value.
 */
const principalState = vi.hoisted(() => ({ scopeEpoch: 0 }))

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: ['search.query'],
    features: { tasks: true },
    effectiveScope: { scopeType: 'facility', scopeId: 'f1', label: 'منشأة أ' },
    availableScopes: [],
    revision: 0,
    scopeEpoch: principalState.scopeEpoch,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
}))

const generated = vi.hoisted(() => ({ search: vi.fn() }))

vi.mock('../../api/generated/cluster', () => generated)

const response = (data: unknown, status = 200) => ({ status, data, headers: new Headers() })

const taskHit = {
  id: 'task-1',
  resource_type: 'task',
  title: 'مهمة أبجد',
  status: 'draft',
  description: 'وصف المهمة',
}
const documentHit = {
  id: 'doc-1',
  resource_type: 'document',
  title: 'مستند أبجد',
  status: 'approved',
}

function tree(client: QueryClient) {
  return (
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <MemoryRouter>
          <SearchScreen />
        </MemoryRouter>
      </SessionProvider>
    </QueryClientProvider>
  )
}

function mount() {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return { view: render(tree(client)), client }
}

beforeEach(() => {
  principalState.scopeEpoch = 0
  generated.search.mockReset()
})

describe('search screen', () => {
  it('runs a query with type and status filters supported by the contract', async () => {
    generated.search.mockResolvedValue(response({ items: [taskHit], next_cursor: null }))
    mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'أبجد' } })

    // Type filter: task
    fireEvent.click(screen.getByRole('combobox', { name: 'النوع' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مهمة' }))
    // Status filter: draft
    fireEvent.click(screen.getByRole('combobox', { name: 'الحالة' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مسودة' }))

    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    await waitFor(() => {
      expect(generated.search).toHaveBeenCalledWith(
        expect.objectContaining({ q: 'أبجد', type: 'task', status: 'draft' }),
        expect.anything(),
      )
    })
  })

  it('groups results by type with an icon and label', async () => {
    generated.search.mockResolvedValue(response({ items: [taskHit, documentHit], next_cursor: null }))
    mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'أبجد' } })
    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    expect(await screen.findByRole('heading', { name: /مهمة/ })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /مستند/ })).toBeInTheDocument()
    expect(screen.getByText('مهمة أبجد')).toBeInTheDocument()
    expect(screen.getByText('مستند أبجد')).toBeInTheDocument()
  })

  it('omits type and status after narrowing a filter and switching it back to All', async () => {
    generated.search.mockResolvedValue(response({ items: [taskHit], next_cursor: null }))
    mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'أبجد' } })

    // Narrow both filters to specific values.
    fireEvent.click(screen.getByRole('combobox', { name: 'النوع' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مهمة' }))
    fireEvent.click(screen.getByRole('combobox', { name: 'الحالة' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مسودة' }))

    // Then switch both back to All — the "__all" sentinel must never reach the API.
    fireEvent.click(screen.getByRole('combobox', { name: 'النوع' }))
    fireEvent.click(await screen.findByRole('option', { name: 'الكل' }))
    fireEvent.click(screen.getByRole('combobox', { name: 'الحالة' }))
    fireEvent.click(await screen.findByRole('option', { name: 'الكل' }))

    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    await waitFor(() => {
      expect(generated.search).toHaveBeenCalled()
    })
    const params = generated.search.mock.calls.at(-1)![0]
    expect(params.q).toBe('أبجد')
    expect(params.type).toBeUndefined()
    expect(params.status).toBeUndefined()
  })

  it('drops stale local results on a real scope epoch change before any refetch', async () => {
    generated.search.mockResolvedValue(response({ items: [taskHit], next_cursor: 'c2' }))
    const { view, client } = mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'أبجد' } })
    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    expect(await screen.findByText('مهمة أبجد')).toBeInTheDocument()
    expect(generated.search).toHaveBeenCalledTimes(1)

    // Simulate a real scope switch: bump the epoch and re-render.
    principalState.scopeEpoch += 1
    view.rerender(tree(client))

    // The old-scope result disappears, and no new-scope fetch was triggered.
    await waitFor(() => {
      expect(screen.queryByText('مهمة أبجد')).not.toBeInTheDocument()
    })
    expect(generated.search).toHaveBeenCalledTimes(1)
  })

  it('loads more results with cursor pagination', async () => {
    generated.search
      .mockResolvedValueOnce(response({ items: [taskHit], next_cursor: 'c2' }))
      .mockResolvedValueOnce(response({ items: [documentHit], next_cursor: null }))
    mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'أبجد' } })
    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    const more = await screen.findByRole('button', { name: 'عرض المزيد' })
    fireEvent.click(more)

    await waitFor(() => {
      expect(screen.getByText('مستند أبجد')).toBeInTheDocument()
    })
    expect(generated.search).toHaveBeenLastCalledWith(
      expect.objectContaining({ q: 'أبجد', cursor: 'c2' }),
      expect.anything(),
    )
  })

  it('shows the empty state for a query without results', async () => {
    generated.search.mockResolvedValue(response({ items: [], next_cursor: null }))
    mount()

    fireEvent.change(screen.getByLabelText('نص البحث'), { target: { value: 'لا شيء' } })
    fireEvent.click(screen.getByRole('button', { name: 'بحث' }))

    expect(await screen.findByText('لا توجد نتائج')).toBeInTheDocument()
  })

  it('renders through the shared PageLayout + PageHeader shell with exactly one H1', () => {
    mount()
    const shell = document.querySelector('.mx-auto.w-full.max-w-6xl.min-w-0.space-y-6')
    expect(shell).not.toBeNull()
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('بحث')
    expect(shell!.contains(headings[0])).toBe(true)
  })
})
