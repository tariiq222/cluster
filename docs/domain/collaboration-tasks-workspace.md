---
doc_id: DOM-CTW-001
title: التعاون والمهام ومساحة العمل
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديولات Collaboration وTasks وWorkspace
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/007-transactional-outbox.md
- docs/adr/017-derived-workspace-and-notifications.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# التعاون والمهام ومساحة العمل

## 1. الغرض والحدود

يوفر هذا المجال تعاوناً عاماً قابلاً للاستخدام من أي موديول دون معرفة تفاصيله: مهام مستقلة أو مرتبطة بسجل مصدر، مسؤول واحد، مشاركون، تعليقات، منشن، إسناد عابر للوحدات بقبول صريح، واعتماد إنجاز اختياري. كما يبني `Workspace` بوصفها Read Model شخصية تجمع ما يحتاج المستخدم إلى فعله من المهام والموافقات والمنشنات والسجلات المعادة والمتأخرات.

لا يملك هذا المجال حالة الطلب أو المشروع أو الخطر، ولا ينسخ حقول المصدر، ولا يجعل مساحة العمل مصدر حقيقة. الموديول المصدر يبقى مالك معنى المهمة المرتبطة وسياسة الإغلاق وملخص المصدر المصرح به.

حدود الإصدار الأول:

- لا توجد مهام فرعية.
- لا توجد محادثة مستقلة عن مهمة أو سجل أعمال.
- لا توجد قنوات تعاون خارج المنصة.
- الإسناد العابر لوحدة المسؤول الحالي أو المنشئ يحتاج قبول المرشح صراحة، حتى مع وجود قدرة الإسناد.

## 2. المصطلحات والنماذج

| المصطلح | التعريف |
|---|---|
| Task | وحدة عمل واحدة لها مسؤول مؤكد واحد بعد التفعيل. |
| SourceReference | مرجع اختياري عام: `module_code` و`record_type` و`record_id`، دون FK إلى جدول أعمال. |
| Assignee | المستخدم المسؤول عن التنفيذ، ولا يوجد أكثر من مسؤول مؤكد في اللحظة نفسها. |
| Participant | مستخدم يستطيع رؤية المهمة والتعليق والإرفاق والمنشن وفق سياسة المهمة. |
| AssignmentOffer | عرض إسناد عابر للوحدات لا يغير المسؤولية قبل القبول. |
| CompletionPolicy | إغلاق مباشر أو إرسال إنجاز إلى معتمد محدد بالسياسة. |
| Mention | إشارة إلى مستخدم في تعليق؛ تضيفه مشاركاً إذا كان مسموحاً، ولا تمنحه وصولاً إلى المصدر. |
| WorkspaceItem | مرشح عمل مشتق لمستخدم؛ لا يمثل حقيقة تشغيلية ولا قرار وصول نهائياً. |

### 2.1 Aggregates

- `TaskAggregate`: المهمة، المسؤول، سياسة الإنجاز، الحالة، الأولوية، الموعد، التصنيف ومرجع المصدر.
- `AssignmentOfferAggregate`: المرشح، الوحدة المرسلة والمستقبلة، المدة، القرار والسبب.
- `TaskConversationAggregate`: المشاركون والتعليقات والمنشنات والمرفقات المرجعية.
- `WorkspaceProjection`: إسقاط قراءة مشتق لكل مستخدم، خارج معاملات حقائق المصدر.

### 2.2 Value Objects

- `TaskId`، `SourceReference`، `OrganizationScope`، `TaskPriority`، `DueAt`.
- `CompletionPolicy`: `direct` أو `requires_acceptance` مع استراتيجية حل المعتمد.
- `AssignmentContext`: الوحدة التي يعمل منها المسند والوحدة الأساسية للمرشح.
- `AuthorizationRecordFacts`: حقائق المهمة التي يقدمها الموديول إلى Authorization، بلا سماح أو منع أو قرار حقول محلي.

## 3. ملكية البيانات والجداول

جميع الطوابع تخزن UTC وتعرض وفق `Asia/Riyadh`. الجداول التالية يملكها الموديول ولا يقرأها موديول أعمال مباشرة.

### 3.1 `tasks`

- `id` BIGINT PK.
- `public_id` CHAR(26) UNIQUE NOT NULL.
- `title` VARCHAR(255) NOT NULL.
- `description` TEXT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL.
- `created_by_user_id` BIGINT NOT NULL.
- `assignee_user_id` BIGINT NULL؛ يكون NULL فقط أثناء عرض الإسناد الأول.
- `assignee_organization_unit_id` BIGINT NULL.
- `source_module` VARCHAR(64) NULL.
- `source_type` VARCHAR(64) NULL.
- `source_id` VARCHAR(128) NULL.
- `status` VARCHAR(40) NOT NULL.
- `priority` VARCHAR(16) NOT NULL DEFAULT `normal`.
- `due_at` DATETIME NULL.
- `classification` VARCHAR(24) NOT NULL DEFAULT `internal`: `public|internal|confidential|top_secret`.
- `completion_policy` VARCHAR(32) NOT NULL.
- `completion_approver_strategy` JSON NULL؛ تعريف حل المعتمد، لا هوية أدمن بديلة.
- `resolved_completion_approver_user_id` BIGINT NULL؛ يثبت عند إرسال الإنجاز.
- `source_policy_snapshot` JSON NULL؛ يحفظ القواعد اللازمة لا معنى أو حقول المصدر.
- `lock_version` INT NOT NULL DEFAULT 1.
- `created_at`، `updated_at`، `completed_at`، `cancelled_at` DATETIME NULL.
- قيد: حقول `source_*` إما جميعها NULL أو جميعها غير NULL.
- فهارس: `(assignee_user_id, status, due_at)`، `(owner_organization_unit_id, status)`، `(source_module, source_type, source_id)`، `(classification)`.

### 3.2 `task_assignment_offers`

- `id` BIGINT PK، `task_id` BIGINT NOT NULL FK.
- `candidate_user_id` BIGINT NOT NULL.
- `from_organization_unit_id` و`to_organization_unit_id` BIGINT NOT NULL.
- `offered_by_user_id` BIGINT NOT NULL.
- `status` VARCHAR(24) NOT NULL: `pending|accepted|rejected|expired|cancelled`.
- `expires_at` DATETIME NULL.
- `decision_reason` VARCHAR(1000) NULL.
- `decided_at` DATETIME NULL، `created_at` DATETIME NOT NULL.
- قيد فريد جزئي منطقي: عرض `pending` واحد فقط للمهمة.
- فهارس: `(candidate_user_id, status, expires_at)`، `(task_id, created_at)`.

### 3.3 `task_participants`

- `task_id` BIGINT NOT NULL FK، `user_id` BIGINT NOT NULL.
- `role` VARCHAR(24) NOT NULL: `creator|assignee|participant|watcher`.
- `added_by_user_id` BIGINT NOT NULL، `added_via` VARCHAR(24) NOT NULL: `explicit|mention|assignment|source`.
- `created_at` DATETIME NOT NULL، `removed_at` DATETIME NULL.
- قيد فريد على العضوية النشطة `(task_id, user_id)`.
- المشاركة تمنح المهمة فقط، ولا تمنح السجل المصدر.

### 3.4 `task_comments`

- `id` BIGINT PK، `task_id` BIGINT NOT NULL FK، `author_user_id` BIGINT NOT NULL.
- `body` TEXT NOT NULL، `classification` VARCHAR(24) NOT NULL: `public|internal|confidential|top_secret`.
- `created_at` DATETIME NOT NULL، `edited_at` DATETIME NULL، `deleted_at` DATETIME NULL.
- لا حذف نهائياً من الواجهة؛ يحتفظ النشاط بالبصمة والفاعل.
- فهرس: `(task_id, created_at)`.

### 3.5 `task_mentions`

- `id` BIGINT PK، `task_id` BIGINT NOT NULL، `comment_id` BIGINT NOT NULL.
- `mentioned_user_id` BIGINT NOT NULL، `mentioned_by_user_id` BIGINT NOT NULL.
- `created_at` DATETIME NOT NULL.
- قيد فريد: `(comment_id, mentioned_user_id)`.

### 3.6 `task_activity`

- `id` BIGINT PK، `task_id` BIGINT NOT NULL.
- `activity_type` VARCHAR(64) NOT NULL، `actor_user_id` BIGINT NULL.
- `before_payload` JSON NULL، `after_payload` JSON NULL، `reason` VARCHAR(1000) NULL.
- `occurred_at` DATETIME NOT NULL، `event_id` CHAR(36) UNIQUE NOT NULL.
- Append-only؛ لا Update ولا Delete لتطبيق المستخدم.

### 3.7 `workspace_items`

- `id` BIGINT PK، `user_id` BIGINT NOT NULL.
- `item_key` VARCHAR(255) NOT NULL؛ مفتاح ثابت من المصدر.
- `source_module`، `source_type` VARCHAR(64) NOT NULL، `source_id` VARCHAR(128) NOT NULL.
- `item_kind` VARCHAR(32) NOT NULL: `task|approval|mention|returned_record|overdue|assignment_offer`.
- `action_code` VARCHAR(64) NOT NULL.
- `priority` VARCHAR(16) NOT NULL، `due_at` DATETIME NULL.
- `source_version` BIGINT NULL، `projection_status` VARCHAR(16) NOT NULL.
- `safe_label_key` VARCHAR(128) NOT NULL؛ مفتاح ترجمة عام فقط، ولا يخزن عنواناً حساساً.
- `created_at`، `updated_at` DATETIME NOT NULL، `resolved_at` DATETIME NULL.
- قيد فريد: `(user_id, item_key)`.
- فهارس: `(user_id, projection_status, due_at)`، `(source_module, source_type, source_id)`.

### 3.8 `workspace_projection_checkpoints`

- `consumer_name` VARCHAR(128) PK، `last_event_id` CHAR(36) NULL.
- `last_occurred_at`، `updated_at` DATETIME NULL، `lag_seconds` INT NOT NULL DEFAULT 0.

## 4. العقود

### 4.1 Commands

- `CreateStandaloneTask(command): TaskId`.
- `CreateLinkedTask(command, SourceReference, SourceTaskPolicy): TaskId`.
- `AssignTask(taskId, assigneeId, actingScope, expectedVersion)`.
- `OfferCrossUnitTaskAssignment(taskId, candidateId, expiresAt)`.
- `AcceptCrossUnitTaskAssignment(offerId, candidateId)`.
- `RejectCrossUnitTaskAssignment(offerId, candidateId, reason)`.
- `AddTaskParticipant(taskId, userId)` و`RemoveTaskParticipant`.
- `AddTaskComment(taskId, body, mentionedUserIds[])`.
- `ChangeTaskDueDate`، `ChangeTaskPriority`، `BlockTask`، `UnblockTask`.
- `SubmitTaskCompletion(taskId, evidenceDocumentIds[], note)`.
- `AcceptTaskCompletion(taskId, decisionId)` و`ReturnTaskCompletion(taskId, decisionId, reason)`.
- `CompleteTaskDirectly(taskId)` و`CancelTask(taskId, reason)`.

كل Command يحمل `actor_user_id` و`acting_organization_unit_id` و`idempotency_key` و`expected_lock_version` عند التعديل.

### 4.2 Queries

- `GetTask(taskId, actorContext): AuthorizedTaskView`.
- `ListMyTasks(actorContext, filters, cursor)`.
- `ListTasksForOrganizationScope(actorContext, scope, filters, cursor)`.
- `ListPendingAssignmentOffers(actorContext)`.
- `ListTaskActivity(taskId, actorContext)`.
- `BuildMyWorkspace(actorContext, filters, cursor)`.
- `CountMyWorkspaceItems(actorContext)`.

`BuildMyWorkspace` يعيد التحقق من كل مرشح عبر Authorization وعقد ملخص المصدر، ويزيل أو يخفي المرشح إذا انتهت الصلاحية. لا يعيد Eloquent models ولا عناوين من الإسقاط قبل السماح.

### 4.3 العقود التي يقدمها الموديول

- `CreateTask` للموديولات المالكة للمصادر.
- `GetTaskSummary`.
- `GetTasksBySourceReference`.
- `ResolveTaskParticipation`.
- `ProjectWorkspaceCandidate` و`ResolveWorkspaceCandidate`.

### 4.4 العقود المطلوبة من المصدر

كل موديول يسمح بمهام مرتبطة ينفذ:

- `GetAuthorizationRecordFacts(source): AuthorizationRecordFacts`؛ وهو عقد الوصول الوحيد الذي يقدمه المالك.
- `ResolveSourceCompletionPolicy(source)`.
- `ValidateTaskSourceExists(source)`.

تمرير `AuthorizationRecordFacts` إلى `DecideAccess` وإصدار Allow أو Deny وقرارات الحقول مسؤولية Authorization وحده. لا يعيد Workspace عنوان المصدر أو ملخصه؛ يفتح endpoint المالك الذي يعيد القرار من الحقائق الحديثة. تعطل عقد المصدر يؤدي إلى منع آمن، لا إلى منح وصول مؤقت.

## 5. الأحداث

تكتب الأحداث في Transactional Outbox مع `event_id` و`event_type` و`occurred_at` و`schema_version` وpayload أدنى بلا نصوص حساسة.

- `TaskCreated`
- `TaskAssignmentOffered`
- `TaskAssignmentAccepted`
- `TaskAssignmentRejected`
- `TaskAssigned`
- `TaskParticipantAdded`
- `TaskParticipantRemoved`
- `TaskCommentAdded`
- `TaskParticipantMentioned`
- `TaskStarted`
- `TaskBlocked`
- `TaskUnblocked`
- `TaskCompletionSubmitted`
- `TaskCompletionReturned`
- `TaskCompleted`
- `TaskCancelled`
- `WorkspaceCandidateAdded`
- `WorkspaceCandidateResolved`

المستهلكات Idempotent بمفتاح `event_id`. فشل الإشعار أو إسقاط Workspace لا يرجع حقيقة المهمة.

## 6. الحالات والانتقالات

### 6.1 Task

```text
PendingAssignmentAcceptance -> Open: قبول أول إسناد عابر للوحدة
PendingAssignmentAcceptance -> Cancelled: رفض بلا بديل أو إلغاء مخول
Open -> InProgress: بدء المسؤول المؤكد
Open -> Cancelled: إلغاء مخول
InProgress -> Blocked: تسجيل عائق وسبب
Blocked -> InProgress: إزالة العائق
InProgress -> PendingAcceptance: إرسال الإنجاز عندما completion_policy=requires_acceptance
PendingAcceptance -> InProgress: إعادة الإنجاز مع سبب
PendingAcceptance -> Completed: اعتماد المعتمد المحلول
InProgress -> Completed: إغلاق مباشر عندما completion_policy=direct
Completed | Cancelled: حالات نهائية
```

`PendingAssignmentAcceptance` يخص قبول الإسناد، و`PendingAcceptance` يخص اعتماد الإنجاز؛ لا يجوز الخلط بينهما في API أو الواجهة.

### 6.2 AssignmentOffer

```text
Pending -> Accepted | Rejected | Expired | Cancelled
```

قبول العرض يثبت المسؤول ويضيفه مشاركاً في Transaction واحدة. عند إعادة الإسناد يبقى المسؤول الحالي مسؤولاً حتى قبول المرشح الجديد.

### 6.3 WorkspaceItem

```text
Active -> Resolved
Active -> Suppressed: منع الوصول أو إلغاء المصدر
Resolved | Suppressed -> Active: حدث مصدر أحدث يعيد فتح العمل
```

## 7. الـInvariants

- للمهمة التشغيلية مسؤول مؤكد واحد فقط؛ لا تدخل `InProgress` دون مسؤول.
- الإسناد بين وحدتين مختلفتين لا يصبح نافذاً قبل قبول المرشح، ولا يستطيع المسند قبول العرض نيابة عنه.
- القدرة على الإسناد العابر تسمح بإنشاء العرض فقط ولا تلغي شرط القبول.
- المنشن يضيف المستخدم مشاركاً في المهمة عند السماح، لكنه لا يمنحه أي قدرة على `SourceReference` ولا يكشف عنوان المصدر أو حقوله.
- رؤية المهمة المرتبطة لا تعني رؤية المصدر؛ يعاد فحص المصدر عند فتحه.
- المشارك يعلق ويرفق ويمنشن، ولا يغير المسؤول أو الحالة أو الموعد أو الأولوية إلا بقدرة مستقلة.
- سياسة الإنجاز تثبت عند الإنشاء؛ تغيير سياسة المصدر لاحقاً لا يغير مهمة جارية بصمت.
- `AcceptTaskCompletion` ينفذه المعتمد المحلول أو مفوض صالح فقط. السوبر أدمن لا يعتمد بدلاً من المعتمد لمجرد امتلاكه دور الإدارة.
- لا يمكن لمن أرسل الإنجاز اعتماده لنفسه إذا كانت السياسة تفصل المنفذ عن المعتمد.
- لا توجد علاقة parent/child بين المهام في الإصدار الأول.
- Workspace مشتقة ومتسقة نهائياً؛ المصدر والعقود هما الحقيقة.
- أي تغيير حالة وتاريخ ومسؤول وأولوية يسجل في `task_activity` ويصدر حدثاً واحداً.

## 8. الأمن والصلاحيات

- كل كتابة تبدأ بـ`DecideAccess` باستخدام القدرة والنطاق والعلاقة والتصنيف والحالة والتكليف الساري.
- يبني Tasks `AuthorizationRecordFacts` من تصنيف المهمة ومشاركتها، ثم يطبق قرار Authorization؛ ولا يقرر الوصول محلياً أو يدمج صلاحية المصدر تلقائياً.
- فتح رابط المصدر يتطلب `AuthorizationRecordFacts` حديثة من الموديول المصدر وقراراً جديداً من Authorization.
- النصوص والمرفقات ترث على الأقل تصنيف المهمة؛ المستندات تطبق أشد قيودها وروابطها وفق مواصفة Documents.
- لا تحتوي أحداث المنشن أو Workspace على عنوان مصدر أو مقتطف حساس.
- يسجل Audit الإسناد العابر، قبول/رفض العرض، تغيير المسؤول، الاعتماد، التفويض، والاطلاع على المحتوى الحساس.
- التفويض محدد المدة والموديول ويظهر الفاعل وصاحب الصلاحية؛ لا يسمح بتفويض محظور بالسياسة.
- استعلامات القوائم تطبق Scope filters مركزية وField access، ولا تعتمد على إخفاء React.
- تستخدم جميع التعديلات `lock_version` لمنع الاستبدال الصامت.

## 9. الفشل والتعافي

- مرشح غير نشط أو تكليفه منتهٍ: يرفض العرض قبل الحفظ.
- انتهاء العرض: لا يمكن قبوله؛ ينشأ عرض جديد.
- رفض الإسناد الأول: تعود المهمة للمنشئ كعنصر يحتاج إعادة إسناد أو تلغى وفق السياسة.
- فشل حل المعتمد عند إرسال الإنجاز: تبقى المهمة `InProgress` وتظهر رسالة قابلة للتفسير؛ لا يستخدم السوبر أدمن كبديل.
- تعطل المصدر عند فتحه أو بناء Workspace: منع آمن مع إظهار عنصر عام بلا عنوان حساس، ثم إعادة المحاولة.
- حدث مكرر: يتجاهله المستهلك بعد التحقق من `event_id`.
- فشل إسقاط Workspace أو الإشعار: تبقى المهمة صحيحة، وتطبق retry ثم dead-letter قابلة للمراجعة.
- تعارض `lock_version`: يرجع `409 Conflict` مع النسخة الحالية دون فقد مدخل المستخدم.
- فشل Outbox: ترجع معاملة تغيير المهمة كاملة.
- فشل فحص مرفق: لا ينشر التعليق المرفق كمتاح حتى تقرر Documents سلامة الإصدار.

## 10. الاختبارات ومعايير القبول

### 10.1 اختبارات المجال

- مهمة لا تنتقل إلى `InProgress` بلا مسؤول مؤكد.
- إسناد داخل الوحدة ينجح مباشرة للمخول.
- إسناد عابر للوحدة ينشئ عرضاً ولا يغير المسؤول قبل القبول.
- قبول المرشح يغير المسؤول مرة واحدة، ورفضه لا يغيره.
- المشارك لا يغير المسؤول أو الحالة دون قدرة.
- الإغلاق المباشر ممنوع عندما السياسة تتطلب اعتماداً.
- السوبر أدمن غير المحلول كمعتمد يفشل في اعتماد الإنجاز.

### 10.2 اختبارات الأمن والعزل

- منشن مستخدم من مستشفى آخر يمنحه المهمة فقط، ولا يمنحه المصدر ولا عنوانه ولا حقوله.
- مستخدم يملك المهمة ولا يملك المصدر يرى رابطاً غير قابل للفتح بلا بيانات مصدر.
- انتهاء علاقة إشرافية يسحب عناصر Workspace المرتبطة في القراءة التالية.
- حقل أو تعليق سري لا يظهر لمشارك دون التصنيف المطلوب.
- البحث والتقرير عن المهام لا يتجاوزان السياسة نفسها.

### 10.3 اختبارات العقود والأحداث

- Contract test لكل عقد مصدر مدعوم.
- Schema test لكل حدث ورفض payload يحوي نص المصدر.
- Outbox test يثبت الحدث مرة واحدة مع Commit وعدم وجوده مع Rollback.
- Idempotency test لإعادة `TaskAssignmentAccepted` و`TaskCompleted`.
- Projection test لإعادة بناء Workspace من الأحداث.

### 10.4 اختبارات الرحلات

- مدير يسند لموظف في وحدته، يبدأ الموظف وينجز مباشرة.
- مسؤول تجمع يعرض مهمة على موظف مستشفى، يقبلها الموظف، ثم تظهر في مساحة عمله.
- موظف يمنشن زميلاً؛ ينضم الزميل ويعلق دون فتح سجل العمل المصدر.
- مهمة مرتبطة بمشروع ترسل للاعتماد، ويعتمدها المعتمد المحلول لا الأدمن.
- فشل عامل Workspace ثم إعادة تشغيله لا يفقد العنصر ولا يكرره.

## 11. الاعتماديات وحدود التكامل

- يعتمد على Organization لحل الوحدات والمدير والعلاقات، وعلى Identity لملخص المستخدم وحالة الحساب.
- يعتمد على Authorization لقرارات القدرة والنطاق والتصنيف والتفويض.
- يعتمد على Documents للمرفقات بمعرّفات وعقود فقط، وعلى Audit للأفعال الحساسة.
- تستهلك Notifications أحداثه لإنشاء إشعارات داخل المنصة.
- تستهلك Search وReporting أحداثه لبناء إسقاطات محكومة.
- تستخدم WorkRecords وPortfolioProjects وRisk عقد `CreateTask` ولا تقرأ جداول المهام.
- لا يعتمد على تفاصيل أي موديول أعمال؛ تكامل المصدر يتم عبر `SourceReference` والعقود المنشورة.
- لا يكتب Workspace في جداول المصادر ولا يستخدم كمرجع لاعتماد أو تغيير حالة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديولات Collaboration وTasks وWorkspace | توحيد الواجهة الأمامية وإزالة تسمية غير رسمية |
