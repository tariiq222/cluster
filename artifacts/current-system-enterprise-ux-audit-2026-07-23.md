# تدقيق منطق النظام الحالي وتجربة الاستخدام المؤسسية

**التاريخ:** 2026-07-23  
**النطاق:** حالة `main` عند `69e625a` في هذا الـcheckout فقط  
**المنهج:** قراءة المسارات والمتحكمات والمهاجرات والعقود والعملاء المولدين وشاشات React والاختبارات، ثم تشغيل بناء الويب واختباراته وفحوص خلفية مستهدفة. لم يعدل التدقيق كود المنتج.

## 1. الملخص التنفيذي

المنصة الحالية تثبت فكرة صحيحة ومتماسكة معمارياً: منصة تشغيل مؤسسية عربية للتجمع الصحي، تربط الهوية والبنية التنظيمية والتفويض متعدد النطاقات بتعريفات العمل والسجلات وسير الموافقات والمهام والوثائق، ثم تعرض إسقاطات بحث وتقارير وإشعارات. هذا ليس prototype شكلياً: يوجد 119 مسار API فعلياً، 11 موديول أعمال، تخزين وعقود واختبارات معتبرة، وحماية جلسة وCSRF وidempotency وoptimistic locking في مسارات كثيرة.

لكن التجربة التي يستطيع المستخدم إنجازها اليوم أضيق من قدرة الخلفية، وفي ثلاثة أسطح رئيسية تصبح مضللة: طلب الإجراء الجديد لا يحفظ شيئاً، تأليف/مراجعة الإجراء قد يعرض نجاحاً رغم رفض الخادم، وشرح قرار الوصول يستدعي URL غير موجود. كما أن دورة حوكمة تعريف workflow غير مكتملة منطقياً: المتحكم يقبل `publish` فقط وينقل `draft` مباشرة إلى `published`، بينما الواجهة تعرض submit/approve/return غير مدعومة فعلياً. لهذا لا يصح اعتبار «إدارة الإجراءات» رحلة تشغيلية مكتملة.

القرار النهائي: **الأساس قابل للاستخدام، لكن يجب تصحيح قضايا عالية المخاطر محددة قبل توسيع الميزات الحالية**. لا أوصي بإعادة بناء النظام؛ أوصي بإغلاق فجوة الإجراءات والعقود، ثم إكمال الإدارة العملية للوثائق والتقارير والبحث وإثبات الرحلات من المتصفح.

أقوى الأجزاء المثبتة:

- قرار وصول RBAC + ABAC حقيقي ومربوط في الإنتاج عبر `BootstrapGatedDecideAccess`، مع explicit deny ونطاقات وتصنيف وسياسات حقول.
- هوية server-side، CSRF، ETag/If-Match، ومفاتيح idempotency في أوامر أساسية.
- تنظيم وموارد بشرية غني نسبياً: منشآت ووحدات وأشخاص ومناصب وإسنادات ومؤقتات واستيراد وعلاقات إشراف.
- دورة وثيقة quarantine/scan/promotion وحفظ آثار وصول حساسة.
- صناديق موافقات شخصية، `allowed_actions`، إسناد/تصعيد، وإخفاء وجود السجل الأجنبي بـ404.
- بناء الواجهة و295 اختبار وحدة ناجح، و27 اختباراً خلفياً مستهدفاً ناجحاً.

أخطر المخاطر:

1. نجاح كاذب في إدارة الإجراءات (H-01).
2. نموذج «طلب إجراء جديد» frontend-only (H-02).
3. نشر workflow من draft مباشرة دون دورة اعتماد حقيقية (H-03).
4. شاشات إدارة/شرح صلاحيات تقرأ عقداً خاطئاً أو URL غير موجود (H-04).
5. غياب إثبات تشغيلي كامل: suite الخلفية الكامل علق، ولا يوجد E2E حديث مثبت لكل رحلة (H-05).

## 2. النطاق المنفذ الحالي

| المجال الوظيفي | الغرض | الخلفية | الواجهة | قاعدة البيانات/API | الاختبارات والتكامل | الثقة | الدليل |
|---|---|---|---|---|---|---|---|
| الهوية والجلسات | تسجيل الدخول والتفعيل وكلمة المرور والحسابات والنطاق المختار | منفذ | منفذ مع إدارة حسابات وأمن شخصي | حسابات، credentials، sessions؛ `/identity/*`, `/me*` | اختبارات Identity وsession | عالية | `apps/api/app/Http/Controllers/Identity/`; `apps/web/src/features/identity/`; `routes/web.php:104-119` |
| التنظيم والقوى العاملة | التجمع والمنشآت والوحدات والأشخاص والمناصب والإسنادات | منفذ واسع | منفذ واسع | migrations Organization؛ 35 تقريباً من مسارات التنظيم | 10 اختبارات موديول + اختبارات واجهة | عالية | `apps/api/Modules/Organization/`; `apps/web/src/features/organization/`; `routes/web.php:155-191` |
| التفويض | RBAC/ABAC والأدوار والنطاقات والتفويض والسياسات | منفذ وقوي في القرار | إدارة جزئية وبعض الشاشات منكسرة عقدياً | 13 جدولاً تقريباً؛ `/authorization/*` | 14 اختبار موديول؛ 27 اختباراً مستهدفاً أخضر ضمن العينة | عالية للخلفية، متوسطة للواجهة | `AppServiceProvider.php:118-139`; `CapabilityCatalog.php`; `features/authorization/` |
| تعريفات العمل | تعريف schema ثابت وإصدارات test/approve/sign/publish | منفذ | إدارة عامة غير غنية | `work_definitions`, versions, idempotency | اختبار موديول واحد + شاشات R1 | متوسطة | `WorkDefinitionController.php:38-177`; `R1Screens.tsx` |
| سجلات العمل/الطلبات | إنشاء سجل، عرضه، تغيير حالته وربط وثيقة | منفذ جزئياً كعمود فقري | إنشاء/تفصيل ولوحة، لكن لا قائمة إدارة كاملة مستقلة | `work_records`, outbox؛ `/work-records/*` | 3 اختبارات موديول + واجهة | متوسطة-عالية | `WorkRecord.php:32-96`; `routes/web.php:193-218`; `features/requests/` |
| سير العمل والموافقات | تعريف workflow، تشغيل instance، steps، قرارات وإسناد وتصعيد | التشغيل الشخصي منفذ؛ حوكمة التعريف ناقصة | صندوق موافقات وتفاصيل جيدة؛ authoring/review غير تشغيليين | 5 جداول workflow؛ `/workflow/*` | 6 اختبارات موديول + Feature tests | عالية للموافقات، منخفضة لحوكمة التعريف | `WorkflowController.php:104-135,235-378`; `features/workflow/` |
| المهام | مهام مستقلة/من خطوة، مشاركون وتعليقات وحالات | منفذ جزئياً | قائمة وتفصيل؛ إدارة عملية محدودة | 4 جداول؛ `/tasks/*` | اختباران موديول + contract tests | متوسطة | `TaskController.php`; `TaskEngagementController.php`; `features/tasks/` |
| الوثائق | metadata، versions، upload، scan، promotion، links، grants | منفذ غني | workspace حقيقي لكن إدخال owner/links تقني | 10 جداول؛ `/documents/*` | 18 اختبار موديول | عالية للخلفية، متوسطة للUX | `Modules/Documents/`; `DocumentsWorkspace.tsx`; `routes/web.php:120-137,235-267` |
| الإشعارات | صندوق وارد وتعليم مقروء + worker/DLQ | منفذ | قائمة/درج | 4 جداول؛ `/notifications` | 3 اختبارات موديول | متوسطة-عالية | `Modules/Notifications/`; `NotificationList.tsx` |
| البحث | projection محدود ومفلتر بالتفويض | منفذ | منفذ لكن الفلاتر/التنقل محدودة | 3 جداول؛ `/search` | اختباران موديول | متوسطة | `Modules/Search/`; `R1Screens.tsx` |
| التقارير ولوحات المؤشرات | projections وتقارير وتصدير ولوحات حسب التفويض | منفذ جزئياً | بطاقات/قوائم أساسية لا بيئة تحليل إدارية كاملة | 6 جداول؛ `/reports`, `/dashboards`, `/exports` | اختباران موديول + واجهة | متوسطة | `Modules/Reporting/`; `features/reporting/` |
| أدوات داخلية | coverage وAPI reference | static/contract | منفذ كأداة داخلية | لا تخزين أعمال | ليست رحلة مستخدم أعمال | عالية في التصنيف | `features/portal/coverage-data.ts`; `features/docs/SwaggerUiScreen.tsx` |

لا يوجد في الكود الحالي تنفيذ فعلي لموديولات Strategy أو PortfolioProjects أو Risk رغم وجود وثائق نطاق لها؛ لم تدخل في التقييم ولم تُحسب كنقص.

## 3. اتساق الفكرة الأساسية

### الفكرة المستنتجة

المشكلة التنظيمية هي تحويل الأعمال والطلبات والإجراءات داخل تجمع صحي متعدد المنشآت والوحدات إلى سجلات محكومة: هوية واضحة، نطاق تنظيمي، صلاحية دقيقة، تعريف منشور، مسؤول، حالة، موافقة، مهمة، وثيقة، أثر، ثم رؤية إدارية.

### مقارنة أربع صور للنظام

| الصورة | ما تقوله فعلياً | الاتساق |
|---|---|---|
| الوثائق | modular monolith، عمليات محكومة، RBAC+ABAC، outbox، عربية افتراضياً | اتجاه واضح |
| قاعدة البيانات والخلفية | تدعم فعلاً الهوية والتنظيم والتفويض والسجلات والworkflow والوثائق والإسقاطات | الأقرب للفكرة |
| الواجهة | تقدم workspace واسعاً حسب القدرات، لكنها تعرض أيضاً أسطح إجراءات غير موصولة | جزئي |
| ما يختبره المستخدم اليوم | ينجز التنظيم والحسابات وبعض الطلبات والموافقات؛ لا يستطيع إدارة دورة الإجراء بثقة | أضيق من الوعد |

الاختلاف المقبول: عدم وجود موديولات مستقبلية. الاختلاف غير المقبول: شاشة حالية تقول إن الطلب أو الاعتماد نجح دون persistence مثبت.

## 4. نموذج التشغيل الحالي

```text
هوية مستخدم + جلسة server-side
  → اختيار نطاق cluster/facility/unit/record_set
    → أدوار + capabilities + delegation + explicit deny
      → قرار RBAC/ABAC + classification + relation + field policy
        → تعريف عمل منشور
          → سجل عمل مملوك لمنشأة/مستخدم
            → Workflow instance مثبت على version
              → Step مسند لمستخدم
                → approve/reject/return/reassign/escalate
                  → Task/Comment/Participant عند الحاجة
                    → Search + Reporting projections
                    → Outbox → Notification worker
            → Document link → version → quarantine → scan → promotion
```

### الأدوار المكتشفة

- `platform_owner`: bootstrap وملكية المنصة/مكتب العمليات.
- `operations_office_member`: إدارة/اعتماد تشغيلي وفق capabilities.
- `system.access-admin`: إدارة الأدوار والإسنادات والتفويض والسياسات.
- `system.security-auditor`: قراءة القرارات والسجل الأمني.
- أدوار journey محلية للاختبارات، وليست نموذج منتج نهائياً.
- المستخدم العادي لا يملك «role» واحداً ثابتاً في UI؛ تجربته مشتقة من capabilities والنطاق الفعال.

### دورات الحالة الرئيسية

- Work definition version: `draft → tested → approved → signed → published` مع ETag، لكن publish في المتحكم يسمح به دون تحقق صريح من `signed`؛ الشرط مقيد للأفعال غير publish (`WorkDefinitionController.php:166-168`).
- Workflow version الحالي: `draft → published` فقط؛ test/approve/sign تعيد 409 (`WorkflowController.php:126-133`).
- Workflow step: `waiting|active → completed|rejected|returned`، مع reassign/escalate وقفل إصدار.
- Workflow instance: `running → completed|cancelled`، والإكمال مشتق من عدم وجود خطوات مفتوحة.
- Document: `draft|active|archived|held|rejected`، والنسخة تمر `pending/scanning/clean/infected/failed`.
- Task: حالات controller أوسع من كيان domain البسيط؛ مصدر الحقيقة التنفيذي هو controller/migration لا `Task.php` وحده.

## 5. تدقيق المجالات الوظيفية

### الهوية والتنظيم

المنطق عملي: الجلسة لا تعتمد bearer وحده، mutations محمية بـCSRF، الحسابات والتفعيل وكلمة المرور موجودة، والكيانات التنظيمية لها CRUD وإسنادات مؤقتة واستيراد. UX التنظيم هو أكثر السطوح نضجاً ويستخدم Drawers ومكونات موحدة. المتبقي: كثير من الحقول ما زال يتوقع UUID أو قيمة داخلية، وقوائم بعض wrappers محددة بـ50/100 دون تجربة pagination واضحة؛ هذا سيظهر عند بيانات تشغيلية كبيرة.

### التفويض

الخلفية تطبق capability + scope + record/classification facts وتفشل مغلقاً. `DecideAccess` مربوط فعلياً بـ`BootstrapGatedDecideAccess` (`AppServiceProvider.php:118-139`). list/show للإدارة يمر عبر gateway scoped، والاختبارات المستهدفة تغطي isolation. المشكلة في العرض الإداري: `AccessScopesScreen` يقرأ `subject_id/role_code/starts_at/ends_at` بينما كيان role assignment يعيد `user_id/role_id/start_at/end_at`؛ النتيجة صفوف `—` (`AccessScopesScreen.tsx:103-109`, `RoleAssignment.php:46-57`). شاشة explainer تستدعي `/api/v1/access/decisions/{id}` بينما route الفعلي `/api/v1/authorization/access-decisions/{id}/explanation`.

### تعريفات العمل وسجلات العمل

تعريف العمل يملك schema hash وإصدارات وoutbox وidempotency. سجل العمل يحفظ النسخة المنشورة والتصنيف والسياسة والمالك؛ وهذا أساس صحيح لمنع تغير النماذج بأثر رجعي. فجوة منطقية: publish لتعريف العمل لا يشترط `signed`، لأن الشرط في السطر 167 يستثني `publish`. الواجهة تنشئ نوع `request` عملياً لكنها لا تقدم catalogue غني أو تفسير «لماذا هذا الطلب/من المسؤول لاحقاً» على مستوى كل سجل.

### Workflow والموافقات

الموافقة الشخصية الحالية قوية نسبياً: `allowed_actions` يحسب من الحالة والإسناد والقدرة، وتفاصيل الموافقة تعرض approve/reject/return/reassign/escalate فقط حين يسمح الخادم. الوصول لسجل أجنبي يعيد 404 ما لم يوجد قرار عمليات scoped (`WorkflowController.php:183-232`).

لكن إدارة تعريف الإجراء متناقضة: authoring يعدل steps في React state فقط (`ProcedureAuthoring.tsx:201-209`)، ثم يرسل action مصطنعاً `submit` ويبتلع 400 ويعرض success (`:211-238`). office review يبتلع 400/404 ويزيل العنصر من القائمة مع success (`ProcedureOfficeReview.tsx:148-202`). backend لا يقبل سوى publish وينشر draft مباشرة. هذه ليست «نواقص تجميلية» بل رحلة أعمال غير صادقة.

### المهام

الـAPI يدعم إنشاء مستقل أو من خطوة، transitions، participants، comments وETag. التفصيل يعرض التعليقات والحالات، لكن المستخدم لا يملك لوحة غنية للفرز حسب الموعد/الأولوية/المسؤول، وبعض تنقلات الرجوع hard reload. لا يوجد دليل على escalation تلقائي أو overdue automation؛ لا أفترض وجوده.

### الوثائق

الخلفية هي الأقوى: ticket موقّع، quarantine، تحقق hash/size، scan، reconcile promotion، grants وروابط. الواجهة تستعمل `allowed_actions` وتدعم الإصدارات والربط. مع ذلك تطلب `owner_organization_unit_id` وrecord IDs كنصوص؛ المستخدم غير التقني يحتاج Select من البنية التنظيمية وسياق ربط من صفحة المصدر. مسارا worker الداخليان لا يستخدمان session middleware في route، لكن controller يحصل على `WorkerPrincipalResolver` مخصص؛ يلزم إثبات deployment secret/rate/isolation قبل الإنتاج.

### الإشعارات والبحث والتقارير

الإشعارات تعتمد outbox/Redis consumer مع inbox idempotency وDLQ، لكن إعادة التشغيل المتزامنة داخل middleware محصورة بالاختبار وليست delivery إنتاجياً. Search وReporting يعيدان قرار الوصول لكل row، وهي نقطة قوة. UX لا يقدم saved views أو بحثاً مركباً أو drill-down إدارياً كاملاً، والتقارير ليست بعدُ مساحة قرار ناضجة: بطاقات أرقام وقوائم بلا period/freshness موحد ولا تفسير السبب والمسؤول في كل indicator.

## 6. تدقيق الرحلات من البداية للنهاية

| الرحلة | المسار الفعلي | النتيجة |
|---|---|---|
| تسجيل الدخول واختيار النطاق | Login → identity session → CSRF refresh → `/me` + scopes → workspace | تشغيلية، مع اختبارات session |
| إنشاء سجل عمل | `/work-records/new` → تحقق الواجهة → POST → access + definition resolve → transaction/outbox → feedback | تشغيلية جزئياً؛ النوع ثابت وتجربة ما بعد الإنشاء محدودة |
| مراجعة موافقة | `/approvals` → GET steps → detail → allowed action → POST decision + If-Match → transaction/decision/outbox → reload | تشغيلية ومثبتة بفحوص مستهدفة |
| إعادة إسناد/تصعيد | detail → reason/target → POST step action → authorization/assignee/ETag → updated detail | تشغيلية ومغطاة مستهدفاً |
| طلب إجراء جديد | `/procedures/new` → تحقق محلي → رسالة «تم إعداد الطلب» | **dead end:** لا API ولا سجل ولا recovery |
| تأليف/إرسال إجراء | authoring → تعديل state محلي → action `submit` غير عقدي → ابتلاع 400 → success | **مضللة وغير تشغيلية** |
| مراجعة إجراء | review → approve/return → route generic → 409/404 → ابتلاع → success + حذف row | **مضللة وغير تشغيلية** |
| رفع وثيقة | initiate → PUT storage → complete → worker scan → reconcile → download grant | الخلفية تشغيلية؛ اكتمال worker deployment غير مثبت |
| إدارة تنظيم | قائمة → Drawer → POST/PATCH + CSRF/ETag → refresh | تشغيلية، أفضل UX حالياً |
| قرار إداري من dashboard | dashboard card → رقم/قائمة | جزئي؛ freshness، cause-to-action، والمسؤول ليست متسقة |

## 7. تدقيق UX المؤسسية

### البنية والتنقل

التنقل مجمع إلى «عملي، التنظيم والقوى العاملة، العمليات، الحسابات والوصول، التقارير، الأدوات الداخلية»، ويخفي العناصر حسب capabilities (`navigation.tsx:108-146`). هذا مناسب. لكن بعض الصفحات تستخدم `window.location.href` فتفقد state والنطاق وتعيد bootstrap، مثل AccessScopes وApprovalDetail. أدوات coverage/API docs مخفية كأدوات داخلية وهذا صحيح، لكنها لا يجب أن تعامل كميزات أعمال.

### التجربة حسب الدور

الـsidebar يتكيف حسب capabilities، لكن landing page واحدة للجميع. لوحة العمل تعرض approvals/tasks/requests ومؤشرات مسموحة، وهي أفضل من dashboard ثابت، لكن لا تقدم queue ذات أولوية أو SLA أو overdue موثق. platform owner/access admin يستطيع رؤية إدارة واسعة؛ المستخدم العادي يستطيع الطلب والموافقة والمهام، لكنه يصطدم بمسارات خام وUUIDs في تفاصيل كثيرة.

### القوائم والتفاصيل والنماذج

- حالات loading/empty/denied/error موجودة على نطاق واسع، وهي نقطة قوة.
- pagination موجودة في عقود عدة لكن واجهات كثيرة تحمّل أول 50 فقط ولا تعرض next cursor.
- التفاصيل تعرض state وallowed actions، لكن timeline/audit history غير موحد.
- نماذج التنظيم جيدة، بينما الوثائق والصلاحيات ما زالت تعرض IDs داخلية.
- نموذج الإجراء الجديد طويل ويجمع تعريف النموذج وسلسلة الموافقات لكنه لا يحفظ؛ هذا أسوأ نوع friction لأنه يهدر عمل المستخدم.

### الاتساق والوصول وRTL

الواجهة عربية افتراضياً وتضبط `dir` وتستخدم IBM Plex Sans Arabic ومكونات `Button/Field/Select/Drawer/Panel/Feedback`. الاختبارات تغطي navigation وdrawers وRTL جزئياً. ما زالت raw tables وhard reloads وstatus badges بلا variant دلالي موحد، وبعض النصوص تعرض enum/UUID. لا توجد نتيجة axe أو screen-reader أو keyboard E2E حديثة؛ لذلك accessibility «متوسطة الدليل» لا «مكتملة».

## 8. محاذاة الواجهة والخلفية

| النوع | الواجهة | الخلفية | الأثر |
|---|---|---|---|
| URL | `/api/v1/access/decisions/{id}` | `/api/v1/authorization/access-decisions/{id}/explanation` | explainer يفشل دائماً |
| Role assignment schema | `subject_id`, `role_code`, `starts_at`, `ends_at` | `user_id`, `role_id`, `start_at`, `end_at` | شاشة نطاقات فارغة دلالياً |
| Workflow action | `submit`, `return`, `approve` مع fallback success | يقبل route أربعة actions لكن controller يسمح `publish` فقط | نجاح كاذب |
| New procedure request | form وsuccess محلي | لا endpoint مستعمل | لا persistence |
| Work definition publish | UI يعرض lifecycle متدرج | publish يستطيع تجاوز signed | حوكمة قابلة للتجاوز عبر API |
| Procedure catalogue gate | navigation على `work_definition.read` | الشاشة تستدعي workflow definitions التي تحتاج `workflow.read` | مستخدم يرى الصفحة ثم يُمنع |
| Generated surface | 183 operation تقريباً مقابل 119 route فعلياً | generic routes تغطي بعضها، وبعضها contract-only/orphan | لا يجوز اعتبار العميل كله منفذاً |

## 9. النتائج حسب الشدة

### Critical

لم أثبت تسريباً عابراً للنطاق أو فساد بيانات لا رجعة فيه في المسارات المقروءة؛ لذلك لا توجد نتيجة Critical مؤكدة.

### High

| ID | النتيجة | المجال | المستخدمون | الأثر | الدليل | الاتجاه |
|---|---|---|---|---|---|---|
| H-01 | نجاح كاذب في authoring/review | Workflow governance | كاتب الإجراء ومكتب العمليات | يعتقد أن الإرسال/الاعتماد تم بينما الخادم رفضه | `ProcedureAuthoring.tsx:211-238`; `ProcedureOfficeReview.tsx:148-202` | endpoint ودورة حالة واحدة، وعدم ابتلاع أي خطأ |
| H-02 | طلب إجراء جديد frontend-only | Procedures | جميع طالبي الإجراءات | فقد عمل المستخدم وعدم وجود سجل للمراجعة | `NewProcedureRequest.tsx:44-52` | ربطه بسجل/تعريف وidempotency وتأكيد حقيقي |
| H-03 | حوكمة workflow تسمح draft→published فقط | Workflow backend | مكتب العمليات | تجاوز مراحل الاعتماد أو تعطلها | `WorkflowController.php:104-135` | state machine صريحة وصلاحيات مستقلة وaudit |
| H-04 | contract drift في شاشات الصلاحيات | Authorization UX | access admin/auditor | شاشة explainer لا تعمل ونطاقات تعرض شرطات | `AccessDecisionWorkspace.tsx:39-55`; `AccessScopesScreen.tsx:103-109`; `routes/web.php:193-199` | generated client وDTO typed واختبارات contract |
| H-05 | لا دليل suite كامل/E2E للرحلات الحرجة | الجودة | كل المستخدمين | regressions قد تمر رغم unit green | `package.json` scripts؛ تشغيل هذه المراجعة | إصلاح hang وتشغيل journey E2E |

### Medium

| ID | النتيجة | المجال | المستخدمون | الأثر | الدليل | الاتجاه |
|---|---|---|---|---|---|---|
| M-01 | WorkDefinition publish لا يشترط signed | تعريفات العمل | ناشرو النماذج | bypass عبر API | `WorkDefinitionController.php:161-168` | transition matrix صارمة |
| M-02 | pagination غير مكتملة في واجهات الإدارة | عدة مجالات | المدراء | سجلات بعد أول 50/100 غير مرئية | `api/r1.ts:60-82` | cursor controls وtotal |
| M-03 | IDs داخلية مطلوبة/معروضة | وثائق/صلاحيات/موافقات | مستخدم غير تقني | أخطاء إدخال واعتماد على الدعم | `DocumentsWorkspace.tsx:82-83`; `ApprovalDetail.tsx:181-185` | searchable Select وhuman labels |
| M-04 | dashboard بلا freshness وdrill-down موحد | التقارير | الإدارة | قرار على رقم غير مفسر | `PrincipalDashboards.tsx:34-92`; `DashboardsScreen.tsx:40-91` | period/source/updated_at/cause/action |
| M-05 | hard navigation داخل SPA | التنقل | الجميع | فقد السياق وإعادة تحميل | `AccessScopesScreen.tsx:83`; `ApprovalDetail.tsx:160-163` | navigate callback/router |
| M-06 | worker document deployment غير مثبت | الوثائق | التشغيل الأمني | scan/promotion قد يتوقف خارج الاختبار | `routes/web.php:136-137`; `AppServiceProvider.php:166-173` | smoke حقيقي وsecret isolation/monitoring |

### Low

| ID | النتيجة | المجال | المستخدمون | الأثر | الدليل | الاتجاه |
|---|---|---|---|---|---|---|
| L-01 | status badge semantics غير موحدة | UI | الجميع | قراءة أبطأ للحالة | `ui/ui.css`; شاشات workflow | variants موحدة |
| L-02 | بعض copy/enums محلية أو خام | التعريب | عربي/إنجليزي | اتساق ضعيف | `features/*` local copy | قاموس مركزي |
| L-03 | bundle Swagger كبير | الأداء | مستخدم الأدوات الداخلية | تحميل زائد | نتيجة build: chunk 1.35MB | lazy split |

## 10. مصفوفة التغطية (0–5)

| المجال | منطق الخلفية | إدارة الواجهة | التفويض | سلامة البيانات | التكامل | UX | ثقة الاختبار |
|---|---:|---:|---:|---:|---:|---:|---:|
| الهوية | 4 | 4 | 4 | 4 | 4 | 3 | 4 |
| التنظيم | 4 | 4 | 4 | 4 | 4 | 4 | 4 |
| التفويض | 5 | 2 | 5 | 4 | 4 | 2 | 4 |
| تعريفات العمل | 3 | 2 | 3 | 4 | 3 | 2 | 2 |
| سجلات العمل | 4 | 3 | 4 | 4 | 4 | 3 | 3 |
| Workflow/approvals | 3 | 2 | 4 | 4 | 3 | 3 | 4 |
| المهام | 3 | 3 | 3 | 3 | 3 | 3 | 3 |
| الوثائق | 4 | 3 | 4 | 5 | 3 | 3 | 4 |
| الإشعارات | 4 | 3 | 4 | 4 | 3 | 3 | 3 |
| البحث | 4 | 2 | 4 | 4 | 4 | 2 | 3 |
| التقارير | 3 | 2 | 4 | 3 | 3 | 2 | 3 |

## 11. مصفوفة تجربة الأدوار

| الدور | المسؤوليات | المدعوم | المحجوب/المكسور | التعقيد | قابلية الاستخدام |
|---|---|---|---|---|---|
| مستخدم عادي | طلباته، مهامه، وثائقه | work records، tasks، my requests | طلب إجراء جديد لا يحفظ | UUIDs وقلة timeline | متوسطة |
| معتمد/مسند إليه | قرار على خطوة | approve/reject/return/reassign/escalate | subject labels فقيرة أحياناً | reason/target يدوي | جيدة مع قيود |
| عضو مكتب العمليات | حوكمة الإجراءات والرؤية | inbox وقراءة definitions | authoring/review lifecycle مكسور | تعدد شاشات متداخل | ضعيفة حالياً |
| مدير تنظيم/قوى عاملة | الهيكل والأشخاص والإسنادات | أغلب الرحلات | pagination عند الحجم | متوسط | جيدة |
| مدير وصول | roles/scopes/delegations/policies | الخلفية قوية وإدارة جزئية | access scopes/explainer drift | IDs وسياسات JSON | متوسطة-ضعيفة |
| مدقق أمني | تفسير القرارات والقراءة | capabilities موجودة | explainer URL مكسور | أدوات تقنية | ضعيفة |
| قيادة/مدير | مؤشرات وتقارير | بطاقات scoped | freshness/drill-down غير مكتمل | تفسير خارجي مطلوب | متوسطة-ضعيفة |

## 12. خطة المعالجة ذات الأولوية

### فوري

1. H-01/H-02/H-03: توحيد نموذج الإجراء وتعريف endpoint ودورة `draft → submitted → approved/returned → published`، وفصل صلاحيات الكاتب عن المعتمد. **التعقيد: كبير؛ الاعتماد: قرار منتج واحد عن ملكية الإجراء.**
2. H-04: إصلاح URLs وDTOs باستخدام generated client فقط وإضافة contract tests. **صغير إلى متوسط؛ بلا اعتماد.**
3. M-01: منع publish لتعريف العمل ما لم تكن الحالة `signed` واختبار direct API bypass. **صغير.**

### قبل توسيع الميزات الحالية

4. M-02/M-03: pagination وsearchable human selectors لكل UUID ظاهر. **متوسط؛ يعتمد على list endpoints.**
5. توحيد timeline/activity والـstatus dictionary والـallowed_actions في التفاصيل. **متوسط.**
6. ربط procedures بWorkDefinition وWorkflow دون مصدرَي حالة متوازيين. **كبير؛ يعتمد على البند 1.**

### قبل Pilot أو Production

7. H-05: معرفة سبب تعليق suite الكامل، وتشغيل API + web + E2E للهوية والتنظيم والطلب والموافقة والوثيقة مع 403/404/409/412 وscope switch وRTL/LTR. **متوسط.**
8. M-06: تشغيل S3/ClamAV/worker smoke حقيقي ومراقبة outbox/DLQ وعدم الاكتفاء بـtesting replay. **متوسط-كبير.**
9. إضافة freshness/source/period وdrill-down للمؤشرات والتقارير. **متوسط.**
10. تحقق accessibility آلي وkeyboard/screen-reader على الرحلات الأساسية. **متوسط.**

### لاحقاً

11. saved views/bulk actions عند ثبوت الحاجة التشغيلية.
12. إزالة hard navigation، توحيد copy وstatus variants، وتقسيم Swagger chunk.

## 13. القرار النهائي

**الأساس قابل للاستخدام، لكن قضايا عالية المخاطر محددة يجب تصحيحها أولاً.**

السبب: boundaries والتخزين والتفويض والهوية ليست معيبة جذرياً، وتوجد اختبارات ذات معنى. الخلل متركز في طبقة توصيل بعض الرحلات الحديثة—خصوصاً procedures—وفي contract drift داخل شاشات إدارية. إضافة وظائف جديدة فوق هذه الأسطح قبل إغلاقها ستضاعف مصادر الحقيقة والحالات الوهمية. بعد إغلاق H-01 إلى H-05 وإثبات E2E، يمكن مواصلة التطوير على المعمارية الحالية دون إعادة بناء شاملة.

## ملحق A: أدلة التحقق المنفذة

- `npm --prefix apps/web run build`: نجح؛ تحذير chunks أكبر من 500KB، وSwagger chunk نحو 1.35MB.
- `npm --prefix apps/web run test:unit`: نجح، **53 ملفاً / 295 اختباراً**.
- فحوص خلفية مستهدفة Authorization + production binding + approvals/personal queues/reassign: نجحت، **27 اختباراً / 172 assertion**.
- `php artisan route:list --path=api/v1 --json`: **119 route entries**.
- `php artisan test` الكامل: لم يخرج نتيجة وظل يعمل أكثر من دقيقة؛ أُوقف يدوياً. لا تسجل له نتيجة نجاح أو فشل.
- لم يُشغل browser E2E أو deploy/runtime خارجي في هذا التدقيق؛ لذلك السلوك المرئي الذي لا تغطيه اختبارات React مصنف static/contract evidence وليس runtime-proven.

## ملحق B: صيغة النتائج التفصيلية

### H-01 — نجاح كاذب في إدارة الإجراء

- **الشدة/الفئة:** High / business logic + UX feedback.
- **المتأثر:** كاتب الإجراء وعضو مكتب العمليات.
- **السلوك المثبت:** الواجهة تلتقط 400/404 من transitions غير المدعومة ثم تعرض success وتحذف عنصر المراجعة.
- **المتوقع:** لا success إلا بعد response ناجح وتحديث persisted يمكن إعادة قراءته.
- **الأثر التشغيلي:** اعتماد أو إرجاع متخيل، وفقد الثقة، واعتماد على تدخل تقني.
- **الأثر التقني:** state في React ينفصل عن DB.
- **السبب الجذري:** الواجهة بُنيت قبل تثبيت lifecycle contract.
- **الاتجاه:** endpoint صريح، generated client، reload/receipt، وعدم fallback إلى success.
- **التعقيد/الثقة:** Medium–Large / High.

### H-02 — طلب إجراء جديد غير محفوظ

- **الشدة/الفئة:** High / workflow completeness.
- **المتأثر:** طالب الإجراء.
- **السلوك المثبت:** submit يتحقق محلياً ثم يضع `reqRequestPrepared` فقط.
- **المتوقع:** command idempotent ينشئ record ويعطي رقم/حالة/مسؤول تالٍ.
- **الأثر:** لا يستطيع المستخدم إكمال العمل من الواجهة.
- **الدليل:** `NewProcedureRequest.tsx:44-52`.
- **السبب:** surface استكشافية عُرضت كرحلة.
- **الاتجاه/التعقيد/الثقة:** ربطها بعقد persistence بعد حسم الملكية / Large / High.

### H-03 — دورة workflow definition غير محكومة

- **الشدة/الفئة:** High / lifecycle governance.
- **السلوك المثبت:** action غير publish يعيد 409؛ publish ينقل draft إلى published، مع منع self approval فقط.
- **المتوقع:** states وشروط وأدوار وآثار مستقلة للاختبار والتقديم والاعتماد والنشر.
- **الأثر:** إما تجاوز الحوكمة أو تعطل الشاشات التي تتوقعها.
- **الدليل:** `WorkflowController.php:104-135`.
- **السبب:** vertical slice قديم بقي مصدر الحقيقة.
- **الاتجاه/التعقيد/الثقة:** state machine واحدة + audit / Large / High.

### H-04 — انحراف عقد شاشات التفويض

- **الشدة/الفئة:** High / frontend-backend contract.
- **السلوك المثبت:** URL وأسماء حقول لا تطابق route/serializer.
- **المتوقع:** generated typed client ومخططات موحدة.
- **الأثر:** أدوات مدير الوصول والمدقق غير قابلة للاستخدام رغم وجود backend.
- **الدليل:** `AccessDecisionWorkspace.tsx:39-55`; `AccessScopesScreen.tsx:103-109`; `RoleAssignment.php:46-57`; `routes/web.php:196`.
- **الاتجاه/التعقيد/الثقة:** استبدال fetch/casts واختبار integration / Small–Medium / High.

### H-05 — دليل التحقق النهائي غير مكتمل

- **الشدة/الفئة:** High / quality assurance.
- **السلوك المثبت:** unit/build أخضر؛ suite الكامل علق؛ لا browser E2E نفذ هنا.
- **المتوقع:** command نهائي bounded وjourneys حقيقية.
- **الأثر:** لا يمكن إعلان pilot readiness رغم جودة أجزاء منفردة.
- **الاتجاه/التعقيد/الثقة:** عزل الاختبار المعلق ثم CI stage وE2E / Medium / High.
