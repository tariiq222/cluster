import { useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import * as generated from '../../api/generated/cluster'
import type { AuditExportDescriptor, Entity } from '../../api/generated/cluster'
import { requestInit, unwrap } from '../../api/http'
import { useSession, useSessionToken } from '../../app/session-context'

/*
 * Per-user session-local export tracking.
 *
 * The API contract only supports get-by-ID (no list-exports endpoint), so the
 * Exports tab can only know about exports created during the current browser
 * session. The tracker is a module-scope store keyed by `ownerUserId`:
 * registrations survive tab switches within the workspace and plain SPA
 * navigation, and each tracked export is polled individually through the
 * matching generated endpoint until it reaches a terminal status.
 *
 * Every read is scoped to the current session's user ID. A user change exposes
 * an empty tracker snapshot immediately: the store holds immutable per-user
 * arrays, reads for a user with no entries return the shared EMPTY snapshot,
 * and polling is disabled for any export whose owner is not the current user.
 */

export type ExportKind = 'report' | 'audit'

export interface TrackedExport {
  id: string
  kind: ExportKind
  name: string
  format: string
  createdAt: string
  ownerUserId: string
}

type Listener = () => void

const store = new Map<string, TrackedExport[]>()
const listeners = new Set<Listener>()
const EMPTY: readonly TrackedExport[] = []

function emit(): void {
  for (const listener of listeners) listener()
}

/*
 * Returns the current user's tracked exports as a stable snapshot reference
 * for `useSyncExternalStore` — the per-user array only changes when that user
 * registers or clears an export. No state is mutated during render; the store
 * is written exclusively from event handlers and tests.
 */
export function getTrackedExports(userId: string): readonly TrackedExport[] {
  return store.get(userId) ?? EMPTY
}

export function registerExport(entry: TrackedExport): void {
  const current = store.get(entry.ownerUserId) ?? []
  store.set(entry.ownerUserId, [...current.filter((item) => item.id !== entry.id), entry])
  emit()
}

export function clearTrackedExports(): void {
  store.clear()
  emit()
}

export function useTrackedExports(): readonly TrackedExport[] {
  const { session } = useSession()
  return useSyncExternalStore(subscribe, () => getTrackedExports(session.userId))
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/* ---- Polling ---- */

export const EXPORT_POLL_INTERVAL_MS = 3000

const TERMINAL_READY = new Set(['ready', 'completed', 'available'])
const TERMINAL_FAILED = new Set(['failed', 'error', 'cancelled', 'expired'])

export function isTerminalExportStatus(status: string | null | undefined): boolean {
  return TERMINAL_READY.has(status ?? '') || TERMINAL_FAILED.has(status ?? '')
}

export function isExportReady(status: string | null | undefined): boolean {
  return TERMINAL_READY.has(status ?? '')
}

/**
 * The TanStack Query refetch-interval decision: `false` the moment the export
 * reaches a terminal status (ready/completed/available or
 * failed/error/cancelled), the fixed interval otherwise. Exported separately
 * so the terminal-stop rule is testable without timers.
 */
export function exportPollInterval(query: {
  state: { data?: { status?: string | null } }
}): number | false {
  return isTerminalExportStatus(query.state.data?.status) ? false : EXPORT_POLL_INTERVAL_MS
}

function exportStatus(entity: Entity): string {
  return typeof entity === 'object' && entity !== null && 'status' in entity && typeof entity.status === 'string'
    ? entity.status
    : ''
}

/**
 * Polls one tracked export through its generated get-by-ID endpoint. The
 * query is disabled — no request, no interval — for any export whose owner is
 * not the current session user, so a stale component holding a previous
 * user's entry can neither display nor poll that user's export ID. The
 * owner is part of the query key so two users' caches never collide. The
 * refetch interval function returns `false` the moment the status turns
 * terminal, so polling stops instead of running forever.
 */
export function useExportStatus(entry: TrackedExport) {
  const csrfToken = useSessionToken()
  const { session } = useSession()
  const owned = entry.ownerUserId === session.userId
  return useQuery({
    queryKey: ['export-status', entry.ownerUserId, entry.kind, entry.id] as const,
    enabled: owned,
    queryFn: async () => {
      if (entry.kind === 'report') {
        const entity = unwrap<Entity>(await generated.getExport(entry.id, requestInit(csrfToken)))
        return { status: exportStatus(entity) }
      }
      const descriptor = unwrap<AuditExportDescriptor>(
        await generated.getAuditExport(entry.id, requestInit(csrfToken)),
      )
      return { status: descriptor.status }
    },
    refetchInterval: owned ? exportPollInterval : false,
    refetchIntervalInBackground: true,
  })
}
