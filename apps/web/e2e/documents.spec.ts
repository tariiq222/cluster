import { expect, test, type Page } from '@playwright/test'

type JsonBody = Record<string, unknown>

const TEST_USER_ID = '01980f50-5f0d-7000-8000-000000000001'
const TEST_FACILITY_ID = '01980f50-5f0d-7000-8000-000000000002'

type DocumentRecord = JsonBody & {
  id: string
  title: string
  status: string
  lifecycle_state: string
  classification: string
  owner_organization_unit_id: string
  description: string
  allowed_actions: string[]
  lock_version: number
  current_version_id?: string
  versions: { id: string; version_number: number; file_name: string; availability_status?: string }[]
  links: Array<JsonBody>
}

function uuidV7() {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  let time = Date.now()
  for (let i = 5; i >= 0; i -= 1) { bytes[i] = time & 0xff; time = Math.floor(time / 256) }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

function createCollection<T>(items: T[]) {
  return { items, next_cursor: null, total: items.length }
}

type JourneyState = {
  csrfToken: string
  documents: Map<string, DocumentRecord>
  uploads: Map<string, { documentId: string; versionId: string }>
  currentDocumentId: string
}

async function login(page: Page) {
  let authenticated = false
  await page.route('**/api/v1/identity/login', (route) => {
    authenticated = true
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: {
        'set-cookie': 'cluster_identity_session=e2e-session; Path=/; HttpOnly; SameSite=Lax',
        'x-csrf-token': 'e2e-csrf-token',
      },
      body: JSON.stringify({
        data: {
          user_id: TEST_USER_ID,
          expires_at: '2027-07-22T10:00:00Z',
          restricted: false,
          csrf_token: 'e2e-csrf-token',
        },
      }),
    })
  })
  // /api/v1/identity/me is ONLY the cookie-session restore probe; it 401s
  // until the login handshake completes. The principal snapshot
  // (capabilities/features) is served by the separate GET /api/v1/me route
  // registered below — never by this session envelope.
  await page.route('**/api/v1/identity/me', (route) => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: TEST_USER_ID,
            csrf_token: 'e2e-csrf-token',
            capabilities: ['documents.read', 'documents.manage'],
            features: { tasks: false },
          },
        }),
      }
    : {
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }),
      }))
  // GET /api/v1/me is the contracted principal projection consumed by
  // `PrincipalProvider`: the root schema carries the capability codes and
  // feature flags the shell renders. Fixture ids are the suite's existing
  // valid UUID constants and the capabilities mirror the session envelope.
  await page.route('**/api/v1/me', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      subject_id: TEST_USER_ID,
      tenant_id: TEST_FACILITY_ID,
      organization_unit_ids: [TEST_FACILITY_ID],
      roles: ['document-manager'],
      capabilities: ['documents.read', 'documents.manage'],
      clearance: 'internal',
      break_glass: false,
      correlation_id: TEST_USER_ID,
      features: { tasks: false },
    }),
  }))
  await page.route('**/api/v1/identity/csrf', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: { csrf_token: 'e2e-csrf-token' } }),
  }))
  await page.route('**/api/v1/me/scopes', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    headers: { ETag: '"1"' },
    body: JSON.stringify({
      available_scopes: [{ scope_type: 'facility', scope_id: TEST_FACILITY_ID, label: 'منشأة الاختبار' }],
      effective_scope: { scope_type: 'facility', scope_id: TEST_FACILITY_ID, label: 'منشأة الاختبار' },
    }),
  }))
  await page.route('**/api/v1/notifications*', (route) => {
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ items: [], next_cursor: null }),
    })
  })
  await page.route('**/api/v1/dashboards*', (route) => {
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify(createCollection([])),
    })
  })

  await page.goto('/')
  await page.getByLabel('اسم المستخدم').fill('e2e-user')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('e2e-password')
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

  await page.unroute('**/api/v1/identity/login')
}

async function installDocumentRoutes(page: Page, state: JourneyState) {
  // Signed direct-upload flow: initiate (POST /documents/uploads for intake
  // or POST /documents/{id}/versions for existing docs), storage PUT,
  // scan status (GET /documents/uploads/{id}), completion
  // (POST /documents/uploads/{id}/complete).
  await page.route('**/api/v1/documents/uploads**', async (route) => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    const method = request.method().toUpperCase()
    const complete = pathname.match(/^\/api\/v1\/documents\/uploads\/([^/]+)\/complete$/)
    const statusMatch = pathname.match(/^\/api\/v1\/documents\/uploads\/([^/]+)$/)

    if (pathname === '/api/v1/documents/uploads' && method === 'POST') {
      const body = request.postDataJSON() as {
        file_name?: string
        content_type?: string
        name?: string
        description?: string | null
        classification?: string
      }
      const uploadId = uuidV7()
      // Intake path: when no currentDocumentId is set, the backend
      // creates a fresh draft document + first version atomically on
      // completion; we provision it here so the completion handler can
      // return a valid document_id. The journey-supplied title/
      // description/classification are honored, not a hard-coded stub.
      if (state.currentDocumentId === '') {
        const documentId = uuidV7()
        const created: DocumentRecord = {
          id: documentId,
          title: String(body.name ?? body.file_name ?? 'Untitled'),
          description: String(body.description ?? ''),
          owner_organization_unit_id: TEST_FACILITY_ID,
          lifecycle_state: 'draft',
          status: 'draft',
          classification: String(body.classification ?? 'internal'),
          allowed_actions: ['read', 'update', 'add-version', 'download', 'link', 'archive'],
          lock_version: 1,
          versions: [],
          links: [],
        }
        state.documents.set(documentId, created)
        state.currentDocumentId = documentId
      }
      state.uploads.set(uploadId, { documentId: state.currentDocumentId, versionId: `${uploadId}-version` })
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            upload_id: uploadId,
            quarantine_object_id: `${uploadId}-quarantine`,
            upload_url: `https://storage.local/documents/${uploadId}/versions/${uploadId}`,
            method: 'PUT',
            required_headers: { 'content-type': body.content_type ?? 'application/octet-stream' },
          },
        }),
      })
      return
    }

    if (statusMatch?.[1] && method === 'GET') {
      const upload = state.uploads.get(statusMatch[1])
      if (!upload) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { scan_status: 'clean', availability_status: 'available' } }),
      })
      return
    }

    if (complete?.[1] && method === 'POST') {
      const uploadId = complete[1]
      const upload = state.uploads.get(uploadId)
      if (!upload || upload.documentId === '') {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }
      const document = state.documents.get(upload.documentId)
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }
      const body = request.postDataJSON() as JsonBody
      const nextVersion = document.versions.length + 1
      document.versions.unshift({
        id: upload.versionId,
        file_name: 'digital-transformation.pdf',
        version_number: nextVersion,
        availability_status: 'available',
      })
      document.current_version_id = upload.versionId
      route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            accepted: true,
            document_id: upload.documentId,
            version_id: upload.versionId,
            scan_status: 'clean',
            availability_status: 'available',
            detected_mime_type: 'application/pdf',
            byte_size: Number(body?.byte_size ?? 0),
            sha256: String(body?.sha256 ?? '00'),
          },
        }),
      })
      return
    }

    route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
  })

  await page.route('https://storage.local/**', (route) => {
    if (route.request().method() === 'PUT') {
      route.fulfill({ status: 200 })
    }
    else {
      route.abort()
    }
  })

  await page.route('**/api/v1/documents**', async (route) => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    const method = request.method().toUpperCase()
    if (pathname.startsWith('/api/v1/documents/uploads') || pathname === '/api/v1/documents/uploads') {
      await route.fallback()
      return
    }
    const matchId = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})$/)
    const matchVersions = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/versions$/)
    const matchLinks = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/links$/)
    const matchTransition = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/(archive|place-hold|release-hold|unarchive)$/)

    if (pathname === '/api/v1/documents' && method === 'GET') {
      const params = new URL(request.url()).searchParams
      const classification = params.get('classification')
      const records = [...state.documents.values()].filter((document) => {
        if (!classification) return true
        return document.classification === classification
      })

      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(createCollection(records)),
      })
      return
    }

    if (pathname === '/api/v1/documents' && method === 'POST') {
      const body = request.postDataJSON() as JsonBody
      const id = uuidV7()
      const created: DocumentRecord = {
        id,
        title: String(body.title ?? 'Untitled'),
        description: String(body.description ?? ''),
        owner_organization_unit_id: String(body.owner_organization_unit_id ?? '018f6f7d-0c00-7000-8000-000000000003'),
        lifecycle_state: 'draft',
        status: 'draft',
        classification: String(body.classification ?? 'internal'),
        allowed_actions: ['read', 'update', 'add-version', 'download', 'link', 'archive'],
        lock_version: 1,
        versions: [],
        links: [],
      }
      state.documents.set(id, created)

      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ data: created }),
      })
      return
    }

    if (matchId?.[1]) {
      const documentId = matchId[1]
      const document = state.documents.get(documentId)
      if (!document) {
        route.fulfill({
          status: 404,
          contentType: 'application/json',
          body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }),
        })
        return
      }

      if (method === 'GET') {
        state.currentDocumentId = documentId
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: document }),
        })
        return
      }
    }

    if (matchVersions?.[1] && method === 'GET') {
      const document = state.documents.get(matchVersions[1])
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }

      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(createCollection(document.versions)) })
      return
    }

    if (matchVersions?.[1] && method === 'POST') {
      const documentId = matchVersions[1]
      const document = state.documents.get(documentId)
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }
      // Version-scoped initiate: the existing-version Sheet must use
      // POST /documents/{id}/versions, not the generic /documents/uploads.
      const body = request.postDataJSON() as { file_name?: string; content_type?: string }
      const uploadId = uuidV7()
      state.currentDocumentId = documentId
      state.uploads.set(uploadId, { documentId, versionId: `${uploadId}-version` })
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            upload_id: uploadId,
            quarantine_object_id: `${uploadId}-quarantine`,
            upload_url: `https://storage.local/documents/${uploadId}/versions/${uploadId}`,
            method: 'PUT',
            required_headers: { 'content-type': body.content_type ?? 'application/octet-stream' },
          },
        }),
      })
      return
    }

    if (matchLinks?.[1]) {
      const document = state.documents.get(matchLinks[1])
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }

      if (method === 'GET') {
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(createCollection(document.links)) })
        return
      }

      if (method === 'POST') {
        const body = request.postDataJSON() as JsonBody
        const source = (body.source ?? {}) as JsonBody
        document.links.unshift({
          id: uuidV7(),
          relation_type: String(body.relation_type ?? 'related'),
          source,
          record_type: source.record_type,
          record_id: source.record_id,
        })
        route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              id: document.id,
              lock_version: document.lock_version,
              ...body,
              status: 'linked',
            },
          }),
        })
        return
      }
    }

    if (matchTransition?.[1] && method === 'POST') {
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...state.documents.get(matchTransition[1]) } }) })
      return
    }

    route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
  })
}

test('documents workspace list/filter/create/version/link flow works with mocked identity', async ({ page }) => {
  const state: JourneyState = {
    csrfToken: 'e2e-csrf-token',
    documents: new Map([
      ['01980f50-5f0d-7000-8000-000000000101', {
        id: '01980f50-5f0d-7000-8000-000000000101',
        title: 'Protocol outline',
        description: 'سياسة داخلية',
        owner_organization_unit_id: '01980f50-5f0d-7000-8000-000000000003',
        classification: 'internal',
        lifecycle_state: 'active',
        status: 'active',
        allowed_actions: ['read', 'update', 'add-version', 'download', 'grant', 'link', 'archive'],
        lock_version: 1,
        current_version_id: '01980f50-5f0d-7000-8000-000000000301',
        versions: [{ id: '01980f50-5f0d-7000-8000-000000000301', version_number: 1, file_name: 'protocol.docx', availability_status: 'available' }],
        links: [{ id: '01980f50-5f0d-7000-8000-000000000401', relation_type: 'related', source: { source_module: 'tasks', record_type: 'task', record_id: '01980f50-5f0d-7000-8000-000000000099' } }],
      } as DocumentRecord],
      ['01980f50-5f0d-7000-8000-000000000102', {
        id: '01980f50-5f0d-7000-8000-000000000102',
        title: 'Public summary',
        description: 'مستند عامًا',
        owner_organization_unit_id: '01980f50-5f0d-7000-8000-000000000003',
        classification: 'public',
        lifecycle_state: 'active',
        status: 'active',
        allowed_actions: ['read', 'link'],
        lock_version: 1,
        current_version_id: '01980f50-5f0d-7000-8000-000000000302',
        versions: [{ id: '01980f50-5f0d-7000-8000-000000000302', version_number: 1, file_name: 'summary.pdf', availability_status: 'available' }],
        links: [],
      } as DocumentRecord],
    ]),
    uploads: new Map(),
    currentDocumentId: '',
  }

  await login(page)
  await installDocumentRoutes(page, state)

  await page.goto('/documents')
  await expect(page).toHaveURL('/documents')
  await expect(page.getByRole('heading', { name: 'المستندات' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Public summary' })).toBeVisible()

  // The classification control is a labelled shadcn Select; choosing `داخلي`
  // must emit the filtered list request and keep only the internal document.
  const filtered = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/documents' && url.searchParams.get('classification') === 'internal'
  })
  await page.getByLabel('التصنيف').click()
  await page.getByRole('option', { name: 'داخلي' }).click()
  await filtered
  await expect(page.getByRole('cell', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Public summary' })).toHaveCount(0)

  // Create a document through the `أنشئ مستنداً` full-page route. The header
  // action must navigate to `/documents/new`, render a real page (no dialog
  // or Sheet semantics), submit through the secure initiate → storage PUT
  // → status → complete chain, and navigate to the new document's detail.
  await page.getByRole('button', { name: 'أنشئ مستنداً' }).click()
  await expect(page).toHaveURL('/documents/new')
  // No create dialog or Sheet must ever appear.
  await expect(page.getByRole('heading', { level: 1, name: 'أنشئ مستنداً جديداً' })).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'أنشئ مستنداً' })).toHaveCount(0)
  const createForm = page.getByTestId('document-create-form')
  await expect(createForm).toBeVisible()

  // DOC-LAYOUT-05: responsive layout viewport evidence on the create page.
  // 1) Mobile single-column — 390×844 must have no horizontal overflow and
  //    the localized custom file-picker button must be reachable.
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(createForm).toBeVisible()
  await expect(page.getByTestId('document-create-file-button')).toBeVisible()
  await expect(page.getByTestId('document-create-file-input')).toBeAttached()
  const mobileOverflow = await page.evaluate(() => {
    const doc = document.documentElement
    const body = document.body
    return {
      docScroll: doc.scrollWidth,
      docClient: doc.clientWidth,
      bodyScroll: body.scrollWidth,
      bodyClient: body.clientWidth,
    }
  })
  expect(mobileOverflow.docScroll).toBeLessThanOrEqual(mobileOverflow.docClient + 1)
  expect(mobileOverflow.bodyScroll).toBeLessThanOrEqual(mobileOverflow.bodyClient + 1)
  // 2) Desktop two-region — 1440×900 must keep the review panel + primary
  //    action in the initial viewport (no scroll needed to submit).
  await page.setViewportSize({ width: 1440, height: 900 })
  await expect(createForm).toBeVisible()
  const reviewPanel = page.getByTestId('document-create-review-panel')
  await expect(reviewPanel).toBeVisible()
  const submit = page.getByTestId('document-create-submit')
  await expect(submit).toBeVisible()
  const viewportEvidence = await page.evaluate(() => {
    const submitEl = document.querySelector('[data-testid="document-create-submit"]')
    const reviewEl = document.querySelector('[data-testid="document-create-review-panel"]')
    const inViewport = (el: Element | null) => {
      if (!el) return false
      const rect = el.getBoundingClientRect()
      return rect.top >= 0
        && rect.left >= 0
        && rect.bottom <= window.innerHeight
        && rect.right <= window.innerWidth
    }
    return { submitIn: inViewport(submitEl), reviewIn: inViewport(reviewEl) }
  })
  expect(viewportEvidence.submitIn).toBe(true)
  expect(viewportEvidence.reviewIn).toBe(true)
  // Restore the default project viewport for the rest of the journey.
  await page.setViewportSize({ width: 1280, height: 720 })

  await createForm.getByTestId('document-create-title-input').fill('إطار التحول الرقمي')
  await createForm.getByTestId('document-create-description-input').fill('وصف اختباري للمستند الجديد')
  await createForm.getByTestId('document-create-file-input').setInputFiles({
    name: 'framework.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('intake document body'),
  })
  // The atomic intake initiates POST /documents/uploads (no document_id),
  // uploads bytes to the signed URL with the required headers, and POSTs
  // /documents/uploads/{id}/complete whose data.document_id is the
  // navigation target.
  const initiate = page.waitForResponse((response) =>
    new URL(response.url()).pathname === '/api/v1/documents/uploads'
    && response.request().method() === 'POST',
  )
  const storagePut = page.waitForResponse((response) =>
    new URL(response.url()).origin === 'https://storage.local',
  )
  const uploadComplete = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.startsWith('/api/v1/documents/uploads/') && url.pathname.endsWith('/complete')
  })
  await createForm.getByTestId('document-create-submit').click()
  const initiateResponse = await initiate
  const initiateData = (await initiateResponse.json()) as { data: { upload_id: string } }
  await storagePut
  const completeResponse = await uploadComplete
  const completeData = (await completeResponse.json()) as { data: { document_id: string } }
  await expect(page).toHaveURL(`/documents/${completeData.data.document_id}`)
  await expect(page.getByRole('heading', { name: 'إطار التحول الرقمي' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'المعاينة' })).toBeVisible()
  await expect(page.getByRole('tabpanel', { name: 'المعاينة' })).toContainText('وصف اختباري للمستند الجديد')
  void initiateData

  // Upload a new version through the full-page `رفع إصدار جديد` editor:
  // choose the file through its `الملف` label, submit, and await the
  // version-scoped initiate at POST /documents/{id}/versions + completion,
  // then confirm the page returns to the document detail.
  await page.getByRole('button', { name: 'رفع إصدار جديد' }).click()
  await expect(page).toHaveURL(/\/documents\/[^/]+\/versions\/new$/)
  const versionAdded = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return /^\/api\/v1\/documents\/[^/]+\/versions$/.test(url.pathname)
      && response.request().method() === 'POST'
  })
  const versionUploadComplete = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.startsWith('/api/v1/documents/uploads/') && url.pathname.endsWith('/complete')
  })
  await page.getByLabel('الملف').setInputFiles({
    name: 'digital-transformation.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('document body'),
  })
  await page.getByRole('button', { name: 'رفع', exact: true }).click()
  await versionAdded
  await versionUploadComplete
  await expect(page).toHaveURL(/\/documents\/[^/]+$/)
  await page.getByRole('tab', { name: 'النسخ' }).click()
  await expect(page.getByRole('tabpanel', { name: 'النسخ' })).toContainText('digital-transformation.pdf')

  // Link the document to a source record through the `ربط مستند` dialog and
  // assert the relation plus the fixture record id in the links tab.
  await page.getByRole('button', { name: 'ربط مستند' }).click()
  const linkDialog = page.getByRole('dialog', { name: 'ربط سجل مصدر' })
  await expect(linkDialog).toBeVisible()
  const linked = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/links') && response.request().method() === 'POST'
  })
  await linkDialog.getByLabel('معرّف السجل المصدر').fill('01980f50-5f0d-7000-8000-000000000099')
  await linkDialog.getByRole('button', { name: 'تأكيد', exact: true }).click()
  await linked
  await page.getByRole('tab', { name: 'الروابط' }).click()
  const linksPanel = page.getByRole('tabpanel', { name: 'الروابط' })
  await expect(linksPanel).toContainText('attachment')
  await expect(linksPanel).toContainText('01980f50-5f0d-7000-8000-000000000099')
})
