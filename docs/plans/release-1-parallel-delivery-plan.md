---
doc_id: PLN-R1-PAR-001
title: خطة التسليم المتوازي لإصدار R1
type: plans
status: proposed
version: 1.0.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- مسؤول المنتج
- قائد التقنية
- مسؤول العمليات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
- docs/plans/active-delivery-status.md
references:
- docs/engineering/vertical-slices.md
- docs/architecture/dependency-rules.md
- docs/plans/readiness-checklist.md
---
# خطة التسليم المتوازي لإصدار R1

## الغرض

تحويل موجات R1 المتبقية إلى مسارات عمل قصيرة قابلة للتوزيع على وكلاء وفرق متعددة، مع
الحفاظ على قاعدة موجة رئيسية واحدة في كل وقت. لا تستبدل هذه الوثيقة بوابات القبول في
`release-1-platform.md`؛ بل تحدد ترتيب التنفيذ والتوازي الآمن بينها.

## نقطة البداية

- `main` المحلي عند revision `c107004e4e5746bde3e57172b94cb9d49fad0081`.
- بوابات W1.1 المحلية ناجحة، بينما CI والمضيف والشبكة وDokploy والاستعادة الحية محجوبة
  بمدخلات البنية الداخلية وصلاحية GitHub `workflow`.
- لا يبدأ كود W1.2 قبل إغلاق W1.1 أو قرار راعٍ مسجل. يسمح الآن فقط بتحضير المتطلبات
  ومسودات العقود وقرارات المنتج ومصفوفة الاختبارات التي لا تدعي بدء الموجة.

## قواعد التنفيذ

1. تبقى الموجات الرئيسية متسلسلة: W1.1 ثم W1.2 حتى W1.10.
2. يبدأ التوازي التنفيذي داخل الموجة بعد قرار بدء مسجل وتثبيت العقود واختبارات القبول
   الحمراء. قبل القرار لا يعدل تحضير الموجة التالية ملفات `apps/` أو الاختبارات التنفيذية.
3. يملك كل مسار ملفات أو موديولات غير متداخلة، ولا يعدل وكيلان الملف نفسه بالتوازي.
4. لا توجد استعلامات أو joins مباشرة بين جداول موديولات الأعمال؛ التكامل بعقود وأحداث
   ومعرفات وread models محكومة.
5. يطبق قرار RBAC + ABAC نفسه على API والبحث والتقارير والتصدير والتنزيل.
6. يحفظ تغيير الأعمال وOutbox event في معاملة واحدة. كل تأثير غير متزامن at-least-once
   يستخدم Inbox/checkpoint ذرياً ويكون المستهلك idempotent.
7. كل حزمة دمج تمثل سلوكاً واحداً قابلاً للشرح والرجوع، وتغلق باختبار وأثر في سجل الحالة.

## مخطط التنفيذ

المراحل التالية ليست PRs منفردة. تقسم كل مرحلة قبل بدئها إلى حزم دمج رأسية صغيرة وفق
قاعدة السلوك الواحد أعلاه.

| المرحلة | التبعية وترتيب المسارات | بوابة الخروج |
|---|---|---|
| P0 إغلاق W1.1 الحي | A وB بالتوازي، ثم C1 وC2 بالتوازي، ثم D | `make verify-w1-1-all` وحزمة Go مسماة |
| P1 تهيئة W1.2 | تخطيط فقط حتى إغلاق P0 | سجل متطلبات وقرارات ومسودات عقود ورحلات، ثم قرار بدء |
| P2 تنفيذ W1.2 | W12-00 بعد P0 وP1؛ Authorization bootstrap ثم Audit/Privacy؛ Organization وIdentity؛ imports المملوكة؛ API؛ Web والترحيل؛ Quality | بوابة W1.2 المعتمدة |
| P3 تنفيذ W1.3 | حقائق Organization؛ كاتب Backend واحد لـAuthorization؛ field/explain UI؛ security tests | المراحل العشر fail-closed وسيناريوهات العزل |
| P4 تنفيذ W1.4 | W1.3 وعقد Workflow أدنى أو قرار اعتماد محدث؛ version/schema core ثم signing/import وsandbox وbuilder ثم E2E | إصدار منشور immutable و`work_type_version_id` مثبت |
| P5 تنفيذ W1.5 | schema/engine وresolver ثم timers/outbox ثم editor وfailure E2E | `workflow_version_id` immutable وسيناريوهات المسار |
| P6 تنفيذ W1.6 ثم W1.7 | W1.4 وW1.5، ثم بوابة W1.6 قبل W1.7؛ WorkRecords وWorkflow بعقودهما؛ RecordsGovernance policy/hold/disposal؛ ثم Tasks وCollaboration بعقودهما | تثبيت الإصدارين ورحلات الطلب ثم بوابة Tasks وحوكمة سجلات قابلة للتنفيذ |
| P7 تنفيذ W1.8 | W1.7 وRecordsGovernance؛ storage/quarantine/scanner ثم Documents وNotifications ثم UI وE2E | تنزيل يعاد تفويضه وإشعار موثوق بلا تسريب |
| P8 تنفيذ W1.9 | source feeds ثم Search وReporting projections مستقلة ثم dashboards وSLO/security E2E | إخفاء title/snippet/count/fields وإعادة بناء مستقلة |
| P9 UAT وإطلاق R1 | W1.9 والاستعدادات الخارجية المبكرة؛ مسارات UAT والأمن والأداء والتعافي والتدريب والقانوني بعد تجميد المرشح | عقد Go حتمي واعتمادات مسماة |

## المسار الفوري P0

| المسار | المسؤول | المعتمد | المدخل الخارجي | الناتج |
|---|---|---|---|---|
| A: GitHub وCI | مسؤول العمليات | قائد التقنية | OAuth `workflow`، سبعة image digests، runners، Environments، variables/secrets وسجل registry | revision منشور وCI ناجح وdescriptor وSBOM/provenance موقعة |
| B: المضيف والشبكة | قائد SRE | مسؤول أمن المعلومات | `HOST_INPUTS` و`HOST_RECEIPT` وNET-04 policy حية وreceipts المضيف والمستخدم والإدارة وrevision مطابق | receipts موقعة على revision نفسه |
| C1: Dokploy | قائد SRE | مسؤول العمليات | artifact موقع وDokploy token ومدخلات المضيف والشبكة المقبولة | دليل N إلى N+1 ثم rollback |
| C2: النسخ والاستعادة | مسؤول قاعدة البيانات | مسؤول العمليات | backup target ومفاتيح التشفير وrestore target منفصل | manifest وrestore receipt وRPO/RTO |
| D: البوابة | مكتب هندسة المنصة | مدير التجمع للتحول الرقمي | manifest وtrust policy وجذور الإصدار والأدلة وcosign مثبت و`GATE_AS_OF` ومراجعات قائد التقنية وقائد SRE ومسؤول أمن المعلومات | GATE-07 وقرار Go/No-Go |

ينفذ A وB بالتوازي، ثم C1 وC2 بعد artifact موقع وقبول المضيف والشبكة، ثم D بعد اكتمال
الأدلة كلها. يبقى المفتاح الخاص محصوراً في `release-signing`، ولا يصل إلى artifact أو
verification jobs. تحفظ الأسرار والreceipts والمفاتيح خارج Git، وتبقى القائمة التنفيذية
الكاملة في `Makefile` وحزمة `w1-1-remaining-delivery-tasks.md`.

## أول موجة منتج P1 وP2

### التحضير المسموح قبل إغلاق P0

1. إنشاء سجل متطلبات W1.2 بمعرفات `REQ-*` و`TEST-*` مستقرة.
2. حسم عدد invariants المتعارض بين خطة R1 ووثيقة Organization قبل كتابة التنفيذ.
3. صياغة مسودات Organization وIdentity وImport وsession وscope selector وقرارات
   UTC/Asia-Riyadh، دون اختبارات تنفيذية أو تعديل `apps/`.
4. حسم التعارض الحالي في الوثائق المقبولة حول Person وFKs واتجاه Organization/Identity
   بقرار ADR وتحديث domain/context-map قبل W12-00. الاتجاه المستهدف: Organization يملك
   Person وPosition وAssignment والعلاقات، وIdentity يملك الحساب والاعتماد والجلسة
   ويحتفظ بـ`person_id` فقط عبر عقد منشور، دون FK عابر للموديولات.
5. تقسيم الاستيراد حسب مالك البيانات؛ لا توجد معاملة apply واحدة تكتب جداول موديولين.

### W12-00 بعد إغلاق P0

1. اعتماد العقود وتجميد subset W1.2 من OpenAPI وعقود الأخطاء والأحداث.
2. إضافة اختبارات القبول الحمراء وحدود تمنع اعتماد Organization على Identity.
3. تثبيت bootstrap إداري محدود زمنياً وfail-closed حتى يكتمل Authorization في W1.3.
4. تثبيت عقود Audit append-only وPII encryption وpassword/session/lockout قبل تخزين
   بيانات الموظفين أو فتح واجهات الإدارة.
5. إنشاء سجل متطلبات وملف بوابة W1.2 تحت مجلد الإصدار؛ يحددان أوامر التحقق والreceipts
   والapprover وfreshness، مع إضافة target باسم `verify-w1-2` قبل dispatch.

### مسارات التنفيذ بعد W12-00

| المسار | ملكية الملفات | نطاق العمل | يعتمد على |
|---|---|---|---|
| A0: Authorization bootstrap | `apps/api/Modules/Authorization/**` بنطاق W1.2 فقط | deny-by-default وbootstrap admin محدود ومؤقت وعقد audit facts، دون ادعاء اكتمال W1.3 | W12-00 |
| S: Security/Audit bootstrap | `apps/api/Modules/Audit/**` وعقود أمن W1.2 المحددة | audit append-only وPII encryption وsession policy | A0 |
| O: Organization | `apps/api/Modules/Organization/**` ومهاجراته فقط | الشجرة وPerson والمناصب والتكليفات واللجان والحقائق الإشرافية | W12-00 وA0 وS |
| I: Identity | `apps/api/Modules/Identity/**` ومهاجراته فقط | الحسابات والاعتمادات والجلسات والتعطيل والإنهاء وربط `person_id` | W12-00 وA0 وS وعقد Person |
| MO: Organization Import | slices ومهاجرات Organization فقط | validate ثم dual approval ثم apply لبيانات Organization | O |
| MI: Identity Provisioning | slices ومهاجرات Identity فقط | provisioning idempotent للحسابات عبر IDs وعقد مستقل | I ونتيجة MO المنشورة |
| W: Admin Web | مسارات admin وshell محددة في `apps/web/src/**` | route registry وscope selector وإدارة الهيكل والحسابات والاستيراد RTL/LTR | OpenAPI ثابت أو mocks مولدة |
| Q: Quality | اختبارات العقود وE2E دون امتلاك ملفات التنفيذ | invariants والعزل والجلسات والاستيراد ورحلتا RTL/LTR | O وI وMO وMI وW |

## خط الأنابيب الثابت لكل موجة لاحقة

1. قرار المنتج والعقود ومصفوفة `REQ-*` إلى `TEST-*`.
2. migrations واختبارات invariants وحدود الموديولات.
3. شرائح Backend رأسية بعقود وأحداث بدلاً من الوصول إلى جداول موديول آخر.
4. OpenAPI وعميل Orval وواجهة React موحدة RTL/LTR.
5. workers وprojections مع Outbox/Inbox وidempotency إلزامياً لكل أثر غير متزامن.
6. اختبارات Unit ثم Integration ثم E2E ثم بوابة الأمن والتشغيل المتأثرة.
7. تحديث `active-delivery-status.md` بدليل revision وCI والبيئة المستهدفة.

لكل حزمة rollback card تشمل التطبيق وschema والأحداث والworkers والprojections وfeature
exposure ومحفز الرجوع وRTO ودليل التحقق. تتبع migrations تسلسل expand ثم migrate ثم
verify ثم contract، ولا تعتمد استعادة الإنتاج على down migration هدّامة.

## مسارات مستمرة عبر الموجات

| المسار | المسؤولية | شرط الدمج |
|---|---|---|
| Security | threat model وRBAC/ABAC وfield policy والاعتماديات والأسرار | اختبارات deny وfacility isolation وعدم تسريب الحقول |
| Audit/Privacy | audit append-only وhash chain وsensitive access وPII purpose/retention | فشل audit يمنع تسليم المحتوى الحساس ولا تظهر PII في logs |
| Platform | CI images وDokploy وbackup/restore وobservability | دليل حي مرتبط بالـrevision، لا فحص محلي فقط |
| Contracts | OpenAPI وAsyncAPI وschemas وmodule contracts | validators والعميل المولد بلا drift |
| UX | React shell وRTL/LTR والوصولية ورحلات الشخصيات | build وcomponent/E2E tests بالعربية والإنجليزية |
| Data Governance | مراجعة تسلسل migrations وbackfills وretention وrebuild فقط؛ لا يملك جداول الموديولات | rollback/rebuild واختبارات اتساق وملكية واحدة لكل migration |
| Governance/Change | سجل القرارات وCCB وADR والتتبع ومراجعة أسبوعية | DRI ومعتمد وREQ/TEST وأثر قرار لكل تغيير |

كل موديول يملك migrations وInbox/checkpoints وprojection tables الخاصة به. Search وReporting
يعيدان البناء باستقلال من events أو Projection Feeds، ولا يكتب مسار Data Governance
في جداول أي موديول.

## نموذج تشغيل الوكلاء

- سبعة مسارات كحد تنظيمي: خمسة للموجة النشطة ومساران لتحضير العقود والرحلات التالية.
- حد العمل الجاري: حزمتا implementation فقط، وحزمتا تحضير docs/contracts فقط.
- merge queue تسلسلية إلى `main`؛ لا batch merge ولا وكيلان على ملف مشترك.
- يملك Contract Maintainer ملفات OpenAPI وAsyncAPI والعميل المولد، ويملك Shell Integrator
  ملفات shell المشتركة. تتوقف المسارات التابعة عند تغير عقد مجمد.
- يحتوي handoff على work item وREQ/TEST والملفات المملوكة وإصدارات العقود والأوامر
  والأدلة والrevision والمخاطر والrollback والمالك التالي.
- توقف الحزمة فور ظهور cross-module join أو revision mismatch أو secret في artifact أو
  فشل بوابة أو حاجة مسارين إلى الملف نفسه.

## قرارات يجب إغلاقها مبكراً

| القرار | المسؤول | المعتمد | موعد الحسم |
|---|---|---|---|
| عدد invariants في W1.2 وسيناريوهات W1.3 وWorkflow | مسؤول المنتج | قائد التقنية | قبل W12-00 |
| ملكية Person وImport وTasks/Collaboration | مجلس معمارية المنصة | قائد التقنية | قبل عقد الموجة المتأثرة |
| مواءمة HA/HPA مع ADR-023 | مسؤول العمليات | مجلس معمارية المنصة | قبل تصميم اختبارات W1.10 |
| عقد Go: البنود الإلزامية والwaivers وN/A والتوقيعات | مدير التجمع للتحول الرقمي | لجنة Go/No-Go | قبل اعتماد P1 |
| monitoring stack | مسؤول العمليات | قائد التقنية | قبل W1.5 |
| retention وlegal hold | مسؤول أمن المعلومات | قائد التقنية | قبل أي بيانات حية |
| backup store | مسؤول العمليات | قائد التقنية | قبل أي بيانات حية |
| object storage وKMS | مسؤول العمليات | قائد التقنية | قبل بدء W1.8، ويبدأ التوريد من P1 |
| malware scanner | مسؤول أمن المعلومات | قائد التقنية | قبل بدء W1.8، ويبدأ التوريد من P1 |

## التحقق والتسليم

تبدأ كل حزمة بأضيق اختبار متأثر. عند بوابة الدمج تستخدم الأوامر المناسبة من:

```bash
./scripts/validate-docs.sh
make verify-boundaries
make test-api
make test-web
make verify-ci-config
make verify-w1-1-local
```

لا تشغل `make verify-w1-1-all` إلا بمدخلات حية كاملة. لا تسجل receipt أو artifact أو
موافقة على أنها مكتملة ما لم ترتبط بـGit revision منشور واحد.

تنتج كل موجة مصفوفة `REQ -> TEST -> command -> receipt`، وتضم boundary report وAPI
contract pack وVitest coverage وPlaywright report وأدلة fail-closed المتأثرة. تتطلب W1.10
أدلة المضيف وDokploy والنسخ والاستعادة وSBOM/provenance والdescriptor الموقع واعتمادات Go.

لا تعد هذه الخطة target تنفيذي للموجات المستقبلية. قبل dispatch أي موجة يجب أن توجد حزمة
محكومة تحت `docs/plans/release-1/` تحدد entry/exit وREQ/TEST والأوامر والبيئة ومسار الأدلة
ومساءلاً واحداً ومعتمداً واحداً وحد العيوب وrollback card، ويجب أن يوجد validator أو Make
target يشغلها. غياب أي عنصر منها stop condition وليس عملاً يؤجل إلى نهاية الموجة.

## بروتوكول تعديل الخطة

- يجوز تقسيم حزمة إذا تجاوزت سلوكاً واحداً أو شاركت ملفات مع مسار موازٍ.
- لا يعاد ترتيب موجتين رئيسيتين إلا بقرار راعٍ يضاف إلى سجل الحالة.
- يوقف المسار عند تغير عقد مشترك، ثم يعاد توليد العميل واختبارات العقود قبل المتابعة.
- تلغى الحزمة فقط مع سبب ودليل أثر وتحديث جدول التبعيات، ولا تحذف آثار القرار السابق.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | إنشاء خطة التسليم المتوازي وربط المراحل بالبوابات والمسارات والقرارات |
