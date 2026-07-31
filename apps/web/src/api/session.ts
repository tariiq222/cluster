import { ApiError, customFetch, unwrap, uuidV7 } from './http'

export interface Session {
  csrfToken: string
  userId: string
  expiresAt: string
  restricted: boolean
}

const SESSION_METADATA_KEY = 'cluster.identity-session'

interface StoredSession {
  csrf_token: string
  user_id: string
  expires_at: string
  restricted: boolean
}

interface IdentitySessionPayload {
  csrf_token: string
  user_id: string
  expires_at: string
  restricted?: boolean
}

interface CurrentIdentityPayload {
  user_id: string
  csrf_token: string
}

export async function login(username: string, password: string): Promise<Session> {
  const response = await customFetch('/api/v1/identity/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json, application/problem+json',
      'Content-Type': 'application/json',
      'X-Correlation-ID': uuidV7(),
    },
    body: JSON.stringify({ username, password }),
  })
  const result = unwrap<IdentitySessionPayload>(response)
  const session = normalizeLogin(result)
  const stored: StoredSession = {
    csrf_token: session.csrfToken,
    user_id: session.userId,
    expires_at: session.expiresAt,
    restricted: session.restricted,
  }
  sessionStorage.setItem(SESSION_METADATA_KEY, JSON.stringify(stored))
  return session
}

function normalizeLogin(result: IdentitySessionPayload): Session {
  const { csrf_token, user_id, expires_at, restricted } = result
  if (!isUuidV7(user_id) || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(expires_at) || !csrf_token) {
    throw new ApiError(502, { type: 'about:blank', title: 'Invalid login response', status: 502 })
  }
  return { csrfToken: csrf_token, userId: user_id, expiresAt: expires_at, restricted: Boolean(restricted) }
}

export async function restoreSession(): Promise<Session | null> {
  try {
    const identity = unwrap<CurrentIdentityPayload>(
      await customFetch('/api/v1/identity/me', {
        method: 'GET',
        headers: { Accept: 'application/json', 'X-Correlation-ID': uuidV7() },
      }),
    )
    const csrf = unwrap<{ csrf_token: string }>(
      await customFetch('/api/v1/identity/csrf', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Correlation-ID': uuidV7(),
          'X-CSRF-Token': identity.csrf_token,
          'Idempotency-Key': uuidV7(),
        },
      }),
    )
    return {
      csrfToken: csrf.csrf_token,
      userId: identity.user_id,
      expiresAt: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
      restricted: false,
    }
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) return null
    throw error
  }
}

export async function identityLogout(csrfToken: string): Promise<void> {
  try {
    await customFetch('/api/v1/identity/logout', {
      method: 'POST',
      headers: {
        Accept: 'application/json, application/problem+json',
        'X-Correlation-ID': uuidV7(),
        'X-CSRF-Token': csrfToken,
        'Idempotency-Key': uuidV7(),
      },
    })
  } catch {
    // best-effort logout
  } finally {
    sessionStorage.removeItem(SESSION_METADATA_KEY)
  }
}

export function storedSession(): Session | null {
  const raw = sessionStorage.getItem(SESSION_METADATA_KEY)
  if (!raw) return null
  try {
    const stored = JSON.parse(raw) as StoredSession
    return {
      csrfToken: stored.csrf_token,
      userId: stored.user_id,
      expiresAt: stored.expires_at,
      restricted: Boolean(stored.restricted),
    }
  } catch {
    return null
  }
}

export function clearStoredSession(): void {
  sessionStorage.removeItem(SESSION_METADATA_KEY)
}

function isUuidV7(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value)
}
