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
    page.getByRole('heading', { name: 'منصة التجمع الصحي' }),
  ).toBeVisible()
  await expect(page.getByLabel('اسم المستخدم')).toBeVisible()
  const password = page.getByLabel('كلمة المرور', { exact: true })
  await expect(password).toHaveAttribute('type', 'password')

  // Empty submit surfaces the runtime-local error without a network round trip.
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('alert')).toContainText('حدث خطأ غير متوقع.')

  await password.fill('internal-password')
  await page.getByRole('button', { name: 'إظهار كلمة المرور' }).click()
  await expect(password).toHaveAttribute('type', 'text')
  await expect(
    page.getByRole('button', { name: 'إخفاء كلمة المرور' }),
  ).toBeVisible()

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
    page.getByRole('heading', { name: 'Health Cluster Platform' }),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Show password' }),
  ).toBeVisible()
})
