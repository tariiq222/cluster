import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { useLocale, useSessionToken } from '../../app/session-context'
import { ApiError, requestInit, unwrap, unwrapWithEtag } from '../../api/http'
import { formatNumber, type Locale } from '../../i18n'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  PanelGrid,
  Select,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import * as generated from '../../api/generated/cluster'

type JobAction = 'validate' | 'approve' | 'reject' | 'apply' | 'cancel'
type ImportStatus =
  | 'received'
  | 'validated'
  | 'approved'
  | 'rejected'
  | 'applied'
  | 'cancelled'
  | 'failed'
type RowProposedAction = 'create' | 'skip'
type RowValidationError = { code: string; severity: string; field?: string }

const copy = {
  ar: {
    title: 'مراجعة استيراد البيانات',
    intro: 'ارفع ملف البيانات، ثم راجع النتائج قبل اعتمادها وتطبيقها.',
    loading: 'جارٍ تحميل الاستيراد…',
    retry: 'إعادة المحاولة',
    error: 'تعذر تحميل الاستيراد. أعد المحاولة.',
    uploadTitle: '١. رفع الملف',
    uploadHelp:
      'ارفع ملف CSV وفق القالب المحدد. يجب أن يكون الملف بترميز UTF-8.',
    file: 'ملف البيانات',
    template: 'نوع البيانات',
    upload: 'رفع الملف',
    uploading: 'جارٍ رفع الملف…',
    uploadReady: 'اختر ملفاً للبدء.',
    uploadComplete: 'اكتمل رفع الملف. يمكنك الآن بدء مراجعته.',
    uploadError: 'تعذر رفع الملف. حاول مرة أخرى.',
    fileRequired: 'اختر ملف CSV للمتابعة.',
    fileInvalid: 'اختر ملفاً بامتداد CSV.',
    fileTooLarge: 'حجم الملف غير مدعوم (10 ميغابايت كحد أقصى).',
    submitTitle: '٢. بدء مراجعة الملف',
    quarantineId: 'مرجع الملف',
    submit: 'بدء المراجعة',
    validation: 'أدخل مرجعاً صحيحاً.',
    submitError: 'تعذر بدء المراجعة. حاول مرة أخرى.',
    summary: 'ملخص المراجعة',
    rowsTitle: 'نتائج فحص البيانات',
    noRows: 'لم تظهر نتائج الفحص بعد. نفّذ خطوة التحقق أولاً.',
    status: 'الحالة',
    total: 'إجمالي السجلات',
    valid: 'جاهزة',
    errors: 'تحتاج تصحيحاً',
    row: 'رقم السجل',
    proposed: 'ما سيحدث',
    decision: 'القرار',
    validationErrors: 'الملاحظات',
    noErrors: 'جاهز',
    create: 'إنشاء',
    skip: 'تخطي',
    accepted: 'مقبول',
    rejectedDecision: 'مرفوض',
    actionsTitle: 'الخطوة التالية',
    reason: 'سبب الرفض أو الإلغاء',
    execute: 'متابعة',
    executing: 'جارٍ التنفيذ…',
    transitionError: 'تعذر إكمال الخطوة. راجع الحالة والصلاحية.',
    stale: 'بيانات قديمة، حدّث الصفحة ثم أعد المحاولة.',
    received: 'تم استلام الملف',
    validated: 'تم فحص البيانات',
    approved: 'تم الاعتماد',
    rejected: 'مرفوض',
    applied: 'تمت الإضافة',
    cancelled: 'ملغي',
    failed: 'تعذر التنفيذ',
    validate: 'تحقق',
    approve: 'اعتماد',
    reject: 'رفض',
    apply: 'تطبيق',
    cancel: 'إلغاء',
    rejectTitle: 'رفض الاستيراد',
    cancelTitle: 'إلغاء الاستيراد',
    reasonRequired: 'أدخل سبباً واضحاً للمتابعة.',
    noJob: 'لا توجد عملية استيراد قيد المراجعة.',
    facilities: 'المنشآت',
    organizationUnits: 'الوحدات التنظيمية',
    positions: 'المناصب',
    peopleAssignments: 'الموظفون والتكليفات',
    refresh: 'تحديث النتائج',
  },
  en: {
    title: 'Import review',
    intro:
      'Upload the data file, then review the results before approving and applying them.',
    loading: 'Loading import…',
    retry: 'Try again',
    error: 'The import could not be loaded.',
    uploadTitle: '1. Upload file',
    uploadHelp:
      'Upload a CSV file matching the selected template. The file must be UTF-8 encoded.',
    file: 'Data file',
    template: 'Data type',
    upload: 'Upload file',
    uploading: 'Uploading file…',
    uploadReady: 'Choose a file to start.',
    uploadComplete: 'File uploaded. You can now start its review.',
    uploadError: 'The file could not be uploaded. Try again.',
    fileRequired: 'Choose a CSV file to continue.',
    fileInvalid: 'Choose a file with a CSV extension.',
    fileTooLarge: 'The file size is not supported (10 MiB maximum).',
    submitTitle: '2. Start file review',
    quarantineId: 'File reference',
    submit: 'Start review',
    validation: 'Enter a valid reference.',
    submitError: 'The review could not be started. Try again.',
    summary: 'Review summary',
    rowsTitle: 'Data check results',
    noRows: 'No check results yet. Run the validation step first.',
    status: 'Status',
    total: 'Total records',
    valid: 'Ready',
    errors: 'Needs correction',
    row: 'Record',
    proposed: 'What will happen',
    decision: 'Decision',
    validationErrors: 'Notes',
    noErrors: 'Ready',
    create: 'Create',
    skip: 'Skip',
    accepted: 'Accepted',
    rejectedDecision: 'Rejected',
    actionsTitle: 'Next step',
    reason: 'Rejection or cancellation reason',
    execute: 'Continue',
    executing: 'Working…',
    transitionError:
      'The step could not be completed. Review status and permission.',
    stale: 'The data is outdated. Refresh the page and try again.',
    received: 'Received',
    validated: 'Validated',
    approved: 'Approved',
    rejected: 'Rejected',
    applied: 'Applied',
    cancelled: 'Cancelled',
    failed: 'Failed',
    validate: 'Validate',
    approve: 'Approve',
    reject: 'Reject',
    apply: 'Apply',
    cancel: 'Cancel',
    rejectTitle: 'Reject import',
    cancelTitle: 'Cancel import',
    reasonRequired: 'Enter a clear reason to continue.',
    noJob: 'No import job is under review.',
    facilities: 'Facilities',
    organizationUnits: 'Organization units',
    positions: 'Positions',
    peopleAssignments: 'People and assignments',
    refresh: 'Refresh results',
  },
} as const

const UUID_V7 =
  /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

const templateOptions: Array<
  [
    generated.ImportJobTemplateCode,
    'facilities' | 'organizationUnits' | 'positions' | 'peopleAssignments',
  ]
> = [
  ['facilities', 'facilities'],
  ['organization_units', 'organizationUnits'],
  ['positions', 'positions'],
  ['people_assignments', 'peopleAssignments'],
]

function statusLabel(locale: Locale, status: ImportStatus): string {
  const text = copy[locale]
  return text[status]
}

function templateLabel(locale: Locale, templateCode: string): string {
  const text = copy[locale]
  const match = templateOptions.find(([code]) => code === templateCode)
  return match ? text[match[1]] : templateCode
}

function availableActions(status: ImportStatus): JobAction[] {
  if (status === 'received') return ['validate']
  if (status === 'validated') return ['approve', 'reject']
  if (status === 'approved') return ['apply', 'cancel']
  return []
}

export function ImportReviewScreen({ jobId }: { jobId?: string }) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const [activeJobId, setActiveJobId] = useState<string | undefined>(jobId)
  const [job, setJob] = useState<generated.ImportJob | null>(null)
  const [rows, setRows] = useState<generated.ImportJobRow[]>([])
  const [loading, setLoading] = useState(Boolean(jobId))
  const [state, setState] = useState<
    'ready' | 'forbidden' | 'not-found' | 'error'
  >('ready')
  const [staleMessage, setStaleMessage] = useState<string | null>(null)
  const [quarantineId, setQuarantineId] = useState('')
  const activeRef = useRef(true)
  const loadRequestRef = useRef(0)
  useEffect(
    () => () => {
      activeRef.current = false
      loadRequestRef.current += 1
    },
    [],
  )

  const load = useCallback(
    async (jobIdToLoad: string | undefined = activeJobId) => {
      const epoch = ++loadRequestRef.current
      activeRef.current = true
      if (!jobIdToLoad) {
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        setJob(null)
        setRows([])
        setLoading(false)
        setState('ready')
        return
      }
      setLoading(true)
      setState('ready')
      try {
        const [jobValue, rowPage] = await Promise.all([
          unwrap<generated.ImportJob>(
            await generated.getOrganizationImport(
              jobIdToLoad,
              requestInit(token),
            ),
          ),
          unwrap<generated.ImportJobRowCollection>(
            await generated.listOrganizationImportRows(
              jobIdToLoad,
              { limit: 100 },
              requestInit(token),
            ),
          ),
        ])
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        setJob(jobValue)
        setRows(rowPage.items)
        setStaleMessage(null)
      } catch (caught) {
        if (!activeRef.current || epoch !== loadRequestRef.current) return
        setJob(null)
        setRows([])
        if (
          caught instanceof ApiError &&
          (caught.status === 403 || caught.status === 404)
        ) {
          setState(caught.status === 403 ? 'forbidden' : 'not-found')
        } else {
          setState('error')
        }
      } finally {
        if (activeRef.current && epoch === loadRequestRef.current)
          setLoading(false)
      }
    },
    [activeJobId, token],
  )

  useEffect(() => {
    void load(jobId)
  }, [jobId, load])

  async function handleTransition(action: JobAction, reason?: string) {
    if (!job) return
    try {
      const { etag } = unwrapWithEtag<generated.ImportJob>(
        await generated.getOrganizationImport(job.id, requestInit(token)),
      )
      if (!etag)
        throw new ApiError(502, {
          type: 'about:blank',
          title: 'Missing import version',
          status: 502,
        })
      const changed = unwrap<generated.ImportJob>(
        await generated.transitionOrganizationImport(
          job.id,
          action,
          reason ? { reason } : {},
          requestInit(token, {
            command: true,
            idempotency: `import-${action}`,
            lockVersion: etag,
          }),
        ),
      )
      setJob(changed)
      await load(job.id)
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 412) {
        setStaleMessage(text.stale)
        await load(job.id)
      } else {
        setStaleMessage(text.transitionError)
      }
    }
  }

  return (
    <Page>
      <PageHeader
        id="import-review-heading"
        title={text.title}
        description={text.intro}
      />
      <div className="screen-list">
        <ImportUploadPanel onUploaded={setQuarantineId} />
        <SubmitPanel
          quarantineId={quarantineId}
          onQuarantineIdChange={setQuarantineId}
          onSubmitted={(created) => {
            setActiveJobId(created.id)
            void load(created.id)
          }}
        />
      </div>
      {staleMessage ? (
        <p role="status" className="status-message status-message--error">
          {staleMessage}
        </p>
      ) : null}
      <div aria-live="polite" aria-atomic="true">
        {loading ? <SkeletonList rows={2} /> : null}
        {!loading && (state === 'forbidden' || state === 'not-found') ? (
          <div className="state-panel" role="status">
            <p>{state === 'forbidden' ? text.error : text.error}</p>
          </div>
        ) : null}
        {!loading && state === 'error' ? (
          <InlineError
            message={text.error}
            retryLabel={text.retry}
            onRetry={() => void load()}
          />
        ) : null}
        {!loading && state === 'ready' && job ? (
          <PanelGrid>
            <JobSummary job={job} />
            <Panel
              id="import-rows-heading"
              title={text.rowsTitle}
              actions={
                <Button variant="secondary" onClick={() => void load()}>
                  {text.refresh}
                </Button>
              }
            >
              {rows.length === 0 ? (
                <EmptyState title={text.noRows} />
              ) : (
                <RowsList rows={rows} />
              )}
            </Panel>
            <TransitionActions
              job={job}
              onTransition={(action, reason) =>
                void handleTransition(action, reason)
              }
            />
          </PanelGrid>
        ) : null}
      </div>
    </Page>
  )
}

function ImportUploadPanel({
  onUploaded,
}: {
  onUploaded: (quarantineId: string) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const [file, setFile] = useState<File | null>(null)
  const [templateCode, setTemplateCode] = useState<string>('people_assignments')
  const [phase, setPhase] = useState<'ready' | 'uploading' | 'complete'>(
    'ready',
  )
  const [error, setError] = useState<
    'file' | 'invalid' | 'size' | 'upload' | null
  >(null)

  const busy = phase === 'uploading'
  const status =
    phase === 'uploading'
      ? text.uploading
      : phase === 'complete'
        ? text.uploadComplete
        : text.uploadReady
  const errorMessage =
    error === 'file'
      ? text.fileRequired
      : error === 'invalid'
        ? text.fileInvalid
        : error === 'size'
          ? text.fileTooLarge
          : text.uploadError

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!file) {
      setError('file')
      return
    }
    if (!file.name.toLowerCase().endsWith('.csv')) {
      setError('invalid')
      return
    }
    if (file.size < 1 || file.size > 10 * 1024 * 1024) {
      setError('size')
      return
    }
    setError(null)
    try {
      setPhase('uploading')
      const reference = unwrap<generated.ImportFileReference>(
        await generated.uploadOrganizationImportFile(
          {
            file,
            template_code:
              templateCode as generated.ImportFileUploadTemplateCode,
            import_type: 'csv',
          },
          requestInit(token, { command: true, idempotency: 'import-file' }),
        ),
      )
      onUploaded(reference.quarantine_object_id)
      setPhase('complete')
    } catch {
      setPhase('ready')
      setError('upload')
    }
  }

  return (
    <Panel id="import-upload-heading" title={text.uploadTitle}>
      <p>{text.uploadHelp}</p>
      <form
        className="screen-list"
        onSubmit={(event) => void submit(event)}
        noValidate
        style={{ gap: 'var(--space-3)' }}
      >
        {error ? (
          <p className="error-summary" role="alert">
            {errorMessage}
          </p>
        ) : null}
        <Field id="import-upload-file" label={text.file} required>
          <input
            id="import-upload-file"
            type="file"
            accept=".csv,text/csv"
            required
            aria-required="true"
            aria-invalid={Boolean(error)}
            disabled={busy}
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null)
              setError(null)
              setPhase('ready')
            }}
          />
        </Field>
        <Field id="import-upload-template" label={text.template} required>
          <Select
            id="import-upload-template"
            value={templateCode}
            onChange={setTemplateCode}
            options={templateOptions.map(([value, key]) => ({
              value,
              label: text[key],
            }))}
          />
        </Field>
        <p
          className="status-message"
          role="status"
          aria-live="polite"
          aria-atomic="true"
        >
          {status}
        </p>
        <div
          className="form-actions"
          style={{ justifyContent: 'flex-start', paddingBlockStart: 0 }}
        >
          <Button type="submit" disabled={busy}>
            {busy ? status : text.upload}
          </Button>
        </div>
      </form>
    </Panel>
  )
}

function SubmitPanel({
  quarantineId,
  onQuarantineIdChange,
  onSubmitted,
}: {
  quarantineId: string
  onQuarantineIdChange: (quarantineId: string) => void
  onSubmitted: (job: generated.ImportJob) => void
}) {
  const locale = useLocale()
  const token = useSessionToken()
  const text = copy[locale]
  const [templateCode, setTemplateCode] = useState<string>('people_assignments')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<'validation' | 'save' | null>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!UUID_V7.test(quarantineId)) {
      setError('validation')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const created = unwrap<generated.ImportJob>(
        await generated.submitOrganizationImport(
          {
            quarantine_object_id: quarantineId,
            template_code:
              templateCode as generated.ImportJobCreateTemplateCode,
            import_type: 'csv',
          },
          requestInit(token, { command: true, idempotency: 'import-submit' }),
        ),
      )
      onSubmitted(created)
    } catch {
      setError('save')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Panel id="import-submit-heading" title={text.submitTitle}>
      <form
        className="screen-list"
        onSubmit={(event) => void submit(event)}
        noValidate
        style={{ gap: 'var(--space-3)' }}
      >
        {error ? (
          <p className="error-summary" role="alert">
            {error === 'validation' ? text.validation : text.submitError}
          </p>
        ) : null}
        <Field id="import-quarantine-id" label={text.quarantineId} required>
          <input
            id="import-quarantine-id"
            dir="ltr"
            value={quarantineId}
            required
            aria-required="true"
            aria-invalid={error === 'validation' || undefined}
            onChange={(event) => onQuarantineIdChange(event.target.value)}
          />
        </Field>
        <Field id="import-submit-template" label={text.template} required>
          <Select
            id="import-submit-template"
            value={templateCode}
            onChange={setTemplateCode}
            options={templateOptions.map(([value, key]) => ({
              value,
              label: text[key],
            }))}
          />
        </Field>
        <div
          className="form-actions"
          style={{ justifyContent: 'flex-start', paddingBlockStart: 0 }}
        >
          <Button type="submit" disabled={submitting}>
            {submitting ? text.executing : text.submit}
          </Button>
        </div>
      </form>
    </Panel>
  )
}

function JobSummary({ job }: { job: generated.ImportJob }) {
  const locale = useLocale()
  const text = copy[locale]
  return (
    <Panel id="import-summary-heading" title={text.summary}>
      <div className="detail-list">
        <div className="detail-list__row">
          <div className="detail-list__key">{text.status}</div>
          <div className="detail-list__value">
            <StatusBadge
              variant={
                job.status === 'applied'
                  ? 'success'
                  : job.status === 'failed' || job.status === 'rejected'
                    ? 'danger'
                    : 'info'
              }
            >
              {statusLabel(locale, job.status)}
            </StatusBadge>
          </div>
        </div>
        <div className="detail-list__row">
          <div className="detail-list__key">{text.template}</div>
          <div className="detail-list__value" dir="ltr">
            {templateLabel(locale, job.template_code)}
          </div>
        </div>
        <div className="detail-list__row">
          <div className="detail-list__key">{text.total}</div>
          <div className="detail-list__value">
            {formatNumber(job.total_rows, locale)}
          </div>
        </div>
        <div className="detail-list__row">
          <div className="detail-list__key">{text.valid}</div>
          <div className="detail-list__value">
            {formatNumber(job.valid_rows, locale)}
          </div>
        </div>
        <div className="detail-list__row">
          <div className="detail-list__key">{text.errors}</div>
          <div className="detail-list__value">
            {formatNumber(job.error_rows, locale)}
          </div>
        </div>
      </div>
    </Panel>
  )
}

function RowsList({ rows }: { rows: generated.ImportJobRow[] }) {
  const locale = useLocale()
  const text = copy[locale]
  return (
    <div className="screen-list">
      {rows.map((row) => (
        <div className="screen-list__row" key={row.id}>
          <div>
            <div className="screen-list__row-title">
              {text.row} {formatNumber(row.row_number, locale)}
            </div>
            <div className="screen-list__row-meta">
              {text.proposed}:{' '}
              {row.proposed_action
                ? text[row.proposed_action as RowProposedAction]
                : '—'}
            </div>
            <div className="screen-list__row-meta">
              {text.decision}:{' '}
              {row.decision === 'accepted'
                ? text.accepted
                : row.decision === 'rejected'
                  ? text.rejectedDecision
                  : '—'}
            </div>
          </div>
          <div>
            {row.validation_errors.length === 0 ? (
              <StatusBadge variant="success">{text.noErrors}</StatusBadge>
            ) : (
              <ul className="screen-list" role="list">
                {row.validation_errors.map(
                  (validationError: RowValidationError) => (
                    <li
                      key={`${validationError.code}-${validationError.field ?? ''}`}
                      className="screen-list__row-meta"
                      role="listitem"
                    >
                      {validationError.code}
                      {validationError.field
                        ? ` · ${validationError.field}`
                        : ''}
                    </li>
                  ),
                )}
              </ul>
            )}
          </div>
        </div>
      ))}
    </div>
  )
}

function TransitionActions({
  job,
  onTransition,
}: {
  job: generated.ImportJob
  onTransition: (action: JobAction, reason?: string) => void
}) {
  const locale = useLocale()
  const text = copy[locale]
  const actions = availableActions(job.status)
  const [submittingAction, setSubmittingAction] = useState<JobAction | null>(
    null,
  )
  const [reasonAction, setReasonAction] = useState<JobAction | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<'reason' | 'transition' | null>(null)

  if (actions.length === 0) return null

  async function run(action: JobAction) {
    if (action === 'reject' || action === 'cancel') {
      setReasonAction(action)
      setReason('')
      setError(null)
      return
    }
    setSubmittingAction(action)
    setError(null)
    try {
      onTransition(action)
    } catch {
      setError('transition')
    } finally {
      setSubmittingAction(null)
    }
  }

  async function submitReason(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!reasonAction || !reason.trim()) {
      setError('reason')
      return
    }
    setSubmittingAction(reasonAction)
    setError(null)
    try {
      onTransition(reasonAction, reason.trim())
    } catch {
      setError('transition')
    } finally {
      setSubmittingAction(null)
      setReasonAction(null)
      setReason('')
    }
  }

  const reasonTitle =
    reasonAction === 'reject' ? text.rejectTitle : text.cancelTitle

  return (
    <Panel id="import-transition-heading" title={text.actionsTitle}>
      <div className="screen-list__row-actions" style={{ flexWrap: 'wrap' }}>
        {actions.map((action) => (
          <Button
            key={action}
            variant={
              action === 'approve' || action === 'apply'
                ? 'primary'
                : 'secondary'
            }
            disabled={submittingAction !== null}
            onClick={() => void run(action)}
          >
            {submittingAction === action ? text.executing : text[action]}
          </Button>
        ))}
      </div>
      <Drawer
        open={reasonAction !== null}
        onClose={() => {
          if (submittingAction === null) {
            setReasonAction(null)
            setReason('')
            setError(null)
          }
        }}
        title={reasonTitle}
      >
        <form onSubmit={(event) => void submitReason(event)} noValidate>
          {error ? (
            <p className="error-summary" role="alert">
              {error === 'reason' ? text.reasonRequired : text.transitionError}
            </p>
          ) : null}
          <Field id="import-transition-reason" label={text.reason} required>
            <input
              id="import-transition-reason"
              value={reason}
              required
              aria-required="true"
              aria-invalid={error === 'reason' || undefined}
              onChange={(event) => setReason(event.target.value)}
            />
          </Field>
          <div className="form-actions">
            <Button
              type="button"
              variant="quiet"
              onClick={() => {
                setReasonAction(null)
                setReason('')
                setError(null)
              }}
              disabled={submittingAction !== null}
            >
              {text.cancel}
            </Button>
            <Button type="submit" disabled={submittingAction !== null}>
              {submittingAction !== null ? text.executing : text.execute}
            </Button>
          </div>
        </form>
      </Drawer>
    </Panel>
  )
}
