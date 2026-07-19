import { expect, test } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

for (const locale of ['ar', 'en'] as const) {
  test(`R1 day3 journey is scoped and complete in ${locale}`, async ({ page }) => {
    await page.goto('/admin/workflow/day2')
    await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
    if (locale === 'en') await page.getByRole('button', { name: 'English' }).click()
    await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')

    const suffix = locale === 'ar' ? 'ar' : 'en'
    const title = locale === 'ar' ? 'طلب رحلة اليوم الثالث العربية' : 'Day three English request'
    await page.getByLabel(locale === 'ar' ? 'رمز تعريف العمل' : 'Work definition code').fill(`request-day3-${suffix}`)
    await page.getByLabel(locale === 'ar' ? 'اسم التعريف' : 'Definition name').fill(title)
    await page.getByRole('button', { name: locale === 'ar' ? 'حفظ ونشر' : 'Save and publish' }).click()
    await page.getByLabel(locale === 'ar' ? 'عنوان الطلب' : 'Request title').fill(title)
    await page.getByLabel(locale === 'ar' ? 'الوصف' : 'Description').fill(locale === 'ar' ? 'رحلة قبول R1 الكاملة ضمن نطاق المنشأة' : 'Complete R1 acceptance journey in the selected facility scope')
    await page.getByRole('button', { name: locale === 'ar' ? 'إنشاء وإرسال الطلب' : 'Create and submit request' }).click()
    await expect(page.getByRole('region', { name: locale === 'ar' ? 'المهمة الناتجة' : 'Generated task' })).toBeVisible()
    await page.getByRole('button', { name: locale === 'ar' ? 'إعادة المهمة' : 'Return task' }).click()
    await page.getByRole('button', { name: locale === 'ar' ? 'إكمال المهمة' : 'Complete task' }).click()
    await page.getByRole('button', { name: locale === 'ar' ? 'إكمال المستند والبحث والتقرير واللوحة' : 'Complete document, search, report and dashboard' }).click()

    const evidence = page.getByRole('region', { name: locale === 'ar' ? 'أدلة اليوم الثالث' : 'Day 3 evidence' })
    await expect(evidence).toBeVisible()
    for (const item of locale === 'ar'
      ? ['المستند مرفق', 'الإشعار مستلم', 'نتيجة البحث', 'نتيجة التقرير', 'نتيجة اللوحة']
      : ['Document attached', 'Notification received', 'Search result', 'Report result', 'Dashboard result']) {
      await expect(evidence).toContainText(item)
    }
  })
}
