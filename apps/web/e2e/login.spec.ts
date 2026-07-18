import { expect, test } from '@playwright/test'

test('login surface is Arabic-first, accessible, and runtime-local', async ({
  page,
}) => {
  const externalRequests: string[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (!['127.0.0.1', 'localhost'].includes(url.hostname))
      externalRequests.push(request.url())
  })

  await page.setViewportSize({ width: 320, height: 720 })
  await page.goto('/')

  await expect(page.locator('html')).toHaveAttribute('lang', 'ar')
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expect(
    page.getByRole('heading', { name: 'مرحباً بعودتك' }),
  ).toBeVisible()
  await expect(
    page.getByText('سجّل الدخول باستخدام حساب المنصة الداخلي.'),
  ).toBeVisible()
  await expect(page.getByText('جميع الحقوق محفوظة © 2026')).toBeVisible()
  await expect(page.getByText('مجمع إرادة والصحة النفسية')).toBeVisible()
  await expect(page.getByText('مكتب إدارة المشاريع والتحول المؤسسي')).toBeVisible()
  await expect(page.getByText('طارق الوليدي')).toBeVisible()
  const footerBox = await page.locator('.login-footer').boundingBox()
  expect(Math.round(footerBox?.x ?? -1)).toBe(0)
  expect(Math.round(footerBox?.width ?? -1)).toBe(320)
  const arabicActions = await page.locator('.login-page-actions').boundingBox()
  expect(arabicActions?.y ?? 999).toBeLessThan(80)
  expect(320 - ((arabicActions?.x ?? 0) + (arabicActions?.width ?? 0))).toBe(16)

  const username = page.getByLabel('اسم المستخدم')
  const password = page.getByLabel('كلمة المرور', { exact: true })
  await expect(username).toBeVisible()
  await expect(password).toHaveAttribute('type', 'password')

  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('alert')).toContainText('أكمل الحقول المطلوبة')
  await expect(username).toHaveAttribute('aria-invalid', 'true')
  await expect(password).toHaveAttribute('aria-invalid', 'true')
  await expect(page.getByText('اسم المستخدم مطلوب.')).toBeVisible()
  await expect(page.getByText('كلمة المرور مطلوبة.')).toBeVisible()

  await password.fill('internal-password')
  await page.getByRole('button', { name: 'إظهار كلمة المرور' }).click()
  await expect(password).toHaveAttribute('type', 'text')
  await expect(
    page.getByRole('button', { name: 'إخفاء كلمة المرور' }),
  ).toHaveAttribute('aria-pressed', 'true')

  await page.getByRole('button', { name: 'تفعيل الوضع الداكن' }).click()
  await expect(page.locator('.login-page')).toHaveAttribute(
    'data-login-theme',
    'dark',
  )
  await expect(
    page.getByRole('button', { name: 'تفعيل الوضع الفاتح' }),
  ).toHaveAttribute('aria-pressed', 'true')
  await page.reload()
  await expect(page.locator('.login-page')).toHaveAttribute(
    'data-login-theme',
    'dark',
  )

  const overflow = await page.evaluate(
    () =>
      document.documentElement.scrollWidth -
      document.documentElement.clientWidth,
  )
  expect(overflow).toBe(0)
  expect(externalRequests).toEqual([])
})

test('language switch preserves the login task in English LTR', async ({
  page,
}) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'English' }).click()

  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(
    page.getByRole('heading', { name: 'Welcome back' }),
  ).toBeVisible()
  await expect(
    page.getByText('Sign in with your internal platform account.'),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Show password' }),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Enable dark mode' }),
  ).toBeVisible()
  const englishActions = await page.locator('.login-page-actions').boundingBox()
  expect(englishActions?.y ?? 999).toBeLessThan(80)
  expect(englishActions?.x ?? 999).toBe(24)
})
