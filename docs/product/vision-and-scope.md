---
doc_id: PRD-VS-001
title: Vision and Scope
type: product
status: accepted
version: 1.1.0
date: 2026-07-16
owner: Product Owner
reviewers:
- Platform Sponsor
- Platform Engineering Office
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: Quarterly
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/001-modular-monolith.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/023-single-host-dokploy-deployment.md
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/assumptions-constraints.md
- docs/governance/traceability-matrix.md
- docs/governance/raci.md
- docs/product/personas-and-journeys.md
- docs/product/releases-and-roadmap.md
- docs/product/success-metrics.md
---

> **R2/R3 modules (Strategy, Indicators, PortfolioProjects, Risk) are PLANNED for R2/R3 and are not yet implemented in the codebase.** Only R1 modules are currently in code: Authorization, Documents, Identity, Notifications, Organization, PlatformSettings, Reporting, Search, Tasks, WorkDefinitions, WorkRecords, Workflow.

# Vision and Scope

## 1. Purpose

This document defines the platform's enterprise vision and what is and is not included across the three planned releases. It prevents scope expansion without an explicit decision and provides a reference against which release success is measured. It refers to the architecture decisions in `docs/adr/` and to the security and data documents rather than duplicating their details.

## 2. Vision

The Third Health Cluster will have a unified digital platform that manages administrative operations between the Cluster and its affiliated facilities through reusable shared capabilities, fine-grained and explainable authorization, and business modules that can be added without rebuilding the core, while remaining self-hosted on a protected internal server.

## 3. Single Enterprise Statement

> A unified enterprise platform for the Third Health Cluster that manages administrative operations between the Cluster and its facilities with centralized governance, fine-grained authorization, and explainable decisions, hosted on a restricted-access internal server.

## 4. Approved Enterprise Principles

| Principle | Operational application | Reference |
|---|---|---|
| Simplicity for the user | The interface answers only four questions: What do I need to do? By when? Who is waiting on me? How far has it progressed? | Vision document §4.1 |
| Small, stable core | The core knows only the organization and access model; it does not know about projects or risks | Vision document §4.2 |
| One owner for each piece of information | Every piece of information has an owning module referenced by a stable identifier | Vision document §4.3 and the requirements traceability document |
| Centralized governance and distributed execution | The Cluster defines policies, and facilities execute according to their permissions | Vision document §4.4 |
| Phased expansion | Only three releases are within the current scope | Releases document |
| No technical complexity without cause | Use a Modular Monolith until the separation criterion is met | ADR-001 |
| Simple self-hosting | Docker Compose and Caddy on a single VPS with limited open ports | ADR-023 |
| Fit to team capacity | A design understandable by a team of 2 to 4 developers | Assumptions document A2 |

## 5. What the Platform Manages (In Scope)

| Category | Items | Release |
|---|---|---|
| Enterprise core | Organizational structure, accounts, roles, supervisory relationships, classification | R1 |
| Shared capabilities | Work type builder, workflows, tasks, documents, search, notifications, reporting, and auditing | R1 |
| Work type within `WorkRecords` | General internal request | R1 |
| Business module | Strategy and indicators | R2 |
| Business module | Portfolios, programs, and projects | R2 |
| Business module | Enterprise risk | R3 |
| Architecture | Laravel Modular Monolith + unified React application + MySQL and Redis | R1 |
| Operations | One VPS using Docker Compose and Caddy, with a firewall and off-host backups | R1 |
| Languages | Arabic by default and full English support, with RTL/LTR | R1 |
| Capacity target | Up to 20,000 accounts and 2,000 concurrent users | R1 |

## 6. What the Platform Does Not Manage (Out of Scope)

| Item | Reason | Alternative |
|---|---|---|
| Electronic medical records and patient data | The platform is administrative, not clinical | A separate HIS or EMR system |
| Payroll, leave, and formal promotions | Responsibility of the Mawared system | Mawared system |
| Full financial and accounting system | Outside the platform's remit | Financial system |
| Procurement and disbursement orders as a financial system | Outside the platform's remit | Financial system |
| Native mobile application | The interface is responsive | A future release if needed |
| Integrations with external systems before they are defined | No specific system, data, or exchange direction has been defined | When a specific integration is available |
| OCR, accredited electronic signatures, and complete formal archiving | These require specialized infrastructure | Later releases |
| Email, SMS, and WhatsApp notifications | No approved integration gateway is available | When an approved gateway is available |
| Semantic search and artificial intelligence | Not required in the current scope | Later releases |

## 7. Phase Boundaries by Release

### 7.1 Release One R1 — Complete General Platform

- The enterprise core, shared platform capabilities, and a published "General Internal Request" work type, whose records are owned by `WorkRecords`.
- The unified interface in Arabic and English.
- 5,000 accounts initially, scalable to 20,000.
- Up to 2,000 concurrent users.
- Direct deployment to a VPS with restricted ports, accepting the risk of service outage if the single host fails.
- An acceptance pilot with one Cluster department and its counterpart at one hospital.

### 7.2 Release Two R2 — Strategy and Portfolios

- Strategy module: plans, themes, objectives, initiatives, indicators, and readings.
- Portfolios, programs, and projects module: hierarchy, templates, standard projects, improvement projects, and impact.
- Depends on R1 for documents, tasks, workflows, and authorization.
- An acceptance pilot with one Cluster program and one program at a hospital.

### 7.3 Release Three R3 — Enterprise Risk

- Risk module: risk registers, controls, treatment plans, and risk indicators.
- Depends on R1 and R2 for tasks, workflows, documents, and indicators.
- An acceptance pilot with one Cluster risk department and one risk department at a hospital.

## 8. Vision Success Criteria

The platform uses five general success criteria measured across the three releases:

| Criterion | Measurement | Target | Release |
|---|---|---|---|
| Clear responsibility and status | Percentage of records with an owner and a readable status | ≥ 95% | R1 |
| Administrative process completion time | Cycle time of the reference request from draft to closure | ≤ 5 business days | R1 |
| Standardized performance measurement | Percentage of indicators recorded in the platform instead of Excel | ≥ 80% | R2 |
| Connecting projects to impact | Percentage of improvement projects with approved actual impact | ≥ 70% | R2 |
| Enterprise risk awareness | Percentage of risks with an active treatment plan | ≥ 90% | R3 |

## 9. Failure Criteria Requiring a Stop

| Condition | Action |
|---|---|
| The specified RTO is exceeded during a critical production incident without recovery within the deadline | Freeze deployment until the Platform Engineering Office completes its review |
| A security incident classified as `high` recurs within 30 days | Freeze new features until an approved remediation plan exists |
| At least 30% of users reject the release experience in a field test | Review the scope before any expansion |
| The `SEC-R1-003` criterion for applying authorization in Laravel is not met | Do not deploy to production |

## 10. Critical Assumptions Affecting Scope

| Assumption | Dependent item | Reference |
|---|---|---|
| The Cluster is an operating entity with its own internal processes | Business modules specific to the Cluster, rather than oversight only | Assumptions document A1 |
| A team of 2 to 4 developers | Adoption of a Modular Monolith | Assumptions document A2 |
| Users connect from inside the network | Username and password authentication only | Assumptions document A3 |
| Operations are isolated | No cloud integration or CDN | Assumptions document T1 |
| Accounts are local | No need for an external identity provider | Assumptions document T9 |

## 11. Risks Affecting Scope

| Risk | Effect on scope | Action |
|---|---|---|
| Failure of the initial user experience | Redesign the interface | Conduct an early field test |
| Resistance to adoption and a return to email | Delayed rollout | Adoption plan with training |
| Weak module-boundary tests | Platform coupling | Architecture test in CI |
| Delayed package upgrades because of the isolated environment | Potential security vulnerabilities | Quarterly upgrade schedule |
| Adoption of an external integration before it matures | Increased platform complexity | Hard gate before any integration |

## 12. References

| Topic | Document |
|---|---|
| Architecture decisions | `docs/adr/README.md` |
| Module boundaries | `docs/architecture/module-catalog.md` |
| Deployment architecture | `docs/architecture/overview.md` and `docs/operations/physical-topology.md` |
| Measurable requirements | `docs/governance/traceability-matrix.md` |
| Governance roles | `docs/governance/raci.md` |
| User personas and journeys | `docs/product/personas-and-journeys.md` |
| Release details and deliverables | `docs/product/releases-and-roadmap.md` |
| Detailed success metrics | `docs/product/success-metrics.md` |

## 13. Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Product Owner | Initial definition of the vision and scope across the three releases |
