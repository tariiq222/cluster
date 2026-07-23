---
doc_id: OPS-IR-001
title: Incident Response
type: operations
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Operations Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources:
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/architecture/overview.md
references:
- docs/data-security/threat-model.md
- docs/operations/observability-and-slos.md
- docs/operations/runbooks.md
---
# Incident Response

## Roles

| Role | Responsibility |
|---|---|
| Incident commander | Declares severity, coordinates decisions, records timeline and impact, and closes the incident |
| Operations lead | Technical diagnosis, containment, and recovery |
| Information security lead | Leads the security investigation, preserves evidence, and decides on security notification |
| Application lead | Analyzes the functional impact and verifies the fix |
| Communications lead | Updates approved stakeholders without disclosing sensitive data |

Roles are assigned in a governed on-call roster. This document does not
include individual names or actual contact details.

## Severity

| Level | Example | Response time |
|---|---|---|
| P1 | Widespread outage, potential data loss, active breach, or RTO breach | Immediate |
| P2 | Major degradation, sustained SLO breach, or severe incident with the service still up | Urgent |
| P3 | Limited impact or predictive alert | Within the support cycle |

## Response cycle

1. The incident commander opens a record: time, reporter, scope, release, and
   known impact.
2. P1/P2/P3 is assessed, the required roles are paged, and an initial update
   is sent to the approved internal channel.
3. The impact is contained with the smallest reversible change: isolate a
   workload, stop a promotion, suspend an account, or reroute traffic. When
   a security issue is suspected, evidence is preserved first.
4. Logs, metrics, traces, and audit records are collected. Copies are kept
   under chain of custody and never posted to public channels.
5. The cause is eradicated and the service is restored through a runbook or
   an approved change, then health, SLO, and critical functions are verified.
6. The incident commander declares recovery only after an appropriate
   independent check, and a post-incident review is opened within the agreed
   working period.

## Security and data incidents

- Suspected resources are not restarted or deleted before the information
  security lead confirms it, if doing so could destroy evidence.
- Break-glass is used only with a two-person procedure, for a limited
  duration, with a full audit trail and a later review.
- The information security lead determines regulatory and privacy
  notification requirements. This document assumes no specific notifying
  authority or legal deadline.

## Closing criteria

- A documented cause or hypothesis with impact, duration, and timeline.
- Confirmation that the impact is removed, or a temporary risk is accepted
  with an owner and a date.
- Links to evidence, changes, and the runbook used.
- Preventive actions that can be owned and measured, and a post-incident
  review for every P1 and P2.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Operations Lead | Initial incident-response framework |
