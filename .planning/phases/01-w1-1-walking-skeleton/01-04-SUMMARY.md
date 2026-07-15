---
phase: 01-w1-1-walking-skeleton
plan: "04"
subsystem: authorization
tags: [laravel, fixtures, login, session, facility-isolation, authorization, module-boundaries]
requires:
  - phase: 01-03
    provides: "حارس حدود الموديولات وأهداف اختبار Laravel القابلة للتشغيل."
provides:
  - "منشأتان وحسابان ثابتان محصوران ببيئتي local وtesting."
  - "جلسة login تطويرية تحمل principal ومنشأة الحساب دون تسجيل الاعتمادات أو الرموز."
  - "عقد DecideAccess وRecordFacts وقرار fail-closed يثبت عزل المنشأة في الخلفية."
affects: [01-05, walking-skeleton, identity, organization, authorization, work-records]
tech-stack:
  added: []
  patterns: ["development-only fixture migrations", "session-scoped fixture principal", "trusted RecordFacts authorization contract", "fail-closed facility decision"]
key-files:
  created: [apps/api/Modules/Identity/Features/DevelopmentFixtureLogin/Http/DevelopmentFixtureLoginController.php, apps/api/Modules/Authorization/Contracts/DecideAccess.php, apps/api/Modules/Authorization/Contracts/RecordFacts.php, apps/api/Modules/Authorization/Infrastructure/FixtureFacilityDecision.php]
  modified: [apps/api/app/Providers/AppServiceProvider.php, apps/api/routes/web.php, apps/api/phpunit.xml, apps/api/tests/Feature/DevelopmentFixtureLoginTest.php]
key-decisions:
  - "يبقى login fixture محصوراً في local وtesting؛ production يعيد 404 ولا يستخدمه كسياسة اعتماد."
  - "يحمل Identity مرجع facility ثابتاً بلا FK أو query إلى Organization، بينما يتخذ Authorization القرار من RecordFacts فقط."
  - "كل capability غير مسندة أو facts/owner/actor facility مفقودة تنتهي إلى deny قابل للتفسير."
requirements-completed: [SEC-R1-009, FR-R1-013]
coverage:
  - id: D1
    description: "دخول حسابي fixture وإصدار principal جلسة لكل منشأة مع فشل اعتماد عام."
    requirement: FR-R1-013
    verification:
      - kind: integration
        ref: "apps/api/tests/Feature/DevelopmentFixtureLoginTest.php; composer test -- --filter=DevelopmentFixtureLogin"
        status: pass
    human_judgment: false
  - id: D2
    description: "قرار Laravel الخلفي يسمح للمنشأة المطابقة فقط ويرفض mismatch أو facts المفقودة قبل serialization."
    requirement: SEC-R1-009
    verification:
      - kind: unit
        ref: "apps/api/Modules/Authorization/Tests/FixtureFacilityDecisionTest.php; composer test -- --filter=FixtureFacilityDecision"
        status: pass
      - kind: integration
        ref: "make verify-boundaries"
        status: pass
    human_judgment: false
duration: 10min
completed: 2026-07-15
status: complete
---

# Phase 01 Plan 04: Fixtures الدخول وقرار عزل المنشأة Summary

**منشأتان تطويريتان وحسابان ثابتان يفتحان جلسات principals مقيدة بالمنشأة، مع عقد Laravel fail-closed يقرر العزل من RecordFacts دون وصول Authorization إلى جداول الأعمال.**

## Performance

- **Duration:** 10 min
- **Started:** 2026-07-15T20:45:12Z
- **Completed:** 2026-07-15T20:55:11Z
- **Tasks:** 2/2
- **Files modified:** 14

## Accomplishments

- أضيفت fixtures منشأة A وB وحساباتها المحصورة في `local` و`testing` فقط، مع migrations ذات ملكية منفصلة لكل من Organization وIdentity.
- أضيف endpoint `/api/v1/auth/login` التطويري الذي ينشئ session principal بمُعرّف المنشأة، ويرد نفس مشكلة 401 العامة للاعتماد غير الصحيح من دون تسجيل inputs أو رموز.
- أضيفت `RecordFacts` و`DecideAccess` و`FixtureFacilityDecision`؛ تسمح فقط بقدرات submit/read/list للـfacility المطابقة وتفشل مغلقاً لكل facts أو scope غير صالح.

## Task Commits

1. **Task 1: إنشاء fixtures المنشأتين وجلسة الدخول التطويرية** — `8e5f51c` (RED test), `bd5ec05` (GREEN feature)
2. **Task 2: تنفيذ عقد قرار العزل الخلفي** — `c164ddd` (RED test), `81300d1` (GREEN feature)

## Validation

- `composer test -- --filter=DevelopmentFixtureLogin` — PASS: اختباران و13 assertion.
- `composer test -- --filter=FixtureFacilityDecision` — PASS: اختباران و12 assertion.
- `make verify-boundaries` — PASS: أربعة اختبارات لحراسة imports وDAG وSQL/FK.
- `php -l` لجميع ملفات PHP الجديدة — PASS.
- `make test-api -- --filter=…` لا يمرر filter عبر Makefile الحالي؛ target الكامل ما زال يتضمن `WalkingSkeletonE2ETest` الأحمر المقصود من Plan 01-03، لذلك لم يُدّعَ نجاح suite الكاملة قبل Plan 01-05.

## Files Created/Modified

- `apps/api/Modules/Organization/Infrastructure/Fixtures/DevelopmentFacilityFixtures.php` — IDs وبيانات المنشأتين الثابتة.
- `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/CreateDevelopmentFacilitiesTable.php` — جدول وseed تطويري محصور بالبيئة.
- `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateDevelopmentFixtureAccountsTable.php` — حسابان مع password hashes ومراجع facility بلا FK.
- `apps/api/Modules/Identity/Features/DevelopmentFixtureLogin/Http/DevelopmentFixtureLoginController.php` — login تطويري وجلسة principal وفشل اعتماد موحد.
- `apps/api/Modules/Authorization/Contracts/{AccessDecision,DecideAccess,RecordFacts}.php` — DTOs وعقد القرار المنشوران.
- `apps/api/Modules/Authorization/Infrastructure/FixtureFacilityDecision.php` — adapter عزل facility fail-closed.
- `apps/api/Modules/Authorization/Tests/FixtureFacilityDecisionTest.php` و`apps/api/tests/Feature/DevelopmentFixtureLoginTest.php` — تغطية RED/GREEN للسلوكين.

## Decisions Made

- لا يقرأ Identity جدول Organization: يحمل حساب fixture `facility_id` كمرجع ثابت فقط، ولا يوجد FK أو join عابر لملكية الموديولات.
- لا يستورد Authorization WorkRecords ولا يقرأ جداولها؛ المالك المستقبلي يبني `RecordFacts` ثم يستهلك عقد `DecideAccess`.
- لا تتوسع fixtures إلى إدارة منظمة أو هوية أو RBAC/ABAC؛ capabilities الثلاثة هي الحد الأدنى لإثبات مسار W1.1.

## TDD Gate Compliance

- **Task 1 RED:** `8e5f51c` فشل كما يلزم بـ404 قبل route/login، ثم صار GREEN في `bd5ec05`.
- **Task 2 RED:** `c164ddd` فشل لغياب adapter، ثم صار GREEN في `81300d1`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ربط Laravel بمسارات migrations وautoload الخاصة بالموديولات**
- **Found during:** Task 1.
- **Issue:** لا يكتشف Laravel ملفات migration التي تحمل أسماء artifacts المطلوبة تلقائياً، ولا يحمل namespace `Modules` دون PSR-4 mapping.
- **Fix:** أضيف `Modules\\` إلى Composer وربط `AppServiceProvider` بملفي migration صراحةً، مع route slice اللازم للوصول إلى controller.
- **Files modified:** `apps/api/composer.json`, `apps/api/app/Providers/AppServiceProvider.php`, `apps/api/routes/web.php`.
- **Verification:** اختبارات login وmigrations في SQLite تمر.
- **Committed in:** `bd5ec05`.

**2. [Rule 1 - Bug] تهيئة قاعدة اختبار login بمهاجرات fixtures**
- **Found during:** Task 1.
- **Issue:** لم يكن اختبار RED يستخدم `RefreshDatabase`، فظهر نقص الجدول بدلاً من إثبات السلوك المكتمل.
- **Fix:** أضيف `RefreshDatabase` وتحقق session principal إلى اختبار login.
- **Files modified:** `apps/api/tests/Feature/DevelopmentFixtureLoginTest.php`.
- **Verification:** `composer test -- --filter=DevelopmentFixtureLogin` يمر باختبارَي allow/failure.
- **Committed in:** `bd5ec05`.

**3. [Rule 3 - Blocking] تسجيل اختبارات الموديولات في PHPUnit**
- **Found during:** Task 2.
- **Issue:** phpunit.xml كان يكتشف `tests/Unit` و`tests/Feature` فقط، بينما ملف الاختبار المطلوب يملكه Authorization داخل `Modules/Authorization/Tests`.
- **Fix:** أضيف testsuite `Modules` كي يبقى اختبار العقد عند مالكه ويعمل داخل suite العامة.
- **Files modified:** `apps/api/phpunit.xml`.
- **Verification:** `composer test -- --filter=FixtureFacilityDecision` يمر، و`make verify-boundaries` يمر.
- **Committed in:** `81300d1`.

**Total deviations:** 3 auto-fixed (2 blocking, 1 bug).
**Impact on plan:** تعديلات wiring واختبار فقط، لازمة لتشغيل artifacts المخططة من دون توسيع نطاق الهوية أو المنظمة أو authorization policy.

## Known Stubs

None — لا توجد قيم placeholder أو مصادر بيانات وهمية تمنع هدف العزل؛ fixtures ثابتة ومقصودة لهذا المسار التطويري فقط.

## User Setup Required

None — لا توجد خدمة خارجية أو secrets أو إعداد نشر دائم ضمن الخطة.

## Next Phase Readiness

- يمكن لـWorkRecords في Plan 01-05 إنشاء `RecordFacts` موثوقة واستخدام `DecideAccess` قبل serialization أو mutation.
- يبقى مسار walking-skeleton الشامل أحمر عمداً حتى يبني Plan 01-05 WorkRecord وOutbox وNotifications والتحقق الفعلي من bearer/session في boundaries اللاحقة.
- لا يجوز ترقية fixture login إلى production authentication أو إضافة management UI دون خطة اختصاصية لاحقة.

## Metadata Commit Scope

- نفّذ SDK تحديث تقدّم `ROADMAP.md`، لكن الملف كان معدلاً قبل التنفيذ وخارج ملكية الخطة؛ لذلك لا يُدرج في metadata commit حفاظاً على تغييرات المستخدم غير المرتبطة.

## Self-Check: PASSED

- تحققت ملفات fixtures وlogin وعقد Authorization والاختبارات على القرص.
- تحققت commits المهام `8e5f51c`, `bd5ec05`, `c164ddd`, و`81300d1` في Git.
