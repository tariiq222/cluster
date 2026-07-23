---
doc_id: ARC-MC-001
title: Canonical Module Catalog
type: architecture
status: accepted
version: 1.2.0
date: '2026-07-15'
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Information Security Lead
- Product Lead
classification: internal
review_cycle: semi-annual
sources: []
references: []
---
# Module Catalog

## 1. Catalog rules

- The following nineteen modules are the only legal boundaries.
- Each module owns its domain, tables, migrations, interfaces, events, and tests.
- Contracts are canonical routing names; DTO fields can be enriched without changing ownership or dependency direction.
- No contract returns an ORM model or exposes a Query Builder or table name.
- All access goes through `Authorization` using `RecordFacts` built by the owner.
- All asynchronous cross-boundary changes publish through the Transactional Outbox with at-least-once delivery.

## 2. Summary

| Module | Brief responsibility | Rank |
|---|---|---:|
| `PlatformSettings` | Versioned publication of general platform settings | 0 |
| `Organization` | Structure, positions, assignments, and organizational relations | 0 |
| `Identity` | Accounts, sessions, and the operational profile | 1 |
| `Authorization` | Centralized access decision: RBAC + ABAC | 2 |
| `Audit` | Append-only audit and sensitive access | 3 |
| `Workflow` | Definition and execution of routes with immutable versions | 4 |
| `RecordsGovernance` | Governed retention, hold, and disposition | 4 |
| `WorkDefinitions` | Work types, forms, fields, and versions | 5 |
| `Documents` | Files, versions, classification, and links | 5 |
| `Collaboration` | Discussion, comments, mentions, and participants | 6 |
| `Tasks` | The task, assignee, deadline, and lifecycle | 7 |
| `WorkRecords` | Dynamic business instances, including requests | 8 |
| `Strategy` | Plans, objectives, initiatives, and indicators | 8 |
| `PortfolioProjects` | Portfolios, programs, and projects | 9 |
| `Risk` | Risks, controls, and treatment plans | 10 |
| `Notifications` | In-platform notifications | 11 |
| `Search` | Governed internal search index | 11 |
| `Reporting` | Reports, dashboards, and Read Models | 11 |
| `Workspace` | Workspace and derived user inboxes | 11 |

## 3. `PlatformSettings`

**Responsibility:** Manage the general platform settings that do not belong to another domain, and publish them with traceable versions.

**Owns:**

- typed setting keys and values.
- setting versions, draft state, and publication.
- language, locale, default time zone, and session and password policies above the fixed security floor, and declared general operational limits.
- the version activation log; final security audit belongs to `Audit`.

**Synchronous contracts (feature handlers):**

- `GetEffectivePlatformSetting`
- `GetPlatformSettingsVersion`
- `PublishPlatformSettingsVersion`

**Events:**

- `PlatformSettingsVersionPublished`

**Depends on:** nothing.

**Does not own:** work-type form, route, indicator, or project settings. Domain settings remain in the domain module.

## 4. `Organization`

**Responsibility:** Represent the cluster, facilities, units, positions, the management hierarchy, assignments, supervisory relations, and their capabilities.

**Owns:**

- organizations, facilities, units, and their types.
- persons, basic PII, and the incremental `person_version` copy.
- positions, supervisory lines, and primary and temporary assignments.
- supervisory, functional, and coordination relations and their durations and capabilities.
- team memberships that are not login accounts or permission roles.

**Synchronous contracts (feature handlers):**

- `ResolveOrganizationScope`
- `ResolveDirectManager`
- `ResolvePositionHolder`
- `GetOrganizationUnitSummary`
- `GetActiveSupervisoryRelationships`
- `ValidateOrganizationReference`
- `ValidatePersonReference`

**Events:**

- `OrganizationUnitCreated`
- `OrganizationUnitMoved`
- `AssignmentStarted`
- `AssignmentEnded`
- `PersonAccessStatusChanged`
- `IdentityProvisioningRequested`
- `TemporaryAssignmentExpired`
- `SupervisoryRelationshipActivated`
- `SupervisoryRelationshipExpired`

**Depends on:** nothing.

**Does not own:** the account, password, role, or work or project record.

## 5. `Identity`

**Responsibility:** Local accounts, authentication, sessions, user lifecycle, and the operational profile.

**Owns:**

- users, credentials, and password change history.
- sessions, controlled recovery, and login attempts.
- the operational profile and identity-side preferences.
- `person_id` as an external identifier and a limited display summary, with no reference PII or FK to Organization.

**Synchronous contracts (feature handlers):**

- `AuthenticateUser`
- `GetUserIdentity`
- `ResolveActiveIdentity`
- `DisableUserAccount`
- `RevokeUserSessions`
- `ChangePassword`

**Events:**

- `UserAccountCreated`
- `UserAccountChanged`
- `UserAccountDisabled`
- `UserPasswordChanged`
- `UserSessionsRevoked`
- `UserProfileUpdated`

**Depends on:** `Organization`, `PlatformSettings`.

**Does not own:** the user's organization, position, or business role; it references them by identifiers and validates them through contracts.

## 6. `Authorization`

**Responsibility:** Turn capability, role, scope, relation, classification, state, delegation, and field policy into a centralized, explainable access decision.

**Owns:**

- roles, capabilities, and their assignments.
- delegations and their durations and constraints.
- classification policies and field access templates.
- the `RecordFacts` schema and the `AccessDecision` and `ScopePredicate` contracts.

**Synchronous contracts (feature handlers):**

- `DecideAccess(actor, capability, RecordFacts)`
- `ResolveFieldAccess`
- `BuildAuthorizedScopePredicate`
- `FilterReadableOrganizationScopes`
- `ExplainAccessDecision`

**Events:**

- `RoleAssigned`
- `RoleRevoked`
- `DelegationActivated`
- `DelegationExpired`
- `AuthorizationPolicyPublished`
- `AccessDecisionEvaluated` when non-blocking audit is required

**Depends on:** `Identity`, `Organization`, `PlatformSettings`.

**Cycle-prevention rule:** it does not depend on any record or business module and does not read its tables. The owning module builds `RecordFacts` from trusted data and then requests the decision.

## 7. `Audit`

**Status: planned for R2/R3**

**Responsibility:** Append-only log of sensitive actions, changes, access, downloads, and exports, with controlled search.

**Owns:**

- immutable audit events.
- sensitive access events.
- correlation and causation links and the actor / on-behalf-of identity at delegation.
- downstream consumption points for audit events.

**Synchronous contracts (feature handlers):**

- `AppendCriticalAuditEvent`
- `RecordSensitiveAccess`
- `QueryAuthorizedAuditTrail`

**Events:**

- `CriticalAuditEventAppended`
- `SensitiveAccessRecorded`

**Consumes:** published change events, including `Authorization` events.

**Depends on:** `Authorization` to protect audit trail queries. `Authorization` does not call it synchronously; permission-change events arrive via the Outbox, so no cycle arises.

**Does not own:** the in-domain user-facing activity log, and does not allow editing or deleting an audit event.

## 8. `Workflow`

**Responsibility:** Define routes and execute approvals, branching, parallelism, quorum, and escalation with immutable versions.

**Owns:**

- route definitions, drafts, and published versions.
- safe nodes, transitions, and conditions.
- route instances, steps, decisions, and escalations.

**Synchronous contracts (feature handlers):**

- `ValidateWorkflowVersion`
- `PublishWorkflowVersion`
- `StartWorkflow`
- `RecordWorkflowDecision`
- `ReturnWorkflowForRevision`
- `GetWorkflowInstanceState`

**Events:**

- `WorkflowVersionPublished`
- `WorkflowStarted`
- `WorkflowStepActivated`
- `WorkflowDecisionRecorded`
- `WorkflowCompleted`
- `WorkflowFailed`

**Depends on:** `Organization`, `Authorization`, `Audit`.

**Does not own:** the meaning of completing a work, project, or risk record. It receives `subject_ref` and the assignee resolution context and does not call the source module.
## 9. `RecordsGovernance`

**Status: planned for R2/R3**

**Responsibility:** Retention policies, legal or administrative hold, disposition review, and disposition evidence without owning record content.

**Owns:**

- the retention tables and their versions.
- general governance subjects linked to `record_ref`.
- hold orders and their durations and reasons.
- disposition review states and their proofs.

**Synchronous contracts (feature handlers):**

- `RegisterGovernedRecord`
- `ResolveRetentionPolicy`
- `PlaceRecordHold`
- `ReleaseRecordHold`
- `DecideDispositionEligibility`
- `ConfirmDispositionOutcome`

**Events:**

- `RecordHoldPlaced`
- `RecordHoldReleased`
- `RecordDispositionDue`
- `RecordDispositionApproved`
- `RecordDispositionCompleted`

**Depends on:** `PlatformSettings`, `Authorization`, `Audit`.

**Does not own:** payload, file, or any deletion inside the source. The owning module executes the disposition after an explicit decision and inside its own transaction, then confirms the outcome.

## 10. `WorkDefinitions`

**Responsibility:** Define dynamic work types, forms, fields, relations, lists, and their versions.

**Owns:**

- the work type definition, draft, and published version.
- field definitions, validation, layouts, and relations.
- list definitions and typed projection metadata.
- the link between a work type and a valid route version.

**Synchronous contracts (feature handlers):**

- `CreateWorkTypeDraft`
- `ValidateWorkTypeVersion`
- `PublishWorkTypeVersion`
- `GetPublishedWorkTypeSchema`
- `GetProjectionDefinition`

**Events:**

- `WorkTypeVersionPublished`
- `WorkTypeVersionRetired`

**Depends on:** `PlatformSettings`, `Workflow`, `Authorization`, `Audit`.

**Does not own:** any `WorkRecord` or operational payload. Deleting a published field means deprecating or hiding it, not destroying the previous values.

## 11. `Documents`

**Responsibility:** Files, metadata, classification, versions, links, and governed download.

**Owns:**

- document metadata, versions, checksum, and scan state.
- S3-compatible storage links.
- document links to `record_ref`.
- document-specific download and access events.

**Synchronous contracts (feature handlers):**

- `CreateDocument`
- `AddDocumentVersion`
- `LinkDocument`
- `AuthorizeDocumentDownload`
- `GetDocumentSummary`

**Events:**

- `DocumentCreated`
- `DocumentVersionAdded`
- `DocumentLinked`
- `DocumentDownloaded`
- `DocumentClassified`

**Depends on:** `RecordsGovernance`, `Authorization`, `Audit`.

**Does not own:** the file inside a business table, and does not grant the source link automatic access. It applies the strictest restrictions of the document and the linked record.

## 12. `Collaboration`

**Status: planned for R2/R3**

**Responsibility:** Record-linked discussions, comments, mentions, participants, subscriptions, and the collaboration activity log.

**Owns:**

- threads, comments, and logical versions for governed editing.
- participants, mentions, and subscriptions.
- comment links to documents.

**Synchronous contracts (feature handlers):**

- `CreateCollaborationThread`
- `AddComment`
- `MentionParticipant`
- `AddParticipant`
- `ListAuthorizedThread`

**Events:**

- `CollaborationThreadCreated`
- `CommentAdded`
- `ParticipantAdded`
- `ParticipantMentioned`

**Depends on:** `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Does not own:** task state or the source record. A mention adds a participant per policy and does not change the assignee or the source state.

## 13. `Tasks`

**Responsibility:** The standalone or linked task, its single assignee, participants from `Collaboration`, its deadline, priority, and lifecycle.

**Owns:**

- the task, `source_ref`, the creator, and the single assignee.
- priority, deadline, state, and the closing rule fixed at creation.
- the task transition log; text and attachments belong to the respective capability modules.

**Synchronous contracts (feature handlers):**

- `CreateTask`
- `AssignTask`
- `SubmitTaskCompletion`
- `AcceptTaskCompletion`
- `CompleteTask`
- `GetTaskSummary`

**Events:**

- `TaskCreated`
- `TaskAssigned`
- `TaskCompletionSubmitted`
- `TaskCompleted`
- `TaskCancelled`

**Depends on:** `Identity`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Does not own:** the source payload and does not grant task visibility on every source field. There are no sub-tasks in phase one.

## 14. `WorkRecords`

**Responsibility:** Instances of dynamic work types, their business state, Envelope, payload, scope, and stakeholders.

**Owns:**

- the `WorkRecord` Envelope: type, version, owner, creator, state, classification, visibility, and `lock_version`.
- the payload bound to a published `WorkDefinitions` version.
- the record's stakeholders and operational relations.
- typed projections the source produces for downstream consumers.

**Synchronous contracts (feature handlers):**

- `CreateWorkRecord`
- `SaveWorkRecordDraft`
- `SubmitWorkRecord`
- `TransitionWorkRecord`
- `ReturnWorkRecordForRevision`
- `CompleteWorkRecord`
- `GetAuthorizedWorkRecord`
- `ResolveWorkRecordFacts`

**Events:**

- `WorkRecordCreated`
- `WorkRecordSubmitted`
- `WorkRecordStateChanged`
- `WorkRecordReturnedForRevision`
- `WorkRecordCompleted`
- `WorkRecordClassified`

**Depends on:** `WorkDefinitions`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Request rule:** the generic internal request is a `WorkRecord` of a published work type whose code is `request`; `request` is a work type, not a data classification. There is no module, table, or Aggregate named `Requests`, and `Request*` events are not used.

## 15. `Strategy`

**Status: planned for R2/R3**

**Responsibility:** Plans, axes, objectives, initiatives, indicators, scorecards, targets, measurements, and approved impact.

**Owns:**

- strategic plans and their versions, axes, objectives, and initiatives.
- indicator definitions, versions, units, formulas, and owners.
- measurement periods, baselines, targets, and their distribution.
- measurements, evidence, and their approval decisions.
- the approved actual impact attributed to projects.

**Synchronous contracts (feature handlers):**

- `CreateStrategicPlan`
- `PublishStrategicPlanVersion`
- `GetIndicatorSummary`
- `DistributeIndicatorTarget`
- `SubmitIndicatorMeasurement`
- `ApproveIndicatorMeasurement`
- `RegisterProjectIndicatorLink`
- `SubmitProjectIndicatorImpact`
- `ApproveProjectIndicatorImpact`

**Events:**

- `StrategicPlanPublished`
- `IndicatorDefined`
- `IndicatorTargetDistributed`
- `IndicatorMeasurementSubmitted`
- `IndicatorMeasurementApproved`
- `ProjectIndicatorImpactApproved`

**Depends on:** `Organization`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Indicator rule:** `Strategy` is the sole owner of indicators. `Reporting`, `PortfolioProjects`, and `Risk` do not own them and do not copy their definitions or measurements.

## 16. `PortfolioProjects`

**Status: planned for R2/R3**

**Responsibility:** Portfolios, programs, projects, templates, phases, milestones, administrative budget, health, and planned impact.

**Owns:**

- portfolios, programs, and projects in the portfolio ← program ← project sequence.
- project templates and lifecycles, phases, milestones, baseline, and weights.
- project memberships and project-specific roles.
- administrative budget, health, and progress snapshots.
- the project link to `indicator_id` and expected impact as planning data, not the indicator definition or measurement.

**Synchronous contracts (feature handlers):**

- `CreatePortfolio`
- `CreateProgram`
- `CreateProject`
- `PublishProjectTemplate`
- `ApproveMilestone`
- `CalculateProjectProgress`
- `GetProjectSummary`
- `SubmitProjectImpactToStrategy`

**Events:**

- `ProjectCreated`
- `ProjectBaselineApproved`
- `MilestoneApproved`
- `ProjectProgressChanged`
- `ProjectHealthChanged`
- `ProjectImpactSubmitted`

**Depends on:** `Organization`, `Strategy`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Does not own:** strategic initiatives or indicators. Completion is computed from approved milestones and their evidence, not from task counts.

## 17. `Risk`

**Status: planned for R2/R3**

**Responsibility:** The enterprise risk register, controls, and treatment plans, with Strategy indicators linked as KRIs with thresholds, alerts, and escalation, and links to strategy and projects.

**Owns:**

- risks and their categories, sources, owners, and review dates.
- likelihood, impact, inherent, and residual assessment.
- controls and their effectiveness and risk responses.
- treatment plan links to tasks and links to objective, indicator, and project.
- KRI links, thresholds, alert rules, and escalation state; not the indicator definition or its measurements.

**Synchronous contracts (feature handlers):**

- `CreateRisk`
- `AssessRisk`
- `RegisterControl`
- `PlanRiskTreatment`
- `AcceptRisk`
- `LinkRiskIndicator`
- `ConfigureRiskIndicatorThreshold`
- `GetRiskSummary`

**Events:**

- `RiskCreated`
- `RiskAssessed`
- `RiskTreatmentPlanned`
- `CriticalRiskEscalated`
- `RiskIndicatorThresholdBreached`
- `RiskAccepted`

**Depends on:** `Organization`, `Strategy`, `PortfolioProjects`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**Status:** the `Risk` module is already planned in R3 in `docs/plans/release-3-risk.md`; the W3.0 specification for the matrix, appetite, and review cycles is an execution prerequisite for the existing plan, not a missing module plan or a conflict with it. `Strategy` remains the sole owner of KRI definitions and their measurements, and `Risk` owns only the links, thresholds, and alerts.

## 18. `Notifications`

**Responsibility:** Create, aggregate, and display in-platform notifications with read state and preferences.

**Owns:**

- notifications, recipients, and read state.
- notification preferences and aggregation rules.
- `source_ref` and the safe link without copying the source payload.
- the dedupe Inbox for consumed events.

**Synchronous contracts (feature handlers):**

- `ListMyNotifications`
- `MarkNotificationRead`
- `UpdateNotificationPreferences`

**Consumes:** events from `WorkRecords`, `Workflow`, `Tasks`, `Collaboration`, `Strategy`, `PortfolioProjects`, `Risk`, `RecordsGovernance` per declared policy.

**Depends on:** `Identity`, `Authorization`, and producer event contracts.

**Does not decide:** source visibility. The decision is re-requested from the owner's endpoint when the link is opened. No email, SMS, or WhatsApp in phase one.

## 19. `Search`

**Responsibility:** Index allowed text and fields and return results governed by scope, classification, and fields.

**Owns:**

- index definitions, progress marks, and derived search copies.
- the dedupe Inbox and the index document version.
- derived authorization facts required for the initial filter.

**Synchronous contracts (feature handlers):**

- `SearchAccessibleRecords`
- `RebuildSearchProjection`

**Consumes:** events from `WorkRecords`, `Tasks`, `Collaboration`, `Documents`, `Strategy`, `PortfolioProjects`, `Risk`.

**Depends on:** `Authorization` and producer event contracts.

**Does not own:** operational truth, and does not return a title, snippet, or field that is forbidden, and does not write to the source.

## 20. `Reporting`

**Responsibility:** Define cross-module reports, dashboards, and Read Models with governed export.

**Owns:**

- report and dashboard definitions and their templates.
- read projections and their rebuild status.
- aggregation and export definitions, not the business indicator definition.

**Synchronous contracts (feature handlers):**

- `RunAuthorizedReport`
- `GetAuthorizedDashboard`
- `ExportAuthorizedReport`
- `RebuildReportingProjection`

**Consumes:** events or Projection Feeds from `Organization`, `WorkRecords`, `Workflow`, `Tasks`, `Strategy`, `PortfolioProjects`, `Risk`.

**Depends on:** `Organization`, `Authorization`, and producer contracts.

**Does not own:** indicators or measurements, does not write to business data, and does not run heavy analytics on raw JSON or on the transaction path when performance would impact it.

## 21. `Workspace`

**Status: planned for R2/R3**

**Responsibility:** The user workspace and the personalized inboxes of approvals, tasks, returned items, and overdue items, plus saved views as a unified personal projection.

**Owns:**

- workspace items and their pointer to the source.
- saved views and personal display preferences.
- event consumption points and projection versions.

**Synchronous contracts (feature handlers):**

- `GetMyWorkspace`
- `GetOrganizationWorkspace`
- `SaveWorkspaceView`
- `RebuildWorkspaceProjection`

**Consumes:** events from `WorkRecords`, `Workflow`, `Tasks`, `Collaboration`, `Strategy`, `PortfolioProjects`, `Risk`.

**Depends on:** `Authorization` and producer event contracts.

**Does not own:** the state of any source item, and does not perform the transition on its behalf. The user is redirected to the owner's endpoint for re-delegation and execution.

## 22. Addition and change rules

- Post-R3 candidate areas are exploration only; they are not committed modules and their implementation is not bound.
- A new module is not created just because a screen or a table exists.
- Any new module needs an independent domain meaning, a data owner, contracts, a DAG rank, boundary tests, and an accepted ADR.
- An entity is not moved between modules silently; the change and the contract and data migration plan are documented.
- The `Shared` directory is used only for the Clock, identifiers, Transaction/Outbox primitives, and domain-neutral technical types; DTOs and domain policies are not placed there.
