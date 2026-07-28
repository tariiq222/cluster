# Cluster Task-Only Workspace Design

> **Date:** 2026-07-28  
> **Design status:** Approved through the current user decision session  
> **Implementation status:** Planned  
> **Source of API truth:** `docs/contracts/api/openapi.yaml`

## 1. Decision

Cluster will temporarily operate without Work Definitions, Work Records, request submission, approval inboxes, or Workflow execution.

Those modules and their data remain in the repository and database, but the `work-management` feature is disabled centrally:

- normal users cannot discover or invoke the disabled capabilities;
- the web application does not render their routes, navigation, dashboard sections, or calls;
- new writes and lifecycle actions are rejected by the API with a stable `feature-disabled` problem;
- historical reads remain available only to an explicitly authorized administrative reader;
- re-enabling the feature later does not require restoring deleted code or schemas.

Tasks remain enabled as an independent module and become the only current operational work journey.

## 2. Goals

1. Disable all current use of Work Definitions, Work Records, request journeys, approvals, and Workflow without deleting their code or data.
2. Make Tasks independent from Workflow at the route, dependency-injection, persistence-service, API-client, and UI layers.
3. Deliver one complete Arabic RTL and English LTR task journey:
   - create a self-task;
   - allow a manager with explicit authority to assign within the manager's team;
   - start, block with a reason, unblock, complete with a required note, or cancel with a reason;
   - reassign within the manager's team;
   - add participants, comments, mentions, and document attachments;
   - notify relevant users inside Cluster after every meaningful mutation.
4. Preserve the authoritative OpenAPI/Orval workflow and regenerate the API reference from the one master contract.
5. Prove the result through API, architecture, web unit, generated-client, and Playwright journey tests.

## 3. Non-goals

- Deleting disabled modules, tables, migrations, contracts, or historical data.
- Migrating or completing existing Workflow instances.
- Retaining any active request catalogue, request form, approval, or Work Record UI.
- Supporting task completion acceptance or review.
- Supporting multiple task assignees.
- Email, SMS, or push notifications.
- Allowing participants to change task state, assignee, priority, or due date.
- Rebuilding Workflow under a different name.

## 4. Feature availability

The API owns a central feature projection with:

```json
{
  "work_management": false,
  "tasks": true
}
```

The server source is `apps/api/config/features.php`, with
`CLUSTER_WORK_MANAGEMENT_ENABLED=false` as the default. Production may change
the environment value only through the normal deployment configuration; the
browser cannot override it.

The value is returned with the authenticated principal projection and is enforced independently on the server. The browser projection is only a rendering hint; it is not an authorization boundary.

`openapi.yaml` keeps the disabled operations and declares a top-level `x-feature-gates` entry plus `x-feature-gate: work-management` on Work Definitions, Work Records, request, approval, and Workflow operations. These operations are implemented but unavailable while the gate is disabled; they are not relabelled `planned`.

When disabled:

- mutations return HTTP `409 application/problem+json` with type `urn:cluster:problem:feature-disabled`;
- normal reads return the same non-disclosing `404` used for unavailable resources;
- historical-administration reads require the dedicated
  `work_management.history.read` capability and remain read-only;
- no disabled command emits audit, outbox, notification, or persistence side effects.

## 5. Task ownership and permissions

Each task has exactly:

- one creator;
- one assignee;
- one owning organization unit;
- zero or more participants;
- zero or more comments and linked documents.

Rules:

- an employee with `tasks.create` may create a task for themselves;
- assigning or reassigning another employee requires `tasks.assign`;
- the target must be inside a team/scope the actor is allowed to manage;
- an assignee may start, block, unblock, and complete the task;
- the creator may unblock the task;
- the creator or an authorized manager may cancel it;
- only the creator or an authorized manager may reassign it;
- participants may read, comment, mention, and attach documents only.

Record-level authorization is evaluated by the server for every read and mutation. A user cannot discover task titles, comments, participants, or attachment metadata outside their authorized relationship to the task.

## 6. Task lifecycle

The only lifecycle is:

```text
open -> in_progress -> blocked -> in_progress -> completed
  \          \            \                         \
   +----------+------------+-------------------------> cancelled
```

Allowed transitions:

| Current state | Action | Next state | Required input |
|---|---|---|---|
| `open` | `start` | `in_progress` | none |
| `in_progress` | `block` | `blocked` | non-empty reason |
| `blocked` | `unblock` | `in_progress` | none |
| `in_progress` | `complete` | `completed` | non-empty completion note |
| `open`, `in_progress`, `blocked` | `cancel` | `cancelled` | non-empty reason |

`completed` and `cancelled` are terminal. Blocking does not pause or alter `due_at`; overdue calculation continues normally.

The `completion_policy` input and the `requires_acceptance`, `submit-completion`, `accept-completion`, `return-completion`, and legacy `return` behaviors are removed from the active task contract.

Every mutation requires optimistic concurrency through `If-Match`. Commands additionally require `Idempotency-Key` where the current command pattern requires it.

## 7. Task independence from Workflow

The following Workflow bridge is disabled and removed from the active task surface:

- `POST /tasks/from-step/{stepId}`;
- `CreateTaskFromWorkflowStepHandler` as the general task creator;
- the `WorkflowStepExists` dependency from `TaskHttpStore`;
- active writes to `workflow_step_id`;
- `source_module=workflow` creation behavior;
- frontend helpers and links that create or open a task from a Workflow step.

Historical nullable source columns may remain in the database for backward-compatible reads, but no active Task service depends on Workflow. General creation moves to a Task-owned `CreateTaskHandler`.

The dynamic action parameter is named `taskAction`, not `workflowTaskAction`.

## 8. Task API

Active operations:

- `GET /tasks` — lists tasks visible to the principal as creator, assignee, or participant; supports state and relationship filters.
- `POST /tasks` — creates a self-task or authorized team assignment.
- `GET /tasks/{taskId}` — returns task details, allowed actions, participants, comments summary, attachments, and strong ETag.
- `PATCH /tasks/{taskId}` — edits mutable fields and performs authorized reassignment.
- `POST /tasks/{taskId}/{taskAction}` — `start`, `block`, `unblock`, `complete`, or `cancel`.
- `POST /tasks/{taskId}/participants` — adds an authorized participant.
- `GET|POST /tasks/{taskId}/comments` — lists or creates comments and mentions.
- `POST /tasks/{taskId}/documents` — links an already-authorized document to the task.

The task response exposes server-computed `allowed_actions`; the UI does not infer authority from status alone.

## 9. Notifications

Notifications are in-app only.

Every successful meaningful mutation creates notifications in the same transaction/outbox boundary as the task change:

- creation and assignment;
- reassignment;
- participant addition;
- document attachment;
- comment and mention;
- start, block, unblock, complete, and cancel;
- title, priority, or due-date update.

Recipients are the creator, assignee, and all participants, excluding the actor who performed the change. Mentioned users are included when authorized. Recipient IDs are deduplicated, and a denied or stale mutation emits no notification.

Notification content uses safe task metadata and does not include restricted comment or document content.

## 10. Web experience

The active web shell removes request, Work Definition, Workflow administration, approval inbox, and request-tracking navigation. The dashboard stops loading or rendering approvals and requests.

The Tasks experience contains:

- task list with `all`, `assigned to me`, `created by me`, `participating`, `open`, `blocked`, `completed`, and `cancelled` filters;
- create-task form with title, description, assignee, priority, due date, classification, and participants;
- task detail with state, creator, assignee, due state, participants, attachments, and comments;
- action forms that require a block/cancel reason or completion note;
- reassignment restricted to authorized team members;
- loading, empty, forbidden, not-found, stale/conflict, validation, and generic error states;
- Arabic RTL and English LTR copy, keyboard navigation, focus restoration, live-region feedback, labels, and accessible dialogs.

No screen calls Workflow or Work Record APIs when `work_management` is disabled.

## 11. Error and concurrency behavior

- `400` — malformed correlation, idempotency, or header input.
- `401` — unauthenticated.
- `403` — authenticated but unauthorized action.
- `404` — unavailable task or disabled non-admin historical resource without disclosure.
- `409` — invalid task transition, idempotency conflict, or disabled feature mutation.
- `412` — stale `If-Match`.
- `422` — invalid task body, missing reason/note, or out-of-team assignee.

The UI refreshes after `412` and preserves the user's unsent reason or note. A failed mutation never applies optimistic local state as if it succeeded.

## 12. Testing and verification

Backend TDD covers:

- feature-gate enforcement with zero side effects;
- self-creation and authorized team assignment;
- forbidden cross-team assignment and reassignment;
- all valid and invalid task transitions;
- required block/cancel reason and completion note;
- participant read/comment/attachment limits;
- creator, assignee, participant, and unrelated-user visibility;
- optimistic concurrency and idempotent replay;
- notification recipients, actor exclusion, deduplication, and atomicity;
- absence of runtime Task-to-Workflow dependencies;
- architecture/table ownership.

Frontend tests cover:

- disabled navigation and zero disabled API calls;
- task creation, filters, detail, transitions, comments, participants, attachments, notifications, RTL/LTR, and all required states;
- generated-client-only transport.

The Playwright journey proves:

1. employee creates and completes a self-task;
2. manager assigns a task within the team;
3. assignee starts, blocks with a reason, resumes, comments, attaches a document, and completes with a note;
4. creator and participants receive in-app notifications while the actor does not;
5. request/Workflow surfaces are absent and direct disabled commands fail closed.

Final verification runs the narrow suites first, then contract generation/check, API tests, module boundaries, web build/lint/unit tests, and the focused browser journey.

## 13. Delivery boundaries

- `openapi.yaml` is edited first; Orval output and `api-reference.html` are generated, never hand-edited.
- Backend and frontend implementation start only after the contract task is stable.
- Existing unrelated repository behavior and the already-committed platform hardening commit are preserved.
- No push, deployment, production migration, or remote branch cleanup is part of this implementation unless separately requested.
