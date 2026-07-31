// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { RolesPermissionsTab } from './RolesPermissionsTab'
import type { AuthorizationRole } from '../../api/generated/cluster'
import { ApiError } from '../../api'
import { ProblemImmutableSystemRoleType } from '../../api/generated/cluster'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'en',
  useToken: () => 'test-token',
}))

const listRoles = vi.fn()
const listCapabilities = vi.fn()
const getRole = vi.fn()
const createRole = vi.fn()
const updateRole = vi.fn()
const archiveRole = vi.fn()
const cloneRoleFromSystemRole = vi.fn()

vi.mock('../../api/r1', () => ({
  listRoles: (...args: unknown[]) => listRoles(...args),
  listCapabilities: (...args: unknown[]) => listCapabilities(...args),
  getRole: (...args: unknown[]) => getRole(...args),
  createRole: (...args: unknown[]) => createRole(...args),
  updateRole: (...args: unknown[]) => updateRole(...args),
  archiveRole: (...args: unknown[]) => archiveRole(...args),
  cloneRoleFromSystemRole: (...args: unknown[]) => cloneRoleFromSystemRole(...args),
}))

const CAPABILITIES = [
  {
    id: '018f6f7d-0c00-7000-8000-0000000000a1',
    code: 'records.read',
    module_code: 'records',
    action: 'read' as const,
    sensitivity: 'internal' as const,
    group_label: 'Records',
  },
]

const FULL_CAPABILITIES = [
  'authorization.role.read',
  'authorization.role.manage',
  'authorization.capability.read',
]

const systemRole: AuthorizationRole = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  code: 'records.viewer',
  name_en: 'Records viewer',
  name_ar: 'عارض السجلات',
  role_type: 'system' as const,
  is_system_role: true,
  status: 'active' as const,
  lock_version: 1,
  capability_codes: [],
  allowed_actions: ['clone'],
}

const customRole: AuthorizationRole = {
  ...systemRole,
  id: '018f6f7d-0c00-7000-8000-000000000002',
  code: 'records.editor',
  name_en: 'Records editor',
  name_ar: 'محرر السجلات',
  is_system_role: false,
  role_type: 'custom' as const,
  allowed_actions: ['edit', 'archive'],
}

function mount(props: { locale?: 'ar' | 'en'; capabilities?: readonly string[]; roles?: AuthorizationRole[] } = {}): void {
  listRoles.mockResolvedValue(props.roles ?? [systemRole, customRole])
  listCapabilities.mockResolvedValue(CAPABILITIES)
  getRole.mockResolvedValue(systemRole)
  createRole.mockResolvedValue(systemRole)
  updateRole.mockResolvedValue(systemRole)
  archiveRole.mockResolvedValue(systemRole)
  cloneRoleFromSystemRole.mockResolvedValue(customRole)
  render(
    <RolesPermissionsTab
      locale={props.locale ?? 'en'}
      capabilities={props.capabilities ?? FULL_CAPABILITIES}
      allowedActionsByRole={{ [systemRole.id]: systemRole.allowed_actions, [customRole.id]: customRole.allowed_actions }}
    />,
  )
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('RolesPermissionsTab locale-aware role names', () => {
  it('renders the Arabic name_ar on every role row when locale is ar', async () => {
    mount({ locale: 'ar' })
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    expect(await screen.findByText('عارض السجلات')).not.toBeNull()
    expect(await screen.findByText('محرر السجلات')).not.toBeNull()
    expect(screen.queryByText('Records viewer')).toBeNull()
    expect(screen.queryByText('Records editor')).toBeNull()
  })

  it('renders the English name_en on every role row when locale is en', async () => {
    mount({ locale: 'en' })
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    expect(await screen.findByText('Records viewer')).not.toBeNull()
    expect(await screen.findByText('Records editor')).not.toBeNull()
    expect(screen.queryByText('عارض السجلات')).toBeNull()
    expect(screen.queryByText('محرر السجلات')).toBeNull()
  })

  it('falls back to the role code when neither bilingual name is present', async () => {
    const codeOnly: AuthorizationRole = {
      ...systemRole,
      id: '018f6f7d-0c00-7000-8000-000000000003',
      code: 'records.auditor',
      name_en: undefined,
      name_ar: undefined,
      is_system_role: false,
      role_type: 'custom' as const,
      allowed_actions: ['edit'],
    }
    mount({ roles: [codeOnly] })
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    const row = (await screen.findAllByRole('listitem'))[0]
    const strong = row.querySelector('strong')
    expect(strong?.textContent).toBe('records.auditor')
  })

  it('seeds the create draft from the selected role when editing', async () => {
    mount({ locale: 'en' })
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    const editButtons = await screen.findAllByRole('button', { name: /edit/i })
    fireEvent.click(editButtons[0]!)
    const nameInput = (await screen.findAllByLabelText(/role name/i))[0] as HTMLInputElement | undefined
    expect(nameInput?.value).toBeTruthy()
  })
})

describe('RolesPermissionsTab form invariants', () => {
  it('shows capability count summary above the form', async () => {
    mount()
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    expect(screen.getByText(/1 capability/i)).not.toBeNull()
  })

  it('does not render any capability UUIDs in the row copy', async () => {
    mount()
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    const list = await screen.findByRole('list')
    expect(within(list).queryByText(/[0-9a-f]{8}-/i)).toBeNull()
  })
})

describe('RolesPermissionsTab edit-role 409 recovery', () => {
  it('keeps the editor open and preserves the draft when a generic 409 is returned', async () => {
    mount()
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    const editButtons = await screen.findAllByRole('button', { name: /edit/i })
    fireEvent.click(editButtons[0]!)

    const nameInput = (await screen.findAllByLabelText(/role name/i))[0] as HTMLInputElement
    fireEvent.change(nameInput, { target: { value: 'Records editor v2' } })
    updateRole.mockRejectedValueOnce(
      new ApiError(409, {
        type: 'urn:cluster:problem:role-archive-in-progress',
        title: 'Role is being archived',
        status: 409,
        detail: 'Another operator is archiving this role.',
      }),
    )

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(updateRole).toHaveBeenCalled())
    // Draft is preserved: the name input still shows the user's edit and the role row's
    // "Edit role" button has been replaced by a "Save changes" submit button (editor still open).
    const stillEditing = (await screen.findAllByLabelText(/role name/i))[0] as HTMLInputElement
    expect(stillEditing.value).toBe('Records editor v2')
    expect(screen.getByRole('button', { name: /save changes/i })).not.toBeNull()
    // Recoverable feedback: the error message is announced in the inline alert.
    expect(screen.getByRole('alert').textContent).toMatch(/archiving this role/i)
  })

  it('closes the editor and refocuses the list when the 409 carries the system-role-immutable URN', async () => {
    mount()
    await waitFor(() => expect(listRoles).toHaveBeenCalled())
    const editButtons = await screen.findAllByRole('button', { name: /edit/i })
    fireEvent.click(editButtons[0]!)

    updateRole.mockRejectedValueOnce(
      new ApiError(409, {
        type: ProblemImmutableSystemRoleType['urn:cluster:problem:system-role-immutable'],
        title: 'System role is immutable',
        status: 409,
        detail: 'System roles cannot be modified.',
      }),
    )

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(updateRole).toHaveBeenCalled())
    // Editor closed: "Save changes" is gone, the "Edit role" button is back.
    await waitFor(() => expect(screen.queryByRole('button', { name: /save changes/i })).toBeNull())
    expect(screen.getAllByRole('button', { name: /edit role/i }).length).toBeGreaterThan(0)
    // List received focus (tabIndex={-1} on the <ul>).
    const list = await screen.findByRole('list')
    expect(document.activeElement).toBe(list)
  })
})