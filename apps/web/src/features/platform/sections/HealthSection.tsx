import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePlatformHealth } from '../../../api/hooks'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { listPlatformAlertPolicies, updatePlatformAlertPolicy } from '../platform-api'
import { healthCopy, logsCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, isEmptyCollection, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformAlertPolicy, PlatformAlertPolicyList, PlatformHealth } from '../platform-types'

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'ok' || status === 'healthy' || status === 'active' || status === 'enabled') return 'success'
  if (status === 'degraded' || status === 'warning') return 'warning'
  if (status === 'critical' || status === 'unhealthy' || status === 'down' || status === 'disabled') return 'danger'
  return 'neutral'
}

interface HealthPayload {
  health: PlatformHealth
  policies: PlatformAlertPolicyList
}

function isHealthPayloadEmpty(payload: HealthPayload): boolean {
  return payload.health.checks.length === 0 && isEmptyCollection(payload.policies.items)
}

function severityLabel(severity: string, locale: 'ar' | 'en'): string {
  const labels = logsCopy.severities as Record<string, { ar: string; en: string }>
  const entry = labels[severity]
  return entry !== undefined ? t(entry, locale) : severity
}

export function HealthSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [editing, setEditing] = useState<PlatformAlertPolicy | null>(null)
  const [editStatus, setEditStatus] = useState('')
  const [editSeverity, setEditSeverity] = useState('')
  const [editChannel, setEditChannel] = useState('')

  const healthQuery = usePlatformHealth()
  const policiesQuery = useQuery({
    queryKey: ['platform-alert-policies'],
    queryFn: () => listPlatformAlertPolicies(csrfToken),
  })

  const health = healthQuery.data as unknown as PlatformHealth | undefined
  const policies = policiesQuery.data ?? null

  const data: HealthPayload | null =
    health !== undefined && policies !== null ? { health, policies } : null

  let state: SectionState = 'loading'
  if (!healthQuery.isPending && !policiesQuery.isPending) {
    const error = healthQuery.error ?? policiesQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else if (data === null) state = 'empty'
    else state = isHealthPayloadEmpty(data) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void healthQuery.refetch()
    void policiesQuery.refetch()
  }, [healthQuery, policiesQuery])

  const canManageAlerts = data !== null && data.policies.items.some((policy) =>
    actionAllowed(policy.allowed_actions, 'platform_operations.alerts.manage'),
  )

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const openEdit = useCallback((policy: PlatformAlertPolicy) => {
    setEditing(policy)
    setEditStatus(policy.status)
    setEditSeverity(policy.severity)
    setEditChannel(policy.channel)
  }, [])

  const saveMutation = useMutation({
    mutationFn: () => {
      if (editing === null) throw new Error('No alert policy selected')
      return updatePlatformAlertPolicy(
        csrfToken,
        editing.id,
        { status: editStatus, severity: editSeverity, channel: editChannel },
        editing.lock_version,
      )
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setEditing(null)
      setActionNotice(t(platformCopy.refreshed, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-alert-policies'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-health'] })
    },
    onError: fail,
  })

  const saveEdit = useCallback(() => {
    if (editing === null) return
    saveMutation.mutate()
  }, [editing, saveMutation])

  const severityOptions = useMemo(
    () => ['critical', 'warning', 'info'].map((value) => ({ value, label: severityLabel(value, locale) })),
    [locale],
  )

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { health: healthData, policies: policiesData } = data

  return (
    <>
      <div className="detail-grid">
        <Panel id="platform-health-checks" title={t(healthCopy.checks, locale)}>
          {healthData.checks.length === 0 ? (
            <p className="screen-list__row-meta">{t(platformCopy.empty, locale)}</p>
          ) : (
            <table className="entity-table">
              <thead>
                <tr>
                  <th scope="col">{t(healthCopy.code, locale)}</th>
                  <th scope="col">{t(healthCopy.status, locale)}</th>
                  <th scope="col">{t(healthCopy.latency, locale)}</th>
                  <th scope="col">{t(healthCopy.checkedAt, locale)}</th>
                </tr>
              </thead>
              <tbody>
                {healthData.checks.map((check) => (
                  <tr key={check.code}>
                    <td>{check.code}</td>
                    <td>
                      <StatusBadge variant={statusVariant(check.status)}>{check.status}</StatusBadge>
                    </td>
                    <td>{check.latency_ms} ms</td>
                    <td>{formatDate(check.checked_at, locale)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>

        <Panel id="platform-alert-policies" title={t(healthCopy.alertPolicies, locale)}>
          {policiesData.items.length === 0 ? (
            <p className="screen-list__row-meta">{t(healthCopy.empty, locale)}</p>
          ) : (
            <table className="entity-table">
              <thead>
                <tr>
                  <th scope="col">{t(healthCopy.code, locale)}</th>
                  <th scope="col">{t(healthCopy.status, locale)}</th>
                  <th scope="col">{t(healthCopy.severity, locale)}</th>
                  <th scope="col">{t(healthCopy.channel, locale)}</th>
                  <th scope="col">{t(platformCopy.actions, locale)}</th>
                </tr>
              </thead>
              <tbody>
                {policiesData.items.map((policy) => (
                  <tr key={policy.id}>
                    <td>{policy.code}</td>
                    <td>
                      <StatusBadge variant={statusVariant(policy.status)}>{policy.status}</StatusBadge>
                    </td>
                    <td>{severityLabel(policy.severity, locale)}</td>
                    <td>{policy.channel}</td>
                    <td>
                      {canManageAlerts && (
                        <Button variant="quiet" disabled={saveMutation.isPending} onClick={() => openEdit(policy)}>
                          {t(healthCopy.edit, locale)}
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      </div>
      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      <Drawer
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={`${t(healthCopy.alertPolicies, locale)} · ${editing?.code ?? ''}`}
      >
        <Field id="platform-alert-policy-status" label={t(healthCopy.status, locale)}>
          <input
            id="platform-alert-policy-status"
            className="field__control"
            maxLength={64}
            value={editStatus}
            onChange={(event) => setEditStatus(event.currentTarget.value)}
          />
        </Field>
        <Field id="platform-alert-policy-severity" label={t(healthCopy.severity, locale)}>
          <select
            id="platform-alert-policy-severity"
            className="field__control"
            value={editSeverity}
            onChange={(event) => setEditSeverity(event.currentTarget.value)}
          >
            {severityOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </Field>
        <Field id="platform-alert-policy-channel" label={t(healthCopy.channel, locale)}>
          <input
            id="platform-alert-policy-channel"
            className="field__control"
            maxLength={64}
            value={editChannel}
            onChange={(event) => setEditChannel(event.currentTarget.value)}
          />
        </Field>
        <div className="form-actions">
          <Button variant="quiet" onClick={() => setEditing(null)}>{t(platformCopy.cancel, locale)}</Button>
          <Button variant="primary" disabled={saveMutation.isPending} onClick={() => void saveEdit()}>
            {t(platformCopy.save, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
