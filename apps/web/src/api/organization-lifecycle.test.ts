import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  endAssignment,
  updateCluster,
  updateFacility,
  updateOrganizationUnit,
  updatePerson,
  updatePosition,
} from './organization'

const token = 'csrf-token'
const unitId = '018f6f7d-0c00-7000-8000-000000000001'
const positionId = '018f6f7d-0c00-7000-8000-000000000002'
const personId = '018f6f7d-0c00-7000-8000-000000000003'
const assignmentId = '018f6f7d-0c00-7000-8000-000000000004'
const facilityId = '018f6f7d-0c00-7000-8000-000000000005'

function response(data: unknown, status = 200, etag = '"2"') {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: etag },
  })
}

function requireFetchCall(fetchMock: { mock: { calls: Parameters<typeof fetch>[] } }, index: number): Parameters<typeof fetch> {
  const call = fetchMock.mock.calls[index]
  if (!call) throw new Error(`Expected fetch call ${index + 1}`)
  return call
}

function requireMockEntry<T>(entries: T[], index: number): T {
  const entry = entries[index]
  if (entry === undefined) throw new Error(`Expected mock entry ${index + 1}`)
  return entry
}

afterEach(() => vi.unstubAllGlobals())

describe('organization lifecycle wrappers', () => {
  it('sends CSRF, correlation, idempotency and If-Match for generated updates', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(response({ id: unitId, name_ar: 'Updated unit', lock_version: 2 }))
      .mockResolvedValueOnce(response({ id: positionId, title_ar: 'Updated position', lock_version: 3 }))
      .mockResolvedValueOnce(response({ id: personId, display_name_ar: 'Updated person', person_version: 4 }))
      .mockResolvedValueOnce(response({ id: assignmentId, status: 'ended', lock_version: 5 }))
    vi.stubGlobal('fetch', fetchMock)

    await updateOrganizationUnit(token, unitId, 1, { name: 'Updated unit' })
    await updatePosition(token, positionId, 2, { title: 'Updated position' })
    await updatePerson(token, personId, 3, { display_name_ar: 'Updated person' })
    await endAssignment(token, assignmentId, 4, { end_at: '2026-07-22T10:00:00Z', reason: 'انتهت المهمة' })

    expect(fetchMock).toHaveBeenCalledTimes(4)
    const paths = fetchMock.mock.calls.map(([path]) => path)
    expect(paths).toEqual([
      `/api/v1/organization/units/${unitId}`,
      `/api/v1/organization/positions/${positionId}`,
      `/api/v1/organization/people/${personId}`,
      `/api/v1/organization/assignments/${assignmentId}/end`,
    ])
    for (const [, init] of fetchMock.mock.calls) {
      const headers = new Headers(init?.headers)
      expect(init).toMatchObject({ credentials: 'include' })
      expect(headers.get('X-CSRF-Token')).toBe(token)
      expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f-]+$/)
      expect(headers.get('Idempotency-Key')).toBeTruthy()
    }
    expect(new Headers(requireFetchCall(fetchMock, 0)[1]?.headers).get('If-Match')).toBe('"1"')
    expect(new Headers(requireFetchCall(fetchMock, 1)[1]?.headers).get('If-Match')).toBe('"2"')
    expect(new Headers(requireFetchCall(fetchMock, 2)[1]?.headers).get('If-Match')).toBe('"3"')
    expect(new Headers(requireFetchCall(fetchMock, 3)[1]?.headers).get('If-Match')).toBe('"4"')
  })

  it('surfaces a stale 412 response instead of pretending the update succeeded', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValueOnce(response({ title: 'Precondition Failed', status: 412 }, 412)))
    await expect(updateOrganizationUnit(token, unitId, 1, { name: 'stale' })).rejects.toMatchObject({ status: 412 })
  })

  it('sends CSRF, correlation, idempotency and If-Match for cluster and facility updates', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(response({ id: 'cluster', name_ar: 'تجمع محدّث', lock_version: 6 }))
      .mockResolvedValueOnce(response({ id: facilityId, name_ar: 'منشأة محدّثة', status: 'active', lock_version: 7 }))
    vi.stubGlobal('fetch', fetchMock)

    await updateCluster(token, 5, { name: 'تجمع محدّث' })
    await updateFacility(token, facilityId, 6, { name: 'منشأة محدّثة', status: 'active' })

    expect(fetchMock).toHaveBeenCalledTimes(2)
    const paths = fetchMock.mock.calls.map(([path]) => path)
    expect(paths).toEqual([
      '/api/v1/organization/cluster',
      `/api/v1/organization/facilities/${facilityId}`,
    ])
    for (const [, init] of fetchMock.mock.calls) {
      const headers = new Headers(init?.headers)
      expect(init).toMatchObject({ credentials: 'include' })
      expect(headers.get('X-CSRF-Token')).toBe(token)
      expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f-]+$/)
      expect(headers.get('Idempotency-Key')).toBeTruthy()
      expect(headers.get('Content-Type')).toBe('application/merge-patch+json')
    }
    expect(new Headers(requireFetchCall(fetchMock, 0)[1]?.headers).get('If-Match')).toBe('"5"')
    expect(new Headers(requireFetchCall(fetchMock, 1)[1]?.headers).get('If-Match')).toBe('"6"')
    const bodies = fetchMock.mock.calls.map(([, init]) => JSON.parse(String(init?.body)))
    expect(requireMockEntry(bodies, 0)).toEqual({ name: 'تجمع محدّث' })
    expect(requireMockEntry(bodies, 1)).toEqual({ name: 'منشأة محدّثة', status: 'active' })
  })

  it('surfaces a stale 412 response for cluster and facility updates', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(response({ title: 'Precondition Failed', status: 412 }, 412))
      .mockResolvedValueOnce(response({ title: 'Precondition Failed', status: 412 }, 412))
    vi.stubGlobal('fetch', fetchMock)

    await expect(updateCluster(token, 1, { name: 'stale' })).rejects.toMatchObject({ status: 412 })
    await expect(updateFacility(token, facilityId, 2, { name: 'stale' })).rejects.toMatchObject({ status: 412 })
  })
})
