---
doc_id: CON-IDX-001
title: فهرس العقود
type: contracts
status: accepted
version: 2.0.0
date: 2026-07-18
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources: []
references:
- docs/contracts/capabilities/identity-credentials-and-sessions.md
- docs/contracts/capabilities/document-signed-direct-upload.md
- docs/contracts/capabilities/organization-import-rows-v1.md
- docs/contracts/capabilities/temporary-assignment.md
---
# عقود المنصة

تحدد هذه العقود ذات الإصدارات حدود المنصة لعملاء HTTP والمستهلكين غير المتزامنين.

| الملف | الغرض |
|---|---|
| [api/openapi.yaml](api/openapi.yaml) | REST API، OpenAPI 3.1 |
| [api/w1-2.openapi.yaml](api/w1-2.openapi.yaml) | snapshot مجمد لعقود W1.2 قبل التنفيذ |
| [api/notifications.md](api/notifications.md) | فهرس عملية قائمة إشعارات المستخدم المحكومة |
| [events/asyncapi.yaml](events/asyncapi.yaml) | نقل أحداث المجال، AsyncAPI 3.1 |
| `schemas/` | موارد JSON Schema Draft 2020-12 |
| [module-contracts.md](module-contracts.md) | الملكية والتوافق وقواعد التسليم |
| [capabilities/identity-credentials-and-sessions.md](capabilities/identity-credentials-and-sessions.md) | بيانات الاعتماد والتفعيل والجلسة opaque المخططة |
| [capabilities/document-signed-direct-upload.md](capabilities/document-signed-direct-upload.md) | الرفع الموقع المباشر إلى حجر Documents المخطط |
| [capabilities/organization-import-rows-v1.md](capabilities/organization-import-rows-v1.md) | schemas صفوف facilities والوحدات والمناصب v1 |
| [capabilities/temporary-assignment.md](capabilities/temporary-assignment.md) | التكليف المؤقت دقيق الوحدة ومحدود القدرة والزمن |

كل المعرفات سلاسل RFC 9562 UUID version 7 بالنمط `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx` وبأحرف سداسية صغيرة، والطوابع الزمنية RFC 3339 UTC وتنتهي حصراً بـ`Z`. التصنيف أحد `public` أو `internal` أو `confidential` أو `top_secret`؛ ويحافظ المستهلك على التصنيف ويطبق سياسة التعامل. تعيد تمثيلات API قيمة `ETag`، ويتطلب تعديل المورد القائم `If-Match` مطابقاً. الأوامر القابلة لإعادة المحاولة تتطلب `Idempotency-Key`.

تستخدم أخطاء HTTP صيغة RFC 7807 `application/problem+json`. يتطلب كل طلب `X-Correlation-ID` بصيغة UUIDv7، ويعيده كل رد؛ ويتطلب كل CloudEvent امتداد `correlationid` بالقيمة نفسها. تنقل الأحداث عبر Redis Streams مع consumer groups وتسليم at-least-once. يتطلب تغيير العقد إصداراً إضافياً متوافقاً للخلف أو endpoint وchannel وschema جديدة بإصدار صريح.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 2.0.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر عقود القدرات المتبقية لـW1.2 مع إبقاء حالة التنفيذ planned |
| 1.8.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر تمثيلات ImportJob المنقحة وأحداث دورة الاستيراد المحكومة |
| 1.7.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر تمثيلات حساب Identity وأحداث دورة حياته الخالية من الاعتمادات وPII العرض |
| 1.6.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر تمثيلات Person وأحداث التسجيل والتحديث المصغرة الخالية من حقول PII والأسماء |
| 1.5.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر عقود شجرة الوحدات والمناصب وأحداثها |
| 1.4.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر عقود تحديث التجمع وتحديث/أرشفة المنشأة |
| 1.3.0 | 2026-07-18 | مسؤول هندسة البرمجيات | نشر عقود أحداث إنشاء التجمع والمنشأة |
| 1.2.0 | 2026-07-18 | مسؤول هندسة البرمجيات | تجميد snapshot وعقود أحداث W1.2 |
| 1.0.0 | 2026-07-15 | مسؤول هندسة البرمجيات | إنشاء أولي |
| 1.1.0 | 2026-07-16 | مسؤول هندسة البرمجيات | إضافة فهرس عقد قائمة إشعارات المستخدم |
