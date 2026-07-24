// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../api'

vi.mock('../../api/platform-settings', () => ({
  requestPlatformBackup: vi.fn(async () => ({
    operation_id: 'op-001',
    status: 'requested',
  })),
  requestPlatformRestore: vi.fn(async () => ({
    operation_id: 'restore-001',
    status: 'requested',
  })),
  confirmPlatformRestore: vi.fn(async () => ({
    status: 'confirmed',
  })),
}))

import { requestPlatformBackup } from '../../api/platform-settings'

import { BackupsScreen } from './BackupsScreen'

afterEach(cleanup)

describe('BackupsScreen', () => {
  it('does not disclose storage paths and reports idempotent backup progress', async () => {
    render(
      <BackupsScreen
        locale="en"
        allowedActions={['platform_operations.backup.run']}
        token="test-token"
      />,
    )
    expect(screen.queryByText(/s3:|\/var\/|credential/i)).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))
    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toMatch(/op-001/)
    })
    expect(requestPlatformBackup).toHaveBeenCalledWith('test-token')
  })

  it('does not render the hardcoded 2026-07-23 06:00 last-success sentinel', () => {
    render(
      <BackupsScreen
        locale="en"
        allowedActions={['platform_operations.backup.run']}
        token="test-token"
      />,
    )

    expect(screen.queryByText('2026-07-23 06:00')).toBeNull()
  })

  it('hides the run backup button when no token is supplied', () => {
    render(<BackupsScreen locale="en" allowedActions={['platform_operations.backup.run']} />)
    expect(screen.queryByRole('button', { name: 'Run backup now' })).toBeNull()
  })

  it('surfaces an error message when the backup request fails', async () => {
    vi.mocked(requestPlatformBackup).mockRejectedValueOnce(
      new ApiError(503, {
        type: 'about:blank',
        title: 'Service unavailable',
        status: 503,
        detail: 'Backup queue is full.',
      }),
    )
    render(
      <BackupsScreen
        locale="en"
        allowedActions={['platform_operations.backup.run']}
        token="test-token"
      />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))
    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toMatch(/Backup queue is full/)
    })
  })
})
