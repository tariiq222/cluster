---
doc_id: ADR-014
title: البحث المحكوم
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
scope: Search
supersedes:
- ADR-008
superseded_by: []
related_adrs:
- ADR-004
- ADR-007
- ADR-011
- ADR-015
review_by: 2027-01-15
---
# ADR-014: البحث المحكوم
## Context
البحث المركزي قد يكشف عنواناً أو مقتطفاً لسجل غير مسموح.
## Drivers
بحث سريع دون تحويل الفهرس إلى مصدر الحقيقة أو منفذ إفصاح.
## Decision
`Search` يستهلك أحداثاً لإنتاج فهرس مشتق، يطبق نطاقاً وتصنيفاً قبل الإرجاع ويعيد التفويض عند فتح المصدر.
## Scope
لا يكتب Search إلى سجلات الأعمال ولا يفهرس حقلاً غير مسموح به.
## Alternatives
رُفض البحث المباشر في جداول الموديولات وفهرس بلا ترشيح تفويض.
## Consequences
توجد نافذة اتساق نهائي وإعادة بناء لازمة بعد تغيرات كبيرة.
## Security
لا يعيد عنواناً أو مقتطفاً أو حقلاً محظوراً، والفشل مغلق.
## Operations
تراقب freshness وlag ونقطة التقدم وتعيد بناء الفهرس بأمان.
## Rollback
تعطل نسخة الفهرس المعطوبة وتبنى من الأحداث أو Projection Feeds.
## Enforcement
اختبارات منع كشف العنوان والعزل والتفويض عند الفتح وidempotency.
## Review
عند اختيار محرك بحث داخلي أو توسيع حقول الفهرسة.
## References
`docs/architecture/module-catalog.md`، `docs/data-security/authorization-model.md`.
