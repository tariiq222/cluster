import { expect, test } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: import('@playwright/test').Page, path: string) {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
}

test.describe('procedure authoring cycle', () => {
  test('the office authoring screen loads in Arabic and survives a direct reload', async ({ page }) => {
    await signIn(page, '/admin/procedures/authoring')
    await expect(page).toHaveURL(/\/admin\/procedures\/authoring$/)
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByRole('heading', { name: 'تصميم الإجراء' })).toBeVisible()

    await page.reload()
    await expect(page).toHaveURL(/\/admin\/procedures\/authoring$/)
    await expect(page.getByRole('heading', { name: 'تصميم الإجراء' })).toBeVisible()
  })

  test('the office review screen reaches a settled state rather than hanging on loading', async ({ page }) => {
    await signIn(page, '/admin/procedures/review')
    await expect(page.getByRole('heading', { name: 'اعتمادات الإجراء' })).toBeVisible()

    // Either there is nothing to review yet, or rows rendered. A stuck skeleton is a failure.
    await expect(
      page.getByText('لا توجد إصدارات بانتظار المراجعة').or(page.getByRole('heading', { level: 2 }).first()),
    ).toBeVisible({ timeout: 15000 })
  })

  test('the published guide is reachable by every signed-in user', async ({ page }) => {
    await signIn(page, '/procedures')
    await expect(page).toHaveURL(/\/procedures$/)
    await expect(page.getByRole('heading', { name: 'دليل الإجراءات المنشورة' })).toBeVisible()
    await expect(
      page.getByText('لا توجد إجراءات منشورة بعد').or(page.getByRole('heading', { level: 2 }).first()),
    ).toBeVisible({ timeout: 15000 })
  })

  test('the guide switches to English and keeps the route', async ({ page }) => {
    await signIn(page, '/procedures')
    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByRole('heading', { name: 'Procedure guide' })).toBeVisible()
    await expect(page).toHaveURL(/\/procedures$/)
  })
})
