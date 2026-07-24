import { formattingLocale, type Locale } from '../../app/copy'

import { useState } from 'react'

import { ApiError } from '../../api'
import {
  confirmPlatformRestore,
  requestPlatformBackup,
  requestPlatformRestore,
} from '../../api/platform-settings'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../ui'
import {
  isAllowed,
  screenText,
  platformEntity,
  stateGate,
  type PlatformScreenProps,
} from './screen-support'

type BackupsNotice =
  | { kind: 'idle' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string }

export function BackupsScreen({
  locale,
  state = 'success',
  allowedActions,
  resource,
  token,
}: PlatformScreenProps & { token?: string }) {
  const entity = platformEntity(resource)
  const [restoreOpen, setRestoreOpen] = useState(false)
  const [restoreSubmitted, setRestoreSubmitted] = useState(false)
  const [restoreReason, setRestoreReason] = useState('')
  const [restoreBackupId, setRestoreBackupId] = useState('')
  const [restoreOperationId, setRestoreOperationId] = useState<string | null>(null)
  const [notice, setNotice] = useState<BackupsNotice>({ kind: 'idle' })
  const [busy, setBusy] = useState<'run' | 'restore' | 'confirm' | null>(null)
  const gate = stateGate(
    locale,
    state,
    screenText(
      locale,
      'لا توجد نسخ احتياطية متاحة',
      'No backup status is available',
    ),
  )
  if (gate) return gate
  const status = readString(entity?.status) ?? 'unknown'
  const lastSuccess = readString((entity as { last_successful_at?: unknown } | undefined)?.last_successful_at)
  const lastFailed = readString((entity as { last_failed_at?: unknown } | undefined)?.last_failed_at)
  const lastValidation = readString((entity as { last_validation_at?: unknown } | undefined)?.last_validation_at)
  const statusVariant: 'success' | 'warning' | 'danger' | 'neutral' =
    status === 'healthy'
      ? 'success'
      : status === 'degraded'
      ? 'warning'
      : status === 'unhealthy'
      ? 'danger'
      : 'neutral'
  const statusLabel = screenText(
    locale,
    status === 'healthy'
      ? 'سليم'
      : status === 'degraded'
      ? 'متدهور'
      : status === 'unhealthy'
      ? 'فشل'
      : 'غير معروف',
    status === 'healthy'
      ? 'Healthy'
      : status === 'degraded'
      ? 'Degraded'
      : status === 'unhealthy'
      ? 'Failed'
      : 'Unknown',
  )
  const canRun =
    isAllowed(allowedActions, 'platform_operations.backup.run') &&
    token !== undefined &&
    busy === null
  const canRequest =
    isAllowed(allowedActions, 'platform_operations.restore.request') &&
    token !== undefined &&
    busy === null
  const canConfirm =
    isAllowed(allowedActions, 'platform_operations.restore.confirm') &&
    token !== undefined &&
    busy === null

  async function runBackup(): Promise<void> {
    if (token === undefined) return
    setBusy('run')
    setNotice({ kind: 'idle' })
    try {
      const result = await requestPlatformBackup(token)
      const operationId = readString((result as { operation_id?: unknown }).operation_id)
      const message = operationId
        ? screenText(locale, `تم تشغيل النسخة الاحتياطية ${operationId}.`, `Backup ${operationId} queued.`)
        : screenText(locale, 'تم تشغيل النسخة الاحتياطية.', 'Backup queued.')
      setNotice({ kind: 'success', message })
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function submitRestore(): Promise<void> {
    if (token === undefined) return
    setBusy('restore')
    setNotice({ kind: 'idle' })
    try {
      const result = await requestPlatformRestore(token, {
        backup_id: restoreBackupId,
        reason: restoreReason,
      })
      const operationId = readString((result as { operation_id?: unknown }).operation_id)
      setRestoreOperationId(operationId ?? null)
      setRestoreSubmitted(true)
      setRestoreOpen(false)
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function confirmRestore(): Promise<void> {
    if (token === undefined || restoreOperationId === null) return
    setBusy('confirm')
    setNotice({ kind: 'idle' })
    try {
      await confirmPlatformRestore(token, restoreOperationId)
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم تأكيد الاستعادة.', 'Restore confirmed.'),
      })
      setRestoreOperationId(null)
      setRestoreSubmitted(false)
      setRestoreBackupId('')
      setRestoreReason('')
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="platform-screen">
      <Panel
        id="backup-status"
        title={screenText(locale, 'حالة النسخ', 'Backup status')}
      >
        <dl className="platform-definition-list">
          <div>
            <dt>{screenText(locale, 'الحالة', 'Status')}</dt>
            <dd>
              <StatusBadge variant={statusVariant}>{statusLabel}</StatusBadge>
            </dd>
          </div>
          <div>
            <dt>{screenText(locale, 'آخر نجاح', 'Last success')}</dt>
            <dd>
              <LastSuccessStamp
                locale={locale}
                updatedAt={lastSuccess}
                fallback={screenText(locale, 'لا يوجد', 'None')}
              />
            </dd>
          </div>
          <div>
            <dt>{screenText(locale, 'آخر فشل', 'Last failure')}</dt>
            <dd>
              <LastSuccessStamp
                locale={locale}
                updatedAt={lastFailed}
                fallback={screenText(locale, 'لا يوجد', 'None')}
              />
            </dd>
          </div>
          <div>
            <dt>{screenText(locale, 'آخر تحقق', 'Last validation')}</dt>
            <dd>
              <LastSuccessStamp
                locale={locale}
                updatedAt={lastValidation}
                fallback={screenText(locale, 'لا يوجد', 'None')}
              />
            </dd>
          </div>
        </dl>
      </Panel>
      <div className="platform-action-row">
        {canRun ? (
          <Button onClick={() => void runBackup()} disabled={busy !== null}>
            {screenText(locale, 'تشغيل نسخة الآن', 'Run backup now')}
          </Button>
        ) : null}
        {canRequest ? (
          <Button
            variant="secondary"
            onClick={() => {
              setRestoreSubmitted(false)
              setRestoreOpen(true)
            }}
            disabled={busy !== null}
          >
            {screenText(locale, 'طلب استعادة', 'Request restore')}
          </Button>
        ) : null}
      </div>
      {notice.kind === 'success' ? <p role="status">{notice.message}</p> : null}
      {notice.kind === 'error' ? (
        <p role="alert" className="platform-error">
          {notice.message}
        </p>
      ) : null}
      {restoreSubmitted && restoreOperationId !== null && canConfirm ? (
        <div className="platform-action-row">
          <Button onClick={() => void confirmRestore()} disabled={busy !== null}>
            {screenText(locale, 'تأكيد الاستعادة', 'Confirm restore')}
          </Button>
        </div>
      ) : null}
      <Drawer
        open={restoreOpen}
        onClose={() => setRestoreOpen(false)}
        title={screenText(locale, 'طلب استعادة', 'Restore request')}
      >
        <p>
          {screenText(
            locale,
            'يتطلب طلب الاستعادة معرّف النسخة وسببًا منفصلًا.',
            'A restore request requires the backup id and a separate reason.',
          )}
        </p>
        <Field id="restore-backup-id" label={screenText(locale, 'معرّف النسخة', 'Backup id')}>
          <input
            id="restore-backup-id"
            value={restoreBackupId}
            onChange={(event) => setRestoreBackupId(event.target.value)}
            className="platform-input"
          />
        </Field>
        <Field id="restore-reason" label={screenText(locale, 'السبب', 'Reason')}>
          <textarea
            id="restore-reason"
            value={restoreReason}
            onChange={(event) => setRestoreReason(event.target.value)}
            className="platform-input"
            rows={3}
          />
        </Field>
        <div className="platform-action-row">
          <Button
            onClick={() => void submitRestore()}
            disabled={busy !== null || restoreBackupId === '' || restoreReason === ''}
          >
            {screenText(locale, 'إرسال الطلب', 'Submit request')}
          </Button>
          <Button variant="quiet" onClick={() => setRestoreOpen(false)} disabled={busy !== null}>
            {screenText(locale, 'إلغاء', 'Cancel')}
          </Button>
        </div>
      </Drawer>
    </div>
  )
}

function readString(value: unknown): string | undefined {
  return typeof value === 'string' ? value : undefined
}

function errorMessage(error: unknown, locale: 'ar' | 'en'): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title
  }
  return locale === 'ar'
    ? 'فشل تشغيل العملية.'
    : 'The operation could not be completed.'
}

function LastSuccessStamp({
  locale,
  updatedAt,
  fallback,
}: {
  locale: Locale
  updatedAt?: string | undefined
  fallback: string
}) {
  if (updatedAt === undefined) {
    return <>{fallback}</>
  }
  const formatted = new Intl.DateTimeFormat(formattingLocale(locale), {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(updatedAt))
  return <time dateTime={updatedAt}>{formatted}</time>
}
