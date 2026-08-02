import { useCallback, useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError } from '../../api/http'
import { createBusinessCalendar } from './platform-api'
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
import { DeniedState } from '@/components/states'
import { ActionError, ActionNotice } from './section-state'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { calendarsCopy, platformCopy, t } from './platform-copy'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former create-calendar Sheet
 * (route `/platform/calendars/new`).
 *
 * Mirrors the Sheet's create branch: the scope_type selector and the
 * scope_id input (locked to `platform` for the platform scope). The create
 * body carries no timezone, so the page exposes exactly scope_type and
 * scope_id. Idempotency is owned by the `createBusinessCalendar` wrapper.
 *
 * The form is a short focused intake (DESIGN-RULES §2.7), so the page uses
 * `SingleRegionFormLayout` with a `max-w-3xl` bounded surface and an
 * actions footer separated by `border-t pt-6`.
 */
export function CalendarCreateScreen() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const [scopeType, setScopeType] = useState('platform')
  const [scopeId, setScopeId] = useState('platform')
  const [staleNotice, setStaleNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const canCreate = (principal.capabilities ?? []).includes('platform_settings.calendar.manage')

  const scopeOptions = useMemo(
    () => [
      { value: 'platform', label: t(calendarsCopy.scopePlatform, locale) },
      { value: 'cluster', label: t(calendarsCopy.scopeCluster, locale) },
      { value: 'facility', label: t(calendarsCopy.scopeFacility, locale) },
    ],
    [locale],
  )

  const back = useCallback(() => {
    navigate('/platform?tab=settings')
  }, [navigate])

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setStaleNotice(t(calendarsCopy.stale, locale))
      return
    }
    setActionError(
      error instanceof ApiError
        ? error.problem.detail ?? error.problem.title ?? t(platformCopy.error, locale)
        : t(platformCopy.error, locale),
    )
  }, [locale])

  const createMutation = useMutation({
    mutationFn: () => createBusinessCalendar(csrfToken, {
      scope_type: scopeType as 'platform' | 'cluster' | 'facility',
      scope_id: scopeType === 'platform' ? 'platform' : scopeId.trim(),
    }),
    onMutate: () => {
      setStaleNotice(null)
      setActionError(null)
    },
    onSuccess: () => {
      navigate('/platform?tab=settings')
    },
    onError: fail,
  })

  const handleSave = useCallback(() => {
    if (scopeType !== 'platform' && scopeId.trim() === '') {
      setActionError(t(calendarsCopy.scopeIdRequired, locale))
      return
    }
    createMutation.mutate()
  }, [scopeType, scopeId, createMutation, locale])

  if (!canCreate) {
    return (
      <PageLayout data-testid="calendar-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="calendar-create-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={t(calendarsCopy.create, locale)}
        description={t(calendarsCopy.createIntro, locale)}
        headingId="calendar-create-title"
      />

      <SingleRegionFormLayout
        testId="calendar-create-form"
        onSubmit={(event) => {
          event.preventDefault()
          handleSave()
        }}
        actions={
          <>
            <Button type="button" variant="outline" onClick={back} disabled={createMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </Button>
            <Button type="submit" disabled={createMutation.isPending}>
              {t(calendarsCopy.create, locale)}
            </Button>
          </>
        }
      >
        <FormSection
          headingId="calendar-create-section-scope"
          title={t(platformCopy.formSectionScope, locale)}
        >
          <div className="grid gap-2">
            <Label htmlFor="platform-calendar-scope-type">{t(calendarsCopy.scopeType, locale)}</Label>
            <Select
              value={scopeType}
              disabled={createMutation.isPending}
              onValueChange={(value) => {
                setScopeType(value)
                if (value === 'platform') setScopeId('platform')
              }}
            >
              <SelectTrigger id="platform-calendar-scope-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {scopeOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-calendar-scope-id">{t(calendarsCopy.scopeId, locale)}</Label>
            <Input
              id="platform-calendar-scope-id"
              dir={scopeType === 'platform' ? 'ltr' : undefined}
              value={scopeType === 'platform' ? 'platform' : scopeId}
              disabled={scopeType === 'platform' || createMutation.isPending}
              onChange={(event) => setScopeId(event.currentTarget.value)}
            />
          </div>
        </FormSection>
        {staleNotice && <ActionNotice message={staleNotice} />}
        {actionError && <ActionError message={actionError} />}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}