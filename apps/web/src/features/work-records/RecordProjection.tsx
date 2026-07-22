import { useEffect, useState, type ReactNode } from 'react'
import { FolderSearch } from 'lucide-react'
import { recordStatusLabel, directionForLocale } from '../../app/copy'
import type { AccessProjection, AuthorizedWorkRecord, FieldAccessState } from '../../api/r1'
import { Button, EmptyState, Field, Page, Panel, SkeletonList } from '../../ui'
import { RecordField, formatRecordValue } from './RecordField'

export type RecordProjectionState = 'loading' | 'ready' | 'empty' | 'forbidden' | 'conflict' | 'stale'
export type RecordProjectionActionState = 'idle' | 'done' | 'error' | 'stale'

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
  onAction?: (action: string, reason?: string) => void
  busy?: boolean
  actionState?: RecordProjectionActionState
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
  ] as const).flatMap(([name, value]): ProjectedSummaryField[] => {
    const access = resolveFieldAccess(record.field_access, name)
    if (access === undefined || access === 'hidden') return []
    return [{ name, value: access === 'masked' ? '***' : value, access }]
  })
}

const text = {
  ar: {
    title: 'سجل العمل', loading: 'جارٍ تحميل السجل…', empty: 'لا يوجد سجل لعرضه', forbidden: 'لا تملك صلاحية عرض هذا المورد.',
    conflict: 'تعذر حفظ التغيير لأن السجل تعارض مع نسخة أحدث.', stale: 'هذه البيانات قديمة. حدّث السجل قبل المتابعة.', retry: 'إعادة المحاولة',
    refresh: 'تحديث', actions: 'الإجراءات المسموح بها', diagnostics: 'تشخيص قرار الوصول', decisionId: 'معرّف القرار', recordNumber: 'رقم السجل',
    status: 'الحالة', classification: 'التصنيف', noActions: 'لا توجد إجراءات مسموح بها.',
    busy: 'جارٍ تنفيذ الإجراء…', actionDone: 'اكتمل الإجراء.', actionError: 'تعذر تنفيذ الإجراء.', actionStale: 'البيانات قديمة؛ تم طلب التحديث.',
    actionUnavailable: 'هذا الإجراء غير متاح في هذه الشاشة.', unsupportedAction: 'لا يوجد مسار عميل معتمد لهذا الإجراء.', refreshUnavailable: 'لا يتوفر تحديث تلقائي لهذا السجل.',
    reason: 'سبب الإجراء (مطلوب للإلغاء والأرشفة)', reasonRequired: 'أدخل سبباً قبل تنفيذ الإلغاء أو الأرشفة.',
  },
  en: {
    title: 'Work record', loading: 'Loading record…', empty: 'There is no record to display', forbidden: 'You do not have permission to view this resource.',
    conflict: 'The change could not be saved because the record conflicts with a newer version.', stale: 'This data is stale. Refresh the record before continuing.', retry: 'Try again',
    refresh: 'Refresh', actions: 'Allowed actions', diagnostics: 'Access decision diagnostics', decisionId: 'Decision ID', recordNumber: 'Record number',
    status: 'Status', classification: 'Classification', noActions: 'No actions are allowed.',
    busy: 'Applying the action…', actionDone: 'Action completed.', actionError: 'The action failed.', actionStale: 'The data is stale; refresh requested.',
    actionUnavailable: 'This action is unavailable on this screen.', unsupportedAction: 'No approved client path exists for this action.', refreshUnavailable: 'A record refresh is not available here.',
    reason: 'Action reason (required for cancel and archive)', reasonRequired: 'Enter a reason before cancelling or archiving.',
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

const EXECUTABLE_WORK_RECORD_ACTIONS = new Set(['submit', 'return', 'complete', 'complete-submission', 'cancel', 'archive'])

export function isWorkRecordActionExecutable(action: string): boolean {
  return EXECUTABLE_WORK_RECORD_ACTIONS.has(action)
}

export function workRecordActionRequiresReason(action: string): boolean {
  return action === 'cancel' || action === 'archive'
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
  const message = projectionStateMessage(state, locale ?? 'ar')
  return (
    <div className="record-state-panel" role={isAlert ? 'alert' : 'status'} aria-live="polite">
      {state === 'empty' ? <EmptyState icon={<FolderSearch />} title={message} /> : <p>{message}</p>}
      {(state === 'conflict' || state === 'stale') && (onRefresh ? <Button variant="secondary" onClick={onRefresh}>{t.refresh}</Button> : <p>{t.refreshUnavailable}</p>)}
      {state === 'loading' && <span aria-hidden="true">…</span>}
      {state === 'loading' && <SkeletonList label={message} />}
      {state !== 'forbidden' && state !== 'empty' && state !== 'loading' && onRetry && <Button variant="secondary" onClick={onRetry}>{t.retry}</Button>}
    </div>
  )
}

function recordTitle(record: AuthorizedWorkRecord): string {
  const payload = record.payload as Record<string, unknown>
  if (typeof payload.title === 'string' && payload.title !== '') {
    return payload.title
  }
  return record.record_number
}


export function RecordProjection({ record, state = record ? 'ready' : 'empty', locale = 'ar', direction = directionForLocale(locale), decisionTrace, onAction, busy = false, actionState = 'idle', onRetry, onRefresh, onFieldChange }: RecordProjectionProps) {
  const t = text[locale]
  const fields = record ? projectRecordFields(record) : []
  const summaryFields = record ? projectRecordSummary(record) : []
  const effectiveState = state === 'ready' && !record ? 'empty' : state
  const needsActionReason = Boolean(record?.allowed_actions.some(workRecordActionRequiresReason))
  const [actionReason, setActionReason] = useState('')
  useEffect(() => { setActionReason('') }, [record?.id])

  return (
    <Page>
      <section className="record-projection" dir={direction} aria-labelledby="record-projection-heading">
        {record && <h1 className="record-projection-title">{recordTitle(record)}</h1>}
        <div className="ui-section-heading"><h2 id="record-projection-heading">{t.title}</h2></div>
        <StatePanel state={effectiveState} locale={locale} onRetry={onRetry} onRefresh={onRefresh} />
      {busy && <p className="status-message" role="status" aria-live="polite">{t.busy}</p>}
      {!busy && actionState !== 'idle' && <p className={actionState === 'done' ? 'status-message' : 'error-summary'} role={actionState === 'done' ? 'status' : 'alert'} aria-live="polite">{actionState === 'done' ? t.actionDone : actionState === 'stale' ? t.actionStale : t.actionError}</p>}
      {effectiveState === 'ready' && record && (
        <>
          <dl className="record-summary">
            {summaryFields.map((field) => <div key={field.name}><dt>{field.name === 'record_number' ? t.recordNumber : field.name === 'status' ? t.status : t.classification}</dt><dd>{field.name === 'status' && field.access !== 'masked' ? recordStatusLabel(String(field.value), locale) : formatRecordValue(field.value, locale)}</dd></div>)}
          </dl>
          <div className="record-fields">{fields.map((field) => <RecordField key={field.name} {...field} locale={locale} onChange={onFieldChange ? (value) => onFieldChange(field.name, value) : undefined} />)}</div>
          {needsActionReason && <Field id="record-action-reason" label={t.reason} required><textarea id="record-action-reason" value={actionReason} onChange={(event) => setActionReason(event.target.value)} required rows={3} /></Field>}
          <Panel id="record-actions-heading" title={t.actions} level={3}>{record.allowed_actions.length ? <div className="record-actions">{record.allowed_actions.map((action: string) => {
            const requiresReason = workRecordActionRequiresReason(action)
            const missingReason = requiresReason && !actionReason.trim()
            const executable = Boolean(onAction) && isWorkRecordActionExecutable(action) && !missingReason
            const reason = !onAction ? t.actionUnavailable : t.unsupportedAction
            const reasonId = `record-action-${action.replace(/[^a-z0-9-]/gi, '-')}-reason`
            return executable
              ? <Button key={action} onClick={() => onAction?.(action, requiresReason ? actionReason : undefined)} disabled={busy}>{action}</Button>
              : <span className="record-action-unavailable" key={action}><Button disabled aria-describedby={reasonId} title={missingReason ? t.reasonRequired : reason}>{action}</Button><span id={reasonId}>{missingReason ? t.reasonRequired : reason}</span></span>
          })}</div> : <p>{t.noActions}</p>}</Panel>
          <Trace record={record} trace={decisionTrace} locale={locale} />
        </>
      )}
      </section>
    </Page>
  )
}
