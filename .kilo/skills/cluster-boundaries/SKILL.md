---
name: cluster-boundaries
description: Enforce the repository's Laravel modular-monolith ownership and dependency rules. Use for contracts, events, SQL, migrations, modules, or cross-module changes.
---

# Cluster Module Boundaries

Read when needed:

- `docs/architecture/dependency-rules.md`
- `docs/architecture/module-catalog.md`
- `apps/api/tests/Architecture/ModuleBoundariesTest.php`

## Invariants

- A module may use another module only through published `Contracts` or `Events`.
- Synchronous dependencies point to a lower-ranked module.
- No direct query, join, foreign key, ORM relationship, or write against another module's tables.
- References across ownership boundaries are IDs or record references.
- Reporting and Search use governed projections rather than source-table joins.
- Shared code contains technical primitives only, not business policies or shared domain models.
- The calling handler owns the transaction. Synchronous contracts join it and never commit independently.
- Source changes and Outbox events are stored atomically.
- Consumers are idempotent under at-least-once delivery.
- Never create a `Requests` module or a `Request*` business aggregate. A request is a `WorkRecord` classification.

Run `make verify-boundaries` when contracts, imports, SQL, migrations, table ownership, or module relationships change.
