// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'

import {
  NAVIGATION_ENTRIES,
  USER_MENU_ENTRIES,
  buildNavigationGroups,
  buildUserMenuEntries,
  isNavigationEntryVisible,
} from './navigation'

const PATHS = (...capabilities: string[]): string[] => {
  const groups = buildNavigationGroups({ locale: 'ar', capabilities })
  return groups.flatMap((group) => group.items.map((item) => item.path))
}

const GROUP_KEYS = (...capabilities: string[] | [null]) => {
  const groups = buildNavigationGroups({ locale: 'ar', capabilities: capabilities[0] === null ? null : (capabilities as string[]) })
  return groups.map((group) => group.key)
}

describe('navigation registry', () => {
  it('provides a meaningful visible icon for every non-empty sidebar group', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: [
        'organization.facility.read',
        'work_definition.read',
        'identity.account.read',
        'reporting.list',
        'authorization.audit.read',
      ],
    })

    expect(groups.map((group) => group.key)).toEqual([
      'my-work',
      'organization-workforce',
      'processes-workflow',
      'accounts-access',
      'reports-insights',
      'internal',
    ])
    for (const group of groups) {
      expect(Reflect.get(group, 'icon'), `${group.key} group icon`).toBeTruthy()
    }
  })

  it('splits administration into scannable work-domain groups', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: [
        'organization.facility.read',
        'organization.person.read',
        'organization.temporary-assignment.read',
        'organization.import.read',
        'work_definition.read',
        'workflow.manage',
        'work_definition.publish',
        'identity.account.read',
        'authorization.role.read',
        'authorization.assignment.read',
        'authorization.delegation.read',
        'authorization.policy.read',
        'reporting.list',
        'reporting.dashboard',
        'authorization.audit.read',
      ],
    })

    expect(groups.map((group) => group.key)).toEqual([
      'my-work',
      'organization-workforce',
      'processes-workflow',
      'accounts-access',
      'reports-insights',
      'internal',
    ])
    expect(groups.find((group) => group.key === 'organization-workforce')?.items.map((item) => item.path)).toEqual([
      '/admin/organization',
      '/admin/organization/people',
      '/admin/organization/temporary-assignments',
      '/admin/imports/organization',
    ])
    expect(groups.find((group) => group.key === 'processes-workflow')?.items.map((item) => item.path)).toEqual([
      '/admin/work-definitions',
      '/admin/workflow',
      '/admin/procedures/review',
    ])
    expect(groups.find((group) => group.key === 'accounts-access')?.items.map((item) => item.path)).toEqual([
      '/admin/identity/accounts',
      '/admin/authorization/roles',
      '/admin/authorization/role-assignments',
      '/admin/authorization/access-scopes',
      '/admin/authorization/delegations',
      '/admin/authorization/classification-policies',
      '/admin/authorization/field-access-templates',
    ])
    expect(groups.find((group) => group.key === 'reports-insights')?.items.map((item) => item.path)).toEqual([
      '/reports',
      '/dashboards',
    ])
  })

  it('hides every gated entry while the principal context is still loading', () => {
    expect(GROUP_KEYS(null)).toEqual(['my-work'])
    expect(PATHS()).toEqual(['/'])
  })

  it('keeps the employee sidebar free of administration and internal tools', () => {
    const employee = ['work_record.create', 'work_record.read', 'tasks.read', 'documents.read']
    const paths = PATHS(...employee)
    expect(paths).toContain('/')
    expect(paths).toContain('/tasks')
    expect(paths).toContain('/documents')
    expect(paths).not.toContain('/admin/organization')
    expect(paths).not.toContain('/admin/identity/accounts')
    expect(paths).not.toContain('/reports')
    expect(paths).not.toContain('/coverage')
    expect(paths).not.toContain('/api-docs')
    expect(GROUP_KEYS(...employee)).not.toContain('administration')
    expect(GROUP_KEYS(...employee)).not.toContain('internal')
  })

  it('offers the approval inbox to a principal holding a decision capability', () => {
    const decider = ['workflow.decide']
    const paths = PATHS(...decider)
    expect(paths).toContain('/approvals')
    expect(paths).not.toContain('/reports')
    expect(GROUP_KEYS(...decider)).toEqual(['my-work'])
  })

  it('exposes the dashboards page only to managers holding the dashboard capability', () => {
    expect(PATHS('reporting.list')).not.toContain('/dashboards')
    expect(PATHS('reporting.dashboard')).toContain('/dashboards')
  })

  it('keeps tab destinations out of the sidebar and names the internal group correctly', () => {
    const entries = NAVIGATION_ENTRIES.map((entry) => entry.key)
    expect(entries).toContain('organization')
    expect(entries).toContain('roles')
    expect(entries).not.toContain('organization-structure')
    expect(entries).not.toContain('capabilities')

    const groups = buildNavigationGroups({ locale: 'ar', capabilities: ['authorization.audit.read'] })
    expect(groups.find((group) => group.key === 'internal')?.labelKey).toBe('internalTools')
  })

  it('keeps internal tools hidden from regular principals', () => {
    const admin = ['reporting.list', 'organization.unit.read', 'identity.account.read']
    expect(GROUP_KEYS(...admin)).not.toContain('internal')
    const platformOwner = ['reporting.list', 'organization.unit.read', 'authorization.audit.read']
    expect(GROUP_KEYS(...platformOwner)).toContain('internal')
    expect(PATHS(...platformOwner)).toEqual(expect.arrayContaining(['/coverage', '/api-docs']))
  })

  it('puts documents in My Work and tools in Internal Tools', () => {
    const paths = PATHS('documents.read', 'authorization.audit.read')
    const groups = buildNavigationGroups({ locale: 'ar', capabilities: ['documents.read', 'authorization.audit.read'] })
    expect(groups.find((group) => group.key === 'my-work')?.items.map((item) => item.path)).toContain('/documents')
    expect(groups.find((group) => group.key === 'internal')?.items.map((item) => item.path)).toEqual(['/coverage', '/api-docs'])
    expect(paths).toContain('/documents')
    expect(paths).toContain('/coverage')
    expect(paths).toContain('/api-docs')
  })

  it('removes the Administration group when no admin capability is held', () => {
    const employee = ['tasks.read', 'documents.read']
    expect(GROUP_KEYS(...employee)).toEqual(['my-work'])
  })

  it('keeps the user menu separate from the sidebar with only personal entries', () => {
    const items = buildUserMenuEntries('ar')
    expect(items.map((item) => item.key).sort()).toEqual(['access-context', 'personal-security'])
    expect(items.map((item) => item.path).sort()).toEqual(['/me/access', '/me/security'])
  })

  it('classifies every entry with a non-empty label key so the sidebar never renders empty text', () => {
    expect(NAVIGATION_ENTRIES.length).toBeGreaterThan(0)
    const allCapabilities = [
      'authorization.audit.read',
      'reporting.dashboard',
      'reporting.list',
      'workflow.decide',
      'workflow.reassign',
      'workflow.escalate',
      'workflow.read',
      'workflow.list',
      'workflow.manage',
      'workflow.approve',
      'work_definition.publish',
      'work_definition.read',
      'work_definition.list',
      'tasks.read',
      'tasks.list',
      'documents.read',
      'documents.list',
      'organization.facility.read',
      'organization.unit.read',
      'organization.person.read',
      'organization.temporary-assignment.read',
      'organization.import.read',
      'identity.account.read',
      'authorization.role.read',
      'authorization.capability.read',
      'authorization.assignment.read',
      'authorization.delegation.read',
      'authorization.policy.read',
      'authorization.decision.read',
    ]
    for (const entry of NAVIGATION_ENTRIES) {
      expect(entry.labelKey).toBeTruthy()
      expect(entry.route).toBeDefined()
      expect(isNavigationEntryVisible(entry, allCapabilities)).toBe(true)
    }
    expect(USER_MENU_ENTRIES.length).toBeGreaterThan(0)
  })

  it('keeps the access-scopes entry on its own capability, not the roles one', () => {
    expect(isNavigationEntryVisible(
      NAVIGATION_ENTRIES.find((entry) => entry.key === 'access-scopes')!,
      ['authorization.role.read'],
    )).toBe(false)
    expect(isNavigationEntryVisible(
      NAVIGATION_ENTRIES.find((entry) => entry.key === 'access-scopes')!,
      ['authorization.assignment.read'],
    )).toBe(true)
  })

  it('keeps the approvals entry hidden from read-only principals', () => {
    expect(isNavigationEntryVisible(
      NAVIGATION_ENTRIES.find((entry) => entry.key === 'approvals')!,
      ['workflow.read'],
    )).toBe(false)
    expect(isNavigationEntryVisible(
      NAVIGATION_ENTRIES.find((entry) => entry.key === 'approvals')!,
      ['workflow.decide'],
    )).toBe(true)
  })
})
