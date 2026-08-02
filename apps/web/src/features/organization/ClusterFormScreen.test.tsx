// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { ClusterFormScreen } from './ClusterFormScreen'

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

const createClusterMock = vi.hoisted(() => vi.fn())
const updateClusterMock = vi.hoisted(() => vi.fn())
const getClusterMock = vi.hoisted(() => vi.fn())
vi.mock('../../api/generated/cluster', () => ({
  createCluster: createClusterMock,
  updateCluster: updateClusterMock,
  getCluster: getClusterMock,
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

beforeEach(() => {
  navigateMock.mockReset()
  createClusterMock.mockReset()
  updateClusterMock.mockReset()
  getClusterMock.mockReset()
  principalState.capabilities = []
})

describe('cluster form page gating', () => {
  it('renders the shared non-disclosing denied state without the manage capability', () => {
    mount(<ClusterFormScreen />)
    expect(screen.getByTestId('cluster-form-screen')).toBeInTheDocument()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(createClusterMock).not.toHaveBeenCalled()
  })
})

describe('cluster form page — create', () => {
  it('submits the create payload with the cluster idempotency key and returns to the tab', async () => {
    principalState.capabilities = ['organization.cluster.manage']
    createClusterMock.mockResolvedValue(response(cluster))
    mount(<ClusterFormScreen />)

    const form = screen.getByTestId('cluster-form')
    const main = screen.getByTestId('cluster-main')
    const review = screen.getByTestId('cluster-review')
    expect(form.tagName).toBe('FORM')
    expect(form).toContainElement(main)
    expect(form).toContainElement(review)
    expect(form.querySelector('form')).toBeNull()
    expect(within(review).getByTestId('cluster-review-summary').tagName).toBe('DL')

    fireEvent.change(screen.getByLabelText('الرقم التعريفي'), { target: { value: 'clu-9' } })
    fireEvent.change(screen.getByLabelText('الاسم بالعربية'), { target: { value: 'تجمع جديد' } })
    fireEvent.change(screen.getByLabelText('الاسم بالإنجليزية'), { target: { value: 'New cluster' } })

    expect(within(review).getByText('CLU-9').tagName).toBe('BDI')
    expect(within(review).getByText('تجمع جديد').tagName).toBe('BDI')
    expect(within(review).getByText('New cluster').tagName).toBe('BDI')

    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(createClusterMock).toHaveBeenCalledWith(
        { code: 'CLU-9', name: 'تجمع جديد', name_en: 'New cluster' },
        expect.objectContaining({
          headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^cluster-/) }),
        }),
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=cluster')
  })
})

describe('cluster form page — edit', () => {
  it('seeds the form from the fresh fetch and saves with the observed lock version', async () => {
    principalState.capabilities = ['organization.cluster.manage']
    getClusterMock.mockResolvedValue(response(cluster))
    updateClusterMock.mockResolvedValue(response({ ...cluster, name_ar: 'تجمع محدث' }))
    mount(<ClusterFormScreen mode="edit" />)

    const nameInput = await screen.findByLabelText('الاسم بالعربية')
    expect(nameInput).toHaveValue('تجمع صحي')
    // Create-only fields are not part of the edit form.
    expect(screen.queryByLabelText('الرقم التعريفي')).toBeNull()

    fireEvent.change(nameInput, { target: { value: 'تجمع محدث' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(updateClusterMock).toHaveBeenCalledWith(
        { name: 'تجمع محدث' },
        expect.objectContaining({
          headers: expect.objectContaining({
            'If-Match': '"1"',
            'Idempotency-Key': expect.stringMatching(/^cluster-update-/),
          }),
        }),
      )
    })
    expect(getClusterMock).toHaveBeenCalledTimes(2)
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=cluster')
  })
})
