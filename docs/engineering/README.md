---
doc_id: ARC-EN-001
title: Executive Engineering Guide
type: engineering
status: draft
version: 1.1.0
date: 2026-07-17
owner: Software Engineering Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: With every change
sources:
- docs/adr/001-modular-monolith.md
- docs/adr/002-module-first-vertical-slices.md
- docs/adr/003-module-boundaries.md
references:
- docs/architecture/overview.md
- docs/governance/document-control.md
---
# Executive Engineering Guide

## Purpose

This documentation set defines the mandatory implementation rules for code, databases, and the delivery pipeline. It applies to every module, feature, and operational change in the platform.

## Documents

| Document | Implementation decision |
|---|---|
| [Delivery Workflow](delivery-workflow.md) | A local single-developer loop followed by a separate final operational run after the product is complete |
| [Vertical Slices](vertical-slices.md) | Start with the module, then deliver a complete, reviewable, deployable use case |
| [Coding and Module Boundaries](coding-and-module-boundaries.md) | One owner per data set, with no cross-module imports or `JOIN`s |
| [Testing Strategy](testing-strategy.md) | Risk-based quality with a documented 80% changed-line coverage target and explicit implementation gaps |
| [CI/CD and Release](ci-cd-and-release.md) | Hosted CI, direct Docker Compose deployment, and a verifiable rollback procedure |
| [Database Migrations](database-migrations.md) | Expand then contract, with both application versions remaining compatible during deployment |
| [Work Definition DSL](definition-dsl.md) | A constrained, sandboxed DSL with no SQL, network, file, or arbitrary-code access |

## Order of Authority

1. `../architecture/overview.md` governs general architectural decisions.
2. This documentation set converts those decisions into implementation controls.
3. Domain specifications define module rules but may not override these controls.
4. If documents conflict, stop the change and issue an ADR or explicit amendment before merging.

## Merge Condition

A change may be merged only after the module owner demonstrates ownership boundaries, the required test layers, contract and migration compatibility, and a successful isolated CI pipeline.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-17 | Software Engineering Lead | Added the unified implementation delivery workflow |
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Created the engineering implementation documentation set |
