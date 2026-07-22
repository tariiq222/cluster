import { describe, expect, it } from 'vitest'

import { capabilityForRoute, isRouteVisible, primaryRoutes, type AppRoute } from './routes'

describe('navigation capability gating', () => {
  it('offers the screens that carry no capability of their own to anyone', () => {
    const open: AppRoute[] = [
      { name: 'list' },
      { name: 'access-context' },
      { name: 'coverage' },
      { name: 'api-docs' },
      { name: 'notifications' },
    ]
    for (const route of open) {
      expect(capabilityForRoute(route)).toBeNull()
      expect(isRouteVisible(route, [])).toBe(true)
      expect(isRouteVisible(route, null)).toBe(true)
    }
  })

  it('withholds a gated screen from a principal that does not hold its capability', () => {
    const accounts: AppRoute = { name: 'identity-accounts' }
    expect(isRouteVisible(accounts, ['work_record.read', 'tasks.read'])).toBe(false)
    expect(isRouteVisible(accounts, ['identity.account.read'])).toBe(true)
  })

  it('withholds every gated screen while the principal context is still loading', () => {
    expect(isRouteVisible({ name: 'reports' }, null)).toBe(false)
    expect(isRouteVisible({ name: 'organization' }, null)).toBe(false)
    expect(isRouteVisible({ name: 'reports' }, ['reporting.list'])).toBe(true)
  })

  it('gates the procedure authoring and office review routes on workflow capabilities and leaves the guide open', () => {
    expect(capabilityForRoute({ name: 'procedure-authoring' })).toBe('workflow.author')
    expect(capabilityForRoute({ name: 'procedure-office-review' })).toBe('workflow.approve')
    expect(capabilityForRoute({ name: 'procedure-guide' })).toBeNull()

    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.author'])).toBe(true)
    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.approve'])).toBe(false)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.approve'])).toBe(true)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.author'])).toBe(false)
    expect(isRouteVisible({ name: 'procedure-guide' }, [])).toBe(true)
    expect(isRouteVisible({ name: 'procedure-guide' }, null)).toBe(true)
  })

  it('gates Stage 4 request routes on workflow capabilities', () => {
    expect(capabilityForRoute({ name: 'approval-inbox' })).toBe('workflow.read')
    expect(capabilityForRoute({ name: 'my-requests' })).toBe('workflow.read')
    expect(capabilityForRoute({ name: 'new-procedure-request' })).toBe('workflow.author')

    expect(isRouteVisible({ name: 'approval-inbox' }, ['workflow.read'])).toBe(true)
    expect(isRouteVisible({ name: 'my-requests' }, ['workflow.read'])).toBe(true)
    expect(isRouteVisible({ name: 'new-procedure-request' }, ['workflow.author'])).toBe(true)
    expect(isRouteVisible({ name: 'new-procedure-request' }, ['workflow.read'])).toBe(false)
  })

  it('gates each authorization tab on its own resource capability', () => {
    expect(capabilityForRoute({ name: 'authorization', resource: 'roles' })).toBe('authorization.role.read')
    expect(capabilityForRoute({ name: 'authorization', resource: 'delegations' })).toBe('authorization.delegation.read')
    expect(capabilityForRoute({ name: 'authorization', resource: 'field-access-templates' })).toBe('authorization.policy.read')

    const holdsRolesOnly = ['authorization.role.read']
    expect(isRouteVisible({ name: 'authorization', resource: 'roles' }, holdsRolesOnly)).toBe(true)
    expect(isRouteVisible({ name: 'authorization', resource: 'delegations' }, holdsRolesOnly)).toBe(false)
  })

  it('classifies every navigable route, so no entry reaches the sidebar unclassified', () => {
    for (const { route } of primaryRoutes) {
      expect(() => capabilityForRoute(route)).not.toThrow()
      const capability = capabilityForRoute(route)
      expect(capability === null || capability.length > 0).toBe(true)
    }
  })

  it('names capabilities in the module.resource.action shape the catalog uses', () => {
    for (const { route } of primaryRoutes) {
      const capability = capabilityForRoute(route)
      if (capability === null) continue
      expect(capability).toMatch(/^[a-z][a-z0-9_]*(\.[a-z0-9_-]+)+$/)
    }
  })
})
