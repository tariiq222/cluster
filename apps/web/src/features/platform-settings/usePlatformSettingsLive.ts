/**
 * Live Platform Settings data layer.
 *
 * Wraps `createLivePlatformSettingsDataSource` with a React hook that exposes
 * loading, success, denied, and error states. The hook terminates in the
 * generated `EntityResponse` / `CollectionResponse` shape so screens keep
 * using their existing contract.
 *
 * Sections without a generated endpoint today (calendars, logs, maintenance)
 * must surface an explicit "unsupported" state rather than a silent mock so
 * live E2E can detect the regression.
 */
import { useCallback, useEffect, useState } from 'react'

import { ApiError } from '../../api'
import {
  type PlatformSettingsMockScreen,
  type PlatformSettingsDataSource,
  type PlatformSettingsDataSourceInput,
  createLivePlatformSettingsDataSource,
} from './PlatformSettingsMockData'
import type { PlatformScreenState } from './screen-support'

export type PlatformSettingsLiveState =
  | { kind: 'idle' }
  | { kind: 'loading' }
  | { kind: 'success'; screen: PlatformSettingsMockScreen }
  | { kind: 'denied'; screen: PlatformSettingsMockScreen }
  | { kind: 'error'; message: string; status?: number }
  | { kind: 'unsupported'; screen: PlatformSettingsMockScreen }

const supportedSections: Partial<Record<PlatformSettingsDataSourceInput['section'], true>> = {
  overview: true,
  security: true,
  calendars: true,
  backups: true,
  health: true,
  logs: true,
  maintenance: true,
}

const unsupportedScreen = (section: PlatformSettingsDataSourceInput['section']): PlatformSettingsMockScreen => ({
  state: 'empty',
  resource: { id: section, items: [], next_cursor: null } as never,
  allowedActions: [],
})

export function usePlatformSettingsLive(
  source: PlatformSettingsDataSource,
  input: PlatformSettingsDataSourceInput | null,
): {
  state: PlatformSettingsLiveState
  reload: () => Promise<void>
} {
  const [state, setState] = useState<PlatformSettingsLiveState>({ kind: 'idle' })
  const section = input?.section ?? null
  const capabilities = input?.capabilities ?? null
  const cursor = input?.cursor


  const load = useCallback(async () => {
    if (section === null) {
      setState({ kind: 'idle' })
      return
    }
    if (supportedSections[section] !== true) {
      setState({ kind: 'unsupported', screen: unsupportedScreen(section) })
      return
    }
    setState({ kind: 'loading' })
    try {
      const screen = await source.load(cursor === undefined ? { section, capabilities } : { section, capabilities, cursor })
      if (screen.state === 'denied') {
        setState({ kind: 'denied', screen })
      } else {
        setState({ kind: 'success', screen })
      }
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.status === 401 || error.status === 403) {
          setState({ kind: 'denied', screen: unsupportedScreen(section) })
          return
        }
        setState({ kind: 'error', message: error.problem?.title ?? error.message, status: error.status })
        return
      }
      const message = error instanceof Error ? error.message : 'Unknown error'
      setState({ kind: 'error', message })
    }
  }, [source, section, capabilities, cursor])

  useEffect(() => {
    void load()
  }, [load])

  return { state, reload: load }
}

export function screenStateFromLive(state: PlatformSettingsLiveState): PlatformScreenState {
  switch (state.kind) {
    case 'loading':
    case 'idle':
      return 'loading'
    case 'error':
      return 'error'
    case 'denied':
      return 'denied'
    case 'success':
      return state.screen.state === 'empty' ? 'empty' : state.screen.state
    case 'unsupported':
      return 'empty'
  }
}

export function buildLiveSource(token: string): PlatformSettingsDataSource {
  return createLivePlatformSettingsDataSource(token)
}
