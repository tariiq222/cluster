/**
 * Local adapter for the platform-settings vertical slice.
 *
 * Replace `platformSettingsMockFor` with Task9's generated-client boundary
 * when its authenticated query hooks are introduced. The screen contract stays
 * the generated `EntityResponse` / `CollectionResponse` shape and never grows
 * a hand-written transport type.
 */
import {
  Classification,
  DomainResourceResourceType,
  type CollectionResponse,
  type DomainResource,
  type EntityResponse,
} from '../../api/generated/cluster'
import { capabilitiesForRoute, type PlatformSettingsSection } from '../../shell/routes'
import {
  getCurrentPlatformSettings,
  getPlatformBackups,
  getPlatformHealth,
  getPlatformOperationsOverview,
} from '../../api/platform-settings'
import type { PlatformScreenState } from './screen-support'

export type PlatformSettingsMockScreen = {
  state: PlatformScreenState
  resource: EntityResponse | CollectionResponse
  allowedActions: readonly string[]
}

export type PlatformSettingsDataSourceInput = {
  section: PlatformSettingsSection
  capabilities: readonly string[] | null
  cursor?: string | null
}

/**
 * Both implementations terminate in generated response shapes. The mock is
 * intentionally the V1 default; `live` is opt-in until every section has a
 * Task9 wrapper and an authenticated query contract.
 */
export interface PlatformSettingsDataSource {
  load(input: PlatformSettingsDataSourceInput): Promise<PlatformSettingsMockScreen>
}

type PlatformSettingsFixture = {
  resource: EntityResponse | CollectionResponse
  /** Actions the server response is willing to project for this resource. */
  serverActions: readonly string[]
}

const timestamp = '2026-07-23T09:30:00+03:00'

function entity(
  id: string,
  resourceType: DomainResource['resource_type'],
  allowedActions: string[],
  values: Record<string, unknown> = {},
): DomainResource {
  return {
    id,
    resource_type: resourceType,
    status: 'active',
    classification: Classification.internal,
    lock_version: 1,
    created_at: timestamp,
    updated_at: timestamp,
    allowed_actions: allowedActions,
    values,
  }
}

const bySection: Record<PlatformSettingsSection, PlatformSettingsFixture> = {
  overview: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd701', DomainResourceResourceType.platform_settings_version, ['platform_operations.health.read', 'platform_operations.backup.run'], { health: 'degraded', services: 7 }), serverActions: ['platform_operations.health.read', 'platform_operations.backup.run'] },
  security: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd702', DomainResourceResourceType.platform_settings_version, ['platform_settings.manage', 'platform_settings.publish']), serverActions: ['platform_settings.manage', 'platform_settings.publish'] },
  calendars: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd703', DomainResourceResourceType.business_calendar, ['platform_settings.calendar.override_official_holiday']), serverActions: ['platform_settings.calendar.override_official_holiday'] },
  backups: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd704', DomainResourceResourceType.platform_settings_version, ['platform_operations.backup.run', 'platform_operations.restore.request']), serverActions: ['platform_operations.backup.run', 'platform_operations.restore.request'] },
  logs: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd705', DomainResourceResourceType.audit_event, ['platform_operations.logs.restore']), serverActions: ['platform_operations.logs.restore'] },
  health: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd706', DomainResourceResourceType.platform_settings_version, ['platform_operations.alerts.manage']), serverActions: ['platform_operations.alerts.manage'] },
  maintenance: { resource: entity('019f8e3b-3368-7192-85a6-3da3949fd707', DomainResourceResourceType.platform_settings_version, ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel']), serverActions: ['platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel'] },
}

const logPageOne: CollectionResponse = {
  items: [
    entity('019f8e3b-3368-7192-85a6-3da3949fd711', DomainResourceResourceType.audit_event, ['platform_operations.logs.restore'], { source: 'queue', severity: 'warning', message_ar: 'زمن الاستجابة تجاوز العتبة', message_en: 'Latency exceeded the threshold', occurred_at: '09:18' }),
    entity('019f8e3b-3368-7192-85a6-3da3949fd712', DomainResourceResourceType.audit_event, ['platform_operations.logs.restore'], { source: 'backup', severity: 'info', message_ar: 'اكتمل التحقق من النسخة', message_en: 'Backup verification completed', occurred_at: '06:08' }),
  ],
  next_cursor: 'platform-logs-2',
}

const logPageTwo: CollectionResponse = {
  items: [
    entity('019f8e3b-3368-7192-85a6-3da3949fd713', DomainResourceResourceType.audit_event, ['platform_operations.logs.restore'], { source: 'storage', severity: 'critical', message_ar: 'فشل فحص السعة', message_en: 'Capacity check failed', occurred_at: '04:32' }),
  ],
  next_cursor: null,
}

const actionCapability: Record<string, string> = {
  'platform_operations.health.read': 'platform_operations.health.read',
  'platform_operations.backup.run': 'platform_operations.backup.run',
  'platform_operations.restore.request': 'platform_operations.restore.request',
  'platform_operations.logs.restore': 'platform_operations.logs.restore',
  'platform_operations.alerts.manage': 'platform_operations.alerts.manage',
  'platform_operations.maintenance.manage': 'platform_operations.maintenance.manage',
  // Cancel is governed by the same maintenance-management capability in V1.
  'platform_operations.maintenance.cancel': 'platform_operations.maintenance.manage',
  'platform_settings.manage': 'platform_settings.manage',
  // Publish is part of the settings-management command surface in V1.
  'platform_settings.publish': 'platform_settings.manage',
  'platform_settings.calendar.override_official_holiday': 'platform_settings.calendar.override_official_holiday',
}

function projectedActions(serverActions: readonly string[], capabilities: readonly string[]): string[] {
  return serverActions.filter((action) => {
    const requiredCapability = actionCapability[action]
    return requiredCapability !== undefined && capabilities.includes(requiredCapability)
  })
}

function actionsFromResource(resource: EntityResponse | CollectionResponse, capabilities: readonly string[]): string[] {
  const serverActions = 'items' in resource
    ? resource.items.flatMap((item) => item.allowed_actions ?? [])
    : resource.allowed_actions ?? []
  // Authorization projections expose action suffixes (for example `read`),
  // while the feature policy uses fully-qualified capabilities. Accept both
  // representations at this boundary and always return the stable UI form.
  const qualified = new Set(serverActions)
  const suffixes = new Set(serverActions.map((action) => action.includes('.') ? action.slice(action.lastIndexOf('.') + 1) : action))
  return Object.entries(actionCapability)
    .filter(([, requiredCapability]) => capabilities.includes(requiredCapability))
    .filter(([action]) => qualified.has(action) || suffixes.has(action.slice(action.lastIndexOf('.') + 1)))
    .map(([action]) => action)
}

export function platformSettingsMockFor(
  section: PlatformSettingsSection,
  capabilities: readonly string[] | null,
  cursor: string | null = null,
): PlatformSettingsMockScreen {
  if (capabilities === null) return { state: 'loading', resource: bySection[section].resource, allowedActions: [] }
  const required = capabilitiesForRoute({ name: 'platform-settings', section }) ?? []
  if (!required.some((capability) => capabilities.includes(capability))) {
    return { state: 'denied', resource: bySection[section].resource, allowedActions: [] }
  }
  const fixture = bySection[section]
  const resource = section === 'logs' ? (cursor === 'platform-logs-2' ? logPageTwo : logPageOne) : fixture.resource
  return { state: 'success', resource, allowedActions: projectedActions(fixture.serverActions, capabilities) }
}

export const mockPlatformSettingsDataSource: PlatformSettingsDataSource = {
  async load({ section, capabilities, cursor = null }) {
    return platformSettingsMockFor(section, capabilities, cursor)
  },
}

export function createLivePlatformSettingsDataSource(token: string): PlatformSettingsDataSource {
  return {
    async load({ section, capabilities, cursor = null }) {
      if (capabilities === null) return platformSettingsMockFor(section, capabilities, cursor)
      const required = capabilitiesForRoute({ name: 'platform-settings', section }) ?? []
      if (!required.some((capability) => capabilities.includes(capability))) {
        return platformSettingsMockFor(section, capabilities, cursor)
      }

      // Only wrappers already generated by Task9 are live today. The remaining
      // screens deliberately retain deterministic mock data until their wrapper
      // is added, rather than constructing a feature-local HTTP request.
      const resource = await (async (): Promise<EntityResponse | null> => {
        switch (section) {
          case 'overview': return getPlatformOperationsOverview(token)
          case 'security': return getCurrentPlatformSettings(token)
          case 'backups': return getPlatformBackups(token)
          case 'health': return getPlatformHealth(token)
          default: return null
        }
      })()
      if (resource === null) return platformSettingsMockFor(section, capabilities, cursor)
      return {
        state: 'success',
        resource,
        allowedActions: actionsFromResource(resource, capabilities),
      }
    },
  }
}
