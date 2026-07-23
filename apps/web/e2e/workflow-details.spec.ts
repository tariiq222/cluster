import { expect, test, type Page } from '@playwright/test'

const ids = {
  user: '01980f50-5f0d-7000-8000-000000000301',
  cluster: '01980f50-5f0d-7000-8000-000000000302',
  facility: '01980f50-5f0d-7000-8000-000000000303',
  unit: '01980f50-5f0d-7000-8000-000000000304',
  step: '01980f50-5f0d-7000-8000-000000000305',
  instance: '01980f50-5f0d-7000-8000-000000000306',
  version: '01980f50-5f0d-7000-8000-000000000307',
  task: '01980f50-5f0d-7000-8000-000000000308',
}

async function installAuthenticatedShell(page: Page) {
  let authenticated = false
  await page.route('**/api/v1/identity/login', route => {
    authenticated = true
    return route.fulfill({
      contentType: 'application/json',
      headers: {
        'set-cookie': 'cluster_identity_session=workflow-details; Path=/; HttpOnly; SameSite=Lax',
        'x-csrf-token': 'workflow-details-csrf',
      },
      body: JSON.stringify({ data: { user_id: ids.user, expires_at: '2027-07-23T09:00:00Z', restricted: false, csrf_token: 'workflow-details-csrf' } }),
    })
  })
  await page.route('**/api/v1/identity/me', route => route.fulfill(authenticated
    ? {
        contentType: 'application/json',
        body: JSON.stringify({ data: { principal: { user_id: ids.user }, session: { restricted: false }, facility_id: ids.facility, facility: 'facility-a', display_name: 'Workflow E2E' } }),
      }
    : {
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }),
      }))
  await page.route('**/api/v1/identity/csrf', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: { csrf_token: 'workflow-details-csrf' } }),
  }))
  await page.route('**/api/v1/me', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      subject_id: ids.user,
      tenant_id: ids.cluster,
      organization_unit_ids: [ids.unit],
      roles: ['manager'],
      capabilities: ['workflow.read', 'workflow.list', 'workflow.decide', 'tasks.read', 'tasks.list'],
      clearance: 'internal',
      break_glass: false,
      correlation_id: ids.user,
    }),
  }))
  await page.route('**/api/v1/me/scopes', route => route.fulfill({
    contentType: 'application/json',
    headers: { ETag: '"1"' },
    body: JSON.stringify({
      available_scopes: [{ scope_type: 'facility', scope_id: ids.facility, label: 'مستشفى الاختبار' }],
      effective_scope: { scope_type: 'facility', scope_id: ids.facility, label: 'مستشفى الاختبار' },
    }),
  }))
  for (const endpoint of [
    'workflow/steps?assignee=me&state=waiting&limit=100',
    'workflow/steps?assignee=me&state=active&limit=100',
    'workflow/instances?limit=100',
    'tasks?limit=100',
    'dashboards?limit=50',
    'work-records?limit=20',
    'notifications?limit=20',
  ]) {
    await page.route(`**/api/v1/${endpoint}`, route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ items: [], next_cursor: null }),
    }))
  }
}

async function signIn(page: Page, path: string) {
  await page.goto(path)
  await page.getByLabel('اسم المستخدم').fill('workflow-e2e')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('workflow-e2e-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
}

function stepDetail(allowedActions: string[], state = 'active') {
  return {
    step_id: ids.step,
    workflow_instance_id: ids.instance,
    source_type: 'work_record',
    source_id: 'WR-42',
    state,
    assignee_user_id: ids.user,
    created_at: '2026-07-23T08:00:00Z',
    lock_version: 7,
    allowed_actions: allowedActions,
    workflow_instance: {
      id: ids.instance,
      workflow_version_id: ids.version,
      source_module: 'workflow',
      source_type: 'work_record',
      source_id: 'WR-42',
      state: state === 'active' ? 'running' : 'completed',
      lock_version: 99,
    },
  }
}

test('approval detail obeys allowed actions and submits the step lock version', async ({ page }) => {
  await installAuthenticatedShell(page)
  let resolved = false
  let decisionHeaders: Record<string, string> = {}
  let decisionBody: unknown
  await page.route('**/api/v1/workflow/steps/**', async route => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    if (pathname === `/api/v1/workflow/steps/${ids.step}/decisions` && request.method() === 'POST') {
      decisionHeaders = request.headers()
      decisionBody = request.postDataJSON()
      resolved = true
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { id: ids.step } }) })
      return
    }
    await route.fulfill({
      contentType: 'application/json',
      headers: { ETag: '"7"' },
      body: JSON.stringify(stepDetail(resolved ? [] : ['approve'])),
    })
  })

  await signIn(page, `/approvals/${ids.step}`)
  await expect(page.getByRole('heading', { name: ids.instance })).toBeVisible()
  await expect(page.getByRole('button', { name: 'اعتماد' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'رفض' })).toHaveCount(0)
  await page.getByRole('button', { name: 'اعتماد' }).click()

  await expect(page.getByRole('button', { name: 'اعتماد' })).toHaveCount(0)
  expect(decisionHeaders['if-match']).toBe('"7"')
  expect(decisionBody).toEqual({ decision: 'approve' })
})

test('approval detail exposes a recoverable stale-decision state', async ({ page }) => {
  await installAuthenticatedShell(page)
  await page.route('**/api/v1/workflow/steps/**', async route => {
    const request = route.request()
    if (request.method() === 'POST') {
      await route.fulfill({
        status: 412,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'precondition-failed', title: 'Stale step', status: 412 }),
      })
      return
    }
    await route.fulfill({ contentType: 'application/json', headers: { ETag: '"7"' }, body: JSON.stringify(stepDetail(['approve'])) })
  })

  await signIn(page, `/approvals/${ids.step}`)
  await page.getByRole('button', { name: 'اعتماد' }).click()
  await expect(page.getByText('أصبحت هذه الخطوة قديمة. حدّث الصفحة قبل اتخاذ القرار.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'إعادة المحاولة' })).toBeVisible()
})

test('request history remains available on a direct URL reload', async ({ page }) => {
  await installAuthenticatedShell(page)
  await page.route(`**/api/v1/workflow/instances/${ids.instance}`, route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      id: ids.instance,
      workflow_version_id: ids.version,
      source_module: 'workflow',
      source_type: 'work_record',
      source_id: 'WR-42',
      state: 'running',
      lock_version: 3,
      created_at: '2026-07-23T07:00:00Z',
      updated_at: '2026-07-23T08:00:00Z',
      current_owner_user_id: ids.user,
      age_seconds: 3600,
      step_history: [{
        step_id: ids.step,
        workflow_instance_id: ids.instance,
        lock_version: 7,
        node_key: 'manager-review',
        node_type: 'approval',
        state: 'active',
        assignee_user_id: ids.user,
        activated_at: '2026-07-23T08:00:00Z',
        completed_at: null,
        actor_user_id: null,
        decision: null,
        reason: null,
      }],
    }),
  }))

  await signIn(page, `/my-requests/${ids.instance}`)
  await expect(page.getByText('manager-review')).toBeVisible()
  await page.reload()
  await expect(page).toHaveURL(new RegExp(`/my-requests/${ids.instance}$`))
  await expect(page.getByText('manager-review')).toBeVisible()
})

test('task detail loads its actions and comments from the direct URL', async ({ page }) => {
  await installAuthenticatedShell(page)
  await page.route(`**/api/v1/tasks/${ids.task}`, route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ id: ids.task, title: 'مراجعة الطلب', description: 'تحقق من بيانات الطلب.', status: 'open', lock_version: 2, allowed_actions: ['complete'] }),
  }))
  await page.route(`**/api/v1/tasks/${ids.task}/comments?limit=100`, route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ items: [{ id: ids.step, body: 'تمت مراجعة المرفقات.' }], next_cursor: null }),
  }))

  await signIn(page, `/tasks/${ids.task}`)
  await expect(page.getByRole('heading', { name: 'مراجعة الطلب' })).toBeVisible()
  await expect(page.getByText('complete')).toBeVisible()
  await expect(page.getByText('تمت مراجعة المرفقات.')).toBeVisible()
})
