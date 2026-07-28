import { expect, test, type Page } from '@playwright/test'

const USER = '01980f50-5f0d-7000-8000-000000000001'
const FACILITY = '01980f50-5f0d-7000-8000-000000000002'
const UNIT = '01980f50-5f0d-7000-8000-000000000003'
const CLUSTER = '01980f50-5f0d-7000-8000-000000000004'
const capabilities = ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list', 'work_definition.read', 'documents.read']

async function openShell(page: Page) {
  await page.route('**/api/v1/identity/login', route => route.fulfill({ status: 200, contentType: 'application/json', headers: { 'set-cookie': 'cluster_identity_session=shell; Path=/; HttpOnly; SameSite=Lax', 'x-csrf-token': 'shell-csrf' }, body: JSON.stringify({ data: { user_id: USER, expires_at: '2026-07-23T09:00:00Z', restricted: false, csrf_token: 'shell-csrf' } }) }))
  await page.route('**/api/v1/me/scopes', route => route.fulfill({ status: 200, contentType: 'application/json', headers: { ETag: '"1"' }, body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' }], effective_scope: { scope_type: 'facility', scope_id: FACILITY, label: 'منشأة الاختبار' } }) }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ subject_id: USER, tenant_id: CLUSTER, organization_unit_ids: [UNIT], roles: ['shell'], capabilities, clearance: 'internal', break_glass: false, correlation_id: '01980f50-5f0d-7000-8000-000000000005', features: { work_management: true, tasks: true } }) }))
  await page.route('**/api/v1/workflow/steps?**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/tasks?limit=100', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/workflow/instances?limit=100', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/dashboards?limit=50', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/work-records?limit=20', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.route('**/api/v1/notifications?limit=20', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('shell-user')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('shell-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

test('authenticated shell renders current dashboard queues in RTL desktop', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await openShell(page)
  await expect(page.getByRole('navigation', { name: 'التنقل الرئيسي' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'ما يحتاجك الآن' })).toBeVisible()
  await expect(page.locator('.work-dashboard-kpi')).toHaveCount(4)
  await page.getByRole('button', { name: 'طي القائمة الجانبية' }).click()
  await expect(page.locator('.app-shell')).toHaveAttribute('data-sidebar-collapsed', 'true')
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
})

test('mobile navigation drawer remains keyboard-dismissible', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 720 })
  await openShell(page)
  const menu = page.getByRole('button', { name: 'فتح القائمة الرئيسية' })
  await menu.click()
  await expect(page.getByRole('dialog', { name: 'التنقل الرئيسي' })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', { name: 'التنقل الرئيسي' })).toBeHidden()
  await expect(menu).toBeFocused()
})
