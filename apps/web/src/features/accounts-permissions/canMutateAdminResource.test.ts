import { describe, expect, it } from 'vitest'

import {
  accountPermissionsTabs,
  canMutateAdminResource,
  isAdvancedAccountPermissionsTab,
  tabAvailableFor,
  type AccountPermissionsTabKey,
  type AdminMutation,
} from './canMutateAdminResource'

describe('canMutateAdminResource matrix', () => {
  it('exposes the canonical five-tab list in design order', () => {
    expect(accountPermissionsTabs).toEqual([
      'accounts',
      'roles-permissions',
      'role-assignments',
      'policies-scopes',
      'decision-inspector',
    ])
  })

  it('grants role creation only when the manage capability is held', () => {
    expect(canMutateAdminResource('roles-permissions', 'create', ['authorization.role.manage'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'create', ['authorization.role.read'])).toBe(false)
    expect(canMutateAdminResource('roles-permissions', 'create', [])).toBe(false)
  })

  it('fails closed when an allowed_actions projection is explicitly empty', () => {
    expect(canMutateAdminResource('roles-permissions', 'edit', ['authorization.role.manage'], ['edit'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'archive', ['authorization.role.manage'], ['edit'])).toBe(false)
    expect(canMutateAdminResource('roles-permissions', 'edit', ['authorization.role.manage'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'edit', ['authorization.role.manage'], [])).toBe(false)
  })

  it('keeps the role-clone action gated on the manage capability', () => {
    expect(canMutateAdminResource('roles-permissions', 'clone', ['authorization.role.manage'], ['clone'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'clone', ['authorization.role.read'], ['clone'])).toBe(false)
  })

  it('gates assignment revoke and expire on the assignment manage capability', () => {
    expect(canMutateAdminResource('role-assignments', 'revoke', ['authorization.assignment.manage'], ['revoke'])).toBe(true)
    expect(canMutateAdminResource('role-assignments', 'revoke', ['authorization.assignment.read'], ['revoke'])).toBe(false)
    expect(canMutateAdminResource('role-assignments', 'expire', ['authorization.assignment.manage'], ['expire'])).toBe(true)
    expect(canMutateAdminResource('role-assignments', 'expire', ['authorization.assignment.read'], ['expire'])).toBe(false)
  })

  it('gates role-capability revoke via the allowed_actions projection', () => {
    expect(canMutateAdminResource('roles-permissions', 'revoke', ['authorization.role.manage'], ['revoke'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'revoke', ['authorization.role.manage'], ['edit'])).toBe(false)
    expect(canMutateAdminResource('roles-permissions', 'revoke', ['authorization.role.read'], ['revoke'])).toBe(true)
  })
  it('gates policies and scopes editing on the policy manage capability', () => {
    expect(canMutateAdminResource('policies-scopes', 'edit', ['authorization.policy.manage'], ['edit'])).toBe(true)
    expect(canMutateAdminResource('policies-scopes', 'edit', ['authorization.policy.read'], ['edit'])).toBe(false)
  })

  it('gates inspector creation on the decision-read capability', () => {
    expect(canMutateAdminResource('decision-inspector', 'create', ['authorization.decision.read'])).toBe(true)
    expect(canMutateAdminResource('decision-inspector', 'create', [])).toBe(false)
  })

  it('gates advanced capability disclosure on the capability-read capability', () => {
    expect(canMutateAdminResource('roles-permissions', 'advanced_disclosure', ['authorization.capability.read'])).toBe(true)
    expect(canMutateAdminResource('roles-permissions', 'advanced_disclosure', [])).toBe(false)
  })
})

describe('canMutateAdminResource fail-closed capability and allowed-actions matrix', () => {
  const expected: Record<AccountPermissionsTabKey, Record<AdminMutation, string | null>> = {
    accounts: {
      create: 'identity.account.manage', edit: null, clone: null, archive: null, revoke: null, expire: null,
      assign: 'authorization.assignment.manage', view_assignments: 'authorization.assignment.read',
      grant: null, retract: null, advanced_disclosure: null,
    },
    'roles-permissions': {
      create: 'authorization.role.manage', edit: 'authorization.role.manage', clone: 'authorization.role.manage',
      archive: 'authorization.role.manage', revoke: null, expire: null, assign: null,
      view_assignments: 'authorization.role.manage', grant: 'authorization.role.manage',
      retract: 'authorization.role.manage', advanced_disclosure: 'authorization.capability.read',
    },
    'role-assignments': {
      create: 'authorization.assignment.manage', edit: 'authorization.assignment.manage', clone: null, archive: null,
      revoke: 'authorization.assignment.manage', expire: 'authorization.assignment.manage', assign: null,
      view_assignments: 'authorization.assignment.read', grant: null, retract: null, advanced_disclosure: null,
    },
    'policies-scopes': {
      create: 'authorization.policy.manage', edit: 'authorization.policy.manage', clone: null,
      archive: 'authorization.policy.manage', revoke: null, expire: null, assign: null,
      view_assignments: 'authorization.policy.read', grant: null, retract: null, advanced_disclosure: null,
    },
    'decision-inspector': {
      create: 'authorization.decision.read', edit: null, clone: null, archive: null, revoke: null, expire: null,
      assign: null, view_assignments: null, grant: null, retract: null, advanced_disclosure: null,
    },
  }

  it('allows only a capable caller and a row that explicitly permits the requested action', () => {
    for (const tab of accountPermissionsTabs) {
      for (const [action, requiredCapability] of Object.entries(expected[tab]) as [AdminMutation, string | null][]) {
        const authorizedCapabilities = requiredCapability === null ? [] : [requiredCapability]

        expect(canMutateAdminResource(tab, action, [])).toBe(requiredCapability === null)
        expect(canMutateAdminResource(tab, action, authorizedCapabilities)).toBe(true)
        expect(canMutateAdminResource(tab, action, authorizedCapabilities, [action])).toBe(true)
        expect(canMutateAdminResource(tab, action, authorizedCapabilities, [])).toBe(false)
        expect(canMutateAdminResource(tab, action, authorizedCapabilities, null)).toBe(false)
        expect(canMutateAdminResource(tab, action, authorizedCapabilities, ['unrelated-action'])).toBe(false)
      }
    }
  })
})

describe('tabAvailableFor', () => {
  it('treats every tab as available for principals with at least one read capability', () => {
    expect(tabAvailableFor('accounts', ['identity.account.read'])).toBe(true)
    expect(tabAvailableFor('roles-permissions', ['authorization.role.read'])).toBe(true)
    expect(tabAvailableFor('role-assignments', ['authorization.assignment.read'])).toBe(true)
    expect(tabAvailableFor('policies-scopes', ['authorization.policy.read'])).toBe(true)
    expect(tabAvailableFor('decision-inspector', ['authorization.decision.read'])).toBe(true)
  })

  it('flags policies and inspector as unavailable when no read or manage capability is held', () => {
    expect(tabAvailableFor('policies-scopes', [])).toBe(false)
    expect(tabAvailableFor('decision-inspector', [])).toBe(false)
  })

  it('falls back to manage-capability when read is missing', () => {
    expect(tabAvailableFor('roles-permissions', ['authorization.role.manage'])).toBe(true)
    expect(tabAvailableFor('policies-scopes', ['authorization.policy.manage'])).toBe(true)
    expect(tabAvailableFor('role-assignments', ['authorization.assignment.manage'])).toBe(true)
  })
})

describe('isAdvancedAccountPermissionsTab', () => {
  it('flags only the inspector and policies-scopes tabs as advanced', () => {
    const advanced = new Set(['policies-scopes', 'decision-inspector'])
    for (const tab of accountPermissionsTabs) {
      expect(isAdvancedAccountPermissionsTab(tab)).toBe(advanced.has(tab))
    }
  })
})
