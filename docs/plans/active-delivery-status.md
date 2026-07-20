---
doc_id: PLN-AS-001
title: حالة التسليم النشطة
type: plans
status: accepted
version: 5.13.0
date: 2026-07-20
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: نهاية كل يوم تنفيذ
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
references:
- docs/engineering/delivery-workflow.md
---
# حالة التسليم النشطة

## الحالة الآن

- **W1.1 مكتملة**: `make verify-w1-1` و`make verify-w1-1-local` أخضران.
- **W1.2 مكتملة**: شجرة Organization وIdentity والحسابات والتكليفات والاستيراد
  وواجهاتها ورحلة MinIO/ClamAV موجودة في `main`، و`make verify-w1-2` ورحلة
  `infra/dev/run-w1-2-e2e.sh` خضراوان في دليل الدمج.
- **W1.3 مكتملة وظيفياً ومفتوحة تكاملياً**: بوابة `make verify-w1-3` خضراء، لكن
  المراجعة أثبتت أن `DecideAccess` مربوط بـ`FixtureFacilityDecision` وأن
  `RbacAbacDecideAccess` وإدارة RoleCapability والنطاقات وسياسات الحقول لا تقود
  الوصول التشغيلي كاملاً. خطة الإقفال في `release-1/w1-3-frontend-slices.md`.
- **Stage 0 من إقفال W1.3 أُغلق**: دُمجت commits `3e31d54` و`420aa0e`
  لإصلاحات role deny وexplanation وprojection coherence إلى جانب
  commits `6fc1e36` و`f7d0f4b` و`da64419` السابقة. `make verify-w1-3` و
  `infra/dev/run-w1-3-e2e.sh` خضراوان: 85/85 وحدة و17/17 رحلة و4/4 حدود
  و40/40 web ورحلة متصفح. الخطوة التالية تنفيذ
  `release-1/w1-3-frontend-slices.md` بعد إعادة تحقق R1 كاملاً بالمحرك
  الحقيقي.
- **`make verify-w1-1` و`make verify-w1-2` معلّقتان على موجتين لاحقتين من خطة
  W1.3 frontend slices**: 79 فشل اختبار في `test-api` تنبع من ترحيل جميع مسارات
  API إلى `RequireIdentitySessionPrincipal` بينما اختبارات الموديولات لا تزال
  تستخدم fixture bearer من `/api/v1/auth/login`؛ هذا هو نطاق الموجة الثانية
  («ربط SessionPrincipalResolver والمحرك الحقيقي، وإبقاء fixture في
  `local/testing`»). وكذلك drift عقد WorkRecordResponse وWorkRecordCollection
  (وضع access metadata داخل data بدلاً من المستوى الأعلى) هو نطاق الموجة
  الخامسة («إسقاط الواجهة: endpoint للسياق الشخصي وallowed_actions وfield_access»).
  الخطوة التالية تفكيك هاتين الفجوتين كقطعتي عمل مستقلتين.
- **موجتا إقفال W1.3 الثانية والثالثة أُغلقتا**: commits `00c510d` و`b0f7df2`
  و`e52293c` و`f692341` أكملت ترحيل fixture bearer إلى جلسة الهوية في بيئة
  الاختبار مع بقاء production مربوطاً بالجلسة المثبتة فقط. المجموعة الكاملة
  `php artisan test` خضراء: ‏362/362 ناجحاً بعد أن كانت 79 فشلاً، وبوابتا
  `verify-w1-2` و`verify-w1-3` خضراوان. بقايا معروفة: `analyse-api` عند 22
  ملاحظة phpstan (مقابل 23 على main؛ لم تكن ظاهرة لأن pint كان يوقف البوابة
  قبلها).
- **W1.4–W1.7 مكتملة**: إنشاء ونشر WorkDefinition وWorkflow، تثبيت الإصدار،
  إنشاء WorkRecord وإرساله وإعادته، وإنشاء Task وإكمالها مثبتة ببوابة
  `make verify-day2` الخضراء.
- **W1.8–W1.10 مكتملة**: Documents/Notifications وSearch/Reporting/Dashboard
  ورحلة القبول الثنائية مثبتة ببوابة `make verify-day3` الخضراء، مدفوعة في
  `main@99a25db` وCI ‏`29681030768` أخضر. اكتمل R1 بتعريف الاكتمال في
  `release-1-platform.md`. هذا إقفال للرحلة الوظيفية، وليس إقفالاً أمنياً نهائياً
  حتى تمر بوابة W1.3 المحدّثة وتعاد اختبارات R1 بالمحرك الحقيقي.

لا يعاد التخطيط أو التنفيذ لـW1.1 وW1.2 إلا عند ظهور انحدار.

## الهدف الجاري

إقفال تكامل Authorization أولاً، ثم تقوية Tasks كقدرة مشتركة، ثم تنفيذ دورة
Strategy الكاملة وPortfolioProjects وربط الأثر وفق
`docs/plans/release-2-strategy-portfolio.md`. لا توجد بوابة بشرية بين الحزم؛
الاعتماديات تقنية وتمنع بناء R2 على fixture أو عقد مهام ناقص.

## ترتيب التنفيذ

1. تنفيذ خطة إقفال W1.3 بالمحرك الحقيقي وإعادة تحقق R1 كاملاً.
2. تقوية Tasks وتطبيق قرار الوصول الحقيقي على المهام والمصادر المرتبطة.
3. بناء التحليل والصياغة والخطة والبطاقة والأهداف والمؤشرات والمراجعات في Strategy.
4. بناء المحافظ والبرامج والمشاريع وربطها بالمبادرات والمؤشرات واعتماد الأثر.
5. بناء R3 كشريحة خطر-ضابط-معالجة-KRI مرتبطة بـR2.
6. تشغيل التحقق المتكامل ثم الانتقال للنشر الآلي عند توفر مدخلات الخادم.

## أدلة خط الأساس

| التاريخ | الدليل | النتيجة |
|---|---|---|
| 2026-07-17 | `make verify-w1-1` و`make verify-w1-1-local` | W1.1 كاملة محلياً من المتصفح إلى MySQL وRedis |
| 2026-07-18 | `make verify-w1-2` | عقود W1.2 وAPI وWeb والحدود والبناء خضراء |
| 2026-07-18 | `infra/dev/run-w1-2-e2e.sh` | رفع CSV وفحصه واستيراده والتكليف المؤقت تعمل على MinIO وClamAV |
| 2026-07-18 | CI `29659562157` ثم إصلاح التنسيق المدمج | حزمة الإنتاج والوثائق والأمن خضراء؛ إصلاح Pint موجود في تاريخ `main` |
| 2026-07-19 | تنظيف Jujutsu ومساحات العمل | أزيلت revisions الفارغة غير المرتبطة وبقايا `frontman` وكاشها ومجلد Git worktrees الفارغ؛ بقيت مساحات العمل وتغييراتها، و`main` و`origin/main` عند `e0dbaaab` وقت التسجيل |
| 2026-07-19 | جرد `work-1-3*` | W1.3 بدأت فعلياً في ست حزم Authorization وOrganization قابلة للدمج |
| 2026-07-19 | توحيد مساحات jj في مستودع واحد | حُذفت المساحات الثماني وأزيلت 5 نسخ مكررة بعد مقارنة interdiff؛ خط W1.3 كاملاً تحت bookmark `work-1-3@ffa40533` والعمل يستمر من مجلد `cluster` وحده |
| 2026-07-19 | تصحيح التوحيد: worktree ‏`cluster-w13` بقي فعلياً وفيه أحدث أعمال W1.3 | دُمج خطه المكوَّن commits (واجهة الإدارة + طبقة HTTP) في `work-1-3@0a4c352c`؛ الـworktree يبقى حتى تنتهي الجلسة النشطة فيه |
| 2026-07-19 | إصلاح تلوث autoload في `cluster` (كان يشير لمسارات `cluster-w13`) وتثبيت تبعيات الويب | `composer dump-autoload` و`npm install`؛ اختبارات Authorization وOrganization ‏50/50، حدود الموديولات 4/4، اختبارات الويب 27/27، وبناء الويب أخضر |
| 2026-07-19 | `work-1-3@93e4632` و`make verify-w1-3` | أغلقت W1.3: ‏50 اختبار API و4 حدود و27 Web unit وlint/build ورحلتا E2E خضراء |
| 2026-07-19 | `work-day2-r1@ddf6ded` و`make verify-day2` | أغلقت W1.4–W1.7: إنشاء ونشر التعريف والمسار ثم إنشاء الطلب وإرساله وإعادته وإنشاء المهمة وإكمالهما بالعربية RTL والإنجليزية LTR |
| 2026-07-19 | دفع `main@92a4cb6` ثم `make verify-w1-1-local` و`make verify-day2` و`make scan-secrets` | أصلح احمرار CI: أسماء فهارس MySQL فوق 64 حرفاً، ترتيب ترحيل العلاقات الإشرافية، صلاحية triggers مع binlog، مواءمة `login.spec.ts` مع تصميم Frontman، واستثناءات gitleaks لقيم اختبارية؛ حزمة الإنتاج ورحلاتها خضراء محلياً |
| 2026-07-19 | `work-day3-r1@098b78af` ثم الدمج في `main@99a25db`؛ `make verify-day3` و`make verify-w1-1-local` و`make scan-secrets` و`./scripts/validate-docs.sh`؛ CI ‏`29681030768` | أغلقت W1.8–W1.10 واكتمل R1: رحلة W1.3–W1.10 تعمل بالعربية RTL والإنجليزية LTR من إنشاء النوع والمسار إلى المستند والإشعار والبحث والتقرير واللوحة ضمن النطاق |
| 2026-07-19 | `work-screens-r1`؛ `make verify-screens` و`make verify-w1-1-local` و`make scan-secrets` و`./scripts/validate-docs.sh` | أغلقت فجوة شاشات R1 بعقد Orval مولّد وشاشات RTL/LTR واختبارات API/Web/E2E وبوابة MySQL المحلية الخضراء |
| 2026-07-20 | Stage 0 commits `6fc1e36` و`f7d0f4b` و`da64419`؛ آخر تشغيل مركز `php artisan test Modules/Authorization/Tests/AuthorizationPolicyAdminHttpAdapterTest.php tests/Feature/SecurityJourneyW13Test.php` | نُفذت الحقائق الموثوقة وBootstrap وعزل الإدارة، لكن الإصلاح المتكامل غير معتمد بعد؛ التالي إغلاق حالات role deny وSearch/Reporting projection وaccess-decision explanation، ثم تشغيل المجموعة المركزة و`make verify-boundaries` قبل module facts adapters وOpenAPI/Orval |
| 2026-07-20 | commits `3e31d54` و`420aa0e`؛ `make verify-w1-3` و`infra/dev/run-w1-3-e2e.sh` | أُغلق Stage 0 فعلياً: 85/85 وحدة و17/17 رحلة و4/4 حدود و40/40 web ورحلة متصفح خضراء؛ أصلح role deny وexplanation وprojection coherence؛ أزيل اعتماد fixture من ركيزة قرار الوصول؛ الخطوة التالية W1.3 frontend slices ثم تقوية Tasks |

## قاعدة التحديث

يضاف سطر واحد في نهاية كل يوم: revision، أمر التحقق، والرحلة التي أصبحت تعمل.
لا نسب تقدم، ولا أسماء معتمدين، ولا اجتماعات، ولا سرد لكل commit.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 5.8.0 | 2026-07-20 | تسجيل حالة Stage 0 الفعلية: commits المدمجة، بوابة التحقق غير الخضراء، والحالات الثلاث التالية قبل module facts adapters وOpenAPI/Orval |
| 5.9.0 | 2026-07-20 | تسجيل إقفال Stage 0: commits `3e31d54` و`420aa0e`، بوابة `verify-w1-3` ورحلة المتصفح خضراوان، الخطوة التالية W1.3 frontend slices ثم تقوية Tasks |
| 5.10.0 | 2026-07-20 | تأجيل `verify-w1-1` و`verify-w1-2` على نطاق موجتي W1.3 frontend slices 2 و5: ترحيل اختبارات fixture bearer إلى session login (79 فشل)، وإصلاح drift عقد WorkRecordResponse/WorkRecordCollection (وضع access metadata داخل data) |
| 5.11.0 | 2026-07-20 | إقفال موجتي W1.3 الثانية والثالثة: المجموعة الكاملة خضراء 362/362 بعد 79 فشلاً، `verify-w1-2` و`verify-w1-3` خضراوان، وتفكيك App.tsx من 1314 إلى 472 سطراً عبر `ed3b4e0` |
| 5.12.0 | 2026-07-20 | إغلاق phpstan: 22 ملاحظة في `analyse-api` إلى 0 عبر إصلاحات العقد الفعلية (لا suppressions) في `3f03818`؛ pint وtest 362 والمجموعة الكاملة ما زالت خضراء |
| 5.13.0 | 2026-07-20 | إغلاق `test-w1-1-api-worker-smoke` و`test-e2e-w1-1`: smoke مرّ بعد إكمال bootstrap في الـapi_env وسياسة كلمات مرور آمنة وترتيب FK في down() وmock متغيرات W1.2 لـdocker compose؛ e2e مرّ بعد تحديث specs (shell mock لجلسة identity/login، copy.en.switchLanguage='العربية'، إزالة assertions brittle لوصف السجل) عبر `e0be11a` و`59f97f0` و`f4bf6d2`. `make verify-w1-1` أخضر بالكامل لأول مرة (pint 0 + phpstan 0 + 362 وحدة + 80 web + 3 phpunit mysql + e2e). |
| 5.7.0 | 2026-07-19 | توثيق توسعة R2 إلى دورة Strategy الكاملة، وإدخال تقوية Tasks قبلها، وفصل PortfolioProjects وربط الأثر ضمن ترتيب التنفيذ النشط |
| 5.6.0 | 2026-07-19 | إعادة فتح W1.3 تكاملياً بعد مراجعة ربط محرك القرار، ووضع إقفال Authorization قبل R2 مع بقاء دليل الرحلة الوظيفية محفوظاً |
| 5.5.0 | 2026-07-19 | إغلاق فجوة شاشات R1 وربطها كاملة بعميل Orval المولّد وإضافة بوابة `verify-screens` |
| 5.4.1 | 2026-07-19 | تثبيت الإقفال الخارجي لليوم الثالث: CI ‏`29681030768` أخضر على `main@99a25db` ونقل سطر الإقفال إلى جدول الأدلة |
| 5.4.0 | 2026-07-19 | تنفيذ W1.8–W1.10 مع بوابة اليوم الثالث ورحلة MySQL/ClamAV وRTL/LTR |
| 5.3.1 | 2026-07-19 | إخضرار بوابة الإنتاج: إصلاح الترحيلات على MySQL الإنتاجي ومواءمة اختبار الدخول مع التصميم الحالي |
| 5.3.0 | 2026-07-19 | إقفال اليوم الثاني W1.4–W1.7 ببوابة تحقق ورحلة متصفح كاملة |
| 5.2.0 | 2026-07-19 | إقفال W1.3 ببوابة تحقق موحدة ورحلة متصفح حقيقية |
| 5.1.0 | 2026-07-19 | تصحيح حالة التوحيد ودمج خط `cluster-w13` في `work-1-3@0a4c352c` وإصلاح تلوث autoload وتسجيل نتائج التحقق |
| 5.0.0 | 2026-07-19 | تثبيت W1.1 وW1.2 كخط أساس منجز وفتح برنامج الخمسة أيام من W1.3 بلا حوكمة بشرية |
| 4.7.0 | 2026-07-19 | تسجيل تنظيف revisions ومساحات العمل الفارغة أو غير المسجلة دون تغيير كود المنتج |
| 4.6.0 | 2026-07-18 | تسجيل تحديث صفحة الدخول المحلية وأدلة RTL/LTR والعزل والتراخيص |
