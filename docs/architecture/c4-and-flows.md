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

الرسومات المصدرية القابلة للتحرير موجودة في [diagrams](diagrams/). تصف القرارات المعتمدة لا اختيار منتج بعينه؛ أسماء محرك البحث والتخزين والـqueue وتوزيع Kubernetes تُختار قبل بوابة التشغيل مع بقاء التشغيل الداخلي المعزول متطلباً ثابتاً.

| المستوى أو التدفق | الرسم | الغرض |
|---|---|---|
| C1، سياق النظام | [system-context.mmd](diagrams/system-context.mmd) | المستخدمون وحدود الأنظمة الخارجية. |
| C2، الحاويات | [containers.mmd](diagrams/containers.mmd) | React وLaravel والعمال والمخازن الداخلية. |
| C3، الموديولات | [modules.mmd](diagrams/modules.mmd) | الموديولات القانونية واتجاه الاعتماد. |
| النشر | [deployment.mmd](diagrams/deployment.mmd) | النسخ القابلة للتوسع والتشغيل المعزول. |
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

لا توجد تكاملات خارجية في المرحلة الأولى. أي تكامل مستقبلي يتطلب تحديد النظام والبيانات والاتجاه والمالك ومتطلبات الأمن قبل إضافة Adapter أو عقد. لا تمثل المخططات قراراً باعتماد منتج محدد للبنية التحتية.
