---
doc_id: CON-CAP-ORG-001
title: عقد التكليف المؤقت محدود القدرة
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: مسؤول هندسة البرمجيات
reviewers:
- مالك موديول Organization
- مالك موديول Authorization
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/domain/organization-and-people.md
- docs/data-security/logical-data-model.md
references:
- docs/contracts/api/openapi.yaml
- docs/contracts/api/w1-2.openapi.yaml
---
# عقد التكليف المؤقت محدود القدرة

## الحالة والنتيجة

**حالة التنفيذ:** `planned` — لا توجد routes أو persistence أو أحداث تنفيذية لهذا العقد،
ولا يدخل سطح W1.2 المجمد الحالي. تكليف Position الأساسي المنفذ لا يثبت تنفيذ
TemporaryAssignment.

ينشئ Organization حقيقة سلطة مؤقتة لشخص داخل OrganizationUnit واحدة بعينها، ويقدمها إلى
Authorization. لا يصدر Organization قرار Allow، ولا يحول التكليف إلى Role أو قدرة دائمة.

## سطح HTTP المخطط

| العملية | المسار | الضوابط |
|---|---|---|
| قائمة محكومة | `GET /api/v1/organization/temporary-assignments` | pagination وفلترة ضمن نطاق قرار Authorization |
| إنشاء | `POST /api/v1/organization/temporary-assignments` | `Idempotency-Key` وقرار قدرة خلفي |
| قراءة | `GET /api/v1/organization/temporary-assignments/{temporaryAssignmentId}` | 403/404 آمن خارج النطاق |
| سحب مبكر | `POST /api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke` | `If-Match` و`Idempotency-Key` وسبب إلزامي |

هذه paths محجوزة للعقد التالي من OpenAPI، لكنها غير منفذة ولا تضاف إلى snapshot الحالي قبل
شريحة كود واختبارات مولدة متوافقة.

## طلب الإنشاء والتمثيل

الحقول المطلوبة للإنشاء:

- `person_id`: Person صالح غير مؤرشف.
- `organization_unit_id`: OrganizationUnit موجودة وغير مؤرشفة؛ هذا هو النطاق الوحيد.
- `capability_codes`: قائمة غير فارغة وفريدة من قدرات منشورة في Authorization.
- `start_at` و`end_at`: RFC 3339 UTC منتهيان بـ`Z`.
- `reason`: نص غير فارغ يشرح الحاجة الإدارية.

يعيد التمثيل `id` والحقول أعلاه و`status` (`scheduled|active|expired|revoked`) و
`approved_by_user_id` و`revoked_at` و`revoke_reason` و`lock_version`. لا يعيد PII للشخص،
ولا يعيد قرار وصول أو role أو field policy مشتقة.

## الـinvariants

1. **نطاق دقيق:** كل قدرة مقيدة بـ`organization_unit_id` نفسها فقط. لا تشمل descendants أو
   parent أو Facility أو Cluster، ولا يقبل v1 scope tags أو wildcard أو أكثر من وحدة.
2. **سبب إلزامي:** يرفض الإنشاء أو السحب المبكر إذا كان السبب فارغاً بعد trim، ويسجل السبب
   والفاعل وcorrelation في التدقيق.
3. **لا backdating:** يقارن الخادم `start_at` بساعته عند قبول الأمر؛ يجب ألا يسبقها. لا
   يستطيع العميل إنشاء حقيقة كانت سارية في الماضي.
4. **حد المدة:** يجب أن يكون `end_at > start_at` وألا تتجاوز المدة 90 يوماً. `end_at`
   حصري، والفترة نصف مفتوحة `[start_at, end_at)`.
5. **منع التداخل:** لكل مفتاح
   `(person_id, organization_unit_id, capability_code)` لا تتقاطع فترة scheduled أو active
   مع فترة أخرى غير revoked. تلامس `end_at` مع `start_at` التالي مسموح لأنه ليس تداخلاً.
6. **قدرات صريحة:** لا تستنتج قدرة من position أو type أو parent. كل code يتحقق عبر عقد
   Authorization، ويخزن Organization snapshot الأكواد الممنوحة كحقائق زمنية.
7. **سحب فوري:** عند `end_at` أو revoke لا تعود القدرة ضمن الحقائق الفعالة في الطلب التالي،
   وتبطل cache/الجلسة الإدارية المتأثرة وفق سياسة Authorization وIdentity دون حذف التاريخ.
8. **ذرية وإعادة:** الإنشاء أو السحب وتغيير الحالة وOutbox في معاملة Organization واحدة؛
   replay لا يكرر التكليف أو الحدث.

## تسوية النموذج العام

يصف `docs/data-security/logical-data-model.md` نموذجاً أوسع يحوي `position_id` و
`authority_scope_tags` و`authority_profile_key`. يضيق هذا العقد v1 ذلك النموذج: لا
`position_id` ولا tags ولا profile، بل OrganizationUnit واحدة وقدرات صريحة. يحتاج توسيع
النطاق أو ربط position إصدار عقد جديداً ومراجعة Authorization، ولا يستنتج من النموذج
المفاهيمي القديم.

## حالات الفشل

- وحدة أو Person أو capability غير قابلة للحل: فشل آمن بلا سجل جزئي.
- start في الماضي أو مدة غير صالحة: خطأ validation بلا تقريب أو تعديل صامت للتاريخ.
- تداخل القدرة نفسها: `409` مع كود ثابت من دون كشف تكليف خارج نطاق actor.
- `If-Match` قديم عند revoke: `412` ولا يغير السجل.
- فشل Outbox أو مصدر حقائق Authorization: rollback أو deny، ولا منح متفائل.

## معايير القبول

- قدرة على وحدة لا تسري على parent أو child أو وحدة شقيقة.
- يرفض reason فارغاً، وstart في الماضي، ومدة أكبر من 90 يوماً.
- يرفض تداخل capability نفسها للمفتاح نفسه، ويسمح بفترتين متلامستين أو بقدرتين مختلفتين.
- expiration وrevoke يسحبان الأثر فوراً مع بقاء سجل التاريخ.
- كل API والبحث والتقرير والتصدير يستخدم قرار Authorization نفسه ولا يقرأ جدول التكليف
  مباشرة من موديول آخر.
