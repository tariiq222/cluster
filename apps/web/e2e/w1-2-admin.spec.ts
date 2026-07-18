import { expect, test, type Page } from '@playwright/test'

import { walkingSkeletonFixtures } from '../src/test/setup'

async function signIn(page: Page) {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور').fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'طلباتي' })).toBeVisible()
}

test('W1.2 Arabic admin journey creates governed organization and identity resources', async ({ page }) => {
  await signIn(page)

  await page.getByRole('link', { name: 'التنظيم' }).first().click()
  await expect(page.getByRole('heading', { name: 'التجمع والمنشآت' })).toBeVisible()
  await page.getByLabel('رمز التجمع').fill('THC3')
  await page.getByLabel('اسم التجمع بالعربية').fill('التجمع الصحي الثالث')
  await page.getByRole('button', { name: 'إنشاء جذر التجمع' }).click()
  await expect(page.getByText('THC3')).toBeVisible()

  await page.getByLabel('رمز المنشأة').fill('HOSPITAL_A')
  await page.getByLabel('اسم المنشأة بالعربية').fill('مستشفى الاختبار')
  await page.getByLabel('نوع المنشأة').selectOption('hospital')
  await page.getByRole('button', { name: 'إضافة منشأة' }).click()
  await expect(page.getByRole('cell', { name: 'مستشفى الاختبار' })).toBeVisible()

  await page.getByRole('link', { name: 'الهيكل والمناصب' }).click()
  await page.getByLabel('الرمز').first().fill('OPERATIONS')
  await page.getByLabel('الاسم بالعربية').fill('إدارة التشغيل')
  await page.getByLabel('النوع').selectOption('department')
  await page.getByRole('button', { name: 'إضافة وحدة' }).click()
  await expect(page.getByRole('cell', { name: 'إدارة التشغيل' })).toBeVisible()

  await page.getByLabel('الرمز').last().fill('OPS_MANAGER')
  await page.getByLabel('المسمى بالعربية').fill('مدير التشغيل')
  await page.getByRole('button', { name: 'إضافة منصب' }).click()
  await expect(page.getByRole('cell', { name: 'مدير التشغيل' })).toBeVisible()

  await page.getByRole('link', { name: 'الأشخاص والتكليفات' }).click()
  await page.getByLabel('الرقم الوظيفي').fill('EMP-E2E-001')
  await page.getByLabel('الاسم بالعربية').fill('موظف رحلة المتصفح')
  await page.getByRole('button', { name: 'إضافة شخص' }).click()
  await expect(page.getByRole('cell', { name: 'موظف رحلة المتصفح' })).toBeVisible()

  await page.getByLabel('الشخص').selectOption({ label: 'موظف رحلة المتصفح' })
  await page.getByLabel('المنصب').selectOption({ label: 'مدير التشغيل' })
  await page.getByLabel('بداية التكليف').fill('2026-07-19T10:00')
  await page.getByLabel('نهاية التكليف').fill('2026-07-20T10:00')
  await page.getByRole('button', { name: 'إضافة تكليف' }).click()
  const assignmentRow = page.getByRole('row').filter({ hasText: 'موظف رحلة المتصفح' }).filter({ hasText: 'مدير التشغيل' })
  await expect(assignmentRow).toBeVisible()

  await page.getByRole('link', { name: 'حسابات الهوية' }).click()
  await page.getByLabel('اسم المستخدم').fill('employee.e2e.001')
  await page.getByRole('button', { name: 'إنشاء حساب pending' }).click()
  await expect(page.getByRole('cell', { name: 'employee.e2e.001' })).toBeVisible()
  await page.getByLabel('الحساب', { exact: true }).selectOption({ label: 'employee.e2e.001' })
  await page.getByRole('button', { name: 'تنفيذ الإجراء' }).click()
  const accountRow = page.getByRole('row').filter({ hasText: 'employee.e2e.001' })
  await expect(accountRow.getByText('نشط', { exact: true })).toBeVisible()

  await page.getByRole('link', { name: 'مراجعة الاستيراد' }).click()
  await expect(page.getByText(/رفع bytes غير متاح/)).toBeVisible()
  await page.getByLabel('معرف quarantine').fill('018f6f7d-0c00-7000-8000-000000000690')
  await page.getByRole('button', { name: 'إنشاء ImportJob' }).click()
  await expect(page.getByText('مستلم', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'تنفيذ' }).click()
  await expect(page.getByText('فشل', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Import review' })).toBeVisible()
})
