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

async function signIn(page: Page) {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function openStructureTab(page: Page) {
  await page.getByRole('navigation', { name: 'القائمة' }).getByRole('link', { name: 'المنظمة', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'المنظمة' })).toBeVisible()
  await page.getByRole('button', { name: 'الهيكل التنظيمي', exact: true }).click()
}

test('Organization tree renders the seeded four-layer hierarchy', async ({ page }) => {
  await signIn(page)
  await openStructureTab(page)

  // The five departments seeded by organization:demo-seed must be visible.
  await expect(page.getByText('إدارة الخدمات الصحية')).toBeVisible()
  await expect(page.getByText('إدارة الموارد البشرية')).toBeVisible()
  await expect(page.getByText('إدارة المالية')).toBeVisible()
  await expect(page.getByText('إدارة تقنية المعلومات')).toBeVisible()
  await expect(page.getByText('إدارة المشاريع')).toBeVisible()

  // The Follow-up Unit is the deepest seeded node; its row renders the unit
  // name with its code and the seeded positions as badges.
  await expect(page.getByText(/وحدة المتابعة · UNIT-FOLLOWUP/)).toBeVisible()
  await expect(page.getByText('مدير وحدة المتابعة')).toBeVisible()

  // The L3 sections seeded under إدارة المشاريع must reach the tree.
  await expect(page.getByText('قسم التخطيط')).toBeVisible()
  await expect(page.getByText('قسم التنفيذ')).toBeVisible()
  await expect(page.getByText('قسم الجودة')).toBeVisible()

  // The seeded analyst position must appear beside the follow-up unit.
  await expect(page.getByText('محلل بيانات المتابعة')).toBeVisible()
  await page.screenshot({ path: 'test-results/org-hierarchy-tree.png', fullPage: true })
})

test('Organization tree surfaces every seeded position for the follow-up unit', async ({ page }) => {
  await signIn(page)
  await openStructureTab(page)

  // The L1 sector root must be present alongside the L2 departments.
  await expect(page.getByText('المكتب التنفيذي للتجمع الصحي')).toBeVisible()

  // The Follow-up Unit renders as a shadcn Collapsible; its node is the
  // deepest `[data-slot="collapsible"]` carrying the unit name, and both
  // seeded position labels live inside that same node.
  const followUpRow = page.locator('[data-slot="collapsible"]', { hasText: 'وحدة المتابعة' }).last()
  await expect(followUpRow).toBeVisible()
  await expect(followUpRow.getByText('مدير وحدة المتابعة')).toBeVisible()
  await expect(followUpRow.getByText('محلل بيانات المتابعة')).toBeVisible()
  await page.screenshot({ path: 'test-results/org-hierarchy-tree-positions.png', fullPage: true })
})
