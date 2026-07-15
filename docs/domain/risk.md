---
doc_id: DOM-RSK-001
title: المخاطر والضوابط والمعالجة
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Risk
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/022-portfolio-projects-and-risk-boundaries.md
- docs/architecture/dependency-rules.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/plans/release-3-risk.md
- docs/architecture/context-map.md
---
# المخاطر والضوابط والمعالجة

## الغرض والنطاق

يمتلك `Risk` سجل المخاطر والضوابط والتقييمات الكامنة والمتبقية وخطط المعالجة، وروابط مؤشرات المخاطر وعتباتها وتنبيهاتها وتصعيدها. يملك `Strategy` وحده تعريف كل مؤشر وقياساته؛ يحتفظ `Risk` بـ`indicator_id` وتهيئة العتبة وحالة التنبيه فقط، ولا ينسخ التعريف أو القراءة. يربط الخطر أيضاً بمعرف هدف من Strategy ومشروع من PortfolioProjects. مصفوفة الاحتمالية والأثر، الشهية، الحدود، ودورية المراجعة بيانات versioned معتمدة قبل تفعيل السجل.

خارج النطاق: تعريف أي مؤشر، أو إدخال قياسه أو اعتماده، أو امتلاك المشروع أو المهمة أو المستند أو محرك الموافقات. تستخدم خطة المعالجة `Tasks`، والأدلة `Documents`، والقبول والتصعيد `Workflow`، وتستهلك عتبات KRI القياسات المعتمدة من `Strategy` عبر العقد والأحداث.

## الكيانات والجداول

| الجدول | الكيان | القيود والفهارس |
|---|---|---|
| `risk_registers` | سجل خطر لنطاق Organization | فريد `(owner_organization_unit_id, code)` |
| `risk_policy_versions` | مصفوفة  الاحتمال/الأثر، الشهية، الحدود ودورية المراجعة | منشور immutable؛ إصدار واحد فعال للنطاق والفترة |
| `risks` | الخطر، مالكه، الفئة، المصدر، التصنيف والحالة | `risk_number` فريد؛ فهارس المالك والحالة وموعد المراجعة |
| `risk_assessments` | تقييم كامن أو متبق، snapshot للسياسة والنتيجة | فريد `(risk_id, assessment_kind, assessment_sequence)`؛ لا تعديل لتقييم معتمد |
| `risk_controls`, `risk_control_links`, `control_effectiveness_reviews` | مكتبة الضوابط وربطها وفعاليتها | الضابط يمكن ربطه بأكثر من خطر؛ فعالية منتهية لا تدخل التقييم |
| `risk_treatments`, `risk_treatment_tasks` | قبول/تخفيف/نقل/تجنب وخطط المهام | مهمة واحدة مرجعية لكل ربط؛ لا تغلق خطة تخفيف قبل حسم مهامها |
| `risk_indicator_links`, `risk_indicator_thresholds`, `risk_indicator_alerts` | ربط `indicator_id` بالخطر أو الضابط، والعتبات وحالة التنبيه | لا تعريف أو قياس محلي؛ فريد للرابط وإصدار العتبة؛ فهرس حالة التنبيه |
| `risk_links`, `risk_activities` | روابط Strategy/Portfolio والنشاط append-only | الرابط معرف فقط؛ سبب إلزامي لفك الرابط |

## الأوامر والاستعلامات والأحداث

**Commands:** `PublishRiskPolicyVersion`, `CreateRiskRegister`, `CreateRisk`, `AssessRisk`, `RegisterControl`, `LinkControlToRisk`, `ReviewControlEffectiveness`, `PlanRiskTreatment`, `LinkTreatmentTask`, `AcceptRisk`, `LinkRiskIndicator`, `ConfigureRiskIndicatorThreshold`, `EscalateCriticalRisk`, `LinkRiskReference`, `CloseRisk`.

**Queries:** `GetAuthorizedRisk`, `ListAuthorizedRisks`, `GetRiskSummary`, `GetAuthorizationRecordFacts`, `ListDueReviews`, `GetRiskHeatmap`, `GetControlSummary`, `GetTreatmentStatus`, `ListRiskIndicatorThresholds`, `ListRiskIndicatorAlerts`.

**Events:** `RiskPolicyVersionPublished`, `RiskCreated`, `RiskAssessed`, `ControlRegistered`, `ControlEffectivenessReviewed`, `RiskTreatmentPlanned`, `RiskIndicatorThresholdBreached`, `CriticalRiskEscalated`, `RiskAccepted`, `RiskClosed`.

كل تغيير يكتب النشاط وOutbox في معاملة Risk؛ الأحداث لا تتضمن وصف الخطر أو دليلًا أو قيمة محجوبة.

## الحالات

```text
Risk: Draft -> Active -> UnderReview -> Closed
Treatment: Draft -> Active -> Completed | Cancelled
Assessment: Draft -> Submitted -> Approved | Returned
ControlReview: Scheduled -> Submitted -> Approved | Expired
```

## الثوابت

- الخطر يملك نطاقاً تنظيمياً ومالكاً ومراجعة تالية، ويستخدم نسخة سياسة منشورة مثبتة عند التقييم.
- التقييم الكامن يسبق المتبقي؛ المتبقي لا يعتمد ضابطاً منتهياً أو غير فعال بلا استثناء موثق.
- درجة ومستوى الخطر يحسبان في الخادم من مصفوفة السياسة، ولا يقبلان من العميل.
- قبول خطر فوق الشهية يحتاج مسار قبول منشور وصاحب قرار محلول؛ لا override إداري.
- علاج `mitigate` لا يكتمل قبل حسم المهام والأدلة المطلوبة، ثم يطلب إعادة تقييم صريحة.
- الخطر يربط هدفاً أو مؤشراً أو مشروعاً بمعرف فقط؛ لا ينسخ اسماً أو حالة أو قياساً.
- يتلقى Risk قياس KRI المعتمد من Strategy بمرجع `indicator_id` و`measurement_id`، ويقيّم عتبته دون تخزين نسخة من القياس أو دليله.
- تجاوز حد KRI أو خطر حرج يصدر حدث تصعيد؛ الإشعار داخل التطبيق فقط ولا يغير مستوى الخطر تلقائياً.

## الأمن والفشل

ينشئ Risk `AuthorizationRecordFacts` ويقدمها دون قرار محلي، ثم يطلب قرار Authorization لكل قراءة أو كتابة. رؤية heatmap أو قيمة مجمعة لا تكشف وصف خطر أو تقييم منشأة دون `view_details`. المستندات وروابطها مقيدة بأشد القيود التي يطبقها Authorization، والبحث والتقرير يعيدان التفويض قبل العرض أو التصدير.

غياب سياسة منشورة، مرجع خارجي غير صالح، تعذر حل المعتمد، أو تعارض `lock_version` يمنع الأمر. لا يستبدل السوبر أدمن مالك الخطر أو معتمد القبول. فشل Outbox يلف تغيير الخطر؛ فشل إشعار أو إسقاط بعد commit يعاد بطريقة idempotent.

## الاختبارات

- حساب مصفوفة السياسة والشهية والتقييم الكامن/المتبقي من نسخة ثابتة.
- ضابط منتهٍ أو ضعيف لا يخفض الخطر المتبقي بلا مراجعة معتمدة.
- خطة تخفيف لا تغلق قبل المهام، وقبول فوق الشهية يفشل لفاعل غير محلول أو أدمن override.
- عزل منشأة عن أخرى، وعدم كشف تفاصيل من قراءة مجمعة أو نتيجة بحث/تقرير.
- عقد روابط Strategy وPortfolioProjects يستخدم المعرفات فقط، والأحداث والتصعيد idempotent.
- اختبار حدود يثبت غياب تعريفات المؤشرات وقياساتها وقراءاتها من جداول وأوامر Risk.

## الاعتماديات

يعتمد على Organization وStrategy وPortfolioProjects وWorkflow وTasks وCollaboration وDocuments وRecordsGovernance وAuthorization وAudit و`PlatformSettings` للتقويم التشغيلي. لا يعتمد على الموديولات المشتقة؛ Notifications وSearch وReporting وWorkspace تستهلك أحداثه فقط.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Risk | إنشاء المواصفة المعتمدة |
