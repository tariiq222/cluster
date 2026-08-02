import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, stateFromError } from '../../api/http'
import { listPlatformAlertPolicies, updatePlatformAlertPolicy } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { ActionError, ActionNotice } from './section-state'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  TwoRegionFormLayout,
} from '@/components/form-page-layout'
import { alertsCopy, logsCopy, platformCopy, t } from './platform-copy'
import type { PlatformAlertPolicyList } from './platform-types'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former edit-policy Sheet
 * (route `/platform/alerts/:policyId/edit`).
 *
 * The policy is loaded by id (list + find) so the page works after a
 * direct navigation or refresh; a missing policy renders the shared
 * non-disclosing alert with a back link. The status/severity options
 * replicate the Sheet's selects. A 412 conflict keeps the inputs, shows
 * the stale alert, and reloads the policy list.
 *
 * The form has enough fields to warrant a live review summary
 * (DESIGN-RULES §2.7), so the page uses `TwoRegionFormLayout` with the
 * intake surface on the left and the review + action stack on the right.
 */

interface AlertPolicyEditScreenProps {
  policyId: string
}

function severityLabel(severity: string, locale: 'ar' | 'en'): string {
  const labels = logsCopy.severities as Record<string, { ar: string; en: string }>
  const entry = labels[severity]
  return entry !== undefined ? t(entry, locale) : severity
}

function statusLabel(statusValue: string, locale: 'ar' | 'en'): string {
  if (statusValue === 'enabled') return t(alertsCopy.enabled, locale)
  if (statusValue === 'disabled') return t(alertsCopy.disabled, locale)
  return statusValue
}

export function AlertPolicyEditScreen({ policyId }: AlertPolicyEditScreenProps) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('')
  const [severity, setSeverity] = useState('')
  const [channel, setChannel] = useState('')
  const [staleNotice, setStaleNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [formReady, setFormReady] = useState(false)
  const seededRef = useRef(false)

  const canManage = (principal.capabilities ?? []).includes('platform_operations.alerts.manage')

  const policiesQuery = useQuery({
    queryKey: ['platform-alert-policies'],
    queryFn: () => listPlatformAlertPolicies(csrfToken),
    enabled: canManage,
  })

  const data: PlatformAlertPolicyList | null = policiesQuery.data ?? null
  const policy = data?.items.find((item) => item.id === policyId)

  /*
   * The form mounts only after the policy values are seeded: Radix Select
   * resyncs its hidden bubble input whenever the controlled value changes
   * after mounting with an empty value, which would discard the seed.
   */
  useEffect(() => {
    if (policy === undefined || seededRef.current) return
    seededRef.current = true
    setStatus(policy.status)
    setSeverity(policy.severity)
    setChannel(policy.channel)
    setFormReady(true)
  }, [policy])

  const reload = useCallback(() => {
    void policiesQuery.refetch()
  }, [policiesQuery])

  const back = useCallback(() => {
    navigate('/platform?tab=settings')
  }, [navigate])

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setStaleNotice(t(alertsCopy.stale, locale))
      reload()
      return
    }
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale, reload])

  const updateMutation = useMutation({
    mutationFn: () => {
      if (policy === undefined) {
        throw new Error('Alert policy not found')
      }
      return updatePlatformAlertPolicy(
        csrfToken,
        policyId,
        { status, severity, channel: channel.trim() },
        policy.lock_version,
      )
    },
    onMutate: () => {
      setStaleNotice(null)
      setActionError(null)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-alert-policies'] })
      navigate('/platform?tab=settings')
    },
    onError: fail,
  })

  if (!canManage) {
    return (
      <PageLayout data-testid="alert-policy-edit-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (policiesQuery.isPending) {
    return (
      <PageLayout data-testid="alert-policy-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToAlerts, locale)} onBack={back} locale={locale} />
        </div>
        <LoadingState rows={3} announce={t(platformCopy.loading, locale)} />
      </PageLayout>
    )
  }

  if (policiesQuery.error) {
    const derived = stateFromError(policiesQuery.error)
    return (
      <PageLayout data-testid="alert-policy-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToAlerts, locale)} onBack={back} locale={locale} />
        </div>
        {derived === 'forbidden' || derived === 'not-found' ? (
          <DeniedState locale={locale} />
        ) : (
          <ErrorState locale={locale} onRetry={reload} />
        )}
      </PageLayout>
    )
  }

  if (policy === undefined) {
    return (
      <PageLayout data-testid="alert-policy-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToAlerts, locale)} onBack={back} locale={locale} />
        </div>
        <Alert variant="destructive" role="alert">
          <AlertTitle>{t(platformCopy.unavailable, locale)}</AlertTitle>
          <AlertDescription>{t(platformCopy.unavailableBody, locale)}</AlertDescription>
        </Alert>
      </PageLayout>
    )
  }

  if (!formReady) {
    return (
      <PageLayout data-testid="alert-policy-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToAlerts, locale)} onBack={back} locale={locale} />
        </div>
        <LoadingState rows={3} announce={t(platformCopy.loading, locale)} />
      </PageLayout>
    )
  }

  const reviewRows = [
    {
      label: t(alertsCopy.status, locale),
      value: statusLabel(status, locale),
    },
    {
      label: t(alertsCopy.severity, locale),
      value: severityLabel(severity, locale),
    },
    {
      label: t(alertsCopy.channel, locale),
      value: channel.trim() === '' ? null : channel.trim(),
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
  ]

  return (
    <PageLayout data-testid="alert-policy-edit-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToAlerts, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={`${t(alertsCopy.edit, locale)} · ${policy.code}`}
        description={t(alertsCopy.editIntro, locale)}
        headingId="alert-policy-edit-title"
      />

      <TwoRegionFormLayout
        testId="alert-policy-edit-form"
        mainTestId="alert-policy-edit-main"
        reviewTestId="alert-policy-edit-review"
        onSubmit={(event) => {
          event.preventDefault()
          updateMutation.mutate()
        }}
        main={
          <>
            <FormSection
              headingId="alert-policy-edit-section-policy"
              title={t(platformCopy.formSectionPolicy, locale)}
            >
              <div className="grid gap-2">
                <Label htmlFor="platform-alert-status">{t(alertsCopy.status, locale)}</Label>
                <Select value={status} onValueChange={setStatus} disabled={updateMutation.isPending}>
                  <SelectTrigger id="platform-alert-status" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="enabled">{t(alertsCopy.enabled, locale)}</SelectItem>
                    <SelectItem value="disabled">{t(alertsCopy.disabled, locale)}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-alert-severity">{t(alertsCopy.severity, locale)}</Label>
                <Select value={severity} onValueChange={setSeverity} disabled={updateMutation.isPending}>
                  <SelectTrigger id="platform-alert-severity" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.keys(logsCopy.severities).map((item) => (
                      <SelectItem key={item} value={item}>
                        {severityLabel(item, locale)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-alert-channel">{t(alertsCopy.channel, locale)}</Label>
                <Input
                  id="platform-alert-channel"
                  maxLength={64}
                  value={channel}
                  disabled={updateMutation.isPending}
                  onChange={(event) => setChannel(event.currentTarget.value)}
                />
              </div>
            </FormSection>
            {staleNotice && <ActionNotice message={staleNotice} />}
            {actionError && <ActionError message={actionError} />}
          </>
        }
        review={
          <>
            <FormSection
              headingId="alert-policy-edit-review-heading"
              title={t(platformCopy.formReviewHeading, locale)}
              density="tight"
            >
              <ReviewSummary rows={reviewRows} testId="alert-policy-edit-review-summary" />
            </FormSection>
            <FormActionStack testId="alert-policy-edit-actions">
              <Button type="button" variant="outline" onClick={back} disabled={updateMutation.isPending}>
                {t(platformCopy.cancel, locale)}
              </Button>
              <Button type="submit" disabled={updateMutation.isPending || policy === undefined || channel.trim() === ''}>
                {t(platformCopy.save, locale)}
              </Button>
            </FormActionStack>
          </>
        }
      />
    </PageLayout>
  )
}