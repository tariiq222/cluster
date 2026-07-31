import { useCallback, useMemo, useState } from 'react'
import { usePrincipal } from '../../../app/principal-context'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, PanelGrid, StatusBadge } from '../../../ui'
import { ApiError } from '../../../api/http'
import {
  createPlatformSettingsDraft,
  getCurrentPlatformSettings,
  listPlatformSettingsVersions,
  publishPlatformSettingsVersion,
  setPlatformSetting,
  validatePlatformSettingsVersion,
} from '../platform-api'
import { platformCopy, securityCopy, t } from '../platform-copy'
import { actionAllowed, useSectionLoad } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformSettingsVersion, PlatformSecurityPolicy } from '../platform-types'

interface SecurityPayload {
  current: PlatformSettingsVersion
  versions: PlatformSettingsVersion[]
}

const SECURITY_KEYS = [
  'idle_timeout_minutes',
  'absolute_session_hours',
  'minimum_password_length',
  'password_history_count',
  'failed_login_attempts',
  'failed_login_window_minutes',
  'lockout_minutes',
] as const

function isSecurityPayloadEmpty(payload: SecurityPayload): boolean {
  return payload.current === null && payload.versions.length === 0
}

function securityPolicyOf(version: PlatformSettingsVersion | null | undefined): PlatformSecurityPolicy {
  return version?.security ?? {}
}

function editableVersion(versions: readonly PlatformSettingsVersion[]): PlatformSettingsVersion | null {
  return versions.find((version) => version.status === 'draft' || version.status === 'validated') ?? null
}

function statusVariant(status: string | undefined): 'neutral' | 'success' | 'warning' | 'info' {
  switch (status) {
    case 'published': return 'success'
    case 'validated': return 'info'
    case 'draft': return 'warning'
    default: return 'neutral'
  }
}

function securityLabel(key: string, locale: 'ar' | 'en'): string {
  switch (key) {
    case 'idle_timeout_minutes': return t(securityCopy.idleTimeoutMinutes, locale)
    case 'absolute_session_hours': return t(securityCopy.absoluteSessionHours, locale)
    case 'minimum_password_length': return t(securityCopy.minimumPasswordLength, locale)
    case 'password_history_count': return t(securityCopy.passwordHistoryCount, locale)
    case 'failed_login_attempts': return t(securityCopy.failedLoginAttempts, locale)
    case 'failed_login_window_minutes': return t(securityCopy.failedLoginWindowMinutes, locale)
    case 'lockout_minutes': return t(securityCopy.lockoutMinutes, locale)
    default: return key
  }
}

export function SecuritySettingsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const { scopeEpoch } = usePrincipal()
  const [actionBusy, setActionBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [editKey, setEditKey] = useState<string | null>(null)
  const [editValue, setEditValue] = useState('')

  const fetcher = useCallback(async (): Promise<SecurityPayload> => {
    const [current, versionsList] = await Promise.all([
      getCurrentPlatformSettings(csrfToken),
      listPlatformSettingsVersions(csrfToken),
    ])
    return { current, versions: versionsList.items }
  }, [csrfToken])

  const { state, data, reload } = useSectionLoad(fetcher, isSecurityPayloadEmpty, scopeEpoch)

  const editable = useMemo(
    () => (data === null ? null : editableVersion(data.versions)),
    [data],
  )

  const actionKeys = data?.current.allowed_actions ?? data?.versions.flatMap((version) => version.allowed_actions ?? []) ?? []
  const canManage = actionAllowed(actionKeys, 'platform_settings.manage')
  const canPublish = actionAllowed(actionKeys, 'platform_settings.publish') || canManage

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setActionNotice(t(securityCopy.stale, locale))
      reload()
      return
    }
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale, reload])

  const createDraft = useCallback(async () => {
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await createPlatformSettingsDraft(csrfToken)
      setActionNotice(t(securityCopy.draft, locale))
      reload()
    } catch (error) {
      fail(error)
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, fail, locale, reload])

  const openEdit = useCallback((key: string, value: number | undefined) => {
    setEditKey(key)
    setEditValue(value === undefined ? '' : String(value))
  }, [])

  const saveEdit = useCallback(async () => {
    if (editKey === null || editable === null || editable.id === undefined || editable.lock_version === undefined) return
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await setPlatformSetting(
        csrfToken,
        editable.id,
        `security.${editKey}`,
        { value_type: 'integer', value: Number(editValue) },
        editable.lock_version,
      )
      setEditKey(null)
      setActionNotice(t(platformCopy.refreshed, locale))
      reload()
    } catch (error) {
      fail(error)
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, editKey, editValue, editable, fail, locale, reload])

  const validate = useCallback(async () => {
    if (editable === null || editable.id === undefined || editable.lock_version === undefined) return
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await validatePlatformSettingsVersion(csrfToken, editable.id, editable.lock_version)
      setActionNotice(t(securityCopy.validated, locale))
      reload()
    } catch (error) {
      fail(error)
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, editable, fail, locale, reload])

  const publish = useCallback(async () => {
    if (editable === null || editable.id === undefined || editable.lock_version === undefined) return
    setActionBusy(true)
    setActionError(null)
    setActionNotice(null)
    try {
      await publishPlatformSettingsVersion(csrfToken, editable.id, editable.lock_version)
      setActionNotice(t(securityCopy.published, locale))
      reload()
    } catch (error) {
      fail(error)
    } finally {
      setActionBusy(false)
    }
  }, [csrfToken, editable, fail, locale, reload])

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { current, versions } = data
  const policy = securityPolicyOf(editable ?? current)

  return (
    <PanelGrid>
      <Panel
        id="platform-security-policy"
        title={t(securityCopy.policy, locale)}
        actions={editable !== null && canManage ? (
          <Button variant="secondary" disabled={actionBusy} onClick={() => void validate()}>
            {t(securityCopy.validate, locale)}
          </Button>
        ) : undefined}
      >
        <dl className="detail-list">
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.version, locale)}</dt>
            <dd className="detail-list__value">
              {(editable ?? current)?.id ?? t(securityCopy.noPublished, locale)}{' '}
              {editable && <StatusBadge variant={statusVariant(editable.status)}>{statusLabelText(editable.status, locale)}</StatusBadge>}
            </dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.defaultLocale, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? current)?.default_locale ?? '—'}</dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.timezone, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? current)?.timezone ?? '—'}</dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.activeLogMonths, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? current)?.active_log_months ?? '—'}</dd>
          </div>
          {SECURITY_KEYS.map((key) => (
            <div className="detail-list__row" key={key}>
              <dt className="detail-list__key">{securityLabel(key, locale)}</dt>
              <dd className="detail-list__value">
                {policy[key] ?? '—'}
                {editable !== null && canManage && (
                  <Button variant="quiet" disabled={actionBusy} onClick={() => openEdit(key, policy[key])}>
                    {t(securityCopy.edit, locale)}
                  </Button>
                )}
              </dd>
            </div>
          ))}
        </dl>
        {editable === null && canManage && (
          <Button variant="secondary" disabled={actionBusy} onClick={() => void createDraft()}>
            {t(securityCopy.createDraft, locale)}
          </Button>
        )}
        {editable !== null && canPublish && editable.status === 'validated' && (
          <Button variant="primary" disabled={actionBusy} onClick={() => void publish()}>
            {t(securityCopy.publish, locale)}
          </Button>
        )}
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Panel id="platform-security-versions" title={t(securityCopy.versions, locale)}>
        {versions.length === 0 ? (
          <p className="screen-list__row-meta">{t(platformCopy.empty, locale)}</p>
        ) : (
          <table className="entity-table">
            <thead>
              <tr>
                <th scope="col">{t(securityCopy.version, locale)}</th>
                <th scope="col">{t(securityCopy.policy, locale)}</th>
                <th scope="col">{t(securityCopy.versions, locale)}</th>
              </tr>
            </thead>
            <tbody>
              {versions.map((version) => (
                <tr key={version.id ?? version.version_id}>
                  <td>{version.id ?? '—'}</td>
                  <td><StatusBadge variant={statusVariant(version.status)}>{statusLabelText(version.status, locale)}</StatusBadge></td>
                  <td>{version.lock_version ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>

      <Drawer
        open={editKey !== null}
        onClose={() => setEditKey(null)}
        title={`${t(securityCopy.editing, locale)} ${editKey !== null ? securityLabel(editKey, locale) : ''}`}
      >
        <Field id="platform-security-edit-value" label={editKey !== null ? securityLabel(editKey, locale) : ''}>
          <input
            id="platform-security-edit-value"
            className="field__control"
            type="number"
            inputMode="numeric"
            min={1}
            value={editValue}
            onChange={(event) => setEditValue(event.currentTarget.value)}
          />
        </Field>
        <div className="form-actions">
          <Button variant="quiet" onClick={() => setEditKey(null)}>{t(platformCopy.cancel, locale)}</Button>
          <Button variant="primary" disabled={actionBusy || editValue === ''} onClick={() => void saveEdit()}>
            {t(platformCopy.save, locale)}
          </Button>
        </div>
      </Drawer>
    </PanelGrid>
  )
}

function statusLabelText(status: string | undefined, locale: 'ar' | 'en'): string {
  switch (status) {
    case 'published': return t(securityCopy.published, locale)
    case 'draft': return t(securityCopy.draft, locale)
    case 'validated': return t(securityCopy.validated, locale)
    case 'retired': return t(securityCopy.retired, locale)
    default: return status ?? '—'
  }
}
