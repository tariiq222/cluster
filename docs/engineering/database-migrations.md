---
doc_id: ARC-EN-006
title: Database Migrations
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
owner: Software Engineering Lead
reviewers:
- Operations Lead
- Information Security Lead
classification: internal
review_cycle: With every change
sources:
- docs/adr/003-module-boundaries.md
- docs/architecture/overview.md
references:
- docs/data-security/logical-data-model.md
---

> **NOT IMPLEMENTED.** CI does not run the MySQL integration suite in `apps/api/phpunit.mysql.xml`, test a representative previous-version upgrade, or automate idempotent migration reruns. The deployment chain also has no automated pre-migration backup or restore drill.
# Database Migrations

## Ownership

Every migration, table, index, and constraint belongs to one module. A module migration must not create a foreign key, `JOIN`, or DDL modification against a table owned by another module. A derived-read owner owns and can rebuild its own table; it does not modify the business fact.

## Expand-Contract Pattern

1. **Expand:** Add a table, column, index, or optional contract compatible with both application versions.
2. **Migrate:** Deploy the application that can read both shapes and dual-write when needed, then migrate data in observable, resumable batches.
3. **Verify:** Reconcile counts, values, constraints, and performance, and record the migration result.
4. **Contract:** After all consumers of the previous version are gone and the compatibility period has ended, remove the old path in a separate later migration.

A single release must not drop an in-use column, rename without an alias, make an incompatible type change, add `NOT NULL` without a backfill, or run a migration that blocks a large table without an approved online-migration plan.

## Implementation Controls

- A migration is deterministic, numbered, traceable, and executed once under an appropriate lock.
- The repository defines a MySQL integration suite in `apps/api/phpunit.mysql.xml`, but no Makefile target or CI job runs it. Empty-database migration, representative previous-version upgrade, and idempotent-rerun verification are therefore not complete CI gates.
- Separate backfills from DDL. Specify batch size and rate, a checkpoint, and stop and resume plans.
- Take a valid backup before high-impact DDL and test restoration in a separate environment before the production window. These are manual requirements because the deployment chain provides no backup or restore automation.
- The Operations Lead reviews the execution plan, lock duration, and health measurement for every high-impact migration.

## Rollback

Application rollback redeploys only a compatible binary or image. It does not run a destructive down migration in production. Correct data or structure with a forward migration, or restore a backup according to RPO/RTO after a catastrophic failure. No dedicated rollback target is present in the Makefile.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Adopted expand-then-contract migrations and documented automation gaps |
