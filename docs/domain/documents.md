---
doc_id: DOM-DOC-001
title: Documents and Governed Links
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Documents Module Owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: On every change
sources:
- docs/adr/013-documents-and-file-security.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/retention-and-legal-hold.md
---
# Documents

## 1. Purpose and Scope

The Documents module owns the stored file-once, its metadata, classification, versions, usage links, document-specific restriction facts, download grants, and the post-Authorization access log. Business modules retain only the document identifier and a usage relationship; they do not store a copy of the file and do not read Object Storage directly.

The first release supports upload, internal scanning, immutable versions, linking to multiple records, document-specific restriction facts when needed, and a view/download log. Documents does not decide access on its own; Authorization alone issues Allow or Deny and field decisions. Formal archival, OCR, electronic signature, and retention numbers are out of scope for the first release, but the model does not preclude adding them later.

## 2. Terms and Models

| Term | Definition |
|---|---|
| Document | Logical identity, metadata, classification, and restriction facts across all versions. |
| DocumentVersion | Immutable binary file with hash and scan state. |
| DocumentLink | Usage relationship between a document and a generic source record. |
| OwnRestrictionFacts | Document restriction facts that feed into the Authorization decision and do not themselves grant or deny. |
| EffectiveRestrictionFacts | The union of the document's facts and the facts of all active links, presented to Authorization. |
| AccessGrant | Short-lived operational grant issued after `AccessDecision=Allow` for a specific operation; not a decision or a permanent permission. |

### 2.1 Aggregates

- `DocumentAggregate`: identity, owner, classification, metadata, restriction facts, and state.
- `DocumentVersionAggregate`: version number, object key, size, type, fingerprint, and scan state.
- `DocumentLinkAggregate`: source reference, purpose, link restrictions, and state.
- `DocumentAccessRecord`: append-only log of view, download, export, and sensitive denial attempts.

### 2.2 Value Objects

- `DocumentId`, `VersionNumber`, `ObjectKey`, `ContentHash`, `MimeType`, `FileSize`.
- `Classification`: `public|internal|confidential|top_secret`.
- `SourceReference`: `module_code`, `record_type`, `record_id`.
- `DocumentRestrictionFacts`: scope, classification, state, `field_policy_key`, and audit requirements, with no Allow/Deny and no field map.

## 3. Most-Restrictive Rule

Documents collects the facts for any `view_metadata|preview|download|add_version|link|unlink|export` operation as follows, then forwards them to Authorization without local evaluation:

```text
AuthorizationRecordFacts = DocumentRestrictionFacts
                           + LinkedSourceAuthorizationRecordFacts(1)
                           + LinkedSourceAuthorizationRecordFacts(2)
                           + ...
```

- Authorization alone applies the most-restrictive rule and issues `AccessDecision` and `FieldAccessDecision`; neither Documents nor the linked module issues Allow or Deny.
- Linking a document later to a more restrictive record immediately restricts access to the document from all paths; it does not create a wider copy.
- Unlinking does not automatically widen access before the decision is recomputed and the change is logged.
- A link does not grant the user permission to the document or to the other record.
- If `AuthorizationRecordFacts` cannot be fetched for any linked source, Authorization issues a denial for `facts_unavailable`; the last stored decision is never reused to allow.
- Search, notification, report, and export use the same decision and do not expose name, snippet, or link count before the grant is made.

## 4. Tables, Constraints, and Indexes

### 4.1 `documents`

- `id` UUID PK, `public_id` CHAR(36) UNIQUE NOT NULL.
- `owner_organization_unit_id` UUID NOT NULL.
- `created_by_user_id` UUID NOT NULL.
- `name` VARCHAR(255) NOT NULL, `description` TEXT NULL.
- `classification` VARCHAR(24) NOT NULL: `public|internal|confidential|top_secret`.
- `status` VARCHAR(24) NOT NULL: `draft|active|archived|held`.
- `restriction_facts` JSON NULL; optional typed facts per a declared schema, with no free code and no Allow/Deny.
- `current_version_id` UUID NULL.
- `retention_until` DATETIME(3) NULL, `retention_policy_key` VARCHAR(128) NULL.
- `legal_hold` BOOLEAN NOT NULL DEFAULT FALSE, `legal_hold_reason` VARCHAR(1000) NULL, `legal_hold_at` DATETIME(3) NULL.
- `lock_version` INT NOT NULL DEFAULT 1.
- `created_at`, `updated_at`.
- Indexes: `(owner_organization_unit_id, status)`, `(classification, status)`.

### 4.2 `document_versions`

- `id` UUID PK, `public_id` UUID UNIQUE NOT NULL, `document_id` UUID NOT NULL FK.
- `storage_object_id` UUID NOT NULL FK -> `document_storage_objects.id`.
- `version_number` INT NOT NULL.
- `object_key` VARCHAR(512) UNIQUE NOT NULL; random value that does not contain the file name.
- `original_filename` VARCHAR(255) NOT NULL.
- `declared_mime_type` VARCHAR(128) NOT NULL, `detected_mime_type` VARCHAR(128) NULL.
- `size_bytes` BIGINT NOT NULL, `sha256` CHAR(64) NULL.
- `scan_status` VARCHAR(24) NOT NULL: `pending|scanning|clean|infected|failed`.
- `availability_status` VARCHAR(24) NOT NULL: `uploading|quarantined|available|rejected|missing`.
- `scan_engine_version` VARCHAR(128) NULL, `scan_result` JSON NULL.
- `scanned_at` DATETIME(3) NULL, `available_at` DATETIME(3) NULL.
- `created_by_user_id` UUID NOT NULL, `created_at` DATETIME NOT NULL.
- Unique constraint: `(document_id, version_number)`.
- Indexes: `(document_id, version_number)`, `(document_id, availability_status)`, `(scan_status, created_at)`, `(sha256)`.
- No update to the file or hash after transition to `available`; any change creates a new version.

### 4.3 `document_links`

- `id` UUID PK, `document_id` UUID NOT NULL FK.
- `source_module` VARCHAR(64) NOT NULL, `source_type` VARCHAR(64) NOT NULL, `source_id` VARCHAR(128) NULL.
- `relation_type` VARCHAR(32) NOT NULL: `attachment|evidence|deliverable|reference`.
- `link_classification` VARCHAR(24) NULL: `public|internal|confidential|top_secret`; a restriction fact that can only tighten.
- `linked_by_user_id` UUID NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active': `active|unlinked`.
- `created_at` DATETIME NOT NULL, `unlinked_at` DATETIME NULL, `unlink_reason` VARCHAR(1000) NULL.
- Logical unique constraint for an active link: `(document_id, source_module, source_type, source_id, relation_type, status)`.
- Indexes: `(source_module, source_type, source_id, status)`, `(document_id, status)`.

### 4.4 `document_restriction_facts`

- `id` UUID PK, `document_id` UUID NOT NULL FK.
- `fact_key` VARCHAR(128) NOT NULL; a key from the declared restriction schema.
- `fact_value` JSON NOT NULL; a typed value that carries no access decision and no business payload.
- `valid_from` DATETIME NOT NULL, `valid_until` DATETIME NULL.
- `recorded_by_user_id` UUID NOT NULL, `created_at` DATETIME NOT NULL.
- Unique constraint: `(document_id, fact_key, valid_from)`.
- Index: `(document_id, fact_key, valid_until)`.
- Documents does not interpret these rows as allow or deny; it only feeds them into `AuthorizationRecordFacts`.

### 4.5 `document_access_events`

- `id` UUID PK, `document_id` UUID NOT NULL, `document_version_id` UUID NULL.
- `actor_user_id` UUID NOT NULL, `acting_organization_unit_id` UUID NOT NULL.
- `action` VARCHAR(24) NOT NULL: `metadata_view|preview|download|export|denied`.
- `decision` VARCHAR(16) NOT NULL, `decision_reason_code` VARCHAR(64) NOT NULL.
- `source_context` JSON NULL; identifiers only with no business payload.
- `ip_address` VARCHAR(45) NULL, `user_agent_hash` CHAR(64) NULL.
- `occurred_at` DATETIME NOT NULL, `event_id` UUID UNIQUE NOT NULL.
- Append-only, with indexes `(document_id, occurred_at)`, `(actor_user_id, occurred_at)`, `(action, occurred_at)`.

## 5. Contracts

### 5.1 Commands

- `CreateDocument(metadata, classification, restrictionFacts): DocumentId`.
- `InitiateDocumentUpload(documentId, filename, size, declaredMime, idempotencyKey): UploadTicket`.
- `FinalizeDocumentUpload(documentId, uploadToken, sha256)`.
- `RecordDocumentScanResult(versionId, result)`; reserved for the trusted scan worker.
- `AddDocumentVersion(documentId, upload)`.
- `UpdateDocumentMetadata(documentId, expectedVersion)`.
- `ChangeDocumentClassification(documentId, newClassification, reason)`.
- `LinkDocument(documentId, sourceReference, relationType)`.
- `UnlinkDocument(linkId, reason)`.
- `ArchiveDocument(documentId, reason)`.
- `PlaceDocumentOnHold(documentId, reason)` and `ReleaseDocumentHold` for authorized callers.

### 5.2 Queries

- `GetDocumentMetadata(documentId, accessDecision)`.
- `ListDocumentVersions(documentId, accessDecision)`.
- `GetDocumentPreviewGrant(documentId, versionId, actorContext): AccessGrant`.
- `GetDocumentDownloadGrant(documentId, versionId, actorContext): AccessGrant`.
- `ListDocumentsLinkedToSource(sourceReference, accessDecision)`.
- `GetDocumentIntegrityStatus(documentId, versionId)`.

Documents requests `DecideAccess` and `ResolveFieldAccess` with fresh facts. It does not issue an `AccessGrant` until an `AccessDecision=Allow` is received from Authorization; the grant is single-use, bound to the user, version, and action, short-lived, and is not issued before the required sensitive access is logged.

### 5.3 Required Source Contracts

Every module that allows linking implements the single access contract below:

- `GetAuthorizationRecordFacts(sourceReference): AuthorizationRecordFacts`.

The facts establish the target's existence, classification, scope, state, `field_policy_key`, and required restrictions. The owner does not issue access decisions, and Documents does not call the source's structure or tables.

### 5.4 Contracts Exposed to Other Modules

- `CreateDocument`, `AddDocumentVersion`, `LinkDocument`.
- `GetDocumentDownloadGrant`.
- `GetAuthorizationRecordFacts(documentId)`; returns document and link facts without an access decision.
- `GetDocumentReferenceSummary(reference, accessDecision)`; returns safe data after an Allow decision from Authorization.
- `VerifyDocumentEvidenceAvailable(documentIds[])`.

## 6. Events

- `DocumentCreated`
- `DocumentUploadInitiated`
- `DocumentVersionUploaded`
- `DocumentVersionScanStarted`
- `DocumentVersionAvailable`
- `DocumentVersionQuarantined`
- `DocumentVersionRejected`
- `DocumentMetadataUpdated`
- `DocumentClassificationChanged`
- `DocumentLinked`
- `DocumentUnlinked`
- `DocumentDownloaded`
- `DocumentArchived`
- `DocumentHoldPlaced`
- `DocumentHoldReleased`

Public events carry no download link, content, or confidential name. Fact and Outbox events are persisted in a single transaction, and consumers are idempotent.

## 7. States

### 7.1 Document

```text
Draft -> Active: a first clean version is available and an authorized link or publish exists
Draft -> Archived: cancellation before activation
Active -> Archived: logical archival
Active | Archived -> Held: hold blocks disposal and tears down restricted links
Held -> Active | Archived: release returns to the prior state
```

### 7.2 DocumentVersion

```text
Uploading -> Quarantined: upload completes and size/hash verification passes
Quarantined -> Scanning
Scanning -> Available: clean result and allowed type
Scanning -> Rejected: infected or disallowed type
Scanning -> Quarantined: technical failure that is retryable
Available | Rejected: terminal; no modification, and re-upload creates a new version
```

### 7.3 DocumentLink

```text
Active -> Unlinked
```

No hard delete of a link from the UI.

## 8. Invariants

- The binary file is stored once per version and is never silently replaced.
- `version_number` is strictly increasing within a document, and `current_version_id` points only to an `available` version.
- No preview or download before `scan_status=clean` and `availability_status=available`.
- The `sha256` computed from storage matches the declared value before scanning.
- Classification cannot be lowered without an independent capability, reason, and audit; lowering does not bypass links that are more restrictive.
- The effective decision is issued by Authorization using the document's facts and all active links; any link that cannot be resolved fails closed.
- Adding a link never widens access, and removing a link never grants access without a full re-decision.
- A user who sees one linked record but not another linked record cannot download the shared document.
- A held document is not disposed, and the links that the hold enforces are not removed.
- No hard delete from the UI; only the retention policy permits a future controlled disposal operation.
- The module carries no commercial `evidence` meaning; it only verifies the existence, type, and availability of a relationship.

## 9. Security

- Object Storage is internal, encrypted in transit and at rest, and is never directly accessible from the browser without an `AccessGrant`.
- Object keys are random and carry no names, units, or classifications.
- The list of allowed extensions, MIME types, and sizes is centrally managed; the decision relies on the detected type, not the extension alone.
- Every file passes internal scanning in quarantine; only workers may move the object into the available space.
- Signed links are short-lived and single-use when possible, and are restricted to the action, user, and version.
- View, download, and export of confidential content are logged before the response is returned; a critical audit failure blocks the operation.
- Authorization alone applies `DecideAccess` and `ResolveFieldAccess` to RBAC + ABAC, scope, classification, state, delegation, and field permissions; Documents only stores and exposes facts.
- The super admin manages settings and policies, but administrative access does not override content classification or the most-restrictive link rule, and every sensitive view is logged.
- Files and metadata are never sent to a service outside the data center.

## 10. Failure and Recovery

- Incomplete upload or hash mismatch: the temporary portion is deleted per policy and the version remains unavailable.
- Infected file: transitions to `Rejected`, the object is isolated, and a security event is logged without exposing scanner details to the user.
- Scanner outage: the version stays `Quarantined` and retry is applied; no optimistic availability.
- Object loss or later hash mismatch: `availability_status=missing`, download is blocked, and an integrity alert is issued.
- Linked source outage while fetching facts: Authorization issues a safe denial for `facts_unavailable`.
- Version-add conflict: optimistic locking and atomic numbering; no two versions share the same number.
- Link-creation failure after upload: the document remains without the link, and the source does not treat it as evidence until `DocumentLinked` succeeds.
- Outbox failure rolls back the metadata or link transaction; asynchronous scanning is retryable.
- AccessGrant expiration or reuse: `401/403` and a new decision is required.
- Storage capacity exceeded: the upload is refused before an incomplete version is left behind.

## 11. Tests and Acceptance Criteria

### 11.1 Domain Tests

- A first clean version activates the document; an infected one does not.
- Adding a version does not modify the previous version.
- Prevent making a quarantined version the current one.
- Prevent lowering classification without a reason and capability.
- Prevent archiving or disposing of a held document.

### 11.2 Most-Restrictive Tests

- A document with internal restriction facts linked to a WorkRecord classified confidential becomes effectively confidential by Authorization decision.
- A document linked to two records, where the user is authorized for only one: no metadata, no download, and no search result.
- Adding a more restrictive link withdraws access that was previously allowed on another path.
- Removing the more restrictive link does not allow access before a full Authorization re-run.
- Outage of one source contract out of several blocks access.
- Narrower document restriction facts cause Authorization to deny even when other contexts allow.

### 11.3 Security and Storage Tests

- Spoofed MIME, double extension, oversize file, and bad hash.
- An API pod cannot read quarantine via the public download path.
- An AccessGrant for a different user or version fails, and reuse fails.
- Confidential view and download are logged, and a critical audit failure blocks the response.
- File name and object key do not appear in a public Outbox event.

### 11.4 Contract and Operational Tests

- Contract test for every source that supports `GetAuthorizationRecordFacts`, verifying it does not return Allow/Deny or decided fields.
- Idempotency for re-`DocumentVersionUploaded` and re-`DocumentLinked`.
- A scanner failure followed by success does not create a second version.
- Database and Object Storage restore preserve version hashes and link integrity.
- Search, report, and export return only documents that pass the most-restrictive decision.

## 12. Dependencies and Integration Boundaries

- Depends on Authorization alone for access and field decisions, on Organization for scope, on Identity for actor identity, and on Audit for sensitive logging.
- Technically depends on Object Storage, Queue, an internal file-scanning engine, Shared/Clock, and Identifiers.
- Business and task modules depend on it through contracts only; they do not reach `object_key` or version tables.
- Notifications consumes the version-available event without embedding the file.
- Search and Reporting consume indexable metadata through derived events and re-check access at read time.
- It does not depend on any specific business module; every source, including WorkRecords, Strategy, PortfolioProjects, and Risk, implements the generic link contract.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Documents Module Owner | Unified the front end and stabilized link contracts |
