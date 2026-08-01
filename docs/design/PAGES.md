# وصف الصفحات — Cluster

> **مصدر الحقيقة لما يجب أن تكون عليه كل صفحة.** يُقرأ مع [DESIGN-RULES.md](DESIGN-RULES.md) — هذا الملف يقول *ماذا*، وذاك يقول *كيف*.
>
> مرجع الـEndpoints المولّد آلياً: [../api/endpoints-table.md](../api/endpoints-table.md)

## التغطية

| الطبقة | العدد | المعنى |
|---|---|---|
| ✅ مفعّل | **126** | يعمل اليوم بلا شروط |
| 🚩 خلف علم | **24** | مسجّل في `web.php` خلف `EnforceWorkManagementFeature`؛ `work_management` يساوي `false` افتراضياً في `apps/api/config/features.php` |
| 📋 مخطط | **55** | في `openapi.yaml` فقط — بلا مسار ولا وحدة تحكم |
| **الإجمالي** | **205** | كل عملية مُسندة لصفحة واحدة بالضبط، أو لـ«خارج نطاق الواجهة» |

**قاعدة الاكتمال:** لا يجوز أن توجد عملية في `endpoints-table.md` بلا إسناد هنا. `scripts/verify-page-coverage.py` يفرض ذلك.

---

# أ. العمل اليومي

ما يفتحه الموظف كل صباح. أقصر مسار من تسجيل الدخول إلى إنجاز شيء.

## الرئيسية

| | |
|---|---|
| **المسار** | `/` |
| **النمط** | لوحة |
| **من يراها** | لا شيء — تظهر لكل مُصادَق عليه |
| **الطبقات** | — |

**الغرض** — يرى الموظف ما يخصّه اليوم: مهامه، إشعاراته، ومدخل البحث.

**المخرج**

شبكة `Card` متجاوبة: عمود واحد على الجوال، ثلاثة على المكتب. بطاقة «مهامي» (أحدث ٥ + عدّاد)، بطاقة «إشعارات غير مقروءة»، بطاقة «نطاق عملك الحالي» تعرض النطاق الفعّال مع زر تبديل. كل بطاقة ترسم حالاتها السبع باستقلال — فشل واحدة لا يُسقط اللوحة.

**الإجراءات** — كل بطاقة تنقل لمساحتها الكاملة. لا إجراءات كتابة في اللوحة.

<details><summary>الـEndpoints</summary>

_لا توجد عمليات مباشرة._

</details>

## المهام

| | |
|---|---|
| **المسار** | `/tasks · /tasks/new · /tasks/:taskId` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `tasks.list` + علم `tasks` (مفعّل افتراضياً) |
| **الطبقات** | ✅ مفعّل × 9 |

**الغرض** — يتابع المستخدم ما هو مطلوب منه ومن غيره، ويحرّك المهمة في دورة حياتها.

**المخرج**

**القائمة**: `DataTable` بأعمدة العنوان، الحالة (`Badge`)، الأولوية، المسؤول، تاريخ الاستحقاق. شريط أدوات فيه بحث نصّي وفلتر حالة وفلتر أولوية. ترقيم بالمؤشّر. زر أساسي «أنشئ مهمة» يمينه… (بداية السطر منطقياً).
**التفصيل**: صفحة كاملة لا Modal — رأس فيه العنوان والحالة وأزرار الانتقالات المسموحة من `allowed_actions`، ثم ثلاثة تبويبات: التفاصيل · التعليقات · المشاركون. لا تُشتقّ الانتقالات المسموحة في الواجهة؛ تُقرأ من الاستجابة.
**الإنشاء**: صفحة مستقلة `/tasks/new` بنموذج `react-hook-form` + `zod`، لا Sheet — النموذج طويل.

**الإجراءات** — إنشاء (`Idempotency-Key`) · تحديث (`If-Match`) · انتقال (`Idempotency-Key` + `If-Match`) · تعليق · إضافة مشارك (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `tasks` | `listTasks` | ✅ مفعّل |
| `POST` | `tasks` | `createTask` | ✅ مفعّل |
| `GET` | `tasks/{taskId}` | `getTask` | ✅ مفعّل |
| `PATCH` | `tasks/{taskId}` | `updateTask` | ✅ مفعّل |
| `GET` | `tasks/{taskId}/comments` | `listTaskComments` | ✅ مفعّل |
| `POST` | `tasks/{taskId}/comments` | `addTaskComment` | ✅ مفعّل |
| `POST` | `tasks/{taskId}/documents` | `attachTaskDocument` | ✅ مفعّل |
| `POST` | `tasks/{taskId}/participants` | `addTaskParticipant` | ✅ مفعّل |
| `POST` | `tasks/{taskId}/{taskAction}` | `transitionTask` | ✅ مفعّل |

</details>

## المستندات

| | |
|---|---|
| **المسار** | `/documents · /documents/:documentId` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `documents.list` |
| **الطبقات** | ✅ مفعّل × 14 |

**الغرض** — يجد المستخدم مستنداً، يقرأ نسخته المعتمدة، ويربطه بسجل عمل.

**المخرج**

**القائمة**: `DataTable` بأعمدة العنوان، النوع، الحالة، آخر تعديل. شريط أدوات فيه بحث وفلتر حالة. زر «أنشئ مستند» يفتح `Sheet` — النموذج قصير.
**التفصيل**: صفحة كاملة، رأس فيه العنوان والحالة وأزرار الانتقال، ثم تبويبات: المعاينة · النسخ · الروابط · الوصول.
**الرفع** عملية من ثلاث خطوات (تهيئة ← رفع ← إتمام) تُعرض كشريط تقدّم واحد داخل `Sheet`، لا كثلاث شاشات — المستخدم يراها فعلاً واحداً. حالة الفحص من الفيروسات تظهر كـ`Badge` مع أيقونة، لا كلون مخصّص.

**الإجراءات** — إنشاء · تهيئة رفع · إتمام رفع · إضافة نسخة · ربط · منح وصول · انتقال (`If-Match`) · تنزيل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `documents` | `listDocuments` | ✅ مفعّل |
| `POST` | `documents` | `createDocument` | ✅ مفعّل |
| `POST` | `documents/uploads` | `initiateDocumentUpload` | ✅ مفعّل |
| `GET` | `documents/uploads/{uploadId}` | `getDocumentUploadStatus` | ✅ مفعّل |
| `POST` | `documents/uploads/{uploadId}/complete` | `completeDocumentUpload` | ✅ مفعّل |
| `GET` | `documents/{documentId}` | `getDocument` | ✅ مفعّل |
| `PATCH` | `documents/{documentId}` | `updateDocument` | ✅ مفعّل |
| `GET` | `documents/{documentId}/download` | `downloadDocument` | ✅ مفعّل |
| `GET` | `documents/{documentId}/links` | `listDocumentLinks` | ✅ مفعّل |
| `POST` | `documents/{documentId}/links` | `linkDocument` | ✅ مفعّل |
| `GET` | `documents/{documentId}/versions` | `listDocumentVersions` | ✅ مفعّل |
| `POST` | `documents/{documentId}/versions` | `addDocumentVersion` | ✅ مفعّل |
| `POST` | `documents/{documentId}/{documentAction}` | `transitionDocument` | ✅ مفعّل |
| `POST` | `documents/{documentId}/{documentGrantType}-grant` | `createDocumentAccessGrant` | ✅ مفعّل |

</details>

## الإشعارات

| | |
|---|---|
| **المسار** | `/notifications` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | لا شيء — إشعارات المستخدم نفسه |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يعرف المستخدم ما جدّ عليه ويصل للسجل المعني بنقرة.

**المخرج**

قائمة عمودية لا جدول — الإشعار نص لا صفوف بيانات. غير المقروء يُميَّز بنقطة `bg-primary` ووزن خط أثقل، **لا بخلفية ملوّنة**. نقر الإشعار يعلّمه مقروءاً وينقل للوجهة في فعل واحد. ترقيم بالمؤشّر بزر «المزيد» لا تمرير لانهائي — التمرير اللانهائي يفقد الموضع عند العودة.

**الإجراءات** — تعليم كمقروء

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `notifications` | `listMyNotifications` | ✅ مفعّل |
| `POST` | `notifications/{notificationId}/read` | `markNotificationRead` | ✅ مفعّل |

</details>

## البحث

| | |
|---|---|
| **المسار** | `⌘K · /search` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | لا شيء — النتائج مقيّدة بالنطاق في الخادم |
| **الطبقات** | ✅ مفعّل × 1 |

**الغرض** — يصل المستخدم لأي كيان بالاسم دون معرفة مكانه في التنقّل.

**المخرج**

**الأساسي `Command` palette** بـ`⌘K` — هذا هو السطح المتوقّع عالمياً في لوحات الإدارة، ويجب أن يكون متاحاً من كل صفحة. النتائج مجمّعة بالنوع مع أيقونة لكل مجموعة.
**`/search`** صفحة كاملة للنتائج الطويلة مع فلاتر النوع والحالة وترقيم بالمؤشّر. تُفتح من «عرض كل النتائج» في الـpalette.

**الإجراءات** — قراءة فقط

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `search` | `search` | ✅ مفعّل |

</details>

## سجلات العمل

| | |
|---|---|
| **المسار** | `/work-records · /work-records/:recordId` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `work_management.*` + **علم `work_management`** |
| **الطبقات** | 🚩 خلف علم `work_management` × 5 · 📋 مخطط — API غير منفَّذ × 1 |

**الغرض** — يقدّم المستخدم سجل عمل مُهيكلاً ويتابع دورته.

**المخرج**

**غائبة تماماً عند إطفاء العلم** (القاعدة 4.2) — لا رابط ولا رسالة؛ المسار يردّ 404.
**القائمة**: `DataTable` بأعمدة رقم السجل، النوع، الحالة، التصنيف، المالك.
**التفصيل**: صفحة كاملة. الحمولة (`payload`) تُرسم **ديناميكياً من مخطّط نموذج العمل** لا بحقول مثبّتة — هذا جوهر الوحدة. `field_access` في الاستجابة يحدّد ما يُقرأ وما يُحرَّر لكل حقل، ويجب احترامه حرفياً.

**الإجراءات** — إنشاء (`Idempotency-Key`) · تحديث *(مخطط)* · انتقال (`If-Match`) · ربط مستند

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `work-records` | `listWorkRecords` | 🚩 خلف علم `work_management` |
| `POST` | `work-records` | `createWorkRecord` | 🚩 خلف علم `work_management` |
| `GET` | `work-records/{recordId}` | `getWorkRecord` | 🚩 خلف علم `work_management` |
| `PATCH` | `work-records/{recordId}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `work-records/{recordId}/documents` | `linkWorkRecordDocument` | 🚩 خلف علم `work_management` |
| `POST` | `work-records/{recordId}/{recordAction}` | `جلسة + CSRF` | 🚩 خلف علم `work_management` |

</details>

## صندوق الموافقات

| | |
|---|---|
| **المسار** | `/inbox` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `workflow.step.*` + **علم `work_management`** |
| **الطبقات** | 🚩 خلف علم `work_management` × 4 |

**الغرض** — يرى المُعتمِد ما ينتظر قراره في مكان واحد، ويقرّر منه مباشرة.

**المخرج**

**غائبة تماماً عند إطفاء العلم.**
قائمة الخطوات المسندة للمستخدم — `DataTable` بأعمدة السجل، نوع الخطوة، تاريخ الإسناد، الحالة. فلتر `assignee` (لي / لفريقي) وفلتر `state`.
القرار يُتّخذ من **`Sheet` جانبي** لا صفحة منفصلة: المُعتمِد يحتاج سياق القائمة وهو يقرّر، والتنقّل بين خطوتين يجب أن يكون بنقرة. الـSheet يعرض ملخّص السجل وحقل السبب وأزرار القرار من `allowed_actions`.

**الإجراءات** — قرار (`Idempotency-Key`) · إجراء على خطوة (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `workflow/steps` | `listWorkflowStepsInbox` | 🚩 خلف علم `work_management` |
| `GET` | `workflow/steps/{stepId}` | `getWorkflowStep` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/steps/{stepId}/decisions` | `recordWorkflowDecision` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/steps/{stepId}/{stepAction}` | `actOnWorkflowStep` | 🚩 خلف علم `work_management` |

</details>

# ب. المنظمة

مساحة واحدة `/organization` بتبويبات. كانت ١٠ صفحات منفصلة في التوزيع القديم — وهي عملياً أوجه لكيان واحد: من يعمل أين وبأي صفة.

## الهيكل التنظيمي

| | |
|---|---|
| **المسار** | `/organization → تبويب الهيكل` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.unit.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 5 |

**الغرض** — يفهم المستخدم شجرة الوحدات ويعيد ترتيبها.

**المخرج**

**شجرة `Collapsible` لا جدول** — العلاقة الأبوية هي المعلومة الأساسية وتضيع في جدول مسطّح. كل عقدة: الاسم، الرمز، `Badge` الحالة، وقائمة `DropdownMenu` للإجراءات. التحرير في `Sheet`.
إعادة الترتيب عملية جماعية صريحة بزر «رتّب» يفعّل وضع السحب ثم «احفظ الترتيب» — لا حفظ ضمني عند كل إفلات؛ العملية تحتاج `If-Match` والفشل الصامت غير مقبول.

**الإجراءات** — إنشاء · تحديث (`If-Match`) · إعادة ترتيب (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/units` | `listOrganizationUnits` | ✅ مفعّل |
| `POST` | `organization/units` | `createOrganizationUnit` | ✅ مفعّل |
| `POST` | `organization/units/reorder` | `reorderOrganizationUnits` | ✅ مفعّل |
| `GET` | `organization/units/{unitId}` | `getOrganizationUnit` | ✅ مفعّل |
| `PATCH` | `organization/units/{unitId}` | `updateOrganizationUnit` | ✅ مفعّل |

</details>

## المنشآت

| | |
|---|---|
| **المسار** | `/organization → تبويب المنشآت` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.facility.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 4 |

**الغرض** — يدير المستخدم المنشآت التابعة للمجمّع.

**المخرج**

`DataTable`: الاسم، الرمز، النوع، الحالة. الإنشاء والتحرير في `Sheet`. تعارض الإنشاء المتزامن (409) يظهر كـ`Alert` داخل الـSheet مع إبقاء المُدخَل — لا يُغلق ولا يُفرَّغ.

**الإجراءات** — إنشاء · تحديث (`If-Match`) · تغيير حالة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/facilities` | `listFacilities` | ✅ مفعّل |
| `POST` | `organization/facilities` | `createFacility` | ✅ مفعّل |
| `GET` | `organization/facilities/{facilityId}` | `getFacility` | ✅ مفعّل |
| `PATCH` | `organization/facilities/{facilityId}` | `Modules\Organization\Features\UpdateFacility\Http\UpdateFacilityController` | ✅ مفعّل |

</details>

## الوظائف

| | |
|---|---|
| **المسار** | `/organization → تبويب الوظائف` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.position.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 4 |

**الغرض** — يعرّف المستخدم الوظائف داخل الوحدات ويربطها بالمسميات.

**المخرج**

`DataTable`: المسمّى، الوحدة، الحالة، المشغول/الشاغر. فلتر بالوحدة. تحرير في `Sheet`.

**الإجراءات** — إنشاء · تحديث (`If-Match`) · تغيير حالة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/positions` | `listPositions` | ✅ مفعّل |
| `POST` | `organization/positions` | `createPosition` | ✅ مفعّل |
| `GET` | `organization/positions/{positionId}` | `getPosition` | ✅ مفعّل |
| `PATCH` | `organization/positions/{positionId}` | `updatePosition` | ✅ مفعّل |

</details>

## المسميات الوظيفية

| | |
|---|---|
| **المسار** | `/organization → تبويب المسميات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.job_title.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يحافظ المستخدم على قائمة مرجعية موحّدة للمسميات.

**المخرج**

جدول بسيط: الاسم، الرمز، الحالة. مرجع صغير — لا يحتاج فلاتر ولا بحث متقدّم.

**الإجراءات** — إنشاء · تحديث

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/job-titles` | `listJobTitles` | ✅ مفعّل |
| `POST` | `organization/job-titles` | `createJobTitle` | ✅ مفعّل |

</details>

## الموظفون

| | |
|---|---|
| **المسار** | `/organization → تبويب الموظفون` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.person.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 5 |

**الغرض** — يسجّل المستخدم الأشخاص ويحدّث بياناتهم ويتحقّق من مرجعياتهم.

**المخرج**

`DataTable`: الاسم، الرقم الوظيفي، الحالة، الوحدة. بحث بالاسم والرقم. التفصيل في `Sheet` بتبويبين: البيانات · التكليفات.
التحقّق من المرجع (`validate-reference`) يُستدعى داخل النموذج عند فقدان التركيز من حقل المعرّف، ويعرض النتيجة تحت الحقل — لا كزر منفصل.

**الإجراءات** — تسجيل · تحديث (`If-Match`) · التحقّق من مرجع

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/people` | `listPeople` | ✅ مفعّل |
| `POST` | `organization/people` | `registerPerson` | ✅ مفعّل |
| `GET` | `organization/people/{personId}` | `getPerson` | ✅ مفعّل |
| `PATCH` | `organization/people/{personId}` | `updatePerson` | ✅ مفعّل |
| `GET` | `organization/people/{personId}/reference` | `validatePersonReference` | ✅ مفعّل |

</details>

## التكليفات

| | |
|---|---|
| **المسار** | `/organization → تبويب التكليفات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.assignment.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 3 |

**الغرض** — يربط المستخدم شخصاً بوظيفة لمدة محدّدة.

**المخرج**

`DataTable`: الشخص، الوظيفة، من، إلى، الحالة. الإنهاء إجراء صريح بتأكيد `AlertDialog` — لا زر مباشر في الصف؛ العملية غير قابلة للتراجع.

**الإجراءات** — إنشاء · إنهاء (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/assignments` | `listAssignments` | ✅ مفعّل |
| `POST` | `organization/assignments` | `createAssignment` | ✅ مفعّل |
| `POST` | `organization/assignments/{assignmentId}/end` | `endAssignment` | ✅ مفعّل |

</details>

## التكليفات المؤقتة

| | |
|---|---|
| **المسار** | `/organization → تبويب التكليفات المؤقتة` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.temporary_assignment.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 4 |

**الغرض** — يمنح المستخدم صلاحية مؤقتة بتفويض موثّق ومحدود بزمن.

**المخرج**

`DataTable`: المفوَّض له، النطاق، تنتهي في، الحالة. **عمود «تنتهي في» يعرض المدة المتبقية نصّاً** («٣ أيام») لا التاريخ وحده — الإلحاح هو المعلومة المهمة. الإلغاء بتأكيد `AlertDialog` مع حقل سبب إلزامي.

**الإجراءات** — إنشاء · إلغاء (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/temporary-assignments` | `listTemporaryAssignments` | ✅ مفعّل |
| `POST` | `organization/temporary-assignments` | `createTemporaryAssignment` | ✅ مفعّل |
| `GET` | `organization/temporary-assignments/{temporaryAssignmentId}` | `getTemporaryAssignment` | ✅ مفعّل |
| `POST` | `organization/temporary-assignments/{temporaryAssignmentId}/revoke` | `revokeTemporaryAssignment` | ✅ مفعّل |

</details>

## العلاقات الإشرافية

| | |
|---|---|
| **المسار** | `/organization → تبويب الإشراف` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `organization.supervisory.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يوثّق المستخدم من يشرف على من، وهو أساس تصعيد الموافقات.

**المخرج**

`DataTable`: المشرف، المرؤوس، النوع، الحالة. فلتر بالمشرف.

**الإجراءات** — إنشاء

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/supervisory-relationships` | `listSupervisoryRelationships` | ✅ مفعّل |
| `POST` | `organization/supervisory-relationships` | `createSupervisoryRelationship` | ✅ مفعّل |

</details>

## إعداد المجمّع

| | |
|---|---|
| **المسار** | `/organization → تبويب المجمّع` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `organization.cluster.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 3 |

**الغرض** — يعرّف المالك كيان المجمّع نفسه مرة واحدة عند التأسيس.

**المخرج**

نموذج مفرد لا جدول — المجمّع كيان واحد. حين لا يوجد مجمّع (404) تُعرض `EmptyState` بزر «أنشئ المجمّع»؛ هذه هي الحالة الوحيدة التي يكون فيها 404 مساراً متوقّعاً لا خطأً، وتُعالَج صراحةً في `useCluster`.

**الإجراءات** — إنشاء · تحديث (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `organization/cluster` | `getCluster` | ✅ مفعّل |
| `PATCH` | `organization/cluster` | `updateCluster` | ✅ مفعّل |
| `POST` | `organization/cluster` | `createCluster` | ✅ مفعّل |

</details>

## استيراد الموظفين

| | |
|---|---|
| **المسار** | `/organization/import · /organization/import/:jobId` |
| **النمط** | معالج |
| **من يراها** | `organization.import.manage` |
| **الطبقات** | ✅ مفعّل × 5 |

**الغرض** — يُدخل المستخدم مئات الموظفين من ملف CSV مع مراجعة قبل الالتزام.

**المخرج**

**معالج بأربع خطوات** لا صفحة واحدة — العملية غير قابلة للتراجع وتحتاج نقاط توقّف:
1. **رفع** — منطقة إفلات + قالب CSV قابل للتنزيل + شريط تقدّم.
2. **تحقّق** — انتظار الفحص، يُعرض بـ`Skeleton` لا دوّارة.
3. **مراجعة** — `DataTable` للصفوف بأعمدة الصف، الإجراء المقترح، الأخطاء. **الصفوف ذات الأخطاء المانعة تُصفّى بفلتر افتراضي عليها** — المستخدم يريد رؤية المشاكل أولاً لا كل الصفوف. قرار لكل صفر (قبول/تجاهل).
4. **التزام** — ملخّص العدد قبل التنفيذ + `AlertDialog` تأكيد.
المعالج مستقل عن تبويبات `/organization` — لا يُحشر كتبويب خامس؛ عملية لا وجهة.

**الإجراءات** — رفع ملف · إرسال · قرار صف · انتقال (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `organization/import-files` | `uploadOrganizationImportFile` | ✅ مفعّل |
| `POST` | `organization/import-jobs` | `submitOrganizationImport` | ✅ مفعّل |
| `GET` | `organization/import-jobs/{jobId}` | `getOrganizationImport` | ✅ مفعّل |
| `GET` | `organization/import-jobs/{jobId}/rows` | `listOrganizationImportRows` | ✅ مفعّل |
| `POST` | `organization/import-jobs/{jobId}/{jobAction}` | `transitionOrganizationImport` | ✅ مفعّل |

</details>

# ج. سير العمل ونماذج العمل

**المساحة كاملة خلف علم `work_management` المُطفأ افتراضياً، أو مخطّطة.** لا يراها أحد اليوم. مُوثّقة لأن الـAPI موجود ومُختبَر، فتصبح جاهزة لحظة تشغيل العلم.

## سير العمل

| | |
|---|---|
| **المسار** | `/workflow` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `workflow.*` + **علم `work_management`** |
| **الطبقات** | 🚩 خلف علم `work_management` × 9 · 📋 مخطط — API غير منفَّذ × 2 |

**الغرض** — يصمّم مسؤول العمليات مسارات الموافقة ويتابع الحالات الجارية.

**المخرج**

ثلاثة تبويبات:
**التعريفات** — `DataTable`: الاسم، الرمز، عدد الإصدارات، الحالة.
**الإصدارات** — قائمة إصدارات التعريف المحدّد مع `Badge` لدورة الحياة (مسودة/قيد المراجعة/منشور). المخطّط (`nodes`/`transitions`) يُعرض كقائمة عقد منظّمة لا كرسم بياني تفاعلي — الرسم البياني مشروع مستقل، والقائمة تكفي للقراءة والتحقّق.
**الحالات الجارية** — `DataTable`: السجل، الإصدار، الخطوة الحالية، البدء. فلتر `state`. الإلغاء بتأكيد وسبب.

**الإجراءات** — إنشاء تعريف · إنشاء إصدار · بدء حالة · إلغاء · انتقال إصدار (`If-Match`) · تحديث مسودة *(مخطط)*

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `workflow/definitions` | `listWorkflowDefinitions` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/definitions` | `createWorkflowDefinition` | 🚩 خلف علم `work_management` |
| `GET` | `workflow/definitions/{definitionId}/versions` | `listWorkflowVersions` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/definitions/{definitionId}/versions` | `createWorkflowVersion` | 🚩 خلف علم `work_management` |
| `GET` | `workflow/instances` | `listWorkflowInstances` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/instances` | `startWorkflow` | 🚩 خلف علم `work_management` |
| `GET` | `workflow/instances/{instanceId}` | `getWorkflowInstance` | 🚩 خلف علم `work_management` |
| `POST` | `workflow/instances/{instanceId}/cancel` | `cancelWorkflow` | 🚩 خلف علم `work_management` |
| `GET` | `workflow/versions/{versionId}` | — | 📋 مخطط — API غير منفَّذ |
| `PATCH` | `workflow/versions/{versionId}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `workflow/versions/{versionId}/{workflowLifecycleAction}` | `transitionWorkflowVersion` | 🚩 خلف علم `work_management` |

</details>

## نماذج العمل

| | |
|---|---|
| **المسار** | `/work-definitions` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `work_management.definition.*` + **علم `work_management`** |
| **الطبقات** | 🚩 خلف علم `work_management` × 6 · 📋 مخطط — API غير منفَّذ × 6 |

**الغرض** — يعرّف مسؤول العمليات مخطّط سجل العمل وحقوله وسياسات حقوله.

**المخرج**

تبويبان:
**النماذج** — `DataTable`: الاسم، الرمز، التصنيف الافتراضي، الإصدار المنشور.
**الإصدارات** — دورة حياة طويلة (مسودة ← اختبار ← موافقة ← توقيع ← نشر)، تُعرض كـ**`Stepper` أفقي** يبيّن الموضع الحالي والخطوة التالية المتاحة، لا كأزرار متناثرة. مخطّط الحقول (`schema_document`) يُعرض كجدول حقول للقراءة؛ محرّر المخطّط خارج النطاق.
**التوقيع** يتطلّب `schema_hash` و`signature` و`key_id` — يُعرض كـ`Dialog` مخصّص مع تحذير صريح أنه غير قابل للتراجع.

**الإجراءات** — إنشاء · إنشاء إصدار · تحديث مسودة *(مخطط)* · اختبار *(مخطط)* · موافقة *(مخطط)* · توقيع *(مخطط)* · نشر *(مخطط)*

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `work-definition-versions/{versionId}` | `getWorkDefinitionVersion` | 🚩 خلف علم `work_management` |
| `PATCH` | `work-definition-versions/{versionId}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `work-definition-versions/{versionId}/approve` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `work-definition-versions/{versionId}/publish` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `work-definition-versions/{versionId}/sign` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `work-definition-versions/{versionId}/test` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `work-definitions` | `listWorkDefinitions` | 🚩 خلف علم `work_management` |
| `POST` | `work-definitions` | `createWorkDefinition` | 🚩 خلف علم `work_management` |
| `GET` | `work-definitions/{definitionId}` | `getWorkDefinition` | 🚩 خلف علم `work_management` |
| `PATCH` | `work-definitions/{definitionId}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `work-definitions/{definitionId}/versions` | `listWorkDefinitionVersions` | 🚩 خلف علم `work_management` |
| `POST` | `work-definitions/{definitionId}/versions` | `createWorkDefinitionVersion` | 🚩 خلف علم `work_management` |

</details>

## مكتب العمليات

| | |
|---|---|
| **المسار** | `/workflow/operations-office` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `workflow.operations_office.*` — **مخطط بالكامل** |
| **الطبقات** | 📋 مخطط — API غير منفَّذ × 5 |

**الغرض** — يراجع مكتب العمليات إصدارات سير العمل قبل نشرها على مستوى المجمّع.

**المخرج**

صندوق مراجعة على نمط `/inbox`: قائمة الإصدارات المُرسَلة + `Sheet` للقرار. سجل التدقيق للإصدار يُعرض كخط زمني في الـSheet. **لا يُبنى قبل تنفيذ الـAPI.**

**الإجراءات** — إرسال · إرجاع · موافقة · نشر · سجل تدقيق — كلها *(مخطط)*

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `workflow/operations-office/versions/{versionId}/approve` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `workflow/operations-office/versions/{versionId}/audit` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `workflow/operations-office/versions/{versionId}/publish` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `workflow/operations-office/versions/{versionId}/return` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `workflow/operations-office/versions/{versionId}/submit` | — | 📋 مخطط — API غير منفَّذ |

</details>

# د. الحسابات والصلاحيات

مساحة `/access` بتبويبات. من يدخل النظام، وبأي دور، وفي أي نطاق.

## الحسابات

| | |
|---|---|
| **المسار** | `/access → تبويب الحسابات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `identity.account.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 6 |

**الغرض** — ينشئ المسؤول حسابات الدخول ويفعّلها ويوقفها.

**المخرج**

`DataTable`: اسم المستخدم، الشخص المرتبط، الحالة، آخر دخول. الإنشاء في `Sheet`.
**التفعيل** يُسلَّم عبر القناة الآمنة المعتمدة ولا يُكشف الرمز في هذه الواجهة الإدارية أبداً — `Dialog` يؤكّد الإصدار وانتهاء الصلاحية فقط. لا رمز في قائمة ولا في الحالة ولا في النسخ.
تغيير الحالة (إيقاف/تعطيل) بتأكيد `AlertDialog`.

**الإجراءات** — إنشاء · انتقال حالة (`If-Match`) · إصدار تفعيل · استهلاك تفعيل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `identity/accounts` | `listUserAccounts` | ✅ مفعّل |
| `POST` | `identity/accounts` | `createUserAccount` | ✅ مفعّل |
| `GET` | `identity/accounts/{accountId}` | `getUserAccount` | ✅ مفعّل |
| `POST` | `identity/accounts/{accountId}/activation` | `issueIdentityActivation` | ✅ مفعّل |
| `POST` | `identity/accounts/{accountId}/{accountAction}` | `transitionUserAccount` | ✅ مفعّل |
| `POST` | `identity/activation` | `consumeIdentityActivation` | ✅ مفعّل |

</details>

## الأدوار والقدرات

| | |
|---|---|
| **المسار** | `/access → تبويب الأدوار` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `authorization.role.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 6 |

**الغرض** — يعرّف المسؤول الأدوار ويسند القدرات ويمنح الأدوار في نطاقات.

**المخرج**

`DataTable` مع مبدّل نوع المورد (أدوار / قدرات / تعيينات) في شريط الأدوات — ثلاثة موارد بنفس الشكل، فلا داعي لثلاثة تبويبات (القاعدة 2.2).
**اختيار نطاق التعيين** هو أصعب جزء: `Combobox` بحث تدريجي يستدعي `assignment-scope-targets` مع `parent_scope_type`، لا قائمة منسدلة محمّلة مسبقاً — النطاقات قد تكون بالمئات.
القدرات الحسّاسة تُعلَّم بأيقونة `ShieldAlert` من `lucide`، لا بلون.

**الإجراءات** — إنشاء · تحديث (`If-Match`) · انتقال (`If-Match`) · بحث نطاقات

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `authorization/assignment-scope-targets` | `listAuthorizationAssignmentScopeTargets` | ✅ مفعّل |
| `GET` | `authorization/{adminResource}` | `listAuthorizationAdminResources` | ✅ مفعّل |
| `POST` | `authorization/{adminResource}` | `createAuthorizationAdminResource` | ✅ مفعّل |
| `GET` | `authorization/{adminResource}/{resourceId}` | `getAuthorizationAdminResource` | ✅ مفعّل |
| `PATCH` | `authorization/{adminResource}/{resourceId}` | `updateAuthorizationAdminResource` | ✅ مفعّل |
| `POST` | `authorization/{adminResource}/{resourceId}/{authorizationAction}` | `جلسة + CSRF` | ✅ مفعّل |

</details>

## تشخيص الوصول

| | |
|---|---|
| **المسار** | `/access → تبويب التشخيص` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `authorization.decision.read` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يفهم المسؤول **لماذا** مُنع مستخدم من فعل، بدل التخمين.

**المخرج**

نموذج «جرّب قراراً»: الفعل + سياق الوصول + حقائق السجل ← يعرض القرار (`permit`/`deny`) مع **سلسلة التبرير كخط زمني**: القاعدة المطبَّقة، التعيينات المؤثّرة، الالتزامات الناتجة. هذه شاشة تشخيص لمسؤول تقني — يُسمح فيها بمصطلح تقني استثناءً من القاعدة 2.5، ويُنصّ على ذلك في الصفحة.

**الإجراءات** — قرار وصول (`Idempotency-Key`) · تفسير قرار

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `authorization/access-decisions` | `decideAccess` | ✅ مفعّل |
| `GET` | `authorization/access-decisions/{decisionId}/explanation` | `explainAccessDecision` | ✅ مفعّل |

</details>

## التهيئة الأولية

| | |
|---|---|
| **المسار** | `/access → تبويب التهيئة` |
| **النمط** | معالج |
| **من يراها** | `authorization.bootstrap.*` |
| **الطبقات** | ✅ مفعّل × 2 · 📋 مخطط — API غير منفَّذ × 1 |

**الغرض** — يؤسّس أول مالك للنظام عند تركيب جديد.

**المخرج**

تظهر **فقط** حين ترجع `bootstrap` حالة تسمح بها؛ بعد الاكتمال تختفي من التبويبات نهائياً. معالج بخطوة واحدة + سبب إلزامي + `AlertDialog` تأكيد — عملية تُنفَّذ مرة في عمر النظام.

**الإجراءات** — قراءة حالة · إتمام (`If-Match`) · تهيئة *(مخطط)*

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `authorization/bootstrap` | `getAuthorizationBootstrap` | ✅ مفعّل |
| `POST` | `authorization/bootstrap` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `authorization/bootstrap/complete` | `bootstrapComplete` | ✅ مفعّل |

</details>

# هـ. التقارير والمراقبة

مساحة `/reports` بتبويبات — القراءة والتحليل والأثر.

## التقارير

| | |
|---|---|
| **المسار** | `/reports → تبويب التقارير` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `reporting.read` |
| **الطبقات** | ✅ مفعّل × 3 |

**الغرض** — يشغّل المستخدم تقريراً مُعرَّفاً ويصدّره.

**المخرج**

قائمة التقارير في العمود الجانبي، والمُحدَّد يُعرض في المساحة الرئيسية. التصدير **غير متزامن** (202): الزر يفتح `Dialog` اختيار الصيغة ثم يعرض `Toast` بـ`sonner` «جارٍ التحضير»، والنتيجة تُتابَع من تبويب التصديرات. **لا تُجمَّد الواجهة بانتظار التصدير.**

**الإجراءات** — قراءة · إنشاء تصدير (`Idempotency-Key`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `reports` | `listReports` | ✅ مفعّل |
| `GET` | `reports/{reportId}` | `getReport` | ✅ مفعّل |
| `POST` | `reports/{reportId}/exports` | `createReportExport` | ✅ مفعّل |

</details>

## لوحات المعلومات

| | |
|---|---|
| **المسار** | `/reports → تبويب اللوحات` |
| **النمط** | لوحة |
| **من يراها** | `reporting.dashboard` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يقرأ المستخدم مؤشّرات مجمّعة بصرياً.

**المخرج**

مُحدِّد لوحة في شريط الأدوات، ثم شبكة `Card` للعناصر. الرسوم بـ`recharts` وألوانها **من `chart-1..5` حصراً** — لا لوحة ألوان مخصّصة. كل بطاقة ترسم حالاتها باستقلال.

**الإجراءات** — قراءة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `dashboards` | `listDashboards` | ✅ مفعّل |
| `GET` | `dashboards/{dashboardId}` | `getDashboard` | ✅ مفعّل |

</details>

## سجل التدقيق

| | |
|---|---|
| **المسار** | `/reports → تبويب التدقيق` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `audit.event.read` |
| **الطبقات** | ✅ مفعّل × 6 |

**الغرض** — يتتبّع المدقّق من فعل ماذا ومتى، ويثبت سلامة السجل.

**المخرج**

`DataTable`: الوقت، الفاعل، الفعل، المورد، النتيجة. فلاتر: مدى زمني، نوع فاعل، نتيجة. **ترقيم بالمؤشّر إلزامي** — السجل قد يبلغ ملايين الصفوف.
تفصيل الحدث في `Sheet` يعرض الحمولة كاملة.
**التحقّق من السلامة** إجراء صريح يعرض النتيجة كـ`Alert` مع مدى ما جرى التحقّق منه، لا كشارة دائمة.
التصدير غير متزامن كالتقارير.

**الإجراءات** — قراءة · تفصيل · إنشاء تصدير · تنزيل تصدير · تحقّق سلامة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `audit/events` | `listAuditEvents` | ✅ مفعّل |
| `GET` | `audit/events/{eventId}` | `getAuditEvent` | ✅ مفعّل |
| `POST` | `audit/exports` | `createAuditExport` | ✅ مفعّل |
| `GET` | `audit/exports/{exportId}` | `getAuditExport` | ✅ مفعّل |
| `GET` | `audit/exports/{exportId}/download` | `downloadAuditExport` | ✅ مفعّل |
| `POST` | `audit/integrity-verifications` | `verifyAuditIntegrity` | ✅ مفعّل |

</details>

## التصديرات

| | |
|---|---|
| **المسار** | `/reports → تبويب التصديرات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | مالك التصدير |
| **الطبقات** | ✅ مفعّل × 1 |

**الغرض** — يتابع المستخدم تصديراته وينزّلها حين تجهز.

**المخرج**

قائمة بالحالة (قيد التحضير / جاهز / فشل) وزر تنزيل يُفعَّل عند الجهوزية فقط. الاستطلاع بـ`refetchInterval` من TanStack Query يتوقّف عند الوصول لحالة نهائية — لا استطلاع أبدي.

**الإجراءات** — تنزيل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `exports/{exportId}` | `Modules\Reporting\Features\Exports\Http\DownloadExportController` | ✅ مفعّل |

</details>

# و. إدارة المنصة

مساحة `/platform` بتبويبات — الإعدادات والتشغيل. أخطر مساحة في النظام؛ كل إجراء مدمّر يمرّ بتأكيد.

## نظرة عامة

| | |
|---|---|
| **المسار** | `/platform → تبويب النظرة العامة` |
| **النمط** | لوحة |
| **من يراها** | `platform_operations.health.read` |
| **الطبقات** | ✅ مفعّل × 1 |

**الغرض** — يرى المشغّل حالة المنصة في شاشة واحدة.

**المخرج**

شبكة `Card`: حالة الخدمات، آخر نسخة احتياطية، نوافذ الصيانة القادمة، تنبيهات نشطة. الحالة الصحّية بأيقونة `lucide` + نص، **لا بنقطة ملوّنة وحدها** — اللون وحده ليس معلومة يمكن الاعتماد عليها.

**الإجراءات** — قراءة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/overview` | `getPlatformOperationsOverview` | ✅ مفعّل |

</details>

## الإعدادات والإصدارات

| | |
|---|---|
| **المسار** | `/platform → تبويب الإعدادات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_settings.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 6 |

**الغرض** — يعدّل المالك إعدادات المنصة عبر مسودة تُتحقَّق ثم تُنشر.

**المخرج**

**الإعدادات مُصدَّرة لا مباشرة** — وهذا يجب أن يظهر في الواجهة: شريط علوي دائم يبيّن الإصدار الحالي المنشور وما إن كانت هناك مسودة مفتوحة.
قائمة المفاتيح مع قيمها، والتحرير في `Sheet` بحقل يتبدّل حسب `value_type`.
النشر يتطلّب تحقّقاً ناجحاً أولاً — زر النشر معطّل حتى يمرّ التحقّق، مع تلميح يشرح السبب. محاولة النشر بلا تحقّق ترجع 409، وهذا سلوك مُختبَر.

**الإجراءات** — إنشاء مسودة · تعديل قيمة (`If-Match`) · تحقّق · نشر (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-settings/current` | `getCurrentPlatformSettings` | ✅ مفعّل |
| `GET` | `platform-settings/versions` | `listPlatformSettingsVersions` | ✅ مفعّل |
| `POST` | `platform-settings/versions` | `createPlatformSettingsDraft` | ✅ مفعّل |
| `POST` | `platform-settings/versions/{versionId}/publish` | `publishPlatformSettingsVersion` | ✅ مفعّل |
| `PUT` | `platform-settings/versions/{versionId}/settings/{settingKey}` | `setPlatformSetting` | ✅ مفعّل |
| `POST` | `platform-settings/versions/{versionId}/validate` | `validatePlatformSettingsVersion` | ✅ مفعّل |

</details>

## التقويم الرسمي

| | |
|---|---|
| **المسار** | `/platform → تبويب التقويم` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_settings.calendar.read` / `.manage` |
| **الطبقات** | ✅ مفعّل × 5 |

**الغرض** — يعرّف المالك أيام العمل والاستثناءات التي تُحسب عليها المواعيد.

**المخرج**

قائمة التقاويم بالنطاق، والمحدَّد يُعرض بجزأين: **أيام الأسبوع** (سبعة صفوف: يوم عمل؟ + وقت البداية/النهاية) و**الاستثناءات** (تقويم شهري بصري تُعلَّم فيه أيام الاستثناء). النشر بتأكيد.

**الإجراءات** — إنشاء · ضبط يوم أسبوع (`If-Match`) · ضبط استثناء (`If-Match`) · نشر (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-settings/calendars` | `listPlatformSettingsCalendars` | ✅ مفعّل |
| `POST` | `platform-settings/calendars` | `createPlatformSettingsCalendar` | ✅ مفعّل |
| `PUT` | `platform-settings/calendars/{calendarId}/exceptions/{date}` | `setPlatformSettingsCalendarException` | ✅ مفعّل |
| `POST` | `platform-settings/calendars/{calendarId}/publish` | `publishPlatformSettingsCalendar` | ✅ مفعّل |
| `PUT` | `platform-settings/calendars/{calendarId}/weekdays/{weekday}` | `setPlatformSettingsCalendarWeekday` | ✅ مفعّل |

</details>

## فحص الحالة

| | |
|---|---|
| **المسار** | `/platform → تبويب الصحة` |
| **النمط** | لوحة |
| **من يراها** | `platform_operations.health.read` |
| **الطبقات** | ✅ مفعّل × 1 |

**الغرض** — يتحقّق المشغّل من صحّة التبعيات (قاعدة البيانات، الذاكرة، التخزين، الفحص).

**المخرج**

بطاقة لكل تبعية: الاسم، الحالة، زمن الاستجابة. **لا يُعرض أي سرّ ولا سلسلة اتصال ولا مضيف** — هذا مُختبَر صراحةً في `platform-settings-live.spec.ts`.

**الإجراءات** — قراءة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/health` | `getPlatformHealth` | ✅ مفعّل |

</details>

## النسخ الاحتياطي

| | |
|---|---|
| **المسار** | `/platform → تبويب النسخ` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_operations.backup.read` / `.run` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يشغّل المشغّل نسخة احتياطية ويتابع سجلّها.

**المخرج**

قائمة النسخ: الوقت، الحجم، الحالة، المدة. زر «شغّل نسخة الآن» بتأكيد `AlertDialog`.

**الإجراءات** — قراءة · تشغيل (`Idempotency-Key`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/backups` | `getPlatformBackups` | ✅ مفعّل |
| `POST` | `platform-operations/backups` | `dispatchPlatformBackup` | ✅ مفعّل |

</details>

## طلبات الاستعادة

| | |
|---|---|
| **المسار** | `/platform → تبويب الاستعادة` |
| **النمط** | معالج |
| **من يراها** | `platform_operations.restore.*` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يطلب المشغّل استعادة من نسخة ويؤكّدها بخطوة ثانية.

**المخرج**

**أخطر إجراء في النظام.** معالج بخطوتين إلزاميتين: طلب (اختيار النسخة + سبب) ثم تأكيد منفصل. `AlertDialog` التأكيد يطلب **كتابة اسم النسخة يدوياً** لا مجرد نقر «موافق». تحذير صريح بأن البيانات الحالية ستُستبدل.

**الإجراءات** — طلب · تأكيد (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `platform-operations/restore-requests` | `requestPlatformRestore` | ✅ مفعّل |
| `POST` | `platform-operations/restore-requests/{requestId}/confirm` | `confirmPlatformRestore` | ✅ مفعّل |

</details>

## نوافذ الصيانة

| | |
|---|---|
| **المسار** | `/platform → تبويب الصيانة` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_operations.maintenance.manage` |
| **الطبقات** | ✅ مفعّل × 3 |

**الغرض** — يجدول المشغّل نافذة صيانة ويلغيها.

**المخرج**

قائمة النوافذ: من، إلى، السبب، الحالة. الجدولة في `Sheet`. النافذة الجارية تُعلَّم بأيقونة وتُثبَّت أعلى القائمة.

**الإجراءات** — قراءة · جدولة · إلغاء (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/maintenance-windows` | `listPlatformMaintenanceWindows` | ✅ مفعّل |
| `POST` | `platform-operations/maintenance-windows` | `schedulePlatformMaintenanceWindow` | ✅ مفعّل |
| `POST` | `platform-operations/maintenance-windows/{windowId}/cancel` | `cancelPlatformMaintenanceWindow` | ✅ مفعّل |

</details>

## السجلات التقنية

| | |
|---|---|
| **المسار** | `/platform → تبويب السجلات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_operations.logs.read` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يقرأ المشغّل سجلات النظام ويطلب استعادة المؤرشف منها.

**المخرج**

قائمة بفلتر الشدّة وبحث نصّي وترقيم بالمؤشّر، بخط `font-mono`.
**قد ترجع 503 مع `problem+json`** حين تكون السجلات مؤجّلة — تُعرض كـ`Alert` يشرح الحالة ويقدّم زر «اطلب استعادة»، لا كخطأ عام. هذا سلوك مُختبَر في `platform-settings-live.spec.ts:223`.

**الإجراءات** — قراءة · طلب استعادة

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/technical-logs` | `listPlatformTechnicalLogs` | ✅ مفعّل |
| `POST` | `platform-operations/technical-logs/restore` | `requestPlatformTechnicalLogsRestore` | ✅ مفعّل |

</details>

## سياسات التنبيهات

| | |
|---|---|
| **المسار** | `/platform → تبويب التنبيهات` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | `platform_operations.alerts.manage` |
| **الطبقات** | ✅ مفعّل × 2 |

**الغرض** — يضبط المشغّل عتبات التنبيه وقنواته.

**المخرج**

قائمة السياسات مع مفتاح `Switch` للتفعيل وتحرير العتبة في `Sheet`.

**الإجراءات** — قراءة · تحديث (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `platform-operations/alert-policies` | `listPlatformAlertPolicies` | ✅ مفعّل |
| `PATCH` | `platform-operations/alert-policies/{policyId}` | `updatePlatformAlertPolicy` | ✅ مفعّل |

</details>

# ز. حسابي والجلسة

ما يخصّ المستخدم نفسه. مفصول عن `/access` عمداً: الوصول الشخصي ليس تبويباً إدارياً.

## الدخول والجلسة

| | |
|---|---|
| **المسار** | `/login` |
| **النمط** | معالج |
| **من يراها** | عام |
| **الطبقات** | ✅ مفعّل × 5 |

**الغرض** — يدخل المستخدم ويخرج، وتُستعاد جلسته عند العودة.

**المخرج**

شاشة دخول مركزية على `bg-background`، بطاقة واحدة، عربية أولاً مع مبدّل لغة ظاهر. الأخطاء تحت الحقل المعني لا كتنبيه عام.
**استعادة الجلسة** تتم قبل رسم أي شيء — لا وميض شاشة دخول لمستخدم لديه جلسة صالحة.
**انتهاء الجلسة (401)** يُلتقط مركزياً في `http.ts` ويُسقط الشِّل كاملاً لشاشة الدخول مع `Toast` يشرح — لا يُترك المستخدم أمام شاشة فارغة.

**الإجراءات** — دخول · خروج · تحديث CSRF · قراءة الهوية

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `auth/login` | `login` | ✅ مفعّل |
| `POST` | `identity/csrf` | `refreshIdentityCsrf` | ✅ مفعّل |
| `POST` | `identity/login` | `identityLogin` | ✅ مفعّل |
| `POST` | `identity/logout` | `identityLogout` | ✅ مفعّل |
| `GET` | `identity/me` | `getCurrentIdentity` | ✅ مفعّل |

</details>

## أماني

| | |
|---|---|
| **المسار** | `/me → تبويب الأمان` |
| **النمط** | قائمة ← تفصيل |
| **من يراها** | المستخدم نفسه |
| **الطبقات** | ✅ مفعّل × 1 |

**الغرض** — يغيّر المستخدم كلمة مروره.

**المخرج**

نموذج واحد: الحالية، الجديدة، التأكيد. مقياس قوة كلمة المرور بـ`Progress` يستخدم `primary` وحده. النجاح يعرض `Toast` ولا يُخرج المستخدم.

**الإجراءات** — تغيير كلمة المرور

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `identity/password` | `changeIdentityPassword` | ✅ مفعّل |

</details>

## صلاحياتي ونطاقاتي

| | |
|---|---|
| **المسار** | `/me → تبويب الوصول` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | المستخدم نفسه |
| **الطبقات** | ✅ مفعّل × 3 |

**الغرض** — يرى المستخدم ما يستطيع فعله وأين، ويبدّل نطاق عمله.

**المخرج**

قسمان:
**نطاقاتي** — قائمة النطاقات المتاحة، الفعّال منها مُعلَّم، والتبديل بنقرة. **التبديل يُبطل ذاكرة الاستعلامات المرتبطة بالنطاق** عبر `scopeEpoch` — البيانات القديمة بعد التبديل خطأ عرض بيانات لا يملكها المستخدم.
**قدراتي** — قائمة القدرات مجمّعة بالوحدة، للقراءة فقط. تجيب سؤال «لماذا لا أرى هذه الصفحة؟» دون مراجعة مسؤول.

**الإجراءات** — قراءة السياق · قراءة النطاقات · تبديل النطاق (`If-Match`)

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `me` | `getCurrentPrincipal` | ✅ مفعّل |
| `PUT` | `me/scope` | `selectMyScope` | ✅ مفعّل |
| `GET` | `me/scopes` | `listMyScopes` | ✅ مفعّل |

</details>

# ح. مساحات مخطّطة

**API غير منفَّذ.** موثّقة لاكتمال الخارطة. لا تُبنى ولا تُسجَّل في الراوتر قبل نزول الـAPI، ولا تدخل بوابة التحقّق.

## حوكمة السجلات

| | |
|---|---|
| **المسار** | `/governance` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `records_governance.*` — **مخطط** |
| **الطبقات** | 📋 مخطط — API غير منفَّذ × 12 |

**الغرض** — يضبط المسؤول سياسات الاحتفاظ ويعلّق السجلات قانونياً ويقرّر التصرّف بها.

**المخرج**

أربعة تبويبات: سياسات الاحتفاظ (بإصدارات ونشر) · السجلات المحكومة · التعليقات القانونية · مراجعات التصرّف.
التصرّف عملية من خطوتين (قرار ثم تأكيد بمصدر خارجي) — معالج لا زر. الحذف النهائي يتطلّب تأكيداً بالكتابة.

**الإجراءات** — مخطط بالكامل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `records-governance/disposition-reviews` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/disposition-reviews` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/disposition-reviews/{reviewId}/confirm` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `records-governance/governed-records` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/governed-records` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `records-governance/governed-records/{governedRecordId}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `records-governance/holds` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/holds` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/holds/{holdId}/release` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `records-governance/retention-policy-versions` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/retention-policy-versions` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `records-governance/retention-policy-versions/{versionId}/publish` | — | 📋 مخطط — API غير منفَّذ |

</details>

## المخاطر

| | |
|---|---|
| **المسار** | `/risk` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `risk.*` — **مخطط** |
| **الطبقات** | 📋 مخطط — API غير منفَّذ × 9 |

**الغرض** — يسجّل المسؤول المخاطر ويقيسها ويتابع مراجعاتها.

**المخرج**

ثلاثة تبويبات: السجل (`DataTable`) · الخريطة الحرارية (شبكة احتمال×أثر بـ`chart-1..5`) · المراجعات المستحقّة (قائمة عمل). قراءات المؤشّرات كخط زمني في تفصيل الخطر.

**الإجراءات** — مخطط بالكامل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `risk/heatmap` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `risk/reviews/due` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `risk/risks/{riskId}` | — | 📋 مخطط — API غير منفَّذ |
| `PATCH` | `risk/risks/{riskId}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `risk/risks/{riskId}/indicator-readings` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `risk/risks/{riskId}/indicator-readings` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `risk/risks/{riskId}/{riskLifecycleAction}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `risk/{riskResource}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `risk/{riskResource}` | — | 📋 مخطط — API غير منفَّذ |

</details>

## الاستراتيجية

| | |
|---|---|
| **المسار** | `/strategy` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `strategy.*` — **مخطط** |
| **الطبقات** | 📋 مخطط — API غير منفَّذ × 7 |

**الغرض** — يتابع المسؤول المؤشّرات الاستراتيجية ويسجّل قياساتها.

**المخرج**

ثلاثة تبويبات: الموارد الاستراتيجية · القياسات المعلّقة (قائمة عمل) · بطاقة أداء المؤشّر (`recharts` بألوان `chart-*`).

**الإجراءات** — مخطط بالكامل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `strategy/indicators/{indicatorId}/scorecard` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `strategy/measurements/pending` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `strategy/{strategyResource}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `strategy/{strategyResource}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `strategy/{strategyResource}/{resourceId}` | — | 📋 مخطط — API غير منفَّذ |
| `PATCH` | `strategy/{strategyResource}/{resourceId}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `strategy/{strategyResource}/{resourceId}/{strategyAction}` | — | 📋 مخطط — API غير منفَّذ |

</details>

## المحفظة والمشاريع

| | |
|---|---|
| **المسار** | `/portfolio` |
| **النمط** | مساحة بتبويبات |
| **من يراها** | `portfolio.*` — **مخطط** |
| **الطبقات** | 📋 مخطط — API غير منفَّذ × 10 |

**الغرض** — يتابع المسؤول المشاريع ومعالمها وأثرها على المؤشّرات.

**المخرج**

تبويبات: المحفظة · المشاريع (`DataTable` + تفصيل) · المعالم (خط زمني) · روابط المؤشّرات · اللقطات الدورية.

**الإجراءات** — مخطط بالكامل

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `GET` | `portfolio/projects/{projectId}/indicator-links` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `portfolio/projects/{projectId}/indicator-links` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `portfolio/projects/{projectId}/milestones` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `portfolio/projects/{projectId}/milestones` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `portfolio/projects/{projectId}/{projectAction}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `portfolio/projects/{projectId}/{snapshotType}-snapshots` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `portfolio/{portfolioResource}` | — | 📋 مخطط — API غير منفَّذ |
| `POST` | `portfolio/{portfolioResource}` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `portfolio/{portfolioResource}/{resourceId}` | — | 📋 مخطط — API غير منفَّذ |
| `PATCH` | `portfolio/{portfolioResource}/{resourceId}` | — | 📋 مخطط — API غير منفَّذ |

</details>

# ط. خارج نطاق الواجهة

مُعلَنة صراحةً — **لا تُبنى لها شاشة**. الإعلان الصريح هو ما يمنع بوابة الاكتمال من الإنذار الكاذب، ويمنع الإسناد الصامت الذي أنتج فجوة الباك/الفرونت أصلاً.

## مسارات بلا واجهة

| | |
|---|---|
| **المسار** | `—` |
| **النمط** | — |
| **من يراها** | — |
| **الطبقات** | ✅ مفعّل × 2 · 📋 مخطط — API غير منفَّذ × 2 |

**الغرض** — مسارات خدمية لا يستهلكها متصفّح.

**المخرج**

`internal/documents/*` — عامل خلفي بين الخدمات، لا مستخدم.
`workspace` *(مخطط)* — تجميعة خادم استُبدلت بـ`/me` و`/me/scopes`.
`up` — فحص حيوية للبنية التحتية.

**الإجراءات** — لا شيء

<details><summary>الـEndpoints</summary>

| Method | Endpoint | Hook | الطبقة |
|---|---|---|---|
| `POST` | `internal/documents/versions/{versionId}/reconcile-promotion` | `reconcileDocumentPromotion` | ✅ مفعّل |
| `POST` | `internal/documents/versions/{versionId}/scan` | `scanDocumentVersion` | ✅ مفعّل |
| `GET` | `up` | — | 📋 مخطط — API غير منفَّذ |
| `GET` | `workspace` | — | 📋 مخطط — API غير منفَّذ |

</details>
