import { ApiError, type ProblemDetails } from '../../api'

export type AuthorizationResource = 'roles' | 'capabilities' | 'role-assignments' | 'delegations'
export type AuthorizationItem = Record<string, unknown> & { id?: string; name?: string; code?: string; status?: string }

async function problem(response: Response): Promise<ProblemDetails> {
  try {
    const body = await response.json() as Partial<ProblemDetails>
    return { type: typeof body.type === 'string' ? body.type : 'about:blank', title: typeof body.title === 'string' ? body.title : 'Request failed', status: response.status, detail: typeof body.detail === 'string' ? body.detail : undefined }
  } catch {
    return { type: 'about:blank', title: 'Request failed', status: response.status }
  }
}

async function request<T>(path: string, token: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json, application/problem+json')
  headers.set('Authorization', `Bearer ${token}`)
  headers.set('X-Correlation-ID', crypto.randomUUID())
  const response = await fetch(`/api/v1${path}`, { ...init, credentials: 'same-origin', headers })
  if (!response.ok) throw new ApiError(response.status, await problem(response))
  return await response.json() as T
}

function collectionBody(body: unknown): AuthorizationItem[] {
  if (!body || typeof body !== 'object') return []
  const candidate = body as { data?: unknown; items?: unknown }
  const data = candidate.data && typeof candidate.data === 'object' ? candidate.data as { items?: unknown } : candidate
  return Array.isArray(data.items) ? data.items.filter((item): item is AuthorizationItem => !!item && typeof item === 'object') : []
}

export async function listAuthorization(resource: AuthorizationResource, token: string): Promise<AuthorizationItem[]> {
  return collectionBody(await request<unknown>(`/authorization/${resource}?limit=50`, token))
}

export async function listSupervisoryRelationships(token: string): Promise<AuthorizationItem[]> {
  return collectionBody(await request<unknown>('/organization/supervisory-relationships?limit=50', token))
}

export async function explainAccessDecision(decisionId: string, token: string): Promise<AuthorizationItem> {
  const body = await request<unknown>(`/authorization/access-decisions/${encodeURIComponent(decisionId)}/explanation`, token)
  return body && typeof body === 'object' && 'data' in body && typeof (body as { data?: unknown }).data === 'object'
    ? (body as { data: AuthorizationItem }).data
    : body as AuthorizationItem
}
