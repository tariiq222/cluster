import { useCallback, useMemo, useState } from 'react'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../../ui'
import { formatDate } from '../../../i18n'
import { ApiError } from '../../../api/http'
import { getPlatformHealth, listPlatformAlertPolicies, updatePlatformAlertPolicy } from '../platform-api'
import { healthCopy, logsCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, isEmptyCollection, useSectionLoad } from '../section-support'
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
  const { scopeEpoch } = usePrincipal()
  const [actionBusy, setActionBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [editing, setEditing] = useState<PlatformAlertPolicy | null>(null)
  const [editStatus, setEditStatus] = useState('')
  const [editSeverity, setEditSeverity] = useState('')
  const [editChannel, setEditChannel] = useState('')

  const fetcher = useCallback(async (): Promise<HealthPayload> => {
    const [health, policies] = await Promise.all([
      getPlatformHealth(csrfToken),
      listPlatformAlertPolicies(csrfToken),
    ])
    return { health, policies }
  }, [csrfToken])

  const { state, data, reload } = useSectionLoad(fetcher, isHealthPayloadEmpty, scopeEpoch)

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

  const saveEdit = useCallback(async () => {
    if (editing === null) return
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await updatePlatformAlertPolicy(
        csrfToken,
        editing.id,
        { status: editStatus, severity: editSeverity, channel: editChannel },
        editing.lock_version,
      )
      setEditing(null)
      setActionNotice(t(platformCopy.refreshed, locale))
      reload()
    } catch (error) {
      fail(error)
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, editChannel, editSeverity, editStatus, editing, fail, locale, reload])

  const severityOptions = useMemo(
    () => ['critical', 'warning', 'info'].map((value) => ({ value, label: severityLabel(value, locale) })),
    [locale],
  )

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { health, policies } = data

  return (
    <>
      <div className="detail-grid">
        <Panel id="platform-health-checks" title={t(healthCopy.checks, locale)}>
          {health.checks.length === 0 ? (
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
                {health.checks.map((check) => (
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
          {policies.items.length === 0 ? (
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
                {policies.items.map((policy) => (
                  <tr key={policy.id}>
                    <td>{policy.code}</td>
                    <td>
                      <StatusBadge variant={statusVariant(policy.status)}>{policy.status}</StatusBadge>
                    </td>
                    <td>{severityLabel(policy.severity, locale)}</td>
                    <td>{policy.channel}</td>
                    <td>
                      {canManageAlerts && (
                        <Button variant="quiet" disabled={actionBusy} onClick={() => openEdit(policy)}>
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
          <Button variant="primary" disabled={actionBusy} onClick={() => void saveEdit()}>
            {t(platformCopy.save, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
