import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useNavigate } from '../../app/navigation-context'
import { ApiError, stateFromError } from '../../api/http'
import { listBusinessCalendars, setBusinessCalendarException } from './platform-api'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
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
import { calendarsCopy, platformCopy, alertsCopy, t } from './platform-copy'
import type { BusinessCalendarList } from './platform-types'
import { PlatformBackButton } from './platform-page-back'

/*
 * Full-page replacement for the former add-exception Sheet
 * (route `/platform/calendars/:calendarId/exceptions/new`).
 *
 * The calendar is loaded by id so the lock_version is observed at save
 * time; a missing calendar renders the shared non-disclosing alert with a
 * back link. The exception options and field set mirror the Sheet's
 * exception branch (type select, date/ends_on, working day, hours,
 * reason). A 412 conflict keeps the inputs, shows the stale alert, and
 * reloads the calendar list.
 *
 * The form has enough fields to warrant a live review summary
 * (DESIGN-RULES §2.7), so the page uses `TwoRegionFormLayout` with the
 * intake surface on the left and the review + action stack on the right.
 */

const REASON_MAX = 2000

interface CalendarExceptionCreateScreenProps {
  calendarId: string
  date?: string
}

export function CalendarExceptionCreateScreen({ calendarId, date }: CalendarExceptionCreateScreenProps) {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [exceptionDate, setExceptionDate] = useState(date ?? '')
  const [exceptionEndsOn, setExceptionEndsOn] = useState('')
  const [exceptionType, setExceptionType] = useState('official_holiday')
  const [isWorkingDay, setIsWorkingDay] = useState(false)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [reason, setReason] = useState('')
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
    if (date !== undefined && exceptionDate === '') {
      setExceptionDate(date)
    }
  }, [calendar, date, exceptionDate])

  const exceptionOptions = useMemo(
    () => Object.entries(calendarsCopy.exceptionTypes).map(([value, label]) => ({ value, label: t(label, locale) })),
    [locale],
  )

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
      return setBusinessCalendarException(
        csrfToken,
        calendarId,
        exceptionDate,
        {
          type: exceptionType as 'official_holiday' | 'local_closure' | 'local_hours' | 'official_holiday_work_override' | 'ramadan',
          ends_on: exceptionEndsOn || null,
          is_working_day: isWorkingDay,
          starts_at: startsAt || null,
          ends_at: endsAt || null,
          reason: reason === '' ? null : reason,
        },
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
    if (exceptionDate === '') {
      setActionError(t(calendarsCopy.exceptionDateRequired, locale))
      return
    }
    if (exceptionEndsOn !== '' && exceptionEndsOn < exceptionDate) {
      setActionError(t(calendarsCopy.dateRangeInvalid, locale))
      return
    }
    if (startsAt !== '' && endsAt !== '' && endsAt <= startsAt) {
      setActionError(t(calendarsCopy.timeRangeInvalid, locale))
      return
    }
    if (reason.length > REASON_MAX) {
      setActionError(t(calendarsCopy.reasonTooLong, locale))
      return
    }
    saveMutation.mutate()
  }, [exceptionDate, exceptionEndsOn, startsAt, endsAt, reason, saveMutation, locale])

  if (!canManage) {
    return (
      <PageLayout data-testid="calendar-exception-create-screen">
        <DeniedState locale={locale} />
      </PageLayout>
    )
  }

  if (calendarsQuery.isPending) {
    return (
      <PageLayout data-testid="calendar-exception-create-screen">
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
      <PageLayout data-testid="calendar-exception-create-screen">
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
      <PageLayout data-testid="calendar-exception-create-screen">
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

  const exceptionTypeLabel = (() => {
    const entry = calendarsCopy.exceptionTypes[exceptionType as keyof typeof calendarsCopy.exceptionTypes]
    return entry !== undefined ? t(entry, locale) : exceptionType
  })()

  const reviewRows = [
    {
      label: t(calendarsCopy.date, locale),
      value: exceptionDate === '' ? null : exceptionDate,
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
    {
      label: t(calendarsCopy.type, locale),
      value: exceptionTypeLabel,
    },
    {
      label: t(calendarsCopy.endsOn, locale),
      value: exceptionEndsOn === '' ? null : exceptionEndsOn,
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
    {
      label: t(calendarsCopy.isWorkingDay, locale),
      value: isWorkingDay
        ? t(alertsCopy.enabled, locale)
        : t(alertsCopy.disabled, locale),
    },
    {
      label: t(calendarsCopy.startsAt, locale),
      value: startsAt === '' ? null : startsAt,
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
    {
      label: t(calendarsCopy.endsAt, locale),
      value: endsAt === '' ? null : endsAt,
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
    {
      label: t(calendarsCopy.reason, locale),
      value: reason === '' ? null : reason,
      empty: t(platformCopy.formReviewEmptyFallback, locale),
      isolate: true,
    },
  ]

  return (
    <PageLayout data-testid="calendar-exception-create-screen">
      <div>
        <PlatformBackButton label={t(platformCopy.backToCalendars, locale)} onBack={back} locale={locale} />
      </div>

      <PageHeader
        title={t(calendarsCopy.addException, locale)}
        description={t(calendarsCopy.editExceptionIntro, locale)}
        headingId="calendar-exception-create-title"
      />

      <TwoRegionFormLayout
        testId="calendar-exception-create-form"
        mainTestId="calendar-exception-create-main"
        reviewTestId="calendar-exception-create-review"
        onSubmit={(event) => {
          event.preventDefault()
          handleSave()
        }}
        main={
          <>
            <FormSection
              headingId="calendar-exception-create-section-window"
              title={t(platformCopy.formSectionException, locale)}
            >
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-date">{t(calendarsCopy.date, locale)}</Label>
                <Input
                  id="platform-calendar-exception-date"
                  type="date"
                  value={exceptionDate}
                  disabled={saveMutation.isPending}
                  onChange={(event) => setExceptionDate(event.currentTarget.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-type">{t(calendarsCopy.type, locale)}</Label>
                <Select value={exceptionType} onValueChange={setExceptionType} disabled={saveMutation.isPending}>
                  <SelectTrigger id="platform-calendar-exception-type" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {exceptionOptions.map((option) => (
                      <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-ends-on">{t(calendarsCopy.endsOn, locale)}</Label>
                <Input
                  id="platform-calendar-exception-ends-on"
                  type="date"
                  value={exceptionEndsOn}
                  disabled={saveMutation.isPending}
                  onChange={(event) => setExceptionEndsOn(event.currentTarget.value)}
                />
              </div>
            </FormSection>
            <FormSection
              headingId="calendar-exception-create-section-schedule"
              title={t(calendarsCopy.startsAt, locale) + ' · ' + t(calendarsCopy.endsAt, locale)}
              divided
            >
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-working" className="flex items-center gap-2">
                  <Checkbox
                    id="platform-calendar-exception-working"
                    checked={isWorkingDay}
                    disabled={saveMutation.isPending}
                    onCheckedChange={(value) => setIsWorkingDay(value === true)}
                  />
                  {t(calendarsCopy.isWorkingDay, locale)}
                </Label>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-starts">{t(calendarsCopy.startsAt, locale)}</Label>
                <Input
                  id="platform-calendar-exception-starts"
                  type="time"
                  value={startsAt}
                  disabled={saveMutation.isPending}
                  onChange={(event) => setStartsAt(event.currentTarget.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-ends">{t(calendarsCopy.endsAt, locale)}</Label>
                <Input
                  id="platform-calendar-exception-ends"
                  type="time"
                  value={endsAt}
                  disabled={saveMutation.isPending}
                  onChange={(event) => setEndsAt(event.currentTarget.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="platform-calendar-exception-reason">{t(calendarsCopy.reason, locale)}</Label>
                <Input
                  id="platform-calendar-exception-reason"
                  value={reason}
                  maxLength={REASON_MAX}
                  disabled={saveMutation.isPending}
                  onChange={(event) => setReason(event.currentTarget.value)}
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
              headingId="calendar-exception-create-review-heading"
              title={t(platformCopy.formReviewHeading, locale)}
              density="tight"
            >
              <ReviewSummary rows={reviewRows} testId="calendar-exception-create-review-summary" />
            </FormSection>
            <FormActionStack testId="calendar-exception-create-actions">
              <Button type="button" variant="outline" onClick={back} disabled={saveMutation.isPending}>
                {t(platformCopy.cancel, locale)}
              </Button>
              <Button type="submit" disabled={saveMutation.isPending}>
                {t(platformCopy.save, locale)}
              </Button>
            </FormActionStack>
          </>
        }
      />
    </PageLayout>
  )
}