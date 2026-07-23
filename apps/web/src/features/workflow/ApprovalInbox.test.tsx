// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import type { Session } from '../../api'
import { actOnWorkflowStep, listActionableWorkflowStepsInbox, recordWorkflowDecision } from './workflow-api'
import { ApprovalInbox } from './ApprovalInbox'

vi.mock('./workflow-api', () => ({
  listActionableWorkflowStepsInbox: vi.fn(),
  recordWorkflowDecision: vi.fn(),
  actOnWorkflowStep: vi.fn(),
}))

const listActionableWorkflowStepsInboxMock = vi.mocked(listActionableWorkflowStepsInbox)
const recordWorkflowDecisionMock = vi.mocked(recordWorkflowDecision)
const actOnWorkflowStepMock = vi.mocked(actOnWorkflowStep)
const session = { access_token: 'test-token', user_id: 'user-1' } as unknown as Session

function inboxItem(allowed_actions: string[]) {
  return {
    id: 'step-1',
    source_type: 'Coverage review',
    source_id: 'WR-17',
    assignee_user_id: 'user-1',
    state: 'waiting',
    created_at: '2026-07-23T08:00:00Z',
    lock_version: 7,
    allowed_actions,
  }
}

describe('ApprovalInbox screen', () => {
  beforeEach(() => {
    listActionableWorkflowStepsInboxMock.mockReset()
    recordWorkflowDecisionMock.mockReset()
    actOnWorkflowStepMock.mockReset()
  })
  afterEach(() => { cleanup(); vi.restoreAllMocks() })

  it('renders the Arabic heading and empty state', async () => {
    listActionableWorkflowStepsInboxMock.mockResolvedValueOnce([])
    render(<ApprovalInbox locale="ar" session={session} scopeReady scopeEpoch={0} />)
    expect(await screen.findByRole('heading', { name: 'اعتماداتي' })).toBeTruthy()
    expect(screen.getByText('لا توجد اعتمادات تنتظر قرارك')).toBeTruthy()
  })

  it('clears approvals and ignores a late response from the previous scope epoch', async () => {
    let resolveOld!: (value: ReturnType<typeof inboxItem>[]) => void
    listActionableWorkflowStepsInboxMock.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve }))
    const { rerender } = render(<ApprovalInbox locale="ar" session={session} scopeReady scopeEpoch={1} />)

    rerender(<ApprovalInbox locale="ar" session={session} scopeReady={false} scopeEpoch={2} />)
    resolveOld([{ ...inboxItem([]), source_type: 'اعتماد قديم' }])

    await act(async () => { await Promise.resolve() })
    expect(screen.queryByText('اعتماد قديم')).toBeNull()
    expect(listActionableWorkflowStepsInboxMock).toHaveBeenCalledTimes(1)
  })

  it('submits a server-permitted reassignment with its target user, reason, and step lock', async () => {
    listActionableWorkflowStepsInboxMock.mockResolvedValueOnce([inboxItem(['reassign'])])
    actOnWorkflowStepMock.mockResolvedValue({ id: 'decision' })

    render(<ApprovalInbox locale="en" session={session} scopeReady scopeEpoch={0} />)
    fireEvent.change(await screen.findByLabelText('Reassign to user ID'), { target: { value: '01980f50-5f0d-7000-8000-000000000103' } })
    fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Coverage handover' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))

    await waitFor(() => expect(actOnWorkflowStepMock).toHaveBeenCalledWith(
      'test-token',
      'step-1',
      'reassign',
      { target_user_id: '01980f50-5f0d-7000-8000-000000000103', reason: 'Coverage handover' },
      7,
    ))
  })

  it('renders every workflow action explicitly permitted by the server', async () => {
    listActionableWorkflowStepsInboxMock.mockResolvedValueOnce([inboxItem(['approve', 'reject', 'return', 'reassign', 'escalate'])])

    render(<ApprovalInbox locale="en" session={session} scopeReady scopeEpoch={0} />)

    await screen.findByRole('button', { name: 'Approve' })
    expect(screen.getByRole('button', { name: 'Reject' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Return for correction' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Reassign' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Escalate' })).toBeTruthy()
  })

  it('renders and submits an escalation-only server action with a required reason', async () => {
    listActionableWorkflowStepsInboxMock.mockResolvedValueOnce([inboxItem(['escalate'])])
    actOnWorkflowStepMock.mockResolvedValue({ id: 'decision' })

    render(<ApprovalInbox locale="en" session={session} scopeReady scopeEpoch={0} />)
    expect(await screen.findByRole('button', { name: 'Escalate' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Escalate' }))
    expect(await screen.findByText('A reason is required for this action.')).toBeTruthy()
    expect(actOnWorkflowStepMock).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Escalation needed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Escalate' }))
    await waitFor(() => expect(actOnWorkflowStepMock).toHaveBeenCalledWith(
      'test-token',
      'step-1',
      'escalate',
      { reason: 'Escalation needed' },
      7,
    ))
  })
})
