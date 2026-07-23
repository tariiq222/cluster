import { expect, test } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: import('@playwright/test').Page, path: string) {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
}

test.describe('personal work uses the isolated API fixture', () => {
  test('my requests settles and preserves its direct URL on reload', async ({ page }) => {
    await signIn(page, '/my-requests')
    await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
    await expect(page.getByText('تعذر تحميل البيانات')).toHaveCount(0)
    await page.reload()
    await expect(page).toHaveURL(/\/my-requests$/)
    await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
  })

  test('approval inbox reaches a non-loading state in Arabic and English', async ({ page }) => {
    await signIn(page, '/approvals')
    await expect(page.getByRole('heading', { name: 'اعتماداتي' })).toBeVisible()
    await expect(page.getByText('جارٍ تحميل بيانات العمل…')).toHaveCount(0)
    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByRole('heading', { name: 'My approvals' })).toBeVisible()
  })

  test('foreign-looking request detail never exposes a record body', async ({ page }) => {
    await signIn(page, '/my-requests/01980f50-5f0d-7000-8000-000000000199')
    await expect(page.getByRole('heading', { name: 'التفاصيل' })).toBeVisible()
    await expect(page.getByText('لا تتوفر تفاصيل لهذا السجل.')).toBeVisible()
    await expect(page.locator('#request-detail-panel')).toHaveCount(0)
  })
})
