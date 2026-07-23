---
doc_id: DOM-PPM-001
title: Portfolios, Programs, and Projects
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: PortfolioProjects module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
- docs/architecture/dependency-rules.md
- docs/adr/006-workflow-versioning.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
- docs/plans/release-2-strategy-portfolio.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

# Portfolios, Programs, and Projects

## Purpose

`PortfolioProjects` owns portfolios, programs, projects and their templates,
phases, milestones, baselines, health, and administrative budget. The only
hierarchy is `Portfolio → Program → Project`. The module does NOT own
initiatives or indicator definitions or measurements; those facts belong
exclusively to `Strategy`.

## Scope

- A regular or improvement project, with one owning unit and participating
  units in defined roles.
- A project template with a fixed version, plus phases, milestones, and
  `Workflow` gates.
- A baseline for weights, duration, and budget; progress computed from
  approved evidence-backed milestones, NOT from task counts.
- An administrative-budget snapshot (`planned`, `actual`, `forecast`,
  `variance`) — no billing or purchase orders.
- Plan-time links to Strategy indicators and initiatives, with actual impact
  delivered to Strategy for approval.

Out of scope: a standalone approval engine; subtasks; indicator definition or
measurement; copying risk data or live ledger entries.

## Entities and Tables

| Table | Entity and owned facts | Key constraints and indexes |
|---|---|---|
| `portfolios` | Portfolio, owner, status, classification | `code` unique; index `(owner_organization_unit_id, status)` |
| `programs` | Program under a portfolio | unique `(portfolio_id, code)`; no Program without a Portfolio |
| `project_templates`, `project_template_versions` | Template and its published version | unique `(template_id, version_number)`; published version is immutable |
| `projects` | Project, program, owning unit, pinned template, status, `lock_version` | unique `project_number`; indexes on owner, status, and program |
| `project_participants` | Participants, project role, and duration | unique on active participant and role; the role does not grant access beyond the project |
| `project_phases`, `project_milestones` | Project phases/milestones, weights, and gates | unique phase/milestone key within the project; weights positive |
| `project_baselines` | Approved baseline for duration, weights, and budget | only one active baseline per project; immutable after approval |
| `project_budget_snapshots`, `project_health_snapshots` | Computed budget/health snapshots and administrative override | index `(project_id, captured_at)`; an override carries a rationale and duration |
| `project_indicator_links` | `indicator_id`, baseline, expected impact, and scope | no indicator definition or measurement value; unique on link, scope, and period |
| `project_activities` | Append-only user-meaningful activity | index `(project_id, occurred_at)` |

References to `Organization`, `Strategy`, `Tasks`, `Documents`, and `Workflow`
are identifiers and contracts only — no foreign keys or joins across business
modules.

## Commands

- `CreatePortfolio`, `CreateProgram`, `CreateProject`
- `CreateProjectTemplateDraft`, `PublishProjectTemplateVersion`
- `AddProjectParticipant`, `SetProjectBaseline`, `StartProject`, `PutProjectOnHold`, `CloseProject`, `CancelProject`
- `CreateProjectPhase`, `CreateProjectMilestone`, `SubmitMilestoneForApproval`, `ApplyMilestoneDecision`
- `RecordBudgetSnapshot`, `RecalculateProjectHealth`, `SetTemporaryHealthOverride`
- `RegisterProjectIndicatorLink`, `SubmitProjectImpactToStrategy`

Every command builds `AuthorizationRecordFacts` for the project from the
owner's aggregate and requests `DecideAccess` before writing; activity and
Outbox are recorded inside the module's own transaction.

## Queries

- `GetAuthorizedProject`, `ListAuthorizedProjects`, `GetPortfolioSummary`, `GetProgramSummary`
- `GetProjectProgress`, `GetProjectHealth`, `GetProjectBudget`, `ListProjectMilestones`
- `GetProjectIndicatorLinks`, `GetAuthorizationRecordFacts`, `GetProjectActivity`

Lists return a `ScopePredicate` from Authorization and apply it before
returning title or summary. Opening an indicator, document, or task re-runs
authorization against its owner.

## Events

- `PortfolioCreated`, `ProgramCreated`, `ProjectCreated`, `ProjectTemplateVersionPublished`
- `ProjectBaselineApproved`, `MilestoneSubmitted`, `MilestoneApproved`
- `ProjectProgressChanged`, `ProjectHealthChanged`, `ProjectBudgetSnapshotRecorded`
- `ProjectImpactSubmitted`, `ProjectClosed`, `ProjectCancelled`

Past events carry identifiers and a safe summary only, and are delivered via
the Transactional Outbox. `Notifications`, `Search`, `Reporting`, and
`Workspace` are idempotent consumers and MUST NOT alter project truth.

## State Machines

```text
Project: Draft -> PendingApproval -> Active -> OnHold -> Closed
                                     |              -> Cancelled
Milestone: Draft -> PendingApproval -> Approved | Returned | Rejected
TemplateVersion: Draft -> Tested -> Approved -> Signed -> Published -> Retired
```

A `WorkflowCompleted` decision MUST NOT silently mutate the project; an
explicit coordinator issues `ApplyMilestoneDecision`, or the transition runs
after idempotent verification.

## Invariants

- Each Program belongs to one Portfolio and each Project belongs to one Program; an Initiative MUST NOT enter this hierarchy.
- A project has one owning unit; participation MUST NOT transfer ownership or expose un-authorized fields.
- Template version and baseline are pinned; change after approval requires a new baseline with a rationale and an audit.
- The sum of approved milestone weights for a baseline equals `100%`.
- Completion equals the sum of weights of approved milestones with available evidence; tasks are an auxiliary signal only.
- A portfolio average MUST NOT mask a single critical red project; the guardian rule elevates overall status per the published policy.
- Expected impact is plan-time local; actual impact is approved only inside Strategy, and the assigned aggregate impact MUST NOT exceed the observed improvement without a documented justification.
- No hard delete from the interface; `RecordsGovernance` holds or freezes archiving or destruction that would conflict.

## Security and Failure

- The module builds `AuthorizationRecordFacts` from the project and does NOT issue Allow/Deny or field decisions; Authorization issues the decision alone.
- A project manager MUST NOT approve a gate on which they do not appear in the snapshot or hold a valid delegation; no Super Admin approval override exists.
- Documents are subject to the strictest document constraint and its links; tasks MUST NOT grant automatic access to the project.
- Unpublished templates/indicators/workflows, invalid references, or stale `lock_version`: command rejected without partial write.
- Workflow start failure or Outbox failure rolls back the change transaction; notification or reporting failure after commit is retried and MUST NOT reflect a different truth.

## Tests

- Unit: prevent Program without Portfolio and Project without Program; prevent Initiative inside the hierarchy.
- Unit: weights not summing to `100%` or completion without an approved milestone and evidence are rejected.
- Integration: only the approved milestone snapshot changes progress; a repeated workflow decision MUST NOT duplicate impact.
- Security: a project participant or `view_aggregate` user MUST NOT see restricted fields or evidence.
- Boundary: no joins or writes into `Strategy`, `Tasks`, or `Documents`; project impact flows through contracts.
- Failure: missing approver is NOT replaced by an admin; Outbox failure or concurrent conflict MUST NOT produce partial success.

## Dependencies

Depends on `Organization`, `Strategy`, `Workflow`, `Tasks`, `Collaboration`,
`Documents`, `RecordsGovernance`, `Authorization`, and `Audit`. Consumes the
Business Calendar from `PlatformSettings` for working dates and milestones.
Provides `AuthorizationRecordFacts` for access paths, summaries, and events
to other consumers; does NOT depend on `Notifications`, `Search`,
`Reporting`, or `Workspace`.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | PortfolioProjects module owner | Create the accepted specification |
