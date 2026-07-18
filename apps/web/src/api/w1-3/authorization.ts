import { ApiError, type ProblemDetails } from '../../api'

export type AuthorizationResource = 'roles' | 'capabilities' | 'role-assignments' | 'delegations'
export type AuthorizationItem = Record<string, unknown> & { id?: string; name?: string; code?: string; status?: string }
export type AuthorizationAdminCreate = {
  resource_type: 'role' | 'capability' | 'role_assignment' | 'delegation'
  code: string
  name?: string
  subject_user_id?: string
  role_id?: string
  scope_type?: 'cluster' | 'facility' | 'unit' | 'record_set'
  scope_id?: string
  start_at?: string
  end_at?: string
  policy_document?: Record<string, unknown>
}
export type SupervisoryRelationshipCreate = { source_unit_id: string; target_unit_id: string; relationship_type: string; start_at: string; end_at?: string; capability_codes: string[] }

export function uuidV7(): string {
  const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); let timestamp = Date.now()
  for (let index = 5; index >= 0; index -= 1) { bytes[index] = timestamp & 0xff; timestamp = Math.floor(timestamp / 256) }
  bytes[6] = (bytes[6] & 0x0f) | 0x70; bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

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
  headers.set('X-Correlation-ID', uuidV7())
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

export async function createAuthorizationResource(input: AuthorizationAdminCreate, token: string): Promise<AuthorizationItem> {
  const body = await request<unknown>('/authorization/' + (input.resource_type === 'role_assignment' ? 'role-assignments' : input.resource_type === 'delegation' ? 'delegations' : 'roles'), token, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
  return body && typeof body === 'object' && 'data' in body && typeof (body as { data?: unknown }).data === 'object' ? (body as { data: AuthorizationItem }).data : body as AuthorizationItem
}

export async function createRoleAssignment(input: Omit<AuthorizationAdminCreate, 'resource_type'>, token: string) { return createAuthorizationResource({ ...input, resource_type: 'role_assignment' }, token) }
export async function createDelegation(input: Omit<AuthorizationAdminCreate, 'resource_type'>, token: string) { return createAuthorizationResource({ ...input, resource_type: 'delegation' }, token) }
export async function createSupervisoryRelationship(input: SupervisoryRelationshipCreate, token: string): Promise<AuthorizationItem> {
  const body = await request<unknown>('/organization/supervisory-relationships', token, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
  return body && typeof body === 'object' && 'data' in body && typeof (body as { data?: unknown }).data === 'object' ? (body as { data: AuthorizationItem }).data : body as AuthorizationItem
}

export async function explainAccessDecision(decisionId: string, token: string): Promise<AuthorizationItem> {
  const body = await request<unknown>(`/authorization/access-decisions/${encodeURIComponent(decisionId)}/explanation`, token)
  return body && typeof body === 'object' && 'data' in body && typeof (body as { data?: unknown }).data === 'object'
    ? (body as { data: AuthorizationItem }).data
    : body as AuthorizationItem
}
