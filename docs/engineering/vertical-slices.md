---
doc_id: ARC-EN-002
title: Vertical Slices Within Modules
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
owner: Software Engineering Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: With every change
sources:
- docs/architecture/dependency-rules.md
- docs/adr/002-module-first-vertical-slices.md
references:
- docs/architecture/overview.md
- docs/engineering/coding-and-module-boundaries.md
---

> **PARTIALLY IMPLEMENTED.** The repository currently contains 12 module directories. Seven R2/R3 names are planned boundaries only, and events are feature-scoped where present rather than a root-level directory shared by every module.
# Vertical Slices Within Modules

## Rule

The application is a modular monolith. A module is the highest ownership boundary, and a slice is one use case within it. Do not create general application layers that combine code from different modules.

The common implemented layout is:

```text
Modules/<Module>/
├── Contracts/                       # Published DTOs and interfaces only
├── Domain/                          # Shared module rules and consistency
├── Features/<BusinessVerb>/         # One use-case slice
├── Infrastructure/
│   └── Persistence/Migrations/      # Owner-specific storage changes
└── Tests/                           # Module-level tests
```

Some implemented modules also have root-level `Http/` or `Exceptions/` directories. Events are feature-scoped where present; the current repository does not have a common root-level `Events/` directory in every module.

The 12 implemented module directories are `Authorization`, `Documents`, `Identity`, `Notifications`, `Organization`, `PlatformSettings`, `Reporting`, `Search`, `Tasks`, `WorkDefinitions`, `WorkRecords`, and `Workflow`. Names reserved for later releases are planned boundaries, not implemented module directories.

## Slice Rules

1. Name the slice for a business action and outcome, such as `SubmitWorkRecord` or `ApproveMilestone`, not for a layer such as `RecordService`.
2. A write slice validates input and authorization, enforces its invariant in `Domain`, and saves the business fact and Outbox record in the same transaction.
3. The Handler that starts the write owns the transaction. Synchronous contracts must not open an independent transaction or call `commit`.
4. A read slice returns a stable DTO or View and applies scope and field decisions before output. It must not return an ORM model.
5. Another module consumes a contract, event, or Read Model. It must not import the owning module's slice, Domain, or Infrastructure details.
6. A frontend Feature corresponds to the use case and must not decide sensitive authorization in the browser.

## Completion Standard

A slice is complete when it produces a visible business outcome, includes success and failure tests for authorization and invariants, exposes a compatible API contract or event, satisfies the architectural boundary checks, and updates its migration or projection when needed.

## Prohibited Patterns

- A `CommonHandler`, generic Repository, or generic Workflow without at least two stable consumers.
- A domain decision in a Controller, React component, or Job.
- Using an event as a hidden command; an event describes only a fact that has already occurred.
- Making Search or Reporting the source of truth.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Documented implementation rules for vertical slices |
