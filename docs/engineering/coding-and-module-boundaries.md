---
doc_id: ARC-EN-003
title: Coding and Module Boundaries
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
- docs/adr/003-module-boundaries.md
references:
- docs/architecture/overview.md
- docs/data-security/logical-data-model.md
---

> **Three of the six claimed CI guards are not implemented.** Only `forbidden imports`, `dependency cycle`, and `cross-owner SQL` are enforced by `apps/api/tests/Architecture/ModuleBoundariesTest.php`. `derived write to business tables`, `contract-without-contract-test`, and `event-without-schema-test` are NOT enforced.

# Coding and Module Boundaries

## Data Ownership

Every fact, table, migration, and event schema has one owner. An external consumer stores only the identifier or a rebuildable derived copy. `Shared` owns no business data or domain rules; it is limited to neutral technical primitives.

## Dependency Direction

```text
Business Modules -> Platform Contracts -> Core Contracts
```

Dependencies form an acyclic DAG. Cross-module imports are allowed only from published `Contracts` and `Events`. Importing another module's `Domain`, `Infrastructure`, ORM model, or migration is prohibited.

## Query Rule

1. A `JOIN` is allowed only between tables owned by the same module.
2. Any `JOIN`, subquery, or foreign key across two business modules is prohibited.
3. Cross-module reads use a narrow synchronous contract, an event and local projection, or a Reporting Read Model owned by `Reporting`.
4. `Search`, `Reporting`, and `Notifications` store derivatives and do not write to business tables.
5. A contract DTO must not be treated as a persistable entity in the consuming module.

## Contracts and Events

- A contract has stable input and output DTOs, declared errors, an owner, and a version.
- An event describes a past fact and carries `event_id`, `occurred_at`, a schema version, and a source reference.
- The Outbox is saved with the business fact in the same transaction. The consumer is idempotent and records `event_id` before applying the effect.
- An incompatible contract or event change requires a new version and a documented compatibility period.

## Architectural Guard Tests

CI currently enforces forbidden imports, dependency direction/cycle constraints, and cross-owner SQL through `apps/api/tests/Architecture/ModuleBoundariesTest.php`. The intended checks for derived writes to business tables, contracts without contract tests, and events without schema/compatibility tests remain documented requirements but are not automated. Permanent exceptions are not allowed; a temporary exception must document its owner, expiry date, and removal ticket.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Established ownership and boundary rules and documented guard-test status |
