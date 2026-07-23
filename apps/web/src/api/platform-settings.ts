/**
 * Typed Platform Settings boundary for feature screens.
 *
 * Generated endpoint types and URLs remain in `generated/cluster`; this file
 * owns session-bound request options, response unwrapping, and stable names for
 * the feature. Screens must not build HTTP headers themselves.
 */
import * as generated from './generated/cluster'
import { requestInit, unwrap } from './http'

export type PlatformSettingsEntity = generated.EntityResponse
export type PlatformSettingValue = generated.SettingValue

export async function getCurrentPlatformSettings(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await generated.getCurrentPlatformSettings(requestInit(token)),
  )
}

export async function createPlatformSettingsDraft(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await generated.createPlatformSettingsDraft(
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
    await generated.setPlatformSetting(
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
    await generated.transitionPlatformSettingsVersion(
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
    await generated.transitionPlatformSettingsVersion(
      versionId,
      'publish',
      requestInit(token, { command: true, idempotency: 'platform-settings-publish', lockVersion }),
    ),
  )
}

export async function getPlatformOperationsOverview(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(
    await generated.getPlatformOperationsOverview(requestInit(token)),
  )
}

export async function getPlatformHealth(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(await generated.getPlatformHealth(requestInit(token)))
}

export async function getPlatformBackups(token: string): Promise<PlatformSettingsEntity> {
  return unwrap<PlatformSettingsEntity>(await generated.getPlatformBackups(requestInit(token)))
}
