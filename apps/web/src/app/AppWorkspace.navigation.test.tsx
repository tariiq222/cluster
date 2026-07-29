// @vitest-environment jsdom
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

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
      { name: 'coverage' } as const,
      { name: 'api-docs' } as const,
      { name: 'dashboards' } as const,
      { name: 'access-explanation' } as const,
    ]
    for (const route of protectedRoutes) {
      expect(isRouteVisible(route, null)).toBe(false)
      expect(isRouteVisible(route, [])).toBe(false)
    }
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
      'organization.temporary-assignment.read',
      'organization.import.read',
      'identity.account.read',
      'authorization.role.read',
      'authorization.assignment.read',
      'authorization.delegation.read',
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
    const sidebarExcludedPrefixes = ['/me/', '/work-records/new', '/search', '/notifications', '/admin/workflow/day2', '/admin/procedures/authoring', '/procedures/new', '/admin/organization/structure', '/admin/organization/people', '/admin/organization/temporary-assignments', '/admin/imports/organization', '/admin/authorization/capabilities', '/admin/authorization/roles', '/admin/authorization/role-assignments', '/admin/authorization/delegations', '/admin/authorization/classification-policies', '/admin/authorization/field-access-templates', '/admin/authorization/access-scopes', '/admin/authorization/explain', '/admin/relationships/supervisory', '/approvals', '/my-requests', '/admin/procedures/review', '/admin/work-definitions', '/admin/workflow', '/procedures', '/audit', '/dashboards', '/coverage', '/api-docs']
    const routePaths = pathsFor(allCaps, ALL_FEATURES).filter((path) => !sidebarExcludedPrefixes.some((prefix) => path.startsWith(prefix)))
    const navPaths = navigationPathsFor(allCaps, ALL_FEATURES)
    for (const path of routePaths) {
      if (path === '/') continue
      expect(navPaths).toContain(path)
    }
  })
})
