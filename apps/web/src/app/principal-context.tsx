import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import * as generated from '../api/generated/cluster'
import {
  ApiError,
  customFetch,
  requestInit,
  unwrap,
  unwrapWithEtag,
  uuidV7,
} from '../api/http'
import { useLocale, useSessionToken } from './session-context'

export interface PrincipalSnapshot {
  state: 'loading' | 'ready' | 'stale' | 'denied' | 'error'
  capabilities: string[] | null
  features: { tasks: boolean } | null
  effectiveScope: { scopeType: string; scopeId: string; label: string } | null
  availableScopes: Array<{ scopeType: string; scopeId: string; label: string }>
  revision: number
  scopeEpoch: number
  scopeReady: boolean
  errorCorrelationId: string | null
  refresh: () => void
  selectScope: (scopeType: string, scopeId: string) => Promise<void>
}

interface ScopesPayload {
  available_scopes: Array<{
    scope_type: string
    scope_id: string
    label: string
  }>
  effective_scope: {
    scope_type: string
    scope_id: string
    label: string
  } | null
}

interface ScopeSelectPayload {
  scope_type: string
  scope_id: string
}

function scopeVersionMissingMessage(locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? 'إصدار النطاق غير معروف؛ أعد تحميل الصفحة.'
    : 'Scope version is unknown; reload the page.'
}

function scopeSelectionInFlightMessage(locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? 'عملية اختيار نطاق أخرى قيد التنفيذ.'
    : 'Another scope selection is already in progress.'
}

const PrincipalContext = createContext<PrincipalSnapshot | null>(null)

export function PrincipalProvider({ children }: { children: ReactNode }) {
  const csrfToken = useSessionToken()
  const locale = useLocale()
  const [state, setState] = useState<PrincipalSnapshot['state']>('loading')
  const [capabilities, setCapabilities] = useState<string[] | null>(null)
  const [features, setFeatures] = useState<
    PrincipalSnapshot['features'] | null
  >(null)
  const [effectiveScope, setEffectiveScope] =
    useState<PrincipalSnapshot['effectiveScope']>(null)
  const [availableScopes, setAvailableScopes] = useState<
    PrincipalSnapshot['availableScopes']
  >([])
  const [revision, setRevision] = useState(0)
  const [scopeEpoch, setScopeEpoch] = useState(0)
  const [scopeReady, setScopeReady] = useState(false)
  const [errorCorrelationId, setErrorCorrelationId] = useState<string | null>(
    null,
  )
  const inFlight = useRef(false)
  // Strong ETag captured from every `/me/scopes` response and from successful
  // `/me/scope` responses. Never exposed on the snapshot; only used to build
  // the next If-Match. Missing → next selection fails fast with a localized
  // error instead of guessing an arbitrary version.
  const scopeVersionRef = useRef<number | null>(null)
  // Provider-level mutex for concurrent selectScope calls. A second call that
  // arrives while one is still in flight is rejected outright so the caller
  // never races two PUTs against the same scope version.
  const selectionInFlight = useRef(false)

  const load = useCallback(async () => {
    if (inFlight.current) return
    inFlight.current = true
    setState('loading')
    setErrorCorrelationId(null)
    try {
      // GET /api/v1/me is the contracted PrincipalContext projection: it
      // carries the capability codes + feature flags the shell needs. The
      // browser never supplies roles/scopes/clearance itself.
      const [principal, scopesResponse] = await Promise.all([
        unwrap<generated.PrincipalContextSchema>(
          await generated.getCurrentPrincipal(requestInit(null)),
        ),
        unwrapWithEtag<ScopesPayload>(
          await customFetch('/api/v1/me/scopes', {
            method: 'GET',
            headers: {
              Accept: 'application/json',
              'X-Correlation-ID': uuidV7(),
            },
          }),
        ),
      ])
      const scopes = scopesResponse.value
      // Capture the strong ETag every time, even if it is null — the next
      // selectScope call decides whether a missing version is fatal.
      scopeVersionRef.current = scopesResponse.etag

      setCapabilities(principal.capabilities ?? [])
      setFeatures(principal.features ?? { tasks: false })
      setAvailableScopes(
        (scopes.available_scopes ?? []).map((s) => ({
          scopeType: s.scope_type,
          scopeId: s.scope_id,
          label: s.label,
        })),
      )
      setEffectiveScope(
        scopes.effective_scope
          ? {
              scopeType: scopes.effective_scope.scope_type,
              scopeId: scopes.effective_scope.scope_id,
              label: scopes.effective_scope.label,
            }
          : null,
      )
      setScopeReady(true)
      setErrorCorrelationId(null)
      setState('ready')
      setRevision((r) => r + 1)
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setCapabilities([])
        setFeatures({ tasks: false })
        setErrorCorrelationId(null)
        setState('denied')
      } else {
        setErrorCorrelationId(
          error instanceof ApiError ? error.correlationId : null,
        )
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
      // Reject any second selection that arrives before the first one
      // finishes — the provider's own state is the only reliable arbiter.
      if (selectionInFlight.current) {
        throw new Error(scopeSelectionInFlightMessage(locale))
      }
      const currentVersion = scopeVersionRef.current
      if (currentVersion === null) {
        // The server never gave us a usable ETag. Never guess — surface a
        // localized provider error so the caller can recover.
        throw new Error(scopeVersionMissingMessage(locale))
      }
      selectionInFlight.current = true
      setScopeEpoch((e) => e + 1)
      setScopeReady(false)
      try {
        const { etag: responseEtag } = await unwrapWithEtag<ScopeSelectPayload>(
          await customFetch('/api/v1/me/scope', {
            method: 'PUT',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-Correlation-ID': uuidV7(),
              'X-CSRF-Token': csrfToken,
              'Idempotency-Key': uuidV7(),
              'If-Match': `"${currentVersion}"`,
            },
            body: JSON.stringify({ scope_type: scopeType, scope_id: scopeId }),
          }),
        )
        // Update from the successful select response before reloading so
        // repeated selections always observe the latest server version.
        if (responseEtag !== null) {
          scopeVersionRef.current = responseEtag
        }
        await load()
      } catch (error) {
        if (error instanceof ApiError && error.status === 412) {
          // The server rejected our ETag — reload to pick up the winning
          // version, then surface a stale state to the caller and rethrow.
          await load()
          setState('stale')
        }
        throw error
      } finally {
        selectionInFlight.current = false
        setScopeReady(true)
      }
    },
    [csrfToken, load, locale],
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
      errorCorrelationId,
      refresh,
      selectScope,
    }),
    [
      state,
      capabilities,
      features,
      effectiveScope,
      availableScopes,
      revision,
      scopeEpoch,
      scopeReady,
      errorCorrelationId,
      refresh,
      selectScope,
    ],
  )

  return (
    <PrincipalContext.Provider value={value}>
      {children}
    </PrincipalContext.Provider>
  )
}

export function usePrincipal(): PrincipalSnapshot {
  const context = useContext(PrincipalContext)
  if (!context)
    throw new Error('usePrincipal must be used within PrincipalProvider')
  return context
}

export function PrincipalContextTestProvider({
  capabilities,
  features,
  effectiveScope = null,
  state = 'ready',
  errorCorrelationId = null,
  refresh = () => {},
  children,
}: {
  capabilities: string[]
  features: PrincipalSnapshot['features']
  effectiveScope?: PrincipalSnapshot['effectiveScope']
  state?: PrincipalSnapshot['state']
  errorCorrelationId?: string | null
  refresh?: () => void
  children: ReactNode
}) {
  const value: PrincipalSnapshot = useMemo(
    () => ({
      state,
      capabilities,
      features,
      effectiveScope,
      availableScopes: [],
      revision: 0,
      scopeEpoch: 0,
      scopeReady: true,
      errorCorrelationId,
      refresh,
      selectScope: async () => {},
    }),
    [
      capabilities,
      features,
      effectiveScope,
      state,
      errorCorrelationId,
      refresh,
    ],
  )
  return (
    <PrincipalContext.Provider value={value}>
      {children}
    </PrincipalContext.Provider>
  )
}
