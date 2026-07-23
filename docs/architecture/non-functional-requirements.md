---
doc_id: ARC-NF-001
title: Architectural Non-Functional Requirements
type: architecture
status: accepted
version: 1.1.0
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
# Architectural Non-Functional Requirements

These are verification requirements and targets, not a claim that the chosen environment or product already meets them. No item is closed except with documented test or operational evidence.

| Domain | Requirement | Verification |
|---|---|---|
| Capacity | The design supports up to 20,000 accounts and 2,000 concurrent users. | Documented load test on a production-like environment before launch. |
| Availability and recovery | Adopted recovery target `RPO <= 15 minutes` and `RTO <= 2 hours`. | Actual restore of data and files, with measured time and data loss. |
| Consistency | Truth change and Outbox in one MySQL transaction; projections are eventually consistent. | Rollback, redelivery, and idempotency tests. |
| Modification safety | Concurrent records use `lock_version` or an equivalent optimistic mechanism. | Parallel modification conflict test with a human-readable result. |
| Security | Back-end access decision is centralized on capability, scope, classification, state, and fields. | Positive and negative tests for API, search, report, export, and download. |
| Privacy | Patient data and clinical records are out of scope. | Schema, input, and export review. |
| Audit | Sensitive actions and sensitive access are recorded per policy. | Audit effect test with correlation and actor/on-behalf-of when present. |
| Hosting | The product runs on a single VPS via Docker Compose and Caddy, and MySQL and Redis are not exposed publicly. | Host port and Compose scan, and a passive access test. |
| Scalability | Server resources and Web/API/worker replicas can be scaled inside Compose; a single scheduler. | Load test, process count, and prevention of double execution of a scheduled task. |
| Observability | Services emit logs, metrics, and centralized alerts without sensitive payload. | Failure signal test, Outbox delay, and error queues. |
| Performance | Heavy reports, indexing, or notifications do not run inside the user transaction path. | Write path measurement and proof that deferred work processes from the Outbox. |
| Usability | A single React + TypeScript frontend, Arabic by default, with English and `RTL/LTR` support. | Journey test per role, screen size, and UI direction. |
| Maintainability | Monorepo with enforced module boundaries and contract tests. | DAG, import, and table ownership checks in CI. |

## Decisions that require proof before going live

The chosen search, storage, and secrets products are not specified here. The deployment platform is decided in ADR-023 as direct Docker Compose and Caddy on a single VPS; capacity, recovery, and ports remain facts that are only accepted with operational evidence.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Create the non-functional requirements |
| 1.1.0 | 2026-07-16 | Platform Owner | Replace the Kubernetes and air-gap assumption with internal Dokploy hosting |
