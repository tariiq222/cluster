---
doc_id: DOM-ORG-001
title: Organization and People
type: domain
status: accepted
version: 1.2.0
date: 2026-07-18
owner: Organization Module Owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/architecture/dependency-rules.md
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Organization and People

## 1. Purpose

This domain represents the administrative reality of the organization: the cluster, its facilities and organizational units, its positions and supervisory relationships, and its human account holders. It does not know request, project, or task; it provides the stable identifiers and organizational facts that an owner needs to build `AuthorizationRecordFacts`, to assign responsibility, or to attach a record to an organizational side. The access decision itself remains exclusively in Authorization.

## 2. Scope

- Representing the cluster as an operating entity with internal administrations, per the mandatory sequence `Cluster > Facility > Unit`.
- Representing facilities of various types (hospital, health center, specialized center, central lab, shared services, a new type defined by super admin) as children of the cluster.
- Representing administrative units, sectors, and departments within the cluster or facility; an organizational unit does not exist outside this sequence.
- Representing positions and their relationship to units.
- Representing temporary assignments and memberships in teams or committees.
- Representing supervisory relationships of various types and their capabilities.
- Importing the structure from CSV/XLSX in a governed, reviewable way.
- Reference calendar for the system: Asia/Riyadh, with all timestamps stored as UTC in the database.

What is out of scope for this domain:

- Accounts and passwords.
- Sessions and the login lifecycle.
- Roles and permissions as access policies (they live in Authorization).
- Any work record such as request, project, or indicator.

## 3. Terminology

| Term | Definition |
|---|---|
| Cluster | The sole root entity of the system, representing a single health cluster. |
| Facility | An entity that belongs directly to the cluster, with a governed facility type. |
| Unit | An organizational entity belonging to the cluster or to a facility within the sequence `Cluster > Facility > Unit`; it may be a sector, administration, department, or unit. |
| Unit Type | A governed classification that distinguishes Cluster, Facility, Sector, Department, Section, and Unit. |
| Position | An official role inside a unit, occupiable by one or more persons. |
| Assignment | A link of a person to a position in a unit with a start and end date. |
| Supervisory Relationship | A link between two units or two persons, with a type, capabilities, and a temporal range. |
| Relationship Type | Direct supervision, functional supervision, coordination, view-only. |
| Granted Capability | An access capability that a specific relationship grants to a set of modules. |

## 4. Aggregates, Entities, and Value Objects

### 4.1 ClusterAggregate

- `Cluster` (root Entity).
- `ClusterProfile` (Value Object: name, code, settings, reference calendar Asia/Riyadh).

### 4.2 FacilityAggregate

- `Facility` (root Entity).
- `FacilityType` (Value Object identified by a governed facility type).
- `FacilityProfile` (Value Object: name, code, local settings).

### 4.3 OrganizationUnitAggregate

- `OrganizationUnit` (root Entity).
- `UnitType` (Value Object: cluster, facility, sector, administration, department, unit).
- `UnitPath` (Value Object: the path string computed across parents to evaluate scope efficiently).
- `UnitStatus` (Value Object: Active, Inactive, Archived).

### 4.4 PositionAggregate

- `Position` (root Entity).
- `PositionTitle` (Value Object).
- `PositionLevel` (optional Value Object for administrative ordering).

### 4.5 PersonAggregate

- `Person` (root Entity, separate from the account).
- `PersonProfile` (Value Object: name, mobile, email, display data).
- `PersonStatus` (Value Object: Active, Suspended, Left).

### 4.6 AssignmentAggregate

- `Assignment` (root Entity): linking a Person to a Position in an OrganizationUnit with a temporal range.
- `AssignmentRole` (optional Value Object to specify the nature of the assignment: primary, alternate, temporary).
- `AssignmentPeriod` (Value Object: start_at, end_at).
- When `end_at` elapses manually or automatically, the assignment loses its effect immediately without deleting the record.

### 4.7 SupervisoryRelationshipAggregate

- `SupervisoryRelationship` (root Entity).
- `RelationshipType` (Value Object: direct, functional, coordination, view_only).
- `RelationshipScope` (Value Object: list of included modules).
- `RelationshipCapability` (Value Object: the granted capability).
- `RelationshipPeriod` (Value Object: start_at, end_at).
- When `end_at` elapses, the relationship and its capabilities are automatically withdrawn.

### 4.8 ImportJobAggregate (for governed import)

- `ImportJob` (root Entity).
- `ImportTemplate` (Value Object: template name, expected columns, validation rules).
- `ImportRow` (child Entity): raw row + parsing result + errors.
- `ImportDiff` (Value Object: comparison between current and proposed values).
- `ImportDecision` (Value Object per row: Create, Update, Skip, Fail).

## 5. Tables, Constraints, and Indexes

### 5.1 `clusters`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `code` VARCHAR(64) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `created_at`, `updated_at` DATETIME.
- Index: `(code)`.

Language, locale, and timezone are general settings owned by `PlatformSettings` and are not stored by Organization.

### 5.2 `facilities`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `cluster_id` CHAR(36) UUID NOT NULL FK -> `clusters.id`.
- `facility_type_id` CHAR(36) UUID NOT NULL FK -> `facility_types.id`.
- `code` VARCHAR(64) NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `created_at`, `updated_at` DATETIME.
- Unique constraint: `(cluster_id, code)`.
- Indexes: `(cluster_id, status)`, `(facility_type_id)`.

### 5.3 `facility_types`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `code` VARCHAR(64) UNIQUE NOT NULL (example: `hospital`, `center`, `lab`, `shared_services`).
- `name_ar` VARCHAR(255) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.

### 5.4 `organization_units`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `cluster_id` CHAR(36) UUID NOT NULL FK.
- `parent_id` CHAR(36) UUID NOT NULL; refers to a Cluster, Facility, or OrganizationUnit inside the module per `parent_type`.
- `parent_type` VARCHAR(16) NOT NULL: `cluster|facility|unit`.
- `unit_type_id` CHAR(36) UUID NOT NULL FK -> `unit_types.id`.
- `code` VARCHAR(64) NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `path_cache` VARCHAR(512) NOT NULL (precomputed path for fast scope lookup).
- `depth` SMALLINT NOT NULL.
- `lock_version` INT UNSIGNED NOT NULL DEFAULT 1.
- `created_at`, `updated_at` DATETIME.
- Unique constraint: `(parent_type, parent_id, code)`.
- Indexes: `(cluster_id, status)`, `(parent_id)`, `(unit_type_id)`, `(path_cache)` as a prefix index.

### 5.5 `unit_types`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `code` VARCHAR(64) UNIQUE NOT NULL (example: `cluster`, `facility`, `sector`, `department`, `section`, `unit`).
- `name_ar` VARCHAR(255) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.

### 5.6 `positions`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `organization_unit_id` CHAR(36) UUID NOT NULL FK.
- `code` VARCHAR(64) NOT NULL.
- `title_ar` VARCHAR(255) NOT NULL.
- `manager_position_id` CHAR(36) UUID NULL FK -> `positions.id`, with no administrative cycle allowed.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- `lock_version` INT UNSIGNED NOT NULL DEFAULT 1.
- Unique constraint: `(organization_unit_id, code)`.
- Index: `(organization_unit_id, is_active)`.

### 5.7 `people`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `national_id_ciphertext` VARBINARY NULL, encrypted at the column level.
- `national_id_lookup_hash` CHAR(64) NULL UNIQUE, HMAC for lookup and to prevent duplicates without revealing the value.
- `employee_number` VARCHAR(64) NOT NULL UNIQUE.
- `display_name_ar` VARCHAR(255) NOT NULL.
- `display_name_en` VARCHAR(255) NULL.
- `primary_email_ciphertext` VARBINARY NULL.
- `primary_phone_ciphertext` VARBINARY NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `person_version` BIGINT NOT NULL DEFAULT 1.
- `created_at`, `updated_at` DATETIME.
- Indexes: `(status)`, `(national_id_lookup_hash)`, `(employee_number)`.

### 5.8 `assignments`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `person_id` CHAR(36) UUIDv7 NOT NULL FK.
- `position_id` CHAR(36) UUID NOT NULL FK.
- `is_primary` BOOLEAN NOT NULL DEFAULT TRUE; detailed temporary assignments remain outside this slice.
- `start_at` DATETIME(3) NOT NULL (UTC is configured at the application layer via `app.timezone`; the migration itself does not enforce UTC at the DB level).
- `end_at` DATETIME(3) NULL.
- `end_reason` TEXT NULL.
- `ended_by_user_id` CHAR(36) UUID NULL, actor identifier without a cross-module FK to Identity.
- `lock_version` INT NOT NULL DEFAULT 1.
- `created_at`, `updated_at` DATETIME.
- Constraint: `start_at < end_at` and `end_at` must be in the future at creation, or `end_at IS NULL` (enforced at the domain layer; the migration does not declare a DB-level `start_at < end_at` check).
- Indexes: `(person_id, is_primary, start_at, end_at)`, `(position_id, start_at, end_at)`.

### 5.9 `supervisory_relationships`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `source_organization_unit_id` CHAR(36) UUID NULL FK (the source side at the unit level).
- `target_organization_unit_id` CHAR(36) UUID NULL FK.
- `relationship_type` VARCHAR(32) NOT NULL.
- `valid_from` DATE NOT NULL.
- `valid_until` DATE NULL.
- `created_at` DATETIME NOT NULL.
- Constraint: both `source_organization_unit_id` and `target_organization_unit_id` must be non-null (the relationship is unit-to-unit only).
- Indexes: `(source_organization_unit_id, valid_from, valid_until)`, `(target_organization_unit_id, valid_from, valid_until)`, `(relationship_type)`.

### 5.10 `relationship_capabilities`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `supervisory_relationship_id` CHAR(36) UUID NOT NULL FK -> `supervisory_relationships.id` ON DELETE CASCADE.
- `module_code` VARCHAR(64) NOT NULL (example: `work-records`, `strategy`, `portfolio-projects`).
- `capability_code` VARCHAR(64) NOT NULL (example: `view_aggregate`, `view_details`, `assign_task`, `participate_approval`).
- `field_policy_key` VARCHAR(128) NULL (reference to a field policy template).
- Unique constraint: `(supervisory_relationship_id, module_code, capability_code)`.

### 5.11 `import_jobs`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `template_code` VARCHAR(64) NOT NULL (`facilities`, `organization_units`, `positions`, `people_assignments`).
- `source_filename` VARCHAR(255) NOT NULL.
- `source_format` VARCHAR(8) NOT NULL (`csv`, `xlsx`).
- `status` VARCHAR(32) NOT NULL (`received`, `validated`, `approved`, `applied`, `failed`, `rejected`, `cancelled`).
- `quarantine_object_id` CHAR(36) NOT NULL, reference to an encrypted raw file held in quarantine without a cross-module FK.
- `submitted_by_user_id` CHAR(36) UUID NOT NULL, actor identifier without a cross-module FK.
- `approved_by_user_id` CHAR(36) UUID NULL, actor identifier without a cross-module FK.
- `total_rows` INT NOT NULL DEFAULT 0.
- `valid_rows` INT NOT NULL DEFAULT 0.
- `error_rows` INT NOT NULL DEFAULT 0.
- `applied_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL.
- Indexes: `(status)`, `(template_code)`, `(submitted_by_user_id)`.

### 5.12 `import_rows`

- `id` CHAR(36) UUID PK (UUIDv7 is not enforced at the migration level).
- `import_job_id` CHAR(36) UUID NOT NULL FK -> `import_jobs.id` ON DELETE CASCADE.
- `row_number` INT NOT NULL.
- `encrypted_payload` JSON NOT NULL; sensitive row fields are encrypted and never appear in errors or logs.
- `proposed_action` VARCHAR(16) NULL (`create`, `update`, `skip`).
- `decision` VARCHAR(16) NULL (`accepted`, `rejected`).
- `applied_at` DATETIME NULL.
- Index: `(import_job_id, row_number)`.

## 6. Commands, Queries, and Events

### 6.1 Commands

- `CreateCluster`
- `UpdateClusterProfile`
- `CreateFacility`
- `UpdateFacilityProfile`
- `ArchiveFacility`
- `CreateOrganizationUnit`
- `MoveOrganizationUnit`
- `UpdateOrganizationUnitProfile`
- `CreatePosition`
- `UpdatePosition`
- `RegisterPerson`
- `UpdatePersonProfile`
- `CreateAssignment`
- `EndAssignment`
- `CreateSupervisoryRelationship`
- `GrantRelationshipCapability`
- `RevokeRelationshipCapability`
- `EndSupervisoryRelationship`
- `SubmitImportJob`
- `ValidateImportJob`
- `ApproveImportJob`
- `RejectImportJob`
- `ApplyImportJob`
- `CancelImportJob`

### 6.2 Queries

- `GetCluster`
- `GetOrganizationUnit`
- `ListFacilityOrganizationUnits`
- `ListOrganizationUnitChildren`
- `ResolveUnitPath`
- `GetPosition`
- `ListPositionAssignments`
- `ResolveDirectManager` (returns the current Person for a given manager position within a time range).
- `GetActiveAssignmentsForPerson` (uses Asia/Riyadh to determine "today").
- `GetActiveSupervisoryRelationships` (used by Authorization and Workflow).
- `GetRelationshipCapabilities` (used by Authorization).
- `ExplainOrganizationalScope` (reads the user's scope efficiently).
- `GetImportJob`
- `ListImportJobRows`

### 6.3 Domain Events

- `ClusterCreated`
- `ClusterUpdated`
- `FacilityCreated`
- `FacilityUpdated`
- `FacilityArchived`
- `OrganizationUnitCreated`
- `OrganizationUnitMoved`
- `OrganizationUnitUpdated`
- `OrganizationUnitArchived`
- `PositionCreated`
- `PositionUpdated`
- `PersonRegistered`
- `PersonUpdated`
- `AssignmentStarted`
- `AssignmentEnded`
- `SupervisoryRelationshipActivated`
- `SupervisoryRelationshipCapabilityGranted`
- `SupervisoryRelationshipCapabilityRevoked`
- `SupervisoryRelationshipExpired`
- `ImportJobSubmitted`
- `ImportJobValidated`
- `ImportJobApproved`
- `ImportJobRejected`
- `ImportJobApplied`
- `ImportJobCancelled`
- `ImportJobFailed`
- `IdentityProvisioningRequested` after a Person has actually been applied; carries `person_id` and `person_version` without PII.
- `PersonAccessStatusChanged` when Active/Suspended/Left changes, carrying the same version.

## 7. State Machines

### 7.1 OrganizationUnit

- `Active` --(archive)--> `Inactive` --(restore)--> `Active`.
- `Inactive` --(archive permanent)--> `Archived` (terminal, retained read-only).

### 7.2 Assignment

- `Pending` (future start date) --(reaches start_at)--> `Active`.
- `Active` --(end_at reached or EndAssignment)--> `Ended`.
- An ended assignment cannot be reactivated; a new assignment is created.

### 7.3 SupervisoryRelationship

- `Pending` --(start_at reached)--> `Active`.
- `Active` --(end_at reached or EndSupervisoryRelationship)--> `Ended`.
- `Ended` is terminal; modifications create a new relationship.

### 7.4 ImportJob

- `Received` --(validate)--> `Validated`.
- `Validated` --(approve)--> `Approved`.
- `Validated` --(reject)--> `Rejected`.
- `Approved` --(apply)--> `Applied`.
- `Approved` --(cancel)--> `Cancelled`.
- `Received`/`Validated` --(system validation failure)--> `Failed`.

## 8. Invariants

- For every non-root `OrganizationUnit`, `parent_id` is not NULL, and the only root is the `Cluster`; a `Facility` is a child of the cluster and administrative units sit inside the cluster or facility per `Cluster > Facility > Unit`.
- A Facility cannot be created under another Facility, and a Unit cannot exist outside a Cluster or Facility unless the governed unit type explicitly allows it.
- A unit cannot be moved into its own descendant (cycles are prevented).
- `assignment.start_at < assignment.end_at` if `end_at` exists, and the API does not create a historically ended assignment without an end and audit path.
- Two occupants cannot overlap on the same Position, and two primary assignments cannot overlap for the same person; parallel non-primary assignments on another Position are allowed.
- A supervisory relationship must have both sides specified, and creating a relationship from a person to another person is not allowed unless the type's policy permits it.
- A supervisory relationship does not grant implicit capabilities outside the defined `relationship_capabilities`.
- CSV/XLSX import does not apply directly: it passes through Received -> Validated -> Approved -> Applied.
- Import rows containing Critical errors are not applied; a re-upload is required.
- Every import requires `approved_by_user_id` to differ from `submitted_by_user_id` (dual-approval principle).
- Actor identifiers are audit facts from the authentication context and are not FKs or ORM relations to Identity.
- Every Person has a monotonically increasing `person_version` that accompanies provisioning and access-status events.
- Reference calendar is Asia/Riyadh, but `created_at`/`updated_at` timestamps are stored as UTC.

## 9. Domain Transactions and Decision Ownership

- Every Command is driven by the owning Aggregate inside Organization; the Slice's Handler owns the Transaction and commits or rolls back.
- Creating or moving a Cluster, Facility, or Unit persists the tree and `path_cache` and events in a single Transaction.
- CSV/XLSX import owns its own ImportJob Transaction and does not write structure rows during upload or validation; application happens only after Approved.
- Applying an import persists Organization changes and the `IdentityProvisioningRequested` event in the Outbox inside the same transaction, and does not write Identity tables.
- Organization does not use a general Transaction to coordinate Identity, Authorization, or WorkRecords tables.
- Important events and Outbox writes are saved inside the owning Transaction; indexing or notification happens after Commit.
- Organization provides scope and relationship facts to Authorization, but it does not issue an Allow or Deny decision on a work record.

## 10. Permissions

- Only super admin creates a `Cluster` and edits its general settings.
- Only super admin creates, edits, and archives `Facility` and `OrganizationUnit`.
- Only super admin creates a `SupervisoryRelationship` and grants its capabilities.
- Only super admin approves an `ImportJob`.
- Only super admin creates a `Position`.
- Only super admin creates an `Assignment` and ends it.
- An employee cannot modify their own organizational side, position, manager, or permissions.
- Super admin can read the audit trail of any sensitive operation; every sensitive operation is recorded with the actor's name, Asia/Riyadh time, and an optional reason.
- Organization provides Authorization with the `ResolveOrganizationScope` and `GetActiveSupervisoryRelationships` contracts, plus the organizational facts needed to build `AuthorizationRecordFacts`.
- Organization does not decide access for a work record and does not read payloads; the Allow/Deny decision and FieldAccess are centralized in Authorization.

## 11. Failure

- CSV/XLSX import with missing required fields: the row is flagged Critical and not applied; a re-upload is allowed.
- Import with values that do not match governed types (such as unknown `facility_type_code`): the row is rejected and does not affect the rest.
- Attempt to move a unit into its descendant: the operation is rejected with an interpretable message.
- Attempt to save an assignment whose end date precedes its start date: rejected at the domain layer.
- A supervisory relationship expiring before any access decision: the decision loses the relationship's capabilities, and the system records the event in the Outbox.
- Outbox event creation failure: the transaction is rolled back and the failure is recorded in the review errors list.
- Any failure to store UTC prevents any Asia/Riyadh conversion at the presentation layer: enforced via a CI tool that verifies timestamps are not mutated in the Read layer.

## 12. Tests and Acceptance

### 12.1 Acceptance Criteria

- Super admin creates exactly one cluster in the system.
- Super admin creates a new facility, specifies its type, and it acquires a unit tree.
- Super admin moves a unit from one administration to another, and `path_cache` changes.
- Super admin creates a functional-supervision relationship between two units with a time curve and defined capabilities.
- Super admin uploads a CSV/XLSX file, errors appear before approval, super admin approves, and the changes are applied.
- An assignment ending on a specific date automatically loses its effect.
- A supervisory relationship ending automatically loses its capabilities.
- All timestamps are presented as Asia/Riyadh for the UI and UTC in the database.

### 12.2 Tests

- Architecture test: prevents the business Namespace from importing Infrastructure from Organization.
- Unit test: `path_cache` rules on move, and cycle prevention.
- Use-case test: creating a new facility is reflected in the unit tree.
- Use-case test: CSV import containing Critical errors -> Failed state and no application.
- Use-case test: a successful import creates the correct number of rows.
- Authorization test: an employee in one facility cannot read assignments of another facility.
- Cross-module contract test: `ResolveDirectManager` returns the correct result per Asia/Riyadh.
- UTC test: no Asia/Riyadh conversion happens in the Persistence layer.
- Integration test: an ended supervisory relationship does not appear in `GetActiveSupervisoryRelationships`.

## 13. Dependencies

- Depends on: Shared/Clock (Asia/Riyadh), Shared/Identifiers.
- Receives actor identifiers from the authentication context as audit only, without a synchronous dependency or FK to Identity.
- Does not depend on Authorization, WorkDefinitions, WorkRecords, Workflow, or Documents.
- Depended on by: Identity (to link a Person to an account), Authorization (to resolve scope and relationships), WorkDefinitions (to reference `owner_organization_unit_id`), WorkRecords (same reference), Workflow (to resolve approvers), Documents (same reference), Strategy (to reference the owning side of an indicator), PortfolioProjects (same reference), Risk (same reference), Collaboration (to reference the owning side of a task).

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.2.0 | 2026-07-18 | Organization Module Owner | Publish rejection and cancellation states within the governed import cycle |
| 1.1.0 | 2026-07-18 | Organization Module Owner | Fix Person ownership, actor boundary, import, and provisioning per ADR-024 |
| 1.0.0 | 2026-07-15 | Organization Module Owner | Unify the front end and module boundaries |
