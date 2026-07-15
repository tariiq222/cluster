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
- docs/adr/018-air-gapped-supply-chain.md
- docs/architecture/overview.md
references:
- docs/engineering/database-migrations.md
---
# التكامل والتسليم والإصدار

## بيئة Air-gapped

يعمل GitLab وRunners وContainer Registry ومرايا Composer وnpm داخل الشبكة المعزولة. يمنع CI والبناء والتشغيل من الاتصال بالإنترنت أو CDN. تثبت الاعتمادات بصور base وحزم ومراجع موقعة ومخزنة داخلياً قبل السماح بها.

## مراحل GitLab CI

1. `validate`: تنسيق، تحليل ساكن، SBOM، فحص الأسرار والتراخيص، والتحقق من lockfiles.
2. `test`: unit وapplication وarchitecture وcontract، وقياس changed-lines coverage وmutation للمنطق الحرج.
3. `package`: بناء artifact وصورة قابلة للتكرار، توليد digest وattestation وتوقيع داخلي.
4. `verify`: نشر ephemeral مع migrations آمنة ثم E2E والأمن والأداء المطلوب.
5. `publish`: دفع الصورة الموقعة إلى Registry الداخلي وتحديث مرجع GitOps فقط بعد نجاح البوابات.

لا تستخدم pipeline متغيرات سرية في السجل أو مخرجات الاختبار. الأسرار تأتي من مدير أسرار داخلي بصلاحية أقل امتيازاً وتدوير معلن.

## GitOps والإصدار

- مستودع GitOps منفصل يعلن البيئة والصورة بالـdigest وHelm/Kustomize والقيم غير السرية.
- Argo CD أو المتحكم المعتمد يزامن الحالة من Git إلى Kubernetes؛ يمنع `kubectl apply` اليدوي في البيئات المدارة.
- كل إصدار يحمل tag SemVer وcommit SHA وimage digest ونسخة migration وDSL المدعومة.
- الترقية: canary أو rolling متوافق مع الإصدارين، مراقبة الصحة وSLO، ثم ترقية كاملة بعد فترة تحقق معلنة.
- الرجوع: يعيد GitOps إلى digest سابق متوافق. لا يرجع DDL هدمي؛ تستعمل ترحيلات forward-fix أو restore وفق وثيقة الترحيلات.

## موافقة الإصدار

يتطلب الإنتاج: نجاح كل بوابات CI، مراجعة كود، موافقة مسؤول العمليات والأمن للتغيير عالي الأثر، وخطة rollback واختبار restore حديث صالح. يثبت سجل الإصدار المراجع وdigests ونتائج البوابات.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول العمليات | اعتماد CI المعزول ومسار GitOps للإصدار |
