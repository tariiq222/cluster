---
doc_id: PRD-SM-001
title: Platform Success Metrics
type: product
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Product Owner
reviewers:
- Platform Sponsor
- Platform Engineering Office
- Software Engineering Lead
- Operations Lead
classification: internal
review_cycle: Quarterly
sources: []
references:
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/traceability-matrix.md
- docs/governance/raci.md
- docs/governance/assumptions-constraints.md
- docs/product/vision-and-scope.md
- docs/product/personas-and-journeys.md
- docs/product/releases-and-roadmap.md
---
# Platform Success Metrics

## 1. Purpose

This document defines the indicators used to measure the success of each release and the overall enterprise goal. It also defines measurement tools, the role responsible for each indicator, the baseline, and the target. It prevents success measurement by subjective or scattered criteria and makes the reports comparable across periods. It refers to the `FR/NFR/SEC/OPS` requirements in the requirements traceability matrix as the source of objective criteria.

## 2. Indicator Categories

| Category | Meaning | Examples |
|---|---|---|
| KPI | A key performance indicator that reflects value for the organization | Percentage of requests approved on the first try |
| KBI | A behavior indicator that reflects how the system is used | Number of follow-ups performed in comments instead of email |
| KQI | A technical quality indicator | P95 response time, test coverage percentage |
| KSI | A security indicator | Number of authorization incidents closed, RPO |
| KRI | An operational risk indicator | Percentage of backups that passed a recovery test |

## 3. Release One R1

### 3.1 Adoption Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KPI-R1-001 | Percentage of requests started inside the platform instead of by email | KPI | 0% | ≥ 80% | Requests in the platform ÷ reference administrative transactions | Product Owner |
| KPI-R1-002 | Weekly active user percentage | KPI | 0% | ≥ 70% | Unique active users ÷ provisioned users | Product Owner |
| KBI-R1-001 | Number of follow-ups performed in comments instead of email | KBI | 0 | ≥ 50% of follow-ups | Comments linked to a record ÷ total observed follow-ups | Product Owner |
| KBI-R1-002 | Time for an employee to learn the basic path | KBI | Not measured | ≤ 30 minutes | Field test with a sample | Product Owner |

### 3.2 Operational Performance Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KPI-R1-003 | P95 cycle time of the reference request | KPI | Not defined | ≤ 5 business days | From submission to closure | Product Owner |
| KPI-R1-004 | Percentage of late requests | KPI | 0% | ≤ 10% | Late requests ÷ total | Product Owner |
| KPI-R1-005 | Percentage of tasks with owner and due date | KPI | 0% | ≥ 95% | Tasks with complete fields ÷ total | Product Owner |
| KQI-R1-001 | P95 read response time | KQI | Not defined | ≤ 1.5 seconds | Performance test | Software Engineering Lead |
| KQI-R1-002 | P95 write response time | KQI | Not defined | ≤ 2 seconds | Performance test | Software Engineering Lead |
| KQI-R1-003 | New release deployment time | KQI | Not defined | ≤ 30 minutes | From approval to production | Operations Lead |
| KQI-R1-004 | Module-boundary test coverage | KQI | 0% | ≥ 90% | CI report | Software Engineering Lead |
| KQI-R1-005 | Number of published work types | KQI | 0 | ≥ 5 | WorkDefinitions log | Product Owner |
| KQI-R1-006 | Number of published workflows | KQI | 0 | ≥ 5 | Workflow log | Product Owner |

### 3.3 Security and Compliance Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KSI-R1-001 | Number of recorded data-leak incidents | KSI | 0 | 0 | Audit log | Information Security Lead |
| KSI-R1-002 | Authorization incidents closed within 14 days | KSI | 0 | 100% | Incident log | Information Security Lead |
| KSI-R1-003 | Accounts with an active password policy | KSI | 100% | 100% | Automated check | Information Security Lead |
| KSI-R1-004 | State and administration services not exposed to the public | KSI | 100% | 100% | Host port and Docker network check | Information Security Lead |
| KSI-R1-005 | Sensitive records containing an `audit_event` | KSI | 100% | 100% | Audit log | Information Security Lead |

### 3.4 Operations Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KSI-R1-006 | Effective RPO | KSI | Not defined | ≤ 15 minutes | Recovery test | Operations Lead |
| KQI-R1-007 | Effective RTO | KQI | Not defined | ≤ 2 hours | Recovery test | Operations Lead |
| KRI-R1-001 | Monthly service availability | KRI | 99% | ≥ 99.5% | Monitoring | Operations Lead |
| KRI-R1-002 | Backups that passed a recovery test | KRI | 0% | 100% monthly | Operations log | Operations Lead |
| KRI-R1-003 | Critical-incident detection time | KRI | Not defined | ≤ 5 minutes | Monitoring | Operations Lead |
| KRI-R1-004 | Critical-incident handling time MTTR | KRI | Not defined | ≤ 60 minutes | Incident log | Operations Lead |

## 4. Release Two R2

### 4.1 Strategy and Portfolio Adoption Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KPI-R2-001 `[planned-R2]` | Percentage of projects linked to a program and portfolio | KPI | 0% | ≥ 95% | Project log | Product Owner |
| KPI-R2-002 `[planned-R2]` | Percentage of projects with a baseline and correct weights | KPI | 0% | ≥ 90% | Project log | Product Owner |
| KPI-R2-003 `[planned-R2]` | Percentage of improvement projects linked to an indicator with expected impact | KPI | 0% | ≥ 80% | Project log | Product Owner |
| KPI-R2-004 `[planned-R2]` | Percentage of periodic readings entered on time | KPI | 0% | ≥ 90% | Reading log | Product Owner |
| KPI-R2-005 `[planned-R2]` | Percentage of readings approved on time | KPI | 0% | ≥ 95% | Reading log | Product Owner |

### 4.2 Measurement Quality Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KBI-R2-001 `[planned-R2]` | Difference between expected impact and approved actual impact | KBI | Not defined | ≤ 10% variance | Compare values | Product Owner |
| KQI-R2-001 `[planned-R2]` | Approved targets that passed the mathematical validation | KQI | 100% | 100% | Automated check | Software Engineering Lead |
| KQI-R2-002 `[planned-R2]` | Improvement projects with approved actual impact within the allowed ceiling | KQI | 0% | 100% | Automated check | Software Engineering Lead |
| KQI-R2-003 `[planned-R2]` | P95 indicator-dashboard rendering time | KQI | Not defined | ≤ 2 seconds | Performance test | Software Engineering Lead |
| KQI-R2-004 `[planned-R2]` | Dashboard rebuild time after the period | KQI | Not defined | ≤ 5 minutes | Behavioral test | Operations Lead |

### 4.3 Portfolio Health Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KPI-R2-006 `[planned-R2]` | Programs with an explained health | KPI | 0% | 100% | Manual review | Product Owner |
| KPI-R2-007 `[planned-R2]` | Critical projects whose rating was raised per the rule | KPI | 0% | 100% | Project log | Software Engineering Lead |
| KBI-R2-002 `[planned-R2]` | Accuracy and explainability of program and portfolio health dashboards | KBI | Not defined | ≥ 85% agreement | Field test | Product Owner |

## 5. Release Three R3

### 5.1 Risk Management Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KPI-R3-001 `[planned-R3]` | Risks registered with an owner and review date | KPI | 0% | ≥ 95% | Risk log | Product Owner |
| KPI-R3-002 `[planned-R3]` | Risks with controls and a calculated residual level | KPI | 0% | ≥ 90% | Risk log | Product Owner |
| KPI-R3-003 `[planned-R3]` | Critical risks with an active treatment plan | KPI | 0% | ≥ 90% | Risk log | Product Owner |
| KPI-R3-004 `[planned-R3]` | Acceptance decisions documented with reason and owner | KPI | 0% | 100% | Risk log | Information Security Lead |
| KBI-R3-001 `[planned-R3]` | Time to assess a risk for the first time | KBI | Not defined | ≤ 10 minutes | Field test | Product Owner |

### 5.2 KRI and Performance Indicators

| ID | Indicator | Category | Baseline | Target | Measurement | Owner |
|---|---|---|---|---|---|---|
| KQI-R3-001 `[planned-R3]` | P95 risk-assessment time | KQI | Not defined | ≤ 2 seconds | Performance test | Software Engineering Lead |
| KQI-R3-002 `[planned-R3]` | Risk-log capacity without degradation | KQI | Not defined | 5,000 active risks | Capacity test | Software Engineering Lead |
| KRI-R3-001 `[planned-R3]` | Alert time when risk appetite is exceeded | KRI | Not defined | ≤ 5 minutes | Behavioral test | Operations Lead |
| KRI-R3-002 `[planned-R3]` | KRIs running on a periodic basis | KRI | 0% | ≥ 90% | Risk log | Operations Lead |

## 6. General Enterprise Indicators

| ID | Indicator | Category | Long-term target | Measurement | Owner |
|---|---|---|---|---|---|
| KPI-ORG-001 | Reduction of administrative work outside the platform | KPI | ≥ 70% by the end of R3 | Field survey | Product Owner |
| KPI-ORG-002 | Improved clarity of responsibility and status | KPI | ≥ 95% by the end of R1 | Automated field check | Software Engineering Lead |
| KPI-ORG-003 | Reduction of approval time | KPI | ≤ 50% of the previous time | Time comparison | Product Owner |
| KPI-ORG-004 | Unification of measurement data | KPI | ≥ 90% of indicators in the platform | Indicator log | Product Owner |
| KPI-ORG-005 | Cluster ability to follow facilities without breaking isolation | KPI | ≥ 80% of facilities covered by a relationship | Relationship log | Product Owner |

## 7. Experience and Satisfaction Indicators

| ID | Indicator | Category | Measurement | Target | Owner |
|---|---|---|---|---|---|
| KPI-UX-001 | Users who complete a task unaided | KPI | Field test | ≥ 85% per release | Product Owner |
| KPI-UX-002 | User satisfaction after the field test | KPI | Standard survey | ≥ 85% per release | Product Owner |
| KPI-UX-003 | P95 critical-task completion time | KPI | Field test | Match the target in `PRD-PJ-001` | Product Owner |

## 8. Indicator Responsibility Matrix

| Category | Classification owner | Measurement owner | Decision owner |
|---|---|---|---|
| KPI | Product Owner | Software Engineering Lead | Platform Sponsor |
| KBI | Product Owner | Product Owner | Platform Sponsor |
| KQI | Software Engineering Lead | Software Engineering Lead | Platform Engineering Office |
| KSI | Information Security Lead | Information Security Lead | Platform Engineering Office |
| KRI | Operations Lead | Operations Lead | Platform Engineering Office |

## 9. Measurement Cycle and Reports

| Report | Content | Cadence | Owner |
|---|---|---|---|
| Weekly status report | Adoption and incident indicators | Weekly | Product Owner |
| Monthly performance report | KQI, KRI, and their trends | Monthly | Software Engineering Lead |
| Monthly security report | KSI and closed incidents | Monthly | Information Security Lead |
| Monthly operations report | RPO/RTO and backups | Monthly | Operations Lead |
| Field-test report | UX and journey indicators | Per field test | Product Owner |
| Exit-gate report | Exit-gate indicators | Per release | Product Owner |

## 10. Measurement Controls

- Indicators are calculated from real operational data; estimated values are only allowed in a documented field test.
- Comparisons between periods that do not use the same definition are prohibited, and the calculation method is attached to each report.
- Data sources are recorded in the indicator row and may not be changed without written notice.
- Changing the definition of an approved indicator requires a formal revision release.

## 11. Indicator to Requirement Links

| Indicator | Linked requirement |
|---|---|
| KPI-R1-003 request cycle time | NFR-R1-001, NFR-R1-002 |
| KQI-R1-005 published work types | FR-R1-005, FR-R1-006 |
| KSI-R1-001 leak incidents | SEC-R1-001, SEC-R1-005 |
| KSI-R1-006 RPO | OPS-R1-001, OPS-R1-005 |
| KQI-R2-003 dashboard time | NFR-R2-001 |
| KQI-R3-002 risk-log capacity | NFR-R3-002 |
| KRI-R3-001 risk-appetite alert | OPS-R3-001 |

## 12. Failure Conditions Requiring Escalation

| Condition | Action |
|---|---|
| Failure of a release's exit gate | Freeze the release and replan |
| A security incident classified as high | Escalate to the Platform Sponsor within 24 hours |
| An enterprise KPI drops by more than 10% in a month | Remediation plan within 14 days |
| A field test fails twice in a row | Freeze the feature and review the scope |

## 13. References

| Topic | Document |
|---|---|
| Requirements with objective measures | `docs/governance/traceability-matrix.md` |
| Release details and exit criteria | `docs/product/releases-and-roadmap.md` |
| User journeys and their criteria | `docs/product/personas-and-journeys.md` |
| Assumptions and constraints | `docs/governance/assumptions-constraints.md` |
| Governance roles | `docs/governance/raci.md` |

## 14. Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Product Owner | Initial definition of KPI/KBI/KQI/KSI/KRI indicators across the three releases, plus general enterprise indicators |
