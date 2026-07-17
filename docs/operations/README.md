---
doc_id: OPS-OV-001
title: فهرس وثائق التشغيل
type: operations
status: proposed
version: 1.1.0
date: 2026-07-16
owner: مسؤول العمليات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/architecture/overview.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/governance/document-control.md
- docs/governance/assumptions-constraints.md
---
# فهرس وثائق التشغيل

هذه الحزمة تصف التصميم التشغيلي لـVPS واحد محدود المنافذ. لا تحتوي أسماء مضيفين أو نطاقات أو عناوين أو أسرار فعلية؛ تحفظ تلك القيم في `.env.production` على الخادم.

| الوثيقة | الغرض |
|---|---|
| [الطوبولوجيا الفيزيائية](physical-topology.md) | طبقات المركز ومجالات العطل والسعة المرجعية |
| [منصة Docker Compose](kubernetes-platform.md) | قرار الخادم الواحد، الحاويات، الوصول، والنشر |
| [التوافر والتعافي والنسخ](ha-dr-backup.md) | HA وPITR وRPO/RTO وتمرين الاستعادة |
| [الرصد وSLO](observability-and-slos.md) | القياسات والتنبيهات وأهداف الخدمة |
| [سلسلة التوريد](air-gap-supply-chain.md) | تثبيت الصور والتحديث والمراجعة وSBOM |
| [الاستجابة للحوادث](incident-response.md) | التصنيف والأدوار والاحتواء والتواصل |
| [كتيبات التشغيل](runbooks.md) | إجراءات تنفيذية للحالات المتكررة والحرجة |

## القرارات الملزمة

- يعمل الإنتاج على VPS واحد عبر Docker Compose مباشر وCaddy، ولا يستخدم Kubernetes أو Dokploy.
- يستخدم التطبيق MySQL وRedis الموجودين على الخادم، ولا تنشر منافذهما للعامة.
- يكون HTTPS مسار المستخدم، ويقيد SSH بعناوين الإدارة.
- تبنى الصور من lockfiles عند النشر ويمكن الرجوع إلى commit سليم.
- تبقى Dev وTest خارج بيانات وأسرار Prod؛ ولا يعد مشروع Compose آخر على الخادم نفسه عزلاً أمنياً كاملاً.
- تحفظ النسخ مشفرة خارج خادم الإنتاج وتختبر الاستعادة على هدف منفصل.

## معايير القبول التشغيلية

- `RPO <= 15 دقيقة` و`RTO <= ساعتين`، ويثبتان بتمرين استعادة ربع سنوي.
- لا يدعى HA عند فشل الخادم الواحد؛ تقاس الإتاحة الفعلية، وAPI `p95 <= 500ms`، والبحث `p95 <= 2s`، وتأخر الفهرسة `<= 60s`.
- يثبت اختبار الحمل خدمة حتى `2,000` مستخدم متزامن قبل الإطلاق.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول العمليات | إنشاء فهرس حزمة التشغيل |
| 2.0.0 | 2026-07-17 | طارق | اعتماد Docker Compose مباشر وCaddy وMySQL/Redis خارجيين على VPS واحد |
