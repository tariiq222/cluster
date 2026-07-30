// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/generated/cluster', async () => {
  const actual = await vi.importActual<typeof import('../api/generated/cluster')>('../api/generated/cluster')
  return {
    ...actual,
    getCurrentPrincipal: vi.fn(),
    listMyScopes: vi.fn(),
    selectMyScope: vi.fn(),
    listAuthorizationAssignmentScopeTargets: vi.fn(),
  }
})

vi.mock('../api/http', async () => {
  const actual = await vi.importActual<typeof import('../api/http')>('../api/http')
  return {
    ...actual,
    parseStrongEtag: vi.fn(),
  }
})

import { PrincipalProvider, usePrincipal } from './principal-context'
import * as generated from '../api/generated/cluster'
import { ApiError, parseStrongEtag } from '../api/http'

const FACILITY_A = '01980f50-5f0d-7000-8000-0000000000a1'
const UNIT_A = '01980f50-5f0d-7000-8000-0000000000a2'

function principal(capabilities: string[]): { data: { subject_id: string; tenant_id: string; clearance: 'public'; correlation_id: string; capabilities: string[]; features: { work_management: boolean; tasks: boolean } }; status: 200; headers: Headers } {
  const headers = new Headers()
  return {
    status: 200,
    headers,
    data: {
      subject_id: '01980f50-5f0d-7000-8000-0000000000f1',
      tenant_id: '01980f50-5f0d-7000-8000-0000000000f2',
      clearance: 'public',
      correlation_id: '01980f50-5f0d-7000-8000-0000000000f3',
      capabilities,
      features: { work_management: false, tasks: true },
    },
  }
}

function scopeSnapshot(scopeType: 'facility' | 'unit', scopeId: string, lock: number): { data: { available_scopes: Array<{ scope_type: 'facility' | 'unit' | 'cluster'; scope_id: string; label: string }>; effective_scope: { scope_type: 'facility' | 'unit' | 'cluster'; scope_id: string; label: string } }; status: 200; headers: Headers } {
  const headers = new Headers()
  headers.set('ETag', `"${lock}"`)
  return {
    status: 200,
    headers,
    data: {
      available_scopes: [
        { scope_type: 'facility', scope_id: FACILITY_A, label: 'Facility A' },
        { scope_type: 'unit', scope_id: UNIT_A, label: 'Unit A' },
      ],
      effective_scope: { scope_type: scopeType, scope_id: scopeId, label: scopeId === UNIT_A ? 'Unit A' : 'Facility A' },
    },
  }
}

function Probe({ onRevision }: { onRevision: (rev: number) => void }) {
  const principal = usePrincipal()
  onRevision(principal.revision)
  return (
    <div>
      <span data-testid="state">{principal.state}</span>
      <span data-testid="revision">{principal.revision}</span>
      <span data-testid="scope-epoch">{principal.scopeEpoch}</span>
      <span data-testid="scope-ready">{String(principal.scopeReady)}</span>
      <ul data-testid="capabilities">{principal.capabilities?.map((cap) => <li key={cap}>{cap}</li>) ?? null}</ul>
      <span data-testid="effective-scope">{principal.effectiveScope ? `${principal.effectiveScope.scopeType}:${principal.effectiveScope.scopeId}` : 'none'}</span>
      <button type="button" onClick={() => void principal.selectScope('unit', UNIT_A)}>select-unit</button>
    </div>
  )
}

const getCurrentPrincipalMock = vi.mocked(generated.getCurrentPrincipal)
const listMyScopesMock = vi.mocked(generated.listMyScopes)
const selectMyScopeMock = vi.mocked(generated.selectMyScope)
const parseStrongEtagMock = vi.mocked(parseStrongEtag)

describe('PrincipalProvider', () => {
  beforeEach(() => {
    getCurrentPrincipalMock.mockReset()
    listMyScopesMock.mockReset()
    selectMyScopeMock.mockReset()
    parseStrongEtagMock.mockReset().mockImplementation((value: string | null) => {
      if (!value) return null
      const match = value.match(/^"(\d+)"$/)
      return match ? Number(match[1]) : null
    })
  })

  afterEach(() => { cleanup(); vi.restoreAllMocks() })

  it('exposes capabilities and increments revision after a scope change', async () => {
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read']))
    listMyScopesMock.mockResolvedValueOnce(scopeSnapshot('facility', FACILITY_A, 3))
    selectMyScopeMock.mockResolvedValueOnce(scopeSnapshot('unit', UNIT_A, 4))
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read', 'tasks.list']))

    const onRevision = vi.fn()
    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={onRevision} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))
    expect(screen.getByTestId('capabilities').textContent).toContain('tasks.read')

    await act(async () => {
      screen.getByRole('button', { name: 'select-unit' }).click()
    })

    await waitFor(() => expect(screen.getByTestId('revision').textContent).toBe('1'))
    expect(screen.getByTestId('effective-scope').textContent).toBe(`unit:${UNIT_A}`)
    expect(screen.getByTestId('capabilities').textContent).toContain('tasks.list')
    expect(selectMyScopeMock).toHaveBeenCalledWith(
      { scope_type: 'unit', scope_id: UNIT_A },
      expect.objectContaining({ headers: expect.objectContaining({ 'If-Match': '"3"' }) }),
    )
  })

  it('returns denied state for 401/403 and keeps capabilities hidden', async () => {
    getCurrentPrincipalMock.mockRejectedValueOnce(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))
    listMyScopesMock.mockRejectedValueOnce(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))

    const onRevision = vi.fn()
    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={onRevision} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('denied'))
    expect(screen.getByTestId('capabilities').textContent).toBe('')
  })

  it('does not report a scope as ready when the server has no effective scope', async () => {
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read']))
    listMyScopesMock.mockResolvedValueOnce({
      status: 200,
      headers: new Headers({ ETag: '"1"' }),
      data: { available_scopes: [], effective_scope: null },
    } as never)

    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={() => undefined} />
      </PrincipalProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))
    expect(screen.getByTestId('scope-ready').textContent).toBe('false')
    expect(screen.getByTestId('effective-scope').textContent).toBe('none')
  })

  it('clears capabilities before applying the new scope so consumers never see stale data', async () => {
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read']))
    listMyScopesMock.mockResolvedValueOnce(scopeSnapshot('facility', FACILITY_A, 7))
    let resolveSelection!: (value: ReturnType<typeof scopeSnapshot>) => void
    selectMyScopeMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveSelection = resolve
    }))
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read']))

    const onRevision = vi.fn()
    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={onRevision} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))
    act(() => {
      screen.getByRole('button', { name: 'select-unit' }).click()
    })

    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('loading'))
    expect(screen.getByTestId('scope-ready').textContent).toBe('false')
    expect(screen.getByTestId('scope-epoch').textContent).toBe('2')
    expect(screen.getByTestId('capabilities').textContent).toBe('')
    expect(screen.getByTestId('effective-scope').textContent).toBe('none')

    await act(async () => {
      resolveSelection(scopeSnapshot('unit', UNIT_A, 8))
    })
    await waitFor(() => expect(screen.getByTestId('revision').textContent).toBe('1'))
    expect(screen.getByTestId('effective-scope').textContent).toBe(`unit:${UNIT_A}`)
  })

  it('reloads principal and scopes after a 412 then exposes a stale state', async () => {
    getCurrentPrincipalMock
      .mockResolvedValueOnce(principal(['tasks.read']))
      .mockResolvedValueOnce(principal(['tasks.read', 'tasks.list']))
    listMyScopesMock
      .mockResolvedValueOnce(scopeSnapshot('facility', FACILITY_A, 7))
      .mockResolvedValueOnce(scopeSnapshot('facility', FACILITY_A, 8))
    selectMyScopeMock.mockRejectedValueOnce(new ApiError(412, {
      type: 'precondition-failed',
      title: 'Scope changed',
      status: 412,
    }))

    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={() => undefined} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))

    act(() => {
      screen.getByRole('button', { name: 'select-unit' }).click()
    })

    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('stale'))
    expect(screen.getByTestId('effective-scope').textContent).toBe(`facility:${FACILITY_A}`)
    expect(screen.getByTestId('capabilities').textContent).toContain('tasks.list')
    expect(getCurrentPrincipalMock).toHaveBeenCalledTimes(2)
    expect(listMyScopesMock).toHaveBeenCalledTimes(2)
  })

  it('does not report ready when capabilities cannot be refreshed after a scope change', async () => {
    getCurrentPrincipalMock
      .mockResolvedValueOnce(principal(['tasks.read']))
      .mockRejectedValueOnce(new ApiError(403, {
        type: 'access-denied',
        title: 'Forbidden',
        status: 403,
      }))
    listMyScopesMock.mockResolvedValueOnce(scopeSnapshot('facility', FACILITY_A, 7))
    selectMyScopeMock.mockResolvedValueOnce(scopeSnapshot('unit', UNIT_A, 8))

    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={() => undefined} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))

    act(() => {
      screen.getByRole('button', { name: 'select-unit' }).click()
    })

    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('denied'))
    expect(screen.getByTestId('capabilities').textContent).toBe('')
    expect(screen.getByTestId('effective-scope').textContent).toBe(`unit:${UNIT_A}`)
  })

  it('fails closed when no ETag is supplied for the scope update', async () => {
    getCurrentPrincipalMock.mockResolvedValueOnce(principal(['tasks.read']))
    listMyScopesMock.mockResolvedValueOnce({ status: 200, data: scopeSnapshot('facility', FACILITY_A, 1).data, headers: new Headers() })

    render(
      <PrincipalProvider token="test-token">
        <Probe onRevision={() => undefined} />
      </PrincipalProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('ready'))
    await act(async () => {
      screen.getByRole('button', { name: 'select-unit' }).click()
    })
    expect(selectMyScopeMock).not.toHaveBeenCalled()
  })
})
