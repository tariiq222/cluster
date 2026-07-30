// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createRef } from 'react'

import { ApiError } from '../../api'
import { AuthorizationMutationFeedbackProvider } from './AuthorizationMutationFeedback'
import type { AnnouncementRegionHandle } from './AnnouncementRegion'
import { RolesPermissionsTab } from './RolesPermissionsTab'

vi.mock('../../app/session-context', () => ({ useToken: () => 'test-token' }))

const listRoles = vi.fn()
const listCapabilities = vi.fn()
const createRole = vi.fn()
const updateRole = vi.fn()
const archiveRole = vi.fn()
const cloneRoleFromSystemRole = vi.fn()
const getRole = vi.fn()

vi.mock('../../api/r1', () => ({
  listRoles: (...args: unknown[]) => listRoles(...args),
  listCapabilities: (...args: unknown[]) => listCapabilities(...args),
  createRole: (...args: unknown[]) => createRole(...args),
  updateRole: (...args: unknown[]) => updateRole(...args),
  archiveRole: (...args: unknown[]) => archiveRole(...args),
  cloneRoleFromSystemRole: (...args: unknown[]) => cloneRoleFromSystemRole(...args),
  getRole: (...args: unknown[]) => getRole(...args),
}))

const customRole = {
  id: '018f6f7d-0c00-7000-8000-000000000001',
  code: 'records.viewer',
  name_en: 'Records viewer',
  role_type: 'custom' as const,
  is_system_role: false,
  status: 'active' as const,
  lock_version: 1,
  allowed_actions: ['edit'],
}

const systemRole = {
  ...customRole,
  id: '018f6f7d-0c00-7000-8000-000000000002',
  code: 'records.system',
  name_en: 'Records system',
  role_type: 'system' as const,
  is_system_role: true,
  allowed_actions: ['clone'],
}

function apiError(
  status: number,
  detail: string,
  problemType: string = 'about:blank',
): ApiError {
  return new ApiError(status, { type: problemType, title: 'Request failed', status, detail })
}

function mount(locale: 'ar' | 'en', roles = [customRole]) {
  listRoles.mockResolvedValue(roles)
  listCapabilities.mockResolvedValue([])
  createRole.mockResolvedValue(customRole)
  updateRole.mockResolvedValue(customRole)
  archiveRole.mockResolvedValue(customRole)
  cloneRoleFromSystemRole.mockResolvedValue(customRole)
  getRole.mockResolvedValue({ ...customRole, lock_version: 2 })
  const regionRef = createRef<AnnouncementRegionHandle>()

  render(
    <AuthorizationMutationFeedbackProvider locale={locale} regionRef={regionRef}>
      <RolesPermissionsTab
        locale={locale}
        capabilities={['authorization.role.manage']}
      />
    </AuthorizationMutationFeedbackProvider>,
  )
}

async function waitForRole(name: string) {
  await screen.findByText(name)
}

async function startEditing() {
  await waitForRole('Records viewer')
  fireEvent.click(screen.getByRole('button', { name: 'Edit role' }))
  fireEvent.change(screen.getByLabelText('Role name'), { target: { value: 'Updated role' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('Authorization mutation feedback', () => {
  it('focuses the live region after a 422 validation error', async () => {
    createRole.mockRejectedValueOnce(apiError(422, 'Role code is required.'))
    mount('en')
    await waitForRole('Records viewer')

    fireEvent.change(screen.getByLabelText('Role code'), { target: { value: 'records.editor' } })
    fireEvent.change(screen.getByLabelText('Role name'), { target: { value: 'Records editor' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create role' }))

    const announcement = await screen.findByRole('status')
    await waitFor(() => expect(announcement.textContent).toContain('Role code is required.'))
  })

  it('closes an immutable role editor and moves focus back to the role list after a 409', async () => {
    updateRole.mockRejectedValueOnce(apiError(409, 'System roles cannot be edited.', 'urn:cluster:problem:system-role-immutable'))
    mount('en')
    await startEditing()

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull())
    expect(document.activeElement).toBe(screen.getByRole('list'))
    expect(screen.getByRole('status').textContent).toContain('System roles cannot be edited.')
  })

  it('retries a stale role save with the refreshed lock version after reloading canonical data', async () => {
    updateRole.mockRejectedValueOnce(apiError(412, 'This role changed elsewhere.'))
    mount('en')
    await startEditing()

    fireEvent.click(await screen.findByRole('button', { name: 'Reload' }))
    await waitFor(() => expect(getRole).toHaveBeenCalledWith('test-token', customRole.id))

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(updateRole.mock.calls).toHaveLength(2))
    expect(updateRole.mock.calls[1]).toEqual([
      'test-token',
      customRole.id,
      { name: 'Updated role', capability_codes: [] },
      2,
    ])
  })

  it('announces a missing clone If-Match header error and focuses the live region', async () => {
    cloneRoleFromSystemRole.mockRejectedValueOnce(apiError(400, 'If-Match header is required.'))
    mount('en', [systemRole])
    await waitForRole('Records system')

    fireEvent.click(screen.getByRole('button', { name: 'Clone as custom role' }))

    const announcement = await screen.findByRole('status')
    await waitFor(() => expect(announcement.textContent).toContain('If-Match header is required.'))
    expect(document.activeElement).toBe(announcement)
  })

  it('announces Arabic validation feedback in an RTL live region', async () => {
    createRole.mockRejectedValueOnce(apiError(422, 'رمز الدور مطلوب.'))
    mount('ar')
    await screen.findByText('Records viewer')

    fireEvent.change(screen.getByLabelText('رمز الدور'), { target: { value: 'records.editor' } })
    fireEvent.change(screen.getByLabelText('اسم الدور'), { target: { value: 'محرر السجلات' } })
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء دور' }))

    const announcement = await screen.findByRole('status')
    await waitFor(() => expect(announcement.textContent).toContain('رمز الدور مطلوب.'))
    expect(announcement.getAttribute('dir')).toBe('rtl')
    expect(document.activeElement).toBe(announcement)
  })
})
