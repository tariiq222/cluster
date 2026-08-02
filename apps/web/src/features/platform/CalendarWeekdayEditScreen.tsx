import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, stateFromError } from '../../api/http'
import { listBusinessCalendars, setBusinessCalendarWeekday } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { ActionError, ActionNotice } from './section-state'
import { FormSection, SingleRegionFormLayout } from '@/components/form-page-layout'
import { calendarsCopy, platformCopy, t } from './platform-copy'
import type { BusinessCalendarList } from './platform-types'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former weekday-edit Sheet
 * (route `/platform/calendars/:calendarId/weekdays/:weekday/edit`).
 *
 * The calendar is loaded by id (list + find) so the page works after a
 * direct navigation or refresh; a missing calendar renders the shared
 * non-disclosing alert with a back link. The working-day checkbox seeds
 * from the calendar's `working_days`; starts/ends are cleared exactly like
 * the Sheet's openWeekday did. A 412 conflict keeps the inputs, shows the
 * stale alert, and reloads the calendar list.
 *
 * The form is a short focused intake (DESIGN-RULES §2.7), so the page uses
 * `SingleRegionFormLayout` with a `max-w-3xl` bounded surface and an
 * actions footer separated by `border-t pt-6`.
 */

interface CalendarWeekdayEditScreenProps {
  calendarId: string
  weekday: number
}

function weekdayLabel(weekday: number, locale: 'ar' | 'en'): string {
  if (!Number.isInteger(weekday) || weekday < 1 || weekday > 7) {
    return String(weekday)
  }
  const day = calendarsCopy.days[weekday - 1]
  if (day === undefined) {
    return String(weekday)
  }
  return t(day, locale)
}

export function CalendarWeekdayEditScreen({ calendarId, weekday }: CalendarWeekdayEditScreenProps) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [isWorkingDay, setIsWorkingDay] = useState(false)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [staleNotice, setStaleNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const seededRef = useRef(false)

  const canManage = (principal.capabilities ?? []).includes('platform_settings.calendar.manage')

  const calendarsQuery = useQuery({
    queryKey: ['platform-calendars'],
    queryFn: () => listBusinessCalendars(csrfToken),
    enabled: canManage,
  })

  const data: BusinessCalendarList | null = calendarsQuery.data ?? null
  const calendar = data?.items.find((item) => item.id === calendarId)

  useEffect(() => {
    if (calendar === undefined || seededRef.current) return
    seededRef.current = true
    const working = calendar.values?.working_days ?? []
    setIsWorkingDay(working.includes(weekday))
  }, [calendar, weekday])

  const reload = useCallback(() => {
    void calendarsQuery.refetch()
  }, [calendarsQuery])

  const back = useCallback(() => {
    navigate('/platform?tab=settings')
  }, [navigate])

  const fail = useCallback((error: unknown) => {
    if (error instanceof ApiError && error.status === 412) {
      setStaleNotice(t(calendarsCopy.stale, locale))
      reload()
      return
    }
    setActionError(
      error instanceof ApiError
        ? error.problem.detail ?? error.problem.title ?? t(platformCopy.error, locale)
        : t(platformCopy.error, locale),
    )
  }, [locale, reload])

  const saveMutation = useMutation({
    mutationFn: () => {
      if (calendar === undefined) {
        throw new Error('Calendar not found')
      }
      return setBusinessCalendarWeekday(
        csrfToken,
        calendarId,
        weekday,
        { is_working_day: isWorkingDay, starts_at: startsAt || null, ends_at: endsAt || null },
        calendar.lock_version,
      )
    },
    onMutate: () => {
      setStaleNotice(null)
      setActionError(null)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-calendars'] })
      navigate('/platform?tab=settings')
    },
    onError: fail,
  })

  const handleSave = useCallback(() => {
    if (startsAt !== '' && endsAt !== '' && endsAt <= startsAt) {
      setActionError(t(calendarsCopy.timeRangeInvalid, locale))
      return
    }
    saveMutation.mutate()
  }, [startsAt, endsAt, saveMutation, locale])

  if (!canManage) {
    return (
      <PageLayout data-testid="calendar-weekday-edit-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (calendarsQuery.isPending) {
    return (
      <PageLayout data-testid="calendar-weekday-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
        </div>
        <LoadingState rows={3} announce={t(platformCopy.loading, locale)} />
      </PageLayout>
    )
  }

  if (calendarsQuery.error) {
    const derived = stateFromError(calendarsQuery.error)
    return (
      <PageLayout data-testid="calendar-weekday-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
        </div>
        {derived === 'forbidden' || derived === 'not-found' ? (
          <DeniedState locale={locale} />
        ) : (
          <ErrorState locale={locale} onRetry={reload} />
        )}
      </PageLayout>
    )
  }

  if (calendar === undefined) {
    return (
      <PageLayout data-testid="calendar-weekday-edit-screen">
        <div>
          <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
        </div>
        <Alert variant="destructive" role="alert">
          <AlertTitle>{t(platformCopy.unavailable, locale)}</AlertTitle>
          <AlertDescription>{t(platformCopy.unavailableBody, locale)}</AlertDescription>
        </Alert>
      </PageLayout>
    )
  }

  return (
    <PageLayout data-testid="calendar-weekday-edit-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={t(calendarsCopy.editWeekday, locale)}
        description={t(calendarsCopy.editWeekdayIntro, locale)}
        meta={<span className="text-muted-foreground text-sm">{weekdayLabel(weekday, locale)}</span>}
        headingId="calendar-weekday-edit-title"
      />

      <SingleRegionFormLayout
        testId="calendar-weekday-edit-form"
        onSubmit={(event) => {
          event.preventDefault()
          handleSave()
        }}
        actions={
          <>
            <Button type="button" variant="outline" onClick={back} disabled={saveMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </Button>
            <Button type="submit" disabled={saveMutation.isPending}>
              {t(platformCopy.save, locale)}
            </Button>
          </>
        }
      >
        <FormSection
          headingId="calendar-weekday-edit-section-day"
          title={t(platformCopy.formSectionWeekday, locale)}
        >
          <div className="grid gap-2">
            <Label htmlFor="platform-calendar-weekday-working" className="flex items-center gap-2">
              <Checkbox
                id="platform-calendar-weekday-working"
                checked={isWorkingDay}
                disabled={saveMutation.isPending}
                onCheckedChange={(value) => setIsWorkingDay(value === true)}
              />
              {t(calendarsCopy.isWorkingDay, locale)}
            </Label>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-calendar-weekday-starts">{t(calendarsCopy.startsAt, locale)}</Label>
            <Input
              id="platform-calendar-weekday-starts"
              type="time"
              value={startsAt}
              disabled={saveMutation.isPending}
              onChange={(event) => setStartsAt(event.currentTarget.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="platform-calendar-weekday-ends">{t(calendarsCopy.endsAt, locale)}</Label>
            <Input
              id="platform-calendar-weekday-ends"
              type="time"
              value={endsAt}
              disabled={saveMutation.isPending}
              onChange={(event) => setEndsAt(event.currentTarget.value)}
            />
          </div>
        </FormSection>
        {staleNotice && <ActionNotice message={staleNotice} />}
        {actionError && <ActionError message={actionError} />}
      </SingleRegionFormLayout>
    </PageLayout>
  )
}