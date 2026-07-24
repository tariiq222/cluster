// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../api'
import * as api from '../../api/platform-settings'
import { PlatformOverviewScreen } from './PlatformOverviewScreen'
import { platformSettingsMockFor } from './PlatformSettingsMockData'

vi.mock('../../api/platform-settings', () => ({
  getPlatformHealth: vi.fn(async () => ({
    status: 'healthy',
    updated_at: '2026-07-23T09:30:00+03:00',
    checks: [
      { code: 'database', status: 'healthy', latency_ms: 18, message_code: 'database_healthy' },
      { code: 'redis', status: 'degraded', latency_ms: 220, message_code: 'redis_degraded' },
      { code: 'storage', status: 'healthy', latency_ms: 32, message_code: 'storage_healthy' },
    ],
  })),
  getPlatformBackups: vi.fn(async () => ({
    status: 'healthy',
    last_successful_at: '2026-07-23T09:00:00+03:00',
  })),
  getPlatformOperationsOverview: vi.fn(async () => ({
    status: 'healthy',
    updated_at: '2026-07-23T09:30:00+03:00',
    issues: [],
    metrics: {},
  })),
  requestPlatformBackup: vi.fn(async () => ({ operation_id: 'op-001', status: 'requested' })),
}))

afterEach(cleanup)

describe('PlatformOverviewScreen', () => {
  it('renders the Arabic control-center order and never offers maintenance as a quick action', () => {
    render(<PlatformOverviewScreen locale="ar" token="test-token" />)

    expect(screen.getByText('الخدمات')).toBeTruthy()
    expect(screen.getByText('آخر نسخة')).toBeTruthy()
    expect(screen.queryByRole('button', { name: /صيانة/i })).toBeNull()
  })

  it('renders the server-shaped action projection supplied by the local adapter', () => {
    const screenData = platformSettingsMockFor('overview', ['platform_operations.health.read'])
    render(<PlatformOverviewScreen locale="en" token="test-token" {...screenData} />)

    expect(screen.getByRole('button', { name: 'Refresh check' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Run backup now' })).toBeNull()
  })

  it('reflects the resource-provided freshness timestamp instead of a literal clock string', () => {
    const screenData = platformSettingsMockFor('overview', ['platform_operations.health.read'])
    screenData.state = 'stale'
    render(<PlatformOverviewScreen locale="en" token="test-token" {...screenData} />)

    const freshness = screen.getByLabelText('Stale data')
    expect(freshness.textContent).not.toMatch(/Last updated: 09:30/)
  })

  it('attributes the degraded snapshot to a specific sub-source', () => {
    render(
      <PlatformOverviewScreen
        locale="en"
        token="test-token"
        resource={{ id: 'overview', status: 'degraded', updated_at: '2026-07-23T09:30:00+03:00' } as never}
      />,
    )

    expect(screen.getByText(/health source unavailable/i)).toBeTruthy()
  })

  it('requests a backup and surfaces the operation identifier returned by the API', async () => {
    const screenData = platformSettingsMockFor('overview', [
      'platform_operations.health.read',
      'platform_operations.backup.run',
    ])
    render(<PlatformOverviewScreen locale="en" token="test-token" {...screenData} />)

    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))

    await waitFor(() => {
      expect(api.requestPlatformBackup).toHaveBeenCalledWith('test-token')
    })
    expect(await screen.findByRole('status').then((el) => el.textContent)).toMatch(/op-001/)
  })

  it('announces backup progress while the request is active', async () => {
    let resolveBackup: (() => void) | undefined
    vi.mocked(api.requestPlatformBackup).mockImplementationOnce(
      () => new Promise((resolve) => {
        resolveBackup = () => resolve({ operation_id: 'op-002', status: 'requested' } as never)
      }),
    )
    const screenData = platformSettingsMockFor('overview', [
      'platform_operations.health.read',
      'platform_operations.backup.run',
    ])
    render(<PlatformOverviewScreen locale="en" token="test-token" {...screenData} />)

    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))

    expect(screen.getByRole('button', { name: 'Running backup…' })).toBeTruthy()
    resolveBackup?.()
    expect(await screen.findByText(/op-002/)).toBeTruthy()
  })

  it('renders the service timestamp as localized time instead of raw API text', () => {
    render(
      <PlatformOverviewScreen
        locale="en"
        resource={{ id: 'overview', status: 'healthy', updated_at: '2026-07-23T09:30:00+03:00' } as never}
      />,
    )

    const timestamp = document.querySelector('time[datetime="2026-07-23T09:30:00+03:00"]')
    expect(timestamp).toBeTruthy()
    expect(timestamp?.textContent).not.toBe('2026-07-23T09:30:00+03:00')
  })

  it('surfaces the API problem detail when the backup dispatch fails', async () => {
    vi.spyOn(api, 'requestPlatformBackup').mockRejectedValueOnce(
      new ApiError(503, {
        type: 'about:blank',
        title: 'Service unavailable',
        status: 503,
        detail: 'Backup dispatch is temporarily unavailable.',
      } as never),
    )
    const screenData = platformSettingsMockFor('overview', [
      'platform_operations.health.read',
      'platform_operations.backup.run',
    ])
    render(<PlatformOverviewScreen locale="en" token="test-token" {...screenData} />)

    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent ?? '').toMatch(/Backup dispatch is temporarily unavailable/i)
    })
  })
})
