// @vitest-environment jsdom
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'

import { ApiError, parseStrongEtag, requestInit, unwrap } from '../api/http'
import type { ScopeSelection, ScopeSelectionUpdate } from '../api/generated/cluster'
import * as generated from '../api/generated/cluster'
import {
  accessContextLabels,
  normalizePrincipal,
  normalizeScopeSelection,
  type PrincipalView,
  type ScopeOptionView,
  type ScopeSelectionView,
} from '../features/authorization/AccessContext'

export type PrincipalState = 'loading' | 'ready' | 'stale' | 'denied' | 'error'

export type PrincipalSnapshot = {
  state: PrincipalState
  capabilities: readonly string[] | null
  effectiveScope: ScopeOptionView | null
  availableScopes: readonly ScopeOptionView[]
  revision: number
  /** Increments before any scope snapshot is cleared, invalidating scoped reads immediately. */
  scopeEpoch: number
  /** True only when the effective scope and capability snapshot form one ready context. */
  scopeReady: boolean
  refresh: () => Promise<void>
  selectScope: (scopeType: ScopeSelectionUpdate['scope_type'], scopeId: string) => Promise<void>
}

const PrincipalContext = createContext<PrincipalSnapshot | null>(null)

export function usePrincipal(): PrincipalSnapshot {
  const value = useContext(PrincipalContext)
  if (!value) throw new Error('usePrincipal must be used inside <PrincipalProvider>.')
  return value
}

async function loadPrincipal(token: string): Promise<PrincipalView> {
  const response = await generated.getCurrentPrincipal(requestInit(token))
  return normalizePrincipal(unwrap<PrincipalView>(response))
}

async function loadScopeSelection(token: string): Promise<{ view: ScopeSelectionView; lockVersion: number | null }> {
  const response = await generated.listMyScopes(requestInit(token))
  const lockVersion = parseStrongEtag(response.headers.get('ETag'))
  return { view: normalizeScopeSelection(unwrap<ScopeSelection>(response), lockVersion), lockVersion }
}

async function updateScopeSelection(
  token: string,
  lockVersion: number | null,
  scopeType: ScopeSelectionUpdate['scope_type'],
  scopeId: string,
): Promise<{ view: ScopeSelectionView; lockVersion: number | null }> {
  if (lockVersion === null) {
    throw new ApiError(412, { type: 'precondition-failed', title: 'Scope version unavailable', status: 412 })
  }
  const response = await generated.selectMyScope({ scope_type: scopeType, scope_id: scopeId }, requestInit(token, { command: true, lockVersion }))
  const nextLock = parseStrongEtag(response.headers.get('ETag'))
  return { view: normalizeScopeSelection(unwrap<ScopeSelection>(response), nextLock), lockVersion: nextLock }
}

/**
 * Resolve a 401/403 outcome to a stable `denied` state without surfacing a
 * separate `error` for the navigation registry — the sidebar stays empty
 * rather than advertising features the principal cannot use.
 */
function stateFromError(error: unknown): PrincipalState {
  if (error instanceof ApiError && (error.status === 401 || error.status === 403)) return 'denied'
  return 'error'
}

export function PrincipalProvider({ token, children }: { token: string; children: ReactNode }) {
  const [state, setState] = useState<PrincipalState>('loading')
  const [capabilities, setCapabilities] = useState<readonly string[] | null>(null)
  const [scopeView, setScopeView] = useState<ScopeSelectionView | null>(null)
  const [scopeLock, setScopeLock] = useState<number | null>(null)
  const [revision, setRevision] = useState(0)
  const [scopeEpoch, setScopeEpoch] = useState(0)
  // While a scope switch is in flight we clear the cached snapshots so consumers
  // do not render stale data from the previous scope.
  const inFlight = useRef(false)
  const scopeEpochRef = useRef(0)
  // Hold the latest lock version in a ref so the scope selector always sees the
  // most recent value even when consumers trigger it from a stale render.
  const scopeLockRef = useRef<number | null>(null)
  useEffect(() => { scopeLockRef.current = scopeLock }, [scopeLock])
  const beginScopeTransition = useCallback(() => {
    const next = scopeEpochRef.current + 1
    scopeEpochRef.current = next
    setScopeEpoch(next)
    return next
  }, [])

  const refresh = useCallback(async () => {
    const epoch = beginScopeTransition()
    setState('loading')
    setCapabilities(null)
    setScopeView(null)
    setScopeLock(null)
    try {
      const [principalView, scopeResult] = await Promise.all([loadPrincipal(token), loadScopeSelection(token)])
      if (scopeEpochRef.current !== epoch) return
      setCapabilities(principalView.capabilities.slice())
      setScopeView(scopeResult.view)
      setScopeLock(scopeResult.lockVersion)
      scopeLockRef.current = scopeResult.lockVersion
      setState('ready')
    } catch (error) {
      if (scopeEpochRef.current !== epoch) return
      setCapabilities(null)
      setScopeView(null)
      setState(stateFromError(error))
    }
  }, [beginScopeTransition, token])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const selectScope = useCallback(async (scopeType: ScopeSelectionUpdate['scope_type'], scopeId: string) => {
    if (inFlight.current) return
    inFlight.current = true
    const epoch = beginScopeTransition()
    const expectedLock = scopeLockRef.current
    setState('loading')
    setCapabilities(null)
    setScopeView(null)
    setScopeLock(null)
    try {
      const result = await updateScopeSelection(token, expectedLock, scopeType, scopeId)
      if (scopeEpochRef.current !== epoch) return
      setRevision((value) => value + 1)
      setScopeView(result.view)
      setScopeLock(result.lockVersion)
      scopeLockRef.current = result.lockVersion
      // Reload capabilities/scopes one more time so role-effective changes
      // from the scope switch are reflected (RBAC + ABAC stays authoritative
      // server-side; the client just re-derives its view from the latest
      // `/me` response).
      try {
        const principalView = await loadPrincipal(token)
        if (scopeEpochRef.current !== epoch) return
        setCapabilities(principalView.capabilities.slice())
        setState('ready')
      } catch (error) {
        // Keep the navigation fail-closed and make the failed refresh visible
        // to the shared selector instead of claiming that the new context is
        // ready with an unknown capability set.
        setCapabilities(null)
        setState(stateFromError(error))
      }
    } catch (error) {
      if (error instanceof ApiError && error.status === 412) {
        try {
          const [principalView, scopeResult] = await Promise.all([loadPrincipal(token), loadScopeSelection(token)])
          if (scopeEpochRef.current !== epoch) return
          setCapabilities(principalView.capabilities.slice())
          setScopeView(scopeResult.view)
          setScopeLock(scopeResult.lockVersion)
          scopeLockRef.current = scopeResult.lockVersion
          setRevision((value) => value + 1)
          setState('stale')
        } catch (refreshError) {
          setCapabilities(null)
          setScopeView(null)
          setState(stateFromError(refreshError))
        }
      } else {
        if (scopeEpochRef.current !== epoch) return
        setState(stateFromError(error))
      }
    } finally {
      if (scopeEpochRef.current === epoch) inFlight.current = false
    }
  }, [beginScopeTransition, token])

  const value = useMemo<PrincipalSnapshot>(() => ({
    state,
    capabilities,
    effectiveScope: scopeView?.effective ?? null,
    availableScopes: scopeView?.options ?? [],
    revision,
    scopeEpoch,
    scopeReady: state === 'ready' && scopeView?.effective != null,
    refresh,
    selectScope,
  }), [state, capabilities, scopeView, revision, scopeEpoch, refresh, selectScope])

  return <PrincipalContext.Provider value={value}>{children}</PrincipalContext.Provider>
}

/** Build a stable scope selector value used by the scope selector UI. */
export function scopeSelectValue(option: ScopeOptionView): string {
  return `${option.scopeType}:${option.scopeId}`
}

export { accessContextLabels }
