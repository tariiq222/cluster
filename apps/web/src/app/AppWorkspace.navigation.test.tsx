import { describe, expect, it, vi } from 'vitest'

import { shellNavigation } from './AppWorkspace'
import type { AppRoute } from '../shell/routes'

const home: AppRoute = { name: 'list' }

function paths(capabilities: readonly string[] | null): string[] {
  return shellNavigation(home, 'ar', vi.fn(), capabilities).flatMap((group) =>
    group.items.map((item) => item.path),
  )
}

function groupKeys(capabilities: readonly string[] | null): string[] {
  return shellNavigation(home, 'ar', vi.fn(), capabilities).map((group) => group.key)
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
})
