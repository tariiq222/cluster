import { afterEach, describe, expect, it, vi } from 'vitest'
import { linkDocumentRecord, listDocumentRecords, transitionDocumentRecord } from './documents'

afterEach(() => vi.unstubAllGlobals())

function requireFetchCall(fetchMock: { mock: { calls: Parameters<typeof fetch>[] } }, index: number): Parameters<typeof fetch> {
  const call = fetchMock.mock.calls[index]
  if (!call) throw new Error(`Expected fetch call ${index + 1}`)
  return call
}

describe('document API wrappers', () => {
  it('uses generated list operation with classification and cursor', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ data: { items: [{ id: 'doc-1' }], next_cursor: 'next' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await expect(listDocumentRecords({ classification: 'internal', cursor: 'c1' })).resolves.toMatchObject({ next_cursor: 'next' })
    const [path] = requireFetchCall(fetchMock, 0)
    expect(String(path)).toContain('/api/v1/documents?')
    expect(String(path)).toContain('classification=internal')
  })

  it('preserves stale transition responses for conflict handling', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ title: 'stale', status: 412 }), { status: 412, headers: { 'Content-Type': 'application/problem+json' } })))
    await expect(transitionDocumentRecord('csrf', 'doc-1', 'archive', { reason: 'test' }, 2)).rejects.toMatchObject({ status: 412 })
  })

  it('sends If-Match when linking a document', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'link-1' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await linkDocumentRecord('csrf', 'doc-1', { source: { source_module: 'requests', record_type: 'request', record_id: 'record-1' }, relation_type: 'related' }, 7)
    expect(new Headers(requireFetchCall(fetchMock, 0)[1]?.headers).get('If-Match')).toBe('"7"')
  })
})
