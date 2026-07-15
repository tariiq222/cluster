---
doc_id: ADR-012
title: الهوية المحلية وأمن الجلسات
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
scope: Identity والجلسات والحسابات المميزة
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-018
- ADR-020
review_by: 2026-10-15
---
# ADR-012: الهوية المحلية وأمن الجلسات
## Context
التشغيل المعزول لا يملك موفر هوية خارجي، وتحتوي المنصة PII وظيفية.
## Drivers
استقلال محلي وحماية الحسابات والجلسات والإدارة.
## Decision
الحسابات محلية؛ كلمات المرور Argon2id بحدود غير قابلة للتخفيض، وجلسات httpOnly قصيرة، وMFA للحسابات الإدارية، واسترداد ثنائي الإدارة وBreak-glass محكوم.
## Scope
يشمل حسابات المستخدمين والخدمات والإدارة والطوارئ.
## Alternatives
رُفضت المصادقة الخارجية الإلزامية وحفظ كلمات المرور أو الأسرار القابلة للاسترجاع.
## Consequences
يتطلب تشغيل دورة حياة الحساب محلياً وتدقيقاً دقيقاً.
## Security
قفل تدريجي، CSRF، إنهاء الجلسات عند التغيير الحساس، وفصل الحساب اليومي عن الإداري.
## Operations
تنبيه على القفل والاسترداد والطوارئ وتدوير الأسرار ضمن البيئة.
## Rollback
تعطل السياسة أو تغييرات الحساب بإصدار سياسة مدقق؛ لا يعاد تفعيل جلسة ملغاة.
## Enforcement
اختبارات Argon2id والقفل وMFA والاسترداد الثنائي وBreak-glass.
## Review
ربع سنوي وعند تغير تهديدات الهوية.
## References
`docs/data-security/identity-session-security.md`، `docs/data-security/threat-model.md`.
