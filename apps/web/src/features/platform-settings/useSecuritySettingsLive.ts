/**
 * Live Security Settings lifecycle.
 *
 * Drives the create draft → set → validate → publish cycle against the
 * generated `platform-settings` endpoint family. The hook terminates in the
 * platform `State` enum so the existing screen can render the same notices
 * (`draft`, `validated`, `published`, `error`) without growing a new transport
 * type.
 *
 * The hook targets the latest editable version (status `draft` or
 * `validated`) for mutations. When no editable version exists, it falls
 * back to the published version for read-only display, and `createDraft`
 * opens a new draft for the next mutation.
 */

import { useCallback, useEffect, useState } from 'react'

import { ApiError } from '../../api'
import {
  createPlatformSettingsDraft,
  getCurrentPlatformSettings,
  listPlatformSettingsVersions,
  publishPlatformSettingsVersion,
  setPlatformSetting,
  validatePlatformSettingsVersion,
  type PlatformSettingsEntity,
  type PlatformSettingValue,
} from '../../api/platform-settings'

export type SecuritySettingsLiveNotice = 'draft' | 'validated' | 'published' | 'error' | 'stale'

export type SecuritySettingsLiveState =
  | { kind: 'idle' }
  | { kind: 'loading' }
  | { kind: 'ready'; entity: PlatformSettingsEntity; notice: SecuritySettingsLiveNotice | null }
  | { kind: 'error'; message: string; status?: number }

const noticeFromError = (status: number | undefined): SecuritySettingsLiveNotice => {
  if (status === 412 || status === 409) return 'stale'
  return 'error'
}

export type SecuritySettingsLiveResult = {
  state: SecuritySettingsLiveState
  reload: () => Promise<void>
  createDraft: () => Promise<void>
  setValue: (key: string, value: PlatformSettingValue) => Promise<void>
  validate: () => Promise<void>
  publish: () => Promise<void>
}

function toErrorState(error: unknown): { message: string; status?: number } {
  if (error instanceof ApiError) {
    return { message: error.problem?.title ?? error.message, status: error.status }
  }
  const message = error instanceof Error ? error.message : 'Unknown error'
  return { message }
}

async function refreshAfterConflict(
  token: string,
  fallback: PlatformSettingsEntity,
  onConflict: (() => void) | undefined,
): Promise<PlatformSettingsEntity> {
  onConflict?.()
  try {
    return await getCurrentPlatformSettings(token)
  } catch {
    return fallback
  }
}

/**
 * Loads the latest editable version (status `draft` or `validated`).
 * Falls back to the published version when no editable version exists
 * or the list endpoint is unavailable.
 */
async function loadEditableVersion(token: string): Promise<PlatformSettingsEntity> {
  try {
    const collection = await listPlatformSettingsVersions(token)
    const editable = collection.items.find((item) => item.status === 'draft' || item.status === 'validated')
    if (editable !== undefined) {
      return editable
    }
  } catch {
    // Fall through to the published version.
  }
  return await getCurrentPlatformSettings(token)
}

export function useSecuritySettingsLive(
  token: string,
  options: { onConflict?: () => void } = {},
): SecuritySettingsLiveResult {
  const [state, setState] = useState<SecuritySettingsLiveState>({ kind: 'idle' })

  const reload = useCallback(async () => {
    setState({ kind: 'loading' })
    try {
      const entity = await loadEditableVersion(token)
      setState({ kind: 'ready', entity, notice: null })
    } catch (error) {
      setState({ kind: 'error', ...toErrorState(error) })
    }

  }, [token])

  useEffect(() => {
    void reload()
  }, [reload])

  const createDraft = useCallback(async () => {
    setState({ kind: 'loading' })
    try {
      const entity = await createPlatformSettingsDraft(token)
      setState({ kind: 'ready', entity, notice: 'draft' })
    } catch (error) {
      const next = toErrorState(error)
      if (next.status === 409 || next.status === 422) {
        // The handler refuses to create a second draft while one is
        // open. Reload the existing draft instead.
        try {
          const existing = await loadEditableVersion(token)
          setState({ kind: 'ready', entity: existing, notice: 'draft' })
          return
        } catch {
          // Fall through to the error state.
        }
      }
      setState({ kind: 'error', ...next })
    }
  }, [token])

  const setValue = useCallback(
    async (key: string, value: PlatformSettingValue) => {
      const current = state
      if (current.kind !== 'ready') {
        setState({ kind: 'error', message: 'No active version to update.' })
        return
      }
      try {
        const updated = await setPlatformSetting(token, current.entity.id, key, value, current.entity.lock_version)
        setState({ kind: 'ready', entity: updated, notice: null })
      } catch (error) {
        if (error instanceof ApiError) {
          const notice = noticeFromError(error.status)
          if (notice === 'stale') {
            const refreshed = await refreshAfterConflict(token, current.entity, options.onConflict)
            setState({ kind: 'ready', entity: refreshed, notice })
            return
          }
          setState({ kind: 'error', ...toErrorState(error) })
          return
        }
        setState({ kind: 'error', ...toErrorState(error) })
      }
    },
    [state, token, options],
  )

  const validate = useCallback(async () => {
    const current = state
    if (current.kind !== 'ready') {
      setState({ kind: 'error', message: 'No draft to validate.' })
      return
    }
    try {
      const updated = await validatePlatformSettingsVersion(token, current.entity.id, current.entity.lock_version)
      setState({ kind: 'ready', entity: updated, notice: 'validated' })
    } catch (error) {
      if (error instanceof ApiError) {
        const notice = noticeFromError(error.status)
        if (notice === 'stale') {
          const refreshed = await refreshAfterConflict(token, current.entity, options.onConflict)
          setState({ kind: 'ready', entity: refreshed, notice })
          return
        }
        setState({ kind: 'error', ...toErrorState(error) })
        return
      }
      setState({ kind: 'error', ...toErrorState(error) })
    }
  }, [state, token, options])

  const publish = useCallback(async () => {
    const current = state
    if (current.kind !== 'ready') {
      setState({ kind: 'error', message: 'No draft to publish.' })
      return
    }
    try {
      const updated = await publishPlatformSettingsVersion(token, current.entity.id, current.entity.lock_version)
      setState({ kind: 'ready', entity: updated, notice: 'published' })
    } catch (error) {
      if (error instanceof ApiError) {
        const notice = noticeFromError(error.status)
        if (notice === 'stale') {
          const refreshed = await refreshAfterConflict(token, current.entity, options.onConflict)
          setState({ kind: 'ready', entity: refreshed, notice })
          return
        }
        setState({ kind: 'error', ...toErrorState(error) })
        return
      }
      setState({ kind: 'error', ...toErrorState(error) })
    }
  }, [state, token, options])

  return { state, reload, createDraft, setValue, validate, publish }
}

export function latestDraftVersion<T extends { status: string; id: string; lock_version: number }>(
  versions: readonly T[],
): T | null {
  if (versions.length === 0) return null
  const draft = versions.find((version) => version.status === 'draft')
  return draft ?? versions[0] ?? null
}
