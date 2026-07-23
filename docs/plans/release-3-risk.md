---
doc_id: PLN-R3-001
title: R3 Fast Plan — Risks, Controls, and Treatment
type: plans
status: accepted
version: 2.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: during Day 5 of execution
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-2-strategy-portfolio.md
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
references:
- docs/domain/risk.md
- docs/domain/strategy.md
- docs/domain/portfolio-projects.md
---
# R3 Fast Plan

## Goal

Build an integrated risk slice on Day 5: register, assessment, control,
treatment plan, KRI, and R2 links. No workshops, committees, or approved
specifications precede it. The defaults exist as configurable data and
implementation starts immediately.

## Default Settings

The first release uses these values; they can be adjusted later from settings
without schema changes:

- A likelihood × impact matrix from 1 to 5.
- Score = likelihood × impact.
- Levels: Low 1–4, Medium 5–9, High 10–16, Critical 17–25.
- Default alert threshold 10 and critical threshold 17.
- Categories: operational, technical, financial, compliance, strategic, and
  other.
- Risk states: Draft, Active, Treated, Accepted, Transferred, Avoided, and
  Closed.

These are technical defaults and not the final institutional policy; their
presence prevents construction from stalling on a human decision, and they
remain configurable when the actual values are available.

## Parallel Execution Packages

### R3-A Risk Register + Assessment

- RiskRegister and Risk linked to `organization_unit_id` and the follow-up
  owner identifier.
- Risk source references optional IDs for an objective, indicator, or project
  without copying their data.
- RiskAssessment stores inherent and residual scores, a time snapshot, and the
  reason for re-assessment.
- Soft delete only, and search passes through `DecideAccess`.

Acceptance:

- Full CRUD and two-facility isolation.
- The score and level are correct at the 4, 5, 9, 10, 16, and 17 boundaries.
- Re-assessment leaves a snapshot and does not alter history.

### R3-B Controls + Treatment

- Reusable Control with preventive, detective, and corrective types.
- ControlEffectiveness with weak, moderate, or strong value and an expiry date.
- RiskControlLink with internal Risk-module identifiers.
- TreatmentPlan with accept, mitigate, transfer, and avoid types.
- `mitigate` links R1 Tasks by IDs; task completion enables closing the plan
  and then re-assessment.
- `accept`/`transfer`/`avoid` actions are gated by capability, not by signature
  or committee.

Acceptance:

- A weak control or an expired one raises the residual per the published rule.
- A mitigate plan cannot close before its linked tasks are completed.
- An insufficient capability prevents accepting a High or Critical risk.

### R3-C KRI + R2 Links + Dashboard

- RiskIndicatorLink consumes `indicator_id` and a measurement reference from
  Strategy; it does not define an indicator or copy a reading into Risk.
- Threshold evaluation produces an Outbox event and an R1 notification with
  deduplication.
- Objective/project links use ID contracts and read models only.
- The dashboard shows the top risks by scope, level, and KRI direction.

Acceptance:

- An R2 measurement crossing the threshold generates one alert and changes the
  R3 board state.
- No join between Risk and Strategy or PortfolioProjects.
- A facility user cannot see risks or titles of another facility.
- Moving from an objective or project to its linked risks works through the
  published APIs.

## Day 5 Order

| Period | Execution | Output |
|---|---|---|
| Start | R3-A, R3-B, and R3-C migrations, contracts, and tests | Three independent packages |
| Middle | Domain, API, and React for each package | Risk, control, treatment, and KRI work independently |
| End | Seed and the full system journey | R2 indicator → KRI → risk → treatment task → new residual |

## Targeted Verification

- Risk tests for CRUD, matrix, history, controls, and treatment.
- Event/inbox tests for redelivery and dedup.
- Boundary tests for R2/R3, FK and join prevention, and cross-import.
- Web build and RTL/LTR and isolation tests.
- E2E for the R3 journey, then R1, R2, and R3 journeys together.

## Outside R3 Fast

- Meetings and workshops for matrix, appetite, or escalation calibration.
- Acceptance committees, periodic reviews, and launch signatures.
- 100-risk onboarding, user training, or manual tabletop exercises.
- Multi-level organizational escalation; the first release uses capability
  plus notification.
- Large control libraries or per-unit custom dashboards.

## Definition of R3 and System Completion

- The integrated journey from R2 indicator to KRI, risk, treatment, and
  re-assessment runs locally.
- R1/R2/R3 boundaries, isolation, and Outbox are proven automatically.
- R1, R2, and R3 journeys, build, analysis, and docs are green on a single
  revision.
- The final revision is recorded in the active delivery status, and the plan
  transitions to automated operations only.

## Change Log

| Version | Date | Change |
|---|---|---|
| 2.0.0 | 2026-07-19 | Drop W3.0, committees, and UAT and start R3 directly with defaults and a one-day slice |
| 1.0.0 | 2026-07-15 | Original multi-wave plan |
