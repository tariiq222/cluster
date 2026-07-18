import { ApiError, type ProblemDetails } from '../api'

export type Day2Entity = Record<string, unknown> & { id?: string; version?: string; etag?: string }
export type WorkflowAction = 'test' | 'approve' | 'sign' | 'publish'
export type TaskAction = 'start' | 'submit-completion' | 'complete' | 'return-completion'

function uuidV7(): string {
  const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); let time = Date.now()
  for (let i = 5; i >= 0; i -= 1) { bytes[i] = time & 0xff; time = Math.floor(time / 256) }
  bytes[6] = (bytes[6] & 0x0f) | 0x70; bytes[8] = (bytes[8] & 0x3f) | 0x80
  const h = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

async function call<T>(token: string, path: string, init: RequestInit = {}, version?: string): Promise<T> {
  const correlation = uuidV7(); const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json, application/problem+json'); headers.set('X-Correlation-ID', correlation)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  if (version) headers.set('If-Match', version)
  const response = await fetch(`/api/v1${path}`, { ...init, credentials: 'same-origin', headers })
  if (!response.ok) {
    let problem: ProblemDetails = { type: 'about:blank', title: 'Request failed', status: response.status }
    try { problem = await response.json() as ProblemDetails } catch { /* metadata-safe fallback */ }
    throw new ApiError(response.status, problem)
  }
  const body = await response.json() as { data?: T }
  return (body.data ?? body) as T
}

export const listWorkDefinitions = (token: string) => call<Day2Entity[]>(token, '/work-definitions?limit=50')
export const createWorkDefinition = (token: string, input: Record<string, unknown>) => call<Day2Entity>(token, '/work-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
export const createWorkflowDefinition = (token: string, input: Record<string, unknown>) => call<Day2Entity>(token, '/workflow/definitions', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
export const createWorkflowVersion = (token: string, definitionId: string, input: Record<string, unknown>) => call<Day2Entity>(token, `/workflow/definitions/${encodeURIComponent(definitionId)}/versions`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
export const transitionWorkflowVersion = (token: string, versionId: string, action: WorkflowAction, version?: string) => call<Day2Entity>(token, `/workflow/versions/${encodeURIComponent(versionId)}/${action}`, { method: 'POST', headers: { 'Idempotency-Key': uuidV7() } }, version)
export const createRequest = (token: string, input: Record<string, unknown>) => call<Day2Entity>(token, '/work-records', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuidV7() }, body: JSON.stringify(input) })
export const submitRequest = (token: string, id: string, version?: string) => call<Day2Entity>(token, `/work-records/${encodeURIComponent(id)}/submit`, { method: 'POST', headers: { 'Idempotency-Key': uuidV7() } }, version)
export const listTasks = (token: string) => call<Day2Entity[]>(token, '/tasks?limit=50')
export const transitionTask = (token: string, id: string, action: TaskAction, version?: string) => call<Day2Entity>(token, `/tasks/${encodeURIComponent(id)}/${action}`, { method: 'POST', headers: { 'Idempotency-Key': uuidV7() } }, version)
