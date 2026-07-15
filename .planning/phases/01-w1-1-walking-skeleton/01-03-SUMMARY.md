---
phase: 01-w1-1-walking-skeleton
plan: "03"
subsystem: testing
tags: [phpunit, playwright-specification, architecture-guard, gitlab-ci, make, module-boundaries]
requires:
  - phase: 01-02
    provides: "Laravel وReact scaffolds مع manifests وlockfiles قابلة للتحقق."
provides:
  - "مواصفة قبول حمراء لمسار حسابي منشأتين، تشمل اللغتين والاتجاهين والإشعار والعزل."
  - "حارس PHP يفرض imports المنشورة، ترتيب DAG، وملكية SQL/FK ويمنع Requests."
  - "أهداف Make ومراحل GitLab لاختبار المنتج والحدود وتهيئة CI."
affects: [01-04, 01-05, walking-skeleton, module-boundaries, ci]
tech-stack:
  added: []
  patterns: ["explicit RED acceptance target", "PHP tokenized source inspection", "SQL literal tokenization", "CI configuration self-check"]
key-files:
  created: [apps/api/tests/Feature/WalkingSkeletonE2ETest.php, apps/api/tests/Architecture/ModuleBoundariesTest.php, apps/web/e2e/walking-skeleton.spec.ts, apps/web/src/test/setup.ts]
  modified: [Makefile, .gitlab-ci.yml]
key-decisions:
  - "يبقى اختبار القبول أحمر ويثبت 404 لمسارات غير مبنية، بدلاً من إضافة route أو mock يزوّر نجاح السلوك."
  - "يفحص حارس الحدود PHP عبر token_get_all ثم يحلل SQL literals؛ لا يعتمد على regex شامل للنص أو للتعليقات."
  - "يبقى تشغيل Playwright مؤجلاً حتى اعتماد runner مقفل؛ لا يضيف هذا المخطط dependency غير معتمدة."
requirements-completed: [FR-R1-013, SEC-R1-009]
coverage:
  - id: D1
    description: "مواصفة قبول حسابين لمسار request واللغة/الاتجاه والإشعار والعزل دون كشف metadata."
    requirement: FR-R1-013
    verification:
      - kind: e2e
        ref: "make test-e2e-w1-1-red"
        status: fail
    human_judgment: true
    rationale: "الفشل مقصود قبل تنفيذ المسار، وPlaywright غير معتمد بعد في lockfile؛ لا يمكن اعتماد السلوك حتى يتحول الاختبار إلى GREEN."
  - id: D2
    description: "حارس تلقائي يمنع imports غير المنشورة واتجاه DAG الخاطئ وSQL/FK العابر للملكية وRequests."
    requirement: SEC-R1-009
    verification:
      - kind: integration
        ref: "make verify-boundaries"
        status: pass
    human_judgment: false
  - id: D3
    description: "أهداف Make ومراحل GitLab المحددة قابلة للتحقق محلياً ولا تحذف بوابات الوثائق."
    verification:
      - kind: other
        ref: "make verify-w1-1 && make test-web"
        status: pass
    human_judgment: false
duration: 7min
completed: 2026-07-15
status: complete
---

# Phase 01 Plan 03: اختبار القبول الأحمر وحارس حدود الموديولات Summary

**مواصفة قبول حمراء لمسار request بين منشأتين وحارس معماري محلل يوقف imports وSQL/FK العابرين للملكية ضمن أهداف Make وCI قابلة للتشغيل.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-07-15T20:33:58Z
- **Completed:** 2026-07-15T20:40:49Z
- **Tasks:** 2/2
- **Files modified:** 6

## Accomplishments

- أضيفت تغطية RED للـAPI وbrowser تصف دخول حساب A، إنشاء `request`، القائمة، الإشعار المشتق، والإنكار الموحد لحساب B بلا metadata مسرّبة.
- أضيف `ModuleBoundariesTest` يختبر الشجرة النظيفة وfixtures مخالفة لـDomain import وJOIN/FK وRequests، باستخدام tokenizer فعلي لمصدر PHP وSQL literals.
- وُسعت `Makefile` و`.gitlab-ci.yml` بمراحل `test` و`verify` وأهداف intake وAPI/Web والحدود وتحقق بنية CI.

## Task Commits

1. **Task 1: كتابة اختبار القبول الأحمر للمسار الكامل** — `adc8bd4` (test)
2. **Task 2: تثبيت بوابات الحدود وCI المنتج** — `00583c2` (feat)

## Validation

- `make test-e2e-w1-1-red` — PASS as RED evidence: اختبارا القبول فشلا كما هو مقصود بـ404 من `/api/v1/auth/login`، ثم تحقق الهدف من سبب الفشل.
- `make verify-boundaries` — PASS: 4 اختبارات، بما فيها fixtures الاستيراد وJOIN/FK وRequests المخالفة.
- `make verify-ci-config` — PASS: YAML صالح ويحتوي المراحل والوظائف المطلوبة.
- `make verify-w1-1` — PASS: intake، الحدود، وبنية CI.
- `make test-web` و`make test-web-smoke` — PASS: TypeScript/Vite وoxlint وفحص عدم وجود مصدر خارجي في HTML.
- `make test-api-smoke` — expected RED failure: اختبارا القبول الحمران يمنعان نجاح suite قبل تنفيذ routes؛ اختبارا scaffold الآخران نجحا.
- `./scripts/validate-docs.sh` — FAIL خارج نطاق الخطة بسبب تعليقات JSONC موجودة مسبقاً في `apps/web/tsconfig.app.json` و`apps/web/tsconfig.node.json`.

## Files Created/Modified

- `apps/api/tests/Feature/WalkingSkeletonE2ETest.php` — عقد API الأحمر للحسابين وheaders الارتباط/idempotency والإشعار والإنكار الموحد.
- `apps/web/e2e/walking-skeleton.spec.ts` — مواصفة browser للمسار العربي والإنجليزي والعزل بلا إفشاء.
- `apps/web/src/test/setup.ts` — fixtures معزولة ومحددات لغة/اتجاه مشتركة للمواصفة.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php` — حارس حدود الموديولات مع fixtures سالبة.
- `Makefile` — أهداف الاختبار والحراسة والتحقق الثابتة للمسار.
- `.gitlab-ci.yml` — وظائف product test/verify إلى جانب وظائف الوثائق القائمة.

## Decisions Made

- يحافظ `test-e2e-w1-1-red` على failure اختبار القبول المقصود ويثبت أنه 404 للمسار الناقص؛ لا يمرر test behaviour غير المبني.
- لا تُضاف `@playwright/test` أو config جديد قبل intake مقفل ومعتمد؛ تحتفظ المواصفة بمسار browser القابل للتنفيذ لاحقاً.
- يعامل الحارس imports وSQL executable فقط، ولذلك لا تتسبب التعليقات أو مطابقة نصية عامة في نتائج كاذبة.

## TDD Gate Compliance

- **RED:** `adc8bd4` موجود ومثبت بتشغيل PHPUnit؛ فشل 404 مقصود لأن routes وmigrations وواجهة المسار لم تُبن بعد.
- **GREEN:** غير موجود عمداً في هذه الخطة؛ تنفيذ السلوك الأخضر يقع في خطط لاحقة. لا يُدّعى نجاح acceptance قبل ذلك.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] جعل fixture موديول `Requests` يفحص identifier المحظور أيضاً**
- **Found during:** Task 2.
- **Issue:** كان الحارس يتوقف بعد اكتشاف اسم الموديول غير القانوني، لذلك لم يرصد class `RequestCreated` في fixture نفسه.
- **Fix:** استمر الفحص المحصور للـidentifier داخل `Modules/Requests` مع إبقاء import/SQL rules للموديولات القانونية فقط.
- **Files modified:** `apps/api/tests/Architecture/ModuleBoundariesTest.php`.
- **Verification:** `make verify-boundaries` نجح في 4 اختبارات.
- **Committed in:** `00583c2`.

**2. [Rule 3 - Blocking] توافق تحقق YAML مع Ruby المحلي**
- **Found during:** Task 2.
- **Issue:** Ruby 2.6 المحلي لا يدعم keyword `aliases:` في `YAML.load_file`.
- **Fix:** استخدم target التحقق `YAML.load_file` المتوافق؛ ملف CI لا يستخدم aliases.
- **Files modified:** `Makefile`.
- **Verification:** `make verify-ci-config` نجح محلياً.
- **Committed in:** `00583c2`.

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking).
**Impact on plan:** التصحيحان ضروريان ليختبر الحارس المخالفة المعلنة وليعمل target التحقق على toolchain المحلي؛ لا يضيفان سلوك أعمال.

## Known Stubs

None — لا توجد قيمة placeholder أو mock مسار يعيد نجاحاً؛ اختبار القبول الأحمر يتعمد إثبات غياب routes بدلاً من محاكاتها.

## Deferred Issues

- Runner Playwright/config غير موجودين في dependency graph المقفل؛ تُشغّل المواصفة المتصفح بعد اعتماد intake للتبعية في Wave 0، ولا يسجل هذا الملخص نجاح browser غير منفذ.
- `scripts/validate-docs.sh` يفشل حالياً على تعليقات JSONC الموروثة في TypeScript configs؛ سجلت التفاصيل في `deferred-items.md` ولم تعدلها هذه الخطة.

## User Setup Required

None — لا توجد خدمة خارجية أو secrets أو نشر دائم ضمن الخطة.

## Metadata Commit Scope

- شغّل SDK تحديث تقدم `ROADMAP.md` كما هو مطلوب، لكن الملف كان معدلاً مسبقاً خارج نطاق الخطة؛ لذلك لم يُدرج في metadata commit حفاظاً على تغييرات المستخدم غير المرتبطة. يبقى التحديث في working tree للمراجعة.

## Next Phase Readiness

- يمكن لخطط تنفيذ المسار جعل `WalkingSkeletonE2ETest` وspec المتصفح أخضرين عبر routes والمهاجرات والواجهة الحقيقية فقط.
- يجب أن تبقي موديولات الأعمال الجديدة imports المنشورة وملكية persistence ضمن ما يفرضه `make verify-boundaries`.
- يتطلب تشغيل browser E2E الحقيقي قبول Playwright أو بديل داخلي، مع lockfile وconfig مثبتين قبل اعتماد النتيجة.

## Self-Check: PASSED

- تحققت ملفات الاختبار والحارس وMake وCI وSUMMARY على القرص.
- تحققت commits المهام `adc8bd4` و`00583c2` في Git.
