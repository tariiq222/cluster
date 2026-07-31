import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const artifactsDir = path.resolve(process.cwd(), '../../artifacts')
const ids = { user: '01980f50-5f0d-7000-8000-000000000101', cluster: '01980f50-5f0d-7000-8000-000000000102', unit: '01980f50-5f0d-7000-8000-000000000103', a: '01980f50-5f0d-7000-8000-000000000104', b: '01980f50-5f0d-7000-8000-000000000105' }
type Persona = 'employee' | 'manager' | 'platform'
const caps: Record<Persona, string[]> = {
  employee: ['workflow.read', 'workflow.list', 'tasks.read', 'tasks.list', 'work_definition.read', 'work_definition.list', 'documents.read', 'documents.list'],
  manager: ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list', 'work_definition.read', 'documents.read', 'reporting.dashboard'],
  platform: ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list', 'work_definition.read', 'documents.read', 'organization.cluster.read', 'organization.unit.read', 'organization.person.read', 'identity.account.read', 'authorization.role.read', 'authorization.capability.read', 'authorization.assignment.read', 'authorization.audit.read', 'authorization.decision.read', 'reporting.read', 'platform_settings.read'],
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
  // The rebuilt PrincipalProvider reads the principal snapshot from
  // /api/v1/identity/me and expects capabilities/features on the envelope's
  // data object; the same route also answers the session-restore probe.
  await page.route('**/api/v1/identity/me', (route) => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: ids.user,
            csrf_token: 'persona-csrf',
            capabilities: caps[persona],
            features: { work_management: false, tasks: true },
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
  for (const path of ['tasks?limit=100', 'workflow/instances?limit=100', 'dashboards?limit=50', 'work-records?limit=20', 'notifications?limit=**']) await page.route(`**/api/v1/${path}`, route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], next_cursor: null }) }))
  // Governance workspace feeds for the platform persona: roles list and the
  // capability catalog behind the Roles tab.
  await page.route('**/api/v1/authorization/roles**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: [], next_cursor: null } }) }))
  await page.route('**/api/v1/authorization/capabilities**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: [], next_cursor: null } }) }))
}
async function login(page: Page, path = '/') { await page.goto(path); await page.getByLabel('اسم المستخدم').fill('persona'); await page.getByLabel('كلمة المرور', { exact: true }).fill('persona-password'); await page.getByRole('button', { name: 'تسجيل الدخول' }).click() }

test('employee navigation contains only personal work and a direct admin URL has no privileged navigation', async ({ page }) => {
  await mockPersona(page, 'employee'); await login(page, '/accounts-permissions')
  await expect(page.getByText('لا تملك الصلاحية المطلوبة لعرض هذا القسم.')).toBeVisible()
  const primaryLinks = page.locator('.shell__nav .shell__nav-item')
  await expect(primaryLinks).toHaveCount(3)
  await expect(primaryLinks).toHaveText(['الرئيسية', 'المهام', 'المستندات'])
  for (let index = 0; index < 3; index += 1) await expect(primaryLinks.nth(index)).toBeVisible()
})

test('platform owner sees seven direct primary links and supports LTR', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await mockPersona(page, 'platform'); await login(page)
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
  const primaryLinks = [
    'الرئيسية',
    'المهام',
    'المستندات',
    'المنظمة',
    'الحسابات والصلاحيات',
    'التقارير والمراقبة',
    'إدارة المنصة',
  ]
  for (const label of primaryLinks) {
    await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible()
  }
  for (const retired of [
    'الطلبات والإجراءات',
    'التكليفات المؤقتة',
    'التفويضات',
    'الأدوات الداخلية',
    'تغطية العمليات',
  ]) {
    await expect(page.getByRole('button', { name: retired, exact: true })).toHaveCount(0)
  }
  await page.getByRole('button', { name: 'الحسابات والصلاحيات', exact: true }).click()
  await expect(page.getByRole('button', { name: 'الحسابات', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'الأدوار', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'مفتش القرارات', exact: true })).toBeVisible()
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-desktop.png'), fullPage: true })
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await page.getByRole('button', { name: 'العربية' }).click()
  await page.setViewportSize({ width: 320, height: 720 })
  await page.getByRole('button', { name: '☰' }).click()
  const primaryLinksMobile = page.locator('.shell__nav .shell__nav-item')
  await expect(primaryLinksMobile).toHaveCount(primaryLinks.length)
  for (let index = 0; index < primaryLinks.length; index += 1) {
    await expect(primaryLinksMobile.nth(index)).toBeInViewport()
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
  await page.screenshot({ path: path.join(artifactsDir, 'sidebar-primary-mobile.png') })
})
