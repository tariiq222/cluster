# 11 · موديول Workflow (تدفقات العمل)

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **المسار:** `apps/api/Modules/Workflow/`
> **Rank:** 4
> **عدد الملفات:** 27 PHP

## 1 · نبذة عامة
موديول `Workflow` يدير **محرك تدفقات العمل (Workflow Engine)**:
- إصدارات قابلة للنشر (`PublishWorkflowVersion`).
- بدء workflow (`StartWorkflowHandler`).
- تسجيل قرارات (`RecordDecisionHandler`).
- تقديم الخطوات (`AdvanceAfterDecision`، `WorkflowStepAdvancer`).
- استعلامات: `GetVisibleWorkflowInstance`، `ListApprovalInbox`.
- قواعد تخصيص الخطوات (`AssignmentRules`).
- قواعد القرار (`DecisionPolicyValidator`).

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/` | AdvanceWorkflowStep، ResolveStepAssignee، ResolveWorkflowSourceAuthorizationFacts، RuleContext، RuleSpec، WorkflowSourceReference |
| Domain | `Domain/` | AssignmentRules، DecisionPolicyValidator، WorkflowVersion |
| Features (Engine) | `Features/Engine/Handler/AdvanceAfterDecision.php`، `RecordDecisionHandler.php` | محرك القرارات |
| Features (Publish) | `Features/PublishWorkflowVersion/Handler/PublishWorkflowVersionHandler.php` | نشر الإصدارات |
| Features (Start) | `Features/StartWorkflow/Handler/StartWorkflowHandler.php` | بدء workflow |
| Features (Queries) | `Features/GetVisibleWorkflowInstance/Query/GetVisibleWorkflowInstance.php`، `Features/ListApprovalInbox/Query/ListApprovalInbox.php` | استعلامات |
| Infrastructure | `Infrastructure/Persistence/WorkflowStepAdvancer.php` | DB advancer |
| Migrations | `Infrastructure/Persistence/Migrations/` (6 ملفات) | CreateWorkflowTables، W14، W15، W16، W17 |
| Tests | `Tests/` | 6 ملفات |

## 3 · العقود
- `AdvanceWorkflowStep` — عقد تقديم الخطوة.
- `ResolveStepAssignee` — حل المُكلَّف.
- `ResolveWorkflowSourceAuthorizationFacts` — facts للـ ABAC.
- `RuleContext` + `RuleSpec` — DSL للقواعد.

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `WorkflowVersion` — VO immutable (version, status, steps).
- `AssignmentRules` — قاعدة: من يُكلَّف؟ (round-robin, load-balanced, specific user).
- `DecisionPolicyValidator` — التحقق من قرار.

### 4.2 Handlers
- `StartWorkflowHandler` — يبدأ workflow جديد.
- `RecordDecisionHandler` — يسجّل قرار.
- `AdvanceAfterDecision` — يقدّم الخطوة بعد القرار.
- `PublishWorkflowVersionHandler` — ينشر إصدار.

### 4.3 Queries
- `GetVisibleWorkflowInstance` — يرجّع workflow مرئي للـ principal.
- `ListApprovalInbox` — قائمة بانتظار الموافقة.

### 4.4 Infrastructure
- `WorkflowStepAdvancer` — ينفّذ التقديم (يستهلك `AdvanceWorkflowStep`).

## 5 · مصادر البيانات
- `workflow_instances` (TABLE_OWNERS)
- جداول مشتقة من التهجيرات: `workflow_versions`, `workflow_steps`, `workflow_decisions`, `workflow_step_assignees`، إلخ (لم تُسجَّل في `TABLE_OWNERS`).

## 6 · نقاط الـ API
- `WorkflowController` legacy يخدم lifecycle (لم يُنقَل).

## 7 · الوضع الحالي
- ✅ **Engine logic** واضح (decision → advance).
- ✅ **ABAC integration** عبر `ResolveWorkflowSourceAuthorizationFacts`.
- ✅ **Approval inbox** query.
- ⚠️ HTTP layer legacy.
- ⚠️ `AssignmentRules` DSL غير موثّق.
- ⚠️ جداول workflow_* مشتقة غير مُسجَّلة في `TABLE_OWNERS`.

## 8 · المشاكل / المخاطر

| # | الو_desc | المرجع |
|---|------|--------|
| WF1 | `WorkflowController` legacy | `ModulePlacementInventory.php:13` |
| WF2 | `workflow_steps`, `workflow_decisions`, `workflow_step_assignees` غير مُسجَّلة في `TABLE_OWNERS` | `CreateWorkflowTables.php`، `W14AddWorkflowStepAssignee.php`، `W15CreateWorkflowDecisionsTable.php`، `W16CreateWorkflowDecisionsTable.php`، `W17AddApprovalColumnsToWorkflowVersions.php` |
| WF3 | `WorkflowStepAdvancer` يستخدم `DB::` مباشرة بدون outbox events للقرارات | `Infrastructure/Persistence/WorkflowStepAdvancer.php` |
| WF4 | `ResolveStepAssignee` لا يحمل retry policy | (gap) |
| WF5 | `DecisionPolicyValidator` لا يميّز بين `deny` و `escalate` | `Domain/DecisionPolicyValidator.php` |
| WF6 | لا تأكيد أن `StartWorkflowHandler` يستخدم Idempotency-Key | `Features/StartWorkflow/Handler/StartWorkflowHandler.php` |
| WF7 | `AdvanceAfterDecision` لا يُسجّل في `sensitive_access_events` | (gap) |

## 9 · التحسينات المقترحة

1. **نقل `WorkflowController`** إلى `Modules/Workflow/Features/*/Http/`.
2. **تسجيل جداول workflow_* المشتقة** في `TABLE_OWNERS`.
3. **Outbox event** `WorkflowStepAdvanced`، `WorkflowDecisionRecorded`.
4. **توثيق `AssignmentRules` DSL** في `docs/architecture/`.
5. **تأكيد `deny` vs `escalate`** distinction في `DecisionPolicyValidator`.
6. **فرض Idempotency-Key** في `StartWorkflowHandler`.
7. **تسجيل القرارات الحساسة** في `sensitive_access_events`.
