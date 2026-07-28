import * as generated from './generated/cluster'
import { requestInit, unwrap } from './http'

const PAGE_LIMIT = 20
const TASK_IDEMPOTENCY_PREFIX = 'task'

export type Task = generated.Task
export type TaskCreate = generated.TaskCreate
export type TaskPatch = generated.TaskPatch
export type TaskTransitionInput = generated.TaskTransitionRequest
export type ParticipantCreate = generated.ParticipantCreate
export type CommentCreate = generated.CommentCreate

export type TaskState = generated.TaskState
export type TaskPriority = generated.TaskPriority
export type TaskAction = 'start' | 'block' | 'unblock' | 'complete' | 'cancel'
export type TaskRelationship = generated.ListTasksRelationship

export type TaskCollection = generated.EntityCollection
export type CommentCollection = generated.EntityCollection

export type ListTasksFilters = {
  cursor?: string
  limit?: number
  state?: TaskState
  relationship?: TaskRelationship
}

export type ListTaskCommentsParams = {
  cursor?: string
  limit?: number
}

/** List assigned tasks with the standard page size and any provided filter. */
export async function listTasks(
  token: string,
  filters: ListTasksFilters = {},
): Promise<TaskCollection> {
  return unwrap<TaskCollection>(
    await generated.listTasks(
      { limit: PAGE_LIMIT, ...filters },
      requestInit(token),
    ),
  )
}

/** Create a standalone or source-linked task. Sends an idempotency key so retries collapse. */
export async function createTask(token: string, input: TaskCreate): Promise<Task> {
  return unwrap<Task>(
    await generated.createTask(
      input,
      requestInit(token, { command: true, idempotency: TASK_IDEMPOTENCY_PREFIX }),
    ),
  )
}

/** Fetch an authorized task. */
export async function getTask(token: string, taskId: string): Promise<Task> {
  return unwrap<Task>(await generated.getTask(taskId, requestInit(token)))
}

/**
 * Patch mutable task fields. Sends `If-Match` for optimistic concurrency
 * and `Idempotency-Key` so a replayed edit collapses.
 */
export async function updateTask(
  token: string,
  taskId: string,
  patch: TaskPatch,
  lockVersion: number,
): Promise<Task> {
  return unwrap<Task>(
    await generated.updateTask(
      taskId,
      patch,
      requestInit(token, { mutation: true, lockVersion }),
    ),
  )
}

/**
 * Start, block, unblock, complete, or cancel a task. Use `input.reason` for `block`/`cancel`
 * and `input.note` for `complete`. `lockVersion` becomes the `If-Match` header.
 */
export async function transitionTask(
  token: string,
  taskId: string,
  action: TaskAction,
  input: TaskTransitionInput = {},
  lockVersion?: number,
): Promise<Task> {
  return unwrap<Task>(
    await generated.transitionTask(
      taskId,
      action,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

/** Add a participant to a task. */
export async function addTaskParticipant(
  token: string,
  taskId: string,
  input: ParticipantCreate,
): Promise<Task> {
  return unwrap<Task>(
    await generated.addTaskParticipant(
      taskId,
      input,
      requestInit(token, { command: true, idempotency: `${TASK_IDEMPOTENCY_PREFIX}-participant` }),
    ),
  )
}

/** List task comments with the standard page size. */
export async function listTaskComments(
  token: string,
  taskId: string,
  params: ListTaskCommentsParams = {},
): Promise<CommentCollection> {
  return unwrap<CommentCollection>(
    await generated.listTaskComments(
      taskId,
      { limit: PAGE_LIMIT, ...params },
      requestInit(token),
    ),
  )
}

/** Add a comment to a task (optionally mentioning other users). */
export async function addTaskComment(
  token: string,
  taskId: string,
  input: CommentCreate,
): Promise<Task> {
  return unwrap<Task>(
    await generated.addTaskComment(
      taskId,
      input,
      requestInit(token, { command: true, idempotency: `${TASK_IDEMPOTENCY_PREFIX}-comment` }),
    ),
  )
}

/** Attach a document the actor can access to a task. */
export async function attachTaskDocument(
  token: string,
  taskId: string,
  documentId: string,
): Promise<Task> {
  return unwrap<Task>(
    await generated.attachTaskDocument(
      taskId,
      { document_id: documentId },
      requestInit(token, { command: true, idempotency: `${TASK_IDEMPOTENCY_PREFIX}-attachment` }),
    ),
  )
}
