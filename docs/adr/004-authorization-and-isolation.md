---
doc_id: ADR-004
title: RBAC + ABAC والعزل التنظيمي
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
scope: كل قرارات الوصول
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-012
- ADR-013
- ADR-014
- ADR-015
review_by: 2026-10-15
---
# ADR-004: RBAC + ABAC والعزل التنظيمي
## Context
الأدوار وحدها لا تكفي للنطاق والعلاقة والتصنيف وحالة السجل.
## Drivers
عزل المنشآت، تفسير القرار، ومنع الاعتماد على الواجهة.
## Decision
`Authorization` يقرر في الخلفية باستخدام RBAC وABAC و`RecordFacts` من الموديول المالك، مع fail-closed.
## Scope
يشمل API والبحث والتقارير والتصدير والتنزيل وصلاحيات الحقول.
## Alternatives
رُفضت RBAC فقط، وفلاتر استعلام متفرقة، وحماية React.
## Consequences
يزيد اختبار مصفوفة القرار لكنه يوحد السلوك ويمنع الدورات.
## Security
المنع الصريح والتصنيف الأعلى يتقدمان على السماح؛ لا يعتمد Authorization على موديولات الأعمال.
## Operations
تسجل القرارات الحساسة وتبطل تغييرات الدور أو التفويض الجلسات المتأثرة.
## Rollback
تعود سياسة منشورة إلى نسخة سابقة مع تدقيق وإعادة تقييم فوري.
## Enforcement
اختبارات المراحل العشر، العزل، fail-closed، وFieldAccess.
## Review
ربع سنوي وعند تغير التصنيف أو حدود الثقة.
## References
`docs/data-security/authorization-model.md`، `docs/architecture/context-map.md`.
