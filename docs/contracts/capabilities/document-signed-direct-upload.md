---
doc_id: CON-CAP-DOC-001
title: Signed Direct Upload to Quarantine Contract
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: Software Engineering Office
reviewers:
- Information Security Officer
- Documents Module Owner
classification: internal
review_cycle: on every change
sources:
- docs/adr/013-documents-and-file-security.md
- docs/domain/documents.md
- docs/data-security/file-security.md
- docs/data-security/retention-and-legal-hold.md
references:
- docs/contracts/api/openapi.yaml
- docs/contracts/capabilities/organization-import-rows-v1.md
---
# Signed Direct Upload to Quarantine Contract

## Status and Boundary

**Implementation status:** `implemented` (Phase B + D update)

This contract defines the target behavior for signed direct upload and private storage
and scanning; publishing the upload routes alone does not implement this capability.

Documents owns the bytes, the quarantine record, and the scan result. For Organization imports, Organization retains only the `quarantine_object_id` opaque reference and does not read any table or object key from Documents. The signed URL grants no read, list, or other-object write permission, and no access to the `available` space.

## Mandatory Flow

1. The client requests an upload ticket for a specific purpose: document version issuance or an Organization import source. Documents verifies authorization, capacity, and the type and size policy before reserving identifiers.
2. Documents returns a single-use ticket containing `upload_id`, `quarantine_object_id`, `method=PUT`, `upload_url`, `required_headers`, `expires_at`, and `max_size_bytes`. The URL is signed for a single random object inside the quarantine and does not include the filename or classification in the object key.
3. The client uploads bytes directly over TLS using the values restricted in the ticket. The system does not trust the filename, extension, or declared `Content-Type`.
4. The client completes the upload with `upload_id`, `sha256`, and `byte_size`. Documents reads back the metadata and the object, and computes SHA-256 over the stored bytes; the client value is a comparison hint and not source of truth.
5. The record transitions to `quarantined` then `scanning`. No public API or Organization can open the source during these states.
6. The worker inspects the detected type, the allowed MIME list, the internal AV, compression bombs, embedded files, links, and macros per the file-security policy. Failure or timeout keeps it fail-closed.
7. Only on success does the result become `clean`. A document version transitions per the Documents lifecycle to `available`. An import source remains a governed reference; only an Organization worker opens it through the Documents read contract after reauthorization and verification of purpose and state.
8. Size, SHA-256, or MIME mismatch, or a non-clean AV result, yields `rejected` or `quarantined` eligibility for re-scan based on the cause; there is no optimistic availability.

Completion is idempotent in the same sense. Replaying the same key with a different request returns `409` and does not create a second copy or object. `quarantine_object_id` is not used in an ImportJob before a `clean` result matching the purpose and the required template.

## Documents Private Object Storage Boundary

Documents owns private object storage at the stand-alone boundary defined by `PrivateObjectStorage` and the `S3CompatiblePrivateObjectStorage` adapter. The boundary is:

- Documents stores and serves quarantine and available objects through this single adapter; Organization does not depend on object keys, bucket layout, or any internal table from Documents.
- Organization only ever references the opaque `quarantine_object_id`; it never constructs, parses, or shares object keys with Documents.
- The adapter is the only component that may read, write, list, or sign objects on behalf of the platform; routes and Application-layer code never call the underlying storage SDK directly.
- Signed URLs are issued only for a single random object inside the quarantine, carry the `PUT` method, and the `required_headers` declared in the ticket; they do not grant list, read, or access to any other object.
- The Documents module is the sole consumer of the adapter; no other module imports or instantiates the storage client.

The planned Documents↔Organization operation contracts (`RequestSignedQuarantineUpload`, `FinalizeQuarantineUpload`, `GetQuarantineObjectStatus`, `OpenCleanQuarantineObject`) listed in earlier drafts are not the live boundary. The live boundary is the opaque `quarantine_object_id` plus the signed PUT ticket plus the Documents read contract; Organization never issues Allow, and Authorization alone decides before Documents verifies the decision and constraints before issuing the ticket or stream.

## Security and Retention Constraints

- SHA-256 is mandatory before scan, after scan, and at retrieval. The server computes BLAKE3 as a secondary proof when the file-security policy requires it, and does not accept any client-computed hash as a substitute for the server computation.
- The detected type is authoritative. A mismatch between declared and detected MIME blocks consumption until the policy issues an explicit verdict, and extension matching alone is not sufficient.
- No byte is moved from the quarantine before a clean AV and the rest of the file-security checks. The scan version, engine, signatures, and outcome necessary for audit are retained without disclosing details that could enable bypassing the scan.
- Operational documents and accepted import sources in the `business` classification are retained for at least seven years from the approved start of the count. Audit events and access evidence are retained for ten years. Legal hold suspends destruction regardless of duration, and archive does not shorten the retention period.
- The upload ticket has a mandatory `expires_at` that is short per the published security configuration at implementation time. This contract does not establish a numeric duration for the upload ticket; the five-minute limit in the file-security policy applies to download links.

## Reconciliation of Existing Conflicts

- The previous `POST /documents/upload` description that passes bytes through the API does not govern the new slice; the paths `/documents/uploads` and `/complete` are the OpenAPI extension points for the signed ticket.
- Direct browser access to Object Storage remains blocked. The upload ticket is a write-only capability to a single quarantine object and is not a download link or a general credential.
- `UploadRequest.byte_size` in OpenAPI sets an envelope ceiling of 1 GiB, while the file-security policy makes the effective default 200 MB and reducible by work type. The server applies the smaller of envelope, policy, and purpose; 1 GiB does not mean the file is allowed.
- Transferring a clean document version to `available` does not turn a raw import source into a downloadable document; `quarantine_object_id` remains an internal capability reference and purpose is part of the consumption decision.

## Acceptance Criteria

- The upload ticket cannot read, list, change object key, or exceed the restricted size and headers.
- Expiry, reuse, or a different completion hash rejects without creating an available object.
- A forged MIME, an infected AV, or scanner failure remains non-consumable.
- Organization does not apply an ImportJob before `clean` and reads only through the owner's contract.
- Retention tests demonstrate seven years for `business` content and ten years for audit effect, and that legal hold prevents destruction.
