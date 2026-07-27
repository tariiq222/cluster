# 11 · موديول Workflow (تدفقات العمل)

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **تحديث 2026-07-27 — تدقيق متوسط العمق + إصلاحات.** عكس حالة WF1/WF2
> المغلقة، تصحيح دلالة `supervisor_of_initiator`، توسيع قائمة الاختبارات،
> وإضافة نتائج التدقيق الجديدة (WF8–WF11). التفاصيل في §7 و§8.

> **المسار:** `apps/api/Modules/Workflow/`
> **Rank:** 4
> **عدد الملفات:** 36 PHP (منها 8 ملفات اختبار)

## 1 · نبذة عامة
موديول `Workflow` يدير **محرك تدفقات العمل (Workflow Engine)**:
- إصدارات قابلة للنشر (`PublishWorkflowVersion`).
- بدء workflow (`StartWorkflowHandler`).
- دورة الحياة عبر HTTP (`WorkflowLifecycleMutator` + `WorkflowController`).
- تسجيل قرارات (`RecordDecisionHandler`).
- تقديم الخطوات (`AdvanceAfterDecision`، `WorkflowStepAdvancer`).
- استعلامات: `GetVisibleWorkflowInstance`، `ListApprovalInbox`.
- قواعد تخصيص الخطوات (`AssignmentRules`).
- قواعد القرار (`DecisionPolicyValidator`).

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/` | AdvanceWorkflowStep، ResolveStepAssignee، ResolveWorkflowSourceAuthorizationFacts، RuleContext، RuleSpec، WorkflowSourceReference، WorkflowStepExists |
| Domain | `Domain/` | AssignmentRules، DecisionPolicyValidator، WorkflowVersion |
| Features (Lifecycle) | `Features/WorkflowLifecycle/{Handler,Http}/` | `WorkflowLifecycleMutator` (كتابات معاملاتية + outbox + idempotency)، `WorkflowController` (حدود HTTP) |
| Features (Engine) | `Features/Engine/Handler/AdvanceAfterDecision.php`، `RecordDecisionHandler.php` | محرك القرارات |
| Features (Publish) | `Features/PublishWorkflowVersion/Handler/PublishWorkflowVersionHandler.php` | نشر الإصدارات |
| Features (Start) | `Features/StartWorkflow/Handler/StartWorkflowHandler.php` | بدء workflow |
| Features (Queries) | `Features/GetVisibleWorkflowInstance/Query/`، `Features/ListApprovalInbox/Query/` | استعلامات |
| Infrastructure | `Infrastructure/Persistence/WorkflowStepAdvancer.php`، `DatabaseWorkflowStepExists.php`، `StaleWorkflowStepVersion.php` | DB advancer + فحوص وجود/إصدار |
| Migrations | `Infrastructure/Persistence/Migrations/` (6 ملفات) | CreateWorkflowTables، W14، W15 (ميت موثّق، غير مسجّل)، W16، W17، W22 |
| Tests | `Tests/` (8 ملفات) | انظر §7 |
| Providers | `Providers/WorkflowServiceProvider.php` | ربط العقود الثلاثة |

## 3 · العقود
- `AdvanceWorkflowStep` — عقد تقديم الخطوة (يستهلكه Tasks).
- `ResolveStepAssignee` — حل المُكلَّف.
- `ResolveWorkflowSourceAuthorizationFacts` — facts للـ ABAC.
- `WorkflowStepExists` — فحص وجود خطوة.
- `RuleContext` + `RuleSpec` — DSL للقواعد.

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `WorkflowVersion` — VO immutable (id، version_number، state، graph، graph_hash).
- `AssignmentRules` — ثلاث قواعد تخصيص فقط: `role` (أول مستخدم في الدور)،
  `supervisor_of_step` (مُكلَّف خطوة سابقة عبر `step_index`)،
  `supervisor_of_initiator`. **دلالة الأخيرة (مصحّحة 2026-07-27):** منصب
  المُنشئ في وحدته الأساسية → `manager_position_id` → حامل المنصب المدير
  (تكليف غير منتهٍ، الأحدث `is_primary`). قبل التصحيح كانت القاعدة ترجع أي
  مرؤوس في الوحدة لا المدير. التخصيص يفشل مغلقًا (`null`) عند غياب الحلقة.
- `DecisionPolicyValidator` — سياسة الاعتماد (منع الاعتماد الذاتي مع مخرج
  bootstrap موثّق لعضو واحد) + تحقق خطية البيان (بداية واحدة، نهاية، بلا
  دورات، انتقال واحد لكل عقدة).

### 4.2 Handlers
- `WorkflowLifecycleMutator` — كل الكتابات المعاملاتية: إنشاء تعريف/نسخة،
  نشر، تسجيل قرار خطوة، reassign/escalate، إلغاء instance. state + outbox +
  idempotency يلتزمون أو يتراجعون معًا (مغطى بمصفوفة `WorkflowAtomicityTest`).
- `StartWorkflowHandler` — يبدأ workflow (الخطوة الأولى عبر المحرك للرسوم
  متعددة الخطوات، مباشرةً لذات الخطوة الواحدة).
- `RecordDecisionHandler` — يسجّل قرار (مسار المحرك).
- `AdvanceAfterDecision` — يقدّم الخطوة بعد القرار (مسار المحرك).
- `PublishWorkflowVersionHandler` — ينشر إصدار.

### 4.3 Queries
- `GetVisibleWorkflowInstance` — يرجّع workflow مرئي للـ principal (المرئية =
  من بدأ الـinstance أو مُكلَّف بخطوة؛ غير ذلك 404 بلا تسريب enumeration).
- `ListApprovalInbox` — قائمة بانتظار الموافقة (cursor مزدوج created_at+id).

### 4.4 Infrastructure
- `WorkflowStepAdvancer` — ينفّذ `taskCompleted` (يستهلكه Tasks) مع outbox
  `workflow.step.completed.v1` ومعرّف حدث حتمي للـ dedupe.

## 5 · مصادر البيانات
كل الجداول الستة مُسجَّلة في `TABLE_OWNERS` تحت `Workflow`
(`ModuleBoundariesTest.php:162-167`):
`workflow_definitions`، `workflow_versions`، `workflow_instances`،
`workflow_step_instances`، `workflow_decisions`، `workflow_idempotency_keys`.

## 6 · نقاط الـ API
`WorkflowController` في موضعه المعياري `Features/WorkflowLifecycle/Http/`
يخدم: `GET/POST workflow/definitions`، `GET/POST …/versions`،
`POST workflow/versions/{id}/publish`، `GET/POST workflow/instances`،
`GET workflow/instances/{id}`، `GET workflow/steps`، `GET workflow/steps/{id}`،
`POST …/decisions`، `POST …/{reassign|escalate}`،
`POST workflow/instances/{id}/cancel`.
الفحوص داخل الكنترولر: correlation UUIDv7 → principal → capability
(`denyUnlessAllowed`) → تحقق المدخلات → Idempotency-Key → If-Match.

## 7 · الوضع الحالي
- ✅ **Engine logic** واضح (decision → advance) ومختبَر (`WorkflowEngineTest`).
- ✅ **ABAC integration** عبر `ResolveWorkflowSourceAuthorizationFacts`.
- ✅ **Approval inbox** query + visibility بلا تسريب.
- ✅ **Atomicity** state+outbox+idempotency (7 اختبارات rollback).
- ✅ **AssignmentRules** دلالة `supervisor_of_initiator` مصحّحة ومغطاة
  باختبارات مزروعة (4 حالات: الحل، بلا مدير، منصب شاغر، تكليف منتهٍ).
- ✅ HTTP layer في الموضع المعياري (أُغلق WF1).
- ✅ قرارات HTTP مثبّتة: approve يغلق الخطوة والـinstance؛ reject/return
  يثبّتان الخطوة ويبقيان الـinstance `running` (موثّق كعقد حالي في
  `WorkflowDecisionHttpTest` — انظر WF10).
- ✅ حد التخويل 401 لـ versions/publish مثبّت
  (`WorkflowVersionLifecycleAuthorizationHttpTest`).
- ⚠️ قرار HTTP `decideStep` لا يقدّم المسار متعدد الخطوات (WF8).
- ⚠️ `versions()` و`publish()` بلا فحص capability (WF9).

**الاختبارات (8 ملفات):** `WorkflowCoreTest`، `WorkflowAtomicityTest`،
`WorkflowEngineTest`، `AssignmentRulesTest`، `WorkflowStepAssigneeTest`،
`ListApprovalInboxTest`، `GetVisibleWorkflowInstanceTest`،
`WorkflowVersionMySqlConcurrencyTest` (يتخطى محليًا بدون `pdo_mysql`؛
يعمل عبر `make verify-mysql-integration`). وخارج الميديول:
`tests/Feature/{WorkflowDecisionHttpTest, WorkflowVersionLifecycleAuthorizationHttpTest, WorkflowStepReassignHttpTest, ApprovalInboxHttpTest, Day2HttpVerticalTest}`.

## 8 · المشاكل / المخاطر

| # | الو_desc | المرجع | الحالة |
|---|------|--------|--------|
| WF1 | `WorkflowController` legacy | كان `ModulePlacementInventory.php` | ✅ مغلق — الكنترولر في الموضع المعياري ولا إدخال له في الجرد |
| WF2 | جداول workflow_* غير مُسجَّلة في `TABLE_OWNERS` | `ModuleBoundariesTest.php:162-167` | ✅ مغلق — الستة مسجَّلة (والجدولان `workflow_steps`/`workflow_step_assignees` المذكوران سابقًا غير موجودين أصلًا) |
| WF3 | `WorkflowStepAdvancer` يستخدم `DB::` مباشرة | `Infrastructure/Persistence/WorkflowStepAdvancer.php` | ⚠️ جزئي — `DB::` المباشر هو النمط المعتمد في الميديول، لكن outbox `workflow.step.completed.v1` يُرسَل داخل نفس المعاملة بمعرّف حتمي |
| WF4 | `ResolveStepAssignee` لا يحمل retry policy | (gap) | مفتوح |
| WF5 | `DecisionPolicyValidator` لا يميّز بين `deny` و `escalate` | `Domain/DecisionPolicyValidator.php` | مفتوح |
| WF6 | لا تأكيد أن `StartWorkflowHandler` يستخدم Idempotency-Key | `Features/StartWorkflow/Handler/StartWorkflowHandler.php` | مفتوح — بدء الـinstance معاملاتي ويكرّد الـinstance الموجود لنفس (source, version) لكن بلا مفتاح idempotency خاص |
| WF7 | `AdvanceAfterDecision` لا يُسجّل في `sensitive_access_events` | (gap) | مفتوح |
| WF8 | `decideStep` (HTTP) لا يستدعي `AdvanceAfterDecision`: في رسم متعدد الخطوات يُغلق الـinstance `completed` بعد أول اعتماد (open==0). المحرك مربوط بـ`StartWorkflowHandler` و`WorkflowEngineTest` فقط | `WorkflowController::decideStep` + `WorkflowLifecycleMutator::recordStepDecision` | مفتوح — يحتاج قرار ربط المحرك بالـHTTP |
| WF9 | `versions()` و`publish()` بلا فحص capability (فقط جلسة موثّقة)؛ و`createDefinition` لا يضبط `submitted_by_user_id` فيتخطى حارس self-approval | `WorkflowController::versions`، `::publish` | مفتوح — مثبّت باختبارات 401؛ إضافة capability تحتاج قرار + تحديث fixtures (العقد المثبّت في `Day2HttpVerticalTest:30,38`) |
| WF10 | الخطوات `rejected`/`returned` تُحسب "مفتوحة" فيقف الـinstance `running` بلا مسار إغلاق | `WorkflowLifecycleMutator::recordStepDecision` (فحص open) | مثبّت كعقد حالي باختبارين — يحتاج سياسة رفض/إرجاع |
| WF11 | `catch (\Throwable)` → 409 مع `$e->getMessage()` في 6 مواضع: تسريب رسائل داخلية وتحويل 500 إلى 409 | `WorkflowLifecycleMutator` كل الدوال + `WorkflowController::instances` | مفتوح |

**إصلاحات جلسة 2026-07-27 (خارج جدول WF):** حذف السمة المكررة
`Modules/Workflow/Features/HttpSupport/HttpSupport` وتحويل
`WorkDefinitionController` إلى `Shared\Http\HttpSupport` (إزالة حافة
عكسية WorkDefinitions→Workflow)؛ تحصين ماسح الاستيرادات في
`ModuleBoundariesTest::allImportsFrom` ضد التهرب بالشرطة المائلة البادئة
(`use \Modules\…`).

## 9 · التحسينات المقترحة

1. ~~نقل `WorkflowController` إلى `Features/<Operation>/Http/`~~ ✅ تم.
2. ~~تسجيل جداول workflow_* في `TABLE_OWNERS`~~ ✅ تم.
3. **ربط `AdvanceAfterDecision` بمسار `decideStep`** (يغلق WF8) — قرار معماري.
4. **إضافة capability check لـ versions/publish** (يغلق WF9) — قرار + fixtures.
5. **سياسة رفض/إرجاع للـinstance** (يغلق WF10) — قرار منتج.
6. **تأكيد `deny` vs `escalate`** distinction في `DecisionPolicyValidator` (WF5).
7. **تسجيل القرارات الحساسة** في `sensitive_access_events` (WF7).
8. **تضييق `catch (\Throwable)`** إلى استثناءات تعارض معروفة ورسائل ثابتة (WF11).
