---
doc_id: PLN-APV-001
title: Requests and Approvals Module Plan
type: plans
status: draft
version: 2.0.0
date: 2026-07-22
owner: Software Engineering Lead
reviewers: []
classification: internal
review_cycle: After completing each phase
sources:
  - docs/contracts/api/openapi.yaml
references:
  - docs/plans/implementation-roadmap.md
  - docs/plans/active-delivery-status.md
---

# Requests and Approvals Module Plan

## 1. Governing Principle

### 1.1 Approval Is Not a Task

The current "Procedures and Workflow" module is in reality an **approvals module**. A
project request, a leave request, and a temporary assignment request are all approval
paths and have nothing to do with the task as a unit of work.

| Axis | Approval | Task |
|---|---|---|
| Question | Do I approve or not? | Did I finish it? |
| Outcome | A decision and reason that moves the path forward | Completed work that does not move anything |
| Collaboration | A single accountable decision | Participants, comments, and mentions |
| Table | `workflow_step_instances` | `tasks` |

An approval node produces a step that appears in the approvals inbox with no row in
`tasks`. The task remains a fully-fledged adjacent module, receiving work after the
approval, not before it.

### 1.2 The Path Is an Ordered List, Not a Graph

Approval chains inside facilities are linear in the vast majority of cases:

```
Assignment request:
  1. Direct manager         <- supervisor_of_initiator
  2. Department manager      <- supervisor_of_step(1)
  3. Human resources         <- role: hr_officer
```

The list is generated into `graph_document` with the same structure
(`start → 1 → 2 → 3 → end`), so the model stays extensible into a graph later
**with no migration and no breakage of in-flight requests**. This eliminates the two
heaviest pieces: a graph editor and a graph interpreter.

### 1.3 The Vocabulary Is Closed

There are three assignment rules and ten field types. Any need outside them is
handled by a new type with its own code and tests, not by extending the format. The
honest promise is not "zero code per request" but **zero code for the majority, and
code for the exception**.

### 1.4 Governance Is a Status Field, Not an Engine

Approving a workflow is a governance requirement: a party approves, an effect is
recorded, and a broadcast arrives. All three are met by a status field, a record,
and a notification. Running them on the same engine makes the system capable of
locking itself: if the governance runtime fails, no path can be published, including
the one that would fix the failure.

## 2. Measured State

| Capability | State | Evidence |
|---|---|---|
| Definitions, versions, and publishing | Built | `Modules/Workflow` |
| Pinning running versions to their version | Built | `workflow_instances.workflow_version_id` |
| Approval step ownership | Built | `W14AddWorkflowStepAssignee` migration |
| Administrative hierarchy and supervisory relations | Built | `Modules/Organization` |
| Notifications, events, and optimistic locking | Built | `notifications`, Outbox, `lock_version` |
| Step-to-step transition | Missing | `StartWorkflowHandler` creates a single step |
| Assignment rules | Missing | `decision_policy` is stored but not read |
| Version approval and broadcast | Missing | Publishing is a single-actor step |
| Approval and follow-up screens | Missing | Three creation screens and a display script |

Not a single real request has gone end to end as of this plan's date. This fact is
what governs the scope below.

## 3. The Two Cycles

The separation between them is the backbone of the design.

### First Cycle — Authoring the Procedure

Runs once per procedure inside the Operations Management Office, an independent body
at the cluster level and outside the organizational hierarchy. Its authority to
author and approve procedures is self-contained; it does not rely on a higher
manager and is not subject to `supervisor_of_initiator` or `supervisor_of_step`
rules.

```
A department requests a new procedure
        ↓
A member of the Operations Office designs the chain and submits it
        ↓
Another member of the office ──── returned with reason ──── back to author
        ↓ approved
     Published
        ↓
Broadcast notification + appears in the procedures directory
```

### Second Cycle — Using the Procedure

Runs every day; its owners are employees and managers.

```
An employee submits a request from the directory
        ↓
Direct manager → department manager → human resources
        ↓
     Completed

Any rejection ← request returns to the employee with the reason
```

### Ownership Split

| Piece | Table | Owner |
|---|---|---|
| Form fields | `work_definition_versions.schema_document` | Department proposes |
| Approval chain | `workflow_versions.graph_document` | Operations Office |
| Field and classification policy | `field_policy_key` · `default_classification` | Operations Office |
| Publishing decision | Version status | Another Operations Office member |

Field policy stays with the Office because it is a security decision, not a content
decision: the department says "I need a budget field", and the Office decides who
sees it.

## 4. Screens

| Screen | Audience | Cycle |
|---|---|---|
| Workflow administration | Operations Office | First |
| Workflow approvals | Authorized Operations Office reviewers | First |
| Procedures directory | Everyone | Bridge between them |
| My approvals | Every manager | Second |
| My requests | Every employee | Second |

The procedures directory is the connection point: what the first cycle produces
appears there, and the second cycle starts from it. It is necessary because the
notification is transient, and someone who joins six months later will not see it.

## 5. Phases

### Phase 0 — Approval Step Ownership (Complete)

Goal: separate approval from the task at the schema level.

Files: `Modules/Workflow/Infrastructure/Persistence/Migrations/W14AddWorkflowStepAssignee.php`,
`Modules/Workflow/Features/StartWorkflow/Handler/StartWorkflowHandler.php`,
`app/Http/Controllers/Api/WorkflowController.php`, `app/Providers/AppServiceProvider.php`.

What was delivered:

- The `assignee_user_id` column and the `[assignee_user_id, state]` index that
  serves the approvals inbox.
- A step takes its owner from the graph if it names one, otherwise the workflow
  initiator.
- The approval decision is restricted to the step owner, after which any holder of
  `workflow.decide` could approve anyone's step.
- Reassignment moves the same step. It used to require
  `workflow_step_instances.task_id`, which is a column no line in the repository
  writes, so the operation always returned `409`. Removing that also removed the
  Workflow module writing to the `tasks` table.

Tests: `Modules/Workflow/Tests/WorkflowStepAssigneeTest.php`,
`tests/Feature/WorkflowStepReassignHttpTest.php`.

Evidence: `php artisan test` at 417 tests, 412 passing and 5 previously skipped,
and `make verify-boundaries` green.

### Phase 1 — Platform Owner and Roles

Goal: create the Operations Management Office as an independent body at the cluster
level and secure bootstrap membership plus authoring and review capabilities
without creating an approval body above it.

Architectural decision: **no exception in code**. A line like
`if ($actor['is_super']) return allow` creates a second decision path that bypasses
explicit deny, classification policies, field access, and scope, and does not go
through `RbacAbacDecideAccess`, so it is not recorded in `access_decisions`. That
makes the strongest user the only one without audit trail. The database itself
rejects this direction: a constraint on `delegation_capabilities` blocks `*`, `?`,
and `%` in the capability code.

Acceptance criteria:

- The `platform_owner` role carries all capabilities explicitly, generated from
  `CapabilityCatalog::all()`. Decisions still pass through the same engine so they
  are audited and explained.
- Operations Office membership is a cluster-scoped assigned role, not a `user_id`
  column. The first user in the system, the platform owner, is assigned this role
  as the first member, and Office members can add other members through the
  governed role-assignment path.
- The `workflow.author` and `workflow.approve` capabilities are separate inside the
  Office: the first designs and submits the chain, the second approves it or
  returns it with a reason. One member may hold both, but holding both does not
  create a decision path outside `RbacAbacDecideAccess`.
- When the Office has two or more active members, the chain author is forbidden
  from approving it even if they hold both capabilities. Actual membership at
  decision time governs whether the ban is lifted or applied.
- During bootstrap with only one member, that member is allowed to approve their
  own version, but the decision is recorded with the explicit tag
  `single_member_bootstrap_approval` together with the adopted `graph_hash`, and
  emits an auditable notification. The exception lifts automatically once the
  second active member is added.
- An explicit deny on the platform owner from modifying `is_system`-tagged paths
  and from deleting audit records.
- **Circularity handling**: the platform owner holds `authorization.deny.manage`,
  so they can remove a deny placed on themselves. A deny on system paths is
  enforced at the system level, not through the interface, and is not managed
  through `authorization.deny.manage`.
- Emergency role assignment is temporary, with a written reason and a time window
  after which it ends on its own. `role_assignments` already enforces a valid
  window through a database constraint.
- Bootstrap uses a seed migration that creates the owner account, assigns them
  the platform-owner roles and Operations Office member role, and grants them
  authoring and approval capabilities through ordinary RBAC.

Tests: `Modules/Authorization/Tests/PlatformOwnerRoleTest.php` — the role covers
all of `CapabilityCatalog::all()` and any new capability without a grant fails the
test; explicit deny overrides allow; the platform owner does not remove a system
deny; adding Office members; self-approval prevention with two members; labeled
allow and notification for the single bootstrap member; automatic lifting of the
exception after the second member is added.

Verification command:

```
cd apps/api && php artisan test Modules/Authorization/Tests
make test-api
```

### Phase 2 — Linear Engine and Assignment Rules

Goal: the step advances to the next one, and its owner is known from the
organizational structure.

Acceptance criteria:

- An ordered list is generated into `graph_document` with the existing structure.
- Completing a step triggers the next one in order; exhausting the steps closes the
  run. The last part already lives in `WorkflowController` and moves to the engine.
- Rejection stops progress and returns the request to the submitter with the reason.
- Exactly three assignment rules: `supervisor_of_initiator`, `supervisor_of_step`,
  and `role`. The three cover "direct manager then department manager then human
  resources".
- Assignment happens through a contract with `Modules/Organization`, with no
  direct query or join.
- **Decision record**: the decision, its reason, owner, and time live in a
  queryable table. Today the reason lives only in an Outbox event, and the Outbox
  is consumed and may be trimmed, so the rejection reason disappears after months.
- Every transition writes its event in the same transaction, and the consumer is
  idempotent.

Tests: `Modules/Workflow/Tests/WorkflowEngineTest.php` — a three-step chain
completes end to end, rejection stops and returns it with the reason, and replaying
the event does not create a duplicate step.
`Modules/Workflow/Tests/AssignmentRulesTest.php` — each rule on its own, and an
unknown rule is rejected at publish time, not at run time.

Verification command:

```
cd apps/api && php artisan test Modules/Workflow/Tests Modules/Organization/Tests tests/Architecture/ModuleBoundariesTest.php
make verify-boundaries
make verify-day2
```

### Phase 3 — Procedure Cycle: Design, Approve, Broadcast

Goal: a member of the Office designs a procedure, another member reviews it, and
the published result reaches everyone without an executive approval layer outside
the Office.

Backend:

- Columns on `workflow_versions`: `submitted_by_user_id`, `submitted_at`,
  `approved_by_user_id`, `approved_at`, `rejection_reason`. A single approval, not
  a chain, so columns suffice and a separate table is not needed.
- Lifecycle `draft → pending_review → published`; return with a reason goes back to
  `draft`. The reviewer is an Operations Office member holding `workflow.approve`,
  not an executive manager and not an external party. The routes in
  `routes/web.php` accept `approve` already and the controller rejects it today.
- `usage_description` field: when the procedure is used and what its purpose is.
- Broadcast scope as a publish statement: everyone or a specific facility or roles.
- Notification distribution via an **asynchronous** Outbox consumer. `notifications`
  needs one row per recipient, so broadcasting to two thousand employees inside
  the publish request would freeze the interface and cause partial failures.
- **Atomic publishing**: the form version and the chain version publish together
  or neither does. A form without a chain means requests reach no one, and a
  chain without a form means a path without an entry.
- The approval decision saves `approved_by_user_id`, `approved_at`, and the adopted
  `graph_hash`. The bootstrap member's self-approval carries the explicit tag and
  emits a notification, and it does not stay available after a second active
  member exists in the Office.

Interface: workflow administration, workflow approvals, procedures directory.

Acceptance criteria:

- Every screen covers loading, empty, denied, error, and success states.
- Commands send `If-Match` and `Idempotency-Key` and handle `409` and `412` with
  Arabic messages.
- Screens work in RTL and LTR with correct titles and access roles.
- The notification explains the usage: the name, when it is used, the approval
  chain readable, and a submit link.
- The procedures directory shows only published entries for reading, and
  submission starts from there.

Tests: `tests/Feature/WorkflowApprovalLifecycleTest.php` — submit to
`pending_review`, internal approval, return with a reason, self-approval
prevention with two members, the labeled bootstrap exception with one member, the
exception lifting after the second member is added, and publishing atomicity when
one side fails.
`tests/Feature/WorkflowPublicationNoticeTest.php` — distribution runs after the
transaction and respects the audience scope. Plus unit tests for the three screens
and `apps/web/e2e/workflow-authoring.spec.ts`.

Verification command:

```
cd apps/api && php artisan test tests/Feature Modules/Workflow/Tests
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit
npm --prefix apps/web run lint
npm --prefix apps/web run build
make verify-screens
```

### Phase 4 — Request Cycle: Submit, Approve, Track

Goal: a real request goes end to end with real users.

Interface: My approvals, My requests, and "New Procedure Request".

The New Procedure Request screen has four sections: definition, the data to collect
as a three-column table, the proposed approval chain in organizational structure
language (not system language), and attachments. The second section is not a form
builder but a three-column layout with an add button, and the type is drawn from
the closed list of ten field types. The third section is a proposal the Office
reviews, which shortens the back-and-forth because both sides use the same
vocabulary.

Acceptance criteria:

- The approvals inbox shows only the current user's steps and does not leak
  other users' steps.
- The tracking screen shows where the request is, who holds it, and since when.
- "New Procedure Request" is defined as the first procedure in the system and
  runs on the same engine, and its operational steps reach the Operations Office
  as the owning body. Approving the procedure version itself stays as a status
  cycle inside the Office and does not add an external approval party or any
  special code.
- One real operational procedure works fully through to final approval.

Tests: `tests/Feature/ApprovalInboxHttpTest.php` — filtering, denial, and
pagination. Plus unit tests for the two screens and
`apps/web/e2e/requests-and-approvals.spec.ts` covering submit, approve, reject,
and track.

Verification command:

```
cd apps/api && php artisan test
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
make verify-screens
```

## 6. Measurement Gate

Do not start any phase after the fourth before running two real procedures on
real users and measuring:

| Indicator | What it means |
|---|---|
| Office setup time per procedure | If it exceeds one day the problem is in the editor, not the engine |
| Number of times the vocabulary is exceeded | If it exceeds twice in two procedures the vocabulary is too narrow |
| Number of back-and-forth rounds between the department and the Office | If it exceeds two the descriptive request model is incomplete |
| Office queue length and age of the oldest request | Early bottleneck indicator |

This gate is what prevents building a general engine for a need a table could
serve.

## 7. Deferred Until the Request Proves Itself

Each is a deliberate decision, not an oversight, and is not built until a real use
case asks for it:

| Deferred | Why |
|---|---|
| Drag-and-drop graph editor | Chains are linear in the vast majority of cases |
| Branches and conditions | Need an expression evaluator and bound field vocabulary |
| Variable-depth `escalation_chain` | "Manager then department manager" is fixed depth and written as two steps |
| Parallel approval | The first release is serial |
| Form builder for departments | Initial forms are defined manually until the change rate is proven |
| Governance path running on the engine | A status field is enough and avoids self-locking |
| Cluster and facility scope | Centralizing the Office solves branching structurally |
| `action` node on another module | Needed only when a path ends with an automatic effect |
| Automatic escalation on timeouts | Needs scheduling and monitoring, and need is measured from the queue |

## 8. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Power concentrated inside the Operations Office | The Office designs and approves without an external check | Separate author and approver when two members exist, full decision record, adopted `graph_hash` recorded, and every published version shown in the procedures directory |
| Signature without reading | Governance becomes theater | Show the chain readably on the approval screen and record the adopted `graph_hash` |
| Opening the vocabulary under pressure | A graph that cannot be read or tested | Every expansion is a new type with its own code and test, not a format extension |
| Lost rejection reason | Loss of governance trail | A queryable decision record, not an Outbox event |
| Returning to mixing approval and task | Repeating the original problem | `tasks.workflow_step_id` restricted to the tasks contract |
| Building a general engine for a limited need | Months wasted from one developer | The measurement gate in section 6 |

## 9. Settled Decisions

- Pinning running versions to their version already exists through
  `workflow_instances.workflow_version_id`, so editing a procedure does not break
  in-flight requests.
- Membership is an assigned role, not a `user_id` column, so someone's leave does
  not freeze procedure versions. The interface shows a name; the execution stays
  a role.
- The Operations Management Office is an independent body at the cluster level
  and is the final authority for approving workflow versions. There is no
  executive-manager approval layer or local facility-manager delegation above or
  in place of it.
- Tasks are not cancelled and not merged. They stay an adjacent module with their
  own participants and comments.
- The ordered list is stored in `graph_document` with the same structure, so an
  upgrade to a graph later requires no migration.

## 10. Verification Before Closing Any Phase

```
cd apps/api && php artisan test
composer --working-dir=apps/api lint
composer --working-dir=apps/api analyse -- --memory-limit=512M
make verify-boundaries
npm --prefix apps/web run lint
npm --prefix apps/web run build
./scripts/validate-docs.sh
```

A phase is not considered complete by a document; it is complete with working
code, a green test, and actual operational evidence, per the
[Active Delivery Status](active-delivery-status.md) and the
[Implementation Roadmap](implementation-roadmap.md).
