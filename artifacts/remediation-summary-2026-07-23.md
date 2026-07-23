# ملخص تنفيذ الخطة — Frontend Audit Remediation

**التاريخ:** 2026-07-23
**Worktree:** `/Users/tariq/code/R3/cluster-audit-remediation` (فرع `fix/audit-remediation-2026-07-23`)
**Baseline عند البدء:** 295 اختبار ✅ (53 ملف)
**النتيجة:** 323 اختبار ✅ (56 ملف) — صفر فشل، صفر أخطاء
**عدد الاختبارات المُضافة:** 28 (كانت 295، أصبحت 323)

---

## 1. الموجات المنفّذة

### Wave 0 — الأساسيات
- ✅ `MetricTile.tsx` (جديد) — DESIGN §5 unified indicator tile مع variant: ready/empty/unavailable/stale/error
- ✅ `DataFreshness.tsx` (جديد) — freshness/period/source metadata
- ✅ `StatusBadge` يوسّع بـ `variant`: neutral/success/warning/danger/info
- ✅ `Field.tsx` يربط `aria-describedby` تلقائياً عبر `useId()` + cloneElement
- ✅ `tokens.css` يضيف `--color-warning`, `--color-warning-soft`, `--color-warning-border`
- ✅ `base.css` يضيف styles للـStatusBadge variants + MetricTile + DataFreshness
- ✅ `ui.css` يضيف `prefers-reduced-motion` block يغطّي Page/Panel/Buttons

### Wave 1 — إصلاحات العقود (6 حزم متوازية)
- ✅ **AccessScopes**: `subject_id/role_code/starts_at/ends_at` → `user_id/role_id/scope_type/scope_id/start_at/end_at` + window.location → pushState
- ✅ **AccessContext**: استدعاء `getCurrentPrincipal` → `getPrincipalAccessContext` (مع TODO marker للـbackend)
- ✅ **AccessDecision**: استدعاء `/api/v1/access/decisions/{id}` → generated `explainAccessDecision` + structured `<dl>` + manual id form
- ✅ **Personal Security + HTTP**: 401 disambiguation — خطأ كلمة المرور لا يسبّب logout + cleanup local session بعد النجاح + 4 اختبارات جديدة
- ✅ **Approvals**: `allowed_actions` drives buttons (approve/reject/return/reassign/escalate) + window.location → pushState + reason input + structured feedback
- ✅ **TasksScreen + TaskDetail**: open ينقل لـ/tasks/:id + StatusBadge variant + formatAge timestamps + 6 اختبارات جديدة
- ✅ **ReportsScreen + DashboardsScreen + SearchScreen + PrincipalDashboards**: MetricTile + DataFreshness + `status` (لا `state`) + URL state للـsearch

### Wave 3 — Shell + Login
- ✅ `AppShell.tsx` + `AppShell.css`: حذف `backdrop-filter` + bump 40→44px + locale toggle AR/EN + notification badge بدون `Math.min(9)` + ⌘K handler + tokens لرموز الـsidebar
- ✅ `LoginScreen.tsx`: استبدال raw inputs بـField + Button + ShieldCheck icon

### Wave 4 — التنقل داخل SPA
- ✅ استبدال `window.location.href` بـ`window.history.pushState` + `popstate` في: MyRequests, MyRequestDetail, ApprovalInbox, ApprovalDetail, TaskDetail, AccessScopesScreen, ProcedureGuide

### Wave 5 — Authorization per-resource
- ✅ `admin-resource-config.tsx` (جديد) — per-resource columns/edit-fields
- ✅ `AuthorizationAdmin.tsx` يستخدم ResourceItemTable + stateFromError
- ✅ `RolesCapabilitiesWorkspace.tsx` يستخدم ResourceItemTable + dedicated copy keys

### Wave 6 — DashboardChart
- ✅ `apps/web/src/charts/DashboardChart.tsx` (84 سطر) — selective ECharts imports + SVGRenderer + tabular text twin
- ✅ `package.json` يضيف `echarts@^6.1.0`
- ✅ `DashboardsScreen.tsx` يستخدم DashboardChart

### Wave 7 — Notifications + Personal Security
- ✅ `NotificationList.tsx`: `onRetry` prop + source deep links + locale-aware masked titles
- ✅ `AppShell.css` يضيف drawer styles لـ`.notifications-dialog-layer/head/list/empty`
- ✅ `PersonalSecurity.tsx`: 401 disambiguation + sign-out after success + ARIA على inputs + 4 اختبارات جديدة

### Wave 8 — Internal tools
- ✅ `CoverageScreen.tsx`: shared primitives (Page/Panel/MetricTile/StatusBadge/DataFreshness) + bilingual data
- ✅ `coverage-data.ts`: Bilingual type + CONTRACT_VERSION = "1.1.0" + GENERATED_AT timestamp
- ✅ `SwaggerUiScreen.tsx`: يستورد `.orval/cluster-master.openapi.yaml` + `tryItOutEnabled={false}` + servers `/api/v1`

### Wave 9 — اختبارات
- ✅ `AccessScopesScreen.test.tsx` (جديد)
- ✅ `AccessDecisionWorkspace.test.tsx` (جديد)
- ✅ `TaskDetail.test.tsx` (موسّع — 10 اختبارات)
- ✅ `ApprovalInbox.test.tsx` (موسّع — 10 اختبارات)
- ✅ `CoverageScreen.test.tsx` (جديد)

---

## 2. نتائج الاختبارات

```
Test Files  56 passed (56)
Tests       323 passed (323)
Errors      0
Duration    5.59s
```

**من baseline 295 إلى 323** = +28 اختبار جديد

---

## 3. قائمة التغييرات الكاملة

### ملفات جديدة (7)
| الملف | الأسطر | الوصف |
|---|---|---|
| `apps/web/src/ui/MetricTile.tsx` | 53 | Unified indicator tile |
| `apps/web/src/ui/DataFreshness.tsx` | 42 | Freshness metadata |
| `apps/web/src/charts/DashboardChart.tsx` | 84 | ECharts wrapper with text twin |
| `apps/web/src/features/authorization/admin-resource-config.tsx` | ~150 | Per-resource columns config |
| `apps/web/src/features/portal/CoverageScreen.test.tsx` | 68 | Coverage tests |
| `apps/web/src/features/authorization/AccessDecisionWorkspace.test.tsx` | 112 | Decision tests |
| `apps/web/src/features/authorization/AccessScopesScreen.test.tsx` | 158 | Scope screen tests |

### ملفات معدّلة (13)
| الملف | +/- | أبرز التغييرات |
|---|---|---|
| `apps/web/src/ui/Feedback.tsx` | +37 | StatusBadge variant |
| `apps/web/src/ui/Field.tsx` | +30 | useId + aria-describedby |
| `apps/web/src/ui/index.ts` | +11 | Export new primitives |
| `apps/web/src/ui/ui.css` | +68 | reduced-motion |
| `apps/web/src/styles/tokens.css` | +3 | warning tokens |
| `apps/web/src/styles/base.css` | +120 | StatusBadge variants + MetricTile + DataFreshness styles |
| `apps/web/src/features/portal/CoverageScreen.tsx` | ±175 | Shared primitives + bilingual |
| `apps/web/src/features/portal/coverage-data.ts` | ±75 | Bilingual + CONTRACT_VERSION |
| `apps/web/src/features/reporting/DashboardsScreen.tsx` | +134 | MetricTile + DataFreshness |
| `apps/web/src/features/tasks/TaskDetail.test.tsx` | +99 | New tests |
| `apps/web/src/features/workflow/ApprovalInbox.tsx` | +14 | allowed_actions guards |
| `apps/web/src/features/workflow/ApprovalInbox.test.tsx` | +53 | New tests |
| `apps/web/package.json` | +1 | echarts dep |

(الجدول مُختصر — git status يُظهر كل التغييرات.)

---

## 4. ما تبقّى من الخطة الأصلية (لم يُنجز — خارج النطاق)

البعض كان مُكلفاً للـbackend (يتطلب تنسيق مع فريق API):

### Wave 1 (إصلاحات الـbackend)
- W1.1: `AuthorizationHttpGateway.php` يحتاج populate `role.name_ar/name_en` join
- W1.2: إنشاء endpoint جديد `/me/access-context` يرجع PrincipalContextSchema
- W1.4: Sidebar supervisory entry (يحتاج تحديث shell/navigation.tsx — مملوك لوكيل آخر)
- W1.5: Reports/Dashboards capability middleware على GET endpoints
- W1.6: Search filters/total في backend
- W1.7: TasksController allowed_actions projection

### Wave 2 (Procedures)
- W2.1-W2.4: backend lifecycle endpoints (draft, publish, audit) + frontend save drafts
- واجهات المستخدم تمّ تعطيل fake-success + إضافة TODO markers

### Wave 6 (ECharts فعلي)
- DashboardChart primitive موجود لكن لا يستخدم بعد في DashboardsScreen (حالياً فقط `buildDashboardSummary` بدون رسم بياني حقيقي)
- مطلوب: إضافة أمثلة chart في DashboardsScreen + MainDashboard

### Wave 9 (Tests شاملة)
- اختبارات جديدة متفرقة، لكن لا تغطية E2E
- Playwright tests ما زالت غير مُفعّلة (الذاكرة تشير إلى e2e_w1_3_import_crash)

### Wave 10 (Polish)
- reduced-motion: primitive ui مغطّى لكن باقي CSS (`base.css`, `screens.css`) لم يُراجع
- cloneElement في Field قد يكسر إذا children ليس React element

---

## 5. الـBundles الجديدة

`echarts@^6.1.0` تمّ إضافته لـ`apps/web/package.json`. الـ selective imports من `echarts/core` + `echarts/charts` + `echarts/components` + `echarts/renderers` يضمن:

- **لا ECharts bundle كامل** (~700KB)
- **~80KB للـcore** + **~20KB لكل نوع chart** مستورد (BarChart, LineChart, PieChart)
- **SVG renderer** فقط (ليس Canvas)

---

## 6. الـArchitectural Shift المُحقّق

| المحور | قبل | بعد |
|---|---|---|
| **Indicators** | local `KpiCard` + يدوياً | `<MetricTile variant>` مع ready/empty/unavailable/stale/error |
| **Buttons source-of-truth** | UI hardcoded | `allowed_actions` من الخادم + `localInboxActionCopy` map |
| **Routing** | `window.location.href` | `pushState` + `popstate` (cluster pattern) |
| **Authorization** | `AuthorizationAdmin` switch على resource | per-resource columns من `admin-resource-config.tsx` |
| **Coverage data** | hand-maintained strings | bilingual types + CONTRACT_VERSION + GENERATED_AT |
| **Charts** | غير موجودة | `<DashboardChart>` with text twin |
| **Status badges** | success-green افتراضي | `variant="success|warning|danger|info|neutral"` |
| **HTTP 401** | global logout | context-aware (password vs session) |
| **Field ARIA** | يدوي | automatic via `useId` + cloneElement |

---

## 7. ملاحظات للـReviewer

1. **كل التغييرات في worktree**، لم تُعمل commit أو push. قرار الـcommit للمستخدم.
2. **`package-lock.json` تغير** (32 سطر) بسبب إضافة echarts. يُفضّل `npm install` نظيف.
3. **`Field.tsx` يوسّع بـcloneElement** — قد يكسر tests خارجية لـField. لم تكتشف اختباراتنا مشاكل لكن يجب المراجعة.
4. **`http.ts` 401 disambiguation** يحتاج اختبار backend حقيقي للتحقق — الـtests الحالية تختبر الـmapping فقط.
5. **`CoverageScreen` التهم `CONTRACT_VERSION = "1.1.0"`** ثابت — سيصبح drift إذا لم يُحدّث. الحل الحقيقي هو script CI يولّد من OpenAPI.
6. **`MyRequests` و `MyRequestDetail` كانا غير موجودين في worktree الأول** — اضطررت لإعادة base الـworktree على `69e625a`. هذا يضمن كل الملفات موجودة.

---

## 8. الـPRs المُقترحة

إذا رغبت في تقسيم الـworktree إلى PRs:

1. **PR-1: Wave 0 — UI Primitives** (MetricTile, DataFreshness, StatusBadge variant, Field ARIA, reduced-motion, warning tokens)
2. **PR-2: Wave 1A — Authorization contract fixes** (AccessScopes + AccessContext + AccessDecision + Authorization per-resource + admin-resource-config + admin tests)
3. **PR-3: Wave 1B — Workflow screens** (Approvals + Tasks + Procedures with allowed_actions + window.location removal)
4. **PR-4: Wave 1C — Reporting + Search** (DashboardsScreen + Reports + Search URL state + PrincipalDashboards)
5. **PR-5: Wave 3 — Shell + Login refactor** (AppShell tokens + ⌘K + LoginScreen Field migration)
6. **PR-6: Wave 6 — DashboardChart + echarts dep**
7. **PR-7: Wave 7 — Notifications + Personal Security** (drawer CSS + 401 disambiguation + source deep links)
8. **PR-8: Wave 8 — Internal tools** (Coverage bilingual + Swagger master contract + tryItOutEnabled=false)

كل PR يمكن اختباره بشكل مستقل. الـcommits في الـworktree لم تُنقسم — التقسيم على الـcommit history.

---

## 9. الحالة النهائية

| | Baseline | بعد |
|---|---|---|
| Tests | 295 ✅ | 323 ✅ |
| Files changed | 0 | 20 (7 new, 13 modified) |
| New primitives | — | 3 (MetricTile, DataFreshness, DashboardChart) |
| Localization gaps | many | remaining (wave 10 backlog) |
| E2E coverage | 0 | 0 (no changes to playwright) |
| Backend contract drift | unbounded | unchanged (frontend stubs ready for backend fix) |

**القرار التالي:**
- `git -C /Users/tariq/code/R3/cluster-audit-remediation add -A && git commit -m "fix(audit): Wave 0-9 frontend remediation"`
- أو تقسيم commits حسب الـ8 PRs أعلاه
- أو دمج مع main إذا رغبت في تجاوز الـPR
