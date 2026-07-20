import { describe, expect, it } from 'vitest'
import type { AuthorizedWorkRecord } from '../../api/r1'
import { formatRecordValue } from './RecordField'
import { isActionAllowed, projectRecordFields, projectRecordSummary, projectionStateMessage, resolveFieldAccess } from './RecordProjection'

const record = {
  id: 'record-1', record_number: 'WR-1', work_type_version_id: 'version-1', owner: {}, status: 'draft', classification: 'internal',
  payload: { visible: 'shown', secret: 'raw secret', masked: 'raw masked', readonly: 'fixed', editable: 'change me' }, lock_version: 1,
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z', decision_id: 'decision-1', allowed_actions: ['submit'],
  field_access: { visible: 'readonly', secret: 'hidden', masked: 'masked', readonly: 'readonly', editable: 'editable' },
} as unknown as AuthorizedWorkRecord

describe('Record projection pure helpers', () => {
  it('omits hidden fields before presentation', () => {
    expect(projectRecordFields(record).map((field) => field.name)).toEqual(['visible', 'masked', 'readonly', 'editable'])
    expect(projectRecordFields(record).some((field) => field.value === 'raw secret')).toBe(false)
    expect(projectRecordFields(record).some((field) => field.value === 'raw masked')).toBe(false)
    expect(projectRecordFields(record).find((field) => field.name === 'masked')?.value).toBe('***')
  })

  it('preserves access states and gates actions from the server projection', () => {
    expect(projectRecordFields(record).find((field) => field.name === 'editable')?.access).toBe('editable')
    expect(isActionAllowed(record, 'submit')).toBe(true)
    expect(isActionAllowed(record, 'complete')).toBe(false)
  })

  it('uses wildcard field access for payload and summary fields while explicit fields override it', () => {
    const wildcardRecord = {
      ...record,
      record_number: 'WR-SECRET',
      status: 'draft',
      classification: 'internal',
      field_access: { '*': 'masked', status: 'readonly', secret: 'hidden' },
    } as unknown as AuthorizedWorkRecord
    expect(resolveFieldAccess(wildcardRecord.field_access, 'editable')).toBe('masked')
    expect(resolveFieldAccess(wildcardRecord.field_access, 'status')).toBe('readonly')
    expect(resolveFieldAccess(wildcardRecord.field_access, 'missing')).toBe('masked')
    expect(projectRecordFields(wildcardRecord).find((field) => field.name === 'editable')?.value).toBe('***')
    expect(projectRecordFields(wildcardRecord).some((field) => field.name === 'secret')).toBe(false)
    expect(projectRecordSummary(wildcardRecord)).toEqual([
      { name: 'record_number', value: '***', access: 'masked' },
      { name: 'status', value: 'draft', access: 'readonly' },
      { name: 'classification', value: '***', access: 'masked' },
    ])
  })

  it('lets explicit summary access override wildcard access, including hidden', () => {
    const summaryRecord = { ...record, field_access: { '*': 'hidden', status: 'readonly', classification: 'masked' } } as unknown as AuthorizedWorkRecord
    expect(projectRecordSummary(summaryRecord)).toEqual([
      { name: 'status', value: 'draft', access: 'readonly' },
      { name: 'classification', value: '***', access: 'masked' },
    ])
  })

  it.each([
    ['readonly', 3, 'draft'],
    ['masked', 3, '***'],
    ['hidden', 0, undefined],
  ] as const)('applies a %s wildcard to summary fields', (_label, count, expectedStatus) => {
    const summaryRecord = { ...record, field_access: { '*': _label } } as unknown as AuthorizedWorkRecord
    const summary = projectRecordSummary(summaryRecord)
    expect(summary).toHaveLength(count)
    if (expectedStatus !== undefined) expect(summary.find((field) => field.name === 'status')?.value).toBe(expectedStatus)
  })

  it.each([
    ['readonly', 'readonly', 'raw value'],
    ['masked', 'masked', '***'],
    ['hidden', 'hidden', undefined],
  ] as const)('applies a %s wildcard to payload fields', (_label, access, expected) => {
    const wildcardRecord = { ...record, field_access: { '*': access } } as unknown as AuthorizedWorkRecord
    const field = projectRecordFields(wildcardRecord).find((item) => item.name === 'visible')
    if (access === 'hidden') {
      expect(field).toBeUndefined()
    } else {
      expect(field?.access).toBe(access)
      expect(field?.value).toBe(expected)
    }
  })

  it('formats masked values exactly and provides localized state messages', () => {
    expect(formatRecordValue({ sensitive: 'raw' })).toContain('sensitive')
    expect(projectionStateMessage('stale', 'ar')).toContain('قديمة')
    expect(projectionStateMessage('conflict', 'en')).toContain('conflicts')
  })
})
