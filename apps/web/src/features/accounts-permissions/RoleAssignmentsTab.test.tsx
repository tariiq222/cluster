// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { RoleAssignmentsTab } from './RoleAssignmentsTab'
import { ApiError } from '../../api'

vi.mock('../../app/session-context', () => ({
  useToken: () => 'test-token',
}))

const listRoleAssignments = vi.fn()
const listRoles = vi.fn()
const createRoleAssignment = vi.fn()
const expireRoleAssignment = vi.fn()
const revokeRoleAssignment = vi.fn()
const updateRoleAssignment = vi.fn()
const listAssignmentScopeTargets = vi.fn()

vi.mock('../../api/r1', () => ({
  listRoleAssignments: (...args: unknown[]) => listRoleAssignments(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  createRoleAssignment: (...args: unknown[]) => createRoleAssignment(...args),
  expireRoleAssignment: (...args: unknown[]) => expireRoleAssignment(...args),
  revokeRoleAssignment: (...args: unknown[]) => revokeRoleAssignment(...args),
  updateRoleAssignment: (...args: unknown[]) => updateRoleAssignment(...args),
  listAssignmentScopeTargets: (...args: unknown[]) => listAssignmentScopeTargets(...args),
}))

const listUserAccounts = vi.fn()
vi.mock('../../api/identity', () => ({
  listUserAccounts: (...args: unknown[]) => listUserAccounts(...args),
}))

const FULL_CAPABILITIES = [
  'authorization.assignment.read',
  'authorization.assignment.manage',
]

const ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000002'
const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000010'
const ACCOUNT_ID = '018f6f7d-0c00-7000-8000-000000000020'
const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000100'
const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000101'
const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000102'

const assignment = {
  id: ASSIGNMENT_ID,
  role_id: ROLE_ID,
  subject_user_id: ACCOUNT_ID,
  scope_type: 'cluster' as const,
  scope_id: CLUSTER_ID,
  effective_status: 'active' as const,
  allowed_actions: ['revoke', 'expire', 'edit'],
  lock_version: 1,
  end_at: '2027-01-01T00:00:00Z',
}

const systemRole = {
  id: ROLE_ID,
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

const clusterTarget = {
  scope_type: 'cluster' as const,
  scope_id: CLUSTER_ID,
  label_ar: 'تجمع الرياض',
  label_en: 'Riyadh cluster',
  code: 'RUH',
}

const facilityTarget = {
  scope_type: 'facility' as const,
  scope_id: FACILITY_ID,
  label_ar: 'مستشفى الملك فيصل',
  label_en: 'King Faisal Hospital',
  code: 'KFH',
}

const unitTarget = {
  scope_type: 'unit' as const,
  scope_id: UNIT_ID,
  label_ar: 'قسم الطوارئ',
  label_en: 'Emergency department',
  code: 'ED',
}

const secondFacilityTarget = {
  scope_type: 'facility' as const,
  scope_id: '018f6f7d-0c00-7000-8000-000000000103',
  label_ar: 'مستشفى الملك عبدالعزيز',
  label_en: 'King Abdulaziz Hospital',
  code: 'KAH',
}

const unitUnderFirstFacility = {
  scope_type: 'unit' as const,
  scope_id: '018f6f7d-0c00-7000-8000-000000000102',
  label_ar: 'قسم الطوارئ',
  label_en: 'Emergency department',
  code: 'ED',
}

const unitUnderSecondFacility = {
  scope_type: 'unit' as const,
  scope_id: '018f6f7d-0c00-7000-8000-000000000104',
  label_ar: 'قسم الجراحة',
  label_en: 'Surgery department',
  code: 'SUR',
}

function setupHappyScopeTargets() {
  listAssignmentScopeTargets.mockImplementation(async (_token: string, query: { scopeType: string; parentScopeId?: string }) => {
    if (query.scopeType === 'cluster') return { items: [clusterTarget], next_cursor: null }
    if (query.scopeType === 'facility') return { items: [facilityTarget], next_cursor: null }
    if (query.scopeType === 'unit' && query.parentScopeId) return { items: [unitTarget], next_cursor: null }
    if (query.scopeType === 'unit' && !query.parentScopeId) return { items: [facilityTarget], next_cursor: null }
    return { items: [], next_cursor: null }
  })
}

function setupTwoFacilityUnitScopeTargets() {
  listAssignmentScopeTargets.mockImplementation(async (_token: string, query: { scopeType: string; parentScopeId?: string }) => {
    if (query.scopeType === 'cluster') return { items: [clusterTarget], next_cursor: null }
    if (query.scopeType === 'facility') return { items: [facilityTarget, secondFacilityTarget], next_cursor: null }
    if (query.scopeType === 'unit' && query.parentScopeId === FACILITY_ID) return { items: [unitUnderFirstFacility], next_cursor: null }
    if (query.scopeType === 'unit' && query.parentScopeId === secondFacilityTarget.scope_id) return { items: [unitUnderSecondFacility], next_cursor: null }
    if (query.scopeType === 'unit' && !query.parentScopeId) return { items: [facilityTarget, secondFacilityTarget], next_cursor: null }
    return { items: [], next_cursor: null }
  })
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('RoleAssignmentsTab create submit', () => {
  it('submits the generated resource_type discriminator (role_assignment) and forwards the picked catalog target id', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([systemRole, customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({
      items: [
        { id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' },
      ],
    })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    const save = await screen.findByRole('button', { name: 'Save assignment' })
    expect(save.getAttribute('disabled')).not.toBeNull()

    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))

    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))

    // Cluster is the default; expand the picker and pick a target.
    fireEvent.click(await screen.findByLabelText('Scope target'))
    await screen.findByRole('option', { name: 'Riyadh cluster' })
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))

    expect(save.getAttribute('disabled')).toBeNull()

    fireEvent.click(save)

    await waitFor(() => expect(createRoleAssignment).toHaveBeenCalledTimes(1))
    const payload = createRoleAssignment.mock.calls[0]?.[1] as Record<string, unknown>
    expect(payload).toMatchObject({
      resource_type: 'role_assignment',
      subject_user_id: ACCOUNT_ID,
      role_id: customRole.id,
      scope_type: 'cluster',
      scope_id: clusterTarget.scope_id,
    })
    expect(payload).not.toHaveProperty('reason')
  })

  it('regression: never sends the legacy hyphen-delimited role-assignment discriminator', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))
    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Save assignment' }))
    await waitFor(() => expect(createRoleAssignment).toHaveBeenCalledTimes(1))
    const payload = createRoleAssignment.mock.calls[0]?.[1] as Record<string, unknown>
    expect(payload.resource_type).toBe('role_assignment')
    expect(payload.resource_type).not.toBe('role-assignment')
  })
})

describe('RoleAssignmentsTab scope picker', () => {
  it('forwards scope_type and renders bilingual labels from the catalog', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="ar" capabilities={FULL_CAPABILITIES} />)

    // The picker asks the catalog for cluster targets immediately (cluster is the default).
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'cluster' })))

    // Pick a cluster target first so the downstream radios become enabled.
    fireEvent.click(await screen.findByLabelText('هدف النطاق'))
    fireEvent.click(await screen.findByRole('option', { name: 'تجمع الرياض' }))

    // Now switch to facility.
    fireEvent.click(await screen.findByRole('radio', { name: 'المنشأة' }))
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'facility' })))
  })

  it('switches scope type and renders the matching target list', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'cluster' })))
    // Pick cluster first, then facility.
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))

    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenLastCalledWith('test-token', expect.objectContaining({ scopeType: 'facility' })))

    fireEvent.click(await screen.findByLabelText('Scope target'))
    expect(await screen.findByRole('option', { name: 'King Faisal Hospital' })).toBeTruthy()
  })

  it('clears a previously picked target when the scope level changes', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))
    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))

    // Pick a cluster target.
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))

    // Switching the level clears the picked target so the submit cannot leak a stale id.
    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    const save = await screen.findByRole('button', { name: 'Save assignment' })
    await waitFor(() => expect(save.getAttribute('disabled')).not.toBeNull())
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenLastCalledWith('test-token', expect.objectContaining({ scopeType: 'facility' })))
  })

  it('shows a parent facility picker for scope_type=unit and forwards parent_scope_id to the catalog', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'cluster' })))
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    const target = await screen.findByLabelText('Scope target')
    fireEvent.click(target)
    fireEvent.click(await screen.findByRole('option', { name: 'King Faisal Hospital' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Unit' }))
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({
      scopeType: 'unit',
      parentScopeType: 'facility',
      parentScopeId: FACILITY_ID,
    })))
  })

  it('resets the picked target id when the parent facility changes', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupTwoFacilityUnitScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'cluster' })))
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    const target = await screen.findByLabelText('Scope target')
    fireEvent.click(target)
    fireEvent.click(await screen.findByRole('option', { name: 'King Faisal Hospital' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Unit' }))

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenLastCalledWith('test-token', expect.objectContaining({
      scopeType: 'unit',
      parentScopeId: FACILITY_ID,
    })))

    const targetTrigger = await screen.findByLabelText('Scope target')
    fireEvent.click(targetTrigger)
    fireEvent.click(await screen.findByRole('option', { name: 'Emergency department' }))

    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenLastCalledWith('test-token', expect.objectContaining({ scopeType: 'facility' })))
    fireEvent.click(target)
    fireEvent.click(await screen.findByRole('option', { name: 'King Abdulaziz Hospital' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Unit' }))

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenLastCalledWith('test-token', expect.objectContaining({
      scopeType: 'unit',
      parentScopeId: secondFacilityTarget.scope_id,
    })))
    const save = await screen.findByRole('button', { name: 'Save assignment' })
    await waitFor(() => expect(save.getAttribute('disabled')).not.toBeNull())
    expect(targetTrigger).toBeTruthy()
  })

  it('stale-response ordering: ignores an out-of-order catalog response so older state cannot overwrite newer state', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })

    const deferreds: Array<{ resolve: (value: { items: typeof clusterTarget[]; next_cursor: null }) => void }> = []
    listAssignmentScopeTargets.mockImplementation(async (_token: string, query: { scopeType: string }) => {
      if (query.scopeType === 'cluster') return { items: [clusterTarget], next_cursor: null }
      if (query.scopeType === 'facility') {
        const deferred = Promise.withResolvers<{ items: typeof clusterTarget[]; next_cursor: null }>()
        deferreds.push({ resolve: deferred.resolve })
        return deferred.promise
      }
      return { items: [], next_cursor: null }
    })

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledWith('test-token', expect.objectContaining({ scopeType: 'cluster' })))
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))
    // Switch to facility (newer request) — the hook holds the request open.
    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))
    // Switch back to cluster. Then switch to facility again.
    fireEvent.click(await screen.findByRole('radio', { name: 'Cluster' }))
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))
    fireEvent.click(await screen.findByRole('radio', { name: 'Facility' }))

    await waitFor(() => expect(deferreds.length).toBeGreaterThanOrEqual(2))

    // Resolve the second (newer) facility first, then the stale one.
    deferreds[1]!.resolve({ items: [facilityTarget, secondFacilityTarget], next_cursor: null })
    fireEvent.click(await screen.findByLabelText('Scope target'))
    await screen.findByRole('option', { name: 'King Faisal Hospital' })
    deferreds[0]!.resolve({ items: [facilityTarget], next_cursor: null })
    await new Promise((resolve) => setTimeout(resolve, 10))
    expect(await screen.findByRole('option', { name: 'King Abdulaziz Hospital' })).toBeTruthy()
  })

  it('disables the save button when the catalog is empty', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    listAssignmentScopeTargets.mockResolvedValue({ items: [], next_cursor: null })

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))
    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))

    const save = await screen.findByRole('button', { name: 'Save assignment' })
    await waitFor(() => expect(save.getAttribute('disabled')).not.toBeNull())
    expect(await screen.findByText('No scope targets available for this level.')).toBeTruthy()
  })

  it('surfaces the picker error with a retry control when the catalog fetch fails', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    createRoleAssignment.mockResolvedValue(assignment)
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    listAssignmentScopeTargets.mockRejectedValueOnce(new Error('network down'))
    setupHappyScopeTargets()

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    const retry = await screen.findByRole('button', { name: 'Reload targets' })
    expect(await screen.findByText('Could not load scope targets.')).toBeTruthy()

    fireEvent.click(retry)
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalledTimes(2))
  })
  it.each([
    [403, 'Forbidden', 'You do not have permission to view scope targets.'],
    [422, 'Unsupported scope type', 'This scope level is not supported.'],
  ])('renders status %i as a distinct non-retryable picker state', async (status, title, expected) => {
    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    listUserAccounts.mockResolvedValue({ items: [] })
    listAssignmentScopeTargets.mockRejectedValue(new ApiError(status, {
      type: status === 422 ? 'urn:cluster:problem:scope_type_not_catalogued' : 'about:blank',
      title,
      status,
    }))

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    expect(await screen.findByText(expected)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Reload targets' })).toBeNull()
  })

  it('localizes non-ApiError catalog failures instead of exposing Error.message', async () => {
    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    listUserAccounts.mockResolvedValue({ items: [] })
    listAssignmentScopeTargets.mockRejectedValue(new Error('private network detail'))

    render(<RoleAssignmentsTab locale="ar" capabilities={FULL_CAPABILITIES} />)

    expect(await screen.findByText('تعذر تحميل أهداف النطاق.')).toBeTruthy()
    expect(screen.queryByText('private network detail')).toBeNull()
  })

})

describe('RoleAssignmentsTab mutation failures', () => {
  function setupMutationFailure(locale: 'ar' | 'en' = 'en') {
    listRoleAssignments.mockResolvedValue([assignment])
    listRoles.mockResolvedValue([customRole])
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    setupHappyScopeTargets()
    render(<RoleAssignmentsTab locale={locale} capabilities={FULL_CAPABILITIES} />)
  }

  it.each([
    ['revoke', revokeRoleAssignment, 'Revoke assignment', 'Could not revoke the role assignment.'],
    ['expire', expireRoleAssignment, 'Expire assignment', 'Could not expire the role assignment.'],
  ])('uses dedicated English %s failure copy for non-ApiError failures', async (_action, mutation, button, expected) => {
    mutation.mockRejectedValue(new Error('private mutation detail'))
    setupMutationFailure()
    fireEvent.click(await screen.findByRole('button', { name: button }))
    expect((await screen.findByRole('alert')).textContent).toContain(expected)
    expect(screen.queryByText('private mutation detail')).toBeNull()
  })

  it('uses dedicated Arabic revoke failure copy', async () => {
    revokeRoleAssignment.mockRejectedValue(new Error('private mutation detail'))
    setupMutationFailure('ar')
    fireEvent.click(await screen.findByRole('button', { name: 'إلغاء الإسناد' }))
    expect((await screen.findByRole('alert')).textContent).toContain('تعذر إلغاء إسناد الدور.')
  })

  it('preserves ApiError server detail for mutation failures', async () => {
    expireRoleAssignment.mockRejectedValue(new ApiError(409, {
      type: 'about:blank', title: 'Conflict', status: 409, detail: 'Assignment already expired.',
    }))
    setupMutationFailure()
    fireEvent.click(await screen.findByRole('button', { name: 'Expire assignment' }))
    expect((await screen.findByRole('alert')).textContent).toContain('Assignment already expired.')
  })
  it('uses dedicated create failure copy', async () => {
    createRoleAssignment.mockRejectedValue(new Error('private mutation detail'))
    setupMutationFailure()
    fireEvent.click(await screen.findByLabelText('Account'))
    fireEvent.click(await screen.findByRole('option', { name: 'Finance officer' }))
    fireEvent.click(await screen.findByLabelText('Role'))
    fireEvent.click(await screen.findByRole('option', { name: 'Records editor' }))
    await waitFor(() => expect(listAssignmentScopeTargets).toHaveBeenCalled())
    fireEvent.click(await screen.findByLabelText('Scope target'))
    fireEvent.click(await screen.findByRole('option', { name: 'Riyadh cluster' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Save assignment' }))
    expect((await screen.findByRole('alert')).textContent).toContain('Could not create the role assignment.')
  })

  it('uses dedicated update failure copy', async () => {
    updateRoleAssignment.mockRejectedValue(new Error('private mutation detail'))
    setupMutationFailure()
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    const endAt = await screen.findByLabelText('End at', { selector: `input#assignment-end-${ASSIGNMENT_ID}` })
    fireEvent.change(endAt, { target: { value: '2030-01-01T00:00' } })
    const saveButtons = await screen.findAllByRole('button', { name: 'Save assignment' })
    fireEvent.click(saveButtons[saveButtons.length - 1]!)
    expect((await screen.findByRole('alert')).textContent).toContain('Could not update the role assignment.')
  })
})


describe('RoleAssignmentsTab edit flow', () => {
  it('opens an edit drawer with the picker pre-populated from the assignment scope', async () => {
    HTMLElement.prototype.scrollIntoView = () => {}

    const unitAssignment = {
      ...assignment,
      scope_type: 'unit' as const,
      scope_id: UNIT_ID,
    }
    listRoleAssignments.mockResolvedValue([unitAssignment])
    listRoles.mockResolvedValue([customRole])
    updateRoleAssignment.mockResolvedValue({ ...unitAssignment, lock_version: 2 })
    listUserAccounts.mockResolvedValue({ items: [{ id: ACCOUNT_ID, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }] })
    listAssignmentScopeTargets.mockImplementation(async (_token: string, query: { scopeType: string; parentScopeId?: string }) => {
      if (query.scopeType === 'cluster') return { items: [clusterTarget], next_cursor: null }
      if (query.scopeType === 'facility') return { items: [facilityTarget], next_cursor: null }
      if (query.scopeType === 'unit' && query.parentScopeId) return { items: [unitTarget], next_cursor: null }
      if (query.scopeType === 'unit' && !query.parentScopeId) return { items: [facilityTarget], next_cursor: null }
      return { items: [], next_cursor: null }
    })

    render(<RoleAssignmentsTab locale="en" capabilities={FULL_CAPABILITIES} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))

    // The edit drawer renders a second picker. The unit radio must already be checked.
    const unitRadios = await screen.findAllByRole('radio', { name: 'Unit' })
    expect(unitRadios.some((radio) => (radio as HTMLInputElement).checked)).toBe(true)

    // Save the edit. The submission must include scope_type + scope_id from the picker.
    const endAt = await screen.findByLabelText('End at', { selector: 'input#assignment-end-' + ASSIGNMENT_ID })
    fireEvent.change(endAt, { target: { value: '2030-01-01T00:00' } })
    const saveButtons = await screen.findAllByRole('button', { name: 'Save assignment' })
    fireEvent.click(saveButtons[saveButtons.length - 1]!)
    await waitFor(() => expect(updateRoleAssignment).toHaveBeenCalledTimes(1))
    const patch = updateRoleAssignment.mock.calls[0]?.[2] as Record<string, unknown>
    expect(patch).toMatchObject({ scope_type: 'unit', scope_id: UNIT_ID, end_at: new Date('2030-01-01T00:00').toISOString() })
    expect(updateRoleAssignment.mock.calls[0]?.[3]).toBe(1)
  })
})
