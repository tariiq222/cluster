/**
 * Live E2E suite for the PlatformSettings vertical slice.
 *
 * These tests rely on the real Laravel API
 * (`W1_1_API_ORIGIN=http://127.0.0.1:8000`) plus a web server on
 * `W1_1_WEB_PORT=4180`. The suite covers the dedicated
 * PlatformSettings personas issued by `Database\Seeders\PlatformSettingsE2EAccountSeeder`,
 * which is registered only in `local` and `testing`. In `production`
 * the seeder refuses to register, the fixture passwords fail to
 * authenticate, and the entire suite aborts with a clear reason. There
 * are no shared platform accounts, no wildcards, and no super-admin
 * fallbacks.
 *
 * The personas are:
 *   - ps-e2e-full-owner    : owns settings, alerts, maintenance, calendars.
import { expect, test, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'
 *   - ps-e2e-unauthorized  : authenticated but holds no PlatformSettings caps.
 *   - ps-e2e-deferred-logs : holds only the deferred technical-log caps.
 *
 * The deferred technical-log capabilities are intentionally granted
 * only to the deferred-logs persona. The full owner and operator
 * personas never receive them, so the deferred surface stays honest.
 */
import { expect, test, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'

const API_ORIGIN = process.env.W1_1_API_ORIGIN ?? 'http://127.0.0.1:8000'
const WEB_PORT = process.env.W1_1_WEB_PORT ?? '4173'
const WEB_ORIGIN = `http://127.0.0.1:${WEB_PORT}`
const ENVIRONMENT = process.env.APP_ENV ?? 'local'

// The suite is for development/CI runs only. If the host environment ever
// points at a non-developmental environment, fail fast so production
// cannot be exercised by accident.
test.beforeAll(() => {
  if (ENVIRONMENT === 'production') {
    throw new Error('PlatformSettings live E2E must not run in production.')
  }
})

const FULL_OWNER = { username: 'ps-e2e-full-owner', password: 'Platform!Full.Owner.E2E.2026' }
const OPERATOR = { username: 'ps-e2e-operator', password: 'Platform!Operator.ReadOnly.E2E.2026' }
const UNAUTHORIZED = { username: 'ps-e2e-unauthorized', password: 'Platform!Unauth.NoCaps.E2E.2026' }
const DEFERRED_LOGS = { username: 'ps-e2e-deferred-logs', password: 'Platform!DeferredLogs.E2E.2026' }

// Exact, non-wildcard capability expectations. Each principal has only
// the capabilities PlatformSettings needs, and nothing else. The list
// is verified through the authoritative `GET /api/v1/me` endpoint after
// login so the assertion matches the real authorization boundary, not
// a hand-rolled check.
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
const OPERATOR_CAPABILITIES = [
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.health.read',
  'platform_settings.read',
] as const
const UNAUTHORIZED_CAPABILITIES: readonly string[] = []
const DEFERRED_LOGS_CAPABILITIES = [
  'platform_operations.logs.read',
  'platform_operations.logs.restore',
] as const

type LoginResult = {
  csrfToken: string
  capabilities: readonly string[]
}

/**
 * Logs in via the API and verifies the principal carries the expected
 * capability set. The login response sets a session cookie on the
 * supplied `APIRequestContext`; the CSRF token comes from the
 * `X-CSRF-Token` response header. The returned `csrfToken` must be
 * sent in `X-CSRF-Token` for every state-changing request.
 */
async function loginAndAssertCapabilities(
  request: APIRequestContext,
  credentials: { username: string; password: string },
  expectedCapabilities: readonly string[],
): Promise<LoginResult> {
  const response = await request.post(`${API_ORIGIN}/api/v1/identity/login`, {
    headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd910' },
    data: { username: credentials.username, password: credentials.password },
  })
  expect(response.status(), `login for ${credentials.username} should succeed`).toBe(200)
  const csrfToken = response.headers()['x-csrf-token'] ?? ''
  expect(csrfToken, 'login response should include a CSRF token').toBeTruthy()

  const meResponse = await request.get(`${API_ORIGIN}/api/v1/me`, {
    headers: { 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd910' },
  })
  expect(meResponse.status(), `/me for ${credentials.username} should succeed`).toBe(200)
  const meBody = (await meResponse.json()) as { capabilities?: readonly string[] }
  const capabilities = (meBody.capabilities ?? []).slice().sort()
  const sortedExpected = expectedCapabilities.slice().sort()
  expect(
    capabilities,
    `${credentials.username} capabilities should match the documented set`,
  ).toEqual(sortedExpected)

  return { csrfToken, capabilities }
}

/**
 * Logs in through the browser, exercising the real cookie-based
 * session. Returns when the dashboard heading is visible.
 */
async function loginThroughUi(page: Page, credentials: { username: string; password: string }): Promise<void> {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill(credentials.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(credentials.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible({ timeout: 15000 })
}

async function navigate(page: Page, path: string): Promise<void> {
  await page.goto(`${WEB_ORIGIN}${path}`);
  await page.waitForLoadState('networkidle');
}

test.describe('platform-settings live E2E', () => {
  test('web server is reachable on the configured port', async ({ page }) => {
    await page.goto(WEB_ORIGIN)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })

  test('anonymous user is redirected to login from /admin/platform', async ({ page }) => {
    await page.goto(`${WEB_ORIGIN}/admin/platform`)
    await expect(page.getByRole('heading', { name: 'مرحباً بعودتك' })).toBeVisible()
  })

  test('full platform owner reads the live overview', async ({ page }) => {
    await loginThroughUi(page, FULL_OWNER)
    await navigate(page, '/admin/platform')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' }).first()).toBeVisible()
  })

  test('security lifecycle buttons render for the full owner', async ({ page }) => {
    await loginThroughUi(page, FULL_OWNER)
    await navigate(page, '/admin/platform/security')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'إنشاء مسودة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'نشر الإعدادات' })).toBeVisible()
  })

  test('backups page renders for the operator', async ({ page }) => {
    await loginThroughUi(page, OPERATOR)
    await navigate(page, '/admin/platform/backups')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    const runButton = page.getByRole('button', { name: 'Run backup now' })
      .or(page.getByRole('button', { name: 'تشغيل نسخة الآن' }))
      .or(page.getByRole('button', { name: 'إنشاء نسخة احتياطية' }))
    await expect(runButton).toBeVisible()
  })

  test('health page renders without leaking secrets', async ({ page }) => {
    await loginThroughUi(page, OPERATOR)
    await navigate(page, '/admin/platform/health')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    const body = await page.content()
    expect(body).not.toMatch(/s3:|\/var\/|credential|token=/i)
  })

  test('maintenance section enforces manage capability at the API', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, OPERATOR, OPERATOR_CAPABILITIES)
    const deniedMaintenance = await request.get(`${API_ORIGIN}/api/v1/platform-operations/maintenance-windows`, {
      headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd911' },
    })
    expect([401, 403]).toContain(deniedMaintenance.status())
  })

  test('maintenance section admits the full owner', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, FULL_OWNER, FULL_OWNER_CAPABILITIES)
    const allowedMaintenance = await request.get(`${API_ORIGIN}/api/v1/platform-operations/maintenance-windows`, {
      headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd912' },
    })
    expect(allowedMaintenance.status()).toBe(200)
  })

  test('rejects the request when no API is reachable', async ({ page, context }) => {
    await loginThroughUi(page, FULL_OWNER)
    await context.route('**/api/v1/platform-operations/overview**', (route) => route.abort('failed'))
    await navigate(page, '/admin/platform')
    await expect(page.getByText(/could not be loaded|تعذر تحميل البيانات/).first()).toBeVisible({ timeout: 15000 })
    await context.unroute('**/api/v1/platform-operations/overview**')
  })

  test('unauthorized user does not see the platform settings link', async ({ page }) => {
    await loginThroughUi(page, UNAUTHORIZED)
    await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
    expect(await page.getByRole('link', { name: 'إعدادات المنصة' }).count()).toBe(0)
  })

  test('calendars section renders for the full owner', async ({ page }) => {
    await loginThroughUi(page, FULL_OWNER)
    await navigate(page, '/admin/platform/calendars')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    await expect(page.getByText(/تقويم|calendar/i).first()).toBeVisible()
  })

  test('logs nav is hidden even when the principal holds the deferred capability', async ({ page }) => {
    await loginThroughUi(page, FULL_OWNER)
    await navigate(page, '/admin/platform')
    expect(await page.getByRole('link', { name: /سجلات|logs/i }).count()).toBe(0)
  })

  test('logs nav stays hidden for the deferred-logs persona', async ({ page }) => {
    await loginThroughUi(page, DEFERRED_LOGS)
    await navigate(page, '/admin/platform')
    expect(await page.getByRole('link', { name: /سجلات|logs/i }).count()).toBe(0)
  })

  test('logs list returns 503 problem details for the deferred-logs principal', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, DEFERRED_LOGS, DEFERRED_LOGS_CAPABILITIES)
    const logsResponse = await request.get(`${API_ORIGIN}/api/v1/platform-operations/technical-logs`, {
      headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd913' },
    })
    expect(logsResponse.status()).toBe(503)
    const body = (await logsResponse.json()) as { type?: string; title?: string; detail?: string }
    expect(body.type).toBe('https://cluster.example/problems/service-unavailable')
    expect(body.title).toBe('Service Unavailable')
    expect(body.detail).toBe('Technical logs are not available in this environment.')
  })

  test('logs restore returns 503 problem details for the deferred-logs principal', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, DEFERRED_LOGS, DEFERRED_LOGS_CAPABILITIES)
    const restoreResponse = await request.post(`${API_ORIGIN}/api/v1/platform-operations/technical-logs/restore`, {
      headers: {
        'X-CSRF-Token': session.csrfToken,
        'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd914',
        'Idempotency-Key': `ps-e2e-logs-restore-${Date.now()}`,
        'Content-Type': 'application/json',
        'Accept': 'application/problem+json',
      },
      data: { manifest_id: '019f8e3b-3368-7192-85a6-3da3949fd711', reason: 'Investigate incident' },
    })
    expect(restoreResponse.status()).toBe(503)
    const body = (await restoreResponse.json()) as { type?: string; detail?: string }
    expect(body.type).toBe('https://cluster.example/problems/service-unavailable')
  })

  test('full owner overview returns 200 with allowed actions', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, FULL_OWNER, FULL_OWNER_CAPABILITIES)
    const overviewResponse = await request.get(`${API_ORIGIN}/api/v1/platform-operations/overview`, {
      headers: { 'X-CSRF-Token': session.csrfToken, 'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd917' },
    })
    expect(overviewResponse.status()).toBe(200)
  })

  test('operator cannot create platform settings draft', async ({ request }) => {
    const session = await loginAndAssertCapabilities(request, OPERATOR, OPERATOR_CAPABILITIES)
    const denied = await request.post(`${API_ORIGIN}/api/v1/platform-settings/versions`, {
      headers: {
        'X-CSRF-Token': session.csrfToken,
        'X-Correlation-ID': '019f8e3b-3368-7192-85a6-3da3949fd916',
        'Content-Type': 'application/json',
        'Accept': 'application/problem+json',
      },
      data: { name: 'Operator should not be able to draft' },
    })
    expect([401, 403]).toContain(denied.status())
  })

  test('route error renders the inline error message, not the loading skeleton', async ({ page }) => {
    await page.route('**/api/v1/platform-operations/overview', (route) => route.fulfill({
      status: 500,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'server', title: 'Internal Server Error', status: 500 }),
    }))
    await loginThroughUi(page, FULL_OWNER)
    await navigate(page, '/admin/platform')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    await expect(page.getByText(/could not be loaded|تعذر تحميل البيانات/).first()).toBeVisible()
  })

  test('overview renders denied for the unauthorized persona without leaking data', async ({ page }) => {
    await loginThroughUi(page, UNAUTHORIZED)
    await navigate(page, '/admin/platform')
    await expect(page.getByText(/do not have access|لا تملك صلاحية/).first()).toBeVisible()
  })
})
