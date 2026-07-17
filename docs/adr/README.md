---
doc_id: ADR-README
title: فهرس قرارات المعمارية
type: adr
status: accepted
version: 1.1.0
date: 2026-07-17
owner: مجلس معمارية المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources: []
references: []
deciders:
- مجلس معمارية المنصة
scope: docs/adr
supersedes: []
superseded_by: []
related_adrs: []
review_by: 2027-01-15
---
# قرارات المعمارية

هذا هو السجل الرسمي للقرارات المعمارية. الحالات هي `proposed` و`accepted` و`superseded` و`rejected`. لا يعدل قرار معتمد لتغيير معناه؛ ينشأ قرار لاحق يحل محله.

| ADR | العنوان | الحالة |
|---|---|---|
| [001](001-modular-monolith.md) | Modular Monolith | accepted |
| [002](002-module-first-vertical-slices.md) | Module-First Vertical Slices | accepted |
| [003](003-module-boundaries.md) | حدود الموديولات وملكية البيانات | accepted |
| [004](004-authorization-and-isolation.md) | RBAC + ABAC والعزل التنظيمي | accepted |
| [005](005-work-records-dynamic-data.md) | WorkRecords والبيانات الديناميكية | accepted |
| [006](006-workflow-versioning.md) | إصدارات المسارات | accepted |
| [007](007-transactional-outbox.md) | Transactional Outbox | accepted |
| [008](008-shared-content-query-capabilities.md) | قدرات المحتوى والاستعلام المشتركة | superseded |
| [009](009-unified-react-shell.md) | واجهة React موحدة | accepted |
| [010](010-air-gapped-kubernetes.md) | Kubernetes معزول | superseded |
| [011](011-lightweight-cqrs-and-transactions.md) | CQRS خفيف وحدود المعاملة | accepted |
| [012](012-local-identity-and-session-security.md) | الهوية المحلية وأمن الجلسات | accepted |
| [013](013-documents-and-file-security.md) | المستندات وأمن الملفات | accepted |
| [014](014-authorized-search.md) | البحث المحكوم | accepted |
| [015](015-authorized-reporting.md) | التقارير واللوحات المحكومة | accepted |
| [016](016-audit-and-records-governance.md) | التدقيق وحوكمة السجلات | accepted |
| [017](017-derived-workspace-and-notifications.md) | مساحة العمل والإشعارات المشتقة | accepted |
| [018](018-air-gapped-supply-chain.md) | سلسلة التوريد المعزولة | superseded |
| [019](019-kubernetes-resilience-and-recovery.md) | تشغيل Kubernetes والتعافي | superseded |
| [020](020-organization-and-time-bounded-authority.md) | التنظيم والسلطة المحددة زمنياً | accepted |
| [021](021-strategy-indicator-ownership.md) | ملكية الاستراتيجية والمؤشرات | accepted |
| [022](022-portfolio-projects-and-risk-boundaries.md) | حدود المحافظ والمشاريع والمخاطر | accepted |
| [023](023-single-host-dokploy-deployment.md) | تشغيل الخادم الداخلي عبر Dokploy وDocker Compose | accepted |
| [024](024-organization-identity-import-boundaries.md) | ملكية Organization وIdentity وحدود الاستيراد | proposed |

القالب المعتمد: [template.md](template.md).

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-17 | مجلس معمارية المنصة | إضافة ADR-024 المقترح |
| 1.0.0 | 2026-07-15 | مجلس معمارية المنصة | إنشاء فهرس قرارات المعمارية |
