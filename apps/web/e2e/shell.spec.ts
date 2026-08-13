import { expect, test, type Page } from '@playwright/test'

const USER = '01980f50-5f0d-7000-8000-000000000001'
const FACILITY = '01980f50-5f0d-7000-8000-000000000002'
const UNIT = '01980f50-5f0d-7000-8000-000000000003'
const CLUSTER = '01980f50-5f0d-7000-8000-000000000004'
const capabilities = [
  'tasks.read',
  'tasks.list',
  'documents.read',
]

async function openShell(page: Page) {
  let authenticated = false
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'set-cookie':
          'cluster_identity_session=shell; Path=/; HttpOnly; SameSite=Lax',
        'x-csrf-token': 'shell-csrf',
      },
      body: JSON.stringify({
        data: {
          user_id: USER,
          expires_at: '2099-07-23T09:00:00Z',
          restricted: false,
          csrf_token: 'shell-csrf',
        },
      }),
    })
  })
  // The rebuilt PrincipalProvider reads the principal snapshot (capabilities
  // + features) from /api/v1/identity/me; the same route answers the
  // session-restore probe before login.
  await page.route('**/api/v1/identity/me', (route) =>
    route.fulfill(
      authenticated
        ? {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              data: {
                user_id: USER,
                csrf_token: 'shell-csrf',
                capabilities,
                features: { tasks: true },
              },
            }),
          }
        : {
            status: 401,
            contentType: 'application/problem+json',
            body: JSON.stringify({
              type: 'about:blank',
              title: 'Unauthorized',
              status: 401,
            }),
          },
    ),
  )
  await page.route('**/api/v1/identity/csrf', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { csrf_token: 'shell-csrf' } }),
    }),
  )
  await page.route('**/api/v1/me/scopes', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { ETag: '"1"' },
      body: JSON.stringify({
        available_scopes: [
          {
            scope_type: 'facility',
            scope_id: FACILITY,
            label: 'منشأة الاختبار',
          },
        ],
        effective_scope: {
          scope_type: 'facility',
          scope_id: FACILITY,
          label: 'منشأة الاختبار',
        },
      }),
    }),
  )
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        subject_id: USER,
        tenant_id: CLUSTER,
        organization_unit_ids: [UNIT],
        roles: ['shell'],
        capabilities,
        clearance: 'internal',
        break_glass: false,
        correlation_id: '01980f50-5f0d-7000-8000-000000000005',
        features: { tasks: true },
      }),
    }),
  )
  await page.route('**/api/v1/tasks?limit=100&view=mine', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        items: [],
        next_cursor: null,
        available_scopes: [],
      }),
    }),
  )
  await page.route('**/api/v1/dashboards?limit=50', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ items: [], next_cursor: null }),
    }),
  )
  await page.route('**/api/v1/notifications?limit=**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ items: [], next_cursor: null }),
    }),
  )
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('shell-user')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('shell-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function expectNoHorizontalOverflow(page: Page) {
  const widths = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }))
  expect(widths.document).toBeLessThanOrEqual(widths.viewport + 1)
  expect(widths.body).toBeLessThanOrEqual(widths.viewport + 1)
}

async function expectToolbarFits(page: Page) {
  const widths = await page.getByLabel('شريط الأدوات').evaluate((toolbar) => ({
    client: toolbar.clientWidth,
    scroll: toolbar.scrollWidth,
  }))
  expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1)
}

test('authenticated shell renders the current dashboard in RTL desktop', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await openShell(page)
  await expect(page.getByRole('navigation', { name: 'القائمة' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
  const navigation = page.getByRole('navigation', { name: 'القائمة' })
  const activeBackground = await navigation
    .getByRole('link', { name: 'الرئيسية', exact: true })
    .evaluate((link) => getComputedStyle(link).backgroundColor)
  const inactiveBackground = await navigation
    .getByRole('link', { name: 'المهام', exact: true })
    .evaluate((link) => getComputedStyle(link).backgroundColor)
  expect(activeBackground).not.toBe(inactiveBackground)
  const kpis = page.getByTestId('dashboard-kpis')
  await expect(kpis).toHaveAttribute('role', 'group')
  await expect(kpis.locator(':scope > div')).toHaveCount(2)

  await expect(page.getByLabel('شريط الأدوات').locator('kbd')).toBeVisible()
  await expectNoHorizontalOverflow(page)
  await expectToolbarFits(page)

  await page.setViewportSize({ width: 800, height: 600 })
  await expect(page.getByLabel('شريط الأدوات').locator('kbd')).toBeHidden()
  await expect(
    page
      .getByLabel('شريط الأدوات')
      .getByRole('button', { name: 'بحث', exact: true }),
  ).toBeVisible()
  await expectNoHorizontalOverflow(page)
  await expectToolbarFits(page)

  await page.setViewportSize({ width: 320, height: 720 })
  await page.locator('[data-sidebar="trigger"]').click()
  const mobileSidebar = page.getByRole('dialog', { name: 'القائمة الجانبية' })
  await expect(mobileSidebar).toBeVisible()
  await expect(mobileSidebar).toHaveAccessibleDescription(
    'التنقل في مساحة عمليات التجمع الصحي.',
  )
  await expect(page.getByRole('dialog', { name: 'Sidebar' })).toHaveCount(0)
  await mobileSidebar.getByRole('button', { name: 'إغلاق القائمة' }).click()
  await expectNoHorizontalOverflow(page)
  await expectToolbarFits(page)

  await page.setViewportSize({ width: 1280, height: 800 })
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Home' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

/* SEARCH-01-FIX: the real header Search trigger opens the controlled
 * command dialog. The dialog has a localized accessible name, focuses
 * its input on open, and Escape returns focus to the trigger. The
 * third-party /frontman surface is opt-in only and never appears in
 * default development. */
test('header search button opens the command dialog and restores focus on Escape', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await openShell(page)

  const header = page.getByLabel('شريط الأدوات')
  // Desktop surface: the outline button carrying the localized search
  // label and the keyboard-shortcut kbd. Both the label and the kbd are
  // in the accessible tree; the kbd is aria-hidden so the name stays
  // clean.
  const searchTrigger = header.getByRole('button', { name: /بحث/ }).filter({
    has: page.locator('kbd'),
  })
  await expect(searchTrigger).toBeVisible()
  await searchTrigger.focus()

  await searchTrigger.click()
  const dialog = page.getByRole('dialog', { name: 'بحث' })
  await expect(dialog).toBeVisible()
  await expect(dialog).toHaveAccessibleDescription('ابحث في المنصة…')

  const input = page.getByPlaceholder('ابحث في المنصة…')
  await expect(input).toBeFocused()

  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(searchTrigger).toBeFocused()
})
