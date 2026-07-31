import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePlatformSettingsVersions } from '../../../api/hooks'
import { Button, Drawer, Field, Panel, PanelGrid, StatusBadge } from '../../../ui'
import { ApiError } from '../../../api/http'
import {
  createPlatformSettingsDraft,
  getCurrentPlatformSettings,
  publishPlatformSettingsVersion,
  setPlatformSetting,
  validatePlatformSettingsVersion,
} from '../platform-api'
import { platformCopy, securityCopy, t } from '../platform-copy'
import { actionAllowed, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import type { PlatformSettingsVersion, PlatformSettingsVersionsList, PlatformSecurityPolicy } from '../platform-types'

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
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [editKey, setEditKey] = useState<string | null>(null)
  const [editValue, setEditValue] = useState('')

  const versionsQuery = usePlatformSettingsVersions()
  const currentQuery = useQuery({
    queryKey: ['platform-settings-current'],
    queryFn: () => getCurrentPlatformSettings(csrfToken),
  })

  const versions = versionsQuery.data as unknown as PlatformSettingsVersionsList | undefined
  const current = currentQuery.data

  const data: SecurityPayload | null =
    versions !== undefined && current !== undefined
      ? { current, versions: versions.items }
      : null

  let state: SectionState = 'loading'
  if (!versionsQuery.isPending && !currentQuery.isPending) {
    const error = versionsQuery.error ?? currentQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else if (data === null) state = 'empty'
    else state = isSecurityPayloadEmpty(data) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void versionsQuery.refetch()
    void currentQuery.refetch()
  }, [currentQuery, versionsQuery])

  const invalidateSettings = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['platform-settings-versions'] })
    void queryClient.invalidateQueries({ queryKey: ['platform-settings-current'] })
  }, [queryClient])

  const editable = data === null ? null : editableVersion(data.versions)

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

  const createDraftMutation = useMutation({
    mutationFn: () => createPlatformSettingsDraft(csrfToken),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(securityCopy.draft, locale))
      invalidateSettings()
    },
    onError: fail,
  })

  const createDraft = useCallback(() => {
    createDraftMutation.mutate()
  }, [createDraftMutation])

  const openEdit = useCallback((key: string, value: number | undefined) => {
    setEditKey(key)
    setEditValue(value === undefined ? '' : String(value))
  }, [])

  const saveEditMutation = useMutation({
    mutationFn: () => {
      if (editKey === null || editable === null || editable.id === undefined || editable.lock_version === undefined) {
        throw new Error('No editable settings version')
      }
      return setPlatformSetting(
        csrfToken,
        editable.id,
        `security.${editKey}`,
        { value_type: 'integer', value: Number(editValue) },
        editable.lock_version,
      )
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setEditKey(null)
      setActionNotice(t(platformCopy.refreshed, locale))
      invalidateSettings()
    },
    onError: fail,
  })

  const saveEdit = useCallback(() => {
    if (editKey === null || editable === null || editable.id === undefined || editable.lock_version === undefined) return
    saveEditMutation.mutate()
  }, [editKey, editable, saveEditMutation])

  const validateMutation = useMutation({
    mutationFn: () => {
      if (editable === null || editable.id === undefined || editable.lock_version === undefined) {
        throw new Error('No editable settings version')
      }
      return validatePlatformSettingsVersion(csrfToken, editable.id, editable.lock_version)
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(securityCopy.validated, locale))
      invalidateSettings()
    },
    onError: fail,
  })

  const validate = useCallback(() => {
    if (editable === null || editable.id === undefined || editable.lock_version === undefined) return
    validateMutation.mutate()
  }, [editable, validateMutation])

  const publishMutation = useMutation({
    mutationFn: () => {
      if (editable === null || editable.id === undefined || editable.lock_version === undefined) {
        throw new Error('No editable settings version')
      }
      return publishPlatformSettingsVersion(csrfToken, editable.id, editable.lock_version)
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(securityCopy.published, locale))
      invalidateSettings()
    },
    onError: fail,
  })

  const publish = useCallback(() => {
    if (editable === null || editable.id === undefined || editable.lock_version === undefined) return
    publishMutation.mutate()
  }, [editable, publishMutation])

  const actionBusy =
    createDraftMutation.isPending || saveEditMutation.isPending || validateMutation.isPending || publishMutation.isPending

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} onRetry={reload} />
  }

  const { current: currentData, versions: versionsData } = data
  const policy = securityPolicyOf(editable ?? currentData)

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
              {(editable ?? currentData)?.id ?? t(securityCopy.noPublished, locale)}{' '}
              {editable && <StatusBadge variant={statusVariant(editable.status)}>{statusLabelText(editable.status, locale)}</StatusBadge>}
            </dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.defaultLocale, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? currentData)?.default_locale ?? '—'}</dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.timezone, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? currentData)?.timezone ?? '—'}</dd>
          </div>
          <div className="detail-list__row">
            <dt className="detail-list__key">{t(securityCopy.activeLogMonths, locale)}</dt>
            <dd className="detail-list__value">{(editable ?? currentData)?.active_log_months ?? '—'}</dd>
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
        {versionsData.length === 0 ? (
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
              {versionsData.map((version) => (
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
