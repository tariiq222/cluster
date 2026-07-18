---
doc_id: PLN-R1-W13-FE-001
title: بطاقة تنفيذ واجهة W1.3 – اليوم الأول
type: plans
status: accepted
version: 2.0.0
date: 2026-07-19
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: نهاية يوم التنفيذ
sources:
- docs/plans/release-1-platform.md
- docs/plans/active-delivery-status.md
references:
- docs/adr/009-unified-react-shell.md
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/contracts/api/openapi.yaml
- docs/engineering/vertical-slices.md
---
# بطاقة تنفيذ واجهة W1.3 – اليوم الأول

## الحالة والمخرج

W1.2 مكتملة ومثبتة على `main` بواسطة `make verify-w1-2` ورحلة
`infra/dev/run-w1-2-e2e.sh`. بدأت W1.3 في مساحات `work-1-3*`؛ مخرج اليوم الأول
هو شريحة Authorization تعمل من React إلى Laravel: قرار `DecideAccess` موحد
للموارد والحقول، وإدارة مختصرة للسياسات والتعيينات والعلاقات، وتفسير آمن للقرار.

هذه بطاقة تنفيذ لليوم الحالي وليست عقد انتظار أو خطة مستقبلية.

## النطاق الضروري

- Role وCapability وRoleAssignment وDelegation وExplicitDeny.
- SupervisoryRelationship وRelationshipCapability بمدة ونطاق واضحين.
- ClassificationPolicy وFieldAccessTemplate وSensitiveAccessEvent.
- `DecideAccess` و`ExplainAccessDecision` مع نتيجة واحدة للـAPI والبحث والتقرير
  والتصدير والتنزيل.
- صفحة React إدارية صغيرة تعرض القائمة، التعيين/التفويض، والعلاقة، وتفسير قرار
  لمورد مسموح فقط. موافقات Workflow التي تظهر في المنتج وظيفة أعمال؛ لا تشكل
  بوابة تطوير لهذه الشريحة.

خارج النطاق: قرار الصلاحية داخل المتصفح، تطبيق إداري مستقل، أو كشف مورد/حقل أخفاه
الخلف. لا تستخدم الواجهة mock لإثبات العزل أو صلاحية الحقل أو التدقيق.

## العقود الصغيرة ومسارات React

يبقى shell الموحد وroute registry في `apps/web/src/app/**` و`apps/web/src/shell/**`؛
تضاف تفاصيل الشريحة في `apps/web/src/features/authorization/**` و
`apps/web/src/features/organization-relationships/**`، ويعزل النقل في
`apps/web/src/api/w1-3/**`. المسارات المقصودة:

- `/admin/authorization/roles`
- `/admin/authorization/capabilities`
- `/admin/authorization/role-assignments`
- `/admin/authorization/delegations`
- `/admin/relationships/supervisory`
- `/admin/authorization/explain`

كل طلب يعيد التفويض في الخلف ويحمل `X-Correlation-ID`، وتستخدم الأوامر
`Idempotency-Key` و`If-Match` عندما ينص العقد. أخطاء `401/403/404/409/412/422`
تظهر بحالات واجهة واضحة دون تسريب وجود المورد أو الحقل. العربية `ar-SA`/RTL هي
الافتراضية ونظير `en-GB`/LTR مطلوب؛ حالات loading وempty وforbidden وnot-found
وconflict وstale وunexpected-error جزء من كل مسار.

## الاختبارات والإغلاق الآلي

الاختبارات الخلفية المستهدفة الموجودة:

```bash
cd apps/api
php artisan test Modules/Authorization/Tests Modules/Organization/Tests/SupervisoryRelationshipTest.php
```

والويب الموجود والقابل للتوسعة:

```bash
npm --prefix apps/web run test:unit -- src/shell/routes.test.ts src/api.test.ts src/w1-2-api.test.ts
npm --prefix apps/web run build
make verify-boundaries
```

تضاف رحلة `apps/web/e2e/w1-3-authorization.spec.ts` إلى إعداد Playwright القائم
لتثبت direct load وrefresh وRTL/LTR وaxe، والسماح والمنع والتفسير عبر API حقيقي.
يكتمل اليوم عندما تعمل الرحلة من React إلى Laravel وتخضر الاختبارات المستهدفة
والحدود والبناء؛ عند الفشل يصلح الكود مباشرة.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 2.0.0 | 2026-07-19 | تحويل العقد المقترح إلى بطاقة تنفيذ W1.3 لليوم الأول وتثبيت اكتمال W1.2 وبداية العمل في `work-1-3*` |
| 1.0.0 | 2026-07-18 | عقد شرائح الواجهة الأولي |
