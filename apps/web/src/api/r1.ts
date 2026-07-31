import * as generated from './generated/cluster'
import { getCurrentPrincipal, listMyScopes, selectMyScope, type ScopeSelection, type ScopeSelectionUpdate } from './generated/cluster'
import { ApiError, parseStrongEtag, requestInit, unwrap, uuidV7 } from './http'

export type R1Entity = Record<string, unknown> & { id?: string; lock_version?: number }
export type R1Collection<T = R1Entity> = { items: T[]; next_cursor?: string | null; total: number }
export type AuthorizationResource =
  | 'roles'
  | 'capabilities'
  | 'role-assignments'
  | 'delegations'
  | 'classification-policies'
  | 'field-access-templates'
export type AuthorizationItem = R1Entity & { name?: string; code?: string; status?: string }
export type Day2Entity = R1Entity
export type WorkflowAction = 'publish' | 'test' | 'approve' | 'sign'
export type AllowedAction = string
export type FieldAccessState = 'hidden' | 'masked' | 'readonly' | 'editable'
export type FieldAccess = Record<string, FieldAccessState>
export type AccessProjection = {
  decision_id: string | null
  allowed_actions: AllowedAction[]
  field_access: FieldAccess
}
export type AccessContext = generated.AccessContextSchema
export type AccessDecisionRequest = generated.AccessDecisionRequest
export type AccessDecision = generated.AccessDecisionResponse
export type AuthorizedWorkRecord = generated.WorkRecordSchema & AccessProjection
export type AuthorizationAdminPatch = generated.AuthorizationAdminPatch
export type AuthorizationAdminCreate = generated.AuthorizationAdminCreate
export type AuthorizationRoleAssignment = generated.AuthorizationRoleAssignment
export type AuthorizationAdminResource = AuthorizationResource
export { parseStrongEtag, uuidV7 }

export type ScopeSelectionSnapshot = {
  selection: ScopeSelection
  lockVersion: number | null
}

export async function getMyAccessContext(token: string): Promise<AccessContext> {
  const response = await getCurrentPrincipal(requestInit(token))
  return unwrap<AccessContext>(response)
}

export async function listMyAccessScopes(token: string): Promise<ScopeSelectionSnapshot> {
  const response = await listMyScopes(requestInit(token))
  return {
    selection: unwrap<ScopeSelection>(response),
    lockVersion: parseStrongEtag(response.headers.get('ETag')),
  }
}

export async function selectMyAccessScope(token: string, input: ScopeSelectionUpdate, lockVersion: number): Promise<ScopeSelectionSnapshot> {
  const response = await selectMyScope(input, requestInit(token, { command: true, lockVersion }))
  return {
    selection: unwrap<ScopeSelection>(response),
    lockVersion: parseStrongEtag(response.headers.get('ETag')),
  }
}

export const listWorkDefinitions = async (token: string) => unwrap<R1Collection>(await generated.listWorkDefinitions({ limit: 50 }, requestInit(token)))
export const createWorkDefinition = async (token: string, input: generated.WorkDefinitionCreate) => unwrap<R1Entity>(await generated.createWorkDefinition(input, requestInit(token, { command: true })))
export const createWorkDefinitionVersion = async (token: string, id: string, input: generated.WorkDefinitionVersionCreate) => unwrap<R1Entity>(await generated.createWorkDefinitionVersion(id, input, requestInit(token, { command: true })))
export const listWorkDefinitionVersions = async (token: string, id: string) => unwrap<R1Collection>(await generated.listWorkDefinitionVersions(id, { limit: 50 }, requestInit(token)))
export const publishWorkDefinitionVersion = async (token: string, id: string, lock = 1) => unwrap<R1Entity>(await generated.publishWorkDefinitionVersion(id, requestInit(token, { command: true, lockVersion: lock })))

export const listWorkflowDefinitions = async (token: string) => unwrap<R1Collection>(await generated.listWorkflowDefinitions({ limit: 50 }, requestInit(token)))
export const createWorkflowDefinition = async (token: string, input: generated.WorkflowDefinitionCreate) => unwrap<{ definition: R1Entity; version: R1Entity }>(await generated.createWorkflowDefinition(input, requestInit(token, { command: true })))
export const createWorkflowVersion = async (token: string, id: string, input: generated.WorkflowVersionCreate) => unwrap<R1Entity>(await generated.createWorkflowVersion(id, input, requestInit(token, { command: true })))
export const listWorkflowVersions = async (token: string, id: string) => unwrap<R1Collection>(await generated.listWorkflowVersions(id, { limit: 50 }, requestInit(token)))
export const transitionWorkflowVersion = async (token: string, id: string, action: WorkflowAction, lock = 1) => unwrap<R1Entity>(await generated.transitionWorkflowVersion(id, action, undefined, requestInit(token, { command: true, lockVersion: lock })))
export const publishWorkflowVersion = (token: string, id: string, lock = 1) => transitionWorkflowVersion(token, id, 'publish', lock)
export const listWorkflowInstances = async (token: string) => unwrap<R1Collection>(await generated.listWorkflowInstances({ limit: 50 }, requestInit(token)))
export const startWorkflow = async (token: string, input: generated.WorkflowStart) => unwrap<R1Entity>(await generated.startWorkflow(input, requestInit(token, { command: true })))
export const getWorkflowInstance = async (token: string, id: string) => unwrap<{ instance: R1Entity; steps: R1Entity[] }>(await generated.getWorkflowInstance(id, requestInit(token)))

export const createRequest = async (token: string, input: Record<string, unknown>) => unwrap<R1Entity>(await generated.createWorkRecord(input as unknown as generated.WorkRecordCreate, requestInit(token, { command: true })))
export const listR1WorkRecords = async (token: string) => unwrap<R1Collection>(await generated.listWorkRecords({ limit: 50 }, requestInit(token)))
export const listAuthorizedWorkRecords = async (token: string): Promise<R1Collection<AuthorizedWorkRecord>> => unwrap<R1Collection<AuthorizedWorkRecord>>(await generated.listWorkRecords({ limit: 50 }, requestInit(token)))
export const getR1WorkRecord = async (token: string, id: string) => unwrap<AuthorizedWorkRecord>(await generated.getWorkRecord(id, requestInit(token)))
export const getAuthorizedWorkRecord = getR1WorkRecord
export const transitionRequest = async (token: string, id: string, action: 'submit' | 'complete', lock = 1) => unwrap<R1Entity>(await generated.transitionWorkRecord(id, action, requestInit(token, { command: true, lockVersion: lock })))
export const submitRequest = (token: string, id: string, lock = 1) => transitionRequest(token, id, 'submit', lock)
export const completeRequest = (token: string, id: string, lock = 1) => transitionRequest(token, id, 'complete', lock)
export type GovernedWorkRecordAction = 'cancel' | 'archive'

async function transitionGovernedWorkRecord(token: string, id: string, action: GovernedWorkRecordAction, reason: string, lock = 1) {
  const normalizedReason = reason.trim()
  if (!normalizedReason) throw new ApiError(400, { type: 'about:blank', title: 'A reason is required', status: 400 })
  const body = { reason: normalizedReason }
  return unwrap<R1Entity>(await (action === 'cancel' ? generated.cancelWorkRecord(id, body, requestInit(token, { command: true, lockVersion: lock })) : generated.archiveWorkRecord(id, body, requestInit(token, { command: true, lockVersion: lock }))))
}

export const cancelRequest = (token: string, id: string, reason: string, lock = 1) => transitionGovernedWorkRecord(token, id, 'cancel', reason, lock)
export const archiveRequest = (token: string, id: string, reason: string, lock = 1) => transitionGovernedWorkRecord(token, id, 'archive', reason, lock)
export const linkDocument = async (token: string, recordId: string, documentId: string) => unwrap<R1Entity>(await generated.linkWorkRecordDocument(recordId, { document_id: documentId, relation_type: 'attachment' }, requestInit(token, { command: true })))
export async function getDocumentDownloadUrl(token: string, documentId: string): Promise<string> {
  const response = await generated.downloadDocument(documentId, { ...requestInit(token), redirect: 'manual' })
  if (response.status >= 400) unwrap(response)
  const location = response.headers.get('Location')
  if (!location) throw new ApiError(409, { type: 'about:blank', title: 'Document download is not ready', status: 409 })
  return location
}

export const searchRecords = async (token: string, query: string, filters?: { type?: string; status?: string }) => unwrap<R1Collection>(await generated.search({ q: query, limit: 50, ...(filters?.type ? { type: filters.type } : {}), ...(filters?.status ? { status: filters.status } : {}) }, requestInit(token)))
export const listReports = async (token: string) => unwrap<R1Collection>(await generated.listReports({ limit: 50 }, requestInit(token)))
export const getReport = async (token: string, reportId: string, scopeId?: string) => unwrap<R1Collection>(await generated.getReport(reportId, scopeId ? { scope_id: scopeId } : undefined, requestInit(token)))
export const requestReportExport = async (token: string, reportId: string, format: 'csv' | 'json' = 'csv') => unwrap<R1Entity>(await generated.createReportExport(reportId, { format }, requestInit(token, { command: true })))
export const getReportExport = async (token: string, exportId: string) => unwrap<R1Entity>(await generated.getExport(exportId, requestInit(token)))
export const getDashboard = async (token: string, dashboardId: string, scopeId?: string) => unwrap<R1Collection>(await generated.getDashboard(dashboardId, scopeId ? { scope_id: scopeId } : undefined, requestInit(token)))
export const listDashboards = async (token: string) => unwrap<R1Collection>(await generated.listDashboards({ limit: 50 }, requestInit(token)))
export const getNotifications = async (token: string) => unwrap<R1Collection>(await generated.listMyNotifications({ limit: 50 }, requestInit(token)))
export const markNotificationRead = async (token: string, notificationId: string) => unwrap<R1Entity>(await generated.markNotificationRead(notificationId, requestInit(token, { command: true })))

function requireLockVersion(lockVersion: number | undefined): number {
  if (lockVersion === undefined) throw new ApiError(400, { type: 'about:blank', title: 'A lock version is required', status: 400 })
  return lockVersion
}

export async function listRoles(token: string, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationRole[]> {
  return unwrap<{ items: generated.AuthorizationRole[] }>(await generated.listAuthorizationAdminResources('roles', filters, requestInit(token))).items
}
export async function getRole(token: string, roleId: string): Promise<generated.AuthorizationRole> {
  return unwrap<generated.AuthorizationRole>(await generated.getAuthorizationAdminResource('roles', roleId, requestInit(token)))
}
export async function listCapabilities(token: string, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationCapability[]> {
  return unwrap<{ items: generated.AuthorizationCapability[] }>(await generated.listAuthorizationAdminResources('capabilities', filters, requestInit(token))).items
}
export async function listRoleAssignments(token: string, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationRoleAssignment[]> {
  return unwrap<{ items: generated.AuthorizationRoleAssignment[] }>(await generated.listAuthorizationAdminResources('role-assignments', filters, requestInit(token))).items
}
export type AssignmentScopeTarget = generated.AssignmentScopeTarget
export type AssignmentScopeTargetCollection = generated.AssignmentScopeTargetCollection & { lock_version?: number }
export type AssignmentScopeType = generated.ListAuthorizationAssignmentScopeTargetsScopeType
export type AssignmentScopeParentType = generated.ListAuthorizationAssignmentScopeTargetsParentScopeType
export const AssignmentScopeType = generated.ListAuthorizationAssignmentScopeTargetsScopeType
export const AssignmentScopeParentType = generated.ListAuthorizationAssignmentScopeTargetsParentScopeType

/**
 * Catalog of manageable assignment scope targets for the current principal.
 * Distinct from the generic `/authorization/{adminResource}` family: the server
 * returns the resources the UI is allowed to pick (`scope_id` + bilingual
 * labels), never a free-text UUID prompt. `record_set` is intentionally absent
 * from the manageable enum and surfaces as a 422 problem when requested.
 *
 * Accepts the typed `AssignmentScopeTargetQuery` (camelCase, screen-friendly
 * keys) and forwards the snake_case wire form to the generated client. The
 * returned collection carries `lock_version` when the response has an ETag,
 * which is the same `unwrap` contract every other read-side wrapper honors.
 */
export type AssignmentScopeTargetQuery = {
  scopeType: AssignmentScopeType
  parentScopeType?: AssignmentScopeParentType
  parentScopeId?: string
  search?: string
  cursor?: string
  limit?: number
}
export async function listAssignmentScopeTargets(
  token: string,
  query: AssignmentScopeTargetQuery,
): Promise<AssignmentScopeTargetCollection> {
  const params: generated.ListAuthorizationAssignmentScopeTargetsParams = {
    scope_type: query.scopeType,
    ...(query.parentScopeType ? { parent_scope_type: query.parentScopeType } : {}),
    ...(query.parentScopeId ? { parent_scope_id: query.parentScopeId } : {}),
    ...(query.search ? { search: query.search } : {}),
    ...(query.cursor ? { cursor: query.cursor } : {}),
    ...(query.limit !== undefined ? { limit: query.limit } : {}),
  }
  return unwrap<AssignmentScopeTargetCollection>(
    await generated.listAuthorizationAssignmentScopeTargets(params, requestInit(token)),
  )
}



export async function createRole(token: string, input: generated.AuthorizationAdminCreate & { capability_codes?: string[] }): Promise<generated.AuthorizationRole> {
  return unwrap<generated.AuthorizationRole>(await generated.createAuthorizationAdminResource('roles', input, requestInit(token, { command: true, idempotency: 'authorization-role' })))
}
export async function updateRole(token: string, roleId: string, patch: generated.AuthorizationAdminPatch & { capability_codes?: string[] }, lockVersion: number): Promise<generated.AuthorizationRole> {
  return unwrap<generated.AuthorizationRole>(await generated.updateAuthorizationAdminResource('roles', roleId, patch, requestInit(token, { mutation: true, lockVersion: requireLockVersion(lockVersion) })))
}
export async function archiveRole(token: string, roleId: string, lockVersion: number): Promise<generated.AuthorizationRole> {
  return updateRole(token, roleId, { status: 'archived' }, requireLockVersion(lockVersion))
}
export async function cloneRoleFromSystemRole(token: string, sourceRoleId: string, overrides: generated.RoleCloneInput | undefined, lockVersion: number): Promise<generated.AuthorizationRole> {
  return unwrap<generated.AuthorizationRole>(await generated.transitionAuthorizationAdminResource('roles', sourceRoleId, 'clone', overrides, requestInit(token, { command: true, idempotency: 'authorization-role-clone', lockVersion: requireLockVersion(lockVersion) })))
}
export async function createRoleAssignment(token: string, input: generated.AuthorizationAdminCreate): Promise<generated.AuthorizationRoleAssignment> {
  return unwrap<generated.AuthorizationRoleAssignment>(await generated.createAuthorizationAdminResource('role-assignments', input, requestInit(token, { command: true, idempotency: 'authorization-role-assignment' })))
}
export async function updateRoleAssignment(token: string, assignmentId: string, patch: generated.AuthorizationAdminPatch, lockVersion: number): Promise<generated.AuthorizationRoleAssignment> {
  return unwrap<generated.AuthorizationRoleAssignment>(await generated.updateAuthorizationAdminResource('role-assignments', assignmentId, patch, requestInit(token, { mutation: true, lockVersion: requireLockVersion(lockVersion) })))
}
async function transitionRoleAssignment(token: string, assignmentId: string, action: 'revoke' | 'expire', lockVersion: number): Promise<generated.AuthorizationRoleAssignment> {
  return unwrap<generated.AuthorizationRoleAssignment>(await generated.transitionAuthorizationAdminResource('role-assignments', assignmentId, action, undefined, requestInit(token, { command: true, idempotency: `authorization-role-assignment-${action}`, lockVersion: requireLockVersion(lockVersion) })))
}
export function revokeRoleAssignment(token: string, assignmentId: string, lockVersion: number): Promise<generated.AuthorizationRoleAssignment> {
  return transitionRoleAssignment(token, assignmentId, 'revoke', lockVersion)
}
export function expireRoleAssignment(token: string, assignmentId: string, lockVersion: number): Promise<generated.AuthorizationRoleAssignment> {
  return transitionRoleAssignment(token, assignmentId, 'expire', lockVersion)
}
export async function revokeRoleCapability(token: string, roleId: string, capabilityId: string, lockVersion: number): Promise<R1Entity> {
  return unwrap<R1Entity>(await generated.transitionAuthorizationAdminResource('role-capabilities', `${roleId}:${capabilityId}`, 'revoke', undefined, requestInit(token, { command: true, idempotency: 'authorization-role-capability-revoke', lockVersion: requireLockVersion(lockVersion) })))
}

export async function listAuthorization(resource: AuthorizationResource, token: string): Promise<AuthorizationItem[]> {
  return (await unwrap<R1Collection<AuthorizationItem>>(await generated.listAuthorizationAdminResources(resource, { limit: 50 }, requestInit(token)))).items ?? []
}
export async function listSupervisoryRelationships(token: string): Promise<AuthorizationItem[]> {
  return (await unwrap<R1Collection<AuthorizationItem>>(await generated.listSupervisoryRelationships({ limit: 50 }, requestInit(token)))).items ?? []
}
export async function createDelegation(input: Record<string, unknown>, token: string) {
  return unwrap<AuthorizationItem>(await generated.createAuthorizationAdminResource('delegations', { ...input, resource_type: 'delegation' } as generated.AuthorizationAdminCreate, requestInit(token, { command: true })))
}
export async function createSupervisoryRelationship(input: generated.SupervisoryRelationshipCreate, token: string) {
  return unwrap<AuthorizationItem>(await generated.createSupervisoryRelationship(input, requestInit(token, { command: true })))
}
export async function explainAccessDecision(decisionId: string, token: string): Promise<AccessDecision> {
  return unwrap<AccessDecision>(await generated.explainAccessDecision(decisionId, requestInit(token)))
}
export const getAuthorizationAudit = explainAccessDecision
export async function getAuthorizationAdminResource(resource: AuthorizationResource, resourceId: string, token: string) {
  return unwrap<AuthorizationItem>(await generated.getAuthorizationAdminResource(resource, resourceId, requestInit(token)))
}
export async function updateAuthorizationAdminResource(resource: AuthorizationResource, resourceId: string, input: AuthorizationAdminPatch, token: string, lockVersion: number) {
  return unwrap<AuthorizationItem>(await generated.updateAuthorizationAdminResource(resource, resourceId, input, requestInit(token, { mutation: true, lockVersion: requireLockVersion(lockVersion) })))
}
export type AuthorizationTransitionAction = 'activate' | 'revoke' | 'expire' | 'publish' | 'clone'
export type AuthorizationReasonAction = 'activate' | 'publish'
const AUTHORIZATION_REASON_ACTIONS: Record<AuthorizationTransitionAction, boolean> = {
  activate: true,
  publish: true,
  revoke: false,
  expire: false,
  clone: false,
}
export async function transitionAuthorizationAdminResource(
  resource: Extract<AuthorizationResource, 'roles' | 'role-assignments' | 'delegations' | 'classification-policies' | 'field-access-templates'>,
  resourceId: string,
  action: AuthorizationTransitionAction,
  reason: string,
  token: string,
  lockVersion: number,
) {
  const requiresReason = AUTHORIZATION_REASON_ACTIONS[action]
  const trimmedReason = reason.trim()
  if (requiresReason && !trimmedReason) throw new ApiError(400, { type: 'about:blank', title: 'A reason is required', status: 400 })
  const body = requiresReason ? { reason: trimmedReason } : undefined
  return unwrap<AuthorizationItem>(await generated.transitionAuthorizationAdminResource(resource, resourceId, action, body, requestInit(token, { command: true, lockVersion: requireLockVersion(lockVersion) })))
}
export async function simulateAccessDecision(input: AccessDecisionRequest, token: string): Promise<AccessDecision> {
  return unwrap<AccessDecision>(await generated.decideAccess(input, requestInit(token, { mutation: true })))
}
export const decideAccess = simulateAccessDecision