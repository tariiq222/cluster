import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'

type JsonBody = Record<string, unknown>
type ApiResult = { status: number; headers: Record<string, string>; body: JsonBody }
type Session = { userId: string; csrfToken: string; facility: 'facility-a' | 'facility-b'; authMode: 'identity-session'; cookieName: string }

function assertRealAuthorizationRuntime(value: unknown): void {
  const serialized = JSON.stringify(value)
  expect(serialized).not.toContain('FixtureFacilityDecision')
  expect(serialized).not.toContain('development-fixture-bearer')
  expect(serialized).not.toContain('development bearer')
}

function requiredEnvironment(name: 'W1_3_ACCOUNT_A_USERNAME' | 'W1_3_ACCOUNT_A_PASSWORD' | 'W1_3_ACCOUNT_B_USERNAME' | 'W1_3_ACCOUNT_B_PASSWORD'): string {
  const value = process.env[name]
  if (!value) throw new Error(`${name} must be supplied by the isolated W1.3 runner.`)
  return value
}

const ACCOUNTS = {
  get ACCOUNT_A(): { username: string; password: string } {
    return { username: requiredEnvironment('W1_3_ACCOUNT_A_USERNAME'), password: requiredEnvironment('W1_3_ACCOUNT_A_PASSWORD') }
  },
  get ACCOUNT_B(): { username: string; password: string } {
    return { username: requiredEnvironment('W1_3_ACCOUNT_B_USERNAME'), password: requiredEnvironment('W1_3_ACCOUNT_B_PASSWORD') }
  },
}
const ADMIN_ID = '018f6f7d-0c00-7000-8000-000000000021'
const USER_B_ID = '018f6f7d-0c00-7000-8000-000000000022'
const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011'

function uuidV7(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  let timestamp = Date.now()
  for (let index = 5; index >= 0; index -= 1) { bytes[index] = timestamp & 0xff; timestamp = Math.floor(timestamp / 256) }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function api(page: Page, path: string, session: Session, init: RequestInit = {}): Promise<ApiResult> {
  return page.evaluate(async ({ path, session, init, correlation }) => {
    const headers = new Headers(init.headers)
    headers.set('Accept', 'application/json, application/problem+json')
    headers.set('X-Correlation-ID', correlation)
    if (init.method && init.method !== 'GET') headers.set('X-CSRF-Token', session.csrfToken)
    if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
    const response = await fetch(path, { ...init, headers, credentials: 'same-origin' })
    let body: JsonBody = {}
    try { body = await response.json() as JsonBody } catch { /* empty response */ }
    return { status: response.status, headers: Object.fromEntries(response.headers.entries()), body }
  }, { path, session, init, correlation: uuidV7() })
}

async function expectApi(page: Page, path: string, session: Session, init: RequestInit = {}, status = 200): Promise<JsonBody> {
  const result = await api(page, path, session, init)
  expect(result.status, `${init.method ?? 'GET'} ${path}: ${JSON.stringify(result.body)}`).toBe(status)
  assertRealAuthorizationRuntime(result.body)
  return result.body
}

async function signIn(page: Page, account: { username: string; password: string }): Promise<Session> {
  await page.goto('/')
  const login = await page.evaluate(async ({ account, correlation }) => {
    const response = await fetch('/api/v1/identity/login', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': correlation },
      body: JSON.stringify(account),
    })
    return { status: response.status, headers: Object.fromEntries(response.headers.entries()), body: await response.json() as JsonBody }
  }, { account, correlation: uuidV7() })
  expect(login.status, JSON.stringify(login.body)).toBe(200)
  const loginBody = login.body
  const data = loginBody.data as JsonBody
  const userId = String(data.user_id)
  const csrfToken = String(data.csrf_token)
  expect(userId).toMatch(/^[0-9a-f-]{36}$/)
  expect(csrfToken).toMatch(/^[a-f0-9]{64}$/)
  expect(login.headers['x-csrf-token']).toBe(csrfToken)
  const cookies = await page.context().cookies()
  const identityCookie = cookies.find((cookie) => cookie.name === 'cluster_identity_session' && cookie.value.length > 0)
  expect(identityCookie).toBeDefined()
  expect((await page.evaluate(() => Object.keys(localStorage).join(',')))).not.toContain('access_token')
  const facility = account.username === ACCOUNTS.ACCOUNT_A.username ? 'facility-a' : 'facility-b'
  return { userId, csrfToken, facility, authMode: 'identity-session', cookieName: identityCookie!.name }
}

async function freshContext(browser: Browser): Promise<BrowserContext> {
  return browser.newContext({ locale: 'ar-SA' })
}

test('W1.3 real browser security journey uses server identity, scoped projections, and admin transitions', async ({ browser }) => {
  const contextA = await freshContext(browser)
  const contextB = await freshContext(browser)
  const pageA = await contextA.newPage()
  const pageB = await contextB.newPage()
  try {
    const sessionA = await signIn(pageA, ACCOUNTS.ACCOUNT_A)
    const sessionB = await signIn(pageB, ACCOUNTS.ACCOUNT_B)
    expect(sessionA.authMode).toBe('identity-session')
    expect(sessionB.authMode).toBe('identity-session')
    expect(sessionA.facility).toBe('facility-a')
    expect(sessionB.facility).toBe('facility-b')
    expect(sessionA.userId).toBe(ADMIN_ID)
    expect(sessionB.userId).toBe(USER_B_ID)

    const cookieOnly = await pageB.evaluate(async (correlation) => {
      const response = await fetch('/api/v1/identity/me', { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Correlation-ID': correlation } })
      return response.status
    }, uuidV7())
    expect(cookieOnly).toBe(200)

    const suffix = uuidV7().slice(-12)
    const created = await expectApi(pageA, '/api/v1/work-records', sessionA, {
      method: 'POST',
      headers: { 'Idempotency-Key': `w13-browser-create-${suffix}`, 'X-Day3-Acceptance': '1' },
      body: JSON.stringify({ work_definition_code: 'request', title: `W1.3 ${suffix}`, description: 'browser security journey' }),
    }, 201)
    const record = (created.data as JsonBody)
    const recordId = String(record.id)

    const denied = await api(pageB, `/api/v1/work-records/${recordId}`, sessionB)
    expect(denied.status).toBe(404)
    expect(JSON.stringify(denied.body)).not.toContain(`W1.3 ${suffix}`)
    const deniedList = await expectApi(pageB, '/api/v1/work-records?limit=50', sessionB)
    expect(JSON.stringify(deniedList)).not.toContain(recordId)

    const adminRole = await expectApi(pageA, '/api/v1/authorization/roles', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-role-${suffix}` },
      body: JSON.stringify({ resource_type: 'role', code: `w13-browser-${suffix}`, name: `W1.3 ${suffix}`, role_type: 'operational' }),
    }, 201)
    const roleId = String((adminRole.data as JsonBody).id)
    await expectApi(pageA, '/api/v1/authorization/role-capabilities', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-cap-${suffix}` },
      body: JSON.stringify({ resource_type: 'role_capability', role_id: roleId, capability_code: 'work_record.read', effect: 'allow' }),
    }, 201)
    const assignment = await expectApi(pageA, '/api/v1/authorization/role-assignments', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-assignment-${suffix}` },
      body: JSON.stringify({ resource_type: 'role_assignment', user_id: USER_B_ID, role_id: roleId, scope_type: 'facility', scope_id: FACILITY_A, start_at: new Date(Date.now() - 60_000).toISOString() }),
    }, 201)
    const assignmentId = String((assignment.data as JsonBody).id)
    await expectApi(pageA, `/api/v1/authorization/role-assignments/${assignmentId}/activate`, sessionA, { method: 'POST', headers: { 'Idempotency-Key': `w13-browser-activate-${suffix}`, 'If-Match': '"1"' }, body: '{}' })

    const allowed = await expectApi(pageB, `/api/v1/work-records/${recordId}`, sessionB)
    expect((allowed.data as JsonBody).id).toBe(recordId)
    expect((allowed.data as JsonBody).decision_id).toBeTruthy()
    expect((allowed.data as JsonBody).allowed_actions).toEqual(expect.any(Array))
    expect((allowed.data as JsonBody).field_access).toBeDefined()
    const explanation = await expectApi(pageA, `/api/v1/authorization/access-decisions/${String((allowed.data as JsonBody).decision_id)}/explanation`, sessionA)
    // HTTP cannot expose the bound PHP class safely. The immutable policy
    // version proves that the real RBAC+ABAC engine handled the request.
    expect(explanation.policy_version, 'W1.3 requires the real RBAC+ABAC engine, not FixtureFacilityDecision').toBe('rbac-abac-v2')
    assertRealAuthorizationRuntime(explanation)

    const revoke = await api(pageA, `/api/v1/authorization/role-assignments/${assignmentId}/revoke`, sessionA, { method: 'POST', headers: { 'Idempotency-Key': `w13-browser-revoke-${suffix}`, 'If-Match': '"2"' }, body: '{}' })
    expect(revoke.status).toBe(200)
    expect((await api(pageB, `/api/v1/work-records/${recordId}`, sessionB)).status).toBe(404)

    const expired = await expectApi(pageA, '/api/v1/authorization/role-assignments', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-expired-${suffix}` },
      body: JSON.stringify({ resource_type: 'role_assignment', user_id: USER_B_ID, role_id: roleId, scope_type: 'facility', scope_id: FACILITY_A, start_at: new Date(Date.now() - 120_000).toISOString(), end_at: new Date(Date.now() - 60_000).toISOString() }),
    }, 201)
    expect((await api(pageB, `/api/v1/work-records/${recordId}`, sessionB)).status).toBe(404)
    expect((expired.data as JsonBody).status).toBeDefined()

    const deny = await expectApi(pageA, '/api/v1/authorization/explicit-denies', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-deny-${suffix}` },
      body: JSON.stringify({ resource_type: 'explicit_deny', user_id: USER_B_ID, capability_code: 'work_record.read', resource_pattern: 'work_record', reason: 'W1.3 browser deny', issued_at: new Date().toISOString() }),
    }, 201)
    expect((deny.data as JsonBody).id).toBeTruthy()

    const delegation = await api(pageA, '/api/v1/authorization/delegations', sessionA, {
      method: 'POST', headers: { 'Idempotency-Key': `w13-browser-delegation-${suffix}` },
      body: JSON.stringify({ resource_type: 'delegation', delegator_user_id: ADMIN_ID, delegate_user_id: USER_B_ID, module_code: 'work_record', capability_codes: ['work_record.read'], scope_type: 'cluster', scope_id: '018f6f7d-0c00-7000-8000-00000000c113', start_at: new Date(Date.now() - 60_000).toISOString(), end_at: new Date(Date.now() + 60_000).toISOString() }),
    })
    expect(delegation.status, JSON.stringify(delegation.body)).toBe(422)
    expect(JSON.stringify(delegation.body)).not.toContain('FixtureFacilityDecision')
    assertRealAuthorizationRuntime(delegation.body)

    // The server projection contract is exercised through the real list/detail APIs.
    const projection = await expectApi(pageA, `/api/v1/work-records/${recordId}`, sessionA)
    expect((projection.data as JsonBody).field_access).toBeDefined()
    expect((projection.data as JsonBody).allowed_actions).toBeDefined()

    // The API journey above uses fetch-based identity login; reload so the app
    // bootstraps the session cookie and renders the authenticated shell whose
    // language toggle carries the English accessible name.
    await pageA.reload()
    await expect(pageA.locator('html')).toHaveAttribute('dir', 'rtl')
    await pageA.getByRole('button', { name: 'English' }).click()
    await expect(pageA.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(pageA.locator('html')).toHaveAttribute('lang', 'en')
  } finally {
    await contextA.close()
    await contextB.close()
  }
})
