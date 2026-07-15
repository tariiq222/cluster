---
doc_id: ADR-017
title: مساحة العمل والإشعارات المشتقة
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
scope: Workspace وNotifications
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-007
- ADR-011
- ADR-014
review_by: 2027-01-15
---
# ADR-017: مساحة العمل والإشعارات المشتقة
## Context
يحتاج المستخدم صندوق عمل موحداً وتنبيهات من مصادر متعددة من دون امتلاك حالة المصدر.
## Drivers
تجربة موحدة وفصل مصادر الأعمال عن المستهلكات الطرفية.
## Decision
`Workspace` و`Notifications` يستهلكان الأحداث ويحفظان مؤشرات مشتقة فقط؛ ينقل الرابط إلى endpoint المالك لإعادة التفويض والتنفيذ.
## Scope
الإشعارات داخل المنصة فقط في المرحلة الأولى، ولا بريد أو SMS أو WhatsApp.
## Alternatives
رُفضت تحديثات متزامنة من المصدر وتكرار payload أو حالة المصدر.
## Consequences
توجد نافذة اتساق نهائي وإعادة بناء Inbox، وتبقى الملكية سليمة.
## Security
الإشعار لا يمنح رؤية؛ تعاد الصلاحية عند الفتح ولا يحوي payload حساساً.
## Operations
تجميع ومنع تكرار وretry وDLQ ومراقبة التأخير.
## Rollback
تعاد بناء الإسقاطات من الأحداث ولا تحتاج معاملة تعويضية للمصدر.
## Enforcement
اختبارات idempotency وإعادة التفويض وعدم كتابة المصدر.
## Review
عند إضافة قناة إشعار معتمدة أو مصدر عمل جديد.
## References
`docs/architecture/module-catalog.md`، `docs/architecture/context-map.md`.
