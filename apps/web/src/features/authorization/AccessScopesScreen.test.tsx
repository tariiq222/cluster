// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, waitFor } from '@testing-library/react'

import { SessionProvider } from '../../app/session-context'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import type { AuthorizationItem } from '../../api/r1'
import { listAuthorization } from '../../api/r1'
import { AccessScopesScreen } from './AccessScopesScreen'

vi.mock('../../api/r1', () => ({
  listAuthorization: vi.fn(),
}))

const listAuthorizationMock = vi.mocked(listAuthorization)

const session: Session = {
  csrf_token: 'csrf',
  access_token: 'csrf',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  expires_at: '2026-07-17T12:00:00Z',
  restricted: false,
  principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
}

function item(overrides: Partial<AuthorizationItem> = {}): AuthorizationItem {
  return { id: 'row-1', ...overrides } as AuthorizationItem
}

afterEach(() => { cleanup(); vi.clearAllMocks() })

describe('AccessScopesScreen', () => {
  it('renders the canonical scope row fields (subject_id, role_code, scope_type, scope_id, starts_at, ends_at) when present', async () => {
    listAuthorizationMock.mockResolvedValueOnce([
      item({
        subject_id: 'user-42',
        role_code: 'role-admin',
        scope_type: 'organization',
        scope_id: 'org-7',
        starts_at: '2026-01-01T00:00:00Z',
        ends_at: '2026-12-31T23:59:59Z',
      }),
    ])

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    expect(await screen.findByText('user-42')).toBeTruthy()
    expect(screen.getByText('role-admin')).toBeTruthy()
    expect(screen.getByText('organization:org-7')).toBeTruthy()
    expect(screen.getByText('2026-01-01T00:00:00Z → 2026-12-31T23:59:59Z')).toBeTruthy()
  })

  it('renders the column headers for the table', async () => {
    listAuthorizationMock.mockResolvedValueOnce([
      item({
        subject_id: 'u',
        role_code: 'r',
        scope_type: 'organization',
        scope_id: 'o',
        starts_at: 's',
        ends_at: 'e',
      }),
    ])

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    await waitFor(() => expect(screen.getByRole('columnheader', { name: 'User' })).toBeTruthy())
    expect(screen.getByRole('columnheader', { name: 'Role' })).toBeTruthy()
    expect(screen.getByRole('columnheader', { name: 'Scope' })).toBeTruthy()
    expect(screen.getByRole('columnheader', { name: 'Window' })).toBeTruthy()
  })

  it('falls back to "—" when an item omits any of the scope fields', async () => {
    listAuthorizationMock.mockResolvedValueOnce([item({})])

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    await waitFor(() => {
      const row = document.querySelector('tbody tr')
      expect(row).not.toBeNull()
    })
    const cells = Array.from(document.querySelectorAll('tbody tr td')).map((td) => td.textContent ?? '')
    expect(cells[0]).toBe('—')
    expect(cells[1]).toBe('—')
    expect(cells[2]).toContain('—')
    expect(cells[3]).toContain('→')
    expect(cells[3]).toContain('—')
  })

  it('keeps the rendered value when a field is a non-empty string', async () => {
    listAuthorizationMock.mockResolvedValueOnce([
      item({
        subject_id: 'has-value',
        role_code: 'r',
        scope_type: 'organization',
        scope_id: 'o',
        starts_at: '2026-01-01',
        ends_at: '2026-12-31',
      }),
    ])

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    expect(await screen.findByText('has-value')).toBeTruthy()
  })

  it('shows the empty state when the list returns no rows', async () => {
    listAuthorizationMock.mockResolvedValueOnce([])

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    expect(await screen.findByText('No role assignments with scopes are available in this environment.')).toBeTruthy()
  })

  it('shows the denied panel when the API rejects with 403', async () => {
    listAuthorizationMock.mockRejectedValueOnce(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    expect(await screen.findByText('We could not load the access scopes.')).toBeTruthy()
  })

  it('shows the inline error when the API rejects with a non-403 status', async () => {
    listAuthorizationMock.mockRejectedValueOnce(new Error('boom'))

    render(
      <SessionProvider locale="en" session={session}>
        <AccessScopesScreen locale="en" scopeReady scopeEpoch={0} />
      </SessionProvider>,
    )

    expect(await screen.findByText(/Try again/)).toBeTruthy()
  })
})