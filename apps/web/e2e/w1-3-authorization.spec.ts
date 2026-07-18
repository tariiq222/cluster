import { expect, test, type Page } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: Page, path = '/') {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور').fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
}

test('W1.3 authorization list supports direct load, refresh, and language direction', async ({ page }) => {
  await signIn(page, '/admin/authorization/roles')
  await expect(page).toHaveURL(/\/admin\/authorization\/roles$/)
  await expect(page.getByRole('heading', { name: 'الأدوار' })).toBeVisible()
  await page.reload()
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور').fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الأدوار' })).toBeVisible()
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible()
})

test('W1.3 access explanation route is reachable without client authorization decisions', async ({ page }) => {
  await signIn(page, '/admin/authorization/explain')
  await expect(page.getByRole('heading', { name: 'شرح قرار الوصول' })).toBeVisible()
  await expect(page.getByLabel('معرّف القرار')).toBeVisible()
})
