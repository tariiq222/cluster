import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { CalendarClock } from 'lucide-react'

import {
  ApiError,
  createTemporaryAssignment,
  listOrganizationUnits,
  listTemporaryAssignments,
  revokeTemporaryAssignment,
  type OrganizationUnit,
  type TemporaryAssignment,
} from '../../api'
import {
  Button,
  EmptyState,
  Field as UiField,
  InlineError,
  Page,
  PageHeader,
  PageSection,
  Panel,
  PanelGrid,
  Select,
} from '../../ui'

type Locale = 'ar' | 'en'
type PageState = 'ready' | 'forbidden' | 'not-found' | 'error'
type MutationError = 'validation' | 'conflict' | 'refresh' | 'save' | null

const copy = {
  ar: {
    title: 'التكليفات المؤقتة', intro: 'إدارة صلاحيات مؤقتة محددة المدة لوحدة تنظيمية واحدة في كل مرة.',
    loading: 'جارٍ تحميل التكليفات المؤقتة…', forbidden: 'لا تملك صلاحية إدارة التكليفات المؤقتة.',
    notFound: 'الوحدة التنظيمية أو التكليف المطلوب لم يعد متاحاً.', error: 'تعذر تحميل التكليفات المؤقتة.', retry: 'إعادة المحاولة',
    unit: 'الوحدة التنظيمية', noUnits: 'أنشئ وحدة تنظيمية قبل إدارة التكليفات المؤقتة.', assignments: 'التكليفات الحالية',
    noAssignments: 'لا توجد تكليفات مؤقتة لهذه الوحدة.', personId: 'معرف الشخص', capabilities: 'رموز الصلاحيات',
    capabilitiesHelp: 'أدخل رمزاً واحداً في كل سطر أو افصل بينها بفاصلة.', reason: 'سبب التكليف', startAt: 'تاريخ ووقت البداية', endAt: 'تاريخ ووقت النهاية',
    create: 'إضافة تكليف مؤقت', saving: 'جارٍ الحفظ…', status: 'الحالة', scheduled: 'مجدول', active: 'نشط', expired: 'منتهٍ', revoked: 'ملغى',
    start: 'البداية', end: 'النهاية', revoke: 'إلغاء التكليف', revokeReason: 'سبب الإلغاء', revoking: 'جارٍ الإلغاء…',
    validation: 'أكمل الحقول المطلوبة: سبب واضح، رموز صلاحيات فريدة، وفترة UTC لا تتجاوز 90 يوماً وتبدأ من الآن أو بعده.',
    conflict: 'تعارض التغيير مع حالة التكليف الحالية. حدّث القائمة ثم أعد المحاولة.', refresh: 'تغيّرت نسخة التكليف على الخادم. حدّث القائمة قبل المحاولة مجدداً.',
    saveError: 'لم يُحفظ التغيير. راجع البيانات ثم أعد المحاولة.', revokeValidation: 'أدخل سبباً واضحاً لإلغاء التكليف.',
    searchUnits: 'ابحث عن وحدة…',
    noMatchingResults: 'لا توجد نتائج مطابقة',
  },
  en: {
    title: 'Temporary assignments', intro: 'Manage time-bound permissions for one organization unit at a time.',
    loading: 'Loading temporary assignments…', forbidden: 'You do not have permission to manage temporary assignments.',
    notFound: 'The organization unit or requested assignment is no longer available.', error: 'Temporary assignments could not be loaded.', retry: 'Try again',
    unit: 'Organization unit', noUnits: 'Create an organization unit before managing temporary assignments.', assignments: 'Current assignments',
    noAssignments: 'There are no temporary assignments for this unit.', personId: 'Person ID', capabilities: 'Capability codes',
    capabilitiesHelp: 'Enter one code per line or separate codes with commas.', reason: 'Assignment reason', startAt: 'Start date and time', endAt: 'End date and time',
    create: 'Add temporary assignment', saving: 'Saving…', status: 'Status', scheduled: 'Scheduled', active: 'Active', expired: 'Expired', revoked: 'Revoked',
    start: 'Start', end: 'End', revoke: 'Revoke assignment', revokeReason: 'Revocation reason', revoking: 'Revoking…',
    validation: 'Complete the required fields: a clear reason, unique capability codes, and a UTC period of no more than 90 days starting now or later.',
    conflict: 'The change conflicts with the assignment’s current state. Refresh the list and try again.', refresh: 'The assignment changed on the server. Refresh the list before trying again.',
    saveError: 'The change was not saved. Review the data and try again.', revokeValidation: 'Enter a clear reason for revoking the assignment.',
    searchUnits: 'Search units…',
    noMatchingResults: 'No matching results',
  },
} as const

export function TemporaryAssignments() {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [units, setUnits] = useState<OrganizationUnit[]>([])
  const [organizationUnitId, setOrganizationUnitId] = useState('')
  const [assignments, setAssignments] = useState<TemporaryAssignment[]>([])
  const [loading, setLoading] = useState(true)
  const [state, setState] = useState<PageState>('ready')

  function handleFailure(error: unknown) {
    if (error instanceof ApiError && error.status === 403) {
      setState('forbidden')
    } else if (error instanceof ApiError && error.status === 404) {
      setState('not-found')
    } else {
      setState('error')
    }
  }

  async function loadAssignments(unitId = organizationUnitId) {
    if (!unitId) {
      setAssignments([])
      setLoading(false)
      return
    }
    setLoading(true)
    setState('ready')
    try {
      const collection = await listTemporaryAssignments(unitId)
      if (organizationUnitId === unitId) setAssignments(collection.items)
    } catch (error) {
      if (organizationUnitId === unitId) {
        setAssignments([])
        handleFailure(error)
      }
    } finally {
      if (organizationUnitId === unitId) setLoading(false)
    }
  }

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setState('ready')
    void listOrganizationUnits(token)
      .then((collection) => {
        if (cancelled) return
        setUnits(collection.items)
        setOrganizationUnitId(collection.items[0]?.id ?? '')
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setUnits([])
          setOrganizationUnitId('')
          handleFailure(error)
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
    // Units are tied to the authenticated session only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  useEffect(() => {
    if (organizationUnitId) void loadAssignments(organizationUnitId)
    setAssignments([])
    // The list is scoped exclusively to the selected organization unit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [organizationUnitId])

  return <Page>
    <PageHeader id="temporary-assignments-heading" title={text.title} description={text.intro} />
    {loading && <div className="skeleton-list" role="status" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
    {!loading && (state === 'forbidden' || state === 'not-found') && (
      <div className="state-panel" role="status"><p>{state === 'forbidden' ? text.forbidden : text.notFound}</p></div>
    )}
    {!loading && state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void loadAssignments()} />}
    {!loading && state === 'ready' && <PanelGrid>
      <Panel id="temporary-unit-heading" level={2} title={<label htmlFor="temporary-unit">{text.unit}</label>}>
        <div className="field">
          <Select
            id="temporary-unit"
            value={organizationUnitId}
            onChange={setOrganizationUnitId}
            disabled={units.length === 0}
            options={units.map((unit) => ({
              value: unit.id,
              label: locale === 'en' && unit.name_en ? unit.name_en : unit.name_ar,
            }))}
            searchPlaceholder={copy[locale].searchUnits}
            emptyLabel={copy[locale].noMatchingResults}
          />
        </div>
        {units.length === 0 ? <p className="status-message" role="status">{text.noUnits}</p> : (
          <PageSection
            id="temporary-assignments-list-heading"
            title={text.assignments}
            actions={<span className="count-badge">{formatNumber(assignments.length, locale)}</span>}
          >
            {assignments.length === 0
              ? <EmptyState icon={<CalendarClock />} title={text.noAssignments} />
              : <AssignmentsTable assignments={assignments} locale={locale} token={token} onFailure={handleFailure} onRefresh={() => void loadAssignments()} onRevoked={(assignment) => setAssignments((current) => current.map((item) => item.id === assignment.id ? assignment : item))} />}
            <TemporaryAssignmentForm locale={locale} token={token} organizationUnitId={organizationUnitId} onFailure={handleFailure} onCreated={(assignment) => setAssignments((current) => [assignment, ...current.filter((item) => item.id !== assignment.id)])} />
          </PageSection>
        )}
      </Panel>
    </PanelGrid>}
  </Page>
}

function AssignmentsTable({ assignments, locale, token, onFailure, onRefresh, onRevoked }: {
  assignments: TemporaryAssignment[]; locale: Locale; token: string;  onFailure: (error: unknown) => void; onRefresh: () => void; onRevoked: (assignment: TemporaryAssignment) => void
}) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.assignments}><table><thead><tr><th scope="col">{text.personId}</th><th scope="col">{text.capabilities}</th><th scope="col">{text.start}</th><th scope="col">{text.end}</th><th scope="col">{text.status}</th><th scope="col">{text.revoke}</th></tr></thead><tbody>{assignments.map((assignment) => <tr key={assignment.id}><td dir="ltr">{assignment.person_id}</td><td dir="ltr">{assignment.capability_codes.join(', ')}</td><td><time dateTime={assignment.start_at}>{formatDate(assignment.start_at, locale)}</time></td><td><time dateTime={assignment.end_at}>{formatDate(assignment.end_at, locale)}</time></td><td><span className="status-badge">{text[assignment.status]}</span></td><td>{assignment.status === 'scheduled' || assignment.status === 'active' ? <RevokeForm assignment={assignment} locale={locale} token={token} onFailure={onFailure} onRefresh={onRefresh} onRevoked={onRevoked} /> : '—'}</td></tr>)}</tbody></table></div>
}

function TemporaryAssignmentForm({ locale, token, organizationUnitId, onFailure, onCreated }: {
  locale: Locale; token: string; organizationUnitId: string;  onFailure: (error: unknown) => void; onCreated: (assignment: TemporaryAssignment) => void
}) {
  const text = copy[locale]
  const [personId, setPersonId] = useState('')
  const [capabilityCodes, setCapabilityCodes] = useState('')
  const [reason, setReason] = useState('')
  const [startAt, setStartAt] = useState('')
  const [endAt, setEndAt] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<MutationError>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const start = toUtcTimestamp(startAt)
    const end = toUtcTimestamp(endAt)
    const capabilities = capabilityCodes.split(/[\n,]/).map((code) => code.trim()).filter(Boolean)
    const now = Date.now()
    if (!personId.trim() || !reason.trim() || !start || !end || start.valueOf() < now || end <= start || end.valueOf() - start.valueOf() > 90 * 24 * 60 * 60 * 1000 || capabilities.length === 0 || new Set(capabilities).size !== capabilities.length) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const assignment = await createTemporaryAssignment(token, { person_id: personId.trim(), organization_unit_id: organizationUnitId, capability_codes: capabilities, reason: reason.trim(), start_at: start.toISOString(), end_at: end.toISOString() })
      onCreated(assignment)
      setPersonId(''); setCapabilityCodes(''); setReason(''); setStartAt(''); setEndAt('')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 403) onFailure(failure)
      if (failure instanceof ApiError && failure.status === 404) onFailure(failure)
      {
        setError(failure instanceof ApiError && failure.status === 409 ? 'conflict' : 'save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
      }
    } finally { setSubmitting(false) }
  }

  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>
    {error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text[error === 'validation' ? 'validation' : error === 'conflict' ? 'conflict' : 'saveError']}</p>}
    <div className="field-row">
      <Field id="temporary-person-id" label={text.personId} value={personId} onChange={setPersonId} required invalid={Boolean(error && !personId.trim())} direction="ltr" />
      <div className="field"><label htmlFor="temporary-capability-codes">{text.capabilities}<span aria-hidden="true"> *</span></label><textarea id="temporary-capability-codes" value={capabilityCodes} required aria-required="true" aria-invalid={Boolean(error)} aria-describedby="temporary-capability-codes-help" onChange={(event) => setCapabilityCodes(event.target.value)} dir="ltr" /><p id="temporary-capability-codes-help" className="field-help">{text.capabilitiesHelp}</p></div>
      <Field id="temporary-reason" label={text.reason} value={reason} onChange={setReason} required invalid={Boolean(error && !reason.trim())} />
      <Field id="temporary-start" label={text.startAt} type="datetime-local" value={startAt} onChange={setStartAt} required invalid={Boolean(error)} direction="ltr" />
      <Field id="temporary-end" label={text.endAt} type="datetime-local" value={endAt} onChange={setEndAt} required invalid={Boolean(error)} direction="ltr" />
    </div>
    <Button type="submit" disabled={submitting}>{submitting ? text.saving : text.create}</Button>
  </form>
}

function RevokeForm({ assignment, locale, token, onFailure, onRefresh, onRevoked }: {
  assignment: TemporaryAssignment; locale: Locale; token: string;  onFailure: (error: unknown) => void; onRefresh: () => void; onRevoked: (assignment: TemporaryAssignment) => void
}) {
  const text = copy[locale]
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<MutationError>(null)
  const errorRef = useRef<HTMLDivElement>(null)
  const fieldId = `revoke-reason-${assignment.id}`

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!reason.trim()) {
      setError('validation')
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      onRevoked(await revokeTemporaryAssignment(token, assignment.id, `"${assignment.lock_version}"`, reason.trim()))
    } catch (failure) {
      if (failure instanceof ApiError && (failure.status === 403 || failure.status === 404)) onFailure(failure)
      {
        setError(failure instanceof ApiError && failure.status === 412 ? 'refresh' : failure instanceof ApiError && failure.status === 409 ? 'conflict' : 'save')
        window.requestAnimationFrame(() => errorRef.current?.focus())
      }
    } finally { setSubmitting(false) }
  }

  return <form onSubmit={(event) => void submit(event)} noValidate>
    {error && <div className="field-error" role="alert" tabIndex={-1} ref={errorRef}><p>{text[error === 'validation' ? 'revokeValidation' : error === 'conflict' ? 'conflict' : error === 'refresh' ? 'refresh' : 'saveError']}</p>{error === 'refresh' && <Button variant="secondary" onClick={onRefresh}>{text.retry}</Button>}</div>}
    <label htmlFor={fieldId}>{text.revokeReason}</label>
    <input id={fieldId} value={reason} required aria-required="true" aria-invalid={Boolean(error)} onChange={(event) => setReason(event.target.value)} />
    <Button variant="secondary" type="submit" disabled={submitting}>{submitting ? text.revoking : text.revoke}</Button>
  </form>
}

function Field({ id, label, value, onChange, type = 'text', required = false, invalid = false, direction }: {
  id: string; label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; invalid?: boolean; direction?: 'ltr'
}) {
  return <UiField id={id} label={label} required={required}><input id={id} type={type} value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} dir={direction} /></UiField>
}

function toUtcTimestamp(value: string): Date | null {
  if (!value) return null
  const timestamp = new Date(value)
  return Number.isNaN(timestamp.valueOf()) ? null : timestamp
}

function formatNumber(value: number, locale: Locale) { return new Intl.NumberFormat(formattingLocale(locale)).format(value) }
function formatDate(value: string, locale: Locale) { return new Intl.DateTimeFormat(formattingLocale(locale), { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Riyadh' }).format(new Date(value)) }
