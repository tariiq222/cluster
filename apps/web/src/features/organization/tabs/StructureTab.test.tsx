// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StructureTab } from './StructureTab'
import { SessionProvider } from '../../../app/session-context'
import { PrincipalContextTestProvider } from '../../../app/principal-context'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

/*
 * The hooks are mocked against a mutable holder so each test can seed its own
 * cluster/units/facilities state without re-registering the module mock.
 */
const mock = vi.hoisted(() => {
  const state = {
    cluster: null as unknown,
    units: [] as unknown[],
    facilities: [] as unknown[],
    positions: [] as unknown[],
    jobTitles: [] as unknown[],
  }
  return { state }
})

vi.mock('../../../api/hooks', () => ({
  useCluster: () => ({
    data: mock.state.cluster,
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  useFacilities: () => ({
    data: { items: mock.state.facilities, next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  useOrganizationUnits: () => ({
    data: { items: mock.state.units, next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  usePositions: () => ({
    data: { items: mock.state.positions, next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  useJobTitles: () => ({
    data: { items: mock.state.jobTitles, next_cursor: null },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

function mount(capabilities: string[]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <PrincipalContextTestProvider capabilities={capabilities} features={{ work_management: false, tasks: true }}>
            <StructureTab />
          </PrincipalContextTestProvider>
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const clusterShape = {
  id: '01900000-0000-7000-8000-000000000001',
  code: 'CLU-1',
  name_ar: 'تجمع صحي',
  name_en: 'Health cluster',
  status: 'active',
  lock_version: 1,
}

const unitShape = {
  id: '01900000-0000-7000-8000-000000000002',
  cluster_id: '01900000-0000-7000-8000-000000000001',
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

describe('structure tab', () => {
  beforeEach(() => {
    mock.state.cluster = clusterShape
    mock.state.units = []
    mock.state.facilities = []
    mock.state.positions = []
    mock.state.jobTitles = []
  })

  it('shows a helpful empty state that exposes Add unit only with manage capability', () => {
    mount(['organization.unit.read', 'organization.unit.manage'])
    const empty = screen.getByTestId('empty-state')
    expect(within(empty).getByText('لا توجد وحدات بعد.')).toBeInTheDocument()
    expect(within(empty).getByRole('button', { name: 'إضافة وحدة' })).toBeInTheDocument()
    expect(within(empty).queryByRole('button', { name: 'ترتيب الوحدات' })).toBeNull()
  })

  it('keeps the empty state action-free when the manage capability is missing', () => {
    mount(['organization.unit.read'])
    const empty = screen.getByTestId('empty-state')
    expect(within(empty).queryByRole('button')).toBeNull()
    expect(screen.queryByRole('button', { name: 'ترتيب الوحدات' })).toBeNull()
  })

  it('makes Add unit the primary action and reorder the secondary one when units exist', () => {
    mock.state.units = [unitShape]
    mount(['organization.unit.read', 'organization.unit.manage'])
    expect(screen.queryByTestId('empty-state')).toBeNull()
    const add = screen.getByRole('button', { name: 'إضافة وحدة' })
    const reorder = screen.getByRole('button', { name: 'ترتيب الوحدات' })
    expect(add).toBeInTheDocument()
    expect(reorder).toBeInTheDocument()
    expect(add).toHaveClass('bg-primary')
    expect(reorder).toHaveClass('border-border')
    expect(add.compareDocumentPosition(reorder) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
  })

  it('renders unit rows with name, isolated LTR code, and an outline status badge', () => {
    mock.state.units = [unitShape]
    mount(['organization.unit.read'])
    expect(screen.getByText('الموارد البشرية')).toBeInTheDocument()
    expect(screen.getByText('HR')).toBeInTheDocument()
    expect(screen.getByText('نشط')).toBeInTheDocument()
  })
})
