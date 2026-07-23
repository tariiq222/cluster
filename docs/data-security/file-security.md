---
doc_id: SEC-CL-002
title: File Security
type: data-security
status: draft
version: 0.2.0
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
- docs/adr/013-documents-and-file-security.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/domain/documents.md
- docs/data-security/logical-data-model.md
- docs/data-security/threat-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/audit-and-privacy.md
---
# File Security

## 1. Purpose and Scope

This document defines the complete security policy for file management inside the administrative platform of the Third Health Cluster, covering:

- Immediate fail-closed quarantine on receipt.
- Checksum integrity verification.
- Antivirus scanning.
- Zip Bomb detection.
- Most-restrictive policy for Multi-link, Hard links, and Symlinks.
- Immutable storage.
- Field-level policy enforcement on every access.

The platform is non-clinical. Uploaded files are administrative documents linked to business records (requests, contracts, minutes, decisions, project documents). It does not receive medical files or patient records.

## 2. File Security Principles

- **Quarantine is mandatory before availability.** Every uploaded file enters quarantine and only becomes available after passing all checks. Quarantine is fail-closed: any check failure keeps the file quarantined and never makes it available.
- **Trust nothing uploaded.** Every file is treated as untrusted until proven otherwise.
- **Immutable storage.** Once checks pass, the storage object becomes immutable. Any change requires a new version and a new record.
- **Most restrictive between record and document.** A document linked to a record applies the most restrictive of the record's or document's constraints, and does not automatically inherit wider access.
- **Service-account separation.** Each service account (upload, download, scan, delete) has independent, restricted permissions.
- **No sensitive data on devices.** Offline copies containing confidential content are not allowed. Download requires an active session and authorization.
- **Physical isolation of quarantine.** The quarantine zone is isolated from the availability zone, and the application never reads directly from it.


## 3. File Data Model

This section describes the canonical entity model for files. The implemented Document storage model in `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php` is authoritative; columns listed here match that schema. Planned controls are marked **PLANNED**.

### 3.1 Entities

#### 3.1.1 `documents` (table) — `Document`

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | UUID | PK | Document id (`document_id`) |
| `public_id` | UUID | unique | Public identifier exposed to clients |
| `owner_organization_unit_id` | UUID | indexed | Owning org unit |
| `created_by_user_id` | UUID | indexed | Creator |
| `name` | string | required | Document name |
| `description` | text | nullable | Optional description |
| `classification` | string(24) | indexed | public, internal, confidential, top_secret |
| `status` | string(24) | indexed | draft, active, archived, held, rejected |
| `current_version_id` | UUID | nullable | Active version |
| `retention_until` | timestamp(3) | nullable | Retention expiry (PLANNED scheduler) |
| `retention_policy_key` | string(128) | nullable | Retention policy key (PLANNED) |
| `legal_hold` | boolean | indexed, default false | Legal hold flag |
| `legal_hold_reason` | string(1000) | nullable | Legal hold reason |
| `legal_hold_at` | timestamp(3) | nullable | Legal hold timestamp |
| `lock_version` | unsigned int | default 1 | Optimistic concurrency token |
| `created_at`, `updated_at` | timestamps | required | Lifecycle timestamps |

#### 3.1.2 `document_versions` (table) — `DocumentVersion`

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | UUID | PK | Version id (`version_id`) |
| `public_id` | UUID | unique | Public identifier |
| `document_id` | UUID | FK -> documents, cascadeOnDelete | Document |
| `storage_object_id` | UUID | FK -> document_storage_objects, restrictOnDelete | Storage object |
| `version_number` | unsigned int | unique per document | Version number (`version_no`) |
| `original_filename` | string | required | Original filename |
| `declared_mime_type` | string(128) | required | MIME declared by uploader |
| `detected_mime_type` | string(128) | nullable | MIME detected by scanner (`mime_type`) |
| `size_bytes` | unsigned bigint | required | Actual size |
| `sha256` | char(64) | nullable, indexed | Content hash — **nullable** during quarantine until hashing completes |
| `scan_status` | string(24) | indexed | pending, scanning, clean, infected, failed |
| `availability_status` | string(24) | indexed | uploading, quarantined, promotion_pending, available, rejected, missing |
| `scan_engine_version` | string(128) | nullable | Scanner engine + signature version |
| `scan_result` | JSON | nullable | Scanner structured result payload (`scan_result` JSON) |
| `scanned_at` | timestamp(3) | nullable | Scan completion timestamp (`scan_completed_at`) |
| `available_at` | timestamp(3) | nullable | Promotion to available timestamp |
| `created_by_user_id` | UUID | indexed | Uploader |
| `created_at`, `updated_at` | timestamps | required | Lifecycle timestamps |

> **Drift correction.** Earlier revisions described BLAKE3, a strictly required/unique `sha256`, and `scan_status` values `rejected`. The implemented schema stores `sha256` as nullable (filled after hashing), exposes a JSON `scan_result` instead of separate `av_*` columns, and exposes scan status as a `DocumentScanStatus` enum with `pending, scanning, clean, infected, failed`. Availability is tracked in a separate `availability_status` column.

#### 3.1.3 `document_storage_objects` (table) — `StorageObject`

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | UUID | PK | Storage object id (`storage_object_id`) |
| `disk` | string(64) | required | Object storage disk name |
| `object_key` | string(512) | unique | Path inside object storage (`storage_path`) |
| `storage_class` | string(24) | indexed | quarantine, available, archive |
| `immutable` | boolean | default false | Whether object is immutable |
| `immutable_since` | timestamp(3) | nullable | When object became immutable |
| `created_at`, `updated_at` | timestamps | required | Lifecycle timestamps |

> **Drift correction.** The schema does not store `sha256`, `size_bytes`, or `encryption_key_id` on storage objects; the content hash lives on the version row, and KMS key handling is performed by the private object storage contract (`PrivateObjectStorage`).

#### 3.1.4 `document_quarantines` (table) — `QuarantineRecord`

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | UUID | PK | Quarantine id (`quarantine_id`) |
| `document_version_id` | UUID | FK -> document_versions, cascadeOnDelete | Version |
| `storage_object_id` | UUID | FK -> document_storage_objects, restrictOnDelete | Storage object |
| `upload_intent_id` | UUID | FK -> document_upload_intents, cascadeOnDelete | Originating upload intent |
| `sha256_verified` | boolean | default false | Hash matches content (PLANNED boolean verification) |
| `size_verified` | boolean | default false | Size matches declared size (PLANNED boolean verification) |
| `mime_verified` | boolean | default false | MIME matches allowed list (PLANNED boolean verification) |
| `detected_mime_type` | string(128) | nullable | MIME detected by scanner |
| `scan_engine` | string(128) | nullable | Scanner engine used (`av_scanner`) |
| `scan_signature_version` | string(128) | nullable | Signature version (`av_signature_version`) |
| `scanner_outcome` | string(24) | nullable | clean, infected, error, timeout — stored here, not on `DocumentVersion` |
| `policy_verdict` | string(24) | indexed | allowed, blocked, quarantined_hard |
| `failure_codes` | JSON | nullable | Structured failure codes (replaces `block_reason` text) |
| `scanned_at` | timestamp(3) | nullable | Scan completion timestamp |
| `created_at`, `updated_at` | timestamps | required | Lifecycle timestamps |

> **Drift correction.** The earlier schema listed `received_at`, `received_from_ip`, `checksum_verified`, `mime_verified`, `av_result`, `av_completed_at`, `decompression_ratio`, `uncompressed_total_bytes`, `embedded_files_count`, `symlink_detected`, `hardlink_detected`, `multi_link_score`, `block_reason`, and `reviewed_by_user_id`. None of those columns exist in the implemented migration. The implemented quarantine record exposes only the **boolean SHA-256 / size / MIME verification fields**, the JSON `scan_result` payload via the version row, and a string `policy_verdict`. Decompression, link, and ratio signals described elsewhere in this document are **PLANNED** and not stored as columns today.

#### 3.1.5 `DocumentLink` (PLANNED)

Linking a document to a business record is a planned control; no `document_links` migration exists in the verified modules. The conceptual model is documented here for future implementation:

| Column | Type | Constraint | Description |
|---|---|---|---|
| `link_id` | UUID | PK | Link id |
| `document_id` | UUID | FK | Document |
| `target_type` | string | required | Entity type |
| `target_id` | string | required | Entity id |
| `link_type` | enum | required | attached, referenced, evidence |
| `created_by_user_id` | UUID | required | Creator |
| `created_at` | timestamp | required | Link timestamp |

#### 3.1.6 `DocumentAccessEvent` (PLANNED)

| Column | Type | Constraint | Description |
|---|---|---|---|
| `event_id` | UUID | PK | Event id |
| `document_id` | UUID | FK | Document |
| `version_id` | UUID | FK | Version |
| `actor_user_id` | UUID | required | Actor |
| `action` | enum | required | view, download, link, unlink |
| `occurred_at` | timestamp(6) | required | Event time |
| `actor_ip` | string | required | Internal IP |
| `user_agent` | string | nullable | Browser |
| `outcome` | enum | required | allowed, denied; an audit copy of the Authorization outcome — not a decision issued by Documents |

### 3.2 Upload-intent and outbox tables (implemented)

The upload pipeline persists two additional tables that earlier revisions did not model:

#### 3.2.1 `document_upload_intents`

| Column | Type | Description |
|---|---|---|
| `id` | UUID | Intent id |
| `document_id` | UUID | Target document |
| `document_version_id` | UUID | Target version |
| `storage_object_id` | UUID | Reserved storage object |
| `expires_at` | timestamp(3) | Intent expiry |
| `completed_at` | timestamp(3) | Completion timestamp |
| `signed_intent_payload` | encrypted text | Encrypted signed-upload contract |
| `created_at`, `updated_at` | timestamps | Lifecycle timestamps |

#### 3.2.2 `document_outbox_events`

| Column | Type | Description |
|---|---|---|
| `id` | UUID | Event id |
| `aggregate_id` | UUID | Aggregate id (document or version) |
| `event_type` | string(128) | `document.outbox.*` event type |
| `payload` | JSON | Event payload |
| `occurred_at` | timestamp(3) | Event time |
| `published_at` | timestamp(3) | Publishing timestamp |
| `created_at`, `updated_at` | timestamps | Lifecycle timestamps |

> **Drift correction.** No `document_scan_queue` table exists. Scanner work is driven by the upload-intent lifecycle and persisted outbox events, not by a dedicated scan queue.

## 4. File Flow

### 4.1 Upload (intake)

The intake flow is **intent-based** and runs through `DocumentUploadHandler`. `POST /documents/upload` does not queue a scan and return 202 directly; it reserves a storage object, persists a `document_upload_intents` row, returns a signed upload URL, and waits for the client to call the completion endpoint:

1. The user starts an upload through the documents intake endpoint.
2. The API calls `InitiateDocumentUpload` (`UploadDocument` feature) which reserves a `document_storage_objects` row and creates a `document_versions` row with `scan_status=pending` and `availability_status=uploading`.
3. The API persists a `document_upload_intents` row bound to the new version and storage object, with an `expires_at` deadline.
4. The API returns a signed upload intent (URL + headers) so the client can PUT the blob directly to private object storage; the API does **not** return 202 with a queue position.
5. The scanner runs from the intake/outbox pipeline rather than a dedicated `document_scan_queue` table.
6. The client completes the upload by calling the completion operation; `CompleteDocumentUpload` flips `document_upload_intents.completed_at` and records the outbox event.
7. The scanner computes SHA-256 (nullable until hashing completes), verifies size, and verifies MIME via the `MalwareScanner` contract, persisting results to `document_quarantines` with `policy_verdict=quarantined_hard` until promotion.
8. On clean verdict the version is promoted: `availability_status=available`, `scan_status=clean`, `available_at` is set, and a `document_outbox_events` row is emitted.

> **Drift correction.** Earlier revisions asserted `POST /documents/upload` directly queued a scan and returned 202, computed BLAKE3, and used `document_scan_queue`. None of those steps match the implemented upload-intent/completion flow.

### 4.2 Scanning

1. The scanner resolves the in-flight upload intent from `document_upload_intents`.
2. It hashes the received bytes and fills the version's nullable `sha256` column.
3. It records size and MIME verification booleans (`sha256_verified`, `size_verified`, `mime_verified`) on `document_quarantines`.
4. It detects the real MIME (content sniffing) and compares it to the declared MIME.
5. It runs the multi-link / symlink / hardlink policy checks (PLANNED — not yet backed by stored columns).
6. It scans the content using the internal AV engine through the `MalwareScanner` contract and writes the structured `scan_result` JSON plus the `scan_engine_version` on the version row.
7. It updates `document_quarantines.policy_verdict` (defaults to `quarantined_hard` until promotion) and `document_versions.scan_status`.

### 4.3 Availability Decision

A version becomes `available` only when **all** of the following conditions hold:

| Condition | Verification |
|---|---|
| SHA-256 hash matches the content | `document_quarantines.sha256_verified = true` |
| MIME type is policy-allowed | `document_quarantines.mime_verified = true` |
| Scanner is clean | `scanner_outcome = clean` and `policy_verdict = allowed` |
| Decompression ratio within limits | **PLANNED** — `decompression_ratio ≤ 100` and `uncompressed_total_bytes ≤ 500 MB` (not stored today) |
| No suspicious multi-link findings | **PLANNED** — `multi_link_score ≤ 5` with `symlink_detected=false` and `hardlink_detected=false` (not stored today) |
| File size within limits | `size_bytes ≤ 200 MB` default, configurable per `work_type_version` |

When every condition holds:

1. The version row is promoted to `availability_status = available`; `scan_status = clean`; `available_at` is set.
2. `document_quarantines.policy_verdict` is set to `allowed`.
3. `document_storage_objects.immutable` is set to `true` and `immutable_since` is recorded.
4. `document_outbox_events` emits `document.scan.passed`.

### 4.4 Failure

When any condition fails:

1. `document_storage_objects.immutable` is set to `true` (the object stays in `quarantine`).
2. `document_versions.scan_status` is set to `infected`, `failed`, or as the scanner dictates.
3. `document_versions.availability_status` is set to `quarantined` or `rejected`.
4. `document_quarantines.policy_verdict` is set to `blocked` and `failure_codes` is populated.
5. `document_outbox_events` emits `document.scan.failed`.
6. Super-admin is notified on repeated uploads from the same source.
7. The file is never exposed through the API.

## 5. MIME Type Policy

### 5.1 Default Allow-list

| Category | Allowed Types |
|---|---|
| Documents | `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |
| Spreadsheets | `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Presentations | `application/vnd.ms-powerpoint`, `application/vnd.openxmlformats-officedocument.presentationml.presentation` |
| Images | `image/png`, `image/jpeg`, `image/tiff` |
| Text | `text/plain`, `text/csv`, `text/markdown` |
| Archives | `application/zip`, `application/x-7z-compressed`, `application/x-rar-compressed`, `application/x-tar`, `application/gzip` |

### 5.2 Disallowed Types

- Anything containing executables: `.exe`, `.bat`, `.cmd`, `.sh`, `.ps1`, `.jar`, `.msi`.
- Macros: `application/vnd.ms-excel.sheet.macroEnabled.12`.
- Embedded scripts: `text/html`, `application/xhtml+xml`.
- Shortcuts: `.lnk`, `.url`.

### 5.3 Double Verification

- Verify the declared MIME in the request header.
- Verify the real MIME via content sniffing (first 4096 bytes).
- If the two disagree, only the detected MIME is used.
- Reject the file when the two disagree and the detected MIME is outside the allow-list.

## 6. Zip Bomb Policy

### 6.1 Decompression Limits

| Item | Default Value | Configurable per Work Type |
|---|---|---|
| Maximum compression ratio | 100:1 | Yes |
| Total uncompressed size | 500 MB | Yes |
| Nesting depth | 5 levels | No |
| Files per archive | 10,000 | Yes |
| Single archive size | 200 MB | Yes |

### 6.2 Scanning Mechanism

1. Open the archive in streaming mode without writing it fully to disk.
2. Read each entry while accumulating the running size.
3. Compute the compression ratio against each compressed entry size.
4. If the cumulative size or the compression ratio exceeds the limit, reject the archive immediately.
5. Record the observed values on `document_quarantines` (PLANNED — the current schema does not store ratio/bytes columns; the failure must be persisted in `failure_codes`).

### 6.3 Tests

- `FileSecurityTest::zip_bomb_with_high_ratio_is_blocked`
- `FileSecurityTest::nested_archives_with_cumulative_size_blocked`
- `FileSecurityTest::archive_with_too_many_files_blocked`
- `FileSecurityTest::decompression_does_not_exhaust_memory`

## 7. Multi-link and Symlink Policy (Most Restrictive)

### 7.1 Rules

| Type | Action | Reason |
|---|---|---|
| Symbolic link inside archive | Always prohibited | Could point to sensitive paths |
| Symbolic link inside PDF/DOCX | Always prohibited | Execution risk |
| Hard link inside archive | Always prohibited | Could reference system files |
| Hard link across StorageObjects | Always prohibited | Each object must be independent |
| Shortcut inside Office | Always prohibited | Macro risk |
| OLE object inside Office | Special inspection | Macro risk |
| Embedded file in PDF | Inspect count and types | Execution risk |
| External reference in XLSX | Prohibited | Data leakage |
| Macro in Office | Prohibited | Instruction execution |

### 7.2 Detection Mechanism

- `archive_read` with `symlink` and `hardlink` detection before extraction.
- For PDFs, use a safe parsing library that ignores embedded JavaScript.
- For Office, inspect the first 8 bytes to detect VBA streams.
- For nested archives, apply the policy at each level up to the maximum depth.

### 7.3 Multi-link Score

Computed per file before availability:

| Detected Element | Points |
|---|---|
| One or more symlinks | 100 |
| One or more hardlinks | 100 |
| Macro in Office | 100 |
| Embedded executable | 100 |
| External reference in XLSX | 50 |
| OLE object in Office | 30 |
| Embedded file in PDF | 10 per file |
| Archive nesting depth 4 | 20 |
| Archive nesting depth 5 | 40 |

**PLANNED.** The score is part of the documented policy; the implemented schema does not currently store `multi_link_score`, `symlink_detected`, or `hardlink_detected` columns. The scanner is expected to emit failure codes via `document_quarantines.failure_codes` until the columns land.
## 8. Storage

### 8.1 Storage Zone Separation

| Zone | Usage | Access |
|---|---|---|
| Quarantine | Files before scanning | `quarantine_role` account only |
| Available | Clean files | `available_role` account only |
| Archive | Files past active retention | `archive_role` account only |
| Export | Copies dedicated to export | `export_role` account only |

Each zone has:

- A distinct prefix in Object Storage.
- A separate service account.
- A `NetworkPolicy` that scopes access.

### 8.2 Encryption

- At-rest encryption via SSE-KMS with a separate key per zone.
- In-transit encryption via TLS 1.3.
- Key rotation every 12 months or on suspicion.
- KMS is separated from production.

### 8.3 Immutability

- After promotion, `document_storage_objects.immutable = true`.
- No UPDATE is performed at the API layer.
- At the object storage layer, `object_lock` policy forbids delete and modify until `retention_until`.
- Destruction requires a controlled workflow per the retention policy.

### 8.4 Hash as Evidence

- SHA-256 is computed on receipt and never changes.
- On download, the hash is recomputed on the delivered bytes and compared.
- Any mismatch raises a critical alert and emits `document.integrity_violation` via the outbox.

## 9. Download and View

### 9.1 Download URLs

- Signed URLs with a short TTL (≤ 5 minutes).
- Bound to session, user, and version.
- Authorization on the record and the document is re-checked on every GET.
- A `DocumentAccessEvent` (`view`, `download`) is recorded **before** the signed URL is issued (PLANNED — see §3.1.6).

### 9.2 Field Policy Enforcement

- Documents forwards to `GetAuthorizationRecordFacts` only the document classification, status, `own_policy_key`, link facts, and the version pointer — never a decision or a field map.
- Authorization interprets those facts and alone issues the download, view, and field decisions.
- If the document is linked to a record, the most restrictive constraints apply.
- A user without visibility into a field linked to a protected document cannot download the document even with record-level authorization.

### 9.3 Physical Isolation of Top-secret Content

- Top-secret uploads are stored in a dedicated zone with a dedicated KMS key.
- Download requires the `documents.download.top_secret` capability and a second reviewer when the Authorization policy mandates it.

### 9.4 Tests

- `FileSecurityTest::presigned_url_has_short_ttl`
- `FileSecurityTest::download_checks_authorization_each_time`
- `FileSecurityTest::download_blocked_when_record_field_classification_higher`
- `FileSecurityTest::download_revalidates_session_on_each_request`

## 10. Advanced AV Scans

### 10.1 Engine Used

- Internal, signed AV engine compatible with isolated execution.
- Signature updates from a signed internal mirror.
- No direct internet access for signature updates.

### 10.2 Scan Modes

| Mode | Description | Application |
|---|---|---|
| Traditional signatures | Signature database | Yes |
| Heuristic | Behavioral detection | Yes |
| Internal sandbox | Isolated execution | For suspicious files only |
| YARA rules | Custom YARA rules | To detect platform-specific patterns |

### 10.3 Suspicious Files

- Files failing the traditional scan are escalated to manual review.
- Manual quarantine duration defaults to 30 days, extendable.
- Security team review is required before promotion or deletion.

## 11. Linking to Records

### 11.1 Linking Rules

- Linking a document to a record requires authorization on both the record and the document type.
- Quarantined documents cannot be linked.
- Removing a link removes the link only, not the document.

### 11.2 Most-restrictive Application

- Documents exposes its constraints and the link constraints as facts only; Authorization computes the final decision.
- A document linked to a record requires authorization on both.
- If the document authorization is narrower than the record authorization, the document authorization wins.
- If the record authorization is narrower than the document authorization, the record authorization wins.

### 11.3 Tests

- `DocumentLinkTest::linking_requires_authorization_on_record`
- `DocumentLinkTest::stricter_classification_wins`
- `DocumentLinkTest::quarantined_document_cannot_be_linked`

## 12. Risk Management and Destruction

### 12.1 Document Lifecycle

| Phase | Action | Logging |
|---|---|---|
| Active | Available for download within authorization | Every download recorded |
| Archive | Move to archive zone, read-only | `document.archived` |
| Legal hold | Block destruction | `document.legal_hold_set` |
| Destruction | Delete from Object Storage after dual approval | `document.destroyed` |

### 12.2 Controlled Destruction

- Destruction requires data owner and super-admin approval.
- Each destruction is recorded in `document_outbox_events` with type `document.destroyed` and a `reason`.
- Destruction removes the object from Object Storage only after `retention_until` has elapsed.
- Destruction of encrypted data erases the key after a grace period.

### 12.3 Tests

- `LifecycleTest::archived_documents_are_read_only`
- `LifecycleTest::legal_hold_blocks_destruction`
- `LifecycleTest::destruction_requires_dual_approval`
- `LifecycleTest::destruction_logged_with_reason`

## 13. Alerting Indicators

- File rejected by AV.
- File rejected for zip bomb.
- File rejected for symlink.
- File rejected for macro.
- Repeated uploads from the same source.
- Spike in file rejection rate over an hour.
- Attempt to download a quarantined document.
- Multiple attempts to access an expired link.

## 14. Isolated Infrastructure Requirements

- Internal AV signature mirror updated monthly.
- Internal sandbox for executing suspicious files.
- No internet egress from any scanning component.
- Scan logs are separated and archived independently.

## 15. Compliance

| Requirement | Application |
|---|---|
| NCA ECC 1-3 Data Protection | Encryption, separation, audit |
| NCA ECC 1-8 Infrastructure Protection | Storage zone separation |
| PDPL Data Security | Field policy enforcement and encryption |
| PDPL Data Minimization | Reject files outside the allow-list |

| NDMO Data Lifecycle | Active / Archive / Destruction phases |

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | Information Security Officer | Initial draft created |
| 0.2.0 | 2026-07-15 | Information Security Officer | Unified classification, references, document tightening, centralization of access decision |
