# Project Instructions

## Product

هذا المستودع يبني منصة التجمع الصحي الثالث كتطبيق Laravel modular monolith مع واجهة
React + TypeScript موحدة، عربية افتراضياً وتدعم الإنجليزية وRTL/LTR.

## Sources of Truth

- `docs/` هو مصدر القرارات والعقود والخطط المحكومة.
- الكود والاختبارات والـlockfiles هي دليل الحالة التنفيذية الفعلية.
- `docs/plans/active-delivery-status.md` يسجل العمل النشط والأدلة والخطوة التالية.
- لا تُعامل وثيقة هدف أو خطة على أنها تنفيذ مكتمل من دون دليل قابل للتشغيل.

## Architecture Boundaries

- يحظر الاستعلام أو join المباشر بين جداول موديولات الأعمال.
- التعاون بين الموديولات يكون عبر contracts وevents وIDs وread models محكومة.
- يطبق الخلف قرار RBAC + ABAC نفسه على API والبحث والتقارير والتصدير والتنزيل.
- يحفظ تغيير الأعمال وOutbox event في معاملة واحدة، ويكون المستهلك idempotent.
- تبقى السجلات الجارية مثبتة على إصدارات أنواع العمل والمسارات المنشورة.
- النشر المستهدف خادم داخلي واحد يديره Dokploy من Docker Compose مثبت.

## Work Safety

- افحص `git status` قبل التعديل واحفظ تغييرات المستخدم غير ذات الصلة.
- استخدم `apply_patch` للتعديلات النصية ولا تستخدم أوامر Git مدمرة.
- لا توسع النطاق خارج طلب المستخدم، ولا تلتزم أو تدفع تغييراته دون تفويض.
- استخدم `rg` و`rg --files` للبحث أولاً.

## Verification

- تغييرات الوثائق: `./scripts/validate-docs.sh`.
- حدود الموديولات: `make verify-boundaries`.
- API: ابدأ بأضيق اختبار Artisan متأثر ثم وسع عند الحاجة.
- الويب: `npm --prefix apps/web run build` ثم الاختبار المستهدف.
- لا تشغل suites واسعة لتغيير غير سلوكي ما لم يبرر الخطر ذلك.

## Local OpenCode Tooling

- `.opencode/plugins/model-swarm.ts` و`.opencode/instructions/model-swarm.md` أدوات تطوير محلية وليست جزءاً من المنتج.
- لا تجعل بناء المنتج أو تشغيله يعتمد على `.opencode/`.
