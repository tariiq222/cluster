---
doc_id: DOM-PPM-001
title: المحافظ والبرامج والمشاريع
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول PortfolioProjects
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
- docs/architecture/dependency-rules.md
- docs/adr/006-workflow-versioning.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
- docs/plans/release-2-strategy-portfolio.md
---
# المحافظ والبرامج والمشاريع

## الغرض

يمتلك `PortfolioProjects` المحافظ والبرامج والمشاريع وقوالبها ومراحلها ومعالمها وخطوط أساسها وصحتها وميزانيتها الإدارية. التسلسل الوحيد هو `Portfolio -> Program -> Project`. لا يملك المبادرات أو تعريف المؤشرات أو قياساتها؛ تلك حقائق حصرية لـ`Strategy`.

## النطاق

- مشروع عادي أو تحسين، بجهة مالكة واحدة وجهات مشاركة بأدوار محددة.
- قالب مشروع بإصدار ثابت، ومراحل ومعالم وبوابات `Workflow`.
- خط أساس للأوزان والمدة والميزانية، وتقدم محسوب من المعالم المعتمدة ذات الأدلة، لا من عدد المهام.
- لقطة ميزانية إدارية (`planned`, `actual`, `forecast`, `variance`) بلا فواتير أو أوامر شراء.
- روابط تخطيطية إلى مؤشرات ومبادرات Strategy، وإرسال الأثر الفعلي إلى Strategy لاعتماده.

خارج النطاق: محرك موافقات مستقل، مهام فرعية، تعريف أو قياس مؤشر، ونسخ بيانات الخطر أو السجل الديناميكي.

## الكيانات والجداول

| الجدول | الكيان والحقائق المملوكة | القيود والفهارس الرئيسية |
|---|---|---|
| `portfolios` | Portfolio، المالك، الحالة والتصنيف | `code` فريد؛ فهرس `(owner_organization_unit_id, status)` |
| `programs` | Program تابع لمحفظة | فريد `(portfolio_id, code)`؛ لا Program بلا Portfolio |
| `project_templates`, `project_template_versions` | القالب وإصداره المنشور | فريد `(template_id, version_number)`؛ المنشور immutable |
| `projects` | Project، البرنامج، الجهة المالكة، القالب المثبت، الحالة، `lock_version` | فريد `project_number`؛ فهارس المالك والحالة والبرنامج |
| `project_participants` | المشاركون ودور المشروع ومدته | فريد للمشارك والدور النشط؛ لا يمنح الدور وصولاً خارج المشروع |
| `project_phases`, `project_milestones` | مراحل ومعالم المشروع وأوزانها وبواباتها | فريد مفتاح المرحلة/المعلم داخل المشروع؛ الأوزان موجبة |
| `project_baselines` | خط أساس معتمد للمدة والأوزان والميزانية | خط أساس نشط واحد للمشروع؛ immutable بعد الاعتماد |
| `project_budget_snapshots`, `project_health_snapshots` | لقطات الميزانية والصحة المحسوبة والتجاوز الإداري | فهرس `(project_id, captured_at)`؛ التجاوز له سبب ومدة |
| `project_indicator_links` | `indicator_id`، خط الأساس والأثر المتوقع والنطاق | لا تعريف أو قيمة قياس مؤشر؛ فريد للرابط والنطاق والفترة |
| `project_activities` | نشاط مفهوم للمستخدم append-only | فهرس `(project_id, occurred_at)` |

المراجع إلى Organization وStrategy وTasks وDocuments وWorkflow معرفات وعقود فقط، لا foreign keys أو joins بين موديولات الأعمال.

## الأوامر

- `CreatePortfolio`, `CreateProgram`, `CreateProject`
- `CreateProjectTemplateDraft`, `PublishProjectTemplateVersion`
- `AddProjectParticipant`, `SetProjectBaseline`, `StartProject`, `PutProjectOnHold`, `CloseProject`, `CancelProject`
- `CreateProjectPhase`, `CreateProjectMilestone`, `SubmitMilestoneForApproval`, `ApplyMilestoneDecision`
- `RecordBudgetSnapshot`, `RecalculateProjectHealth`, `SetTemporaryHealthOverride`
- `RegisterProjectIndicatorLink`, `SubmitProjectImpactToStrategy`

كل أمر يبني `AuthorizationRecordFacts` للمشروع من aggregate المالك ويطلب `DecideAccess` قبل الكتابة؛ يسجل النشاط وOutbox داخل معاملة الموديول نفسها.

## الاستعلامات

- `GetAuthorizedProject`, `ListAuthorizedProjects`, `GetPortfolioSummary`, `GetProgramSummary`
- `GetProjectProgress`, `GetProjectHealth`, `GetProjectBudget`, `ListProjectMilestones`
- `GetProjectIndicatorLinks`, `GetAuthorizationRecordFacts`, `GetProjectActivity`

تعيد القوائم `ScopePredicate` من Authorization وتطبقها قبل العنوان أو الملخص. فتح مؤشر أو مستند أو مهمة يعيد التفويض إلى مالكه.

## الأحداث

- `PortfolioCreated`, `ProgramCreated`, `ProjectCreated`, `ProjectTemplateVersionPublished`
- `ProjectBaselineApproved`, `MilestoneSubmitted`, `MilestoneApproved`
- `ProjectProgressChanged`, `ProjectHealthChanged`, `ProjectBudgetSnapshotRecorded`
- `ProjectImpactSubmitted`, `ProjectClosed`, `ProjectCancelled`

الأحداث الماضية تحمل معرفات وملخصاً آمناً فقط، وتصل عبر Transactional Outbox. Notifications وSearch وReporting وWorkspace مستهلكات idempotent ولا تغير حقيقة المشروع.

## الحالات

```text
Project: Draft -> PendingApproval -> Active -> OnHold -> Closed
                                     |              -> Cancelled
Milestone: Draft -> PendingApproval -> Approved | Returned | Rejected
TemplateVersion: Draft -> Tested -> Approved -> Signed -> Published -> Retired
```

قرار `WorkflowCompleted` لا يغير المشروع خفياً؛ منسق صريح يصدر `ApplyMilestoneDecision` أو انتقال المشروع بعد التحقق idempotently.

## الثوابت

- كل Program يتبع Portfolio واحداً وكل Project يتبع Program واحداً؛ لا تدخل Initiative في هذا التسلسل.
- للمشروع جهة مالكة واحدة؛ المشاركة لا تنقل الملكية ولا تكشف حقولاً غير مصرح بها.
- إصدار القالب وخط الأساس مثبتان؛ التغيير بعد الاعتماد يتطلب baseline جديداً بسبب وتدقيق.
- مجموع أوزان المعالم المعتمدة لخط الأساس يساوي `100%`.
- الإنجاز يساوي مجموع أوزان المعالم المعتمدة ذات الأدلة المتاحة؛ المهمة مؤشر مساعد فقط.
- لا يغطي متوسط المحفظة مشروعاً حرجاً أحمر؛ قاعدة الحارس ترفع الحالة العامة وفق السياسة المنشورة.
- الأثر المتوقع محلي للتخطيط؛ الأثر الفعلي لا يصبح معتمداً إلا في Strategy، ولا يتجاوز مجموع الآثار المنسوبة التحسن المرصود بلا مبرر معتمد.
- لا حذف نهائي من الواجهة، والحجز أو الاحتفاظ من `RecordsGovernance` يمنع الأرشفة أو الإتلاف المخالف.

## الأمن والفشل

- يبني الموديول `AuthorizationRecordFacts` من المشروع، ولا يصدر Allow أو Deny أو قرار حقول؛ يصدر Authorization القرار وحده.
- مدير المشروع لا يعتمد بوابة لا يظهر فيها ضمن snapshot أو تفويض صالح؛ لا يوجد override اعتماد للسوبر أدمن.
- المستندات تخضع لأشد قيود المستند وكل روابطه، والمهام لا تمنح وصولاً تلقائياً إلى المشروع.
- قالب أو مؤشر أو workflow غير منشور، أو مرجع غير صالح، أو `lock_version` قديم: يرفض الأمر بلا كتابة جزئية.
- فشل بدء workflow أو كتابة Outbox يلف معاملة التغيير؛ فشل الإشعار أو التقرير بعد commit يعاد بمحاولة ولا يعكس الحقيقة.

## الاختبارات

- وحدة: منع Program بلا Portfolio وProject بلا Program، ومنع Initiative داخل التسلسل.
- وحدة: أوزان غير `100%` أو إنجاز بلا معلم معتمد ودليل مرفوضان.
- تكامل: snapshot المعلم المعتمد فقط يغير التقدم، وقرار workflow المكرر لا يكرر الأثر.
- أمن: مشارك مشروع أو مستخدم `view_aggregate` لا يرى الحقول أو الأدلة المحجوبة.
- حدود: لا joins أو كتابة في Strategy أو Tasks أو Documents؛ أثر المشروع يمر بالعقد.
- فشل: غياب معتمد لا يبدله أدمن، وفشل Outbox أو التعارض المتزامن لا ينتج نجاحاً جزئياً.

## الاعتماديات

يعتمد على Organization وStrategy وWorkflow وTasks وCollaboration وDocuments وRecordsGovernance وAuthorization وAudit. يستهلك Business Calendar من `PlatformSettings` لحساب مواعيد ومعالم العمل. يقدم `AuthorizationRecordFacts` لمسار الوصول وملخصات وأحداثاً للمستهلكات الأخرى، ولا يعتمد على Notifications أو Search أو Reporting أو Workspace.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول PortfolioProjects | إنشاء المواصفة المعتمدة |
