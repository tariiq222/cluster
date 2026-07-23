---
doc_id: ARC-OV-001
title: Architecture Overview for the Third Health Cluster Platform
type: architecture
status: accepted
version: 1.2.0
date: '2026-07-16'
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Information Security Lead
- Operations Lead
classification: internal
review_cycle: semi-annual
sources: []
references: []
---
# Architecture Overview

## 1. Authority of this bundle

This document is the entry point to the Arabic architectural source of truth for the platform. The binding bundle consists of this document and the five linked documents:

- [Context Map](context-map.md)
- [Module Catalog](module-catalog.md)
- [Dependency Rules](dependency-rules.md)
- [C4 and Flows](c4-and-flows.md)
- [Non-Functional Requirements](non-functional-requirements.md)

On conflict, the explicit decisions in this bundle take precedence, followed by the latest accepted ADR. Deleted files or local copies outside `docs/` are not parallel references for this bundle.

## 2. Purpose and scope

The platform is a unified enterprise administrative system for the Third Health Cluster and its directorates and affiliated facilities. It converts requests, approvals, tasks, documents, strategy, projects, and risks from email, Excel files, and paper into governed, searchable, measurable, auditable digital records.

The platform:

- serves the cluster as a full operational entity, not only as a supervisory one.
- supports local operation within each facility with centralized governance.
- runs on a single VPS via direct Docker Compose and Caddy, with public access restricted to HTTPS.
- delivers a single web application in Arabic by default with English and `RTL/LTR` support.
- starts as a Laravel Modular Monolith with a unified React + TypeScript frontend.

Out of scope:

- medical records, patient data, and clinical systems.
- payroll, leave, and formal promotions managed by "Mawared".
- accounting, procurement, and invoicing as a full financial system.
- external integrations and data migration in phase one.
- a native mobile application or operational reliance on public cloud services.

## 3. Governing constraints

- Expected development team of two to four engineers.
- Strongest experience in Laravel, with less maturity in React and platform operations.
- Five thousand to twenty thousand accounts and up to two thousand concurrent users.
- MySQL inside the Docker Compose bundle on the single production server, with off-server copies.
- Limited-port VPS deployment via Docker Compose; does not claim air-gap or HA on host failure.
- Phased expansion starting with administrative work, then strategy and projects, then risk.
- Strict organizational isolation inside a shared database, not multi-tenant or per-facility databases.

## 4. Binding architectural decisions

1. **Modular Monolith:** a single Laravel application that can scale horizontally, with module boundaries, data ownership, and explicit contracts.
2. **Monorepo:** the single repository holds the Laravel application, the React + TypeScript frontend, infrastructure, and docs; this does not dilute module boundaries or data ownership.
3. **Module-First Vertical Slices:** the module is the upper boundary; use cases are independent internal units for write and read.
4. **Light DDD and CQRS:** a shared domain inside the module, Commands for write, Queries and Read Models for read, with no Event Sourcing.
5. **One owner per fact:** no module reads or writes another module's tables.
6. **DAG without cycles:** every dependency points to a lower-ranked module as defined in [Dependency Rules](dependency-rules.md).
7. **Centralized Authorization:** the decision combines RBAC and ABAC, and receives `RecordFacts` from the owning module without depending on business modules.
8. **Caller-owned transaction:** the caller that initiates a write use case opens the transaction and decides `commit`/`rollback`; every synchronous contract joins it and does not own it.
9. **Outbox with at-least-once delivery:** the event is persisted with the source change in the same transaction, and consumers are idempotent.
10. **Immutable versions:** definitions of work types and published routes are immutable, and the running record pins both versions.
11. **Derived search and reports:** indexes and projections are not a source of truth and do not write to business records.
12. **Single frontend:** unified session, navigation, and design; hiding UI elements improves UX, not as a security boundary.
13. **Constrained self-hosting:** Docker Compose and Caddy run the application; MySQL and Redis are not exposed publicly, and the frontend does not depend on a CDN or public scripts at runtime.

## 5. The only canonical modules

These nineteen names are the canonical names. No new business module or alias may be created without a new architectural decision.

| Group | Modules |
|---|---|
| Enterprise foundation | `PlatformSettings`, `Organization`, `Identity`, `Authorization`, `Audit` |
| Work definition and operation | `Workflow`, `WorkDefinitions`, `WorkRecords`, `RecordsGovernance` |
| Collaboration capabilities | `Documents`, `Collaboration`, `Tasks`, `Workspace`, `Notifications` |
| Enterprise read | `Search`, `Reporting` |
| Specialized domains | `Strategy`, `PortfolioProjects`, `Risk` |

Detailed ownership, contracts, and events live in [Module Catalog](module-catalog.md).

## 6. The request is a WorkRecord

No module named `Requests` exists, and there is no separate Aggregate or table for the generic request. The generic internal request is:

- a published definition owned by `WorkDefinitions`;
- a `WorkRecord` instance owned by `WorkRecords`;
- an optional execution route owned by `Workflow`;
- related tasks owned by `Tasks`;
- a discussion owned by `Collaboration`;
- attached files owned by `Documents`.

A work type uses a classification such as `request` to shape the form, states, and transitions, but the classification does not create a new architectural boundary. Its canonical events are `WorkRecordCreated`, `WorkRecordSubmitted`, `WorkRecordStateChanged`, and `WorkRecordCompleted`.

## 7. Strategy owns the indicators

`Strategy` is the sole owner of everything related to indicators:

- the indicator definition, its version, unit, direction, and aggregation formula.
- periods, baselines, targets, and their distribution.
- measurements, evidence, and their approval states.
- indicator owners and coordinators.
- the approved actual impact attributed to a project.

`PortfolioProjects` keeps only the indicator identifier and project-specific planning data, and uses `Strategy` contracts to register the link or submit impact for approval. It does not copy the indicator definition or its measurements and does not write `Strategy` tables.

## 8. Operational data model

### 8.1 Static data

Specialized modules and the core use clearly defined relational tables; each module owns its migrations and constraints.

### 8.2 Dynamic work data

`WorkRecords` uses a hybrid model:

- a stable relational Envelope holding identity, owner, creator, state, classification, version, and `lock_version`.
- a dynamic payload bound to a published version from `WorkDefinitions`.
- typed projections for selected fields for search and reporting.
- explicit relations instead of a generic EAV or cross-module joins.

### 8.3 Derived facts

`Search`, `Reporting`, `Workspace`, and `Notifications` store projections or derived messages. They can be rebuilt and do not become the source of operational state.

## 9. Access and isolation

Access decisions are centralized in `Authorization` and consist of:

```text
role capability
+ account state
+ organizational scope
+ supervisory relationship
+ ownership and participation
+ classification
+ record state
+ active assignment or delegation
+ field policy
= explainable access decision
```

The owning module reads the minimal non-sensitive slice of the Envelope, builds `RecordFacts`, and then requests the decision. `Authorization` does not call the owning module and does not know its Aggregate or table; therefore no dependency cycle arises. The same decision applies to API, search, reports, export, and download, and explicit denial or higher classification takes precedence over general allow.

## 10. Inter-module communication

### 10.1 Synchronous

Used when an immediate result or a shared invariant is required, such as an access decision, manager resolution, starting a route, or atomic task creation. The contract is published by the owning module, and its inputs and outputs are immutable DTOs, not ORM models.

### 10.2 Asynchronous

Used for notifications, indexing, projections, dashboards, and non-critical audit. The producer persists the event in the Outbox inside the source transaction, then a worker delivers it after `commit` at least once. Each consumer records the `event_id` before applying the effect to prevent duplicates.

### 10.3 Transaction boundaries

- The caller Handler is the transaction owner.
- Synchronously called contracts join the existing transaction, do not start a new one, and do not issue `commit`.
- The transaction does not extend to a worker, search engine, external storage, or network integration.
- A notification or indexing failure does not invalidate the persisted truth.

## 11. Operational topology

- Caddy on a single VPS in front of the React Unified Web App and Laravel Web/API.
- A single Outbox/Notifications worker defined in Docker Compose; no scheduler without scheduled work.
- MySQL and Redis on the host are reached by the application via a private network, and their ports are not exposed publicly.
- Logs, metrics, and alerts fit the single host and do not assume an external cluster.
- Images are built directly from Dockerfiles and lockfiles at deploy time.
- An encrypted backup store lives outside the production host failure domain with separate credentials.

Binding recovery targets: `RPO ≤ 15 minutes` and `RTO ≤ 2 hours`, with periodic actual restore drills.

## 12. Phases

| Phase | Outcome |
|---|---|
| One | Enterprise foundation, work definition, `WorkRecords` for general requests, routes, tasks, collaboration, documents, workspace, notifications, operational search and reports |
| Two | `Strategy` including indicators, then `PortfolioProjects` and linking project impact to indicators |
| Three | `Risk` and its links to strategy, projects, tasks, and documents |
| Later | candidate areas are not binding; none of them counts as a committed module until a boundary, ownership, and contract specification and an explicit decision are in place |

## 13. Quality gates

- Architecture tests for DAG direction and to prevent cross-module imports or SQL.
- Contract tests for synchronous contracts.
- Event schema and compatibility tests.
- Outbox, idempotency, and retry tests.
- Validity tests for scope, classification, fields, search, report, and export.
- Concurrency tests using optimistic locking.
- An end-to-end journey from the frontend to the database, queue, and projections.
- Load tests up to two thousand concurrent users, container outage and recovery drills, and restore drills for data and files to a separate target.

## 14. Deferred decisions that do not change the boundaries

To be resolved before the relevant operational gate: the external backup store location, server capacity and tuning of MySQL and OpenSearch and logs, detailed retention durations, and the official security classification. The deployment platform is decided in ADR-023 as direct Docker Compose and Caddy; these decisions do not alter data ownership, the DAG, or module contracts.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-15 | Platform Engineering Office | Stabilize the architecture overview |
| 1.2.0 | 2026-07-16 | Platform Owner | Adopt Dokploy and Docker Compose on a single internal host |
