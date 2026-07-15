---
doc_id: DOM-STG-001
title: الاستراتيجية والمؤشرات
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Strategy
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/006-workflow-versioning.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/plans/release-2-strategy-portfolio.md
---
# الاستراتيجية والمؤشرات

## 1. الغرض والملكية

يمتلك موديول Strategy الخطط الاستراتيجية وإصداراتها ومحاورها وأهدافها ومبادراتها، ويمتلك حصرياً تعريفات المؤشرات وإصداراتها وفتراتها ومستهدفاتها وقياساتها واعتمادها. لا يجوز لـPortfolioProjects أوRisk أوReporting إنشاء تعريف مؤشر أو نسخة مكررة منه؛ تستخدم هذه الموديولات معرف المؤشر وعقود Strategy وأحداثه.

المبادرة عنصر استراتيجي داخل هذا الموديول، وليست مستوى في تسلسل `المحفظة ← البرنامج ← المشروع`. يجوز ربطها بمشروع أو برنامج عبر عقد، ولا تنتقل ملكيتها إلى موديول المشاريع.

## 2. المصطلحات والنماذج

| المصطلح | التعريف |
|---|---|
| StrategicPlan | خطة محددة النطاق والفترة، تنشر بإصدار ثابت. |
| Axis | محور داخل إصدار خطة. |
| Objective | هدف استراتيجي تابع لمحور. |
| Initiative | مبادرة استراتيجية لتحقيق هدف، ويمكن ربطها بالتنفيذ. |
| Indicator | هوية المؤشر المستقرة وملكيته التنظيمية. |
| IndicatorVersion | تعريف ثابت: الوحدة، الاتجاه، الدورية، المعادلة، البسط والمقام وقواعد الأدلة. |
| IndicatorPeriod | نافذة قياس مشتقة من دورية إصدار المؤشر. |
| TargetDistribution | مستهدف التجمع ومستهدفات الجهات لإصدار مؤشر وفترة. |
| Measurement | قراءة جهة واحدة لفترة محددة، مع البيانات والأدلة ودورة الاعتماد. |
| IndicatorOwner | صاحب المسؤولية التجارية عن التعريف والتوزيع والمراجعة، وليس السوبر أدمن تلقائياً. |
| Coordinator | مدخل القياسات في نطاق تنظيمي محدد. |

### 2.1 Aggregates

- `StrategicPlanAggregate`: الخطة وإصداراتها المنشورة.
- `PlanVersionAggregate`: المحاور والأهداف والمبادرات ضمن نسخة ثابتة.
- `IndicatorAggregate`: هوية المؤشر والمالك والمنسقون والحالة.
- `IndicatorVersionAggregate`: تعريف الحساب والدورية وسياسة الأدلة.
- `TargetDistributionAggregate`: مستهدف التجمع وبنود التوزيع والتحقق والاعتماد.
- `IndicatorPeriodAggregate`: فتح الفترة وإغلاقها وحالة الاكتمال.
- `MeasurementAggregate`: مدخلات الجهة والقيمة المحسوبة والأدلة وقرار الاعتماد.

### 2.2 Value Objects

- `MeasurementPeriod`، `IndicatorUnit`، `Baseline`، `TargetValue`.
- `DesiredDirection`: `higher|lower|within_range`.
- `Frequency`: `monthly|quarterly|semiannual|annual`.
- `AggregationFormula`: `weighted_average|ratio_of_sums|sum|average|latest` مع parameters محكومة بلا كود حر.
- `MeasurementInput`: `numerator` و`denominator` و`manual_value` حسب نوع المعادلة.
- `AchievementResult`: القيمة، نسبة تحقيق المستهدف، الحالة اللونية وسبب الحساب.

## 3. الجداول والقيود والفهارس

### 3.1 `strategic_plans`

- `id` BIGINT PK، `code` VARCHAR(64) UNIQUE NOT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL، `name_en` VARCHAR(255) NULL.
- `start_date`، `end_date` DATE NOT NULL.
- `status` VARCHAR(24) NOT NULL: `draft|in_review|published|retired`.
- `current_version_id` BIGINT NULL، `lock_version` INT NOT NULL DEFAULT 1.
- `created_by_user_id` BIGINT NOT NULL، timestamps.
- قيد: `start_date < end_date`.

### 3.2 `strategic_plan_versions`

- `id` BIGINT PK، `strategic_plan_id` BIGINT NOT NULL FK.
- `version_number` INT NOT NULL، `status` VARCHAR(16) NOT NULL: `draft|published|retired`.
- `effective_from` DATE NOT NULL، `effective_until` DATE NULL.
- `published_by_user_id` BIGINT NULL، `published_at` DATETIME NULL.
- `workflow_version_id` BIGINT NULL.
- قيد فريد: `(strategic_plan_id, version_number)`.
- الإصدار المنشور immutable.

### 3.3 `strategic_axes`

- `id` BIGINT PK، `plan_version_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL، `name_ar` VARCHAR(255) NOT NULL، `description` TEXT NULL.
- `weight` DECIMAL(7,4) NULL، `sort_order` INT NOT NULL.
- قيد فريد: `(plan_version_id, code)`.

### 3.4 `strategic_objectives`

- `id` BIGINT PK، `axis_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL، `name_ar` VARCHAR(255) NOT NULL، `description` TEXT NULL.
- `weight` DECIMAL(7,4) NULL، `owner_organization_unit_id` BIGINT NOT NULL.
- `sort_order` INT NOT NULL.
- قيد فريد: `(axis_id, code)`.

### 3.5 `strategic_initiatives`

- `id` BIGINT PK، `objective_id` BIGINT NOT NULL FK.
- `code` VARCHAR(64) NOT NULL، `name_ar` VARCHAR(255) NOT NULL، `description` TEXT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL، `owner_user_id` BIGINT NOT NULL.
- `status` VARCHAR(24) NOT NULL: `planned|active|on_hold|completed|cancelled`.
- `start_date`، `end_date` DATE NULL، `lock_version` INT NOT NULL DEFAULT 1.
- قيد فريد: `(objective_id, code)`.

### 3.6 `indicators`

- `id` BIGINT PK، `public_id` CHAR(26) UNIQUE NOT NULL، `code` VARCHAR(64) UNIQUE NOT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL، `owner_user_id` BIGINT NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL، `name_en` VARCHAR(255) NULL.
- `classification` VARCHAR(24) NOT NULL: `public|internal|confidential|top_secret`.
- `status` VARCHAR(24) NOT NULL: `draft|active|suspended|retired`.
- `current_version_id` BIGINT NULL، `lock_version` INT NOT NULL DEFAULT 1.
- timestamps، وفهارس `(owner_organization_unit_id, status)`، `(owner_user_id, status)`.

### 3.7 `indicator_versions`

- `id` BIGINT PK، `indicator_id` BIGINT NOT NULL FK، `version_number` INT NOT NULL.
- `description` TEXT NOT NULL، `unit_code` VARCHAR(64) NOT NULL.
- `desired_direction` VARCHAR(24) NOT NULL.
- `range_min`، `range_max` DECIMAL(20,6) NULL.
- `frequency` VARCHAR(24) NOT NULL، `aggregation_formula` VARCHAR(32) NOT NULL.
- `formula_parameters` JSON NOT NULL.
- `requires_numerator`، `requires_denominator` BOOLEAN NOT NULL.
- `baseline_value` DECIMAL(20,6) NULL، `cluster_target_value` DECIMAL(20,6) NULL.
- `evidence_required` BOOLEAN NOT NULL DEFAULT TRUE.
- `measurement_workflow_version_id` BIGINT NOT NULL.
- `distribution_workflow_version_id` BIGINT NOT NULL.
- `effective_from` DATE NOT NULL، `effective_until` DATE NULL.
- `status` VARCHAR(16) NOT NULL: `draft|published|retired`.
- `published_at` DATETIME NULL، `published_by_user_id` BIGINT NULL.
- قيد فريد: `(indicator_id, version_number)`؛ الإصدار المنشور immutable.

### 3.8 `indicator_coordinators`

- `id` BIGINT PK، `indicator_id` BIGINT NOT NULL FK.
- `user_id` BIGINT NOT NULL، `organization_unit_id` BIGINT NOT NULL.
- `valid_from` DATE NOT NULL، `valid_until` DATE NULL.
- قيد يمنع التداخل المكرر للمستخدم والمؤشر والنطاق.
- فهرس: `(user_id, valid_from, valid_until)`.

### 3.9 `indicator_periods`

- `id` BIGINT PK، `indicator_version_id` BIGINT NOT NULL FK.
- `period_key` VARCHAR(32) NOT NULL، `starts_at`، `ends_at` DATE NOT NULL.
- `submission_opens_at`، `submission_closes_at` DATETIME NOT NULL.
- `status` VARCHAR(24) NOT NULL: `scheduled|open|under_review|locked|reopened`.
- `locked_at` DATETIME NULL، `lock_reason` VARCHAR(1000) NULL.
- قيد فريد: `(indicator_version_id, period_key)`.

### 3.10 `indicator_target_distributions`

- `id` BIGINT PK، `indicator_version_id` BIGINT NOT NULL، `period_id` BIGINT NOT NULL.
- `cluster_target_value` DECIMAL(20,6) NOT NULL.
- `expected_aggregate_value` DECIMAL(20,6) NULL.
- `status` VARCHAR(24) NOT NULL: `draft|submitted|in_approval|approved|returned|rejected`.
- `workflow_instance_id` BIGINT NULL، `approved_at` DATETIME NULL.
- `created_by_user_id` BIGINT NOT NULL، `lock_version` INT NOT NULL DEFAULT 1.
- قيد فريد: `(indicator_version_id, period_id)`.

### 3.11 `indicator_targets`

- `id` BIGINT PK، `distribution_id` BIGINT NOT NULL FK.
- `organization_unit_id` BIGINT NOT NULL.
- `target_value` DECIMAL(20,6) NOT NULL.
- `weight_basis` DECIMAL(20,6) NULL؛ حجم العينة المتوقع أو وزن محكوم.
- `rationale` VARCHAR(1000) NULL.
- قيد فريد: `(distribution_id, organization_unit_id)`.

### 3.12 `indicator_measurements`

- `id` BIGINT PK، `indicator_version_id` BIGINT NOT NULL، `period_id` BIGINT NOT NULL.
- `organization_unit_id` BIGINT NOT NULL، `submitted_by_user_id` BIGINT NOT NULL.
- `numerator`، `denominator`، `manual_value` DECIMAL(20,6) NULL.
- `calculated_value` DECIMAL(20,6) NOT NULL.
- `sample_size` DECIMAL(20,6) NULL.
- `status` VARCHAR(24) NOT NULL: `draft|submitted|in_review|returned|approved|rejected|locked`.
- `workflow_instance_id` BIGINT NULL.
- `submission_note` TEXT NULL، `return_reason` TEXT NULL.
- `approved_by_user_id` BIGINT NULL، `approved_at` DATETIME NULL.
- `lock_version` INT NOT NULL DEFAULT 1، timestamps.
- قيد فريد: `(indicator_version_id, period_id, organization_unit_id)`.

### 3.13 `indicator_measurement_evidence`

- `measurement_id` BIGINT NOT NULL، `document_id` BIGINT NOT NULL.
- `added_by_user_id` BIGINT NOT NULL، `created_at` DATETIME NOT NULL.
- قيد فريد: `(measurement_id, document_id)`.
- المستند يظل مملوكاً لـDocuments وتطبق عليه قيوده وروابطه.

## 4. قواعد الحساب

- يحسب الخادم `calculated_value`؛ لا يعتمد على قيمة React.
- `ratio_of_sums = sum(numerator) / sum(denominator)` مع منع denominator صفر.
- `weighted_average = sum(value × weight_basis) / sum(weight_basis)`، وهو القالب الافتراضي لتوزيع المنشآت عند وجود حجم عينة.
- `average = sum(value) / count(valid values)`، و`sum` و`latest` وفق تعريف الإصدار.
- `within_range` ناجح إذا `range_min <= value <= range_max`.
- عند `higher` يحقق التوزيع مستهدف التجمع إذا `expected >= cluster_target`، وعند `lower` إذا `expected <= cluster_target`، وعند `within_range` إذا داخل النطاق.
- التقريب وعدد المنازل وسياسة القيم المفقودة تحفظ في `formula_parameters` وتطبق بصورة واحدة في القياس والتقرير.
- لا يعتمد توزيع المستهدف إذا لم يحقق الناتج المتوقع مستهدف التجمع.

## 5. العقود

### 5.1 Commands

- `CreateStrategicPlan`، `CreatePlanVersion`، `AddStrategicAxis`، `AddStrategicObjective`، `AddStrategicInitiative`.
- `SubmitPlanVersionForApproval`، `PublishStrategicPlanVersion`، `RetireStrategicPlanVersion`.
- `CreateIndicator`، `CreateIndicatorVersion`، `AssignIndicatorOwner`، `AssignIndicatorCoordinator`.
- `ValidateIndicatorVersion`، `PublishIndicatorVersion`، `RetireIndicatorVersion`.
- `OpenIndicatorPeriod`، `ReopenIndicatorPeriod`، `LockIndicatorPeriod`.
- `CreateTargetDistribution`، `SetOrganizationTarget`، `SubmitTargetDistribution`، `ApplyTargetDistributionDecision`.
- `SaveMeasurementDraft`، `SubmitIndicatorMeasurement`، `ApplyMeasurementDecision`.

### 5.2 Queries

- `GetStrategicPlan`، `GetPublishedPlanVersion`، `ListObjectivesByScope`.
- `GetIndicatorDefinition(indicatorId, version?)`.
- `ListIndicatorsByAuthorizedScope(actor, filters)`.
- `GetIndicatorPeriod`، `GetOrganizationTarget`.
- `CalculateExpectedDistribution(distributionId)`.
- `CalculateIndicatorAggregate(indicatorVersionId, periodId, actor)`.
- `GetIndicatorScorecard(indicatorId, scope, periods, actor)`.
- `ListPendingIndicatorMeasurements(actor)`.

### 5.3 العقود المقدمة للموديولات

- `ValidateIndicatorReference(indicatorId, requiredPeriod)`.
- `GetIndicatorReferenceSummary(actor, indicatorId)`.
- `GetIndicatorAchievement(indicatorId, periodId, scope)`.
- `ResolveIndicatorOwner(indicatorId, atTime)`.
- `ValidateInitiativeReference(initiativeId)`.
- `GetObservedIndicatorChange(indicatorId, scope, fromPeriod, toPeriod)` لدعم إسناد أثر المشاريع.

لا يقدم الموديول كتابة مباشرة إلى قياس أو هدف من موديول آخر.

## 6. التكامل مع Workflow والاعتماد

- كل توزيع مستهدف وقياس يحتاج إصدار Workflow منشوراً مثبتاً عند بدء الاعتماد.
- Strategy يبدأ المسار، وWorkflow يحل المعتمد وقت تفعيل الخطوة من Organization وAuthorization.
- قرار Workflow وحده لا يعدل جدول Strategy؛ يستهلك Handler قراراً موثقاً ويطبق الانتقال Idempotently.
- السوبر أدمن يستطيع إدارة التعريفات والصلاحيات والمسارات، لكنه لا يعتمد توزيعاً أو قياساً بدلاً من المعتمد المحلول.
- لا يوجد fallback إلى السوبر أدمن عند شغور المنصب؛ يستخدم المسار البديل المنشور أو يفشل التفعيل إلى قائمة معالجة.
- التفويض الصالح والمسموح بالسياسة فقط يمكنه اتخاذ القرار، ويسجل الفاعل وصاحب الصلاحية.
- لا يجوز لمقدم القياس اعتماد قياسه إذا كانت السياسة تفرض فصل الواجبات.

## 7. الأحداث

- `StrategicPlanCreated`
- `StrategicPlanVersionSubmitted`
- `StrategicPlanVersionPublished`
- `StrategicInitiativeCreated`
- `StrategicInitiativeStatusChanged`
- `IndicatorCreated`
- `IndicatorVersionPublished`
- `IndicatorOwnerAssigned`
- `IndicatorCoordinatorAssigned`
- `IndicatorPeriodOpened`
- `IndicatorTargetDistributed`
- `IndicatorTargetDistributionSubmitted`
- `IndicatorTargetDistributionApproved`
- `IndicatorMeasurementDrafted`
- `IndicatorMeasurementSubmitted`
- `IndicatorMeasurementReturned`
- `IndicatorMeasurementApproved`
- `IndicatorPeriodLocked`
- `IndicatorAggregateRecalculated`

الأحداث تحمل المعرفات والقيم اللازمة فقط، ولا تحمل أسماء أدلة أو حقولاً محجوبة. تستخدم Outbox وschema versioning وIdempotency.

## 8. الحالات

### 8.1 PlanVersion

```text
Draft -> InReview -> Published -> Retired
InReview -> Draft: إعادة للتعديل
InReview -> Rejected: رفض نهائي للإصدار المقترح
```

### 8.2 IndicatorVersion

```text
Draft -> Published -> Retired
```

لا تعديل لمنشور؛ ينشأ إصدار جديد، وتبقى الفترات والقياسات على إصدارها.

### 8.3 TargetDistribution

```text
Draft -> Submitted -> InApproval -> Approved
InApproval -> Returned -> Draft
InApproval -> Rejected
```

### 8.4 Measurement

```text
Draft -> Submitted -> InReview -> Approved -> Locked
InReview -> Returned -> Draft
InReview -> Rejected
Approved -> Draft: فقط عبر ReopenPeriod وإجراء تصحيح مسجل ينشئ مراجعة جديدة
```

### 8.5 IndicatorPeriod

```text
Scheduled -> Open -> UnderReview -> Locked
Locked -> Reopened -> Open: بصلاحية مستقلة وسبب وتدقيق
```

## 9. الـInvariants

- Strategy هو المالك الوحيد للمؤشرات وتعريفاتها وفتراتها ومستهدفاتها وقياساتها.
- لا ينتمي Axis إلا إلى PlanVersion واحد، ولا Objective إلا إلى Axis واحد، ولا Initiative إلا إلى Objective واحد.
- لا تدخل Initiative في تسلسل المحفظة والبرنامج والمشروع.
- الإصدار المنشور ثابت، وكل قياس وتوزيع يثبت `indicator_version_id`.
- لا تتداخل فترات الإصدار نفسه، وتطابق الدورية المنشورة.
- منسق الجهة يدخل قياس نطاقه فقط أثناء نافذة مفتوحة.
- الأدلة إلزامية إذا `evidence_required=true`، ويجب أن تكون إصداراتها متاحة ومصرحاً بها.
- القيمة المحسوبة مشتقة من تعريف الإصدار؛ لا تعدل يدوياً.
- توزيع المستهدف لا يعتمد ما لم يحقق مستهدف التجمع وفق الاتجاه والمعادلة.
- الفترة لا تقفل وفيها قياسات مطلوبة غير محسومة إلا باستثناء حوكمي موثق يحدد القيم المفقودة.
- القياس المعتمد لا يعدل؛ التصحيح يعيد فتح الفترة وينشئ دورة مراجعة مسجلة.
- السوبر أدمن لا يحل محل المعتمد ولا مالك المؤشر في قرار أعمال.
- التجميع لا يكشف قياسات منشأة إذا كانت قدرة المستخدم `view_aggregate` فقط.

## 10. الأمن والصلاحيات

- القدرات الأساسية: `strategy.manage_plan`، `strategy.view`، `indicator.manage_definition`، `indicator.distribute_target`، `indicator.submit_measurement`، `indicator.approve_measurement`، `indicator.export`.
- يقيد ABAC القرار بالجهة والعلاقة الإشرافية ودور المالك/المنسق والفترة والتصنيف.
- رؤية المؤشر المجمع لا تمنح القراءة التفصيلية ولا الأدلة.
- صلاحية القياس لا تمنح تنزيل أدلته؛ Documents يعيد قرار أشد القيود.
- تغيير المالك والتعريف والمعادلة والمستهدف والاعتماد وإعادة الفتح والتصدير تسجل في Audit.
- إدارة المنصة أو إعداد Workflow لا تمنح قدرة الاطلاع على قياسات سرية أو اعتمادها.
- Search وReporting وExport تستخدم Authorization وField access نفسها؛ لا يظهر اسم مؤشر محظور أو عدد قياساته.
- `lock_version` إلزامي للتعديلات المتزامنة.

## 11. الفشل والتعافي

- تعريف صيغة غير صالح أو denominator مطلوب بلا سياسة صفر: يمنع النشر برسالة تفصيلية.
- مستهدفات ناقصة أو أوزانها صفر: يمنع الإرسال للاعتماد.
- الناتج المتوقع لا يحقق مستهدف التجمع: يبقى التوزيع Draft/Returned مع تفسير الحساب.
- قياس denominator صفر: يرفض الإرسال أو يطبق سياسة missing المنشورة؛ لا ينتج Infinity.
- دليل quarantined أو غير مصرح: يمنع الإرسال.
- شغور المعتمد وعدم وجود fallback: لا يبدأ القرار ولا يستبدله الأدمن؛ تسجل حالة `approver_unresolved` للمراجعة.
- فشل Workflow بعد حفظ المسودة لا يفقدها؛ بدء المسار Idempotent.
- حدث Workflow مكرر لا يكرر الاعتماد.
- فشل تحديث لوحة أو فهرس لا يرجع القياس المعتمد؛ يعاد بناء الإسقاط.
- تعارض إصدار: `409` مع القيم الحالية.
- تغيير تكليف المنسق أثناء فترة مفتوحة يطبق عند القرار التالي ولا يغير من نفذ إدخالاً سابقاً.

## 12. الاختبارات ومعايير القبول

### 12.1 المجال والحساب

- نشر خطة بمحاور وأهداف ومبادرات، ومنع تعديل الإصدار المنشور.
- منع إدخال Initiative في hierarchy المشاريع.
- حساب `ratio_of_sums` و`weighted_average` وحالات higher/lower/within_range بدقة وتقريب منشور.
- منع اعتماد توزيع لا يحقق مستهدف التجمع.
- إنشاء فترات شهرية وربع سنوية دون تداخل.
- منع تعديل قياس معتمد دون Reopen.

### 12.2 Workflow وفصل الواجبات

- منسق مستشفى يرسل قياساً، ومالك المؤشر المحلول يعتمده.
- المعتمد يعيد القياس بسبب واضح فيعود Draft.
- سوبر أدمن غير محلول يحاول الاعتماد فيفشل.
- مفوض صالح يعتمد ويظهر الاسمان، ومفوض منتهي يفشل.
- شغور المنصب يستخدم fallback المنشور فقط.

### 12.3 الأمن والعزل

- مسؤول يملك `view_aggregate` يرى التجميع ولا يرى قياسات المنشآت أو الأدلة.
- مستشفيان معزولان؛ منسق أحدهما لا يرى أو يعدل الآخر.
- Field access يخفي numerator/denominator مع السماح بالقيمة النهائية عند السياسة.
- Search/report/export لا تكشف مؤشر أو حقل أو دليلاً محظوراً.

### 12.4 العقود والأحداث

- PortfolioProjects وRisk يتحققان من مرجع مؤشر عبر Contract دون قراءة الجداول.
- Schema tests للأحداث وOutbox مرة واحدة مع المعاملة.
- إعادة حدث `WorkflowCompleted` و`IndicatorMeasurementSubmitted` Idempotent.
- إعادة بناء scorecard من القياسات المعتمدة يعطي النتيجة نفسها.

### 12.5 القبول

- إنشاء خطة ومحاور وأهداف ومبادرات ومؤشر بإصدار منشور.
- توزيع مستهدفات مختلفة على منشآت واعتمادها بعد تحقيق مستهدف التجمع.
- فتح فترة، إدخال بسط ومقام وأدلة، إعادتها، إعادة إرسالها، اعتمادها وقفلها.
- عرض بطاقة تجمع وبطاقة منشأة حسب الصلاحية.

## 13. الاعتماديات وحدود التكامل

- يعتمد على Organization للجهات والملاك والمنسقين وحل المعتمد، وIdentity لملخصات المستخدمين.
- يعتمد على Authorization للقدرات والنطاق والتفويض والتصنيف، وعلى Workflow للموافقات بإصدارات ثابتة.
- يعتمد على Documents للأدلة بمعرفات وروابط فقط، وعلى Audit للأفعال الحساسة.
- يعتمد على Notifications/Reporting كقدرات مشتقة؛ فشلها لا يغير حقائق Strategy.
- يعتمد عليه PortfolioProjects للتحقق من المؤشرات والمبادرات وقراءة التغير المرصود، ويعتمد عليه Risk لربط مؤشرات المخاطر.
- لا يقرأ PortfolioProjects أوRisk جداول المؤشرات ولا يكتبان فيها.
- لا يعتمد Strategy على تفاصيل PortfolioProjects أوRisk؛ التكامل العكسي عبر أحداث وروابط أو Read Models محكومة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Strategy | توحيد الواجهة الأمامية وتثبيت ملكية المؤشرات |
