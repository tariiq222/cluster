/**
 * Typed Platform Settings boundary for feature screens.
 *
 * Generated endpoint types and URLs remain in `generated/cluster`; this file
 * owns session-bound request options, response unwrapping, and stable names for
 * the feature. Screens must not build HTTP headers themselves.
 */
import {
  createPlatformSettingsCalendar,
  createPlatformSettingsDraft as createGeneratedPlatformSettingsDraft,
  getCurrentPlatformSettings as getGeneratedCurrentPlatformSettings,
  getPlatformBackups as getGeneratedPlatformBackups,
  getPlatformHealth as getGeneratedPlatformHealth,
  getPlatformOperationsOverview as getGeneratedPlatformOperationsOverview,
  listPlatformSettingsCalendars,
  listPlatformSettingsVersions as listGeneratedPlatformSettingsVersions,
  publishPlatformSettingsCalendar,
  setPlatformSetting as setGeneratedPlatformSetting,
  setPlatformSettingsCalendarException,
  setPlatformSettingsCalendarWeekday,
  transitionPlatformSettingsVersion,
  type BusinessCalendarCreate,
  type BusinessCalendarException,
  type BusinessCalendarWeekday,
  type CollectionResponse,
  type EntityResponse,
  type ListPlatformSettingsCalendarsParams,
  type SettingValue,
} from './generated/cluster'
import { requestInit, unwrap } from './http'

export type PlatformSettingsEntity = EntityResponse
export type PlatformSettingValue = SettingValue
export type PlatformSettingsCollection = CollectionResponse

export async function listPlatformSettingsVersions(token: string): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await listGeneratedPlatformSettingsVersions({ limit: 50 }, requestInit(token)),
  )
}

export async function getCurrentPlatformSettings(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedCurrentPlatformSettings(requestInit(token)),
  )
}

export async function createPlatformSettingsDraft(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await createGeneratedPlatformSettingsDraft(
      { name: 'Platform settings draft' },
      requestInit(token, { command: true, idempotency: 'platform-settings-draft' }),
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
      requestInit(token, { command: true, idempotency: 'platform-settings-publish', lockVersion }),
    ),
  )
}

export async function getPlatformOperationsOverview(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await getGeneratedPlatformOperationsOverview(requestInit(token)),
  )
}

export async function getPlatformHealth(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(await getGeneratedPlatformHealth(requestInit(token)))
}

export async function getPlatformBackups(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(await getGeneratedPlatformBackups(requestInit(token)))
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
      requestInit(token, { command: true, idempotency: 'business-calendar-create' }),
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
      requestInit(token, { command: true, idempotency: 'business-calendar-publish', lockVersion }),
    ),
  )
}

export async function requestPlatformBackup(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: '/api/v1/platform-operations/backups',
      command: true,
      idempotency: 'platform-backup-run',
    }),
  )
}

export async function requestPlatformRestore(
  token: string,
  body: { backup_id: string; reason: string },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: '/api/v1/platform-operations/restore-requests',
      command: true,
      idempotency: 'platform-restore-request',
      body,
    }),
  )
}

export async function confirmPlatformRestore(
  token: string,
  requestId: string,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: `/api/v1/platform-operations/restore-requests/${encodeURIComponent(requestId)}/confirm`,
      command: true,
      idempotency: 'platform-restore-confirm',
    }),
  )
}

export async function listPlatformMaintenanceWindows(token: string): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await rawFetchCollection(token, '/api/v1/platform-operations/maintenance-windows'),
  )
}

export async function schedulePlatformMaintenanceWindow(
  token: string,
  body: { starts_at: string; ends_at?: string | null; message_ar: string; message_en: string },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: '/api/v1/platform-operations/maintenance-windows',
      command: true,
      idempotency: 'platform-maintenance-schedule',
      body,
    }),
  )
}

export async function cancelPlatformMaintenanceWindow(
  token: string,
  windowId: string,
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: `/api/v1/platform-operations/maintenance-windows/${encodeURIComponent(windowId)}/cancel`,
      command: true,
      idempotency: 'platform-maintenance-cancel',
      lockVersion,
    }),
  )
}

export async function listPlatformAlertPolicies(token: string): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await rawFetchCollection(token, '/api/v1/platform-operations/alert-policies'),
  )
}

export async function updatePlatformAlertPolicy(
  token: string,
  policyId: string,
  body: { status?: string; severity?: string; channel?: string },
  lockVersion: number,
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'PATCH',
      path: `/api/v1/platform-operations/alert-policies/${encodeURIComponent(policyId)}`,
      mutation: true,
      lockVersion,
      body,
    }),
  )
}

export async function listPlatformTechnicalLogs(
  token: string,
  params: { category?: string; source?: string; correlation_id?: string; cursor?: string; per_page?: number } = {},
): Promise<PlatformSettingsCollection> {
  return unwrap<PlatformSettingsCollection>(
    await rawFetchCollection(token, '/api/v1/platform-operations/technical-logs', params),
  )
}

export async function requestPlatformTechnicalLogsRestore(
  token: string,
  body: { manifest_id: string; reason: string },
): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await rawFetch(token, {
      method: 'POST',
      path: '/api/v1/platform-operations/technical-logs/restore',
      command: true,
      idempotency: 'platform-technical-logs-restore',
      body,
    }),
  )
}

async function rawFetch(
  token: string,
  options: {
    method: 'POST' | 'PATCH' | 'PUT' | 'DELETE'
    path: string
    command?: boolean
    mutation?: boolean
    idempotency?: string
    lockVersion?: number
    body?: unknown
  },
): Promise<{ status: number; data: unknown; headers: Headers }> {
  const init = requestInit(token, {
    command: options.command,
    mutation: options.mutation,
    idempotency: options.idempotency,
    lockVersion: options.lockVersion,
  })
  return generatedRequest(options.path, options.method, init, options.body)
}

async function rawFetchCollection(
  token: string,
  path: string,
  params: Record<string, string | number | undefined> = {},
): Promise<{ status: number; data: unknown; headers: Headers }> {
  const init = requestInit(token)
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value))
  }
  const suffix = query.toString()
  return generatedRequest(suffix ? `${path}?${suffix}` : path, 'GET', init)
}

async function generatedRequest(
  path: string,
  method: string,
  init: RequestInit,
  body?: unknown,
): Promise<{ status: number; data: unknown; headers: Headers }> {
  const headers = new Headers(init.headers ?? {})
  if (body !== undefined) headers.set('Content-Type', 'application/json')
  const response = await fetch(path, {
    ...init,
    method,
    headers,
    body: body === undefined ? null : JSON.stringify(body),
  })
  const text = await response.text()
  let data: unknown = null
  if (text.length > 0) {
    try {
      data = JSON.parse(text)
    } catch {
      data = text
    }
  }
  return { status: response.status, data, headers: response.headers }
}
