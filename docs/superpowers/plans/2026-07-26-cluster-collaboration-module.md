# Cluster Collaboration Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: M03
status: blocked
depends_on:
  - M00
blocks:
  - M07:final-integration
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Implement a first-class Collaboration module whose users can create visible threads, manage membership, comment and mention colleagues, attach Documents-owned references, inspect permitted edit history, moderate content, and receive privacy-safe notifications.

**Architecture:** `Collaboration` is rank 6 and owns the thread aggregate and its seven reserved tables. Its HTTP controllers authenticate and authorize before disclosure or detailed validation, application handlers own transactions/idempotency/CAS/event publication, and persistence never reads another module’s tables. Higher-ranked producer modules may open a thread only through `OpenCollaborationThread`; consumers such as Notifications and Workspace may import only Collaboration `Contracts/` or `Events/`.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, MySQL/SQLite, React 19, TypeScript 6, Vite 8, Vitest 4, OpenAPI 3.1, Orval 8.22, Redis Streams, WCAG 2.2 AA.

**Approved Design:** [`../specs/2026-07-26-cluster-production-and-modules-program-design.md`](../specs/2026-07-26-cluster-production-and-modules-program-design.md)

## Global Constraints

- M00’s canonical reservation is exact: rank `6`; API prefix `/api/v1/collaboration`; web prefix `/collaboration`; seven owned tables; nine capability codes; two published contracts; three published events.
- The current Architecture Closure plan remains `in_progress` and retains `Makefile`, CI workflows, master OpenAPI, generated Orval output, `ModuleBoundariesTest.php`, and any actively claimed `routes/web.php` task until explicit handoff.
- Existing Tasks engagement remains Tasks-owned: `task_participants`, `task_comments`, `/api/v1/tasks/{taskId}/participants`, and `/api/v1/tasks/{taskId}/comments` are neither moved nor aliased.
- Module flow is controller → validation/capability check → handler/application service → Collaboration-owned persistence.
- Cross-module dependencies use published `Contracts/` or `Events/`; Collaboration never imports another module’s `Domain/`, `Application/`, `Features/`, or `Infrastructure/` code.
- State, idempotency replay state, revisions/moderation evidence, Shared outbox events, and response metadata for one command commit in one database transaction.
- Optimistic concurrency is a compare-and-swap predicate, not a pre-read followed by an unconditional update.
- Production adapters fail closed; deterministic fakes are test-only and no fake binding may merge.
- Generated `apps/web/src/api/generated/cluster.ts` changes only through `npm --prefix apps/web run api:generate` after the OpenAPI integration token.
- No commit, push, deployment, migration, cloud action, or external notification is authorized by this plan. A commit is recorded only after explicit user authorization.

---

## 1. Status, dependencies, and handoff gates

The start dependency is only `M00`. Do not promote producer integrations into start dependencies.

| Phase | Gate | Rule |
|---|---|---|
| Discovery and plan-owned tests | none | Read-only work may run while blocked. |
| Module-owned implementation | `M00=completed` and Architecture Closure Tasks 4/6/7/12 handoff | M00 must publish the canonical reservation matrix. |
| Shared outbox event dispatch | `COLLABORATION-SHARED-RELAY` after M03 event cases/schemas merge | Architecture Closure T6 owns only `TransactionalOutbox::append()` and Shared writer ownership; it created no generic relay. M03 publishes an immutable packet for the Shared outbox owner to implement/test a bounded Collaboration relay before Notifications/P01 activation. |
| Module registry/migrations/provider | architecture/module registry queue token after M01 then M02 | Shared files are changed by the queue executor, never by an un-tokened M03 worktree. |
| Laravel routes | route queue token after current `routes/web.php` owner and M01/M02 | Only reserved paths are added. |
| OpenAPI/Orval | contract queue token after the current master OpenAPI owner and M01/M02 | Generate; never hand-edit generated output. |
| Documents linked facts | `DOCUMENTS-LINKED-FACTS` after Collaboration provider implementation exists | M03 publishes an immutable packet; the Documents-owned queue implements the composite/provider binding and its tests against the existing Documents public contract. |
| Notification consumer | `NOTIFICATIONS-COLLABORATION-CONSUMER` after Collaboration events/schemas exist | M03 publishes an immutable packet; the Notifications-owned queue creates the handler/worker/command, binds them, and adds all consumer tests. |
| Notification runtime | `PROD-WORKLOAD-REGISTRY` after Shared relay and Notifications packets merge | The Shared owner and Notifications owner jointly request P01 to add exact ordered argv for `shared:relay-collaboration` immediately before `notifications:consume-collaboration`; M03 edits no owner surface. |
| Governance completion | `M02=completed` | M03 core may be implemented earlier; governance integration and final exit remain blocked. |
| Web shell | shell integration token after M01/M02; M07 retains final aggregation token | Feature directory is M03-owned; shell/navigation files are serialized. |
| Final verification | all preceding gates, one authorized recorded commit | Missing, skipped, stale, or mismatched evidence blocks completion. |

`M03` blocks only `M07:final-integration`. M07 consumes M03’s read contract after M03’s shared integration token has landed.

## 2. Goal and user-visible outcome

A signed-in, scoped user can:

1. Open a standalone Collaboration thread in an authorized facility/organization-unit scope, or receive a source-owned thread opened through the published contract.
2. See only threads allowed by both thread visibility and the central classification/capability decision.
3. Add or revoke members, choose `member` or `moderator`, and set notification preference `all`, `mentions`, or `none`.
4. Add comments, mention active members, edit their own comments, and see the permitted revision history.
5. Moderate comments with explicit `hide`, `restore`, or `redact` actions and a retained reason/action record.
6. Link an available document to a comment without Collaboration owning document bytes or document-link persistence.
7. Receive generic, privacy-safe notifications for new comments or direct mentions; denied or newly inaccessible sources remain masked by Notifications.
8. Use the bilingual `/collaboration` web workspace by keyboard and screen reader, with stable loading/empty/error/forbidden/stale-write states.

## 3. Current source evidence

- `apps/api/tests/Architecture/ModuleBoundariesTest.php` currently fixes `Collaboration => 6`, lists it in `PLANNED_MODULES`, and maps `task_participants`/`task_comments` to Tasks. Its rank guard permits a higher-ranked module to import a lower-ranked module only through `Contracts/` or `Events/`.
- `docs/architecture/module-catalog.md` reserves Collaboration at rank 6 for shared collaborative surfaces.
- `apps/api/Modules/Tasks/Features/Http/TaskEngagementController.php` already owns task participants/comments and the `tasks.comment`/`tasks.participant-manage` checks. It proves the existing behavior that must remain untouched, including current limitations.
- `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/W13CreateTaskEngagementTables.php` owns `task_participants` and `task_comments`; M03 must not reuse or query them.
- `apps/web/src/features/tasks/TaskDetail.tsx` displays Tasks-owned comments. The new Collaboration workspace must not silently replace this surface.
- `apps/api/Modules/Identity/Contracts/PrincipalContext.php` provides server-derived user, facility, organization-unit, selected-scope, and restriction facts. Browsers never supply those authorization facts.
- `apps/api/Modules/Identity/Contracts/ResolveAccountEntitlement.php` can validate that a proposed member is an active account without Collaboration reading `users`.
- `apps/api/Modules/Authorization/Contracts/DecideAccess.php`, `RecordFacts.php`, and `AccessProjection.php` are the central authorization and response-projection contracts.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php` and `Modules/Authorization/Tests/CapabilityCatalogTest.php` require exact synchronized capability lists.
- `apps/api/Modules/Documents/Contracts/LinkDocument.php`, `DocumentSourceReference.php`, and `LinkedResourceAuthorizationFacts.php` are the existing public seam: Documents owns link/storage/quarantine persistence, while a source adapter resolves only its own facts.
- `apps/api/Modules/WorkRecords/Application/WorkRecordAuthorizationFacts.php` and `WorkRecordsServiceProvider.php` prove the current single-provider binding. No composite or multi-provider test exists; Architecture Closure Task 6 did not create one. `DOCUMENTS-LINKED-FACTS` must introduce that Documents-owned composition without replacing WorkRecords.
- `apps/api/Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Handler/ConsumeWorkRecordSubmittedHandler.php` demonstrates CloudEvent validation, inbox deduplication, generic titles, grouping, recipient rows, and atomic Notification-owned writes.
- `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/ListMyNotificationsController.php` masks inaccessible source notifications using stored owner-facility/classification facts; M03 events must carry those facts and no comment body.
- `apps/api/Shared/Contracts/TransactionalOutbox.php` is the only allowed event-publication abstraction. M00 explicitly reserves no M03 outbox table.
- `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php` maps event literals to schema paths and Redis stream names; M03’s cases require a serialized shared integration token.
- `apps/api/config/module_migrations.php` explicitly lists every module migration. Merely creating a migration file does not load it.
- `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` explicitly registers module providers, and `apps/api/routes/web.php` is the current route wiring surface.
- `docs/contracts/api/openapi.yaml` and `apps/web/src/api/generated/cluster.ts` are reserved shared/generated surfaces until handoff.

## 4. Scope and explicit non-goals

### In scope

- Thread aggregate; membership and visibility; comments, mentions, edit revisions, moderation evidence; idempotency/CAS; cursor reads.
- Published M00 contracts/events and their testable production adapters.
- Session/CSRF/correlation/problem+json API, OpenAPI/Orval generation, and the M03-owned web feature.
- M03-owned Collaboration event/schema production and document-link consumption through `LinkDocument`, plus a source-owned Collaboration facts adapter whose integration is applied only under `DOCUMENTS-LINKED-FACTS`; immutable packets dispatch Documents-owned composition/binding and Notifications-owned event consumption.
- M02 governance registration/hold/disposition checks as the explicit final blocked phase.
- Serialized integration for provider, migration list, capabilities, event enum/schemas, routes, OpenAPI, generated client, shell, `DOCUMENTS-LINKED-FACTS`, `NOTIFICATIONS-COLLABORATION-CONSUMER`, MySQL suite discovery, and `PROD-WORKLOAD-REGISTRY`.

### Non-goals

- Moving, wrapping, deleting, or dual-writing existing Tasks participants/comments.
- Importing Tasks, WorkRecords, Documents, Notifications, Organization, Identity, Authorization, or RecordsGovernance Domain/Infrastructure from Collaboration.
- Foreign keys, joins, or raw SQL against another module’s tables.
- A Collaboration-owned outbox table, attachment table, document binary, notification table, identity projection, or governance projection.
- Rich-text/HTML rendering, anonymous/public threads, external guests, direct messages, reactions, real-time sockets, email delivery, search indexing, or task/work-record automatic thread creation.
- Parallel edits to shared integration files or ownership of `Makefile`/workflows.
- Direct creation or modification under `apps/api/Modules/Documents/`, `apps/api/Modules/Notifications/`, or another existing module by the M03 executor; owning queues consume M03 packets and implement their internals/tests. Under `DOCUMENTS-LINKED-FACTS`, M03 may change only its own `CollaborationServiceProvider` to register/tag the source-owned adapter as the existing Documents contract; it never binds that contract or edits a Documents provider.
- Manual generated-client edits, compatibility aliases, fake production adapters, or skipped production verification.

## 5. Architecture and ownership boundaries

### Aggregate and invariants

`CollaborationThread` is the aggregate root. It contains stable source metadata but never resolves a producer table:

```text
id, source_module?, source_type?, source_id?, title,
owner_facility_id, owner_organization_unit_id, classification,
visibility, status, created_by_user_id, lock_version, timestamps
```

- `source_*` is nullable for a standalone thread. A source owner opens a linked thread through `OpenCollaborationThread`; the browser cannot submit owner/classification facts for a linked source.
- At most one active thread exists per non-null `(source_module, source_type, source_id)`; replay returns the existing reference, while a different registration payload returns conflict.
- The creator is inserted as active `moderator` in the same transaction as the thread.
- Visibility is exactly `members`, `organization_unit`, or `facility`.
- Membership is always necessary for `members`; server-derived scope plus the central decision is necessary for the broader two values. Membership never overrides classification or explicit deny.
- `confidential` and `top_secret` threads are forced to `members`; an attempted broader visibility returns 422.
- An active moderator must remain. Revoking or demoting the final moderator returns 409.
- Archive is reversible only through a later approved plan; archived threads are read-only and no mutation accepts them.

### Authorization order

For every endpoint:

1. Validate only correlation UUIDv7, session/CSRF presence, `Idempotency-Key` syntax, `If-Match` syntax, and bounded pagination shape.
2. Resolve `PrincipalContext` server-side.
3. Run a coarse capability decision with no resource facts. Denial returns 403 without querying detailed input-dependent resources.
4. Read the Collaboration-owned row, build `RecordFacts`, and run the resource decision.
5. Return the same 404 `resource-not-found` for absent and non-visible rows; disclose no title, membership, classification, or existence.
6. Validate the detailed body only after the resource is authorized.

Lists apply scope predicates before pagination, continue scanning past denied rows, and never expose denied totals. All response resources use `AccessProjection`; the UI renders only returned fields/actions.

### Layering

```text
M03 controller
  -> PrincipalContext + DecideAccess Contracts
  -> M03 application handler
  -> M03 repository + M03 idempotency store
  -> Shared TransactionalOutbox (same transaction)
  -> M03-owned tables

Documents link:
M03 handler -> Documents.Contracts.LinkDocument
DOCUMENTS-LINKED-FACTS packet -> Collaboration-owned tagged facts adapter + Documents-owned contract-only composite/final boot-time binding
  -> captured existing WorkRecords contract binding + tagged Collaboration contract implementation; no producer concrete import in Documents

Notifications:
M03 Events/Shared outbox -> NOTIFICATIONS-COLLABORATION-CONSUMER packet
  -> Notifications-owned handler/worker/command/tests/provider binding -> Notifications-owned tables
  -> PROD-WORKLOAD-REGISTRY packet -> P01-owned allowlist/order/readiness integration

Governance after M02:
M03 handler -> RecordsGovernance.Contracts only
```

## 6. File manifest

### Create — Collaboration-owned API

- `apps/api/Modules/Collaboration/Contracts/OpenCollaborationThread.php`
- `apps/api/Modules/Collaboration/Contracts/CollaborationThreadRegistration.php`
- `apps/api/Modules/Collaboration/Contracts/CollaborationThreadReference.php`
- `apps/api/Modules/Collaboration/Contracts/ListVisibleCollaborationThreads.php`
- `apps/api/Modules/Collaboration/Contracts/CollaborationThreadQuery.php`
- `apps/api/Modules/Collaboration/Contracts/CollaborationThreadPage.php`
- `apps/api/Modules/Collaboration/Contracts/CollaborationThreadSummary.php`
- `apps/api/Modules/Collaboration/Events/CommentPublishedV1.php`
- `apps/api/Modules/Collaboration/Events/MentionCreatedV1.php`
- `apps/api/Modules/Collaboration/Events/ThreadVisibilityChangedV1.php`
- `apps/api/Modules/Collaboration/Domain/CollaborationThread.php`
- `apps/api/Modules/Collaboration/Domain/ThreadVisibility.php`
- `apps/api/Modules/Collaboration/Domain/CommentStatus.php`
- `apps/api/Modules/Collaboration/Application/OpenThreadHandler.php`
- `apps/api/Modules/Collaboration/Application/UpdateThreadHandler.php`
- `apps/api/Modules/Collaboration/Application/ListVisibleThreadsHandler.php`
- `apps/api/Modules/Collaboration/Application/ManageMembershipHandler.php`
- `apps/api/Modules/Collaboration/Application/CreateCommentHandler.php`
- `apps/api/Modules/Collaboration/Application/EditCommentHandler.php`
- `apps/api/Modules/Collaboration/Application/ModerateCommentHandler.php`
- `apps/api/Modules/Collaboration/Application/ArchiveThreadHandler.php`
- `apps/api/Modules/Collaboration/Application/LinkCommentDocumentHandler.php`
- `apps/api/Modules/Collaboration/Application/CollaborationLinkedResourceAuthorizationFacts.php` — created under `DOCUMENTS-LINKED-FACTS`; reads Collaboration-owned rows and implements the existing Documents public contract.
- `apps/api/Modules/Collaboration/Infrastructure/Persistence/DatabaseCollaborationRepository.php`
- `apps/api/Modules/Collaboration/Infrastructure/Persistence/DatabaseCollaborationIdempotency.php`
- `apps/api/Modules/Collaboration/Infrastructure/Persistence/Migrations/M03CreateCollaborationCoreTables.php`
- `apps/api/Modules/Collaboration/Features/Http/CollaborationApi.php`
- `apps/api/Modules/Collaboration/Features/Http/ThreadController.php`
- `apps/api/Modules/Collaboration/Features/Http/MembershipController.php`
- `apps/api/Modules/Collaboration/Features/Http/CommentController.php`
- `apps/api/Modules/Collaboration/Providers/CollaborationServiceProvider.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationDomainTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationPersistenceTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationAuthorizationTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationHttpAdapterTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationIdempotencyConcurrencyTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationModerationTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationDocumentLinkTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationGovernanceTest.php`
- `apps/api/Modules/Collaboration/Tests/CollaborationContractTest.php`

### Create — M03-owned event schemas and immutable integration packets

- `docs/contracts/schemas/com-cluster-collaboration-commentpublished-v1.schema.json`
- `docs/contracts/schemas/com-cluster-collaboration-mentioncreated-v1.schema.json`
- `docs/contracts/schemas/com-cluster-collaboration-threadvisibilitychanged-v1.schema.json`
- `docs/architecture/evidence/M03/<commit-sha>/packets/documents-linked-facts.json` — immutable `DOCUMENTS-LINKED-FACTS` packet; created only after an authorized commit supplies `<commit-sha>`.
- `docs/architecture/evidence/M03/<commit-sha>/packets/notifications-collaboration-consumer.json` — immutable `NOTIFICATIONS-COLLABORATION-CONSUMER` packet; created only after an authorized commit supplies `<commit-sha>`.
- `docs/architecture/evidence/M03/<commit-sha>/packets/prod-workload-registry.json` — immutable joint `PROD-WORKLOAD-REGISTRY` request packet for the Shared relay plus Notifications consumer; published after both owner packets merge and only after an authorized commit supplies `<commit-sha>`.
- `docs/architecture/evidence/M03/<commit-sha>/packets/collaboration-shared-relay.json` — immutable `COLLABORATION-SHARED-RELAY` packet; created only after an authorized commit supplies `<commit-sha>`.

The packet paths are retained execution evidence, not files created while drafting. Each JSON packet records `token`, `state`, `requesting_plan`, `applying_owner`, `source_commit`, `base_commit`, exact owned surfaces, symbols/signatures or argv, tests/commands, expected result, artifact hashes, and merge/release evidence. M03 never creates a file below Documents, Notifications, or Shared Infrastructure and never modifies their providers/bindings; its facts adapter is only tagged by `CollaborationServiceProvider` and cannot replace the existing contract binding.

### Create — M03-owned web feature

- `apps/web/src/features/collaboration/collaboration-api.ts`
- `apps/web/src/features/collaboration/collaboration-copy.ts`
- `apps/web/src/features/collaboration/CollaborationWorkspace.tsx`
- `apps/web/src/features/collaboration/ThreadList.tsx`
- `apps/web/src/features/collaboration/ThreadDetail.tsx`
- `apps/web/src/features/collaboration/ThreadComposer.tsx`
- `apps/web/src/features/collaboration/MembershipPanel.tsx`
- `apps/web/src/features/collaboration/CommentComposer.tsx`
- `apps/web/src/features/collaboration/ModerationDialog.tsx`
- `apps/web/src/features/collaboration/CollaborationWorkspace.test.tsx`
- `apps/web/src/features/collaboration/collaboration-api.test.ts`
- `apps/web/e2e/collaboration.spec.ts`

### Modify only under serialized tokens

- `apps/api/tests/Architecture/ModuleBoundariesTest.php` — remove Collaboration from `PLANNED_MODULES` and apply M00’s rank/table reservations; M00/module registry queue owns the token.
- `apps/api/config/module_migrations.php` — append the one M03 migration path after M02’s entries.
- `apps/api/app/Providers/AppServiceProvider.php::MODULE_PROVIDERS` — register `CollaborationServiceProvider` after M02’s provider and before higher-ranked modules.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php` and `Modules/Authorization/Tests/CapabilityCatalogTest.php` — add the exact nine canonical capability codes.
- `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php` — add the exact three canonical cases after M02’s cases.
- `apps/api/phpunit.mysql.xml` — `MYSQL-SUITE` queue adds exactly `<file>Modules/Collaboration/Tests/CollaborationIdempotencyConcurrencyTest.php</file>`; the sentinel in section 11 proves discovery before the runner executes.
- `apps/api/routes/web.php` — route queue only.
- `docs/contracts/api/openapi.yaml` — contract queue only.
- `apps/web/src/api/generated/cluster.ts` — generation command only.
- `apps/web/src/shell/routes.ts`, `apps/web/src/shell/navigation.tsx`, and `apps/web/src/app/workspace-routes.tsx` — shell queue only; M07 retains final aggregation.

No file is moved or removed.

## 7. Public contracts, events, routes, schemas, and capabilities

### M00-frozen published contracts

```php
namespace Modules\Collaboration\Contracts;

interface OpenCollaborationThread
{
    public function open(CollaborationThreadRegistration $registration): CollaborationThreadReference;
}

interface ListVisibleCollaborationThreads
{
    public function list(CollaborationThreadQuery $query): CollaborationThreadPage;
}
```

`CollaborationThreadRegistration` is readonly and contains `sourceModule`, `sourceType`, `sourceId`, `title`, `ownerFacilityId`, `ownerOrganizationUnitId`, `classification`, `visibility`, `createdByUserId`, `initialMemberUserIds`, `correlationId`, and `idempotencyKey`. All IDs are lowercase UUIDv7; the source triple is all-null or all-present.

`CollaborationThreadReference` contains `threadId`, `lockVersion`, `status`, and `created`.

`CollaborationThreadQuery` contains a trusted `PrincipalContext`, `limit` (1–100), encrypted `cursor`, optional `sourceModule/sourceType/sourceId`, and optional `status`. Its query fingerprint includes every filter, limit, principal user ID, selected scope, and scope epoch.

`CollaborationThreadPage` contains `list<CollaborationThreadSummary> items` and `?string nextCursor`. Each summary contains only `threadId`, `title`, `source` opaque IDs, `visibility`, `classification` if field projection allows it, `status`, `memberCount`, `lastActivityAt`, `lockVersion`, and `allowedActions`.

M07 consumes only `ListVisibleCollaborationThreads`, `CollaborationThreadQuery`, `CollaborationThreadPage`, and `CollaborationThreadSummary`; it never reads Collaboration tables.

### M00-frozen events and exact literals

| Event class | `OutboxEventType` literal | Purpose |
|---|---|---|
| `CommentPublishedV1` | `com.cluster.collaboration.commentpublished.v1` | Notify eligible active members excluding author and mentioned recipients. |
| `MentionCreatedV1` | `com.cluster.collaboration.mentioncreated.v1` | Notify exactly one active mentioned member per event. |
| `ThreadVisibilityChangedV1` | `com.cluster.collaboration.threadvisibilitychanged.v1` | Invalidate downstream visibility projections without carrying content. |

Every envelope is CloudEvents 1.0: lowercase UUIDv7 `id`/`correlationid`, `source=/collaboration`, `subject=/collaboration/threads/{threadId}` or `/collaboration/comments/{commentId}`, UTC RFC3339 `time`, and `datacontenttype=application/json`. Data includes opaque IDs, `owner_facility_id`, `owner_organization_unit_id`, `classification`, and recipient IDs where applicable. It never includes title, comment body, names, email, account status, role labels, or document metadata.

### Capabilities

```text
collaboration.thread.create
collaboration.thread.read
collaboration.thread.list
collaboration.thread.update
collaboration.thread.archive
collaboration.membership.manage
collaboration.comment.create
collaboration.comment.edit
collaboration.comment.moderate
```

### API routes

All routes require `IdentitySessionMiddleware` and `RequireIdentitySessionPrincipal`. GET routes do not mutate. Every mutation also requires `IdentityCsrfMiddleware`, `X-Correlation-ID`, and `Idempotency-Key`; PATCH/archive/membership removal/comment-document linking/moderation require `If-Match`.

| Method | Path | Capability | Success |
|---|---|---|---|
| GET | `/api/v1/collaboration/threads` | `collaboration.thread.list` | 200 page |
| POST | `/api/v1/collaboration/threads` | `collaboration.thread.create` | 201 + `ETag: "1"` |
| GET | `/api/v1/collaboration/threads/{threadId}` | `collaboration.thread.read` | 200 + ETag |
| PATCH | `/api/v1/collaboration/threads/{threadId}` | `collaboration.thread.update` | 200 + next ETag |
| POST | `/api/v1/collaboration/threads/{threadId}/archive` | `collaboration.thread.archive` | 200 + next ETag |
| GET | `/api/v1/collaboration/threads/{threadId}/memberships` | `collaboration.thread.read` | 200 page |
| POST | `/api/v1/collaboration/threads/{threadId}/memberships` | `collaboration.membership.manage` | 201 + next thread ETag |
| PATCH | `/api/v1/collaboration/threads/{threadId}/memberships/{membershipId}` | `collaboration.membership.manage` | 200 + next thread ETag |
| DELETE | `/api/v1/collaboration/threads/{threadId}/memberships/{membershipId}` | `collaboration.membership.manage` | 204 + next thread ETag |
| GET | `/api/v1/collaboration/threads/{threadId}/comments` | `collaboration.thread.read` | 200 page |
| POST | `/api/v1/collaboration/threads/{threadId}/comments` | `collaboration.comment.create` | 201 + comment ETag |
| PATCH | `/api/v1/collaboration/comments/{commentId}` | `collaboration.comment.edit` | 200 + next comment ETag |
| GET | `/api/v1/collaboration/comments/{commentId}/revisions` | author+edit or moderator | 200 page |
| POST | `/api/v1/collaboration/comments/{commentId}/moderation-actions` | `collaboration.comment.moderate` | 201 + next comment ETag |
| POST | `/api/v1/collaboration/comments/{commentId}/document-links` | comment edit/create + `documents.link` | 201 + next comment ETag |

Bodies use `application/json`; errors use `application/problem+json` with `type`, `title`, `status`, `detail`, and `correlation_id`. Collections use encrypted principal/filter-bound cursors over `(updated_at,id)` for threads and `(created_at,id)` for memberships/comments/revisions.

## 8. Database schema, indexes, constraints, migration order, and recovery

`M03CreateCollaborationCoreTables.php::up()` creates in this exact order:

1. `collaboration_threads`
   - UUIDv7 `id` PK; nullable `source_module`/`source_type` varchar(64), nullable UUIDv7 `source_id`; `title` varchar(200).
   - opaque UUIDv7 `owner_facility_id`, `owner_organization_unit_id`, `created_by_user_id`; `classification` varchar(32); `visibility` varchar(32); `status` varchar(32); unsigned bigint `lock_version` default 1; millisecond UTC timestamps and nullable `archived_at`.
   - unique `(source_module,source_type,source_id)`; indexes `(owner_organization_unit_id,status,updated_at,id)`, `(owner_facility_id,status,updated_at,id)`, `(status,updated_at,id)`.
2. `collaboration_thread_memberships`
   - UUIDv7 `id` PK; Collaboration-owned `thread_id`; opaque `user_id`, `added_by_user_id`, nullable `revoked_by_user_id`; `role` (`member|moderator`); `notification_level` (`all|mentions|none`); `active` bool; unsigned bigint `lock_version`; timestamps/revoked_at.
   - unique `(thread_id,user_id)`; indexes `(thread_id,active,role)`, `(user_id,active,thread_id)`.
3. `collaboration_comments`
   - UUIDv7 `id` PK; Collaboration-owned `thread_id`; opaque `author_user_id`; `body` text; `status` (`published|hidden|redacted`); JSON `document_ids` default `[]`; unsigned bigint `lock_version`; created/updated/edited timestamps.
   - indexes `(thread_id,created_at,id)`, `(author_user_id,created_at,id)`.
4. `collaboration_mentions`
   - UUIDv7 `id` PK; Collaboration-owned `thread_id`/`comment_id`; opaque `mentioned_user_id`; `created_at`.
   - unique `(comment_id,mentioned_user_id)`; indexes `(mentioned_user_id,created_at,id)`, `(thread_id,created_at,id)`.
5. `collaboration_comment_revisions`
   - UUIDv7 `id` PK; Collaboration-owned `thread_id`/`comment_id`; unsigned bigint `revision_number`; prior `body`, prior `status`, opaque `changed_by_user_id`, `change_kind` (`author_edit|moderator_redact`), nullable `reason`, `created_at`.
   - unique `(comment_id,revision_number)`; index `(comment_id,created_at,id)`.
6. `collaboration_moderation_actions`
   - UUIDv7 `id` PK; Collaboration-owned `thread_id`/`comment_id`; opaque `actor_user_id`; `action` (`hide|restore|redact`); `reason` varchar(500); `before_status`, `after_status`; `created_at`.
   - indexes `(comment_id,created_at,id)`, `(actor_user_id,created_at,id)`.
7. `collaboration_idempotency_keys`
   - UUIDv7 `id` PK; opaque `actor_user_id`; `operation` varchar(96); SHA-256 `key_hash` and `request_hash`; `resource_type`, nullable `resource_id`; `response_status`; JSON `response_body`; nullable `response_etag`; timestamps and `expires_at`.
   - unique `(actor_user_id,operation,key_hash)`; index `(expires_at,id)`.

Internal Collaboration foreign keys use `RESTRICT`, never cascade, so governance evidence cannot disappear through parent deletion. Opaque IDs have indexes but no foreign keys to Identity, Organization, Documents, Tasks, WorkRecords, or RecordsGovernance.

`down()` drops in reverse order. It refuses to run in production unless an explicit Laravel migration rollback is authorized and a verified backup exists. Rollback evidence records row counts and checksum before/after; routine application rollback leaves schema/data intact.

## 9. TDD implementation tasks

### Task 1: Freeze contracts and domain invariants

**Files:** Create the seven `Contracts/` files, three `Events/` files, three `Domain/` files, and `CollaborationDomainTest.php`/`CollaborationContractTest.php`.

**Produces:** the exact M00 public symbols in section 7; no runtime bindings.

- [ ] Write failing tests named `test_registration_requires_all_or_no_source_fields`, `test_confidential_threads_are_members_only`, `test_creator_is_initial_moderator`, and `test_m00_contract_signatures_are_exact`.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationDomainTest.php Modules/Collaboration/Tests/CollaborationContractTest.php`; expect FAIL because classes do not exist.
- [ ] Implement readonly DTO validation, enums, aggregate creation/update/archive decisions, and exact event value objects. Event objects expose `eventType()`, `eventId()`, and `toCloudEvent()` with the fixed literals.
- [ ] Re-run the same command; expect PASS with no skipped tests.

### Task 2: Create module-owned persistence and idempotency

**Files:** Create `M03CreateCollaborationCoreTables.php`, both Infrastructure persistence classes, and `CollaborationPersistenceTest.php`.

**Produces:** transactional repository methods with no cross-owner SQL.

- [ ] Write failing tests for all tables/indexes/unique constraints, insertion order, active-member lookup, stable cursor tuples, and idempotency duplicate-key races.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationPersistenceTest.php`; expect FAIL because the migration is not registered and tables are absent.
- [ ] Implement the migration and repositories. Mutation repository methods accept expected lock version and update with `where(id)->where(lock_version)->increment(lock_version)`; affected rows 0 raises a typed stale-write error.
- [ ] Register the migration only after receiving the module registry token in section 12.
- [ ] Run the SQLite test once tokened; expect PASS. Under `MYSQL-SUITE`, add the exact test path to `apps/api/phpunit.mysql.xml`, run the section-11 discovery sentinel, then run `make verify-mysql-integration`; all three M03 MySQL sentinel names must execute with zero skip.

### Task 3: Implement authorization-safe reads and published list contract

**Files:** Create `ListVisibleThreadsHandler.php`, `CollaborationApi.php`, read methods in `ThreadController.php`, `CollaborationAuthorizationTest.php`, and read cases in `CollaborationHttpAdapterTest.php`.

**Produces:** `ListVisibleCollaborationThreads::list()` and GET routes.

- [ ] Write failing tests proving members/scope users can read, explicit deny wins, confidential non-members receive the same 404 as unknown IDs, cursors reject a different principal/filter/limit, and scans continue past denied rows.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationAuthorizationTest.php --filter='read|list|cursor|disclosure'`; expect FAIL because handlers/routes are absent.
- [ ] Implement coarse decision → row lookup → fine decision → field projection. Bind `ListVisibleCollaborationThreads` to `ListVisibleThreadsHandler` in the module provider.
- [ ] Re-run the filtered tests; expect PASS and zero denied-resource fields in serialized bodies/log captures.

### Task 4: Implement thread, membership, and comment mutations

**Files:** Create `OpenThreadHandler.php`, `UpdateThreadHandler.php`, `ManageMembershipHandler.php`, `CreateCommentHandler.php`, `ArchiveThreadHandler.php`, write controller methods, `CollaborationIdempotencyConcurrencyTest.php`, and write cases in `CollaborationHttpAdapterTest.php`.

**Produces:** POST/PATCH/DELETE routes, `OpenCollaborationThread`, atomic idempotency/CAS/outbox writes.

- [ ] Write failing tests for identical replay, different-payload key conflict, two-connection stale update, final-moderator protection, inactive/non-member mentions, and rollback injection after Shared outbox append.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationIdempotencyConcurrencyTest.php`; expect FAIL because handlers are absent.
- [ ] Implement one outer `DB::transaction`: reserve idempotency key, lock/CAS the aggregate, persist state/mentions, append `CommentPublishedV1` plus one `MentionCreatedV1` per mentioned user through `TransactionalOutbox`, store the exact response, then commit.
- [ ] When an authorized update changes `visibility`, append `ThreadVisibilityChangedV1` with the prior/new visibility and source authorization facts in the same transaction; a title-only update emits no visibility event.
- [ ] Mention recipients must be active thread members with active Identity entitlement. `CommentPublishedV1` recipients are active `all` members excluding author and mentioned users; `MentionCreatedV1` has one recipient and no body.
- [ ] Re-run the command on SQLite; expect PASS with replay returning the original status/body/ETag and unchanged event counts. Execute the two-connection/race cases on MySQL only through the registered `phpunit.mysql.xml` lane in section 11.

### Task 5: Implement edit history and moderation

**Files:** Create `EditCommentHandler.php`, `ModerateCommentHandler.php`, controller edit/history/moderation methods, and `CollaborationModerationTest.php`.

**Produces:** append-only revision/moderation evidence and CAS semantics.

- [ ] Write failing tests: author edit records prior body; non-author edit denied; moderator cannot silently rewrite another author’s body; hide/restore/redact require reason; redaction preserves prior content only in restricted revisions; regular members see `edited=true` but not revision bodies; held threads reject redaction after M02 integration.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationModerationTest.php`; expect FAIL because moderation handlers are absent.
- [ ] Implement author edit and moderator actions. Redact stores the prior body revision, replaces current body with a fixed redaction marker, and writes the moderation action in the same transaction. History authorization is author+`collaboration.comment.edit` or active moderator+`collaboration.comment.moderate`.
- [ ] Re-run; expect PASS, with 412 on stale versions and 409 on invalid state transitions.

### Task 6: Integrate Documents-owned links

**Files:** The `DOCUMENTS-LINKED-FACTS` packet has two owner-applied phases. M03 creates `LinkCommentDocumentHandler.php`, `CollaborationLinkedResourceAuthorizationFacts.php`, and `CollaborationDocumentLinkTest.php`, modifies only `apps/api/Modules/Collaboration/Providers/CollaborationServiceProvider.php` to register/tag the adapter, and publishes `docs/architecture/evidence/M03/<commit-sha>/packets/documents-linked-facts.json`. The Documents owner creates `apps/api/Modules/Documents/Application/CompositeLinkedResourceAuthorizationFacts.php` and `apps/api/Modules/Documents/Tests/CompositeLinkedResourceAuthorizationFactsTest.php`, and modifies only `apps/api/Modules/Documents/Providers/DocumentsServiceProvider.php`. `WorkRecordsServiceProvider.php` remains untouched.

**Produces:** M03 document links for `new DocumentSourceReference('collaboration', 'comment', $commentId)` through the existing `LinkDocument::link(string $documentId, DocumentSourceReference $reference, string $relationType, string $principalId, string $facilityId, ?string $constraintPolicyKey = null): string`; source-owned fact resolution; Documents-owned contract-only composition/binding; no M03 attachment table or singleton interface binding.

- [ ] Write `CollaborationDocumentLinkTest.php` red cases around an injected `LinkDocument` and direct facts adapter: the handler constructs the exact Collaboration reference/relation type, passes server-derived principal/facility facts, maps unavailable/denied to the same non-disclosing 404, replays one link, and rolls back `document_ids`/ETag on stale version or adapter exception; the facts adapter returns `null` for another source and returns owner scope/classification for a Collaboration comment. Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationDocumentLinkTest.php`; expect FAIL until both M03 classes exist.
- [ ] Implement `LinkCommentDocumentHandler` against `LinkDocument`; never import `DocumentLinkService` or Documents persistence. Implement `CollaborationLinkedResourceAuthorizationFacts::resolve(DocumentSourceReference $reference): ?RecordFacts` under `Modules\Collaboration\Application`: return `null` unless `sourceModule === 'collaboration'` and `sourceType === 'comment'`, then read only `collaboration_comments` and its owning `collaboration_threads` row and return owner scope/classification. Import only Documents/Authorization public `Contracts/`. Re-run the M03 test; expect PASS and zero cross-owner SQL.
- [ ] Publish immutable `DOCUMENTS-LINKED-FACTS` with source phase owner `M03`, composition phase owner `Documents`, the adapter's exact class/signature, current `LinkedResourceAuthorizationFacts` binding evidence, and exact tag `documents.linked-resource-authorization-facts`. Packet status is `requested`; it does not claim an Architecture Closure T6 composite/handoff and authorizes no edit to WorkRecords.
- [ ] In the M03-owned phase, make `CollaborationServiceProvider::register()` singleton-register `CollaborationLinkedResourceAuthorizationFacts::class` by its concrete class and tag that class with `documents.linked-resource-authorization-facts`. Do not call `bind`, `singleton`, `instance`, or `extend` for `LinkedResourceAuthorizationFacts::class`. Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationDocumentLinkTest.php tests/Architecture/ModuleBoundariesTest.php`; expect PASS and container inspection before boot-time composition still resolves the existing WorkRecords contract binding, not the Collaboration adapter.
- [ ] Under the Documents-owned phase, create `CompositeLinkedResourceAuthorizationFactsTest.php` with fixed-order, WorkRecords, Collaboration, unknown, ambiguous, and final-container-resolution cases; run it and expect FAIL because the Documents composite/final binding are absent.
- [ ] Implement `CompositeLinkedResourceAuthorizationFacts` with `/** @param list<LinkedResourceAuthorizationFacts> $providers */ __construct(array $providers)` and `resolve(DocumentSourceReference $reference): ?RecordFacts`. Iterate contract instances in supplied order, return the sole non-null result, return `null` for zero, and throw `DomainException('linked_resource_facts_ambiguous')` for two. The class imports only `Modules\Documents\Contracts\{LinkedResourceAuthorizationFacts,DocumentSourceReference}` and `Modules\Authorization\Contracts\RecordFacts`; it never names or imports WorkRecords/Collaboration concrete classes and issues no SQL.
- [ ] Preserve `WorkRecordsServiceProvider.php` byte-for-byte. In `DocumentsServiceProvider::boot()`, first resolve and retain the current `LinkedResourceAuthorizationFacts` instance (the existing WorkRecords binding after all providers' `register()` methods), then resolve the `documents.linked-resource-authorization-facts` tagged iterable, assert every element implements the same contract, construct the composite with existing first then tagged providers, and install that composite as the final singleton instance for `LinkedResourceAuthorizationFacts`. The final-container test boots the application, asserts the resolved interface is the composite, and proves both source paths resolve without inspecting producer concrete types.
- [ ] The Documents owner runs `cd apps/api && php artisan test Modules/Documents/Tests/CompositeLinkedResourceAuthorizationFactsTest.php Modules/Documents/Tests/DocumentGovernanceAcceptanceTest.php Modules/Collaboration/Tests/CollaborationDocumentLinkTest.php` and `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`; expect PASS for final contract resolution, fixed existing-then-tagged order, WorkRecords/Collaboration behavior, unknown→`null`, ambiguous→`linked_resource_facts_ambiguous`, quarantine/deny masking, duplicate replay, rollback, no producer concrete import in Documents, no Documents cross-owner SQL, and no existing-module internal edit. Record both phases `requested → granted → merged → released`, merged SHAs/output hashes, `WorkRecordsServiceProvider.php` pre/post hash equality, tag inventory, and final binding class; until both phases release M03 remains blocked at this integration phase.
### Task 7: Publish and dispatch the Notifications consumer packets

**Files:** M03 creates the three Collaboration event schemas and publishes `docs/architecture/evidence/M03/<commit-sha>/packets/notifications-collaboration-consumer.json`. The Notifications owner alone creates `apps/api/Modules/Notifications/Features/ConsumeCollaborationActivity/{Handler/ConsumeCollaborationActivityHandler.php,Worker/NotificationsCollaborationActivityWorker.php,Console/ConsumeCollaborationActivityCommand.php,Tests/ConsumeCollaborationActivityTest.php,Tests/NotificationsCollaborationActivityWorkerTest.php}` and modifies `apps/api/Modules/Notifications/Providers/NotificationsServiceProvider.php` under `NOTIFICATIONS-COLLABORATION-CONSUMER`.

**Produces:** a merged, provider-bound `notifications:consume-collaboration --once --consumer= --limit=100` consumer plus a dispatched `PROD-WORKLOAD-REGISTRY` request; M03 itself creates/modifies no Notifications internals.

- [ ] In `CollaborationContractTest.php`, add red producer-schema assertions for the three exact event literals/envelopes and privacy allowlist. Add the three JSON schemas with `additionalProperties:false`; run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationContractTest.php`; expect PASS with unhyphenated lowercase `collaboration` event tokens and no comment text/title/name/email.
- [ ] Publish immutable `NOTIFICATIONS-COLLABORATION-CONSUMER` packet with applying owner `Notifications`; exact Collaboration event/schema inputs; generic localization keys and opaque deep link `/collaboration/threads/{threadId}?comment={commentId}`; and owner cases for valid envelope, duplicate event ID, malformed type/source/correlation, inaccessible-source masking facts, mention-vs-comment deduplication, reclaim, maximum three attempts, and DLQ. Fix the transport contract to the exact current `OutboxEventType::streamName()` derivation: `platform.collaboration-commentpublished`, `platform.collaboration-mentioncreated`, and `platform.collaboration-threadvisibilitychanged`; use three corresponding groups `notifications.collaboration-commentpublished.v1`, `notifications.collaboration-mentioncreated.v1`, and `notifications.collaboration-threadvisibilitychanged.v1`, plus shared DLQ `platform.dlq.v1`. The worker polls streams in that listed order with one aggregate batch bound.
- [ ] Do not claim Architecture Closure T6 supplied a generic relay: current `WorkRecords\Infrastructure\Outbox\Relay\RedisOutboxRelay` filters only `com.cluster.workrecord.submitted.v1`. Publish immutable `COLLABORATION-SHARED-RELAY` with applying owner `Shared outbox`, source events/cases/schemas, exact owner surfaces `apps/api/Shared/Infrastructure/Outbox/CollaborationOutboxRelay.php`, `apps/api/Shared/Infrastructure/Outbox/Console/RelayCollaborationOutboxCommand.php`, `apps/api/Shared/Providers/SharedOutboxServiceProvider.php`, `apps/api/Shared/Tests/Infrastructure/Outbox/CollaborationOutboxRelayTest.php`, and `apps/api/Shared/Tests/Infrastructure/Outbox/RelayCollaborationOutboxCommandTest.php`. The bounded public command is exactly `shared:relay-collaboration --once --limit=100`; `relayPending(int $limit = 100): int` remains internal. The orchestration ledger must record this new token before Shared edits; never alias it to T6.
- [ ] Under `COLLABORATION-SHARED-RELAY`, first run `cd apps/api && php artisan test Shared/Tests/Infrastructure/Outbox/CollaborationOutboxRelayTest.php Shared/Tests/Infrastructure/Outbox/RelayCollaborationOutboxCommandTest.php`; expect FAIL because relay/command/provider/tests are absent. Implement the relay restricted to the three M03 `OutboxEventType` cases: select unpublished rows in `(occurred_at,event_id)` order, map each case through `streamName()`, `XADD`, then set `published_at`; retry preserves CloudEvent ID. Implement the command to require `--once`, clamp `--limit` to 1–100, return nonzero on failure, log counts only, and register it through `SharedOutboxServiceProvider` in `AppServiceProvider::MODULE_PROVIDERS` under the serialized registry token. Re-run the two tests plus Artisan discovery; expect exact command discovery, one event per stream, batch/order proof, XADD failure left unpublished, and no WorkRecords/Notifications import or owner-table write.
- [ ] Under the Notifications-owned queue, write the two declared owner tests, then run `cd apps/api && php artisan test Modules/Notifications/Features/ConsumeCollaborationActivity/Tests`; expect FAIL because handler/worker/command/provider binding are absent. Implement the handler so `notification_inbox`, `notifications`, and `notification_recipients` commit in one transaction; implement fixed-order multi-stream reclaim/three-attempt/DLQ semantics; implement the bounded command and register it in `NotificationsServiceProvider`. Re-run the owner suite plus `Modules/Collaboration/Tests/CollaborationContractTest.php`; expect PASS with one generic mention notification and replay no-op.
- [ ] After both owner packets record `requested → granted → merged → released`, publish one joint `PROD-WORKLOAD-REGISTRY` request with owners `Shared outbox` and `Notifications`, applying owner `P01`, and exact consecutive argv: (1) `php artisan shared:relay-collaboration --once --limit="$OUTBOX_RELAY_BATCH_SIZE" --no-interaction`; (2) `php artisan notifications:consume-collaboration --once --consumer="$consumer" --limit="$NOTIFICATIONS_STREAM_BATCH_SIZE" --no-interaction`. Include provider/Artisan discovery, Shared relay outputs, consumer idempotency/three-attempt/DLQ evidence, privacy-safe log names, and both focused owner verifiers.
- [ ] The workload packet inserts the Shared relay then Collaboration consumer immediately after `notifications:consume-work-record-submitted` and before `platform-settings:relay-technical-alerts`. Exact resulting order positions are 5 `shared:relay-collaboration`, 6 `notifications:consume-collaboration`, 7 technical-alert relay, 8 technical-alert consumer, and 9 platform-operations dispatch; all prior order relationships remain intact.
- [ ] Under P01 ownership, hold `PROD-WORKLOAD-REGISTRY` across `apps/api/docker/worker-loop.sh` and `apps/api/docker/tests/worker-loop-test.sh`; add both argv and the exact nine-command assertion atomically. Run `sh apps/api/docker/tests/worker-loop-test.sh`, both Shared relay tests, and the Notifications suite; expect PASS. In P01's workload topology seed one committed mention outbox row, execute `php artisan worker-loop run-once` inside the production worker service (via the declared compose/container exec topology, never host `/usr/local/bin/worker-loop`), then `healthcheck`; expect relay-before-consumer, one stream/inbox/notification effect, and readiness.
- [ ] Record `PROD-WORKLOAD-REGISTRY requested → granted → merged → released`, P01 merged SHA, exact nine-command order output, readiness/failure output, stream entry IDs, outbox `published_at`, and consumer counts. Missing relay command/provider, wrong order, or absent P01 registration is an undispatched consumer and blocks M03 verification.

### Final M01 Audit integration gate (blocked only on M01 completion)
- [ ] After M01 is completed (without changing M03 start dependencies), write producer-owned failing tests covering every successful Collaboration mutation and inject only `Modules\Audit\Contracts\RecordAuditEvent`; call `record(AuditEventInput)` inside each existing producer transaction. Prove injected Audit failure rolls back Collaboration state, idempotency, Collaboration outbox, and the Audit append. Release evidence via the M01 integration packet before M03 verification.

### Task 8: Complete the M02 governance phase

**Gate:** `M02=completed`. Core work may reach `blocked` here without changing `depends_on`.

**Files:** Implement M02 contract calls in M03 application handlers and `CollaborationGovernanceTest.php`; no RecordsGovernance internals.

- [ ] Write failing tests proving thread creation calls `RegisterGovernedRecord::register()` once for `RecordSourceReference('collaboration','thread',threadId)`, archived thread retention start is recorded, active holds reject redaction/disposition, and governance adapter failure rolls back the command.
- [ ] Run `cd apps/api && php artisan test Modules/Collaboration/Tests/CollaborationGovernanceTest.php`; expect FAIL until M02 production contracts are available.
- [ ] Integrate only canonical M02 contracts: `RegisterGovernedRecord`, `ReadGovernedRecordStatus::get`, and `GuardDispositionExecution::evaluate`. Treat a missing/failed production adapter as a closed failure, not permission.
- [ ] Govern the thread aggregate and all child membership/comment/mention/revision/moderation records under that source. M03 exposes no physical-delete endpoint; any future erasure must present an allowed disposition decision and preserve M02 evidence.
- [ ] Re-run; expect PASS with real M02 bindings and no test fake registered in production.

### Task 9: Integrate HTTP contract and accessible web workspace

**Files:** Token-modify routes/OpenAPI/generated client/shell; create every file under `apps/web/src/features/collaboration/` and `apps/web/e2e/collaboration.spec.ts`.

**Produces:** `/collaboration` user experience and generated API client.

- [ ] Write failing Vitest tests for loading/empty/error/403/404, thread creation, stale edit, mention selection limited to active members, keyboard membership/moderation dialog, focus return, live-region mutation result, bidi copy, and rendered text escaping.
- [ ] Run `npm --prefix apps/web run test:unit -- src/features/collaboration`; expect FAIL because the feature/client symbols are absent.
- [ ] Add OpenAPI operations/schemas with exact routes, problem responses, headers, cursor, ETag, and idempotency semantics. Run `npm --prefix apps/web run api:generate`; generated output is accepted, not edited.
- [ ] Implement `collaboration-api.ts` as a thin wrapper over generated operations. Abort stale fetches on scope/thread change; store no thread/comment text in `localStorage`, `sessionStorage`, URLs, analytics, or console.
- [ ] Implement semantic headings/lists/forms/dialogs, visible labels, 44×44 CSS-pixel targets, logical RTL properties, non-color-only states, focus management, and reduced-motion-safe feedback.
- [ ] After the shell token, register `/collaboration` and capability-aware navigation without claiming M07’s final aggregation token.
- [ ] Re-run Vitest; expect PASS. Run the Playwright smoke in section 11; expect all declared user journeys to pass with no critical axe findings.

### Task 10: Serialized integration and one-commit verification

**Files:** only the shared or owner-applied files enumerated in sections 6 and 12, each processed by its named queue owner; M03 creates only its module/schema/web artifacts and immutable packets.

- [ ] Confirm current Architecture Closure has handed off every shared surface and M01/M02 tokens have landed. Confirm `DOCUMENTS-LINKED-FACTS` and `NOTIFICATIONS-COLLABORATION-CONSUMER` packets contain the source commit and exact owner test commands before either owner receives a token.
- [ ] Apply module registry/migration/provider/capability/event, MySQL suite, route, OpenAPI/Orval, web shell, Documents, Notifications, and P01 workload tokens through their owners. After each, run the packet's focused command and record `requested → granted → merged → released`; an unapplied packet or a command absent from the P01 allowlist is not integration.
- [ ] Run every command and sentinel in section 11 on the same authorized commit; any skipped MySQL/E2E/runtime command, missing `phpunit.mysql.xml` path, undiscovered consumer command, stale packet source SHA, or failed worker readiness assertion is failure.
- [ ] Retain the manifest/logs/screenshots/schema validation and three immutable packet records. Record `implementation_commit` and `last_verified_commit` only after explicit user authorization and exact SHA equality.

## 10. Failure, retry, idempotency, concurrency, and privacy behavior

| Condition | Required behavior |
|---|---|
| Missing/invalid session | 401 problem+json; no resource lookup. |
| CSRF missing/invalid on mutation | 419 problem+json under existing middleware semantics. |
| Capability denied before resource lookup | 403 generic access denied. |
| Unknown or invisible resource | identical 404 body and timing class; no membership/title/classification disclosure. |
| Malformed correlation/headers/query | 400 before detailed body validation. |
| Detailed body invalid | 422 only after authorization. |
| Duplicate unique membership/source thread | 409 problem with stable type. |
| Same Idempotency-Key and request hash | replay exact original status/body/ETag; no second state/event/notification. |
| Same key with different hash | 409 `idempotency-key-reused`. |
| Stale `If-Match` | 412 `precondition-failed`; no revision/action/link/outbox side effect. |
| Concurrent first use of same key | one transaction wins; loser reloads and replays or returns key-reused according to request hash. |
| Shared outbox append failure | roll back state, idempotency row, revision/action, and response. |
| Notification consumer transient failure | Redis retry/reclaim, maximum three attempts, then Notifications-owned DLQ. |
| Notification replay | `notification_inbox.event_id` makes it a no-op. |
| Documents unavailable/quarantined/denied | generic 404; no document state leak and no comment JSON mutation. |
| M02 unavailable or hold active | fail closed; no redaction/disposition/destructive mutation. |
| Archived thread | 409 for all mutation endpoints. |
| Last moderator removal | 409 and no membership change. |

Comment bodies are plain text and escaped on render. PII/PHI never appears in URLs, event bodies, notification titles, browser persistence, error details, or unsanitized logs. IDs in URLs are opaque UUIDv7 only. Event recipients are the minimum required user IDs and are not logged.

## 11. Targeted verification and user-visible smoke tests

Do not run these while drafting this plan. Future execution retains full outputs.

### Narrow red/green commands

```bash
cd apps/api && php artisan test Modules/Collaboration/Tests
cd apps/api && php artisan test Modules/Documents/Tests/CompositeLinkedResourceAuthorizationFactsTest.php Modules/Documents/Tests/DocumentGovernanceAcceptanceTest.php Modules/Collaboration/Tests/CollaborationDocumentLinkTest.php
cd apps/api && php artisan test Modules/Notifications/Features/ConsumeCollaborationActivity/Tests
cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php
npm --prefix apps/web run test:unit -- src/features/collaboration
npm --prefix apps/web run api:check
```

Expected: each exits 0 with no skipped tests or generated drift. Documents resolves both WorkRecords and Collaboration through its owner-bound composite; Notifications owns and discovers its consumer; the boundary test reports no cross-owner SQL/import/table violation.

### Required MySQL discovery sentinel and concurrency command

The `MYSQL-SUITE` owner adds exactly this child to `apps/api/phpunit.mysql.xml`:

```xml
<file>Modules/Collaboration/Tests/CollaborationIdempotencyConcurrencyTest.php</file>
```

The class exposes these exact MySQL-only test names: `test_mysql_two_connections_reject_stale_comment_cas`, `test_mysql_concurrent_first_idempotency_key_has_one_effect`, and `test_mysql_outbox_failure_rolls_back_state_and_idempotency`. Before starting MySQL, run the discovery sentinel:

```bash
(cd apps/api && php -r '$xml=simplexml_load_file("phpunit.mysql.xml"); $want="Modules/Collaboration/Tests/CollaborationIdempotencyConcurrencyTest.php"; $files=array_map("strval", iterator_to_array($xml->testsuites->testsuite->file)); if (count(array_keys($files, $want, true)) !== 1) { fwrite(STDERR, "M03 MySQL suite entry missing or duplicated\n"); exit(1); }')
(cd apps/api && php vendor/bin/phpunit -c phpunit.mysql.xml --list-tests) | tee /tmp/m03-mysql-discovery.log
python3 -c 'from pathlib import Path; p=Path("/tmp/m03-mysql-discovery.log").read_text(); names=("test_mysql_two_connections_reject_stale_comment_cas","test_mysql_concurrent_first_idempotency_key_has_one_effect","test_mysql_outbox_failure_rolls_back_state_and_idempotency"); assert all(n in p for n in names), p'
make verify-mysql-integration 2>&1 | tee /tmp/m03-mysql.log
python3 -c 'from pathlib import Path; p=Path("/tmp/m03-mysql.log").read_text(); assert "SKIP" not in p and "OK" in p, p'
```

Expected: the XML sentinel exits 0 only for one exact entry; PHPUnit discovery lists all three named M03 cases before MySQL starts; the runner exits 0 and prints `OK`; and no `SKIP` occurs. A green MySQL suite that omits the exact file or any named case is failure.

### Notification command and P01 workload readiness smoke
```bash
(cd apps/api && php artisan list --format=json) | php -r '$j=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $names=array_column($j["commands"], "name"); exit(count(array_keys($names, "notifications:consume-collaboration", true)) === 1 ? 0 : 1);'
(cd apps/api && php artisan notifications:consume-collaboration --once --consumer=m03-smoke --limit=10 --no-interaction)
sh apps/api/docker/tests/worker-loop-test.sh
```

Expected after seeding one committed valid `MentionCreatedV1` outbox row: command discovery is exact; P01's nine-command order suite places `shared:relay-collaboration` immediately before `notifications:consume-collaboration`; the cycle relays then consumes once, replay creates none, and readiness succeeds only after all nine commands pass. An injected relay or Collaboration-consumer failure makes the cycle nonzero and leaves readiness absent/stale.

### Web and browser smoke

```bash
npm --prefix apps/web run test:e2e:local -- collaboration.spec.ts
```

The Playwright scenario must prove:

1. Member opens `/collaboration`, creates a members-only thread, and sees ETag-backed detail.
2. Moderator adds an active member; the new member sees the thread after scope refresh.
3. Member posts a comment mentioning another member; the recipient sees one generic notification and navigates to the authorized comment.
4. Author edits the comment; the edited marker appears and permitted history shows the prior revision.
5. Moderator hides/restores then redacts with a reason; normal members never see redacted content/history.
6. Two tabs edit the same comment; the second receives the user-visible 412 conflict with reload/reapply controls.
7. User links an available document; reload preserves the reference. A quarantined document shows only a generic unavailable message.
8. A user outside visibility receives the same not-found UI as a random UUID and receives no notification content.
9. Keyboard-only and screen-reader assertions cover composer, membership panel, moderation dialog, focus restoration, live regions, Arabic RTL, and no critical axe violation.

### Integrated gates before completion

Run the Documents owner suite, Notifications owner suite, XML/output MySQL sentinels, command-discovery sentinel, and P01 order/readiness smoke above before this aggregate set:

```bash
make verify-boundaries
make test-api
npm --prefix apps/web run api:check
make test-web
make verify-mysql-integration
```

P08 remains the only owner of the singular final closure gate. M03 completes on its own immutable ancestor-SHA manifest after these child gates pass; P08 later reruns every critical verifier on final integrated HEAD and stores fresh final-SHA outputs.

## 12. Shared-file integration tokens

M03 has `shared_file_owner: []`. Every shared edit below is blocked until an exclusive token is granted:

1. **`MODULE-REGISTRY`, M01 → M02 → M03:** `ModuleBoundariesTest.php`, `config/module_migrations.php`, `AppServiceProvider.php::MODULE_PROVIDERS`, CapabilityCatalog/tests, and OutboxEventType. Current Architecture Closure must first release its surfaces; the atomic token includes the real Collaboration directory/migration plus rank/table/planned-list cutover. No Documents or Notifications file is in this M03-owned registry change.
2. **`MYSQL-SUITE`, M01 → M02 → M03:** `apps/api/phpunit.mysql.xml`. Add the one exact section-11 `<file>` entry and release only after the XML and output sentinels prove all three named M03 cases execute without skip.
3. **`API-ROUTES`, M01 → M02 → M03:** `apps/api/routes/web.php`. Add only section 7 routes inside the existing session/CSRF groups.
4. **`OPENAPI`/`ORVAL`, M01 → M02 → M03:** `docs/contracts/api/openapi.yaml` plus generated Orval client. Event schemas are M03-owned source artifacts. Run generation once, then `api:check`; never hand-edit generated output.
5. **`DOCUMENTS-LINKED-FACTS`:** M03's source phase creates its facts adapter and tags the concrete class in `CollaborationServiceProvider` as `documents.linked-resource-authorization-facts` without binding the interface. The Documents phase creates a contract-only/no-SQL composite and installs the final interface instance in `DocumentsServiceProvider::boot()` after capturing the existing WorkRecords contract instance. Neither phase edits WorkRecords; Documents imports no producer concrete class. Both phase records and final-container/boundary tests are required. No Architecture Closure alias is invented.
6. **`COLLABORATION-SHARED-RELAY`:** M03 publishes the Task-7 packet; the Shared outbox owner alone creates the bounded relay/tests described there. This is a new explicit serialized integration token, not an Architecture Closure handoff alias. It must merge/release before the Notifications owner runs consumer integration or P01 activates it.
7. **`NOTIFICATIONS-COLLABORATION-CONSUMER`:** after token 6 merges, M03 publishes the Task-7 consumer packet; the Notifications owner alone creates consumer internals/tests and modifies `NotificationsServiceProvider`, then proves Artisan discovery. M03 never edits Notifications internals.
8. **`PROD-WORKLOAD-REGISTRY`:** after tokens 6 and 7 merge, the Shared and Notifications owners dispatch one joint Task-7 packet to P01 for both exact argv. P01 alone modifies `worker-loop.sh` and `worker-loop-test.sh`, preserving the nine-command allowlist/order/readiness surface atomically. M03/Shared/Notifications do not edit P01 files.
9. **`WEB-SHELL`, M01 → M02 → M03:** shell routes/navigation/workspace switch. M07 keeps the final aggregation token.
10. **`CLOSURE-CI`:** none for M03. P08 alone integrates final Make/workflow gates after the current Task-13 handoff.

If a shared file lacks a released owner/token, set M03 to `blocked`, record the exact gate/evidence, and continue only independent module-owned work. Never copy the shared surface into a competing file to bypass the queue.

## 13. Rollback procedure

1. P01 removes the Collaboration consumer from its allowlist and exact-order test under a reverse `PROD-WORKLOAD-REGISTRY` token, runs the order/readiness suite, and stops the worker through its runtime interface; M03 edits no P01 file.
2. The Notifications owner removes the command/provider binding and consumer internals under a reverse `NOTIFICATIONS-COLLABORATION-CONSUMER` token only after P01 stops dispatching it; Notification rows/inbox/DLQ evidence remain intact.
3. The Documents owner removes the Collaboration provider from its composite under a reverse `DOCUMENTS-LINKED-FACTS` token while preserving the existing WorkRecords provider and runs its composite suite; M03 edits no Documents binding.
4. Remove `/collaboration` navigation and route registration through reverse shell/route tokens while leaving data intact.
5. Disable `CollaborationServiceProvider` through the module registry token. Do not leave a fake/no-op binding.
6. Roll back the application artifact to the last verified commit. Shared OpenAPI/generated files roll back together so wrappers never target absent endpoints.
7. Leave all seven tables and Shared outbox rows intact during routine rollback. This preserves idempotency, moderation/revision, notification, and governance evidence.
8. A schema rollback is a separate authorized recovery action: verify backup, confirm M02 has no hold/disposition block, drain consumers/outbox, record counts/checksums, execute the one migration `down()` in reverse dependency order, and verify restore.
9. Re-enable the previous artifact and run read-only health plus notification replay checks. Unknown event types remain in Shared outbox/DLQ for recovery; they are never discarded to make rollback appear green.

Rollback triggers include authorization disclosure, missing atomicity, duplicated notification, event/schema incompatibility, generated drift, failed no-skip MySQL CAS, or inability to restore.

## 14. Exit criteria and retained evidence

M03 is complete only when all are true:

- M00 canonical rank/table/capability/route/contract/event reservations are applied exactly.
- Existing Tasks engagement tests still pass and no Tasks table/route was moved, aliased, or dual-written.
- All seven and only seven Collaboration tables are owned by M03; no own outbox/attachment table exists.
- Controllers contain no business SQL/transaction/outbox work; handlers own atomic commands.
- No cross-module Domain/Application/Features/Infrastructure import or cross-owner SQL/FK exists.
- Session/CSRF/correlation/problem+json, authorization-before-disclosure, field projection, cursor binding, and privacy tests pass.
- Idempotency replay/mismatch/race, MySQL two-connection CAS, rollback injection, and stale ETag tests pass without skip.
- Membership/visibility/final-moderator, edit/revision/moderation/redaction, and archived-state invariants pass.
- Documents owns all link rows/binaries and the `LinkedResourceAuthorizationFacts` composite/provider binding; its contract-only suite proves WorkRecords remains resolved, tagged Collaboration resolves, unknown/ambiguous sources fail closed, and quarantine/rollback behavior passes. The released `DOCUMENTS-LINKED-FACTS` packet records both owner phases.
- Three canonical event literals match enum cases and JSON schemas. The released `COLLABORATION-SHARED-RELAY` packet proves committed outbox rows reach all three exact streams without loss/duplicate marking. Notifications owns the handler/worker/command/provider/tests; inbox/retry/DLQ/masking and Artisan-discovery tests pass, and the released `NOTIFICATIONS-COLLABORATION-CONSUMER` packet records the owner merge.
- M02 real production bindings govern thread aggregates and hold/disposition tests pass; no production fake remains.
- OpenAPI generates the client, a second generation produces zero drift, web unit/browser/a11y smoke passes, and `/collaboration` is shell-integrated via token.
- The exact Collaboration MySQL class appears once in `phpunit.mysql.xml`; discovery lists all three sentinel tests; the MySQL lane executes them with no skip.
- The released `PROD-WORKLOAD-REGISTRY` packet proves P01's nine-command allowlist with Shared relay immediately before the Collaboration consumer, end-to-end outbox→stream→notification replay smoke, and full-cycle readiness/failure behavior. M03 has touched neither Shared/Notifications/P01 owner internals.
- M03's immutable completion manifest and packet artifacts refer to its one user-authorized commit SHA. P08 may ingest that ancestor-SHA evidence, but later reruns every critical M03 verifier on final integrated HEAD and records fresh final-SHA outputs; P08 acceptance is not an M03 completion prerequisite.

Retain under `docs/architecture/evidence/M03/<commit-sha>/`:

- `manifest.json` with `plan_id`, `commit_sha`, `verified_at_utc`, `commands[]` (`command`, `exit_code`, `started_at_utc`, `duration_ms`, `output_sha256`, `artifact_paths`), `smoke_scenarios[]`, and `integration_tokens[]` (`surface`, `owner`, `sequence`, `applied_commit`).
- `packets/documents-linked-facts.json`, `packets/collaboration-shared-relay.json`, `packets/notifications-collaboration-consumer.json`, and `packets/prod-workload-registry.json`, each with request/grant/merge/release history, applying owner, source/base/merge SHAs, exact surfaces/argv, output hashes, and status gate.
- Raw API/Notification/Architecture/web/MySQL/E2E command logs.
- MySQL two-connection trace and rollback-injection row/event counts.
- Event schema validation output plus sample redacted envelopes.
- OpenAPI/Orval first-generation output and zero-drift second check.
- Browser screenshots, accessibility result, and user-visible 403/404/409/412 states.
- Migration up/down dry-run evidence, table/index inventory, backup/restore reference, and consumer smoke counts.

Do not place live PII/PHI, cookies, tokens, raw comment bodies, recipient identities, or document metadata in retained evidence.

## 15. Status transition rules

- `planned` → `ready`: not used initially; M03 starts `blocked` because M00/current shared handoffs are unresolved.
- `blocked` → `ready`: M00 is completed and Architecture Closure Tasks 4/6/7/12 have handed off module-owned start surfaces. The orchestration plan must record the same transition.
- `ready` → `in_progress`: an executor begins module-owned work in an isolated worktree and records the base SHA.
- `in_progress` → `blocked`: the next executable phase requires M02, Task-10 outbox handoff, `DOCUMENTS-LINKED-FACTS`, `COLLABORATION-SHARED-RELAY`, `NOTIFICATIONS-COLLABORATION-CONSUMER`, joint `PROD-WORKLOAD-REGISTRY`, `MYSQL-SUITE`, or another shared queue token. Name the exact gate; independent completed work remains valid.
- `in_progress` → `verification`: implementation, M02 governance, Documents/Shared/Notifications packet merges, joint P01 relay+consumer registration/readiness, MySQL discovery, and every shared integration token are complete; no fake production binding exists.
- `verification` → `completed`: section 14 passes on one recorded commit after explicit user authorization, immutable manifest/packet evidence is retained, and `implementation_commit == last_verified_commit`. Orchestration records completion; later P08 acceptance/replay is downstream and does not hold M03 below `completed`.
- Any failed/stale/skipped critical gate, schema/event drift, disclosure regression, duplicate side effect, or rollback failure moves `verification`/`completed` to `blocked` and clears `last_verified_commit` until reverified.
- `superseded` requires a later approved plan path, the approving user decision, dependency/block updates, and orchestration/shared-ownership updates.
- Raw `.minimax-flow` findings may be registered only after current validation as a new sourced `C` finding with evidence and exit criteria; unrecoverable historical `F` placeholders are never recreated.
