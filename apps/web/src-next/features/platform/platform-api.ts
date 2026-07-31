/**
 * Typed PlatformSettings boundary for the src-next rebuild.
 *
 * Generated endpoint functions and URLs remain in `src/api/generated/cluster`;
 * this file owns session-bound request options, response unwrapping, and
 * stable names for the feature. Screens must not build HTTP headers
 * themselves.
 */
import * as generated from '../../../src/api/generated/cluster'
import { requestInit, unwrap } from '../../api/http'
import type {
  BusinessCalendarEntity,
  BusinessCalendarList,
  PlatformAlertPolicy,
  PlatformAlertPolicyList,
  PlatformBackupReport,
  PlatformHealth,
  PlatformMaintenanceWindow,
  PlatformMaintenanceWindowList,
  PlatformOperationResult,
  PlatformOperationsOverview,
  PlatformSettingsVersion,
  PlatformSettingsVersionsList,
  PlatformTechnicalLogList,
} from './platform-types'

export async function listPlatformSettingsVersions(csrfToken: string | null): Promise<PlatformSettingsVersionsList> {
  return unwrap<PlatformSettingsVersionsList>(
    await generated.listPlatformSettingsVersions(
      { limit: 50 },
      requestInit(csrfToken),
    ),
  )
}

export async function createPlatformSettingsDraft(csrfToken: string | null): Promise<PlatformSettingsVersion> {
  return unwrap<PlatformSettingsVersion>(
    await generated.createPlatformSettingsDraft(
      { name: 'Platform settings draft' },
      requestInit(csrfToken, {
        command: true,
        idempotency: 'platform-settings-draft',
      }),
    ),
  )
}

export async function getCurrentPlatformSettings(csrfToken: string | null): Promise<PlatformSettingsVersion> {
  return unwrap<PlatformSettingsVersion>(
    await generated.getCurrentPlatformSettings(requestInit(csrfToken)),
  )
}

export async function setPlatformSetting(
  csrfToken: string | null,
  versionId: string,
  settingKey: string,
  value: generated.SettingValue,
  lockVersion: number,
): Promise<PlatformSettingsVersion> {
  return unwrap<PlatformSettingsVersion>(
    await generated.setPlatformSetting(
      versionId,
      settingKey,
      value,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function validatePlatformSettingsVersion(
  csrfToken: string | null,
  versionId: string,
  lockVersion: number,
): Promise<PlatformSettingsVersion> {
  return unwrap<PlatformSettingsVersion>(
    await generated.validatePlatformSettingsVersion(
      versionId,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function publishPlatformSettingsVersion(
  csrfToken: string | null,
  versionId: string,
  lockVersion: number,
): Promise<PlatformSettingsVersion> {
  return unwrap<PlatformSettingsVersion>(
    await generated.publishPlatformSettingsVersion(
      versionId,
      requestInit(csrfToken, {
        command: true,
        idempotency: 'platform-settings-publish',
        lockVersion,
      }),
    ),
  )
}

export async function getPlatformOperationsOverview(csrfToken: string | null): Promise<PlatformOperationsOverview> {
  return unwrap<PlatformOperationsOverview>(
    await generated.getPlatformOperationsOverview(requestInit(csrfToken)),
  )
}

export async function getPlatformHealth(csrfToken: string | null): Promise<PlatformHealth> {
  return unwrap<PlatformHealth>(
    await generated.getPlatformHealth(requestInit(csrfToken)),
  )
}

export async function getPlatformBackups(csrfToken: string | null): Promise<PlatformBackupReport> {
  return unwrap<PlatformBackupReport>(
    await generated.getPlatformBackups(requestInit(csrfToken)),
  )
}

export async function dispatchPlatformBackup(csrfToken: string | null): Promise<PlatformOperationResult> {
  return unwrap<PlatformOperationResult>(
    await generated.dispatchPlatformBackup(
      requestInit(csrfToken, { command: true, idempotency: 'platform-backup' }),
    ),
  )
}

export async function listBusinessCalendars(csrfToken: string | null): Promise<BusinessCalendarList> {
  return unwrap<BusinessCalendarList>(
    await generated.listPlatformSettingsCalendars(undefined, requestInit(csrfToken)),
  )
}

export async function createBusinessCalendar(
  csrfToken: string | null,
  body: generated.BusinessCalendarCreate,
): Promise<BusinessCalendarEntity> {
  return unwrap<BusinessCalendarEntity>(
    await generated.createPlatformSettingsCalendar(
      body,
      requestInit(csrfToken, { command: true, idempotency: 'business-calendar-create' }),
    ),
  )
}

export async function setBusinessCalendarWeekday(
  csrfToken: string | null,
  calendarId: string,
  weekday: number,
  body: generated.BusinessCalendarWeekday,
  lockVersion: number,
): Promise<BusinessCalendarEntity> {
  return unwrap<BusinessCalendarEntity>(
    await generated.setPlatformSettingsCalendarWeekday(
      calendarId,
      weekday,
      body,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function setBusinessCalendarException(
  csrfToken: string | null,
  calendarId: string,
  date: string,
  body: generated.BusinessCalendarException,
  lockVersion: number,
): Promise<BusinessCalendarEntity> {
  return unwrap<BusinessCalendarEntity>(
    await generated.setPlatformSettingsCalendarException(
      calendarId,
      date,
      body,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function publishBusinessCalendar(
  csrfToken: string | null,
  calendarId: string,
  lockVersion: number,
): Promise<BusinessCalendarEntity> {
  return unwrap<BusinessCalendarEntity>(
    await generated.publishPlatformSettingsCalendar(
      calendarId,
      requestInit(csrfToken, {
        command: true,
        idempotency: 'business-calendar-publish',
        lockVersion,
      }),
    ),
  )
}

export async function requestPlatformRestore(
  csrfToken: string | null,
  body: generated.RequestPlatformRestoreBody,
): Promise<PlatformOperationResult> {
  return unwrap<PlatformOperationResult>(
    await generated.requestPlatformRestore(
      body,
      requestInit(csrfToken, { command: true, idempotency: 'platform-restore-request' }),
    ),
  )
}

export async function confirmPlatformRestore(
  csrfToken: string | null,
  requestId: string,
): Promise<PlatformOperationResult> {
  return unwrap<PlatformOperationResult>(
    await generated.confirmPlatformRestore(
      requestId,
      requestInit(csrfToken, { command: true, idempotency: 'platform-restore-confirm' }),
    ),
  )
}

export async function listPlatformTechnicalLogs(
  csrfToken: string | null,
  params: generated.ListPlatformTechnicalLogsParams = {},
): Promise<PlatformTechnicalLogList> {
  return unwrap<PlatformTechnicalLogList>(
    await generated.listPlatformTechnicalLogs(params, requestInit(csrfToken)),
  )
}

export async function requestPlatformTechnicalLogsRestore(
  csrfToken: string | null,
  body: generated.RequestPlatformTechnicalLogsRestoreBody,
): Promise<PlatformOperationResult> {
  return unwrap<PlatformOperationResult>(
    await generated.requestPlatformTechnicalLogsRestore(
      body,
      requestInit(csrfToken, { command: true, idempotency: 'platform-technical-logs-restore' }),
    ),
  )
}

export async function listPlatformAlertPolicies(csrfToken: string | null): Promise<PlatformAlertPolicyList> {
  return unwrap<PlatformAlertPolicyList>(
    await generated.listPlatformAlertPolicies(undefined, requestInit(csrfToken)),
  )
}

export async function updatePlatformAlertPolicy(
  csrfToken: string | null,
  policyId: string,
  body: generated.UpdatePlatformAlertPolicyBody,
  lockVersion: number,
): Promise<PlatformAlertPolicy> {
  return unwrap<PlatformAlertPolicy>(
    await generated.updatePlatformAlertPolicy(
      policyId,
      body,
      requestInit(csrfToken, { mutation: true, lockVersion }),
    ),
  )
}

export async function listPlatformMaintenanceWindows(csrfToken: string | null): Promise<PlatformMaintenanceWindowList> {
  return unwrap<PlatformMaintenanceWindowList>(
    await generated.listPlatformMaintenanceWindows(undefined, requestInit(csrfToken)),
  )
}

export async function schedulePlatformMaintenanceWindow(
  csrfToken: string | null,
  body: generated.SchedulePlatformMaintenanceWindowBody,
): Promise<PlatformMaintenanceWindow> {
  return unwrap<PlatformMaintenanceWindow>(
    await generated.schedulePlatformMaintenanceWindow(
      body,
      requestInit(csrfToken, { command: true, idempotency: 'platform-maintenance-schedule' }),
    ),
  )
}

export async function cancelPlatformMaintenanceWindow(
  csrfToken: string | null,
  windowId: string,
  lockVersion: number,
): Promise<PlatformMaintenanceWindow> {
  return unwrap<PlatformMaintenanceWindow>(
    await generated.cancelPlatformMaintenanceWindow(
      windowId,
      requestInit(csrfToken, {
        command: true,
        idempotency: 'platform-maintenance-cancel',
        lockVersion,
      }),
    ),
  )
}
