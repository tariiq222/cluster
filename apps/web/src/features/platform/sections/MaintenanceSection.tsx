import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { cancelPlatformMaintenanceWindow, listPlatformMaintenanceWindows, schedulePlatformMaintenanceWindow } from '../platform-api'
import { maintenanceCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, isEmptyCollection, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformMaintenanceWindow, PlatformMaintenanceWindowList } from '../platform-types'

function statusVariant(status: string): 'success' | 'warning' | 'neutral' | 'info' {
  if (status === 'active') return 'warning'
  if (status === 'cancelled') return 'neutral'
  if (status === 'ended') return 'info'
  return 'success'
}

function statusText(status: string, locale: 'ar' | 'en'): string {
  switch (status) {
    case 'scheduled': return t(maintenanceCopy.scheduled, locale)
    case 'active': return t(maintenanceCopy.active, locale)
    case 'cancelled': return t(maintenanceCopy.cancelled, locale)
    case 'ended': return t(maintenanceCopy.ended, locale)
    default: return status
  }
}

function toUtc(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toISOString()
}

export function MaintenanceSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [messageAr, setMessageAr] = useState('')
  const [messageEn, setMessageEn] = useState('')

  const windowsQuery = useQuery({
    queryKey: ['platform-maintenance'],
    queryFn: () => listPlatformMaintenanceWindows(csrfToken),
  })

  const data: PlatformMaintenanceWindowList | null = windowsQuery.data ?? null

  let state: SectionState = 'loading'
  if (!windowsQuery.isPending) {
    const error = windowsQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else state = isEmptyCollection(data?.items) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void windowsQuery.refetch()
  }, [windowsQuery])

  const canManage = data !== null && data.items.some((window) =>
    actionAllowed(window.allowed_actions, 'platform_operations.maintenance.manage'),
  )

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const scheduleMutation = useMutation({
    mutationFn: () => schedulePlatformMaintenanceWindow(csrfToken, {
      starts_at: toUtc(startsAt),
      ends_at: endsAt === '' ? null : toUtc(endsAt),
      message_ar: messageAr.trim(),
      message_en: messageEn.trim(),
    }),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setScheduleOpen(false)
      setStartsAt('')
      setEndsAt('')
      setMessageAr('')
      setMessageEn('')
      setActionNotice(t(platformCopy.refreshed, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-maintenance'] })
    },
    onError: fail,
  })

  const submitSchedule = useCallback(() => {
    scheduleMutation.mutate()
  }, [scheduleMutation])

  const cancelMutation = useMutation({
    mutationFn: (window: PlatformMaintenanceWindow) => cancelPlatformMaintenanceWindow(csrfToken, window.id, window.lock_version),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(maintenanceCopy.cancelled, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-maintenance'] })
    },
    onError: fail,
  })

  const cancelWindow = useCallback((window: PlatformMaintenanceWindow) => {
    cancelMutation.mutate(window)
  }, [cancelMutation])

  const actionBusy = scheduleMutation.isPending || cancelMutation.isPending

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} emptyTitle={t(maintenanceCopy.empty, locale)} onRetry={reload} />
  }

  return (
    <>
      <Panel
        id="platform-maintenance"
        title={t(maintenanceCopy.title, locale)}
        actions={canManage ? (
          <Button variant="secondary" disabled={actionBusy} onClick={() => setScheduleOpen(true)}>
            {t(maintenanceCopy.schedule, locale)}
          </Button>
        ) : undefined}
      >
        {data.items.length === 0 ? (
          <p className="screen-list__row-meta">{t(maintenanceCopy.empty, locale)}</p>
        ) : (
          <table className="entity-table">
            <thead>
              <tr>
                <th scope="col">{t(maintenanceCopy.window, locale)}</th>
                <th scope="col">{t(maintenanceCopy.startsAt, locale)}</th>
                <th scope="col">{t(maintenanceCopy.endsAt, locale)}</th>
                <th scope="col">{t(platformCopy.actions, locale)}</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((window) => (
                <tr key={window.id}>
                  <td>
                    <p className="screen-list__row-title">{locale === 'ar' ? window.message_ar : window.message_en}</p>
                    <StatusBadge variant={statusVariant(window.status)}>{statusText(window.status, locale)}</StatusBadge>
                  </td>
                  <td>{formatDate(window.starts_at, locale)}</td>
                  <td>{window.ends_at ? formatDate(window.ends_at, locale) : '—'}</td>
                  <td>
                    {(window.status === 'scheduled' || window.status === 'active') &&
                      actionAllowed(window.allowed_actions, 'platform_operations.maintenance.cancel') && (
                        <Button variant="danger" disabled={actionBusy} onClick={() => void cancelWindow(window)}>
                          {t(maintenanceCopy.cancel, locale)}
                        </Button>
                      )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Drawer open={scheduleOpen} onClose={() => setScheduleOpen(false)} title={t(maintenanceCopy.schedule, locale)}>
        <Field id="platform-maintenance-starts" label={t(maintenanceCopy.startsAt, locale)} required>
          <input
            id="platform-maintenance-starts"
            className="field__control"
            type="datetime-local"
            value={startsAt}
            onChange={(event) => setStartsAt(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-maintenance-ends" label={t(maintenanceCopy.endsAt, locale)}>
          <input
            id="platform-maintenance-ends"
            className="field__control"
            type="datetime-local"
            value={endsAt}
            onChange={(event) => setEndsAt(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-maintenance-message-ar" label={t(maintenanceCopy.messageAr, locale)} required>
          <input
            id="platform-maintenance-message-ar"
            className="field__control"
            maxLength={1024}
            value={messageAr}
            onChange={(event) => setMessageAr(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-maintenance-message-en" label={t(maintenanceCopy.messageEn, locale)} required>
          <input
            id="platform-maintenance-message-en"
            className="field__control"
            maxLength={1024}
            value={messageEn}
            onChange={(event) => setMessageEn(event.currentTarget.value)}
          />
        </Field>
        <div className="form-actions">
          <Button variant="quiet" onClick={() => setScheduleOpen(false)}>{t(platformCopy.cancel, locale)}</Button>
          <Button
            variant="primary"
            disabled={actionBusy || startsAt === '' || messageAr.trim() === '' || messageEn.trim() === ''}
            onClick={() => void submitSchedule()}
          >
            {t(maintenanceCopy.schedule, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
