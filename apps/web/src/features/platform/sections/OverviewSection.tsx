import { useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, Database, ServerCog, TriangleAlert } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePlatformHealth, usePlatformOperationsOverview } from '../../../api/hooks'
import { formatDate } from '../../../i18n'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/states'
import { queryResourceState, type QueryLike } from '../section-support'
import { SectionBoundary } from '../section-state'
import { getPlatformBackups } from '../platform-api'
import { healthCopy, overviewCopy, platformCopy, t } from '../platform-copy'
import type { PlatformBackupReport, PlatformHealth, PlatformOperationsOverview } from '../platform-types'

interface OverviewPayload {
  overview: PlatformOperationsOverview
  health: PlatformHealth
  backups: PlatformBackupReport
}

function isOverviewEmpty(payload: OverviewPayload): boolean {
  return (
    payload.overview === null ||
    (payload.overview.metrics?.health_checks?.length === 0 && payload.overview.issues.length === 0)
  )
}

function statusLabel(status: string, locale: 'ar' | 'en'): string {
  if (status === 'healthy') return t(overviewCopy.healthy, locale)
  if (status === 'degraded') return t(overviewCopy.degraded, locale)
  if (status === 'critical' || status === 'unhealthy') return t(overviewCopy.critical, locale)
  return t(overviewCopy.unknown, locale)
}

/** Status as icon + text — a colour alone is never the only signal. */
function StatusWithIcon({ status, locale }: { status: string; locale: 'ar' | 'en' }) {
  const icon =
    status === 'healthy' || status === 'ok'
      ? <CircleCheck className="size-4 text-primary" aria-hidden="true" />
      : status === 'degraded'
        ? <TriangleAlert className="size-4" aria-hidden="true" />
        : status === 'critical' || status === 'unhealthy'
          ? <CircleAlert className="size-4 text-destructive" aria-hidden="true" />
          : <CircleAlert className="size-4" aria-hidden="true" />
  return (
    <Badge variant="outline" className="gap-1">
      {icon}
      {statusLabel(status, locale)}
    </Badge>
  )
}

export function OverviewSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()

  const overviewQuery = usePlatformOperationsOverview()
  const healthQuery = usePlatformHealth()
  const backupsQuery = useQuery({
    queryKey: ['platform-backups'],
    queryFn: () => getPlatformBackups(csrfToken),
  })

  const overview = overviewQuery.data as unknown as PlatformOperationsOverview | undefined
  const health = healthQuery.data as unknown as PlatformHealth | undefined
  const backups = backupsQuery.data ?? null

  const data: OverviewPayload | null =
    overview !== undefined && health !== undefined && backups !== null
      ? { overview, health, backups }
      : null

  const combinedQuery: QueryLike<OverviewPayload> = {
    isPending: overviewQuery.isPending || healthQuery.isPending || backupsQuery.isPending,
    error: overviewQuery.error ?? healthQuery.error ?? backupsQuery.error,
    data,
  }
  const state = queryResourceState(combinedQuery, isOverviewEmpty)

  const reload = useCallback(() => {
    void overviewQuery.refetch()
    void healthQuery.refetch()
    void backupsQuery.refetch()
  }, [backupsQuery, healthQuery, overviewQuery])

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(platformCopy.empty, locale)} />}
      />
    )
  }

  const { overview: overviewData, health: healthData, backups: backupsData } = data
  const checks = overviewData.metrics?.health_checks ?? healthData.checks ?? []

  return (
    <section aria-labelledby="platform-overview" className="space-y-4">
      <div>
        <h2 id="platform-overview" className="text-xl font-semibold tracking-tight">
          {t(overviewCopy.status, locale)}
        </h2>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
            <CardTitle className="text-sm font-medium">{t(overviewCopy.status, locale)}</CardTitle>
            <ServerCog className="size-4 text-muted-foreground" aria-hidden="true" />
          </CardHeader>
          <CardContent>
            <StatusWithIcon status={overviewData.status} locale={locale} />
            {overviewData.updated_at && (
              <CardDescription className="mt-2">
                {t(overviewCopy.updatedAt, locale)}: {formatDate(overviewData.updated_at, locale)}
              </CardDescription>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
            <CardTitle className="text-sm font-medium">{t(overviewCopy.checksCount, locale)}</CardTitle>
            <Database className="size-4 text-muted-foreground" aria-hidden="true" />
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold">{checks.length}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
            <CardTitle className="text-sm font-medium">{t(overviewCopy.backupStatus, locale)}</CardTitle>
            <Database className="size-4 text-muted-foreground" aria-hidden="true" />
          </CardHeader>
          <CardContent>
            <StatusWithIcon status={backupsData.status} locale={locale} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
            <CardTitle className="text-sm font-medium">{t(overviewCopy.lastBackup, locale)}</CardTitle>
            <Database className="size-4 text-muted-foreground" aria-hidden="true" />
          </CardHeader>
          <CardContent>
            <p className="text-sm">{formatDate(backupsData.last_successful_at, locale) || '—'}</p>
            {backupsData.last_failed_at && (
              <CardDescription className="mt-1">
                {t(overviewCopy.lastFailed, locale)}: {formatDate(backupsData.last_failed_at, locale)}
              </CardDescription>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t(overviewCopy.issues, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          {overviewData.issues.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t(overviewCopy.noIssues, locale)}</p>
          ) : (
            <ul className="space-y-2">
              {overviewData.issues.map((issue) => (
                <li key={`${issue.source}-${issue.code}`} className="flex items-center gap-2 text-sm">
                  <CircleAlert className="size-4 text-destructive" aria-hidden="true" />
                  <span className="font-medium">{issue.code}</span>
                  <span className="text-muted-foreground">{issue.source}</span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t(overviewCopy.healthChecks, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          {checks.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t(platformCopy.empty, locale)}</p>
          ) : (
            <ul className="space-y-2">
              {checks.map((check) => (
                <li key={check.code} className="flex items-center justify-between gap-2 text-sm">
                  <span className="flex items-center gap-2 font-medium">
                    <Database className="size-4 text-muted-foreground" aria-hidden="true" />
                    {check.code}
                  </span>
                  <span className="flex items-center gap-3">
                    <span className="text-muted-foreground">{t(healthCopy.latency, locale)}: {check.latency_ms} ms</span>
                    <StatusWithIcon status={check.status} locale={locale} />
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
