import { useCallback, useEffect, useRef, useState } from 'react'
import * as generated from '../../../src/api/generated/cluster'
import type { DomainResource, Entity } from '../../../src/api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { Button, EmptyState, Field, InlineError, Page, PageHeader, Panel, PanelGrid, Select, SkeletonList } from '../../ui'

const copy = {
  ar: {
    title: 'التقارير',
    description: 'التقارير المتاحة ضمن نطاقك. اختر تقريرًا لعرض نتيجته أو تصديرها.',
    loading: 'جارٍ تحميل التقارير…',
    failed: 'تعذر تحميل التقارير.',
    empty: 'لا توجد تقارير متاحة ضمن نطاقك.',
    selectReport: 'اختر تقريرًا',
    reportDetail: 'تفاصيل التقرير',
    values: 'القيم',
    status: 'الحالة',
    classification: 'التصنيف',
    version: 'الإصدار',
    updated: 'آخر تحديث',
    export: 'تصدير',
    exportFormat: 'التنسيق',
    createExport: 'إنشاء التصدير',
    exportQueued: 'جارٍ تجهيز التصدير…',
    exportReady: 'التصدير جاهز للتنزيل',
    download: 'تنزيل الملف',
    exportFailed: 'تعذر إنشاء التصدير.',
    exportExpired: 'انتهت صلاحية التصدير أو تعذر تحميله.',
    denied: 'غير مصرح لك بالوصول إلى هذا التقرير.',
    notFound: 'لم نعثر على هذا التقرير.',
    retry: 'إعادة المحاولة',
    notAvailable: 'غير متاح',
  },
  en: {
    title: 'Reports',
    description: 'Reports available in your scope. Select a report to view or export its result.',
    loading: 'Loading reports…',
    failed: 'Reports could not be loaded.',
    empty: 'No reports are available in your scope.',
    selectReport: 'Select a report',
    reportDetail: 'Report details',
    values: 'Values',
    status: 'Status',
    classification: 'Classification',
    version: 'Version',
    updated: 'Updated',
    export: 'Export',
    exportFormat: 'Format',
    createExport: 'Create export',
    exportQueued: 'Preparing export…',
    exportReady: 'Export ready to download',
    download: 'Download file',
    exportFailed: 'The export could not be created.',
    exportExpired: 'The export expired or could not be downloaded.',
    denied: 'You are not authorized to view this report.',
    notFound: 'We could not find this report.',
    retry: 'Retry',
    notAvailable: 'Not available',
  },
} as const

const EXPORT_DONE = new Set(['ready', 'completed', 'available'])
const EXPORT_FAILED = new Set(['failed', 'error', 'cancelled'])

function apiMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : fallback
}

function isDomainResource(entity: Entity): entity is DomainResource {
  return 'resource_type' in entity
}

function entityTitle(entity: Entity): string {
  if (isDomainResource(entity)) {
    const title = entity.title ?? entity.name ?? entity.code
    if (title) return String(title)
  }
  if ('record_number' in entity) return entity.record_number
  return entity.id
}

function pickStatus(entity: Entity): string {
  return String(entity.status ?? '')
}

function downloadCsvArtifact(
  url: string,
  fallbackName: string,
  csrfToken: string,
): Promise<string> {
  return fetch(url, {
    credentials: 'include',
    headers: {
      ...requestInit(csrfToken).headers,
      Accept: 'text/csv',
    },
  }).then(async (response) => {
    if (!response.ok) {
      let problem: { type?: string; title?: string; status?: number } | null = null
      try {
        problem = (await response.json()) as { type?: string; title?: string; status?: number }
      } catch {
        problem = null
      }
      const type = typeof problem?.type === 'string' && problem.type !== '' ? problem.type : 'about:blank'
      const title = typeof problem?.title === 'string' && problem.title !== '' ? problem.title : 'Export download failed'
      throw new ApiError(response.status, { type, title, status: response.status })
    }
    const disposition = response.headers.get('Content-Disposition') ?? ''
    const match = /filename="?([^";]+)"?/.exec(disposition)
    const filename = match?.[1] ?? fallbackName
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filename
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(url)
    return filename
  })
}

function isScalar(value: unknown): value is string | number | boolean {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

export function ReportsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const t = copy[locale]
  const scopeId = principal.effectiveScope?.scopeId
  const scopeEpoch = principal.scopeEpoch

  const [items, setItems] = useState<Entity[]>([])
  const [state, setState] = useState<'loading' | 'ready' | 'empty' | 'forbidden' | 'error'>('loading')
  const [selectedId, setSelectedId] = useState('')
  const [detail, setDetail] = useState<DomainResource | null>(null)
  const [detailState, setDetailState] = useState<'idle' | 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'>('idle')
  const requestRevision = useRef(0)

  const loadList = useCallback(async () => {
    const request = ++requestRevision.current
    setState('loading')
    setItems([])
    setDetail(null)
    setDetailState('idle')
    try {
      const list = unwrap<generated.CollectionResponse>(
        await generated.listReports({ limit: 50 }, requestInit(csrfToken)),
      )
      if (request !== requestRevision.current) return
      setItems(list.items ?? [])
      setState((list.items?.length ?? 0) > 0 ? 'ready' : 'empty')
    } catch (error) {
      if (request !== requestRevision.current) return
      setState(error instanceof ApiError && error.status === 403 ? 'forbidden' : 'error')
    }
  }, [csrfToken])

  const loadDetail = useCallback(
    async (reportId: string) => {
      const request = ++requestRevision.current
      setDetail(null)
      setDetailState('loading')
      try {
        const entity = unwrap<Entity>(
          await generated.getReport(
            reportId,
            scopeId ? { scope_id: scopeId } : undefined,
            requestInit(csrfToken),
          ),
        )
        if (request !== requestRevision.current) return
        if (!isDomainResource(entity)) {
          setDetailState('error')
          return
        }
        setDetail(entity)
        setDetailState('ready')
      } catch (error) {
        if (request !== requestRevision.current) return
        setDetail(null)
        if (error instanceof ApiError && error.status === 403) setDetailState('forbidden')
        else if (error instanceof ApiError && error.status === 404) setDetailState('not-found')
        else setDetailState('error')
      }
    },
    [csrfToken, scopeId],
  )

  useEffect(() => {
    void loadList()
  }, [loadList, scopeEpoch])

  useEffect(() => {
    if (!selectedId) return
    void loadDetail(selectedId)
  }, [loadDetail, selectedId, scopeEpoch])

  const [exporting, setExporting] = useState(false)
  const [exportItem, setExportItem] = useState<DomainResource | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [downloading, setDownloading] = useState(false)

  async function createExport(): Promise<void> {
    if (!selectedId || exporting || detailState !== 'ready') return
    setExporting(true)
    setExportError(null)
    setExportItem(null)
    try {
      const entity = unwrap<Entity>(
        await generated.createReportExport(
          selectedId,
          { format: 'csv' },
          requestInit(csrfToken, { command: true }),
        ),
      )
      if (!isDomainResource(entity) || !entity.id) {
        setExportError(t.exportFailed)
        setExporting(false)
        return
      }
      setExportItem(entity)
    } catch (error) {
      setExportError(apiMessage(error, t.exportFailed))
      setExporting(false)
    }
  }

  const exportId = exportItem?.id ?? null

  useEffect(() => {
    if (!exportId) return
    let cancelled = false
    let timer: ReturnType<typeof setTimeout> | undefined
    let attempts = 0
    const maxAttempts = 12
    const poll = async (): Promise<void> => {
      if (cancelled) return
      try {
        const entity = unwrap<Entity>(await generated.getExport(exportId, requestInit(csrfToken)))
        if (cancelled) return
        if (!isDomainResource(entity)) {
          setExportError(t.exportFailed)
          setExporting(false)
          return
        }
        setExportItem(entity)
        const status = pickStatus(entity)
        if (EXPORT_DONE.has(status)) {
          setExporting(false)
          return
        }
        if (EXPORT_FAILED.has(status)) {
          setExportError(t.exportFailed)
          setExporting(false)
          return
        }
        if (attempts >= maxAttempts) {
          setExportError(t.exportFailed)
          setExporting(false)
          return
        }
        attempts += 1
        timer = setTimeout(() => void poll(), 1000 * Math.min(attempts, 6))
      } catch (error) {
        if (cancelled) return
        if (attempts >= maxAttempts) {
          setExportError(apiMessage(error, t.exportFailed))
          setExporting(false)
          return
        }
        attempts += 1
        timer = setTimeout(() => void poll(), 1000 * Math.min(attempts, 6))
      }
    }
    void poll()
    return () => {
      cancelled = true
      if (timer) clearTimeout(timer)
    }
  }, [exportId, csrfToken, t.exportFailed])

  async function downloadExport(): Promise<void> {
    if (!exportId || downloading) return
    setDownloading(true)
    setExportError(null)
    try {
      await downloadCsvArtifact(
        `/api/v1/exports/${encodeURIComponent(exportId)}`,
        `report-${exportId}.csv`,
        csrfToken,
      )
    } catch (error) {
      setExportError(apiMessage(error, t.exportExpired))
    } finally {
      setDownloading(false)
    }
  }

  const numericValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, number] => typeof entry[1] === 'number')
    : []
  const scalarValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, string | number | boolean] => isScalar(entry[1]))
    : []

  return (
    <Page aria-labelledby="reports-title">
      <PageHeader id="reports-title" title={t.title} description={t.description} />

      {state === 'loading' ? <SkeletonList rows={4} /> : null}
      {state === 'forbidden' ? <EmptyState title={t.denied} /> : null}
      {state === 'error' ? (
        <InlineError message={t.failed} retryLabel={t.retry} onRetry={() => void loadList()} />
      ) : null}
      {state === 'empty' ? <EmptyState title={t.empty} /> : null}

      {state === 'ready' ? (
        <Panel id="reports-list-panel" title={t.selectReport} level={2}>
          <Field id="reports-select" label={t.selectReport}>
            <Select
              id="reports-select"
              value={selectedId}
              onChange={(value) => setSelectedId(value)}
              options={items.map((item) => ({ value: item.id, label: entityTitle(item) }))}
              placeholder={t.selectReport}
            />
          </Field>
        </Panel>
      ) : null}

      {detailState === 'loading' ? <SkeletonList rows={3} /> : null}
      {detailState === 'forbidden' ? <EmptyState title={t.denied} /> : null}
      {detailState === 'not-found' ? <EmptyState title={t.notFound} /> : null}
      {detailState === 'error' ? (
        <InlineError message={t.failed} retryLabel={t.retry} onRetry={() => void loadDetail(selectedId)} />
      ) : null}

      {detailState === 'ready' && detail ? (
        <PanelGrid>
          <Panel id="report-detail-panel" title={t.reportDetail} level={2}>
            <div className="metric-grid" role="group" aria-label={t.reportDetail}>
              <div className="metric-tile">
                <span className="metric-tile__value">{statusLabel(pickStatus(detail), locale)}</span>
                <span className="metric-tile__label">{t.status}</span>
              </div>
              <div className="metric-tile">
                <span className="metric-tile__value">{statusLabel(detail.classification, locale)}</span>
                <span className="metric-tile__label">{t.classification}</span>
              </div>
              {typeof detail.version_number === 'number' ? (
                <div className="metric-tile">
                  <span className="metric-tile__value">{formatNumber(detail.version_number, locale)}</span>
                  <span className="metric-tile__label">{t.version}</span>
                </div>
              ) : null}
              <div className="metric-tile">
                <span className="metric-tile__value">{formatDate(detail.updated_at, locale)}</span>
                <span className="metric-tile__label">{t.updated}</span>
              </div>
            </div>
            {numericValues.length > 0 ? (
              <div className="metric-grid" role="group" aria-label={t.values}>
                {numericValues.map(([key, value]) => (
                  <div key={key} className="metric-tile metric-tile--success">
                    <span className="metric-tile__value">{formatNumber(value, locale)}</span>
                    <span className="metric-tile__label">{key}</span>
                  </div>
                ))}
              </div>
            ) : null}
            {scalarValues.length > 0 ? (
              <section aria-labelledby="report-values-title">
                <h3 className="panel__heading" id="report-values-title">
                  {t.values}
                </h3>
                <table className="entity-table">
                  <thead>
                    <tr>
                      <th scope="col">{t.values}</th>
                      <th scope="col">{t.status}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {scalarValues.map(([key, value]) => (
                      <tr key={key}>
                        <td>{key}</td>
                        <td>{String(value)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </section>
            ) : null}
          </Panel>

          <Panel id="report-export-panel" title={t.export} level={2}>
            <div className="inline-form">
              <Field id="report-export-format" label={t.exportFormat}>
                <Select
                  id="report-export-format"
                  value="csv"
                  onChange={() => undefined}
                  options={[{ value: 'csv', label: 'CSV' }]}
                />
              </Field>
              <Button type="button" onClick={() => void createExport()} disabled={exporting}>
                {t.createExport}
              </Button>
            </div>
            {exporting ? (
              <p className="status-message" role="status">
                {t.exportQueued}
              </p>
            ) : null}
            {exportError ? (
              <p className="status-message status-message--error" role="alert">
                {exportError}
              </p>
            ) : null}
            {exportItem && !exporting ? (
              <div className="status-message" role="status">
                <p className="status-message status-message--success">{t.exportReady}</p>
                <Button variant="secondary" onClick={() => void downloadExport()} disabled={downloading}>
                  {t.download}
                </Button>
              </div>
            ) : null}
          </Panel>
        </PanelGrid>
      ) : null}
    </Page>
  )
}
