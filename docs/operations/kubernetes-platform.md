---
doc_id: OPS-DP-001
title: منصة Docker Compose على VPS
type: operations
status: proposed
version: 3.0.0
date: 2026-07-17
owner: مسؤول العمليات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/architecture/overview.md
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/operations/physical-topology.md
- docs/operations/ha-dr-backup.md
---
# منصة Docker Compose على VPS

> احتُفظ باسم الملف التاريخي لاستقرار الروابط. لا يستخدم المنتج Kubernetes أو Dokploy.

## التوزيع

يعمل الإنتاج على VPS واحد. يشغل Docker Compose خمس خدمات: Caddy، Web، API،
Worker، وMigration one-shot. يستخدم التطبيق MySQL وRedis الموجودين على الخادم.

| المكون | القرار |
|---|---|
| HTTPS | Caddy يصدر الشهادة ويوجه إلى Web |
| Web | React static build داخل Nginx |
| API | Laravel PHP-FPM |
| المعالجة | عامل Outbox وRedis Streams؛ لا Scheduler بلا مهام مجدولة |
| الحالة | MySQL وRedis خارج Compose التطبيق وغير متاحين للعامة |
| الأسرار | `.env.production` على الخادم ومحجوب عن Git |

## النشر

```sh
make deploy-vps
```

يبني الأمر الصور من Dockerfiles وlockfiles، يشغل migration ثم الخدمات، ويتحقق من
`/up`. لا يحتاج Registry أو لوحة إدارة أو runner على الخادم.

## الوصول

- `80/tcp` للتحويل وإصدار الشهادة، و`443/tcp,udp` للمستخدمين.
- SSH مقيد بعناوين الإدارة.
- `3306` و`6379` وDocker socket غير عامة.
- تصل الحاويات إلى خدمات المضيف عبر `host.docker.internal:host-gateway` أو عنوان خاص.

## الرجوع

يُختار آخر commit سليم ثم يعاد `make deploy-vps`. لا تستخدم down migration هدمية؛
تستخدم forward-fix أو استعادة MySQL عند الحاجة.

## حدود التوافر

الـVPS نقطة فشل واحدة. تعدد الحاويات لا يحقق HA. تخفف المخاطر بالمراقبة والنسخ
الخارجية وإمكانية إعادة إنشاء الحزمة على VPS بديل.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 3.0.0 | 2026-07-17 | اعتماد Compose مباشر وCaddy وMySQL/Redis خارجيين |
| 2.0.0 | 2026-07-16 | استبدال Kubernetes بـDokploy وCompose |
