import { useCallback, useEffect, useRef, useState } from 'react'

import { listAssignmentScopeTargets } from '../../api/r1'
import type {
  AssignmentScopeParentType,
  AssignmentScopeTarget,
  AssignmentScopeType,
} from '../../api/r1'
import { ApiError, stateFromError } from '../../api'

/**
 * Stateful, cursor-paginated reader for the assignment scope-target catalog.
 *
 * The hook owns an in-flight `AbortController` and a monotonically-increasing
 * `requestEpoch` integer. Every load attempt captures the current epoch inside
 * the request body and refuses to commit results whose epoch no longer matches
 * `requestEpochRef.current`, so a slow /catalog response cannot overwrite the
 * newer state established by a follow-up cascade change.
 *
 * `items` is de-duplicated by `(scope_type, scope_id)` on every append so a
 * cursor-paged browse never surfaces the same row twice, even if the server
 * returns overlapping pages.
 */
export type AssignmentScopeTargetsState = 'idle' | 'loading' | 'ready' | 'forbidden' | 'unsupported' | 'error'

export type UseAssignmentScopeTargetsQuery = {
  scopeType: AssignmentScopeType
  parentScopeType?: AssignmentScopeParentType
  parentScopeId?: string
  search?: string
  limit?: number
  /** When false the hook clears state and skips fetching (e.g. read-only caller). */
  enabled?: boolean
}

export type UseAssignmentScopeTargetsResult = {
  items: AssignmentScopeTarget[]
  next_cursor: string | null
  state: AssignmentScopeTargetsState
  error: string | null
  loadMore: () => void
  retry: () => void
}

export function useAssignmentScopeTargets(
  token: string,
  query: UseAssignmentScopeTargetsQuery,
): UseAssignmentScopeTargetsResult {
  const enabled = query.enabled !== false
  const [items, setItems] = useState<AssignmentScopeTarget[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [state, setState] = useState<AssignmentScopeTargetsState>('idle')
  const [error, setError] = useState<string | null>(null)
  /** Last successful cursor. `null` is the sentinel for the first page. */
  const cursorRef = useRef<string | null>(null)
  /** Monotonically increments on every fetch. Newer load attempts supersede older ones. */
  const epochRef = useRef(0)
  const aborterRef = useRef<AbortController | null>(null)
  const mountedRef = useRef(true)

  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      aborterRef.current?.abort()
    }
  }, [])

  const runFetch = useCallback(
    async (cursor: string | null) => {
      aborterRef.current?.abort()
      const controller = new AbortController()
      aborterRef.current = controller
      const epoch = ++epochRef.current
      setState('loading')
      setError(null)
      try {
        const response = await listAssignmentScopeTargets(token, {
          scopeType: query.scopeType,
          ...(query.parentScopeType ? { parentScopeType: query.parentScopeType } : {}),
          ...(query.parentScopeId ? { parentScopeId: query.parentScopeId } : {}),
          ...(query.search ? { search: query.search } : {}),
          ...(cursor ? { cursor } : {}),
          ...(query.limit !== undefined ? { limit: query.limit } : {}),
        })
        if (!mountedRef.current) return
        if (epoch !== epochRef.current) return
        if (controller.signal.aborted) return
        const incoming = response.items ?? []
        setItems((current) => appendUnique(current, incoming))
        setNextCursor(response.next_cursor ?? null)
        cursorRef.current = response.next_cursor ?? null
        setState('ready')
      } catch (caught) {
        if (!mountedRef.current) return
        if (epoch !== epochRef.current) return
        if (controller.signal.aborted) return
        if (caught instanceof ApiError && (caught.status === 422 || caught.problem.type === 'urn:cluster:problem:scope_type_not_catalogued')) {
          setState('unsupported')
          setError(null)
        } else if (caught instanceof ApiError && stateFromError(caught) === 'forbidden') {
          setState('forbidden')
          setError(null)
        } else {
          setState('error')
          setError(null)
        }
      }
    },
    [token, query.scopeType, query.parentScopeType, query.parentScopeId, query.search, query.limit],
  )

  useEffect(() => {
    if (!enabled) {
      aborterRef.current?.abort()
      epochRef.current += 1
      setItems([])
      setNextCursor(null)
      setState('idle')
      setError(null)
      cursorRef.current = null
      return
    }
    epochRef.current += 1
    cursorRef.current = null
    setItems([])
    setNextCursor(null)
    setState('loading')
    setError(null)
    void runFetch(null)
    return () => {
      aborterRef.current?.abort()
    }
  }, [enabled, query.scopeType, query.parentScopeType, query.parentScopeId, runFetch])

  const loadMore = useCallback(() => {
    if (state !== 'ready') return
    if (!nextCursor) return
    void runFetch(nextCursor)
  }, [state, nextCursor, runFetch])

  const retry = useCallback(() => {
    if (state === 'loading') return
    void runFetch(cursorRef.current)
  }, [state, runFetch])

  return { items, next_cursor: nextCursor, state, error, loadMore, retry }
}

function appendUnique(
  current: AssignmentScopeTarget[],
  incoming: AssignmentScopeTarget[],
): AssignmentScopeTarget[] {
  if (incoming.length === 0) return current
  const seen = new Set(current.map((row) => key(row)))
  const merged = current.slice()
  for (const row of incoming) {
    const k = key(row)
    if (seen.has(k)) continue
    seen.add(k)
    merged.push(row)
  }
  return merged
}

function key(row: AssignmentScopeTarget): string {
  return `${row.scope_type}:${row.scope_id}`
}
