---
doc_id: DOM-WRC-001
title: سجلات العمل الديناميكية
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول WorkRecords
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/authorization-model.md
---
# Work Records

## 1. الغرض

يمثل هذا المجال السجلات التشغيلية الديناميكية التي تنشأ من نوع عمل منشور، ويحفظ envelope ثابتاً وpayload مرتبطاً بإصدار تعريف محدد. WorkRecords مستقل عن WorkDefinitions: الأول يملك حقيقة السجل وحالته ومالكه وإصداره ونشاطه، والثاني يملك schema والتعريف. كما أنه مستقل عن Workflow؛ المسار ينفذ خطواته، لكن WorkRecords يملك معنى إنشاء السجل وإرساله وإغلاقه.

كل كتابة على سجل يقودها aggregate المالك عبر owner-led transaction. لا توجد Transaction عامة يملكها التطبيق كله، ولا يكتب موديول آخر مباشرة في جداول WorkRecords. عند الحاجة إلى Workflow أو Tasks أو Documents يستدعي WorkRecords عقوداً معلنة، وتبقى حقيقة السجل ومعاملة أمره تحت مالك WorkRecords.

## 2. النطاق

- إنشاء سجل ديناميكي من Work Type Version منشور.
- حفظ owner وorganization unit وclassification وstatus وresponsible user.
- حفظ payload typed أو JSON مرتبط بالإصدار، مع إسقاطات بحث وتقارير مختارة.
- تطبيق schema وDSL validation قبل الحفظ أو الانتقال.
- إدارة المسودة والإرسال والإعادة والرفض والإنجاز والإلغاء وفق lifecycle التعريف.
- إدارة المشاركين والمشاركة الصريحة وروابط السجلات.
- توفير `AuthorizationRecordFacts` من مصدر الحقيقة للسجل.
- إصدار أحداث السجل إلى Outbox للبحث والإشعارات والقراءات المشتقة.

ما لا يدخل في هذا المجال:

- تعريف نوع العمل أو تعديل schema.
- تعريف أو تنفيذ workflow nodes وقرارات الموافقة.
- تخزين الملفات نفسها، ويملكها Documents.
- إدارة كلمة المرور أو الدور أو العلاقة التنظيمية.
- كتابة جداول Strategy أو PortfolioProjects أو Risk؛ تلك موديولات متخصصة مستقلة.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| سجل العمل (Work Record) | وحدة تشغيلية مستقلة تحمل envelope وpayload وإصدار التعريف والحالة. |
| مالك السجل (Record Owner) | الوحدة التنظيمية Cluster أو Facility أو Unit المسؤولة عن السجل، وقد يكون لها owner user. |
| إصدار التعريف | `work_type_version_id` المنشور الذي يثبت schema والحقول عند إنشاء السجل. |
| Envelope | الأعمدة الثابتة اللازمة للهوية والمالك والتصنيف والحالة والإصدار والتدقيق. |
| Payload | القيم الديناميكية typed المرتبطة بإصدار التعريف، وليست مصدراً للصلاحيات وحدها. |
| إسقاط typed | قيمة مفهرسة لحقل اختاره التعريف للبحث أو التقارير، مشتقة من payload. |
| مشاركة (Sharing) | منح رؤية صريحة لوحدة أو مستخدم أو دور بحسب سياسة Authorization. |
| حالة السجل | Lifecycle state يحدده النوع، ولا يتغير إلا بانتقال معرف ومخول. |
| Transaction Owner | الـHandler الذي يقود أمر aggregate ويملك commit أو rollback وآثاره في Outbox. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 WorkRecordAggregate

- `WorkRecord` (Entity جذر): record_id، type/version، owner، creator، responsible، classification، lifecycle_state، lock_version.
- `RecordEnvelope` (Value Object): المعرف والمالك والحالة والتصنيف والإصدار.
- `RecordOwner` (Value Object): owner_scope_type وowner_scope_id مع Cluster > Facility > Unit.
- `RecordClassification` (Value Object).
- `RecordVersion` (Value Object) للقفل التفاؤلي.

### 4.2 WorkPayloadAggregate

- `WorkPayload` (Entity تابعة): payload وschema version وnormalized hash.
- `TypedFieldProjection` (Entity تابعة): field_key، typed_value، visibility metadata.
- `RecordRelation` (Entity تابعة): relation_key، target_type، target_id، authorization reference.

### 4.3 RecordCollaborationAggregate

- `RecordParticipant` (Entity تابعة): user أو unit ودور المشاركة ومدة المشاركة.
- `RecordActivity` (Entity تابعة): فعل مفهوم للمستخدم، actor، before/after summary، reason.
- لا تمنح المشاركة الوصول إلى كل الحقول؛ يعاد طلب القرار باستخدام `AuthorizationRecordFacts` و`ResolveFieldAccess`.

### 4.4 AuthorizationRecordFacts

ينشئ WorkRecords DTO الحقائق التالية من السجل نفسه:

- owner cluster/facility/unit.
- creator وowner user وresponsible user.
- المشاركون والوحدات المشتركة.
- classification وlifecycle/workflow state.
- type/version وfield_policy_key.
- lock/version ووقت الحقائق.

لا يحتوي DTO payload غير المطلوب ولا يملك قرار Allow أو Deny.

## 5. الجداول والقيود والفهارس

### 5.1 `work_records`

- `id` BIGINT PK.
- `record_number` VARCHAR(64) NOT NULL.
- `work_type_id` BIGINT NOT NULL، معرف مرجعي يملكه WorkDefinitions.
- `work_type_version_id` BIGINT NOT NULL، يجب أن يكون Published عند الإنشاء.
- `owner_scope_type` VARCHAR(16) NOT NULL (`cluster`، `facility`، `unit`).
- `owner_scope_id` BIGINT NOT NULL.
- `owner_facility_id` BIGINT NULL.
- `owner_organization_unit_id` BIGINT NULL.
- `created_by_user_id` BIGINT NOT NULL.
- `owner_user_id` BIGINT NULL.
- `responsible_user_id` BIGINT NULL.
- `status` VARCHAR(32) NOT NULL DEFAULT `draft`.
- `classification` VARCHAR(32) NOT NULL DEFAULT `internal`: `public|internal|confidential|top_secret`.
- `lock_version` BIGINT NOT NULL DEFAULT 1.
- `submitted_at` DATETIME NULL.
- `completed_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- قيد فريد على `(record_number)`.
- قيد اتساق: owner_scope_type يطابق المعرف الموافق، وFacility أو Unit ينتمي إلى Cluster نفسه.
- فهارس: `(work_type_id, status)`، `(work_type_version_id, status)`، `(owner_scope_type, owner_scope_id, status)`، `(owner_facility_id, status)`، `(owner_organization_unit_id, status)`، `(responsible_user_id, status)`، `(classification, status)`، `(created_at)`.

### 5.2 `work_record_payloads`

- `id` BIGINT PK.
- `work_record_id` BIGINT NOT NULL FK -> `work_records.id` ON DELETE RESTRICT.
- `work_type_version_id` BIGINT NOT NULL.
- `payload` JSON NOT NULL.
- `payload_hash` CHAR(64) NOT NULL.
- `schema_validated_at` DATETIME NOT NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- قيد فريد على `(work_record_id, work_type_version_id)`.
- فهرس: `(work_type_version_id, payload_hash)`.
- لا يسمح بربط payload بإصدار مختلف عن envelope.

### 5.3 `work_record_field_projections`

- `id` BIGINT PK.
- `work_record_id` BIGINT NOT NULL FK -> `work_records.id` ON DELETE CASCADE.
- `work_type_version_id` BIGINT NOT NULL.
- `field_key` VARCHAR(96) NOT NULL.
- `field_type` VARCHAR(32) NOT NULL.
- `string_value` VARCHAR(1024) NULL.
- `number_value` DECIMAL(24,8) NULL.
- `date_value` DATE NULL.
- `datetime_value` DATETIME NULL.
- `boolean_value` BOOLEAN NULL.
- `search_visibility` VARCHAR(16) NOT NULL DEFAULT `eligible`.
- `projection_version` VARCHAR(32) NOT NULL.
- قيد فريد على `(work_record_id, field_key, projection_version)`.
- فهارس typed على `(field_key, string_value)`، `(field_key, number_value)`، `(field_key, date_value)`، `(work_type_version_id, field_key)`.
- القيم لا تعد مصدراً مستقلاً، وتولد من payload مع سياسة field access.

### 5.4 `work_record_relations`

- `id` BIGINT PK.
- `work_record_id` BIGINT NOT NULL FK -> `work_records.id` ON DELETE CASCADE.
- `relation_key` VARCHAR(96) NOT NULL.
- `target_type` VARCHAR(96) NOT NULL.
- `target_id` BIGINT NOT NULL.
- `created_by_user_id` BIGINT NOT NULL.
- قيد فريد على `(work_record_id, relation_key, target_type, target_id)`.
- فهارس: `(target_type, target_id)`، `(work_record_id, relation_key)`.

### 5.5 `work_record_participants`

- `id` BIGINT PK.
- `work_record_id` BIGINT NOT NULL FK -> `work_records.id` ON DELETE CASCADE.
- `participant_type` VARCHAR(16) NOT NULL (`user`، `unit`).
- `participant_id` BIGINT NOT NULL.
- `participant_role` VARCHAR(32) NOT NULL.
- `start_at` DATETIME NOT NULL.
- `end_at` DATETIME NULL.
- `added_by_user_id` BIGINT NOT NULL.
- قيد يمنع مدة غير صالحة، وفريد تشغيلي يمنع المشاركة النشطة المكررة.
- فهارس: `(work_record_id, end_at)`، `(participant_type, participant_id, end_at)`، `(participant_role)`.

### 5.6 `work_record_activities`

- `id` BIGINT PK.
- `work_record_id` BIGINT NOT NULL FK -> `work_records.id` ON DELETE CASCADE.
- `activity_type` VARCHAR(64) NOT NULL.
- `actor_user_id` BIGINT NOT NULL.
- `from_state` VARCHAR(32) NULL.
- `to_state` VARCHAR(32) NULL.
- `change_summary` JSON NOT NULL.
- `reason` VARCHAR(1000) NULL.
- `created_at` DATETIME NOT NULL.
- فهارس: `(work_record_id, created_at)`، `(actor_user_id, created_at)`، `(activity_type, created_at)`.

## 6. الأوامر والاستعلامات والأحداث

### 6.1 Commands

- `CreateWorkRecord`
- `SaveWorkRecordDraft`
- `UpdateWorkRecordDraft`
- `AddWorkRecordRelation`
- `AddWorkRecordParticipant`
- `RemoveWorkRecordParticipant`
- `SubmitWorkRecord`
- `ReturnWorkRecordForRevision`
- `RejectWorkRecord`
- `StartWorkRecordProcessing`
- `CompleteWorkRecord`
- `CancelWorkRecord`
- `TransferRecordOwnership`
- `UpdateWorkRecordClassification`
- `GetAuthorizationRecordFacts` ليس كتابة، لكنه عقد مملوك لمالك السجل.

كل أمر كتابة يمر عبر WorkRecords Handler، يفحص Authorization، يحمل aggregate، يطبق domain transition، يحفظ payload/activity/outbox في Transaction واحدة يقودها WorkRecords.

### 6.2 Queries

- `GetWorkRecord`
- `GetWorkRecordAuthorizedView`
- `ListMyWorkRecords`
- `ListOwnerInbox`
- `ListWorkRecordsByOrganizationScope`
- `GetWorkRecordPayload`
- `GetTypedFieldProjection`
- `GetWorkRecordActivity`
- `GetWorkRecordRelations`
- `GetAuthorizationRecordFacts`
- `GetPublishedVersionForRecord`
- `BuildWorkRecordReadModel`

كل Query يطلب قرار Authorization قبل payload أو field projection، ولا يعيد record title أو snippet إذا كان السجل محظوراً.

### 6.3 Domain وApplication Events

- `WorkRecordCreated`
- `WorkRecordDraftSaved`
- `WorkRecordSubmitted`
- `WorkRecordReturnedForRevision`
- `WorkRecordRejected`
- `WorkRecordProcessingStarted`
- `WorkRecordCompleted`
- `WorkRecordCancelled`
- `WorkRecordOwnershipTransferred`
- `WorkRecordClassificationChanged`
- `WorkRecordParticipantAdded`
- `WorkRecordParticipantRemoved`
- `WorkRecordPayloadChanged`
- `WorkRecordAuthorizationFactsChanged`

يكتب WorkRecords event وOutbox row في Transaction واحدة. Search وNotifications وReporting مستهلكات Idempotent ولا تغير السجل المصدر.

## 7. State Machines

### 7.1 WorkRecord

- `Draft` --(SubmitWorkRecord after schema validation)--> `Submitted`.
- `Submitted` --(workflow required)--> `InApproval`.
- `Submitted` --(no approval required)--> `InProcessing`.
- `InApproval` --(return)--> `ReturnedForRevision`.
- `ReturnedForRevision` --(resubmit)--> `Submitted`.
- `InApproval` --(approve)--> `InProcessing`.
- `InApproval` --(reject)--> `Rejected`.
- `InProcessing` --(complete)--> `Completed`.
- `InProcessing` --(return)--> `ReturnedForRevision`.
- `Draft` أو `ReturnedForRevision` --(cancel)--> `Cancelled` حسب policy.
- `Rejected` و`Completed` و`Cancelled` حالات نهائية، وأي إعادة فتح تحتاج Command وسياسة وإصدار نشاط صريح.

### 7.2 RecordPayload

- `Unvalidated` --(schema validation)--> `Valid`.
- `Valid` --(draft edit)--> `Unvalidated`.
- `Valid` --(submit)--> `FrozenForState`.
- `FrozenForState` لا يتغير إلا بانتقال مخول أو إعادة للتعديل.

### 7.3 Ownership

- `Assigned` --(TransferRecordOwnership)--> `Transferred` مع حفظ المالك السابق والجديد في Activity.
- لا يتغير owner تلقائياً بسبب تغير Organization؛ يحتاج Command محكوماً وإعادة تقييم Authorization.

## 8. الـInvariants

- كل سجل يملك `work_type_version_id` منشوراً، ولا يتغير الإصدار بصمت أثناء حياته.
- كل سجل يتبع Cluster > Facility > Unit؛ لا يملك Unit خارج Cluster، ولا يشير Facility إلى Cluster مختلف.
- لكل سجل owner scope واحد واضح، ولا يكفي created_by_user_id لتمثيل الملكية.
- لا يحفظ payload قبل نجاح schema وDSL validation.
- لا يسمح بانتقال غير معرف في Work Type Version، ولا يقبل حالة غير موجودة في schema.
- لا يظهر أو يبحث أو يصدر field قبل `ResolveFieldAccess`.
- لا تمنح المشاركة رؤية payload أو المستندات أو السجل المصدر تلقائياً؛ كل فعل يعاد تفويضه.
- `lock_version` يزيد مع كل كتابة ويحمي من الاستبدال الصامت.
- لا يكتب موديول آخر مباشرة في `work_records` أو `work_record_payloads`.
- كل أمر يغير aggregate يكتب activity واضحة، والأفعال الحساسة تكتب Audit عبر contract.
- لا يوجد حذف نهائي مباشر من الواجهة؛ الإلغاء أو الأرشفة المستقبلية لا يتلف التاريخ.
- owner-led transaction هي وحدة الاتساق: نجاح record state وpayload وactivity وOutbox أو Rollback كلها معاً.
- لا تمتد Transaction إلى Queue أو Search أو Object Storage؛ تستخدم العقود والأحداث بعد Commit.
- بناء `AuthorizationRecordFacts` يتم من نفس نسخة aggregate التي قررت العملية، ولا يستخدم cache قديماً.
- لا يطبق typed projection من JSON خام خارج schema أو دون version match.

## 9. الصلاحيات

- `CreateWorkRecord` يحتاج create capability على Work Type والنطاق التنظيمي.
- تعديل Draft يقتصر على المنشئ أو owner/responsible أو من يمنحه Authorization قدرة update.
- Submit وComplete وReject وTransfer وClassificationChange قدرات منفصلة.
- مالك Unit لا يرى تلقائياً سجلات Facility أو Unit شقيق؛ Cluster لا يعني كشف التفاصيل.
- WorkRecords ينشئ `AuthorizationRecordFacts`، لكن Authorization وحده يقرر Allow أو Deny وfield access.
- المسؤول في التجمع قد يحصل على aggregate أو مؤشر فقط وفق العلاقة، ولا يتجاوز `view_details` غيابها.
- السوبر أدمن يخضع للتدقيق عند قراءة أو تصدير سجل مصنف حساس.
- أي تنفيذ بالنيابة يظهر actor وdelegator في Activity وAudit.

## 10. الفشل

- نوع العمل أو الإصدار غير Published: يرفض الإنشاء.
- payload ناقص أو typed value غير صحيحة أو DSL فاشل: يبقى Draft ولا يفقد الإدخال الصالح.
- `AuthorizationRecordFacts` غير متاحة: Fail Closed للعرض أو التعديل، مع إتاحة خطأ تشغيلي دون كشف السجل.
- Deny من Authorization: لا يعاد record id أو العنوان أو الحقول المخفية.
- `lock_version` قديم: تعارض تعديل، تعرض القيم الحالية، ولا يستبدل التغيير بصمت.
- انتقال غير مسموح: يرفض مع الحالة الحالية والانتقال المطلوب، دون تعديل جزئي.
- Workflow start يفشل أثناء submit: Rollback لمعنى الإرسال ما لم يوجد contract تعويضي صريح، ولا يظهر السجل Submitted كذباً.
- فشل Outbox: Rollback للسجل والنشاط والتغيير، ويظهر الخطأ لقائمة المراجعة.
- فشل Search أو Notification أو Projection بعد Commit: يبقى السجل صحيحاً، ويعاد العمل عبر retry idempotent.
- محاولة تغيير version لسجل جارٍ: ترفض أو تطلب migration صريحاً وفحص توافق.
- owner خارج Cluster أو Facility أو Unit المسموح: يرفض النقل ويعيد سبباً قابلاً للتفسير.

## 11. الاختبارات

- Unit: envelope يثبت owner وversion وclassification والحالة.
- Unit: lifecycle transitions ورفض الحالات غير المعرفة.
- Unit: schema وDSL validation للpayload.
- Feature: إنشاء Draft ثم Submit مع workflow أو بدونه.
- Feature: owner-led transaction تعمل atomically للسجل وpayload وactivity وOutbox.
- Concurrency: stale `lock_version` يمنع الاستبدال الصامت.
- Authorization contract: `AuthorizationRecordFacts` تفرّق بين owner Unit والمشارك ومؤشر التجمع.
- Field security: الحقل Hidden لا يظهر في Get أو Search أو Report أو Export.
- Isolation: Facility A لا يرى عنوان أو snippet لسجل Facility B دون علاقة.
- Versioning: السجل الجاري يبقى على version القديم عند نشر version جديد.
- Failure: فشل Workflow أو Outbox لا ينتج حالة ناجحة جزئياً.
- Idempotency: إعادة حدث WorkRecordSubmitted لا تنشئ إشعاراً أو projection مرتين.
- Boundary: Workflow وTasks وSearch لا تكتب جداول WorkRecords مباشرة.
- Integration: تغير Organization أو انتهاء assignment يعيد بناء `AuthorizationRecordFacts` ويغير القرار دون تغيير تاريخ السجل.

## 12. الاعتماديات

- يعتمد على `Shared/Clock` و`Shared/Identifiers`.
- يعتمد على Organization لمرجع Cluster > Facility > Unit والملكية والعلاقات.
- يعتمد على Identity لحالة creator وresponsible والمشاركين.
- يعتمد على Authorization لقرارات السجل والحقول وRecordFacts contract.
- يعتمد على WorkDefinitions لـpublished schema وversion وDSL contract، دون امتلاك تعريفاته.
- يقدّم إلى Workflow مرجع السجل وversion وfacts، وإلى Documents وTasks روابط المصدر.
- يرسل إلى Search وNotifications وReporting أحداث Outbox.
- لا يوجد موديول مستقل أو جداول مستقلة للطلبات؛ الطلبات الداخلية هي `WorkRecord` من نوع عمل منشور رمزه `request`، والرمز ليس تصنيف بيانات، ولا يسمح لـStrategy أو PortfolioProjects أو Risk بالكتابة المباشرة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول WorkRecords | تثبيت ملكية الطلبات الديناميكية وتوحيد الواجهة الأمامية |
