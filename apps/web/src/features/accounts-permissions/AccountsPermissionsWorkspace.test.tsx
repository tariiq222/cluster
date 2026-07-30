// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { AccountsPermissionsWorkspace } from './AccountsPermissionsWorkspace'
import type { AccountPermissionsTabKey } from './canMutateAdminResource'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'en',
  useToken: () => 'test-token',
}))

const listRoles = vi.fn()
const listRoleAssignments = vi.fn()
const listCapabilities = vi.fn()
const simulateAccessDecision = vi.fn()
const explainAccessDecision = vi.fn()
const createRole = vi.fn()
const updateRole = vi.fn()
const archiveRole = vi.fn()
const cloneRoleFromSystemRole = vi.fn()
const updateRoleAssignment = vi.fn()
const createRoleAssignment = vi.fn()

vi.mock('../../api/r1', () => ({
  listRoles: (...args: unknown[]) => listRoles(...args),
  listRoleAssignments: (...args: unknown[]) => listRoleAssignments(...args),
  listCapabilities: (...args: unknown[]) => listCapabilities(...args),
  simulateAccessDecision: (...args: unknown[]) => simulateAccessDecision(...args),
  explainAccessDecision: (...args: unknown[]) => explainAccessDecision(...args),
  createRole: (...args: unknown[]) => createRole(...args),
  updateRole: (...args: unknown[]) => updateRole(...args),
  archiveRole: (...args: unknown[]) => archiveRole(...args),
  cloneRoleFromSystemRole: (...args: unknown[]) => cloneRoleFromSystemRole(...args),
  updateRoleAssignment: (...args: unknown[]) => updateRoleAssignment(...args),
  createRoleAssignment: (...args: unknown[]) => createRoleAssignment(...args),
}))

const listUserAccounts = vi.fn()
vi.mock('../../api/identity', () => ({ listUserAccounts: (...args: unknown[]) => listUserAccounts(...args) }))

vi.mock('../identity/IdentityAccounts', () => ({
  IdentityAccounts: () => <div data-testid="identity-accounts-stub">accounts list</div>,
}))

const FULL_CAPABILITIES = [
  'identity.account.read',
  'authorization.role.read',
  'authorization.role.manage',
  'authorization.assignment.read',
  'authorization.assignment.manage',
  'authorization.policy.read',
  'authorization.policy.manage',
  'authorization.capability.read',
  'authorization.capability.manage',
  'authorization.decision.read',
]

const readOnlyCapabilities = [
  'identity.account.read',
  'authorization.role.read',
  'authorization.assignment.read',
  'authorization.policy.read',
  'authorization.capability.read',
]

const role = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  code: 'records.viewer',
  role_type: 'system' as const,
  is_system_role: true,
  status: 'active' as const,
  lock_version: 1,
  allowed_actions: ['clone'],
}

const assignment = {
  id: '018f6f7d-0c00-7000-8000-000000000002',
  role_id: '018f6f7d-0c00-7000-8000-000000000001',
  subject_user_id: '018f6f7d-0c00-7000-8000-000000000003',
  effective_status: 'active' as const,
  allowed_actions: ['revoke', 'expire', 'edit'],
  lock_version: 1,
}

const capability = {
  code: 'records.read',
  module_code: 'records',
  action: 'read' as const,
  sensitivity: 'internal' as const,
  group_label: 'Records',
}

function mount(props: {
  capabilities: readonly string[]
  initialTab?: AccountPermissionsTabKey
  navigate?: (path: string) => void
  locale?: 'ar' | 'en'
}): (path: string) => void {
  listRoles.mockResolvedValue([role])
  listRoleAssignments.mockResolvedValue([assignment])
  listCapabilities.mockResolvedValue([capability])
  listUserAccounts.mockResolvedValue({ items: [{ id: assignment.subject_user_id, username: 'finance', display_name_ar: 'مسؤول المالية', display_name_en: 'Finance officer' }] })
  simulateAccessDecision.mockResolvedValue({
    decision_id: '018f6f7d-0c00-7000-8000-00000000000a',
    decision: 'allow' as const,
    action: 'records.read',
    resource_type: 'records',
    reason_codes: ['records.read.granted'],
    policy_version: 'p-1',
    facts_version: 'f-1',
    authorization_trace_id: '018f6f7d-0c00-7000-8000-00000000000b',
    evaluated_at: '2026-07-29T00:00:00Z',
    correlation_id: '018f6f7d-0c00-7000-8000-00000000000c',
    classification: 'internal' as const,
    access_context: { actor_id: 'a', scope_id: 's', session_id: 'se' },
    applies_in_plain_language: 'A finance officer may view records in scope North.',
    assignment_summaries: [{ assignment_id: assignment.id, scope_label: 'North facility' }],
    policy_references: [{ policy_id: 'records.viewer', policy_label: 'Records viewer policy' }],
  })
  explainAccessDecision.mockResolvedValue({})
  createRole.mockResolvedValue(role)
  updateRole.mockResolvedValue(role)
  archiveRole.mockResolvedValue(role)
  cloneRoleFromSystemRole.mockResolvedValue(role)
  updateRoleAssignment.mockResolvedValue(assignment)
  createRoleAssignment.mockResolvedValue(assignment)

  const navigate = props.navigate ?? vi.fn()
  const initialTab: AccountPermissionsTabKey = props.initialTab ?? 'accounts'
  render(
    <AccountsPermissionsWorkspace
      locale={props.locale ?? 'en'}
      activeTab={initialTab}
      capabilities={props.capabilities}
      allowedActionsByRole={{ [role.id]: role.allowed_actions }}
      navigate={navigate}
    />,
  )
  return navigate
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('AccountsPermissionsWorkspace tab order', () => {
  it('renders exactly five tabs in the canonical order with the advanced eyebrow when advanced is active', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'policies-scopes' })
    const nav = await screen.findByRole('tablist')
    const tabs = within(nav).getAllByRole('tab')
    expect(tabs.map((tab) => tab.textContent)).toEqual([
      'Accounts',
      'Roles & Permissions',
      'Role Assignments',
      'Policies & Scopes',
      'Permission Decision Inspector',
    ])
    expect(screen.queryByText(/Advanced/)).not.toBeNull()
  })

  it('reads accounts query state via the IdentityAccounts stub', async () => {
    mount({ capabilities: FULL_CAPABILITIES })
    await screen.findByTestId('identity-accounts-stub')
  })
})

describe('AccountsPermissionsWorkspace capability gating', () => {
  it('renders the localized unavailable state for the policies-and-scopes tab without that capability', async () => {
    mount({ capabilities: [], initialTab: 'policies-scopes' })
    await screen.findByText(/policies and scopes/)
    expect(screen.queryByText(/AccessScopesScreen/i)).toBeNull()
    expect(screen.queryByText(/[0-9a-f]{8}-/i)).toBeNull()
  })

  it('renders the localized unavailable state for the decision inspector tab without that capability', async () => {
    mount({ capabilities: readOnlyCapabilities, initialTab: 'decision-inspector' })
    await screen.findByText(/advanced tool is not available/)
    expect(screen.queryByText(/[0-9a-f]{8}-/i)).toBeNull()
  })

  it('renders the roles catalog without raw UUIDs by default', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'roles-permissions' })
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    expect(screen.queryByText(/[0-9a-f]{8}-/i)).toBeNull()
    expect(screen.queryByText(/\{/)).toBeNull()
  })
})

describe('AccountsPermissionsWorkspace RTL and keyboard navigation', () => {
  it('sets the direction attribute to ltr on the local section when locale is en', async () => {
    mount({ capabilities: FULL_CAPABILITIES })
    const nav = await screen.findByRole('tablist')
    expect(nav.closest('section')?.getAttribute('dir')).toBe('ltr')
  })

  it('invokes navigation on ArrowRight with the next canonical tab path', async () => {
    const navigate = mount({ capabilities: FULL_CAPABILITIES, initialTab: 'accounts' })
    const nav = await screen.findByRole('tablist')
    const tabs = within(nav).getAllByRole('tab')
    const initialActive = tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true')
    expect(initialActive).toBe(0)
    act(() => { tabs[initialActive]?.focus() })
    fireEvent.keyDown(nav, { key: 'ArrowRight' })
    await waitFor(() => expect(navigate).toHaveBeenCalled())
    const lastCall = navigate.mock.calls[navigate.mock.calls.length - 1]?.[0] as string
    expect(lastCall).toContain('/admin/authorization/roles')
    expect(lastCall).toContain('tab=roles-permissions')
  })
})

describe('AccountsPermissionsWorkspace action denial', () => {
  it('fails closed when the server explicitly supplies no allowed actions', async () => {
    const { canMutateAdminResource } = await import('./canMutateAdminResource')
    expect(canMutateAdminResource('roles-permissions', 'edit', FULL_CAPABILITIES, [])).toBe(false)
  })
})

describe('AccountsPermissionsWorkspace inspector reuse', () => {
  it('submits an exact access decision request without a cast or partial context', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'decision-inspector' })
    const fields: Record<string, string> = {
      Action: 'records.read', 'Account ID': '018f6f7d-0c00-7000-8000-000000000001', 'Tenant ID': '018f6f7d-0c00-7000-8000-000000000002',
      'Correlation ID': '018f6f7d-0c00-7000-8000-000000000003', 'Facts version': 'v1', 'Source module': 'records', 'Record type': 'record',
      'Record ID': '018f6f7d-0c00-7000-8000-000000000004', 'Cluster ID': '018f6f7d-0c00-7000-8000-000000000005', 'Lifecycle state': 'active', 'Field policy key': 'records.default',
    }
    for (const [label, value] of Object.entries(fields)) fireEvent.change(await screen.findByLabelText(label), { target: { value } })
    await act(async () => { fireEvent.click(await screen.findByRole('button', { name: /simulate/i })) })
    await waitFor(() => expect(simulateAccessDecision).toHaveBeenCalled())
    expect(simulateAccessDecision).toHaveBeenCalledWith(expect.objectContaining({
      action: 'records.read',
      access_context: expect.objectContaining({ subject_id: fields['Account ID'], tenant_id: fields['Tenant ID'], clearance: 'internal', correlation_id: fields['Correlation ID'] }),
      record_facts: expect.objectContaining({ facts_version: 'v1', source_module: 'records', record_type: 'record', record_id: fields['Record ID'], cluster_id: fields['Cluster ID'], classification: 'internal', lifecycle_state: 'active', field_policy_key: 'records.default', lock_version: 1 }),
    }), 'test-token')
    expect((await screen.findByRole('region', { name: /decision result/i })).textContent).toContain('A finance officer may view records in scope North.')
    expect(screen.queryByText(/[0-9a-f]{8}-/i)).toBeNull()
  })
})

describe('AccountsPermissionsWorkspace role mutations', () => {
  it('creates a role with its selected capability set', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'roles-permissions' })
    fireEvent.change(await screen.findByLabelText('Role code'), { target: { value: 'records.editor' } })
    fireEvent.change(await screen.findByLabelText('Role name'), { target: { value: 'Records editor' } })
    fireEvent.click(await screen.findByLabelText('records.read'))
    fireEvent.click(await screen.findByRole('button', { name: 'Create role' }))
    await waitFor(() => expect(createRole).toHaveBeenCalledWith('test-token', expect.objectContaining({ resource_type: 'role', code: 'records.editor', name: 'Records editor', capability_codes: ['records.read'] })))
  })
})

describe('AccountsPermissionsWorkspace assignment editing', () => {
  it('keeps the create end date isolated from row update controls', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'role-assignments' })
    fireEvent.change(await screen.findByLabelText('End at'), { target: { value: '2026-12-01T12:00' } })
    expect(screen.queryByRole('button', { name: 'Save assignment' })).not.toBeNull()
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull()
    expect(updateRoleAssignment).not.toHaveBeenCalled()
  })

  it('updates only the selected assignment with its lock version and end date', async () => {
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'role-assignments' })
    fireEvent.click(await screen.findByRole('button', { name: 'Edit end date' }))
    const editor = (await screen.findAllByLabelText('End at'))[1]
    fireEvent.change(editor!, { target: { value: '2026-12-02T10:30' } })
    fireEvent.click((await screen.findAllByRole('button', { name: 'Save assignment' }))[1]!)
    await waitFor(() => expect(updateRoleAssignment).toHaveBeenCalledWith('test-token', assignment.id, { end_at: new Date('2026-12-02T10:30').toISOString() }, 1))
  })
})

describe('AccountsPermissionsWorkspace Arabic assignment labels', () => {
  it('uses Arabic scope, role, and account labels instead of English fallbacks', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'role-assignments', locale: 'ar' })
    expect(await screen.findByText('التجمع')).not.toBeNull()
    expect(await screen.findByText('مسؤول المالية')).not.toBeNull()
    expect((await screen.findAllByText('الدور')).length).toBeGreaterThan(1)
    expect(screen.queryByText('Finance officer')).toBeNull()
  })
})

describe('AccountsPermissionsWorkspace assignment creation guard', () => {
  it('requires selected account and role, then creates only a cluster-scoped assignment', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}
    mount({ capabilities: FULL_CAPABILITIES, initialTab: 'role-assignments' })
    const create = await screen.findByRole('button', { name: 'Save assignment' })
    expect(create.getAttribute('disabled')).not.toBeNull()
    expect(screen.queryByRole('textbox', { name: 'Scope target' })).toBeNull()
    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))
    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'records.viewer' }))
    expect(create.getAttribute('disabled')).toBeNull()
    fireEvent.click(create)
    await waitFor(() => expect(createRoleAssignment).toHaveBeenCalledWith('test-token', expect.objectContaining({ subject_user_id: assignment.subject_user_id, role_id: role.id, scope_type: 'cluster' })))
    expect(createRoleAssignment.mock.calls[0]?.[1]).not.toHaveProperty('scope_id')
  })
})
