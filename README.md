---
doc_id: GOV-ROOT-001
title: منصة التجمع الصحي الثالث
type: governance
status: accepted
version: 2.0.0
date: 2026-07-17
owner: طارق
reviewers: []
classification: internal
review_cycle: عند الحاجة
sources:
- docs/README.md
references:
- docs/governance/document-control.md
---
# منصة التجمع الصحي الثالث | Third Health Cluster Platform

تطبيق Laravel modular monolith مع واجهة React موحدة، عربية افتراضياً وتدعم
الإنجليزية وRTL/LTR.

## المكونات

- Laravel API وعامل Outbox/Notifications.
- React + TypeScript داخل Nginx.
- MySQL وRedis.
- Docker Compose مباشر على VPS واحد، وCaddy للـHTTPS.

## التحقق المحلي

```sh
make verify-w1-1
make verify-w1-1-local
./scripts/validate-docs.sh
```

`make verify-w1-1` يشغل اختبارات API والويب والحدود ورحلة MySQL/Redis من
المتصفح. `make verify-w1-1-local` يبني صور الإنتاج ويشغل حزمة Compose المؤقتة.

## النشر على VPS

يتوقع النشر أن MySQL وRedis يعملان مسبقاً على الخادم ولا تنشر منافذهما للعامة.

1. انسخ مثال البيئة:

```sh
install -m 600 infra/platform/production/.env.example infra/platform/production/.env.production
```

2. ضع النطاق و`APP_KEY` واعتمادات MySQL وRedis في `.env.production`.

3. اجعل MySQL وRedis يستمعان على عنوان خاص يمكن لشبكة Docker الوصول إليه، مع
   جدار ناري يسمح لشبكة Docker فقط. `host.docker.internal` يحدد بوابة المضيف لكنه
   لا يصل إلى خدمة مربوطة على `127.0.0.1` فقط.

4. وجّه DNS للنطاق إلى الـVPS وتأكد أن Caddy يملك `80/tcp` و`443/tcp,udp`؛
   يحتاجهما إصدار شهادة HTTPS وتجديدها.

5. انشر:

```sh
make deploy-vps
```

يبني الأمر صور Laravel وReact، يشغل migration، ثم ينتظر صحة API والعامل والويب
وCaddy ويفحص `https://<APP_DOMAIN>/up`. الأسرار تبقى في `.env.production`
بصلاحية `600` والمحجوب عن Git.

## أوامر التشغيل

```sh
docker compose \
  --env-file infra/platform/production/.env.production \
  -f infra/platform/production/compose.yaml ps

docker compose \
  --env-file infra/platform/production/.env.production \
  -f infra/platform/production/compose.yaml logs -f api web worker caddy
```

للرجوع، انتقل إلى آخر commit سليم ثم شغّل `make deploy-vps` مجدداً. هذا رجوع
بإعادة البناء من المصدر، لذلك يجب إبقاء commit وDocker base images متاحين على
الخادم. لا تستخدم migration هدمية للرجوع؛ استخدم forward-fix أو استعادة نسخة MySQL.

## الوثائق

- المعمارية: `docs/architecture/overview.md`.
- حالة التسليم: `docs/plans/active-delivery-status.md`.
- التشغيل: `docs/operations/runbooks.md`.

## السرية

لا تضف أسراراً أو بيانات شخصية أو ملفات `.env.production` إلى Git.
