---
doc_id: PLN-RM-001
title: Integrated Implementation Roadmap — From R1 Closure to R3
type: plans
status: accepted
version: 5.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: at each execution package closure
sources:
- docs/plans/active-delivery-status.md
- docs/plans/release-1-platform.md
- docs/plans/release-2-strategy-portfolio.md
- docs/plans/release-3-risk.md
references:
- docs/engineering/delivery-workflow.md
- docs/architecture/module-catalog.md
- docs/architecture/dependency-rules.md
---
# Integrated Implementation Roadmap

## Required Outcome

Build the integrated local release starting from W1.3 closure, then the full
Strategy cycle, then PortfolioProjects and impact linkage, then Risk. No status
meetings, no human approvals, no manual UAT within the development path; code,
contracts, and tests advance together in vertical slices.

The artificial two-day compression of R2 and R3 is dropped because the adopted
scope is now a full Strategy cycle with its horizontal approvals. No artificial
duration is placed on top of the definition of completion; on verification
failure, the slice is fixed before moving to the next dependent.

## Baseline

- W1.1 is complete and pinned by `make verify-w1-1` and `make verify-w1-1-local`.
- W1.2 is complete on `main` and pinned locally by `make verify-w1-2` and the
  isolated E2E journey.
- R1 journeys up to W1.10 are functionally complete, but the W1.3 review proved
  the application is still bound to a fixture authorization engine and that role
  management does not fully drive real access.
- W1.3 is reopened as a security integration closure per
  `release-1/w1-3-frontend-slices.md` before merging R2; completed R1 features
  are not rebuilt.
- Deploying to the VPS is not required between slices; it runs automatically once
  after the build is complete.

## Updated Execution Order

W1.3 closure runs first because it changes a horizontal contract consumed by
every module. The order of packages below is mandatory by technical dependencies,
and no gate may be bypassed to an unprotected consumer.

1. Close the real Authorization engine and its contracts, R1 consumers, and
   re-gate R1.
2. Strengthen Tasks as a shared capability for linked sources and apply
   Authorization fully.
3. Implement R2-2 through R2-4: analysis, formulation, plan, scorecard,
   indicators, and reviews.
4. Implement R2-5 and R2-6: portfolios and projects, then impact linkage and the
   integrated journey.
5. Implement R3 on the same decision and contracts, then run integrated
   verification.
6. Transition to automated deployment when server inputs are available.

## Package Map

| Package | Runnable Output | Dependency | Automated Closure |
|---|---|---|---|
| W1.3 | Real session and decision engine and AccessProjection across all R1 | Functional R1 | Security journey + R1 gates |
| R2-1 | Fully authorized Tasks supporting linked sources | W1.3 | API + isolation + Outbox + task E2E |
| R2-2 | Documented analysis, formulation, and strategic direction | R2-1 | Analysis-to-draft-plan journey |
| R2-3 | Plan, scorecard, objectives, initiatives, and versions | R2-2 | Multi-scope scorecard journey |
| R2-4 | Indicators, measurements, reviews, and historical snapshots | R2-3 | Calculations + isolation + review journey |
| R2-5 | Portfolios, programs, projects, milestones, budget, and health | R2-3 and R2-4 contracts | Project-from-template journey |
| R2-6 | Project-to-indicator linkage and impact approval | R2-4 and R2-5 | Full R2 journey |
| R3 | Risks, controls, treatment, and KRI linked to R2 | R2-6 | R3 journey then integrated verification |

The PortfolioProjects core can be developed in parallel with indicators after
the initiative and review contracts are published, but no consumer is merged
before the published contract and its compatibility test.

## What Was Removed from the Path

- Release managers, reviewers, committees, governance boards, and signatures.
- W3.0 as a separate workshop phase; R3 uses configurable defaults and starts
  with code.
- Manual UAT, training, and field trials as a condition for completing
  development.
- Administrative risk reports, progress percentages, and follow-up meetings.
- Full contract freeze before the first line of code; the contract is cemented
  with the slice and tested against drift.
- Waiting for the remote CI between every package; targeted local testing is
  enough for local merging, and the final CI covers the full candidate.

## Technical Controls That Remain

These are not human governance; they are health and security properties inside
the code:

- No FK, join, or ORM relation between business module tables.
- A single deny-by-default access decision for every API, search, report,
  export, and download.
- Business change and Outbox in a single transaction, and the consumer is
  idempotent.
- Optimistic locking and Idempotency-Key for replayable commands.
- PII encryption, secrets blocked from Git, and file scanning before
  availability.
- In-flight records pinned to published definition and path versions.

## Fast Merge Method

1. Each package owns its own module or folder; the shared files and public
   contracts are merged once at the end of the day.
2. The package starts with a single behavior test, then delivers the smallest
   vertical slice that turns it green.
3. The backend does not wait for the frontend and vice versa; they agree on a
   small generatable snapshot.
4. If two packages conflict, the conflict is resolved in the contract or shared
   file immediately; no committee or decision document is created unless a
   permanent architectural boundary changes.
5. `active-delivery-status.md` is updated once at the end of the day, not after
   every commit.

## Definition of Completion

- The day's mentioned journey runs locally from React to MySQL/Redis and back.
- Targeted tests, module boundaries, and build are green.
- No placeholder, fake endpoint, or mock that proves a security rule.
- The last day passes the integrated verification suite and records the
  revision and result.

## After R3 Completion

If operational inputs are ready, the automated deployment phase runs per
`readiness-checklist.md`. Advanced features not required for the core journeys,
such as heavy voting patterns or dozens of templates and boards, are added
after the integrated system exists and do not block the first release.

## Change Log

| Version | Date | Change |
|---|---|---|
| 5.0.0 | 2026-07-19 | Replace the five-day compression with a dependency roadmap for Authorization closure, Tasks hardening, full Strategy cycle, then PortfolioProjects, impact, and R3 |
| 4.1.0 | 2026-07-19 | Insert the Authorization closure gate before R2 after discovering that the green journey uses a fixture engine and does not prove real role impact |
| 4.0.0 | 2026-07-19 | Replace week-based waves with a five-day program starting from W1.3 and dropping human governance |
| 3.0.0 | 2026-07-17 | Separate local development from final operations |
