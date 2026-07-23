---
doc_id: DOM-WRC-001
title: Dynamic work records
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: WorkRecords module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/authorization-model.md
---
# Work Records

## 1. Purpose

This domain represents the dynamic operational records created from a published work type and persists a fixed envelope together with a payload bound to a specific definition version. WorkRecords is independent from WorkDefinitions: the former owns the record reality, its state, ownership, version, and activity, while the latter owns the schema and the definition. It is also independent from Workflow; the path executes its steps, but WorkRecords owns the meaning of creating the record, submitting it, and closing it.

Every write to a record is driven by the owning aggregate through an owner-led transaction. There is no application-wide transaction, and no other module writes directly into WorkRecords tables. When Workflow, Tasks, or Documents are needed, WorkRecords calls declared contracts and the record reality and the command transaction remain owned by WorkRecords.

## 2. Scope

- Create a dynamic record from a Published Work Type Version.
- Persist the owner, organization unit, classification, status, and responsible user.
- Persist a typed or JSON payload bound to the version, with selected search and report projections.
- Apply schema and DSL validation before save or transition.
- Manage draft, submit, return, reject, complete, and cancel per the definition's lifecycle.
- Manage participants, explicit sharing, and record links.
- Provide `AuthorizationRecordFacts` as the source of truth for the record.
- Emit record events to the outbox for search, notifications, and derived reads.

What this domain does not do:

- Define or modify the work type or schema.
- Define or execute workflow nodes or approval decisions.
- Store files themselves; that is owned by Documents.
- Manage passwords, roles, or organizational relationships.
- Write Strategy, PortfolioProjects, or Risk tables; those are independent specialized modules.

## 3. Terminology

| Term | Definition |
|---|---|
| Work Record | An independent operational unit that carries envelope, payload, definition version, and state. |
| Record Owner | The Cluster, Facility, or Unit responsible for the record; an owner user may also exist. |
| Definition Version | The published `work_type_version_id` that pins the schema and fields at record creation. |
| Envelope | The fixed columns for identity, owner, classification, state, version, and audit. |
| Payload | The dynamic typed values bound to the definition version; not an authority source on its own. |
| Typed Projection | An indexed value for a field chosen by the definition for search or reports, derived from the payload. |
| Sharing | An explicit visibility grant to a unit, user, or role per Authorization policy. |
| Record State | A lifecycle state defined by the type; it only changes through a defined, authorized transition. |
| Transaction Owner | The handler that drives the aggregate command and owns the commit, rollback, and outbox effects. |

## 4. Aggregates, entities, and value objects

### 4.1 WorkRecordAggregate

- `WorkRecord` (root entity): record_id, type/version, owner, creator, responsible, classification, lifecycle_state, lock_version.
- `RecordEnvelope` (value object): the identifier, owner, state, classification, and version.
- `RecordOwner` (value object): owner_scope_type and owner_scope_id across Cluster > Facility > Unit.
- `RecordClassification` (value object).
- `RecordVersion` (value object) for optimistic locking.

### 4.2 WorkPayloadAggregate

- `WorkPayload` (child entity): payload, schema version, normalized hash.
- `TypedFieldProjection` (child entity): field_key, typed_value, visibility metadata.
- `RecordRelation` (child entity): relation_key, target_type, target_id, authorization reference.

### 4.3 RecordCollaborationAggregate

- `RecordParticipant` (child entity): user or unit, participation role, and participation window.
- `RecordActivity` (child entity): a user-meaningful action, the actor, a before/after summary, and a reason.
- Sharing never grants access to every field; the decision is re-requested through `AuthorizationRecordFacts` and `ResolveFieldAccess`.

### 4.4 AuthorizationRecordFacts

WorkRecords produces the following facts DTO from the record itself:

- owner cluster/facility/unit.
- creator and owner user and responsible user.
- participants and shared units.
- classification and lifecycle/workflow state.
- type/version and `field_policy_key`.
- lock/version and the facts timestamp.

The DTO contains no payload it does not need and never makes an Allow or Deny decision.

## 5. Tables, constraints, and indexes

> **Drift correction:** The previous revision described six tables (`work_records`, `work_record_payloads`, `work_record_field_projections`, `work_record_relations`, `work_record_participants`, `work_record_activities`) plus a `work_record_idempotency_keys` table. The implementation under `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/` ships exactly **two** tables: `work_records` and `work_record_idempotency_keys`. The payload is stored as a single JSON column on `work_records`; there are no separate payload/projection/relation/participant/activity tables. The previous revision's BIGINT PKs and `created_by_user_id BIGINT` claim are also dropped — the migration uses `uuid('id')->primary()` and `uuid('creator_user_id')`. The owning user column is `creator_user_id` (not `created_by_user_id`). The `work_record_idempotency_keys` table has a `facility_id` column, contradicting the previous revision's claim that it did not.

### 5.1 `work_records`

- `id` UUID PK.
- `record_number` VARCHAR(64) UNIQUE NOT NULL.
- `work_type_version_id` UUID NOT NULL; must be Published at creation. The previous `work_type_id` column is **absent** — the version is the only reference back into WorkDefinitions.
- `owner_facility_id` UUID NOT NULL. The polymorphic `owner_scope_type`/`owner_scope_id`/`owner_organization_unit_id` columns from the previous revision are **absent**.
- `creator_user_id` UUID NOT NULL.
- `status` VARCHAR(32) NOT NULL DEFAULT `draft`, indexed.
- `classification` VARCHAR(32) NOT NULL DEFAULT `internal` (`public|internal|confidential|top_secret`), indexed.
- `field_policy_key` VARCHAR(128) NULL (added by W13).
- `payload` JSON NOT NULL.
- `lock_version` UNSIGNED INT NOT NULL DEFAULT 1.
- `submitted_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `record_number`.
- Index on `(owner_facility_id, status)`.

### 5.2 `work_record_idempotency_keys`

- `id` BIGINT PK (Laravel auto-increment).
- `principal_id` UUID NOT NULL.
- `facility_id` UUID NOT NULL.
- `operation` VARCHAR(96) NOT NULL.
- `idempotency_key_hash` CHAR(64) NOT NULL.
- `request_hash` CHAR(64) NOT NULL.
- `work_record_id` UUID NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(principal_id, facility_id, operation, idempotency_key_hash)` (named `work_record_idempotency_scope_unique`).
- Index on `work_record_id`.

## 6. Commands, queries, and events

### 6.1 Commands

- `CreateWorkRecord`
- `SubmitWorkRecord`

> **Drift correction:** The previous revision listed commands that target the missing sub-tables (`SaveWorkRecordDraft`, `UpdateWorkRecordDraft`, `AddWorkRecordRelation`, `AddWorkRecordParticipant`, `RemoveWorkRecordParticipant`, `ReturnWorkRecordForRevision`, `RejectWorkRecord`, `StartWorkRecordProcessing`, `CompleteWorkRecord`, `CancelWorkRecord`, `TransferRecordOwnership`, `UpdateWorkRecordClassification`). Only `CreateWorkRecord` and `SubmitWorkRecord` (plus the read-side `GetAuthorizedWorkRecord` and `ListAuthorizedWorkRecords` features) are implemented. The remaining commands are future work tied to the missing payload/relation/participant/activity tables.

`GetAuthorizationRecordFacts` is not a write, but it is a contract owned by the record owner.

Every write command goes through a WorkRecords handler, asks Authorization, loads the aggregate, applies the domain transition, and persists the payload/activity/outbox in a single transaction led by WorkRecords.

### 6.2 Queries

- `GetWorkRecord`
- `GetWorkRecordAuthorizedView`
- `ListMyWorkRecords`
- `ListOwnerInbox`
- `ListWorkRecordsByOrganizationScope`
- `GetWorkRecordPayload`
- `GetTypedFieldProjection`
- `GetWorkRecordActivity`
- `GetWorkRecordRelations`
- `GetAuthorizationRecordFacts`
- `GetPublishedVersionForRecord`
- `BuildWorkRecordReadModel`

Every query asks Authorization before returning the payload or a field projection and never returns a record title or snippet when the record is blocked.

### 6.3 Domain and application events

- `WorkRecordCreated`
- `WorkRecordDraftSaved`
- `WorkRecordSubmitted`
- `WorkRecordReturnedForRevision`
- `WorkRecordRejected`
- `WorkRecordProcessingStarted`
- `WorkRecordCompleted`
- `WorkRecordCancelled`
- `WorkRecordOwnershipTransferred`
- `WorkRecordClassificationChanged`
- `WorkRecordParticipantAdded`
- `WorkRecordParticipantRemoved`
- `WorkRecordPayloadChanged`
- `WorkRecordAuthorizationFactsChanged`

WorkRecords writes the event and the outbox row in one transaction. Search, Notifications, and Reporting are idempotent consumers and never mutate the source record.

## 7. State machines

### 7.1 WorkRecord

- `Draft` --(SubmitWorkRecord after schema validation)--> `Submitted`.
- `Submitted` --(workflow required)--> `InApproval`.
- `Submitted` --(no approval required)--> `InProcessing`.
- `InApproval` --(return)--> `ReturnedForRevision`.
- `ReturnedForRevision` --(resubmit)--> `Submitted`.
- `InApproval` --(approve)--> `InProcessing`.
- `InApproval` --(reject)--> `Rejected`.
- `InProcessing` --(complete)--> `Completed`.
- `InProcessing` --(return)--> `ReturnedForRevision`.
- `Draft` or `ReturnedForRevision` --(cancel)--> `Cancelled` per policy.
- `Rejected`, `Completed`, and `Cancelled` are terminal; any reopen requires an explicit command, policy, and activity row.

### 7.2 RecordPayload

- `Unvalidated` --(schema validation)--> `Valid`.
- `Valid` --(draft edit)--> `Unvalidated`.
- `Valid` --(submit)--> `FrozenForState`.
- `FrozenForState` never changes except through an authorized transition or a return to edit.

### 7.3 Ownership

- `Assigned` --(TransferRecordOwnership)--> `Transferred` with both the previous and the new owner captured in Activity.
- Ownership never changes automatically because Organization changed; it needs a governed command and a re-evaluation of Authorization.

## 8. Invariants

- Every record carries a published `work_type_version_id`, and the version never changes silently during the record's life.
- Every record follows Cluster > Facility > Unit; no Unit exists outside a Cluster, and no Facility points at a different Cluster.
- Every record has one clear owner scope; `creator_user_id` alone is never enough to represent ownership.
- A payload is never persisted before schema and DSL validation succeed.
- A transition not defined in the Work Type Version is rejected; an unknown state is rejected.
- A field never appears, searches, or exports before `ResolveFieldAccess`.
- Sharing never grants visibility on the payload, the documents, or the source record automatically; every action is re-authorized.
- `lock_version` increments on every write and protects against silent overwrite.
- No other module writes directly into `work_records` or `work_record_idempotency_keys`.
- Every command that mutates the aggregate writes a clear activity row, and sensitive actions write Audit through a contract.
- There is no direct hard delete from the UI; cancellation or future archival never destroys history.
- The owner-led transaction is the unit of consistency: the record state, payload, activity, and outbox either succeed together or roll back together.
- No transaction extends into the queue, search, or object storage; contracts and post-Commit events are used instead.
- `AuthorizationRecordFacts` is built from the same aggregate copy that decided the operation and never uses a stale cache.
- A typed projection never applies from raw JSON outside the schema or without a matching version.

## 9. Permissions

- `CreateWorkRecord` requires a create capability on the Work Type and the organizational scope.
- Draft editing is limited to the creator, owner, responsible, or anyone Authorization grants an update capability to.
- Submit, Complete, Reject, Transfer, and ClassificationChange are separate capabilities.
- A Unit owner does not automatically see Facility or sibling-Unit records; Cluster never implies detail visibility.
- WorkRecords produces `AuthorizationRecordFacts`, but Authorization alone decides Allow or Deny and field access.
- A cluster owner may receive only an aggregate or indicator depending on the relationship and never sees `view_details` they lack.
- Super admin actions on sensitive classified records remain under audit.
- Any act-on-behalf-of execution shows the actor and the delegator in Activity and Audit.

## 10. Failure modes

- The work type or version is not Published: creation is rejected.
- Missing payload, an invalid typed value, or a failing DSL: the record stays Draft without losing the valid input.
- `AuthorizationRecordFacts` is unavailable: fail-closed for view or edit, with an operational error that does not leak the record.
- Deny from Authorization: never return record id, title, or hidden fields.
- Stale `lock_version`: a concurrent edit conflict is surfaced with the current values; the change is never silently applied.
- Disallowed transition: rejected with the current state and the requested transition, with no partial mutation.
- Workflow start failure during submit: rollback the submit meaning unless an explicit compensation contract exists; the record never appears Submitted falsely.
- Outbox failure: rollback the record, activity, and change; the error is surfaced to the review queue.
- Search, Notification, or Projection failure after Commit: the record stays valid and idempotent retry is used.
- An attempt to change the version of a live record: rejected or requires explicit migration and a compatibility check.
- Owner outside the allowed Cluster, Facility, or Unit: the transfer is rejected with a diagnosable reason.

## 11. Tests

- Unit: envelope pins owner, version, classification, and state.
- Unit: lifecycle transitions and rejection of undefined states.
- Unit: schema and DSL validation for the payload.
- Feature: create a Draft and Submit with and without workflow.
- Feature: the owner-led transaction is atomic for the record, payload, activity, and outbox.
- Concurrency: a stale `lock_version` blocks silent overwrite.
- Authorization contract: `AuthorizationRecordFacts` distinguishes Unit owner, participant, and cluster indicator.
- Field security: a Hidden field never appears in Get, Search, Report, or Export.
- Isolation: Facility A never sees the title or snippet of a Facility B record without a relationship.
- Versioning: a live record stays on its old version when a new version is published.
- Failure: Workflow or outbox failure never produces a partial success.
- Idempotency: replaying `WorkRecordSubmitted` never creates a duplicate notification or projection.
- Boundary: Workflow, Tasks, and Search never write WorkRecords tables directly.
- Integration: an Organization change or assignment end rebuilds `AuthorizationRecordFacts` and changes the decision without rewriting the record's history.

## 12. Dependencies

- Depends on `Shared/Clock` and `Shared/Identifiers`.
- Depends on Organization for Cluster > Facility > Unit reference, ownership, and relationships.
- Depends on Identity for creator, responsible, and participant status.
- Depends on Authorization for record, field, and `RecordFacts` decisions.
- Depends on WorkDefinitions for the published schema, version, and DSL contract, without owning the definitions.
- Provides Workflow with the record reference, version, and facts; provides Documents and Tasks with source links.
- Sends outbox events to Search, Notifications, and Reporting.
- There is no separate request module or separate request tables; internal requests are a `WorkRecord` of a published work type with code `request`, and the code is not a data classification. Strategy, PortfolioProjects, and Risk never write directly.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | WorkRecords module owner | Established the dynamic-request ownership and unified the front-end |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; reduced the schema to the two actual tables; replaced BIGINT PKs and `created_by_user_id BIGINT` with UUIDs and `creator_user_id`; removed the missing payload/projection/relation/participant/activity sub-tables; recorded the W13 `field_policy_key` addition and the `facility_id` column on the idempotency-keys table |