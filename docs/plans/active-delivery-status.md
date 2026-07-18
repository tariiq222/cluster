---
doc_id: PLN-AS-001
title: حالة التسليم النشطة
type: plans
status: accepted
version: 4.12.0
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
- خط الأساس المحلي: `main` يتضمن W1.2 حتى شريحة حساب Identity؛ يحتاج push فقط
  ليظهر دليله في CI المستضاف.
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

## الخطوة التالية

1. يبدأ الاستيراد المحكوم Received → Validated → Approved → Applied وتعبئة PII المشفرة.
2. تبقى TemporaryAssignment التفصيلية محجوبة حتى ينشر عقد قدراتها الصريحة وسحبها عند الانتهاء.
3. تبقى credentials والاسترداد الحقيقيان خلف عقد مستقل؛ fixture login الحالي ليس حساب Identity المنشأ.

ينفذ CI الجديد على GitHub-hosted runners عند أول push. لا يبدأ أي تشغيل على الخادم
حتى تصل الخطة إلى `D1` وتتوفر قيم `.env.production` وDNS وربط MySQL وRedis الخاص.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
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
