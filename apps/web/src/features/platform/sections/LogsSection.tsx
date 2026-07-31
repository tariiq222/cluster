import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { listPlatformTechnicalLogs, requestPlatformTechnicalLogsRestore } from '../platform-api'
import { logsCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, isEmptyCollection, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformTechnicalLogEntry, PlatformTechnicalLogList } from '../platform-types'

function severityVariant(severity: string): 'danger' | 'warning' | 'info' | 'neutral' {
  if (severity === 'critical' || severity === 'error') return 'danger'
  if (severity === 'warning') return 'warning'
  if (severity === 'info') return 'info'
  return 'neutral'
}

function severityLabel(severity: string, locale: 'ar' | 'en'): string {
  const labels = logsCopy.severities as Record<string, { ar: string; en: string }>
  const entry = labels[severity]
  return entry !== undefined ? t(entry, locale) : severity
}

export function LogsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [severityFilter, setSeverityFilter] = useState('')
  const [restoreOpen, setRestoreOpen] = useState(false)
  const [manifestId, setManifestId] = useState('')
  const [reason, setReason] = useState('')

  const logsQuery = useQuery({
    queryKey: ['platform-logs'],
    queryFn: () => listPlatformTechnicalLogs(csrfToken, { per_page: 50 }),
  })

  const data: PlatformTechnicalLogList | null = logsQuery.data ?? null

  let state: SectionState = 'loading'
  if (!logsQuery.isPending) {
    const error = logsQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else state = isEmptyCollection(data?.items) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void logsQuery.refetch()
  }, [logsQuery])

  const canRestore = actionAllowed(data?.allowed_actions, 'platform_operations.logs.restore')

  const severityOptions = useMemo(() => {
    const seen = new Set<string>()
    for (const entry of data?.items ?? []) seen.add(entry.severity)
    return Array.from(seen).map((severity) => ({ value: severity, label: severityLabel(severity, locale) }))
  }, [data, locale])

  const filtered = useMemo(() => {
    if (severityFilter === '') return data?.items ?? []
    return (data?.items ?? []).filter((entry) => entry.severity === severityFilter)
  }, [data, severityFilter])

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const submitRestoreMutation = useMutation({
    mutationFn: () => requestPlatformTechnicalLogsRestore(csrfToken, { manifest_id: manifestId.trim(), reason: reason.trim() }),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setRestoreOpen(false)
      setManifestId('')
      setReason('')
      setActionNotice(t(logsCopy.restored, locale))
    },
    onError: fail,
  })

  const submitRestore = useCallback(() => {
    submitRestoreMutation.mutate()
  }, [submitRestoreMutation])

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} emptyTitle={t(logsCopy.empty, locale)} onRetry={reload} />
  }

  return (
    <>
      <Panel
        id="platform-logs"
        title={t(logsCopy.title, locale)}
        actions={canRestore ? (
          <Button variant="secondary" disabled={submitRestoreMutation.isPending} onClick={() => setRestoreOpen(true)}>
            {t(logsCopy.restore, locale)}
          </Button>
        ) : undefined}
      >
        <div className="filter-bar">
          <Field id="platform-logs-severity" label={t(logsCopy.severity, locale)}>
            <Select
              id="platform-logs-severity"
              value={severityFilter}
              onChange={setSeverityFilter}
              options={severityOptions}
              placeholder={t(logsCopy.allSeverities, locale)}
            />
          </Field>
        </div>
        {filtered.length === 0 ? (
          <p className="screen-list__row-meta">{t(logsCopy.empty, locale)}</p>
        ) : (
          <table className="entity-table">
            <thead>
              <tr>
                <th scope="col">{t(logsCopy.severity, locale)}</th>
                <th scope="col">{t(logsCopy.source, locale)}</th>
                <th scope="col">{t(logsCopy.category, locale)}</th>
                <th scope="col">{t(logsCopy.message, locale)}</th>
                <th scope="col">{t(logsCopy.occurredAt, locale)}</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((entry: PlatformTechnicalLogEntry) => (
                <tr key={entry.id}>
                  <td>
                    <StatusBadge variant={severityVariant(entry.severity)}>{severityLabel(entry.severity, locale)}</StatusBadge>
                  </td>
                  <td>{entry.source}</td>
                  <td>{entry.category ?? '—'}</td>
                  <td>{locale === 'ar' ? entry.message_ar : entry.message_en}</td>
                  <td>{formatDate(entry.occurred_at, locale)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Drawer open={restoreOpen} onClose={() => setRestoreOpen(false)} title={t(logsCopy.restore, locale)}>
        <Field id="platform-logs-restore-manifest" label={t(logsCopy.manifestId, locale)} required>
          <input
            id="platform-logs-restore-manifest"
            className="field__control"
            value={manifestId}
            onChange={(event) => setManifestId(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-logs-restore-reason" label={t(logsCopy.reason, locale)} required>
          <textarea
            id="platform-logs-restore-reason"
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
            disabled={submitRestoreMutation.isPending || manifestId.trim() === '' || reason.trim() === ''}
            onClick={() => void submitRestore()}
          >
            {t(logsCopy.restore, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
