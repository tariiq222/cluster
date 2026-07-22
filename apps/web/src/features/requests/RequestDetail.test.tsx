// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { ApiError } from '../../api'
import { RequestDetail } from './RequestDetail'
import * as documentsApi from '../../api/documents'
import * as r1Api from '../../api/r1'

const record = {
  id: 'record-1',
  payload: { title: 'طلب تجريبي', description: 'تفاصيل' },
  status: 'draft',
  classification: 'internal',
  lock_version: 1,
  created_at: '2026-07-01T00:00:00Z',
  updated_at: '2026-07-01T00:00:00Z',
  allowed_actions: ['submit'],
  decision_id: null,
} as never

describe('RequestDetail document picker', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('loads documents via listDocumentRecords, selects one in the picker, and links it', async () => {
    vi.spyOn(documentsApi, 'listDocumentRecords').mockResolvedValue({
      items: [
        { id: 'doc-draft', title: 'Policy draft', status: 'draft', current_version_id: 'v0', allowed_actions: ['read'], lock_version: 1 },
        { id: 'doc-1', title: 'Incident log', status: 'active', current_version_id: 'v1', allowed_actions: ['link'], lock_version: 1 },
      ],
      next_cursor: null,
    } as never)
    const linkDocument = vi.spyOn(r1Api, 'linkDocument').mockResolvedValue({ id: 'record-1' } as never)
    const onRetry = vi.fn()

    render(<RequestDetail locale="en" token="tok" record={record} loading={false} state="ready" onRetry={onRetry} />)
    await screen.findByText('Incident log')
    expect(screen.queryByText('Policy draft')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Attach' }))

    await waitFor(() => expect(linkDocument).toHaveBeenCalledWith('tok', 'record-1', 'doc-1'))
    expect(onRetry).toHaveBeenCalledTimes(1)
  })

  it('surfaces a user-facing state when document listing is forbidden', async () => {
    vi.spyOn(documentsApi, 'listDocumentRecords').mockRejectedValue(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))
    render(<RequestDetail locale="en" token="tok" record={record} loading={false} state="ready" onRetry={vi.fn()} />)

    await expect(screen.findByText('You do not have permission to view available documents.')).resolves.toBeTruthy()
  })

  it('loads documents after a loading-to-ready rerender without violating hook order', async () => {
    vi.spyOn(documentsApi, 'listDocumentRecords').mockResolvedValue({
      items: [{ id: 'doc-1', title: 'Policy draft', status: 'active', current_version_id: 'v1', allowed_actions: ['link'], lock_version: 1 }],
      next_cursor: null,
    } as never)
    const onRetry = vi.fn()
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const renderResult = render(
      <RequestDetail locale="en" token="tok" record={record} loading={true} state="ready" onRetry={onRetry} />,
    )

    expect(() =>
      renderResult.rerender(<RequestDetail locale="en" token="tok" record={record} loading={false} state="ready" onRetry={onRetry} />),
    ).not.toThrow()

    await screen.findByText('Policy draft')
    expect(consoleError).not.toHaveBeenCalledWith(
      expect.stringContaining('Rendered fewer hooks than during the previous render'),
    )
    expect(consoleError).not.toHaveBeenCalledWith(
      expect.stringContaining('Rendered more hooks than during previous render'),
    )
    expect(consoleError).not.toHaveBeenCalledWith(
      expect.stringContaining('React has detected a change in the order of Hooks'),
    )

    consoleError.mockRestore()
  })

  it('loads every cursor page before building available document options', async () => {
    const list = vi.spyOn(documentsApi, 'listDocumentRecords')
      .mockResolvedValueOnce({ items: [], next_cursor: 'page-2' } as never)
      .mockResolvedValueOnce({ items: [{ id: 'doc-2', title: 'Second page', status: 'active', current_version_id: 'v2', allowed_actions: ['link'] }], next_cursor: null } as never)
    render(<RequestDetail locale="en" token="tok" record={record} loading={false} state="ready" onRetry={vi.fn()} />)
    await screen.findByText('Second page')
    expect(list).toHaveBeenNthCalledWith(1, { limit: 100, cursor: undefined })
    expect(list).toHaveBeenNthCalledWith(2, { limit: 100, cursor: 'page-2' })
  })

  it('renders the document picker alongside an authorized record projection', async () => {
    vi.spyOn(documentsApi, 'listDocumentRecords').mockResolvedValue({
      items: [{ id: 'doc-1', title: 'Authorized document', status: 'active', current_version_id: 'v1', allowed_actions: ['link'] }],
      next_cursor: null,
    } as never)
    const authorizedRecord = {
      id: 'record-1', record_number: 'WR-1', work_type_version_id: 'version-1', owner: {}, status: 'draft', classification: 'internal',
      payload: { title: 'Authorized request' }, lock_version: 1, created_at: '2026-07-01T00:00:00Z', updated_at: '2026-07-01T00:00:00Z',
      decision_id: 'decision-1', allowed_actions: ['submit'], field_access: { '*': 'readonly' },
    } as never
    render(<RequestDetail locale="en" token="tok" record={record} loading={false} state="ready" authorizedRecord={authorizedRecord} onRetry={vi.fn()} />)
    expect(await screen.findByText('Authorized document')).toBeTruthy()
    expect(document.getElementById('record-documents-heading')).toBeTruthy()
  })
})
