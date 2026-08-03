// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { ImportWizard } from './ImportWizard'
import { ReviewStep } from './steps/ReviewStep'
import { SessionProvider } from '../../app/session-context'

const { uploadOrganizationImportFileMock, submitOrganizationImportMock } = vi.hoisted(() => ({
  uploadOrganizationImportFileMock: vi.fn(),
  submitOrganizationImportMock: vi.fn(),
}))

vi.mock('../../api/generated/cluster', () => ({
  uploadOrganizationImportFile: (...args: unknown[]) => uploadOrganizationImportFileMock(...args),
  submitOrganizationImport: (...args: unknown[]) => submitOrganizationImportMock(...args),
  getOrganizationImport: vi.fn(),
  listOrganizationImportRows: vi.fn(),
  transitionOrganizationImport: vi.fn(),
}))

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const rows = [
  { id: 'r1', row_number: 1, proposed_action: 'create', decision: null, validation_errors: [] },
  { id: 'r2', row_number: 2, proposed_action: 'create', decision: null, validation_errors: [] },
  { id: 'r3', row_number: 3, proposed_action: 'create', decision: null, validation_errors: [] },
  {
    id: 'r4',
    row_number: 4,
    proposed_action: 'create',
    decision: null,
    validation_errors: [{ code: 'missing-field', severity: 'blocking', field: 'name_ar' }],
  },
]

const QUARANTINE_ID = '018f2f64-8c2a-7a11-8a3d-123456789abc'
const REPLACEMENT_QUARANTINE_ID = '018f2f64-8c2a-7a11-8a3d-abcdefabcdef'

function response(data: unknown, status = 200) {
  return { data, status, headers: new Headers() }
}

function submitHeaders(callIndex: number): Record<string, string> {
  return submitOrganizationImportMock.mock.calls[callIndex]?.[1]?.headers as Record<string, string>
}

function renderWizard() {
  return render(
    <SessionProvider session={session} locale="en" setLocale={() => {}}>
      <ImportWizard />
    </SessionProvider>,
  )
}

async function uploadWithTemplate(templateName: string, quarantineId = QUARANTINE_ID) {
  uploadOrganizationImportFileMock.mockResolvedValueOnce(
    response({ quarantine_object_id: quarantineId }, 201),
  )
  renderWizard()

  fireEvent.click(screen.getByRole('combobox', { name: 'Data type' }))
  fireEvent.click(await screen.findByRole('option', { name: templateName }))
  fireEvent.change(screen.getByLabelText('Data file'), {
    target: { files: [new File(['name\nvalue'], 'organization.csv', { type: 'text/csv' })] },
  })
  fireEvent.click(screen.getByRole('button', { name: 'Upload file' }))

  await waitFor(() => expect(screen.getByLabelText('File reference')).toHaveValue(quarantineId))
}

beforeEach(() => {
  uploadOrganizationImportFileMock.mockReset()
  submitOrganizationImportMock.mockReset()
})

describe('import upload and submission', () => {
  it('submits the exact uploaded template and prevents duplicate submission while pending', async () => {
    let resolveSubmission!: (value: unknown) => void
    submitOrganizationImportMock.mockImplementation(
      () => new Promise((resolve) => {
        resolveSubmission = resolve
      }),
    )
    await uploadWithTemplate('Facilities')

    const submit = screen.getByRole('button', { name: 'Start review' })
    fireEvent.click(submit)

    await waitFor(() => expect(submitOrganizationImportMock).toHaveBeenCalledTimes(1))
    expect(submitOrganizationImportMock.mock.calls[0]?.[0]).toMatchObject({
      quarantine_object_id: QUARANTINE_ID,
      template_code: 'facilities',
      import_type: 'csv',
    })
    expect(submitHeaders(0)['Idempotency-Key']).toMatch(/^import-submit-/)
    expect(screen.getByRole('button', { name: 'Executing…' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Executing…' }))
    expect(submitOrganizationImportMock).toHaveBeenCalledTimes(1)

    resolveSubmission(response({}, 409))
    expect(await screen.findByRole('alert')).toHaveTextContent('The review could not be started. Try again.')
  })

  it('keeps submit strictly disabled until an upload succeeds, blocking manual reference edits from bypassing the gate', async () => {
    renderWizard()

    // The submit button must be disabled until the latest upload succeeded
    // and the visible selection still matches the artifact — there is no
    // manual stale reference bypass.
    const submit = screen.getByRole('button', { name: 'Start review' })
    expect(submit).toBeDisabled()

    // Editing the file reference field cannot enable submission on its own.
    fireEvent.change(screen.getByLabelText('File reference'), { target: { value: 'not-a-uuid' } })
    expect(submit).toBeDisabled()

    // The disabled button ignores the click; the submit handler cannot run
    // without a valid artifact behind it.
    fireEvent.click(submit)
    expect(submitOrganizationImportMock).not.toHaveBeenCalled()
  })

  it('shows a localized submission error and retains the reference and template for retry', async () => {
    submitOrganizationImportMock.mockResolvedValueOnce(response({}, 500))
    await uploadWithTemplate('Positions')

    fireEvent.click(screen.getByRole('button', { name: 'Start review' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The review could not be started. Try again.')
    expect(screen.getByLabelText('File reference')).toHaveValue(QUARANTINE_ID)
    const firstKey = submitHeaders(0)['Idempotency-Key']

    submitOrganizationImportMock.mockImplementationOnce(() => new Promise(() => {}))
    fireEvent.click(screen.getByRole('button', { name: 'Start review' }))
    await waitFor(() => expect(submitOrganizationImportMock).toHaveBeenCalledTimes(2))
    expect(submitHeaders(1)['Idempotency-Key']).toBe(firstKey)
    expect(submitOrganizationImportMock.mock.calls[1]?.[0]).toMatchObject({
      quarantine_object_id: QUARANTINE_ID,
      template_code: 'positions',
    })
  })

  it('invalidates an uploaded artifact when the visible file changes', async () => {
    await uploadWithTemplate('Facilities')

    fireEvent.change(screen.getByLabelText('Data file'), {
      target: { files: [new File(['replacement'], 'replacement.csv', { type: 'text/csv' })] },
    })

    const submit = screen.getByRole('button', { name: 'Start review' })
    expect(submit).toBeDisabled()
    expect(screen.getByLabelText('File reference')).toHaveValue('')
    fireEvent.click(submit)
    expect(submitOrganizationImportMock).not.toHaveBeenCalled()
  })

  it('invalidates an uploaded artifact when the visible template changes', async () => {
    await uploadWithTemplate('Facilities')

    fireEvent.click(screen.getByRole('combobox', { name: 'Data type' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Positions' }))

    expect(screen.getByRole('button', { name: 'Start review' })).toBeDisabled()
    expect(screen.getByLabelText('File reference')).toHaveValue('')
  })

  it('invalidates the artifact before an active replacement upload can finish', async () => {
    await uploadWithTemplate('Facilities')
    let resolveReplacement!: (value: unknown) => void
    uploadOrganizationImportFileMock.mockImplementationOnce(
      () => new Promise((resolve) => {
        resolveReplacement = resolve
      }),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Upload file' }))
    await waitFor(() => expect(uploadOrganizationImportFileMock).toHaveBeenCalledTimes(2))
    expect(screen.getByRole('button', { name: 'Start review' })).toBeDisabled()
    expect(screen.getByLabelText('File reference')).toHaveValue('')
    expect(submitOrganizationImportMock).not.toHaveBeenCalled()

    resolveReplacement(response({ quarantine_object_id: REPLACEMENT_QUARANTINE_ID }, 201))
    await waitFor(() => expect(screen.getByLabelText('File reference')).toHaveValue(REPLACEMENT_QUARANTINE_ID))
    expect(screen.getByRole('button', { name: 'Start review' })).toBeEnabled()
  })

  it('keeps a failed replacement invalid while retaining the selected inputs and error', async () => {
    await uploadWithTemplate('Facilities')
    uploadOrganizationImportFileMock.mockRejectedValueOnce(new Error('network unavailable'))

    fireEvent.click(screen.getByRole('button', { name: 'Upload file' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The file could not be uploaded. Try again.')
    expect(screen.getByRole('button', { name: 'Start review' })).toBeDisabled()
    expect(screen.getByLabelText('File reference')).toHaveValue('')
    expect((screen.getByLabelText('Data file') as HTMLInputElement).files?.[0]?.name).toBe('organization.csv')
    expect(screen.getByRole('combobox', { name: 'Data type' })).toHaveTextContent('Facilities')
  })

  it('uses a new submit idempotency key for a new uploaded artifact', async () => {
    submitOrganizationImportMock.mockResolvedValue(response({}, 409))
    await uploadWithTemplate('Facilities')

    fireEvent.click(screen.getByRole('button', { name: 'Start review' }))
    await waitFor(() => expect(submitOrganizationImportMock).toHaveBeenCalledTimes(1))
    expect(await screen.findByRole('alert')).toHaveTextContent('The review could not be started. Try again.')
    const firstKey = submitHeaders(0)['Idempotency-Key']

    uploadOrganizationImportFileMock.mockResolvedValueOnce(
      response({ quarantine_object_id: REPLACEMENT_QUARANTINE_ID }, 201),
    )
    fireEvent.change(screen.getByLabelText('Data file'), {
      target: { files: [new File(['replacement'], 'replacement.csv', { type: 'text/csv' })] },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Upload file' }))
    await waitFor(() => expect(screen.getByLabelText('File reference')).toHaveValue(REPLACEMENT_QUARANTINE_ID))

    fireEvent.click(screen.getByRole('button', { name: 'Start review' }))
    await waitFor(() => expect(submitOrganizationImportMock).toHaveBeenCalledTimes(2))
    expect(submitHeaders(1)['Idempotency-Key']).not.toBe(firstKey)
    expect(submitOrganizationImportMock.mock.calls[1]?.[0]).toMatchObject({
      quarantine_object_id: REPLACEMENT_QUARANTINE_ID,
      template_code: 'facilities',
    })
  })
})

describe('import review step', () => {
  it('filters to blocking rows by default on the review step', () => {
    render(
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <ReviewStep
          rows={rows}
          status="validated"
          onTransition={() => {}}
        />
      </SessionProvider>,
    )
    // DESIGN-RULES §3.3: row numbers render with Latin digits (0-9) in both
    // locales; formatNumber uses `ar-SA-u-nu-latn`, not Arabic-Indic digits.
    expect(screen.queryByText('رقم السجل 1')).toBeNull()
    expect(screen.queryByText('رقم السجل 2')).toBeNull()
    expect(screen.queryByText('رقم السجل 3')).toBeNull()
    expect(screen.getByText('رقم السجل 4')).toBeInTheDocument()
    expect(screen.getByText(/missing-field/)).toBeInTheDocument()
    const showAll = screen.getByRole('button', { name: /عرض الكل|show all/i })
    fireEvent.click(showAll)
    expect(screen.getByText('رقم السجل 1')).toBeInTheDocument()
    expect(screen.getByText('رقم السجل 3')).toBeInTheDocument()
  })
})
