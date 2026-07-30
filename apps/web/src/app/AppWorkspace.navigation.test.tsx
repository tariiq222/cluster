// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { RouteAccessGuard } from './AppWorkspace'

import {
  PRIMARY_NAVIGATION_ENTRIES,
  buildPrimaryNavigationItems,
  isNavigationEntryVisible,
} from '../shell/navigation'
import {
  capabilityForRoute,
  isRouteVisible,
  primaryRoutes,
} from '../shell/routes'

/**
 * Cross-checks the navigation registry against the route registry so both stay
 * in sync. Anything visible per the navigation registry must also pass
 * `isRouteVisible` for the same capability set, and vice-versa.
 */
function pathsFor(capabilities: readonly string[] | null, features: { work_management: boolean; tasks: boolean } | null = null): string[] {
  return primaryRoutes
    .filter(({ route }) => isRouteVisible(route, capabilities, features))
    .map(({ path }) => path)
}

function navigationPathsFor(capabilities: readonly string[] | null, features: { work_management: boolean; tasks: boolean } | null = null): string[] {
  return buildPrimaryNavigationItems({ locale: 'ar', capabilities, features }).map((item) => item.path)
}

function itemKeys(capabilities: readonly string[] | null, features: { work_management: boolean; tasks: boolean } | null = null): string[] {
  return buildPrimaryNavigationItems({ locale: 'ar', capabilities, features }).map((item) => item.key)
}

const ALL_FEATURES = { work_management: true, tasks: true } as const
const DISABLED = { work_management: false, tasks: true } as const

describe('sidebar navigation by capability', () => {
  it('blocks direct protected-route content with the unified denied state', () => {
    const { rerender } = render(
      <RouteAccessGuard locale="en" route={{ name: 'api-docs' }} capabilities={null} features={null}>
        <span>API contract content</span>
      </RouteAccessGuard>,
    )

    expect(screen.getByText('You do not have permission to open this page')).toBeTruthy()
    expect(screen.queryByText('API contract content')).toBeNull()

    rerender(
      <RouteAccessGuard locale="en" route={{ name: 'api-docs' }} capabilities={['authorization.audit.read']} features={null}>
        <span>API contract content</span>
      </RouteAccessGuard>,
    )
    expect(screen.getByText('API contract content')).toBeTruthy()
  })

  it('withholds direct protected URLs when their capability is absent or unresolved', () => {
    const protectedRoutes = [
      { name: 'audit' } as const,
      { name: 'api-docs' } as const,
      { name: 'dashboards' } as const,
    ]
    for (const route of protectedRoutes) {
      expect(isRouteVisible(route, null)).toBe(false)
      expect(isRouteVisible(route, [])).toBe(false)
    }
  })

  it('keeps the decision-inspector deep link reachable so the workspace can render its localized unavailable copy', () => {
    const explanation = { name: 'access-explanation' } as const
    expect(isRouteVisible(explanation, null)).toBe(true)
    expect(isRouteVisible(explanation, [])).toBe(true)
    expect(isRouteVisible(explanation, ['authorization.role.read'])).toBe(true)
  })

  it('offers an employee their own work and nothing administrative', () => {
    const employee = ['work_record.create', 'work_record.read', 'tasks.read', 'documents.read']

    expect(pathsFor(employee)).toContain('/')
    expect(pathsFor(employee)).toContain('/tasks')
    expect(pathsFor(employee)).toContain('/documents')
    expect(pathsFor(employee)).not.toContain('/admin/organization')
    expect(pathsFor(employee)).not.toContain('/admin/identity/accounts')
    expect(pathsFor(employee)).not.toContain('/reports')
  })

  it('drops administrative destinations once their gating capabilities are withheld', () => {
    const employee = ['tasks.read']

    expect(itemKeys(employee, DISABLED)).toEqual(['home', 'tasks'])
    expect(pathsFor(employee)).toContain('/tasks')
    expect(pathsFor(employee)).not.toContain('/admin/organization')
  })

  it('offers only the matching work-domain group to a principal holding one of its capabilities', () => {
    const officer = ['tasks.read', 'reporting.list']

    expect(itemKeys(officer, DISABLED)).toContain('reports-monitoring')
    expect(itemKeys(officer, DISABLED)).not.toContain('organization')
    expect(pathsFor(officer)).toContain('/reports')
    expect(pathsFor(officer)).not.toContain('/admin/organization')
  })

  it('offers the full surface to a principal holding every gating capability', () => {
    const admin = [
      'tasks.read',
      'documents.read',
      'work_definition.read',
      'organization.unit.read',
      'identity.account.read',
      'reporting.list',
    ]

    expect(pathsFor(admin, ALL_FEATURES)).toEqual(
      expect.arrayContaining([
        '/',
        '/tasks',
        '/admin/organization',
        '/admin/identity/accounts',
        '/reports',
        '/documents',
      ]),
    )
  })

  it('withholds gated entries while the principal context is still loading', () => {
    expect(pathsFor(null)).not.toContain('/admin/identity/accounts')
    expect(pathsFor(null)).not.toContain('/reports')
    expect(pathsFor(null)).toContain('/')
  })

  it('classifies the procedure routes with their navigation-target capabilities', () => {
    expect(capabilityForRoute({ name: 'procedure-authoring' })).toBe('workflow.author')
    expect(capabilityForRoute({ name: 'procedure-office-review' })).toBe('workflow.approve')
    expect(capabilityForRoute({ name: 'procedure-guide' })).toBe('work_definition.read')

    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.author'], ALL_FEATURES)).toBe(true)
    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.approve'], ALL_FEATURES)).toBe(false)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.approve'], ALL_FEATURES)).toBe(true)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.author'], ALL_FEATURES)).toBe(false)

    expect(isRouteVisible({ name: 'procedure-guide' }, null)).toBe(false)
    expect(isRouteVisible({ name: 'procedure-guide' }, [])).toBe(false)
    expect(isRouteVisible({ name: 'procedure-guide' }, ['work_definition.read'], ALL_FEATURES)).toBe(true)
  })

  it('keeps the navigation registry and the route registry in sync', () => {
    const allCaps = [
      'reporting.list',
      'reporting.dashboard',
      'workflow.decide',
      'workflow.read',
      'workflow.list',
      'workflow.author',
      'workflow.approve',
      'work_definition.read',
      'work_definition.list',
      'tasks.read',
      'documents.read',
      'organization.unit.read',
      'organization.facility.read',
      'organization.person.read',
      'identity.account.read',
      'authorization.role.read',
      'authorization.assignment.read',
      'authorization.policy.read',
      'authorization.audit.read',
      'platform_settings.read',
    ]
    // Every entry visible per the registry must also pass isRouteVisible.
    for (const entry of PRIMARY_NAVIGATION_ENTRIES) {
      if (!isNavigationEntryVisible(entry, allCaps, ALL_FEATURES)) continue
      expect(isRouteVisible(entry.route, allCaps, ALL_FEATURES)).toBe(true)
    }
    // And the navigation registry must surface the seven primary destinations
    // the route registry exposes for administrative principals.
    const sidebarExcludedPrefixes = ['/me/', '/work-records/new', '/search', '/notifications', '/admin/procedures/authoring', '/procedures/new', '/admin/organization/structure', '/admin/organization/people', '/admin/imports/organization', '/admin/authorization/capabilities', '/admin/authorization/roles', '/admin/authorization/role-assignments', '/admin/authorization/classification-policies', '/admin/authorization/field-access-templates', '/admin/authorization/access-scopes', '/admin/authorization/explain', '/admin/relationships/supervisory', '/approvals', '/my-requests', '/admin/procedures/review', '/admin/work-definitions', '/admin/workflow', '/procedures', '/dashboards', '/api-docs', '/admin/identity/accounts']
    const routePaths = pathsFor(allCaps, ALL_FEATURES).filter((path) => !sidebarExcludedPrefixes.some((prefix) => path.startsWith(prefix)))
    const navPaths = navigationPathsFor(allCaps, ALL_FEATURES)
    for (const path of routePaths) {
      if (path === '/') continue
      expect(navPaths).toContain(path)
    }
  })
})

// The navigate closure inside AppWorkspaceShell compares the FULL current
// URL (pathname + search + hash) before pushState, then passes only the
// pathname to routeFromPath. The regression suite below renders the shell
// and drives the user menu — which uses the same `navigate` closure — to
// prove that (1) a navigation onto a different URL always updates the
// address bar, and (2) a navigation onto the exact same URL does not.
vi.mock('../api/generated/cluster', async () => {
  const actual = await vi.importActual<typeof import('../api/generated/cluster')>('../api/generated/cluster')
  return {
    ...actual,
    getCurrentPrincipal: vi.fn(),
    listMyScopes: vi.fn(),
    selectMyScope: vi.fn(),
    listMyNotifications: vi.fn(),
    markNotificationRead: vi.fn(),
  }
})

vi.mock('../api/work-records', async () => {
  const actual = await vi.importActual<typeof import('../api/work-records')>('../api/work-records')
  return {
    ...actual,
    listNotifications: vi.fn(),
    markNotificationRead: vi.fn(),
  }
})

import { render as rtlRender } from '@testing-library/react'
import AppWorkspaceShell from './AppWorkspaceShell'
import { SessionProvider } from './session-context'
import { PrincipalProvider } from './principal-context'
import * as generated from '../api/generated/cluster'
import * as notifications from '../api/work-records'
import type { Session } from '../api'

const FACILITY = '01980f50-5f0d-7000-8000-0000000000a1'
const UNIT = '01980f50-5f0d-7000-8000-0000000000a2'
const SUBJECT = '01980f50-5f0d-7000-8000-0000000000f1'
const TENANT = '01980f50-5f0d-7000-8000-0000000000f2'

function buildSession(): Session {
  return {
    csrf_token: 'csrf-shell',
    user_id: SUBJECT,
    expires_at: '2099-01-01T00:00:00Z',
    restricted: false,
    principal: { user_id: SUBJECT, facility_id: FACILITY },
    access_token: 'csrf-shell',
  }
}

function principalResponse(): { data: { subject_id: string; tenant_id: string; clearance: 'public'; correlation_id: string; capabilities: string[]; features: { work_management: boolean; tasks: boolean } }; status: 200; headers: Headers } {
  const headers = new Headers()
  return {
    status: 200,
    headers,
    data: {
      subject_id: SUBJECT,
      tenant_id: TENANT,
      clearance: 'public',
      correlation_id: SUBJECT,
      capabilities: ['authorization.role.read', 'authorization.role.manage'],
      features: { work_management: false, tasks: true },
    },
  }
}

function scopeResponse(): { data: { available_scopes: Array<{ scope_type: 'facility' | 'unit' | 'cluster'; scope_id: string; label: string }>; effective_scope: { scope_type: 'facility' | 'unit' | 'cluster'; scope_id: string; label: string } }; status: 200; headers: Headers } {
  const headers = new Headers()
  headers.set('ETag', '"1"')
  return {
    status: 200,
    headers,
    data: {
      available_scopes: [
        { scope_type: 'facility', scope_id: FACILITY, label: 'Facility A' },
        { scope_type: 'unit', scope_id: UNIT, label: 'Unit A' },
      ],
      effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'Facility A' },
    },
  }
}

function renderShell() {
  return rtlRender(
    <SessionProvider locale="ar" session={buildSession()}>
      <PrincipalProvider token="csrf-shell">
        <AppWorkspaceShell
          locale="ar"
          session={buildSession()}
          onLocaleChange={vi.fn()}
          onLogout={vi.fn()}
        />
      </PrincipalProvider>
    </SessionProvider>,
  )
}

async function clickUserMenuItem(label: string) {
  const toggle = document.querySelector('.header-user-menu button') as HTMLButtonElement | null
  expect(toggle).toBeTruthy()
  toggle?.click()
  // The dropdown only renders after React state has updated; wait until
  // the menu items are in the DOM before clicking.
  await waitFor(() => {
    const item = Array.from(document.querySelectorAll('#user-menu [role="menuitem"]'))
      .find((el) => (el.textContent ?? '').includes(label))
    expect(item).toBeTruthy()
  })
  const item = Array.from(document.querySelectorAll('#user-menu [role="menuitem"]'))
    .find((el) => (el.textContent ?? '').includes(label)) as HTMLAnchorElement | undefined
  item?.click()
}

describe('AppWorkspaceShell navigate preserves URL state', () => {
  let pushState: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    window.history.replaceState({}, '', '/admin/authorization/roles?tab=roles-permissions')
    pushState = vi.spyOn(window.history, 'pushState')
    vi.mocked(generated.getCurrentPrincipal).mockResolvedValue(principalResponse())
    vi.mocked(generated.listMyScopes).mockResolvedValue(scopeResponse())
    vi.mocked(generated.listMyNotifications).mockResolvedValue({ data: { items: [], next_cursor: null }, status: 200, headers: new Headers() })
    vi.mocked(notifications.listNotifications).mockResolvedValue({ items: [], next_cursor: null })
    vi.mocked(notifications.markNotificationRead).mockResolvedValue({
      id: '00000000-0000-7000-8000-000000000000',
      title: 'mock',
      title_ar: 'mock',
      body: 'mock',
      body_ar: 'mock',
      created_at: '2099-01-01T00:00:00Z',
      is_read: true,
      severity: 'info',
      category: 'system',
      action_url: null,
    })
  })

  afterEach(() => {
    pushState.mockRestore()
    vi.clearAllMocks()
    window.history.replaceState({}, '', '/')
  })

  it('updates the address bar when only the search string changes on the same pathname', async () => {
    renderShell()

    await waitFor(() => {
      expect(vi.mocked(generated.listMyScopes)).toHaveBeenCalled()
    })

    // First click navigates to `/me/security` (no query) — pushState is
    // required because the URL changed.
    const callsBefore = pushState.mock.calls.length
    clickUserMenuItem('الأمان الشخصي')
    await waitFor(() => {
      expect(pushState.mock.calls.length).toBe(callsBefore + 1)
    })
    expect(pushState.mock.calls[pushState.mock.calls.length - 1]?.[2]).toBe('/me/security')

    // Prime the address bar with a search on the same pathname (`/me/security?tab=foo`)
    // to reproduce the regression scenario: same pathname, different search.
    // The previous bug compared pathname-only, so navigating back to bare
    // `/me/security` would no-op. The fix compares the full URL, so pushState
    // is invoked again.
    window.history.replaceState({}, '', '/me/security?tab=foo')
    const callsBeforeSecond = pushState.mock.calls.length
    clickUserMenuItem('الأمان الشخصي')
    await waitFor(() => {
      expect(pushState.mock.calls.length).toBe(callsBeforeSecond + 1)
    })
    expect(pushState.mock.calls[pushState.mock.calls.length - 1]?.[2]).toBe('/me/security')
    expect(window.location.pathname).toBe('/me/security')
    expect(window.location.search).toBe('')
  })

  it('skips pushState when the target URL matches the current pathname + search + hash', async () => {
    renderShell()

    await waitFor(() => {
      expect(vi.mocked(generated.listMyScopes)).toHaveBeenCalled()
    })

    // First click lands at /me/security (no query).
    clickUserMenuItem('الأمان الشخصي')
    await waitFor(() => {
      expect(window.location.pathname).toBe('/me/security')
    })

    // Second click on the same entry — URL is unchanged, so pushState is
    // skipped.
    const callsBefore = pushState.mock.calls.length
    clickUserMenuItem('الأمان الشخصي')
    await waitFor(() => {
      expect(pushState.mock.calls.length).toBe(callsBefore)
    })
  })
})
