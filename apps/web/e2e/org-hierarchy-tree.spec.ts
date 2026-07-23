import { expect, test, type Page } from '@playwright/test'

import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: Page) {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: /صباح الخير|مساء الخير/ })).toBeVisible()
}

test('Organization tree renders the seeded four-layer hierarchy', async ({ page }) => {
  await signIn(page)

  // Open the organization menu and pick the structure view.
  await page.getByRole('button', { name: 'إدارة المنشآت والموظفين' }).click()
  await page.getByRole('link', { name: 'الهيكل التنظيمي' }).click()
  await page.getByRole('link', { name: 'شجرة الهيكل' }).click()

  // The page heading should mount.
  await expect(page.getByRole('heading', { name: 'الوحدات والمناصب' })).toBeVisible()

  // The five departments seeded by organization:demo-seed must be visible.
  await expect(page.getByText('إدارة الخدمات الصحية', { exact: true })).toBeVisible()
  await expect(page.getByText('إدارة الموارد البشرية', { exact: true })).toBeVisible()
  await expect(page.getByText('إدارة المالية', { exact: true })).toBeVisible()
  await expect(page.getByText('إدارة تقنية المعلومات', { exact: true })).toBeVisible()
  await expect(page.getByText('إدارة المشاريع', { exact: true })).toBeVisible()

  // The Follow-up Unit is the deepest seeded node; it must reach the canvas.
  const followUpCard = page.getByRole('button', {
    name: 'وحدة المتابعة، رمز UNIT-FOLLOWUP',
    exact: true,
  })
  await expect(followUpCard).toBeVisible()
  await followUpCard.click()

  // At least one position for the follow-up unit must be selectable.
  const drawer = page.getByRole('dialog', { name: 'وحدة المتابعة', exact: true })
  await expect(drawer).toBeVisible()
  await expect(drawer.getByText('مدير وحدة المتابعة', { exact: true })).toBeVisible()

  await page.screenshot({ path: 'test-results/org-hierarchy-tree.png', fullPage: true })
})
