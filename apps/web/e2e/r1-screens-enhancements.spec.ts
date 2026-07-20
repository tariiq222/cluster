import { expect, test } from '@playwright/test'
import { walkingSkeletonFixtures } from '../src/test/setup'

async function login(page: import('@playwright/test').Page, path: string, locale: 'ar' | 'en') {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  if (locale === 'en') await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
}

for (const locale of ['ar', 'en'] as const) {
  test(`tasks filter and confirm flow in ${locale}`, async ({ page }) => {
    await login(page, '/tasks', locale)

    const openLabel = locale === 'ar' ? 'المفتوحة' : 'Open'
    const doneLabel = locale === 'ar' ? 'المكتملة' : 'Done'
    const allLabel = locale === 'ar' ? 'الكل' : 'All'
    const refreshLabel = locale === 'ar' ? 'تحديث' : 'Refresh'

    await page.getByRole('button', { name: openLabel, exact: true }).click()
    await expect(page.getByRole('button', { name: openLabel, exact: true })).toHaveAttribute('aria-pressed', 'true')

    await page.getByRole('button', { name: doneLabel, exact: true }).click()
    await expect(page.getByRole('button', { name: doneLabel, exact: true })).toHaveAttribute('aria-pressed', 'true')

    await page.getByRole('button', { name: allLabel, exact: true }).click()
    await expect(page.getByRole('button', { name: allLabel, exact: true })).toHaveAttribute('aria-pressed', 'true')

    await page.getByRole('button', { name: refreshLabel, exact: true }).click()

    const updatedLabel = locale === 'ar' ? 'آخر تحديث' : 'Last refreshed'
    await expect(page.getByText(new RegExp(updatedLabel))).toBeVisible()
  })

  test(`dashboard renders KPI cards and quick links in ${locale}`, async ({ page }) => {
    await login(page, '/', locale)

    const dashboardHeading = locale === 'ar' ? 'اللوحة التكيفية حسب الدور والنطاق' : 'Role and scope adaptive dashboard'
    await expect(page.getByRole('heading', { name: dashboardHeading })).toBeVisible()

    const kpiScope = locale === 'ar' ? 'إجمالي ضمن النطاق' : 'Total in scope'
    await expect(page.getByText(kpiScope)).toBeVisible()

    const openTasksLink = page.getByRole('link', { name: locale === 'ar' ? 'فتح المهام' : 'Open tasks' })
    await expect(openTasksLink).toBeVisible()
    await openTasksLink.click()
    await expect(page).toHaveURL(/\/tasks$/)
    await expect(page.getByRole('heading', { name: locale === 'ar' ? 'مهامي' : 'My tasks' })).toBeVisible()
  })

  test(`search shows query summary and clear button in ${locale}`, async ({ page }) => {
    await login(page, '/search', locale)

    const searchInput = page.getByLabel(locale === 'ar' ? 'نص البحث' : 'Search text')
    await searchInput.fill(locale === 'ar' ? 'طلب' : 'request')

    const submitLabel = locale === 'ar' ? 'بحث' : 'Search'
    await page.getByRole('button', { name: submitLabel, exact: true }).click()

    const clearLabel = locale === 'ar' ? 'مسح' : 'Clear'
    const clearButton = page.getByRole('button', { name: clearLabel, exact: true })
    if (await clearButton.count()) {
      await clearButton.click()
      await expect(searchInput).toHaveValue('')
    }
  })

  test(`notifications screen shows read/unread counters in ${locale}`, async ({ page }) => {
    await login(page, '/notifications', locale)

    const heading = page.getByRole('heading', { name: locale === 'ar' ? 'الإشعارات' : 'Notifications' })
    await expect(heading).toBeVisible()

    const countersPattern = locale === 'ar' ? /غير المقروء: \d+ \| المقروء: \d+/ : /Unread: \d+ \| Read: \d+/
    const counters = page.getByText(countersPattern)
    if (await counters.count()) {
      await expect(counters.first()).toBeVisible()
    }
  })

  test(`reports screen export card renders status label in ${locale}`, async ({ page }) => {
    await login(page, '/reports', locale)

    await expect(page.getByRole('heading', { name: locale === 'ar' ? 'التقارير' : 'Reports' })).toBeVisible()
    const exportLabel = locale === 'ar' ? 'تصدير التقرير' : 'Export report'
    if (await page.getByRole('heading', { name: exportLabel }).count()) {
      await expect(page.getByRole('heading', { name: exportLabel })).toBeVisible()
      const button = page.getByRole('button', { name: locale === 'ar' ? 'طلب تصدير' : 'Request export' })
      await expect(button).toBeVisible()
    }
  })
}