---
doc_id: PLN-R1-W12-FE-001
title: W1.2 Frontend Completion Record
type: plans
status: accepted
version: 2.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: not reviewed unless W1.2 regresses
sources:
- docs/plans/release-1-platform.md
references:
- docs/contracts/api/w1-2.openapi.yaml
- docs/adr/009-unified-react-shell.md
---
# W1.2 Frontend Completion Record

This file is a brief reference record, not a work plan or starting gate. W1.2
is complete on `main`; there are no file ownerships, pending contracts, or
approvals required before W1.3.

## What Works

- A unified shell and typed routes that support direct load, refresh,
  back/forward, and 404.
- Cluster, facilities, units, positions, persons, and assignment management.
- Identity account lifecycle management without mixing Person and UserAccount.
- Signed CSV upload to MinIO, ClamAV scanning, ImportJob, and redacted error
  display.
- A client generated from `w1-2.openapi.yaml` with correlation, idempotency,
  ETag/If-Match, and Problem Details.
- Arabic RTL and English LTR, and loading, empty, forbidden, stale, and error
  states.

## Pinned Boundaries

- React does not grant permission; every request is re-authorized in Laravel.
- Organization owns Person and PII, and Identity consumes `person_id` through
  contracts without FK or join.
- Mocks test display only; isolation, session termination, and import are
  proven by the real API/E2E.
- UTC in contracts and Asia/Riyadh in display.

## Verification

```bash
make verify-w1-2
infra/dev/run-w1-2-e2e.sh
```

Any later failure is treated as a code regression and does not reopen the W1.2
plan.

## Change Log

| Version | Date | Change |
|---|---|---|
| 2.0.0 | 2026-07-19 | Replace the detailed planning contract with a verifiable completion record |
| 1.1.0 | 2026-07-18 | Freeze the frontend contract and execute W1.2 |
