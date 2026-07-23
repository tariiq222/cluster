---
doc_id: ARC-FL-001
title: C4 and Architectural Flows
type: architecture
status: accepted
version: 1.0.0
date: '2026-07-15'
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Operations Lead
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources: []
references: []
---
# C4 and Architectural Flows

The editable source diagrams live in [diagrams](diagrams/system-context.mmd). They describe the accepted decisions per ADR-023: a single VPS, Caddy, and a direct Docker Compose bundle that uses the existing MySQL and Redis on the host. They do not represent Kubernetes or a GitOps controller.

| Level or flow | Diagram | Purpose |
|---|---|---|
| C1, system context | [system-context.mmd](diagrams/system-context.mmd) | users and external system boundaries |
| C2, containers | [containers.mmd](diagrams/containers.mmd) | React, Laravel, workers, and internal stores |
| C3, modules | [modules.mmd](diagrams/modules.mmd) | canonical modules and dependency direction |
| Deployment | [deployment.mmd](diagrams/deployment.mmd) | single host, restricted inbound ports, and off-host backups |
| Authorization | [authorization-sequence.mmd](diagrams/authorization-sequence.mmd) | `RecordFacts` and access decision |
| Work record and route | [workflow-sequence.mmd](diagrams/workflow-sequence.mmd) | `WorkRecord` submission and the owning transaction |
| Document | [document-sequence.mmd](diagrams/document-sequence.mmd) | upload, download, and re-delegation |
| Outbox | [outbox-sequence.mmd](diagrams/outbox-sequence.mmd) | post-commit publishing and safe redelivery |

## Reading the flows

- The solid line is a synchronous contract; the source does not see the supplier's internals.
- The dashed line is an event or derived projection after commit, not a path to change the source truth.
- Every write flow identifies the transaction-owning Handler. The worker or an out-of-process service does not send before commit.
- The `WorkRecord` states shown are a reference pattern configurable by the work type; not every work type follows all of them.

## Document boundary

There are no external integrations in phase one. Any future integration requires specifying the system, the data, the direction, the owner, and the security requirements before adding an Adapter or contract.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | Platform Engineering Office | Align deployment diagrams with Dokploy and Compose on a single host |
