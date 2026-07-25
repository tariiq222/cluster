import { describe, expect, it, vi } from 'vitest'
import {
  archiveRequest,
  cancelRequest,
  createRoleAssignment,
  getAuthorizationAdminResource,
  listAuthorization,
  simulateAccessDecision,
  transitionAuthorizationAdminResource,
  updateAuthorizationAdminResource,
  uuidV7,
} from '../r1'

function requiredAt<T>(values: readonly T[], index: number): T {
  const value = values[index]
  if (value === undefined) throw new Error(`Expected value at index ${index}`)
  return value
}

function requiredRequest(calls: readonly Parameters<typeof fetch>[], index: number): RequestInit {
  const request = requiredAt(calls, index)[1]
  if (request === undefined) throw new Error(`Expected request init at index ${index}`)
  return request
}

describe('W1.3 authorization transport', () => {
  it('creates UUIDv7 correlation and idempotency headers', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'x' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await createRoleAssignment({ code: 'role.test' }, 'token')
    const request = requiredRequest(fetchMock.mock.calls, 0)
    const headers = new Headers(request.headers)
    expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/)
    expect(headers.get('Authorization')).toBeNull()
    expect(headers.get('X-CSRF-Token')).toBe('token')
    expect(request.credentials).toBe('include')
    vi.unstubAllGlobals()
  })

  it('maps problem responses to ApiError and collection envelopes', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({ data: { items: [{ id: '1' }] } }), { status: 200 })).mockResolvedValueOnce(new Response(JSON.stringify({ title: 'Conflict', status: 409 }), { status: 409 })))
    await expect(listAuthorization('roles', 'token')).resolves.toHaveLength(1)
    await expect(listAuthorization('roles', 'token')).rejects.toMatchObject({ status: 409 })
    expect(await uuidV7()).toMatch(/-7[0-9a-f]{3}-/)
    vi.unstubAllGlobals()
  })

  it('uses the generated admin get/update and access simulator operations', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: '1' } }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: '1' } }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ decision_id: '018f6f7d-0c00-7000-8000-000000000001', decision: 'allow' }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await getAuthorizationAdminResource('roles', '018f6f7d-0c00-7000-8000-000000000002', 'token')
    await updateAuthorizationAdminResource('roles', '018f6f7d-0c00-7000-8000-000000000002', { name: 'Updated' }, 'token', 2)
    await simulateAccessDecision({
      action: 'work_record.read',
      access_context: {
        subject_id: '018f6f7d-0c00-7000-8000-000000000001',
        tenant_id: '018f6f7d-0c00-7000-8000-000000000002',
        clearance: 'internal',
        correlation_id: '018f6f7d-0c00-7000-8000-000000000003',
      },
      record_facts: {} as never,
    }, 'token')

    expect(fetchMock).toHaveBeenCalledTimes(3)
    const updateCall = requiredAt(fetchMock.mock.calls, 1)
    expect(String(updateCall[0])).toContain('/authorization/roles/018f6f7d-0c00-7000-8000-000000000002')
    const updateHeaders = new Headers(requiredRequest(fetchMock.mock.calls, 1).headers)
    expect(updateHeaders.get('If-Match')).toBe('"2"')
    expect(updateHeaders.get('X-CSRF-Token')).toBe('token')
    expect(updateHeaders.get('Authorization')).toBeNull()
    const simulatorCall = requiredAt(fetchMock.mock.calls, 2)
    expect(String(simulatorCall[0])).toContain('/authorization/access-decisions')
    const simulatorRequest = requiredRequest(fetchMock.mock.calls, 2)
    expect(new Headers(simulatorRequest.headers).get('X-CSRF-Token')).toBe('token')
    expect(new Headers(simulatorRequest.headers).get('Authorization')).toBeNull()
    expect(simulatorRequest.credentials).toBe('include')
    vi.unstubAllGlobals()
  })

  it('posts governed transitions through the generated wrapper with a required reason', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ data: { id: '1', status: 'revoked' } }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await transitionAuthorizationAdminResource('role-assignments', '018f6f7d-0c00-7000-8000-000000000002', 'revoke', 'No longer assigned', 'token', 4)

    const transitionCall = requiredAt(fetchMock.mock.calls, 0)
    expect(String(transitionCall[0])).toContain('/authorization/role-assignments/018f6f7d-0c00-7000-8000-000000000002/revoke')
    const request = requiredRequest(fetchMock.mock.calls, 0)
    expect(JSON.parse(String(request.body))).toEqual({ reason: 'No longer assigned' })
    expect(new Headers(request.headers).get('If-Match')).toBe('"4"')
    expect(new Headers(request.headers).get('X-CSRF-Token')).toBe('token')
    vi.unstubAllGlobals()
  })

  it('routes cancel and archive through reasoned generated work-record commands', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: '1', status: 'cancelled' } }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: '1', status: 'archived' } }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await cancelRequest('token', 'record-1', 'Withdrawn by requester', 2)
    await archiveRequest('token', 'record-1', 'Retention completed', 3)
    await expect(cancelRequest('token', 'record-1', '  ', 4)).rejects.toMatchObject({ status: 400 })

    const cancelCall = requiredAt(fetchMock.mock.calls, 0)
    const archiveCall = requiredAt(fetchMock.mock.calls, 1)
    expect(String(cancelCall[0])).toContain('/work-records/record-1/cancel')
    expect(String(archiveCall[0])).toContain('/work-records/record-1/archive')
    expect(JSON.parse(String(requiredRequest(fetchMock.mock.calls, 0).body))).toEqual({ reason: 'Withdrawn by requester' })
    expect(new Headers(requiredRequest(fetchMock.mock.calls, 1).headers).get('If-Match')).toBe('"3"')
    vi.unstubAllGlobals()
  })
})
