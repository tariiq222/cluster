// @vitest-environment jsdom
import { StrictMode, type ReactNode } from 'react'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useQuery, useQueryClient } from '@tanstack/react-query'

const {
  loginMock,
  restoreSessionMock,
  identityLogoutMock,
  storedSessionMock,
  clearStoredSessionMock,
  registerExpiredMock,
} = vi.hoisted(() => ({
  loginMock: vi.fn(),
  restoreSessionMock: vi.fn(),
  identityLogoutMock: vi.fn(),
  storedSessionMock: vi.fn(),
  clearStoredSessionMock: vi.fn(),
  registerExpiredMock: vi.fn(),
}))

vi.mock('../api/session', () => ({
  login: loginMock,
  restoreSession: restoreSessionMock,
  identityLogout: identityLogoutMock,
  storedSession: storedSessionMock,
  clearStoredSession: clearStoredSessionMock,
}))
vi.mock('../api/http', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/http')>()
  return { ...actual, registerSessionExpiredHandler: registerExpiredMock }
})
vi.mock('./principal-context', () => ({
  PrincipalProvider: ({ children }: { children: ReactNode }) => children,
}))
vi.mock('./LoginScreen', () => ({
  LoginScreen: ({
    onLogin,
  }: {
    onLogin: (username: string, password: string) => Promise<void>
  }) => {
    const client = useQueryClient()
    return (
      <div>
        <button
          type="button"
          onClick={() => void onLogin('user-b', 'password')}
        >
          Login
        </button>
        <span data-testid="cache-size">
          {client.getQueryCache().getAll().length}
        </span>
      </div>
    )
  },
}))
vi.mock('../router', () => ({
  AppRouter: ({ onLogout }: { onLogout: () => void }) => {
    const client = useQueryClient()
    const lateQuery = useQuery({
      queryKey: ['identity-bound-data'],
      queryFn: () => lateQueries.shift() ?? Promise.resolve('unexpected query'),
      retry: false,
    })
    return (
      <div>
        <button type="button" onClick={() => void onLogout()}>
          Logout
        </button>
        <span data-testid="cache-size">
          {client.getQueryCache().getAll().length}
        </span>
        <span data-testid="query-state">{lateQuery.status}</span>
      </div>
    )
  },
}))

import { App } from './App'

const SESSION_A = {
  csrfToken: 'csrf-a',
  userId: '0197f0e0-0000-7000-8000-000000000021',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}
const SESSION_B = {
  csrfToken: 'csrf-b',
  userId: '0197f0e0-0000-7000-8000-000000000022',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}
let currentSession: typeof SESSION_A | null
let resolveLateQuery: ((value: string) => void) | undefined
let lateQueries: Promise<string>[]
let expiredHandler: (() => void) | undefined

beforeEach(() => {
  currentSession = SESSION_A
  resolveLateQuery = undefined
  lateQueries = [
    new Promise((resolve) => {
      resolveLateQuery = resolve
    }),
    new Promise(() => {}),
  ]
  storedSessionMock.mockImplementation(() => currentSession)
  clearStoredSessionMock.mockImplementation(() => {
    currentSession = null
  })
  identityLogoutMock.mockResolvedValue(undefined)
  loginMock.mockImplementation(async () => {
    currentSession = SESSION_B
    return SESSION_B
  })
  restoreSessionMock.mockReset()
  registerExpiredMock.mockReset()
  registerExpiredMock.mockImplementation((handler: () => void) => {
    expiredHandler = handler
  })
})

describe('App identity cache isolation', () => {
  it('cancels and clears prior queries before logout, and ignores their late response after login', async () => {
    render(
      <StrictMode>
        <App />
      </StrictMode>,
    )
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Logout' })).toBeTruthy(),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Logout' }))
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Login' })).toBeTruthy(),
    )
    expect(screen.getByTestId('cache-size')).toHaveTextContent('0')
    resolveLateQuery?.('old identity data')
    await Promise.resolve()
    expect(screen.getByTestId('cache-size')).toHaveTextContent('0')
    fireEvent.click(screen.getByRole('button', { name: 'Login' }))
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Logout' })).toBeTruthy(),
    )
    expect(screen.getByTestId('cache-size')).toHaveTextContent('1')
    expect(screen.getByTestId('query-state')).toHaveTextContent('pending')
  })

  it('clears the query cache before expiry exposes the login surface', async () => {
    render(<App />)
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Logout' })).toBeTruthy(),
    )
    await act(async () => {
      expiredHandler?.()
    })
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Login' })).toBeTruthy(),
    )
    expect(screen.getByTestId('cache-size')).toHaveTextContent('0')
  })
})
