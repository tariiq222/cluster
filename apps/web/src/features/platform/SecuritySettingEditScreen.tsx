import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { usePlatformSettingsVersions } from '../../api/hooks'
import { ApiError, stateFromError } from '../../api/http'
import { setPlatformSetting } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { ActionError, ActionNotice } from './section-state'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { platformCopy, securityCopy, t } from './platform-copy'
import type { PlatformSettingsVersionsList } from './platform-types'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former one-field security edit Sheet
 * (route `/platform/settings/:versionId/security/:settingKey/edit`).
 *
 * The version is located in the settings-versions list (rows carry
 * `version_id` and `lock_version`); the current value is seeded from the
 * version's security policy. Every security key is an integer, so the
 * field renders the same number input the inline Sheet used and saves with
 * `value_type: 'integer'`. A 412 conflict keeps the typed value, shows the
 * stale alert, and reloads the versions list.
 *
 * The form is a short focused intake (DESIGN-RULES §2.7), so the page uses
 * `SingleRegionFormLayout` with a `max-w-3xl` bounded surface and an
 * actions footer separated by `border-t pt-6`.
 */

interface SecuritySettingEditScreenProps {
  versionId: string
  settingKey: string
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

export function SecuritySettingEditScreen({ versionId, settingKey }: SecuritySettingEditScreenProps) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [value, setValue] = useState('')
  const [staleNotice, setStaleNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const seededRef = useRef(false)

  const versionsQuery = usePlatformSettingsVersions()
  const versions = versionsQuery.data as unknown as PlatformSettingsVersionsList | undefined
  const version = versions?.items.find(
    (item) => item.version_id === versionId || item.id === versionId,
  )

  const canManage = (principal.capabilities ?? []).includes('platform_settings.manage')

  useEffect(() => {
    if (version === undefined || seededRef.current) return
    seededRef.current = true
    const current = (version.security as Record<string, number | undefined> | undefined)?.[settingKey]
    setValue(current === undefined ? '' : String(current))
  }, [version, settingKey])

  const reload = useCallback(() => {
    void versionsQuery.refetch()
  }, [versionsQuery])

  const back = useCallback(() => {
    navigate('/platform?tab=settings')
  }, [navigate])

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setStaleNotice(t(securityCopy.stale, locale))
      reload()
      return
    }
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale, reload])

  const saveMutation = useMutation({
    mutationFn: () => {
      if (version === undefined || version.lock_version === undefined) {
        throw new Error('Settings version not found')
      }
      return setPlatformSetting(
        csrfToken,
        versionId,
        settingKey,
        { value_type: 'integer', value: Number(value) },
        version.lock_version,
      )
    },
    onMutate: () => {
      setStaleNotice(null)
      setActionError(null)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-settings-versions'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-settings-current'] })
      navigate('/platform?tab=settings')
    },
    onError: fail,
  })

  if (!canManage) {
    return (
      <PageLayout data-testid="security-setting-edit-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (versionsQuery.isPending) {
    return (
      <PageLayout data-testid="security-setting-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToSecurity, locale)} onBack={back} locale={locale} />
        </div>
        <LoadingState rows={3} announce={t(platformCopy.loading, locale)} />
      </PageLayout>
    )
  }

  if (versionsQuery.error) {
    const derived = stateFromError(versionsQuery.error)
    return (
      <PageLayout data-testid="security-setting-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToSecurity, locale)} onBack={back} locale={locale} />
        </div>
        {derived === 'forbidden' || derived === 'not-found' ? (
          <DeniedState locale={locale} />
        ) : (
          <ErrorState locale={locale} onRetry={reload} />
        )}
      </PageLayout>
    )
  }

  if (version === undefined) {
    return (
      <PageLayout data-testid="security-setting-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToSecurity, locale)} onBack={back} locale={locale} />
        </div>
        <Alert variant="destructive" role="alert">
          <AlertTitle>{t(platformCopy.unavailable, locale)}</AlertTitle>
          <AlertDescription>{t(platformCopy.unavailableBody, locale)}</AlertDescription>
        </Alert>
      </PageLayout>
    )
  }

  const label = securityLabel(settingKey, locale)

  return (
    <PageLayout data-testid="security-setting-edit-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToSecurity, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={`${t(securityCopy.editing, locale)}${label}`}
        description={t(securityCopy.editSettingIntro, locale)}
        meta={
          <span className="font-mono text-muted-foreground text-xs" dir="ltr">
            {version.id ?? version.version_id ?? '—'}
          </span>
        }
        headingId="security-setting-edit-title"
      />

      <SingleRegionFormLayout
        testId="security-setting-edit-form"
        onSubmit={(event) => {
          event.preventDefault()
          saveMutation.mutate()
        }}
        actions={
          <>
            <Button type="button" variant="outline" onClick={back} disabled={saveMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </Button>
            <Button type="submit" disabled={saveMutation.isPending || value === ''}>
              {t(platformCopy.save, locale)}
            </Button>
          </>
        }
      >
        <FormSection
          headingId="security-setting-edit-section-value"
          title={t(platformCopy.formSectionSecurity, locale)}
        >
          <div className="grid gap-2">
            <Label htmlFor="platform-security-edit-value">{label}</Label>
            <Input
              id="platform-security-edit-value"
              type="number"
              inputMode="numeric"
              min={1}
              value={value}
              disabled={saveMutation.isPending}
              onChange={(event) => setValue(event.currentTarget.value)}
            />
          </div>
        </FormSection>
        {staleNotice && <ActionNotice message={staleNotice} />}
        {actionError && <ActionError message={actionError} />}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}