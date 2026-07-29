import { expect, test, type Page, type Route } from '@playwright/test'

type JsonResponse<T> = { status: number; headers: Record<string, string>; body: T }
type IdentityLogin = { data: { csrf_token: string } }
type UploadTicket = { upload_id: string; quarantine_object_id: string; upload_url: string; method: string; required_headers: Record<string, string> }
type UploadCompletion = { accepted: boolean }
type UploadStatus = { scan_status: string; availability_status: string }

const requiredEnvironment = [
  'W1_2_IDENTITY_USERNAME', 'W1_2_IDENTITY_PASSWORD', 'W1_2_IMPORT_USERNAME', 'W1_2_IMPORT_PASSWORD',
  'W1_2_IMPORT_POSITION_ID',
] as const

function required(name: typeof requiredEnvironment[number]): string {
  const value = process.env[name]
  if (!value) throw new Error(`${name} must identify an isolated W1.2 E2E seed credential or resource.`)
  return value
}

function correlationId(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  let timestamp = Date.now()
  for (let index = 5; index >= 0; index -= 1) { bytes[index] = timestamp & 0xff; timestamp = Math.floor(timestamp / 256) }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function browserJson<T>(page: Page, path: string, init: RequestInit): Promise<JsonResponse<T>> {
  return page.evaluate(async ({ path, init }) => {
    const response = await fetch(path, { ...init, credentials: 'same-origin' })
    return { status: response.status, headers: Object.fromEntries(response.headers.entries()), body: await response.json() }
  }, { path, init }) as Promise<JsonResponse<T>>
}

async function sha256(page: Page, value: string): Promise<string> {
  return page.evaluate(async (value) => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))), (byte) => byte.toString(16).padStart(2, '0')).join(''), value)
}

async function loginIdentitySession(page: Page): Promise<string> {
  const login = await browserJson<IdentityLogin>(page, '/api/v1/identity/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Correlation-ID': correlationId() },
    body: JSON.stringify({ username: required('W1_2_IDENTITY_USERNAME'), password: required('W1_2_IDENTITY_PASSWORD') }),
  })
  expect(login.status).toBe(200)
  expect(login.headers['x-csrf-token']).toBe(login.body.data.csrf_token)
  await expect.poll(async () => (await page.context().cookies()).find((item) => item.name === (process.env.W1_2_IDENTITY_COOKIE ?? 'cluster_identity_session'))).toMatchObject({
    secure: process.env.W1_2_SESSION_SECURE_COOKIE === 'true', httpOnly: true, sameSite: 'Lax',
  })
  return login.body.data.csrf_token
}

async function configureUiCsrfBridge(page: Page, csrfToken: string): Promise<void> {
  await page.route('**/api/v1/documents/uploads/*', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue()
      return
    }
    await fulfillDocumentCompatibilityResponse(route, route.request().headers())
  })
  await page.route('**/api/v1/documents/uploads', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await fulfillDocumentCompatibilityResponse(route, headers)
  })
  await page.route('**/api/v1/documents/uploads/*/complete', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await fulfillDocumentCompatibilityResponse(route, headers)
  })
}

async function fulfillDocumentCompatibilityResponse(route: Route, headers: Record<string, string>): Promise<void> {
  const response = await route.fetch({ headers })
  if (!response.ok()) {
    await route.fulfill({ response })
    return
  }

  const body = await response.json() as unknown
  const isEnvelope = body !== null && typeof body === 'object' && 'data' in body
  await route.fulfill({ response, json: isEnvelope ? body : { data: body } })
}

async function signInWeb(page: Page): Promise<void> {
  await page.getByLabel('اسم المستخدم').fill(required('W1_2_IMPORT_USERNAME'))
  await page.getByLabel('كلمة المرور', { exact: true }).fill(required('W1_2_IMPORT_PASSWORD'))
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

test.beforeEach(async ({ page }) => {
  for (const name of requiredEnvironment) required(name)
  await page.goto('/')
})

test('W1.2 browser cookie session and CSRF request a signed CSV upload and observe its clean scan', async ({ page }) => {
  const csv = `employee_number,display_name_ar,status,position_id,start_at\nE2E-${correlationId()},موظف اختبار,active,${required('W1_2_IMPORT_POSITION_ID')},2027-01-01T08:00:00Z\n`
  const byteSize = new TextEncoder().encode(csv).byteLength
  const csrfToken = await loginIdentitySession(page)
  const initiated = await browserJson<UploadTicket>(page, '/api/v1/documents/uploads', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': `e2e-api-upload-${correlationId()}`, 'X-Correlation-ID': correlationId(), 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ purpose: 'organization_import_source', name: 'W1.2 API browser CSV import', description: null, classification: 'confidential', file_name: `w1-2-api-${correlationId()}.csv`, content_type: 'text/csv', byte_size: byteSize, sha256: await sha256(page, csv) }),
  })
  expect(initiated.status, JSON.stringify(initiated.body)).toBe(201)
  expect(initiated.body.method).toBe('PUT')
  const uploadResult = await page.evaluate(async ({ ticket, csv }) => {
    const response = await fetch(ticket.upload_url, { method: ticket.method, headers: ticket.required_headers, body: new Blob([csv], { type: 'text/csv' }) })
    return { status: response.status, body: await response.text(), ticketHeaderNames: Object.keys(ticket.required_headers).sort() }
  }, { ticket: initiated.body, csv })
  expect(uploadResult.status, JSON.stringify(uploadResult)).toBe(200)
  const completed = await browserJson<UploadCompletion>(page, `/api/v1/documents/uploads/${initiated.body.upload_id}/complete`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': `e2e-api-complete-${correlationId()}`, 'X-Correlation-ID': correlationId(), 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ byte_size: byteSize, sha256: await sha256(page, csv) }),
  })
  expect(completed.status, JSON.stringify(completed.body)).toBe(202)
  expect(completed.body.accepted).toBe(true)
  await expect.poll(async () => {
    const status = await browserJson<UploadStatus>(page, `/api/v1/documents/uploads/${initiated.body.upload_id}`, { headers: { 'X-Correlation-ID': correlationId() } })
    expect(status.status, JSON.stringify(status.body)).toBe(200)
    return status.body.scan_status
  }, { timeout: 30_000 }).toBe('clean')
})

test('W1.2 web UI uploads and submits a CSV import', async ({ page }) => {
  const csrfToken = await loginIdentitySession(page)
  await configureUiCsrfBridge(page, csrfToken)
  await signInWeb(page)
  const suffix = correlationId()
  const csv = `employee_number,display_name_ar,status,position_id,start_at\nE2E-${suffix},موظف رحلة المتصفح,active,${required('W1_2_IMPORT_POSITION_ID')},2027-01-01T08:00:00Z\n`

  // Navigate directly through the primary link into the organization workspace,
  // then use the local employee section action to open the import review screen.
  await page.getByRole('link', { name: 'المنشآت والموظفون', exact: true }).click()
  await page.getByRole('link', { name: 'الموظفون والتكليفات الوظيفية', exact: true }).click()
  await page.getByRole('button', { name: 'استيراد موظفين' }).click()
  await expect(page.getByRole('heading', { name: 'إضافة بيانات من ملف' })).toBeVisible()
  const initiated = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/documents/uploads' && response.request().method() === 'POST')
  const completed = page.waitForResponse((response) => /\/api\/v1\/documents\/uploads\/[^/]+\/complete$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST')
  await page.getByLabel('ملف البيانات', { exact: true }).setInputFiles({ name: `w1-2-ui-${suffix}.csv`, mimeType: 'text/csv', buffer: Buffer.from(csv) })
  await page.getByRole('button', { name: 'رفع الملف' }).click()
  const uploadTicket = await initiated
  expect(uploadTicket.status()).toBe(201)
  const upload = await uploadTicket.json() as { data: UploadTicket }
  expect(upload.data.method).toBe('PUT')
  expect((await completed).status()).toBe(202)
  await expect(page.getByText('اكتمل رفع الملف. يمكنك الآن بدء مراجعته.')).toBeVisible()

  const quarantineId = upload.data.quarantine_object_id
  await expect.poll(async () => {
    const status = await browserJson<UploadStatus>(page, `/api/v1/documents/uploads/${upload.data.upload_id}`, { headers: { 'X-Correlation-ID': correlationId() } })
    expect(status.status, JSON.stringify(status.body)).toBe(200)
    return status.body.scan_status
  }, { timeout: 30_000 }).toBe('clean')
  await expect(page.getByLabel('مرجع الملف', { exact: true })).toHaveValue(quarantineId)
  const submitted = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/organization/import-jobs' && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'بدء مراجعة الملف' }).click()
  expect((await submitted).status()).toBe(202)
  await expect(page.getByText('تم استلام الملف', { exact: true })).toBeVisible()
})
