---
doc_id: ADR-001
title: اعتماد Laravel Modular Monolith
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
scope: التطبيق الخلفي
supersedes: []
superseded_by: []
related_adrs:
- ADR-002
- ADR-003
- ADR-011
review_by: 2027-01-15
---
# ADR-001: اعتماد Laravel Modular Monolith
## Context
المنصة كبيرة لكن فريقها صغير والتشغيل محلي معزول.
## Drivers
سرعة التسليم، معاملات MySQL محلية، وبساطة النشر.
## Decision
نبني تطبيق Laravel واحداً منظماً إلى موديولات مستقلة ونوسعه أفقياً.
## Scope
ينطبق على جميع موديولات الخلفية ولا يمنع فصل موديول لاحقاً بدليل تشغيلي.
## Alternatives
رُفضت microservices المبكرة والتطبيق غير المقسم إلى موديولات.
## Consequences
يقل عبء التشغيل، لكن الحدود والاختبارات تصبح إلزامية.
## Security
عزل الصلاحيات والبيانات يطبق داخل التطبيق وفي قاعدة البيانات.
## Operations
ينشر التطبيق بعدة replicas ضمن Kubernetes.
## Rollback
يسترجع إصدار الصورة وقاعدة البيانات وفق خطة الإصدار؛ لا فصل تشغيلي تلقائي.
## Enforcement
اختبارات حدود الموديولات ومراجعة معمارية في CI.
## Review
يراجع عند الحاجة المستمرة لتوسع أو فريق أو دورة نشر مستقلة لموديول.
## References
`docs/architecture/overview.md`، `docs/architecture/module-catalog.md`.
