---
doc_id: DOM-RSK-001
title: Risks, Controls, and Treatment
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Risk module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
- docs/architecture/dependency-rules.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/plans/release-3-risk.md
- docs/architecture/context-map.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

# Risks, Controls, and Treatment

## Purpose and Scope

The `Risk` module owns the risk register, controls, inherent and residual
assessments, treatment plans, KRI links, thresholds, alerts, and escalation.
`Strategy` alone owns every indicator definition and its measurements; `Risk`
keeps `indicator_id` and the threshold configuration and alert state, and does
NOT duplicate the definition or the reading. A risk is also linked by
identifier to a Strategy objective and a PortfolioProjects project. The
likelihood/impact matrix, appetite, limits, and review cadence are versioned
data that is approved before the register is activated.

Out of scope: defining any indicator, entering or approving its measurements,
and owning the project, task, document, or approval engine. The treatment plan
uses `Tasks`, evidence uses `Documents`, acceptance and escalation use
`Workflow`, and KRI thresholds consume the approved measurements from
`Strategy` via contracts and events.

## Entities and Tables

| Table | Entity | Constraints and indexes |
|---|---|---|
| `risk_registers` | A risk register scoped to an Organization unit | unique `(owner_organization_unit_id, code)` |
| `risk_policy_versions` | The likelihood/impact matrix, appetite, limits, and review cadence | published version is immutable; only one effective version per scope and period |
| `risks` | The risk, its owner, category, source, classification, and status | `risk_number` unique; indexes on owner, status, and next-review date |
| `risk_assessments` | Inherent or residual assessment with a snapshot of the policy and the result | unique `(risk_id, assessment_kind, assessment_sequence)`; an approved assessment MUST NOT be edited |
| `risk_controls`, `risk_control_links`, `control_effectiveness_reviews` | The control library, its links, and effectiveness | a control may be linked to multiple risks; an expired effectiveness MUST NOT influence the assessment |
| `risk_treatments`, `risk_treatment_tasks` | Accept/Mitigate/Transfer/Avoid and task plans | exactly one reference task per link; a mitigation plan MUST NOT close before its tasks are resolved |
| `risk_indicator_links`, `risk_indicator_thresholds`, `risk_indicator_alerts` | Links a risk or control to `indicator_id`, plus thresholds and alert state | no local definition or measurement; unique on link and threshold version; index on alert state |
| `risk_links`, `risk_activities` | Strategy/Portfolio links and append-only activity | the link is identifier-only; a reason is required to unlink |

## Commands, Queries, and Events

**Commands:** `PublishRiskPolicyVersion`, `CreateRiskRegister`, `CreateRisk`, `AssessRisk`, `RegisterControl`, `LinkControlToRisk`, `ReviewControlEffectiveness`, `PlanRiskTreatment`, `LinkTreatmentTask`, `AcceptRisk`, `LinkRiskIndicator`, `ConfigureRiskIndicatorThreshold`, `EscalateCriticalRisk`, `LinkRiskReference`, `CloseRisk`.

**Queries:** `GetAuthorizedRisk`, `ListAuthorizedRisks`, `GetRiskSummary`, `GetAuthorizationRecordFacts`, `ListDueReviews`, `GetRiskHeatmap`, `GetControlSummary`, `GetTreatmentStatus`, `ListRiskIndicatorThresholds`, `ListRiskIndicatorAlerts`.

**Events:** `RiskPolicyVersionPublished`, `RiskCreated`, `RiskAssessed`, `ControlRegistered`, `ControlEffectivenessReviewed`, `RiskTreatmentPlanned`, `RiskIndicatorThresholdBreached`, `CriticalRiskEscalated`, `RiskAccepted`, `RiskClosed`.

Every change writes activity and Outbox in one Risk transaction; events MUST
NOT include the risk description, evidence, or any restricted value.

## State Machines

```text
Risk: Draft -> Active -> UnderReview -> Closed
Treatment: Draft -> Active -> Completed | Cancelled
Assessment: Draft -> Submitted -> Approved | Returned
ControlReview: Scheduled -> Submitted -> Approved | Expired
```

## Invariants

- A risk has an organizational scope, an owner, and a next review, and uses a pinned published policy version when assessed.
- The inherent assessment precedes the residual one; the residual assessment MUST NOT rely on an expired or non-effective control without a documented exception.
- Score and risk level are computed server-side from the policy matrix and MUST NOT be accepted from the client.
- Accepting a risk above appetite requires a published acceptance path and a resolved decision owner; no admin override.
- A `mitigate` treatment MUST NOT complete before the required tasks and evidence are resolved, then a fresh assessment is requested.
- A risk links an objective, indicator, or project by identifier only; it MUST NOT copy a name, status, or measurement.
- Risk receives the approved KRI measurement from Strategy via `indicator_id` and `measurement_id`, and evaluates its threshold without storing a copy of the measurement or its evidence.
- A KRI threshold breach or a critical risk emits an escalation event; the in-app notification alone MUST NOT auto-change the risk level.

## Security and Failure

Risk builds `AuthorizationRecordFacts` and submits them without a local
decision, then asks Authorization for a decision on every read or write.
Viewing a heatmap or aggregate value MUST NOT disclose a risk's description
or a facility's assessment without `view_details`. Documents and their links
are constrained by the strictest Authorization rule, and search and reporting
re-run authorization before display or export.

Missing published policy, invalid external reference, unresolvable approver,
or `lock_version` conflict blocks the command. The Super Admin MUST NOT
replace the risk owner or acceptance approver. Outbox failure rolls back the
risk change; notification failure or post-commit drop is retried
idempotently.

## Tests

- Compute the policy matrix, appetite, and inherent/residual assessment from a fixed version.
- An expired or weak control MUST NOT lower the residual risk without an approved review.
- A mitigation plan MUST NOT close before its tasks are resolved, and an above-appetite acceptance fails for an unresolved actor or admin override.
- Facility isolation: one facility MUST NOT see another's data, and an aggregate read or search/report result MUST NOT expose details.
- Strategy and PortfolioProjects contract links use identifiers only; events and escalation are idempotent.
- A boundary test confirms the absence of indicator definitions, measurements, and readings from Risk tables and commands.

## Dependencies

Depends on `Organization`, `Strategy`, `PortfolioProjects`, `Workflow`,
`Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`,
`Audit`, and `PlatformSettings` for operating cadence. Does NOT depend on the
derived modules; `Notifications`, `Search`, `Reporting`, and `Workspace` only
consume its events.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Risk module owner | Create the accepted specification |
