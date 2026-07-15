---
doc_id: ADR-016
title: التدقيق وحوكمة السجلات
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
scope: Audit وRecordsGovernance
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-007
- ADR-013
- ADR-019
review_by: 2026-10-15
---
# ADR-016: التدقيق وحوكمة السجلات
## Context
تحتاج المنصة دليلاً غير قابل للتعديل للأعمال الحساسة ودورة حياة للسجلات والمستندات.
## Drivers
المساءلة والاحتفاظ والحجز والإتلاف المتوافق.
## Decision
`Audit` append-only للحوادث الحساسة، و`RecordsGovernance` يملك سياسة الاحتفاظ والحجز وقرار الإتلاف بينما ينفذ المالك الإتلاف ويؤكد نتيجته.
## Scope
يشمل الوصول الحساس والتنزيل والتصدير والحجز والاستبقاء، ولا يملك payload المصدر.
## Alternatives
رُفض سجل نشاط قابل للحذف داخل كل موديول وحذف مباشر بلا قرار حوكمة.
## Consequences
تزداد كتابة التدقيق ومراجعة الإتلاف، وتتحسن قابلية الإثبات.
## Security
Hash chain وتصدير تدقيق موقّع وفصل أقل صلاحيات للحجز والإتلاف.
## Operations
تراقب سلامة السلسلة والتصدير والاستبقاء والطلبات المستحقة.
## Rollback
لا يحذف حدث تدقيق؛ يعالج القرار الخاطئ بحدث تصحيحي، ويلغى الحجز بقرار موثق.
## Enforcement
اختبارات append-only والحجز ومنع الإتلاف والتدقيق قبل الاستجابة.
## Review
ربع سنوي ومع أي تغير تنظيمي أو قانوني.
## References
`docs/data-security/audit-and-privacy.md`، `docs/data-security/retention-and-legal-hold.md`.
