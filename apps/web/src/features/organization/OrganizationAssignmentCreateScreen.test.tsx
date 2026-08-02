// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { OrganizationAssignmentCreateScreen } from './OrganizationAssignmentCreateScreen'

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

const organizationState = vi.hoisted(() => {
  const person = {
    id: '01900000-0000-7000-8000-000000000001',
    employee_number: 'EMP-1',
    display_name_ar: 'أحمد',
    display_name_en: 'Ahmed',
    status: 'active',
    person_version: 1,
  }
  const position = {
    id: '01900000-0000-7000-8000-000000000002',
    organization_unit_id: '01900000-0000-7000-8000-000000000003',
    code: 'POS-1',
    title_ar: 'مدير الموارد',
    job_title_id: null,
    manager_position_id: null,
    is_active: true,
    lock_version: 1,
  }
  return {
    person,
    position,
    peopleQuery: {
      data: { items: [person], next_cursor: null },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    },
    positionsQuery: {
      data: { items: [position], next_cursor: null },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    },
  }
})
const person = organizationState.person
const position = organizationState.position

vi.mock('../../api/hooks', () => ({
  usePeople: () => organizationState.peopleQuery,
  usePositions: () => organizationState.positionsQuery,
}))

const createAssignmentMock = vi.hoisted(() => vi.fn())
vi.mock('../../api/generated/cluster', () => ({
  createAssignment: createAssignmentMock,
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

beforeEach(() => {
  navigateMock.mockReset()
  createAssignmentMock.mockReset()
  principalState.capabilities = ['organization.assignment.manage']
})

describe('organization assignment create page', () => {
  it('uses the two-region form and reviews selected human labels before submitting', async () => {
    createAssignmentMock.mockResolvedValue(
      response({
        id: '01900000-0000-7000-8000-000000000004',
        person_id: person.id,
        position_id: position.id,
        start_at: '2026-08-04T09:30:00Z',
        end_at: null,
        is_primary: false,
        status: 'active',
        end_reason: null,
        lock_version: 1,
      }),
    )
    mount(<OrganizationAssignmentCreateScreen />)

    const form = screen.getByTestId('assignment-create-form')
    const main = screen.getByTestId('assignment-create-main')
    const review = screen.getByTestId('assignment-create-review')
    expect(form.tagName).toBe('FORM')
    expect(form).toContainElement(main)
    expect(form).toContainElement(review)
    expect(form.querySelector('form')).toBeNull()

    const personSelect = screen.getByRole('combobox', { name: 'الموظف' })
    expect(personSelect).toHaveClass('w-full')
    fireEvent.click(personSelect)
    fireEvent.click(await screen.findByRole('option', { name: 'أحمد' }))

    const positionSelect = screen.getByRole('combobox', { name: 'المنصب' })
    expect(positionSelect).toHaveClass('w-full')
    fireEvent.click(positionSelect)
    fireEvent.click(await screen.findByRole('option', { name: 'مدير الموارد' }))

    const localStart = '2026-08-04T09:30'
    fireEvent.change(screen.getByLabelText('بداية التكليف'), {
      target: { value: localStart },
    })

    expect(within(review).getByText('أحمد').tagName).toBe('BDI')
    expect(within(review).getByText('مدير الموارد').tagName).toBe('BDI')
    expect(within(review).getByText(localStart).tagName).toBe('BDI')

    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(createAssignmentMock).toHaveBeenCalledWith(
        {
          person_id: person.id,
          position_id: position.id,
          start_at: new Date(localStart).toISOString(),
        },
        expect.objectContaining({
          headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^assignment-/) }),
        }),
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=assignments')
  })
})
