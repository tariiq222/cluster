---
doc_id: ADR-018
title: سلسلة التوريد المعزولة
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
scope: البناء والحزم والصور والشبكة
supersedes:
- ADR-010
superseded_by: []
related_adrs:
- ADR-012
- ADR-013
- ADR-019
review_by: 2026-10-15
---
# ADR-018: سلسلة التوريد المعزولة
## Context
البناء والتشغيل داخل مركز البيانات بلا اتصال إنترنت خارجي.
## Drivers
سيادة البيانات ومنع تسرب الشبكة والتحكم في الاعتماديات.
## Decision
تستخدم صور OCI ومرايا Composer وnpm وAV داخلية فقط؛ توقع الصور وتنتج SBOM، وNetworkPolicy تمنع egress افتراضياً ولا CDN أو خطوط أو scripts خارجية.
## Scope
يشمل CI/CD والصور والحزم وDNS ووقت التشغيل في البيئة المعزولة.
## Alternatives
رُفض pull وقت التشغيل ومرايا عامة وخدمات SaaS تتطلب تحققاً دورياً.
## Consequences
يتطلب مساراً Offline موثقاً لتغذية المرايا ومعالجة الثغرات.
## Security
فحص أسرار وثغرات وتحقق التوقيع وتسجيل محاولات الخروج.
## Operations
تراقب صحة المرايا وسعة registry وتدير تحديثات الحزم دورياً.
## Rollback
تنشر صورة داخلية موقعة سابقة مع SBOM معروف؛ لا تجلب بديلاً خارجياً.
## Enforcement
`verify-airgap` وفحص URL خارجي وتوقيع الصورة وNetworkPolicy default-deny.
## Review
ربع سنوي وعند إضافة اعتماد أو مرآة.
## References
`docs/data-security/threat-model.md`، `docs/governance/assumptions-constraints.md`.
