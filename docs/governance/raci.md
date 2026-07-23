---
doc_id: GOV-RC-001
title: RACI Accountability Matrix
type: governance
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Platform Engineering Office
reviewers:
- Governance Lead
- Product Owner
- Engineering Lead
classification: internal
review_cycle: Semi-annual
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/assumptions-constraints.md
- docs/governance/traceability-matrix.md
- docs/product/vision-and-scope.md
- docs/product/releases-and-roadmap.md
---
# RACI Accountability Matrix

## 1. Purpose

This document defines the institutional and technical roles assigned with responsibilities and distributes the roles across the main activities of the platform using the approved `RACI` semantics. It prevents confusion between a person's name and the role, and enables institutional review when individuals change. The document does not mention individual names, but deals exclusively with roles.

## 2. RACI Semantics

| Code | Meaning | Condition |
|---|---|---|
| `R` Responsible | Role that performs the activity or delivers the output | At least one role and at most two roles per activity |
| `A` Accountable | Role that signs off on the output and owns the acceptance decision | Exactly one role per activity |
| `C` Consulted | Role that provides input before execution or approval | Explicitly stated and opinion documented |
| `I` Informed | Role that is notified of the output without participating in the decision | Formal notification is sufficient |

## 3. Institutional Roles

| Role | Scope | Operational Reference |
|---|---|---|
| Platform Sponsor (Executive Sponsor) | Funds the project, owns the strategic decision, and resolves conflicts between priorities | Platform Engineering Office |
| Product Owner | Defines the vision, scope, success metrics, and release priority | Product documents |
| Governance Lead | Manages document controls and decisions and reviews compliance | Document Control document |
| Engineering Lead | Ensures architectural correctness, module boundaries, and code quality | Architecture and ADR document |
| Security Lead | Defines the security policy, classification, permissions, and penetration | Security documents |
| Operations Lead | Manages deployment, monitoring, recovery, and backup | Operations documents |
| Data Lead | Oversees the logical model, data quality, and projections | Data model document |
| QA Lead | Manages the testing strategy and acceptance criteria | Testing document |
| Platform Engineering Office | Coordinates between roles and edits the ADR and architecture documents | Document Control document |
| Super Admin | Role within the platform to manage structures, permissions, and administrative content | Scope and product document |

## 4. Operational Roles Within the Platform

| Role | Scope Within the Platform |
|---|---|
| Cluster Officer | User role combining administrative permissions at the cluster level and supervisory relationships |
| Facility Manager | User role managing an operational unit at the facility level |
| Department Manager | User role managing an administrative unit and signing off on its transactions |
| Project Manager | User role managing a project, program, or portfolio |
| Indicator Coordinator | User role entering indicator readings for a facility |
| Indicator Owner | User role approving indicator readings and distributing targets |
| Team Member | User role working within a project or committee |
| Reviewer | User role providing review on blind outputs without modification authority |
| Employee | Base user with limited permissions within their scope |

## 5. Institutional Activities Matrix

| Activity | Platform Sponsor | Product Owner | Governance Lead | Engineering Lead | Security Lead | Operations Lead | Platform Engineering Office |
|---|---|---|---|---|---|---|---|
| Approve vision and scope | A | R | C | C | C | I | C |
| Approve release roadmap | A | R | C | C | C | C | C |
| Approve budget and funding | R | C | I | I | I | I | I |
| Approve new architectural decision | I | C | C | R | C | C | A |
| Approve governance document | I | I | R | C | C | I | A |
| Approve security policy | A | I | C | C | R | C | C |
| Approve recovery and disaster plan | A | I | C | C | C | R | C |
| Approve data model | I | C | C | C | C | I | A |
| Approve release acceptance criteria | A | R | C | C | C | C | C |
| Approve production entry | A | R | I | C | C | R | C |
| Promote release between environments | I | I | I | C | C | R | C |
| Manage critical incidents | A | I | I | C | C | R | C |
| Review future module separation | I | C | C | R | C | C | A |
| Respond to organizational change request | A | C | R | C | C | I | C |

## 6. Release Lifecycle Activities Matrix

| Activity | Platform Sponsor | Product Owner | Governance Lead | Engineering Lead | Security Lead | Operations Lead | Data Lead | QA Lead | Platform Engineering Office |
|---|---|---|---|---|---|---|---|---|---|
| Define release objectives | A | R | C | C | C | C | C | C | C |
| Decompose release into specifications | I | R | I | C | C | I | C | C | C |
| Approve module specification | I | A | I | R | C | I | C | C | C |
| Execute plan within release | I | I | I | R | I | C | C | C | C |
| Architectural review of plan | I | I | I | A | C | C | C | I | R |
| Security review of plan | I | I | I | C | A | C | I | I | C |
| Operational review of plan | I | I | I | C | C | A | I | I | C |
| Data review of plan | I | I | I | C | C | I | A | I | C |
| Execute quality tests | I | I | I | C | I | I | I | A | C |
| Approve acceptance criteria | A | R | C | C | C | C | C | C | C |
| Field user experience | I | A | I | C | I | C | I | C | C |
| Approve production deployment | A | R | I | C | C | R | I | C | C |
| Document lessons learned | I | C | C | R | I | C | C | C | A |

## 7. Platform Operational Activities Matrix

| Activity | Super Admin | Cluster Officer | Facility Manager | Department Manager | Employee | Platform Engineering Office | Security Lead | Operations Lead |
|---|---|---|---|---|---|---|---|---|
| Create new organizational unit | R | I | I | I | I | A | I | I |
| Modify supervisory relationship | R | C | C | I | I | A | C | I |
| Approve CSV/XLSX import | R | C | C | C | I | A | C | I |
| Create new work type | R | C | C | C | I | A | C | I |
| Deploy work type release | R | C | C | C | I | A | C | I |
| Modify published path | R | C | C | C | I | A | C | I |
| Approve indicator target distribution | I | R | C | C | I | A | I | I |
| Approve indicator reading | I | R | C | C | I | A | I | I |
| Approve new project | C | R | C | C | I | A | I | I |
| Close project | C | R | C | C | I | A | I | I |
| Accept or avoid risk | C | R | C | C | I | A | C | I |
| Approve regulatory record lock | R | C | C | C | I | A | C | I |
| Manage user and account | R | I | C | C | I | A | C | I |
| Cancel emergency delegation | R | I | C | C | I | A | C | I |
| Escalate security incident | R | C | C | C | I | C | A | C |

## 8. Application Rules

- Each cell in the matrix carries only one code from `R`, `A`, `C`, or `I`.
- `R` cannot be assigned to more than two roles per activity.
- Each activity must have exactly one `A` role.
- Absence of `A` for any activity is a distribution defect and is recorded in the risk log.
- The matrix is updated on any change in organizational structure or platform roles.

## 9. Exceptions and Alternatives

| Case | Alternative |
|---|---|
| Platform Sponsor absence in an emergency | `A` transfers to Platform Engineering Office with documentation |
| Product Owner absence | Platform Engineering Office assumes `A` and `R` with Sponsor notification |
| Conflict between Security Lead and Operations Lead | Escalated to Platform Engineering Office and resolved within 48 hours |
| Adding a new internal platform role | Added to `docs/governance/raci.md` and the change log is updated |

## 10. Adherence Indicators

| Indicator | Target | Measurement | Responsible Role |
|---|---|---|---|
| Percentage of activities with a single `A` | 100% | Quarterly review | Governance Lead |
| Percentage of decisions documented with activity, decision, and date | 100% | Quarterly review | Platform Engineering Office |
| Percentage of platform roles covered in the daily usage matrix | 100% | Semi-annual review | Product Owner |
| Percentage of RACI updates after any organizational change | Within 14 days | Semi-annual review | Platform Engineering Office |

## 11. Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Initial matrix creation with roles only, no individual names |
