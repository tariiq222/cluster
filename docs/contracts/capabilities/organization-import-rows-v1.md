---
doc_id: CON-CAP-IMP-001
title: عقد صفوف استيراد Organization الإصدار الأول
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: مسؤول هندسة البرمجيات
reviewers:
- مالك موديول Organization
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/024-organization-identity-import-boundaries.md
- docs/domain/organization-and-people.md
- docs/contracts/api/openapi.yaml
references:
- docs/contracts/capabilities/document-signed-direct-upload.md
- docs/contracts/schemas/facilities-import-row-v1.schema.json
- docs/contracts/schemas/organization-units-import-row-v1.schema.json
- docs/contracts/schemas/positions-import-row-v1.schema.json
---
# عقد صفوف استيراد Organization الإصدار الأول

## الحالة والنطاق

**حالة التنفيذ:** `planned` — دورة ImportJob الحالية منفذة لقالب `people_assignments`، أما
parsers وapplicators للقوالب الثلاثة في هذا العقد فغير منفذة. نشر schemas لا يعني أن bytes
تُنقل أو أن الصفوف تطبق الآن.

يثبت الإصدار الأول صف create واحداً يطابق payload عملية Create الحالية لكل من
`facilities` و`organization_units` و`positions`. لا يضيف حقول persistence أو response،
ولا يضم update أو archive أو move. يبقى `people_assignments` خارج هذا العقد.

## تعيين القالب إلى schema

- `facilities`: يستخدم [schema v1](../schemas/facilities-import-row-v1.schema.json). يطلب
  `cluster_id` و`type_code` و`code` و`name`، ويسمح بـ`name_en`.
- `organization_units`: يستخدم
  [schema v1](../schemas/organization-units-import-row-v1.schema.json). يطلب `cluster_id`
  و`code` و`name` و`type_code`، ويسمح بـ`parent_id` و`name_en`.
- `positions`: يستخدم [schema v1](../schemas/positions-import-row-v1.schema.json). يطلب
  `organization_unit_id` و`code` و`title`، ويسمح بـ`manager_position_id`.

يبقى request الحالي بلا `template_version`. يربط الخادم كل `template_code` أعلاه بـv1
server-side، ويحفظ schema id/hash المستخدم مع ImportJob. إضافة version إلى HTTP أو تغيير
اسم أو نوع أو قيد حقل يتطلب إصدار عقد جديد، لا تعديلاً صامتاً على v1.

## التطبيع والتحقق البنيوي

- صف CSV الأول أو صف XLSX المحدد header يستخدم أسماء الحقول أعلاه حرفياً. الأعمدة
  الإضافية أو المكررة مرفوضة؛ لا يحول parser أسماء domain مثل `name_ar` أو `title_ar` إلى
  أسماء جديدة بصمت.
- UUIDs هي UUIDv7 lowercase. تطبق أنماط `type_code` و`code` وأطوال النصوص نفسها في Create
  API. الفراغ ليس قيمة لحقل مطلوب.
- الخانة الفارغة لحقل اختياري تطبع إلى `null` أو غياب الحقل وفق schema؛ لا تطبع UUID أو
  نصاً مطلوباً إلى قيمة مصطنعة.
- `additionalProperties=false` في schemas الثلاثة. لا يقبل parser حقول status أو id أو
  lock_version أو path أو depth أو actor أو أسرار Identity.

## الـinvariants الدلالية المطابقة لعمليات Create

### facilities

- `cluster_id` يشير إلى Cluster الموجود الوحيد.
- `type_code` يشير إلى FacilityType فعال ومحكوم.
- `code` فريد داخل Cluster. التعارض لا يتحول إلى update ضمن v1.

### organization_units

- `cluster_id` موجود. غياب `parent_id` أو مساواته `cluster_id` يعني parent من نوع Cluster.
- وإلا يجب أن يكون parent منشأة غير مؤرشفة أو OrganizationUnit غير مؤرشفة داخل Cluster
  نفسه. يرفض parent خارج التجمع أو غير صالح.
- `type_code` فعال، و`code` فريد داخل `(parent_type, parent_id)`. يحسب التطبيق
  `parent_type` و`path_cache` و`depth`؛ لا يقبلها من الملف.

### positions

- `organization_unit_id` يشير إلى OrganizationUnit موجودة.
- `manager_position_id`، إن وجد، يشير إلى Position موجود ولا ينشئ self-reference أو دورة
  إدارية.
- `code` فريد داخل OrganizationUnit. يحسب التطبيق `is_active` و`lock_version` ولا يقبلهما
  من الملف.

## دورة الاستيراد والفشل

1. ينشأ ImportJob فقط من `quarantine_object_id` نتيجته `clean` وغرضه Organization import
   مطابق للقالب؛ لا تصل Organization إلى Object Storage مباشرة.
2. يفك parser الملف إلى صفوف مطبعة ويطبق schema ثم invariants المالك بلا كتابة أعمال.
3. يخزن payload الخام مشفراً، ويعرض API أخطاء منقحة بالصف والحقل والكود فقط.
4. أي خطأ Critical، ومنها حقل مطلوب ناقص أو parent/type غير صالح، يجعل الملف كله غير قابل
   للتطبيق. لا يكتب صف سليم منفرداً.
5. يختلف الرافع عن المعتمد. بعد `approved` فقط يطبق Organization كل الصفوف وOutbox في
   معاملة واحدة، ويعيد replay idempotently.

رغم أن وثيقة المجال العامة تذكر `ImportDecision=Update`، فإن v1 هنا create-only بما يطابق
Create API و`ImportJobRow.proposed_action` المنشور للشريحة الحالية (`create|skip`). يحتاج
update قالباً وإصدار schema وعقد تعارض منفصلين.

## معايير القبول

- كل fixture صالح وفق schema ينتج payload مطابقاً لعملية Create المقابلة بلا حقول زائدة.
- حقل مطلوب ناقص أو نمط code خاطئ أو UUID غير UUIDv7 يفشل التحقق قبل apply.
- parent أو type أو manager غير صالح يفشل الملف كله بلا كتابة جزئية أو Outbox ناقص.
- replay لنفس ImportJob لا ينشئ الموارد أو الأحداث مرتين.
- الخطأ وlog وevent لا يعيدان raw row أو اسم ملف حساس أو `quarantine_object_id`.
