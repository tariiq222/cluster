// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

import { ApiError, type Session } from '../../api'
import { listWorkflowInstances } from './workflow-api'
import { MyRequests } from './MyRequests'

vi.mock('./workflow-api', () => ({
  listWorkflowInstances: vi.fn(),
  getWorkflowInstance: vi.fn(),
}))

const listWorkflowInstancesMock = vi.mocked(listWorkflowInstances)
const session = { access_token: 'test-token', user_id: 'user-1' } as unknown as Session


describe('MyRequests screen', () => {
  beforeEach(() => listWorkflowInstancesMock.mockReset())
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders the Arabic heading and empty state for the current user', async () => {
    listWorkflowInstancesMock.mockResolvedValueOnce({ items: [], total: 0 })
    render(<MyRequests locale="ar" session={session} scopeReady scopeEpoch={0} />)
    expect(await screen.findByRole('heading', { name: 'طلباتي' })).toBeTruthy()
    expect(screen.getByText('لا توجد طلبات مرسلة بعد')).toBeTruthy()
  })

  it('clears old requests and ignores their late scoped response', async () => {
    let resolveOld!: (value: { items: Array<{ id: string; subject: string }>; total: number }) => void
    listWorkflowInstancesMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveOld = resolve
    }))
    const { rerender } = render(<MyRequests locale="ar" session={session} scopeReady scopeEpoch={1} />)

    rerender(<MyRequests locale="ar" session={session} scopeReady={false} scopeEpoch={2} />)
    resolveOld({ items: [{ id: 'old-request', subject: 'طلب قديم' }], total: 1 })

    await act(async () => {
      await Promise.resolve()
    })
    expect(screen.queryByText('طلب قديم')).toBeNull()
    expect(listWorkflowInstancesMock).toHaveBeenCalledTimes(1)
  })

  it('routes the per-request Details button through the SPA navigation, not window.location.href', async () => {
    listWorkflowInstancesMock.mockResolvedValueOnce({
      items: [{ id: 'req-1', subject: 'Annual leave' }],
      total: 1,
    })
    const onNavigate = vi.fn()
    render(<MyRequests locale="en" session={session} scopeReady scopeEpoch={0} onNavigate={onNavigate} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Details' }))
    expect(onNavigate).toHaveBeenCalledWith('/my-requests/req-1')
  })

  it('renders the forbidden panel when the server rejects the request list with HTTP 403', async () => {
    listWorkflowInstancesMock.mockRejectedValueOnce(
      new ApiError(403, {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
      }),
    )
    render(<MyRequests locale="en" session={session} scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('You do not have access')).toBeTruthy()
  })
})
