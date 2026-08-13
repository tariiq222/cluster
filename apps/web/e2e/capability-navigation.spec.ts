import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const artifactsDir = path.resolve(process.cwd(), '../../artifacts')
const ids = { user: '01980f50-5f0d-7000-8000-000000000101', cluster: '01980f50-5f0d-7000-8000-000000000102', unit: '01980f50-5f0d-7000-8000-000000000103', a: '01980f50-5f0d-7000-8000-000000000104', b: '01980f50-5f0d-7000-8000-000000000105' }
type Persona = 'employee' | 'manager' | 'platform'
const caps: Record<Persona, string[]> = {
  employee: ['tasks.read', 'tasks.list', 'documents.read', 'documents.list'],
  manager: ['tasks.read', 'tasks.list', 'documents.read', 'reporting.dashboard'],
  platform: ['tasks.read', 'tasks.list', 'documents.read', 'documents.list', 'organization.cluster.read', 'organization.unit.read', 'organization.person.read', 'identity.account.read', 'authorization.role.read', 'authorization.capability.read', 'authorization.assignment.read', 'authorization.audit.read', 'authorization.decision.read', 'reporting.read', 'platform_settings.read'],
}

async function mockPersona(page: Page, persona: Persona, twoScopes = false) {
  let selected = ids.a
  let authenticated = false
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'set-cookie': 'cluster_identity_session=persona; Path=/; HttpOnly; SameSite=Lax',
        'x-csrf-token': 'persona-csrf',
      },
      body: JSON.stringify({
        data: {
          user_id: ids.user,
          expires_at: '2099-07-23T09:00:00Z',
          restricted: false,
          csrf_token: 'persona-csrf',
        },
      }),
    })
  })
  // /api/v1/identity/me is only the cookie-session restore probe; the
  // principal snapshot (capabilities/features) comes from the separate
  // GET /api/v1/me route registered below.
  await page.route('**/api/v1/identity/me', (route) => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: ids.user,
            csrf_token: 'persona-csrf',
            capabilities: caps[persona],
            features: { tasks: true },
          },
        }),
      }
    : {
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }),
      }))
  await page.route('**/api/v1/identity/csrf', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: { csrf_token: 'persona-csrf' } }),
  }))
  await page.route('**/api/v1/identity/accounts?**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/organization/people?**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/me', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ subject_id: ids.user, tenant_id: ids.cluster, organization_unit_ids: [ids.unit], roles: [persona], capabilities: caps[persona], clearance: 'internal', break_glass: false, correlation_id: ids.user, features: { tasks: true } }) }))
  await page.route('**/api/v1/me/scopes', route => route.fulfill({ contentType: 'application/json', headers: { ETag: selected === ids.a ? '"1"' : '"2"' }, body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: ids.a, label: 'نطاق أ' }, ...(twoScopes ? [{ scope_type: 'facility', scope_id: ids.b, label: 'نطاق ب' }] : [])], effective_scope: { scope_type: 'facility', scope_id: selected, label: selected === ids.a ? 'نطاق أ' : 'نطاق ب' } }) }))
  await page.route('**/api/v1/me/scope', async route => { selected = JSON.parse(route.request().postData() ?? '{}').scope_id; await route.fulfill({ contentType: 'application/json', headers: { ETag: '"2"' }, body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: ids.a, label: 'نطاق أ' }, { scope_type: 'facility', scope_id: ids.b, label: 'نطاق ب' }], effective_scope: { scope_type: 'facility', scope_id: selected, label: 'نطاق ب' } }) }) })
  for (const path of ['tasks?limit=100', 'dashboards?limit=50', 'notifications?limit=**']) await page.route(`**/api/v1/${path}`, route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  // Governance workspace feeds for the platform persona: roles list and the
  // capability catalog behind the Roles tab.
  await page.route('**/api/v1/authorization/roles**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: [], next_cursor: null } }) }))
  await page.route('**/api/v1/authorization/capabilities**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: [], next_cursor: null } }) }))
}
async function login(page: Page, path = '/') { await page.goto(path); await page.getByLabel('اسم المستخدم').fill('persona'); await page.getByLabel('كلمة المرور', { exact: true }).fill('persona-password'); await page.getByRole('button', { name: 'تسجيل الدخول' }).click() }

test('employee navigation contains only personal work and a direct admin URL has no privileged navigation', async ({ page }) => {
  await mockPersona(page, 'employee'); await login(page, '/accounts-permissions')
  // `/accounts-permissions` is a retired path that redirects to `/access`;
  // a capability-less principal sees the shared non-disclosing denied copy,
  // never an admin surface.
  await expect(page.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeVisible()
  const navigation = page.getByRole('navigation', { name: 'القائمة' })
  const primaryLinks = navigation.getByRole('link')
  await expect(primaryLinks).toHaveCount(3)
  await expect(primaryLinks).toHaveText(['الرئيسية', 'المهام', 'المستندات'])
  for (let index = 0; index < 3; index += 1) await expect(primaryLinks.nth(index)).toBeVisible()
})

test('platform owner sees seven direct primary links and supports LTR', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await mockPersona(page, 'platform'); await login(page)
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
  // Sidebar destinations are links inside the labelled `القائمة` navigation;
  // the count and order mirror the capability-filtered nav groups exactly.
  const navigation = page.getByRole('navigation', { name: 'القائمة' })
  const primaryLinks = [
    'الرئيسية',
    'المهام',
    'المستندات',
    'المنظمة',
    'الحسابات والصلاحيات',
    'التقارير والمراقبة',
    'إدارة المنصة',
  ]
  const links = navigation.getByRole('link')
  await expect(links).toHaveCount(primaryLinks.length)
  await expect(links).toHaveText(primaryLinks)
  for (const retired of [
    'الطلبات والإجراءات',
    'التكليفات المؤقتة',
    'التفويضات',
    'الأدوات الداخلية',
    'تغطية العمليات',
  ]) {
    await expect(navigation.getByRole('link', { name: retired, exact: true })).toHaveCount(0)
  }
  await navigation.getByRole('link', { name: 'الحسابات والصلاحيات', exact: true }).click()
  // In-workspace surface tabs remain tabs (shadcn `TabsTrigger`), not links.
  await expect(page.getByRole('tab', { name: 'الحسابات', exact: true })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'الأدوار', exact: true })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'تشخيص الوصول', exact: true })).toBeVisible()
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-desktop.png'), fullPage: true })
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await page.getByRole('button', { name: 'العربية' }).click()
  await page.setViewportSize({ width: 320, height: 720 })
  // On mobile the sidebar lives behind the accessible `تبديل الشريط الجانبي`
  // trigger (the legacy hamburger glyph is gone); the name follows the
  // active locale like every other shell control.
  await page.getByRole('button', { name: 'تبديل الشريط الجانبي' }).click()
  const navigationMobile = page.getByRole('navigation', { name: 'القائمة' })
  await expect(navigationMobile).toBeVisible()
  const primaryLinksMobile = navigationMobile.getByRole('link')
  await expect(primaryLinksMobile).toHaveCount(primaryLinks.length)
  for (let index = 0; index < primaryLinks.length; index += 1) {
    await primaryLinksMobile.nth(index).scrollIntoViewIfNeeded()
    await expect(primaryLinksMobile.nth(index)).toBeInViewport()
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-mobile.png') })
})
