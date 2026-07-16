---
doc_id: ADR-019
title: تشغيل Kubernetes والتعافي
type: adr
status: superseded
version: 1.1.0
date: 2026-07-16
owner: مجلس معمارية المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources: []
references: []
deciders:
- مجلس معمارية المنصة
scope: البنية والتوافر والنسخ الاحتياطي
supersedes:
- ADR-010
superseded_by:
- ADR-023
related_adrs:
- ADR-007
- ADR-013
- ADR-018
review_by: 2026-10-15
---
# ADR-019: تشغيل Kubernetes والتعافي

> استبدل هذا القرار بـ[ADR-023](023-single-host-dokploy-deployment.md) لاعتماد Dokploy وDocker Compose على خادم داخلي واحد مع قبول خطر توقف الخدمة عند تعطل الخادم.
## Context
المنصة تحتاج حتى 2,000 مستخدم متزامن وتبقى متاحة عند فشل عقدة داخل المركز.
## Drivers
التوافر والتعافي القابل للقياس في تشغيل on-premises.
## Decision
تشغل Web/API وWorkers بعدة replicas، وMySQL وCache/Queue وObject Storage وSearch داخلياً عالي التوافر؛ النسخ مشفرة ومستقلة عن نطاق عطل Kubernetes مع RPO ≤15 دقيقة وRTO ≤ ساعتين.
## Scope
يشمل البيئات المنفصلة والمراقبة والسجلات وleader-elected scheduler والاستعادة.
## Alternatives
رُفضت عقدة وحيدة ونسخ داخل الكتلة فقط وخدمات سحابية عامة.
## Consequences
يزيد التعقيد التشغيلي ويستلزم SRE وتمارين استعادة فعلية.
## Security
فصل حساب النسخ ومفاتيحه وشبكته وتشفير النقل والتخزين.
## Operations
مقاييس وتنبيهات وفشل عقدة وتمرين استعادة ربع سنوي موثق.
## Rollback
تسترجع صورة وتكويناً وبيانات متوافقة ضمن نقطة التعافي؛ تختبر الاستعادة في شبكة معزولة.
## Enforcement
اختبارات حمل وفشل عقدة واستعادة RPO/RTO وفحص استقلال مخزن النسخ.
## Review
ربع سنوي وعند تغير توزيع Kubernetes أو أهداف التعافي.
## References
`docs/architecture/overview.md`، `docs/data-security/threat-model.md`.
