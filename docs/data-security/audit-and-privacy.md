---
doc_id: SEC-AU-001
title: Audit and Privacy
type: data-security
status: draft
version: 0.3.0
date: 2026-07-15
owner: Information Security Officer
reviewers:
- Platform Engineering Office
- Operations Officer
classification: internal
review_cycle: semi-annual
sources: []
references:
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/016-audit-and-records-governance.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/domain/audit.md
- docs/data-security/logical-data-model.md
- docs/data-security/threat-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/file-security.md
---

# Audit and Privacy

> **Planned module.** This document describes the central audit module, its append-only schema, the hash-chain procedures, the daily export, and the privacy workflows. **The audit module is planned, not implemented.** No `audit_events`, `audit_payloads`, `audit_hash_link`, `audit_export_batch`, `audit_merkle_roots`, `audit_writer`, `audit_reader`, or append-only-enforcing triggers exist in the verified migrations. The only audit-shaped persistence that is currently implemented lives in the Authorization module: `access_decisions` is written on every decision, and `sensitive_access_events` is written conditionally on confidential-or-above decisions (`apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php` and `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php`). Document access events are emitted as outbox events on the document aggregate (`document_outbox_events`, planned-table description in §3.3). The material below defines the target behavior of the planned audit module and must not be read as a description of the current runtime.

## 1. Purpose and Scope

This document defines the audit policy in the administrative platform of the Third Health Cluster, covering:

- The central audit log structure (planned).
- The append-only model at the database level with role separation (planned).
- The hash chain that links events and detects tampering (planned).
- The daily, immutable export for compliance and long-term retention (planned).
- PDPL privacy handling for the employee data lifecycle.
- NDMO mapping for the metadata lifecycle.

The platform is non-clinical. It processes employee PII only and does not receive or send data outside the cluster data center. Therefore this document focuses on what happens inside the platform, the internal audit channels, and the tamper-resistance guarantees.

## 2. Audit Principles (planned target)

- **True append-only at the database level.** No UPDATE or DELETE is permitted on the audit tables from any account, including DBAs. The database itself refuses modification at the storage-engine level.
- **Role separation.** The application's database account has no privileges on the audit tables. Writes go through a dedicated procedure and reads through a separate role.
- **Hash chain per event.** Each event carries the hash of the previous event; any modification of a prior event breaks the chain and the verification fails.
- **Immutable daily export.** A signed daily bundle is stored in a physically separate store.
- **Two complementary records.** A central security record for sensitive incidents, and a functional activity record that is intelligible to the user and shown inside the record itself.
- **Retention by classification.** Retention duration is tied to the record classification and the work-type policy, and is subject to controlled destruction rules.
- **No duplicate events.** Each event carries a unique `event_id`; consumers are idempotent.

## 3. Data Model

Every `UUID` in the tables below means RFC 9562 UUIDv7. The application generates identifiers before the transaction starts; the MySQL `UUID()` function is not used because it does not guarantee v7.

> **Planned schema.** All tables in §3.1 (`audit_events`, `audit_payloads`, `audit_hash_link`, `audit_export_batch`) are planned and do not exist in the verified migrations.

### 3.1 Planned Tables

#### 3.1.1 `audit_events` (planned)

| Field | Type | Constraint | Description |
|---|---|---|---|
| `event_id` | UUID | PK | Unique event id |
| `event_type` | string | required, indexed | Event type per central dictionary |
| `occurred_at` | timestamp(6) | required | Occurrence time with microsecond precision |
| `recorded_at` | timestamp(6) | required | Time written to the log |
| `actor_user_id` | UUID | optional | Human actor |
| `actor_session_id` | UUID | optional | Session |
| `actor_service_account` | string | optional | Machine actor |
| `actor_ip` | string | optional | Internal IP |
| `actor_user_agent` | string | optional | Browser or agent |
| `target_type` | string | required | Target entity type |
| `target_id` | string | required | Target entity id |
| `target_owner_org_unit_id` | UUID | optional | Owning org unit |
| `classification` | enum | required | Event classification: public, internal, confidential, top_secret |
| `outcome` | enum | required | success, denied, failure, error |
| `reason` | text | optional | Reason when needed |
| `module` | string | required | Producing module |
| `payload_hash` | string | required | Hash of separated payload |
| `payload_size` | int | required | Payload size in bytes |
| `prev_event_hash` | string | required | Hash of previous event in the chain |
| `event_hash` | string | required, unique | Hash of the current event |
| `chain_id` | string | required | Sub-chain id |
| `sequence_no` | bigint | required | Sequence number within the sub-chain |
| `export_batch_id` | UUID | optional, indexed | Link to export bundle |

#### 3.1.2 `audit_payloads` (planned)

| Field | Type | Constraint | Description |
|---|---|---|---|
| `event_id` | UUID | PK, FK | Link to event |
| `payload_encrypted` | blob | required | Encrypted payload |
| `payload_kms_key_id` | string | required | KMS key id |
| `retention_until` | timestamp | required | Retention expiry |

#### 3.1.3 `audit_hash_link` (planned)

| Field | Type | Constraint | Description |
|---|---|---|---|
| `chain_id` | string | PK | Sub-chain |
| `sequence_no` | bigint | PK | Sequence number |
| `event_id` | UUID | unique | Event |
| `prev_event_hash` | string | required | Previous hash |
| `event_hash` | string | required | Current hash |
| `signed_at` | timestamp(6) | required | Signature time |

#### 3.1.4 `audit_export_batch` (planned)

| Field | Type | Constraint | Description |
|---|---|---|---|
| `batch_id` | UUID | PK | Bundle id |
| `export_date` | date | unique | Export date |
| `started_at` | timestamp(6) | required | Export start |
| `completed_at` | timestamp(6) | optional | Completion time |
| `event_count` | bigint | required | Number of events |
| `payload_digest` | string | required | Aggregate hash of all events |
| `signature` | string | required | Bundle signature |
| `signature_key_id` | string | required | Signing key id |
| `storage_path` | string | required | Bundle path in the separate store |
| `status` | enum | required | pending, completed, failed, verified |
| `verified_at` | timestamp(6) | optional | External verification time |
| `verifier_user_id` | UUID | optional | Verifier |
| `failure_reason` | text | optional | Failure reason |

### 3.2 Implemented Audit-shaped Persistence

The following are the audit-related tables that **are** implemented today:

#### 3.2.1 `access_decisions` (implemented)

Written by `DatabasePersistAccessDecision::record()` for every authorization decision. Captures the actor, capability, classification, decision (`allow`/`deny`), reason code, and decision id. The write participates in the same transaction as the underlying mutation and returns failure on missing actor, missing decision id, or transaction error.

#### 3.2.2 `sensitive_access_events` (implemented)

Written by the same persistence path **conditionally** when the decision is on a confidential-or-above classification (`apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php`). This is the platform's current sensitive-audit record.

#### 3.2.3 Document access events (implemented as outbox)

The Documents module emits document lifecycle events through `document_outbox_events` (see `docs/data-security/file-security.md` §3.2.2). The current emission types include `document.uploaded`, `document.scan.passed`, `document.scan.failed`, `document.archived`, and `document.destroyed`. No dedicated `DocumentAccessEvent` table exists yet; the planned entity is documented in `file-security.md` §3.1.6.

### 3.3 Event Type Dictionary (planned)

Events are classified according to a central exported dictionary. Adding an event type requires super-admin approval and security review. Prefixes are used for grouping:

| Prefix | Category | Examples |
|---|---|---|
| `auth.*` | Identity and session events | `auth.login.success`, `auth.login.failed`, `auth.session.terminated.idle` |
| `recovery.*` | Recovery events | `recovery.request.opened`, `recovery.verified`, `recovery.completed` |
| `breakglass.*` | Emergency events | `breakglass.activated`, `breakglass.session.started`, `breakglass.session.ended` |
| `access.*` | Access decision | `access.granted`, `access.denied`, `access.sensitive.view` |
| `record.*` | Record events | `record.created`, `record.updated`, `record.deleted`, `record.classification.changed` |
| `workflow.*` | Workflow events | `workflow.step.activated`, `workflow.decision.recorded` |
| `task.*` | Task events | `task.assigned`, `task.completed`, `task.commented` |
| `document.*` | Document events | `document.uploaded`, `document.downloaded`, `document.linked`, `document.quarantined` |
| `export.*` | Export events | `export.report.run`, `export.audit.batch.created` |
| `admin.*` | Administration events | `admin.user.created`, `admin.role.assigned`, `admin.config.changed` |
| `system.*` | System events | `system.backup.completed`, `system.restore.started` |

## 4. Append-only Mechanism (planned)

### 4.1 Modification Block at the MySQL Engine

UPDATE and DELETE on `audit_events`, `audit_payloads`, and `audit_hash_link` are forbidden at the MySQL level via:

- The application database account `app_role` has only `INSERT` and `SELECT` (procedure-bound) on these tables.
- The audit account `audit_writer` has `INSERT` only.
- The audit-reader account `audit_reader` has `SELECT` only.
- The DBA account has no modification privileges except through a documented exception procedure, which is recorded in an external operational log.

#### 4.1.1 Applying Privileges in MySQL

```sql
REVOKE UPDATE, DELETE ON audit_db.audit_events FROM 'app_role'@'%';
REVOKE UPDATE, DELETE ON audit_db.audit_payloads FROM 'app_role'@'%';
REVOKE UPDATE, DELETE ON audit_db.audit_hash_link FROM 'app_role'@'%';

GRANT INSERT, SELECT ON audit_db.audit_events TO 'audit_writer'@'%';
GRANT INSERT, SELECT ON audit_db.audit_payloads TO 'audit_writer'@'%';
GRANT INSERT, SELECT ON audit_db.audit_hash_link TO 'audit_writer'@'%';

GRANT SELECT ON audit_db.audit_events TO 'audit_reader'@'%';
GRANT SELECT ON audit_db.audit_payloads TO 'audit_reader'@'%';
GRANT SELECT ON audit_db.audit_hash_link TO 'audit_reader'@'%';
```

#### 4.1.2 Defensive Triggers

```sql
DELIMITER //
CREATE TRIGGER audit_events_no_update
BEFORE UPDATE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'audit_events is append-only';
END//

CREATE TRIGGER audit_events_no_delete
BEFORE DELETE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'audit_events is append-only';
END//
DELIMITER ;
```

### 4.2 Writing via Dedicated Procedure

The application does not write directly to the tables. It calls a procedure that takes the parameters, computes the hash, and appends the event. This guarantees:

- Uniform `event_hash` generation.
- `prev_event_hash` validation.
- Atomic `sequence_no` computation.
- Hash loss prevention against programming error.

#### 4.2.1 Event Append Procedure

```sql
DELIMITER //
CREATE PROCEDURE audit_append_event(
    IN p_event_id BINARY(16),
    IN p_initial_chain_id VARCHAR(36),
    IN p_event_type VARCHAR(100),
    IN p_actor_user_id BINARY(16),
    IN p_actor_session_id BINARY(16),
    IN p_target_type VARCHAR(100),
    IN p_target_id VARCHAR(100),
    IN p_owner_org_unit_id BINARY(16),
    IN p_classification VARCHAR(20),
    IN p_outcome VARCHAR(20),
    IN p_module VARCHAR(50),
    IN p_payload VARBINARY(8192)
)
proc: BEGIN
    DECLARE v_prev_hash VARCHAR(64);
    DECLARE v_seq BIGINT;
    DECLARE v_chain_id VARCHAR(64);
    DECLARE v_recorded_at TIMESTAMP(6);
    DECLARE v_occurred_at TIMESTAMP(6);
    DECLARE v_event_hash VARCHAR(64);
    DECLARE v_payload_hash VARCHAR(64);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        RESIGNAL;
    END;

    START TRANSACTION;

    SET v_occurred_at = NOW(6);
    SET v_recorded_at = NOW(6);

    SELECT chain_id, sequence_no, event_hash
      INTO v_chain_id, v_seq, v_prev_hash
      FROM audit_hash_link
      ORDER BY sequence_no DESC
      LIMIT 1
      FOR UPDATE;

    IF v_seq IS NULL THEN
        SET v_seq = 1;
        SET v_prev_hash = REPEAT('0', 64);
        IF p_initial_chain_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'UUIDv7 chain id is required';
        END IF;
        SET v_chain_id = p_initial_chain_id;
    ELSE
        SET v_seq = v_seq + 1;
    END IF;

    SET v_payload_hash = SHA2(p_payload, 256);

    SET v_event_hash = SHA2(
        CONCAT_WS('|',
            LOWER(p_event_type),
            v_occurred_at,
            IFNULL(p_actor_user_id, ''),
            IFNULL(p_target_id, ''),
            v_payload_hash,
            v_prev_hash,
            v_seq
        ),
        256
    );

    INSERT INTO audit_events(
        event_id, event_type, occurred_at, recorded_at,
        actor_user_id, actor_session_id,
        target_type, target_id, target_owner_org_unit_id,
        classification, outcome, module,
        payload_hash, payload_size,
        prev_event_hash, event_hash, chain_id, sequence_no
    ) VALUES (
        p_event_id, p_event_type, v_occurred_at, v_recorded_at,
        p_actor_user_id, p_actor_session_id,
        p_target_type, p_target_id, p_owner_org_unit_id,
        p_classification, p_outcome, p_module,
        v_payload_hash, LENGTH(p_payload),
        v_prev_hash, v_event_hash, v_chain_id, v_seq
    );

    INSERT INTO audit_hash_link(
        chain_id, sequence_no, event_id, prev_event_hash, event_hash, signed_at
    ) VALUES (
        v_chain_id, v_seq, p_event_id, v_prev_hash, v_event_hash, v_recorded_at
    );

    INSERT INTO audit_payloads(
        event_id, payload_encrypted, payload_kms_key_id, retention_until
    ) VALUES (
        p_event_id, AES_ENCRYPT(p_payload, @audit_payload_key),
        @audit_kms_key_id,
        DATE_ADD(v_recorded_at, INTERVAL @retention_years YEAR)
    );

    COMMIT;
END proc//
DELIMITER ;
```

### 4.3 Tamper-resistance Test

The engine is expected to reject any of:

- `UPDATE` on `audit_events` failing at the trigger level.
- `DELETE` on `audit_events` failing at the trigger level.
- `TRUNCATE` rejected at the application account level.
- Even the DBA requires a documented exception and review.

### 4.4 Chain-loss Handling

When verification detects a hash-chain break:

- The system enters `audit_degraded` mode.
- An immediate alert is raised to super-admin and the security team.
- Sensitive actions stop being accepted until the chain is restored.
- Chain rebuild is not automatic; it requires documented manual intervention.
- Every rebuild attempt is recorded as a new event in the new chain.

## 5. Hash Chain and Tamper Detection (planned)

### 5.1 Chain Properties

- Every `chain_id` carries an increasing `sequence_no`.
- `event_hash` includes `prev_event_hash` as input.
- Any modification of a prior event changes `event_hash` and breaks all later events.
- Verification is linear forward, and a periodic scan starts from the last trusted prior signature.

### 5.2 External Signature Points

Every hour, the system signs the Merkle root of every sub-chain and writes:

- `merkle_root` into the `audit_merkle_roots` table with the signature.
- A duplicated copy in `audit_export_batch` in a separate store.

### 5.3 Verification

#### 5.3.1 Internal Verification

- A verifier running as a background job checks the last 10,000 events hourly.
- The check fails on any hash break.
- The last verified sequence is kept as a resume point.

#### 5.3.2 External Verification

- Daily, the external verifier uploads the signed `merkle_root` list and the latest `event_hash`.
- Re-validates the root hash.
- Stores the result in an electronically signed report.

### 5.4 Tests

- `AuditChainTest::event_hash_includes_prev_hash`
- `AuditChainTest::tampering_with_event_breaks_chain`
- `AuditChainTest::merkle_root_recomputes_correctly`
- `AuditChainTest::external_verifier_detects_modification`
- `AuditChainTest::replay_attack_blocked_by_sequence`

## 6. Daily Immutable Export (planned)

### 6.1 Schedule

- Scheduled daily at a fixed time (03:00 cluster local).
- Captures every event of the previous day up to 23:59:59.
- Runs explicitly in the `Asia/Riyadh` time zone.

### 6.2 Bundle Layout

```text
audit-export-YYYY-MM-DD/
├── manifest.json
├── events.parquet
├── events.sha256
├── payloads.enc
├── payloads.sha256
├── signature.sig
└── chain-roots.json
```

- `manifest.json` describes the bundle, event count, and digest values.
- `events.parquet` holds the core audit fields.
- `payloads.enc` holds encrypted payloads.
- `signature.sig` holds the ECDSA P-256 signature over `manifest.json`.
- `chain-roots.json` holds signed Merkle roots for every sub-chain.

### 6.3 Immutability Properties

- The bundle is written with a separate KMS key from production.
- Production users cannot read or modify the bundle.
- Transport uses a dedicated `audit_export_role` service account.
- Signature verification uses the public key stored in an internal HSM.
- Any verification failure stops reads and is recorded in a separate operational log.

### 6.4 Retention

- Bundles are kept for 7 years in the separate audit store.
- Physically separated from production (separate store, separate VLAN).
- Tape backup encrypted outside the region.

### 6.5 Failure Indicators

- Export failure within 30 minutes of schedule raises an alert.
- Verification failure raises a critical alert.
- Any modification attempt on the audit store raises a critical alert.

### 6.6 Tests

- `AuditExportTest::daily_export_contains_all_events_of_previous_day`
- `AuditExportTest::export_signature_verifies_with_public_key`
- `AuditExportTest::tampering_with_export_fails_signature`
- `AuditExportTest::export_storage_path_is_write_only_for_app_role`
- `AuditExportTest::failure_alerts_within_30_minutes`

## 7. PDPL Privacy Application

### 7.1 Processing Principles

| Principle | Application |
|---|---|
| Processing basis | Every work type carries `processing_basis` in its definition |
| Data minimization | Every dynamic field carries `purpose` and `retention_years` |
| Data accuracy | PII modification is permitted to the owner only; every modification is recorded |
| Retention | `retention_until` is computed from `retention_years` on the work type |
| Data subject rights | A controlled internal-requests workflow |
| Data security | PII encryption, role separation, access auditing |
| Breach notification | Detection and alerting within 24 hours |

### 7.2 Data Subject Rights

- **Right of access:** A request form within a controlled workflow generates a dedicated Read Model report of all subject data. The event is recorded.
- **Right of rectification:** A request form, with correction authority granted to the owner or a delegate the system authorizes. The event is recorded.
- **Right of erasure:** Available only outside the legal/professional frame. Records subject to statutory retention are not deleted. The rejection is documented with its basis.
- **Right of objection:** Forwarded to the competent authority for review.

### 7.3 Exceptions and Limits

- Employees cannot delete accounting or administrative records subject to statutory retention.
- Employees cannot use this workflow to object to administrative decisions.
- PDPL rights do not apply to anonymized data aggregated for KPIs.

### 7.4 Tests

- `PrivacyTest::pii_fields_marked_with_purpose_and_retention`
- `PrivacyTest::data_subject_access_request_works`
- `PrivacyTest::deletion_blocked_for_legal_hold_records`
- `PrivacyTest::pii_edits_audit_with_actor_and_reason`

## 8. NDMO Application

### 8.1 Data Classification

| Level | Description | Controls |
|---|---|---|
| `public` | Published data | Publication and scope controls |
| `internal` | Internal administrative data | Authorization decision and org-unit scope |
| `confidential` | Employee PII | Encryption, field authorization, auditing |
| `top_secret` | National IDs and sensitive information | Column encryption, administrative separation, expanded audit |

### 8.2 Data Ownership

- Every work record carries `owner_organization_unit_id`.
- Every work type carries `data_steward_role`.
- Any change to a work-type definition requires data-owner approval.

### 8.3 Metadata Lifecycle

| Phase | Application |
|---|---|
| Creation | Generate `record_id`, `created_at`, `created_by` in one transaction |
| Use | Log every read of confidential content |
| Archive | Move to read-only, disable writes |
| Destruction | Controlled procedure with data-owner and super-admin approval |

### 8.4 Master Data

- `Person` and core PII belong to Organization; `UserAccount`, credentials, and sessions belong to Identity.
- `OrgUnit`, `Position`, committees, `Employment`, `PositionAssignment`, `TemporaryAssignment`, and `CommitteeMembership` belong to Organization.
- `WorkRecord` belongs to `WorkRecords`; `Workflow` belongs to `Workflow`.
- Identity does not duplicate PII beyond `display_name_ar` and `display_name_en` as a classified display summary, with no national id, email, or phone.

### 8.5 Tests

- `NdmoTest::every_workrecord_has_owner_org_unit`
- `NdmoTest::data_steward_role_required_for_definition_changes`
- `NdmoTest::archived_records_are_read_only`
- `NdmoTest::destruction_requires_dual_approval`

## 9. Periodic Guard Tests

- `AuditTest::append_only_enforced_at_db_level`
- `AuditTest::audit_writer_role_lacks_update_grants`
- `AuditTest::audit_reader_role_lacks_insert_grants`
- `AuditTest::app_role_lacks_audit_table_grants`
- `AuditTest::hash_chain_intact_after_backup_restore`
- `PrivacyTest::no_pii_in_application_logs`
- `PrivacyTest::pii_columns_use_kms_encryption`
- `NdmoTest::classification_levels_present_on_records`
- `NdmoTest::retention_policy_present_on_work_types`

## 10. Alerting Indicators

- Hash chain verification failure.
- Daily export failure.
- Modification attempt on `audit_events` or `audit_payloads`.
- Access attempts by audit accounts outside the approved list.
- Audit write rate exceeding the natural upper bound (attack or fault detection).
- Retention period exceeded without a destruction plan.
- Anomalous growth of audit table volume.

## 11. Audit Incident Response Plan

- Detection of failure → immediate super-admin alert.
- Temporarily isolate application reads and writes.
- Freeze the separate store.
- Analyze the chain gap.
- Signed report within 24 hours.
- Resume-or-stop decision.
- Every action is recorded in external operational audit.

## 12. Compliance

| Requirement | Application |
|---|---|
| NCA ECC 1-7 Logging and Monitoring | Complete audit log with role separation (planned) |
| NCA ECC 1-10 Backup Management | Daily export with signature and isolation (planned) |
| PDPL Data Security | Payload encryption and access auditing |
| PDPL Data Subject Rights | Controlled and recorded workflow |
| PDPL Retention | Retention policies tied to work type |
| NDMO Data Classification | 4 levels with controls |
| NDMO Data Ownership | Mandatory assignment on every record |

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | Information Security Officer | Initial draft created |
| 0.2.0 | 2026-07-15 | Information Security Officer | Unified classification, replaced historical operational references, applied document tightening |
| 0.3.0 | 2026-07-18 | Information Security Officer | Aligned Person and PII ownership with ADR-024; marked the audit module, hash chain, and daily export as planned controls while retaining the implemented `access_decisions`, `sensitive_access_events`, and document outbox events |
