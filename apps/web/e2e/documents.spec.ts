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
  requestRecord: {
    id: string
    payload: { title: string; description: string }
    status: string
    lock_version: number
    created_at: string
    updated_at: string
  }
  documents: Map<string, DocumentRecord>
  uploads: Map<string, { documentId: string; versionId: string }>
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
  await page.route('**/api/v1/identity/me', (route) => route.fulfill(authenticated
    ? {
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            principal: { user_id: TEST_USER_ID },
            session: { restricted: false },
            facility_id: TEST_FACILITY_ID,
            facility: 'facility-a',
            display_name: 'Documents E2E user',
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

async function installSharedWorkRecordRoutes(page: Page, state: JourneyState) {
  await page.route('**/api/v1/work-records**', async (route) => {
    const request = route.request()
    const method = request.method().toUpperCase()
    const pathname = new URL(request.url()).pathname
    const match = pathname.match(/^\/api\/v1\/work-records\/([0-9a-f-]{36})$/)
    const matchDocuments = pathname.match(/^\/api\/v1\/work-records\/([0-9a-f-]{36})\/documents$/)

    if (pathname === '/api/v1/work-records' && method === 'GET') {
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(createCollection([{
          id: state.requestRecord.id,
          payload: state.requestRecord.payload,
          status: state.requestRecord.status,
          lock_version: state.requestRecord.lock_version,
          created_at: state.requestRecord.created_at,
          updated_at: state.requestRecord.updated_at,
          allowed_actions: ['read', 'download', 'attachment'],
          decision_id: null,
        }])),
      })
      return
    }

    if (match?.[1]) {
      const recordId = match[1]
      if (method === 'GET' && recordId === state.requestRecord.id) {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              ...state.requestRecord,
              record_number: 'WR-E2E-099',
              classification: 'internal',
              field_access: { '*': 'visible' },
              lock_version: state.requestRecord.lock_version,
              allowed_actions: ['submit'],
              decision_id: null,
            },
          }),
        })
        return
      }
    }

    if (matchDocuments?.[1] && method === 'POST' && matchDocuments[1] === state.requestRecord.id) {
      const body = request.postDataJSON() as { document_id?: string }
      expect(body.document_id).toBeTruthy()
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: matchDocuments[1],
            payload: state.requestRecord.payload,
            document_id: body.document_id,
            relation_type: 'attachment',
            action: 'attached',
            decision_id: null,
          },
        }),
      })
      return
    }

    if (matchDocuments?.[1] && method === 'GET' && matchDocuments[1] === state.requestRecord.id) {
      route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }),
      })
      return
    }

    route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
  })
}

async function installDocumentRoutes(page: Page, state: JourneyState) {
  await page.route('**/api/v1/documents/uploads/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const match = url.pathname.match(/^\/api\/v1\/documents\/uploads\/([^/]+)\/complete$/)

    if (!match?.[1]) {
      route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
      return
    }

    const uploadId = match[1]
    const upload = state.uploads.get(uploadId)
    if (!upload) {
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
      file_name: 'policy-evidence.pdf',
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
          detected_mime_type: body?.content_type,
          byte_size: body?.byte_size,
          sha256: body?.sha256,
        },
      }),
    })
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
    if (pathname.startsWith('/api/v1/documents/uploads/')) {
      await route.fallback()
      return
    }
    const matchId = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})$/)
    const matchVersions = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/versions$/)
    const matchLinks = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/links$/)
    const matchTransition = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/(archive|place-hold|release-hold)$/)
    const matchGrant = pathname.match(/^\/api\/v1\/documents\/([0-9a-f-]{36})\/(download|preview)-grant$/)

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
        allowed_actions: ['read', 'update', 'add-version', 'download', 'grant', 'link', 'archive'],
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
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: document }),
        })
        return
      }

      if (method === 'PATCH') {
        const body = request.postDataJSON() as JsonBody
        if (typeof body.title === 'string' && body.title.trim().length > 0) {
          document.title = body.title
          document.lock_version += 1
        }
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: document }) })
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
      const document = state.documents.get(matchVersions[1])
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }

      const body = request.postDataJSON() as { file_name?: string; content_type?: string }
      const uploadId = uuidV7()
      const versionId = `${matchVersions[1]}-${uuidV7().slice(0, 12)}`
      state.uploads.set(uploadId, { documentId: matchVersions[1], versionId })
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            upload_id: uploadId,
            quarantine_object_id: `${uploadId}-quarantine`,
            upload_url: `https://storage.local/documents/${matchVersions[1]}/versions/${uploadId}`,
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
        document.links.unshift({
          id: uuidV7(),
          relation_type: String(body.relation_type ?? 'related'),
          source: body.source,
          record_type: (body as { record_type?: unknown }).record_type ?? (body.source as { record_type?: unknown })?.record_type,
          record_id: (body.source as { record_id?: unknown })?.record_id,
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

    if (matchGrant?.[1] && method === 'POST') {
      const document = state.documents.get(matchGrant[1])
      if (!document) {
        route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
        return
      }

      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: `${matchGrant[1]}-grant`,
            document_id: matchGrant[1],
            grant_type: matchGrant[2],
            status: 'granted',
          },
        }),
      })
      return
    }

    if (matchTransition?.[1] && method === 'POST') {
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...state.documents.get(matchTransition[1]) } }) })
      return
    }

    route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }) })
  })
}

test('documents workspace list/filter/create/update/version/link/grant flow works with mocked identity', async ({ page }) => {
  const state: JourneyState = {
    csrfToken: 'e2e-csrf-token',
    requestRecord: {
      id: '01980f50-5f0d-7000-8000-000000000099',
      payload: { title: 'طلب مراجعة', description: 'طلب رحلة المستندات' },
      status: 'draft',
      lock_version: 1,
      created_at: '2026-07-22T10:00:00Z',
      updated_at: '2026-07-22T10:00:00Z',
    },
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
  }

  await login(page)
  await installDocumentRoutes(page, state)
  await page.route('**/api/v1/work-records**', (route) => {
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify(createCollection([])),
    })
  })

  await page.goto('/documents')
  await expect(page).toHaveURL('/documents')
  await expect(page.locator('#documents-heading')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Public summary' })).toBeVisible()

  const filtered = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname === '/api/v1/documents' && url.searchParams.get('classification') === 'internal'
  })
  await page.locator('#documents-classification').click()
  await page.getByRole('option', { name: 'internal' }).click()
  await filtered
  await expect(page.getByRole('button', { name: 'Protocol outline' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Public summary' })).toBeHidden()

  await page.getByLabel('العنوان').fill('إطار التحول الرقمي')
  await page.getByLabel('الوصف').fill('وثيقة تشغيل مختبر التحول')
  await page.getByLabel('معرّف الوحدة المالكة').fill('01980f50-5f0d-7000-8000-000000000003')
  const created = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/documents' && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'إضافة مستند' }).click()
  const createdResponse = await created
  const createdPayload = (await createdResponse.json()) as { data: DocumentRecord }
  await expect(page).toHaveURL(`/documents/${createdPayload.data.id}`)

  const createdHeading = page.locator('#document-detail-heading')
  await expect(createdHeading).toContainText('إطار التحول الرقمي')
  await page.locator('#document-update-title').fill('إطار التحول الرقمي المعدل')
  await page.getByRole('button', { name: 'حفظ التعديلات' }).click()
  await expect(createdHeading).toContainText('إطار التحول الرقمي المعدل')

  const versionAdded = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.startsWith('/api/v1/documents/') && url.pathname.endsWith('/versions') && response.request().method() === 'POST'
  })
  const uploadComplete = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.startsWith('/api/v1/documents/uploads/') && url.pathname.endsWith('/complete')
  })
  await page.locator('#document-version-file').setInputFiles({
    name: 'digital-transformation.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('document body'),
  })
  await page.locator('#document-version-file').locator('xpath=ancestor::form').locator('button[type="submit"]').click()
  await versionAdded
  await uploadComplete
  await expect(page.locator('[aria-labelledby="document-versions"]')).toContainText('1')

  const linked = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/links') && response.request().method() === 'POST'
  })
  await page.getByLabel('معرّف السجل').fill(state.requestRecord.id)
  await page.getByRole('button', { name: 'الروابط' }).click()
  await linked
  await expect(page.locator('[aria-labelledby="document-links"]')).toContainText('related')

  const granted = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/download-grant') && response.request().method() === 'POST'
  })
  await page.getByRole('button', { name: 'إصدار صلاحية تنزيل' }).click()
  await granted
})

test('RequestDetail document picker offers authorized documents and attaches selected one', async ({ page }) => {
  const state: JourneyState = {
    csrfToken: 'e2e-csrf-token',
    requestRecord: {
      id: '01980f50-5f0d-7000-8000-000000000099',
      payload: { title: 'طلب تجميع ملفات', description: 'رحلة التحقق من المستندات المرتبطة' },
      status: 'draft',
      lock_version: 1,
      created_at: '2026-07-22T10:00:00Z',
      updated_at: '2026-07-22T10:00:00Z',
    },
    documents: new Map([
      ['01980f50-5f0d-7000-8000-000000000201', {
        id: '01980f50-5f0d-7000-8000-000000000201',
        title: 'وثيقة مصرح بها',
        description: 'مفروضات مصرح بها',
        owner_organization_unit_id: '01980f50-5f0d-7000-8000-000000000003',
        classification: 'internal',
        lifecycle_state: 'active',
        status: 'active',
        allowed_actions: ['read', 'download', 'link'],
        lock_version: 1,
        current_version_id: '01980f50-5f0d-7000-8000-000000000303',
        versions: [{ id: '01980f50-5f0d-7000-8000-000000000303', version_number: 1, file_name: 'auth.pdf', availability_status: 'available' }],
        links: [],
      } as DocumentRecord],
      ['01980f50-5f0d-7000-8000-000000000202', {
        id: '01980f50-5f0d-7000-8000-000000000202',
        title: 'وثيقة محظورة',
        description: 'غير مصرح',
        owner_organization_unit_id: '01980f50-5f0d-7000-8000-000000000003',
        classification: 'internal',
        lifecycle_state: 'active',
        status: 'active',
        allowed_actions: ['comment'],
        lock_version: 1,
        versions: [],
        links: [],
      } as DocumentRecord],
    ]),
    uploads: new Map(),
  }

  await login(page)
  await installSharedWorkRecordRoutes(page, state)
  await installDocumentRoutes(page, state)

  await page.goto(`/work-records/${state.requestRecord.id}`)
  await expect(page.locator('#record-actions-heading')).toBeVisible()
  await expect(page.locator('#record-documents-heading')).toBeVisible()

  await page.locator('#record-document-id').click()
  await expect(page.getByRole('option', { name: 'وثيقة مصرح بها' })).toBeVisible()
  await expect(page.getByRole('option', { name: 'وثيقة محظورة' })).toHaveCount(0)

  await page.getByRole('option', { name: 'وثيقة مصرح بها' }).click()
  const attachRequest = page.waitForRequest((request) => {
    const url = new URL(request.url())
    return request.method() === 'POST'
      && url.pathname === `/api/v1/work-records/${state.requestRecord.id}/documents`
  })
  await page.getByRole('button', { name: 'إرفاق' }).click()
  const attached = await attachRequest
  expect(await attached.postDataJSON()).toMatchObject({ document_id: '01980f50-5f0d-7000-8000-000000000201', relation_type: 'attachment' })
  await expect(page.getByText('اكتمل الإجراء.', { exact: true })).toBeVisible()
})
