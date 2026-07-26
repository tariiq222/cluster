# Cluster Planned-Module Contracts Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` or `skill://executing-plans` to execute this plan task by task. Use checkbox (`- [ ]`) tracking. Do not implement a planned module while executing M00.

```yaml
plan_id: M00
status: blocked
depends_on:
  - ARCHITECTURE-CLOSURE:T4
  - ARCHITECTURE-CLOSURE:T6
  - ARCHITECTURE-CLOSURE:T7
  - ARCHITECTURE-CLOSURE:T12
blocks:
  - M01
  - M02
  - M03
  - M04
  - M05
  - M06
  - M07
shared_file_owner:
  - apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS
  - apps/api/tests/Architecture/ModuleBoundariesTest.php::PLANNED_MODULES
  - apps/api/tests/Architecture/ModuleBoundariesTest.php::TABLE_OWNERS
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Freeze one approved, source-grounded contract and ownership baseline for the seven planned modules so their module-owned cores can be implemented in parallel and their shared integrations can be applied safely in one serial queue.

**User-visible outcome:** The program has an approved, reviewable answer for which module owns each planned capability and table, which URLs and public types are reserved, which data can contain PHI, and which integration token applies each change. M00 itself adds no user-facing endpoint or runtime behavior.

**Architecture:** M00 creates documentation decision artifacts only. It records the existing enforced ranks, reserves disjoint table/capability/route namespaces, defines narrow published Contracts and versioned Events, and fixes the integration order. Each later module implements its own controller → authorization/validation → handler/service → persistence path; cross-module access uses only the public types frozen here.

**Tech stack:** Markdown, YAML, Laravel 13.8 modular-monolith conventions, PHP 8.4 contract naming, JSON Schema Draft 2020-12 event conventions, OpenAPI 3, Orval, MySQL, and the existing architecture/documentation validation commands.

## Global constraints

- The approved source of truth is `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`.
- The current `docs/superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md` remains `in_progress` and retains every reserved shared surface until explicit handoff.
- M00 may investigate before its dependencies complete, but may not enter decision approval or verification until Architecture Closure Tasks 4, 6, 7, and 12 are complete and their handoff evidence is recorded.
- M00 owns only the three named constants in `ModuleBoundariesTest.php`, only after current-plan handoff, and only through the serialized `MODULE-REGISTRY` token. M00 does not own the file as a whole. The seven frozen planned-rank reservations remain present in `MODULE_RANKS` before implementation and are legal; child cutovers must not add, remove, or change them. A module's runtime cutover is one atomic integration change: the real module directory, all owned migrations, migration-manifest entries, that module's removal from `PLANNED_MODULES`, and its `TABLE_OWNERS` additions land together. Prematurely removing a planned module or registering table ownership before that change is forbidden ghost runtime registration.
- No M00 task edits `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, web shell files, migrations, module runtime code, `Makefile`, or CI workflows.
- Planned client output is changed only by `npm --prefix apps/web run api:generate` while the `OPENAPI`/`ORVAL` token is held by a later module integration.
- No empty module directory, empty migration, placeholder route, no-op adapter, production fake, compatibility alias, or incomplete scaffold may be created.
- Test doubles implement only the frozen public contract, are deterministic, live under tests, and never receive a production container binding.
- No commit, push, deployment, migration, external message, or cloud change is authorized by this plan. A commit is recorded only after explicit user authorization.
- New audit findings use the next validated `C` identifier with source and evidence. Unsourced historical `F001`–`F123` entries are never recreated; raw `.minimax-flow` findings must be revalidated before registration.

---

## 1. Status header and dependency gates

M00 remains `blocked` until all four prerequisite handoffs are present in the orchestration record:

| Gate | Required handoff evidence | Surface released to M00 or later queues |
|---|---|---|
| `ARCHITECTURE-CLOSURE:T4` | Exact migrated-table set equals `TABLE_OWNERS`; placement exceptions exist, have a reason, and are unexpired | The three M00-owned registry constants; `docs/architecture/module-catalog.md` after its T4 edit is integrated |
| `ARCHITECTURE-CLOSURE:T6` | Published-contract boundary and shared transactional-outbox ownership pass; the final event catalogue location is recorded | Contract/event naming baseline and shared outbox assumptions |
| `ARCHITECTURE-CLOSURE:T7` | Canonical problem renderer, correlation request attribute, resource envelope, and strong ETag parsing are integrated | HTTP behavior inherited by M01–M07 |
| `ARCHITECTURE-CLOSURE:T12` | Exact live/planned route reconciliation is approved; master OpenAPI, generated client, and bounded cursor contract are synchronized | Route/OpenAPI reservations for later serialized module tokens |

The handoff record must name the releasing task, the full base commit, the exact surface, the grant time, and the token state. A task completion claim without resolvable evidence is not a handoff.

`M01`–`M07` stay blocked until the current user approves the frozen M00 artifacts. Producer-dependent integrations remain blocked phases inside the owning module plan; they are not promoted into module start dependencies.

## 2. Goal and user-visible outcome

M00 answers these questions before runtime work begins:

1. Which existing rank is assigned to each planned module?
2. Which table names are reserved, and which module is their sole owner?
3. Which capabilities and HTTP/OpenAPI prefixes are reserved?
4. Which PHP Contracts, DTOs, and Events are public, and which remain internal?
5. Which fields may contain PHI/PII, and what may cross an event, error, URL, log, or browser boundary?
6. Which mutations require idempotency or optimistic concurrency?
7. Which migration and shared-integration order is legal?
8. Which deterministic test fakes allow parallel core work without creating a production fallback?

Success is a user-approved documentation baseline and a reproducible evidence manifest. Success is not a module directory, route, table, adapter, generated client, or deployable feature.

## 3. Current source evidence

A fresh executor must re-read these exact sources after the four handoffs; line numbers may move, but symbols and decisions must be resolved by name:

- `apps/api/tests/Architecture/ModuleBoundariesTest.php`
  - `MODULE_RANKS` currently fixes `Audit=3`, `RecordsGovernance=4`, `Collaboration=6`, `Strategy=8`, `PortfolioProjects=9`, `Risk=10`, and `Workspace=11`.
  - `PLANNED_MODULES` lists exactly those seven modules.
  - `TABLE_OWNERS` is the enforced table-owner catalogue.
  - `test_planned_modules_have_no_implementation_directory_yet` states the clean cutover: remove one module from `PLANNED_MODULES` and register its tables when that module is actually implemented.
  - `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout` and the cross-owner SQL/FK fixtures enforce ownership.
- `apps/api/tests/Architecture/ModulePlacementInventory.php` is an expiring exception list, not a place to reserve planned runtime files.
- `docs/architecture/module-catalog.md` is the human architecture catalogue and must agree with the architecture test. It describes the same seven ranks and the lower-rank-only `Contracts/`/`Events/` rule.
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php` already contains the exact Strategy, PortfolioProjects, and Risk codes frozen below. M00 must preserve them rather than invent parallel names.
- `apps/api/database/seeders/AuthorizationCatalogSeeder.php` derives `module_code` from the first capability segment and marks sensitive actions. New module plans must update the catalogue and tests, not insert ad hoc capability rows.
- `apps/api/routes/web.php` applies the repository-wide `api/v1` prefix and the Identity session/principal/CSRF middleware conventions.
- `docs/contracts/api/openapi.yaml` currently contains planned `/audit`, `/workspace`, `/strategy`, `/portfolio`, `/risk`, and `/records-governance` paths. It currently has no Collaboration path family. Task 12 may reconcile these entries, so M00 must re-read the post-T12 file before approval.
- `docs/contracts/api/README.md` makes the master OpenAPI authoritative and requires generation through `npm --prefix apps/web run api:generate`; intermediate bundles and `cluster.ts` are never hand-edited.
- `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php` is the event-type catalogue; `schemaPath()` converts dots to dashes for the matching file under `docs/contracts/schemas/`.
- `docs/contracts/schemas/README.md` requires one Draft 2020-12 CloudEvents schema for every produced event type.
- `apps/api/config/module_migrations.php` is the current serialized migration manifest. A later module adds its real migrations there only under its registry/integration token.
- `docs/superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md` Tasks 4, 6, 7, and 12 define the prerequisite exact-inventory, public-contract/outbox, HTTP primitive, and route/OpenAPI work.
- `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md` owns cross-plan status and token state; this plan owns detailed M00 evidence only.

The M00 evidence record must capture the post-handoff full commit and the observed values of these symbols. It must not rely on stale pre-handoff line numbers.

## 4. Scope and explicit non-goals

### In scope

- Create the normative planned-module decision manifest.
- Create the human-readable approval record and link it from the module catalogue.
- Freeze ranks, planned inventory, table reservations, capabilities, route/OpenAPI prefixes, public types, event literals, classification rules, idempotency/concurrency rules, migration order, test-fake rules, and serialized queue order.
- Compare the frozen values to post-handoff source and record every mismatch as a blocking decision, not an implicit edit.
- Record the exact downstream contract for M01–M07.
- Obtain explicit current-user approval and retain its evidence.

### Out of scope

- Runtime module directories or service providers.
- Controllers, handlers, repositories, workers, adapters, or production bindings.
- Migrations or direct database changes.
- Laravel routes or web routes/navigation.
- Master OpenAPI edits, Orval generation, or generated-client edits.
- Capability catalogue runtime changes or authorization seeding.
- Event enum cases or JSON Schema event files.
- Existing-module contract additions not listed in the frozen matrix.
- `Makefile`, CI workflow, production topology, deployment, or cloud changes.
- Reassignment of `sensitive_access_events`; it remains Authorization-owned until M01 receives an explicit data-migration and registry handoff.

## 5. Architecture and ownership boundaries

- Runtime flow is always module-owned controller → session/principal and capability check → boundary validation → handler/application service → module-owned persistence.
- Authorization occurs before detailed validation, record existence disclosure, or field projection.
- A module may import a lower-rank module only through that module's `Contracts/` or `Events/`. It never imports another module's Domain, Features, Infrastructure, persistence model, or migration.
- Same-rank imports are forbidden. In particular, Workspace rank 11 does not import Notifications, Search, or Reporting rank 11. The web composes those existing surfaces separately.
- Cross-module references are opaque UUIDv7 values plus a source module/type. A consumer validates them through a published contract at command time; it never adds a cross-owner FK, join, or direct SQL query.
- Internal FKs are allowed only between tables owned by the same module and are added after their parent table in that module's migration order.
- Each command commits state, the required M01 audit call/event, and—only when a downstream domain Event exists—the shared outbox append atomically. Controllers do not own transactions or outbox writes. Workspace preference mutation is the sole M00-defined exception described below.
- Workspace preferences are write-only via `PUT /api/v1/workspace/preferences`, which performs a full replacement guarded by `If-Match`. Workspace is not an event-producing module: the mutation publishes no domain Event and appends no outbox row. M00 freezes Workspace retry as at-most-once without server-side replay state: the `If-Match` predicate enforces optimistic concurrency, and the request does not carry `Idempotency-Key`. A successful write always increments `lock_version` and writes one M01 audit record; the command is not retry-safe, and there is no equal-representation no-op. A retry whose `If-Match` does not match the current version returns 412 and writes nothing. A different representation commits preference state, optimistic version, and M01 audit record in one module-owned transaction. M00 defines no Workspace idempotency table, no per-key replay record, no idempotency-key column, and no fingerprint column on `workspace_preferences`; the sole approved column set is `user_id`, `layout_json`, `lock_version`, and timestamps. Workspace clients must coalesce retries or send a fresh `If-Match` after a 412; the server stores nothing to make retries succeed.
- Published Events contain identifiers, timestamps, classification, source ownership facts, and non-sensitive state changes only. Free text, before/after payloads, secrets, PHI, and unrestricted metadata never enter Events.
- Contract fakes are test-only and deterministic. They have exact signatures and denial/not-found/stale/failure modes; they do not simulate a second production system.

## 6. Files to create, modify, move, or remove

### M00 execution files

- Create in `docs/architecture/`: `planned-module-contracts.yaml` — normative machine-readable decision manifest.
- Create in `docs/architecture/`: `planned-module-contracts.md` — rationale, approval record, PHI review, and downstream handoff guide; it must state that the YAML manifest is normative.
- Modify: `docs/architecture/module-catalog.md` — link the planned-module section to both M00 artifacts and state that implementation is applied serially per module.
- Create under `docs/superpowers/evidence/M00/`: `manifest.yaml` — stable evidence manifest.
- Create in that evidence directory during verification and retain: `planned-contracts-validator.txt`, `docs-validate.txt`, `boundaries.txt`, `api-check.txt`, `route-list.json`, and `decision-approval.txt`.

### Shared registry surfaces

The following are decision-owned by M00 only after T4 handoff and while `MODULE-REGISTRY` is granted:

- `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS`
- `apps/api/tests/Architecture/ModuleBoundariesTest.php::PLANNED_MODULES`
- `apps/api/tests/Architecture/ModuleBoundariesTest.php::TABLE_OWNERS`

M00 normally makes no change to these already-correct values. `MODULE_RANKS` continues to reserve all seven fixed planned ranks while the module directories do not yet exist; this is design metadata, not runtime registration. Each later module integration, one at a time, performs its runtime registry cutover in the same authorized integration change that adds the real module directory, all owned reversible migrations, and their manifest entries: it leaves every `MODULE_RANKS` value unchanged, removes only itself from `PLANNED_MODULES`, and adds exactly its actually created frozen tables to `TABLE_OWNERS`. A child plan must not remove a module from `PLANNED_MODULES` or add one of its table owners earlier or separately, and no table may be registered without its matching `Schema::create` migration in that same change.

### No move/remove operations

M00 moves or removes no file. It does not create any path under `apps/api/Modules/` or `apps/web/src/features/`.

## 7. Public Contracts, DTOs, Events, routes, schemas, and capabilities

The exact reservation matrix below is normative and must be copied into `planned-module-contracts.yaml` without renaming, aliases, pluralization changes, or alternate prefixes.

### 7.1 Rank, namespace, route, and capability reservations

| Plan | Module/rank | Full API prefix | OpenAPI/web prefix | Capabilities |
|---|---|---|---|---|
| M01 | `Audit` / 3 | `/api/v1/audit` | `/audit` | `audit.event.read`, `audit.event.export`, `audit.integrity.verify` |
| M02 | `RecordsGovernance` / 4 | `/api/v1/records-governance` | `/records-governance` | `records_governance.retention-policy.read`, `records_governance.retention-policy.manage`, `records_governance.retention-policy.publish`, `records_governance.record.read`, `records_governance.record.register`, `records_governance.hold.read`, `records_governance.hold.manage`, `records_governance.disposition.read`, `records_governance.disposition.review`, `records_governance.disposition.confirm` |
| M03 | `Collaboration` / 6 | `/api/v1/collaboration` | `/collaboration` | `collaboration.thread.create`, `collaboration.thread.read`, `collaboration.thread.list`, `collaboration.thread.update`, `collaboration.thread.archive`, `collaboration.membership.manage`, `collaboration.comment.create`, `collaboration.comment.edit`, `collaboration.comment.moderate` |
| M04 | `Strategy` / 8 | `/api/v1/strategy` | `/strategy` | Existing exact codes: `strategy.plan.read`, `strategy.plan.manage`, `strategy.indicator.read`, `strategy.indicator.manage`, `strategy.measurement.submit`, `strategy.measurement.approve`, `strategy.impact.read` |
| M05 | `PortfolioProjects` / 9 | `/api/v1/portfolio` | `/portfolio` | Existing exact codes: `portfolio_projects.portfolio.read`, `portfolio_projects.portfolio.manage`, `portfolio_projects.project.read`, `portfolio_projects.project.manage`, `portfolio_projects.milestone.approve`, `portfolio_projects.impact.submit`, `portfolio_projects.budget.read` |
| M06 | `Risk` / 10 | `/api/v1/risk` | `/risk` | Existing exact codes: `risk.risk.read`, `risk.risk.manage`, `risk.assess`, `risk.control.manage`, `risk.treatment.manage`, `risk.accept`, `risk.kri.manage` |
| M07 | `Workspace` / 11 | `/api/v1/workspace` | `/workspace` | `workspace.read`, `workspace.preferences.update` |

The M05 route token is `portfolio`, its capability token is `portfolio_projects`, and its event token is `portfolioprojects`. This intentional asymmetry is frozen and must not be normalized by a later implementer.

### 7.2 Exact published PHP types

All types live under `Modules\<Owner>\Contracts` unless explicitly listed under `Events`. DTOs are immutable, scalar/array-only boundary values and contain no Eloquent model or foreign module domain type.

| Owner | Published Contracts and exact signatures | Published DTOs |
|---|---|---|
| M01 Audit | `RecordAuditEvent::record(AuditEventInput $input): AuditEventReceipt`; `QueryAuditActivity::query(AuditActivityQuery $query): AuditActivityPage` | `AuditEventInput`, `AuditEventReceipt`, `AuditActivityQuery`, `AuditActivityItem`, `AuditActivityPage` |
| M02 RecordsGovernance | `RegisterGovernedRecord::register(GovernedRecordRegistration $registration): GovernedRecordStatus`; `ReadGovernedRecordStatus::get(RecordSourceReference $source): ?GovernedRecordStatus`; `GuardDispositionExecution::evaluate(RecordSourceReference $source): DispositionExecutionDecision`; `QueryRecordsGovernanceSummary::forScope(RecordsGovernanceSummaryQuery $query): RecordsGovernanceSummary` | `GovernedRecordRegistration`, `GovernedRecordStatus`, `RecordSourceReference`, `DispositionExecutionDecision`, `RecordsGovernanceSummaryQuery`, `RecordsGovernanceSummary` |
| M03 Collaboration | `OpenCollaborationThread::open(CollaborationThreadRegistration $registration): CollaborationThreadReference`; `ListVisibleCollaborationThreads::list(CollaborationThreadQuery $query): CollaborationThreadPage` | `CollaborationThreadRegistration`, `CollaborationThreadReference`, `CollaborationThreadQuery`, `CollaborationThreadSummary`, `CollaborationThreadPage` |
| M04 Strategy | `ResolveStrategyReference::resolve(StrategyAccessContext $context, StrategyResourceType $type, string $id): ?StrategyReference`; `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot` | `StrategyResourceType`, `StrategyReference`, `StrategyAccessContext`, `StrategySnapshot` |
| M05 PortfolioProjects | `ResolveAuthorizedProjectReference::resolve(ProjectAccessContext $context, string $projectId): ?ProjectReference`; `ListAuthorizedProjectSummaries::list(AuthorizedProjectSummaryQuery $query): AuthorizedProjectSummaryPage` | `ProjectAccessContext`, `ProjectReference`, `AuthorizedProjectSummaryQuery`, `AuthorizedProjectSummary`, `AuthorizedProjectSummaryPage` |
| M06 Risk | `ResolveRiskReference::resolve(RiskAccessContext $context, string $riskId): ?RiskReference`; `QueryRiskWorkspaceItems::query(RiskWorkspaceQuery $query): RiskWorkspacePage` | `RiskAccessContext`, `RiskReference`, `RiskWorkspaceQuery`, `RiskWorkspaceItem`, `RiskWorkspacePage` |
| M07 Workspace | No published cross-module Contract or Event | No public cross-module DTO; its HTTP view DTOs remain module-internal |

M07 consumes exactly these six lower-rank query contracts: M01 `QueryAuditActivity`, M02 `QueryRecordsGovernanceSummary`, M03 `ListVisibleCollaborationThreads`, M04 `GetStrategySnapshot`, M05 `ListAuthorizedProjectSummaries`, and M06 `QueryRiskWorkspaceItems`. For Strategy it constructs and passes the authenticated `StrategyAccessContext` plus `organizationUnitId` and optional `periodId`; M04 remains responsible for organization-unit/record authorization and must fail closed. M07 never calls the context-free Strategy signature, never reconstructs authorization, and never imports a same-rank contract.

| Consumer | Producer Contract | Exact authorization-bearing invocation |
|---|---|---|
| M07 Workspace | M01 `QueryAuditActivity` | `query(AuditActivityQuery $query): AuditActivityPage` |
| M07 Workspace | M02 `QueryRecordsGovernanceSummary` | `forScope(RecordsGovernanceSummaryQuery $query): RecordsGovernanceSummary` |
| M07 Workspace | M03 `ListVisibleCollaborationThreads` | `list(CollaborationThreadQuery $query): CollaborationThreadPage` |
| M07 Workspace | M04 `GetStrategySnapshot` | `forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot` |
| M07 Workspace | M05 `ListAuthorizedProjectSummaries` | `list(AuthorizedProjectSummaryQuery $query): AuthorizedProjectSummaryPage` |
| M07 Workspace | M06 `QueryRiskWorkspaceItems` | `query(RiskWorkspaceQuery $query): RiskWorkspacePage` |

The M04 row is the fixed canonical signature. `StrategyAccessContext` carries the authenticated principal/scope facts used by M04's record-level authorization. Omitting it, passing a raw principal instead, or reconstructing authorization outside M04 is a blocking contract violation.

M06 does not add a Tasks public port under Risk ownership. Due review and treatment-action work remains Risk-owned and is exposed through Risk queries/routes. A future Tasks integration requires a separate approved producer-owned contract amendment and token.

### 7.3 Exact Events and event-type literals

New planned-module events use an unhyphenated lowercase module token, the lowercase event class name without the `V1` suffix, and `.v1`: `com.cluster.<module-token>.<concatenated-event-name>.v1`. The fixed module tokens are `audit`, `recordsgovernance`, `collaboration`, `strategy`, `portfolioprojects`, `risk`, and `workspace`. Historical underscore/hyphen variants are outliers and are not copied. The event token does not have to equal the route or capability token.

| Owner | Event class | Exact event type |
|---|---|---|
| M01 | `AuditEventRecordedV1` | `com.cluster.audit.auditeventrecorded.v1` |
| M01 | `AuditExportCompletedV1` | `com.cluster.audit.auditexportcompleted.v1` |
| M01 | `AuditIntegrityViolationDetectedV1` | `com.cluster.audit.auditintegrityviolationdetected.v1` |
| M02 | `RetentionPolicyVersionPublishedV1` | `com.cluster.recordsgovernance.retentionpolicyversionpublished.v1` |
| M02 | `GovernedRecordStatusChangedV1` | `com.cluster.recordsgovernance.governedrecordstatuschanged.v1` |
| M02 | `RecordHoldChangedV1` | `com.cluster.recordsgovernance.recordholdchanged.v1` |
| M02 | `DispositionExecutionRequestedV1` | `com.cluster.recordsgovernance.dispositionexecutionrequested.v1` |
| M02 | `DispositionOutcomeConfirmedV1` | `com.cluster.recordsgovernance.dispositionoutcomeconfirmed.v1` |
| M03 | `CommentPublishedV1` | `com.cluster.collaboration.commentpublished.v1` |
| M03 | `MentionCreatedV1` | `com.cluster.collaboration.mentioncreated.v1` |
| M03 | `ThreadVisibilityChangedV1` | `com.cluster.collaboration.threadvisibilitychanged.v1` |
| M04 | `StrategyPlanPublishedV1` | `com.cluster.strategy.strategyplanpublished.v1` |
| M04 | `StrategyPlanRetiredV1` | `com.cluster.strategy.strategyplanretired.v1` |
| M04 | `StrategyProgressEvidenceApprovedV1` | `com.cluster.strategy.strategyprogressevidenceapproved.v1` |
| M05 | `ProjectLifecycleChangedV1` | `com.cluster.portfolioprojects.projectlifecyclechanged.v1` |
| M05 | `ProjectHealthSnapshotRecordedV1` | `com.cluster.portfolioprojects.projecthealthsnapshotrecorded.v1` |
| M05 | `ProjectProgressReportedV1` | `com.cluster.portfolioprojects.projectprogressreported.v1` |
| M05 | `ProjectIndicatorLinkChangedV1` | `com.cluster.portfolioprojects.projectindicatorlinkchanged.v1` |
| M06 | `RiskChangedV1` | `com.cluster.risk.riskchanged.v1` |
| M06 | `RiskReviewDueV1` | `com.cluster.risk.riskreviewdue.v1` |
| M06 | `RiskTreatmentActionDueV1` | `com.cluster.risk.risktreatmentactiondue.v1` |
| M07 | none | none |

Each later producer adds the enum case and the exact `docs/contracts/schemas/com-cluster-<token>-<concatenatedevent>-v1.schema.json` in the same change. Its failing contract test precedes producer implementation. The schema is CloudEvents 1.0 plus a `data` object, sets `additionalProperties: false`, identifies every required field, and excludes free text/PHI.

## 8. Database tables, indexes, constraints, migration order, rollback, and PHI classification

### 8.1 Exact table ownership reservations

| Owner | Reserved tables |
|---|---|
| M01 Audit | `audit_events`, `audit_export_jobs`, `audit_integrity_checkpoints`, `audit_idempotency_keys` |
| M02 RecordsGovernance | `records_governance_retention_policy_versions`, `records_governance_retention_policy_rules`, `records_governance_governed_records`, `records_governance_holds`, `records_governance_disposition_reviews`, `records_governance_evidence`, `records_governance_idempotency_keys` |
| M03 Collaboration | `collaboration_threads`, `collaboration_thread_memberships`, `collaboration_comments`, `collaboration_mentions`, `collaboration_comment_revisions`, `collaboration_moderation_actions`, `collaboration_idempotency_keys` |
| M04 Strategy | `strategy_periods`, `strategy_plans`, `strategy_plan_versions`, `strategy_objectives`, `strategy_outcomes`, `strategy_indicators`, `strategy_indicator_periods`, `strategy_target_distributions`, `strategy_measurements`, `strategy_progress_evidence`, `strategy_approvals`, `strategy_idempotency_keys` |
| M05 PortfolioProjects | `portfolio_projects_portfolios`, `portfolio_projects_programs`, `portfolio_projects_projects`, `portfolio_projects_project_templates`, `portfolio_projects_milestones`, `portfolio_projects_health_snapshots`, `portfolio_projects_budget_snapshots`, `portfolio_projects_indicator_links`, `portfolio_projects_idempotency_keys` |
| M06 Risk | `risk_registers`, `risk_policy_versions`, `risks`, `risk_assessments`, `risk_controls`, `risk_treatments`, `risk_treatment_actions`, `risk_reviews`, `risk_indicators`, `risk_indicator_readings`, `risk_idempotency_keys` |
| M07 Workspace | `workspace_preferences` |

No extra table is implied. In particular there is no module-private outbox, attachment table, Workspace projection cache, M01 retention-policy table, M05 dependency/progress table, or M06 link/history table in this baseline.

### 8.2 Required index and constraint rules

- Every table uses a UUIDv7 primary key, except `workspace_preferences`, whose natural owner key is `user_id` and whose row still carries `lock_version` and timestamps.
- Mutable aggregate tables carry non-negative `lock_version`; CAS writes use `WHERE id = ? AND lock_version = ?`, increment once, and return 412 when affected rows are zero.
- Code/version aggregates have owner-local unique constraints: Audit `event_id`; RecordsGovernance policy `(code, version_number)` and governed source `(source_module, source_type, source_id)`; Collaboration thread source tuple and membership `(thread_id, principal_id)`; Strategy code/version and indicator-period tuples; PortfolioProjects code and project/indicator tuples; Risk code and risk/indicator/observed-at tuples.
- Every idempotency table has an owner-scoped unique key, request fingerprint, stored status/body or result reference, and timestamps. Same key plus same fingerprint replays the stored result; the same key plus a different fingerprint is 409.
- Cursor-backed lists index a stable filter/sort tuple ending in UUIDv7 `id`. Cursor payloads use the authenticated codec finalized by closure Task 12 and bind resource, filter fingerprint, limit, principal, and scope.
- Owner-local child tables may FK only to the same owner's parent tables. Cross-module IDs are indexed opaque values with no FK and are resolved through the frozen Contract.
- Append-only audit, history/revision, measurement, snapshot, evidence, assessment, review, and reading rows are not updated in place; correction appends a superseding row or an explicitly modeled state transition.

### 8.3 Migration and registry order

Real module migrations are merged and added to `module_migrations.php` in this exact serial order: M01 → M02 → M03 → M04 → M05 → M06 → M07. Parallel module-core branches may exist, but the registry token is never concurrent.

Within each module, create parent/version tables before child/history tables, then idempotency tables; create internal FKs and unique indexes only after both owner-local tables exist. All seven planned `MODULE_RANKS` entries are prerequisites and remain unchanged throughout implementation. A module's runtime `MODULE-REGISTRY` cutover is indivisible and, in one integration change:

1. adds the real module directory and provider/runtime implementation;
2. creates all and only its real reversible migrations;
3. adds only those migrations to `module_migrations.php` in owner-local order;
4. proves its existing fixed `MODULE_RANKS` entry is unchanged;
5. removes only that module from `PLANNED_MODULES`;
6. adds exactly its frozen, actually created tables to `TABLE_OWNERS`;
7. proves fresh migrate and disposable up/down behavior; and
8. rejects missing owners, premature `PLANNED_MODULES` removal, premature or ghost table owners, a registry-only cutover, a module directory without all owned migrations, and any planned-list/table-owner mutation whose module directory and owned migrations are not present in the same integrated change.

The queue owner must reject and roll back the whole runtime cutover rather than land a partial registry state. It is forbidden to pre-register implementation status or table ownership to make a later branch pass; the pre-existing seven fixed rank reservations remain required and unchanged.

M01 does not silently take `sensitive_access_events`. That table remains Authorization-owned until a separately approved handoff specifies source/target counts, immutable mapping, cutover, rollback, and zero-loss proof.

### 8.4 PHI/PII classification freeze

| Module | Classification decision |
|---|---|
| Audit | IDs and access metadata are Confidential and may be PHI-linked. `audit_events` stores no raw source payload, free-text clinical content, secrets, tokens, or before/after object dumps. Export is capability-gated, audited, time-bounded, and redacted by field policy. |
| RecordsGovernance | Source references, holds, and disposition state are Confidential and may be PHI-linked. The module stores governance metadata only, never the governed source payload. |
| Collaboration | Thread/comment classification inherits the subject ceiling. Comment and revision bodies are PHI-capable free text: encrypt at rest, mask by authorization, exclude from Events/logs/errors/browser persistence, and audit every sensitive read/export. Mention events contain IDs and classification only. |
| Strategy | Strategy metadata is Confidential business data. Direct PHI is prohibited; evidence is an opaque Documents reference and names/notes must reject patient identifiers by product policy. |
| PortfolioProjects | Portfolio/project metadata is Confidential business data. Direct PHI is prohibited; evidence is an opaque Documents reference. |
| Risk | Enterprise risk metadata is Confidential. Direct patient/case PHI is prohibited; evidence is an opaque Documents reference. Clinical-case tracking requires a separately approved module/contract, not free text in Risk. |
| Workspace | `user_id` and preferences are PII, not PHI. Workspace stores layout/preferences only and never caches producer facts, PHI, query results, or same-rank module data. |

Across all modules, PHI/PII never appears in a URL, cursor plaintext, idempotency fingerprint output, problem response, correlation metadata, unsanitized log, browser local/session storage, or event payload. P04 reviews M01/M02/M03 enforcement after their implementation; that is a completion-phase gate for P04, not an M00 start dependency.

## 9. TDD execution tasks

### Task 1: Re-establish the post-closure baseline and handoffs

**Files:**
- Read: the sources listed in section 3
- Create during execution under `docs/superpowers/evidence/M00/`: `route-list.json`
- Create during execution under the same directory: `decision-approval.txt`

**Interfaces:**
- Consumes: T4/T6/T7/T12 handoff records and their full base commit.
- Produces: one evidence-backed comparison showing the seven exact ranks/planned entries, exact current table ownership, finalized public/outbox primitives, post-T12 route state, and the absence of any registry mutation whose module directory and owned migrations are not present in the same integration change.

- [ ] **Step 1: Prove all four gates are resolvable**

Read the orchestration status and each closure task's retained output. Record task, base commit, exact surface, and grant state. If any record is absent, set M00 to `blocked` with that exact missing gate and stop before editing documentation.

- [ ] **Step 2: Capture the post-handoff routes without changing them**

Run only after the gates pass:

```bash
M00_EVIDENCE_DIR="$(pwd)/docs/superpowers/evidence/M00"
mkdir -p "$M00_EVIDENCE_DIR"
cd apps/api
php artisan route:list --path=api/v1 --json > "$M00_EVIDENCE_DIR/route-list.json"
```

Expected: exit 0; valid JSON; no M01–M07 runtime controller is present before implementation.

- [ ] **Step 3: Compare exact registry symbols**

Run:

```bash
make verify-boundaries
```

Expected: PASS; all seven frozen planned ranks remain exact; no premature planned-list removal, ghost table owner, missing owner, registry-only cutover, planned module directory, cross-owner SQL/FK, or placement exception failure. Retain complete output later in `boundaries.txt`. A `PLANNED_MODULES` removal or `TABLE_OWNERS` addition without the matching real module directory and all owned migrations in the same integrated change is an exact blocking failure; the pre-existing planned `MODULE_RANKS` entries are expected and must not be treated as ghosts.

- [ ] **Step 4: Record discrepancies as approval blockers**

Compare all seven modules' exact ranks, complete ordered tables, API and OpenAPI/web prefixes, complete capabilities, exact Contract signatures/DTOs, every Event class/literal, and the Markdown matrix agreement required by sections 7–8. Also compare planned inventory, finalized outbox convention, owning-module integration tokens, and atomic registry-cutover state. Do not edit runtime to make the comparison pass. Any discrepancy is written into the approval record with source path, observed value, frozen value, affected downstream plan, and a required current-user decision.

### Task 2: Add a failing documentation reference before the decision artifacts

**Files:**
- Modify: `docs/architecture/module-catalog.md`
- Create in `docs/architecture/`: `planned-module-contracts.yaml`
- Create in `docs/architecture/`: `planned-module-contracts.md`
- Create: `scripts/validate-planned-module-contracts.py`
- Modify: `scripts/validate-docs.sh`

**Interfaces:**
- Consumes: the discrepancy-free or explicitly approved result from Task 1.
- Produces: one normative YAML manifest, one readable decision record linked by the authoritative module catalogue, and one exhaustive validator proving exact agreement across all seven reservation dimensions for M01–M07.

- [ ] **Step 1: Add the catalogue links first**

In the `docs/architecture/module-catalog.md` planned-modules section, add these two Markdown bullets by concatenating each code-span pair with no whitespace between them:

- `- [Planned module contract manifest]` + `(planned-module-contracts.yaml)`
- `- [Planned module contract decision record]` + `(planned-module-contracts.md)`

State there that M00 defines the decisions and the module registry queue applies M01→M07 one module at a time.

- [ ] **Step 2: Run the red documentation check**

Run:

```bash
make docs-validate
```

Expected: FAIL with `ERROR: broken link in docs/architecture/module-catalog.md: planned-module-contracts.yaml` and the equivalent `planned-module-contracts.md` error because those two catalogue targets do not exist yet. A different failure must be diagnosed before proceeding; it is not accepted as the red proof.

- [ ] **Step 3: Create the normative YAML**

Create `planned-module-contracts.yaml` with top-level keys `version`, `approved_design`, `prerequisite_handoffs`, `modules`, `event_types`, `classification`, `migration_order`, `integration_queues`, and `approval`. Under each module, copy verbatim the exact rank, route/OpenAPI prefixes, table list, capability list, public signatures/DTOs, event classes/literals, PHI decision, idempotency mode, concurrency mode, test-fake allowance, and required owning-module integration tokens from sections 7–12. Freeze M04's `StrategyAccessContext` signature and M07 consumer mapping exactly. Freeze the Workspace preference exception exactly: preference/version/idempotency plus M01 audit are atomic, no domain Event is published, and no outbox row is appended. The `approval` object records `state: pending_user_approval` until the current user explicitly approves; it does not contain invented names or dates.

- [ ] **Step 4: Add an exhaustive planned-module validator**

Create `scripts/validate-planned-module-contracts.py` and invoke it from `scripts/validate-docs.sh`. The validator must parse the YAML and Markdown rather than search source text, require exactly M01–M07, and compare all seven complete dimensions with strict equality: (1) exact module ranks, (2) complete ordered table lists, (3) full API and OpenAPI/web route prefixes, (4) complete capability lists, (5) exact Contract signatures and DTO names including the canonical context-bearing M04 `GetStrategySnapshot`, (6) every exact Event literal, and (7) authoritative token ownership.

Add deterministic negative fixtures in the validator's own temporary-copy/self-check mode, without modifying repository artifacts: rank `Strategy: 9` must fail with `ERROR: M04 rank mismatch: expected 8, got 9`; extra table `workspace_items` must fail with `ERROR: M07 tables mismatch: unexpected workspace_items`; route `/api/v1/portfolio-projects` must fail with `ERROR: M05 api_prefix mismatch: expected /api/v1/portfolio, got /api/v1/portfolio-projects`; missing `strategy.impact.read` must fail with `ERROR: M04 capabilities mismatch: missing strategy.impact.read`; the context-free `GetStrategySnapshot::forOrganizationUnit(string $organizationUnitId, ?string $periodId = null): StrategySnapshot` must fail with `ERROR: M04 contract mismatch: StrategyAccessContext is required`; event literal `com.cluster.portfolio-projects.projectlifecyclechanged.v1` must fail with `ERROR: M05 event literal mismatch: module token must be portfolioprojects`; and a Markdown/YAML M06 rank disagreement must fail with `ERROR: Markdown matrix mismatch for M06.rank: expected 10, got 9`. Run `python3 scripts/validate-planned-module-contracts.py --self-check`; expected: exit 0 and `Planned-module validator self-check passed: 7/7 negative fixtures rejected.` Retain that output as `planned-contracts-validator.txt`; any fixture accepted blocks Task 2.

- [ ] **Step 5: Create the readable decision record**

Create `planned-module-contracts.md` with the same seven-module matrix and complete rank/table/route/capability/Contract/Event values as YAML, rationale for every intentional asymmetry, the `sensitive_access_events` handoff rule, the Workspace same-rank prohibition and atomic-audit/no-domain-event exception, test-fake restrictions, owning-module integration tokens, queue protocol, and a link to the normative YAML. It must say that conflicts are resolved in favor of the YAML until an approved mutation updates both artifacts atomically.

- [ ] **Step 6: Run the green documentation and exhaustive contract checks**

Run:

```bash
python3 scripts/validate-planned-module-contracts.py
make docs-validate
```

Expected: the direct validator exits 0 with `Planned-module contract validation passed: 7 modules; ranks, tables, routes, capabilities, contracts, events, and Markdown agree.` Then `docs-validate` passes with `Documentation validation passed.` and no broken path, YAML parse, closure-register, exhaustive planned-module contract validator, or matrix-agreement error. Retain direct validator output in `docs/superpowers/evidence/M00/planned-contracts-validator.txt`; any sample-only comparison, accepted negative fixture, or mismatch blocks `in_progress → verification`.

### Task 3: Review the frozen contract as executable red/green handoffs

**Files:**
- Modify in `docs/architecture/`: `planned-module-contracts.yaml`
- Modify in `docs/architecture/`: `planned-module-contracts.md`
- Create under `docs/superpowers/evidence/M00/`: `decision-approval.txt`

**Interfaces:**
- Consumes: section 7/8 exact reservations.
- Produces: testable acceptance contracts for M01–M07, without creating their tests or runtime files.

- [ ] **Step 1: Define the required red test for every later module**

For each M01–M07 entry, record that its first implementation change must add a failing architecture test showing that its pre-existing fixed rank remains unchanged while the module directory, all owned migrations, migration manifest, planned-list removal, and table-owner additions form one atomic runtime cutover—not ghost implementation/table registration. Each public Contract gets a consumer test using a deterministic test fake before its production adapter. M04 and every consumer test use `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot`; the context-free signature is forbidden.

Expected red outcomes are exact: ghost/partial registry cutover, missing `TABLE_OWNERS` entries, absent interface/class, stale Strategy signature, absent event enum/schema, or route not found. Source-text assertions and tests that pass before implementation are rejected.

- [ ] **Step 2: Define the green condition for every later module**

Record that green requires module-owned behavior tests, the indivisible directory+migrations+registry cutover, event schema validation, no production fake binding, no cross-owner SQL/FK, exact route/OpenAPI generation under tokens, and final tests on the integrated commit. M03's Documents and Notifications packets are applied only by their owning queues. A narrow module test does not mark the module complete before its shared tokens merge.

- [ ] **Step 3: Obtain explicit current-user approval**

Present the exact matrix and every discrepancy/intentional asymmetry. Retain the user's approval text and date in `decision-approval.txt`; then update YAML `approval.state` to `approved` and record the same evidence path in the Markdown. Silence, agent agreement, or a passing command is not approval.

### Task 4: Verify and publish downstream handoffs

**Files:**
- Create/modify under `docs/superpowers/evidence/M00/`: `manifest.yaml`
- Create: the retained command outputs listed in section 6, including `planned-contracts-validator.txt`
- Modify after authorization only: this plan's status fields and the orchestration plan's M00 summary

**Interfaces:**
- Consumes: approved M00 artifacts.
- Produces: exact M01–M07 contract packets and a retained evidence manifest.

- [ ] **Step 1: Run targeted checks on the same working tree**

Run the commands in section 11 and retain untrimmed output. Any skip, stale generated output, or source mismatch blocks verification.

- [ ] **Step 2: Issue seven contract packets**

Each packet repeats its module's rank, tables, capabilities, prefixes, public signatures, Events, PHI decision, idempotency/concurrency rules, fake restrictions, producer-integration blockers, and required tokens. A packet may link to the approved YAML but must also carry the exact values needed by a cold-start executor.

- [ ] **Step 3: Record commit only after authorization**

If the current user authorizes a commit, record the resulting full commit in `implementation_commit`, `last_verified_commit`, the orchestration summary, and the evidence manifest. Without commit authorization, leave both fields `null` and M00 cannot transition to `completed`.

## 10. Failure, retry, idempotency, concurrency, authorization, and test-fake behavior

### HTTP and authorization

- All endpoints use Identity session and principal middleware. Mutations also require CSRF.
- Capability and classification checks precede detailed validation and resource disclosure.
- Errors use `application/problem+json`, the canonical correlation ID in header/body, and sanitized stable problem types.
- Missing/invalid session is 401; denied access is 403 or concealed 404 according to the frozen resource policy; malformed input is 400/422; idempotency fingerprint conflict is 409; stale strong ETag is 412.
- Versioned entity responses emit strong ETags derived from `lock_version`. Collections use `{items,next_cursor}` after T12 and never reveal unauthorized totals.

### Idempotency and retry

- Create and transition commands require `Idempotency-Key`; retries with the same fingerprint return the stored response and emit no duplicate audit/outbox effect.
- Same key with different normalized payload, principal, scope, or target returns 409.
- PATCH commands require `If-Match`; transition POSTs require both `If-Match` and `Idempotency-Key` when they mutate an existing aggregate.
- `PUT /api/v1/workspace/preferences` performs a full replacement guarded by `If-Match`. Workspace retry is at-most-once without server replay state: any retry whose `If-Match` does not match the current version returns 412 and writes nothing; a successful write always increments `lock_version` and emits one M01 audit record. The endpoint is not retry-safe; clients must coalesce or resend with a fresh `If-Match` after a 412.
- Workspace preference mutation is the sole M00-defined exception to the shared-outbox rule. Workspace stores no replay row, key, or fingerprint. Optimistic concurrency is the only retry control; no `Idempotency-Key` header is required on the Workspace preference endpoint.
- Transient outbox/consumer failures use bounded retry and dead-letter behavior from the shared runtime. Validation, authorization, idempotency conflict, and stale-write failures are not retried. Workspace preference writes have no outbox retry path; transaction failure rolls back preference/version/audit together and the client retries with a fresh `If-Match` after reading the current resource.

### Concurrency and atomicity

- State, required M01 audit record, and—only when a downstream domain Event exists—shared outbox append share one database transaction. Workspace preference mutation is the documented M00 exception: it commits preference state, optimistic version, and M01 audit, and writes no outbox row because no downstream side effect exists. Workspace has no idempotency table and no replay record.
- Append-only measurements, readings, snapshots, revisions, and audit rows use unique command/event IDs to deduplicate races.
- Concurrent publish/approve/accept/confirm operations have one winner and one 412/409 loser, with one state transition and one outbox event; concurrent Workspace preference updates have one winner, one 412 loser, one audit record for the winner, and zero outbox rows. A Workspace retry with a stale `If-Match` returns 412 and writes nothing, including no audit record.

### Exact test-fake policy

- M05 and M06 may build module-owned cores against deterministic in-memory fakes for the frozen lower-rank contracts while their producer integrations are blocked.
- M07 may use deterministic fakes for exactly the six query contracts listed in section 7.2 while final aggregation is blocked.
- A fake implements the exact interface and immutable DTOs, exposes configured allow/deny/not-found/stale/failure outcomes, and performs no network/database access.
- Fakes live under the consuming module's test tree, are bound only in tests, and are excluded from production service providers and container verification.
- A module cannot enter `verification` while an unresolved production fake binding, fallback branch, fixture bearer, or test-only adapter is reachable in production configuration.

## 11. Targeted verification commands and smoke scenarios

Do not run these while drafting this plan. Run them during authorized M00 execution after the four handoffs and approval.

```bash
python3 scripts/validate-planned-module-contracts.py --self-check
python3 scripts/validate-planned-module-contracts.py
make docs-validate
make verify-boundaries
npm --prefix apps/web run api:check
cd apps/api && php artisan route:list --path=api/v1 --json
```

Expected outcomes:

- validator self-check: PASS with all seven exact negative fixtures rejected; retain `planned-contracts-validator.txt`.
- direct planned-module validation: PASS only when all seven modules' exact ranks, complete tables, routes, capabilities, Contracts/DTOs, Event literals, and Markdown matrix agree with YAML.
- `docs-validate`: PASS; M00 Markdown/YAML paths resolve, YAML parses, and the exhaustive planned-module validator runs.
- `verify-boundaries`: PASS; seven planned modules still have no runtime directory; migrated table owners are exact; no cross-owner import/SQL/FK drift or partial/ghost registry cutover exists.
- `api:check`: PASS with zero generated drift; M00 did not edit the master contract or client.
- `route:list`: exit 0 and valid JSON; M00 added no runtime route.

Retain complete outputs in the paths from section 6. If a command is not available because the current closure has not handed it off, M00 remains blocked; it is not marked passed or skipped.

### Documentation smoke scenario

1. Start from the approved M00 YAML.
2. Select any module, for example M05.
3. Resolve its rank, all owned tables, capabilities, API/OpenAPI prefixes, public Contracts/DTOs, Events/literals, PHI rule, migration position, and required tokens without consulting another plan.
4. Confirm no table/capability/route/event collides with another module.

Expected: a cold-start executor can reconstruct the exact M05 handoff, including the intentional `portfolio` / `portfolio_projects` / `portfolioprojects` token asymmetry.

### Queue smoke scenario

1. Simulate M01 holding `MODULE-REGISTRY` on a recorded base commit.
2. Request the same token for M02.
3. Confirm M02 remains queued until M01 records merge/release.
4. Confirm neither holder also receives `OPENAPI`, `ORVAL`, or `WEB-SHELL` implicitly.

Expected: one active registry holder; independent tokens remain independently granted; a stale token is revoked/rebased rather than conflict-resolved ad hoc.

### Test-fake smoke scenario

1. Instantiate an M07 test with deterministic fakes for the six lower-rank query Contracts.
2. Configure one fake to deny, one to return empty, and four to return authorized summaries.
3. Confirm Workspace returns only authorized/available widgets and persists no producer fact.
4. Inspect production container bindings.

Expected: deterministic test result; `workspace_preferences` is the only Workspace table; no fake is production-bound.

## 12. Shared-file integration token requirements and downstream handoffs

The exact shared queue order is M01 → M02 → M03 → M04 → M05 → M06 → M07 for each relevant queue after current-owner handoff.

| Token | Surface | M00 rule |
|---|---|---|
| `MODULE-REGISTRY` | Three named architecture constants, module catalogue, real module directory, and real migration manifest | M00 freezes all seven planned ranks in advance. One module at a time later applies its indivisible directory+migrations+manifest+planned-list removal+table-owner cutover without changing any rank. No premature implementation status or table ownership. |
| `API-ROUTES` | `apps/api/routes/web.php` | Later module route integration only after active closure route ownership is released. |
| `OPENAPI` | `docs/contracts/api/openapi.yaml` | Later module reconciles only its frozen namespace after T12 handoff. |
| `ORVAL` | generated bundles and `apps/web/src/api/generated/cluster.ts` | Same holder as `OPENAPI`; generation command only, never hand editing. |
| `WEB-SHELL` | typed web routes/navigation | Serial module queue; M07 owns only the final aggregation token. |
| `DOCUMENTS-LINKED-FACTS` | Documents-owned `LinkedResourceAuthorizationFacts` composite/provider binding | M03 produces an exact integration packet; the Documents owner alone applies it after current-plan release. M03 never edits Documents provider bindings. |
| `COLLABORATION-SHARED-RELAY` | Shared-owned bounded relay/command/provider/tests for the three M03 event types | M03 produces an exact integration packet; the Shared outbox owner alone applies it before Notifications consumes the streams or P01 dispatches either command. M03 never edits Shared outbox internals. |
| `NOTIFICATIONS-COLLABORATION-CONSUMER` | Notifications-owned Collaboration event consumer/provider binding | M03 produces an exact integration packet; the Notifications owner alone applies it, then requests `PROD-WORKLOAD-REGISTRY`. M03 never edits Notifications internals. |
| `CLOSURE-CI` | `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml` | P08 only after Task 13 handoff; M00 never owns it. |

A token grant records token, state, requesting plan, releasing owner, full base commit, exact surface, grant evidence, expiry, and merge commit/result. One token has one granted holder. A module is not `completed` until its required tokens are processed and final tests pass on the integrated commit.

### Downstream phase gates

- M01 start: M00 approved plus `ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX`; `sensitive_access_events` transfer remains a distinct explicit handoff.
- M02 core start: M00 approved; final Audit integration remains blocked on M01.
- M03 core start: M00 approved; final RecordsGovernance integration remains blocked on M02. M03 publishes immutable `DOCUMENTS-LINKED-FACTS`, `COLLABORATION-SHARED-RELAY`, and `NOTIFICATIONS-COLLABORATION-CONSUMER` packets; only the Documents, Shared outbox, and Notifications owners respectively apply them.
- M04 core start: M00 approved; final RecordsGovernance integration remains blocked on M02.
- M05 core start: M00 approved with test fakes allowed; final Strategy integration remains blocked on M04.
- M06 core start: M00 approved with test fakes allowed; final Strategy/PortfolioProjects integration remains blocked on M04 and M05.
- M07 core start: M00 approved with six test fakes allowed; final aggregation remains blocked on M01–M06. Same-rank modules remain web-composed.

## 13. Rollback procedure

M00 rollback is documentation-only:

1. Revoke any unmerged M00 token and record the revocation reason/base commit.
2. Revert `planned-module-contracts.yaml`, `planned-module-contracts.md`, and the module-catalog links as one unit.
3. Revert M00 status/evidence references in the orchestration summary as one unit.
4. Do not roll back, rename, drop, or create any runtime table/route/module because M00 created none.
5. If a later module already consumed the baseline, do not silently rewrite M00. Use the plan mutation protocol: current-user approval, M00 version increment, affected child metadata, orchestration graph, token ownership, and every downstream dependency updated atomically.
6. If a wrong planned Event has not shipped, replace its reservation before producer implementation. If it has shipped, add a new version and migrate consumers; never mutate a published schema in place.
7. If a module registry token partially applied, the owning module rolls back its real migrations and registry change together on a disposable database, preserving data/evidence. M00 does not add a ghost owner to hide the failure.

## 14. Exit criteria and required retained evidence

M00 may transition to `completed` only when all criteria are true:

- T4, T6, T7, and T12 handoff records resolve to the same approved baseline commit lineage.
- The normative YAML and readable Markdown contain every exact reservation from sections 7–10 and disagree nowhere.
- The current user explicitly approved the frozen decisions; approval evidence is retained.
- The module catalogue links the baseline and states the serial application rule.
- No runtime module directory, migration, route, adapter, event enum/schema, generated client, shell, Makefile, or CI file was changed by M00.
- Current architecture constants still show the seven fixed rank reservations and seven planned modules; those ranks are legal before runtime directories and remain unchanged. No module is removed from `PLANNED_MODULES` and no table owner is added before its real module directory and all owned migrations land in that same integrated change.
- The exhaustive validator rejects all seven exact negative fixtures and proves exact equality for every module's rank, complete tables, routes, capabilities, Contracts/DTOs, Event literals, and Markdown matrix.
- All commands in section 11 pass on one recorded commit with no critical skip or stale generated output.
- Each M01–M07 contract packet is complete and carries its blocked integration phases.
- No production fake/stub/fallback is authorized.
- `implementation_commit` and `last_verified_commit` identify the same full recorded commit after explicit commit authorization.

The `manifest.yaml` under `docs/superpowers/evidence/M00/` must contain:

```yaml
plan_id: M00
commit: null
evidence_root: docs/superpowers/evidence/M00
commands:
  - name: planned-contracts-validator
    command: python3 scripts/validate-planned-module-contracts.py --self-check && python3 scripts/validate-planned-module-contracts.py
    exit_code: null
    output_path: planned-contracts-validator.txt
  - name: docs-validate
    command: make docs-validate
    exit_code: null
    output_path: docs-validate.txt
  - name: boundaries
    command: make verify-boundaries
    exit_code: null
    output_path: boundaries.txt
  - name: api-check
    command: npm --prefix apps/web run api:check
    exit_code: null
    output_path: api-check.txt
  - name: route-list
    command: cd apps/api && php artisan route:list --path=api/v1 --json
    exit_code: null
    output_path: route-list.json
approval_evidence: decision-approval.txt
open_findings: []
accepted_risks: []
```

During execution, replace each `null` result field with the observed value. `commit` remains `null` until a user-authorized commit exists; a completed manifest requires a 40-character lowercase hexadecimal commit, five zero exit codes, resolvable outputs, no open finding, and no unapproved accepted risk.

## 15. Status transition rules

- `blocked → ready`: all four architecture-closure handoffs and the `MODULE-REGISTRY` decision token are recorded on a full base commit.
- `ready → in_progress`: the M00 executor/worktree, base commit, and evidence paths are recorded; work remains documentation-only.
- `in_progress → blocked`: record the exact missing approval, changed source contract, handoff conflict, or token owner plus the last safe commit. Preserve completed evidence.
- `in_progress → verification`: both M00 decision artifacts are complete, internally consistent, explicitly approved, the exhaustive validator passes its seven negative fixtures and exact YAML/Markdown comparisons, and no runtime surface changed.
- `verification → completed`: every exit criterion and command passes on one user-authorized recorded commit; M01–M07 packets—including the M03 owning-module packets—are published; evidence paths resolve.
- Any status `→ superseded`: record current-user approval, replacement plan path, M00 version superseded, affected token state, and updated dependencies for M01–M07.
- A dependency, rank, table, capability, route, Contract, Event, classification, or queue change follows the mutation protocol in this order: approved design amendment → M00 artifacts → affected child plan metadata → orchestration inventory/graph → shared ownership/token queue → every downstream dependency → reason and approval evidence.

Planning completion does not satisfy M00 execution. This plan remains `blocked` until the named architecture-closure gates hand off their surfaces.
