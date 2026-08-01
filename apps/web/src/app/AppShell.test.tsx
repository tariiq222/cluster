// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { AppShell } from './AppShell'
import { PrincipalContextTestProvider } from './principal-context'
import { SessionProvider } from './session-context'
import { ThemeProvider } from '@/components/theme-provider'

const session = { csrfToken: 'test-csrf', userId: 'test-user', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function shell(capabilities: string[], features: { work_management: boolean; tasks: boolean }) {
  return render(
    <MemoryRouter>
      <ThemeProvider>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <PrincipalContextTestProvider capabilities={capabilities} features={features}>
            <AppShell onLogout={() => {}} />
          </PrincipalContextTestProvider>
        </SessionProvider>
      </ThemeProvider>
    </MemoryRouter>,
  )
}

describe('app shell navigation', () => {
  it('omits work-management destinations entirely when the flag is off', () => {
    const { container } = shell(
      ['work_management.record.read', 'workflow.step.read'],
      { work_management: false, tasks: true },
    )
    // Absent, not disabled, and with no explanatory text — the API answers 404
    // without disclosing existence, and the UI must not undo that.
    expect(container.textContent).not.toContain('سجلات العمل')
    expect(container.textContent).not.toContain('صندوق الموافقات')
    expect(container.textContent).not.toContain('غير متاح')
    expect(container.querySelector('[aria-disabled="true"]')).toBeNull()
  })

  it('shows work-management destinations when the flag is on and capability is held', () => {
    const { container } = shell(
      ['work_management.record.read', 'workflow.step.read'],
      { work_management: true, tasks: true },
    )
    expect(container.textContent).toContain('سجلات العمل')
  })

  it('omits a destination when the capability is missing even with the flag on', () => {
    const { container } = shell([], { work_management: true, tasks: true })
    expect(container.textContent).not.toContain('سجلات العمل')
  })
})
