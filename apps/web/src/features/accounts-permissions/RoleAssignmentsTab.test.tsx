// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { RoleAssignmentsTab } from './RoleAssignmentsTab'

vi.mock('../../app/session-context', () => ({
  useToken: () => 'test-token',
}))

const listRoleAssignments = vi.fn()
const listRoles = vi.fn()
const createRoleAssignment = vi.fn()
const expireRoleAssignment = vi.fn()
const revokeRoleAssignment = vi.fn()
const updateRoleAssignment = vi.fn()

vi.mock('../../api/r1', () => ({
  listRoleAssignments: (...args: unknown[]) => listRoleAssignments(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  createRoleAssignment: (...args: unknown[]) => createRoleAssignment(...args),
  expireRoleAssignment: (...args: unknown[]) => expireRoleAssignment(...args),
  revokeRoleAssignment: (...args: unknown[]) => revokeRoleAssignment(...args),
  updateRoleAssignment: (...args: unknown[]) => updateRoleAssignment(...args),
}))

const listUserAccounts = vi.fn()
vi.mock('../../api/identity', () => ({
  listUserAccounts: (...args: unknown[]) => listUserAccounts(...args),
}))

const FULL_CAPABILITIES = [
  'authorization.assignment.read',
  'authorization.assignment.manage',
]

const assignment = {
  id: '018f6f7d-0c00-7000-8000-000000000002',
  role_id: '018f6f7d-0c00-7000-8000-000000000010',
  subject_user_id: '018f6f7d-0c00-7000-8000-000000000020',
  effective_status: 'active' as const,
  allowed_actions: ['revoke', 'expire', 'edit'],
  lock_version: 1,
}

const systemRole = {
  id: '018f6f7d-0c00-7000-8000-000000000010',
  code: 'records.viewer',
  name_en: 'Records viewer',
  name_ar: 'عارض السجلات',
  role_type: 'system' as const,
  is_system_role: true,
  status: 'active' as const,
  lock_version: 1,
  allowed_actions: ['clone'],
}

const customRole = {
  id: '018f6f7d-0c00-7000-8000-000000000011',
  code: 'records.editor',
  name_en: 'Records editor',
  name_ar: 'محرر السجلات',
  role_type: 'custom' as const,
  is_system_role: false,
  status: 'active' as const,
  lock_version: 1,
  allowed_actions: ['edit', 'archive'],
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('RoleAssignmentsTab create submit', () => {
  it('submits the generated resource_type discriminator (role_assignment, not the legacy role-assignment literal)', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([systemRole, customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({
      items: [
        {
          id: assignment.subject_user_id,
          username: 'finance',
          display_name_en: 'Finance officer',
          display_name_ar: 'مسؤول المالية',
        },
      ],
    })

    render(
      <RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />,
    )

    // Save stays disabled until both selects have values.
    const save = await screen.findByRole('button', { name: 'Save assignment' })
    expect(save.getAttribute('disabled')).not.toBeNull()

    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))

    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))

    expect(save.getAttribute('disabled')).toBeNull()

    fireEvent.click(save)

    await waitFor(() => expect(createRoleAssignment).toHaveBeenCalledTimes(1))
    const payload = createRoleAssignment.mock.calls[0]?.[1] as Record<string, unknown>
    // Generated AuthorizationAdminCreateResourceType uses the underscore form.
    // The previous implementation sent the hyphenated literal "role-assignment",
    // which is not in the enum and is rejected by the server contract.
    expect(payload).toMatchObject({
      resource_type: 'role_assignment',
      subject_user_id: assignment.subject_user_id,
      role_id: customRole.id,
      scope_type: 'cluster',
    })
  })
})