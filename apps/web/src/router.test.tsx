// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { AppRouter, RouteFallback, routePaths } from './router'
import { PrincipalContextTestProvider } from './app/principal-context'
import { SessionProvider } from './app/session-context'

const session = {
  csrfToken: 'test-csrf',
  userId: 'test-user',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

function unresolvedRouter(
  state: 'loading' | 'error',
  errorCorrelationId: string | null = null,
  refresh = () => {},
) {
  return render(
    <SessionProvider session={session} locale="ar" setLocale={() => {}}>
      <PrincipalContextTestProvider
        capabilities={[]}
        features={null}
        state={state}
        errorCorrelationId={errorCorrelationId}
        refresh={refresh}
      >
        <AppRouter onLogout={() => {}} />
      </PrincipalContextTestProvider>
    </SessionProvider>,
  )
}

describe('route tree', () => {
  it('registers the consolidated workspaces', () => {
    for (const path of [
      '/',
      '/tasks',
      '/documents',
      '/organization',
      '/organization/import',
      '/access',
      '/reports',
      '/platform',
      '/me',
      '/search',
      '/notifications',
    ]) {
      expect(routePaths()).toContain(path)
    }
  })

  it('registers the detail, create, and import routes', () => {
    for (const path of [
      '/tasks/new',
      '/tasks/:taskId',
      '/documents/new',
      '/documents/:documentId',
      '/documents/:documentId/versions/new',
      '/organization/import/:jobId',
      '/organization/cluster/new',
      '/organization/cluster/edit',
      '/organization/facilities/new',
      '/organization/facilities/:facilityId/edit',
      '/organization/units/new',
      '/organization/positions/new',
      '/organization/job-titles/new',
      '/organization/people/new',
      '/organization/people/:personId/edit',
      '/organization/assignments/new',
      '/access/accounts/new',
      '/access/accounts/:accountId',
      '/access/roles/new',
      '/access/roles/:roleId/edit',
      '/access/role-assignments/new',
      '/reports/audit/events/:eventId',
      '/platform/settings/:versionId/security/:settingKey/edit',
      '/platform/calendars/new',
      '/platform/calendars/:calendarId/weekdays/:weekday/edit',
      '/platform/calendars/:calendarId/exceptions/new',
      '/platform/maintenance/new',
      '/platform/logs/restore',
      '/platform/alerts/:policyId/edit',
    ]) {
      expect(routePaths()).toContain(path)
    }
  })

  it('does not register planned workspaces whose API is unimplemented', () => {
    for (const path of [
      '/governance',
      '/risk',
      '/strategy',
      '/portfolio',
      '/workflow/operations-office',
    ]) {
      expect(routePaths()).not.toContain(path)
    }
  })

  it('does not register the retired per-resource organization routes', () => {
    for (const path of [
      '/accounts-permissions',
      '/reports-monitoring',
      '/platform-management',
      '/audit',
      '/dashboards',
      '/imports',
      '/me/security',
      '/me/access',
    ]) {
      expect(routePaths()).not.toContain(path)
    }
  })

  it('renders an accessible shell-shaped state while the principal loads', () => {
    unresolvedRouter('loading')
    expect(screen.getByRole('status')).toHaveTextContent('جارٍ التحميل…')
    expect(screen.getByTestId('principal-loading')).toBeTruthy()
  })

  it('renders a localized recoverable principal error with correlation id', () => {
    const refresh = vi.fn()
    unresolvedRouter('error', '01980f50-5f0d-7000-8000-000000000099', refresh)
    expect(screen.getByRole('alert')).toHaveTextContent(
      'حدث خطأ أثناء تحميل البيانات.',
    )
    expect(
      screen.getByText('01980f50-5f0d-7000-8000-000000000099'),
    ).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'أعد المحاولة' }))
    expect(refresh).toHaveBeenCalledOnce()
  })

  it('renders an accessible shared route-loading fallback with the localized label', () => {
    render(
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <RouteFallback />
      </SessionProvider>,
    )
    const status = screen.getByRole('status')
    expect(status).toHaveAttribute('aria-live', 'polite')
    expect(status).toHaveTextContent('جارٍ التحميل…')
    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
  })
})
