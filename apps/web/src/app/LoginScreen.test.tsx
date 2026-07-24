// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

afterEach(() => {
  cleanup()
  vi.unstubAllEnvs()
  vi.resetModules()
})

async function loadLoginScreen() {
  return await import('./LoginScreen')
}

describe('LoginScreen development credential gating', () => {
  it('hides development credentials when DEV is false', async () => {
    vi.stubEnv('DEV', false)
    const { LoginScreen } = await loadLoginScreen()

    render(
      <LoginScreen
        locale="en"
        sessionExpired={false}
        onLocaleChange={() => {}}
        onAuthenticated={() => {}}
      />,
    )

    expect(screen.queryByRole('button', { name: /w13-e2e-account-a/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /w13-e2e-account-b/i })).toBeNull()
    expect(screen.queryByText(/North!River7Quartz2026/i)).toBeNull()
    expect(screen.queryByText(/Cedar!Orbit8Harbor2026/i)).toBeNull()
    expect(screen.queryByRole('button', { name: /platform-admin/i })).toBeNull()
    expect(screen.queryByText(/Admin!Cluster9Owner2026/i)).toBeNull()
  })

  it('reveals development credentials only when DEV is true', async () => {
    vi.stubEnv('DEV', true)
    const { LoginScreen } = await loadLoginScreen()

    render(
      <LoginScreen
        locale="en"
        sessionExpired={false}
        onLocaleChange={() => {}}
        onAuthenticated={() => {}}
      />,
    )

    expect(screen.getByRole('button', { name: /w13-e2e-account-a \/ North!River7Quartz2026/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /w13-e2e-account-b \/ Cedar!Orbit8Harbor2026/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /platform-admin \/ Admin!Cluster9Owner2026/ })).toBeTruthy()
  })
})
