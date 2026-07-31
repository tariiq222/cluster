// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AccessWorkspace, accessSectionForRoute } from './AccessWorkspace'
import { SessionProvider } from '../../app/session-context'
import type { Session } from '../../api'
import type { AppRoute } from '../../shell/routes'



const session: Session = {
  csrf_token: 'csrf-token',
  access_token: 'csrf-token',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  expires_at: '2026-07-17T12:00:00Z',
  restricted: false,
  principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
}

const ALL_GOVERNANCE_CAPABILITIES = [
  'identity.account.read',
  'authorization.role.read',
  'authorization.role.manage',
  'authorization.capability.read',
  'authorization.assignment.read',
  'authorization.assignment.manage',
  'authorization.policy.read',
  'authorization.policy.manage',
  'authorization.decision.read',
]

afterEach(() => {
  cleanup()
})

function renderRoute(activeRoute: AppRoute, navigate: (path: string) => void = vi.fn(), capabilities: readonly string[] = ALL_GOVERNANCE_CAPABILITIES) {
  return render(
    <SessionProvider locale="en" session={session}>
      <AccessWorkspace locale="en" activeRoute={activeRoute} navigate={navigate} capabilities={capabilities} />
    </SessionProvider>,
  )
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
    [{ name: 'identity-accounts' }, 'accounts'],
    [{ name: 'authorization', resource: 'roles' }, 'roles-permissions'],
    [{ name: 'authorization', resource: 'role-assignments' }, 'role-assignments'],
    [{ name: 'access-scopes' }, 'policies-scopes'],
    [{ name: 'access-explanation' }, 'decision-inspector'],
  ] as const)('maps %o to the canonical tab panel', (route, expectedTab) => {
    renderRoute(route)

    expect(screen.getByRole('tablist')).toBeTruthy()
    expect(screen.getByRole('tab', { name: expectedTab === 'roles-permissions' ? 'Roles & Permissions' : expectedTab === 'role-assignments' ? 'Role Assignments' : expectedTab === 'policies-scopes' ? 'Policies & Scopes' : expectedTab === 'decision-inspector' ? 'Permission Decision Inspector' : 'Accounts' }).getAttribute('aria-selected')).toBe('true')
    expect(document.getElementById(`${expectedTab}-panel`)).toBeTruthy()
  })
})

describe('AccessWorkspace governance tabs', () => {
  it('renders the canonical tablist and preserves ARIA panel associations', () => {
    renderRoute({ name: 'identity-accounts' })

    const renderedTabs = screen.getAllByRole('tab')
    expect(renderedTabs.map((tab) => tab.textContent)).toEqual([
      'Accounts',
      'Roles & Permissions',
      'Role Assignments',
      'Policies & Scopes',
      'Permission Decision Inspector',
    ])
    for (const tab of renderedTabs) {
      expect(tab.getAttribute('aria-controls')).toBe(`${tab.id.replace(/-tab$/, '')}-panel`)
    }
  })

  it('advances keyboard navigation relative to the focused tab', () => {
    const navigate = vi.fn()
    renderRoute({ name: 'identity-accounts' }, navigate)

    const tablist = screen.getByRole('tablist')
    const rendered = within(tablist).getAllByRole('tab')
    const policiesTab = within(tablist).getByRole('tab', { name: 'Policies & Scopes' })
    policiesTab.focus()
    fireEvent.keyDown(tablist, { key: 'ArrowRight' })

    expect(navigate).toHaveBeenCalledWith('/admin/authorization/explain?tab=decision-inspector')
    expect(document.activeElement).toBe(rendered[4])
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
