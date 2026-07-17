import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ApiError,
  createWorkRecord,
  getWorkRecord,
  listNotifications,
  listWorkRecords,
  login,
} from './api'

const session = {
  access_token: 'fixture-token',
  token_type: 'Bearer' as const,
  expires_at: '2026-07-17T12:00:00Z',
  facility: 'facility-a' as const,
  principal: {
    user_id: '018f6f7d-0c00-7000-8000-000000000021',
    facility_id: '018f6f7d-0c00-7000-8000-000000000011',
  },
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function mockFetch(response: Response) {
  const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('API client', () => {
  it('logs in with correlation metadata and accepts the data envelope', async () => {
    const fetchMock = mockFetch(jsonResponse({ data: session }))

    await expect(login('fixture-user', 'fixture-password')).resolves.toEqual(session)

    expect(fetchMock).toHaveBeenCalledOnce()
    const [path, init] = fetchMock.mock.calls[0]
    const headers = new Headers(init?.headers)
    expect(path).toBe('/api/v1/auth/login')
    expect(init).toMatchObject({ method: 'POST', credentials: 'same-origin' })
    expect(headers.get('Accept')).toBe('application/json, application/problem+json')
    expect(headers.get('Content-Type')).toBe('application/json')
    expect(headers.get('X-Correlation-ID')).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
    expect(init?.body).toBe(JSON.stringify({ username: 'fixture-user', password: 'fixture-password' }))
  })

  it('rejects an invalid login response', async () => {
    const invalidSessions: unknown[] = [
      null,
      {},
      { ...session, access_token: 42 },
      { ...session, access_token: '' },
      { ...session, token_type: 'Basic' },
      { ...session, expires_at: null },
      { ...session, expires_at: 'not-a-timestamp' },
      { ...session, facility: 'facility-c' },
      { ...session, principal: 'invalid' },
      { ...session, principal: null },
      { ...session, principal: { facility_id: session.principal.facility_id } },
      { ...session, principal: { user_id: session.principal.user_id } },
      { ...session, principal: { ...session.principal, user_id: 42 } },
      { ...session, principal: { ...session.principal, facility_id: 42 } },
      { ...session, principal: { ...session.principal, user_id: 'not-a-uuid' } },
      { ...session, principal: { ...session.principal, facility_id: 'not-a-uuid' } },
    ]

    for (const invalidSession of invalidSessions) {
      mockFetch(jsonResponse({ data: invalidSession }))
      await expect(login('fixture-user', 'fixture-password')).rejects.toMatchObject({
        name: 'ApiError',
        status: 502,
        problem: { title: 'Invalid session response' },
      })
    }
  })

  it('rejects a valid-looking login response without the contract envelope', async () => {
    mockFetch(jsonResponse(session))

    await expect(login('fixture-user', 'fixture-password')).rejects.toMatchObject({ status: 502 })
  })

  it('preserves safe problem details and filters malformed field errors', async () => {
    mockFetch(jsonResponse({
      type: 'https://example.test/problems/validation',
      title: 'Validation failed',
      status: 422,
      detail: 'Review the submitted fields.',
      errors: [
        { pointer: '/title', code: 'required', message: 'Required' },
        { pointer: 42, code: 'invalid' },
      ],
    }, 422))

    const failure = await login('fixture-user', 'fixture-password').catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(ApiError)
    expect(failure).toMatchObject({
      status: 422,
      message: 'Review the submitted fields.',
      problem: {
        type: 'https://example.test/problems/validation',
        errors: [{ pointer: '/title', code: 'required', message: 'Required' }],
      },
    })
  })

  it('falls back to a metadata-safe problem for a non-JSON failure', async () => {
    mockFetch(new Response('upstream failure', { status: 503 }))

    await expect(listWorkRecords(session.access_token)).rejects.toMatchObject({
      status: 503,
      problem: { type: 'about:blank', title: 'Request failed', status: 503 },
    })
  })

  it('normalizes optional problem fields when they are absent', async () => {
    mockFetch(jsonResponse({
      title: 'Conflict',
      status: 409,
      errors: [{ pointer: '/record', code: 'conflict' }],
    }, 409))

    await expect(listNotifications(session.access_token)).rejects.toMatchObject({
      message: 'Conflict',
      problem: {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: undefined,
        errors: [{ pointer: '/record', code: 'conflict', message: undefined }],
      },
    })
  })

  it('ignores a non-array problem errors field', async () => {
    mockFetch(jsonResponse({ title: 'Conflict', status: 409, errors: 'unsafe' }, 409))

    await expect(listNotifications(session.access_token)).rejects.toMatchObject({
      problem: { title: 'Conflict', status: 409, errors: undefined },
    })
  })

  it('rejects malformed problem metadata without exposing its contents', async () => {
    mockFetch(jsonResponse({ title: 42, status: 'failed', errors: 'unsafe' }, 500))

    await expect(listNotifications(session.access_token)).rejects.toMatchObject({
      status: 500,
      problem: { type: 'about:blank', title: 'Request failed', status: 500 },
    })
  })

  it('creates a work record with matching correlation and idempotency identifiers', async () => {
    const record = {
      id: '018f6f7d-0c00-7000-8000-000000000001',
      record_number: 'REQ-0001',
      status: 'draft' as const,
      payload: { title: 'Test', description: 'Description' },
      created_at: '2026-07-17T12:00:00Z',
    }
    const fetchMock = mockFetch(jsonResponse({ data: record }, 201))

    await expect(createWorkRecord(session.access_token, {
      work_definition_code: 'request',
      title: 'Test',
      description: 'Description',
    })).resolves.toEqual(record)

    const [, init] = fetchMock.mock.calls[0]
    const headers = new Headers(init?.headers)
    const correlationId = headers.get('X-Correlation-ID')
    expect(headers.get('Authorization')).toBe('Bearer fixture-token')
    expect(headers.get('Idempotency-Key')).toBe(`request-${correlationId}`)
  })

  it('uses the expected collection and encoded detail routes', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'record/id' } }))
    vi.stubGlobal('fetch', fetchMock)

    await listWorkRecords(session.access_token)
    await listNotifications(session.access_token)
    await getWorkRecord(session.access_token, 'record/id')

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/work-records?limit=20',
      '/api/v1/notifications?limit=20',
      '/api/v1/work-records/record%2Fid',
    ])
  })
})
