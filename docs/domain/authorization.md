---
doc_id: DOM-AUT-001
title: Central authorization
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Authorization module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/context-map.md
- docs/data-security/authorization-model.md
---
# Authorization

## 1. Purpose

Authorization owns the central, explainable access decision. It combines capability from roles with organizational scope, relationships, ownership, classification, lifecycle state, assignment, delegation, and field policy, then returns a unified decision that can be applied across the API, search, reporting, export, and UI layers. Authorization does not own the operational record and never re-derives its meaning; the record-owning module supplies specific `AuthorizationRecordFacts` alongside each decision request.

## 2. Scope

- Defining roles, capabilities, and field policy templates.
- Granting and revoking role assignments within a known time range and scope.
- Managing bounded-time, bounded-scope delegations.
- Applying RBAC + ABAC over Cluster > Facility > Unit and over records and fields.
- Consuming account status from Identity and scope / relationships from Organization.
- Receiving record facts supplied by the owning module through the `AuthorizationRecordFacts` contract.
- Issuing `AccessDecision` and `FieldAccessDecision` and explaining the decision.

What is out of scope:

- Passwords, sessions, and the source account status — these stay in Identity.
- The source Cluster / Facility / Unit tree and supervisory relationships — these stay in Organization.
- Record payload, record state transitions, and workflow execution.
- Writing into other modules' business tables or interpreting their private fields.

## 3. Terms

| Term | Definition |
|---|---|
| Role | A named bundle of capabilities that can be assigned to a user within a scope and time range. |
| Capability | A fine-grained action such as `view`, `create`, `update`, `submit`, `approve`, `assign`, `export`, `manage`. |
| Scope | Cluster, Facility, Unit, or a shared record set that defines where a capability applies. |
| AuthorizationRecordFacts | A facts contract the record owner produces about ownership, status, classification, participation, and field policy; it carries no payload and no access decision. |
| AccessDecision | An `Allow` or `Deny` result with reason codes, fact values, policy version, and decision time. |
| FieldAccessDecision | A per-field state: `Hidden`, `ReadOnly`, `Editable`, or `Masked`. |
| Delegation | A bounded transfer of capability and scope to a delegate, while preserving the original owner's identity. |
| Explicit deny | A rule that blocks access even when a wider allow exists. |
| Fail closed | A safe-deny posture when `AuthorizationRecordFacts` are missing, a fact source fails, or the decision window expires. |

## 4. Aggregates, entities, and value objects

### 4.1 Role aggregate

- `Role` (root entity): `code`, `name`, `status`, `role_type`.
- `Capability` (child entity): `capability_code`, `module_code`, `action`, `sensitivity`.
- `RoleCapability` (junction entity): `role_id` + `capability_id` with an `allow` / `deny` effect when needed.

### 4.2 RoleAssignment aggregate

- `RoleAssignment` (root entity): `user_id`, `role_id`, `scope_id`, `start_at`, `end_at`, `status`.
- The previous revision listed a separate `AuthorizationScope` value object carrying `scope_type`. The schema does not store `scope_type` on `role_assignments`; only `scope_id` (UUID, nullable) is persisted. The scope kind is implied by where the row is consumed (cluster, facility, unit, or shared set) and is not stored at the row level.
- `AssignmentPeriod` (value object): a time range that does not exceed the role's policy.

### 4.3 Delegation aggregate

- `Delegation` (root entity): `delegator_user_id`, `delegate_user_id`, `module_code`, `scope_id`, `start_at`, `end_at`, `status`.
- The previous revision listed a JSON `capability_set` column and a `scope_type` + `reason` pair. The actual schema uses `module_code VARCHAR(64)` to identify the module and stores the delegated capability list in a separate `delegation_capabilities` table. There is no `scope_type` column on `delegations` and no `reason` column at all. The `delegator_user_id <> delegate_user_id` inequality is enforced as a database check constraint on MySQL and as a SQLite trigger.
- `DelegationReason` is not modeled as a value object. When a sensitive capability requires a reason, the upstream record supplies it as part of `AuthorizationRecordFacts`; the delegations table does not store it.

### 4.4 ClassificationPolicy aggregate

- `ClassificationPolicy` (root entity): classification level, minimum capability, participation / export / audit rules.
- `FieldAccessTemplate` (root entity): `field_policy_key`, per-role / per-status / per-scope rules.

### 4.5 AccessDecision value objects

- `RecordReference`: `module_code`, `record_type`, `record_id`.
- `AuthorizationRecordFacts`: the facts the record owner supplies.
- `AccessDecision`: `decision`, `reason_codes`, `policy_version`, `evaluated_at`, `trace_id`.
- `FieldAccessMap`: the per-field allow / hide map.

## 5. AuthorizationRecordFacts contract

### 5.1 Record-owner's responsibility

The owning module (for example `WorkRecords` or `Workflow`) produces `AuthorizationRecordFacts` from its source of truth and supplies only this contract to the access pipeline. Authorization never builds the facts from arbitrary joins and never accepts a full payload just to decide. The owner never issues `Allow` or `Deny` and never issues a field decision.

### 5.2 Mandatory fields

- `facts_version`.
- `source_module`, `record_type`, `record_id`.
- `cluster_id`, `owner_facility_id`, `owner_organization_unit_id`.
- `created_by_user_id`, `owner_user_id`, `responsible_user_id` when present.
- `shared_unit_ids`, `shared_user_ids`, `participant_ids` per the owner's policy.
- `classification`, `lifecycle_state`, `workflow_state`.
- `field_policy_key`, `work_type_version_id` when present.
- The action context is not part of the owner's facts; the caller passes a separate `action_context` to `DecideAccess`.

### 5.3 Rules

- `AuthorizationRecordFacts` never contain a password, a token, or any payload that is not required.
- `AuthorizationRecordFacts` never bypass a role, classification, or central explicit-deny rule.
- The facts must be re-readable and must match the record at the same version.
- A missing mandatory fact or a failed owner contract produces a `Deny` or a clear service status; it never produces a default `Allow`.
- The decision retains `facts_version` and `policy_version` for explainability and re-investigation.

## 6. Tables, constraints, and indexes

### 6.1 `roles`

- `id` CHAR(36) UUID PK.
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `role_type` VARCHAR(32) NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- `is_system_role` BOOLEAN NOT NULL DEFAULT FALSE.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Indexes: `(status, role_type)`.

> **Drift correction:** The previous revision listed `id BIGINT PK`. The migration uses `$table->uuid('id')->primary()` (UUID PK, not BIGINT).

### 6.2 `capabilities`

- `id` CHAR(36) UUID PK.
- `module_code` VARCHAR(64) NOT NULL.
- `capability_code` VARCHAR(96) NOT NULL.
- `action` VARCHAR(32) NOT NULL.
- `sensitivity` VARCHAR(16) NOT NULL DEFAULT `normal`.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- Unique constraint on `(module_code, capability_code)`.
- Indexes: `(module_code, action, status)`, `(sensitivity, status)`.

> **Drift correction:** The previous revision listed `id BIGINT PK`. The migration uses `$table->uuid('id')->primary()`.

### 6.3 `role_capabilities`

- `role_id` CHAR(36) UUID NOT NULL FK -> `roles.id` (cascade on delete).
- `capability_id` CHAR(36) UUID NOT NULL FK -> `capabilities.id` (restrict on delete).
- `effect` VARCHAR(8) NOT NULL DEFAULT `allow`.
- `created_at` DATETIME NOT NULL.
- Composite PK `(role_id, capability_id)`.
- Allowed values for `effect`: `allow`, `deny`.

### 6.4 `role_assignments`

- `id` CHAR(36) UUID PK.
- `user_id` CHAR(36) UUID NOT NULL — Identity reference through a contract.
- `role_id` CHAR(36) UUID NOT NULL FK -> `roles.id` (restrict on delete).
- `scope_id` CHAR(36) UUID NULL — no `scope_type` column.
- `start_at` DATETIME NOT NULL.
- `end_at` DATETIME NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- `granted_by_user_id` CHAR(36) UUID NOT NULL.
- Constraint `start_at < end_at` when `end_at` is set (database check on MySQL; SQLite trigger).
- Overlap check on `(user_id, role_id, scope_id, status='active')` (MySQL trigger; SQLite trigger).
- Indexes: `(user_id, status, start_at, end_at)`, `(scope_id, status)`, `(role_id, status)`.

> **Drift correction:** The previous revision listed `id BIGINT PK`, `scope_type VARCHAR(16) NOT NULL`, and `scope_id BIGINT NOT NULL`. The migration uses UUID PK, no `scope_type` column, and a UUID nullable `scope_id`.

### 6.5 `delegations`

- `id` CHAR(36) UUID PK.
- `delegator_user_id` CHAR(36) UUID NOT NULL.
- `delegate_user_id` CHAR(36) UUID NOT NULL.
- `module_code` VARCHAR(64) NOT NULL.
- `scope_id` CHAR(36) UUID NULL — no `scope_type` column.
- `start_at` DATETIME NOT NULL.
- `end_at` DATETIME NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- Check constraint `delegator_user_id <> delegate_user_id` and `end_at > start_at` (MySQL check; SQLite trigger).
- No `reason` column; the reason lives on the originating record / `AuthorizationRecordFacts` when required.
- Indexes: `(delegate_user_id, status, start_at, end_at)`, `(delegator_user_id, status)`, `(scope_id, status)`.
- Capabilities are stored in the sibling `delegation_capabilities` table:
  - `delegation_id` CHAR(36) UUID NOT NULL FK -> `delegations.id` (cascade on delete).
  - `capability_code` VARCHAR(96) NOT NULL.
  - Composite PK `(delegation_id, capability_code)`.
  - Database check that `capability_code` is non-empty, ≤ 96 chars, and contains no `*` / `?` / `%` wildcards.

> **Drift correction:** The previous revision listed `capability_set JSON`, `scope_type VARCHAR(16)`, `scope_id BIGINT`, `reason VARCHAR(500)`, and an inequality check. None of those match the schema. Capabilities live in `delegation_capabilities`; the check is enforced by database constraints; no `reason` is persisted.

### 6.6 `classification_policies`

- `id` BIGINT PK.
- `classification_code` VARCHAR(32) UNIQUE NOT NULL (`public`, `internal`, `confidential`, `top_secret`).
- `minimum_capability` VARCHAR(96) NOT NULL.
- `export_policy` VARCHAR(32) NOT NULL.
- `download_policy` VARCHAR(32) NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- Indexes: `(is_active, classification_code)`.

### 6.7 `field_access_templates`

- `id` BIGINT PK.
- `field_policy_key` VARCHAR(128) UNIQUE NOT NULL.
- `module_code` VARCHAR(64) NOT NULL.
- `policy_definition` JSON NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- Indexes: `(module_code, is_active)`.

### 6.8 `access_decisions` (HTTP/decision log)

- `id` CHAR(36) UUID PK.
- `decision` VARCHAR(8) — binary `allow` / `deny`. The previous revision listed a `Requested` state; the schema only stores the final allow/deny value.
- `action` VARCHAR(128) NOT NULL.
- `resource_type` VARCHAR(128) NOT NULL.
- `resource_id` CHAR(36) UUID NULL.
- `reason_codes` JSON NOT NULL.
- `policy_version` VARCHAR(128) NOT NULL.
- `facts_version` VARCHAR(128) NOT NULL.
- `authorization_trace_id` CHAR(36) UUID NOT NULL.
- `evaluated_at` DATETIME(3) NOT NULL.
- `correlation_id` CHAR(36) UUID NOT NULL.
- `classification` VARCHAR(32) NOT NULL.
- `access_context` JSON NOT NULL.
- `actor_user_id` CHAR(36) UUID NOT NULL.
- Indexes: `(actor_user_id, evaluated_at)`, `(resource_type, resource_id)`, `(correlation_id)`.

### 6.9 `sensitive_access_events`

The table lives inside the Authorization module (`apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php:37-57`). The Audit module does not currently own this table; if Audit later absorbs it, the migration file moves accordingly.

## 7. Commands, queries, and events

### 7.1 Commands (implemented by Authorization feature handlers)

- `CreateRole`
- `DefineCapability`
- `GrantRoleCapability`
- `DenyRoleCapability`
- `AssignRole`
- `RevokeRole`
- `CreateDelegation`
- `ActivateDelegation`
- `EndDelegation`
- `CreateClassificationPolicy`
- `PublishFieldAccessTemplate`

Administrative commands are owned by Authorization. Every write inside Authorization runs inside a transaction owned by the relevant aggregate; access decisions and field decisions are queries and never write into a business record.

### 7.2 Queries (contract interfaces in `apps/api/Modules/Authorization/Contracts/`)

- `DecideAccess` — central decision entry point.
- `AccessDecision` — typed result contract.
- `RecordFacts` — facts construction contract.
- `AccessProjection` — read-side projection for explanations.
- `PersistAccessDecision` — append-only decision log writer.
- `CapabilityCatalog` — read the active capability catalog.
- `CountOperationsOfficeMembers` — operations-office specific count.
- `AuthorizationResourceReference` — resolves the resource reference for an authorization call.
- `ResolveAuthorizationSimulationFacts` — supplies simulation facts.
- `AuthorizationSimulationFactsProvider` — provider shape for the simulator.

The HTTP layer exposes an `ExplainAccessDecision` controller, but there is no `Contracts/ExplainAccessDecision.php` interface — explanation is delivered by composing the published contracts above.

> **Drift correction:** The previous revision listed `BuildAuthorizedScopePredicate`, `ResolveFieldAccess`, `ExplainAccessDecision`, `FilterReadableOrganizationScopes`, `GetActiveRoleAssignments`, `GetActiveDelegations`, `GetCapabilitiesForContext`, `GetClassificationPolicy`, and `GetFieldAccessTemplate` as Authorization queries. None of them exist as contract interfaces in the current module. The owning module applies its own scope predicate before calling `DecideAccess`; field policy is resolved through `RecordFacts` + `DecideAccess`.

The owning module applies its scope predicate over its own query, then passes `AuthorizationRecordFacts` for each result to `DecideAccess`. Authorization never queries the owning module and never returns business records.

### 7.3 Domain and application events

- `RoleCreated`
- `RoleCapabilityGranted`
- `RoleCapabilityDenied`
- `RoleAssigned`
- `RoleRevoked`
- `DelegationCreated`
- `DelegationActivated`
- `DelegationExpired`
- `DelegationEnded`
- `ClassificationPolicyPublished`
- `FieldAccessTemplatePublished`
- `AccessDeniedForSensitiveRecord`
- `AuthorizationDecisionRecorded`

Administrative and sensitive events are recorded through the outbox; a normal read decision does not become an operational event unless classification policy requires it.

## 8. State machines

### 8.1 RoleAssignment

- `Pending` --(`start_at` reached)--> `Active`.
- `Active` --(revoke or `end_at` reached)--> `Revoked`.
- `Pending` --(cancel)--> `Cancelled`.
- `Revoked` and `Cancelled` are final; a new assignment is required for renewed access.

### 8.2 Delegation

- `Draft` --(activate after validation)--> `Active`.
- `Active` --(`end_at` reached or `EndDelegation`)--> `Expired`.
- `Draft` --(cancel)--> `Cancelled`.
- `Expired` and `Cancelled` are final.

### 8.3 AccessDecision

- The previous revision described a `Requested -> Allowed | Denied` and `Requested -> Indeterminate -> Denied` machine. The runtime does not persist a `Requested` state: `AccessDecision` is binary `allow` / `deny`, persisted on `access_decisions.decision VARCHAR(8)` (`ZAddAuthorizationHttpTables.php:30-50`).
- The decision never grants a persistent capability; it is re-evaluated on every sensitive operation or whenever `policy_version` / `facts_version` changes.

## 9. Invariants

- The central decision is the only policy point; no module or UI may rebuild an independent `Allow`.
- The default is `Deny`; any `Allow` requires a valid capability, scope, and context.
- An explicit deny or a higher classification takes precedence over a wider allow.
- Role, assignment, or delegation expiry removes its effect immediately per the reference Clock.
- `view_aggregate` never grants `view_details`; an aggregate indicator never grants record details.
- Sharing `AuthorizationRecordFacts` is not sharing data; an independent capability must exist for the requested fields.
- Stale `AuthorizationRecordFacts` (changed `facts_version` / `lock_version`) are never reused without re-fetch.
- A delegation never grants a capability the delegator does not hold, nor a sensitive capability that policy forbids.
- A delegation never includes itself, an unbounded duration, or a wider scope than the originating assignment.
- Fields in `Hidden` state never appear in a response, search snippet, or export.
- If the owner does not supply valid `AuthorizationRecordFacts`, the decision fails closed; Authorization never infers them from payload.
- Every decision explanation mentions `policy_version`, `facts_version`, and `reason_codes` without leaking unnecessary data.
- Every sensitive decision is linkable to a `trace_id` and to an audit record; an access decision is not a contract to mutate the record.

## 10. Permissions

- The super admin manages roles, capabilities, classification templates, and sensitive delegations.
- Assigning a role requires an explicit administrative capability; being an organizational manager is not sufficient.
- An Organization relationship owner never adds a capability on their own; relationship capabilities are recorded in Organization and read by Authorization as context facts.
- The owning module decides who the owner is and what the state is; Authorization decides whether that satisfies capability policy.
- `WorkRecords`, `Workflow`, and `WorkDefinitions` request `DecideAccess` and the field decision only through contracts.
- Search, reporting, export, and download re-check the record/document decision and never rely on hiding React elements.
- The super admin themselves is audited when accessing sensitive content.

## 11. Failure modes

- `AuthorizationRecordFacts` missing or unavailable: `Deny` for protected content with reason `facts_unavailable`.
- Inactive account: `Deny` before any other rule.
- Scope outside the allowed Cluster / Facility / Unit: `Deny` without revealing the existence of a protected record.
- Classification above the capability: `Deny` or `Masked` per field policy; never auto-converted to `ReadOnly`.
- Delegation expires during the request: re-evaluation; denied if expiry falls before commit.
- Policy-version conflict: the policy is reloaded; no stale cache is used for a sensitive operation.
- Failure of an Organization or Identity contract: fail closed with an operational alert that does not leak data.
- Attempt to bypass `field_policy_key`: extra fields are refused; either the allowed shape is returned or the request fails per operation type.
- Two role assignments conflict: explicit deny is applied and the conflict is logged for review.
- Failure to record a sensitive access event: content is withheld if the policy requires recording before display.

## 12. Tests

- Unit: RBAC combined with scope, relationship, classification, and state.
- Unit: explicit deny wins over allow; expiry removes capability.
- Contract: `WorkRecords` supplies a complete `AuthorizationRecordFacts` and receives an explainable `Decision`.
- Contract: `Workflow` does not re-resolve the assignee inside Authorization; it consumes the decision for the resolved user.
- Security: `AuthorizationRecordFacts` contains no payload, password, or token.
- Authorization matrix: isolation between Facilities, isolation between Units, and functional-relationship cases.
- Field policy: `Hidden` fields never appear in API, search, report, or export.
- Classification: `view_aggregate` never reveals details; a confidential classification logs viewing and download.
- Delegation: no wider delegation than the delegator's capability; auto-expiry.
- Fail closed: Identity, Organization, or record-owner outage never produces an `Allow`.
- Cache: a change in `policy_version` or `facts_version` never reuses an old decision.
- Boundary: no direct reads of `WorkRecords`, `Workflow`, or `WorkDefinitions` tables.
- Property: for any `AuthorizationRecordFacts` outside the user's scope, no `record_id`, title, or snippet is visible.

## 13. Dependencies

- Depends on `Shared/Clock` and `Shared/Identifiers`.
- Consumes Organization for Cluster > Facility > Unit, assignments, relationships, and their capabilities.
- Consumes Identity for account status and identity summary; never reads `credentials`.
- Receives `AuthorizationRecordFacts` from every record-owning module alongside each decision request.
- Does not depend on `WorkDefinitions` to know field shapes; it receives `field_policy_key` inside `AuthorizationRecordFacts` and applies its own field template.
- Publishes decision contracts to `WorkRecords`, `Workflow`, `Documents`, `Reporting`, `Search`, and specialized modules.
- Sends events to Audit and the outbox through contracts; does not own the central audit log.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Authorization module owner | Unified front-end contract and `AuthorizationRecordFacts` boundaries |
| 1.0.1 | 2026-07-23 | Domain audit pass | PKs switched to UUID for `roles`, `capabilities`, `role_assignments`, `delegations`; removed unsupported `scope_type` / `reason` / `capability_set` columns; queries trimmed to the published contracts; `sensitive_access_events` ownership clarified; `AccessDecision` `Requested` state removed |
