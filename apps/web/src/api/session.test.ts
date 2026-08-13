// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './http'

const { customFetchMock } = vi.hoisted(() => ({ customFetchMock: vi.fn() }))

vi.mock('./http', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./http')>()
  return { ...actual, customFetch: customFetchMock }
})

import { login, restoreSession } from './session'

const USER_ID = '0197f0e0-0000-7000-8000-000000000021'

function response(data: unknown, status = 200) {
  return { data, status, headers: new Headers() }
}

describe('identity session boundaries', () => {
  beforeEach(() => {
    customFetchMock.mockReset()
    sessionStorage.clear()
  })

  it('restores the authoritative principal/session shape and rotates CSRF without sending a nonexistent token', async () => {
    customFetchMock
      .mockResolvedValueOnce(
        response({
          data: {
            principal: { user_id: USER_ID },
            account: { id: 'account-1' },
            session: { restricted: true },
          },
        }),
      )
      .mockResolvedValueOnce(response({ data: { csrf_token: 'rotated-csrf' } }))

    const restored = await restoreSession()

    expect(restored).toMatchObject({
      userId: USER_ID,
      csrfToken: 'rotated-csrf',
      restricted: true,
    })
    expect(customFetchMock.mock.calls[1]![1].headers).not.toHaveProperty(
      'X-CSRF-Token',
    )
    expect(customFetchMock.mock.calls[1]![1].headers).not.toHaveProperty(
      'Idempotency-Key',
    )
  })

  it.each([401, 403])(
    'treats restore %s as an unauthenticated session',
    async (status) => {
      customFetchMock.mockResolvedValueOnce(
        response(
          { type: 'about:blank', title: 'Not authenticated', status },
          status,
        ),
      )

      await expect(restoreSession()).resolves.toBeNull()
    },
  )

  it('rejects malformed identity or CSRF responses', async () => {
    customFetchMock.mockResolvedValueOnce(
      response({
        data: {
          principal: { user_id: 'not-a-uuid' },
          session: { restricted: false },
        },
      }),
    )
    await expect(restoreSession()).rejects.toMatchObject({ status: 502 })

    customFetchMock
      .mockResolvedValueOnce(
        response({
          data: {
            principal: { user_id: USER_ID },
            session: { restricted: false },
          },
        }),
      )
      .mockResolvedValueOnce(response({ data: { csrf_token: '' } }))
    await expect(restoreSession()).rejects.toMatchObject({ status: 502 })
  })

  it('accepts login without persisting CSRF or user identity in browser storage', async () => {
    customFetchMock.mockResolvedValueOnce(
      response({
        data: {
          user_id: USER_ID,
          csrf_token: 'login-csrf',
          expires_at: '2026-12-31T00:00:00Z',
          restricted: false,
        },
      }),
    )

    await expect(login('user', 'password')).resolves.toMatchObject({
      userId: USER_ID,
      csrfToken: 'login-csrf',
      restricted: false,
    })
    expect(sessionStorage.length).toBe(0)
  })

  it('does not convert unrelated restore failures into an unauthenticated session', async () => {
    const failure = new ApiError(500, {
      type: 'about:blank',
      title: 'Server error',
      status: 500,
    })
    customFetchMock.mockRejectedValueOnce(failure)

    await expect(restoreSession()).rejects.toBe(failure)
  })
})
