import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import * as generated from '../api/generated/cluster'
import { ApiError, customFetch, requestInit, unwrap, uuidV7 } from '../api/http'
import { useSessionToken } from './session-context'

export interface PrincipalSnapshot {
  state: 'loading' | 'ready' | 'stale' | 'denied' | 'error'
  capabilities: string[] | null
  features: { work_management: boolean; tasks: boolean } | null
  effectiveScope: { scopeType: string; scopeId: string; label: string } | null
  availableScopes: Array<{ scopeType: string; scopeId: string; label: string }>
  revision: number
  scopeEpoch: number
  scopeReady: boolean
  refresh: () => void
  selectScope: (scopeType: string, scopeId: string) => Promise<void>
}

interface ScopesPayload {
  available_scopes: Array<{ scope_type: string; scope_id: string; label: string }>
  effective_scope: { scope_type: string; scope_id: string; label: string } | null
}

interface ScopeSelectPayload {
  scope_type: string
  scope_id: string
}

const PrincipalContext = createContext<PrincipalSnapshot | null>(null)

export function PrincipalProvider({ children }: { children: ReactNode }) {
  const csrfToken = useSessionToken()
  const [state, setState] = useState<PrincipalSnapshot['state']>('loading')
  const [capabilities, setCapabilities] = useState<string[] | null>(null)
  const [features, setFeatures] = useState<PrincipalSnapshot['features'] | null>(null)
  const [effectiveScope, setEffectiveScope] = useState<PrincipalSnapshot['effectiveScope']>(null)
  const [availableScopes, setAvailableScopes] = useState<PrincipalSnapshot['availableScopes']>([])
  const [revision, setRevision] = useState(0)
  const [scopeEpoch, setScopeEpoch] = useState(0)
  const [scopeReady, setScopeReady] = useState(false)
  const inFlight = useRef(false)

  const load = useCallback(async () => {
    if (inFlight.current) return
    inFlight.current = true
    setState('loading')
    try {
      // GET /api/v1/me is the contracted PrincipalContext projection: it
      // carries the capability codes + feature flags the shell needs. The
      // browser never supplies roles/scopes/clearance itself.
      const [principal, scopes] = await Promise.all([
        unwrap<generated.PrincipalContextSchema>(
          await generated.getCurrentPrincipal(requestInit(null)),
        ),
        unwrap<ScopesPayload>(
          await customFetch('/api/v1/me/scopes', {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Correlation-ID': uuidV7() },
          }),
        ),
      ])
      setCapabilities(principal.capabilities ?? [])
      setFeatures(principal.features ?? { work_management: false, tasks: false })
      setAvailableScopes(
        (scopes.available_scopes ?? []).map((s) => ({
          scopeType: s.scope_type,
          scopeId: s.scope_id,
          label: s.label,
        })),
      )
      setEffectiveScope(
        scopes.effective_scope
          ? { scopeType: scopes.effective_scope.scope_type, scopeId: scopes.effective_scope.scope_id, label: scopes.effective_scope.label }
          : null,
      )
      setScopeReady(true)
      setState('ready')
      setRevision((r) => r + 1)
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setCapabilities([])
        setFeatures({ work_management: false, tasks: false })
        setState('denied')
      } else {
        setState('error')
      }
    } finally {
      inFlight.current = false
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load, csrfToken])

  const selectScope = useCallback(
    async (scopeType: string, scopeId: string) => {
      setScopeEpoch((e) => e + 1)
      setScopeReady(false)
      try {
        await unwrap<ScopeSelectPayload>(
          await customFetch('/api/v1/me/scope', {
            method: 'PUT',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-Correlation-ID': uuidV7(),
              'X-CSRF-Token': csrfToken,
              'Idempotency-Key': uuidV7(),
              'If-Match': '"1"',
            },
            body: JSON.stringify({ scope_type: scopeType, scope_id: scopeId }),
          }),
        )
        await load()
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          await load()
          setState('stale')
        }
        setScopeReady(true)
      }
    },
    [csrfToken, load],
  )

  const refresh = useCallback(() => {
    void load()
  }, [load])

  const value = useMemo(
    () => ({
      state,
      capabilities,
      features,
      effectiveScope,
      availableScopes,
      revision,
      scopeEpoch,
      scopeReady,
      refresh,
      selectScope,
    }),
    [state, capabilities, features, effectiveScope, availableScopes, revision, scopeEpoch, scopeReady, refresh, selectScope],
  )

  return <PrincipalContext.Provider value={value}>{children}</PrincipalContext.Provider>
}

export function usePrincipal(): PrincipalSnapshot {
  const context = useContext(PrincipalContext)
  if (!context) throw new Error('usePrincipal must be used within PrincipalProvider')
  return context
}
