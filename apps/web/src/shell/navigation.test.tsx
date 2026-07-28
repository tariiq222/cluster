// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'

import {
  NAVIGATION_ENTRIES,
  USER_MENU_ENTRIES,
  buildNavigationGroups,
  buildUserMenuEntries,
  isNavigationEntryVisible,
} from './navigation'

const ENABLED = { work_management: true, tasks: true }

const PATHS = (...capabilities: string[]): string[] => {
  const groups = buildNavigationGroups({ locale: 'ar', capabilities, features: ENABLED })
  return groups.flatMap((group) => group.items.map((item) => item.path))
}

const GROUP_KEYS = (...capabilities: string[] | [null]) => {
  const groups = buildNavigationGroups({
    locale: 'ar',
    capabilities: capabilities[0] === null ? null : (capabilities as string[]),
    features: ENABLED,
  })
  return groups.map((group) => group.key)
}

const fullCapabilities = [
  'authorization.audit.read',
  'audit.event.read',
  'reporting.dashboard',
  'reporting.list',
  'workflow.decide',
  'workflow.reassign',
  'workflow.escalate',
  'workflow.read',
  'workflow.manage',
  'workflow.author',
  'workflow.approve',
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
  'platform_settings.read',
]

const WORK_MANAGEMENT_CAPABILITIES = [
  'workflow.decide',
  'workflow.author',
  'workflow.approve',
  'workflow.read',
  'workflow.list',
  'workflow.reassign',
  'workflow.escalate',
  'work_definition.read',
  'work_definition.list',
  'tasks.read',
  'tasks.list',
  'documents.read',
  'documents.list',
]

describe('navigation registry', () => {
  it('exposes the merged sidebar groups for a full platform owner', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: fullCapabilities,
      features: ENABLED,
    })
    expect(groups.map((group) => group.key)).toEqual([
      'my-work',
      'organization-workforce',
      'processes-workflow',
      'governance-access',
      'reports-insights',
      'platform-management',
      'internal',
    ])
    for (const group of groups) {
      expect(Reflect.get(group, 'icon'), `${group.key} group icon`).toBeTruthy()
    }
  })

  it('keeps the employee sidebar free of governance, reports, and platform tools', () => {
    const employee = [
      'work_record.create',
      'work_record.read',
      'tasks.read',
      'documents.read',
    ]
    const paths = PATHS(...employee)
    expect(paths).toContain('/')
    expect(paths).toContain('/tasks')
    expect(paths).toContain('/documents')
    expect(paths).not.toContain('/admin/organization')
    expect(paths).not.toContain('/admin/identity/accounts')
    expect(paths).not.toContain('/reports')
    expect(paths).not.toContain('/coverage')
    expect(paths).not.toContain('/api-docs')
    expect(GROUP_KEYS(...employee)).toEqual(['my-work'])
  })

  it('keeps governance and Audit entries in one ordered group', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: fullCapabilities,
      features: ENABLED,
    })
    const governanceItems =
      groups.find((group) => group.key === 'governance-access')?.items ?? []
    expect(governanceItems).toHaveLength(2)
    expect(governanceItems.map((item) => item.path)).toEqual([
      '/admin/identity/accounts',
      '/audit',
    ])
  })

  it('shows Audit navigation only for the ledger read capability', () => {
    expect(PATHS('audit.event.export')).not.toContain('/audit')
    expect(PATHS('audit.integrity.verify')).not.toContain('/audit')
    expect(PATHS('audit.event.read')).toContain('/audit')
  })

  it('keeps internal tools separate from governance', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: fullCapabilities,
      features: ENABLED,
    })
    const internalItems =
      groups
        .find((group) => group.key === 'internal')
        ?.items.map((item) => item.path) ?? []
    expect(internalItems).toContain('/coverage')
    expect(internalItems).toContain('/api-docs')
    expect(internalItems).not.toContain('/admin/identity/accounts')
  })

  it('keeps platform settings in the platform management group', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: ['platform_settings.read'],
      features: ENABLED,
    })
    const platformItems =
      groups
        .find((group) => group.key === 'platform-management')
        ?.items.map((item) => item.path) ?? []
    expect(platformItems).toContain('/admin/platform')
  })

  it('hides every gated entry while the principal context is still loading', () => {
    expect(GROUP_KEYS(null)).toEqual(['my-work'])
    expect(PATHS()).toEqual(['/'])
  })

  it('hides governance and platform management from principals without admin or platform capabilities', () => {
    const manager = [
      'reporting.list',
      'organization.unit.read',
      'identity.account.read',
    ]
    expect(GROUP_KEYS(...manager)).toContain('governance-access')
    expect(GROUP_KEYS(...manager)).not.toContain('platform-management')
    expect(GROUP_KEYS(...manager)).not.toContain('internal')
  })

  it('keeps the user menu separate from the sidebar with only personal entries', () => {
    const items = buildUserMenuEntries('ar')
    expect(items.map((item) => item.key).sort()).toEqual([
      'access-context',
      'personal-security',
    ])
    expect(items.map((item) => item.path).sort()).toEqual([
      '/me/access',
      '/me/security',
    ])
  })

  it('classifies every entry with a non-empty label key so the sidebar never renders empty text', () => {
    expect(NAVIGATION_ENTRIES.length).toBeGreaterThan(0)
    for (const entry of NAVIGATION_ENTRIES) {
      expect(entry.labelKey).toBeTruthy()
      expect(entry.route).toBeDefined()
      expect(isNavigationEntryVisible(entry, fullCapabilities, ENABLED)).toBe(true)
    }
    expect(USER_MENU_ENTRIES.length).toBeGreaterThan(0)
  })

  it('exposes the dashboards page only to managers holding the dashboard capability', () => {
    expect(PATHS('reporting.list')).not.toContain('/dashboards')
    expect(PATHS('reporting.dashboard')).toContain('/dashboards')
  })

  it('keeps the approvals entry hidden from read-only principals', () => {
    const approvals = NAVIGATION_ENTRIES.find(
      (entry) => entry.key === 'approvals',
    )
    expect(approvals).toBeDefined()
    if (approvals) {
      expect(isNavigationEntryVisible(approvals, ['workflow.read'], ENABLED)).toBe(false)
      expect(isNavigationEntryVisible(approvals, ['workflow.decide'], ENABLED)).toBe(
        true,
      )
    }
  })

  it('hides every work-management entry when the work_management feature is disabled', () => {
    const disabled = { work_management: false, tasks: true }
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: WORK_MANAGEMENT_CAPABILITIES,
      features: disabled,
    })
    const paths = groups.flatMap((group) => group.items.map((item) => item.path))
    expect(paths).not.toContain('/approvals')
    expect(paths).not.toContain('/my-requests')
    expect(paths).not.toContain('/procedures')
    expect(paths).not.toContain('/admin/procedures/review')
    expect(paths).not.toContain('/admin/work-definitions')
    expect(paths).not.toContain('/admin/workflow')
    expect(paths).not.toContain('/procedures/new')
    expect(paths).not.toContain('/admin/procedures/authoring')
    // The processes-workflow group must vanish entirely when disabled.
    expect(groups.find((group) => group.key === 'processes-workflow')).toBeUndefined()
    // Tasks/documents/home remain visible.
    expect(paths).toContain('/')
    expect(paths).toContain('/tasks')
    expect(paths).toContain('/documents')
  })

  it('shows work-management entries again when the feature is enabled', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: WORK_MANAGEMENT_CAPABILITIES,
      features: ENABLED,
    })
    const paths = groups.flatMap((group) => group.items.map((item) => item.path))
    expect(paths).toContain('/approvals')
    expect(paths).toContain('/my-requests')
    expect(paths).toContain('/admin/procedures/review')
    expect(paths).toContain('/admin/work-definitions')
    expect(paths).toContain('/admin/workflow')
    expect(groups.find((group) => group.key === 'processes-workflow')).toBeDefined()
  })

  it('treats a null feature projection as disabled for work-management entries', () => {
    const groups = buildNavigationGroups({
      locale: 'ar',
      capabilities: WORK_MANAGEMENT_CAPABILITIES,
      features: null,
    })
    const paths = groups.flatMap((group) => group.items.map((item) => item.path))
    expect(paths).not.toContain('/approvals')
    expect(paths).not.toContain('/admin/work-definitions')
    expect(paths).not.toContain('/admin/workflow')
  })
})