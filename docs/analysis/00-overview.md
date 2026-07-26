# تحليل مشروع Cluster (R3)

> **آخر تحديث:** 2026-07-26
> **النطاق:** `apps/api` + `apps/web` + عقود API + CI/tooling
> **الحالة:** خط الأساس الحالي موثّق في [`SUMMARY.md`](SUMMARY.md)، والمخاطر الحالية في [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

## كيفية قراءة هذه المجموعة

- [`SUMMARY.md`](SUMMARY.md): الحالة التنفيذية الحالية ونتائج التحقق الأخيرة.
- [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md): المخاطر المثبتة والأولوية والإجراء التالي.
- [`../architecture/module-catalog.md`](../architecture/module-catalog.md): المرجع الأعلى للرتب وملكية الموديولات والجداول.
- [`../architecture/ARCHITECTURE.md`](../architecture/ARCHITECTURE.md): التدفقات المعمارية التفصيلية.
- الملفات `01`–`16` أدناه هي **خط أساس تحليلي بتاريخ 2026-07-25**. تفاصيلها مفيدة لفهم الموديولات، لكن مقاييس الترحيل والمخاطر داخلها لا تتقدم على الملخص وسجل المخاطر المحدّثين.

## فهرس خط الأساس التفصيلي

| # | الوثيقة | النطاق |
|---|---|---|
| 1 | [`01-architecture.md`](01-architecture.md) | البنية العامة وحدود الموديولات |
| 2 | [`02-shared-crosscutting.md`](02-shared-crosscutting.md) | Shared وmiddleware وcomposition root |
| 3 | [`03-identity.md`](03-identity.md) | Identity والجلسات والاعتمادات |
| 4 | [`04-authorization.md`](04-authorization.md) | RBAC/ABAC والتفويض والتصنيف |
| 5 | [`05-organization.md`](05-organization.md) | Organization والأشخاص والهيكل |
| 6 | [`06-work-records.md`](06-work-records.md) | WorkRecords وWorkDefinitions |
| 8 | [`08-documents.md`](08-documents.md) | Documents والتخزين والفحص |
| 9 | [`09-tasks.md`](09-tasks.md) | Tasks |
| 10 | [`10-notifications.md`](10-notifications.md) | Notifications |
| 11 | [`11-workflow.md`](11-workflow.md) | Workflow |
| 12 | [`12-reporting.md`](12-reporting.md) | Reporting وSearch |
| 14 | [`14-platform-settings.md`](14-platform-settings.md) | PlatformSettings والعمليات |
| 15 | [`15-web-client.md`](15-web-client.md) | React/Vite والرحلات |
| 16 | [`16-scripts-and-tooling.md`](16-scripts-and-tooling.md) | scripts وCI وMake |
| 17 | [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md) | المخاطر الحالية وخارطة الإغلاق |

## صورة سريعة بعد موجة 2026-07-26

- 12 موديول منفّذ، ولكل موديول `ServiceProvider` خاص به.
- `AppServiceProvider.php` انخفض إلى 106 أسطر ويحتفظ بالتجميع المشترك والحراس الإنتاجية.
- لا يبقى في `app/Http/Controllers/` سوى `Controller.php` الأساسي.
- 102 controller أصبحت داخل الموديولات؛ تبقى 5 في `Modules/*/Http/` خارج نمط `Features/*/Http/` المفضّل.
- `TABLE_OWNERS` يحتوي 97 مدخلاً ويغطي 96 اسماً مميزاً لجداول الهجرات، مع مفتاح زائد واحد موثّق.
- `UpdateDocumentController` داخل مجموعة session + principal + CSRF.
- عقد OpenAPI الحاكم يصف 151 path و202 operation، لكن إعادة توليد العميل منه تحذف Business Calendar surface وتكسر web build؛ التكامل غير مغلق.

النتيجة التفصيلية ونتائج الأوامر في [`SUMMARY.md`](SUMMARY.md).
