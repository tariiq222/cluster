import { expect, test, type Page } from '@playwright/test'

// The shell tests exercise the authenticated shell layout. Mock the new
// session-cookie flow that the app now uses (formerly the legacy
// fixture-bearer /auth/login endpoint).
const SHELL_USER_ID = '01980f50-5f0d-7000-8000-000000000001'
const SHELL_FACILITY_ID = '01980f50-5f0d-7000-8000-000000000002'

async function openAuthenticatedShell(page: Page, data: { records?: unknown[]; notifications?: unknown[] } = {}): Promise<void> {
  await page.route('**/api/v1/identity/login', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: {
      'set-cookie': 'cluster_identity_session=shell-test-session; Path=/; HttpOnly; SameSite=Lax',
      'x-csrf-token': 'shell-test-csrf',
    },
    body: JSON.stringify({
      data: {
        user_id: SHELL_USER_ID,
        expires_at: '2026-07-18T18:00:00Z',
        restricted: false,
        csrf_token: 'shell-test-csrf',
      },
    }),
  }))
  await page.route('**/api/v1/identity/me', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data: {
        user_id: SHELL_USER_ID,
        facility_id: SHELL_FACILITY_ID,
        facility: 'facility-a',
        display_name: 'Shell test user',
      },
    }),
  }))
  await page.route('**/api/v1/work-records?limit=20', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ items: data.records ?? [], next_cursor: null }),
  }))
  await page.route('**/api/v1/notifications?limit=20', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ items: data.notifications ?? [], next_cursor: null }),
  }))
  await page.route('**/api/v1/dashboards/*', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: { items: [], next_cursor: null, total: 0 } }),
  }))

  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('shell-user')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('shell-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
}

test('authenticated shell follows the RTL desktop layout', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await openAuthenticatedShell(page)

  const sidebar = page.locator('.desktop-sidebar')
  const workspace = page.locator('.shell-workspace')
  const sidebarBox = await sidebar.boundingBox()
  const workspaceBox = await workspace.boundingBox()

  await expect(sidebar).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'التنقل الرئيسي' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'مرحباً بك' })).toBeVisible()
  await expect(page.locator('.dashboard-kpi').first()).toBeVisible()
  expect(await page.locator('.dashboard-kpi').count()).toBeGreaterThanOrEqual(4)
  await expect(page.locator('.dashboard-kpi').first()).toHaveCSS('border-radius', '16px')
  await expect(page.locator('.dashboard-panel').first()).toHaveCSS('border-radius', '16px')
  expect(Math.round((await page.locator('.dashboard-range').boundingBox())?.height ?? 0)).toBeGreaterThanOrEqual(20)
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--focus-ring').trim())).toMatch(/^3px /)
  const collapseButton = page.locator('.sidebar-collapse-button')
  await expect(collapseButton).toHaveCSS('border-radius', '12px')
  await expect(sidebar.getByRole('link', { name: 'طلباتي' })).toHaveCSS('border-radius', '12px')
  expect(Math.round((await collapseButton.boundingBox())?.height ?? 0)).toBeGreaterThanOrEqual(20)
  await expect(page.getByRole('contentinfo')).toContainText('جميع الحقوق محفوظة')
  expect(Math.round(sidebarBox?.width ?? 0)).toBe(264)
  expect(Math.round((sidebarBox?.x ?? 0) + (sidebarBox?.width ?? 0))).toBe(1280)
  expect(Math.round(workspaceBox?.x ?? -1)).toBe(0)
  expect(Math.round((workspaceBox?.width ?? 0) + (sidebarBox?.width ?? 0))).toBe(1280)

  await page.getByRole('button', { name: 'طي القائمة الجانبية' }).click()
  await expect(page.locator('.app-shell')).toHaveAttribute('data-sidebar-collapsed', 'true')
  expect(Math.round((await sidebar.boundingBox())?.width ?? 0)).toBe(68)
  expect(await page.evaluate(() => window.localStorage.getItem('cluster.sidebar-collapsed'))).toBe('true')
  // The collapsed desktop sidebar hides the navigation group items via CSS;
  // the collapse is verified by the width and the persisted flag above.
  await expect(sidebar.getByRole('button', { name: 'طي القائمة الجانبية' })).toHaveCount(0)

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  const ltrSidebarBox = await sidebar.boundingBox()
  expect(Math.round(ltrSidebarBox?.x ?? -1)).toBe(0)
})

test('mobile navigation is an accessible inline-start drawer', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 720 })
  await openAuthenticatedShell(page)

  const menuButton = page.getByRole('button', { name: 'فتح القائمة الرئيسية' })
  await expect(page.locator('.desktop-sidebar')).toBeHidden()
  await expect(menuButton).toBeVisible()
  await menuButton.click()

  const drawer = page.getByRole('dialog', { name: 'التنقل الرئيسي' })
  await expect(drawer).toBeVisible()
  await expect(page.locator('.shell-workspace')).toHaveAttribute('inert', '')
  const drawerBox = await drawer.boundingBox()
  expect(Math.round((drawerBox?.x ?? 0) + (drawerBox?.width ?? 0))).toBe(320)

  await page.keyboard.press('Escape')
  await expect(drawer).toBeHidden()
  await expect(page.locator('.shell-workspace')).not.toHaveAttribute('inert', '')
  await expect(menuButton).toBeFocused()

  await menuButton.click()
  await drawer.getByRole('link', { name: 'طلباتي' }).click()
  await expect(drawer).toBeHidden()
  await expect(menuButton).toBeFocused()

  const overflow = await page.evaluate(() => (
    document.documentElement.scrollWidth - document.documentElement.clientWidth
  ))
  expect(overflow).toBe(0)

  await menuButton.click()
  await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('hidden')
  await page.setViewportSize({ width: 900, height: 720 })
  await expect(drawer).toBeHidden()
  await expect(page.locator('.shell-workspace')).not.toHaveAttribute('inert', '')
  await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('')
  await expect(page.locator('.desktop-sidebar')).toBeVisible()
  await expect(page.locator('.desktop-sidebar').getByRole('link', { name: 'طلباتي' })).toBeFocused()
})

test('notification dialog isolates the shell and restores focus', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 720 })
  await openAuthenticatedShell(page)

  const notificationButton = page.getByRole('button', { name: 'الإشعارات', exact: true })
  await notificationButton.click()

  await expect(page.getByRole('dialog', { name: 'الإشعارات' })).toBeVisible()
  await expect(page.locator('.app-shell')).toHaveAttribute('inert', '')
  await expect(page.getByRole('dialog')).toHaveCount(1)

  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', { name: 'الإشعارات' })).toBeHidden()
  await expect(page.locator('.app-shell')).not.toHaveAttribute('inert', '')
  await expect(notificationButton).toBeFocused()
})

test('dashboard metrics and available activity use authenticated W1.1 data', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await openAuthenticatedShell(page, {
    records: [
      { id: '01980f50-5f0d-7000-8000-000000000011', status: 'submitted', payload: { title: 'طلب مراجعة', description: 'تفاصيل الطلب الأول' }, created_at: '2026-07-18T10:00:00Z' },
      { id: '01980f50-5f0d-7000-8000-000000000012', status: 'completed', payload: { title: 'طلب مكتمل', description: 'تفاصيل الطلب المكتمل' }, created_at: '2026-07-17T10:00:00Z' },
      { id: '01980f50-5f0d-7000-8000-000000000013', status: 'draft', payload: { title: 'طلب مسودة', description: 'تفاصيل المسودة' }, created_at: '2026-07-16T10:00:00Z' },
    ],
    notifications: [
      { id: '01980f50-5f0d-7000-8000-000000000021', title: 'اكتملت معالجة الطلب', is_read: false, created_at: '2026-07-18T11:00:00Z' },
      { id: '01980f50-5f0d-7000-8000-000000000022', title: 'تم استلام الطلب', is_read: true, created_at: '2026-07-18T09:00:00Z' },
    ],
  })

  await expect(page.locator('.dashboard-kpi').filter({ hasText: 'الطلبات المحمّلة' }).locator('strong')).toHaveText('٣')
  await expect(page.locator('.dashboard-kpi').filter({ hasText: 'طلبات قيد الإجراء' }).locator('strong')).toHaveText('١')
  await expect(page.locator('.dashboard-kpi').filter({ hasText: 'طلبات مكتملة' }).locator('strong')).toHaveText('١')
  await expect(page.locator('.dashboard-kpi').filter({ hasText: 'غير المقروءة المحمّلة' }).locator('strong')).toHaveText('١')
  await expect(page.getByRole('button', { name: 'الإشعارات: ١' })).toBeVisible()
  await expect(page.getByRole('link', { name: /طلب مراجعة/ })).toBeVisible()
  await expect(page.getByText('اكتملت معالجة الطلب')).toBeVisible()
})
