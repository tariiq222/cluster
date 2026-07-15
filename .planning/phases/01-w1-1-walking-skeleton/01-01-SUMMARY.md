---
phase: 01-w1-1-walking-skeleton
plan: "01"
subsystem: governance
tags: [supply-chain, development-policy, lockfiles, production-air-gap]
requires: []
provides:
  - "سجل بوابة يجيز المصادر العامة للتطوير ويمنع ادعاء امتثال الإنتاج."
  - "جذرا المنتج apps/api وapps/web ومسار تسجيل lockfiles في Plan 01-02."
affects: [01-02, supply-chain, production-platform]
tech-stack:
  added: []
  patterns: ["development-source policy separate from production air-gap", "lockfile-derived dependency BOM"]
key-files:
  created: [.planning/phases/01-w1-1-walking-skeleton/01-01-SUMMARY.md]
  modified:
    - .planning/phases/01-w1-1-walking-skeleton/01-ENTRY-GATE.md
    - .planning/phases/01-w1-1-walking-skeleton/01-SKELETON.md
key-decisions:
  - "يسمح التطوير العادي المتصل بالإنترنت بمصادر Composer وnpm وOCI العامة."
  - "يبقى الإنتاج معزولاً ويعتمد فقط على artifacts وصور وlockfiles محضّرة داخلياً."
  - "تسجل الإصدارات والتراخيص وprovenance الفعلية بعد إنشاء lockfiles في Plan 01-02، لا بالتخمين."
requirements-completed: []
requirements-tracked: [SEC-R1-001, SEC-R1-011, OPS-R1-006, OPS-R1-007, OPS-R1-008, OPS-R1-011, OPS-R1-012]
coverage:
  - id: D1
    description: "سجل بوابة يحدد سياسة مصادر التطوير وجذور المنتج وحدود الإنتاج."
    verification:
      - kind: other
        ref: "git diff --check وgrep لحقول البوابة"
        status: pass
    human_judgment: false
  - id: D2
    description: "عقد Walking Skeleton يربط الجذور وسياسة التطوير ببوابات الإنتاج المحجوبة."
    verification:
      - kind: other
        ref: "grep لقرار المصادر العامة وPlan 01-02"
        status: pass
    human_judgment: false
duration: 23min
completed: 2026-07-15
status: complete
---

# Phase 01 Plan 01: بوابة الإدخال وقرار مصادر التطوير Summary

**سجل بوابة يجيز مصادر Composer وnpm وOCI العامة للتطوير، ويثبت `apps/api` و`apps/web` مع إبقاء سلسلة توريد وإثباتات الإنتاج معزولة ومحجوبة.**

## Performance

- **Duration:** 23 min
- **Started:** 2026-07-15T19:52:05Z
- **Completed:** 2026-07-15T20:15:20Z
- **Tasks:** 2/2
- **Files modified:** 3

## Accomplishments

- وثق Task 1 سجل D-01 إلى D-10 وحدود أدلة التشغيل الدائم دون الادعاء بأن Compose أو الخادم الأحادي دليل Kubernetes أو HA أو NetworkPolicy.
- طبّق Task 2 قرار المالك: التطوير العادي متصل بالإنترنت، وجذرا المنتج المعتمدان هما `apps/api` و`apps/web`.
- أجّل BOM الدقيق، والتراخيص، وprovenance إلى القيم الفعلية التي تنتجها lockfiles في Plan 01-02، مع بقاء قبول الإنتاج محجوباً.

## Task Commits

1. **Task 1: إنشاء سجل بوابة الإدخال والأدلة غير المتاحة** — `a8fd7c9` (docs)
2. **Task 2: اعتماد intake وtoolchain وجذور monorepo وانحراف المنصة** — `65098a9` (docs)

## Files Created/Modified

- `.planning/phases/01-w1-1-walking-skeleton/01-ENTRY-GATE.md` — سياسة مصدر التطوير، الجذور المعتمدة، ومسار BOM/lockfile وحدود الإنتاج.
- `.planning/phases/01-w1-1-walking-skeleton/01-SKELETON.md` — يعكس الجذور المعتمدة، خطة lockfiles، وفصل تطوير Compose عن الامتثال الدائم.
- `.planning/phases/01-w1-1-walking-skeleton/01-01-SUMMARY.md` — خلاصة التنفيذ والتتبع والبوابات المتبقية.

## Decisions Made

- يسمح للتطوير العادي المتصل بالإنترنت بتنزيل Composer وnpm وصور OCI التطويرية من المصادر العامة.
- لا يسري ذلك على production: يجب أن يبني ويعمل من artifacts وصور وlockfiles داخلية محضّرة، مع intake وSBOM وتوقيع وحيازة مفاتيح قابلة للتدقيق.
- لا تُخترع إصدارات أو تراخيص أو BOM قبل إنشاء lockfiles في Plan 01-02.

## Deviations from Plan

تم استبدال افتراض الخطة بأن التطوير يحتاج مرايا داخلية بقرار المستخدم الملزم. لا يمثل القرار إعفاءً من air gap للإنتاج، وظلت بوابات Kubernetes/GitOps وNetworkPolicy وregistry/signing/key custody وMinIO والتشفير وrestore drill محجوبة صراحة.

### Auto-fixed Issues

**1. [Rule 1 - Bug] حفظ تحديث خارطة الطريق دون إعادة تنسيق بقية الوثيقة**
- **Found during:** تحديث حالة الخطة بعد Task 2.
- **Issue:** أضاف handler `roadmap.update-plan-progress` أسطراً فارغة إلى أقسام مراحل غير مرتبطة.
- **Fix:** أُعيد الملف إلى محتواه السابق ثم طُبق فقط عداد خطة Phase 1 وجدول تقدمه.
- **Files modified:** `.planning/ROADMAP.md`
- **Verification:** `git diff --check` ومراجعة diff المحصور في صفوف Phase 1.

## Requirements Status

لم تُعلَّم المتطلبات `SEC-R1-001` و`SEC-R1-011` و`OPS-R1-006` و`OPS-R1-007` و`OPS-R1-008` و`OPS-R1-011` و`OPS-R1-012` مكتملة: هي أدلة تشغيل وإنتاج دائمة ما زالت محجوبة في سجل البوابة.

## Issues Encountered

- كشف `git diff --check` مسافة لاحقة في السجل المعدل؛ أزيلت قبل التحقق والالتزام.

## User Setup Required

لا شيء لهذا القرار. لا تُضاف credentials أو أسرار أو مواد توقيع إلى المستودع.

## Next Phase Readiness

- يستطيع Plan 01-02 إنشاء manifests وlockfiles تحت `apps/api` و`apps/web` من مصادر التطوير المعتمدة وتسجيل القيم الفعلية الناتجة.
- تبقى أدلة Kubernetes/GitOps وNetworkPolicy وregistry/signing/key custody وMinIO والتشفير واختبار الاستعادة شروطاً حاجبة لأي ادعاء نشر دائم أو قبول متطلبات تشغيل الإنتاج.

## Self-Check: PASSED

- تحققت الملفات الثلاثة المسجلة في الملخص على القرص.
- تحققت التزامات Task 1 (`a8fd7c9`) وTask 2 (`65098a9`) في جميع مراجع Git.
- نجح فحص المسافات اللاحقة وأنماط الـstub للملفات المعدلة.
