import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../api/generated/cluster', async () => {
  const actual = await vi.importActual<typeof import('../../api/generated/cluster')>('../../api/generated/cluster')
  return {
    ...actual,
    getWorkflowInstance: vi.fn(),
    getWorkflowStep: vi.fn(),
    listWorkflowStepsInbox: vi.fn(),
    actOnWorkflowStep: vi.fn(),
  }
})

import * as generated from '../../api/generated/cluster'
import {
  actOnWorkflowStep,
  getWorkflowInstance,
  getWorkflowStep,
  listActionableWorkflowStepsInbox,
} from './workflow-api'

const INSTANCE_ID = '01980f50-5f0d-7000-8000-000000000101'
const STEP_ID = '01980f50-5f0d-7000-8000-000000000102'
const USER_ID = '01980f50-5f0d-7000-8000-000000000103'
const VERSION_ID = '01980f50-5f0d-7000-8000-000000000104'
const CREATED_AT = '2026-07-23T08:00:00Z'

const getWorkflowInstanceMock = vi.mocked(generated.getWorkflowInstance)
const getWorkflowStepMock = vi.mocked(generated.getWorkflowStep)
const listWorkflowStepsInboxMock = vi.mocked(generated.listWorkflowStepsInbox)
const actOnWorkflowStepMock = vi.mocked(generated.actOnWorkflowStep)

describe('workflow detail API wrappers', () => {
  beforeEach(() => {
    getWorkflowInstanceMock.mockReset()
    getWorkflowStepMock.mockReset()
    listWorkflowStepsInboxMock.mockReset()
    actOnWorkflowStepMock.mockReset()
  })

  it('maps the flat generated WorkflowInstanceTracking response for request details', async () => {
    const tracking = {
      id: INSTANCE_ID,
      resource_type: 'workflow_instance',
      status: 'active',
      classification: 'internal',
      lock_version: 11,
      created_at: CREATED_AT,
      updated_at: CREATED_AT,
      workflow_version_id: VERSION_ID,
      source_module: 'workflow',
      source_type: 'work_record',
      source_id: 'WR-17',
      state: 'active',
      current_owner_user_id: USER_ID,
      age_seconds: 60,
      step_history: [{
        step_id: STEP_ID,
        workflow_instance_id: INSTANCE_ID,
        lock_version: 7,
        node_key: 'manager_review',
        node_type: 'approval',
        state: 'active',
        assignee_user_id: USER_ID,
        activated_at: CREATED_AT,
        completed_at: null,
        actor_user_id: null,
        decision: null,
        reason: null,
      }],
    } satisfies generated.WorkflowInstanceTracking
    getWorkflowInstanceMock.mockResolvedValue({ data: tracking, status: 200, headers: new Headers() })

    const result = await getWorkflowInstance('token', INSTANCE_ID)

    expect(result.instance).toEqual(expect.objectContaining({ id: INSTANCE_ID, state: 'active', lock_version: 11 }))
    expect(result.steps).toEqual([
      expect.objectContaining({ id: STEP_ID, workflow_instance_id: INSTANCE_ID, lock_version: 7 }),
    ])
  })

  it('returns the generated WorkflowStepDetail shape without substituting instance data', async () => {
    const step = {
      step_id: STEP_ID,
      workflow_instance_id: INSTANCE_ID,
      source_type: 'work_record',
      source_id: 'WR-17',
      state: 'active',
      assignee_user_id: USER_ID,
      created_at: CREATED_AT,
      lock_version: 7,
      allowed_actions: ['approve', 'return'],
      workflow_instance: {
        id: INSTANCE_ID,
        workflow_version_id: VERSION_ID,
        source_module: 'workflow',
        source_type: 'work_record',
        source_id: 'WR-17',
        state: 'active',
        lock_version: 11,
      },
    } satisfies generated.WorkflowStepDetail
    getWorkflowStepMock.mockResolvedValue({ data: step, status: 200, headers: new Headers() })

    await expect(getWorkflowStep('token', STEP_ID)).resolves.toEqual(step)
    expect(getWorkflowStepMock).toHaveBeenCalledWith(STEP_ID, expect.any(Object))
  })

  it('combines only the current principal waiting and active inbox steps', async () => {
    const waitingItem: generated.WorkflowStepInboxItem = {
      step_id: STEP_ID,
      workflow_instance_id: INSTANCE_ID,
      source_type: 'work_record',
      source_id: 'WR-17',
      state: generated.WorkflowStepInboxItemState.waiting,
      assignee_user_id: USER_ID,
      created_at: CREATED_AT,
      lock_version: 7,
      allowed_actions: [generated.WorkflowStepInboxItemAllowedActionsItem.approve],
    }
    const waiting: generated.WorkflowStepCollection = {
      items: [waitingItem],
      next_cursor: null,
    }
    const active: generated.WorkflowStepCollection = {
      ...waiting,
      items: [{ ...waitingItem, step_id: '01980f50-5f0d-7000-8000-000000000105', state: generated.WorkflowStepInboxItemState.active }],
    }
    listWorkflowStepsInboxMock.mockResolvedValueOnce({ data: waiting, status: 200, headers: new Headers() })
    listWorkflowStepsInboxMock.mockResolvedValueOnce({ data: active, status: 200, headers: new Headers() })

    await expect(listActionableWorkflowStepsInbox('token')).resolves.toEqual([
      expect.objectContaining({ id: STEP_ID, state: 'waiting' }),
      expect.objectContaining({ state: 'active' }),
    ])
    expect(listWorkflowStepsInboxMock).toHaveBeenNthCalledWith(1, { assignee: 'me', state: 'waiting', limit: 100 }, expect.any(Object))
    expect(listWorkflowStepsInboxMock).toHaveBeenNthCalledWith(2, { assignee: 'me', state: 'active', limit: 100 }, expect.any(Object))
  })

  it('forwards the reassignment target, reason, and step lock to the generated contract client', async () => {
    actOnWorkflowStepMock.mockResolvedValue({
      data: {
        id: STEP_ID,
        resource_type: 'workflow_step',
        status: 'active',
        classification: 'internal',
        lock_version: 7,
        created_at: CREATED_AT,
        updated_at: CREATED_AT,
      } satisfies generated.DomainResource,
      status: 200,
      headers: new Headers(),
    })

    await actOnWorkflowStep('token', STEP_ID, 'reassign', {
      target_user_id: USER_ID,
      reason: 'Coverage handover',
    }, 7)

    expect(actOnWorkflowStepMock).toHaveBeenCalledWith(
      STEP_ID,
      'reassign',
      { target_user_id: USER_ID, reason: 'Coverage handover' },
      expect.any(Object),
    )
  })
})
