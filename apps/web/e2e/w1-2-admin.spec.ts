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

test('W1.2 Arabic admin journey creates governed organization and identity resources', async ({ page }) => {
  await signIn(page)

  // Organization workspace — the overview tab creates the cluster root and
  // the first facility.
  await page.getByRole('button', { name: 'المنظمة', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'المنظمة' })).toBeVisible()
  await page.getByRole('button', { name: 'إضافة تجمع' }).click()
  const clusterDialog = page.getByRole('dialog', { name: 'إضافة تجمع' })
  await clusterDialog.getByLabel('الرقم التعريفي').fill('THC3')
  await clusterDialog.getByLabel('الاسم بالعربية').fill('التجمع الصحي الثالث')
  await clusterDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('THC3')).toBeVisible()

  await page.getByRole('button', { name: 'إضافة منشأة' }).click()
  const facilityDialog = page.getByRole('dialog', { name: 'إضافة منشأة' })
  await facilityDialog.getByLabel('النوع').selectOption('hospital')
  await facilityDialog.getByLabel('الرقم التعريفي').fill('HOSPITAL_A')
  await facilityDialog.getByLabel('اسم المنشأة بالعربية').fill('مستشفى الاختبار')
  await facilityDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('مستشفى الاختبار')).toBeVisible()

  // Structure tab — add a department unit and a position inside it.
  await page.getByRole('button', { name: 'الهيكل التنظيمي', exact: true }).click()
  await page.getByRole('button', { name: 'إضافة وحدة' }).click()
  const unitDialog = page.getByRole('dialog', { name: 'إضافة وحدة' })
  await unitDialog.getByLabel('الرقم التعريفي').fill('OPERATIONS')
  await unitDialog.getByLabel('الاسم بالعربية').fill('إدارة التشغيل')
  await unitDialog.getByLabel('نوع الوحدة').selectOption('department')
  await unitDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('إدارة التشغيل')).toBeVisible()

  await page.getByRole('button', { name: 'إضافة منصب' }).first().click()
  const positionDialog = page.getByRole('dialog', { name: 'إضافة منصب' })
  await positionDialog.getByLabel('الرقم التعريفي').fill('OPS_MANAGER')
  await positionDialog.getByLabel('العنوان').fill('مدير التشغيل')
  await positionDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('مدير التشغيل')).toBeVisible()

  // People tab — add an employee and assign them to the position.
  await page.getByRole('button', { name: 'الموظفون والتكليفات', exact: true }).click()
  await page.getByRole('button', { name: 'إضافة موظف' }).click()
  const personDialog = page.getByRole('dialog', { name: 'إضافة موظف' })
  await personDialog.getByLabel('الرقم الوظيفي').fill('EMP-E2E-001')
  await personDialog.getByLabel('الاسم بالعربية').fill('موظف رحلة المتصفح')
  await personDialog.getByRole('button', { name: 'حفظ' }).click()
  await expect(page.getByText('موظف رحلة المتصفح')).toBeVisible()

  await page.getByRole('button', { name: 'إنشاء تكليف' }).click()
  const assignmentDialog = page.getByRole('dialog', { name: 'إنشاء تكليف' })
  await assignmentDialog.getByLabel('الموظف').selectOption({ label: 'موظف رحلة المتصفح' })
  await assignmentDialog.getByLabel('المنصب').selectOption({ label: 'مدير التشغيل' })
  await assignmentDialog.getByLabel('بداية التكليف').fill('2026-07-19T10:00')
  await assignmentDialog.getByLabel('نهاية التكليف').fill('2026-07-20T10:00')
  await assignmentDialog.getByRole('button', { name: 'حفظ' }).click()
  const assignmentRow = page.locator('.screen-list__row').filter({ hasText: 'موظف رحلة المتصفح' }).filter({ hasText: 'مدير التشغيل' })
  await expect(assignmentRow).toBeVisible()

  // Identity workspace — the Accounts tab creates a sign-in account that
  // starts as awaiting activation.
  await page.getByRole('button', { name: 'الحسابات والصلاحيات', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'الحسابات والصلاحيات' })).toBeVisible()
  await page.getByRole('button', { name: 'إضافة حساب' }).click()
  const accountDialog = page.getByRole('dialog', { name: 'إضافة حساب دخول' })
  await accountDialog.getByLabel('اسم الدخول').fill('employee.e2e.001')
  await accountDialog.getByRole('button', { name: 'إنشاء الحساب' }).click()
  const accountRow = page.locator('.screen-list__row').filter({ hasText: 'employee.e2e.001' })
  await expect(accountRow).toBeVisible()
  await expect(accountRow.getByText('بانتظار التفعيل', { exact: true })).toBeVisible()

  // Import review — the direct route replaces the former organization
  // workspace link; the job starts as received and validation fails closed.
  await page.goto('/imports')
  await expect(page.getByRole('heading', { name: 'مراجعة استيراد البيانات' })).toBeVisible()
  await page.getByLabel('مرجع الملف', { exact: true }).fill('018f6f7d-0c00-7000-8000-000000000690')
  await page.getByRole('button', { name: 'بدء المراجعة' }).click()
  await expect(page.getByText('تم استلام الملف', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'تحقق' }).click()
  await expect(page.getByText('تعذر التنفيذ', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Import review' })).toBeVisible()
})
