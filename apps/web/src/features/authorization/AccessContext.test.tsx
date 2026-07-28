import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api'
import {
  accessContextLabels,
  clearanceLabel,
  delegationRows,
  directionForLocale,
  fieldAccessRows,
  fieldStateLabel,
  isContextEmpty,
  normalizePrincipal,
  normalizeScopeSelection,
  parseScopeSelectValue,
  projectionSummary,
  scopeSelectionView,
  scopeSelectValue,
  scopeTypeLabel,
  stateFromError,
} from './AccessContext'
import { listMyAccessScopes, parseStrongEtag, selectMyAccessScope } from '../../api/r1'

function requiredAt<T>(values: readonly T[], index: number): T {
  const value = values[index]
  if (value === undefined) throw new Error(`Expected value at index ${index}`)
  return value
}

function requiredRequest(calls: readonly Parameters<typeof fetch>[], index: number): RequestInit {
  const request = requiredAt(calls, index)[1]
  if (request === undefined) throw new Error(`Expected request init at index ${index}`)
  return request
}

describe('AccessContext pure view-model helpers', () => {
  it('renders masked fields as *** and never renders hidden fields', () => {
    const rows = fieldAccessRows({ visible: 'editable', secret: 'hidden', masked: 'masked', locked: 'readonly' })
    expect(rows).toEqual([
      { name: 'visible', state: 'editable', display: 'editable' },
      { name: 'masked', state: 'masked', display: '***' },
      { name: 'locked', state: 'readonly', display: 'readonly' },
    ])
    expect(rows.some((row) => row.name === 'secret')).toBe(false)
    expect(fieldAccessRows(undefined)).toEqual([])
    expect(fieldAccessRows({ weird: 'unknown-state' as never })).toEqual([])
  })

  it('maps 403 to forbidden without deriving access locally, and leaves 401 to the shell', () => {
    // A 401 ends the session through the API layer's central handler, so this screen
    // only needs to distinguish "denied" from "something else went wrong".
    expect(stateFromError(new ApiError(401, { type: 'about:blank', title: 'Unauthorized', status: 401 }))).toBe('error')
    expect(stateFromError(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))).toBe('forbidden')
    expect(stateFromError(new ApiError(500, { type: 'about:blank', title: 'Server error', status: 500 }))).toBe('error')
    expect(stateFromError(new Error('network'))).toBe('error')
  })

  it('provides Arabic-default accessible labels and RTL/LTR-safe direction', () => {
    expect(directionForLocale('ar')).toBe('rtl')
    expect(directionForLocale('en')).toBe('ltr')
    expect(accessContextLabels.ar.title).toBe('سياق الوصول الشخصي')
    expect(accessContextLabels.ar.scopeSelector).toBeTruthy()
    expect(accessContextLabels.en.scopeSelector).toBeTruthy()
    expect(accessContextLabels.ar.capabilities).toBe('الصلاحيات الفعلية')
    expect(accessContextLabels.en.capabilities).toBe('Effective capabilities')
    expect(fieldStateLabel('masked', 'ar')).toBe('مقنّع')
    expect(fieldStateLabel('readonly', 'en')).toBe('Read only')
    expect(clearanceLabel('confidential', 'ar')).toBe('سري')
    expect(clearanceLabel('custom-level', 'en')).toBe('custom-level')
    expect(scopeTypeLabel('facility', 'ar')).toBe('المنشأة')
    expect(scopeTypeLabel('record_set', 'en')).toBe('record_set')
  })

  it('normalizes server scope selections and keeps effective scope selection keyboard-safe', () => {
    const selection = normalizeScopeSelection({
      available_scopes: [
        { scope_type: 'cluster', scope_id: '018f6f7d-0c00-7000-8000-000000000001', label: 'Cluster A' },
        { scope_type: 'facility', scope_id: '018f6f7d-0c00-7000-8000-000000000002', label: 'Facility B' },
        { scope_type: 'unit', label: 'missing id' },
      ],
      effective_scope: { scope_type: 'facility', scope_id: '018f6f7d-0c00-7000-8000-000000000002', label: 'Facility B' },
    })
    expect(selection.options).toHaveLength(2)
    expect(selection.lockVersion).toBeNull()
    expect(requiredAt(selection.options, 1).effective).toBe(true)
    expect(selection.effective?.scopeId).toBe('018f6f7d-0c00-7000-8000-000000000002')

    const values = selection.options.map(scopeSelectValue)
    expect(new Set(values).size).toBe(values.length)
    for (const value of values) {
      const parsed = parseScopeSelectValue(value)
      expect(parsed).not.toBeNull()
      expect(scopeSelectValue({ scopeType: parsed!.scopeType, scopeId: parsed!.scopeId, label: '', effective: false })).toBe(value)
    }
    expect(parseScopeSelectValue('')).toBeNull()
    expect(parseScopeSelectValue(':')).toBeNull()
    expect(parseScopeSelectValue('cluster:')).toBeNull()

    expect(normalizeScopeSelection(null)).toEqual({ options: [], effective: null, lockVersion: null })
    expect(normalizeScopeSelection({}).effective).toBeNull()
    expect(normalizeScopeSelection({ available_scopes: [{ scope_type: 'record_set', scope_id: 'record-set-1', label: 'Unsupported' }] }).options).toEqual([])
  })

  it('retains the GET scope ETag version for optimistic selection and rejects weak or malformed tags', () => {
    expect(parseStrongEtag('"7"')).toBe(7)
    expect(parseStrongEtag('W/"7"')).toBeNull()
    expect(parseStrongEtag('"0"')).toBeNull()
    expect(parseStrongEtag(null)).toBeNull()
    expect(scopeSelectionView({
      selection: { available_scopes: [], effective_scope: { scope_type: 'cluster', scope_id: 'cluster-1', label: 'Cluster' } },
      lockVersion: 7,
    }).lockVersion).toBe(7)
  })

  it('passes the GET ETag version as If-Match through the generated scope client seam', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({ available_scopes: [], effective_scope: { scope_type: 'cluster', scope_id: '018f6f7d-0c00-7000-8000-000000000001', label: 'Cluster' } }), { status: 200, headers: { ETag: '"7"' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ available_scopes: [], effective_scope: { scope_type: 'facility', scope_id: '018f6f7d-0c00-7000-8000-000000000002', label: 'Facility' } }), { status: 200, headers: { ETag: '"8"' } }))
    vi.stubGlobal('fetch', fetchMock)

    const snapshot = await listMyAccessScopes('token')
    await selectMyAccessScope('token', { scope_type: 'facility', scope_id: '018f6f7d-0c00-7000-8000-000000000002' }, snapshot.lockVersion!)

    expect(fetchMock).toHaveBeenCalledTimes(2)
    const selectionInit = requiredRequest(fetchMock.mock.calls, 1)
    expect(new Headers(selectionInit.headers).get('If-Match')).toBe('"7"')
    expect(new Headers(selectionInit.headers).get('Idempotency-Key')).toBeTruthy()
    vi.unstubAllGlobals()
  })

  it('normalizes the server principal context without inventing roles or clearance', () => {
    expect(normalizePrincipal({
      subject_id: '018f6f7d-0c00-7000-8000-000000000001',
      tenant_id: '018f6f7d-0c00-7000-8000-000000000002',
      roles: ['role.a', 42, 'role.b'],
      capabilities: ['tasks.read', 42, 'documents.read'],
      clearance: 'internal',
      break_glass: true,
      organization_unit_ids: ['u1', 'u2'],
      correlation_id: '018f6f7d-0c00-7000-8000-000000000003',
    })).toEqual({
      subjectId: '018f6f7d-0c00-7000-8000-000000000001',
      tenantId: '018f6f7d-0c00-7000-8000-000000000002',
      roles: ['role.a', 'role.b'],
      capabilities: ['tasks.read', 'documents.read'],
      clearance: 'internal',
      breakGlass: true,
      organizationUnitCount: 2,
      correlationId: '018f6f7d-0c00-7000-8000-000000000003',
      features: null,
    })
    expect(normalizePrincipal(undefined)).toEqual({
      subjectId: '', tenantId: '', roles: [], capabilities: [], clearance: 'public', breakGlass: false, organizationUnitCount: 0, correlationId: '', features: null,
    })
  })

  it('summarizes the server projection with decision ID, allowed actions, and masked-safe fields', () => {
    expect(projectionSummary(null)).toBeNull()
    const summary = projectionSummary({
      decision_id: 'decision-1',
      allowed_actions: ['submit', 'read'],
      field_access: { payload_title: 'masked', payload_secret: 'hidden', status: 'readonly' },
    })
    expect(summary?.decisionId).toBe('decision-1')
    expect(summary?.actions).toEqual(['submit', 'read'])
    expect(summary?.fields).toEqual([
      { name: 'payload_title', state: 'masked', display: '***' },
      { name: 'status', state: 'readonly', display: 'readonly' },
    ])
  })

  it('maps delegation rows from server data only', () => {
    expect(delegationRows([{ id: 'd1', name: 'Delegate', code: 'del-1', status: 'active' }])).toEqual([
      { id: 'd1', name: 'Delegate', code: 'del-1', status: 'active' },
    ])
    expect(delegationRows([{}])).toEqual([{ id: 'delegation-0', name: '—', code: '—', status: '—' }])
  })

  it('detects an empty personal context for the empty state', () => {
    const principal = normalizePrincipal({ roles: [], clearance: 'public' })
    expect(isContextEmpty(principal, { options: [], effective: null, lockVersion: null })).toBe(true)
    expect(isContextEmpty(principal, { options: [{ scopeType: 'cluster', scopeId: 's1', label: 'S', effective: true }], effective: null, lockVersion: null })).toBe(false)
    expect(isContextEmpty({ ...principal, roles: ['role.a'] }, { options: [], effective: null, lockVersion: null })).toBe(false)
    expect(isContextEmpty({ ...principal, capabilities: ['tasks.read'] }, { options: [], effective: null, lockVersion: null })).toBe(false)
  })
})
