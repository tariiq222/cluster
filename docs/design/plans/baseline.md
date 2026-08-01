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

## bundle

| | الحجم (minified) |
|---|---|
| نهاية خطة الأساس (الشاشة غير المُستوردة موجودة كملف ميت) | 807.19 kB · gzip 228.69 kB |
| بعد المهمة ١ (حذف التبعيات + api-docs) | 807.25 kB · gzip 228.70 kB |
| قبل ترحيل المهمة ٨ (cc1f767؛ بعد مهام ٢–٧) | **1,023.86 kB · gzip 284.54 kB** |
| بعد إنجاز المهمة ٨ (التفكيك والترحيل الكاملان) | **1,071.30 kB · gzip 295.75 kB** |

الثبات مقصود وموثَّق: `ApiDocsScreen` لم يكن مستورَداً أصلاً عند نهاية خطة الأساس (الراوتر الجديد لا يشير إليه)، فلم يكن swagger-ui-react يدخل الحزمة. الربح الفعلي: وزن القفل والتركيب (~1MB+)، وثلاث تبعيات مُعلَنة بلا استخدام، و`recharts` متاح لمهام اللوحات.

القفزة إلى 1,023.86 kB قبل المهمة ٨ ناتجة عن تراكم ترحيل مهام ٢–٧ إلى shadcn/Radix؛ يُصدر Vite تحذير «أكبر من 500 kB» لهذه الشحنة. التقسيم على مستوى المسار (`React.lazy` + `Suspense`) وإعادة القياس مجدولان صراحةً في المهمة ١٤ (خطوة ١ من خطة الترحيل)، ولن يُنفَّذ خلال المهمة ٨. بعد إنجاز المهمة ٨ بلغت الشحنة الرئيسية 1,071.30 kB minified · 295.75 kB gzip، بزيادة +47.44/+11.21 kB عن ما قبل المهمة ٨، ويبقى تحذير «أكبر من 500 kB» قائماً لأن التقسيم على مستوى المسار مجدول في المهمة ١٤ لا في المهمة ٨.

## e2e بعد إعادة التوجيه

شُغِّل كامل رحلة W1.1 (walking-skeleton · login · shell · accounts-permissions) عبر `infra/dev/run-w1-1-e2e.sh` على مكدّس MySQL/Redis/API طازج ومزوَّر:

| | النتيجة |
|---|---|
| هذا الفرع (بعد إعادة توجيه المهمة ١١) | 9 فاشل · 5 ناجح · 1 متخطّى |
| خط الأساس `17a84ac` (نفس السويت عبر worktree) | **مطابق تماماً: 9 فاشل · 5 ناجح · 1 متخطّى** |

الفاشلون التسعة موجودون قبل هذه الخطة أصلاً (توقّعتها خطة التنفيذ: «الاختبارات مكتوبة قبل إعادة بناء الواجهة، فبعضها مكسور احتمالاً»):

- `login.spec.ts` — رسالة انتهاء الجلسة بدل خطأ المحلي عند الإرسال الفارغ
- `walking-skeleton.spec.ts` — استعادة الجلسة بعد فقدان التخزين · بوابة الصلاحيات لشريط المنصة (زر «إنشاء تقويم» لا يظهر: بذرة W1.1 لا تمنح `platform_settings.calendar.manage` ولا تزرع تقويماً) · خاسرا 409 و412
- `accounts-permissions.spec.ts` — التبويبات الثلاثة · استنساخ الأدوار · مفتش القرار · مسار 412

لا يوجد انكسار بسبب انتقال مسار: التوجيهات (`/platform-management` ← `/platform` وغيرها) غطّت كل الوصول. لا يوجد تراجع سلوكي عن خط الأساس. إصلاح هذه التسعة خارج نطاق هذه الخطة (تتطلب أدواراً/تقويماً في بذرة W1.1 أو إعادة بناء الشاشات في خطة الترحيل).

عند الجري ضد واجهة API قائمة منذ زمن (`127.0.0.1:8000`) خارج حامل W1.1، فشلت 45 من 52 بسبب حالة البذرة البيئية لا بسبب التوجيه.
