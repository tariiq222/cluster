---
doc_id: DOM-WDF-001
title: Work definitions
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: WorkDefinitions module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/006-workflow-versioning.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Work Definitions

## 1. Purpose

This domain represents the definitions of dynamic work types that the super admin can build without writing code: the fields, validation, states, relations, forms, lists, field policies, and DSL rules. WorkDefinitions owns the definition and its versions only; it does not own the operational record, the record payload, or the execution of approvals. Every published version is immutable and becomes the reference that WorkRecords and Workflow rely on.

## 2. Scope

- Create a work type family and the drafts of its versions.
- Define typed fields, validation rules, relations, and interfaces.
- Define the state schema and the allowed transitions for the record.
- Define field policies bound to a type version.
- Define lists, filters, and base reports without executing operational data.
- Test the definition with samples and accept/reject cases before approval.
- Sign a definition package free of data and secrets and publish an immutable version.
- Provide the schema contract and field policy contract to WorkRecords and Authorization.

What this domain does not do:

- Create or modify a real WorkRecord.
- Store passwords, roles, or real access decisions.
- Execute a workflow instance or an approval decision.
- Embed PHP, JavaScript, or SQL inside a definition.
- Define financial or clinical rules that fall outside the governed DSL.

## 3. Terminology

| Term | Definition |
|---|---|
| Work Type | A definition family such as an internal request or follow-up record, with multiple versions. |
| Work Type Version | A complete, immutable snapshot of a type's schema and rules, used by new records. |
| Draft | A version editable before it is tested. |
| DSL | A constrained expression language that represents typed conditions and rules as an AST and never executes free-form code. |
| Field Definition | A typed definition for a field: name, requirement, value, display policy, and validation. |
| Field Policy | A map of Hidden, ReadOnly, Editable, and Masked states by context. |
| Definition Package | A transport representation of the version without operational records or secrets. |
| Definition Test | An input case with an expected result to validate the schema, DSL, or transition. |
| Signature | Proof that the approved package was not modified between approval and publication. |

## 4. Aggregates, entities, and value objects

### 4.1 WorkTypeAggregate

- `WorkTypeDefinition` (root entity): work_type_id, code, names, owner scope, status.
- `WorkTypeVersion` (child entity): version_id, version_number, status, schema_hash.
- `DefinitionMetadata` (value object): name, description, classification, default retention.

### 4.2 SchemaAggregate

- `FieldDefinition` (child entity): field_key, type, required, default, validation rules.
- `RelationDefinition` (child entity): relation_key, target_type, cardinality, visibility.
- `StateDefinition` (child entity): state_key, terminal, display metadata.
- `TransitionDefinition` (child entity): from_state, to_state, guard DSL, required capability.

### 4.3 PresentationAggregate

- `FormLayout` (child entity).
- `ListViewDefinition` (child entity).
- `ReportViewDefinition` (child entity).
- `FieldAccessPolicy` (value object) keyed by field_policy_key for the type version.

### 4.4 DefinitionGovernanceAggregate

- `DefinitionTestCase` (child entity).
- `DefinitionApproval` (child entity): approver, reason, timestamp.
- `DefinitionSignature` (child entity): fingerprint, signer, key_id, signed_at.
- `DefinitionPackage` (root entity): package_hash, source, manifest, import result.

## 5. Constrained DSL

### 5.1 Shape

- The rule is stored as a JSON AST with a `dsl_version` field and is never stored as a runnable text expression.
- Every node carries a fixed type such as `and`, `or`, `not`, `equals`, `in`, `greater_than`, `is_present`, `date_before`.
- The only references allowed are field keys in the same version and declared context values such as current_user and record_state.
- No loop, recursion, reflection, HTTP call, filesystem call, database call, or non-allow-listed function is permitted.

### 5.2 DSL gate

- The parser converts the input into a canonical AST.
- The validator checks types, references, depth, node count, and size.
- The internal compiler turns the AST into an evaluator over an allow-listed set.
- The executor enforces a time budget and a node budget and rejects any unknown shape.
- Any change to DSL version or allow-list requires a new test run and a new signature.

### 5.3 Default limits

- Maximum depth: 12 levels.
- Maximum node count: 200.
- No more than 50 values in the `in` operator.
- The DSL never reads hidden payload or any field not defined in the version.
- Time, user, and randomness are not treated as non-deterministic inputs except through a declared context snapshot.

## 6. Tables, constraints, and indexes

> **Drift correction:** The previous revision described eight tables (`work_type_definitions`, `work_type_versions`, `field_definitions`, `definition_rules`, `relation_definitions`, `form_layouts`, `definition_test_cases`, `definition_signatures`) plus a `DefinitionPackage`. The implementation (`apps/api/Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateWorkDefinitionTables.php:11-48` and `CreateDevelopmentWorkTypeFixturesTable.php:15-22`) ships exactly **four** tables: `work_definitions`, `work_definition_versions`, `work_definition_idempotency_keys`, and the dev-only `work_definition_development_work_type_versions`. Field/rule/relation/layout/test/signature sub-tables are not present in the schema; they remain future work. The `WorkTypeVersion` state machine is a single `status VARCHAR(16) DEFAULT 'draft'` column with no `definition_state`, `tested`, `approved`, or `signed` status, and no `approved_at`, `signed_at`, `signed_by_user_id`, `key_id`, or `signature` columns. `dsl_version` is not present on the version row.

### 6.1 `work_definitions`

- `id` UUID PK (Laravel `uuid`).
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `name` VARCHAR(255) NOT NULL.
- `description` VARCHAR(2000) NULL.
- `default_classification` VARCHAR(24) NOT NULL DEFAULT `internal`.
- `created_by_user_id` UUID NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- `lock_version` UNSIGNED INT NOT NULL DEFAULT 1.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.

### 6.2 `work_definition_versions`

- `id` UUID PK.
- `work_definition_id` UUID NOT NULL FK -> `work_definitions.id` ON DELETE RESTRICT.
- `version_number` UNSIGNED INT NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `draft`.
- `schema_document` JSON NOT NULL.
- `field_policy_key` VARCHAR(128) NOT NULL.
- `schema_hash` CHAR(64) NOT NULL.
- `change_summary` VARCHAR(2000) NULL.
- `created_by_user_id` UUID NOT NULL.
- `lock_version` UNSIGNED INT NOT NULL DEFAULT 1.
- `published_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(work_definition_id, version_number)`.
- Index on `(work_definition_id, status)`.

### 6.3 `work_definition_idempotency_keys`

- `id` BIGINT PK (Laravel auto-increment).
- `principal_id` UUID NOT NULL.
- `operation` VARCHAR(96) NOT NULL.
- `key_hash` CHAR(64) NOT NULL.
- `request_hash` CHAR(64) NOT NULL.
- `resource_id` UUID NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(principal_id, operation, key_hash)`.

### 6.4 `work_definition_development_work_type_versions` (development fixtures, local/testing only)

- `id` UUID PK.
- `code` VARCHAR(64) UNIQUE NOT NULL.
- `version` UNSIGNED SMALLINT NOT NULL.
- `status` VARCHAR(32) NOT NULL.
- `input_schema` JSON NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.

## 7. Commands, queries, and events

### 7.1 Commands

- `CreateWorkTypeDraft`
- `CreateWorkTypeVersionDraft`
- `PublishWorkTypeVersion`
- `RetirePublishedWorkType`
- `ExportDefinitionPackage`
- `ImportDefinitionPackage`

> **Drift correction:** The previous revision listed many draft/test/approve/sign commands (`AddFieldDefinition`, `UpdateDraftFieldDefinition`, `AddRelationDefinition`, `ConfigureFieldAccessPolicy`, `DefineStateTransition`, `DefineRestrictedDslRule`, `ConfigureFormLayout`, `AddDefinitionTestCase`, `TestWorkTypeVersion`, `ApproveWorkTypeVersion`, `SignWorkTypeVersion`). The schema's single `status VARCHAR(16)` column does not implement the `Draft -> Tested -> Approved -> Signed -> Published` lifecycle, and no handler ships for those commands. They remain future work tied to the missing sub-tables.

### 7.2 Queries

- `GetWorkType`
- `GetDraftWorkTypeVersion`
- `GetPublishedWorkTypeSchema`
- `GetPublishedFieldDefinitions`
- `GetFieldAccessPolicy`
- `GetDefinitionTestResults`
- `GetDefinitionPackageManifest`
- `ListPublishedWorkTypes`
- `ValidateDefinitionCompatibility`

### 7.3 Domain and application events

- `WorkTypeCreated`
- `WorkTypeVersionDraftCreated`
- `FieldDefinitionAdded`
- `WorkTypeVersionTested`
- `WorkTypeVersionApproved`
- `WorkTypeVersionSigned`
- `WorkTypeVersionPublished`
- `WorkTypeVersionRetired`
- `DefinitionPackageExported`
- `DefinitionPackageImported`
- `DefinitionCompatibilityFailed`

## 8. State machines

### 8.1 WorkTypeVersion (implemented)

- The version row carries `status VARCHAR(16) DEFAULT 'draft'` and `published_at`. The richer `Draft -> Tested -> Approved -> Signed -> Published` lifecycle from the previous revision is **not** enforced by the migration; it remains a future work target once the missing sub-tables land.

### 8.2 DefinitionPackage (future)

- `Built` --(signature created)--> `Signed`.
- `Signed` --(import verification)--> `Verified` or `Rejected`.
- `Verified` --(publish in target environment)--> `Applied`.
- The package carries no operational records or secrets.

## 9. Invariants

- The implemented version table has no `definition_state`, so the previous ordering rule (`Draft -> Tested -> Approved -> Signed -> Published`) is not enforced at the schema level. A future migration is required before this invariant can hold.
- No version is published without successful blocking tests, a separate approval, and a signature that matches `schema_hash`.
- No more than one effective `Published` version per family without an explicit migration policy.
- The `Published` version is immutable; new records reference a specific `work_definition_version_id`.
- Deleting a field that is in use means retired or hidden and never destroys historical values.
- Every `field_key`, `relation_key`, and `rule_key` is unique inside a version.
- Every field type, operator, and relation matches an approved schema allow-list.
- Every DSL AST passes the parser, type checker, and limits; free-form code never runs.
- No rule references a hidden, undefined, or cross-version field.
- The package contains no WorkRecord data, credentials, or secret keys; the signing key stays outside the package.
- Publishing a definition never silently changes the state or payload of a live record.
- Every change in Draft passes through a WorkDefinitions-led transaction and writes an outbox event when needed.
- WorkDefinitions owns no WorkRecord or workflow-instance table.

## 10. Permissions

- The super admin creates the work type, edits the draft, and sets the fields and policies.
- Testing, approval, and signing are separate roles per the organization's policy; a person does not approve a version they created if the separation-of-duties policy forbids it.
- Publication requires the `publish_work_definition` capability and a centralized Authorization decision over the allowed Cluster or Facility scope.
- Importing a package requires hash, signature, environment, and version verification; the package has no permissions of its own.
- WorkRecords requests the schema and field policy through contracts; it never bypasses a Published definition with a local decision.
- A field-policy definition does not grant the user access to data; Authorization is the final decision point via `AuthorizationRecordFacts`.

## 11. Failure modes

- Duplicate field or invalid `field_key`: the change is rejected in Draft.
- Unknown AST or limits exceeded: the test fails and the version does not move to Tested.
- A blocking test fails: the version stays Draft with reviewable results.
- An attempt to move to Approved without Tested or to Signed without Approval: rejected by the state rule.
- A signature does not match `schema_hash` or the key is untrusted: publication is rejected.
- A package contains records, secrets, or a disallowed type: rejected before import.
- An attempt to modify a Published version: rejected; a new draft version is created only on a valid request.
- Deleting a field that is in use: converts to retired or fails if the deletion is destructive.
- `version_number` conflict: rollback with a new number chosen by the system.
- Outbox failure: rollback for the owning change and no successful publication.
- Compatibility failure with WorkRecords: blocks publication or requires an explicit migration plan.

## 12. Tests

- Unit: transitions across the implemented `status` value; the full Draft/Tested/Approved/Signed/Published chain remains a future test target.
- Unit: canonical AST, type checking, and limits for the DSL.
- Unit: blocking loops, HTTP, database, and non-allow-listed functions.
- Feature: create a schema and run an accept case and a reject case.
- Feature: the signature does not succeed after `schema_hash` changes.
- Feature: a Published version rejects update and yields a new version.
- Contract: `GetPublishedWorkTypeSchema` returns a stable version to WorkRecords.
- Contract: `GetFieldAccessPolicy` matches the Authorization policy key.
- Security: the package contains no payload, credentials, or secrets.
- Integration: an imported definition does not apply without signature and environment verification.
- Compatibility: the new schema does not break a live record or require an undeclared migration.
- Boundary: WorkDefinitions reads no WorkRecords or workflow-instance tables.
- Outbox: a single publication produces a single retryable event without duplication.

## 13. Dependencies

- Depends on `Shared/Clock` and `Shared/Identifiers`.
- Depends on Authorization for administrative command protection and field templates, and on the Audit/Outbox contracts.
- Provides WorkRecords with the schema, published version, and validation contract.
- Provides Workflow with the node and transition definitions and the constrained DSL when binding a path to a work type.
- Does not depend on WorkRecords and owns no operational data.
- Does not run Authorization from inside the DSL; the DSL provides context facts and Authorization remains the access-decision point.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | WorkDefinitions module owner | Unified the front-end and module boundary |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; reduced the schema to the four actual tables; dropped the BIGINT PK and `dsl_version` claims; replaced the `Draft -> Tested -> Approved -> Signed -> Published` lifecycle with the implemented `status` column |