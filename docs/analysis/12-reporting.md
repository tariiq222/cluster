# 12 · موديول Reporting (التقارير ولوحات المعلومات)

> **المسار:** `apps/api/Modules/Reporting/`
> **Rank:** 11
> **عدد الملفات:** 16 PHP

## 1 · نبذة عامة
موديول `Reporting` يُدير **Read-Models (CQRS projections)**:
- `reporting_read_models` (TABLE_OWNERS).
- يبني projections من Work Records المُرسلة (Refresh + Rebuild).
- يخدم GET dashboard / report / export.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Features (Build) | `Features/RebuildReportingProjection/Handler/RebuildReportingProjectionHandler.php`، `Features/RefreshReportingProjection/Handler/RefreshReportingProjectionHandler.php` | بناء/تحديث الإسقاط |
| Features (Read) | `Features/GetAuthorizedDashboard/Handler/GetAuthorizedDashboardHandler.php`، `Features/RunAuthorizedReport/Handler/RunAuthorizedReportHandler.php`، `Features/ExportAuthorizedReport/Handler/ExportAuthorizedReportHandler.php`، `Features/DownloadExportArtifact/Handler/DownloadExportArtifactHandler.php` | قراءة + تنفيذ + تصدير |
| Http | `Http/{CreateReportExportController, DownloadExportController, GetDashboardController, GetReportController, ListDashboardsController, ListReportsController, ReportingApi}.php` | HTTP layer (ضمن الـ Module) |
| Migrations | `Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php` | الجداول |
| Tests | `Tests/{ReportingHttpAdapterTest, ReportingProjectionTest}.php` | اختبارات |

## 3 · العقود المُستهلكة
- `Modules\Authorization\Contracts\DecideAccess`
- `Modules\Authorization\Contracts\RecordFacts`
- `Shared\Contracts\TransactionalOutbox`

## 4 · Handlers

### 4.1 Build
- `RebuildReportingProjectionHandler` — يعيد بناء الإسقاط من الصفر.
- `RefreshReportingProjectionHandler` — يحدّث سطر واحد (يُستدعى من `ProjectWorkRecordReadModels` middleware).

### 4.2 Read
- `GetAuthorizedDashboardHandler`
- `RunAuthorizedReportHandler`
- `ExportAuthorizedReportHandler`
- `DownloadExportArtifactHandler`

## 5 · مصادر البيانات
- `reporting_read_models` (TABLE_OWNERS).
- الجداول الفعلية من `CreateReportingProjectionTables.php` (مفصّلة في التهجيرة).

## 6 · نقاط الـ API
- `GET /api/v1/dashboards/{dashboardId}` (IdentitySession + RequirePrincipal).
- `GET /api/v1/reports/{reportId}` (IdentitySession + RequirePrincipal).
- `POST /api/v1/reports/{reportId}/exports` (IdentitySession + IdentityCsrf + RequirePrincipal).
- `GET /api/v1/exports/{exportId}` (IdentitySession + RequirePrincipal).
- `ListReportsController` و `ListDashboardsController` legacy paths.

## 7 · الوضع الحالي
- ✅ **CQRS pattern** واضح (Read-models منفصلة).
- ✅ **Authorization-aware** (DecideAccess).
- ✅ **Export flow** مع artifact download.
- ⚠️ `ListReportsController` و `ListDashboardsController` legacy في `ModulePlacementInventory.php:103-104`.

## 8 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| R1 | `ListReportsController` و `ListDashboardsController` legacy | `ModulePlacementInventory.php:103-104` |
| R2 | `RefreshReportingProjectionHandler` لا يستخدم optimistic locking (race condition risk) | `RefreshReportingProjectionHandler.php` |
| R3 | لا pagination على `reporting_read_models` (لو كبر الحجم) | (gap) |
| R4 | `ExportAuthorizedReportHandler` لا يحمل TTL على artifact | (gap) |
| R5 | `GetAuthorizedDashboardHandler` لا cache للـ dashboard config | (gap) |
| R6 | `DownloadExportArtifactHandler` لا يفحص ownership قبل التحميل | (gap) |

## 9 · التحسينات المقترحة

1. **نقل `ListReportsController` و `ListDashboardsController`** إلى `Features/ListReports/Http/` و `Features/ListDashboards/Http/`.
2. **إضافة optimistic locking** لـ `RefreshReportingProjectionHandler`.
3. **cursor pagination** على `reporting_read_models`.
4. **TTL** على export artifacts.
5. **cache layer** للـ dashboard config.
6. **ownership check** في `DownloadExportArtifactHandler`.
7. **اختبار end-to-end** لـ rebuild + refresh + read.

---

# 13 · موديول Search (البحث)

> **المسار:** `apps/api/Modules/Search/`
> **Rank:** 11
> **عدد الملفات:** 8 PHP

## 1 · نبذة عامة
موديول `Search` يبني **read-model للبحث** عبر `search_index_entries` (مع unique على `source_type/source_id/projection_version`)، `search_checkpoints`، `search_inbox`. يُفهرس Work Records فقط (عبر middleware).

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Features (Index) | `Features/IndexSourceEvent/Handler/IndexSourceEventHandler.php` | كتابة/تحديث الإسقاط (PROJECTION_VERSION = 'w1.9-v1') |
| Features (Rebuild) | `Features/RebuildSearchProjection/Handler/RebuildSearchProjectionHandler.php` | إعادة بناء الإسقاط |
| Features (Read) | `Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php` | استعلام مع DecideAccess |
| Http | `Http/SearchController.php`، `Http/SearchApi.php` | HTTP layer |
| Migrations | `Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php` | الجداول |
| Tests | `Tests/{SearchProjectionTest, SearchHttpAdapterTest}.php` | اختبارات |

## 3 · العقود المُستهلكة
- `Modules\Authorization\Contracts\DecideAccess`
- `Modules\Authorization\Contracts\RecordFacts`
- `Modules\WorkRecords\Contracts\...` (ضمنياً عبر middleware)

## 4 · Handlers
- `IndexSourceEventHandler` — يكتب في `search_index_entries` (يتجاهل payload غير الصالح).
- `RebuildSearchProjectionHandler` — يحذف ثم يعيد الإدراج (داخل transaction).
- `SearchAccessibleRecordsHandler` — يستعلم مع `DecideAccess` per-row.

## 5 · مصادر البيانات
- `search_index_entries` (unique على source_type/source_id/projection_version).
- `search_checkpoints` (storage checkpoints).
- `search_inbox` (inbox احتياطي).

## 6 · نقاط الـ API
- `GET /api/v1/search` (IdentitySession + RequirePrincipal).

## 7 · الوضع الحالي
- ✅ **CQRS + projection_version** pattern.
- ✅ **Authorization-aware** search.
- ✅ **Http layer ضمن الـ module** (لا legacy في `app/`).

## 8 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| S1 | `search_index`, `search_index_entries`, `search_checkpoints`, `search_inbox` — TABLE_OWNERS يذكر `search_index` فقط | `ModuleBoundariesTest.php:108` |
| S2 | `IndexSourceEventHandler` يتجاهل payload (silent fail) | `Features/IndexSourceEvent/Handler/IndexSourceEventHandler.php` |
| S3 | `RebuildSearchProjectionHandler` يحذف ثم يعيد (race condition مع readers) | `Features/RebuildSearchProjection/Handler/RebuildSearchProjectionHandler.php` |
| S4 | `SearchAccessibleRecordsHandler` يستعلم row-by-row مع `DecideAccess` (N+1) | `Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php` |
| S5 | `projection_version='w1.9-v1'` vs `'w13-v1'` في SecurityJourneyW13Test — انحراف versions | `tests/Feature/SecurityJourneyW13Test.php` |
| S6 | لا full-text search (LIKE فقط؟) | (gap) |
| S7 | `IndexSourceEventHandler` يُفهرس من Work Records فقط (لا documents ولا tasks) | `ProjectWorkRecordReadModels.php` |

## 9 · التحسينات المقترحة

1. **تسجيل 4 جداول** في `TABLE_OWNERS` (`search_index_entries`, `search_checkpoints`, `search_inbox`).
2. **log + drop** بدلاً من silent fail في `IndexSourceEventHandler`.
3. **soft rebuild** (truncate-then-rebuild في transaction) أو staged rebuild.
4. **batch DecideAccess** لتقليل N+1.
5. **تأكيد consistency** لـ `projection_version` (توحيد 'w1.9-v1' vs 'w13-v1').
6. **full-text search** (MySQL FULLTEXT أو Redis FT).
7. **توسيع الـ indexer** ليشمل Documents و Tasks.
