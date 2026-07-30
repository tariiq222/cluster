// @vitest-environment jsdom

import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AccessWorkspace, accessSectionForRoute } from './AccessWorkspace'
import { SessionProvider } from '../../app/session-context'
import type { Session } from '../../api'
import type { AppRoute } from '../../shell/routes'


vi.mock('../accounts-permissions/AccountsTab', () => ({
  AccountsTab: ({ locale, capabilities }: { locale: string; capabilities: readonly string[] }) => <div data-testid="accounts-tab" data-locale={locale} data-capabilities={capabilities.join(',')} />,
}))
vi.mock('../accounts-permissions/RolesPermissionsTab', () => ({
  RolesPermissionsTab: ({ locale, capabilities }: { locale: string; capabilities: readonly string[] }) => <div data-testid="roles-permissions-tab" data-locale={locale} data-capabilities={capabilities.join(',')} />,
}))
vi.mock('../accounts-permissions/RoleAssignmentsTab', () => ({
  RoleAssignmentsTab: ({ locale, capabilities }: { locale: string; capabilities: readonly string[] }) => <div data-testid="role-assignments-tab" data-locale={locale} data-capabilities={capabilities.join(',')} />,
}))
vi.mock('../accounts-permissions/PoliciesScopesTab', () => ({
  PoliciesScopesTab: ({ locale, capabilities }: { locale: string; capabilities: readonly string[] }) => <div data-testid="policies-scopes-tab" data-locale={locale} data-capabilities={capabilities.join(',')} />,
}))
vi.mock('../accounts-permissions/PermissionDecisionInspector', () => ({
  PermissionDecisionInspector: ({ locale, decisionId }: { locale: string; decisionId?: string }) => <div data-testid="permission-decision-inspector" data-locale={locale} data-decision-id={decisionId ?? ''} />,
}))

const session: Session = {
  csrf_token: 'csrf-token',
  access_token: 'csrf-token',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  expires_at: '2026-07-17T12:00:00Z',
  restricted: false,
  principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
}

afterEach(() => {
  cleanup()
})

function renderRoute(activeRoute: AppRoute, navigate: (path: string) => void = vi.fn(), capabilities: readonly string[] = ['authorization.role.manage']) {
  return render(
    <SessionProvider locale="en" session={session}>
      <AccessWorkspace locale="en" activeRoute={activeRoute} navigate={navigate} capabilities={capabilities} />
    </SessionProvider>,
  )
}

function sectionLabels(): string[] {
  const regions = screen.getAllByRole('region', { name: 'Identity & access' })
  const workspace = regions[regions.length - 1] as HTMLElement
  const nav = workspace.querySelector('nav.workspace-tabs')
  if (!nav) throw new Error('Access workspace tabs nav not found')
  return Array.from(nav.querySelectorAll('a')).map((anchor) => anchor.textContent?.trim() ?? '')
}

describe('accessSectionForRoute', () => {
  it('maps every governance and policy route to one of the five sections', () => {
    expect(accessSectionForRoute({ name: 'identity-accounts' })).toBe('accounts')
    expect(accessSectionForRoute({ name: 'authorization', resource: 'roles' })).toBe('roles-permissions')
    expect(accessSectionForRoute({ name: 'authorization', resource: 'capabilities' })).toBe('roles-permissions')
    expect(accessSectionForRoute({ name: 'authorization', resource: 'role-assignments' })).toBe('role-assignments')
    expect(accessSectionForRoute({ name: 'authorization', resource: 'classification-policies' })).toBe('policies-scopes')
    expect(accessSectionForRoute({ name: 'authorization', resource: 'field-access-templates' })).toBe('policies-scopes')
    expect(accessSectionForRoute({ name: 'access-scopes' })).toBe('policies-scopes')
    expect(accessSectionForRoute({ name: 'access-explanation' })).toBe('decision-inspector')
  })
})

describe('AccessWorkspace routed content', () => {
  it.each([
    [{ name: 'identity-accounts' }, 'accounts-tab'],
    [{ name: 'authorization', resource: 'roles' }, 'roles-permissions-tab'],
    [{ name: 'authorization', resource: 'role-assignments' }, 'role-assignments-tab'],
    [{ name: 'access-scopes' }, 'policies-scopes-tab'],
    [{ name: 'access-explanation' }, 'permission-decision-inspector'],
  ] as const)('mounts the current Task 7 component for %o', (route, expectedTab) => {
    const { unmount } = renderRoute(route)

    const mountedTab = screen.getByTestId(expectedTab)
    expect(mountedTab.getAttribute('data-locale')).toBe('en')
    if (expectedTab !== 'permission-decision-inspector') {
      expect(mountedTab.getAttribute('data-capabilities')).toBe('authorization.role.manage')
    }
    expect(screen.getByRole('status')).toBeTruthy()
    unmount()
  })

  it('passes a decision deep-link id to the current inspector component', () => {
    renderRoute({ name: 'access-explanation', decisionId: 'decision-7' })

    expect(screen.getByTestId('permission-decision-inspector').getAttribute('data-decision-id')).toBe('decision-7')
    expect(screen.getByRole('link', { name: 'Permission decision inspector' }).getAttribute('aria-current')).toBe('page')
  })
})

describe('AccessWorkspace governance tabs', () => {
  it('renders exactly five section tabs and never personal access, delegations, or supervisory', () => {
    renderRoute({ name: 'identity-accounts' })

    expect(sectionLabels()).toEqual([
      'Accounts',
      'Roles and permissions',
      'Role assignments',
      'Permission policies and scopes',
      'Permission decision inspector',
    ])
    expect(screen.queryByRole('link', { name: 'Delegations' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Supervisory relationships' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Personal access' })).toBeNull()
    expect(screen.getByRole('link', { name: 'Permission policies and scopes' }).getAttribute('href')).toBe(
      '/admin/authorization/classification-policies',
    )
  })

  it('activates the policies-and-scopes tab on the classification-policies deep link', () => {
    renderRoute({ name: 'authorization', resource: 'classification-policies' })

    const policiesLink = screen.getByRole('link', { name: 'Permission policies and scopes' })
    expect(policiesLink.getAttribute('aria-current')).toBe('page')
  })

  it('activates the policies-and-scopes tab on the access-scopes deep link', () => {
    renderRoute({ name: 'access-scopes' })

    const policiesLink = screen.getByRole('link', { name: 'Permission policies and scopes' })
    expect(policiesLink.getAttribute('aria-current')).toBe('page')
  })

  it('activates the policies-and-scopes tab on the field-access-templates deep link', () => {
    renderRoute({ name: 'authorization', resource: 'field-access-templates' })

    const policiesLink = screen.getByRole('link', { name: 'Permission policies and scopes' })
    expect(policiesLink.getAttribute('aria-current')).toBe('page')
  })

  it('activates the roles-and-permissions tab on the capabilities deep link', () => {
    renderRoute({ name: 'authorization', resource: 'capabilities' })

    const rolesLink = screen.getByRole('link', { name: 'Roles and permissions' })
    expect(rolesLink.getAttribute('aria-current')).toBe('page')
  })
})

describe('AccessWorkspace personal access route', () => {
  it('renders an empty state when /me/access reaches AccessWorkspace (the integration lane renders AccessContext directly)', () => {
    renderRoute({ name: 'access-context' })
    // The personal-access screen is owned by WorkspaceContent, not AccessWorkspace.
    // When AccessWorkspace is reached with the personal route it must show the
    // choose-an-identity placeholder, never a Personal access tab.
    expect(screen.queryByRole('link', { name: 'صلاحياتي ونطاق عملي' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Personal access' })).toBeNull()
    expect(screen.queryByRole('heading', { name: /صلاحياتي/ })).toBeNull()
  })
})
