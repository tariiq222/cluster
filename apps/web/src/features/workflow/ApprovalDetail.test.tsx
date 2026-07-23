// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('./workflow-api', () => ({
  getWorkflowStep: vi.fn(),
  recordWorkflowDecision: vi.fn(),
  actOnWorkflowStep: vi.fn(),
}))

import type { Session } from '../../api'
import { ApiError } from '../../api'
import type { WorkflowStepDetail } from '../../api/generated/cluster'
import { ApprovalDetail } from './ApprovalDetail'
import { actOnWorkflowStep, getWorkflowStep, recordWorkflowDecision } from './workflow-api'

const STEP_ID = '01980f50-5f0d-7000-8000-000000000102'
const INSTANCE_ID = '01980f50-5f0d-7000-8000-000000000101'
const USER_ID = '01980f50-5f0d-7000-8000-000000000103'
const VERSION_ID = '01980f50-5f0d-7000-8000-000000000104'
const session = { access_token: 'token', user_id: USER_ID } as unknown as Session

const getWorkflowStepMock = vi.mocked(getWorkflowStep)
const recordWorkflowDecisionMock = vi.mocked(recordWorkflowDecision)
const actOnWorkflowStepMock = vi.mocked(actOnWorkflowStep)

function stepDetail(allowedActions: string[], state = 'active'): WorkflowStepDetail {
  return {
    step_id: STEP_ID,
    workflow_instance_id: INSTANCE_ID,
    source_type: 'work_record',
    source_id: 'WR-17',
    state,
    assignee_user_id: USER_ID,
    created_at: '2026-07-23T08:00:00Z',
    lock_version: 7,
    allowed_actions: allowedActions,
    workflow_instance: {
      id: INSTANCE_ID,
      workflow_version_id: VERSION_ID,
      source_module: 'workflow',
      source_type: 'work_record',
      source_id: 'WR-17',
      state,
      lock_version: 99,
    },
  }
}

beforeEach(() => {
  getWorkflowStepMock.mockReset()
  recordWorkflowDecisionMock.mockReset()
  actOnWorkflowStepMock.mockReset()
})

afterEach(() => {
  cleanup()
})

describe('ApprovalDetail', () => {
  it('loads the authorized step and renders only actions allowed by the server', async () => {
    getWorkflowStepMock.mockResolvedValue(stepDetail(['approve', 'return']))

    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)

    expect(await screen.findByText(INSTANCE_ID)).toBeTruthy()
    expect(screen.getByText('work_record · WR-17')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Approve' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Return for correction' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Reject' })).toBeNull()
    expect(getWorkflowStepMock).toHaveBeenCalledWith('token', STEP_ID)
  })

  it('submits the step lock version and reloads resolved actions after success', async () => {
    getWorkflowStepMock
      .mockResolvedValueOnce(stepDetail(['reject']))
      .mockResolvedValueOnce(stepDetail([], 'completed'))
    recordWorkflowDecisionMock.mockResolvedValue({ id: 'decision' })

    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)
    fireEvent.change(await screen.findByLabelText('Decision reason'), { target: { value: 'Needs correction' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reject' }))

    await waitFor(() => expect(recordWorkflowDecisionMock).toHaveBeenCalledWith(
      'token',
      STEP_ID,
      { decision: 'reject', reason: 'Needs correction' },
      7,
    ))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Reject' })).toBeNull())
    expect(getWorkflowStepMock).toHaveBeenCalledTimes(2)
  })

  it('submits a server-permitted reassignment with the target user and the step lock', async () => {
    getWorkflowStepMock
      .mockResolvedValueOnce(stepDetail(['reassign']))
      .mockResolvedValueOnce(stepDetail([], 'active'))
    actOnWorkflowStepMock.mockResolvedValue({ id: 'reassigned' })

    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)
    const targetUser = await screen.findByLabelText('Reassign to user ID')
    fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Coverage handover' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))
    expect(await screen.findByText('A target user ID is required for reassignment.')).toBeTruthy()
    expect(actOnWorkflowStepMock).not.toHaveBeenCalled()

    fireEvent.change(targetUser, { target: { value: '01980f50-5f0d-7000-8000-000000000105' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))

    await waitFor(() => expect(actOnWorkflowStepMock).toHaveBeenCalledWith(
      'token',
      STEP_ID,
      'reassign',
      { target_user_id: '01980f50-5f0d-7000-8000-000000000105', reason: 'Coverage handover' },
      7,
    ))
  })

  it('renders and validates an escalation-only server action', async () => {
    getWorkflowStepMock.mockResolvedValue(stepDetail(['escalate']))
    actOnWorkflowStepMock.mockResolvedValue({ id: 'escalated' })

    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)
    expect(await screen.findByRole('button', { name: 'Escalate' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Escalate' }))
    expect(await screen.findByText('A reason is required for this action.')).toBeTruthy()
    expect(actOnWorkflowStepMock).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Escalation needed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Escalate' }))
    await waitFor(() => expect(actOnWorkflowStepMock).toHaveBeenCalledWith(
      'token',
      STEP_ID,
      'escalate',
      { reason: 'Escalation needed' },
      7,
    ))
  })

  it.each([
    [403, 'You do not have access'],
    [404, 'No details are available for this record.'],
  ])('renders the read state for HTTP %s', async (status, expected) => {
    getWorkflowStepMock.mockRejectedValue(new ApiError(status, { type: 'about:blank', title: 'Failure', status }))
    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)
    expect(await screen.findByText(expected)).toBeTruthy()
  })

  it.each([
    [409, 'The data changed while the decision was being submitted. Refresh and try again.'],
    [412, 'This step is stale. Refresh before making a decision.'],
  ])('renders the decision state for HTTP %s', async (status, expected) => {
    getWorkflowStepMock.mockResolvedValue(stepDetail(['approve']))
    recordWorkflowDecisionMock.mockRejectedValue(new ApiError(status, { type: 'about:blank', title: 'Failure', status }))
    render(<ApprovalDetail locale="en" session={session} stepId={STEP_ID} scopeReady scopeEpoch={1} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }))
    expect(await screen.findByText(expected)).toBeTruthy()
  })

  it('clears the previous scope and ignores its late step response', async () => {
    let resolveOldScope!: (value: WorkflowStepDetail) => void
    getWorkflowStepMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveOldScope = resolve
    }))

    const view = render(
      <ApprovalDetail
        locale="en"
        session={session}
        stepId={STEP_ID}
        scopeReady
        scopeEpoch={1}
      />,
    )

    view.rerender(
      <ApprovalDetail
        locale="en"
        session={session}
        stepId={STEP_ID}
        scopeReady={false}
        scopeEpoch={2}
      />,
    )
    resolveOldScope(stepDetail(['approve']))

    await waitFor(() => expect(screen.getByLabelText('Loading requests…')).toBeTruthy())
    expect(screen.queryByText(INSTANCE_ID)).toBeNull()
    expect(getWorkflowStepMock).toHaveBeenCalledTimes(1)
  })
})
