---
doc_id: ADR-013
title: المستندات وأمن الملفات
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
scope: Documents وObject Storage
supersedes:
- ADR-008
superseded_by: []
related_adrs:
- ADR-004
- ADR-007
- ADR-016
- ADR-018
- ADR-019
review_by: 2026-10-15
---
# ADR-013: المستندات وأمن الملفات
## Context
كل الموديولات تحتاج ملفات مصنفة، والملف المرفوع غير موثوق.
## Drivers
أمن المحتوى، إصدارات الملفات، وسياسة وصول موحدة.
## Decision
`Documents` يملك metadata والإصدارات والروابط؛ التخزين S3-compatible محلي، والحجر والفحص fail-closed قبل الإتاحة، والتحميل يعيد التفويض.
## Scope
يتضمن checksum وAV وMIME وZip Bomb وعدم القابلية للتعديل وأشد قيود الرابط.
## Alternatives
رُفضت تخزين الملفات في موديولات الأعمال أو الإتاحة قبل الفحص أو روابط عامة دائمة.
## Consequences
توجد معالجة غير متزامنة وحجر ومراقبة، لكن لا يتسرب ملف من رابط مصدر.
## Security
تشفير، حسابات خدمة منفصلة، روابط موقعة قصيرة، وتدقيق تحميل المحتوى الحساس.
## Operations
يراقب عامل الفحص وDLQ وتحديث توقيعات AV من مرآة داخلية.
## Rollback
يبقى الإصدار السابق immutable؛ الملف الفاشل لا ينقل إلى الإتاحة.
## Enforcement
اختبارات الحجر وMIME وAV والروابط ومدة الرابط والتصنيف.
## Review
ربع سنوي وعند تغيير محرك الفحص أو سياسة MIME.
## References
`docs/data-security/file-security.md`، `docs/architecture/module-catalog.md`.
