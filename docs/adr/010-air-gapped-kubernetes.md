---
doc_id: ADR-010
title: Kubernetes معزول
type: adr
status: superseded
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
scope: التشغيل المعزول والبنية
supersedes: []
superseded_by:
- ADR-018
- ADR-019
related_adrs:
- ADR-007
- ADR-012
- ADR-013
review_by: 2026-07-15
---
# ADR-010: Kubernetes معزول
## Context
المنصة داخل مركز البيانات وتحتاج توفرية عالية بلا إنترنت خارجي.
## Drivers
سيادة البيانات وسلامة سلسلة التوريد والتعافي.
## Decision
كان القرار يجمع Air-gap وKubernetes؛ استبدل بتقسيم قابل للإنفاذ بين ADR-018 لسلسلة التوريد وADR-019 للتشغيل والتعافي.
## Scope
هذا السجل تاريخي فقط.
## Alternatives
رُفضت الخدمات السحابية العامة والبناء أو التشغيل المتصلين بالإنترنت.
## Consequences
التقسيم يفصل ضوابط الصور والحزم عن التوافر والنسخ الاحتياطي.
## Security
تبقى سياسة منع egress والتوقيع ملزمة عبر ADR-018.
## Operations
تبقى أهداف RPO/RTO والتشغيل متعدد النسخ ملزمة عبر ADR-019.
## Rollback
لا عودة إلى القرار الجامع؛ تعدل السجلات اللاحقة.
## Enforcement
تطبق ضوابط ADR-018 وADR-019.
## Review
مغلق لأنه مستبدل.
## References
ADR-018، ADR-019، `docs/data-security/threat-model.md`.
