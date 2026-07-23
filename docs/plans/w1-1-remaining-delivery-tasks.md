---
doc_id: PLN-W11-OPS-001
title: Deferred W1.1 Operational Tasks
type: plans
status: accepted
version: 4.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: on demand
sources:
- docs/plans/release-1-platform.md
- docs/adr/023-single-host-dokploy-deployment.md
- docs/plans/readiness-checklist.md
references:
- docs/plans/active-delivery-status.md
---
# Deferred W1.1 Operational Tasks

W1.1 is closed locally with the evidence of `make verify-w1-1` and
`make verify-w1-1-local`. These tasks are not redone and do not block W1.2 or
the five-day program. The following items execute once in the automated
operations phase after R1, R2, and R3 complete.

| # | Deferred Operation | Automated Command/Evidence | Closure Condition |
|---|---|---|---|
| 1 | CI check on the final commit | CI check tied to the release commit | All jobs green |
| 2 | VPS deploy and rollback | `make deploy-vps` then rollback and a smoke test | Deploy and rollback are repeatable |
| 3 | MySQL backup and restore | Backup task, checksum, and restore to an isolated target | The critical journey works after restore and RPO/RTO are met |
| 4 | Operations and security checks | healthchecks, `make verify-boundaries`, and secrets and isolation checks | No open security or architectural failure |

This record has no human owners, reviewers, committees, signatures, UAT,
training, or manual acceptance. Technical implementation records command
outputs only in the status log. Server setup (firewall, network, and ports)
stays outside the repository, while Compose, backup, and restore checks stay
within the technical scope.

## Change Log

| Version | Date | Change |
|---|---|---|
| 4.0.0 | 2026-07-19 | Shorten the record to deferred automated operations and remove human governance |
| 3.0.0 | 2026-07-17 | Close W1.1 locally and move operations to the final phase |
| 2.0.0 | 2026-07-17 | Simplify the package to a single developer and a single server |
| 1.0.0 | 2026-07-17 | Create the W1.1 gaps record |
