---
doc_id: PLN-R2-001
title: Integrated R2 Plan — Strategy, Portfolio, and Projects Cycle
type: plans
status: accepted
version: 3.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: at each R2 package closure
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
- docs/plans/release-1/w1-3-frontend-slices.md
- docs/adr/021-strategy-indicator-ownership.md
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
references:
- docs/domain/strategy.md
- docs/domain/portfolio-projects.md
- docs/domain/collaboration-tasks-workspace.md
- docs/domain/authorization.md
---
# Integrated R2 Plan

## Goal

Build a strategic management cycle that starts from reality analysis and
direction formulation, then the plan, performance scorecard, objectives,
indicators, initiatives, and reviews, and ends by converting initiatives into
portfolios, programs, projects, and impact measurement. The R2 product is
dedicated to the Third Health Cluster experience, and multi-tenant redesign
is not part of this path.

`Strategy` does not include project, task, or risk detail. Ownership stays
distributed across modules, and integration runs through contracts, events,
IDs, and a single Authorization decision.

## Baseline Verdict

- `Strategy` and `PortfolioProjects` are defined in the docs only; no code,
  tables, or API exist in the current state.
- `Tasks` exists as an operational nucleus for creating a task from Workflow
  and completing it, but it does not yet apply the linked tasks specification
  in full and does not consume `DecideAccess` on all its paths.
- `RbacAbacDecideAccess` exists, but the current operational binding is still
  `FixtureFacilityDecision`, and some R1 controllers use a development
  principal.
- Accordingly, R2 integration does not start before W1.3 security closure and
  the shared Tasks contract hardening. R2 tests and contracts can be prepared,
  but no new fixture or bypass is allowed.

## Ownership Boundaries

| Module | Owns | Does Not Own |
|---|---|---|
| `Strategy` | Analysis, formulation, plan and its versions, scorecards, objectives, indicators, initiatives, reviews, and approved actual impact | Portfolios, projects, tasks, and risks |
| `PortfolioProjects` | Portfolios, programs, projects, templates, phases, milestones, baselines, administrative budget, health, and expected impact | Initiatives, indicator definition, and its measurements |
| `Tasks` | Task, assignment, participation, comments, completion cycle, and activity | Objective, project, or source access decisions |
| `Workflow` | Review path definition, its instance, steps, and decisions | Plan, indicator, or project state |
| `Authorization` | RBAC, ABAC, scope, delegation, deny, classification, and field decisions | Record facts or domain data |
| `Documents` | Files, versions, scanning, and download links | Evidence meaning or plan and project approval decisions |
| `Reporting`, `Search`, `Workspace`, `Notifications` | Rebuildable derived projections | Any source operational truth |

## Mandatory Integration Pattern

Every protected read or write follows the same path:

```text
Real Identity session
  -> PrincipalContext
  -> Owning module builds RecordFacts from its record
  -> Authorization applies RBAC + ABAC + classification + field policy
  -> AccessProjection: allowed_actions + field_access
  -> Module handler executes the command inside its own transaction
  -> Audit + Transactional Outbox
  -> Idempotent consumers for notification, search, reporting, and workspace
```

React uses `allowed_actions` and `field_access` to improve the experience only.
It does not create a local decision and does not send user roles or record
facts. Lists apply `ScopePredicate` before pagination and before exposing the
title or count.

## Required Authorization Capabilities

The Capability Catalog is pinned before the first R2 route and includes at
least:

- `strategy.analysis.view|manage`.
- `strategy.plan.create|update|submit|publish|retire`.
- `strategy.objective.manage` and `strategy.initiative.manage`.
- `strategy.indicator.define|publish|submit_measurement|approve_measurement`.
- `strategy.review.manage` and `strategy.impact.approve`.
- `portfolio.create|view|manage` and `program.create|view|manage`.
- `project.create|view|update|assign|start|close`.
- `project.baseline.approve` and `project.milestone.approve`.
- `project.budget.view|manage` and `project.impact.submit`.
- `task.view|create|assign|update|submit_completion|accept_completion|cancel`.

Every capability is scoped by organizational scope, duration, classification,
and record relationship. Explicit deny, RoleCapability deny, and higher
classification override allow, and super admin has no implicit commercial
override.

## Execution Packages and Dependencies

### R2-0: Shared Foundation Closure

**Dependency:** W1.3 plan.

- Bind `SessionPrincipalResolver` and `RbacAbacDecideAccess` in the real
  runtime.
- Block production boot when fixture or development principal is bound to a
  user path.
- Complete RoleCapability, ExplicitDeny, delegation, scopes, and field policies.
- Deliver unified `PrincipalContext`, `RecordFacts`, and `AccessProjection`.
- Re-gate R1 with the real engine.

**Closure:** A two-user two-scope journey that proves role granting, its
expiration, deny, delegation, field masking, and matching API, search, report,
and download.

### R2-1: Strengthen Tasks as a Shared Capability

**Dependency:** R2-0.

- Move reads and transitions from SQL inside Controllers to Handlers inside the
  module.
- Apply `DecideAccess` to list, detail, assignment, update, completion, and
  cancellation.
- Pin the `CreateLinkedTask` contract with `SourceReference` and
  `SourceTaskPolicy`.
- Complete participants, comments, mentions, activity log, and completion
  approval when required.
- Opening the source re-delegates authorization to the owning module; owning
  the task does not grant the source.
- `TaskCompleted` does not change the source directly; it is consumed by an
  explicit handler at the owner.

**Closure:** A standalone task and a source-linked task both work with
optimistic locking, Outbox, and Idempotency, and an unauthorized user is
blocked from both the task and the source independently.

### R2-2: Strategy Analysis and Formulation

**Dependency:** R2-0 and R2-1 for the resulting work items.

- Strategy cycles, vision, mission, values, and priorities.
- SWOT, PESTLE, stakeholder analysis, gap analysis, issues, and assumptions.
- Scenarios, alternatives, decision criteria, and chosen direction.
- Evidence via Documents, follow-up procedures via Tasks, and reviews via
  Workflow.
- An immutable published version preserves the relationship between analytical
  outputs and the resulting objectives.

**Closure:** The authorized user can build a documented analysis, compare
alternatives, choose a direction, and produce a plan draft with a record
explaining the source of every objective.

### R2-3: Plan, Scorecard, and Objectives

**Dependency:** R2-2.

- StrategicPlan and its versions, axes, perspectives, objectives, and
  initiatives.
- Configurable perspectives with the Third Health Cluster template, without a
  fixed commercial assumption.
- Cluster, facility, and department scorecards with alignment and rollup
  relationships.
- Perspective and objective weights and a cause-and-effect map.
- Map, tree, list, and board views with historical snapshots.

**Closure:** A published version does not change; modifying it creates a later
version, and the aggregated scorecard is shown without exposing objectives or
fields outside the user's scope.

### R2-4: Indicators, Measurements, and Reviews

**Dependency:** R2-3.

- Indicator and its version, unit, direction, period, formula, and evidence
  policy.
- Baseline, targets, distribution across units, and measurement periods.
- Measurement, check-in, comment, deviation, direction, state, and last update.
- Weighted average, ratio of sums, sum, average, and latest with controlled
  templates.
- Monthly, quarterly, and annual reviews with pre-review snapshots and
  corrective actions as tasks.
- KPI Summary and Boards with period comparison via derived read models.

**Closure:** Calculations run on the server, inconsistent distribution is
rejected, measurement retains its evidence and history, and previous versions
stay reviewable after strategy changes.

### R2-5: Portfolios, Programs, and Projects

**Dependency:** R2-3 for initiative contracts; the project core can be
developed in parallel with R2-4 after the shared contracts are published.

- Portfolio, Program, Project, ProjectTemplate, Phase, and Milestone.
- Convert or link a strategy initiative to a project without moving initiative
  ownership.
- Participants, schedule, dependencies, baseline, and administrative budget.
- Progress computed from approved milestones with evidence, not from task count.
- Green/Amber/Red health with a rule that prevents hiding a critical project
  inside an average green portfolio.
- Project tasks are created via `CreateLinkedTask` and do not grant automatic
  access to the project.
- Milestone gates use Workflow, and the module applies the decision with an
  explicit idempotent command.

**Closure:** Creating a project from a template proves its version, phases,
and milestones, weights sum to 100%, and budget, health, progress, and
isolation work from React to MySQL.

### R2-6: Impact Linkage and the Integrated Journey

**Dependency:** R2-4 and R2-5.

- `ProjectIndicatorLink` keeps Strategy identifiers without FK or cross-join.
- PortfolioProjects owns the baseline, expected impact, and impact assignment
  request.
- Strategy reads the observed improvement and owns the approved actual impact.
- The sum of attributed impact does not exceed the observed improvement without
  justification and an independent path.
- Changing a reading or baseline recomputes and preserves the previous
  snapshot.
- Link deletion is logical and audited and does not delete the project or
  indicator.

**Closure:** A single journey works in Arabic RTL and English LTR:

```text
Analysis -> Direction -> Plan -> Objective -> Indicator -> Initiative ->
Project -> Milestone -> Task -> Completion -> Measurement -> Impact ->
Strategy Review
```

## Integration Between Modules

### Synchronous

- Verifying a published objective, initiative, or indicator reference.
- Authorization decision, ScopePredicate, and FieldAccess.
- Creating a linked task or starting Workflow when the result is part of the
  command invariant.
- Submitting a project impact to Strategy and approving it with an independent
  command.

Contracts return DTOs or IDs only, never ORM models, and never allow reading
another module's table. The use case owner owns the transaction, and the
participating contract joins it and does not commit.

### Asynchronous

- Non-critical Notifications, Search, Reporting, Workspace, and Audit.
- Project progress, health, indicator measurement, and review state changes.
- Every event is written to Outbox with the same change, and every consumer
  records `event_id` to prevent duplicates.

## Integration Controls

- No FK, join, or ORM relation between Strategy, PortfolioProjects, and Tasks.
- No direct read of module tables from public Controllers; operations flow
  through Handlers and module contracts.
- The boundaries test covers `Modules/`, the HTTP layer, and any raw SQL or
  migration.
- No route without a canonical Capability and RecordFacts and a deny-by-default
  test.
- Search and Reporting do not store raw sensitive titles before publish
  decision, and authorization is reapplied at read, export, and download.
- Contracts, OpenAPI, the generated client, and the frontend change in the same
  slice.
- No placeholder, fake endpoint, or fixture that proves a security rule.

## Targeted Verification

- A focused API test for each Command and Query before widening scope.
- Contract tests between Strategy, PortfolioProjects, Tasks, Workflow, and
  Authorization.
- `make verify-boundaries` after each package that changes a relationship
  between modules.
- Tests of calculation, versioning, concurrency, Outbox, and Idempotency.
- An isolation test for two users and two scopes in every slice.
- `npm --prefix apps/web run build` and the targeted frontend test per
  capability.
- E2E for the package at closure, then the full R2 journey in R2-6.
- `./scripts/validate-docs.sh` when contracts or the plan change.

## Outside R2

- Moving projects, tasks, or risks into Strategy.
- A full financial system, invoicing, purchase orders, or project accounting.
- Commercial multi-tenancy, subscriptions, and white-label for multiple
  customers.
- AI approval for plans or indicators; it may draft only later.
- Jira, Salesforce, Tableau, and external measurement source integrations in
  the first release.
- Building Risk before R2 closure; risk, control, treatment, and KRI stay in
  R3.

## Definition of R2 Completion

- The R2-6 journey runs locally from React to MySQL and Redis and back.
- Every protected action passes through the real engine and returns
  allowed_actions and field_access.
- Strategy, PortfolioProjects, and Tasks do not read each other's tables and do
  not copy each other's facts.
- Published versions, historical snapshots, and approved impact are
  re-investigable.
- Targeted tests, boundaries, build, and the E2E journey are green on a single
  revision.
- The revision, verification commands, and the working journey are recorded in
  `active-delivery-status.md`.

## Change Log

| Version | Date | Change |
|---|---|---|
| 3.0.0 | 2026-07-19 | Expand R2 to the full Strategy cycle, add Authorization closure and Tasks hardening as dependencies, separate Strategy and PortfolioProjects ownership, and document the integrated impact journey |
| 2.0.0 | 2026-07-19 | Compress R2 to a one-day slice and remove package owners, approvals, UAT, and administrative risks |
| 1.0.0 | 2026-07-15 | Original multi-wave plan |
