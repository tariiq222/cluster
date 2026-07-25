// @vitest-environment jsdom

import { act, cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { DocumentRecord } from '../../api/documents'
import { DocumentsWorkspace } from './DocumentsWorkspace'

const getDocumentRecord = vi.fn()
const listDocumentRecordVersions = vi.fn()
const listDocumentRecordLinks = vi.fn()

vi.mock('../../api/documents', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/documents')>()
  return {
    ...actual,
    getDocumentRecord: (...args: unknown[]) => getDocumentRecord(...args),
    listDocumentRecordVersions: (...args: unknown[]) => listDocumentRecordVersions(...args),
    listDocumentRecordLinks: (...args: unknown[]) => listDocumentRecordLinks(...args),
  }
})

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((resolvePromise) => { resolve = resolvePromise })
  return { promise, resolve }
}

function document(id: string, title: string): DocumentRecord {
  return {
    id,
    resource_type: 'document',
    title,
    status: 'active',
    classification: 'internal',
    lock_version: 1,
    created_at: '2026-07-25T00:00:00Z',
    updated_at: '2026-07-25T00:00:00Z',
  }
}

const emptyPage = { items: [], next_cursor: null }

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('DocumentsWorkspace request ordering', () => {
  it('ignores a detail response superseded by navigation to another document', async () => {
    const firstDocument = deferred<DocumentRecord>()
    getDocumentRecord
      .mockImplementationOnce(() => firstDocument.promise)
      .mockResolvedValueOnce(document('doc-2', 'Current document'))
    listDocumentRecordVersions.mockResolvedValue(emptyPage)
    listDocumentRecordLinks.mockResolvedValue(emptyPage)

    const view = render(
      <DocumentsWorkspace locale="en" token="csrf" documentId="doc-1" onNavigate={vi.fn()} />,
    )
    view.rerender(
      <DocumentsWorkspace locale="en" token="csrf" documentId="doc-2" onNavigate={vi.fn()} />,
    )

    expect(await screen.findByRole('heading', { name: 'Current document' })).toBeTruthy()
    await act(async () => { firstDocument.resolve(document('doc-1', 'Stale document')) })

    expect(screen.queryByRole('heading', { name: 'Stale document' })).toBeNull()
    expect(screen.getByRole('heading', { name: 'Current document' })).toBeTruthy()
  })
})
