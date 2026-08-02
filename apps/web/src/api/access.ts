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

/*
 * People (organization) cursor-paginated list. Same bounded semantics as
 * the rest of the access wrappers: never eager all-pages, never silent
 * truncation. The picker that drives account creation consumes one page
 * at a time with explicit load-more.
 */
export interface PersonCollection {
  items: generated.Person[]
  next_cursor: string | null
}

export async function listPeopleCursor(cursor?: string): Promise<PersonCollection> {
  return unwrap<PersonCollection>(
    await generated.listPeople(
      { limit: 25, ...(cursor ? { cursor } : {}) },
      requestInit(null),
    ),
  )
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

/*
 * Server is authoritative: only the wire's `allowed_actions` is exposed
 * here, never a locally guessed list. When the row omits the property
 * (collection projections do), the screen renders no transition controls.
 */
export function normalizeAssignmentRow(row: ResourceRow): NormalizedAssignmentRow {
  const assignment = row as Record<string, unknown>
  const subjectUserId = typeof assignment.subject_user_id === 'string'
    ? assignment.subject_user_id
    : (typeof assignment.user_id === 'string' ? assignment.user_id : undefined)
  const status = typeof assignment.status === 'string' ? assignment.status : undefined
  const allowedActions = Array.isArray(assignment.allowed_actions)
    ? (assignment.allowed_actions as unknown[]).filter((action): action is string => typeof action === 'string')
    : []
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

/*
 * Walks every cursor page of the capability catalog so a full-page
 * capability picker can group and search the complete set instead of
 * silently truncating to the first bounded page. The catalog is a
 * curated reference list (not a user-generated collection), so the walk
 * stays bounded in practice; failures reject the whole load so the
 * screen can never offer a silently incomplete catalog.
 */
export async function listAllCapabilities(): Promise<NormalizedCapabilityRow[]> {
  const items: NormalizedCapabilityRow[] = []
  let cursor: string | undefined
  for (;;) {
    const page = await listCapabilities(cursor)
    items.push(...page.items)
    if (!page.next_cursor) break
    cursor = page.next_cursor
  }
  return items
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

/* ------------------------------------------------------------------ */
/* Shared role-capability association walk (ACC-03 cache)              */
/* ------------------------------------------------------------------ */

export type RoleCapabilityWalk = {
  rows: Array<Record<string, unknown>>
  codesByRole: Map<string, string[]>
}

export type RoleCapabilityPageFetcher = (cursor?: string) => Promise<ResourceCollection>

export interface RoleCapabilityCache {
  get: () => Promise<RoleCapabilityWalk>
  invalidate: () => void
  getEpoch: () => number
}

class RoleCapabilityCacheInvalidatedError extends Error {
  constructor() {
    super('Role capability cache invalidated')
    this.name = 'RoleCapabilityCacheInvalidatedError'
  }
}

function roleCapabilityCodeOf(row: Record<string, unknown>): string | undefined {
  if (typeof row.capability_code === 'string') return row.capability_code
  if (typeof row.code === 'string') return row.code

  return undefined
}

/*
 * Factory for the scoped `role-capability` association walk cache.
 *
 * Properties:
 *  - Concurrent callers within the same cache generation observe the
 *    same in-flight walk and resolve together (one walk per
 *    consumer × page, not per consumer).
 *  - Successful walks are cached; subsequent calls in the same
 *    generation return the cached `RoleCapabilityWalk`.
 *  - Rejected walks are NEVER cached: the next caller triggers a fresh
 *    walk from page 1.
 *  - `invalidate()` bumps the generation; any walk in flight under the
 *    previous generation aborts cleanly on the next epoch check so its
 *    result cannot poison the new generation's cache.
 *  - When `getContextKey` is provided, the cache additionally treats a
 *    context-key mismatch as a cache miss — even if the in-cache epoch
 *    matches — so a previous context's cached walk can never be served
 *    to a new context (ACC-03-SCOPE-CORRECTION).
 *  - The walk honours the existing 100-page safety cap and
 *    repeated-cursor guard so a pathological backend cannot hang the UI.
 *
 * The factory takes a page fetcher so the cache can be unit-tested with
 * a mock fetcher (see `apps/web/src/features/accounts/AccessScreen.test.tsx`)
 * without having to stub the internal module-level `listAdminResources`
 * reference inside `access.ts`.
 */
export function createRoleCapabilityCache(
  fetcher: RoleCapabilityPageFetcher,
  getContextKey?: () => string | null,
): RoleCapabilityCache {
  let cached: RoleCapabilityWalk | null = null
  let cachedEpoch = -1
  let cachedContextKey: string | null | undefined = undefined
  let inflight: Promise<RoleCapabilityWalk> | null = null
  let epoch = 0

  async function performWalk(myEpoch: number): Promise<RoleCapabilityWalk> {
    const rows: Array<Record<string, unknown>> = []
    const seenCursors = new Set<string>()
    let cursor: string | undefined
    for (let page = 0; page < 100; page += 1) {
      if (myEpoch !== epoch) {
        throw new RoleCapabilityCacheInvalidatedError()
      }
      const collection = await fetcher(cursor)
      if (myEpoch !== epoch) {
        throw new RoleCapabilityCacheInvalidatedError()
      }
      rows.push(...(collection.items as Array<Record<string, unknown>>))
      const next = collection.next_cursor
      if (next === null || next === cursor || seenCursors.has(next)) break
      seenCursors.add(next)
      cursor = next
    }

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

    return { rows, codesByRole }
  }

  function isCacheHit(): boolean {
    if (!cached || cachedEpoch !== epoch) return false
    if (getContextKey === undefined) return true
    return cachedContextKey === getContextKey()
  }

  return {
    get: () => {
      if (isCacheHit()) return Promise.resolve(cached as RoleCapabilityWalk)
      if (!inflight) {
        const myEpoch = epoch
        const myContextKey = getContextKey?.() ?? null
        const myPromise = performWalk(myEpoch)
          .then((result) => {
            if (myEpoch === epoch) {
              cached = result
              cachedEpoch = myEpoch
              cachedContextKey = myContextKey
            }
            if (inflight === myPromise) {
              inflight = null
            }
            return result
          })
          .catch((error) => {
            if (inflight === myPromise) {
              inflight = null
            }
            throw error
          })
        inflight = myPromise
        return myPromise
      }
      return inflight
    },
    invalidate: () => {
      epoch += 1
      cached = null
      cachedEpoch = -1
      cachedContextKey = undefined
      inflight = null
    },
    getEpoch: () => epoch,
  }
}

/*
 * Production singleton: every consumer in the access workspace shares
 * this cache, so a single walk per scope/epoch generation services the
 * RolesTab resource page, the labels query, the assignment sheet role
 * picker, and the role edit sheet — exactly one walk instead of one per
 * consumer × page.
 *
 * The cache is bound to the module-level `currentRoleCapabilityContextKey`
 * via the optional `getContextKey` argument. The factory treats a
 * context-key mismatch as a cache miss even if the in-cache epoch
 * matches, so a previous identity or scope can never have its cached
 * walk served to a new context — see ACC-03-SCOPE-CORRECTION.
 */
export const roleCapabilityCache: RoleCapabilityCache = createRoleCapabilityCache(
  (cursor) => listAdminResources('role-capabilities', cursor),
  () => currentRoleCapabilityContextKey,
)

/*
 * Module-level facade so callers don't need to know about the singleton.
 * `invalidateRoleCapabilityCache()` MUST be called whenever the
 * principal's effective scope changes (different scope = a different
 * scoped `role-capabilities` collection) or after any successful
 * mutation that creates, updates, clones, or archives a role's
 * capability set. The hook `useRoleCapabilityCacheScope()` in
 * `apps/web/src/api/hooks.ts` performs the scope-driven invalidation
 * for the whole workspace via `setRoleCapabilityContext`.
 */
export function invalidateRoleCapabilityCache(): void {
  roleCapabilityCache.invalidate()
}

export function getRoleCapabilityEpoch(): number {
  return roleCapabilityCache.getEpoch()
}

/* ------------------------------------------------------------------ */
/* Cross-context isolation (ACC-03-SCOPE-CORRECTION)                   */
/* ------------------------------------------------------------------ */

/*
 * Stable cache-context key for the role-capability walk. The walk is
 * authorization-scoped, so any change in the authenticated identity or
 * the effective scope generation/identity MUST produce a different key;
 * a same-key comparison is the only condition under which a previously
 * cached walk may be served.
 *
 * Composition (deliberately conservative):
 *
 *   - `userId` and `csrfToken` from the authenticated session. A session
 *     rotation (different `csrfToken` after a re-auth) or an outright
 *     identity change (different `userId`) flips the key, so the next
 *     consumer cannot read the previous identity's associations.
 *
 *   - `scopeEpoch` from the principal context. The epoch is bumped the
 *     moment `selectScope` starts, before `effectiveScope` is refetched;
 *     including it here means the key changes immediately on scope
 *     selection rather than only after the refetch resolves.
 *
 *   - `effectiveScope.scopeType` and `effectiveScope.scopeId`. Defensive
 *     duplicate of the scope identity in case a future code path flips
 *     the scope without bumping `scopeEpoch` (the principal context does
 *     not, but a third party could).
 *
 * The `\u0000` separator prevents two keys whose fields differ only by
 * concatenation order from colliding.
 */
export function computeRoleCapabilityContextKey(args: {
  userId: string | null
  csrfToken: string | null
  scopeEpoch: number
  effectiveScope: { scopeType: string; scopeId: string } | null
}): string {
  const identity = `${args.userId ?? ''}\u0000${args.csrfToken ?? ''}`
  const scope = args.effectiveScope
    ? `${args.effectiveScope.scopeType}\u0000${args.effectiveScope.scopeId}\u0000${args.scopeEpoch}`
    : `\u0000\u0000${args.scopeEpoch}`
  return `${identity}|${scope}`
}

/*
 * Module-level "current cache context" key. Set by `setRoleCapabilityContext`
 * (idempotent, synchronous). The role-capability singleton reads this
 * key on every `get()` and treats a mismatch as a cache miss even if
 * `cachedEpoch` would otherwise match — belt-and-suspenders against any
 * path that mutates `walkEpoch` without going through
 * `setRoleCapabilityContext`.
 */
let currentRoleCapabilityContextKey: string | null = null

export function getRoleCapabilityContextKey(): string | null {
  return currentRoleCapabilityContextKey
}

/*
 * Synchronize the module-level cache with the caller's current context.
 *
 *  - Idempotent: same key on repeated calls is a single string equality
 *    check; the cache is left untouched.
 *  - Synchronous: runs during the calling component's render, BEFORE any
 *    consumer (the role-capability reads triggered by the same render
 *    pass or its effects) can observe the cached walk. There is no
 *    first-render no-op: the very first call already installs a context,
 *    and every subsequent render either matches (no-op) or bumps the
 *    cache (synchronous).
 *  - Invalidate, never "merge": a context transition discards the
 *    previous context's cached walk AND any in-flight walk for the
 *    previous context. The in-flight rejection is enforced by the
 *    `myEpoch !== epoch` check inside `performWalk` plus the
 *    `cachedEpoch === epoch` check inside `get()`.
 *
 * This is the load-bearing primitive that prevents the ACC-03 unmount/
 * change/remount defect: there is no component-local ref to seed, so an
 * unmount/remount cannot silently observe a previous scope's cached
 * walk.
 */
export function setRoleCapabilityContext(contextKey: string): void {
  if (currentRoleCapabilityContextKey === contextKey) return
  currentRoleCapabilityContextKey = contextKey
  roleCapabilityCache.invalidate()
}

/*
 * Test-only: resets the singleton's cached walk, its internal context key,
 * and the module-level current context key. Tests should call this in
 * `beforeEach` to avoid cross-test pollution — the singleton outlives a
 * single `describe` block within a worker.
 */
export function __resetRoleCapabilityCacheForTests(): void {
  currentRoleCapabilityContextKey = null
  roleCapabilityCache.invalidate()
}

/*
 * Internal cache binding: each cache created via `createRoleCapabilityCache`
 * tracks its own `cachedContextKey` and treats a mismatch against the
 * current context (read via the optional `getContextKey` argument) as a
 * miss. No external mirror is needed because `setRoleCapabilityContext`
 * invalidates the cache on every context change, so the in-cache
 * `cachedContextKey` is always equal to the current key (or
 * `undefined` after a context change before the next walk).
 */

/*
 * Enriches only the roles on the currently requested page with their
 * allow-set capability codes by listing the already-scoped
 * `role-capabilities` resource. Only `effect === 'allow'` rows count.
 * Callers must gate this on `authorization.assignment.read`; the
 * collection endpoint is itself guarded by that capability.
 *
 * The association walk is shared with `listRoleCapabilityCodes` and any
 * other enriched consumer (resource page, labels query, picker) through
 * `roleCapabilityCache` so concurrent and sequential consumers in the
 * same scope/epoch generation observe a single walk instead of one walk
 * per consumer × page.
 */
export async function listRolesWithCapabilities(
  cursor?: string,
): Promise<{ items: RoleWithCapabilities[]; next_cursor: string | null }> {
  const [collection, walk] = await Promise.all([
    listAdminResources('roles', cursor),
    roleCapabilityCache.get(),
  ])

  return {
    items: collection.items.map((row) => ({
      ...row,
      /*
       * `slice()` keeps the cached array immutable — the cached
       * `codesByRole` map is shared across consumers, so we must never
       * mutate it through `Array.prototype.sort`.
       */
      capability_codes: (walk.codesByRole.get(row.id) ?? []).slice().sort(),
    })),
    next_cursor: collection.next_cursor,
  }
}

/*
 * Resolves the allow-set capability codes for a single role. Used by the
 * role edit sheet when the incoming row did not carry `capability_codes`;
 * returns the known (possibly empty) allow set and never guesses. Reuses
 * the shared walk so it costs nothing extra after any other enriched
 * consumer has primed the cache.
 */
export async function listRoleCapabilityCodes(roleId: string): Promise<string[]> {
  const walk = await roleCapabilityCache.get()
  return (walk.codesByRole.get(roleId) ?? []).slice().sort()
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
  /*
   * Optional abort signal forwarded to the wrapper fetch. The generated
   * client does not expose a signal parameter; this is purely advisory and
   * the monotonic-generation guard in ScopeTargetCombobox is authoritative.
   */
  signal?: AbortSignal
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
      params.signal
        ? { ...requestInit(null), signal: params.signal }
        : requestInit(null),
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
