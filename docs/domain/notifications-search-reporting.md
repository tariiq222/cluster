---
doc_id: DOM-NSR-001
title: الإشعارات والبحث والتقارير
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديولات Notifications وSearch وReporting
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/014-authorized-search.md
- docs/adr/015-authorized-reporting.md
- docs/adr/017-derived-workspace-and-notifications.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
- docs/data-security/authorization-model.md
---
# الإشعارات والبحث والتقارير

## الغرض والنطاق

هذه موديولات مشتقة منفصلة تشترك في قاعدة: لا تملك حقيقة المصدر ولا تكتب فيه ولا تمنح حق الوصول إليه. `Notifications` ينشئ إشعارات داخل التطبيق فقط. `Search` يفهرس حقولاً ونصوصاً مسموحة. `Reporting` يملك تعريفات التقارير واللوحات وRead Models والتصدير المحكوم. جميعها تستهلك Outbox أو Projection Feeds، وتعيد التفويض عند العرض أو الفتح أو التصدير.

خارج النطاق: البريد وSMS وWhatsApp، تنفيذ انتقال مجال، تعريف مؤشر أو قياس، ومصدر حقيقة بديل.

## الكيانات والجداول

| الموديول والجداول | الحقائق المملوكة | القيود |
|---|---|---|
| Notifications: `notifications`, `notification_preferences`, `notification_inbox` | المستلم، النوع، `source_ref`، القراءة والتجميع ومنع التكرار | فريد `(consumer, event_id)`؛ لا payload أو عنوان مصدر حساس |
| Search: `search_index_entries`, `search_checkpoints`, `search_inbox` | وثيقة الفهرس المشتقة، نسخة المصدر وحقائق ترشيح أولي | فريد `(source_type, source_id, projection_version)`؛ لا تخزين حقل غير قابل للفهرسة |
| Reporting: `report_definitions`, `dashboard_definitions`, `report_read_models`, `report_runs`, `export_artifacts`, `report_inbox` | القالب، الإسقاط، حالة التحديث، تشغيل التقرير ودفعة التصدير | الفهرس حسب النطاق والزمن؛ artifact مؤقت ومحكوم بالاحتفاظ |

كل مرجع مصدر هو `{source_module, source_type, source_id, source_version}`. لا توجد joins مباشرة على جداول المنتجين.

## الأوامر والاستعلامات والأحداث

**Notifications Commands:** `MarkNotificationRead`, `UpdateNotificationPreferences`, `RebuildNotificationProjection`. **Queries:** `ListMyNotifications`, `CountUnreadNotifications`. **Events:** `NotificationCreated`, `NotificationRead`.

**Search Commands:** `IndexSourceEvent`, `RemoveIndexEntry`, `RebuildSearchProjection`. **Queries:** `SearchAccessibleRecords`, `GetSearchIndexLag`. **Events:** `SearchIndexUpdated`, `SearchIndexFailed`.

**Reporting Commands:** `CreateReportDefinition`, `PublishDashboardDefinition`, `RefreshReportingProjection`, `RunAuthorizedReport`, `ExportAuthorizedReport`, `RebuildReportingProjection`. **Queries:** `GetAuthorizedDashboard`, `GetReportRun`, `GetReportingFreshness`. **Events:** `ReportDefinitionPublished`, `ReportingProjectionRefreshed`, `ReportExportCreated`.

## الحالات

```text
Notification: Unread -> Read
IndexEntry: Pending -> Indexed | Suppressed | Failed
ReportRun: Queued -> Running -> Completed | Failed | Expired
ExportArtifact: Available -> Expired -> Disposed
```

## الثوابت

- الإشعار رابط آمن وملخص عام فقط؛ فتحه يستدعي endpoint المالك لإعادة التفويض، ولا يكشف المصدر عند المنع.
- البحث يرشح أولياً بالحقائق المشتقة ثم يعيد `DecideAccess` و`ResolveFieldAccess` لكل نتيجة قبل عنوان أو مقتطف أو عدّاد.
- التقرير واللوحة والتصدير يعيدون التفويض على السجل والحقول وقت التنفيذ؛ Read Model أو قرار مخزن لا يكفي.
- لا يظهر `Hidden` في نتيجة أو اقتراح أو تجميع أو export. التجميع المصرح به لا يفتح التفاصيل.
- المستهلكات at-least-once وidempotent، وتحفظ checkpoint أو inbox؛ إعادة البناء من الأحداث أو feed تعطي نتيجة مكافئة.
- فشل الإسقاط لا يغير أو يرجع معاملة المصدر؛ يعرض freshness ويدخل retry/dead-letter قابل للمراجعة.
- الاحتفاظ يزيل artifact التصدير بعد مدة السياسة، ويبقي أثر التدقيق دون الملف.

## الأمن والفشل

يستخدم كل عرض أو تصدير `Authorization` مع `AuthorizationRecordFacts` الحديثة التي يقدمها المصدر؛ لا يبني Search أو Reporting قراراً محلياً. الفشل في Authorization أو المصدر أو إعادة التفويض فشل مغلق: لا عنوان ولا مقتطف ولا صف تقرير ولا artifact. يسجل Audit البحث الحساس، عرض التقارير الحساسة، وكل تصدير مع الحقول ومعرفات المصدر. الإشعار داخل التطبيق فقط؛ لا توجد قناة خارجية تفشل أو تتجاوز التصنيف.

## الاختبارات

- مستخدم فقد النطاق بعد الفهرسة أو بناء التقرير لا يرى نتيجة أو صفاً أو artifact.
- حقل Hidden لا يظهر في Search أو dashboard أو CSV/PDF، والنتيجة المجمعة لا تكشف التفصيل.
- إشعار لسجل لم يعد مسموحاً يعرض نصاً عاماً ويمنع الفتح بلا تسريب.
- إعادة حدث المنتج أو إعادة البناء لا تكرر إشعاراً ولا صف فهرس أو تقرير.
- تعطل المنتج أو Authorization أو consumer ينتج deny أو retry بلا تغيير مصدر، وفشل تدقيق تصدير حساس يمنع التسليم.

## الاعتماديات

يعتمد Notifications على Identity وAuthorization، ويعتمد Search على Authorization، ويعتمد Reporting على Organization وAuthorization. الثلاثة تستهلك عقود أحداث WorkRecords وWorkflow وTasks وCollaboration وDocuments وStrategy وPortfolioProjects وRisk وRecordsGovernance وفق ما يعلنه كل موديول. لا تعتمد عليها أي حقيقة أعمال متزامنة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديولات Notifications وSearch وReporting | إنشاء المواصفة المعتمدة |
