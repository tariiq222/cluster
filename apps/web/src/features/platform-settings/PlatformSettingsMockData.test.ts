import { beforeEach, describe, expect, it, vi } from 'vitest'

const platformWrappers = vi.hoisted(() => ({
  getCurrentPlatformSettings: vi.fn(),
  getPlatformBackups: vi.fn(),
  getPlatformHealth: vi.fn(),
  getPlatformOperationsOverview: vi.fn(),
}))

vi.mock('../../api/platform-settings', () => platformWrappers)

import {
  createLivePlatformSettingsDataSource,
  mockPlatformSettingsDataSource,
  platformSettingsMockFor,
} from './PlatformSettingsMockData'

beforeEach(() => vi.clearAllMocks())

describe('platformSettings data sources', () => {
  it('returns denied for the deferred logs section even when the principal holds the deferred capability', () => {
    const first = platformSettingsMockFor('logs', ['platform_operations.logs.read'])
    const restoreAllowed = platformSettingsMockFor('logs', ['platform_operations.logs.read', 'platform_operations.logs.restore'])
    const second = platformSettingsMockFor('logs', ['platform_operations.logs.read', 'platform_operations.logs.restore'], 'platform-logs-2')

    expect(first.state).toBe('denied')
    expect(restoreAllowed.state).toBe('denied')
    expect(second.state).toBe('denied')
  })

  it('does not project backup.run for a health reader', () => {
    const overview = platformSettingsMockFor('overview', ['platform_operations.health.read'])

    expect(overview.allowedActions).toEqual(['platform_operations.health.read'])
    expect(overview.allowedActions).not.toContain('platform_operations.backup.run')
  })

  it('uses the mock source by default without invoking a network wrapper', async () => {
    const result = await mockPlatformSettingsDataSource.load({
      section: 'health',
      capabilities: ['platform_operations.health.read'],
    })

    expect(result.state).toBe('success')
    expect(platformWrappers.getPlatformOperationsOverview).not.toHaveBeenCalled()
    expect(platformWrappers.getPlatformHealth).not.toHaveBeenCalled()
    expect(platformWrappers.getPlatformBackups).not.toHaveBeenCalled()
    expect(platformWrappers.getCurrentPlatformSettings).not.toHaveBeenCalled()
  })

  it('uses the generated wrapper boundary only through the opt-in live source', async () => {
    const generatedResponse = platformSettingsMockFor('overview', [
      'platform_operations.health.read',
      'platform_operations.backup.run',
    ]).resource
    platformWrappers.getPlatformOperationsOverview.mockResolvedValue(generatedResponse)

    const result = await createLivePlatformSettingsDataSource('session-token').load({
      section: 'overview',
      capabilities: ['platform_operations.health.read', 'platform_operations.backup.run'],
    })

    expect(platformWrappers.getPlatformOperationsOverview).toHaveBeenCalledWith('session-token')
    expect(result.resource).toBe(generatedResponse)
    expect(result.allowedActions).toEqual(['platform_operations.health.read', 'platform_operations.backup.run'])
  })

  // RED: audit claim — inconsistent policy projection across sections.
  // Maintenance has a sub-gated `cancel` action that the mock maps to the
  // broader `manage` capability. The cancel capability alone, however, is
  // not in any action's "required capability" mapping, so a delegated
  // principal holding only the cancel capability is projected NO actions
  // and the cancel control never appears. The audit says the projection
  // must hold consistently across sections: any capability that the
  // resource exposes should reach the user.
  it('projects the maintenance cancel action when only the cancel capability is granted', () => {
    const cancelOnly = platformSettingsMockFor(
      'maintenance',
      ['platform_operations.maintenance.cancel'],
    )

    expect(cancelOnly.allowedActions).toContain('platform_operations.maintenance.cancel')
  })
})
