# 09 · موديول Tasks (المهام)

> **المسار:** `apps/api/Modules/Tasks/`
> **Rank:** 7
> **عدد الملفات:** 12 PHP

## 1 · نبذة عامة
موديول `Tasks` يدير دورة حياة المهام (Tasks) المُنشأة من خطوات Workflow أو مباشرة من المستخدم. يدعم participants، comments، engagement (likes/follow)، optimistic concurrency عبر `lock_version` و `If-Match`.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Domain | `Domain/Task.php` | VO readonly |
| Features (Http) | `Features/Http/TaskController.php`، `Features/Http/TaskEngagementController.php`، `Features/Http/TaskHttpSupport.php` | HTTP layer (الـ controllers) |
| Features (Create) | `Features/CreateTaskFromWorkflowStep/Handler/CreateTaskFromWorkflowStepHandler.php` | إنشاء من workflow step |
| Features (Complete) | `Features/CompleteTask/Handler/CompleteTaskHandler.php` | إنهاء |
| Features (Transition) | `Features/TransitionTask/Handler/TransitionTaskHandler.php` | state machine |
| Infrastructure (Persistence) | `Infrastructure/Persistence/TaskHttpStore.php` | DB store مباشر (DB facade) |
| Migrations | `Infrastructure/Persistence/Migrations/CreateTasksTable.php`، `W13CreateTaskEngagementTables.php` | الجداول |
| Tests | `Tests/TaskWorkflowCoreTest.php`، `Tests/TasksHttpControllerTest.php` | الاختبارات |

## 3 · العقود المُستهلكة
- `Modules\Authorization\Contracts\DecideAccess`
- `Modules\Authorization\Contracts\RecordFacts`
- `Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal`
- `Modules\Workflow\Contracts\AdvanceWorkflowStep`
- `Shared\Contracts\TransactionalOutbox`

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `Task.php` — VO readonly (id، title، status، owner، participants، lock_version، due_at، ...).

### 4.2 Handlers
- `CreateTaskFromWorkflowStepHandler` — ينشئ task من workflow step (يستهلك `AdvanceWorkflowStep`).
- `CompleteTaskHandler` — ينهي المهمة.
- `TransitionTaskHandler` — يدير state machine.

### 4.3 Http
- `TaskController` — GET/POST/PATCH/DELETE /api/v1/tasks/{id}.
- `TaskEngagementController` — engagement (likes/follow).
- `TaskHttpSupport` — utilities.

### 4.4 Infrastructure
- `TaskHttpStore` — DB facade مباشر (ينتهك نمط "controllers must not own transactions/Outbox" إذا كان يستخدم Outbox).

## 5 · مصادر البيانات
- `tasks` — root.
- `task_participants` — المشاركين.
- `task_comments` — التعليقات.
- `task_idempotency_keys` — Idempotency-Key (مُنشأ لكن غير مفعّل بالكامل).

## 6 · نقاط الـ API
- `GET/POST /api/v1/tasks`
- `GET/PATCH/DELETE /api/v1/tasks/{id}`
- `POST /api/v1/tasks/{id}/complete` (mutation)
- `POST /api/v1/tasks/{id}/engagement` (likes/follow)

## 7 · الوضع الحالي
- ✅ **Domain + State machine** واضح.
- ✅ **Outbox events** بنصوص خام.
- ✅ **Optimistic concurrency** عبر lock_version + If-Match.
- ⚠️ HTTP layer ضمن `Features/Http/` (ليس legacy في `app/`).
- ⚠️ `task_idempotency_keys` table مُنشأة لكن غير مفعّلة.
- ⚠️ Outbox events بنصوص خام (لا Contracts/Events) — coupling.

## 8 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| T1 | `TaskController` في `Features/Http/` — يجب نقله إلى `Features/Http/*/Http/` حسب النمط (لكن `Features/Http/` ككل مقبول) | `Features/Http/TaskController.php` |
| T2 | `TaskHttpStore` يستخدم `DB::table('tasks')` مباشرة في handler | `Infrastructure/Persistence/TaskHttpStore.php` |
| T3 | Outbox events بنصوص خام (event_type = "task.created.v1" مثلاً) لا Contracts/Events | (gap) |
| T4 | `task_idempotency_keys` غير مفعّل | `W13CreateTaskEngagementTables.php` |
| T5 | `TaskHttpSupport` helper بدون tests | `Features/Http/TaskHttpSupport.php` |
| T6 | لا workflow integration tests | (gap) |
| T7 | `TaskEngagement` لا يُسجَّل في `sensitive_access_events` | (gap) |
| T8 | `CreateTaskFromWorkflowStepHandler` لا يحمل retry policy | `CreateTaskFromWorkflowStepHandler.php` |

## 9 · التحسينات المقترحة

1. **نقل Outbox events** إلى Contracts/Events منفصلة (`TaskCreated`, `TaskCompleted`, `TaskTransitioned`).
2. **تفعيل `task_idempotency_keys`** في كل mutations.
3. **استبدال `TaskHttpStore`** بـ Adapter pattern (DB queries) — تسهيل الـ mocking.
4. **نقل `TaskController` و `TaskEngagementController`** إلى `Features/<Operation>/Http/` (يتبع النمط المتّسق).
5. **إضافة tests لـ `TaskHttpSupport`**.
6. **تسجيل engagement في `sensitive_access_events`** (تحتاج مراجعة ما هو حساس).
7. **إضافة retry policy** على `CreateTaskFromWorkflowStepHandler`.
8. **تأكيد concurrency tests** على parallel transitions.
