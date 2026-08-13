import { useCallback, useEffect, useRef, useState } from 'react'
import * as generated from '../../api/generated/cluster'
import type { DomainResource, Entity } from '../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../api/http'
import { useReportsList } from '../../api/hooks'
import { usePrincipal } from '../../app/principal-context'
import { useLocale, useSession, useSessionToken } from '../../app/session-context'
import { formatDate, formatNumber, statusLabel } from '../../i18n'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { DeniedState, EmptyState, ErrorState, LoadingState } from '@/components/states'
import { reportsCopy } from './reports-copy'
import { registerExport } from './export-tracker'

type DetailState = 'idle' | 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'

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
  return entity.id
}

function pickStatus(entity: Entity): string {
  return String(entity.status ?? '')
}

function isScalar(value: unknown): value is string | number | boolean {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

/*
 * Reports tab: a responsive two-region layout — the report list/selector on
 * one side and the selected report detail on the other (stacked on mobile).
 * Exporting is asynchronous (202): a format Dialog opens, then a sonner
 * preparation toast fires, the export is handed to the session-local tracker
 * (Exports tab), and control returns immediately. No polling happens here.
 */
export function ReportsScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { session } = useSession()
  const principal = usePrincipal()
  const t = reportsCopy[locale]
  const scopeId = principal.effectiveScope?.scopeId
  const scopeEpoch = principal.scopeEpoch
  const canExport = principal.capabilities?.includes('reporting.export') ?? false

  const [selectedId, setSelectedId] = useState('')
  const [detail, setDetail] = useState<DomainResource | null>(null)
  const [detailState, setDetailState] = useState<DetailState>('idle')
  const requestRevision = useRef(0)

  const reportsQuery = useReportsList()
  const items = (reportsQuery.data as generated.CollectionResponse | undefined)?.items ?? []
  const listState: 'loading' | 'ready' | 'empty' | 'forbidden' | 'error' = reportsQuery.isLoading
    ? 'loading'
    : reportsQuery.isError
      ? reportsQuery.error instanceof ApiError && reportsQuery.error.status === 403
        ? 'forbidden'
        : 'error'
      : items.length > 0
        ? 'ready'
        : 'empty'

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
    if (!selectedId) return
    void loadDetail(selectedId)
  }, [loadDetail, selectedId, scopeEpoch])

  const [formatOpen, setFormatOpen] = useState(false)
  const [exportFormat, setExportFormat] = useState<'csv' | 'json'>('csv')
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  async function createExport(): Promise<void> {
    if (!canExport || !selectedId || exporting || detailState !== 'ready') return
    setExporting(true)
    setExportError(null)
    try {
      const entity = unwrap<Entity>(
        await generated.createReportExport(
          selectedId,
          { format: exportFormat },
          requestInit(csrfToken, { command: true }),
        ),
      )
      if (!isDomainResource(entity) || !entity.id) {
        setExportError(t.exportFailed)
        return
      }
      // 202 Accepted: return control immediately. Close the dialog, re-enable
      // the trigger, notify preparation, and register the export (owned by
      // the current session user) so the Exports tab can track and download it.
      setFormatOpen(false)
      toast(t.exportQueued)
      registerExport({
        id: entity.id,
        kind: 'report',
        name: detail ? entityTitle(detail) : selectedId,
        format: exportFormat,
        createdAt: entity.created_at ?? new Date().toISOString(),
        ownerUserId: session.userId,
      })
    } catch (error) {
      setExportError(apiMessage(error, t.exportFailed))
    } finally {
      setExporting(false)
    }
  }

  const numericValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, number] => typeof entry[1] === 'number')
    : []
  const scalarValues = detail
    ? Object.entries(detail.values ?? {}).filter((entry): entry is [string, string | number | boolean] => isScalar(entry[1]))
    : []

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-semibold tracking-tight">{t.reportsTitle}</h2>
      <p className="text-muted-foreground text-sm">{t.reportsDescription}</p>

      {listState === 'loading' ? <LoadingState rows={4} /> : null}
      {listState === 'forbidden' ? <DeniedState locale={locale} /> : null}
      {listState === 'error' ? (
        <ErrorState locale={locale} onRetry={() => void reportsQuery.refetch()} />
      ) : null}
      {listState === 'empty' ? <EmptyState title={t.empty} /> : null}

      {listState === 'ready' ? (
        <div className="grid gap-6 lg:grid-cols-[minmax(14rem,18rem)_1fr] lg:items-start">
          <Card>
            <CardHeader>
              <CardTitle className="text-base font-semibold">{t.selectReport}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-1">
              {items.map((item) => {
                const id = item.id
                const label = entityTitle(item)
                const active = id === selectedId
                return (
                  <Button
                    key={id}
                    type="button"
                    variant={active ? 'secondary' : 'ghost'}
                    className="justify-start whitespace-normal text-start"
                    onClick={() => setSelectedId(id)}
                  >
                    {label}
                  </Button>
                )
              })}
            </CardContent>
          </Card>

          <div className="space-y-4">
            {detailState === 'loading' ? <LoadingState rows={3} /> : null}
            {detailState === 'forbidden' || detailState === 'not-found' ? (
              <DeniedState locale={locale} />
            ) : null}
            {detailState === 'error' ? (
              <ErrorState locale={locale} onRetry={() => void loadDetail(selectedId)} />
            ) : null}
            {detailState === 'ready' && detail ? (
              <Card>
                <CardHeader>
                  <CardTitle>{entityTitle(detail)}</CardTitle>
                  <CardDescription>{t.reportDetail}</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div className="grid gap-1">
                      <span className="text-muted-foreground text-xs">{t.status}</span>
                      <span className="font-medium">{statusLabel(pickStatus(detail), locale)}</span>
                    </div>
                    <div className="grid gap-1">
                      <span className="text-muted-foreground text-xs">{t.classification}</span>
                      <span className="font-medium">{statusLabel(detail.classification, locale)}</span>
                    </div>
                    {typeof detail.version_number === 'number' ? (
                      <div className="grid gap-1">
                        <span className="text-muted-foreground text-xs">{t.version}</span>
                        <span className="font-medium">{formatNumber(detail.version_number, locale)}</span>
                      </div>
                    ) : null}
                    <div className="grid gap-1">
                      <span className="text-muted-foreground text-xs">{t.updated}</span>
                      <span className="font-medium">{formatDate(detail.updated_at, locale)}</span>
                    </div>
                  </div>
                  {numericValues.length > 0 ? (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                      {numericValues.map(([key, value]) => (
                        <div key={key} className="grid gap-1">
                          <span className="text-muted-foreground text-xs">{key}</span>
                          <span className="text-lg font-semibold">{formatNumber(value, locale)}</span>
                        </div>
                      ))}
                    </div>
                  ) : null}
                  {scalarValues.length > 0 ? (
                    <section aria-labelledby="report-values-title">
                      <h3 className="text-base font-semibold" id="report-values-title">
                        {t.values}
                      </h3>
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead>{t.values}</TableHead>
                            <TableHead>{t.status}</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {scalarValues.map(([key, value]) => (
                            <TableRow key={key}>
                              <TableCell>{key}</TableCell>
                              <TableCell>{String(value)}</TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </section>
                  ) : null}
                  <div>
                    {canExport ? (
                      <Button type="button" onClick={() => setFormatOpen(true)}>
                        {t.export}
                      </Button>
                    ) : null}
                  </div>
                </CardContent>
              </Card>
            ) : null}
          </div>
        </div>
      ) : null}

      {canExport ? (
        <Dialog open={formatOpen} onOpenChange={(next) => { if (!next && !exporting) setFormatOpen(false) }}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t.exportFormatTitle}</DialogTitle>
              <DialogDescription>{t.exportFormatHelp}</DialogDescription>
            </DialogHeader>
            <div className="grid gap-1.5">
              <Label htmlFor="report-export-format">{t.exportFormat}</Label>
              <Select value={exportFormat} onValueChange={(value) => setExportFormat(value as 'csv' | 'json')}>
                <SelectTrigger id="report-export-format">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="csv">CSV</SelectItem>
                  <SelectItem value="json">JSON</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {exportError ? (
              <p className="text-destructive text-sm" role="alert">
                {exportError}
              </p>
            ) : null}
            <DialogFooter>
              <Button type="button" variant="outline" disabled={exporting} onClick={() => setFormatOpen(false)}>
                {t.cancel}
              </Button>
              <Button type="button" disabled={exporting} onClick={() => void createExport()}>
                {t.createExport}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      ) : null}
    </div>
  )
}
