---
doc_id: ENG-DLV-001
title: Rapid Build Workflow
type: engineering
status: accepted
version: 4.0.0
date: 2026-07-19
owner: Technical Delivery
reviewers: []
classification: internal
review_cycle: When build or test tooling changes
sources:
- docs/adr/002-module-first-vertical-slices.md
- docs/adr/003-module-boundaries.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/007-transactional-outbox.md
references:
- docs/engineering/testing-strategy.md
- docs/plans/active-delivery-status.md
---

> **NOT IMPLEMENTED.** There is no dedicated day-five R1/R2/R3 verification target, and the deployment chain does not provide named health-check, rollback, backup, or restore targets. Use only the repository commands listed below; do not treat the complete end-of-product operational sequence as automated.

# Rapid Build Workflow

## The Single Loop

1. **Test:** Write the narrowest test that describes the next behavior.
2. **Build:** Implement a complete vertical slice, from the contract through the API and UI when needed.
3. **Integrate:** When the test and affected checks pass, integrate the package and move immediately to the next one.

A failure sends the work back to the code. There is no approval stage, mandatory human review, or delivery report. Update the status log once at the end of the delivery day with the command, revision, and result.

## Selecting Verification

- API: Run the narrowest module-scoped `php artisan test`, then `make verify-boundaries` when a contract or boundary changes.
- Web: Run the targeted test, then `npm --prefix apps/web run build`.
- Documentation only: Run `./scripts/validate-docs.sh`.
- End of day: Run the E2E journey for that day's deliverable.
- Day five: No aggregate R1/R2/R3 target exists. Run the relevant existing targets individually, such as `make verify-day3`, `make verify-screens`, and `make verify-w1-1`.

Do not run a broad suite after a text-only edit, and do not rely on a mock-only test to prove isolation, transaction integrity, or access authorization.

## Parallel Work

- Split packages by module or Feature directory.
- Assign one writer per day to public contracts, index files, and lockfiles.
- Integrate shared files after independent packages, not while those packages are in progress.
- Do not block a package on another package when it can build against an explicit contract or fake. Replace the fake with the real integration before closing the work.

## Controls That Must Not Be Shortened

- Module boundaries and data ownership.
- Backend authorization and deny-by-default isolation.
- The business transaction, Outbox, and idempotent consumption.
- Upgrade-compatible migrations and safe application rollback without data deletion.
- No secrets in the repository and no raw PII in events or logs.

## Operations

After the system is complete, `make deploy-vps` provides the repository's automated VPS deployment entry point. Final CI, health verification, rollback, backup, and restore remain separate operational activities; health-check, rollback, backup, and restore are not exposed as named Makefile targets in the deployment chain. Follow `docs/plans/readiness-checklist.md` and record the command and observed result for each manual step.

## Change Log

| Version | Date | Change |
|---|---|---|
| 4.0.0 | 2026-07-19 | Reduced the workflow to test, build, and integrate; removed approvals and human delivery reports |
| 3.0.0 | 2026-07-17 | Separated local development from final server operations |
