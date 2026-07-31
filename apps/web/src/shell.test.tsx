// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render, renderHook } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider, useLocale, useSessionToken } from './app/session-context'
import type { Session } from './api/session'

function makeSession(): Session {
  return { csrfToken: 'test-csrf', userId: '0197f0e0-0000-7000-8000-000000000021', expiresAt: '2026-12-31T00:00:00Z', restricted: false }
}

function wrap(ui: React.ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return (
    <QueryClientProvider client={queryClient}>
      <SessionProvider session={makeSession()} locale="ar" setLocale={() => {}}>
        {ui}
      </SessionProvider>
    </QueryClientProvider>
  )
}

function hookWrapper({ children }: { children: React.ReactNode }) {
  return wrap(children)
}

describe('session context', () => {
  it('exposes locale and session token', () => {
    const { result } = renderHook(() => ({ locale: useLocale(), token: useSessionToken() }), { wrapper: hookWrapper })
    expect(result.current.locale).toBe('ar')
    expect(result.current.token).toBe('test-csrf')
  })
})

describe('router integration', () => {
  it('renders nested routes under a provider shell', () => {
    function Probe() {
      const location = useLocation()
      return <div data-testid="path">{location.pathname}</div>
    }
    const { getByTestId } = render(
      wrap(
        <MemoryRouter initialEntries={['/tasks']}>
          <Probe />
        </MemoryRouter>,
      ),
    )
    expect(getByTestId('path').textContent).toBe('/tasks')
  })
})
