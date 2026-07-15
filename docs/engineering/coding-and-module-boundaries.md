---
doc_id: ARC-EN-003
title: حدود الكود والموديولات
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/architecture/dependency-rules.md
- docs/adr/003-module-boundaries.md
references:
- docs/architecture/overview.md
- docs/data-security/logical-data-model.md
---
# حدود الكود والموديولات

## ملكية البيانات

لكل حقيقة وجدول وترحيل ومخطط حدث مالك واحد. يحتفظ المستهلك الخارجي بالمعرف أو نسخة مشتقة قابلة لإعادة البناء فقط. لا يملك `shared` بيانات أعمال أو قواعد مجال؛ يقتصر على primitives تقنية محايدة.

## اتجاه الاعتماد

```text
Business Modules -> Platform Contracts -> Core Contracts
```

الاعتماد DAG بلا دورات. الاستيراد الخارجي مسموح من `Contracts` و`Events` المنشورة فقط. يمنع استيراد `Domain` أو `Infrastructure` أو ORM model أو migration من موديول آخر.

## قاعدة الاستعلام

1. يسمح بـ`JOIN` بين جداول الموديول نفسه فقط.
2. يمنع أي `JOIN` أو subquery أو FK عابر لموديولي أعمال.
3. القراءة العابرة تمر بعقد متزامن محدود، أو event وإسقاط محلي، أو Reporting Read Model مملوك لـ`Reporting`.
4. `Search` و`Reporting` و`Notifications` تخزن مشتقات ولا تكتب في جداول الأعمال.
5. لا يعالج contract DTO ككيان قابل للحفظ في الموديول المستهلك.

## العقود والأحداث

- العقد: مدخلات ومخرجات DTOs ثابتة، أخطاء معلنة، ومالك وإصدار.
- الحدث: حقيقة ماضية، يحمل `event_id` و`occurred_at` ونسخة schema ومرجع المصدر.
- Outbox يحفظ مع الحقيقة في المعاملة نفسها؛ المستهلك idempotent ويسجل `event_id` قبل الأثر.
- تغيير عقد أو حدث غير متوافق يتطلب إصداراً جديداً وفترة توافق موثقة.

## اختبارات الحراسة المعمارية

ينفذ CI اختبارات آلية تفشل عند: استيراد محظور، دورة اعتماد، SQL عابر للمالك، كتابة مشتق في جدول أعمال، عقد بلا اختبار contract، أو event بلا اختبار schema/compatibility. لا تقبل استثناءات دائمة؛ الاستثناء المؤقت موثق بمالك وتاريخ انتهاء وتذكرة إزالة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول هندسة البرمجيات | تثبيت قواعد الملكية والحدود واختبارات الحراسة |
