import { describe, expect, it } from 'vitest'

import {
  capabilityForRoute,
  isRouteVisible,
  primaryRoutes,
} from '../shell/routes'

/**
 * Mirrors the sidebar groups produced by `shellNavigation` inside `AppWorkspace`
 * without depending on its internal (non-exported) implementation. The grouping
 * is intentionally redundant with `AppWorkspace` so this test catches drift
 * between the sidebar structure and the route registry.
 */
const SIDEBAR_GROUPS: ReadonlyArray<{ key: string; paths: readonly string[] }> = [
  { key: 'work', paths: ['/', '/tasks'] },
  {
    key: 'operations',
    paths: [
      '/admin/work-definitions',
      '/admin/organization',
      '/admin/identity/accounts',
      '/reports',
    ],
  },
  { key: 'review', paths: ['/documents', '/coverage', '/api-docs'] },
]

function visiblePaths(capabilities: readonly string[] | null): string[] {
  return primaryRoutes
    .filter(({ route }) => isRouteVisible(route, capabilities))
    .map(({ path }) => path)
}

function paths(capabilities: readonly string[] | null): string[] {
  return visiblePaths(capabilities)
}

function groupKeys(capabilities: readonly string[] | null): string[] {
  const visible = new Set(visiblePaths(capabilities))
  return SIDEBAR_GROUPS.filter((group) =>
    group.paths.some((path) => visible.has(path)),
  ).map((group) => group.key)
}

describe('sidebar navigation by capability', () => {
  it('offers an employee their own work and nothing administrative', () => {
    const employee = ['work_record.create', 'work_record.read', 'tasks.read', 'documents.read']

    expect(paths(employee)).toContain('/')
    expect(paths(employee)).toContain('/tasks')
    expect(paths(employee)).not.toContain('/admin/organization')
    expect(paths(employee)).not.toContain('/admin/identity/accounts')
    expect(paths(employee)).not.toContain('/reports')
  })

  it('drops a group once every entry in it is withheld', () => {
    const employee = ['work_record.read', 'tasks.read']

    // "Operations" holds only administrative entries, so it disappears with them
    // rather than leaving an empty heading that advertises what is withheld.
    expect(groupKeys(employee)).not.toContain('operations')
    expect(groupKeys(employee)).toContain('work')
  })

  it('offers the operations group to a principal holding one of its capabilities', () => {
    const officer = ['tasks.read', 'reporting.list']

    expect(groupKeys(officer)).toContain('operations')
    expect(paths(officer)).toContain('/reports')
    expect(paths(officer)).not.toContain('/admin/organization')
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

    expect(paths(admin)).toEqual(
      expect.arrayContaining([
        '/',
        '/tasks',
        '/admin/work-definitions',
        '/admin/organization',
        '/admin/identity/accounts',
        '/reports',
        '/documents',
      ]),
    )
  })

  it('withholds gated entries while the principal context is still loading', () => {
    expect(paths(null)).not.toContain('/admin/identity/accounts')
    expect(paths(null)).not.toContain('/reports')
    expect(paths(null)).toContain('/')
  })

  it('classifies the Stage 3 procedure routes with the operations-office capabilities', () => {
    expect(capabilityForRoute({ name: 'procedure-authoring' })).toBe('workflow.author')
    expect(capabilityForRoute({ name: 'procedure-office-review' })).toBe('workflow.approve')
    expect(capabilityForRoute({ name: 'procedure-guide' })).toBeNull()

    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.author'])).toBe(true)
    expect(isRouteVisible({ name: 'procedure-authoring' }, ['workflow.approve'])).toBe(false)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.approve'])).toBe(true)
    expect(isRouteVisible({ name: 'procedure-office-review' }, ['workflow.author'])).toBe(false)

    expect(isRouteVisible({ name: 'procedure-guide' }, null)).toBe(true)
    expect(isRouteVisible({ name: 'procedure-guide' }, [])).toBe(true)
  })
})
