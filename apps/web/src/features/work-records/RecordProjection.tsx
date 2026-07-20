import type { ReactNode } from 'react'
import type { AccessProjection, AuthorizedWorkRecord, FieldAccessState } from '../../api/r1'
import { RecordField, formatRecordValue } from './RecordField'

export type RecordProjectionState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'conflict' | 'stale'

export type DecisionTrace = {
  label: string
  value: string
}

export type ProjectedRecordField = {
  name: string
  value: unknown
  access: FieldAccessState
}

export type ProjectedSummaryField = {
  name: 'record_number' | 'status' | 'classification'
  value: unknown
  access: FieldAccessState
}

export type RecordProjectionProps = {
  record?: AuthorizedWorkRecord | null
  state?: RecordProjectionState
  locale?: 'ar' | 'en'
  direction?: 'rtl' | 'ltr'
  decisionTrace?: DecisionTrace[] | ReactNode
  onAction?: (action: string) => void
  onRetry?: () => void
  onRefresh?: () => void
  onFieldChange?: (name: string, value: string) => void
}

export function resolveFieldAccess(fieldAccess: Record<string, FieldAccessState>, name: string): FieldAccessState | undefined {
  return fieldAccess[name] ?? fieldAccess['*']
}

export function projectRecordSummary(record: AuthorizedWorkRecord): ProjectedSummaryField[] {
  return ([
    ['record_number', record.record_number],
    ['status', record.status],
    ['classification', record.classification],
  ] as const)
    .map(([name, value]) => ({ name, value, access: resolveFieldAccess(record.field_access, name) }))
    .filter((field): field is ProjectedSummaryField => field.access !== undefined && field.access !== 'hidden')
    .map((field) => ({ ...field, value: field.access === 'masked' ? '***' : field.value }))
}

const text = {
  ar: {
    title: 'سجل العمل', loading: 'جارٍ تحميل السجل…', empty: 'لا يوجد سجل لعرضه', forbidden: 'لا تملك صلاحية عرض هذا المورد.',
    conflict: 'تعذر حفظ التغيير لأن السجل تعارض مع نسخة أحدث.', stale: 'هذه البيانات قديمة. حدّث السجل قبل المتابعة.', retry: 'إعادة المحاولة',
    refresh: 'تحديث', actions: 'الإجراءات المسموح بها', diagnostics: 'تشخيص قرار الوصول', decisionId: 'معرّف القرار', recordNumber: 'رقم السجل',
    status: 'الحالة', classification: 'التصنيف', noActions: 'لا توجد إجراءات مسموح بها.',
  },
  en: {
    title: 'Work record', loading: 'Loading record…', empty: 'There is no record to display', forbidden: 'You do not have permission to view this resource.',
    conflict: 'The change could not be saved because the record conflicts with a newer version.', stale: 'This data is stale. Refresh the record before continuing.', retry: 'Try again',
    refresh: 'Refresh', actions: 'Allowed actions', diagnostics: 'Access decision diagnostics', decisionId: 'Decision ID', recordNumber: 'Record number',
    status: 'Status', classification: 'Classification', noActions: 'No actions are allowed.',
  },
} as const

/** Projects only fields explicitly returned with a non-hidden access state. */
export function projectRecordFields(record: AuthorizedWorkRecord): ProjectedRecordField[] {
  return Object.keys(record.payload)
    .filter((name): name is string => {
      const access = resolveFieldAccess(record.field_access, name)
      return access !== undefined && access !== 'hidden'
    })
    .map((name) => {
      const access = resolveFieldAccess(record.field_access, name) as FieldAccessState
      return { name, value: access === 'masked' ? '***' : record.payload[name], access }
    })
}

export function isActionAllowed(projection: AccessProjection, action: string): boolean {
  return projection.allowed_actions.includes(action)
}

export function projectionStateMessage(state: RecordProjectionState, locale: 'ar' | 'en' = 'ar'): string {
  const t = text[locale]
  return state === 'loading' ? t.loading : state === 'empty' ? t.empty : state === 'forbidden' ? t.forbidden : state === 'conflict' ? t.conflict : t.stale
}

function Trace({ record, trace, locale }: { record: AuthorizedWorkRecord; trace?: DecisionTrace[] | ReactNode; locale: 'ar' | 'en' }) {
  const t = text[locale]
  return (
    <details className="record-decision-trace">
      <summary>{t.diagnostics}</summary>
      <dl>
        <div><dt>{t.decisionId}</dt><dd>{record.decision_id ?? '—'}</dd></div>
        {Array.isArray(trace) ? trace.map((item) => <div key={`${item.label}-${item.value}`}><dt>{item.label}</dt><dd>{item.value}</dd></div>) : trace}
      </dl>
    </details>
  )
}

function StatePanel({ state, locale, onRetry, onRefresh }: Pick<RecordProjectionProps, 'locale' | 'onRetry' | 'onRefresh'> & { state: RecordProjectionState }) {
  const t = text[locale ?? 'ar']
  if (state === 'ready') return null
  const isAlert = state !== 'loading' && state !== 'empty'
  return (
    <div className="record-state-panel" role={isAlert ? 'alert' : 'status'} aria-live="polite">
      <p>{projectionStateMessage(state, locale ?? 'ar')}</p>
      {(state === 'conflict' || state === 'stale') && <button type="button" onClick={onRefresh}>{t.refresh}</button>}
      {state === 'loading' && <span aria-hidden="true">…</span>}
      {state !== 'forbidden' && state !== 'empty' && state !== 'loading' && onRetry && <button type="button" onClick={onRetry}>{t.retry}</button>}
    </div>
  )
}

export function RecordProjection({ record, state = record ? 'ready' : 'empty', locale = 'ar', direction = locale === 'ar' ? 'rtl' : 'ltr', decisionTrace, onAction, onRetry, onRefresh, onFieldChange }: RecordProjectionProps) {
  const t = text[locale]
  const fields = record ? projectRecordFields(record) : []
  const summaryFields = record ? projectRecordSummary(record) : []
  const effectiveState = state === 'ready' && !record ? 'empty' : state

  return (
    <section className="record-projection" dir={direction} aria-labelledby="record-projection-heading">
      <h2 id="record-projection-heading">{t.title}</h2>
      <StatePanel state={effectiveState} locale={locale} onRetry={onRetry} onRefresh={onRefresh} />
      {effectiveState === 'ready' && record && (
        <>
          <dl className="record-summary">
            {summaryFields.map((field) => <div key={field.name}><dt>{field.name === 'record_number' ? t.recordNumber : field.name === 'status' ? t.status : t.classification}</dt><dd>{field.name === 'record_number' && field.access !== 'masked' ? formatRecordValue(field.value, locale) : field.value}</dd></div>)}
          </dl>
          <div className="record-fields">{fields.map((field) => <RecordField key={field.name} {...field} locale={locale} onChange={onFieldChange ? (value) => onFieldChange(field.name, value) : undefined} />)}</div>
          <section aria-labelledby="record-actions-heading"><h3 id="record-actions-heading">{t.actions}</h3>{record.allowed_actions.length ? <div className="record-actions">{record.allowed_actions.map((action) => <button type="button" key={action} onClick={() => onAction?.(action)} disabled={!onAction}>{action}</button>)}</div> : <p>{t.noActions}</p>}</section>
          <Trace record={record} trace={decisionTrace} locale={locale} />
        </>
      )}
    </section>
  )
}
