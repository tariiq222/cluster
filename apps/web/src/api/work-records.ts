import * as generated from './generated/cluster'
import type {
  Notification as GeneratedNotification,
  NotificationCollection as GeneratedNotificationCollection,
  WorkRecordCollection as GeneratedWorkRecordCollection,
  WorkRecordCreate,
  WorkRecordSchema,
} from './generated/cluster'
import { requestInit, unwrap } from './http'

export type WorkRecord = Omit<WorkRecordSchema, 'payload'> & {
  payload: WorkRecordSchema['payload'] & {
    title?: string
    description?: string
  }
}

export type WorkRecordCollection = Omit<GeneratedWorkRecordCollection, 'items'> & {
  items: WorkRecord[]
}

export type Notification = GeneratedNotification
export type NotificationCollection = GeneratedNotificationCollection
export type CreateWorkRecordInput = WorkRecordCreate

const PAGE_LIMIT = 20

export async function createWorkRecord(
  token: string,
  input: CreateWorkRecordInput,
): Promise<WorkRecord> {
  return unwrap<WorkRecord>(
    await generated.createWorkRecord(
      input,
      requestInit(token, { command: true, idempotency: 'request' }),
    ),
  )
}

export async function listWorkRecords(
  token: string,
  cursor?: string,
): Promise<WorkRecordCollection> {
  return unwrap<WorkRecordCollection>(
    await generated.listWorkRecords(
      { limit: PAGE_LIMIT, ...(cursor ? { cursor } : {}) },
      requestInit(token),
    ),
  )
}

export async function getWorkRecord(token: string, recordId: string): Promise<WorkRecord> {
  return unwrap<WorkRecord>(await generated.getWorkRecord(recordId, requestInit(token)))
}

export async function cancelWorkRecord(
  token: string,
  recordId: string,
  reason: string,
  lockVersion?: number,
): Promise<WorkRecord> {
  return unwrap<WorkRecord>(
    await generated.cancelWorkRecord(
      recordId,
      { reason },
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function archiveWorkRecord(
  token: string,
  recordId: string,
  reason: string,
  lockVersion?: number,
): Promise<WorkRecord> {
  return unwrap<WorkRecord>(
    await generated.archiveWorkRecord(
      recordId,
      { reason },
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function listNotifications(
  token: string,
  cursor?: string,
): Promise<NotificationCollection> {
  return unwrap<NotificationCollection>(
    await generated.listMyNotifications(
      { limit: PAGE_LIMIT, ...(cursor ? { cursor } : {}) },
      requestInit(token),
    ),
  )
}

export async function markNotificationRead(
  token: string,
  notificationId: string,
): Promise<Notification> {
  return unwrap<Notification>(
    await generated.markNotificationRead(notificationId, requestInit(token, { command: true })),
  )
}
