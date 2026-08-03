export interface ProblemDetails {
  type: string
  title: string
  status: number
  detail?: string
  correlation_id?: string
  errors?: Array<{ pointer?: string; code?: string; message?: string }>
}

export class ApiError extends Error {
  readonly status: number
  readonly problem: ProblemDetails
  readonly correlationId: string | null

  constructor(
    status: number,
    problem: ProblemDetails,
    correlationId: string | null = problem.correlation_id ?? null,
  ) {
    super(problem.title ?? `Request failed (${status})`)
    this.status = status
    this.problem = problem
    this.correlationId = correlationId
  }
}

export type ResourceState =
  | 'loading'
  | 'ready'
  | 'empty'
  | 'forbidden'
  | 'not-found'
  | 'conflict'
  | 'stale'
  | 'error'

export function stateFromError(error: unknown): ResourceState {
  if (error instanceof ApiError) {
    switch (error.status) {
      case 403:
        return 'forbidden'
      case 404:
        return 'not-found'
      case 409:
        return 'conflict'
      case 412:
        return 'stale'
    }
  }
  return 'error'
}

export function isRetryable(state: ResourceState): boolean {
  return state === 'error' || state === 'conflict' || state === 'stale'
}

/* ---- Session expiry (global 401) ---- */

type SessionExpiredHandler = () => void
let sessionExpiredHandler: SessionExpiredHandler | null = null

export function registerSessionExpiredHandler(
  handler: SessionExpiredHandler,
): void {
  sessionExpiredHandler = handler
}

function notifySessionExpired(): void {
  sessionExpiredHandler?.()
}

/* ---- UUID v7 ---- */

export function uuidV7(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  const time = BigInt(Date.now())
  bytes[0] = Number((time >> 40n) & 0xffn)
  bytes[1] = Number((time >> 32n) & 0xffn)
  bytes[2] = Number((time >> 24n) & 0xffn)
  bytes[3] = Number((time >> 16n) & 0xffn)
  bytes[4] = Number((time >> 8n) & 0xffn)
  bytes[5] = Number(time & 0xffn)
  bytes[6] = (bytes[6]! & 0x0f) | 0x70
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/* ---- Request options ---- */

export interface RequestOptions {
  command?: boolean
  mutation?: boolean
  idempotency?: string
  idempotencyKey?: string
  lockVersion?: number
  ifMatch?: string
  headers?: Record<string, string>
  redirect?: RequestRedirect
}

export function requestInit(
  csrfToken: string | null,
  options: RequestOptions = {},
): RequestInit {
  const correlationId = uuidV7()
  const headers: Record<string, string> = {
    Accept: 'application/json, application/problem+json',
    'X-Correlation-ID': correlationId,
    ...options.headers,
  }
  if (options.command) {
    headers['Idempotency-Key'] = options.idempotencyKey
      ?? (options.idempotency
        ? `${options.idempotency}-${correlationId}`
        : correlationId)
  }
  if (csrfToken && (options.command || options.mutation)) {
    headers['X-CSRF-Token'] = csrfToken
  }
  if (options.ifMatch) {
    headers['If-Match'] = options.ifMatch
  } else if (options.lockVersion !== undefined) {
    headers['If-Match'] = `"${options.lockVersion}"`
  }

  return {
    credentials: 'include',
    headers,
    ...(options.redirect ? { redirect: options.redirect } : {}),
  }
}

/* ---- Transport ---- */

export type GeneratedResponse = {
  status: number
  data: unknown
  headers: Headers
}

const EMPTY_BODY_STATUSES = new Set([204, 205, 304])

export async function customFetch(
  url: string,
  options: RequestInit,
): Promise<GeneratedResponse> {
  const response = await fetch(url, { credentials: 'include', ...options })
  const body = EMPTY_BODY_STATUSES.has(response.status)
    ? null
    : await response.text()
  let data: unknown = {}
  if (body) {
    try {
      data = JSON.parse(body)
    } catch {
      data = {}
    }
  }
  return { data, status: response.status, headers: response.headers }
}

export function parseStrongEtag(value: string | null): number | null {
  if (!value || !/^"[1-9]\d*"$/.test(value)) return null
  return Number(value.slice(1, -1))
}

/* ---- Unwrap ---- */

function errorFromResponse(response: GeneratedResponse): ApiError {
  const problem = (response.data as ProblemDetails) ?? {
    type: 'about:blank',
    title: 'Request failed',
    status: response.status,
  }
  return new ApiError(
    response.status,
    problem,
    problem.correlation_id ?? response.headers.get('X-Correlation-ID'),
  )
}

export function unwrap<T>(response: GeneratedResponse): T {
  if (response.status >= 400) {
    if (response.status === 401) notifySessionExpired()
    throw errorFromResponse(response)
  }
  const data = response.data as { data?: T } | T
  const payload = (
    data && typeof data === 'object' && 'data' in data
      ? (data as { data: T }).data
      : data
  ) as T
  const etag = parseStrongEtag(response.headers.get('ETag'))
  if (etag !== null && payload && typeof payload === 'object') {
    ;(payload as { lock_version?: number }).lock_version = etag
  }
  return payload
}

export function unwrapWithEtag<T>(response: GeneratedResponse): {
  value: T
  etag: number | null
} {
  const value = unwrap<T>(response)
  return { value, etag: parseStrongEtag(response.headers.get('ETag')) }
}

export function unwrapEmpty(response: GeneratedResponse): void {
  if (response.status >= 400) {
    if (response.status === 401) notifySessionExpired()
    throw errorFromResponse(response)
  }
}
