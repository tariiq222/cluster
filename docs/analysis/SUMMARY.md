# ملخّص تنفيذي (بالعربية)

> **التاريخ:** 2026-07-26 (إعادة كتابة بعد تحديث drift)
> **الحالة:** تحليل evidence-first لـ Cluster (R3).
> **الفهرس الكامل:** [`00-overview.md`](00-overview.md)


## النتيجة في 60 ثانية

Cluster مبنيّ كـ **Laravel 13.8 Modular Monolith** بـ 12 موديول أعمال + Shared kernel + Web client (React 19 + Vite 8). النمط المعماري (Contracts → Domain → Features → Infrastructure → Http) مطبَّق بقوة، والـ `ModuleBoundariesTest` يفرض ranks وملكية الجداول.

## المرحلة الحالية (ما تم إنجازه)

منذ التحليل الأول، تمّت المراحل التالية:

1. **`RequireIdentitySessionPrincipal` أُعيد تنفيذه** (Stage 6.5) — enforcer حقيقي يفحص تماسك `session.user_id === principal.user_id` ويعيد 401 عند الفشل. الـ flag الميت `identity.session_only` لم يعد موجوداً.
2. **`docs/contracts/api/` مُستعاد** — `openapi.yaml` (10380 سطر) + `w1-1` + `w1-2` + `r1-screens` موجودة ومذكورة في `docs/contracts/api/README.md`.
3. **`docs/architecture/module-catalog.md` مُنشَأ** (المرجع الأعلى للـ ranks والحدود).
4. **`IdentitySecurityEventRegistry`** (Stage 6.7) — مركز موحَّد لتعيين الـ 12 suffix للـ security events.
5. **Self-hosted E2E CI workflow** (`.github/workflows/ci-e2e.yml`) — قالب جاهز ينتظر provisioning لـ runner مع label `cluster-e2e`.
6. **Legacy controller migration** (Stage 6.8) — لا تبقى business controllers في `app/Http/Controllers/`; المجلد يحوي `Controller.php` الأساسي فقط.
7. **`AppWorkspace.tsx` مُفكَّك** — الآن 1 سطر (re-export)؛ التقطيع تم في `AppWorkspaceShell.tsx` (271 سطر) + `WorkspaceContent.tsx` (271) + `WorkspaceHeader.tsx` (90) + `WorkspaceSidebar.tsx` (64) + `WorkspaceTabs.tsx` (54) = **750 سطر موزعة على 5 مكونات**.
8. **الحارس الإنتاجي على `RequireIdentitySessionPrincipal`** — قرار 6.5 موثَّق في `module-catalog.md` §6.5.

## المخاطر الحرجة المتبقية (P0)

1. **CSRF gap** — `UpdateDocumentController` لا يستخدم `IdentityCsrfMiddleware` رغم كونه mutation.
2. **9 جداول ناقصة** في `TABLE_OWNERS` (`work_definition_versions`, `search_index_entries`, `platform_settings_outbox`, `notification_inbox`, إلخ).
3. **`audit_events` ownership drift** — مُسجَّل لـ Authorization لكن Audit مُخطَّط (rank 3).
## ما تبقّى من Quick Wins (الأسبوع 1)

1. ✅ **`docs/contracts/api/openapi.yaml`** — **مُنجَز** (10380 سطر، مع snapshots `w1-1`, `w1-2`, `r1-screens`).
2. ✅ **`docs/architecture/module-catalog.md`** — **مُنجَز** (الـ 12 موديول + 7 مخطَّط + قرارات 6.5–6.8).
3. **إصلاح CSRF** في `UpdateDocumentController` (إضافة `IdentityCsrfMiddleware`) — **لم يُنجَز بعد**.
4. ✅ **`RequireIdentitySessionPrincipal`** — **مُنجَز** (تحويله لـ enforcer حقيقي بدل حذفه — Stage 6.5).
5. **تحديث `TABLE_OWNERS`** بإضافة الجداول الـ 9 الناقصة — **لم يُنجَز بعد**.

## الـ 12 موديول — في جملة واحدة لكل واحد

| الموديول | Rank | الحالة | في جملة |
|---------|------|--------|---------|
| **Identity** | 1 | ✅ production | مصادقة بـ session + TOTP + Idempotency + Stream Worker لـ 3 تيارات من Organization |
| **Authorization** | 2 | ✅ production | RBAC + ABAC + Classification + Delegation + ExplicitDeny + FieldAccess + SensitiveAccess |
| **Organization** | 0 | ✅ production | Cluster/Facility/Unit/Person/Position/Assignment + Import Excel + TemporaryAssignment مع concurrency + 4 Person-event outbox types |
| **WorkRecords** | 8 | ✅ production | Submit/List/Get + envelope + Idempotency-Key + outbox `com.cluster.workrecord.submitted.v1` |
| **WorkDefinitions** | 5 | ✅ minimal | تعريفات + fixture + Idempotency-Key (HTTP layer legacy) |
| **Documents** | 5 | ✅ production | Quarantine → ClamAV → S3 SigV4 presigned + Clean Spreadsheet Parser (HTTP layer legacy) |
| **Tasks** | 7 | ✅ production | CreateFromWorkflowStep/Complete/Transition + Engagement (HTTP layer ضمن module) |
| **Notifications** | 11 | ✅ production | Inbox + Workers مع reclaim/DLQ + cursor masked (HTTP layer ضمن module) |
| **Workflow** | 4 | ✅ production | Engine (Decision → Advance) + Approval Inbox + Assignment Rules (HTTP layer في الموضع المعياري `Features/WorkflowLifecycle/Http`) |
| **Reporting** | 11 | ✅ production | CQRS read-models + Refresh/Rebuild + Export (HTTP layer ضمن module) |
| **Search** | 11 | ✅ production | Indexer + Rebuild + DecideAccess per row (HTTP layer ضمن module) |
| **PlatformSettings** | 0 | ✅ production | Settings versions + Alerts outbox + Calendars + Maintenance + Backup + Technical Logs (DEFERRED) |
## خارطة طريق (7 مراحل، 10 أسابيع) — محدَّثة

1. **الأسبوع 1:** إصلاحات حرجة متبقية — CSRF gap + `TABLE_OWNERS`.
2. **الأسبوع 2-3:** تنظيف Architecture — الحفاظ على نقل controllers الحالي وإزالة integrations وseeders المتبقية من طبقة التطبيق.
3. **الأسبوع 4:** ملكية الجداول — إضافة 9 جداول ناقصة.
4. **الأسبوع 5-6:** تحسينات الإنتاج — Outbox typed + Dead path cleanup + retry/timeout.
5. **الأسبوع 7:** إنشاء موديول `Audit` (rank 3) ونقل `audit_events`.
6. **الأسبوع 8-9:** Web client — تفكيك `AppShell.tsx` (583 سطر) و `AppShell.css` (864 سطر) لاستخراج design system موحَّد.
7. **الأسبوع 10:** CI + Tooling — تفكيك `openapi_reconciler.py` (51KB) + cache + `make docs:validate`.

## مؤشرات النجاح — محدَّثة (2026-07-26)

| المؤشر | الحالي | المستهدف |
|--------|--------|----------|
| `misplacedBusinessFiles` count | 0 (لا تبقى business controllers خارج الموديولات) | 0 ✅ |
| `TABLE_OWNERS` entries | 39 (ناقصة) | 50+ (نظيف) |
| Web client unit tests | 212 ملف `.test.{ts,tsx}` | تغطية features متكافئة |
| `AppWorkspace.tsx` LOC | 1 (re-export لـ `AppWorkspaceShell`) | 1 ✅ |
| `AppWorkspaceShell.tsx` LOC | 271 | < 300 |
| `AppShell.tsx` LOC | 583 | < 300 |
| `AppShell.css` LOC | 864 | < 200 |
| OpenAPI source | `docs/contracts/api/openapi.yaml` ✅ | — |
| CSRF gaps | 1 (`UpdateDocumentController`) | 0 |
| Dead code paths | 2 (`ResolveAuthorizationSimulationFacts`, `ConsumeSubmittedNotification`) | 0 |
| Outbox events typed | 100% (`OutboxEventType` enum + matching schema) | 100% ✅ |
| Self-hosted E2E CI | قالب جاهز في `ci-e2e.yml` | provisioning runner |

- **Audit first-class module** (rank 3) — استخراج audit_events.
- **Outbox events as Contracts** — تحويل النصوص الخام إلى typed contracts.
- **CI E2E** (self-hosted runner) — لسد فجوة localhost-only.
- **Web client feature-first** — تفكيك AppWorkspace.
- **Documentation as Code** — OpenAPI + catalog.
- **Single Composition Root** — ModuleServiceProvider لكل موديول.

## الفهرس الكامل

راجع [`00-overview.md`](00-overview.md) للجدول الكامل بـ 17 وثيقة تحليلية.
