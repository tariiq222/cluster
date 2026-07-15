---
doc_id: OPS-SC-001
title: سلسلة التوريد والتشغيل المعزول
type: operations
status: proposed
version: 1.0.0
date: 2026-07-15
owner: مسؤول العمليات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/adr/018-air-gapped-supply-chain.md
- docs/architecture/overview.md
references:
- docs/governance/assumptions-constraints.md
- docs/data-security/threat-model.md
- docs/operations/kubernetes-platform.md
---
# سلسلة التوريد والتشغيل المعزول

## المبدأ

لا يصل build أو runtime إلى الإنترنت. تستخدم GitLab لإدارة المصدر وCI، وHarbor لتسجيل OCI، وNexus لمرايا الحزم، بصفتها أدواراً مطلوبة داخل المركز لا أسماء خدمات مثبّتة. أي بديل يحقق الوظيفة والضوابط يحتاج موافقة معمارية وأمنية.

## تدفق artifact

1. يستورد فريق مخصص dependencies والتحديثات إلى منطقة إدخال محكومة خارج الإنتاج، مع فحص المصدر والترخيص والثغرات.
2. بعد الموافقة، ترفع الحزم إلى Nexus والصور الأساسية إلى Harbor مع metadata وتوقيع.
3. يبني GitLab CI من مرايا داخلية فقط، ويشغل الاختبارات وفحص الأسرار والثغرات والـlicense.
4. يولد لكل إصدار immutable: image digest، SBOM، نتائج الفحوص، provenance، وتوقيع.
5. تراجع طلبات GitOps، ثم يسحب controller الـartifact المسموح إلى `Staging` ثم `Prod` وفق الموافقة.
6. يتحقق admission من التوقيع والمصدر وارتباط SBOM قبل التشغيل.

## الضوابط

- تثبت versions بالـdigest أو lockfile؛ لا يسمح بوسم mutable مثل `latest`.
- لا يبنى أو ينشر artifact بلا SBOM وتوقيع متحقق ومراجعة ثغرات موثقة.
- تستخدم مفاتيح التوقيع وcredentials من Vault ولا تظهر في CI logs أو images أو Git.
- ترفض NetworkPolicy egress افتراضياً، ويشمل ذلك DNS الخارجي وimage pull من خارج السجل الداخلي.
- تحفظ حزم الاعتماد والـbase images اللازمة للتعافي والتحديث في المرايا الداخلية قبل قطع المسار الخارجي.
- تفصل صلاحيات إدخال dependencies عن اعتمادها ونشرها إلى الإنتاج.

## دليل القبول لكل إصدار

| الدليل | شرط القبول |
|---|---|
| artifact digest | مربوط بـGit revision معتمد |
| توقيع | صالح من هوية توقيع داخلية معتمدة |
| SBOM | موجود، قابل للقراءة، ومربوط بالـdigest |
| فحص | لا ثغرة أو استثناء غير معتمد يمنع النشر |
| air-gap | build وdeploy ناجحان دون تنزيل خارجي |
| GitOps | تغيير منشور من مراجعة معتمدة لا من تعديل يدوي |

## الاستجابة لاكتشاف ثغرة

تعطل artifact المتأثر من promotion، ويحدد نطاق الـSBOM، ثم يستورد الإصلاح بالمسار المحكوم، ويعاد بناؤه وتوقيعه واختباره. عند وجود استغلال نشط، يتبع فريق التشغيل [الاستجابة للحوادث](incident-response.md).

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول العمليات | إنشاء ضوابط سلسلة التوريد المعزولة |
