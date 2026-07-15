---
doc_id: DOM-AUD-001
title: التدقيق غير القابل للتعديل
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Audit
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: confidential
review_cycle: مع كل تغيير
sources:
- docs/adr/016-audit-and-records-governance.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/audit-and-privacy.md
---
# التدقيق غير القابل للتعديل

## الغرض والنطاق

يمتلك `Audit` السجل الأمني والتشغيلي append-only للأفعال الحساسة، وقراءات المحتوى والتصدير والتنزيل، وروابط correlation وcausation والفاعل والأصيل عند التفويض. لا يملك سجل النشاط المفهوم للمستخدم داخل موديول المصدر، ولا يصدر قرار وصول.

## الكيانات والجداول والقيود

| الجدول | الحقيقة | القيود |
|---|---|---|
| `audit_events` | حدث تدقيق immutable وpayload منقح | `event_id` فريد؛ فهرس `(occurred_at, actor_user_id, action)` |
| `sensitive_access_events` | قراءة/تنزيل/تصدير حساس وقرار الوصول | فريد idempotency؛ لا محتوى مصدر |
| `audit_hash_links` | سلسلة hash للسلامة | predecessor واحد وتسلسل متصل |
| `audit_checkpoints` | نقطة تحقق وتوقيع دوري | append-only وموقعة |

## الأوامر والاستعلامات والأحداث والحالات

**Commands:** `AppendCriticalAuditEvent`, `RecordSensitiveAccess`, `VerifyAuditIntegrity`.
**Queries:** `QueryAuthorizedAuditTrail`, `GetAuditEvent`, `VerifyAuditCheckpoint`.
**Events:** `CriticalAuditEventAppended`, `SensitiveAccessRecorded`, `AuditIntegrityCheckFailed`.
**الحالات:** الحدث `Appended` نهائي؛ التصدير المؤقت `Generated -> Expired -> Disposed`.

## الثوابت والأمن والفشل

- لا update أو delete أو تصحيح صامت؛ التصحيح حدث جديد ذو causation واضح.
- لا يسجل password أو token أو payload أعمال أو ملف؛ يخزن المعرفات وhash والحقول اللازمة للتفسير فقط.
- كل استعلام تدقيق يعاد تفويضه عبر Authorization، والتصدير يراجع الحقول ويسجل نفسه.
- إذا كانت سياسة المصدر تتطلب تسجيل الوصول قبل العرض وفشل `RecordSensitiveAccess`، يمنع المصدر النتيجة. أخطاء الاستهلاك غير الحرج تدخل retry ولا تغير المصدر.

## الاختبارات والاعتماديات

- منع update/delete، والتحقق من hash chain، ورفض payload سري.
- تسجيل الفاعل والأصيل في تفويض، وتفويض بحث/تصدير التدقيق.
- idempotency وOutbox، وفشل التدقيق الحرج يمنع العملية التي تشترطه.

يعتمد على Authorization لحماية القراءة وعلى Outbox التقنية؛ يستهلك أحداث جميع الموديولات ولا يستدعيه Authorization متزامناً، منعاً للدورة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Audit | إنشاء المواصفة المعتمدة |
