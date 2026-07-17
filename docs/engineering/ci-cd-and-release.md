---
doc_id: ARC-EN-005
title: التكامل والتسليم والإصدار
type: engineering
status: accepted
version: 2.0.0
date: 2026-07-17
owner: طارق
reviewers: []
classification: internal
review_cycle: عند الحاجة
sources:
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/engineering/database-migrations.md
- docs/plans/readiness-checklist.md
---
# التكامل والتسليم والإصدار

## المبدأ

مشروع يُطوَّر محلياً ويُنشر على خادم واحد عبر Dokploy، مثل أي مشروع على VPS.
CI يثبت أن الاختبارات سليمة، والنشر مرحلة نهائية مستقلة.

## CI على GitHub Actions

يعمل `.github/workflows/ci.yml` على أجهزة GitHub المستضافة (`ubuntu-latest`)
عند كل push، بأربع وظائف:

| الوظيفة | ماذا تفحص |
|---|---|
| `api` | composer validate، Pint، PHPStan، audit، اختبارات API، حدود الموديولات |
| `web` | npm ci، عقد OpenAPI والعميل المولد، lint، اختبارات الوحدة بالتغطية، البناء |
| `docs` | `./scripts/validate-docs.sh` و`mkdocs build --strict` |
| `secrets` | gitleaks على تاريخ المستودع |

لا runners ذاتية، ولا Environments، ولا توقيع صور، ولا SBOM في CI. رحلة E2E
الكاملة (`make verify-w1-1`) واختبار حزمة الإنتاج (`make verify-w1-1-local`)
تنفذ محلياً لأنها تحتاج Docker وMySQL ومتصفحاً.

## البناء والنشر (مرحلة D1 النهائية)

1. تُبنى صور الإنتاج محلياً من lockfiles عبر `make verify-w1-1-local`، الذي
   يفحص الحزمة ويبنيها ويشغل رحلة E2E كاملة على Compose الإنتاجي.
2. تُدفع الصور إلى registry ويثبت مرجعها بالـdigest في
   `infra/platform/production/compose.yaml`.
3. ينشر Dokploy الحزمة على الخادم الداخلي، وhealthchecks تسبق التفعيل.
4. الرجوع = إعادة نشر آخر digest سليم من Dokploy. لا DDL هدمي؛ ترحيلات
   forward-fix أو restore وفق وثيقة الترحيلات.
5. قبل التشغيل الحقيقي تُراجع `docs/plans/readiness-checklist.md` مرة واحدة
   (نسخ/استعادة وأمن أساسي). إعداد الخادم وشبكته شأن إدارة الخادم خارج
   نطاق المستودع.

## قواعد ثابتة

- الصور تُبنى من lockfiles ولا تنزّل حزماً عند بدء الحاوية.
- صور الإنتاج مثبتة بالـdigest في Compose، لا `latest`.
- لا أسرار في المستودع؛ أسرار التشغيل تُدار في Dokploy على الخادم.
- MySQL وValkey على شبكات داخلية ولا تُنشر منافذها للعامة.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
| --- | --- | --- |
| 2.0.0 | 2026-07-17 | تبسيط شامل: CI على أجهزة GitHub المستضافة، حذف runners الداخلية وEnvironments والتوقيع وSBOM، ونشر VPS عبر Dokploy في مرحلة D1 |
| 1.3.1 | 2026-07-17 | تسجيل blocker P0-A عند غياب مدخلات GitHub الحية |
| 1.2.0 | 2026-07-17 | نقل CI إلى GitHub Actions بعزل runners وEnvironments |
| 1.1.0 | 2026-07-16 | مواءمة الإصدار مع Dokploy وCompose على خادم واحد |
