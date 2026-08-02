// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { CalendarWeekdayEditScreen } from './CalendarWeekdayEditScreen'

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

vi.mock('./platform-api', () => ({
  listBusinessCalendars: vi.fn(),
  setBusinessCalendarWeekday: vi.fn(),
}))

import { listBusinessCalendars, setBusinessCalendarWeekday } from './platform-api'

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

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

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

const calendarFixture = {
  id: 'cal-1',
  scope_type: 'platform',
  scope_id: 'platform',
  status: 'draft',
  timezone: 'Asia/Riyadh',
  values: { working_days: [1, 2, 3, 4, 5], holidays: [] },
  allowed_actions: [],
  lock_version: 7,
}

beforeEach(() => {
  navigateMock.mockReset()
  vi.mocked(listBusinessCalendars).mockReset()
  vi.mocked(setBusinessCalendarWeekday).mockReset()
  vi.mocked(listBusinessCalendars).mockResolvedValue({ items: [calendarFixture] })
  principalState.capabilities = []
})

describe('calendar weekday edit page', () => {
  it('finds the calendar by id, toggles the working day, and saves with the observed lock version', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={2} />)

    // The form uses the shared SingleRegionFormLayout (DESIGN-RULES §2.7).
    const form = await screen.findByTestId('calendar-weekday-edit-form')
    expect(form.tagName).toBe('FORM')

    // Monday (2) is in working_days, so the checkbox seeds checked.
    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    expect(checkbox).toBeChecked()

    // Toggle off the working day and set the shift hours.
    fireEvent.click(checkbox)
    expect(checkbox).not.toBeChecked()
    fireEvent.change(screen.getByLabelText('بداية'), { target: { value: '08:00' } })
    fireEvent.change(screen.getByLabelText('نهاية'), { target: { value: '16:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(setBusinessCalendarWeekday).toHaveBeenCalledWith(
        'x',
        'cal-1',
        2,
        { is_working_day: false, starts_at: '08:00', ends_at: '16:00' },
        7,
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/platform?tab=settings')
  })
})
