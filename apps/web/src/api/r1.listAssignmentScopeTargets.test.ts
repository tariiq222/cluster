import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ListAuthorizationAssignmentScopeTargetsParams } from './generated/cluster'

import { listAssignmentScopeTargets } from './r1'

const token = 'csrf-token'

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: '"2"' },
  })
}

function fetchMock(data: unknown, status = 200) {
  const mock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse(data, status))
  vi.stubGlobal('fetch', mock)
  return mock
}

function callOf(mock: ReturnType<typeof vi.fn<typeof fetch>>) {
  const call = mock.mock.calls[0]
  if (!call) throw new Error('Expected fetch call')
  return call
}

const clusterTarget = {
  scope_type: 'cluster',
  scope_id: '018f6f7d-0c00-7000-8000-000000000101',
  label_ar: 'تجمع الرياض',
  label_en: 'Riyadh cluster',
  code: 'RUH',
}
const facilityTarget = {
  scope_type: 'facility',
  scope_id: '018f6f7d-0c00-7000-8000-000000000102',
  label_ar: 'مستشفى الملك فيصل',
  label_en: 'King Faisal Hospital',
  code: 'KFH',
}
const unitTarget = {
  scope_type: 'unit',
  scope_id: '018f6f7d-0c00-7000-8000-000000000103',
  label_ar: 'قسم الطوارئ',
  label_en: 'Emergency department',
  code: 'ED',
}

afterEach(() => vi.unstubAllGlobals())

describe('listAssignmentScopeTargets typed wrapper', () => {
  it('returns the flat { items, next_cursor } envelope for scope_type=cluster', async () => {
    const mock = fetchMock({ items: [clusterTarget], next_cursor: null })
    const result = await listAssignmentScopeTargets(token, { scopeType: 'cluster' })
    expect(result).toMatchObject({ items: [clusterTarget], next_cursor: null })
    const call = callOf(mock)
    expect(call[0]).toBe('/api/v1/authorization/assignment-scope-targets?scope_type=cluster')
    expect(call[1]?.method ?? 'GET').toBe('GET')
    const headers = new Headers(call[1]?.headers)
    expect(headers.get('X-CSRF-Token')).toBeNull()
    expect(headers.get('Idempotency-Key')).toBeNull()
  })

  it('forwards scope_type, parent_scope_type and parent_scope_id together for scope_type=unit', async () => {
    const mock = fetchMock({ items: [unitTarget], next_cursor: 'cursor-2' })
    const parentScopeId = '018f6f7d-0c00-7000-8000-000000000200'
    const result = await listAssignmentScopeTargets(token, {
      scopeType: 'unit',
      parentScopeType: 'facility',
      parentScopeId: parentScopeId,
    })
    expect(result).toMatchObject({ items: [unitTarget], next_cursor: 'cursor-2' })
    const url = String(callOf(mock)[0])
    const parsed = new URL(url, 'https://placeholder.local')
    expect(parsed.pathname).toBe('/api/v1/authorization/assignment-scope-targets')
    expect(parsed.searchParams.get('scope_type')).toBe('unit')
    expect(parsed.searchParams.get('parent_scope_type')).toBe('facility')
    expect(parsed.searchParams.get('parent_scope_id')).toBe(parentScopeId)
  })

  it('surfaces the 422 scope_type_not_catalogued problem through the existing ApiError flow', async () => {
    const mock = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          type: 'urn:cluster:problem:scope_type_not_catalogued',
          title: 'Scope type not catalogued',
          status: 422,
          detail: 'record_set is not a manageable level',
        }),
        { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
      ),
    )
    vi.stubGlobal('fetch', mock)
    await expect(
      listAssignmentScopeTargets(token, { scopeType: 'record_set' }),
    ).rejects.toMatchObject({
      status: 422,
      problem: { type: 'urn:cluster:problem:scope_type_not_catalogued' },
    })
  })

  it('accepts every generated scope_type value at the type level (no record_set exclusion)', () => {
    // If a future regression narrows the wrapper params to exclude `record_set`,
    // these literal assignments will fail at compile time and the test stops
    // being a meaningful compile-time guarantee.
    const params: ListAuthorizationAssignmentScopeTargetsParams = {
      scope_type: 'cluster',
    }
    expect(params.scope_type).toBe('cluster')
    const facilityParams: ListAuthorizationAssignmentScopeTargetsParams = {
      scope_type: 'facility',
    }
    expect(facilityParams.scope_type).toBe('facility')
    const unitParams: ListAuthorizationAssignmentScopeTargetsParams = {
      scope_type: 'unit',
    }
    expect(unitParams.scope_type).toBe('unit')
    const recordSetParams: ListAuthorizationAssignmentScopeTargetsParams = {
      scope_type: 'record_set',
    }
    expect(recordSetParams.scope_type).toBe('record_set')
  })
  it('omits parent_scope_type and parent_scope_id when only scope_type is supplied', async () => {
    const mock = fetchMock({ items: [facilityTarget], next_cursor: null })
    const result = await listAssignmentScopeTargets(token, { scopeType: 'facility' })
    const url = String(callOf(mock)[0])
    const parsed = new URL(url, 'https://placeholder.local')
    expect(parsed.searchParams.get('scope_type')).toBe('facility')
    expect(parsed.searchParams.has('parent_scope_type')).toBe(false)
    expect(parsed.searchParams.has('parent_scope_id')).toBe(false)
  })
})