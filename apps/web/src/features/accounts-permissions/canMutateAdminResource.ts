/**
 * Capability ↔ tab ↔ mutation matrix used by the accounts & permissions workspace.
 *
 * The five tabs (`accounts`, `roles-permissions`, `role-assignments`,
 * `policies-scopes`, `decision-inspector`) collapse every governance surface
 * onto one screen. Each mutation verb (`create`, `edit`, `clone`, …) is
 * gated on a single capability code and intersected with the per-resource
 * `allowed_actions` projection the published admin endpoints return. A
 * missing requirement or an empty projection denies the action; passing
 * either gate approves it.
 *
 * Tab availability is intentionally broader than mutation availability:
 * a principal who can read must always be able to land on the tab and see
 * the unavailable state, never an error. Mutation control handlers should
 * still funnel through this matrix before issuing a request.
 */

export type AccountPermissionsTabKey =
  | 'accounts'
  | 'roles-permissions'
  | 'role-assignments'
  | 'policies-scopes'
  | 'decision-inspector'

export const accountPermissionsTabs: readonly AccountPermissionsTabKey[] = [
  'accounts',
  'roles-permissions',
  'role-assignments',
  'policies-scopes',
  'decision-inspector',
] as const

export type AdminMutation =
  | 'create'
  | 'edit'
  | 'clone'
  | 'archive'
  | 'revoke'
  | 'expire'
  | 'assign'
  | 'view_assignments'
  | 'grant'
  | 'retract'
  | 'advanced_disclosure'

const REQUIREMENTS: Record<AccountPermissionsTabKey, Record<AdminMutation, string | null>> = {
  accounts: {
    create: 'identity.account.manage',
    edit: null,
    clone: null,
    archive: null,
    revoke: null,
    expire: null,
    assign: 'authorization.assignment.manage',
    view_assignments: 'authorization.assignment.read',
    grant: null,
    retract: null,
    advanced_disclosure: null,
  },
  'roles-permissions': {
    create: 'authorization.role.manage',
    edit: 'authorization.role.manage',
    clone: 'authorization.role.manage',
    archive: 'authorization.role.manage',
    revoke: null,
    expire: null,
    assign: null,
    view_assignments: 'authorization.role.manage',
    grant: 'authorization.role.manage',
    retract: 'authorization.role.manage',
    advanced_disclosure: 'authorization.capability.read',
  },
  'role-assignments': {
    create: 'authorization.assignment.manage',
    edit: 'authorization.assignment.manage',
    clone: null,
    archive: null,
    revoke: 'authorization.assignment.manage',
    expire: 'authorization.assignment.manage',
    assign: null,
    view_assignments: 'authorization.assignment.read',
    grant: null,
    retract: null,
    advanced_disclosure: null,
  },
  'policies-scopes': {
    create: 'authorization.policy.manage',
    edit: 'authorization.policy.manage',
    clone: null,
    archive: 'authorization.policy.manage',
    revoke: null,
    expire: null,
    assign: null,
    view_assignments: 'authorization.policy.read',
    grant: null,
    retract: null,
    advanced_disclosure: null,
  },
  'decision-inspector': {
    create: 'authorization.decision.read',
    edit: null,
    clone: null,
    archive: null,
    revoke: null,
    expire: null,
    assign: null,
    view_assignments: null,
    grant: null,
    retract: null,
    advanced_disclosure: null,
  },
}

export function canMutateAdminResource(
  tab: AccountPermissionsTabKey,
  action: AdminMutation,
  capabilities: readonly string[],
  allowedActions?: readonly string[] | null,
): boolean {
  const required = REQUIREMENTS[tab][action]
  if (required !== null && !capabilities.includes(required)) return false
  // An omitted list predates per-row authorization. A present list is authoritative,
  // including an explicit empty list, so mutation controls fail closed.
  if (allowedActions !== undefined && (!allowedActions || !allowedActions.includes(action))) return false
  return true
}

export function tabAvailableFor(
  tab: AccountPermissionsTabKey,
  capabilities: readonly string[],
): boolean {
  if (tab === 'accounts') {
    return capabilities.includes('identity.account.read') || capabilities.includes('authorization.assignment.read')
  }
  if (tab === 'roles-permissions') {
    return capabilities.includes('authorization.role.read') || capabilities.includes('authorization.role.manage')
  }
  if (tab === 'role-assignments') {
    return capabilities.includes('authorization.assignment.read') || capabilities.includes('authorization.assignment.manage')
  }
  if (tab === 'policies-scopes') {
    return capabilities.includes('authorization.policy.read') || capabilities.includes('authorization.policy.manage')
  }
  if (tab === 'decision-inspector') {
    return capabilities.includes('authorization.decision.read')
  }
  return false
}

const ADVANCED_TABS: Record<AccountPermissionsTabKey, boolean> = {
  accounts: false,
  'roles-permissions': false,
  'role-assignments': false,
  'policies-scopes': true,
  'decision-inspector': true,
}

export function isAdvancedAccountPermissionsTab(tab: AccountPermissionsTabKey): boolean {
  return ADVANCED_TABS[tab] === true
}
