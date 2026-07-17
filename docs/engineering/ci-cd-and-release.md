---
doc_id: ARC-EN-005
title: التكامل والتسليم والإصدار
type: engineering
status: draft
version: 1.3.0
date: 2026-07-17
owner: مسؤول العمليات
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/engineering/database-migrations.md
---
# التكامل والتسليم والإصدار

## بيئة البناء والتشغيل

لا تفترض هذه الوثيقة عزلاً مؤسسياً كاملاً أو اتصالاً وقت التشغيل بالإنترنت. تُبنى الصور والاعتماديات من مصادر معتمدة، وتُثبت صور الإنتاج بالـdigest داخل حزمة Compose. يثبت سجل الإصدار مصدر كل artifact ونتيجة فحصه قبل إتاحته لـDokploy.

## مراحل GitHub Actions

1. `validate`: التوثيق والرسوم وlockfiles وتنسيق PHP والتحليل الساكن وعقد OpenAPI والعميل TypeScript المولد وفحص الأسرار والاعتماديات.
2. `test`: unit وapplication وarchitecture وcontract وE2E، مع بوابة تغطية 100% لحد HTTP اليدوي المختبر في الويب.
3. `package`: بناء artifact وصورة قابلة للتكرار، وتوليد SBOM وdigest وprovenance وفحص الثغرات والتراخيص ثم توقيع داخلي.
4. `verify`: فحص healthchecks والمنافذ والشبكات الداخلية، ثم اختبارات E2E والأمن والأداء المطلوبة.
5. `publish`: حفظ الصور والـCompose revision المعتمدين في السجل الذي يقرأه Dokploy؛ لا يوجد GitOps controller أو Helm.

لا تستخدم workflow متغيرات سرية في السجل أو مخرجات الاختبار. تحفظ GitHub Environments المحمية أسرار registry في `release-artifacts`، والمفتاح الخاص لـCosign في `release-signing` فقط، والمفتاح العام في `release-signing` و`release-verification`. تتطلب الوظائف release tag محمياً وrunner داخلياً منفصلاً حسب البيئة، بينما تبقى صور الحاويات مثبتة بالـdigest وتفشل مغلقة إلى أن تعتمد المنصة قيمها الفعلية.

لا ينفذ كود pull request من fork على runner داخلي. تقبل وظائف `ci-general` فقط push أو pull request من المستودع نفسه، وتبقى مساهمات fork بلا تنفيذ داخلي حتى تمر بمسار مراجعة وعزل مستقل. لا تسمح workflow بتوسيع `GITHUB_TOKEN` على مستوى job، وتثبت كل action خارجية بـcommit SHA كامل، وتمنع validator انتقال أسرار registry أو Cosign إلى وظيفة غير مصرح لها. تعتمد وظائف الإصدار على جميع بوابات التوثيق والرسوم والاختبارات والأمن، لا على مجموعة فرعية منها.

## تهيئة GitHub Actions خارج Git

ينفذ قائد SRE ومسؤول أمن المعلومات هذه الخطوات في GitHub والبنية الداخلية، ولا تحفظ قيم الأسرار أو مساراتها في المستودع:

1. أنشئ runner groups داخلية معزولة ويفضل أن تكون ephemeral، ثم سجّل runners بالـlabels `ci-general` و`release-artifacts` و`release-signing` و`release-verification`. لا تمنح runner التوقيع وصولاً إلى مجموعة أو بيئة أخرى، ولا تسمح لـfork pull requests باستخدام أي runner داخلي.
2. ابنِ صور الأدوات الداخلية المعتمدة، ثم استبدل جميع digests الصفرية في `.github/workflows/ci.yml` دفعة واحدة عبر مراجعة محمية. يقبل validator فقط placeholders كلها أو digests معتمدة كلها، لذلك لا يمرر إعدادًا مختلطًا. يجب أن يملك runner فقط صلاحية pull لهذه الصور؛ لا يمرر اعتماد pull كسر workflow.
3. أنشئ Environments محمية بالأسماء نفسها للمهام release. قيدها بـrelease tags محمية ومراجعين مسميين، واحصر secrets registry في `release-artifacts`، و`COSIGN_PRIVATE_KEY` في `release-signing`، و`COSIGN_PUBLIC_KEY` في بيئتي التوقيع والتحقق فقط.
4. بعد اعتماد digests والـrunners، اضبط GitHub Actions variable `SC_LIVE_TOOLING_APPROVED=true`. القيمة الافتراضية في workflow هي `false`، لذلك لا يمكن للتشغيل أن يتجاوز الموافقة بالخطأ.
5. ثبّت أدوات cosign وsyft وgrype وإصداراتها المحددة، وأرفق Grype DB وevidence وdigest حقيقيين. أنشئ GitHub Environment variables غير سرية باسم `SC_GRYPE_DB_SHA256` و`SC_GRYPE_DB_BUILT_AT` في `release-artifacts`؛ لا تبدأ release قبل توفرهما.
6. نفّذ `make verify-ci-config` محلياً و`make verify-w11-sc-03-local` قبل أول workflow حي. بعده فقط تُجمع artifacts وreceipts الخارجية لمسار W1.1 النهائي.

## Dokploy والإصدار

- كل إصدار يحمل tag SemVer وcommit SHA وimage digest وCompose revision ونسخة migration المدعومة.
- ينفذ Dokploy النشر المحكوم لحزمة Compose المثبتة، مع مراجعة يدوية وسجل نشر وhealthchecks قبل التفعيل.
- الترقية تتم باستبدال revision بعد تحقق الصحة والسعة؛ لا يوجد canary أو rolling متعدد العقد على الخادم الواحد.
- الرجوع يعيد آخر image digest وCompose revision سليمين. لا يرجع DDL هدمياً؛ تستخدم ترحيلات forward-fix أو restore وفق وثيقة الترحيلات.

## موافقة الإصدار

يتطلب الإنتاج: نجاح كل بوابات CI، مراجعة كود، موافقة مسؤول العمليات والأمن للتغيير عالي الأثر، وخطة rollback واختبار restore حديث صالح. يثبت سجل الإصدار المراجع وdigests ونتائج البوابات.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
| --- | --- | --- | --- |
| 1.1.0 | 2026-07-16 | مسؤول العمليات | مواءمة الإصدار مع Dokploy وCompose على خادم واحد |
| 1.2.0 | 2026-07-17 | مسؤول العمليات | نقل CI إلى GitHub Actions وتحديد عزل runners وEnvironments والأسرار |
| 1.3.0 | 2026-07-17 | مسؤول العمليات | فرض lockfiles وكل بوابات الإصدار وتثبيت actions ومنع صلاحيات job وتسرب الأسرار وتشغيل forks داخلياً |
