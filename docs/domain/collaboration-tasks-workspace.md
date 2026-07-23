---
doc_id: DOM-CTW-001
title: Collaboration, Tasks, and Workspace
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: Collaboration, Tasks, and Workspace modules owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/007-transactional-outbox.md
- docs/adr/017-derived-workspace-and-notifications.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

The Collaboration and Workspace modules do not exist under `apps/api/Modules/Collaboration/` or `apps/api/Modules/Workspace/`; only the Tasks module is implemented (`apps/api/Modules/Tasks/`). Tables, commands, and queries described here for Collaboration and Workspace are the target specification; the Tasks section reflects the tables that actually exist in code (`tasks`, `task_idempotency_keys`, `task_participants`, `task_comments`).

# Collaboration, Tasks, and Workspace

## 1. Purpose and scope

This domain provides general-purpose collaboration usable from any module without knowing its internals: tasks that are standalone or linked to a source record, a single assignee, participants, comments, mentions, cross-unit assignment with explicit acceptance, and optional completion approval. It also builds `Workspace` as a personal read model that aggregates what a user needs to act on: tasks, approvals, mentions, returned records, and overdue items.

This domain does not own request, project, or risk state, does not copy source fields, and does not make Workspace a source of truth. The source module remains the owner of the linked task's meaning, closure policy, and authorized source summary.

First-release boundaries:

- No subtasks.
- No conversation independent of a task or work record.
- No collaboration channels outside the platform.
- Cross-unit assignment to the current responsible unit or the creator requires explicit candidate acceptance, even when the assignment capability is held.

## 2. Terms and models

| Term | Definition |
|---|---|
| Task | A single unit of work with one confirmed assignee after activation. |
| SourceReference | An optional generic reference: `module_code`, `record_type`, and `record_id`, with no FK to a business table. |
| Assignee | The user responsible for execution; only one confirmed assignee at any moment. |
| Participant | A user who can see the task and comment, attach, and mention per the task's policy. |
| AssignmentOffer | A cross-unit assignment offer that does not change responsibility until it is accepted. |
| CompletionPolicy | Direct closure or completion submitted to a policy-resolved approver. |
| Mention | A reference to a user in a comment; it adds them as a participant if allowed, but does not grant them access to the source. |
| WorkspaceItem | A derived work candidate for a user; it does not represent operational truth or a final access decision. |

### 2.1 Aggregates

- `TaskAggregate`: the task, assignee, completion policy, status, priority, due date, classification, and source reference.
- `AssignmentOfferAggregate`: the candidate, the sending and receiving units, the duration, the decision and reason.
- `TaskConversationAggregate`: participants, comments, mentions, and reference attachments.
- `WorkspaceProjection`: a derived read projection per user, outside source-fact transactions.

### 2.2 Value Objects

- `TaskId`, `SourceReference`, `OrganizationScope`, `TaskPriority`, `DueAt`.
- `CompletionPolicy`: `direct` or `requires_acceptance`, with an approver-resolution strategy.
- `AssignmentContext`: the unit the assignee works from and the candidate's home unit.
- `AuthorizationRecordFacts`: the task facts the module presents to Authorization, with no local allow, deny, or field decision.

## 3. Data ownership and tables

All timestamps are stored in UTC and rendered in `Asia/Riyadh`. The tables below are owned by the module; no business module reads them directly.

> **Implementation note.** Only four Tasks tables currently exist in code: `tasks`, `task_idempotency_keys`, `task_participants`, `task_comments` (`apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/CreateTasksTable.php`, `W13CreateTaskEngagementTables.php`). The full table set for the wider domain (Collaboration offers, mentions, activity, Workspace) is the planned specification.

### 3.1 `tasks` (implemented)

- `id` CHAR(36) UUID PK.
- `title` VARCHAR(255) NOT NULL.
- `description` TEXT NULL.
- `created_by_user_id` CHAR(36) UUID NOT NULL.
- `assignee_user_id` CHAR(36) UUID NOT NULL; assigned at creation once a candidate is confirmed.
- `owner_organization_unit_id` CHAR(36) UUID NULL.
- `workflow_step_id` CHAR(36) UUID NULL UNIQUE.
- `status` VARCHAR(32) NOT NULL.
- `priority` VARCHAR(16) NOT NULL DEFAULT `normal`.
- `due_at` TIMESTAMP NULL.
- `classification` VARCHAR(24) NOT NULL DEFAULT `internal`: `public|internal|confidential|top_secret`.
- `completion_policy` VARCHAR(32) NOT NULL DEFAULT `direct`.
- `source_module` VARCHAR(64) NULL, `source_type` VARCHAR(128) NULL, `source_id` VARCHAR(128) NULL.
- `lock_version` INT UNSIGNED NOT NULL DEFAULT 1.
- `completed_at` TIMESTAMP NULL, `created_at`/`updated_at` TIMESTAMP NOT NULL.
- Constraint: `source_*` columns are either all NULL or all NOT NULL.
- Indexes: `(assignee_user_id, status, due_at)`; `status`; `due_at`.

### 3.2 `task_idempotency_keys` (implemented)

- `id` BIGINT PK.
- `principal_id` CHAR(36) UUID NOT NULL.
- `operation` VARCHAR(96) NOT NULL.
- `key_hash` CHAR(64) NOT NULL.
- `request_hash` CHAR(64) NOT NULL.
- `task_id` CHAR(36) UUID NOT NULL.
- `created_at`/`updated_at` TIMESTAMPS.
- Unique: `(principal_id, operation, key_hash)`.

### 3.3 `task_participants` (implemented)

- `id` CHAR(36) UUID PK.
- `task_id` CHAR(36) UUID NOT NULL.
- `user_id` CHAR(36) UUID NOT NULL.
- `role` VARCHAR(64) NOT NULL DEFAULT `participant`.
- `added_by_user_id` CHAR(36) UUID NOT NULL.
- `created_at`/`updated_at` TIMESTAMPS.
- Unique on active membership: `(task_id, user_id)`.
- Participation grants access to the task only and never to the source record.

### 3.4 `task_comments` (implemented)

- `id` CHAR(36) UUID PK.
- `task_id` CHAR(36) UUID NOT NULL.
- `author_user_id` CHAR(36) UUID NOT NULL.
- `body` TEXT NOT NULL.
- `mentioned_user_ids` JSON NULL.
- `created_at` TIMESTAMP(3) NOT NULL.
- No hard delete from the UI; activity keeps the trail and the actor.
- Index: `(task_id, created_at)`.

### 3.5 `task_assignment_offers` (planned)

- `id` CHAR(36) UUID PK; `task_id` CHAR(36) UUID NOT NULL FK.
- `candidate_user_id` CHAR(36) UUID NOT NULL.
- `from_organization_unit_id` and `to_organization_unit_id` CHAR(36) UUID NOT NULL.
- `offered_by_user_id` CHAR(36) UUID NOT NULL.
- `status` VARCHAR(24) NOT NULL: `pending|accepted|rejected|expired|cancelled`.
- `expires_at` TIMESTAMP NULL.
- `decision_reason` VARCHAR(1000) NULL.
- `decided_at` TIMESTAMP NULL, `created_at` TIMESTAMP NOT NULL.
- Logical partial unique constraint: only one `pending` offer per task.
- Indexes: `(candidate_user_id, status, expires_at)`, `(task_id, created_at)`.

### 3.6 `task_mentions` (planned)

- `id` CHAR(36) UUID PK, `task_id` CHAR(36) UUID NOT NULL, `comment_id` CHAR(36) UUID NOT NULL.
- `mentioned_user_id` CHAR(36) UUID NOT NULL, `mentioned_by_user_id` CHAR(36) UUID NOT NULL.
- `created_at` TIMESTAMP NOT NULL.
- Unique: `(comment_id, mentioned_user_id)`.

### 3.7 `task_activity` (planned)

- `id` CHAR(36) UUID PK, `task_id` CHAR(36) UUID NOT NULL.
- `activity_type` VARCHAR(64) NOT NULL, `actor_user_id` CHAR(36) UUID NULL.
- `before_payload` JSON NULL, `after_payload` JSON NULL, `reason` VARCHAR(1000) NULL.
- `occurred_at` TIMESTAMP NOT NULL, `event_id` CHAR(36) UNIQUE NOT NULL.
- Append-only; no Update or Delete from the application layer.

### 3.8 `workspace_items` (planned)

- `id` CHAR(36) UUID PK, `user_id` CHAR(36) UUID NOT NULL.
- `item_key` VARCHAR(255) NOT NULL; a stable key from the source.
- `source_module`, `source_type` VARCHAR(64) NOT NULL, `source_id` VARCHAR(128) NOT NULL.
- `item_kind` VARCHAR(32) NOT NULL: `task|approval|mention|returned_record|overdue|assignment_offer`.
- `action_code` VARCHAR(64) NOT NULL.
- `priority` VARCHAR(16) NOT NULL, `due_at` TIMESTAMP NULL.
- `source_version` BIGINT NULL, `projection_status` VARCHAR(16) NOT NULL.
- `safe_label_key` VARCHAR(128) NOT NULL; a generic translation key only; never stores a sensitive title.
- `created_at`, `updated_at` TIMESTAMP NOT NULL, `resolved_at` TIMESTAMP NULL.
- Unique: `(user_id, item_key)`.
- Indexes: `(user_id, projection_status, due_at)`, `(source_module, source_type, source_id)`.

### 3.9 `workspace_projection_checkpoints` (planned)

- `consumer_name` VARCHAR(128) PK, `last_event_id` CHAR(36) NULL.
- `last_occurred_at`, `updated_at` TIMESTAMP NULL, `lag_seconds` INT NOT NULL DEFAULT 0.

## 4. Contracts

### 4.1 Commands

- `CreateStandaloneTask(command): TaskId`.
- `CreateLinkedTask(command, SourceReference, SourceTaskPolicy): TaskId`.
- `AssignTask(taskId, assigneeId, actingScope, expectedVersion)`.
- `OfferCrossUnitTaskAssignment(taskId, candidateId, expiresAt)`.
- `AcceptCrossUnitTaskAssignment(offerId, candidateId)`.
- `RejectCrossUnitTaskAssignment(offerId, candidateId, reason)`.
- `AddTaskParticipant(taskId, userId)` and `RemoveTaskParticipant`.
- `AddTaskComment(taskId, body, mentionedUserIds[])`.
- `ChangeTaskDueDate`, `ChangeTaskPriority`, `BlockTask`, `UnblockTask`.
- `SubmitTaskCompletion(taskId, evidenceDocumentIds[], note)`.
- `AcceptTaskCompletion(taskId, decisionId)` and `ReturnTaskCompletion(taskId, decisionId, reason)`.
- `CompleteTaskDirectly(taskId)` and `CancelTask(taskId, reason)`.

Every Command carries `actor_user_id`, `acting_organization_unit_id`, `idempotency_key`, and `expected_lock_version` on edits.

### 4.2 Queries

- `GetTask(taskId, actorContext): AuthorizedTaskView`.
- `ListMyTasks(actorContext, filters, cursor)`.
- `ListTasksForOrganizationScope(actorContext, scope, filters, cursor)`.
- `ListPendingAssignmentOffers(actorContext)`.
- `ListTaskActivity(taskId, actorContext)`.
- `BuildMyWorkspace(actorContext, filters, cursor)`.
- `CountMyWorkspaceItems(actorContext)`.

`BuildMyWorkspace` re-checks every candidate via Authorization and the source-summary contracts, and removes or hides the candidate when it expires. It never returns Eloquent models or titles from the projection before allowing the access.

### 4.3 Contracts provided by the module

- `CreateTask` for source-owning modules.
- `GetTaskSummary`.
- `GetTasksBySourceReference`.
- `ResolveTaskParticipation`.
- `ProjectWorkspaceCandidate` and `ResolveWorkspaceCandidate`.

### 4.4 Contracts required from the source

Every module that allows linked tasks implements:

- `GetAuthorizationRecordFacts(source): AuthorizationRecordFacts`; the only access contract the owner provides.
- `ResolveSourceCompletionPolicy(source)`.
- `ValidateTaskSourceExists(source)`.

Passing `AuthorizationRecordFacts` to `DecideAccess` and issuing Allow/Deny plus field decisions is Authorization's responsibility alone. Workspace never returns the source's title or summary; it exposes the owner's endpoint that returns the decision from current facts. A source contract outage produces a safe-deny, not a temporary grant.

## 5. Events

Events are written in the Transactional Outbox with `event_id`, `event_type`, `occurred_at`, `schema_version`, and a minimal payload with no sensitive text.

- `TaskCreated`
- `TaskAssignmentOffered`
- `TaskAssignmentAccepted`
- `TaskAssignmentRejected`
- `TaskAssigned`
- `TaskParticipantAdded`
- `TaskParticipantRemoved`
- `TaskCommentAdded`
- `TaskParticipantMentioned`
- `TaskStarted`
- `TaskBlocked`
- `TaskUnblocked`
- `TaskCompletionSubmitted`
- `TaskCompletionReturned`
- `TaskCompleted`
- `TaskCancelled`
- `WorkspaceCandidateAdded`
- `WorkspaceCandidateResolved`

Consumers are idempotent on `event_id`. A notification or Workspace projection failure does not roll back task truth.

## 6. States and transitions

### 6.1 Task

```text
PendingAssignmentAcceptance -> Open: first cross-unit assignment is accepted
PendingAssignmentAcceptance -> Cancelled: no replacement is chosen, or authorized cancellation
Open -> InProgress: the confirmed assignee starts
Open -> Cancelled: authorized cancellation
InProgress -> Blocked: a blocker is recorded with a reason
Blocked -> InProgress: the blocker is cleared
InProgress -> PendingAcceptance: completion is submitted when completion_policy=requires_acceptance
PendingAcceptance -> InProgress: completion is returned with a reason
PendingAcceptance -> Completed: the resolved approver approves
InProgress -> Completed: direct closure when completion_policy=direct
Completed | Cancelled: terminal
```

`PendingAssignmentAcceptance` concerns assignment acceptance; `PendingAcceptance` concerns completion approval; the two must not be conflated in the API or the UI.

### 6.2 AssignmentOffer

```text
Pending -> Accepted | Rejected | Expired | Cancelled
```

Accepting the offer commits the assignee and adds them as a participant in a single transaction. On reassignment, the current assignee remains responsible until the new candidate accepts.

### 6.3 WorkspaceItem

```text
Active -> Resolved
Active -> Suppressed: access is denied, or the source is cancelled
Resolved | Suppressed -> Active: a newer source event reopens the work
```

## 7. Invariants

- A running task has exactly one confirmed assignee; it never enters `InProgress` without an assignee.
- Cross-unit assignment is not effective before the candidate accepts, and the current assignee cannot accept the offer on their behalf.
- The cross-unit assignment capability allows the offer to be created only and does not waive the acceptance requirement.
- A mention adds the user as a task participant when allowed, but does not grant any capability on `SourceReference` or expose the source title or its fields.
- Seeing a linked task does not mean seeing the source; the source is re-checked on open.
- A participant can comment, attach, and mention, but cannot change assignee, status, due date, or priority without an independent capability.
- The completion policy is pinned at creation; a later source policy change does not silently change a running task.
- `AcceptTaskCompletion` is executed only by the resolved approver or a valid delegate. A super admin does not approve on behalf of the approver merely because they hold an admin role.
- The submitter cannot approve their own completion if the policy separates performer from approver.
- There is no parent/child relationship between tasks in the first release.
- Workspace is derived and eventually consistent; sources and contracts are the truth.
- Any status, date, assignee, or priority change is recorded in `task_activity` and emits exactly one event.

## 8. Security and authorization

- Every write begins with `DecideAccess` using capability, scope, relationship, classification, status, and the active assignment.
- Tasks build `AuthorizationRecordFacts` from the task's classification and participation, then apply the Authorization decision; it decides access locally never and never folds in source access automatically.
- Opening a source link requires fresh `AuthorizationRecordFacts` from the source module and a fresh decision from Authorization.
- Bodies and attachments inherit at least the task's classification; documents apply their strictest restrictions and links per the Documents specification.
- Mention and Workspace events carry no source title or sensitive excerpt.
- Audit records cross-unit assignment, offer acceptance/rejection, assignee changes, approvals, delegations, and access to sensitive content.
- Delegations have a duration and a module and show the actor and the capability owner; a delegation prohibited by policy is never permitted.
- List queries apply central scope filters and field access and never rely on React-side hiding.
- All edits use `lock_version` to prevent silent overwrite.

## 9. Failure and recovery

- Inactive candidate or expired assignment: the offer is rejected before save.
- Offer expiry: it cannot be accepted; a new offer is required.
- Rejection of the first assignment: the task returns to the creator as an item needing reassignment, or is cancelled per policy.
- Approver-resolution failure on completion submission: the task stays `InProgress` with an interpretable message; a super admin is never substituted.
- Source outage on open or on Workspace build: safe-deny with a generic item that carries no sensitive title, then retry.
- Duplicate event: the consumer ignores it after checking `event_id`.
- Workspace or notification failure: the task remains correct; retry applies, then a reviewable dead-letter.
- `lock_version` conflict: returns `409 Conflict` with the current version and the user's input is preserved.
- Outbox failure: the entire task-change transaction is rolled back.
- Attachment check failure: the comment is not exposed as available until Documents declares the version safe.

## 10. Tests and acceptance criteria

### 10.1 Domain tests

- A task does not move to `InProgress` without a confirmed assignee.
- Within-unit assignment succeeds directly for the authorized actor.
- Cross-unit assignment creates an offer and does not change the assignee before acceptance.
- Acceptance changes the assignee exactly once; rejection does not change it.
- A participant cannot change the assignee or status without the capability.
- Direct closure is denied when policy requires approval.
- A super admin who is not the resolved approver cannot approve completion.

### 10.2 Security and isolation tests

- A mention of a user from another hospital grants the task only; they do not gain source access, the source title, or its fields.
- A user who holds the task but not the source sees a link they cannot open without source data.
- Expiry of a supervisory relationship removes the related Workspace items on the next read.
- A confidential field or comment never appears to a participant without the required classification.
- Search and reporting on tasks apply the same policy.

### 10.3 Contract and event tests

- Contract test for every supported source contract.
- Schema test for every event and rejection of payloads containing source text.
- Outbox test confirms one event per commit and zero events on rollback.
- Idempotency test on replaying `TaskAssignmentAccepted` and `TaskCompleted`.
- Projection test rebuilds Workspace from events.

### 10.4 Journey tests

- A manager assigns an employee in their unit; the employee starts and completes directly.
- A cluster responsible presents a task to a hospital employee; the employee accepts it, and it then appears in their workspace.
- An employee mentions a colleague; the colleague joins and comments without opening the source work record.
- A task linked to a project is submitted for approval, and the resolved approver, not the admin, approves it.
- A Workspace worker failure followed by restart neither loses nor duplicates an item.

## 11. Dependencies and integration boundaries

- Depends on Organization to resolve units, managers, and relationships, and on Identity for user summary and account state.
- Depends on Authorization for capability, scope, classification, and delegation decisions.
- Depends on Documents for attachments using identifiers and contracts only, and on Audit for sensitive actions.
- Notifications consumes its events to generate in-platform notifications.
- Search and Reporting consume its events to build governed projections.
- WorkRecords, PortfolioProjects, and Risk use the `CreateTask` contract and never read task tables.
- Does not depend on the internals of any business module; source integration runs through `SourceReference` and the published contracts.
- Workspace never writes to source tables and is not used as a reference for approval or status changes.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Collaboration, Tasks, and Workspace modules owner | Unified the front-end and removed informal naming |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; replaced non-existent tables/columns with the four implemented Tasks tables; banner added to mark Collaboration and Workspace as planned |
