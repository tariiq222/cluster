---
doc_id: ADR-007
title: Transactional Outbox للأحداث الداخلية
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
scope: الأحداث غير المتزامنة
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-011
- ADR-017
review_by: 2026-10-15
---
# ADR-007: Transactional Outbox للأحداث الداخلية
## Context
الإشعارات والفهارس والإسقاطات لا يجوز أن تفقد حدث تغيير ناجح.
## Drivers
تسليم موثوق من دون معاملة موزعة.
## Decision
يحفظ المنتج تغييره وOutbox في المعاملة نفسها؛ العامل يسلم after-commit at-least-once والمستهلك idempotent عبر `event_id` وInbox.
## Scope
للإشعارات والفهرسة وRead Models والتدقيق غير الحرج، لا لإخفاء تدفق تجاري متزامن.
## Alternatives
رُفض النشر قبل commit أو بعده بلا Outbox وEvent Sourcing.
## Consequences
اتساق نهائي ومراقبة تأخير وإعادة معالجة مطلوبة.
## Security
يحمل الحدث schema version وبيانات دنيا؛ لا يكتب payload حساساً كاملاً في السجلات.
## Operations
retry محدود وDLQ وتنبيه ومقاييس lag وإعادة تشغيل آمنة.
## Rollback
المستهلكات قابلة لإعادة المعالجة؛ يعطل المستهلك المعطوب بلا إبطال للحقيقة المصدرية.
## Enforcement
اختبار المعاملة الواحدة وidempotency وschema compatibility وDLQ.
## Review
ربع سنوي وعند إضافة وسيط أحداث.
## References
`docs/architecture/overview.md`، `docs/architecture/diagrams/outbox-sequence.mmd`.
