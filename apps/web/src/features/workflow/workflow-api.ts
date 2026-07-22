import * as generated from '../../api/generated/cluster'
import { requestInit, unwrap } from '../../api/http'
import type { R1Collection, R1Entity } from '../../api/r1'

export type WorkflowInstance = R1Entity & {
  id: string
  started_by_user_id?: string
  state?: string
  lock_version?: number
  created_at?: string
  updated_at?: string
}

export type WorkflowStep = R1Entity & {
  id: string
  workflow_instance_id?: string
  assignee_user_id?: string | null
  state?: string
  node_key?: string
  completed_at?: string | null
  created_at?: string
  lock_version?: number
}

export type WorkflowInstanceDetails = {
  instance: WorkflowInstance
  steps: WorkflowStep[]
}

export async function listWorkflowInstances(token: string): Promise<R1Collection<WorkflowInstance>> {
  return unwrap<R1Collection<WorkflowInstance>>(
    await generated.listWorkflowInstances({ limit: 100 }, requestInit(token)),
  )
}

export async function getWorkflowInstance(
  token: string,
  instanceId: string,
): Promise<WorkflowInstanceDetails> {
  return unwrap<WorkflowInstanceDetails>(
    await generated.getWorkflowInstance(instanceId, requestInit(token)),
  )
}

export type WorkflowDecision = {
  decision: 'approve' | 'reject' | 'return' | 'accept' | 'decline'
  reason?: string
}

export async function recordWorkflowDecision(
  token: string,
  stepId: string,
  input: WorkflowDecision,
  lockVersion = 1,
): Promise<R1Entity> {
  return unwrap<R1Entity>(
    await generated.recordWorkflowDecision(
      stepId,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}
