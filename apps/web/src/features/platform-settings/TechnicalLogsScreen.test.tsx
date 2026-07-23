// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { platformSettingsMockFor } from './PlatformSettingsMockData'
import { TechnicalLogsScreen } from './TechnicalLogsScreen'

afterEach(cleanup)
beforeEach(() => {
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
})

describe('TechnicalLogsScreen', () => {
  it('filters generated-response log rows and follows the deterministic cursor', () => {
    const screenData = platformSettingsMockFor('logs', ['platform_operations.logs.read', 'platform_operations.logs.restore'])
    const onCursorChange = vi.fn()
    const logs = 'items' in screenData.resource ? screenData.resource : undefined
    render(<TechnicalLogsScreen locale="en" {...screenData} logs={logs} onCursorChange={onCursorChange} />)

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
})
