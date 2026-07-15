# خارطة الطريق: منصة التجمع الصحي الثالث

## Overview

تُنفّذ المنصة في تسلسل الموجات الخمس والعشرين المعتمد دون دمج أو إعادة ترتيب. كل مرحلة تنفيذية تسلّم قدرة رأسية قابلة للنشر عبر واجهة React وواجهة Laravel ومالك البيانات ومسار Outbox والتشغيل المعزول، بينما تبقى W3.0 بوابة مواصفة وحوكمة بلا كود. الأمن والعزل والتوطين والأداء والاستعادة والتتبع شروط خروج تراكمية، وتمنع بوابات W1.10 وW2.7 وW3.0 الانتقال إلى الإصدار التالي ما لم تُغلق أو يُسجّل استثناء الراعي المسموح به صراحة.

## Phases

- [ ] **Phase 1: W1.1 Walking Skeleton** - مسار دائم من الواجهة إلى البيانات والإشعار يعمل ويُنشر كلياً دون إنترنت.
- [ ] **Phase 2: W1.2 Organization + Identity + Import** - هيكل تنظيمي وهوية محلية واستيراد محكوم قابل للاستخدام.
- [ ] **Phase 3: W1.3 Authorization + العلاقات الإشرافية** - قرارات RBAC+ABAC مفسّرة وعزل تنظيمي شامل.
- [ ] **Phase 4: W1.4 WorkDefinitions + منشئ النماذج** - تعريفات أعمال ونماذج منشورة بإصدارات ثابتة دون كود.
- [ ] **Phase 5: W1.5 Workflow Engine** - مسارات اعتماد وإعادة وتصعيد وتفرع تعمل بإصدارات مثبتة.
- [ ] **Phase 6: W1.6 WorkRecords: الطلب الداخلي العام** - طلب داخلي عام كامل من المسودة إلى الإغلاق.
- [ ] **Phase 7: W1.7 Tasks** - مهام مسؤولة وتعاون وتعليقات ومنشن بدورة حياة كاملة.
- [ ] **Phase 8: W1.8 Documents + Notifications** - مستندات مصنفة بإصدارات وإشعارات داخلية متينة.
- [ ] **Phase 9: W1.9 Search + Reporting + لوحات** - بحث وتقارير وتصدير ولوحات محكومة بالصلاحيات.
- [ ] **Phase 10: W1.10 UAT + إطلاق R1** - تجربة R1 الميدانية وبوابة إطلاق خضراء بالأدلة الكمية.
- [ ] **Phase 11: W2.1 Strategy foundation** - خطط ومحاور وأهداف ومبادرات استراتيجية بإصدارات محكومة.
- [ ] **Phase 12: W2.2 Indicators** - مؤشرات ومستهدفات موزعة وقراءات مدعومة بالأدلة ومعتمدة.
- [ ] **Phase 13: W2.3 Portfolio + Program + Project** - محافظ وبرامج ومشاريع بقوالب مثبتة وبوابات محكومة.
- [ ] **Phase 14: W2.4 قوالب مشاريع التحسين** - قوالب PDSA وDMAIC وFOCUS-PDCA وقالب داخلي قابلة للتشغيل.
- [ ] **Phase 15: W2.5 الإنجاز والصحة والميزانية** - إنجاز بالأدلة وصحة حارسة وميزانية إدارية قابلة للقياس.
- [ ] **Phase 16: W2.6 ربط الأثر** - أثر مشروع معتمد ومحدود بالتحسن المرصود للمؤشر.
- [ ] **Phase 17: W2.7 UAT + إطلاق R2** - تجربة استراتيجية إلى أثر وبوابة إطلاق R2 خضراء.
- [ ] **Phase 18: W3.0 مواصفة المخاطر** - مواصفة مخاطر وقرارات حوكمة وحالات ذهبية معتمدة قبل أي كود R3.
- [ ] **Phase 19: W3.1 سجل المخاطر** - سجلات مخاطر محكومة بالنطاق والملكية ودورة الحياة.
- [ ] **Phase 20: W3.2 التقييم** - تقييمات كامنة ومتبقية قابلة لإعادة الحساب والتتبع.
- [ ] **Phase 21: W3.3 مكتبة الضوابط** - ضوابط قابلة لإعادة الاستخدام وفعالية تؤثر في المخاطر المتبقية.
- [ ] **Phase 22: W3.4 خطط المعالجة** - قرارات معالجة وخطط تخفيف تنفّذ عبر المهام المشتركة.
- [ ] **Phase 23: W3.5 مؤشرات المخاطر والتصعيد** - KRI وعتبات وتنبيهات وتصعيد ولوحات نطاقية.
- [ ] **Phase 24: W3.6 ربط بالأهداف والمشاريع** - روابط مخاطر ثنائية الاتجاه مع الاستراتيجية والمشاريع دون نسخ الحقيقة.
- [ ] **Phase 25: W3.7 UAT + إطلاق R3** - سجل مخاطر تشغيلي وبوابة R3 النهائية وما بعد R3.

## Phase Details

### Phase 1: W1.1 Walking Skeleton
**Goal:** يمتلك المستخدمون والمشغلون مساراً دائماً قابلاً للنشر من React إلى Laravel وMySQL وOutbox والإشعار داخل البيئة المعزولة.
**Mode:** mvp
**Depends on:** Nothing (first phase)
**Requirements:** FR-R1-013, SEC-R1-001, SEC-R1-009, SEC-R1-011, OPS-R1-006, OPS-R1-007, OPS-R1-008, OPS-R1-011, OPS-R1-012
**Entry gate:** تُحسم قبل البدء توزيعة Kubernetes والتخزين، ومصفوفة MySQL server/Router/Shell/Operator/backup، وتوافق أداة التطبيق، وإدخال القطع الأثرية والتوقيع وحيازة المفاتيح، ونموذج تشغيل المنصة/SRE/الأمن؛ وإلا لا تُثبّت الصور الأساسية ولا يبدأ مسار النشر الدائم.
**Exit gate:** الرحلة الدائمة واختبارات العزل والحدود والبناء المعزول والنشر والرجوع خضراء؛ لا يُقبل مسار مؤقت أو اتصال خارجي.
**Success Criteria** (what must be TRUE):
  1. يستطيع مستخدم تسجيل الدخول من الواجهة العربية الافتراضية أو الإنجليزية مع RTL/LTR، وإنشاء مثيل `request` منشور في كل من جهتين، ولا يرى مثيل الجهة الأخرى.
  2. يظهر الإشعار الداخلي بعد تثبيت الطلب، وتتحمل إعادة تشغيل العامل دون فقد الحدث أو تكرار أثره.
  3. يستطيع المشغل بناء صورة من مخازن مؤقتة نظيفة، والتحقق من توقيعها وSBOM، ونشرها عبر GitOps ثم الرجوع عنها دون إنترنت أو egress.
  4. تفشل آلياً أي محاولة join أو استيراد Infrastructure بين موديولات الأعمال، وتبقى بيئات التطوير والاختبار والتجريب والإنتاج معزولة.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4–5,8؛ `cluster/docs/plans/release-1-platform.md` §3 W1.1؛ `cluster/docs/adr/018-air-gapped-supply-chain.md`؛ `cluster/docs/adr/019-kubernetes-resilience-and-recovery.md`

### Phase 2: W1.2 Organization + Identity + Import
**Goal:** يستطيع المسؤولون إدارة الحقيقة التنظيمية والهوية المحلية وإدخال الهيكل بأمان، ويستطيع المستخدم العمل ضمن نطاقه الفعّال.
**Mode:** mvp
**Depends on:** Phase 1
**Requirements:** FR-R1-001, FR-R1-002, FR-R1-017, FR-R1-018, FR-R1-019, FR-R1-020, SEC-R1-002, SEC-R1-006, SEC-R1-007, SEC-R1-008, SEC-R1-014
**Entry gate:** W1.1 خضراء، وسياسات الحسابات والتشفير والوقت وأنواع الهيكل محددة ضمن الحدود المعتمدة.
**Exit gate:** تنجح Invariants الهيكل الأربعة عشر والاستيراد بالموافقة المزدوجة واختبارات انتهاء السلطة وتعطيل الحساب.
**Success Criteria** (what must be TRUE):
  1. يستطيع مسؤول مخوّل إنشاء شجرة تجمع/منشأة/وحدة متعددة الأعماق، ويمنع النظام الدورات ونقل الوحدة إلى نسلها برسالة مفهومة.
  2. يستطيع مسؤولان مختلفان التحقق من استيراد CSV/XLSX واعتماده، ولا يطبق النظام أي صف عند وجود خطأ حرج.
  3. يستطيع المستخدم تفعيل حساب محلي واستعادته وفق سياسة كلمة المرور والقفل، ولا يستطيع أي مسؤول قراءة كلمة مروره.
  4. يؤدي تعطيل الحساب أو انتهاء التكليف المؤقت إلى إنهاء الجلسات وسحب التفويضات فوراً، مع تشفير PII وإظهار Asia/Riyadh فوق تخزين UTC.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W1.2؛ `cluster/docs/plans/release-1-platform.md` §3 W1.2؛ `cluster/docs/domain/organization-and-people.md`؛ `cluster/docs/data-security/logical-data-model.md`

### Phase 3: W1.3 Authorization + العلاقات الإشرافية
**Goal:** يحصل كل مستخدم على قرار وصول خلفي موحد ومفسّر يطبق الدور والنطاق والعلاقة والتفويض والتصنيف والحقل دون تسرب بين الجهات.
**Mode:** mvp
**Depends on:** Phase 2
**Requirements:** FR-R1-003, FR-R1-004, FR-R1-014, FR-R1-015, SEC-R1-003, SEC-R1-004, SEC-R1-005, SEC-R1-013
**Entry gate:** حقائق المنظمة والهوية الزمنية مستقرة، ومصفوفة النطاقات والتصنيف والقدرات معتمدة للاختبار.
**Exit gate:** تنجح سيناريوهات العزل الأربعة عشر ومصفوفة الرفض عبر API والقوائم، مع تفسير وتدقيق قابل للتحقق.
**Success Criteria** (what must be TRUE):
  1. لا يستطيع مستخدم في إدارة أو مستشفى قراءة سجل جهة أخرى، بينما يرى صاحب العلاقة الإشرافية فقط النطاق والحقول الممنوحة صراحة.
  2. يستطيع المستخدم المخوّل رؤية تفسير قرار السماح أو المنع، ويغلب المنع الصريح والتصنيف الأعلى أي سماح عام.
  3. ينتهي أثر العلاقة أو التفويض عند نهاية نافذته، ويظهر الإجراء المنفذ بالنيابة باسمَي المفوِّض والمنفَّذ.
  4. يُسجل كل تغيير حالة أو عمل حساس وكل وصول لمحتوى سري في سجل تدقيق معزول ذي Hash Chain قابل للتحقق؛ ولا تمنح JavaScript أي صلاحية.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W1.3؛ `cluster/docs/plans/release-1-platform.md` §3 W1.3؛ `cluster/docs/adr/004-authorization-and-isolation.md`؛ `cluster/docs/data-security/authorization-model.md`

### Phase 4: W1.4 WorkDefinitions + منشئ النماذج
**Goal:** يستطيع المسؤول إنشاء نوع عمل ونموذجه وحقوله وعلاقاته واختباره ونشر إصدار موقّع دون كود ودون تغيير السجلات الجارية.
**Mode:** mvp
**Depends on:** Phase 3
**Requirements:** FR-R1-005, SEC-R1-010
**Entry gate:** قرار الوصول والحقول والتصنيف يعمل عبر الخلفية، ولغة التعريف المقيدة وسياسة التوقيع معتمدتان.
**Exit gate:** قبل نشر أول نوع عمل في الإنتاج تُعتمد سياسة الاحتفاظ والإتلاف الخاصة به (سبع سنوات افتراض فقط لا قرار نهائي)؛ وإلا يقتصر النشر على sandbox/staging.
**Success Criteria** (what must be TRUE):
  1. يستطيع مسؤول إنشاء نوع عمل من الواجهة، وتعريف نماذج الإنشاء والعرض والقائمة والحقول والعلاقات وقواعد التحقق دون كود تنفيذي.
  2. يستطيع المسؤول تجربة النوع في Sandbox منفصل ثم توقيع الحزمة والتحقق منها ونشر إصدار ثابت صالح للاستخدام.
  3. يبقى سجل مثبت على الإصدار الأول دون تغيير عند نشر إصدار ثانٍ، ولا يمكن حذف حقل مستخدم أو ترحيل سجل جارٍ بصمت.
  4. لا يرى المستخدم حقلاً مخفياً أو يعدل حقلاً للقراءة فقط وفق دوره وحالة السجل.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4,8 W1.4؛ `cluster/docs/plans/release-1-platform.md` §3 W1.4؛ `cluster/docs/adr/005-work-records-dynamic-data.md`

### Phase 5: W1.5 Workflow Engine
**Goal:** تعمل للمستخدمين مسارات منشورة تدعم الموافقة والرفض والإعادة والتصعيد والتفرع والتوازي دون كسر المعاملات الجارية.
**Mode:** mvp
**Depends on:** Phase 4
**Requirements:** FR-R1-006, OPS-R1-009, OPS-R1-010
**Entry gate:** تُحسم منتجات المراقبة والسجلات الداخلية وخط نشرها وسعتها المدعومة قبل تشغيل المؤقتات والعمال؛ وإلا لا تُقبل بوابة التشغيل.
**Exit gate:** تنجح سيناريوهات المسار العشرة، والجدولة بقيادة واحدة، وإعادة المحاولة idempotent، ومراقبة الأخطاء والتنبيه خلال 5 دقائق.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم اعتماد الطلب أو رفضه أو إعادته بسبب إلزامي، وتصل القرارات الفردية/الكل/أي واحد/الأغلبية/النصاب إلى النتيجة الصحيحة.
  2. تنفذ الفروع الآمنة والموافقات المتوازية والدمج والبديل للشاغر كما نُشرت، ويصعّد النظام الخطوة عند تجاوز المهلة.
  3. تبقى المعاملة الجارية مثبتة على إصدار مسارها عند نشر إصدار جديد، ولا تحدث هجرة إلا بفحص توافق وطلب صريح قابل للرجوع.
  4. يستطيع المشغل رؤية السجلات المركزية والتوفر والأخطاء وتأخر Outbox، ويتلقى تنبيهاً خلال 5 دقائق دون فقد معاملة عند فشل مستهلك.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4,8 W1.5؛ `cluster/docs/plans/release-1-platform.md` §3 W1.5؛ `cluster/docs/adr/006-workflow-versioning.md`؛ `cluster/docs/adr/007-transactional-outbox.md`؛ `cluster/docs/architecture/c4-and-flows.md`

### Phase 6: W1.6 WorkRecords: الطلب الداخلي العام
**Goal:** يستطيع الموظف إكمال رحلة طلب داخلي عام داخل سجل رقمي محكوم من المسودة حتى الإغلاق.
**Mode:** mvp
**Depends on:** Phase 4, Phase 5
**Requirements:** FR-R1-007, SEC-R1-012, OPS-R1-004, OPS-R1-005
**Entry gate:** قبل أول نوع إنتاج وبيانات حقيقية تُعتمد سياسة الاحتفاظ، ومخزن نسخ مستقل مشفر يدعم WORM، واسترداد المفاتيح وmanifest الاستعادة، وتنجح استعادة معزولة؛ وإلا لا تُقبل بيانات حقيقية.
**Exit gate:** تنجح رحلات الطلب الثلاث، والتعارض المتفائل، واتساق الإصدار/Outbox/audit، ويصبح اختبار الاستعادة الموثق قابلاً للتكرار شهرياً.
**Success Criteria** (what must be TRUE):
  1. يستطيع الموظف إنشاء مسودة وإرسالها ومتابعتها عبر الاعتماد أو الإعادة أو الرفض أو المعالجة حتى الإغلاق، مع نشاط كامل وإصدار تعريف ومسار مثبتين.
  2. تعمل الرحلة بين إدارتي تجمع، وداخل مستشفى، ومن التجمع إلى مستشفى مع عزل النطاق والحقول في كل خطوة.
  3. يرى الموظف «طلباتي» وترى الإدارة صندوقها، ويمنع النظام تعديل السجل المغلق أو الكتابة فوق تحديث متزامن دون تنبيه.
  4. يستطيع المشغل استعادة قاعدة البيانات والأصول والمفاتيح والتكوين من نسخ مشفرة خارج Kubernetes في بيئة معزولة، مع دليل شهري قابل للتدقيق.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4,8 W1.6؛ `cluster/docs/plans/release-1-platform.md` §3 W1.6؛ `cluster/docs/adr/005-work-records-dynamic-data.md`؛ `cluster/docs/operations/ha-dr-backup.md`

### Phase 7: W1.7 Tasks
**Goal:** يستطيع المستخدمون إنشاء مهام مستقلة أو مرتبطة، وتحديد المسؤول والمشاركين والتعاون عليها حتى الإغلاق المحكوم.
**Mode:** mvp
**Depends on:** Phase 6
**Requirements:** FR-R1-008
**Entry gate:** رحلة WorkRecord والعزل والتفويض مستقرة، وحدود ملكية Tasks وCollaboration وDocuments واضحة.
**Exit gate:** تنجح دورة المهمة وحالات الإسناد النظامي وخارج التسلسل والمنشن والإغلاق الخاص بالنوع.
**Success Criteria** (what must be TRUE):
  1. يستطيع مدير إسناد مهمة لمسؤول واحد وإضافة مساهمين أو مراقبين، وتظهر المهمة في صندوق المسؤول مع موعدها وحالتها.
  2. يستطيع المشاركون التعليق والمنشن وإرفاق دليل، وينضم المذكور كمشارك دون أن يكتسب مسؤولية أو قدرة على سجل المصدر.
  3. يرفض النظام الإسناد خارج التسلسل بلا قدرة خاصة، ويطبق قاعدة الإغلاق والاعتماد الخاصة بنوع المهمة.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W1.7؛ `cluster/docs/plans/release-1-platform.md` §3 W1.7؛ `cluster/docs/architecture/module-catalog.md`

### Phase 8: W1.8 Documents + Notifications
**Goal:** يستطيع المستخدم حفظ مستندات مصنفة بإصدارات آمنة وتلقي إشعارات داخلية متينة تقوده فقط إلى محتوى ما زال مخولاً له.
**Mode:** mvp
**Depends on:** Phase 7
**Requirements:** FR-R1-009, FR-R1-010
**Entry gate:** يُتحقق من object storage والماسح وتحديث التواقيع داخل الفجوة الهوائية وسلوك الحجر والاستعادة.
**Exit gate:** ينجح سيناريو المستند السري وفشل الإشعار وإعادة المحاولة وDLQ واتساق نسخ قاعدة البيانات والكائنات.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم رفع ملف وتصنيفه وربطه بسجل، ولا يصبح متاحاً قبل الفحص، ويحمل كل إصدار checksum وحجماً وMIME محفوظة.
  2. لا تتجاوز صلاحية المستند أشد قيود سجلاته المرتبطة، ويعاد التحقق عند التنزيل ويُسجل الوصول السري.
  3. تصل الإشعارات الداخلية بعد Commit بحالة قراءة وتجميع مفهوم، ولا يؤدي تعطل العامل أو replay إلى فقدها أو تكرار أثرها.
  4. يعيد رابط الإشعار فحص الصلاحية ويمنع كشف عنوان أو محتوى سجل لم يعد المستخدم مخولاً له.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W1.8؛ `cluster/docs/plans/release-1-platform.md` §3 W1.8؛ `cluster/docs/adr/007-transactional-outbox.md`؛ `cluster/docs/data-security/logical-data-model.md`

### Phase 9: W1.9 Search + Reporting + لوحات
**Goal:** يجد المستخدم المصرح له عمله ويعرض لوحته وتقاريره ويصدرها دون تسرب عنوان أو عدد أو حقل محظور.
**Mode:** mvp
**Depends on:** Phase 8
**Requirements:** FR-R1-011, FR-R1-012, FR-R1-016
**Entry gate:** corpus عربي تمثيلي وحقائق صلاحية مشتقة وخطة rebuild/freshness واختبارات عدم تسرب metadata جاهزة.
**Exit gate:** ينجح البحث المحدود ولوحة المسؤول وإعادة بناء read models واختبار الصلاحية عند الحجم المستهدف.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم البحث بالكلمات والمرشحات، ولا تظهر له عناوين أو snippets أو أعداد أو facets لسجلات وحقول محظورة.
  2. يرى كل دور لوحة قابلة للضبط ضمن نطاقه، ويغير محدد النطاق الأرقام والقوائم دون كشف تفاصيل غير مخولة.
  3. يستطيع المستخدم تشغيل تقرير وتصديره، وتظل الحقول المخفية والمقنعة محمية في الملف كما هي في الشاشة.
  4. يستطيع المشغل إعادة بناء فهارس البحث والتقارير والـWorkspace deterministically ورؤية freshness/lag دون جعلها مصدر الحقيقة.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W1.9؛ `cluster/docs/plans/release-1-platform.md` §3 W1.9؛ `cluster/docs/adr/004-authorization-and-isolation.md`؛ `cluster/docs/architecture/non-functional-requirements.md`

### Phase 10: W1.10 UAT + إطلاق R1
**Goal:** تعمل منصة R1 فعلياً في إدارة بالتجمع وإدارة نظيرة بمستشفى وتثبت بالأدلة الجاهزية الوظيفية والأمنية والتشغيلية والميدانية للإطلاق.
**Mode:** mvp
**Depends on:** Phase 9
**Requirements:** NFR-R1-001, NFR-R1-002, NFR-R1-003, NFR-R1-004, NFR-R1-005, NFR-R1-006, NFR-R1-007, NFR-R1-008, OPS-R1-001, OPS-R1-002, OPS-R1-003
**Entry gate:** لا feature catch-up؛ جميع موجات W1.1–W1.9 وبواباتها وأدلة تتبعها مكتملة، وبيئة التجربة مصنفة وموافق عليها.
**Exit gate:** تُحسم إعادة التصنيف الأمني قبل تعميم R1، وتكون جميع أدلة R1 خضراء؛ لا يبدأ R2 ولا يتوسع التشغيل خارج التجربة عند أي فشل إلا باستثناء راعٍ صريح ومسجل حيث تسمح الحوكمة.
**Success Criteria** (what must be TRUE):
  1. تكتمل تجربة 4 أسابيع في الإدارتين بـ80–150 مستخدماً و500–1,500 حساب مهيأ و≥50 طلباً فعلياً و≥100 مهمة و≥200 مستند متعدد النسخ، بالعربية والإنجليزية وRTL/LTR بجودة متساوية.
  2. يحقق المستخدمون P95 لدورة الطلب المرجعي ≤5 أيام عمل، وينشر المسؤولون ≥5 أنواع أعمال دون كود، ويبلغ القبول الميداني ≥90% وأدلة الاستخدام المكتملة ≥80%.
  3. تُطبق 100% من قرارات الوصول في Laravel، ويكون التسرب بين الإدارتين والثغرات الحرجة المفتوحة صفراً، وينجح الاختبار الخارجي، وتبلغ تغطية اختبارات حدود الموديولات ≥90%.
  4. تثبت الاختبارات P95 للقراءة ≤1.5s وP99 للطلبات القصوى ≤3s ودعم 20,000 حساب و2,000 مستخدم متزامن وتوفر شهري ≥99.5% ونشر ≤30 دقيقة ونسخ يومي ≤60 دقيقة وRPO ≤15 دقيقة وRTO ≤ساعتين مع تنبيه الفشل خلال 5 دقائق.
  5. تنجح قائمة الجاهزية الوظيفية/الأمنية/التشغيلية/البيانات/الاستعادة/الفجوة الهوائية/التوطين/التدريب 100%، وتكون نتيجة Go/No-Go ≥90 بلا أحمر ولا قسم وظيفي أو أمني دون 80، ثم يوقع ملاك الإطلاق قرار Go.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4–8 W1.10؛ `cluster/docs/plans/release-1-platform.md` §§3 W1.10,5؛ `cluster/docs/plans/readiness-checklist.md` §§2.1,3–12؛ `cluster/docs/product/success-metrics.md`

### Phase 11: W2.1 Strategy foundation
**Goal:** يستطيع مالكو الاستراتيجية إدارة خطة ذات محاور وأهداف ومبادرات محكومة بالإصدار والاعتماد دون خلطها بالمشاريع.
**Mode:** mvp
**Depends on:** Phase 10
**Requirements:** FR-R2-001
**Entry gate:** بوابة R1 خضراء أو استثناء الراعي الصريح المسجل، وعقد Strategy مع المشاريع محدد دون تنفيذ PortfolioProjects مسبقاً.
**Exit gate:** تعمل دورة الخطة والمبادرة كاملة وتُثبت حدود الملكية والروابط بالمعرفات فقط.
**Success Criteria** (what must be TRUE):
  1. يستطيع مالك الاستراتيجية إنشاء إصدار خطة بمحاور وأهداف ومبادرات وملاك، وتمريره من Draft إلى Approved ثم Active وRetired عبر Workflow.
  2. يرى المستخدم كل مبادرة تحت محور وهدف صحيحين، ويُرفض الربط الهرمي المزدوج المخالف.
  3. تظهر المبادرة المرتبطة لاحقاً بمشروع في السجلين عبر عقد ومعرف دون تكرار الحقيقة، ويُسجل سبب كل اعتماد أو تعديل.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.1؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.1؛ `cluster/docs/adr/021-strategy-indicator-ownership.md`

### Phase 12: W2.2 Indicators
**Goal:** يستطيع ملاك المؤشرات تعريف مؤشرات بإصدارات، وتوزيع المستهدفات، وإدخال قراءات بأدلة واعتمادها وقفل فتراتها.
**Mode:** mvp
**Depends on:** Phase 11, Phase 9
**Requirements:** FR-R2-002, FR-R2-003, FR-R2-004, SEC-R2-001
**Entry gate:** قواعد decimal والوحدات والفترات والصيغ والتقريب والتصحيح والتوزيع معتمدة بحالات ذهبية.
**Exit gate:** ينجح سيناريو التوزيع والقياس، ولا يدخل التقرير إلا قراءة معتمدة ولا يُعتمد توزيع غير متوازن.
**Success Criteria** (what must be TRUE):
  1. يستطيع المالك تعريف إصدار مؤشر باتجاه ووحدة وخط أساس ومالك ودورية وصيغة تجميع وبسط ومقام قابلة للتدقيق.
  2. يستطيع توزيع مستهدف التجمع على المنشآت، ويرفض النظام الاعتماد ما لم يساوِ المجموع المستهدف وفق قاعدة التقريب المعتمدة.
  3. يستطيع المنسق فتح فترة وإدخال قراءة وإرفاق أدلتها، ثم يراجعها المخول ويعتمدها ويقفل الفترة؛ ولا تظهر قراءة غير معتمدة كتقرير رسمي.
  4. تبقى النسخ والتصحيحات والأدلة وسلسلة الاعتماد قابلة للتتبع دون تعديل القيمة المعتمدة بصمت.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.2؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.2؛ `cluster/docs/adr/021-strategy-indicator-ownership.md`

### Phase 13: W2.3 Portfolio + Program + Project
**Goal:** يستطيع مكتب المشاريع تشغيل محفظة وبرامج ومشاريع ذات قوالب مثبتة وأدوار وبوابات اعتماد عبر الجهات.
**Mode:** mvp
**Depends on:** Phase 10, Phase 11, Phase 7
**Requirements:** FR-R2-005, FR-R2-011
**Entry gate:** R1 مطلق، وعقود Strategy وTasks وWorkflow جاهزة، وملكية PortfolioProjects لا تتسرب إلى الموديولات الأخرى.
**Exit gate:** ينجح المشروع العابر للجهات ودورة القالب والبوابات مع authorization مستقل لكل جهة.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم المخول إنشاء محفظة وبرنامج ومشروع من قالب يحدد المراحل والمعالم والأدوار، ويبقى إصدار القالب مثبتاً عند البدء.
  2. ينتقل المشروع خلال دورة حياته وبواباته عبر محرك Workflow المشترك، ولا يمكن تجاوز موافقة يفرضها نوع المشروع.
  3. يستطيع مشروع بمالك OrgUnit واحد إشراك جهات أخرى بأدوار محددة دون منحها صلاحية خارج المشروع أو كشف مشاريع أخرى.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.3؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.3؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 14: W2.4 قوالب مشاريع التحسين
**Goal:** يستطيع مكتب المشاريع تشغيل مشاريع عادية وتحسين عبر قوالب PDSA وDMAIC وFOCUS-PDCA وقالب داخلي محكوم.
**Mode:** mvp
**Depends on:** Phase 13
**Requirements:** FR-R2-006
**Entry gate:** دورة المشروع والقالب المثبت والبوابات المشتركة تعمل، ومعايير الأدلة لكل منهج معتمدة.
**Exit gate:** تعمل دورة PDSA كاملة ويُثبت تشغيل DMAIC وFOCUS-PDCA والقالب الداخلي دون كود تنفيذي.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم بدء مشروع PDSA وإكمال Plan/Do/Study/Act مع دليل واعتماد كل معلم مطلوب.
  2. يستطيع المستخدم اختيار DMAIC أو FOCUS-PDCA وتشغيل مراحلهما المنشورة دون إسقاط أو خلط المنهج.
  3. يستطيع مسؤول إنشاء قالب داخلي من الواجهة ونشر إصدار ثابت واستخدامه في مشروع تجريبي دون التأثير في المشاريع الجارية.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.4؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.4؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 15: W2.5 الإنجاز والصحة والميزانية
**Goal:** يرى ملاك المشاريع والمحافظ إنجازاً قائماً على المعالم والأدلة وصحة لا تخفي المشروع الحرج وميزانية إدارية واضحة.
**Mode:** mvp
**Depends on:** Phase 13, Phase 14
**Requirements:** FR-R2-007, FR-R2-008, FR-R2-009, FR-R2-012
**Entry gate:** قواعد decimal والتقريب والأوزان والصحة والتصنيف الحرج والميزانية معتمدة بحالات ذهبية.
**Exit gate:** تنجح قواعد مجموع الأوزان والحارس والتصنيف الحرج على بيانات وحمل تمثيليين.
**Success Criteria** (what must be TRUE):
  1. يرى المستخدم إنجاز المشروع محسوباً فقط من المعالم المعتمدة وأدلتها وأوزانها التي تساوي 100%، لا من عدد المهام.
  2. يرى حالة Green/Amber/Red وقواعد التصنيف الحرج وسبب أي تجاوز إداري، ولا يستطيع المتوسط إخفاء مشروع حرج متعثر في البرنامج أو المحفظة.
  3. يستطيع المخول تسجيل المعتمد والمخطط والمصروف والمتوقع ورؤية الانحراف الإداري دون أوامر شراء أو فواتير.
  4. تُقفل الأوزان بعد خط الأساس، ويظهر تاريخ مبرر لأي تغيير في المدة أو الميزانية أو الوزن أو التصنيف.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.5؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.5؛ `cluster/docs/architecture/non-functional-requirements.md`

### Phase 16: W2.6 ربط الأثر
**Goal:** يستطيع مالك المؤشر اعتماد أثر مشروع على تحسن مرصود دون أن تتجاوز المساهمات التحسن الحقيقي أو تتكرر الحقيقة بين الموديولات.
**Mode:** mvp
**Depends on:** Phase 12, Phase 15
**Requirements:** FR-R2-010, SEC-R2-002
**Entry gate:** قواعد خط الأساس والفترة والتقريب والإسناد ومنع الازدواج معتمدة، وتعمل صلاحيات Strategy وPortfolioProjects من الجانبين.
**Exit gate:** ينجح سيناريو الأثر كاملاً، ويُرفض حسابياً أي اعتماد يتجاوز التحسن المرصود وفق القاعدة المعتمدة.
**Success Criteria** (what must be TRUE):
  1. يستطيع مدير المشروع ربط مشروع بمؤشر وتسجيل خط أساس وأثر متوقع وفترة ومنشأة وأدلة، ثم يعتمد مالك المؤشر الأثر الفعلي.
  2. يرفض النظام مجموع مساهمات يتجاوز التحسن المرصود، ولا يوزع التحسن بالتساوي أو يسمح بازدواج الفترة افتراضياً.
  3. يرى المستخدم الرابط والأثر المعتمد من لوحتي المشروع والمؤشر ضمن صلاحيات الجانبين، مع بقاء القياس الحقيقي مملوكاً لـStrategy.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W2.6؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §3 W2.6؛ `cluster/docs/adr/021-strategy-indicator-ownership.md`؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 17: W2.7 UAT + إطلاق R2
**Goal:** تثبت تجربة ميدانية من الاستراتيجية إلى المشروع والأثر أن R2 قابل للإطلاق دون تراجع R1.
**Mode:** mvp
**Depends on:** Phase 16
**Requirements:** NFR-R2-001, NFR-R2-002, OPS-R2-001, OPS-R2-002
**Entry gate:** بوابة R1 ما زالت خضراء وجميع موجات W2.1–W2.6 مكتملة؛ لا feature catch-up في UAT.
**Exit gate:** أدلة R2 خضراء مع إعادة بوابات R1؛ لا يبدأ تنفيذ R3 قبل Go الموقّع.
**Success Criteria** (what must be TRUE):
  1. تكتمل تجربة 8 أسابيع في برنامج بالتجمع وبرنامج بمستشفى، تضم ≥10 مشاريع، و≥25 مؤشراً معرفاً منها ≥8 معتمدة، و≥80 قراءة معتمدة، و≥6 توزيعات مستهدفات عبر ≥3 منشآت.
  2. تمتلك ≥70% من مشاريع التحسين أثراً فعلياً معتمداً، وتنجح 100% من التوزيعات، ولا يتجاوز الأثر التحسن أبداً، وتُمارس PDSA وFOCUS-PDCA فعلياً مع بقاء DMAIC متاحاً ومختبراً، ويبلغ القبول الميداني ≥85%.
  3. تحقق لوحة المؤشرات P95 ≤2s وتُعاد بناؤها خلال ≤5 دقائق، وتتحمل سعة المشاريع 1.5× خط الأساس دون إعادة بنية، وتستعاد بيانات المشروع كاملة ضمن RTO المحدد.
  4. تعاد بوابات R1 الأمنية والعزلية وحدود الموديولات والاستعادة RPO≤15m/RTO≤2h والفجوة الهوائية والتوطين بلا تخفيف، مع صفر ثغرات حرجة وتسرب بيانات.
  5. تكتمل قائمة الجاهزية والأدلة 100%، وتكون Go/No-Go ≥90 بلا أحمر ولا قسم وظيفي أو أمني دون 80، ثم يوقع الملاك Go قبل أي عمل R3.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4–7 W2.7؛ `cluster/docs/plans/release-2-strategy-portfolio.md` §§3 W2.7,5؛ `cluster/docs/plans/readiness-checklist.md` §§2.2,3–12؛ `cluster/docs/product/success-metrics.md`

### Phase 18: W3.0 مواصفة المخاطر
**Goal:** تمتلك الحوكمة والفريق التقني مواصفة مخاطر قابلة للتنفيذ وحالات قبول ذهبية معتمدة قبل كتابة أي كود إنتاج R3.
**Mode:** mvp
**Depends on:** Phase 17
**Requirements:** None (intentional no-code governance/specification gate; no canonical v1 requirement is duplicated here)
**Entry gate:** R2 Green وموقّع؛ لا تبدأ ورش المواصفة ولا أي كود R3 قبل إغلاق W2.7.
**Exit gate:** تعتمد لجنة المخاطر `RISK-SPEC.md` وسجل القرار ومصفوفة القدرات/الحقول والحالات الذهبية؛ إذا غاب أي منها فلا يبدأ كود W3.1.
**Success Criteria** (what must be TRUE):
  1. يستطيع مسؤول المخاطر تطبيق حالات ذهبية متفق عليها على مصفوفة الاحتمالية/الأثر والشهية وإنتاج النتيجة والتصعيد المتوقعين دون تفسير إضافي من المطور.
  2. توجد قواعد معتمدة للفئات والمصادر ودورات المراجعة والبيانات الإلزامية والضوابط والفعالية والتقييم الكامن والمتبقي والمعالجة.
  3. توجد قواعد معتمدة لملكية KRI وعتباته والتنبيه والتصعيد وقبول الخطر والقدرات والحقول والاحتفاظ.
  4. يثبت قائد التقنية أن المواصفة قابلة للتنفيذ ضمن حدود Risk/Strategy/PortfolioProjects والخدمات المشتركة دون نسخ الحقيقة أو إنشاء موديول جديد.
**Plans:** TBD
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4,8 W3.0؛ `cluster/docs/plans/release-3-risk.md` §§2,4 W3.0؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 19: W3.1 سجل المخاطر
**Goal:** يستطيع المستخدمون المخولون إنشاء وإدارة سجلات مخاطر مرتبطة بالنطاق والمالك والفئة والمصدر عبر دورة حياة مدققة.
**Mode:** mvp
**Depends on:** Phase 18
**Requirements:** FR-R3-001
**Entry gate:** مواصفة W3.0 والحالات الذهبية معتمدة، ولا تُخترع فئات أو حالات أو حقول أثناء التنفيذ.
**Exit gate:** CRUD وInvariants والعزل والبحث المدقق تعمل على سجلات التجمع والمنشآت.
**Success Criteria** (what must be TRUE):
  1. يستطيع مسؤول مخاطر إنشاء RiskRegister لجهة أو وحدة وإضافة خطر بمالك ومستوى وفئة ومصدر وفق المواصفة المعتمدة.
  2. ينتقل الخطر بين حالاته المحكومة، ويمنع الحذف الفعلي غير المصرح ويُسجل كل تعديل وحذف منطقي.
  3. يرى مسؤول المنشأة سجل منشأته فقط، ويرى مسؤول التجمع مخاطر الجهات التي تمنحه علاقته نطاقها دون نسخ بيانات Strategy أو Project.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.1؛ `cluster/docs/plans/release-3-risk.md` §4 W3.1؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 20: W3.2 التقييم
**Goal:** يستطيع مسؤول المخاطر تقييم الخطر كامناً ومتبقياً وفق المصفوفة والشهية المعتمدتين مع snapshots وإعادة تقييم مبررة.
**Mode:** mvp
**Depends on:** Phase 19
**Requirements:** FR-R3-002
**Entry gate:** معادلة المصفوفة والشهية وقواعد التقريب والحالات الذهبية من W3.0 قابلة للتنفيذ، وعقد إعادة الحساب عند تغير الضابط محدد دون اختراع بيانات W3.3.
**Exit gate:** تطابق كل الحسابات الحالات الذهبية، ويثبت مسار إعادة الحساب عند تغير فعالية ضابط لاحقاً.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم إدخال الاحتمالية والأثر ويرى الدرجة والمستوى الكامن محسوبين وفق إصدار المصفوفة المعتمد.
  2. يستطيع المستخدم تسجيل تقييم متبقٍ منفصل ومقارنته بالشهية، ولا يُحفظ تقييم بلا مدخلاته وإصدار القاعدة الحاكمة.
  3. تتطلب إعادة التقييم سبباً، وتحفظ snapshot تاريخية، وتعيد المنصة الحساب عند تغير فعالية ضابط عبر العقد المحدد.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.2؛ `cluster/docs/plans/release-3-risk.md` §4 W3.2؛ `cluster/docs/architecture/module-catalog.md`

### Phase 21: W3.3 مكتبة الضوابط
**Goal:** يستطيع مسؤولو المخاطر إدارة ضوابط قابلة لإعادة الاستخدام وتقييم فعاليتها وربطها بالمخاطر لإعادة حساب المستوى المتبقي.
**Mode:** mvp
**Depends on:** Phase 20
**Requirements:** FR-R3-003
**Entry gate:** عقد إعادة الحساب من W3.2 مثبت، وتعريفات الضبط والفعالية والمراجعة من W3.0 معتمدة.
**Exit gate:** يثبت ربط متعدد وإعادة تقييم متبقٍ صحيحة عند ضعف الضابط أو انتهاء مراجعته.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم إنشاء ضابط بمالك ونوع وهدف وفترة مراجعة وربطه بخطر واحد أو عدة مخاطر.
  2. يستطيع المالك والمراجع المستقل تسجيل فعالية الضابط مع المبرر والتاريخ دون استبدال التقييم السابق.
  3. يؤدي ضعف الفعالية أو انتهاء المراجعة إلى إبطال الاعتماد المتبقي وطلب إعادة التقييم وفق المواصفة.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.3؛ `cluster/docs/plans/release-3-risk.md` §4 W3.3؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 22: W3.4 خطط المعالجة
**Goal:** يستطيع مالك الخطر اتخاذ قرار قبول أو تخفيف أو نقل أو تجنب وتنفيذ التخفيف عبر Tasks وDocuments المشتركة حتى إعادة التقييم.
**Mode:** mvp
**Depends on:** Phase 21, Phase 7
**Requirements:** FR-R3-004, FR-R3-007, SEC-R3-002
**Entry gate:** حوكمة سلطة القبول والأدلة وإعادة التقييم معتمدة، وعقود Tasks/Documents idempotent ولا تنسخ حالتها إلى Risk.
**Exit gate:** تعمل خطة Mitigate كاملة، ويُمنع قبول خطر عالٍ أو إغلاق خطة بلا السلطة والأدلة والمهام المطلوبة.
**Success Criteria** (what must be TRUE):
  1. يستطيع المخول اختيار Accept/Mitigate/Transfer/Avoid، ويسجل النظام السبب والمالك والسلطة لكل قرار قبول.
  2. تنشئ خطة Mitigate روابط إلى مهام R1 بملاك ومواعيد وأدلة، ولا تمنح روابط المهام وصولاً إضافياً إلى الخطر.
  3. لا تُغلق خطة التخفيف حتى تكتمل مهامها وأدلتها، ثم يطلب النظام إعادة تقييم ويعرض أثر الخطة على المستوى المتبقي.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.4؛ `cluster/docs/plans/release-3-risk.md` §4 W3.4؛ `cluster/docs/architecture/module-catalog.md`

### Phase 23: W3.5 مؤشرات المخاطر والتصعيد
**Goal:** يرى المستخدمون مؤشرات المخاطر ولوحاتهم ضمن النطاق، وتولد قراءة Strategy المعتمدة المتجاوزة لعتبة Risk تنبيهاً وتصعيداً محكوماً.
**Mode:** mvp
**Depends on:** Phase 22, Phase 12
**Requirements:** FR-R3-005, FR-R3-006, FR-R3-008, FR-R3-010, SEC-R3-001, OPS-R3-001
**Entry gate:** قواعد KRI والعتبات والصمت وإزالة التكرار والشهية والتصعيد من W3.0 مثبتة، وStrategy يحتفظ حصرياً بتعريف وقياس المؤشر.
**Exit gate:** تنجح رحلة قياس Strategy إلى تنبيه Risk وتصعيد Workflow ولوحة النطاق، بزمن تنبيه لا يتجاوز 5 دقائق.
**Success Criteria** (what must be TRUE):
  1. يستطيع مسؤول المخاطر ربط `indicator_id` معتمد بخطر أو ضابط كـKRI وتحديد العتبات، دون نسخ تعريف المؤشر أو قراءته إلى Risk.
  2. تولد القراءة المعتمدة المتجاوزة للشهية تنبيهاً داخلياً خلال ≤5 دقائق مرة منطقية واحدة، وتصعّد الخطر حسب المستوى وتُسجل المرحلة.
  3. يتلقى مالك الخطر تنبيهاً قبل موعد المراجعة، ويظهر التأخر أو تجاوز الشهية في مساره ولوحته.
  4. يرى المستخدم لوحة مخاطر/KRI مرتبة حسب الشهية لنطاق الإدارة أو المنشأة أو التجمع فقط، ولا تكشف الروابط أو الأعداد خارج صلاحياته.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.5؛ `cluster/docs/plans/release-3-risk.md` §4 W3.5؛ `cluster/docs/adr/021-strategy-indicator-ownership.md`؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 24: W3.6 ربط بالأهداف والمشاريع
**Goal:** يستطيع المستخدم المخول التنقل بين الخطر والهدف والمؤشر والمشروع وخطة المعالجة دون خرق الملكية أو الصلاحية.
**Mode:** mvp
**Depends on:** Phase 23
**Requirements:** FR-R3-009
**Entry gate:** عقود IDs/read models بين Risk وStrategy وPortfolioProjects معتمدة، وصلاحية كل جانب مستقلة.
**Exit gate:** تنجح الرحلة من الهدف إلى الخطر إلى المعالجة مع dual authorization وعدم نسخ أسماء أو بيانات المصدر كحقيقة.
**Success Criteria** (what must be TRUE):
  1. يستطيع المستخدم المخول ربط خطر بهدف أو مؤشر أو مشروع ورؤية الرابط من الجانبين ضمن صلاحيات كل سجل.
  2. يستطيع المستخدم الانتقال من هدف استراتيجي إلى مخاطره ثم خطط معالجتها، ولا يرى عنواناً أو تفصيلاً يمنعه الجانب المالك.
  3. يتطلب حذف الرابط سبباً ويسجله التدقيق، ولا يستطيع Risk تعديل بيانات Strategy أو PortfolioProjects أو الاحتفاظ بنسخة مصدر حقيقة منها.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §4 W3.6؛ `cluster/docs/plans/release-3-risk.md` §4 W3.6؛ `cluster/docs/adr/022-portfolio-projects-and-risk-boundaries.md`

### Phase 25: W3.7 UAT + إطلاق R3
**Goal:** تعمل R3 كسجل مخاطر تشغيلي معتمد في إدارتي مخاطر وتثبت جاهزية المنصة الكاملة والأداء والتبني والحكم النهائي.
**Mode:** mvp
**Depends on:** Phase 24
**Requirements:** NFR-R3-001, NFR-R3-002, OPS-R3-002
**Entry gate:** W3.0–W3.6 مكتملة، وR1/R2 ما زالت بواباتهما خضراء، ولا feature catch-up في UAT.
**Exit gate:** بعد Go لـR3 لا يضاف أي مجال أو موديول قياساً؛ يلزم قرار ملكية وحدود وعقود ورتبة DAG واختبارات وADR مستقل قبل إدخاله خارطة لاحقة.
**Success Criteria** (what must be TRUE):
  1. تكتمل تجربة 6 أسابيع في إدارتي مخاطر بالتجمع والمستشفى، مع ≥100 سجل خطر منها ≥60 نشطاً، و≥120 ضابطاً، و≥40 خطة معالجة نشطة بمهام، و≥15 مؤشر Strategy مرتبطاً كـKRI، وتدريب ≥8 مسؤولي مخاطر عبر 3 منشآت.
  2. تُقيّم 100% من المخاطر الجديدة بالمصفوفة، وترتبط ≥90% بضوابط ومستوى متبقٍ، وتستخدم 100% من خطط المعالجة Tasks، وتوثق 100% من قرارات القبول بالسبب والمالك.
  3. يصل تنبيه تجاوز الشهية خلال ≤5 دقائق، ويحقق تقييم الخطر P95 ≤2s وتنجح سعة 5,000 خطر نشط دون تراجع، وتُؤرشف التقييمات وفق سياسة الاحتفاظ المعتمدة.
  4. تنجح من جديد بوابات العزل والأمن وحدود الموديولات والاستعادة RPO≤15m/RTO≤2h والفجوة الهوائية والتوطين، مع صفر تسرب وثغرات حرجة واختبار Tabletop للتصعيد.
  5. يبلغ قبول إدارتي المخاطر ≥85%، وتكتمل قائمة الجاهزية 100%، وتكون Go/No-Go ≥90 بلا أحمر ولا قسم وظيفي أو أمني دون 80، ثم يوقع الملاك Go وتُفعل بوابة حوكمة ما بعد R3.
**Plans:** TBD
**UI hint:** yes
**Canonical refs:** `cluster/docs/plans/implementation-roadmap.md` §§4–8 W3.7؛ `cluster/docs/plans/release-3-risk.md` §§4 W3.7,6,8؛ `cluster/docs/plans/readiness-checklist.md` §§2.3,3–13؛ `cluster/docs/product/success-metrics.md`

## Progress

**Execution Order:** Phase 1 through Phase 25 in strict order; no release may bypass its predecessor gate except by an explicit, recorded sponsor decision permitted by the accepted governance process.

| Phase | Plans Complete | Status | Completed |
|---|---:|---|---|
| 1. W1.1 Walking Skeleton | 0/TBD | Not started | - |
| 2. W1.2 Organization + Identity + Import | 0/TBD | Not started | - |
| 3. W1.3 Authorization + العلاقات الإشرافية | 0/TBD | Not started | - |
| 4. W1.4 WorkDefinitions + منشئ النماذج | 0/TBD | Not started | - |
| 5. W1.5 Workflow Engine | 0/TBD | Not started | - |
| 6. W1.6 WorkRecords: الطلب الداخلي العام | 0/TBD | Not started | - |
| 7. W1.7 Tasks | 0/TBD | Not started | - |
| 8. W1.8 Documents + Notifications | 0/TBD | Not started | - |
| 9. W1.9 Search + Reporting + لوحات | 0/TBD | Not started | - |
| 10. W1.10 UAT + إطلاق R1 | 0/TBD | Not started | - |
| 11. W2.1 Strategy foundation | 0/TBD | Not started | - |
| 12. W2.2 Indicators | 0/TBD | Not started | - |
| 13. W2.3 Portfolio + Program + Project | 0/TBD | Not started | - |
| 14. W2.4 قوالب مشاريع التحسين | 0/TBD | Not started | - |
| 15. W2.5 الإنجاز والصحة والميزانية | 0/TBD | Not started | - |
| 16. W2.6 ربط الأثر | 0/TBD | Not started | - |
| 17. W2.7 UAT + إطلاق R2 | 0/TBD | Not started | - |
| 18. W3.0 مواصفة المخاطر | 0/TBD | Not started | - |
| 19. W3.1 سجل المخاطر | 0/TBD | Not started | - |
| 20. W3.2 التقييم | 0/TBD | Not started | - |
| 21. W3.3 مكتبة الضوابط | 0/TBD | Not started | - |
| 22. W3.4 خطط المعالجة | 0/TBD | Not started | - |
| 23. W3.5 مؤشرات المخاطر والتصعيد | 0/TBD | Not started | - |
| 24. W3.6 ربط بالأهداف والمشاريع | 0/TBD | Not started | - |
| 25. W3.7 UAT + إطلاق R3 | 0/TBD | Not started | - |

## Coverage Validation

- Canonical v1 requirements: **88**
- Primary phase mappings: **88**
- Unmapped: **0**
- Duplicate primary mappings: **0**
- Accepted phases/waves: **25**, in exact order W1.1–W1.10 → W2.1–W2.7 → W3.0–W3.7

---
*Created: 2026-07-15 — initial roadmap approved for execution planning.*
