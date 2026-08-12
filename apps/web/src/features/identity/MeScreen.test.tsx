// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { MeScreen } from './MeScreen'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

/*
 * Controllable principal: availableScopes A/B, effective A, scopeEpoch 0.
 * selectScope is replaced per-test; the default models the real provider: it
 * bumps the epoch and switches the effective scope. The AccessTab purges
 * scope-bound cached rows BEFORE invoking selectScope, so a test can hold the
 * selection unresolved and still prove the old-epoch rows are gone.
 */
const principalState = vi.hoisted(() => ({
  scopeEpoch: 0,
  effectiveScope: { scopeType: 'facility', scopeId: 'A', label: 'منشأة أ' },
  availableScopes: [
    { scopeType: 'facility', scopeId: 'A', label: 'منشأة أ' },
    { scopeType: 'facility', scopeId: 'B', label: 'منشأة ب' },
  ],
  selectScope: null as unknown as (scopeType: string, scopeId: string) => Promise<void>,
}))

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: ['tasks.list', 'documents.read', 'search.query'],
    features: { work_management: false, tasks: true },
    effectiveScope: principalState.effectiveScope,
    availableScopes: principalState.availableScopes,
    revision: 0,
    scopeEpoch: principalState.scopeEpoch,
    scopeReady: true,
    refresh: () => {},
    selectScope: principalState.selectScope,
  }),
}))

const defaultSelectScope: (scopeType: string, scopeId: string) => Promise<void> = async (scopeType, scopeId) => {
  principalState.scopeEpoch += 1
  principalState.effectiveScope = {
    scopeType,
    scopeId,
    label: scopeId === 'B' ? 'منشأة ب' : 'منشأة أ',
  }
}

beforeEach(() => {
  principalState.scopeEpoch = 0
  principalState.effectiveScope = { scopeType: 'facility', scopeId: 'A', label: 'منشأة أ' }
  principalState.selectScope = defaultSelectScope
})

function mount(client: QueryClient, initialEntries: string[] = ['/me']) {
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <MemoryRouter initialEntries={initialEntries}>
          <MeScreen />
          <LocationProbe />
        </MemoryRouter>
      </SessionProvider>
    </QueryClientProvider>,
  )
}

/*
 * The hook writes through `setSearchParams` which is internal to the
 * memory router; `window.location` is not affected. A child probe reads
 * the live `useLocation` and exposes the search string so the test can
 * assert the URL-backed tab contract from the visible surface.
 */
function LocationProbe() {
  const location = useLocation()
  return <span data-testid="location-search">{location.search}</span>
}

describe('MeScreen workspace', () => {
  it('renders a single H1 with the two personal tabs', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { container } = mount(client)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(screen.getByRole('tab', { name: 'أماني' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'صلاحياتي ونطاقاتي' })).toBeInTheDocument()
  })

  it('invalidates scope-bound cached rows when the effective scope changes', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    // Rows fetched under the previous scope (epoch 0) sit in the query cache
    // under scope-bound keys. If the switch left them serviceable, the next
    // render could display data the user is no longer entitled to.
    client.setQueryData(['tasks', { limit: 100 }, 0], { items: [{ id: 'old-task' }], next_cursor: null })
    client.setQueryData(['search', 'needle', 0], { items: [{ id: 'old-hit' }], next_cursor: null })

    mount(client)

    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي' }))
    fireEvent.click(await screen.findByRole('button', { name: 'منشأة ب' }))

    await waitFor(() => {
      expect(client.getQueryData(['tasks', { limit: 100 }, 0])).toBeUndefined()
      expect(client.getQueryData(['search', 'needle', 0])).toBeUndefined()
    })
  })

  it('purges old-epoch cached rows while the scope selection is still unresolved', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    client.setQueryData(['tasks', { limit: 100 }, 0], { items: [{ id: 'old-task' }], next_cursor: null })
    client.setQueryData(['search', 'needle', 0], { items: [{ id: 'old-hit' }], next_cursor: null })

    // Hold the selection request unresolved so the purge must happen before it.
    let releaseSelection!: () => void
    principalState.selectScope = () =>
      new Promise<void>((resolve) => {
        releaseSelection = resolve
      })

    mount(client)

    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي' }))
    fireEvent.click(await screen.findByRole('button', { name: 'منشأة ب' }))

    // While the selection is in flight, the old-epoch rows are already gone.
    await waitFor(() => {
      expect(client.getQueryData(['tasks', { limit: 100 }, 0])).toBeUndefined()
      expect(client.getQueryData(['search', 'needle', 0])).toBeUndefined()
    })

    // The switch indicator stays visible until the selection resolves.
    expect(screen.getByRole('status')).toHaveTextContent('جارٍ تبديل النطاق…')

    releaseSelection()
    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument()
    })
  })
})

/*
 * URL-backed tab contract for MeScreen: the active tab is part of the
 * route so a deep link, a refresh, a back/forward navigation, and a
 * legacy `/me/security` / `/me/access` redirect all open the right tab
 * without losing intent. An invalid `?tab=` value must fall back to
 * the default instead of pinning a tab that no panel exists for.
 */
describe('MeScreen URL-backed tab', () => {
  it('opens the security tab by default when the query is absent', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    mount(client, ['/me'])
    expect(
      await screen.findByRole('tab', { name: 'أماني', selected: true }),
    ).toBeInTheDocument()
  })

  it('opens the access tab when ?tab=access is present in the URL', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    mount(client, ['/me?tab=access'])
    expect(
      await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي', selected: true }),
    ).toBeInTheDocument()
  })

  it('opens the security tab when ?tab=security is present in the URL', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    mount(client, ['/me?tab=security'])
    expect(
      await screen.findByRole('tab', { name: 'أماني', selected: true }),
    ).toBeInTheDocument()
  })

  it('normalizes an invalid ?tab= value to the default security tab', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    mount(client, ['/me?tab=foo'])
    expect(
      await screen.findByRole('tab', { name: 'أماني', selected: true }),
    ).toBeInTheDocument()
  })

  it('writes ?tab=access to the URL when the access tab is activated', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    mount(client, ['/me'])
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي' }))
    fireEvent.click(screen.getByRole('tab', { name: 'صلاحياتي ونطاقاتي' }))
    await waitFor(() => {
      expect(screen.getByTestId('location-search')).toHaveTextContent(
        '?tab=access',
      )
    })
  })

  it('applies external URL changes so back/forward navigates the tab', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { unmount } = render(
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <MemoryRouter initialEntries={['/me?tab=security']}>
            <MeScreen />
          </MemoryRouter>
        </SessionProvider>
      </QueryClientProvider>,
    )
    expect(
      await screen.findByRole('tab', { name: 'أماني', selected: true }),
    ).toBeInTheDocument()
    unmount()
    render(
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <MemoryRouter initialEntries={['/me?tab=access']}>
            <MeScreen />
          </MemoryRouter>
        </SessionProvider>
      </QueryClientProvider>,
    )
    expect(
      await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي', selected: true }),
    ).toBeInTheDocument()
  })

  it('falls back to the default when the query is stripped externally', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { unmount } = render(
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <MemoryRouter initialEntries={['/me?tab=access']}>
            <MeScreen />
          </MemoryRouter>
        </SessionProvider>
      </QueryClientProvider>,
    )
    expect(
      await screen.findByRole('tab', { name: 'صلاحياتي ونطاقاتي', selected: true }),
    ).toBeInTheDocument()
    unmount()
    render(
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <MemoryRouter initialEntries={['/me']}>
            <MeScreen />
          </MemoryRouter>
        </SessionProvider>
      </QueryClientProvider>,
    )
    expect(
      await screen.findByRole('tab', { name: 'أماني', selected: true }),
    ).toBeInTheDocument()
  })
})
