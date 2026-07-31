import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../../ui'
import { ApiError } from '../../../api/http'
import {
  createBusinessCalendar,
  listBusinessCalendars,
  publishBusinessCalendar,
  setBusinessCalendarException,
  setBusinessCalendarWeekday,
} from '../platform-api'
import { calendarsCopy, platformCopy, t } from '../platform-copy'
import { actionAllowed, isEmptyCollection, stateFromSectionError, type SectionState } from '../section-support'
import { ActionError, ActionNotice, SectionStateView } from '../section-state'
import { statusLabel } from '../../../i18n'
import type { BusinessCalendarEntity, BusinessCalendarList } from '../platform-types'

type EditMode =
  | { kind: 'create' }
  | { kind: 'weekday'; calendar: BusinessCalendarEntity; weekday: number }
  | { kind: 'exception'; calendar: BusinessCalendarEntity; date?: string }

function statusVariant(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'published') return 'success'
  if (status === 'draft') return 'warning'
  return 'neutral'
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
  return t(calendarsCopy.days[weekday - 1] ?? calendarsCopy.days[0], locale)
}

export function CalendarsSection() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState<string | null>(null)
  const [actionNotice, setActionNotice] = useState<string | null>(null)
  const [edit, setEdit] = useState<EditMode | null>(null)
  const [scopeType, setScopeType] = useState('platform')
  const [scopeId, setScopeId] = useState('platform')
  const [isWorkingDay, setIsWorkingDay] = useState(true)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [exceptionDate, setExceptionDate] = useState('')
  const [exceptionEndsOn, setExceptionEndsOn] = useState('')
  const [exceptionType, setExceptionType] = useState('official_holiday')
  const [reason, setReason] = useState('')

  const calendarsQuery = useQuery({
    queryKey: ['platform-calendars'],
    queryFn: () => listBusinessCalendars(csrfToken),
  })

  const data: BusinessCalendarList | null = calendarsQuery.data ?? null

  let state: SectionState = 'loading'
  if (!calendarsQuery.isPending) {
    const error = calendarsQuery.error
    if (error !== null) state = stateFromSectionError(error)
    else state = isEmptyCollection(data?.items) ? 'empty' : 'ready'
  }

  const reload = useCallback(() => {
    void calendarsQuery.refetch()
  }, [calendarsQuery])

  const fail = useCallback((error: unknown) => {
    setActionError(error instanceof ApiError ? error.problem.title : t(platformCopy.error, locale))
  }, [locale])

  const openCreate = useCallback(() => {
    setScopeType('platform')
    setScopeId('platform')
    setEdit({ kind: 'create' })
  }, [])

  const openWeekday = useCallback((calendar: BusinessCalendarEntity, day: number) => {
    const working = calendar.values?.working_days ?? []
    const weekdayValues = working.includes(day) ? { isWorking: true, start: '', end: '' } : { isWorking: false, start: '', end: '' }
    setIsWorkingDay(weekdayValues.isWorking)
    setStartsAt(weekdayValues.start)
    setEndsAt(weekdayValues.end)
    setEdit({ kind: 'weekday', calendar, weekday: day })
  }, [])

  const openException = useCallback((calendar: BusinessCalendarEntity, date?: string) => {
    setExceptionDate(date ?? '')
    setExceptionEndsOn('')
    setExceptionType('official_holiday')
    setIsWorkingDay(false)
    setStartsAt('')
    setEndsAt('')
    setReason('')
    setEdit({ kind: 'exception', calendar, date })
  }, [])

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (edit === null) throw new Error('No calendar edit active')
      if (edit.kind === 'create') {
        return createBusinessCalendar(csrfToken, {
          scope_type: scopeType as 'platform' | 'cluster' | 'facility',
          scope_id: scopeType === 'platform' ? 'platform' : scopeId.trim(),
        })
      }
      const calendar = edit.calendar
      if (edit.kind === 'weekday') {
        return setBusinessCalendarWeekday(
          csrfToken,
          calendar.id,
          edit.weekday,
          { is_working_day: isWorkingDay, starts_at: startsAt || null, ends_at: endsAt || null },
          calendar.lock_version,
        )
      }
      return setBusinessCalendarException(
        csrfToken,
        calendar.id,
        exceptionDate,
        {
          type: exceptionType as 'official_holiday' | 'local_closure' | 'local_hours' | 'official_holiday_work_override' | 'ramadan',
          ends_on: exceptionEndsOn || null,
          is_working_day: isWorkingDay,
          starts_at: startsAt || null,
          ends_at: endsAt || null,
          reason: reason || null,
        },
        calendar.lock_version,
      )
    },
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setEdit(null)
      setActionNotice(t(platformCopy.refreshed, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-calendars'] })
    },
    onError: fail,
  })

  const save = useCallback(() => {
    if (edit === null) return
    saveMutation.mutate()
  }, [edit, saveMutation])

  const publishMutation = useMutation({
    mutationFn: (calendar: BusinessCalendarEntity) => publishBusinessCalendar(csrfToken, calendar.id, calendar.lock_version),
    onMutate: () => {
      setActionError(null)
      setActionNotice(null)
    },
    onSuccess: () => {
      setActionNotice(t(calendarsCopy.publish, locale))
      void queryClient.invalidateQueries({ queryKey: ['platform-calendars'] })
    },
    onError: fail,
  })

  const publish = useCallback((calendar: BusinessCalendarEntity) => {
    publishMutation.mutate(calendar)
  }, [publishMutation])

  const actionBusy = saveMutation.isPending || publishMutation.isPending

  const scopeOptions = useMemo(
    () => [
      { value: 'platform', label: t(calendarsCopy.scopePlatform, locale) },
      { value: 'cluster', label: t(calendarsCopy.scopeCluster, locale) },
      { value: 'facility', label: t(calendarsCopy.scopeFacility, locale) },
    ],
    [locale],
  )

  const exceptionOptions = useMemo(
    () => Object.entries(calendarsCopy.exceptionTypes).map(([value, label]) => ({ value, label: t(label, locale) })),
    [locale],
  )

  if (state !== 'ready' || data === null) {
    return <SectionStateView state={state} emptyTitle={t(calendarsCopy.empty, locale)} onRetry={reload} />
  }

  return (
    <>
      <Panel
        id="platform-calendars"
        title={t(calendarsCopy.title, locale)}
        actions={data.items.some((calendar) => actionAllowed(calendar.allowed_actions, 'platform_settings.calendar.manage')) ? (
          <Button variant="secondary" disabled={actionBusy} onClick={openCreate}>
            {t(calendarsCopy.create, locale)}
          </Button>
        ) : undefined}
      >
        {data.items.length === 0 ? (
          <p className="screen-list__row-meta">{t(calendarsCopy.empty, locale)}</p>
        ) : (
          <ul className="screen-list">
            {data.items.map((calendar) => (
              <li key={calendar.id} className="screen-list__row">
                <div>
                  <p className="screen-list__row-title">
                    {scopeLabel(calendar.scope_type, locale)} · {calendar.scope_id}{' '}
                    <StatusBadge variant={statusVariant(calendar.status)}>{statusLabel(calendar.status, locale)}</StatusBadge>
                  </p>
                  <p className="screen-list__row-meta">
                    {t(calendarsCopy.timezone, locale)}: {calendar.timezone ?? '—'}
                    {' · '}
                    {t(calendarsCopy.workingDays, locale)}: {calendar.values?.working_days?.map((day) => weekdayLabel(day, locale)).join('، ') || '—'}
                    {' · '}
                    {t(calendarsCopy.holidays, locale)}: {calendar.values?.holidays?.length ?? 0}
                  </p>
                </div>
                <div className="screen-list__row-actions">
                  {actionAllowed(calendar.allowed_actions, 'platform_settings.calendar.manage') && (
                    <>
                      <Select
                        id={`platform-calendar-weekday-${calendar.id}`}
                        value=""
                        onChange={(value) => { if (value !== '') openWeekday(calendar, Number(value)) }}
                        options={Array.from({ length: 7 }, (_, index) => ({ value: String(index + 1), label: weekdayLabel(index + 1, locale) }))}
                        placeholder={t(calendarsCopy.editWeekday, locale)}
                        ariaLabel={t(calendarsCopy.editWeekday, locale)}
                      />
                      <Button variant="quiet" onClick={() => openException(calendar)}>{t(calendarsCopy.addException, locale)}</Button>
                      {calendar.status === 'draft' && (
                        <Button variant="secondary" disabled={actionBusy} onClick={() => void publish(calendar)}>
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
        {actionNotice && <ActionNotice message={actionNotice} />}
        {actionError && <ActionError message={actionError} />}
      </Panel>

      <Drawer
        open={edit !== null}
        onClose={() => setEdit(null)}
        title={edit?.kind === 'create' ? t(calendarsCopy.create, locale) : edit?.kind === 'weekday' ? t(calendarsCopy.editWeekday, locale) : t(calendarsCopy.editException, locale)}
      >
        {edit?.kind === 'create' ? (
          <>
            <Field id="platform-calendar-scope-type" label={t(calendarsCopy.scopeType, locale)}>
              <Select
                id="platform-calendar-scope-type"
                value={scopeType}
                onChange={(value) => { setScopeType(value); if (value === 'platform') setScopeId('platform') }}
                options={scopeOptions}
              />
            </Field>
            <Field id="platform-calendar-scope-id" label={t(calendarsCopy.scopeId, locale)}>
              <input
                id="platform-calendar-scope-id"
                className="field__control"
                value={scopeType === 'platform' ? 'platform' : scopeId}
                disabled={scopeType === 'platform'}
                onChange={(event) => setScopeId(event.currentTarget.value)}
              />
            </Field>
          </>
        ) : edit?.kind === 'weekday' ? (
          <>
            <p className="screen-list__row-meta">{weekdayLabel(edit.weekday, locale)}</p>
            <Field id="platform-calendar-weekday-working" label={t(calendarsCopy.isWorkingDay, locale)}>
              <input
                id="platform-calendar-weekday-working"
                type="checkbox"
                checked={isWorkingDay}
                onChange={(event) => setIsWorkingDay(event.currentTarget.checked)}
              />{' '}
              {t(calendarsCopy.isWorkingDay, locale)}
            </Field>
            <Field id="platform-calendar-weekday-starts" label={t(calendarsCopy.startsAt, locale)}>
              <input
                id="platform-calendar-weekday-starts"
                className="field__control"
                type="time"
                value={startsAt}
                onChange={(event) => setStartsAt(event.currentTarget.value)}
              />
            </Field>
            <Field id="platform-calendar-weekday-ends" label={t(calendarsCopy.endsAt, locale)}>
              <input
                id="platform-calendar-weekday-ends"
                className="field__control"
                type="time"
                value={endsAt}
                onChange={(event) => setEndsAt(event.currentTarget.value)}
              />
            </Field>
          </>
        ) : edit?.kind === 'exception' ? (
          <>
            <Field id="platform-calendar-exception-date" label={t(calendarsCopy.date, locale)} required>
              <input
                id="platform-calendar-exception-date"
                className="field__control"
                type="date"
                value={exceptionDate}
                onChange={(event) => setExceptionDate(event.currentTarget.value)}
              />
            </Field>
            <Field id="platform-calendar-exception-type" label={t(calendarsCopy.type, locale)}>
              <Select
                id="platform-calendar-exception-type"
                value={exceptionType}
                onChange={setExceptionType}
                options={exceptionOptions}
              />
            </Field>
            <Field id="platform-calendar-exception-ends-on" label={t(calendarsCopy.endsOn, locale)}>
              <input
                id="platform-calendar-exception-ends-on"
                className="field__control"
                type="date"
                value={exceptionEndsOn}
                onChange={(event) => setExceptionEndsOn(event.currentTarget.value)}
              />
            </Field>
            <Field id="platform-calendar-exception-working" label={t(calendarsCopy.isWorkingDay, locale)}>
              <input
                id="platform-calendar-exception-working"
                type="checkbox"
                checked={isWorkingDay}
                onChange={(event) => setIsWorkingDay(event.currentTarget.checked)}
              />{' '}
              {t(calendarsCopy.isWorkingDay, locale)}
            </Field>
            <Field id="platform-calendar-exception-starts" label={t(calendarsCopy.startsAt, locale)}>
              <input
                id="platform-calendar-exception-starts"
                className="field__control"
                type="time"
                value={startsAt}
                onChange={(event) => setStartsAt(event.currentTarget.value)}
              />
            </Field>
            <Field id="platform-calendar-exception-ends" label={t(calendarsCopy.endsAt, locale)}>
              <input
                id="platform-calendar-exception-ends"
                className="field__control"
                type="time"
                value={endsAt}
                onChange={(event) => setEndsAt(event.currentTarget.value)}
              />
            </Field>
            <Field id="platform-calendar-exception-reason" label={t(calendarsCopy.reason, locale)}>
              <input
                id="platform-calendar-exception-reason"
                className="field__control"
                value={reason}
                onChange={(event) => setReason(event.currentTarget.value)}
              />
            </Field>
          </>
        ) : null}
        <div className="form-actions">
          <Button variant="quiet" onClick={() => setEdit(null)}>{t(platformCopy.cancel, locale)}</Button>
          <Button
            variant="primary"
            disabled={actionBusy || (edit?.kind === 'exception' && exceptionDate === '')}
            onClick={() => void save()}
          >
            {t(platformCopy.save, locale)}
          </Button>
        </div>
      </Drawer>
    </>
  )
}
