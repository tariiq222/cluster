// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { OrganizationScreen } from './OrganizationScreen'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'
import { ApiError } from '../../api/http'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const emptyQuery = {
  data: { items: [], next_cursor: null },
  isLoading: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
}

const notFoundCluster = {
  data: null,
  isLoading: false,
  isError: true,
  error: new ApiError(404, { type: 'x', title: 'Not found', status: 404 }),
  refetch: vi.fn(),
}

vi.mock('../../api/hooks', () => ({
  useCluster: () => notFoundCluster,
  useFacilities: () => emptyQuery,
  useOrganizationUnits: () => emptyQuery,
  usePositions: () => emptyQuery,
  useJobTitles: () => emptyQuery,
  usePeople: () => emptyQuery,
  useAssignments: () => emptyQuery,
  useTemporaryAssignments: () => emptyQuery,
  useSupervisoryRelationships: () => emptyQuery,
  useAllOrganizationUnits: () => emptyQuery,
  useAllPositions: () => emptyQuery,
  useAllJobTitles: () => emptyQuery,
  useAllPeople: () => emptyQuery,
}))

function mount(capabilities: string[]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <PrincipalContextTestProvider capabilities={capabilities} features={{ tasks: true }}>
            <OrganizationScreen />
          </PrincipalContextTestProvider>
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('organization workspace', () => {
  it('omits tabs the principal cannot read', () => {
    mount(['organization.facility.read'])
    expect(screen.getByRole('tab', { name: 'المنشآت' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'الموظفون' })).toBeNull()
    expect(screen.queryByRole('tab', { name: 'الوظائف' })).toBeNull()
    expect(screen.queryByRole('tab', { name: 'المسميات الوظيفية' })).toBeNull()
    expect(screen.queryByRole('tab', { name: 'التكليفات المؤقتة' })).toBeNull()
    expect(screen.queryByRole('tab', { name: 'العلاقات الإشرافية' })).toBeNull()
    expect(screen.queryByRole('tab', { name: 'إعداد المجمّع' })).toBeNull()
  })

  it('shows positions and job titles to a position-only administrator', () => {
    mount(['organization.position.read'])

    expect(screen.getByRole('tab', { name: 'الوظائف' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'المسميات الوظيفية' })).toBeInTheDocument()
    expect(screen.getAllByRole('tab')).toHaveLength(2)
  })

  it('shows structure and supervisory tabs to a unit-only administrator', () => {
    mount(['organization.unit.read'])

    expect(screen.getByRole('tab', { name: 'العلاقات الإشرافية' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'الهيكل التنظيمي' })).toBeInTheDocument()
    expect(screen.getAllByRole('tab')).toHaveLength(2)
  })

  it('shows no organization tab to an unrelated principal', () => {
    mount(['tasks.list'])

    expect(screen.queryByRole('tablist', { name: 'أقسام المنظمة' })).toBeNull()
    expect(screen.queryByRole('tab')).toBeNull()
  })

  it('labels the tab list accessibly', () => {
    mount(['organization.unit.read', 'organization.facility.read'])
    expect(screen.getByRole('tablist', { name: 'أقسام المنظمة' })).toBeInTheDocument()
  })

  it('stacks the tab navigation above the active content in one vertical flow', () => {
    mount(['organization.unit.read'])
    const tabs = screen.getByTestId('organization-tabs')
    expect(tabs).toHaveClass('flex-col')
    const tablist = screen.getByRole('tablist', { name: 'أقسام المنظمة' })
    const content = tabs.querySelector('[data-slot="tabs-content"]')
    expect(content).not.toBeNull()
    expect(tablist.compareDocumentPosition(content as Element) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
  })

  it('renders the cluster tab empty state rather than an error when no cluster exists', () => {
    mount(['organization.cluster.read', 'organization.cluster.manage'])
    screen.getByRole('tab', { name: 'إعداد المجمّع' }).click()
    expect(screen.getByRole('button', { name: 'إضافة تجمع' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
