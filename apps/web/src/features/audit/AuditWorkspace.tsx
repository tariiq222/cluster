import {
  useCallback,
  useEffect,
  useState,
  type FormEvent,
  type ReactNode,
} from 'react'
import {
  CheckCircle2,
  Download,
  FileSearch,
  Fingerprint,
  Search,
  ShieldAlert,
} from 'lucide-react'

import type { Locale } from '../../app/copy'
import {
  createAuditExport,
  downloadAuditExport,
  getAuditEvent,
  listAuditEvents,
  verifyAuditIntegrity,
  type AuditEvent,
  type AuditExportDescriptor,
  type AuditIntegrityResult,
  type ListAuditEventsParams,
} from '../../api/audit'
import { ApiError } from '../../api/http'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  InlineError,
  Page,
  PageHeader,
  Panel,
  SkeletonList,
  StatusBadge,
} from '../../ui'
import './audit-workspace.css'

const copy = {
  ar: {
    eyebrow: 'الضمان والامتثال',
    title: 'سجل التدقيق',
    description:
      'استعرض سجلًا غير قابل للتغيير، نزّل لقطات مبررة، وتحقق من سلامة السلاسل دون كشف مواد التجزئة.',
    loaded: 'الأحداث المحمّلة',
    violations: 'مخالفات السلامة',
    denied: 'قرارات مرفوضة',
    modules: 'الوحدات الظاهرة',
    filters: 'تصفية السجل',
    sourceModule: 'الوحدة المصدر',
    action: 'الإجراء',
    classification: 'التصنيف',
    allClassifications: 'كل التصنيفات',
    from: 'وقع من',
    to: 'وقع إلى',
    apply: 'تطبيق المرشحات',
    clear: 'مسح',
    ledger: 'الأحداث',
    loading: 'جارٍ تحميل سجل التدقيق',
    loadFailed: 'تعذر تحميل أحداث التدقيق.',
    retry: 'إعادة المحاولة',
    empty: 'لا توجد أحداث مطابقة',
    emptyBody: 'غيّر المرشحات أو وسّع النطاق الزمني.',
    occurred: 'وقت الحدث',
    actor: 'المنفذ',
    subject: 'الموضوع',
    outcome: 'النتيجة',
    integrity: 'السلامة',
    inspect: 'فحص الحدث',
    loadMore: 'تحميل المزيد',
    eventDetail: 'تفاصيل حدث التدقيق',
    close: 'إغلاق',
    eventId: 'معرّف الحدث',
    correlationId: 'معرّف الارتباط',
    eventType: 'نوع الحدث',
    recorded: 'وقت التسجيل',
    retention: 'الاحتفاظ حتى',
    accessDecision: 'قرار الوصول',
    context: 'السياق المنقح',
    redacted:
      'يعرض الخادم سياقًا منقحًا فقط. لا تتضمن هذه الواجهة التجزئات أو المفاتيح أو بصمة الطلب.',
    exportTitle: 'تصدير لقطة',
    exportReason: 'سبب التصدير',
    exportReasonHelp: 'سبب مهني محدد؛ يُسجل مع طلب التصدير.',
    format: 'التنسيق',
    createExport: 'إنشاء التصدير',
    exportReady: 'التصدير جاهز',
    expires: 'ينتهي',
    events: 'حدث',
    download: 'تنزيل الملف',
    verifyTitle: 'التحقق من سلامة سلسلة',
    streamKey: 'مفتاح السلسلة',
    firstSequence: 'أول تسلسل (اختياري)',
    lastSequence: 'آخر تسلسل (اختياري)',
    rangeHelp: 'أدخل الحدين معًا، أو اتركهما فارغين للتحقق من السلسلة المتاحة.',
    verify: 'تحقق الآن',
    verified: 'تم التحقق',
    pairRequired: 'يجب إدخال أول وآخر تسلسل معًا.',
    operationFailed: 'تعذر إكمال العملية.',
    notAvailable: 'غير متاح',
    system: 'النظام',
  },
  en: {
    eyebrow: 'Assurance & compliance',
    title: 'Audit ledger',
    description:
      'Inspect the immutable ledger, download reason-bound snapshots, and verify stream integrity without exposing hash material.',
    loaded: 'Events loaded',
    violations: 'Integrity violations',
    denied: 'Denied decisions',
    modules: 'Visible modules',
    filters: 'Filter ledger',
    sourceModule: 'Source module',
    action: 'Action',
    classification: 'Classification',
    allClassifications: 'All classifications',
    from: 'Occurred from',
    to: 'Occurred to',
    apply: 'Apply filters',
    clear: 'Clear',
    ledger: 'Events',
    loading: 'Loading audit ledger',
    loadFailed: 'Audit events could not be loaded.',
    retry: 'Retry',
    empty: 'No matching events',
    emptyBody: 'Change the filters or widen the time range.',
    occurred: 'Occurred',
    actor: 'Actor',
    subject: 'Subject',
    outcome: 'Outcome',
    integrity: 'Integrity',
    inspect: 'Inspect event',
    loadMore: 'Load more',
    eventDetail: 'Audit event detail',
    close: 'Close',
    eventId: 'Event ID',
    correlationId: 'Correlation ID',
    eventType: 'Event type',
    recorded: 'Recorded',
    retention: 'Retained until',
    accessDecision: 'Access decision',
    context: 'Redacted context',
    redacted:
      'Only server-redacted context is shown. Hashes, keys, and request fingerprints never enter this interface.',
    exportTitle: 'Export snapshot',
    exportReason: 'Export reason',
    exportReasonHelp:
      'State a specific business purpose; it is recorded with the export request.',
    format: 'Format',
    createExport: 'Create export',
    exportReady: 'Export ready',
    expires: 'Expires',
    events: 'events',
    download: 'Download file',
    verifyTitle: 'Verify stream integrity',
    streamKey: 'Stream key',
    firstSequence: 'First sequence (optional)',
    lastSequence: 'Last sequence (optional)',
    rangeHelp:
      'Enter both bounds, or leave both empty to verify the available stream.',
    verify: 'Verify now',
    verified: 'Verification complete',
    pairRequired: 'First and last sequence must be supplied together.',
    operationFailed: 'The operation could not be completed.',
    notAvailable: 'Not available',
    system: 'System',
  },
} as const

type FilterDraft = {
  sourceModule: string
  action: string
  classification: '' | 'public' | 'internal' | 'confidential' | 'top_secret'
  occurredFrom: string
  occurredTo: string
}

const EMPTY_FILTERS: FilterDraft = {
  sourceModule: '',
  action: '',
  classification: '',
  occurredFrom: '',
  occurredTo: '',
}

function apiMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError
    ? (error.problem.detail ?? error.problem.title)
    : fallback
}

function formatTimestamp(value: string, locale: Locale): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed)
}

function statusVariant(
  value: string,
): 'success' | 'warning' | 'danger' | 'neutral' | 'info' {
  if (value === 'verified' || value === 'succeeded') return 'success'
  if (value === 'violated' || value === 'failed') return 'danger'
  if (value === 'denied' || value === 'unverified') return 'warning'
  if (value === 'confidential' || value === 'top_secret') return 'info'
  return 'neutral'
}

function queryFromFilters(filters: FilterDraft): ListAuditEventsParams {
  return {
    ...(filters.sourceModule
      ? { source_module: filters.sourceModule.trim() }
      : {}),
    ...(filters.action ? { action: filters.action.trim() } : {}),
    ...(filters.classification
      ? { classification: filters.classification }
      : {}),
    ...(filters.occurredFrom
      ? { occurred_from: new Date(filters.occurredFrom).toISOString() }
      : {}),
    ...(filters.occurredTo
      ? { occurred_to: new Date(filters.occurredTo).toISOString() }
      : {}),
  }
}

export type AuditWorkspaceProps = {
  locale: Locale
  token: string
  capabilities: readonly string[]
}

export function AuditWorkspace({
  locale,
  token,
  capabilities,
}: AuditWorkspaceProps) {
  const t = copy[locale]
  const [draft, setDraft] = useState<FilterDraft>(EMPTY_FILTERS)
  const [filters, setFilters] = useState<FilterDraft>(EMPTY_FILTERS)
  const [items, setItems] = useState<AuditEvent[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadError, setLoadError] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<AuditEvent | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [exportReason, setExportReason] = useState('')
  const [exportFormat, setExportFormat] = useState<'csv' | 'ndjson'>('csv')
  const [exportBusy, setExportBusy] = useState(false)
  const [createdExport, setCreatedExport] =
    useState<AuditExportDescriptor | null>(null)
  const [exportNotice, setExportNotice] = useState<string | null>(null)
  const [streamKey, setStreamKey] = useState('')
  const [firstSequence, setFirstSequence] = useState('')
  const [lastSequence, setLastSequence] = useState('')
  const [verifyBusy, setVerifyBusy] = useState(false)
  const [verification, setVerification] = useState<AuditIntegrityResult | null>(
    null,
  )
  const [verifyNotice, setVerifyNotice] = useState<string | null>(null)

  const canExport = capabilities.includes('audit.event.export')
  const canVerify = capabilities.includes('audit.integrity.verify')

  const loadPage = useCallback(
    async (cursor: string | null, append: boolean) => {
      if (append) setLoadingMore(true)
      else setLoading(true)
      setLoadError(false)
      try {
        const page = await listAuditEvents(token, {
          ...queryFromFilters(filters),
          ...(cursor ? { cursor } : {}),
        })
        setItems((current) =>
          append ? [...current, ...page.items] : page.items,
        )
        setNextCursor(page.next_cursor)
      } catch {
        if (!append) setItems([])
        setLoadError(true)
      } finally {
        setLoading(false)
        setLoadingMore(false)
      }
    },
    [filters, token],
  )

  useEffect(() => {
    void loadPage(null, false)
  }, [loadPage])

  async function inspectEvent(event: AuditEvent): Promise<void> {
    setDetail(event)
    setDetailOpen(true)
    setDetailLoading(true)
    setDetailError(null)
    try {
      setDetail(await getAuditEvent(token, event.event_id))
    } catch (error) {
      setDetailError(apiMessage(error, t.operationFailed))
    } finally {
      setDetailLoading(false)
    }
  }

  function submitFilters(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    setFilters({ ...draft })
  }

  async function submitExport(
    event: FormEvent<HTMLFormElement>,
  ): Promise<void> {
    event.preventDefault()
    setExportBusy(true)
    setExportNotice(null)
    try {
      const query = queryFromFilters(filters)
      setCreatedExport(
        await createAuditExport(token, {
          format: exportFormat,
          reason: exportReason.trim(),
          filters: {
            ...(query.source_module
              ? { source_module: query.source_module }
              : {}),
            ...(query.action ? { action: query.action } : {}),
            ...(query.occurred_from
              ? { occurred_from: query.occurred_from }
              : {}),
            ...(query.occurred_to ? { occurred_to: query.occurred_to } : {}),
          },
        }),
      )
      setExportReason('')
    } catch (error) {
      setExportNotice(apiMessage(error, t.operationFailed))
    } finally {
      setExportBusy(false)
    }
  }

  async function saveExport(): Promise<void> {
    if (!createdExport) return
    setExportBusy(true)
    setExportNotice(null)
    try {
      const download = await downloadAuditExport(token, createdExport.id)
      const url = URL.createObjectURL(download.blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = download.filename
      anchor.click()
      URL.revokeObjectURL(url)
    } catch (error) {
      setExportNotice(apiMessage(error, t.operationFailed))
    } finally {
      setExportBusy(false)
    }
  }

  async function submitVerification(
    event: FormEvent<HTMLFormElement>,
  ): Promise<void> {
    event.preventDefault()
    const hasFirst = firstSequence !== ''
    const hasLast = lastSequence !== ''
    if (hasFirst !== hasLast) {
      setVerifyNotice(t.pairRequired)
      return
    }
    setVerifyBusy(true)
    setVerifyNotice(null)
    try {
      setVerification(
        await verifyAuditIntegrity(token, {
          stream_key: streamKey.trim(),
          ...(hasFirst && hasLast
            ? {
                first_sequence: Number(firstSequence),
                last_sequence: Number(lastSequence),
              }
            : {}),
        }),
      )
    } catch (error) {
      setVerifyNotice(apiMessage(error, t.operationFailed))
    } finally {
      setVerifyBusy(false)
    }
  }

  const integrityViolations = items.filter(
    (item) => item.integrity_status === 'violated',
  ).length
  const deniedEvents = items.filter((item) => item.outcome === 'denied').length
  const visibleModules = new Set(items.map((item) => item.source_module)).size

  return (
    <Page className="audit-workspace" aria-labelledby="audit-workspace-title">
      <div className="audit-heading">
        <p>{t.eyebrow}</p>
        <PageHeader
          id="audit-workspace-title"
          title={t.title}
          description={t.description}
        />
      </div>

      <section className="audit-metrics" aria-label={t.title}>
        <Metric
          value={items.length}
          label={t.loaded}
          icon={<FileSearch aria-hidden="true" />}
        />
        <Metric
          value={integrityViolations}
          label={t.violations}
          icon={<ShieldAlert aria-hidden="true" />}
          tone={integrityViolations > 0 ? 'danger' : 'success'}
        />
        <Metric
          value={deniedEvents}
          label={t.denied}
          icon={<Fingerprint aria-hidden="true" />}
          tone="warning"
        />
        <Metric
          value={visibleModules}
          label={t.modules}
          icon={<CheckCircle2 aria-hidden="true" />}
          tone="info"
        />
      </section>

      <Panel
        id="audit-filters"
        title={t.filters}
        level={2}
        className="audit-filter-panel"
      >
        <form className="audit-filter-grid" onSubmit={submitFilters}>
          <Field id="audit-source-module" label={t.sourceModule}>
            <input
              id="audit-source-module"
              value={draft.sourceModule}
              onChange={(event) =>
                setDraft({ ...draft, sourceModule: event.target.value })
              }
            />
          </Field>
          <Field id="audit-action" label={t.action}>
            <input
              id="audit-action"
              value={draft.action}
              onChange={(event) =>
                setDraft({ ...draft, action: event.target.value })
              }
            />
          </Field>
          <Field id="audit-classification" label={t.classification}>
            <select
              id="audit-classification"
              value={draft.classification}
              onChange={(event) =>
                setDraft({
                  ...draft,
                  classification: event.target
                    .value as FilterDraft['classification'],
                })
              }
            >
              <option value="">{t.allClassifications}</option>
              <option value="public">Public</option>
              <option value="internal">Internal</option>
              <option value="confidential">Confidential</option>
              <option value="top_secret">Top secret</option>
            </select>
          </Field>
          <Field id="audit-occurred-from" label={t.from}>
            <input
              id="audit-occurred-from"
              type="datetime-local"
              value={draft.occurredFrom}
              onChange={(event) =>
                setDraft({ ...draft, occurredFrom: event.target.value })
              }
            />
          </Field>
          <Field id="audit-occurred-to" label={t.to}>
            <input
              id="audit-occurred-to"
              type="datetime-local"
              value={draft.occurredTo}
              onChange={(event) =>
                setDraft({ ...draft, occurredTo: event.target.value })
              }
            />
          </Field>
          <div className="audit-filter-actions">
            <Button type="submit">
              <Search aria-hidden="true" />
              {t.apply}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setDraft(EMPTY_FILTERS)
                setFilters(EMPTY_FILTERS)
              }}
            >
              {t.clear}
            </Button>
          </div>
        </form>
      </Panel>

      <Panel
        id="audit-ledger"
        title={t.ledger}
        level={2}
        className="audit-ledger-panel"
      >
        {loading ? <SkeletonList label={t.loading} rows={6} /> : null}
        {!loading && loadError ? (
          <InlineError
            message={t.loadFailed}
            retryLabel={t.retry}
            onRetry={() => void loadPage(null, false)}
          />
        ) : null}
        {!loading && !loadError && items.length === 0 ? (
          <EmptyState
            icon={<FileSearch />}
            title={t.empty}
            body={t.emptyBody}
          />
        ) : null}
        {!loading && !loadError && items.length > 0 ? (
          <div className="table-scroll audit-table-scroll">
            <table>
              <caption className="sr-only">{t.ledger}</caption>
              <thead>
                <tr>
                  <th scope="col">{t.occurred}</th>
                  <th scope="col">{t.action}</th>
                  <th scope="col">{t.actor}</th>
                  <th scope="col">{t.subject}</th>
                  <th scope="col">{t.outcome}</th>
                  <th scope="col">{t.integrity}</th>
                  <th scope="col">{t.inspect}</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.event_id}>
                    <td>
                      <time dateTime={item.occurred_at}>
                        {formatTimestamp(item.occurred_at, locale)}
                      </time>
                    </td>
                    <td>
                      <strong>{item.action}</strong>
                      <small>{item.source_module}</small>
                    </td>
                    <td>
                      {item.actor_id ?? t.system}
                      <small>{item.actor_type}</small>
                    </td>
                    <td>
                      {item.subject_id ?? t.notAvailable}
                      <small>{item.subject_type}</small>
                    </td>
                    <td>
                      <StatusBadge variant={statusVariant(item.outcome)}>
                        {item.outcome}
                      </StatusBadge>
                    </td>
                    <td>
                      <StatusBadge
                        variant={statusVariant(item.integrity_status)}
                      >
                        {item.integrity_status}
                      </StatusBadge>
                    </td>
                    <td>
                      <Button
                        variant="secondary"
                        onClick={() => void inspectEvent(item)}
                      >
                        {t.inspect}
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {nextCursor ? (
          <div className="audit-load-more">
            <Button
              variant="secondary"
              disabled={loadingMore}
              onClick={() => void loadPage(nextCursor, true)}
            >
              {t.loadMore}
            </Button>
          </div>
        ) : null}
      </Panel>

      <div className="audit-operations-grid">
        {canExport ? (
          <Panel id="audit-export" title={t.exportTitle} level={2}>
            <form
              className="audit-operation-form"
              onSubmit={(event) => void submitExport(event)}
            >
              <Field
                id="audit-export-reason"
                label={t.exportReason}
                required
                help={t.exportReasonHelp}
              >
                <textarea
                  id="audit-export-reason"
                  rows={3}
                  required
                  minLength={1}
                  maxLength={500}
                  value={exportReason}
                  onChange={(event) => setExportReason(event.target.value)}
                />
              </Field>
              <Field id="audit-export-format" label={t.format}>
                <select
                  id="audit-export-format"
                  value={exportFormat}
                  onChange={(event) =>
                    setExportFormat(event.target.value as 'csv' | 'ndjson')
                  }
                >
                  <option value="csv">CSV</option>
                  <option value="ndjson">NDJSON</option>
                </select>
              </Field>
              <Button
                type="submit"
                disabled={exportBusy || exportReason.trim() === ''}
              >
                {t.createExport}
              </Button>
            </form>
            {exportNotice ? (
              <p role="alert" className="audit-notice-error">
                {exportNotice}
              </p>
            ) : null}
            {createdExport ? (
              <div className="audit-result" role="status">
                <strong>{t.exportReady}</strong>
                <span>
                  {createdExport.event_count} {t.events} · {t.expires}{' '}
                  {formatTimestamp(createdExport.expires_at, locale)}
                </span>
                <Button
                  variant="secondary"
                  disabled={exportBusy}
                  onClick={() => void saveExport()}
                >
                  <Download aria-hidden="true" />
                  {t.download}
                </Button>
              </div>
            ) : null}
          </Panel>
        ) : null}

        {canVerify ? (
          <Panel id="audit-integrity" title={t.verifyTitle} level={2}>
            <form
              className="audit-operation-form"
              onSubmit={(event) => void submitVerification(event)}
            >
              <Field id="audit-stream-key" label={t.streamKey} required>
                <input
                  id="audit-stream-key"
                  required
                  maxLength={160}
                  value={streamKey}
                  onChange={(event) => setStreamKey(event.target.value)}
                />
              </Field>
              <div className="audit-sequence-grid">
                <Field
                  id="audit-first-sequence"
                  label={t.firstSequence}
                  help={t.rangeHelp}
                >
                  <input
                    id="audit-first-sequence"
                    type="number"
                    min={1}
                    value={firstSequence}
                    onChange={(event) => setFirstSequence(event.target.value)}
                  />
                </Field>
                <Field id="audit-last-sequence" label={t.lastSequence}>
                  <input
                    id="audit-last-sequence"
                    type="number"
                    min={1}
                    value={lastSequence}
                    onChange={(event) => setLastSequence(event.target.value)}
                  />
                </Field>
              </div>
              <Button
                type="submit"
                disabled={verifyBusy || streamKey.trim() === ''}
              >
                {t.verify}
              </Button>
            </form>
            {verifyNotice ? (
              <p role="alert" className="audit-notice-error">
                {verifyNotice}
              </p>
            ) : null}
            {verification ? (
              <div className="audit-result" role="status">
                <strong>{t.verified}</strong>
                <StatusBadge
                  variant={statusVariant(verification.integrity_status)}
                >
                  {verification.integrity_status}
                </StatusBadge>
                <span>
                  {verification.verified_event_count} {t.events} ·{' '}
                  {verification.first_sequence}–{verification.last_sequence}
                </span>
              </div>
            ) : null}
          </Panel>
        ) : null}
      </div>

      <Drawer
        open={detailOpen}
        onClose={() => setDetailOpen(false)}
        title={t.eventDetail}
        ariaLabelClose={t.close}
        className="audit-detail-drawer"
      >
        {detailLoading ? <SkeletonList label={t.loading} rows={4} /> : null}
        {detailError ? <InlineError message={detailError} /> : null}
        {!detailLoading && detail ? (
          <AuditEventDetail event={detail} locale={locale} />
        ) : null}
      </Drawer>
    </Page>
  )
}

function Metric({
  value,
  label,
  icon,
  tone = 'neutral',
}: {
  value: number
  label: string
  icon: ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
}) {
  return (
    <div className={`audit-metric audit-metric--${tone}`}>
      <span>{icon}</span>
      <div>
        <strong>{value}</strong>
        <small>{label}</small>
      </div>
    </div>
  )
}

function AuditEventDetail({
  event,
  locale,
}: {
  event: AuditEvent
  locale: Locale
}) {
  const t = copy[locale]
  const facts = [
    [t.eventId, event.event_id],
    [t.correlationId, event.correlation_id],
    [t.eventType, event.event_type],
    [t.occurred, formatTimestamp(event.occurred_at, locale)],
    [t.recorded, formatTimestamp(event.recorded_at, locale)],
    [t.retention, formatTimestamp(event.retention_until, locale)],
    [t.accessDecision, event.access_decision_id ?? t.notAvailable],
  ]
  return (
    <div className="audit-detail">
      <div className="audit-detail-badges">
        <StatusBadge variant={statusVariant(event.outcome)}>
          {event.outcome}
        </StatusBadge>
        <StatusBadge variant={statusVariant(event.integrity_status)}>
          {event.integrity_status}
        </StatusBadge>
        <StatusBadge variant={statusVariant(event.classification)}>
          {event.classification}
        </StatusBadge>
      </div>
      <dl>
        {facts.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
      <section aria-labelledby="audit-context-title">
        <h3 id="audit-context-title">{t.context}</h3>
        <p className="audit-redaction-note">{t.redacted}</p>
        <dl className="audit-context-list">
          {Object.entries(event.context).map(([key, value]) => (
            <div key={key}>
              <dt>{key}</dt>
              <dd>
                {typeof value === 'string' ? value : JSON.stringify(value)}
              </dd>
            </div>
          ))}
        </dl>
      </section>
    </div>
  )
}
