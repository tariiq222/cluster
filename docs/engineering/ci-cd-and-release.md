---
doc_id: ARC-EN-005
title: التكامل والتسليم والإصدار
type: engineering
status: accepted
version: 3.0.0
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

## CI

تعمل `.github/workflows/ci.yml` على GitHub-hosted runners عند كل push وpull request:

| الوظيفة | التحقق |
|---|---|
| `api` | Composer وPint وPHPStan وaudit واختبارات API وحدود الموديولات |
| `web` | npm وعقد OpenAPI وlint والتغطية والبناء |
| `docs` | تحقق الوثائق وبناء MkDocs |
| `secrets` | فحص الأسرار عبر Gitleaks |
| `production-bundle` | سياسة Compose وبناء الصور ورحلة MySQL/Redis/Worker/Browser الكاملة |

لا runners ذاتية ولا registry إلزامي ولا توقيع صور أو SBOM أو receipts.

## النشر

على الـVPS:

```sh
install -m 600 infra/platform/production/.env.example infra/platform/production/.env.production
# عدل الملف بالقيم الفعلية مرة واحدة
make deploy-vps
```

يبني Compose الصور من المصدر، يشغل migration، ثم API والعامل والويب وCaddy. يستخدم
MySQL وRedis الموجودين على الخادم من خلال `DB_HOST` و`REDIS_HOST`.

## الرجوع

1. اختر آخر commit سليم وتأكد أن مصدره وDocker base images ما زالت متاحة لإعادة البناء.
2. تأكد أن migration متوافقة للخلف أو استخدم forward-fix.
3. شغّل `make deploy-vps`.
4. تحقق من `/up` وتسجيل الدخول والعامل.

## قواعد ثابتة

- لا أسرار في Git؛ `.env.production` على الخادم فقط.
- Caddy هو المدخل العام الوحيد على `80/443`.
- MySQL وRedis غير متاحين من الإنترنت.
- Dockerfiles تبني من lockfiles وتشغل مستخدمين غير root.
- تؤخذ نسخة MySQL قبل migration عالية المخاطر.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 3.0.0 | 2026-07-17 | اعتماد CI مستضاف ونشر VPS مباشر مع Caddy وMySQL/Redis خارجيين |
| 2.0.0 | 2026-07-17 | تبسيط CI ونقل تشغيل الخادم إلى المرحلة النهائية |
