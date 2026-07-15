---
doc_id: GOV-CR-001
title: مراجعة اتساق القرارات الملزمة
type: governance
status: accepted
version: 2.0.0
date: 2026-07-15
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources: []
references:
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/005-work-records-dynamic-data.md
- docs/domain/work-definitions.md
- docs/domain/work-records.md
- docs/governance/document-control.md
- docs/governance/glossary.md
- docs/governance/traceability-matrix.md
- docs/plans/release-1-platform.md
---
# نتيجة مراجعة الاتساق

## النتيجة

**Pass** لنطاق الإصلاح المكوّن من `docs/plans/**` و`docs/architecture/**` و`docs/domain/**` و`docs/governance/**` و`docs/operations/**`.

## ما تم التحقق منه

| الضابط | النتيجة | الدليل |
|---|---|---|
| مصدر الوثائق | Pass | جميع المراجع في النطاق تشير إلى مسارات حالية تحت `docs/`، وتعرّف `docs/governance/document-control.md` هذا المسار مصدراً وحيداً. |
| حدود الطلب الداخلي | Pass | «الطلب الداخلي العام» نوع منشور في `WorkDefinitions`، وكل مثيل وعلاقة ومشارك ونشاط تابع له مملوك لـ`WorkRecords`. لا يوجد موديول أو namespace أو جدول أو aggregate مستقل للطلبات. |
| خطة R1 | Pass | تستخدم W1.6 `WorkRecord` و`RecordRelation` و`RecordParticipant` و`RecordActivity`، وتسند ملكية التنفيذ إلى `WorkRecords`. |
| التتبّع | Pass | يسند `FR-R1-007` إلى `WorkRecords` ويصف `request` بأنه رمز نوع عمل منشور. |
| ملكية KRI | Pass | يملك `Strategy` وحده تعريفات المؤشرات وقياساتها، بينما يملك `Risk` روابط KRI والعتبات والتنبيهات؛ لا توجد قراءة مؤشر مكررة في Risk. |
| خطة R3 | Pass | موديول `Risk` مخطط في خطة R3، وW3.0 مواصفة سياسة لازمة لتنفيذ الخطة وليست دليلاً على غياب التخطيط. |
| ما بعد R3 | Pass | المجالات المذكورة بعد R3 مرشحات غير ملزمة، ولا تصبح موديولات إلا بقرار حدود وملكية وعقود وADR. |
| التصنيفات | Pass | القيم canonical هي `public` و`internal` و`confidential` و`top_secret`، وأسماء العرض العربية هي عام وداخلي وسري وسري للغاية. |
| علامات النص المؤقت | Pass | لا توجد علامات نص مؤقت أو عناصر إصلاح معلّقة في النطاق. |

لا تُعد كلمة «طلب» في النص التشغيلي، ولا مصطلح HTTP request، تعريفاً لموديول أو مساحة أسماء أو جدول أو aggregate.

## حدود النتيجة

تثبت هذه النتيجة اتساق الملفات الواقعة في المجلدات الخمسة المحددة فقط. لا تقدم حكماً على ملفات خارج هذا النطاق.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مكتب هندسة المنصة | مراجعة أولية للتعارضات |
| 2.0.0 | 2026-07-15 | مكتب هندسة المنصة | تحويل المراجعة إلى نتيجة Pass بعد إصلاح المراجع وحدود `WorkRecords` والتصنيفات والتتبّع ضمن النطاق |
