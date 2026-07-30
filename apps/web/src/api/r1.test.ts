import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  transitionAuthorizationAdminResource,
  archiveRole,
  cloneRoleFromSystemRole,
  createRole,
  createRoleAssignment,
  expireRoleAssignment,
  getRole,
  listAssignmentScopeTargets,
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


const clusterScope = {
  scope_type: 'cluster',
  scope_id: '018f6f7d-0c00-7000-8000-000000000101',
  label_ar: 'تجمع الرياض',
  label_en: 'Riyadh cluster',
  code: 'RUH',
}
const facilityScope = {
  scope_type: 'facility',
  scope_id: '018f6f7d-0c00-7000-8000-000000000102',
  label_ar: 'مستشفى الملك فيصل',
  label_en: 'King Faisal Hospital',
  code: 'KFH',
}
const unitScope = {
  scope_type: 'unit',
  scope_id: '018f6f7d-0c00-7000-8000-000000000103',
  label_ar: 'قسم الطوارئ',
  label_en: 'Emergency department',
  code: 'ED',
}

describe('assignment scope-target catalog wrapper', () => {
  it('forwards scope_type and unwraps the collection envelope', async () => {
    const mock = fetchMock({ items: [clusterScope], next_cursor: null })
    const result = await listAssignmentScopeTargets(token, { scopeType: 'cluster' })
    expect(result).toMatchObject({ items: [clusterScope], next_cursor: null })

    const call = callOf(mock)
    expect(call[0]).toBe('/api/v1/authorization/assignment-scope-targets?scope_type=cluster')
    expect(call[1]?.method ?? 'GET').toBe('GET')
  })

  it('forwards parent_scope_type and parent_scope_id without leaking other params', async () => {
    const mock = fetchMock({ items: [facilityScope], next_cursor: null })
    await listAssignmentScopeTargets(token, {
      scopeType: 'facility',
      parentScopeType: 'cluster',
      parentScopeId: '018f6f7d-0c00-7000-8000-000000000200',
      search: 'king',
      limit: 25,
    })
    const url = String(callOf(mock)[0])
    const parsed = new URL(url, 'https://placeholder.local')
    expect(parsed.pathname).toBe('/api/v1/authorization/assignment-scope-targets')
    expect(parsed.searchParams.get('scope_type')).toBe('facility')
    expect(parsed.searchParams.get('parent_scope_type')).toBe('cluster')
    expect(parsed.searchParams.get('parent_scope_id')).toBe('018f6f7d-0c00-7000-8000-000000000200')
    expect(parsed.searchParams.get('search')).toBe('king')
    expect(parsed.searchParams.get('limit')).toBe('25')
    // No `reason` parameter is ever sent on a read.
    expect(parsed.searchParams.has('reason')).toBe(false)
  })

  it('omits parent_scope_type and parent_scope_id when not supplied', async () => {
    const mock = fetchMock({ items: [unitScope], next_cursor: null })
    await listAssignmentScopeTargets(token, { scopeType: 'unit' })
    const url = String(callOf(mock)[0])
    const parsed = new URL(url, 'https://placeholder.local')
    expect(parsed.searchParams.get('scope_type')).toBe('unit')
    expect(parsed.searchParams.has('parent_scope_type')).toBe(false)
    expect(parsed.searchParams.has('parent_scope_id')).toBe(false)
  })

  it('surfaces 422 urn:cluster:problem:scope_type_not_catalogued as an ApiError', async () => {
    const mock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          type: 'urn:cluster:problem:scope_type_not_catalogued',
          title: 'Scope type not catalogued',
          status: 422,
          detail: 'record_set is not a manageable level',
        }),
        { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
      ),
    )
    vi.stubGlobal('fetch', mock)
    await expect(listAssignmentScopeTargets(token, { scopeType: 'record_set' as never })).rejects.toMatchObject({
      status: 422,
      problem: { type: 'urn:cluster:problem:scope_type_not_catalogued' },
    })
  })

  it('surfaces 403 as a typed ApiError so the screen can fail closed', async () => {
    const mock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({ type: 'about:blank', title: 'Forbidden', status: 403 }),
        { status: 403, headers: { 'Content-Type': 'application/problem+json' } },
      ),
    )
    vi.stubGlobal('fetch', mock)
    await expect(listAssignmentScopeTargets(token, { scopeType: 'facility' })).rejects.toMatchObject({
      status: 403,
    })
  })
})
