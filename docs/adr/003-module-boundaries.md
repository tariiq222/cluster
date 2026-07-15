---
doc_id: ADR-003
title: حدود الموديولات وملكية البيانات
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
scope: العقود والبيانات بين الموديولات
supersedes: []
superseded_by: []
related_adrs:
- ADR-001
- ADR-002
- ADR-007
- ADR-011
review_by: 2027-01-15
---
# ADR-003: حدود الموديولات وملكية البيانات
## Context
تحتاج الموديولات قدرات مشتركة من دون تشابك جداول أو دورات اعتماد.
## Drivers
حماية الملكية، استقلال التغيير، وإمكان الفصل اللاحق.
## Decision
كل حقيقة وجداولها وترحيلاتها يملكها موديول واحد؛ الاعتماد DAG عبر DTOs أو أحداث أو Projection Feeds.
## Scope
لا SQL أو ORM أو joins عابرة للموديولات؛ المراجع بمعرفات وعقود ثابتة.
## Alternatives
رُفضت قاعدة بيانات مشتركة بلا ملكية وواجهات تعيد نماذج ORM.
## Consequences
تزداد العقود والإسقاطات، وينخفض التشابك.
## Security
يحظر الوصول المباشر لبيانات موديول آخر ويقلل الإفصاح.
## Operations
تملك كل وحدة ترحيلاتها وإعادة بناء إسقاطاتها.
## Rollback
تتبع تغييرات العقود ترحيلاً متوافقاً قبل إزالة العقد السابق.
## Enforcement
فحوص DAG وملكية الجداول وcontract tests في CI.
## Review
عند إضافة موديول أو نقل حقيقة بين الموديولات.
## References
`docs/architecture/module-catalog.md`، `docs/architecture/context-map.md`.
