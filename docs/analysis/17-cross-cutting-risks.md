# 17 · المخاطر الشاملة وخارطة الأولويات

> **آخر تحديث:** 2026-07-26
> **مرحلة المشروع:** stabilization بعد موجة ترحيل معمارية كبيرة.
> **readiness_state:** `unassessable` — لا يوجد goal charter معتمد من المستخدم لإصدار حكم إغلاق رسمي. هذا لا يلغي فشل البوابات المذكور أدناه.

## ملخص القرار

الموجة الأخيرة أصلحت جزءاً كبيراً من الدين المعماري: نقلت متحكمات التطبيق إلى الموديولات، قسمت dependency injection على 12 `ServiceProvider`، أكملت ملكية كل جداول الهجرات، وأبقت حارس Authorization الإنتاجي. لكن الصورة ليست خضراء بعد: عقد OpenAPI والعميل المولّد وBusiness Calendar wrappers لا تتكامل في شجرة واحدة قابلة للبناء، واختبارات الويب فيها 8 حالات فاشلة، وحراس دقة inventory لا يلتقطون مفاتيح `TABLE_OWNERS` الزائدة أو كل متحكمات `Modules/*/Http/`، ولا توجد نتيجة E2E حديثة.

## النتائج الحالية

| الأولوية | الحالة | الادعاء | الدليل | الأثر | الثقة | الإجراء الأصغر المسؤول |
|---|---|---|---|---|---|---|
| P0 | blocked | العميل المولّد الملتزم stale، لكن regeneration يحذف Business Calendar operations/types التي تستخدمها wrappers اليدوية ويكسر TypeScript build. | baseline `api:check` يغيّر `cluster.ts`; بعد regeneration يمر على 151 path و202 operation، ثم `build` يفشل على 7 exports/types مفقودة و`scope_type`; أُعيد الملف السابق. | لا توجد حالة شجرة واحدة تمر فيها بوابة contract drift وبوابة البناء معاً. | عالية؛ تسلسل توليد ثم build مباشر. | إعادة العمليات والأنواع إلى العقد الحاكم إن كانت مطلوبة، أو ترحيل wrappers/الشاشات إذا أُلغيت، ثم تشغيل `api:check` و`build` متتاليين دون restore. |
| P0 | at-risk | ثمانية اختبارات UI لعروض Organization لا تجد `org-drawer-alert` بعد إرسال حالات 409/412. | `npm --prefix apps/web run test:unit`: 71 ملفاً نجح، ملف واحد فشل، 8 اختبارات فشلت في `OrganizationDrawers.test.tsx`. | دلالة الخطأ/التعارض قد لا تظهر للمستخدم أو أن fixtures لم تعد تطابق مسار الحفظ. لا يمكن اعتبار بوابة الويب خضراء. | عالية؛ فشل runtime test مباشر. | تشخيص مسار submit في drawers والم mocks، ثم إثبات 409 و412 باختبار سلوكي ناجح. |
| P1 | at-risk | `TABLE_OWNERS` كامل من جهة النقص لكنه غير دقيق من جهة الزيادة. | 97 مدخلاً مقابل 96 اسماً مميزاً في `Schema::create`; المفتاح الزائد `project_work_record_read_models` لا يملك هجرة. `make verify-boundaries` يمر. | الحارس يسمح ghost entries، ما يضعف موثوقية الكتالوج. | عالية؛ مقارنة آلية بين الاختبار والهجرات. | حذف المفتاح إن لم يكن جدولاً، وإضافة assertion يرفض owner entries بلا migration. |
| P1 | at-risk | حارس placement لا يغطي كل الشكل المعلن في الكتالوج. | خمسة controllers في `Modules/Reporting/Http/` و`Modules/Search/Http/` خارج `Features/*/Http/`. `ModulePlacementInventory` يحتوي مسارين Reporting غير موجودين، ولا يختبر وجود كل entry. | يمكن أن يمر `verify-boundaries` مع inventory stale أو controller في موقع غير قياسي. | عالية؛ tree inspection + 12 architecture tests ناجحة. | إضافة path-existence assertion وحارس module-level placement، ثم نقل 5 controllers أو توثيق استثناءاتهم. |
| P1 | observed | `ResolveAuthorizationSimulationFacts` مربوط بـ `RegisteredAuthorizationSimulationFactsResolver` بدون providers افتراضيين. | `AuthorizationServiceProvider.php` + constructor الافتراضي `iterable $providers = []`. | simulation قد يبقى بلا facts فعلية. | متوسطة؛ binding مثبت لكن الرحلة لم تُشغّل end-to-end. | تسجيل providers الفعلية أو حذف المسار إذا كان غير مستخدم، مع اختبار رحلة. |
| P2 | at-risk | جودة الويب تمر lint/build مع تحذيرات متراكمة. | `npm ... lint`: 60 warning في 16 ملفاً. `npm ... build`: chunks بحجم 1.24 MB و1.35 MB قبل gzip وتحذير أكبر من 500 kB. | صيانة أضعف، Fast Refresh أقل ثباتاً، وحمولة أولية/Swagger كبيرة. | عالية؛ مخرجات أدوات مباشرة. | فصل exports غير المكوّنات، تنظيف unused values، ثم تقسيم chunks ذات القياس المثبت. |
| P2 | at-risk | OpenAPI صالح لكنه يصدر تحذيرات بنيوية. | `api:check` lint: ambiguous document/task paths وunused components؛ العقود الأربعة valid. | التوليد يعمل، لكن ambiguity قد يربك routers أو generators أخرى. | عالية؛ Redocly مباشر. | تضييق path parameters أو إعادة تسمية المسارات الثابتة، وحذف `$defs` غير المستخدمة. |
| P2 | observed | اكتشاف Documents production ما زال يعتمد على `argv`. | `AppServiceProvider::documentsProduction()` و`DocumentsServiceProvider::documentsProduction()`. | سلوك الحارس يتأثر بأسماء أوامر CLI ويصعب إثباته. | عالية؛ source inspection. | استبداله بسياسة environment/config صريحة واختبارات للأوامر المسموحة. |
| P2 | unverified | لا يوجد إثبات E2E حديث للرحلات الحرجة بعد موجة 262 ملفاً. | `.github/workflows/ci-e2e.yml` موجود، لكن لم يُشغّل browser/production bundle في هذا التقييم. | نجاح unit/static لا يثبت تكامل session/CSRF/API/UI. | عالية بخصوص غياب الدليل؛ منخفضة بخصوص وجود عطل فعلي. | تشغيل رحلة محلية أو runner المسمى `cluster-e2e` بعد إصلاح unit tests وتسجيل النتيجة. |

## مخاطر أُغلقت في الموجة الأخيرة

1. `UpdateDocumentController` أصبح داخل مجموعة `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` + `IdentityCsrfMiddleware`.
2. متحكمات الأعمال لم تعد تحت `app/Http/Controllers/`; بقي فقط base `Controller.php`.
3. `AppServiceProvider` لم يعد composition root ضخماً: 106 أسطر + 12 module providers.
4. لا يوجد جدول هجرة بلا owner في `TABLE_OWNERS`.
5. `audit_events` و`authorizations` لم يعودا موثقين كجداول فعلية؛ كلاهما historical ghost.
6. `make verify-boundaries`, `make lint-api`, و`make analyse-api` تمر في التقييم الحالي.
7. API PHPUnit المباشر أكمل 724 اختباراً بنجاح: 716 passed، 8 skipped، و5050 assertions؛ تعطل `make test-api` كان مهلة Composer عند 300 ثانية بينما التشغيل احتاج نحو 380 ثانية.

## ترتيب العمل التالي

1. توحيد عقد Business Calendar مع wrappers؛ إثبات `api:check` ثم `build` على الناتج نفسه.
2. إصلاح اختبارات `OrganizationDrawers` الثمانية وإثبات دلالة 409/412 للمستخدم.
3. جعل `ModulePlacementInventory` و`TABLE_OWNERS` exact inventories، لا قوائم تسمح بالزيادات الصامتة.
4. إنهاء نقل Reporting/Search إلى `Features/*/Http/` أو اعتماد استثناء معماري صريح.
5. ضبط مهلة بوابة `make test-api` بما يتجاوز زمن PHPUnit المثبت.
6. تشغيل E2E وتسجيل النتيجة بجانب تاريخها.
7. بعد ذلك فقط: تنظيف lint/OpenAPI warnings وتحسين chunking بناءً على القياسات.

## الحدث الذي يغيّر التقييم

يتغير هذا التقييم عند واحد من الأحداث التالية: اعتماد goal charter، توحيد عقد Business Calendar والعميل المولّد مع build ناجح، نجاح اختبارات الويب الثمانية، تعديل inventory guards، أو توفر نتيجة E2E حديثة. عندها يُعاد تحديث [`SUMMARY.md`](SUMMARY.md) وهذه الوثيقة.
