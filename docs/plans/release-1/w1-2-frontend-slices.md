---
doc_id: PLN-R1-W12-FE-001
title: عقد شرائح واجهة W1.2
type: plans
status: accepted
version: 1.1.0
date: 2026-07-17
owner: طارق
reviewers: []
classification: internal
review_cycle: مع كل تغيير في عقود W1.2
sources:
- docs/plans/release-1-platform.md
references:
- docs/adr/009-unified-react-shell.md
- docs/architecture/non-functional-requirements.md
- docs/contracts/README.md
- docs/contracts/api/openapi.yaml
- docs/contracts/api/w1-2.openapi.yaml
- docs/contracts/module-contracts.md
- docs/engineering/vertical-slices.md
---
# عقد شرائح واجهة W1.2

## الغرض وحالة العقد

يحدد هذا الملف عقد التنفيذ اللاحق لمسار `W: Admin Web` في موجة W1.2: تسجيل
المسارات، وملكية ملفات الواجهة، وsubset الـOpenAPI، وحدود الـmocks، واختبارات
العربية `RTL` والإنجليزية `LTR` والوصولية. لا يعد هذا الملف تنفيذاً للواجهة ولا
دليلاً على جاهزية W1.2.

اعتمد العقد مع حزمة W12-00 وقرار ملكية Organization/Identity. يبدأ تعديل `apps/web`
من snapshot المجمد فقط، ولا يستنتج مساراً أو schema من النصوص العامة.

## القرارات الثابتة

1. تبقى الإدارة داخل تطبيق React الموحد؛ لا ينشأ تطبيق أو shell مستقل للسوبر أدمن.
2. يسجل كل موديول مساراته بعقد واضح، بينما يملك Shell Integrator تجميع المسارات
   والتنقل والجلسة ومحدد النطاق.
3. تمثل كل Feature حالة استخدام واحدة، ولا تقرر صلاحية أو نطاقاً حساساً في React.
   الإخفاء أو التعطيل تحسين تجربة فقط، وقرار الخلفية هو المرجع.
4. لا يستورد كود Feature عمليات `fetch` المولدة مباشرة. يمر عبر adapter يحافظ على
   `X-Correlation-ID` و`Idempotency-Key` و`If-Match` وRFC 7807.
5. العربية هي الافتراضية، ولكل رحلة قبول نظير إنجليزي. تستخدم الواجهة توقيت
   `Asia/Riyadh` للعرض فقط، وتبقى قيم العقد UTC منتهية بـ`Z`.
6. لا يخفي mock نقص عقد. المسار الذي لا يملك عملية منشورة في subset المجمد يبقى
   `blocked` ولا ينشأ له endpoint افتراضي في الواجهة.

## النطاق

يشمل مسار الواجهة في W1.2:

- محدد نطاق تفاعلي يعرض النطاق الفعال الذي يعيده الخلف ولا يسمح بتوسيعه محلياً،
  بعد نشر عقد يميز النطاق الفعال من النطاقات المتاحة ويحدد عملية تغييره.
- صفحات إدارة التجمع والمنشآت والوحدات والمناصب والأشخاص والتكليفات.
- صفحات إدارة الحسابات المحلية وحالاتها بعد نشر عقد Identity.
- رحلة استيراد Organization من الرفع حتى التحقق والموافقة المزدوجة والتطبيق.
- رحلة Identity provisioning بعد نجاح استيراد Organization ونشر عقدها المستقل.
- حالات التحميل والفراغ والخطأ والتعارض وانتهاء الجلسة في كل Slice.

خارج النطاق:

- أي شاشة مستقلة عن shell الموحد أو أي قرار RBAC/ABAC داخل المتصفح.
- العلاقات الإشرافية؛ هي ضمن W1.3 حتى لو ظهر لها مسار في OpenAPI العام.
- إدارة سياسات Authorization أو Audit أو إعداداتها.
- تعديل generated client أو lockfiles أو ملفات shell المشتركة من كاتب Feature.
- اختبارات تعتمد على mock لإثبات العزل أو إنهاء الجلسات أو الموافقة المزدوجة.

## سجل المسارات

المسارات التالية هي الأسماء المحجوزة للتنفيذ. يملك Shell Integrator ملف التسجيل
الوحيد، وتملك كل Feature محتوى route الخاص بها. يتطلب الدخول المباشر وإعادة التحميل
وزري الرجوع والتقدم النتيجة نفسها التي يحققها التنقل داخل التطبيق.

تعني `محجوز` أن الاسم والمالك مثبتان دون سماح بالتنفيذ، وتعني `مرشح` أن العمليات
المطلوبة موجودة في العقد العام لكنها تنتظر snapshot W1.2، وتعني `مرشح جزئي` أن بعض
نتائج route فقط لها عمليات منشورة. تعني `blocked-contract` أن التنفيذ ممنوع حتى
إغلاق الفجوة المذكورة في هذا الملف.

| المسار | المالك | الحالة | نتيجة الأعمال |
|---|---|---|---|
| `/admin/organization` | Organization overview | محجوز | ملخص الهيكل وروابط الإدارة المسموحة من الخلف |
| `/admin/organization/cluster` | Organization cluster | `blocked-contract` | عرض التجمع الواحد وتعديله ومنع إنشاء تجمع ثانٍ |
| `/admin/organization/facilities` | Organization facilities | مرشح | قائمة المنشآت وإنشاؤها وتعديلها وأرشفتها |
| `/admin/organization/units` | Organization units | مرشح | شجرة متعددة الأعماق وإنشاء الوحدة ونقلها وتعديلها |
| `/admin/organization/positions` | Organization positions | مرشح جزئي | قائمة المناصب وإنشاؤها؛ التعديل ينتظر عقد detail |
| `/admin/organization/people` | Organization people | `blocked-contract` | يتطلب فصل schema إنشاء Person عن UserAccount أولاً |
| `/admin/organization/assignments` | Organization assignments | مرشح جزئي | عرض التكليفات وإنشاء الأساسي وإنهاؤه دون حذف السجل |
| `/admin/identity/accounts` | Identity accounts | `blocked-contract` | الحسابات وحالاتها والدخول الأول والقفل والتعطيل |
| `/admin/imports/organization` | Organization import | `blocked-contract` | ينتظر قراءة ImportJob وأخطاء الصفوف وعقد رفع مكتمل |
| `/admin/imports/identity` | Identity provisioning | `blocked-contract` | provisioning idempotent بعد نتيجة Organization المنشورة |

قواعد التسجيل والتنقل:

- المسار غير المعروف يعرض صفحة `404` موحدة ولا يعود صامتاً إلى الصفحة الرئيسية.
- يحتفظ تسجيل الدخول بالمسار المقصود بعد نجاح الجلسة، ما لم يمنعه الخلف.
- يحمي shell قسم `/admin` بحالة principal المنشورة، لكن كل طلب API يعاد تفويضه في
  الخلف؛ لا تعد حماية route قرار سماح.
- يقبل route parameter معرف UUIDv7 فقط. الإدخال غير الصالح لا يرسل طلب شبكة.
- يظل محدد النطاق في shell وليس route أعمال. لا ينفذ التغيير قبل أن ينشر الخلف
  النطاق الفعال والنطاقات المتاحة وعملية التغيير؛ وعندها لا يضيف المتصفح organization
  unit غير موجودة في العقد المنشور.

## ملكية الملفات في التنفيذ اللاحق

هذه الحدود تمنع تعارض تغييرات slices المختلفة في الملفات المشتركة. المسارات المذكورة
أهداف مستقبلية وليست ادعاء بأنها موجودة حالياً.

| المالك | الملفات الحصرية | ما لا يملكه |
|---|---|---|
| Shell Integrator | `apps/web/src/app/**` وملف route registry ومحدد النطاق والتنقل والجلسة | تفاصيل Features وgenerated API |
| Organization UI | `apps/web/src/features/organization/**` | Identity وimports وملفات shell |
| Identity UI | `apps/web/src/features/identity/**` | Person وOrganization persistence |
| Organization Import UI | `apps/web/src/features/imports/organization/**` | Identity provisioning ورفع Documents الداخلي |
| Identity Provisioning UI | `apps/web/src/features/imports/identity/**` | الكتابة المباشرة في Organization |
| Contract Maintainer | snapshot باسم `w1-2.openapi.yaml` و`apps/web/src/api/generated/**` وOrval/Redocly drift gate | صفحات الواجهة |
| Web API Integrator | `apps/web/src/api/w1-2/**` واختبارات transport المقابلة | generated files وقرارات المجال |
| Quality | `apps/web/e2e/w1-2-admin.spec.ts` وfixtures الاختبار المتفق عليها | ملفات التنفيذ |

تسبق مسؤولية Shell Integrator غيرها في تفكيك `apps/web/src/App.tsx` الحالي ودمج CSS
أو إعدادات الاختبار المشتركة. لا يخلط تغيير Feature تعديلات `App.tsx` أو `index.css`
أو `package.json` أو lockfile أو `orval.config.ts` أو ملفات الفهارس قبل اكتمال هذه
الخطوة المشتركة.

يسبق تفكيك `App.tsx` ونشر route registry وports العامة دمج أي Feature. تبقى كل
مسارات Feature في حالة توقف إذا لم تكتمل هذه الحزمة الأساسية أولاً؛ ولا يعدل تغيير
Feature الملف الأحادي مؤقتاً لتجاوز الترتيب.

قواعد الاعتماد داخل الواجهة:

- يجوز لـFeature استيراد API adapter وprimitives منشورة من shell/design system فقط.
- لا تستورد Feature ملفات داخلية من Feature أخرى. ينسق shell الانتقال بينها عبر
  route وIDs منشورة.
- لا يمرر shell payload أعمال كاملاً بين Features ولا يعيد تنفيذ قواعد المجال.
- تبقى copy والاختبارات الخاصة بالحالة داخل Feature، بينما يملك shell أسماء التنقل
  العامة وبيانات locale واتجاه الصفحة.

## subset الـOpenAPI

### العمليات المجمدة

يوجد snapshot باسم `w1-2.openapi.yaml` داخل مجلد عقود API على نمط W1.1، ويجمد الطرق
التالية فقط مع مكونات الأمن والأخطاء والـheaders التي تعتمد عليها.
الجدول allow-list حصرية؛ يفشل validator إذا دخل snapshot أي path أو method غير مذكور.

| المسار | الطرق | استخدام الواجهة |
|---|---|---|
| `/auth/login` | `POST` | إعادة استخدام دخول W1.1 داخل shell |
| `/auth/logout` | `POST` | إنهاء الجلسة من shell |
| `/me` | `GET` | access context الحالي |
| `/me/scopes`, `/me/scope` | `GET`, `PUT` | النطاقات المسموحة واختيار الفعال بلا توسعة صلاحية |
| `/documents` | `GET`, `POST` | metadata لملف الاستيراد المحجور |
| `/documents/uploads` | `POST` | بدء رفع bytes باستخدام `document_id` الموجود |
| `/documents/uploads/{uploadId}/complete` | `POST` | إكمال الرفع والتحقق من checksum |
| `/organization/cluster` | `GET`, `POST`, `PATCH` | جذر التجمع الوحيد |
| `/organization/facilities` | `GET`, `POST` | قائمة المنشآت وإنشاؤها |
| `/organization/facilities/{facilityId}` | `GET`, `PATCH` | عرض المنشأة وتعديلها أو أرشفتها |
| `/organization/units` | `GET`, `POST` | قائمة/شجرة الوحدات وإنشاؤها |
| `/organization/units/{unitId}` | `GET`, `PATCH` | عرض الوحدة وتعديلها أو نقلها |
| `/organization/positions` | `GET`, `POST` | قائمة المناصب وإنشاؤها |
| `/organization/positions/{positionId}` | `GET`, `PATCH` | عرض المنصب وتعديله |
| `/organization/people` | `GET`, `POST` | قائمة الأشخاص وإنشاء Person بلا حساب |
| `/organization/people/{personId}` | `GET`, `PATCH` | عرض Person وتحديثه تحت سياسة الحقول |
| `/organization/people/{personId}/reference` | `GET` | عقد تحقق محدود لـIdentity بلا PII مرجعية |
| `/organization/assignments` | `GET`, `POST` | قائمة التكليفات وإنشاؤها |
| `/organization/assignments/{assignmentId}/end` | `POST` | إنهاء التكليف دون حذف السجل |
| `/organization/import-jobs` | `POST` | إنشاء ImportJob من `quarantine_object_id` |
| `/organization/import-jobs/{jobId}` | `GET` | الحالة وملخص التحقق المنقح |
| `/organization/import-jobs/{jobId}/rows` | `GET` | أخطاء الصفوف المنقحة بلا payload خام |
| `/organization/import-jobs/{jobId}/{jobAction}` | `POST` | validate/approve/reject/apply/cancel حسب العقد |
| `/identity/accounts` | `GET`, `POST` | قائمة الحسابات وإنشاء pending بعد تحقق Person |
| `/identity/accounts/{accountId}` | `GET` | ملخص حساب بلا credential أو token |
| `/identity/accounts/{accountId}/{accountAction}` | `POST` | activate/unlock/disable/archive/revoke/force-change |
| `/authorization/bootstrap` | `GET`, `POST` | bootstrap مؤقت deny-by-default وإغلاقه مرة واحدة |

يضم snapshot مراجع `bearerAuth` و`CorrelationId` و`IdempotencyKey` و`IfMatch`
و`ETag` و`Problem` وpagination وUUIDv7 وUTC اللازمة لهذه العمليات، ولا ينسخ schemas
يدوياً. لا يضم `/organization/supervisory-relationships` أو مسارات W1.3 وما بعدها.

### ما يبقى خارج snapshot

تبقى إدارة الأنواع المحكومة وTemporaryAssignment التفصيلية خارج أول شريحة حتى تنشر
عملياتها في إصدار snapshot لاحق متوافق. `CommitteeMembership` خارج R1 وفق tombstone
`REQ-R1-W1.2-014`. لا يبتكر فريق الواجهة أسماء paths أو schemas، ولا يحول
`/organization/people` إلى API للحسابات.

### عقد العميل

- يولد Orval العميل من snapshot المجمد فقط، ويفشل drift gate إذا اختلف الناتج
  الملتزم عن إعادة توليد نظيفة.
- يملك Web API Integrator adapter واحداً لـW1.2. ينشئ correlation UUIDv7 لكل طلب،
  ويربط bearer token، ويولد idempotency key للأوامر القابلة للإعادة، ويرسل آخر
  `ETag` في `If-Match`.
- لا يبني العميل cursor ولا يفك ترميزه. يتبع `Link rel="next"` كما ينشره الخلف.
- يحافظ adapter على `application/problem+json` ويعرض رسالة آمنة محلية. لا يعرض
  stack أو SQL أو تفاصيل Authorization أو بيانات صف آخر.
- يعامل `401` كجلسة منتهية، و`403` كمنع، و`409` كتعارض أعمال، و`412` كنسخة قديمة
  تتطلب إعادة الجلب؛ لا يعاد إرسال mutation تلقائياً بقيمة stale.

## عقد الـmocks والـfixtures

لا توجد مكتبة MSW معتمدة حالياً، لذلك يعتمد W1.2 الاستراتيجية الأقل تغييراً:

1. تختبر طبقة transport بـVitest و`vi.stubGlobal('fetch', ...)` وبـ`Response` حقيقي،
   على نمط اختبارات W1.1.
2. تستقبل route components ports/adapters قابلة للاستبدال في الاختبار. تستخدم
   اختبارات العرض fixture builders typed من types المولدة، ولا تعترض الشبكة داخل
   مكونات الأعمال.
3. تحفظ fixtures الخاصة بالموجة في `apps/web/src/test/w1-2/**` ويملكها Quality؛ لا
   تعادّل `apps/web/src/test/setup.ts` المشترك إلا بواسطة مالكه.
4. تغطي fixtures النجاح والفراغ و`401/403/404/409/412/422`، وImportJob بكل انتقال
   مسموح ومرفوض، وبيانات عربية وإنجليزية طويلة، وشجرة بثلاثة أعماق على الأقل.
5. لا تستخدم mocks لإغلاق اختبارات العزل، أو تعطيل الحساب وإنهاء جلساته، أو منع نقل
   الوحدة إلى نسلها، أو فصل الرافع عن المعتمد. تثبت هذه الحالات عبر API حقيقي في E2E.

إذا اختير MSW لاحقاً فيجب أن يكون قراراً مستقلاً مع lockfile ومراجعة supply chain؛
لا يضاف ضمن Slice جانبية. يجب أن تولد handlers من snapshot المجمد أو تتحقق منه، وأن
يفشل الاختبار عند طلب path أو method غير موجودة بدلاً من إرجاع نجاح عام.

## حالات الواجهة الإلزامية

لكل route حالات `loading`, `empty`, `ready`, `validation-error`, `forbidden`,
`not-found`, `conflict`, `stale`, و`unexpected-error` بقدر ما يسمح به العقد. وتطبق
القواعد التالية:

- تعيد `403` و`404` نصاً آمناً لا يكشف وجود مورد خارج النطاق عندما يفرض عقد الخلف
  التماثل بينهما.
- تعرض `409` نقل الوحدة إلى نسلها برسالة مفهومة دون محاولة إصلاح محلي خفي.
- تعرض `412` مع إجراء واضح لإعادة تحميل النسخة، وتحافظ على إدخال المستخدم بقدر آمن.
- لا يظهر زر apply للاستيراد كبديل عن تحقق الخلف من اختلاف الرافع والمعتمد.
- يعرض تقدم ImportJob من قراءة حالته المنشورة، لا من مؤقت متفائل في المتصفح.
- يعرض الوقت بـ`Asia/Riyadh` مع عنصر `<time dateTime="...Z">`، ولا يغير القيمة
  المرسلة أو يخزن نسخة محلية بالتوقيت الإقليمي.

## عقد RTL/LTR

تشغل مصفوفة القبول نفسها مرتين: `ar-SA` مع `lang="ar" dir="rtl"`، و`en-GB` مع
`lang="en" dir="ltr"`. وتثبت الاختبارات:

- العربية هي first paint الافتراضي، واللغة المحفوظة تطبق قبل أول عرض ذي معنى.
- تغيير اللغة لا يغير route أو principal أو scope أو القيم المدخلة غير الحساسة.
- تستخدم styles الجديدة الخصائص المنطقية `inline/block` ولا تعتمد على `left/right`
  للمحاذاة أو الترتيب.
- تنعكس الأيقونات الاتجاهية فقط؛ لا تنعكس أيقونات المعنى أو الأرقام أو حالة العمل.
- تتعامل الجداول والشجرة والأسماء الطويلة مع الالتفاف والتمرير دون قص الأزرار.
- تعرض التواريخ والأوقات بـ`ar-SA` أو `en-GB` في `Asia/Riyadh` مع بقاء العقد UTC.
- تبقى الرحلات كاملة عند عرض سطح صغير وكبير، ولا يختفي إجراء لازم في أي اتجاه.

## عقد الوصولية

الهدف WCAG 2.2 AA للرحلات المحددة. يلزم في التنفيذ والاختبارات:

- `main` واحد و`h1` واحد لكل route، واسم وصولي محلي لكل `nav` وregion.
- labels ظاهرة مرتبطة بكل حقل، و`aria-required` و`aria-invalid` و`aria-describedby`
  للمساعدة والخطأ، مع نقل التركيز إلى ملخص الخطأ بعد الإرسال.
- تنقل كامل بلوحة المفاتيح، وترتيب تركيز يوافق الترتيب المرئي في RTL وLTR، وتركيز
  ظاهر، واستعادة التركيز بعد إغلاق dialog أو disclosure.
- استخدام قائمة متداخلة وأزرار disclosure للشجرة، أو تطبيق نمط ARIA tree كاملاً؛
  يمنع مزج أدوار tree جزئياً.
- `aria-live="polite"` لتغير حالة الاستيراد، و`role="alert"` للفشل الذي يتطلب
  إجراء، دون إعلان متكرر مع كل polling.
- أسماء نصية للحالات؛ لا يكون اللون أو موضع العنصر الوسيلة الوحيدة لنقل المعنى.
- حجم هدف لا يقل عن 44x44 CSS pixel للإجراءات، واحترام `prefers-reduced-motion`.
- إدارة كاملة لتركيز dialog، وEscape للإغلاق عندما يكون الإغلاق آمناً، ومنع تأكيد
  apply أو disable عبر dialog لا يحمل عنواناً ووصفاً واضحين.

تستخدم رحلة Playwright فحص `@axe-core/playwright` على كل حالة `ready` وكل حالة خطأ
مرئية وdialog رئيسي بعد اعتماد الاعتمادية في supply chain. غياب runner معتمد أو
وجود مخالفة `serious` أو `critical` stop condition؛ ولا تستبدل نتيجة axe اختبارات
لوحة المفاتيح والتركيز.

## مصفوفة اختبارات الواجهة

هذه المعرفات تخص عقد الواجهة وتربط لاحقاً بمعرفات W12-REQ النهائية دون إعادة
ترقيمها.

| الاختبار | المتطلبات/القبول | الدليل المطلوب |
|---|---|---|
| `FE-TEST-W1.2-001` | routes وshell الموحد | direct load وrefresh وback/forward و404 والمسار المقصود بعد الدخول |
| `FE-TEST-W1.2-002` | `REQ-R1-W1.2-001..005` | التجمع الواحد والمنشآت وشجرة الوحدات والمناصب؛ رفض نقل الوحدة إلى نسلها عبر `409` حقيقي |
| `FE-TEST-W1.2-003` | `REQ-R1-W1.2-006..014` | فصل Person عن UserAccount، الحسابات والتكليفات واللجان بعد نشر العقود الناقصة |
| `FE-TEST-W1.2-004` | `REQ-R1-W1.2-010` و`TEST-R1-W1.2-05` | تعطيل حساب ينهي جلساته؛ يثبت بجلستين وAPI حقيقي لا بـmock |
| `FE-TEST-W1.2-005` | `REQ-R1-W1.2-015..016` و`TEST-R1-W1.2-03..04` | ملف ناقص يفشل، والرافع A لا يعتمد، والمعتمد B يطبق، مع حالة وأخطاء صفوف مقروءة |
| `FE-TEST-W1.2-006` | `REQ-R1-W1.2-017` و`TEST-R1-W1.2-06` | UTC في payload و`Asia/Riyadh` في `<time>` بالعربية والإنجليزية |
| `FE-TEST-W1.2-007` | عقود HTTP | correlation وidempotency وETag/If-Match وProblem وcursor في اختبارات adapter |
| `FE-TEST-W1.2-008` | RTL/LTR | الرحلة نفسها لكل route في الاتجاهين وعلى سطح صغير وكبير |
| `FE-TEST-W1.2-009` | WCAG 2.2 AA | axe بلا serious/critical مع keyboard وfocus وlabels وlive regions |
| `FE-TEST-W1.2-010` | أمن النطاق | scope selector لا يوسع `/me`، و403/404 لا يسربان وجود موارد أو حقولاً مقيدة |

لا يغلق `FE-TEST-W1.2-003` جزئياً: تبقى نتيجة TemporaryAssignment blocked حتى تنشر
عملياته، ولا تدخل CommitteeMembership في R1. ولا يحول وجود mock ناجح أي بند إلى pass.

## بوابات التنفيذ والتسليم

### بوابة الدخول

- P0 مغلق بدليل revision واحد، وحزمة W12-00 معتمدة.
- قرار ملكية Person وImport مقبول، وسجل W12-REQ النهائي يربط REQ/TEST.
- snapshot W1.2 مجمد وله validator، والعميل المولد بلا drift.
- عقود session/lockout وAudit وPII وAuthorization bootstrap معتمدة قبل بيانات الموظفين.
- عقد النطاق يميز effective/available scope ويحدد عملية التغيير، أو يبقى محدد النطاق
  خارج أول دفعة ولا يدعي التفاعل.
- حزمة shell الأساسية تفكك `App.tsx` وتنشر route registry وports قبل Features.
- لكل route في أول دفعة عملية API منشورة أو mock مولد من snapshot؛ وإلا يبقى blocked.

### أوامر التحقق المستهدفة للتنفيذ اللاحق

```bash
python3 scripts/validate-w1-2-contracts.py
npm --prefix apps/web run api:check
npm --prefix apps/web run lint
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- e2e/w1-2-admin.spec.ts
```

اسم متغير W1.1 في الأمر الأخير يعكس إعداد Playwright الحالي فقط؛ يملك Shell
Integrator تحويله إلى اسم محايد أو W1.2 مع تحديث الاختبار والأمر في revision واحد.
لا تسجل receipt من mock كبديل عن رحلة API الحقيقية.

### بوابة الخروج

- كل route مسجل من ملف يملكه Shell Integrator، ولا توجد imports بين Features.
- subset والعميل المولد والadapter متطابقة، وتنجح حالات metadata والأخطاء والتعارض.
- تمر المصفوفة العربية والإنجليزية والوصولية، مع receipts مرتبطة بالrevision نفسه.
- تثبت اختبارات API الحقيقية العزل وتعطيل الجلسات ومنع النقل والموافقة المزدوجة.
- لا توجد بيانات حساسة أو tokens أو ملفات استيراد حقيقية في fixtures أو reports.
- ينجح build وunit وE2E المستهدف، ويحدث المنسق الفهارس وسجل الحالة بعد الدمج.

## شروط التوقف

يتوقف مسار الواجهة عند أي من الآتي:

- تغير snapshot المجمد أو فشل drift gate.
- احتياج Feature إلى path/schema غير منشورة أو إلى كتابة ملف يملكه مسار آخر.
- اعتماد UI على إخفاء عنصر لإثبات السماح أو على mock لإثبات invariant أمني.
- عدم إمكان التمييز الآمن بين `409` و`412` أو غياب حالة قراءة ImportJob.
- اختلاف route أو بيانات الأعمال بين RTL وLTR، أو فشل keyboard/axe.
- ظهور secret أو PII أو محتوى ملف استيراد حقيقي في artifact.

## rollback card

- **المحفز:** خطأ يمنع الدخول أو التنقل، تسريب نطاق/حقل، drift عقد، فشل رحلة RTL/LTR
  أو الوصولية الحرجة، أو mutation بلا idempotency/If-Match.
- **الإجراء:** إيقاف تسجيل routes W1.2 من ملف registry وإعادة نشر آخر bundle معروف
  بالصحة، مع إعادة snapshot والعميل المولد والadapter إلى النسخة المتوافقة معه إذا
  كان سبب الرجوع contract drift. لا تعدل البيانات ولا تستخدم rollback قاعدة بيانات
  من مسار الواجهة.
- **التوافق:** تبقى عمليات API المضافة متوافقة للخلف أثناء الرجوع، ولا تحذف schemas
  أو paths حتى يثبت عدم استهلاك bundle السابق لها.
- **RTO المستهدف:** إعادة bundle السابق خلال 30 دقيقة من قرار الرجوع.
- **التحقق:** دخول W1.1، والتنقل واللغة والعزل، وعدم ظهور روابط W1.2، ثم ربط receipt
  بالrevision المنشور.

## تحقق هذه البطاقة

هذه البطاقة تنشئ ملف التخطيط هذا فقط. أمر التحقق:

```bash
./scripts/validate-docs.sh
```

تضاف مراجع `docs/catalog.yaml` و`mkdocs.yml` بعد مراجعة W12-ADR وW12-REQ وW12-FE
حسب ترتيب التنفيذ، ولا يعالج فشل الفهرسة بتعديل خارج نطاق هذه البطاقة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-18 | طارق | اعتماد العقد بعد تجميد snapshot ومسارات Identity والنطاق والاستيراد |
| 1.0.0 | 2026-07-17 | طارق | تثبيت عقد routes/files وOpenAPI والـmocks واختبارات RTL/LTR والوصولية لـW1.2 |
