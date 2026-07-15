---
doc_id: DOM-AUT-001
title: التفويض المركزي
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Authorization
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/context-map.md
- docs/data-security/authorization-model.md
---
# Authorization

## 1. الغرض

يملك Authorization قرار الوصول المركزي القابل للتفسير. يجمع القدرة من الدور مع النطاق التنظيمي والعلاقة والملكية والتصنيف والحالة والتكليف أو التفويض وسياسة الحقل، ثم يعيد قراراً موحداً يمكن تطبيقه في API والبحث والتقارير والتصدير والواجهة. لا يملك Authorization السجل التشغيلي ولا يعيد بناء معناه؛ يتلقى من مالك السجل `AuthorizationRecordFacts` محددة مع طلب القرار.

## 2. النطاق

- تعريف الأدوار والقدرات وقوالب سياسات الحقول.
- إسناد الأدوار وسحبها ضمن مدى زمني ونطاق معلوم.
- إدارة التفويضات المحددة المدة والمجال.
- تطبيق RBAC + ABAC على Cluster > Facility > Unit وعلى السجلات والحقول.
- استهلاك حالة الحساب من Identity والنطاق والعلاقات من Organization.
- استقبال حقائق السجل التي يقدمها الموديول المالك عبر عقد `AuthorizationRecordFacts`.
- إصدار `AccessDecision` و`FieldAccessDecision` وشرح القرار.

ما لا يدخل في هذا المجال:

- كلمة المرور والجلسة وحالة الحساب المصدر، وتبقى في Identity.
- شجرة Cluster وFacility وUnit والعلاقات الإشرافية المصدر، وتبقى في Organization.
- payload السجل أو انتقالات حالته أو تنفيذ مساره.
- كتابة جداول موديولات الأعمال أو تفسير حقولها الخاصة.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| الدور (Role) | مجموعة قدرات مسماة يمكن إسنادها لمستخدم ضمن نطاق ومدة. |
| القدرة (Capability) | فعل دقيق مثل view، create، update، submit، approve، assign، export، manage. |
| النطاق (Scope) | Cluster أو Facility أو Unit أو مجموعة سجلات مشتركة يحدد أين تنطبق القدرة. |
| AuthorizationRecordFacts | عقد حقائق ينشئه مالك السجل عن المالك والحالة والتصنيف والمشاركة وسياسة الحقول، دون payload غير لازم أو قرار وصول. |
| AccessDecision | نتيجة Allow أو Deny مع سبب وقيم الحقائق ونسخة السياسة ووقت القرار. |
| FieldAccessDecision | حالة حقل: Hidden أو ReadOnly أو Editable أو Masked. |
| التفويض (Delegation) | نقل محدد المدة والقدرة والمجال إلى مفوض له مع بقاء هوية صاحبها ظاهرة. |
| المنع الصريح (Explicit Deny) | قاعدة تمنع الوصول حتى لو وجد سماح أوسع. |
| قرار Fail Closed | رفض آمن عند نقص `AuthorizationRecordFacts` أو فشل مصدر حقيقة أو انتهاء زمن القرار. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 RoleAggregate

- `Role` (Entity جذر): code، name، status، role_type.
- `Capability` (Entity تابعة): capability_code، module_code، action، sensitivity.
- `RoleCapability` (Entity رابطة): role_id وcapability_id مع allow أو deny عند الحاجة.

### 4.2 RoleAssignmentAggregate

- `RoleAssignment` (Entity جذر): user_id، role_id، scope، start_at، end_at، status.
- `AuthorizationScope` (Value Object): مستوى Cluster أو Facility أو Unit ومعرفاته وقواعد الوراثة.
- `AssignmentPeriod` (Value Object): مدى زمني لا يتجاوز سياسة الدور.

### 4.3 DelegationAggregate

- `Delegation` (Entity جذر): delegator_user_id، delegate_user_id، capabilities، scope، period، status.
- `DelegationReason` (Value Object): سبب إلزامي عند القدرات الحساسة.

### 4.4 ClassificationPolicyAggregate

- `ClassificationPolicy` (Entity جذر): مستوى التصنيف، الحد الأدنى للدور، قواعد المشاركة والتصدير والتدقيق.
- `FieldAccessTemplate` (Entity جذر): field_policy_key، الحالات حسب الدور والحالة والنطاق.

### 4.5 AccessDecision Value Objects

- `RecordReference`: module_code، record_type، record_id.
- `AuthorizationRecordFacts`: حقائق السجل التي يقدمها المالك.
- `AccessDecision`: decision، reason_codes، policy_version، evaluated_at، trace_id.
- `FieldAccessMap`: خريطة الحقول المسموحة والمخفية.

## 5. عقد AuthorizationRecordFacts

### 5.1 مسؤولية مالك السجل

ينشئ الموديول المالك، مثل WorkRecords أو Workflow، `AuthorizationRecordFacts` من مصدر الحقيقة الخاص به ويقدم هذا العقد فقط لمسار الوصول. لا يبني Authorization هذه الحقائق من Join عشوائي، ولا يستقبل payload كاملاً لمجرد اتخاذ القرار. المالك لا يصدر Allow أو Deny أو قرار الحقول.

### 5.2 الحقول الإلزامية

- `facts_version`.
- `source_module` و`record_type` و`record_id`.
- `cluster_id` و`owner_facility_id` و`owner_organization_unit_id`.
- `created_by_user_id` و`owner_user_id` و`responsible_user_id` عند وجودها.
- `shared_unit_ids` و`shared_user_ids` و`participant_ids` حسب سياسة المالك.
- `classification` و`lifecycle_state` و`workflow_state`.
- `field_policy_key` و`work_type_version_id` عند وجودهما.
- لا يدخل سياق الإجراء ضمن حقائق المالك؛ يمرر المستدعي `action_context` منفصلاً إلى `DecideAccess`.

### 5.3 القواعد

- لا تحتوي `AuthorizationRecordFacts` على كلمة مرور أو token أو payload سري غير مطلوب.
- لا تسمح `AuthorizationRecordFacts` بتجاوز دور أو تصنيف أو قاعدة منع مركزية.
- يجب أن تكون الحقائق قابلة لإعادة القراءة ومطابقة للسجل في نفس الإصدار.
- نقص حقيقة لازمة أو فشل عقد المالك يؤدي إلى Deny أو حالة خدمة واضحة، ولا يؤدي إلى Allow افتراضي.
- يحتفظ القرار بـ`facts_version` و`policy_version` للتفسير وإعادة التحقيق.

## 6. الجداول والقيود والفهارس

### 6.1 `roles`

- `id` BIGINT PK.
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `role_type` VARCHAR(32) NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- `is_system_role` BOOLEAN NOT NULL DEFAULT FALSE.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- فهارس: `(status, role_type)`.

### 6.2 `capabilities`

- `id` BIGINT PK.
- `module_code` VARCHAR(64) NOT NULL.
- `capability_code` VARCHAR(96) NOT NULL.
- `action` VARCHAR(32) NOT NULL.
- `sensitivity` VARCHAR(16) NOT NULL DEFAULT `normal`.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- قيد فريد على `(module_code, capability_code)`.
- فهارس: `(module_code, action, status)`، `(sensitivity, status)`.

### 6.3 `role_capabilities`

- `role_id` BIGINT NOT NULL FK -> `roles.id`.
- `capability_id` BIGINT NOT NULL FK -> `capabilities.id`.
- `effect` VARCHAR(8) NOT NULL DEFAULT `allow`.
- `created_at` DATETIME NOT NULL.
- PK مركب `(role_id, capability_id)`.
- القيم المسموحة لـ`effect`: `allow`، `deny`.

### 6.4 `role_assignments`

- `id` BIGINT PK.
- `user_id` BIGINT NOT NULL، مرجع Identity عبر contract.
- `role_id` BIGINT NOT NULL FK -> `roles.id`.
- `scope_type` VARCHAR(16) NOT NULL (`cluster`، `facility`، `unit`، `record_set`).
- `scope_id` BIGINT NOT NULL.
- `start_at` DATETIME NOT NULL.
- `end_at` DATETIME NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- `granted_by_user_id` BIGINT NOT NULL.
- قيد `start_at <= end_at` عند وجود النهاية.
- فهارس: `(user_id, status, start_at, end_at)`، `(scope_type, scope_id, status)`، `(role_id, status)`.

### 6.5 `delegations`

- `id` BIGINT PK.
- `delegator_user_id` BIGINT NOT NULL.
- `delegate_user_id` BIGINT NOT NULL.
- `capability_set` JSON NOT NULL.
- `scope_type` VARCHAR(16) NOT NULL.
- `scope_id` BIGINT NOT NULL.
- `start_at` DATETIME NOT NULL.
- `end_at` DATETIME NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- `reason` VARCHAR(500) NOT NULL.
- قيد `delegator_user_id <> delegate_user_id` و`start_at < end_at`.
- فهارس: `(delegate_user_id, status, start_at, end_at)`، `(delegator_user_id, status)`، `(scope_type, scope_id, status)`.

### 6.6 `classification_policies`

- `id` BIGINT PK.
- `classification_code` VARCHAR(32) UNIQUE NOT NULL (`public`، `internal`، `confidential`، `top_secret`)؛ وتقابلها `عام`، `داخلي`، `سري`، `سري للغاية`.
- `minimum_capability` VARCHAR(96) NOT NULL.
- `export_policy` VARCHAR(32) NOT NULL.
- `download_policy` VARCHAR(32) NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- فهارس: `(is_active, classification_code)`.

### 6.7 `field_access_templates`

- `id` BIGINT PK.
- `field_policy_key` VARCHAR(128) UNIQUE NOT NULL.
- `module_code` VARCHAR(64) NOT NULL.
- `policy_definition` JSON NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE.
- فهارس: `(module_code, is_active)`.

## 7. الأوامر والاستعلامات والأحداث

### 7.1 Commands

- `CreateRole`
- `DefineCapability`
- `GrantRoleCapability`
- `DenyRoleCapability`
- `AssignRole`
- `RevokeRole`
- `CreateDelegation`
- `ActivateDelegation`
- `EndDelegation`
- `CreateClassificationPolicy`
- `PublishFieldAccessTemplate`

Commands الإدارية يملكها Authorization. كل كتابة في Authorization تنفذها Transaction يقودها aggregate المالك؛ أما قرارات الوصول والحقول فهي Queries بلا كتابة في سجل الأعمال.

### 7.2 Queries

- `GetActiveRolesForUser`
- `GetActiveRoleAssignments`
- `GetActiveDelegations`
- `GetCapabilitiesForContext`
- `GetClassificationPolicy`
- `GetFieldAccessTemplate`
- `DecideAccess(actorContext, capability, AuthorizationRecordFacts, actionContext)`
- `BuildAuthorizedScopePredicate`
- `FilterReadableOrganizationScopes`
- `ResolveFieldAccess`
- `ExplainAccessDecision`

يطبق الموديول المالك `BuildAuthorizedScopePredicate` على استعلامه، ثم يمرر `AuthorizationRecordFacts` لكل نتيجة إلى `DecideAccess` و`ResolveFieldAccess`. لا يستدعي Authorization المالك ولا يعيد سجلات أعمال.

### 7.3 Domain وApplication Events

- `RoleCreated`
- `RoleCapabilityGranted`
- `RoleCapabilityDenied`
- `RoleAssigned`
- `RoleRevoked`
- `DelegationCreated`
- `DelegationActivated`
- `DelegationExpired`
- `DelegationEnded`
- `ClassificationPolicyPublished`
- `FieldAccessTemplatePublished`
- `AccessDeniedForSensitiveRecord`
- `AuthorizationDecisionRecorded`

الأحداث الإدارية والحساسة تسجل عبر Outbox، أما قرار القراءة العادي فلا يتحول إلى حدث تشغيلي إلا إذا فرضت سياسة التصنيف ذلك.

## 8. State Machines

### 8.1 RoleAssignment

- `Pending` --(start_at reached)--> `Active`.
- `Active` --(revoke or end_at reached)--> `Revoked`.
- `Pending` --(cancel)--> `Cancelled`.
- `Revoked` و`Cancelled` حالتان نهائيتان، وإنشاء صلاحية جديدة يحتاج Assignment جديداً.

### 8.2 Delegation

- `Draft` --(activate after validation)--> `Active`.
- `Active` --(end_at reached or EndDelegation)--> `Expired`.
- `Draft` --(cancel)--> `Cancelled`.
- `Expired` و`Cancelled` نهائيتان.

### 8.3 AccessDecision

- `Requested` --(facts and policy evaluate)--> `Allowed` أو `Denied`.
- `Requested` --(facts unavailable)--> `Indeterminate` ثم `Denied` للمحتوى المحمي.
- القرار لا يمنح صلاحية مستمرة؛ يعاد تقييمه عند كل عملية حساسة أو عند تغير الإصدار.

## 9. الـInvariants

- القرار المركزي هو نقطة السياسة الوحيدة؛ لا يحق لموديول أو واجهة إعادة بناء Allow مستقل.
- الأصل هو Deny، وأي Allow يحتاج قدرة ونطاقاً وسياقاً صالحاً.
- المنع الصريح والتصنيف الأعلى يقدمان على السماح العام.
- انتهاء الدور أو التكليف أو التفويض يزيل أثره فوراً حسب Clock المرجعي.
- لا يمنح `view_aggregate` حق `view_details`، ولا يمنح مؤشر مجمع حق تفاصيل السجل.
- لا تصبح مشاركة `AuthorizationRecordFacts` مشاركة بيانات؛ يجب أن توجد قدرة مستقلة للحقول المطلوبة.
- لا تستخدم `AuthorizationRecordFacts` القديمة بعد تغير `facts_version` أو `lock_version` دون إعادة جلبها.
- لا يسمح بتفويض قدرة لا يملكها المفوض، ولا بتفويض قدرة حساسة تحظرها السياسة.
- لا يمكن أن يشمل التفويض نفسه أو مدة غير محدودة أو نطاقاً أوسع من أصل الصلاحية.
- لا تعاد الحقول Hidden في Response أو Search snippet أو Export.
- إذا لم يقدم المالك `AuthorizationRecordFacts` صحيحة، يفشل القرار Fail Closed ولا يستنتجها Authorization من payload.
- كل شرح قرار يذكر policy_version وfacts_version وreason_codes دون كشف بيانات غير لازمة.
- كل قرار حساس قابل للربط بـ`trace_id` وبسجل تدقيق، ولا يعد قرار الوصول عقداً لتعديل السجل.

## 10. الصلاحيات

- السوبر أدمن يدير الأدوار والقدرات وقوالب التصنيف والتفويضات الحساسة.
- إسناد الدور يحتاج قدرة إدارة صريحة، ولا يكفي كون المستخدم مديراً تنظيمياً.
- مالك العلاقة في Organization لا يضيف قدرة من تلقاء نفسه؛ تُسجل قدرات العلاقة في Organization وتقرأها Authorization ضمن facts السياق.
- الموديول المالك يقرر من هو owner ومن هي الحالة، وAuthorization يقرر هل يطابق ذلك سياسة القدرة.
- WorkRecords وWorkflow وWorkDefinitions يطلبون `DecideAccess` و`ResolveFieldAccess` عبر العقد فقط.
- البحث والتقارير والتصدير والتنزيل يعيدون فحص قرار السجل والمستند ولا يعتمدون على إخفاء عناصر React.
- السوبر أدمن نفسه يخضع للتدقيق عند الوصول إلى محتوى حساس.

## 11. الفشل

- `AuthorizationRecordFacts` ناقصة أو غير متاحة: Deny للمحتوى المحمي مع سبب `facts_unavailable`.
- حساب غير نشط: Deny قبل بقية قواعد القرار.
- نطاق خارج Cluster أو Facility أو Unit المسموح: Deny دون إظهار وجود سجل محمي.
- تصنيف أعلى من القدرة: Deny أو Masked حسب سياسة الحقل، ولا يتحول إلى ReadOnly تلقائياً.
- انتهاء تفويض أثناء الطلب: يعاد التقييم، ويمنع القرار إذا انتهى قبل commit.
- تعارض إصدار السياسة: يعاد تحميل السياسة، ولا يستخدم cache قديم لعملية حساسة.
- فشل عقد Organization أو Identity: Fail Closed، مع تنبيه تشغيلي لا يكشف بيانات.
- محاولة تجاوز `field_policy_key`: ترفض الحقول الزائدة ويعاد فقط الشكل المسموح أو يفشل الطلب حسب نوع العملية.
- تعارض إسناد دورين متناقضين: يطبق deny الصريح ويسجل التعارض للمراجعة.
- فشل تسجيل حدث الوصول الحساس: يمنع إرجاع المحتوى إذا كانت السياسة تشترط التسجيل قبل العرض.

## 12. الاختبارات

- Unit: دمج RBAC مع النطاق والعلاقة والتصنيف والحالة.
- Unit: deny الصريح يتقدم على allow، وانتهاء المدة يسحب القدرة.
- Contract: WorkRecords يقدم `AuthorizationRecordFacts` كاملة ويستقبل Decision قابلاً للتفسير.
- Contract: Workflow لا يعيد حل المعتمد من داخل Authorization؛ يستهلك قراراً للمستخدم المثبت.
- Security: `AuthorizationRecordFacts` لا تحتوي payload أو password أو token.
- Authorization matrix: عزل Facility عن Facility، وعزل Unit عن Unit، وحالات العلاقة الوظيفية.
- Field policy: Hidden لا يظهر في API أو البحث أو التقرير أو التصدير.
- Classification: `view_aggregate` لا يكشف التفاصيل، والتصنيف السري يسجل العرض والتنزيل.
- Delegation: لا يسمح بتفويض أوسع من قدرة المفوض، وينتهي تلقائياً.
- Fail closed: توقف Identity أو Organization أو مالك السجل لا ينتج Allow.
- Cache: تغير policy_version أو facts_version لا يستخدم قراراً سابقاً.
- Boundary: لا توجد قراءة مباشرة لجداول WorkRecords أو Workflows أو WorkDefinitions.
- Property: لأي `AuthorizationRecordFacts` خارج نطاق المستخدم لا يظهر record_id أو title أو snippet.

## 13. الاعتماديات

- يعتمد على `Shared/Clock` و`Shared/Identifiers`.
- يستهلك Organization لعقد Cluster > Facility > Unit، التكليفات، العلاقات وقدراتها.
- يستهلك Identity لحالة الحساب وملخص الهوية، ولا يقرأ credentials.
- يتلقى `AuthorizationRecordFacts` من كل موديول مالك لسجل محمي مع طلب القرار.
- لا يعتمد على WorkDefinitions لمعرفة شكل الحقول؛ يتلقى `field_policy_key` ضمن `AuthorizationRecordFacts` ويطبق قالب الحقول الذي يملكه.
- يقدّم عقود القرار إلى WorkRecords وWorkflow وDocuments وReporting وSearch والموديولات المتخصصة.
- يرسل أحداثاً إلى Audit وOutbox عبر العقود، ولا يملك سجل التدقيق المركزي.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Authorization | توحيد الواجهة الأمامية وحدود AuthorizationRecordFacts |
