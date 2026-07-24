// @vitest-environment jsdom
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as api from '../../api/platform-settings'
import { ApiError } from '../../api'
import type { PlatformSettingsEntity, PlatformSettingsCollection } from '../../api/platform-settings'
import { useSecuritySettingsLive } from './useSecuritySettingsLive'

const token = 'csrf-token'

function buildEntity(overrides: Partial<PlatformSettingsEntity> = {}): PlatformSettingsEntity {
  return {
    id: '01980f50-5f0d-7000-8000-000000000901',
    resource_type: 'platform_settings_version',
    status: 'published',
    classification: 'internal',
    lock_version: 1,
    created_at: '2026-07-23T09:30:00+03:00',
    updated_at: '2026-07-23T09:30:00+03:00',
    allowed_actions: [],
    values: { 'security.minimum_password_length': 12 },
    ...overrides,
  }
}

afterEach(() => vi.restoreAllMocks())

beforeEach(() => {
  // The hook now reads `listPlatformSettingsVersions` first so it can
  // target the latest editable version. The unit tests mock only
  // `getCurrentPlatformSettings`; provide an empty list so the hook
  // falls back to the published version.
  vi.spyOn(api, 'listPlatformSettingsVersions').mockResolvedValue({
    id: 'list',
    resource_type: 'collection',
    classification: 'internal',
    items: [],
    allowed_actions: [],
    next_cursor: null,
  } as unknown as PlatformSettingsCollection)
})

describe('useSecuritySettingsLive', () => {
  it('loads the current published version on initial read', async () => {
    const getCurrent = vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity())
    const { result } = renderHook(() => useSecuritySettingsLive(token))

    await waitFor(() => expect(result.current.state.kind).toBe('ready'))
    expect(getCurrent).toHaveBeenCalledWith(token)
  })

  it('creates a draft and reloads', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity())
    const createDraft = vi.spyOn(api, 'createPlatformSettingsDraft').mockResolvedValue(buildEntity({ status: 'draft', lock_version: 2 }))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.createDraft()
    })

    expect(createDraft).toHaveBeenCalledWith(token)
    expect(result.current.state).toMatchObject({ kind: 'ready', notice: 'draft' })
  })

  it('sends If-Match on set value and reloads with the new lock version', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity({ lock_version: 5 }))
    const setPlatformSetting = vi.spyOn(api, 'setPlatformSetting').mockResolvedValue(buildEntity({ lock_version: 6 }))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.setValue('security.minimum_password_length', { value_type: 'integer', value: 14 })
    })

    expect(setPlatformSetting).toHaveBeenCalledWith(token, expect.any(String), 'security.minimum_password_length', { value_type: 'integer', value: 14 }, 5)
    expect(result.current.state).toMatchObject({ kind: 'ready' })
    if (result.current.state.kind === 'ready') {
      expect(result.current.state.entity.lock_version).toBe(6)
    }
  })

  it('marks stale on 412 and reloads from the server', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings')
      .mockResolvedValueOnce(buildEntity({ lock_version: 5 }))
      .mockResolvedValueOnce(buildEntity({ lock_version: 9 }))
    const setPlatformSetting = vi.spyOn(api, 'setPlatformSetting').mockRejectedValue(new ApiError(412, { type: 'stale', title: 'Stale version', status: 412 } as never))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.setValue('security.idle_timeout_minutes', { value_type: 'integer', value: 30 })
    })

    expect(setPlatformSetting).toHaveBeenCalled()
    expect(result.current.state).toMatchObject({ kind: 'ready', notice: 'stale' })
    if (result.current.state.kind === 'ready') {
      expect(result.current.state.entity.lock_version).toBe(9)
    }
  })

  it('surfaces 500 errors without leaving the ready state', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity())
    vi.spyOn(api, 'validatePlatformSettingsVersion').mockRejectedValue(new ApiError(500, { type: 'server', title: 'Server error', status: 500 } as never))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.validate()
    })

    expect(result.current.state).toMatchObject({ kind: 'error', status: 500 })
  })

  it('publishes with idempotency and confirms the published notice', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity({ status: 'validated', lock_version: 4 }))
    const publish = vi.spyOn(api, 'publishPlatformSettingsVersion').mockResolvedValue(buildEntity({ status: 'published', lock_version: 5 }))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.publish()
    })

    expect(publish).toHaveBeenCalledWith(token, expect.any(String), 4)
    expect(result.current.state).toMatchObject({ kind: 'ready', notice: 'published' })
  })

  it('runs the full draft → set → validate → publish cycle', async () => {
    vi.spyOn(api, 'getCurrentPlatformSettings').mockResolvedValue(buildEntity({ lock_version: 1 }))
    const createDraft = vi.spyOn(api, 'createPlatformSettingsDraft').mockResolvedValue(buildEntity({ status: 'draft', lock_version: 2 }))
    const setPlatformSetting = vi.spyOn(api, 'setPlatformSetting').mockResolvedValue(buildEntity({ status: 'draft', lock_version: 3 }))
    const validate = vi.spyOn(api, 'validatePlatformSettingsVersion').mockResolvedValue(buildEntity({ status: 'validated', lock_version: 4 }))
    const publish = vi.spyOn(api, 'publishPlatformSettingsVersion').mockResolvedValue(buildEntity({ status: 'published', lock_version: 5 }))

    const { result } = renderHook(() => useSecuritySettingsLive(token))
    await waitFor(() => expect(result.current.state.kind).toBe('ready'))

    await act(async () => {
      await result.current.createDraft()
    })
    await act(async () => {
      await result.current.setValue('security.minimum_password_length', { value_type: 'integer', value: 14 })
    })
    await act(async () => {
      await result.current.validate()
    })
    await act(async () => {
      await result.current.publish()
    })

    expect(createDraft).toHaveBeenCalledOnce()
    expect(setPlatformSetting).toHaveBeenCalledOnce()
    expect(validate).toHaveBeenCalledOnce()
    expect(publish).toHaveBeenCalledOnce()
    expect(result.current.state).toMatchObject({ kind: 'ready', notice: 'published' })
  })
})
