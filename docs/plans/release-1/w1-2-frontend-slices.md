---
doc_id: PLN-R1-W12-FE-001
title: سجل اكتمال واجهة W1.2
type: plans
status: accepted
version: 2.0.0
date: 2026-07-19
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: لا يراجع إلا عند انحدار W1.2
sources:
- docs/plans/release-1-platform.md
references:
- docs/contracts/api/w1-2.openapi.yaml
- docs/adr/009-unified-react-shell.md
---
# سجل اكتمال واجهة W1.2

هذا الملف سجل رجوع مختصر، وليس خطة عمل أو بوابة بدء. اكتملت W1.2 على `main`؛
لا توجد ملكيات ملفات أو عقود انتظار أو موافقات مطلوبة قبل W1.3.

## ما يعمل

- shell موحد ومسارات typed تدعم direct load وrefresh وback/forward و404.
- إدارة التجمع والمنشآت والوحدات والمناصب والأشخاص والتكليفات.
- إدارة دورة حساب Identity بلا خلط Person مع UserAccount.
- رفع CSV موقّع إلى MinIO، فحص ClamAV، ImportJob، وعرض أخطاء منقحة.
- عميل مولد من `w1-2.openapi.yaml` مع correlation وidempotency وETag/If-Match
  وProblem Details.
- العربية RTL والإنجليزية LTR، وحالات loading وempty وforbidden وstale وerror.

## الحدود المثبتة

- React لا يمنح صلاحية؛ كل طلب يعاد تفويضه في Laravel.
- Organization يملك Person وPII، وIdentity يستهلك `person_id` عبر عقد بلا FK أو join.
- mocks تختبر العرض فقط؛ العزل وإنهاء الجلسات والاستيراد يثبتها API/E2E الحقيقي.
- UTC في العقد وAsia/Riyadh في العرض.

## التحقق

```bash
make verify-w1-2
infra/dev/run-w1-2-e2e.sh
```

أي فشل لاحق يعالج كانحدار في الكود، ولا يعيد فتح خطة W1.2.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 2.0.0 | 2026-07-19 | استبدال عقد التخطيط التفصيلي بسجل اكتمال قابل للتحقق |
| 1.1.0 | 2026-07-18 | تجميد عقد الواجهة وتنفيذ W1.2 |
