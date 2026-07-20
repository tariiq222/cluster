import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test'

import { walkingSkeletonFixtures, walkingSkeletonLocales } from '../src/test/setup'

type Locale = 'ar' | 'en'

type WorkRecord = {
  id: string
  payload: {
    title?: string
    description?: string
  }
}

type WorkRecordCollection = {
  items: WorkRecord[]
  next_cursor: string | null
}

const copy = {
  ar: {
    lang: walkingSkeletonLocales.arabic.lang,
    dir: walkingSkeletonLocales.arabic.dir,
    username: 'اسم المستخدم',
    password: 'كلمة المرور',
    signIn: 'تسجيل الدخول',
    newRequest: 'طلب جديد',
    title: 'عنوان الطلب (مطلوب)',
    description: 'وصف الطلب (مطلوب)',
    submit: 'إرسال الطلب',
    success: 'تم إرسال طلبك',
    back: 'العودة إلى طلباتي',
    notifications: 'الإشعارات',
    refresh: 'تحديث الإشعارات',
    unavailable: 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
  },
  en: {
    lang: walkingSkeletonLocales.english.lang,
    dir: walkingSkeletonLocales.english.dir,
    username: 'Username',
    password: 'Password',
    signIn: 'Sign in',
    newRequest: 'New request',
    title: 'Request title (required)',
    description: 'Request description (required)',
    submit: 'Submit request',
    success: 'Your request was submitted',
    back: 'Back to my requests',
    notifications: 'Notifications',
    refresh: 'Refresh notifications',
    unavailable: 'You cannot open this request, or it is no longer available.',
  },
} as const

function correlationId(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  let timestamp = Date.now()
  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = timestamp & 0xff
    timestamp = Math.floor(timestamp / 256)
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function apiSession(request: APIRequestContext, username: string, password: string): Promise<void> {
  const response = await request.post('/api/v1/identity/login', {
    headers: { 'X-Correlation-ID': correlationId() },
    data: { username, password },
  })
  expect(response.status()).toBe(200)
}

async function apiRecords(request: APIRequestContext): Promise<WorkRecordCollection> {
  const response = await request.get('/api/v1/work-records?limit=100', {
    headers: {
      'X-Correlation-ID': correlationId(),
    },
  })
  expect(response.status()).toBe(200)
  return response.json() as Promise<WorkRecordCollection>
}

async function signIn(page: Page, locale: Locale, username: string, password: string): Promise<void> {
  const labels = copy[locale]
  await page.goto('/')
  if (locale === 'en') {
    await page.getByRole('button', { name: 'English' }).click()
  }
  await expect(page.locator('html')).toHaveAttribute('lang', labels.lang)
  await expect(page.locator('html')).toHaveAttribute('dir', labels.dir)
  await page.getByLabel(labels.username).fill(username)
  await page.getByLabel(labels.password, { exact: true }).fill(password)
  await page.getByRole('button', { name: labels.signIn }).click()
  await expect(page.getByRole('heading', { name: locale === 'ar' ? 'طلباتي' : 'My requests' })).toBeVisible()
}

async function submitRequest(page: Page, locale: Locale, title: string, description: string): Promise<void> {
  const labels = copy[locale]
  await page.getByRole('link', { name: labels.newRequest }).first().click()
  await page.getByLabel(labels.title).fill(title)
  await page.getByLabel(labels.description).fill(description)
  await page.getByRole('button', { name: labels.submit }).click()
  await expect(page.getByRole('heading', { name: labels.success })).toBeFocused()
  await page.getByRole('link', { name: labels.back }).click()
  await expect(page.getByRole('link', { name: title })).toBeVisible()
}

async function openRoute(page: Page, path: string): Promise<void> {
  await page.evaluate((nextPath) => {
    window.history.pushState({}, '', nextPath)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, path)
}

async function exerciseIsolatedJourney(browser: Browser, request: APIRequestContext, locale: Locale): Promise<void> {
  const labels = copy[locale]
  const suffix = `${locale}-${Date.now()}`
  const titleA = `${walkingSkeletonFixtures.accountA.title} ${suffix}`
  const descriptionA = `${walkingSkeletonFixtures.accountA.description} ${suffix}`
  const titleB = `طلب حساب ب ${suffix}`
  const descriptionB = `وصف لا يراه إلا حساب المنشأة ب. ${suffix}`
  const pageA = await browser.newPage()
  const pageB = await browser.newPage()

  await signIn(pageA, locale, walkingSkeletonFixtures.accountA.username, walkingSkeletonFixtures.accountA.password)
  await submitRequest(pageA, locale, titleA, descriptionA)
  await signIn(pageB, locale, walkingSkeletonFixtures.accountB.username, walkingSkeletonFixtures.accountB.password)
  await submitRequest(pageB, locale, titleB, descriptionB)

  // The request context carries the session cookie of the most recent
  // login, so each API read re-authenticates as the intended principal.
  await apiSession(request, walkingSkeletonFixtures.accountA.username, walkingSkeletonFixtures.accountA.password)
  const recordsA = await apiRecords(request)
  await apiSession(request, walkingSkeletonFixtures.accountB.username, walkingSkeletonFixtures.accountB.password)
  const recordsB = await apiRecords(request)
  const recordA = recordsA.items.find((record) => record.payload.title === titleA)
  const recordB = recordsB.items.find((record) => record.payload.title === titleB)
  expect(recordA).toBeTruthy()
  expect(recordB).toBeTruthy()
  expect(recordsA.items.some((record) => record.id === recordB?.id)).toBe(false)
  expect(recordsB.items.some((record) => record.id === recordA?.id)).toBe(false)
  await expect(pageA.getByText(titleB)).toHaveCount(0)
  await expect(pageB.getByText(titleA)).toHaveCount(0)

  await pageA.getByRole('link', { name: titleA }).click()
  await expect(pageA.getByRole('heading', { name: titleA })).toBeVisible()
  await expect(pageA.getByText(descriptionA)).toBeVisible()
  await pageB.getByRole('link', { name: titleB }).click()
  await expect(pageB.getByRole('heading', { name: titleB })).toBeVisible()
  await expect(pageB.getByText(descriptionB)).toBeVisible()

  await pageA.getByRole('button', { name: locale === 'ar' ? 'English' : 'العربية' }).click()
  await expect(pageA.locator('html')).toHaveAttribute('lang', locale === 'ar' ? 'en' : 'ar')
  await expect(pageA.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'ltr' : 'rtl')
  await expect(pageA.getByRole('heading', { name: titleA })).toBeVisible()
  await pageA.getByRole('button', { name: locale === 'ar' ? 'العربية' : 'English' }).click()

  const sharedCorrelation = correlationId()
  const crossHeaders = () => ({
    'X-Correlation-ID': sharedCorrelation,
  })
  await apiSession(request, walkingSkeletonFixtures.accountA.username, walkingSkeletonFixtures.accountA.password)
  const aReadsB = await request.get(`/api/v1/work-records/${recordB!.id}`, { headers: crossHeaders() })
  await apiSession(request, walkingSkeletonFixtures.accountB.username, walkingSkeletonFixtures.accountB.password)
  const bReadsA = await request.get(`/api/v1/work-records/${recordA!.id}`, { headers: crossHeaders() })
  expect(aReadsB.status()).toBe(404)
  expect(aReadsB.status()).toBe(bReadsA.status())
  const aReadsBBody = await aReadsB.body()
  expect(aReadsBBody).toEqual(await bReadsA.body())
  const unavailableBody = await aReadsB.text()
  expect(JSON.parse(unavailableBody)).toEqual({
    type: 'https://cluster.example/problems/work-record-unavailable',
    title: 'Not Found',
    status: 404,
    detail: 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
  })
  for (const forbidden of [titleA, descriptionA, titleB, descriptionB, 'facility', 'owner', 'trace', 'authorization']) {
    expect(unavailableBody).not.toContain(forbidden)
  }

  await openRoute(pageA, `/work-records/${recordB!.id}`)
  await expect(pageA.getByText(labels.unavailable)).toBeVisible()
  await expect(pageA.getByText(titleB)).toHaveCount(0)
  await expect(pageA.getByText(descriptionB)).toHaveCount(0)

  await pageA.getByRole('button', { name: labels.notifications }).click()
  for (let attempt = 0; attempt < 20 && await pageA.locator('.notification-list li').count() === 0; attempt += 1) {
    const notificationResponse = pageA.waitForResponse((response) => {
      const url = new URL(response.url())
      return url.pathname === '/api/v1/notifications'
    })
    await pageA.getByRole('button', { name: labels.refresh }).click()
    expect((await notificationResponse).status()).toBe(200)
    if (await pageA.locator('.notification-list li').count() === 0) await pageA.waitForTimeout(250)
  }
  await expect(pageA.locator('.notification-list li').first()).toBeVisible()

  await pageA.close()
  await pageB.close()
}

test('Arabic RTL journey keeps facility A and B records symmetrically isolated', async ({ browser, request }) => {
  await exerciseIsolatedJourney(browser, request, 'ar')
})

test('English LTR journey keeps facility A and B records symmetrically isolated', async ({ browser, request }) => {
  await exerciseIsolatedJourney(browser, request, 'en')
})
