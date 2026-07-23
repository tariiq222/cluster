---
doc_id: PRD-PJ-001
title: Personas and User Journeys
type: product
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Product Owner
reviewers:
- Platform Sponsor
- Platform Engineering Office
- Software Engineering Lead
classification: internal
review_cycle: Quarterly
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/raci.md
- docs/governance/traceability-matrix.md
- docs/product/vision-and-scope.md
- docs/product/releases-and-roadmap.md
- docs/product/success-metrics.md
---
# Personas and User Journeys

## 1. Purpose

This document defines the main personas that use the platform across the three releases and the essential user journeys for each persona, together with their measurement criteria. It prevents divergence between the product, engineering, and test teams on who we serve and how we measure service success. It refers to the authorization decisions in `ADR-004` and to the requirements traceability document for measurement details.

## 2. Rules for Defining Personas

- A persona represents a stable operational role, not a temporary administrative position.
- Each persona is linked to the default path capabilities, and exceptions are stated explicitly.
- The value of a persona is estimated from its contribution to a defined enterprise objective.
- The languages, time zone, and supported technologies for each persona are listed.
- A persona is not added without a measurable journey.

## 3. Primary Personas

### 3.1 Employee

| Item | Description |
|---|---|
| Role | An employee within a Cluster or facility department |
| Scope | Their own department and shared items |
| Main goal | Complete their tasks and requests without losing status |
| Current pain | Follow-up scattered across email, Excel, and paper |
| Devices | Main desktop with a modern browser, and a tablet when mobile |
| Language | Arabic by default with basic English comprehension |
| Expected usage share | 80% of total users |

Core capabilities:

- View assigned tasks and queue.
- Create, submit, and follow up on a request of a published type.
- Comment, mention, and add attachments.
- Search within scope and export what they are entitled to.
- Update their profile and interface language.

### 3.2 Direct Manager

| Item | Description |
|---|---|
| Role | Department manager or facility manager |
| Scope | Their own department and direct reports |
| Main goal | Make timely decisions and follow up on compliance |
| Current pain | Approvals lost between channels |
| Devices | Desktop and internal phone |
| Language | Arabic with English comprehension |

Core capabilities, in addition to the Employee capabilities:

- An inbox of approval items with quick-decision buttons.
- View of team tasks and their statuses.
- Approve, reject, or return requests according to the workflow.
- Assign tasks to their direct reports.
- A management dashboard with concise indicators.

### 3.3 Cluster Officer

| Item | Description |
|---|---|
| Role | A central administrator in the Cluster |
| Scope | Their own department internally, and facilities according to the supervisory relationship |
| Main goal | Follow up the Cluster and authorized facilities |
| Current pain | Lack of a unified view that respects isolation |
| Devices | Desktop |
| Language | Arabic and English |

Core capabilities, in addition to the Manager capabilities:

- A scope selector from the Cluster to the department.
- View of aggregated or detailed indicators according to the granted capability.
- Convert an indicator into an improvement project or task.
- A Cluster Officer dashboard with critical indicators.

### 3.4 Super Admin

| Item | Description |
|---|---|
| Role | A platform administrator who manages structures, authorization, and administrative content |
| Scope | All scopes in the administrative interface |
| Main goal | Keep the platform operational and governed |
| Current pain | Manual administration across multiple channels |
| Devices | Desktop |
| Language | Arabic and English |

Core capabilities, in addition to the Officer capabilities:

- A Platform Administration section inside the unified interface.
- Manage structure, units, facilities, and relationships.
- Create and modify work types and workflows.
- Manage accounts and capabilities.
- Approve imports, allocations, and definition releases.
- Review the audit log and security incidents.

### 3.5 Indicator Coordinator `[planned-R2]`

| Item | Description |
|---|---|
| Role | A person who enters indicator readings for a facility |
| Scope | The assigned facility or departments |
| Main goal | Enter accurate readings on time with evidence |
| Current pain | Scattered Excel sheets with no shared evidence |
| Devices | Desktop |
| Language | Arabic |

Core capabilities, in addition to the Employee capabilities:

- Enter an indicator reading with numerator, denominator, and evidence.
- Review previous readings and provide preliminary approval.
- View the facility's allocated target.
- Receive a reminder before the measurement deadline.

### 3.6 Indicator Owner `[planned-R2]`

| Item | Description |
|---|---|
| Role | A Cluster officer who approves readings and distributes targets |
| Scope | The indicator and the readings of facilities included in it |
| Main goal | Ensure measurement accuracy and target achievement |
| Current pain | Lack of a unified mathematical verification mechanism |
| Devices | Desktop |
| Language | Arabic |

Core capabilities, in addition to the Manager capabilities:

- Distribute the target across facilities with sum validation.
- Review and approve or return readings.
- Manage the aggregation equation and measurement cadence.
- Link projects to readings and approve their actual contribution.

### 3.7 Project Manager `[planned-R2]`

| Item | Description |
|---|---|
| Role | A portfolio, program, or project manager |
| Scope | The project, its members, and its phases |
| Main goal | Deliver the project to plan, health, and budget |
| Current pain | Lack of a unified view of health and impact |
| Devices | Desktop and internal phone |
| Language | Arabic |

Core capabilities, in addition to the Manager capabilities:

- Manage project phases, milestones, and weights.
- Manage an approved administrative budget with planned, actual, and forecast amounts.
- Manage team members and their roles.
- Convert a milestone into a linked task.
- Calculate health and completion and show variance.

### 3.8 Team Member `[planned-R2]`

| Item | Description |
|---|---|
| Role | A member of a project, committee, or team |
| Scope | The assigned project or committee only |
| Main goal | Deliver assigned tasks within their role |
| Current pain | Unclear role and deliverables |
| Devices | Desktop and tablet |
| Language | Arabic |

Core capabilities, in addition to the Employee capabilities:

- View the project plan within their role.
- Execute assigned tasks within the project.
- Report risks and blockers to the project lead.

### 3.9 Risk Owner `[planned-R3]`

| Item | Description |
|---|---|
| Role | A risk officer within a department, facility, or Cluster |
| Scope | The risk registers assigned to them |
| Main goal | Manage risks, controls, and treatment plans |
| Current pain | Lack of a unified register and warning indicators |
| Devices | Desktop |
| Language | Arabic |

Core capabilities, in addition to the Manager capabilities:

- Create and update risk registers per the matrix.
- Manage controls and treatment plans through the tasks service.
- Approve acceptance, avoidance, or transfer of a risk.
- Link risks to objectives, indicators, and projects.
- Alert when the risk appetite is exceeded.

## 4. Essential User Journeys

The measurements below depend on the release in which the journey appears. A journey that spans multiple releases is listed with the release where it begins.

### 4.1 Employee Journey: Create and Submit a Request

| Item | Description |
|---|---|
| Release | R1 |
| Persona | Employee |
| Path | Create a request of a published type → fill dynamic fields → attach a document → submit |
| Expected outcome | The request appears in the recipient's inbox and the sender is notified |
| Success criterion | ≥ 90% of users succeed unaided in a field test |
| Target time | P95 to complete submission ≤ 90 seconds |

### 4.2 Manager Journey: Approve or Return a Request

| Item | Description |
|---|---|
| Release | R1 |
| Persona | Direct Manager |
| Path | Open the inbox → review the request → make a decision → provide a reason when returning |
| Expected outcome | Status changes, the sender is notified, and the action is logged |
| Success criterion | P95 approval time ≤ 30 seconds inside the interface |
| Target time | Cycle time from receipt to decision ≤ 24 business hours |

### 4.3 Super Admin Journey: Create a Work Type and Workflow

| Item | Description |
|---|---|
| Release | R1 |
| Persona | Super Admin |
| Path | Create a draft → add fields → design a form → link a workflow → validate → sign → publish |
| Expected outcome | A published work type becomes usable and the new records begin |
| Success criterion | Publish a work type in less than 60 minutes from opening the draft |
| Verification criterion | Field test with a representative of a beneficiary department |

### 4.4 Employee Journey: Handle a Task

| Item | Description |
|---|---|
| Release | R1 |
| Persona | Employee |
| Path | Open the task → start work → add a comment or attachment → submit for approval or close directly |
| Expected outcome | Status and activity log are updated |
| Success criterion | No edits are lost after a worker failure |

### 4.5 Employee Journey: Search Within Scope

| Item | Description |
|---|---|
| Release | R1 |
| Persona | Employee |
| Path | Enter a keyword → apply scope filters → review results → open a record |
| Expected outcome | Only the permitted records are shown |
| Success criterion | 0 occurrences of a forbidden record's title in a security test |

### 4.6 Indicator Coordinator Journey: Enter a Reading `[planned-R2]`

| Item | Description |
|---|---|
| Release | R2 |
| Persona | Indicator Coordinator |
| Path | Open the measurement period → enter numerator and denominator → attach evidence → submit for approval |
| Expected outcome | The reading is recorded and the Indicator Owner is notified |
| Success criterion | Enter the reading in less than 5 minutes |

### 4.7 Indicator Owner Journey: Distribute a Target `[planned-R2]`

| Item | Description |
|---|---|
| Release | R2 |
| Persona | Indicator Owner |
| Path | Define the Cluster target → distribute across facilities → validate the sum → approve |
| Expected outcome | The distribution is saved and the next-period measurement is activated |
| Success criterion | Approval is blocked if the Cluster sum is not satisfied |

### 4.8 Project Manager Journey: Create a Project with an Improvement Template `[planned-R2]`

| Item | Description |
|---|---|
| Release | R2 |
| Persona | Project Manager |
| Path | Choose a PDSA template → fill the phases → link milestones to tasks → link an indicator → approve the baseline |
| Expected outcome | A published project with health and completion indicators |
| Success criterion | Complete the baseline in less than 30 minutes |

### 4.9 Indicator Owner Journey: Approve a Project's Impact `[planned-R2]`

| Item | Description |
|---|---|
| Release | R2 |
| Persona | Indicator Owner |
| Path | Open an approved reading → review the project contributions → adjust the actual contribution → approve |
| Expected outcome | The actual impact is recorded and linked to the project |
| Success criterion | The sum of attributed impact ≤ the observed improvement |

### 4.10 Risk Owner Journey: Assess a Risk `[planned-R3]`

| Item | Description |
|---|---|
| Release | R3 |
| Persona | Risk Owner |
| Path | Create a record → set likelihood and impact → register the controls → calculate residual level → create a treatment plan |
| Expected outcome | An active risk with a calculated level and treatment tasks |
| Success criterion | The level is calculated per an approved matrix |

### 4.11 Super Admin Journey: Escalate a Critical Risk `[planned-R3]`

| Item | Description |
|---|---|
| Release | R3 |
| Persona | Super Admin |
| Path | Review a critical-risk alert → review the record → decide to escalate or accept |
| Expected outcome | The decision is documented and senior levels are notified |
| Success criterion | Escalation time from alert to decision ≤ 24 business hours |

## 5. Personas × Capabilities Matrix

| Capability | Employee | Manager | Cluster Officer | Super Admin | Indicator Coordinator | Indicator Owner | Project Manager | Team Member | Risk Owner |
|---|---|---|---|---|---|---|---|---|---|
| Create a request | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Approve a request | – | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| Create a work type | – | – | – | ✓ | – | – | – | – | – |
| Create a project | – | – | – | ✓ | – | – | ✓ | – | – |
| Enter an indicator reading | – | – | – | – | ✓ | – | – | – | – |
| Approve an indicator reading | – | – | ✓ | ✓ | – | ✓ | – | – | – |
| Distribute a target | – | – | ✓ | ✓ | – | ✓ | – | – | – |
| Create a risk register | – | – | ✓ | ✓ | – | – | – | – | ✓ |
| Accept a risk | – | – | ✓ | ✓ | – | – | – | – | ✓ |
| Search and export | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Manage accounts | – | – | – | ✓ | – | – | – | – | – |

## 6. Persona Satisfaction Indicators

| Indicator | Target | Measurement | Persona |
|---|---|---|---|
| Time to create a first new request | ≤ 5 minutes | Field test | Employee |
| Percentage of requests approved on the first try | ≥ 80% | System log | Manager |
| Time to create a work type | ≤ 60 minutes | Field test | Super Admin |
| Time to enter an indicator reading | ≤ 5 minutes | Field test | Indicator Coordinator |
| Time to approve a project impact | ≤ 15 minutes | Field test | Indicator Owner |
| Time to assess a risk | ≤ 10 minutes | Field test | Risk Owner |

## 7. Additional Rules

- A persona is not granted capabilities outside their operational scope even by delegation, and delegation for sensitive actions is prohibited without an additional constraint.
- Each persona's experience is recorded in a field test before every release.
- Personas and journeys are updated whenever there is a material change in scope or in persona permissions.
- Any operational role may operate under the `Employee` persona at a minimum when logging in.

## 8. References

| Topic | Document |
|---|---|
| Authorization and isolation decisions | `docs/adr/004-authorization-and-isolation.md` |
| Data model and entity relationships | `docs/data-security/logical-data-model.md` |
| Requirements traceability matrix | `docs/governance/traceability-matrix.md` |
| Release details | `docs/product/releases-and-roadmap.md` |
| Detailed success metrics | `docs/product/success-metrics.md` |

## 9. Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Product Owner | Initial definition of nine personas and 11 essential journeys across the three releases |
