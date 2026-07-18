---
doc_id: PLN-R3-001
title: خطة R3 السريعة – المخاطر والضوابط والمعالجة
type: plans
status: accepted
version: 2.0.0
date: 2026-07-19
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: أثناء اليوم الخامس من التنفيذ
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-2-strategy-portfolio.md
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
references:
- docs/domain/risk.md
- docs/domain/strategy.md
- docs/domain/portfolio-projects.md
---
# خطة R3 السريعة

## الهدف

بناء شريحة خطر متكاملة في اليوم الخامس: سجل وتقييم وضابط وخطة معالجة وKRI وروابط
R2. لا تسبقها ورش أو لجنة أو مواصفة معتمدة. القيم الافتراضية تنشأ كبيانات قابلة
للتهيئة ويبدأ التنفيذ مباشرة.

## الإعدادات الافتراضية

تستخدم النسخة الأولى هذه القيم، ويمكن تعديلها لاحقاً من الإعدادات دون تغيير schema:

- مصفوفة احتمال × أثر من 1 إلى 5.
- الدرجة = الاحتمال × الأثر.
- المستويات: Low ‏1–4، Medium ‏5–9، High ‏10–16، Critical ‏17–25.
- عتبة إنذار افتراضية 10 وعتبة حرجة 17.
- الفئات: operational وtechnical وfinancial وcompliance وstrategic وother.
- حالات الخطر: Draft وActive وTreated وAccepted وTransferred وAvoided وClosed.

هذه defaults تقنية وليست سياسة نهائية للمؤسسة؛ وجودها يمنع توقف البناء على قرار
بشري، وتبقى قابلة للتهيئة عندما تتوفر القيم الفعلية.

## حزم التنفيذ المتوازية

### R3-A Risk Register + Assessment

- RiskRegister وRisk مرتبطان بـ`organization_unit_id` ومعرف المسؤول عن المتابعة.
- مصدر الخطر مراجع IDs اختيارية لهدف أو مؤشر أو مشروع دون نسخ بياناتها.
- RiskAssessment يحفظ inherent وresidual وsnapshot زمني وسبب إعادة التقييم.
- soft delete فقط، والبحث يمر عبر `DecideAccess`.

القبول:

- CRUD كامل وعزل منشأتين.
- حساب الدرجة والمستوى صحيح عند حدود 4 و5 و9 و10 و16 و17.
- إعادة التقييم تترك snapshot ولا تعدل التاريخ.

### R3-B Controls + Treatment

- Control reusable بأنواع preventive وdetective وcorrective.
- ControlEffectiveness بقيمة weak أو moderate أو strong وتاريخ صلاحية.
- RiskControlLink بمعرفات داخل موديول Risk.
- TreatmentPlan بأنواع accept وmitigate وtransfer وavoid.
- mitigate يربط Tasks من R1 بالـIDs؛ اكتمال المهام يتيح إغلاق الخطة ثم إعادة التقييم.
- أفعال accept/transfer/avoid تخضع capability، لا توقيعاً أو لجنة.

القبول:

- ضعف الضابط أو انتهاء صلاحيته يرفع residual وفق القاعدة المنشورة.
- لا تغلق خطة mitigate قبل اكتمال المهام المرتبطة.
- capability غير كافية تمنع accept للخطر High أو Critical.

### R3-C KRI + R2 Links + Dashboard

- RiskIndicatorLink يستهلك `indicator_id` وmeasurement reference من Strategy؛ لا
  يعرّف مؤشراً أو ينسخ قراءة في Risk.
- تقييم العتبة يولد Outbox event وإشعار R1 مع deduplication.
- روابط objective/project تستخدم عقود IDs وread models فقط.
- Dashboard يعرض أكبر المخاطر حسب النطاق والمستوى واتجاه KRI.

القبول:

- قياس R2 يتجاوز العتبة فيولد تنبيهاً واحداً ويغير حالة لوحة R3.
- لا join بين Risk وStrategy أو PortfolioProjects.
- مستخدم منشأة لا يرى مخاطر أو عناوين منشأة أخرى.
- الانتقال من هدف أو مشروع إلى المخاطر المرتبطة يعمل عبر APIs منشورة.

## ترتيب اليوم الخامس

| الفترة | التنفيذ | الناتج |
|---|---|---|
| البداية | migrations والعقود واختبارات R3-A وR3-B وR3-C | ثلاث حزم مستقلة |
| الوسط | Domain وAPI وReact لكل حزمة | خطر وضابط ومعالجة وKRI تعمل منفصلة |
| النهاية | seed ورحلة النظام الكاملة | مؤشر R2 → KRI → خطر → مهمة معالجة → residual جديد |

## التحقق المستهدف

- اختبارات Risk للـCRUD والمصفوفة والتاريخ والضوابط والمعالجة.
- اختبارات event/inbox لإعادة التسليم ومنع التكرار.
- اختبار حدود R2/R3 ومنع FK وjoin والاستيراد العابر.
- بناء Web واختبارات RTL/LTR والعزل.
- E2E لرحلة R3، ثم رحلات R1 وR2 وR3 معاً.

## خارج R3 السريع

- اجتماعات وورش تحديد المصفوفة أو الشهية أو التصعيد.
- لجان قبول ومراجعات دورية وتوقيعات إطلاق.
- إدخال 100 خطر أو تدريب مستخدمين أو Tabletop يدوي.
- تصعيد تنظيمي متعدد المستويات؛ النسخة الأولى تستخدم capability + notification.
- مكتبة ضخمة للضوابط أو dashboards مخصصة لكل جهة.

## تعريف اكتمال R3 والنظام

- الرحلة المتكاملة من مؤشر R2 إلى KRI وخطر ومعالجة وإعادة تقييم تعمل محلياً.
- حدود R1/R2/R3 والعزل وOutbox مثبتة آلياً.
- رحلات R1 وR2 وR3 والبناء والتحليل والوثائق خضراء على revision واحد.
- يسجل revision النهائي في حالة التسليم، ثم تنتقل الخطة إلى التشغيل الآلي فقط.

## سجل التغيير

| الإصدار | التاريخ | التغيير |
|---|---|---|
| 2.0.0 | 2026-07-19 | إلغاء W3.0 واللجان وUAT وبدء R3 مباشرة بقيم افتراضية وشريحة يوم واحد |
| 1.0.0 | 2026-07-15 | الخطة الأصلية متعددة الموجات |
