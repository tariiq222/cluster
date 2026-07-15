---
phase: 01-w1-1-walking-skeleton
plan: "02"
subsystem: platform-scaffold
tags: [laravel, react, typescript, vite, phpunit, lockfiles, development-source-policy]
requires:
  - phase: 01-01
    provides: "مسموحية المصادر العامة للتطوير، جذرا apps/api وapps/web، وفصل صريح عن قبول الإنتاج."
provides:
  - "Laravel 13 API scaffold محايد للموديولات مع smoke test لنقطة الصحة."
  - "React 19 وTypeScript 6 application محلي الأصول، عربي افتراضياً وRTL."
  - "composer.lock وpackage-lock.json وهدف Make للتحقق من توافق manifests مع ملفات القفل."
affects: [01-03, 01-04, module-boundaries, local-development, production-intake]
tech-stack:
  added: [Laravel 13.20.0, PHPUnit 12.5.31, React 19.2.7, TypeScript 6.0.3, Vite 8.1.4]
  patterns: ["locked development dependency graphs", "Laravel module-neutral bootstrap", "single local-asset React entry point"]
key-files:
  created: [Makefile, apps/api/composer.lock, apps/api/bootstrap/app.php, apps/api/phpunit.xml, apps/web/package-lock.json, apps/web/src/main.tsx, apps/web/src/App.tsx]
  modified: [.planning/phases/01-w1-1-walking-skeleton/01-ENTRY-GATE.md]
key-decisions:
  - "حلت الإصدارات الفعلية من Composer وnpm للتطوير المتصل فقط وسجلت التراخيص وprovenance في سجل البوابة."
  - "أزيل Vite الافتراضي من apps/api لتبقى apps/web واجهة React الوحيدة ولا توجد dependency أمامية غير مقفلة في API."
  - "تبدأ واجهة الويب بالعربية وRTL ولا تستورد document أي font أو script أو asset من CDN."
requirements-completed: []
requirements-tracked: [SEC-R1-009, OPS-R1-012]
coverage:
  - id: D1
    description: "ملفات قفل Laravel وReact وهدف تحقق يعيد فحص manifest/lock من دون إعادة حل graph."
    verification:
      - kind: other
        ref: "make verify-intake"
        status: pass
    human_judgment: false
  - id: D2
    description: "Laravel API bootstrap واختبار health محلي قابل للتشغيل."
    verification:
      - kind: integration
        ref: "make test-api-smoke"
        status: pass
    human_judgment: false
  - id: D3
    description: "React/TypeScript bootstrap محلي الأصول مع build وlint ناجحين."
    verification:
      - kind: other
        ref: "make test-web-smoke && npm --prefix apps/web run lint"
        status: pass
    human_judgment: false
duration: 9min
completed: 2026-07-15
status: complete
---

# Phase 01 Plan 02: Laravel وReact/TypeScript Scaffold Summary

**Laravel 13 API محايد للموديولات وReact 19 عربي افتراضياً مع ملفات قفل قابلة لإعادة الإنتاج وأصول متصفح محلية فقط.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-07-15T20:21:05Z
- **Completed:** 2026-07-15T20:29:37Z
- **Tasks:** 3/3
- **Files modified:** 59

## Accomplishments

- أنشئ `apps/api` كتطبيق Laravel قابل للاختبار، بلا موديولات أعمال أو migrations أو جداول business افتراضية.
- أنشئ `apps/web` كتطبيق React + TypeScript واحد يبدأ بالعربية و`dir="rtl"` ولا يحمل أي أصل من CDN.
- قُفلت dependency graphs في `composer.lock` و`package-lock.json`، ووثقت BOM التطوير الفعلي والتراخيص وprovenance في سجل البوابة.

## Task Commits

1. **Task 1: تثبيت manifests وlockfiles من سجل البوابة** — `cd72340` (chore)
2. **Task 2: إنشاء Bootstrap Laravel واختباراته القابلة للتشغيل** — `0ec8f75` (feat)
3. **Task 3: إنشاء Bootstrap React/TypeScript محلي الأصول** — `85bb96c` (feat)

## Validation

- `make verify-intake` — PASS: Composer manifest/lock وnpm manifest/lock صالحان.
- `make test-api-smoke` — PASS: PHPUnit شغّل اختبارين بنجاح، بما فيه health endpoint.
- `make test-web-smoke` — PASS: TypeScript وVite build نجحا، ورفض الفحص وجود مصدر HTTP(S) في document.
- `npm --prefix apps/web run lint` — PASS.

## Files Created/Modified

- `Makefile` — أهداف تحقق intake وAPI/Web smoke من جذر monorepo.
- `apps/api/composer.json`, `apps/api/composer.lock` — Laravel وPHPUnit development graph المقفل.
- `apps/api/bootstrap/app.php`, `apps/api/phpunit.xml`, `apps/api/tests/TestCase.php` — bootstrap وبيئة اختبار Laravel المحايدة.
- `apps/web/package.json`, `apps/web/package-lock.json` — React/TypeScript/Vite graph المقفل.
- `apps/web/index.html`, `apps/web/tsconfig.json`, `apps/web/src/main.tsx`, `apps/web/src/App.tsx` — نقطة الدخول العربية المحلية للتطبيق الموحد.
- `.planning/phases/01-w1-1-walking-skeleton/01-ENTRY-GATE.md` — BOM الفعلي للتطوير وحدود عدم قبول الإنتاج.

## Decisions Made

- استخدمت المصادر العامة المسموح بها محلياً فقط، وسجلت الإصدارات الدقيقة الناتجة في lockfiles؛ لا يثبت ذلك intake أو mirror أو SBOM أو توقيعاً للإنتاج.
- أزيلت واجهة Vite الافتراضية من Laravel كي تظل `apps/web` تطبيق React الوحيد ولا يبقى dependency أمامي بلا lockfile داخل `apps/api`.
- بقي `App` بلا سلوك أعمال؛ يثبت فقط نقطة الدخول العربية والوصول الدلالي الأساسية للـshell اللاحق.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] أضيفت أهداف التحقق المطلوبة للخطة**
- **Found during:** Task 1.
- **Issue:** لم يكن `Makefile` موجوداً، رغم أن أوامر التحقق الملزمة في الخطة تستدعي `make verify-intake` وsmoke targets.
- **Fix:** أضيف `Makefile` بثلاثة أهداف تتحقق من lockfiles وتشغّل Laravel وReact smoke tests.
- **Files modified:** `Makefile`.
- **Verification:** نجحت الأهداف الثلاثة في التحقق النهائي.
- **Committed in:** `cd72340`.

**2. [Rule 2 - Missing Critical Functionality] سُجل BOM الفعلي وأزيلت dependency أمامية غير مقفلة من API**
- **Found during:** Tasks 1–2.
- **Issue:** سجل البوابة لم يكن يسجل بعد الإصدارات/التراخيص/provenance الناتجة، وكان Laravel template يضيف Vite/npm manifest غير مقفل رغم وجود تطبيق React مستقل.
- **Fix:** سجلت القيم الفعلية في `01-ENTRY-GATE.md`، وأزيلت واجهة Vite الافتراضية وscripts التابعة لها من API مع الإبقاء على Laravel backend فقط.
- **Files modified:** `01-ENTRY-GATE.md`, `apps/api/composer.json`, `apps/api/routes/web.php`, `apps/api/tests/Feature/ExampleTest.php`.
- **Verification:** `make verify-intake` و`make test-api-smoke` نجحا.
- **Committed in:** `0ec8f75`.

**Total deviations:** 2 auto-fixed (1 blocking, 1 missing critical functionality).
**Impact on plan:** التصحيحان لازمان لتشغيل أوامر الخطة ولمنع dependency غير مقفلة أو واجهة ثانية؛ لا يضيفان سلوك أعمال أو ادعاء امتثال إنتاجي.

## Known Stubs

None — `App` intentionally provides only the shell entry point required by this scaffold and no mock data flows to UI rendering.

## Threat Flags

| Flag | File | Description |
|---|---|---|
| threat_flag: network endpoint | `apps/api/bootstrap/app.php` | Laravel registers `/up` لقياس الصحة؛ smoke test يثبت نجاحه فقط ولا يعرض بيانات أعمال. |

## Requirements Status

- لم تُعلَّم `SEC-R1-009` مكتملة: guard imports/DAG/SQL ownership مقرر في Plan 01-03.
- لم تُعلَّم `OPS-R1-012` مكتملة: `apps/api` و`apps/web` حُلّا من مصادر عامة للتطوير المسموح، أما mirrors الداخلية والإثبات المعزول للإنتاج فما زالا محجوبين في D-08 وD-09.

## Issues Encountered

- كانت `.planning/ROADMAP.md` معدلة مسبقاً بتغييرات تنسيق واسعة وعدّاد Phase 1 غير صحيح؛ لم تُحدّث أو تُدرج في commit النهائي حفاظاً على عمل المستخدم غير المرتبط. سُجلت للمتابعة في `deferred-items.md`.

## User Setup Required

None — لا توجد خدمة خارجية أو secrets أو إعداد نشر دائم في هذه الخطة.

## Next Phase Readiness

- يمكن للخطط اللاحقة وضع قوالب الموديولات واختبارات الحدود فوق Laravel scaffold، وبناء unified shell فوق React entry point.
- يبقى قبول الإنتاج، mirrors الداخلية، SBOM، التوقيع، وحيازة المفاتيح بوابات صريحة ولا تمثلها هذه بيئة التطوير.

## Self-Check: PASSED

- تحققت ملفات manifests وlockfiles وbootstrap وSUMMARY على القرص.
- تحققت commits المهام `cd72340` و`0ec8f75` و`85bb96c` في Git.
