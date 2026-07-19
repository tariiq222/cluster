import { ApiError, type ProblemDetails } from '../api'

export type Day2Entity = Record<string, unknown> & { id?: string; lock_version?: number; version?: string; etag?: string }
export type Collection<T> = { items: T[]; next_cursor?: string | null }
export type WorkflowAction = 'publish' | 'test' | 'approve' | 'sign'
export type TaskAction = 'start' | 'return' | 'return-completion' | 'submit-completion' | 'complete'

function uuidV7(): string { const b = new Uint8Array(16); crypto.getRandomValues(b); let t = Date.now(); for (let i = 5; i >= 0; i -= 1) { b[i] = t & 255; t = Math.floor(t / 256) } b[6] = (b[6] & 15) | 112; b[8] = (b[8] & 63) | 128; const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join(''); return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}` }

async function call<T>(token: string, path: string, init: RequestInit = {}, lockVersion?: number): Promise<T> {
  const correlation = uuidV7(); const headers = new Headers(init.headers); headers.set('Accept', 'application/json, application/problem+json'); headers.set('X-Correlation-ID', correlation); if (token) headers.set('Authorization', `Bearer ${token}`); if (lockVersion !== undefined) headers.set('If-Match', `"${lockVersion}"`)
  const response = await fetch(`/api/v1${path}`, { ...init, credentials: 'same-origin', headers }); if (!response.ok) { let problem: ProblemDetails = { type: 'about:blank', title: 'Request failed', status: response.status }; try { problem = await response.json() as ProblemDetails } catch {} throw new ApiError(response.status, problem) }
  const body = await response.json() as { data?: T; items?: unknown[]; next_cursor?: string | null }; const value = body.data ?? body
  if (value && typeof value === 'object' && !Array.isArray(value)) { const entity = value as Day2Entity; const etag = response.headers.get('ETag'); if (etag && !entity.lock_version) entity.lock_version = Number(etag.replaceAll('"', '')) }
  return value as T
}

const command = (body?: unknown): RequestInit => ({ method: 'POST', headers: { ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), 'Idempotency-Key': uuidV7() }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
export const listWorkDefinitions = (token: string) => call<Collection<Day2Entity>>(token, '/work-definitions?limit=50')
export const createWorkDefinition = (token: string, input: Record<string, unknown>) => call<Day2Entity>(token, '/work-definitions', command(input))
export const createWorkDefinitionVersion = (token: string, id: string, input: Record<string, unknown>) => call<Day2Entity>(token, `/work-definitions/${encodeURIComponent(id)}/versions`, command(input))
export const publishWorkDefinitionVersion = (token: string, id: string, lock?: number) => call<Day2Entity>(token, `/work-definition-versions/${encodeURIComponent(id)}/publish`, command(), lock)
export const createWorkflowDefinition = (token: string, input: Record<string, unknown>) => call<{ definition: Day2Entity; version: Day2Entity }>(token, '/workflow/definitions', command(input))
export const createWorkflowVersion = (token: string, id: string, input: Record<string, unknown>) => call<Day2Entity>(token, `/workflow/definitions/${encodeURIComponent(id)}/versions`, command(input))
export const transitionWorkflowVersion = (token: string, id: string, action: WorkflowAction, lock?: number) => call<Day2Entity>(token, `/workflow/versions/${encodeURIComponent(id)}/${action}`, command(), lock)
export const publishWorkflowVersion = (token: string, id: string, lock?: number) => transitionWorkflowVersion(token, id, 'publish', lock)
export const createRequest = (token: string, input: Record<string, unknown>) => {
  const init = command(input)
  init.headers = { ...(init.headers as Record<string, string>), 'X-Day3-Acceptance': '1' }
  return call<Day2Entity>(token, '/work-records', init)
}
export const submitRequest = (token: string, id: string, lock?: number) => call<Day2Entity>(token, `/work-records/${encodeURIComponent(id)}/submit`, command(), lock)
export const returnRequest = (token: string, id: string, lock?: number) => call<Day2Entity>(token, `/work-records/${encodeURIComponent(id)}/return`, command(), lock)
export const completeRequest = (token: string, id: string, lock?: number) => call<Day2Entity>(token, `/work-records/${encodeURIComponent(id)}/complete`, command(), lock)
export const startWorkflow = (token: string, input: Record<string, unknown>) => call<Day2Entity>(token, '/workflow/instances', command(input))
export const getWorkflowInstance = (token: string, id: string) => call<{ instance: Day2Entity; steps: Day2Entity[] }>(token, `/workflow/instances/${encodeURIComponent(id)}`)
export const createTaskFromStep = (token: string, stepId: string, title?: string) => call<Day2Entity>(token, `/tasks/from-step/${encodeURIComponent(stepId)}`, command(title ? { title } : undefined))
export const listTasks = (token: string) => call<Collection<Day2Entity>>(token, '/tasks?limit=50')
export const transitionTask = (token: string, id: string, action: TaskAction, lock?: number) => call<Day2Entity>(token, `/tasks/${encodeURIComponent(id)}/${action}`, command(), lock)
export const linkDocument = (token: string, recordId: string, documentId: string) => call<Day2Entity>(token, `/work-records/${encodeURIComponent(recordId)}/documents`, command({ document_id: documentId, relation_type: 'attachment' }))
export const searchRecords = (token: string, query: string) => call<{ items: Day2Entity[]; total: number }>(token, `/search?q=${encodeURIComponent(query)}`)
export const getReport = (token: string, reportId: string) => call<{ items: Day2Entity[]; total: number }>(token, `/reports/${encodeURIComponent(reportId)}`)
export const getDashboard = (token: string, dashboardId: string) => call<{ items: Day2Entity[]; total: number }>(token, `/dashboards/${encodeURIComponent(dashboardId)}`)
export const getNotifications = (token: string) => call<Collection<Day2Entity>>(token, '/notifications?limit=20')
