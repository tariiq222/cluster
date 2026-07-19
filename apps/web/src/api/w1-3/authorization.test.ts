import { describe, expect, it, vi } from 'vitest'
import { createRoleAssignment, listAuthorization, uuidV7 } from '../r1'

describe('W1.3 authorization transport', () => {
  it('creates UUIDv7 correlation and idempotency headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'x' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await createRoleAssignment({ code: 'role.test' }, 'token')
    const request = fetchMock.mock.calls[0][1] as RequestInit
    const headers = new Headers(request.headers)
    expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/)
    vi.unstubAllGlobals()
  })

  it('maps problem responses to ApiError and collection envelopes', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({ data: { items: [{ id: '1' }] } }), { status: 200 })).mockResolvedValueOnce(new Response(JSON.stringify({ title: 'Conflict', status: 409 }), { status: 409 })))
    await expect(listAuthorization('roles', 'token')).resolves.toHaveLength(1)
    await expect(listAuthorization('roles', 'token')).rejects.toMatchObject({ status: 409 })
    expect(await uuidV7()).toMatch(/-7[0-9a-f]{3}-/)
    vi.unstubAllGlobals()
  })
})
