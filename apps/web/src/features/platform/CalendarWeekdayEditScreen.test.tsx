// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { ApiError } from '../../api/http'
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

const baseWeekdays = [
  { weekday: 1, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
  { weekday: 2, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
  { weekday: 3, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
  { weekday: 4, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
  { weekday: 5, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
  { weekday: 6, is_working_day: false, starts_at: null, ends_at: null },
  { weekday: 7, is_working_day: false, starts_at: null, ends_at: null },
]

const calendarFixture = {
  id: 'cal-1',
  scope_type: 'platform',
  scope_id: 'platform',
  status: 'draft',
  timezone: 'Asia/Riyadh',
  values: { weekdays: baseWeekdays, working_days: [1, 2, 3, 4, 5], weekends: [6, 7], holidays: [] },
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

    // Monday (2) is a working day, so the checkbox seeds checked.
    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    expect(checkbox).toBeChecked()

    // Toggle off the working day; hours are cleared and not sent.
    fireEvent.click(checkbox)
    expect(checkbox).not.toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(setBusinessCalendarWeekday).toHaveBeenCalledWith(
        'x',
        'cal-1',
        2,
        { is_working_day: false, starts_at: null, ends_at: null },
        7,
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/platform?tab=settings')
  })

  it('seeds working-day hours from the persisted weekday projection', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={1} />)

    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    await waitFor(() => expect(checkbox).toBeChecked())
    expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('08:00')
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('16:00')
  })

  it('preserves existing hours when only the working-day flag is saved', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={3} />)

    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    await waitFor(() => expect(checkbox).toBeChecked())
    expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('08:00')
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('16:00')

    // Save without editing the inputs: hours must be preserved end-to-end.
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(setBusinessCalendarWeekday).toHaveBeenCalledWith(
        'x',
        'cal-1',
        3,
        { is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
        7,
      )
    })
  })

  it('seeds a non-working weekday as a clean toggle without hours', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={6} />)

    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    await waitFor(() => expect(checkbox).not.toBeChecked())
    expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('')
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('')

    // Enable the working day — both time inputs are empty and the save must
    // demand a complete range.
    fireEvent.click(checkbox)
    expect(checkbox).toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(screen.getByText('حدد وقت البداية والنهاية ليوم العمل.')).toBeInTheDocument()
    })
    expect(setBusinessCalendarWeekday).not.toHaveBeenCalled()
  })

  it('applies safe defaults when the weekday projection entry is missing', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']
    const projectionOnly = {
      ...calendarFixture,
      values: { ...calendarFixture.values, weekdays: [{ weekday: 1, is_working_day: true, starts_at: '08:00', ends_at: '16:00' }] },
    }
    vi.mocked(listBusinessCalendars).mockResolvedValue({ items: [projectionOnly] })

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={5} />)

    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    await waitFor(() => expect(checkbox).not.toBeChecked())
    expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('')
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('')
  })

  it('reseeds inputs from the fresh weekday projection after a 412 conflict', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']
    const apiError = new ApiError(412, { type: 'about:blank', title: 'Precondition failed', status: 412 })
    vi.mocked(setBusinessCalendarWeekday).mockRejectedValueOnce(apiError)
    const winnerCalendar = {
      ...calendarFixture,
      lock_version: 9,
      values: {
        ...calendarFixture.values,
        weekdays: [
          { weekday: 2, is_working_day: true, starts_at: '09:30', ends_at: '17:30' },
          { weekday: 4, is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
        ],
      },
    }
    vi.mocked(listBusinessCalendars)
      .mockResolvedValueOnce({ items: [calendarFixture] })
      .mockResolvedValueOnce({ items: [winnerCalendar] })

    mount(<CalendarWeekdayEditScreen calendarId="cal-1" weekday={2} />)

    const checkbox = await screen.findByRole('checkbox', { name: 'يوم عمل' })
    await waitFor(() => expect(checkbox).toBeChecked())
    expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('08:00')
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('16:00')

    // The save uses the seeded, untouched values, so no human edit is in
    // flight. After a 412 the page must show the stale notice and reseed
    // to the authoritative projection that the refetch returns.
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(setBusinessCalendarWeekday).toHaveBeenCalledWith(
        'x',
        'cal-1',
        2,
        { is_working_day: true, starts_at: '08:00', ends_at: '16:00' },
        7,
      )
    })
    await waitFor(() => {
      expect(screen.getByText(/تعارض في النسخة/)).toBeInTheDocument()
    })
    await waitFor(() => {
      expect((screen.getByLabelText('بداية') as HTMLInputElement).value).toBe('09:30')
    })
    expect((screen.getByLabelText('نهاية') as HTMLInputElement).value).toBe('17:30')
    expect(navigateMock).not.toHaveBeenCalled()
  })
})
