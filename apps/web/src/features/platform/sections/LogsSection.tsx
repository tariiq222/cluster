import { useCallback, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArchiveRestore, ListFilter } from 'lucide-react'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { listPlatformTechnicalLogs } from '../platform-api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  Alert,
  AlertAction,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { EmptyState } from '@/components/states'
import { actionAllowed, isEmptyCollection, queryResourceState } from '../section-support'
import { SectionBoundary } from '../section-state'
import { logsCopy, t } from '../platform-copy'
import type { PlatformTechnicalLogEntry, PlatformTechnicalLogList } from '../platform-types'

function severityLabel(severity: string, locale: 'ar' | 'en'): string {
  const labels = logsCopy.severities as Record<string, { ar: string; en: string }>
  const entry = labels[severity]
  return entry !== undefined ? t(entry, locale) : severity
}

function isLogsEmpty(payload: PlatformTechnicalLogList): boolean {
  return isEmptyCollection(payload.items)
}

export function LogsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const [severityFilter, setSeverityFilter] = useState<string>('all')

  const logsQuery = useQuery({
    queryKey: ['platform-logs'],
    queryFn: () => listPlatformTechnicalLogs(csrfToken, { per_page: 50 }),
  })

  const data: PlatformTechnicalLogList | null = logsQuery.data ?? null

  /*
   * A 503 problem+json response means the logs are deferred in this
   * environment — render an explanatory Alert with a restore-request action,
   * NOT the generic error state. This is the behaviour asserted by
   * platform-settings-live.spec.ts and the unit suite.
   */
  const deferred =
    logsQuery.error instanceof ApiError && logsQuery.error.status === 503

  const state = queryResourceState(
    { isPending: logsQuery.isPending, error: deferred ? null : logsQuery.error, data },
    isLogsEmpty,
  )

  const reload = useCallback(() => {
    void logsQuery.refetch()
  }, [logsQuery])

  const canRestore = actionAllowed(data?.allowed_actions, 'platform_operations.logs.restore')

  /*
   * A 503 deferred response carries no `data`, so the server-provided
   * `allowed_actions` are unavailable. The restore action is gated on the
   * principal capability read from `/me` instead — never exposed to a
   * logs.read-only principal.
   */
  const canRestoreByPrincipal =
    (principal.capabilities ?? []).includes('platform_operations.logs.restore')

  const severityOptions = useMemo(() => {
    const seen = new Set<string>()
    for (const entry of data?.items ?? []) seen.add(entry.severity)
    return Array.from(seen)
  }, [data])

  const filtered = useMemo(() => {
    if (severityFilter === 'all') return data?.items ?? []
    return (data?.items ?? []).filter((entry) => entry.severity === severityFilter)
  }, [data, severityFilter])

  if (deferred) {
    return (
      <section aria-labelledby="platform-logs-title" className="space-y-4">
        <h2 id="platform-logs-title" className="text-xl font-semibold tracking-tight">
          {t(logsCopy.title, locale)}
        </h2>
        <Alert>
          <ArchiveRestore className="size-4" aria-hidden="true" />
          <AlertTitle>{t(logsCopy.deferredTitle, locale)}</AlertTitle>
          <AlertDescription>{t(logsCopy.deferredBody, locale)}</AlertDescription>
          {canRestoreByPrincipal && (
            <AlertAction>
              <Button variant="outline" size="sm" onClick={() => navigate('/platform/logs/restore')}>
                <ArchiveRestore className="size-4" aria-hidden="true" />
                {t(logsCopy.deferredRestore, locale)}
              </Button>
            </AlertAction>
          )}
        </Alert>
      </section>
    )
  }

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(logsCopy.empty, locale)} />}
      />
    )
  }

  return (
    <section aria-labelledby="platform-logs-title" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="platform-logs-title" className="text-xl font-semibold tracking-tight">
          {t(logsCopy.title, locale)}
        </h2>
        {canRestore && (
          <Button variant="outline" size="sm" onClick={() => navigate('/platform/logs/restore')}>
            <ArchiveRestore className="size-4" aria-hidden="true" />
            {t(logsCopy.restore, locale)}
          </Button>
        )}
      </div>

      <div className="flex items-center gap-2">
        <ListFilter className="size-4 text-muted-foreground" aria-hidden="true" />
        <Select value={severityFilter} onValueChange={setSeverityFilter}>
          <SelectTrigger className="w-44" aria-label={t(logsCopy.severity, locale)}>
            <SelectValue placeholder={t(logsCopy.allSeverities, locale)} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t(logsCopy.allSeverities, locale)}</SelectItem>
            {severityOptions.map((severity) => (
              <SelectItem key={severity} value={severity}>
                {severityLabel(severity, locale)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">{t(logsCopy.title, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          {filtered.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t(logsCopy.empty, locale)}</p>
          ) : (
            <ul className="divide-y">
              {filtered.map((entry: PlatformTechnicalLogEntry) => (
                <li key={entry.id} className="flex items-start gap-3 py-2 text-sm">
                  <Badge variant="outline" className="shrink-0 gap-1">
                    {severityLabel(entry.severity, locale)}
                  </Badge>
                  <div className="min-w-0 flex-1">
                    <p className="font-mono text-xs">{entry.source}{entry.category ? ` · ${entry.category}` : ''}</p>
                    <p className="truncate">{locale === 'ar' ? entry.message_ar : entry.message_en}</p>
                  </div>
                  <span className="shrink-0 text-muted-foreground text-xs" dir="ltr">
                    {formatDate(entry.occurred_at, locale)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </section>
  )
}
