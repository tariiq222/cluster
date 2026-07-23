---
doc_id: GOV-GL-001
title: Unified Glossary of Terms
type: governance
status: accepted
version: 1.2.0
date: 2026-07-15
owner: Platform Engineering Office
reviewers:
- Governance Lead
- Product Lead
- Software Engineering Lead
classification: internal
review_cycle: Quarterly
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/003-module-boundaries.md
- docs/adr/005-work-records-dynamic-data.md
- docs/governance/document-control.md
- docs/governance/assumptions-constraints.md
- docs/governance/raci.md
- docs/product/vision-and-scope.md
- docs/product/personas-and-journeys.md
- docs/data-security/logical-data-model.md
- docs/domain/organization-and-people.md
---
# Unified Glossary of Terms

## 1. Purpose

This glossary defines the meanings of core terms in English (and Arabic legacy) so they are used consistently across the platform's documentation, code, and user interface. It prevents name divergence between the product team, the engineering team, and end users, and is the reference point whenever the interpretation of another document is in dispute.

The glossary does not duplicate the content of other governance documents. Each term here carries a single operational definition and refers to the detailed document when further expansion is needed.

## 2. Usage Rules

- The English term is the basis for the UI and execution documents; the Arabic legacy label, when present, appears in parentheses on the first occurrence in each section.
- Using multiple synonyms for the same concept within a single document is prohibited; exactly one name from this glossary must be chosen.
- When a new term is introduced in a document, it must be added here in the same `commit` as the change.
- Names of individuals and commercial products are not defined inside the glossary.

## 3. Institutional and Organizational Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Third Health Cluster | Third Health Cluster | The root entity of the platform; it has subordinate facilities and represents the owning party | Platform Engineering Office |
| Facility | Facility | An operational unit under the cluster with a governed type and an administrative level | Platform Engineering Office |
| Facility Type | Facility Type | A governed classification of a facility, set by the Super Admin | Platform Engineering Office |
| Organization Unit | Organization Unit | Any administrative division within the cluster or facility, built as a multi-level tree | Platform Engineering Office |
| Unit Type | Unit Type | Cluster, facility, sector, department, section, unit, or committee | Platform Engineering Office |
| Sector | Sector | An intermediate organizational level between facility and department | Platform Engineering Office |
| Department | Department | An executive organizational level within a facility or the cluster | Platform Engineering Office |
| Section | Section | A sub-level of organization within a department | Platform Engineering Office |
| Unit | Unit | The lowest operational organizational level | Platform Engineering Office |
| Position | Position | A description of duties and authorities within a unit, which can be held by more than one person | Platform Engineering Office |
| Primary Assignment | Primary Assignment | A formal binding between a person and a position within a unit for a specified period | Platform Engineering Office |
| Temporary Assignment | Temporary Assignment | A time-bound assignment that grants an additional scope without changing the original binding | Platform Engineering Office |
| Membership | Membership | A person's belonging to a committee, team, or council with a defined authority | Platform Engineering Office |
| Supervisory Relationship | Supervisory Relationship | A link between two units or two persons with a type, capabilities, and a time range | Platform Engineering Office |
| Relationship Type | Relationship Type | Direct, functional, coordination, or view-only | Platform Engineering Office |
| Granted Capability | Granted Capability | An access capability that the relationship grants to a set of modules | Platform Engineering Office |

## 4. People and Account Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Person | Person | The legal and organizational entity, separate from the login account | Organization module |
| User Account | User Account | The user's login account on the platform | Identity module |
| Username | Username | A fixed login identifier that does not change | Identity module |
| Password | Password | A local verification secret guarded by the user | Identity module |
| Session | Session | An active login period bound to the device and browser | Identity module |
| Forced Password Change | Forced Password Change | A flag that forces the user to change the password before continuing | Identity module |
| Account Lockout | Account Lockout | Blocking login after a number of failed attempts | Identity module |
| Account Disable | Account Disable | Stopping login while terminating sessions and revoking authorizations | Identity module |
| Account Recovery | Account Recovery | A governed procedure that re-enables login without exposing the previous password | Identity module |
| Break Glass Account | Break Glass Account | A reserved account activated by a documented procedure for emergency cases | Identity module |
| Super Admin | Super Admin | A role that owns the management of structures, authorizations, and administrative content of the platform | Platform Engineering Office |
| Employee | Employee | A user for whom an active assignment exists within a unit | Platform Engineering Office |
| Direct Manager | Direct Manager | The person holding the highest-ranking assignment in the employee's administrative chain at the time of the request | Platform Engineering Office |

## 5. Authorization and Isolation Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Role | Role | A set of capabilities granted to a user with an optional scope | Authorization module |
| Capability | Capability | A permitted action on a module, work type, or field | Authorization module |
| Role Assignment | Role Assignment | Binding of an account to a role with a scope and a time window | Authorization module |
| Delegation | Delegation | Transfer of a specific capability from one user to another for a defined duration and defined modules | Authorization module |
| User Scope | User Scope | The set of units within which the user is allowed to operate at a given moment | Authorization module |
| Record Scope | Record Scope | The units permitted to view a given work record | Owning work module |
| Record Classification | Record Classification | A canonical value from `public`, `internal`, `confidential`, `top_secret` | Authorization module |
| Access Decision | Access Decision | The result of the central check that determines whether an action is allowed | Authorization module |
| Access Decision Explanation | Access Decision Explanation | Human-readable text that explains why an action was granted or denied | Authorization module |
| Organizational Isolation | Organizational Isolation | Preventing a user from viewing records outside their scope without sharing or a relationship | Platform Engineering Office |
| Field Policy | Field Policy | The state of a field: hidden, read-only, or editable | `Authorization` |
| Field Access Template | Field Access Template | A reusable set of field rules | `Authorization` |

## 6. Dynamic Work Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Work Type | Work Type | A general administrative definition for types of administrative records; it can be created from the UI | Work Definitions module |
| Work Type Draft | Work Type Draft | A definition that can be edited before publishing | Work Definitions module |
| Work Type Version | Work Type Version | A published, immutable copy used to create records | Work Definitions module |
| Field Definition | Field Definition | A description of a field with its type, validation, and access policy | Work Definitions module |
| Form Layout | Form Layout | The arrangement of fields in the create and view form | Work Definitions module |
| Relation Definition | Relation Definition | A permitted link between a work type and another entity | Work Definitions module |
| Work Record | Work Record | A live work record bound to a published version of a work type | `WorkRecords` module |
| Internal Request | Internal Request | A work type published under the code `request`; every actual request is a `WorkRecord` instance, not an independent module | `WorkDefinitions` for the definition and `WorkRecords` for execution |
| Work Payload | Work Payload | The dynamic values bound to the definition version | `WorkRecords` module |
| Record Relation | Record Relation | A defined relation between a `WorkRecord` and a destination or another record; owned by `WorkRecords` | `WorkRecords` module |
| Record Participant | Record Participant | A user or unit with a defined participation role and an optional duration on a `WorkRecord` | `WorkRecords` module |
| Record Activity | Record Activity | A user-meaningful event that records a change on a `WorkRecord` | `WorkRecords` module |
| Locked Version Id | Locked Version Id | The identifier of the work-type version or path pinned on the record | `WorkRecords` module |
| Optimistic Lock | Optimistic Lock | A version number used to prevent conflicting concurrent writes | `WorkRecords` module |
| Legal Hold | Legal Hold | Prevention of destruction or modification of a record for legal or administrative reasons | `RecordsGovernance` |
| Retention Period | Retention Period | The duration a record remains before permitted destruction | `RecordsGovernance` |
| Signed Definition Package | Signed Definition Package | A digitally signed definitions file transferred between environments | Work Definitions module |

## 7. Workflow and Process Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Workflow | Workflow | A configurable sequence of steps for completing a process | Workflows module |
| Workflow Version | Workflow Version | A published, immutable copy that runs the transactions | Workflows module |
| Workflow Step | Workflow Step | An execution unit within the workflow | Workflows module |
| Workflow Decision | Workflow Decision | An approval, rejection, or a return for revision on a step | Workflows module |
| Approver | Approver | A person or role responsible for a step decision | Workflows module |
| Quorum | Quorum | The minimum number of approvals required to approve a step | Workflows module |
| Vote | Vote | A mechanism for recording an opinion within a collective-approval step | Workflows module |
| Escalation | Escalation | Transfer of a decision to a higher level when there is no response | Workflows module |
| Merge | Merge | Combining parallel flows into a later step | Workflows module |
| Branch | Branch | A conditional transition between workflow steps | Workflows module |
| Approver Resolution | Approver Resolution | Identifying the person or role responsible at the time a step is activated | Workflows module and Organization |
| Active Workflow Instance | Active Workflow Instance | A workflow that has started but has not yet been completed or cancelled | Workflows module |
| Fallback Approver | Fallback Approver | A person who replaces the original approver when the position is vacant | Workflows module |

## 8. Tasks, Documents, and Notifications Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Task | Task | An independent unit of work for one responsible party with participants | Tasks service |
| Task Owner | Task Owner | The person assigned to execute the task | Tasks service |
| Task Participant | Task Participant | A member of the task with viewing and interaction rights but no authority to change the state | Tasks service |
| Comment | Comment | Text or an attachment added to a record or task | Tasks service |
| Mention | Mention | A reference to a person in a comment that makes them a participant | Tasks service |
| Pending Acceptance | Pending Acceptance | The state of a completed task awaiting closure approval from the party who holds it | Tasks service |
| Document | Document | A logical file with metadata and classification | Documents service |
| Document Version | Document Version | A specific version of a document with a `checksum` value | Documents service |
| Document Link | Document Link | Linking a document to one or more records with an independent authority | Documents service |
| Quarantine | Quarantine | Isolating a file for inspection before making it available | Documents service |
| Document Scan | Document Scan | An internal scan of the file before making it available to users | Documents service |
| Notification | Notification | An in-platform message that is alert- or action-oriented | Notifications service |
| Notification Bundling | Notification Bundling | Merging similar notifications into a single message | Notifications service |
| Retry | Retry | Re-executing a worker after an initial failure according to a policy | Notifications service |

## 9. Search, Reporting, and Audit Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Index | Index | A derived structure that accelerates search; not a source of truth | Search service |
| Index Entry | Index Entry | A record in the index pointing to a work record after authorization has been verified | Search service |
| Report | Report | A governed query definition that reads from a Read Model | Reports service |
| Dashboard | Dashboard | A visual composition of metrics and links by role | Reports service |
| Read Model | Read Model | A governed projection of business data that serves queries | Reports service |
| Report Run | Report Run | A single execution of a report definition with a state and a result | Reports service |
| Audit Event | Audit Event | An immutable record of a sensitive action or access | Audit service |
| Hash Chain | Hash Chain | Linking event hashes to ensure they have not been altered | Audit service |
| Sensitive Access Event | Sensitive Access Event | Recording a read or download of confidential content | Audit service |
| Export Batch | Export Batch | A signed package of audit events for a specific period | Audit service |

## 10. Strategy, Portfolios, and Projects Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Strategic Plan | Strategic Plan | A top-level document that defines axes and objectives for a specified period | Strategy module |
| Strategic Axis | Strategic Axis | A main axis that groups objectives of similar nature | Strategy module |
| Strategic Objective | Strategic Objective | A phased outcome linked to an axis | Strategy module |
| Strategic Initiative | Strategic Initiative | A strategic effort that does not fall within the portfolio sequence | Strategy module |
| Indicator | Indicator | A measure linked to an objective or a process | Strategy module |
| Indicator Owner | Indicator Owner | A role responsible for distributing and approving readings | Strategy module |
| Indicator Coordinator | Indicator Coordinator | A role that enters readings and evidence for a facility | Strategy module |
| Target | Target | A target value for an indicator within a specified period | Strategy module |
| Indicator Measurement | Indicator Measurement | An actual value entered with evidence for a measurement period | Strategy module |
| Aggregation Formula | Aggregation Formula | A mathematical rule for aggregating facility readings | Strategy module |
| Baseline | Baseline | The indicator value before improvement begins | Strategy module |
| Portfolio | Portfolio | A group of programs under a single governance umbrella | Projects module |
| Program | Program | A group of projects linked to a strategic objective | Projects module |
| Project | Project | An effort with a defined scope, schedule, and budget to achieve an outcome | Projects module |
| Improvement Project | Improvement Project | A project that follows a PDSA, DMAIC, or FOCUS-PDCA template | Projects module |
| Project Template | Project Template | A reusable definition of phases, milestones, and weights | Projects module |
| Project Phase | Project Phase | A major division within the project plan | Projects module |
| Project Milestone | Project Milestone | A major achievement point with evidence | Projects module |
| Project Health | Project Health | An indicator computed from delay, deviation, and impact | Projects module |
| Administrative Budget | Administrative Budget | Approved, planned, spent, and forecasted figures for a project | Projects module |
| Project Impact | Project Impact | An actual certified contribution to an indicator's value | Projects module |
| Criticality Class | Criticality Class | A grade that determines review and escalation priority | Projects module |

## 11. Enterprise Risk Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Risk Register | Risk Register | A container of risks for a specific entity or unit | Risk module |
| Risk | Risk | A potential event with a likelihood and an impact that can hinder an objective | Risk module |
| Likelihood | Likelihood | The probability of the risk materializing within a specified period | Risk module |
| Impact | Impact | The severity of the consequences when the risk materializes | Risk module |
| Inherent Level | Inherent Level | The likelihood and impact value before controls are applied | Risk module |
| Residual Level | Residual Level | The likelihood and impact value after controls are applied | Risk module |
| Control | Control | An action that reduces likelihood or impact | Risk module |
| Treatment Plan | Treatment Plan | A set of tasks and activities to treat the risk | Risk module |
| Risk Acceptance | Risk Acceptance | A formal decision to bear the risk after treatment | Risk module |
| Key Risk Indicators | Key Risk Indicators | Indicators whose definitions and measurements are owned by `Strategy`; `Risk` links them to risks and owns their thresholds and alerts | `Strategy` for definition and measurement; `Risk` for linking, thresholds, and alerts |
| Risk Appetite | Risk Appetite | The acceptable risk limit at the entity or cluster level | Risk module |

## 12. Architecture and Operations Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Architecture Style | Architecture Style | An organizational choice that defines the application's shape and boundaries | Platform Engineering Office |
| Modular Monolith | Modular Monolith | A single application with clear module boundaries that can be separated | Platform Engineering Office |
| Vertical Slice | Vertical Slice | An execution unit for a complete use case | Platform Engineering Office |
| Module Boundary | Module Boundary | An explicit contract between modules that protects data ownership | Platform Engineering Office |
| Contract | Contract | A declared interface between two modules | Platform Engineering Office |
| Domain Event | Domain Event | A fact that occurred in a module and is announced to others | Platform Engineering Office |
| Outbox | Outbox | A table that stores events within the same change transaction | Platform Engineering Office |
| Event Id | Event Id | A unique identifier for an event that prevents duplicate processing | Platform Engineering Office |
| Air-Gapped Mode | Air-Gapped Mode | Operation without external Internet connectivity | Platform Engineering Office |
| Backup | Backup | A restorable data snapshot taken according to a policy | Operations Office |
| Recovery Point Objective | Recovery Point Objective | The maximum acceptable period of data loss | Operations Office |
| Recovery Time Objective | Recovery Time Objective | The maximum acceptable duration to restore service | Operations Office |
| Signed Log | Signed Log | A digitally signed log that is tamper-resistant | Information Security Office |
| SBOM Scan | SBOM Scan | An inventory of the software components installed in a release | Information Security Office |

## 13. Release and Platform Terms

| Arabic Term | English Term | Definition | Owner |
|---|---|---|---|
| Platform Release | Platform Release | A set of modules and capabilities released together against acceptance criteria | Product Lead |
| Environment | Environment | An independent runtime scope for development, testing, or production | Operations Lead |
| Test Environment | Test Environment | An environment used to test work-type drafts and workflows | Operations Lead |
| Pilot Environment | Pilot Environment | A user-limited environment for production-like experimentation | Operations Lead |
| Production | Production | The environment where actual end users operate | Operations Lead |
| Exit Gate | Exit Gate | Acceptance criteria required to approve a release or a phase | Product Lead |
| User Acceptance | User Acceptance | The user's verification that requirements are met before general rollout | Product Lead |

## 14. Cross-References

- The definitions of organizational terms are expanded in `docs/domain/organization-and-people.md`.
- The definitions of data entities are expanded in `docs/data-security/logical-data-model.md`.
- Architecture decisions are defined in `docs/adr/` and are referenced here when a decision term appears.
- The roles of executive persons are defined in `docs/governance/raci.md`.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.2.0 | 2026-07-18 | Platform Engineering Office | Pin ownership of Person in Organization per ADR-024 |
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Initial creation unifying organizational, security, architecture, and commercial terms |
| 1.1.0 | 2026-07-15 | Platform Engineering Office | Unify classifications and internal-request terms under `WorkRecords` and update references |
