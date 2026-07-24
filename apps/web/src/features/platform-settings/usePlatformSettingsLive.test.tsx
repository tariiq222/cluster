// @vitest-environment jsdom
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../api'
import {
  type PlatformSettingsDataSource,
  type PlatformSettingsDataSourceInput,
  type PlatformSettingsMockScreen,
} from './PlatformSettingsMockData'
import { usePlatformSettingsLive } from './usePlatformSettingsLive'

function buildScreen(state: PlatformSettingsMockScreen['state']): PlatformSettingsMockScreen {
  return {
    state,
    resource: { id: '019f8e3b-3368-7192-85a6-3da3949fd701', items: [], next_cursor: null } as never,
    allowedActions: [],
  }
}

const successScreen = buildScreen('success')
const deniedScreen = buildScreen('denied')

describe('usePlatformSettingsLive', () => {
  beforeEach(() => {
    vi.useRealTimers()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('loads via the data source for supported sections', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockResolvedValue(successScreen)
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('success'))
    expect(result.current.state.kind === 'success' && result.current.state.screen).toEqual(successScreen)
    expect(load).toHaveBeenCalledWith(input)
  })

  it('does not reload when a parent recreates equivalent input', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockResolvedValue(successScreen)
    const source: PlatformSettingsDataSource = { load }
    const initialInput: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }
    const { rerender } = renderHook(
      ({ input }) => usePlatformSettingsLive(source, input),
      { initialProps: { input: initialInput } },
    )

    await waitFor(() => expect(load).toHaveBeenCalledTimes(1))
    await act(async () => {
      rerender({ input: { section: 'overview', capabilities: initialInput.capabilities } })
    })

    expect(load).toHaveBeenCalledTimes(1)
  })

  it('reports denied when the data source returns a denied screen', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockResolvedValue(deniedScreen)
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'security', capabilities: [] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('denied'))
  })

  it('keeps a supported section in success when the data source returns a success screen', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockResolvedValue(successScreen)
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'maintenance', capabilities: ['platform_operations.maintenance.manage'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('success'))
    expect(load).toHaveBeenCalledWith(input)
  })
  it('maps 401/403 ApiError to denied', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(403, { type: 'forbidden', title: 'Forbidden', status: 403 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'security', capabilities: ['platform_settings.manage'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('denied'))
  })
  it('maps 500 ApiError to error with status', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(500, { type: 'server', title: 'boom', status: 500 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('error'))
    expect(result.current.state.kind === 'error' && result.current.state.status).toBe(500)
  })
  it('maps 404 ApiError to error with status', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(404, { type: 'not_found', title: 'Not Found', status: 404 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'health', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('error'))
    expect(result.current.state.kind === 'error' && result.current.state.status).toBe(404)
  })
  it('maps 412 ApiError to error with status', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(412, { type: 'precondition', title: 'Precondition Failed', status: 412 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'maintenance', capabilities: ['platform_operations.maintenance.manage'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('error'))
    expect(result.current.state.kind === 'error' && result.current.state.status).toBe(412)
  })
  it('maps 422 ApiError to error with status', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(422, { type: 'validation', title: 'Validation Failed', status: 422 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'calendars', capabilities: ['platform_settings.calendar.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('error'))
    expect(result.current.state.kind === 'error' && result.current.state.status).toBe(422)
  })
  it('maps 503 ApiError to error with status', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockRejectedValue(new ApiError(503, { type: 'unavailable', title: 'Service Unavailable', status: 503 } as never))
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('error'))
    expect(result.current.state.kind === 'error' && result.current.state.status).toBe(503)
  })
  it('reloads on demand', async () => {
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockResolvedValue(successScreen)
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('success'))
    await act(async () => {
      await result.current.reload()
    })
    expect(load).toHaveBeenCalledTimes(2)
  })
  it('keeps loading state until the source resolves', async () => {
    const deferred = createDeferred<PlatformSettingsMockScreen>()
    const load = vi.fn<PlatformSettingsDataSource['load']>().mockReturnValue(deferred.promise)
    const source: PlatformSettingsDataSource = { load }
    const input: PlatformSettingsDataSourceInput = { section: 'overview', capabilities: ['platform_operations.health.read'] }

    const { result } = renderHook(() => usePlatformSettingsLive(source, input))

    await waitFor(() => expect(result.current.state.kind).toBe('loading'))
    deferred.resolve(successScreen)
    await waitFor(() => expect(result.current.state.kind).toBe('success'))
  })
})

function createDeferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}
