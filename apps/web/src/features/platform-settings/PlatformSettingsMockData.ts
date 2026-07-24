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
  listBusinessCalendars,
  listPlatformMaintenanceWindows,
  listPlatformTechnicalLogs,
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

const logPageOne = {
  items: [
    { id: '019f8e3b-3368-7192-85a6-3da3949fd711', source: 'queue', severity: 'warning', message_ar: 'زمن الاستجابة تجاوز العتبة', message_en: 'Latency exceeded the threshold', occurred_at: '09:18' },
    { id: '019f8e3b-3368-7192-85a6-3da3949fd712', source: 'backup', severity: 'info', message_ar: 'اكتمل التحقق من النسخة', message_en: 'Backup verification completed', occurred_at: '06:08' },
  ],
  next_cursor: 'platform-logs-2',
} as unknown as CollectionResponse

const logPageTwo = {
  items: [
    { id: '019f8e3b-3368-7192-85a6-3da3949fd713', source: 'storage', severity: 'critical', message_ar: 'فشل فحص السعة', message_en: 'Capacity check failed', occurred_at: '04:32' },
  ],
  next_cursor: null,
} as unknown as CollectionResponse

const actionCapability: Record<string, string> = {
  'platform_operations.health.read': 'platform_operations.health.read',
  'platform_operations.backup.run': 'platform_operations.backup.run',
  'platform_operations.restore.request': 'platform_operations.restore.request',
  'platform_operations.logs.restore': 'platform_operations.logs.restore',
  'platform_operations.alerts.manage': 'platform_operations.alerts.manage',
  'platform_operations.maintenance.manage': 'platform_operations.maintenance.manage',
  'platform_operations.maintenance.cancel': 'platform_operations.maintenance.cancel',
  'platform_settings.manage': 'platform_settings.manage',
  'platform_settings.publish': 'platform_settings.publish',
  'platform_settings.calendar.manage': 'platform_settings.calendar.manage',
  'platform_settings.calendar.read': 'platform_settings.calendar.read',
  'platform_settings.calendar.publish': 'platform_settings.publish',
  'platform_settings.calendar.override_official_holiday': 'platform_settings.calendar.override_official_holiday',
}

function actionAliases(action: string): readonly string[] {
  const aliases = [action]
  const finalSeparator = action.lastIndexOf('.')
  if (finalSeparator > 0) aliases.push(action.slice(finalSeparator + 1))
  if (action.startsWith('platform_operations.')) aliases.push(action.slice('platform_operations.'.length))
  if (action.startsWith('platform_settings.')) aliases.push(action.slice('platform_settings.'.length))
  return aliases
}

function projectedActions(serverActions: readonly string[], capabilities: readonly string[]): string[] {
  const server = new Set(serverActions)
  const hasServerActions = serverActions.length > 0
  return Object.entries(actionCapability)
    .filter(([action, requiredCapability]) => {
      if (!capabilities.includes(requiredCapability)) return false
      if (!hasServerActions) return true
      return actionAliases(action).some((alias) => server.has(alias))
    })
    .map(([action]) => action)
}

function actionsFromResource(resource: EntityResponse | CollectionResponse, capabilities: readonly string[]): string[] {
  const serverActions = 'items' in resource
    ? resource.items.flatMap((item) => item.allowed_actions ?? [])
    : resource.allowed_actions ?? []
  return projectedActions(serverActions, capabilities)
}



export function platformSettingsMockFor(
  section: PlatformSettingsSection,
  capabilities: readonly string[] | null,
  cursor: string | null = null,
): PlatformSettingsMockScreen {
  if (capabilities === null) return { state: 'loading', resource: bySection[section].resource, allowedActions: [] }
  // The maintenance section exposes both manage and cancel as first-class
  // actions; the route guard requires `manage`, but a delegated cancel-only
  // principal must still be admitted to view the cancel control.
  const required = capabilitiesForRoute({ name: 'platform-settings', section }) ?? []
  const admitsSection = required.some((capability) => capabilities.includes(capability))
    || (section === 'maintenance' && capabilities.includes('platform_operations.maintenance.cancel'))
  if (!admitsSection) {
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
    async load({ section, capabilities, cursor }) {
      if (capabilities === null) return platformSettingsMockFor(section, capabilities, null)
      const required = capabilitiesForRoute({ name: 'platform-settings', section }) ?? []
      if (!required.some((capability) => capabilities.includes(capability))) {
        return platformSettingsMockFor(section, capabilities, null)
      }

      // Each supported section terminates in the live API; mock data is only
      // used when the principal cannot authorize the section.
      const resource: EntityResponse | CollectionResponse | null = await (async () => {
        switch (section) {
          case 'overview':
            return await getPlatformOperationsOverview(token)
          case 'security':
            return await getCurrentPlatformSettings(token)
          case 'calendars':
            return await listBusinessCalendars(token)
          case 'backups':
            return await getPlatformBackups(token)
          case 'health':
            return await getPlatformHealth(token)
          case 'logs':
            return await listPlatformTechnicalLogs(token, cursor ? { cursor } : {})
          case 'maintenance':
            return await listPlatformMaintenanceWindows(token)
        }
      })()
      if (resource === null) {
        throw new Error(`Live data source has no endpoint for section "${section}".`)
      }
      return {
        state: 'success',
        resource,
        allowedActions: actionsFromResource(resource, capabilities),
      }
    },
  }
}
