// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { FacilityFormScreen } from './FacilityFormScreen'
import { ApiError } from '../../api/http'

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const principalState = vi.hoisted(() => ({ capabilities: [] as string[] }))
vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: principalState.capabilities,
    features: { work_management: false, tasks: true },
    effectiveScope: null,
    availableScopes: [],
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
}))

const clusterQuery = vi.hoisted(() => ({
  data: null as unknown,
  isLoading: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
}))
vi.mock('../../api/hooks', () => ({
  useCluster: () => clusterQuery,
}))

const createFacilityMock = vi.hoisted(() => vi.fn())
const updateFacilityMock = vi.hoisted(() => vi.fn())
const getFacilityMock = vi.hoisted(() => vi.fn())
vi.mock('../../api/generated/cluster', () => ({
  createFacility: createFacilityMock,
  updateFacility: updateFacilityMock,
  getFacility: getFacilityMock,
}))

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function response(data: unknown) {
  return { status: 200, data: { data }, headers: new Headers() }
}

function mount(node: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        {node}
      </SessionProvider>
    </QueryClientProvider>,
  )
}

const cluster = {
  id: '01900000-0000-7000-8000-000000000001',
  code: 'CLU-1',
  name_ar: 'تجمع صحي',
  name_en: null,
  status: 'active',
  lock_version: 1,
}

const facility = {
  id: '01900000-0000-7000-8000-000000000002',
  cluster_id: cluster.id,
  type_code: 'hospital',
  code: 'HOSP-1',
  name_ar: 'مستشفى',
  name_en: null,
  status: 'active',
  lock_version: 2,
}

beforeEach(() => {
  navigateMock.mockReset()
  createFacilityMock.mockReset()
  updateFacilityMock.mockReset()
  getFacilityMock.mockReset()
  principalState.capabilities = []
  clusterQuery.data = null
  clusterQuery.isLoading = false
  clusterQuery.isError = false
  clusterQuery.error = null
})

describe('facility form page — create', () => {
  it('submits the create payload with the facility idempotency key', async () => {
    principalState.capabilities = ['organization.facility.manage']
    clusterQuery.data = cluster
    createFacilityMock.mockResolvedValue(response(facility))
    mount(<FacilityFormScreen />)

    const form = screen.getByTestId('facility-form')
    const main = screen.getByTestId('facility-main')
    const review = screen.getByTestId('facility-review')
    expect(form.tagName).toBe('FORM')
    expect(form).toContainElement(main)
    expect(form).toContainElement(review)
    expect(form.querySelector('form')).toBeNull()
    expect(within(review).getByText('مستشفى')).toBeInTheDocument()
    expect(document.getElementById('org-facility-type')).toHaveClass('w-full')

    fireEvent.change(screen.getByLabelText('الرقم التعريفي'), { target: { value: 'hosp-1' } })
    fireEvent.change(screen.getByLabelText('الاسم بالعربية'), { target: { value: 'مستشفى' } })
    expect(within(review).getByText('HOSP-1').tagName).toBe('BDI')

    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(createFacilityMock).toHaveBeenCalledWith(
        { cluster_id: cluster.id, type_code: 'hospital', code: 'HOSP-1', name: 'مستشفى', name_en: null },
        expect.objectContaining({
          headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^facility-/) }),
        }),
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=facilities')
  })
})

describe('facility form page — edit', () => {
  it('keeps the inputs visible and offers a reload when the save comes back 412', async () => {
    principalState.capabilities = ['organization.facility.manage']
    getFacilityMock.mockResolvedValue(response(facility))
    updateFacilityMock.mockRejectedValue(
      new ApiError(412, { type: 'about:blank', title: 'Precondition Failed', status: 412 }),
    )
    mount(<FacilityFormScreen facilityId={facility.id} />)

    const nameInput = await screen.findByLabelText('الاسم بالعربية')
    const review = screen.getByTestId('facility-review')
    expect(nameInput).toHaveValue('مستشفى')
    const codeInput = screen.getByLabelText('الرقم التعريفي')
    expect(codeInput).toHaveValue('HOSP-1')
    expect(codeInput).toHaveAttribute('readonly')

    fireEvent.change(nameInput, { target: { value: 'مستشفى محدث' } })
    expect(within(review).getByText('مستشفى محدث').tagName).toBe('BDI')
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(updateFacilityMock).toHaveBeenCalledWith(
        facility.id,
        { name: 'مستشفى محدث' },
        expect.objectContaining({
          headers: expect.objectContaining({ 'If-Match': '"2"' }),
        }),
      )
    })
    // The stale alert keeps the edited inputs on screen and offers a reload.
    await waitFor(() => {
      expect(screen.getByText('تغيّرت البيانات في مكان آخر. حدّث الصفحة ثم أعد المحاولة.')).toBeInTheDocument()
    })
    expect(screen.getByRole('button', { name: 'إعادة المحاولة' })).toBeInTheDocument()
    expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('مستشفى محدث')
    expect(screen.getByLabelText('الرقم التعريفي')).toHaveValue('HOSP-1')
  })
})
