---
doc_id: GOV-TR-001
title: Requirements Traceability Matrix
type: governance
status: accepted
version: 1.1.0
date: 2026-07-15
owner: Platform Engineering Office
reviewers:
- Product Owner
- Software Engineering Owner
- Information Security Owner
- Operations Owner
classification: internal
review_cycle: Quarterly
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/001-modular-monolith.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/adr/023-single-host-docker-compose-deployment.md
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/assumptions-constraints.md
- docs/governance/raci.md
- docs/product/vision-and-scope.md
- docs/product/releases-and-roadmap.md
- docs/product/success-metrics.md
- docs/data-security/logical-data-model.md
---
# Requirements Traceability Matrix

## 1. Purpose

This document links every operational, non-functional, security, or operational requirement to the source of the original decision, to the capability or module that fulfills it, to the version in which it is introduced, to the measurement standard, and to the verification role. It prevents the loss of requirements across work teams, enables measuring coverage, and reveals orphaned requirements that have no link to value.

The document does not elaborate on the implementation details of requirements. Each requirement here carries a reference to the document that explains its implementation and its detailed acceptance criterion.

## 2. Requirement Identifier

Requirements are assigned identifiers according to the format:

```text
{Category}-{Version}-{Sequence}
```

| Category | Meaning |
|---|---|
| `FR` | Functional Requirement |
| `NFR` | Non-Functional Requirement |
| `SEC` | Security Requirement |
| `OPS` | Operational Requirement |

| Version | Code |
|---|---|
| First Version | `R1` |
| Second Version | `R2` |
| Third Version | `R3` |

Example: `FR-R1-001` is the first functional requirement of the first version.

## 3. Common Acceptance Criteria

Every requirement carries a measurable acceptance criterion. The following metrics are used where applicable:

| Metric | Definition | Tool |
|---|---|---|
| P95 Response Time | The time not exceeded by 95% of requests under normal load | Performance Measurement |
| P99 Response Time | The time not exceeded by 99% of requests under extreme conditions | Performance Measurement |
| MTTR | Average time to recover from an incident | Incident Log |
| Service Availability | Percentage of time the service is available | Monitoring |
| RPO | Maximum acceptable period of data loss | Recovery Test |
| RTO | Maximum acceptable time to restore service | Recovery Test |
| Test Coverage | Percentage of tests covering a line or behavior | Coverage Tool |
| Scan Time | Time required for an automated security scan | Scanning Tool |
| Deployment Time | Time from approval to production | Release Log |
| Usability Index | Percentage of users who complete the task without assistance | Field Test |

## 4. Functional Requirements FR

### 4.1 Release 1 - Full General Platform

| ID | Requirement | Release | Module or Capability | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|---|
| FR-R1-001 | Create and modify the organizational structure of the cluster, facilities, and units with governed types | R1 | Organization | Vision Document §6 | Create a multi-depth tree | Use-case test |
| FR-R1-002 | Manage local accounts, their lifecycle, and passwords | R1 | Identity | Vision Document §6.6 | 100% of accounts have an expiration policy | Security test |
| FR-R1-003 | Manage roles, capabilities, and authorizations | R1 | Authorization | Vision Document §10.4 | Enforce the decision in the API and search | Architecture test |
| FR-R1-004 | Create supervisory relationships with a type, capabilities, and time range | R1 | Organization | Vision Document §7 | Expiration automatically withdraws capabilities | Behavioral test |
| FR-R1-005 | Create work types, fields, and relations from the interface | R1 | WorkDefinitions | Vision Document §12 | Publish a version without code | Field test |
| FR-R1-006 | Execute workflows with approvals, returns, escalations, and branching | R1 | Workflow | Vision Document §13 | Execute complex workflows | Functional test |
| FR-R1-007 | Manage the lifecycle of an internal request | R1 | `WorkRecords`, for the published work type `request` | Vision Document §17.3 | Full journey from draft to closure | Journey test |
| FR-R1-008 | Manage tasks with one owner, participants, comments, and mentions | R1 | Tasks | Vision Document §14 | Successful approval lifecycle | Journey test |
| FR-R1-009 | Upload, classify, version, and link documents to records | R1 | Documents | Vision Document §15 | Save the version with a checksum | Functional test |
| FR-R1-010 | Send, aggregate, and mark internal notifications as read | R1 | Notifications | Vision Document §16.3 | 0 notifications lost after commit | Resilience test |
| FR-R1-011 | Provide unified search governed by scope and field permissions | R1 | Search | Vision Document §16.1 | 0 titles exposed for a restricted record | Security test |
| FR-R1-012 | Provide configurable dashboards and reports without code | R1 | Reporting | Vision Document §16.2 | Display a dashboard according to role | Field test |
| FR-R1-013 | Provide a unified Arabic and English interface with RTL/LTR | R1 | Unified Shell | Vision Document §8 | Interface works in both languages | Usability test |
| FR-R1-014 | Enforce the access decision in the backend, not in the interface | R1 | Authorization | ADR-004 | 0 reliance on interface hiding | Architecture test |
| FR-R1-015 | Record audit events for sensitive operations | R1 | Audit | Vision Document §11.2 | Record every state change | Behavioral test |
| FR-R1-016 | Execute report exports within field permissions | R1 | Reporting | Vision Document §10.7 | Export respects masking | Security test |
| FR-R1-017 | Manage account status, disable accounts, and terminate their sessions | R1 | Identity | Vision Document §6.6 | Disabling revokes sessions and authorizations | Behavioral test |
| FR-R1-018 | Implement password recovery through a governed flow | R1 | Identity | Vision Document §6.6 | 0 password disclosures to administrators | Security test |
| FR-R1-019 | Manage general kernel, language, and time-zone settings | R1 | PlatformSettings | Vision Document §9 | Changing a setting requires no code | Field test |
| FR-R1-020 | Import the structure from CSV/XLSX through a dual-approval flow | R1 | Organization | Vision Document §30 | 0 application of critical errors | Journey test |

### 4.2 Release 2 - Strategy and Portfolios

| ID | Requirement | Release | Module or Capability | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|---|
| FR-R2-001 | Create a strategic plan, pillars, objectives, and initiatives | R2 | Strategy | Vision Document §18.1 | Link an objective to a pillar and indicator | Functional test |
| FR-R2-002 | Define indicators with an aggregation formula, baseline, owner, and frequency | R2 | Strategy | Vision Document §18.1 | Save a version of the indicator definition | Behavioral test |
| FR-R2-003 | Distribute targets across facilities with sum validation | R2 | Strategy | Vision Document §18.1 | Prevent approval when the distribution does not meet the cluster total | Behavioral test |
| FR-R2-004 | Enter and approve an indicator reading with evidence | R2 | Strategy | Vision Document §18.1 | Lock the period after approval | Journey test |
| FR-R2-005 | Create a portfolio, program, and project from a template | R2 | PortfolioProjects | Vision Document §18.2 | Save the template version on the project | Behavioral test |
| FR-R2-006 | Run a standard project and an improvement project with two different templates | R2 | PortfolioProjects | Vision Document §18.2 | Execute PDSA, DMAIC, and FOCUS-PDCA | Field test |
| FR-R2-007 | Calculate project completion from approved milestones and their evidence | R2 | PortfolioProjects | Vision Document §18.2 | 0 reliance on task count | Behavioral test |
| FR-R2-008 | Manage an administrative budget with approved, planned, spent, and forecast amounts | R2 | PortfolioProjects | Vision Document §18.2 | Calculate variance | Behavioral test |
| FR-R2-009 | Calculate project, program, and portfolio health using guardrail rules | R2 | PortfolioProjects | Vision Document §18.2 | Do not hide a critical project behind an average | Behavioral test |
| FR-R2-010 | Link a project to an indicator and measure approved actual impact | R2 | PortfolioProjects | Vision Document §18.3 | Attributed total impact ≤ improvement | Behavioral test |
| FR-R2-011 | Manage project gates through the workflow engine | R2 | PortfolioProjects | Vision Document §18.2 | Use the shared workflow engine | Architecture test |
| FR-R2-012 | Define a critical project classification and raise it automatically according to rules | R2 | PortfolioProjects | Vision Document §18.2 | Document the classification decision | Behavioral test |

### 4.3 Release 3 - Enterprise Risk

| ID | Requirement | Release | Module or Capability | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|---|
| FR-R3-001 | Create a risk register for an entity or unit | R3 | Risk | Vision Document §19.2 | Record an owner and level | Functional test |
| FR-R3-002 | Assess probability, impact, inherent level, and residual level | R3 | Risk | Vision Document §19.2 | Calculate the level according to the matrix | Behavioral test |
| FR-R3-003 | Manage controls and their effectiveness | R3 | Risk | Vision Document §19.2 | Link a control to a residual level | Behavioral test |
| FR-R3-004 | Manage treatment plans through the task service | R3 | Risk | Vision Document §19.3 | Use shared Tasks | Architecture test |
| FR-R3-005 | Record the risk owner and review dates | R3 | Risk | Vision Document §19.2 | Alert before the review date | Behavioral test |
| FR-R3-006 | Link an indicator defined and measured by Strategy as a KRI, and configure its thresholds and alerts | R3 | Risk for links, thresholds, and alerts; Strategy for definition and measurement | Vision Document §19.2 | An approved Strategy measurement exceeding a Risk threshold generates an alert | Behavioral and ownership-boundary test |
| FR-R3-007 | Accept, mitigate, transfer, or avoid the risk | R3 | Risk | Vision Document §19.2 | Document the decision and reason | Behavioral test |
| FR-R3-008 | Escalate critical risks according to the matrix | R3 | Risk | Vision Document §19.2 | Automatic escalation according to the rule | Behavioral test |
| FR-R3-009 | Link a risk to an objective, indicator, and project | R3 | Risk | Vision Document §19.3 | Display cross-cutting impact | Behavioral test |
| FR-R3-010 | Provide risk dashboards at management, facility, and cluster levels | R3 | Risk | Vision Document §19.2 | Change the dashboard by scope | Field test |

## 5. Non-Functional Requirements NFR

| ID | Requirement | Release | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|
| NFR-R1-001 | P95 response time below 1.5 seconds for a normal read | R1 | Vision Document §24 | P95 ≤ 1.5s | Performance test |
| NFR-R1-002 | P99 response time below 3 seconds under extreme conditions | R1 | Vision Document §24 | P99 ≤ 3s | Performance test |
| NFR-R1-003 | Support up to 20,000 accounts | R1 | Vision Document §24.1 | Accommodate 20k | Capacity test |
| NFR-R1-004 | Support up to 2,000 concurrent users | R1 | ADR-019 | 2k concurrent | Load test |
| NFR-R1-005 | New-release deployment time below 30 minutes | R1 | Operations Document | ≤ 30 minutes | Deployment log |
| NFR-R1-006 | Support RTL and LTR with equal quality | R1 | Vision Document §8.5 | Both languages work | Usability test |
| NFR-R1-007 | Service availability ≥ 99.5% per month | R1 | Vision Document §24.2 | ≥ 99.5% | Monitoring |
| NFR-R1-008 | Daily backup time ≤ 60 minutes | R1 | Operations Document | ≤ 60 minutes | Operations log |
| NFR-R2-001 | Indicator-dashboard response time P95 below 2 seconds | R2 | Performance Document | P95 ≤ 2s | Performance test |
| NFR-R2-002 | Tolerate 50% growth in project count without re-architecture | R2 | Capacity Document | Accommodate 1.5x | Capacity test |
| NFR-R3-001 | Risk-evaluation time P95 below 2 seconds | R3 | Performance Document | P95 ≤ 2s | Performance test |
| NFR-R3-002 | Support 5,000 active risks without performance degradation | R3 | Capacity Document | 5k records | Capacity test |

## 6. Security Requirements SEC

| ID | Requirement | Release | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|
| SEC-R1-001 | Deny inbound access and publishing of status and administration services by default | R1 | ADR-023 | Only approved ports are available | Live-host scan |
| SEC-R1-002 | Encrypt sensitive fields at column level with an internal KMS | R1 | Vision Document §13 | All PII encrypted | Security scan |
| SEC-R1-003 | Enforce the access decision in Laravel, not in the interface | R1 | ADR-004 | No permission grants in JS | Architecture test |
| SEC-R1-004 | Prevent implicit capability grants through a relationship | R1 | Vision Document §7.3 | Every capability declared | Architecture test |
| SEC-R1-005 | Log access to confidential and sensitive content | R1 | Vision Document §11.2 | Complete logging | Behavioral test |
| SEC-R1-006 | Enforce a minimum password policy that may only be strengthened | R1 | Vision Document §6.6 | No reduction below the minimum | Behavioral test |
| SEC-R1-007 | Lock the account after a number of failed attempts | R1 | Vision Document §6.6 | Lock after N attempts | Security test |
| SEC-R1-008 | Terminate sessions and revoke authorizations when an account is disabled | R1 | Vision Document §6.6 | Immediate termination | Behavioral test |
| SEC-R1-009 | Disallow direct queries between business-module tables | R1 | ADR-003 | 0 cross-module joins | Architecture test |
| SEC-R1-010 | Digitally sign work-definition and workflow packages | R1 | Vision Document §12.5 | Signature verification | Security test |
| SEC-R1-011 | Scan dependencies and secrets and build runtime images from lockfiles | R1 | ADR-023 | Green CI and images without build tools or secrets | CI scan |
| SEC-R1-012 | Separate encrypted backups from the production server | R1 | ADR-023 | Storage outside the server-failure domain | Operations scan |
| SEC-R1-013 | Isolate the audit log with a hash chain | R1 | Vision Document §11.2 | Event chaining | Security test |
| SEC-R1-014 | Restrict secret reading to the user themselves | R1 | Vision Document §6.6 | 0 administrator access | Security test |
| SEC-R2-001 | Prevent approval of a target distribution that does not meet the cluster total | R2 | Vision Document §18.1 | Mathematical validation | Behavioral test |
| SEC-R2-002 | Restrict approval of a project contribution so that it does not exceed observed improvement | R2 | Vision Document §18.3 | Logical validation | Behavioral test |
| SEC-R3-001 | Apply the risk appetite policy to escalation | R3 | Vision Document §19.2 | Escalation ceiling | Behavioral test |
| SEC-R3-002 | Document the risk-acceptance decision with reason and owner | R3 | Vision Document §19.2 | Complete recording | Behavioral test |

## 7. Operational Requirements OPS

| ID | Requirement | Release | Source | Measurement Criterion | Verification |
|---|---|---|---|---|---|
| OPS-R1-001 | RPO no greater than 15 minutes | R1 | Vision Document §24.3 | RPO ≤ 15m | Recovery test |
| OPS-R1-002 | RTO no greater than two hours | R1 | Vision Document §24.3 | RTO ≤ 2h | Recovery test |
| OPS-R1-003 | Availability ≥ 99.5% monthly | R1 | Vision Document §24.2 | ≥ 99.5% | Monitoring |
| OPS-R1-004 | Encrypted backup outside the production server | R1 | ADR-023 | Storage outside the server-failure domain | Operations scan |
| OPS-R1-005 | Documented periodic recovery test | R1 | Vision Document §24.3 | Monthly test | Operations log |
| OPS-R1-006 | Direct VPS deployment with health check and rollback | R1 | ADR-023 | Rollback to a healthy commit | Deployment log |
| OPS-R1-007 | Separate production data, secrets, and volumes from Dev/Test | R1 | ADR-023 | No sharing between environments | Operations scan |
| OPS-R1-008 | Controlled update followed by deploy and rollback through Compose | R1 | ADR-023 | Complete deploy and rollback record | Operations log |
| OPS-R1-009 | Monitor and alert on service availability and errors | R1 | Vision Document §23.5 | Alert within five minutes | Monitoring test |
| OPS-R1-010 | Centralized logs for events and access | R1 | Vision Document §11.2 | Log aggregation | Operations scan |
| OPS-R1-011 | Build images from lockfiles and deploy them through Compose | R1 | ADR-023 | Published commit matches and health succeeds | Operations scan |
| OPS-R1-012 | Pin Composer and npm with lockfiles and maintain a reference-update log | R1 | ADR-023 | Lockfiles, commit, and release match | CI scan |
| OPS-R2-001 | Rebuild the indicator dashboard within five minutes after the period | R2 | Operations Document | ≤ 5m | Behavioral test |
| OPS-R2-002 | Restore a project from its latest snapshot within the defined RTO | R2 | Operations Document | Full restoration | Recovery test |
| OPS-R3-001 | Alert automatically when risks exceed the risk-appetite threshold | R3 | Operations Document | Immediate alert | Behavioral test |
| OPS-R3-002 | Archive risk assessments according to the retention policy | R3 | Retention Document | Defined period | Operations scan |

## 8. Release Coverage Matrix

| Capability | FR | NFR | SEC | OPS | Release |
|---|---|---|---|---|---|
| Enterprise core | FR-R1-001 to FR-R1-004, FR-R1-017, FR-R1-019 | NFR-R1-001 to NFR-R1-005 | SEC-R1-001 to SEC-R1-009 | OPS-R1-001 to OPS-R1-012 | R1 |
| Work-type and workflow builder | FR-R1-005, FR-R1-006 | NFR-R1-001 | SEC-R1-010 | OPS-R1-006 | R1 |
| Requests, tasks, and documents | FR-R1-007 to FR-R1-010 | NFR-R1-002 | SEC-R1-005 | OPS-R1-009 | R1 |
| Search and reporting | FR-R1-011, FR-R1-012 | NFR-R1-001 | SEC-R1-009 | OPS-R1-010 | R1 |
| Unified interface | FR-R1-013 | NFR-R1-006 | SEC-R1-003 | OPS-R1-009 | R1 |
| Strategy and indicators | FR-R2-001 to FR-R2-004 | NFR-R2-001 | SEC-R2-001 | OPS-R2-001 | R2 |
| Portfolios, programs, and projects | FR-R2-005 to FR-R2-012 | NFR-R2-002 | SEC-R2-002 | OPS-R2-002 | R2 |
| Enterprise risk | FR-R3-001 to FR-R3-010 | NFR-R3-001, NFR-R3-002 | SEC-R3-001, SEC-R3-002 | OPS-R3-001, OPS-R3-002 | R3 |

## 9. Requirement Role and Responsibility Matrix

| Category | Classification Owner | Measurement Owner | Acceptance Owner |
|---|---|---|---|
| `FR` | Product Owner | Software Engineering Owner | Platform Sponsor |
| `NFR` | Software Engineering Owner | Operations Owner | Platform Sponsor |
| `SEC` | Information Security Owner | Information Security Owner | Platform Sponsor |
| `OPS` | Operations Owner | Operations Owner | Platform Sponsor |

## 10. Requirements Health Indicators

| Indicator | Target | Measurement |
|---|---|---|
| Percentage of requirements covered by tests | ≥ 90% per release | CI report |
| Percentage of requirements with an objective measurement criterion | 100% | Quarterly review |
| Percentage of isolated requirements without a measurable value | 0% | Semi-annual review |
| Percentage of requirements with a clear owner | 100% | Quarterly review |

## 11. Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Initial creation linking functional, non-functional, security, and operational requirements to versions, sources, and standards |
| 1.1.0 | 2026-07-15 | Platform Engineering Office | Assignment of FR-R1-007 to `WorkRecords` and updating architectural and operational references |
