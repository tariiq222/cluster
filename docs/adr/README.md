---
doc_id: ADR-README
title: Architecture Decision Records Index
type: adr
status: accepted
version: 1.3.0
date: 2026-07-21
owner: Platform Architecture Council
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: semiannual
sources: []
references: []
deciders:
- Platform Architecture Council
scope: docs/adr
supersedes: []
superseded_by: []
related_adrs: []
review_by: 2027-01-15
---
# Architecture Decision Records

This is the official register of architecture decisions. Valid statuses are `proposed`, `accepted`, `superseded`, and `rejected`. Do not change an accepted decision's meaning; create a subsequent decision that supersedes it.

| ADR | Title | Status |
|---|---|---|
| [001](001-modular-monolith.md) | Modular Monolith | accepted |
| [002](002-module-first-vertical-slices.md) | Module-First Vertical Slices | accepted |
| [003](003-module-boundaries.md) | Module Boundaries and Data Ownership | accepted |
| [004](004-authorization-and-isolation.md) | RBAC + ABAC and Organizational Isolation | accepted |
| [005](005-work-records-dynamic-data.md) | WorkRecords and Dynamic Data | accepted |
| [006](006-workflow-versioning.md) | Workflow Versioning and Execution | accepted |
| [007](007-transactional-outbox.md) | Transactional Outbox | accepted |
| [008](008-shared-content-query-capabilities.md) | Shared Content and Query Capabilities | superseded |
| [009](009-unified-react-shell.md) | Unified React Shell | accepted |
| [010](010-air-gapped-kubernetes.md) | Air-Gapped Kubernetes | superseded |
| [011](011-lightweight-cqrs-and-transactions.md) | Lightweight CQRS and Transaction Boundaries | accepted |
| [012](012-local-identity-and-session-security.md) | Local Identity and Session Security | accepted |
| [013](013-documents-and-file-security.md) | Documents and File Security | accepted |
| [014](014-authorized-search.md) | Authorized Search | accepted |
| [015](015-authorized-reporting.md) | Authorized Reporting and Dashboards | accepted |
| [016](016-audit-and-records-governance.md) | Audit and Records Governance | accepted |
| [017](017-derived-workspace-and-notifications.md) | Derived Workspace and Notifications | accepted |
| [018](018-air-gapped-supply-chain.md) | Air-Gapped Supply Chain | superseded |
| [019](019-kubernetes-resilience-and-recovery.md) | Kubernetes Operations and Recovery | superseded |
| [020](020-organization-and-time-bounded-authority.md) | Organization and Time-Bounded Authority | accepted |
| [021](021-strategy-indicator-ownership.md) | Strategy and Indicator Ownership | accepted |
| [022](022-portfolio-projects-and-risk-boundaries.md) | Portfolio, Project, and Risk Boundaries | accepted |
| [023](023-single-host-dokploy-deployment.md) | Direct VPS Deployment with Docker Compose | accepted |
| [024](024-organization-identity-import-boundaries.md) | Organization/Identity Ownership and Import Boundaries | accepted |
| [025](025-job-titles-reference-normalization.md) | Job-Title Reference Normalization | accepted |

Approved template: [template.md](template.md).

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.3.0 | 2026-07-21 | Engineering Office | Added ADR-025 and accepted job-title normalization |
| 1.2.0 | 2026-07-18 | Engineering Office | Accepted ADR-024 after aligning W1.2 contracts |
| 1.1.0 | 2026-07-17 | Platform Architecture Council | Added ADR-024 |
| 1.0.0 | 2026-07-15 | Platform Architecture Council | Created the architecture decision index |
