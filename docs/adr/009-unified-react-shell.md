---
doc_id: ADR-009
title: واجهة React موحدة
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
scope: تطبيق الويب
supersedes: []
superseded_by: []
related_adrs:
- ADR-002
- ADR-004
- ADR-012
review_by: 2027-01-15
---
# ADR-009: واجهة React موحدة
## Context
الواجهات المنفصلة للإدارة والمستخدم تضاعف الصيانة والتدريب.
## Drivers
تجربة موحدة ودعم العربية RTL والإنجليزية LTR.
## Decision
تطبيق React وTypeScript واحد بجلسة وتنقل وتصميم موحدين؛ تسجل الموديولات routes وقوائمها بعقود واضحة.
## Scope
يشمل جميع الأدوار، ويظهر قسم الإدارة ضمن التطبيق عند السماح.
## Alternatives
رُفضت واجهة مستقلة للسوبر أدمن وتطبيقات متعددة للموديولات.
## Consequences
تقل الازدواجية ويلزم منع Shell من معرفة تفاصيل كل موديول.
## Security
إخفاء الواجهة تحسين تجربة فقط؛ الخلفية مرجع الصلاحية.
## Operations
يبنى ويخدم محلياً بلا CDN أو موارد خارجية.
## Rollback
يسترجع bundle الإصدار السابق مع توافق API.
## Enforcement
فحص routes المسجلة واختبارات عدم الاعتماد على إذن العميل.
## Review
عند دليل تشغيلي على حاجة تطبيق مستقل.
## References
`docs/architecture/overview.md`، `docs/product/personas-and-journeys.md`.
