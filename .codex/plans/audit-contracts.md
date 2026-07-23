# Contracts and API Audit

**TOTAL=56 RESOLVED=15 ACCEPTED=17 OPEN=24**

Canonical reference: `.codex/plans/canonical-code-reference.txt` (119 live `/api/v1` routes). Audit basis: full `docs/contracts/**` and `docs/api/**` surfaces, `apps/api/routes/web.php`, routed controllers and validators, module Features/Domain code, Authorization catalog, Notifications workers, and transactional outbox producers.

Classification: **DRIFT-RESOLVED** = code and contract agree; **DRIFT-ACCEPTED** = intentional/planned or no actionable mismatch established; **DRIFT-OPEN** = documentation or schema must be reconciled with live code.

## `docs/contracts/README.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 25-28 describe identity credentials, signed upload, and temporary assignments as planned, and import rows only as published schemas. All four are live. | `apps/api/routes/web.php:99-135`; `apps/api/Modules/Organization/Features/ImportJob/Handler/ImportJobHandler.php:355-360` |

## `docs/contracts/module-contracts.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Lines 40-41 import quarantine/isolation behavior is implemented. | `ImportJobHandler.php:153-156,216-240` |
| DRIFT-RESOLVED | Line 42 opaque cookie plus CSRF design is implemented. | `SessionHandler.php:101-105`; `apps/api/routes/web.php:108-117` |
| DRIFT-OPEN | Lines 42-47 still label credentials/sessions and TemporaryAssignment planned and the three import schemas create-only. | `ActivationHandler.php:16-88`; `CredentialHandler.php:27-79`; `TemporaryAssignmentHandler.php:38-134`; `ImportJobHandler.php:355-360` |

## `docs/contracts/capabilities/identity-credentials-and-sessions.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 25-28 and 87-96 say credentials, activation, sessions, and all HTTP operations are unimplemented/planned; routes and handlers are live. | `apps/api/routes/web.php:99-117`; `ActivationHandler.php:16-88`; `CredentialHandler.php:27-79`; `SessionHandler.php:101-105,217-220` |
| DRIFT-RESOLVED | Lines 53-57 specify opaque Secure/HttpOnly/SameSite cookies and no JSON token; implementation matches. | `SessionHandler.php:101-105`; `IdentityCredentialHttpAdapterTest.php:55-75` |
| DRIFT-ACCEPTED | Lines 104-108 intentionally preserve fixture `/auth/login` alongside real identity sessions. | `apps/api/routes/web.php:99-103` |

## `docs/contracts/capabilities/document-signed-direct-upload.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 24-26 say storage adapter and signed URLs are unimplemented. Signed direct upload and S3-compatible storage are live; only real scanner status should remain explicitly unimplemented. | `apps/api/routes/web.php:121,131-132`; `InitiateDocumentUploadController.php:35-110`; `S3CompatiblePrivateObjectStorage.php:52-102` |
| DRIFT-RESOLVED | Lines 34-47 ticket/completion fields match controllers. | `InitiateDocumentUploadController.php:45-58,99-109`; `CompleteDocumentUploadController.php:45-65` |
| DRIFT-OPEN | Lines 57-65 name planned Documents↔Organization contracts that do not match the actual boundary. | `PrivateObjectStorage.php:19-23`; `DocumentUploadHandler.php:57-60,245-249` |
| DRIFT-RESOLVED | Lines 88-91 paths `/documents/uploads` and `/complete` are live. | `apps/api/routes/web.php:131-132` |

## `docs/contracts/capabilities/organization-import-rows-v1.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 25-27 say only `people_assignments` is implemented. Facilities, units, positions, and people assignments are all selected and applied. | `ImportJobHandler.php:355-360`; `FacilitiesImportTemplate.php:18-76`; `OrganizationUnitsImportTemplate.php:18-99`; `PositionsImportTemplate.php:17-79` |
| DRIFT-OPEN | Lines 39-40 require position `title`, but the JSON schema omits it from `required`; schema exposes `job_title_id`, which the applicator drops. | `positions-import-row-v1.schema.json:6-17`; `PositionsImportTemplate.php:19,61-68` |
| DRIFT-RESOLVED | Lines 34-39 facility/unit required fields match runtime. | `FacilitiesImportTemplate.php:20`; `OrganizationUnitsImportTemplate.php:20` |
| DRIFT-RESOLVED | Lines 74-86 semantic invariants are enforced. | `ValidatesImportRows.php:57-88`; `PositionsImportTemplate.php:23-47,83-92` |

## `docs/contracts/capabilities/temporary-assignment.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 25-27 claim no routes, persistence, or events; all exist. | `apps/api/routes/web.php:123-124,133-134`; `ZCreateOrganizationTemporaryAssignmentsTable.php:12-47`; `TemporaryAssignmentEventFactory.php:24-52` |
| DRIFT-RESOLVED | Lines 34-40 paths exactly match live routes. | `apps/api/routes/web.php:123-124,133-134` |
| DRIFT-RESOLVED | Lines 47-58 create/representation fields align. | `CreateTemporaryAssignmentController.php:41-59`; `TemporaryAssignmentApi.php:12-25,99-114` |
| DRIFT-RESOLVED | Lines 63-83 invariants and overlap checks are enforced. | `TemporaryAssignmentHandler.php:48-62,83-103,263-328` |
| DRIFT-OPEN | Contract says API status `scheduled`; storage/events use state `pending`. Document the API translation and add/link the event schema. | `TemporaryAssignmentApi.php:103-104`; `TemporaryAssignmentEventFactory.php:34-42` |

## `docs/contracts/api/notifications.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Lines 21-28 claim typed `SourceReference` fields (`source_module`, `record_type`, `record_id`), while storage carries `source_record_id`, owner facility, and classification facts. | `CreateNotificationsTable.php:11-20`; `W13AddNotificationSourceFacts.php:11-14` |
| DRIFT-OPEN | Page presents list-only HTTP scope but live API also marks a notification read. | `apps/api/routes/web.php:136-140` |
| DRIFT-RESOLVED | List output omits access context/reasons and re-evaluates access before masking. | `ListMyNotificationsController.php:59-130` |

## `docs/contracts/api/openapi.yaml`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Live route inventory is represented; 49 additional operations are explicitly marked planned. | `apps/api/routes/web.php:97-297`; `openapi.yaml` `x-implementation-status` markers |
| DRIFT-OPEN | `/platform-settings/versions/{versionId}/{settingsAction}` remains marked planned even though dedicated `/validate` and `/publish` routes are live, leaving a duplicate stale transition contract. | `openapi.yaml:3368-3413,3536-3547`; `apps/api/routes/web.php:281-282` |
| DRIFT-OPEN | Calendar collection moved to `/platform-settings/calendars`, but old `/business-calendars/{calendarId}/days/{date}` and `/publish` paths remain as planned while current live weekday/exception/publish paths also exist. Reconcile or explicitly deprecate the old family. | `openapi.yaml:3414-3535,3548-3565`; `apps/api/routes/web.php:283-286` |
| DRIFT-OPEN | Newly live Platform Settings/Operations operations are not consistently marked `x-implementation-status: implemented`, unlike nearby canonical operations; consumers cannot reliably distinguish live from unspecified status. | `openapi.yaml:3286-3645`; `apps/api/routes/web.php:266-296` |

## `docs/contracts/api/w1-1.openapi.yaml`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Frozen W1.1 overlay references four canonical paths; no concrete mismatch established. | File fully audited against `openapi.yaml` and `routes/web.php` |

## `docs/contracts/api/w1-2.openapi.yaml`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Frozen W1.2 `$ref` overlay remains internally linked; no concrete route mismatch established. | File fully audited against `openapi.yaml` and `routes/web.php` |

## `docs/contracts/api/r1-screens.openapi.yaml`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | R1 screen overlay references canonical paths and its inline action enums match route constraints; no concrete mismatch established. | `apps/api/routes/web.php:197-297` |

## `docs/contracts/events/asyncapi.yaml`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Work-record-submitted channel/type is consumed explicitly. | `ConsumeWorkRecordSubmittedHandler.php:13,114-118`; Notifications stream worker constants |
| DRIFT-OPEN | Actual `com.cluster.platform.technical-alert.v1` stream/event and DLQ are absent from AsyncAPI. | `NotificationsTechnicalAlertWorker.php:16-20`; `ConsumeTechnicalAlertHandler.php:14,63-67` |
| DRIFT-OPEN | Document upload/version outbox events are absent: upload initiated, uploaded, rejected, promotion requested, quarantined, available. | `DocumentUploadHandler.php:226,329-331,423-426,521-524,854-860` |
| DRIFT-OPEN | AsyncAPI declares broad publish/consume channels without an evidenced consumer dispatch point for every declared type; prune or mark planned until routing is implemented. | `DatabaseTransactionalOutbox.php`; module worker/handler registry audit |

## `docs/api/endpoints.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Inventory covers all 119 current route declarations and no extra live path was found. | `apps/api/routes/web.php:97-297`; canonical reference route total 119 |
| DRIFT-ACCEPTED | Cards list non-success status codes while success is implicit; this is a presentation convention, not route drift. | File-wide card format |

## `docs/api/rbac-matrix.md`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Notification GET row reports CSRF required, but only POST `/{notificationId}/read` has CSRF middleware. Similar read-only rows must be regenerated from middleware groups. | `rbac-matrix.md:139-140`; `apps/api/routes/web.php:136-140` |
| DRIFT-OPEN | Matrix does not map live authorization capabilities, including notifications and new Platform Settings/Operations capabilities. | `CapabilityCatalog.php:8-138`; `AuthorizationAdminController.php:43-58` |
| DRIFT-ACCEPTED | `platform_owner` is intentionally granted the full catalog; operations-office member remains narrowly scoped. | `OperationsOfficeRoleCatalog.php:11-18`; `PlatformOwnerRoleTest.php` |

## `docs/contracts/schemas/access-context.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Current event factories emit compatible subject/tenant/clearance/correlation context. | `TemporaryAssignmentEventFactory.php:44-52`; `IdentityApi.php:77-86` |

## `docs/contracts/schemas/access-decision.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Schema requires `authorization_trace_id` and `evaluated_at`, but live response omits both; field decision enum/name also differs from `field_access` (`hidden|masked|readonly|editable`). | Schema lines 6-14; `DecideAccessController.php:67-87`; `AccessDecision.php:8-24`; `AccessProjection.php:45-48` |

## `docs/contracts/schemas/assignment-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Primary Position assignment event remains distinct from temporary assignment events; no mismatch established. | Assignment handler/event audit |

## `docs/contracts/schemas/cluster-created.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Cluster producer audit |

## `docs/contracts/schemas/cluster-updated.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Cluster producer audit |

## `docs/contracts/schemas/document-scan-completed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Schema requires `completed_at`, classification, and access context, while current scan result projects only document/version IDs plus scan and availability status. Define the event mapper or reconcile schema. | Schema lines 6-24; `DocumentScanResult.php:5-24` |

## `docs/contracts/schemas/event-envelope.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | CloudEvents extensions are allowed and current event factories supply standard fields. | `TemporaryAssignmentEventFactory.php:24-33`; `IdentityOutbox.php:66-70` |

## `docs/contracts/schemas/facilities-import-row-v1.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Required fields and patterns match runtime validator/applicator. | `FacilitiesImportTemplate.php:20-42`; `ValidatesImportRows.php:24-49` |

## `docs/contracts/schemas/facility-archived.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Facility producer audit |

## `docs/contracts/schemas/facility-created.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Import template publishes `Facility::toArray`; required create fields align. | `FacilitiesImportTemplate.php:67-75` |

## `docs/contracts/schemas/facility-updated.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Facility producer audit |

## `docs/contracts/schemas/identity-provisioning-requested.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Identity consumer explicitly accepts this event type and validates context. | `ConsumeOrganizationPersonEventHandler.php:15-19,266-280` |

## `docs/contracts/schemas/import-job-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Enum contains all four implemented templates. | `ImportJobHandler.php:355-360` |

## `docs/contracts/schemas/organization-unit-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Organization-unit producer audit |

## `docs/contracts/schemas/organization-units-import-row-v1.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Required/optional fields and patterns match runtime. | `OrganizationUnitsImportTemplate.php:20-65`; `ValidatesImportRows.php:24-88` |

## `docs/contracts/schemas/person-access-status-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Person event audit |

## `docs/contracts/schemas/person-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Person event audit |

## `docs/contracts/schemas/position-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-RESOLVED | Fields match the Position payload emitted by handler. | `PositionHandler.php:64-94` |

## `docs/contracts/schemas/positions-import-row-v1.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | `title` exists but is not required although runtime requires it. `job_title_id` is published but silently ignored by apply. | Schema lines 6-17; `PositionsImportTemplate.php:19,61-68` |

## `docs/contracts/schemas/principal-context.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Schema claims GET `/me` fields `subject_id`, `tenant_id`, `clearance`, `correlation_id`; actual context exposes user/person/account and selected cluster/facility/unit scope fields. | Schema lines 6-27; `PrincipalContext.php:18-47` |

## `docs/contracts/schemas/problem-details.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Controller problem helpers emit required RFC 7807 fields and permitted extensions. | Module `*Api::problem` helpers |

## `docs/contracts/schemas/record-facts.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Schema uses `record_type` and `owner_organization_unit_id`; live Authorization boundary uses `resourceType` and `organizationUnitId` with different optionality. | Schema lines 6-36; `RecordFacts.php:12-32` |

## `docs/contracts/schemas/user-account-changed.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Status/action values align with account lifecycle handler. | `UserAccountHandler.php:155-210`; `ConsumeOrganizationPersonEventHandler.php:266-280` |

## `docs/contracts/schemas/work-record.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Schema requires authorization projection fields absent from domain envelope, and forbids undeclared `field_policy_key` that runtime includes. | Schema lines 6-31; `WorkRecord.php:73-97` |

## `docs/contracts/schemas/work-record-submitted.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | References stale `work-record.schema.json`, inheriting its required/prohibited field mismatch. | Schema lines 1-9; `WorkRecord.php:73-97` |

## `docs/contracts/schemas/workflow-decision-recorded.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Workflow event audit |

## `docs/contracts/schemas/workflow-step-activated.schema.json`

| Class | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No concrete producer mismatch established. | Workflow event audit |
