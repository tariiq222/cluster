// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { RouterProvider } from 'react-router-dom'
import { ThemeProvider } from '@/components/theme-provider'
import {
  AppRouter,
  CalendarWeekdayEditRoute,
  ParamRecoverySurface,
  RouteFallback,
  createTestRouter,
  routePaths,
} from './router'
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
    <ThemeProvider>
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
      </SessionProvider>
    </ThemeProvider>,
  )
}

function mountAt(path: string, additionalRoutes?: Parameters<typeof createTestRouter>[0]['additionalRoutes']) {
  const router = createTestRouter({
    features: { tasks: false },
    onLogout: () => {},
    initialEntries: [path],
    additionalRoutes,
  })
  const result = render(
    <ThemeProvider>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider
          capabilities={[]}
          features={{ tasks: false }}
          state="ready"
        >
          <RouterProvider router={router} />
        </PrincipalContextTestProvider>
      </SessionProvider>
    </ThemeProvider>,
  )
  return { ...result, router }
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
      <ThemeProvider>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <RouteFallback />
        </SessionProvider>
      </ThemeProvider>,
    )
    const status = screen.getByRole('status')
    expect(status).toHaveAttribute('aria-live', 'polite')
    expect(status).toHaveTextContent('جارٍ التحميل…')
    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
  })
})

/*
 * The route-level error boundary: a thrown render or a failed lazy import
 * must surface the localized "something went wrong" copy with retry + home
 * affordances, and the technical error must never leak into the visible
 * surface.
 */
describe('route error boundary', () => {
  it('catches a thrown render and shows the localized recovery surface with retry and home', async () => {
    const ThrowingRoute = () => {
      throw new Error('boom-from-route')
    }
    // Suppress the console.error noise the boundary logs in the test
    // output; the assertion below proves nothing technical leaks to the
    // visible surface, which is the actual contract.
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    try {
      mountAt('/__test/throw', [
        { path: '/__test/throw', element: <ThrowingRoute /> },
      ])
      await waitFor(() => {
        expect(screen.getByText('حدث خطأ غير متوقع.')).toBeInTheDocument()
      })
      // shellCopy.retry is "إعادة المحاولة" in Arabic.
      expect(screen.getByRole('button', { name: 'إعادة المحاولة' })).toBeInTheDocument()
      const home = screen.getByRole('link', { name: 'الرئيسية' })
      expect(home).toHaveAttribute('href', '/')
      // The technical message must never appear in the rendered output.
      expect(document.body.textContent).not.toContain('boom-from-route')
    } finally {
      consoleError.mockRestore()
    }
  })

  it('hides 403/404 resource-exposing strings on a thrown render and only renders the generic copy', async () => {
    const ThrowingForbidden = () => {
      throw new Error('forbidden by server')
    }
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    try {
      mountAt('/__test/forbidden', [
        { path: '/__test/forbidden', element: <ThrowingForbidden /> },
      ])
      await waitFor(() => {
        expect(screen.getByText('حدث خطأ غير متوقع.')).toBeInTheDocument()
      })
      // The shared non-disclosure denied copy is intentionally not used
      // for render errors; the generic error copy preserves the rule
      // that an error page must not claim a resource is forbidden.
      expect(screen.queryByText('لا يمكن الوصول إلى هذا المحتوى.')).not.toBeInTheDocument()
      expect(document.body.textContent).not.toContain('forbidden by server')
    } finally {
      consoleError.mockRestore()
    }
  })
})

/*
 * Param adapters must never render `null` on a missing or unparseable
 * param — a blank page is a worse failure than a localized not-found.
 */
describe('param adapters', () => {
  it('CalendarWeekdayEditRoute renders the localized recovery surface for a non-integer weekday', async () => {
    mountAt('/platform/calendars/cal-1/weekdays/abc/edit', [
      {
        path: '/platform/calendars/:calendarId/weekdays/:weekday/edit',
        element: <CalendarWeekdayEditRoute />,
      },
    ])
    await waitFor(() => {
      expect(screen.getByText('الصفحة غير موجودة.')).toBeInTheDocument()
    })
    // The recovery surface never renders a blank pane.
    expect(document.body.textContent).toBeTruthy()
  })

  it('CalendarWeekdayEditRoute renders the localized recovery surface when both params are missing', async () => {
    mountAt('/platform/calendars//weekdays//edit', [
      {
        path: '/platform/calendars/:calendarId/weekdays/:weekday/edit',
        element: <CalendarWeekdayEditRoute />,
      },
    ])
    await waitFor(() => {
      expect(screen.getByText('الصفحة غير موجودة.')).toBeInTheDocument()
    })
  })

  it('ParamRecoverySurface renders the localized not-found copy in both locales', () => {
    const ar = render(
      <ThemeProvider>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <ParamRecoverySurface />
        </SessionProvider>
      </ThemeProvider>,
    )
    expect(ar.getByText('الصفحة غير موجودة.')).toBeInTheDocument()
    ar.unmount()
    const en = render(
      <ThemeProvider>
        <SessionProvider session={session} locale="en" setLocale={() => {}}>
          <ParamRecoverySurface />
        </SessionProvider>
      </ThemeProvider>,
    )
    expect(en.getByText('Page not found.')).toBeInTheDocument()
  })
})

/*
 * The retired `/imports/:jobId` redirect must interpolate the actual
 * job id, not the literal template token. A `Navigate to=".../import/:jobId"`
 * would land on a literal `:jobId` segment and 404; the contract is
 * that the rendered URL matches `/organization/import/<id>`.
 */
describe('dynamic /imports/:jobId redirect', () => {
  it('interpolates the actual id so a deep link lands on the review screen', async () => {
    const { router } = mountAt('/imports/01980f50-5f0d-7000-8000-000000000123')
    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/organization/import/01980f50-5f0d-7000-8000-000000000123',
      )
    })
  })

  it('never lands on a literal :jobId segment in the destination URL', async () => {
    // Visit a route that should redirect to /organization/import/<id>;
    // the resolved path must contain an actual id, not the literal
    // template token that a static `Navigate to=".../import/:jobId"`
    // would have produced.
    const { router } = mountAt('/imports/job-xyz-9')
    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/organization/import/job-xyz-9',
      )
      expect(router.state.location.pathname).not.toContain(':jobId')
    })
  })
})

/*
 * `/me/security` and `/me/access` must carry the requested tab through
 * to the URL-backed MeScreen so deep links, refresh, and back/forward
 * all open the right tab.
 */
describe('/me/security and /me/access tab preservation', () => {
  it('redirects /me/security to /me?tab=security', async () => {
    const { router } = mountAt('/me/security')
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/me')
      expect(router.state.location.search).toBe('?tab=security')
    })
  })

  it('redirects /me/access to /me?tab=access', async () => {
    const { router } = mountAt('/me/access')
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/me')
      expect(router.state.location.search).toBe('?tab=access')
    })
  })
})
