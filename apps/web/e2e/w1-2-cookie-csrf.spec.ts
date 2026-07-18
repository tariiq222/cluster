import { expect, test, type Page } from '@playwright/test'

type JsonResponse<T> = { status: number; headers: Record<string, string>; body: T }
type IdentityLogin = { data: { csrf_token: string } }
type UploadTicket = { data: { upload_id: string; quarantine_object_id: string; upload_url: string; method: string; required_headers: Record<string, string> } }
type UploadStatus = { data: { scan_status: string; availability_status: string } }
type ImportJob = { data: { id: string; status: string } }
type TemporaryAssignment = { data: { id: string; status: string } }

const requiredEnvironment = [
  'W1_2_IDENTITY_USERNAME', 'W1_2_IDENTITY_PASSWORD', 'W1_2_IMPORT_USERNAME', 'W1_2_IMPORT_PASSWORD',
  'W1_2_IMPORT_POSITION_ID', 'W1_2_TEMPORARY_ASSIGNMENT_PERSON_ID', 'W1_2_TEMPORARY_ASSIGNMENT_UNIT_ID', 'W1_2_TEMPORARY_ASSIGNMENT_CAPABILITY',
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
  await page.route('**/api/v1/documents/uploads', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await route.continue({ headers })
  })
  await page.route('**/api/v1/documents/uploads/*/complete', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await route.continue({ headers })
  })
  await page.route('**/api/v1/organization/temporary-assignments', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await route.continue({ headers })
  })
  await page.route('**/api/v1/organization/temporary-assignments/*/revoke', async (route) => {
    const headers = { ...route.request().headers(), 'x-csrf-token': csrfToken }
    await route.continue({ headers })
  })
}

async function signInWeb(page: Page): Promise<void> {
  await page.getByLabel('اسم المستخدم').fill(required('W1_2_IMPORT_USERNAME'))
  await page.getByLabel('كلمة المرور').fill(required('W1_2_IMPORT_PASSWORD'))
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
}

function dateTimeLocal(value: Date): string {
  const offset = value.getTimezoneOffset() * 60_000
  return new Date(value.valueOf() - offset).toISOString().slice(0, 16)
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
  expect(initiated.status).toBe(201)
  expect(initiated.body.data.method).toBe('PUT')
  const uploadStatus = await page.evaluate(async ({ ticket, csv }) => (await fetch(ticket.upload_url, { method: ticket.method, headers: ticket.required_headers, body: new Blob([csv], { type: 'text/csv' }) })).status, { ticket: initiated.body.data, csv })
  expect(uploadStatus).toBe(200)
  const completed = await browserJson<{ data: { accepted: boolean } }>(page, `/api/v1/documents/uploads/${initiated.body.data.upload_id}/complete`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': `e2e-api-complete-${correlationId()}`, 'X-Correlation-ID': correlationId(), 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ byte_size: byteSize, sha256: await sha256(page, csv) }),
  })
  expect(completed.status).toBe(202)
  expect(completed.body.data.accepted).toBe(true)
  await expect.poll(async () => (await browserJson<UploadStatus>(page, `/api/v1/documents/uploads/${initiated.body.data.upload_id}`, { headers: { 'X-Correlation-ID': correlationId() } })).body.data.scan_status, { timeout: 30_000 }).toBe('clean')
})

test('W1.2 web UI uploads and submits a CSV import, then creates and revokes a temporary assignment', async ({ page }) => {
  const csrfToken = await loginIdentitySession(page)
  await configureUiCsrfBridge(page, csrfToken)
  await signInWeb(page)
  const suffix = correlationId()
  const csv = `employee_number,display_name_ar,status,position_id,start_at\nE2E-${suffix},موظف رحلة المتصفح,active,${required('W1_2_IMPORT_POSITION_ID')},2027-01-01T08:00:00Z\n`

  await page.getByRole('link', { name: 'التنظيم' }).click()
  await page.getByRole('link', { name: 'مراجعة الاستيراد' }).click()
  await expect(page.getByRole('heading', { name: 'مراجعة الاستيراد' })).toBeVisible()
  const initiated = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/documents/uploads' && response.request().method() === 'POST')
  const completed = page.waitForResponse((response) => /\/api\/v1\/documents\/uploads\/[^/]+\/complete$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST')
  await page.getByLabel('ملف CSV').setInputFiles({ name: `w1-2-ui-${suffix}.csv`, mimeType: 'text/csv', buffer: Buffer.from(csv) })
  await page.getByRole('button', { name: 'رفع الملف' }).click()
  const uploadTicket = await initiated
  expect(uploadTicket.status()).toBe(201)
  const upload = await uploadTicket.json() as UploadTicket
  expect(upload.data.method).toBe('PUT')
  expect((await completed).status()).toBe(202)
  await expect(page.getByText('اكتمل رفع الملف. راجع مرجع الحجر ثم أنشئ مهمة الاستيراد.')).toBeVisible()

  const quarantineId = upload.data.quarantine_object_id
  await expect.poll(async () => (await browserJson<UploadStatus>(page, `/api/v1/documents/uploads/${upload.data.upload_id}`, { headers: { 'X-Correlation-ID': correlationId() } })).body.data.scan_status, { timeout: 30_000 }).toBe('clean')
  await expect(page.getByLabel('معرف quarantine')).toHaveValue(quarantineId)
  const submitted = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/organization/import-jobs' && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'إنشاء ImportJob' }).click()
  expect((await submitted).status()).toBe(202)
  await expect(page.getByText('مستلم', { exact: true })).toBeVisible()

  const validate = page.waitForResponse((response) => /\/api\/v1\/organization\/import-jobs\/[^/]+\/validate$/.test(new URL(response.url()).pathname))
  await page.getByLabel('انتقال الحالة').selectOption('validate')
  await page.getByRole('button', { name: 'تنفيذ' }).click()
  expect((await validate).status()).toBe(200)
  await expect(page.getByText('تم التحقق', { exact: true })).toBeVisible()
  const reject = page.waitForResponse((response) => /\/api\/v1\/organization\/import-jobs\/[^/]+\/reject$/.test(new URL(response.url()).pathname))
  await page.getByLabel('انتقال الحالة').selectOption('reject')
  await page.getByLabel('سبب الرفض أو الإلغاء').fill(`W1.2 E2E cleanup ${suffix}`)
  await page.getByRole('button', { name: 'تنفيذ' }).click()
  expect((await reject).status()).toBe(200)
  await expect(page.getByText('مرفوض', { exact: true })).toBeVisible()

  await page.getByRole('link', { name: 'التكليفات المؤقتة' }).click()
  await expect(page.getByRole('heading', { name: 'التكليفات المؤقتة' })).toBeVisible()
  await page.locator('#temporary-unit').selectOption(required('W1_2_TEMPORARY_ASSIGNMENT_UNIT_ID'))
  const start = new Date(Date.now() + (7 + Number.parseInt(suffix.slice(-4), 16) % 30) * 24 * 60 * 60 * 1000)
  await page.getByLabel('معرف الشخص').fill(required('W1_2_TEMPORARY_ASSIGNMENT_PERSON_ID'))
  await page.getByLabel('رموز الصلاحيات').fill(required('W1_2_TEMPORARY_ASSIGNMENT_CAPABILITY'))
  await page.getByLabel('سبب التكليف').fill(`W1.2 UI ${suffix}`)
  await page.getByLabel('تاريخ ووقت البداية').fill(dateTimeLocal(start))
  await page.getByLabel('تاريخ ووقت النهاية').fill(dateTimeLocal(new Date(start.valueOf() + 60 * 60 * 1000)))
  const assignmentCreated = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/organization/temporary-assignments' && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'إضافة تكليف مؤقت' }).click()
  expect((await assignmentCreated).status()).toBe(201)
  const assignment = await assignmentCreated
  const assignmentId = (await assignment.json() as TemporaryAssignment).data.id
  const assignmentRow = page.getByRole('row').filter({ hasText: required('W1_2_TEMPORARY_ASSIGNMENT_PERSON_ID') }).filter({ hasText: required('W1_2_TEMPORARY_ASSIGNMENT_CAPABILITY') })
  await expect(assignmentRow).toContainText('مجدول')
  await assignmentRow.getByLabel('سبب الإلغاء').fill(`W1.2 UI cleanup ${suffix}`)
  const assignmentRevoked = page.waitForResponse((response) => new URL(response.url()).pathname === `/api/v1/organization/temporary-assignments/${assignmentId}/revoke` && response.request().method() === 'POST')
  await assignmentRow.getByRole('button', { name: 'إلغاء التكليف' }).click()
  expect((await assignmentRevoked).status()).toBe(200)
  await expect(assignmentRow).toContainText('ملغى')
})
