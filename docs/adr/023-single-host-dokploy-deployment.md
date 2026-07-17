---
doc_id: ADR-023
title: تشغيل VPS مباشر عبر Docker Compose
type: adr
status: accepted
version: 2.0.0
date: 2026-07-17
owner: طارق
reviewers: []
classification: internal
review_cycle: عند الحاجة
sources:
- docs/architecture/overview.md
references:
- docs/operations/runbooks.md
- docs/operations/ha-dr-backup.md
deciders:
- طارق
scope: الاستضافة والنشر والوصول والتعافي
supersedes:
- ADR-018
- ADR-019
superseded_by: []
related_adrs:
- ADR-001
- ADR-007
- ADR-013
review_by: 2027-01-17
---
# ADR-023: تشغيل VPS مباشر عبر Docker Compose

## Context

يملك المطور VPS واحداً تتوفر عليه MySQL وRedis. لا توجد حاجة إلى Kubernetes أو
Dokploy أو سلسلة إصدار مؤسسية لتشغيل التطبيق الحالي.

## Decision

يُنشر التطبيق مباشرة من المستودع عبر Docker Compose. تشغل الحزمة Caddy وReact/Nginx
وLaravel API والعامل وmigration فقط. تستخدم الحاويات MySQL وRedis الموجودين على
الـVPS عبر عناوين تحددها متغيرات البيئة.

Caddy هو المدخل العام الوحيد ويصدر HTTPS تلقائياً. لا تنشر MySQL أو Redis أو
Docker socket للعامة. تحفظ أسرار التشغيل في `.env.production` خارج Git.

## Rationale

- أمر نشر واحد يمكن للمطور تشغيله وفهمه.
- لا registry أو runners ذاتية أو توقيع صور أو receipts تشغيلية.
- قواعد البيانات الموجودة على الخادم لا تتكرر داخل Compose الإنتاجي.
- يمكن إعادة بناء نسخة سابقة من commit معروف للرجوع.

## Runtime

```text
Internet -> Caddy :443 -> React/Nginx -> Laravel PHP-FPM
                                      -> Worker
Laravel/Worker -> MySQL + Redis on the VPS
```

لا يشغل Scheduler حتى توجد مهمة مجدولة فعلية. migration حاوية one-shot تسبق API
والعامل. تبنى الصور من lockfiles عبر Dockerfiles متعددة المراحل.

## Security

- يفتح الجدار الناري `80/443` للمستخدمين وSSH لعناوين الإدارة فقط.
- يجب أن تستمع MySQL وRedis على loopback أو واجهة خاصة تسمح لشبكة Docker فقط.
- يستخدم التطبيق حساب MySQL محدود الصلاحيات وكلمة مرور Redis.
- لا `network_mode: host` ولا حاويات privileged.

## Deployment And Rollback

ينفذ `make deploy-vps` التحقق والبناء و`docker compose up -d` وفحص الصحة. للرجوع
يُختار آخر commit سليم ويعاد الأمر. تغييرات قاعدة البيانات تكون backward-compatible؛
وعند فقد البيانات تستخدم نسخة MySQL مستقلة.

## Consequences

- تعطل الـVPS يوقف الخدمة؛ لا يوجد ادعاء HA.
- إدارة MySQL وRedis والنسخ الاحتياطي مسؤولية تشغيل الخادم.
- الانتقال إلى orchestrator يعاد بحثه فقط عند إضافة أكثر من خادم أو فريق تشغيل.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 2.0.0 | 2026-07-17 | استبدال Dokploy بنشر Docker Compose مباشر واستخدام MySQL وRedis الموجودين على VPS |
| 1.0.0 | 2026-07-16 | اعتماد Dokploy وDocker Compose على خادم داخلي واحد |
