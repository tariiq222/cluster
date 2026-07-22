// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import App from './App'

const api = vi.hoisted(() => ({
  restoreSession: vi.fn(),
  identityLogout: vi.fn(),
  clearSessionMetadata: vi.fn(),
  registerSessionExpiredHandler: vi.fn(),
}))

vi.mock('./api', () => ({
  restoreSession: api.restoreSession,
  identityLogout: api.identityLogout,
  clearSessionMetadata: api.clearSessionMetadata,
  registerSessionExpiredHandler: api.registerSessionExpiredHandler,
  SESSION_METADATA_KEY: 'cluster.identity-session',
}))

vi.mock('./app/LoginScreen', () => ({
  LoginScreen: ({ sessionExpired, onAuthenticated }: {
    sessionExpired: boolean
    onAuthenticated: (session: {
      csrf_token: string
      user_id: string
      expires_at: string
      restricted: boolean
      principal: { user_id: string }
      access_token: string
    }) => void
  }) => (
    <div data-testid="login-screen" data-session-expired={String(sessionExpired)}>
      <button
        type="button"
        onClick={() => onAuthenticated({
          csrf_token: 'fresh-csrf',
          user_id: '018f6f7d-0c00-7000-8000-000000000001',
          expires_at: '2026-01-01T00:00:00.000Z',
          restricted: false,
          principal: { user_id: '018f6f7d-0c00-7000-8000-000000000001' },
          access_token: 'fresh-csrf',
        })}
      >
        authenticate
      </button>
    </div>
  ),
}))

vi.mock('./app/AppWorkspace', () => ({
  AppWorkspace: ({ onLogout, session }: {
    onLogout?: () => void | Promise<void>
    session: { csrf_token: string }
  }) => (
    <div data-testid="workspace" data-csrf-token={session.csrf_token}>
      <button type="button" onClick={() => void onLogout?.()}>logout</button>
    </div>
  ),
}))

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.sessionStorage.clear()
})

beforeEach(() => {
  api.restoreSession.mockReset()
  api.identityLogout.mockReset()
  api.clearSessionMetadata.mockReset()
})

describe('App session gate', () => {
  it('passes session expiration to the login screen when stored metadata cannot be restored', async () => {
    window.sessionStorage.setItem('cluster.identity-session', JSON.stringify({ csrf_token: 'stale' }))
    api.restoreSession.mockResolvedValue(null)

    render(<App />)

    const loginScreen = await screen.findByTestId('login-screen')
    expect(loginScreen.getAttribute('data-session-expired')).toBe('true')
  })

  it('logs out through the identity endpoint and returns to the login screen', async () => {
    api.restoreSession.mockResolvedValue({
      csrf_token: 'fresh-csrf',
      user_id: '018f6f7d-0c00-7000-8000-000000000001',
      expires_at: '2026-01-01T00:00:00.000Z',
      restricted: false,
      principal: { user_id: '018f6f7d-0c00-7000-8000-000000000001' },
      access_token: 'fresh-csrf',
    })
    api.identityLogout.mockResolvedValue(undefined)

    render(<App />)

    await screen.findByTestId('workspace')
    fireEvent.click(screen.getByRole('button', { name: 'logout' }))

    await waitFor(() => {
      expect(api.identityLogout).toHaveBeenCalledWith('fresh-csrf')
      expect(api.clearSessionMetadata).toHaveBeenCalledTimes(1)
    })
    expect(screen.queryByTestId('workspace')).toBeNull()
    expect(await screen.findByTestId('login-screen')).toBeTruthy()
  })
})
