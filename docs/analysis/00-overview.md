# تحليل تفصيلي لمشروع Cluster (R3)

> وثيقة تحليل evidence-first لبنية ومعماريات وموديولات النظام.
> **التاريخ:** 2026-07-25
> **النطاق:** `apps/api` (Laravel 13.8) + `apps/web` (React 19 + Vite 8) + `scripts/` + `infra/`
> **المنهجية:** تفصيل تفصيلي لكل ملف بفروقات (file:line)، وثلاثة محاور في كل قسم:
> **الوضع الحالي**، **المشاكل/المخاطر**، **التحسينات المقترحة**.

## فهرس الوثائق

| # | الوثيقة | الوصف |
|---|--------|-------|
| 1 | [`01-architecture.md`](01-architecture.md) | البنية العامة، طبقات النظام، حدود الموديولات، الترتيب الرتبي (ranks)، الـ crosscutting middleware، وقواعد الإرث |
| 2 | [`02-shared-crosscutting.md`](02-shared-crosscutting.md) | `Shared/Contracts`, `Shared/Infrastructure`, `app/Http/Middleware`, `app/Integrations`, `app/Support`, `AppServiceProvider` |
| 3 | [`03-identity.md`](03-identity.md) | موديول Identity (المصادقة، الجلسات، TOTP، كلمة المرور، التنشيط، استهلاك أحداث Organization) |
| 4 | [`04-authorization.md`](04-authorization.md) | موديول Authorization (RBAC+ABAC، ExplicitDeny، Classification، FieldAccess، Delegation، SensitiveAccess، OperationsOffice) |
| 5 | [`05-organization.md`](05-organization.md) | موديول Organization (Cluster/Facility/Unit/Person/Position/Assignment/JobTitle/Import/Temp/Supervisory) |
| 6 | [`06-work-records.md`](06-work-records.md) | موديول WorkRecords (Submit/List/GetAuthorized) |
| 7 | `WorkDefinitions` | موديول WorkDefinitions (publish-request-fixture + resolve-published) — موثَّق في `module-catalog.md` §2 |
| 8 | [`08-documents.md`](08-documents.md) | موديول Documents (Upload/Download/Link + ClamAV + S3) |
| 9 | [`09-tasks.md`](09-tasks.md) | موديول Tasks (Create/Complete/Transition + Workflow integration) |
| 10 | [`10-notifications.md`](10-notifications.md) | موديول Notifications (Inbox/Outbox + Streams Workers) |
| 11 | [`11-workflow.md`](11-workflow.md) | موديول Workflow (Engine + Queries + Assignment Rules) |
| 12 | [`12-reporting.md`](12-reporting.md) | موديول Reporting (Read-models + Dashboards + Exports) |
| 13 | `Search` | موديول Search (Search Indexer + Accessible records) — موثَّق في `module-catalog.md` §2 |
| 14 | [`14-platform-settings.md`](14-platform-settings.md) | موديول PlatformSettings (Settings/Alerts/Calendar/Maintenance/Operations/Logs) |
| 15 | [`15-web-client.md`](15-web-client.md) | العميل React/Vite (shell/routing/api/features/UI/E2E) |
| 16 | [`16-scripts-and-tooling.md`](16-scripts-and-tooling.md) | السكربتات، infra، CI، Make targets، التحققات، خطوط الإنتاج |
| 17 | [`17-cross-cutting-risks.md`](17-cross-cutting-risks.md) | المخاطر الشاملة على مستوى كل المنظومة وخارطة طريق الأولويات |

## ملخّص تنفيذي (TL;DR) — محدَّث 2026-07-25

- **النمط المعتمَد:** Laravel Modular Monolith + Module Layered Architecture (Contracts → Domain → Features → Infrastructure → Http) مع ثلاث طبقات معمارية:
  - **Shared kernel**: `Shared/Contracts/TransactionalOutbox` + `Shared/Infrastructure/{Outbox,Streams}` فقط.
  - **Module kernel**: كل موديول يتبع الترتيب الرتبي (rank) ويمنع cross-owner imports إلا عبر `Contracts` أو `Events`.
  - **App/Shell**: `app/Http/Middleware` (Identity/CORS/Maintenance)، `app/Integrations` (Platform APIs)، `app/Providers/AppServiceProvider` (composition root)، `app/Support` (Seeders).
- **حالة الـ DDD نظيفة في معظم الموديولات**، مع **75 controller إرث** في `app/Http/Controllers/` مسجَّل في `tests/Architecture/ModulePlacementInventory.php` (انخفض من 89 بـ 14 منقول في Stage 6.8) بانتظار النقل إلى `Modules/<Name>/Features/*/Http/`. تاريخ انتهاء الـ 60 المتبقية: 2027-04-25.
- **آخر إصلاحات حرجة منجزة:**
  1. ✅ `docs/contracts/api/` مُستعاد — `openapi.yaml` (10380 سطر) + `w1-1` + `w1-2` + `r1-screens`.
  2. ✅ `docs/architecture/module-catalog.md` مُنشَأ (المرجع الأعلى للـ ranks والقرارات 6.5–6.8).
  3. ✅ `RequireIdentitySessionPrincipal` تحوّل إلى enforcer حقيقي (Stage 6.5) — لم يعد dead middleware.
  4. ✅ `IdentitySecurityEventRegistry` (Stage 6.7) — عقد موحَّد لـ 12 security event suffix.
  5. ✅ Self-hosted E2E CI workflow (`.github/workflows/ci-e2e.yml`) — قالب جاهز.
  6. ✅ `AppWorkspace.tsx` مُفكَّك — 1 سطر (re-export)؛ التقطيع في 5 مكونات (`AppWorkspaceShell` 271 + `WorkspaceContent` 271 + `WorkspaceHeader` 90 + `WorkspaceSidebar` 64 + `WorkspaceTabs` 54).
- **مخاطر حرجة متبقية (P0):**
  1. **CSRF gap** — `UpdateDocumentController` لا يستخدم `IdentityCsrfMiddleware` رغم كونه mutation.
  2. **9 جداول ناقصة** في `TABLE_OWNERS` (`work_definition_versions`, `search_index_entries`, `platform_settings_outbox`, `notification_inbox`, إلخ).
  3. **`audit_events` ownership drift** — مُسجَّل لـ Authorization لكن Audit مُخطَّط (rank 3).
  4. **`AppServiceProvider` 473 سطر** — يحتاج تفكيك إلى ModuleServiceProvider.
- **Web client** متين: React 19 + Vite 8 + Orval generated client + Playwright E2E (22 spec) + 212 ملف `.test.{ts,tsx}`. AppWorkspace تفكيكه تم، لكن `AppShell.tsx` (583 سطر) و `AppShell.css` (864 سطر) ما زالا بحاجة لتقسيم.
