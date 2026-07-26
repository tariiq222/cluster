# 08 · موديول Documents (الوثائق)

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **المسار:** `apps/api/Modules/Documents/`
> **Rank:** 5
> **عدد الملفات:** 90 PHP

## 1 · نبذة عامة
موديول `Documents` هو **محرّك إدارة الوثائق**:
- دورة حياة كاملة: Initiate Upload → Quarantine → ClamAV Scan → Promote to Private Storage → Version → Download via SigV4 presigned URL.
- ربط الوثائق بمصادر (LinkedResource: work_record, task, workflow_step).
- **Clean Spreadsheet Parser** لاستخراج schema (للـ Excel).
- Outbox events: `document.scanned.v1`, `document.promoted.v1`.
- Sensitive access events.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Application | `Application/` | AuthorizedDocumentActor، DocumentAccessRequest، DocumentAuthorizationFacts، DocumentDownloadGrant، DocumentDownloadService، DocumentLinkService، DocumentMetadata، DocumentScanResult، DocumentSourceReference، DocumentUploadCompletion، DocumentUploadStatus، IdempotencyContext، InitiateDocumentUpload، InitiatedDocumentUpload، MalwareScanResult، QuarantineObjectReference، QuarantineUploadRequest، RetryableStorageException، SignedUploadIntent، StoredObjectProperties، UploadFileMetadata، VerifiedQuarantineObject، CleanSpreadsheetDocument |
| Contracts | `Contracts/` | DocumentAuthorizationFactsReader، DocumentDownloadGrantIssuer، DocumentUploadStatusReader، LinkedResourceAuthorizationFacts، MalwareScanner، PrivateObjectStorage، SensitiveAccessEventRecorder، TrustedDocumentAuthorizationContext، WorkerPrincipalResolver |
| Domain | `Domain/` | DocumentRetentionPolicy، DocumentScanStatus، DocumentStatus، DocumentUploadPolicy، DocumentVersionAvailabilityStatus، ResolvedDocumentRetention، UuidV7 |
| Features (Upload) | `Features/Upload/DocumentUploadHandler.php` | خط أنابيب الرفع |
| Features (Spreadsheet) | `Features/Spreadsheet/CleanSpreadsheetReferenceService.php` | استخراج schema من Excel |
| Infrastructure (Authorization) | `Infrastructure/Authorization/` | ConfiguredWorkerPrincipalResolver، GrantedDocumentAuthorizationContext |
| Infrastructure (Persistence) | `Infrastructure/Persistence/` | DatabaseDocumentAuthorizationFactsReader، DatabaseDocumentUploadStatusReader، DatabaseSensitiveAccessEventRecorder |
| Infrastructure (Security) | `Infrastructure/Security/` | ClamAvConfiguration، ClamAvMalwareScanner، ClamAvSocketTransport، ClamAvTransportException، StreamSocketClamAvTransport، UnavailableMalwareScanner |
| Infrastructure (Storage) | `Infrastructure/Storage/` | PrivateDocumentDiskConfiguration، UnavailablePrivateObjectStorage، S3/* (DeterministicObjectKeyResolver، GuzzleS3RequestExecutor، ObjectKeyResolver، QuarantineObjectByteSource، S3CompatibleConfiguration، S3CompatiblePrivateObjectStorage، S3DocumentDownloadGrantIssuer، S3QuarantineObjectByteSource، S3RequestExecutor، SigV4RequestSigner) |
| Migrations | `Infrastructure/Persistence/Migrations/` | CreateDocumentsCoreTables، HardenDocumentUploadSecurityTables، W18CreateDocumentGovernanceTables، W19AddDocumentLinkConstraintPolicyKey، ZZAddDocumentUploadPurpose |
| Http (legacy) | `app/Http/Controllers/Documents/` | 18 controller (AddDocumentVersion، CompleteDocumentUpload، CreateDocument، CreateDocumentGrant، DocumentAccessSupport، DownloadDocument، GetDocument، GetDocumentUploadStatus، InitiateDocumentUpload، LinkDocument، ListDocumentLinks، ListDocumentVersions، ListDocuments، ReconcileDocumentPromotion، ScanDocumentVersion، TransitionDocument، UpdateDocument) |
| Tests | `Tests/` | 11 ملف (Http، Infrastructure/Security، Infrastructure/Storage، Support) |

## 3 · أهم العقود (Contracts)

| العقد | الوظيفة |
|------|---------|
| `MalwareScanner` | `scan(QuarantineObjectReference, UploadFileMetadata): MalwareScanResult` |
| `PrivateObjectStorage` | `put(VerifiedQuarantineObject, InitiatedDocumentUpload): StoredObjectProperties` |
| `DocumentDownloadGrantIssuer` | إصدار presigned URL |
| `DocumentUploadStatusReader` | قراءة حالة الرفع |
| `DocumentAuthorizationFactsReader` | authorization facts (read) |
| `LinkedResourceAuthorizationFacts` | source facts (write) |
| `SensitiveAccessEventRecorder` | تسجيل وصول حساس |
| `TrustedDocumentAuthorizationContext` | grant-based context |
| `WorkerPrincipalResolver` | يحلّ principal للـ workers (scan/reconcile) |
| `CleanSpreadsheetReferenceService` | يستخرج reference من وثيقة متاحة نظيفة (CSV/XLSX) — Consumers لاحقاً في Organization يبنون Parser خاصاً بهم |

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `DocumentStatus` — enum (draft, quarantined, scanned, promoted, archived, deleted).
- `DocumentScanStatus` — enum (pending, clean, infected, failed).
- `DocumentUploadPolicy` + `DocumentRetentionPolicy` — config-driven policies.
- `DocumentVersionAvailabilityStatus` — version lifecycle.

### 4.2 Handlers
- `DocumentUploadHandler` (Features/Upload) — orchestrate: Initiate → Quarantine → Scan → Promote.

### 4.3 Infrastructure
- **S3 stack**: `S3CompatiblePrivateObjectStorage` + `S3DocumentDownloadGrantIssuer` + `SigV4RequestSigner` + `GuzzleS3RequestExecutor` + `S3CompatibleConfiguration` + `DeterministicObjectKeyResolver`.
- **ClamAV stack**: `ClamAvMalwareScanner` + `ClamAvSocketTransport` + `StreamSocketClamAvTransport` + `ClamAvConfiguration`.
- **Sentinels**: `UnavailablePrivateObjectStorage`, `UnavailableMalwareScanner`.
- **Auth**: `ConfiguredWorkerPrincipalResolver`، `GrantedDocumentAuthorizationContext`.

## 5 · مصادر البيانات (DB tables)
- `documents` — entity root.
- `document_storage_objects` — ربط بـ S3 keys.
- `document_versions` — version history.
- `document_upload_intents` — initiate request.
- `document_quarantines` — quarantine metadata.
- `document_idempotency_keys` — Idempotency-Key support.
- `document_outbox_events` — Outbox للـ scanned/promoted.

## 6 · نقاط الـ API
- `POST /api/v1/documents/uploads` (CSRF) — InitiateDocumentUploadController.
- `GET /api/v1/documents/uploads/{uploadId}` — GetDocumentUploadStatusController.
- `POST /api/v1/documents/uploads/{uploadId}/complete` (CSRF) — CompleteDocumentUploadController.
- `GET /api/v1/documents/{documentId}/download` — DownloadDocumentController.
- `POST /api/v1/internal/documents/versions/{versionId}/scan` (throttle:60,1) — ScanDocumentVersionController.
- `POST /api/v1/internal/documents/versions/{versionId}/reconcile-promotion` (throttle:60,1) — ReconcileDocumentPromotionController.
- إضافة: Add/Create/Get/List/Update/Transition/Link/CreateDocumentGrant/ListLinks/ListVersions (legacy).

## 7 · الاختبارات
- `DocumentGovernanceAcceptanceTest`
- `DocumentUploadCoreTest`
- `Http/{DocumentListingControllerTest, DocumentsHttpControllerTest, DownloadDocumentControllerTest}`
- `Infrastructure/Security/{ClamAvConfigurationTest, ClamAvMalwareScannerTest, FakeClamAvTransport, InMemoryByteSource, RecordingTransport}`
- `Infrastructure/Storage/S3/{FakeS3RequestExecutor, GuzzleS3RequestExecutorTest, S3CompatibleConfigurationTest, S3CompatiblePrivateObjectStorageTest, SigV4RequestSignerTest}`
- `Support/{InMemoryMalwareScanner, InMemoryPrivateObjectStorage, InMemoryTrustedDocumentAuthorizationContext}`

## 8 · الوضع الحالي
- ✅ **Production-grade upload pipeline** مع ClamAV + S3 SigV4.
- ✅ **Presigned download grant** مع TTL.
- ✅ **Idempotency** كامل.
- ✅ **Sensitive access events** recorder.
- ⚠️ HTTP layer (18 controller) legacy في `app/`.
- ⚠️ `UpdateDocumentController` لا يستخدم `IdentityCsrfMiddleware` (gap).
- ⚠️ `CreateDocumentController` يتخطّى `DocumentUploadHandler` (logic split).
- ⚠️ `document_storage_objects` ملكية متقطعة بين Authorization و Documents.

## 9 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| D1 | 18 legacy controller في `app/Http/Controllers/Documents/` | `ModulePlacementInventory.php:23-42` |
| D2 | `UpdateDocumentController` لا يستخدم `IdentityCsrfMiddleware` (PUT/PATCH بدون CSRF) | (gap) |
| D3 | `CreateDocumentController` يتجاوز `DocumentUploadHandler` (logic split) | `app/Http/Controllers/Documents/CreateDocumentController.php` |
| D4 | `DocumentAccessSupport` helper في legacy (logic utility) | `ModulePlacementInventory.php:25` |
| D5 | `SensitiveAccessEventRecorder` في `app/Integrations/PlatformOperations/`؟ (يجب نقلها) | `app/Integrations/PlatformOperations/...` |
| D6 | لا تأكيد أن `S3CompatibleConfiguration` يرفض http endpoints في production (security) | `AppServiceProvider.php:443-461` |
| D7 | `ClamAvMalwareScanner` يستخدم `stream_socket_client` بدون timeout recovery | `ClamAvMalwareScanner.php` |
| D8 | `S3RequestExecutor` و `GuzzleS3RequestExecutor` مكرّران (احتفاظ بـ interface + concrete) | `S3/*` |
| D9 | لا retry policy على `scan` endpoint (`throttle:60,1` فقط) | `routes/web.php:142` |
| D10 | `WorkRecordAuthorizationFacts` (في `app/Integrations/`) يقرأ `work_records` (ownership) | `app/Integrations/WorkRecordAuthorizationFacts.php` |
| D11 | `WorkRecordWorkflowSourceAuthorizationFacts` (في `app/Integrations/`) يقرأ `workflow_instances` (ownership) | `app/Integrations/WorkRecordWorkflowSourceAuthorizationFacts.php` |

## 10 · التحسينات المقترحة

1. **نقل 18 legacy controllers** إلى `Modules/Documents/Features/*/Http/`.
2. **إضافة `IdentityCsrfMiddleware` لـ `UpdateDocumentController`**.
3. **توحيد `CreateDocumentController`** مع `DocumentUploadHandler` (single entry point).
4. **نقل `DocumentAccessSupport` helper** إلى `Modules/Documents/Infrastructure/Http/Support/`.
5. **نقل `WorkRecordAuthorizationFacts` و `WorkRecordWorkflowSourceAuthorizationFacts`** إلى `Modules/WorkRecords/Infrastructure/Persistence/`.
6. **إضافة retry policy** على scan endpoint (exponential backoff).
7. **تأكيد S3 endpoint allowlist في production** (موجود في `assertDocumentsStorageRuntimeSafe`).
8. **timeout + reconnect في ClamAv transport** عند socket errors.
9. **توثيق `DocumentVersionAvailabilityStatus`** flow.
10. **اختبار end-to-end للـ upload → scan → promote → download** في MySQL integration.
