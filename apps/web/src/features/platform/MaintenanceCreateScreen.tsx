import { useCallback, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError } from '../../api/http'
import { schedulePlatformMaintenanceWindow } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DeniedState } from '@/components/states'
import { ActionError } from './section-state'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { maintenanceCopy, platformCopy, t } from './platform-copy'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former schedule-maintenance Sheet
 * (route `/platform/maintenance/new`).
 *
 * Mirrors the Sheet's field set: starts/ends as datetime-local inputs and
 * the bilingual messages. Times are converted to UTC before submit, exactly
 * like the Section did. Idempotency is owned by the
 * `schedulePlatformMaintenanceWindow` wrapper.
 *
 * The form is a short focused intake (DESIGN-RULES §2.7), so the page uses
 * `SingleRegionFormLayout` with a `max-w-3xl` bounded surface and an
 * actions footer separated by `border-t pt-6`.
 */

function toUtc(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toISOString()
}

export function MaintenanceCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [messageAr, setMessageAr] = useState('')
  const [messageEn, setMessageEn] = useState('')
  const [actionError, setActionError] = useState<string | null>(null)

  const canManage = (principal.capabilities ?? []).includes('platform_operations.maintenance.manage')

  const back = useCallback(() => {
    navigate('/platform?tab=maintenance')
  }, [navigate])

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
    },
    onSuccess: () => {
      navigate('/platform?tab=maintenance')
    },
    onError: fail,
  })

  const canSubmit =
    startsAt !== '' && messageAr.trim() !== '' && messageEn.trim() !== ''

  if (!canManage) {
    return (
      <PageLayout data-testid="maintenance-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="maintenance-create-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToMaintenance, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={t(maintenanceCopy.schedule, locale)}
        description={t(maintenanceCopy.scheduleIntro, locale)}
        headingId="maintenance-create-title"
      />

      <SingleRegionFormLayout
        testId="maintenance-create-form"
        onSubmit={(event) => {
          event.preventDefault()
          scheduleMutation.mutate()
        }}
        actions={
          <>
            <Button type="button" variant="outline" onClick={back} disabled={scheduleMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </Button>
            <Button type="submit" disabled={scheduleMutation.isPending || !canSubmit}>
              {t(maintenanceCopy.schedule, locale)}
            </Button>
          </>
        }
      >
        <FormSection
          headingId="maintenance-create-section-window"
          title={t(platformCopy.formSectionSchedule, locale)}
        >
          <div className="grid gap-2">
            <Label htmlFor="platform-maintenance-starts">{t(maintenanceCopy.startsAt, locale)}</Label>
            <Input
              id="platform-maintenance-starts"
              type="datetime-local"
              value={startsAt}
              disabled={scheduleMutation.isPending}
              onChange={(event) => setStartsAt(event.currentTarget.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-maintenance-ends">{t(maintenanceCopy.endsAt, locale)}</Label>
            <Input
              id="platform-maintenance-ends"
              type="datetime-local"
              value={endsAt}
              disabled={scheduleMutation.isPending}
              onChange={(event) => setEndsAt(event.currentTarget.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-maintenance-message-ar">{t(maintenanceCopy.messageAr, locale)}</Label>
            <Input
              id="platform-maintenance-message-ar"
              maxLength={1024}
              value={messageAr}
              disabled={scheduleMutation.isPending}
              onChange={(event) => setMessageAr(event.currentTarget.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-maintenance-message-en">{t(maintenanceCopy.messageEn, locale)}</Label>
            <Input
              id="platform-maintenance-message-en"
              maxLength={1024}
              value={messageEn}
              disabled={scheduleMutation.isPending}
              onChange={(event) => setMessageEn(event.currentTarget.value)}
            />
          </div>
        </FormSection>
        {actionError && <ActionError message={actionError} />}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}