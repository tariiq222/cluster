import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import * as generated from '../api/generated/cluster'
import { requestInit, unwrap } from './http'
import { useSessionToken } from '../app/session-context'
import { usePrincipal } from '../app/principal-context'

/* Shared patterns: csrf from session, scope epoch from principal for scoped reads. */
function useAuth() {
  const csrfToken = useSessionToken()
  const { scopeEpoch, effectiveScope } = usePrincipal()
  return { csrfToken, scopeEpoch, scopeId: effectiveScope?.scopeId ?? undefined }
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

export function usePeople() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['people'] as const,
    queryFn: async () => unwrap(await generated.listPeople({ limit: 100 }, requestInit(null))),
  })
}

export function useAssignments() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['assignments'] as const,
    queryFn: async () => unwrap(await generated.listAssignments({ limit: 100 }, requestInit(null))),
  })
}

/* ============ Accounts ============ */

export function useUserAccounts() {
  return useQuery<generated.CollectionResponse>({
    queryKey: ['user-accounts'] as const,
    queryFn: async () => unwrap(await generated.listUserAccounts({ limit: 100 }, requestInit(null))),
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
  return useQuery<generated.CollectionResponse>({
    queryKey: ['reports'] as const,
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
