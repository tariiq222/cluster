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
}))

function mount(capabilities: string[]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <PrincipalContextTestProvider capabilities={capabilities} features={{ work_management: false, tasks: true }}>
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
    expect(screen.queryByRole('tab', { name: 'إعداد المجمّع' })).toBeNull()
  })

  it('renders the cluster tab empty state rather than an error when no cluster exists', () => {
    mount(['organization.cluster.read', 'organization.cluster.manage'])
    screen.getByRole('tab', { name: 'إعداد المجمّع' }).click()
    expect(screen.getByRole('button', { name: 'إضافة تجمع' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
