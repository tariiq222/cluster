import { useCallback, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError } from '../../api/http'
import { requestPlatformTechnicalLogsRestore } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { DeniedState } from '@/components/states'
import { ActionError } from './section-state'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { logsCopy, platformCopy, t } from './platform-copy'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former restore Sheet
 * (route `/platform/logs/restore`).
 *
 * Gated on the same `platform_operations.logs.restore` capability the
 * LogsSection uses before exposing the restore action (both in the
 * header and in the deferred-503 alert). Idempotency is owned by the
 * `requestPlatformTechnicalLogsRestore` wrapper.
 *
 * Confirm-and-act intake (DESIGN-RULES §2.7): manifest_id and reason are
 * the two inputs that trigger the restore request, both required, with
 * cancel and restore actions in a separated footer.
 */
export function LogsRestoreScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const [manifestId, setManifestId] = useState('')
  const [reason, setReason] = useState('')
  const [actionError, setActionError] = useState<string | null>(null)

  const canRestore = (principal.capabilities ?? []).includes('platform_operations.logs.restore')

  const back = useCallback(() => {
    navigate('/platform?tab=monitoring')
  }, [navigate])

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const restoreMutation = useMutation({
    mutationFn: () =>
      requestPlatformTechnicalLogsRestore(csrfToken, { manifest_id: manifestId.trim(), reason: reason.trim() }),
    onMutate: () => {
      setActionError(null)
    },
    onSuccess: () => {
      navigate('/platform?tab=monitoring')
    },
    onError: fail,
  })

  const valid = manifestId.trim() !== '' && reason.trim() !== ''

  if (!canRestore) {
    return (
      <PageLayout data-testid="logs-restore-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="logs-restore-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToLogs, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={t(logsCopy.restore, locale)}
        description={t(logsCopy.restoreIntro, locale)}
        headingId="logs-restore-title"
      />

      <SingleRegionFormLayout
        testId="logs-restore-screen-form"
        onSubmit={(event) => {
          event.preventDefault()
          restoreMutation.mutate()
        }}
        actions={
          <>
            <Button type="button" variant="outline" onClick={back} disabled={restoreMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </Button>
            <Button type="submit" disabled={restoreMutation.isPending || !valid}>
              {t(logsCopy.restore, locale)}
            </Button>
          </>
        }
      >
        <FormSection
          headingId="logs-restore-section-request"
          title={t(platformCopy.formSectionRestore, locale)}
        >
          <div className="grid gap-2">
            <Label htmlFor="platform-logs-restore-manifest">{t(logsCopy.manifestId, locale)}</Label>
            <Input
              id="platform-logs-restore-manifest"
              dir="ltr"
              value={manifestId}
              disabled={restoreMutation.isPending}
              onChange={(event) => setManifestId(event.currentTarget.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-logs-restore-reason">{t(logsCopy.reason, locale)}</Label>
            <Textarea
              id="platform-logs-restore-reason"
              rows={4}
              value={reason}
              disabled={restoreMutation.isPending}
              onChange={(event) => setReason(event.currentTarget.value)}
            />
          </div>
        </FormSection>
        {actionError && <ActionError message={actionError} />}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}