---
doc_id: CON-IDX-001
title: فهرس العقود
type: contracts
status: accepted
version: 1.1.0
date: 2026-07-15
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources: []
references: []
---
# عقود المنصة

تحدد هذه العقود ذات الإصدارات حدود المنصة لعملاء HTTP والمستهلكين غير المتزامنين.

| الملف | الغرض |
|---|---|
| [api/openapi.yaml](api/openapi.yaml) | REST API، OpenAPI 3.1 |
| [api/notifications.md](api/notifications.md) | فهرس عملية قائمة إشعارات المستخدم المحكومة |
| [events/asyncapi.yaml](events/asyncapi.yaml) | نقل أحداث المجال، AsyncAPI 3.1 |
| `schemas/` | موارد JSON Schema Draft 2020-12 |
| [module-contracts.md](module-contracts.md) | الملكية والتوافق وقواعد التسليم |

كل المعرفات سلاسل RFC 9562 UUID version 7 بالنمط `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx` وبأحرف سداسية صغيرة، والطوابع الزمنية RFC 3339 UTC وتنتهي حصراً بـ`Z`. التصنيف أحد `public` أو `internal` أو `confidential` أو `top_secret`؛ ويحافظ المستهلك على التصنيف ويطبق سياسة التعامل. تعيد تمثيلات API قيمة `ETag`، ويتطلب تعديل المورد القائم `If-Match` مطابقاً. الأوامر القابلة لإعادة المحاولة تتطلب `Idempotency-Key`.

تستخدم أخطاء HTTP صيغة RFC 7807 `application/problem+json`. يتطلب كل طلب `X-Correlation-ID` بصيغة UUIDv7، ويعيده كل رد؛ ويتطلب كل CloudEvent امتداد `correlationid` بالقيمة نفسها. تنقل الأحداث عبر Redis Streams مع consumer groups وتسليم at-least-once. يتطلب تغيير العقد إصداراً إضافياً متوافقاً للخلف أو endpoint وchannel وschema جديدة بإصدار صريح.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مسؤول هندسة البرمجيات | إنشاء أولي |
| 1.1.0 | 2026-07-16 | مسؤول هندسة البرمجيات | إضافة فهرس عقد قائمة إشعارات المستخدم |
