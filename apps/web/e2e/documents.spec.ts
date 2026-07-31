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
  // Session restore + principal snapshot share /api/v1/identity/me on the
  // rebuilt frontend; the principal payload carries capabilities/features.
  await page.route('**/api/v1/identity/me', (route) => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: TEST_USER_ID,
            csrf_token: 'e2e-csrf-token',
            capabilities: ['documents.read', 'documents.manage'],
            features: { work_management: false, tasks: false },
          },
        }),
      }
    : {
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({ type: 'about:blank', title: 'Unauthorized', status: 401 }),
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
  // Signed direct-upload flow: initiate (POST /documents/uploads), storage
  // PUT, scan status (GET /documents/uploads/{id}), completion
  // (POST /documents/uploads/{id}/complete).
  await page.route('**/api/v1/documents/uploads**', async (route) => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    const method = request.method().toUpperCase()
    const complete = pathname.match(/^\/api\/v1\/documents\/uploads\/([^/]+)\/complete$/)
    const statusMatch = pathname.match(/^\/api\/v1\/documents\/uploads\/([^/]+)$/)

    if (pathname === '/api/v1/documents/uploads' && method === 'POST') {
      const body = request.postDataJSON() as { file_name?: string; content_type?: string }
      const uploadId = uuidV7()
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
        links: [{ id: '01980f50-5f0d-7000-8000-000000000401', relation_type: 'related', source: { source_module: 'work-record', record_type: 'record', record_id: '01980f50-5f0d-7000-8000-000000000099' } }],
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
  await expect(page.locator('#documents-heading')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Public summary' })).toBeVisible()

  const filtered = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/documents' && url.searchParams.get('classification') === 'internal'
  })
  await page.locator('#documents-classification-filter').selectOption('internal')
  await filtered
  await expect(page.getByRole('heading', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Public summary' })).toBeHidden()

  await page.getByLabel('العنوان').fill('إطار التحول الرقمي')
  const created = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/documents' && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'إنشاء', exact: true }).click()
  const createdResponse = await created
  const createdPayload = (await createdResponse.json()) as { data: DocumentRecord }
  await expect(page.getByRole('heading', { name: 'إطار التحول الرقمي' })).toBeVisible()

  // Open the freshly created document from the list.
  const row = page.locator('article', { has: page.getByRole('heading', { name: 'إطار التحول الرقمي' }) })
  await row.getByRole('button', { name: 'فتح المستند' }).click()
  await expect(page).toHaveURL(`/documents/${createdPayload.data.id}`)

  const detailHeading = page.locator('#document-detail-heading')
  await expect(detailHeading).toContainText('إطار التحول الرقمي')
  await expect(page.getByRole('heading', { name: 'بيانات المستند' })).toBeVisible()

  const versionAdded = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/documents/uploads' && response.request().method() === 'POST'
  })
  const uploadComplete = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.startsWith('/api/v1/documents/uploads/') && url.pathname.endsWith('/complete')
  })
  await page.locator('#document-upload-file').setInputFiles({
    name: 'digital-transformation.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('document body'),
  })
  await page.getByRole('button', { name: 'رفع', exact: true }).click()
  await versionAdded
  await uploadComplete
  await expect(page.getByText('تم رفع الإصدار بنجاح.')).toBeVisible()
  await expect(page.locator('#document-versions-panel')).toContainText('digital-transformation.pdf')

  const linked = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/links') && response.request().method() === 'POST'
  })
  await page.getByRole('button', { name: 'ربط مستند' }).click()
  const linkDrawer = page.getByRole('dialog', { name: 'ربط سجل مصدر' })
  await linkDrawer.getByLabel('معرّف السجل المصدر').fill('01980f50-5f0d-7000-8000-000000000099')
  await linkDrawer.getByRole('button', { name: 'تأكيد' }).click()
  await linked
  await expect(page.locator('#document-links-panel')).toContainText('attachment')
})
