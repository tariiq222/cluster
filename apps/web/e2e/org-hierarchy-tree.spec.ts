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

  // Open the facilities and employees workspace, then exercise its local structure link.
  await page.getByRole('link', { name: 'المنشآت والموظفون', exact: true }).click()
  await page.getByRole('link', { name: 'المنشآت والهيكل التنظيمي', exact: true }).click()

  // The page heading should mount.
  await expect(page.getByRole('heading', { name: 'الهيكل التنظيمي' })).toBeVisible()

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

  // The L3 sections seeded under إدارة المشاريع must reach the canvas.
  await expect(page.getByText('قسم التخطيط', { exact: true })).toBeVisible()
  await expect(page.getByText('قسم التنفيذ', { exact: true })).toBeVisible()
  await expect(page.getByText('قسم الجودة', { exact: true })).toBeVisible()

  // The seeded analyst position must appear in the Follow-up unit's drawer,
  // mirroring the broader coverage the deleted bit-rotted .mjs asserted.
  await expect(drawer.getByText('محلل بيانات المتابعة', { exact: true })).toBeVisible()
  await page.screenshot({ path: 'test-results/org-hierarchy-tree.png', fullPage: true })
})

test('Organization tree drawer surfaces every seeded position for the follow-up unit', async ({ page }) => {
  await signIn(page)
  await page.getByRole('link', { name: 'المنشآت والموظفون', exact: true }).click()
  await page.getByRole('link', { name: 'المنشآت والهيكل التنظيمي', exact: true }).click()

  // The page heading should mount.
  await expect(page.getByRole('heading', { name: 'الهيكل التنظيمي' })).toBeVisible()
  // The L1 sector root must be present alongside the L2 departments.
  await expect(page.getByText('المكتب التنفيذي للتجمع الصحي', { exact: true })).toBeVisible()

  const followUpCard = page.getByRole('button', {
    name: 'وحدة المتابعة، رمز UNIT-FOLLOWUP',
    exact: true,
  })
  await expect(followUpCard).toBeVisible()
  await followUpCard.click()

  const drawer = page.getByRole('dialog', { name: 'وحدة المتابعة', exact: true })
  await expect(drawer).toBeVisible()
  await expect(drawer.getByText('مدير وحدة المتابعة', { exact: true })).toBeVisible()
  await expect(drawer.getByText('محلل بيانات المتابعة', { exact: true })).toBeVisible()
  await page.screenshot({ path: 'test-results/org-hierarchy-tree-positions.png', fullPage: true })
})
