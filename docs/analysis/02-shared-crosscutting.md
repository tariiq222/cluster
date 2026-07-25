# 02 · Shared + App (Crosscutting Analysis)

## 1 · `Shared/` Kernel

### 1.1 `Shared/Contracts/TransactionalOutbox.php` (10 أسطر)
واجهة عامة لكل الموديولات:

```php
interface TransactionalOutbox {
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void;
}
```

### 1.2 `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php`
تنفيذ Eloquent: يحفظ في `outbox_events` مع `published_at = null` و `delivery_attempts = 0`. يُستخدم من عدة موديولات (WorkRecords، Organization، PlatformSettings، Identity، Documents).

### 1.3 `Shared/Infrastructure/Streams/`
- `RedisStreamTransport.php` (37 سطر) — عقد: `xadd`, `createGroup`, `readGroup`, `pending`, `reclaim`, `ack`, `publishDlq`, `purgeDlq`.
- `PredisRedisStreamTransport.php` (7.4KB) — تنفيذ Predis مع `id`/`fields`/`deliveries`/`consumer`/`idle_ms`.

**الوضع:** نظيف. لا يوجد تسريب لـ Predis داخل الموديولات.

## 2 · `app/Http/Middleware/` — حدود الـ HTTP

### 2.1 `IdentitySessionMiddleware.php` (83 سطر)
- يحلّ الـ session من الكوكي.
- fallback للـ fixture-bearer في بيئات `local`/`testing` فقط (سطر 32–61).
- يضبط `IdentityRequestAttributes::SESSION` و `::PRINCIPAL` على الـ request.
- يفرض `X-Correlation-ID` UUIDv7 (سطر 17–25).
- **الوضع:** نظيف، لكن الـ fallback في الإنتاج يعتمد على `app()->environment(['local', 'testing'])` — التحقق عبر `AppServiceProvider::assertAuthorizationRuntimeSafe()` في `production` (سطر 377-441) لكنه لا يضمن عدم وصول fixture-bearer للـ production.

### 2.2 `IdentityCsrfMiddleware.php` (66 سطر)
- يتحقق من `X-CSRF-Token` إن لم يكن fixture-bearer (سطر 37-41).
- **الوضع:** ✅ الفحص نظيف. الـ `fixture-bearer:` تخطي الـ CSRF منطقي لأن البيئة غير إنتاجية.

### 2.3 `RequireIdentitySessionPrincipal.php` — enforcer حقيقي (Stage 6.5)

أُعيد تنفيذه بعد أن كان dead middleware يضبط `identity.session_only=true` بدون مستهلك.

**المنطق الحالي (من `tests/Unit/Http/Middleware/RequireIdentitySessionPrincipalTest.php`):**
- يفحص `identity.session` و `identity.principal` اللذَين يكتبه `IdentitySessionMiddleware`.
- يتطلب: `session.user_id` و `session.session_id` يكونان non-empty strings.
- يتطلب: `principal.user_id` يكون non-empty string.
- يتطلب: `principal.user_id === session.user_id` (تماسك).
- **عند التماسك:** يمرّر الطلب كما هو.
- **عند عدم التماسك:** يعيد `IdentityApi::problem(401, 'authentication-required', ...)` بدون استدعاء الـ handler التالي.

**القرار وأسبابه (موثَّق في `module-catalog.md` §6.5):**
- لا يستدعي `ResolvePrincipalContext` لأن ذلك contract يُرجع `null` للجلسات المقيدة بـ `must-change-password` — وهذا يكسر مسار تغيير كلمة المرور الذي يفترض أن يصل إليه الـ restricted principal.
- إذن يفحص فقط الـ attributes التي يكتبها `IdentitySessionMiddleware` (نفس نموذج `session_only` ولكن كنتيجة فحص، لا كـ flag).

**المرجع:** `apps/api/app/Http/Middleware/RequireIdentitySessionPrincipal.php` + `apps/api/tests/Unit/Http/Middleware/RequireIdentitySessionPrincipalTest.php`.

### 2.4 `EnforcePlatformMaintenance.php` (83 سطر)
- يرفض POST/PUT/PATCH/DELETE خلال نوافذ الصيانة.
- GET/HEAD/OPTIONS مسموح دائماً.
- `/up` و `/api/v1/auth/login` و `/api/v1/identity/login` مُعفاة.
- internal worker endpoints (scan, reconcile-promotion) معفاة إن `WorkerPrincipalResolver::resolve` يُرجع قيمة.
- يستهلك `MaintenanceWindowHandler`، `ResolvePrincipalContext`، `DecideAccess`، `RecordFacts`.
- **الوضع:** جيد لكن **يستهلك `DecideAccess` في كل طلب**، حتى لو الـ principal غير موجود. هذا قد يكون عنق زجاجة.

### 2.5 `IdentityRequestAttributes.php` (11 سطر)
```php
class IdentityRequestAttributes {
    public const PRINCIPAL = 'identity.principal';
    public const SESSION = 'identity.session';
}
```
- مفاتيح موحَّدة للـ session/principal. ✅

### 2.6 `IdentityRequestBinding.php`
- يحلّ IP+UA context من الـ request ويبني `TrustedRequestBindingContext`. (مذكور في `IdentitySessionMiddleware.php:30`).

### 2.7 `ConsumeSubmittedNotification.php` (legacy)
- middleware إرث يستهلك `outbox_events` في وضع testing فقط.
- **مشكلة:** منطق عمل داخل middleware = خرق للمعمارية (الـ middleware يجب أن يكون pass-through فقط). مسجَّل في `ModulePlacementInventory.php:88`.

### 2.8 `ProjectWorkRecordReadModels.php` (63 سطر)
- بعد كل POST /api/v1/work-records ناجح، يفهرس في Search ويعيد بناء projection في Reporting.
- `REPORT_ID = '019f7000-0000-7000-8000-000000000901'` ثابت.
- **الوضع:** مفيد لكنه ينشئ coupling صلب بين WorkRecords و Search+Reporting عبر middleware. الأفضل نقل هذا المنطق إلى داخل `SubmitWorkRecordHandler` (event handler يستمع لـ `WorkRecordSubmitted`).

## 3 · `app/Http/Controllers/...` — Legacy Boundaries

### 3.1 الجرد الكامل
كل controller في هذه القائمة مذكور بالاسم الكامل في `ModulePlacementInventory::misplacedBusinessFiles()`:
- `Api/{HttpSupport,LinkDocument,WorkDefinition,WorkRecordLifecycle,Workflow}Controller.php`
- `Authorization/{AuthorizationAdmin,CompleteAuthorizationBootstrap,DecideAccess,ExplainAccessDecision,GetAuthorizationBootstrap}Controller.php`
- `Documents/{AddDocumentVersion,CompleteDocumentUpload,CreateDocument,CreateDocumentGrant,DocumentAccessSupport,DownloadDocument,GetDocument,GetDocumentUploadStatus,InitiateDocumentUpload,LinkDocument,ListDocumentLinks,ListDocumentVersions,ListDocuments,ReconcileDocumentPromotion,ScanDocumentVersion,TransitionDocument,UpdateDocument}Controller.php`
- `Identity/{ChangePassword,ConsumeActivation,CreateUserAccount,GetCurrentIdentity,GetCurrentPrincipal,GetUserAccount,IdentityIdempotency,IdentityLogin,IdentityLogout,IssueActivation,ListMyScopes,ListUserAccounts,RefreshIdentityCsrf,ResolvesScopeSelection,SelectMyScope,TransitionUserAccount}Controller.php`
- `Organization/{CreateAssignment,CreateCluster,CreateFacility,CreateJobTitle,CreateOrganizationUnit,CreatePerson,CreatePosition,CreateTemporaryAssignment,EndAssignment,GetCluster,GetFacility,GetImportJob,GetOrganizationUnit,GetPerson,GetPersonReference,GetPosition,GetTemporaryAssignment,ListAssignments,ListFacilities,ListImportJobRows,ListJobTitles,ListOrganizationUnits,ListPeople,ListPositions,ListTemporaryAssignments,ReorderOrganizationUnits,RevokeTemporaryAssignment,SubmitImportJob,SupervisoryRelationship,TransitionImportJob,UpdateCluster,UpdateFacility,UpdateOrganizationUnit,UpdatePerson,UpdatePosition}Controller.php`

**المجموع ≈ 80 controller** يستحق النقل إلى `Modules/<Name>/Features/*/Http`.

### 3.2 ملاحظات PII/سرية
- `IdentityIdempotency.php` و `ResolvesScopeSelection.php` — utilities خاصة بـ Identity، يُفترض نقلها مع موديول Identity.

## 4 · `app/Http/Authentication/SessionPrincipalResolver.php`
- تنفيذ production لـ `ResolveDevelopmentFixturePrincipal` (في `app/Providers/AppServiceProvider.php:222-228`، يسند `SessionPrincipalResolver` إذا كانت البيئة **غير** `local/testing`).
- يحلّ principal من session المخزَّن على الـ request attributes (يضبطه `IdentitySessionMiddleware`).
- **الوضع:** ممتاز كحارس production. لكن يبقى موضعه في `app/` بدل `Modules/Identity/Infrastructure/...`.

## 5 · `app/Integrations/...`

| الملف | الموقع الحالي | المالك الصحيح | ملاحظة |
|-------|-------------|--------------|--------|
| `Notifications/DatabaseTechnicalAlertRecipientResolver.php` | `app/Integrations` | `Modules/Notifications/Infrastructure/Persistence` | تنفيذ `ResolveTechnicalAlertRecipients` |
| `PlatformOperations/CommandBackupOperationsGateway.php` | `app/Integrations` | `Modules/PlatformSettings/Infrastructure/Persistence` | تنفيذ `BackupOperationsGateway` |
| `PlatformOperations/CompositeTechnicalLogSource.php` | `app/Integrations` | `Modules/PlatformSettings/Infrastructure/Persistence` | للـ logs المؤجل |
| `PlatformOperations/LaravelPlatformHealthGateway.php` | `app/Integrations` | `Modules/PlatformSettings/Infrastructure/Persistence` | تنفيذ `PlatformHealthGateway` |
| `PlatformOperations/MockTechnicalLogSource.php` | `app/Integrations` | `Modules/PlatformSettings` | DEFERRED في production |
| `PlatformOperations/ObjectStorageTechnicalLogArchive.php` | `app/Integrations` | `Modules/PlatformSettings` | تنفيذ `TechnicalLogArchive` |
| `PlatformOperations/TechnicalLogSourceUnavailable.php` | `app/Integrations` | `Modules/PlatformSettings` | exception |
| `PlatformOperations/UnavailableTechnicalLogSource.php` | `app/Integrations` | `Modules/PlatformSettings` | sentinel |
| `PlatformSettings/CatalogTechnicalAlertRecipientCapabilityValidator.php` | `app/Integrations` | `Modules/PlatformSettings/Infrastructure/Authorization` | تنفيذ `ValidateTechnicalAlertRecipientCapability` |
| `PlatformSettings/PlatformSettingsApi.php` | `app/Integrations` | (يستخدم Contracts من PlatformSettings) | API client |
| `WorkRecordAuthorizationFacts.php` | `app/Integrations` | `Modules/WorkRecords/Infrastructure/Persistence` | تنفيذ `LinkedResourceAuthorizationFacts` |
| `WorkRecordWorkflowSourceAuthorizationFacts.php` | `app/Integrations` | `Modules/WorkRecords/Infrastructure/Persistence` | تنفيذ `ResolveWorkflowSourceAuthorizationFacts` |

**المجموع: 12 integration** يجب نقلها.

## 6 · `app/Support/...`

| الملف | الوظيفة | ملاحظات |
|-------|---------|---------|
| `OrganizationHierarchyDefinition.php` | تعريف ثابت للتسلسل الهرمي للمنظمة | seeders |
| `OrganizationHierarchyDemoSeeder.php` | seeder توضيحي | مسجَّل في `ModulePlacementInventory.php:97` كـ misplaced |
| `RealisticClusterFacilitiesSeeder.php` | seeder لمؤسسات واقعية | مسجَّل كـ misplaced |
| `W12E2EFixtureSeeder.php` | seeder لاختبارات W12 | مسجَّل كـ misplaced |

**القرار:** نقل الكل إلى `Modules/Organization/Infrastructure/Fixtures/`.

## 7 · `app/Providers/AppServiceProvider.php` (474 سطر)

### 7.1 `register()` (سطر 140-319)
**49 binding رئيسي:**

| نوع | عدد |
|-----|-----|
| Authorization (DecideAccess، PersistAccess، CountOffice، Simulation) | 4 |
| Organization (GetActiveSupervisory, ResolveScopeAncestry, ResolvePersonScope, ValidatePerson, QuarantinedImport) | 5 |
| PlatformSettings (Settings, Calendar, Maintenance, Operations, Logs, Backup, Health, Alerts) | 11 |
| Identity (PrincipalContext, AccountEntitlement, UserForPerson, Authenticate, PreAuthThrottle, Activation, ChangePassword, ResolveSession) | 8 |
| WorkDefinitions (ResolvePublished*, RequestFixture) | 2 |
| Workflow (AdvanceStep, StepAssignee, WorkflowFacts) | 3 |
| WorkRecords (Outbox binding + HttpGateway) | 1 |
| TemporaryAssignment (HttpGateway, ExpireRun, EventFactory, CapabilityValidator) | 4 |
| Documents (FactsReader, UploadStatus, DownloadGrant, DownloadService, LinkedFacts, SensitiveEvents, UploadPolicy, RetentionPolicy) | 8 |
| Infrastructure (RedisStream, S3CompatibleConfig, ClamAvConfig, S3RequestExecutor, SigV4Signer, QuarantineSource, ClamAvTransport, PrivateStorage, MalwareScanner) | 9 |

### 7.2 `boot()` (سطر 324-393)
- **47 migration load** بالترتيب الأبجدي-الزمني (Base → Wn → Zn).
- تسجيل 2 command: `ExpireTemporaryAssignmentsCommand`, `RunPlatformOperationsDispatchCommand`.
- `assertAuthorizationRuntimeSafe()` في production (سطر 377-379).
- `assertDocumentsStorageRuntimeSafe()` في production (سطر 386-388).

### 7.3 الـ Guards الإنتاجية
- `assertAuthorizationRuntimeSafe()`: في production، تحقق أن `DecideAccess` هو `BootstrapGatedDecideAccess` (مع `RbacAbacDecideAccess` كـ engine) و `ResolveDevelopmentFixturePrincipal` هو `SessionPrincipalResolver`.
- `assertDocumentsStorageRuntimeSafe()`: في production، تحقق من S3 credentials + KMS keys.
- `resolveTechnicalLogSource()`: يُرجع `UnavailableTechnicalLogSource` (DEFERRED capability).

### 7.4 تقييم الجودة
- ✅ عزل الـ production logic.
- ⚠️ استخدام `app->environment('testing')` + فحص `$_SERVER['argv']` في `documentsProduction()` (سطر 400-411) — هشّ ويعتمد على اسماء scripts.
- ⚠️ `try { ... } catch (\Throwable) { ... }` في `TechnicalLogArchive` binding (سطر 176-196) يبتلع كل الأخطاء، يخلق sentinel داخل closure — صعب الاختبار.

## 8 · `bootstrap/app.php` (مختصر)
- `withRouting(api: web.php, apiPrefix: '', health: '/up')`.
- `api(prepend: [EnforcePlatformMaintenance::class])` — يضمن أن middleware الصيانة يُحقن أولاً.
- `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` — JSON-only على API.

## 9 · `routes/web.php` (مختصر)
- prefix `api/v1` يضمّ ~80 route + 5 development routes.
- middleware stacks:
  - `web` + بدون `PreventRequestForgery` لـ `auth/login`.
  - `IdentitySession` + `RequireIdentitySessionPrincipal` للقراءات.
  - `IdentitySession` + `RequireIdentitySessionPrincipal` + `IdentityCsrf` للـ mutations.
- throttle: `throttle:6,1` على `identity/activation`، `throttle:60,1` على internal scan/reconcile.

## 10 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| S2 | `ConsumeSubmittedNotification` يحمل منطق outbox في middleware | `ModulePlacementInventory.php:88` |
| S1 | ✅ ~~`RequireIdentitySessionPrincipal` يضبط `identity.session_only=true` ولا أحد يقرأه~~ — **مُنجَز** (Stage 6.5). راجع §2.3 و `module-catalog.md` §6.5. | `RequireIdentitySessionPrincipal.php` |
| S4 | 12 integration في `app/Integrations` يجب نقلها إلى موديولات | `app/Integrations/**` |
| S5 | 4 seeder/fixture في `app/Support` | `app/Support/**Seeder.php` |
| S6 | `SessionPrincipalResolver` في `app/` بدل `Modules/Identity/Infrastructure` | `app/Http/Authentication/SessionPrincipalResolver.php` |
| S7 | `documentsProduction()` يعتمد على argv parsing (هشّ) | `AppServiceProvider.php:400-411` |
| S8 | `try/catch \Throwable` يبتلع كل الأخطاء في TechnicalLogArchive | `AppServiceProvider.php:182-196` |
| S9 | `mockTechnicalLogSource` يبقى bind حتى في production code | `AppServiceProvider.php:175` (مفلتر عبر `resolveTechnicalLogSource`) |
| S10 | `EnforcePlatformMaintenance` يستهلك `DecideAccess` في كل طلب (cost overhead) | `EnforcePlatformMaintenance.php:62-68` |

## 11 · التحسينات المقترحة

1. **نقل 75 legacy controllers** (انخفض من 89 بـ 14 منقول في Stage 6.8) إلى `Modules/<Name>/Features/*/Http` على دفعات، مع تحديث `ModulePlacementInventory` بعد كل دفعة.
2. ✅ ~~**حذف `RequireIdentitySessionPrincipal`** أو ربطه بـ `IdentitySessionMiddleware`~~ — **مُنجَز** (Stage 6.5): الـ middleware صار enforcer حقيقي. راجع §2.3 و `module-catalog.md` §6.5.
3. **نقل `ConsumeSubmittedNotification`** إلى `Modules/Notifications/Features/ConsumeOutboxInTesting/Http/...` أو حذفه إن كان للاختبارات فقط.
5. **نقل 4 seeders** إلى `Modules/Organization/Infrastructure/Fixtures/`.
6. **استبدال argv-based `documentsProduction()`** بـ `app->runningInConsole() + app->runningUnitTests()` + فحص `app->environment('production')` نظيف.
7. **إضافة log واضح في `try/catch` TechnicalLogArchive** لتفادي ابتلاع الأخطاء الصامتة.
8. **cache `DecideAccess` resolution** في `EnforcePlatformMaintenance` للـ principal الواحد.
