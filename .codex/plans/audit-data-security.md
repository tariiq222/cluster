# Data-security code audit

TOTAL=9 RESOLVED=2 ACCEPTED=3 OPEN=4

Scope: documentation was compared with the canonical reference and the implemented Authorization, Identity, Documents, Organization, outbox, middleware, provider bindings, and migration surfaces. `DRIFT-RESOLVED` means the claim matches code; `DRIFT-ACCEPTED` means it is explicitly a planned/policy-only claim (not an implementation assertion); `DRIFT-OPEN` means the document states an implemented/runtime fact contradicted by code.

## docs/data-security/README.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Index and document purposes match the nine files present in this directory. | `README.md:17-26`; directory listing |

## docs/data-security/logical-data-model.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Person PII ownership and Identity reference separation match the migrations: `people` owns encrypted national ID/email/phone, while `users` stores `person_id` and display-name projection. | `logical-data-model.md:48-49, 622-650`; `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationPeopleTable.php:11-23`; `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php:11-26` |
| DRIFT-ACCEPTED | Strategy, portfolio, risk, audit, and other non-implemented ERD entities are presented as the logical target model; the canonical reference identifies several of these modules as planned rather than implemented. | `logical-data-model.md:51-132, 348-357`; canonical reference, “KEY GAPS vs docs/architectural-claims” |
| DRIFT-OPEN | The document presents `AuditEvent`, `AuditHashLink`, `AuditExportBatch`, `ClearanceLevel`, `RecordClassification`, and related tables as data entities, but no corresponding `Schema::create` migrations exist. The only implemented audit-like persistence is `access_decisions`, `sensitive_access_events`, and document access events. | `logical-data-model.md:109-110, 394-397, 604-650`; `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/ZAddAuthorizationHttpTables.php:30-50`; migration search across `apps/api/Modules/*` |
| DRIFT-OPEN | The model says every record carries retention/legal-hold fields and that large `audit_events` tables are partitioned, but the implemented WorkRecords/Documents schemas do not provide the documented retention subsystem or audit table/partition. | `logical-data-model.md:419-431, 484-487`; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php:11-31`; `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php:11-` |

## docs/data-security/authorization-model.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | The documented ten-stage evaluation order is not the runtime order. The implementation first rejects missing facts, missing actor user ID, and unsupported capability, then checks explicit deny, classification validity, role deny, grants/delegations/supervisory relationships, expiry, and fallback; it has no runtime stages for account state, shares, record state, or field-policy evaluation as described in stages 1, 5, 8, and 9. | `authorization-model.md:66-144`; `apps/api/Modules/Authorization/Infrastructure/RbacAbacDecideAccess.php:114-186` |
| DRIFT-RESOLVED | Backend-only decisioning, bootstrap gating, four classification values, and sensitive-audit conversion on persistence failure are consistent with the implemented engine/provider and persistence contract. | `authorization-model.md:19-27, 114-143, 155-170`; `apps/api/Modules/Authorization/Infrastructure/BootstrapGatedDecideAccess.php:17-35`; `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php:13-45`; `apps/api/app/Providers/AppServiceProvider.php` |

## docs/data-security/authorization-engine-quickref.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Bootstrap gate capability allow-list and the core RBAC/ABAC reason-code chain match the implementation. | `authorization-engine-quickref.md:48-72`; `apps/api/Modules/Authorization/Infrastructure/BootstrapGatedDecideAccess.php:10-35`; `apps/api/Modules/Authorization/Infrastructure/RbacAbacDecideAccess.php:114-186` |
| DRIFT-OPEN | The quick reference says every controller with confidential `RecordFacts` uses a sensitive-seeded capability and cites a conformance test, but this is not a property of the engine itself and the referenced test is not part of the runtime contract. The document should label this as a test/seed invariant rather than a universal engine guarantee. | `authorization-engine-quickref.md:88-91`; `apps/api/Modules/Authorization/Contracts/RecordFacts.php:12-31`; `apps/api/Modules/Authorization/Infrastructure/RbacAbacDecideAccess.php:134-136` |

## docs/data-security/identity-session-security.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | The session token contract is documented as a JWT/HS256 token with embedded claims plus a separate eight-hour refresh token, but Identity stores an opaque token hash and server-side metadata; `IdentitySessionMiddleware` resolves the cookie through `ResolveSession` and has no JWT or refresh-token path. | `identity-session-security.md:128-135`; `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php:34-46`; `apps/api/Modules/Identity/Features/Sessions/Handler/SessionHandler.php:35-114`; `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php:20-77` |
| DRIFT-OPEN | The document asserts 30-minute idle and eight-hour maximum session enforcement, but the persisted session schema exposes only `expires_at`, `revoked_at`, and `last_seen_at`; no `idle_expires_at` or JWT claim exists, and the actual values are runtime settings rather than the documented fixed contract. | `identity-session-security.md:114-145`; `CreateIdentityAccountTables.php:34-46`; `SessionHandler.php:118-189` |
| DRIFT-OPEN | The document claims dual-admin recovery, break-glass accounts, separate administrative accounts, and super-admin MFA as implemented security flows, while the Identity module exposes credential/session/TOTP primitives and no recovery or break-glass feature tables/handlers. | `identity-session-security.md:44-48, 190-278`; `apps/api/Modules/Identity/` file inventory; `ZAddIdentityCredentialCoreTables.php:23-79` |

## docs/data-security/classification-and-handling.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | The four classification symbols and ordering are implemented by `ClassificationLevel`, and sensitive auditing begins at confidential. | `classification-and-handling.md:34-40, 61-65`; `apps/api/Modules/Authorization/Domain/ClassificationLevel.php:6-37` |
| DRIFT-OPEN | The document describes ClearanceLevel, RecordClassification history, classification raise/lower capabilities, double approval, field classification storage, and super-admin controls as runtime mechanisms, but the migrations contain no such tables or handlers; the implemented classification input is a string in `RecordFacts` and document rows. | `classification-and-handling.md:67-72, 110-145, 604-639`; `apps/api/Modules/Authorization/Contracts/RecordFacts.php:12-31`; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php:11-31`; migration search |
| DRIFT-ACCEPTED | Handling rules for public/internal/confidential/top-secret content (search, printing, export, and break-glass) are policy requirements in a draft, not claims of an implemented module; they are acceptable only while clearly tracked as planned controls. | `classification-and-handling.md:74-108, 174-205`; canonical reference: audit/planned-module gaps |

## docs/data-security/file-security.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | The documented file schema and upload pipeline require BLAKE3, decompression/link metrics, and a `QuarantineRecord` with those columns, but the real schema has only nullable SHA-256, JSON `scan_result`, and boolean SHA/size/MIME verification fields; no BLAKE3 or multi-link fields exist. | `file-security.md:70-127, 160-187`; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php:43-100` |
| DRIFT-OPEN | The document claims `POST /documents/upload` directly queues a scan and returns 202, while the implemented flow is upload-intent/completion based and persists `document_upload_intents` and `document_outbox_events`; no `document_scan_queue` table appears in the Documents migrations. | `file-security.md:158-166`; `CreateDocumentsCoreTables.php:69-128`; `apps/api/Modules/Documents/Features/Upload/DocumentUploadHandler.php` |
| DRIFT-RESOLVED | Quarantine-before-availability, private object storage, malware scanner abstraction, and immutable storage metadata are represented in the Documents handlers/contracts and migrations. | `file-security.md:20-32, 178-208`; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php:33-100`; `apps/api/Modules/Documents/Contracts/MalwareScanner.php`; `apps/api/Modules/Documents/Contracts/PrivateObjectStorage.php` |

## docs/data-security/retention-and-legal-hold.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | The 7/10-year/24-hour periods, LegalHoldCase/Target, DisposalEvent, RetentionExtension, scheduler, and destruction workflow are not implemented in the verified modules. As a draft retention policy this is accepted planned behavior; it must not be read as current runtime capability. | `retention-and-legal-hold.md:34-141, 160-258`; no retention/legal-hold migration or module in `apps/api/Modules/*`; canonical reference planned-module gaps |

## docs/data-security/audit-and-privacy.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | This document consistently describes the Audit module, append-only audit schema, hash-chain procedures, daily export, and privacy workflows as policy/design material, but that module and its tables are planned rather than implemented. The claims are accepted only as planned controls, not evidence of current enforcement. | `audit-and-privacy.md:1-24, 59-137, 146-312`; no `audit_events`, `audit_payloads`, `audit_hash_link`, or `audit_export_batch` migrations; canonical reference planned-module gaps |
| DRIFT-RESOLVED | The narrower access-decision persistence claim is supported: implemented Authorization persistence writes `access_decisions` and conditionally `sensitive_access_events`, and returns failure on missing actor/decision ID or transaction errors. | `audit-and-privacy.md:125-137`; `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php:13-45`; `CreateAuthorizationFieldAuditTables.php:37-` |

## docs/data-security/threat-model.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | STRIDE boundaries, NCA/PDPL/NDMO mappings, and named guard tests are a draft threat model and future control/test inventory; most named tests and break-glass/audit/export infrastructure do not exist in the verified code. They are acceptable as requirements only if not represented as deployed controls. | `threat-model.md:1-30, 100-275`; verified test inventory and module directories; canonical reference planned-module gaps |
| DRIFT-OPEN | The threat model states the platform uses JWT/refresh sessions and a 30-minute/8-hour session policy, contradicting the actual opaque server-side session implementation. This is a runtime-security claim inside the threat/control matrix and should be corrected or explicitly marked planned. | `threat-model.md:106-110`; `identity-session-security.md:128-145`; `apps/api/Modules/Identity/Features/Sessions/Handler/SessionHandler.php`; `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php` |
| DRIFT-RESOLVED | The threat model's requirement that authorization remain backend-controlled and that internal routes use the identity/session boundary is consistent with the middleware and Authorization provider runtime guard. | `threat-model.md:108, 161-166`; `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php:20-77`; `apps/api/app/Providers/AppServiceProvider.php` |
