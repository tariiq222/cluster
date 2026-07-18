import { type FormEvent, useEffect, useRef, useState } from 'react'

import {
  ApiError,
  completeDocumentUpload,
  getImportJob,
  initiateDocumentUpload,
  listImportJobRows,
  submitImportJob,
  transitionImportJob,
  type ImportJob,
  type ImportJobAction,
  type ImportJobRow,
} from '../../api'

type Locale = 'ar' | 'en'

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const copy = {
  ar: {
    title: 'مراجعة الاستيراد', intro: 'تابع ملف people_assignments من مرجع quarantine من دون عرض الصفوف الخام.',
    uploadTitle: 'رفع ملف الاستيراد', uploadHelp: 'اختر ملف CSV لرفعه مباشرة إلى منطقة الحجر.', file: 'ملف CSV', upload: 'رفع الملف', uploadReady: 'جاهز لرفع الملف.', hashing: 'جارٍ تجهيز الملف…', requestingUpload: 'جارٍ طلب تصريح الرفع…', uploading: 'جارٍ رفع الملف…', completingUpload: 'جارٍ تأكيد الرفع…', uploadComplete: 'اكتمل رفع الملف. راجع مرجع الحجر ثم أنشئ مهمة الاستيراد.', fileRequired: 'اختر ملف CSV للمتابعة.', fileInvalid: 'اختر ملفاً بامتداد CSV.', fileTooLarge: 'حجم الملف غير مدعوم.', uploadError: 'تعذر رفع الملف. حاول مرة أخرى.', uploadHashError: 'تعذر تجهيز الملف للرفع. حاول مرة أخرى.', uploadName: 'مصدر استيراد تنظيمي',
    quarantineId: 'معرف quarantine', submit: 'إنشاء ImportJob', jobId: 'معرف ImportJob', open: 'فتح الاستيراد', saving: 'جارٍ التنفيذ…',
    loading: 'جارٍ تحميل الاستيراد…', forbidden: 'لا تملك صلاحية قراءة هذا الاستيراد.', notFound: 'الاستيراد غير موجود أو غير متاح.', error: 'تعذر تحميل الاستيراد.', retry: 'إعادة المحاولة',
    summary: 'ملخص الحالة', status: 'الحالة', template: 'القالب', total: 'إجمالي الصفوف', valid: 'صالحة', errors: 'بأخطاء', approver: 'المعتمد', notApproved: 'لم يعتمد بعد',
    rows: 'نتائج الصفوف المنقحة', noRows: 'لا توجد نتائج صفوف بعد.', row: 'الصف', proposed: 'الإجراء المقترح', decision: 'القرار', validationErrors: 'أخطاء التحقق', noErrors: 'بلا أخطاء',
    action: 'انتقال الحالة', reason: 'سبب الرفض أو الإلغاء', execute: 'تنفيذ', refresh: 'تحديث الحالة', validation: 'أدخل معرف UUIDv7 صالحاً.', transitionError: 'تعذر تنفيذ الانتقال. راجع الحالة والصلاحية.', stale: 'تغيرت نسخة الاستيراد. حدّث الحالة ثم أعد المحاولة.',
    received: 'مستلم', validated: 'تم التحقق', approved: 'معتمد', rejected: 'مرفوض', applied: 'مطبق', cancelled: 'ملغي', failed: 'فشل',
    validate: 'تحقق', approve: 'اعتماد', reject: 'رفض', apply: 'تطبيق', cancel: 'إلغاء', accepted: 'مقبول', rejectedDecision: 'مرفوض', create: 'إنشاء', skip: 'تخطي',
  },
  en: {
    title: 'Import review', intro: 'Follow a people_assignments file from its quarantine reference without exposing raw rows.',
    uploadTitle: 'Upload import file', uploadHelp: 'Choose a CSV file to upload directly to quarantine.', file: 'CSV file', upload: 'Upload file', uploadReady: 'Ready to upload the file.', hashing: 'Preparing file…', requestingUpload: 'Requesting upload permission…', uploading: 'Uploading file…', completingUpload: 'Confirming upload…', uploadComplete: 'File uploaded. Review the quarantine reference, then create the import job.', fileRequired: 'Choose a CSV file to continue.', fileInvalid: 'Choose a file with a CSV extension.', fileTooLarge: 'The file size is not supported.', uploadError: 'The file could not be uploaded. Try again.', uploadHashError: 'The file could not be prepared for upload. Try again.', uploadName: 'Organization import source',
    quarantineId: 'Quarantine ID', submit: 'Create ImportJob', jobId: 'ImportJob ID', open: 'Open import', saving: 'Working…',
    loading: 'Loading import…', forbidden: 'You do not have permission to read this import.', notFound: 'The import does not exist or is unavailable.', error: 'The import could not be loaded.', retry: 'Try again',
    summary: 'Status summary', status: 'Status', template: 'Template', total: 'Total rows', valid: 'Valid', errors: 'Errors', approver: 'Approver', notApproved: 'Not approved yet',
    rows: 'Redacted row results', noRows: 'No row results yet.', row: 'Row', proposed: 'Proposed action', decision: 'Decision', validationErrors: 'Validation errors', noErrors: 'No errors',
    action: 'State transition', reason: 'Rejection or cancellation reason', execute: 'Execute', refresh: 'Refresh status', validation: 'Enter a valid UUIDv7 identifier.', transitionError: 'The transition could not be completed. Review state and permission.', stale: 'The import version changed. Refresh and try again.',
    received: 'Received', validated: 'Validated', approved: 'Approved', rejected: 'Rejected', applied: 'Applied', cancelled: 'Cancelled', failed: 'Failed',
    validate: 'Validate', approve: 'Approve', reject: 'Reject', apply: 'Apply', cancel: 'Cancel', accepted: 'Accepted', rejectedDecision: 'Rejected', create: 'Create', skip: 'Skip',
  },
} as const

export function ImportReview({ locale, token, jobId, onJobOpen, onSessionExpired }: {
  locale: Locale; token: string; jobId?: string; onJobOpen: (jobId: string) => void; onSessionExpired: () => void
}) {
  const text = copy[locale]
  const [job, setJob] = useState<ImportJob | null>(null)
  const [rows, setRows] = useState<ImportJobRow[]>([])
  const [loading, setLoading] = useState(Boolean(jobId))
  const [state, setState] = useState<'ready' | 'forbidden' | 'not-found' | 'error'>('ready')
  const [quarantineId, setQuarantineId] = useState('')

  async function load() {
    if (!jobId) { setJob(null); setRows([]); setLoading(false); return }
    setLoading(true); setState('ready')
    try {
      const [jobValue, rowPage] = await Promise.all([getImportJob(token, jobId), listImportJobRows(token, jobId)])
      setJob(jobValue); setRows(rowPage.items)
    } catch (error) {
      setJob(null); setRows([])
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else if (error instanceof ApiError && error.status === 403) setState('forbidden')
      else if (error instanceof ApiError && error.status === 404) setState('not-found')
      else setState('error')
    } finally { setLoading(false) }
  }
  useEffect(() => {
    void load()
    // Import data is reloaded when the route job or session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [jobId, token])

  return <section className="organization-page" aria-labelledby="import-heading">
    <div className="page-heading page-heading-copy"><div><h1 id="import-heading">{text.title}</h1><p>{text.intro}</p></div></div>
    <ImportUpload locale={locale} csrfToken={token} onUploaded={setQuarantineId} onSessionExpired={onSessionExpired} />
    <div className="import-entry-grid"><SubmitForm locale={locale} token={token} quarantineId={quarantineId} onQuarantineIdChange={setQuarantineId} onSubmitted={(created) => onJobOpen(created.id)} onSessionExpired={onSessionExpired} /><OpenForm key={jobId ?? 'new'} locale={locale} initialValue={jobId ?? ''} onOpen={onJobOpen} /></div>
    <div aria-live="polite" aria-atomic="true">
      {loading && <div className="skeleton-list" aria-label={text.loading}>{[0, 1, 2].map((item) => <div className="skeleton-row" aria-hidden="true" key={item} />)}</div>}
      {!loading && state !== 'ready' && <div className="state-panel" role={state === 'error' ? 'alert' : 'status'}><p>{state === 'forbidden' ? text.forbidden : state === 'not-found' ? text.notFound : text.error}</p>{state === 'error' && <button type="button" className="secondary-button" onClick={() => void load()}>{text.retry}</button>}</div>}
      {!loading && state === 'ready' && job && <div className="organization-layout"><JobSummary job={job} locale={locale} /><section className="organization-section" aria-labelledby="import-rows-heading"><div className="section-heading"><h2 id="import-rows-heading">{text.rows}</h2><button type="button" className="secondary-button" onClick={() => void load()}>{text.refresh}</button></div>{rows.length === 0 ? <p>{text.noRows}</p> : <RowsTable rows={rows} locale={locale} />}</section><TransitionForm key={job.status} locale={locale} token={token} job={job} onChanged={(changed) => { setJob(changed); void load() }} onSessionExpired={onSessionExpired} /></div>}
    </div>
  </section>
}

function JobSummary({ job, locale }: { job: ImportJob; locale: Locale }) {
  const text = copy[locale]
  return <section className="organization-section" aria-labelledby="import-summary-heading"><h2 id="import-summary-heading">{text.summary}</h2><dl className="definition-grid"><div><dt>{text.status}</dt><dd><span className="status-badge">{text[job.status]}</span></dd></div><div><dt>{text.template}</dt><dd dir="ltr">{job.template_code}</dd></div><div><dt>{text.total}</dt><dd>{number(job.total_rows, locale)}</dd></div><div><dt>{text.valid}</dt><dd>{number(job.valid_rows, locale)}</dd></div><div><dt>{text.errors}</dt><dd>{number(job.error_rows, locale)}</dd></div><div><dt>{text.approver}</dt><dd dir="ltr">{job.approved_by_user_id ?? text.notApproved}</dd></div></dl></section>
}

function RowsTable({ rows, locale }: { rows: ImportJobRow[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.rows}><table><thead><tr><th scope="col">{text.row}</th><th scope="col">{text.proposed}</th><th scope="col">{text.decision}</th><th scope="col">{text.validationErrors}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{number(row.row_number, locale)}</td><td>{row.proposed_action ? text[row.proposed_action] : '—'}</td><td>{row.decision === 'accepted' ? text.accepted : row.decision === 'rejected' ? text.rejectedDecision : '—'}</td><td>{row.validation_errors.length === 0 ? text.noErrors : <ul className="inline-errors">{row.validation_errors.map((error) => <li key={`${error.code}-${error.field ?? ''}`}>{error.code}{error.field ? ` · ${error.field}` : ''}</li>)}</ul>}</td></tr>)}</tbody></table></div>
}

function ImportUpload({ locale, csrfToken, onUploaded, onSessionExpired }: { locale: Locale; csrfToken: string; onUploaded: (quarantineId: string) => void; onSessionExpired: () => void }) {
  const text = copy[locale]
  const [file, setFile] = useState<File | null>(null)
  const [phase, setPhase] = useState<'ready' | 'hashing' | 'requesting' | 'uploading' | 'completing' | 'complete'>('ready')
  const [error, setError] = useState<'file' | 'invalid' | 'size' | 'hash' | 'upload' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const busy = !['ready', 'complete'].includes(phase)
  const status = phase === 'hashing' ? text.hashing : phase === 'requesting' ? text.requestingUpload : phase === 'uploading' ? text.uploading : phase === 'completing' ? text.completingUpload : phase === 'complete' ? text.uploadComplete : text.uploadReady
  const errorMessage = error === 'file' ? text.fileRequired : error === 'invalid' ? text.fileInvalid : error === 'size' ? text.fileTooLarge : error === 'hash' ? text.uploadHashError : text.uploadError

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!file) return fail('file')
    if (!file.name.toLowerCase().endsWith('.csv')) return fail('invalid')
    if (file.size < 1 || file.size > 1_073_741_824) return fail('size')
    setError(null)
    try {
      setPhase('hashing')
      let sha256: string
      try { sha256 = await hashFile(file) } catch { return fail('hash') }
      setPhase('requesting')
      const ticket = await initiateDocumentUpload(csrfToken, {
        purpose: 'organization_import_source', name: text.uploadName, description: null, classification: 'confidential', file_name: file.name,
        content_type: 'text/csv', byte_size: file.size, sha256,
      })
      setPhase('uploading')
      const response = await fetch(ticket.upload_url, { method: ticket.method, headers: ticket.required_headers, body: file })
      if (!response.ok) return fail('upload')
      setPhase('completing')
      const completion = await completeDocumentUpload(csrfToken, ticket.upload_id, { byte_size: file.size, sha256 })
      if (!completion.accepted) return fail('upload')
      onUploaded(ticket.quarantine_object_id)
      setPhase('complete')
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) onSessionExpired()
      else fail('upload')
    }
  }

  function fail(nextError: NonNullable<typeof error>) { setPhase('ready'); setError(nextError); window.requestAnimationFrame(() => errorRef.current?.focus()) }

  return <section className="organization-section" aria-labelledby="import-upload-heading"><h2 id="import-upload-heading">{text.uploadTitle}</h2><p>{text.uploadHelp}</p><form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p id="import-upload-error" className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{errorMessage}</p>}<div className="field"><label htmlFor="import-upload-file">{text.file}<span aria-hidden="true"> *</span></label><input id="import-upload-file" type="file" accept=".csv,text/csv" required aria-required="true" aria-invalid={Boolean(error)} aria-describedby={error ? 'import-upload-error' : undefined} disabled={busy} onChange={(event) => { setFile(event.target.files?.[0] ?? null); setError(null); setPhase('ready') }} /></div><p className="status-message" role="status" aria-live="polite" aria-atomic="true">{status}</p><button type="submit" className="primary-button" disabled={busy}>{busy ? status : text.upload}</button></form></section>
}

async function hashFile(file: File) {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function SubmitForm({ locale, token, quarantineId, onQuarantineIdChange, onSubmitted, onSessionExpired }: { locale: Locale; token: string; quarantineId: string; onQuarantineIdChange: (quarantineId: string) => void; onSubmitted: (job: ImportJob) => void; onSessionExpired: () => void }) {
  const text = copy[locale]
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!UUID_V7.test(quarantineId)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(false)
    try { onSubmitted(await submitImportJob(token, { quarantine_object_id: quarantineId, template_code: 'people_assignments', import_type: 'csv' })) }
    catch (failure) { if (failure instanceof ApiError && failure.status === 401) onSessionExpired(); else { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()) } }
    finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}<Field id="quarantine-id" label={text.quarantineId} value={quarantineId} onChange={onQuarantineIdChange} required invalid={error} /><button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.submit}</button></form>
}

function OpenForm({ locale, initialValue, onOpen }: { locale: Locale; initialValue: string; onOpen: (jobId: string) => void }) {
  const text = copy[locale]
  const [value, setValue] = useState(initialValue)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!UUID_V7.test(value)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return } setError(false); onOpen(value) }
  return <form className="resource-form" onSubmit={submit} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}<Field id="import-job-id" label={text.jobId} value={value} onChange={setValue} required invalid={error} /><button type="submit" className="secondary-button">{text.open}</button></form>
}

function TransitionForm({ locale, token, job, onChanged, onSessionExpired }: { locale: Locale; token: string; job: ImportJob; onChanged: (job: ImportJob) => void; onSessionExpired: () => void }) {
  const text = copy[locale]
  const available = availableActions(job.status)
  const [action, setAction] = useState<ImportJobAction>(available[0] ?? 'validate')
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'stale' | 'transition' | null>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  if (available.length === 0) return null
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (['reject', 'cancel'].includes(action) && !reason.trim()) { setError('validation'); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(null)
    try { onChanged(await transitionImportJob(token, job.id, action, reason.trim() || undefined)) }
    catch (failure) { if (failure instanceof ApiError && failure.status === 401) onSessionExpired(); else { setError(failure instanceof ApiError && failure.status === 412 ? 'stale' : 'transition'); window.requestAnimationFrame(() => errorRef.current?.focus()) } }
    finally { setSubmitting(false) }
  }
  return <section className="organization-section" aria-labelledby="transition-heading"><h2 id="transition-heading">{text.action}</h2><form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : error === 'stale' ? text.stale : text.transitionError}</p>}<div className="field-row"><div className="field"><label htmlFor="import-action">{text.action}</label><select id="import-action" value={action} onChange={(event) => setAction(event.target.value as ImportJobAction)}>{available.map((item) => <option key={item} value={item}>{text[item]}</option>)}</select></div><Field id="import-reason" label={text.reason} value={reason} onChange={setReason} /></div><button type="submit" className="primary-button" disabled={submitting}>{submitting ? text.saving : text.execute}</button></form></section>
}

function availableActions(status: ImportJob['status']): ImportJobAction[] { if (status === 'received') return ['validate']; if (status === 'validated') return ['approve', 'reject']; if (status === 'approved') return ['apply', 'cancel']; return [] }
function Field({ id, label, value, onChange, required = false, invalid = false }: { id: string; label: string; value: string; onChange: (value: string) => void; required?: boolean; invalid?: boolean }) { return <div className="field"><label htmlFor={id}>{label}{required && <span aria-hidden="true"> *</span>}</label><input id={id} dir="ltr" value={value} required={required} aria-required={required || undefined} aria-invalid={invalid} onChange={(event) => onChange(event.target.value)} /></div> }
function number(value: number, locale: Locale) { return new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-GB').format(value) }
