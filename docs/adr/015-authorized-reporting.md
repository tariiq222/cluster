---
doc_id: ADR-015
title: التقارير واللوحات المحكومة
type: adr
status: accepted
version: 1.0.0
date: 2026-07-15
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
scope: Reporting والتصدير
supersedes:
- ADR-008
superseded_by: []
related_adrs:
- ADR-004
- ADR-007
- ADR-011
- ADR-014
- ADR-021
review_by: 2027-01-15
---
# ADR-015: التقارير واللوحات المحكومة
## Context
التقارير العابرة للموديولات لا يجوز أن تضغط مسار المعاملات أو تتجاوز سياسة الحقول.
## Drivers
قراءة مؤسسية قابلة للتوسع مع عزل وتصدير قابل للتدقيق.
## Decision
`Reporting` يملك تعريفات التقارير واللوحات وRead Models مشتقة فقط؛ يطبق Authorization وFieldAccess عند العرض والتصدير.
## Scope
يشمل الإسقاطات والتقارير والتصدير، ولا يملك تعريف المؤشر أو قياساته.
## Alternatives
رُفضت joins مباشرة في جداول الأعمال والتقارير على JSON الخام ومستودع تحليلي مبكر.
## Consequences
توجد تأخيرات اتساق نهائي وإعادة بناء، ويخف حمل قاعدة المعاملات.
## Security
يعاد تقييم الوصول للحقول عند التصدير ويسجل المحتوى الحساس.
## Operations
تراقب freshness ومدة التقرير وحجم التصدير وتعالج الإخفاقات خارج الطلب.
## Rollback
تعاد بناء Read Model أو تعطل تعريف التقرير دون تغيير المصدر.
## Enforcement
اختبارات عدم كتابة المصدر وصلاحية الحقول والتصدير والعزل.
## Review
عند الحاجة المثبتة لمستودع تحليلي مستقل.
## References
`docs/architecture/module-catalog.md`، `docs/data-security/authorization-model.md`.
