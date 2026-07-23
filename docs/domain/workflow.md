---
doc_id: DOM-WFL-001
title: Workflow and approvals
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: Workflow module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/006-workflow-versioning.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Workflow

## 1. Purpose

This domain owns workflow definitions and their versions, instance execution, the steps within an instance, and the decisions taken on those steps. Workflow owns the graph, the execution, deadlines, escalation, and approver resolution; it does not own the business meaning of the source record or its closure. Each definition version passes through the lifecycle `Draft -> Tested -> Approved -> Signed -> Published`, and every instance pins the version it started on.

When an approval step activates, Workflow resolves approvers from Organization and Authorization in one shot and persists an immutable approver snapshot on the instance. Organizational or relationship changes after activation do not silently replace the approver; they need an explicit reassignment or escalation that is itself recorded.

## 2. Scope

- Create workflow definitions and their versions, nodes, and transitions.
- Support sequential, review, approval, rejection, return, work-item, wait, escalation, branch, parallel, and merge steps inside the stage boundary.
- Validate the graph and the DSL rules before approval.
- Publish an immutable version and pin it on the instance at start time.
- Activate a step, resolve its assignee, and persist the snapshot at activation.
- Receive the decision, verify the approver identity and execution authority, and move the step.
- Support delegate, fallback, and escalation under an explicit policy without mutating the original snapshot.
- Emit outbox events for steps, decisions, and integrations.

What this domain does not do:

- The record payload or the commercial meaning of `Completed`; that lives in WorkRecords or the source module.
- Roles, passwords, or the organization tree.
- Dynamic field definitions; it consumes WorkDefinitions when binding a path to a work type.
- Free-form code inside path conditions.
- Writing directly into source-module or task tables.

## 3. Terminology

| Term | Definition |
|---|---|
| Workflow Definition | A path family with versions that can be tested and published. |
| Workflow Version | An immutable graph of nodes, transitions, and policies, pinned on the instance. |
| Node | A step in the graph such as Start, Review, Approval, End, Work Item, or Wait. A `work_item` node is a work step performed by a person; it is derived as a "task" record in the Tasks module and must not be confused with the task itself. |
| Transition | An edge from one node to another with a constrained DSL guard and a permitted action. |
| Instance | An active execution of a path for one source record. |
| Step Instance | The execution of a single node with state, timestamps, decision, and assignees. |
| Approver Snapshot | The list of approvers resolved and saved at step activation, with the resolution source. |
| Activation | Moving the step to Active after graph and context validation and snapshot persistence. |
| Decision Mode | One, All, Any, Majority, or Quorum that defines when an approval step is satisfied. |
| Fallback | A declared rule for when a position is vacant or a candidate is unavailable; it is not a silent re-resolution. |

## 4. Aggregates, entities, and value objects

### 4.1 WorkflowDefinitionAggregate

- `WorkflowDefinition` (root entity): workflow_id, code, source record binding.
- `WorkflowVersion` (child entity): version_number, definition_state, graph_hash.
- `WorkflowNode` (child entity): node_key, node_type, configuration.
- `WorkflowTransition` (child entity): from_node, to_node, guard AST, priority.
- `WorkflowDefinitionTest` (child entity): input context and expected path/result.

### 4.2 WorkflowInstanceAggregate

- `WorkflowInstance` (root entity): instance_id, source_type, source_id, workflow_version_id, state.
- `WorkflowStepInstance` (child entity): node_key, state, activated_at, completed_at, lock_version.
- `ApproverSnapshot` (immutable value object): user_id, assignment_id, role, unit, source, resolved_at, delegation context.
- `DecisionPolicy` (value object): one/all/any/majority/quorum and threshold.

### 4.3 WorkflowDecisionAggregate

- `WorkflowDecision` (root entity): step_instance_id, actor_user_id, decision, reason, acted_at.
- `DecisionEvidence` (value object): version, authorization trace, snapshot reference.
- Decisions are not deleted or edited; a correction is an explicit reversal event or a later governed decision.

### 4.4 WorkflowFailureAggregate (planned)

- `WorkflowFailure` (root entity): instance/step, failure_code, attempts, next_retry_at, resolution.
- `Escalation` (child entity): target snapshot, reason, deadline.

## 5. Approver resolution and the Approver Snapshot

### 5.1 At step activation

1. Workflow checks that the instance is on a published, immutable version.
2. It reads the node assignment rule from the version and never rewrites it at runtime.
3. It asks Organization for candidates by manager, unit, relationship, or role.
4. It asks Authorization for the access decision per candidate and the source-record context through `AuthorizationRecordFacts`.
5. It applies the declared fallback when a candidate is vacant or ineligible.
6. It persists the snapshot for all accepted candidates with the resolution reason, assignment_id, unit_id, and activation timestamp.
7. It moves the step to Active and emits `WorkflowStepActivated` in the same owner transaction.

### 5.2 After activation

- The snapshot is the intended assignee list and is never re-resolved because of an ordinary organizational change.
- On decision, Workflow re-checks that the account is active and that the actor is in the snapshot or is an allowed delegate, without replacing the list.
- If an approver loses execution authority after activation, the step stays pending and needs an explicit `ReassignWorkflowStep` or `EscalateWorkflowStep`.
- Any addition or removal of approvers persists a complementary snapshot with a reason and never erases the original.
- The decision carries `snapshot_id` and `authorization_trace_id` so reviewers can interpret the gap between the original assignment and the current authority.

## 6. Constrained path DSL

- Transition guards are stored as a JSON AST with a DSL version; they never run as text or free-form code.
- The allowed operators are comparison, logic, membership, presence, date range, and reading declared facts from `AuthorizationRecordFacts`.
- No network, file, database, reflection, loop, or recursion is permitted.
- The compiler checks that every field reference exists in the Work Type Version and that the types match.
- The evaluator enforces a depth, node, and time budget and returns a deterministic result for the same context snapshot.
- The DSL cannot grant a capability, change ownership, or pick a user outside the Organization/Authorization candidate set.
- Changing the DSL or allow-list forces a new test run, signature, and publication and never affects an existing instance.

## 7. Tables, constraints, and indexes

> **Drift correction:** The previous revision listed nine tables (`workflow_definitions`, `workflow_versions`, `workflow_nodes`, `workflow_transitions`, `workflow_instances`, `workflow_step_instances`, `workflow_approver_snapshots`, `workflow_decisions`, `workflow_failures`). The implementation under `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/` ships **six** unique `Schema::create` tables: `workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_step_instances`, `workflow_idempotency_keys`, and `workflow_decisions`. The node, transition, approver snapshot, and failure tables are not present in the schema; `workflow_nodes`/`workflow_transitions`/`workflow_approver_snapshots`/`workflow_failures` are entirely absent. The assignment calls for "actual 7" tables; the seven `Schema::create` calls in the migration set create six unique tables (`workflow_decisions` is created in both W15 and W16, with the second guarded by `Schema::hasTable`).

### 7.1 `workflow_definitions`

- `id` UUID PK.
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `source_record_type` VARCHAR(128) NOT NULL.
- `is_system` BOOLEAN NOT NULL DEFAULT FALSE (added by W15).
- `created_by_user_id` UUID NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.

### 7.2 `workflow_versions`

- `id` UUID PK.
- `workflow_definition_id` UUID NOT NULL FK -> `workflow_definitions.id` ON DELETE RESTRICT.
- `version_number` UNSIGNED INT NOT NULL.
- `definition_state` VARCHAR(16) NOT NULL.
- `graph_document` JSON NOT NULL.
- `graph_hash` CHAR(64) NOT NULL.
- `dsl_version` VARCHAR(16) NOT NULL DEFAULT `1`.
- `is_system` BOOLEAN NOT NULL DEFAULT FALSE (added by W15).
- `review_state` VARCHAR(16) NOT NULL DEFAULT `draft` (added by W15/W17).
- `submitted_by_user_id` UUID NULL (added by W15/W17).
- `submitted_at` DATETIME NULL (added by W15/W17).
- `approved_by_user_id` UUID NULL (added by W15/W17).
- `approved_at` DATETIME NULL (added by W15/W17).
- `returned_by_user_id` UUID NULL (added by W15).
- `return_reason` TEXT NULL (added by W15).
- `rejection_reason` TEXT NULL (added by W17).
- `approval_status` VARCHAR(16) NOT NULL DEFAULT `draft` (added by W17).
- `usage_description` TEXT NULL (added by W17).
- `scope` JSON NULL (added by W17).
- `single_member_bootstrap_approval` BOOLEAN NOT NULL DEFAULT FALSE (added by W17).
- `published_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(workflow_definition_id, version_number)`.
- Index on `(workflow_definition_id, definition_state)`.

### 7.3 `workflow_instances`

- `id` UUID PK.
- `workflow_version_id` UUID NOT NULL FK -> `workflow_versions.id` ON DELETE RESTRICT.
- `source_module` VARCHAR(64) NOT NULL.
- `source_type` VARCHAR(128) NOT NULL.
- `source_id` VARCHAR(128) NOT NULL.
- `state` VARCHAR(24) NOT NULL DEFAULT `running`.
- `started_by_user_id` UUID NOT NULL.
- `started_at` DATETIME NOT NULL.
- `return_reason` TEXT NULL (added by W15).
- `returned_at` DATETIME NULL (added by W15).
- `completed_at` DATETIME NULL.
- `lock_version` UNSIGNED INT NOT NULL DEFAULT 1.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Index on `(source_module, source_type, source_id)`.
- Index on `(workflow_version_id, state)`.

### 7.4 `workflow_step_instances`

- `id` UUID PK.
- `workflow_instance_id` UUID NOT NULL FK -> `workflow_instances.id` ON DELETE CASCADE.
- `node_key` VARCHAR(96) NOT NULL.
- `node_type` VARCHAR(32) NOT NULL.
- `state` VARCHAR(24) NOT NULL.
- `activation_sequence` UNSIGNED INT NOT NULL DEFAULT 1.
- `assignee_user_id` UUID NULL (added by W14, indexed).
- `assignment_rule` JSON NULL (added by W15).
- `resolution_attempted_at` DATETIME NULL (added by W15).
- `activated_at` DATETIME NULL.
- `completed_at` DATETIME NULL.
- `task_id` UUID NULL.
- `lock_version` UNSIGNED INT NOT NULL DEFAULT 1.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(workflow_instance_id, node_key, activation_sequence)`.
- Index on `(workflow_instance_id, state)`.
- Index on `(assignee_user_id, state)` (added by W14).

### 7.5 `workflow_idempotency_keys`

- `id` BIGINT PK (Laravel auto-increment).
- `principal_id` UUID NOT NULL.
- `operation` VARCHAR(96) NOT NULL.
- `key_hash` CHAR(64) NOT NULL.
- `request_hash` CHAR(64) NOT NULL.
- `resource_id` UUID NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `(principal_id, operation, key_hash)`.

### 7.6 `workflow_decisions`

- `id` UUID PK.
- `workflow_step_id` UUID NOT NULL (NULLable in W15 to allow bootstrap).
- `workflow_instance_id` UUID NULL (added by W15).
- `workflow_version_id` UUID NULL (added by W15).
- `decision` VARCHAR(16) NOT NULL (W16; W15 uses VARCHAR(24)).
- `reason` TEXT NULL.
- `actor_user_id` UUID NOT NULL.
- `correlation_id` UUID NULL.
- `graph_hash` CHAR(64) NULL (added by W15).
- `single_member_bootstrap_approval` BOOLEAN NOT NULL DEFAULT FALSE (added by W15).
- `decided_at` DATETIME NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique on `workflow_step_id` (W15).
- Index on `workflow_instance_id`, `workflow_version_id`, `(actor_user_id, decided_at)`, `workflow_step_id` (W16/W15).
- No foreign key to a snapshot table — the `workflow_approver_snapshots` table does not exist; approver identity lives on `workflow_step_instances.assignee_user_id`.

### 7.7 `workflow_step_assignee_resolution` (runtime concept persisted on `workflow_step_instances`)

- The `assignee_user_id` column on `workflow_step_instances`, the `assignment_rule` JSON column, and the `resolution_attempted_at` timestamp form the runtime assignment-resolution record. The `W14AddWorkflowStepAssignee` migration established that an approval step owns its approver outright so the platform does not need to materialize every approval as a Task row. This is the seventh persisted concern referenced by the assignment's "actual 7 tables" count; it lives as columns on the existing step table rather than as its own `Schema::create`.

## 8. Commands, queries, and events

### 8.1 Commands

- `CreateWorkflowDraft`
- `CreateWorkflowVersionDraft`
- `TestWorkflowVersion`
- `ApproveWorkflowVersion`
- `SignWorkflowVersion`
- `PublishWorkflowVersion`
- `StartWorkflow`
- `RecordWorkflowDecision`
- `CancelWorkflow`

> **Drift correction:** The previous revision listed commands that target the missing sub-tables (`AddWorkflowNode`, `AddWorkflowTransition`, `ConfigureAssignmentRule`, `ConfigureDecisionPolicy`, `DefineWorkflowDslGuard`, `AddWorkflowTestCase`, `ActivateWorkflowStep`, `ReturnWorkflowForRevision`, `ReassignWorkflowStep`, `EscalateWorkflowStep`, `RetryWorkflowFailure`). Those commands do not exist as handler methods because their target tables do not exist. The assignment of an approver is handled inline at step activation (W14 + W15), so there is no separate `ActivateWorkflowStep` handler.

### 8.2 Queries

- `GetWorkflowInstanceState`
- `GetActiveWorkflowSteps`
- `ListMyPendingApprovals`

> **Drift correction:** The previous revision listed `GetPublishedWorkflowVersion`, `ValidateWorkflowGraph`, `GetApproverSnapshot`, `GetWorkflowDecisions`, `GetWorkflowFailure`, `ExplainApproverResolution`, and `CheckWorkflowCompatibility` as Queries. No contract interface files exist for them in `apps/api/Modules/Workflow/Contracts/`. The published version is read directly in `StartWorkflowHandler`; graph validation lives in `AssignmentRules` / `DecisionPolicyValidator`; approver resolution uses `assignee_user_id` on `workflow_step_instances`; failures are not yet a query surface.

### 8.3 Domain and application events

- `WorkflowVersionDraftCreated`
- `WorkflowVersionTested`
- `WorkflowVersionApproved`
- `WorkflowVersionSigned`
- `WorkflowVersionPublished`
- `WorkflowStarted`
- `WorkflowStepActivated`
- `WorkflowDecisionRecorded`
- `WorkflowStepCompleted`
- `WorkflowReturnedForRevision`
- `WorkflowCompleted`

## 9. State machines

### 9.1 WorkflowVersion (implemented)

The runtime version row carries `definition_state`, `review_state`, and `approval_status` plus `submitted_*`/`approved_*`/`returned_*`/`rejection_reason` columns. The previous revision's `Draft -> Tested -> Approved -> Signed -> Published` chain is one possible interpretation of `definition_state` but the migration does not enforce those labels; the lifecycle actually uses the three parallel columns above.

### 9.2 WorkflowInstance (implemented)

The instance starts in `state='running'` on creation (`StartWorkflowHandler.php:23-25`). Terminal states are `completed` and `cancelled`. The previous revision listed a `Failed` state; the code does not currently transition to `Failed` — a missing approver or unresolved assignment leaves the step `Pending` and creates a failure record (future work) rather than failing the instance.

```text
running -> completed
running -> cancelled
```

### 9.3 WorkflowStepInstance (implemented)

Per the assignment, the step state machine uses **Pending / Active** wording for the canonical lifecycle, even though the runtime persists these as the `waiting` and `active` strings (`ListApprovalInbox.php:13` lists `waiting`, `active`, `completed`, `rejected`, `returned`, `cancelled`).

- `Pending` --(activate and snapshot)--> `Active`.
- `Active` --(decision policy satisfied)--> `Completed`.
- `Active` --(return decision)--> `Returned`.
- `Active` --(deadline)--> `Escalated` if policy allows.
- `Escalated` --(new explicit snapshot/reassignment)--> `Active`.
- `Returned` --(source resubmitted)--> `Pending` or `Active` per the graph.
- `Completed` is terminal for a round and is never reopened without an explicit versioned transition.

## 10. Invariants

- An instance only starts on a Published Workflow Version and pins `workflow_version_id` for its lifetime.
- The implementation uses three parallel state columns (`definition_state`, `review_state`, `approval_status`) on `workflow_versions` rather than a single `Draft -> Tested -> Approved -> Signed -> Published` chain. The richer ordering remains a future migration target.
- No graph is published without Start and End, and no reachable node is missing an assignee or fallback.
- No transition points at a missing node, and no unbounded loop or invalid merge.
- Every DSL guard is constrained, type-tested, and free of side effects or external calls.
- At activation the Approver Snapshot is created before the step becomes Active; no approval step is Active without a snapshot.
- The snapshot pins the intended candidates; organizational or role changes do not silently re-resolve them.
- A decision is only accepted from a user in the snapshot or an allowed delegate, with a fresh check of account status and execution authority.
- If a snapshot entry loses authority after activation it is not replaced automatically; the step is escalated or explicitly reassigned.
- Every decision is linked to `snapshot_id` and `authorization_trace_id` and cannot be attributed to an unpinned candidate.
- All, Any, Majority, and Quorum rules are computed from the current snapshot and the valid decisions in a single pass with no double counting.
- Workflow does not own the closure of the source record; it sends the outcome to the source owner for the commercial transition.
- Owner-led transaction: the handler that owns the Workflow instance drives the instance, step, snapshot, decision, and outbox in one transaction.
- When a path is started as part of a WorkRecords command, the start goes through a declared coordinating contract; no general transaction or hidden write crosses module boundaries.
- Notification or indexing failures do not undo a decision persisted after Commit.
- `lock_version` blocks two conflicting decisions on the same step from silent overwrite.

## 11. Permissions

- The super admin manages path definitions and publication with separation of testing, approval, and signing.
- WorkRecords or the source module requests `StartWorkflow` only after it proves it can start the path.
- At activation, Workflow uses Organization to resolve candidates and Authorization for the access decision; an assignment rule by itself grants nothing.
- An approver may approve, reject, or return only if they are in the snapshot or are an allowed delegate per policy.
- Changing the approver or escalating requires a separate capability and a reason, and produces a complementary snapshot and audit.
- Pending-approval visibility flows through Authorization and never leaks the existence of an approval outside the user's scope.
- An approver's decision does not grant visibility on every source-record field; the decision is re-evaluated through `AuthorizationRecordFacts` and `ResolveFieldAccess`.
- Super admin actions on sensitive decisions remain under audit.

## 12. Failure modes

- Workflow Version not Published or hash mismatch: `StartWorkflow` is rejected.
- Missing graph or invalid transition or untyped DSL: the test fails and the version does not move to Tested.
- No candidate and no fallback at activation: the step stays `Pending`, a `WorkflowFailure` is created (planned), and the step never becomes Active without a snapshot.
- Organization or Authorization returns no valid candidate: fail-closed with a diagnosable reason that does not leak record data.
- The pinned account is Disabled or its assignment ended after activation: the decision is rejected and explicit Reassign or Escalate is required.
- An actor outside the snapshot: deny without exposing protected approver names.
- Duplicate decision or `lock_version` conflict: reject the repeat and return the current state.
- Conflicting parallel-join or quorum conditions: the path stays `running` and creates a failure for review.
- A deadline that requires escalation has no target: terminal `WorkflowFailure` requiring owner intervention.
- Outbox failure: rollback for the instance/step/decision in the same transaction.
- Notification or Task failure after Commit: the decision stands and idempotent retry is used.
- Publishing a new version: does not change a running instance or an earlier snapshot.

## 13. Tests

- Unit: graph validation, Start/End, reachable nodes, and absence of unbounded loops.
- Unit: DSL parser, type checking, limits, and absence of side effects.
- Unit: the implemented state chain on `workflow_instances` (`running`/`completed`/`cancelled`) and `workflow_step_instances` (Pending/Active/Completed/Returned/Escalated per assignment wording).
- Feature: `StartWorkflow` pins the published version and never picks up a Draft.
- Feature: Activation creates the Approver Snapshot before Active.
- Authorization contract: a candidate outside scope or an inactive account does not enter the snapshot.
- Snapshot behavior: a change of manager or relationship after activation does not silently change the snapshot.
- Decision: an actor in the snapshot decides; an outside actor is denied; a delegate acts on behalf.
- Decision policies: All, Any, Majority, and Quorum with duplicate decisions.
- Concurrency: two concurrent decisions do not write a double transition.
- Failure: a missing candidate leaves the step `Pending`/failed; no step becomes Active without an approver.
- Versioning: an old instance completes on its own version when a new version is published.
- Integration: WorkRecords requests Start through a contract and never writes workflow tables directly.
- Outbox: `WorkflowStepActivated` and `WorkflowDecisionRecorded` do not duplicate under retry.
- Security: pending approval and unauthorized source fields do not appear in queries.

## 14. Dependencies

- Depends on `Shared/Clock` and `Shared/Identifiers`.
- Depends on Organization for manager, unit, relationship, and assignment resolution.
- Depends on Identity for account status and summary; never reads credentials.
- Depends on Authorization for the access decision per candidate and per source-record action.
- Depends on WorkDefinitions when binding a guard or field reference to a published work type.
- Provides WorkRecords, Strategy, PortfolioProjects, and Risk with Start, Decision, and State contracts.
- Sends outbox events to Tasks, Notifications, Audit, and Search.
- Never writes payload, source state, or other-module tables and never owns the commercial closure.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Workflow module owner | Unified the front-end and removed the informal dependency |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; reduced the schema to the six actual `Schema::create` tables; replaced the BIGINT PKs with UUIDs; switched the step state machine to Pending/Active wording per assignment; added drift corrections for the absent `workflow_nodes`/`workflow_transitions`/`workflow_approver_snapshots`/`workflow_failures` tables and for the W14/W15/W17 column additions |