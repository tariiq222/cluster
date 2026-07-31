import { expect, test, type Page, type Route } from '@playwright/test'

/**
 * Deterministic W1.1 coverage for the Accounts & Permissions workspace.
 * All intercepted requests are made by the browser to the configured local
 * API origin; no remote service, fixture account, or shared mutable seed is
 * required. Fixture UUIDs never appear as rendered copy.
 *
 * The rebuilt screen has three tabs — Accounts / Roles / Decision inspector —
 * so the legacy fifth-tab surfaces (scope pickers, policies, delegations)
 * are not exercised here.
 *
 * Network guard:
 *   Every journey installs `enforceLocalApiOnly` BEFORE any other route
 *   handler so the test fails closed the moment the app issues a request the
 *   spec didn't authorise. This is the only safety net against accidental
 *   external traffic when the local API origin is offline.
 */
const ids = {
  admin: '01980f50-5f0d-7000-8000-000000000801',
  tenant: '01980f50-5f0d-7000-8000-000000000802',
  unit: '01980f50-5f0d-7000-8000-000000000803',
  facility: '01980f50-5f0d-7000-8000-000000000804',
  systemRole: '01980f50-5f0d-7000-8000-000000000805',
  customRole: '01980f50-5f0d-7000-8000-000000000806',
  account: '01980f50-5f0d-7000-8000-000000000807',
  decision: '01980f50-5f0d-7000-8000-000000000809',
} as const

const fullCapabilities = [
  'identity.account.read', 'authorization.role.read', 'authorization.role.manage',
  'authorization.capability.read', 'authorization.assignment.read', 'authorization.assignment.manage',
  'authorization.policy.read', 'authorization.policy.manage', 'authorization.decision.read',
] as const

/**
 * ETag progression mirrors the server-side lock_version contract:
 *   system role ships at lock_version 1; a successful clone bumps the freshly
 *   materialised custom role to lock_version 2; the first edit on that custom
 *   role yields lock_version 3.
 */
const systemRole = {
  id: ids.systemRole, code: 'finance.system', name_en: 'Finance reviewer', name_ar: 'مراجع مالي',
  is_system_role: true, role_type: 'system' as const, status: 'active' as const,
  lock_version: 1, capability_codes: ['records.read'], allowed_actions: ['clone'],
}

const customRole = {
  id: ids.customRole, code: 'finance.custom', name_en: 'Finance reviewer (custom)', name_ar: 'مراجع مالي مخصص',
  is_system_role: false, role_type: 'custom' as const, status: 'active' as const,
  lock_version: 2, capability_codes: ['records.read'], allowed_actions: ['edit', 'archive'],
}

const customRoleAfterEdit = {
  ...customRole, lock_version: 3, name_en: 'Finance approver',
}

async function json(route: Route, data: unknown, status = 200, headers: Record<string, string> = {}): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', headers, body: JSON.stringify(data) })
}

type WorkspaceOptions = {
  capabilities?: readonly string[]
  roles?: ReadonlyArray<{ id: string; code: string; name_en: string; name_ar: string; is_system_role: boolean; role_type: 'system' | 'custom'; status: 'active' | 'archived'; lock_version: number; capability_codes: string[]; allowed_actions: string[] }>
}

/**
 * Network guard. Registered FIRST so every other handler that completes the
 * request wins the race; any `/api/v1/**` call the spec forgot to mock throws
 * an explicit error instead of leaking to a remote origin.
 */
async function enforceLocalApiOnly(page: Page): Promise<void> {
  // Catch every request that targets the configured API origin. We avoid
  // wildcarding "**/api/**" because Vite also serves TypeScript sources
  // from `/src/api/...` during dev, and those are legitimate file requests,
  // not network calls. The Vite dev proxy forwards `${origin}/api/v1/**`
  // to the API, so a path match anchored on `/api/v1/` is precise enough
  // to catch any unmocked business mutation.
  await page.route('**/api/v1/**', async (route) => {
    throw new Error(`Unexpected /api/v1/** request: ${route.request().method()} ${route.request().url()}`)
  })
}

async function mockWorkspace(page: Page, options: WorkspaceOptions = {}): Promise<void> {
  const capabilities = options.capabilities ?? fullCapabilities
  const seenRoles: () => unknown = () => [systemRole, customRole]

  let authenticated = false
  await page.route('**/api/v1/identity/me', (route) => {
    if (!authenticated) {
      return json(route, { type: 'about:blank', title: 'Unauthorized', status: 401 }, 401)
    }
    return json(route, {
      data: {
        user_id: ids.admin,
        csrf_token: 'accounts-csrf',
        capabilities: [...capabilities],
        features: { work_management: false, tasks: false },
      },
    })
  })
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true
    return json(route, { data: { user_id: ids.admin, expires_at: '2099-01-01T00:00:00Z', restricted: false, csrf_token: 'accounts-csrf' } }, 200, { 'set-cookie': 'cluster_identity_session=accounts; Path=/; HttpOnly; SameSite=Lax', 'x-csrf-token': 'accounts-csrf' })
  })
  await page.route('**/api/v1/identity/csrf', (route) => json(route, { data: { csrf_token: 'accounts-csrf' } }))
  await page.route('**/api/v1/me/scopes', (route) => json(route, { available_scopes: [{ scope_type: 'facility', scope_id: ids.facility, label: 'North facility' }, { scope_type: 'unit', scope_id: ids.unit, label: 'North unit' }], effective_scope: { scope_type: 'facility', scope_id: ids.facility, label: 'North facility' } }, 200, { ETag: '"1"' }))
  await page.route('**/api/v1/notifications?limit=**', (route) => json(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/work-records?limit=**', (route) => json(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/identity/accounts?**', (route) => json(route, { items: [{ id: ids.account, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية', status: 'active', must_change_password: false }], next_cursor: null }))
  await page.route('**/api/v1/organization/people?**', (route) => json(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/authorization/capabilities**', (route) => json(route, { data: { items: [{ id: '01980f50-5f0d-7000-8000-000000000810', code: 'records.read', name_en: 'Read records' }], next_cursor: null } }))

  await page.route('**/api/v1/authorization/roles', (route) => {
    const items = seenRoles()
    return json(route, { data: { items, next_cursor: null } }, 200, { ETag: `"${items.length}"` })
  })

  await page.route(`**/api/v1/authorization/roles/${ids.systemRole}/clone`, async (route) => {
    expect(route.request().method()).toBe('POST')
    expect(route.request().headers()['if-match']).toBe('"1"')
    expect(route.request().headers()['idempotency-key']).toMatch(/^authorization-role-clone-/)
    expect(route.request().headers()['x-csrf-token']).toBeTruthy()
    await json(route, { data: customRole }, 201, { ETag: '"2"' })
  })
  // Direct PATCH on the system role demonstrates the documented 409 problem
  // envelope. Any non-PATCH method falls through to Playwright's default so
  // future GET probes do not accidentally hit this handler.
  await page.route(`**/api/v1/authorization/roles/${ids.systemRole}`, async (route) => {
    if (route.request().method() !== 'PATCH') {
      await route.fallback()
      return
    }
    expect(route.request().headers()['content-type']).toContain('application/merge-patch+json')
    await route.fulfill({
      status: 409,
      headers: { 'content-type': 'application/problem+json' },
      body: JSON.stringify({
        type: 'urn:cluster:problem:system-role-immutable',
        title: 'Immutable system role',
        status: 409,
        detail: 'Clone the system role before editing it.',
      }),
    })
  })

  await page.route(`**/api/v1/authorization/roles/${ids.customRole}`, async (route) => {
    const ifMatch = route.request().headers()['if-match']
    if (route.request().method() === 'GET') {
      return json(route, { data: customRole }, 200, { ETag: `"${customRole.lock_version}"` })
    }
    expect(route.request().method()).toBe('PATCH')
    expect(ifMatch).toBe('"2"')
    expect(route.request().headers()['content-type']).toContain('application/merge-patch+json')
    await json(route, { data: customRoleAfterEdit }, 200, { ETag: '"3"' })
  })

  await page.route('**/api/v1/authorization/access-decisions/**/explanation', (route) => json(route, { type: 'about:blank', title: 'Forbidden', status: 403, detail: 'You cannot inspect this decision.' }, 403))
  await page.route(`**/api/v1/authorization/access-decisions/${ids.decision}/explanation`, (route) => json(route, { data: { decision_id: ids.decision, decision: 'allow', action: 'records.read', resource_type: 'record', reason_codes: [], assignment_summaries: [], policy_references: [], applies_in_plain_language: 'Finance officer may read records in North facility.' } }))
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('authorization-admin')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('authorization-admin-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function openWorkspace(page: Page): Promise<void> {
  await page.goto('/accounts-permissions')
  await expect(page.getByRole('heading', { name: 'الحسابات والصلاحيات' })).toBeVisible()
}

async function openRolesTab(page: Page): Promise<void> {
  await openWorkspace(page)
  await page.getByRole('button', { name: 'الأدوار', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'الأدوار والصلاحيات' })).toBeVisible()
}

test('three governance tabs keep their order, RTL flow, and never leak fixture UUIDs', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openWorkspace(page)

  const workspaceNav = page.getByRole('navigation', { name: 'مساحات الحسابات والصلاحيات' })
  const tabs = workspaceNav.getByRole('button')
  await expect(tabs).toHaveText(['الحسابات', 'الأدوار', 'مفتش القرارات'])
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

  // UUIDs must never leak into rendered copy.
  await expect(page.getByText(ids.systemRole, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.customRole, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.decision, { exact: false })).toHaveCount(0)

  // Each tab renders its canonical panel.
  await expect(page.getByRole('heading', { name: 'حسابات الدخول' })).toBeVisible()
  await page.getByRole('button', { name: 'الأدوار', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'الأدوار والصلاحيات' })).toBeVisible()
  await page.getByRole('button', { name: 'مفتش القرارات', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'مفتش قرارات الوصول' })).toBeVisible()
})

test('system roles must be cloned before custom-role editing; direct system PATCH is documented as immutable', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openRolesTab(page)

  // Clone: POST with If-Match "1", idempotency-key prefix, CSRF token;
  // server returns the freshly materialised custom role at lock_version 2.
  const cloneRequest = waitForRequest(page, (req) => req.url.endsWith(`/api/v1/authorization/roles/${ids.systemRole}/clone`) && req.method === 'POST')
  await page.getByRole('button', { name: 'نسخ كدور مخصص' }).click()
  const clone = await cloneRequest
  expect(clone.headers['if-match']).toBe('"1"')
  expect(clone.headers['idempotency-key']).toMatch(/^authorization-role-clone-/)
  expect(clone.headers['x-csrf-token']).toBeTruthy()

  await expect(page.getByText('مراجع مالي مخصص')).toBeVisible()

  // Edit: PATCH with If-Match "2" (system role is at 1, cloned custom role at 2),
  // Content-Type merge-patch+json, advances lock_version to 3.
  const editRequest = waitForRequest(page, (req) => req.url.endsWith(`/api/v1/authorization/roles/${ids.customRole}`) && req.method === 'PATCH')
  await page.getByRole('button', { name: 'تعديل', exact: true }).click()
  await page.getByLabel('اسم الدور').fill('Finance approver')
  await page.getByRole('button', { name: 'حفظ التغييرات' }).click()
  const edit = await editRequest
  expect(edit.headers['if-match']).toBe('"2"')
  expect(edit.headers['content-type']).toContain('application/merge-patch+json')

  // Direct PATCH on the system role itself is the documented immutable path.
  // Capture both the request AND the response so the 409 problem envelope
  // is asserted end-to-end: status, content-type, and the documented
  // `urn:cluster:problem:system-role-immutable` type.
  const directPatch = waitForRequest(page, (req) => req.url.endsWith(`/api/v1/authorization/roles/${ids.systemRole}`) && req.method === 'PATCH')
  const directResponse = page.waitForResponse(
    (res) => res.url().endsWith(`/api/v1/authorization/roles/${ids.systemRole}`) && res.request().method() === 'PATCH',
    { timeout: 15_000 },
  )
  await page.evaluate(async (roleId) => {
    await fetch(`/api/v1/authorization/roles/${roleId}`, {
      method: 'PATCH',
      credentials: 'include',
      headers: {
        'Accept': 'application/json, application/problem+json',
        'Content-Type': 'application/merge-patch+json',
        'If-Match': '"1"',
        'Idempotency-Key': `authorization-role-edit-direct-${Date.now()}`,
      },
      body: JSON.stringify({ name: 'Manual override' }),
    })
  }, ids.systemRole)
  const directFetch = await directPatch
  // The fetch we just issued is the one that was intercepted.
  expect(directFetch.url).toContain(`/api/v1/authorization/roles/${ids.systemRole}`)
  const directPatchResponse = await directResponse
  expect(directPatchResponse.status()).toBe(409)
  const directContentType = directPatchResponse.headers()['content-type'] ?? ''
  expect(directContentType).toContain('application/problem+json')
  const directBody = await directPatchResponse.json()
  expect(directBody.type).toBe('urn:cluster:problem:system-role-immutable')
  expect(directBody.status).toBe(409)

  await expect(page.getByText(ids.systemRole, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.customRole, { exact: false })).toHaveCount(0)
})

test('decision inspector renders plain-language explanation and an ineligible principal stays denied', async ({ browser }) => {
  // Two journeys that share a browser but mount PrincipalProvider with
  // different capability projections.
  const privileged = await browser.newContext({ locale: 'ar-SA' })
  const privilegedPage = await privileged.newPage()
  await enforceLocalApiOnly(privilegedPage)
  await mockWorkspace(privilegedPage)
  await signIn(privilegedPage)
  await openWorkspace(privilegedPage)
  await privilegedPage.getByRole('button', { name: 'مفتش القرارات', exact: true }).click()
  await privilegedPage.getByLabel('معرّف القرار').fill(ids.decision)
  await privilegedPage.getByRole('button', { name: 'فحص' }).click()
  await expect(privilegedPage.getByText('Finance officer may read records in North facility.')).toBeVisible()
  await expect(privilegedPage.locator('html')).toHaveAttribute('dir', 'rtl')
  await privileged.close()

  const restricted = await browser.newContext({ locale: 'ar-SA' })
  const restrictedPage = await restricted.newPage()
  await enforceLocalApiOnly(restrictedPage)
  await mockWorkspace(restrictedPage, { capabilities: ['authorization.role.read'] })
  await signIn(restrictedPage)
  await openWorkspace(restrictedPage)
  await restrictedPage.getByRole('button', { name: 'مفتش القرارات', exact: true }).click()
  await expect(restrictedPage.getByText('لا تملك الصلاحية المطلوبة لعرض هذا القسم.')).toBeVisible()
  // The URL survives the deep-link visit (no redirect away from the path).
  await expect.poll(() => new URL(restrictedPage.url()).pathname).toBe('/accounts-permissions')
  await restricted.close()
})

test('mutation feedback proves the 412 path through the alert region', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openRolesTab(page)

  // Stage a 412 on the edit endpoint; the custom role still has to exist.
  await page.route(`**/api/v1/authorization/roles/${ids.customRole}`, async (route) => {
    if (route.request().method() === 'PATCH') {
      await json(route, { type: 'about:blank', title: 'Precondition failed', status: 412, detail: 'This role changed; reload before saving.' }, 412)
      return
    }
    throw new Error(`Unexpected method ${route.request().method()}`)
  })

  // Clone so the custom role exists.
  await page.getByRole('button', { name: 'نسخ كدور مخصص' }).click()
  await expect(page.getByText('مراجع مالي مخصص')).toBeVisible()

  // Edit + save — 412 path.
  await page.getByRole('button', { name: 'تعديل', exact: true }).click()
  await page.getByLabel('اسم الدور').fill('Conflicting edit')
  const recoveryPatch = waitForRequest(page, (req) => req.url.endsWith(`/api/v1/authorization/roles/${ids.customRole}`) && req.method === 'PATCH')
  await page.getByRole('button', { name: 'حفظ التغييرات' }).click()
  const patch = await recoveryPatch
  expect(patch.headers['if-match']).toBe('"2"')
  expect(patch.headers['content-type']).toContain('application/merge-patch+json')
  expect(patch.headers['x-csrf-token']).toBeTruthy()

  // The runtime surfaces the conflict through the alert region with the
  // problem title; no recovery buttons exist on the rebuilt form.
  await expect(page.getByRole('alert')).toContainText('Precondition failed')
})

type CapturedRequest = { method: string; url: string; headers: Record<string, string> }
type RequestPredicate = (req: CapturedRequest) => boolean

/**
 * Wait for the next Playwright request that satisfies `predicate`. The route
 * must already be installed via `page.route()` before this helper runs so the
 * request finishes immediately and we do not leak any unmocked call.
 */
function waitForRequest(page: Page, predicate: RequestPredicate): Promise<CapturedRequest> {
  return page.waitForRequest(
    (req) => predicate({ method: req.method(), url: req.url(), headers: req.headers() }),
    { timeout: 15_000 },
  ).then((req) => ({ method: req.method(), url: req.url(), headers: req.headers() }))
}
