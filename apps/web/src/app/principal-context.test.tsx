// @vitest-environment jsdom
import { useState } from 'react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api/http'
import { PrincipalProvider, usePrincipal } from './principal-context'
import { SessionProvider } from './session-context'

const { getCurrentPrincipalMock, customFetchMock } = vi.hoisted(() => ({
  getCurrentPrincipalMock: vi.fn(),
  customFetchMock: vi.fn(),
}))

vi.mock('../api/generated/cluster', () => ({
  getCurrentPrincipal: (...args: unknown[]) => getCurrentPrincipalMock(...args),
}))

vi.mock('../api/http', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/http')>()
  return {
    ...actual,
    customFetch: (...args: unknown[]) => customFetchMock(...args),
  }
})

const session = {
  csrfToken: 'csrf',
  userId: 'user-1',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

const scopes = {
  available_scopes: [
    { scope_type: 'facility', scope_id: 'facility-1', label: 'Facility One' },
    { scope_type: 'facility', scope_id: 'facility-2', label: 'Facility Two' },
  ],
  effective_scope: {
    scope_type: 'facility',
    scope_id: 'facility-1',
    label: 'Facility One',
  },
}

function jsonResponse(
  data: unknown,
  status = 200,
  headers: Record<string, string> = {},
) {
  return {
    data,
    status,
    headers: new Headers(headers),
  }
}

function PrincipalProbe() {
  const principal = usePrincipal()
  const [caught, setCaught] = useState('none')

  async function switchScope() {
    try {
      await principal.selectScope('facility', 'facility-2')
      setCaught('resolved')
    } catch (error) {
      setCaught(error instanceof ApiError ? `api-${error.status}` : 'generic')
    }
  }

  return (
    <div>
      <span data-testid="principal-state">{principal.state}</span>
      <span data-testid="scope-ready">{String(principal.scopeReady)}</span>
      <span data-testid="caught-error">{caught}</span>
      <button type="button" onClick={() => void switchScope()}>
        Switch scope
      </button>
    </div>
  )
}

function renderProvider() {
  return render(
    <SessionProvider session={session} locale="en" setLocale={() => {}}>
      <PrincipalProvider>
        <PrincipalProbe />
      </PrincipalProvider>
    </SessionProvider>,
  )
}

beforeEach(() => {
  getCurrentPrincipalMock.mockReset()
  customFetchMock.mockReset()
  getCurrentPrincipalMock.mockResolvedValue(
    jsonResponse({
      capabilities: ['documents.manage'],
      features: { tasks: true },
    }),
  )
})

describe('PrincipalProvider scope selection', () => {
  it('rejects generic scope failures to callers and restores scope readiness without marking stale', async () => {
    const failure = new Error('network unavailable')
    customFetchMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/me/scopes')
        return jsonResponse(scopes, 200, { ETag: '"1"' })
      if (url === '/api/v1/me/scope') throw failure
      throw new Error(`Unexpected URL: ${url}`)
    })
    renderProvider()

    await waitFor(() =>
      expect(screen.getByTestId('principal-state')).toHaveTextContent('ready'),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))

    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('generic'),
    )
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('true')
    expect(screen.getByTestId('principal-state')).toHaveTextContent('ready')
    expect(getCurrentPrincipalMock).toHaveBeenCalledTimes(1)
  })

  it('reloads after a 412, ends stale and scope-ready, and still rejects to the caller', async () => {
    let scopeSelectionAttempts = 0
    let scopeLoadCount = 0
    customFetchMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/me/scopes') {
        scopeLoadCount += 1
        // First load shows version 1; the post-412 reload shows the winning
        // version 2 so the next selection would use the right If-Match.
        const etag = scopeLoadCount === 1 ? '"1"' : '"2"'
        return jsonResponse(scopes, 200, { ETag: etag })
      }
      if (url === '/api/v1/me/scope') {
        scopeSelectionAttempts += 1
        return jsonResponse(
          { type: 'about:blank', title: 'Precondition failed', status: 412 },
          412,
        )
      }
      throw new Error(`Unexpected URL: ${url}`)
    })
    renderProvider()

    await waitFor(() =>
      expect(screen.getByTestId('principal-state')).toHaveTextContent('ready'),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))

    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('api-412'),
    )
    expect(screen.getByTestId('principal-state')).toHaveTextContent('stale')
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('true')
    expect(scopeSelectionAttempts).toBe(1)
    expect(getCurrentPrincipalMock).toHaveBeenCalledTimes(2)
  })

  it('captures and replays the strong ETag across two sequential successful selections', async () => {
    // Initial load → 1; select → 2; reload after select → 2;
    // select → 3; reload after select → 3.
    const etagQueue: string[] = ['"1"', '"2"', '"2"', '"3"', '"3"']
    const ifMatchHistory: string[] = []
    let scopeLoadCount = 0

    customFetchMock.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (url === '/api/v1/me/scopes') {
          scopeLoadCount += 1
          const etag = etagQueue.shift() ?? '"99"'
          return jsonResponse(scopes, 200, { ETag: etag })
        }
        if (url === '/api/v1/me/scope') {
          const headers = (options?.headers ?? {}) as Record<string, string>
          ifMatchHistory.push(headers['If-Match'])
          const etag = etagQueue.shift() ?? '"99"'
          return jsonResponse(
            { scope_type: 'facility', scope_id: 'facility-2' },
            200,
            { ETag: etag },
          )
        }
        throw new Error(`Unexpected URL: ${url}`)
      },
    )

    renderProvider()
    await waitFor(() =>
      expect(screen.getByTestId('principal-state')).toHaveTextContent('ready'),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))
    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('resolved'),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))
    await waitFor(() => expect(ifMatchHistory.length).toBe(2))

    expect(ifMatchHistory).toEqual(['"1"', '"2"'])
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('true')
    expect(screen.getByTestId('principal-state')).toHaveTextContent('ready')
    // One initial load + two reloads after each successful selection.
    expect(scopeLoadCount).toBe(3)
  })

  it('fails safely with a provider error when the scope ETag is missing from the load', async () => {
    let selectInvocations = 0
    customFetchMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/me/scopes') {
        return jsonResponse(scopes) // No ETag header.
      }
      if (url === '/api/v1/me/scope') {
        selectInvocations += 1
        return jsonResponse({ scope_type: 'facility', scope_id: 'facility-2' })
      }
      throw new Error(`Unexpected URL: ${url}`)
    })

    renderProvider()
    await waitFor(() =>
      expect(screen.getByTestId('principal-state')).toHaveTextContent('ready'),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))

    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('generic'),
    )
    expect(selectInvocations).toBe(0)
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('true')
    expect(screen.getByTestId('principal-state')).toHaveTextContent('ready')
  })

  it('rejects a second selectScope while a first one is still in flight', async () => {
    let selectAttempts = 0
    let resolveFirstSelect: (() => void) | undefined
    const firstSelectGate = new Promise<void>((resolve) => {
      resolveFirstSelect = resolve
    })

    customFetchMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/me/scopes') {
        return jsonResponse(scopes, 200, { ETag: '"1"' })
      }
      if (url === '/api/v1/me/scope') {
        selectAttempts += 1
        return firstSelectGate.then(() =>
          jsonResponse(
            { scope_type: 'facility', scope_id: 'facility-2' },
            200,
            { ETag: '"2"' },
          ),
        )
      }
      throw new Error(`Unexpected URL: ${url}`)
    })

    renderProvider()
    await waitFor(() =>
      expect(screen.getByTestId('principal-state')).toHaveTextContent('ready'),
    )

    // Fire two clicks back-to-back. The first call reaches the server and
    // parks; the second call must be rejected by the provider mutex without
    // reaching the server.
    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))
    fireEvent.click(screen.getByRole('button', { name: 'Switch scope' }))

    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('generic'),
    )

    // The first click is still in flight; scope-readiness must reflect that.
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('false')
    expect(selectAttempts).toBe(1)

    // Let the first selection finish so the test cleans up deterministically.
    resolveFirstSelect?.()
    await waitFor(() =>
      expect(screen.getByTestId('caught-error')).toHaveTextContent('resolved'),
    )
    expect(screen.getByTestId('scope-ready')).toHaveTextContent('true')
    expect(selectAttempts).toBe(1)
  })
})