import { type FormEvent, useEffect, useRef, useState } from 'react'
import { formattingLocale } from '../../app/copy'
import { useLocale, useToken } from '../../app/session-context'

import { FileSpreadsheet } from 'lucide-react'

import {
  ApiError,
  completeDocumentUpload,
  getImportJob,
  initiateDocumentUpload,
  listImportJobRows,
  putUploadTicket,
  submitImportJob,
  transitionImportJob,
  type ImportJob,
  type ImportJobAction,
  type ImportJobRow,
  stateFromError,
} from '../../api'
import {
  Button,
  EmptyState,
  Field as UiField,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select as UiSelect,
  SkeletonList,
} from '../../ui'

type Locale = 'ar' | 'en'

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const copy = {
  ar: {
    title: 'إضافة بيانات من ملف', intro: 'ارفع ملف الموظفين والتكليفات، ثم راجع الأخطاء قبل اعتماد البيانات.',
    uploadTitle: '١. اختيار الملف', uploadHelp: 'اختر ملف CSV يحتوي على بيانات الموظفين والتكليفات.', file: 'ملف البيانات', upload: 'رفع الملف', uploadReady: 'الملف جاهز للرفع.', hashing: 'جارٍ تجهيز الملف…', requestingUpload: 'جارٍ تجهيز عملية الرفع…', uploading: 'جارٍ رفع الملف…', completingUpload: 'جارٍ تأكيد الرفع…', uploadComplete: 'اكتمل رفع الملف. يمكنك الآن بدء مراجعته.', fileRequired: 'اختر ملف CSV للمتابعة.', fileInvalid: 'اختر ملفاً بامتداد CSV.', fileTooLarge: 'حجم الملف غير مدعوم.', uploadError: 'تعذر رفع الملف. حاول مرة أخرى.', uploadHashError: 'تعذر تجهيز الملف للرفع. حاول مرة أخرى.', uploadName: 'ملف بيانات الموظفين والتكليفات',
    quarantineId: 'مرجع الملف', submit: 'بدء مراجعة الملف', jobId: 'مرجع عملية الاستيراد', open: 'فتح عملية سابقة', saving: 'جارٍ التنفيذ…',
    loading: 'جارٍ تحميل الاستيراد…', forbidden: 'لا تملك صلاحية قراءة هذا الاستيراد.', notFound: 'الاستيراد غير موجود أو غير متاح.', error: 'تعذر تحميل الاستيراد.', retry: 'إعادة المحاولة',
    summary: '٢. ملخص المراجعة', status: 'الحالة', template: 'نوع البيانات', total: 'إجمالي السجلات', valid: 'جاهزة', errors: 'تحتاج تصحيحاً', approver: 'الاعتماد', notApproved: 'لم يعتمد بعد',
    rows: '٣. نتائج فحص البيانات', noRows: 'لم تظهر نتائج الفحص بعد.', row: 'رقم السجل', proposed: 'ما سيحدث', decision: 'القرار', validationErrors: 'الملاحظات', noErrors: 'جاهز',
    action: 'الخطوة التالية', reason: 'سبب الرفض أو الإلغاء', execute: 'متابعة', refresh: 'تحديث النتائج', validation: 'أدخل مرجعاً صحيحاً.', transitionError: 'تعذر إكمال الخطوة. راجع الحالة والصلاحية.', stale: 'تغيرت بيانات العملية. حدّث النتائج ثم أعد المحاولة.',
    received: 'تم استلام الملف', validated: 'تم فحص البيانات', approved: 'تم الاعتماد', rejected: 'مرفوض', applied: 'تمت الإضافة', cancelled: 'ملغي', failed: 'تعذر التنفيذ',
    validate: 'تحقق', approve: 'اعتماد', reject: 'رفض', apply: 'تطبيق', cancel: 'إلغاء', accepted: 'مقبول', rejectedDecision: 'مرفوض', create: 'إنشاء', skip: 'تخطي',
  },
  en: {
    title: 'Add data from file', intro: 'Upload employee and assignment data, then review issues before applying it.',
    uploadTitle: '1. Choose file', uploadHelp: 'Choose a CSV file containing employee and assignment data.', file: 'Data file', upload: 'Upload file', uploadReady: 'The file is ready to upload.', hashing: 'Preparing file…', requestingUpload: 'Preparing upload…', uploading: 'Uploading file…', completingUpload: 'Confirming upload…', uploadComplete: 'File uploaded. You can now start its review.', fileRequired: 'Choose a CSV file to continue.', fileInvalid: 'Choose a file with a CSV extension.', fileTooLarge: 'The file size is not supported.', uploadError: 'The file could not be uploaded. Try again.', uploadHashError: 'The file could not be prepared for upload. Try again.', uploadName: 'Employee and assignment data file',
    quarantineId: 'File reference', submit: 'Start file review', jobId: 'Import reference', open: 'Open previous import', saving: 'Working…',
    loading: 'Loading import…', forbidden: 'You do not have permission to read this import.', notFound: 'The import does not exist or is unavailable.', error: 'The import could not be loaded.', retry: 'Try again',
    summary: '2. Review summary', status: 'Status', template: 'Data type', total: 'Total records', valid: 'Ready', errors: 'Needs correction', approver: 'Approval', notApproved: 'Not approved yet',
    rows: '3. Data check results', noRows: 'No check results yet.', row: 'Record', proposed: 'What will happen', decision: 'Decision', validationErrors: 'Notes', noErrors: 'Ready',
    action: 'Next step', reason: 'Rejection or cancellation reason', execute: 'Continue', refresh: 'Refresh results', validation: 'Enter a valid reference.', transitionError: 'The step could not be completed. Review status and permission.', stale: 'The import changed. Refresh the results and try again.',
    received: 'Received', validated: 'Validated', approved: 'Approved', rejected: 'Rejected', applied: 'Applied', cancelled: 'Cancelled', failed: 'Failed',
    validate: 'Validate', approve: 'Approve', reject: 'Reject', apply: 'Apply', cancel: 'Cancel', accepted: 'Accepted', rejectedDecision: 'Rejected', create: 'Create', skip: 'Skip',
  },
} as const

export function ImportReview({ jobId, onJobOpen }: {
  jobId?: string; onJobOpen: (jobId: string) => void;}) {
  const locale = useLocale()
  const token = useToken()
  const text = copy[locale]
  const [job, setJob] = useState<ImportJob | null>(null)
  const [rows, setRows] = useState<ImportJobRow[]>([])
  const [loading, setLoading] = useState(Boolean(jobId))
  const [state, setState] = useState<'ready' | 'forbidden' | 'not-found' | 'error'>('ready')
  const [quarantineId, setQuarantineId] = useState('')
  /**
   * Track in-flight load requests so a superseded token/jobId change cannot
   * overwrite the freshest snapshot. The unmount cleanup bumps the ref so no
   * async callback writes after the import route is torn down.
   */
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)
  useEffect(() => () => { activeRef.current = false; loadRequestRef.current += 1 }, [])

  async function load() {
    const epoch = ++loadRequestRef.current
    activeRef.current = true
    if (!jobId) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setJob(null); setRows([]); setLoading(false)
      return
    }
    setLoading(true); setState('ready')
    try {
      const [jobValue, rowPage] = await Promise.all([getImportJob(token, jobId), listImportJobRows(token, jobId)])
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setJob(jobValue); setRows(rowPage.items)
    } catch (error) {
      if (!activeRef.current || epoch !== loadRequestRef.current) return
      setJob(null); setRows([])
      const resolution = stateFromError(error)
      setState(resolution === 'forbidden' || resolution === 'not-found' ? resolution : 'error')
    } finally {
      if (activeRef.current && epoch === loadRequestRef.current) setLoading(false)
    }
  }
  useEffect(() => {
    void load()
    // Import data is reloaded when the route job or session changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [jobId, token])

  return <Page>
    <PageHeader id="import-heading" title={text.title} description={text.intro} />
    <ImportUpload locale={locale} csrfToken={token} onUploaded={setQuarantineId} />
    <div className="import-entry-grid"><SubmitForm locale={locale} token={token} quarantineId={quarantineId} onQuarantineIdChange={setQuarantineId} onSubmitted={(created) => onJobOpen(created.id)} /><OpenForm key={jobId ?? 'new'} locale={locale} initialValue={jobId ?? ''} onOpen={onJobOpen} /></div>
    <div aria-live="polite" aria-atomic="true">
      {loading && <SkeletonList label={text.loading} />}
      {!loading && (state === 'forbidden' || state === 'not-found') && <div className="state-panel" role="status"><p>{state === 'forbidden' ? text.forbidden : text.notFound}</p></div>}
      {!loading && state === 'error' && <InlineError message={text.error} retryLabel={text.retry} onRetry={() => void load()} />}
      {!loading && state === 'ready' && job && <PanelGrid><JobSummary job={job} locale={locale} /><Panel id="import-rows-heading" title={text.rows} level={2} actions={<Button variant="secondary" onClick={() => void load()}>{text.refresh}</Button>}>{rows.length === 0 ? <EmptyState icon={<FileSpreadsheet />} title={text.noRows} /> : <RowsTable rows={rows} locale={locale} />}</Panel><TransitionForm key={job.status} locale={locale} token={token} job={job} onChanged={(changed) => { setJob(changed); void load() }} /></PanelGrid>}
    </div>
  </Page>
}

function JobSummary({ job, locale }: { job: ImportJob; locale: Locale }) {
  const text = copy[locale]
  return <Panel id="import-summary-heading" title={text.summary} level={2}><dl className="definition-grid"><div><dt>{text.status}</dt><dd><span className="status-badge">{text[job.status]}</span></dd></div><div><dt>{text.template}</dt><dd dir="ltr">{job.template_code}</dd></div><div><dt>{text.total}</dt><dd>{number(job.total_rows, locale)}</dd></div><div><dt>{text.valid}</dt><dd>{number(job.valid_rows, locale)}</dd></div><div><dt>{text.errors}</dt><dd>{number(job.error_rows, locale)}</dd></div><div><dt>{text.approver}</dt><dd>{job.approved_by_user_id ? text.approved : text.notApproved}</dd></div></dl></Panel>
}

function RowsTable({ rows, locale }: { rows: ImportJobRow[]; locale: Locale }) {
  const text = copy[locale]
  return <div className="table-scroll" tabIndex={0} role="region" aria-label={text.rows}><table><thead><tr><th scope="col">{text.row}</th><th scope="col">{text.proposed}</th><th scope="col">{text.decision}</th><th scope="col">{text.validationErrors}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{number(row.row_number, locale)}</td><td>{row.proposed_action ? text[row.proposed_action] : '—'}</td><td>{row.decision === 'accepted' ? text.accepted : row.decision === 'rejected' ? text.rejectedDecision : '—'}</td><td>{row.validation_errors.length === 0 ? text.noErrors : <ul className="inline-errors">{row.validation_errors.map((error) => <li key={`${error.code}-${error.field ?? ''}`}>{error.code}{error.field ? ` · ${error.field}` : ''}</li>)}</ul>}</td></tr>)}</tbody></table></div>
}

function ImportUpload({ locale, csrfToken, onUploaded }: { locale: Locale; csrfToken: string; onUploaded: (quarantineId: string) => void; }) {
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
      await putUploadTicket(ticket.upload_url, file, ticket.required_headers, ticket.method)
      setPhase('completing')
      const completion = await completeDocumentUpload(csrfToken, ticket.upload_id, { byte_size: file.size, sha256 })
      if (!completion.accepted) return fail('upload')
      onUploaded(ticket.quarantine_object_id)
      setPhase('complete')
    } catch {
      fail('upload')
    }
  }

  function fail(nextError: NonNullable<typeof error>) { setPhase('ready'); setError(nextError); window.requestAnimationFrame(() => errorRef.current?.focus()) }

  return <Panel id="import-upload-heading" title={text.uploadTitle} level={2}><p>{text.uploadHelp}</p><form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p id="import-upload-error" className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{errorMessage}</p>}<UiField id="import-upload-file" label={text.file} required><input id="import-upload-file" type="file" accept=".csv,text/csv" required aria-required="true" aria-invalid={Boolean(error)} aria-describedby={error ? 'import-upload-error' : undefined} disabled={busy} onChange={(event) => { setFile(event.target.files?.[0] ?? null); setError(null); setPhase('ready') }} /></UiField><p className="status-message" role="status" aria-live="polite" aria-atomic="true">{status}</p><Button type="submit" disabled={busy}>{busy ? status : text.upload}</Button></form></Panel>
}

async function hashFile(file: File) {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function SubmitForm({ locale, token, quarantineId, onQuarantineIdChange, onSubmitted }: { locale: Locale; token: string; quarantineId: string; onQuarantineIdChange: (quarantineId: string) => void; onSubmitted: (job: ImportJob) => void; }) {
  const text = copy[locale]
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!UUID_V7.test(quarantineId)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return }
    setSubmitting(true); setError(false)
    try { onSubmitted(await submitImportJob(token, { quarantine_object_id: quarantineId, template_code: 'people_assignments', import_type: 'csv' })) }
    catch { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    finally { setSubmitting(false) }
  }
  return <form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}<UiField id="quarantine-id" label={text.quarantineId} required><input id="quarantine-id" dir="ltr" value={quarantineId} required aria-required="true" aria-invalid={error} onChange={(event) => onQuarantineIdChange(event.target.value)} /></UiField><Button type="submit" disabled={submitting}>{submitting ? text.saving : text.submit}</Button></form>
}

function OpenForm({ locale, initialValue, onOpen }: { locale: Locale; initialValue: string; onOpen: (jobId: string) => void }) {
  const text = copy[locale]
  const [value, setValue] = useState(initialValue)
  const [error, setError] = useState(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!UUID_V7.test(value)) { setError(true); window.requestAnimationFrame(() => errorRef.current?.focus()); return } setError(false); onOpen(value) }
  return <form className="resource-form" onSubmit={submit} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{text.validation}</p>}<UiField id="import-job-id" label={text.jobId} required><input id="import-job-id" dir="ltr" value={value} required aria-required="true" aria-invalid={error} onChange={(event) => setValue(event.target.value)} /></UiField><Button type="submit" variant="secondary">{text.open}</Button></form>
}

function TransitionForm({ locale, token, job, onChanged }: { locale: Locale; token: string; job: ImportJob; onChanged: (job: ImportJob) => void; }) {
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
    catch (failure) { setError(failure instanceof ApiError && failure.status === 412 ? 'stale' : 'transition'); window.requestAnimationFrame(() => errorRef.current?.focus()) }
    finally { setSubmitting(false) }
  }
  return <Panel id="transition-heading" title={text.action} level={2}><form className="resource-form" onSubmit={(event) => void submit(event)} noValidate>{error && <p className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>{error === 'validation' ? text.validation : error === 'stale' ? text.stale : text.transitionError}</p>}<div className="field-row"><UiField id="import-action" label={text.action}><UiSelect id="import-action" value={action} onChange={(next) => setAction(next as ImportJobAction)} options={available.map((item) => ({ value: item, label: text[item] }))} /></UiField><UiField id="import-reason" label={text.reason}><input id="import-reason" dir="ltr" value={reason} onChange={(event) => setReason(event.target.value)} /></UiField></div><Button type="submit" disabled={submitting}>{submitting ? text.saving : text.execute}</Button></form></Panel>
}

function availableActions(status: ImportJob['status']): ImportJobAction[] { if (status === 'received') return ['validate']; if (status === 'validated') return ['approve', 'reject']; if (status === 'approved') return ['apply', 'cancel']; return [] }
function number(value: number, locale: Locale) { return new Intl.NumberFormat(formattingLocale(locale)).format(value) }
