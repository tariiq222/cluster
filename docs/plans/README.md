---
doc_id: PLN-IDX-001
title: Execution Plans Index
type: plans
status: accepted
version: 4.2.0
date: 2026-07-22
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: when scope or execution order changes
sources: []
references: []
---
# Execution Plans

These folders serve system construction and are not an approval path. The single
source of status is `active-delivery-status.md`, and the source of execution
order is `implementation-roadmap.md`.

| Document | Current Use |
|---|---|
| [Implementation Roadmap](implementation-roadmap.md) | Closure dependencies from W1.3 to the Strategy/Portfolio cycle and R3 |
| [Active Delivery Status](active-delivery-status.md) | What has actually completed and what is being executed now |
| [Approvals and Requests](approvals-and-requests.md) | Stages for separating approval from task, building the engine, and the governance path |
| [R1](release-1-platform.md) | Rest of the general platform capabilities and their automated tests |
| [W1.3 Closure](release-1/w1-3-frontend-slices.md) | Cutover gate for the real Authorization engine and its impact on R1 modules before R2 |
| [R2](release-2-strategy-portfolio.md) | Full Strategy cycle then portfolios, projects, and impact linkage |
| [R3](release-3-risk.md) | Vertical slice for risks, controls, and treatment |
| [Operational Readiness](readiness-checklist.md) | Automated commands before actual deployment, no signatures |
| [W1.2 Completion Record](release-1/w1-2-frontend-slices.md) | Reference-only delivered contract, not upcoming work |
| [Deferred W1.1 Operations](w1-1-remaining-delivery-tasks.md) | Three automated operations executed at final deployment |

No separate plan is created for every screen or API. Detail is added to a test
or contract close to the code, and these documents are updated only when scope
or execution order changes.
