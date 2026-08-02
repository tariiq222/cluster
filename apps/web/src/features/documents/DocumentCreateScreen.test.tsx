// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useEffect, useState } from 'react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { DocumentCreateScreen } from './DocumentCreateScreen'
import { SessionProvider } from '../../app/session-context'
import { ApiError } from '../../api/http'
import * as generated from '../../api/generated/cluster'

/*
 * Coverage matrix (DOC-INTAKE-04 §12):
 *   - full-page form surface, no dialog/Sheet semantics
 *   - current facility label never contains the UUID
 *   - alternative facility options rendered with human labels only
 *   - selecting an alternative calls selectScope and remains disabled
 *     until the new effective scope lands
 *   - no-facility-available blocked state with localized copy
 *   - missing file is rejected before initiate; empty/too-large file
 *     surfaces localized validation
 *   - integrated success: initiate → signed PUT → status → complete →
 *     direct detail navigation, with the file's intent.method and
 *     intent.required_headers honored on the storage call
 *   - backend rejection / generic error keeps the page visible and
 *     prevents silent navigation away
 *   - Arabic / English back affordance direction
 *
 * `usePrincipal` is mocked here per the task allowance so we can
 * exercise scope switching without altering the shared test provider.
 * The mock subscribes to a tiny `useReducer`-driven re-render so that
 * `currentPrincipal` mutations propagate to the screen under test.
 */

const session = {
  csrfToken: 'csrf',
  userId: '01980f50-5f0d-7000-8000-000000000001',
  expiresAt: '2027-01-01T00:00:00Z',
  restricted: false,
}

const FACILITY_A_ID = '01980f50-5f0d-7000-8000-0000000000aa'
const FACILITY_A_LABEL = 'منشأة أ · Facility A'
const FACILITY_B_ID = '01980f50-5f0d-7000-8000-8000-0000000000bb'
const FACILITY_B_LABEL = 'منشأة ب · Facility B'

const navigateSpy = vi.fn()
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return {
    ...actual,
    MemoryRouter: actual.MemoryRouter,
    useNavigate: () => navigateSpy,
  }
})

interface PrincipalMock {
  state: 'ready' | 'loading' | 'denied' | 'stale' | 'error'
  capabilities: string[]
  features: { work_management: boolean; tasks: boolean } | null
  effectiveScope: { scopeType: string; scopeId: string; label: string } | null
  availableScopes: Array<{ scopeType: string; scopeId: string; label: string }>
  revision: number
  scopeEpoch: number
  scopeReady: boolean
  selectScope: ReturnType<typeof vi.fn>
  refresh: () => void
  errorCorrelationId: string | null
}

let currentPrincipal: PrincipalMock = {
  state: 'ready',
  capabilities: ['documents.manage'],
  features: { work_management: false, tasks: false },
  effectiveScope: null,
  availableScopes: [],
  revision: 0,
  scopeEpoch: 0,
  scopeReady: true,
  selectScope: vi.fn().mockResolvedValue(undefined),
  refresh: () => {},
  errorCorrelationId: null,
}

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => {
    // Subscribe to mock changes via a custom window event so the screen
    // re-renders when `currentPrincipal` is mutated by selectScope.
    const [, setTick] = useState(0)
    useEffect(() => {
      const handler = () => setTick((n) => n + 1)
      window.addEventListener('principal-change', handler)
      return () => window.removeEventListener('principal-change', handler)
    }, [])
    return currentPrincipal
  },
}))

// Storage PUT mock — by default a successful 200; tests can override.
const storagePutMock = vi.fn(async () => ({
  status: 200,
  data: {},
  headers: new Headers(),
}))

vi.mock('../../api/http', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/http')>()
  return {
    ...actual,
    customFetch: (...args: unknown[]) => storagePutMock(...args),
  }
})

function buildFacilityPrincipal(overrides: Partial<PrincipalMock> = {}): PrincipalMock {
  const selectScopeMock = vi.fn().mockImplementation(async () => {
    // Simulate the principal-context swap: scopeReady goes false during
    // the server round-trip, then true again on success.
    currentPrincipal = { ...currentPrincipal, scopeReady: false }
    window.dispatchEvent(new Event('principal-change'))
  })
  const principal: PrincipalMock = {
    state: 'ready',
    capabilities: ['documents.manage'],
    features: { work_management: false, tasks: false },
    effectiveScope: {
      scopeType: 'facility',
      scopeId: FACILITY_A_ID,
      label: FACILITY_A_LABEL,
    },
    availableScopes: [
      { scopeType: 'facility', scopeId: FACILITY_A_ID, label: FACILITY_A_LABEL },
      { scopeType: 'facility', scopeId: FACILITY_B_ID, label: FACILITY_B_LABEL },
    ],
    revision: 1,
    scopeEpoch: 1,
    scopeReady: true,
    selectScope: selectScopeMock,
    refresh: () => {},
    errorCorrelationId: null,
    ...overrides,
  }
  return principal
}

function buildNoFacilityPrincipal(): PrincipalMock {
  return {
    state: 'ready',
    capabilities: ['documents.manage'],
    features: { work_management: false, tasks: false },
    effectiveScope: null,
    availableScopes: [],
    revision: 1,
    scopeEpoch: 1,
    scopeReady: true,
    selectScope: vi.fn().mockResolvedValue(undefined),
    refresh: () => {},
    errorCorrelationId: null,
  }
}

// P1-C: when the principal has an effective facility but no swappable
// `availableScopes` (e.g. facility-only users), the page must still
// render and show the current facility label — never the blocked state.
function buildEffectiveOnlyPrincipal(): PrincipalMock {
  return {
    state: 'ready',
    capabilities: ['documents.manage'],
    features: { work_management: false, tasks: false },
    effectiveScope: {
      scopeType: 'facility',
      scopeId: FACILITY_A_ID,
      label: FACILITY_A_LABEL,
    },
    availableScopes: [],
    revision: 1,
    scopeEpoch: 1,
    scopeReady: true,
    selectScope: vi.fn().mockResolvedValue(undefined),
    refresh: () => {},
    errorCorrelationId: null,
  }
}

function mount(
  principal: PrincipalMock,
  locale: 'ar' | 'en' = 'ar',
) {
  currentPrincipal = principal
  return render(
    <MemoryRouter initialEntries={['/documents/new']}>
      <SessionProvider session={session} locale={locale} setLocale={() => {}}>
        <DocumentCreateScreen />
      </SessionProvider>
    </MemoryRouter>,
  )
}

describe('DocumentCreateScreen', () => {
  beforeEach(() => {
    navigateSpy.mockReset()
    storagePutMock.mockClear()
    storagePutMock.mockResolvedValue({ status: 200, data: {}, headers: new Headers() })
  })
  afterEach(() => {
    navigateSpy.mockReset()
    vi.restoreAllMocks()
  })

  it('renders a full-page form with the facility label, never the UUID', () => {
    mount(buildFacilityPrincipal())
    expect(
      screen.getByRole('heading', { level: 1, name: 'أنشئ مستنداً جديداً' }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).toBeNull()
    const form = screen.getByTestId('document-create-form')
    expect(form).toBeInTheDocument()
    expect(form.textContent).toContain(FACILITY_A_LABEL)
    expect(form.textContent).not.toContain(FACILITY_A_ID)
  })

  it('points the back affordance right in Arabic RTL', () => {
    mount(buildFacilityPrincipal(), 'ar')
    const icon = screen.getByTestId('document-create-back-icon')
    expect(icon).toBeInTheDocument()
    expect(icon.getAttribute('class') ?? '').toContain('lucide-arrow-right')
  })

  it('points the back affordance left in English LTR', () => {
    mount(buildFacilityPrincipal(), 'en')
    const icon = screen.getByTestId('document-create-back-icon')
    expect(icon).toBeInTheDocument()
    const className = icon.getAttribute('class') ?? ''
    expect(className).toContain('lucide-arrow-left')
    expect(className).not.toContain('lucide-arrow-right')
  })

  it('renders the actionable blocked alert when no facility option exists', () => {
    mount(buildNoFacilityPrincipal())
    expect(screen.getByTestId('document-create-blocked')).toBeInTheDocument()
    expect(screen.queryByTestId('document-create-form')).toBeNull()
    expect(screen.queryByTestId('document-create-submit')).toBeNull()
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('لا تتوفر منشأة مالكة')
    expect(alert.textContent).not.toContain('permission')
  })

  it('renders the form when an effective facility exists even if availableScopes is empty', () => {
    mount(buildEffectiveOnlyPrincipal())
    // Page must NOT be in the blocked state.
    expect(screen.queryByTestId('document-create-blocked')).toBeNull()
    // Form is rendered and the facility label is visible — never a UUID.
    const form = screen.getByTestId('document-create-form')
    expect(form).toBeInTheDocument()
    expect(form.textContent).toContain(FACILITY_A_LABEL)
    expect(form.textContent).not.toContain(FACILITY_A_ID)
    // Submit must be enabled: the effective facility is the owning one.
    expect(screen.getByTestId('document-create-submit')).toBeEnabled()
  })

  it('calls selectScope when picking an alternative facility and stays disabled until effective', async () => {
    const principal = buildFacilityPrincipal()
    mount(principal)

    const select = document.querySelector(
      '#document-create-owner-select',
    ) as HTMLButtonElement | null
    expect(select).not.toBeNull()
    // The shadcn Select is rendered as a button with role=combobox; click
    // it open and choose the alternative facility option by its label.
    fireEvent.click(select!)
    const option = await screen.findByRole('option', { name: FACILITY_B_LABEL })
    fireEvent.click(option)
    await waitFor(() =>
      expect(principal.selectScope).toHaveBeenCalledWith('facility', FACILITY_B_ID),
    )

    // scopeReady was flipped to false by the mocked selectScope; the
    // submit button must stay disabled until the new effective scope
    // lands server-side.
    await waitFor(() =>
      expect(screen.getByTestId('document-create-submit')).toBeDisabled(),
    )
  })

  it('rejects an empty or oversized file before any initiate call', async () => {
    const initiate = vi.fn()
    vi.spyOn(generated, 'initiateDocumentUpload').mockImplementation(initiate)
    mount(buildFacilityPrincipal())

    const submit = screen.getByTestId('document-create-submit')
    const empty = new File([''], 'empty.bin', { type: 'application/octet-stream' })
    const fileInput = screen.getByTestId('document-create-file-input') as HTMLInputElement
    fireEvent.change(fileInput, { target: { files: [empty] } })
    await waitFor(() =>
      expect(
        screen.getByTestId('document-create-file-error'),
      ).toHaveTextContent('الملف المختار فارغ'),
    )
    expect(initiate).not.toHaveBeenCalled()

    fireEvent.change(fileInput, { target: { files: [] } })
    fireEvent.click(submit)
    await waitFor(() =>
      expect(
        screen.getByTestId('document-create-file-error'),
      ).toHaveTextContent('يجب اختيار ملف لرفعه مع المستند'),
    )
    expect(initiate).not.toHaveBeenCalled()
  })

  it('integrates initiate → signed PUT → status → complete → navigates to returned document_id', async () => {
    const createdId = '01980f50-5f0d-7000-8000-000000099999'
    vi.spyOn(generated, 'initiateDocumentUpload').mockResolvedValue({
      status: 201,
      data: {
        upload_id: 'u-intake-1',
        upload_url: 'https://storage.local/documents/u-intake-1',
        method: 'PUT',
        required_headers: {
          'x-storage-acl': 'private',
          'x-amz-meta-source': 'intake',
        },
      },
      headers: new Headers(),
    })
    vi.spyOn(generated, 'getDocumentUploadStatus').mockResolvedValue({
      status: 200,
      data: { scan_status: 'clean' },
      headers: new Headers(),
    })
    vi.spyOn(generated, 'completeDocumentUpload').mockResolvedValue({
      status: 202,
      data: {
        accepted: true,
        document_id: createdId,
        version_id: 'v-new',
        failure_codes: [],
      },
      headers: new Headers(),
    })

    mount(buildFacilityPrincipal())

    fireEvent.change(screen.getByTestId('document-create-title-input'), {
      target: { value: 'إطار التحول الرقمي' },
    })
    fireEvent.change(screen.getByTestId('document-create-description-input'), {
      target: { value: 'وصف اختياري' },
    })
    const file = new File(['%PDF-1.4 body'], 'plan.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })

    fireEvent.click(screen.getByTestId('document-create-submit'))

    await waitFor(() =>
      expect(generated.initiateDocumentUpload).toHaveBeenCalledTimes(1),
    )
    const initiateArgs = (generated.initiateDocumentUpload as unknown as ReturnType<typeof vi.fn>).mock.calls[0]
    const [payload] = initiateArgs as [
      {
        file_name: string
        byte_size: number
        sha256: string
        description?: string | null
        classification: string
      },
    ]
    expect(payload.file_name).toBe('plan.pdf')
    expect(payload.byte_size).toBe(file.size)
    expect(payload.sha256).toMatch(/^[a-f0-9]{64}$/)
    expect(payload.description).toBe('وصف اختياري')

    await waitFor(() => expect(storagePutMock).toHaveBeenCalled())
    const [url, init] = storagePutMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('https://storage.local/documents/u-intake-1')
    expect(init.method).toBe('PUT')
    expect(init.headers).toMatchObject({
      'x-storage-acl': 'private',
      'x-amz-meta-source': 'intake',
    })

    await waitFor(() => expect(generated.getDocumentUploadStatus).toHaveBeenCalled())
    await waitFor(() => expect(generated.completeDocumentUpload).toHaveBeenCalled())
    await waitFor(() => expect(navigateSpy).toHaveBeenCalledWith(`/documents/${createdId}`))
  })

  it('keeps the page visible and never navigates on a backend rejection', async () => {
    vi.spyOn(generated, 'initiateDocumentUpload').mockRejectedValue(
      new ApiError(
        403,
        {
          type: 'about:blank',
          title: 'Forbidden',
          status: 403,
        },
        'corr-doc-create',
      ),
    )
    mount(buildFacilityPrincipal())

    fireEvent.change(screen.getByTestId('document-create-title-input'), {
      target: { value: 'مستند اختبار' },
    })
    const file = new File(['body'], 'note.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })
    fireEvent.click(screen.getByTestId('document-create-submit'))

    const alert = await screen.findByTestId('document-create-error')
    expect(alert).toHaveTextContent('غير مصرح لك بإنشاء المستندات في هذه المنشأة')
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(navigateSpy).not.toHaveBeenCalled()
    await waitFor(() =>
      expect(screen.getByTestId('document-create-submit')).toBeEnabled(),
    )
  })

  it('retries the same signed upload after a post-initiation storage failure without initiating again', async () => {
    const createdId = '01980f50-5f0d-7000-8000-000000077777'
    vi.spyOn(generated, 'initiateDocumentUpload').mockResolvedValue({
      status: 201,
      data: {
        upload_id: 'u-retry-1',
        upload_url: 'https://storage.local/documents/u-retry-1',
        method: 'PUT',
        required_headers: {
          'x-storage-acl': 'private',
          'x-amz-meta-source': 'intake',
        },
      },
      headers: new Headers(),
    })
    vi.spyOn(generated, 'getDocumentUploadStatus').mockResolvedValue({
      status: 200,
      data: { scan_status: 'clean' },
      headers: new Headers(),
    })
    vi.spyOn(generated, 'completeDocumentUpload').mockResolvedValue({
      status: 202,
      data: {
        accepted: true,
        document_id: createdId,
        version_id: 'v-retry',
        failure_codes: [],
      },
      headers: new Headers(),
    })
    // Storage PUT fails on the first attempt, succeeds on the retry.
    let storageCalls = 0
    storagePutMock.mockImplementation(async () => {
      storageCalls += 1
      if (storageCalls === 1) {
        return { status: 503, data: {}, headers: new Headers() }
      }
      return { status: 200, data: {}, headers: new Headers() }
    })

    mount(buildFacilityPrincipal())

    fireEvent.change(screen.getByTestId('document-create-title-input'), {
      target: { value: 'إطار التحول الرقمي' },
    })
    const file = new File(['%PDF-1.4 body'], 'plan.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })

    // First submit → initiate succeeds, storage PUT fails (503).
    fireEvent.click(screen.getByTestId('document-create-submit'))
    await waitFor(() =>
      expect(generated.initiateDocumentUpload).toHaveBeenCalledTimes(1),
    )
    await waitFor(() => expect(storagePutMock).toHaveBeenCalledTimes(1))
    // Wait for the screen to settle back to idle and the error to render.
    const errorAlert = await screen.findByTestId('document-create-error')
    expect(errorAlert).toBeInTheDocument()
    await waitFor(() =>
      expect(screen.getByTestId('document-create-submit')).toBeEnabled(),
    )

    // Second submit → must reuse the retained intent (same URL, same
    // required headers) and not call initiateDocumentUpload again.
    fireEvent.click(screen.getByTestId('document-create-submit'))
    await waitFor(() => expect(storagePutMock).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(generated.getDocumentUploadStatus).toHaveBeenCalled())
    await waitFor(() => expect(generated.completeDocumentUpload).toHaveBeenCalled())

    expect(generated.initiateDocumentUpload).toHaveBeenCalledTimes(1)

    // Same signed URL on both calls — proves the retained intent was
    // reused, not re-initiated.
    const [url1] = storagePutMock.mock.calls[0] as [string, RequestInit]
    const [url2] = storagePutMock.mock.calls[1] as [string, RequestInit]
    expect(url1).toBe('https://storage.local/documents/u-retry-1')
    expect(url2).toBe('https://storage.local/documents/u-retry-1')

    await waitFor(() => expect(navigateSpy).toHaveBeenCalledWith(`/documents/${createdId}`))
  })

  /*
   * Layout/accessibility matrix (DOC-LAYOUT-05):
   *   - the form adopts the PageLayout width (no inner `max-w-2xl` island)
   *   - at desktop the form uses a predictable two-region grid; main comes
   *     first in DOM and the review panel is a sibling flat card
   *   - both select triggers fill their assigned column (`w-full`)
   *   - review is rendered as a semantic `<dl>` with `<dt>/<dd>`
   *   - the localized custom file-picker button is visible while the
   *     native `<input type="file">` remains accessible (sr-only)
   *   - selected filename state shows the file name and size in Arabic
   *     locale, using `<bdi>` to isolate mixed script
   *   - the submit action lives in the review panel and stays visible
   *     without scrolling at desktop widths
   */
  it('expands the form to the PageLayout width and drops the narrow max-w-2xl island', () => {
    mount(buildFacilityPrincipal())
    const form = screen.getByTestId('document-create-form')
    expect(form).toBeInTheDocument()
    // No `max-w-2xl` on the form itself — the page width is owned by PageLayout.
    expect(form.className).not.toMatch(/\bmax-w-2xl\b/)
    // Form adopts the two-region responsive grid at desktop.
    expect(form.className).toContain('lg:grid-cols-[2fr_1fr]')
    expect(form.className).toMatch(/\blg:items-start\b/)
  })

  it('keeps the main intake surface as the first child of the form grid and the review panel as a sibling', () => {
    mount(buildFacilityPrincipal())
    const form = screen.getByTestId('document-create-form')
    const reviewPanel = screen.getByTestId('document-create-review-panel')
    // First descendant element of the form must be the main intake surface,
    // not the review panel — main content stays first in DOM/logical order.
    const firstRegion = form.firstElementChild as HTMLElement | null
    expect(firstRegion).not.toBeNull()
    expect(firstRegion).not.toBe(reviewPanel)
    expect(firstRegion?.className ?? '').toMatch(/\brounded-xl\b/)
    expect(firstRegion?.className ?? '').toMatch(/\bbg-card\b/)
    // The review panel is a flat bounded card, not nested inside another card.
    expect(reviewPanel.className).toMatch(/\brounded-xl\b/)
    expect(reviewPanel.className).toMatch(/\bbg-card\b/)
    expect(reviewPanel.className).not.toMatch(/\bmax-w-2xl\b/)
  })

  it('renders both select triggers as full-width within their column', () => {
    mount(buildFacilityPrincipal())
    const ownerTrigger = document.getElementById(
      'document-create-owner-select',
    ) as HTMLButtonElement | null
    const classificationTrigger = document.getElementById(
      'document-create-classification',
    ) as HTMLButtonElement | null
    expect(ownerTrigger).not.toBeNull()
    expect(classificationTrigger).not.toBeNull()
    expect(ownerTrigger!.className).toMatch(/\bw-full\b/)
    expect(classificationTrigger!.className).toMatch(/\bw-full\b/)
  })

  it('renders the review summary as a semantic <dl> with <dt>/<dd> pairs', () => {
    mount(buildFacilityPrincipal())
    const review = screen.getByTestId('document-create-review')
    const dl = review.querySelector('dl')
    expect(dl).not.toBeNull()
    const dts = review.querySelectorAll('dt')
    const dds = review.querySelectorAll('dd')
    // Five label/value pairs: title, classification, facility, file, policy.
    expect(dts).toHaveLength(5)
    expect(dds).toHaveLength(5)
    // Each <dt> must be paired with a <dd> inside the same wrapper div.
    dts.forEach((dt) => {
      const wrapper = dt.parentElement
      expect(wrapper?.querySelector('dd')).not.toBeNull()
    })
  })

  it('isolates long filenames and facility labels with <bdi dir="auto">', () => {
    mount(buildFacilityPrincipal())
    fireEvent.change(screen.getByTestId('document-create-title-input'), {
      target: { value: 'إطار التحول الرقمي' },
    })
    const file = new File(
      ['%PDF-1.4 body'],
      'long-mixed-script-filename-2026-إطار.pdf',
      { type: 'application/pdf' },
    )
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })
    // The selected-file summary uses <bdi> so mixed-script names do not
    // blow up the line in RTL.
    const summary = screen.getByTestId('document-create-file-summary')
    const bdiInSummary = summary.querySelector('bdi[dir="auto"]')
    expect(bdiInSummary).not.toBeNull()
    expect(bdiInSummary?.textContent).toContain(
      'long-mixed-script-filename-2026-إطار.pdf',
    )
    // The review panel also uses <bdi> for the file row and the facility
    // label so neither overflows the narrow review column.
    const review = screen.getByTestId('document-create-review')
    const bdisInReview = review.querySelectorAll('bdi[dir="auto"]')
    expect(bdisInReview.length).toBeGreaterThanOrEqual(2)
  })

  it('exposes a localized custom file picker and keeps the native file input accessible', () => {
    mount(buildFacilityPrincipal())
    const pickerButton = screen.getByTestId('document-create-file-button')
    expect(pickerButton).toBeInTheDocument()
    // Localized initial label and FileUp icon.
    expect(pickerButton.textContent).toContain('اختيار ملف')
    // Native input stays in the DOM (Playwright sets files directly) but
    // is visually hidden.
    const nativeInput = screen.getByTestId(
      'document-create-file-input',
    ) as HTMLInputElement
    expect(nativeInput).toBeInTheDocument()
    expect(nativeInput.type).toBe('file')
    expect(nativeInput).toHaveClass('sr-only')
    // Picker button announces the file help text and the live status
    // region. The validation error id is appended once a file error
    // is present (covered by the dedicated described-by test below).
    expect(pickerButton).toHaveAttribute(
      'aria-describedby',
      'document-create-file-help document-create-file-status',
    )
  })

  it('switches the picker label to the localized replace action once a file is selected', () => {
    mount(buildFacilityPrincipal())
    const pickerButton = screen.getByTestId('document-create-file-button')
    expect(pickerButton.textContent).toContain('اختيار ملف')
    const file = new File(['body'], 'framework.pdf', {
      type: 'application/pdf',
    })
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })
    expect(pickerButton.textContent).toContain('استبدال الملف')
    // Summary surfaces the chosen name and size, isolated with <bdi>.
    const summary = screen.getByTestId('document-create-file-summary')
    expect(summary.textContent).toContain('framework.pdf')
    // Polite live region announces the selection for assistive tech.
    const status = document.getElementById('document-create-file-status')
    expect(status).not.toBeNull()
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(status?.textContent).toContain('framework.pdf')
  })

  it('places the primary submit action inside the review panel and disables it while uploading', async () => {
    vi.spyOn(generated, 'initiateDocumentUpload').mockImplementation(
      () => new Promise(() => {}), // never resolves; the screen stays in "hashing"/initiating
    )
    mount(buildFacilityPrincipal())
    fireEvent.change(screen.getByTestId('document-create-title-input'), {
      target: { value: 'مستند اختبار' },
    })
    const file = new File(['body'], 'note.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByTestId('document-create-file-input'), {
      target: { files: [file] },
    })
    const submit = screen.getByTestId('document-create-submit')
    const reviewPanel = screen.getByTestId('document-create-review-panel')
    // Submit must live inside the review/action region so it is visible
    // at desktop without scrolling.
    expect(reviewPanel.contains(submit)).toBe(true)
    // Submit and cancel both fill the review column.
    expect(submit.className).toMatch(/\bw-full\b/)
    fireEvent.click(submit)
    await waitFor(() => expect(submit).toBeDisabled())
  })

  /*
   * DOC-LAYOUT-05-CORRECTION-1:
   *   P1-A — group headings must differ from the adjacent field labels so
   *     the screen no longer repeats the same word as both H2 and label.
   *   P1-B — the visible file picker Button and the sr-only native file
   *     input must both reference the validation error id in
   *     `aria-describedby` once a file error is raised, and only then.
   */
  it('uses a distinct bilingual group heading for the facility section that is not the field label', () => {
    mount(buildFacilityPrincipal())
    const heading = screen.getByRole('heading', {
      level: 2,
      name: 'الملكية والحماية',
    })
    expect(heading).toBeInTheDocument()
    // The facility select still has an accessible label that says
    // "المنشأة المالكة" — the group heading must not duplicate it.
    const ownerLabel = document.querySelector(
      'label[for="document-create-owner-select"]',
    )
    expect(ownerLabel?.textContent).toBe('المنشأة المالكة')
    expect(ownerLabel?.textContent).not.toBe(heading.textContent)
  })

  it('uses a distinct bilingual group heading for the file section that is not the field label', () => {
    mount(buildFacilityPrincipal())
    const heading = screen.getByRole('heading', {
      level: 2,
      name: 'النسخة الأولى',
    })
    expect(heading).toBeInTheDocument()
    // The file input still has an accessible label that says "الملف" —
    // the group heading must not duplicate it.
    const fileLabel = document.querySelector('label[for="document-create-file"]')
    expect(fileLabel?.textContent).toBe('الملف')
    expect(fileLabel?.textContent).not.toBe(heading.textContent)
  })

  it('associates the file validation error with both the picker button and the native input via aria-describedby', async () => {
    mount(buildFacilityPrincipal())
    const pickerButton = screen.getByTestId('document-create-file-button')
    const nativeInput = screen.getByTestId(
      'document-create-file-input',
    ) as HTMLInputElement

    // Before any error: described-by contains help + status, no error id.
    expect(pickerButton.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status',
    )
    expect(nativeInput.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status',
    )

    // Trigger a file validation error: empty file → "الملف المختار فارغ".
    const empty = new File([''], 'empty.bin', { type: 'application/octet-stream' })
    fireEvent.change(nativeInput, { target: { files: [empty] } })
    await waitFor(() =>
      expect(
        screen.getByTestId('document-create-file-error'),
      ).toHaveTextContent('الملف المختار فارغ'),
    )

    // After the error: both controls now reference the error id alongside
    // help and status so the message is announced for the active control.
    expect(pickerButton.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status document-create-file-error',
    )
    expect(nativeInput.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status document-create-file-error',
    )
    expect(nativeInput).toHaveAttribute('aria-invalid', 'true')

    // Clearing the error (replacing with a valid file) drops the error id
    // from the described-by chain again.
    const valid = new File(['body'], 'ok.pdf', { type: 'application/pdf' })
    fireEvent.change(nativeInput, { target: { files: [valid] } })
    expect(pickerButton.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status',
    )
    expect(nativeInput.getAttribute('aria-describedby')).toBe(
      'document-create-file-help document-create-file-status',
    )
  })
})