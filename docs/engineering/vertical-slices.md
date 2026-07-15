---
doc_id: ARC-EN-002
title: الشرائح الرأسية داخل الموديولات
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
- docs/adr/002-module-first-vertical-slices.md
references:
- docs/architecture/overview.md
- docs/engineering/coding-and-module-boundaries.md
---
# الشرائح الرأسية داخل الموديولات

## القاعدة

التطبيق Modular Monolith. الموديول هو حد الملكية الأعلى؛ والـSlice حالة استخدام واحدة داخله. لا تنشأ طبقات تطبيقية عامة تجمع كود موديولات مختلفة.

```text
Modules/<Module>/
├── Domain/                 # قواعد واتساق الموديول المشتركة
├── Contracts/              # DTOs وواجهات منشورة فقط
├── Events/                 # أحداث صادرة بإصدار معلوم
├── Infrastructure/         # التخزين والمحولات الخاصة بالمالك
└── Features/<BusinessVerb>/ # Slice واحدة
    ├── Command|Query
    ├── Handler
    ├── Http
    └── Tests
```

## قواعد الـSlice

1. الاسم فعل ونتيجة أعمال مثل `SubmitWorkRecord` أو `ApproveMilestone`، وليس اسم طبقة مثل `RecordService`.
2. Slice الكتابة تتحقق من الإدخال والصلاحية، تنفذ invariant في `Domain`، وتحفظ الحقيقة وOutbox في المعاملة نفسها.
3. مالك الـHandler الذي يبدأ الكتابة هو مالك المعاملة؛ لا تفتح العقود المتزامنة معاملة مستقلة ولا تنفذ `commit`.
4. Slice القراءة تعيد DTO أو View ثابتاً، وتطبق النطاق وقرار الحقول قبل الإخراج. لا تعيد ORM model.
5. يستهلك الموديول الآخر عقداً أو حدثاً أو Read Model؛ لا يستورد تفاصيل Slice أو Domain أو Infrastructure للموديول المالك.
6. تتطابق Feature الواجهة مع حالة الاستخدام، ولا تقرر صلاحية حساسة في المتصفح.

## معيار الاكتمال

تعد الـSlice مكتملة عند وجود نتيجة أعمال قابلة للعرض، اختبار نجاح وفشل الصلاحية وinvariant، عقد API أو event متوافق، اختبار حدود معماري، وتحديث الترحيل أو الإسقاط عند الحاجة.

## ممنوعات

- `CommonHandler` أو Repository عام أو Workflow عام بلا استخدامين مستقرين على الأقل.
- وضع قرار مجال في Controller أو React أو Job.
- استخدام الحدث كأمر مخفي؛ الحدث يصف حقيقة تمت فقط.
- جعل Search أو Reporting مصدر الحقيقة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول هندسة البرمجيات | توثيق قواعد الشرائح التنفيذية |
