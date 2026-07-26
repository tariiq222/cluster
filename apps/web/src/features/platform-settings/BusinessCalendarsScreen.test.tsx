// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api'
import * as api from '../../api/platform-settings'
import { BusinessCalendarsScreen } from './BusinessCalendarsScreen'
import type { CollectionResponse } from '../../api/generated/cluster'

afterEach(cleanup)
afterEach(() => vi.restoreAllMocks())
beforeEach(() => {
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
})

describe('BusinessCalendarsScreen', () => {
  it('shows the empty state and hides official-holiday override without resource data', () => {
    render(
      <BusinessCalendarsScreen
        locale="ar"
        state="empty"
        allowedActions={['platform_settings.calendar.override_official_holiday']}
        token="csrf-token"
      />,
    )
    expect(screen.getByText('لا يوجد تقويم في هذا النطاق')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'طلب العمل أثناء عطلة رسمية' })).toBeNull()
  })

  it('marks the screen as live when token is provided', () => {
    const { container } = render(
      <BusinessCalendarsScreen locale="en" allowedActions={[]} token="csrf-token" />,
    )
    expect(container.querySelector('[data-token="live"]')).toBeTruthy()
  })

  it('renders real backend calendar data when the resource provides it', () => {
    const collection: CollectionResponse = {
      items: [
        {
          id: '01980f50-5f0d-7000-8000-000000000911',
          resource_type: 'business_calendar',
          status: 'published',
          classification: 'internal',
          lock_version: 1,
          created_at: '2026-07-23T09:30:00+03:00',
          updated_at: '2026-07-23T09:30:00+03:00',
          allowed_actions: [],
          values: {
            working_days: ['Sunday-Thursday'],
            working_hours: '08:00-16:00',
            weekends: ['Friday-Saturday'],
            holidays: ['National Day'],
          },
        },
      ],
      next_cursor: null,
    }
    render(
      <BusinessCalendarsScreen
        locale="ar"
        allowedActions={['platform_settings.calendar.read', 'platform_settings.calendar.override_official_holiday', 'platform_settings.calendar.publish']}
        resource={collection}
        token="csrf-token"
      />,
    )
    expect(screen.getByText('Sunday-Thursday، 08:00-16:00')).toBeTruthy()
    expect(screen.getByText('Friday-Saturday، عطلة')).toBeTruthy()
    expect(screen.getByText('National Day')).toBeTruthy()
    expect(screen.getAllByText('مصدر: الخادم').length).toBe(2)
  })

  it('creates a platform calendar with the canonical literal scope id', async () => {
    const createSpy = vi.spyOn(api, 'createBusinessCalendar').mockResolvedValue({} as never)
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        token="csrf-token"
      />,
    )
    expect(screen.queryByLabelText('Scope ID')).toBeNull()
    screen.getByRole('button', { name: 'Create calendar' }).click()
    await waitFor(() => expect(createSpy).toHaveBeenCalledTimes(1))
    expect(createSpy).toHaveBeenCalledWith('csrf-token', {
      scope_type: 'platform',
      scope_id: 'platform',
      parent_calendar_id: null,
    })
  })

  it('requires a valid UUID and sends the entered cluster scope id', async () => {
    const createSpy = vi.spyOn(api, 'createBusinessCalendar').mockResolvedValue({} as never)
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        token="csrf-token"
      />,
    )

    screen.getByRole('button', { name: 'Calendar scope' }).click()
    fireEvent.click(await screen.findByRole('option', { name: 'Cluster' }))
    const scopeId = screen.getByRole('textbox', { name: /^Scope ID/ })
    const createButton = screen.getByRole('button', { name: 'Create calendar' }) as HTMLButtonElement
    expect(createButton.disabled).toBe(true)

    fireEvent.change(scopeId, { target: { value: 'cluster' } })
    expect(createButton.disabled).toBe(true)
    const clusterId = '01980f50-5f0d-7000-8000-000000000912'
    fireEvent.change(scopeId, { target: { value: clusterId } })
    expect(createButton.disabled).toBe(false)
    createButton.click()

    await waitFor(() => expect(createSpy).toHaveBeenCalledTimes(1))
    expect(createSpy).toHaveBeenCalledWith('csrf-token', {
      scope_type: 'cluster',
      scope_id: clusterId,
      parent_calendar_id: null,
    })
  })

  it('shows a labeled facility scope id input and sends its UUID', async () => {
    const createSpy = vi.spyOn(api, 'createBusinessCalendar').mockResolvedValue({} as never)
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        token="csrf-token"
      />,
    )

    screen.getByRole('button', { name: 'Calendar scope' }).click()
    fireEvent.click(await screen.findByRole('option', { name: 'Facility' }))
    const facilityId = '01980f50-5f0d-7000-8000-000000000913'
    fireEvent.change(screen.getByRole('textbox', { name: /^Scope ID/ }), { target: { value: facilityId } })
    screen.getByRole('button', { name: 'Create calendar' }).click()

    await waitFor(() => expect(createSpy).toHaveBeenCalledTimes(1))
    expect(createSpy).toHaveBeenCalledWith('csrf-token', {
      scope_type: 'facility',
      scope_id: facilityId,
      parent_calendar_id: null,
    })
  })

  it('surfaces the API error message when calendar creation fails', async () => {
    vi.spyOn(api, 'createBusinessCalendar').mockRejectedValue(new ApiError(422, {
      type: 'https://cluster.example/problems/validation-failed',
      title: 'Validation failed',
      status: 422,
      detail: 'Scope id is required.',
    }))
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        token="csrf-token"
      />,
    )
    screen.getByRole('button', { name: 'Create calendar' }).click()
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Scope id is required.'))
  })

  it('uses ISO Sunday 7 when marking Sunday off', async () => {
    const weekdaySpy = vi.spyOn(api, 'setBusinessCalendarWeekday').mockResolvedValue({} as never)
    const collection: CollectionResponse = {
      items: [
        {
          id: '01980f50-5f0d-7000-8000-000000000911',
          resource_type: 'business_calendar',
          status: 'draft',
          classification: 'internal',
          lock_version: 3,
          created_at: '2026-07-23T09:30:00+03:00',
          updated_at: '2026-07-23T09:30:00+03:00',
          allowed_actions: [],
          values: { working_days: ['Monday'], weekends: [], holidays: [] },
        },
      ],
      next_cursor: null,
    }
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        resource={collection}
        token="csrf-token"
      />,
    )
    screen.getByRole('button', { name: 'Mark Sunday off' }).click()
    await waitFor(() => expect(weekdaySpy).toHaveBeenCalledTimes(1))
    expect(weekdaySpy).toHaveBeenCalledWith(
      'csrf-token',
      '01980f50-5f0d-7000-8000-000000000911',
      7,
      expect.objectContaining({ is_working_day: false, starts_at: '', ends_at: '' }),
      3,
    )
  })

  it('calls publishBusinessCalendar with the displayed lock version when the operator confirms', async () => {
    const publishSpy = vi.spyOn(api, 'publishBusinessCalendar').mockResolvedValue({} as never)
    const collection: CollectionResponse = {
      items: [
        {
          id: '01980f50-5f0d-7000-8000-000000000911',
          resource_type: 'business_calendar',
          status: 'draft',
          classification: 'internal',
          lock_version: 4,
          created_at: '2026-07-23T09:30:00+03:00',
          updated_at: '2026-07-23T09:30:00+03:00',
          allowed_actions: [],
          values: { working_days: ['Monday'], weekends: [], holidays: [] },
        },
      ],
      next_cursor: null,
    }
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.publish']}
        resource={collection}
        token="csrf-token"
      />,
    )
    screen.getByRole('button', { name: 'Publish calendar' }).click()
    await waitFor(() => expect(publishSpy).toHaveBeenCalledTimes(1))
    expect(publishSpy).toHaveBeenCalledWith('csrf-token', '01980f50-5f0d-7000-8000-000000000911', 4)
  })

  it('opens the exception drawer, captures the typed values, and calls setBusinessCalendarException', async () => {
    const exceptionSpy = vi.spyOn(api, 'setBusinessCalendarException').mockResolvedValue({} as never)
    const collection: CollectionResponse = {
      items: [
        {
          id: '01980f50-5f0d-7000-8000-000000000911',
          resource_type: 'business_calendar',
          status: 'draft',
          classification: 'internal',
          lock_version: 5,
          created_at: '2026-07-23T09:30:00+03:00',
          updated_at: '2026-07-23T09:30:00+03:00',
          allowed_actions: [],
          values: { working_days: [], weekends: [], holidays: [] },
        },
      ],
      next_cursor: null,
    }
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.override_official_holiday']}
        resource={collection}
        token="csrf-token"
      />,
    )
    screen.getByRole('button', { name: 'Request official-holiday work' }).click()
    const dateInput = await screen.findByLabelText('Date')
    expect(screen.getByRole('button', { name: 'Exception type' }).textContent).toContain('Official-holiday work override')
    fireEvent.change(dateInput, { target: { value: '2099-06-15' } })
    screen.getByRole('button', { name: 'Confirm request' }).click()
    await waitFor(() => expect(exceptionSpy).toHaveBeenCalledTimes(1))
    expect(exceptionSpy).toHaveBeenCalledWith(
      'csrf-token',
      '01980f50-5f0d-7000-8000-000000000911',
      '2099-06-15',
      expect.objectContaining({ type: 'official_holiday_work_override', is_working_day: true, starts_at: '08:00', ends_at: '16:00' }),
      5,
    )
  })

  it('prevents double-submission: a second create click while the first is in flight is ignored', async () => {
    let resolveFirst!: (value: unknown) => void
    const inFlight = new Promise<unknown>((resolve) => { resolveFirst = resolve })
    const createSpy = vi.spyOn(api, 'createBusinessCalendar').mockImplementation(
      () => inFlight as never,
    )
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.manage']}
        token="csrf-token"
      />,
    )
    const button = screen.getByRole('button', { name: 'Create calendar' })
    button.click()
    await waitFor(() => expect(createSpy).toHaveBeenCalledTimes(1))
    button.click()
    button.click()
    expect(createSpy).toHaveBeenCalledTimes(1)
    resolveFirst({})
    await inFlight
  })

  it('surfaces the API error from publishBusinessCalendar as a 412 conflict', async () => {
    vi.spyOn(api, 'publishBusinessCalendar').mockRejectedValue(new ApiError(412, {
      type: 'https://cluster.example/problems/precondition-failed',
      title: 'Precondition Failed',
      status: 412,
      detail: 'If-Match does not match the current calendar.',
    }))
    const collection: CollectionResponse = {
      items: [
        {
          id: '01980f50-5f0d-7000-8000-000000000911',
          resource_type: 'business_calendar',
          status: 'draft',
          classification: 'internal',
          lock_version: 4,
          created_at: '2026-07-23T09:30:00+03:00',
          updated_at: '2026-07-23T09:30:00+03:00',
          allowed_actions: [],
          values: { working_days: [], weekends: [], holidays: [] },
        },
      ],
      next_cursor: null,
    }
    render(
      <BusinessCalendarsScreen
        locale="en"
        allowedActions={['platform_settings.calendar.publish']}
        resource={collection}
        token="csrf-token"
      />,
    )
    screen.getByRole('button', { name: 'Publish calendar' }).click()
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('If-Match does not match the current calendar.'))
  })
})
