/**
 * Typed Platform Settings boundary for feature screens.
 *
 * Generated endpoint types and URLs remain in `generated/cluster`; this file
 * owns session-bound request options, response unwrapping, and stable names for
 * the feature. Screens must not build HTTP headers themselves.
 */
import {
  cancelPlatformMaintenanceWindow as cancelGeneratedPlatformMaintenanceWindow,
  confirmPlatformRestore as confirmGeneratedPlatformRestore,
  createPlatformSettingsCalendar,
  createPlatformSettingsDraft as createGeneratedPlatformSettingsDraft,
  dispatchPlatformBackup,
  getCurrentPlatformSettings as getGeneratedCurrentPlatformSettings,
  getPlatformBackups as getGeneratedPlatformBackups,
  getPlatformHealth as getGeneratedPlatformHealth,
  getPlatformOperationsOverview as getGeneratedPlatformOperationsOverview,
  listPlatformAlertPolicies as listGeneratedPlatformAlertPolicies,
  listPlatformMaintenanceWindows as listGeneratedPlatformMaintenanceWindows,
  listPlatformSettingsCalendars,
  listPlatformSettingsVersions as listGeneratedPlatformSettingsVersions,
  listPlatformTechnicalLogs as listGeneratedPlatformTechnicalLogs,
  publishPlatformSettingsCalendar,
  requestPlatformRestore as requestGeneratedPlatformRestore,
  requestPlatformTechnicalLogsRestore as requestGeneratedPlatformTechnicalLogsRestore,
  schedulePlatformMaintenanceWindow as scheduleGeneratedPlatformMaintenanceWindow,
  setPlatformSetting as setGeneratedPlatformSetting,
  setPlatformSettingsCalendarException,
  setPlatformSettingsCalendarWeekday,
  transitionPlatformSettingsVersion,
  updatePlatformAlertPolicy as updateGeneratedPlatformAlertPolicy,
  type BusinessCalendarCreate,
  type BusinessCalendarException,
  type BusinessCalendarWeekday,
  type CollectionResponse,
  type EntityResponse,
  type ListPlatformSettingsCalendarsParams,
  type ListPlatformTechnicalLogsParams,
  type SettingValue,
} from './generated/cluster'
import { requestInit, unwrap } from './http'

export type PlatformSettingsEntity = EntityResponse
export type PlatformSettingValue = SettingValue
export type PlatformSettingsCollection = CollectionResponse

export async function listPlatformSettingsVersions(
  token: string,
): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await listGeneratedPlatformSettingsVersions(
      { limit: 50 },
      requestInit(token),
    ),
  )
}

export async function getCurrentPlatformSettings(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedCurrentPlatformSettings(requestInit(token)),
  )
}

export async function createPlatformSettingsDraft(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await createGeneratedPlatformSettingsDraft(
      { name: 'Platform settings draft' },
      requestInit(token, {
        command: true,
        idempotency: 'platform-settings-draft',
      }),
    ),
  )
}

export async function setPlatformSetting(
  token: string,
  versionId: string,
  settingKey: string,
  value: PlatformSettingValue,
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await setGeneratedPlatformSetting(
      versionId,
      settingKey,
      value,
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

export async function validatePlatformSettingsVersion(
  token: string,
  versionId: string,
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await transitionPlatformSettingsVersion(
      versionId,
      'validate',
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

export async function publishPlatformSettingsVersion(
  token: string,
  versionId: string,
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await transitionPlatformSettingsVersion(
      versionId,
      'publish',
      requestInit(token, {
        command: true,
        idempotency: 'platform-settings-publish',
        lockVersion,
      }),
    ),
  )
}

export async function getPlatformOperationsOverview(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedPlatformOperationsOverview(requestInit(token)),
  )
}

export async function getPlatformHealth(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedPlatformHealth(requestInit(token)),
  )
}

export async function getPlatformBackups(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedPlatformBackups(requestInit(token)),
  )
}

export type BusinessCalendar = EntityResponse
export type BusinessCalendarList = CollectionResponse
export type BusinessCalendarCreateInput = BusinessCalendarCreate

export async function listBusinessCalendars(
  token: string,
  params: ListPlatformSettingsCalendarsParams = {},
): Promise<BusinessCalendarList> {
  return unwrap<BusinessCalendarList>(
    await listPlatformSettingsCalendars(params, requestInit(token)),
  )
}

export async function createBusinessCalendar(
  token: string,
  body: BusinessCalendarCreate,
): Promise<BusinessCalendar> {
  return unwrap<BusinessCalendar>(
    await createPlatformSettingsCalendar(
      body,
      requestInit(token, {
        command: true,
        idempotency: 'business-calendar-create',
      }),
    ),
  )
}

export async function setBusinessCalendarWeekday(
  token: string,
  calendarId: string,
  weekday: number,
  body: BusinessCalendarWeekday,
  lockVersion: number,
): Promise<BusinessCalendar> {
  return unwrap<BusinessCalendar>(
    await setPlatformSettingsCalendarWeekday(
      calendarId,
      weekday,
      body,
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

export async function setBusinessCalendarException(
  token: string,
  calendarId: string,
  date: string,
  body: BusinessCalendarException,
  lockVersion: number,
): Promise<BusinessCalendar> {
  return unwrap<BusinessCalendar>(
    await setPlatformSettingsCalendarException(
      calendarId,
      date,
      body,
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

export async function publishBusinessCalendar(
  token: string,
  calendarId: string,
  lockVersion: number,
): Promise<BusinessCalendar> {
  return unwrap<BusinessCalendar>(
    await publishPlatformSettingsCalendar(
      calendarId,
      requestInit(token, {
        command: true,
        idempotency: 'business-calendar-publish',
        lockVersion,
      }),
    ),
  )
}

export async function requestPlatformBackup(
  token: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await dispatchPlatformBackup(
      requestInit(token, { command: true, idempotency: 'platform-backup-run' }),
    ),
  )
}

export async function requestPlatformRestore(
  token: string,
  body: { backup_id: string; reason: string },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await requestGeneratedPlatformRestore(
      body,
      requestInit(token, {
        command: true,
        idempotency: 'platform-restore-request',
      }),
    ),
  )
}

export async function confirmPlatformRestore(
  token: string,
  requestId: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await confirmGeneratedPlatformRestore(
      requestId,
      requestInit(token, {
        command: true,
        idempotency: 'platform-restore-confirm',
      }),
    ),
  )
}

export async function listPlatformMaintenanceWindows(
  token: string,
): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await listGeneratedPlatformMaintenanceWindows(
      undefined,
      requestInit(token),
    ),
  )
}

export async function schedulePlatformMaintenanceWindow(
  token: string,
  body: {
    starts_at: string
    ends_at?: string | null
    message_ar: string
    message_en: string
  },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await scheduleGeneratedPlatformMaintenanceWindow(
      body,
      requestInit(token, {
        command: true,
        idempotency: 'platform-maintenance-schedule',
      }),
    ),
  )
}

export async function cancelPlatformMaintenanceWindow(
  token: string,
  windowId: string,
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await cancelGeneratedPlatformMaintenanceWindow(
      windowId,
      requestInit(token, {
        command: true,
        idempotency: 'platform-maintenance-cancel',
        lockVersion,
      }),
    ),
  )
}

export async function listPlatformAlertPolicies(
  token: string,
): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await listGeneratedPlatformAlertPolicies(undefined, requestInit(token)),
  )
}

export async function updatePlatformAlertPolicy(
  token: string,
  policyId: string,
  body: { status?: string; severity?: string; channel?: string },
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await updateGeneratedPlatformAlertPolicy(
      policyId,
      body,
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

export async function listPlatformTechnicalLogs(
  token: string,
  params: ListPlatformTechnicalLogsParams = {},
): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await listGeneratedPlatformTechnicalLogs(params, requestInit(token)),
  )
}

export async function requestPlatformTechnicalLogsRestore(
  token: string,
  body: { manifest_id: string; reason: string },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await requestGeneratedPlatformTechnicalLogsRestore(
      body,
      requestInit(token, {
        command: true,
        idempotency: 'platform-technical-logs-restore',
      }),
    ),
  )
}
