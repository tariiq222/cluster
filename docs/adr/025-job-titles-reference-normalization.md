---
doc_id: ADR-025
title: تطبيع المسمى الوظيفي عبر مرجع job_titles
type: adr
status: accepted
version: 1.0.0
date: 2026-07-22
owner: طارق
reviewers: []
classification: internal
review_cycle: عند الحاجة
sources:
- docs/domain/organization-and-people.md
- docs/plans/active-delivery-status.md
- docs/contracts/api/openapi.yaml
references:
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/adr/024-organization-identity-import-boundaries.md
- docs/adr/011-lightweight-cqrs-and-transactions.md
- docs/engineering/database-migrations.md
deciders:
- طارق
scope: تطبيع title_ar في Position عبر مرجع job_titles، دون إدارة ملاك أو اشتقاق صلاحيات
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-020
- ADR-024
review_by: 2027-07-21
---
# ADR-025: تطبيع المسمى الوظيفي عبر مرجع job_titles
## Context
`Position` مقعد وظيفي فردي (شخص واحد لكل فترة، مضمون بقيد التداخل في
`AssignmentHandler`). المسمى يخزن اليوم كنص حر `title_ar` في جدول `positions`،
ويطلبه العقد `PositionCreate` كـ`title` حر (`minLength 1, maxLength 255`).
عند إنشاء عدة مقاعد لنفس الوظيفة (مثل عشرة فنيي مختبر) يتكرر النص يدوياً، فتنشأ
صياغات متعددة لنفس الوظيفة («فني مختبر» مقابل «فنّي مختبر») لا يمكن للنظام
التعرف على تطابقها. هذا يفسد أي تجميع أو تقرير على مستوى الوظيفة ويجعل نمط
الرمز `LAB-TECH-##` العُرفي مصدرَ حقيقة هشاً غير مُلزَم.

## Drivers
تقارير موحدة موثوقة على مستوى الوظيفة، ومنع تشتت المسمى النصي، مع بقاء إدخال
البيانات التنظيمية بسيطاً ومتوافقاً مع ADR-020.

## Decision
نُدخل مرجعاً ثابتاً `job_titles(id, code, title_ar, status)` ونربط
`positions.job_title_id` به كمفتاح أجنبي. يصبح `job_titles` مصدر الحقيقة للمسمى،
ويبقى `positions.title_ar` لقطة مشتقة (denormalized) للعرض والتوافق. لا نُدخل
حقل عدد ملاك (`headcount`) مخزناً؛ «المعتمد/المشغول/الشاغر» تُحسب حياً:
المعتمد = عدد المقاعد النشطة، المشغول = المقاعد ذات تكليف نشط، الشاغر = الفرق.

## Scope
يشمل جدول `job_titles`، ربط `positions`، مسارات `GET/POST organization/job-titles`،
وتحويل حقل المسمى في إنشاء المنصب من نص حر إلى مرجع. لا يشمل: اشتقاق صلاحيات من
المنصب أو نوعه (تبقى الصلاحية من الوحدة والدور حسب ADR-004/ADR-020)، ولا إدارة
ملاك رسمية، ولا توليد رموز تلقائي، ولا تعديل `AssignmentHandler`.

## Alternatives
- **تجميع نصي في الواجهة حسب `title_ar`**: رُفض لهشاشته (اختلاف صياغة يصنع
  مجموعتين، وتغيير المسمى يغير الأعداد).
- **الاعتماد على نمط الرمز `LAB-TECH-##`**: رُفض لأنه عُرف غير مُلزَم.
- **كيان `PositionType` كامل مع ملاك وتوليد رموز**: أُجّل؛ يضيف مصدر حقيقة
  مكرراً (`headcount` مقابل عدد المقاعد الفعلي) لمتطلب ملاك غير معلن في R1–R3.

## Consequences
يصبح المسمى موحداً وقابلاً للتجميع الموثوق، ويزيد إدخال المراجع خطوةً واحدة
(اختيار مسمى معرّف بدل كتابة نص). التقارير على مستوى الوظيفة تصبح ممكنة دون
جدول ملاك.

## Security
لا أثر على قرار الوصول: `job_title_id` لا يشارك في `AuthorizationScope`،
والصلاحية تبقى محكومة بالوحدة والدور. المرجع بيانات تصنيف `internal` بلا حقول
حساسة.

## Operations
يراقب اتساق `positions.title_ar` مع `job_titles.title_ar` بعد الربط. حساب
الشواغر استعلام تجميعي حي متوافق مع «مراقبة الشواغر» في ADR-020.

## Rollback
ترحيل Expand-Contract: `job_title_id` يضاف nullable ولا يفرض NOT NULL قبل
اكتمال backfill؛ يُتراجع بإسقاط العمود والجدول المرجعي دون فقد أي منصب قائم
لبقاء `title_ar` مصدراً صالحاً طوال فترة التوافق.

## Enforcement
اختبارات: رفض `job_title_id` غير موجود، عدم فقد أي منصب في backfill، وإرجاع
التجميع عدداً واحداً لكل مسمى موحد.

## Review
عند ظهور متطلب إدارة ملاك رسمية (establishment) يعاد النظر في الترقية إلى
كيان `PositionType` كامل.

## References
`docs/domain/organization-and-people.md`، `docs/contracts/api/openapi.yaml`،
`docs/engineering/database-migrations.md`.
