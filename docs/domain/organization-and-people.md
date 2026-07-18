---
doc_id: DOM-ORG-001
title: المؤسسة والهيكل التنظيمي
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: مالك موديول Organization
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/architecture/dependency-rules.md
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Organization and People

## 1. الغرض

يمثل هذا المجال حقيقة المؤسسة الإدارية: التجمع ومنشآته ووحداته التنظيمية، ومنصبه وعلاقاته الإشرافية، وملاك الحسابات البشرية. لا يعرف الطلب ولا المشروع ولا المهمة، لكنه يقدم المعرّفات المستقرة وحقائق التنظيم التي يحتاجها المالك لبناء `AuthorizationRecordFacts`، أو لتعيين مسؤولية أو ربط سجل بجهة. إصدار قرار الوصول يبقى حصراً في Authorization.

## 2. النطاق

- تمثيل التجمع كجهة تشغيلية ذات إدارات داخلية، وفق التسلسل الملزم `Cluster > Facility > Unit`.
- تمثيل المنشآت بمختلف أنواعها (مستشفى، مركز صحي، مركز متخصص، مختبر مركزي، خدمات مشتركة، نوع جديد يعرّفه السوبر أدمن) كأبناء للتجمع.
- تمثيل الوحدات الإدارية والقطاعات والأقسام داخل التجمع أو المنشأة، ولا تكون الوحدة التنظيمية خارج هذا التسلسل.
- تمثيل المناصب وعلاقتها بالوحدات.
- تمثيل التكليفات المؤقتة والعضويات في فرق أو لجان.
- تمثيل العلاقات الإشرافية بمختلف أنواعها وقدراتها.
- استيراد الهيكل من CSV/XLSX بطريقة محكومة ومراجعة.
- التقويم المرجعي للنظام: Asia/Riyadh، مع تخزين جميع الطوابع الزمنية بـ UTC في قاعدة البيانات.

ما لا يدخل في هذا المجال:

- الحسابات وكلمات المرور.
- الجلسات ودورة حياة الدخول.
- الأدوار والصلاحيات كسياسات وصول (تعيش في Authorization).
- أي سجل أعمال مثل طلب أو مشروع أو مؤشر.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| التجمع (Cluster) | الكيان الجذري الوحيد للنظام، ويمثل تجمعاً صحياً واحداً. |
| المنشأة (Facility) | كيان تابع مباشرة للتجمع، وله نوع منشأة محكوم. |
| الوحدة (Unit) | كيان تنظيمي تابع للتجمع أو لمنشأة ضمن التسلسل `Cluster > Facility > Unit`، وقد يكون قطاعاً أو إدارة أو قسماً أو وحدة. |
| نوع الوحدة (Unit Type) | تصنيف محكوم يميز Cluster وFacility وSector وDepartment وSection وUnit. |
| المنصب (Position) | وظيفة رسمية داخل وحدة، قابلة للشغل من قبل شخص أو أكثر. |
| التكليف (Assignment) | ربط شخص بوحدة بمنصب، له تاريخ بداية ونهاية. |
| العلاقة الإشرافية (Supervisory Relationship) | رابط بين وحدتين أو شخصين، له نوع وقدرات ومدى زمني. |
| نوع العلاقة (Relationship Type) | إشراف مباشر، إشراف وظيفي، تنسيق، اطلاع فقط. |
| القدرة الممنوحة (Granted Capability) | قدرة وصول تمنحها علاقة بعينها لمجموعة موديولات. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 ClusterAggregate

- `Cluster` (Entity جذر).
- `ClusterProfile` (Value Object: الاسم، الرمز، الإعدادات، التقويم المرجعي Asia/Riyadh).

### 4.2 FacilityAggregate

- `Facility` (Entity جذر).
- `FacilityType` (Value Object معرّف بنوع منشأة محكوم).
- `FacilityProfile` (Value Object: الاسم، الرمز، الإعدادات المحلية).

### 4.3 OrganizationUnitAggregate

- `OrganizationUnit` (Entity جذر).
- `UnitType` (Value Object: تجمع، منشأة، قطاع، إدارة، قسم، وحدة).
- `UnitPath` (Value Object: سلسلة المسار المحسوبة عبر الأبوين لتقييم النطاق بكفاءة).
- `UnitStatus` (Value Object: Active، Inactive، Archived).

### 4.4 PositionAggregate

- `Position` (Entity جذر).
- `PositionTitle` (Value Object).
- `PositionLevel` (Value Object اختياري للترتيب الإداري).

### 4.5 PersonAggregate

- `Person` (Entity جذر، منفصل عن الحساب).
- `PersonProfile` (Value Object: الاسم، الجوال، البريد، بيانات العرض).
- `PersonStatus` (Value Object: Active، Suspended، Left).

### 4.6 AssignmentAggregate

- `Assignment` (Entity جذر): ربط Person بـ Position بـ OrganizationUnit بمدى زمني.
- `AssignmentRole` (Value Object اختياري لتحديد طبيعة التكليف: أساسي، مناوب، تكليف مؤقت).
- `AssignmentPeriod` (Value Object: start_at، end_at).
- عند انتهاء end_at يدوياً أو آلياً، يفقد التكليف أثره فوراً دون حذف السجل.

### 4.7 SupervisoryRelationshipAggregate

- `SupervisoryRelationship` (Entity جذر).
- `RelationshipType` (Value Object: direct، functional، coordination، view_only).
- `RelationshipScope` (Value Object: قائمة الموديولات المشمولة).
- `RelationshipCapability` (Value Object: القدرة الممنوحة).
- `RelationshipPeriod` (Value Object: start_at، end_at).
- عند انتهاء end_at تسحب العلاقة وقدراتها تلقائياً.

### 4.8 ImportJobAggregate (للاستيراد المحكوم)

- `ImportJob` (Entity جذر).
- `ImportTemplate` (Value Object: اسم القالب، الأعمدة المتوقعة، قواعد التحقق).
- `ImportRow` (Entity تابعة): صف خام + نتيجة تحليل + أخطاء.
- `ImportDiff` (Value Object: مقارنة بين القيم الحالية والمقترحة).
- `ImportDecision` (Value Object لكل صف: Create، Update، Skip، Fail).

## 5. الجداول والقيود والفهارس

### 5.1 `clusters`

- `id` CHAR(36) UUIDv7 PK.
- `code` VARCHAR(64) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `settings` JSON NOT NULL.
- `created_at`، `updated_at` DATETIME.
- فهرس: `(code)`.

اللغة والـlocale والمنطقة الزمنية إعدادات عامة يملكها `PlatformSettings` ولا تخزنها Organization.

### 5.2 `facilities`

- `id` CHAR(36) UUIDv7 PK.
- `cluster_id` CHAR(36) UUIDv7 NOT NULL FK -> `clusters.id`.
- `facility_type_id` CHAR(36) UUIDv7 NOT NULL FK -> `facility_types.id`.
- `code` VARCHAR(64) NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `settings` JSON NOT NULL.
- `created_at`، `updated_at` DATETIME.
- قيد فريد: `(cluster_id, code)`.
- فهارس: `(cluster_id, status)`، `(facility_type_id)`.

### 5.3 `facility_types`

- `id` CHAR(36) UUIDv7 PK.
- `code` VARCHAR(64) UNIQUE NOT NULL (مثال: `hospital`، `center`، `lab`، `shared_services`).
- `name_ar` VARCHAR(255) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.

### 5.4 `organization_units`

- `id` CHAR(36) UUIDv7 PK.
- `cluster_id` CHAR(36) UUIDv7 NOT NULL FK.
- `parent_id` CHAR(36) UUIDv7 NOT NULL؛ يشير إلى Cluster أو Facility أو OrganizationUnit داخل الموديول بحسب `parent_type`.
- `parent_type` VARCHAR(16) NOT NULL: `cluster|facility|unit`.
- `unit_type_id` CHAR(36) UUIDv7 NOT NULL FK -> `unit_types.id`.
- `code` VARCHAR(64) NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `path_cache` VARCHAR(512) NOT NULL (مسار مُسبق للحساب السريع للنطاق).
- `depth` SMALLINT NOT NULL.
- `lock_version` INT UNSIGNED NOT NULL DEFAULT 1.
- `created_at`، `updated_at` DATETIME.
- قيد فريد: `(parent_type, parent_id, code)`.
- فهارس: `(cluster_id, status)`، `(parent_id)`، `(unit_type_id)`، `(path_cache)` كـ prefix index.

### 5.5 `unit_types`

- `id` CHAR(36) UUIDv7 PK.
- `code` VARCHAR(64) UNIQUE NOT NULL (مثال: `cluster`، `facility`، `sector`، `department`، `section`، `unit`).
- `name_ar` VARCHAR(255) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.

### 5.6 `positions`

- `id` CHAR(36) UUIDv7 PK.
- `organization_unit_id` CHAR(36) UUIDv7 NOT NULL FK.
- `code` VARCHAR(64) NOT NULL.
- `title_ar` VARCHAR(255) NOT NULL.
- `title_en` VARCHAR(255) NULL.
- `level` SMALLINT NULL.
- `manager_position_id` CHAR(36) UUIDv7 NULL FK -> `positions.id`، ولا يسمح بدورة إدارية.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- `lock_version` INT UNSIGNED NOT NULL DEFAULT 1.
- قيد فريد: `(organization_unit_id, code)`.
- فهرس: `(organization_unit_id, is_active)`.

### 5.7 `people`

- `id` CHAR(36) UUIDv7 PK.
- `national_id_ciphertext` VARBINARY NULL، مشفر على مستوى العمود.
- `national_id_lookup_hash` CHAR(64) NULL UNIQUE، HMAC للبحث ومنع التكرار بلا كشف القيمة.
- `employee_number` VARCHAR(64) NOT NULL UNIQUE.
- `display_name_ar` VARCHAR(255) NOT NULL.
- `display_name_en` VARCHAR(255) NULL.
- `primary_email_ciphertext` VARBINARY NULL.
- `primary_phone_ciphertext` VARBINARY NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT 'active'.
- `person_version` BIGINT NOT NULL DEFAULT 1.
- `created_at`، `updated_at` DATETIME.
- فهارس: `(status)`، `(national_id_lookup_hash)`، `(employee_number)`.

### 5.8 `assignments`

- `id` CHAR(36) UUIDv7 PK.
- `person_id` CHAR(36) UUIDv7 NOT NULL FK.
- `position_id` CHAR(36) UUIDv7 NOT NULL FK.
- `role` VARCHAR(32) NOT NULL DEFAULT 'primary'.
- `start_at` DATE NOT NULL.
- `end_at` DATE NULL.
- `created_at`، `updated_at` DATETIME.
- قيد: `start_at <= end_at` أو `end_at IS NULL`.
- فهارس: `(person_id, end_at)`، `(position_id, start_at, end_at)`.

### 5.9 `supervisory_relationships`

- `id` CHAR(36) UUIDv7 PK.
- `source_unit_id` CHAR(36) UUIDv7 NULL FK (الجهة المصدر على مستوى الوحدة).
- `source_person_id` CHAR(36) UUIDv7 NULL FK (الجهة المصدر على مستوى الشخص).
- `target_unit_id` CHAR(36) UUIDv7 NULL FK.
- `target_person_id` CHAR(36) UUIDv7 NULL FK.
- `relationship_type` VARCHAR(32) NOT NULL.
- `start_at` DATE NOT NULL.
- `end_at` DATE NULL.
- `created_by_user_id` CHAR(36) UUIDv7 NOT NULL، معرف actor من سياق المصادقة بلا FK أو join إلى Identity.
- `created_at` DATETIME NOT NULL.
- قيد: واحد على الأقل من source_unit_id أو source_person_id، وواحد على الأقل من target_unit_id أو target_person_id.
- فهارس: `(source_unit_id, start_at, end_at)`، `(source_person_id, start_at, end_at)`، `(target_unit_id, start_at, end_at)`، `(target_person_id, start_at, end_at)`، `(relationship_type)`.

### 5.10 `relationship_capabilities`

- `id` CHAR(36) UUIDv7 PK.
- `supervisory_relationship_id` CHAR(36) UUIDv7 NOT NULL FK -> `supervisory_relationships.id` ON DELETE CASCADE.
- `module_code` VARCHAR(64) NOT NULL (مثال: `work-records`، `strategy`، `portfolio-projects`).
- `capability_code` VARCHAR(64) NOT NULL (مثال: `view_aggregate`، `view_details`، `assign_task`، `participate_approval`).
- `field_policy_key` VARCHAR(128) NULL (مرجع لقالب سياسة حقول).
- قيد فريد: `(supervisory_relationship_id, module_code, capability_code)`.

### 5.11 `import_jobs`

- `id` CHAR(36) UUIDv7 PK.
- `template_code` VARCHAR(64) NOT NULL (`facilities`، `organization_units`، `positions`، `people_assignments`).
- `source_filename` VARCHAR(255) NOT NULL.
- `source_format` VARCHAR(8) NOT NULL (`csv`، `xlsx`).
- `status` VARCHAR(32) NOT NULL (`received`، `validated`، `approved`، `applied`، `failed`، `rejected`، `cancelled`).
- `quarantine_object_id` CHAR(36) NOT NULL، مرجع ملف خام مشفر ومحجور بلا FK عابر.
- `submitted_by_user_id` CHAR(36) UUIDv7 NOT NULL، معرف actor بلا FK عابر.
- `approved_by_user_id` CHAR(36) UUIDv7 NULL، معرف actor بلا FK عابر.
- `total_rows` INT NOT NULL DEFAULT 0.
- `valid_rows` INT NOT NULL DEFAULT 0.
- `error_rows` INT NOT NULL DEFAULT 0.
- `applied_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL.
- فهارس: `(status)`، `(template_code)`، `(submitted_by_user_id)`.

### 5.12 `import_rows`

- `id` CHAR(36) UUIDv7 PK.
- `import_job_id` CHAR(36) UUIDv7 NOT NULL FK -> `import_jobs.id` ON DELETE CASCADE.
- `row_number` INT NOT NULL.
- `encrypted_payload` JSON NOT NULL، حقول الصف الحساسة مشفرة ولا تظهر في الأخطاء أو Logs.
- `proposed_action` VARCHAR(16) NULL (`create`، `update`، `skip`).
- `proposed_target_id` CHAR(36) UUIDv7 NULL.
- `validation_errors` JSON NULL.
- `decision` VARCHAR(16) NULL (`accepted`، `rejected`).
- `applied_at` DATETIME NULL.
- فهرس: `(import_job_id, row_number)`.

## 6. الأوامر والاستعلامات والأحداث

### 6.1 Commands

- `CreateCluster`
- `UpdateClusterProfile`
- `CreateFacility`
- `UpdateFacilityProfile`
- `ArchiveFacility`
- `CreateOrganizationUnit`
- `MoveOrganizationUnit`
- `UpdateOrganizationUnitProfile`
- `CreatePosition`
- `UpdatePosition`
- `RegisterPerson`
- `UpdatePersonProfile`
- `CreateAssignment`
- `EndAssignment`
- `CreateSupervisoryRelationship`
- `GrantRelationshipCapability`
- `RevokeRelationshipCapability`
- `EndSupervisoryRelationship`
- `SubmitImportJob`
- `ValidateImportJob`
- `ApproveImportJob`
- `RejectImportJob`
- `ApplyImportJob`
- `CancelImportJob`

### 6.2 Queries

- `GetCluster`
- `GetOrganizationUnit`
- `ListFacilityOrganizationUnits`
- `ListOrganizationUnitChildren`
- `ResolveUnitPath`
- `GetPosition`
- `ListPositionAssignments`
- `ResolveDirectManager` (يُرجع Person الحالي لمنصب مدير محدد ضمن مدى زمني).
- `GetActiveAssignmentsForPerson` (يستخدم Asia/Riyadh لتحديد "اليوم").
- `GetActiveSupervisoryRelationships` (تستخدمها Authorization وWorkflow).
- `GetRelationshipCapabilities` (تستخدمها Authorization).
- `ExplainOrganizationalScope` (يقرأ نطاق المستخدم بكفاءة).
- `GetImportJob`
- `ListImportJobRows`

### 6.3 Domain Events

- `ClusterCreated`
- `ClusterUpdated`
- `FacilityCreated`
- `FacilityUpdated`
- `FacilityArchived`
- `OrganizationUnitCreated`
- `OrganizationUnitMoved`
- `OrganizationUnitUpdated`
- `OrganizationUnitArchived`
- `PositionCreated`
- `PositionUpdated`
- `PersonRegistered`
- `PersonUpdated`
- `AssignmentStarted`
- `AssignmentEnded`
- `SupervisoryRelationshipActivated`
- `SupervisoryRelationshipCapabilityGranted`
- `SupervisoryRelationshipCapabilityRevoked`
- `SupervisoryRelationshipExpired`
- `ImportJobSubmitted`
- `ImportJobValidated`
- `ImportJobApproved`
- `ImportJobApplied`
- `ImportJobFailed`
- `IdentityProvisioningRequested` بعد تطبيق Person فعلياً، ويحمل `person_id` و`person_version` بلا PII.
- `PersonAccessStatusChanged` عند تغير Active أو Suspended أو Left، ويحمل النسخة نفسها.

## 7. State Machines

### 7.1 OrganizationUnit

- `Active` --(archive)--> `Inactive` --(restore)--> `Active`.
- `Inactive` --(archive permanent)--> `Archived` (نهاية، يحتفظ به للقراءة).

### 7.2 Assignment

- `Pending` (تاريخ بداية قادم) --(reaches start_at)--> `Active`.
- `Active` --(end_at reached or EndAssignment)--> `Ended`.
- لا يمكن إعادة تفعيل تكليف منتهي؛ ينشأ تكليف جديد.

### 7.3 SupervisoryRelationship

- `Pending` --(start_at reached)--> `Active`.
- `Active` --(end_at reached or EndSupervisoryRelationship)--> `Ended`.
- `Ended` نهائي؛ التعديل ينشئ علاقة جديدة.

### 7.4 ImportJob

- `Received` --(validate)--> `Validated`.
- `Validated` --(approve)--> `Approved`.
- `Validated` --(reject)--> `Rejected`.
- `Approved` --(apply)--> `Applied`.
- `Approved` --(cancel)--> `Cancelled`.
- `Received`/`Validated` --(system validation failure)--> `Failed`.

## 8. الـInvariants

- لكل `OrganizationUnit` غير الجذر، `parent_id` ليس NULL، والجذر الوحيد هو `Cluster`؛ تكون `Facility` ابناً للتجمع وتكون الوحدات الإدارية داخل التجمع أو المنشأة وفق `Cluster > Facility > Unit`.
- لا يمكن إنشاء Facility تحت Facility أخرى، ولا Unit خارج Cluster أو Facility إلا إذا سمح نوع الوحدة المحكوم بذلك صراحة.
- لا يمكن نقل وحدة إلى نسلها (يمنع الدوران).
- `assignment.start_at <= assignment.end_at` إن وُجد.
- تكليف واحد فقط لكل (Person، Position، period overlap) مع استثناءات التكليف المتوازي بحسب السياسة.
- العلاقة الإشرافية يجب أن يكون لها طرفيها محددان، ولا يجوز إنشاء علاقة من شخص لآخر إذا لم تكن سياسات النوع تسمح.
- العلاقة الإشرافية لا تمنح قدرات ضمنية خارج `relationship_capabilities` المعرّفة.
- استيراد CSV/XLSX لا يطبق مباشرة: يمر عبر حالات Received → Validated → Approved → Applied.
- لا تُطبّق صفحات الاستيراد التي تحوي أخطاء حرجة (Critical)؛ يطلب إعادة رفع.
- كل استيراد يحتاج `approved_by_user_id` مختلف عن `submitted_by_user_id` (مبدأ الموافقة المزدوجة).
- معرفات actor حقائق تدقيق من سياق المصادقة وليست FKs أو ORM relations إلى Identity.
- لكل Person رقم `person_version` أحادي الزيادة يرافق أحداث provisioning وحالة الوصول.
- التقويم المرجعي Asia/Riyadh، لكن طوابع `created_at`/`updated_at` تُخزن UTC.

## 9. معاملات المجال وملكية القرار

- كل Command يقوده Aggregate المالك داخل Organization؛ Handler الخاص بالـSlice يملك Transaction وcommit أو rollback.
- إنشاء أو نقل Cluster أو Facility أو Unit يحفظ الشجرة و`path_cache` والأحداث في Transaction واحدة.
- استيراد CSV/XLSX يملك ImportJob Transaction الخاصة به، ولا يكتب صفوف الهيكل عند الرفع أو التحقق؛ التطبيق فقط يحدث بعد Approved.
- تطبيق الاستيراد يحفظ تغييرات Organization و`IdentityProvisioningRequested` في Outbox داخل المعاملة نفسها، ولا يكتب جداول Identity.
- لا يستخدم Organization Transaction عامة لتنسيق جداول Identity أو Authorization أو WorkRecords.
- الأحداث المهمة وOutbox تحفظ داخل Transaction المالك، وتنفذ الفهرسة أو الإشعار بعد Commit.
- يقدم Organization حقائق النطاق والعلاقات إلى Authorization، لكنه لا يصدر قرار Allow أو Deny لسجل أعمال.

## 10. الصلاحيات

- السوبر أدمن فقط ينشئ `Cluster` ويعدل إعداداته العامة.
- السوبر أدمن فقط ينشئ `Facility` و`OrganizationUnit` ويعدلها ويؤرشفها.
- السوبر أدمن فقط ينشئ `SupervisoryRelationship` ويمنح القدرات.
- السوبر أدمن فقط يعتمد `ImportJob`.
- السوبر أدمن فقط ينشئ `Position`.
- السوبر أدمن فقط ينشئ `Assignment` وينهيه.
- لا يستطيع الموظف تعديل جهته أو منصبه أو مديره أو صلاحياته بنفسه.
- السوبر أدمن يطّلع على سجل التدقيق لأي عملية حساسة، وكل عملية حساسة تسجل مع اسم المنفّذ والوقت Asia/Riyadh وسبب اختياري.
- يقدم Organization إلى Authorization عقود `ResolveOrganizationScope` و`GetActiveSupervisoryRelationships` وحقائق التنظيم اللازمة لبناء `AuthorizationRecordFacts`.
- لا يقرر Organization صلاحية سجل أعمال ولا يقرأ payload؛ قرار Allow أو Deny وFieldAccess مركزي في Authorization.

## 11. الفشل

- استيراد CSV/XLSX فيه حقول مطلوبة ناقصة: الصف يُعلّم كـ Critical ولا يُطبّق، ويُسمح بإعادة الرفع.
- استيراد فيه قيم لا تطابق أنواع محكومة (مثل `facility_type_code` غير معروف): الصف يُرفَض ولا يؤثر على غيره.
- محاولة نقل وحدة إلى نسلها: العملية تُمنع مع رسالة قابلة للتفسير.
- محاولة حفظ تكليف بتاريخ نهاية قبل البداية: تُمنع في طبقة المجال.
- انتهاء علاقة إشرافية قبل أي قرار وصول: يفقد القرار قدرات العلاقة، ويسجّل النظام الحدث في Outbox.
- فشل إنشاء Outbox event: المعاملة تُلفّ، وتُسجّل في قائمة أخطاء المراجعة.
- أي فشل في تخزين UTC يحول دون أي تحويل Asia/Riyadh محتمل في طبقة العرض: يمنع بأداة CI تختبر أن الطوابع لا تُعدّل في طبقة الـRead.

## 12. الاختبارات والقبول

### 12.1 معايير القبول

- السوبر أدمن ينشئ تجمعاً واحداً فقط في النظام.
- السوبر أدمن ينشئ منشأة جديدة ويحدد نوعها ويصبح لها شجرة وحدات.
- السوبر أدمن ينقل وحدة من إدارة إلى إدارة أخرى، ويتغير `path_cache`.
- السوبر أدمن ينشئ علاقة إشراف وظيفي بين وحدتين بمنحنى زمني وقدرات محددة.
- السوبر أدمن يرفع ملف CSV/XLSX، تظهر الأخطاء قبل الاعتماد، يوافق، تطبّق التغييرات.
- انتهاء تكليف بتاريخ محدد يسحب أثره تلقائياً.
- انتهاء علاقة إشرافية يسحب قدراتها تلقائياً.
- جميع الطوابع الزمنية تُعرض Asia/Riyadh للواجهة وUTC لقاعدة البيانات.

### 12.2 الاختبارات

- اختبار معماري: يمنع Namespace الأعمال من استيراد Infrastructure من Organization.
- اختبار وحدة: قواعد `path_cache` عند النقل، ومنع الدوران.
- اختبار حالة استخدام: إنشاء منشأة جديدة ينعكس على شجرة الوحدات.
- اختبار حالة استخدام: استيراد CSV يحوي أخطاء حرجة → حالة Failed وعدم تطبيق.
- اختبار حالة استخدام: استيراد ناجح ينشئ عدد الصفوف الصحيح.
- اختبار Authorization: موظف في منشأة لا يستطيع قراءة تكليفات منشأة أخرى.
- اختبار عبر-موديول عقد: `ResolveDirectManager` يعيد النتيجة الصحيحة بحسب Asia/Riyadh.
- اختبار UTC: لا يوجد تحويل Asia/Riyadh في طبقة Persistence.
- اختبار Integration: علاقة إشرافية منتهية لا تظهر في `GetActiveSupervisoryRelationships`.

## 13. الاعتماديات

- يعتمد على: Shared/Clock (Asia/Riyadh)، Shared/Identifiers.
- يستقبل معرفات actor من سياق المصادقة كتدقيق فقط، بلا اعتماد متزامن أو FK إلى Identity.
- لا يعتمد على Authorization، WorkDefinitions، WorkRecords، Workflow، Documents.
- يعتمد عليه: Identity (لربط Person بحساب)، Authorization (لحل النطاق والعلاقات)، WorkDefinitions (للإشارة إلى `owner_organization_unit_id`)، WorkRecords (نفس المرجع)، Workflow (لحل المعتمدين)، Documents (نفس المرجع)، Strategy (للإشارة إلى الجهة المالكة للمؤشر)، PortfolioProjects (نفس المرجع)، Risk (نفس المرجع)، Collaboration (للإشارة إلى الجهة المالكة للمهمة).

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-18 | مالك موديول Organization | تثبيت ملكية Person وحدود actor والاستيراد وprovisioning وفق ADR-024 |
| 1.0.0 | 2026-07-15 | مالك موديول Organization | توحيد الواجهة الأمامية وحدود الموديول |
