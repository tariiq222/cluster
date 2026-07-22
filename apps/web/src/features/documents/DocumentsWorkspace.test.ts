import { describe, expect, it } from 'vitest'
import type { DocumentRecord } from '../../api/documents'
import { isDocumentActionAllowed } from './DocumentsWorkspace'

describe('document action gating', () => {
  it('defaults a resource without allowed_actions to read-only', () => {
    const record = { id: 'doc-1' } as unknown as DocumentRecord
    expect(isDocumentActionAllowed(record, 'update')).toBe(false)
    expect(isDocumentActionAllowed(record, 'add-version')).toBe(false)
    expect(isDocumentActionAllowed(record, 'link')).toBe(false)
    expect(isDocumentActionAllowed(record, 'grant')).toBe(false)
    expect(isDocumentActionAllowed(record, 'archive')).toBe(false)
  })

  it('accepts only exact backend action names', () => {
    const record = { id: 'doc-1', allowed_actions: ['update', 'add-version', 'link', 'grant', 'archive'] } as unknown as DocumentRecord
    for (const action of ['update', 'add-version', 'link', 'grant', 'archive']) {
      expect(isDocumentActionAllowed(record, action)).toBe(true)
    }
    expect(isDocumentActionAllowed(record, 'write')).toBe(false)
  })
})
