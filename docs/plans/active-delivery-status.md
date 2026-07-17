---
doc_id: PLN-AS-001
title: حالة التسليم النشطة
type: plans
status: proposed
version: 1.6.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- قائد التقنية
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
- docs/plans/w1-1-remaining-delivery-tasks.md
references:
- docs/architecture/overview.md
- docs/operations/kubernetes-platform.md
- docs/operations/ha-dr-backup.md
---
# حالة التسليم النشطة

## الغرض

هذه الوثيقة هي سجل الحالة التنفيذية النشطة بعد فصل أدوات التخطيط عن المشروع. خارطة التنفيذ
المعتمدة تبقى في `docs/plans/implementation-roadmap.md`، ويُثبت الإنجاز بالكود والاختبارات
والالتزامات والوثائق المحكومة، لا بنسبة مولدة.

## الموضع الحالي

- النطاق النشط: W1.1 Walking Skeleton ضمن R1.
- خط الأساس الملتزم: خطط التأسيس حتى 01-05 ظاهرة في تاريخ Git حتى الالتزام `43649bf`.
- المسار الوظيفي المحلي لـWorkRecords HTTP وNotifications وOutbox relay وواجهة React
  مثبت عبر SQLite وMySQL وValkey ومتصفح حقيقي؛ لا يعني ذلك اكتمال بوابة W1.1 التشغيلية.
- حزمة W11-BLD-02 المحلية مثبتة بصور Laravel وReact متعددة المراحل وCompose إنتاجي؛
  نجح التشغيل من صور مسبقة البناء وmigrations وhealthchecks وإعادة تشغيل Valkey والـworker
  ورحلتا المتصفح، دون ادعاء نشر Dokploy أو سلسلة توريد مكتملة.
- توجد الآن عقود وأدوات واختبارات محلية لـW11-SC-03 وW11-NET-04 وW11-DEP-05
  وW11-DR-06 وW11-GATE-07 تحت `scripts/` و`infra/platform/contracts/` و`infra/platform/network/`
  و`infra/platform/release/` و`tests/ops/`. تثبت هذه الأسطح قواعد التحقق فقط؛ لا تثبت
  pipeline أو مضيفاً أو شبكة أو Dokploy أو هدف استعادة حياً.
- قرار التشغيل المعتمد هو خادم داخلي واحد يديره Dokploy من Docker Compose مثبت وفق ADR-023.
- أعمال النشر والجدار الناري والرجوع والنسخ والاستعادة وبوابة Go/No-Go ما زالت
  `blocked-external` حتى توفر مدخلات المضيف وتجارب التنفيذ الفعلية ونتيجة CI الحية.

## القرارات المحمولة إلى التنفيذ

- تطبيق المنتج من `apps/api` و`apps/web`، مع React واحد لكل الأدوار.
- `request` نوع WorkDefinition منشور وليس موديول أعمال مستقلاً.
- حفظ WorkRecord وOutbox event في معاملة واحدة، واستهلاك الأحداث idempotent.
- عزل المنشآت يعتمد على حقائق موثوقة وقرار خلفي موحد، ولا تمنح الواجهة صلاحية.
- لا يبدأ إصدار لاحق قبل بوابة الإصدار السابق إلا بقرار راعٍ مسجل.

## فجوات W1.1 المفتوحة

1. `W11-OPS-01`: `implemented-local / blocked-external`؛ بقيت قيم المضيف المعتمدة
   وتشغيل Staging المصادق عليه وreceipt الموقع خارج Git.
2. `W11-BLD-02`: `implemented-local`؛ الحزمة تعمل محلياً، بينما سلسلة التوريد الحية
   والـdescriptor الموقع يثبتان في `W11-SC-03`.
3. `W11-SC-03`: `implemented-local / blocked-external`؛ validator وdescriptor واختبارات
   CI المحلية موجودة، وتبقى pipeline الحية وartifacts الموقعة خارج الدليل المحلي.
4. `W11-NET-04`: `implemented-local / blocked-external`؛ policy وverifier والاختبارات
   موجودة، وتبقى فحوص المضيف ومنظورا المستخدم والإدارة خارج Git.
5. `W11-DEP-05`: `implemented-local / blocked-external`؛ عقد أدلة N→N+1→rollback موجود،
   ولم ينفذ نشر Dokploy أو rollback حي.
6. `W11-DR-06`: `implemented-local / blocked-external`؛ عقود manifest وrestore وقياس
   RPO/RTO موجودة، ولم ينفذ backup أو restore على هدف منفصل.
7. `W11-GATE-07`: `implemented-local / blocked-external`؛ جامع الأدلة يرفض النقص
   والقدم وعدم تطابق revision، ولا توجد بعد حزمة أدلة حية أو موافقات Go.

تفاصيل النطاق والتبعيات وعقد Unit وIntegration وE2E في
`docs/plans/w1-1-remaining-delivery-tasks.md`.

## أدلة التحقق المحلي

| التاريخ | الأمر | الدليل |
|---|---|---|
| 2026-07-17 | مجموعة API المستهدفة | 56 اختباراً، منها 54 ناجحاً و2 integration مشروطتان، و492 assertion على Identity وWorkDefinitions وWorkRecords وNotifications والحدود |
| 2026-07-17 | `composer lint` + `composer analyse` | Pint نجح وPHPStan/Larastan level 5 غطى `app` و`Modules` و`Shared` و`tests` بلا أخطاء |
| 2026-07-17 | مدققا OpenAPI + `./scripts/validate-docs.sh` | عقود WorkRecords وNotifications والتوثيق اجتازت التحقق |
| 2026-07-17 | Orval وRedocly وVitest وبناء React | عقد المنصة وsnapshot مسارات W1.1 بلا تحذيرات، والعميل المولد يقتصر على المسارات المنفذة وهو ثابت، و10 اختبارات نجحت بتغطية 100% للأسطر والفروع والدوال، ونجح lint والبناء |
| 2026-07-17 | `make verify-w1-1` | نجح المدخل الموحد، بما فيه MySQL وValkey وOutbox/Inbox وإعادة التسليم وDLQ ورحلتا المتصفح |
| 2026-07-17 | `make verify-w1-1-local` | نجحت المهام المحلية السبع، بما فيها بناء وفحص صور الإنتاج وCompose واختبارات SC/NET/DEP/DR/GATE |
| 2026-07-17 | `make verify-ci-config` + gitleaks + dependency audits | validator المحلي يثبت بنية workflow الـfail-closed، ولا أسرار أو advisories معروفة في lockfiles |
| 2026-07-17 | GitHub API readback | المستودع البعيد بلا فرع افتراضي أو runs أو runners أو Environments أو variables؛ لا يوجد دليل CI حي حتى نشر revision معتمد وإعداد البنية الخارجية |
| 2026-07-17 | `make verify-w11-ops-01-local` | 20 Unit و14 Integration ورحلة CLI محلية؛ عقد المثال صالح وreceipt منقح، دون ادعاء قبول مضيف حي |
| 2026-07-17 | `make verify-w11-bld-02-local` | 18 Unit و4 Integration وبناء وفحص صورتين runtime ثم E2E على Compose: migrations وhealthchecks وإعادة تشغيل Valkey والـworker ورحلتا العربية والإنجليزية وعزل المنشأتين |
| 2026-07-17 | `PYTHONPATH=. pytest --collect-only -q tests/ops` | أسطح SC-03 وNET-04 وDEP-05 وDR-06 وGATE-07 واختباراتها موجودة؛ جمع الاختبارات لا يثبت CI أو قبولاً حياً |

أدلة 2026-07-17 أعلاه ناتجة من شجرة العمل المحلية المبنية فوق الالتزام
`67348340521a5446506c9344776bfa18c73b616c` وليست بعدُ revision قبول منشوراً. يعاد
تشغيل الأوامر على الالتزام المنشور نفسه قبل اعتماد أي receipt حي.

## قاعدة تحديث الحالة

لا يُنقل بند إلى مكتمل إلا مع أمر تحقق ونتيجة أو التزام محدد أو وثيقة اعتماد. تبقى
الملاحظات غير المثبتة عملاً مفتوحاً، ولا تستخدم هذه الوثيقة نسبة تقدم إجمالية.

## الخطوة التالية

الخطوة التالية هي تشغيل pipeline الحية على revision واحد، ثم تسليم قائد SRE ملف المضيف
المعتمد خارج Git وتشغيل `make preflight-w11-ops-01-live`، وفحص NET-04 من المضيف ومنظوري
المستخدم والإدارة. بعد ذلك تجمع أدلة Dokploy وbackup/restore المنفصلة عبر الأدوات الجديدة
وتراجع بوابة GATE-07 عبر المسارات الفعلية:
`make verify-w1-1-host` و`make verify-w1-1-all`. يتطلب مسار المضيف `HOST_INPUTS`
و`HOST_RECEIPT`، ومدخلات NET-04: `NET04_POLICY` و`NET04_COMPOSE`
و`NET04_HOST_RECEIPT` و`NET04_USER_RECEIPT` و`NET04_MANAGEMENT_RECEIPT`
و`NET04_REVISION`. ويتطلب المسار المجمع `GATE_MANIFEST` و`GATE_TRUST_POLICY`
و`GATE_RELEASE_ROOT` و`GATE_EVIDENCE_ROOT` و`GATE_RECEIPT` و`GATE_AS_OF`، ومدخلات
cosign المثبتة `COSIGN_BINARY` و`COSIGN_SHA256` و`COSIGN_VERSION`، إضافة إلى
`RELEASE_DESCRIPTOR` و`RELEASE_ROOT` و`COSIGN_PUBLIC_KEY` التي يستهلكها `verify-build`.
ملف Compose المحكوم موجود في Git، أما policy الشبكة المعتمدة وقيم المضيف والreceipts
والأدلة والمفاتيح فتبقى آمنة خارج Git. يفشل المسار الحي إذا استُخدم ملف NET-04 المثال،
ولا يعد وجود الأهداف أو الأمثلة دليلاً على تشغيل حي.

## استلام إدارة العمل

اكتمل في 2026-07-17 فصل أدوات التخطيط السابقة عن المستودع:

- أزيل وقت التشغيل والأوامر والمهارات والوكلاء وhooks وMCP المرتبطة به.
- حذفت شجرة الحالة الوسيطة بعد نقل الوضع والقرارات والفجوات القابلة للتحقق.
- بقي OpenCode و`model-swarm` مستقلين وقابلين للتحميل.
- اجتاز مدقق الوثائق، وطابقت بصمة ملفات المنتج لقطة ما قبل التنظيف.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.5.0 | 2026-07-17 | مكتب هندسة المنصة | تسجيل الأسطح المحلية لـSC/NET/DEP/DR/GATE مع إبقاء الأدلة الحية وبوابة Go محجوبة خارجياً |
| 1.6.0 | 2026-07-17 | مكتب هندسة المنصة | تثبيت بوابات الجودة والعقد والعميل المولد وأمن CI وتسجيل نجاح الإغلاق المحلي وحالة GitHub الخارجية الفعلية |
| 1.4.0 | 2026-07-17 | مكتب هندسة المنصة | إثبات W11-BLD-02 محلياً بصور runtime وCompose ورحلتي متصفح وتوجيه الخطوة التالية إلى سلسلة التوريد والشبكة |
| 1.3.0 | 2026-07-17 | مكتب هندسة المنصة | إثبات تنفيذ W11-OPS-01 المحلي وإبقاء قبول المضيف الحي وreceipt الموقع كحاجز خارجي |
| 1.2.0 | 2026-07-17 | مكتب هندسة المنصة | ربط فجوات W1.1 بسبع مهام تنفيذية واختبارات Unit وIntegration وE2E وبوابة إثبات نهائية |
| 1.1.0 | 2026-07-17 | مكتب هندسة المنصة | إثبات المسار المحلي الكامل وحصر الفجوات المتبقية في بوابات التشغيل |
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | إنشاء سجل التسليم المستقل وحفظ حالة W1.1 القابلة للتحقق |
