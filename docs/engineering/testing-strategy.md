---
doc_id: ARC-EN-004
title: Testing Strategy
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
owner: Software Engineering Lead
reviewers:
- Information Security Lead
- Operations Lead
classification: internal
review_cycle: With every change
sources:
- docs/adr/002-module-first-vertical-slices.md
- docs/adr/003-module-boundaries.md
references:
- docs/architecture/overview.md
- docs/engineering/coding-and-module-boundaries.md
---

> **NOT IMPLEMENTED.** The following gates are documented but not enforced in CI: 80% mutation score on Domain files, 2,000 concurrent-user load tests, RPO≤15 min / RTO≤2 h restore drill automation. Infection, k6/locust, and restore scripts are not present in the repository.
# Testing Strategy

## Coverage Gate

The intended merge-request target is at least **80% line coverage for changed lines**. It is measured against the request diff after generated files, schemas, and pure DDL migrations are excluded with reviewer approval. Numeric coverage does not compensate for a missing scenario test.

> **NOT IMPLEMENTED.** CI has no changed-line coverage gate for the API or web application. The web configuration applies a threshold to `src/api.ts`, not to each merge-request diff.

## Test Layers

| Layer | Requirement |
|---|---|
| Unit/Domain | Invariants, Value Objects, transitions, and edge cases |
| Application | Handler, transaction, authorization, Outbox, and idempotency |
| Contract | Every synchronous contract and schema/compatibility coverage for every published event |
| Architecture | DAG, imports, table ownership, and prevention of cross-boundary SQL/writes |
| API/UI E2E | A critical journey from the UI through persistence, queueing, and projection, with a stable API contract |

> **NOT IMPLEMENTED.** There is no central runtime contract-test layer under `apps/api/tests/Contract/`. Repository scripts validate selected OpenAPI artifacts but do not provide the complete synchronous-contract and event-compatibility layer described above.

## Mandatory Tests by Risk

1. **Mutation:** Run mutation testing on Domain code and critical logic. A change must not merge if a surviving mutant removes an authorization check, invariant, state transition, or classification constraint. The intended minimum is an 80% mutation score for changed critical files. **Not enforced:** no Infection dependency or CI job is present.
2. **Security:** Test deny-by-default behavior, organizational scope, classification, fields, authorization, IDOR, malicious input, and authentication/session handling. Include E2E tests for read, export, and download paths.
3. **Performance:** Run a load test for every critical slice or new query and preserve the baseline. The intended release gate checks 2,000 concurrent users for selected journeys and compliance with the approved SLO. **Not enforced:** no k6, Locust, or equivalent harness is present.
4. **Recovery:** Periodically restore a real database and file backup in an isolated environment and verify data integrity and application operation. The intended target is `RPO <= 15 minutes` and `RTO <= 2 hours`. **Not enforced:** automated backup/restore drill scripts and a CI gate are absent.

## Implementation

- Tests are deterministic and do not depend on system time, the network, or shared data. They use a Clock and isolated fixtures.
- A test lives near its slice. Contract and architectural guard tests remain in a central path that CI can run.
- CI fails on a flaky test. Quarantine it only temporarily with a ticket, owner, and repair date; a successful rerun is not a permanent pass.
- The module owner reviews available coverage and E2E results before accepting a merge request. Mutation, concurrent-load, and restore reports cannot be required until their missing tooling is implemented.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Documented intended testing, coverage, and recovery gates and identified unenforced gates |
