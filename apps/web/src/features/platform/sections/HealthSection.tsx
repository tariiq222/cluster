import { useCallback } from 'react'
import { usePlatformHealth } from '../../../api/hooks'
import { CircleAlert, CircleCheck, Database, TriangleAlert } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { formatDate } from '../../../i18n'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/states'
import { queryResourceState } from '../section-support'
import { SectionBoundary } from '../section-state'
import { healthCopy, overviewCopy, platformCopy, t } from '../platform-copy'
import type { PlatformHealth } from '../platform-types'

function isHealthEmpty(payload: PlatformHealth): boolean {
  return payload.checks.length === 0
}

function statusLabel(status: string, locale: 'ar' | 'en'): string {
  if (status === 'healthy' || status === 'ok' || status === 'active' || status === 'enabled') {
    return t(overviewCopy.healthy, locale)
  }
  if (status === 'degraded' || status === 'warning') return t(overviewCopy.degraded, locale)
  if (status === 'critical' || status === 'unhealthy' || status === 'down' || status === 'disabled') {
    return t(overviewCopy.critical, locale)
  }
  return t(overviewCopy.unknown, locale)
}

function StatusWithIcon({ status, locale }: { status: string; locale: 'ar' | 'en' }) {
  const icon =
    status === 'healthy' || status === 'ok'
      ? <CircleCheck className="size-4 text-primary" aria-hidden="true" />
      : status === 'degraded' || status === 'warning'
        ? <TriangleAlert className="size-4" aria-hidden="true" />
        : status === 'critical' || status === 'unhealthy' || status === 'down'
          ? <CircleAlert className="size-4 text-destructive" aria-hidden="true" />
          : <CircleAlert className="size-4" aria-hidden="true" />
  return (
    <Badge variant="outline" className="gap-1">
      {icon}
      {statusLabel(status, locale)}
    </Badge>
  )
}

/*
 * Health dashboard. Only dependency name, status, latency, and check time are
 * rendered — never hosts, connection strings, DSNs, tokens, or environment
 * values (asserted by platform-settings-live.spec.ts). The platform's live
 * snapshot has no such fields, so the safest guarantee is that the section
 * only reads the projection fields it renders.
 */
export function HealthSection() {
  const locale = useLocale()
  const healthQuery = usePlatformHealth()
  const data = healthQuery.data as unknown as PlatformHealth | undefined

  const state = queryResourceState(
    { isPending: healthQuery.isPending, error: healthQuery.error, data },
    isHealthEmpty,
  )

  const reload = useCallback(() => {
    void healthQuery.refetch()
  }, [healthQuery])

  if (state !== 'ready' || data === undefined) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(healthCopy.checks, locale)} body={t(platformCopy.empty, locale)} />}
      />
    )
  }

  return (
    <section aria-labelledby="platform-health-title" className="space-y-4">
      <div>
        <h2 id="platform-health-title" className="text-xl font-semibold tracking-tight">
          {t(healthCopy.checks, locale)}
        </h2>
        <p className="text-muted-foreground text-sm">
          {t(overviewCopy.updatedAt, locale)}: {formatDate(data.updated_at, locale)}
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {data.checks.map((check) => (
          <Card key={check.code}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-2">
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <Database className="size-4 text-muted-foreground" aria-hidden="true" />
                {check.code}
              </CardTitle>
              <StatusWithIcon status={check.status} locale={locale} />
            </CardHeader>
            <CardContent className="text-sm">
              <p className="text-muted-foreground">
                {t(healthCopy.latency, locale)}: {check.latency_ms} ms
              </p>
              <p className="text-muted-foreground">
                {t(healthCopy.checkedAt, locale)}: {formatDate(check.checked_at, locale)}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  )
}
