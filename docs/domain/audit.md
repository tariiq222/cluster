---
doc_id: DOM-AUD-001
title: Tamper-evident audit
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: Audit module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: confidential
review_cycle: on every change
sources:
- docs/adr/016-audit-and-records-governance.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/audit-and-privacy.md
---

> **Planned for R2/R3.** This module is documented but not yet implemented in the codebase.

The current sensitive-access persistence is owned by Authorization (`apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php:37-57`) and will be migrated to Audit when that module lands.

# Tamper-evident audit

## Purpose and scope

`Audit` owns the append-only security and operational record for sensitive actions, content reads, exports and downloads, and the correlation/causation/actor/principal links produced at authorization time. It does not own the user-facing activity feed inside any source module, and it does not emit access decisions.

## Entities, tables, and constraints

| Table | Reality | Constraints |
|---|---|---|
| `audit_events` | An immutable audit event with a redacted payload | Unique `event_id`; index on `(occurred_at, actor_user_id, action)` |
| `sensitive_access_events` | A sensitive read/download/export with the access decision it depended on | Idempotency-unique; no source content |
| `audit_hash_links` | A hash chain preserving integrity | A single predecessor per row and a connected sequence |
| `audit_checkpoints` | A periodic checkpoint and signature | Append-only and signed |

> **Drift correction:** The previous revision listed `sensitive_access_events` inside Audit without flagging ownership. The implemented sensitive-access persistence currently lives in Authorization (`CreateAuthorizationFieldAuditTables.php:37-57`); when Audit lands, the table moves to it.

## Commands, queries, events, and states

**Commands:** `AppendCriticalAuditEvent`, `RecordSensitiveAccess`, `VerifyAuditIntegrity`.
**Queries:** `QueryAuthorizedAuditTrail`, `GetAuditEvent`, `VerifyAuditCheckpoint`.
**Events:** `CriticalAuditEventAppended`, `SensitiveAccessRecorded`, `AuditIntegrityCheckFailed`.

```text
AuditEvent: Appended (terminal)
TemporaryExport: Generated -> Expired -> Disposed
```

## Constants, security, and failure modes

- No update, delete, or silent correction; a correction is a new event with explicit causation.
- No password, token, work-record payload, or file is recorded; only the identifiers, hashes, and fields needed for interpretation.
- Every audit query is re-authorized through Authorization; exports are field-reviewed and self-recorded.
- If a source policy requires recording the access before display and `RecordSensitiveAccess` fails, the source blocks the result. Non-critical consumer errors retry and never mutate the source.

## Tests and dependencies

- Block update/delete, verify the hash chain, and reject secret payloads.
- Record the actor and principal at authorization; authorize audit search and export.
- Idempotency and outbox; a failed critical audit blocks the operation that required it.

Depends on Authorization for read protection and on the technical outbox; it consumes events from every module and is not invoked synchronously by Authorization, to avoid cycles.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Audit module owner | Initial accepted specification |
| 1.1.0 | 2026-07-23 | Domain audit pass | Translated to English; module status banner added; `sensitive_access_events` ownership drift corrected to point at Authorization until Audit lands |