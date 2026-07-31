import { useCallback, useEffect, useRef, useState } from 'react'
import * as generated from '../../api/generated/cluster'
import { customFetch, requestInit, unwrap, stateFromError } from '../../api/http'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { formatDate, statusLabel } from '../../i18n'
import {
  EmptyState,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../ui'

const copy = {
  ar: {
    title: 'سياق الوصول',
    intro: 'تعريف هويتك وصلاحياتك والنطاق الذي تعمل فيه حالياً.',
    identity: 'الهوية والصلاحيات',
    subjectId: 'معرّف المستخدم',
    roles: 'الأدوار',
    capabilities: 'الصلاحيات',
    features: 'المزايا',
    featureWorkManagement: 'إدارة الأعمال',
    featureTasks: 'المهام',
    clearance: 'التصنيف',
    breakGlass: 'دخول استثنائي',
    scope: 'النطاق الحالي',
    scopeHint: 'اختر النطاق الذي تريد العمل ضمنه. تتغير الصلاحيات الفعلية وفق النطاق.',
    noScopes: 'لا توجد نطاقات متاحة.',
    scopeSwitching: 'جارٍ تبديل النطاق…',
    scopeError: 'تعذّر تبديل النطاق. أعد المحاولة.',
    delegations: 'التفويضات',
    delegationsIntro: 'تفويضات صادرة أو مستلمة منسوبة إليك.',
    noDelegations: 'لا توجد تفويضات.',
    unavailable: 'غير متاح',
    unavailableBody: 'تحتاج صلاحية authorization.assignment.read لعرض التفويضات.',
    error: 'تعذّر تحميل سياق الوصول.',
    retry: 'إعادة المحاولة',
    loading: 'جارٍ تحميل سياق الوصول…',
    scopeNone: '—',
    effectiveFrom: 'ساري من',
    effectiveTo: 'ساري إلى',
    actions: 'الإجراءات المسموحة',
  },
  en: {
    title: 'Access context',
    intro: 'Your identity, permissions, and the scope you are currently working in.',
    identity: 'Identity & permissions',
    subjectId: 'Subject ID',
    roles: 'Roles',
    capabilities: 'Capabilities',
    features: 'Features',
    featureWorkManagement: 'Work management',
    featureTasks: 'Tasks',
    clearance: 'Clearance',
    breakGlass: 'Break glass',
    scope: 'Current scope',
    scopeHint: 'Choose the scope you want to work in. Effective permissions change with the scope.',
    noScopes: 'No scopes available.',
    scopeSwitching: 'Switching scope…',
    scopeError: 'Could not switch scope. Try again.',
    delegations: 'Delegations',
    delegationsIntro: 'Delegations issued or received on your behalf.',
    noDelegations: 'No delegations.',
    unavailable: 'Unavailable',
    unavailableBody: 'You need the authorization.assignment.read capability to view delegations.',
    error: 'Could not load the access context.',
    retry: 'Retry',
    loading: 'Loading access context…',
    scopeNone: '—',
    effectiveFrom: 'Valid from',
    effectiveTo: 'Valid to',
    actions: 'Allowed actions',
  },
} as const

type LoadState = 'loading' | 'ready' | 'forbidden' | 'error'

interface ScopesPayload {
  available_scopes: Array<{ scope_type: string; scope_id: string; label: string }>
  effective_scope: { scope_type: string; scope_id: string; label: string } | null
}

interface DelegationItem {
  id?: string
  code?: string
  name?: string
  title?: string
  status?: string
  effective_from?: string | null
  effective_to?: string | null
  allowed_actions?: string[]
  lock_version?: number
}

export function AccessContextScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const text = copy[locale]
  const [state, setState] = useState<LoadState>('loading')
  const [me, setMe] = useState<generated.PrincipalContextSchema | null>(null)
  const [scopes, setScopes] = useState<ScopesPayload | null>(null)
  const [delegations, setDelegations] = useState<DelegationItem[] | null>(null)
  const [scopeError, setScopeError] = useState(false)
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)

  useEffect(() => {
    activeRef.current = true
    return () => {
      activeRef.current = false
      loadRequestRef.current += 1
    }
  }, [])

  const canReadDelegations = (principal.capabilities ?? []).includes('authorization.assignment.read')

  const load = useCallback(async () => {
    const epoch = ++loadRequestRef.current
    setState('loading')
    try {
      const [mePayload, scopesPayload] = await Promise.all([
        unwrap<generated.PrincipalContextSchema>(
          await generated.getCurrentPrincipal(requestInit(csrfToken)),
        ),
        unwrap<ScopesPayload>(
          await customFetch('/api/v1/me/scopes', requestInit(csrfToken)),
        ),
      ])
      const canRead = (mePayload.capabilities ?? []).includes('authorization.assignment.read')
      const delegationsPayload = canRead
        ? unwrap<{ items: DelegationItem[] }>(
            await generated.listAuthorizationAdminResources(
              'delegations',
              { limit: 100 },
              requestInit(csrfToken),
            ),
          )
        : null
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setMe(mePayload)
      setScopes(scopesPayload)
      setDelegations(delegationsPayload ? delegationsPayload.items : [])
      setScopeError(false)
      setState('ready')
    } catch (error) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setMe(null)
      setScopes(null)
      setDelegations(null)
      setState(stateFromError(error) === 'forbidden' ? 'forbidden' : 'error')
    }
  }, [csrfToken])

  useEffect(() => {
    void load()
  }, [load])

  async function changeScope(value: string) {
    if (!value) return
    const separator = value.indexOf(':')
    if (separator < 0) return
    const scopeType = value.slice(0, separator)
    const scopeId = value.slice(separator + 1)
    setScopeError(false)
    try {
      await principal.selectScope(scopeType, scopeId)
      await load()
    } catch {
      setScopeError(true)
    }
  }

  const effective = scopes?.effective_scope ?? null
  const options =
    scopes?.available_scopes.map((scope) => ({
      value: `${scope.scope_type}:${scope.scope_id}`,
      label: scope.label,
    })) ?? []

  return (
    <Page>
      <PageHeader id="access-context-heading" title={text.title} description={text.intro} />
      {state === 'loading' && <SkeletonList rows={3} />}
      {state === 'forbidden' && <EmptyState title={copy[locale].unavailable} body={copy[locale].unavailableBody} />}
      {state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />}
      {state === 'ready' && me && scopes && (
        <PanelGrid>
          <Panel id="access-identity-panel" title={text.identity} level={2}>
            <dl className="detail-list">
              <div>
                <dt>{text.subjectId}</dt>
                <dd dir="ltr">{me.subject_id}</dd>
              </div>
              <div>
                <dt>{text.clearance}</dt>
                <dd>
                  <StatusBadge variant="info">{statusLabel(me.clearance, locale)}</StatusBadge>
                </dd>
              </div>
              {me.break_glass ? (
                <div>
                  <dt>{text.breakGlass}</dt>
                  <dd>
                    <StatusBadge variant="warning">{copy[locale].breakGlass}</StatusBadge>
                  </dd>
                </div>
              ) : null}
            </dl>
            <h3 className="panel__heading">{text.roles}</h3>
            {me.roles && me.roles.length > 0 ? (
              <div className="badge-row">
                {me.roles.map((role) => (
                  <StatusBadge key={role} variant="neutral">
                    {role}
                  </StatusBadge>
                ))}
              </div>
            ) : (
              <p className="status-message">{text.scopeNone}</p>
            )}
            <h3 className="panel__heading">{text.features}</h3>
            <div className="badge-row">
              <StatusBadge variant={me.features.work_management ? 'success' : 'neutral'}>
                {text.featureWorkManagement}: {me.features.work_management ? '✓' : '✕'}
              </StatusBadge>
              <StatusBadge variant={me.features.tasks ? 'success' : 'neutral'}>
                {text.featureTasks}: {me.features.tasks ? '✓' : '✕'}
              </StatusBadge>
            </div>
            <h3 className="panel__heading">{text.capabilities}</h3>
            {(me.capabilities ?? []).length > 0 ? (
              <div className="badge-row">
                {(me.capabilities ?? []).map((capability) => (
                  <StatusBadge key={capability} variant="info">
                    {capability}
                  </StatusBadge>
                ))}
              </div>
            ) : (
              <p className="status-message">{text.scopeNone}</p>
            )}
          </Panel>

          <Panel id="access-scope-panel" title={text.scope} level={2}>
            <p className="field__help">{text.scopeHint}</p>
            {scopeError && <p className="error-summary" role="alert">{text.scopeError}</p>}
            {options.length === 0 ? (
              <EmptyState title={text.noScopes} />
            ) : (
              <>
                <Select
                  id="access-scope-select"
                  value={effective ? `${effective.scope_type}:${effective.scope_id}` : ''}
                  onChange={(value) => void changeScope(value)}
                  options={options}
                  ariaLabel={text.scope}
                  disabled={!principal.scopeReady}
                />
                {!principal.scopeReady && <p className="field__help" role="status">{text.scopeSwitching}</p>}
              </>
            )}
          </Panel>

          <Panel id="access-delegations-panel" title={text.delegations} level={2}>
            <p className="field__help">{text.delegationsIntro}</p>
            {!canReadDelegations ? (
              <EmptyState title={text.unavailable} body={text.unavailableBody} />
            ) : (delegations ?? []).length === 0 ? (
              <EmptyState title={text.noDelegations} />
            ) : (
              <ul className="screen-list">
                {(delegations ?? []).map((delegation) => (
                  <li key={delegation.id ?? delegation.code ?? delegation.title ?? ''} className="screen-list__row">
                    <span className="screen-list__row-title">
                      {delegation.name ?? delegation.title ?? delegation.code ?? delegation.id ?? text.scopeNone}
                    </span>
                    <span className="screen-list__row-meta">
                      {delegation.status ? <StatusBadge variant="neutral">{statusLabel(delegation.status, locale)}</StatusBadge> : null}
                      {delegation.effective_from ? (
                        <span>
                          {text.effectiveFrom} {formatDate(delegation.effective_from, locale)}
                        </span>
                      ) : null}
                      {delegation.effective_to ? (
                        <span>
                          {text.effectiveTo} {formatDate(delegation.effective_to, locale)}
                        </span>
                      ) : null}
                    </span>
                    {delegation.allowed_actions && delegation.allowed_actions.length > 0 ? (
                      <span className="screen-list__row-meta">
                        {text.actions}: {delegation.allowed_actions.join('، ')}
                      </span>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </PanelGrid>
      )}
    </Page>
  )
}
