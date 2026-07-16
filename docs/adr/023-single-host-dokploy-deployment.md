---
doc_id: ADR-023
title: تشغيل الخادم الداخلي عبر Dokploy وDocker Compose
type: adr
status: accepted
version: 1.0.0
date: 2026-07-16
owner: مجلس معمارية المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
references:
- docs/operations/kubernetes-platform.md
- docs/operations/physical-topology.md
- docs/operations/ha-dr-backup.md
deciders:
- مالك المنصة
scope: الاستضافة والنشر والوصول والتعافي
supersedes:
- ADR-018
- ADR-019
superseded_by: []
related_adrs:
- ADR-001
- ADR-007
- ADR-013
review_by: 2027-01-16
---
# ADR-023: تشغيل الخادم الداخلي عبر Dokploy وDocker Compose

## Context

يعمل المنتج على خادم داخلي واحد يملكه مشغل المنصة، وليس على مركز بيانات متعدد العقد. الوصول إلى الخادم محدود بمنافذ يحددها الجدار الناري، لكنه لا يوصف كبيئة `air-gapped` مؤسسية ولا يحتاج Kubernetes لتحقيق قيمة المنتج الحالية.

## Drivers

بساطة التشغيل لفريق صغير، خفض كلفة التعقيد، نشر قابل للتكرار، حماية خدمات الحالة، وإمكانية النسخ والاستعادة خارج نطاق عطل الخادم.

## Decision

يُشغّل الإنتاج على خادم داخلي واحد باستخدام Dokploy لإدارة حزمة Docker Compose مثبتة الإصدارات. لا يستخدم Kubernetes أو RKE2 أو GitOps controller؛ ويقتصر الوصول الوارد على HTTPS ومسار إدارة محمي، مع منع نشر MySQL وValkey وDocker socket وواجهة Dokploy للعامة.

## Scope

يشمل Docker وCompose وDokploy وTraefik والجدار الناري وشبكات الحاويات وإدارة الأسرار والنشر والرجوع والنسخ خارج الخادم. لا يغير حدود Laravel Modular Monolith أو عقود الموديولات.

## Alternatives

- **Kubernetes أو RKE2:** يوفر orchestration متعدد العقد، لكنه لا يحقق توافراً عالياً على جهاز واحد ويضيف عبئاً تشغيلياً غير مبرر حالياً.
- **Docker Compose دون Dokploy:** صالح تقنياً وأبسط اعتماداً، لكنه يفتقد واجهة التشغيل وسجل النشر الملائمين للمشغل؛ يبقى مسار طوارئ واستعادة مقبولاً.
- **Air-gap كامل بمرايا داخلية:** يوفر عزلاً أقوى، لكنه لا يمثل البيئة الفعلية ويتطلب بنية تغذية حزم وصور غير موجودة.

## Consequences

- يصبح النشر والصيانة أبسط، وتصبح حزمة Compose هي تعريف التشغيل القابل لإعادة الإنشاء.
- يقبل مالك المنصة أن تعطل الخادم يوقف الخدمة؛ لا يُدعى وجود HA أو استمرار الخدمة عند فشل الجهاز.
- تبقى الصور مثبتة بالـdigest، والتحديثات خاضعة للمراجعة، والنسخة السابقة قابلة للرجوع.
- يجب أن تكون النسخ مشفرة وخارج الخادم وباعتمادات منفصلة، وأن تختبر الاستعادة على هدف منفصل.

## Security

يطبق الجدار الناري default-deny للوصول الوارد، وتتاح HTTPS فقط للمستخدمين، بينما يمر SSH وواجهة Dokploy عبر VPN أو شبكة إدارة مقيدة. تبقى خدمات الحالة على شبكات Docker داخلية ولا ينشر Docker socket مباشرة.

## Operations

يشغل Dokploy تطبيق React وLaravel Web/API والعمال والـscheduler وخدمات MySQL وValkey من حزمة Compose مثبتة. تراقب الصحة والسعة ومساحة القرص، وتوثق التحديثات والرجوع والنسخ والاستعادة.

## Rollback

يعاد نشر آخر image digest وCompose revision معروفين بالصحة. لا تستخدم migrations هدامة كوسيلة رجوع، وتستعاد البيانات من النسخة الخارجية عند الحاجة وفق runbook مقاس.

## Enforcement

يفحص CI تثبيت الصور، صحة Compose، غياب المنافذ العامة لخدمات الحالة، ووجود healthchecks وعقد النسخ. تثبت تجربة حية الجدار الناري والنشر والرجوع والاستعادة قبل إغلاق بوابة الإنتاج.

## Review

يراجع القرار عند إضافة خادم ثانٍ، أو تعذر تحقيق أهداف الاستعادة، أو تجاوز السعة المقاسة للخادم، أو توفر فريق منصة يبرر الانتقال إلى orchestrator متعدد العقد.

## References

`docs/operations/kubernetes-platform.md`، `docs/operations/physical-topology.md`، `docs/operations/ha-dr-backup.md`.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-16 | مالك المنصة | اعتماد Dokploy وDocker Compose على خادم داخلي واحد |
