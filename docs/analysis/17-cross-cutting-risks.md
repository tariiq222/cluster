# 17 · المخاطر الشاملة وخارطة الأولويات

## 1 · ملخّص تنفيذي (محدَّث 2026-07-25)

| المحور | الحالة | الأولوية |
|--------|--------|----------|
| **CSRF/Idempotency-Key gaps** | ⚠️ handler يتجاوز middleware | عالية (P1) |
| **Cross-module writes** (Authorization → Org tables) | ⚠️ غير محمي | متوسطة (P2) |
| **Audit موديول** (مذكور، غير منفَّذ) | ❌ مخطَّط | متوسطة (P2) |
| **DEFERRED capabilities** (Technical logs، fixtures) | ⚠️ sentinel | متوسطة (P2) |
| **Web client monolithic components** (`AppShell.tsx` 583 سطر) | ⚠️ صعب الصيانة | متوسطة (P2) |
| **Test coverage gap** (web features) | ⚠️ 212 ملف `.test.{ts,tsx}` | منخفضة (P3) |
| **CI gaps** (E2E مفقود) | ⚠️ محلي فقط | منخفضة (P3) |
| **مخاطر محلولة (مُنجَزة)** | ✅ | — |
| — `docs/contracts/api/` مفقود | ✅ مُنجَز | — |
| — `docs/architecture/module-catalog.md` مفقود | ✅ مُنجَز | — |
| — `RequireIdentitySessionPrincipal` dead | ✅ مُنجَز (Stage 6.5) | — |
| — `AppWorkspace.tsx` 807 سطر | ✅ مُنجَز (التفكيك في 5 مكونات) | — |
| — Outbox events typed | ✅ مُنجَز (100% مُغطَّى بـ `OutboxEventType` enum) | — |
| — IdentitySecurityEventRegistry dead | ✅ مُنجَز (Stage 6.7) | — |
| — Self-hosted E2E CI workflow | ✅ مُنجَز (`.github/workflows/ci-e2e.yml`) | — |
| — Legacy controller migration (Stage 6.8) | ✅ 14 منقول من 89 | — |

## 2 · أعلى 10 مخاطر (P0/P1) — محدَّث 2026-07-25

### 2.1 ✅ ~~P0 — `docs/contracts/api/` مفقود~~ — **مُنجَز**
- **الوضع:** `docs/contracts/api/openapi.yaml` (10380 سطر) + `w1-1.openapi.yaml` + `w1-2.openapi.yaml` + `r1-screens.openapi.yaml` + `README.md` موجودون. الـ lineage من `.orval/cluster-master.openapi.yaml` إلى `docs/contracts/api/` موثَّق.

### 2.2 ✅ ~~P0 — `docs/architecture/module-catalog.md` مفقود~~ — **مُنجَز**
- **الوضع:** `docs/architecture/module-catalog.md` يحتوي الرتب الكاملة، الـ 12 موديول المنفَّذ + 7 مخطَّط + 6 قرارات معمارية (6.1–6.8). راجع `module-catalog.md`.

### 2.3 P0 — CSRF gap في `UpdateDocumentController`
- **التأثير:** mutation بدون CSRF check.
- **الإصلاح:** إضافة `IdentityCsrfMiddleware` للـ route.

### 2.4 P1 — 75 legacy controller في `app/Http/Controllers/`
- **التأثير:** Architecture test يفشل نظرياً لكن `misplacedBusinessFiles` يُعطيه استثناء. (انخفض من 89 بـ 14 منقول في Stage 6.8).
- **الإصلاح:** نقلهم على دفعات (Identity → Authorization → Organization → Documents → ...).

### 2.5 P1 — ملكية الجداول ناقصة
- **التأثير:** `audit_events` مذكور لـ Authorization، `work_definition_versions` و `search_index_entries` و `notification_inbox` غير مُسجَّلة.
- **الإصلاح:** تحديث `TABLE_OWNERS` و `audit_events` ملكها لـ `Audit` (أو حذفها).

### 2.6 ✅ ~~P1 — Outbox events بنصوص خام~~ — **مُنجَز**
- **الوضع:** `Shared/Infrastructure/Outbox/OutboxEventType` enum (123 سطر) يحتوي **كل** الـ 56 event type literals. كل حالة تستدعي `schemaPath()` التي تحدد ملف JSON Schema. الـ architecture test `test_every_event_type_in_outbox_has_a_matching_json_schema` يفرض ذلك.

### 2.7 P1 — `ResolveAuthorizationSimulationFacts` dead path
- **التأثير:** simulation لا يعمل.
- **الإصلاح:** إما تسجيل providers (WorkRecordAuthorizationFacts) أو حذف الـ resolver.

### 2.8 P1 — Cross-module writes
- **التأثير:** Authorization يحدّث `temporary_assignment_capabilities` و `relationship_capabilities` (Organization tables).
- **الإصلاح:** نقل الجداول إلى Authorization أو فرض Contract-based write.

### 2.9 ✅ ~~P1 — `RequireIdentitySessionPrincipal` middleware dead~~ — **مُنجَز** (Stage 6.5)
- **الوضع:** أُعيد تنفيذه كـ enforcer حقيقي يفحص تماسك `session.user_id === principal.user_id` ويعيد 401 عند الفشل. راجع `module-catalog.md` §6.5.

## 3 · مخاطر معمارية متوسطة (P2) — محدَّث 2026-07-25

- **`EnforcePlatformMaintenance`** يستهلك DecideAccess في كل request (cost).
- **`WorkRecordLifecycleController` legacy** + **`ProjectWorkRecordReadModels` middleware** يربط الـ 3 موديولات بشكل ضمني.
- **No retry policy** على `ScanDocumentVersionController` (throttle:60,1 فقط).
- **`MarkNotificationReadController`** يطلب Idempotency-Key دون تخزين.
- **`platform_settings_outbox` غير مُسجَّل** في `TABLE_OWNERS`.
- **Audit موديول مخطَّط** لكن `audit_events` ملكها لـ Authorization (ownership drift).
- **`FixtureFacilityDecision`** قد يُحقن في production (يحتاج guard إضافي).
- **`documentsProduction()` يعتمد على argv parsing** (هشّ).
- **Web client `AppShell.tsx` 583 سطر و `AppShell.css` 864 سطر** — يحتاجان design system موحَّد.
- ✅ ~~`AppWorkspace.tsx` 807 سطر~~ — **مُنجَز** (التفكيك في 5 مكونات).
- **`swagger-ui-react` lazy load** قد يكسر UX.

## 4 · مخاطر منخفضة (P3) — محدَّث 2026-07-25

- **`AppShell.css` و `WorkspaceTabs.css`** غير موحَّدة.
- **`PlatformSettingsMockData`** في الـ shell (mock في production).
- **`w1-3/*` snapshots** في `src/api/w1-3/` قد تُربك.
- **Test coverage gap** في web (212 ملف `.test.{ts,tsx}` حالياً — التحقق من التغطية المتكافئة).
- **CI E2E** يحتاج self-hosted runner مع label `cluster-e2e` (workflow جاهز، runner لم يُprovisioned).
- **`openapi_reconciler.py` 51KB** — صعب الصيانة.
- **`run-*.sh` متعدّدة** بدون Make target موحَّد.
- **No `make docs:validate`** (الـ `validate-docs.sh` متاح لكن غير مدمج).

## 5 · خارطة طريق مقترحة (مرحلية) — محدَّث 2026-07-25

### المرحلة 1 — التأسيس (أسبوع 1) — مُنجَز جزئياً
1. ✅ ~~إنشاء `docs/contracts/api/`~~ — **مُنجَز**.
2. ✅ ~~إنشاء `docs/architecture/module-catalog.md`~~ — **مُنجَز**.
3. **إصلاح CSRF gap في `UpdateDocumentController`** — متبقٍّ.
4. ✅ ~~حذف أو تفعيل `RequireIdentitySessionPrincipal`~~ — **مُنجَز** (Stage 6.5: تحوّل إلى enforcer).
5. ✅ ~~تحديث `redocly.yaml` للإشارة إلى `docs/contracts/api/`~~ — **مُنجَز** (موثَّق في `docs/contracts/api/README.md`).

### المرحلة 2 — تنظيف Architecture (أسبوع 2-3)
1. ~~نقل Identity controllers (16)~~ — **مُنجَز جزئياً** (Stage 6.8: 12 Organization + 2 Reporting منقول، تبقى 16 Identity + 5 Authorization + 18 Documents + 4 Api + 35 Organization المتبقية).
2. نقل Organization controllers (23 متبقّية من 35 الأصلية).
3. نقل Documents controllers (18).
4. نقل Authorization controllers (5).
5. نقل WorkRecords + WorkDefinitions + Workflow controllers.
6. نقل Search + Reporting (2).
7. نقل Notifications (2 controllers، 1 middleware).
8. نقل app/Integrations (4 — انخفض من 12).
9. نقل app/Support seeders (3).
10. نقل `SessionPrincipalResolver` + `ConsumeSubmittedNotification`.
### المرحلة 3 — ملكية الجداول (أسبوع 4)
1. إضافة `work_definition_versions`، `work_definition_idempotency_keys`، `development_work_type_fixtures`.
2. إضافة `search_index_entries`، `search_checkpoints`، `search_inbox`.
3. إضافة `platform_settings_outbox` و الإصدارات.
4. إضافة `notification_inbox`، `notification_recipients`، `notification_dead_letters`.
5. حل `audit_events` ملك (نقل إلى Audit أو حذف).
6. توضيح `identities` vs `users`.

### المرحلة 4 — تحسينات الإنتاج (أسبوع 5-6)
1. **Outbox events typed** لكل الموديولات (Tasks، Workflow، Identity).
2. **Dead path cleanup** (`RegisteredAuthorizationSimulationFactsResolver`).
3. **Production guard لـ `FixtureFacilityDecision`**.
4. **استبدال `documentsProduction()`** argv-based بـ environment check نظيف.
5. **timeout/retry** في ClamAV transport.
6. **TTL** على export artifacts.
7. **optimistic locking** في `RefreshReportingProjectionHandler`.
8. **batch DecideAccess** في Search.
9. **soft rebuild** في `RebuildSearchProjectionHandler`.

### المرحلة 5 — Audit (أسبوع 7)
1. إنشاء موديول `Audit` (rank 3).
2. نقل `audit_events` و `SensitiveAccessEvent` infrastructure.
3. تحديث `TABLE_OWNERS` و `PLANNED_MODULES`.
4. Audit events: `IdentityAuthenticated`, `DocumentDownloaded`, `WorkRecordSubmitted`، إلخ.

### المرحلة 6 — Web client refactor (أسبوع 8-9)
1. تفكيك `AppWorkspace` (807 → 5 components).
2. توحيد CSS (إزالة AppShell.css و WorkspaceTabs.css).
3. استبدال `PlatformSettingsMockData` بـ real API.
4. حذف `w1-3/*` snapshots.
5. زيادة unit tests لـ features (49 → 70+).

### المرحلة 7 — CI + Tooling (أسبوع 10)
1. إضافة `make docs:validate` target.
2. تفكيك `openapi_reconciler.py` (51KB).
3. استبدال `inventory-routes.py` بـ `php artisan route:list --json`.
4. توحيد `run-*.sh` في Makefile.
5. cache لـ composer install.
6. npm audit في CI.
7. (اختياري) E2E job في CI مع self-hosted runner.

## 6 · مؤشرات النجاح (KPIs) — محدَّث 2026-07-25

| المؤشر | الحالي | المستهدف |
|--------|--------|----------|
| `tests/Architecture/ModuleBoundariesTest::misplacedBusinessFiles` count | 75 (انخفض من 89 بـ 14 منقول) | 0 (انتهاء 2027-04-25) |
| `TABLE_OWNERS` entries | 39 (ناقصة) | 50+ (نظيف) |
| Web client unit tests | 212 ملف `.test.{ts,tsx}` | تغطية متكافئة |
| `AppWorkspace.tsx` LOC | 1 (re-export) | 1 ✅ |
| `AppWorkspaceShell.tsx` LOC | 271 | < 300 |
| `AppShell.tsx` LOC | 583 | < 300 |
| `AppShell.css` LOC | 864 | < 200 |
| `OpenAPI` source of truth | `docs/contracts/api/openapi.yaml` ✅ | — |
| CSRF gaps | 1 (`UpdateDocumentController`) | 0 |
| Dead code paths | 2 (`ResolveAuthorizationSimulationFacts`, `ConsumeSubmittedNotification`) | 0 |
| Outbox events typed | 100% (`OutboxEventType` enum = 56 حالة، 55 schema) | 100% ✅ |
| Self-hosted E2E CI | قالب جاهز (`ci-e2e.yml`) | provisioning runner |

## 7 · القرارات المعمارية الموصى بها (محدَّث 2026-07-25)

1. **Audit as a first-class module** (rank 3) — استخراج `audit_events` و `SensitiveAccessEvent`.
2. ✅ ~~**Outbox events as Contracts**~~ — **مُنجَز** (`OutboxEventType` enum + 55 JSON Schema).
3. **CI E2E** (self-hosted runner) — التقليل من فجوة localhost-only (workflow جاهز).
4. ✅ ~~**Web client** كـ feature-first~~ — **مُنجَز جزئياً** (AppWorkspace تفكيكه تم).
5. ✅ ~~**Documentation as Code** — OpenAPI في `docs/contracts/api/`، catalog في `docs/architecture/`~~ — **مُنجَز**.
## 8 · مخرجات هذا التحليل

| الوثيقة | الرابط |
|--------|-------|
| Overview & TOC | `00-overview.md` |
| Architecture (global) | `01-architecture.md` |
| Shared + App crosscutting | `02-shared-crosscutting.md` |
| Identity | `03-identity.md` |
| Authorization | `04-authorization.md` |
| Organization | `05-organization.md` |
| WorkRecords + WorkDefinitions | `06-work-records.md` |
| Documents | `08-documents.md` |
| Tasks | `09-tasks.md` |
| Notifications | `10-notifications.md` |
| Workflow | `11-workflow.md` |
| Reporting + Search | `12-reporting.md` |
| PlatformSettings | `14-platform-settings.md` |
| Web client | `15-web-client.md` |
| Scripts + Infra + CI | `16-scripts-and-tooling.md` |
| Cross-cutting risks (هذا الملف) | `17-cross-cutting-risks.md` |
