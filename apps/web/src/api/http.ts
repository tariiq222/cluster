/**
 * Shared plumbing for every API call: request options, problem parsing, and
 * envelope unwrapping. Screens never call `fetch` and never build headers —
 * they call a domain wrapper that goes through `unwrap` over a generated client.
 */

export type ProblemFieldError = {
  pointer: string
  code: string
  message?: string
}

export type ProblemDetails = {
  type: string
  title: string
  status: number
  detail?: string
  errors?: ProblemFieldError[]
}

export class ApiError extends Error {
  readonly status: number
  readonly problem: ProblemDetails

  constructor(status: number, problem: ProblemDetails) {
    super(problem.detail ?? problem.title)
    this.name = 'ApiError'
    this.status = status
    this.problem = problem
  }
}

/**
 * Session expiry is a whole-app concern, not a per-screen one: the shell registers a
 * handler once and every 401 from any endpoint routes to it. Screens therefore no longer
 * need to inspect the status themselves to detect an expired session.
 */
let sessionExpiredHandler: (() => void) | null = null

export function registerSessionExpiredHandler(handler: (() => void) | null): void {
  sessionExpiredHandler = handler
}

function notifySessionExpired(): void {
  sessionExpiredHandler?.()
}

/**
 * The states any screen can be in while reading a resource. Screens used to each declare
 * a near-identical union; sharing one keeps the copy and the handling consistent.
 */
export type ResourceState =
  | 'loading'
  | 'ready'
  | 'empty'
  | 'forbidden'
  | 'not-found'
  | 'conflict'
  | 'stale'
  | 'error'

/**
 * Maps a failure onto the canonical state. `409` means someone else changed the record,
 * `412` means the caller's `If-Match` was out of date — both need a refresh before retry,
 * which is why they stay distinct from a generic error.
 */
export function stateFromError(error: unknown): ResourceState {
  if (!(error instanceof ApiError)) return 'error'
  switch (error.status) {
    case 403:
      return 'forbidden'
    case 404:
      return 'not-found'
    case 409:
      return 'conflict'
    case 412:
      return 'stale'
    default:
      return 'error'
  }
}

/** Whether retrying the same request unchanged could plausibly succeed. */
export function isRetryable(state: ResourceState): boolean {
  return state === 'error' || state === 'conflict' || state === 'stale'
}

export function uuidV7(): string {
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  let timestamp = Date.now()

  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = timestamp & 0xff
    timestamp = Math.floor(timestamp / 256)
  }

  bytes[6] = (bytes[6]! & 0x0f) | 0x70
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) =>
    byte.toString(16).padStart(2, '0'),
  ).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export type RequestOptions = {
  /** Sends `Idempotency-Key`; use for every command that creates or transitions state. */
  command?: boolean
  /**
   * Prefixes the idempotency key so a replayed command is identifiable in logs
   * (`cluster-<correlation-id>`). The key shares the request's correlation id.
   */
  idempotency?: string
  /** Sends `X-CSRF-Token` without an idempotency key; use for PATCH-style mutations. */
  mutation?: boolean
  /** Sends `If-Match` for optimistic concurrency. */
  lockVersion?: number
  /** Raw `If-Match` value when the caller already holds the ETag string. */
  ifMatch?: string
  headers?: Record<string, string>
  redirect?: RequestRedirect
}

export function requestInit(token?: string, options: RequestOptions = {}): RequestInit {
  const correlationId = uuidV7()
  const headers: Record<string, string> = {
    Accept: 'application/json, application/problem+json',
    'X-Correlation-ID': correlationId,
    ...options.headers,
  }
  if (options.command) {
    headers['Idempotency-Key'] = options.idempotency
      ? `${options.idempotency}-${correlationId}`
      : correlationId
  }
  if (token && (options.command || options.mutation)) headers['X-CSRF-Token'] = token
  if (options.ifMatch) headers['If-Match'] = options.ifMatch
  else if (options.lockVersion !== undefined) headers['If-Match'] = `"${options.lockVersion}"`

  return {
    credentials: 'include',
    headers,
    ...(options.redirect ? { redirect: options.redirect } : {}),
  }
}

export type GeneratedResponse = { status: number; data: unknown; headers: Headers }

export function parseStrongEtag(value: string | null): number | null {
  if (!value || !/^"[1-9]\d*"$/.test(value)) return null
  return Number(value.slice(1, -1))
}

function problemFrom(status: number, payload: unknown): ProblemDetails {
  const value =
    payload && typeof payload === 'object' ? (payload as Partial<ProblemDetails>) : {}
  const errors = Array.isArray(value.errors)
    ? value.errors.flatMap((entry) => {
        if (
          typeof entry !== 'object' ||
          entry === null ||
          typeof (entry as ProblemFieldError).pointer !== 'string' ||
          typeof (entry as ProblemFieldError).code !== 'string'
        ) {
          return []
        }
        const field = entry as ProblemFieldError
        return [
          {
            pointer: field.pointer,
            code: field.code,
            message: typeof field.message === 'string' ? field.message : undefined,
          },
        ]
      })
    : undefined

  return {
    type: typeof value.type === 'string' ? value.type : 'about:blank',
    title: typeof value.title === 'string' ? value.title : 'Request failed',
    status,
    detail: typeof value.detail === 'string' ? value.detail : undefined,
    errors,
  }
}

type Entity = Record<string, unknown> & { lock_version?: number }

/**
 * Turns a generated `{ data, status, headers }` result into the domain value:
 * throws `ApiError` on a problem response, peels the `{ data: … }` envelope, and
 * stamps `lock_version` from the ETag so callers can send `If-Match` later.
 */
export function unwrap<T>(response: GeneratedResponse): T {
  if (response.status >= 400) {
    if (response.status === 401) notifySessionExpired()
    throw new ApiError(response.status, problemFrom(response.status, response.data))
  }

  const body = response.data
  const value =
    body && typeof body === 'object' && !Array.isArray(body) && 'data' in body
      ? (body as { data: unknown }).data
      : body

  if (value && typeof value === 'object' && !Array.isArray(value)) {
    const entity = value as Entity
    const lockVersion = parseStrongEtag(response.headers.get('ETag'))
    if (lockVersion !== null && entity.lock_version === undefined) {
      entity.lock_version = lockVersion
    }
  }

  return value as T
}

/**
 * Unwraps endpoints whose published contract requires a top-level `data`
 * envelope. Keeping this separate from `unwrap` lets legacy raw-body endpoints
 * remain supported without accepting malformed identity/session responses.
 */
export function unwrapEnvelope<T>(response: GeneratedResponse): T {
  if (response.status >= 400) return unwrap<T>(response)
  const body = response.data
  if (!body || typeof body !== 'object' || Array.isArray(body) || !('data' in body)) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Invalid response envelope',
      status: 502,
    })
  }
  return unwrap<T>(response)
}

/** Same as `unwrap`, but also returns the ETag so a follow-up command can send `If-Match`. */
export function unwrapWithEtag<T>(response: GeneratedResponse): { value: T; etag: string | null } {
  return { value: unwrap<T>(response), etag: response.headers.get('ETag') }
}

/** Asserts a successful response for endpoints that return no body. */
export function unwrapEmpty(response: GeneratedResponse): void {
  if (response.status >= 400) {
    if (response.status === 401) notifySessionExpired()
    throw new ApiError(response.status, problemFrom(response.status, response.data))
  }
}
