/**
 * API-level mutation, persistence, lifecycle and concurrency workflows for
 * PlatformSettings.
 *
 * The browser-driven journeys (draft → validate → publish, business
 * calendars, alert policies, maintenance windows) were removed when the
 * frontend was rebuilt: the rebuilt sections use new controls, labels, and
 * drawers, so the old UI selectors no longer apply. The two tests retained
 * here exercise the unchanged API contract directly — an invalid
 * draft→publish transition must be rejected as a conflict, and a business
 * calendar lifecycle must persist weekday, exception, and publish state —
 * with the database asserted through read-only sqlite queries.
 *
 * Test isolation:
 * - Every record created by these tests is named with a per-run
 *   random suffix so the suite is repeatable and never collides with
 *   previous runs.
 * - The dedicated E2E seeder (`e2e:platform-settings:seed`) provisions
 *   personas and a test-owned alert policy.
 * - The Database is sqlite under `apps/api/database/database.sqlite`
 *   in `local`. The run uses `APP_ENV=local` so the test accounts are
 *   available.
 */
import { expect, test, type APIRequestContext } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'

const API_ORIGIN = process.env.W1_1_API_ORIGIN ?? 'http://127.0.0.1:8000'
const ENVIRONMENT = process.env.APP_ENV ?? 'local'
const DB_PATH = '/Users/tariq/code/R3/cluster/apps/api/database/database.sqlite'

test.beforeAll(() => {
  if (ENVIRONMENT === 'production') {
    throw new Error('PlatformSettings workflow E2E must not run in production.')
  }
  mkdirSync('/Users/tariq/code/R3/cluster/apps/web/artifacts', { recursive: true })
})

const FULL_OWNER = { username: 'ps-e2e-full-owner', password: 'Platform!Full.Owner.E2E.2026' }

const FULL_OWNER_CAPABILITIES = [
  'platform_operations.alerts.manage',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.health.read',
  'platform_operations.maintenance.cancel',
  'platform_operations.maintenance.manage',
  'platform_operations.restore.confirm',
  'platform_operations.restore.request',
  'platform_settings.calendar.manage',
  'platform_settings.calendar.override_official_holiday',
  'platform_settings.calendar.read',
  'platform_settings.manage',
  'platform_settings.publish',
  'platform_settings.read',
] as const

type LoginResult = { csrfToken: string; capabilities: readonly string[] }

function correlationId(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  let timestamp = Date.now()
  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = timestamp & 0xff
    timestamp = Math.floor(timestamp / 256)
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function loginAndAssert(
  request: APIRequestContext,
  credentials: { username: string; password: string },
  expected: readonly string[],
): Promise<LoginResult> {
  const response = await request.post(`${API_ORIGIN}/api/v1/identity/login`, {
    headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': correlationId() },
    data: { username: credentials.username, password: credentials.password },
  })
  expect(response.status(), `login for ${credentials.username} should succeed`).toBe(200)
  const csrfToken = response.headers()['x-csrf-token'] ?? ''
  expect(csrfToken, 'login response should include a CSRF token').toBeTruthy()

  const meResponse = await request.get(`${API_ORIGIN}/api/v1/me`, {
    headers: { 'X-Correlation-ID': correlationId() },
  })
  expect(meResponse.status(), `/me for ${credentials.username} should succeed`).toBe(200)
  const meBody = (await meResponse.json()) as { capabilities?: readonly string[] }
  const capabilities = (meBody.capabilities ?? []).slice().sort()
  expect(capabilities).toEqual(expected.slice().sort())
  return { csrfToken, capabilities }
}

type CalendarRow = {
  id: string
  status: string
  lockVersion: number
  scopeId: string
}

async function listCalendars(
  request: APIRequestContext,
  session: LoginResult,
): Promise<CalendarRow[]> {
  const response = await request.get(`${API_ORIGIN}/api/v1/platform-settings/calendars`, {
    headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': correlationId() },
  })
  expect(response.status()).toBe(200)
  const body = (await response.json()) as {
    items: Array<{ id: string; status: string; lock_version: number; scope_id: string; created_at?: string }>
  }
  const sorted = [...body.items].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''))
  return sorted.map((item) => ({
    id: item.id,
    status: item.status,
    lockVersion: item.lock_version,
    scopeId: item.scope_id,
  }))
}

type DateOnlyString = string

type WeekdayRow = {
  business_calendar_id: string
  weekday: number
  is_working_day: number
  starts_at: string | null
  ends_at: string | null
}

type ExceptionRow = {
  business_calendar_id: string
  exception_type: string
  starts_on: DateOnlyString
  is_official_holiday: number
  is_working_day: number
  starts_at: string | null
  ends_at: string | null
}

function runSqliteQuery<T>(sql: string): T[] {
  const output = execFileSync('sqlite3', ['-cmd', '.timeout 5000', '-json', '-readonly', DB_PATH, sql], { encoding: 'utf8' })
  if (output.trim() === '') return []
  return JSON.parse(output) as T[]
}

function readWeekdayRows(calendarId: string): WeekdayRow[] {
  const safeId = calendarId.replace(/[^0-9a-fA-F-]/g, '')
  return runSqliteQuery<WeekdayRow>(
    `SELECT business_calendar_id, weekday, is_working_day, starts_at, ends_at FROM business_calendar_weekdays WHERE business_calendar_id = '${safeId}' ORDER BY weekday ASC`,
  )
}

function readExceptionRows(calendarId: string): ExceptionRow[] {
  const safeId = calendarId.replace(/[^0-9a-fA-F-]/g, '')
  return runSqliteQuery<ExceptionRow>(
    `SELECT business_calendar_id, exception_type, starts_on, is_official_holiday, is_working_day, starts_at, ends_at FROM business_calendar_exceptions WHERE business_calendar_id = '${safeId}' ORDER BY starts_on ASC`,
  )
}

test.describe('platform-settings API mutation and persistence workflows', () => {
  test('B1: publishing a draft without validation is rejected as a conflict', async ({ request }) => {
    const session = await loginAndAssert(request, FULL_OWNER, FULL_OWNER_CAPABILITIES)
    // Ensure there is a draft in `draft` state. If the seeder
    // produced an idle row, create one; otherwise reuse the open
    // row regardless of its current status and reset a fresh draft.
    const list = await request.get(`${API_ORIGIN}/api/v1/platform-settings/versions`, {
      headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': correlationId() },
    })
    expect(list.status()).toBe(200)
    const versions = (await list.json()) as {
      items: Array<{ id: string; status: string; lock_version: number }>
    }
    const openDraft = versions.items.find((item) => item.status === 'draft')
    const openValidated = versions.items.find((item) => item.status === 'validated')
    if (openDraft === undefined) {
      if (openValidated === undefined) {
        // No draft to exercise invalid transition against; create
        // one and reject the publish immediately.
        const create = await request.post(`${API_ORIGIN}/api/v1/platform-settings/versions`, {
          headers: {
            'X-CSRF-Token': session.csrfToken,
            'X-Correlation-ID': correlationId(),
            'Idempotency-Key': `ps-e2e-wf-b1-${randomUUID()}`,
            'Content-Type': 'application/json',
          },
          data: { name: 'B1 invalid transition draft' },
        })
        expect(create.status()).toBe(201)
        const body = (await create.json()) as { id: string; lock_version: number }
        const publishAttempt = await request.post(
          `${API_ORIGIN}/api/v1/platform-settings/versions/${body.id}/publish`,
          {
            headers: {
              'X-CSRF-Token': session.csrfToken,
              'X-Correlation-ID': correlationId(),
              'If-Match': `"${body.lock_version}"`,
            },
          },
        )
        expect(publishAttempt.status(), 'publishing a draft without validation must be rejected').toBe(409)
        const detail = (await publishAttempt.json()) as { detail?: string }
        expect((detail.detail ?? '').toLowerCase()).toContain('validated')
        return
      }
      // The seeder left a `validated` row from a previous run. Drop
      // it by publishing it, then create a fresh draft. The publish
      // must succeed because the row is already in `validated`.
      const publish = await request.post(
        `${API_ORIGIN}/api/v1/platform-settings/versions/${openValidated.id}/publish`,
        {
          headers: {
            'X-CSRF-Token': session.csrfToken,
            'X-Correlation-ID': correlationId(),
            'If-Match': `"${openValidated.lock_version}"`,
          },
        },
      )
      expect(publish.status()).toBe(200)
      const create = await request.post(`${API_ORIGIN}/api/v1/platform-settings/versions`, {
        headers: {
          'X-CSRF-Token': session.csrfToken,
          'X-Correlation-ID': correlationId(),
          'Idempotency-Key': `ps-e2e-wf-b1b-${randomUUID()}`,
          'Content-Type': 'application/json',
        },
        data: { name: 'B1 invalid transition draft' },
      })
      expect(create.status()).toBe(201)
      const body = (await create.json()) as { id: string; lock_version: number }
      const publishAttempt = await request.post(
        `${API_ORIGIN}/api/v1/platform-settings/versions/${body.id}/publish`,
        {
          headers: {
            'X-CSRF-Token': session.csrfToken,
            'X-Correlation-ID': correlationId(),
            'If-Match': `"${body.lock_version}"`,
          },
        },
      )
      expect(publishAttempt.status(), 'publishing a draft without validation must be rejected').toBe(409)
      const detail = (await publishAttempt.json()) as { detail?: string }
      expect((detail.detail ?? '').toLowerCase()).toContain('validated')
      return
    }
    // The open row is in `draft`. Attempt to publish it directly.
    const publishAttempt = await request.post(
      `${API_ORIGIN}/api/v1/platform-settings/versions/${openDraft.id}/publish`,
      {
        headers: {
          'X-CSRF-Token': session.csrfToken,
          'X-Correlation-ID': correlationId(),
          'If-Match': `"${openDraft.lock_version}"`,
        },
      },
    )
    expect(publishAttempt.status(), 'publishing a draft without validation must be rejected').toBe(409)
    const detail = (await publishAttempt.json()) as { detail?: string }
    expect((detail.detail ?? '').toLowerCase()).toContain('validated')
  })

  test('B2: business calendar persistence is fully asserted from the database', async ({ request }) => {
    const session = await loginAndAssert(request, FULL_OWNER, FULL_OWNER_CAPABILITIES)
    const scopeSuffix = randomUUID().slice(0, 8)
    const scopeId = `ps-e2e-cal-b2-${scopeSuffix}`
    const create = await request.post(`${API_ORIGIN}/api/v1/platform-settings/calendars`, {
      headers: {
        'X-CSRF-Token': session.csrfToken,
        'X-Correlation-ID': correlationId(),
        'Idempotency-Key': `ps-e2e-cal-b2-${scopeSuffix}`,
        'Content-Type': 'application/json',
      },
      data: { scope_type: 'facility', scope_id: scopeId, parent_calendar_id: null },
    })
    expect(create.status()).toBe(201)
    const created = (await create.json()) as { id: string; status: string; lock_version: number }
    const weekday = await request.put(
      `${API_ORIGIN}/api/v1/platform-settings/calendars/${created.id}/weekdays/1`,
      {
        headers: {
          'X-CSRF-Token': session.csrfToken,
          'X-Correlation-ID': correlationId(),
          'If-Match': `"${created.lock_version}"`,
          'Content-Type': 'application/json',
        },
        data: { is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
      },
    )
    const weekdayBody = (await weekday.json()) as { lock_version: number }
    const exceptionDate = '2099-07-22'
    expect(weekday.status()).toBe(200)
    const exception = await request.put(
      `${API_ORIGIN}/api/v1/platform-settings/calendars/${created.id}/exceptions/${exceptionDate}`,
      {
        headers: {
          'X-CSRF-Token': session.csrfToken,
          'X-Correlation-ID': correlationId(),
          'If-Match': `"${weekdayBody.lock_version}"`,
          'Content-Type': 'application/json',
        },
        data: {
          type: 'local_closure',
          is_working_day: false,
          starts_at: null,
          ends_at: null,
        },
      },
    )
    expect(exception.status()).toBe(200)
    const exceptionBody = (await exception.json()) as { lock_version: number }
    const publish = await request.post(
      `${API_ORIGIN}/api/v1/platform-settings/calendars/${created.id}/publish`,
      {
        headers: {
          'X-CSRF-Token': session.csrfToken,
          'X-Correlation-ID': correlationId(),
          'If-Match': `"${exceptionBody.lock_version}"`,
        },
      },
    )
    expect(publish.status()).toBe(200)
    const publishBody = (await publish.json()) as { status: string }
    expect(publishBody.status).toBe('published')

    // Persist the summary status field.
    const allCalendars = await listCalendars(request, session)
    const summary = allCalendars.find((row) => row.id === created.id)
    expect(summary, 'calendar must remain in the list').not.toBeUndefined()
    expect(summary!.status).toBe('published')

    // Persist the detailed weekday row.
    const weekdayRows = readWeekdayRows(created.id)
    const monday = weekdayRows.find((row) => row.weekday === 1)
    expect(monday, 'Monday weekday must be persisted').toBeDefined()
    expect(monday!.is_working_day).toBe(1)
    expect(monday!.starts_at).toBe('08:00')
    expect(monday!.ends_at).toBe('16:00')

    // Persist the exception date, type, and working flag.
    const exceptions = readExceptionRows(created.id)
    const found = exceptions.find((row) => row.starts_on === exceptionDate)
    expect(found, 'exception must be persisted').toBeDefined()
    expect(found!.exception_type).toBe('local_closure')
    expect(found!.is_working_day).toBe(0)
  })
})
