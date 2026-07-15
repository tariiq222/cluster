---
doc_id: OPS-DP-001
title: منصة Kubernetes والنشر
type: operations
status: proposed
version: 1.0.0
date: 2026-07-15
owner: مسؤول العمليات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources:
- docs/architecture/overview.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
references:
- docs/operations/physical-topology.md
- docs/operations/air-gap-supply-chain.md
---
# منصة Kubernetes والنشر

## قرار التوزيع

يختار فريق العمليات منصة Kubernetes مؤسسية مدارة داخلياً عندما تكون متاحة ومتوافقة مع التشغيل المعزول ومتطلبات الدعم والتحديث. عند عدم توافرها، يعتمد `RKE2` بثلاث عقد control-plane. لا يحدد هذا القرار منتجاً أو مزوداً فعلياً.

## البيئات والعناقيد

| البيئة | الغرض | البيانات |
|---|---|---|
| `Dev` | تطوير وتكامل أولي | اصطناعية فقط |
| `Test` | اختبارات آلية وأمنية | اصطناعية أو منزوعة الهوية |
| `Staging` | تحقق قبل الإنتاج وتمارين النشر | منزوعة الهوية أو اصطناعية |
| `Prod` | الخدمة الفعلية | بيانات تشغيلية محكومة |

تفصل `Prod` عن بقية البيئات حسابياً وشبكياً وصلاحياً. لا ينسخ الإنتاج إلى البيئات الأدنى إلا بإجراء معتمد لإخفاء البيانات.

## مكونات المنصة

| المكون | القرار |
|---|---|
| التطبيق | Web/API وworkers بعدة replicas، probes وlimits وrequests إلزامية |
| قاعدة البيانات | MySQL InnoDB Cluster يديره Operator مع مراقبة quorum وPITR |
| cache والطوابير | Valkey HA مع فصل منطقي للمسارات وحالة الفشل |
| البحث الموجّه للمستخدم | OpenSearch؛ الفهرس مشتق ومحكوم بالتفويض وليس مصدر الحقيقة |
| السجلات التشغيلية | Loki؛ لا يستخدم كفهرس بحث لسجلات الأعمال |
| الملفات والنسخ | S3-compatible storage أو MinIO؛ Object Lock للنسخ المحتجزة |
| الأسرار والشهادات | Vault، بما في ذلك إصدار شهادات PKI وتدويرها |
| المراقبة | metrics وlogs وtraces وتنبيهات داخلية |

## ضوابط النشر

- GitOps هو مسار الكتابة الوحيد إلى `Staging` و`Prod`، بما في ذلك العلاج العاجل والـrollback؛ يقتصر `kubectl` في `Prod` على التشخيص للقراءة فقط، وتمنع أوامر `apply|edit|patch|delete` اليدوية.
- تقبل admission policy صور OCI الموقعة فقط، وتتحقق من منشأها الداخلي وSBOM المرتبط بالإصدار.
- تحفظ manifests كتعريف مرغوب versioned، وتخضع للمراجعة والموافقة قبل المزامنة.
- تستخدم namespaces وحسابات خدمة مخصصة وRBAC بأقل صلاحية وNetworkPolicy بمنع خروج افتراضي.
- تمنع صور `latest`، وطلبات السحب من registries خارجية، والأسرار داخل manifests أو الصور.
- ينفذ rollback بإرجاع Git revision معروف ثم تحقق health وSLO؛ لا يجرى حذف موارد الحالة كوسيلة rollback.

## تشغيل آمن

- certificates داخلية صادرة من Vault PKI، مع مراقبة الانتهاء والتدوير قبل النفاد.
- encryption in transit بين الطبقات الحساسة حسب قدرة المكون، ولا تكتب الأسرار في logs أو tickets.
- تسمح سياسات الشبكة فقط بالتدفقات اللازمة: ingress إلى API، API/workers إلى خدمات الحالة، وGitOps إلى Kubernetes API.
- تسجل أعمال الإدارة والنشر وتراجع دورياً.

## قبول المنصة

- تنشر نسخة موقعة من Git revision معتمد دون اتصال خارجي.
- ينجح rolling update وrollback مثبتان على `Staging` قبل `Prod`.
- يرفض admission صورة بلا توقيع أو بلا provenance/SBOM مطلوبين.
- يثبت فشل عقدة واحدة أن replicas وprobes يعيدان الخدمة دون تدخل يدوي غير مبرر.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول العمليات | إنشاء قرار منصة Kubernetes والنشر |
