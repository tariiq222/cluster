---
doc_id: ARC-FL-001
title: C4 والتدفقات المعمارية
type: architecture
status: accepted
version: 1.0.0
date: '2026-07-15'
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول العمليات
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources: []
references: []
---
# C4 والتدفقات المعمارية

الرسومات المصدرية القابلة للتحرير موجودة في [diagrams](diagrams/). تصف القرارات المعتمدة وفق ADR-023: خادم داخلي واحد، Dokploy، وحزمة Docker Compose مثبتة. لا تمثل Kubernetes أو RKE2 أو GitOps controller، ولا تفترض air-gap مؤسسياً.

| المستوى أو التدفق | الرسم | الغرض |
|---|---|---|
| C1، سياق النظام | [system-context.mmd](diagrams/system-context.mmd) | المستخدمون وحدود الأنظمة الخارجية. |
| C2، الحاويات | [containers.mmd](diagrams/containers.mmd) | React وLaravel والعمال والمخازن الداخلية. |
| C3، الموديولات | [modules.mmd](diagrams/modules.mmd) | الموديولات القانونية واتجاه الاعتماد. |
| النشر | [deployment.mmd](diagrams/deployment.mmd) | خادم واحد، منافذ واردة مقيدة، ونسخ خارجية. |
| التفويض | [authorization-sequence.mmd](diagrams/authorization-sequence.mmd) | `RecordFacts` وقرار الوصول. |
| سجل العمل والمسار | [workflow-sequence.mmd](diagrams/workflow-sequence.mmd) | إرسال `WorkRecord` ومعاملة المالك. |
| المستند | [document-sequence.mmd](diagrams/document-sequence.mmd) | الرفع والتنزيل وإعادة التفويض. |
| Outbox | [outbox-sequence.mmd](diagrams/outbox-sequence.mmd) | النشر بعد commit والتسليم المتكرر بأمان. |

## قراءة التدفقات

- الخط المتصل هو عقد متزامن؛ مصدره لا يرى البنية الداخلية للمورد.
- الخط المتقطع هو حدث أو إسقاط مشتق بعد commit، وليس مساراً لتغيير حقيقة المصدر.
- كل تدفق كتابة يحدد Handler مالك المعاملة. لا يرسل العامل أو خدمة خارج العملية قبل commit.
- حالات `WorkRecord` المعروضة نمط مرجعي قابل للتكوين بنوع العمل؛ لا تفترض أن كل نوع عمل يتبعها كلها.

## حدود الوثيقة

لا توجد تكاملات خارجية في المرحلة الأولى. أي تكامل مستقبلي يتطلب تحديد النظام والبيانات والاتجاه والمالك ومتطلبات الأمن قبل إضافة Adapter أو عقد.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | مكتب هندسة المنصة | مواءمة رسومات النشر مع Dokploy وCompose على خادم واحد |
