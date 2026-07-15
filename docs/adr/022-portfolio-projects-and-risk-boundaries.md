---
doc_id: ADR-022
title: حدود المحافظ والمشاريع والمخاطر
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
scope: PortfolioProjects وRisk
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-006
- ADR-016
- ADR-021
review_by: 2027-01-15
---
# ADR-022: حدود المحافظ والمشاريع والمخاطر
## Context
المشروع والخطر يرتبطان بالاستراتيجية وببعضهما لكن لكل منهما دورة حياة وحقائق مختلفة.
## Drivers
منع تكرار بيانات الاستراتيجية وحماية حدود R2 وR3.
## Decision
`PortfolioProjects` يملك التسلسل محفظة ← برنامج ← مشروع وخططه ومعالمه وميزانيته الإدارية؛ `Risk` يملك الخطر والضوابط والمعالجة وKRI، والروابط إلى Strategy أو المشروع معرفات وعقود فقط.
## Scope
المبادرة تبقى في Strategy، والإنجاز من المعالم المعتمدة لا عدد المهام؛ مصفوفة المخاطر وشهيتها مواصفة معتمدة قبل تنفيذ Risk.
## Alternatives
رُفض إدخال المبادرات في تسلسل المشروع وامتلاك المشاريع للمؤشرات ونسخ حقائق المخاطر.
## Consequences
تحتاج الروابط لعقود وتدقيق، وتبقى التقارير مشتقة من المصادر.
## Security
كل رابط وعرض متبادل يخضع للتفويض ولا يكشف تفاصيل المصدر بلا قرار مستقل.
## Operations
تراقب صحة المحافظ والتصعيد وKRIs وخطط المعالجة والمهام المرتبطة.
## Rollback
يحذف الرابط بإجراء مبرر ومدقق؛ لا يحذف المشروع أو الخطر فعلياً كتعويض تلقائي.
## Enforcement
اختبارات ملكية البيانات ومنع النسخ وحدود الربط وقواعد الإنجاز والتصعيد.
## Review
قبل R2 للمشاريع وقبل R3 بعد اعتماد `RISK-SPEC.md`، ثم سنوياً.
## References
`docs/domain/strategy.md`، `docs/plans/release-2-strategy-portfolio.md`، `docs/plans/release-3-risk.md`.
