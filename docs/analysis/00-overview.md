# تحليل مشروع Cluster (R3)

> **آخر تحديث:** 2026-08-13
> **النطاق:** `apps/api` + `apps/web` + عقود API + CI/tooling
> **الحالة:** خط الأساس الحالي موثّق في [`SUMMARY.md`](SUMMARY.md)، والمخاطر الحالية في [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

## كيفية قراءة هذه المجموعة

- [`SUMMARY.md`](SUMMARY.md): الحالة التنفيذية الحالية ونتائج التحقق الأخيرة.
- [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md): المخاطر المثبتة والأولوية والإجراء التالي.
- [`../architecture/module-catalog.md`](../architecture/module-catalog.md): المرجع الأعلى للرتب وملكية الموديولات والجداول.
- [`../architecture/ARCHITECTURE.md`](../architecture/ARCHITECTURE.md): التدفقات المعمارية التفصيلية.
- الملفات التفصيلية المؤرخة في 2026-07-25 هي **أرشيف تحليلي تاريخي** وليست وصفًا للنظام الجاري. أُحيلت موديولات `WorkRecords` و`WorkDefinitions` و`Workflow` من المنتج، ولذلك لا يجوز استخدام وثائقها التاريخية لإثبات وجود API أو سلوك تشغيلي حالي.

## فهرس خط الأساس التفصيلي

| # | الوثيقة | النطاق |
|---|---|---|
| 1 | [`01-architecture.md`](01-architecture.md) | البنية العامة وحدود الموديولات |
| 2 | [`02-shared-crosscutting.md`](02-shared-crosscutting.md) | Shared وmiddleware وcomposition root |
| 3 | [`03-identity.md`](03-identity.md) | Identity والجلسات والاعتمادات |
| 4 | [`04-authorization.md`](04-authorization.md) | RBAC/ABAC والتفويض والتصنيف |
| 5 | [`05-organization.md`](05-organization.md) | Organization والأشخاص والهيكل |
| 6 | [`06-work-records.md`](06-work-records.md) | أرشيف تاريخي: WorkRecords وWorkDefinitions المتقاعدان |
| 8 | [`08-documents.md`](08-documents.md) | Documents والتخزين والفحص |
| 9 | [`09-tasks.md`](09-tasks.md) | Tasks |
| 10 | [`10-notifications.md`](10-notifications.md) | Notifications |
| 12 | [`12-reporting.md`](12-reporting.md) | Reporting وSearch |
| 14 | [`14-platform-settings.md`](14-platform-settings.md) | PlatformSettings والعمليات |
| 16 | [`16-scripts-and-tooling.md`](16-scripts-and-tooling.md) | scripts وCI وMake |
| 17 | [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md) | المخاطر الحالية وخارطة الإغلاق |

## الصورة الحالية

- المنتج التشغيلي يتمحور حول الهوية، الهيكل التنظيمي، الصلاحيات، المهام، الوثائق، الإشعارات، البحث، التقارير، التدقيق، وإعدادات المنصة.
- `WorkRecords` و`WorkDefinitions` و`Workflow` متقاعدة: لا routes أو providers أو واجهات مستخدم نشطة لها.
- عقد OpenAPI الحاكم هو `docs/contracts/api/openapi.yaml`، والحالة الحالية تُثبت من العقد والكود والاختبارات، لا من ملفات التحليل التاريخية.

النتيجة التفصيلية ونتائج الأوامر في [`SUMMARY.md`](SUMMARY.md).
