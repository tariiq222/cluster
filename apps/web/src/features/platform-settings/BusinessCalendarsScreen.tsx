import { useState } from 'react'
import { CalendarDays } from 'lucide-react'

import { ApiError } from '../../api'
import {
  createBusinessCalendar,
  publishBusinessCalendar,
  setBusinessCalendarException,
  setBusinessCalendarWeekday,
  type BusinessCalendarCreateInput,
} from '../../api/platform-settings'
import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../ui'
import {
  isAllowed,
  screenText,
  stateGate,
  type PlatformScreenProps,
} from './screen-support'

export type BusinessCalendarsScreenProps = PlatformScreenProps & {
  token?: string
}

type Notice =
  | { kind: 'idle' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string }

function firstCalendar(resource: PlatformScreenProps['resource']): {
  id?: string
  lockVersion?: number
  workingDays: readonly string[]
  workingHours: string
  weekends: readonly string[]
  holidays: readonly string[]
} | null {
  if (resource === undefined || !('items' in resource)) return null
  const items = (resource as { items?: unknown }).items
  if (!Array.isArray(items) || items.length === 0) return null
  const first = items[0]
  if (typeof first !== 'object' || first === null) return null
  const record = first as Record<string, unknown>
  const values = (record.values && typeof record.values === 'object') ? record.values as Record<string, unknown> : {}
  const id = typeof record.id === 'string' ? record.id : undefined
  const lockVersion = typeof record.lock_version === 'number' ? record.lock_version : undefined
  const workingDays = Array.isArray(values.working_days) ? values.working_days as readonly string[] : []
  const weekends = Array.isArray(values.weekends) ? values.weekends as readonly string[] : []
  const holidays = Array.isArray(values.holidays) ? values.holidays as readonly string[] : []
  const workingHours = typeof values.working_hours === 'string' ? values.working_hours : '08:00–16:00'
  return { id, lockVersion, workingDays, workingHours, weekends, holidays }
}

function errorMessage(error: unknown, locale: 'ar' | 'en'): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title
  }
  return locale === 'ar'
    ? 'فشل تشغيل العملية.'
    : 'The operation could not be completed.'
}

type CalendarExceptionType =
  | 'official_holiday'
  | 'local_closure'
  | 'local_hours'
  | 'official_holiday_work_override'
  | 'ramadan'

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export function BusinessCalendarsScreen({
  locale,
  state = 'success',
  allowedActions,
  resource,
  token,
}: BusinessCalendarsScreenProps) {
  const [scope, setScope] = useState<'platform' | 'cluster' | 'facility'>('platform')
  const [scopeId, setScopeId] = useState('')
  const [overrideOpen, setOverrideOpen] = useState(false)
  const [overrideDate, setOverrideDate] = useState('')
  const [overrideReason, setOverrideReason] = useState('')
  const [overrideType, setOverrideType] = useState<CalendarExceptionType>('official_holiday_work_override')
  const [overrideStart, setOverrideStart] = useState('08:00')
  const [overrideEnd, setOverrideEnd] = useState('16:00')
  const [overrideWorking, setOverrideWorking] = useState(true)
  const [notice, setNotice] = useState<Notice>({ kind: 'idle' })
  const [busy, setBusy] = useState<'create' | 'exception' | 'publish' | null>(null)
  // Local override for the calendar that was just created in this
  // session, since the parent `resource` is not reloaded after
  // mutations. Cleared on scope change.
  const [localCalendar, setLocalCalendar] = useState<{ id: string; lockVersion: number; values: Record<string, unknown> } | null>(null)
  const [liveLockVersion, setLiveLockVersion] = useState<number | null>(null)

  const gate = stateGate(locale, state, screenText(locale, 'لا يوجد تقويم في هذا النطاق', 'No calendar exists in this scope'))
  if (gate) return gate
  const canOverride = isAllowed(allowedActions, 'platform_settings.calendar.override_official_holiday')
  const scopeIdValid = scope === 'platform' || UUID_V7_PATTERN.test(scopeId.trim())
  const canCreate = token !== undefined && isAllowed(allowedActions, 'platform_settings.calendar.manage')
  const canPublish = token !== undefined && busy === null && isAllowed(allowedActions, 'platform_settings.calendar.publish')
  const canApply = token !== undefined && busy === null && isAllowed(allowedActions, 'platform_settings.calendar.manage')
  const calendarFromResource = firstCalendar(resource)
  const calendar = localCalendar !== null
    ? { id: localCalendar.id, lockVersion: localCalendar.lockVersion, workingDays: [] as readonly string[], workingHours: '08:00–16:00', weekends: [] as readonly string[], holidays: [] as readonly string[] }
    : calendarFromResource
  const calendarId = calendar?.id
  const lockVersion = liveLockVersion ?? calendar?.lockVersion ?? null
  const workWeekLabel = calendar !== null && calendar.workingDays.length > 0
    ? calendar.workingDays.map((day) => `${day}، ${calendar.workingHours}`).join(' / ')
    : screenText(locale, 'لم يتم تعريف أيام العمل بعد', 'No working days defined yet')
  const weekendLabel = calendar !== null && calendar.weekends.length > 0
    ? calendar.weekends.map((day) => `${day}، ${screenText(locale, 'عطلة', 'non-working')}`).join(' / ')
    : screenText(locale, 'لا توجد عطلات أسبوعية محددة', 'No weekend days defined')
  const holidays = calendar?.holidays ?? []

  function createInput(): BusinessCalendarCreateInput {
    if (scope === 'platform') return { scope_type: 'platform', scope_id: 'platform' }
    const enteredScopeId = scopeId.trim()
    if (scope === 'cluster') return { scope_type: 'cluster', scope_id: enteredScopeId }
    return { scope_type: 'facility', scope_id: enteredScopeId }
  }

  async function createCalendar(): Promise<void> {
    if (token === undefined || busy !== null || !scopeIdValid) return
    setBusy('create')
    setNotice({ kind: 'idle' })
    try {
      const response = await createBusinessCalendar(token, createInput())
      const created = response as unknown as { id?: string; lock_version?: number; values?: Record<string, unknown> }
      if (typeof created.id === 'string' && created.id.length > 0) {
        setLocalCalendar({ id: created.id, lockVersion: created.lock_version ?? 1, values: created.values ?? {} })
      }
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم إنشاء التقويم بنجاح.', 'Calendar created successfully.'),
      })
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function applyException(): Promise<void> {
    if (token === undefined || calendarId === undefined || lockVersion === null || busy !== null) return
    if (overrideDate === '' || (overrideWorking && (overrideStart === '' || overrideEnd === ''))) {
      setNotice({
        kind: 'error',
        message: screenText(locale, 'يجب إدخال التاريخ والوقت.', 'Date and times are required.'),
      })
      return
    }
    setBusy('exception')
    setNotice({ kind: 'idle' })
    try {
      const response = await setBusinessCalendarException(token, calendarId, overrideDate, {
        type: overrideType,
        is_working_day: overrideWorking,
        starts_at: overrideWorking ? overrideStart : '',
        ends_at: overrideWorking ? overrideEnd : '',
      }, lockVersion)
      const updated = response as unknown as { lock_version?: number }
      if (typeof updated.lock_version === 'number') {
        setLiveLockVersion(updated.lock_version)
      }
      setOverrideOpen(false)
      setOverrideWorking(true)
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم تسجيل الاستثناء.', 'Exception recorded.'),
      })
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function publishCalendar(): Promise<void> {
    if (token === undefined || calendarId === undefined || lockVersion === null || busy !== null) return
    setBusy('publish')
    setNotice({ kind: 'idle' })
    try {
      const response = await publishBusinessCalendar(token, calendarId, lockVersion)
      const updated = response as unknown as { lock_version?: number }
      if (typeof updated.lock_version === 'number') {
        setLiveLockVersion(updated.lock_version)
      }
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم نشر التقويم.', 'Calendar published.'),
      })
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function applyWeekday(weekday: number, isWorking: boolean): Promise<void> {
    if (token === undefined || calendarId === undefined || lockVersion === null || busy !== null) return
    setBusy('exception')
    setNotice({ kind: 'idle' })
    try {
      const response = await setBusinessCalendarWeekday(token, calendarId, weekday, {
        is_working_day: isWorking,
        starts_at: isWorking ? '08:00' : '',
        ends_at: isWorking ? '16:00' : '',
      }, lockVersion)
      const updated = response as unknown as { lock_version?: number }
      if (typeof updated.lock_version === 'number') {
        setLiveLockVersion(updated.lock_version)
      }
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم تحديث اليوم.', 'Day updated.'),
      })
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="platform-screen" data-token={token === undefined ? 'mock' : 'live'}>
      <Panel id="calendar-scope" title={screenText(locale, 'نطاق التقويم', 'Calendar scope')}>
        <Field id="calendar-scope-select" label={screenText(locale, 'النطاق', 'Scope')}>
          <Select
            id="calendar-scope-select"
            value={scope}
            onChange={(value) => {
              setScope(value as 'platform' | 'cluster' | 'facility')
              setScopeId('')
              setLocalCalendar(null)
              setLiveLockVersion(null)
            }}
            options={[
              { value: 'platform', label: screenText(locale, 'المنصة', 'Platform') },
              { value: 'cluster', label: screenText(locale, 'التجمع', 'Cluster') },
              { value: 'facility', label: screenText(locale, 'المنشأة', 'Facility') },
            ]}
            ariaLabel={screenText(locale, 'نطاق التقويم', 'Calendar scope')}
          />
        </Field>
        {scope !== 'platform' ? (
          <Field
            id="calendar-scope-id"
            label={screenText(locale, 'معرّف النطاق', 'Scope ID')}
            required
            error={scopeId !== '' && !scopeIdValid
              ? screenText(locale, 'أدخل معرّف UUID صالحاً.', 'Enter a valid UUID.')
              : undefined}
          >
            <input
              id="calendar-scope-id"
              type="text"
              dir="ltr"
              required
              aria-required="true"
              aria-invalid={scopeId !== '' && !scopeIdValid}
              aria-describedby={scopeId !== '' && !scopeIdValid ? 'calendar-scope-id-error' : undefined}
              value={scopeId}
              onChange={(event) => setScopeId(event.target.value)}
            />
          </Field>
        ) : null}
        {canCreate || canPublish ? (
          <div className="platform-action-row">
            {canCreate ? (
              <Button onClick={() => void createCalendar()} disabled={busy !== null || !scopeIdValid}>
                {screenText(locale, 'إنشاء تقويم', 'Create calendar')}
              </Button>
            ) : null}
            {canPublish ? (
              <Button variant="secondary" onClick={() => void publishCalendar()} disabled={busy !== null || calendarId === undefined}>
                {screenText(locale, 'نشر التقويم', 'Publish calendar')}
              </Button>
            ) : null}
          </div>
        ) : null}
      </Panel>
      <Panel id="calendar-workweek" title={screenText(locale, 'أسبوع العمل', 'Working week')}>
        <ul className="platform-status-list">
          <li>
            <CalendarDays aria-hidden="true" />
            <span>{workWeekLabel}</span>
            <StatusBadge variant="info">{screenText(locale, 'مصدر: الخادم', 'Source: server')}</StatusBadge>
          </li>
          <li>
            <CalendarDays aria-hidden="true" />
            <span>{weekendLabel}</span>
            <StatusBadge variant="neutral">{screenText(locale, 'مصدر: الخادم', 'Source: server')}</StatusBadge>
          </li>
        </ul>
        {canApply ? (
          <div className="platform-action-row">
            <Button variant="quiet" onClick={() => void applyWeekday(1, true)} disabled={busy !== null || calendarId === undefined}>
              {screenText(locale, 'تفعيل الإثنين', 'Mark Monday working')}
            </Button>
            <Button variant="quiet" onClick={() => void applyWeekday(7, false)} disabled={busy !== null || calendarId === undefined}>
              {screenText(locale, 'تعطيل الأحد', 'Mark Sunday off')}
            </Button>
          </div>
        ) : null}
      </Panel>
      <Panel id="calendar-exceptions" title={screenText(locale, 'العطل والفترات الموسمية', 'Holidays and seasonal periods')}>
        <ul className="platform-activity-list">
          {holidays.length === 0 ? (
            <li>{screenText(locale, 'لا توجد عطلات مسجلة.', 'No holidays recorded.')}</li>
          ) : (
            holidays.map((holiday) => (
              <li key={holiday}>{holiday}</li>
            ))
          )}
        </ul>
        {canOverride ? (
          <Button variant="secondary" onClick={() => setOverrideOpen(true)} disabled={calendarId === undefined}>
            {screenText(locale, 'طلب العمل أثناء عطلة رسمية', 'Request official-holiday work')}
          </Button>
        ) : null}
      </Panel>
      {notice.kind === 'success' ? (
        <div role="status" className="platform-notice platform-notice--success">{notice.message}</div>
      ) : null}
      {notice.kind === 'error' ? (
        <div role="alert" className="platform-notice platform-notice--error">{notice.message}</div>
      ) : null}
      <Drawer
        open={overrideOpen}
        onClose={() => setOverrideOpen(false)}
        title={screenText(locale, 'سبب العمل أثناء العطلة', 'Reason for official-holiday work')}
      >
        <Field id="calendar-exception-date" label={screenText(locale, 'التاريخ', 'Date')}>
          <input id="calendar-exception-date" type="date" value={overrideDate} onChange={(event) => setOverrideDate(event.target.value)} />
        </Field>
        <Field id="calendar-exception-type" label={screenText(locale, 'النوع', 'Type')}>
          <Select
            id="calendar-exception-type"
            value={overrideType}
            onChange={(value) => setOverrideType(value as CalendarExceptionType)}
            options={[
              { value: 'official_holiday', label: screenText(locale, 'عطلة رسمية', 'Official holiday') },
              { value: 'local_closure', label: screenText(locale, 'إغلاق محلي', 'Local closure') },
              { value: 'local_hours', label: screenText(locale, 'ساعات محلية', 'Local hours') },
              { value: 'official_holiday_work_override', label: screenText(locale, 'عمل أثناء عطلة رسمية', 'Official-holiday work override') },
              { value: 'ramadan', label: screenText(locale, 'رمضان', 'Ramadan') },
            ]}
            ariaLabel={screenText(locale, 'نوع الاستثناء', 'Exception type')}
          />
        </Field>
        <Field id="calendar-exception-working" label={screenText(locale, 'يوم عمل', 'Working day')}>
          <input
            id="calendar-exception-working"
            type="checkbox"
            checked={overrideWorking}
            onChange={(event) => setOverrideWorking(event.target.checked)}
          />
        </Field>
        <Field id="calendar-exception-start" label={screenText(locale, 'وقت البدء', 'Start time')}>
          <input id="calendar-exception-start" type="time" value={overrideStart} onChange={(event) => setOverrideStart(event.target.value)} />
        </Field>
        <Field id="calendar-exception-end" label={screenText(locale, 'وقت الانتهاء', 'End time')}>
          <input id="calendar-exception-end" type="time" value={overrideEnd} onChange={(event) => setOverrideEnd(event.target.value)} />
        </Field>
        <Field id="calendar-exception-reason" label={screenText(locale, 'السبب', 'Reason')}>
          <input id="calendar-exception-reason" type="text" value={overrideReason} onChange={(event) => setOverrideReason(event.target.value)} />
        </Field>
        <div className="platform-action-row">
          <Button onClick={() => void applyException()} disabled={busy !== null}>
            {screenText(locale, 'تأكيد الطلب', 'Confirm request')}
          </Button>
          <Button variant="quiet" onClick={() => setOverrideOpen(false)}>{screenText(locale, 'إلغاء', 'Cancel')}</Button>
        </div>
      </Drawer>
    </div>
  )
}
