import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePlatformHealth, usePlatformOperationsOverview } from '../../../api/hooks'
import { Button, Panel, PanelGrid, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { dispatchPlatformBackup, getPlatformBackups } from '../platform-api'
import { healthCopy, overviewCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
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

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'healthy') return 'success'
  if (status === 'degraded') return 'warning'
  if (status === 'critical' || status === 'unhealthy') return 'danger'
  return 'neutral'
}

function statusText(status: string, locale: 'ar' | 'en'): string {
  if (status === 'healthy') return t(overviewCopy.healthy, locale)
  if (status === 'degraded') return t(overviewCopy.degraded, locale)
  if (status === 'critical' || status === 'unhealthy') return t(overviewCopy.critical, locale)
  return t(overviewCopy.unknown, locale)
}

export function OverviewSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)

  const overviewQuery = usePlatformOperationsOverview()
  const healthQuery = usePlatformHealth()
  const backupsQuery = useQuery({
    queryKey: ['platform-backups'],
    queryFn: () => getPlatformBackups(csrfToken),
  })

  const runBackupMutation = useMutation({
    mutationFn: () => dispatchPlatformBackup(csrfToken),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(overviewCopy.backupRequested, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-backups'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-operations-overview'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-health'] })
    },
    onError: (error) => {
      setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
    },
  })

  const overview = overviewQuery.data as unknown as PlatformOperationsOverview | undefined
  const health = healthQuery.data as unknown as PlatformHealth | undefined
  const backups = backupsQuery.data ?? null

  const data: OverviewPayload | null =
    overview !== undefined && health !== undefined && backups !== null
      ? { overview, health, backups }
      : null

  let state: SectionState = 'loading'
  if (!overviewQuery.isPending && !healthQuery.isPending && !backupsQuery.isPending) {
    const error = overviewQuery.error ?? healthQuery.error ?? backupsQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else if (data === null) state = 'empty'
    else state = isOverviewEmpty(data) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void overviewQuery.refetch()
    void healthQuery.refetch()
    void backupsQuery.refetch()
  }, [backupsQuery, healthQuery, overviewQuery])

  const runBackup = useCallback(() => {
    runBackupMutation.mutate()
  }, [runBackupMutation])

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { overview: overviewData, health: healthData, backups: backupsData } = data
  const backupAction = 'platform_operations.backup.run'
  const canRunBackup = actionAllowed(overviewData.allowed_actions, backupAction)
  const checks = overviewData.metrics?.health_checks ?? healthData.checks ?? []

  return (
    <PanelGrid>
      <Panel
        id="platform-overview-status"
        title={t(overviewCopy.status, locale)}
        actions={canRunBackup ? (
          <Button variant="secondary" onClick={() => void runBackup()} disabled={runBackupMutation.isPending}>
            {t(overviewCopy.runBackup, locale)}
          </Button>
        ) : undefined}
      >
        <div className="metric-grid">
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.status, locale)}</span>
            <span className="metric-tile__value">
              <StatusBadge variant={statusVariant(overviewData.status)}>{statusText(overviewData.status, locale)}</StatusBadge>
            </span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.checksCount, locale)}</span>
            <span className="metric-tile__value">{checks.length}</span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.backupStatus, locale)}</span>
            <span className="metric-tile__value">
              <StatusBadge variant={statusVariant(backupsData.status)}>{statusText(backupsData.status, locale)}</StatusBadge>
            </span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.lastBackup, locale)}</span>
            <span className="metric-tile__value">{formatDate(backupsData.last_successful_at, locale) || '—'}</span>
          </div>
        </div>
        {overviewData.updated_at && (
          <p className="screen-list__row-meta">
            {t(overviewCopy.updatedAt, locale)}: {formatDate(overviewData.updated_at, locale)}
          </p>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Panel id="platform-overview-issues" title={t(overviewCopy.issues, locale)}>
        {overviewData.issues.length === 0 ? (
          <p className="screen-list__row-meta">{t(overviewCopy.noIssues, locale)}</p>
        ) : (
          <ul className="screen-list">
            {overviewData.issues.map((issue) => (
              <li key={`${issue.source}-${issue.code}`} className="screen-list__row">
                <span className="screen-list__row-title">{issue.code}</span>
                <span className="screen-list__row-meta">{issue.source}</span>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel id="platform-overview-checks" title={t(overviewCopy.healthChecks, locale)}>
        {checks.length === 0 ? (
          <p className="screen-list__row-meta">{t(platformCopy.empty, locale)}</p>
        ) : (
          <table className="entity-table">
            <thead>
              <tr>
                <th scope="col">{t(healthCopy.code, locale)}</th>
                <th scope="col">{t(overviewCopy.status, locale)}</th>
                <th scope="col">{t(healthCopy.latency, locale)}</th>
              </tr>
            </thead>
            <tbody>
              {checks.map((check) => (
                <tr key={check.code}>
                  <td>{check.code}</td>
                  <td>
                    <StatusBadge variant={statusVariant(check.status)}>{statusText(check.status, locale)}</StatusBadge>
                  </td>
                  <td>{check.latency_ms} ms</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </PanelGrid>
  )
}
