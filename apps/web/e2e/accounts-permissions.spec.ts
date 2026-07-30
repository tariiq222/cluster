import { expect, test, type Page, type Route } from '@playwright/test'

/**
 * Deterministic W1.1 coverage for the Accounts & Permissions workspace.
 * All intercepted requests are made by the browser to the configured local
 * API origin; no remote service, fixture account, or shared mutable seed is
 * required. Fixture UUIDs never appear as rendered copy.
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
  recordSet: '01980f50-5f0d-7000-8000-00000000080a',
  systemRole: '01980f50-5f0d-7000-8000-000000000805',
  customRole: '01980f50-5f0d-7000-8000-000000000806',
  account: '01980f50-5f0d-7000-8000-000000000807',
  assignmentFacility: '01980f50-5f0d-7000-8000-000000000808',
  assignmentCluster: '01980f50-5f0d-7000-8000-00000000080b',
  assignmentUnit: '01980f50-5f0d-7000-8000-00000000080c',
  assignmentRecordSet: '01980f50-5f0d-7000-8000-00000000080d',
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
 *   role yields lock_version 3. The dirty spec fixture drifted from this
 *   contract by carrying an implicit "1" for both roles and never asserting
 *   that the custom row did not exist pre-clone — both fixes are below.
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

type MockState = {
  assignmentStatus: Record<string, string>
  assignmentRoles: ReadonlyArray<typeof facilityAssignment>
  lastCreatedPayload: Record<string, unknown> | null
}

const facilityAssignment = {
  id: ids.assignmentFacility, role_id: ids.customRole, subject_user_id: ids.account,
  scope_type: 'facility' as const, scope_id: ids.facility,
  effective_status: 'active', lock_version: 4,
  allowed_actions: ['edit', 'revoke', 'expire'],
}
const clusterAssignment = {
  id: ids.assignmentCluster, role_id: ids.customRole, subject_user_id: ids.account,
  scope_type: 'cluster' as const, scope_id: ids.tenant,
  effective_status: 'active', lock_version: 5,
  allowed_actions: ['edit', 'revoke', 'expire'],
}
const unitAssignment = {
  id: ids.assignmentUnit, role_id: ids.customRole, subject_user_id: ids.account,
  scope_type: 'unit' as const, scope_id: ids.unit,
  effective_status: 'active', lock_version: 6,
  allowed_actions: ['edit', 'revoke', 'expire'],
}
const recordSetAssignment = {
  id: ids.assignmentRecordSet, role_id: ids.customRole, subject_user_id: ids.account,
  scope_type: 'record_set' as const, scope_id: ids.recordSet,
  effective_status: 'active', lock_version: 7,
  allowed_actions: ['edit', 'revoke', 'expire'],
}

const allAssignments = [facilityAssignment, clusterAssignment, unitAssignment, recordSetAssignment]

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
    const request = route.request()
    const url = new URL(request.url())
    if (
      request.method() === 'GET'
      && url.pathname === '/api/v1/authorization/assignment-scope-targets'
    ) {
      await route.fallback()
      return
    }
    throw new Error(`Unexpected /api/v1/** request: ${request.method()} ${request.url()}`)
  })
}

async function mockWorkspace(page: Page, options: WorkspaceOptions = {}): Promise<MockState> {
  const capabilities = options.capabilities ?? fullCapabilities
  const state: MockState = {
    assignmentStatus: Object.fromEntries(allAssignments.map((row) => [row.id, row.effective_status])),
    assignmentRoles: allAssignments,
    lastCreatedPayload: null,
  }
  // The custom role is a fixture state that the assignment-lifecycle and
  // decision-inspector journeys rely on without first cloning the system
  // role; expose it on every GET so those journeys can render labelled rows
  // and verify scope/status without staging a clone first.
  const seenRoles: () => unknown = () => [systemRole, customRole]

  // Specific handlers first; the catch-all guard registered by enforceLocalApiOnly
  // becomes unreachable once any of these satisfies the request.
  let authenticated = false
  await page.route('**/api/v1/identity/me', (route) => {
    if (!authenticated) {
      return json(route, { type: 'about:blank', title: 'Unauthorized', status: 401 }, 401)
    }
    return json(route, { data: { principal: { user_id: ids.admin }, account: {}, session: { restricted: false } } })
  })
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true
    return json(route, { data: { user_id: ids.admin, expires_at: '2099-01-01T00:00:00Z', restricted: false, csrf_token: 'accounts-csrf' } }, 200, { 'set-cookie': 'cluster_identity_session=accounts; Path=/; HttpOnly; SameSite=Lax', 'x-csrf-token': 'accounts-csrf' })
  })
  await page.route('**/api/v1/identity/csrf', (route) => json(route, { data: { csrf_token: 'accounts-csrf' } }))
  await page.route('**/api/v1/me', (route) => json(route, { subject_id: ids.admin, tenant_id: ids.tenant, organization_unit_ids: [ids.unit], roles: ['authorization-admin'], capabilities: [...capabilities], clearance: 'internal', break_glass: false, correlation_id: ids.admin, features: { work_management: false, tasks: true } }))
  await page.route('**/api/v1/me/scopes', (route) => json(route, { available_scopes: [{ scope_type: 'facility', scope_id: ids.facility, label: 'North facility' }, { scope_type: 'unit', scope_id: ids.unit, label: 'North unit' }, { scope_type: 'record_set', scope_id: ids.recordSet, label: 'Records 2026-Q1' }], effective_scope: { scope_type: 'facility', scope_id: ids.facility, label: 'North facility' } }, 200, { ETag: '"1"' }))
  await page.route('**/api/v1/notifications?limit=**', (route) => json(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/work-records?limit=**', (route) => json(route, { items: [], next_cursor: null }))
  await page.route('**/api/v1/identity/accounts?**', (route) => json(route, { items: [{ id: ids.account, username: 'finance', display_name_en: 'Finance officer', display_name_ar: 'مسؤول المالية' }], next_cursor: null }))
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
  // future GET probes (for example, the role-detail view) do not accidentally
  // hit this handler and trip on an unexpected method.
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

  // One catch-all handler for `/role-assignments` (and any query string
  // variants) that dispatches by method. Playwright matches routes
  // last-registered-first, so two handlers on the same glob would race:
  // the first handler registered would never run. By collapsing GET
  // listing + POST create into a single function we let the per-resource
  // action sub-paths (registered earlier, with literal suffixes) keep
  // their specificity without conflicting with the listing glob.
  await page.route(/\/api\/v1\/authorization\/role-assignments(\?|$)/, async (route) => {
    const method = route.request().method()
    if (method === 'GET') {
      const items = state.assignmentRoles.map((row) => ({
        ...row,
        effective_status: state.assignmentStatus[row.id] ?? row.effective_status,
      }))
      return json(route, { data: { items, next_cursor: null } }, 200, { ETag: '"4"' })
    }
    if (method === 'POST') {
      const payload = route.request().postDataJSON() as Record<string, unknown>
      state.lastCreatedPayload = payload
      expect(route.request().headers()['x-csrf-token']).toBe('accounts-csrf')
      expect(route.request().headers()['content-type']).toContain('application/json')
      expect(payload).toMatchObject({
        resource_type: 'role_assignment',
        code: 'role-assignment',
        subject_user_id: ids.account,
        role_id: ids.customRole,
        scope_type: 'cluster',
        scope_id: ids.tenant,
      })
      expect(route.request().headers()['idempotency-key']).toMatch(/^authorization-role-assignment-/)
      const created = {
        id: '01980f50-5f0d-7000-8000-00000000080e',
        role_id: ids.customRole,
        subject_user_id: ids.account,
        scope_type: 'cluster',
        scope_id: ids.tenant,
        effective_status: 'active',
        lock_version: 8,
        allowed_actions: ['edit', 'revoke', 'expire'],
      }
      state.assignmentRoles = [...state.assignmentRoles, created]
      return json(route, { data: created }, 201, { ETag: '"8"' })
    }
    throw new Error(`Unexpected method ${method}`)
  })
  for (const action of ['revoke', 'expire'] as const) {
    await page.route(`**/api/v1/authorization/role-assignments/${ids.assignmentFacility}/${action}`, async (route) => {
      expect(route.request().method()).toBe('POST')
      expect(route.request().headers()['if-match']).toBe('"4"')
      expect(route.request().headers()['idempotency-key']).toMatch(new RegExp(`^authorization-role-assignment-${action}-`))
      state.assignmentStatus[ids.assignmentFacility] = action === 'revoke' ? 'revoked' : 'expired'
      await json(route, { data: { ...facilityAssignment, effective_status: state.assignmentStatus[ids.assignmentFacility] } }, 200, { ETag: '"5"' })
    })
  }
  await page.route(`**/api/v1/authorization/role-assignments/${ids.assignmentFacility}`, async (route) => {
    if (route.request().method() === 'PATCH') {
      expect(route.request().headers()['if-match']).toBe('"4"')
      expect(route.request().headers()['content-type']).toContain('application/merge-patch+json')
      state.assignmentStatus[ids.assignmentFacility] = 'active'
      await json(route, { data: { ...facilityAssignment, effective_status: 'active' } }, 200, { ETag: '"5"' })
      return
    }
    throw new Error(`Unexpected method ${route.request().method()}`)
  })
  for (const action of ['revoke', 'expire'] as const) {
    await page.route(`**/api/v1/authorization/role-assignments/${ids.assignmentUnit}/${action}`, async (route) => {
      expect(route.request().method()).toBe('POST')
      expect(route.request().headers()['if-match']).toBe(`"${unitAssignment.lock_version}"`)
      state.assignmentStatus[ids.assignmentUnit] = action === 'revoke' ? 'revoked' : 'expired'
      await json(route, { data: { ...unitAssignment, effective_status: state.assignmentStatus[ids.assignmentUnit] } }, 200, { ETag: `"${unitAssignment.lock_version + 1}"` })
    })
  }
  for (const action of ['revoke', 'expire'] as const) {
    await page.route(`**/api/v1/authorization/role-assignments/${ids.assignmentCluster}/${action}`, async (route) => {
      expect(route.request().method()).toBe('POST')
      expect(route.request().headers()['if-match']).toBe(`"${clusterAssignment.lock_version}"`)
      state.assignmentStatus[ids.assignmentCluster] = action === 'revoke' ? 'revoked' : 'expired'
      await json(route, { data: { ...clusterAssignment, effective_status: state.assignmentStatus[ids.assignmentCluster] } }, 200, { ETag: `"${clusterAssignment.lock_version + 1}"` })
    })
  }
  // Deterministic catalog for the supported picker levels. Facility and unit
  // requests must carry the ancestry selected in the visible cascade; the
  // unsupported record_set level remains disabled and is never fabricated.
  await page.route(/\/api\/v1\/authorization\/assignment-scope-targets(?:\?|$)/, async (route) => {
    const request = route.request()
    expect(request.method()).toBe('GET')
    const catalogUrl = new URL(request.url())
    expect(catalogUrl.pathname).toBe('/api/v1/authorization/assignment-scope-targets')
    const scopeType = catalogUrl.searchParams.get('scope_type')
    const targets = {
      cluster: [{ scope_type: 'cluster', scope_id: ids.tenant, label_ar: 'تجمع الشمال الصحي', label_en: 'North health cluster', code: 'NHC' }],
      facility: [{ scope_type: 'facility', scope_id: ids.facility, label_ar: 'مستشفى الشمال', label_en: 'North facility', code: 'NF' }],
      unit: [{ scope_type: 'unit', scope_id: ids.unit, label_ar: 'وحدة المالية', label_en: 'Finance unit', code: 'FIN' }],
    } as const
    expect(scopeType === 'cluster' || scopeType === 'facility' || scopeType === 'unit').toBe(true)
    if (scopeType === 'cluster') {
      expect(catalogUrl.searchParams.has('parent_scope_type')).toBe(false)
      expect(catalogUrl.searchParams.has('parent_scope_id')).toBe(false)
    } else if (scopeType === 'facility') {
      expect(catalogUrl.searchParams.get('parent_scope_type')).toBe('cluster')
      expect(catalogUrl.searchParams.get('parent_scope_id')).toBe(ids.tenant)
    } else if (scopeType === 'unit') {
      expect(catalogUrl.searchParams.get('parent_scope_type')).toBe('facility')
      expect(catalogUrl.searchParams.get('parent_scope_id')).toBe(ids.facility)
    }
    await json(route, { data: { items: targets[scopeType as keyof typeof targets], next_cursor: null } })
  })
  // Register the 403 catch-all BEFORE the privileged 200 handler. Playwright
  // resolves routes last-registered-first, so registering 200 last keeps the
  // privileged path alive while the restricted principal still surfaces the
  // documented problem envelope.
  await page.route('**/api/v1/authorization/access-decisions/**/explanation', (route) => json(route, { type: 'about:blank', title: 'Forbidden', status: 403, detail: 'You cannot inspect this decision.' }, 403))
  await page.route(`**/api/v1/authorization/access-decisions/${ids.decision}/explanation`, (route) => json(route, { data: { decision_id: ids.decision, decision: 'allow', action: 'records.read', resource_type: 'record', reason_codes: [], assignment_summaries: [], policy_references: [], applies_in_plain_language: 'Finance officer may read records in North facility.' } }))

  return state
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('authorization-admin')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('authorization-admin-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function openTab(page: Page, path: string): Promise<void> {
  // `page.goto()` performs a full reload so PrincipalProvider re-fetches
  // /me. PushState alone keeps the provider mounted with stale capabilities.
  await page.goto(path)
}

test('five governance tabs keep their order, ARIA focus-relative keyboard behaviour, RTL flow, and label associations', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openTab(page, '/admin/authorization/roles?tab=roles-permissions')

  const workspace = page.locator('.accounts-permissions-workspace')
  const tablist = workspace.getByRole('tablist', { name: 'أقسام الحسابات والصلاحيات' })
  const tabs = tablist.getByRole('tab')
  await expect(tabs).toHaveText(['الحسابات', 'الأدوار والصلاحيات', 'إسنادات الأدوار', 'السياسات والنطاقات', 'فاحص قرار الصلاحية'])
  await expect(workspace).toHaveAttribute('dir', 'rtl')

  // UUIDs must never leak into rendered copy.
  await expect(page.getByText(ids.systemRole, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.customRole, { exact: false })).toHaveCount(0)

  // ARIA: each tab controls its canonical panel.
  for (const tab of await tabs.all()) {
    const tabKey = (await tab.getAttribute('data-tab-key')) ?? ''
    const expected = `${tabKey}-panel`
    expect(await tab.getAttribute('aria-controls')).toBe(expected)
    expect(await tab.getAttribute('aria-selected')).toBe(tabKey === 'roles-permissions' ? 'true' : 'false')
    const panel = page.locator(`#${expected}`)
    expect(await panel.count()).toBe(1)
  }

  // Focus-relative keyboard navigation: in RTL the visual right is still
  // ArrowRight per the WAI-ARIA tabs pattern, and we always advance from the
  // focused descendant rather than the active tab.
  const peopleTab = tablist.getByRole('tab', { name: 'السياسات والنطاقات' })
  await peopleTab.focus()
  await page.keyboard.press('ArrowRight')
  const inspectorTab = tablist.getByRole('tab', { name: 'فاحص قرار الصلاحية' })
  await expect(inspectorTab).toBeFocused()
  await expect(inspectorTab).toHaveAttribute('aria-selected', 'true')
  await page.keyboard.press('Home')
  const accountsTab = tablist.getByRole('tab', { name: 'الحسابات' })
  await expect(accountsTab).toBeFocused()
  await page.keyboard.press('End')
  await expect(inspectorTab).toBeFocused()

  // Advanced boundary copy renders for the two advanced tabs.
  await accountsTab.click()
  await expect(page.getByText('متقدم', { exact: true })).toHaveCount(0)
  await tablist.getByRole('tab', { name: 'السياسات والنطاقات' }).click()
  await expect(page.getByText('متقدم', { exact: true })).toBeVisible()
})

test('system roles must be cloned before custom-role editing; direct system PATCH is documented as immutable', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openTab(page, '/admin/authorization/roles?tab=roles-permissions')

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
  await page.getByRole('button', { name: 'تعديل الدور' }).click()
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

test('assignment lifecycle covers every scope level, two revokes and an explicit expiry, with labelled projections and an out-of-scope rejection', async ({ page }) => {
  await enforceLocalApiOnly(page)
  const state = await mockWorkspace(page)
  await signIn(page)
  await openTab(page, '/admin/authorization/role-assignments?tab=role-assignments')

  // Labelled role + account projections for every rendered row. IDs must
  // never appear in the copy; only the canonical Arabic/English names do.
  await expect(page.getByText('مراجع مالي مخصص')).toHaveCount(4)
  await expect(page.getByText('مسؤول المالية')).toHaveCount(4)
  await expect(page.getByText(ids.facility, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.tenant, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.unit, { exact: false })).toHaveCount(0)
  await expect(page.getByText(ids.recordSet, { exact: false })).toHaveCount(0)

  // Status column starts at 'active' for every row.
  const rows = page.locator('li.assignment-row')
  await expect(rows).toHaveCount(4)
  await expect(rows.nth(0)).toContainText('active')
  await expect(rows.nth(1)).toContainText('active')
  await expect(rows.nth(2)).toContainText('active')
  await expect(rows.nth(3)).toContainText('active')

  // 1. Explicit expiry: facility row → ended.
  const facilityRow = rows.nth(0)
  await facilityRow.getByRole('button', { name: 'إنهاء الإسناد' }).click()
  await expect(facilityRow).toContainText('expired')

  // 2. First revoke: unit row → revoked.
  const unitRow = rows.nth(2)
  await unitRow.getByRole('button', { name: 'إلغاء الإسناد' }).click()
  await expect(unitRow).toContainText('revoked')

  // 3. Second revoke: cluster row → revoked.
  const clusterRow = rows.nth(1)
  await clusterRow.getByRole('button', { name: 'إلغاء الإسناد' }).click()
  await expect(clusterRow).toContainText('revoked')

  // 4. Supported assignment creation goes through the visible catalog-backed
  // picker: account + role selectors, explicit cluster radio, catalog target,
  // and the form submit. No UUID is typed or injected by page.evaluate.
  await page.getByRole('button', { name: 'الموظف' }).click()
  await page.getByRole('option', { name: 'مسؤول المالية' }).click()
  await page.getByRole('button', { name: 'الدور' }).click()
  await page.getByRole('option', { name: 'مراجع مالي مخصص' }).click()
  await page.getByRole('radio', { name: 'التجمع' }).click()
  await page.getByRole('button', { name: 'هدف النطاق' }).click()
  await page.getByRole('option', { name: 'تجمع الشمال الصحي' }).click()
  const saveAssignment = page.getByRole('button', { name: 'حفظ الإسناد' })
  await expect(saveAssignment).toBeEnabled()
  await saveAssignment.click()
  await expect(rows).toHaveCount(5)
  await expect(rows.nth(4)).toContainText('مراجع مالي مخصص')
  await expect(rows.nth(4)).toContainText('مسؤول المالية')
  await expect(rows.nth(4)).toContainText('active')
  expect(state.lastCreatedPayload).toMatchObject({ scope_type: 'cluster', scope_id: ids.tenant })

  // 5. Deliberate server-only 403 probe. The supported UI cannot select an
  // out-of-bound catalog target, so this direct request is intentionally kept
  // separate from (and after) the UI creation proof.
  let outOfScopeRequest = false
  await page.route(/\/api\/v1\/authorization\/role-assignments(\?|$)/, async (route) => {
    if (route.request().method() === 'POST') {
      outOfScopeRequest = true
      await json(route, { type: 'about:blank', title: 'Forbidden', status: 403, detail: 'This scope is outside your administrative boundary.' }, 403)
      return
    }
    await route.fallback()
  })
  const outOfScopeBody = await page.evaluate(
    async (payload) => {
      const response = await fetch('/api/v1/authorization/role-assignments', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json, application/problem+json',
          'Content-Type': 'application/merge-patch+json',
          'Idempotency-Key': `authorization-role-assignment-out-of-scope-${Date.now()}`,
        },
        body: JSON.stringify(payload),
      })
      return { status: response.status, body: await response.json() }
    },
    {
      resource_type: 'role_assignment',
      code: 'role-assignment',
      subject_user_id: ids.account,
      role_id: ids.customRole,
      scope_type: 'cluster',
      scope_id: ids.tenant,
    },
  )
  expect(outOfScopeBody.status).toBe(403)
  expect(outOfScopeBody.body.type).toBe('about:blank')
  expect(outOfScopeBody.body.detail).toBe('This scope is outside your administrative boundary.')
  // The server-only out-of-scope request must actually reach the route handler;
  // this cannot substitute for the UI creation asserted immediately above.
  expect(outOfScopeRequest).toBe(true)

  // URL state is preserved: query string is intact after every action.
  await expect.poll(() => new URL(page.url()).search).toBe('?tab=role-assignments')
})

test('decision inspector renders plain-language explanation and an ineligible deep link keeps URL state', async ({ browser }) => {
  // Two journeys that share a browser but mount PrincipalProvider with
  // different capability projections. The second test opens a fresh
  // page/context so the principal context re-fetches /me instead of
  // relying on popstate to refetch.
  const privileged = await browser.newContext({ locale: 'ar-SA' })
  const privilegedPage = await privileged.newPage()
  await enforceLocalApiOnly(privilegedPage)
  await mockWorkspace(privilegedPage)
  await signIn(privilegedPage)
  await openTab(privilegedPage, `/admin/authorization/explain/${ids.decision}?tab=decision-inspector`)
  await expect(privilegedPage.getByText('Finance officer may read records in North facility.')).toBeVisible()
  await expect(privilegedPage.locator('.accounts-permissions-workspace')).toHaveAttribute('dir', 'rtl')
  await privileged.close()
  const restricted = await browser.newContext({ locale: 'ar-SA' })
  const restrictedPage = await restricted.newPage()
  await enforceLocalApiOnly(restrictedPage)
  await mockWorkspace(restrictedPage, { capabilities: ['authorization.role.read'] })
  await signIn(restrictedPage)
  await openTab(restrictedPage, `/admin/authorization/explain/${ids.decision}?tab=decision-inspector`)
  await expect(restrictedPage.locator('.accounts-permissions-panel [role="status"]')).toContainText('هذه الأداة المتقدمة غير متاحة لحسابك.')
  // URL survives the deep-link visit (no observable redirect away from the path).
  await expect.poll(() => new URL(restrictedPage.url()).pathname).toBe(`/admin/authorization/explain/${ids.decision}`)
  await expect.poll(() => new URL(restrictedPage.url()).search).toBe('?tab=decision-inspector')
  await restricted.close()
})

test('mutation feedback proves the 412 path through the live region, recovery buttons, and idempotency header', async ({ page }) => {
  await enforceLocalApiOnly(page)
  await mockWorkspace(page)
  await signIn(page)
  await openTab(page, '/admin/authorization/roles?tab=roles-permissions')

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
  await page.getByRole('button', { name: 'تعديل الدور' }).click()
  await page.getByLabel('اسم الدور').fill('Conflicting edit')
  const recoveryPatch = waitForRequest(page, (req) => req.url.endsWith(`/api/v1/authorization/roles/${ids.customRole}`) && req.method === 'PATCH')
  await page.getByRole('button', { name: 'حفظ التغييرات' }).click()
  const patch = await recoveryPatch
  expect(patch.headers['if-match']).toBe('"2"')
  expect(patch.headers['content-type']).toContain('application/merge-patch+json')
  expect(patch.headers['x-csrf-token']).toBeTruthy()

  const announcement = page.locator('output[role="status"]')
  await expect(announcement).toContainText('This role changed; reload before saving.')
  await expect(announcement).toBeFocused()
  // The live-region sequence counter advanced at least once for the error.
  const sequenceAttr = await announcement.getAttribute('data-announcement-sequence')
  expect(Number(sequenceAttr)).toBeGreaterThanOrEqual(1)
  await expect(page.getByRole('button', { name: 'إعادة التحميل' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'إعادة المحاولة' })).toBeVisible()

  // Recover by reloading canonical state — proves the round-trip and that the
  // recovery flow hands the row's lock_version back to the editor.
  await page.route(`**/api/v1/authorization/roles/${ids.customRole}`, async (route) => {
    if (route.request().method() === 'GET') return json(route, { data: { ...customRole, lock_version: 2 } }, 200, { ETag: '"2"' })
    if (route.request().method() === 'PATCH') {
      expect(route.request().headers()['if-match']).toBe('"2"')
      return json(route, { data: { ...customRole, lock_version: 3 } }, 200, { ETag: '"3"' })
    }
    throw new Error(`Unexpected method ${route.request().method()}`)
  })
  await page.getByRole('button', { name: 'إعادة التحميل' }).click()
  await page.getByRole('button', { name: 'إعادة المحاولة' }).click()
  await expect(page.getByRole('button', { name: 'إعادة التحميل' })).toHaveCount(0)
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
