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
  it('keeps actions in the generated resource projection and emits a deterministic second log page', () => {
    const first = platformSettingsMockFor('logs', ['platform_operations.logs.read'])
    const restoreAllowed = platformSettingsMockFor('logs', ['platform_operations.logs.read', 'platform_operations.logs.restore'])
    const second = platformSettingsMockFor('logs', ['platform_operations.logs.read', 'platform_operations.logs.restore'], 'platform-logs-2')

    expect(first.allowedActions).toEqual([])
    expect(restoreAllowed.allowedActions).toEqual(['platform_operations.logs.restore'])
    expect('items' in first.resource && first.resource.next_cursor).toBe('platform-logs-2')
    expect('items' in second.resource && second.resource.next_cursor).toBeNull()
  })

  it('does not project backup.run for a health reader', () => {
    const overview = platformSettingsMockFor('overview', ['platform_operations.health.read'])

    expect(overview.allowedActions).toEqual(['platform_operations.health.read'])
    expect(overview.allowedActions).not.toContain('platform_operations.backup.run')
  })

  it('uses the mock source by default without invoking a network wrapper', async () => {
    const result = await mockPlatformSettingsDataSource.load({
      section: 'logs',
      capabilities: ['platform_operations.logs.read'],
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
})
