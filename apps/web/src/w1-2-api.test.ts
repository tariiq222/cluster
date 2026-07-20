import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  changeIdentityPassword,
  completeDocumentUpload,
  consumeIdentityActivation,
  createCluster,
  createFacility,
  createOrganizationUnit,
  createAssignment,
  createPerson,
  createPosition,
  createTemporaryAssignment,
  createUserAccount,
  getCurrentIdentity,
  getCluster,
  getDocumentUploadStatus,
  listFacilities,
  listOrganizationUnits,
  listAssignments,
  listPeople,
  listPositions,
  listTemporaryAssignments,
  listUserAccounts,
  getTemporaryAssignment,
  identityLogin,
  identityLogout,
  initiateDocumentUpload,
  issueIdentityActivation,
  revokeTemporaryAssignment,
  transitionUserAccount,
  getImportJob,
  listImportJobRows,
  submitImportJob,
  transitionImportJob,
} from './api'
import {
  DocumentUploadInitiateRequestPurpose,
  getCompleteDocumentUploadUrl,
  getGetDocumentUploadStatusUrl,
  getGetTemporaryAssignmentUrl,
  getIdentityLoginUrl,
  getListTemporaryAssignmentsUrl,
  getRevokeTemporaryAssignmentUrl,
} from './api/generated/w1-2'

const token = 'fixture-token'
const cluster = {
  id: '018f6f7d-0c00-7000-8000-000000000101',
  code: 'THC3',
  name_ar: 'التجمع الصحي الثالث',
  name_en: 'Third Health Cluster',
  status: 'active' as const,
  lock_version: 1,
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => vi.unstubAllGlobals())

describe('W1.2 Organization API adapter', () => {
  it('retains the generated W1.2 operation URLs and upload purpose contract', () => {
    const assignmentId = '018f6f7d-0c00-7000-8000-000000000108'
    const uploadId = '018f6f7d-0c00-7000-8000-000000000109'

    expect(DocumentUploadInitiateRequestPurpose).toEqual({
      document_version: 'document_version',
      organization_import_source: 'organization_import_source',
    })
    expect(getIdentityLoginUrl()).toBe('/api/v1/identity/login')
    expect(getGetDocumentUploadStatusUrl(uploadId)).toBe(`/api/v1/documents/uploads/${uploadId}`)
    expect(getCompleteDocumentUploadUrl(uploadId)).toBe(`/api/v1/documents/uploads/${uploadId}/complete`)
    expect(getListTemporaryAssignmentsUrl({ organization_unit_id: cluster.id, limit: 20 })).toBe(
      `/api/v1/organization/temporary-assignments?organization_unit_id=${cluster.id}&limit=20`,
    )
    expect(getGetTemporaryAssignmentUrl(assignmentId)).toBe(`/api/v1/organization/temporary-assignments/${assignmentId}`)
    expect(getRevokeTemporaryAssignmentUrl(assignmentId)).toBe(`/api/v1/organization/temporary-assignments/${assignmentId}/revoke`)
  })

  it('uses the cookie session and CSRF proof for document uploads and temporary assignments', async () => {
    const uploadId = '018f6f7d-0c00-7000-8000-000000000109'
    const assignment = {
      id: '018f6f7d-0c00-7000-8000-000000000108', person_id: '018f6f7d-0c00-7000-8000-000000000105',
      organization_unit_id: cluster.id, capability_codes: ['organization.temporary-assignment.manage'],
      start_at: '2026-07-18T08:00:00Z', end_at: '2026-07-19T08:00:00Z', status: 'active',
      reason: 'Cover planned leave', approved_by_user_id: '018f6f7d-0c00-7000-8000-000000000021',
      revoked_at: null, revoke_reason: null, lock_version: 1,
    }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { upload_id: uploadId } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { document_id: 'document-id', version_id: 'version-id', scan_status: 'pending', availability_status: 'quarantined', detected_mime_type: null, byte_size: null, sha256: null } }))
      .mockResolvedValueOnce(jsonResponse({ data: { accepted: true, document_id: 'document-id', version_id: 'version-id', scan_status: 'pending', availability_status: 'quarantined', failure_codes: [] } }, 202))
      .mockResolvedValueOnce(jsonResponse({ items: [assignment], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: assignment }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: assignment }))
      .mockResolvedValueOnce(jsonResponse({ data: { ...assignment, status: 'revoked', lock_version: 2 } }))
    vi.stubGlobal('fetch', fetchMock)

    await initiateDocumentUpload('csrf-token', {
      purpose: 'document_version', name: 'Evidence', classification: 'internal', file_name: 'evidence.pdf',
      content_type: 'application/pdf', byte_size: 42, sha256: 'a'.repeat(64),
    })
    await getDocumentUploadStatus(uploadId)
    await completeDocumentUpload('csrf-token', uploadId, { byte_size: 42, sha256: 'a'.repeat(64) })
    await listTemporaryAssignments(cluster.id, 20)
    await createTemporaryAssignment('csrf-token', {
      person_id: assignment.person_id, organization_unit_id: cluster.id, capability_codes: assignment.capability_codes,
      start_at: assignment.start_at, end_at: assignment.end_at, reason: assignment.reason,
    })
    await getTemporaryAssignment(assignment.id)
    await revokeTemporaryAssignment('csrf-token', assignment.id, '"1"', 'Coverage no longer required')

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/documents/uploads',
      `/api/v1/documents/uploads/${uploadId}`,
      `/api/v1/documents/uploads/${uploadId}/complete`,
      `/api/v1/organization/temporary-assignments?organization_unit_id=${cluster.id}&limit=20`,
      '/api/v1/organization/temporary-assignments',
      `/api/v1/organization/temporary-assignments/${assignment.id}`,
      `/api/v1/organization/temporary-assignments/${assignment.id}/revoke`,
    ])
    for (const [, init] of fetchMock.mock.calls) {
      expect(init).toMatchObject({ credentials: 'include' })
      expect(new Headers(init?.headers).get('Authorization')).toBeNull()
    }
    for (const callIndex of [0, 2, 4, 6]) {
      const headers = new Headers(fetchMock.mock.calls[callIndex][1]?.headers)
      expect(headers.get('X-CSRF-Token')).toBe('csrf-token')
      expect(headers.get('Idempotency-Key')).toBeTruthy()
    }
    expect(new Headers(fetchMock.mock.calls[6][1]?.headers).get('If-Match')).toBe('"1"')
  })

  it('uses cookie-session credential operations with their required CSRF inputs', async () => {
    const accountId = '018f6f7d-0c00-7000-8000-000000000106'
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: { user_id: accountId, expires_at: '2026-07-18T08:00:00Z', restricted: false, csrf_token: 'csrf-token' } }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ data: { principal: { user_id: accountId }, account: { id: accountId }, session: { restricted: false } } }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ data: { account_id: accountId, status: 'activation_issued', expires_at: '2026-07-18T08:00:00Z', delivery: 'controlled' } }, 202))
    vi.stubGlobal('fetch', fetchMock)

    await identityLogin({ username: 'employee.100', password: 'correct-password' })
    await consumeIdentityActivation({ token: 'a'.repeat(64), password: 'new-correct-password' })
    await getCurrentIdentity()
    await identityLogout('csrf-token')
    await changeIdentityPassword('csrf-token', { current_password: 'correct-password', new_password: 'new-correct-password' })
    await issueIdentityActivation('csrf-token', accountId)

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/identity/login',
      '/api/v1/identity/activation',
      '/api/v1/identity/me',
      '/api/v1/identity/logout',
      '/api/v1/identity/password',
      `/api/v1/identity/accounts/${accountId}/activation`,
    ])
    for (const [, init] of fetchMock.mock.calls) {
      expect(init).toMatchObject({ credentials: 'include' })
      expect(new Headers(init?.headers).get('Authorization')).toBeNull()
    }
    for (const callIndex of [3, 4, 5]) {
      expect(new Headers(fetchMock.mock.calls[callIndex][1]?.headers).get('X-CSRF-Token')).toBe('csrf-token')
    }
  })

  it('reads the cluster and facility collection from published routes', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: cluster }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(getCluster(token)).resolves.toEqual(cluster)
    await expect(listFacilities(token)).resolves.toEqual({ items: [], next_cursor: null })

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/organization/cluster',
      '/api/v1/organization/facilities?limit=100',
    ])
    for (const [, init] of fetchMock.mock.calls) {
      expect(new Headers(init?.headers).get('Authorization')).toBeNull()
    }
  })

  it('creates cluster and facility resources with governed replay headers', async () => {
    const facility = {
      id: '018f6f7d-0c00-7000-8000-000000000102',
      cluster_id: cluster.id,
      type_code: 'hospital',
      code: 'HOSPITAL_A',
      name_ar: 'مستشفى الاختبار',
      name_en: null,
      status: 'active' as const,
      lock_version: 1,
    }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: cluster }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: facility }, 201))
    vi.stubGlobal('fetch', fetchMock)

    await createCluster(token, { code: 'THC3', name: 'التجمع الصحي الثالث' })
    await createFacility(token, {
      cluster_id: cluster.id,
      type_code: 'hospital',
      code: 'HOSPITAL_A',
      name: 'مستشفى الاختبار',
    })

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/organization/cluster',
      '/api/v1/organization/facilities',
    ])
    for (const [, init] of fetchMock.mock.calls) {
      const headers = new Headers(init?.headers)
      expect(headers.get('Idempotency-Key')).toMatch(/^(cluster|facility)-[0-9a-f-]+$/)
      expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/)
    }
  })

  it('lists and creates units and positions through typed W1.2 routes', async () => {
    const unit = {
      id: '018f6f7d-0c00-7000-8000-000000000103',
      cluster_id: cluster.id,
      parent_id: cluster.id,
      parent_type: 'cluster' as const,
      type_code: 'department',
      code: 'OPERATIONS',
      name_ar: 'إدارة التشغيل',
      name_en: null,
      status: 'active' as const,
      path_cache: `/${cluster.id}/018f6f7d-0c00-7000-8000-000000000103`,
      depth: 1,
      lock_version: 1,
    }
    const position = {
      id: '018f6f7d-0c00-7000-8000-000000000104',
      organization_unit_id: unit.id,
      code: 'OPS_MANAGER',
      title_ar: 'مدير التشغيل',
      manager_position_id: null,
      is_active: true,
      lock_version: 1,
    }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: unit }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: position }, 201))
    vi.stubGlobal('fetch', fetchMock)

    await listOrganizationUnits(token)
    await listPositions(token)
    await createOrganizationUnit(token, {
      cluster_id: cluster.id,
      type_code: 'department',
      code: 'OPERATIONS',
      name: 'إدارة التشغيل',
    })
    await createPosition(token, {
      organization_unit_id: unit.id,
      code: 'OPS_MANAGER',
      title: 'مدير التشغيل',
      manager_position_id: null,
    })

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/organization/units?limit=100',
      '/api/v1/organization/positions?limit=100',
      '/api/v1/organization/units',
      '/api/v1/organization/positions',
    ])
    for (const [, init] of fetchMock.mock.calls.slice(2)) {
      expect(new Headers(init?.headers).get('Idempotency-Key')).toMatch(/^(organization-unit|position)-[0-9a-f-]+$/)
    }
  })

  it('lists and creates people and assignments without mixing Identity fields', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'person-id' } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'assignment-id' } }, 201))
    vi.stubGlobal('fetch', fetchMock)

    await listPeople(token)
    await listAssignments(token)
    await createPerson(token, {
      employee_number: 'EMP-100', display_name_ar: 'موظف الاختبار', status: 'active',
    })
    await createAssignment(token, {
      person_id: '018f6f7d-0c00-7000-8000-000000000105',
      position_id: '018f6f7d-0c00-7000-8000-000000000104',
      start_at: '2026-07-18T08:00:00.000Z',
      is_primary: true,
    })

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/organization/people?limit=100',
      '/api/v1/organization/assignments?limit=100',
      '/api/v1/organization/people',
      '/api/v1/organization/assignments',
    ])
    expect(String(fetchMock.mock.calls[2][1]?.body)).not.toContain('identity')
  })

  it('reads an account ETag before a governed lifecycle transition', async () => {
    const account = {
      id: '018f6f7d-0c00-7000-8000-000000000106', username: 'employee.100',
      person_id: '018f6f7d-0c00-7000-8000-000000000105', person_version: 1,
      status: 'pending' as const, must_change_password: true, password_version: 1,
      locked_until: null, display_name_ar: 'موظف الاختبار', display_name_en: null,
    }
    const activeAccount = { ...account, status: 'active' as const }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse(account, 201))
      .mockResolvedValueOnce(new Response(JSON.stringify(account), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"3"' } }))
      .mockResolvedValueOnce(jsonResponse(activeAccount))
    vi.stubGlobal('fetch', fetchMock)

    await listUserAccounts(token)
    await expect(createUserAccount(token, { person_id: account.person_id, person_version: 1, username: account.username })).resolves.toEqual(account)
    await expect(transitionUserAccount(token, account.id, 'activate', 'Approved')).resolves.toEqual(activeAccount)

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/identity/accounts?limit=100',
      '/api/v1/identity/accounts',
      `/api/v1/identity/accounts/${account.id}`,
      `/api/v1/identity/accounts/${account.id}/activate`,
    ])
    const headers = new Headers(fetchMock.mock.calls[3][1]?.headers)
    expect(headers.get('If-Match')).toBe('"3"')
    expect(headers.get('Idempotency-Key')).toMatch(/^identity-activate-[0-9a-f-]+$/)
  })

  it('fails closed without an account ETag and supports a reasonless action', async () => {
    const accountId = '018f6f7d-0c00-7000-8000-000000000106'
    const account = { id: accountId, status: 'active' }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse(account))
      .mockResolvedValueOnce(new Response(JSON.stringify(account), { headers: { 'Content-Type': 'application/json', ETag: '"1"' } }))
      .mockResolvedValueOnce(jsonResponse(account))
    vi.stubGlobal('fetch', fetchMock)

    await expect(transitionUserAccount(token, accountId, 'disable')).rejects.toMatchObject({
      status: 502,
      problem: { title: 'Missing account version' },
    })
    await expect(transitionUserAccount(token, accountId, 'revoke-sessions')).resolves.toEqual(account)

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[2][1]?.body).toBe('{}')
  })

  it('submits, reads, and transitions a redacted import using a fresh ETag', async () => {
    const job = {
      id: '018f6f7d-0c00-7000-8000-000000000107', template_code: 'people_assignments' as const,
      import_type: 'csv' as const, status: 'received' as const,
      submitted_by_user_id: '018f6f7d-0c00-7000-8000-000000000021', approved_by_user_id: null,
      total_rows: 0, valid_rows: 0, error_rows: 0, applied_at: null, lock_version: 1,
    }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: job }, 202))
      .mockResolvedValueOnce(jsonResponse({ data: job }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: job }), { headers: { 'Content-Type': 'application/json', ETag: '"1"' } }))
      .mockResolvedValueOnce(jsonResponse({ data: { ...job, status: 'validated', lock_version: 2 } }))
    vi.stubGlobal('fetch', fetchMock)

    await submitImportJob(token, { quarantine_object_id: job.id, template_code: 'people_assignments', import_type: 'csv' })
    await getImportJob(token, job.id)
    await listImportJobRows(token, job.id)
    await transitionImportJob(token, job.id, 'validate')

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/organization/import-jobs',
      `/api/v1/organization/import-jobs/${job.id}`,
      `/api/v1/organization/import-jobs/${job.id}/rows?limit=100`,
      `/api/v1/organization/import-jobs/${job.id}`,
      `/api/v1/organization/import-jobs/${job.id}/validate`,
    ])
    const transitionHeaders = new Headers(fetchMock.mock.calls[4][1]?.headers)
    expect(transitionHeaders.get('If-Match')).toBe('"1"')
    expect(String(fetchMock.mock.calls[0][1]?.body)).not.toContain('raw_payload')
  })

  it('fails closed without an import ETag and sends a governed decision reason', async () => {
    const jobId = '018f6f7d-0c00-7000-8000-000000000107'
    const job = { id: jobId, status: 'validated' }
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: job }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: job }), { headers: { 'Content-Type': 'application/json', ETag: '"2"' } }))
      .mockResolvedValueOnce(jsonResponse({ data: { ...job, status: 'rejected' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(transitionImportJob(token, jobId, 'approve')).rejects.toMatchObject({
      status: 502,
      problem: { title: 'Missing import version' },
    })
    await transitionImportJob(token, jobId, 'reject', 'Rows require correction')

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[2][1]?.body).toBe(JSON.stringify({ reason: 'Rows require correction' }))
  })
})
