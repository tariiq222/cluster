---
doc_id: PLN-RC-001
title: Automated Readiness Checklist for Final Operations
type: plans
status: accepted
version: 4.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: on demand
sources:
- docs/product/releases-and-roadmap.md
- docs/architecture/overview.md
- docs/adr/023-single-host-dokploy-deployment.md
- docs/operations/ha-dr-backup.md
- docs/plans/active-delivery-status.md
references: []
---
# Automated Readiness Checklist for Final Operations

This checklist is a single automated technical gate after the five-day program
completes. No manual acceptance, UAT, training, or signatures. Technical
implementation records command outputs in `docs/plans/active-delivery-status.md`;
failure returns work to execution until it passes.

## Automated Checks

| # | Command | Guard |
|---|---|---|
| C-01 | `make verify-boundaries` | No joins or direct queries between module tables |
| C-02 | `make verify-w1-1` | W1.1 journey, isolation, Outbox, relay, and DLQ green |
| C-03 | `make verify-w1-2` | W1.2 slices and frontend tests green |
| C-04 | `make test-api` | All API tests, including R1, R2, and R3, green |
| C-05 | `make test-web` | Generated client, frontend, its tests, and coverage green |
| C-06 | `make verify-w1-1-local` | Production images, Compose bundle, and local operations journey green |
| C-07 | `npm --prefix apps/web run build` | Frontend build from lockfile without external resources |
| C-08 | `./scripts/validate-docs.sh` | Doc contracts and links correct |

## Automated Deployment and Restore

| # | Command/Check | Guard |
|---|---|---|
| O-01 | CI check on the release commit | All jobs green and image pinned by digest |
| O-02 | `make deploy-vps` then an automated smoke test | Direct deployment works on the target server |
| O-03 | Runnable rollback script then a smoke test | Rollback is repeatable and does not lose data; if the script is missing, building it is open technical work |
| O-04 | Backup script with checksum | An off-server MySQL backup is readable; if the script is missing, building it is open technical work |
| O-05 | Restore script to an isolated target then the critical journey | RPO/RTO within declared values; operations are not declared before the command exists and succeeds |
| O-06 | Restart Compose and check healthchecks | Worker/queue recover and do not lose transactions |

## Automated Security and Isolation

| # | Check | Guard |
|---|---|---|
| S-01 | RBAC + ABAC tests on API, search, reports, export, and download | A single authorization decision in the backend |
| S-02 | Two-facility isolation test and sensitive record reads | Zero leaks and every access logged |
| S-03 | Secrets, dependencies, and uploaded file scan | No secrets in Git, and files scanned before availability |
| S-04 | PII column encryption, account lock, and session termination checks | Identity and sensitive data controls active |
| S-05 | External connection and Compose port block check | Operations surface limited to the internal server |

## Change Log

| Version | Date | Change |
|---|---|---|
| 4.0.0 | 2026-07-19 | Convert the checklist to automated commands and checks only, and remove UAT, training, acceptance, and human signatures |
| 3.0.0 | 2026-07-17 | Convert the checklist to a single gate after R1–R3 completion |
| 2.0.0 | 2026-07-17 | Simplify the checklist to a single server and a single developer |
| 1.0.0 | 2026-07-15 | Create the original checklist |
