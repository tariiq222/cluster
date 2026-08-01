import * as generated from './generated/cluster'
import { requestInit, unwrap, unwrapWithEtag } from './http'

/*
 * Access-domain transport wrapper. The single place where feature files
 * reach the authorization/identity admin APIs through the generated Orval
 * client. Feature screens never build raw headers or call fetch directly:
 * idempotency, If-Match/ETag, CSRF, and correlation flow through
 * requestInit in `./http`.
 */

/* ------------------------------------------------------------------ */
/* Accounts (identity)                                                */
/* ------------------------------------------------------------------ */

export interface AccountCollection {
  items: generated.UserAccount[]
  next_cursor: string | null
}

export async function listAccounts(cursor?: string): Promise<AccountCollection> {
  return unwrap<AccountCollection>(
    await generated.listUserAccounts({ limit: 25, ...(cursor ? { cursor } : {}) }, requestInit(null)),
  )
}

export async function getAccount(accountId: string): Promise<generated.UserAccount & { lock_version?: number }> {
  return unwrap<generated.UserAccount & { lock_version?: number }>(
    await generated.getUserAccount(accountId, requestInit(null)),
  )
}

export async function createAccount(input: generated.UserAccountCreate, csrfToken: string | null): Promise<generated.UserAccount> {
  return unwrap<generated.UserAccount>(
    await generated.createUserAccount(input, requestInit(csrfToken, { command: true, idempotency: 'identity-account' })),
  )
}

export async function transitionAccount(
  accountId: string,
  action:
    | 'activate'
    | 'unlock'
    | 'disable'
    | 'archive'
    | 'revoke-sessions'
    | 'force-password-change',
  reason: string | undefined,
  lockVersion: number,
  csrfToken: string | null,
): Promise<generated.UserAccount> {
  return unwrap<generated.UserAccount>(
    await generated.transitionUserAccount(
      accountId,
      action,
      reason ? { reason } : undefined,
      requestInit(csrfToken, { command: true, lockVersion }),
    ),
  )
}

/*
 * Activation is delivered through the controlled channel and is NEVER
 * returned by this administrative UI. The accepted response type is narrow
 * on purpose: even if a server ever included a token property, it is
 * structurally inaccessible here — only the documented confirmation fields
 * are read back.
 */
export interface ActivationIssued {
  account_id: string
  status: 'activation_issued'
  expires_at: string
  delivery: 'controlled'
}

export async function issueAccountActivation(accountId: string, csrfToken: string | null): Promise<ActivationIssued> {
  const issued = unwrap<generated.IdentityActivationIssued>(
    await generated.issueIdentityActivation(accountId, requestInit(csrfToken, { command: true, idempotency: 'identity-activation' })),
  )
  return {
    account_id: issued.account_id,
    status: issued.status,
    expires_at: issued.expires_at,
    delivery: issued.delivery,
  }
}

/* ------------------------------------------------------------------ */
/* Authorization admin resources (roles / capabilities / assignments) */
/* ------------------------------------------------------------------ */

export type AdminResourceType =
  | 'roles'
  | 'capabilities'
  | 'role-capabilities'
  | 'role-assignments'
  | 'delegations'
  | 'classification-policies'
  | 'field-access-templates'

/* transitionAuthorizationAdminResource accepts every admin resource except
 * `capabilities` (a capability is not transitioned, only catalogued). */
export type TransitionAdminResourceType = Exclude<AdminResourceType, 'capabilities'>

export interface ResourceCollection {
  items: Array<Record<string, unknown> & { id: string; lock_version?: number }>
  next_cursor: string | null
}

export type ResourceRow = Record<string, unknown> & { id: string; lock_version?: number }

export async function listAdminResources(
  resource: AdminResourceType,
  cursor?: string,
): Promise<ResourceCollection> {
  return unwrap<ResourceCollection>(
    await generated.listAuthorizationAdminResources(resource, { limit: 25, ...(cursor ? { cursor } : {}) }, requestInit(null)),
  )
}

/* ------------------------------------------------------------------ */
/* Live wire projection normalization                                  */
/* ------------------------------------------------------------------ */

/*
 * The live authorization admin collection endpoints project raw
 * persistence rows (AuthorizationHttpGateway::serialize) while the
 * generated contract describes the documented shape. These normalizers
 * adapt the wire rows truthfully — canonical aliases only, no invented
 * data — and the server remains authoritative for what the principal may
 * actually see or do.
 */

export type NormalizedCapabilityRow = Record<string, unknown> & {
  id: string
  lock_version?: number
  code?: string
  capability_code?: string
  module_code?: string
  action?: string
  sensitivity?: string
  group_label?: string
}

export type NormalizedAssignmentRow = Record<string, unknown> & {
  id: string
  lock_version?: number
  subject_user_id?: string
  user_id?: string
  role_id?: string
  scope_type?: string
  scope_id?: string
  start_at?: string
  end_at?: string
  status?: string
  effective_status?: string
  allowed_actions: string[]
}

/*
 * Cosmetic local action list used only when the row carries no
 * `allowed_actions` property at all (collection projections never do).
 * When the server includes one — on the single-resource projection — it
 * stays authoritative. `activate` appears here for `pending` rows only
 * because the server's current action matrix does not advertise it.
 */
const LOCAL_ASSIGNMENT_ACTIONS: Record<string, string[]> = {
  pending: ['activate', 'revoke', 'expire'],
  active: ['edit', 'revoke', 'expire'],
  revoked: [],
  expired: [],
}

export function normalizeCapabilityRow(row: ResourceRow): NormalizedCapabilityRow {
  const capability = row as Record<string, unknown>
  const code = typeof capability.code === 'string' ? capability.code : undefined
  const capabilityCode = typeof capability.capability_code === 'string' ? capability.capability_code : undefined
  const groupLabel = typeof capability.group_label === 'string' ? capability.group_label : undefined
  const moduleCode = typeof capability.module_code === 'string' ? capability.module_code : undefined

  return {
    ...row,
    code: code ?? capabilityCode,
    group_label: groupLabel ?? moduleCode,
  }
}

export function normalizeAssignmentRow(row: ResourceRow): NormalizedAssignmentRow {
  const assignment = row as Record<string, unknown>
  const subjectUserId = typeof assignment.subject_user_id === 'string'
    ? assignment.subject_user_id
    : (typeof assignment.user_id === 'string' ? assignment.user_id : undefined)
  const status = typeof assignment.status === 'string' ? assignment.status : undefined
  const rawActions = Array.isArray(assignment.allowed_actions)
    ? (assignment.allowed_actions as unknown[]).filter((action): action is string => typeof action === 'string')
    : undefined
  const allowedActions = rawActions ?? (status ? (LOCAL_ASSIGNMENT_ACTIONS[status] ?? []) : [])
  const effectiveStatus = typeof assignment.effective_status === 'string'
    ? assignment.effective_status
    : status

  return {
    ...row,
    subject_user_id: subjectUserId,
    effective_status: effectiveStatus,
    allowed_actions: allowedActions,
  }
}

export async function listCapabilities(
  cursor?: string,
): Promise<{ items: NormalizedCapabilityRow[]; next_cursor: string | null }> {
  const collection = await listAdminResources('capabilities', cursor)

  return {
    items: collection.items.map((row) => normalizeCapabilityRow(row)),
    next_cursor: collection.next_cursor,
  }
}

export async function listAssignments(
  cursor?: string,
): Promise<{ items: NormalizedAssignmentRow[]; next_cursor: string | null }> {
  const collection = await listAdminResources('role-assignments', cursor)

  return {
    items: collection.items.map((row) => normalizeAssignmentRow(row)),
    next_cursor: collection.next_cursor,
  }
}

/* ------------------------------------------------------------------ */
/* Enriched role pages                                                 */
/* ------------------------------------------------------------------ */

export type RoleWithCapabilities = Record<string, unknown> & {
  id: string
  lock_version?: number
  code?: string
  capability_codes: string[]
}

/*
 * Walks the already-scoped `role-capabilities` collection (limit 100 per
 * page) following cursors safely. Repeated, non-progressing, or
 * already-seen cursors and an absurd page count abort the walk so a
 * pathological backend cannot hang the UI.
 */
async function collectRoleCapabilityRows(): Promise<Array<Record<string, unknown>>> {
  const rows: Array<Record<string, unknown>> = []
  const seenCursors = new Set<string>()
  let cursor: string | undefined
  for (let page = 0; page < 100; page += 1) {
    const collection = await listAdminResources('role-capabilities', cursor)
    rows.push(...(collection.items as Array<Record<string, unknown>>))
    const next = collection.next_cursor
    if (next === null || next === cursor || seenCursors.has(next)) break
    seenCursors.add(next)
    cursor = next
  }

  return rows
}

function roleCapabilityCodeOf(row: Record<string, unknown>): string | undefined {
  if (typeof row.capability_code === 'string') return row.capability_code
  if (typeof row.code === 'string') return row.code

  return undefined
}

/*
 * Enriches only the roles on the currently requested page with their
 * allow-set capability codes by listing the already-scoped
 * `role-capabilities` resource. Only `effect === 'allow'` rows count.
 * Callers must gate this on `authorization.assignment.read`; the
 * collection endpoint is itself guarded by that capability.
 */
export async function listRolesWithCapabilities(
  cursor?: string,
): Promise<{ items: RoleWithCapabilities[]; next_cursor: string | null }> {
  const collection = await listAdminResources('roles', cursor)
  const rows = await collectRoleCapabilityRows()
  const codesByRole = new Map<string, string[]>()
  for (const row of rows) {
    if (row.effect !== 'allow') continue
    const roleId = typeof row.role_id === 'string' ? row.role_id : undefined
    if (roleId === undefined) continue
    const code = roleCapabilityCodeOf(row)
    if (code === undefined) continue
    const codes = codesByRole.get(roleId)
    if (codes === undefined) {
      codesByRole.set(roleId, [code])
    } else if (!codes.includes(code)) {
      codes.push(code)
    }
  }

  return {
    items: collection.items.map((row) => ({
      ...row,
      capability_codes: (codesByRole.get(row.id) ?? []).sort(),
    })),
    next_cursor: collection.next_cursor,
  }
}

/*
 * Resolves the allow-set capability codes for a single role. Used by the
 * role edit sheet when the incoming row did not carry `capability_codes`;
 * returns the known (possibly empty) allow set and never guesses.
 */
export async function listRoleCapabilityCodes(roleId: string): Promise<string[]> {
  const rows = await collectRoleCapabilityRows()
  const codes: string[] = []
  for (const row of rows) {
    if (row.effect !== 'allow') continue
    if (row.role_id !== roleId) continue
    const code = roleCapabilityCodeOf(row)
    if (code === undefined || codes.includes(code)) continue
    codes.push(code)
  }

  return codes.sort()
}

export async function getAdminResource(
  resource: AdminResourceType,
  resourceId: string,
): Promise<Record<string, unknown> & { id: string; lock_version?: number }> {
  return unwrap<Record<string, unknown> & { id: string; lock_version?: number }>(
    await generated.getAuthorizationAdminResource(resource, resourceId, requestInit(null)),
  )
}

export async function createAdminResource(
  resource: AdminResourceType,
  input: generated.AuthorizationAdminCreate,
  csrfToken: string | null,
  idempotency = 'authorization-admin',
): Promise<Record<string, unknown> & { id: string; lock_version?: number }> {
  return unwrap<Record<string, unknown> & { id: string; lock_version?: number }>(
    await generated.createAuthorizationAdminResource(
      resource,
      input,
      requestInit(csrfToken, { command: true, idempotency }),
    ),
  )
}

/*
 * Role assignment creation payload. The generated `AuthorizationAdminCreate`
 * shape is broader than the live role-assignment endpoint needs; this narrow
 * type matches exactly what the backend gateway consumes (subject, role,
 * scope, window) and keeps `code` out of the wire payload.
 */
export interface AssignmentCreateInput {
  subject_user_id: string
  role_id: string
  scope_type: 'cluster' | 'facility' | 'unit'
  scope_id: string
  start_at: string
  end_at?: string
}

export async function createAssignment(
  input: AssignmentCreateInput,
  csrfToken: string | null,
): Promise<Record<string, unknown> & { id: string; lock_version?: number }> {
  return createAdminResource(
    'role-assignments',
    { resource_type: 'role_assignment', ...input } as unknown as generated.AuthorizationAdminCreate,
    csrfToken,
    'authorization-assignment',
  )
}

export async function updateAdminResource(
  resource: AdminResourceType,
  resourceId: string,
  patch: generated.AuthorizationAdminPatch,
  lockVersion: number,
  csrfToken: string | null,
): Promise<Record<string, unknown> & { id: string; lock_version?: number }> {
  return unwrap<Record<string, unknown> & { id: string; lock_version?: number }>(
    await generated.updateAuthorizationAdminResource(
      resource,
      resourceId,
      patch,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function transitionAdminResource(
  resource: TransitionAdminResourceType,
  resourceId: string,
  action: 'activate' | 'revoke' | 'expire' | 'publish' | 'clone',
  body: Record<string, unknown> | undefined,
  lockVersion: number,
  csrfToken: string | null,
  idempotency = 'authorization-admin-action',
): Promise<Record<string, unknown> & { id: string; lock_version?: number }> {
  return unwrap<Record<string, unknown> & { id: string; lock_version?: number }>(
    await generated.transitionAuthorizationAdminResource(
      resource,
      resourceId,
      action,
      body,
      requestInit(csrfToken, { command: true, idempotency, lockVersion }),
    ),
  )
}

/* ------------------------------------------------------------------ */
/* Assignment scope targets (on-demand search only)                   */
/* ------------------------------------------------------------------ */

export interface ScopeTargetSearch {
  scopeType: generated.ListAuthorizationAssignmentScopeTargetsScopeType
  parentScopeType?: generated.ListAuthorizationAssignmentScopeTargetsParentScopeType
  parentScopeId?: string
  search?: string
  cursor?: string
}

export async function searchScopeTargets(params: ScopeTargetSearch): Promise<generated.AssignmentScopeTargetCollection> {
  return unwrap<generated.AssignmentScopeTargetCollection>(
    await generated.listAuthorizationAssignmentScopeTargets(
      {
        scope_type: params.scopeType,
        ...(params.parentScopeType ? { parent_scope_type: params.parentScopeType } : {}),
        ...(params.parentScopeId ? { parent_scope_id: params.parentScopeId } : {}),
        ...(params.search ? { search: params.search } : {}),
        ...(params.cursor ? { cursor: params.cursor } : {}),
        limit: 25,
      },
      requestInit(null),
    ),
  )
}

/* ------------------------------------------------------------------ */
/* Decision explanation (diagnostics)                                 */
/* ------------------------------------------------------------------ */

export async function explainDecision(decisionId: string): Promise<generated.AccessDecisionSchema> {
  return unwrap<generated.AccessDecisionSchema>(
    await generated.explainAccessDecision(decisionId, requestInit(null)),
  )
}

/* ------------------------------------------------------------------ */
/* Bootstrap                                                          */
/* ------------------------------------------------------------------ */

export type BootstrapStatus = 'bootstrap_pending' | 'completed' | 'expired'

export interface BootstrapState {
  status: BootstrapStatus
  version: number | null
  allowedCapabilities: string[]
  expiresAt: string | null
  completedAt: string | null
  completedByUserId: string | null
}

type BootstrapWire = Partial<generated.AuthorizationBootstrap> & {
  state?: 'pending' | 'complete' | 'expired'
  completed_at?: string | null
  completed_by_user_id?: string | null
  version?: number
  status?: 'bootstrap_pending' | 'completed' | 'expired'
  expires_at?: string | null
}

/*
 * Single normalization for both the GET projection and the completion
 * response: live `{ state: pending|complete, version, ... }` and documented
 * `{ status: bootstrap_pending|completed|expired, ... }` map identically.
 * The ETag/version is preserved either way.
 */
function normalizeBootstrap(value: BootstrapWire, etag: number | null): BootstrapState {
  const status: BootstrapStatus = value.status
    ?? (value.state === 'pending'
      ? 'bootstrap_pending'
      : value.state === 'complete'
        ? 'completed'
        : value.state === 'expired'
          ? 'expired'
          : 'completed')
  const version = value.version ?? etag
  return {
    status,
    version,
    allowedCapabilities: value.allowed_capabilities ?? [],
    expiresAt: value.expires_at ?? null,
    completedAt: value.completed_at ?? null,
    completedByUserId: value.completed_by_user_id ?? null,
  }
}

/*
 * The GET ETag/version is preserved either way.
 */
export async function fetchBootstrapState(): Promise<BootstrapState> {
  const { value, etag } = await unwrapWithEtag<BootstrapWire>(
    await generated.getAuthorizationBootstrap(requestInit(null)),
  )
  return normalizeBootstrap(value, etag)
}

/*
 * Only the implemented `/authorization/bootstrap/complete` operation is used
 * here. The planned `completeAuthorizationBootstrap` (POST to
 * `/authorization/bootstrap`) is intentionally never called.
 */
export async function completeBootstrap(
  reason: string,
  version: number,
  csrfToken: string | null,
): Promise<BootstrapState> {
  const { value, etag } = await unwrapWithEtag<BootstrapWire>(
    await generated.bootstrapComplete(
      { reason },
      requestInit(csrfToken, { command: true, idempotency: 'authorization-bootstrap-complete', lockVersion: version }),
    ),
  )
  return normalizeBootstrap(value, etag)
}
