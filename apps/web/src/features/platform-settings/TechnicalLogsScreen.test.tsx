// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as api from '../../api/platform-settings'
import { platformSettingsMockFor } from './PlatformSettingsMockData'
import { TechnicalLogsScreen } from './TechnicalLogsScreen'

afterEach(cleanup)
beforeEach(() => {
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
})
describe('TechnicalLogsScreen', () => {
  it('filters generated-response log rows and follows the deterministic cursor', () => {
    const onCursorChange = vi.fn()
    const logs = {
      items: [
        { id: '019f8e3b-3368-7192-85a6-3da3949fd711', source: 'queue', severity: 'warning', message_ar: 'زمن الاستجابة تجاوز العتبة', message_en: 'Latency exceeded the threshold', occurred_at: '09:18' },
        { id: '019f8e3b-3368-7192-85a6-3da3949fd712', source: 'backup', severity: 'info', message_ar: 'اكتمل التحقق من النسخة', message_en: 'Backup verification completed', occurred_at: '06:08' },
      ],
      next_cursor: 'platform-logs-2',
    } as unknown as Parameters<typeof TechnicalLogsScreen>[0]['logs']
    render(<TechnicalLogsScreen locale="en" token="test-token" state="success" allowedActions={['platform_operations.logs.restore']} logs={logs} onCursorChange={onCursorChange} />)
    expect(screen.getByText('queue')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Request archive restore' })).toBeTruthy()
    expect(screen.queryByText(/\{.*\}/)).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Severity' }))
    fireEvent.click(screen.getByRole('option', { name: 'Warning' }))
    expect(screen.getByText('Latency exceeded the threshold')).toBeTruthy()
    expect(screen.queryByText('Backup verification completed')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }))
    expect(onCursorChange).toHaveBeenCalledWith('platform-logs-2')
  })

  it('uses the adapter denied state to withhold both logs and mutations', () => {
    const screenData = platformSettingsMockFor('logs', [])
    render(<TechnicalLogsScreen locale="en" {...screenData} />)

    expect(screen.getByText('You do not have access to this section')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Request archive restore' })).toBeNull()
  })

  it('submits a validated archive restore request to the live API', async () => {
    const restore = vi.spyOn(api, 'requestPlatformTechnicalLogsRestore').mockResolvedValue({
      id: 'restore-001',
      status: 'requested',
    } as never)
    const logs = {
      items: [
        { id: 'log-1', source: 'queue', severity: 'warning', message_ar: 'تحذير', message_en: 'Warning', occurred_at: '2026-07-24T09:18:00+03:00' },
      ],
      next_cursor: null,
    } as unknown as Parameters<typeof TechnicalLogsScreen>[0]['logs']
    render(
      <TechnicalLogsScreen
        locale="en"
        token="test-token"
        state="success"
        allowedActions={['platform_operations.logs.restore']}
        logs={logs}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Request archive restore' }))
    fireEvent.change(screen.getByLabelText(/Archive manifest ID/), { target: { value: 'manifest-2026-07' } })
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Incident review' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }))

    await waitFor(() => {
      expect(restore).toHaveBeenCalledWith('test-token', {
        manifest_id: 'manifest-2026-07',
        reason: 'Incident review',
      })
    })
    expect((await screen.findByRole('status')).textContent).toBe('Archive restore requested.')
  })

  it('keeps the restore drawer open and explains required fields', () => {
    const logs = {
      items: [
        { id: 'log-1', source: 'queue', severity: 'warning', message_ar: 'تحذير', message_en: 'Warning', occurred_at: '2026-07-24T09:18:00+03:00' },
      ],
      next_cursor: null,
    } as unknown as Parameters<typeof TechnicalLogsScreen>[0]['logs']
    render(
      <TechnicalLogsScreen
        locale="en"
        token="test-token"
        state="success"
        allowedActions={['platform_operations.logs.restore']}
        logs={logs}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Request archive restore' }))
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }))

    expect(screen.getByRole('alert').textContent).toBe('Enter the archive manifest ID and reason.')
    expect(screen.getByRole('dialog', { name: 'Archive restore' })).toBeTruthy()
  })
})
