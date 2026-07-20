import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ApiError,
  clearSessionMetadata,
  createWorkRecord,
  getWorkRecord,
  identityLogout,
  listNotifications,
  listWorkRecords,
  login,
  restoreSession,
  refreshIdentityCsrf,
} from './api'

const session = {
  access_token: 'csrf-token',
  csrf_token: 'csrf-token',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  restricted: false,
  expires_at: '2026-07-17T12:00:00Z',
  facility: 'facility-a' as const,
    principal: {
      user_id: '018f6f7d-0c00-7000-8000-000000000021',
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
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { user_id: session.user_id, expires_at: session.expires_at, restricted: false, csrf_token: session.csrf_token } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(login('fixture-user', 'fixture-password')).resolves.toMatchObject({ csrf_token: 'csrf-token', user_id: session.user_id })

    expect(fetchMock).toHaveBeenCalledOnce()
    const [path, init] = fetchMock.mock.calls[0]
    const headers = new Headers(init?.headers)
    expect(path).toBe('/api/v1/identity/login')
    expect(init).toMatchObject({ method: 'POST', credentials: 'include' })
    expect(headers.get('Accept')).toBe('application/json, application/problem+json')
    expect(headers.get('Content-Type')).toBe('application/json')
    expect(headers.get('X-Correlation-ID')).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
    expect(init?.body).toBe(JSON.stringify({ username: 'fixture-user', password: 'fixture-password' }))
    expect(headers.get('Authorization')).toBeNull()
  })

  it('rejects an invalid login response', async () => {
    const invalidSessions: unknown[] = [
      null,
      {},
      { ...session, csrf_token: 42 },
      { ...session, csrf_token: '' },
      { ...session, expires_at: null },
      { ...session, expires_at: 'not-a-timestamp' },
      { ...session, user_id: 'not-a-uuid' },
    ]

    for (const invalidSession of invalidSessions) {
      const fetchMock = vi.fn<typeof fetch>()
        .mockResolvedValueOnce(jsonResponse({ data: invalidSession }))
      vi.stubGlobal('fetch', fetchMock)
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

  it('restores a cookie-backed session from identity/me without sending a bearer header', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { principal: { user_id: session.user_id }, account: {}, session: { restricted: false } } }))
      .mockResolvedValueOnce(jsonResponse({ data: { csrf_token: 'fresh-csrf' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(restoreSession()).resolves.toMatchObject({ csrf_token: 'fresh-csrf', user_id: session.user_id })

    const [, init] = fetchMock.mock.calls[1]
    expect(init).toMatchObject({ credentials: 'include' })
    expect(new Headers(init?.headers).get('Authorization')).toBeNull()
  })

  it('restores after sessionStorage loss when identity/me returns a fresh CSRF token', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { principal: { user_id: session.user_id }, account: {}, session: { restricted: false } } }))
      .mockResolvedValueOnce(jsonResponse({ data: { csrf_token: 'fresh-csrf' } }))
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('sessionStorage', { clear: vi.fn() })
    sessionStorage.clear()

    await expect(restoreSession()).resolves.toMatchObject({ csrf_token: 'fresh-csrf', user_id: session.user_id })
    expect(new Headers(fetchMock.mock.calls[1][1]?.headers).get('Authorization')).toBeNull()
  })

  it('refreshes CSRF through the authenticated cookie session without a bearer header', async () => {
    const fetchMock = mockFetch(jsonResponse({ data: { csrf_token: 'rotated-csrf' } }))
    await expect(refreshIdentityCsrf()).resolves.toEqual({ csrf_token: 'rotated-csrf' })
    const [, init] = fetchMock.mock.calls[0]
    expect(init?.credentials).toBe('include')
    expect(new Headers(init?.headers).get('Authorization')).toBeNull()
  })

  it('rejects an empty CSRF response with a 502 ApiError', async () => {
    mockFetch(jsonResponse({ data: { csrf_token: '' } }))
    await expect(refreshIdentityCsrf()).rejects.toMatchObject({ status: 502 })
  })

  it('rejects when CSRF response is missing the data envelope', async () => {
    mockFetch(jsonResponse({ csrf_token: 'orphan' }))
    await expect(refreshIdentityCsrf()).rejects.toBeInstanceOf(ApiError)
  })

  it('persists the restricted flag from the live identity session', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { principal: { user_id: session.user_id }, account: {}, session: { restricted: true } } }))
      .mockResolvedValueOnce(jsonResponse({ data: { csrf_token: 'restricted-csrf' } }))
    vi.stubGlobal('fetch', fetchMock)
    const restored = await restoreSession()
    expect(restored?.restricted).toBe(true)
  })

  it('returns null when the live identity session is missing or unauthenticated', async () => {
    const fetchMock = mockFetch(jsonResponse({ title: 'Unauthorized', status: 401 }, 401))
    await expect(restoreSession()).resolves.toBeNull()
    const [, init] = fetchMock.mock.calls[0]
    expect(init?.credentials).toBe('include')
  })

  it('rethrows non-auth identity errors instead of silently returning null', async () => {
    mockFetch(jsonResponse({ title: 'Server error', status: 500 }, 500))
    await expect(restoreSession()).rejects.toBeInstanceOf(ApiError)
  })

  it('removes the persisted metadata even when sessionStorage is unavailable', () => {
    const originalWindow = (globalThis as { window?: unknown }).window
    Object.defineProperty(globalThis, 'window', {
      value: { sessionStorage: { removeItem: () => { throw new Error('blocked') } } },
      configurable: true,
    })
    try {
      expect(() => clearSessionMetadata()).not.toThrow()
    } finally {
      Object.defineProperty(globalThis, 'window', { value: originalWindow, configurable: true })
    }
  })

  it('does not claim logout succeeded when the server rejects it', async () => {
    mockFetch(jsonResponse({ title: 'Forbidden', status: 403 }, 403))
    await expect(identityLogout('csrf-token')).rejects.toMatchObject({ status: 403 })
  })

  it('uses the cookie-backed logout endpoint on success', async () => {
    const fetchMock = mockFetch(new Response(null, { status: 204 }))
    await expect(identityLogout('csrf-token')).resolves.toBeUndefined()
    const [, init] = fetchMock.mock.calls[0]
    expect(init?.credentials).toBe('include')
    expect(new Headers(init?.headers).get('X-CSRF-Token')).toBe('csrf-token')
    expect(new Headers(init?.headers).get('Authorization')).toBeNull()
  })

  it('does not restore after a successful logout has invalidated the server session', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ title: 'Unauthorized', status: 401 }, 401))
    vi.stubGlobal('fetch', fetchMock)

    await expect(identityLogout('csrf-token')).resolves.toBeUndefined()
    await expect(restoreSession()).resolves.toBeNull()
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual(['/api/v1/identity/logout', '/api/v1/identity/me'])
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

  it('normalizes cookie-session endpoint failures as ApiError instances', async () => {
    const fetchMock = mockFetch(jsonResponse({
      type: 'https://example.test/problems/csrf',
      title: 'Forbidden',
      status: 403,
      detail: 'The CSRF proof is invalid.',
    }, 403))

    await expect(identityLogout('csrf-token')).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
      problem: { title: 'Forbidden', detail: 'The CSRF proof is invalid.' },
    })

    const [, init] = fetchMock.mock.calls[0]
    const headers = new Headers(init?.headers)
    expect(init).toMatchObject({ credentials: 'include' })
    expect(headers.get('X-CSRF-Token')).toBe('csrf-token')
    expect(headers.get('Authorization')).toBeNull()
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
    expect(headers.get('Authorization')).toBeNull()
    expect(headers.get('X-CSRF-Token')).toBe('csrf-token')
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
