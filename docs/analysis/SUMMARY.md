# ملخّص تنفيذي لحالة Cluster

> **آخر تحديث:** 2026-07-26
> **المنهجية:** repository state + change history + source inspection + fresh quality gates
> **المرحلة الحالية:** stabilization بعد integration/architecture migration
> **readiness_state:** `unassessable` — لم يُعتمد goal charter رسمي للحكم على الإغلاق.

## الخلاصة

التغييرات الأخيرة حسّنت البنية بوضوح ولم تعد مجرد ترحيل أولي: موجة العمل من `14d5032` إلى `be9dd40` غيّرت 262 ملفاً (`+14880/-1919`)، قسمت composition root، نقلت شجرة المتحكمات القديمة إلى الموديولات، أصلحت حدوداً وعقوداً وfixtures، وأعادت بوابات CI. الفرع الحالي لا يبتعد عن `origin/main`; التغيير غير الملتزم عند بداية هذا التقييم كان تحديث `docs/api/endpoints.md` و`docs/api/rbac-matrix.md` الناتج عن route inventory.

الوضع أقوى معمارياً، لكنه ليس أخضر بالكامل: API static/boundary checks تمر، وPHPUnit المباشر نجح في 724 اختباراً (716 ناجحاً، 8 متجاوزة، 5050 assertion)، وأُصلحت بوابة توثيق محلية كانت تعتمد ملفات catalog/MkDocs محذوفة. في المقابل، اختبارات الويب تحتوي 8 حالات فاشلة في Organization drawers، والعميل المولّد لا يطابق العقد الحالي: baseline `api:check` يكتشف staleness، بينما regeneration يجعل الفحص يمر لكنه يكسر TypeScript build بسبب اختفاء عمليات وأنواع Business Calendar التي تعتمد عليها wrappers اليدوية. أُعيد ملف العميل إلى حالته السابقة حتى لا تُترك الشجرة في حالة لا تُبنى. هدف Composer تجاوز مهلة 300 ثانية، لكن تشغيل PHPUnit المباشر أكمل بنجاح خلال نحو 380 ثانية؛ المشكلة هنا مهلة البوابة لا صحة اختبارات API.

## ماذا تغيّر فعلياً

| المحور | قبل الموجة | الآن | الدليل |
|---|---|---|---|
| Composition root | `AppServiceProvider` مركزي وضخم | 106 أسطر + 12 module service providers | `AppServiceProvider.php`, `Modules/*/Providers/` |
| Controllers | 89 مسار legacy ثم 14 منقول | لا يبقى تحت `app/Http/Controllers/` إلا base `Controller.php`; 102 controller داخل الموديولات | file tree + routes |
| Organization HTTP | 35 controller في app سابقاً | 35 controller داخل `Modules/Organization/Features/*/Http/` | module tree |
| CSRF للوثائق | `UpdateDocumentController` موثق كفجوة | داخل session + principal + CSRF mutation group | `routes/web.php:278-305` |
| Table ownership | 39 entries ووثائق تدّعي جداول ناقصة | 97 entries تغطي كل 96 migrated table names | `ModuleBoundariesTest.php` + migration scan |
| DI guards | خطر فقدان production engine أثناء التقسيم | `assertAuthorizationRuntimeSafe()` يفرض production engine + session principal resolver | `AppServiceProvider.php:84-97` |
| API docs | route rows/middleware قديمة | endpoints/RBAC أُعيد توليدهما؛ WorkRecord route يوثق `project_work_record_read_models` | `docs/api/` diff |
| Generated client | Orval output الملتزم stale | regeneration يطابق 151 path و202 operation لكنه يحذف Business Calendar surface ويكسر TypeScript build؛ أُعيد العميل السابق | `api:check` + `build` |

## الحالة المعمارية الحالية

- 12 موديول منفّذ + 7 موديولات مخططة.
- `make verify-boundaries`: 12 اختباراً، 53 assertion، ناجحة.
- 49 ملف migration يعلن 97 `Schema::create` call تمثل 96 اسماً مميزاً.
- لا يوجد migrated table بلا owner.
- توجد مشكلة exactness واحدة: `project_work_record_read_models` في `TABLE_OWNERS` وليس جدولاً مهاجراً.
- `ModulePlacementInventory` يحتوي 6 entries: 4 موجودة و2 Reporting paths قديمة غير موجودة.
- توجد 5 module-owned controllers خارج النمط المفضّل `Features/*/Http/`: أربعة Reporting وواحد Search. الحارس الحالي لا يرفضها.
- `Audit` ما زال مخططاً فقط؛ لا توجد هجرة `audit_events`. `sensitive_access_events` ملك Authorization حالياً.

## نتائج التحقق الحديثة

| الفحص | النتيجة | النطاق/الملاحظة |
|---|---|---|
| `make verify-boundaries` | ناجح | 12 tests / 53 assertions |
| `make lint-api` | ناجح | Pint |
| `make analyse-api` | ناجح | PHPStan، 0 errors |
| `make docs-validate` | ناجح | Notifications/Auth/WorkRecords/W1.1/W1.2 + YAML/JSON/links |
| `npm --prefix apps/web run build` | ناجح بتحذير | chunks رئيسية 1.24 MB وSwagger 1.35 MB قبل gzip |
| `npm --prefix apps/web run lint` | ناجح بتحذيرات | 60 warning في 16 ملفاً |
| `npm --prefix apps/web run api:check` | فاشل تكاملياً | baseline stale؛ regeneration يمر على 151 path / 202 operation ثم يفشل web build بسبب Business Calendar exports/types المفقودة |
| `npm --prefix apps/web run test:unit` | فاشل | 71 files passed، 1 failed؛ 8 اختبارات Organization drawer فشلت |
| API PHPUnit مباشر | ناجح | 724 tests: 716 passed، 8 skipped، 5050 assertions؛ نحو 380 ثانية. `make test-api` نفسه انتهى بمهلة Composer عند 300 ثانية |
| E2E | غير مُشغّل | لا يوجد browser/runtime evidence حديث في هذا التقييم |

## الفجوات ذات الأولوية

1. **P0 — contract/client integration:** حسم مصدر الحقيقة لعمليات وأنواع Business Calendar؛ يجب أن يمر `api:check` ثم `build` على الناتج نفسه بلا استعادة ملف قديم.
2. **P0 — بوابة web unit:** إصلاح حالات 409/412 الثمانية في `OrganizationDrawers.test.tsx` أو إثبات أن الاختبارات هي القديمة وتحديثها وفق السلوك المقصود.
3. **P1 — inventory exactness:** رفض مفاتيح `TABLE_OWNERS` الزائدة والتحقق من وجود كل path في `ModulePlacementInventory`.
4. **P1 — controller layout:** نقل 5 controllers إلى `Features/*/Http/` أو اعتماد استثناء صريح وحارس يطابق القرار.
5. **P2 — gate timeout:** رفع مهلة `make test-api` أو إزالة طبقة المهلة؛ PHPUnit المباشر سليم لكنه يحتاج نحو 380 ثانية في هذه البيئة.
6. **P2 — quality warnings:** 60 lint warnings، Redocly ambiguity/unused component warnings، وتحذير Vite عن chunks الكبيرة.
7. **P2 — runtime evidence:** تشغيل E2E بعد إغلاق unit/contract gates.

## وضع الوثائق بعد التحديث

- [`00-overview.md`](00-overview.md) صار فهرساً واضحاً بين الحالة الحالية وخط الأساس التاريخي.
- هذه الوثيقة هي الملخص التنفيذي الحالي.
- [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md) يحتوي سجل المخاطر مع evidence/impact/confidence/action.
- [`../architecture/module-catalog.md`](../architecture/module-catalog.md) يعكس provider split، اكتمال ترحيل app controllers، وديون exactness الحالية.
- [`../architecture/ARCHITECTURE.md`](../architecture/ARCHITECTURE.md) لم يعد يدّعي وجود `audit_events`.
- ملفات التحليل `01`–`16` موسومة بوضوح كخط أساس تاريخي بتاريخ 2026-07-25.
- [`../contracts/api/README.md`](../contracts/api/README.md) يوثق مسار `make docs-validate` الحالي بدون catalog/MkDocs المحذوفين.

## القرار العملي

المشروع في **مرحلة تثبيت، لا مرحلة توسعة ميزات**. الأولوية الصحيحة الآن: توحيد عقد Business Calendar والعميل المولّد حتى يمر `api:check` و`build` على الشجرة نفسها، ثم جعل web unit خضراء، ثم تشديد inventory guards، ثم E2E. API tests نفسها خضراء، لكن مهلة بوابة Composer أقصر من زمنها الفعلي في هذه البيئة. أي roadmap أقدم يتكلم عن “75 legacy controller” أو “9 جداول ناقصة” أو “CSRF gap في UpdateDocumentController” أصبح تاريخياً ولا يمثل الوضع الحالي.
