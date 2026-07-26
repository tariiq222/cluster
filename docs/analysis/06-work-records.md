# 06 · موديول WorkRecords (سجلات العمل)

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **المسار:** `apps/api/Modules/WorkRecords/`
> **Rank:** 8
> **عدد الملفات:** 14 PHP

## 1 · نبذة عامة
موديول `WorkRecords` يتعامل مع **سجلات العمل المُرسلة (Submitted Work Records)**:
- `Domain/WorkRecord` — VO readonly (id، recordNumber، workTypeVersionId، ownerFacilityId، creatorUserId، classification، payload، submittedAt، fieldPolicyKey).
- `Domain/Tests/WorkRecordEnvelopeTest.php` — envelope serialization.
- 3 features: `GetAuthorizedWorkRecord`, `ListAuthorizedWorkRecords`, `SubmitWorkRecord`.
- `Infrastructure/Outbox/Relay/RedisOutboxRelay.php` — ينقل `com.cluster.workrecord.submitted.v1` من outbox إلى Redis Stream `platform.work-record.submitted.v1`.
- `Infrastructure/Persistence/Migrations/` — `CreateWorkRecordsTable`، `W13AddWorkRecordFieldPolicyKey`.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Domain | `Domain/WorkRecord.php` | VO readonly |
| Features | `Features/GetAuthorizedWorkRecord/` | GET /api/v1/work-records/{id} (filtered by authorization) |
| Features | `Features/ListAuthorizedWorkRecords/` | GET /api/v1/work-records (filtered + paginated) |
| Features | `Features/SubmitWorkRecord/` | POST /api/v1/work-records (with envelope, idempotency, If-Match) |
| Outbox | `Infrastructure/Outbox/Relay/RedisOutboxRelay.php` | ينقل من outbox_events إلى Redis Stream |
| Outbox migrations | `Infrastructure/Outbox/Migrations/CreateOutboxTable.php` | جدول outbox_events |
| Persistence | `Infrastructure/Persistence/Migrations/` | work_records + field_policy_key |
| Http (legacy) | `app/Http/Controllers/Api/WorkRecordLifecycleController.php` | controller lifecycle (submit/get/list/update) |
| Middleware | `app/Http/Middleware/ProjectWorkRecordReadModels.php` | بعد كل POST ناجح → Search+Reporting projections |

## 3 · أهم العقود (Contracts) — المُستهلكة
يستهلك WorkRecords العقود من:
- `Modules\Identity\Contracts\ResolveAccountEntitlement`
- `Modules\Identity\Contracts\ResolveUserForPerson`
- `Modules\Identity\Contracts\PrincipalContext`
- `Modules\Authorization\Contracts\DecideAccess`
- `Modules\Authorization\Contracts\RecordFacts`
- `Modules\Authorization\Contracts\AccessDecision`
- `Modules\Organization\Contracts\ResolvePersonOrganizationScope`
- `Modules\Organization\Contracts\GetActiveSupervisoryRelationships`
- `Modules\WorkDefinitions\Contracts\ResolvePublishedWorkDefinition`
- `Modules\Documents\Contracts\LinkedResourceAuthorizationFacts`
- `Modules\Workflow\Contracts\AdvanceWorkflowStep`
- `Modules\Workflow\Contracts\ResolveStepAssignee`
- `Shared\Contracts\TransactionalOutbox`
- `Shared\Infrastructure\Streams\RedisStreamTransport`

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `WorkRecord` — VO readonly. الـ 4 classifications: `public, internal, confidential, top_secret`.
- `submitted()` (static factory) — ينشئ WorkRecord جديد.
- `withUpdatedClassification()` (mutation method).
- `assertClassifications()` private.
- `toArray()`، `lockVersion` (computed).

### 4.2 Handlers
- `SubmitWorkRecordHandler` — يحفظ envelope + CloudEvent في transaction واحدة.
- `ListAuthorizedWorkRecordsHandler` — يفلتر بـ DecideAccess.
- `GetAuthorizedWorkRecordHandler` — يفحص الصلاحية قبل الإرجاع.

### 4.3 Outbox Relay
- `RedisOutboxRelay` — ينقل `com.cluster.workrecord.submitted.v1` من outbox_events إلى Redis Stream `platform.work-record.submitted.v1` مع `MAX_BATCH_SIZE = 100`.

### 4.4 Migrations
- `CreateWorkRecordsTable` — جدول work_records.
- `W13AddWorkRecordFieldPolicyKey` — إضافة field_policy_key (W13).

## 5 · مصادر البيانات
- `work_records` — السجل الرئيسي (id, record_number, work_type_version_id, owner_facility_id, creator_user_id, classification, payload, lock_version, submitted_at, field_policy_key).
- `outbox_events` (shared).

## 6 · نقاط الـ API
- `POST /api/v1/work-records` — SubmitWorkRecordController (في module) + ProjectWorkRecordReadModels middleware.
- `GET /api/v1/work-records` — ListAuthorizedWorkRecordsController.
- `GET /api/v1/work-records/{id}` — GetAuthorizedWorkRecordController.
- `WorkRecordLifecycleController` (legacy) — باقي lifecycle (update، transition، list-versions).

## 7 · الاختبارات
- `WorkRecordEnvelopeTest` (Domain) — envelope serialization.
- `WorkRecordHttpAdapterTest` (Features/SubmitWorkRecord) — HTTP layer.
- `RedisOutboxRelayTest` — relay logic.

## 8 · الوضع الحالي
- ✅ **Production-grade Submit** مع envelope + idempotency + If-Match + outbox.
- ✅ **Authorization-aware List/Get** عبر DecideAccess.
- ✅ **Outbox + Streams** يعمل (RedisOutboxRelay ينقل للـ stream).
- ⚠️ **WorkRecordLifecycleController** ما زال legacy (transitions, updates, list-versions).
- ⚠️ **ProjectWorkRecordReadModels middleware** يربط WorkRecords بـ Search+Reporting بشكل ضمني (يفضل نقل المنطق لـ event handler).

## 9 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| W1 | `WorkRecordLifecycleController` legacy في `app/Http/Controllers/Api/` | `ModulePlacementInventory.php:11` |
| W2 | `ProjectWorkRecordReadModels` middleware يحتوي logic indexable + reporting — يربط الموديول بـ Search/Reporting via HTTP | `app/Http/Middleware/ProjectWorkRecordReadModels.php` |
| W3 | `REPORT_ID = '019f7000-0000-7000-8000-000000000901'` hard-coded في middleware | `ProjectWorkRecordReadModels.php:14` |
| W4 | `RedisOutboxRelay` لا يحمل DeadLetter envelope (بسيط مقارنة بـ NotificationsStreamWorker) | `Infrastructure/Outbox/Relay/RedisOutboxRelay.php` |
| W5 | `SubmitWorkRecordHandler::assertCloudEvent` لا يفحص أن event_type = `com.cluster.workrecord.submitted.v1` بدقة (string comparison فقط) | `SubmitWorkRecordHandler.php:108-133` |
| W6 | `SubmitWorkRecordHandler::idempotencyQuery` يستخدم `principal_id` فقط كمفتاح — لا tenant isolation إضافي | `SubmitWorkRecordHandler.php:153-160` |
| W7 | `payload` يُخزَّن كـ JSON في work_records.payload — يفقد نوع كل حقل | `Domain/WorkRecord.php` |
| W8 | `field_policy_key` لا يظهر له enforcement في Submit (W13 schema فقط) | `W13AddWorkRecordFieldPolicyKey.php` |
| W9 | لا يوجد `work_record_versions` أو audit trail (سجل التعديلات) | (gap) |
| W10 | `lock_version` computed في VO لكن لم يُلاحَظ اختبار concurrency على Submit | `Domain/WorkRecord.php` |

## 10 · التحسينات المقترحة

1. **نقل `WorkRecordLifecycleController`** إلى `Modules/WorkRecords/Features/*/Http/`.
2. **استبدال `ProjectWorkRecordReadModels` middleware** بـ event handler يستمع لـ `com.cluster.workrecord.submitted.v1` (في Reporting + Search).
3. **تعميم `REPORT_ID`**: نقله إلى config.
4. **تعزيز `RedisOutboxRelay`**: إضافة DLQ envelope (مثل NotificationsStreamWorker).
5. **تشديد `SubmitWorkRecordHandler::assertCloudEvent`**: التحقق من exact event_type.
6. **إضافة tenant isolation لـ idempotency**: `principal_id + facility_id + operation`.
7. **تخزين payload كـ typed JSONB** (MySQL) مع schema versioning.
8. **تطبيق `field_policy_key` enforcement** في Submit عبر `FieldAccessTemplate` من Authorization.
9. **إضافة `work_record_versions` table** + outbox event `WorkRecordUpdated`.
10. **concurrency tests** على Submit (parallel submits of same record_number).

---

# 06b · موديول WorkDefinitions (تعريفات العمل)

> **المسار:** `apps/api/Modules/WorkDefinitions/`
> **Rank:** 5
> **عدد الملفات:** 8 PHP

## 1 · نبذة عامة
موديول `WorkDefinitions` يدير **تعريفات العمل (Work Definitions)**: قوالب قابلة للنشر تحدّد بنية السجلات (`work_type_version_id`) والـ fixture التطويرية (`request`). رتبة 5، يستهلك Organization/Identity.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/ResolvePublishedWorkDefinition.php`، `Contracts/ResolvePublishedRequestFixture.php` | استرجاع التعريف المنشور والـ fixture |
| Features (PublishRequestFixture) | `Features/PublishRequestFixture/Handler/PublishRequestFixtureHandler.php` | نشر fixture ثابت في `local/testing` فقط |
| Infrastructure (Resolve) | `Infrastructure/ResolvePublishedWorkDefinitionFromPersistence.php`، `Infrastructure/ResolvePublishedRequestFixtureFromPersistence.php` | تنفيذ |
| Migrations | `Infrastructure/Persistence/Migrations/CreateWorkDefinitionTables.php`، `CreateDevelopmentWorkTypeFixturesTable.php` | جداول + fixture |
| Tests | `Features/PublishRequestFixture/Tests/PublishRequestFixtureTest.php` | اختبارات |
| Http (legacy) | `app/Http/Controllers/Api/WorkDefinitionController.php` | controller lifecycle |

## 3 · Domain / Handlers / Infrastructure
- لا يوجد `Domain/` layer صريح.
- `PublishRequestFixtureHandler` — handler بسيط.
- `ResolvePublishedWorkDefinitionFromPersistence` — يجلب أحدث إصدار منشور مع fallback fixture.

## 4 · مصادر البيانات
- `work_definitions` (TABLE_OWNERS)
- `work_definition_versions` (لم يُسجَّل في TABLE_OWNERS — ownership drift)
- `work_definition_idempotency_keys` (لم يُسجَّل)
- `development_work_type_fixtures` (لم يُسجَّل)

## 5 · نقاط الـ API
- `WorkDefinitionController` legacy يخدم CRUD lifecycle.

## 6 · الوضع الحالي
- ✅ العقود مكتملة.
- ✅ Fallback fixture للاختبارات.
- ⚠️ HTTP layer legacy.
- ⚠️ Fallback fixture قد يرمي `LogicException` في production بدل 422.

## 7 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| WD1 | `WorkDefinitionController` legacy | `ModulePlacementInventory.php:10` |
| WD2 | 3 جداول (`work_definition_versions`, `work_definition_idempotency_keys`, `development_work_type_fixtures`) غير مُسجَّلة في `TABLE_OWNERS` | `CreateWorkDefinitionTables.php`، `CreateDevelopmentWorkTypeFixturesTable.php` |
| WD3 | `ResolvePublishedRequestFixture` يرمي `LogicException` في production إذا لم يُحمَّل fixture (يجب أن يرجّع null أو 422) | `ResolvePublishedRequestFixtureFromPersistence.php` |
| WD4 | لا gate للقراءة على `GetWorkDefinition`/`GetWorkDefinitionVersion` (الـ legacy controller) | `WorkDefinitionController.php` |
| WD5 | `PublishRequestFixtureHandler` لا يفرض Idempotency-Key رغم أن مسار النشر mutation | (gap) |
| WD6 | لا foreign keys بين `work_records.work_type_version_id` و `work_definition_versions.id` | (gap) |

## 8 · التحسينات المقترحة

1. **نقل `WorkDefinitionController`** إلى `Modules/WorkDefinitions/Features/*/Http/`.
2. **تسجيل 3 جداول** في `TABLE_OWNERS`.
3. **استبدال `LogicException`** بـ typed exception أو null-returning.
4. **إضافة reading gate** (DecideAccess) لـ GET endpoints.
5. **فرض Idempotency-Key** في `PublishRequestFixtureHandler`.
6. **إضافة foreign keys** بين work_records.work_type_version_id و work_definition_versions.id.
