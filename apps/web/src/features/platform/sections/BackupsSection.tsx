import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { confirmPlatformRestore, dispatchPlatformBackup, getPlatformBackups, requestPlatformRestore } from '../platform-api'
import { backupsCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformBackupReport } from '../platform-types'

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'ok' || status === 'healthy' || status === 'verified') return 'success'
  if (status === 'failed' || status === 'critical') return 'danger'
  if (status === 'running' || status === 'pending') return 'warning'
  return 'neutral'
}

export function BackupsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const { capabilities } = usePrincipal()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [restoreOpen, setRestoreOpen] = useState(false)
  const [backupId, setBackupId] = useState('')
  const [reason, setReason] = useState('')
  const [pendingRestoreId, setPendingRestoreId] = useState<string | null>(null)

  const backupsQuery = useQuery({
    queryKey: ['platform-backups'],
    queryFn: () => getPlatformBackups(csrfToken),
  })

  const data: PlatformBackupReport | null = backupsQuery.data ?? null

  let state: SectionState = 'loading'
  if (!backupsQuery.isPending) {
    const error = backupsQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else state = data === null ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void backupsQuery.refetch()
  }, [backupsQuery])

  const canRunBackup = actionAllowed(data?.allowed_actions, 'platform_operations.backup.run')
  const canRequestRestore = actionAllowed(data?.allowed_actions, 'platform_operations.restore.request')
  const canConfirmRestore = capabilities?.includes('platform_operations.restore.confirm') === true

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const runBackupMutation = useMutation({
    mutationFn: () => dispatchPlatformBackup(csrfToken),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(backupsCopy.requested, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-backups'] })
    },
    onError: fail,
  })

  const runBackup = useCallback(() => {
    runBackupMutation.mutate()
  }, [runBackupMutation])

  const submitRestoreMutation = useMutation({
    mutationFn: () => requestPlatformRestore(csrfToken, { backup_id: backupId.trim(), reason: reason.trim() }),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: (result) => {
      setPendingRestoreId(result.operation_id)
      setRestoreOpen(false)
      setActionNotice(t(backupsCopy.restoreSubmitted, locale))
    },
    onError: fail,
  })

  const submitRestore = useCallback(() => {
    submitRestoreMutation.mutate()
  }, [submitRestoreMutation])

  const confirmRestoreMutation = useMutation({
    mutationFn: () => {
      if (pendingRestoreId === null) throw new Error('No pending restore request')
      return confirmPlatformRestore(csrfToken, pendingRestoreId)
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setPendingRestoreId(null)
      setActionNotice(t(backupsCopy.confirmed, locale))
    },
    onError: fail,
  })

  const confirmRestore = useCallback(() => {
    if (pendingRestoreId === null) return
    confirmRestoreMutation.mutate()
  }, [confirmRestoreMutation, pendingRestoreId])

  const actionBusy =
    runBackupMutation.isPending || submitRestoreMutation.isPending || confirmRestoreMutation.isPending

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} emptyTitle={t(backupsCopy.empty, locale)} onRetry={reload} />
  }

  return (
    <>
      <Panel
        id="platform-backups"
        title={t(backupsCopy.status, locale)}
        actions={canRunBackup ? (
          <Button variant="secondary" disabled={actionBusy} onClick={() => void runBackup()}>
            {t(backupsCopy.runBackup, locale)}
          </Button>
        ) : undefined}
      >
        <div className="metric-grid">
          <div className="metric-tile">
            <span className="metric-tile__label">{t(backupsCopy.status, locale)}</span>
            <span className="metric-tile__value">
              <StatusBadge variant={statusVariant(data.status)}>{data.status}</StatusBadge>
            </span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(backupsCopy.lastSuccessful, locale)}</span>
            <span className="metric-tile__value">{formatDate(data.last_successful_at, locale) || '—'}</span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(backupsCopy.lastFailed, locale)}</span>
            <span className="metric-tile__value">{formatDate(data.last_failed_at, locale) || '—'}</span>
          </div>
          <div className="metric-tile">
            <span className="metric-tile__label">{t(backupsCopy.lastValidation, locale)}</span>
            <span className="metric-tile__value">{formatDate(data.last_validation_at, locale) || '—'}</span>
          </div>
        </div>
        <div className="form-actions">
          {canRequestRestore && (
            <Button variant="secondary" disabled={actionBusy} onClick={() => setRestoreOpen(true)}>
              {t(backupsCopy.restore, locale)}
            </Button>
          )}
        </div>
        {pendingRestoreId !== null && canConfirmRestore && (
          <div className="status-message status-message--success" role="status">
            <p>{t(backupsCopy.confirmRestore, locale)} ({pendingRestoreId})</p>
            <p className="screen-list__row-meta">{t(backupsCopy.confirmHint, locale)}</p>
            <Button variant="primary" disabled={actionBusy} onClick={() => void confirmRestore()}>
              {t(backupsCopy.confirmRestore, locale)}
            </Button>
          </div>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Drawer open={restoreOpen} onClose={() => setRestoreOpen(false)} title={t(backupsCopy.restore, locale)}>
        <Field id="platform-restore-backup-id" label={t(backupsCopy.backupId, locale)} required>
          <input
            id="platform-restore-backup-id"
            className="field__control"
            value={backupId}
            onChange={(event) => setBackupId(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-restore-reason" label={t(backupsCopy.reason, locale)} required help={t(backupsCopy.reasonHelp, locale)}>
          <textarea
            id="platform-restore-reason"
            className="field__control"
            rows={4}
            value={reason}
            onChange={(event) => setReason(event.currentTarget.value)}
          />
        </Field>
        <div className="form-actions">
          <Button variant="quiet" onClick={() => setRestoreOpen(false)}>{t(platformCopy.cancel, locale)}</Button>
          <Button
            variant="primary"
            disabled={actionBusy || backupId.trim() === '' || reason.trim().length < 10}
            onClick={() => void submitRestore()}
          >
            {t(backupsCopy.restore, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
