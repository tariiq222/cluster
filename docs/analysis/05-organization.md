# 05 · موديول Organization

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **المسار:** `apps/api/Modules/Organization/`
> **Rank:** 0
> **عدد الملفات:** 78 PHP

## 1 · نبذة عامة
موديول `Organization` هو **الجذر التنظيمي** للمنصة. يدير:
- Cluster, Facilities, OrganizationUnits, Positions, People, Assignments, JobTitles.
- Import Jobs (Excel/CSV) مع قوالب مخصّصة (Facilities، OrganizationUnits، PeopleAssignments، Positions).
- TemporaryAssignments (مع capability validator و expiration lock).
- Supervisory Relationships.
- Person-event outbox: يصدر `PersonRegistered`, `PersonUpdated`, `PersonAccessStatusChanged`, `IdentityProvisioningRequested` كـ CloudEvents.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/` | GetActiveSupervisoryRelationships، ListActiveTemporaryAssignmentFacts، ResolveOrganizationScopeAncestry، ResolvePersonOrganizationScope، ResolveQuarantinedImport، ValidatePersonReference |
| Domain | `Domain/` | Cluster، Facility، JobTitle، OrganizationUnit، Person، Position، RelationshipCapability، SupervisoryRelationship |
| Features (Cluster) | `Features/CreateCluster/`، `Features/UpdateCluster/` | إنشاء/تحديث Cluster |
| Features (Facility) | `Features/CreateFacility/`، `Features/UpdateFacility/` | إنشاء/تحديث Facility |
| Features (Unit) | `Features/OrganizationUnit/` | إدارة OrganizationUnit (CRUD + reorder) |
| Features (Person) | `Features/Person/` | Person CRUD |
| Features (Position) | `Features/Position/` | Position CRUD |
| Features (JobTitle) | `Features/JobTitle/` | JobTitle CRUD |
| Features (Assignment) | `Features/Assignment/` | Workforce Assignment (start/end) |
| Features (ImportJob) | `Features/ImportJob/` | Import jobs + templates |
| Features (TemporaryAssignment) | `Features/TemporaryAssignment/` | Issue/revoke/expire (535 سطر في Handler) |
| Features (SupervisoryRelationship) | inline في legacy `SupervisoryRelationshipController` | |
| Http | `Http/OrganizationApi.php` | correlation-id, idempotency-key, if-match, problem+json, cloudEvent builders |
| Infrastructure | `Infrastructure/` | DatabaseXxx، Outbox (OrganizationOutbox + PersonOutboxRelay)، ConfiguredCapabilityValidator، UnavailableQuarantinedImport، DevelopmentFacilityFixtures |
| Console | `Features/TemporaryAssignment/Console/` | ExpireTemporaryAssignmentsCommand، HandlerTemporaryAssignmentExpiration، RunTemporaryAssignmentExpiration |
| Migrations | `Infrastructure/Persistence/Migrations/` | 12 ملف |
| Tests | `Tests/` | 9 ملفات |

## 3 · أهم العقود (Contracts)

| العقد | الوظيفة |
|------|---------|
| `GetActiveSupervisoryRelationships` | للـ ABAC decisions |
| `ListActiveTemporaryAssignmentFacts` | capabilities للـ principal |
| `ResolveOrganizationScopeAncestry` | parent chain من scope |
| `ResolvePersonOrganizationScope` | scope لـ person_id |
| `ResolveQuarantinedImport` | استرجاع quarantine (DEFERRED في production) |
| `ValidatePersonReference` | التحقق من person reference |
| `ValidateTemporaryAssignmentCapabilities` | (داخل Features) |
| `BuildTemporaryAssignmentEvent` / `TemporaryAssignmentEventFactory` | بناء CloudEvent |

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `Cluster` — الكيان الجذر (cluster_id، config، settings).
- `Facility`، `OrganizationUnit`، `Position`، `Person`، `JobTitle`، `SupervisoryRelationship`، `RelationshipCapability` — VOs/enums.

### 4.2 Handlers
- `CreateClusterHandler`، `UpdateClusterHandler`
- `CreateFacilityHandler`، `UpdateFacilityHandler`
- `OrganizationUnitHandler` (CRUD + reorder)
- `PersonHandler`
- `PositionHandler`
- `JobTitleHandler`
- `AssignmentHandler` (start/end)
- `ImportJobHandler` (Excel/CSV → import_rows → reconcile)
- `TemporaryAssignmentHandler` (535 سطر، أكبر handler في الموديول) — يدعم create/revoke، idempotency replay، expiration lock.

### 4.3 Console
- `ExpireTemporaryAssignmentsCommand` — `php artisan temporary-assignments:expire`.
- `HandlerTemporaryAssignmentExpiration` + `RunTemporaryAssignmentExpiration`.

### 4.4 Infrastructure
- `DatabaseGetActiveSupervisoryRelationships`
- `DatabaseResolveOrganizationScopeAncestry`
- `DatabaseResolvePersonOrganizationScope`
- `ValidatePersonReferenceFromPersistence`
- `DatabaseTemporaryAssignmentHttpGateway`، `TemporaryAssignmentHttpGateway`
- `OrganizationOutbox` (يكتب events)
- `OrganizationPersonOutboxRelay` (ينقل من outbox_events إلى Redis Streams)
- `ConfiguredTemporaryAssignmentCapabilityValidator`
- `UnavailableQuarantinedImport` (sentinel)
- `DevelopmentFacilityFixtures` (للاختبارات)

### 4.5 Outbox + Streams
- `OrganizationPersonOutboxRelay::STREAMS` يربط event types بـ stream names:
  - `com.cluster.organization.identityprovisioningrequested.v1` → `platform.organization.identity-provisioning-requested.v1`
  - `com.cluster.organization.personaccessstatuschanged.v1` → `platform.organization.person-access-status-changed.v1`
  - `com.cluster.organization.personregistered.v1` → `platform.organization.person-registered.v1`
  - `com.cluster.organization.personupdated.v1` → `platform.organization.person-updated.v1`
- `MAX_BATCH_SIZE = 100`، `relayPending($limit)`.
- `IdentityPersonStreamWorker` يستهلك هذه التيارات.

## 5 · مصادر البيانات (DB tables)
- `organizations`, `clusters`, `facility_types`, `facilities`
- `unit_types`, `organization_units`
- `positions`
- `people`
- `assignments`
- `import_jobs`, `import_rows`, `organization_idempotency_keys`
- `temporary_assignments`, `temporary_assignment_capabilities`
- `supervisory_relationships`, `relationship_capabilities`
- `organization_idempotency_keys`

## 6 · نقاط الـ API (legacy + features)
- `GET/POST/PATCH /api/v1/organization/cluster`
- `GET/POST /api/v1/organization/facilities`
- `GET/PATCH /api/v1/organization/facilities/{id}`
- `GET/POST /api/v1/organization/units`
- `POST /api/v1/organization/units/reorder`
- `GET/PATCH /api/v1/organization/units/{id}`
- `GET/POST /api/v1/organization/job-titles`
- `GET/POST /api/v1/organization/positions`
- `GET/PATCH /api/v1/organization/positions/{id}`
- `GET/POST /api/v1/organization/people`
- `GET /api/v1/organization/people/{id}/reference`
- `GET/PATCH /api/v1/organization/people/{id}`
- `GET/POST /api/v1/organization/assignments`
- `POST /api/v1/organization/assignments/{id}/end`
- `GET/POST /api/v1/organization/supervisory-relationships`
- `POST /api/v1/organization/import-jobs`
- `GET /api/v1/organization/import-jobs/{jobId}`
- `GET /api/v1/organization/import-jobs/{jobId}/rows`
- `POST /api/v1/organization/import-jobs/{jobId}/{jobAction}`
- `GET/POST /api/v1/organization/temporary-assignments` (مع RequirePrincipal session)
- `GET /api/v1/organization/temporary-assignments/{id}`
- `POST /api/v1/organization/temporary-assignments` (CSRF)
- `POST /api/v1/organization/temporary-assignments/{id}/revoke` (CSRF)

## 7 · الاختبارات
- `JobTitleHttpAdapterTest`
- `OrganizationAssignmentHttpAdapterTest`
- `OrganizationCoreHttpAdapterTest`
- `OrganizationImportHttpAdapterTest`
- `OrganizationPersonHttpAdapterTest`
- `OrganizationScopeFactsTest`
- `OrganizationTreeHttpAdapterTest`
- `SupervisoryRelationshipTest`
- `TemporaryAssignmentMySqlConcurrencyTest`
- `TemporaryAssignmentTest`

## 8 · الوضع الحالي
- ✅ **Production-grade**: Cluster، Facility، Person، Position، Unit، Import، Temporary، Supervisory.
- ✅ **Person-event outbox + relay** يعمل، مع consumer في Identity.
- ✅ **Concurrency test** لـ Temporary Assignment على MySQL.
- ✅ **Idempotency** على كل الـ mutations.
- ⚠️ HTTP layer (35+ controller) ما زال legacy في `app/`.
- ⚠️ `DevelopmentFacilityFixtures` fixture يجب ألّا يُحقن في production.

## 9 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| O1 | 35+ legacy controller في `app/Http/Controllers/Organization/` | `ModulePlacementInventory.php:47-82` |
| O2 | 4 seeders/fixtures في `app/Support/` (Organization hierarchy + realistic + W12 E2E + Development) | `ModulePlacementInventory.php:97-99` |
| O3 | `ResolveQuarantinedImport` مُسجَّل لـ `UnavailableQuarantinedImport` دائماً — لا استيراد حقيقي متاح | `AppServiceProvider.php:158` |
| O4 | `TemporaryAssignmentHandler` (535 سطر) — monolithic، صعب الـ unit test | `Features/TemporaryAssignment/Handler/TemporaryAssignmentHandler.php` |
| O5 | `SupervisoryRelationshipController` (legacy) يخدم GET و POST في نفس الكلاس | `app/Http/Controllers/Organization/SupervisoryRelationshipController.php` |
| O6 | `import-job` transitions عبر مسار wildcard `/{jobId}/{jobAction}` — يفتقر لـ typed action enum | `routes/web.php` (الـ controller legacy) |
| O7 | `temporary_assignment_capabilities` و`relationship_capabilities` يُكتب من Authorization أيضاً (cross-module writes) | `ModuleBoundariesTest.php:80-82` |
| O8 | لا يوجد `org_assignment_history` أو audit trail في DB | (gap) |
| O9 | `OrganizationHierarchyDefinition.php` و `OrganizationHierarchyDemoSeeder` في `app/Support/` يجب نقلها | `app/Support/OrganizationHierarchyDefinition.php` |
| O10 | `ExpireTemporaryAssignmentsCommand` غير مذكور في Make target — يحتاج `make expire-temporary-assignments` | `Makefile` |

## 10 · التحسينات المقترحة

1. **نقل 35+ legacy controllers** إلى `Modules/Organization/Features/*/Http/`.
2. **نقل 4 seeders** إلى `Modules/Organization/Infrastructure/Fixtures/`.
3. **تفكيك `TemporaryAssignmentHandler`** إلى خدمات أصغر: `CreateTemporaryAssignment`, `RevokeTemporaryAssignment`, `ExpireTemporaryAssignment`.
4. **استبدال `UnavailableQuarantinedImport`** بـ Quarantine backed بـ S3-compatible store + DB pointer.
5. **تأكيد `isAuthorized` pattern**: `SupervisoryRelationshipController` legacy يخدم GET و POST — split.
6. **typed enum لمسار import-job transition** بدل `/{jobAction}` wildcard.
7. **إضافة `org_assignment_history`** table + outbox event `AssignmentEnded` و `AssignmentStarted`.
8. **نقل `OrganizationHierarchyDefinition` و Demo seeder** إلى module fixtures.
9. **Make target** لـ `php artisan temporary-assignments:expire` مع `make expire-temporary-assignments`.
10. **تأكيد أن `DevelopmentFacilityFixtures` لا يُحقن في production** عبر `assertOrganizationRuntimeSafe()`.
