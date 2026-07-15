---
doc_id: DOM-WDF-001
title: تعريفات العمل
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول WorkDefinitions
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/006-workflow-versioning.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Work Definitions

## 1. الغرض

يمثل هذا المجال تعريف أنواع الأعمال الديناميكية التي يمكن للسوبر أدمن بناؤها دون كود حر: الحقول، التحقق، الحالات، العلاقات، النماذج، القوائم، سياسات الحقول، وقواعد DSL. يملك WorkDefinitions تعريفاً وإصداراته فقط، ولا يملك السجل التشغيلي أو payload السجل أو تنفيذ الموافقة. كل إصدار منشور ثابت ويصبح مرجعاً يمكن لـWorkRecords وWorkflow الاعتماد عليه.

## 2. النطاق

- إنشاء عائلة نوع عمل ومسودات إصداراتها.
- تعريف الحقول typed وقواعد التحقق والعلاقات والواجهات.
- تعريف مخطط الحالات والانتقالات المسموح بها للسجل.
- تعريف سياسات الحقول المرتبطة بإصدار النوع.
- تعريف القوائم والفلاتر والتقارير الأساسية دون تنفيذ بيانات التشغيل.
- اختبار التعريف بعينات وحالات رفض وقبول قبل الاعتماد.
- توقيع حزمة تعريف خالية من البيانات والأسرار ونشر إصدار ثابت.
- توفير Schema contract وField Policy contract إلى WorkRecords وAuthorization.

ما لا يدخل في هذا المجال:

- إنشاء أو تعديل WorkRecord فعلي.
- تخزين كلمة مرور أو دور أو قرار وصول فعلي.
- تنفيذ Workflow instance أو قرار معتمد.
- كتابة كود PHP أو JavaScript أو SQL داخل تعريف.
- تعريف قواعد مالية أو سريرية خاصة خارج DSL المحكوم.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| نوع العمل (Work Type) | عائلة تعريفية مثل طلب داخلي أو سجل متابعة، لها إصدارات متعددة. |
| إصدار التعريف (Work Type Version) | نسخة كاملة وثابتة من schema وقواعد النوع، تستخدمها السجلات الجديدة. |
| المسودة (Draft) | إصدار قابل للتعديل قبل اختباره. |
| DSL | لغة تعبير مقيدة تمثل شروطاً وقواعد typed على شكل AST، لا تنفذ كوداً حراً. |
| الحقل (Field Definition) | تعريف typed لحقل اسمه ومطلوبه وقيمته وسياسة عرضه والتحقق منه. |
| سياسة الحقل (Field Policy) | خريطة حالات Hidden وReadOnly وEditable وMasked حسب السياق. |
| حزمة التعريف (Definition Package) | تمثيل قابل للنقل للإصدار دون سجلات تشغيلية أو أسرار. |
| الاختبار (Definition Test) | حالة إدخال ونتيجة متوقعة للتحقق من schema أو DSL أو انتقال. |
| التوقيع (Signature) | إثبات أن الحزمة المعتمدة لم تتغير بعد اعتمادها وقبل نشرها. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 WorkTypeAggregate

- `WorkTypeDefinition` (Entity جذر): work_type_id، code، names، owner scope، status.
- `WorkTypeVersion` (Entity تابعة): version_id، version_number، definition_state، schema_hash.
- `DefinitionMetadata` (Value Object): الاسم والوصف والتصنيف والاحتفاظ الافتراضي.

### 4.2 SchemaAggregate

- `FieldDefinition` (Entity تابعة): field_key، type، required، default، validation_rules.
- `RelationDefinition` (Entity تابعة): relation_key، target_type، cardinality، visibility.
- `StateDefinition` (Entity تابعة): state_key، terminal، display metadata.
- `TransitionDefinition` (Entity تابعة): from_state، to_state، guard DSL، required capability.

### 4.3 PresentationAggregate

- `FormLayout` (Entity تابعة).
- `ListViewDefinition` (Entity تابعة).
- `ReportViewDefinition` (Entity تابعة).
- `FieldAccessPolicy` (Value Object) مرتبط بـfield_policy_key وإصدار التعريف.

### 4.4 DefinitionGovernanceAggregate

- `DefinitionTestCase` (Entity تابعة).
- `DefinitionApproval` (Entity تابعة): المعتمد والسبب والوقت.
- `DefinitionSignature` (Entity تابعة): fingerprint، signer، key_id، signed_at.
- `DefinitionPackage` (Entity جذر): package_hash، source، manifest، import result.

## 5. DSL المقيد

### 5.1 الشكل

- تخزن القاعدة كـAST JSON بعبارة `dsl_version`، ولا تخزن expression نصياً قابلاً للتنفيذ.
- كل عقدة تحمل نوعاً ثابتاً، مثل `and`، `or`، `not`، `equals`، `in`، `greater_than`، `is_present`، `date_before`.
- المراجع المسموحة هي field keys في الإصدار نفسه وقيم سياق معلنة مثل current_user وrecord_state.
- لا يسمح بالـloop أو recursion أو reflection أو استدعاء HTTP أو filesystem أو database أو function غير مسجل.

### 5.2 بوابة DSL

- Parser يحول الإدخال إلى AST canonical.
- Validator يتحقق من الأنواع والمراجع والعمق وعدد العقد والحجم.
- Compiler داخلي يحول AST إلى evaluator محدود allow-listed.
- Executor يطبق time budget وnode budget ويرفض أي شكل غير معروف.
- أي تغيير في DSL version أو allow-list يحتاج اختباراً وتوقيعاً جديداً.

### 5.3 الحدود الافتراضية

- عمق أقصى 12 مستوى.
- عدد عقد أقصى 200.
- لا أكثر من 50 قيمة في عامل `in`.
- لا يقرأ DSL payload مخفياً أو حقلاً غير معرف في الإصدار.
- لا يعتبر الوقت أو المستخدم أو العشوائية مصدراً غير ثابت إلا عبر context snapshot معلن.

## 6. الجداول والقيود والفهارس

### 6.1 `work_type_definitions`

- `id` BIGINT PK.
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `description` TEXT NULL.
- `owner_scope_type` VARCHAR(16) NOT NULL.
- `owner_scope_id` BIGINT NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- `created_by_user_id` BIGINT NOT NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- فهارس: `(owner_scope_type, owner_scope_id, status)`، `(code)`.

### 6.2 `work_type_versions`

- `id` BIGINT PK.
- `work_type_id` BIGINT NOT NULL FK -> `work_type_definitions.id` ON DELETE RESTRICT.
- `version_number` INT NOT NULL.
- `definition_state` VARCHAR(16) NOT NULL (`draft`، `tested`، `approved`، `signed`، `published`).
- `schema_document` JSON NOT NULL.
- `schema_hash` CHAR(64) NOT NULL.
- `dsl_version` VARCHAR(16) NOT NULL.
- `published_at` DATETIME NULL.
- `created_by_user_id` BIGINT NOT NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- قيد فريد على `(work_type_id, version_number)`.
- قيد فريد جزئي أو سياسة تطبيقية تمنع أكثر من Published فعال لنوع واحد.
- فهارس: `(work_type_id, definition_state)`، `(definition_state, published_at)`، `(schema_hash)`.

### 6.3 `field_definitions`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE CASCADE.
- `field_key` VARCHAR(96) NOT NULL.
- `field_type` VARCHAR(32) NOT NULL.
- `required` BOOLEAN NOT NULL DEFAULT FALSE.
- `is_searchable` BOOLEAN NOT NULL DEFAULT FALSE.
- `is_reportable` BOOLEAN NOT NULL DEFAULT FALSE.
- `field_policy_key` VARCHAR(128) NOT NULL.
- `validation_ast` JSON NULL.
- `retired_at` DATETIME NULL.
- قيد فريد على `(work_type_version_id, field_key)`.
- فهارس: `(work_type_version_id, field_type)`، `(work_type_version_id, is_searchable)`، `(field_policy_key)`.

### 6.4 `definition_rules`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE CASCADE.
- `rule_key` VARCHAR(96) NOT NULL.
- `rule_type` VARCHAR(32) NOT NULL (`validation`، `guard`، `visibility`، `calculation`).
- `ast` JSON NOT NULL.
- `dsl_version` VARCHAR(16) NOT NULL.
- `severity` VARCHAR(16) NOT NULL (`error`، `warning`).
- قيد فريد على `(work_type_version_id, rule_key)`.
- فهارس: `(work_type_version_id, rule_type)`، `(severity)`.

### 6.5 `relation_definitions`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE CASCADE.
- `relation_key` VARCHAR(96) NOT NULL.
- `target_kind` VARCHAR(64) NOT NULL.
- `cardinality` VARCHAR(16) NOT NULL.
- `required` BOOLEAN NOT NULL DEFAULT FALSE.
- `authorization_policy_key` VARCHAR(128) NOT NULL.
- قيد فريد على `(work_type_version_id, relation_key)`.
- فهارس: `(target_kind)`، `(work_type_version_id, required)`.

### 6.6 `form_layouts`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE CASCADE.
- `layout_key` VARCHAR(96) NOT NULL.
- `layout_document` JSON NOT NULL.
- قيد فريد على `(work_type_version_id, layout_key)`.

### 6.7 `definition_test_cases`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE CASCADE.
- `case_key` VARCHAR(96) NOT NULL.
- `input_document` JSON NOT NULL.
- `expected_document` JSON NOT NULL.
- `result` VARCHAR(16) NULL (`passed`، `failed`).
- `executed_at` DATETIME NULL.
- قيد فريد على `(work_type_version_id, case_key)`.
- فهارس: `(work_type_version_id, result)`.

### 6.8 `definition_signatures`

- `id` BIGINT PK.
- `work_type_version_id` BIGINT NOT NULL FK -> `work_type_versions.id` ON DELETE RESTRICT.
- `package_hash` CHAR(64) NOT NULL.
- `signature` TEXT NOT NULL.
- `key_id` VARCHAR(128) NOT NULL.
- `signed_by_user_id` BIGINT NOT NULL.
- `signed_at` DATETIME NOT NULL.
- قيد فريد على `(work_type_version_id, package_hash)`.
- فهارس: `(key_id, signed_at)`.

## 7. الأوامر والاستعلامات والأحداث

### 7.1 Commands

- `CreateWorkTypeDraft`
- `CreateWorkTypeVersionDraft`
- `AddFieldDefinition`
- `UpdateDraftFieldDefinition`
- `AddRelationDefinition`
- `ConfigureFieldAccessPolicy`
- `DefineStateTransition`
- `DefineRestrictedDslRule`
- `ConfigureFormLayout`
- `AddDefinitionTestCase`
- `TestWorkTypeVersion`
- `ApproveWorkTypeVersion`
- `SignWorkTypeVersion`
- `PublishWorkTypeVersion`
- `RetirePublishedWorkType`
- `ExportDefinitionPackage`
- `ImportDefinitionPackage`

### 7.2 Queries

- `GetWorkType`
- `GetDraftWorkTypeVersion`
- `GetPublishedWorkTypeSchema`
- `GetPublishedFieldDefinitions`
- `GetFieldAccessPolicy`
- `GetDefinitionTestResults`
- `GetDefinitionPackageManifest`
- `ListPublishedWorkTypes`
- `ValidateDefinitionCompatibility`

### 7.3 Domain وApplication Events

- `WorkTypeCreated`
- `WorkTypeVersionDraftCreated`
- `FieldDefinitionAdded`
- `WorkTypeVersionTested`
- `WorkTypeVersionApproved`
- `WorkTypeVersionSigned`
- `WorkTypeVersionPublished`
- `WorkTypeVersionRetired`
- `DefinitionPackageExported`
- `DefinitionPackageImported`
- `DefinitionCompatibilityFailed`

## 8. State Machines

### 8.1 WorkTypeVersion

- `Draft` --(TestWorkTypeVersion and all blocking tests pass)--> `Tested`.
- `Tested` --(ApproveWorkTypeVersion)--> `Approved`.
- `Approved` --(SignWorkTypeVersion and package hash matches)--> `Signed`.
- `Signed` --(PublishWorkTypeVersion and signature verifies)--> `Published`.
- `Draft` يمكن تعديله، وأي تعديل جوهري بعد `Tested` يعيده إلى `Draft`.
- `Tested` أو `Approved` عند فشل التحقق يعاد إلى `Draft` مع سبب.
- `Signed` و`Published` غير قابلتين للتعديل؛ التغيير ينشئ version جديداً.

### 8.2 DefinitionPackage

- `Built` --(signature created)--> `Signed`.
- `Signed` --(import verification)--> `Verified` أو `Rejected`.
- `Verified` --(publish in target environment)--> `Applied`.
- الحزمة لا تحمل سجلات تشغيلية أو أسراراً.

## 9. الـInvariants

- ترتيب حالة الإصدار إلزامي: `Draft -> Tested -> Approved -> Signed -> Published`.
- لا ينشر إصدار بلا اختبارات blocking ناجحة، واعتماد منفصل، وتوقيع يطابق `schema_hash`.
- لا يوجد أكثر من إصدار Published فعال للعائلة نفسها دون سياسة ترحيل صريحة.
- الإصدار Published immutable، والسجلات الجديدة تشير إلى `work_type_version_id` محدد.
- حذف حقل مستخدم يعني retired أو hidden ولا يتلف القيم التاريخية.
- كل field_key وrelation_key وrule_key فريد داخل الإصدار.
- كل نوع field وoperator وrelation مطابق لقائمة schema المعتمدة.
- كل DSL AST يمر عبر parser وtype checker وlimits، ولا ينفذ code حر.
- لا تشير قاعدة إلى field مخفي أو غير موجود أو إلى إصدار آخر.
- لا يتضمن package بيانات WorkRecords أو credentials أو مفاتيح سرية؛ مفتاح التوقيع يبقى خارج الحزمة.
- لا يغير نشر تعريف حالة أو payload لسجل جارٍ بصمت.
- كل تغيير في Draft يمر عبر Transaction يقودها WorkDefinitions ويكتب Outbox event عند الحاجة.
- لا يملك WorkDefinitions أي جدول من WorkRecords أو Workflow instances.

## 10. الصلاحيات

- السوبر أدمن ينشئ نوع العمل ويعدل المسودة ويحدد الحقول والسياسات.
- اختبار الإصدار واعتماده وتوقيعه أدوار منفصلة حسب سياسة المؤسسة، ولا يعتمد الشخص إصداراً أنشأه إذا كانت سياسة الفصل تمنع ذلك.
- النشر يحتاج قدرة `publish_work_definition` وقراراً مركزياً من Authorization على نطاق Cluster أو Facility المسموح.
- استيراد package يحتاج تحقق hash والتوقيع والبيئة والإصدار، ولا يملك package صلاحيات بذاته.
- WorkRecords يطلب schema وfield policy عبر العقود؛ لا يتجاوز تعريفاً Published بقرار محلي.
- تعريف field policy لا يمنح المستخدم وصولاً إلى البيانات؛ Authorization هو صاحب القرار النهائي من `AuthorizationRecordFacts`.

## 11. الفشل

- حقل مكرر أو field_key غير صالح: يرفض التغيير في Draft.
- AST غير معروف أو يتجاوز limits: يفشل الاختبار ولا ينتقل الإصدار إلى Tested.
- اختبار blocking فاشل: يبقى الإصدار Draft مع نتائج قابلة للمراجعة.
- محاولة Approved بلا Tested أو Signed بلا Approval: ترفض بقاعدة الحالة.
- توقيع لا يطابق schema_hash أو key غير موثوق: يرفض النشر.
- package يحتوي سجلاً أو سراً أو نوعاً غير مسموح: يرفض قبل الاستيراد.
- محاولة تعديل Published: يرفض وينشئ مسودة version جديدة فقط عند طلب صحيح.
- حذف field مستخدم: يحول إلى retired أو يفشل إن كان حذفاً مدمراً.
- تعارض version_number: Rollback مع رقم جديد يختاره النظام.
- فشل Outbox: Rollback للتغيير المالك وعدم إرجاع نشر ناجح.
- فشل Compatibility مع WorkRecords: يمنع النشر أو يطلب migration plan صريحاً.

## 12. الاختبارات

- Unit: انتقالات Draft وTested وApproved وSigned وPublished.
- Unit: canonical AST وtype checking وlimits للـDSL.
- Unit: منع loop وHTTP وdatabase وfunction غير allow-listed.
- Feature: إنشاء schema ثم اختبار حالة صحيحة وحالة فاشلة.
- Feature: التوقيع لا ينجح بعد تغيير schema_hash.
- Feature: الإصدار Published لا يقبل update ويولد version جديداً.
- Contract: `GetPublishedWorkTypeSchema` يعيد إصداراً ثابتاً إلى WorkRecords.
- Contract: `GetFieldAccessPolicy` متسق مع Authorization policy key.
- Security: package لا يحتوي payload أو credentials أو أسراراً.
- Integration: لا يطبق تعريف مستورد دون تحقق التوقيع والبيئة.
- Compatibility: schema الجديد لا يكسر سجلاً جارياً أو يطلب ترحيلاً غير معلن.
- Boundary: WorkDefinitions لا يقرأ جداول WorkRecords ولا Workflow instances.
- Outbox: نشر واحد يصدر event واحداً قابلًا لإعادة المحاولة دون تكرار.

## 13. الاعتماديات

- يعتمد على `Shared/Clock` و`Shared/Identifiers`.
- يعتمد على Authorization لحماية أوامر الإدارة وقوالب الحقول، وعلى Audit/Outbox عبر العقود.
- يقدم إلى WorkRecords schema وpublished version وvalidation contract.
- يقدم إلى Workflow تعريف nodes وtransitions وDSL المقيد عند ربط مسار بنوع عمل.
- لا يعتمد على WorkRecords ولا يملك بيانات التشغيل.
- لا ينفذ Authorization من داخل DSL؛ DSL يقدم facts context، وAuthorization يظل نقطة قرار الوصول.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول WorkDefinitions | توحيد الواجهة الأمامية وحدود الموديول |
