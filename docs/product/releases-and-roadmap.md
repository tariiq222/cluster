---
doc_id: PRD-RR-001
title: Releases and Roadmap
type: product
status: accepted
version: 3.0.0
date: 2026-07-19
owner: Technical Delivery
reviewers: []
classification: internal
review_cycle: As needed
sources:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/plans/implementation-roadmap.md
- docs/plans/active-delivery-status.md
references:
- docs/plans/readiness-checklist.md
- docs/plans/w1-1-remaining-delivery-tasks.md
---

> **R2/R3 entries are aspirational; only R1 is implemented.** The 5-day program and Day-4/Day-5 entries are forward-looking, not yet built.

# Releases and Roadmap

This document describes a compressed five-day technical program that begins after W1.1 and W1.2 are complete. There are no human gates between waves; progression depends on green automated tests, module isolation, and security, backup, and recovery controls.

## Deliverables

| Release | Scope | Automated outcome |
|---|---|---|
| R1 | Core, identity and authorization, WorkRecords, work types and workflows, tasks, documents, search, notifications, auditing, and the unified interface | Reference request journey, two-facility isolation, and backend authorization decisions |
| R2 | Strategy, indicators, portfolios, programs, projects, and improvement templates | Link a project to an indicator, validate allocation and impact, and rebuild dashboards |
| R3 | Risk register, matrix and controls, treatment plans, KRI, alerts, and links to R1/R2 | Assess a risk, calculate residual risk, and issue an automated alert within the deadline |

## Five-Day Program

| Day | Work | Automated completion evidence |
|---|---|---|
| 1 | Merge W1.3 and complete Authorization and supervisory relationships | Targeted Authorization tests, `make verify-boundaries`, and the Web build |
| 2 | WorkDefinitions, Workflow, WorkRecords, and Tasks | `make test-api` and the request and task journey in the browser |
| 3 | Documents, Notifications, Search, Reporting, and completion of R1 | `make test-api`, `make test-web`, and the integrated R1 journey |
| 4 | Implement R2: Strategy, Indicators, PortfolioProjects, and impact linkage | R2 calculation and boundary tests and the integrated R2 journey |
| 5 | Implement R3, then integrate the R1-R3 journeys | `make test-api`, `make test-web`, `make verify-boundaries`, and a documentation check |

## Execution and Exit Rules

- Module contracts, IDs, events, and read models remain the collaboration boundaries; there are no direct joins between business tables.
- The same RBAC + ABAC applies to the API, search, reports, exports, and downloads. A business change and its Outbox event are stored in one transaction, with an idempotent consumer.
- There is no server deployment or real data during the five days. Final operations are handled separately after Day 5 through the [automated readiness checklist](../plans/readiness-checklist.md).
- Each day's completion is determined by existing commands and rerunnable tests. Failure returns the work to implementation; there are no reviewers, committees, signatures, UAT, training, or manual acceptance.
- Backups, recovery testing, health checks, secret scans, isolation, and digest-pinned production images remain mandatory controls.

## After the Five Days

Only after the Day 5 gate passes are the operational activities deferred from W1.1 performed: final CI, VPS deployment and rollback, and backup and recovery. Any features outside R1, R2, or R3 (external integrations, OCR, electronic signatures, and semantic search) remain on a later candidate list and do not change the five-day program.

## Change Log

| Version | Date | Change |
|---|---|---|
| 3.0.0 | 2026-07-19 | Consolidated the roadmap into a five-day program beginning with W1.3, and removed human governance and manual acceptance gates |
| 2.0.0 | 2026-07-17 | Separated local development gates from final operations |
| 1.0.0 | 2026-07-15 | Created the three-release roadmap |
