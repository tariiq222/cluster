// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { PersonFormScreen } from './PersonFormScreen'

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

const registerPersonMock = vi.hoisted(() => vi.fn())
const updatePersonMock = vi.hoisted(() => vi.fn())
const getPersonMock = vi.hoisted(() => vi.fn())
vi.mock('../../api/generated/cluster', () => ({
  registerPerson: registerPersonMock,
  updatePerson: updatePersonMock,
  getPerson: getPersonMock,
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

const person = {
  id: '01900000-0000-7000-8000-000000000001',
  employee_number: 'EMP-1',
  display_name_ar: 'أحمد',
  display_name_en: null,
  status: 'active',
  person_version: 3,
}

beforeEach(() => {
  navigateMock.mockReset()
  registerPersonMock.mockReset()
  updatePersonMock.mockReset()
  getPersonMock.mockReset()
  principalState.capabilities = []
})

describe('person form page — create', () => {
  it('registers the person with an active status and the person idempotency key', async () => {
    principalState.capabilities = ['organization.person.manage']
    registerPersonMock.mockResolvedValue(response(person))
    mount(<PersonFormScreen />)

    const form = screen.getByTestId('person-form')
    const main = screen.getByTestId('person-main')
    const review = screen.getByTestId('person-review')
    expect(form.tagName).toBe('FORM')
    expect(form).toContainElement(main)
    expect(form).toContainElement(review)
    expect(form.querySelector('form')).toBeNull()
    expect(within(review).getByTestId('person-review-summary').tagName).toBe('DL')

    fireEvent.change(screen.getByLabelText('الرقم الوظيفي'), { target: { value: 'EMP-1' } })
    fireEvent.change(screen.getByLabelText('الاسم بالعربية'), { target: { value: 'أحمد' } })
    fireEvent.change(screen.getByLabelText('الاسم بالإنجليزية'), { target: { value: 'Ahmed' } })

    expect(within(review).getByText('EMP-1').tagName).toBe('BDI')
    expect(within(review).getByText('أحمد').tagName).toBe('BDI')
    expect(within(review).getByText('Ahmed').tagName).toBe('BDI')

    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(registerPersonMock).toHaveBeenCalledWith(
        { employee_number: 'EMP-1', display_name_ar: 'أحمد', display_name_en: 'Ahmed', status: 'active' },
        expect.objectContaining({
          headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^person-/) }),
        }),
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=people')
  })
})

describe('person form page — edit', () => {
  it('loads the person by id, keeps the employee number read-only, and saves with person_version', async () => {
    principalState.capabilities = ['organization.person.manage']
    getPersonMock.mockResolvedValue(response(person))
    updatePersonMock.mockResolvedValue(response({ ...person, display_name_ar: 'أحمد محدث' }))
    mount(<PersonFormScreen personId={person.id} />)

    const employeeNumberInput = await screen.findByLabelText('الرقم الوظيفي')
    expect(employeeNumberInput).toHaveValue('EMP-1')
    expect(employeeNumberInput).toHaveAttribute('readonly')
    expect(screen.getByLabelText('الاسم بالعربية')).toHaveValue('أحمد')

    fireEvent.change(screen.getByLabelText('الاسم بالعربية'), { target: { value: 'أحمد محدث' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(updatePersonMock).toHaveBeenCalledWith(
        person.id,
        { display_name_ar: 'أحمد محدث' },
        expect.objectContaining({
          headers: expect.objectContaining({
            'If-Match': '"3"',
            'Idempotency-Key': expect.stringMatching(/^person-update-/),
          }),
        }),
      )
    })
    expect(getPersonMock).toHaveBeenCalledTimes(2)
    expect(navigateMock).toHaveBeenCalledWith('/organization?tab=people')
  })
})
