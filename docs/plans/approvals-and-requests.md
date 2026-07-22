---
doc_id: PLN-APV-001
title: خطة موديول الطلبات والاعتمادات
type: plans
status: draft
version: 1.0.0
date: 2026-07-22
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: بعد إنجاز كل مرحلة
sources:
  - docs/contracts/api/openapi.yaml
references:
  - docs/plans/implementation-roadmap.md
  - docs/plans/active-delivery-status.md
---

# خطة موديول الطلبات والاعتمادات

## 1. المبدأ الحاكم

الموديول الحالي «الإجراءات وسير العمل» هو في حقيقته **موديول اعتمادات، لا موديول
مهام**. طلب المشروع وطلب التقرير وطلب الإجازة وطلب التكليف كلها مسارات اعتماد،
ولا علاقة لها بالمهمة كوحدة عمل قابلة للإسناد.

| المحور | الاعتماد | المهمة |
|---|---|---|
| السؤال | أوافق أم لا؟ | هل أنجزتُه؟ |
| النتيجة | قرار وسبب يحرّك المسار | عمل مكتمل لا يحرّك شيئاً |
| التعاون | قرار فردي مسؤول عنه | مشاركون وتعليقات وإشارات |
| التفويض | تفويض صلاحية اعتماد | إعادة إسناد عمل |
| الجدول | `workflow_step_instances` | `tasks` |

القاعدة التنفيذية المشتقة من ذلك:

- عقدة `approval` تُنتج خطوة تظهر في وارد الاعتمادات، **بلا صف في `tasks`**.
- عقدة `task` وحدها تُنتج مهمة حقيقية بمدة وأولوية ومشاركين.
- المهمة قد تكون **نتيجة** طلبٍ اعتُمد، لا **وسيلة** اعتماده.

قاعدة ثانية تحكم المفردات: **الأنواع الأولية كود يُكتب مرة، وتركيباتها بيانات بلا
حد**. أي حاجة خارج المفردات تُعالج بنوع عقدة جديد له كود واختبار، لا بتمديد صيغة
الشروط. الشروط تبقى مقيّدة إلى `field op literal` بلا دوال ولا تعبيرات متداخلة.

## 2. الحالة المقيسة

| القدرة | الحالة | الدليل |
|---|---|---|
| تعريفات وإصدارات ورسم ونشر | مبني | `Modules/Workflow` |
| تثبيت النسخ الجارية على إصدارها | مبني | `workflow_instances.workflow_version_id` |
| ملكية خطوة الاعتماد | مبني | هجرة `W14AddWorkflowStepAssignee` |
| الهرم الإداري وعلاقات الإشراف | مبني | `Modules/Organization` |
| الإشعارات والأحداث والقفل المتفائل | مبني | `notifications`، outbox، `lock_version` |
| تنفيذ الانتقالات بين العقد | مفقود | `StartWorkflowHandler` ينشئ خطوة واحدة |
| مفردات العقد وقواعد الإسناد | مفقود | `decision_policy` مخزّن ولا يُقرأ |
| نطاق الملكية للتجمع والمنشأة | مفقود | لا أعمدة نطاق على `workflow_definitions` |
| وارد الاعتمادات وتتبّع الطلب | مفقود | لا شاشة تستهلك مسارات القرار |

## 3. المراحل

### المرحلة 0 — ملكية خطوة الاعتماد (مكتملة)

الهدف: فصل الاعتماد عن المهمة على مستوى المخطط، وهو حجر الأساس لكل ما بعده.

الملفات: `Modules/Workflow/Infrastructure/Persistence/Migrations/W14AddWorkflowStepAssignee.php`،
`Modules/Workflow/Features/StartWorkflow/Handler/StartWorkflowHandler.php`،
`app/Http/Controllers/Api/WorkflowController.php`، `app/Providers/AppServiceProvider.php`.

ما أُنجز:

- عمود `assignee_user_id` على `workflow_step_instances` وفهرس
  `[assignee_user_id, state]` الذي يخدم استعلام وارد الاعتمادات.
- الخطوة تأخذ صاحبها من الرسم إن سمّاه، وإلا فمُطلِق المسار.
- قرار الاعتماد مقصور على صاحب الخطوة، بعد أن كان كل حامل لـ `workflow.decide`
  يعتمد خطوة أي شخص.
- إعادة الإسناد تحرّك الخطوة نفسها. كانت تشترط `workflow_step_instances.task_id`
  وهو عمود لا يكتبه أي سطر في المستودع، أي أن العملية كانت ترجع `409` دائماً.
  وبزوال ذلك زالت كتابة موديول سير العمل في جدول `tasks`.

الاختبارات: `Modules/Workflow/Tests/WorkflowStepAssigneeTest.php` (ثلاث حالات)،
`tests/Feature/WorkflowStepReassignHttpTest.php` (الدورة الكاملة: خطوة لها صاحب،
ثم إعادة إسناد، ثم منع المعتمِد السابق بـ `403`، مع بقاء الخطوة `waiting`).

الدليل: `php artisan test` عند 417 اختباراً منها 412 ناجح و5 متخطاة سابقاً،
و`make verify-boundaries` أخضر بأربعة اختبارات.

### المرحلة 1 — وارد الاعتمادات

الهدف: تحويل ما بُني في المرحلة 0 إلى شيء مرئي، وإخراج الاعتمادات من شاشة «مهامي».

الملفات المملوكة:

- `app/Http/Controllers/Api/WorkflowController.php` و`routes/web.php`: مسار
  `GET /workflow/steps` يفلتر بصاحب الخطوة وحالتها.
- `docs/contracts/api/openapi.yaml`: تعريف العملية وschema الخطوة.
- `apps/web/src/api/`: غلاف مولّد عبر orval، بلا نداء يدوي.
- `apps/web/src/features/workflow/`: شاشة «اعتماداتي» بقرار اعتماد أو رفض مع سبب.

معايير القبول:

- الوارد يعرض خطوات المستخدم الحالي فقط، ولا يسرّب خطوات غيره.
- كل حالة عرض مغطاة: تحميل، فارغ، ممنوع، خطأ، نجاح.
- القرار يرسل `If-Match` و`Idempotency-Key`، ويعالج `409` و`412` برسائل عربية.
- الشاشة تعمل RTL وLTR، ولها عناوين وأدوار وصول صحيحة.

الاختبارات:

- API: `tests/Feature/ApprovalInboxHttpTest.php` — الفلترة، المنع، الترقيم.
- الويب: اختبار وحدة للشاشة يغطي الحالات الخمس.
- E2E: `apps/web/e2e/approvals-inbox.spec.ts` — تقديم طلب ثم اعتماده من الوارد.

أمر التحقق:

```
cd apps/api && php artisan test tests/Feature/ApprovalInboxHttpTest.php Modules/Workflow/Tests
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
make verify-day2
```

### المرحلة 2 — تصحيح أساس تعدد المنشآت

الهدف: منع خلل صامت يزداد كلفةً مع كل يوم بيانات إضافية.

الملفات المملوكة: هجرة جديدة في `Modules/Workflow/Infrastructure/Persistence/Migrations/`،
و`app/Http/Controllers/Api/WorkflowController.php`.

معايير القبول:

- أعمدة `scope` و`owner_scope_id` و`is_system` على `workflow_definitions`.
- قيد التفرّد يصبح مركّباً على `[owner_scope_id, code]`. اليوم `code` فريد عالمياً،
  فأول منشأة تُنشئ رمزاً تحجزه على التجمع كله.
- التجمع يُشتق من هوية المستخدم لا من أول صف أبجدياً في جدول `clusters`.
- مسار بنطاق تجمع يظهر لكل المنشآت، ومسار بنطاق منشأة لا يظهر لغيرها.

الاختبارات: `Modules/Workflow/Tests/WorkflowScopeTest.php` — منشأتان تنشئان الرمز
نفسه بنجاح، ومنشأة لا ترى مسار منشأة أخرى، والتجمع يُشتق صحيحاً لكل مستخدم.

أمر التحقق:

```
cd apps/api && php artisan test Modules/Workflow/Tests tests/Architecture/ModuleBoundariesTest.php
make test-api
```

### المرحلة 3 — المحرّك ينفّذ الانتقالات

الهدف: أن يقرأ المحرّك `transitions` فعلياً بدل إنشاء خطوة واحدة والتوقف.

الملفات المملوكة: `Modules/Workflow/Infrastructure/Persistence/WorkflowStepAdvancer.php`،
`Modules/Workflow/Features/StartWorkflow/Handler/StartWorkflowHandler.php`،
`Modules/Workflow/Contracts/AdvanceWorkflowStep.php`.

معايير القبول:

- اكتمال خطوة يفعّل العقدة التالية وفق الرسم، وينشئ خطوتها بصاحبها وحالتها.
- نفاد الخطوات المفتوحة ينهي نسخة التشغيل. المنطق موجود جزئياً في
  `WorkflowController` وينتقل إلى المحرّك.
- الرفض يوقف التقدم ويعيد الطلب وفق سياسة العقدة.
- كل انتقال يكتب حدث outbox في المعاملة نفسها، والمستهلك idempotent.
- الرسوم ذات الفروع تنتقل وفق شرط `field op literal` فقط.

الاختبارات: `Modules/Workflow/Tests/WorkflowEngineTest.php` — مسار من ثلاث عقد يمر
كاملاً، ورفض يوقف السلسلة، وفرع شرطي يختار المسار الصحيح، وإعادة تشغيل الحدث
لا تُنشئ خطوة مكررة.

أمر التحقق:

```
cd apps/api && php artisan test Modules/Workflow/Tests Modules/Tasks/Tests
make verify-day2
```

### المرحلة 4 — المفردات وقواعد الإسناد

الهدف: أن يصبح «يمر بمديري الإداري ثم الموارد البشرية» بيانات تُهيَّأ لا كوداً يُكتب.

الملفات المملوكة: `Modules/Workflow/Domain/`، عقد جديد يستهلك
`Modules/Organization/Contracts/GetActiveSupervisoryRelationships`.

المفردات المغلقة:

| نوع العقدة | قاعدة الإسناد |
|---|---|
| `approval` | `supervisor_of_initiator` |
| `task` | `supervisor_of_step` |
| `escalation_chain` | `role` |
| `notify` | `unit_manager` |
| `action` | `specific_user` |

معايير القبول:

- قاعدة الإسناد تترجَم إلى `assignee_user_id` عبر عقد بين الموديولين، بلا استعلام
  ولا join مباشر بين جداولهما.
- `escalation_chain` يصعد الهرم بعمق فعلي متغير حتى جهة محددة، مع سقف مستويات.
- رمز أو قاعدة غير معروفة ترفَض عند النشر لا عند التشغيل.
- منع الاعتماد الذاتي: من صاغ الطلب لا يعتمده ولو وقع ضمن السلسلة.

الاختبارات: `Modules/Workflow/Tests/AssignmentRulesTest.php` — كل قاعدة على حدة،
وسلسلة تصعيد بعمقين مختلفين حسب موقع مقدّم الطلب، ورفض قاعدة مجهولة عند النشر،
ومنع الاعتماد الذاتي.

أمر التحقق:

```
cd apps/api && php artisan test Modules/Workflow/Tests Modules/Organization/Tests tests/Architecture/ModuleBoundariesTest.php
make verify-boundaries
```

### المرحلة 5 — الفصل والتسمية وصناديق الوارد الأربعة

الهدف: إخراج المهام من الموديول، وفصل ما تضغطه اليوم شاشة واحدة.

| الصندوق | محتواه | مصدره |
|---|---|---|
| اعتماداتي | تنتظر قراري | `workflow_step_instances` |
| طلباتي | قدّمتها وأتابعها | `workflow_instances` |
| مهامي | عمل أنجزه وأغلقه | `tasks` |
| للعلم | لا إجراء مطلوب | `notifications` |

الملفات المملوكة: `apps/web/src/app/copy.ts`، `apps/web/src/shell/routes.ts`،
`apps/web/src/features/workflow/ProcessWorkspace.tsx`،
`apps/web/src/features/r1/R1Screens.tsx`.

معايير القبول:

- الموديول يُسمّى «الطلبات والاعتمادات»، والمهام موديول مجاور مستقل.
- الروابط العميقة القائمة تبقى صالحة أو تُعاد توجيهها، بلا كسر إشارات مرجعية.
- شاشة تتبّع الطلب تعرض أين وصل الطلب ومن يمسكه ومنذ متى.
- التسميات العربية والإنجليزية مكتملة، وشجرة التنقل تعكس الفصل.

الاختبارات: تحديث `apps/web/src/shell/routes.test.ts` و`apps/web/src/app/copy.test.ts`،
واختبار وحدة لكل صندوق، و`apps/web/e2e/requests-and-approvals.spec.ts` يغطي
الرحلات الأربع.

أمر التحقق:

```
npm --prefix apps/web run test:unit
npm --prefix apps/web run lint
npm --prefix apps/web run build
make verify-screens
```

### المرحلة 6 — عقدة الإجراء

الهدف: أن ينتهي المسار بأثر فعلي على موديول آخر لا بتوثيق اعتماد فقط.

معايير القبول:

- عقدة `action` تستدعي عقد الموديول الهدف عبر contract أو event، بلا كتابة مباشرة
  في جداوله.
- فشل الإجراء لا يترك المسار في حالة معلّقة، ويظهر سببه للمعتمِد الأخير.
- الإجراء idempotent: إعادة تشغيل الحدث لا تُنشئ التكليف مرتين.

الاختبارات: `Modules/Workflow/Tests/WorkflowActionNodeTest.php` — نجاح ينشئ الأثر
مرة واحدة، وفشل يوقف بحالة واضحة، وإعادة التشغيل لا تكرر.

أمر التحقق:

```
cd apps/api && php artisan test Modules/Workflow/Tests tests/Architecture/ModuleBoundariesTest.php
make test-api
```

### المرحلة 7 — مسار الحوكمة المزروع

الهدف: أن يمر إنشاء سير العمل نفسه بسير عمل. النظام يحكم نفسه.

سلسلة الاعتماد: مصمّم المسار، ثم مدير إدارته الأعلى، ثم مدير أعلى إن وُجد، ثم فريق
حوكمة العمليات. الرفض من أي جهة يكتب سبباً، فتصل مهمة تعديل لمقدّم الطلب وإشعار
لكل من اعتمد سابقاً.

معايير القبول:

- المسار مزروع في هجرة، موسوم `is_system`، غير قابل للتعديل ولا الحذف من الواجهة.
  وإلا استطاع حامل `workflow.manage` حذف فريق الحوكمة من مسار الحوكمة نفسه.
- المسودة المقترحة تُحفظ صفاً في `workflow_versions` لا حقلاً داخل الطلب، فتُتحقق
  ويُحسب لها `graph_hash`.
- دورة الحياة تصبح `draft` ثم `in_review` ثم `approved` ثم `published`. المسارات
  في `routes/web.php` تقبل `approve` و`sign` أصلاً ويرفضها المتحكم اليوم.
- عند إعادة التقديم: تغيّر `graph_hash` يستأنف السلسلة من الصفر، وثباته يستأنفها
  من الجهة الرافضة. سياسة معلنة على العقدة لا سلوك مدفون في الكود.

الاختبارات: `Modules/Workflow/Tests/GovernanceWorkflowTest.php` — السلسلة كاملة،
ورفض يعيد للمقدّم ويُشعر السابقين، وتعديل جوهري يلغي الاعتمادات السابقة، وتعديل
غير جوهري يستأنف، ومنع تعديل المسار المزروع.

أمر التحقق:

```
cd apps/api && php artisan test Modules/Workflow/Tests
make verify-day2
```

## 4. مصفوفة الموديولات

| المرحلة | Workflow | Tasks | Organization | Authorization | Web | العقد |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| 0 | ✅ | — | — | ✅ | — | — |
| 1 | ✅ | — | — | ✅ | ✅ | ✅ |
| 2 | ✅ | — | — | ✅ | — | — |
| 3 | ✅ | — | — | — | — | — |
| 4 | ✅ | — | ✅ | ✅ | — | — |
| 5 | ✅ | ✅ | — | — | ✅ | ✅ |
| 6 | ✅ | ✅ | ✅ | — | — | — |
| 7 | ✅ | — | ✅ | ✅ | ✅ | ✅ |

الحد المعماري ثابت في كل المراحل: يحظر الاستعلام أو join المباشر بين جداول
الموديولات، والتعاون يكون عبر contracts وevents وIDs. يُثبَّت ذلك بـ
`make verify-boundaries` في كل مرحلة تمس أكثر من موديول.

## 5. الاعتماديات وترتيب التنفيذ

- المرحلة 1 مستقلة وتعتمد على المرحلة 0 وحدها.
- المرحلة 2 مستقلة تماماً، وتُقدَّم لأن كلفتها ترتفع مع حجم البيانات لا مع الوقت.
- المرحلة 4 تعتمد على 3، ولا معنى لعكسهما.
- المرحلة 5 تعتمد على 1 لاكتمال صندوق الاعتمادات.
- المرحلة 7 تستهلك 3 و4 و6 مجتمعة، فهي آخر ما يُبنى لا أوله.

## 6. المخاطر

| الخطر | الأثر | التخفيف |
|---|---|---|
| تحوّل المفردات إلى لغة برمجة | رسم لا يُقرأ ولا يُختبر | مفردات مغلقة معدودة، وشروط `field op literal` فقط |
| تفرّع المنشآت للمسارات المعيارية | فقدان سبب المنصة المشتركة | نطاق `cluster` يُتبنّى ولا يُفرَّع، والضبط بارامترات فقط |
| بقاء اعتمادات سارية بعد تعديل جوهري | ثغرة حوكمية | مقارنة `graph_hash` كسياسة معلنة على العقدة |
| تعديل مسار الحوكمة من داخله | انهيار المنظومة من داخلها | `is_system` وهجرة فقط، ومنع الاعتماد الذاتي |
| عودة الخلط بين الاعتماد والمهمة | تكرار المشكلة الأصلية | `tasks.workflow_step_id` مقصور على عقد `task` |

## 7. قرارات محسومة

- تثبيت النسخ الجارية على إصدارها قائم فعلاً عبر
  `workflow_instances.workflow_version_id`، فتعديل مسار لا يكسر طلبات جارية.
- الملكية دور مُسنَد بنطاق لا عمود `user_id`، حتى لا تجمّد إجازةُ شخصٍ حوكمةَ
  منشأته. الواجهة تعرض اسماً، والتنفيذ يبقى دوراً.
- المهام لا تُلغى ولا تُدمج. تبقى موديولاً مجاوراً كامل الأهلية بمشاركيه وتعليقاته،
  ويصله العمل من عقدة `action` بعد الاعتماد.

## 8. التحقق العام قبل إغلاق أي مرحلة

```
cd apps/api && php artisan test
composer --working-dir=apps/api lint
composer --working-dir=apps/api analyse -- --memory-limit=512M
make verify-boundaries
npm --prefix apps/web run lint
npm --prefix apps/web run build
./scripts/validate-docs.sh
```

لا تُعدّ المرحلة منجزة بوثيقة. تُعدّ منجزة بكود عامل واختبار أخضر ودليل تشغيل
فعلي، وفق [حالة التسليم النشطة](active-delivery-status.md) و
[خارطة التنفيذ](implementation-roadmap.md).
