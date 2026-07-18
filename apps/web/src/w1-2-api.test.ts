import { afterEach, describe, expect, it, vi } from 'vitest'

import { createCluster, createFacility, getCluster, listFacilities } from './api'

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
})
