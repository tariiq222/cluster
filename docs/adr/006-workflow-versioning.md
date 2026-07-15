---
doc_id: ADR-006
title: إصدارات المسارات وتنفيذها
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
scope: Workflow
supersedes: []
superseded_by: []
related_adrs:
- ADR-005
- ADR-007
- ADR-020
review_by: 2027-01-15
---
# ADR-006: إصدارات المسارات وتنفيذها
## Context
تعديل مسار منشور قد يفسد معاملات الموافقة الجارية.
## Drivers
قابلية التدقيق والاستقرار مع التفرع والتوازي والنصاب.
## Decision
المسودة قابلة للتعديل وكل نشر ينشئ إصداراً ثابتاً؛ تثبت المعاملة `workflow_version_id` ويحل المعتمد وقت تفعيل الخطوة.
## Scope
يشمل التحقق قبل النشر والتصعيد وحل الشاغر؛ يمنع الكود الحر في القواعد.
## Alternatives
رُفض تعديل المسار المنشور مباشرة ومحرك قواعد ينفذ كوداً حراً.
## Consequences
يحتاج أدوات توافق وترحيل صريح، ويصون المعاملات الجارية.
## Security
الانتقال والاعتماد يخضعان لـAuthorization، وتدقق القرارات.
## Operations
يراقب التصعيد والخطوات العالقة وحالات فشل التنفيذ.
## Rollback
يوقف الإصدار الجديد للمعاملات الجديدة؛ الجارية تبقى على إصدارها أو ترحل بعد فحص صريح.
## Enforcement
فحوص DAG للمسار وبداية/نهاية ومعتمد ونصاب، واختبارات تثبيت الإصدار.
## Review
عند إضافة نوع خطوة أو نمط قرار.
## References
`docs/domain/workflow.md`، `docs/architecture/module-catalog.md`.
