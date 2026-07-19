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
    page.getByRole('heading', { name: 'تسجيل الدخول' }),
  ).toBeVisible()
  await expect(page.getByText('منصة التجمع الصحي الثالث')).toBeVisible()

  const username = page.getByLabel('اسم المستخدم')
  const password = page.getByLabel('كلمة المرور', { exact: true })
  await expect(username).toBeVisible()
  await expect(password).toHaveAttribute('type', 'password')

  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('alert')).toContainText(
    'أكمل اسم المستخدم وكلمة المرور.',
  )
  await expect(username).toHaveAttribute('aria-invalid', 'true')
  await expect(password).toHaveAttribute('aria-invalid', 'true')

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
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByText('Third Health Cluster Platform')).toBeVisible()
  await expect(page.getByLabel('Username')).toBeVisible()
  await expect(page.getByLabel('Password', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'العربية' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
})
