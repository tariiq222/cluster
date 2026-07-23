---
doc_id: ARC-DR-001
title: Architecture Dependency Rules
type: architecture
status: accepted
version: 1.0.0
date: '2026-07-15'
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources: []
references: []
---
# Architecture Dependency Rules

## Core rule

The platform is a `Laravel Modular Monolith` in a `monorepo` that hosts the `React + TypeScript` frontend and the Laravel application. The shared repository does not allow dependencies between business modules: each module has its own code, data, published contracts, and tests boundary.

The arrow `A -> B` means that `A` consumes a published contract from `B`. The arrow does not allow reading `B`'s tables or importing its internal structure.

## DAG rank order

| Rank | Modules |
|---:|---|
| 0 | `PlatformSettings`, `Organization` |
| 1 | `Identity` |
| 2 | `Authorization` |
| 3 | `Audit` |
| 4 | `Workflow`, `RecordsGovernance` |
| 5 | `WorkDefinitions`, `Documents` |
| 6 | `Collaboration` |
| 7 | `Tasks` |
| 8 | `WorkRecords`, `Strategy` |
| 9 | `PortfolioProjects` |
| 10 | `Risk` |
| 11 | `Notifications`, `Search`, `Reporting`, `Workspace` |

Synchronous dependency points only to a lower rank. Same-rank modules do not depend on each other. The full direct dependency list is in [Context Map](context-map.md).

## Allowed and forbidden

| Case | Rule |
|---|---|
| Synchronous call | passes through the owning module's `Contracts` and an immutable DTO, not an ORM model or Query Builder |
| Asynchronous exchange | past-tense event from the Transactional Outbox, with schema version and event id; consumer is idempotent |
| Cross-boundary reference | stable identifier or `record_ref`, with verification via a contract when needed. No cross-module Foreign Key or Join when module independence forbids it |
| Bulk read | `Reporting` Read Model or a controlled Projection Feed, not a direct query across source tables |
| Source write | Command to the owning module. `Search`, `Reporting`, `Workspace`, and `Notifications` do not write to the business source |
| Technical sharing | `Shared` for the Clock, identifiers, transaction primitives, and Outbox only. No domain policies, DTOs, or shared business models |

## Transaction ownership and concurrency

- The Handler that initiates a write use case is the transaction owner: it opens the transaction and decides `commit` or `rollback`.
- The synchronous contract joins the caller's transaction; it does not start a new one and does not issue `commit`.
- The source writes the truth change and the Outbox event in the same MySQL transaction.
- The Outbox worker does not run inside the source transaction. Delivery is `at-least-once`; every consumer keeps an Inbox or `event_id` log to prevent duplicate effects.
- A binding domain rule does not depend on a deferred event. An explicit Command inside the use-case transaction coordinates it.

## Boundary-specific rules

- `Authorization` does not depend on any business module and does not read its tables; the owning module builds trustworthy `RecordFacts`.
- The generic internal request is a `WorkRecord` of a published work type whose code is `request`; the code is a work type, not a data classification. There is no module, Aggregate, table, or event named `Requests` or `Request*`.
- The indicator definition, measurement, target, and approved impact belong to `Strategy`. There is no module named `Indicators`; `PortfolioProjects` keeps the reference and planning data, and `Risk` keeps the KRI link, threshold, and alert only, with no copy of the indicator definition or its measurement.
- `Workflow` owns route execution, not the meaning of completing a business record. `WorkRecords` owns the business record state transition.

## Enforcement

- An architecture test prevents imports into another module's `Infrastructure` and asserts the DAG ordering.
- CI checks for table ownership, queries, and the absence of cross-boundary joins.
- Contract tests for synchronous contracts and schema-compatibility tests for events.
- Outbox, idempotency, and retry tests for derived consumers.
