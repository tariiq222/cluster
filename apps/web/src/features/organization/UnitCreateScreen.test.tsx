// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { UnitCreateScreen } from './UnitCreateScreen'

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
const unitsQuery = vi.hoisted(() => ({
  data: { items: [] as unknown[], next_cursor: null },
  isLoading: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
}))
vi.mock('../../api/hooks', () => ({
  useCluster: () => clusterQuery,
  useOrganizationUnits: () => unitsQuery,
}))

const createOrganizationUnitMock = vi.hoisted(() => vi.fn())
vi.mock('../../api/generated/cluster', () => ({
  createOrganizationUnit: createOrganizationUnitMock,
}))

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function response(data: unknown) {
  return { status: 200, data: { data }, headers: new Headers() }
}

function mount(node: ReactNode, initialEntries: string[]) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={initialEntries}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          {node}
        </SessionProvider>
      </MemoryRouter>
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

const parentUnit = {
  id: '01900000-0000-7000-8000-000000000002',
  cluster_id: cluster.id,
  parent_id: null,
  parent_type: 'cluster',
  type_code: 'department',
  code: 'HR',
  name_ar: 'الموارد البشرية',
  name_en: null,
  status: 'active',
  path_cache: '01900000-0000-7000-8000-000000000002',
  depth: 1,
  lock_version: 1,
}

beforeEach(() => {
  navigateMock.mockReset()
  createOrganizationUnitMock.mockReset()
  principalState.capabilities = []
  clusterQuery.data = cluster
  unitsQuery.data = { items: [parentUnit], next_cursor: null }
})

describe('unit create page — parent preselection', () => {
  it('preselects the parent unit from the parentId URL param', async () => {
    principalState.capabilities = ['organization.unit.manage']
    mount(<UnitCreateScreen />, ['/organization/units/new?parentId=' + parentUnit.id])

    const form = screen.getByTestId('unit-create-form')
    expect(form.tagName).toBe('FORM')
    expect(form).toContainElement(screen.getByTestId('unit-create-actions'))
    expect(form.querySelector('aside')).toBeNull()
    expect(form.querySelector('form')).toBeNull()
    expect(screen.getByRole('heading', { level: 2, name: 'البيانات الأساسية' })).toBeInTheDocument()

    const parentSelect = await screen.findByRole('combobox', { name: 'الموقع الأعلى' })
    expect(parentSelect).toHaveClass('w-full')
    expect(screen.getByRole('combobox', { name: 'نوع الوحدة' })).toHaveClass('w-full')
    expect(parentSelect).toHaveTextContent('الموارد البشرية')
  })
})

describe('unit create page — submit', () => {
  it('submits the create payload with the preselected parent and the unit idempotency key', async () => {
    principalState.capabilities = ['organization.unit.manage']
    createOrganizationUnitMock.mockResolvedValue(response(parentUnit))
    mount(<UnitCreateScreen />, ['/organization/units/new?parentId=' + parentUnit.id])

    await screen.findByRole('combobox', { name: 'الموقع الأعلى' })
    fireEvent.change(screen.getByLabelText('الرقم التعريفي'), { target: { value: 'hr' } })
    fireEvent.change(screen.getByLabelText('الاسم بالعربية'), { target: { value: 'الموارد البشرية' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(createOrganizationUnitMock).toHaveBeenCalledWith(
        { cluster_id: cluster.id, parent_id: parentUnit.id, type_code: 'department', code: 'HR', name: 'الموارد البشرية' },
        expect.objectContaining({
          headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^organization-unit-/) }),
        }),
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=structure')
  })
})
