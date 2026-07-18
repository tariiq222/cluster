import type {
  Notification as GeneratedNotification,
  NotificationCollection as GeneratedNotificationCollection,
  Session as GeneratedSession,
  WorkRecordCollection as GeneratedWorkRecordCollection,
  WorkRecordCreate,
  WorkRecordSchema,
} from './api/generated/cluster'
import type {
  Cluster as GeneratedCluster,
  ClusterCreate,
  Facility as GeneratedFacility,
  FacilityCollection as GeneratedFacilityCollection,
  FacilityCreate,
  OrganizationNodeCreate,
  OrganizationUnit as GeneratedOrganizationUnit,
  OrganizationUnitCollection as GeneratedOrganizationUnitCollection,
  Assignment as GeneratedAssignment,
  AssignmentCollection as GeneratedAssignmentCollection,
  AssignmentCreate,
  Person as GeneratedPerson,
  PersonCollection as GeneratedPersonCollection,
  PersonCreate,
  Position as GeneratedPosition,
  PositionCollection as GeneratedPositionCollection,
  PositionCreate,
  UserAccount as GeneratedUserAccount,
  UserAccountCollection as GeneratedUserAccountCollection,
  UserAccountCreate,
  ImportJob as GeneratedImportJob,
  ImportJobCreate,
  ImportJobRowCollection as GeneratedImportJobRowCollection,
  ImportJobRow as GeneratedImportJobRow,
} from './api/generated/w1-2'

export type ProblemFieldError = {
  pointer: string
  code: string
  message?: string
}

export type ProblemDetails = {
  type: string
  title: string
  status: number
  detail?: string
  errors?: ProblemFieldError[]
}

export type Session = GeneratedSession

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
export type Cluster = GeneratedCluster
export type Facility = GeneratedFacility
export type FacilityCollection = GeneratedFacilityCollection
export type CreateClusterInput = ClusterCreate
export type CreateFacilityInput = FacilityCreate
export type OrganizationUnit = GeneratedOrganizationUnit
export type OrganizationUnitCollection = GeneratedOrganizationUnitCollection
export type CreateOrganizationUnitInput = OrganizationNodeCreate
export type Position = GeneratedPosition
export type PositionCollection = GeneratedPositionCollection
export type CreatePositionInput = PositionCreate
export type Person = GeneratedPerson
export type PersonCollection = GeneratedPersonCollection
export type CreatePersonInput = PersonCreate
export type Assignment = GeneratedAssignment
export type AssignmentCollection = GeneratedAssignmentCollection
export type CreateAssignmentInput = AssignmentCreate
export type UserAccount = GeneratedUserAccount
export type UserAccountCollection = GeneratedUserAccountCollection
export type CreateUserAccountInput = UserAccountCreate
export type UserAccountAction = 'activate' | 'unlock' | 'disable' | 'archive' | 'revoke-sessions' | 'force-password-change'
export type ImportJob = GeneratedImportJob
export type ImportJobRowCollection = GeneratedImportJobRowCollection
export type ImportJobRow = GeneratedImportJobRow
export type CreateImportJobInput = ImportJobCreate
export type ImportJobAction = 'validate' | 'approve' | 'reject' | 'apply' | 'cancel'

export class ApiError extends Error {
  readonly status: number
  readonly problem: ProblemDetails

  constructor(status: number, problem: ProblemDetails) {
    super(problem.detail ?? problem.title)
    this.name = 'ApiError'
    this.status = status
    this.problem = problem
  }
}

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const UTC_DATE_TIME_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/

function uuidV7(): string {
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  let timestamp = Date.now()

  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = timestamp & 0xff
    timestamp = Math.floor(timestamp / 256)
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function problemFrom(response: Response): Promise<ProblemDetails> {
  try {
    const body = await response.json() as Partial<ProblemDetails>
    if (typeof body.title === 'string' && typeof body.status === 'number') {
      const errors = Array.isArray(body.errors)
        ? body.errors.flatMap((error) => {
            if (
              typeof error !== 'object'
              || error === null
              || !('pointer' in error)
              || !('code' in error)
              || typeof error.pointer !== 'string'
              || typeof error.code !== 'string'
            ) {
              return []
            }

            return [{
              pointer: error.pointer,
              code: error.code,
              message: 'message' in error && typeof error.message === 'string' ? error.message : undefined,
            }]
          })
        : undefined

      return {
        type: typeof body.type === 'string' ? body.type : 'about:blank',
        title: body.title,
        status: body.status,
        detail: typeof body.detail === 'string' ? body.detail : undefined,
        errors,
      }
    }
  } catch {
    // A generic metadata-safe problem is returned below.
  }

  return {
    type: 'about:blank',
    title: 'Request failed',
    status: response.status,
  }
}

async function requestJsonResponse<T>(path: string, init: RequestInit, token?: string): Promise<{ body: T; response: Response }> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json, application/problem+json')
  if (!headers.has('X-Correlation-ID')) {
    headers.set('X-Correlation-ID', uuidV7())
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers,
  })

  if (!response.ok) {
    throw new ApiError(response.status, await problemFrom(response))
  }

  return { body: await response.json() as T, response }
}

async function requestJson<T>(path: string, init: RequestInit, token?: string): Promise<T> {
  return (await requestJsonResponse<T>(path, init, token)).body
}

export async function login(username: string, password: string): Promise<Session> {
  const body = await requestJson<{ data?: unknown }>('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  })

  const session = body.data
  if (
    typeof session !== 'object'
    || session === null
    || !('access_token' in session)
    || !('token_type' in session)
    || !('expires_at' in session)
    || !('facility' in session)
    || !('principal' in session)
    || typeof session.access_token !== 'string'
    || session.access_token === ''
    || session.token_type !== 'Bearer'
    || typeof session.expires_at !== 'string'
    || !UTC_DATE_TIME_PATTERN.test(session.expires_at)
    || (session.facility !== 'facility-a' && session.facility !== 'facility-b')
    || typeof session.principal !== 'object'
    || session.principal === null
    || !('user_id' in session.principal)
    || !('facility_id' in session.principal)
    || typeof session.principal.user_id !== 'string'
    || typeof session.principal.facility_id !== 'string'
    || !UUID_V7_PATTERN.test(session.principal.user_id)
    || !UUID_V7_PATTERN.test(session.principal.facility_id)
  ) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Invalid session response',
      status: 502,
    })
  }

  return session as Session
}

export async function createWorkRecord(token: string, input: CreateWorkRecordInput): Promise<WorkRecord> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: WorkRecord }>('/api/v1/work-records', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `request-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)

  return body.data
}

export function listWorkRecords(token: string): Promise<WorkRecordCollection> {
  return requestJson<WorkRecordCollection>('/api/v1/work-records?limit=20', { method: 'GET' }, token)
}

export async function getWorkRecord(token: string, recordId: string): Promise<WorkRecord> {
  const body = await requestJson<{ data: WorkRecord }>(`/api/v1/work-records/${encodeURIComponent(recordId)}`, {
    method: 'GET',
  }, token)

  return body.data
}

export function listNotifications(token: string): Promise<NotificationCollection> {
  return requestJson<NotificationCollection>('/api/v1/notifications?limit=20', { method: 'GET' }, token)
}

export async function getCluster(token: string): Promise<Cluster> {
  const body = await requestJson<{ data: Cluster }>('/api/v1/organization/cluster', { method: 'GET' }, token)
  return body.data
}

export async function createCluster(token: string, input: CreateClusterInput): Promise<Cluster> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: Cluster }>('/api/v1/organization/cluster', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `cluster-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listFacilities(token: string): Promise<FacilityCollection> {
  return requestJson<FacilityCollection>('/api/v1/organization/facilities?limit=100', { method: 'GET' }, token)
}

export async function createFacility(token: string, input: CreateFacilityInput): Promise<Facility> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: Facility }>('/api/v1/organization/facilities', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `facility-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listOrganizationUnits(token: string): Promise<OrganizationUnitCollection> {
  return requestJson<OrganizationUnitCollection>('/api/v1/organization/units?limit=100', { method: 'GET' }, token)
}

export async function createOrganizationUnit(token: string, input: CreateOrganizationUnitInput): Promise<OrganizationUnit> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: OrganizationUnit }>('/api/v1/organization/units', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `organization-unit-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listPositions(token: string): Promise<PositionCollection> {
  return requestJson<PositionCollection>('/api/v1/organization/positions?limit=100', { method: 'GET' }, token)
}

export async function createPosition(token: string, input: CreatePositionInput): Promise<Position> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: Position }>('/api/v1/organization/positions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `position-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listPeople(token: string): Promise<PersonCollection> {
  return requestJson<PersonCollection>('/api/v1/organization/people?limit=100', { method: 'GET' }, token)
}

export async function createPerson(token: string, input: CreatePersonInput): Promise<Person> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: Person }>('/api/v1/organization/people', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `person-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listAssignments(token: string): Promise<AssignmentCollection> {
  return requestJson<AssignmentCollection>('/api/v1/organization/assignments?limit=100', { method: 'GET' }, token)
}

export async function createAssignment(token: string, input: CreateAssignmentInput): Promise<Assignment> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: Assignment }>('/api/v1/organization/assignments', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `assignment-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export function listUserAccounts(token: string): Promise<UserAccountCollection> {
  return requestJson<UserAccountCollection>('/api/v1/identity/accounts?limit=100', { method: 'GET' }, token)
}

export async function createUserAccount(token: string, input: CreateUserAccountInput): Promise<UserAccount> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: UserAccount }>('/api/v1/identity/accounts', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `identity-account-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export async function transitionUserAccount(token: string, accountId: string, action: UserAccountAction, reason?: string): Promise<UserAccount> {
  const detail = await requestJsonResponse<{ data: UserAccount }>(`/api/v1/identity/accounts/${encodeURIComponent(accountId)}`, { method: 'GET' }, token)
  const etag = detail.response.headers.get('ETag')
  if (!etag) {
    throw new ApiError(502, { type: 'about:blank', title: 'Missing account version', status: 502 })
  }
  const correlationId = uuidV7()
  const body = await requestJson<{ data: UserAccount }>(`/api/v1/identity/accounts/${encodeURIComponent(accountId)}/${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `identity-${action}-${correlationId}`,
      'If-Match': etag,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(reason ? { reason } : {}),
  }, token)
  return body.data
}

export async function submitImportJob(token: string, input: CreateImportJobInput): Promise<ImportJob> {
  const correlationId = uuidV7()
  const body = await requestJson<{ data: ImportJob }>('/api/v1/organization/import-jobs', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `import-submit-${correlationId}`,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(input),
  }, token)
  return body.data
}

export async function getImportJob(token: string, jobId: string): Promise<ImportJob> {
  const body = await requestJson<{ data: ImportJob }>(`/api/v1/organization/import-jobs/${encodeURIComponent(jobId)}`, { method: 'GET' }, token)
  return body.data
}

export function listImportJobRows(token: string, jobId: string): Promise<ImportJobRowCollection> {
  return requestJson<ImportJobRowCollection>(`/api/v1/organization/import-jobs/${encodeURIComponent(jobId)}/rows?limit=100`, { method: 'GET' }, token)
}

export async function transitionImportJob(token: string, jobId: string, action: ImportJobAction, reason?: string): Promise<ImportJob> {
  const detail = await requestJsonResponse<{ data: ImportJob }>(`/api/v1/organization/import-jobs/${encodeURIComponent(jobId)}`, { method: 'GET' }, token)
  const etag = detail.response.headers.get('ETag')
  if (!etag) {
    throw new ApiError(502, { type: 'about:blank', title: 'Missing import version', status: 502 })
  }
  const correlationId = uuidV7()
  const body = await requestJson<{ data: ImportJob }>(`/api/v1/organization/import-jobs/${encodeURIComponent(jobId)}/${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': `import-${action}-${correlationId}`,
      'If-Match': etag,
      'X-Correlation-ID': correlationId,
    },
    body: JSON.stringify(reason ? { reason } : {}),
  }, token)
  return body.data
}
