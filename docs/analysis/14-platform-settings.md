# 14 · موديول PlatformSettings (إعدادات المنصة)

> **المسار:** `apps/api/Modules/PlatformSettings/`
> **Rank:** 0
> **عدد الملفات:** 68 PHP

## 1 · نبذة عامة
موديول `PlatformSettings` يُدير **إعدادات المنصة الكاملة**:
- **Settings**: lifecycle `Create → Update → Validate → Publish` (إصدارات قابلة للنشر).
- **Alerts**: سياسات التنبيهات (AlertPolicy).
- **Calendars**: BusinessCalendar (working week, exceptions).
- **Maintenance**: MaintenanceWindow (active windows تعطّل الخدمة).
- **Operations**: Backup dispatch، GetPlatformOverview، PlatformOperations.
- **TechnicalLogs**: تجميع + أرشفة + استعادة.
- **Outbox**: `platform_settings_outbox` + `TechnicalAlertOutboxRelay`.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/` | BackupOperationsGateway، GetEffectivePlatformSettings، PlatformHealthGateway، PublishTechnicalAlert، ResolveBusinessCalendar، TechnicalLogArchive، TechnicalLogArchiveStore، TechnicalLogSource، ValidateTechnicalAlertRecipientCapability |
| Domain | `Domain/` | AlertPolicy، ArchiveBatch، ArchiveManifest، BackupStatus، CalendarException، CalendarScope، HealthCheckResult، MaintenanceWindow، PlatformHealthSnapshot، SecurityPolicy، SettingKey، SettingsVersion، TechnicalLogEntry، TechnicalLogFilter، TechnicalLogPage، WorkingWeek |
| Features (Settings) | `Features/Settings/Handler/PlatformSettingsHandler.php`، `Http/{CreateSettingsVersionController, GetCurrentPlatformSettingsController, ListSettingsVersionsController, PublishSettingsVersionController, UpdateSettingsValueController, ValidateSettingsVersionController}.php` | دورة حياة الإعدادات |
| Features (Alerts) | `Features/Alerts/Handler/AlertPolicyHandler.php`، `Http/AlertPoliciesController.php` | سياسات التنبيهات |
| Features (Calendars) | `Features/Calendars/Handler/BusinessCalendarHandler.php`، `Http/BusinessCalendarController.php` | التقويم |
| Features (Logs) | `Features/Logs/Handler/TechnicalLogsHandler.php`، `Http/TechnicalLogsController.php` | السجلات التقنية |
| Features (Maintenance) | `Features/Maintenance/Handler/MaintenanceWindowHandler.php`، `Http/MaintenanceWindowsController.php` | نوافذ الصيانة |
| Features (Operations) | `Features/Operations/Handler/PlatformOperationsHandler.php`، `Features/Operations/Handler/PlatformOperationsDispatchHandler.php`، `Console/RunPlatformOperationsDispatchCommand.php`، `Http/{DispatchBackupController, GetPlatformOverviewController, PlatformOperationsController}.php` | العمليات + النسخ الاحتياطي |
| Infrastructure | `Infrastructure/Outbox/{PlatformSettingsOutbox, TechnicalAlertOutboxRelay}.php`، `Infrastructure/Persistence/{DatabaseBusinessCalendars, DatabasePlatformSettings, DatabaseTechnicalLogArchiveStore}.php` | المخرجات |
| Migrations | `Infrastructure/Persistence/Migrations/{CreatePlatformSettingsTables, CreateTechnicalLogArchiveTables}.php` | الجداول |
| Tests | `Tests/` | 11 ملف |

## 3 · أهم العقود

| العقد | الوظيفة |
|------|---------|
| `GetEffectivePlatformSettings` | يرجّع settings نافذة (latest published version) |
| `ResolveBusinessCalendar` | تقويم scope مع inheritance |
| `MaintenanceWindowHandler` (في Features) | active window للوقت الحالي |
| `PublishTechnicalAlert` | ينشر تنبيه تقني |
| `ResolveTechnicalAlertRecipients` | يحلّ recipients |
| `TechnicalLogSource` | مصدر السجلات (DEFERRED) |
| `TechnicalLogArchive` | أرشفة (Object Storage) |
| `TechnicalLogArchiveStore` | DB manifest store |
| `BackupOperationsGateway` | dispatch backups (command-based) |
| `PlatformHealthGateway` | snapshot صحة |
| `ValidateTechnicalAlertRecipientCapability` | validation |

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `SettingsVersion` — VO immutable (version, status=draft/validated/published, values).
- `SettingKey` — enum لمفاتيح الإعدادات.
- `AlertPolicy` — سياسة تنبيه (recipient_capability, severity, code).
- `WorkingWeek` + `CalendarException` + `CalendarScope` — التقويم.
- `MaintenanceWindow` — نافذة صيانة.
- `TechnicalLogEntry`, `TechnicalLogFilter`, `TechnicalLogPage` — السجلات.
- `ArchiveBatch` + `ArchiveManifest` — أرشفة.
- `PlatformHealthSnapshot` + `HealthCheckResult` + `SecurityPolicy` + `BackupStatus` — عمليات.

### 4.2 Handlers
- `PlatformSettingsHandler` (Create/Update/Validate/Publish).
- `AlertPolicyHandler` (CRUD + PublishTechnicalAlert).
- `BusinessCalendarHandler` (resolve effective calendar).
- `MaintenanceWindowHandler` (activeAt).
- `PlatformOperationsHandler` + `PlatformOperationsDispatchHandler` (operations).
- `TechnicalLogsHandler` (read + archive + restore).

### 4.3 Console
- `RunPlatformOperationsDispatchCommand` (في `AppServiceProvider.php:375`).

### 4.4 Infrastructure
- `DatabasePlatformSettings`، `DatabaseBusinessCalendars`، `DatabaseTechnicalLogArchiveStore`.
- `PlatformSettingsOutbox` (يكتب في `platform_settings_outbox`).
- `TechnicalAlertOutboxRelay` (ينقل من `platform_settings_outbox` إلى Redis Stream `platform.technical-alert.v1`).

## 5 · مصادر البيانات
- `platform_settings` (TABLE_OWNERS)
- `platform_settings_versions`، `platform_settings_values` (مُشتقّة من `CreatePlatformSettingsTables.php`، غير مُسجَّلة في TABLE_OWNERS).
- `platform_settings_outbox` (مُشتق، غير مُسجَّل).
- `platform_health_snapshots`، `backup_runs`، `alert_policies`، `maintenance_windows`، `business_calendars`، `calendar_exceptions`، `technical_log_archives`، `technical_log_archive_manifests` (مُشتقّة).
- `audit_events` (مذكور ضمن Authorization، موضع خلاف).

## 6 · نقاط الـ API
- `GET /api/v1/platform-settings` — current.
- `GET /api/v1/platform-settings/versions` — list.
- `POST /api/v1/platform-settings/versions` (CSRF) — create.
- `PATCH /api/v1/platform-settings/versions/{id}` (CSRF) — update value.
- `POST /api/v1/platform-settings/versions/{id}/validate` (CSRF) — validate.
- `POST /api/v1/platform-settings/versions/{id}/publish` (CSRF) — publish.
- `GET /api/v1/platform-settings/alerts/policies` + POST/PATCH.
- `GET /api/v1/platform-settings/calendars/{scopeType}/{scopeId}`.
- `GET /api/v1/platform-settings/maintenance` + POST/PATCH/DELETE.
- `GET /api/v1/platform-settings/operations/overview`.
- `POST /api/v1/platform-settings/operations/backup` (CSRF) — DispatchBackup.
- `GET /api/v1/platform-settings/logs` + `POST /api/v1/platform-settings/logs/archive` + `POST /api/v1/platform-settings/logs/restore`.

## 7 · الوضع الحالي
- ✅ **Settings lifecycle** كامل (Create → Update → Validate → Publish).
- ✅ **Technical Alert outbox + relay** يعمل.
- ✅ **Maintenance windows** مدمجة مع `EnforcePlatformMaintenance` middleware.
- ✅ **Backup dispatch** عبر command.
- ⚠️ Controllers ضمن `Features/*/Http/` (نمط جيد).
- ⚠️ `platform_settings_outbox` غير مُسجَّل في `TABLE_OWNERS`.
- ⚠️ `TechnicalLogSource` DEFERRED في production (لا سجلات حقيقية).
- ⚠️ عدة جداول مُشتقّة غير مُسجَّلة في `TABLE_OWNERS`.

## 8 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| PS1 | `platform_settings_outbox` غير مُسجَّل في `TABLE_OWNERS` (انحراف ملكية) | `CreatePlatformSettingsTables.php` |
| PS2 | `platform_settings_versions`, `platform_settings_values` غير مُسجَّلة | `CreatePlatformSettingsTables.php` |
| PS3 | `TechnicalLogSource` DEFERRED (UnavailableTechnicalLogSource) — لا logs في production | `AppServiceProvider.php:469-472` |
| PS4 | `BusinessCalendarController` يستخدم weekday 0..6 بينما Domain يستخدم 1..7 (عدم تطابق) | `Features/Calendars/Http/BusinessCalendarController.php` vs `Domain/WorkingWeek.php` |
| PS5 | `AlertPolicyHandler` PATCH يتجاوز `Domain\AlertPolicy` validation | `Features/Alerts/Handler/AlertPolicyHandler.php` |
| PS6 | `MaintenanceWindowHandler` لا يحمل `EnforcePlatformMaintenance` integration tests | (gap) |
| PS7 | `DispatchBackupController` لا يحمل timeout طويل (command-based) | `Features/Operations/Http/DispatchBackupController.php` |
| PS8 | `PublishTechnicalAlert` لا يضمن أن recipient_capability موجود في `CapabilityCatalog` | `Features/Alerts/Handler/AlertPolicyHandler.php` |
| PS9 | لا relay ظاهر لـ `settings.version.published` event (مقارنة بـ technical-alert) | (gap) |
| PS10 | `TechnicalLogsHandler` لا يفلتر `classification` (security risk) | `Features/Logs/Handler/TechnicalLogsHandler.php` |
| PS11 | `PlatformOperations` يفتح CSRF على كل الـ mutations | OK |

## 9 · التحسينات المقترحة

1. **تسجيل `platform_settings_outbox`** في `TABLE_OWNERS`.
2. **تسجيل جداول `platform_settings_*` المشتقة** في `TABLE_OWNERS`.
3. **تأكيد weekday mapping** (0..6 vs 1..7) بين Controller و Domain.
4. **إصلاح `AlertPolicyHandler` PATCH** ليلتزم بـ Domain validation.
5. **integration tests** لـ `EnforcePlatformMaintenance` + `MaintenanceWindowHandler`.
6. **timeout configuration** للـ backup dispatch.
7. **تأكيد `recipient_capability` validation** في `PublishTechnicalAlert`.
8. **outbox event** `settings.version.published` (لإعلام Identity/Tasks/Search إلخ).
9. **classification filter** في `TechnicalLogsHandler`.
10. **Production guard لـ `TechnicalLogSource`**: التأكد من تعطيل mock source.
