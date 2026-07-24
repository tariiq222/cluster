import { expect, test, type Page } from '@playwright/test'

const USER = '019f8e3b-3368-7192-85a6-3da3949fd801'
const FACILITY = '019f8e3b-3368-7192-85a6-3da3949fd802'
const UNIT = '019f8e3b-3368-7192-85a6-3da3949fd803'
const CLUSTER = '019f8e3b-3368-7192-85a6-3da3949fd804'
const OPERATOR_CAPABILITIES = [
  'platform_settings.read',
  'platform_operations.health.read',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.logs.read',
]
const FULL_PLATFORM_CAPABILITIES = [
  ...OPERATOR_CAPABILITIES,
  'platform_settings.manage',
  'platform_operations.restore.request',
  'platform_operations.alerts.manage',
  'platform_operations.maintenance.manage',
]

async function navigate(page: Page, path: string): Promise<void> {
  await page.evaluate((nextPath) => {
    window.history.pushState({}, '', nextPath)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, path)
}

async function openPlatformSettings(page: Page, capabilities: readonly string[]): Promise<void> {
  // Keep the mocked shell isolated from a developer's local API. Specific
  // identity/principal routes below override this fallback.
  await page.route('**/api/v1/**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ items: [], next_cursor: null }),
  }))
  await page.route('**/api/v1/identity/login', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: { 'set-cookie': 'cluster_identity_session=platform-settings; Path=/; HttpOnly; SameSite=Lax', 'x-csrf-token': 'platform-settings-csrf' },
    body: JSON.stringify({ data: { user_id: USER, expires_at: '2099-07-23T09:00:00Z', restricted: false, csrf_token: 'platform-settings-csrf' } }),
  }))
  await page.route('**/api/v1/me/scopes', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: { ETag: '"1"' },
    body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' }], effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' } }),
  }))
  await page.route('**/api/v1/me', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ subject_id: USER, tenant_id: CLUSTER, organization_unit_ids: [UNIT], roles: ['platform-operator'], capabilities, clearance: 'internal', break_glass: false, correlation_id: '019f8e3b-3368-7192-85a6-3da3949fd805' }),
  }))
  await page.route('**/api/v1/work-records?limit=20', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/notifications?limit=20', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('platform-operator')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('safe-test-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
  await navigate(page, '/admin/platform')
}

test('platform operator opens the RTL overview and its direct deep link', async ({ page }) => {
  await openPlatformSettings(page, OPERATOR_CAPABILITIES)

  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
  await expect(page.getByText('حالة الخدمات')).toBeVisible()
  await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toBeVisible()

  await expect(page).toHaveURL(/\/admin\/platform$/)
})

test('technical logs are redacted in the mocked RTL journey and preserve the English LTR route', async ({ page }) => {
  await openPlatformSettings(page, OPERATOR_CAPABILITIES)
  await navigate(page, '/admin/platform/logs')
  await expect(page.getByText('تصفية السجلات')).toBeVisible()
  await expect(page.getByText('زمن الاستجابة تجاوز العتبة')).toBeVisible()
  await expect(page.locator('body')).not.toContainText('fixture-secret-token')
  await expect(page.locator('body')).not.toContainText('Bearer super-secret')

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Platform settings' })).toBeVisible()
  await expect(page.getByText('Filter logs')).toBeVisible()
})

test('security draft validation and publication confirmation remain explicit in the mocked journey', async ({ page }) => {
  await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
  await navigate(page, '/admin/platform/security')

  await expect(page.getByText('الحدود الثابتة: 8–128')).toBeVisible()
  await expect(page.getByRole('button', { name: 'التحقق من المسودة' })).toBeVisible()
  await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
  await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
  await expect(page.getByText('سيصبح الإصدار v4 فعالاً لجميع المستخدمين.')).toBeVisible()
  await page.getByRole('button', { name: 'إلغاء' }).click()
  await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
})

test('backup request is idempotent in the mocked journey and restore requires a second actor', async ({ page }) => {
  await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
  await navigate(page, '/admin/platform/backups')

  const runBackup = page.getByRole('button', { name: 'تشغيل نسخة الآن' })
  await runBackup.dblclick()
  await expect(page.getByRole('status')).toHaveText('تمت جدولة العملية باستخدام مفتاح idempotency.')
  await page.getByRole('button', { name: 'طلب استعادة' }).click()
  await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toContainText('تأكيد مستخدم ثانٍ')
})

test('degraded health and maintenance deep links keep the mocked platform UI available', async ({ page }) => {
  await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
  await navigate(page, '/admin/platform/health')
  await expect(page.getByText('Redis — 220ms')).toBeVisible()
  await expect(page.getByText('متدهور')).toBeVisible()

  await navigate(page, '/admin/platform/maintenance')
  await expect(page).toHaveURL(/\/admin\/platform\/maintenance$/)
  await page.getByRole('button', { name: 'إنشاء نافذة صيانة' }).click()
  await expect(page.getByRole('dialog', { name: 'إنشاء نافذة صيانة' })).toBeVisible()
})

test('unauthorized user receives a mocked 403 and the platform navigation remains hidden', async ({ page }) => {
  await openPlatformSettings(page, [])
  await page.route('**/api/v1/platform-settings/**', (route) => route.fulfill({
    status: 403,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type: 'access-denied', title: 'Forbidden', status: 403 }),
  }))
  await navigate(page, '/')
  await navigate(page, '/admin/platform')

  await expect(page.getByRole('link', { name: 'إعدادات المنصة' })).toHaveCount(0)
  const response = await page.evaluate(async () => (await fetch('/api/v1/platform-settings/health')).status)
  expect(response).toBe(403)
})
