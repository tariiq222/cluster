# خطة تصحيح تدقيق الواجهات — Frontend Audit Remediation Plan

**التاريخ:** 2026-07-23
**المصدر:** `artifacts/frontend-audit-2026-07-23.md`
**النطاق:** 35 شاشة + الـShell + مسارات Login/NotFound
**المنهجية:** موجات رأسية قابلة للشحن (Vertical Slices)، كل خطوة = PR واحد، ملكية ملفات غير متعارضة
**المالك:** Sol (المدير) → يفوّض الحزم للوكلاء المباشرين بعمق تفويض واحد

---

## خريطة الأولويات

| الأولوية | العدد | الأثر |
|---|---|---|
| **P0** — حواجز شحن | 11 شاشة + 5 حرجة في الـShell | يجب إغلاقها قبل أي نشر إنتاجي |
| **P1** — رفع الجودة | 17 شاشة | يمكن نشرها تدريجياً مع إصلاحات P0 |
| **P2** — تحسينات مستمرة | متبقيات | backlog قابل للدخول في موجات لاحقة |

## بنية الموجات

```
W0  أساسيات الـUI (يوم 1-2)
 │   └─> يجب أن تكتمل قبل أي واجهة تستعمل بدائيات جديدة
 │
 ├──W1  تصحيح عقود API (يوم 3-7)  [أعمال متوازية]
 │       W1.1 AccessScopes · W1.2 AccessContext · W1.3 AccessDecision
 │       W1.4 Supervisory  · W1.5 Reports+Dashboards · W1.6 Search
 │       W1.7 Tasks allowed_actions
 │
 ├──W2  دورة حياة الإجراء Procedure lifecycle (يوم 8-10)
 │
 ├──W3  ترحيل الـShell والـLogin للبدائيات الموحدة (يوم 11-13) [أعمال متوازية]
 │
 ├──W4  التنقل الصحيح داخل SPA + بوابات القدرات (يوم 14-15) [أعمال متوازية]
 │
 ├──W5  موديول Authorization موحّد (يوم 16-19)
 │
 ├──W6  موديول Reporting + DashboardChart (يوم 20-23)
 │
 ├──W7  Notifications + Personal Security (يوم 24-25)
 │
 ├──W8  الأدوات الداخلية Coverage + Swagger (يوم 26-27)
 │
 ├──W9  تغطية الاختبارات (يوم 28-30) [أعمال متوازية]
 │
 └──W10 تحسينات P2 (يوم 31-35) backlog
```

---

## Wave 0 — أساسيات الـUI (Foundation)

> **الهدف:** إضافة البدائيات التي يطلبها DESIGN.md §5 وفشل ووجودها يمنع الواجهات من تطبيق النظام الموحد.

### W0.1 — بدائيات المؤشرات (MetricTile + DataFreshness)
- **المالك:** Sol → `front-first-ui` أو `worker`
- **يعتمد على:** لا شيء
- **يفتح لـ:** W3 كامل، W5، W6
- **ينشئ:**
  - `apps/web/src/ui/MetricTile.tsx` بمقاييس label, value, unit, period, updatedAt, source, variant (zero/empty/unavailable/stale)
  - `apps/web/src/ui/DataFreshness.tsx` لعرض آخر تحديث + الفترة + تحذير stale
  - `apps/web/src/ui/StatusBadge.tsx` يستقبل variant="success|warning|info|neutral|danger" بدل الافتراضي الأخضر
- **يعدل:**
  - `apps/web/src/ui/index.ts` يصدّر البدائيات الثلاث
  - `apps/web/src/ui/ui.css` يضيف tokens للألوان الدلالية الجديدة
  - `apps/web/src/styles/base.css:1041-1051` يحذف default-green ويستدعي variant
- **فحص:**
  - `npm --prefix apps/web run build`
  - `npm --prefix apps/web test -- MetricTile DataFreshness StatusBadge`
- **معايير الإنجاز:**
  - كل primitive يدعم states: `loading | ready | empty | error | stale`
  - لا ألوان hex خام خارج الـtokens
  - `prefers-reduced-motion` يعطل دخول البطاقة

### W0.2 — Field يربط `aria-describedby` فعلياً
- **المالك:** Sol → `spark-medium`
- **يعتمد على:** لا شيء
- **يفتح لـ:** W3، W7، Login form
- **يعدل:**
  - `apps/web/src/ui/Field.tsx:25-37` يضيف `useId()` ويضع `aria-describedby={helpId}` و `aria-invalid={Boolean(error)}` على `<input>`/`<textarea>`/`<select>`
  - `apps/web/src/ui/Field.tsx` ينقل `aria-required` ليُطبَّق عندما `required` صحيح
- **فحص:** `npm --prefix apps/web test -- Field`
- **معايير الإنجاز:** قارئ الشاشة يقرأ help عند التركيز، error عند الخطأ.

### W0.3 — احترام `prefers-reduced-motion` على الـprimitives
- **المالك:** Sol → `spark-medium`
- **يعدل:** `apps/web/src/ui/ui.css:3-9, 158-167, 547-557` إضافة `@media (prefers-reduced-motion: reduce) { animation: none !important; transition: none !important; }` على `.ui-page`, `.ui-panel`, `.ui-button`
- **فحص:** chrome devtools reduced-motion ⇒ لا حركة
- **معايير الإنجاز:** صفحات Tasks/Procedures/Dashboards لا تتحرك تحت الـreduced-motion

---

## Wave 1 — تصحيح عقود الـAPI (Contract Drift)

> كل خطوة تستبدل واجهة تستدعي endpoint خاطئ أو يقرأ حقولاً غير موجودة بحقل صحيح. **الواجهة الأمامية + الخلفية معاً** ضمن نفس الـPR (الواجهة تبقى تُخضر الـtests + الواجهة الخلفية تُخضر اختبارات الوحدة).

### W1.1 — AccessScopes يطابق الـbackend
- **المالك:** Sol → `worker` (لون متوسطة)
- **الأثر:** 🔴 → 🟢
- **ينشئ:** `apps/api/Modules/Authorization/Features/ListRoleAssignments/Query/...` (إذا لم يوجد) يضمن إرجاع `user_id, role_id, scope_type, scope_id, start_at, end_at` مع ضمّ `role.name_ar/name_en`
- **يعدل:**
  - `apps/web/src/features/authorization/AccessScopesScreen.tsx:83` يستبدل `window.location.href` بـ `navigate('/admin/authorization/role-assignments')`
  - `apps/web/src/features/authorization/AccessScopesScreen.tsx:96-108` يطابق أسماء الحقول الفعلية (role.name_ar للعمود code، user_id للعمود user)
  - `apps/web/src/features/authorization/AccessScopesScreen.tsx` يقبل `navigate` كـprop
  - `apps/web/src/app/AppWorkspace.tsx:442` يمرّر `navigate` لـAccessScopesScreen
- **يضيف:** اختبار `apps/web/src/features/authorization/AccessScopesScreen.test.tsx` يتأكد من ظهور 5 أعمدة مع حقول وهمية صحيحة
- **فحص:**
  - `cd apps/api && php artisan test --filter=RoleAssignmentTest`
  - `npm --prefix apps/web test -- AccessScopes`
- **معايير الإنجاز:** الشاشة تعرض user/role/scope/window بقيم حقيقية (لا `—`).

### W1.2 — AccessContext يستدعي endpoint الصحيح
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **ينشئ:** `apps/api/Modules/Authorization/Features/GetPrincipalAccessContext/Http/...Controller.php` يرجع `PrincipalContextSchema` (tenant_id, organization_unit_ids, roles, capabilities, clearance, break_glass, correlation_id)
- **يعدل:**
  - `apps/web/src/api/r1.ts:39-42` يستبدل `getCurrentPrincipal` بـ `getPrincipalAccessContext`
  - `apps/web/src/features/authorization/AccessContext.tsx:108,153,159-161` يحذف `directionForLocale` المحلي ويستورد من `app/copy`
  - `apps/web/src/features/authorization/AccessContext.tsx:66-156` ينقل كل `accessContextLabels` إلى `apps/web/src/app/copy.ts` تحت namespace `accessContext`
  - `apps/web/src/features/authorization/AccessContext.tsx:442` يحذف `throw new ApiError(412)` الزائف؛ يستخدم `state='ready'` مع `selectedScope: null`
  - `apps/web/src/app/AppWorkspace.tsx:441` يمرّر `principal.projection` كـprop
- **يضيف:** اختبار `apps/web/src/features/authorization/AccessContext.test.tsx:60-110` يُوسّع ليشمل capabilities, clearance, break_glass
- **فحص:** `php artisan test --filter=GetPrincipalAccessContext` + `npm test -- AccessContext`
- **معايير الإنجاز:** الشاشة تعرض قائمة capabilities كاملة + clearance + break_glass

### W1.3 — AccessDecision يستخدم endpoint /explanation
- **المالك:** Sol → `spark-medium`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/web/src/features/authorization/AccessDecisionWorkspace.tsx:46-54` يستبدل `fetch('/api/v1/access/decisions/${id}')` بـ `explainAccessDecision(id, token)` من `apps/web/src/api/generated/cluster.ts:12243`
  - `apps/web/src/features/authorization/AccessDecisionWorkspace.tsx:76` يستبدل `<p>{stringify}</p>` بـ `<dl>` يعرض decision, action, resource_type, reason_codes, policy_version, evaluated_at
  - `apps/web/src/features/authorization/AccessDecisionWorkspace.tsx` يضيف `<Field>` لإدخال decision id يدوياً عند فقدان الـURL param
- **يقرر:** حذف `AuthorizationAdmin.tsx:307-321` (AccessExplanation المتكرر) للحفاظ على مصدر واحد
- **يضيف:** `AccessDecisionWorkspace.test.tsx` يستدعي العميل المولّد ويفهرس الحقول
- **فحص:** `npm test -- AccessDecision`
- **معايير الإنجاز:** الـdeep-link `/admin/authorization/explain/{validId}` يعرض القرار كاملاً

### W1.4 — Supervisory: شكل صحيح + ظهور في الـSidebar
- **المالك:** Sol → `worker`
- **الأثر:** 🟡 → 🟢
- **يعدل:**
  - `apps/api/Modules/Organization/Features/ListSupervisoryRelationships/Http/...` يضمن إرجاع `source_unit_id, target_unit_id, relationship_type, capability_codes, start_at, end_at`
  - `apps/web/src/features/authorization/AuthorizationAdmin.tsx:120,303` يضيف `case 'supervisory':` يعرض الجدول بأعمدة فعلية
  - `apps/web/src/shell/navigation.tsx` يضيف entry `supervisory` تحت group `organization-workforce`
- **يضيف:** `AuthorizationAdmin.test.tsx` حالة supervisory
- **فحص:** `php artisan test --filter=Supervisory` + `npm test -- navigation`
- **معايير الإنجاز:** Supervisory يظهر في الـSidebar + الجدول يعرض أعمدة حقيقية

### W1.5 — Reports + Dashboards: scope + freshness + capabilities
- **المالك:** Sol → `worker` (تعقيد متوسط)
- **الأثر:** 🔴 → 🟢
- **ينشئ:**
  - `apps/api/Modules/Reporting/Http/GetReportController.php` middleware `reporting.list`
  - `apps/api/Modules/Reporting/Http/GetDashboardController.php` middleware `reporting.dashboard` و 404 لـid غير موجود
- **يعدل:**
  - `apps/api/Modules/Reporting/Features/GetAuthorizedDashboard/Handler/...` يضيف `updated_at`, `period`, `source` للـprojection
  - `apps/api/Modules/Reporting/Features/RunAuthorizedReport/Handler/...` يقبل `scope_id` ويعيد `items[]` ضمن شكل type-safe
  - `apps/api/Modules/Reporting/Http/CreateReportExportController.php` يضمن توليد `download_url` صحيح
- **يعدل (الواجهة):**
  - `apps/web/src/features/r1/R1Screens.tsx:602-627` يفصل `listDashboards` عن `listReports` ويضع كل واحدة في `try` مستقل
  - `apps/web/src/features/r1/R1Screens.tsx:741-750` يستبدل dashboard-kpi بـ MetricTile
  - `apps/web/src/app/AppWorkspace.tsx:534-543` يمرّر `principal.effectiveScope?.scopeId` لـReportsScreen/DashboardsScreen
  - `apps/web/src/features/reporting/DashboardsScreen.tsx:102-119` يقرأ `status` (لا `state`)، ويعرض freshness من `updated_at`
- **يضيف:** اختبار للـhandlers الجدد + DashboardScreen يستقبل updatedAt
- **فحص:** تقارير متعددة + dashboards فارغة + dashboards غير موجودة
- **معايير الإنجاز:** كل KPIs مع period/source/updatedAt؛ dashboard مفقود = 404 مع empty-state

### W1.6 — Search يقبل filters + total
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/api/Modules/Search/Http/SearchController.php:29-39` يقبل `source_type`, `status`, `cursor`, `limit`
  - `apps/api/Modules/Search/Features/SearchAccessibleRecords/Handler/...:46-53` يعيد `{ items, total, next_cursor }` ويستخدم `source_type` (لا `resource_type`)
- **يعدل (الواجهة):**
  - `apps/web/src/features/r1/R1Screens.tsx:507-510,551-567` يستخدم `source_type` + ترجمة عربية
  - `apps/web/src/features/r1/R1Screens.tsx:579-582` يعرض `total` المستلم
  - `apps/web/src/features/r1/R1Screens.tsx:494-498` يخزن q/filters/page في URL params
- **يضيف:** SearchScreen.test.tsx مع fixture حقيقي
- **فحص:** filter `source_type=work_record` يرجع نتائج، `total` صحيح
- **معايير الإنجاز:** البحث يعمل مع فلاتر حقيقية + deep-link قابل للمشاركة

### W1.7 — Tasks + Workflow Steps يعرضان `allowed_actions`
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/api/app/Http/Controllers/Api/TaskController.php:31-38` يضمّ `allowed_actions` للـprojection
  - `apps/api/Modules/Workflow/UI/Http/WorkflowApiGateway.php` يضمّ `allowed_actions: approve|reject|return|reassign|escalate` (موجود بالفعل لكن مفقود في الواجهة)
- **يعدل (الواجهة):**
  - `apps/web/src/features/r1/R1Screens.tsx:277-286` يحذف hardcoded buttons، يستخدم `allowed_actions.includes('complete')`
  - `apps/web/src/features/r1/R1Screens.tsx:281-313` يحوّل "Open" إلى `<Link>` (router) لـ`/tasks/:id`
  - `apps/web/src/features/tasks/TaskDetail.tsx` يعرض allowed_actions كقائمة أزرار منطقية
  - `apps/web/src/features/workflow/ApprovalInbox.tsx:137-139` يضيف reassign/escalate/return buttons
- **فحص:** `php artisan test --filter=TaskTest` + `npm test -- TasksScreen TaskDetail ApprovalInbox`
- **معايير الإنجاز:** UI يطابق الـbackend، لا أزرار مزيفة

---

## Wave 2 — دورة حياة الإجراء (Procedures)

### W2.1 — مصدر واحد للحالة (Lifecycle State Canonicalization)
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 (أساس)
- **يعدل:**
  - `apps/api/Modules/Workflow/Domain/DecisionPolicy.php` يضيف `lifecycle_state` enum واحد: `draft | pending_review | approved | published | retired`
  - يدمج `definition_state`, `review_state`, `approval_status` في هذا الحقل مع migration
  - `docs/contracts/api/openapi.yaml` يحدّث operations-office enum (`approved` يعود للظهور)
- **يضيف:** اختبار Domain يثبت round-trip
- **فحص:** `php artisan test --filter=DecisionPolicy`
- **معايير الإنجاز:** كل المسارات اللاحقة في W2 تستعمل `lifecycle_state` فقط

### W2.2 — ProcedureAuthoring يحفظ المسودات
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟡
- **يعدل:**
  - `apps/web/src/features/workflow/ProcedureAuthoring.tsx:201-209,319-520` يستدعي `updateWorkflowVersionDraft` لكل edit
  - `:227-231` يحذف `'submit' as unknown as WorkflowAction` ويستبدله بـ `submitWorkflowVersionForReview` (المُخطّط في OpenAPI لكن غير منشور)
  - **الواجهة الخلفية:** ينشر endpoint `POST /api/v1/workflow/versions/{id}/submit-review` (كان في OpenAPI فقط)
  - `:128-138` يستخدم `validateDraft` مشترك مع `DecisionPolicyValidator`
  - `:489-502` يضيف تأكيد+undo للحذف
  - `:437-488` يربط error per-field بدل global
- **يضيف:** اختبار `ProcedureAuthoring.test.tsx` لاختبار save/restore بعد refresh + invalid submit
- **فحص:** تعديل خطوة → refresh → التعديلات محفوظة
- **معايير الإنجاز:** Drafts قابلة للحفظ + submit عبر endpoint صحيح + undo للحذف

### W2.3 — ProcedureOfficeReview يربط approve/return/publish عبر العملاء المولّدين
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟡
- **ينشئ:** `apps/api/Modules/Workflow/Http/OperationsOfficeVersionReviewController.php` endpoints approve/return/publish/audit
- **يعدل:**
  - `apps/web/src/features/workflow/ProcedureOfficeReview.tsx:148-215` يستدعي العملاء المولّدين مع typed payload (`graph_hash_observed`, reason)
  - `:184-202` يحذف swallow-404-fake-success
  - `:282-322` يعرض graph diff + assignment resolution + audit history
  - bootstrap eligibility تحسب من server (`office_membership.active`)
- **يضيف:** `ProcedureOfficeReview.test.tsx` بـ 4 سيناريوهات: approve success, 409 conflict, 412 stale, hash mismatch
- **فحص:** `php artisan test --filter=OperationsOffice` + `npm test -- ProcedureOfficeReview`
- **معايير الإنجاز:** approve/return/publish لا تفترق، 412 تظهر كـstale، hash mismatch يرسم field error

### W2.4 — ProcedureGuide يعتمد server-published endpoint
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟡
- **ينشئ:** `apps/api/Modules/Workflow/Features/ListPublishedProcedures/...` endpoint مفلتر audience-scoped، gated على `work_definition.read`
- **يعدل:**
  - `apps/web/src/features/workflow/ProcedureGuide.tsx:74-87` يستدعي `listPublishedProcedures` (لا `listWorkflowDefinitions`)
  - `:84-93` يختار latest published version فقط (لا multiple)
  - `:134-168` يستبدل `<a className="primary-button">` بـ `<Button>` ويربط بـ`/procedures/:id/submit` حقيقي
- **يضيف:** ProcedureGuide.test.tsx يُغطّي: empty, single published, multiple versions, deep link, false-submit
- **فحص:** فتح `/procedures` يعمل بدون capability `workflow.read`
- **معايير الإنجاز:** قائمة نُشرت بدون N+1 + deep-link يعرض procedure + submit ينقل لنموذج حقيقي

### W2.5 — قاموس الحالات يُغطّي جميع القيم
- **المالك:** Sol → `spark-medium`
- **يعدل:** `apps/web/src/features/workflow/workflow-copy.ts:273-285,463-475` يضيف `cancelled, rejected, returned, completed` ويفصل إلى namespace واحد `workflowState()`
- **فحص:** `npm test -- workflow-copy`
- **معايير الإنجاز:** لا تُعرض قيم خام في أي مكان

---

## Wave 3 — ترحيل الـShell والـLogin للبدائيات الموحدة

### W3.1 — LoginScreen يستخدم Field/Button
- **المالك:** Sol → `worker`
- **الأثر:** 🟡 → 🟢
- **يعدل:**
  - `apps/web/src/app/LoginScreen.tsx:118-166` يستبدل `<input>/<button>` بـ`<Field>/<Button>`
  - `:85-87, 188` يستبدل `slice(0,2)` بـ`<span aria-hidden="true">AR</span>` ويضع الـbutton مع tooltip
  - ينقل أنماط login إلى `apps/web/src/app/LoginScreen.css` بدلاً من `styles/base.css`
- **يضيف:** `LoginScreen.test.tsx` حالة locale toggle
- **فحص:** `npm test -- LoginScreen`
- **معايير الإنجاز:** aria-describedby يربط help+error

### W3.2 — AppShell: حذف blur، 44px، Drawer للإشعارات، ⌘K
- **المالك:** Sol → `luna-high` (تعقيد عالي، نطاق محدود)
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/web/src/app/AppShell.css:494-496` يحذف `backdrop-filter: blur(12px)`
  - `:483` `--header-control-size: 40px` → `44px`
  - `:27-58` يحذف radial-gradients + blob pseudo-elements
  - `:30-32, 605` يستبدل hex الخام بـtokens
  - `:235-245, 647-663` يستخدم `--shadow-float` / `--shadow-dialog` من tokens.css
  - `apps/web/src/app/AppShell.tsx:17-475` يستبدل `<button>` خام بـ`<Button variant="ghost">` للـsidebar toggle و language toggle
  - `:454-456` يربط document keydown listener بـ`Ctrl/⌘+K` لتركيز حقل البحث
  - `:476-512` user menu يضيف focus trap + Escape close + focus restore
  - `:312-345` يستخدم `<Drawer open onClose>` للـnotifications
  - `:472` يستبدل `Math.min(unreadNotifications, 9)` بـ"9+" فعلية
  - `:236-237` يصدر numeral واحدة (Western) وaria-label متطابق
  - `:495` user menu escape + outside click handler
- **يضيف:** `AppShell.test.tsx` يُغطّي ⌘K + focus trap + locale toggle
- **فحص:** `npm test -- AppShell navigation`
- **معايير الإنجاز:** جميع اختبارات accessibility تمر على الـShell

### W3.3 — Locale toggle ينقل لـcopy.ts
- **المالك:** Sol → `spark-medium`
- **يعدل:**
  - `apps/web/src/app/copy.ts` يضيف `switchLanguageCode: { ar: 'AR', en: 'EN' }`
  - `apps/web/src/app/AppShell.tsx:459-461` و`apps/web/src/app/LoginScreen.tsx:85-87` يستخدمان `text[locale].switchLanguageCode`
- **فحص:** `npm test -- copy`
- **معايير الإنجاز:** لا قيم "ال"/"En" في أي مكان

### W3.4 — حذف Field/Select المحلي
- **المالك:** Sol → `spark-medium`
- **يستهدف:** `TemporaryAssignments.tsx:287-291`, `ImportReview.tsx:207,444`, `IdentityAccounts.tsx:437-445`, `DocumentsWorkspace.tsx`
- **يعدل:** يستبدل كل local Field/Select بـ`import { Field, Select } from '../../ui'`
- **يضيف:** اختبار لكل شاشة بـ aria-describedby
- **فحص:** `npm test -- TemporaryAssignments ImportReview IdentityAccounts DocumentsWorkspace`
- **معايير الإنجاز:** grep لا يجد `function Field` خارج `ui/Field.tsx`

---

## Wave 4 — التنقل الصحيح داخل SPA + بوابات القدرات

> كل خطوة تستبدل `window.location` أو `<a href>` بـrouter push.

### W4.1 — استبدال window.location
- **المالك:** Sol → `spark-medium` (موازي على كل الملفات)
- **الأثر:** 🟡 → 🟢
- **يعدل:**
  - `apps/web/src/features/workflow/MyRequests.tsx:87` ← `navigate(/my-requests/${id})`
  - `apps/web/src/features/workflow/MyRequestDetail.tsx:19` ← نفسه
  - `apps/web/src/features/workflow/ApprovalDetail.tsx:117-119` ← نفسه
  - `apps/web/src/features/workflow/ApprovalInbox.tsx:127` ← `<Link>`
  - `apps/web/src/features/tasks/TaskDetail.tsx:20` ← يستقبل `navigate` كـprop من AppWorkspace
  - `apps/web/src/features/authorization/AccessScopesScreen.tsx:83` ← يقبل `navigate` ويمرر من AppWorkspace
  - `apps/web/src/app/AppWorkspace.tsx` يمرّر `navigate` لجميع الشاشات المذكورة
- **فحص:** ESLint rule جديدة تمنع `window.location` خارج `LoginScreen.tsx`
- **معايير الإنجاز:** grep -r "window.location" apps/web/src/features apps/web/src/app يظهر فقط LoginScreen

### W4.2 — Tasks screen ينقل لـ/tasks/:id
- **المالك:** Sol → `spark-medium`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/web/src/features/r1/R1Screens.tsx:281-313` يستبدل `<article>` بـ`<Link to={\`/tasks/${item.id}\`}>`
  - يحذف `selected` state المحلي
- **يضيف:** اختبار TasksScreen يستدعي `navigate('/tasks/...')` عند click
- **فحص:** `npm test -- TasksScreen`
- **معايير الإنجاز:** Tasks detail يحمّل بـnavigation، لا state transfer

### W4.3 — Supervisory في الـSidebar
- **المالك:** Sol → `spark-medium`
- **يعدل:** `apps/web/src/shell/navigation.tsx:118-122` يضيف entry supervisory تحت `organization-workforce`
- **يضيف:** `navigation.test.tsx` حالة supervisory visibility
- **فحص:** sidebar يحتوي على Supervisory لمن لديه `organization.unit.read`
- **معايير الإنجاز:** Supervisory يظهر/يختفي مع capability

### W4.4 — Server authorization لـ/dashboards/{id}
- **المالك:** Sol → `worker`
- **يعدل:** `apps/api/Modules/Reporting/Http/GetDashboardController.php` يضيف middleware `reporting.dashboard`
- **فحص:** `curl /api/v1/dashboards/{id}` بدون capability يرجع 403
- **معايير الإنجاز:** dashboards detail محمي على الخادم

---

## Wave 5 — موديول Authorization موحّد

### W5.1 — RolesCapabilitiesWorkspace يستعمل ItemTable موحد
- **المالك:** Sol → `worker`
- **الأثر:** 🟡 → 🟢
- **يعدل:**
  - `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx:79-86` يستبدل `<ul>` بـ`<ItemTable>` من AuthorizationAdmin
  - `:68` يستبدل `t.title.split(' ')[0]` بـcopy keys `rolesTab, capabilitiesTab`
  - `:74` يحذف `<Panel title="403">` ويستعمل `EmptyState` للـdenied
  - `:82-83` يضيف عمود `classification` للـcapabilities
- **يضيف:** `RolesCapabilitiesWorkspace.test.tsx` يُغطّي denied, empty, multilingual
- **فحص:** `npm test -- RolesCapabilitiesWorkspace`
- **معايير الإنجاز:** نفس شكل الجدول عبر Authorization module

### W5.2 — AuthorizationAdmin: per-resource column definitions
- **المالك:** Sol → `worker`
- **الأثر:** 🟡 → 🟢
- **ينشئ:** `apps/web/src/features/authorization/admin-resource-config.ts` يصدّر per-resource column definitions + Edit panel shape
- **يعدل:**
  - `apps/web/src/features/authorization/AuthorizationAdmin.tsx:118-121` يقرأ columns من الـconfig
  - `:229-286` يقرأ Edit fields من الـconfig (يحذف branch `{ name }` للـclassification-policies)
  - `:256-260` يحذف empty patch
  - `:301` `stateFromError` يمتد ليشمل notFound/conflict/stale
  - `:170,220` يحذف `policy_document` للـrole-assignments/delegations
  - `:123-138` ينقل scope/status options إلى `authLabels[locale]`
- **يضيف:** `AuthorizationAdmin.test.tsx` لكل resource حالة
- **فحص:** `npm test -- AuthorizationAdmin`
- **معايير الإنجاز:** كل resource له شكل خاص، لا حقول فارغة، 404 يختلف عن 403

### W5.3 — Delegations: capability_codes + end required
- **المالك:** Sol → `worker`
- **يعدل:**
  - `apps/web/src/features/authorization/AuthorizationAdmin.tsx:204-208` يضيف `<Select multiple>` لـ`capability_codes`
  - `:218` `end` يصير `required` للـdelegations
- **فحص:** submit delegation بـcapability_codes صالحة ينجح
- **معايير الإنجاز:** 422 من الخادم بسبب payload ناقص يظهر كـfield error

### W5.4 — Classification-policies + Field-access-templates: editors حقيقيون
- **المالك:** Sol → `luna-high` (تعقيد متوسط)
- **الأثر:** 🔴 → 🟢
- **ينشئ:**
  - `apps/web/src/features/authorization/ClassificationPolicyEditor.tsx` (textarea مع JSON schema validation)
  - `apps/web/src/features/authorization/FieldAccessTemplateEditor.tsx` (field→state mapping matrix)
- **يعدل:** `AuthorizationAdmin.tsx` يستدعي editors حسب resource
- **يضيف:** field-access-templates لـGOVERNED_RESOURCES (`AuthorizationAdmin.tsx:98`)
- **فحص:** classification-policy بحقل policy_document يُحفظ ويُعاد
- **معايير الإنجاز:** يمكن إنشاء وتحرير كلا الموردين

### W5.5 — Authorization copy ينقل لـcopy.ts
- **المالك:** Sol → `spark-medium`
- **يعدل:**
  - `apps/web/src/app/copy.ts` يضيف namespace `authorization` مع roles/capabilities/policies/templates/scope/status labels
  - يحذف `rolesCapabilitiesCopy`, `authLabels`, `accessContextLabels` المحلية
- **فحص:** grep لا يجد definitions محلية
- **معايير الإنجاز:** copy واحد لـAuthorization

---

## Wave 6 — موديول Reporting + DashboardChart

### W6.1 — DashboardChart + Chart primitives
- **المالك:** Sol → `luna-high` (تنفيذ ECharts خلف wrapper)
- **الأثر:** 🔴 → 🟢
- **ينشئ:**
  - `apps/web/src/charts/DashboardChart.tsx` يستخدم `echarts/core` + `SVGRenderer` فقط
  - يستورد أنواع: `BarChart, LineChart, PieChart, ScatterChart` وأجزاءها الانتقائية
  - يفعّل `aria.show` + decal + textual summary
- **يعدل:** `apps/web/package.json` يضيف `echarts` بالنسخة المثبتة في lockfile
- **يضيف:** `DashboardChart.test.tsx` يجرب empty + ready + stale
- **فحص:** `npm test -- DashboardChart` + bundle analyzer لـ`apps/web/dist/assets/`
- **معايير الإنجاز:** `import('echarts/core')` فقط، لا full bundle

### W6.2 — DashboardsScreen يستعمل DashboardChart + freshness
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/web/src/features/reporting/DashboardsScreen.tsx:114-119` يستبدل `<dl>` بـ`<DashboardChart>` + `<table>` للمكافئ النصي
  - `:102-106` يقرأ `status` (لا `state`)، يضيف variant badge
  - `:114-119` يستخدم `MetricTile` للـtotal
  - `apps/web/src/features/reporting/PrincipalDashboards.tsx:90-105` يستبدل `dashboard-kpi` بـ`<MetricTile>`
- **يضيف:** `DashboardsScreen.test.tsx` يُغطّي state/empty/404/RTL
- **فحص:** dashboard مفقود → 404 + empty-state؛ dashboard فارغ → 0 مع period/source
- **معايير الإنجاز:** كل dashboard له chart + textual summary + freshness

### W6.3 — ReportsScreen: per-screen-aware table + export
- **الملك:** Sol → `worker`
- **الأثر:** 🔴 → 🟡
- **يعدل:**
  - `apps/web/src/features/r1/R1Screens.tsx:602-627` يفصل list loading
  - `:726-765` يستبدل entity table بـschema-aware columns (`title, source_type, source_id, status`)
  - `:741-750` يستعمل MetricTile بـperiod/source
  - `:681-724` polling يحترم server-reported `status` (queued → available → downloaded)
  - `apps/web/src/app/AppWorkspace.tsx:534-543` يمرّر scope
- **يضيف:** `ReportsScreen.test.tsx`
- **فحص:** download URL يظهر بعد export متاح
- **معايير الإنجاز:** report ينقّل لـexport قابل للتنزيل

### W6.4 — SearchScreen مع URL state
- **المالك:** Sol → `spark-medium`
- **الأثر:** 🔴 → 🟡
- **يعدل:** `apps/web/src/features/r1/R1Screens.tsx:489-587` يقرأ `q, source_type, status, cursor` من URL ويكتب back
- **يضيف:** SearchScreen.test.tsx
- **فحص:** `/search?q=foo&source_type=work_record` يعيد البحث السابق
- **معايير الإنجاز:** deep-link قابل للمشاركة

---

## Wave 7 — Notifications + Personal Security

### W7.1 — Notifications: drawer CSS + source links + cursor preserved
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **ينشئ:** `apps/api/Modules/Notifications/Features/CountMyUnreadNotifications/Http/...Controller.php` يرجع العدد الإجمالي
- **يعدل:**
  - `apps/web/src/app/AppShell.css:712-864` يضيف `.notifications-dialog*` كامل + responsive overlay + scroll containment
  - `apps/web/src/app/AppShell.tsx:312-345` يحفظ focusable elements بعد التحميل بـMutationObserver
  - `apps/web/src/app/AppWorkspace.tsx:715-739` يحفظ cursor بعد فشل load-more
  - `apps/web/src/app/NotificationList.tsx` يعرض source links (يفحص capability أولاً)
  - `:28-30` يربط retry callback
  - `:34-66` يحوّل `aria-live` من `<ul>` إلى `<li>` فقط
- **يضيف:** NotificationList.test.tsx + AppShell.drawer.test.tsx
- **فحص:** load-more fail → retry متاح، drawer يركز العناصر الجديدة
- **معايير الإنجاز:** Notifications drawer + route يعملان بنفس السلوك + retry

### W7.2 — Personal Security: 401 نوعان + sign out after success
- **المالك:** Sol → `worker`
- **الأثر:** 🟡 → 🟢
- **يعدل:**
  - `apps/web/src/api/http.ts:195-199,241-245` يضيف context-aware 401: إذا الـpath `identity/password` وresponse code 401 → `ApiError(401, 'invalid_current_password')` بدلاً من تسجيل خروج
  - `apps/web/src/features/identity/PersonalSecurity.tsx:71-79` يميّز الـ401 عن باقي الأخطاء ويرسم per-field guidance
  - `:71-79` بعد success يستدعي `clearSessionMetadata` ويذهب لـ`/login`
  - `:97-117` يربط aria-describedby + name + minLength + maxLength
- **يضيف:** PersonalSecurity.test.tsx يُغطّي 400, 401 invalid pwd, 401 expired, 422 weak policy, success+signout
- **فحص:** pwd خاطئ → error موضعي؛ pwd ضعيف → per-field guidance؛ success → logout
- **معايير الإنجاز:** لا logout خاطئ بسبب خطأ في الكتابة

---

## Wave 8 — الأدوات الداخلية Coverage + Swagger

### W8.1 — Coverage يولّد من OpenAPI
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **ينشئ:** `apps/web/scripts/generate-coverage.ts` يقرأ OpenAPI يحسب الإحصائيات + module breakdown + gap rows
- **يعدل:**
  - `apps/web/package.json` يضيف script `"coverage:generate"` يُشغّل قبل build
  - `apps/web/src/features/portal/CoverageScreen.tsx` يقرأ `coverage-data.generated.ts`
  - `coverage-data.ts` يصبح ملف مولّد فقط
  - `:60-134` يستبدل `card/stat-grid` بـ`<Panel>+<MetricTile>`
  - `:8-41` ينقل كل البيانات إلى locale-aware
- **يضيف:** `CoverageScreen.test.tsx`
- **فحص:** `npm run coverage:generate` ينتج ملف، `npm test -- Coverage`
- **معايير الإنجاز:** الإحصائيات تتطابق مع OpenAPI الحالي تلقائياً

### W8.2 — SwaggerUiScreen: master contract + same-origin
- **المالك:** Sol → `worker`
- **الأثر:** 🔴 → 🟢
- **يعدل:**
  - `apps/web/src/features/docs/SwaggerUiScreen.tsx:9` يستورد `.orval/cluster-master.openapi.yaml`
  - `apps/web/package.json:16` script يستبدل `cluster.openapi.yaml` بـ master
  - `:24-31` يضيف `SwaggerUIStandalonePreset({...})` مع `tryItOutEnabled: false` افتراضياً، `url: '/api/v1'`
  - `:19-33` يحل محل `state-panel` بـ`<Page>` ويتجاوز عرض Swagger container
- **يضيف:** SwaggerUiScreen.test.tsx + boundary test (لا fetch خارجي)
- **فحص:** chrome devtools network → لا request لـ`api.cluster.example`، Swagger يحاول `/api/v1`
- **معايير الإنجاز:** contract الحديث + same-origin + submit معطّل افتراضياً

### W8.3 — إعلان deps للـSwagger
- **المالك:** Sol → `spark-medium`
- **يعدل:** `apps/web/package.json` يضيف `swagger-ui-react` + `js-yaml` صريحاً
- **فحص:** `npm ci` ينجح من lockfile نظيف
- **معايير الإنجاز:** التثبيت النظيف ينجح

---

## Wave 9 — تغطية الاختبارات

> **كل خطوة تستند على خطوة من موجة سابقة:** لا تختبر شاشة قبل إصلاح المنطق.

### W9.1 — Tasks + TaskDetail tests
- **المالك:** Sol → `spark-medium`
- **يعدل/يضيف:**
  - `apps/web/src/features/tasks/TaskDetail.test.tsx` يستعمل UUIDv7 صحيح، يُغطّي loading/empty/forbidden/error/conflict/stale/success+comments-only-fail
  - `apps/web/src/features/r1/R1Screens.test.tsx` يُضيف describe لـTasksScreen مع حالات (open, closed, navigate to detail)
- **فحص:** `npm test -- TaskDetail TasksScreen`
- **معايير الإنجاز:** كل state له حالة اختبار

### W9.2 — Procedures tests
- **المالك:** Sol → `spark-medium`
- **يعدل/يضيف:**
  - `ProcedureGuide.test.tsx` يُغطّي empty, single published, deep link `/procedures/:id`, `submit` route, multiple versions
  - `ProcedureAuthoring.test.tsx` يُغطّي add/edit/remove/persistence-after-refresh/invalid-submit/undo
  - `ProcedureOfficeReview.test.tsx` يُغطّي approve/return/conflict/stale/hash-mismatch/self-approval
- **فحص:** `npm test -- Procedure*`
- **معايير الإنجاز:** كل عملية lifecycle لها سيناريو اختبار

### W9.3 — Reporting + Search + Swagger + Coverage
- **المالك:** Sol → `spark-medium`
- **يعدل/يضيف:** tests للـD)، ودلة المك.
- **فحص:** `npm test -- ReportsScreen DashboardsScreen SearchScreen SwaggerUiScreen CoverageScreen`
- **معايير الإنجاز:** كل شاشة لها اختبارات state كاملة

### W9.4 — Authorization + Access tests
- **المالك:** Sol → `spark-medium`
- **يعدل/يضيف:** tests لكل مورد من Authorization + AccessScopes + AccessDecision + AccessContext (بعد W1.1-W1.4)
- **فحص:** `npm test -- Authorization* Access*`
- **معايير الإنجاز:** كل مورد له سيناريو happy + error + forbidden + stale

### W9.5 — Personal Security + Notifications tests
- **المالك:** Sol → `spark-medium`
- **يعدل/يضيف:** Personal Security paths كاملة (W7.2) + Notifications drawer focus + retry
- **فحص:** `npm test -- PersonalSecurity NotificationList`
- **معايير الإنجاز:** اختبارات تغطي 400/401/422/success مع signout

---

## Wave 10 — تحسينات P2

### W10.1 — Reduced-motion على كل الصفحات
- **المالك:** Sol → `spark-medium`
- **يعدل:** CSS audits في `ui.css`, `base.css`, `screens.css`, `AppShell.css` + إزالة `clamp()` للعناوين (DESIGN §3 "Fixed Product Scale")
- **فحص:** chrome reduced-motion ⇒ لا حركة
- **معايير الإنجاز:** lighthouse accessibility ≥ 95

### W10.2 — OrganizationBoard memoization + dead-code cleanup
- **المالك:** Sol → `spark-medium`
- **يعدل:**
  - `apps/web/src/features/organization/OrganizationBoard.tsx:1129` يحذف `void ChevronUp`
  - `:620-629` `useMemo` لـ`popupParent` و`popupChildren`
  - `:933-1004` يرفع `UnitDetailsPanel` لمكون منفصل لمنع إعادة رسم كامل الـboard
- **يضيف:** render smoke test
- **فحص:** devtools profiler → لا re-render غير ضروري
- **معايير الإنجاز:** board يظل مستقراً على drag/scroll

### W10.3 — Centralize local copy maps
- **المالك:** Sol → `spark-medium`
- **يستهدف:** `WorkDashboard.tsx:43-56`, `R1Screens.tsx:31-176`, `DocumentsWorkspace.tsx:23-26`, `AppWorkspace.tsx:336-355`
- **يعدل:** ينقلها لـ`app/copy.ts` تحت namespaces
- **فحص:** `grep -r 'const copy'` apps/web/src/features + apps/web/src/app لا يجد إلا `copy.ts`
- **معايير الإنجاز:** نسخة واحدة من كل مفتاح copy

### W10.4 — NewProcedureRequest disabled state
- **المالك:** Sol → `spark-medium`
- **يعدل:**
  - `apps/web/src/features/workflow/NewProcedureRequest.tsx:58-59` يُظهر banner فقط عند الـfocus
  - `:79` يعطل submit بينما `reqApiUpdating`
  - يحذف auto-numbered headings من copy
- **فحص:** submit معطّل دائماً مع banner
- **معايير الإنجاز:** الواجهة تَعِد بما تقدّم

### W10.5 — Day2Workflow: split panels + role switch
- **المالك:** Sol → `worker`
- **يعدل:**
  - `apps/web/src/features/workflow/Day2Workflow.tsx:106-145` يقسم `setup()` لِـ4 لوحات منفصلة (definition, version, request, task) كل واحد له busy/error
  - `:151-182` نفس الشيء لـ`submit()`
  - `:189-192` `role` يتحول بين `status` و`alert` حسب نوع المحتوى
- **يضيف:** rollback path إذا فشلت خطوة وسطى
- **فحص:** فشل خطوة → لوحات أخرى تستمر + rollback handler
- **معايير الإنجاز:** فشل جزئي لا يَعْلَق الشاشة

---

## Matrix التزامن (Lane Matrix)

| الموجة | شاشات متضمنة | الاعتماديات | يمكن تشغيل متوازي؟ |
|---|---|---|---|
| W0 | لا شاشات | لا شيء | نعم (3 خطوات متوازية) |
| W1 | 7 شاشات × واجهة خلفية | W0.2 فقط | نعم (W1.1-W1.7 متوازية) |
| W2 | Procedures + Workflow inbox/detail | W1.7, W0.1 | W2.2 بعد W2.1، W2.4, W2.5 متوازية |
| W3 | Shell + Login | W0.1, W0.2 | نعم (4 خطوات متوازية) |
| W4 | 6 شاشات + Sidebar + API | W3.2 | نعم (W4.1-W4.4 متوازية) |
| W5 | Authorization | W0.1, W1.1-W1.4 | W5.5 آخر، الباقي متوازي |
| W6 | Reporting + Search | W0.1, W1.5, W1.6 | W6.1 أولاً، الباقي بعدها |
| W7 | Notifications + Personal Security | W0.2 | متوازي |
| W8 | Internal tools | لا | W8.1+W8.2 متوازية، W8.3 يتبع |
| W9 | اختبارات | كل الموجات السابقة | نعم (5 مواضيع متوازية) |
| W10 | Polish | W3+ | متوازي |

---

## Strategy of Execution

> هذا مشروع شخص واحد (طارق)؛ الـmulti-agent يأتي من **.kilo/agents** و `.kilo/commands/front-first.md` عند الحاجة. المالك (Sol) ينفّذ كل خطوة بنفسه أو يفوّض العامل الميكانيكي (`spark-medium`) أو العامل المعقد (`worker`/`luna-high`) لِـPR واحد فقط في كل مرة.

### قواعد التفويض

- **استخدم `spark-medium` للميكانيكا الصرفة** (تعديل Field، حذف window.location، نقل copy، memoization)
- **استخدم `worker` للتنفيذ المعتاد** (إضافة Service، تحديث Endpoints، UI شاشة كاملة)
- **استخدم `luna-high` للتعقيد العالي محدود النطاق** (AppShell drawer، DashboardChart ECharts، editors حقيقيون)
- **لا تستخدم Terra** (مخاطرة عالية غير مثبتة)
- **لا تشغّل sol-high** (نفسك)

### Branch/PR Conventions

- فرع واحد لكل خطوة: `fix/audit-w{N}-{slug}` (مثال `fix/audit-w1-1-access-scopes`)
- PR وحيد لكل خطوة، يحوي backend+frontend معاً إذا كانت العقد تغيّرت
- وصف PR يستخدم: `Refs audit: W{N}.{M} — <title>`
- كل PR يجب أن يُمرّر: `php artisan test` (backend) + `npm test` + `npm run build` + `make verify-boundaries`

### Acceptance Gates لكل PR

- [ ] كل اختبارات الـPHP خضراء
- [ ] كل اختبارات الـWeb خضراء (vitest)
- [ ] `npm run build` ينجح بدون تحذيرات
- [ ] `make verify-boundaries` ينجح (لا cross-module joins)
- [ ] `grep -r 'as unknown as Record' apps/web/src` لم يزد
- [ ] لا `window.location` جديد خارج `LoginScreen.tsx`
- [ ] لا `any` جديد في `apps/web/src`
- [ ] لا TODOs/`FIXME` جديدة
- [ ] كل backend endpoint جديد له اختبار في `tests/Feature/`
- [ ] كل UI primitive جديد له اختبار في `*.test.tsx`
- [ ] كل route جديد/معدّل له نسخة عربية وإنجليزية في `copy.ts`
- [ ] locale-aware numerals (`formattingLocale(locale)`) مستخدمة لكل رقم

---

## تقدير الجهد

| الموجة | خطوات | أيام (مطور واحد) |
|---|---|---|
| W0 | 3 | 1.5 |
| W1 | 7 | 5 |
| W2 | 5 | 3 |
| W3 | 4 | 3 |
| W4 | 4 | 2 |
| W5 | 5 | 4 |
| W6 | 4 | 4 |
| W7 | 2 | 2 |
| W8 | 3 | 2 |
| W9 | 5 | 3 |
| W10 | 5 | 2 |
| **المجموع** | **47** | **~31 يوم** |

---

## المخاطر

| المخاطرة | التخفيف |
|---|---|
| تغييرات عقود API قد تكسر مستهلكين آخرين | كل تغيير عقد يكون deprecation-first، يحذف فقط في موجة لاحقة |
| ECharts bundle يكبر | استيراد انتقائي + lazy load + bundle budget في CI |
| تعطيل موجة P1 بسبب أساسيات ناقصة | W0 يجب أن تكتمل قبل W1+ |
| اختبارات الـWeb بطيئة مع نمو التغطية | تشغيل موازي + قاعدة بيانات mock لكل اختبار HTTP |
| استبدال `window.location` يُفقد state الـShell | `navigate()` يحفظ الـscope epoch والـcapability preflight |

---

## ما لا تشمله هذه الخطة

- توسيع الوحدات خارج الـR1-R3 (Settings، Audit، RecordsGovernance، Collaboration، Strategy R2، PortfolioProjects R2، Risk R3، Workspace — كلها في MODULE_RANKS لكنها غير منفّذة)
- E2E Playwright — خطة منفصلة (تستهدف `playwright test w1-3` الذي ينهار في import-time كما هو مسجل في الذاكرة)
- البنية التحتية للنشر (Docker/Caddy/MySQL/Redis) — موجودة ومنفصلة
- ترحيل قاعدة البيانات من SQLite إلى MySQL الإنتاجي

---

## ملاحظات ختامية

- الذاكرة تسجّل أن `worker-loop.sh` يجب أن يسبق `consume`؛ لا تغيير هنا
- موديول `Organization` و `Identity` و `Authorization` و `Workflow` لها حدود واضحة؛ لا cross-module joins مطلوبة
- `docs/plans/active-delivery-status.md` يسجل التقدم الفعلي — تحديثه ليس خطوة منفصلة بل نتيجة للـPRs
- هذه الخطة ليست تعهداً؛ الطارق يقرر الأولوية الفعلية (R1/R2/R3 نشر، أصناف موديولات لاحقة، إلخ)
