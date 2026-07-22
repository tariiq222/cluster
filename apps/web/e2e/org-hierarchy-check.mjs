import { chromium } from '@playwright/test'
import { mkdirSync } from 'node:fs'

const WEB_ORIGIN = process.env.W1_1_WEB_ORIGIN ?? 'http://127.0.0.1:4173'
const USERNAME = 'w13-e2e-account-a'
const PASSWORD = 'North!River7Quartz2026'

mkdirSync('test-results', { recursive: true })

const browser = await chromium.launch()
const context = await browser.newContext({ locale: 'ar-SA' })
const page = await context.newPage()

const apiCalls = []
page.on('response', (response) => {
  const url = response.url()
  if (url.includes('/api/v1/')) {
    apiCalls.push({ method: response.request().method(), url: url.replace(WEB_ORIGIN, ''), status: response.status() })
  }
})

console.log(`Opening ${WEB_ORIGIN}`)
await page.goto(WEB_ORIGIN, { waitUntil: 'domcontentloaded' })

await page.getByLabel('اسم المستخدم').fill(USERNAME)
await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
await page.getByRole('heading', { name: 'طلباتي' }).waitFor({ timeout: 15_000 })

console.log('Navigating to org structure…')
await page.getByRole('button', { name: 'التنظيم' }).click()
await page.getByRole('link', { name: 'الهيكل والمناصب' }).click()
await page.waitForTimeout(2_500)

const orgFetches = apiCalls.filter((call) => call.url.includes('/organization/'))
const orgFailures = orgFetches.filter((call) => call.status >= 400)
console.log('\nOrg API responses:')
for (const call of orgFetches) {
  const tag = call.status >= 400 ? '✗' : '✓'
  console.log(`  ${tag} ${call.method} ${call.status} ${call.url}`)
}

const expectations = [
  { text: 'المكتب التنفيذي للتجمع الصحي', label: 'L1 sector' },
  { text: 'إدارة الخدمات الصحية', label: 'L2 health' },
  { text: 'إدارة الموارد البشرية', label: 'L2 hr' },
  { text: 'إدارة المالية', label: 'L2 finance' },
  { text: 'إدارة تقنية المعلومات', label: 'L2 it' },
  { text: 'إدارة المشاريع', label: 'L2 projects' },
  { text: 'قسم التخطيط', label: 'L3 planning section' },
  { text: 'قسم التنفيذ', label: 'L3 execution section' },
  { text: 'قسم الجودة', label: 'L3 quality section' },
  { text: 'وحدة المتابعة', label: 'L3 follow-up unit' },
]
const missing = []
for (const { text, label } of expectations) {
  const present = await page.getByText(text, { exact: true }).count() > 0
  console.log(`  ${present ? 'OK' : 'MISSING'} : ${label.padEnd(24, ' ')} (${text})`)
  if (!present) missing.push(label)
}

// Click the Follow-up unit card and verify the manager position shows in the drawer.
await page.locator('.org-board-card', { hasText: 'وحدة المتابعة' }).first().click({ force: true }).catch(() => {})
await page.waitForTimeout(800)
const managerVisible = (await page.getByText('مدير وحدة المتابعة', { exact: true }).count()) > 0
const analystVisible = (await page.getByText('محلل بيانات المتابعة', { exact: true }).count()) > 0
console.log(`  ${managerVisible ? 'OK' : 'MISSING'} : L4 manager position (مدير وحدة المتابعة)`)
console.log(`  ${analystVisible ? 'OK' : 'MISSING'} : L4 employee position (محلل بيانات المتابعة)`)
if (!managerVisible) missing.push('follow-up manager position')
if (!analystVisible) missing.push('follow-up analyst position')

await page.screenshot({ path: 'test-results/org-hierarchy-tree.png', fullPage: true })
await browser.close()

if (missing.length || orgFailures.length) {
  console.error('\nFAILED')
  if (missing.length) console.error('missing labels:', missing)
  if (orgFailures.length) console.error('org API failures:', orgFailures)
  process.exit(1)
}
console.log('\nPASS — four-layer hierarchy is rendered.')
