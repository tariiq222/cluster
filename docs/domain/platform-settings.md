---
doc_id: DOM-PLS-001
title: إعدادات المنصة والتقويم التشغيلي
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول PlatformSettings
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/architecture/dependency-rules.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# إعدادات المنصة والتقويم التشغيلي

## الغرض والنطاق

يمتلك `PlatformSettings` الإعدادات العامة التي لا يملكها مجال آخر، وإصداراتها المنشورة، والتقويم التشغيلي. يشمل اللغة والـlocale والمنطقة الزمنية، وحدود الجلسة فوق الحد الأمني الثابت، وحدود التشغيل، وأيام العمل والعطل وساعات العمل وقواعد احتساب المواعيد. لا يملك إعدادات نوع عمل أو مسار أو مشروع أو مؤشر.

## الكيانات والجداول والقيود

| الجدول | الحقيقة | القيود |
|---|---|---|
| `platform_setting_versions` | إصدار إعدادات عام وحالته وhash | منشور واحد فعال؛ المنشور immutable |
| `platform_settings` | مفتاح typed وقيمة ضمن الإصدار | فريد `(version_id, setting_key)`؛ allow-list للمفاتيح والأنواع |
| `business_calendars` | تقويم نطاقه تجمع أو منشأة وسياسته | تقويم فعال واحد لكل نطاق وزمن |
| `business_calendar_days` | يوم عمل/عطلة واستثناء ساعات | فريد `(calendar_id, calendar_date)` |

## الأوامر والاستعلامات والأحداث والحالات

**Commands:** `CreatePlatformSettingsDraft`, `SetPlatformSetting`, `PublishPlatformSettingsVersion`, `CreateBusinessCalendar`, `SetBusinessCalendarDay`.

**Queries:** `GetEffectivePlatformSetting`, `GetPlatformSettingsVersion`, `GetBusinessCalendar`, `CalculateBusinessDueAt`.

**Events:** `PlatformSettingsVersionPublished`, `BusinessCalendarPublished`.

```text
SettingsVersion: Draft -> Validated -> Published -> Retired
BusinessCalendar: Draft -> Published -> Superseded
```

## الثوابت والأمن والفشل

- لا يخفض إعداد منشور الحد الأدنى الأمني الثابت لكلمة المرور أو الجلسة.
- كل موعد تشغيلي يحسب من تقويم نطاق السجل وبـ`Asia/Riyadh`؛ تخزن الطوابع UTC.
- لا يغير نشر تقويم موعداً مثبتاً أو SLA جارياً بصمت؛ يعاد الحساب فقط بأمر المالك وسياسة صريحة.
- النشر والتعديل قدرات إدارية مركزية، وتسجيلها في Audit إلزامي؛ لا يمنح دور الإدارة اعتماد أعمال.
- مفتاح أو نوع أو تقويم غير صالح، أو نشر بلا تحقق، يرفض. فشل Outbox يلف النشر، ويستهلك الآخرون آخر إصدار منشور فقط.

## الاختبارات والاعتماديات

- اختبار typed keys، وعدم خفض الحد الأمني، وimmutability للإصدار المنشور.
- اختبار اللغة والـlocale والمنطقة الزمنية، والعطل وساعات العمل وحساب موعد يعبر نهاية الأسبوع.
- اختبار أن تغيير التقويم لا يعدل موعداً مثبتاً، واختبار Outbox idempotency.

لا يعتمد على موديول مجال. تعتمد عليه Identity وAuthorization وWorkDefinitions وRecordsGovernance، وتستهلك باقي الموديولات عقد التقويم فقط.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول PlatformSettings | إنشاء المواصفة المعتمدة |
