---
doc_id: PLN-AS-001
title: حالة التسليم النشطة
type: plans
status: accepted
version: 4.21.0
date: 2026-07-18
owner: طارق
reviewers: []
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/release-1-platform.md
- docs/plans/w1-1-remaining-delivery-tasks.md
references:
- docs/engineering/delivery-workflow.md
---
# حالة التسليم النشطة

## الغرض

هذه الوثيقة الوحيدة لحالة التنفيذ. سطر لكل إنجاز: التاريخ والأمر والالتزام
والنتيجة. لا نسب تقدم، ولا يُنقل بند إلى مكتمل بلا أمر تحقق ونتيجة.

## الموضع الحالي

- الموجة المكتملة محلياً: **W1.1 Walking Skeleton** — `make verify-w1-1` و
  `make verify-w1-1-local` أخضران، ولا تعاد أعمالها إلا لمعالجة انحدار.
- الموجة النشطة: **W1.2 Organization + Identity + Import** — تخطيط W12-REQ وW12-FE
  مكتمل، وأغلقت بوابة الجاهزية W12-00 محلياً.
- خط الأساس المنشور: `main` يتضمن W1.2 حتى رحلة إدارة Organization وIdentity وImport
  عند `4824a804`، وCI المستضاف `29643248979` أخضر.
- ADR-024 مقبول، وملكية Person واتجاه `Identity -> Organization` وtaxonomy الحساب
  وعقود OpenAPI والأحداث والاستيراد والنطاق وbootstrap مجمدة ومتسقة.
- اكتمل CRUD الخلفي الحالي للتجمع والمنشآت محلياً: إنشاء/قراءة/تعديل التجمع الواحد،
  وإنشاء/قائمة/قراءة/تعديل/أرشفة المنشآت، مع ETag و`If-Match` وOutbox ذري وتفويض
  bootstrap مغلق افتراضياً.
- اكتملت شجرة الوحدات والمناصب محلياً: parent محكوم، `path_cache` و`depth` ذريان
  للـsubtree، منع الدوران، مدير منصب بلا دورة، optimistic locking وOutbox.
- اكتملت شريحة Person محلياً: تسجيل/قائمة/قراءة/تعديل ومرجع Identity مصغر،
  `person_version` وETag وreplay مشفر وOutbox سري بلا أسماء أو PII، مع أعمدة PII
  ciphertext وlookup hash الجاهزة لمسار الاستيراد المحكوم من دون قبول PII خام غير متعاقد.
- اكتملت شريحة حساب Identity محلياً: حساب واحد حي لكل Person، تحقق ذري من
  `person_id` و`person_version`، lifecycle وETag وreplay مشفر وسحب جلسات، مع relay
  محكوم لأحداث Person وRedis worker وInbox/high-water وDLQ idempotent بلا FK أو join عابر.
- اكتملت شريحة التكليفات المحكومة محلياً: ربط Person بمنصب ضمن مدى UTC، منع تداخل
  primary وتداخل شاغلي المنصب، حالات pending/active/ended، إنهاء نهائي مع ETag وreplay
  وOutbox ذري، من دون نسخ PII أو FK عابر إلى Identity.
- اكتملت أول شريحة تنفيذية للاستيراد المحكوم `people_assignments` محلياً: حالات
  received/validated/approved/applied مع reject/cancel/fail، موافقة actor ثان، صفوف مشفرة
  ونتائج منقحة وETag وreplay وOutbox ذري وتطبيق Person والتكليف وطلب provisioning مرة
  واحدة. يبقى مصدر quarantine الحقيقي مغلقاً افتراضياً حتى ينشر تكامل Documents.
- اكتمل أساس Web لـW1.2 محلياً: عميلان مولدان مستقلان من snapshotي W1.1 وW1.2 مع
  drift gate واحد، وسجل routes typed يحافظ على direct load وback/forward و404 ولا
  يكسر رحلة WorkRecords الحالية.
- اكتملت واجهة إدارة التجمع والمنشآت محلياً: route عربية/إنجليزية متجاوبة تقرأ الجذر
  والمنشآت وتنشئهما عبر correlation وidempotency، مع loading/empty/403/error وجدول
  قابل للتمرير ونماذج labels كاملة من دون توسيع صلاحية الواجهة.
- اكتملت واجهة شجرة الوحدات والمناصب محلياً: route تقرأ hierarchy والمناصب وتنشئ
  parent من التجمع أو المنشأة أو الوحدة، وتربط المنصب بوحدته ومديره الاختياري، مع
  عرض عمق الشجرة وحالات الوصول والفشل بالعربية والإنجليزية.
- اكتملت واجهة الأشخاص والتكليفات محلياً: route تفصل Person عن Identity، تنشئ ملف
  الشخص بلا حقول حساب، وتعرض التكليفات بتوقيت Asia/Riyadh مع إرسال UTC، وتنشئ
  التكليف الأساسي ضمن فترة محققة ومراجع Person/Position منشورة.
- اكتملت واجهة حسابات Identity محلياً: إنشاء pending من Person/version منشور، وعرض
  lifecycle بلا credentials، وتنفيذ activate/unlock/disable/archive/revoke/force-change
  بعد GET للـETag وإرسال If-Match، مع fail-closed عند غياب النسخة أو `412`.
- اكتملت واجهة مراجعة الاستيراد المحكوم محلياً: إنشاء `people_assignments` من مرجع
  quarantine، فتح job مباشر، عرض الحالة والصفوف المنقحة بلا payload، وتنفيذ
  validate/approve/reject/apply/cancel بعد ETag حديث، مع توضيح حجب رفع bytes.
- نشر VPS المباشر والرجوع والاستعادة مؤجلة إلى مرحلة `D1`
  النهائية بعد اكتمال تطوير R1 وR2 وR3، ولا تحجب W1.2.

## قرارات محمولة إلى التنفيذ

- تطبيق المنتج من `apps/api` و`apps/web`، مع React واحد لكل الأدوار.
- `request` نوع WorkDefinition منشور وليس موديول أعمال مستقلاً.
- حفظ WorkRecord وOutbox event في معاملة واحدة، والمستهلك idempotent.
- عزل المنشآت يعتمد على قرار خلفي موحد، ولا تمنح الواجهة صلاحية.
- التشغيل على VPS واحد عبر Docker Compose مباشر وCaddy وفق ADR-023.
- Person وPII الأساسية يملكهما Organization، والحساب والجلسة يملكهما Identity بلا FK أو join عابر.

## أدلة التحقق

| التاريخ | الأمر | النتيجة |
|---|---|---|
| 2026-07-17 | `make verify-w1-1` | أخضر: MySQL وRedis وOutbox/Inbox وإعادة التسليم وDLQ ورحلتا المتصفح RTL/LTR |
| 2026-07-17 | `make verify-w1-1-local` | أخضر: بناء صور الإنتاج من lockfiles وتشغيل حزمة Compose كاملة |
| 2026-07-17 | مجموعة API | 56 اختباراً و492 assertion على Identity وWorkDefinitions وWorkRecords وNotifications والحدود |
| 2026-07-17 | `composer lint` + `composer analyse` | Pint وPHPStan level 5 بلا أخطاء |
| 2026-07-17 | Vitest + بناء React | 10 اختبارات بتغطية 100%، وlint والبناء ناجحان |
| 2026-07-17 | `./scripts/validate-docs.sh` | الوثائق والعقود سليمة |
| 2026-07-17 | دمج W1.1 محلياً في `main` | fast-forward إلى `c107004`، الشجرة نظيفة |
| 2026-07-17 | ADR-024 صيغ واجتاز التحقق | `validate-docs` و`verify-boundaries` أخضران؛ اعتماده النهائي مع مواءمة وثائق المجال قبل بدء W1.2 |
| 2026-07-17 | إغلاق تخطيط W12-REQ وW12-FE | دمج REQ/TEST و14 Invariant وبوابة W12-00 وعقد routes/RTL/LTR/a11y في خطة R1؛ `validate-docs` و`verify-boundaries` أخضران؛ اختبار runtime هو N/A لأن الناتج تخطيط فقط |
| 2026-07-17 | `make verify-w1-1` بعد التحويل إلى Redis | أخضر: 56 اختبار API و492 assertion، 10 اختبارات Web، حدود الموديولات، Gitleaks، ورحلتا المتصفح |
| 2026-07-17 | `make verify-w1-1-local` على حزمة VPS المباشرة | أخضر: بناء صور runtime، MySQL وRedis محمي، migration، worker، healthchecks، RTL/LTR والعزل؛ Caddy وCompose والوثائق سليمة |
| 2026-07-18 | دمج مخلفات workspaces في `main` | حُفظ عقد واجهة W1.2 الفريد؛ تغييرات بوابات W1.1 القديمة وADR-024 المكرر superseded بالتبسيط والقرار الموجود |
| 2026-07-18 | `make verify-w1-1` و`make verify-w1-1-local` و`./scripts/validate-docs.sh` | أخضر: 56 اختبار API و492 assertion، 10 اختبارات Web، الصور، Caddy HTTPS، MySQL/Redis، migration، worker، RTL/LTR والعزل |
| 2026-07-18 | CI `29613697299` ثم `make verify-w1-1-local` | كشف CI أن PHP 8.3 لا يطابق lockfile وأن Dockerfile ينسخ `resources/` فارغاً غير متتبع؛ رُفع CI إلى PHP 8.4 وحذف COPY غير المستخدم، والبوابة المحلية خضراء |
| 2026-07-18 | CI `29614091300` ثم مجموعة API | نجحت حزمة الإنتاج وكشف اختبار API اعتماداً ضمنياً على `.env` المحلي؛ أضيف `APP_KEY` اختباري ثابت إلى `phpunit.xml` لإبقاء checkout النظيف قابلاً للتكرار |
| 2026-07-18 | CI `29614449372` | أخضر: API وWeb والوثائق وGitleaks وحزمة VPS الإنتاجية كاملة على `main` |
| 2026-07-18 | `make verify-w1-2` | أخضر: عقود W1.2 وOpenAPI الثلاثة والأحداث والحدود، 56 اختبار API و492 assertion، و10 اختبارات Web بتغطية 100% |
| 2026-07-18 | `php artisan test Modules/Organization/Tests/OrganizationCoreHttpAdapterTest.php` | أخضر: 6 اختبارات و81 assertion للتجمع والمنشآت وcursor وidempotency وrollback الذري للـOutbox |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` | أخضر: migration واختبارات Organization وrollback المعاملة وmigration على MySQL مع Redis وعزل W1.1؛ أزيل اعتماد smoke على `.env` المحلي بإضافة `APP_KEY` اختباري |
| 2026-07-18 | `make verify-w1-2` بعد W12-ORG-01 | أخضر: 62 اختبار API و573 assertion، الحدود والعقود وOpenAPI، و10 اختبارات Web بتغطية 100% |
| 2026-07-18 | `make verify-w1-2` بعد CRUD التجمع والمنشآت | أخضر: 66 اختبار API و648 assertion، عقود W1.2 عبر Redocly والحدود، وثبات Orval 8.22.0 لخط أساس W1.1 بلا drift، وبناء Web و10 اختبارات بتغطية 100% |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد optimistic updates | أخضر: MySQL أثبت تحديث/أرشفة Organization و`412` وrollback الذري مع Outbox، ثم عزل W1.1 وRedis وInbox/DLQ |
| 2026-07-18 | `make verify-w1-2` بعد شجرة الوحدات والمناصب | أخضر: 71 اختبار API و836 assertion، عقود W1.2 وRedocly والحدود، وثبات Orval لخط أساس W1.1، وبناء Web و10 اختبارات بتغطية 100% |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد شجرة Organization | أخضر: MySQL أثبت migrations وsubtree move ومنع الدوران ودورة مدير المنصب و`412` وrollback الذري مع Outbox |
| 2026-07-18 | `make verify-w1-2` بعد شريحة Person | أخضر: 77 اختبار API و948 assertion، عقود OpenAPI/AsyncAPI والحدود، وثبات Orval لخط أساس W1.1، وبناء Web و10 اختبارات بتغطية 100% |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد شريحة Person | أخضر: MySQL أثبت 21 اختبار Organization و456 assertion للتسجيل وreplay المشفر و`person_version` و`412` وrollback الذري مع Outbox |
| 2026-07-18 | `make verify-w1-2` بعد شريحة حساب Identity | أخضر: 93 اختبار API، نجح 91 وتخطى 2، و1161 assertion؛ الوثائق والحدود وPHPStan وPint وRedocly وOrval وبناء Web و10 اختبارات Web خضراء |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد relay وworker لأحداث Person | أخضر: MySQL وRedis؛ Walking Skeleton ‏2/44، Organization ‏21/456، Identity ‏16/213 للحساب وlifecycle وInbox/high-water والـrelay وإعادة التسليم وDLQ |
| 2026-07-18 | `make verify-w1-2` بعد تكليفات Person وPosition | أخضر: 97 اختبار API، نجح 95 وتخطى 2، و1238 assertion؛ الوثائق والحدود وPHPStan وPint وRedocly وOrval وبناء Web و10 اختبارات Web خضراء |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد تكليفات Person وPosition | أخضر: MySQL وRedis؛ Walking Skeleton ‏2/44، Organization ‏25/533، Identity ‏16/213؛ أثبت migration وترتيب FKs والتداخل وETag وreplay وrollback الذري |
| 2026-07-18 | `make verify-w1-2` بعد أول vertical للاستيراد المحكوم | أخضر: 103 اختبارات API، نجح 101 وتخطى 2، و1362 assertion؛ OpenAPI/AsyncAPI والحدود وPint وPHPStan وRedocly وOrval وبناء Web و10 اختبارات Web خضراء |
| 2026-07-18 | `./infra/dev/run-w1-1-api-worker-smoke.sh` بعد `people_assignments` | أخضر: MySQL وRedis؛ Walking Skeleton ‏2/44، Organization ‏31/657، Identity ‏16/213؛ أثبت تشفير الصفوف والموافقة الثنائية والـapply مرة واحدة والفشل المغلق والrollback |
| 2026-07-18 | `make verify-w1-2` بعد W1.2 Web shell/client baseline | أخضر: عميل Orval مستقل لكل snapshot بلا drift، 103 اختبارات API و1362 assertion، وبناء Web وlint و12 اختبار Web أخضر |
| 2026-07-18 | `make verify-w1-2` بعد واجهة التجمع والمنشآت | أخضر: 103 اختبارات API و1362 assertion، و14 اختبار Web بتغطية 100% للعميل، مع Redocly وOrval والبناء وlint بلا أخطاء |
| 2026-07-18 | `make verify-w1-2` بعد واجهة الوحدات والمناصب | أخضر: 103 اختبارات API و1362 assertion، و15 اختبار Web بتغطية 100% للعميل، مع route typed وبناء RTL/LTR بلا drift |
| 2026-07-18 | `make verify-w1-2` بعد واجهة الأشخاص والتكليفات | أخضر: 103 اختبارات API و1362 assertion، و16 اختبار Web بتغطية 100% للعميل، مع تحويل العرض Asia/Riyadh والإرسال UTC |
| 2026-07-18 | `make verify-w1-2` بعد واجهة حسابات Identity | أخضر: 103 اختبارات API و1362 assertion، و18 اختبار Web بتغطية 100% للعميل، مع ETag/If-Match ورفض mutation بلا نسخة |
| 2026-07-18 | `make verify-w1-2` بعد واجهة مراجعة الاستيراد | أخضر: 103 اختبارات API و1362 assertion، و20 اختبار Web بتغطية 100% للعميل، مع صفوف منقحة وETag لكل انتقال بلا رفع bytes مخترع |
| 2026-07-18 | `./infra/dev/run-w1-1-e2e.sh` ثم `make verify-w1-2` بعد رحلة إدارة W1.2 | أخضر: 3 رحلات Playwright على MySQL وRedis؛ ثبت إنشاء Organization وIdentity وImport والفشل المغلق وRTL/LTR، مع إصلاح `APP_KEY` وشكل استجابة Identity وعزل عداد Outbox حسب نوع الحدث؛ بقيت 103 اختبارات API و20 اختبار Web بتغطية 100% خضراء |
| 2026-07-18 | CI `29643248979` على `main@4824a804` | أخضر: API وWeb والوثائق وGitleaks وW1.2 readiness وحزمة الإنتاج و`make verify-w1-1-local`؛ استبعدت أدوات OpenCode وOpenSpec المحلية من سلسلة المنتج قبل الدفع |
| 2026-07-18 | `make verify-w1-2` بوابة دمج W1.2 | أخضر محلياً: `validate-docs` والحدود وOpenAPI/توليد العميل؛ API 209 ناجحاً و5 متخطاة لـMySQL؛ Web build/lint وتغطية 24 اختباراً؛ E2E وS3 وClamAV هي N/A لهذه البوابة المحلية فقط |

## الخطوة التالية

1. ينشر تكامل quarantine الحقيقي عبر عقد موديول محكوم، ثم تنفذ قوالب facilities وorganization_units وpositions؛ يبقى adapter الحالي fail-closed حتى ذلك.
2. تبقى واجهة رفع الاستيراد محجوبة حتى ينشر Documents عقد quarantine القابل للتنفيذ، بينما يمكن بناء قراءة الحالة والصفوف على العقد الحالي.
3. تبقى TemporaryAssignment التفصيلية محجوبة حتى ينشر عقد قدراتها الصريحة وسحبها عند الانتهاء.
4. تبقى credentials والاسترداد الحقيقيان خلف عقد مستقل؛ fixture login الحالي ليس حساب Identity المنشأ.

CI المستضاف أخضر على خط أساس W1.2 المنشور. لا يبدأ أي تشغيل على الخادم حتى تصل
الخطة إلى `D1` وتتوفر قيم `.env.production` وDNS وربط MySQL وRedis الخاص.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 4.21.0 | 2026-07-18 | تسجيل دمج W1.2 في main وCI الأخضر بعد فصل أدوات OpenCode وOpenSpec المحلية |
| 4.20.0 | 2026-07-18 | إثبات رحلة متصفح W1.2 كاملة وإغلاق انحدارات بيئة login واستجابة Identity وتنسيق Outbox |
| 4.19.0 | 2026-07-18 | إغلاق واجهة مراجعة ImportJob وانتقالاته المنقحة مع بقاء نقل bytes محجوباً بالعقد |
| 4.18.0 | 2026-07-18 | إغلاق واجهة حسابات Identity ودورة حياتها المحكومة بـETag وIf-Match |
| 4.17.0 | 2026-07-18 | إغلاق واجهة Person والتكليفات الزمنية من دون خلط حقول Identity |
| 4.16.0 | 2026-07-18 | إغلاق واجهة شجرة الوحدات والمناصب وإنشائها المحكوم على عقد W1.2 |
| 4.15.0 | 2026-07-18 | إغلاق واجهة التجمع والمنشآت على عقد W1.2 المولد مع حالات الوصول والفشل والاستجابة |
| 4.14.0 | 2026-07-18 | نشر W1.2 Web route registry وعميل Orval ثانٍ مع إبقاء رحلة W1.1 بلا انحدار |
| 4.13.0 | 2026-07-18 | إغلاق أول vertical لـpeople_assignments مع الصفوف المشفرة والموافقة الثنائية والتطبيق الذري على SQLite وMySQL |
| 4.12.0 | 2026-07-18 | إغلاق تكليفات Person وPosition ومددها وتداخلها وإنهائها الذري على SQLite وMySQL |
| 4.11.0 | 2026-07-18 | إغلاق حساب Identity المرتبط بـPerson وlifecycle وrelay/worker وInbox/high-water وDLQ على SQLite وMySQL/Redis |
| 4.10.0 | 2026-07-18 | إغلاق Person CRUD والمرجع المصغر وperson_version وreplay المشفر وأحداث بلا PII على SQLite وMySQL |
| 4.9.0 | 2026-07-18 | إغلاق شجرة الوحدات والمناصب مع منع الدوران وpath_cache وOutbox على SQLite وMySQL |
| 4.8.0 | 2026-07-18 | إغلاق CRUD التجمع والمنشآت مع ETag و`412` والأرشفة وOutbox الذري على SQLite وMySQL |
| 4.7.0 | 2026-07-18 | إغلاق W12-ORG-01 للتجمع الواحد وإنشاء المنشآت على SQLite وMySQL |
| 4.6.0 | 2026-07-18 | إغلاق W12-00 محلياً وفتح أول شريحة تنفيذ Organization |
| 4.5.0 | 2026-07-18 | تسجيل CI الأخضر بعد دمج workspaces وإصلاح انحرافات checkout النظيف |
| 4.4.0 | 2026-07-18 | إزالة اعتماد اختبارات API الضمني على ملف `.env` المحلي |
| 4.3.0 | 2026-07-18 | تسجيل انحراف PHP في CI ومدخل Docker غير المتتبع وإصلاحهما |
| 4.2.0 | 2026-07-18 | دمج العمل الفريد من workspaces وإثبات بوابات W1.1 وحزمة VPS بعد الدمج |
| 4.1.0 | 2026-07-17 | استبدال Dokploy/Valkey بنشر VPS مباشر وCaddy وRedis وتسجيل البوابات المحلية الخضراء |
| 4.0.0 | 2026-07-17 | إغلاق W1.1 محلياً وفتح W1.2 ونقل تشغيل الخادم إلى المرحلة النهائية D1 |
| 3.1.0 | 2026-07-17 | إثبات إغلاق تخطيط W12-REQ وW12-FE وإبقاء W12-00 والتنفيذ كخطوة تالية |
| 3.0.0 | 2026-07-17 | تبسيط السجل لمطور واحد: حذف بوابات الأدلة الموقعة والمسارات المتوازية وحصر المتبقي في أربع مهام |
| 2.0.0 | 2026-07-17 | اعتماد سجل المراحل المبسط ونقل W1.1 إلى الاختبار الحي |
| 1.0.0 | 2026-07-17 | إنشاء سجل التسليم المستقل |
