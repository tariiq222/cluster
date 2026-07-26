# Cluster Healthcare Privacy Compliance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: P04
status: planned
depends_on: []
blocks: [P08]
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Establish a source-backed PII/PHI inventory and enforce minimum-necessary access, auditability, retention/disposition, redaction, export, incident-evidence, environment, encryption, and vendor-boundary controls without claiming legal certification.

**Architecture:** Inventory begins immediately and is read-only with respect to active implementation surfaces. Enforcement begins only after the named Architecture Closure handoffs and completes only after M01 Audit, M02 RecordsGovernance, and P02 Documents production runtime provide their frozen contracts and evidence. Every source module remains responsible for its own controller, authorization/validation, handler, persistence, retention side effects, and outbox; P04 adds compliance registers, black-box acceptance gates, and narrowly scoped remediations through serialized integration tokens.

**Tech Stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, MySQL/SQLite, React 19, TypeScript 6, Vitest 4, OpenAPI 3.1, Orval, Python 3.12, Bash, private S3-compatible object storage with KMS, and ClamAV.

**Approved Design:** [`../specs/2026-07-26-cluster-production-and-modules-program-design.md`](../specs/2026-07-26-cluster-production-and-modules-program-design.md)

---

## 1. Status, dependencies, and phase gates

P04 has no start dependency: the inventory and threat-model phase may begin while Architecture Closure remains `in_progress`. P04 blocks P08. M01, M02, and P02 are **completion-phase gates**, not `depends_on` start gates.

| Phase | May start when | Must stop before |
|---|---|---|
| A — immediate inventory | P04 is `ready` | Any application/config/contract change |
| B — source remediation | `ARCHITECTURE-CLOSURE:T6`, `T7`, `T9`, `T10`, and `T12` have handed off affected files; each module/UI owner has issued a non-overlapping token | Master OpenAPI, generated client, `routes/web.php`, active module work, or production topology without its queue token |
| C — audit/governance/storage enforcement | M01 and M02 are `completed`; immutable P02 credential-rotation evidence and P03 vendor/RPO evidence for the same candidate commit are readable | P04 verification if any producer is still using a test fake or supplied evidence identifies an unresolved production boundary |
| D — verification | All P04 changes are integrated and `ARCHITECTURE-CLOSURE:T13-HANDOFF` is recorded | `completed` until P04's own evidence is fresh, commit-bound, validated, and published |

The following are hard gates:

- M01 must publish `RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` and `QueryAuditActivity::query(AuditActivityQuery): AuditActivityPage` (`AuditActivityItem`).
- M02 must publish `RegisterGovernedRecord::register(GovernedRecordRegistration): GovernedRecordStatus`, `ReadGovernedRecordStatus::get(RecordSourceReference): ?GovernedRecordStatus`, `GuardDispositionExecution::evaluate(RecordSourceReference): DispositionExecutionDecision`, and `QueryRecordsGovernanceSummary::forScope(RecordsGovernanceSummaryQuery): RecordsGovernanceSummary`.
- P02 must publish immutable evidence for real private quarantine/available object stores, distinct credentials/buckets, KMS encryption, fail-closed ClamAV, production worker identity, and credential rotation; P03 must publish immutable vendor-boundary and backup RPO evidence. P04 consumes their manifests and does not duplicate or require topology, rotation, vendor, or RPO implementation.
- The current Architecture Closure plan retains its reserved surfaces until explicit handoff. P04 never edits `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, or `apps/api/tests/Architecture/ModuleBoundariesTest.php`.

## 2. Goal and user-visible outcome

A permitted user sees only the smallest record fields and rows required for the active facility/unit task. Sensitive reads, downloads, searches, exports, holds, and dispositions are attributable by opaque identifiers and correlation ID. Search terms do not enter URLs; PHI/PII and credentials do not enter browser persistence, outward errors, unsanitized logs, telemetry, dead letters, or fixtures accepted as production evidence. Exports expire and are actually disposed; legal holds prevent disposition; every disposal outcome leaves non-PHI evidence.

This plan produces engineering controls and retained evidence. It does **not** assert HIPAA, GDPR, DISHA, Saudi PDPL, or any other legal certification. A legal/privacy owner must determine jurisdiction, covered-entity/business-associate roles, retention periods, disclosure rules, breach deadlines, and whether a vendor agreement or BAA is required.

## 3. Current source evidence

The immediate inventory must record these observed facts before proposing changes:

### Identity, authorization, and field minimization

- `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php::handle()` resolves the HttpOnly Identity cookie, rejects invalid UUIDv7 correlation IDs, and permits a fixture-bearer fallback only in local/testing.
- `apps/api/app/Http/Middleware/IdentityCsrfMiddleware.php::handle()` validates the session-bound CSRF proof; fixture-bearer CSRF bypass is testing-only.
- `apps/api/Modules/Identity/Features/Sessions/Handler/SessionHandler.php` stores SHA-256 hashes of 32-byte session/CSRF tokens, enforces expiry, idle timeout, binding, revocation, password version, MFA, and concurrent-session limits.
- `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php` creates `users`, `identity_sessions`, identity claims/idempotency/inbox/watermark/provisioning tables.
- `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php` creates credential/history/activation/TOTP/auth-attempt state; TOTP material is ciphertext and credential rollback is forward-only.
- `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationPeopleTable.php` stores national ID, email, and phone only as ciphertext/hash columns, but employee numbers and display names remain plaintext PII.
- `apps/api/Modules/Authorization/Infrastructure/RbacAbacDecideAccess.php` applies capabilities, scope, classification policies, field templates, export/download policy, obligations, and fail-closed `['*' => 'hidden']` field access.
- `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php::persist()` writes `access_decisions` and confidential/top-secret `sensitive_access_events` transactionally, but logs raw exception messages on persistence failure.
- `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php` makes `sensitive_access_events` append-only with DB triggers.

### Documents, retention, and storage

- `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/CreateDocumentsCoreTables.php` stores classification, `retention_until`, `retention_policy_key`, legal-hold fields, filenames, MIME, checksums, scan state, storage references, idempotency, and outbox data.
- `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php` creates links, restriction facts, and `document_access_events`.
- `apps/api/config/documents.php` currently maps public/internal to 2,557 days, confidential to 3,653 days, and top secret to 5,479 days with an automatic hold. These are current configuration values, not approved legal requirements.
- `apps/api/Modules/Documents/Domain/DocumentRetentionPolicy.php::resolve()` computes retention and requires a reason for an automatic hold.
- `apps/api/Modules/Documents/Features/Upload/DocumentUploadHandler.php` implements private quarantine, checksum/size/MIME verification, fail-closed scan, and promotion.
- `apps/api/config/filesystems.php` configures separate private quarantine/available S3 disks with `aws:kms`; `apps/api/.env.example` currently uses document variable names that do not fully match the quarantine/available names read by `filesystems.php`.
- `apps/api/Modules/Documents/Application/DocumentDownloadService.php::download()` reauthorizes document and linked resources, allows only clean/available versions, and records sensitive downloads.
- `apps/api/Modules/Documents/Features/DocumentAccess/Http/DocumentAccessSupport.php::documentResource()` currently returns title/description/classification/hold reason; each can itself carry PII/PHI.
- `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/TransitionDocumentController.php` updates hold/archive state, idempotency, and access events without one enclosing transaction. Architecture Closure T10 must hand this surface off before P04 relies on it.

### Search, reporting, exports, logging, and browser state

- `apps/api/Modules/Search/Http/SearchController.php` accepts `q` through `GET /api/v1/search`; this places arbitrary search text in a URL.
- `apps/api/app/Http/Middleware/ProjectWorkRecordReadModels.php` copies WorkRecord title/description into Search projection fields. Per-row authorization occurs at read time, but the copied text remains a persistent secondary store.
- `apps/api/Modules/Search/Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php` reauthorizes each result but returns no real cursor.
- `apps/api/Modules/Reporting/Features/ExportAuthorizedReport/Handler/ExportAuthorizedReportHandler.php` duplicates item arrays into plaintext `report_runs.result` and `export_artifacts.safe_result` with a one-day logical expiry.
- `apps/api/Modules/Reporting/Features/DownloadExportArtifact/Handler/DownloadExportArtifactHandler.php` reauthorizes stored rows using hard-coded `internal` classification and returns JSON despite the csv/xlsx/pdf label.
- `apps/api/Modules/PlatformSettings/Domain/TechnicalLogEntry.php::redact()` recursively redacts only exact keys `password`, `token`, `authorization`, `cookie`, `document_content`, and `national_id`.
- `apps/api/Modules/PlatformSettings/Features/Logs/Http/TechnicalLogsController.php` omits context from the HTTP response, while the archive restores encrypted contexts. `technical_log_archive_restore_requests.read_model` has `expires_at`, but no cleanup worker was found.
- `apps/api/Modules/Notifications/Infrastructure/Persistence/Migrations/W18CreateNotificationDeliveryTables.php` stores the original failed event in `notification_dead_letters.original_event`.
- `apps/web/src/api/session.ts::persistSessionMetadata()` writes CSRF token and user ID to `sessionStorage`; this conflicts with the program rule that sensitive credentials/PII do not enter browser persistence.
- `apps/api/database/seeders/DevelopmentJourneyAuthorizationSeeder.php`, `PlatformSettingsE2EAccountSeeder.php`, `apps/api/app/Support/W12E2EFixtureSeeder.php`, and `RealisticClusterFacilitiesSeeder.php` contain fixed/generated testing identities or credentials. They are not production evidence and must remain impossible to execute in production.

### Planned module evidence

- M01 Audit rank 3 owns `audit_events`, `audit_export_jobs`, `audit_integrity_checkpoints`, and `audit_idempotency_keys`; existing `Authorization.sensitive_access_events` remains Authorization-owned until an explicit M01 migration/handoff.
- M02 RecordsGovernance rank 4 owns `records_governance_retention_policy_versions`, `records_governance_retention_policy_rules`, `records_governance_governed_records`, `records_governance_holds`, `records_governance_disposition_reviews`, `records_governance_evidence`, and `records_governance_idempotency_keys`.
- M03–M07 add comments/mentions, strategy evidence and measurements, project/budget/health snapshots, risk assessments/treatments/readings, and workspace preferences. The inventory must reserve these surfaces now and require each module's merge evidence to classify every column/event/schema before P08; this does not add them as P04 start dependencies.

## 4. Scope and explicit non-goals

### In scope

- Column-, JSON-path-, contract-, event-, file-object-, cache-, log-, browser-, backup-, export-, fixture-, and vendor-level inventory.
- Data-flow mapping from collection through storage, projection, access, export, audit, retention, disposition, backup, and deletion.
- Least privilege, facility/unit isolation, classification and field-level minimum necessary rules.
- Audit completeness and integrity evidence through M01 public contracts.
- Retention, hold, disposition, and deletion evidence through M02 public contracts/events.
- P02 storage/KMS/ClamAV/worker evidence review.
- Central log/telemetry redaction rules; outward problem-detail and dead-letter minimization.
- Search URL cutover, browser-persistence removal, export reauthorization/encryption/disposal, incident canaries, environment/fixture separation, and vendor/BAA evidence boundaries.

### Non-goals

- Legal advice, certification, attestation, BAA execution, regulator submission, breach notification, or declaration that Cluster is compliant.
- Creating a `Compliance` module or a P04-owned runtime database.
- Direct SQL/FKs into M01/M02 or another module's tables.
- Editing M01/M02-owned implementation instead of consuming their published Contracts/Events and returning failed acceptance evidence to the owner.
- Adding patient, encounter, clinical-record, consent, or billing domains that do not exist in current source.
- Treating encryption alone, a narrow unit test, fixture data, or a skipped production check as closure.
- Editing generated Orval output by hand, current Architecture Closure shared files without handoff, production topology files owned by P01/P02/P03, or Make/CI files owned by P08.

## 5. Architecture and ownership boundaries

1. The source module remains data controller in code: module-owned controller → authorization before detailed validation/disclosure → handler/service → module-owned persistence/outbox.
2. P04 owns only new compliance registers, validators, evidence scripts, black-box privacy tests, and plan-scoped redaction support. `shared_file_owner: []` remains accurate.
3. Cross-module governance uses M02 Contracts/Events; M02 does not delete source rows. A source module consumes `DispositionExecutionRequestedV1`, calls its own guarded deletion/anonymization handler, and confirms the result with `DispositionOutcomeConfirmedV1`.
4. Audit integration uses M01 `RecordAuditEvent`; P04 never inserts into `audit_events` or reads its tables directly. Evidence queries use `QueryAuditActivity` with opaque IDs, correlation ID, module/type, classification, and time filters only.
5. Search and Reporting own their projections and derived-data disposal. Documents owns object deletion/preservation. Identity and Organization own account/person lifecycle. Authorization owns access decisions and existing sensitive access events.
6. Authorization must run before detailed validation or resource existence disclosure. PHI/PII fields are filtered server-side through `field_access`; hiding only in React is insufficient.
7. A command's state, idempotency, audit, and outbox effects commit atomically. Stale `If-Match` returns 412; reused idempotency key with a different body returns 409.
8. Production adapters fail closed. A missing vendor approval, KMS key, malware scanner, audit recorder, governance guard, or redactor blocks the sensitive operation; no fake production fallback is permitted.

## 6. Files to create, modify, move, or remove

### Phase A — create immediately

- Create: `docs/compliance/privacy-data-inventory.yaml` — canonical item/column/JSON-path inventory.
- Create: `docs/compliance/privacy-data-flows.yaml` — collection, transfer, projection, export, audit, retention, vendor, and deletion flows.
- Create: `docs/compliance/privacy-control-register.yaml` — control IDs, threat cases, owner, status, evidence, and exit criterion.
- Create: `docs/compliance/privacy-vendor-boundaries.yaml` — provider/service boundary and approval evidence references; agreement contents remain outside the repository.
- Create: `docs/compliance/privacy-evidence-manifest.schema.json` — retained manifest schema.
- Create: `scripts/validate-privacy-compliance.py` — exact inventory/control/vendor validator; independent of `scripts/validate-docs.sh`.
- Create: `apps/api/tests/Feature/Privacy/PrivacyComplianceInventoryTest.php` — runtime schema versus inventory reconciliation.

### Phase B — modify only after Architecture Closure/module/UI handoff

- Create: `apps/api/Shared/Infrastructure/Privacy/SensitiveValueRedactor.php`.
- Create: `apps/api/tests/Unit/Shared/Infrastructure/Privacy/SensitiveValueRedactorTest.php`.
- Create: `apps/api/tests/Feature/Privacy/PrivacyLeakRegressionTest.php`.
- Create: `apps/api/tests/Feature/Privacy/MinimumNecessaryAccessTest.php`.
- Modify: `apps/api/Modules/PlatformSettings/Domain/TechnicalLogEntry.php::redact()` to use the same key/value rules without importing another module.
- Modify: `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabasePersistAccessDecision.php::persist()` so failure logs contain an opaque error code/class, decision ID, action, and correlation ID—not exception text or record facts.
- Modify: `apps/web/src/api/session.ts` and `apps/web/src/api.test.ts` to keep CSRF/user identity in memory only.
- Modify: `apps/web/src/App.tsx` and `apps/web/src/App.test.tsx` to restore from the HttpOnly cookie without a sessionStorage marker.
- Modify under `SEARCH-PRIVACY`, owned and applied by the existing Search module integrator after the current Architecture Closure handoff: `apps/api/Modules/Search/Http/SearchController.php`, `apps/api/Modules/Search/Tests/Http/SearchHttpAdapterTest.php`, and Search web wrapper/tests for the POST-body cutover.
- The same `SEARCH-PRIVACY` token serializes `apps/api/routes/web.php` and `docs/contracts/api/openapi.yaml`; only after those route/OpenAPI edits land does its owner run `npm --prefix apps/web run api:generate` to regenerate `apps/web/src/api/generated/cluster.ts` and run `npm --prefix apps/web run api:check`. Generated output is never hand edited.
- Modify: `apps/api/Modules/Reporting/Features/ExportAuthorizedReport/Handler/ExportAuthorizedReportHandler.php`, `DownloadExportArtifact/Handler/DownloadExportArtifactHandler.php`, and Reporting tests so result payloads are encrypted, classification/scope facts survive, and download reauthorization is exact.
- Create: `apps/api/Modules/Reporting/Features/ConsumeDispositionExecutionRequested/Handler/PurgeReportingArtifactHandler.php` after M02 handoff.
- Create: `apps/api/Modules/Search/Features/ConsumeDispositionExecutionRequested/Handler/PurgeSearchProjectionHandler.php` after M02 handoff.
- Modify: module provider/outbox consumer registrations only after the owning module issues a token.

### Phase C — create after M01/M02 completion and P02/P03 evidence publication

- Create: `apps/api/tests/Feature/Privacy/AuditComplianceIntegrationTest.php` using M01 Contracts only.
- Create: `apps/api/tests/Feature/Privacy/RecordsGovernanceComplianceIntegrationTest.php` using M02 Contracts only.
- Create: `apps/api/tests/Feature/Privacy/ProductionPrivacyBoundaryTest.php` consuming P02 credential-rotation and P03 vendor/RPO evidence without editing production topology.
- Create: `scripts/verify-privacy-compliance.sh` — self-contained final runner requiring `--commit <sha>`. It produces two distinct output roots and never conflates them: the P04-independent completion run (the default) writes only `artifacts/privacy-compliance/<sha>/` and binds every artifact to `<sha>`; a P08 program-replay invocation adds `--program-run-id <id> --program-evidence-root <path> --output-root <path>` and atomically creates the absent, unique program-run-scoped replay/output root `<path>`, writing the fresh final-SHA replay manifest (`manifest.json`, `registers/`, `commands/`, `incident-rehearsal/`, dependency hashes) into `<path>` while never writing into or mutating the P04 ancestor root `artifacts/privacy-compliance/<sha>/`. The runner rejects `--program-run-id` without `--output-root`, rejects a pre-existing/symlinked/foreign/traversal `<output-root>`, and never copies the P04 ancestor manifest into the program root.

No files are moved or removed by this plan. Removal of obsolete GET-search declarations and sessionStorage metadata code occurs in the same clean-cutover changes that replace them.

## 7. Public Contracts, Events, routes, schemas, and capability names

### Existing capabilities exercised

`identity.account.read`, `identity.account.manage`, `organization.person.read`, `organization.person.reference`, `organization.person.manage`, `work_record.read`, `work_record.list`, `documents.read`, `documents.list`, `documents.download`, `documents.hold`, `documents.archive`, `search.query`, `reporting.read`, `reporting.run`, `reporting.export`, `reporting.download`, `platform_operations.logs.read`, and `platform_operations.logs.restore`.

P04 creates no capability namespace.

### M01 canonical surface

- Capabilities: `audit.event.read`, `audit.event.export`, `audit.integrity.verify`.
- Contracts: `RecordAuditEvent`, `AuditEventInput`, `AuditEventReceipt`, `QueryAuditActivity`, `AuditActivityQuery`, `AuditActivityPage`, `AuditActivityItem`.
- Events/literals:
  - `AuditEventRecordedV1` → `com.cluster.audit.auditeventrecorded.v1`
  - `AuditExportCompletedV1` → `com.cluster.audit.auditexportcompleted.v1`
  - `AuditIntegrityViolationDetectedV1` → `com.cluster.audit.auditintegrityviolationdetected.v1`
- Reserved prefixes: API `/api/v1/audit`, web `/audit`.

### M02 canonical surface

- Capabilities: `records_governance.retention-policy.read`, `.manage`, `.publish`; `records_governance.record.read`, `.register`; `records_governance.hold.read`, `.manage`; `records_governance.disposition.read`, `.review`, `.confirm`.
- Contracts: `RegisterGovernedRecord`, `GovernedRecordRegistration`, `GovernedRecordStatus`, `ReadGovernedRecordStatus`, `RecordSourceReference`, `GuardDispositionExecution`, `DispositionExecutionDecision`, `QueryRecordsGovernanceSummary`, `RecordsGovernanceSummaryQuery`, `RecordsGovernanceSummary`.
- Events/literals:
  - `RetentionPolicyVersionPublishedV1` → `com.cluster.recordsgovernance.retentionpolicyversionpublished.v1`
  - `GovernedRecordStatusChangedV1` → `com.cluster.recordsgovernance.governedrecordstatuschanged.v1`
  - `RecordHoldChangedV1` → `com.cluster.recordsgovernance.recordholdchanged.v1`
  - `DispositionExecutionRequestedV1` → `com.cluster.recordsgovernance.dispositionexecutionrequested.v1`
  - `DispositionOutcomeConfirmedV1` → `com.cluster.recordsgovernance.dispositionoutcomeconfirmed.v1`
- Reserved prefixes: API `/api/v1/records-governance`, web `/records-governance`.

The event literal rule is exact: `com.cluster.<unhyphenated-lowercase-module-token>.<lowercase-event-class-without-V1>.v1`.

### P04 HTTP/API cutover

- Replace `GET /api/v1/search?q=...&scope_id=...&limit=...` with `POST /api/v1/search`.
- Request schema `PrivacySearchRequest`:

```yaml
type: object
additionalProperties: false
required: [query]
properties:
  query: {type: string, minLength: 1, maxLength: 256}
  scope_id: {$ref: '#/components/schemas/UUIDv7'}
  cursor: {type: string, minLength: 1, maxLength: 4096}
  limit: {type: integer, minimum: 1, maximum: 100, default: 25}
```

- Authentication remains session-cookie based. Because search is read-only, no CSRF or Idempotency-Key is added. `X-Correlation-ID` remains required; responses remain `application/problem+json` on error and cursor-paginated on success.
- The old GET operation and generated function are removed in the same contract queue token. No alias or dual route remains.

## 8. Database inventory, indexes, constraints, migration order, and recovery

P04 creates no database table and claims no table ownership. The inventory validator must cover every migrated table/column and every structured leaf inside JSON/ciphertext/projection fields.

### Classification dimensions

Authorization classification and privacy category are separate fields:

- `authorization_classification`: `public | internal | confidential | top_secret | dynamic`.
- `privacy_categories`: one or more of `none`, `pii`, `phi`, `conditional_phi`, `credential`, `security_audit`, `financial`, `operational_sensitive`.
- `storage_form`: `plaintext`, `hash`, `ciphertext`, `opaque_id`, `derived_projection`, `external_object`.
- `minimum_access`: capability, scope key, field policy, and purpose string.

`conditional_phi` means content may become PHI depending on the submitted value; it is governed as PHI until a validated schema proves otherwise.

### Required inventory method

1. Enumerate every `Schema::create`/`Schema::table` under `apps/api/Modules/*/Infrastructure/Persistence/Migrations/*.php` and reconcile it against a migrated runtime database in `PrivacyComplianceInventoryTest` using Laravel schema introspection.
2. Enumerate every OpenAPI request/response property in `docs/contracts/api/openapi.yaml`, every event property in `docs/contracts/schemas/*.schema.json`, browser persistence key, cache key, log context, object-store metadata/content, export field, fixture field, and planned M00 table/event reservation.
3. For JSON/text/ciphertext fields such as `work_records.payload`, `search_index_entries.search_text`, `report_read_models.safe_data`, outbox/dead-letter payloads, and document content, add `table.column#json.path` entries for defined paths and a wildcard entry with `conditional_phi` plus fail-closed field policy.
4. Every inventory entry must include `id`, `owner_module`, `path`, `privacy_categories`, `authorization_classification`, `storage_form`, `purpose`, `collection_source`, `scope_keys`, `capabilities`, `field_policy`, `flows`, `retention_policy_source`, `legal_hold_source`, `encryption_at_rest`, `encryption_in_transit`, `audit_actions`, `deletion_owner`, `vendor_boundary`, and `evidence`.
5. The validator fails on a runtime column or contract/event property without an entry, a stale entry without source, a sensitive entry without scope/capability/audit/retention/deletion data, any URL/browser/log flow carrying PHI/PII, or any approved external-PHI flow without agreement evidence metadata.

The first inventory must explicitly include Identity credentials/sessions, Organization people/import payloads, WorkRecord payloads, Documents metadata/content/storage/holds/access events, Search projections, Reporting runs/exports, Authorization decisions/sensitive events, technical log archive/restore models, notification dead letters, outbox/event schemas, Redis/cache/session metadata, fixtures, database/backup copies, and all M01–M07 planned tables/events.

### M01/M02 constraints and order

M01 and M02 own their migrations and rollback. P04 verifies, but does not author, these required properties:

- M01 audit rows append-only; actor/resource/correlation/time indexes; idempotency uniqueness; integrity checkpoints; retention metadata; expired-segment purge that leaves checkpoint proof.
- M02 published policy versions immutable; one active policy selection per governed type/scope; active holds prevent disposition; disposition review/confirm uses optimistic concurrency and idempotency; source-reference uniqueness prevents duplicate governed records.
- Migration order: M01 and M02 migrations first; producer contract bindings second; P04 black-box tests third; source-owned projection/export cleanup consumers last.

### Reporting/Search derived-data changes

No direct M02 FK is added. Reporting encrypted payloads retain source ID, source type, scope, classification, and field-policy key inside ciphertext so each download reauthorizes exact facts. `report_runs.result` no longer stores item content. Expired or disposition-requested artifacts are deleted by Reporting, and Search deletes its own projection row. Both handlers are idempotent and confirm only their own outcome.

### Recovery and rollback constraints

- Never roll back by dropping audit, hold, governance-evidence, credential, or encrypted source data.
- A failed encryption migration leaves the old column read-only and the export endpoint disabled until every row is verified; it never falls back to plaintext writes.
- A failed disposition consumer retries the same idempotency key and leaves the governed record pending; it never reports deletion before source-owner confirmation.
- Restore operations must restore holds and governance state before enabling disposition workers, then rebuild Search/Reporting only from authorized source events.

## 9. TDD implementation tasks

### Task 1: Build the immediate inventory and threat register

**Files:** Create the four YAML registers, manifest schema, validator, and `PrivacyComplianceInventoryTest.php` listed in Section 6.

- [ ] **Step 1: Write the failing inventory gate**

Add `PrivacyComplianceInventoryTest::test_every_runtime_table_column_and_sensitive_contract_path_is_classified()` to boot a migrated database, enumerate tables/columns, run `python3 scripts/validate-privacy-compliance.py --inventory docs/compliance/privacy-data-inventory.yaml --flows docs/compliance/privacy-data-flows.yaml --controls docs/compliance/privacy-control-register.yaml --vendors docs/compliance/privacy-vendor-boundaries.yaml`, and assert exit 0.

Run:

```bash
cd apps/api && php artisan test tests/Feature/Privacy/PrivacyComplianceInventoryTest.php
```

Expected red result: FAIL because the registers/validator do not exist.

- [ ] **Step 2: Create the exact schemas and seed observed entries**

Use the fields in Section 8. Seed every currently observed store and each M00-planned table/event; classify WorkRecord payloads, document content/metadata, search text, report/export payload, dead letters, logs, and planned free-text fields as `conditional_phi` by default. Record hardcoded document retention values with `policy_status: engineering-default-unapproved`, not as a legal requirement.

- [ ] **Step 3: Implement exact reconciliation**

`validate-privacy-compliance.py` exits 1 for missing/stale items, unsupported enum values, absent flow references, sensitive URL/browser/log sinks, external-PHI flows without an approved evidence reference, or a terminal control without command/source evidence. It exits 0 only when all registers reconcile.

Run the command from Step 1.

Expected green result: PASS and output `P04 inventory: complete; unclassified=0; stale=0; unsafe_flows=0`.

- [ ] **Step 4: Register evidenced threat cases**

Create non-terminal controls for the exact cases in Section 10. A threat may close only with the named test/command. Any additional source-verified issue receives the next `C` identifier with source and exit criterion; never recreate unsourced historical `F` entries.

### Task 2: Make logs, errors, telemetry, and dead letters non-disclosing

**Files:** Create redactor/tests; modify TechnicalLogEntry and DatabasePersistAccessDecision after handoff; add source-owner tests for dead-letter/event minimization.

- [ ] **Step 1: Write red tests with synthetic canaries**

Use these non-real values only:

```text
P04-CANARY-NATIONAL-ID-9000000000
p04-canary@example.invalid
+966500000004
P04-CANARY-DIAGNOSIS
P04-CANARY-CSRF-SECRET
```

Test exact key aliases (`email`, `phone`, `employee_number`, `patient_name`, `request_body`, `headers`, `query`, `filename`, `description`, `csrf_token`, `session_id`, `access_token`), nested arrays/objects, URL query strings, bearer/cookie values, national-ID/email/phone patterns, and an exception containing a canary.

Run:

```bash
cd apps/api && php artisan test tests/Unit/Shared/Infrastructure/Privacy/SensitiveValueRedactorTest.php tests/Feature/Privacy/PrivacyLeakRegressionTest.php
```

Expected red result: FAIL because current redaction covers only six exact keys and exception text reaches the authorization error log.

- [ ] **Step 2: Implement allowlist-first redaction**

`SensitiveValueRedactor::redact(array $context): array` recursively keeps approved operational keys (`correlation_id`, opaque UUIDv7 IDs, event type, status/code, bounded count/duration), replaces sensitive keys/values with `[REDACTED]`, strips URL query/fragment, and truncates unrecognized strings. It must never hash low-entropy PHI such as phone/national ID into logs; it removes it.

`TechnicalLogEntry::redact()` must apply identical rules through a PlatformSettings-owned adapter or the shared pure service without importing another module's Domain/Infrastructure. `DatabasePersistAccessDecision` logs `exception_class` and a stable error code, never `getMessage()`.

- [ ] **Step 3: Enforce event/dead-letter minimization**

Extend producer tests to prove Identity/outbox/M01/M02 event schemas allow only opaque references, versions, classification, reason codes, and correlation IDs. `notification_dead_letters.original_event` may hold only a schema-validated minimized event; a malformed/unregistered payload is rejected before persistence.

Run the Step 1 command plus:

```bash
cd apps/api && php artisan test Modules/Identity/Tests Modules/Notifications/Tests Modules/PlatformSettings/Tests/TechnicalLogsHandlerTest.php
```

Expected: PASS; canaries absent from response bodies, captured logs, archived technical contexts, outbox rows, and dead letters.

### Task 3: Remove URL and browser-persistence exposure

**Files:** Modify web session/App files directly after their UI handoff. The existing Search module integrator exclusively owns the route, OpenAPI, generated-client, Search controller/test, and Search web wrapper/test cutover under `SEARCH-PRIVACY` after the current Architecture Closure handoff.

- [ ] **Step 1: Write browser and Search red tests**

The P04 executor adds browser assertions that login/restore never calls `sessionStorage.setItem` or `localStorage.setItem` with CSRF/user/session data. After `ARCHITECTURE-CLOSURE:T12/T13-HANDOFF`, the Search module integrator acquires `SEARCH-PRIVACY`, updates `SearchHttpAdapterTest.php` and `SearchReporting.repro.test.tsx`, and proves a Search request sends `PrivacySearchRequest` in a POST body with no query string.

Run under the token:

```bash
npm --prefix apps/web run test:unit -- src/api.test.ts src/App.test.tsx src/features/r1/SearchReporting.repro.test.tsx
cd apps/api && php artisan test Modules/Search/Tests/Http/SearchHttpAdapterTest.php
```

Expected red result: session metadata is persisted and Search accepts GET query parameters.

- [ ] **Step 2: Perform the browser cutover**

The P04 executor removes `persistSessionMetadata()` and the stored-session marker dependency; boot always attempts cookie-backed `getCurrentIdentity()` then `refreshIdentityCsrf()`. CSRF/user/session facts remain in React memory only.

- [ ] **Step 3: Apply `SEARCH-PRIVACY` cleanly**

After the current Architecture Closure route/contract handoff, the existing Search module integrator records owner, token, paths, and candidate SHA; changes `SearchController.php` and `routes/web.php` to accept only POST JSON `query/scope_id/cursor/limit`; rejects GET and unknown keys; replaces the OpenAPI operation; removes the obsolete GET operation and wrapper; regenerates rather than edits `apps/web/src/api/generated/cluster.ts`; and releases `SEARCH-PRIVACY` only after:

```bash
npm --prefix apps/web run api:generate
npm --prefix apps/web run api:check
cd apps/api && php artisan test Modules/Search/Tests/Http/SearchHttpAdapterTest.php
npm --prefix apps/web run test:unit -- src/features/r1/SearchReporting.repro.test.tsx
```

Expected: every command exits 0, repeated generation has zero drift, generated client exposes POST search only, and the handoff record names the owner, token, paths, output hashes, and candidate SHA.

- [ ] **Step 4: Verify the user flow**

Run Step 1 commands again.

Expected: PASS; browser history/location contains no search term; Storage contains presentation preferences only; session restore succeeds from the HttpOnly cookie; GET `/api/v1/search?q=P04-CANARY-DIAGNOSIS` returns 405/404 and POST returns authorized results.

### Task 4: Enforce minimum necessary and safe export lifecycle

**Files:** Create `MinimumNecessaryAccessTest`; modify Search/Reporting handlers/tests; add source-owned disposition consumers after M02.

- [ ] **Step 1: Write failing cross-scope/field/export tests**

Create two facilities, two confidential records, masked/hidden fields, a canary value, and actors with read/export/download capabilities in only one scope. Assert authorization precedes existence/detail validation, cross-scope lists/search/reports return zero rows, masked/hidden fields never enter projections or exports, and revoked access blocks download.

Assert stored `report_runs.result` contains no items, `export_artifacts.safe_result` contains no plaintext canary, and download reauthorizes the original per-item classification rather than `internal`.

Run:

```bash
cd apps/api && php artisan test tests/Feature/Privacy/MinimumNecessaryAccessTest.php Modules/Search/Tests Modules/Reporting/Tests Modules/WorkRecords/Tests/GetAuthorizedWorkRecordFieldMaskingTest.php Modules/WorkRecords/Tests/ListAuthorizedWorkRecordsFieldMaskingTest.php
```

Expected red result: Reporting stores plaintext duplicated payloads and download hard-codes classification.

- [ ] **Step 2: Filter before projection and encrypt export payloads**

Project only schema-allowlisted fields after field-access decisions. Store report metadata/count only in `report_runs`; store encrypted item arrays in `export_artifacts.safe_result`, including opaque source ID/type, scope, original classification, and field-policy key. On download: decrypt, re-resolve source facts, reauthorize `reporting.download`/source read, reapply field masking, record M01 audit, and return no data when facts are unavailable.

- [ ] **Step 3: Implement actual expiry/disposition**

Reporting and Search consume M02 disposition requests through their own handlers. Each verifies `GuardDispositionExecution`, deletes only its own derived rows/artifacts, uses the event/idempotency key, and emits/records a non-PHI outcome. Expired exports are purged, not merely hidden by `expires_at`.

- [ ] **Step 4: Verify authorization, retry, and disposal**

Run the Step 1 command twice, including the same disposition event twice.

Expected: PASS; second delivery is a no-op with the same outcome; held records remain; expired artifact row/ciphertext is absent; audit shows create/download/deny/dispose by opaque IDs.

### Task 5: Gate M01 audit completeness and integrity

**Files:** Create `AuditComplianceIntegrationTest.php`; consume M01 contracts only.

- [ ] **Step 1: Write the M01-gated test before binding**

Record synthetic create/read/update/download/search/export/hold/disposition/deny events with correlation/session/actor/original actor, resource type/opaque ID, action, classification, purpose/reason code, outcome, scope, and timestamp. Query with `QueryAuditActivity`; assert no payload/name/email/phone/search text/filename appears.

Run:

```bash
cd apps/api && php artisan test tests/Feature/Privacy/AuditComplianceIntegrationTest.php
```

Expected while M01 is unavailable: FAIL with the missing production M01 binding. Do not supply a fake outside the test itself.

- [ ] **Step 2: Verify completeness, export, and integrity**

After M01 completion, exercise `audit.event.read`, `audit.event.export`, and `audit.integrity.verify`; query by opaque resource/actor/correlation/module/type/classification/time with an opaque cursor. Audit export must be idempotent, scope-bound, reason-audited, re-redacted, and contain no PII/PHI filters in URLs. Attempt update/delete to prove append-only enforcement; run integrity verification, expire an eligible segment, purge it, and prove checkpoint continuity.

Expected green result: PASS; M01 events use the exact literals in Section 7; no raw data enters audit rows/exports; integrity failure emits `AuditIntegrityViolationDetectedV1` and blocks successful verification.

### Task 6: Gate M02 retention, deletion, and legal hold

**Files:** Create `RecordsGovernanceComplianceIntegrationTest.php`; consume M02 contracts/events only.

- [ ] **Step 1: Write hold/disposition red tests**

Register a document, work record, report artifact, and search projection through `RegisterGovernedRecord`. Assert `ReadGovernedRecordStatus` reports the published policy and effective hold; active record or scope hold makes `GuardDispositionExecution` deny; source-owned hold also wins; unavailable governance fails closed.

Run:

```bash
cd apps/api && php artisan test tests/Feature/Privacy/RecordsGovernanceComplianceIntegrationTest.php
```

Expected while M02 is unavailable: FAIL with missing production M02 binding.

- [ ] **Step 2: Replace engineering defaults with published policy resolution**

After legal/privacy owner approval is recorded outside code, map each source record type/classification/jurisdiction to an immutable M02 published policy version. Documents keeps a denormalized effective date/policy key for enforcement but M02 is the policy authority. A missing/ambiguous policy blocks disposition and marks the control non-terminal; no arbitrary default period is substituted.

- [ ] **Step 3: Verify two-phase disposal**

M02 emits `DispositionExecutionRequestedV1`; source owners re-check current version and holds, dispose/anonymize only their own data/object/projection, then confirm with `DispositionOutcomeConfirmedV1`. The test covers retry, stale version, partial source failure, legal hold, hold release, confirmed deletion, and retained non-PHI evidence.

Expected green result: PASS; no held content is deleted; source data disappears only after source-owner success; governance and audit evidence remains.

### Task 7: Verify production environment, encryption, secrets, fixtures, and vendors

**Files:** Create `ProductionPrivacyBoundaryTest.php`; update compliance/vendor registers. Do not modify `infra/platform/production/compose.yaml` or `.env.example`.

- [ ] **Step 1: Write production-boundary red tests**

Assert production boot fails when APP key, DB/Redis transport requirement, KMS key, distinct document credentials/buckets, ClamAV, worker identity, approved log sink, or vendor boundary is absent. Assert every development seeder/fixture endpoint/bearer/credential output refuses production.

Run:

```bash
cd apps/api && php artisan test tests/Feature/Privacy/ProductionPrivacyBoundaryTest.php tests/Feature/ProductionAuthorizationBindingTest.php tests/Feature/DocumentsRuntimeProviderTest.php tests/Feature/W12E2EDocumentUploadRuntimeTest.php
```

Expected before dependency evidence: FAIL with exact missing P02 credential-rotation or P03 vendor/RPO evidence identifiers; never skip and never assign remediation to P04.

- [ ] **Step 2: Consume P02 credential-rotation evidence**

P02 publishes a commit-bound immutable manifest with paths and SHA-256 hashes proving HTTPS/TLS endpoints, private buckets, distinct least-privilege credentials, KMS keys/rotation owner, signed URL TTL/referrer policy, ClamAV version/signature/update/health, quarantine deletion, worker credential rotation/scope/expiry/failure/audit, and access logs. P04 validates the P02 manifest schema, candidate commit/ancestor relationship, and referenced hashes, then records those references in its manifest; P02 retains topology and rotation ownership. Missing or invalid evidence blocks P04 without creating P04-owned implementation work.

- [ ] **Step 3: Consume P03 vendor and RPO evidence**

P03 publishes a commit-bound immutable manifest for database, Redis, S3/KMS, ClamAV, backup store, mail, error monitoring, technical-log archive, support tools, and any LLM/analytics service, including provider/legal entity, service/region, data categories, purpose, subprocessors, retention/deletion, incident contact, agreement/BAA decision, approval owner/date/expiry, restricted evidence hash, backup encryption/restore proof, and declared/tested RPO. P04 validates and references that evidence; it does not procure vendors, execute agreements, configure backups, or establish RPO.

Allowed active-flow states remain `non_phi_only` or `approved_for_declared_scope`; unknown or `blocked` boundaries fail P04. Agreement contents and PHI samples never enter the repository, and no legal sufficiency claim is made.

- [ ] **Step 4: Verify environment separation**

Run the Step 1 command after consuming P02/P03 manifests.

Expected green result: PASS; production has no fixture routes/credentials, debug/request-body logging, public PHI disk, test adapter, unapproved external PHI sink, stale credential-rotation evidence, or missing vendor/RPO proof. Local/testing artifacts are labeled synthetic and excluded from production evidence.

### Task 8: Rehearse incident evidence and produce the final manifest

**Files:** Create `scripts/verify-privacy-compliance.sh`; finalize control/vendor registers and manifest schema.

- [ ] **Step 1: Make the final runner fail closed and commit-bound**

Implement `scripts/verify-privacy-compliance.sh --commit <sha>` with a required full 40-lowercase-hex commit. It must reject a dirty worktree, reject when `git rev-parse HEAD` differs from `<sha>`, and never derive or substitute another commit. For P04-independent completion the runner creates only `artifacts/privacy-compliance/<sha>/`, executes every targeted command in Section 11 against that checkout, captures stdout/stderr separately, hashes every output/register and consumed P01–P03/M01/M02 manifest, and writes `<sha>` into every command record plus top-level `commit`. For P08 program-replay the runner accepts `--program-run-id`, `--program-evidence-root`, and `--output-root`; it refuses to write outside `--output-root`; it never derives or substitutes any commit; and it emits `$PROGRAM_EVIDENCE_ROOT/replay/privacy/manifest.json` (the path recorded as `privacy_replay_manifest_path` in the P08 closure) carrying `program_run_id`, `commit_sha` (must equal `<sha>`), register hashes, dependency hashes, and incident rehearsal digest. P04 owns both modes; the two modes never share filesystem paths or write into each other's roots. It exits non-zero on failed/skipped commands, stale/missing registers, open critical threats, active blocked vendors, invalid dependency evidence, absent hashes, generated-client drift, or any path/record commit mismatch.

Run:

```bash
bash scripts/verify-privacy-compliance.sh --commit "$(git rev-parse HEAD)"
```

Expected red result before all gates: non-zero with exact unmet control/dependency IDs; no PASS manifest.

- [ ] **Step 2: Run the synthetic incident rehearsal**

Inject the five canaries through rejected login, person/work-record payload, document metadata, search, report export, exception/log, outbox/dead-letter, technical-log archive/restore, and disposition paths. Evidence must show detection by correlation ID, containment by disabling the affected sink/export, scope of stores checked, no canary in outward response/URL/browser/log/audit/vendor payload, and preserved audit/governance evidence. Do not use real person data.

- [ ] **Step 3: Generate, validate, and publish retained evidence**

Run `bash scripts/verify-privacy-compliance.sh --commit "<recorded-sha>"` after all P04 gates. Expected: exit 0; `artifacts/privacy-compliance/<recorded-sha>/manifest.json` conforms to the schema; every artifact path is beneath that SHA directory; every command/register/dependency record carries that SHA and a hash; every threat is closed or validly accepted; and no active PHI flow uses an unknown/blocked vendor. Publish the immutable manifest and artifact hashes to the orchestration evidence index for later P08 replay.

- [ ] **Step 4: Record P04 completion after its own evidence passes**

After explicit user authorization, set `implementation_commit` and `last_verified_commit` to the recorded SHA and transition P04 `verification → completed` once the commit-bound manifest is validated and immutably published. P08 acceptance is not a P04 completion gate; P08 later consumes the published manifest and reruns critical verifiers on final integrated HEAD.

## 10. Failure, retry, idempotency, concurrency, authorization, and threat cases

### Required behavior

- Authentication failure: generic 401; no username/account/record detail.
- Authorization failure: check before detailed validation/existence; 403 with generic problem detail and correlation ID.
- Stale mutation/hold/disposition: 412 from write predicate using `If-Match`/`lock_version`.
- Idempotency reuse with a different canonical body: 409; no second state/audit/outbox effect.
- Audit unavailable/integrity invalid: sensitive mutation/export/download fails closed; ordinary denied attempt still has minimal operational evidence without PHI.
- Governance unavailable/ambiguous: disposition fails closed and remains pending.
- Storage/scanner unavailable: object remains quarantined; no signed download grant.
- Export decryption/facts failure: return generic unavailable/forbidden; never return cached plaintext.
- Redactor failure: drop unrecognized context and retain correlation/error code only.
- Event retry: inbox/idempotency prevents duplicate effect; poison event moves only a minimized schema-validated envelope to dead letter.

### Explicit threat/leak cases

| ID | Case | Required proof |
|---|---|---|
| P04-T01 | Search text in GET URL/history/access log | POST-only API/web tests; GET unavailable; canary absent from URL/log |
| P04-T02 | CSRF/user/session data in browser persistence | session/App tests assert no sensitive Storage writes |
| P04-T03 | Cross-facility/person directory disclosure | confidential person list/get tests with two facilities and server-side row scope |
| P04-T04 | WorkRecord title/description copied to Search without field minimization | projection test proves masked/hidden/unmapped fields absent before persistence |
| P04-T05 | Document title/description/filename/hold reason disclosure | list/get/download tests with field/capability differentiation |
| P04-T06 | Signed download URL exposure/replay | short TTL, one principal/version, no filename, Referrer-Policy, audit, expiry/revocation evidence |
| P04-T07 | Hold/archive state commits without idempotency/audit/outbox | atomicity failure injection and zero partial effects |
| P04-T08 | Sensitive-access decision linkage synthesized or incomplete | same decision/correlation ID across Authorization/M01/Documents evidence |
| P04-T09 | Exact-key redaction misses aliases or sensitive values | nested key/value canary matrix across logs/archive/errors |
| P04-T10 | Plaintext/duplicate report/export result and wrong classification on download | DB plaintext absence, exact fact reauthorization, actual purge |
| P04-T11 | Technical-log restored read model outlives expiry | cleanup/disposition test and retained non-content evidence |
| P04-T12 | Full producer event copied to dead letter/vendor telemetry | schema minimization and canary absence |
| P04-T13 | Realistic/fixed fixtures or credentials reach production | production boot/seeder/route/UI refusal tests |
| P04-T14 | Static document worker credential lacks rotation/scope/audit | P02 evidence for rotation, scope, expiry, failure, and audit |
| P04-T15 | Hardcoded retention treated as legal authority | M02 published-policy resolution and approved evidence reference |
| P04-T16 | Audit export exposes PHI/PII filters or rows | M01 opaque filters, scope, re-redaction, reason, idempotency, integrity tests |
| P04-T17 | Active legal/source hold deleted on retry or stale request | M02/source guard, version, idempotency, two-phase confirmation tests |
| P04-T18 | External service receives PHI without approved boundary evidence | vendor register validator and production boundary test |

A threat closes only with current source/command evidence on the final commit. Legal-risk acceptance requires a named authorized owner and expiry; P0/P1 technical leaks may not be accepted merely to complete P04.

## 11. Targeted verification commands and smoke scenarios

The executor runs these commands; this drafting session runs none of them.

```bash
python3 scripts/validate-privacy-compliance.py \
  --inventory docs/compliance/privacy-data-inventory.yaml \
  --flows docs/compliance/privacy-data-flows.yaml \
  --controls docs/compliance/privacy-control-register.yaml \
  --vendors docs/compliance/privacy-vendor-boundaries.yaml

cd apps/api && php artisan test \
  tests/Feature/Privacy/PrivacyComplianceInventoryTest.php \
  tests/Feature/Privacy/PrivacyLeakRegressionTest.php \
  tests/Feature/Privacy/MinimumNecessaryAccessTest.php \
  tests/Feature/Privacy/AuditComplianceIntegrationTest.php \
  tests/Feature/Privacy/RecordsGovernanceComplianceIntegrationTest.php \
  tests/Feature/Privacy/ProductionPrivacyBoundaryTest.php

cd apps/api && php artisan test \
  Modules/Organization/Tests/OrganizationPersonHttpAdapterTest.php \
  Modules/Authorization/Tests/RbacAbacDecideAccessTest.php \
  Modules/Authorization/Tests/AuthorizationFieldAuditMigrationTest.php \
  Modules/Documents/Tests/Http/DocumentListingControllerTest.php \
  Modules/Documents/Tests/Http/DownloadDocumentControllerTest.php \
  Modules/Documents/Tests/Http/DocumentsHttpControllerTest.php \
  Modules/Search/Tests \
  Modules/Reporting/Tests \
  Modules/PlatformSettings/Tests/TechnicalLogsHandlerTest.php

npm --prefix apps/web run test:unit -- \
  src/api.test.ts \
  src/App.test.tsx \
  src/features/r1/SearchReporting.repro.test.tsx \
  src/features/platform-settings/TechnicalLogsScreen.test.tsx

npm --prefix apps/web run api:check
bash scripts/verify-privacy-compliance.sh --commit "$(git rev-parse HEAD)"
```

Expected: every command exits 0; no skip; Orval has zero drift; every artifact and manifest record is bound to the explicit HEAD SHA.

### Smoke scenario

1. Log in as a facility-A principal and confirm no session/CSRF/user data is stored in browser Storage.
2. POST a canary Search body; confirm browser URL/history is unchanged, only facility-A rows appear, and masked fields are absent.
3. Request a confidential document from facility B; confirm generic 403 before resource detail. Request an authorized clean document; confirm short-lived opaque signed URL, no filename/query referrer leakage, and M01 audit correlation.
4. Create an export containing a masked field; inspect DB/log evidence to confirm no plaintext canary; revoke access and confirm download is denied.
5. Place a M02 hold, issue disposition twice, and confirm no source/projection/object deletion. Release with authorization/version/reason, approve disposition, and confirm source-owned deletion plus retained M01/M02 evidence.
6. Restore technical logs containing a nested canary; confirm redaction and expiry cleanup.
7. Attempt production fixture seeding and a configuration with missing KMS/ClamAV/vendor evidence; confirm fail-closed startup/operation.

## 12. Shared-file integration tokens and handoff requirements

- `SEARCH-PRIVACY`: after `ARCHITECTURE-CLOSURE:T12/T13-HANDOFF`, the existing Search module integrator exclusively owns the clean GET-to-POST cutover across `apps/api/Modules/Search/Http/SearchController.php`, `apps/api/Modules/Search/Tests/Http/SearchHttpAdapterTest.php`, `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, Search web wrapper/tests, and generated `apps/web/src/api/generated/cluster.ts`. Release requires the exact tests and generation checks in Task 3 plus owner/path/output-hash/candidate-SHA handoff evidence.
- Generated clients remain generation-only: the `SEARCH-PRIVACY` owner runs `npm --prefix apps/web run api:generate`; nobody hand edits `apps/web/src/api/generated/cluster.ts`.
- `PLATFORMSETTINGS-RETENTION`: after M02 publishes disposition Contracts/Events and the current PlatformSettings handoff releases its files, the PlatformSettings module integrator owns technical-log archive cleanup, including `technical_log_archive_restore_requests` expiry/disposition handler, provider/consumer registration, and PlatformSettings tests. It consumes `GuardDispositionExecution` and `DispositionExecutionRequestedV1` through public M02 Contracts/Events, deletes only PlatformSettings-owned restored read models idempotently, and confirms through `DispositionOutcomeConfirmedV1`; P04 supplies the threat/control packet and black-box assertion but never edits PlatformSettings internals under this token. Release requires `cd apps/api && php artisan test Modules/PlatformSettings/Tests/TechnicalLogsHandlerTest.php` plus expiry, held-record, duplicate-delivery, and outcome-confirmation tests, with owner/path/output-hash/candidate-SHA handoff evidence.
- Module registry/ranks/planned inventory/table ownership: M00 defines them; P04 never edits `ModuleBoundariesTest.php`.
- M01/M02 files: their owners complete and hand off; P04 consumes public Contracts/Events and adds black-box tests, not direct table access.
- P02/P03: P04 consumes immutable, commit-bound P02 credential-rotation and P03 vendor/RPO manifests; P04 does not edit production topology, implement credential rotation, procure vendors, or own backup/RPO work.
- `Makefile` and CI workflows: P08 only after T13 handoff. P04 publishes `bash scripts/verify-privacy-compliance.sh --commit <sha>` evidence independently; P08 later replays it on final integrated HEAD.
- Web files shared with P05/P06 or active modules require a non-overlapping UI token and stable surface; no parallel edit is assumed.

## 13. Rollback procedure

1. Stop sensitive exports, Search, log restore, and disposition workers before rollback; leave ordinary authenticated source reads available only if their authorization/audit path remains intact.
2. Preserve all M01 audit rows/checkpoints, M02 policy/hold/evidence rows, document access events, and source data. Never delete evidence to make rollback pass.
3. Revert P04 application changes as one reviewed unit only when the prior behavior does not reintroduce a known leak. Specifically, do not restore GET Search, plaintext exports, raw exception logging, or browser session persistence; disable that feature instead.
4. For Reporting encrypted payload failure, disable create/download, preserve ciphertext and metadata, restore from the last verified backup, and retry migration. Do not write plaintext.
5. For disposition consumer failure, stop consumption, keep pending state, repair/replay the same idempotency key, and confirm only after source-owner success.
6. M01/M02/P02 migrations/topology follow their owning plans' recovery procedures. P04 does not execute cross-owner rollback SQL.
7. Re-run the privacy inventory validator and targeted tests after rollback. Record rollback command output/hash in the same evidence schema and leave P04 `blocked` or `in_progress`, never `completed`.

## 14. Exit criteria and retained evidence

P04 may complete only when all of the following are true on one explicit recorded commit:

- Runtime migrations, OpenAPI/event schemas, browser storage, logs, caches, object stores, exports, fixtures, vendor boundaries, and all M00 planned surfaces reconcile with zero unclassified/stale items.
- Every flow identifies purpose, minimum scope/capability/field policy, audit actions, retention/hold/deletion owner, encryption, and vendor boundary.
- P04-T01 through P04-T18 have terminal evidence; no critical leak is open or silently accepted.
- M01 audit completeness, redaction, idempotent export, append-only/integrity, and retained-checkpoint purge pass through public contracts.
- M02 published policy, source/record/scope hold, fail-closed guard, optimistic concurrency, two-phase source-owned disposition, retry, and evidence pass through public contracts/events.
- P02 credential-rotation/private-storage/KMS/ClamAV/worker evidence and P03 vendor/RPO evidence are validated and retained by immutable manifest reference; no unavailable/in-memory production adapter is bound.
- `SEARCH-PRIVACY` handoff proves Search is POST-body only, generated client has zero drift, and exact route/OpenAPI/client tests pass on the recorded SHA.
- `PLATFORMSETTINGS-RETENTION` handoff proves technical-log archive restored read models expire/dispose idempotently through M02 public Contracts/Events, including hold and duplicate-delivery tests.
- Browser persistence carries presentation preferences only, and outward errors/logs/audit/dead letters/vendor payloads contain no canary.
- Reporting stores no plaintext duplicate item arrays, reauthorizes exact current facts at download, and physically purges expired/disposed artifacts.
- Fixtures are synthetic, production-inaccessible, and excluded from production evidence.
- Vendor/BAA register contains no unknown or blocked boundary used by an active PHI flow; this is an engineering gate, not a legal sufficiency claim.
- The smoke scenario passes; `verify-privacy-compliance.sh --commit <recorded-sha>` exits 0; every retained artifact is hash- and commit-bound beneath `artifacts/privacy-compliance/<recorded-sha>/`; and the immutable completion manifest is published for later P08 consumption.

Retain `artifacts/privacy-compliance/<recorded-sha>/manifest.json` with this shape:

```json
{
  "plan_id": "P04",
  "commit": "40-lowercase-hex",
  "started_at": "UTC-date-time",
  "finished_at": "UTC-date-time",
  "commands": [{"name": "privacy-inventory", "command": "python3 scripts/validate-privacy-compliance.py ...", "commit": "40-lowercase-hex", "exit_code": 0, "stdout_path": "privacy-inventory.stdout", "stdout_sha256": "64-lowercase-hex", "stderr_path": "privacy-inventory.stderr", "stderr_sha256": "64-lowercase-hex"}],
  "registers": [{"path": "docs/compliance/privacy-data-inventory.yaml", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex"}],
  "dependency_manifests": [{"plan_id": "P01", "path": "artifacts/production-topology/40-lowercase-hex/manifest.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex", "relationship": "exact_or_ancestor_of_recorded_commit"}, {"plan_id": "P02", "path": "artifacts/documents-production/40-lowercase-hex/manifest.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex", "relationship": "exact_or_ancestor_of_recorded_commit"}, {"plan_id": "P03", "path": "artifacts/production-readiness/40-lowercase-hex/manifest.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex", "relationship": "exact_or_ancestor_of_recorded_commit"}, {"plan_id": "M01", "path": "artifacts/module-completion/M01/40-lowercase-hex/manifest.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex", "relationship": "exact_or_ancestor_of_recorded_commit"}, {"plan_id": "M02", "path": "artifacts/module-completion/M02/40-lowercase-hex/manifest.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex", "relationship": "exact_or_ancestor_of_recorded_commit"}],
  "threat_cases": [{"id": "P04-T01", "status": "closed", "evidence": [{"path": "privacy-search.stdout", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex"}]}],
  "vendor_boundaries": [{"vendor": "object-storage", "status": "approved_for_declared_scope", "evidence": {"path": "vendor-object-storage.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex"}}],
  "incident_rehearsal": {"status": "passed", "evidence": {"path": "incident-rehearsal.json", "commit": "40-lowercase-hex", "sha256": "64-lowercase-hex"}}
}
```

The JSON values above are schema examples, not evidence claims. Actual timestamps, SHA, provider decisions, approvals, and hashes are generated from the verified run; the validator rejects example literals in a retained manifest.

## 15. Status transition rules

- `planned → ready`: this plan is approved, Phase A has an executor/worktree, and current Architecture Closure reservations are acknowledged.
- `ready → in_progress`: Phase A inventory work starts. Application files remain untouched.
- `in_progress → blocked`: Phase A is complete but the next step lacks a named Architecture Closure/shared-file token, M01/M02 contract handoff, immutable P02 credential-rotation evidence, immutable P03 vendor/RPO evidence, legal/privacy retention decision, vendor evidence, or environment prerequisite. Record the exact gate and evidence command in this plan and orchestration summary.
- `blocked → in_progress`: every named next-step gate is satisfied; do not require unrelated module completion.
- `in_progress → verification`: all P04 implementation and serialized integrations are complete, M01/M02 gates and P02/P03 evidence validations pass, generated client has no drift, and no production fake remains.
- `verification → blocked`: any command fails/skips, evidence is stale/missing, a commit/path/hash differs, canary leaks, or a vendor boundary is unknown/blocked for an active PHI flow.
- `verification → completed`: `bash scripts/verify-privacy-compliance.sh --commit <recorded-sha>` passes, both commit fields equal that SHA, every artifact is hash- and commit-bound, retained evidence conforms to schema, and the immutable completion manifest is published. P08 acceptance is explicitly not required; P08 later consumes this manifest and replays critical verification on final integrated HEAD.
- Any status → `superseded`: only by an approved replacement path and synchronized orchestration/dependency/shared-ownership update.

Newly revalidated raw `.minimax-flow` findings receive a new sourced `C` identifier with evidence and exit criterion. Unsourced historical `F001–F123` entries are never recreated.
