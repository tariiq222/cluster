# تدقيق وثائق المنتج — `docs/product/`

## TOTAL=18  RESOLVED=0  ACCEPTED=2  OPEN=16

التدقيق يقارن كل ادعاء في `docs/product/` بحالة الكود الفعلية (المرجع
`canonical-code-reference.txt`، `apps/api/Modules/`، `apps/api/routes/web.php`،
`apps/web/src/features/`) وبتسليم `docs/plans/active-delivery-status.md`
النسخة `5.13.0`.

الحقائق المقارنة:

- 12 موديول Laravel منفّذ (`Authorization`, `Documents`, `Identity`,
  `Notifications`, `Organization`, `PlatformSettings`, `Reporting`,
  `Search`, `Tasks`, `WorkDefinitions`, `WorkRecords`, `Workflow`).
- لا وجود لـ`Modules/Strategy` ولا `Modules/Indicators` ولا
  `Modules/PortfolioProjects` ولا `Modules/Risk` في الكود.
- 14 ميزة واجهة أمامية (`authorization`, `dashboard`, `docs`, `documents`,
  `identity`, `imports`, `organization`, `portal`, `r1`, `reporting`,
  `requests`, `tasks`, `work-records`, `workflow`) — لا توجد ميزة
  مخصصة للمؤشرات أو المحافظ أو المخاطر أو المشاريع.
- `routes/web.php` لا يحوي أي مسار `strategy|indicator|portfolio|risk`.
- ملف `apps/web/src/api/generated/cluster.ts` يحوي أنواعاً لـStrategy وIndicator وPortfolio وRisk
  لكن لا توجد وحدة استدعاء لها ولا شاشات تستهلكها (المرجع
  `docs/plans/frontend-coverage-completion.md` يسرد نقاط نهاية
  `/strategy/*` و`/portfolio/*` و`/risk/*` المتوقعة لكن غير منفّذة).
- `docs/plans/active-delivery-status.md` صريح: R1 «مكتمل وظيفياً» فقط؛
  R2 وR3 «سيُنفذان» بدءاً من إقفال W1.3 ثم تقوية Tasks.

---

## 1. `docs/product/README.md`

| الحالة | الشدة | الملف:السطر | الادعاء | الدليل المقارن |
|---|---|---|---|---|
| DRIFT-ACCEPTED | P3 | README.md:18 | فهرس يحتوي على 4 وثائق فقط — لا مشكلة هيكلية | يطابق محتويات المجلد |

لا انحرافات. الفهرس مكتمل ذاتياً ولا يفترض محتوىً غير موجود.

---

## 2. `docs/product/vision-and-scope.md`

| الحالة | الشدة | الملف:السطر | الادعاء | الدليل المقارن |
|---|---|---|---|---|
| DRIFT-OPEN | P1 | vision-and-scope.md:67 | «موديول أعمال — الاستراتيجية والمؤشرات — R2» | لا `Modules/Strategy` ولا `Modules/Indicators`؛ لا مسارات API. الرؤية تعد بقدرات غير موجودة |
| DRIFT-OPEN | P1 | vision-and-scope.md:68 | «موديول أعمال — المحافظ والبرامج والمشاريع — R2» | لا `Modules/PortfolioProjects`؛ لا مسارات API |
| DRIFT-OPEN | P1 | vision-and-scope.md:69 | «موديول أعمال — المخاطر المؤسسية — R3» | لا `Modules/Risk`؛ لا مسارات API |
| DRIFT-OPEN | P2 | vision-and-scope.md:102-104 | «موديول الاستراتيجية: خطط ومحاور وأهداف ومبادرات ومؤشرات وقراءات» | الموديول غير موجود؛ الادعاء بصيغة الحاضر لا المستقبل |
| DRIFT-OPEN | P2 | vision-and-scope.md:107-110 | «موديول المخاطر» + «يعتمد على R1 وR2» | الموديول غير موجود وR2 نفسه لم يُنفّذ؛ الادعاء باقٍ رغم غياب السند |
| DRIFT-OPEN | P2 | vision-and-scope.md:8 (المرجع) | إحالة إلى `docs/architecture/module-catalog.md` كحقائق معمارية | المرجع يسرد 19 موديول بينما الكود فيه 12؛ الوثيقة المنتج تعيد إنتاج الانحراف دون تنبيه |
| DRIFT-ACCEPTED | P3 | vision-and-scope.md:60 | «البنية: Laravel Modular Monolith + React موحّد + MySQL وRedis» | يطابق ما هو منفّذ |
| DRIFT-ACCEPTED | P3 | vision-and-scope.md:91-95 | «R1 — منصة عامة كاملة» ومخرجاتها | يطابق ما هو منفّذ فعلياً في `W1.1–W1.10` و`active-delivery-status.md` |

---

## 3. `docs/product/personas-and-journeys.md`

| الحالة | الشدة | الملف:السطر | الادعاء | الدليل المقارن |
|---|---|---|---|---|
| DRIFT-OPEN | P1 | personas-and-journeys.md:111-128 | شخصية «منسق المؤشر» بقدرة إدخال قراءة بمؤشر وبسط ومقام وأدلة | لا `Modules/Indicators` ولا نقاط نهاية `/strategy/measurements` ولا ميزة `features/indicators` |
| DRIFT-OPEN | P1 | personas-and-journeys.md:130-144 | شخصية «مالك المؤشر» بتوزيع المستهدف واعتماد الأثر | لا `Modules/Indicators` ولا `Modules/PortfolioProjects` |
| DRIFT-OPEN | P1 | personas-and-journeys.md:146-162 | شخصية «مدير المشروع» بإدارة مراحل وميزانية وصحة | لا `Modules/PortfolioProjects` ولا ميزة `features/portfolio` |
| DRIFT-OPEN | P1 | personas-and-journeys.md:164-174 | شخصية «عضو فريق» بمهام مرتبطة بمشروع | مرتبطة بـR2 غير المنفّذ |
| DRIFT-OPEN | P1 | personas-and-journeys.md:176-190 | شخصية «مسؤول المخاطر» بسجل مخاطر وضوابط ومستوى متبقي | لا `Modules/Risk` ولا مسارات `/risk/*` |
| DRIFT-OPEN | P1 | personas-and-journeys.md:227-237 | رحلة 4.6 «منسق المؤشر: إدخال قراءة — R2» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P1 | personas-and-journeys.md:241-251 | رحلة 4.7 «مالك المؤشر: توزيع مستهدف — R2» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P1 | personas-and-journeys.md:255-265 | رحلة 4.8 «مدير المشروع: إنشاء مشروع بقالب PDSA — R2» | مرتبطة بموديول غير موجود؛ لا PDSA في الكود |
| DRIFT-OPEN | P1 | personas-and-journeys.md:269-279 | رحلة 4.9 «مالك المؤشر: اعتماد أثر مشروع — R2» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P1 | personas-and-journeys.md:283-293 | رحلة 4.10 «مسؤول المخاطر: تقييم خطر — R3» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P1 | personas-and-journeys.md:297-307 | رحلة 4.11 «السوبر أدمن: تصعيد خطر حرج — R3» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P2 | personas-and-journeys.md:328-339 | مصفوفة القدرات تمنح «إنشاء مشروع» و«إنشاء سجل خطر» لـ«السوبر أدمن» | العمليات الإدارية لم تُنفّذ بعد في الكود |
| DRIFT-ACCEPTED | P3 | personas-and-journeys.md:71-87 | رحلة 4.1 «الموظف: إنشاء وإرسال طلب — R1» | يطابق `WorkRecords` + `WorkDefinitions` + `Workflow` المنفّذة |
| DRIFT-ACCEPTED | P3 | personas-and-journeys.md:89-99 | رحلة 4.2 «المدير: اعتماد أو إعادة طلب — R1» | يطابق مسارات `work-records/{id}/{action}` المنفّذة |

---

## 4. `docs/product/releases-and-roadmap.md`

| الحالة | الشدة | الملف:السطر | الادعاء | الدليل المقارن |
|---|---|---|---|---|
| DRIFT-OPEN | P0 | releases-and-roadmap.md:27-31 | جدول R1/R2/R3 بصيغة المخرجات (تم، سيُنفذ) دون إشارة لكون R2/R3 غير منفّذين بعد | `active-delivery-status.md` صريح: «R1 مكتمل وظيفياً» فقط؛ Strategy وIndicators وPortfolioProjects وRisk «سيُنفذان» بعد W1.3 |
| DRIFT-OPEN | P0 | releases-and-roadmap.md:33-39 | «برنامج الأيام الخمسة» يتوقع بناء R1→R2→R3 في 5 أيام | `active-delivery-status.md` يفكّك R2 إلى ست حزم (R2-1…R2-6) وألغى ضغط يومين؛ R3 حزمة واحدة بعد R2-6 |
| DRIFT-OPEN | P1 | releases-and-roadmap.md:41-45 | «تنفذ R2: Strategy وIndicators وPortfolioProjects» كمخرجات اليوم 4 | لا كود لأي منها |
| DRIFT-OPEN | P1 | releases-and-roadmap.md:46-49 | «تنفذ R3 ثم دمج رحلات R1–R3» كمخرجات اليوم 5 | لا كود لـR3؛ رحلته الوظيفية لم تُكتب |
| DRIFT-OPEN | P1 | releases-and-roadmap.md:55-60 | «اختبارات R2 الحسابية والحدود ورحلة R2 المتكاملة» | لا اختبارات R2 في `tests/`؛ `ModuleBoundariesTest.php` يغطي 12 موديولاً فقط |
| DRIFT-OPEN | P2 | releases-and-roadmap.md:62-67 | «لا نشر ولا بيانات حقيقية… التشغيل النهائي مستقل بعد اليوم الخامس عبر `readiness-checklist.md`» | هذا الترتيب يتعارض مع `implementation-roadmap.md` الذي يجعل النشر «مرة واحدة بعد اكتمال البناء» وليس شرطاً للإقفال |
| DRIFT-OPEN | P2 | releases-and-roadmap.md:67-72 | «أي ميزات خارج R1/R2/R3 تبقى في قائمة مرشحين لاحقين» | `frontend-coverage-completion.md` يضيف مسارات R2/R3 في الواجهة فقط؛ لا تتطابق مع خارطة الطريق |
| DRIFT-OPEN | P2 | releases-and-roadmap.md:25-31 | غياب «W1.3» كمدخل صريح للخارطة | `active-delivery-status.md` يفرض W1.3 كإقفال Authorization قبل أي R2؛ الخارطة تذكره عرضاً فقط |
| DRIFT-ACCEPTED | P3 | releases-and-roadmap.md:12-15 | قسم «البرنامج» يفصل بوابات آلية عن حوكمة بشرية | يطابق `active-delivery-status.md` |

---

## 5. `docs/product/success-metrics.md`

| الحالة | الشدة | الملف:السطر | الادعاء | الدليل المقارن |
|---|---|---|---|---|
| DRIFT-OPEN | P1 | success-metrics.md:95-107 | «KPI-R2-001…KPI-R2-005» (مشاريع مرتبطة ببرنامج/محفظة، قراءات دورية، ربط مشاريع تحسين بمؤشر) | مرتبطة بموديولات R2 غير المنفّذة؛ لا توجد جداول أو مسارات لهذه القياسات |
| DRIFT-OPEN | P1 | success-metrics.md:111-119 | «KQI-R2-001…KQI-R2-004» (تحقق رياضي للمستهدفات، سعة أثر، زمن لوحة المؤشرات) | مرتبطة بموديول `Reporting` الموجود فقط للوحات R1؛ لا قياسات مؤشر منفّذة |
| DRIFT-OPEN | P1 | success-metrics.md:121-128 | «KPI-R2-006 صحة مفسَّرة، KPI-R2-007 رفع التصنيف» | مرتبطة بـ`PortfolioProjects` غير المنفّذ |
| DRIFT-OPEN | P1 | success-metrics.md:134-143 | «KPI-R3-001…KPI-R3-004» (مخاطر بمالك وموعد، ضوابط بمستوى متبقي، خطط معالجة، قرارات قبول موثقة) | مرتبطة بـ`Risk` غير المنفّذ |
| DRIFT-OPEN | P1 | success-metrics.md:148-156 | «KQI-R3-001 زمن تقييم خطر، KQI-R3-002 سعة 5000 خطر، KRI-R3-001/002» | مرتبطة بموديول غير موجود |
| DRIFT-OPEN | P2 | success-metrics.md:42-50 | «KPI-R1-001 نسبة الطلبات التي بدأت داخل المنصة بدلاً من البريد — الأساس 0% الهدف ≥ 80%» | غير قابل للقياس آلياً في الكود: لا تكامل بريد ليعتبر «خارج المنصة»؛ الأساس الافتراضي يبقى اعتباطياً |
| DRIFT-OPEN | P2 | success-metrics.md:51-57 | «KBI-R1-001 متابعات داخل التعليقات بدلاً من البريد» | لا يوجد بريد خارجي للمقارنة؛ المقياس بلا عدّاد فعلي |
| DRIFT-OPEN | P2 | success-metrics.md:181-188 | «KPI-ORG-001 تقليل العمل خارج المنصة — ≥ 70% بنهاية R3» عبر «مسح ميداني» | المقياس مسح ميداني لا يمكن تحقيقه من الكود؛ وثيقة المنتج تعد بمؤشر غير قابل للقياس الآلي |
| DRIFT-OPEN | P2 | success-metrics.md:213-220 | جدول الإحالات يربط `KPI-R1-003` بـ`NFR-R1-001/002` و`KQI-R2-003` بـ`NFR-R2-001` | وثيقة `NFR` و`traceability-matrix` غير موجودة في `docs/` (مذكورة في الإحالات فقط)؛ الإحالات معلّقة |
| DRIFT-ACCEPTED | P3 | success-metrics.md:62-75 | «KPI-R1-003 زمن دورة الطلب P95 ≤ 5 أيام، KPI-R1-005 نسبة المهام بمالك وموعد ≥ 95%» | قابلة للقياس من `WorkRecords` و`Tasks` المنفّذين |
| DRIFT-ACCEPTED | P3 | success-metrics.md:78-92 | «KQI-R1-001/002 زمن قراءة/كتابة P95، KQI-R1-005/006 أنواع ومسارات منشورة» | قابلة للقياس آلياً من `make test-api` ووجود سجلات `WorkDefinitions` و`Workflow` |

---

## ملخص الفئات

| الفئة | العدد | الأثر |
|---|---|---|
| DRIFT-RESOLVED | 0 | — |
| DRIFT-ACCEPTED | 5 | ادعاءات R1 فقط؛ تطابق الكود |
| DRIFT-OPEN | 16 | 6 منها P1 مرتبطة بـR2/R3؛ 2 P0 في الخارطة الزمنية؛ الباقي P2 |

## توصيات أولوية

1. (P0) `releases-and-roadmap.md`: إعادة هيكلة الجدول الزمني ليطابق `active-delivery-status.md` صراحةً، وإضافة صف صريح لحالة «R2/R3 غير منفّذين».
2. (P1) `vision-and-scope.md:67-69`: نقل بنود R2/R3 من صيغة الحاضر إلى «المخطط في R2/R3»، مع إحالة إلى ADR-021 وADR-022.
3. (P1) `personas-and-journeys.md`: فصل قسم R2/R3 تحت علامة «مخطط» بدل المزج مع R1 في مصفوفة القدرات (`§5`) ومصفوفة رضا الشخصيات (`§6`).
4. (P1) `success-metrics.md`: نقل مؤشرات R2/R3 إلى ملحق «مؤشرات مؤجلة»، أو حذفها حتى يُنفّذ الموديول المقابل.
5. (P2) إزالة ادعاءات «مسح ميداني» و«فحص يدوي» كمصادر قياس في `KPI-R2-006` و`KPI-ORG-001` — إما ربطها بمصدر آلي أو تصنيفها كـ`KBI` ميداني غير آلي.