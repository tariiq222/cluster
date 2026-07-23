import { expect, test } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: import('@playwright/test').Page, path: string) {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
}

test.describe('request journey', () => {
  /**
   * The inbox is keyed on the step assignee. Before the `/workflow/steps` endpoint
   * existed the screen listed instances the caller had started, so an approver saw
   * an empty box forever; reaching a settled state here is the regression guard.
   */
  test('the approval inbox settles instead of erroring', async ({ page }) => {
    await signIn(page, '/approvals')
    await expect(page).toHaveURL(/\/approvals$/)
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByRole('heading', { name: 'اعتماداتي' })).toBeVisible()

    await expect(
      page.getByText('لا توجد اعتمادات تنتظر قرارك').or(page.getByRole('button', { name: 'اعتماد' }).first()),
    ).toBeVisible({ timeout: 15000 })
    await expect(page.getByText('تعذر تحميل البيانات')).toHaveCount(0)
  })

  test('my requests loads and reloads on a direct link', async ({ page }) => {
    await signIn(page, '/my-requests')
    await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()

    await page.reload()
    await expect(page).toHaveURL(/\/my-requests$/)
    await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
  })

  test('a department can open the new procedure request form', async ({ page }) => {
    await signIn(page, '/procedures/new')
    await expect(page).toHaveURL(/\/procedures\/new$/)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  })

  test('the inbox switches to English without losing the route', async ({ page }) => {
    await signIn(page, '/approvals')
    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByRole('heading', { name: 'My approvals' })).toBeVisible()
    await expect(page).toHaveURL(/\/approvals$/)
  })
})
