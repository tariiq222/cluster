# 15 · Web client (React + Vite + Orval + Playwright)

> **المسار:** `apps/web/`
> **Stack:** React 19.2 + Vite 8.1 + TypeScript 6 + Orval 8.22 + Vitest 4 + Playwright 1.61.1 + echarts 6 + lucide-react + swagger-ui-react + js-yaml
> **عدد الملفات:** 175 TS/TSX

## 1 · بنية الـ Web client

```
src/
  main.tsx              (entry)
  App.tsx               (session bootstrap + 401 handler)
  app/                  (AppWorkspace, AppShell, WorkspaceTabs, LoginScreen, NotificationList, copy.ts, principal-context, session-context)
  shell/                (routes.ts, navigation.tsx)
  api/                  (fetcher, http, session, organization, identity, documents, work-records, platform-settings, w1-3/*, index)
  features/             (15 feature modules: authorization, dashboard, docs, documents, identity, imports, organization, platform-settings, portal, r1, reporting, requests, tasks, work-records, workflow)
  ui/                   (Button, Drawer, Field, MetricTile, Page, Select, Feedback, DataFreshness, cx, ui.css)
  styles/               (tokens.css, base.css, screens.css)
  charts/               (chart utilities)
  test/                 (test support)
  api/generated/        (Orval-generated client — DO NOT EDIT)
  orval.config.ts
  vite.config.ts
  vitest.config.ts
  playwright.config.ts
  redocly.yaml
```

## 2 · الإعدادات (scripts من `package.json`)

| Script | الوصف |
|--------|-------|
| `dev` | `vite` |
| `build` | `tsc -b && vite build` |
| `lint` | `oxlint` |
| `preview` | `vite preview` |
| `test:unit` | `vitest run` |
| `coverage` | `vitest run --coverage` (100% on `src/api.ts`) |
| `api:format` | `prettier --write ./src/api/generated` |
| `api:lint` | `redocly lint` على 4 OpenAPI sources |
| `api:bundle` | `redocly bundle` ينشئ `.orval/cluster-master.openapi.yaml` + 3 w1.x + يبني `build-client-contract.mjs` |
| `api:generate` | `npm run api:bundle && orval --config ./orval.config.ts` |
| `api:docs` | `redocly build-docs` |
| `api:check` | `api:lint && node ./check-generated-api.mjs` |
| `test:e2e:list` | `playwright test --list` |
| `test:e2e:local` | `PLAYWRIGHT_BROWSERS_PATH=... playwright test` |
| `test:e2e:w1-2` | `playwright test --config playwright.w1-2.config.ts` |

## 3 · تدفّق الـ session

1. **`main.tsx`** يهيّئ React root + `StrictMode` + IBM Plex Sans Arabic.
2. **`App.tsx`** يستعيد `Session` من الكوكي عبر `restoreSession()`، يفعّل `registerSessionExpiredHandler` لـ 401، يعرض `LoginScreen` أو `AppWorkspace`.
3. **`AppWorkspace.tsx`** (1 سطر) — re-export فقط: `export { default as AppWorkspace, RouteAccessGuard } from './AppWorkspaceShell'`. التفكيك تم في `AppWorkspaceShell.tsx` (271 سطر) + `WorkspaceContent.tsx` (271 سطر) + `WorkspaceHeader.tsx` (90 سطر) + `WorkspaceSidebar.tsx` (64 سطر) + `WorkspaceTabs.tsx` (54 سطر) = **750 سطر موزعة على 5 مكونات**.

## 4 · الـ API client (Orval)

- **Orval** يُولّد `apps/web/src/api/generated/cluster.ts` من `.orval/cluster-master.openapi.yaml`.
- **`fetcher.ts`** الـ custom fetch (35 سطر): `credentials: 'include'`، defensive JSON parse، EMPTY_BODY_STATUSES = [204, 205, 304].
- **`http.ts`** (247 سطر): `ApiError`, `ResourceState` (loading/ready/empty/forbidden/not-found/conflict/stale/error), `requestInit(token, options)`, `parseStrongEtag`, `unwrap`, `unwrapEnvelope`, `unwrapWithEtag`, `unwrapEmpty`, `registerSessionExpiredHandler`.
- **`index.ts`** يصدر كل المساعدين + domain wrappers.
- **Domain wrappers** في `apps/web/src/api/{session,organization,identity,documents,work-records,platform-settings}.ts` (تُغلِّف generated calls).
- **`w1-3/*`** لإصدارات snapshot contract.

## 5 · الـ Routes & Navigation

`apps/web/src/shell/routes.ts` (450+ سطر) يعرّف:
- **`AppRoute`** union (40+ نوع) مع workspace grouping.
- **`ROUTE_WORKSPACE`** يربط كل route بـ `RouteWorkspace` (`organization`, `roles-capabilities`, `platform-settings`, null).
- **`capabilitiesForRoute(route)`** يرجّع capabilities اللازمة (مثل `organization.cluster.read`).
- **`isRouteVisible(route, capabilities)`** يقرّر ظهور route في sidebar.
- **`pathFromRoute` و `routeFromPath`** (path ↔ route mapping).
- **`PLATFORM_SETTINGS_SECTIONS`** enum: `overview, security, calendars, backups, logs, health, maintenance`.
- **`DEFERRED_CAPABILITIES`** للقدرات المؤجلة (Technical logs مثلاً).

## 6 · الـ UI Primitives (`ui/`)

- `Button`، `Drawer`، `Field`، `MetricTile`، `Page`، `Select`، `Feedback`، `DataFreshness`.
- `cx` — className utility.
- `ui.css` — design tokens.

## 7 · الـ Features (15 module)

| الميزة | المسار | الوصف |
|--------|-------|-------|
| authorization | `features/authorization/` | Roles/Capabilities/RoleAssignments/Delegations/Supersvisory/Classification/FieldAccess UI |
| dashboard | `features/dashboard/` | Dashboards UI |
| docs | `features/docs/` | Swagger UI (lazy) + API docs |
| documents | `features/documents/` | Documents UI (upload, list, download, link) |
| identity | `features/identity/` | Identity accounts, login, settings |
| imports | `features/imports/` | Import jobs UI |
| organization | `features/organization/` | Cluster/Facility/Units/People/Positions/Assignments |
| platform-settings | `features/platform-settings/` | Settings versions، alerts، calendars، maintenance، operations، logs |
| portal | `features/portal/` | الرئيسية/landing |
| r1 | `features/r1/` | R1 release UI |
| reporting | `features/reporting/` | Reports + dashboards + exports |
| requests | `features/requests/` | My requests + procedure authoring + approvals |
| tasks | `features/tasks/` | Tasks + engagement |
| work-records | `features/work-records/` | Work records list/detail/submit |
| workflow | `features/workflow/` | Approval inbox + workflow details + admin |

**ملاحظة:** `r1` و `portal` و `requests` و `strategy` (لا يوجد) قد تكون placeholders لشاشات لاحقة.

## 8 · الاختبارات

- **Unit (Vitest):** 49 ملف test، يكتشف `src/**/*.test.{ts,tsx}`. coverage 100% على `src/api.ts`.
- **E2E (Playwright):** 22 ملف spec تحت `e2e/`:
  - `capability-navigation.spec.ts`
  - `dashboard-navigation-browser-qa.spec.ts`
  - `day2-workflow.spec.ts`
  - `day3-r1.spec.ts`
  - `documents.spec.ts`
  - `login.spec.ts`
  - `org-hierarchy-check.mjs`
  - `org-hierarchy-tree.spec.ts`
  - `personal-work.spec.ts`
  - `platform-settings-comprehensive.spec.ts`
  - `platform-settings-live.spec.ts`
  - `platform-settings-workflows.spec.ts`
  - `platform-settings.spec.ts`
  - `r1-screens-enhancements.spec.ts`
  - `r1-screens.spec.ts`
  - `requests-and-approvals.spec.ts`
  - `shell.spec.ts`
  - `w1-2-admin.spec.ts`
  - `w1-2-cookie-csrf.spec.ts`
  - `w1-3-authorization.spec.ts`
  - `walking-skeleton.spec.ts`
  - `workflow-authoring.spec.ts`
  - `workflow-details.spec.ts`

## 9 · Playwright Config

- `testDir: './e2e'`
- `fullyParallel: false`، `forbidOnly: true`، `retries: 0`، `workers: 1`
- `locale: 'ar-SA'` (Arabic default)
- `trace: 'retain-on-failure'`
- `W1_1_API_ORIGIN` env إلزامي (localhost only)
- `W1_1_WEB_PORT` default 4173

## 10 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| W1 | ✅ ~~`docs/contracts/api/` مفقود~~ — **مُنجَز**: `openapi.yaml` (10380 سطر) + `w1-1` + `w1-2` + `r1-screens` موجودون تحت `docs/contracts/api/` مع `README.md` يصف خط أنابيب التوليد. | `docs/contracts/api/` |
| W2 | ✅ ~~`AppWorkspace.tsx` (807 سطر)~~ — **مُنجَز**: الآن 1 سطر (re-export). التفكيك في `AppWorkspaceShell.tsx` (271) + `WorkspaceContent.tsx` (271) + `WorkspaceHeader.tsx` (90) + `WorkspaceSidebar.tsx` (64) + `WorkspaceTabs.tsx` (54). | `src/app/AppWorkspace.tsx` |
| W3 | ✅ ~~`ShellInner` (590 سطر)~~ — **مُنجَز**: الاسم `ShellInner` لم يعد موجوداً. التقطيع تم في `AppWorkspaceShell.tsx` + `WorkspaceContent.tsx`. | `src/app/AppWorkspaceShell.tsx` |
| W4 | `AppShell.tsx` (583 سطر) و `AppShell.css` (864 سطر) — لا تزالان بحاجة لتقسيم إلى design system موحَّد. | `src/app/AppShell.tsx` |
| W5 | `WorkspaceTabs.css` (1.8K) — يحتاج توحيد مع `ui.css`/`tokens.css`. | `src/app/WorkspaceTabs.css` |
| W6 | `features/portal/`, `features/r1/`, `features/requests/` — ليست placeholders: `portal/CoverageScreen.tsx`, `r1/R1Screens.tsx`, `requests/RequestForm.tsx` + `RequestDashboard.tsx` + `RequestDetail.tsx` كلها منفَّذة. | `src/features/*` |
| W7 | `PlatformSettingsMockData` يُستخدم في `AppWorkspace` (mock data في الـ shell) — **لم يُنجَز**. | `AppWorkspace.tsx` |
| W8 | ✅ ~~`Redocly` و `Orval` chain يتطلب `docs/contracts/api/` (مفقود)~~ — **مُنجَز**: الـ pipeline موثَّق في `docs/contracts/api/README.md` ويعمل ضد المصادر الفعلية. | `redocly.yaml` |
| W9 | `playwright.config.ts` يحدّ `W1_1_API_ORIGIN` على localhost فقط (OK للـ local). | `playwright.config.ts:30-37` |
| W10 | **212 ملف `.test.{ts,tsx}` حالياً** (تحقق 2026-07-25) — Coverage غيـر متكافئ عبر الـ features. | `find apps/web -name '*.test.*'` |
| W11 | `SwaggerUiScreen` lazy import لكن `swagger-ui-react` ثقيل. | `AppWorkspaceShell.tsx` |
| W12 | `w1-3` snapshot contracts ما زالت في `apps/web/src/api/w1-3/` — هل يجب أرشفتها؟ | `src/api/w1-3/` |

## 11 · التحسينات المقترحة (محدَّث 2026-07-25)

1. ✅ ~~**استعادة `docs/contracts/api/`** وربط `redocly.yaml`** — **مُنجَز**: `docs/contracts/api/openapi.yaml` (10380 سطر) + 3 snapshots + `README.md` موثَّق.
2. ✅ ~~**تفكيك `AppWorkspace`** إلى `ShellInner` + sub-components~~ — **مُنجَز**: التفكيك في `AppWorkspaceShell.tsx` (271) + `WorkspaceContent.tsx` (271) + `WorkspaceHeader.tsx` (90) + `WorkspaceSidebar.tsx` (64) + `WorkspaceTabs.tsx` (54).
3. **استخراج `routeCapabilities` lookup** إلى جدول `Map<AppRoute, readonly string[]>` لتفادي الـ if-else الطويل.
4. **تفكيك `AppShell.tsx` (583 سطر) و `AppShell.css` (864 سطر)** — استخراج design system موحَّد في `ui/` + `styles/tokens.css` + `styles/base.css`.
5. **استبدال `PlatformSettingsMockData`** في الـ shell بـ real API (تقليل mock في production).
6. **حذف `w1-3/` snapshots** أو أرشفتها في `.archive/`.
7. **زيادة unit tests** على features (212 ملف `.test.{ts,tsx}` حالياً — التحقق من التغطية المتكافئة).
8. **split `routes.ts`** إلى `routes.ts` (types) + `routes.map.ts` (path ↔ route).
9. **document `AppRoute` discrimination** مع Zod (لتحسين runtime safety).
10. **lazy load `swagger-ui-react`** بشكل أعمق (React.lazy + Suspense fallback).
