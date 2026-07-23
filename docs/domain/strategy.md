---
doc_id: DOM-STG-001
title: Strategy and Indicators
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Strategy module owner
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
- docs/plans/release-2-strategy-portfolio.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

# Strategy and Indicators

## 1. Purpose and Ownership

The `Strategy` module owns strategic plans and their versions, axes, objectives
and initiatives, and is the sole owner of indicator definitions and their
versions, periods, targets, measurements and approvals. `PortfolioProjects`,
`Risk`, and `Reporting` MUST NOT create an indicator definition or duplicate an
existing one; those modules consume indicator identifiers, Strategy contracts,
and Strategy events.

An Initiative is a strategic element inside this module, not a level in the
`Portfolio → Program → Project` hierarchy. An Initiative MAY be linked to a
Project or Program via contract; ownership does NOT transfer to the Projects
module.

## 2. Terms and Models

| Term | Definition |
|---|---|
| `StrategicPlan` | A plan scoped to a unit and a period, published in a fixed version. |
| `Axis` | An axis inside a plan version. |
| `Objective` | A strategic objective under an axis. |
| `Initiative` | A strategic initiative that realizes an objective and MAY link to execution. |
| `Indicator` | The stable identity of an indicator and its organizational owner. |
| `IndicatorVersion` | A fixed definition: unit, direction, frequency, formula, numerator/denominator and evidence rules. |
| `IndicatorPeriod` | A measurement window derived from the indicator version frequency. |
| `TargetDistribution` | Cluster target and per-unit targets for an indicator version and period. |
| `Measurement` | A single unit reading for a specific period, including data, evidence and the approval cycle. |
| `IndicatorOwner` | The business owner of definition, distribution, and review — not implicitly the Super Admin. |
| `Coordinator` | The person who enters measurements within a defined organizational scope. |

### 2.1 Aggregates

- `StrategicPlanAggregate`: the plan and its published versions.
- `PlanVersionAggregate`: axes, objectives, and initiatives inside a fixed version.
- `IndicatorAggregate`: indicator identity, owner, coordinators, and status.
- `IndicatorVersionAggregate`: the calculation definition, frequency, and evidence policy.
- `TargetDistributionAggregate`: cluster target, distribution items, verification, and approval.
- `IndicatorPeriodAggregate`: period open/close and completeness status.
- `MeasurementAggregate`: unit inputs, calculated value, evidence, and approval decision.

### 2.2 Value Objects

- `MeasurementPeriod`, `IndicatorUnit`, `Baseline`, `TargetValue`.
- `DesiredDirection`: `higher|lower|within_range`.
- `Frequency`: `monthly|quarterly|semiannual|annual`.
- `AggregationFormula`: `weighted_average|ratio_of_sums|sum|average|latest` with governed parameters — no free-form code.
- `MeasurementInput`: `numerator`, `denominator`, `manual_value` per formula type.
- `AchievementResult`: value, target achievement ratio, color status, and calculation rationale.

## 3. Tables, Constraints, and Indexes

### 3.1 `strategic_plans`

- `id` BIGINT PK, `code` VARCHAR(64) UNIQUE NOT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL, `name_en` VARCHAR(255) NULL.
- `start_date`, `end_date` DATE NOT NULL.
- `status` VARCHAR(24) NOT NULL: `draft|in_review|published|retired`.
- `current_version_id` BIGINT NULL, `lock_version` INT NOT NULL DEFAULT 1.
- `created_by_user_id` BIGINT NOT NULL, timestamps.
- Constraint: `start_date < end_date`.

### 3.2 `strategic_plan_versions`

- `id` BIGINT PK, `strategic_plan_id` BIGINT NOT NULL FK.
- `version_number` INT NOT NULL, `status` VARCHAR(16) NOT NULL: `draft|published|retired`.
- `effective_from` DATE NOT NULL, `effective_until` DATE NULL.
- `published_by_user_id` BIGINT NULL, `published_at` DATETIME NULL.
- `workflow_version_id` BIGINT NULL.
- Unique constraint: `(strategic_plan_id, version_number)`.
- The published version is immutable.

### 3.3 `strategic_axes`

- `id` BIGINT PK, `plan_version_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL, `name_ar` VARCHAR(255) NOT NULL, `description` TEXT NULL.
- `weight` DECIMAL(7,4) NULL, `sort_order` INT NOT NULL.
- Unique constraint: `(plan_version_id, code)`.

### 3.4 `strategic_objectives`

- `id` BIGINT PK, `axis_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL, `name_ar` VARCHAR(255) NOT NULL, `description` TEXT NULL.
- `weight` DECIMAL(7,4) NULL, `owner_organization_unit_id` BIGINT NOT NULL.
- `sort_order` INT NOT NULL.
- Unique constraint: `(axis_id, code)`.

### 3.5 `strategic_initiatives`

- `id` BIGINT PK, `objective_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL, `name_ar` VARCHAR(255) NOT NULL, `description` TEXT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL, `owner_user_id` BIGINT NOT NULL.
- `status` VARCHAR(24) NOT NULL: `planned|active|on_hold|completed|cancelled`.
- `start_date`, `end_date` DATE NULL, `lock_version` INT NOT NULL DEFAULT 1.
- Unique constraint: `(objective_id, code)`.

### 3.6 `indicators`

- `id` BIGINT PK, `public_id` CHAR(26) UNIQUE NOT NULL, `code` VARCHAR(64) UNIQUE NOT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL, `owner_user_id` BIGINT NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL, `name_en` VARCHAR(255) NULL.
- `classification` VARCHAR(24) NOT NULL: `public|internal|confidential|top_secret`.
- `status` VARCHAR(24) NOT NULL: `draft|active|suspended|retired`.
- `current_version_id` BIGINT NULL, `lock_version` INT NOT NULL DEFAULT 1.
- Timestamps; indexes `(owner_organization_unit_id, status)`, `(owner_user_id, status)`.

### 3.7 `indicator_versions`

- `id` BIGINT PK, `indicator_id` BIGINT NOT NULL FK, `version_number` INT NOT NULL.
- `description` TEXT NOT NULL, `unit_code` VARCHAR(64) NOT NULL.
- `desired_direction` VARCHAR(24) NOT NULL.
- `range_min`, `range_max` DECIMAL(20,6) NULL.
- `frequency` VARCHAR(24) NOT NULL, `aggregation_formula` VARCHAR(32) NOT NULL.
- `formula_parameters` JSON NOT NULL.
- `requires_numerator`, `requires_denominator` BOOLEAN NOT NULL.
- `baseline_value` DECIMAL(20,6) NULL, `cluster_target_value` DECIMAL(20,6) NULL.
- `evidence_required` BOOLEAN NOT NULL DEFAULT TRUE.
- `measurement_workflow_version_id` BIGINT NOT NULL.
- `distribution_workflow_version_id` BIGINT NOT NULL.
- `effective_from` DATE NOT NULL, `effective_until` DATE NULL.
- `status` VARCHAR(16) NOT NULL: `draft|published|retired`.
- `published_at` DATETIME NULL, `published_by_user_id` BIGINT NULL.
- Unique constraint: `(indicator_id, version_number)`; the published version is immutable.

### 3.8 `indicator_coordinators`

- `id` BIGINT PK, `indicator_id` BIGINT NOT NULL FK.
- `user_id` BIGINT NOT NULL, `organization_unit_id` BIGINT NOT NULL.
- `valid_from` DATE NOT NULL, `valid_until` DATE NULL.
- Constraint prevents duplicate overlap per user/indicator/scope.
- Index: `(user_id, valid_from, valid_until)`.

### 3.9 `indicator_periods`

- `id` BIGINT PK, `indicator_version_id` BIGINT NOT NULL FK.
- `period_key` VARCHAR(32) NOT NULL, `starts_at`, `ends_at` DATE NOT NULL.
- `submission_opens_at`, `submission_closes_at` DATETIME NOT NULL.
- `status` VARCHAR(24) NOT NULL: `scheduled|open|under_review|locked|reopened`.
- `locked_at` DATETIME NULL, `lock_reason` VARCHAR(1000) NULL.
- Unique constraint: `(indicator_version_id, period_key)`.

### 3.10 `indicator_target_distributions`

- `id` BIGINT PK, `indicator_version_id` BIGINT NOT NULL, `period_id` BIGINT NOT NULL.
- `cluster_target_value` DECIMAL(20,6) NOT NULL.
- `expected_aggregate_value` DECIMAL(20,6) NULL.
- `status` VARCHAR(24) NOT NULL: `draft|submitted|in_approval|approved|returned|rejected`.
- `workflow_instance_id` BIGINT NULL, `approved_at` DATETIME NULL.
- `created_by_user_id` BIGINT NOT NULL, `lock_version` INT NOT NULL DEFAULT 1.
- Unique constraint: `(indicator_version_id, period_id)`.

### 3.11 `indicator_targets`

- `id` BIGINT PK, `distribution_id` BIGINT NOT NULL FK.
- `organization_unit_id` BIGINT NOT NULL.
- `target_value` DECIMAL(20,6) NOT NULL.
- `weight_basis` DECIMAL(20,6) NULL — expected sample size or governed weight.
- `rationale` VARCHAR(1000) NULL.
- Unique constraint: `(distribution_id, organization_unit_id)`.

### 3.12 `indicator_measurements`

- `id` BIGINT PK, `indicator_version_id` BIGINT NOT NULL, `period_id` BIGINT NOT NULL.
- `organization_unit_id` BIGINT NOT NULL, `submitted_by_user_id` BIGINT NOT NULL.
- `numerator`, `denominator`, `manual_value` DECIMAL(20,6) NULL.
- `calculated_value` DECIMAL(20,6) NOT NULL.
- `sample_size` DECIMAL(20,6) NULL.
- `status` VARCHAR(24) NOT NULL: `draft|submitted|in_review|returned|approved|rejected|locked`.
- `workflow_instance_id` BIGINT NULL.
- `submission_note` TEXT NULL, `return_reason` TEXT NULL.
- `approved_by_user_id` BIGINT NULL, `approved_at` DATETIME NULL.
- `lock_version` INT NOT NULL DEFAULT 1, timestamps.
- Unique constraint: `(indicator_version_id, period_id, organization_unit_id)`.

### 3.13 `indicator_measurement_evidence`

- `measurement_id` BIGINT NOT NULL, `document_id` BIGINT NOT NULL.
- `added_by_user_id` BIGINT NOT NULL, `created_at` DATETIME NOT NULL.
- Unique constraint: `(measurement_id, document_id)`.
- The document remains owned by `Documents` and its constraints and links apply.

## 4. Calculation Rules

- The server computes `calculated_value`; React MUST NOT be trusted to supply it.
- `ratio_of_sums = sum(numerator) / sum(denominator)` with zero-denominator blocked.
- `weighted_average = sum(value × weight_basis) / sum(weight_basis)`. This is the default template for distributing across facilities when a sample size exists.
- `average = sum(value) / count(valid values)`; `sum` and `latest` follow the version definition.
- `within_range` succeeds if `range_min <= value <= range_max`.
- For `higher`, a distribution achieves the cluster target when `expected >= cluster_target`; for `lower`, when `expected <= cluster_target`; for `within_range`, when inside the range.
- Rounding precision and missing-value policy are stored in `formula_parameters` and applied identically at measurement and reporting time.
- A target distribution MUST NOT be approved if its expected aggregate does not meet the cluster target.

## 5. Contracts

### 5.1 Commands

- `CreateStrategicPlan`, `CreatePlanVersion`, `AddStrategicAxis`, `AddStrategicObjective`, `AddStrategicInitiative`.
- `SubmitPlanVersionForApproval`, `PublishStrategicPlanVersion`, `RetireStrategicPlanVersion`.
- `CreateIndicator`, `CreateIndicatorVersion`, `AssignIndicatorOwner`, `AssignIndicatorCoordinator`.
- `ValidateIndicatorVersion`, `PublishIndicatorVersion`, `RetireIndicatorVersion`.
- `OpenIndicatorPeriod`, `ReopenIndicatorPeriod`, `LockIndicatorPeriod`.
- `CreateTargetDistribution`, `SetOrganizationTarget`, `SubmitTargetDistribution`, `ApplyTargetDistributionDecision`.
- `SaveMeasurementDraft`, `SubmitIndicatorMeasurement`, `ApplyMeasurementDecision`.

### 5.2 Queries

- `GetStrategicPlan`, `GetPublishedPlanVersion`, `ListObjectivesByScope`.
- `GetIndicatorDefinition(indicatorId, version?)`.
- `ListIndicatorsByAuthorizedScope(actor, filters)`.
- `GetIndicatorPeriod`, `GetOrganizationTarget`.
- `CalculateExpectedDistribution(distributionId)`.
- `CalculateIndicatorAggregate(indicatorVersionId, periodId, actor)`.
- `GetIndicatorScorecard(indicatorId, scope, periods, actor)`.
- `ListPendingIndicatorMeasurements(actor)`.

### 5.3 Contracts Exposed to Other Modules

- `ValidateIndicatorReference(indicatorId, requiredPeriod)`.
- `GetIndicatorReferenceSummary(actor, indicatorId)`.
- `GetIndicatorAchievement(indicatorId, periodId, scope)`.
- `ResolveIndicatorOwner(indicatorId, atTime)`.
- `ValidateInitiativeReference(initiativeId)`.
- `GetObservedIndicatorChange(indicatorId, scope, fromPeriod, toPeriod)` to support project-impact attribution.

The module MUST NOT expose a direct write to another module's measurement or target.

## 6. Workflow Integration and Approvals

- Every target distribution and measurement requires a published Workflow version pinned at workflow start.
- Strategy initiates the path; Workflow resolves the approver at step activation from Organization and Authorization.
- A Workflow decision alone MUST NOT mutate a Strategy table; the handler consumes a documented decision and applies the transition idempotently.
- The Super Admin can manage definitions, capabilities, and workflow paths, but MUST NOT approve a distribution or measurement in place of the resolved approver.
- There is NO Super Admin fallback when the approver seat is vacant; the published alternate path is used, or activation fails to a processing queue.
- Only a valid delegation permitted by policy can take a decision, and both the actor and the grantor are recorded.
- The submitter of a measurement MUST NOT approve their own measurement when the policy enforces duty separation.

## 7. Events

- `StrategicPlanCreated`
- `StrategicPlanVersionSubmitted`
- `StrategicPlanVersionPublished`
- `StrategicInitiativeCreated`
- `StrategicInitiativeStatusChanged`
- `IndicatorCreated`
- `IndicatorVersionPublished`
- `IndicatorOwnerAssigned`
- `IndicatorCoordinatorAssigned`
- `IndicatorPeriodOpened`
- `IndicatorTargetDistributed`
- `IndicatorTargetDistributionSubmitted`
- `IndicatorTargetDistributionApproved`
- `IndicatorMeasurementDrafted`
- `IndicatorMeasurementSubmitted`
- `IndicatorMeasurementReturned`
- `IndicatorMeasurementApproved`
- `IndicatorPeriodLocked`
- `IndicatorAggregateRecalculated`

Events carry identifiers and required values only — never evidence names or restricted fields. They use the Outbox, schema versioning, and idempotency.

## 8. State Machines

### 8.1 PlanVersion

```text
Draft -> InReview -> Published -> Retired
InReview -> Draft: return for edits
InReview -> Rejected: final rejection of the proposed version
```

### 8.2 IndicatorVersion

```text
Draft -> Published -> Retired
```

No edits to a published version; a new version is created and periods and measurements stay on their version.

### 8.3 TargetDistribution

```text
Draft -> Submitted -> InApproval -> Approved
InApproval -> Returned -> Draft
InApproval -> Rejected
```

### 8.4 Measurement

```text
Draft -> Submitted -> InReview -> Approved -> Locked
InReview -> Returned -> Draft
InReview -> Rejected
Approved -> Draft: only via ReopenPeriod with a logged correction that creates a new review cycle
```

### 8.5 IndicatorPeriod

```text
Scheduled -> Open -> UnderReview -> Locked
Locked -> Reopened -> Open: with independent capability, rationale, and audit trail
```

## 9. Invariants

- Strategy is the sole owner of indicators, definitions, periods, targets, and measurements.
- An Axis belongs to exactly one PlanVersion; an Objective belongs to exactly one Axis; an Initiative belongs to exactly one Objective.
- An Initiative MUST NOT enter the Portfolio/Program/Project hierarchy.
- A published version is fixed; every measurement and distribution pins `indicator_version_id`.
- Periods for the same version MUST NOT overlap, and they MUST match the published frequency.
- A unit Coordinator enters measurements for their scope only while the window is open.
- Evidence is mandatory when `evidence_required=true`, and its versions MUST be available and authorized.
- The calculated value derives from the version definition; it MUST NOT be edited manually.
- A target distribution MUST NOT be approved unless the expected aggregate meets the cluster target under the version's direction and formula.
- A period MUST NOT be locked while required measurements remain undecided except via a documented governance exception that specifies the missing values.
- An approved measurement MUST NOT be edited; corrections reopen the period and create a logged review cycle.
- The Super Admin MUST NOT stand in for an approver or an indicator owner on a business decision.
- Aggregation MUST NOT expose individual-facility measurements to a user whose capability is `view_aggregate` only.

## 10. Security and Capabilities

- Core capabilities: `strategy.manage_plan`, `strategy.view`, `indicator.manage_definition`, `indicator.distribute_target`, `indicator.submit_measurement`, `indicator.approve_measurement`, `indicator.export`.
- ABAC restricts the decision by unit, supervisory relation, owner/coordinator role, period, and classification.
- Aggregate indicator visibility MUST NOT grant detail-level read or evidence access.
- Measurement capability MUST NOT grant download of evidence; Documents applies the most-restrictive decision.
- Owner, definition, formula, target, approval, reopen, and export changes are recorded in Audit.
- Platform administration or Workflow setup MUST NOT grant the capability to view or approve confidential measurements.
- Search, Reporting, and Export use the same Authorization and Field-access controls; a restricted indicator's name and measurement count MUST NOT be exposed.
- `lock_version` is mandatory for concurrent edits.

## 11. Failure and Recovery

- An invalid formula definition, or a denominator required without a zero-handling policy: blocks publication with a detailed error.
- Missing targets or zero weights: blocks submission for approval.
- Expected aggregate fails the cluster target: the distribution stays Draft/Returned with a calculation rationale.
- Zero-denominator measurement: submission is rejected, or the published missing-value policy applies — no `Infinity` produced.
- Quarantined or unauthorized evidence: blocks submission.
- Approver vacancy without a fallback: the decision does NOT start and is NOT replaced by the admin; the state `approver_unresolved` is recorded for review.
- Workflow failure after draft save does NOT lose the draft; workflow start is idempotent.
- Duplicate Workflow events do NOT duplicate an approval.
- Board or index update failure MUST NOT roll back an approved measurement; the projection is rebuilt.
- Version conflict: `409` with current values.
- A coordinator reassignment during an open period applies at the next decision and does NOT change who made a prior entry.

## 12. Tests and Acceptance Criteria

### 12.1 Domain and Calculation

- Publish a plan with axes, objectives, and initiatives; published versions MUST NOT be editable.
- Prevent adding an Initiative into the projects hierarchy.
- Compute `ratio_of_sums` and `weighted_average` and `higher`/`lower`/`within_range` cases at the published precision and rounding.
- Prevent approving a distribution that does not meet the cluster target.
- Generate monthly and quarterly periods without overlap.
- Prevent editing an approved measurement without Reopen.

### 12.2 Workflow and Duty Separation

- A hospital Coordinator submits a measurement; the resolved indicator owner approves it.
- The approver returns the measurement with a stated reason and it returns to Draft.
- An unresolved Super Admin attempting approval is rejected.
- A valid delegate approves and both names are recorded; an expired delegate is rejected.
- Approver vacancy uses the published fallback only.

### 12.3 Security and Isolation

- An officer with `view_aggregate` sees the aggregate and NOT individual facility measurements or evidence.
- Two hospitals are isolated; a Coordinator of one MUST NOT see or edit the other.
- Field access hides numerator/denominator while allowing the final value when the policy permits.
- Search/report/export MUST NOT expose a restricted indicator, field, or evidence.

### 12.4 Contracts and Events

- `PortfolioProjects` and `Risk` verify an indicator reference via Contract without reading the tables.
- Schema tests for events and Outbox run once with the same transaction.
- Replaying `WorkflowCompleted` and `IndicatorMeasurementSubmitted` is idempotent.
- Rebuilding a scorecard from approved measurements yields the same result.

### 12.5 Acceptance

- Create a plan with axes, objectives, initiatives, and a published indicator version.
- Distribute distinct targets across facilities and approve them after the cluster target is met.
- Open a period, enter numerator/denominator and evidence, return it, resubmit it, approve it, and lock it.
- Display an aggregate card and a facility card per capability.

## 13. Dependencies and Integration Boundaries

- Depends on `Organization` for units, owners, coordinators, and approver resolution; on `Identity` for user summaries.
- Depends on `Authorization` for capabilities, scope, delegation, and classification; on `Workflow` for approvals with fixed versions.
- Depends on `Documents` for evidence via identifiers and links only; on `Audit` for sensitive actions.
- Depends on `Notifications` and `Reporting` as derived capabilities; their failure MUST NOT alter Strategy facts.
- Provides dependency to `PortfolioProjects` for indicator/initiative validation and observed-change reads, and to `Risk` for risk-indicator linkage.
- `PortfolioProjects` and `Risk` MUST NOT read indicator tables or write to them.
- Strategy MUST NOT depend on `PortfolioProjects` or `Risk` internals; reverse integration is through events, links, or governed read models.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Strategy module owner | Unify the front-end interface and pin indicator ownership |
