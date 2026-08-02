import { useState } from 'react'
import { CircleAlert, Clock3, Download, FileCheck2 } from 'lucide-react'
import { toast } from 'sonner'
import { useLocale, useSessionToken } from '../../app/session-context'
import { formatDate } from '../../i18n'
import { downloadReportExport } from '../../api/reporting-download'
import { downloadAuditExport } from '../../api/audit-download'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/states'
import { reportsCopy } from './reports-copy'
import {
  isExportReady,
  isTerminalExportStatus,
  useExportStatus,
  useTrackedExports,
  type TrackedExport,
} from './export-tracker'

/*
 * Exports created during the current workspace session. Each row polls its
 * own export through the generated get-by-ID endpoint; the query's
 * `refetchInterval` returns false at a terminal status so polling never runs
 * forever. Download is enabled only when the export is terminal-ready.
 */
export function ExportsTab() {
  const locale = useLocale()
  const t = reportsCopy[locale]
  const exports = useTrackedExports()

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-semibold tracking-tight">{t.exportsTitle}</h2>
      <p className="text-muted-foreground text-sm">{t.exportsDescription}</p>
      {exports.length === 0 ? (
        <EmptyState title={t.noExports} body={t.noExportsBody} />
      ) : (
        <div className="grid gap-4">
          {exports.map((entry) => (
            <ExportRow key={entry.id} entry={entry} />
          ))}
        </div>
      )}
    </div>
  )
}

function ExportRow({ entry }: { entry: TrackedExport }) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const t = reportsCopy[locale]
  const poll = useExportStatus(entry)
  const status = poll.data?.status ?? 'preparing'
  const ready = isExportReady(status)
  const failed = isTerminalExportStatus(status) && !ready
  const statusCopy = ready ? t.ready : failed ? t.failedShort : t.preparing
  const [downloading, setDownloading] = useState(false)

  async function handleDownload(): Promise<void> {
    if (!ready || downloading) return
    setDownloading(true)
    try {
      if (entry.kind === 'audit') {
        await downloadAuditExport(
          entry.id,
          entry.format === 'ndjson' ? 'ndjson' : 'csv',
          csrfToken,
          `audit-${entry.id}.${entry.format}`,
        )
      } else {
        await downloadReportExport(
          entry.id,
          entry.format === 'json' ? 'json' : 'csv',
          csrfToken,
          `report-${entry.id}.${entry.format}`,
        )
      }
    } catch {
      toast(t.downloadFailed)
    } finally {
      setDownloading(false)
    }
  }

  const statusIcon = ready ? (
    <FileCheck2 className="size-3.5" aria-hidden="true" />
  ) : failed ? (
    <CircleAlert className="size-3.5" aria-hidden="true" />
  ) : (
    <Clock3 className="size-3.5" aria-hidden="true" />
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">{entry.name}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <dl className="grid gap-1 text-sm sm:grid-cols-3">
          <div className="flex gap-2">
            <dt className="text-muted-foreground">{t.type}</dt>
            <dd>{entry.kind === 'report' ? t.reportType : t.auditType}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-muted-foreground">{t.format}</dt>
            <dd dir="ltr">{entry.format.toUpperCase()}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-muted-foreground">{t.created}</dt>
            <dd>{formatDate(entry.createdAt, locale)}</dd>
          </div>
        </dl>
        <div className="flex items-center justify-between gap-4 sm:justify-end">
          <Badge variant="outline">
            {statusIcon}
            {statusCopy}
          </Badge>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!ready || downloading}
            onClick={() => void handleDownload()}
          >
            <Download aria-hidden="true" />
            {t.download}
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
