import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createAuditExport,
  downloadAuditExport,
  listAuditEvents,
  verifyAuditIntegrity,
} from './audit'

const token = 'csrf-token'
const exportId = '01980f50-5f0d-7000-8000-000000000901'

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function requiredAt<T>(values: readonly T[], index: number): T {
  const value = values[index]
  if (value === undefined) throw new Error(`Expected value at index ${index}`)
  return value
}

afterEach(() => vi.unstubAllGlobals())

describe('Audit API boundary', () => {
  it('lists filtered events through the generated client without mutation headers', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      listAuditEvents(token, {
        source_module: 'documents',
        action: 'document.uploaded',
        classification: 'confidential',
      }),
    ).resolves.toEqual({ items: [], next_cursor: null })

    const [url, init] = requiredAt(fetchMock.mock.calls, 0)
    expect(url).toBe(
      '/api/v1/audit/events?limit=50&source_module=documents&action=document.uploaded&classification=confidential',
    )
    const headers = new Headers(init?.headers)
    expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f-]+$/)
    expect(headers.get('X-CSRF-Token')).toBeNull()
    expect(headers.get('Idempotency-Key')).toBeNull()
  })

  it('creates a reason-bound export with CSRF and idempotency', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(
        jsonResponse({ id: exportId, format: 'csv', status: 'ready' }, 201),
      )
    vi.stubGlobal('fetch', fetchMock)

    await createAuditExport(token, {
      format: 'csv',
      reason: 'Quarterly compliance review',
      filters: { source_module: 'documents' },
    })

    const [, init] = requiredAt(fetchMock.mock.calls, 0)
    const headers = new Headers(init?.headers)
    expect(headers.get('X-CSRF-Token')).toBe(token)
    expect(headers.get('Idempotency-Key')).toMatch(/^audit-export-/)
    expect(JSON.parse(String(init?.body))).toEqual({
      format: 'csv',
      reason: 'Quarterly compliance review',
      filters: { source_module: 'documents' },
    })
  })

  it('verifies a bounded stream with an independently scoped idempotency key', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValueOnce(
      jsonResponse(
        {
          stream_key: 'documents:document:01980f50-5f0d-7000-8000-000000000902',
          first_sequence: 1,
          last_sequence: 8,
          verified_event_count: 8,
          integrity_status: 'verified',
          checkpoint_id: exportId,
        },
        201,
      ),
    )
    vi.stubGlobal('fetch', fetchMock)

    await verifyAuditIntegrity(token, {
      stream_key: 'documents:document:01980f50-5f0d-7000-8000-000000000902',
      first_sequence: 1,
      last_sequence: 8,
    })

    const [, init] = requiredAt(fetchMock.mock.calls, 0)
    const headers = new Headers(init?.headers)
    expect(headers.get('X-CSRF-Token')).toBe(token)
    expect(headers.get('Idempotency-Key')).toMatch(/^audit-integrity-verify-/)
  })

  it('preserves a successful CSV body and server filename', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValueOnce(
        new Response('event_id,action\n1,document.uploaded\n', {
          status: 200,
          headers: {
            'Content-Type': 'text/csv; charset=UTF-8',
            'Content-Disposition': 'attachment; filename="audit-july.csv"',
          },
        }),
      ),
    )

    const download = await downloadAuditExport(token, exportId)

    expect(download.filename).toBe('audit-july.csv')
    await expect(download.blob.text()).resolves.toContain('document.uploaded')
  })
})
