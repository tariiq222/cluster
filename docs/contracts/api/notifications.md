---
doc_id: CON-API-001
title: عقد واجهة الإشعارات
type: contracts
status: accepted
version: 1.1.0
date: 2026-07-16
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/contracts/api/openapi.yaml
references:
- docs/architecture/module-catalog.md
- docs/contracts/schemas/problem-details.schema.json
---
# عقد واجهة الإشعارات

## السلطة التعاقدية

يمثل المسار `GET /api/v1/notifications` عملية `listMyNotifications` للمستخدم المصادق عليه. يبقى [OpenAPI](openapi.yaml) السلطة القانونية الوحيدة لشكل الطلب والاستجابات والحقول؛ هذه الصفحة فهرس محكوم ولا تعيد تعريف المخطط.

## حدود الملكية والخصوصية

- يعيد الموديول إسقاطات `Notifications` التي يملكها للمستلم المستمد حصراً من هوية Bearer الموثوقة.
- لا يمنح مرجع السجل صلاحية فتح المصدر؛ يعيد endpoint المالك اتخاذ قرار الوصول عند الانتقال إلى السجل.
- يستخدم كل إشعار `source` من النوع المشترك `SourceReference` (`source_module` و`record_type` و`record_id`) بدلاً من معرف سجل غير مقيّد النوع.
- لا تحمل الاستجابة payload أو منشأة أو سياق وصول أو أسباب قرار أو أثر تفويض من `WorkRecords`.
- يستخدم الخطأ `application/problem+json` ومخطط RFC 7807 المشترك، ويعيد كل رد `X-Correlation-ID` المطلوب في الطلب.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | مسؤول هندسة البرمجيات | استبدال معرف المصدر المفرد بمرجع مصدر مكتوب النوع ومتوافق مع العقد المشترك |
| 1.0.0 | 2026-07-16 | مسؤول هندسة البرمجيات | إنشاء فهرس عقد قائمة إشعارات المستخدم |
