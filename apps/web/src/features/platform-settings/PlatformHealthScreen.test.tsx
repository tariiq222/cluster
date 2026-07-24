// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../api'
import * as api from '../../api/platform-settings'
import { PlatformHealthScreen } from './PlatformHealthScreen'

afterEach(cleanup)
afterEach(() => vi.restoreAllMocks())

const healthResource = {
  id: 'health',
  status: 'healthy',
  updated_at: '2026-07-23T09:30:00+03:00',
  checks: [
    { code: 'database', status: 'healthy', latency_ms: 18, message_code: 'database_healthy' },
    { code: 'redis', status: 'degraded', latency_ms: 220, message_code: 'redis_degraded' },
    { code: 'storage', status: 'healthy', latency_ms: 32, message_code: 'storage_healthy' },
  ],
} as never

const alertPolicyRow = {
  id: '01980f50-5f0d-7000-8000-000000000811',
  resource_type: 'platform_alert_policy',
  status: 'published',
  classification: 'internal',
  lock_version: 1,
  created_at: '2026-07-23T09:30:00+03:00',
  updated_at: '2026-07-23T09:30:00+03:00',
  allowed_actions: [],
  values: { severity: 'warning', status: 'active', channel: 'in_app', description: 'Database latency' },
}

function alertPolicyList() {
  return { items: [alertPolicyRow], next_cursor: null } as never
}

describe('PlatformHealthScreen', () => {
  it('renders safe latency and no exception trace', () => {
    render(<PlatformHealthScreen locale="en" resource={healthResource} />)
    expect(screen.getByText('database — 18ms')).toBeTruthy()
    expect(screen.queryByText(/Exception|Stack trace/)).toBeNull()
  })

  it('does not render a hardcoded "Checked 09:30" freshness sentinel', () => {
    render(<PlatformHealthScreen locale="en" resource={healthResource} />)
    expect(screen.queryByText(/Checked 09:30/)).toBeNull()
  })

  it('shows a non-literal freshness stamp when the snapshot is reported stale', () => {
    render(<PlatformHealthScreen locale="en" state="stale" resource={healthResource} />)
    const freshness = screen.getByLabelText('Stale data')
    expect(freshness.textContent).not.toMatch(/(Checked|فُحصت)\s+09:30/)
  })

  it('loads the initial alert policy list through listPlatformAlertPolicies', async () => {
    const listSpy = vi.spyOn(api, 'listPlatformAlertPolicies').mockResolvedValue(alertPolicyList())
    render(
      <PlatformHealthScreen
        locale="en"
        resource={healthResource}
        allowedActions={['platform_operations.alerts.manage']}
        token="csrf-token"
      />,
    )
    await waitFor(() => expect(listSpy).toHaveBeenCalledTimes(1))
    expect(listSpy).toHaveBeenCalledWith('csrf-token')
    expect(screen.getByText('Database latency — warning')).toBeTruthy()
  })

  it('calls updatePlatformAlertPolicy with the policy lockVersion and refreshes the list', async () => {
    const updated = vi.fn().mockResolvedValue({ severity: 'info', lock_version: 2 } as never)
    const refreshed = vi.fn().mockResolvedValue({
      items: [
        {
          ...alertPolicyRow,
          lock_version: 2,
          values: { severity: 'info', status: 'active', channel: 'in_app', description: 'Database latency' },
        },
      ],
      next_cursor: null,
    } as never)
    vi.spyOn(api, 'listPlatformAlertPolicies')
      .mockResolvedValueOnce(alertPolicyList())
      .mockImplementationOnce(refreshed)
    vi.spyOn(api, 'updatePlatformAlertPolicy').mockImplementation(updated)
    render(
      <PlatformHealthScreen
        locale="en"
        resource={healthResource}
        allowedActions={['platform_operations.alerts.manage']}
        token="csrf-token"
      />,
    )
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toBeTruthy())
    screen.getByRole('button', { name: 'Edit' }).click()
    const statusInput = await screen.findByLabelText('Status')
    fireEvent.change(statusInput, { target: { value: 'paused' } })
    const severityInput = await screen.findByLabelText('Severity')
    fireEvent.change(severityInput, { target: { value: 'info' } })
    const saveButton = await screen.findByRole('button', { name: 'Save' })
    saveButton.click()
    await waitFor(() => expect(updated).toHaveBeenCalledTimes(1))
    expect(updated).toHaveBeenCalledWith(
      'csrf-token',
      '01980f50-5f0d-7000-8000-000000000811',
      expect.objectContaining({ status: 'paused', severity: 'info' }),
      1,
    )
    await waitFor(() => expect(refreshed).toHaveBeenCalledTimes(1))
  })

  it('surfaces the API error from updatePlatformAlertPolicy as a 412 conflict', async () => {
    vi.spyOn(api, 'listPlatformAlertPolicies').mockResolvedValue(alertPolicyList())
    vi.spyOn(api, 'updatePlatformAlertPolicy').mockRejectedValue(new ApiError(412, {
      type: 'https://cluster.example/problems/precondition-failed',
      title: 'Precondition Failed',
      status: 412,
      detail: 'If-Match does not match the current alert policy.',
    }))
    render(
      <PlatformHealthScreen
        locale="en"
        resource={healthResource}
        allowedActions={['platform_operations.alerts.manage']}
        token="csrf-token"
      />,
    )
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toBeTruthy())
    screen.getByRole('button', { name: 'Edit' }).click()
    const saveButton = await screen.findByRole('button', { name: 'Save' })
    saveButton.click()
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Precondition Failed'))
  })

  it('surfaces the API error from updatePlatformAlertPolicy as a 403 authorization failure', async () => {
    vi.spyOn(api, 'listPlatformAlertPolicies').mockResolvedValue(alertPolicyList())
    vi.spyOn(api, 'updatePlatformAlertPolicy').mockRejectedValue(new ApiError(403, {
      type: 'https://cluster.example/problems/access-denied',
      title: 'Forbidden',
      status: 403,
      detail: 'Access denied.',
    }))
    render(
      <PlatformHealthScreen
        locale="en"
        resource={healthResource}
        allowedActions={['platform_operations.alerts.manage']}
        token="csrf-token"
      />,
    )
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toBeTruthy())
    screen.getByRole('button', { name: 'Edit' }).click()
    const saveButton = await screen.findByRole('button', { name: 'Save' })
    saveButton.click()
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Forbidden'))
  })
  it('prevents double-submission: a second save click while the first is in flight is ignored', async () => {
    let resolve!: (value: unknown) => void
    const inFlight = new Promise<unknown>((res) => { resolve = res })
    const updated = vi.spyOn(api, 'updatePlatformAlertPolicy').mockImplementation(
      () => inFlight as never,
    )
    vi.spyOn(api, 'listPlatformAlertPolicies').mockResolvedValue(alertPolicyList())
    render(
      <PlatformHealthScreen
        locale="en"
        resource={healthResource}
        allowedActions={['platform_operations.alerts.manage']}
        token="csrf-token"
      />,
    )
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toBeTruthy())
    screen.getByRole('button', { name: 'Edit' }).click()
    const save = await screen.findByRole('button', { name: 'Save' })
    save.click()
    await waitFor(() => expect(updated).toHaveBeenCalledTimes(1))
    save.click()
    save.click()
    expect(updated).toHaveBeenCalledTimes(1)
    resolve({})
    await inFlight
  })
})
