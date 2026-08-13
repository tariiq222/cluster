import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const artifactsDir = path.resolve(process.cwd(), '../../artifacts')
const USER = '01980f50-5f0d-7000-8000-000000000201'
const FACILITY = '01980f50-5f0d-7000-8000-000000000202'
const UNIT = '01980f50-5f0d-7000-8000-000000000203'
const CLUSTER = '01980f50-5f0d-7000-8000-000000000204'

async function mockDashboard(page: Page) {
  let authenticated = false
  // The rebuilt PrincipalProvider reads the principal snapshot
  // (capabilities + features) from /api/v1/identity/me; the same route
  // answers the session-restore probe before login.
  await page.route('**/api/v1/identity/me', route => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: USER,
            csrf_token: 'visual-qa-csrf',
            capabilities: ['tasks.read', 'tasks.list', 'reporting.dashboard'],
            features: { tasks: true },
          },
        }),
      }
    : {
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }),
      }))
  await page.route('**/api/v1/identity/login', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: {
      'set-cookie': 'cluster_identity_session=visual-qa; Path=/; HttpOnly; SameSite=Lax',
      'x-csrf-token': 'visual-qa-csrf',
    },
    body: JSON.stringify({
      data: {
        user_id: USER,
        expires_at: '2026-07-23T09:00:00Z',
        restricted: false,
        csrf_token: 'visual-qa-csrf',
      },
    }),
  }))
  await page.route('**/api/v1/me/scopes', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: { ETag: '"1"' },
    body: JSON.stringify({
      available_scopes: [{ scope_type: 'facility', scope_id: FACILITY, label: 'مستشفى التجمع' }],
      effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'مستشفى التجمع' },
    }),
  }))
  await page.route('**/api/v1/me', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      subject_id: USER,
      tenant_id: CLUSTER,
      organization_unit_ids: [UNIT],
      roles: ['manager'],
      capabilities: ['tasks.read', 'tasks.list', 'reporting.dashboard'],
      clearance: 'internal',
      break_glass: false,
      correlation_id: USER,
      features: { tasks: true },
    }),
  }))
  await page.route('**/api/v1/tasks?limit=100', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ items: [{ id: UNIT, title: 'مراجعة جاهزية العيادة', status: 'open', priority: 'high', due_at: '2026-07-23T12:00:00Z' }], next_cursor: null }),
  }))
  for (const endpoint of ['dashboards?limit=50', 'notifications?limit=**']) {
    await page.route(`**/api/v1/${endpoint}`, route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  }
}

async function signIn(page: import('@playwright/test').Page) {
  await mockDashboard(page)
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('visual-qa')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('visual-qa-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function expectNoHorizontalOverflow(page: import('@playwright/test').Page) {
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }))
  expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 1)
  expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport + 1)
}

test('dashboard shell passes desktop, 200 percent, mobile, RTL and LTR visual checks', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await signIn(page)

  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expect(page.getByTestId('dashboard-kpis').locator(':scope > div')).toHaveCount(2)
  await expect(page.getByRole('group', { name: 'مؤشرات الأداء' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'مهامي', exact: true })).toBeVisible()
  await expectNoHorizontalOverflow(page)
  await page.screenshot({ path: path.join(artifactsDir, 'dashboard-navigation-qa-desktop.png'), fullPage: true })

  // A 640 CSS-pixel viewport is the reflow equivalent of 200% browser zoom
  // on a 1280-pixel desktop viewport. CSS `zoom` scales without reflow and
  // therefore does not model the accessibility requirement.
  await page.setViewportSize({ width: 640, height: 400 })
  await expectNoHorizontalOverflow(page)
  await page.screenshot({ path: path.join(artifactsDir, 'dashboard-navigation-qa-zoom-200.png'), fullPage: true })

  await page.setViewportSize({ width: 1280, height: 800 })

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expectNoHorizontalOverflow(page)
  await page.screenshot({ path: path.join(artifactsDir, 'dashboard-navigation-qa-ltr.png'), fullPage: true })

  await page.getByRole('button', { name: 'العربية' }).click()
  await page.setViewportSize({ width: 320, height: 720 })
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expectNoHorizontalOverflow(page)
  const menu = page.getByRole('button', { name: 'تبديل الشريط الجانبي' })
  await menu.click()
  const navigation = page.getByRole('navigation', { name: 'القائمة' })
  await expect(navigation).toBeVisible()
  await page.screenshot({ path: path.join(artifactsDir, 'dashboard-navigation-qa-mobile.png') })
})
