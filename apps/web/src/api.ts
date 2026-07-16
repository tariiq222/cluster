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

export type Session = {
  access_token: string
  token_type: 'Bearer'
  expires_at: string
  facility?: string
}

export type WorkRecord = {
  id: string
  record_number: string
  status: 'draft' | 'submitted' | 'in_review' | 'returned' | 'approved' | 'rejected' | 'completed' | 'cancelled' | 'archived'
  payload: {
    title?: string
    description?: string
  }
  created_at: string
}

export type WorkRecordCollection = {
  items: WorkRecord[]
  next_cursor: string | null
}

export type SourceReference = {
  source_module: string
  record_type: string
  record_id: string
}

export type Notification = {
  id: string
  title: string
  source: SourceReference
  is_read: boolean
  created_at: string
}

export type NotificationCollection = {
  items: Notification[]
  next_cursor: string | null
}

export type CreateWorkRecordInput = {
  work_definition_code: 'request'
  title: string
  description: string
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

function uuidV7(): string {
  const bytes = new Uint8Array(16)
  window.crypto.getRandomValues(bytes)
  let timestamp = Date.now()

  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = timestamp & 0xff
    timestamp = Math.floor(timestamp / 256)
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function problemFrom(response: Response): Promise<ProblemDetails> {
  try {
    const body = await response.json() as Partial<ProblemDetails>
    if (typeof body.title === 'string' && typeof body.status === 'number') {
      const errors = Array.isArray(body.errors)
        ? body.errors.flatMap((error) => {
            if (
              typeof error !== 'object'
              || error === null
              || !('pointer' in error)
              || !('code' in error)
              || typeof error.pointer !== 'string'
              || typeof error.code !== 'string'
            ) {
              return []
            }

            return [{
              pointer: error.pointer,
              code: error.code,
              message: 'message' in error && typeof error.message === 'string' ? error.message : undefined,
            }]
          })
        : undefined

      return {
        type: typeof body.type === 'string' ? body.type : 'about:blank',
        title: body.title,
        status: body.status,
        detail: typeof body.detail === 'string' ? body.detail : undefined,
        errors,
      }
    }
  } catch {
    // A generic metadata-safe problem is returned below.
  }

  return {
    type: 'about:blank',
    title: 'Request failed',
    status: response.status,
  }
}

async function requestJson<T>(path: string, init: RequestInit, token?: string): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json, application/problem+json')
  if (!headers.has('X-Correlation-ID')) {
    headers.set('X-Correlation-ID', uuidV7())
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers,
  })

  if (!response.ok) {
    throw new ApiError(response.status, await problemFrom(response))
  }

  return response.json() as Promise<T>
}

export async function login(username: string, password: string): Promise<Session> {
  const body = await requestJson<Session | { data: Session }>('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  })

  const session = 'data' in body ? body.data : body
  if (
    typeof session.access_token !== 'string'
    || session.access_token === ''
    || session.token_type !== 'Bearer'
    || typeof session.expires_at !== 'string'
  ) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Invalid session response',
      status: 502,
    })
  }

  return session
}

export async function createWorkRecord(token: string, input: CreateWorkRecordInput): Promise<WorkRecord> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: WorkRecord }>('/api/v1/work-records', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `request-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)

  return body.data
}

export function listWorkRecords(token: string): Promise<WorkRecordCollection> {
  return requestJson<WorkRecordCollection>('/api/v1/work-records?limit=20', { method: 'GET' }, token)
}

export async function getWorkRecord(token: string, recordId: string): Promise<WorkRecord> {
  const body = await requestJson<{ data: WorkRecord }>(`/api/v1/work-records/${encodeURIComponent(recordId)}`, {
    method: 'GET',
  }, token)

  return body.data
}

export function listNotifications(token: string): Promise<NotificationCollection> {
  return requestJson<NotificationCollection>('/api/v1/notifications?limit=20', { method: 'GET' }, token)
}
