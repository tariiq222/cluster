---
doc_id: PLN-FE-COV-001
title: خطة إغلاق تغطية الواجهة للعقود
type: plans
status: draft
version: 1.0.0
date: 2026-07-22
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: بعد إنجاز كل موجة
sources:
  - docs/contracts/api/openapi.yaml
references:
  - docs/plans/implementation-roadmap.md
  - docs/plans/active-delivery-status.md
---

# خطة إغلاق تغطية الواجهة للعقود

## 1. الحالة المقيسة

القياس أُجري آليًا على عقد العميل الموحّد مقابل ما تستهلكه الشاشات فعليًا
(عملية تُعدّ «مغطّاة» إذا كان لها غلاف في `src/api/` **و** كان ذلك الغلاف
مستوردًا من كود خارج `src/api/`).

| المؤشر | العدد | النسبة |
|---|---:|---:|
| عمليات عقد العميل الموحّد | 183 | 100% |
| تصل إليها شاشة فعليًا | 94 | 51% |
| لها غلاف بلا مستهلك | 2 | 1% |
| بلا غلاف إطلاقًا | 87 | 48% |

تفكيك الـ87 غير المغطّاة:

| التصنيف | العدد | القرار |
|---|---:|---|
| بنية تحتية لا تحتاج شاشة | 8 | تُستثنى نهائيًا |
| مسارات زائدة في العقد | 4 | تُحذف من العقد |
| فجوات منتج حقيقية متبقية | 75 | نطاق الموجات التالية |

### 1.1 ما يُستثنى نهائيًا (8)

هذه عمليات بين الخدمات أو فحوص تشغيلية، ووجود شاشة لها خطأ تصميمي لا نقص:

| العملية | المسار | السبب |
|---|---|---|
| `getBootstrapHealth` | `GET /up` | فحص تشغيلي للبنية |
| `scanDocumentVersion` | `POST /internal/documents/versions/{versionId}/scan` | استدعاء داخلي من خدمة الفحص |
| `reconcileDocumentPromotion` | `POST /internal/documents/versions/{versionId}/reconcile-promotion` | تسوية داخلية |
| `validatePersonReference` | `GET /organization/people/{personId}/reference` | عقد داخلي بين الموديولات |
| `loginW12` | `POST /auth/login` | مسار تطوير؛ الإنتاج يستخدم `/identity/login` |
| `getAuthorizationBootstrap` | `GET /authorization/bootstrap` | تهيئة أولية لمرة واحدة |
| `completeAuthorizationBootstrap` | `POST /authorization/bootstrap` | تهيئة أولية لمرة واحدة |
| `bootstrapComplete` | `POST /authorization/bootstrap/complete` | تهيئة أولية لمرة واحدة |

### 1.2 ما يجب حذفه من العقد (4)

الباك-إند يخدم مسارًا واحدًا مُقولبًا `POST /work-records/{recordId}/{recordAction}`
(انظر `apps/api/routes/web.php`)، بينما العقد يعلن أربعة مسارات مفردة موسومة
`x-implementation-status: planned`. هذا انحراف توثيقي يجب إغلاقه لا بناء شاشة له:

| العملية | المسار |
|---|---|
| `submitWorkRecord` | `POST /work-records/{recordId}/submit` |
| `transitionWorkRecordReturn` | `POST /work-records/{recordId}/return` |
| `transitionWorkRecordComplete` | `POST /work-records/{recordId}/complete` |
| `transitionWorkRecordCompleteSubmission` | `POST /work-records/{recordId}/complete-submission` |

---

## 2. مبدأ الترتيب

الموجات مرتّبة بحاصل **(الأثر ÷ الكلفة)**، لا بترتيب الموديولات في العقد.
القاعدة العملية: الفجوة داخل موديول له شاشة تعمل أرخص بكثير من موديول بلا
سطح استخدام أصلًا، لأن التنقّل والحالات والنصوص جاهزة.

- **الموجات 1–5**: نواقص داخل موديولات قائمة (38 عملية) — توصيل لا تصميم.
- **الموجات 6–10**: موديولات غائبة بالكامل (47 عملية) — تحتاج تصميم رحلات.

---

## 3. الموجة 1 — مركز المستندات (10 عمليات)

**لماذا أولًا:** رفع المستند يعمل بالفعل عبر `ImportReview`، لكن لا توجد أي
شاشة لعرض المستندات أو إدارتها. المستخدم يرفع ملفًا ولا يستطيع رؤيته بعدها.
هذه أكبر فجوة وظيفية مفردة في المنتج.

| العملية | المسار |
|---|---|
| `listDocuments` | `GET /documents` |
| `getDocument` | `GET /documents/{documentId}` |
| `createDocument` | `POST /documents` |
| `updateDocument` | `PATCH /documents/{documentId}` |
| `transitionDocument` | `POST /documents/{documentId}/{documentAction}` |
| `listDocumentVersions` | `GET /documents/{documentId}/versions` |
| `addDocumentVersion` | `POST /documents/{documentId}/versions` |
| `listDocumentLinks` | `GET /documents/{documentId}/links` |
| `linkDocument` | `POST /documents/{documentId}/links` |
| `createDocumentAccessGrant` | `POST /documents/{documentId}/{grantType}-grant` |

**المخرجات:**
1. مسار `/documents` وشاشة قائمة مع تصفية بالحالة والتصنيف و`cursor` للترقيم.
2. شاشة تفصيل: البيانات، النسخ، الروابط، ومنح الوصول.
3. رفع نسخة جديدة يعيد استخدام تدفق الحجر الموجود في `ImportReview`.
4. استبدال حقل «الصق معرّف المستند» في `RequestDetail` بمنتقي حقيقي.

**تبعية:** لا شيء. `initiateDocumentUpload`/`completeDocumentUpload` مغطّاة أصلًا.

**فجوة عقد مرصودة:** لا يوجد endpoint لعرض المنح أو إلغائها — الإنشاء فقط.
يجب رفعها لمالك العقد قبل تصميم شاشة المنح.

---

## 4. الموجة 2 — سجل التدقيق (1 عملية)

**لماذا:** بند P0 مسجّل في شاشة التغطية عندك. حاليًا «الجدول الزمني» في
`RequestDetail` صفٌّ مُصطنع واحد مبني من `created_at`، وليس تاريخًا حقيقيًا.

| العملية | المسار |
|---|---|
| `listAuditEvents` | `GET /audit` |

**المخرجات:**
1. مسار `/audit` وشاشة بحث مع تصفية بالمورد والفاعل والمدى الزمني.
2. لوحة «سجل الأحداث» مضمّنة في شاشة تفصيل أي كيان، مُرشَّحة على معرّفه.
3. حذف الجدول الزمني المُصطنع من `RequestDetail`.

**تبعية:** يُفضّل بعد الموجة 1 لإعادة استخدام نمط «لوحة مضمّنة في التفصيل».

---

## 5. الموجة 3 — قرارات سير العمل مع السبب (3 عمليات)

**لماذا:** ثغرة حوكمة حقيقية لا مجرد نقص واجهة. الموافقات اليوم ثنائية بلا
تسجيل سبب على مستوى الخطوة، رغم أن العقد يوجب `reason` في `Decision`.

| العملية | المسار |
|---|---|
| `recordWorkflowDecision` | `POST /workflow/steps/{stepId}/decisions` |
| `actOnWorkflowStep` | `POST /workflow/steps/{stepId}/{stepAction}` |
| `cancelWorkflow` | `POST /workflow/instances/{instanceId}/cancel` |

**المخرجات:**
1. نموذج قرار على الخطوة: `approve` / `reject` / `return` / `accept` / `decline`
   مع حقل سبب إلزامي وتحقق طول (1–2000).
2. إعادة إسناد وتصعيد الخطوة عبر `actOnWorkflowStep`.
3. إلغاء نسخة سير العمل من شاشة الإدارة.

**ملاحظة مرتبطة:** يوجد نمط مشابه في `AuthorizationAdmin` — تغيير حالة
role-assignment يتم عبر PATCH مباشر بلا سبب، متجاوزًا
`transitionAuthorizationAdminResource`. يجب إصلاحه ضمن هذه الموجة لأنه
نفس ثغرة الحوكمة.

---

## 6. الموجة 4 — تعليقات المهام والمشاركون (6 عمليات)

| العملية | المسار |
|---|---|
| `getTask` | `GET /tasks/{taskId}` |
| `createTask` | `POST /tasks` |
| `updateTask` | `PATCH /tasks/{taskId}` |
| `listTaskComments` | `GET /tasks/{taskId}/comments` |
| `addTaskComment` | `POST /tasks/{taskId}/comments` |
| `addTaskParticipant` | `POST /tasks/{taskId}/participants` |

**المخرجات:**
1. شاشة تفصيل مهمة (`/tasks/{taskId}`) — غير موجودة اليوم، القائمة فقط.
2. خيط تعليقات فعلي مع إضافة.
3. إضافة مشارك إلى المهمة.

---

## 7. الموجة 5 — دورة حياة التعريفات وصفحات التفصيل (18 عملية)

### 7.1 دورة تعريفات العمل (9)

`publish` فقط يعمل اليوم؛ باقي الدورة الحوكمية غير موصول.

| العملية | المسار |
|---|---|
| `getWorkDefinition` | `GET /work-definitions/{definitionId}` |
| `updateWorkDefinition` | `PATCH /work-definitions/{definitionId}` |
| `getWorkDefinitionVersion` | `GET /work-definition-versions/{versionId}` |
| `updateWorkDefinitionVersionDraft` | `PATCH /work-definition-versions/{versionId}` |
| `testWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/test` |
| `approveWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/approve` |
| `signWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/sign` |
| `getWorkflowVersion` | `GET /workflow/versions/{versionId}` |
| `updateWorkflowVersionDraft` | `PATCH /workflow/versions/{versionId}` |

### 7.2 صفحات تفصيل الكيانات (9)

لا توجد اليوم صفحة تفصيل لأي كيان تنظيمي — الشاشات تعتمد على القوائم فقط.

| العملية | المسار |
|---|---|
| `getOrganizationUnit` | `GET /organization/units/{unitId}` |
| `getPosition` | `GET /organization/positions/{positionId}` |
| `getPerson` | `GET /organization/people/{personId}` |
| `getFacility` | `GET /organization/facilities/{facilityId}` |
| `updateFacility` | `PATCH /organization/facilities/{facilityId}` |
| `updateCluster` | `PATCH /organization/cluster` |
| `getWorkspace` | `GET /workspace` |
| `updateWorkRecord` | `PATCH /work-records/{recordId}` |
| `logout` | `POST /auth/logout` |

**ملاحظة:** `logout` هو مسار التطوير؛ الإنتاج يستخدم `identityLogout` المغطّى.
يُرجّح حذفه من العقد بدل بناء شيء له.

---

## 8. الموجات 6–10 — الموديولات الغائبة (47 عملية)

هذه ليست ثغرات توصيل بل **منتج غير مبني**: لا شاشة ولا مسار ولا مدخل تنقّل.
كل موجة تحتاج تصميم رحلة قبل الكود، ولذلك تُقدّر بمقياس مختلف عن الموجات 1–5.

### 8.1 الموجة 6 — حوكمة السجلات (12)

| العملية | المسار |
|---|---|
| `listGovernedRecords` | `GET /records-governance/governed-records` |
| `registerGovernedRecord` | `POST /records-governance/governed-records` |
| `getGovernedRecordStatus` | `GET /records-governance/governed-records/{governedRecordId}` |
| `listRecordHolds` | `GET /records-governance/holds` |
| `placeRecordHold` | `POST /records-governance/holds` |
| `releaseRecordHold` | `POST /records-governance/holds/{holdId}/release` |
| `listRetentionPolicyVersions` | `GET /records-governance/retention-policy-versions` |
| `createRetentionPolicyVersion` | `POST /records-governance/retention-policy-versions` |
| `publishRetentionPolicyVersion` | `POST /records-governance/retention-policy-versions/{versionId}/publish` |
| `listDispositionReviews` | `GET /records-governance/disposition-reviews` |
| `decideDispositionEligibility` | `POST /records-governance/disposition-reviews` |
| `confirmDispositionOutcome` | `POST /records-governance/disposition-reviews/{reviewId}/confirm` |

**تبعية:** بعد الموجة 1، لأن حوكمة السجلات تعمل على المستندات.

### 8.2 الموجة 7 — المحافظ والمشاريع (10)

| العملية | المسار |
|---|---|
| `listPortfolioResources` | `GET /portfolio/{portfolioResource}` |
| `getPortfolioResource` | `GET /portfolio/{portfolioResource}/{resourceId}` |
| `createPortfolioResource` | `POST /portfolio/{portfolioResource}` |
| `updatePortfolioResource` | `PATCH /portfolio/{portfolioResource}/{resourceId}` |
| `transitionProject` | `POST /portfolio/projects/{projectId}/{projectAction}` |
| `listProjectMilestones` | `GET /portfolio/projects/{projectId}/milestones` |
| `createProjectMilestone` | `POST /portfolio/projects/{projectId}/milestones` |
| `listProjectIndicatorLinks` | `GET /portfolio/projects/{projectId}/indicator-links` |
| `createProjectIndicatorLink` | `POST /portfolio/projects/{projectId}/indicator-links` |
| `recordProjectSnapshot` | `POST /portfolio/projects/{projectId}/{snapshotType}-snapshots` |

**تبعية:** روابط المؤشرات تتطلب الموجة 9 (الاستراتيجية) لتكون ذات معنى.

### 8.3 الموجة 8 — إعدادات المنصة والتقاويم (9)

| العملية | المسار |
|---|---|
| `getCurrentPlatformSettings` | `GET /platform-settings/current` |
| `listPlatformSettingsVersions` | `GET /platform-settings/versions` |
| `createPlatformSettingsDraft` | `POST /platform-settings/versions` |
| `setPlatformSetting` | `PUT /platform-settings/versions/{versionId}/settings/{settingKey}` |
| `transitionPlatformSettingsVersion` | `POST /platform-settings/versions/{versionId}/{settingsAction}` |
| `listBusinessCalendars` | `GET /business-calendars` |
| `createBusinessCalendar` | `POST /business-calendars` |
| `setBusinessCalendarDay` | `PUT /business-calendars/{calendarId}/days/{date}` |
| `publishBusinessCalendar` | `POST /business-calendars/{calendarId}/publish` |

**ملاحظة:** التقاويم تؤثر على حساب مُهل سير العمل، لذا قيمتها التشغيلية أعلى
من ترتيبها هنا إن كانت المُهل مفعّلة فعلًا.

### 8.4 الموجة 9 — المخاطر (9)

| العملية | المسار |
|---|---|
| `listRiskResources` | `GET /risk/{riskResource}` |
| `getRisk` | `GET /risk/risks/{riskId}` |
| `createRiskResource` | `POST /risk/{riskResource}` |
| `updateRisk` | `PATCH /risk/risks/{riskId}` |
| `transitionRisk` | `POST /risk/risks/{riskId}/{riskLifecycleAction}` |
| `listRiskIndicatorReadings` | `GET /risk/risks/{riskId}/indicator-readings` |
| `createRiskIndicatorReading` | `POST /risk/risks/{riskId}/indicator-readings` |
| `getRiskHeatmap` | `GET /risk/heatmap` |
| `listDueRiskReviews` | `GET /risk/reviews/due` |

### 8.5 الموجة 10 — الاستراتيجية (7)

| العملية | المسار |
|---|---|
| `listStrategyResources` | `GET /strategy/{strategyResource}` |
| `getStrategyResource` | `GET /strategy/{strategyResource}/{resourceId}` |
| `createStrategyResource` | `POST /strategy/{strategyResource}` |
| `updateStrategyResource` | `PATCH /strategy/{strategyResource}/{resourceId}` |
| `transitionStrategyResource` | `POST /strategy/{strategyResource}/{resourceId}/{strategyAction}` |
| `getIndicatorScorecard` | `GET /strategy/indicators/{indicatorId}/scorecard` |
| `listPendingIndicatorMeasurements` | `GET /strategy/measurements/pending` |

---

## 9. أعمال عرضية لا ترتبط بموجة

هذه بنود مرصودة أثناء التدقيق يجب إنجازها بالتوازي:

| # | البند | الموقع | الأثر |
|---|---|---|---|
| ع-1 | إخفاء استباقي للتنقّل والأزرار بحسب `allowed_actions` بدل الاعتماد على 403 بعد المحاولة | كل الشاشات | تجربة + أمان ظاهري |
| ع-2 | ترقيم `cursor` في القوائم التي ما زالت بحد ثابت | `listPositions`, `listPeople`, `listAssignments`, `listUserAccounts`, `listImportJobRows` | فقدان بيانات صامت |
| ع-3 | تصحيح عقد `POST /organization/units/reorder`: يوجب `ordered_unit_ids` بينما الكونترولر يتجاهل الجسم | `docs/contracts/api/openapi.yaml` | انحراف عقد |
| ع-4 | حذف المسارات الأربعة الزائدة لسجلات العمل من العقد | `docs/contracts/api/openapi.yaml` | انحراف عقد |
| ع-5 | endpoints ناقصة يجب إضافتها للعقد: عرض/إلغاء منح المستند، إنشاء مقاعد دفعة واحدة، تفصيل وتعديل المسمى الوظيفي، إنهاء العلاقة الإشرافية، `unread-count` و`mark-all-read` للإشعارات | العقد | تحجب موجات لاحقة |
| ع-6 | مراجعة بصرية لأربع شاشات بعد إصلاح سلاسل شرطية منهارة | `IdentityAccounts`, `ImportReview`, `OrganizationStructure`, `AuthorizationAdmin` | تحقق من انحسار |
| ع-7 | التحقق من أثر إزالة ترويسة `X-Day3-Acceptance` من `createWorkRecord` على تدفقات القبول | `apps/web/src/api/work-records.ts` | اختبارات قبول |

---

## 10. تعريف الإنجاز لكل موجة

الموجة لا تُعدّ منجزة إلا باكتمال كل ما يلي:

1. كل عملية في الموجة لها غلاف في `src/api/` يمر عبر العميل المولّد — لا `fetch` يدوي.
2. كل غلاف مستهلَك من شاشة أو مكوّن خارج `src/api/`.
3. الشاشة تغطي الحالات الست: `loading` / `ready` / `empty` / `forbidden` / `not-found` / `error`
   عبر `stateFromError` المشترك، مع `conflict` و`stale` لكل مورد له `lock_version`.
4. لا نصوص مضمّنة: كل سلسلة في قاموس الملف أو القاموس المركزي، عربي وإنجليزي.
5. المسار مُصنَّف في `ROUTE_WORKSPACE` داخل `shell/routes.ts` وله مدخل تنقّل.
6. اختبارات وحدة للمنطق النقي، واختبار رحلة واحد على الأقل للمسار السعيد.
7. إعادة قياس التغطية وتحديث الجدول في القسم 1.

## 11. تتبّع التقدّم

| الموجة | العمليات | الحالة |
|---|---:|---|
| 1 — مركز المستندات | 10 | مكتملة — 10 / 10 |
| 2 — سجل التدقيق | 1 | لم تبدأ |
| 3 — قرارات سير العمل | 3 | لم تبدأ |
| 4 — المهام والتعليقات | 6 | لم تبدأ |
| 5 — التعريفات وصفحات التفصيل | 18 | لم تبدأ |
| 6 — حوكمة السجلات | 12 | لم تبدأ |
| 7 — المحافظ والمشاريع | 10 | لم تبدأ |
| 8 — إعدادات المنصة والتقاويم | 9 | لم تبدأ |
| 9 — المخاطر | 9 | لم تبدأ |
| 10 — الاستراتيجية | 7 | لم تبدأ |
| **الإجمالي** | **85** | **10 / 85** |

عند اكتمال الموجات 1–5 تصبح التغطية **~67%**، وعند اكتمال الجميع **100%**
من العمليات المخصّصة للواجهة (183 ناقص 12 مستثناة أو محذوفة = 171).

## 12. كيفية إعادة القياس

```bash
cd apps/web && python3 - <<'PY'
import pathlib, re
gen = pathlib.Path('src/api/generated/cluster.ts').read_text()
ops = sorted(set(o for o in re.findall(r"^export const (\w+) = async \(", gen, re.M)
             if not re.match(r"^get\w+Url$", o)))
wrappers = {p: p.read_text() for p in pathlib.Path('src/api').rglob('*.ts')
            if 'generated' not in str(p) and '.test.' not in p.name}
app = '\n'.join(p.read_text() for p in pathlib.Path('src').rglob('*.ts*')
                if not str(p).startswith('src/api') and '.test.' not in p.name)
def owner(text, idx):
    c = [m for m in re.finditer(r"export (?:async )?function (\w+)|export const (\w+) = ", text[:idx])]
    return (c[-1].group(1) or c[-1].group(2)) if c else None
reach = 0
for op in ops:
    names = {owner(s, m.start()) for s in wrappers.values()
             for m in re.finditer(r"(?:generated\.)?\b%s\(" % re.escape(op), s)}
    if any(n and re.search(r"\b%s\b" % re.escape(n), app) for n in names):
        reach += 1
print(f"{reach}/{len(ops)} = {reach*100//len(ops)}%")
PY
```
