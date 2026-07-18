import { test, expect } from '@playwright/test'

test.describe('day 2 workflow vertical', () => {
  test('direct load keeps the workflow route and supports locale direction', async ({ page }) => {
    await page.goto('/admin/workflow/day2')
    await expect(page).toHaveURL(/\/admin\/workflow\/day2$/)
    await expect(page.locator('html')).toHaveAttribute('dir', /rtl|ltr/)
    await page.reload()
    await expect(page).toHaveURL(/\/admin\/workflow\/day2$/)
  })
})
