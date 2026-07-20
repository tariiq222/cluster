import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../../api'
import {
  getMyAccessContext,
  listAuthorization,
  listMyAccessScopes,
  selectMyAccessScope,
  type AccessProjection,
  type AuthorizationItem,
  type FieldAccess,
  type FieldAccessState,
  type ScopeSelectionSnapshot,
} from '../../api/r1'

export type Locale = 'ar' | 'en'
export type AccessContextState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'error'
export type ErrorResolution = AccessContextState | 'session-expired'
export type DelegationState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'unavailable'

export type PrincipalView = {
  subjectId: string
  tenantId: string
  roles: string[]
  clearance: string
  breakGlass: boolean
  organizationUnitCount: number
  correlationId: string
}

export type ScopeOptionView = {
  scopeType: string
  scopeId: string
  label: string
  effective: boolean
}

export type ScopeSelectionView = {
  options: ScopeOptionView[]
  effective: ScopeOptionView | null
  lockVersion: number | null
}

export type FieldAccessRow = {
  name: string
  state: FieldAccessState
  display: string
}

export type DelegationRow = {
  id: string
  name: string
  code: string
  status: string
}

export type ProjectionSummary = {
  decisionId: string | null
  actions: string[]
  fields: FieldAccessRow[]
}

export const accessContextLabels = {
  ar: {
    title: 'سياق الوصول الشخصي',
    intro: 'يعرض هذا العرض قرارات الخادم فقط ولا يُتخذ في المتصفح أي قرار صلاحية.',
    loading: 'جارٍ تحميل سياق الوصول…',
    empty: 'لا يتوفر سياق وصول لعرضه.',
    forbidden: 'لا تملك صلاحية عرض سياق الوصول.',
    error: 'تعذر تحميل سياق الوصول.',
    retry: 'إعادة المحاولة',
    subject: 'معرّف المستخدم',
    clearance: 'مستوى التصنيف الأمني',
    breakGlass: 'كسر الزجاج',
    breakGlassOn: 'مفعّل',
    breakGlassOff: 'غير مفعّل',
    organizationUnits: 'الوحدات التنظيمية',
    correlationId: 'معرّف الارتباط',
    effectiveScope: 'النطاق الفعلي',
    noEffectiveScope: 'لم يحدد الخادم نطاقاً فعلياً.',
    scopeSelector: 'اختيار النطاق الفعلي',
    scopeUpdating: 'جارٍ تحديث النطاق…',
    roles: 'الأدوار الفعلية',
    noRoles: 'لم يرجع الخادم أدواراً فعلية.',
    delegations: 'التفويضات المرئية',
    noDelegations: 'لا توجد تفويضات مرئية.',
    delegationsForbidden: 'لا تملك صلاحية عرض التفويضات.',
    delegationsUnavailable: 'تعذر تحميل التفويضات.',
    projection: 'إسقاط قرار الوصول',
    decisionId: 'معرّف القرار',
    allowedActions: 'الإجراءات المسموح بها',
    noActions: 'لا توجد إجراءات مسموح بها.',
    fieldAccess: 'وصول الحقول',
    noFieldAccess: 'لا توجد قرارات حقول قابلة للعرض.',
    name: 'الاسم',
    code: 'الرمز',
    status: 'الحالة',
    field: 'الحقل',
    access: 'الوصول',
    scopeTypes: { cluster: 'التجمع', facility: 'المنشأة', unit: 'الوحدة' },
    clearanceLevels: { public: 'عام', internal: 'داخلي', confidential: 'سري', top_secret: 'سري للغاية' },
    fieldStates: { hidden: 'مخفي', masked: 'مقنّع', readonly: 'للقراءة فقط', editable: 'قابل للتحرير' },
  },
  en: {
    title: 'Personal access context',
    intro: 'This view renders server decisions only; the browser never makes authorization decisions.',
    loading: 'Loading access context…',
    empty: 'There is no access context to display.',
    forbidden: 'You do not have permission to view the access context.',
    error: 'We could not load the access context.',
    retry: 'Try again',
    subject: 'Subject ID',
    clearance: 'Clearance level',
    breakGlass: 'Break glass',
    breakGlassOn: 'Active',
    breakGlassOff: 'Inactive',
    organizationUnits: 'Organization units',
    correlationId: 'Correlation ID',
    effectiveScope: 'Effective scope',
    noEffectiveScope: 'The server did not return an effective scope.',
    scopeSelector: 'Select the effective scope',
    scopeUpdating: 'Updating scope…',
    roles: 'Effective roles',
    noRoles: 'The server returned no effective roles.',
    delegations: 'Visible delegations',
    noDelegations: 'There are no visible delegations.',
    delegationsForbidden: 'You do not have permission to view delegations.',
    delegationsUnavailable: 'We could not load delegations.',
    projection: 'Access decision projection',
    decisionId: 'Decision ID',
    allowedActions: 'Allowed actions',
    noActions: 'No actions are allowed.',
    fieldAccess: 'Field access',
    noFieldAccess: 'There are no displayable field decisions.',
    name: 'Name',
    code: 'Code',
    status: 'Status',
    field: 'Field',
    access: 'Access',
    scopeTypes: { cluster: 'Cluster', facility: 'Facility', unit: 'Unit' },
    clearanceLevels: { public: 'Public', internal: 'Internal', confidential: 'Confidential', top_secret: 'Top secret' },
    fieldStates: { hidden: 'Hidden', masked: 'Masked', readonly: 'Read only', editable: 'Editable' },
  },
} as const

export function directionForLocale(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr'
}

export function stateFromError(error: unknown): ErrorResolution {
  if (error instanceof ApiError && error.status === 401) return 'session-expired'
  if (error instanceof ApiError && error.status === 403) return 'forbidden'
  return 'error'
}

export function normalizePrincipal(input: unknown): PrincipalView {
  const source = input && typeof input === 'object' ? (input as Record<string, unknown>) : {}
  const roles = Array.isArray(source.roles) ? source.roles.filter((role): role is string => typeof role === 'string') : []
  return {
    subjectId: typeof source.subject_id === 'string' ? source.subject_id : '',
    tenantId: typeof source.tenant_id === 'string' ? source.tenant_id : '',
    roles,
    clearance: typeof source.clearance === 'string' ? source.clearance : 'public',
    breakGlass: source.break_glass === true,
    organizationUnitCount: Array.isArray(source.organization_unit_ids) ? source.organization_unit_ids.length : 0,
    correlationId: typeof source.correlation_id === 'string' ? source.correlation_id : '',
  }
}

function normalizeScopeOption(input: unknown): ScopeOptionView | null {
  if (!input || typeof input !== 'object') return null
  const source = input as Record<string, unknown>
  if (typeof source.scope_id !== 'string' || !source.scope_id) return null
  const scopeType = typeof source.scope_type === 'string' ? source.scope_type : 'cluster'
  if (scopeType !== 'cluster' && scopeType !== 'facility' && scopeType !== 'unit') return null
  const label = typeof source.label === 'string' && source.label ? source.label : source.scope_id
  return { scopeType, scopeId: source.scope_id, label, effective: false }
}

function isSelectableScope(input: unknown): input is Record<string, unknown> {
  if (!input || typeof input !== 'object') return false
  const scopeType = (input as Record<string, unknown>).scope_type
  return scopeType === 'cluster' || scopeType === 'facility' || scopeType === 'unit'
}

export function normalizeScopeSelection(input: unknown, lockVersion: number | null = null): ScopeSelectionView {
  const source = input && typeof input === 'object' ? (input as Record<string, unknown>) : {}
  const effectiveBase = isSelectableScope(source.effective_scope) ? normalizeScopeOption(source.effective_scope) : null
  const rawOptions = Array.isArray(source.available_scopes) ? source.available_scopes : []
  const options = rawOptions
    .map(normalizeScopeOption)
    .filter((option): option is ScopeOptionView => option !== null)
    .map((option) => ({
      ...option,
      effective: effectiveBase !== null && option.scopeType === effectiveBase.scopeType && option.scopeId === effectiveBase.scopeId,
    }))
  return { options, effective: effectiveBase ? { ...effectiveBase, effective: true } : null, lockVersion }
}

export function scopeSelectionView(snapshot: ScopeSelectionSnapshot): ScopeSelectionView {
  return normalizeScopeSelection(snapshot.selection, snapshot.lockVersion)
}

export function scopeSelectValue(option: ScopeOptionView): string {
  return `${option.scopeType}:${option.scopeId}`
}

export function parseScopeSelectValue(value: string): { scopeType: string; scopeId: string } | null {
  const separator = value.indexOf(':')
  if (separator <= 0 || separator === value.length - 1) return null
  return { scopeType: value.slice(0, separator), scopeId: value.slice(separator + 1) }
}

const FIELD_ACCESS_STATES: readonly FieldAccessState[] = ['hidden', 'masked', 'readonly', 'editable']

/** Projects only non-hidden server field decisions; masked fields never expose a raw value. */
export function fieldAccessRows(access: FieldAccess | null | undefined): FieldAccessRow[] {
  if (!access) return []
  return Object.entries(access)
    .filter((entry): entry is [string, FieldAccessState] => FIELD_ACCESS_STATES.includes(entry[1] as FieldAccessState))
    .filter(([, state]) => state !== 'hidden')
    .map(([name, state]) => ({ name, state, display: state === 'masked' ? '***' : state }))
}

export function delegationRows(items: AuthorizationItem[]): DelegationRow[] {
  return items.map((item, index) => ({
    id: typeof item.id === 'string' ? item.id : `delegation-${index}`,
    name: typeof item.name === 'string' ? item.name : '—',
    code: typeof item.code === 'string' ? item.code : '—',
    status: typeof item.status === 'string' ? item.status : '—',
  }))
}

export function projectionSummary(projection: AccessProjection | null | undefined): ProjectionSummary | null {
  if (!projection) return null
  return {
    decisionId: typeof projection.decision_id === 'string' ? projection.decision_id : null,
    actions: Array.isArray(projection.allowed_actions) ? projection.allowed_actions.filter((action) => typeof action === 'string') : [],
    fields: fieldAccessRows(projection.field_access),
  }
}

export function isContextEmpty(principal: PrincipalView, scopes: ScopeSelectionView): boolean {
  return principal.roles.length === 0 && scopes.options.length === 0
}

export function scopeTypeLabel(scopeType: string, locale: Locale): string {
  const labels = accessContextLabels[locale].scopeTypes as Record<string, string>
  return labels[scopeType] ?? scopeType
}

export function clearanceLabel(clearance: string, locale: Locale): string {
  const labels = accessContextLabels[locale].clearanceLevels as Record<string, string>
  return labels[clearance] ?? clearance
}

export function fieldStateLabel(state: FieldAccessState, locale: Locale): string {
  return accessContextLabels[locale].fieldStates[state]
}

async function loadPrincipal(token: string): Promise<PrincipalView> {
  return normalizePrincipal(await getMyAccessContext(token))
}

async function loadScopeSelection(token: string): Promise<ScopeSelectionView> {
  return scopeSelectionView(await listMyAccessScopes(token))
}

async function updateScopeSelection(token: string, scopes: ScopeSelectionView, scopeType: string, scopeId: string): Promise<ScopeSelectionView> {
  if (scopes.lockVersion === null) throw new ApiError(412, { type: 'precondition-failed', title: 'Scope version unavailable', status: 412 })
  return scopeSelectionView(await selectMyAccessScope(token, { scope_type: scopeType as never, scope_id: scopeId }, scopes.lockVersion))
}

function ContextStatePanel({ state, locale, onRetry }: { state: AccessContextState; locale: Locale; onRetry: () => void }) {
  const text = accessContextLabels[locale]
  if (state === 'ready') return null
  if (state === 'loading') {
    return <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>
  }
  const message = state === 'empty' ? text.empty : state === 'forbidden' ? text.forbidden : text.error
  return (
    <div className="state-panel" role={state === 'empty' ? 'status' : 'alert'}>
      <p>{message}</p>
      {state === 'error' && <button type="button" className="secondary-button" onClick={onRetry}>{text.retry}</button>}
    </div>
  )
}

function ScopeSection({ scopes, locale, selecting, error, onSelect }: {
  scopes: ScopeSelectionView
  locale: Locale
  selecting: boolean
  error: string | null
  onSelect: (scopeType: string, scopeId: string) => void
}) {
  const text = accessContextLabels[locale]
  const current = scopes.effective ? scopeSelectValue(scopes.effective) : ''
  return (
    <section aria-labelledby="access-context-scope-heading">
      <h2 id="access-context-scope-heading">{text.effectiveScope}</h2>
      {scopes.effective
        ? <p>{scopes.effective.label} — {scopeTypeLabel(scopes.effective.scopeType, locale)} (<span dir="ltr">{scopes.effective.scopeId}</span>)</p>
        : <p>{text.noEffectiveScope}</p>}
      {scopes.options.length > 0 && (
        <div className="inline-form">
          <label htmlFor="access-context-scope-selector">{text.scopeSelector}
            <select
              id="access-context-scope-selector"
              aria-label={text.scopeSelector}
              value={current}
              disabled={selecting}
              onChange={(event) => {
                const parsed = parseScopeSelectValue(event.target.value)
                if (parsed) onSelect(parsed.scopeType, parsed.scopeId)
              }}
            >
              {!scopes.effective && <option value="" disabled>—</option>}
              {scopes.options.map((option) => (
                <option key={scopeSelectValue(option)} value={scopeSelectValue(option)}>
                  {option.label} — {scopeTypeLabel(option.scopeType, locale)}
                </option>
              ))}
            </select>
          </label>
          {selecting && <span role="status">{text.scopeUpdating}</span>}
        </div>
      )}
      {error && <p role="alert">{error}</p>}
    </section>
  )
}

function DelegationSection({ state, rows, locale }: { state: DelegationState; rows: DelegationRow[]; locale: Locale }) {
  const text = accessContextLabels[locale]
  return (
    <section aria-labelledby="access-context-delegations-heading">
      <h2 id="access-context-delegations-heading">{text.delegations}</h2>
      {state === 'loading' && <p role="status">{text.loading}</p>}
      {state === 'forbidden' && <p role="status">{text.delegationsForbidden}</p>}
      {state === 'unavailable' && <p role="status">{text.delegationsUnavailable}</p>}
      {state === 'empty' && <p>{text.noDelegations}</p>}
      {state === 'ready' && (
        <div className="table-scroll">
          <table className="data-table">
            <caption className="visually-hidden">{text.delegations}</caption>
            <thead><tr><th>{text.name}</th><th>{text.code}</th><th>{text.status}</th></tr></thead>
            <tbody>{rows.map((row) => <tr key={row.id}><td>{row.name}</td><td dir="ltr">{row.code}</td><td>{row.status}</td></tr>)}</tbody>
          </table>
        </div>
      )}
    </section>
  )
}

function ProjectionSection({ summary, locale }: { summary: ProjectionSummary; locale: Locale }) {
  const text = accessContextLabels[locale]
  return (
    <section aria-labelledby="access-context-projection-heading">
      <h2 id="access-context-projection-heading">{text.projection}</h2>
      <dl className="record-summary">
        <div><dt>{text.decisionId}</dt><dd dir="ltr">{summary.decisionId ?? '—'}</dd></div>
      </dl>
      <h3>{text.allowedActions}</h3>
      {summary.actions.length
        ? <ul>{summary.actions.map((action) => <li key={action} dir="ltr">{action}</li>)}</ul>
        : <p>{text.noActions}</p>}
      <h3>{text.fieldAccess}</h3>
      {summary.fields.length
        ? (
          <div className="table-scroll">
            <table className="data-table">
              <caption className="visually-hidden">{text.fieldAccess}</caption>
              <thead><tr><th>{text.field}</th><th>{text.access}</th></tr></thead>
              <tbody>
                {summary.fields.map((row) => (
                  <tr key={row.name}>
                    <td dir="ltr">{row.name}</td>
                    <td>{row.state === 'masked' ? '***' : fieldStateLabel(row.state, locale)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
        : <p>{text.noFieldAccess}</p>}
    </section>
  )
}

export function AccessContext({ locale = 'ar', token, onSessionExpired, projection }: {
  locale?: Locale
  token: string
  onSessionExpired: () => void
  projection?: AccessProjection | null
}) {
  const text = accessContextLabels[locale]
  const [state, setState] = useState<AccessContextState>('loading')
  const [principal, setPrincipal] = useState<PrincipalView | null>(null)
  const [scopes, setScopes] = useState<ScopeSelectionView | null>(null)
  const [delegations, setDelegations] = useState<DelegationRow[]>([])
  const [delegationState, setDelegationState] = useState<DelegationState>('loading')
  const [scopeError, setScopeError] = useState<string | null>(null)
  const [selectingScope, setSelectingScope] = useState(false)

  const load = useCallback(async () => {
    setState('loading')
    try {
      const [principalView, scopeView] = await Promise.all([loadPrincipal(token), loadScopeSelection(token)])
      setPrincipal(principalView)
      setScopes(scopeView)
      setState(isContextEmpty(principalView, scopeView) ? 'empty' : 'ready')
    } catch (error) {
      const resolution = stateFromError(error)
      if (resolution === 'session-expired') {
        onSessionExpired()
        setState('error')
        return
      }
      setState(resolution)
    }
  }, [token, onSessionExpired])

  useEffect(() => { void load() }, [load])

  useEffect(() => {
    let active = true
    setDelegationState('loading')
    listAuthorization('delegations', token)
      .then((items) => {
        if (!active) return
        setDelegations(delegationRows(items))
        setDelegationState(items.length ? 'ready' : 'empty')
      })
      .catch((error: unknown) => {
        if (!active) return
        const resolution = stateFromError(error)
        if (resolution === 'session-expired') {
          onSessionExpired()
          return
        }
        setDelegationState(resolution === 'forbidden' ? 'forbidden' : 'unavailable')
      })
    return () => { active = false }
  }, [token, onSessionExpired])

  const changeScope = useCallback(async (scopeType: string, scopeId: string) => {
    setSelectingScope(true)
    setScopeError(null)
    try {
      if (!scopes) throw new ApiError(412, { type: 'precondition-failed', title: 'Scope version unavailable', status: 412 })
      setScopes(await updateScopeSelection(token, scopes, scopeType, scopeId))
    } catch (error) {
      const resolution = stateFromError(error)
      if (resolution === 'session-expired') {
        onSessionExpired()
        return
      }
      setScopeError(resolution === 'forbidden' ? text.forbidden : text.error)
    } finally {
      setSelectingScope(false)
    }
  }, [scopes, token, onSessionExpired, text.forbidden, text.error])

  const summary = projectionSummary(projection)

  return (
    <section className="organization-page authorization-page access-context-page" aria-labelledby="access-context-heading" dir={directionForLocale(locale)}>
      <div className="page-heading page-heading-copy">
        <div><h1 id="access-context-heading">{text.title}</h1><p>{text.intro}</p></div>
      </div>
      <ContextStatePanel state={state} locale={locale} onRetry={() => void load()} />
      {state === 'ready' && principal && scopes && (
        <>
          <dl className="record-summary access-context-summary">
            <div><dt>{text.subject}</dt><dd dir="ltr">{principal.subjectId || '—'}</dd></div>
            <div><dt>{text.clearance}</dt><dd>{clearanceLabel(principal.clearance, locale)}</dd></div>
            <div><dt>{text.breakGlass}</dt><dd>{principal.breakGlass ? text.breakGlassOn : text.breakGlassOff}</dd></div>
            <div><dt>{text.organizationUnits}</dt><dd>{principal.organizationUnitCount}</dd></div>
            <div><dt>{text.correlationId}</dt><dd dir="ltr">{principal.correlationId || '—'}</dd></div>
          </dl>
          <ScopeSection scopes={scopes} locale={locale} selecting={selectingScope} error={scopeError} onSelect={(scopeType, scopeId) => void changeScope(scopeType, scopeId)} />
          <section aria-labelledby="access-context-roles-heading">
            <h2 id="access-context-roles-heading">{text.roles}</h2>
            {principal.roles.length
              ? <ul>{principal.roles.map((role) => <li key={role} dir="ltr">{role}</li>)}</ul>
              : <p>{text.noRoles}</p>}
          </section>
          <DelegationSection state={delegationState} rows={delegations} locale={locale} />
          {summary && <ProjectionSection summary={summary} locale={locale} />}
        </>
      )}
    </section>
  )
}
