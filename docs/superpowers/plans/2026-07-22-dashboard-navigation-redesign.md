---
doc_id: PLN-UI-003
title: خطة تنفيذ إعادة توزيع الداشبورد والتنقل حسب عمل المستخدم
type: plans
status: accepted
version: 1.1.0
date: 2026-07-22
owner: التنفيذ التقني
reviewers:
- مسؤول المنتج
- مسؤول هندسة البرمجيات
classification: internal
review_cycle: عند اكتمال كل موجة أو تغير عقد التنقل أو الصلاحيات
sources:
- docs/superpowers/specs/2026-07-22-dashboard-navigation-redesign-design.md
- docs/design-system.md
- docs/plans/approvals-and-requests.md
references:
- docs/data-security/authorization-model.md
- docs/engineering/delivery-workflow.md
- docs/domain/notifications-search-reporting.md
---

# Dashboard and Capability-Adaptive Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** إعادة بناء الداشبورد والقائمة الجانبية وتوزيع الصفحات حول عمل المستخدم، مع إظهار الروابط حسب القدرات والنطاق الفعلي، وفصل الصفحات المستقلة وإلغاء التبويبات المتداخلة.

**Architecture:** يبقى `/` داشبورد موحداً، ويصبح `routes.ts` مصدر المسارات و`navigation.tsx` مصدر تعريف عناصر التنقل. يحمل `PrincipalProvider` القدرات والنطاق من الخادم، لكن API يظل صاحب قرار التفويض النهائي. تعتمد صفحات «بانتظار إجراء مني» و«طلباتي» و«مهامي» على استعلامات خادمية مصفاة، ثم يركب الداشبورد بياناتها من دون جلب شامل أو تصفية أمنية في React.

**Tech Stack:** Laravel modular monolith، PHP 8، MySQL/SQLite للاختبارات، OpenAPI + Redocly + Orval، React 19، TypeScript 6، Vite، Vitest، Testing Library، Playwright، ومكونات `apps/web/src/ui/` الموحدة.

## Global Constraints

- شجرة العمل الحالية غير نظيفة وفيها عمل جارٍ على Workflow وOpenAPI والمسارات وصفحات `ApprovalInbox` و`MyRequests`. يبدأ التنفيذ بمصالحة هذه التغييرات ولا يحذفها أو يكتب فوقها.
- لا ينشأ worktree جديد إلا عند بدء التنفيذ وبعد استخدام `superpowers:using-git-worktrees` والتحقق من مكان التغييرات الجارية. لا يستخدم `git reset` أو `git checkout --`.
- القائمة مبنية على القدرات لتحسين التجربة فقط؛ كل endpoint والتفاصيل والبحث والتقرير والتصدير تعيد تطبيق RBAC + ABAC في الخادم.
- لا تستخدم الواجهة `session.user_id` لتصفية مجموعة شاملة. `me` والنطاق والمالك والمُسند إليه قيود خادمية.
- أي تعديل API يبدأ من `docs/contracts/api/openapi.yaml` ومخططاته، ثم `api:generate`، ثم استهلاك الأنواع المولدة. لا تعدّل `apps/web/src/api/generated/cluster.ts` يدوياً.
- تستخدم الشاشات `Page` و`PageHeader` و`Panel` و`Button` و`Field` و`Select` و`Drawer` و`Feedback` من `apps/web/src/ui/`. إذا نقص سلوك يضاف للمكتبة الموحدة مع اختبارها.
- تبويبان كحد أقصى، ولا يوجد رابط جانبي ثم تبويبات ثم تبويبات فرعية. التفاصيل روابط عميقة وليست تبويبات.
- الحالات المنطبقة إلزامية: loading وempty وdenied وerror وsuccess و409/412 stale-conflict.
- لا تعرض قيمة صفر أثناء التحميل، ولا أرقاماً ثابتة، ولا بطاقة إشعارات مكررة داخل الداشبورد.
- لا يحدث `docs/plans/active-delivery-status.md` إلا بطلب المستخدم.
- خطوات commit أدناه تنفذ فقط إذا فوّض المستخدم إنشاء commits. من دون ذلك تبقى الخطوات كحدود تجميع منطقية للتغييرات.
- لا يشغل الخادم كتوقف بين الموجات؛ التحقق بالمتصفح مرحلة نهائية بعد اكتمال الشرائح.

## Target Route Map

| الموضع | الصفحة | المسار | سياسة الظهور في القائمة |
|---|---|---|---|
| مساحة عملي | الرئيسية | `/` | كل مستخدم موثق |
| مساحة عملي | بانتظار إجراء مني | `/approvals` | أي من `workflow.decide`, `workflow.reassign`, `workflow.escalate` |
| مساحة عملي | طلباتي | `/my-requests` | أي من `workflow.read`, `workflow.list` |
| مساحة عملي | مهامي | `/tasks` | أي من `tasks.read`, `tasks.list` |
| مساحة عملي | الإجراءات والخدمات | `/procedures` | أي من `work_definition.read`, `work_definition.list` |
| مساحة عملي | الوثائق | `/documents` | أي من `documents.read`, `documents.list` |
| إدارة المنشآت والموظفين | المنشآت والهيكل | `/admin/organization` | أي من `organization.facility.read`, `organization.unit.read` |
| إدارة المنشآت والموظفين | الموظفون | `/admin/organization/people` | `organization.person.read` |
| إدارة المنشآت والموظفين | التكليفات المؤقتة | `/admin/organization/temporary-assignments` | `organization.temporary-assignment.read` |
| إدارة المنشآت والموظفين | استيراد البيانات | `/admin/imports/organization` | `organization.import.read` |
| إدارة المنشآت والموظفين | العلاقات الإشرافية | `/admin/relationships/supervisory` | `organization.unit.read` |
| الإجراءات وسير العمل | أنواع الطلبات | `/admin/work-definitions` | أي من `work_definition.read`, `work_definition.list` |
| الإجراءات وسير العمل | مسارات الاعتماد | `/admin/workflow` | أي من `workflow.read`, `workflow.list`, `workflow.manage` |
| الإجراءات وسير العمل | مراجعة ونشر الإجراءات | `/admin/procedures/review` | أي من `workflow.approve`, `work_definition.publish` |
| الحسابات والصلاحيات | الحسابات | `/admin/identity/accounts` | `identity.account.read` |
| الحسابات والصلاحيات | الأدوار والصلاحيات | `/admin/authorization/roles` | أي من `authorization.role.read`, `authorization.capability.read` |
| الحسابات والصلاحيات | إسنادات الأدوار | `/admin/authorization/role-assignments` | `authorization.assignment.read` |
| الحسابات والصلاحيات | نطاقات الوصول | `/admin/authorization/access-scopes` | `authorization.assignment.read` |
| الحسابات والصلاحيات | التفويضات | `/admin/authorization/delegations` | `authorization.delegation.read` |
| الحسابات والصلاحيات | سياسات التصنيف | `/admin/authorization/classification-policies` | `authorization.policy.read` |
| الحسابات والصلاحيات | سياسات الحقول | `/admin/authorization/field-access-templates` | `authorization.policy.read` |
| التقارير والمؤشرات | التقارير | `/reports` | `reporting.list` |
| التقارير والمؤشرات | لوحات المؤشرات | `/dashboards` | `reporting.dashboard` |
| الأدوات الداخلية | فحص قرار الوصول | `/admin/authorization/explain` | `authorization.decision.read` |
| الأدوات الداخلية | التغطية | `/coverage` | `authorization.audit.read` |
| الأدوات الداخلية | مرجع API | `/api-docs` | `authorization.audit.read` |
| قائمة المستخدم | الأمان الشخصي | `/me/security` | كل مستخدم موثق |
| قائمة المستخدم | سياق وصولي | `/me/access` | كل مستخدم موثق |

ملاحظات حاسمة:

- `/admin/organization` وحده يسمح بتبويبي «المنشآت» و«الهيكل».
- `/admin/authorization/roles` يسمح بتبويبي «الأدوار» و«الصلاحيات»، ويبقى `/admin/authorization/capabilities` رابط التبويب الثاني.
- `/admin/authorization/access-scopes` عرض قراءة مستقل فوق إسنادات الأدوار المصفاة، ولا ينشئ مجال صلاحيات جديداً. تعديل النطاق يتم من صفحة إسناد الدور، أما اختيار النطاق الشخصي فيبقى `/me/access` والشريط العلوي.
- `/admin/workflow/day2` يبقى رابط توافق مباشر إلى `Day2Workflow` بلا تبويبات وبلا مدخل في القائمة حتى تنقل كل وظائفه؛ لا يحذف ولا يعاد توجيهه إلى صفحة غير مكافئة.
- التفاصيل الجديدة: `/approvals/:stepId` و`/my-requests/:instanceId` و`/tasks/:taskId`، مع تحقق المعرّفات في parser.

## Lane Matrix

| الموجة | Backend | Contract | Frontend | Security | Verification |
|---|---|---|---|---|---|
| 1. الأساس | لا تغيير مجال | تثبيت Principal الحالي | سجل المسارات والتنقل وPrincipalProvider | إخفاء fail-closed | route/navigation/provider unit |
| 2. مساحة عملي | inbox + ownership queries | Workflow inbox/tracking | approvals/requests/tasks/details | منع التسريب والـN+1 | feature + generated API + unit |
| 3. الداشبورد | إعادة استخدام المصادر المصفاة | لا عقد تجميعي وهمي | dashboard composition | لا خلط نطاقات | dashboard unit + integration |
| 4. الإدارة | لا تغيير إلا نقص مثبت | العقود الحالية | تفكيك Organization/Workflow/Authorization | بوابات قدرات لكل صفحة | workspace unit |
| 5. التقارير والأدوات | العقود الحالية | لا تغيير | dashboards page + internal tools | عدم ظهور الأدوات للعادي | reporting/navigation unit |
| 6. الإغلاق | لا جديد | تطابق OpenAPI | responsive/RTL/LTR/a11y | direct URL negative journeys | build + E2E + browser QA |

## Execution Status — 2026-07-23

اعتمد المستخدم توزيع القائمة حسب مجال العمل، ونُفذت المهام الإحدى عشرة. تمثل مربعات الخطوات التفصيلية أدناه وصف طريقة التنفيذ الأصلية وليست ادعاءً بوجود commits؛ لم يفوض المستخدم commit أو push أو merge، لذلك بقيت خطوات commit اختيارية وغير منفذة.

- [x] Task 1: سجل المسارات وسجل التنقل التكيفي مع الصلاحيات.
- [x] Task 2: PrincipalProvider وحاجز تغيير النطاق وإبطال الاستجابات القديمة.
- [x] Task 3: صندوق اعتماد وطلبات شخصية مصفاة خادمياً، مع cursor وstate وlimit.
- [x] Task 4: قوائم وتفاصيل الاعتماد والطلب والمهمة، وعقد خطوة الاعتماد الصحيح.
- [x] Task 5: تركيب الداشبورد من مصادر مستقلة وبالترتيب المعتمد.
- [x] Task 6: فصل صفحات المنشآت والموظفين والتكليفات والاستيراد.
- [x] Task 7: فصل أنواع الطلبات ومسارات الاعتماد ومراجعة الإجراءات.
- [x] Task 8: فصل الحسابات والأدوار والصلاحيات والنطاقات والسياسات.
- [x] Task 9: فصل التقارير ولوحات المؤشرات والأدوات الداخلية.
- [x] Task 10: responsive وRTL/LTR والوصولية واختبارات AppShell المباشرة.
- [x] Task 11: رحلات E2E للشخصيات والنطاق والتفاصيل والقرارات والروابط المباشرة.

### Fresh verification evidence

آخر تحقق: `2026-07-23 03:03:04 +03`.

| البوابة | النتيجة |
|---|---|
| `npm --prefix apps/web run test:unit` | exit 0 — 53 files، 295 tests |
| `npm --prefix apps/web run build` | exit 0 — production build |
| `npm --prefix apps/web run lint` | exit 0 — تحذيرات Fast Refresh قديمة فقط، بلا أخطاء |
| `npm --prefix apps/web run api:check` | exit 0 — العقد صالح والعميل المولد مطابق؛ تحذيرات Redocly المعروفة فقط |
| `composer test` | exit 0 — 482 tests، 477 passed، 5 skipped، 3923 assertions |
| `composer lint` و`composer analyse` | exit 0 — Pint نظيف وPHPStan صفر أخطاء |
| `python3 scripts/inventory-routes.py --check` | exit 0 — 119 routes |
| `make verify-boundaries` | exit 0 — 4 tests، 6 assertions |
| `./infra/dev/run-approvals-e2e.sh` | exit 0 — 22 Playwright journeys |
| `./infra/dev/run-w1-3-e2e.sh` | exit 0 — W1.3 security journey |
| Browser QA | desktop، collapsed group icons، 200% reflow، 320px، RTL، LTR، drawer focus، no horizontal overflow |

المخرجات البصرية محفوظة في `artifacts/dashboard-navigation-qa-*.png` و`artifacts/sidebar-work-groups-*.png`، ومنها `artifacts/sidebar-work-groups-collapsed.png`.

أغلق التحقق الأخير فجوات `waiting` و`running`، ونصوص تغذية راجعة النطاق، وأيقونات المجموعات المطوية، وأسطح `approve/reject/return/reassign/escalate` حسب `allowed_actions` مع قفل الخطوة.

بحث scaffolding لم يجد `TO[D]O` أو`FIX[M]E` أو mock دائم. بقيت أسماء `RequestDashboard` و`ProcessWorkspace` و`AccessWorkspace` في ملفات التوافق واختباراتها فقط؛ لا يستوردها `AppWorkspace` ولا تستخدمها مسارات المنتج الجديدة، وحذفها خارج نطاق هذا التنفيذ لأنه يكسر أسطح الاختبار القديمة.

---

### Task 1: Reconcile In-Flight Work and Freeze the Route Registry

**Files:**

- Modify: `apps/web/src/shell/routes.ts`
- Modify: `apps/web/src/shell/routes.test.ts`
- Create: `apps/web/src/shell/navigation.tsx`
- Create: `apps/web/src/shell/navigation.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.navigation.test.tsx`
- Preserve and reconcile: `apps/web/src/app/AppWorkspace.tsx`, `docs/contracts/api/openapi.yaml`, `apps/web/src/features/workflow/ApprovalInbox.tsx`, `apps/web/src/features/workflow/MyRequests.tsx`

**Consumes:** `AppRoute`, `pathFromRoute`, `routeFromPath`, capability codes returned by `GET /me`.

**Produces:** one exhaustive route registry; one navigation registry with placement and `anyOf` capability policies; exact compatibility behavior for old links.

- [ ] قبل أي تعديل، شغّل `git status --short` و`git diff --check` وراجع diffs للملفات المحفوظة أعلاه. سجّل في ملاحظات التنفيذ أي كتلة تخص عملاً جارياً آخر، ولا تستبدلها.
- [ ] شغّل خط الأساس المستهدف:

  ```bash
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/app/AppWorkspace.navigation.test.tsx src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/MyRequests.test.tsx
  ```

  المتوقع في الحالة الحالية: فشل تحليل `/approvals` و`/my-requests` و`/procedures/new` أو عدم اتساق اختبارات القائمة مع `shellNavigation`. لا تصلح الاختبار بحذف التوقعات.

- [ ] أضف اختبارات route parsing قبل التنفيذ، بما فيها التفاصيل ولوحة المؤشرات ونطاقات الوصول والتوافق القديم:

  ```ts
  it('round-trips the direct work and administration routes', () => {
    const stepId = '01980f50-5f0d-7000-8000-000000000101'
    const instanceId = '01980f50-5f0d-7000-8000-000000000102'
    const taskId = '01980f50-5f0d-7000-8000-000000000103'
    expect(routeFromPath('/approvals')).toEqual({ name: 'approval-inbox' })
    expect(routeFromPath(`/approvals/${stepId}`)).toEqual({ name: 'approval-detail', stepId })
    expect(routeFromPath(`/my-requests/${instanceId}`)).toEqual({ name: 'my-request-detail', instanceId })
    expect(routeFromPath(`/tasks/${taskId}`)).toEqual({ name: 'task-detail', taskId })
    expect(routeFromPath('/dashboards')).toEqual({ name: 'dashboards' })
    expect(routeFromPath('/admin/authorization/access-scopes')).toEqual({ name: 'access-scopes' })
    expect(pathFromRoute({ name: 'dashboards' })).toBe('/dashboards')
    expect(routeFromPath('/admin/workflow/day2')).toEqual({ name: 'workflow-day2' })
  })
  ```

- [ ] أعد تصنيف `ROUTE_WORKSPACE` بحيث لا يجمع صفحات مستقلة. القيم الوحيدة غير `null` تكون لتبويبي المنشأة/الهيكل وتبويبي الأدوار/الصلاحيات. صفحات approvals وrequests وprocedures وبقية الإدارة يجب أن تبقى مستقلة في active state.
- [ ] أنشئ `navigation.tsx` بعقد صريح لا يعتمد على switch داخل `AppWorkspace.tsx`:

  ```tsx
  export type NavigationPolicy =
    | { kind: 'authenticated' }
    | { kind: 'anyOf'; capabilities: readonly string[] }

  export type NavigationEntry = {
    key: string
    route: AppRoute
    group:
      | 'my-work'
      | 'organization-workforce'
      | 'processes-workflow'
      | 'accounts-access'
      | 'reports-insights'
      | 'internal'
    labelKey: NavigationLabelKey
    icon: LucideIcon
    policy: NavigationPolicy
  }

  export function isNavigationEntryVisible(
    entry: NavigationEntry,
    capabilities: readonly string[] | null,
  ): boolean {
    if (entry.policy.kind === 'authenticated') return true
    return capabilities !== null
      && entry.policy.capabilities.some((code) => capabilities.includes(code))
  }
  ```

- [ ] عرّف كل عنصر من جدول Target Route Map مرة واحدة في `NAVIGATION_ENTRIES`. لا تضع `/me/*` فيه؛ عرّف `USER_MENU_ENTRIES` منفصلاً. احذف المجموعة إن أصبحت بلا عناصر.
- [ ] أضف اختبارات فشل آمن وتوزيع المجموعات:

  ```tsx
  it('hides gated and empty groups while principal capabilities are unresolved', () => {
    const groups = buildNavigationGroups({ route: { name: 'list' }, locale: 'ar', capabilities: null, navigate: vi.fn() })
    expect(groups.map((group) => group.key)).toEqual(['my-work'])
    expect(groups.flatMap((group) => group.items.map((item) => item.path))).toEqual(['/'])
  })

  it('puts documents in My Work and tools in Internal Tools', () => {
    const paths = buildNavigationGroups({
      route: { name: 'list' }, locale: 'ar',
      capabilities: ['documents.read', 'authorization.audit.read'], navigate: vi.fn(),
    })
    expect(paths.find((group) => group.key === 'my-work')?.items.map((item) => item.path)).toContain('/documents')
    expect(paths.find((group) => group.key === 'internal')?.items.map((item) => item.path)).toEqual(['/coverage', '/api-docs'])
  })
  ```

- [ ] شغّل الاختبارات المستهدفة وتأكد من خضرتها:

  ```bash
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/AppWorkspace.navigation.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/shell/routes.ts apps/web/src/shell/routes.test.ts apps/web/src/shell/navigation.tsx apps/web/src/shell/navigation.test.tsx apps/web/src/app/AppWorkspace.navigation.test.tsx && git commit -m "feat(web): define capability adaptive navigation registry"`

### Task 2: Load Principal Capabilities and Effective Scope Once

**Files:**

- Create: `apps/web/src/app/principal-context.tsx`
- Create: `apps/web/src/app/principal-context.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/app/AppShell.css`
- Modify: `apps/web/src/features/authorization/AccessContext.tsx`
- Modify: `apps/web/src/api/r1.ts`

**Consumes:** `GET /me`, `GET /me/scopes`, `PUT /me/scope`, session CSRF token.

**Produces:** `PrincipalSnapshot` with fail-closed capabilities, effective scope, a `revision` that invalidates scope-bound reads, and one shared scope selector.

- [ ] أضف اختبار provider أولاً:

  ```tsx
  it('exposes capabilities and increments revision after a scope change', async () => {
    getMyAccessContextMock.mockResolvedValue(principal({ capabilities: ['tasks.read'] }))
    listMyAccessScopesMock.mockResolvedValue(scopeSnapshot('facility', FACILITY_A, 3))
    selectMyAccessScopeMock.mockResolvedValue(scopeSnapshot('unit', UNIT_A, 4))
    render(<PrincipalProvider session={session}><Probe /></PrincipalProvider>)
    expect(await screen.findByText('tasks.read')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'select-unit' }))
    expect(await screen.findByText('revision:1')).toBeTruthy()
  })
  ```

- [ ] طبّق العقد التالي في `principal-context.tsx`:

  ```ts
  export type PrincipalSnapshot = {
    state: 'loading' | 'ready' | 'denied' | 'error'
    capabilities: readonly string[] | null
    effectiveScope: ScopeOptionView | null
    availableScopes: readonly ScopeOptionView[]
    revision: number
    refresh: () => Promise<void>
    selectScope: (scopeType: ScopeSelectionUpdate['scope_type'], scopeId: string) => Promise<void>
  }
  ```

  أثناء `loading/error/denied` تبقى `capabilities = null`. لا تستعمل أدواراً مسماة مثل manager/admin لتقرير الظهور.

- [ ] اجعل provider ينفذ `getMyAccessContext` و`listMyAccessScopes` مرة لكل جلسة. عند تغيير النطاق: طبق `PUT /me/scope` مع ETag، حدّث النطاق، صفّر البيانات القديمة، ثم زد `revision`.
- [ ] انقل تطبيع القدرات والنطاق إلى helpers مشتركة يستخدمها `AccessContext` بدلاً من نسخة ثانية. أضف `capabilities` إلى `PrincipalView` لكي تعرض صفحة «سياق وصولي» ما أعاده الخادم بلا قرار محلي.
- [ ] اربط `AppWorkspace` بـ`usePrincipal()`، وابنِ القائمة بواسطة `buildNavigationGroups`. لا تبن القائمة قبل وصول القدرات المقيدة.
- [ ] أضف محدد النطاق إلى الشريط العلوي باستخدام `Select`. لا يظهر عندما يوجد نطاق واحد. عند 412 اعرض حالة stale وأعد تحميل قائمة النطاقات قبل السماح بمحاولة ثانية.
- [ ] اجعل قائمة المستخدم تحتوي فقط على الأمان الشخصي، سياق وصولي، اللغة، وتسجيل الخروج. لا تكرر `/me/access` في القائمة الجانبية.
- [ ] أضف اختباراً يثبت أن تغيير النطاق يعيد بناء القائمة ولا يترك اسم النطاق القديم أو بياناته:

  ```tsx
  expect(onScopeRevision).toHaveBeenLastCalledWith(1)
  expect(screen.getByRole('combobox', { name: 'النطاق الحالي' })).toHaveValue(`unit:${UNIT_A}`)
  ```

- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/app/principal-context.test.tsx src/features/authorization/AccessContext.test.tsx src/app/AppWorkspace.navigation.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/app/principal-context.tsx apps/web/src/app/principal-context.test.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.css apps/web/src/features/authorization/AccessContext.tsx apps/web/src/api/r1.ts && git commit -m "feat(web): load principal capabilities and effective scope"`

### Task 3: Complete Server-Filtered Approval Inbox and Request Tracking Contracts

**Files:**

- Modify: `docs/contracts/api/openapi.yaml`
- Create: `apps/api/Modules/Workflow/Features/ListApprovalInbox/Query/ListApprovalInbox.php`
- Create: `apps/api/Modules/Workflow/Features/GetVisibleWorkflowInstance/Query/GetVisibleWorkflowInstance.php`
- Create: `apps/api/Modules/Workflow/Tests/ListApprovalInboxTest.php`
- Modify: `apps/api/app/Http/Controllers/Api/WorkflowController.php`
- Modify: `apps/api/routes/web.php`
- Create: `apps/api/tests/Feature/WorkflowPersonalQueuesHttpTest.php`
- Regenerate: `apps/web/src/api/generated/cluster.ts`
- Modify: `apps/web/src/features/workflow/workflow-api.ts`
- Create: `apps/web/src/features/workflow/workflow-api.test.ts`

**Consumes:** trusted principal from identity middleware, Workflow-owned `workflow_instances` and `workflow_step_instances`, current `workflow.read` and `workflow.decide` decisions.

**Produces:** `GET /workflow/steps?assignee=me`, owner-filtered `GET /workflow/instances`, protected `GET /workflow/instances/{id}`, generated typed client.

- [ ] اكتب اختبار HTTP الفاشل قبل controller:

  ```php
  public function test_inbox_returns_only_current_principal_steps(): void
  {
      $this->seedInstanceWithStep(self::USER_A, self::USER_A, 'active');
      $this->seedInstanceWithStep(self::USER_B, self::USER_B, 'active');

      $response = $this->asUser(self::USER_A)
          ->getJson('/api/v1/workflow/steps?assignee=me&state=active', $this->headers())
          ->assertOk();

      $this->assertCount(1, $response->json('items'));
      $this->assertSame(self::USER_A, $response->json('items.0.assignee_user_id'));
  }

  public function test_instance_detail_does_not_disclose_another_users_request(): void
  {
      $instance = $this->seedInstanceWithStep(self::USER_B, self::USER_B, 'active');
      $this->asUser(self::USER_A)
          ->getJson('/api/v1/workflow/instances/'.$instance, $this->headers())
          ->assertNotFound();
  }
  ```

- [ ] أضف اختبارات 401، 403، `state=all`، cursor، وأن المالك يستطيع قراءة مثيله والمُسند إليه يستطيع قراءة الخطوة المرتبطة فقط. لا تعيد أسماء أو IDs في مشكلة 403/404.
- [ ] صحح OpenAPI ليستخدم projection حقيقياً لا `Entity` عاماً:

  ```yaml
  WorkflowInboxItem:
    type: object
    additionalProperties: false
    required: [step_id, workflow_instance_id, source_type, source_id, state, assignee_user_id, created_at, lock_version, allowed_actions]
    properties:
      step_id: { $ref: '#/components/schemas/UUIDv7' }
      workflow_instance_id: { $ref: '#/components/schemas/UUIDv7' }
      source_type: { type: string }
      source_id: { type: string }
      state: { type: string, enum: [waiting, active, completed, rejected, returned, cancelled] }
      assignee_user_id: { $ref: '#/components/schemas/UUIDv7' }
      created_at: { $ref: '#/components/schemas/UtcDateTime' }
      lock_version: { type: integer, minimum: 1 }
      allowed_actions:
        type: array
        uniqueItems: true
        items: { type: string, enum: [approve, reject, return, reassign, escalate] }
  ```

  لا تضف `subject` أو `due_at` إن لم يملك Workflow هذه البيانات. تعرض الواجهة `source_type/source_id` أو رابط المورد المصرح به بدلاً من join عبر موديول آخر.

- [ ] نفذ `ListApprovalInbox` داخل موديول Workflow. يسمح `assignee=me` فقط للمستخدم العادي، ويقبل `assignee_user_id` فقط بعد قرار `workflow.approve` المناسب. يطبق `state` وcursor قبل الإرجاع.
- [ ] أضف `Route::get('workflow/steps', [WorkflowController::class, 'steps'])` إلى مجموعة الجلسة ذات القراءة.
- [ ] اجعل `showInstance` يستخدم `GetVisibleWorkflowInstance`: المالك أو مُسند إليه خطوة في المثيل فقط. أي عرض أوسع لمكتب العمليات يجب أن يمر بقرار `workflow.approve` ونطاق صريح، لا بمجرد معرفة ID.
- [ ] أبق `GET /workflow/instances` مصفى بـ`started_by_user_id` في SQL وأضف اختباره؛ لا تعتمد على فلتر React الموجود حالياً.
- [ ] شغّل من `apps/api`:

  ```bash
  php artisan test Modules/Workflow/Tests/ListApprovalInboxTest.php tests/Feature/WorkflowPersonalQueuesHttpTest.php
  ```

  المتوقع بعد التنفيذ: أخضر، ولا query تعيد بيانات USER_B إلى USER_A.

- [ ] حدّث العميل المولد فقط بالأوامر:

  ```bash
  npm --prefix apps/web run api:lint
  npm --prefix apps/web run api:generate
  npm --prefix apps/web run api:check
  ```

- [ ] اجعل `workflow-api.ts` wrapper رقيقاً:

  ```ts
  export async function listMyApprovalSteps(token: string, state: WorkflowInboxState = 'active') {
    return unwrap(await generated.listWorkflowStepsInbox(
      { assignee: 'me', state, limit: 50 }, requestInit(token),
    ))
  }

  export async function listMyWorkflowInstances(token: string) {
    return unwrap(await generated.listWorkflowInstances({ limit: 50 }, requestInit(token)))
  }
  ```

- [ ] أضف اختبار wrapper يثبت query parameters وأنه لا يرسل `assignee_user_id` من المتصفح.
- [ ] شغّل `make verify-boundaries` لأن Query classes وحدود Workflow تغيرت.
- [ ] إذا كان commit مفوضاً: `git add docs/contracts/api/openapi.yaml apps/api/Modules/Workflow/Features apps/api/Modules/Workflow/Tests/ListApprovalInboxTest.php apps/api/app/Http/Controllers/Api/WorkflowController.php apps/api/routes/web.php apps/api/tests/Feature/WorkflowPersonalQueuesHttpTest.php apps/web/src/api/generated/cluster.ts apps/web/src/features/workflow/workflow-api.ts apps/web/src/features/workflow/workflow-api.test.ts && git commit -m "feat(workflow): expose server filtered personal queues"`

### Task 4: Finish My Work Pages and Their Deep Links

**Files:**

- Modify: `apps/web/src/features/workflow/ApprovalInbox.tsx`
- Modify: `apps/web/src/features/workflow/ApprovalInbox.test.tsx`
- Modify: `apps/web/src/features/workflow/MyRequests.tsx`
- Modify: `apps/web/src/features/workflow/MyRequests.test.tsx`
- Create: `apps/web/src/features/workflow/ApprovalDetail.tsx`
- Create: `apps/web/src/features/workflow/ApprovalDetail.test.tsx`
- Create: `apps/web/src/features/workflow/MyRequestDetail.tsx`
- Create: `apps/web/src/features/workflow/MyRequestDetail.test.tsx`
- Create: `apps/web/src/features/tasks/TaskDetail.tsx`
- Create: `apps/web/src/features/tasks/TaskDetail.test.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/features/workflow/workflow-copy.ts`

**Consumes:** typed personal queue wrappers from Task 3 and existing `GET /tasks`/`GET /tasks/{id}`.

**Produces:** direct list/detail journeys for approvals, requests, and tasks; complete state handling; no client-side identity filtering.

- [ ] استبدل اختبار ApprovalInbox الحالي باختبار يرفض النمط القديم:

  ```tsx
  it('loads the server-filtered inbox without listing every workflow instance', async () => {
    listMyApprovalStepsMock.mockResolvedValue({ items: [inboxItem()], next_cursor: null })
    render(<ApprovalInbox locale="ar" session={session} onOpen={onOpen} />)
    expect(await screen.findByText('سجل العمل · WR-17')).toBeTruthy()
    expect(listMyApprovalStepsMock).toHaveBeenCalledWith('test-token', 'active')
    expect(listWorkflowInstancesMock).not.toHaveBeenCalled()
  })
  ```

- [ ] احذف `Promise.all(getWorkflowInstance)` وشرط `step.assignee_user_id === session.user_id`. ارسم العناصر المصفاة مباشرة، واعرض الأفعال من `allowed_actions` فقط.
- [ ] في `MyRequests` احذف `.filter(instance.started_by_user_id === session.user_id)`. الاختبار يجب أن يثبت أن كل عنصر يعيده endpoint يظهر لأن العقد نفسه شخصي.
- [ ] اجعل صفوف القوائم روابط دائمة إلى `ApprovalDetail` و`MyRequestDetail` و`TaskDetail`. لا تستخدم Drawer كتفاصيل كاملة؛ يبقى Drawer لفعل قصير مثل سبب الرفض فقط.
- [ ] نفذ الحالات لكل صفحة: skeleton، empty، 403 denied، error/retry، success aria-live، 409 conflict، 412 stale/reload.
- [ ] استخدم `allowed_actions` في `ApprovalDetail`، وبيانات task المصرح بها في `TaskDetail`. إذا عاد 404 لا تعرض ID أو مالكاً من state قديم.
- [ ] اربط routes الجديدة في `AppWorkspace.renderRoute()` من دون workspace جامع أو تبويبات.
- [ ] مرر `principal.revision` إلى loaders أو اجعله dependency لكي يعاد التحميل بعد تغيير النطاق.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/ApprovalDetail.test.tsx src/features/workflow/MyRequests.test.tsx src/features/workflow/MyRequestDetail.test.tsx src/features/tasks/TaskDetail.test.tsx src/shell/routes.test.ts
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/workflow apps/web/src/features/tasks apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx && git commit -m "feat(web): complete personal work queues and details"`

### Task 5: Compose the Adaptive Home Dashboard from Authorized Sources

**Files:**

- Create: `apps/web/src/features/dashboard/dashboard-model.ts`
- Create: `apps/web/src/features/dashboard/dashboard-model.test.ts`
- Create: `apps/web/src/features/dashboard/WorkDashboard.tsx`
- Create: `apps/web/src/features/dashboard/WorkDashboard.test.tsx`
- Create: `apps/web/src/features/dashboard/WorkDashboard.css`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.tsx`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.test.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Delete after replacement: `apps/web/src/features/requests/RequestDashboard.tsx`
- Delete after replacement: `apps/web/src/features/requests/RequestDashboard.css`

**Consumes:** `listMyApprovalSteps`, `listMyWorkflowInstances`, `listTasks`, `listDashboards/getDashboard`, effective scope and principal revision.

**Produces:** one `/` dashboard with four real KPIs, priority queue, request tracking, today summary, and optional scoped manager indicators.

- [ ] اكتب model tests أولاً:

  ```ts
  it('derives the four KPIs from the same authorized collections', () => {
    expect(buildDashboardSummary({ inbox, tasks, requests, now: NOW })).toEqual({
      awaitingDecision: 2,
      dueToday: 1,
      overdue: 1,
      activeRequests: 3,
    })
  })

  it('does not turn loading or failed sources into zero', () => {
    expect(metricValue({ state: 'loading' })).toBeNull()
    expect(metricValue({ state: 'error' })).toBeNull()
  })
  ```

- [ ] عرّف `DashboardData` بمصادر مستقلة لا boolean عام واحد:

  ```ts
  type Loadable<T> =
    | { state: 'loading' }
    | { state: 'ready'; data: T }
    | { state: 'denied' }
    | { state: 'error' }

  export type DashboardData = {
    inbox: Loadable<WorkflowInboxItem[]>
    tasks: Loadable<Task[]>
    requests: Loadable<WorkflowInstanceTracking[]>
    dashboards: Loadable<DashboardCard[]>
  }
  ```

- [ ] حمّل المصادر بالتوازي مع فصل أخطائها. فشل `dashboards` أو 403 طبيعي لموظف ولا يسقط inbox/tasks/requests.
- [ ] ابن `WorkDashboard` بالترتيب المعتمد: رأس مضغوط بالنطاق، شريط أولوية يفتح `/approvals`، أربع KPIs، «ما يحتاجك الآن»، «متابعة طلباتي»، «اليوم»، ثم `PrincipalDashboards` إن كان `reporting.dashboard` متاحاً.
- [ ] استخدم 2×2 للمؤشرات على الجوال وعموداً واحداً للمحتوى. أزل hero الكبير وبطاقتي status/notifications وquick actions المكررة.
- [ ] لا تمرر `notifications` إلى الداشبورد الجديد. يبقى عدد الإشعارات والـdrawer في `AppShell` فقط.
- [ ] عدل `PrincipalDashboards` ليستقبل `scopeId` و`revision`، ويستخدم `Promise.allSettled`. زر التفاصيل يفتح `/dashboards` لا `/reports`.
- [ ] أضف اختبار component يثبت أن 403 في dashboards لا يخفي «ما يحتاجك الآن»، وأن CTA شريط الأولوية يفتح approvals لا نموذج إنشاء سجل.
- [ ] بدّل route `/` إلى `WorkDashboard`، ثم احذف `RequestDashboard` وCSS بعد التأكد من عدم وجود imports عبر `rg -n "RequestDashboard" apps/web/src`.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/dashboard/dashboard-model.test.ts src/features/dashboard/WorkDashboard.test.tsx src/features/reporting/PrincipalDashboards.test.tsx
  npm --prefix apps/web run build
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/dashboard apps/web/src/features/reporting/PrincipalDashboards.tsx apps/web/src/features/reporting/PrincipalDashboards.test.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/features/requests/RequestDashboard.tsx apps/web/src/features/requests/RequestDashboard.css && git commit -m "feat(web): build adaptive authorized home dashboard"`

### Task 6: Split Organization Administration into Direct Pages

**Files:**

- Modify: `apps/web/src/features/organization/OrganizationWorkspace.tsx`
- Create: `apps/web/src/features/organization/OrganizationWorkspace.test.tsx`
- Modify: `apps/web/src/features/organization/PeopleAssignments.tsx`
- Modify: `apps/web/src/features/organization/PeopleAssignments.test.tsx`
- Modify: `apps/web/src/features/organization/TemporaryAssignments.tsx`
- Modify: `apps/web/src/features/imports/ImportReview.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/e2e/shell.spec.ts`

**Consumes:** current organization components and current routes/APIs.

**Produces:** one two-tab organization page plus independent employees, temporary assignments, and import pages.

- [ ] اكتب اختبار بنية الصفحة:

  ```tsx
  it('keeps only facilities and structure as tabs', () => {
    render(<OrganizationWorkspace locale="ar" activeRouteName="organization" navigate={vi.fn()} />)
    const tabs = within(screen.getByRole('navigation', { name: 'أقسام المنشآت والهيكل' }))
    expect(tabs.getAllByRole('link')).toHaveLength(2)
    expect(tabs.queryByRole('link', { name: 'الموظفون' })).toBeNull()
  })
  ```

- [ ] غيّر `OrganizationWorkspaceRoute` إلى `'organization' | 'organization-structure'` فقط، وأبق `OrganizationOverview` و`OrganizationStructure` كتبوبي الصفحة.
- [ ] ارسم `PeopleAssignments` و`TemporaryAssignments` و`ImportReview` مباشرة من `AppWorkspace`، مع `PageHeader` خاص بكل رحلة إذا لم يكن المكون يملكه.
- [ ] حافظ على paths الحالية والروابط العميقة للاستيراد. يجب أن يبقى import job detail يعمل بعد refresh/back/forward.
- [ ] تأكد أن navigation registry يعطي كل صفحة مدخلاً مستقلاً وقدرتها الصحيحة، وأن active state لا يضيء «المنشآت» عند فتح «الموظفين».
- [ ] حدّث اختبار E2E القديم من 5 تبويبات إلى تبويبين، ثم اختبر الانتقال المباشر لبقية الصفحات من القائمة.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/organization/OrganizationWorkspace.test.tsx src/features/organization/OrganizationOverview.test.tsx src/features/organization/OrganizationStructure.test.tsx src/features/organization/PeopleAssignments.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/organization apps/web/src/features/imports/ImportReview.tsx apps/web/src/app/AppWorkspace.tsx apps/web/e2e/shell.spec.ts && git commit -m "refactor(web): split organization administration pages"`

### Task 7: Split Request Types, Approval Paths, and Procedure Review

**Files:**

- Modify: `apps/web/src/features/workflow/ProcessWorkspace.tsx`
- Modify: `apps/web/src/features/workflow/ProcessWorkspace.test.tsx`
- Modify: `apps/web/src/features/workflow/ProcedureAuthoring.tsx`
- Modify: `apps/web/src/features/workflow/ProcedureOfficeReview.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/app/copy.ts`

**Consumes:** current `WorkDefinitionsScreen`, `WorkflowAdminScreen`, `ProcedureAuthoring`, `ProcedureOfficeReview`, and compatibility `Day2Workflow`.

**Produces:** independent request type, approval path, review/publish pages; no three-tab process workspace.

- [ ] غيّر الاختبار أولاً ليؤكد غياب `WorkspaceTabs` من الصفحات المستقلة:

  ```tsx
  it('renders request types as a direct page without process tabs', () => {
    render(<WorkDefinitionsScreen />)
    expect(screen.getByRole('heading', { name: 'أنواع الطلبات' })).toBeTruthy()
    expect(screen.queryByRole('navigation', { name: 'أقسام الإجراءات وسير العمل' })).toBeNull()
  })
  ```

- [ ] اجعل `/admin/work-definitions` يرسم `WorkDefinitionsScreen` مباشرة بعنوان «أنواع الطلبات»، و`/admin/workflow` يرسم `WorkflowAdminScreen` بعنوان «مسارات الاعتماد».
- [ ] اجعل `/admin/procedures/review` صفحة مراجعة ونشر مستقلة. يبقى `/admin/procedures/authoring` رابطاً عميقاً لأداة التأليف إذا احتاجته رحلة نوع الطلب، لكنه لا يظهر كبند مكرر إن كانت «أنواع الطلبات» هي المدخل الأساسي.
- [ ] احتفظ بـ`/admin/workflow/day2` كتوافق مباشر إلى `Day2Workflow` بلا `ProcessWorkspace` وبلا رابط جانبي.
- [ ] احذف استدعاء `ProcessWorkspace` من `AppWorkspace`. احذف الملف نفسه فقط إذا لم يبق مستهلك بعد `rg -n "ProcessWorkspace" apps/web/src`؛ وإلا حوّله wrapper توافق بلا tabs.
- [ ] اختبر القدرات منفصلة: `work_definition.read` لا يظهر مراجعة النشر، و`workflow.approve` لا يظهر حسابات أو Organization.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/workflow/ProcessWorkspace.test.tsx src/features/workflow/ProcedureAuthoring.test.tsx src/features/workflow/ProcedureOfficeReview.test.tsx src/features/r1/R1Screens.test.ts src/shell/navigation.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/workflow apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/app/copy.ts && git commit -m "refactor(web): split workflow administration journeys"`

### Task 8: Flatten Identity and Authorization Administration

**Files:**

- Modify: `apps/web/src/features/authorization/AccessWorkspace.tsx`
- Modify: `apps/web/src/features/authorization/AccessWorkspace.test.tsx`
- Create: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx`
- Create: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx`
- Create: `apps/web/src/features/authorization/AccessScopesScreen.tsx`
- Create: `apps/web/src/features/authorization/AccessScopesScreen.test.tsx`
- Modify: `apps/web/src/features/authorization/AuthorizationAdmin.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`

**Consumes:** `IdentityAccounts`, `AuthorizationAdmin`, `AccessContext`, existing authorization list API.

**Produces:** exactly one two-tab roles/capabilities page; all other authorization resources as direct pages; personal access context in user menu.

- [ ] اكتب اختبار الهيكل قبل التغيير:

  ```tsx
  it('offers exactly two tabs on the roles and capabilities object', () => {
    render(<RolesCapabilitiesWorkspace locale="ar" activeResource="roles" navigate={vi.fn()} />)
    const nav = screen.getByRole('navigation', { name: 'الأدوار والصلاحيات' })
    expect(within(nav).getAllByRole('link')).toHaveLength(2)
    expect(within(nav).queryByRole('link', { name: 'التفويضات' })).toBeNull()
  })
  ```

- [ ] أنشئ `RolesCapabilitiesWorkspace` للتبويبين `roles` و`capabilities` فقط. يطبق كل تبويب قدرته الخاصة، فلا يكشف تبويب capabilities لمستخدم يملك role.read وحدها.
- [ ] ارسم `IdentityAccounts` و`role-assignments` و`delegations` والسياسات والعلاقات الإشرافية مباشرة من `AppWorkspace` بلا primary tabs أو secondary tabs.
- [ ] أنشئ `/admin/authorization/access-scopes` كصفحة قراءة مستقلة تستدعي `listAuthorization('role-assignments')` وتعرض `scope_type`, `scope_id`, المستخدم، الدور، والفترة. الإجراء الوحيد «فتح إسناد الدور»؛ لا تنشئ endpoint إدارة نطاق جديداً.
- [ ] انقل `AccessContext` إلى `/me/access` فقط، و`AccessExplanation` إلى مجموعة الأدوات الداخلية فقط.
- [ ] قلص `AccessWorkspace` إلى wrapper توافق مؤقت أو احذفه إذا لم يبق له import. لا تترك تنقلاً من 4 تبويبات يتبعه 7 تبويبات.
- [ ] اختبر denied/error/empty في `AccessScopesScreen`، واختبر أن صفحات السياسة والتفويض مستقلة في active state.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/authorization/AccessWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/authorization/AccessScopesScreen.test.tsx src/features/authorization/AccessContext.test.tsx src/shell/navigation.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/authorization apps/web/src/app/AppWorkspace.tsx && git commit -m "refactor(web): flatten identity and authorization administration"`

### Task 9: Separate Reports, Dashboards, and Internal Tools

**Files:**

- Create: `apps/web/src/features/reporting/DashboardsScreen.tsx`
- Create: `apps/web/src/features/reporting/DashboardsScreen.test.tsx`
- Modify: `apps/web/src/features/r1/R1Screens.tsx`
- Modify: `apps/web/src/features/reporting/PrincipalDashboards.tsx`
- Modify: `apps/web/src/app/AppWorkspace.tsx`
- Modify: `apps/web/src/shell/navigation.tsx`
- Modify: `apps/web/src/app/copy.ts`

**Consumes:** `GET /reports`, `GET /dashboards`, `GET /dashboards/{id}`, current Coverage and Swagger screens.

**Produces:** direct reports page, direct dashboards page, and bottom internal-tools group hidden from ordinary users.

- [ ] اكتب الاختبارات التالية قبل الربط:

  ```tsx
  it('renders only dashboards returned by the authorized list endpoint', async () => {
    listDashboardsMock.mockResolvedValue({ items: [dashboard('d-1')], total: 1 })
    render(<DashboardsScreen />)
    expect(await screen.findByRole('link', { name: /المعاملات المتأخرة/ })).toBeTruthy()
  })

  it('does not expose internal tools without audit capability', () => {
    expect(pathsFor(['reporting.list'])).not.toContain('/coverage')
    expect(pathsFor(['authorization.audit.read'])).toContain('/api-docs')
  })
  ```

- [ ] أنشئ `/dashboards` قائمة dashboards المصرح بها، وdetail داخل الصفحة أو route `/dashboards/:dashboardId` إذا احتاج رابطاً دائماً. لا تخلطها داخل `ReportsScreen`.
- [ ] اعرض source/freshness/last updated عندما يعيدها العقد. إن لم يعدها العقد، لا تخترع قيمة؛ سجّل الحاجة كتحسين عقد مستقل فقط إذا كانت ضرورية للقبول.
- [ ] انقل Coverage وAPI docs وAccess Explanation إلى مجموعة `internal` في أسفل القائمة. تخفي المجموعة كلها دون `authorization.audit.read`/`authorization.decision.read`.
- [ ] أضف client route guard لصفحات الأدوات يعرض denied عند direct navigation بلا capability، مع إبقاء أي بيانات محمية خلف API كذلك.
- [ ] اربط `PrincipalDashboards` بـ`/dashboards` واختبر أن الموظف بلا dashboards لا يرى مساحة فارغة.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/features/reporting/DashboardsScreen.test.tsx src/features/reporting/PrincipalDashboards.test.tsx src/shell/navigation.test.tsx
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/features/reporting apps/web/src/features/r1/R1Screens.tsx apps/web/src/app/AppWorkspace.tsx apps/web/src/shell/navigation.tsx apps/web/src/app/copy.ts && git commit -m "feat(web): separate reports dashboards and internal tools"`

### Task 10: Finish Shell Density, Responsive Behavior, RTL/LTR, and Accessibility

**Files:**

- Modify: `apps/web/src/app/AppShell.tsx`
- Modify: `apps/web/src/app/AppShell.css`
- Modify: `apps/web/src/ui/ui.css`
- Modify: `apps/web/src/features/dashboard/WorkDashboard.css`
- Create: `apps/web/src/app/AppShell.test.tsx`
- Modify: `apps/web/e2e/shell.spec.ts`

**Consumes:** final navigation groups, user menu entries, scope selector, dashboard structure.

**Produces:** calm operations-room shell, compact dashboard, accessible collapsed sidebar and mobile drawer in both directions.

- [ ] أضف component tests للطي والـtooltips وقائمة المستخدم وscope selector قبل CSS النهائي. تحقق من `aria-current`, `aria-expanded`, أسماء الأزرار، واسترجاع focus.
- [ ] أبق sidebar سطح المكتب 264px والمطوي 68px ما لم يثبت browser QA أن النصوص المعتمدة تحتاج تعديلاً. في الوضع المطوي تعرض العناصر Tooltip قابلاً للوصول باللوحة، لا title بصرياً فقط.
- [ ] أبق mobile drawer حقيقياً: Escape يغلقه، background inert، focus يعود لزر القائمة، ويتغير inline-start مع RTL/LTR.
- [ ] طبّق خصائص CSS المنطقية (`inline-size`, `margin-inline-*`, `inset-inline-*`) واعزل الأرقام/IDs الإنجليزية موضعياً بـ`dir="ltr"`.
- [ ] راجع التباين والتركيز ضد WCAG 2.2 AA، واحترم `prefers-reduced-motion`. لا يحمل اللون معنى الحالة وحده.
- [ ] أضف assertions تمنع horizontal overflow عند 320px وتثبت 2×2 KPI grid ثم عموداً واحداً للمحتوى.
- [ ] شغّل:

  ```bash
  npm --prefix apps/web run test:unit -- src/app/AppShell.test.tsx src/features/dashboard/WorkDashboard.test.tsx
  npm --prefix apps/web run lint
  npm --prefix apps/web run build
  ```

- [ ] إذا كان commit مفوضاً: `git add apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.css apps/web/src/ui/ui.css apps/web/src/features/dashboard/WorkDashboard.css apps/web/src/app/AppShell.test.tsx apps/web/e2e/shell.spec.ts && git commit -m "feat(web): finish responsive accessible application shell"`

### Task 11: Add Persona-Based E2E Journeys and Close the Delivery Gate

**Files:**

- Modify: `apps/web/e2e/shell.spec.ts`
- Create: `apps/web/e2e/capability-navigation.spec.ts`
- Create: `apps/web/e2e/personal-work.spec.ts`
- Modify only if fixture gaps are proven: `apps/api/database/seeders/DevelopmentJourneyAuthorizationSeeder.php`
- Modify only if fixture gaps are proven: `apps/api/app/Console/Commands/SeedW12E2EFixture.php`
- Track checkboxes in: `docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md`

**Consumes:** all previous slices.

**Produces:** evidence for employee, manager, and platform owner journeys across desktop/mobile and RTL/LTR, including negative direct URLs and refresh/history behavior.

- [ ] حدّث shell fixture ليعيد `/api/v1/me` بالـ`capabilities` الصحيحة و`/me/scopes` بنطاق فعلي. لا تجعل route wildcard يعيد 200 لكل شيء لأنه يخفي أخطاء العقود.
- [ ] اكتب matrix E2E:

  | الشخصية | يجب أن ترى | يجب ألا ترى |
  |---|---|---|
  | موظف | الرئيسية، طلباتي، مهامي، الإجراءات، الوثائق | الإدارة، الأدوات الداخلية، مؤشرات المدير |
  | مدير/معتمد | الموظف + بانتظار إجراء مني + مؤشرات نطاقه | أدوات المنصة غير المصرح بها |
  | مالك المنصة | صفحات الإدارة المصرح بها + الأدوات الداخلية | أي رابط لا يملك قدرته الفعلية |

- [ ] أضف رحلة approvals: القائمة لا تعرض خطوة USER_B، القرار الناجح يزيل العنصر، 412 يعرض stale ويعيد التحميل، والـrefresh لا يعيد العنصر المحسوم.
- [ ] أضف رحلة My Requests: تعرض فقط ما بدأه المستخدم، detail يعمل بعد reload، وdirect ID لمستخدم آخر يعطي denied/404 بلا تسريب.
- [ ] أضف رحلة scope change: تغيير النطاق يصفر المحتوى القديم أثناء التحميل، يعيد KPI/list/dashboard، ولا يترك عدادات النطاق السابق.
- [ ] أضف رحلة navigation: كل رابط ظاهر يفتح صفحة غير 403 في fixture الخاص بالشخصية، والمجموعات الفارغة غير موجودة. اختبر back/forward وروابط التفاصيل.
- [ ] نفذ E2E في 1280×800 و320×720، مرة عربية RTL ومرة إنجليزية LTR للرحلات الجوهرية، مع screenshots/traces عند الفشل.
- [ ] شغّل التحقق المتدرج النهائي:

  ```bash
  npm --prefix apps/web run api:check
  npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/shell/routes.capabilities.test.ts src/shell/navigation.test.tsx src/app/principal-context.test.tsx src/features/workflow/ApprovalInbox.test.tsx src/features/workflow/MyRequests.test.tsx src/features/dashboard/WorkDashboard.test.tsx src/features/organization/OrganizationWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/reporting/DashboardsScreen.test.tsx
  npm --prefix apps/web run lint
  npm --prefix apps/web run build
  make verify-boundaries
  npm --prefix apps/web run test:e2e:local -- e2e/shell.spec.ts e2e/capability-navigation.spec.ts e2e/personal-work.spec.ts
  ./scripts/validate-docs.sh
  ```

- [ ] استخدم `superpowers:verification-before-completion`. سجّل لكل أمر timestamp، exit code، والاختبارات المنفذة. لا تقل «مكتمل» إن كانت E2E محجوبة ببيئة؛ قل بدقة ما هو أخضر وما هو محجوب.
- [ ] نفذ browser QA يدوياً بعد الاختبارات: القائمة كاملة/مطوية، drawer، dashboard فوق الطية، scope switch، approvals، direct links، RTL/LTR، keyboard-only، focus، 200% zoom، و320px overflow.
- [ ] شغّل `rg -n "TO[D]O|FIX[M]E|mock permanent|RequestDashboard|ProcessWorkspace|AccessWorkspace" apps/web/src apps/api docs/contracts` وفسر كل تطابق متبقٍ؛ لا تترك scaffold أو mock دائم.
- [ ] راجع تغطية المواصفة بنداً بنداً: لا 3 tabs، لا مستوى ثالث، لا إشعارات مكررة، لا client filtering، لا static KPIs، لا internal tools للمستخدم العادي.
- [ ] اطلب مراجعة مستقلة باستخدام `superpowers:requesting-code-review` على diff كامل، وعالج الملاحظات قبل الإغلاق.
- [ ] إذا كان commit مفوضاً: `git add apps/web/e2e apps/api/database/seeders/DevelopmentJourneyAuthorizationSeeder.php apps/api/app/Console/Commands/SeedW12E2EFixture.php docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md && git commit -m "test: cover adaptive dashboard and navigation journeys"`

## Implementation Order and Stop Conditions

1. Tasks 1–3 متسلسلة إجبارياً لأنها تثبت المسارات والسياق والعقد الأمني.
2. Task 4 تكتمل قبل Task 5 حتى يستخدم الداشبورد المصادر الشخصية الصحيحة.
3. Tasks 6–9 يمكن تجهيز اختبارات ومكونات كل منها باستقلال، لكن تعديل `AppWorkspace.tsx` و`routes.ts` يدمج تسلسلياً بواسطة منفذ واحد.
4. Task 10 بعد ثبات هيكل الصفحات كي لا تعاد كتابة CSS مرتين.
5. Task 11 بوابة الإغلاق الوحيدة.

يوقف التنفيذ ويطلب قرار المستخدم فقط إذا:

- تبين أن التغييرات الجارية على Workflow تعود لمسار آخر لا يمكن دمجه من دون استبدال عمله.
- احتاجت «نطاقات الوصول» مجال إدارة جديداً بدلاً من العرض المستقل فوق إسنادات الأدوار؛ هذا توسع منتج وليس افتراض تنفيذ.
- ثبت أن capability مطلوبة غير موجودة في `CapabilityCatalog` ولا يمكن تمثيلها بأمان بقدرة قائمة.
- تطلبت الروابط القديمة حذف بيانات أو migration غير قابلة للعكس.

## Definition of Done

- الموظف والمدير ومالك المنصة يرون قوائم مختلفة مبنية على قدراتهم، وكل رابط ظاهر يفتح رحلة صالحة لهم.
- الخادم يمنع الرابط المباشر غير المصرح به، وpersonal queues لا تسرب بيانات مستخدم آخر.
- القائمة لا تحتوي «مراجعة المنتج»؛ الوثائق ضمن مساحة العمل والأدوات الداخلية في قسم سفلي مقيد.
- Organization وRoles/Capabilities فقط يملكان تبويبين؛ بقية الوظائف صفحات مستقلة.
- الداشبورد لا يكرر الإشعارات ولا يعرض سجلات عامة كقرارات شخصية، ويستخدم أربعة KPIs حقيقية كحد أقصى.
- تغيير النطاق يعيد كل البيانات المرتبطة به ولا يعرض snapshot قديماً.
- build وtargeted tests وAPI check وmodule boundaries وE2E وdocs validation خضراء بأدلة حديثة.
- تمت مراجعة لوحة المفاتيح والتركيز وRTL/LTR والجوال يدوياً، ولم يوجد overflow أفقي عند 320px.
