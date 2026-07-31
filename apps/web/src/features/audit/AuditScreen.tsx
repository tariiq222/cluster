import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import * as generated from '../../api/generated/cluster'
import type { AuditEvent, AuditExportDescriptor, AuditIntegrityResult } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useAuditEvents } from '../../api/hooks'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate } from '../../i18n'
import { Button, Drawer, EmptyState, Field, InlineError, Page, PageHeader, Panel, SkeletonList, StatusBadge } from '../../ui'

const copy = {
  ar: {
    title: 'سجل التدقيق',
    description: 'استعرض سجلًا غير قابل للتغيير، نزّل لقطات مبررة، وتحقق من سلامة السلاسل دون كشف مواد التجزئة.',
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
    eventId: 'معرّف الحدث',
    correlationId: 'معرّف الارتباط',
    eventType: 'نوع الحدث',
    recorded: 'وقت التسجيل',
    retention: 'الاحتفاظ حتى',
    accessDecision: 'قرار الوصول',
    context: 'السياق المنقح',
    redacted: 'يعرض الخادم سياقًا منقحًا فقط. لا تتضمن هذه الواجهة التجزئات أو المفاتيح أو بصمة الطلب.',
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
    denied: 'غير مصرح لك بالوصول إلى سجل التدقيق.',
  },
  en: {
    title: 'Audit ledger',
    description:
      'Inspect the immutable ledger, download reason-bound snapshots, and verify stream integrity without exposing hash material.',
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
    exportReasonHelp: 'State a specific business purpose; it is recorded with the export request.',
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
    rangeHelp: 'Enter both bounds, or leave both empty to verify the available stream.',
    verify: 'Verify now',
    verified: 'Verification complete',
    pairRequired: 'First and last sequence must be supplied together.',
    operationFailed: 'The operation could not be completed.',
    notAvailable: 'Not available',
    system: 'System',
    denied: 'You are not authorized to view the audit ledger.',
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
  return error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : fallback
}

function statusVariant(value: string): 'success' | 'warning' | 'danger' | 'neutral' | 'info' {
  if (value === 'verified' || value === 'succeeded') return 'success'
  if (value === 'violated' || value === 'failed') return 'danger'
  if (value === 'denied' || value === 'unverified') return 'warning'
  if (value === 'confidential' || value === 'top_secret') return 'info'
  return 'neutral'
}

function queryFromFilters(filters: FilterDraft): generated.ListAuditEventsParams {
  return {
    ...(filters.sourceModule ? { source_module: filters.sourceModule.trim() } : {}),
    ...(filters.action ? { action: filters.action.trim() } : {}),
    ...(filters.classification ? { classification: filters.classification } : {}),
    ...(filters.occurredFrom ? { occurred_from: new Date(filters.occurredFrom).toISOString() } : {}),
    ...(filters.occurredTo ? { occurred_to: new Date(filters.occurredTo).toISOString() } : {}),
  }
}

export function AuditScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const t = copy[locale]
  const canRead = principal.capabilities?.includes('audit.event.read') ?? false

  if (!canRead) {
    return (
      <Page aria-labelledby="audit-title">
        <PageHeader id="audit-title" title={t.title} description={t.description} />
        <EmptyState title={t.denied} />
      </Page>
    )
  }

  return <AuditLedger locale={locale} />
}

function AuditLedger({ locale }: { locale: 'ar' | 'en' }) {
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = copy[locale]
  const canExport = principal.capabilities?.includes('audit.event.export') ?? false
  const canVerify = principal.capabilities?.includes('audit.integrity.verify') ?? false

  const queryClient = useQueryClient()
  const invalidateLedger = () => void queryClient.invalidateQueries({ queryKey: ['audit-events'] })

  const [draft, setDraft] = useState<FilterDraft>(EMPTY_FILTERS)
  const [filters, setFilters] = useState<FilterDraft>(EMPTY_FILTERS)
  const appliedFilters = useMemo(() => queryFromFilters(filters), [filters])
  const ledgerQuery = useAuditEvents({ ...appliedFilters, limit: 50 })
  const ledgerData = ledgerQuery.data as generated.AuditEventCollection | undefined
  const [appended, setAppended] = useState<AuditEvent[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadMoreError, setLoadMoreError] = useState<string | null>(null)

  useEffect(() => {
    setAppended([])
    setNextCursor(ledgerData?.next_cursor ?? null)
  }, [ledgerQuery.data])

  const items = useMemo(
    () => [...(ledgerData?.items ?? []), ...appended],
    [ledgerData, appended],
  )
  const loading = ledgerQuery.isLoading
  const loadError = ledgerQuery.isError ? apiMessage(ledgerQuery.error, t.loadFailed) : loadMoreError

  const retryLedger = () => {
    setLoadMoreError(null)
    void ledgerQuery.refetch()
  }

  const loadMore = useCallback(async () => {
    if (!nextCursor || loadingMore) return
    setLoadingMore(true)
    setLoadMoreError(null)
    try {
      const page = unwrap<generated.AuditEventCollection>(
        await generated.listAuditEvents(
          { ...appliedFilters, limit: 50, cursor: nextCursor },
          requestInit(csrfToken),
        ),
      )
      setAppended((current) => [...current, ...page.items])
      setNextCursor(page.next_cursor)
    } catch (error) {
      setLoadMoreError(apiMessage(error, t.loadFailed))
    } finally {
      setLoadingMore(false)
    }
  }, [appliedFilters, nextCursor, loadingMore, csrfToken, t.loadFailed])

  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<AuditEvent | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)

  async function inspectEvent(event: AuditEvent): Promise<void> {
    setDetail(event)
    setDetailOpen(true)
    setDetailLoading(true)
    setDetailError(null)
    try {
      setDetail(
        unwrap<AuditEvent>(await generated.getAuditEvent(event.event_id, requestInit(csrfToken))),
      )
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

  const [exportReason, setExportReason] = useState('')
  const [exportFormat, setExportFormat] = useState<'csv' | 'ndjson'>('csv')
  const [createdExport, setCreatedExport] = useState<AuditExportDescriptor | null>(null)

  const createExportMutation = useMutation({
    mutationFn: async (input: generated.AuditExportCreate) =>
      unwrap<AuditExportDescriptor>(
        await generated.createAuditExport(input, requestInit(csrfToken, { command: true })),
      ),
    onSuccess: (descriptor) => {
      setCreatedExport(descriptor)
      setExportReason('')
      invalidateLedger()
    },
  })
  const [downloading, setDownloading] = useState(false)
  const [downloadNotice, setDownloadNotice] = useState<string | null>(null)
  const exportBusy = createExportMutation.isPending || downloading
  const exportNotice = createExportMutation.isPending
    ? null
    : createExportMutation.isError
      ? apiMessage(createExportMutation.error, t.operationFailed)
      : downloadNotice

  function submitExport(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    const query = queryFromFilters(filters)
    createExportMutation.mutate({
      format: exportFormat,
      reason: exportReason.trim(),
      filters: {
        ...(query.source_module ? { source_module: query.source_module } : {}),
        ...(query.action ? { action: query.action } : {}),
        ...(query.occurred_from ? { occurred_from: query.occurred_from } : {}),
        ...(query.occurred_to ? { occurred_to: query.occurred_to } : {}),
      },
    })
  }

  async function saveExport(): Promise<void> {
    if (!createdExport) return
    setDownloading(true)
    setDownloadNotice(null)
    try {
      const accept = createdExport.format === 'ndjson' ? 'application/x-ndjson' : 'text/csv'
      const response = await fetch(
        `/api/v1/audit/exports/${encodeURIComponent(createdExport.id)}/download`,
        {
          credentials: 'include',
          headers: {
            ...requestInit(csrfToken).headers,
            Accept: accept,
          },
        },
      )
      if (!response.ok) {
        let problem: { type?: string; title?: string; status?: number } | null = null
        try {
          problem = (await response.json()) as { type?: string; title?: string; status?: number }
        } catch {
          problem = null
        }
        throw new ApiError(response.status, {
          type: typeof problem?.type === 'string' && problem.type !== '' ? problem.type : 'about:blank',
          title:
            typeof problem?.title === 'string' && problem.title !== ''
              ? problem.title
              : 'Audit export download failed',
          status: response.status,
        })
      }
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const match = /filename="?([^";]+)"?/.exec(disposition)
      const filename = match?.[1] ?? `audit-export-${createdExport.id}.${createdExport.format}`
      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      URL.revokeObjectURL(url)
    } catch (error) {
      setDownloadNotice(apiMessage(error, t.operationFailed))
    } finally {
      setDownloading(false)
    }
  }

  const [streamKey, setStreamKey] = useState('')
  const [firstSequence, setFirstSequence] = useState('')
  const [lastSequence, setLastSequence] = useState('')
  const [verification, setVerification] = useState<AuditIntegrityResult | null>(null)
  const [verifyNotice, setVerifyNotice] = useState<string | null>(null)

  const verifyMutation = useMutation({
    mutationFn: async (input: generated.AuditIntegrityRequest) =>
      unwrap<AuditIntegrityResult>(
        await generated.verifyAuditIntegrity(input, requestInit(csrfToken, { command: true })),
      ),
    onSuccess: (result) => {
      setVerification(result)
      invalidateLedger()
    },
  })
  const verifyBusy = verifyMutation.isPending
  const displayedVerifyNotice = verifyMutation.isPending
    ? null
    : verifyMutation.isError
      ? apiMessage(verifyMutation.error, t.operationFailed)
      : verifyNotice

  function submitVerification(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    const hasFirst = firstSequence !== ''
    const hasLast = lastSequence !== ''
    if (hasFirst !== hasLast) {
      setVerifyNotice(t.pairRequired)
      return
    }
    setVerifyNotice(null)
    verifyMutation.mutate({
      stream_key: streamKey.trim(),
      ...(hasFirst && hasLast
        ? { first_sequence: Number(firstSequence), last_sequence: Number(lastSequence) }
        : {}),
    })
  }

  const integrityViolations = items.filter((item) => item.integrity_status === 'violated').length
  const deniedEvents = items.filter((item) => item.outcome === 'denied').length
  const visibleModules = new Set(items.map((item) => item.source_module)).size

  return (
    <Page aria-labelledby="audit-title">
      <PageHeader id="audit-title" title={t.title} description={t.description} />

      <div className="metric-grid" role="group" aria-label={t.title}>
        <div className="metric-tile">
          <span className="metric-tile__value">{items.length}</span>
          <span className="metric-tile__label">{t.ledger}</span>
        </div>
        <div className={`metric-tile${integrityViolations > 0 ? ' metric-tile--danger' : ' metric-tile--success'}`}>
          <span className="metric-tile__value">{integrityViolations}</span>
          <span className="metric-tile__label">{t.integrity}</span>
        </div>
        <div className="metric-tile metric-tile--warning">
          <span className="metric-tile__value">{deniedEvents}</span>
          <span className="metric-tile__label">{t.outcome}</span>
        </div>
        <div className="metric-tile">
          <span className="metric-tile__value">{visibleModules}</span>
          <span className="metric-tile__label">{t.sourceModule}</span>
        </div>
      </div>

      <Panel id="audit-filters" title={t.filters} level={2}>
        <form className="inline-form" onSubmit={submitFilters}>
          <Field id="audit-source-module" label={t.sourceModule}>
            <input
              id="audit-source-module"
              value={draft.sourceModule}
              onChange={(event) => setDraft({ ...draft, sourceModule: event.currentTarget.value })}
            />
          </Field>
          <Field id="audit-action" label={t.action}>
            <input
              id="audit-action"
              value={draft.action}
              onChange={(event) => setDraft({ ...draft, action: event.currentTarget.value })}
            />
          </Field>
          <Field id="audit-classification" label={t.classification}>
            <select
              id="audit-classification"
              className="field__control"
              value={draft.classification}
              onChange={(event) =>
                setDraft({
                  ...draft,
                  classification: event.currentTarget.value as FilterDraft['classification'],
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
              onChange={(event) => setDraft({ ...draft, occurredFrom: event.currentTarget.value })}
            />
          </Field>
          <Field id="audit-occurred-to" label={t.to}>
            <input
              id="audit-occurred-to"
              type="datetime-local"
              value={draft.occurredTo}
              onChange={(event) => setDraft({ ...draft, occurredTo: event.currentTarget.value })}
            />
          </Field>
          <div className="form-actions">
            <Button type="submit">{t.apply}</Button>
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

      <Panel id="audit-ledger" title={t.ledger} level={2}>
        {loading ? <SkeletonList rows={6} /> : null}
        {!loading && loadError ? (
          <InlineError message={loadError} retryLabel={t.retry} onRetry={retryLedger} />
        ) : null}
        {!loading && !loadError && items.length === 0 ? (
          <EmptyState title={t.empty} body={t.emptyBody} />
        ) : null}
        {!loading && !loadError && items.length > 0 ? (
          <div className="table-scroll">
            <table className="entity-table">
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
                      <time dateTime={item.occurred_at}>{formatDate(item.occurred_at, locale)}</time>
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
                      <StatusBadge variant={statusVariant(item.outcome)}>{item.outcome}</StatusBadge>
                    </td>
                    <td>
                      <StatusBadge variant={statusVariant(item.integrity_status)}>
                        {item.integrity_status}
                      </StatusBadge>
                    </td>
                    <td>
                      <Button variant="secondary" onClick={() => void inspectEvent(item)}>
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
          <div className="pagination-bar">
            <Button variant="secondary" disabled={loadingMore} onClick={() => void loadMore()}>
              {t.loadMore}
            </Button>
          </div>
        ) : null}
      </Panel>

      <div className="panel-grid">
        {canExport ? (
          <Panel id="audit-export" title={t.exportTitle} level={2}>
            <form className="inline-form" onSubmit={submitExport}>
              <Field id="audit-export-reason" label={t.exportReason} required help={t.exportReasonHelp}>
                <textarea
                  id="audit-export-reason"
                  rows={3}
                  required
                  minLength={1}
                  maxLength={500}
                  value={exportReason}
                  onChange={(event) => setExportReason(event.currentTarget.value)}
                />
              </Field>
              <Field id="audit-export-format" label={t.format}>
                <select
                  id="audit-export-format"
                  className="field__control"
                  value={exportFormat}
                  onChange={(event) => setExportFormat(event.currentTarget.value as 'csv' | 'ndjson')}
                >
                  <option value="csv">CSV</option>
                  <option value="ndjson">NDJSON</option>
                </select>
              </Field>
              <div className="form-actions">
                <Button type="submit" disabled={exportBusy || exportReason.trim() === ''}>
                  {t.createExport}
                </Button>
              </div>
            </form>
            {exportNotice ? (
              <p className="status-message status-message--error" role="alert">
                {exportNotice}
              </p>
            ) : null}
            {createdExport ? (
              <div className="status-message" role="status">
                <p className="status-message status-message--success">{t.exportReady}</p>
                <p>
                  {createdExport.event_count} {t.events} · {t.expires}{' '}
                  {formatDate(createdExport.expires_at, locale)}
                </p>
                <Button variant="secondary" disabled={exportBusy} onClick={() => void saveExport()}>
                  {t.download}
                </Button>
              </div>
            ) : null}
          </Panel>
        ) : null}

        {canVerify ? (
          <Panel id="audit-integrity" title={t.verifyTitle} level={2}>
            <form className="inline-form" onSubmit={submitVerification}>
              <Field id="audit-stream-key" label={t.streamKey} required>
                <input
                  id="audit-stream-key"
                  required
                  maxLength={160}
                  value={streamKey}
                  onChange={(event) => setStreamKey(event.currentTarget.value)}
                />
              </Field>
              <Field id="audit-first-sequence" label={t.firstSequence} help={t.rangeHelp}>
                <input
                  id="audit-first-sequence"
                  type="number"
                  min={1}
                  value={firstSequence}
                  onChange={(event) => setFirstSequence(event.currentTarget.value)}
                />
              </Field>
              <Field id="audit-last-sequence" label={t.lastSequence}>
                <input
                  id="audit-last-sequence"
                  type="number"
                  min={1}
                  value={lastSequence}
                  onChange={(event) => setLastSequence(event.currentTarget.value)}
                />
              </Field>
              <div className="form-actions">
                <Button type="submit" disabled={verifyBusy || streamKey.trim() === ''}>
                  {t.verify}
                </Button>
              </div>
            </form>
            {displayedVerifyNotice ? (
              <p className="status-message status-message--error" role="alert">
                {displayedVerifyNotice}
              </p>
            ) : null}
            {verification ? (
              <div className="status-message" role="status">
                <p className="status-message status-message--success">{t.verified}</p>
                <p>
                  <StatusBadge variant={statusVariant(verification.integrity_status)}>
                    {verification.integrity_status}
                  </StatusBadge>{' '}
                  {verification.verified_event_count} {t.events} · {verification.first_sequence}–
                  {verification.last_sequence}
                </p>
                <p>
                  {t.ledger}: {verification.stream_key}
                </p>
              </div>
            ) : null}
          </Panel>
        ) : null}
      </div>

      <Drawer open={detailOpen} onClose={() => setDetailOpen(false)} title={t.eventDetail}>
        {detailLoading ? <SkeletonList rows={4} /> : null}
        {detailError ? <InlineError message={detailError} /> : null}
        {!detailLoading && detail ? <AuditEventDetail event={detail} locale={locale} /> : null}
      </Drawer>
    </Page>
  )
}

function AuditEventDetail({ event, locale }: { event: AuditEvent; locale: 'ar' | 'en' }) {
  const t = copy[locale]
  const facts: Array<[string, string]> = [
    [t.eventId, event.event_id],
    [t.correlationId, event.correlation_id],
    [t.eventType, event.event_type],
    [t.occurred, formatDate(event.occurred_at, locale)],
    [t.recorded, formatDate(event.recorded_at, locale)],
    [t.retention, formatDate(event.retention_until, locale)],
    [t.accessDecision, event.access_decision_id ?? t.notAvailable],
    [t.actor, `${event.actor_type}${event.actor_id ? ` · ${event.actor_id}` : ` · ${t.system}`}`],
    [t.subject, `${event.subject_type}${event.subject_id ? ` · ${event.subject_id}` : ` · ${t.notAvailable}`}`],
  ]
  return (
    <div className="detail-list">
      <p className="status-message">
        <StatusBadge variant={statusVariant(event.outcome)}>{event.outcome}</StatusBadge>{' '}
        <StatusBadge variant={statusVariant(event.integrity_status)}>{event.integrity_status}</StatusBadge>{' '}
        <StatusBadge variant={statusVariant(event.classification)}>{event.classification}</StatusBadge>
      </p>
      <dl>
        {facts.map(([label, value]) => (
          <div key={label} className="detail-list__row">
            <dt className="detail-list__key">{label}</dt>
            <dd className="detail-list__value">{value}</dd>
          </div>
        ))}
      </dl>
      <section aria-labelledby="audit-context-title">
        <h3 className="panel__heading" id="audit-context-title">
          {t.context}
        </h3>
        <p className="status-message">{t.redacted}</p>
        <dl>
          {Object.entries(event.context).map(([key, value]) => (
            <div key={key} className="detail-list__row">
              <dt className="detail-list__key">{key}</dt>
              <dd className="detail-list__value">
                {typeof value === 'string' ? value : JSON.stringify(value)}
              </dd>
            </div>
          ))}
        </dl>
      </section>
    </div>
  )
}
