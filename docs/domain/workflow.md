---
doc_id: DOM-WFL-001
title: سير العمل والموافقات
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Workflow
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
- docs/architecture/context-map.md
---
# Workflow

## 1. الغرض

يمثل هذا المجال تعريف مسارات الأعمال وإصداراتها وتنفيذ instances وخطواتها وقراراتها. يملك Workflow الرسم والتنفيذ والمهلة والتصعيد وحل المعتمدين، لكنه لا يملك المعنى التجاري للسجل المصدر أو إغلاقه. كل إصدار تعريف يمر بالحالة `Draft -> Tested -> Approved -> Signed -> Published`، وكل instance يثبت الإصدار الذي بدأ عليه.

عند تفعيل خطوة موافقة، يحل Workflow المعتمدين من Organization وAuthorization مرة واحدة، ثم يحفظ `Approver Snapshot` غير قابل للتغيير على instance. لا تؤدي تغييرات المنصب أو العلاقة بعد التفعيل إلى استبدال المعتمد بصمت؛ تحتاج إعادة تعيين أو تصعيداً صريحاً ومسجلاً.

## 2. النطاق

- إنشاء workflow definition وإصداراته وعقده وانتقالاته.
- دعم الخطوات التسلسلية والمراجعة والموافقة والرفض والإعادة وإنشاء مهمة والانتظار والتصعيد والتفرع والتوازي والدمج ضمن حدود المرحلة.
- اختبار صحة الرسم وقواعد DSL قبل الاعتماد.
- نشر نسخة immutable وتثبيتها على instance عند البدء.
- تفعيل خطوة وحل مسؤولها وحفظ snapshot عند activation.
- استقبال القرار والتحقق من هوية المعتمد وصلاحية التنفيذ وتغيير حالة الخطوة.
- دعم delegate أو fallback أو escalation بسياسة صريحة دون تغيير snapshot الأصلي.
- إصدار أحداث Outbox للخطوات والقرارات والتكاملات.

ما لا يدخل في هذا المجال:

- payload السجل أو معنى `Completed` التجاري؛ يملكه WorkRecords أو الموديول المصدر.
- الأدوار أو كلمة المرور أو الشجرة التنظيمية.
- تعريف حقل ديناميكي؛ يستهلك WorkDefinitions عند الربط.
- كود حر داخل شروط المسار.
- كتابة جداول الموديول المصدر أو المهام مباشرة.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| تعريف المسار (Workflow Definition) | عائلة مسار لها إصدارات قابلة للاختبار والنشر. |
| إصدار المسار (Workflow Version) | رسم ثابت من nodes وtransitions وpolicies، يثبت على instance. |
| العقدة (Node) | خطوة في الرسم مثل Start أو Review أو Approval أو End أو Work Item أو Wait. عقدة `work_item` هي خطوة عمل يُنفّذها شخص، وتُشتق كسجل «مهمة» في موديول Tasks؛ لا تخلط بينها وبين المهمة نفسها. |
| الانتقال (Transition) | حافة من عقدة إلى أخرى مع شرط DSL مقيد وإجراء مسموح. |
| Instance | تنفيذ فعلي لمسار لسجل مصدر واحد. |
| Step Instance | تنفيذ عقدة واحدة مع حالة وتواريخ وقرار ومكلفين. |
| Approver Snapshot | قائمة المعتمدين التي حُلت وحُفظت لحظة تفعيل الخطوة، مع مصدر الحل. |
| Activation | انتقال الخطوة إلى Active بعد تحقق الرسم والسياق وحفظ snapshot. |
| نمط القرار | One، All، Any، Majority، Quorum يحدد متى تكتمل خطوة الموافقة. |
| Fallback | قاعدة معلنة عند شغور المنصب أو تعذر المرشح، وليست إعادة حل صامتة. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 WorkflowDefinitionAggregate

- `WorkflowDefinition` (Entity جذر): workflow_id، code، owner scope.
- `WorkflowVersion` (Entity تابعة): version_number، definition_state، graph_hash.
- `WorkflowNode` (Entity تابعة): node_key، node_type، configuration.
- `WorkflowTransition` (Entity تابعة): from_node، to_node، guard AST، priority.
- `WorkflowDefinitionTest` (Entity تابعة): input context وexpected path/result.

### 4.2 WorkflowInstanceAggregate

- `WorkflowInstance` (Entity جذر): instance_id، source_type، source_id، workflow_version_id، state.
- `WorkflowStepInstance` (Entity تابعة): node_key، state، activated_at، completed_at، lock_version.
- `ApproverSnapshot` (Value Object immutable): user_id، assignment_id، role، unit، source، resolved_at، delegation context.
- `DecisionPolicy` (Value Object): one/all/any/majority/quorum وthreshold.

### 4.3 WorkflowDecisionAggregate

- `WorkflowDecision` (Entity جذر): step_instance_id، actor_user_id، decision، reason، acted_at.
- `DecisionEvidence` (Value Object): version، authorization trace، snapshot reference.
- القرار لا يحذف أو يعدل؛ التصحيح يسجل حدثاً عكسياً أو قراراً لاحقاً محكوماً.

### 4.4 WorkflowFailureAggregate

- `WorkflowFailure` (Entity جذر): instance/step، failure_code، attempts، next_retry_at، resolution.
- `Escalation` (Entity تابعة): target snapshot، reason، deadline.

## 5. حل المعتمد وApprover Snapshot

### 5.1 عند تفعيل الخطوة

1. يتحقق Workflow من أن instance على version منشور ثابت.
2. يقرأ node assignment rule من version ولا يغيرها runtime.
3. يطلب من Organization المرشحين حسب المدير أو الوحدة أو العلاقة أو الدور.
4. يطلب من Authorization قرار صلاحية لكل مرشح وسياق سجل المصدر عبر `AuthorizationRecordFacts`.
5. يطبق fallback المعلن إن كان المرشح شاغراً أو غير مؤهل.
6. يحفظ snapshot لجميع المرشحين المقبولين مع سبب الحل وassignment_id وunit_id ووقت activation.
7. يجعل step Active ويصدر `WorkflowStepActivated` في نفس Transaction المالك.

### 5.2 بعد التفعيل

- snapshot هو قائمة المسؤولين المقصودة ولا يعاد حلها بسبب تغيير تنظيمي عادي.
- عند اتخاذ القرار يعاد التحقق من أن الحساب نشط وأن الفاعل أحد snapshot أو مفوض مسموح، دون استبدال القائمة.
- إذا فقد المعتمد صلاحية التنفيذ بعد التفعيل، تبقى الخطوة معلقة وتحتاج `ReassignWorkflowStep` أو `EscalateWorkflowStep` صريحاً.
- أي إضافة أو إزالة معتمد تحفظ snapshot جديداً مكملاً مع سبب، ولا تمحو snapshot الأصلي.
- يظهر في القرار `snapshot_id` و`authorization_trace_id` لتفسير الفرق بين التعيين الأصلي والصلاحية الحالية.

## 6. DSL المسار المقيد

- شروط الانتقال تخزن AST JSON بإصدار DSL، ولا تنفذ نصاً أو كوداً حراً.
- operators المسموحة: المقارنة، المنطق، membership، وجود قيمة، نطاق تاريخي، وقراءة حقائق معلنة من `AuthorizationRecordFacts`.
- لا يسمح باستدعاء الشبكة أو الملفات أو قاعدة البيانات أو reflection أو loop أو recursion.
- يتحقق compiler من أن field references موجودة في Work Type Version وأن أنواعها متوافقة.
- يفرض evaluator حد عمق وعقد ووقت، ويعيد نتيجة deterministic لنفس context snapshot.
- لا يستطيع DSL منح capability أو تغيير owner أو اختيار مستخدم خارج مرشح Organization/Authorization.
- تغيير DSL أو allow-list يعيد الاختبار والتوقيع والنشر ولا يؤثر في instance قديم.

## 7. الجداول والقيود والفهارس

### 7.1 `workflow_definitions`

- `id` BIGINT PK.
- `code` VARCHAR(96) UNIQUE NOT NULL.
- `name_ar` VARCHAR(255) NOT NULL.
- `name_en` VARCHAR(255) NULL.
- `owner_scope_type` VARCHAR(16) NOT NULL.
- `owner_scope_id` BIGINT NOT NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `active`.
- `created_by_user_id` BIGINT NOT NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- فهارس: `(owner_scope_type, owner_scope_id, status)`.

### 7.2 `workflow_versions`

- `id` BIGINT PK.
- `workflow_definition_id` BIGINT NOT NULL FK -> `workflow_definitions.id` ON DELETE RESTRICT.
- `version_number` INT NOT NULL.
- `definition_state` VARCHAR(16) NOT NULL (`draft`، `tested`، `approved`، `signed`، `published`).
- `graph_document` JSON NOT NULL.
- `graph_hash` CHAR(64) NOT NULL.
- `dsl_version` VARCHAR(16) NOT NULL.
- `published_at` DATETIME NULL.
- قيد فريد على `(workflow_definition_id, version_number)`.
- سياسة تمنع أكثر من Published فعال للعائلة نفسها.
- فهارس: `(workflow_definition_id, definition_state)`، `(definition_state, published_at)`، `(graph_hash)`.

### 7.3 `workflow_nodes`

- `id` BIGINT PK.
- `workflow_version_id` BIGINT NOT NULL FK -> `workflow_versions.id` ON DELETE CASCADE.
- `node_key` VARCHAR(96) NOT NULL.
- `node_type` VARCHAR(32) NOT NULL (`start`، `review`، `approval`، `return`، `work_item`، `wait`، `escalation`، `parallel_split`، `parallel_join`، `end`). سابقاً كان `task`؛ أُعيدت تسميته إلى `work_item` لتمييزه عن سجل «المهمة» في موديول Tasks.
- `assignment_rule` JSON NULL.
- `decision_policy` JSON NULL.
- `configuration` JSON NOT NULL.
- قيد فريد على `(workflow_version_id, node_key)`.
- فهارس: `(workflow_version_id, node_type)`.

### 7.4 `workflow_transitions`

- `id` BIGINT PK.
- `workflow_version_id` BIGINT NOT NULL FK -> `workflow_versions.id` ON DELETE CASCADE.
- `from_node_key` VARCHAR(96) NOT NULL.
- `to_node_key` VARCHAR(96) NOT NULL.
- `transition_key` VARCHAR(96) NOT NULL.
- `guard_ast` JSON NULL.
- `priority` SMALLINT NOT NULL DEFAULT 0.
- قيد فريد على `(workflow_version_id, transition_key)`.
- قيد يمنع from_node = to_node إلا لنمط مصرح.
- فهارس: `(workflow_version_id, from_node_key, priority)`، `(workflow_version_id, to_node_key)`.

### 7.5 `workflow_instances`

- `id` BIGINT PK.
- `workflow_version_id` BIGINT NOT NULL FK -> `workflow_versions.id` ON DELETE RESTRICT.
- `source_type` VARCHAR(96) NOT NULL.
- `source_id` BIGINT NOT NULL.
- `state` VARCHAR(24) NOT NULL DEFAULT `created`.
- `started_by_user_id` BIGINT NOT NULL.
- `started_at` DATETIME NOT NULL.
- `completed_at` DATETIME NULL.
- `lock_version` BIGINT NOT NULL DEFAULT 1.
- قيد فريد على `(source_type, source_id, workflow_version_id)` حسب سياسة تعدد المسارات.
- فهارس: `(source_type, source_id, state)`، `(workflow_version_id, state)`، `(started_at)`.

### 7.6 `workflow_step_instances`

- `id` BIGINT PK.
- `workflow_instance_id` BIGINT NOT NULL FK -> `workflow_instances.id` ON DELETE CASCADE.
- `node_key` VARCHAR(96) NOT NULL.
- `state` VARCHAR(24) NOT NULL.
- `activated_at` DATETIME NULL.
- `completed_at` DATETIME NULL.
- `deadline_at` DATETIME NULL.
- `lock_version` BIGINT NOT NULL DEFAULT 1.
- قيد فريد تشغيلي على `(workflow_instance_id, node_key, activation_sequence)`.
- فهارس: `(state, deadline_at)`، `(workflow_instance_id, state)`، `(node_key, state)`.

### 7.7 `workflow_approver_snapshots`

- `id` BIGINT PK.
- `workflow_step_instance_id` BIGINT NOT NULL FK -> `workflow_step_instances.id` ON DELETE RESTRICT.
- `snapshot_sequence` INT NOT NULL.
- `user_id` BIGINT NOT NULL.
- `assignment_id` BIGINT NULL.
- `role_code` VARCHAR(96) NULL.
- `organization_unit_id` BIGINT NULL.
- `resolution_source` VARCHAR(64) NOT NULL.
- `authorization_trace_id` CHAR(36) NOT NULL.
- `resolved_at` DATETIME NOT NULL.
- `is_active_candidate` BOOLEAN NOT NULL DEFAULT TRUE.
- قيد فريد على `(workflow_step_instance_id, snapshot_sequence, user_id)`.
- فهارس: `(user_id, is_active_candidate)`، `(workflow_step_instance_id, is_active_candidate)`، `(authorization_trace_id)`.

### 7.8 `workflow_decisions`

- `id` BIGINT PK.
- `workflow_step_instance_id` BIGINT NOT NULL FK -> `workflow_step_instances.id` ON DELETE RESTRICT.
- `actor_user_id` BIGINT NOT NULL.
- `decision` VARCHAR(24) NOT NULL (`approve`، `reject`، `return`، `accept`، `decline`).
- `reason` VARCHAR(2000) NULL.
- `snapshot_id` BIGINT NOT NULL FK -> `workflow_approver_snapshots.id`.
- `authorization_trace_id` CHAR(36) NOT NULL.
- `created_at` DATETIME NOT NULL.
- قيد يمنع قراراً مكرراً من الفاعل والخطوة إلا إذا كانت policy تسمح بجولة جديدة.
- فهارس: `(workflow_step_instance_id, created_at)`، `(actor_user_id, created_at)`، `(decision)`.

### 7.9 `workflow_failures`

- `id` BIGINT PK.
- `workflow_instance_id` BIGINT NOT NULL FK -> `workflow_instances.id` ON DELETE RESTRICT.
- `workflow_step_instance_id` BIGINT NULL FK -> `workflow_step_instances.id`.
- `failure_code` VARCHAR(64) NOT NULL.
- `attempts` INT NOT NULL DEFAULT 0.
- `next_retry_at` DATETIME NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `open`.
- `resolution` JSON NULL.
- فهارس: `(status, next_retry_at)`، `(workflow_instance_id, status)`.

## 8. الأوامر والاستعلامات والأحداث

### 8.1 Commands

- `CreateWorkflowDraft`
- `CreateWorkflowVersionDraft`
- `AddWorkflowNode`
- `AddWorkflowTransition`
- `ConfigureAssignmentRule`
- `ConfigureDecisionPolicy`
- `DefineWorkflowDslGuard`
- `AddWorkflowTestCase`
- `TestWorkflowVersion`
- `ApproveWorkflowVersion`
- `SignWorkflowVersion`
- `PublishWorkflowVersion`
- `StartWorkflow`
- `ActivateWorkflowStep`
- `RecordWorkflowDecision`
- `ReturnWorkflowForRevision`
- `ReassignWorkflowStep`
- `EscalateWorkflowStep`
- `CancelWorkflow`
- `RetryWorkflowFailure`

### 8.2 Queries

- `GetPublishedWorkflowVersion`
- `ValidateWorkflowGraph`
- `GetWorkflowInstanceState`
- `GetActiveWorkflowSteps`
- `GetApproverSnapshot`
- `ListMyPendingApprovals`
- `GetWorkflowDecisions`
- `GetWorkflowFailure`
- `ExplainApproverResolution`
- `CheckWorkflowCompatibility`

### 8.3 Domain وApplication Events

- `WorkflowVersionDraftCreated`
- `WorkflowVersionTested`
- `WorkflowVersionApproved`
- `WorkflowVersionSigned`
- `WorkflowVersionPublished`
- `WorkflowStarted`
- `WorkflowStepActivated`
- `ApproverSnapshotCreated`
- `WorkflowDecisionRecorded`
- `WorkflowStepCompleted`
- `WorkflowReturnedForRevision`
- `WorkflowReassigned`
- `WorkflowEscalated`
- `WorkflowCompleted`
- `WorkflowFailed`
- `WorkflowFailureResolved`

## 9. State Machines

### 9.1 WorkflowVersion

- `Draft` --(all graph and DSL tests pass)--> `Tested`.
- `Tested` --(ApproveWorkflowVersion)--> `Approved`.
- `Approved` --(valid signature)--> `Signed`.
- `Signed` --(publish)--> `Published`.
- أي تعديل جوهري على Draft يعيد الاختبار؛ `Signed` و`Published` immutable ويحتاجان version جديداً.

### 9.2 WorkflowInstance

- `Created` --(start and version check)--> `Running`.
- `Running` --(all terminal path conditions)--> `Completed`.
- `Running` --(cancel by policy)--> `Cancelled`.
- `Running` --(unrecoverable failure)--> `Failed` مع حفظ failure record.
- `Failed` --(retry or repair)--> `Running` فقط بأمر مسجل.

### 9.3 WorkflowStepInstance

- `Pending` --(activate and snapshot)--> `Active`.
- `Active` --(decision policy satisfied)--> `Completed`.
- `Active` --(return decision)--> `Returned`.
- `Active` --(deadline)--> `Escalated` إذا كانت السياسة تسمح.
- `Escalated` --(new explicit snapshot/reassignment)--> `Active`.
- `Returned` --(source resubmitted)--> `Pending` أو `Active` وفق الرسم.
- `Completed` حالة نهائية للجولة ولا يعاد فتحها دون انتقال versioned صريح.

## 10. الـInvariants

- لا يبدأ instance إلا على Workflow Version Published، ويثبت `workflow_version_id` طوال التنفيذ.
- ترتيب definition state إلزامي: `Draft -> Tested -> Approved -> Signed -> Published`.
- لا ينشر graph بلا Start وEnd، ولا node قابلة للوصول بلا مسؤول أو fallback معرف.
- لا توجد transition إلى node غير موجودة، ولا حلقة غير منتهية أو merge غير صالح.
- كل guard DSL مقيد ومختبر typed ولا يملك آثاراً جانبية أو استدعاءات خارجية.
- عند Activation ينشأ Approver Snapshot قبل جعل step Active، ولا توجد خطوة موافقة Active بلا snapshot.
- snapshot يثبت المرشحين المقصودين؛ تغير Organization أو الدور لا يعيد حلهم بصمت.
- القرار لا يقبله إلا user في snapshot أو delegate مسموح، مع إعادة فحص حالة الحساب وقرار التنفيذ الحالي.
- إذا فقد snapshot أهليته بعد activation فلا يستبدل تلقائياً؛ يرفع failure أو يحتاج Reassign/Escalate صريحاً.
- كل قرار يرتبط بـsnapshot_id وauthorization_trace_id ولا يمكن نسبته إلى مرشح غير مثبت.
- قواعد All وAny وMajority وQuorum تحسب من snapshot الحالي والقرارات الصحيحة مرة واحدة دون ازدواج.
- لا يملك Workflow دلالة إغلاق source record؛ يرسل نتيجة إلى مالك المصدر ليقرر transition التجاري.
- owner-led transaction: Handler المالك للـWorkflow instance يقود instance وstep وsnapshot وdecision وOutbox معاً.
- عند بدء المسار كجزء من أمر WorkRecords، يمر البدء عبر contract منسق معلن؛ لا توجد Transaction عامة أو كتابة خفية بين الموديولات.
- فشل الإشعار أو الفهرسة لا يلغي قراراً محفوظاً بعد Commit.
- `lock_version` يمنع قرارين متعارضين على الخطوة نفسها من الكتابة الصامتة.

## 11. الصلاحيات

- السوبر أدمن يدير تعريفات المسارات ونشرها وفق فصل الاختبار والاعتماد والتوقيع.
- WorkRecords أو الموديول المصدر يطلب StartWorkflow بعد أن يثبت قدرته على بدء المسار.
- عند Activation، يستخدم Workflow Organization لحل المرشحين وAuthorization لقرار الصلاحية، ولا يمنح assignment rule قدرة بذاتها.
- المعتمد يملك approve أو reject أو return فقط إذا كان في snapshot أو مفوضاً وفق السياسة.
- تغيير المعتمد أو التصعيد يحتاج قدرة مستقلة وسبباً، ويولد snapshot مكملاً وتدقيقاً.
- رؤية pending approvals تمر عبر Authorization ولا تكشف وجود موافقة خارج نطاق المستخدم.
- قرار المعتمد لا يمنح رؤية كل source record fields؛ يعاد القرار باستخدام `AuthorizationRecordFacts` و`ResolveFieldAccess`.
- السوبر أدمن يخضع للتدقيق عند فتح أو تعديل قرار حساس.

## 12. الفشل

- Workflow Version غير Published أو hash غير صحيح: يرفض StartWorkflow.
- graph ناقص أو transition غير صالحة أو DSL غير typed: يفشل Test ولا ينتقل إلى Tested.
- لا يوجد مرشح أو fallback عند Activation: تبقى الخطوة Pending ويخلق WorkflowFailure، ولا تصير Active بلا snapshot.
- Organization أو Authorization لا يعيد مرشحاً صالحاً: Fail Closed مع سبب قابل للتشخيص دون كشف سجل.
- الحساب المثبت Disabled أو منتهي التكليف بعد Activation: يرفض القرار ويطلب Reassign أو Escalate صريحاً.
- محاولة فاعل خارج snapshot: Deny دون كشف أسماء المعتمدين المحميين.
- قرار مزدوج أو تعارض lock_version: يرفض التكرار ويعيد الحالة الحالية.
- تعارض شروط parallel join أو quorum: يبقى المسار Running ويخلق failure للمراجعة.
- deadline يتطلب escalation ولا يوجد target: WorkflowFailure نهائي يحتاج تدخل مالك التعريف.
- فشل Outbox: Rollback لتغيير instance/step/decision في نفس Transaction.
- فشل Notification أو Task بعد Commit: لا يرجع القرار، وتستخدم retry idempotent.
- نشر version جديد: لا يغير instance جارياً ولا snapshot سابقاً.

## 13. الاختبارات

- Unit: graph validation وStart/End وreachable nodes وعدم وجود حلقات غير منتهية.
- Unit: DSL parser وtype checking وlimits وغياب الآثار الجانبية.
- Unit: تعريف state chain Draft-Tested-Approved-Signed-Published.
- Feature: StartWorkflow يثبت version المنشور ولا يلتقط Draft.
- Feature: Activation ينشئ Approver Snapshot قبل Active.
- Authorization contract: مرشح خارج النطاق أو حساب غير نشط لا يدخل snapshot.
- Snapshot behavior: تغير المدير أو العلاقة بعد activation لا يغير snapshot تلقائياً.
- Decision: actor في snapshot يقرر، وactor خارجه يرفض، والمفوض يظهر بالنيابة.
- Decision policies: All وAny وMajority وQuorum مع duplicate decisions.
- Concurrency: قراران متزامنان لا يكتبان transition مزدوجاً.
- Failure: غياب المرشح ينتج Pending/Failure لا Active بلا approver.
- Versioning: instance قديم يكمل versionه عند نشر version جديد.
- Integration: WorkRecords يطلب Start عبر contract ولا يكتب workflow tables مباشرة.
- Outbox: WorkflowStepActivated وWorkflowDecisionRecorded لا يتكرران عند retry.
- Security: لا تظهر pending approval أو source fields غير المسموحة في query.

## 14. الاعتماديات

- يعتمد على `Shared/Clock` و`Shared/Identifiers`.
- يعتمد على Organization لعقد حل المدير والوحدة والعلاقة والتكليف.
- يعتمد على Identity للتحقق من الحساب والملخص، ولا يقرأ credentials.
- يعتمد على Authorization لإصدار القرار لكل مرشح ولكل فعل على المصدر.
- يعتمد على WorkDefinitions عند ربط guard وfield references بنوع عمل منشور.
- يقدم إلى WorkRecords وStrategy وPortfolioProjects وRisk عقود Start وDecision وState.
- يرسل أحداثاً إلى Tasks وNotifications وAudit وSearch عبر Outbox.
- لا يكتب payload أو source state أو جداول الموديولات الأخرى، ولا يملك قرار الإغلاق التجاري.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Workflow | توحيد الواجهة الأمامية وإزالة الاعتماد غير الرسمي |
