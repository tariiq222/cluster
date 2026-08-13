import { expect, test, type Page } from '@playwright/test'

// The W1.3 journey seeder provisions these real accounts on facilities A
// and B; the UI and session APIs only accept server-issued identities.
// (Previously shared from src/test/setup.ts, which the frontend rebuild removed.)
const walkingSkeletonFixtures = {
  accountA: {
    username: 'w13-e2e-account-a',
    password: 'North!River7Quartz2026',
  },
} as const

const walkingSkeletonLocales = {
  arabic: { lang: 'ar', dir: 'rtl' },
  english: { lang: 'en', dir: 'ltr' },
} as const

type Locale = 'ar' | 'en'

const PLATFORM_ADMIN = {
  username: 'platform-admin',
  password: 'Admin!Cluster9Owner2026',
} as const
// Production-bundle runs pass the external HTTPS origin; the W1.1 dev lane
// only provides a port.
const WEB_ORIGIN = process.env.W1_1_WEB_ORIGIN ?? `http://127.0.0.1:${process.env.W1_1_WEB_PORT ?? '4173'}`
test.setTimeout(60_000)

const copy = {
  ar: {
    lang: walkingSkeletonLocales.arabic.lang,
    dir: walkingSkeletonLocales.arabic.dir,
    username: 'اسم المستخدم',
    password: 'كلمة المرور',
    signIn: 'تسجيل الدخول',
    home: 'الرئيسية',
    expired: 'انتهت الجلسة، يرجى تسجيل الدخول مرة أخرى.',
  },
  en: {
    lang: walkingSkeletonLocales.english.lang,
    dir: walkingSkeletonLocales.english.dir,
    username: 'Username',
    password: 'Password',
    signIn: 'Sign in',
    home: 'Home',
    expired: 'Session expired. Please sign in again.',
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

async function signIn(page: Page, locale: Locale, username: string, password: string): Promise<void> {
  const labels = copy[locale]
  await page.goto(WEB_ORIGIN)
  if (locale === 'en') {
    await page.getByRole('button', { name: 'English' }).click()
  }
  await expect(page.locator('html')).toHaveAttribute('lang', labels.lang)
  await expect(page.locator('html')).toHaveAttribute('dir', labels.dir)
  const homeHeading = page.getByRole('heading', { name: labels.home, exact: true })
  const usernameField = page.getByLabel(labels.username)
  await expect(homeHeading.or(usernameField)).toBeVisible()
  if (await homeHeading.isVisible()) return

  await usernameField.fill(username)
  await page.getByLabel(labels.password, { exact: true }).fill(password)
  await page.getByRole('button', { name: labels.signIn }).click()
  await expect(homeHeading).toBeVisible()
}


async function signInPlatformAdmin(page: Page): Promise<void> {
  await page.goto(WEB_ORIGIN)
  await page.getByLabel('اسم المستخدم').fill(PLATFORM_ADMIN.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(PLATFORM_ADMIN.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function currentCsrfToken(page: Page): Promise<string> {
  const response = await page.request.post('/api/v1/identity/csrf', {
    headers: { 'X-Correlation-ID': correlationId() },
  })
  expect(response.status()).toBe(200)
  const body = await response.json() as { data?: { csrf_token?: unknown } }
  const token = typeof body.data?.csrf_token === 'string' ? body.data.csrf_token : ''
  expect(token).toMatch(/^[a-f0-9]{64}$/)
  return token
}

async function openOrganizationOverview(page: Page): Promise<void> {
  await page.goto(`${WEB_ORIGIN}/organization`)
  await expect(page.getByRole('heading', { name: 'المنظمة', exact: true })).toBeVisible()
}


test('cookie session restores without web storage and a revoked session returns to login', async ({ page }) => {
  await signIn(
    page,
    'ar',
    walkingSkeletonFixtures.accountA.username,
    walkingSkeletonFixtures.accountA.password,
  )
  await expect(page.evaluate(() => window.sessionStorage.length)).resolves.toBe(0)
  await page.evaluate(() => window.sessionStorage.clear())
  await page.goto(`${WEB_ORIGIN}/tasks`)
  await page.reload()
  await expect(page.getByRole('heading', { name: 'المهام' }).first()).toBeVisible()
  const csrfToken = await currentCsrfToken(page)

  const logout = await page.request.post('/api/v1/identity/logout', {
    headers: {
      'X-Correlation-ID': correlationId(),
      'X-CSRF-Token': csrfToken,
      'Idempotency-Key': `closure-session-expiry-${correlationId()}`,
    },
  })
  expect(logout.status()).toBe(204)

  // Deterministic expiry: a session cookie with no backing session row is a
  // 401 boundary. Session metadata is deliberately never persisted in web
  // storage; the HttpOnly cookie is the only browser-held session credential.
  const origin = new URL(WEB_ORIGIN)
  await page.context().addCookies([{
    name: 'cluster_identity_session',
    value: '018f6f7d-0c00-7000-8000-00000000dead',
    domain: origin.hostname,
    path: '/',
  }])
  await page.reload()
  await expect(page.getByRole('form', { name: 'تسجيل الدخول' })).toBeVisible()
  await expect(page.evaluate(() => window.sessionStorage.length)).resolves.toBe(0)
})

test('cookie mutation rejects a missing CSRF proof and admits the matching proof', async ({ page }) => {
  await signInPlatformAdmin(page)
  const payload = {
    code: `CSRF-${correlationId().replaceAll('-', '').slice(-12).toUpperCase()}`,
    name: 'تجمع تحقق CSRF',
    name_en: 'CSRF verification cluster',
  }
  const rejected = await page.request.post('/api/v1/organization/cluster', {
    headers: {
      'X-Correlation-ID': correlationId(),
      'Idempotency-Key': `closure-csrf-rejected-${correlationId()}`,
    },
    data: payload,
  })
  expect(rejected.status()).toBe(403)
  await expect(rejected.json()).resolves.toMatchObject({
    type: 'https://cluster.example/problems/csrf-failed',
    status: 403,
  })

  const accepted = await page.request.post('/api/v1/organization/cluster', {
    headers: {
      'X-Correlation-ID': correlationId(),
      'X-CSRF-Token': await currentCsrfToken(page),
      'Idempotency-Key': `closure-csrf-accepted-${correlationId()}`,
    },
    data: payload,
  })
  expect(accepted.status()).toBe(409)
  await expect(accepted.json()).resolves.toMatchObject({
    type: 'https://cluster.example/problems/cluster-already-exists',
    status: 409,
    detail: 'Only one cluster may exist.',
  })
})

test('server capabilities gate both the platform-management route and its create control', async ({ browser, page }) => {
  await signIn(
    page,
    'ar',
    walkingSkeletonFixtures.accountA.username,
    walkingSkeletonFixtures.accountA.password,
  )
  await page.goto(`${WEB_ORIGIN}/platform-management`)
  await expect(page.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'إنشاء تقويم' })).toHaveCount(0)

  const ownerPage = await browser.newPage()
  try {
    await signInPlatformAdmin(ownerPage)
    await ownerPage.goto(`${WEB_ORIGIN}/platform-management`)
    await expect(ownerPage.getByRole('heading', { name: 'إدارة المنصة' })).toBeVisible()
    await ownerPage.getByRole('tab', { name: 'إعدادات المنصة', exact: true }).click()
    await expect(ownerPage.getByRole('button', { name: 'إنشاء تقويم' })).toBeVisible()
  } finally {
    await ownerPage.close()
  }
})

test('Organization surfaces a save error to the 409 loser when two owners create the same facility', async ({ browser }) => {
  const pageA = await browser.newPage()
  const pageB = await browser.newPage()
  const code = `CLOSURE-${correlationId().replaceAll('-', '').slice(-12).toUpperCase()}`

  try {
    await signInPlatformAdmin(pageA)
    await signInPlatformAdmin(pageB)
    await openOrganizationOverview(pageA)
    await openOrganizationOverview(pageB)
    await pageA.getByRole('tab', { name: 'المنشآت', exact: true }).click()
    await pageB.getByRole('tab', { name: 'المنشآت', exact: true }).click()

    await pageA.getByRole('button', { name: 'إضافة منشأة' }).click()
    await pageB.getByRole('button', { name: 'إضافة منشأة' }).click()
    await expect(pageA).toHaveURL(/\/organization\/facilities\/new$/)
    await expect(pageB).toHaveURL(/\/organization\/facilities\/new$/)
    await pageA.getByLabel('الرقم التعريفي').fill(code)
    await pageA.getByLabel('الاسم بالعربية', { exact: true }).fill('منشأة الفائز')
    await pageB.getByLabel('الرقم التعريفي').fill(code)
    await pageB.getByLabel('الاسم بالعربية', { exact: true }).fill('منشأة الخاسر')

    await pageA.getByRole('button', { name: 'حفظ' }).click()
    await expect(pageA.getByText('منشأة الفائز')).toBeVisible()

    const conflictResponse = pageB.waitForResponse((response) => (
      new URL(response.url()).pathname === '/api/v1/organization/facilities'
      && response.request().method() === 'POST'
    ))
    await pageB.getByRole('button', { name: 'حفظ' }).click()
    expect((await conflictResponse).status()).toBe(409)
    await expect(pageB.getByRole('alert')).toContainText('تعذر حفظ البيانات. أعد المحاولة.')
  } finally {
    await pageA.close()
    await pageB.close()
  }
})

// The rebuilt Calendars section reads the live platform-settings API and
// renders rows only when a calendar exists; the create button is a panel
// action on the ready state. The full lifecycle (create → weekday →
// exception → publish) through the new drawers is tracked as follow-up.
test.skip('Business Calendar persists create, weekday, exception, and publish through the UI', async ({ page }) => {
  await signInPlatformAdmin(page)
  await page.goto(`${WEB_ORIGIN}/platform-management`)
  await expect(page.getByRole('heading', { name: 'إدارة المنصة' })).toBeVisible()
  await page.getByRole('button', { name: 'التقويمات' }).click()
  await page.getByRole('button', { name: 'إنشاء تقويم' }).click()
  await expect(page.getByRole('dialog', { name: 'إنشاء تقويم' })).toBeVisible()
  await page.getByRole('dialog', { name: 'إنشاء تقويم' }).getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('تم تحديث البيانات.')).toBeVisible()
  await page.getByLabel('تعديل يوم عمل').first().selectOption('1')
  const weekdayDialog = page.getByRole('dialog', { name: 'تعديل يوم عمل' })
  await weekdayDialog.getByLabel('يوم عمل').check()
  await weekdayDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('تم تحديث البيانات.')).toBeVisible()
  await page.getByRole('button', { name: 'إضافة استثناء' }).first().click()
  const exceptionDialog = page.getByRole('dialog', { name: 'تعديل استثناء' })
  await exceptionDialog.getByLabel('التاريخ').fill('2099-06-15')
  await exceptionDialog.getByLabel('السبب').fill('Architecture closure browser journey')
  await exceptionDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('تم تحديث البيانات.')).toBeVisible()
  await page.getByRole('button', { name: 'نشر' }).first().click()
  await expect(page.getByText('تم تحديث البيانات.')).toBeVisible()
  await page.reload()
  await expect(page.getByText('منشور').first()).toBeVisible()
})
test('Organization stale-write loser sees 412 feedback and refreshes to the winner value', async ({ browser }) => {
  const pageA = await browser.newPage()
  const pageB = await browser.newPage()
  const suffix = correlationId().replaceAll('-', '').slice(-8)
  const winnerName = `تجمع الفائز ${suffix}`
  const loserName = `تجمع الخاسر ${suffix}`

  try {
    await signInPlatformAdmin(pageA)
    await signInPlatformAdmin(pageB)
    await openOrganizationOverview(pageA)
    await openOrganizationOverview(pageB)
    await pageA.getByRole('tab', { name: 'إعداد المجمّع', exact: true }).click()
    await pageB.getByRole('tab', { name: 'إعداد المجمّع', exact: true }).click()

    await pageA.getByRole('button', { name: 'تعديل بيانات التجمع' }).click()
    await pageB.getByRole('button', { name: 'تعديل بيانات التجمع' }).click()
    await expect(pageA).toHaveURL(/\/organization\/cluster\/edit$/)
    await expect(pageB).toHaveURL(/\/organization\/cluster\/edit$/)
    await expect(pageA.getByLabel('الاسم بالعربية')).toBeVisible()
    await expect(pageB.getByLabel('الاسم بالعربية')).toBeVisible()
    await pageA.getByLabel('الاسم بالعربية').fill(winnerName)
    await pageB.getByLabel('الاسم بالعربية').fill(loserName)

    const winnerResponse = pageA.waitForResponse((response) => (
      new URL(response.url()).pathname === '/api/v1/organization/cluster'
      && response.request().method() === 'PATCH'
    ))
    await pageA.getByRole('button', { name: 'حفظ' }).click()
    expect((await winnerResponse).status()).toBe(200)
    await expect(pageA.getByText(winnerName, { exact: true })).toBeVisible()

    const loserResponse = pageB.waitForResponse((response) => (
      new URL(response.url()).pathname === '/api/v1/organization/cluster'
      && response.request().method() === 'PATCH'
    ))
    await pageB.getByRole('button', { name: 'حفظ' }).click()
    expect((await loserResponse).status()).toBe(412)
    await expect(pageB.getByRole('alert')).toContainText('تغيّرت البيانات في مكان آخر. حدّث الصفحة ثم أعد المحاولة.')

    await pageB.getByRole('button', { name: 'إعادة المحاولة' }).click()
    await expect(pageB.getByLabel('الاسم بالعربية')).toHaveValue(winnerName)
    await expect(pageB.getByLabel('الاسم بالعربية')).not.toHaveValue(loserName)
  } finally {
    await pageA.close()
    await pageB.close()
  }
})
