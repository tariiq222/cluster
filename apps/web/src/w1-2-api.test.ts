import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createCluster,
  createFacility,
  createOrganizationUnit,
  createAssignment,
  createPerson,
  createPosition,
  createUserAccount,
  getCluster,
  listFacilities,
  listOrganizationUnits,
  listAssignments,
  listPeople,
  listPositions,
  listUserAccounts,
  transitionUserAccount,
} from './api'

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
      expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer fixture-token')
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
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: account }, 201))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: account }), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"3"' } }))
      .mockResolvedValueOnce(jsonResponse({ data: { ...account, status: 'active' } }))
    vi.stubGlobal('fetch', fetchMock)

    await listUserAccounts(token)
    await createUserAccount(token, { person_id: account.person_id, person_version: 1, username: account.username })
    await transitionUserAccount(token, account.id, 'activate', 'Approved')

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
      .mockResolvedValueOnce(jsonResponse({ data: account }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: account }), { headers: { 'Content-Type': 'application/json', ETag: '"1"' } }))
      .mockResolvedValueOnce(jsonResponse({ data: account }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(transitionUserAccount(token, accountId, 'disable')).rejects.toMatchObject({
      status: 502,
      problem: { title: 'Missing account version' },
    })
    await expect(transitionUserAccount(token, accountId, 'revoke-sessions')).resolves.toEqual(account)

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[2][1]?.body).toBe('{}')
  })
})
