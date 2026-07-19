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

test('shared R1 screen states cover loading, empty, forbidden, error and stale', async ({ page }) => {
  await login(page, '/', 'ar')
  const openTasks = async () => page.evaluate(() => { window.history.pushState({}, '', `/tasks?state=${Date.now()}`); window.history.replaceState({}, '', '/tasks'); window.dispatchEvent(new PopStateEvent('popstate')) })
  let responseStatus = 200
  let delayResponse = true

  await page.route('**/api/v1/tasks?*', async (route) => {
    if (delayResponse) await new Promise((resolve) => setTimeout(resolve, 250))
    await route.fulfill({ status: responseStatus, contentType: responseStatus === 200 ? 'application/json' : 'application/problem+json', body: responseStatus === 200 ? JSON.stringify({ items: [], next_cursor: null }) : JSON.stringify({ type: 'about:blank', title: 'State', status: responseStatus }) })
  })
  await openTasks()
  await expect(page.getByLabel('جارٍ التحميل…')).toBeVisible()
  await expect(page.getByText('لا توجد بيانات متاحة')).toBeVisible()
  delayResponse = false

  for (const [status, message] of [[403, 'لا تملك صلاحية عرض هذه الشاشة.'], [500, 'تعذر تحميل البيانات.'], [412, 'تغيّرت البيانات على الخادم. حدّث الشاشة ثم أعد المحاولة.']] as const) {
    responseStatus = status
    await openTasks()
    await expect(page.getByText(message)).toBeVisible()
  }
})

for (const locale of ['ar', 'en'] as const) {
  test(`complete R1 screen registry is reachable in ${locale}`, async ({ page }) => {
    const screens = [
      ['/tasks', locale === 'ar' ? 'مهامي' : 'My tasks'],
      ['/admin/work-definitions', locale === 'ar' ? 'إدارة تعريفات العمل' : 'Work definition administration'],
      ['/admin/workflow', locale === 'ar' ? 'إدارة المسارات' : 'Workflow administration'],
      ['/search', locale === 'ar' ? 'البحث' : 'Search'],
      ['/reports', locale === 'ar' ? 'التقارير' : 'Reports'],
      ['/notifications', locale === 'ar' ? 'الإشعارات' : 'Notifications'],
    ] as const

    await login(page, screens[0][0], locale)
    for (const [path, heading] of screens) {
      await page.evaluate((nextPath) => { window.history.pushState({}, '', nextPath); window.dispatchEvent(new PopStateEvent('popstate')) }, path)
      await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
      await expect(page.locator('html')).toHaveAttribute('lang', locale)
    }
  })

  test(`search filters and request detail actions are rendered in ${locale}`, async ({ page }) => {
    await login(page, '/search', locale)
    await page.getByLabel(locale === 'ar' ? 'نص البحث' : 'Search text').fill(locale === 'ar' ? 'طلب' : 'request')
    await page.getByRole('button', { name: locale === 'ar' ? 'بحث' : 'Search', exact: true }).click()
    await expect(page.getByText(locale === 'ar' ? 'يعيد الخادم النتائج المصرح بها فقط؛ لا تُكشف عناوين الموارد المحجوبة.' : 'The server returns authorized results only; denied resource titles are never exposed.')).toBeVisible()

    await page.goto('/')
    const firstRecord = page.locator('button.request-card, a.request-card').first()
    if (await firstRecord.count()) {
      await firstRecord.click()
      await expect(page.getByRole('heading', { name: locale === 'ar' ? 'إجراءات الطلب' : 'Request actions' })).toBeVisible()
      await expect(page.getByRole('heading', { name: locale === 'ar' ? 'المستندات المرتبطة' : 'Linked documents' })).toBeVisible()
      await expect(page.getByRole('heading', { name: locale === 'ar' ? 'الخط الزمني للنشاط' : 'Activity timeline' })).toBeVisible()
    }
  })
}
