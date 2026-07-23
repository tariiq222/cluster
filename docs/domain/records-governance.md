---
doc_id: DOM-RGV-001
title: Records governance, retention, and legal hold
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: RecordsGovernance module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: confidential
review_cycle: on every change
sources:
- docs/adr/016-audit-and-records-governance.md
- docs/architecture/dependency-rules.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/retention-and-legal-hold.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

# Records governance, retention, and legal hold

## Purpose and scope

`RecordsGovernance` owns retention policies, governed-record subjects keyed by `record_ref`, legal holds, and disposition eligibility with its decision and evidence. It does not own record payloads or files, and it does not delete inside the source module; the source owner performs the disposal inside its own transaction and confirms the outcome.

## Entities, tables, and constraints

| Table | Reality | Constraints |
|---|---|---|
| `retention_policy_versions`, `retention_rules` | A versioned retention policy and its rules | The published version is immutable; only one effective rule matches a given record |
| `governed_records` | A `record_ref`, its policy, the due date, and the current status | Unique `(record_type, record_id)` |
| `record_holds`, `record_hold_targets` | A legal hold, its scope, reason, and duration | An active hold blocks disposal; unique per target and per hold |
| `disposition_reviews`, `disposition_evidence` | Disposition eligibility with its decision and evidence | No approval without eligibility and an inactive hold |

## Commands, queries, events, and states

**Commands:** `PublishRetentionPolicyVersion`, `RegisterGovernedRecord`, `PlaceRecordHold`, `ReleaseRecordHold`, `DecideDispositionEligibility`, `ConfirmDispositionOutcome`.
**Queries:** `ResolveRetentionPolicy`, `GetRecordGovernanceStatus`, `GetActiveRecordHolds`, `GetDispositionEligibility`.
**Events:** `RecordHoldPlaced`, `RecordHoldReleased`, `RecordDispositionDue`, `RecordDispositionApproved`, `RecordDispositionCompleted`.

```text
GovernedRecord: Active -> Due -> UnderReview -> Disposed | Retained
Hold: Active -> Released | Expired | Superseded
DispositionReview: Pending -> Eligible -> Approved -> Completed | Rejected
```

## Constants, security, and failure modes

- An active hold suspends disposal and cannot be bypassed by the record owner or any admin.
- Governance records the decision only; the record source re-checks the hold, performs the disposal, and confirms the outcome.
- Every access and disposal decision is subject to Authorization and Audit; no administrative acceptance replaces the required workflow or duty-of-separation rules.
- An inability to read a hold, policy, or source confirmation equals a safe refusal of disposal. An outbox failure rolls back the governance decision; a source-side disposal failure does not close the review.

## Tests and dependencies

- An active hold blocks eligibility and disposal; releasing it re-triggers evaluation but never causes automatic disposal.
- A source that does not confirm the outcome leaves the disposition incomplete, and replayed events are idempotent.
- Boundary: does not read payloads and never deletes inside WorkRecords or Documents; tests cover the fail-closed contract for the source module.

Depends on PlatformSettings, Authorization, and Audit; delivers decisions to WorkRecords, Documents, and the remaining record owners through contracts only.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | RecordsGovernance module owner | Initial accepted specification |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; module status banner added to mark the spec as planned-only until the module lands |