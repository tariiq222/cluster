import { useCallback, useState } from 'react'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Panel, PanelGrid, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { dispatchPlatformBackup, getPlatformBackups, getPlatformHealth, getPlatformOperationsOverview } from '../platform-api'
import { healthCopy, overviewCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, useSectionLoad } from '../section-support'
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
  const { scopeEpoch } = usePrincipal()
  const [actionBusy, setActionBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)

  const fetcher = useCallback(async (): Promise<OverviewPayload> => {
    const [overview, health, backups] = await Promise.all([
      getPlatformOperationsOverview(csrfToken),
      getPlatformHealth(csrfToken),
      getPlatformBackups(csrfToken),
    ])
    return { overview, health, backups }
  }, [csrfToken])

  const { state, data, reload } = useSectionLoad(fetcher, isOverviewEmpty, scopeEpoch)

  const runBackup = useCallback(async () => {
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await dispatchPlatformBackup(csrfToken)
      setActionNotice(t(overviewCopy.backupRequested, locale))
      reload()
    } catch (error) {
      setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, locale, reload])

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { overview, health, backups } = data
  const backupAction = 'platform_operations.backup.run'
  const canRunBackup = actionAllowed(overview.allowed_actions, backupAction)
  const checks = overview.metrics?.health_checks ?? health.checks ?? []

  return (
    <PanelGrid>
      <Panel
        id="platform-overview-status"
        title={t(overviewCopy.status, locale)}
        actions={canRunBackup ? (
          <Button variant="secondary" onClick={() => void runBackup()} disabled={actionBusy}>
            {t(overviewCopy.runBackup, locale)}
          </Button>
        ) : undefined}
      >
        <div className="metric-grid">
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.status, locale)}</span>
            <span className="metric-tile__value">
              <StatusBadge variant={statusVariant(overview.status)}>{statusText(overview.status, locale)}</StatusBadge>
            </span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.checksCount, locale)}</span>
            <span className="metric-tile__value">{checks.length}</span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.backupStatus, locale)}</span>
            <span className="metric-tile__value">
              <StatusBadge variant={statusVariant(backups.status)}>{statusText(backups.status, locale)}</StatusBadge>
            </span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(overviewCopy.lastBackup, locale)}</span>
            <span className="metric-tile__value">{formatDate(backups.last_successful_at, locale) || '—'}</span>
          </div>
        </div>
        {overview.updated_at && (
          <p className="screen-list__row-meta">
            {t(overviewCopy.updatedAt, locale)}: {formatDate(overview.updated_at, locale)}
          </p>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Panel id="platform-overview-issues" title={t(overviewCopy.issues, locale)}>
        {overview.issues.length === 0 ? (
          <p className="screen-list__row-meta">{t(overviewCopy.noIssues, locale)}</p>
        ) : (
          <ul className="screen-list">
            {overview.issues.map((issue) => (
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
