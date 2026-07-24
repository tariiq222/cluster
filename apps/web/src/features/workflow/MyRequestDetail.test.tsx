// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { ApiError, type Session } from '../../api'
import { getWorkflowInstance } from './workflow-api'
import { MyRequestDetail } from './MyRequestDetail'

vi.mock('./workflow-api', () => ({ getWorkflowInstance: vi.fn() }))

const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const mock = vi.mocked(getWorkflowInstance)

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})


describe('MyRequestDetail', () => {
  it('shows loading', () => {
    mock.mockReturnValue(new Promise(() => undefined))
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />)
    expect(screen.getByLabelText('Loading requests…')).toBeTruthy()
  })

  it('shows empty', async () => {
    mock.mockResolvedValue({ instance: { id: 'i', state: 'active' }, steps: [] })
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('No details are available for this record.')).toBeTruthy()
  })

  it('shows success', async () => {
    mock.mockResolvedValue({ instance: { id: 'i', state: 'active' }, steps: [{ id: 's', state: 'waiting', node_key: 'review' }] })
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('review')).toBeTruthy()
  })

  it('routes the Back-to-list control through the SPA navigation, not window.location.href', async () => {
    mock.mockResolvedValue({ instance: { id: 'i', state: 'active' }, steps: [] })
    const onNavigate = vi.fn()
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} onNavigate={onNavigate} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Back to list' }))
    expect(onNavigate).toHaveBeenCalledWith('/my-requests')
  })

  it('shows the conflict notice when the API returns HTTP 409', async () => {
    mock.mockRejectedValueOnce(
      new ApiError(409, {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
      }),
    )
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />)
    expect(
      await screen.findByText(
        'The data changed while the decision was being submitted. Refresh and try again.',
      ),
    ).toBeTruthy()
  })

  it('shows the stale notice when the API returns HTTP 412', async () => {
    mock.mockRejectedValueOnce(
      new ApiError(412, {
        type: 'about:blank',
        title: 'Precondition Failed',
        status: 412,
      }),
    )
    render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />)
    expect(
      await screen.findByText('This step is stale. Refresh before making a decision.'),
    ).toBeTruthy()
  })
})
