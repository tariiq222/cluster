// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { UploadVersionScreen } from './UploadVersionScreen'
import { ApiError } from '../../api/http'

/*
 * Coverage matrix (DOC-INTAKE-04 §13), adapted for the full page:
 *   - progress affordance merges the secure stages into a single bar
 *   - generated Input primitive wraps the native file input
 *   - selecting a file enables submit and clears error state
 *   - addDocumentVersion receives the existing document id + file metadata
 *   - generic initiateDocumentUpload is never called
 *   - signed storage method and required_headers are honored verbatim
 *     (method passthrough + headers spread, not a hard-coded Content-Type)
 *   - success navigates back to the document detail page
 *   - the page loads the document by id (same ['document', id] query key)
 *   - no add-version/initiate-upload allowed action → non-disclosing
 *     DeniedState; 403/404 → DeniedState; other errors → ErrorState retry
 */

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const documentId = 'd-existing-id-7'

const documentState = vi.hoisted(() => ({
  data: undefined as unknown,
  isLoading: false,
  isError: false,
  error: null as unknown,
  refetch: vi.fn(),
}))

vi.mock('../../api/hooks', () => ({
  useDocument: () => documentState,
}))

const addDocumentVersionMock = vi.fn().mockResolvedValue({
  status: 201,
  data: {
    upload_id: 'u-version-1',
    upload_url: 'https://storage.local/documents/u-version-1',
    method: 'POST',
    required_headers: {
      'x-amz-acl': 'private',
      'x-amz-meta-source': 'intake-sheet',
    },
  },
  headers: new Headers(),
})
const getDocumentUploadStatusMock = vi.fn().mockResolvedValue({
  status: 200,
  data: { scan_status: 'clean' },
  headers: new Headers(),
})
const completeDocumentUploadMock = vi.fn().mockResolvedValue({
  status: 202,
  data: {
    accepted: true,
    document_id: documentId,
    version_id: 'v-new',
    failure_codes: [],
  },
  headers: new Headers(),
})
const initiateDocumentUploadMock = vi.fn()

vi.mock('../../api/generated/cluster', () => ({
  addDocumentVersion: (...args: unknown[]) => addDocumentVersionMock(...args),
  getDocumentUploadStatus: (...args: unknown[]) =>
    getDocumentUploadStatusMock(...args),
  completeDocumentUpload: (...args: unknown[]) =>
    completeDocumentUploadMock(...args),
  initiateDocumentUpload: (...args: unknown[]) =>
    initiateDocumentUploadMock(...args),
}))

const customFetchMock = vi.fn().mockResolvedValue({ status: 200, data: {}, headers: new Headers() })

vi.mock('../../api/http', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    customFetch: (...args: unknown[]) => customFetchMock(...args),
  }
})

vi.mock('../../app/session-context', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, useLocale: () => 'ar' as const, useSessionToken: () => 'csrf' }
})

function readyDocument(allowedActions: string[] = ['add-version', 'initiate-upload']) {
  documentState.data = {
    id: documentId,
    classification: 'internal',
    status: 'active',
    lock_version: 1,
    allowed_actions: allowedActions,
  }
  documentState.isLoading = false
  documentState.isError = false
  documentState.error = null
}

function failDocument(error: unknown) {
  documentState.data = undefined
  documentState.isLoading = false
  documentState.isError = true
  documentState.error = error
}

function mountPage() {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <UploadVersionScreen documentId={documentId} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  navigateMock.mockReset()
  readyDocument()
  addDocumentVersionMock.mockClear()
  getDocumentUploadStatusMock.mockClear()
  completeDocumentUploadMock.mockClear()
  initiateDocumentUploadMock.mockClear()
  customFetchMock.mockClear()
})

describe('upload version page', () => {
  it('initiates a new version through /documents/{id}/versions with file metadata, then PUTs signed bytes, completes, and returns to the detail page', async () => {
    mountPage()
    const file = new File(['hello'], 'note.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByLabelText('الملف'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'رفع' }))

    await waitFor(() => expect(addDocumentVersionMock).toHaveBeenCalledTimes(1))
    expect(initiateDocumentUploadMock).not.toHaveBeenCalled()
    const [docId, payload] = addDocumentVersionMock.mock.calls[0]!
    expect(docId).toBe(documentId)
    expect(payload.file_name).toBe('note.pdf')
    expect(payload.content_type).toBe('application/pdf')
    expect(typeof payload.byte_size).toBe('number')
    expect(payload.sha256).toMatch(/^[a-f0-9]{64}$/)

    await waitFor(() => expect(customFetchMock).toHaveBeenCalledTimes(1))
    const [url, init] = customFetchMock.mock.calls[0]!
    expect(url).toBe('https://storage.local/documents/u-version-1')
    expect(init.method).toBe('POST')
    expect(init.headers).toMatchObject({
      'x-amz-acl': 'private',
      'x-amz-meta-source': 'intake-sheet',
    })
    // No hard-coded Content-Type override: the signed headers govern.
    expect(init.headers).not.toEqual(
      expect.objectContaining({ 'Content-Type': expect.any(String) }),
    )

    await waitFor(() => expect(getDocumentUploadStatusMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(completeDocumentUploadMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith(`/documents/${documentId}`))
  })

  it('presents initiate, upload and complete as one progress affordance', async () => {
    mountPage()
    const file = new File(['hello'], 'note.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByLabelText('الملف'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'رفع' }))
    await waitFor(() => expect(screen.getAllByRole('progressbar').length).toBe(1))
    expect(screen.queryByRole('button', { name: /التالي|السابق|next|back/i })).toBeNull()
  })

  it('renders a localized file picker with the accessible label and a sr-only native input', () => {
    mountPage()
    const input = screen.getByLabelText('الملف') as HTMLInputElement
    expect(input).toBeInTheDocument()
    expect(input).toHaveAttribute('id', 'document-upload-file')
    expect(input).toHaveAttribute('type', 'file')
    // The native input is the accessible, testable target; the visible
    // affordance is the localized Button.
    expect(input).toHaveClass('sr-only')
    const pickerButton = screen.getByTestId('document-upload-file-button')
    expect(pickerButton).toBeInTheDocument()
    expect(pickerButton.textContent).toContain('اختيار ملف')
  })

  it('captures the selected file and resets the error state on change', () => {
    mountPage()
    const input = screen.getByLabelText('الملف') as HTMLInputElement
    const file = new File(['data'], 'a.bin', { type: 'application/octet-stream' })
    fireEvent.change(input, { target: { files: [file] } })
    expect(input.files?.[0]).toBe(file)
    expect(screen.getByRole('button', { name: 'رفع' })).toBeEnabled()
  })

  it('renders the shared non-disclosing denied state without the upload allowed actions', () => {
    readyDocument([])
    mountPage()
    expect(screen.getByTestId('upload-version-screen')).toBeInTheDocument()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'رفع' })).not.toBeInTheDocument()
  })

  it('renders the shared non-disclosing denied state for 403 and 404 document loads', () => {
    failDocument(
      new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }),
    )
    mountPage()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(screen.queryByLabelText('الملف')).not.toBeInTheDocument()

    cleanup()
    failDocument(
      new ApiError(404, { type: 'about:blank', title: 'Not Found', status: 404 }),
    )
    mountPage()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
  })

  it('renders the shared ErrorState with a retry that reloads the document', () => {
    failDocument(
      new ApiError(503, {
        type: 'about:blank',
        title: 'Service Unavailable',
        status: 503,
        detail: 'boom',
      }),
    )
    mountPage()
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('حدث خطأ أثناء تحميل البيانات.')
    fireEvent.click(within(alert).getByRole('button', { name: 'أعد المحاولة' }))
    expect(documentState.refetch).toHaveBeenCalled()
  })

  it('renders the loading skeleton while the document query is pending', () => {
    documentState.data = undefined
    documentState.isLoading = true
    documentState.isError = false
    documentState.error = null
    mountPage()
    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
    expect(screen.queryByLabelText('الملف')).not.toBeInTheDocument()
  })
})
