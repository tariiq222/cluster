---
doc_id: ADR-011
title: CQRS خفيف وحدود المعاملة
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
scope: حالات الاستخدام والمعاملات
supersedes: []
superseded_by: []
related_adrs:
- ADR-002
- ADR-003
- ADR-007
review_by: 2027-01-15
---
# ADR-011: CQRS خفيف وحدود المعاملة
## Context
تحتاج الكتابة اتساقاً محلياً والقراءة إسقاطات قابلة للتوسع دون Event Sourcing.
## Drivers
وضوح مسؤولية Handler، معاملات موثوقة، وفصل القراءة الثقيلة.
## Decision
نستخدم Commands للكتابة وQueries/Read Models للقراءة؛ Handler المستدعي يملك المعاملة والعقود المتزامنة تنضم إليها بلا commit مستقل.
## Scope
لا تمتد المعاملة إلى Queue أو Search أو Object Storage أو شبكة خارجية.
## Alternatives
رُفض Event Sourcing وdistributed transactions وطبقة CQRS ثقيلة.
## Consequences
تكون القراءات المشتقة متسقة نهائياً وتبقى حقائق الأعمال محلية.
## Security
يفوض الأمر قبل التغيير وتبنى القراءة من بيانات مسموح بها.
## Operations
تراقب مدة المعاملة وفشل الإسقاطات وتعيد بناء Read Models.
## Rollback
تعكس الكتابة داخل المعاملة؛ تعالج الآثار المشتقة عبر Outbox وإعادة البناء.
## Enforcement
فحوص تمنع commit في العقود المستدعاة واختبارات المعاملة وRead Model.
## Review
عند ظهور حاجة مثبتة لدفتر أحداث أو معاملة موزعة.
## References
`docs/architecture/overview.md`، `docs/architecture/context-map.md`.
