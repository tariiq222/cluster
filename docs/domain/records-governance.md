---
doc_id: DOM-RGV-001
title: حوكمة السجلات والاحتفاظ والحجز
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول RecordsGovernance
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: confidential
review_cycle: مع كل تغيير
sources:
- docs/adr/016-audit-and-records-governance.md
- docs/architecture/dependency-rules.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/retention-and-legal-hold.md
---
# حوكمة السجلات والاحتفاظ والحجز

## الغرض والنطاق

يمتلك `RecordsGovernance` سياسات الاحتفاظ، وموضوعات الحوكمة المرتبطة بـ`record_ref`، والحجز، وأهلية الإتلاف وقرارها وإثباتها. لا يملك payload أو ملفاً أو حذفاً في موديول المصدر؛ المالك ينفذ الإتلاف في معاملته ويؤكد النتيجة.

## الكيانات والجداول والقيود

| الجدول | الحقيقة | القيود |
|---|---|---|
| `retention_policy_versions`, `retention_rules` | سياسة احتفاظ versioned وقواعدها | المنشور immutable؛ قاعدة فعالة واحدة للمطابقة |
| `governed_records` | `record_ref`، السياسة، تاريخ الاستحقاق والحالة | فريد `(record_type, record_id)` |
| `record_holds`, `record_hold_targets` | الحجز ونطاقه وسببه ومدته | الحجز النشط يمنع الإتلاف؛ فريد للهدف والحجز |
| `disposition_reviews`, `disposition_evidence` | أهلية الإتلاف وقراراته وإثباته | لا approval بلا أهلية ولا hold نشط |

## الأوامر والاستعلامات والأحداث والحالات

**Commands:** `PublishRetentionPolicyVersion`, `RegisterGovernedRecord`, `PlaceRecordHold`, `ReleaseRecordHold`, `DecideDispositionEligibility`, `ConfirmDispositionOutcome`.
**Queries:** `ResolveRetentionPolicy`, `GetRecordGovernanceStatus`, `GetActiveRecordHolds`, `GetDispositionEligibility`.
**Events:** `RecordHoldPlaced`, `RecordHoldReleased`, `RecordDispositionDue`, `RecordDispositionApproved`, `RecordDispositionCompleted`.

```text
GovernedRecord: Active -> Due -> UnderReview -> Disposed | Retained
Hold: Active -> Released | Expired | Superseded
DispositionReview: Pending -> Eligible -> Approved -> Completed | Rejected
```

## الثوابت والأمن والفشل

- الحجز النشط يعلّق الإتلاف ولا يمكن لمالك السجل أو الأدمن تجاوزه.
- الحوكمة تسجل القرار فقط؛ مصدر السجل يعيد التحقق من الحجز ثم ينفذ الإتلاف ويؤكد النتيجة.
- كل قرار وصول وإتلاف يخضع Authorization وAudit؛ لا يحل قبول إداري محل المسار أو فصل الواجبات المطلوب.
- تعذر قراءة hold أو سياسة أو تأكيد المصدر يساوي منعاً آمناً للإتلاف. فشل Outbox يلف قرار الحوكمة؛ فشل إتلاف المصدر لا يغلق المراجعة.

## الاختبارات والاعتماديات

- حجز فعال يمنع eligibility والإتلاف، ورفعه يعيد التقييم لا الإتلاف التلقائي.
- مصدر لا يؤكد النتيجة لا يجعل disposition مكتملة، وإعادة الحدث idempotent.
- حدود: لا يقرأ payload ولا يحذف في WorkRecords أو Documents، واختبار fail-closed لعقد المصدر.

يعتمد على PlatformSettings وAuthorization وAudit، ويقدم قرارات إلى WorkRecords وDocuments وباقي ملاك السجلات عبر العقد فقط.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول RecordsGovernance | إنشاء المواصفة المعتمدة |
