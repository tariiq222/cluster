import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Plus, Rocket } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { ApiError } from '../../../api/http'
import { statusLabel } from '../../../i18n'
import {
  listBusinessCalendars,
  publishBusinessCalendar,
} from '../platform-api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { EmptyState } from '@/components/states'
import { actionAllowed, isEmptyCollection, queryResourceState } from '../section-support'
import { SectionBoundary, ActionNotice, ActionError } from '../section-state'
import { calendarsCopy, platformCopy, t } from '../platform-copy'
import type { BusinessCalendarEntity, BusinessCalendarList } from '../platform-types'

function isCalendarsEmpty(payload: BusinessCalendarList): boolean {
  return isEmptyCollection(payload.items)
}

function scopeLabel(scopeType: string, locale: 'ar' | 'en'): string {
  switch (scopeType) {
    case 'platform': return t(calendarsCopy.scopePlatform, locale)
    case 'cluster': return t(calendarsCopy.scopeCluster, locale)
    case 'facility': return t(calendarsCopy.scopeFacility, locale)
    default: return scopeType
  }
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

function workingDaysLabel(days: readonly number[] | undefined, locale: 'ar' | 'en'): string {
  if (days === undefined || days.length === 0) return '—'
  const separator = locale === 'ar' ? '، ' : ', '
  return days.map((day) => weekdayLabel(day, locale)).join(separator)
}

function statusVariant(status: string): 'outline' | 'secondary' | 'default' {
  if (status === 'published') return 'default'
  if (status === 'draft') return 'outline'
  return 'outline'
}

export function CalendarsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [pendingPublish, setPendingPublish] = useState<BusinessCalendarEntity | null>(null)

  const calendarsQuery = useQuery({
    queryKey: ['platform-calendars'],
    queryFn: () => listBusinessCalendars(csrfToken),
  })

  const data: BusinessCalendarList | null = calendarsQuery.data ?? null
  const state = queryResourceState(
    { isPending: calendarsQuery.isPending, error: calendarsQuery.error, data },
    isCalendarsEmpty,
  )

  const reload = useCallback(() => {
    void calendarsQuery.refetch()
  }, [calendarsQuery])

  const canCreate = principal.capabilities !== null
    && principal.capabilities.includes('platform_settings.calendar.manage')

  const publishMutation = useMutation({
    mutationFn: (calendar: BusinessCalendarEntity) => publishBusinessCalendar(csrfToken, calendar.id, calendar.lock_version),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setPendingPublish(null)
      setActionNotice(t(calendarsCopy.published, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-calendars'] })
    },
    onError: (error) => {
      setPendingPublish(null)
      setActionError(error instanceof ApiError
        ? error.problem.detail ?? error.problem.title ?? t(platformCopy.error, locale)
        : t(platformCopy.error, locale))
    },
  })

  const actionBusy = publishMutation.isPending

  const handleRequestPublish = useCallback((calendar: BusinessCalendarEntity) => {
    setPendingPublish(calendar)
  }, [])

  const handleConfirmPublish = useCallback(() => {
    if (pendingPublish === null) return
    publishMutation.mutate(pendingPublish)
  }, [pendingPublish, publishMutation])

  if (state !== 'ready' || data === null) {
    return (
      <SectionBoundary
        state={state}
        locale={locale}
        onRetry={reload}
        empty={
          <EmptyState
            title={t(calendarsCopy.empty, locale)}
            action={canCreate ? (
              <Button variant="outline" size="sm" disabled={actionBusy} onClick={() => navigate('/platform/calendars/new')}>
                <Plus className="size-4" aria-hidden="true" />
                {t(calendarsCopy.create, locale)}
              </Button>
            ) : undefined}
          />
        }
      />
    )
  }

  return (
    <section aria-labelledby="platform-calendars-title" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="platform-calendars-title" className="text-xl font-semibold tracking-tight">
          {t(calendarsCopy.title, locale)}
        </h2>
        {canCreate && (
          <Button variant="outline" size="sm" disabled={actionBusy} onClick={() => navigate('/platform/calendars/new')}>
            <Plus className="size-4" aria-hidden="true" />
            {t(calendarsCopy.create, locale)}
          </Button>
        )}
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center gap-2">
          <CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />
          <CardTitle>{t(calendarsCopy.title, locale)}</CardTitle>
        </CardHeader>
        <CardContent>
          {data.items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t(calendarsCopy.empty, locale)}</p>
          ) : (
            <ul className="divide-y">
              {data.items.map((calendar) => (
                <li key={calendar.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                      <span className="shrink-0">{scopeLabel(calendar.scope_type, locale)}</span>
                      <span aria-hidden="true">·</span>
                      <span
                        className="font-mono text-xs [overflow-wrap:anywhere] [word-break:break-word]"
                        dir="ltr"
                      >
                        {calendar.scope_id}
                      </span>
                      <Badge variant={statusVariant(calendar.status)}>{statusLabel(calendar.status, locale)}</Badge>
                    </p>
                    <p className="text-muted-foreground text-xs break-words">
                      {t(calendarsCopy.timezone, locale)}: {calendar.timezone ?? '—'} ·{' '}
                      {t(calendarsCopy.workingDays, locale)}: {workingDaysLabel(calendar.values?.working_days, locale)} ·{' '}
                      {t(calendarsCopy.holidays, locale)}: {calendar.values?.holidays?.length ?? 0}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {actionAllowed(calendar.allowed_actions, 'platform_settings.calendar.manage') && (
                      <>
                        <Select
                          value=""
                          disabled={actionBusy}
                          onValueChange={(value) => {
                            if (value !== '') {
                              navigate(`/platform/calendars/${calendar.id}/weekdays/${value}/edit`)
                            }
                          }}
                        >
                          <SelectTrigger className="w-44" aria-label={t(calendarsCopy.editWeekday, locale)}>
                            <SelectValue placeholder={t(calendarsCopy.editWeekday, locale)} />
                          </SelectTrigger>
                          <SelectContent>
                            {Array.from({ length: 7 }, (_, index) => (
                              <SelectItem key={index + 1} value={String(index + 1)}>
                                {weekdayLabel(index + 1, locale)}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          disabled={actionBusy}
                          onClick={() => navigate(`/platform/calendars/${calendar.id}/exceptions/new`)}
                          aria-label={t(calendarsCopy.addException, locale)}
                        >
                          <Plus className="size-3.5" aria-hidden="true" />
                        </Button>
                        {calendar.status === 'draft' && (
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={actionBusy}
                            onClick={() => handleRequestPublish(calendar)}
                          >
                            <Rocket className="size-4" aria-hidden="true" />
                            {t(calendarsCopy.publish, locale)}
                          </Button>
                        )}
                      </>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {actionNotice && <ActionNotice message={actionNotice} />}
      {actionError && <ActionError message={actionError} />}

      <AlertDialog
        open={pendingPublish !== null}
        onOpenChange={(open) => { if (!open && !publishMutation.isPending) setPendingPublish(null) }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(calendarsCopy.publishConfirmTitle, locale)}</AlertDialogTitle>
            <AlertDialogDescription>{t(calendarsCopy.publishConfirmBody, locale)}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={publishMutation.isPending}>
              {t(platformCopy.cancel, locale)}
            </AlertDialogCancel>
            <AlertDialogAction
              variant="default"
              disabled={publishMutation.isPending}
              onClick={(event) => {
                event.preventDefault()
                handleConfirmPublish()
              }}
            >
              {t(calendarsCopy.publishConfirm, locale)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </section>
  )
}
