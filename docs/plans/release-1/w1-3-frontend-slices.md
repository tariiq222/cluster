---
doc_id: PLN-R1-W13-FE-001
title: عقد شرائح واجهة W1.3
type: plans
status: proposed
version: 1.0.0
date: 2026-07-18
owner: طارق
reviewers: []
classification: internal
review_cycle: مع كل تغيير في عقود W1.3
sources:
- docs/plans/release-1-platform.md
references:
- docs/adr/004-authorization-and-isolation.md
- docs/adr/009-unified-react-shell.md
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/contracts/api/openapi.yaml
- docs/data-security/authorization-model.md
- docs/engineering/vertical-slices.md
---
# عقد شرائح واجهة W1.3

## الغرض وحالة العقد

يحدد هذا الملف عقد التنفيذ اللاحق لمسار `W: Admin Web` في W1.3: أسماء المسارات،
وملكية الملفات، وsubset الـOpenAPI، وحدود الـmocks، واختبارات العربية `RTL`
والإنجليزية `LTR` والوصولية. لا يعد هذا الملف تنفيذاً للواجهة أو لمحرك الصلاحيات.

يبقى العقد `proposed` ولا يبدأ تعديل `apps/web` اعتماداً عليه حتى يغلق W1.2 وتغلق
بوابة W13-00. لا يبتكر فريق الواجهة path أو schema أو قرار صلاحية غير منشور.

## القرارات الثابتة

1. تبقى الإدارة داخل تطبيق React الموحد؛ لا ينشأ shell أو تطبيق منفصل للصلاحيات.
2. قرار `DecideAccess` خلفي؛ الإخفاء أو التعطيل في الواجهة تحسين تجربة لا سماح.
3. يمر كل طلب عبر adapter يحفظ `X-Correlation-ID` و`Idempotency-Key` و`If-Match`
   و`application/problem+json` حيث تنشرها العملية.
4. لا يفسر العميل رموز أسباب القرار إلى حقائق محمية، ولا يعرض شرحاً لمورد مخفي.
5. العربية هي الافتراضية، ولكل رحلة قبول نظير إنجليزي وعلى سطح صغير وكبير.
6. لا يثبت mock العزل أو صلاحية الحقل أو التفويض أو حدث التدقيق؛ تثبت عبر API حقيقي.

## النطاق

يشمل المسار اللاحق إدارة الأدوار والقدرات وتعييناتها، والعلاقات الإشرافية، والتفويضات،
وسياسات التصنيف وقوالب الحقول، ولوحة تفسير قرار الوصول للمخولين. يشمل حالات التحميل
والفراغ والمنع والتعارض والنسخة القديمة في كل route منشور.

خارج النطاق: اتخاذ RBAC أو ABAC داخل المتصفح، عرض سجل أو حقل أخفاه الخلف، إنشاء
تطبيق إداري مستقل، وتعديل generated client أو lockfile أو ملفات shell المشتركة من
كاتب Feature. لا تدخل شاشات WorkDefinitions أو Workflow أو WorkRecords في هذه الموجة.

## سجل المسارات

تعني `محجوز` أن الاسم والمالك ثابتان دون سماح بالتنفيذ، و`blocked-contract` أن التنفيذ
ممنوع حتى W13-00 ونشر العملية. يتطلب الدخول المباشر وإعادة التحميل وزرا الرجوع والتقدم
النتيجة نفسها التي يحققها التنقل داخل التطبيق.

| المسار | المالك | الحالة | نتيجة الأعمال |
|---|---|---|---|
| `/admin/authorization/roles` | Authorization roles | محجوز | قائمة الأدوار وارتباطها بالقدرات المنشورة |
| `/admin/authorization/capabilities` | Authorization capabilities | محجوز | دليل قدرات ثابت قابل للتدقيق |
| `/admin/authorization/role-assignments` | Authorization assignments | `blocked-contract` | تعيين دور بنطاق ونافذة زمنية |
| `/admin/authorization/delegations` | Authorization delegations | `blocked-contract` | تفويض محدود المصدر والموديول والمدة والنطاق |
| `/admin/authorization/classification-policies` | Authorization classification | `blocked-contract` | سياسات التصنيف المنشورة |
| `/admin/authorization/field-access-templates` | Authorization field policies | `blocked-contract` | قوالب صلاحيات الحقول المنشورة |
| `/admin/relationships/supervisory` | Organization relationships | `blocked-contract` | عرض حقائق العلاقة وقدراتها دون قرار محلي |
| `/admin/authorization/explain` | Authorization explanation | `blocked-contract` | تفسير آمن لقرار مورد مخول فقط |

قواعد التسجيل والتنقل:

- يعرض route غير المعروف صفحة `404` موحدة ولا يعود صامتاً إلى الصفحة الرئيسية.
- يحتفظ تسجيل الدخول بالمسار المقصود بعد نجاح الجلسة ما لم يمنعه الخلف.
- يحمي shell `/admin` بحالة principal المنشورة، لكن كل طلب API يعاد تفويضه في الخلف.
- يقبل route parameter معرف UUIDv7 فقط؛ الإدخال غير الصالح لا يرسل طلب شبكة.
- لا يعرض `403` أو `404` وجود مورد أو اسم حقل أو سبب قرار محمي عندما يفرض الخلف تماثلهما.

## ملكية الملفات في التنفيذ اللاحق

هذه أهداف مستقبلية حصرية لتجنب تعارض الشرائح؛ لا تدعي أنها موجودة حالياً.

| المالك | الملفات الحصرية | ما لا يملكه |
|---|---|---|
| Shell Integrator | `apps/web/src/app/**` وroute registry والتنقل والجلسة | تفاصيل Features وgenerated API |
| Authorization UI | `apps/web/src/features/authorization/**` | حقائق Organization وملفات shell |
| Organization Relationships UI | `apps/web/src/features/organization-relationships/**` | قرار Authorization أو persistence |
| Contract Maintainer | snapshot `w1-3.openapi.yaml` و`apps/web/src/api/generated/**` | صفحات الواجهة |
| Web API Integrator | `apps/web/src/api/w1-3/**` واختبارات transport | generated files وقرارات المجال |
| Quality | `apps/web/e2e/w1-3-authorization.spec.ts` وfixtures الموجة | ملفات التنفيذ |

لا تستورد Feature ملفات داخلية من Feature أخرى. يجوز لها استيراد adapter وprimitives
منشورة من shell فقط، ولا يمرر shell payload أعمال كاملاً بين Features.

## subset الـOpenAPI

ينشئ Contract Maintainer snapshot باسم `w1-3.openapi.yaml` بعد W13-00. allow-list
المستهدف حصري؛ وجود عملية في `openapi.yaml` العام يجعلها مرشحة ولا يعني اعتمادها.

| مجموعة المسارات | الاستخدام | الحالة قبل التجميد |
|---|---|---|
| `/auth/login`, `/auth/logout`, `/me` | الجلسة وسياق principal في shell | يعاد استخدام العقد المنشور |
| `/authorization/{adminResource}` للقيم `roles`, `capabilities`, `role-assignments`, `delegations`, `classification-policies`, `field-access-templates` | إدارة موارد Authorization | مرشح في العقد العام؛ يجمد snapshot القيم والطرق والخطط المسموحة |
| `/organization/supervisory-relationships` | حقائق العلاقات وقدراتها | مرشح في العقد العام؛ يثبت W13-00 ملكية Organization وحدود الاستهلاك |
| `/authorization/access-decisions` | طلب قرار وصول | مرشح في العقد العام؛ يحدد W13-00 من يحق له استدعاؤه |
| `/authorization/access-decisions/{decisionId}/explanation` | تفسير قرار مسجل للمخول | مرشح في العقد العام؛ يجمد W13-00 الإخفاء ورموز الأسباب |

تمنع التنفيذ الفجوات الآتية: عقد AccessContext وحقائق السجل، دلالة OrgUnit الفارغ
والتوريث، ملكية العلاقة الإشرافية، رموز أسباب القرار المسموح عرضها، mapping قيم
`FieldDecision` المنشورة إلى نصوص الواجهة، وschema حدث الوصول الحساس. يحدد Contract
Maintainer لكل فجوة المالك والعملية والـschema وأخطاء `401/403/404/409/412/422` قبل رفع
route من `blocked-contract`.

طلبات القرار والتفسير قراءات قرار وليست mutations؛ لا تفرض عليها الواجهة
`Idempotency-Key` أو `If-Match` ما لم ينشر snapshot W1.3 ذلك صراحةً. يبقى
`X-Correlation-ID` إلزامياً حيث ينص العقد العام عليه.

## عقد الـmocks والـfixtures

تعتمد الاختبارات الاستراتيجية الحالية: `vi.stubGlobal('fetch', ...)` و`Response`
حقيقي لاختبارات transport، وports قابلة للاستبدال لمكونات routes، وfixture builders
typed في `apps/web/src/test/w1-3/**`. تغطي fixtures النجاح والفراغ و`401/403/404/409/412/422`
وبيانات عربية وإنجليزية طويلة؛ لا تحتوي أسراراً أو بيانات صحية أو أسماء أشخاص حقيقيين.

لا تستخدم mock لإثبات منع نطاق منشأة، أو إزالة حقل، أو انتهاء تفويض، أو ظهور الاسمين
في التدقيق. هذه سيناريوهات API وE2E حقيقية بعد نشر العقود.

## حالات الواجهة الإلزامية

لكل route حالات `loading`, `empty`, `ready`, `validation-error`, `forbidden`,
`not-found`, `conflict`, `stale`, و`unexpected-error` بقدر ما يسمح به العقد.

- لا يظهر الحقل الذي يقرر الخلف إخفاءه في DOM أو state أو payload مرسل من المتصفح.
- يوضح الحقل الذي يقرره الخلف للقراءة فقط أنه غير قابل للتحرير ولا يرسل mutation عند تعطيله.
- تعرض `412` بإجراء آمن لإعادة تحميل النسخة ولا تعيد mutation تلقائياً.
- تعرض لوحة التفسير رموزاً آمنة ونسخاً منشورة فقط، ولا تصبح وسيلة لاكتشاف مورد مخفي.
- يعامل `401` كجلسة منتهية، و`403` كمنع، و`409` كتعارض أعمال، و`412` كنسخة قديمة.

## عقد RTL/LTR والوصولية

تشغل مصفوفة القبول مرتين: `ar-SA` مع `lang="ar" dir="rtl"`، و`en-GB` مع
`lang="en" dir="ltr"`. تستخدم styles الخصائص المنطقية ولا تعتمد على `left/right`.
لا تنعكس إلا الأيقونات الاتجاهية، وتتعامل الجداول والأشجار والأسماء الطويلة مع الالتفاف
والتمرير دون قص إجراء لازم.

الهدف WCAG 2.2 AA: `main` و`h1` واحدان لكل route، labels ظاهرة مرتبطة بالحقول،
تركيز ظاهر، تنقل كامل بلوحة المفاتيح، إدارة تركيز dialog، `aria-live="polite"`
للحالات غير الحرجة و`role="alert"` للفشل، وأهداف إجراءات لا تقل عن 44x44 CSS pixel.
يفحص Playwright مع `@axe-core/playwright` كل حالة `ready` وحالة خطأ ظاهرة وdialog
رئيسي؛ مخالفة `serious` أو `critical` شرط توقف.

## مصفوفة اختبارات الواجهة

| الاختبار | المتطلبات/القبول | الدليل المطلوب |
|---|---|---|
| `FE-TEST-W1.3-001` | routes وshell | direct load وrefresh وback/forward و404 والمسار المقصود بعد الدخول |
| `FE-TEST-W1.3-002` | `REQ-R1-W1.3-001..002` | إدارة Role وCapability وتعيين بنطاق ونافذة منشورين |
| `FE-TEST-W1.3-003` | `REQ-R1-W1.3-003..005` | أنواع العلاقة وقدراتها وانتهاء أثرها عبر API حقيقي |
| `FE-TEST-W1.3-004` | `REQ-R1-W1.3-006..007` | تفويض محدود وظهور الأصيل والفاعل في الأثر |
| `FE-TEST-W1.3-005` | `REQ-R1-W1.3-008..010,012` | تفسير رموز آمنة وقرار Laravel لا إخفاء UI |
| `FE-TEST-W1.3-006` | `REQ-R1-W1.3-009,011,013` | منع صريح وتصنيف وحقول وإثبات حدث وصول حساس |
| `FE-TEST-W1.3-007` | RTL/LTR والوصولية | الرحلة ذاتها على السطحين مع axe ولوحة المفاتيح والتركيز |

## بوابات التنفيذ والتسليم

### بوابة الدخول

- W1.2 مغلق و`make verify-w1-2` أخضر، وبوابة W13-00 مغلقة على revision واحد.
- snapshot W1.3 مجمد وله `scripts/validate-w1-3-openapi.py`، والعميل المولد بلا drift.
- لكل route عملية API منشورة أو fixture مولد من snapshot؛ وإلا يبقى blocked.
- تحدد العقود كيف تعرض الواجهة `403` و`404` وسبب القرار دون تسريب مورد أو حقل.
- تكون حزمة shell الأساسية وroute registry وports منشورة قبل أي Feature.

### أوامر التحقق المستهدفة للتنفيذ اللاحق

```bash
./scripts/validate-docs.sh
make verify-boundaries
make verify-w1-3
npm --prefix apps/web run api:check
npm --prefix apps/web run lint
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
```

### بوابة الخروج

- كل route مسجل من ملف يملكه Shell Integrator ولا توجد imports بين Features.
- تتطابق subset والعميل المولد والadapter، وتنجح حالات metadata والأخطاء والتعارض.
- تمر العربية والإنجليزية والوصولية مع receipts على revision واحد.
- تثبت اختبارات API الحقيقية العزل وصلاحيات الحقول وانتهاء العلاقة والتفويض والتدقيق.
- لا توجد بيانات حساسة أو tokens أو سجلات حقيقية في fixtures أو reports.

## شروط التوقف والرجوع

يتوقف المسار عند تغير snapshot، أو الحاجة إلى path/schema غير منشورة، أو اعتماد UI أو
mock لإثبات صلاحية، أو اختلاف `403` و`404` بما يسرب مورداً، أو فشل RTL/LTR أو keyboard
أو axe، أو ظهور secret أو PII. عند الرجوع يوقف تسجيل routes W1.3 ويعاد آخر bundle وعقد
متوافقان؛ لا تعدل البيانات ولا يستخدم rollback قاعدة بيانات من الواجهة.

## تحقق هذه البطاقة

هذه البطاقة تنشئ التخطيط فقط. أمر التحقق:

```bash
./scripts/validate-docs.sh
```

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-18 | طارق | تثبيت عقد routes/files وOpenAPI والـmocks واختبارات RTL/LTR والوصولية لـW1.3 |
