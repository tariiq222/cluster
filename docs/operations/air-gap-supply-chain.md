---
doc_id: OPS-SC-001
title: سلسلة توريد الصور والتحديثات للخادم الداخلي
type: operations
status: proposed
version: 2.0.0
date: 2026-07-16
owner: مسؤول العمليات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/governance/assumptions-constraints.md
- docs/data-security/threat-model.md
- docs/operations/kubernetes-platform.md
---
# سلسلة توريد الصور والتحديثات للخادم الداخلي

> احتُفظ باسم الملف التاريخي لاستقرار الروابط. البيئة ليست Air-gap مؤسسية، ولا تشترط مرايا حزم أو registry داخلياً ما لم تفرضه البنية الفعلية لاحقاً.

## المبدأ

تبنى الاعتماديات من lockfiles، وتنتج صورة OCI ثابتة يمكن نسبها إلى Git revision. يسمح لمسار البناء والتحديث بالوصول إلى مصادر معتمدة، بينما لا تنزل حاوية الإنتاج حزماً عند بدء التشغيل ولا تعتمد الواجهة على CDN أو scripts عامة.

## تدفق الإصدار

1. يراجع التغيير وlockfiles ونتائج الاختبارات وفحص الأسرار والثغرات.
2. تبنى صورة OCI وتوسم بالـcommit وتثبت بالـdigest.
3. يحفظ SBOM ونتائج الفحص وCompose revision وخطة migrations والرجوع.
4. يسمح Dokploy بنشر descriptor معتمد: image digest + Compose revision + environment contract.
5. تنفذ healthchecks بعد النشر، ويحفظ آخر descriptor معروف بالصحة للرجوع.

## الضوابط

- يمنع `latest` والمراجع المتغيرة في إنتاج Dokploy.
- لا يحتوي Git أو image أو logs على أسرار.
- لا تنفذ `composer install` أو `npm install` أو image pull غير متوقع داخل حاوية بدأت لخدمة المستخدم.
- تراجع الثغرات والتراخيص قبل الإصدار؛ ويربط SBOM بالـdigest متى كانت أداة البناء متاحة.
- لا تكون واجهة Dokploy أو Docker socket أو Registry credentials متاحة لمسار المستخدم.
- يسجل تحديث Dokploy وDocker والصور ضمن نافذة صيانة وخطة رجوع.

## دليل القبول لكل إصدار

| الدليل | شرط القبول |
|---|---|
| image digest | مربوط بـGit revision معتمد ولا يستخدم `latest` |
| Compose revision | صالح ومثبت ومتوافق مع متغيرات البيئة |
| اختبارات وفحص | لا فشل أو استثناء غير معتمد يمنع النشر |
| SBOM | موجود ومربوط بالـdigest عند بوابة الإنتاج |
| نشر Dokploy | سجل النشر وhealthchecks محفوظان |
| رجوع | descriptor سابق معروف بالصحة ومجرب |

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول العمليات | إنشاء ضوابط سلسلة التوريد المعزولة |
| 2.0.0 | 2026-07-16 | مالك المنصة | تحويل الضوابط إلى سلسلة تحديث Dokploy لخادم داخلي متصل بشكل مقيد |
