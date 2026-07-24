import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createPlatformSettingsDraft,
  getCurrentPlatformSettings,
  listPlatformSettingsVersions,
  setPlatformSetting,
  validatePlatformSettingsVersion,
} from './platform-settings'

const token = 'csrf-token'
const versionId = '01980f50-5f0d-7000-8000-000000000901'

function response(data: unknown, status = 200, etag = '"2"') {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: etag },
  })
}

function problemResponse(problem: unknown, status: number) {
  return new Response(JSON.stringify(problem), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

afterEach(() => vi.unstubAllGlobals())

describe('platform settings API wrappers', () => {
  it('lists settings versions through the authenticated generated client', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValueOnce(response({ items: [], next_cursor: null }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(listPlatformSettingsVersions(token)).resolves.toMatchObject({ items: [], next_cursor: null })

    expect(fetchMock).toHaveBeenCalledWith('/api/v1/platform-settings/versions?limit=50', expect.any(Object))
  })

  it('reads the published version through the generated client with session headers', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValueOnce(response({ id: versionId, status: 'published' }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(getCurrentPlatformSettings(token)).resolves.toMatchObject({ id: versionId })

    expect(fetchMock).toHaveBeenCalledWith('/api/v1/platform-settings/current', expect.any(Object))
    const headers = new Headers(fetchMock.mock.calls[0][1]?.headers)
    expect(headers.get('X-Correlation-ID')).toMatch(/^[0-9a-f-]+$/)
    expect(headers.get('X-CSRF-Token')).toBeNull()
  })

  it('sends CSRF, idempotency, and If-Match for a settings draft lifecycle', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(response({ id: versionId, status: 'draft' }, 201, '"1"'))
      .mockResolvedValueOnce(response({ id: versionId, status: 'draft' }, 200, '"3"'))
      .mockResolvedValueOnce(response({ id: versionId, status: 'validated' }, 200, '"4"'))
    vi.stubGlobal('fetch', fetchMock)

    await createPlatformSettingsDraft(token)
    await setPlatformSetting(token, versionId, 'identity.session_idle_minutes', { value_type: 'integer', value: 30 }, 2)
    await validatePlatformSettingsVersion(token, versionId, 3)

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      '/api/v1/platform-settings/versions',
      `/api/v1/platform-settings/versions/${versionId}/settings/identity.session_idle_minutes`,
      `/api/v1/platform-settings/versions/${versionId}/validate`,
    ])
    const [create, setValue, validate] = fetchMock.mock.calls.map(([, init]) => new Headers(init?.headers))
    expect(create.get('X-CSRF-Token')).toBe(token)
    expect(create.get('Idempotency-Key')).toMatch(/^platform-settings-draft-/)
    expect(setValue.get('X-CSRF-Token')).toBe(token)
    expect(setValue.get('Idempotency-Key')).toBeNull()
    expect(setValue.get('If-Match')).toBe('"2"')
    expect(validate.get('X-CSRF-Token')).toBe(token)
    expect(validate.get('Idempotency-Key')).toBeNull()
    expect(validate.get('If-Match')).toBe('"3"')
  })

  it('surfaces Problem Details as an ApiError', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValueOnce(
      problemResponse({ type: 'forbidden', title: 'Forbidden', status: 403, detail: 'No platform access.' }, 403),
    ))

    await expect(getCurrentPlatformSettings(token)).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
      problem: { type: 'forbidden' },
    })
  })
})
