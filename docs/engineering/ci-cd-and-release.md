---
doc_id: ARC-EN-005
title: التكامل والتسليم والإصدار
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
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

## مراحل GitLab CI

1. `validate`: تنسيق، تحليل ساكن، SBOM، فحص الأسرار والتراخيص، والتحقق من lockfiles وCompose.
2. `test`: unit وapplication وarchitecture وcontract، وقياس changed-lines coverage وmutation للمنطق الحرج.
3. `package`: بناء artifact وصورة قابلة للتكرار، توليد digest وattestation وتوقيع داخلي.
4. `verify`: فحص healthchecks والمنافذ والشبكات الداخلية، ثم اختبارات E2E والأمن والأداء المطلوبة.
5. `publish`: حفظ الصور والـCompose revision المعتمدين في السجل الذي يقرأه Dokploy؛ لا يوجد GitOps controller أو Helm.

لا تستخدم pipeline متغيرات سرية في السجل أو مخرجات الاختبار. الأسرار تأتي من مدير أسرار داخلي بصلاحية أقل امتيازاً وتدوير معلن.

## Dokploy والإصدار

- كل إصدار يحمل tag SemVer وcommit SHA وimage digest وCompose revision ونسخة migration المدعومة.
- ينفذ Dokploy النشر المحكوم لحزمة Compose المثبتة، مع مراجعة يدوية وسجل نشر وhealthchecks قبل التفعيل.
- الترقية تتم باستبدال revision بعد تحقق الصحة والسعة؛ لا يوجد canary أو rolling متعدد العقد على الخادم الواحد.
- الرجوع يعيد آخر image digest وCompose revision سليمين. لا يرجع DDL هدمياً؛ تستخدم ترحيلات forward-fix أو restore وفق وثيقة الترحيلات.

## موافقة الإصدار

يتطلب الإنتاج: نجاح كل بوابات CI، مراجعة كود، موافقة مسؤول العمليات والأمن للتغيير عالي الأثر، وخطة rollback واختبار restore حديث صالح. يثبت سجل الإصدار المراجع وdigests ونتائج البوابات.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | مسؤول العمليات | مواءمة الإصدار مع Dokploy وCompose على خادم واحد |
