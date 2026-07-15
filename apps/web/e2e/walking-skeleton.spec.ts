import { expect, test } from '@playwright/test'

import { walkingSkeletonFixtures, walkingSkeletonLocales } from '../src/test/setup'

test('account A submits its request, sees only its row, and receives its in-app notification', async ({ page }) => {
  await page.goto('/')

  await expect(page.locator('html')).toHaveAttribute('lang', walkingSkeletonLocales.arabic.lang)
  await expect(page.locator('html')).toHaveAttribute('dir', walkingSkeletonLocales.arabic.dir)

  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('كلمة المرور').fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

  await page.getByRole('link', { name: 'طلب جديد' }).click()
  await page.getByLabel('عنوان الطلب (مطلوب)').fill(walkingSkeletonFixtures.accountA.title)
  await page.getByLabel('وصف الطلب (مطلوب)').fill(walkingSkeletonFixtures.accountA.description)
  await page.getByRole('button', { name: 'إرسال الطلب' }).click()

  await expect(page.getByRole('heading', { name: 'تم إرسال طلبك' })).toBeFocused()
  await page.getByRole('link', { name: 'العودة إلى طلباتي' }).click()
  await expect(page.getByText(walkingSkeletonFixtures.accountA.title)).toBeVisible()
  await expect(page.getByText(walkingSkeletonFixtures.accountA.description)).toBeVisible()

  await page.getByRole('button', { name: 'الإشعارات' }).click()
  await page.getByRole('button', { name: 'تحديث الإشعارات' }).click()
  await expect(page.getByText(walkingSkeletonFixtures.accountA.title)).toBeVisible()
})

test('English uses LTR while the same account A journey remains available', async ({ page }) => {
  await page.goto('/')

  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', walkingSkeletonLocales.english.lang)
  await expect(page.locator('html')).toHaveAttribute('dir', walkingSkeletonLocales.english.dir)

  await page.getByLabel('Username').fill(walkingSkeletonFixtures.accountA.username)
  await page.getByLabel('Password').fill(walkingSkeletonFixtures.accountA.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.getByRole('link', { name: 'New request' }).click()
  await page.getByLabel('Request title (required)').fill(walkingSkeletonFixtures.accountA.title)
  await page.getByLabel('Request description (required)').fill(walkingSkeletonFixtures.accountA.description)
  await page.getByRole('button', { name: 'Submit request' }).click()

  await expect(page.getByRole('heading', { name: 'Your request was submitted' })).toBeFocused()
})

test('account B cannot reveal account A record metadata', async ({ page }) => {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill(walkingSkeletonFixtures.accountB.username)
  await page.getByLabel('كلمة المرور').fill(walkingSkeletonFixtures.accountB.password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await page.goto(`/work-records/${walkingSkeletonFixtures.unavailableRecordId}`)

  await expect(page.getByText('لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.')).toBeVisible()
  await expect(page.getByText(walkingSkeletonFixtures.accountA.title)).not.toBeVisible()
  await expect(page.getByText(walkingSkeletonFixtures.accountA.description)).not.toBeVisible()
  await expect(page.getByText(/facility|منشأة|authorization trace|مسار الصلاحية/i)).not.toBeVisible()
})
