# Cluster Planned-Module Contracts — Decision Record (M00)

> **Status:** The current user explicitly approved the exact M01–M07 matrix
> on 2026-07-27. M00 may enter verification. No commit, push, or completion
> was authorized; `implementation_commit` and `last_verified_commit` in the
> canonical YAML remain `null` until all required gates pass on one
> user-authorized recorded commit.

This file is the human-readable companion to
[`docs/architecture/planned-module-contracts.yaml`](planned-module-contracts.yaml)
(the **canonical, machine-readable** normative manifest). When this
Markdown and the YAML disagree, the YAML wins until an approved mutation
updates both artifacts atomically. M00 freezes the M01–M07 contract
surface so that M01–M07 may implement in parallel without colliding on
shared names; M00 itself creates no runtime, registry, route, table,
migration, generated-client, or seed change.

This decision record covers every value copied from the canonical YAML:

- exact rank, ordered table list, API/OpenAPI/web prefix, event token;
- every owned capability;
- every public Contract signature and DTO;
- every Event class and literal;
- classification, idempotency mode, concurrency mode;
- within-module migration rule and one-at-a-time serial registry
  cutover order;
- owning-module integration tokens, one-token/one-holder protocol, and
  the YAML mutation rule.

It also narrates the intentional asymmetries, the
`sensitive_access_events` handoff, the Workspace same-rank prohibition
with the atomic-audit / no-domain-event exception, and the test-fake
restrictions.

---

## 1 · Source of truth

- Approved source of truth: `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`
- M00 plan: `docs/superpowers/plans/2026-07-26-cluster-planned-module-contracts-baseline.md`
- M00 child plans:
  - M01: `docs/superpowers/plans/2026-07-26-cluster-audit-module.md`
  - M02: `docs/superpowers/plans/2026-07-26-cluster-records-governance-module.md`
  - M03: `docs/superpowers/plans/2026-07-26-cluster-collaboration-module.md`
  - M04: `docs/superpowers/plans/2026-07-26-cluster-strategy-module.md`
  - M05: `docs/superpowers/plans/2026-07-26-cluster-portfolio-projects-module.md`
  - M06: `docs/superpowers/plans/2026-07-26-cluster-risk-module.md`
  - M07: `docs/superpowers/plans/2026-07-26-cluster-workspace-module.md`
- Architecture-test surfaces (M00 owns only these constants and only after
  T4 handoff):
  - `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS`
  - `apps/api/tests/Architecture/ModuleBoundariesTest.php::PLANNED_MODULES`
  - `apps/api/tests/Architecture/ModuleBoundariesTest.php::TABLE_OWNERS`
- Shared event catalogue: `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`
- Schema directory and version: `docs/contracts/schemas/` —
  CloudEvents 1.0 + JSON Schema Draft 2020-12.
- Master OpenAPI: `docs/contracts/api/openapi.yaml`.
- Web client bundle: `apps/web/src/api/generated/cluster.ts` (generated
  via `npm --prefix apps/web run api:generate`; never hand-edited).

---

## 2 · Prerequisite handoffs (M00 plan §1)

M00 remains `blocked` until all four architecture-closure handoffs are
present on the same approved baseline commit lineage:

| Handoff            | Surface                                                                                                                                                                                                                                  | Token granted          |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| ARCHITECTURE-CLOSURE:T4 | `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS`; `::PLANNED_MODULES`; `::TABLE_OWNERS`; `docs/architecture/module-catalog.md` (post-T4 edit)                                                                | `MODULE-REGISTRY`      |
| ARCHITECTURE-CLOSURE:T6 | `apps/api/app/Providers/AppServiceProvider.php`; `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php` (final event catalogue location); `Shared\Contracts\TransactionalOutbox` ownership                                       | `SHARED-OUTBOX`        |
| ARCHITECTURE-CLOSURE:T7 | `apps/api/Shared/Http` (canonical problem renderer, correlation request attribute, resource envelope, strong ETag parser)                                                                                                              | `SHARED-HTTP`          |
| ARCHITECTURE-CLOSURE:T12 | `apps/api/routes/web.php`; `docs/contracts/api/openapi.yaml`; `apps/web/src/api/generated/cluster.ts`; `apps/api/Shared/Http/AuthenticatedCursorCodec.php` (bounded cursor contract)                                                | `ROUTE-OPENAPI`        |

The base commit lineage on `architecture-closure-2026-07-26` for every
one of these handoffs is:

```
4c3e22c → 83b733d → 1fd523b → b031b30 → 8bab3a4 → 175da30 → 8414127
       → ab9578e → b4a9851 → 7889df4 → 447f756 → 3fa4528 → c90dd10
       → da27c81 → df2588c
```

All four handoffs are recorded as `state: pending_authorization`. A task
completion claim without resolvable evidence is not a handoff.

---

## 3 · M01–M07 matrix

The seven planned-module entries are M01–M07 and **only** M01–M07.
Existing implemented modules (Shared, PlatformSettings, Organization,
Identity, Authorization, Workflow, WorkDefinitions, Documents, Tasks,
WorkRecords, Notifications, Search, Reporting) appear only as
prerequisite facts, not as module entries.

### 3.1 · M01 Audit (rank 3)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/audit` |
| OpenAPI/web prefix | `/audit` |
| Event token | `audit` |
| Owned tables (in order) | `audit_events`, `audit_export_jobs`, `audit_integrity_checkpoints`, `audit_idempotency_keys` |
| Capabilities | `audit.event.read`, `audit.event.export`, `audit.integrity.verify` |
| Public Contracts | `RecordAuditEvent::record(AuditEventInput $input): AuditEventReceipt`; `QueryAuditActivity::query(AuditActivityQuery $query): AuditActivityPage` |
| Public DTOs | `AuditEventInput`, `AuditEventReceipt`, `AuditActivityQuery`, `AuditActivityItem`, `AuditActivityPage` |
| Events | `AuditEventRecordedV1`, `AuditExportCompletedV1`, `AuditIntegrityViolationDetectedV1` |
| Classification | `confidential_phi_linked` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `append_only_unique_event_id` |
| Test fakes allowed | No |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

**`sensitive_access_events` handoff.** M01 does **not** silently take
`sensitive_access_events`. That table remains Authorization-owned until
a separately approved handoff specifies source/target counts, immutable
mapping, cutover, rollback, and zero-loss proof. The historical debt
note was retired on 2026-07-27 when Audit was activated at rank 3
because Audit exposes the access-event ingest through its Contracts
(`RecordAuditEvent`) and Authorization keeps the column-rich
access-decision history local.

### 3.2 · M02 RecordsGovernance (rank 4)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/records-governance` |
| OpenAPI/web prefix | `/records-governance` |
| Event token | `recordsgovernance` |
| Owned tables (in order) | `records_governance_retention_policy_versions`, `records_governance_retention_policy_rules`, `records_governance_governed_records`, `records_governance_holds`, `records_governance_disposition_reviews`, `records_governance_evidence`, `records_governance_idempotency_keys` |
| Capabilities | `records_governance.retention-policy.read`, `records_governance.retention-policy.manage`, `records_governance.retention-policy.publish`, `records_governance.record.read`, `records_governance.record.register`, `records_governance.hold.read`, `records_governance.hold.manage`, `records_governance.disposition.read`, `records_governance.disposition.review`, `records_governance.disposition.confirm` |
| Public Contracts | `RegisterGovernedRecord::register(GovernedRecordRegistration $registration): GovernedRecordStatus`; `ReadGovernedRecordStatus::get(RecordSourceReference $source): ?GovernedRecordStatus`; `GuardDispositionExecution::evaluate(RecordSourceReference $source): DispositionExecutionDecision`; `QueryRecordsGovernanceSummary::forScope(RecordsGovernanceSummaryQuery $query): RecordsGovernanceSummary` |
| Public DTOs | `GovernedRecordRegistration`, `GovernedRecordStatus`, `RecordSourceReference`, `DispositionExecutionDecision`, `RecordsGovernanceSummaryQuery`, `RecordsGovernanceSummary` |
| Events | `RetentionPolicyVersionPublishedV1`, `GovernedRecordStatusChangedV1`, `RecordHoldChangedV1`, `DispositionExecutionRequestedV1`, `DispositionOutcomeConfirmedV1` |
| Classification | `confidential_phi_linked` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `optimistic_lock_version_with_cas` |
| Test fakes allowed | No |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

### 3.3 · M03 Collaboration (rank 6)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/collaboration` |
| OpenAPI/web prefix | `/collaboration` |
| Event token | `collaboration` |
| Owned tables (in order) | `collaboration_threads`, `collaboration_thread_memberships`, `collaboration_comments`, `collaboration_mentions`, `collaboration_comment_revisions`, `collaboration_moderation_actions`, `collaboration_idempotency_keys` |
| Capabilities | `collaboration.thread.create`, `collaboration.thread.read`, `collaboration.thread.list`, `collaboration.thread.update`, `collaboration.thread.archive`, `collaboration.membership.manage`, `collaboration.comment.create`, `collaboration.comment.edit`, `collaboration.comment.moderate` |
| Public Contracts | `OpenCollaborationThread::open(CollaborationThreadRegistration $registration): CollaborationThreadReference`; `ListVisibleCollaborationThreads::list(CollaborationThreadQuery $query): CollaborationThreadPage` |
| Public DTOs | `CollaborationThreadRegistration`, `CollaborationThreadReference`, `CollaborationThreadQuery`, `CollaborationThreadSummary`, `CollaborationThreadPage` |
| Events | `CommentPublishedV1`, `MentionCreatedV1`, `ThreadVisibilityChangedV1` |
| Classification | `confidential_phi_capable_free_text` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `optimistic_lock_version_with_cas` |
| Test fakes allowed | No |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL`, `DOCUMENTS-LINKED-FACTS`, `COLLABORATION-SHARED-RELAY`, `NOTIFICATIONS-COLLABORATION-CONSUMER` |

**Owning packets.** M03 publishes immutable `DOCUMENTS-LINKED-FACTS`,
`COLLABORATION-SHARED-RELAY`, and `NOTIFICATIONS-COLLABORATION-CONSUMER`
packets. Only the Documents, Shared outbox, and Notifications owners
respectively apply them. M03 never edits Documents provider bindings,
Shared outbox internals, or Notifications internals.

### 3.4 · M04 Strategy (rank 8)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/strategy` |
| OpenAPI/web prefix | `/strategy` |
| Event token | `strategy` |
| Owned tables (in order) | `strategy_periods`, `strategy_plans`, `strategy_plan_versions`, `strategy_objectives`, `strategy_outcomes`, `strategy_indicators`, `strategy_indicator_periods`, `strategy_target_distributions`, `strategy_measurements`, `strategy_progress_evidence`, `strategy_approvals`, `strategy_idempotency_keys` |
| Capabilities | `strategy.plan.read`, `strategy.plan.manage`, `strategy.indicator.read`, `strategy.indicator.manage`, `strategy.measurement.submit`, `strategy.measurement.approve`, `strategy.impact.read` |
| Public Contracts | `ResolveStrategyReference::resolve(StrategyAccessContext $context, StrategyResourceType $type, string $id): ?StrategyReference`; `GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot` |
| Public DTOs | `StrategyResourceType`, `StrategyReference`, `StrategyAccessContext`, `StrategySnapshot` |
| Events | `StrategyPlanPublishedV1`, `StrategyPlanRetiredV1`, `StrategyProgressEvidenceApprovedV1` |
| Classification | `confidential_business_no_phi` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `optimistic_lock_version_with_cas` |
| Test fakes allowed | No |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

**Canonical M04 signature.** The fixed canonical M04 signature is
`GetStrategySnapshot::forOrganizationUnit(StrategyAccessContext $context,
string $organizationUnitId, ?string $periodId = null): StrategySnapshot`.
`StrategyAccessContext` carries the authenticated principal/scope facts
used by M04's record-level authorization. Omitting it, passing a raw
principal instead, or reconstructing authorization outside M04 is a
blocking contract violation. The context-free signature is forbidden.

### 3.5 · M05 PortfolioProjects (rank 9)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/portfolio` |
| OpenAPI/web prefix | `/portfolio` |
| Event token | `portfolioprojects` |
| Owned tables (in order) | `portfolio_projects_portfolios`, `portfolio_projects_programs`, `portfolio_projects_projects`, `portfolio_projects_project_templates`, `portfolio_projects_milestones`, `portfolio_projects_health_snapshots`, `portfolio_projects_budget_snapshots`, `portfolio_projects_indicator_links`, `portfolio_projects_idempotency_keys` |
| Capabilities | `portfolio_projects.portfolio.read`, `portfolio_projects.portfolio.manage`, `portfolio_projects.project.read`, `portfolio_projects.project.manage`, `portfolio_projects.milestone.approve`, `portfolio_projects.impact.submit`, `portfolio_projects.budget.read` |
| Public Contracts | `ResolveAuthorizedProjectReference::resolve(ProjectAccessContext $context, string $projectId): ?ProjectReference`; `ListAuthorizedProjectSummaries::list(AuthorizedProjectSummaryQuery $query): AuthorizedProjectSummaryPage` |
| Public DTOs | `ProjectAccessContext`, `ProjectReference`, `AuthorizedProjectSummaryQuery`, `AuthorizedProjectSummary`, `AuthorizedProjectSummaryPage` |
| Events | `ProjectLifecycleChangedV1`, `ProjectHealthSnapshotRecordedV1`, `ProjectProgressReportedV1`, `ProjectIndicatorLinkChangedV1` |
| Classification | `confidential_business_no_phi` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `optimistic_lock_version_with_cas` |
| Test fakes allowed | **Yes** |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

**M05 frozen asymmetry (intentional).** The M05 route token is
`portfolio`, its capability token is `portfolio_projects`, and its event
token is `portfolioprojects`. The three tokens are intentionally not
normalized and must not be unified by a later implementer; the YAML
freezes this asymmetry.

### 3.6 · M06 Risk (rank 10)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/risk` |
| OpenAPI/web prefix | `/risk` |
| Event token | `risk` |
| Owned tables (in order) | `risk_registers`, `risk_policy_versions`, `risks`, `risk_assessments`, `risk_controls`, `risk_treatments`, `risk_treatment_actions`, `risk_reviews`, `risk_indicators`, `risk_indicator_readings`, `risk_idempotency_keys` |
| Capabilities | `risk.risk.read`, `risk.risk.manage`, `risk.assess`, `risk.control.manage`, `risk.treatment.manage`, `risk.accept`, `risk.kri.manage` |
| Public Contracts | `ResolveRiskReference::resolve(RiskAccessContext $context, string $riskId): ?RiskReference`; `QueryRiskWorkspaceItems::query(RiskWorkspaceQuery $query): RiskWorkspacePage` |
| Public DTOs | `RiskAccessContext`, `RiskReference`, `RiskWorkspaceQuery`, `RiskWorkspaceItem`, `RiskWorkspacePage` |
| Events | `RiskChangedV1`, `RiskReviewDueV1`, `RiskTreatmentActionDueV1` |
| Classification | `confidential_business_no_phi` |
| Idempotency mode | `server_replay_with_idempotency_key` |
| Concurrency mode | `optimistic_lock_version_with_cas` |
| Test fakes allowed | **Yes** |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

**M06 no Tasks public port.** M06 does not add a Tasks public port
under Risk ownership. Due review and treatment-action work remains
Risk-owned and is exposed through Risk queries/routes. A future Tasks
integration requires a separate approved producer-owned contract
amendment and token.

### 3.7 · M07 Workspace (rank 11)

| Field | Value |
| --- | --- |
| API prefix | `/api/v1/workspace` |
| OpenAPI/web prefix | `/workspace` |
| Event token | `workspace` |
| Owned tables (in order) | `workspace_preferences` |
| Capabilities | `workspace.read`, `workspace.preferences.update` |
| Public Contracts | **None** (M07 has no published cross-module Contract) |
| Public DTOs | **None** (HTTP view DTOs remain module-internal) |
| Events | **None** (M07 does not publish a domain Event) |
| Classification | `pii_no_phi` |
| Idempotency mode | `none_atomic_if_match_preference_write` |
| Concurrency mode | `optimistic_lock_version_with_if_match` |
| Test fakes allowed | **Yes** |
| Required integration tokens | `MODULE-REGISTRY`, `API-ROUTES`, `OPENAPI`, `ORVAL`, `WEB-SHELL` |

**M07 same-rank prohibition.** Workspace is rank 11. It must not
import from any other rank-11 module (Notifications, Search, Reporting,
or any future rank-11 module). The web composes those existing rank-11
surfaces separately. The same rule applies to every M01–M07 entry: a
module may only import a lower-rank module through that module's
`Contracts/` or `Events/` namespace, never via a same-rank import.

**M07 atomic-audit / no-domain-event exception.** Workspace preferences
are write-only via `PUT /api/v1/workspace/preferences` and perform a
full replacement guarded by `If-Match`. Workspace is **not** an
event-producing module: the mutation publishes no domain Event and
appends no outbox row. M07 retry is at-most-once without server-side
replay state. The `If-Match` predicate enforces optimistic concurrency
and the request does not carry `Idempotency-Key`. A successful write
always increments `lock_version` and writes one M01 audit record; the
command is not retry-safe and there is no equal-representation no-op. A
retry whose `If-Match` does not match the current version returns 412
and writes nothing (no audit record either). Preference state, the
optimistic version, and the M01 audit record commit atomically.

**M07 consumer mapping.** M07 consumes exactly six lower-rank query
contracts while its producer integrations are blocked:

| Consumer | Producer Contract | Exact authorization-bearing invocation |
| --- | --- | --- |
| M07 Workspace | M01 `QueryAuditActivity` | `query(AuditActivityQuery $query): AuditActivityPage` |
| M07 Workspace | M02 `QueryRecordsGovernanceSummary` | `forScope(RecordsGovernanceSummaryQuery $query): RecordsGovernanceSummary` |
| M07 Workspace | M03 `ListVisibleCollaborationThreads` | `list(CollaborationThreadQuery $query): CollaborationThreadPage` |
| M07 Workspace | M04 `GetStrategySnapshot` | `forOrganizationUnit(StrategyAccessContext $context, string $organizationUnitId, ?string $periodId = null): StrategySnapshot` |
| M07 Workspace | M05 `ListAuthorizedProjectSummaries` | `list(AuthorizedProjectSummaryQuery $query): AuthorizedProjectSummaryPage` |
| M07 Workspace | M06 `QueryRiskWorkspaceItems` | `query(RiskWorkspaceQuery $query): RiskWorkspacePage` |

For Strategy, M07 constructs and passes the authenticated
`StrategyAccessContext` plus `organizationUnitId` and optional
`periodId`; M04 remains responsible for organization-unit /
record-level authorization and must fail closed. M07 never calls the
context-free Strategy signature, never reconstructs authorization, and
never imports a same-rank contract.

---

## 4 · Event catalogue (M00 plan §7.3)

The format is `com.cluster.<module-token>.<concatenated-event-name>.v1`,
where the module token is the unhyphenated lowercase token and the
concatenated event name is the lowercase event class name without the
`V1` suffix. The fixed module tokens are `audit`, `recordsgovernance`,
`collaboration`, `strategy`, `portfolioprojects`, `risk`, and `workspace`.
Historical underscore/hyphen variants are not copied. The event token
does not have to equal the route or capability token (M05 is the
canonical example).

| Owner | Event class | Exact event type |
| --- | --- | --- |
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

Each later producer adds the enum case and the exact
`docs/contracts/schemas/com-cluster-<token>-<concatenatedevent>-v1.schema.json`
in the same change. The schema is CloudEvents 1.0 plus a `data` object,
sets `additionalProperties: false`, identifies every required field, and
excludes free text / PHI.

---

## 5 · Classification (M00 plan §8.4)

| Module | `contains_phi` | `contains_pii` | Classification | Rationale (summary) |
| --- | --- | --- | --- | --- |
| M01 Audit | true | true | `confidential` | IDs and access metadata are Confidential and may be PHI-linked. `audit_events` stores no raw source payload, free-text clinical content, secrets, tokens, or before/after object dumps. Export is capability-gated, audited, time-bounded, and redacted by field policy. |
| M02 RecordsGovernance | true | true | `confidential` | Source references, holds, and disposition state are Confidential and may be PHI-linked. The module stores governance metadata only, never the governed source payload. |
| M03 Collaboration | true | true | `confidential` | Thread/comment classification inherits the subject ceiling. Comment and revision bodies are PHI-capable free text: encrypt at rest, mask by authorization, exclude from Events/logs/errors/browser persistence, and audit every sensitive read/export. Mention events contain IDs and classification only. |
| M04 Strategy | false | true | `confidential` | Strategy metadata is Confidential business data. Direct PHI is prohibited; evidence is an opaque Documents reference and names/notes must reject patient identifiers by product policy. |
| M05 PortfolioProjects | false | true | `confidential` | Portfolio/project metadata is Confidential business data. Direct PHI is prohibited; evidence is an opaque Documents reference. |
| M06 Risk | false | true | `confidential` | Enterprise risk metadata is Confidential. Direct patient/case PHI is prohibited; evidence is an opaque Documents reference. Clinical-case tracking requires a separately approved module/contract, not free text in Risk. |
| M07 Workspace | false | true | `confidential` | `user_id` and preferences are PII, not PHI. Workspace stores layout/preferences only and never caches producer facts, PHI, query results, or same-rank module data. |

**Global rule.** PHI/PII never appears in a URL, cursor plaintext,
idempotency fingerprint output, problem response, correlation metadata,
unsanitized log, browser local/session storage, or event payload. P04
reviews M01/M02/M03 enforcement after their implementation; that is a
completion-phase gate for P04, not an M00 start dependency.

---

## 6 · Migration order and indivisible registry cutover

### 6.1 Serial registry order

The serial registry order is fixed and indivisible:

```
M01 → M02 → M03 → M04 → M05 → M06 → M07
```

Parallel module-core branches may exist during development, but the
`MODULE-REGISTRY` token is **never concurrent**: exactly one module at a
time applies the indivisible directory + migrations + manifest +
planned-list removal + table-owner cutover.

### 6.2 Within-module rule

Within each module, create parent/version tables before child/history
tables, then idempotency tables; create internal FKs and unique indexes
only after both owner-local tables exist.

### 6.3 Cutover rule (one-at-a-time, M01 → M07)

A module's runtime `MODULE-REGISTRY` cutover is indivisible and, in one
integration change:

1. adds the real module directory and provider/runtime implementation;
2. creates all and only its real reversible migrations;
3. adds only those migrations to `module_migrations.php` in owner-local
   order;
4. proves its existing fixed `MODULE_RANKS` entry is unchanged;
5. removes only that module from `PLANNED_MODULES`;
6. adds exactly its frozen, actually created tables to `TABLE_OWNERS`;
7. proves fresh migrate and disposable up/down behavior; and
8. rejects missing owners, premature `PLANNED_MODULES` removal,
   premature or ghost table owners, a registry-only cutover, a module
   directory without all owned migrations, and any planned-list /
   table-owner mutation whose module directory and owned migrations are
   not present in the same integrated change.

The seven fixed `MODULE_RANKS` entries are prerequisites and remain
unchanged throughout implementation. Premature `PLANNED_MODULES`
removal, ghost table owners, or a registry-only cutover are forbidden.

### 6.4 `sensitive_access_events` handoff (recap)

M01 does not silently take `sensitive_access_events`. That table remains
Authorization-owned until a separately approved handoff specifies
source/target counts, immutable mapping, cutover, rollback, and
zero-loss proof.

---

## 7 · Owning-module integration tokens

A token has **one** granted holder. A token grant records token, state,
requesting plan, releasing owner, full base commit, exact surface, grant
evidence, expiry, and merge commit / result. A module is not completed
until its required tokens are processed and final tests pass on the
integrated commit.

### 7.1 Shared queue order

The shared queue order repeats the registry order:

```
M01 → M02 → M03 → M04 → M05 → M06 → M07
```

### 7.2 Token catalog

| Token | Surface | Holder | M00 rule |
| --- | --- | --- | --- |
| `MODULE-REGISTRY` | `apps/api/tests/Architecture/ModuleBoundariesTest.php::MODULE_RANKS`; `::PLANNED_MODULES`; `::TABLE_OWNERS`; `docs/architecture/module-catalog.md`; real module directory; real migration manifest | rotating | M00 freezes all seven planned ranks in advance. One module at a time later applies its indivisible directory+migrations+manifest+planned-list removal+table-owner cutover without changing any rank. No premature implementation status or table ownership. |
| `API-ROUTES` | `apps/api/routes/web.php` | rotating | Later module route integration only after active closure route ownership is released. |
| `OPENAPI` | `docs/contracts/api/openapi.yaml` | rotating | Later module reconciles only its frozen namespace after T12 handoff. |
| `ORVAL` | generated bundles; `apps/web/src/api/generated/cluster.ts` | same as `OPENAPI` | Same holder as `OPENAPI`; generation command only, never hand-editing. |
| `WEB-SHELL` | typed web routes / navigation | rotating | Serial module queue; M07 owns only the final aggregation token. |
| `DOCUMENTS-LINKED-FACTS` | Documents-owned `LinkedResourceAuthorizationFacts` composite/provider binding | documents_owner | M03 produces an exact integration packet; the Documents owner alone applies it after current-plan release. M03 never edits Documents provider bindings. |
| `COLLABORATION-SHARED-RELAY` | Shared-owned bounded relay / command / provider / tests for the three M03 event types | shared_outbox_owner | M03 produces an exact integration packet; the Shared outbox owner alone applies it before Notifications consumes the streams or P01 dispatches either command. M03 never edits Shared outbox internals. |
| `NOTIFICATIONS-COLLABORATION-CONSUMER` | Notifications-owned Collaboration event consumer / provider binding | notifications_owner | M03 produces an exact integration packet; the Notifications owner alone applies it, then requests `PROD-WORKLOAD-REGISTRY`. M03 never edits Notifications internals. |
| `CLOSURE-CI` | `Makefile`; `.github/workflows/ci.yml`; `.github/workflows/ci-e2e.yml` | p08_after_T13_handoff | P08 only after Task 13 handoff; M00 never owns it. |

### 7.3 Downstream phase gates

| Gate | Prerequisites |
| --- | --- |
| M01 start | M00 approved **plus** `ARCHITECTURE-CLOSURE:AUTHORIZATION-OUTBOX`; `sensitive_access_events` transfer remains a distinct explicit handoff. |
| M02 core start | M00 approved; final Audit integration remains blocked on M01. |
| M03 core start | M00 approved; final RecordsGovernance integration remains blocked on M02. M03 publishes immutable `DOCUMENTS-LINKED-FACTS`, `COLLABORATION-SHARED-RELAY`, and `NOTIFICATIONS-COLLABORATION-CONSUMER` packets; only the Documents, Shared outbox, and Notifications owners respectively apply them. |
| M04 core start | M00 approved; final RecordsGovernance integration remains blocked on M02. |
| M05 core start | M00 approved with test fakes allowed; final Strategy integration remains blocked on M04. |
| M06 core start | M00 approved with test fakes allowed; final Strategy / PortfolioProjects integration remains blocked on M04 and M05. |
| M07 core start | M00 approved with six test fakes allowed; final aggregation remains blocked on M01–M06. Same-rank modules remain web-composed. |

---

## 8 · Test-fake restrictions

Fakes are only permitted for the modules whose matrix entry sets
`test_fake_allowed: true` (M05, M06, M07). M01, M02, M03, and M04 do
**not** permit test fakes for cross-module Contracts.

When test fakes are permitted, the following restrictions apply (taken
from the M05/M06/M07 module entries):

- A module may build module-owned cores against deterministic in-memory
  fakes for the frozen lower-rank contracts while its producer
  integrations are blocked.
- Fakes implement the **exact** interface and immutable DTOs of the
  frozen Contract.
- Fakes expose configured allow / deny / not-found / stale / failure
  outcomes.
- Fakes perform **no** network or database access.
- Fakes live under `tests/`.
- Fakes are bound **only in tests** (never in production service
  providers and never in container verification).
- M07 may use deterministic fakes for exactly the six lower-rank query
  contracts (`M01 QueryAuditActivity`, `M02 QueryRecordsGovernanceSummary`,
  `M03 ListVisibleCollaborationThreads`, `M04 GetStrategySnapshot`,
  `M05 ListAuthorizedProjectSummaries`, `M06 QueryRiskWorkspaceItems`)
  while final aggregation is blocked. Same binding / lifecycle
  restrictions as M05/M06.

---

## 9 · Module-boundary rules captured here

These rules are repeated from the YAML so the readable record remains
self-contained:

1. **Rank ordering.** A module may only depend on strictly lower-rank
   modules, and only through the dependency's `Contracts/` or `Events/`
   namespace. `test_detects_a_cross_module_domain_import` enforces this.
2. **Same-rank prohibition.** Rank-11 modules (Notifications, Search,
   Reporting, Workspace) never import each other. The web composes them
   separately.
3. **Atomic-audit / no-domain-event exception (M07 only).** Workspace
   preferences commit preference state, optimistic version, and one M01
   audit record atomically, publish **no** domain Event, and append
   **no** outbox row. They are the only M00-defined exception to the
   rule that every command commits state, the required M01 audit
   call/event, and—only when a downstream domain Event exists—the
   shared outbox append atomically.
4. **Controller placement.** HTTP controllers must not own transactions
   or write to the outbox directly. The outbox is owned by the
   application's handler layer (and shared infrastructure for the
   shared transactional outbox).
5. **Forbidden identifier.** No class, interface, trait, or enum may be
   named `Request*` because Laravel reserves that word for HTTP request
   objects. `test_rejects_requests_as_a_business_module_or_identifier`
   enforces this.

---

## 10 · Approval state

M00 approval is **approved**.

- State: `approved`.
- Approval date: `2026-07-27`.
- Approval evidence:
  `docs/superpowers/evidence/M00/decision-approval.txt`.
- `implementation_commit`: `null`.
- `last_verified_commit`: `null`.
- Satisfied for `approved`:
  - `explicit_user_approval_of_exact_matrix`
  - `retained_approval_evidence`
- Still required for `completed`:
  - `approved_state`
  - `five_zero_exit_codes`
  - `retained_command_outputs`
  - `user_authorized_recorded_commit`

The four architecture-closure handoffs (T4, T6, T7, T12) are recorded as
the source commit lineage in section 2. On 2026-07-27, the current user
selected “اعتماد المصفوفة كما هي” after presentation of the seven exact
module ranks and the aggregate 51 owned tables, 45 capabilities, and 21
published event types. This satisfies M00 plan §3 Task 3 Step 3 and allows
`in_progress → verification`.

The approval does not authorize a commit, push, or completion claim.
`verification → completed` still requires every named gate to pass on one
user-authorized recorded commit. M01 verification may proceed, but M00 and
M01 completion remain blocked on that commit gate.

### 10.1 Evidence paths (existing, not yet committed)

| Path | Purpose |
| --- | --- |
| `docs/superpowers/evidence/M00/decision-approval.txt` | Retained explicit approval of the exact frozen matrix on 2026-07-27; no commit authorization. |
| `docs/superpowers/evidence/M00/route-list.json` | Post-handoff route snapshot taken before any M01–M07 runtime controller. |
| `docs/superpowers/evidence/M00/manifest.yaml` | Stable evidence manifest (planned). |
| `docs/superpowers/evidence/M00/planned-contracts-validator.txt` | Direct validator output (planned). |
| `docs/superpowers/evidence/M00/docs-validate.txt` | `make docs-validate` output (planned). |
| `docs/superpowers/evidence/M00/boundaries.txt` | `make verify-boundaries` output (planned). |
| `docs/superpowers/evidence/M00/api-check.txt` | `make api:check` output (planned). |

---

## 11 · YAML-wins mutation rule

When this Markdown and the canonical YAML disagree, the YAML wins
until an approved mutation updates both artifacts atomically. The M00
plan (`docs/superpowers/plans/2026-07-26-cluster-planned-module-contracts-baseline.md`)
is the authoritative source for the values copied below; sections 7–12
of that plan freeze every value above. Any future change to the M01–M07
matrix must update the YAML first, then this Markdown, then
`MODULE_RANKS` / `PLANNED_MODULES` / `TABLE_OWNERS` only when the
applicable `MODULE-REGISTRY` token is held by an authorized
integration change.
