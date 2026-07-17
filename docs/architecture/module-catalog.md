---
doc_id: ARC-MC-001
title: كتالوج الموديولات canonical
type: architecture
status: accepted
version: 1.2.0
date: '2026-07-15'
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
- مسؤول المنتج
classification: internal
review_cycle: نصف سنوي
sources: []
references: []
---
# كتالوج الموديولات

## 1. قواعد الكتالوج

- الموديولات التالية وعددها تسعة عشر هي الحدود القانونية الوحيدة.
- كل موديول يملك مجاله وجداوله وترحيلاته وواجهاته وأحداثه واختباراته.
- العقود أسماء توجيهية canonical؛ يمكن إثراء حقول DTO دون تغيير الملكية أو اتجاه الاعتماد.
- لا يعيد أي عقد نموذج ORM أو يتيح Query Builder أو اسم جدول.
- جميع عمليات الوصول تمر عبر `Authorization` باستخدام `RecordFacts` يبنيها المالك.
- جميع التغييرات العابرة غير الفورية تنشر عبر Transactional Outbox بتسليم at-least-once.

## 2. الملخص

| الموديول | المسؤولية المختصرة | الرتبة |
|---|---|---:|
| `PlatformSettings` | إعدادات المنصة العامة المصدرة بإصدارات | 0 |
| `Organization` | الهيكل والمناصب والتكليفات والعلاقات التنظيمية | 0 |
| `Identity` | الحسابات والجلسات والملف التشغيلي | 1 |
| `Authorization` | قرار وصول مركزي RBAC + ABAC | 2 |
| `Audit` | تدقيق append-only ووصول حساس | 3 |
| `Workflow` | تعريف وتنفيذ المسارات بإصدارات ثابتة | 4 |
| `RecordsGovernance` | الاحتفاظ والحجز والإتلاف المحكوم | 4 |
| `WorkDefinitions` | أنواع العمل والنماذج والحقول والإصدارات | 5 |
| `Documents` | الملفات والإصدارات والتصنيف والروابط | 5 |
| `Collaboration` | النقاش والتعليقات والمنشن والمشاركون | 6 |
| `Tasks` | المهمة والمسؤول والموعد ودورة الحياة | 7 |
| `WorkRecords` | مثيلات الأعمال الديناميكية، بما فيها الطلبات | 8 |
| `Strategy` | الخطط والأهداف والمبادرات والمؤشرات | 8 |
| `PortfolioProjects` | المحافظ والبرامج والمشاريع | 9 |
| `Risk` | المخاطر والضوابط وخطط المعالجة | 10 |
| `Notifications` | إشعارات داخل المنصة | 11 |
| `Search` | فهرس بحث داخلي محكوم | 11 |
| `Reporting` | التقارير واللوحات وRead Models | 11 |
| `Workspace` | مساحة العمل وصناديق المستخدم المشتقة | 11 |

## 3. `PlatformSettings`

**المسؤولية:** إدارة إعدادات المنصة العامة التي لا تنتمي إلى مجال آخر، ونشرها بإصدارات قابلة للتتبع.

**يملك:**

- مفاتيح الإعدادات وقيمها typed.
- إصدارات الإعدادات وحالة المسودة والنشر.
- اللغة والـlocale والمنطقة الزمنية الافتراضية، وسياسات الجلسة وكلمة المرور فوق الحد الأمني الثابت، وحدود تشغيل عامة معلنة.
- سجل تفعيل الإصدار؛ أما التدقيق الأمني النهائي فيملكه `Audit`.

**عقود متزامنة:**

- `GetEffectivePlatformSetting`
- `GetPlatformSettingsVersion`
- `PublishPlatformSettingsVersion`

**أحداث:**

- `PlatformSettingsVersionPublished`

**يعتمد على:** لا شيء.

**لا يملك:** إعدادات نموذج عمل، أو مسار، أو مؤشر، أو مشروع. تبقى إعدادات المجال في موديول المجال.

## 4. `Organization`

**المسؤولية:** تمثيل التجمع والمنشآت والوحدات والمناصب والتسلسل الإداري والتكليفات والعلاقات الإشرافية وقدراتها.

**يملك:**

- المؤسسات والمنشآت والوحدات وأنواعها.
- الأشخاص وPII الأساسية ونسخة `person_version` المتزايدة.
- المناصب وخطوط الإشراف والتعيينات الأساسية والمؤقتة.
- العلاقات الإشرافية والوظيفية والتنسيقية ومددها وقدراتها.
- عضويات الفرق التنظيمية التي ليست حسابات دخول ولا أدوار صلاحية.

**عقود متزامنة:**

- `ResolveOrganizationScope`
- `ResolveDirectManager`
- `ResolvePositionHolder`
- `GetOrganizationUnitSummary`
- `GetActiveSupervisoryRelationships`
- `ValidateOrganizationReference`
- `ValidatePersonReference`

**أحداث:**

- `OrganizationUnitCreated`
- `OrganizationUnitMoved`
- `PositionAssigned`
- `PersonAccessStatusChanged`
- `IdentityProvisioningRequested`
- `TemporaryAssignmentExpired`
- `SupervisoryRelationshipActivated`
- `SupervisoryRelationshipExpired`

**يعتمد على:** لا شيء.

**لا يملك:** الحساب أو كلمة المرور أو الدور أو سجل العمل أو المشروع.

## 5. `Identity`

**المسؤولية:** الحسابات المحلية والاعتماد والجلسات ودورة حياة المستخدم والملف التشغيلي.

**يملك:**

- المستخدمين وبيانات الاعتماد وتاريخ تغييرها.
- الجلسات والاستعادة المحكومة ومحاولات الدخول.
- الملف الشخصي التشغيلي والتفضيلات الهووية.
- `person_id` كمعرف خارجي وملخص عرض محدود، بلا PII مرجعية أو FK إلى Organization.

**عقود متزامنة:**

- `AuthenticateUser`
- `GetUserIdentity`
- `ResolveActiveIdentity`
- `DisableUserAccount`
- `RevokeUserSessions`
- `ChangePassword`

**أحداث:**

- `UserAccountCreated`
- `UserAccountDisabled`
- `UserPasswordChanged`
- `UserSessionsRevoked`
- `UserProfileUpdated`

**يعتمد على:** `Organization`, `PlatformSettings`.

**لا يملك:** جهة المستخدم أو منصبه أو دوره التجاري؛ يشير إليها بمعرفات ويتحقق منها عبر العقود.

## 6. `Authorization`

**المسؤولية:** تحويل القدرة والدور والنطاق والعلاقة والتصنيف والحالة والتفويض وسياسة الحقل إلى قرار مركزي قابل للتفسير.

**يملك:**

- الأدوار والقدرات وإسناداتها.
- التفويضات ومددها وقيودها.
- سياسات التصنيف وقوالب الوصول إلى الحقول.
- schema الخاص بـ`RecordFacts` وعقود `AccessDecision` و`ScopePredicate`.

**عقود متزامنة:**

- `DecideAccess(actor, capability, RecordFacts)`
- `ResolveFieldAccess`
- `BuildAuthorizedScopePredicate`
- `FilterReadableOrganizationScopes`
- `ExplainAccessDecision`

**أحداث:**

- `RoleAssigned`
- `RoleRevoked`
- `DelegationActivated`
- `DelegationExpired`
- `AuthorizationPolicyPublished`
- `AccessDecisionEvaluated` عند وجوب التدقيق غير المتزامن

**يعتمد على:** `Identity`, `Organization`, `PlatformSettings`.

**قاعدة منع الدورات:** لا يعتمد على أي موديول سجلات أو أعمال، ولا يقرأ جداوله. الموديول المالك يبني `RecordFacts` من بيانات موثوقة ثم يطلب القرار.

## 7. `Audit`

**المسؤولية:** سجل append-only للأعمال الحساسة والتغييرات والوصول والتنزيل والتصدير، مع قابلية البحث المحكومة.

**يملك:**

- أحداث التدقيق غير القابلة للتعديل.
- أحداث الوصول الحساس.
- روابط correlation وcausation وهوية المنفذ والأصيل عند التفويض.
- نقاط استهلاك أحداث التدقيق غير المتزامنة.

**عقود متزامنة:**

- `AppendCriticalAuditEvent`
- `RecordSensitiveAccess`
- `QueryAuthorizedAuditTrail`

**أحداث:**

- `CriticalAuditEventAppended`
- `SensitiveAccessRecorded`

**يستهلك:** أحداث التغيير المنشورة، بما فيها أحداث `Authorization`.

**يعتمد على:** `Authorization` لحماية استعلامات سجل التدقيق. لا يستدعيه `Authorization` متزامناً؛ أحداث تغييرات الصلاحية تصل عبر Outbox، وبذلك لا تنشأ دورة.

**لا يملك:** سجل النشاط المفهوم للمستخدم داخل المجال، ولا يسمح بتعديل أو حذف حدث تدقيق.

## 8. `Workflow`

**المسؤولية:** تعريف المسارات وتنفيذ الموافقات والتفرع والتوازي والنصاب والتصعيد بإصدارات ثابتة.

**يملك:**

- تعريفات المسار ومسوداتها وإصداراتها المنشورة.
- العقد والانتقالات والشروط الآمنة.
- مثيلات المسار والخطوات والقرارات والتصعيدات.

**عقود متزامنة:**

- `ValidateWorkflowVersion`
- `PublishWorkflowVersion`
- `StartWorkflow`
- `RecordWorkflowDecision`
- `ReturnWorkflowForRevision`
- `GetWorkflowInstanceState`

**أحداث:**

- `WorkflowVersionPublished`
- `WorkflowStarted`
- `WorkflowStepActivated`
- `WorkflowDecisionRecorded`
- `WorkflowCompleted`
- `WorkflowFailed`

**يعتمد على:** `Organization`, `Authorization`, `Audit`.

**لا يملك:** معنى إكمال سجل عمل أو مشروع أو خطر. يتلقى `subject_ref` وسياق حل المعتمد ولا يستدعي الموديول المصدر.

## 9. `RecordsGovernance`

**المسؤولية:** سياسات الاحتفاظ، والحجز القانوني أو الإداري، ومراجعة الإتلاف، وتوثيق القرار دون امتلاك محتوى السجل.

**يملك:**

- جداول الاحتفاظ وإصداراتها.
- موضوعات الحوكمة العامة المرتبطة بـ`record_ref`.
- أوامر الحجز ومددها وأسبابها.
- حالات مراجعة الإتلاف وإثباتاته.

**عقود متزامنة:**

- `RegisterGovernedRecord`
- `ResolveRetentionPolicy`
- `PlaceRecordHold`
- `ReleaseRecordHold`
- `DecideDispositionEligibility`
- `ConfirmDispositionOutcome`

**أحداث:**

- `RecordHoldPlaced`
- `RecordHoldReleased`
- `RecordDispositionDue`
- `RecordDispositionApproved`
- `RecordDispositionCompleted`

**يعتمد على:** `PlatformSettings`, `Authorization`, `Audit`.

**لا يملك:** payload أو الملف أو عملية الحذف داخل المصدر. الموديول المالك ينفذ الإتلاف بعد قرار صريح وفي معاملته ثم يؤكد النتيجة.

## 10. `WorkDefinitions`

**المسؤولية:** تعريف أنواع الأعمال الديناميكية والنماذج والحقول والعلاقات والقوائم وإصداراتها.

**يملك:**

- تعريف نوع العمل ومسودته وإصداره المنشور.
- تعريفات الحقول والتحقق والتخطيطات والعلاقات.
- تعريفات القوائم وTyped projection metadata.
- ربط نوع العمل بإصدار مسار صالح.

**عقود متزامنة:**

- `CreateWorkTypeDraft`
- `ValidateWorkTypeVersion`
- `PublishWorkTypeVersion`
- `GetPublishedWorkTypeSchema`
- `GetProjectionDefinition`

**أحداث:**

- `WorkTypeVersionPublished`
- `WorkTypeVersionRetired`

**يعتمد على:** `PlatformSettings`, `Workflow`, `Authorization`, `Audit`.

**لا يملك:** أي `WorkRecord` أو payload تشغيلي. حذف حقل منشور يعني إيقافه أو إخفاءه لا إتلاف القيم السابقة.

## 11. `Documents`

**المسؤولية:** الملفات والبيانات الوصفية والتصنيف والإصدارات والروابط والتنزيل المحكوم.

**يملك:**

- Metadata المستند والنسخ وchecksum وحالة الفحص.
- روابط التخزين المتوافق مع S3.
- روابط المستند إلى `record_ref`.
- أحداث التنزيل والوصول الخاصة بالمستند.

**عقود متزامنة:**

- `CreateDocument`
- `AddDocumentVersion`
- `LinkDocument`
- `AuthorizeDocumentDownload`
- `GetDocumentSummary`

**أحداث:**

- `DocumentCreated`
- `DocumentVersionAdded`
- `DocumentLinked`
- `DocumentDownloaded`
- `DocumentClassified`

**يعتمد على:** `RecordsGovernance`, `Authorization`, `Audit`.

**لا يملك:** الملف داخل جدول الأعمال، ولا يمنح رابط المصدر وصولاً تلقائياً. يطبق أشد قيود المستند والسجل المرتبط.

## 12. `Collaboration`

**المسؤولية:** النقاشات العامة المرتبطة بالسجلات، والتعليقات والمنشن والمشاركون والاشتراكات وسجل النشاط التعاوني.

**يملك:**

- threads والتعليقات والإصدارات المنطقية للتعديل المحكوم.
- المشاركين والمنشنات والاشتراكات.
- روابط التعليق بالمستندات.

**عقود متزامنة:**

- `CreateCollaborationThread`
- `AddComment`
- `MentionParticipant`
- `AddParticipant`
- `ListAuthorizedThread`

**أحداث:**

- `CollaborationThreadCreated`
- `CommentAdded`
- `ParticipantAdded`
- `ParticipantMentioned`

**يعتمد على:** `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**لا يملك:** حالة المهمة أو السجل المصدر. المنشن يضيف مشاركاً حسب السياسة ولا يغير المسؤول أو حالة المصدر.

## 13. `Tasks`

**المسؤولية:** المهمة المستقلة أو المرتبطة، ومسؤولها الواحد، ومشاركوها من `Collaboration`، وموعدها وأولويتها ودورة حياتها.

**يملك:**

- المهمة و`source_ref` والمنشئ والمسؤول الواحد.
- الأولوية والموعد والحالة وقاعدة الإغلاق المثبتة عند الإنشاء.
- سجل انتقالات المهمة؛ أما النصوص والمرفقات فتملكها القدرات المختصة.

**عقود متزامنة:**

- `CreateTask`
- `AssignTask`
- `SubmitTaskCompletion`
- `AcceptTaskCompletion`
- `CompleteTask`
- `GetTaskSummary`

**أحداث:**

- `TaskCreated`
- `TaskAssigned`
- `TaskCompletionSubmitted`
- `TaskCompleted`
- `TaskCancelled`

**يعتمد على:** `Identity`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**لا يملك:** payload المصدر ولا يمنح الاطلاع على المهمة حق رؤية كل حقول المصدر. لا توجد مهام فرعية في المرحلة الأولى.

## 14. `WorkRecords`

**المسؤولية:** مثيلات أنواع الأعمال الديناميكية وحالتها التجارية وEnvelope وpayload والنطاق والجهات المشاركة.

**يملك:**

- `WorkRecord` Envelope: النوع والإصدار والمالك والمنشئ والحالة والتصنيف والرؤية و`lock_version`.
- payload المرتبط بإصدار `WorkDefinitions`.
- الأطراف والعلاقات التشغيلية الخاصة بالسجل.
- Typed projections التي يعدها المصدر للنشر إلى المستهلكات.

**عقود متزامنة:**

- `CreateWorkRecord`
- `SaveWorkRecordDraft`
- `SubmitWorkRecord`
- `TransitionWorkRecord`
- `ReturnWorkRecordForRevision`
- `CompleteWorkRecord`
- `GetAuthorizedWorkRecord`
- `ResolveWorkRecordFacts`

**أحداث:**

- `WorkRecordCreated`
- `WorkRecordSubmitted`
- `WorkRecordStateChanged`
- `WorkRecordReturnedForRevision`
- `WorkRecordCompleted`
- `WorkRecordClassified`

**يعتمد على:** `WorkDefinitions`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**قاعدة الطلبات:** الطلب الداخلي العام مجرد `WorkRecord` من نوع عمل منشور رمزه `request`؛ `request` نوع عمل لا تصنيف بيانات. لا يوجد موديول أو جدول أو Aggregate باسم `Requests`، ولا تستخدم أحداث `Request*`.

## 15. `Strategy`

**المسؤولية:** الخطط والمحاور والأهداف والمبادرات والمؤشرات وبطاقات الأداء والمستهدفات والقياسات والأثر المعتمد.

**يملك:**

- الخطط الاستراتيجية وإصداراتها والمحاور والأهداف والمبادرات.
- تعريفات المؤشرات وإصداراتها ووحداتها ومعادلاتها وملاكها.
- فترات القياس وخطوط الأساس والمستهدفات وتوزيعها.
- القياسات والأدلة وقرارات اعتمادها.
- الأثر الفعلي المعتمد المنسوب إلى المشاريع.

**عقود متزامنة:**

- `CreateStrategicPlan`
- `PublishStrategicPlanVersion`
- `GetIndicatorSummary`
- `DistributeIndicatorTarget`
- `SubmitIndicatorMeasurement`
- `ApproveIndicatorMeasurement`
- `RegisterProjectIndicatorLink`
- `SubmitProjectIndicatorImpact`
- `ApproveProjectIndicatorImpact`

**أحداث:**

- `StrategicPlanPublished`
- `IndicatorDefined`
- `IndicatorTargetDistributed`
- `IndicatorMeasurementSubmitted`
- `IndicatorMeasurementApproved`
- `ProjectIndicatorImpactApproved`

**يعتمد على:** `Organization`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**قاعدة المؤشرات:** `Strategy` المالك الوحيد للمؤشرات. لا يملكها `Reporting` أو `PortfolioProjects` أو `Risk`، ولا تنسخ تلك الموديولات تعريفاتها أو قياساتها.

## 16. `PortfolioProjects`

**المسؤولية:** المحافظ والبرامج والمشاريع والقوالب والمراحل والمعالم والميزانية الإدارية والصحة والأثر المخطط.

**يملك:**

- المحافظ والبرامج والمشاريع بتسلسل المحفظة ← البرنامج ← المشروع.
- قوالب ودورات حياة المشاريع والمراحل والمعالم وخط الأساس والأوزان.
- المشاركات والأدوار الخاصة بالمشروع.
- snapshots الميزانية الإدارية والصحة والتقدم.
- رابط المشروع إلى `indicator_id` والأثر المتوقع كبيانات تخطيط، لا تعريف المؤشر ولا قياسه.

**عقود متزامنة:**

- `CreatePortfolio`
- `CreateProgram`
- `CreateProject`
- `PublishProjectTemplate`
- `ApproveMilestone`
- `CalculateProjectProgress`
- `GetProjectSummary`
- `SubmitProjectImpactToStrategy`

**أحداث:**

- `ProjectCreated`
- `ProjectBaselineApproved`
- `MilestoneApproved`
- `ProjectProgressChanged`
- `ProjectHealthChanged`
- `ProjectImpactSubmitted`

**يعتمد على:** `Organization`, `Strategy`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**لا يملك:** المبادرات الاستراتيجية أو المؤشرات. الإنجاز يحسب من المعالم المعتمدة وأدلتها، لا من عدد المهام.

## 17. `Risk`

**المسؤولية:** سجل المخاطر المؤسسي والضوابط وخطط المعالجة، وربط مؤشرات Strategy كـKRI مع عتبات وتنبيهات وتصعيد، والربط بالاستراتيجية والمشاريع.

**يملك:**

- المخاطر وفئاتها ومصادرها ومالكها ومواعيد مراجعتها.
- تقييم الاحتمالية والأثر والمستوى الكامن والمتبقي.
- الضوابط وفعاليتها واستجابات الخطر.
- روابط خطط المعالجة إلى مهام، وروابط الهدف والمؤشر والمشروع.
- روابط KRI وعتباتها وقواعد التنبيه وحالة التصعيد؛ لا تعريف المؤشر ولا قياساته.

**عقود متزامنة:**

- `CreateRisk`
- `AssessRisk`
- `RegisterControl`
- `PlanRiskTreatment`
- `AcceptRisk`
- `LinkRiskIndicator`
- `ConfigureRiskIndicatorThreshold`
- `GetRiskSummary`

**أحداث:**

- `RiskCreated`
- `RiskAssessed`
- `RiskTreatmentPlanned`
- `CriticalRiskEscalated`
- `RiskIndicatorThresholdBreached`
- `RiskAccepted`

**يعتمد على:** `Organization`, `Strategy`, `PortfolioProjects`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit`.

**الحالة:** موديول `Risk` مخطط بالفعل ضمن R3 في `docs/plans/release-3-risk.md`؛ مواصفة W3.0 للمصفوفة والشهية ودورات المراجعة شرط تنفيذ للخطة القائمة، وليست خطة موديول مفقودة أو تناقضاً معها. يظل `Strategy` المالك الوحيد لتعريفات KRI وقياساتها، ويملك `Risk` الروابط والعتبات والتنبيهات فقط.

## 18. `Notifications`

**المسؤولية:** إنشاء وتجميع وعرض إشعارات داخل المنصة وحالة القراءة والتفضيلات.

**يملك:**

- الإشعارات والمستلم وحالة القراءة.
- تفضيلات الإشعار وقواعد التجميع.
- `source_ref` والرابط الآمن دون نسخ payload المصدر.
- Inbox منع التكرار للأحداث المستهلكة.

**عقود متزامنة:**

- `ListMyNotifications`
- `MarkNotificationRead`
- `UpdateNotificationPreferences`

**يستهلك:** أحداث `WorkRecords`, `Workflow`, `Tasks`, `Collaboration`, `Strategy`, `PortfolioProjects`, `Risk`, `RecordsGovernance` حسب سياسة معلنة.

**يعتمد على:** `Identity`, `Authorization` وعقود أحداث المنتجين.

**لا يقرر:** صلاحية رؤية المصدر. يعاد طلب القرار من endpoint المالك عند فتح الرابط. لا بريد ولا SMS ولا WhatsApp في المرحلة الأولى.

## 19. `Search`

**المسؤولية:** فهرسة النص والحقول المسموحة وإرجاع نتائج محكومة بالنطاق والتصنيف والحقول.

**يملك:**

- تعريفات الفهارس ونقاط التقدم ونسخ البحث المشتقة.
- Inbox منع التكرار وإصدارات وثيقة الفهرس.
- حقائق تفويض مشتقة لازمة للترشيح الأولي.

**عقود متزامنة:**

- `SearchAccessibleRecords`
- `RebuildSearchProjection`

**يستهلك:** أحداث `WorkRecords`, `Tasks`, `Collaboration`, `Documents`, `Strategy`, `PortfolioProjects`, `Risk`.

**يعتمد على:** `Authorization` وعقود أحداث المنتجين.

**لا يملك:** الحقيقة التشغيلية، ولا يعيد عنواناً أو مقتطفاً أو حقلاً محظوراً، ولا يكتب في سجل المصدر.

## 20. `Reporting`

**المسؤولية:** تعريف التقارير واللوحات وRead Models العابرة للموديولات والتصدير المحكوم.

**يملك:**

- تعريفات التقارير واللوحات وقوالبها.
- إسقاطات القراءة وحالة تحديثها.
- تعريفات التجميع والتصدير، لا تعريف المؤشر التجاري.

**عقود متزامنة:**

- `RunAuthorizedReport`
- `GetAuthorizedDashboard`
- `ExportAuthorizedReport`
- `RebuildReportingProjection`

**يستهلك:** أحداث أو Projection Feeds من `Organization`, `WorkRecords`, `Workflow`, `Tasks`, `Strategy`, `PortfolioProjects`, `Risk`.

**يعتمد على:** `Organization`, `Authorization` وعقود المنتجين.

**لا يملك:** المؤشرات أو القياسات، ولا يكتب في بيانات الأعمال، ولا يشغل تحليلات ثقيلة على JSON الخام أو على مسار المعاملات عند تأثيرها في الأداء.

## 21. `Workspace`

**المسؤولية:** مساحة عمل المستخدم وصناديق الموافقات والمهام والعناصر المعادة والمتأخرة وSaved Views كإسقاط شخصي موحد.

**يملك:**

- عناصر مساحة العمل المشتقة ومؤشرها إلى المصدر.
- Saved Views وتفضيلات العرض الخاصة بالمساحة.
- نقاط استهلاك الأحداث وإصدارات الإسقاط.

**عقود متزامنة:**

- `GetMyWorkspace`
- `GetOrganizationWorkspace`
- `SaveWorkspaceView`
- `RebuildWorkspaceProjection`

**يستهلك:** أحداث `WorkRecords`, `Workflow`, `Tasks`, `Collaboration`, `Strategy`, `PortfolioProjects`, `Risk`.

**يعتمد على:** `Authorization` وعقود أحداث المنتجين.

**لا يملك:** حالة أي عنصر مصدر، ولا ينفذ الانتقال نيابة عنه. ينقل المستخدم إلى endpoint المالك لإعادة التفويض والتنفيذ.

## 22. قواعد الإضافة والتغيير

- مجالات ما بعد R3 مرشحات استكشاف فقط، وليست موديولات مقررة أو ملتزماً بتنفيذها.
- لا ينشأ موديول جديد لمجرد وجود شاشة أو جدول.
- أي موديول جديد يحتاج معنى مجال مستقل، ومالك بيانات، وعقوداً، ورتبة في DAG، واختبارات حدود، وADR معتمد.
- لا ينقل كيان بين الموديولات بصمت؛ يوثق التغيير وخطة ترحيل العقود والبيانات.
- لا تستخدم مجلدات `Shared` إلا للـClock وIdentifiers وTransaction/Outbox primitives والأنواع التقنية المحايدة؛ لا توضع فيها DTOs أو سياسات مجال.
