import { formattingLocale, type Locale } from '../../app/copy'

import { useEffect, useState } from 'react'
import { Activity, DatabaseBackup, HardDrive, ShieldAlert } from 'lucide-react'

import { ApiError } from '../../api'
import {
  getPlatformBackups,
  getPlatformHealth,
  requestPlatformBackup,
} from '../../api/platform-settings'
import {
  Button,
  DataFreshness,
  MetricTile,
  Panel,
  PanelGrid,
  StatusBadge,
} from '../../ui'
import {
  isAllowed,
  screenText,
  platformEntity,
  stateGate,
  type PlatformScreenProps,
} from './screen-support'

type ActionState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string }

export function PlatformOverviewScreen({
  locale,
  state = 'success',
  allowedActions,
  resource,
  token,
}: PlatformScreenProps & { token?: string }) {
  const entity = platformEntity(resource)
  const [backupStatus, setBackupStatus] = useState<string | null>(null)
  const [backupAt, setBackupAt] = useState<string | null>(null)
  const [healthCheckCount, setHealthCheckCount] = useState<number | null>(null)
  const [healthHealthy, setHealthHealthy] = useState<number | null>(null)
  const [alertCount, setAlertCount] = useState<number | null>(null)
  const [issues, setIssues] = useState<string[]>([])
  const [action, setAction] = useState<{ kind: 'health' | 'backup' } | null>(null)
  const [actionState, setActionState] = useState<ActionState>({ kind: 'idle' })

  useEffect(() => {
    if (token === undefined) return
    let cancelled = false
    void (async () => {
      try {
        const [backups, health] = await Promise.all([
          getPlatformBackups(token),
          getPlatformHealth(token),
        ])
        if (cancelled) return
        const backupStatusValue = readString(backups.status) ?? 'unknown'
        const backupAtValue = readString((backups as { last_successful_at?: unknown }).last_successful_at)
        const checks = readChecks(health)
        setBackupStatus(backupStatusValue)
        setBackupAt(backupAtValue ?? null);
        setHealthCheckCount(checks.length)
        setHealthHealthy(checks.filter((check) => check.status === 'healthy').length)
      } catch {
        // The hook above already surfaces failures; the overview simply falls back
        // to the entity that the route already loaded.
      }
    })()
    return () => {
      cancelled = true
    }
  }, [token])

  const gate = stateGate(
    locale,
    state,
    screenText(locale, 'لا يوجد نشاط حديث', 'No recent activity'),
  )
  if (gate) return gate
  const stale = state === 'stale'
  const overallStatus = readString(entity?.status) ?? 'unknown'
  const overallVariant: 'success' | 'warning' | 'danger' | 'neutral' =
    overallStatus === 'healthy'
      ? 'success'
      : overallStatus === 'degraded'
      ? 'warning'
      : overallStatus === 'unhealthy'
      ? 'danger'
      : 'neutral'
  const overallLabel = screenText(
    locale,
    overallStatus === 'healthy'
      ? 'سليم'
      : overallStatus === 'degraded'
      ? 'متدهور'
      : overallStatus === 'unhealthy'
      ? 'فشل'
      : 'غير معروف',
    overallStatus === 'healthy'
      ? 'Healthy'
      : overallStatus === 'degraded'
      ? 'Degraded'
      : overallStatus === 'unhealthy'
      ? 'Failed'
      : 'Unknown',
  )
  const isHealthDegraded = overallStatus === 'degraded'
  async function refreshHealth(): Promise<void> {
    if (token === undefined) return
    setAction({ kind: 'health' })
    setActionState({ kind: 'pending' })
    try {
      const result = await getPlatformHealth(token)
      const checks = readChecks(result)
      const issuesNew = readIssues(result)
      setHealthCheckCount(checks.length)
      setHealthHealthy(checks.filter((check) => check.status === 'healthy').length)
      setIssues(issuesNew)
      setAlertCount(issuesNew.length)
      setActionState({
        kind: 'success',
        message: screenText(locale, 'تم تحديث فحص الصحة.', 'Health check refreshed.'),
      })
    } catch (error) {
      setActionState({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setAction(null)
    }
  }

  async function runBackup(): Promise<void> {
    if (token === undefined) return
    setAction({ kind: 'backup' })
    setActionState({ kind: 'pending' })
    try {
      const result = await requestPlatformBackup(token)
      const operationId = readString((result as { operation_id?: unknown }).operation_id)
      const message = operationId
        ? screenText(locale, `تمت جدولة النسخة الاحتياطية ${operationId}.`, `Backup ${operationId} queued.`)
        : screenText(locale, 'تمت جدولة النسخة الاحتياطية.', 'Backup queued.')
      setActionState({ kind: 'success', message })
    } catch (error) {
      setActionState({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setAction(null)
    }
  }

  const servicesLabel =
    healthCheckCount !== null && healthHealthy !== null
      ? `${healthHealthy}/${healthCheckCount}`
      : screenText(locale, '—', '—')
  const backupLabel =
    backupStatus === 'healthy'
      ? screenText(locale, 'سليم', 'Healthy')
      : backupStatus === 'degraded'
      ? screenText(locale, 'متدهور', 'Degraded')
      : backupStatus === 'unhealthy'
      ? screenText(locale, 'فشل', 'Failed')
      : screenText(locale, '—', '—')
  const canRunBackup = isAllowed(allowedActions, 'platform_operations.backup.run') && token !== undefined
  const canRefreshHealth = isAllowed(allowedActions, 'platform_operations.health.read') && token !== undefined

  return (
    <div className="platform-screen">
      {stale ? (
        <DataFreshness
          state="stale"
          updatedAt={
            <OverviewFreshnessStamp
              locale={locale}
              updatedAt={readString(entity?.updated_at)}
            />
          }
          staleAfterMinutes={15}
        />
      ) : null}
      <Panel id="platform-overall" title={screenText(locale, 'الحالة العامة', 'Overall status')}>
        <StatusBadge variant={overallVariant}>{overallLabel}</StatusBadge>
        {issues.length > 0 ? (
          <ul className="platform-issue-list">
            {issues.map((issue) => (
              <li key={issue}>{issue}</li>
            ))}
          </ul>
        ) : null}
      </Panel>
      <Panel
        id="platform-source-attribution"
        title={screenText(locale, 'مصدر البيانات', 'Source attribution')}
      >
        <p>
          {isHealthDegraded
            ? screenText(
                locale,
                'مصدر الصحة غير متاح: فحص الصحة الفوري متدهور.',
                'Health source unavailable: live health check is degraded.',
              )
            : screenText(
                locale,
                'لا توجد إجراءات حرجة معلقة. راجع خدمة الطابور إن ظهرت.',
                'No critical action is pending. Review the queue service if it appears.',
              )}
        </p>
      </Panel>
      <PanelGrid className="platform-metrics">
        <MetricTile
          label={screenText(locale, 'الخدمات', 'Services')}
          value={servicesLabel}
          variant={healthHealthy !== null && healthHealthy === healthCheckCount ? 'ready' : 'stale'}
          source={screenText(locale, 'مصدر: فحص المنصة', 'Source: platform check')}
        />
        <MetricTile
          label={screenText(locale, 'آخر نسخة', 'Last backup')}
          value={backupLabel}
          variant={backupStatus === 'healthy' ? 'ready' : 'stale'}
          updatedAt={
            backupAt !== null ? <LastBackupStamp locale={locale} updatedAt={backupAt} /> : null
          }
        />
        <MetricTile
          label={screenText(locale, 'التنبيهات', 'Alerts')}
          value={alertCount !== null ? String(alertCount) : screenText(locale, '—', '—')}
          variant={alertCount !== null && alertCount > 0 ? 'stale' : 'empty'}
          period={screenText(locale, 'قائمة الانتظار الحالية', 'Current issues')}
        />
      </PanelGrid>
      <Panel id="platform-safe-actions" title={screenText(locale, 'إجراءات سريعة آمنة', 'Safe quick actions')}>
        <div className="platform-action-row">
          {canRefreshHealth ? (
            <Button
              variant="secondary"
              onClick={() => void refreshHealth()}
              disabled={action?.kind === 'health'}
            >
              {action?.kind === 'health'
                ? screenText(locale, 'جارٍ تحديث الفحص…', 'Refreshing check…')
                : screenText(locale, 'تحديث الفحص', 'Refresh check')}
            </Button>
          ) : null}
          {canRunBackup ? (
            <Button onClick={() => void runBackup()} disabled={action?.kind === 'backup'}>
              {action?.kind === 'backup'
                ? screenText(locale, 'جارٍ تشغيل النسخة…', 'Running backup…')
                : screenText(locale, 'تشغيل نسخة الآن', 'Run backup now')}
            </Button>
          ) : null}
        </div>
        {actionState.kind === 'success' ? <p role="status">{actionState.message}</p> : null}
        {actionState.kind === 'error' ? (
          <p role="alert" className="platform-error">
            {actionState.message}
          </p>
        ) : null}
      </Panel>
      <Panel id="platform-recent-activity" title={screenText(locale, 'النشاط الأخير', 'Recent activity')}>
        {issues.length === 0 ? (
          <p>{screenText(locale, 'لا توجد ملاحظات حالية.', 'No outstanding issues.')}</p>
        ) : (
          <ul className="platform-activity-list">
            {issues.map((issue) => (
              <li key={issue}>
                <ShieldAlert aria-hidden="true" />
                {issue}
              </li>
            ))}
          </ul>
        )}
      </Panel>
      <Panel id="platform-service-status" title={screenText(locale, 'حالة الخدمات', 'Service status')}>
        <ul className="platform-status-list">
          <li>
            <Activity aria-hidden="true" />
            <span>{screenText(locale, 'المنصة', 'Platform')}</span>
            <StatusBadge variant={overallVariant}>{overallLabel}</StatusBadge>
          </li>
          <li>
            <HardDrive aria-hidden="true" />
            <span>{screenText(locale, 'النسخ الاحتياطي', 'Backups')}</span>
            <StatusBadge variant={backupStatus === 'healthy' ? 'success' : 'warning'}>
              {backupLabel}
            </StatusBadge>
          </li>
          <li>
            <DatabaseBackup aria-hidden="true" />
            <span>
              <OverviewServiceStamp
                locale={locale}
                updatedAt={readString(entity?.updated_at)}
              />
            </span>
            <StatusBadge variant="neutral">
              {screenText(locale, 'طابع زمني', 'Timestamp')}
            </StatusBadge>
          </li>
        </ul>
      </Panel>
    </div>
  )
}

function OverviewServiceStamp({
  locale,
  updatedAt,
}: {
  locale: Locale
  updatedAt: string | undefined
}) {
  if (updatedAt === undefined) {
    return <>{screenText(locale, 'غير متاح', 'Unavailable')}</>
  }
  const formatted = new Intl.DateTimeFormat(formattingLocale(locale), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(updatedAt))
  return <time dateTime={updatedAt}>{formatted}</time>
}

function readString(value: unknown): string | undefined {
  return typeof value === 'string' ? value : undefined
}

function readChecks(resource: unknown): Array<{ status: string }> {
  if (resource === null || typeof resource !== 'object') return []
  const checks = (resource as { checks?: unknown }).checks
  if (!Array.isArray(checks)) return []
  return checks.filter((entry): entry is { status: string } => {
    return (
      typeof entry === 'object' &&
      entry !== null &&
      typeof (entry as { status?: unknown }).status === 'string'
    )
  })
}

function readIssues(resource: unknown): string[] {
  if (resource === null || typeof resource !== 'object') return []
  const issues = (resource as { issues?: unknown }).issues
  if (!Array.isArray(issues)) return []
  return issues
    .filter((entry): entry is { source?: string; code?: string } => {
      return typeof entry === 'object' && entry !== null
    })
    .map((entry) => `${entry.source ?? 'unknown'}: ${entry.code ?? 'unknown'}`)
}

function errorMessage(error: unknown, locale: 'ar' | 'en'): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title
  }
  return locale === 'ar'
    ? 'فشل تشغيل العملية.'
    : 'The operation could not be completed.'
}

function OverviewFreshnessStamp({
  locale,
  updatedAt,
}: {
  locale: Locale
  updatedAt: string | undefined
}) {
  if (updatedAt === undefined) return null
  const formatted = new Intl.DateTimeFormat(formattingLocale(locale), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(updatedAt))
  return (
    <>
      {screenText(locale, 'آخر تحديث:', 'Last updated:')}{' '}
      <time dateTime={updatedAt}>{formatted}</time>
    </>
  )
}

function LastBackupStamp({
  locale,
  updatedAt,
}: {
  locale: Locale
  updatedAt: string | null
}) {
  if (updatedAt === null) return null
  const formatted = new Intl.DateTimeFormat(formattingLocale(locale), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(updatedAt))
  return <time dateTime={updatedAt}>{formatted}</time>
}
