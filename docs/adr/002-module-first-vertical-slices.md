---
doc_id: ADR-002
title: Module-First Vertical Slices
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
scope: تنظيم الكود
supersedes: []
superseded_by: []
related_adrs:
- ADR-001
- ADR-003
- ADR-011
review_by: 2027-01-15
---
# ADR-002: Module-First Vertical Slices
## Context
التنظيم الطبقي العام يشتت حالة الاستخدام، والشرائح بلا موديولات تضعف ملكية المجال.
## Drivers
تجميع ما يتغير معاً ووضوح الاختبار والمراجعة.
## Decision
الموديول هو الحد الأعلى؛ داخله تنظم الكتابة والقراءة حسب حالة الاستخدام، وDomain مشترك للموديول.
## Scope
يشمل Laravel وReact؛ Controller رفيع وHandler منسق وSlice تملك اختباراتِها.
## Alternatives
رُفضت الطبقات الأفقية العامة ونسخ Domain داخل كل Slice.
## Consequences
يتحسن التركيز لكن يلزم منع Shared عام لسياسات المجال.
## Security
كل Slice يستدعي التفويض الخلفي قبل القراءة أو الكتابة.
## Operations
لا يضيف مكونات تشغيلية مستقلة.
## Rollback
يمكن عكس Slice في إصدار التطبيق مع ترحيلها المملوك.
## Enforcement
قوالب الموديولات وفحوص namespace والاعتماد في CI.
## Review
عند ظهور حالة استخدام مشتركة حقيقية بين موديولين.
## References
`docs/architecture/overview.md`، `docs/architecture/context-map.md`.
