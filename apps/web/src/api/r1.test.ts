import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  transitionAuthorizationAdminResource,
  archiveRole,
  cloneRoleFromSystemRole,
  createRole,
  createRoleAssignment,
  expireRoleAssignment,
  getRole,
  listCapabilities,
  listRoleAssignments,
  listRoles,
  revokeRoleAssignment,
  revokeRoleCapability,
  updateRole,
  updateRoleAssignment,
} from './r1'

const token = 'csrf-token'
const roleId = '018f6f7d-0c00-7000-8000-000000000001'
const capabilityId = '018f6f7d-0c00-7000-8000-000000000002'
const assignmentId = '018f6f7d-0c00-7000-8000-000000000003'

const role = {
  id: roleId,
  code: 'records.viewer',
  role_type: 'custom',
  is_system_role: false,
  status: 'active',
  lock_version: 2,
  allowed_actions: ['edit'],
}
const assignment = {
  id: assignmentId,
  role_id: roleId,
  subject_user_id: '018f6f7d-0c00-7000-8000-000000000004',
  effective_status: 'active',
  allowed_actions: ['edit'],
}
const capability = {
  code: 'records.read',
  module_code: 'records',
  action: 'read',
  sensitivity: 'internal',
  group_label: 'Records',
}

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: '"2"' },
  })
}

function fetchMock(data: unknown, status = 200) {
  const mock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse(data, status))
  vi.stubGlobal('fetch', mock)
  return mock
}

function callOf(mock: ReturnType<typeof vi.fn<typeof fetch>>) {
  const call = mock.mock.calls[0]
  if (!call) throw new Error('Expected fetch call')
  return call
}

function headersOf(call: Parameters<typeof fetch>) {
  return new Headers(call[1]?.headers)
}

afterEach(() => vi.unstubAllGlobals())

describe('authorization role and assignment API boundary', () => {
  it('uses typed generic reads and forwards pagination filters', async () => {
    const mock = fetchMock({ items: [role], next_cursor: null })
    await expect(listRoles(token, { cursor: 'next', limit: 25 })).resolves.toEqual([expect.objectContaining({ id: roleId })])
    expect(callOf(mock)[0]).toBe('/api/v1/authorization/roles?cursor=next&limit=25')

    mock.mockResolvedValueOnce(jsonResponse(role))
    await expect(getRole(token, roleId)).resolves.toMatchObject({ id: roleId, lock_version: 2 })
    expect(mock.mock.calls[1]?.[0]).toBe(`/api/v1/authorization/roles/${roleId}`)

    mock.mockResolvedValueOnce(jsonResponse({ items: [capability], next_cursor: null }))
    await expect(listCapabilities(token, { limit: 50 })).resolves.toEqual([expect.objectContaining({ code: 'records.read' })])
    expect(mock.mock.calls[2]?.[0]).toBe('/api/v1/authorization/capabilities?limit=50')

    mock.mockResolvedValueOnce(jsonResponse({ items: [assignment], next_cursor: null }))
    await expect(listRoleAssignments(token, { cursor: 'assignments' })).resolves.toEqual([expect.objectContaining({ id: assignmentId })])
    expect(mock.mock.calls[3]?.[0]).toBe('/api/v1/authorization/role-assignments?cursor=assignments')
  })

  it('creates roles and assignments with idempotency and no reason', async () => {
    const mock = fetchMock(role, 201)
    await createRole(token, { resource_type: 'role', code: 'records.viewer', capability_codes: ['records.read'] })
    let call = callOf(mock)
    expect(call[0]).toBe('/api/v1/authorization/roles')
    expect(call[1]?.method).toBe('POST')
    expect(headersOf(call).get('Idempotency-Key')).toMatch(/^authorization-role-/)
    expect(JSON.parse(String(call[1]?.body))).toEqual({ resource_type: 'role', code: 'records.viewer', capability_codes: ['records.read'] })
    expect(JSON.parse(String(call[1]?.body))).not.toHaveProperty('reason')

    mock.mockResolvedValueOnce(jsonResponse(assignment, 201))
    await createRoleAssignment(token, { resource_type: 'role_assignment', code: 'records.assignment', role_id: roleId })
    call = mock.mock.calls[1]!
    expect(call[0]).toBe('/api/v1/authorization/role-assignments')
    expect(headersOf(call).get('Idempotency-Key')).toMatch(/^authorization-role-assignment-/)
    expect(JSON.parse(String(call[1]?.body))).not.toHaveProperty('reason')
  })

  it('updates and archives roles with merge-patch and exact concurrency headers', async () => {
    const mock = fetchMock(role)
    await updateRole(token, roleId, { name: 'Records viewer', capability_codes: ['records.read'] }, 1)
    let call = callOf(mock)
    expect(call[0]).toBe(`/api/v1/authorization/roles/${roleId}`)
    expect(call[1]?.method).toBe('PATCH')
    expect(headersOf(call).get('Content-Type')).toBe('application/merge-patch+json')
    expect(headersOf(call).get('If-Match')).toBe('"1"')
    expect(headersOf(call).get('Idempotency-Key')).toBeNull()
    expect(JSON.parse(String(call[1]?.body))).toEqual({ name: 'Records viewer', capability_codes: ['records.read'] })

    mock.mockResolvedValueOnce(jsonResponse({ ...role, status: 'archived' }))
    await archiveRole(token, roleId, 2)
    call = mock.mock.calls[1]!
    expect(call[0]).toBe(`/api/v1/authorization/roles/${roleId}`)
    expect(headersOf(call).get('Content-Type')).toBe('application/merge-patch+json')
    expect(headersOf(call).get('If-Match')).toBe('"2"')
    expect(JSON.parse(String(call[1]?.body))).toEqual({ status: 'archived' })
    expect(JSON.parse(String(call[1]?.body))).not.toHaveProperty('reason')
  })

  it('updates assignments with merge-patch and no reason', async () => {
    const mock = fetchMock(assignment)
    await updateRoleAssignment(token, assignmentId, { end_at: '2027-01-01T00:00:00Z' }, 3)
    const call = callOf(mock)
    expect(call[0]).toBe(`/api/v1/authorization/role-assignments/${assignmentId}`)
    expect(call[1]?.method).toBe('PATCH')
    expect(headersOf(call).get('Content-Type')).toBe('application/merge-patch+json')
    expect(headersOf(call).get('If-Match')).toBe('"3"')
    expect(headersOf(call).get('Idempotency-Key')).toBeNull()
    expect(JSON.parse(String(call[1]?.body))).toEqual({ end_at: '2027-01-01T00:00:00Z' })
  })

  it.each([
    ['revoke', revokeRoleAssignment],
    ['expire', expireRoleAssignment],
  ] as const)('%ss an assignment without a body or reason', async (action, wrapper) => {
    const mock = fetchMock(assignment)
    await wrapper(token, assignmentId, 4)
    const call = callOf(mock)
    expect(call[0]).toBe(`/api/v1/authorization/role-assignments/${assignmentId}/${action}`)
    expect(call[1]?.method).toBe('POST')
    expect(headersOf(call).get('If-Match')).toBe('"4"')
    expect(headersOf(call).get('Idempotency-Key')).toMatch(new RegExp(`^authorization-role-assignment-${action}-`))
    expect(call[1]?.body).toBeUndefined()
  })

  it('revokes a composite role-capability ID with concurrency and idempotency', async () => {
    const mock = fetchMock({ id: `${roleId}:${capabilityId}` })
    await revokeRoleCapability(token, roleId, capabilityId, 5)
    const call = callOf(mock)
    expect(call[0]).toBe(`/api/v1/authorization/role-capabilities/${encodeURIComponent(`${roleId}:${capabilityId}`)}/revoke`)
    expect(headersOf(call).get('If-Match')).toBe('"5"')
    expect(headersOf(call).get('Idempotency-Key')).toMatch(/^authorization-role-capability-revoke-/)
    expect(call[1]?.body).toBeUndefined()
  })

  it('clones a system role with exact overrides, concurrency, and idempotency', async () => {
    const mock = fetchMock({ ...role, id: '018f6f7d-0c00-7000-8000-000000000005' }, 201)
    await cloneRoleFromSystemRole(token, roleId, { code: 'records.clone', name_en: 'Records clone' }, 6)
    const call = callOf(mock)
    expect(call[0]).toBe(`/api/v1/authorization/roles/${roleId}/clone`)
    expect(headersOf(call).get('If-Match')).toBe('"6"')
    expect(headersOf(call).get('Idempotency-Key')).toMatch(/^authorization-role-clone-/)
    expect(JSON.parse(String(call[1]?.body))).toEqual({ code: 'records.clone', name_en: 'Records clone' })
    expect(JSON.parse(String(call[1]?.body))).not.toHaveProperty('reason')
  })
  it.each([
    ['with overrides', { code: 'records.clone' }],
    ['without overrides', undefined],
  ] as const)('rejects clone %s before fetch when lockVersion is missing', async (_label, overrides) => {
    const mock = fetchMock(role)
    await expect(cloneRoleFromSystemRole(token, roleId, overrides, undefined as never)).rejects.toMatchObject({ status: 400 })
    expect(mock).not.toHaveBeenCalled()
  })

  it.each([
    ['updateRole', (t: string) => updateRole(t, roleId, { name: 'X' }, undefined as never)],
    ['archiveRole', (t: string) => archiveRole(t, roleId, undefined as never)],
    ['updateRoleAssignment', (t: string) => updateRoleAssignment(t, assignmentId, { end_at: '2027-01-01T00:00:00Z' }, undefined as never)],
    ['revokeRoleAssignment', (t: string) => revokeRoleAssignment(t, assignmentId, undefined as never)],
    ['expireRoleAssignment', (t: string) => expireRoleAssignment(t, assignmentId, undefined as never)],
    ['revokeRoleCapability', (t: string) => revokeRoleCapability(t, roleId, capabilityId, undefined as never)],
  ] as const)('rejects %s before fetch when lockVersion is missing', async (_label, wrapper) => {
    const mock = fetchMock(role)
    await expect(wrapper(token)).rejects.toMatchObject({ status: 400 })
    expect(mock).not.toHaveBeenCalled()
  })

  it('rejects activate without reason and publishes with reason', async () => {
    const mock = fetchMock({ id: roleId })
    await expect(transitionAuthorizationAdminResource('role-assignments', assignmentId, 'activate', '   ', token, 4)).rejects.toMatchObject({ status: 400 })
    expect(mock).not.toHaveBeenCalled()
    mock.mockResolvedValueOnce(jsonResponse({ id: '018f6f7d-0c00-7000-8000-000000000006' }))
    await transitionAuthorizationAdminResource('classification-policies', '018f6f7d-0c00-7000-8000-000000000006', 'publish', 'Policy rollout', token, 5)
    const publishCall = mock.mock.calls[0]
    expect(publishCall?.[0]).toBe('/api/v1/authorization/classification-policies/018f6f7d-0c00-7000-8000-000000000006/publish')
    expect(JSON.parse(String(publishCall?.[1]?.body))).toEqual({ reason: 'Policy rollout' })
    expect(headersOf(publishCall as Parameters<typeof fetch>).get('If-Match')).toBe('"5"')
  })
})
