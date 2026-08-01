# Baseline — 2026-08-01T00:00:20Z

## tsc
exit=0

## lint

> web@0.0.0 lint
> oxlint

src/router.tsx:50:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/ui/index.tsx:314:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/session-context.tsx:28:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/session-context.tsx:34:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/session-context.tsx:38:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/session-context.tsx:42:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/principal-context.tsx:150:17: warning react(only-export-components): Fast refresh only works when a file only exports components. Use a new file to share constants or functions between components.
src/app/AppShell.tsx:44:21: warning react-hooks(exhaustive-deps): React hook useMemo depends on `features`, which changes every render help: Try memoizing this variable with `useRef` or `useCallback`.
src/app/AppShell.tsx:44:7: warning react-hooks(exhaustive-deps): React hook useMemo depends on `capabilities`, which changes every render help: Try memoizing this variable with `useRef` or `useCallback`.
src/features/audit/AuditScreen.tsx:207:19: warning react-hooks(exhaustive-deps): React Hook useEffect has a missing dependency: 'ledgerData.next_cursor' help: Either include it or remove the dependency array.

## unit


 RUN  v4.1.10 /Users/tariq/code/R3/cluster/apps/web


 Test Files  3 passed (3)
      Tests  9 passed (9)
   Start at  03:00:23
   Duration  944ms (transform 56ms, setup 0ms, import 202ms, tests 23ms, environment 664ms)


## e2e list
  walking-skeleton.spec.ts:305:6 › Business Calendar persists create, weekday, exception, and publish through the UI
  walking-skeleton.spec.ts:330:1 › Organization stale-write loser sees 412 feedback and refreshes to the winner value
Total: 52 tests in 14 files

## coverage

قبل الإصلاح كان `coverage.include` يشير إلى `src/api.ts` غير الموجود، فبلغت البوابة `0/0` ومرّت عتبات 100% بلا قياس. بعد توجيهه إلى `src/api/http.ts` و`src/api/session.ts` و`src/i18n.ts`:

| المؤشر | القيمة المقيسة | العتبة المحدّثة (تقريباً لأسفل لأقرب 5) |
|---|---|---|
| Statements | 38.26% (44/115) | 35 |
| Branches | 30.12% (25/83) | 30 |
| Functions | 25.92% (7/27) | 25 |
| Lines | 39.25% (42/107) | 35 |

## e2e بعد إعادة التوجيه

_يملؤها التنفيذ (المهمة ١١)._
