// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../api'
import * as api from '../../api/platform-settings'
import { MaintenanceScreen } from './MaintenanceScreen'
import { platformSettingsMockFor } from './PlatformSettingsMockData'

afterEach(cleanup)
afterEach(() => vi.restoreAllMocks())

describe('MaintenanceScreen', () => {
  it('explains impact and hides maintenance mutations until allowed', () => {
    render(<MaintenanceScreen locale="ar" allowedActions={[]} />)
    expect(screen.getByText(/رسالة ثنائية اللغة/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إنشاء نافذة صيانة' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'إلغاء النافذة' })).toBeNull()
  })

  it('surfaces the cancel control when only the cancel capability is granted', () => {
    const screenData = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.cancel'],
    )
    const resource = {
      id: 'maintenance',
      items: [
        {
          id: '019f8e3b-3368-7192-85a6-3da3949fd707',
          status: 'active',
          starts_at: '2026-07-23T10:00:00+03:00',
          ends_at: '2026-07-23T11:00:00+03:00',
          lock_version: 1,
        },
      ],
      next_cursor: null,
    } as never
    render(<MaintenanceScreen locale="en" token="test-token" {...screenData} resource={resource} />)
    expect(screen.queryByRole('button', { name: 'Cancel current window' })).toBeTruthy()
  })

  it('surfaces a 412 lock-version problem when the cancel mutation is rejected', async () => {
    vi.spyOn(api, 'cancelPlatformMaintenanceWindow').mockRejectedValueOnce(
      new ApiError(412, {
        type: 'about:blank',
        title: 'Precondition Failed',
        status: 412,
        detail: 'If-Match does not match the current maintenance window.',
      } as never),
    )
    const screenData = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel'],
    )
    const resource = {
      id: 'maintenance',
      items: [
        {
          id: '019f8e3b-3368-7192-85a6-3da3949fd707',
          status: 'active',
          starts_at: '2026-07-23T10:00:00+03:00',
          ends_at: '2026-07-23T11:00:00+03:00',
          lock_version: 1,
        },
      ],
      next_cursor: null,
    } as never
    render(<MaintenanceScreen locale="en" token="test-token" {...screenData} resource={resource} />)
    fireEvent.click(screen.getByRole('button', { name: 'Cancel current window' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm cancel' }))
    await waitFor(() => {
      expect(screen.getByRole('alert').textContent ?? '').toMatch(/If-Match does not match the current maintenance window/i)
    })
  })

  it('calls schedulePlatformMaintenanceWindow with the typed payload and the provided token', async () => {
    const scheduleSpy = vi.spyOn(api, 'schedulePlatformMaintenanceWindow').mockResolvedValue({} as never)
    const screenData = platformSettingsMockFor('maintenance', ['platform_operations.maintenance.manage'])
    render(<MaintenanceScreen locale="en" token="csrf-token" {...screenData} />)
    screen.getByRole('button', { name: 'Schedule window' }).click()
    const startsAt = await screen.findByLabelText('Start')
    fireEvent.change(startsAt, { target: { value: '2099-06-15T08:00' } })
    const endsAt = await screen.findByLabelText('End')
    fireEvent.change(endsAt, { target: { value: '2099-06-15T09:00' } })
    const messageAr = await screen.findByLabelText('Message (Arabic)')
    fireEvent.change(messageAr, { target: { value: 'صيانة اختبار' } })
    const messageEn = await screen.findByLabelText('Message (English)')
    fireEvent.change(messageEn, { target: { value: 'E2E maintenance' } })
    screen.getByRole('button', { name: 'Schedule' }).click()
    await waitFor(() => expect(scheduleSpy).toHaveBeenCalledTimes(1))
    expect(scheduleSpy).toHaveBeenCalledWith('csrf-token', expect.objectContaining({
      message_ar: 'صيانة اختبار',
      message_en: 'E2E maintenance',
      ends_at: '2099-06-15T09:00',
    }))
  })

  it('calls cancelPlatformMaintenanceWindow with the active window lock version and token', async () => {
    const cancelSpy = vi.spyOn(api, 'cancelPlatformMaintenanceWindow').mockResolvedValue({} as never)
    const screenData = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel'],
    )
    const resource = {
      id: 'maintenance',
      items: [
        {
          id: '019f8e3b-3368-7192-85a6-3da3949fd707',
          status: 'active',
          starts_at: '2026-07-23T10:00:00+03:00',
          ends_at: '2026-07-23T11:00:00+03:00',
          lock_version: 4,
        },
      ],
      next_cursor: null,
    } as never
    render(<MaintenanceScreen locale="en" token="csrf-token" {...screenData} resource={resource} />)
    screen.getByRole('button', { name: 'Cancel current window' }).click()
    const confirm = await screen.findByRole('button', { name: 'Confirm cancel' })
    confirm.click()
    await waitFor(() => expect(cancelSpy).toHaveBeenCalledTimes(1))
    expect(cancelSpy).toHaveBeenCalledWith('csrf-token', '019f8e3b-3368-7192-85a6-3da3949fd707', 4)
  })

  it('surfaces a 403 authorization failure when the cancel mutation is rejected', async () => {
    vi.spyOn(api, 'cancelPlatformMaintenanceWindow').mockRejectedValueOnce(
      new ApiError(403, {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
        detail: 'Access denied.',
      } as never),
    )
    const screenData = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel'],
    )
    const resource = {
      id: 'maintenance',
      items: [
        {
          id: '019f8e3b-3368-7192-85a6-3da3949fd707',
          status: 'active',
          starts_at: '2026-07-23T10:00:00+03:00',
          ends_at: '2026-07-23T11:00:00+03:00',
          lock_version: 1,
        },
      ],
      next_cursor: null,
    } as never
    render(<MaintenanceScreen locale="en" token="test-token" {...screenData} resource={resource} />)
    fireEvent.click(screen.getByRole('button', { name: 'Cancel current window' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm cancel' }))
    await waitFor(() => {
      expect(screen.getByRole('alert').textContent ?? '').toMatch(/Access denied\./i)
    })
  })
  it('prevents double-submission: a second cancel click while the first is in flight is ignored', async () => {
    let resolve!: (value: unknown) => void
    const inFlight = new Promise<unknown>((res) => { resolve = res })
    const cancelSpy = vi.spyOn(api, 'cancelPlatformMaintenanceWindow').mockImplementation(
      () => inFlight as never,
    )
    const screenData = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel'],
    )
    const resource = {
      id: 'maintenance',
      items: [
        {
          id: '019f8e3b-3368-7192-85a6-3da3949fd707',
          status: 'active',
          starts_at: '2026-07-23T10:00:00+03:00',
          ends_at: '2026-07-23T11:00:00+03:00',
          lock_version: 1,
        },
      ],
      next_cursor: null,
    } as never
    render(<MaintenanceScreen locale="en" token="test-token" {...screenData} resource={resource} />)
    fireEvent.click(screen.getByRole('button', { name: 'Cancel current window' }))
    const confirm = await screen.findByRole('button', { name: 'Confirm cancel' })
    confirm.click()
    await waitFor(() => expect(cancelSpy).toHaveBeenCalledTimes(1))
    confirm.click()
    confirm.click()
    expect(cancelSpy).toHaveBeenCalledTimes(1)
    resolve({})
    await inFlight
  })
})
