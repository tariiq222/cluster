import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const artifactsDir = path.resolve(process.cwd(), '../../artifacts')
const ids = { user: '01980f50-5f0d-7000-8000-000000000101', cluster: '01980f50-5f0d-7000-8000-000000000102', unit: '01980f50-5f0d-7000-8000-000000000103', a: '01980f50-5f0d-7000-8000-000000000104', b: '01980f50-5f0d-7000-8000-000000000105' }
type Persona = 'employee' | 'manager' | 'platform'
const caps: Record<Persona, string[]> = {
  employee: ['workflow.read', 'workflow.list', 'tasks.read', 'tasks.list', 'work_definition.read', 'work_definition.list', 'documents.read', 'documents.list'],
  manager: ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list', 'work_definition.read', 'documents.read', 'reporting.dashboard'],
  platform: ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list', 'work_definition.read', 'documents.read', 'organization.unit.read', 'organization.person.read', 'identity.account.read', 'authorization.role.read', 'authorization.capability.read', 'authorization.assignment.read', 'authorization.audit.read', 'authorization.decision.read', 'reporting.dashboard'],
}

async function mockPersona(page: Page, persona: Persona, twoScopes = false) {
  let selected = ids.a
  await page.route('**/api/v1/identity/me', route => route.fulfill({ status: 401, contentType: 'application/problem+json', body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }) }))
  await page.route('**/api/v1/identity/login', route => route.fulfill({ contentType: 'application/json', headers: { 'set-cookie': 'cluster_identity_session=persona; Path=/', 'x-csrf-token': 'persona-csrf' }, body: JSON.stringify({ data: { user_id: ids.user, expires_at: '2099-07-23T09:00:00Z', restricted: false, csrf_token: 'persona-csrf' } }) }))
  await page.route('**/api/v1/me', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ subject_id: ids.user, tenant_id: ids.cluster, organization_unit_ids: [ids.unit], roles: [persona], capabilities: caps[persona], clearance: 'internal', break_glass: false, correlation_id: ids.user, features: { work_management: false, tasks: true } }) }))
  await page.route('**/api/v1/me/scopes', route => route.fulfill({ contentType: 'application/json', headers: { ETag: selected === ids.a ? '"1"' : '"2"' }, body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: ids.a, label: 'نطاق أ' }, ...(twoScopes ? [{ scope_type: 'facility', scope_id: ids.b, label: 'نطاق ب' }] : [])], effective_scope: { scope_type: 'facility', scope_id: selected, label: selected === ids.a ? 'نطاق أ' : 'نطاق ب' } }) }))
  await page.route('**/api/v1/me/scope', async route => { selected = JSON.parse(route.request().postData() ?? '{}').scope_id; await route.fulfill({ contentType: 'application/json', headers: { ETag: '"2"' }, body: JSON.stringify({ available_scopes: [{ scope_type: 'facility', scope_id: ids.a, label: 'نطاق أ' }, { scope_type: 'facility', scope_id: ids.b, label: 'نطاق ب' }], effective_scope: { scope_type: 'facility', scope_id: selected, label: 'نطاق ب' } }) }) })
  await page.route('**/api/v1/workflow/steps?**', async route => {
    const state = new URL(route.request().url()).searchParams.get('state')
    if (state === 'active') {
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) })
      return
    }
    if (selected === ids.b) await new Promise(resolve => setTimeout(resolve, 250))
    const scopeLabel = selected === ids.a ? 'اعتماد نطاق أ' : 'اعتماد نطاق ب'
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [{ step_id: ids.a, workflow_instance_id: ids.b, source_type: scopeLabel, source_id: selected, state: 'waiting', assignee_user_id: ids.user, created_at: '2026-07-23T08:00:00Z', lock_version: 1, allowed_actions: ['approve'] }], next_cursor: null }) })
  })
  for (const path of ['tasks?limit=100', 'workflow/instances?limit=100', 'dashboards?limit=50', 'work-records?limit=20', 'notifications?limit=20']) await page.route(`**/api/v1/${path}`, route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
}
async function login(page: Page, path = '/') { await page.goto(path); await page.getByLabel('اسم المستخدم').fill('persona'); await page.getByLabel('كلمة المرور', { exact: true }).fill('persona-password'); await page.getByRole('button', { name: 'تسجيل الدخول' }).click() }

test('employee navigation contains only personal work and direct admin URL has no privileged navigation', async ({ page }) => {
  await mockPersona(page, 'employee'); await login(page, '/admin/authorization/roles')
  await expect(page.getByRole('heading', { name: 'لا تملك صلاحية فتح هذه الصفحة' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'الأدوار' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'مهامي', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'المستندات', exact: true })).toBeVisible()
})

test('platform owner sees seven direct primary links and supports LTR', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await mockPersona(page, 'platform'); await login(page)
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
  const primaryLinks = [
    'الرئيسية',
    'مهامي',
    'المستندات',
    'المنشآت والموظفون',
    'الحسابات والصلاحيات',
    'التقارير والمتابعة',
    'إدارة المنصة',
  ]
  for (const label of primaryLinks) {
    await expect(page.getByRole('link', { name: label, exact: true })).toBeVisible()
  }
  for (const label of primaryLinks) {
    const expanded = await page.getByRole('link', { name: label, exact: true }).getAttribute('aria-expanded')
    expect(expanded, `primary link "${label}" should not be an accordion toggle`).toBeNull()
  }
  await expect(page.locator('.navigation-group-toggle')).toHaveCount(0)
  for (const retired of [
    'الطلبات والإجراءات',
    'التكليفات المؤقتة',
    'التفويضات',
    'الأدوات الداخلية',
    'تغطية العمليات',
  ]) {
    await expect(page.getByRole('link', { name: retired, exact: true })).toHaveCount(0)
  }
  await page.getByRole('link', { name: 'الحسابات والصلاحيات', exact: true }).click()
  await expect(page.getByRole('link', { name: 'الحسابات', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'الأدوار والصلاحيات' })).toBeVisible()
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-desktop.png'), fullPage: true })
  await page.getByRole('button', { name: 'طي القائمة الجانبية' }).click()
  const collapsedIcons = page.locator('.desktop-sidebar .primary-navigation a .navigation-icon svg')
  await expect(collapsedIcons).toHaveCount(primaryLinks.length)
  for (let index = 0; index < primaryLinks.length; index += 1) await expect(collapsedIcons.nth(index)).toBeVisible()
  const collapsedLabels = page.locator('.desktop-sidebar .primary-navigation a span:not(.navigation-icon)')
  await expect(collapsedLabels).toHaveCount(0)
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-collapsed.png'), fullPage: true })
  await page.getByRole('button', { name: 'توسيع القائمة الجانبية' }).click()
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await page.getByRole('button', { name: 'العربية' }).click()
  await page.setViewportSize({ width: 320, height: 720 })
  await page.getByRole('button', { name: 'فتح القائمة الرئيسية' }).click()
  const primaryLinksMobile = page.locator('.mobile-navigation .primary-navigation a')
  await expect(primaryLinksMobile).toHaveCount(primaryLinks.length)
  for (let index = 0; index < primaryLinks.length; index += 1) {
    await expect(primaryLinksMobile.nth(index)).toBeInViewport()
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-mobile.png') })
})
