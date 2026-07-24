import { useState } from 'react'

import { ApiError } from '../../api'
import {
  cancelPlatformMaintenanceWindow,
  schedulePlatformMaintenanceWindow,
} from '../../api/platform-settings'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../ui'
import {
  isAllowed,
  screenText,
  stateGate,
  type PlatformScreenProps,
} from './screen-support'

type MaintenanceNotice =
  | { kind: 'idle' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string }

export function MaintenanceScreen({
  locale,
  state = 'success',
  allowedActions,
  resource,
  token,
}: PlatformScreenProps & { token?: string }) {
  const items = readMaintenanceItems(resource)
  const [createOpen, setCreateOpen] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [messageAr, setMessageAr] = useState('')
  const [messageEn, setMessageEn] = useState('')
  const [cancelId, setCancelId] = useState<string | null>(null)
  const [cancelLock, setCancelLock] = useState<number | null>(null)
  const [notice, setNotice] = useState<MaintenanceNotice>({ kind: 'idle' })
  const [busy, setBusy] = useState<'schedule' | 'cancel' | null>(null)
  const gate = stateGate(
    locale,
    state,
    screenText(locale, 'لا توجد نوافذ صيانة', 'No maintenance windows are scheduled'),
  )
  if (gate) return gate
  const active = items.find((item) => item.status === 'scheduled' || item.status === 'active') ?? null
  const upcoming = items.filter((item) => item.status === 'scheduled').slice(0, 5)
  const canSchedule = isAllowed(allowedActions, 'platform_operations.maintenance.manage') && token !== undefined && busy === null
  const canCancel = isAllowed(allowedActions, 'platform_operations.maintenance.cancel') && token !== undefined && busy === null

  async function submitSchedule(): Promise<void> {
    if (token === undefined) return
    setBusy('schedule')
    setNotice({ kind: 'idle' })
    try {
      await schedulePlatformMaintenanceWindow(token, {
        starts_at: startsAt,
        ends_at: endsAt === '' ? null : endsAt,
        message_ar: messageAr,
        message_en: messageEn,
      })
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تمت جدولة نافذة الصيانة.', 'Maintenance window scheduled.'),
      })
      setCreateOpen(false)
      setStartsAt('')
      setEndsAt('')
      setMessageAr('')
      setMessageEn('')
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  async function submitCancel(): Promise<void> {
    if (token === undefined || cancelId === null || cancelLock === null) return
    setBusy('cancel')
    setNotice({ kind: 'idle' })
    try {
      await cancelPlatformMaintenanceWindow(token, cancelId, cancelLock)
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم إلغاء نافذة الصيانة.', 'Maintenance window cancelled.'),
      })
      setCancelOpen(false)
      setCancelId(null)
      setCancelLock(null)
    } catch (error) {
      setNotice({ kind: 'error', message: errorMessage(error, locale) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="platform-screen">
      <Panel id="maintenance-current" title={screenText(locale, 'الحالة الحالية', 'Current status')}>
        {active === null ? (
          <p>
            {screenText(
              locale,
              'وضع الصيانة غير نشط. ستظهر رسالة ثنائية اللغة للمستخدمين المتأثرين عند التفعيل.',
              'Maintenance mode is inactive. A bilingual message will be shown to affected users when activated.',
            )}
          </p>
        ) : (
          <p>
            {screenText(
              locale,
              `نافذة نشطة من ${formatDate(active.startsAt, locale)} حتى ${formatDate(active.endsAt, locale)}.`,
              `Active window from ${formatDate(active.startsAt, locale)} until ${formatDate(active.endsAt, locale)}.`,
            )}
          </p>
        )}
        <StatusBadge variant={active === null ? 'success' : 'warning'}>
          {active === null
            ? screenText(locale, 'متاح', 'Available')
            : screenText(locale, 'صيانة نشطة', 'Maintenance active')}
        </StatusBadge>
      </Panel>
      <Panel id="maintenance-upcoming" title={screenText(locale, 'النوافذ القادمة', 'Upcoming windows')}>
        {upcoming.length === 0 ? (
          <p>{screenText(locale, 'لا توجد نوافذ قادمة.', 'No upcoming windows are scheduled.')}</p>
        ) : (
          <ul className="platform-activity-list">
            {upcoming.map((window) => (
              <li key={window.id}>
                {screenText(
                  locale,
                  `من ${formatDate(window.startsAt, locale)} حتى ${formatDate(window.endsAt, locale)}.`,
                  `From ${formatDate(window.startsAt, locale)} until ${formatDate(window.endsAt, locale)}.`,
                )}
              </li>
            ))}
          </ul>
        )}
        <div className="platform-action-row">
          {canSchedule ? (
            <Button onClick={() => setCreateOpen(true)} disabled={busy !== null}>
              {screenText(locale, 'جدولة نافذة', 'Schedule window')}
            </Button>
          ) : null}
          {canCancel && active !== null ? (
            <Button
              variant="secondary"
              onClick={() => {
                setCancelId(active.id)
                setCancelLock(active.lockVersion)
                setCancelOpen(true)
              }}
              disabled={busy !== null}
            >
              {screenText(locale, 'إلغاء النافذة الحالية', 'Cancel current window')}
            </Button>
          ) : null}
        </div>
      </Panel>
      {notice.kind === 'success' ? <p role="status">{notice.message}</p> : null}
      {notice.kind === 'error' ? (
        <p role="alert" className="platform-error">
          {notice.message}
        </p>
      ) : null}
      <Drawer
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={screenText(locale, 'جدولة نافذة صيانة', 'Schedule maintenance window')}
      >
        <Field id="maintenance-starts" label={screenText(locale, 'البدء', 'Start')}>
          <input
            id="maintenance-starts"
            type="datetime-local"
            value={startsAt}
            onChange={(event) => setStartsAt(event.target.value)}
            className="platform-input"
          />
        </Field>
        <Field id="maintenance-ends" label={screenText(locale, 'الانتهاء', 'End')}>
          <input
            id="maintenance-ends"
            type="datetime-local"
            value={endsAt}
            onChange={(event) => setEndsAt(event.target.value)}
            className="platform-input"
          />
        </Field>
        <Field id="maintenance-message-ar" label={screenText(locale, 'الرسالة بالعربية', 'Message (Arabic)')}>
          <input
            id="maintenance-message-ar"
            value={messageAr}
            onChange={(event) => setMessageAr(event.target.value)}
            className="platform-input"
          />
        </Field>
        <Field id="maintenance-message-en" label={screenText(locale, 'الرسالة بالإنجليزية', 'Message (English)')}>
          <input
            id="maintenance-message-en"
            value={messageEn}
            onChange={(event) => setMessageEn(event.target.value)}
            className="platform-input"
          />
        </Field>
        <div className="platform-action-row">
          <Button
            onClick={() => void submitSchedule()}
            disabled={
              busy !== null ||
              startsAt === '' ||
              messageAr.trim() === '' ||
              messageEn.trim() === ''
            }
          >
            {screenText(locale, 'جدولة', 'Schedule')}
          </Button>
          <Button variant="quiet" onClick={() => setCreateOpen(false)} disabled={busy !== null}>
            {screenText(locale, 'إلغاء', 'Cancel')}
          </Button>
        </div>
      </Drawer>
      <Drawer
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={screenText(locale, 'إلغاء نافذة الصيانة', 'Cancel maintenance window')}
      >
        <p>
          {screenText(
            locale,
            'سيتم إرسال طلب إلغاء النافذة مع رقم الإصدار الحالي.',
            'The cancel request will be sent with the current lock version.',
          )}
        </p>
        <div className="platform-action-row">
          <Button onClick={() => void submitCancel()} disabled={busy !== null}>
            {screenText(locale, 'تأكيد الإلغاء', 'Confirm cancel')}
          </Button>
          <Button variant="quiet" onClick={() => setCancelOpen(false)} disabled={busy !== null}>
            {screenText(locale, 'رجوع', 'Back')}
          </Button>
        </div>
      </Drawer>
    </div>
  )
}

function readMaintenanceItems(resource: PlatformScreenProps['resource']): MaintenanceItem[] {
  if (resource === undefined) return []
  if (!('items' in resource) || !Array.isArray(resource.items)) return []
  const items: MaintenanceItem[] = []
  for (const entry of resource.items) {
    if (typeof entry !== 'object' || entry === null) continue
    const startsAt = readString((entry as { starts_at?: unknown }).starts_at)
    const status = readString((entry as { status?: unknown }).status)
    const id = readString((entry as { id?: unknown }).id)
    if (id === undefined || startsAt === undefined || status === undefined) continue
    items.push({
      id,
      status,
      startsAt,
      endsAt: readString((entry as { ends_at?: unknown }).ends_at),
      lockVersion: readNumber((entry as { lock_version?: unknown }).lock_version) ?? 1,
    })
  }
  return items
}

type MaintenanceItem = {
  id: string
  status: string
  startsAt: string
  endsAt: string | undefined
  lockVersion: number
}

function readString(value: unknown): string | undefined {
  return typeof value === 'string' ? value : undefined
}

function readNumber(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined
}

function formatDate(value: string | undefined, locale: 'ar' | 'en'): string {
  if (value === undefined) return locale === 'ar' ? 'مفتوح' : 'open'
  try {
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value))
  } catch {
    return value
  }
}

function errorMessage(error: unknown, locale: 'ar' | 'en'): string {
  if (error instanceof ApiError) {
    return error.problem.detail ?? error.problem.title
  }
  return locale === 'ar'
    ? 'فشل تشغيل العملية.'
    : 'The operation could not be completed.'
}
