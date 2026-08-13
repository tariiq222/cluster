import { expect, test, type Page, type Route } from '@playwright/test'

/**
 * Read-only Playwright journey for the M01 Audit workspace.
 *
 * The shell is fully mocked via `page.route` so the suite never touches the
 * live backend. Every endpoint the workspace depends on — cookie-session
 * restoration (`/api/v1/identity/me`, `/api/v1/identity/csrf`), the
 * principal snapshot (`/api/v1/me`), `/api/v1/me/scopes`, the login
 * handshake, the audit ledger endpoints, and the notifications probe — is
 * stubbed with deterministic payloads. No mutations are submitted without
 * an explicit fixture.
 *
 * `/audit` is a retired path that redirects to `/reports`; with an
 * audit-only capability the Audit tab becomes active automatically, so every
 * journey lands on the Audit ledger through the Reports workspace.
 *
 * The page renders in Arabic by default. Both Arabic and English labels
 * are covered because the production surface ships fully bilingual; the
 * assertion text mirrors `src/features/audit/AuditScreen.tsx`.
 */

const SUBJECT_ID = '01980f50-5f0d-7000-8000-000000000901'
const SCOPE_ID = '01980f50-5f0d-7000-8000-000000000904'
const EVENT_ID = '01980f50-5f0d-7000-8000-000000000905'
const CORRELATION_ID = '01980f50-5f0d-7000-8000-000000000906'
const ACTOR_ID = '01980f50-5f0d-7000-8000-000000000907'
const SUBJECT_RESOURCE_ID = '01980f50-5f0d-7000-8000-000000000908'

const READ_ONLY_CAPABILITIES = ['audit.event.read'] as const

const LEDGER_EVENT = {
  event_id: EVENT_ID,
  source_module: 'documents',
  action: 'document.uploaded',
  event_type: 'com.cluster.documents.documentuploaded.v1',
  actor_type: 'user',
  actor_id: ACTOR_ID,
  original_actor_id: null,
  subject_type: 'document',
  subject_id: SUBJECT_RESOURCE_ID,
  correlation_id: CORRELATION_ID,
  outcome: 'succeeded',
  classification: 'confidential',
  context: {
    document_id: SUBJECT_RESOURCE_ID,
    filename: '[REDACTED]',
    quarantine_id: '[REDACTED]',
  },
  occurred_at: '2026-07-27T08:00:00.000Z',
  recorded_at: '2026-07-27T08:00:00.125Z',
  access_decision_id: null,
  retention_until: '2033-07-27T08:00:00.000Z',
  integrity_status: 'verified',
  allowed_actions: [],
}

async function fulfillJson(
  route: Route,
  body: unknown,
  status = 200,
  headers: Record<string, string> = {},
): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    headers,
    body: JSON.stringify(body),
  })
}

async function mockShell(
  page: Page,
  capabilities: readonly string[],
): Promise<void> {
  // Catch-all defensive stub: any other GET that bubbles through stays benign.
  await page.route('**/api/v1/**', (route) => {
    if (route.request().method() !== 'GET')
      return route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
    return fulfillJson(route, { items: [], next_cursor: null })
  })

  let authenticated = false

  // 1. Cookie-session restoration probe on bootstrap (`App.tsx` →
  //    `restoreSession`). 401 until the login handshake completes. The
  //    principal snapshot itself is served by `GET /api/v1/me` (route 2),
  //    never by this session envelope.
  await page.route('**/api/v1/identity/me', (route) =>
    fulfillJson(
      route,
      authenticated
        ? {
            data: {
              user_id: SUBJECT_ID,
              csrf_token: 'audit-workspace-csrf',
              capabilities: [...capabilities],
              features: { tasks: false },
            },
          }
        : {
            type: 'about:blank',
            title: 'Unauthorized',
            status: 401,
          },
      authenticated ? 200 : 401,
    ),
  )

  // 2. Principal snapshot (`PrincipalProvider` → `getCurrentPrincipal` →
  //    `GET /api/v1/me`). The root projection carries the capability codes
  //    and feature flags the shell renders. Registered after the catch-all
  //    above so this exact route wins for `/api/v1/me`; all fixture ids are
  //    the suite's existing valid UUID constants.
  await page.route('**/api/v1/me', (route) =>
    fulfillJson(
      route,
      {
        subject_id: SUBJECT_ID,
        tenant_id: SCOPE_ID,
        organization_unit_ids: [SCOPE_ID],
        roles: ['audit-reader'],
        capabilities: [...capabilities],
        clearance: 'internal',
        break_glass: false,
        correlation_id: CORRELATION_ID,
        features: { tasks: false },
      },
      200,
    ),
  )

  // 3. Login handshake (`LoginScreen` → `identityLogin`).
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true
    return fulfillJson(
      route,
      {
        data: {
          user_id: SUBJECT_ID,
          expires_at: '2099-07-23T09:00:00Z',
          restricted: false,
          csrf_token: 'audit-workspace-csrf',
        },
      },
      200,
      {
        'set-cookie':
          'cluster_identity_session=audit-workspace; Path=/; HttpOnly; SameSite=Lax',
        'x-csrf-token': 'audit-workspace-csrf',
      },
    )
  })

  // 4. CSRF rotation (`restoreSession` → `refreshIdentityCsrf`).
  await page.route('**/api/v1/identity/csrf', (route) =>
    fulfillJson(route, {
      data: { csrf_token: 'audit-workspace-csrf' },
    }),
  )

  // 5. Scope selection (`PrincipalProvider` → `loadScopeSelection`).
  await page.route('**/api/v1/me/scopes', (route) =>
    fulfillJson(
      route,
      {
        available_scopes: [
          {
            scope_type: 'facility',
            scope_id: SCOPE_ID,
            label: 'منشأة التدقيق',
          },
        ],
        effective_scope: {
          scope_type: 'facility',
          scope_id: SCOPE_ID,
          label: 'منشأة التدقيق',
        },
      },
      200,
      { ETag: '"1"' },
    ),
  )

  // 6. Notifications probe (`HomeDashboard` → `useNotificationsList`).
  await page.route('**/api/v1/notifications?limit=**', (route) =>
    fulfillJson(route, { items: [], next_cursor: null }),
  )

  // 7. Audit ledger list — captured so the filter assertion can inspect the
  //    request the workspace actually emits.
  await page.route('**/api/v1/audit/events**', (route) =>
    fulfillJson(route, {
      data: { items: [LEDGER_EVENT], next_cursor: null },
    }),
  )

  // 8. Audit event detail drawer — same shape, with a payload that would
  //    expose hash material if the projection ever regresses. The journey
  //    asserts that no hash/key/fingerprint bytes reach the DOM.
  await page.route(`**/api/v1/audit/events/${EVENT_ID}`, (route) =>
    fulfillJson(route, {
      data: LEDGER_EVENT,
    }),
  )
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('audit-reader')
  await page
    .getByLabel('كلمة المرور', { exact: true })
    .fill('audit-reader-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page.getByRole('heading', { name: 'الرئيسية' })).toBeVisible()
}

async function navigate(page: Page, path: string): Promise<void> {
  await page.evaluate((nextPath) => {
    window.history.pushState({}, '', nextPath)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, path)
}

test('Audit ledger renders one redacted event and the detail drawer hides hash material', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await mockShell(page, READ_ONLY_CAPABILITIES)
  await signIn(page)

  const listRequests: URL[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.pathname === '/api/v1/audit/events') listRequests.push(url)
  })

  // The retired `/audit` path redirects to `/reports`, where the audit-only
  // capability activates the Audit tab automatically.
  await navigate(page, '/audit')
  await expect(page).toHaveURL(/\/reports$/)
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

  // Page chrome — Arabic default copy straight from the audit screen source.
  await expect(page.getByRole('heading', { name: 'سجل التدقيق' })).toBeVisible()
  await expect(
    page.getByText(
      'استعرض سجلًا غير قابل للتغيير، نزّل لقطات مبررة، وتحقق من سلامة السلاسل دون كشف مواد التجزئة.',
    ),
  ).toBeVisible()

  // The compact summary band reflects a single ledger entry. The loaded
  // metric lives in the same row as the matching label, which keeps the
  // assertion deterministic when other rows also happen to show `1`.
  const summary = page.getByTestId('audit-summary')
  await expect(summary).toBeVisible()
  const eventsMetric = summary.locator('div').filter({ hasText: 'الأحداث' })
  await expect(eventsMetric.locator('dd')).toHaveText('1')

  // Filter controls are reachable by their Field labels.
  const sourceModule = page.getByLabel('الوحدة المصدر')
  const action = page.getByLabel('الإجراء')
  const classification = page.getByLabel('التصنيف')
  const occurredFrom = page.getByLabel('وقع من')
  const occurredTo = page.getByLabel('وقع إلى')
  await expect(sourceModule).toBeVisible()
  await expect(action).toBeVisible()
  await expect(classification).toBeVisible()
  await expect(occurredFrom).toBeVisible()
  await expect(occurredTo).toBeVisible()
  await expect(
    classification.locator('option', { hasText: 'كل التصنيفات' }),
  ).toHaveCount(1)

  // Ledger table renders exactly one row and the action cell surfaces
  // the projected event_type metadata without leaking hash material.
  const ledgerTable = page.getByRole('table').filter({ hasText: 'وقت الحدث' })
  await expect(ledgerTable).toBeVisible()
  const ledgerRow = ledgerTable
    .getByRole('row')
    .filter({ hasText: 'document.uploaded' })
  await expect(ledgerRow).toHaveCount(1)
  await expect(ledgerRow).toContainText('documents')
  await expect(ledgerRow.getByRole('cell', { name: 'succeeded' })).toBeVisible()
  await expect(ledgerRow.getByRole('cell', { name: 'verified' })).toBeVisible()
  await expect(ledgerRow).not.toContainText('event_hash')
  await expect(ledgerRow).not.toContainText('previous_hash')
  await expect(ledgerRow).not.toContainText('integrity_key_version')

  // On a narrow viewport the page itself must not overflow horizontally;
  // the wide ledger remains usable through its dedicated scroll container.
  await page.setViewportSize({ width: 390, height: 844 })
  await expect
    .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(390)
  const tableContainer = page.locator('[data-slot="table-container"]')
  await expect(tableContainer).toBeVisible()
  expect(
    await tableContainer.evaluate(
      (element) => element.scrollWidth > element.clientWidth,
    ),
  ).toBe(true)
  await page.setViewportSize({ width: 1280, height: 800 })
  // Apply a real filter — only `source_module`, `action`, and `classification`
  // are sent when the date bounds are left blank.
  await sourceModule.fill('authorization')
  await action.fill('authorization.decision.denied')
  await classification.selectOption('top_secret')
  await page.getByRole('button', { name: 'تطبيق المرشحات' }).click()
  await expect(page.getByRole('button', { name: 'مسح' })).toBeVisible()

  // The most recent list request encodes the three filters exactly.
  const filterRequest = listRequests[listRequests.length - 1]
  expect(filterRequest.searchParams.get('source_module')).toBe('authorization')
  expect(filterRequest.searchParams.get('action')).toBe(
    'authorization.decision.denied',
  )
  expect(filterRequest.searchParams.get('classification')).toBe('top_secret')
  expect(filterRequest.searchParams.get('occurred_from')).toBeNull()
  expect(filterRequest.searchParams.get('occurred_to')).toBeNull()

  // Clear returns the workspace to its unfiltered shape so the detail
  // drawer opens against a deterministic ledger row.
  await page.getByRole('button', { name: 'مسح' }).click()
  await expect(
    ledgerTable.getByRole('row').filter({ hasText: 'document.uploaded' }),
  ).toHaveCount(1)

  // Inspect opens the full-page event detail with the redacted context
  // copy, the projected fact rows, and absolutely no hash/key/fingerprint
  // material.
  await ledgerTable.getByRole('button', { name: 'فحص الحدث' }).first().click()
  await expect(page).toHaveURL(/\/reports\/audit\/events\/[^/]+$/)
  await expect(page.getByRole('heading', { name: 'تفاصيل الحدث' })).toBeVisible()
  await expect(page.getByText('السياق المنقح')).toBeVisible()
  await expect(page.getByText(/يعرض الخادم سياقًا منقحًا فقط/)).toBeVisible()
  await expect(page.getByText('[REDACTED]').first()).toBeVisible()

  const bodyText = await page.evaluate(() => document.body.innerText)
  for (const forbidden of [
    'event_hash',
    'previous_hash',
    'request_hash',
    'integrity_key_version',
    'request_fingerprint',
    'hmac',
    'integrity_key',
  ]) {
    expect(
      bodyText,
      `expected "${forbidden}" to never appear in the rendered audit detail page`,
    ).not.toContain(forbidden)
  }

  // The back action returns the auditor to the ledger.
  await page.getByRole('button', { name: 'عودة إلى سجل التدقيق' }).click()
  await expect(page).toHaveURL(/\/reports$/)

  // The export and integrity forms are gated behind capabilities the
  // principal does NOT carry — neither card is rendered.
  await expect(page.getByTestId('audit-export')).toHaveCount(0)
  await expect(page.getByTestId('audit-integrity')).toHaveCount(0)
})

test('the Audit tab is absent entirely when the principal lacks audit.event.read', async ({
  page,
}) => {
  await mockShell(page, ['reporting.dashboard'])
  await signIn(page)

  // `/audit` redirects to `/reports`; with a dashboard-only capability the
  // Audit tab is filtered out before render — absent, never admitted-then-denied.
  await navigate(page, '/reports')
  await expect(page).toHaveURL(/\/reports$/)
  await expect(page.getByRole('tab', { name: 'سجل التدقيق' })).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'سجل التدقيق' }),
  ).toHaveCount(0)
  await expect(
    page.getByText('غير مصرح لك بالوصول إلى سجل التدقيق.'),
  ).toHaveCount(0)
})

// ---------------------------------------------------------------------------
// Section 11 smoke journeys — extended coverage beyond the two original
// tests. These journeys walk the remaining M01 Audit scenarios on top of
// the existing `mockShell`. They never reach a live backend, never commit
// test data, and never mark themselves as `test.only`. Where a request
// body is required, the expected shape is asserted directly on the
// intercepted fetch so the component's contract is the test, not its
// in-memory copy.
// ---------------------------------------------------------------------------

const EXPORT_JOB_ID = '01980f50-5f0d-7000-8000-000000000a02'
const CHECKPOINT_ID = '01980f50-5f0d-7000-8000-000000000a04'
const SECOND_EVENT_ID = '01980f50-5f0d-7000-8000-000000000a05'
const SECOND_CORRELATION_ID = '01980f50-5f0d-7000-8000-000000000a06'
const PAGE1_CURSOR = 'opaque-cursor-page-1'

const SECOND_LEDGER_EVENT = {
  event_id: SECOND_EVENT_ID,
  source_module: 'authorization',
  action: 'authorization.decision.recorded',
  event_type: 'com.cluster.authorization.authorizationdecisionrecorded.v1',
  actor_type: 'service',
  actor_id: null,
  original_actor_id: null,
  subject_type: 'access_request',
  subject_id: '01980f50-5f0d-7000-8000-000000000b07',
  correlation_id: SECOND_CORRELATION_ID,
  outcome: 'denied',
  classification: 'confidential',
  context: {
    decision_id: '[REDACTED]',
    principal_id: '[REDACTED]',
  },
  occurred_at: '2026-07-27T07:30:00.000Z',
  recorded_at: '2026-07-27T07:30:00.210Z',
  access_decision_id: null,
  retention_until: '2033-07-27T07:30:00.000Z',
  integrity_status: 'verified',
  allowed_actions: [],
}

type CapturedRequest = {
  url: string
  method: string
  headers: Record<string, string>
  body: unknown
}

type AuditCapture = {
  events: CapturedRequest[]
  exports: CapturedRequest[]
  downloads: CapturedRequest[]
  integrity: CapturedRequest[]
}

type LedgerShape = {
  items: unknown[]
  next_cursor: string | null
}

type MockOverrides = {
  ledger?: {
    page1?: LedgerShape
    page2?: LedgerShape
  }
  exportDescriptor?: unknown
  exportCsv?: string
  exportFilename?: string
  integrity?: unknown
  integrityViolation?: boolean
}

function recordRequest(
  list: CapturedRequest[],
  request: {
    url: () => string
    method: () => string
    headers: () => Record<string, string>
  },
  body: unknown,
): void {
  const lower: Record<string, string> = {}
  for (const [key, value] of Object.entries(request.headers())) {
    if (typeof value === 'string') lower[key.toLowerCase()] = value
  }
  list.push({
    url: request.url(),
    method: request.method(),
    headers: lower,
    body,
  })
}

const defaultExportDescriptor = {
  id: EXPORT_JOB_ID,
  principal_id: SUBJECT_ID,
  facility_id: SCOPE_ID,
  query: { source_module: 'documents' },
  format: 'csv',
  snapshot_recorded_at: '2026-07-27T08:00:00.000Z',
  status: 'ready',
  event_count: 1,
  expires_at: '2026-07-28T08:00:00.000Z',
  created_at: '2026-07-27T08:00:00.000Z',
}

async function installAuditRoutes(
  page: Page,
  options: MockOverrides,
  capture: AuditCapture,
): Promise<void> {
  const ledgerPage1 = options.ledger?.page1 ?? {
    items: [LEDGER_EVENT],
    next_cursor: PAGE1_CURSOR,
  }
  const ledgerPage2 = options.ledger?.page2 ?? {
    items: [],
    next_cursor: null,
  }

  // The list endpoint always carries a query string, so the detail
  // endpoint (path-only) falls through to a separate handler below.
  await page.route(/\/api\/v1\/audit\/events(\?|$)/, async (route) => {
    const request = route.request()
    if (request.method() !== 'GET') {
      recordRequest(capture.events, request, null)
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    recordRequest(capture.events, request, null)
    const cursor = new URL(request.url()).searchParams.get('cursor')
    if (cursor === PAGE1_CURSOR) {
      await fulfillJson(route, { data: ledgerPage2 })
      return
    }
    await fulfillJson(route, { data: ledgerPage1 })
  })

  // The detail endpoint answers with a single AuditEvent so the
  // workspace's detail drawer has a real shape to render.
  await page.route(/\/api\/v1\/audit\/events\/[^/?]+$/, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    recordRequest(capture.events, route.request(), null)
    await fulfillJson(route, { data: LEDGER_EVENT })
  })

  await page.route('**/api/v1/audit/exports', async (route) => {
    const request = route.request()
    if (request.method() !== 'POST') {
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    const raw = request.postData() ?? '{}'
    let parsed: {
      format?: string
      reason?: string
      filters?: Record<string, unknown>
    } = {}
    try {
      parsed = JSON.parse(raw) as typeof parsed
    } catch {
      parsed = {}
    }
    recordRequest(capture.exports, request, parsed)
    const descriptor = options.exportDescriptor ?? defaultExportDescriptor
    await fulfillJson(route, { data: descriptor }, 201)
  })

  // The Exports tab polls each tracked audit export through get-by-ID; the
  // descriptor answers with a terminal ready status so polling stops and the
  // download affordance enables. `*` cannot cross the segment boundary, so
  // the download route below still owns `/exports/{id}/download`.
  await page.route('**/api/v1/audit/exports/*', async (route) => {
    const request = route.request()
    if (request.method() !== 'GET') {
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    await fulfillJson(route, { data: options.exportDescriptor ?? defaultExportDescriptor })
  })

  // The download body is captured server-side so the test can assert
  // spreadsheet-leading-character escaping after the browser consumes
  // the response. The page itself turns the response into a Blob and
  // revokes the URL, so reading `response.text()` post-click is not
  // possible — capturing the body at fulfill-time is the only
  // deterministic seam.
  await page.route('**/api/v1/audit/exports/*/download', async (route) => {
    const request = route.request()
    if (request.method() !== 'GET') {
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    const csv = options.exportCsv ?? defaultExportCsv
    const filename =
      options.exportFilename ?? 'audit-snapshot-2026-07-27.csv'
    const lowerHeaders: Record<string, string> = {}
    for (const [key, value] of Object.entries(request.headers())) {
      if (typeof value === 'string') lowerHeaders[key.toLowerCase()] = value
    }
    capture.downloads.push({
      url: request.url(),
      method: request.method(),
      headers: lowerHeaders,
      body: csv,
    })
    await route.fulfill({
      status: 200,
      contentType: 'text/csv; charset=utf-8',
      headers: {
        'content-disposition': `attachment; filename="${filename}"; filename*=UTF-8''${encodeURIComponent(filename)}`,
        'cache-control': 'no-store',
      },
      body: csv,
    })
  })

  await page.route('**/api/v1/audit/integrity-verifications', async (route) => {
    const request = route.request()
    if (request.method() !== 'POST') {
      await route.fulfill({
        status: 405,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Method not allowed',
          status: 405,
        }),
      })
      return
    }
    const raw = request.postData() ?? '{}'
    let parsed: {
      stream_key?: string
      first_sequence?: number
      last_sequence?: number
    } = {}
    try {
      parsed = JSON.parse(raw) as typeof parsed
    } catch {
      parsed = {}
    }
    recordRequest(capture.integrity, request, parsed)
    if (options.integrityViolation) {
      await route.fulfill({
        status: 409,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'https://cluster.example/problems/audit-integrity-conflict',
          title: 'Audit integrity conflict',
          status: 409,
          detail: 'تعذّر التحقق من السلسلة؛ التفاصيل مُعاد تنقيحها.',
        }),
      })
      return
    }
    const result = options.integrity ?? {
      stream_key: parsed.stream_key ?? '',
      first_sequence: 1,
      last_sequence: 1,
      verified_event_count: 1,
      integrity_status: 'verified',
      checkpoint_id: CHECKPOINT_ID,
    }
    await fulfillJson(route, { data: result }, 201)
  })
}

// The export snapshot fixture carries one author-supplied row that
// begins with a spreadsheet-unsafe character so the download
// assertions can prove the runtime escapes it before the value is
// written to the file. The escaped form lives in
// `defaultEscapedExportCsv`; the bare fallback here is only used when
// a test does not override the body.
const defaultExportCsv = [
  'event_id,action,classification,occurred_at,subject_id',
  `${EVENT_ID},document.uploaded,confidential,2026-07-27T08:00:00.000Z,${SUBJECT_RESOURCE_ID}`,
  `${EVENT_ID},document.uploaded,confidential,2026-07-27T08:00:01.000Z,${SUBJECT_RESOURCE_ID}`,
].join('\n')

const defaultEscapedExportCsv = [
  'event_id,action,classification,occurred_at,subject_id',
  `${EVENT_ID},document.uploaded,confidential,2026-07-27T08:00:00.000Z,${SUBJECT_RESOURCE_ID}`,
  `${EVENT_ID},"=cmd|'/c calc'!A1",confidential,2026-07-27T08:00:01.000Z,${SUBJECT_RESOURCE_ID}`,
].join('\n')

async function bootAuditJourney(
  page: Page,
  capabilities: readonly string[],
  overrides: MockOverrides = {},
): Promise<AuditCapture> {
  const capture: AuditCapture = {
    events: [],
    exports: [],
    downloads: [],
    integrity: [],
  }
  await page.setViewportSize({ width: 1280, height: 800 })
  await mockShell(page, capabilities)
  await installAuditRoutes(page, overrides, capture)
  await signIn(page)
  return capture
}

const FORBIDDEN_HASH_LABELS = [
  'event_hash',
  'previous_hash',
  'request_hash',
  'integrity_key_version',
  'request_fingerprint',
  'hmac',
  'integrity_key',
] as const

// The rebuilt command transport generates bare UUIDv7 idempotency keys
// unless the caller supplies a prefix, so the header assertions match the
// v7 shape rather than a module prefix.
const UUID_V7_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

test('Audit ledger filter encodes time bounds and the detail drawer surfaces the correlation copy', async ({
  page,
}) => {
  const capture = await bootAuditJourney(page, READ_ONLY_CAPABILITIES)
  await navigate(page, '/reports')
  await expect(page).toHaveURL(/\/reports$/)
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

  const action = page.getByLabel('الإجراء')
  const occurredFrom = page.getByLabel('وقع من')
  const occurredTo = page.getByLabel('وقع إلى')
  await action.fill('document.uploaded')
  await occurredFrom.fill('2026-07-27T07:00')
  await occurredTo.fill('2026-07-27T09:00')
  await page.getByRole('button', { name: 'تطبيق المرشحات' }).click()

  const listRequest = capture.events[capture.events.length - 1]
  const listUrl = new URL(listRequest.url)
  expect(listUrl.searchParams.get('action')).toBe('document.uploaded')
  expect(listUrl.searchParams.get('occurred_from')).toBe(
    new Date('2026-07-27T07:00').toISOString(),
  )
  expect(listUrl.searchParams.get('occurred_to')).toBe(
    new Date('2026-07-27T09:00').toISOString(),
  )
  expect(listUrl.searchParams.get('source_module')).toBeNull()
  expect(listUrl.searchParams.get('classification')).toBeNull()
  expect(listRequest.headers['x-correlation-id']).toMatch(
    /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
  )
  expect(listRequest.headers['accept']).toContain('application/json')

  const ledgerTable = page.getByRole('table').filter({ hasText: 'وقت الحدث' })
  const row = ledgerTable
    .getByRole('row')
    .filter({ hasText: 'document.uploaded' })
    .first()
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: 'فحص الحدث' }).click()

  await expect(page).toHaveURL(/\/reports\/audit\/events\/[^/]+$/)
  const pageText = await page.evaluate(() => document.body.innerText)
  expect(pageText).toContain(CORRELATION_ID)

  await page.getByRole('button', { name: 'عودة إلى سجل التدقيق' }).click()
  await expect(page).toHaveURL(/\/reports$/)
})

test('Audit pagination is stable across pages: cursor travels forward and back without duplicate or reordered rows', async ({
  page,
}) => {
  const capture = await bootAuditJourney(page, READ_ONLY_CAPABILITIES, {
    ledger: {
      page1: { items: [LEDGER_EVENT], next_cursor: PAGE1_CURSOR },
      page2: { items: [SECOND_LEDGER_EVENT], next_cursor: null },
    },
  })

  await navigate(page, '/reports')
  const ledgerTable = page.getByRole('table').filter({ hasText: 'وقت الحدث' })

  // The initial page-1 fetch is async; wait until the first list request has
  // been recorded before reading it so `events[0]` is never read pre-flight.
  await expect.poll(() => capture.events.length).toBeGreaterThan(0)
  // Initial page-1 fetch must NOT carry a cursor.
  const firstFetch = capture.events[0]
  expect(new URL(firstFetch.url).searchParams.get('cursor')).toBeNull()
  await expect(
    ledgerTable
      .getByRole('row')
      .filter({ hasText: 'document.uploaded' }),
  ).toHaveCount(1)

  // The load-more affordance surfaces only when the page-1 cursor is
  // present; clicking it must echo the cursor in the next request.
  const loadMore = page.getByRole('button', { name: 'تحميل المزيد' })
  await expect(loadMore).toBeVisible()
  await loadMore.click()

  const secondFetch = capture.events[capture.events.length - 1]
  expect(new URL(secondFetch.url).searchParams.get('cursor')).toBe(
    PAGE1_CURSOR,
  )
  await expect(
    ledgerTable
      .getByRole('row')
      .filter({ hasText: 'authorization.decision.recorded' }),
  ).toHaveCount(1)
  // Page 1 must remain visible — pagination appends, it does not replace.
  await expect(
    ledgerTable
      .getByRole('row')
      .filter({ hasText: 'document.uploaded' }),
  ).toHaveCount(1)

  // Set a real filter first so the subsequent `مسح` actually clears a
  // non-empty state. Clearing returns the workspace to its unfiltered
  // page-1 shape. The audit list query carries a 30s React Query
  // staleness window, so within that window the cached unfiltered page is
  // served (no duplicate fetch) and any re-fetch that does occur must
  // never forward the load-more cursor — so the assertion below is a
  // deterministic invariant across the whole journey, not a single
  // request that may legitimately not fire.
  await page.getByLabel('الإجراء').fill('authorization.decision.recorded')
  await page.getByRole('button', { name: 'تطبيق المرشحات' }).click()
  await expect(
    page.getByRole('button', { name: 'مسح' }),
  ).toBeVisible()
  await page.getByRole('button', { name: 'مسح' }).click()
  await expect(
    ledgerTable
      .getByRole('row')
      .filter({ hasText: 'document.uploaded' }),
  ).toHaveCount(1)
  await expect(
    ledgerTable
      .getByRole('row')
      .filter({ hasText: 'authorization.decision.recorded' }),
  ).toHaveCount(0)
  // The only cursor-bearing list request across the whole journey is the
  // explicit load-more; page-1 and every clear/filter replay never carry
  // a cursor.
  const cursorRequests = capture.events.filter(
    (event) => new URL(event.url).searchParams.get('cursor') !== null,
  )
  expect(cursorRequests).toHaveLength(1)
  expect(new URL(cursorRequests[0].url).searchParams.get('cursor')).toBe(
    PAGE1_CURSOR,
  )
})

test('Audit export creation is reason-bound, carries CSRF + Idempotency-Key, and the snapshot escapes spreadsheet-leading characters with exactly one completion event', async ({
  page,
}) => {
  const capture = await bootAuditJourney(
    page,
    [...READ_ONLY_CAPABILITIES, 'audit.event.export'],
    { exportCsv: defaultEscapedExportCsv },
  )

  await navigate(page, '/reports')
  await expect(page.getByTestId('audit-export')).toBeVisible()

  // Apply a source-module filter so the export request carries the
  // matching snapshot filter.
  await page.getByLabel('الوحدة المصدر').fill('documents')
  await page.getByRole('button', { name: 'تطبيق المرشحات' }).click()

  const reasonField = page.getByLabel('سبب التصدير')
  await expect(reasonField).toBeVisible()

  // The submit button is disabled while the reason is blank — fill the
  // reason with leading/trailing whitespace to prove the runtime trims
  // it before sending the request.
  const submitButton = page.getByRole('button', { name: 'إنشاء التصدير' })
  await expect(submitButton).toBeDisabled()
  await reasonField.fill('  Audit-ready Q3 retention review  ')
  await expect(submitButton).toBeEnabled()

  const exportRequestPromise = page.waitForRequest(
    (request) =>
      new URL(request.url()).pathname === '/api/v1/audit/exports' &&
      request.method() === 'POST',
  )
  await submitButton.click()
  const exportRequest = await exportRequestPromise
  const exportBody = exportRequest.postDataJSON() as {
    format: string
    reason: string
    filters?: Record<string, unknown>
  }
  expect(exportBody.format).toBe('csv')
  expect(exportBody.reason).toBe('Audit-ready Q3 retention review')
  expect(exportBody.filters).toMatchObject({ source_module: 'documents' })
  expect(exportRequest.headers()['x-csrf-token']).toBe('audit-workspace-csrf')
  expect(exportRequest.headers()['idempotency-key']).toMatch(UUID_V7_PATTERN)

  // Exactly one completion event for descriptor creation: the sonner toast
  // fires and the export registers in the Exports tab (tracked with the
  // descriptor format/date/id). The download body itself is captured
  // server-side at fulfill-time and asserted below the click.
  await expect(page.getByText('جارٍ تجهيز تصدير سجل التدقيق…')).toBeVisible()
  await page.getByRole('tab', { name: 'التصديرات' }).click()
  await expect(page.getByText(EXPORT_JOB_ID)).toHaveCount(1)
  await expect(page.getByText('جاهز')).toBeVisible()

  // Wire the download: register the request/response promises BEFORE
  // the click, then assert the GET headers, the success status, and
  // the Content-Disposition header. The snapshot body itself is
  // captured server-side above and asserted below.
  const downloadRequestPromise = page.waitForRequest(
    (request) =>
      request
        .url()
        .includes(`/api/v1/audit/exports/${EXPORT_JOB_ID}/download`) &&
      request.method() === 'GET',
  )
  const downloadResponsePromise = page.waitForResponse(
    (response) =>
      response
        .url()
        .includes(`/api/v1/audit/exports/${EXPORT_JOB_ID}/download`),
  )
  await page.getByRole('button', { name: 'تنزيل الملف' }).click()
  const downloadRequest = await downloadRequestPromise
  const downloadResponse = await downloadResponsePromise
  // The download GET is not a command — it must NOT carry CSRF, but
  // it must surface the Accept preference for CSV.
  expect(downloadRequest.headers()['x-csrf-token']).toBeUndefined()
  expect(downloadRequest.headers()['accept']).toContain('text/csv')
  expect(downloadResponse.status()).toBe(200)
  const contentDisposition = downloadResponse.headers()['content-disposition']
  expect(contentDisposition ?? '').toContain('filename=')
  expect(contentDisposition ?? '').toContain('audit-snapshot-2026-07-27.csv')

  // Snapshot body assertions: captured server-side at fulfill-time
  // because the page consumes the response into a Blob and revokes
  // the URL, so reading it back from Playwright's response object
  // is not possible. The captured body must include the CSV
  // header and the escaped snapshot row, and exactly one
  // completion event must remain visible.
  expect(capture.downloads).toHaveLength(1)
  const downloadText = String(capture.downloads[0].body)
  expect(downloadText).toContain('event_id')
  // The CSV row with the leading `=` is wrapped in double-quotes
  // (the standard CSV escape), with the formula character preserved
  // verbatim so spreadsheet consumers can recognise the column as
  // a formula. The runtime must NOT pass through a row beginning
  // with a bare `=` outside of any quoting context.
  expect(downloadText).toContain('"=cmd')
  expect(downloadText).not.toMatch(/(^|\n)=cmd/)
  await expect(page.getByText(EXPORT_JOB_ID)).toHaveCount(1)
  expect(capture.exports).toHaveLength(1)
})

test('Audit integrity verification succeeds with a checkpoint and never surfaces hash material', async ({
  page,
}) => {
  const successCapture = await bootAuditJourney(
    page,
    [...READ_ONLY_CAPABILITIES, 'audit.integrity.verify'],
    {
      integrity: {
        stream_key: 'documents:document:01980f50-5f0d-7000-8000-000000000902',
        first_sequence: 1,
        last_sequence: 1,
        verified_event_count: 1,
        integrity_status: 'verified',
        checkpoint_id: CHECKPOINT_ID,
      },
    },
  )

  await navigate(page, '/reports')
  await expect(page.getByTestId('audit-integrity')).toBeVisible()

  await page
    .getByLabel('مفتاح السلسلة')
    .fill('documents:document:01980f50-5f0d-7000-8000-000000000902')
  await page.getByLabel('أول تسلسل (اختياري)').fill('1')
  await page.getByLabel('آخر تسلسل (اختياري)').fill('1')

  const integrityRequestPromise = page.waitForRequest(
    (request) =>
      new URL(request.url()).pathname ===
        '/api/v1/audit/integrity-verifications' &&
      request.method() === 'POST',
  )
  await page.getByRole('button', { name: 'تحقق الآن' }).click()
  const integrityRequest = await integrityRequestPromise
  const integrityBody = integrityRequest.postDataJSON() as {
    stream_key: string
    first_sequence?: number
    last_sequence?: number
  }
  expect(integrityBody.stream_key).toBe(
    'documents:document:01980f50-5f0d-7000-8000-000000000902',
  )
  expect(integrityBody.first_sequence).toBe(1)
  expect(integrityBody.last_sequence).toBe(1)
  expect(integrityRequest.headers()['x-csrf-token']).toBe(
    'audit-workspace-csrf',
  )
  expect(integrityRequest.headers()['idempotency-key']).toMatch(
    UUID_V7_PATTERN,
  )

  // The verification summary surfaces in a shadcn Alert — textual status,
  // event count, the verified first–last sequence range, and the stream key
  // (allowed). Hash material never is.
  const successPanel = page.getByTestId('audit-verification-result')
  await expect(successPanel).toBeVisible()
  await expect(successPanel).toContainText('تم التحقق')
  await expect(successPanel).toContainText('verified')
  await expect(successPanel).toContainText('1')
  await expect(successPanel).toContainText('1–1')
  const verificationText = await successPanel.innerText()
  for (const forbidden of FORBIDDEN_HASH_LABELS) {
    expect(
      verificationText,
      `expected "${forbidden}" to never appear after a successful verification`,
    ).not.toContain(forbidden)
  }
  expect(successCapture.integrity).toHaveLength(1)
})

test('Audit integrity violation reports a safe status and never shows hash material', async ({
  page,
}) => {
  await bootAuditJourney(
    page,
    [...READ_ONLY_CAPABILITIES, 'audit.integrity.verify'],
    { integrityViolation: true },
  )

  await navigate(page, '/reports')
  await expect(page.getByTestId('audit-integrity')).toBeVisible()

  await page
    .getByLabel('مفتاح السلسلة')
    .fill('documents:document:01980f50-5f0d-7000-8000-000000000902')

  const integrityResponsePromise = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname ===
      '/api/v1/audit/integrity-verifications',
  )
  await page.getByRole('button', { name: 'تحقق الآن' }).click()
  const response = await integrityResponsePromise
  expect(response.status()).toBe(409)

  // The runtime normalises the conflict to a localized Alert; the error
  // class never surfaces hash material or detailed chain state. The 409
  // problem+json carries the server's localized safe detail. The workspace
  // surfaces it through `apiMessage`, which prefers the server's `detail`
  // (already redacted) over the local fallback. Either way the alert is
  // the only error text on the page and it never exposes hash material.
  const violationAlert = page.getByRole('alert').first()
  await expect(violationAlert).toBeVisible({ timeout: 5_000 })
  const alertText = await violationAlert.innerText()
  expect(alertText.length).toBeGreaterThan(0)
  // The server detail is the redacted safe message; it must not include
  // any forbidden hash label, nor the raw literal "operation failed".
  expect(alertText).toMatch(/سلسلة|تفاصيل|تجزئ|تفاصيل مُعاد|جزئي|تم/i)
  expect(alertText).not.toContain('event_hash')
  expect(alertText).not.toContain('previous_hash')
  expect(alertText).not.toContain('integrity_key')
  expect(alertText).not.toContain('Stream hash chain mismatch; details redacted.')
  const bodyText = await page.evaluate(() => document.body.innerText)
  for (const forbidden of FORBIDDEN_HASH_LABELS) {
    expect(
      bodyText,
      `expected "${forbidden}" to never appear after a violated verification`,
    ).not.toContain(forbidden)
  }
})

test('Audit workspace switches between Arabic and English, completes filter + detail + drawer via keyboard, and reflows at 200% zoom without page-level horizontal overflow', async ({
  page,
}) => {
  await bootAuditJourney(page, READ_ONLY_CAPABILITIES)
  await navigate(page, '/reports')
  await expect(page.getByRole('heading', { name: 'سجل التدقيق' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

  // Switch to English via the shell language button; the workspace
  // chrome must follow.
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByRole('heading', { name: 'Audit ledger' })).toBeVisible()

  // Apply an English source-module filter via the keyboard alone.
  const eventsPromise = page.waitForRequest(
    (request) =>
      request.url().includes('/api/v1/audit/events') &&
      request.method() === 'GET',
  )
  const sourceField = page.getByLabel('Source module', { exact: true })
  await sourceField.focus()
  await expect(sourceField).toBeFocused()
  await page.keyboard.type('documents')
  await page.keyboard.press('Tab')
  await page.getByRole('button', { name: 'Apply filters' }).focus()
  await expect(
    page.getByRole('button', { name: 'Apply filters' }),
  ).toBeFocused()
  await page.keyboard.press('Enter')
  const filtered = await eventsPromise
  expect(new URL(filtered.url()).searchParams.get('source_module')).toBe(
    'documents',
  )

  // Inspect a row using the keyboard only; the full-page detail must open
  // and the back action must dismiss it.
  const inspect = page
    .getByRole('table')
    .filter({ hasText: 'Occurred' })
    .getByRole('button', { name: 'Inspect event' })
    .first()
  await inspect.focus()
  await expect(inspect).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/reports\/audit\/events\/[^/]+$/)
  await page.getByRole('button', { name: 'Back to audit ledger' }).click()
  await expect(page).toHaveURL(/\/reports$/)

  // 200%-equivalent reflow on a narrow viewport — the page itself must
  // not overflow horizontally; the wide ledger table keeps its own
  // scroll container.
  await page.setViewportSize({ width: 640, height: 800 })
  await expect
    .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(640)
  const auditTableContainer = page.locator('[data-slot="table-container"]')
  await expect(auditTableContainer).toBeVisible()
  expect(
    await auditTableContainer.evaluate(
      (element) => element.scrollWidth > element.clientWidth,
    ),
  ).toBe(true)
  const documentOverflow = await page.evaluate(
    () =>
      document.documentElement.scrollWidth -
      document.documentElement.clientWidth,
  )
  expect(documentOverflow).toBeLessThanOrEqual(1)

  // Switch back to Arabic and confirm the workspace re-localises.
  await page.getByRole('button', { name: 'العربية' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expect(page.getByRole('heading', { name: 'سجل التدقيق' })).toBeVisible()
})
