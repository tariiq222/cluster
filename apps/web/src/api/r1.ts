import { ApiError, type ProblemDetails } from '../api'
import * as generated from './generated/r1-screens'

export type R1Entity = Record<string, unknown> & { id?: string; lock_version?: number }
export type R1Collection<T = R1Entity> = { items: T[]; next_cursor?: string | null; total: number }
export type AuthorizationResource = 'roles' | 'capabilities' | 'role-assignments' | 'delegations'
export type AuthorizationItem = R1Entity & { name?: string; code?: string; status?: string }
export type Day2Entity = R1Entity
export type WorkflowAction = 'publish' | 'test' | 'approve' | 'sign'
export type TaskAction = 'start' | 'return-completion' | 'submit-completion' | 'complete'

export function uuidV7(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
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

type GeneratedResponse = { status: number; data: unknown; headers: Headers }

function options(token: string, command = false, lockVersion?: number): RequestInit {
  const headers: Record<string, string> = {
    Accept: 'application/json, application/problem+json',
    Authorization: `Bearer ${token}`,
    'X-Correlation-ID': uuidV7(),
  }
  if (command) headers['Idempotency-Key'] = uuidV7()
  if (lockVersion !== undefined) headers['If-Match'] = `"${lockVersion}"`
  return { credentials: 'same-origin', headers }
}

function unwrap<T>(response: GeneratedResponse): T {
  if (response.status >= 400) {
    const value = response.data && typeof response.data === 'object' ? response.data as Partial<ProblemDetails> : {}
    throw new ApiError(response.status, {
      type: typeof value.type === 'string' ? value.type : 'about:blank',
      title: typeof value.title === 'string' ? value.title : 'Request failed',
      status: response.status,
      detail: typeof value.detail === 'string' ? value.detail : undefined,
    })
  }
  const body = response.data as { data?: T }
  const value = body && typeof body === 'object' && 'data' in body ? body.data : response.data
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    const entity = value as R1Entity
    const etag = response.headers.get('ETag')
    if (etag && entity.lock_version === undefined) entity.lock_version = Number(etag.replaceAll('"', ''))
  }
  return value as T
}

export const listWorkDefinitions = async (token: string) => unwrap<R1Collection>(await generated.listWorkDefinitions({ limit: 50 }, options(token)))
export const createWorkDefinition = async (token: string, input: generated.WorkDefinitionCreate) => unwrap<R1Entity>(await generated.createWorkDefinition(input, options(token, true)))
export const createWorkDefinitionVersion = async (token: string, id: string, input: generated.WorkDefinitionVersionCreate) => unwrap<R1Entity>(await generated.createWorkDefinitionVersion(id, input, options(token, true)))
export const listWorkDefinitionVersions = async (token: string, id: string) => unwrap<R1Collection>(await generated.listWorkDefinitionVersions(id, { limit: 50 }, options(token)))
export const publishWorkDefinitionVersion = async (token: string, id: string, lock = 1) => unwrap<R1Entity>(await generated.publishWorkDefinitionVersion(id, options(token, true, lock)))

export const listWorkflowDefinitions = async (token: string) => unwrap<R1Collection>(await generated.listWorkflowDefinitions({ limit: 50 }, options(token)))
export const createWorkflowDefinition = async (token: string, input: generated.WorkflowDefinitionCreate) => unwrap<{ definition: R1Entity; version: R1Entity }>(await generated.createWorkflowDefinition(input, options(token, true)))
export const createWorkflowVersion = async (token: string, id: string, input: generated.WorkflowVersionCreate) => unwrap<R1Entity>(await generated.createWorkflowVersion(id, input, options(token, true)))
export const listWorkflowVersions = async (token: string, id: string) => unwrap<R1Collection>(await generated.listWorkflowVersions(id, { limit: 50 }, options(token)))
export const transitionWorkflowVersion = async (token: string, id: string, action: WorkflowAction, lock = 1) => unwrap<R1Entity>(await generated.transitionWorkflowVersion(id, action, undefined, options(token, true, lock)))
export const publishWorkflowVersion = (token: string, id: string, lock = 1) => transitionWorkflowVersion(token, id, 'publish', lock)
export const listWorkflowInstances = async (token: string) => unwrap<R1Collection>(await generated.listWorkflowInstances({ limit: 50 }, options(token)))
export const startWorkflow = async (token: string, input: generated.WorkflowStart) => unwrap<R1Entity>(await generated.startWorkflow(input, options(token, true)))
export const getWorkflowInstance = async (token: string, id: string) => unwrap<{ instance: R1Entity; steps: R1Entity[] }>(await generated.getWorkflowInstance(id, options(token)))

export const createTaskFromStep = async (token: string, stepId: string, title?: string) => unwrap<R1Entity>(await generated.createTaskFromStep(stepId, title ? { title } : undefined, options(token, true)))
export const listTasks = async (token: string) => unwrap<R1Collection>(await generated.listTasks({ limit: 50 }, options(token)))
export const transitionTask = async (token: string, id: string, action: TaskAction, lock = 1) => unwrap<R1Entity>(await generated.transitionTask(id, action, undefined, options(token, true, lock)))

export const createRequest = async (token: string, input: Record<string, unknown>) => unwrap<R1Entity>(await generated.createWorkRecord(input as unknown as generated.WorkRecordCreate, { ...options(token, true), headers: { ...options(token, true).headers, 'X-Day3-Acceptance': '1' } }))
export const listR1WorkRecords = async (token: string) => unwrap<R1Collection>(await generated.listWorkRecords({ limit: 50 }, options(token)))
export const getR1WorkRecord = async (token: string, id: string) => unwrap<R1Entity>(await generated.getWorkRecord(id, options(token)))
export const transitionRequest = async (token: string, id: string, action: 'submit' | 'return' | 'complete' | 'complete-submission', lock = 1) => unwrap<R1Entity>(await generated.transitionWorkRecord(id, action, options(token, true, lock)))
export const submitRequest = (token: string, id: string, lock = 1) => transitionRequest(token, id, 'submit', lock)
export const returnRequest = (token: string, id: string, lock = 1) => transitionRequest(token, id, 'return', lock)
export const completeRequest = (token: string, id: string, lock = 1) => transitionRequest(token, id, 'complete', lock)
export const linkDocument = async (token: string, recordId: string, documentId: string) => unwrap<R1Entity>(await generated.linkWorkRecordDocument(recordId, { document_id: documentId, relation_type: 'attachment' }, options(token, true)))
export async function getDocumentDownloadUrl(token: string, documentId: string): Promise<string> {
  const response = await generated.downloadDocument(documentId, { ...options(token), redirect: 'manual' })
  if (response.status >= 400) unwrap(response)
  const location = response.headers.get('Location')
  if (!location) throw new ApiError(409, { type: 'about:blank', title: 'Document download is not ready', status: 409 })
  return location
}

export const searchRecords = async (token: string, query: string) => unwrap<R1Collection>(await generated.search({ q: query, limit: 50 }, options(token)))
export const getReport = async (token: string, reportId: string, scopeId?: string) => unwrap<R1Collection>(await generated.getReport(reportId, scopeId ? { scope_id: scopeId } : undefined, options(token)))
export const requestReportExport = async (token: string, reportId: string, format: 'csv' | 'xlsx' | 'pdf' = 'csv') => unwrap<R1Entity>(await generated.createReportExport(reportId, { format }, options(token, true)))
export const getReportExport = async (token: string, exportId: string) => unwrap<R1Entity>(await generated.getExport(exportId, options(token)))
export const getDashboard = async (token: string, dashboardId: string, scopeId?: string) => unwrap<R1Collection>(await generated.getDashboard(dashboardId, scopeId ? { scope_id: scopeId } : undefined, options(token)))
export const getNotifications = async (token: string) => unwrap<R1Collection>(await generated.listMyNotifications({ limit: 50 }, options(token)))
export const markNotificationRead = async (token: string, notificationId: string) => unwrap<R1Entity>(await generated.markNotificationRead(notificationId, options(token, true)))

export async function listAuthorization(resource: AuthorizationResource, token: string): Promise<AuthorizationItem[]> {
  return (await unwrap<R1Collection<AuthorizationItem>>(await generated.listAuthorizationAdminResources(resource, { limit: 50 }, options(token)))).items ?? []
}
export async function listSupervisoryRelationships(token: string): Promise<AuthorizationItem[]> {
  return (await unwrap<R1Collection<AuthorizationItem>>(await generated.listSupervisoryRelationships({ limit: 50 }, options(token)))).items ?? []
}
export async function createRoleAssignment(input: Record<string, unknown>, token: string) {
  return unwrap<AuthorizationItem>(await generated.createAuthorizationAdminResource('role-assignments', { ...input, resource_type: 'role_assignment' } as generated.AuthorizationAdminCreate, options(token, true)))
}
export async function createDelegation(input: Record<string, unknown>, token: string) {
  return unwrap<AuthorizationItem>(await generated.createAuthorizationAdminResource('delegations', { ...input, resource_type: 'delegation' } as generated.AuthorizationAdminCreate, options(token, true)))
}
export async function createSupervisoryRelationship(input: generated.SupervisoryRelationshipCreate, token: string) {
  return unwrap<AuthorizationItem>(await generated.createSupervisoryRelationship(input, options(token, true)))
}
export async function explainAccessDecision(decisionId: string, token: string) {
  return unwrap<AuthorizationItem>(await generated.explainAccessDecision(decisionId, options(token)))
}
