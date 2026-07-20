import { describe, expect, it, vi } from 'vitest'
import {
  createRoleAssignment,
  getAuthorizationAdminResource,
  listAuthorization,
  simulateAccessDecision,
  updateAuthorizationAdminResource,
  uuidV7,
} from '../r1'

describe('W1.3 authorization transport', () => {
  it('creates UUIDv7 correlation and idempotency headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'x' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await createRoleAssignment({ code: 'role.test' }, 'token')
    const request = fetchMock.mock.calls[0][1] as RequestInit
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
    const fetchMock = vi.fn()
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
    expect(String(fetchMock.mock.calls[1][0])).toContain('/authorization/roles/018f6f7d-0c00-7000-8000-000000000002')
    const updateHeaders = new Headers((fetchMock.mock.calls[1][1] as RequestInit).headers)
    expect(updateHeaders.get('If-Match')).toBe('"2"')
    expect(updateHeaders.get('X-CSRF-Token')).toBe('token')
    expect(updateHeaders.get('Authorization')).toBeNull()
    expect(String(fetchMock.mock.calls[2][0])).toContain('/authorization/access-decisions')
    const simulatorRequest = fetchMock.mock.calls[2][1] as RequestInit
    expect(new Headers(simulatorRequest.headers).get('X-CSRF-Token')).toBe('token')
    expect(new Headers(simulatorRequest.headers).get('Authorization')).toBeNull()
    expect(simulatorRequest.credentials).toBe('include')
    vi.unstubAllGlobals()
  })
})
