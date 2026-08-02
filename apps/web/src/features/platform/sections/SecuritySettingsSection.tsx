import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileLock2, Pencil, Rocket, ShieldCheck } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { useNavigate } from '../../../app/navigation-context'
import { usePlatformSettingsVersions } from '../../../api/hooks'
import { ApiError } from '../../../api/http'
import {
  createPlatformSettingsDraft,
  getCurrentPlatformSettings,
  publishPlatformSettingsVersion,
  validatePlatformSettingsVersion,
} from '../platform-api'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/states'
import { actionAllowed, queryResourceState, type QueryLike } from '../section-support'
import { SectionBoundary, ActionNotice, ActionError } from '../section-state'
import { platformCopy, securityCopy, t } from '../platform-copy'
import type { PlatformSecurityPolicy, PlatformSettingsVersion, PlatformSettingsVersionsList } from '../platform-types'

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

function statusLabelText(status: string | undefined, locale: 'ar' | 'en'): string {
  switch (status) {
    case 'published': return t(securityCopy.published, locale)
    case 'draft': return t(securityCopy.draft, locale)
    case 'validated': return t(securityCopy.validated, locale)
    case 'retired': return t(securityCopy.retired, locale)
    default: return status ?? '—'
  }
}

function statusVariant(status: string | undefined): 'outline' | 'secondary' | 'default' | 'destructive' {
  switch (status) {
    case 'published': return 'default'
    case 'validated': return 'secondary'
    case 'draft': return 'outline'
    default: return 'outline'
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
  const navigate = useNavigate()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)

  const versionsQuery = usePlatformSettingsVersions()
  const currentQuery = useQuery({
    queryKey: ['platform-settings-current'],
    queryFn: () => getCurrentPlatformSettings(csrfToken),
  })

  const versions = versionsQuery.data as unknown as PlatformSettingsVersionsList | undefined
  const current = currentQuery.data as PlatformSettingsVersion | undefined

  const data: SecurityPayload | null =
    versions !== undefined && current !== undefined
      ? { current, versions: versions.items }
      : null

  const combinedQuery: QueryLike<SecurityPayload> = {
    isPending: versionsQuery.isPending || currentQuery.isPending,
    error: versionsQuery.error ?? currentQuery.error,
    data,
  }
  const state = queryResourceState(combinedQuery, isSecurityPayloadEmpty)

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

  const actionBusy =
    createDraftMutation.isPending || validateMutation.isPending || publishMutation.isPending

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={<EmptyState title={t(securityCopy.policy, locale)} body={t(platformCopy.empty, locale)} />}
      />
    )
  }

  const { current: currentData, versions: versionsData } = data
  const policy = securityPolicyOf(editable ?? currentData)
  const validated = editable?.status === 'validated'

  return (
    <section aria-labelledby="platform-security-policy" className="space-y-4">
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2">
            <h2 id="platform-security-policy" className="flex items-center gap-2 text-base leading-snug font-medium">
              <FileLock2 className="size-4 text-muted-foreground" aria-hidden="true" />
              {t(securityCopy.policy, locale)}
            </h2>
            {editable !== null && canManage && (
              <Button variant="outline" size="sm" disabled={actionBusy} onClick={() => validateMutation.mutate()}>
                <ShieldCheck className="size-4" aria-hidden="true" />
                {t(securityCopy.validate, locale)}
              </Button>
            )}
          </CardHeader>
          <CardContent className="grid gap-2 text-sm">
            <dl className="grid gap-2">
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted-foreground">{t(securityCopy.version, locale)}</dt>
                <dd className="flex items-center gap-2">
                  {(editable ?? currentData)?.id ?? t(securityCopy.noPublished, locale)}
                  {editable && (
                    <Badge variant={statusVariant(editable.status)}>{statusLabelText(editable.status, locale)}</Badge>
                  )}
                </dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted-foreground">{t(securityCopy.defaultLocale, locale)}</dt>
                <dd>{(editable ?? currentData)?.default_locale ?? '—'}</dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted-foreground">{t(securityCopy.timezone, locale)}</dt>
                <dd>{(editable ?? currentData)?.timezone ?? '—'}</dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted-foreground">{t(securityCopy.activeLogMonths, locale)}</dt>
                <dd>{(editable ?? currentData)?.active_log_months ?? '—'}</dd>
              </div>
              {SECURITY_KEYS.map((key) => (
                <div className="flex items-center justify-between gap-2" key={key}>
                  <dt className="text-muted-foreground">{securityLabel(key, locale)}</dt>
                  <dd className="flex items-center gap-2">
                    {policy[key] ?? '—'}
                    {editable !== null && canManage && (
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        disabled={actionBusy}
                        onClick={() => {
                          void navigate(`/platform/settings/${editable.id ?? editable.version_id}/security/${key}/edit`)
                        }}
                        aria-label={`${t(securityCopy.edit, locale)} ${securityLabel(key, locale)}`}
                      >
                        <Pencil className="size-3.5" aria-hidden="true" />
                      </Button>
                    )}
                  </dd>
                </div>
              ))}
            </dl>
            <CardDescription>
              {editable === null && canManage && (
                <Button variant="outline" size="sm" disabled={actionBusy} onClick={() => createDraftMutation.mutate()}>
                  {t(securityCopy.createDraft, locale)}
                </Button>
              )}
            </CardDescription>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <h2 id="platform-security-versions" className="flex items-center gap-2 text-base leading-snug font-medium">
              <Rocket className="size-4 text-muted-foreground" aria-hidden="true" />
              {t(securityCopy.versions, locale)}
            </h2>
          </CardHeader>
          <CardContent>
            {versionsData.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t(platformCopy.empty, locale)}</p>
            ) : (
              <ul className="divide-y">
                {versionsData.map((version) => (
                  <li key={version.id ?? version.version_id} className="flex items-center justify-between gap-2 py-2 text-sm">
                    <span className="font-mono text-xs" dir="ltr">{version.id ?? '—'}</span>
                    <span className="flex items-center gap-2">
                      <Badge variant={statusVariant(version.status)}>{statusLabelText(version.status, locale)}</Badge>
                      <span className="text-muted-foreground text-xs">{version.lock_version ?? '—'}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      {/* Publishing requires a successful validation first. The button stays
       * disabled until the draft is validated, with an accessible tooltip
       * explaining why (PAGES.md § settings). */}
      <div className="flex justify-end">
        {editable !== null && canPublish && (
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span tabIndex={0} className="inline-flex">
                  <Button
                    type="button"
                    variant="default"
                    disabled={!validated || actionBusy}
                    onClick={() => publishMutation.mutate()}
                  >
                    <Rocket className="size-4" aria-hidden="true" />
                    {t(securityCopy.publish, locale)}
                  </Button>
                </span>
              </TooltipTrigger>
              {!validated && (
                <TooltipContent>{t(securityCopy.publishDisabled, locale)}</TooltipContent>
              )}
            </Tooltip>
          </TooltipProvider>
        )}
      </div>
    </section>
  )
}
