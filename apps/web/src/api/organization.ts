import * as generated from './generated/cluster'
import type {
  Assignment as GeneratedAssignment,
  AssignmentCollection as GeneratedAssignmentCollection,
  AssignmentCreate,
  Cluster as GeneratedCluster,
  ClusterCreate,
  ClusterPatch,
  Facility as GeneratedFacility,
  FacilityCollection as GeneratedFacilityCollection,
  FacilityCreate,
  FacilityPatch,
  ImportJob as GeneratedImportJob,
  ImportJobCreate,
  ImportFileUpload as GeneratedImportFileUpload,
  ImportFileReference as GeneratedImportFileReference,
  ImportJobRow as GeneratedImportJobRow,
  ImportJobRowCollection as GeneratedImportJobRowCollection,
  JobTitle as GeneratedJobTitle,
  JobTitleCollection as GeneratedJobTitleCollection,
  JobTitleCreate,
  OrganizationNodeCreate,
  OrganizationNodePatch,
  OrganizationUnit as GeneratedOrganizationUnit,
  OrganizationUnitCollection as GeneratedOrganizationUnitCollection,
  Person as GeneratedPerson,
  PersonCollection as GeneratedPersonCollection,
  PersonCreate,
  PersonPatch,
  Position as GeneratedPosition,
  PositionCollection as GeneratedPositionCollection,
  PositionCreate,
  PositionPatch,
  TemporaryAssignment as GeneratedTemporaryAssignment,
  TemporaryAssignmentCollection as GeneratedTemporaryAssignmentCollection,
  TemporaryAssignmentCreate,
  EffectiveEnd,
} from './generated/cluster'
import { ApiError, requestInit, unwrap, unwrapWithEtag } from './http'

export type Cluster = GeneratedCluster
export type CreateClusterInput = ClusterCreate
export type UpdateClusterInput = ClusterPatch
export type Facility = GeneratedFacility
export type FacilityCollection = GeneratedFacilityCollection
export type CreateFacilityInput = FacilityCreate
export type UpdateFacilityInput = FacilityPatch
export type OrganizationUnit = GeneratedOrganizationUnit
export type OrganizationUnitCollection = GeneratedOrganizationUnitCollection
export type CreateOrganizationUnitInput = OrganizationNodeCreate
export type UpdateOrganizationUnitInput = OrganizationNodePatch
export type Position = GeneratedPosition
export type PositionCollection = GeneratedPositionCollection
export type CreatePositionInput = PositionCreate
export type UpdatePositionInput = PositionPatch
export type JobTitle = GeneratedJobTitle
export type JobTitleCollection = GeneratedJobTitleCollection
export type CreateJobTitleInput = JobTitleCreate
export type Person = GeneratedPerson
export type PersonCollection = GeneratedPersonCollection
export type CreatePersonInput = PersonCreate
export type UpdatePersonInput = PersonPatch
export type Assignment = GeneratedAssignment
export type AssignmentCollection = GeneratedAssignmentCollection
export type CreateAssignmentInput = AssignmentCreate
export type EndAssignmentInput = EffectiveEnd
export type ImportJob = GeneratedImportJob
export type ImportJobRow = GeneratedImportJobRow
export type ImportJobRowCollection = GeneratedImportJobRowCollection
export type CreateImportJobInput = ImportJobCreate
export type ImportJobAction = 'validate' | 'approve' | 'reject' | 'apply' | 'cancel'
export type ImportFileUploadInput = Omit<GeneratedImportFileUpload, 'file'> & { file: File }
export type ImportFileReference = GeneratedImportFileReference
export type TemporaryAssignment = GeneratedTemporaryAssignment
export type TemporaryAssignmentCollection = GeneratedTemporaryAssignmentCollection
export type CreateTemporaryAssignmentInput = TemporaryAssignmentCreate

/** Page size used by list screens until cursor paging is wired through the UI. */
const PAGE_LIMIT = 100

export async function getCluster(token: string): Promise<Cluster> {
  return unwrap<Cluster>(await generated.getCluster(requestInit(token)))
}

export async function createCluster(token: string, input: CreateClusterInput): Promise<Cluster> {
  return unwrap<Cluster>(
    await generated.createCluster(input, requestInit(token, { command: true, idempotency: 'cluster' })),
  )
}

export async function updateCluster(
  token: string,
  lockVersion: number,
  input: UpdateClusterInput,
): Promise<Cluster> {
  return unwrap<Cluster>(
    await generated.updateCluster(
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function listFacilities(token: string): Promise<FacilityCollection> {
  return unwrap<FacilityCollection>(
    await generated.listFacilities({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createFacility(
  token: string,
  input: CreateFacilityInput,
): Promise<Facility> {
  return unwrap<Facility>(
    await generated.createFacility(input, requestInit(token, { command: true, idempotency: 'facility' })),
  )
}

export async function updateFacility(
  token: string,
  facilityId: string,
  lockVersion: number,
  input: UpdateFacilityInput,
): Promise<Facility> {
  return unwrap<Facility>(
    await generated.updateFacility(
      facilityId,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function listOrganizationUnits(token: string): Promise<OrganizationUnitCollection> {
  return unwrap<OrganizationUnitCollection>(
    await generated.listOrganizationUnits({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createOrganizationUnit(
  token: string,
  input: CreateOrganizationUnitInput,
): Promise<OrganizationUnit> {
  return unwrap<OrganizationUnit>(
    await generated.createOrganizationUnit(input, requestInit(token, { command: true, idempotency: 'organization-unit' })),
  )
}

export async function updateOrganizationUnit(
  token: string,
  unitId: string,
  lockVersion: number,
  input: UpdateOrganizationUnitInput,
): Promise<OrganizationUnit> {
  return unwrap<OrganizationUnit>(
    await generated.updateOrganizationUnit(
      unitId,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

/**
 * Reorders every cached unit by the server's sibling policy. The endpoint derives the
 * ordering itself and ignores the submitted list, so no explicit ordering is sent.
 * The cluster `lock_version` (from `getCluster`) is the optimistic-concurrency
 * token the server requires in `If-Match`.
 */
export async function reorderOrganizationUnits(
  token: string,
  lockVersion: number,
): Promise<{ updated: number; policy: string }> {
  const body = unwrap<{ updated: number; by_parent: string[]; policy: string }>(
    await generated.reorderOrganizationUnits(
      { ordered_unit_ids: [] },
      requestInit(token, { command: true, idempotency: 'organization-units-reorder', lockVersion }),
    ),
  )
  return { updated: body.updated, policy: body.policy }
}

export async function listPositions(token: string): Promise<PositionCollection> {
  return unwrap<PositionCollection>(
    await generated.listPositions({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createPosition(
  token: string,
  input: CreatePositionInput,
): Promise<Position> {
  return unwrap<Position>(
    await generated.createPosition(input, requestInit(token, { command: true, idempotency: 'position' })),
  )
}

export async function updatePosition(
  token: string,
  positionId: string,
  lockVersion: number,
  input: UpdatePositionInput,
): Promise<Position> {
  return unwrap<Position>(
    await generated.updatePosition(
      positionId,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function listJobTitles(token: string): Promise<JobTitleCollection> {
  return unwrap<JobTitleCollection>(
    await generated.listJobTitles({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createJobTitle(
  token: string,
  input: CreateJobTitleInput,
): Promise<JobTitle> {
  return unwrap<JobTitle>(
    await generated.createJobTitle(input, requestInit(token, { command: true, idempotency: 'job-title' })),
  )
}

export async function listPeople(token: string): Promise<PersonCollection> {
  return unwrap<PersonCollection>(
    await generated.listPeople({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createPerson(token: string, input: CreatePersonInput): Promise<Person> {
  return unwrap<Person>(
    await generated.registerPerson(input, requestInit(token, { command: true, idempotency: 'person' })),
  )
}

export async function updatePerson(
  token: string,
  personId: string,
  personVersion: number,
  input: UpdatePersonInput,
): Promise<Person> {
  return unwrap<Person>(
    await generated.updatePerson(
      personId,
      input,
      requestInit(token, { command: true, lockVersion: personVersion }),
    ),
  )
}

export async function listAssignments(token: string): Promise<AssignmentCollection> {
  return unwrap<AssignmentCollection>(
    await generated.listAssignments({ limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function createAssignment(
  token: string,
  input: CreateAssignmentInput,
): Promise<Assignment> {
  return unwrap<Assignment>(
    await generated.createAssignment(input, requestInit(token, { command: true, idempotency: 'assignment' })),
  )
}

export async function endAssignment(
  token: string,
  assignmentId: string,
  lockVersion: number,
  input: EndAssignmentInput,
): Promise<Assignment> {
  return unwrap<Assignment>(
    await generated.endAssignment(
      assignmentId,
      input,
      requestInit(token, { command: true, lockVersion }),
    ),
  )
}

export async function uploadImportFile(
  csrfToken: string,
  input: ImportFileUploadInput,
): Promise<ImportFileReference> {
  return unwrap<ImportFileReference>(
    await generated.uploadOrganizationImportFile(
      { file: input.file, template_code: input.template_code, import_type: input.import_type },
      requestInit(csrfToken, { command: true, idempotency: 'import-file' }),
    ),
  )
}

export async function submitImportJob(
  token: string,
  input: CreateImportJobInput,
): Promise<ImportJob> {
  return unwrap<ImportJob>(
    await generated.submitOrganizationImport(input, requestInit(token, { command: true, idempotency: 'import-submit' })),
  )
}

export async function getImportJob(token: string, jobId: string): Promise<ImportJob> {
  return unwrap<ImportJob>(await generated.getOrganizationImport(jobId, requestInit(token)))
}

export async function listImportJobRows(
  token: string,
  jobId: string,
): Promise<ImportJobRowCollection> {
  return unwrap<ImportJobRowCollection>(
    await generated.listOrganizationImportRows(jobId, { limit: PAGE_LIMIT }, requestInit(token)),
  )
}

export async function transitionImportJob(
  token: string,
  jobId: string,
  action: ImportJobAction,
  reason?: string,
): Promise<ImportJob> {
  const { etag } = unwrapWithEtag<ImportJob>(
    await generated.getOrganizationImport(jobId, requestInit(token)),
  )
  if (!etag) {
    throw new ApiError(502, {
      type: 'about:blank',
      title: 'Missing import version',
      status: 502,
    })
  }

  return unwrap<ImportJob>(
    await generated.transitionOrganizationImport(
      jobId,
      action,
      reason ? { reason } : {},
      requestInit(token, { command: true, ifMatch: etag }),
    ),
  )
}

export async function listTemporaryAssignments(
  organizationUnitId: string,
  limit = PAGE_LIMIT,
): Promise<TemporaryAssignmentCollection> {
  return unwrap<TemporaryAssignmentCollection>(
    await generated.listTemporaryAssignments(
      { organization_unit_id: organizationUnitId, limit },
      requestInit(),
    ),
  )
}

export async function createTemporaryAssignment(
  csrfToken: string,
  input: CreateTemporaryAssignmentInput,
): Promise<TemporaryAssignment> {
  return unwrap<TemporaryAssignment>(
    await generated.createTemporaryAssignment(input, requestInit(csrfToken, { command: true, idempotency: 'temporary-assignment' })),
  )
}

export async function getTemporaryAssignment(
  temporaryAssignmentId: string,
): Promise<TemporaryAssignment> {
  return unwrap<TemporaryAssignment>(
    await generated.getTemporaryAssignment(temporaryAssignmentId, requestInit()),
  )
}

export async function revokeTemporaryAssignment(
  csrfToken: string,
  temporaryAssignmentId: string,
  ifMatch: string,
  reason: string,
): Promise<TemporaryAssignment> {
  return unwrap<TemporaryAssignment>(
    await generated.revokeTemporaryAssignment(
      temporaryAssignmentId,
      { reason },
      requestInit(csrfToken, { command: true, ifMatch }),
    ),
  )
}
