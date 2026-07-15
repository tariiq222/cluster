---
doc_id: ADR-005
title: WorkRecords والبيانات الديناميكية
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
scope: تعريف وتشغيل الأعمال الإدارية
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-006
- ADR-021
review_by: 2027-01-15
---
# ADR-005: WorkRecords والبيانات الديناميكية
## Context
تحتاج المنصة أنواع أعمال قابلة للتعريف دون كود مع تقارير وصلاحيات قابلة للتنفيذ.
## Drivers
المرونة، سلامة الإصدارات، وفهرسة الحقول المهمة.
## Decision
الطلب العام هو `WorkRecord` فقط، لا `Requests` module/table/events؛ النموذج Envelope علائقي وpayload مرتبط بإصدار تعريف منشور وإسقاطات typed.
## Scope
ينطبق على الأعمال الديناميكية، لا على المجالات المتخصصة ذات النموذج الثابت.
## Alternatives
رُفض EAV العام وJSON فقط وجدول أو Aggregate باسم Requests.
## Consequences
يلزم إسقاطات وترحيل إصدارات محكوم، وتبقى التقارير فعالة.
## Security
يحمل الـEnvelope المالك والتصنيف والحالة ويدعم `RecordFacts` وصلاحية الحقل.
## Operations
تراقب إعادة بناء الإسقاطات وتعالج تعارض الكتابة بـ`lock_version`.
## Rollback
لا يغير سجل جارٍ إصداره بصمت؛ يعكس النشر بإيقاف الإصدار لا بحذف قيمه.
## Enforcement
فحوص تمنع `Request*` وتتحقق من تثبيت الإصدار وoptimistic locking.
## Review
عند ظهور حاجة ثابتة متخصصة تستدعي موديولاً مستقلاً.
## References
`docs/domain/work-records.md`، `docs/architecture/overview.md`.
