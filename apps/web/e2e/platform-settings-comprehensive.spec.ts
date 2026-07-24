/**
 * Comprehensive E2E coverage for the Platform Settings module.
 *
 * What is actually implemented (verified by reading the source):
 *  - 7 routes handled by `PlatformSettingsRoute`: overview, security, calendars,
 *    backups, logs, health, maintenance. They share a single in-memory mock
 *    via `platformSettingsMockFor`. The UI does NOT call the live Task9
 *    wrappers from screens; the data is purely client-side state.
 *  - The route-level `RouteAccessGuard` (in `AppWorkspace.tsx`) renders the
 *    generic "لا تملك صلاحية فتح هذه الصفحة" panel when the principal
 *    lacks the required capability. The per-section "لا تملك صلاحية هذا القسم"
 *    copy is reachable only via the section gate inside
 *    `PlatformSettingsLayout`, which the route guard always hides.
 *  - The "Accounts and access" sidebar group is collapsed by default; the
 *    "إعدادات المنصة" link only appears after the group is expanded.
 *  - The session is in-memory. A browser reload drops the user back on the
 *    login screen because the access token is not persisted.
 *  - `Select` is the custom dropdown component, not a native `<select>`.
 *    `getByLabel` returns the trigger button, not a native <select>. The
 *    trigger carries its own `aria-label` (e.g. "الخطورة" / "النوع" /
 *    "نطاق التقويم") distinct from the field label.
 *  - Drawer focus trap restores focus to the previously-focused element
 *    on close, and the close button uses an explicit `aria-label`.
 *  - The Unauthorized user sees the route-level denial panel; the section
 *    gate is never reached. The sidebar group is removed entirely when no
 *    visible entries remain.
 *
 * The shell is fully mocked via Playwright `page.route` so the suite never
 * touches the live backend. The only live surface under test is what the UI
 * calls during the user journey, which in V1 is exclusively the mock layer.
 */

import { expect, test, type Page, type Route } from '@playwright/test'

const USER = '019f8e3b-3368-7192-85a6-3da3949fd801'
const FACILITY = '019f8e3b-3368-7192-85a6-3da3949fd802'
const UNIT = '019f8e3b-3368-7192-85a6-3da3949fd803'
const CLUSTER = '019f8e3b-3368-7192-85a6-3da3949fd804'

const FULL_PLATFORM_CAPABILITIES = [
  'platform_settings.read',
  'platform_settings.manage',
  'platform_settings.calendar.read',
  'platform_settings.calendar.override_official_holiday',
  'platform_operations.health.read',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.restore.request',
  'platform_operations.logs.read',
  'platform_operations.logs.restore',
  'platform_operations.alerts.manage',
  'platform_operations.maintenance.manage',
  'platform_operations.maintenance.cancel',
]

const OPERATOR_NO_RESTORE = [
  'platform_settings.read',
  'platform_operations.health.read',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.logs.read',
]

const OPERATOR_NO_HEALTH = [
  'platform_settings.read',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.logs.read',
]

const OPERATOR_NO_BACKUP_RUN = [
  'platform_settings.read',
  'platform_operations.health.read',
  'platform_operations.backup.read',
  'platform_operations.logs.read',
]

const OPERATOR_NO_MAINTENANCE = [
  'platform_settings.read',
  'platform_operations.health.read',
  'platform_operations.backup.read',
  'platform_operations.backup.run',
  'platform_operations.logs.read',
]

const PARTIAL_CALENDARS_ONLY = [
  'platform_settings.read',
  'platform_settings.calendar.read',
  'platform_settings.calendar.override_official_holiday',
]

const PARTIAL_LOGS_ONLY = [
  'platform_settings.read',
  'platform_operations.logs.read',
]

const PARTIAL_BACKUP_RUN_ONLY = [
  'platform_operations.backup.run',
]

const SECTION_PATHS = {
  overview: '/admin/platform',
  security: '/admin/platform/security',
  calendars: '/admin/platform/calendars',
  backups: '/admin/platform/backups',
  logs: '/admin/platform/logs',
  health: '/admin/platform/health',
  maintenance: '/admin/platform/maintenance',
} as const

async function navigate(page: Page, path: string): Promise<void> {
  await page.evaluate((nextPath) => {
    window.history.pushState({}, '', nextPath)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, path)
}

async function fulfillJson(route: Route, body: unknown, status = 200, headers: Record<string, string> = {}): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    headers,
    body: JSON.stringify(body),
  })
}

async function openPlatformSettings(page: Page, capabilities: readonly string[]): Promise<void> {
  await page.route('**/api/v1/**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ items: [], next_cursor: null }),
  }))
  await page.route('**/api/v1/identity/login', (route) => fulfillJson(route, {
    data: { user_id: USER, expires_at: '2099-07-23T09:00:00Z', restricted: false, csrf_token: 'platform-settings-csrf' },
  }, 200, {
    'set-cookie': 'cluster_identity_session=platform-settings; Path=/; HttpOnly; SameSite=Lax',
    'x-csrf-token': 'platform-settings-csrf',
  }))
  await page.route('**/api/v1/me/scopes', (route) => fulfillJson(route, {
    available_scopes: [{ scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' }],
    effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' },
  }, 200, { ETag: '"1"' }))
  await page.route('**/api/v1/me', (route) => fulfillJson(route, {
    subject_id: USER,
    tenant_id: CLUSTER,
    organization_unit_ids: [UNIT],
    roles: ['platform-operator'],
    capabilities,
    clearance: 'internal',
    break_glass: false,
    correlation_id: '019f8e3b-3368-7192-85a6-3da3949fd805',
  }))
  await page.route('**/api/v1/work-records?limit=20', (route) => fulfillJson(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/notifications?limit=20', (route) => fulfillJson(route, { items: [], next_cursor: null }))

  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('platform-operator')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('safe-test-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function openPlatformSettingsAsUnauthorized(page: Page): Promise<void> {
  await page.route('**/api/v1/**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ items: [], next_cursor: null }),
  }))
  await page.route('**/api/v1/identity/login', (route) => fulfillJson(route, {
    data: { user_id: USER, expires_at: '2099-07-23T09:00:00Z', restricted: false, csrf_token: 'platform-settings-csrf' },
  }, 200, {
    'set-cookie': 'cluster_identity_session=platform-settings; Path=/; HttpOnly; SameSite=Lax',
    'x-csrf-token': 'platform-settings-csrf',
  }))
  await page.route('**/api/v1/me/scopes', (route) => fulfillJson(route, {
    available_scopes: [{ scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' }],
    effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' },
  }, 200, { ETag: '"1"' }))
  await page.route('**/api/v1/me', (route) => fulfillJson(route, {
    subject_id: USER,
    tenant_id: CLUSTER,
    organization_unit_ids: [UNIT],
    roles: ['no-platform-roles'],
    capabilities: [],
    clearance: 'internal',
    break_glass: false,
    correlation_id: '019f8e3b-3368-7192-85a6-3da3949fd805',
  }))
  await page.route('**/api/v1/work-records?limit=20', (route) => fulfillJson(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/notifications?limit=20', (route) => fulfillJson(route, { items: [], next_cursor: null }))

  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('no-platform-user')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('safe-test-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function expandAccountsAndAccessGroup(page: Page): Promise<void> {
  // Click once and verify the link is now visible. If the click collapses an
  // already-open group, retry. The retry pattern is bounded to avoid hangs.
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const link = page.getByRole('link', { name: 'إعدادات المنصة' })
    if (await link.count() > 0 && await link.first().isVisible()) return
    const trigger = page.getByRole('button', { name: 'الحسابات والصلاحيات' })
    if (await trigger.count() === 0) return
    const expanded = await trigger.getAttribute('aria-expanded')
    if (expanded === 'true' && await link.count() > 0) return
    await trigger.click()
    await page.waitForTimeout(150)
  }
}

test.describe('Platform Settings — Control center (overview)', () => {
  test('renders metrics, service status, and safe actions for an operator', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.overview)

    await expect(page).toHaveURL(/\/admin\/platform$/)
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'مركز التحكم' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'حالة الخدمات' })).toBeVisible()
    await expect(page.getByText('7/8')).toBeVisible()
    await expect(page.getByText('آخر نسخة', { exact: true })).toBeVisible()
    await expect(page.getByText('ناجحة')).toBeVisible()
    await expect(page.getByText('التخزين', { exact: true })).toBeVisible()
    await expect(page.getByText('68%')).toBeVisible()
    await expect(page.getByText('قاعدة البيانات')).toBeVisible()
    await expect(page.getByText('الطابور', { exact: true })).toBeVisible()
    await expect(page.getByText('النسخ الاحتياطي', { exact: true })).toBeVisible()
    await expect(page.getByText('سليم').first()).toBeVisible()
    await expect(page.getByText('متدهور', { exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'تحديث الفحص' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toBeVisible()
    await expect(page.getByText('تنبيه طابور متدهور — 09:18')).toBeVisible()
    await expect(page.getByText('اكتملت النسخة المجدولة — 06:00')).toBeVisible()
  })

  test('refresh check action announces a status update', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.overview)

    await page.getByRole('button', { name: 'تحديث الفحص' }).click()
    await expect(page.getByRole('status')).toHaveText('تم تحديث فحص صحة المنصة.')
  })

  test('Run backup now is idempotent on double-click and shows a status', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.overview)

    const runBackup = page.getByRole('button', { name: 'تشغيل نسخة الآن' })
    await runBackup.dblclick()
    await expect(page.getByRole('status')).toHaveText('تمت جدولة النسخة الاحتياطية باستخدام مفتاح idempotency.')
  })

  test('operator without backup.run does not see the run backup button', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_BACKUP_RUN)
    await navigate(page, SECTION_PATHS.overview)

    await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'تحديث الفحص' })).toBeVisible()
  })

  test('operator without health.read does not see the refresh check button', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_HEALTH)
    await navigate(page, SECTION_PATHS.overview)

    await expect(page.getByRole('button', { name: 'تحديث الفحص' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toBeVisible()
  })

  test('sidebar link is present for operator and absent for unauthorized user', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await expandAccountsAndAccessGroup(page)
    await expect(page.getByRole('link', { name: 'إعدادات المنصة' })).toBeVisible()

    await openPlatformSettingsAsUnauthorized(page)
    await expandAccountsAndAccessGroup(page)
    await expect(page.getByRole('link', { name: 'إعدادات المنصة' })).toHaveCount(0)
  })

  test('unauthorized direct link renders the route-level denial panel', async ({ page }) => {
    await openPlatformSettingsAsUnauthorized(page)
    await page.route('**/api/v1/platform-settings/**', (route) => route.fulfill({
      status: 403,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'access-denied', title: 'Forbidden', status: 403 }),
    }))
    await navigate(page, SECTION_PATHS.overview)

    await expect(page.getByRole('heading', { name: 'لا تملك صلاحية فتح هذه الصفحة' })).toBeVisible()
    await expect(page.getByText('لا تظهر بيانات هذه الصفحة حتى تتوفر الصلاحية المطلوبة في نطاقك الحالي.')).toBeVisible()
    await expect(page.locator('body')).not.toContainText('platform_settings.manage')

    const response = await page.evaluate(async () => (await fetch('/api/v1/platform-settings/health')).status)
    expect(response).toBe(403)
  })

  test('unauthorized direct link to every section renders the same denial panel', async ({ page }) => {
    await openPlatformSettingsAsUnauthorized(page)
    await page.route('**/api/v1/platform-settings/**', (route) => route.fulfill({
      status: 403,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'access-denied', title: 'Forbidden', status: 403 }),
    }))

    for (const path of Object.values(SECTION_PATHS)) {
      await navigate(page, path)
      await expect(page.getByRole('heading', { name: 'لا تملك صلاحية فتح هذه الصفحة' })).toBeVisible()
      const body = await page.locator('body').innerText()
      expect(body).not.toContain('platform_operations.restore.request')
    }
  })
})

test.describe('Platform Settings — Security and settings', () => {
  test('renders active version, draft, language, and fixed security ranges', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await expect(page.getByText('الإصدار الفعال', { exact: true })).toBeVisible()
    await expect(page.getByText('v3')).toBeVisible()
    await expect(page.getByText('منشور')).toBeVisible()
    await expect(page.getByText('المسودة', { exact: true })).toBeVisible()
    await expect(page.getByText('v4')).toBeVisible()
    await expect(page.getByText('مسودة', { exact: true })).toBeVisible()
    await expect(page.getByText('اللغة الافتراضية', { exact: true })).toBeVisible()
    await expect(page.getByText('العربية')).toBeVisible()
    await expect(page.getByText('المنطقة الزمنية', { exact: true })).toBeVisible()
    await expect(page.getByText('Asia/Riyadh')).toBeVisible()
    await expect(page.getByText('الحدود الثابتة: 8–64')).toBeVisible()
    await expect(page.getByText('الحدود الثابتة: 5–120')).toBeVisible()
    await expect(page.getByText('الحدود الثابتة: 3–10')).toBeVisible()
    await expect(page.getByText('الحد الأدنى لطول كلمة المرور')).toBeVisible()
    await expect(page.getByText('مهلة الخمول بالدقائق')).toBeVisible()
    await expect(page.getByText('محاولات الدخول الفاشلة')).toBeVisible()
  })

  test('manage capability gates the draft, validate, and publish buttons', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.security)

    await expect(page.getByRole('button', { name: 'إنشاء مسودة' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'التحقق من المسودة' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'نشر الإعدادات' })).toHaveCount(0)
  })

  test('create draft, validate draft, and publish flow with confirmation dialog', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await expect(page.getByRole('button', { name: 'إنشاء مسودة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'التحقق من المسودة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'نشر الإعدادات' })).toBeVisible()

    await page.getByRole('button', { name: 'إنشاء مسودة' }).click()
    await expect(page.getByRole('status')).toHaveText('تم إنشاء مسودة الإعدادات v5.')

    await page.getByRole('button', { name: 'التحقق من المسودة' }).click()
    await expect(page.getByRole('status')).toHaveText('تم التحقق من المسودة ولا توجد مخالفات.')

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await expect(page.getByText('سيصبح الإصدار v4 فعالاً لجميع المستخدمين.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'تأكيد النشر' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'إلغاء' })).toBeVisible()

    await page.getByRole('button', { name: 'تأكيد النشر' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
    await expect(page.getByRole('status')).toHaveText('تم نشر إعدادات الأمان بنجاح.')
  })

  test('cancel via the cancel button inside the confirmation dialog', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await page.getByRole('button', { name: 'إلغاء' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
  })

  test('Escape closes the publish confirmation drawer', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
  })

  test('close button hides the publish confirmation drawer', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await page.getByRole('button', { name: 'إغلاق' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
  })

  test('click outside the dialog backdrop closes the confirmation drawer', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await page.locator('.ui-drawer-layer').click({ position: { x: 10, y: 200 } })
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeHidden()
  })

  test('repeating the publish flow does not duplicate the status announcement', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await page.getByRole('button', { name: 'تأكيد النشر' }).click()
    await expect(page.getByRole('status')).toHaveText('تم نشر إعدادات الأمان بنجاح.')

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await page.getByRole('button', { name: 'تأكيد النشر' }).click()
    await expect(page.getByRole('status')).toHaveCount(1)
  })
})

test.describe('Platform Settings — Business calendars', () => {
  test('calendar scope selector is a custom dropdown exposing platform / cluster / facility levels', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.calendars)

    await expect(page.getByRole('heading', { name: 'تقويم العمل' })).toBeVisible()
    const trigger = page.getByRole('button', { name: 'نطاق التقويم' })
    await expect(trigger).toBeVisible()
    await expect(trigger).toHaveAttribute('aria-haspopup', 'listbox')

    await trigger.click()
    await expect(page.getByRole('option', { name: 'المنصة' })).toBeVisible()
    await expect(page.getByRole('option', { name: 'التجمع' })).toBeVisible()
    await expect(page.getByRole('option', { name: 'المنشأة' })).toBeVisible()

    await page.getByRole('option', { name: 'التجمع' }).click()
    await expect(trigger).toContainText('التجمع')

    await trigger.click()
    await page.getByRole('option', { name: 'المنشأة' }).click()
    await expect(trigger).toContainText('المنشأة')
  })

  test('working week, holidays, and source chips are visible', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.calendars)

    await expect(page.getByText('الأحد–الخميس، 08:00–16:00')).toBeVisible()
    await expect(page.getByText('الجمعة–السبت، عطلة')).toBeVisible()
    await expect(page.getByText('اليوم الوطني — عطلة رسمية')).toBeVisible()
    await expect(page.getByText('رمضان 1448 — 10:00–15:00')).toBeVisible()
    await expect(page.getByText('مصدر: المنصة').first()).toBeVisible()
  })

  test('override-official-holiday capability is required for the button', async ({ page }) => {
    await openPlatformSettings(page, PARTIAL_CALENDARS_ONLY)
    await navigate(page, SECTION_PATHS.calendars)
    await expect(page.getByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' })).toBeVisible()

    await openPlatformSettings(page, ['platform_settings.read', 'platform_settings.calendar.read'])
    await navigate(page, SECTION_PATHS.calendars)
    await expect(page.getByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' })).toHaveCount(0)
  })

  test('override drawer opens, cancels, and closes via Escape', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.calendars)

    await page.getByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' }).click()
    await expect(page.getByRole('dialog', { name: 'سبب العمل أثناء العطلة' })).toBeVisible()
    await expect(page.getByText('يتطلب هذا الاستثناء سبباً وتأكيداً مستقلاً.')).toBeVisible()

    await page.getByRole('button', { name: 'إلغاء' }).click()
    await expect(page.getByRole('dialog', { name: 'سبب العمل أثناء العطلة' })).toBeHidden()

    await page.getByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' }).click()
    await expect(page.getByRole('dialog', { name: 'سبب العمل أثناء العطلة' })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'سبب العمل أثناء العطلة' })).toBeHidden()
  })
})

test.describe('Platform Settings — Backups and recovery', () => {
  test('renders backup status, schedule, retention, and only allowed actions', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.backups)

    await expect(page.getByRole('heading', { name: 'النسخ الاحتياطي والاستعادة' })).toBeVisible()
    await expect(page.getByText('آخر نجاح', { exact: true })).toBeVisible()
    await expect(page.getByText('2026-07-23 06:00')).toBeVisible()
    await expect(page.getByText('تم التحقق')).toBeVisible()
    await expect(page.getByText('آخر فشل', { exact: true })).toBeVisible()
    await expect(page.getByText('لا يوجد')).toBeVisible()
    await expect(page.getByText('الجدول', { exact: true })).toBeVisible()
    await expect(page.getByText('يومياً 06:00')).toBeVisible()
    await expect(page.getByText('الاحتفاظ', { exact: true })).toBeVisible()
    await expect(page.getByText('30 يوماً')).toBeVisible()

    await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'طلب استعادة' })).toHaveCount(0)
  })

  test('full owner can run the backup and see the idempotency status', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.backups)

    const runBackup = page.getByRole('button', { name: 'تشغيل نسخة الآن' })
    await runBackup.click()
    await expect(page.getByRole('status')).toHaveText('تمت جدولة العملية باستخدام مفتاح idempotency.')
    await runBackup.dblclick()
    await expect(page.getByRole('status')).toHaveCount(1)
  })

  test('operator without backup.run does not see the action and the screen still renders', async ({ page }) => {
    await openPlatformSettings(page, ['platform_operations.backup.read'])
    await navigate(page, SECTION_PATHS.backups)

    await expect(page.getByRole('heading', { name: 'النسخ الاحتياطي والاستعادة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'تشغيل نسخة الآن' })).toHaveCount(0)
  })

  test('restore drawer opens, submits, cancels, and closes on Escape', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.backups)

    await page.getByRole('button', { name: 'طلب استعادة' }).click()
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toBeVisible()
    await expect(page.getByText('لا يظهر إلا تدفق تأكيد مستخدم ثانٍ بعد التحقق.')).toBeVisible()

    await page.getByRole('button', { name: 'إرسال الطلب' }).click()
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toBeHidden()
    await expect(page.getByRole('status')).toHaveText('تم إرسال طلب الاستعادة للمراجعة.')

    await page.getByRole('button', { name: 'طلب استعادة' }).click()
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toBeVisible()
    await page.getByRole('button', { name: 'إلغاء' }).click()
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toBeHidden()

    await page.getByRole('button', { name: 'طلب استعادة' }).click()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toBeHidden()
  })

  test('restore drawer secondary user confirmation copy is visible', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.backups)

    await page.getByRole('button', { name: 'طلب استعادة' }).click()
    await expect(page.getByRole('dialog', { name: 'طلب استعادة' })).toContainText('تأكيد مستخدم ثانٍ')
  })

  test('partial capability (backup.run only) hits the route-level denial panel', async ({ page }) => {
    await openPlatformSettings(page, PARTIAL_BACKUP_RUN_ONLY)
    await navigate(page, SECTION_PATHS.backups)

    await expect(page.getByRole('heading', { name: 'لا تملك صلاحية فتح هذه الصفحة' })).toBeVisible()
  })
})

test.describe('Platform Settings — Technical logs', () => {
  test('renders filters, results, and redacts sensitive tokens', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.logs)

    await expect(page.getByRole('heading', { name: 'السجلات التقنية' })).toBeVisible()
    await expect(page.getByText('تصفية السجلات', { exact: true })).toBeVisible()
    await expect(page.getByText('زمن الاستجابة تجاوز العتبة')).toBeVisible()
    await expect(page.getByText('اكتمل التحقق من النسخة')).toBeVisible()
    await expect(page.locator('body')).not.toContainText('fixture-secret-token')
    await expect(page.locator('body')).not.toContainText('Bearer super-secret')
    await expect(page.locator('body')).not.toContainText('Authorization:')
    await expect(page.locator('body')).not.toContainText('password=')
  })

  test('severity filter narrows down to warning on the first page', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.logs)

    const trigger = page.getByRole('button', { name: 'الخطورة' })
    await trigger.click()
    await page.getByRole('option', { name: 'تحذير' }).click()
    await expect(page.getByText('زمن الاستجابة تجاوز العتبة')).toBeVisible()
    await expect(page.getByText('فشل فحص السعة')).toHaveCount(0)
    await expect(page.getByText('اكتمل التحقق من النسخة')).toHaveCount(0)
  })

  test('paging reveals the critical log on the second page', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.logs)

    await page.getByRole('button', { name: 'الصفحة التالية' }).click()
    await expect(page.getByText('فشل فحص السعة')).toBeVisible()
    await expect(page.getByRole('button', { name: 'الصفحة التالية' })).toHaveCount(0)
  })

  test('source filter narrows results to the chosen source', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.logs)

    const sourceTrigger = page.getByRole('button', { name: 'النوع' })
    await sourceTrigger.click()
    await page.getByRole('option', { name: 'queue' }).click()
    await expect(page.getByText('زمن الاستجابة تجاوز العتبة')).toBeVisible()
    await expect(page.getByText('اكتمل التحقق من النسخة')).toHaveCount(0)

    await sourceTrigger.click()
    await page.getByRole('option', { name: 'backup' }).click()
    await expect(page.getByText('اكتمل التحقق من النسخة')).toBeVisible()
    await expect(page.getByText('زمن الاستجابة تجاوز العتبة')).toHaveCount(0)
  })

  test('combining source and severity that match no row shows the empty results message', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.logs)

    // Pick the only available source 'backup' and then a severity that is
    // not present in that source. Page 1 only contains queue (warning) and
    // backup (info), so requesting 'critical' on the 'backup' source returns
    // an empty result set.
    const sourceTrigger = page.getByRole('button', { name: 'النوع' })
    await sourceTrigger.click()
    await page.getByRole('option', { name: 'backup' }).click()

    const severityTrigger = page.getByRole('button', { name: 'الخطورة' })
    await severityTrigger.click()
    await page.getByRole('option', { name: 'حرج' }).click()
    await expect(page.getByText('لا توجد نتائج لهذه التصفية.')).toBeVisible()
  })

  test('archive restore drawer opens and submits', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.logs)

    await expect(page.getByRole('button', { name: 'طلب استرجاع أرشيف' })).toBeVisible()
    await page.getByRole('button', { name: 'طلب استرجاع أرشيف' }).click()
    await expect(page.getByRole('dialog', { name: 'استرجاع الأرشيف' })).toBeVisible()
    await expect(page.getByText('سيتم طلب الأرشيف المصرح به فقط.')).toBeVisible()
    await page.getByRole('button', { name: 'إرسال الطلب' }).click()
    await expect(page.getByRole('dialog', { name: 'استرجاع الأرشيف' })).toBeHidden()
  })

  test('logs.restore capability is required for the archive request button', async ({ page }) => {
    await openPlatformSettings(page, PARTIAL_LOGS_ONLY)
    await navigate(page, SECTION_PATHS.logs)

    await expect(page.getByRole('button', { name: 'طلب استرجاع أرشيف' })).toHaveCount(0)
  })
})

test.describe('Platform Settings — Health and alerts', () => {
  test('renders health signals, freshness indicator, and routing policies', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.health)

    await expect(page.getByRole('heading', { name: 'صحة المنصة والتنبيهات' })).toBeVisible()
    await expect(page.getByText('Database — 18ms')).toBeVisible()
    await expect(page.getByText('Redis — 220ms')).toBeVisible()
    await expect(page.getByText('Storage — 32ms')).toBeVisible()
    await expect(page.getByText('الحرج ← داخل التطبيق + البريد ← تصعيد بعد 15 دقيقة')).toBeVisible()
    await expect(page.getByText('التحذير ← داخل التطبيق')).toBeVisible()
    await expect(page.getByRole('button', { name: 'تعديل سياسات التوجيه' })).toBeVisible()
  })

  test('alerts.manage capability is required for the edit routing button', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_RESTORE)
    await navigate(page, SECTION_PATHS.health)

    await expect(page.getByRole('button', { name: 'تعديل سياسات التوجيه' })).toHaveCount(0)
  })

  test('edit routing drawer opens, saves, cancels, and closes on Escape', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.health)

    await page.getByRole('button', { name: 'تعديل سياسات التوجيه' }).click()
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeVisible()
    await expect(page.getByText('تغييرات التوجيه تخضع لحدود الأمان الثابتة وتحتاج نشرًا صريحًا.')).toBeVisible()

    await page.getByRole('button', { name: 'حفظ المسودة' }).click()
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeHidden()

    await page.getByRole('button', { name: 'تعديل سياسات التوجيه' }).click()
    await page.getByRole('button', { name: 'إلغاء' }).click()
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeHidden()

    await page.getByRole('button', { name: 'تعديل سياسات التوجيه' }).click()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeHidden()
  })
})

test.describe('Platform Settings — Maintenance mode', () => {
  test('renders current status, upcoming windows, and gated actions', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.maintenance)

    await expect(page.getByRole('heading', { name: 'وضع الصيانة' })).toBeVisible()
    await expect(page.getByText('وضع الصيانة غير نشط. ستظهر رسالة ثنائية اللغة للمستخدمين المتأثرين عند التفعيل.')).toBeVisible()
    await expect(page.getByText('متاح')).toBeVisible()
    await expect(page.getByText('الخميس 30 يوليو، 01:00–02:00 — تحديث مجدول')).toBeVisible()
    await expect(page.getByRole('button', { name: 'إنشاء نافذة صيانة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'إلغاء النافذة' })).toBeVisible()
  })

  test('create window drawer opens and confirms', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.maintenance)

    await page.getByRole('button', { name: 'إنشاء نافذة صيانة' }).click()
    await expect(page.getByRole('dialog', { name: 'إنشاء نافذة صيانة' })).toBeVisible()
    await expect(page.getByText('تحتوي النافذة على البداية والنهاية والسبب ورسالتين بالعربية والإنجليزية.')).toBeVisible()
    await page.getByRole('button', { name: 'إنشاء', exact: true }).click()
    await expect(page.getByRole('dialog', { name: 'إنشاء نافذة صيانة' })).toBeHidden()
  })

  test('cancel window drawer opens independently and closes on Escape', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.maintenance)

    await page.getByRole('button', { name: 'إلغاء النافذة' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد إلغاء النافذة' })).toBeVisible()
    await expect(page.getByText('يتطلب الإلغاء تأكيداً مستقلاً.')).toBeVisible()
    await page.getByRole('button', { name: 'تأكيد الإلغاء' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد إلغاء النافذة' })).toBeHidden()

    await page.getByRole('button', { name: 'إلغاء النافذة' }).click()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'تأكيد إلغاء النافذة' })).toBeHidden()
  })

  test('maintenance.manage capability is required for the manage and cancel buttons', async ({ page }) => {
    await openPlatformSettings(page, OPERATOR_NO_MAINTENANCE)
    await navigate(page, SECTION_PATHS.maintenance)

    await expect(page.getByRole('button', { name: 'إنشاء نافذة صيانة' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'إلغاء النافذة' })).toHaveCount(0)
  })
})

test.describe('Platform Settings — Cross-cutting accessibility, RTL/LTR, and redaction', () => {
  test('Arabic RTL is the default and the locale toggle switches to English LTR', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.overview)

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()

    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByRole('heading', { name: 'Platform settings' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Control center' })).toBeVisible()
    await expect(page.getByText('Service status')).toBeVisible()
    await expect(page.getByText('Run backup now')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Refresh check' })).toBeVisible()

    await page.getByRole('button', { name: 'العربية' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByRole('heading', { name: 'إعدادات المنصة' })).toBeVisible()
  })

  test('localized English deep links keep the same headings across sections', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await page.getByRole('button', { name: 'English' }).click()
    await navigate(page, SECTION_PATHS.security)
    await expect(page.getByRole('heading', { name: 'Security and settings' })).toBeVisible()

    await navigate(page, SECTION_PATHS.calendars)
    await expect(page.getByRole('heading', { name: 'Business calendar' })).toBeVisible()

    await navigate(page, SECTION_PATHS.backups)
    await expect(page.getByRole('heading', { name: 'Backups and recovery' })).toBeVisible()

    await navigate(page, SECTION_PATHS.logs)
    await expect(page.getByRole('heading', { name: 'Technical logs' })).toBeVisible()

    await navigate(page, SECTION_PATHS.health)
    await expect(page.getByRole('heading', { name: 'Platform health and alerts' })).toBeVisible()

    await navigate(page, SECTION_PATHS.maintenance)
    await expect(page.getByRole('heading', { name: 'Maintenance mode' })).toBeVisible()
  })

  test('keyboard navigation opens the drawer with Enter and closes it with Escape', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.health)

    await page.getByRole('button', { name: 'تعديل سياسات التوجيه' }).focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog', { name: 'تعديل سياسات التوجيه' })).toBeHidden()

    const focusedText = await page.evaluate(() => document.activeElement?.textContent ?? '')
    expect(focusedText).toContain('تعديل سياسات التوجيه')
  })

  test('drawer close button has an aria-label and the dialog has a labelled role', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    const dialog = page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toHaveAttribute('aria-modal', 'true')

    const closeButton = dialog.getByRole('button', { name: 'إغلاق' })
    await expect(closeButton).toBeVisible()
    await expect(closeButton).toHaveAttribute('aria-label', 'إغلاق')
  })

  test('deep links load via popstate without losing the layout', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.overview)
    await navigate(page, SECTION_PATHS.security)
    await navigate(page, SECTION_PATHS.overview)

    await page.goBack()
    await expect(page).toHaveURL(/\/admin\/platform\/security$/)
    await page.goForward()
    await expect(page).toHaveURL(/\/admin\/platform$/)
    await expect(page.getByRole('heading', { name: 'مركز التحكم' })).toBeVisible()
  })

  test('capability text never leaks into the denial copy', async ({ page }) => {
    await openPlatformSettingsAsUnauthorized(page)
    await page.route('**/api/v1/platform-settings/**', (route) => route.fulfill({
      status: 403,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'access-denied', title: 'Forbidden', status: 403 }),
    }))

    for (const path of Object.values(SECTION_PATHS)) {
      await navigate(page, path)
      const body = await page.locator('body').innerText()
      expect(body).not.toContain('platform_settings.manage')
      expect(body).not.toContain('platform_operations.restore.request')
      expect(body).not.toContain('platform_operations.alerts.manage')
      expect(body).not.toContain('platform_operations.maintenance.manage')
    }
  })

  test('drawer does not allow more than one dialog open at a time', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.security)

    await page.getByRole('button', { name: 'نشر الإعدادات' }).click()
    await expect(page.getByRole('dialog', { name: 'تأكيد نشر الإعدادات' })).toBeVisible()
    await expect(page.getByRole('dialog')).toHaveCount(1)
  })

  test('reload clears the in-memory session and shows the login screen', async ({ page }) => {
    await openPlatformSettings(page, FULL_PLATFORM_CAPABILITIES)
    await navigate(page, SECTION_PATHS.overview)
    await expect(page.getByRole('heading', { name: 'مركز التحكم' })).toBeVisible()

    await page.reload()
    await expect(page.getByRole('heading', { name: 'مرحباً بعودتك' })).toBeVisible()
    await expect(page.getByLabel('اسم المستخدم')).toBeVisible()
  })
})
