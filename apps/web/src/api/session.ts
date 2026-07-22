import * as generated from './generated/cluster'
import type {
  CurrentIdentityResponseData,
  IdentityActivationIssued,
  IdentityActivationRequest,
  IdentityLoginRequest,
  IdentityPasswordChangeRequest,
  IdentitySessionResponseData,
} from './generated/cluster'
import { ApiError, requestInit, unwrap, unwrapEmpty, unwrapEnvelope } from './http'

export type IdentityLoginInput = IdentityLoginRequest
export type IdentityActivationInput = IdentityActivationRequest
export type IdentityCredentialChangeInput = IdentityPasswordChangeRequest
export type IdentitySession = IdentitySessionResponseData
export type CurrentIdentity = CurrentIdentityResponseData
export type IdentityActivation = IdentityActivationIssued

export type Session = {
  csrf_token: string
  user_id: string
  expires_at: string
  restricted: boolean
  facility?: 'facility-a' | 'facility-b'
  principal: { user_id: string; facility_id?: string }
  /** @deprecated Compatibility alias for legacy callers; contains the CSRF token, never a bearer token. */
  access_token: string
}

export const SESSION_METADATA_KEY = 'cluster.identity-session'

const UUID_V7_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const UTC_DATE_TIME_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/

function persistSessionMetadata(session: Session): void {
  try {
    window.sessionStorage.setItem(
      SESSION_METADATA_KEY,
      JSON.stringify({
        csrf_token: session.csrf_token,
        user_id: session.user_id,
        expires_at: session.expires_at,
        restricted: session.restricted,
      }),
    )
  } catch {
    // Session restoration remains available through the live cookie when storage is unavailable.
  }
}

export function clearSessionMetadata(): void {
  try {
    window.sessionStorage.removeItem(SESSION_METADATA_KEY)
  } catch {
    /* ignore unavailable storage */
  }
}

export async function identityLogin(input: IdentityLoginInput): Promise<IdentitySession> {
  return unwrapEnvelope<IdentitySession>(await generated.identityLogin(input, requestInit()))
}

export async function login(username: string, password: string): Promise<Session> {
  const session = await identityLogin({ username, password })

  if (
    !session ||
    typeof session !== 'object' ||
    typeof session.csrf_token !== 'string' ||
    session.csrf_token === '' ||
    typeof session.user_id !== 'string' ||
    !UUID_V7_PATTERN.test(session.user_id) ||
    typeof session.expires_at !== 'string' ||
    !UTC_DATE_TIME_PATTERN.test(session.expires_at)
  ) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Invalid session response',
      status: 502,
    })
  }

  const restored: Session = {
    csrf_token: session.csrf_token,
    user_id: session.user_id,
    expires_at: session.expires_at,
    restricted: session.restricted,
    principal: { user_id: session.user_id },
    access_token: session.csrf_token,
  }
  persistSessionMetadata(restored)
  return restored
}

export async function getCurrentIdentity(): Promise<CurrentIdentity> {
  return unwrapEnvelope<CurrentIdentity>(await generated.getCurrentIdentity(requestInit()))
}

export async function refreshIdentityCsrf(): Promise<{ csrf_token: string }> {
  const body = unwrapEnvelope<{ csrf_token?: unknown }>(
    await generated.refreshIdentityCsrf(requestInit()),
  )
  if (typeof body?.csrf_token !== 'string' || body.csrf_token === '') {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Invalid CSRF response',
      status: 502,
    })
  }
  return { csrf_token: body.csrf_token }
}

export async function restoreSession(): Promise<Session | null> {
  const current = await getCurrentIdentity().catch((error: unknown) => {
    if (error instanceof ApiError && [401, 403].includes(error.status)) return null
    throw error
  })
  if (!current) return null

  const csrf = await refreshIdentityCsrf()
  const restored: Session = {
    csrf_token: csrf.csrf_token,
    user_id: current.principal.user_id,
    expires_at: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
    restricted: current.session.restricted,
    principal: { user_id: current.principal.user_id },
    access_token: csrf.csrf_token,
  }
  persistSessionMetadata(restored)
  return restored
}

export async function identityLogout(csrfToken: string): Promise<void> {
  unwrapEmpty(await generated.identityLogout(requestInit(csrfToken, { command: true, idempotency: 'identity-logout' })))
}

export async function consumeIdentityActivation(
  input: IdentityActivationInput,
): Promise<void> {
  unwrapEmpty(await generated.consumeIdentityActivation(input, requestInit()))
}

export async function changeIdentityPassword(
  csrfToken: string,
  input: IdentityCredentialChangeInput,
): Promise<void> {
  unwrapEmpty(
    await generated.changeIdentityPassword(input, requestInit(csrfToken, { mutation: true })),
  )
}

export async function issueIdentityActivation(
  csrfToken: string,
  accountId: string,
): Promise<IdentityActivation> {
  return unwrap<IdentityActivation>(
    await generated.issueIdentityActivation(
      accountId,
      requestInit(csrfToken, { command: true, idempotency: 'identity-activation' }),
    ),
  )
}
