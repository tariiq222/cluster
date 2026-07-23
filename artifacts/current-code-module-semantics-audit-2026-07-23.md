# تدقيق الموديولات والتسميات والدلالات في الكود الفعلي

تاريخ التدقيق: 2026-07-23  
النطاق: `apps/api/Modules` و`apps/api/app` و`apps/api/routes/web.php` و`apps/web/src`  
الغرض: مرجع تحليلي يسبق تحديث الوثائق، ولا يُعامل كتحديث للوثائق المحكومة.

## 1. الخلاصة التنفيذية

النظام في الكود الحالي هو Modular Monolith فعلي من 11 موديول Backend:

1. Authorization
2. Documents
3. Identity
4. Notifications
5. Organization
6. Reporting
7. Search
8. Tasks
9. WorkDefinitions
10. WorkRecords
11. Workflow

يوجد أيضاً Shared Infrastructure، لكنه ليس موديول أعمال ولا يملك جداول مستقلة.

الكود الحالي يسجل:

- 119 مسار API تحت `/api/v1`.
- 83 جدولاً فريداً تنشئها migrations داخل الموديولات.
- 37 شكلاً لمسارات الواجهة في `AppRoute`، منها شاشات تفاصيل وأدوات داخلية.
- 14 مجلد Features في الواجهة، لكنها لا تطابق الموديولات واحداً بواحد.
- نجاح فحص حدود الموديولات: 4 اختبارات، 6 assertions.

الحكم العام:

- حدود Backend الأساسية واضحة ومعقولة.
- أكبر مشكلة ليست تكرار الموديولات، بل اختلاف اللغة بين طبقات النظام.
- كلمة «طلب» في الواجهة تشير أحياناً إلى `WorkRecord` وأحياناً إلى
  `WorkflowInstance`، بينما «اعتماد» يشير إلى `WorkflowStepInstance`، و«مهمة» إلى
  `Task`. هذه كيانات مختلفة فعلاً وليست أسماء مختلفة لكيان واحد.
- `WorkDefinitions` و`Workflow` منفصلان تقنياً، لكن الواجهة تستخدم «الإجراءات»
  لتغطي كليهما، فتخفي الفرق بين نموذج البيانات ومسار التنفيذ.
- داخل Workflow توجد ازدواجية فعلية في دلالات حالة نسخة المسار:
  `definition_state` و`review_state` و`approval_status`. هذه ليست مجرد مشكلة ترجمة،
  بل ثلاثة حقول متقاربة قد تتعارض.
- `Person` و`UserAccount` و`PrincipalContext` منفصلة بصورة صحيحة في المعمارية،
  لكن المصطلحات `user_id` و`account_id` و«المستخدم» تحتاج قاموساً صريحاً.
- `Facility` و`OrganizationUnit` كلاهما عقدة تنظيمية في بعض التدفقات، لكنهما
  كيانان مختلفان في التخزين والنطاق. استخدام `facility_id` كحقل legacy قد يحمل
  `primaryOrganizationUnitId` عند غياب المنشأة، وهو أخطر التباسات الهوية التنظيمية.
- قاموس الصلاحيات يحتوي قدرات Strategy وPortfolioProjects وRisk رغم عدم وجود
  موديولات تنفيذية لهذه المجالات حالياً. يجب توثيقها كـreserved contract surface،
  لا كموديولات منفذة.

## 2. قاعدة الثقة

هذا التدقيق يميز بين:

- **منفذ:** له كود ومسار أو handler وتخزين أو شاشة فعلية.
- **إسقاط:** Search أو Reporting أو Dashboard يعرض بيانات مشتقة ولا يملك سجل العمل.
- **تجميع واجهة:** مجلد أو شاشة React تجمع أكثر من موديول ولا تمثل موديول Backend.
- **محجوز:** اسم أو capability موجود للعقود المستقبلية بلا موديول أعمال حالي.

لم تُستخدم الخطط لإثبات وجود موديول. الدليل الأساسي هو الكود الحالي، migrations،
route registry، واختبار الحدود.

## 3. خريطة الموديولات الفعلية

| الموديول | ملكيته الفعلية | الجداول | سطح الواجهة | الحكم الدلالي |
|---|---|---:|---|---|
| Authorization | الأدوار، القدرات، الإسنادات، التفويضات، المنع الصريح، التصنيف، سياسات الحقول، قرارات الوصول | 13 | `features/authorization` | واضح كمحرك قرار؛ يلتبس مع Identity إذا سميت شاشاته «المستخدمون والصلاحيات» |
| Documents | المستند، النسخة، التخزين، الرفع، الفحص، الحجر، الربط، الاحتفاظ، hold، الوصول الحساس | 10 | `features/documents` | واضح؛ يجب فصل Document عن Upload وVersion وLink في القاموس |
| Identity | الحساب، بيانات الاعتماد، الجلسة، التفعيل، TOTP، ربط الحساب بالشخص | 13 | `features/identity` | واضح تقنياً؛ يحتاج فصل Person/Account/Principal في اللغة |
| Notifications | الإشعار، المستلم، inbox idempotency، dead letter | 4 | داخل shell/dashboard ولا توجد مساحة مستقلة كاملة | موديول توصيل/عرض، لا يملك الحدث التجاري |
| Organization | التجمع، المنشآت، الوحدات، الأشخاص، المسميات، المناصب، الإشغالات، العلاقات الإشرافية، التكليفات المؤقتة، الاستيراد | 17 | `organization` و`imports` | أوسع موديول وأعلى مساحة للبس الدلالي |
| Reporting | تعريف التقرير، تعريف اللوحة، read model، run، export artifact | 6 | `reporting` و`dashboard` | Reporting هو المالك؛ Dashboard ليس موديولاً مستقلاً |
| Search | فهرس محدود، checkpoint، inbox | 3 | route وشاشة بحث عبر shell | إسقاط قراءة، لا يملك الكيانات التي يجدها |
| Tasks | المهمة التنفيذية، المشاركون، التعليقات، idempotency | 4 | `features/tasks` | منفصل عن Workflow Step رغم إمكانية الربط بينهما |
| WorkDefinitions | تعريف نوع العمل ونسخه وschema وسياسة الحقول | 4 | `r1` و`workflow` و`requests` جزئياً | «ما البيانات المطلوبة؟» وليس «كيف تسير؟» |
| WorkRecords | سجل العمل الفعلي وpayload وحالته ومالكه | 3 | `work-records` و`requests` وdashboard | الواجهة تسميه غالباً «طلباً»؛ الاسم التقني أعم |
| Workflow | تعريف المسار ونسخه، instance، step instance، decision ledger | 6 | `workflow` و`r1` | «كيف ينتقل العمل ومن يقرر؟»؛ أكثر موديول يحتاج تنظيف قاموس الحالات |

مجموع الجداول: 83.

## 4. المصطلحات الأساسية المقترحة

| المصطلح البرمجي | الدلالة الدقيقة | الاسم العربي الموصى به |
|---|---|---|
| WorkDefinition | قالب البيانات والقواعد الحقلية لنوع عمل | تعريف العمل |
| WorkDefinitionVersion | نسخة مثبتة من قالب البيانات | إصدار تعريف العمل |
| WorkflowDefinition | هوية مسار المعالجة | تعريف مسار العمل |
| WorkflowVersion | رسم منشور أو مسودة لمسار المعالجة | إصدار مسار العمل |
| WorkRecord | واقعة أعمال منشأة من تعريف عمل | سجل عمل |
| Request | تسمية تجربة مستخدم لنوع محدد من WorkRecord | طلب، فقط عندما يكون نوع العمل طلباً |
| WorkflowInstance | تشغيل مسار محدد على مصدر أعمال محدد | حالة سير/مثيل مسار |
| WorkflowStepInstance | خطوة فعلية تنتظر أو اكتملت ضمن مثيل المسار | خطوة سير |
| WorkflowDecision | قرار غير قابل للخلط مع حالة الخطوة | قرار اعتماد |
| Task | عمل مطلوب من شخص، مستقل أو ناتج عن خطوة | مهمة |
| Procedure | حزمة مفهومية للمستخدم قد تجمع تعريف العمل ومسار العمل | إجراء |
| Person | إنسان/موظف داخل الهيكل التنظيمي | شخص/موظف |
| UserAccount | حساب دخول مرتبط اختيارياً بشخص | حساب مستخدم |
| PrincipalContext | هوية جلسة موثوقة مع النطاقات الفعلية | سياق الهوية والصلاحية |
| Position | مقعد/منصب تنظيمي داخل وحدة | منصب تنظيمي |
| JobTitle | مسمى وظيفي مرجعي قابل لإسناده | مسمى وظيفي |
| Assignment | إشغال شخص لمنصب خلال مدة | إشغال وظيفي |
| TemporaryAssignment | تكليف صلاحياتي/تنظيمي مؤقت | تكليف مؤقت |
| RoleAssignment | منح دور في نطاق Authorization | إسناد دور |
| Delegation | نقل قدرات محددة مؤقتاً | تفويض صلاحيات |
| SupervisoryRelationship | علاقة إشراف بين وحدات | علاقة إشرافية |
| ReportDefinition | تعريف استعلام/عرض تقريري | تعريف تقرير |
| ReportRun | نتيجة تشغيل التقرير في لحظة ونطاق | تشغيل تقرير |
| DashboardDefinition | تركيب لوحة مرتبط بتقرير أو أكثر | تعريف لوحة |
| ExportArtifact | ملف/ناتج قابل للتنزيل مشتق من تشغيل | مخرج تصدير |

## 5. مناطق الالتباس الحرجة

### 5.1 WorkDefinition مقابل WorkflowDefinition

الحكم: كيانان مختلفان ويجب عدم دمجهما.

- `work_definition_versions.schema_document` يحدد شكل البيانات.
- `work_definition_versions.field_policy_key` يربط سياسات الحقول.
- `workflow_versions.graph_document` يحدد العقد والانتقالات.
- `workflow_definitions.source_record_type` يحدد نوع المصدر الذي يعمل عليه المسار.

المشكلة الحالية:

- الواجهة تستخدم «الإجراءات» لتغطية التصميم، المراجعة، الدليل، والتقديم.
- `ProcedureAuthoring` و`ProcedureOfficeReview` موجودتان تحت `features/workflow`
  رغم أنهما تتعاملان أيضاً مع WorkDefinitions.
- capability `workflow.author` تتحكم في «تصميم الإجراء»، بينما نشر الدليل يتطلب
  `work_definition.publish`.

التوصية:

- تعريف «الإجراء» في الوثائق كـconceptual composition، لا aggregate جديد.
- الإجراء = تعريف عمل منشور + مسار عمل منشور + ربط واضح بين الإصدارين.
- لا تسموا WorkDefinition وحده «إجراء»، ولا WorkflowDefinition وحده «إجراء».

### 5.2 WorkRecord مقابل Request مقابل WorkflowInstance

الحكم: أعلى مصدر لبس للمستخدم والوثائق.

- الصفحة `/work-records/{recordId}` تعرض `WorkRecord`.
- الصفحة `/my-requests/{instanceId}` تستعمل `WorkflowInstance`.
- Dashboard يسمي عناصر `work_records` «طلبات».
- نموذج الإنشاء يرسل `work_definition_code: request`.

النتيجة:

- «الطلب» في صفحة قد يعني سجل الأعمال نفسه.
- «الطلب» في صفحة أخرى قد يعني تشغيل المسار فوق السجل.
- المعرفان `recordId` و`instanceId` مختلفان ولا يوجد في URL ما يوضح ذلك للمستخدم.

التوصية:

- مصطلح النظام العام: «سجل عمل».
- مصطلح المنتج: «طلب» فقط لأنواع العمل المصنفة كطلبات.
- «طلباتي» يجب أن تعتمد سجل العمل كمحور وتضم حالة المسار كبيانات تابعة، أو يعاد
  تسميتها «مساراتي الجارية» إذا بقيت قائمة WorkflowInstances.
- يجب أن توثق العلاقة صراحة:
  `WorkRecord 1 -> 0..n WorkflowInstance`، مع تحديد هل يسمح فعلاً بأكثر من تشغيل.

### 5.3 WorkflowStep مقابل Task مقابل Approval

الحكم: الفصل المعماري صحيح، لكن اللغة الحالية تجعلها تبدو شيئاً واحداً.

- `workflow_step_instances` يملك `assignee_user_id` مباشرة.
- migration W14 يوضح أن الخطوة كانت سابقاً تعتمد على `tasks.assignee_user_id`.
- `workflow_step_instances.task_id` اختياري.
- `tasks.workflow_step_id` اختياري وفريد.
- صندوق الاعتمادات يعرض Workflow Steps.
- قائمة المهام تعرض Tasks.

قاعدة الملكية المقترحة:

- Approval هو نوع/سلوك خطوة Workflow، وليس Task تلقائياً.
- Task ينشأ فقط إذا احتاجت الخطوة عملاً تنفيذياً قابلاً للمتابعة خارج قرار الاعتماد.
- لا ينبغي إنشاء Task لمجرد جعل approver يرى الخطوة؛ `assignee_user_id` في الخطوة
  حل ذلك بالفعل.

### 5.4 حالات WorkflowVersion المتعددة

الحكم: تضارب تنفيذي فعلي، أولوية P0 قبل تثبيت الوثائق.

الجدول الحالي يحمل أو قد يحمل:

- `definition_state`
- `review_state`
- `approval_status`
- `published_at`
- `submitted_at`
- `approved_at`
- `returned_by_user_id` و`return_reason`
- `rejection_reason`

كما أن:

- الإنشاء الأول يكتب `definition_state=draft`.
- إنشاء نسخة لاحقة يكتب أيضاً `approval_status=draft`.
- النشر المباشر يغير `definition_state` من draft إلى published.
- migration W15 يضيف `review_state`.
- migration W17 يضيف `approval_status` و`review_state` و`rejection_reason`.
- المصطلحات `returned` و`rejected` تظهر في مواضع مختلفة.

الخطر:

- يمكن أن تكون النسخة `definition_state=published` و`review_state=draft`.
- لا توجد في المخطط قاعدة وحيدة ظاهرة تمنع عدم الاتساق.
- الوثائق قد تختار lifecycle واحداً بينما الكود يملك أكثر من lifecycle متراكب.

التوصية:

- اختيار حقل canonical واحد لحياة النسخة.
- المقترح: `lifecycle_state = draft|submitted|approved|returned|signed|published|retired`.
- جعل timestamps/actors آثاراً للحالة لا حالات بديلة.
- إلغاء `approval_status` و`review_state` أو جعلهما projections محسوبة، لا مصادر حقيقة.
- توحيد `return_reason` و`rejection_reason` وفق قرار أعمال واضح:
  return = قابل للتعديل وإعادة الإرسال، reject = إغلاق نهائي.

### 5.5 قرارات Workflow: approve/reject/return مقابل accept/decline

الحكم: ازدواج دلالي بلا حاجة واضحة.

الـcontroller يقبل:

- approve
- reject
- return
- accept
- decline

ثم يحول approve وaccept إلى `completed`، وreject وdecline إلى `rejected`.

التوصية:

- إذا لم توجد دلالة أعمال مختلفة مثبتة، يعتمد قاموس واحد:
  `approve|return|reject`.
- إن كانت accept/decline لخطوات غير اعتماد، يجب ربط allowed decisions بنوع node
  في graph contract بدلاً من قبولها عاماً.

### 5.6 Person مقابل UserAccount مقابل Principal

الحكم: الفصل صحيح ومهم أمنياً.

- Organization يملك `people`.
- Identity يملك `users`.
- `users.person_id` اختياري.
- `identity_person_account_claims` يضمن ربط الشخص بحساب واحد.
- PrincipalContext يبنى من الجلسة والحساب وحقائق Organization.

التوصية:

- Person: سجل الموارد البشرية/التنظيم.
- UserAccount: وسيلة دخول وحالة وصول.
- Principal: الحساب داخل طلب موثوق بعد إضافة نطاقاته.
- لا تستخدم الوثائق «مستخدم» للدلالة على Person.
- حقول actor يجب أن تسمى `actor_user_id` أو `account_id` حسب المقصود، لا `user_id`
  المجرد في العقود الجديدة.

### 5.7 Facility مقابل OrganizationUnit مقابل Scope

الحكم: كيانات متمايزة، لكن توجد وصلة legacy خطرة.

- Facility يملك نوع منشأة ويتبع Cluster.
- OrganizationUnit تتبع Cluster ويكون parent نوعه Facility أو Unit.
- AuthorizationScope يدعم cluster/facility/unit/record_set.
- PrincipalContext يحتفظ بقوائم منفصلة للـclusters/facilities/units.
- `toLegacyArray()` يعيد `facility_id`.
- `defaultFacilityId()` يعيد أول Facility، وإن لم يوجد يعيد
  `primaryOrganizationUnitId`.

الخطر:

- اسم `facility_id` قد يحمل فعلياً Organization Unit ID.
- أي ABAC أو query قد يفسر القيمة على أنها منشأة حقيقية.

التوصية:

- إيقاف fallback بين نوعي الهوية.
- تمرير `selected_scope: {scope_type, scope_id}` أو الحقول المنفصلة دائماً.
- توثيق Facility كجذر تشغيلي، وOrganizationUnit كعقدة داخل المنشأة/التجمع.

### 5.8 Position مقابل JobTitle مقابل Assignment

الحكم: الفصل صحيح لكنه يحتاج قاموساً واضحاً.

- Position: مقعد تنظيمي محدد داخل OrganizationUnit، وله manager_position_id.
- JobTitle: مسمى مرجعي.
- Assignment: علاقة زمنية بين Person وPosition.
- TemporaryAssignment: تكليف مؤقت ذو capabilities، وليس إشغالاً وظيفياً عادياً.

التوصية:

- عدم ترجمة Position وJobTitle معاً إلى «مسمى وظيفي».
- Position = منصب/مقعد تنظيمي.
- JobTitle = مسمى وظيفي.
- Assignment = إشغال منصب.
- TemporaryAssignment = تكليف مؤقت.

### 5.9 Organization Assignment مقابل Role Assignment مقابل Delegation

الحكم: كلمة Assignment تتكرر في ثلاثة سياقات مختلفة.

- Organization `assignments`: شخص يشغل منصباً.
- Authorization `role_assignments`: حساب/شخص يحصل على دور ضمن نطاق.
- Organization `temporary_assignments`: تكليف مؤقت مع capabilities.
- Authorization `delegations`: مفوض ينقل قدرات محددة لمفوض إليه.

التوصية:

- منع استخدام «إسناد» وحدها في الوثائق أو الواجهة.
- استخدام: إشغال وظيفي، إسناد دور، تكليف مؤقت، تفويض صلاحيات.

### 5.10 Documents: Document مقابل Version مقابل Upload

الحكم: الفصل جيد، لكن API قد يوحي أن upload هو document.

- Document هو metadata/lifecycle/ownership.
- DocumentVersion هو المحتوى المحدد ونتيجة الفحص.
- UploadIntent هو تذكرة نقل مؤقتة.
- StorageObject هو الكائن الفيزيائي.
- Quarantine هو حالة أمنية للنسخة.
- DocumentLink يربط المستند بمصدر أعمال polymorphic.

التوصية:

- «رفع ملف» عملية لإنشاء نسخة، وليس إنشاء مستند بالضرورة.
- `current_version_id` يحدد النسخة الفعالة، ولا ينبغي أن تستخدم `document_id`
  لتنزيل bytes بلا حل النسخة وسياسة الوصول.

### 5.11 Report مقابل Dashboard مقابل Export

الحكم: لا توجد ثلاثة موديولات؛ كلها داخل Reporting.

- ReportDefinition يحدد التقرير.
- ReportRun يمثل تشغيله ونتيجته.
- DashboardDefinition تركيب عرض وقد يرتبط بتقرير.
- ExportArtifact ناتج مشتق من ReportRun.

التوصية:

- Dashboard تسمى «لوحة» لا «تقرير مرئي».
- Export هو مخرج تشغيل، لا تقرير جديد.
- `features/dashboard` و`features/reporting` تقسيم واجهة، لا حدود Domain.

### 5.12 Search وNotifications

الحكم: كلاهما موديول دعم، لا مصدر حقيقة للأعمال.

- Search يملك projection محدوداً ثم يعيد فحص DecideAccess لكل نتيجة.
- Reporting يملك projections وتقارير مشتقة.
- Notifications يستهلك أحداث WorkRecords ويملك حالة التسليم/القراءة فقط.

التوصية:

- لا توصف Search بأنها «قاعدة بيانات موحدة».
- لا يوصف Notification بأنه «حالة الطلب».
- حالة الطلب تأتي من WorkRecord/Workflow، والإشعار يخبر عنها فقط.

## 6. التناقضات أو الروائح الاسمية في الكود

### P0

1. ثلاثة حقول حالة متداخلة في `workflow_versions`.
2. `facility_id` legacy قد يحتوي OrganizationUnit ID.
3. «طلباتي» مبنية على WorkflowInstance بينما Dashboard وطلب التفاصيل مبنيان على
   WorkRecord.

### P1

4. `work_records.work_type_version_id` بينما الموديول المستهدف يسمى
   WorkDefinitions ويملك `work_definition_versions`. يجب توحيد الاسم إلى
   `work_definition_version_id` أو توثيق WorkType كمرادف رسمي.
5. Workflow يقبل زوجي قرارات متكافئين: approve/accept وreject/decline.
6. `WorkflowVersion` domain object يسمي الخاصية `state` بينما العمود
   `definition_state`.
7. `workflow_decisions.decision` طوله 24 في W15، بينما W16 يعرفه بطول 16،
   وRecordDecisionHandler يقتطع إلى 16.
8. W15 وW16 كلاهما يحمل اسم CreateWorkflowDecisionsTable؛ guards تمنع الإنشاء
   المكرر، لكن الاسم يوحي بتاريخ migration غير موحد.

### P2

9. route name `list` يعني قائمة WorkRecords، و`detail` يعني WorkRecord detail؛
   الأسماء عامة أكثر من اللازم داخل AppRoute.
10. `features/requests` ليس موديول Requests، بل تجربة واجهة لـWorkRecords.
11. `features/r1` اسم release/history وليس معنى أعمال؛ لا ينبغي أن يظهر في خريطة
    معمارية طويلة العمر.
12. `procedure` هو composition UI غير ممثل بعقد Domain صريح.
13. `source_module/source_type/source_id` تتكرر في Workflow وTasks وDocuments
    وSearch وReporting، لكن casing المرصود يختلف (`WorkRecords` و`work_records`).
14. `classification` تستخدم قيم موحدة غالباً، لكن اسم `top_secret` يجب تثبيته
    في قاموس موحد للعقد والواجهة والتخزين.

## 7. قدرات بلا موديولات حالية

`CapabilityCatalog` يحتوي مجموعات:

- `strategy.*`
- `portfolio_projects.*`
- `risk.*`

لكن لا توجد مجلدات Backend Modules مقابلة لها في الكود الحالي.

الحكم:

- هذه ليست موديولات منفذة.
- هي قدرات محجوزة أو تصميم مستقبلي.
- يجب ألا تعرض الوثائق النظام الحالي وكأنه يضم Strategy أو PortfolioProjects أو
  Risk بناءً على الكتالوج وحده.
- عند تنفيذها لاحقاً، تبقى Tasks وWorkflow وDocuments خدمات مشتركة، ولا تصبح
  المشاريع أو المخاطر أجزاء من Strategy.

## 8. العلاقة العابرة للموديولات

الخريطة التشغيلية الأساسية:

```text
Organization Person
  -> Identity UserAccount
  -> PrincipalContext
  -> Authorization DecideAccess

WorkDefinitionVersion
  -> WorkRecord
  -> WorkflowInstance pinned to WorkflowVersion
  -> WorkflowStepInstance
  -> optional Task
  -> WorkflowDecision

WorkRecord
  -> Outbox
  -> Notifications

WorkRecord
  -> Search projection
  -> Reporting projection

WorkRecord/other source
  -> DocumentLink
  -> Document/DocumentVersion
```

قواعد الملكية الواجب تثبيتها في الوثائق:

- Organization يملك الشخص والهيكل، لا الحساب.
- Identity يملك الحساب والجلسة، لا الدور.
- Authorization يملك قرار الوصول، لا سجل الأعمال.
- WorkDefinitions يملك schema، لا runtime record.
- WorkRecords يملك حالة الأعمال، لا graph التنفيذ.
- Workflow يملك graph/instance/step/decision، ولا يملك النهاية التجارية للمصدر.
- Tasks يملك المهمة، لا خطوة الاعتماد.
- Documents يملك الملف ونسخه، لا المصدر المرتبط به.
- Search وReporting projections وليسا source of truth.
- Notifications يملك التسليم والقراءة فقط.

## 9. ما يجب تحديثه في الوثائق

ترتيب التحديث المقترح:

1. إنشاء Glossary محكوم بالمصطلحات في القسم 4.
2. تحديث خريطة الموديولات إلى 11 موديولاً منفذاً + Shared Infrastructure.
3. إضافة Concept Map تفصل Procedure/Request عن aggregates البرمجية.
4. توثيق علاقة WorkDefinitionVersion وWorkRecord وWorkflowVersion وWorkflowInstance.
5. توثيق الفرق بين WorkflowStep وTask وApproval.
6. حسم canonical lifecycle لـWorkflowVersion قبل نسخ حالات الكود الحالية إلى الوثائق.
7. توثيق Person/UserAccount/Principal.
8. توثيق Facility/OrganizationUnit/AuthorizationScope ومنع fallback الدلالي.
9. فصل قدرات Strategy/PortfolioProjects/Risk إلى «محجوزة وغير منفذة».
10. وضع جدول mapping بين route labels العربية والكيان البرمجي الذي تستخدمه.

لا أوصي بتحديث الوثائق لتطابق التناقضات الحالية حرفياً. في P0 يجب أولاً اتخاذ قرار
نطاقي، ثم تحديث الكود والوثائق معاً.

## 10. أدلة قابلة لإعادة التشغيل

الأوامر المستخدمة للتحقق:

```bash
git status --short --branch
find apps/api/Modules -mindepth 1 -maxdepth 1 -type d | sort
php artisan route:list --json
rg -o "Schema::create\('[^']+'" apps/api/Modules --glob '*.php' | sort -u
make verify-boundaries
```

نتائج التحقق الحالية:

- branch: `main`, متقدم على `origin/main` بـ66 commit وقت التدقيق.
- worktree غير نظيف؛ تغييرات المستخدم لم تُعدل ضمن هذا التدقيق.
- API routes: 119.
- unique module tables: 83.
- module boundary test: passed، 4 tests، 6 assertions.

الملفات المرجعية الأهم:

- `apps/api/routes/web.php`
- `apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php`
- `apps/api/Modules/Identity/Contracts/PrincipalContext.php`
- `apps/api/Modules/Authorization/Domain/AuthorizationScope.php`
- `apps/api/Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateWorkDefinitionTables.php`
- `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php`
- `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/CreateWorkflowTables.php`
- `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/W14AddWorkflowStepAssignee.php`
- `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/W15CreateWorkflowDecisionsTable.php`
- `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/W16CreateWorkflowDecisionsTable.php`
- `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/W17AddApprovalColumnsToWorkflowVersions.php`
- `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/CreateTasksTable.php`
- `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php`
- `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationWorkforceAssignmentsTable.php`
- `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php`
- `apps/web/src/shell/routes.ts`
- `apps/web/src/shell/navigation.tsx`
