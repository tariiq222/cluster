import * as generated from '../../api/generated/cluster'
import { requestInit, unwrap, parseStrongEtag } from '../../api/http'
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
  history?: WorkflowStep[]
}

export type WorkflowInboxItem = WorkflowStep & {
  source_type?: string
  source_id?: string
  allowed_actions: string[]
}

export type Task = R1Entity & {
  id: string
  title?: string
  description?: string
  state?: string
  status?: string
  lock_version?: number
  allowed_actions?: string[]
}

export async function listWorkflowInstances(token: string): Promise<R1Collection<WorkflowInstance>> {
  return unwrap<R1Collection<WorkflowInstance>>(
    await generated.listWorkflowInstances({ limit: 100 }, requestInit(token)),
  )
}

/**
 * The approval inbox is keyed on the step assignee. Listing instances would only
 * ever return what the caller started, and an approver never starts what they decide.
 */
export async function listWorkflowStepsInbox(
  token: string,
  state: 'waiting' | 'active' | 'completed' | 'rejected' | 'cancelled' = 'active',
): Promise<R1Collection<WorkflowInboxItem>> {
  const collection = unwrap<{ items: Array<WorkflowInboxItem & { step_id: string }>; next_cursor?: string | null }>(
    await generated.listWorkflowStepsInbox({ assignee: 'me', state, limit: 100 }, requestInit(token)),
  )
  return {
    ...collection,
    items: (collection.items ?? []).map(({ step_id, ...item }) => ({ ...item, id: step_id })),
    total: collection.items?.length ?? 0,
  }
}

export async function listApprovalInboxSteps(token: string): Promise<WorkflowInboxItem[]> {
  return listActionableWorkflowStepsInbox(token)
}

/**
 * The personal inbox is deliberately composed from two server-filtered queries.
 * `assignee=me` stays enforced by the API, while both actionable step states
 * remain visible without mixing historical records into the queue.
 */
export async function listActionableWorkflowStepsInbox(token: string): Promise<WorkflowInboxItem[]> {
  const [waiting, active] = await Promise.all([
    listWorkflowStepsInbox(token, 'waiting'),
    listWorkflowStepsInbox(token, 'active'),
  ])

  return [...waiting.items, ...active.items]
}

export async function getWorkflowInstance(
  token: string,
  instanceId: string,
): Promise<WorkflowInstanceDetails> {
  const tracking = unwrap<generated.WorkflowInstanceTracking>(
    await generated.getWorkflowInstance(instanceId, requestInit(token)),
  )
  const instance: WorkflowInstance = {
    ...tracking,
    id: tracking.id,
    state: tracking.state,
    lock_version: tracking.lock_version,
    created_at: tracking.created_at,
    updated_at: tracking.updated_at,
  }
  const history: WorkflowStep[] = tracking.step_history.map((step) => ({
    id: step.step_id,
    workflow_instance_id: step.workflow_instance_id ?? instanceId,
    assignee_user_id: step.assignee_user_id,
    state: step.state,
    node_key: step.node_key,
    completed_at: step.completed_at,
    created_at: step.activated_at ?? undefined,
    lock_version: step.lock_version,
    decision: step.decision,
    reason: step.reason,
    actor_user_id: step.actor_user_id,
  }))
  return { instance, steps: history, history }
}

export async function getWorkflowStep(
  token: string,
  stepId: string,
): Promise<generated.WorkflowStepDetail> {
  return unwrap<generated.WorkflowStepDetail>(
    await generated.getWorkflowStep(stepId, requestInit(token)),
  )
}

export type WorkflowDecision = {
  decision: 'approve' | 'reject' | 'return' | 'accept' | 'decline'
  reason?: string
}

export type WorkflowStepActionInput = Pick<generated.WorkflowStepAction, 'reason' | 'target_user_id'>

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

export async function actOnWorkflowStep(
  token: string,
  stepId: string,
  action: 'reassign' | 'escalate',
  input: WorkflowStepActionInput,
  lockVersion = 1,
): Promise<R1Entity> {
  return unwrap<R1Entity>(
    await generated.actOnWorkflowStep(stepId, action, input, requestInit(token, { command: true, lockVersion })),
  )
}

export async function listTasks(token: string): Promise<R1Collection<Task>> {
  return unwrap<R1Collection<Task>>(await generated.listTasks({ limit: 100 }, requestInit(token)))
}

export function workflowLockVersion(value: unknown): number {
  if (typeof value === 'number' && value > 0) return value
  if (typeof value === 'string') return parseStrongEtag(value) ?? 1
  return 1
}
