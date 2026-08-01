// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { UploadVersionSheet } from './UploadVersionSheet'

const document = { id: 'd1', classification: 'internal' as const }

vi.mock('../../api/generated/cluster', () => ({
  initiateDocumentUpload: vi.fn().mockResolvedValue({
    status: 201,
    data: { upload_id: 'u1', upload_url: 'https://storage.example/put' },
    headers: new Headers(),
  }),
  getDocumentUploadStatus: vi.fn().mockResolvedValue({
    status: 200,
    data: { scan_status: 'clean' },
    headers: new Headers(),
  }),
  completeDocumentUpload: vi.fn().mockResolvedValue({
    status: 200,
    data: { accepted: true, failure_codes: [] },
    headers: new Headers(),
  }),
}))

vi.mock('../../api/http', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    customFetch: vi.fn().mockResolvedValue({ status: 200, data: {}, headers: new Headers() }),
  }
})

vi.mock('../../app/session-context', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, useLocale: () => 'ar' as const, useSessionToken: () => 'csrf' }
})

describe('upload sheet', () => {
  it('presents initiate, upload and complete as one progress affordance', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <UploadVersionSheet document={document} open onOpenChange={() => {}} />
      </QueryClientProvider>,
    )
    const file = new File(['hello'], 'note.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByLabelText('الملف'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'رفع' }))
    await waitFor(() => expect(screen.getAllByRole('progressbar').length).toBe(1))
    expect(screen.queryByRole('button', { name: /التالي|السابق|next|back/i })).toBeNull()
  })
})
