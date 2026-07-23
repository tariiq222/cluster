---
doc_id: GOV-AC-001
title: Assumptions, Constraints, and Risks
type: governance
status: accepted
version: 1.1.0
date: 2026-07-16
owner: Platform Engineering Office
reviewers:
- Engineering Lead
- Security Lead
- Operations Lead
classification: internal
review_cycle: Semi-annual
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/001-modular-monolith.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/007-transactional-outbox.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/adr/023-single-host-dokploy-deployment.md
- docs/governance/document-control.md
- docs/governance/raci.md
- docs/governance/traceability-matrix.md
- docs/product/vision-and-scope.md
- docs/product/releases-and-roadmap.md
---
# Assumptions, Constraints, and Risks

## 1. Purpose

This document defines the assumptions underpinning platform decisions, the constraints that cannot be violated, and what lies outside the scope, in addition to the institutional risks monitored by governance. It is used as the basis for measuring deviation during execution. The document does not repeat the architectural decisions detailed in `docs/adr/`, but refers to them within the approved documentation source `docs/`.

## 2. Institutional Assumptions

| # | Assumption | Verification Criterion | Impact on Violation |
|---|---|---|---|
| A1 | The third health cluster is an operational entity with internal operations, not merely a supervisory role | Presence of operational departments and tasks within the cluster using the platform | Home page and workspace are redesigned |
| A2 | Development team of 2 to 4 people with strong Laravel experience and less mature React and platform operations experience | Architecture and product separation review with each hiring | Release plan is re-estimated |
| A3 | End users connect from an approved network or a protected access path on a modern browser | Session and network path inspection from production logs | Non-approved path is closed and firewall is reconfigured |
| A4 | Users are proficient in Arabic and able to read English when needed | Usability testing with a sample of each role | Interface is simplified and English terms are reduced |
| A5 | Organizational decisions are issued exclusively by the Super Admin and are not granted automatically through a relationship | Architectural test preventing implicit capability grants | Permission model is redesigned |
| A6 | Official institutions such as "Mawared" remain responsible for their data without commitment to integration in the first phase | Absence of integration contract in the first release plan | First release remains as-is without integrations |
| A7 | Current data exists in email and Excel and is not migrated automatically | Operational trial on one department | First release starts from scratch |

## 3. Technical Assumptions

| # | Assumption | Verification Criterion | Impact on Violation |
|---|---|---|---|
| T1 | The platform runs on a single VPS via Docker Compose and Caddy, with inbound access controlled by a firewall | Live port scanning and service binding inspection | Deployment is blocked and the host is reconfigured |
| T2 | MySQL on the server can serve the measured load within the machine's resources | Load test on the server before launch | Server resources are scaled up or hosting decision is revisited |
| T3 | Container images are pinned by digest and enter through an approved update path | Compose and release log inspection | Release is blocked |
| T4 | Composer and npm use lockfiles and approved sources during build only | Build inspection and absence of downloads at container start | Release is blocked |
| T5 | The Laravel Modular Monolith application suffices for the first, second, and third phases | No operational need for Microservice separation | Pattern is reconsidered according to the separation criterion |
| T6 | Modular Monolith can be reorganized without a complete rewrite | Semi-annual architectural review | Module is separated when the criterion is met |
| T7 | The React interface is unified for all roles including Super Admin | Usability test for Super Admin as a regular employee | Separate interfaces are prohibited |
| T8 | Application data lives on local volumes, and the encrypted backup is stored outside the production server | Recovery test to a separate target | Production data is prohibited |
| T9 | The search engine does not depend on a SaaS service required to run the product | Technical review of the search product before selection | Product is replaced |

## 4. Operational Assumptions

| # | Assumption | Verification Criterion | Impact on Violation |
|---|---|---|---|
| O1 | There is a responsible owner for the VPS, Docker, backups, and recovery | Owner availability and emergency procedure | Recovery targets are reduced or production is delayed |
| O2 | Backup is in a vault outside the production server and encrypted | Vault inspection, location, and credentials | Production data is prohibited |
| O3 | Development and testing do not use production data and do not share its secrets | Configuration and data inspection | Production deployment is blocked |
| O4 | Docker and server maintenance is performed in a declared window that accepts service downtime | Approved maintenance plan | Window is adjusted |
| O5 | Package and image updates go through a documented review path, then deploy with a measured rollback | Update and release log | Unreviewed updates are prohibited |

## 5. Hard Constraints

| # | Constraint | Permitted Exception | Reference |
|---|---|---|---|
| C1 | Inbound access is default-deny; only HTTPS and a restricted admin path are allowed | Temporary port with approval and closure log | ADR-023 |
| C2 | MySQL, Redis, or Docker socket are not exposed to the public | No exception | ADR-023 |
| C3 | No CDN, public lines, or scripts required to run the interface | No exception | ADR-023 |
| C4 | No integration with an external system in the first release | No exception | First release |
| C5 | No data migration from email or Excel in the first release | Structure import from CSV/XLSX after Super Admin approval | First release |
| C6 | Accounts are local within the platform and do not require an external identity service | No exception | Approved design |
| C7 | One account per person is the default; an emergency account is opened only by a documented procedure | Emergency account closed by formal procedure | Approved design |
| C8 | Passwords cannot be reduced below the security minimum defined in the security document | No exception | Security document |
| C9 | The platform is not a clinical system and does not store patient data | No exception | Scope document |
| C10 | System decisions are executed in Laravel and the interface, not in JavaScript | One exception: hiding interface elements | ADR-004 |
| C11 | Every transaction is recorded in `Outbox` within the same `Transaction` | No exception | ADR-007 |
| C12 | Search and reporting do not write to business tables | No exception | ADR-008 |
| C13 | Isolation between facilities is the default and is broken only by an explicit relationship or sharing | No exception | ADR-004 |
| C14 | Live records are not silently migrated to a new release | Migration by explicit request and compatibility check | ADR-005 and ADR-006 |
| C15 | A regular user cannot modify their entity, position, or permissions | Super Admin only | Approved design |
| C16 | A disabled account immediately cancels sessions and delegations | No exception | Approved design |

## 6. Out of Scope

| # | Item | Reason | Proposed Future Owner |
|---|---|---|---|
| OOS1 | Electronic medical records and patient data | Platform is administrative, not clinical | Separate HIS or EMR systems |
| OOS2 | Salaries, leaves, and official promotions | Mawared responsibility | Mawared system |
| OOS3 | Full financial and accounting system | Specialized financial systems responsibility | Financial system |
| OOS4 | Procurement and disbursement orders as invoices | Platform does not manage operational finances | Financial system |
| OOS5 | Native mobile application | Interface is responsive in the first phase | Future when needed |
| OOS6 | Subtasks | First phase simplification | Second release or later |
| OOS7 | Full formal archive with retention numbers | Requires specialized infrastructure | Advanced document service |
| OOS8 | Internal OCR | Requires specialized infrastructure and tools | Later releases |
| OOS9 | Officially certified electronic signature | Requires legislative infrastructure | Later releases |
| OOS10 | Integrations with external systems before they are defined | No defined system, data, or exchange direction | When a specific integration is available |
| OOS11 | Email, SMS, and WhatsApp for notifications | No approved integration gateway yet | When an approved gateway is available |
| OOS12 | Semantic search and artificial intelligence | Not required in the first phase | Later releases |
| OOS13 | Event Sourcing and independent event stores | Complexity not justified | When the separation criterion is met |

## 7. Institutional Risks and Status

| # | Risk | Likelihood | Impact | Preventive Action | Owner |
|---|---|---|---|---|---|
| R1 | First-time user experience failure and perception of the platform as complex | Medium | High | Early usability testing with each role | Product Owner |
| R2 | Permission breach due to relying on isolation in the interface only | Low | Very High | Decision enforcement in Laravel and architectural test coverage | Security Lead |
| R3 | Data loss due to lack of regular recovery testing | Low | Very High | Documented monthly recovery test | Operations Lead |
| R4 | Single production server failure brings down the service | Medium | High | Monitoring, spare parts/standby target, and off-site backup with measured recovery | Operations Lead |
| R5 | Concurrent edit conflict on a record | Medium | Medium | Optimistic locking and conflict display for review | Engineering Lead |
| R6 | Regulatory change requiring modifications to governance and classification | Medium | Medium | Quarterly review and a dedicated compliance owner | Security Lead |
| R7 | Implementation failure in release due to weak boundary tests between modules | Medium | High | Architectural test in CI preventing prohibited imports | Engineering Lead |
| R8 | Image or Compose update breaks the service | Medium | High | Pinned digest, pre-deployment inspection, and full rollback version retained | Operations Lead |
| R9 | User adoption resistance and return to email-based workflows | Medium | High | Adoption plan with training and weekly usage measurement | Product Owner |
| R10 | Adoption of external integration before the architecture matures | Low | High | Strict gate before any integration | Platform Engineering Office |
| R11 | Duplication of shared capabilities between modules | Medium | Medium | Semi-annual architectural review and enforced boundaries | Platform Engineering Office |
| R12 | Undocumented personal decisions affecting the release | Low | Medium | Document controls and RACI by roles | Platform Engineering Office |

## 8. Response Matrix

| Likelihood \ Impact | Low | Medium | High | Very High |
|---|---|---|---|---|
| High | Monitor | Mitigate | Response Plan | Response Plan |
| Medium | Monitor | Mitigate | Response Plan | Urgent Response Plan |
| Low | Monitor | Mitigate | Response Plan | Urgent Response Plan |

## 9. Review Log

| Date | Role | Decision |
|---|---|---|
| 2026-07-15 | Platform Engineering Office | Approve first release |
| 2026-07-16 | Platform Owner | Approve single-host deployment with Docker Compose without Dokploy per ADR-023 |
