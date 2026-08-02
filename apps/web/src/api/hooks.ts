import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import * as generated from '../api/generated/cluster'
import { requestInit, unwrap } from './http'
import {
  computeRoleCapabilityContextKey,
  listAccounts,
  listPeopleCursor,
  setRoleCapabilityContext,
  type AccountCollection as AccessAccountCollection,
} from './access'
import { useSession, useSessionToken } from '../app/session-context'
import { usePrincipal } from '../app/principal-context'

/* Shared patterns: csrf from session, scope epoch from principal for scoped reads. */
function useAuth() {
  const csrfToken = useSessionToken()
  const { scopeEpoch, effectiveScope } = usePrincipal()
  return { csrfToken, scopeEpoch, scopeId: effectiveScope?.scopeId ?? undefined }
}

/*
 * Synchronize the shared `role-capability` association walk with the
 * caller's cache context on EVERY render. The walk is scoped to the
 * authenticated identity and the effective scope, so a different
 * context must NEVER observe a previous context's cached associations.
 *
 * Design (ACC-03-SCOPE-CORRECTION):
 *
 *  - The hook computes a stable context key from the session token /
 *    user id and the principal's effective scope epoch + identity.
 *  - It then calls `setRoleCapabilityContext(key)` — a module-level,
 *    idempotent, synchronous operation that invalidates the cache if and
 *    only if the key changed.
 *  - The call is made during render, BEFORE any consumer of the same
 *    render pass can read the cache. There is no first-render no-op and
 *    no component-local ref: an unmount/change/remount cycle that
 *    mounts with a different context invalidates the previous context's
 *    cached walk on the very first render of the new mount.
 *  - A same-key re-render is a single string equality check; the cache
 *    is never re-invalidated on every render.
 *
 * Multiple consumers calling this hook observe the same module-level
 * cache; the cache itself additionally checks the context key on every
 * `get()` so a previous context's cached walk can never leak even if
 * `setRoleCapabilityContext` were bypassed.
 */
export function useRoleCapabilityCacheScope(): void {
  const session = useSession()
  const { scopeEpoch, effectiveScope } = usePrincipal()
  const contextKey = computeRoleCapabilityContextKey({
    userId: session.session.userId,
    csrfToken: session.session.csrfToken,
    scopeEpoch,
    effectiveScope: effectiveScope
      ? { scopeType: effectiveScope.scopeType, scopeId: effectiveScope.scopeId }
      : null,
  })
  setRoleCapabilityContext(contextKey)
}

/* ============ Tasks ============ */

export function useTasksList(filters: generated.ListTasksParams) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['tasks', filters, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listTasks(filters, requestInit(null))),
  })
}

export function useTask(taskId: string) {
  return useQuery<generated.EntityResponse>({
    queryKey: ['task', taskId] as const,
    queryFn: async () => unwrap(await generated.getTask(taskId, requestInit(null))),
  })
}

export function useTaskComments(taskId: string) {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['task-comments', taskId] as const,
    queryFn: async () => unwrap(await generated.listTaskComments(taskId, undefined, requestInit(null))),
  })
}

export function useTaskMutations() {
  const queryClient = useQueryClient()
  const { csrfToken } = useAuth()
  const invalidateTask = (taskId: string) => {
    void queryClient.invalidateQueries({ queryKey: ['task', taskId] })
    void queryClient.invalidateQueries({ queryKey: ['tasks'] })
    void queryClient.invalidateQueries({ queryKey: ['task-comments', taskId] })
  }
  return {
    create: useMutation({
      mutationFn: async (input: Parameters<typeof generated.createTask>[0]) =>
        unwrap(await generated.createTask(input, requestInit(csrfToken, { command: true, idempotency: 'task-create' }))),
      onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['tasks'] }),
    }),
    update: useMutation({
      mutationFn: async ({ taskId, input, lockVersion }: { taskId: string; input: Parameters<typeof generated.updateTask>[1]; lockVersion: number }) =>
        unwrap(await generated.updateTask(taskId, input, requestInit(csrfToken, { mutation: true, lockVersion }))),
      onSuccess: (_, vars) => invalidateTask(vars.taskId),
    }),
    transition: useMutation({
      mutationFn: async ({
        taskId,
        action,
        input,
        lockVersion,
      }: {
        taskId: string
        action: Parameters<typeof generated.transitionTask>[1]
        input: Parameters<typeof generated.transitionTask>[2]
        lockVersion: number
      }) => unwrap(await generated.transitionTask(taskId, action, input, requestInit(csrfToken, { command: true, lockVersion }))),
      onSuccess: (_, vars) => invalidateTask(vars.taskId),
    }),
    addComment: useMutation({
      mutationFn: async ({ taskId, input }: { taskId: string; input: Parameters<typeof generated.addTaskComment>[1] }) =>
        unwrap(await generated.addTaskComment(taskId, input, requestInit(csrfToken, { command: true }))),
      onSuccess: (_, vars) => invalidateTask(vars.taskId),
    }),
    addParticipant: useMutation({
      mutationFn: async ({ taskId, input, lockVersion }: { taskId: string; input: Parameters<typeof generated.addTaskParticipant>[1]; lockVersion: number }) =>
        unwrap(await generated.addTaskParticipant(taskId, input, requestInit(csrfToken, { command: true, lockVersion }))),
      onSuccess: (_, vars) => invalidateTask(vars.taskId),
    }),
  }
}

/* ============ Documents ============ */

export function useDocumentsList(filters: generated.ListDocumentsParams) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['documents', filters, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listDocuments(filters, requestInit(null))),
  })
}

export function useDocument(documentId: string) {
  return useQuery<generated.EntityResponse>({
    queryKey: ['document', documentId] as const,
    queryFn: async () => unwrap(await generated.getDocument(documentId, requestInit(null))),
  })
}

export function useDocumentVersions(documentId: string) {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['document-versions', documentId] as const,
    queryFn: async () => unwrap(await generated.listDocumentVersions(documentId, { limit: 50 }, requestInit(null))),
  })
}

export function useDocumentLinks(documentId: string) {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['document-links', documentId] as const,
    queryFn: async () => unwrap(await generated.listDocumentLinks(documentId, { limit: 50 }, requestInit(null))),
  })
}

/* ============ Work records ============ */

export function useWorkRecordsList(filters: generated.ListWorkRecordsParams) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['work-records', filters, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listWorkRecords(filters, requestInit(null))),
  })
}

/* ============ Organization ============ */

export function useCluster() {
  return useQuery<generated.EntityResponse | null>({
    queryKey: ['cluster'] as const,
    queryFn: async () => {
      try {
        return unwrap(await generated.getCluster(requestInit(null)))
      } catch (error) {
        if ((error as { status?: number })?.status === 404) return null
        throw error
      }
    },
  })
}

export function useFacilities() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['facilities'] as const,
    queryFn: async () => unwrap(await generated.listFacilities({ limit: 100 }, requestInit(null))),
  })
}

export function useOrganizationUnits() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['organization-units'] as const,
    queryFn: async () => unwrap(await generated.listOrganizationUnits({ limit: 100 }, requestInit(null))),
  })
}

export function usePositions() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['positions'] as const,
    queryFn: async () => unwrap(await generated.listPositions({ limit: 100 }, requestInit(null))),
  })
}

export function useJobTitles() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['job-titles'] as const,
    queryFn: async () => unwrap(await generated.listJobTitles({ limit: 100 }, requestInit(null))),
  })
}

/*
 * People cursor page. The create-account picker uses an explicit load-more
 * rather than a giant eager fetch, so it never silently stops at the first
 * page when there are more selectable employees available.
 */
export function usePeople(cursor?: string) {
  return useQuery<{
    items: generated.Person[]
    next_cursor: string | null
  }>({
    queryKey: ['people', cursor ?? null] as const,
    queryFn: async () => listPeopleCursor(cursor),
  })
}

export function useAssignments() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['assignments'] as const,
    queryFn: async () => unwrap(await generated.listAssignments({ limit: 100 }, requestInit(null))),
  })
}

/* ============ Accounts ============ */

export function useUserAccounts(cursor?: string) {
  return useQuery<AccessAccountCollection>({
    queryKey: ['user-accounts', cursor ?? null] as const,
    queryFn: async () => listAccounts(cursor),
  })
}

/* ============ Platform settings ============ */

export function usePlatformSettingsVersions() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['platform-settings-versions'] as const,
    queryFn: async () => unwrap(await generated.listPlatformSettingsVersions({ limit: 100 }, requestInit(null))),
  })
}

export function usePlatformOperationsOverview() {
  return useQuery<generated.EntityResponse>({
    queryKey: ['platform-operations-overview'] as const,
    queryFn: async () => unwrap(await generated.getPlatformOperationsOverview(requestInit(null))),
  })
}

export function usePlatformHealth() {
  return useQuery<generated.EntityResponse>({
    queryKey: ['platform-health'] as const,
    queryFn: async () => unwrap(await generated.getPlatformHealth(requestInit(null))),
  })
}

/* ============ Reports / Audit / Search / Notifications ============ */

export function useReportsList() {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['reports', scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listReports({ limit: 50 }, requestInit(null))),
  })
}

export function useAuditEvents(filters: generated.ListAuditEventsParams) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['audit-events', filters, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listAuditEvents(filters, requestInit(null))),
  })
}

export function useNotificationsList(limit = 20) {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['notifications', limit] as const,
    queryFn: async () => unwrap(await generated.listMyNotifications({ limit }, requestInit(null))),
  })
}

/* ============ Roles (governance) ============ */

export function useRolesList() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['roles'] as const,
    queryFn: async () => unwrap(await generated.listAuthorizationAdminResources('roles', { limit: 100 }, requestInit(null))),
  })
}

export function useCapabilitiesList() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['capabilities'] as const,
    queryFn: async () => unwrap(await generated.listAuthorizationAdminResources('capabilities', { limit: 100 }, requestInit(null))),
  })
}

/* ============ Search ============ */

export function useSearch(query: string, enabled: boolean) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['search', query, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.search({ q: query, limit: 10 }, requestInit(null))),
    enabled: enabled && query.trim().length >= 2,
  })
}

/* ============ Organization: temporary assignments ============ */

export function useTemporaryAssignments() {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['temporary-assignments', scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listTemporaryAssignments({ limit: 100, organization_unit_id: '*' }, requestInit(null))),
  })
}

/* ============ Organization: supervisory relationships ============ */

export function useSupervisoryRelationships() {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['supervisory-relationships', scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.listSupervisoryRelationships({ limit: 100 }, requestInit(null))),
  })
}
