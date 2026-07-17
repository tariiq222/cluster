---
doc_id: PLN-W11-OPS-001
title: حزمة مهام إغلاق W1.1 التشغيلية
type: plans
status: proposed
version: 1.2.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- قائد SRE
- مهندس الجودة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/release-1-platform.md
- docs/adr/023-single-host-dokploy-deployment.md
- docs/operations/kubernetes-platform.md
- docs/operations/air-gap-supply-chain.md
- docs/operations/ha-dr-backup.md
references:
- docs/plans/active-delivery-status.md
- docs/engineering/testing-strategy.md
---
# حزمة مهام إغلاق W1.1 التشغيلية

## 1. الهدف والنطاق

تحول هذه الحزمة فجوات `W1.1 Walking Skeleton` المفتوحة إلى مهام تنفيذية مرتبة، مع
اختبارات وحدة وتكامل ورحلات طرف إلى طرف وأدلة تشغيل قابلة للمراجعة. تغلق الحزمة
`REQ-R1-W1.1-001` حتى `REQ-R1-W1.1-010` وبوابة W1.1 فقط.

لا تدخل في هذه الحزمة موجات W1.2 وما بعدها، ولا الهوية الإنتاجية الكاملة، ولا RBAC +
ABAC، ولا منشئ أنواع الأعمال، ولا Workflow، ولا البحث أو التقارير. لا يعاد بناء المسار
المحلي المثبت إلا لمعالجة انحدار ظهر أثناء تنفيذ المهام.

## 2. خط الأساس المثبت

لا تعيد المهام التالية العمل المثبت في `make verify-w1-1`:

- تسجيل الدخول التطويري ونشر fixture النوع `request`.
- إنشاء WorkRecord في منشأتين وعزلهما بشكل متماثل.
- حفظ WorkRecord وOutbox في معاملة واحدة.
- Relay إلى Valkey واستهلاك idempotent وإنشاء إشعار وDLQ.
- رحلة React العربية RTL والإنجليزية LTR على MySQL وValkey ومتصفح حقيقي.

يبقى هذا الدليل محلياً ولا يثبت صورة إنتاج أو CI حياً أو مضيفاً أو نشر Dokploy أو
استعادة منفصلة.

## 3. عقد الاختبار والإثبات

### 3.1 مستويات الاختبار

| المستوى | الغرض | البيئة | قاعدة القبول |
| --- | --- | --- | --- |
| Unit | فحص schemas وparsers وقواعد السياسات والحالات السلبية | عملية معزولة وfixtures آمنة | حتمي، بلا شبكة أو أسرار أو مضيف فعلي |
| Integration | تشغيل أدوات البناء وDocker وCompose وMySQL وValkey والتوقيع والنسخ معاً | CI أو بيئة مؤقتة | موارد حقيقية مؤقتة وتنظيف مضمون بعد النجاح والفشل |
| E2E | إثبات السلوك من خارج النظام على Staging أو المضيف المستهدف | Dokploy/Host وهدف استعادة منفصل عند الحاجة | فحص أسود الصندوق مع receipt مؤرخ وخالٍ من الأسرار |

الاختبار المحلي أو fixture لا يحل محل تجربة المضيف. إذا غابت مدخلات المضيف أو صلاحية
Dokploy أو هدف الاستعادة، تبقى المهمة `blocked-external` ولا تسجل ناجحة.

### 3.2 مداخل التحقق المطلوبة

| الأمر المستهدف | ما يثبته |
| --- | --- |
| `make verify-w1-1` | المسار الوظيفي المحلي الحالي والعقود والحدود |
| `make test-unit-w11-ops-01` | وحدات schema وvalidator لمدخلات المضيف والحالات السلبية |
| `make test-integration-w11-ops-01` | probes مؤقتة لـpreflight وفشل DNS والقرص والregistry وهدف النسخ مع redaction |
| `make test-e2e-w11-ops-01-local` | رحلة CLI محلية لعقد المثال وreceipt منقح؛ لا تثبت مضيفاً أو Staging حياً |
| `make verify-build` | image digest وSBOM وprovenance والتوقيع وrelease descriptor؛ يتطلب `RELEASE_DESCRIPTOR` و`COSIGN_BINARY` و`COSIGN_VERSION` و`COSIGN_PUBLIC_KEY`، ويستخدم `RELEASE_ROOT` للجذر الفعلي |
| `make verify-w1-1-host` | فحص المضيف مباشرة عبر `preflight` + `verify-host` و`verify-edge`؛ يتطلب `HOST_INPUTS` و`HOST_RECEIPT` و`NET04_POLICY` و`NET04_COMPOSE` وإيصالات المنظورات الثلاثة و`NET04_REVISION` |
| `python3 scripts/net04_network_policy.py verify-host ...` و`verify-edge ...` | فحص NET-04 read-only من المضيف ومنظوري المستخدم والإدارة بمدخلات خارج Git |
| `python3 scripts/deployment_evidence.py --evidence ... --evidence-root ... --receipt ...` | يبني receipt غير موقع مرتبطاً بمحتوى أدلة Dokploy N→N+1→rollback بعد التقاطها من Dokploy/Staging |
| `python3 scripts/backup_restore_evidence.py --manifest ... --restore ... --receipt ... --artifact-root ... --evidence-root ... --artifact-path ... --signature-path ... --bundle-path ... --public-key ... --cosign-binary ... --cosign-sha256 ... --cosign-version ... --as-of ...` | تحقق manifest النسخة وrestore المنفصل وقياس RPO/RTO بعد تنفيذ خارجي فعلي |
| `make verify-w1-1-all` | يفحص المدخلات الحية ثم يجمع `verify-w1-1-local` و`verify-build` و`verify-w1-1-host` قبل تشغيل `w1_1_acceptance_gate.py` |

مخرجات الأدلة الحية تُمرّر عبر متغيرات `verify-w1-1-all` المطلوبة؛ ولا تغير دلالة
`make verify-w1-1` المحلي.
خيار DEP-05 `--dry-run` للتخطيط غير المقبول فقط؛ لا يقرأ evidence ولا يتحقق منه ولا
يستبدل تشغيل الأمر العادي عند بناء receipt الأدلة الملتقطة.

### 3.3 تعريف الإنجاز لكل مهمة

- مخرجات الكود والتهيئة والعقود موجودة في Git بلا أسرار.
- Unit وIntegration وE2E المحددة للمهمة خضراء، أو E2E الخارجية مسجلة صراحة كحاجز
  خارجي بمالك ومدخل مفقود دون ادعاء نجاح.
- الحالات السلبية تفشل مغلقة ولا تسرب قيماً حساسة إلى logs أو artifacts.
- دليل القبول يربط Task ID وREQ/TEST IDs وGit revision ووقت التنفيذ والنتيجة.
- الانحدارات في `make verify-w1-1` غير مقبولة.

## 4. ترتيب التنفيذ

| الترتيب | Task ID | المهمة | التبعيات | فجوة الحالة النشطة |
| --- | --- | --- | --- | --- |
| 1 | W11-OPS-01 | عقد مدخلات المضيف وPreflight | لا يوجد | 1 |
| 2 | W11-BLD-02 | صور الإنتاج وحزمة Compose المثبتة | W11-OPS-01 | 2 جزئياً |
| 3 | W11-SC-03 | سلسلة التوريد وCI وحزمة الإصدار | W11-BLD-02 | implemented-local / blocked-external |
| 4 | W11-NET-04 | شبكات الحاويات وجدار المضيف | W11-OPS-01, W11-BLD-02 | implemented-local / blocked-external |
| 5 | W11-DEP-05 | نشر Dokploy والرجوع | W11-SC-03, W11-NET-04 | implemented-local / blocked-external |
| 6 | W11-DR-06 | النسخ المشفر والاستعادة المنفصلة | W11-SC-03, W11-NET-04 | implemented-local / blocked-external |
| 7 | W11-GATE-07 | CI الحي وبوابة قبول W1.1 | W11-OPS-01..W11-DR-06 | implemented-local / blocked-external |

يمكن تنفيذ W11-SC-03 وW11-NET-04 بالتوازي بعد W11-BLD-02. لا يبدأ النشر أو تمرين
الاستعادة قبل تثبيت descriptor الحزمة وعقد المضيف.

## 5. المهام التنفيذية

### W11-OPS-01: عقد مدخلات المضيف وPreflight

**المالك:** قائد SRE.
**المتطلبات:** REQ-R1-W1.1-001، REQ-R1-W1.1-002.
**اختبارات القبول:** تمهيد TEST-R1-W1.1-03 وTEST-R1-W1.1-05.

**الحالة في 2026-07-17:** `implemented-local / blocked-external`. اكتمل schema المغلق
ومثال المدخلات وقائمة أسماء الأسرار وvalidator وpreflight القراءة فقط وreceipt المنقح،
واجتازت 20 Unit و14 Integration ورحلة CLI محلية واحدة. لا تعد هذه الحالة قبول E2E؛
يبقى ملف المضيف المعتمد وتشغيل Staging المصادق عليه وتوقيع receipt بيد قائد SRE خارج Git.

**الأدلة المحلية:** `make verify-w11-ops-01-local`، و`make preflight-w11-ops-01-live`
هو المدخل الحي الذي يفشل مغلقاً عند غياب ملف المضيف أو اعتمادات القراءة فقط. الفحوص الحية
تطابق هوية مشروع Dokploy ومستودع registry وهدفي النسخ والاستعادة، وترفض redirects ولا
تسجل headers أو response bodies.

**النطاق والمخرجات:**

- schema محكوم لمدخلات المضيف غير السرية: نطاق المستخدم، مسار الإدارة، CIDRs المسموحة،
  المنافذ، registry، مشروع Dokploy، مواقع volumes، وهدف النسخ والاستعادة.
- مثال آمن خالٍ من القيم الفعلية، وقائمة منفصلة بأسماء الأسرار ومصدرها دون قيمها.
- preflight read-only يتحقق من Docker/Compose/Dokploy والقرص والوقت وDNS/TLS والوصول
  إلى registry وهدف النسخ، وينتج receipt منقحاً.
- قرار مالك المضيف على القيم الفعلية خارج Git.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | قبول عقد صحيح؛ رفض الحقول الناقصة، CIDR إدارة عام، منفذ حالة منشور، سر مضمن، ومسار نسخ يساوي قرص الإنتاج |
| Integration | تشغيل preflight على VM/حاوية مؤقتة؛ محاكاة نقص القرص وفشل DNS وregistry وهدف النسخ؛ التحقق من redaction |
| E2E | تشغيل read-only على Staging host الحقيقي من مسار الإدارة، وحفظ receipt موقع يثبت الهوية والوقت والنتيجة دون أسرار |

**معايير القبول:** العقد يمر مع مدخلات المضيف المعتمدة، ويفشل مغلقاً لكل قيمة خطرة،
وتظهر المدخلات الخارجية المفقودة بأسماء واضحة ومالك، ولا يغير preflight حالة المضيف.

**خارج النطاق:** تثبيت Dokploy أو تغيير الجدار أو إنشاء أسرار فعلية.

### W11-BLD-02: صور الإنتاج وحزمة Compose المثبتة

**المالك:** SRE مع Backend Lead وFrontend Lead.
**التبعية:** W11-OPS-01.
**المتطلبات:** REQ-R1-W1.1-001، REQ-R1-W1.1-002، REQ-R1-W1.1-009.

**الحالة في 2026-07-17:** `implemented-local`. أضيفت صور Laravel وReact متعددة المراحل
بـbase digests مثبتة وruntime غير root، وحزمة Compose مسبقة البناء لـWeb وAPI وworker
وscheduler وMySQL وValkey، مع شبكة حالة داخلية وvolume وhealthchecks وأسرار runtime فقط.
اجتازت 18 Unit و4 Integration والبناء وفحص محتوى الصورتين ورحلة E2E كاملة على Compose.

**الأدلة المحلية:** `make verify-w11-bld-02-local` يبني من lockfiles، يفحص عدم وجود
Composer وNode والاختبارات داخل runtime، يشغل migrations، يعيد تشغيل Valkey والـworker،
ثم يجتاز رحلتي العربية RTL والإنجليزية LTR وعزل المنشأتين. يستخدم E2E بيئة `testing`
المحدودة لتمكين fixture الدخول؛ يبقى Compose افتراضياً على `production`. التوقيع وSBOM
وprovenance وrelease descriptor ونشر Dokploy خارج هذه المهمة.

**النطاق والمخرجات:**

- Dockerfiles متعددة المراحل لـLaravel وReact، تبني من lockfiles وتحتوي runtime فقط.
- Compose إنتاجي يشغل Web/API وworker وscheduler وMySQL وValkey، مع healthchecks
  وvolumes وشبكات داخلية وعقد متغيرات بيئة، دون أسرار في Git.
- تثبيت base images بالـdigest ومنع `latest` ومنع تنزيل حزم عند startup.
- policy validator لحزمة الإنتاج وfixtures صحيحة وخاطئة.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | اكتشاف `latest`، base image بلا digest، port عام لـMySQL/Valkey، غياب healthcheck، secret literal، أو أمر install في runtime |
| Integration | بناء نظيف من lockfiles؛ تشغيل Compose المؤقت؛ migrations؛ healthchecks؛ restart للworker وValkey؛ تشغيل بلا شبكة بعد توفر الصور |
| E2E | تشغيل حزمة الإنتاج على Staging محلي/مؤقت ثم اجتياز رحلتي العربية والإنجليزية وعزل المنشأتين من endpoint المستخدم فقط |

**معايير القبول:** تبدأ الحزمة من images مبنية مسبقاً بلا تنزيل runtime، ولا يظهر للعامة
إلا HTTP المطلوب، وتنجح الرحلة المحلية الحالية دون تعديل يدوي داخل الحاويات.

**خارج النطاق:** النشر على Prod أو توقيع الصورة؛ ينفذان في المهمتين التاليتين.

### W11-SC-03: سلسلة التوريد وCI وحزمة الإصدار

**المالك:** قائد SRE ومهندس الجودة.
**التبعية:** W11-BLD-02.
**المتطلبات:** REQ-R1-W1.1-009، REQ-R1-W1.1-010.
**اختبارات القبول:** TEST-R1-W1.1-04.

**الحالة الحالية:** `implemented-local / blocked-external`. توجد أداة
`scripts/release_descriptor.py` وعقد `infra/platform/release/release-descriptor.schema.json`
واختبارات descriptor/CI محلية. يثبت السطح الحالي hashes صريحة للصور وSBOM وprovenance وCompose؛
أما hashes خطط migration/rollback بعد remediation وتنفيذها الحي وسجلها فجزء من W11-DEP-05،
وليس دليلاً محلياً.

**النطاق والمخرجات:**

- jobs حية في GitHub Actions للبناء والاختبارات والعقود والحدود وE2E وبناء صور OCI.
- SBOM لكل صورة، provenance، فحص أسرار وثغرات وتراخيص، وتوقيع قابل للتحقق بأداة
  وإصدار مثبتين.
- release descriptor schema يربط Git revision وCompose revision وimage digests وSBOM
  وprovenance والتوقيع؛ وتضاف hashes لخطة migration/rollback المعتمدة بعد remediation،
  بينما يبقى تنفيذها الحي وسجلها ضمن W11-DEP-05.
- `make verify-build` يعيد التحقق من artifacts ولا يعيد الثقة باسم tag.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | validator للdescriptor؛ رفض digest/tag متعارض، SBOM مفقود، توقيع خاطئ، revision غير مطابق، artifact زائد غير منسوب |
| Integration | بناء صورتين متطابقتين من revision واحد؛ توليد SBOM/provenance؛ توقيع ثم verify؛ كشف تعديل artifact بعد التوقيع |
| E2E | pipeline حية على merge معتمد تنتج artifacts المحتفظ بها، وتنجح إعادة `make verify-build` على runner مستقل |

**معايير القبول:** لا ينشر pipeline عند فشل أي بوابة، وكل artifact منسوب إلى digest وGit
revision واحد، والتوقيع وSBOM قابلان للتحقق خارج job الذي أنشأهما.

**خارج النطاق:** مفاتيح فعلية داخل المستودع أو نشر تلقائي غير محمي إلى Prod.

### W11-NET-04: شبكات الحاويات وجدار المضيف

**المالك:** SRE ومسؤول أمن المعلومات.
**التبعيات:** W11-OPS-01 وW11-BLD-02.
**المتطلبات:** REQ-R1-W1.1-001.
**اختبار القبول:** TEST-R1-W1.1-05.

**الحالة الحالية:** `implemented-local / blocked-external`. توجد policy وverifier في
`infra/platform/network/` و`scripts/net04_network_policy.py` واختبارات سلبية وحية قابلة
للتشغيل. مثال policy يستخدم عناوين placeholder؛ يلزم policy معتمدة خارج Git وفحص فعلي من
مسار الإدارة ومسار المستخدم قبل قبول المهمة.

**النطاق والمخرجات:**

- شبكة frontend تقبل Traefik إلى خدمات HTTP فقط، وشبكة state داخلية لـMySQL وValkey.
- سياسة جدار مضيف default-deny: HTTPS للمستخدم، وSSH/Dokploy من شبكة الإدارة فقط.
- منع نشر MySQL وValkey وDocker socket وواجهة Dokploy على مسار المستخدم.
- verifier read-only يجمع المنافذ وقواعد الجدار وشبكات Compose في receipt منقح.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | fixtures لقواعد مسموحة ومرفوضة؛ كشف `0.0.0.0` لخدمة حالة، Docker socket mount، إدارة عامة، أو network غير internal |
| Integration | فحص اتصال داخل Compose: API يصل MySQL/Valkey، Web لا يصل state مباشرة، وحاوية غير موثوقة لا تعبر الشبكة الداخلية |
| E2E | scan من شبكة المستخدم ومن شبكة الإدارة: 443 متاح للمستخدم، الإدارة متاحة فقط لمسارها، و3306/6379/Docker/Dokploy العام كلها مغلقة |

**معايير القبول:** نتائج المنظورين متطابقة مع العقد، ولا يحوي receipt عناوين أو أسرار أكثر
مما يلزم، وأي منفذ حالة مكشوف يجعل فحص NET-04 عبر `scripts/net04_network_policy.py verify-host`
غير ناجح.

**خارج النطاق:** ادعاء HA أو تغيير سياسات شبكة المؤسسة خارج المضيف دون موافقة مالكها.

### W11-DEP-05: نشر Dokploy والرجوع

**المالك:** قائد SRE.
**التبعيات:** W11-SC-03 وW11-NET-04.
**المتطلبات:** REQ-R1-W1.1-001، REQ-R1-W1.1-002، REQ-R1-W1.1-009.
**اختبارات القبول:** TEST-R1-W1.1-03 وTEST-R1-W1.1-06.

**الحالة الحالية:** `implemented-local / blocked-external`. توجد عقود
`infra/platform/contracts/dokploy-release-evidence.schema.json` وأداة
`scripts/deployment_evidence.py` واختبارات تحقق مغلقة. لا توجد نتيجة نشر Dokploy أو rollback
حية؛ يجب التقاط revisionين وhealth وmigration compatibility وpre-backup والرحلتين العربية
والإنجليزية من البيئة المستهدفة ثم تمريرها للأداة.

**النطاق والمخرجات:**

- تعريف تطبيق Dokploy من release descriptor المثبت، بعقود أسرار وhealthchecks واضحة.
- pre-deploy يفحص النسخة المتاحة والقرص وdescriptor وmigrations وcompatibility.
- post-deploy يفحص health والرحلة الرفيعة ومقياسها تحت خمس ثوانٍ.
- مسار rollback إلى آخر descriptor معروف بالصحة دون down migration هدامة.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | قبول/رفض خطة نشر حسب descriptor وmigration compatibility ووجود rollback target؛ redaction لأخطاء Dokploy |
| Integration | نشر revision مؤقت إلى Dokploy/Staging، فشل health متعمد، استدعاء rollback، والتحقق من worker/scheduler والمigrations |
| E2E | زرع سجلين، نشر N+1، تنفيذ رحلة العربية والإنجليزية، فرض فشل، الرجوع إلى N، ثم إثبات health والعزل وبقاء البيانات |

**معايير القبول:** يحتفظ سجل Dokploy بالـrevisionين والنتيجة، وينجح الرجوع بلا CLI بنيوي
يدوي أو فقد بيانات، ويعود آخر descriptor معروف بالصحة خلال النافذة المعتمدة.

**خارج النطاق:** down migrations هدامة أو تعديل بيانات الإنتاج يدوياً لإجبار الرجوع.

### W11-DR-06: النسخ المشفر والاستعادة على هدف منفصل

**المالك:** SRE مع DBA ومسؤول أمن المعلومات.
**التبعيات:** W11-SC-03 وW11-NET-04.
**المتطلبات:** بوابة W1.1 التشغيلية وADR-023.
**اختبار القبول:** TEST-R1-W1.1-07.

**الحالة الحالية:** `implemented-local / blocked-external`. توجد عقود
`backup-restore-evidence.schema.json` و`restore-receipt.schema.json` وأداة
`scripts/backup_restore_evidence.py` واختبارات حساب الحدود. لم ينفذ backup/PITR أو restore
على هدف مستقل؛ لا تُحفظ قيم المفاتيح أو بيانات النسخة في Git، ويجب تقديم manifest موقّعاً
وإيصال restore منفصلاً من التمرين الفعلي.

**النطاق والمخرجات:**

- مهمة backup كاملة مع binlog/PITR تحقق `RPO <= 15 دقيقة`، وتكتب إلى هدف مستقل مشفر
  باعتمادات منفصلة.
- manifest وchecksum وتوقيع واحتفاظ immutable/WORM أو بديل معتمد.
- restore automation إلى شبكة وهدف منفصلين، يعيد MySQL والملفات ثم يبني Valkey والإسقاطات
  من مصادر الحقيقة.
- receipt يقيس RPO وRTO ويذكر النسخة والنقطة والانحرافات دون بيانات حساسة.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | validator للmanifest والاحتفاظ؛ رفض checksum أو توقيع أو تشفير أو target identity خاطئ؛ حساب RPO/RTO والحدود |
| Integration | seed ثم full backup وPITR؛ إفساد نسخة والتأكد من رفضها؛ استعادة نسخة سليمة إلى MySQL مؤقت والتحقق من schema والعينات |
| E2E | إعلان تمرين، استعادة الحزمة إلى هدف منفصل، تشغيل health وAPI والرحلة الرفيعة، قياس RPO <= 15 دقيقة وRTO <= ساعتين |

**معايير القبول:** لا تستعاد النسخة فوق Prod، ولا يعد backup ناجحاً قبل checksum والتوقيع،
ويثبت الهدف المنفصل أن البيانات والسجلات الحرجة قابلة للاستخدام ضمن القياسين.

**خارج النطاق:** ادعاء توافر عالٍ على خادم واحد أو استخدام Valkey كنسخة أعمال.

### W11-GATE-07: CI الحي وبوابة قبول W1.1

**المالك:** قائد التقنية ومهندس الجودة.
**التبعيات:** جميع المهام السابقة.
**المتطلبات:** REQ-R1-W1.1-001 حتى REQ-R1-W1.1-010.
**اختبار القبول:** TEST-R1-W1.1-08.

**الحالة الحالية:** `implemented-local / blocked-external`. يجمع
`scripts/w1_1_acceptance_gate.py` الأدلة offline ويرفض receipt المحلي أو القديم أو المختلف
في revision، لكنه لا ينشئ أدلة ولا موافقات. يتطلب الإغلاق pipeline وhost/NET وDokploy وrestore
الحية وTEST-R1-W1.1-01..08 وموافقات Go المسماة بعد اكتمال كل البوابات الآلية.

**النطاق والمخرجات:**

- evidence manifest schema يربط كل REQ/TEST/Task بالـcommit والأمر والنتيجة والartifact
  والوقت والمالك.
- `make verify-w1-1-all` يفحص المدخلات الحية ثم يجمع `verify-w1-1-local` و`verify-build`
  و`verify-w1-1-host` قبل تشغيل `w1_1_acceptance_gate.py`. تتطلب البوابة
  `GATE_MANIFEST` و`GATE_TRUST_POLICY` و`GATE_RELEASE_ROOT` و`GATE_EVIDENCE_ROOT`
  و`GATE_RECEIPT` و`GATE_AS_OF` و`COSIGN_BINARY` و`COSIGN_SHA256` و`COSIGN_VERSION`.
  ويتطلب `verify-build` أيضاً `RELEASE_DESCRIPTOR` و`COSIGN_PUBLIC_KEY` ويستخدم
  `RELEASE_ROOT`، بينما يتطلب مسار المضيف `HOST_INPUTS` و`HOST_RECEIPT` و`NET04_POLICY`
  و`NET04_COMPOSE` و`NET04_HOST_RECEIPT` و`NET04_USER_RECEIPT`
  و`NET04_MANAGEMENT_RECEIPT` و`NET04_REVISION`.
- pipeline حية واحدة على revision واحد تحفظ تقارير Unit وIntegration وE2E وSBOM والتوقيع
  والنشر والرجوع والاستعادة.
- مراجعة Go/No-Go محدودة ببوابة W1.1 وتحديث حالة التسليم بعد اعتماد الأدلة.

| مستوى الاختبار | الحالات الإلزامية |
| --- | --- |
| Unit | رفض evidence ناقص أو قديم أو من commit مختلف أو مكرر أو بلا مالك؛ التحقق من عدم احتواء الأسرار |
| Integration | تشغيل بوابات المحلي والبناء على revision واحد؛ التحقق المتبادل من digests وCompose وSBOM وreceipts |
| E2E | GitHub Actions خضراء حية، نشر وrollback، scan منافذ، restore منفصل، ثم رحلة `login → request → notification → isolation` تحت خمس ثوانٍ على Staging |

**معايير القبول:** لا توجد بوابة متجاوزة أو receipt محلي يدعي حدثاً خارجياً، وكل الأدلة من
revision واحد أو سلسلة مراجعة موثقة. لا تنقل هذه المهمة W1.1 إلى مكتمل قبل وصول الأدلة الحية
والموافقات؛ عندها فقط تفتح W1.2.

**خارج النطاق:** إعلان جاهزية R1 الكاملة أو بدء W1.2 قبل توقيع بوابة W1.1.

## 6. مصفوفة اختبارات القبول

| TEST ID | السيناريو | المستوى النهائي | المهمة |
| --- | --- | --- | --- |
| TEST-R1-W1.1-01 | تسجيل دخول ثم طلب ثم إشعار | E2E | خط الأساس + W11-GATE-07 |
| TEST-R1-W1.1-02 | عزل منشأتين بعد نشر النوع | Integration + E2E | خط الأساس + W11-GATE-07 |
| TEST-R1-W1.1-03 | descriptor مثبت ينشر عبر Dokploy بلا جلب runtime | Integration + E2E | W11-BLD-02, W11-SC-03, W11-DEP-05 |
| TEST-R1-W1.1-04 | SBOM وتوقيع قابلان للتحقق | Unit + Integration | W11-SC-03 |
| TEST-R1-W1.1-05 | المنافذ المسموحة فقط متاحة وخدمات الحالة غير عامة | Unit + Integration + E2E | W11-NET-04 |
| TEST-R1-W1.1-06 | نشر N+1 ثم rollback إلى N مع بقاء الصحة والبيانات | Integration + E2E | W11-DEP-05 |
| TEST-R1-W1.1-07 | استعادة على هدف منفصل ضمن RPO/RTO | Unit + Integration + E2E | W11-DR-06 |
| TEST-R1-W1.1-08 | بوابات المحلي والبناء والمضيف وCI الحي خضراء على revision واحد | Unit + Integration + E2E | W11-GATE-07 |

## 7. بوابة الإغلاق

لا تغلق W1.1 حتى تتحقق الشروط مجتمعة:

1. جميع المهام W11-OPS-01 حتى W11-GATE-07 مقبولة بالأدلة.
2. بوابة W1.1 النهائية عبر `scripts/w1_1_acceptance_gate.py` + `make verify-build` مع
   GitHub Actions حية خضراء على Git revision نفسه.
3. release descriptor وتوقيعه وSBOM وprovenance وCompose revision متطابقة.
4. scan المضيف ونشر Dokploy والرجوع والاستعادة المنفصلة موثقة وليست محاكاة محلية.
5. TEST-R1-W1.1-01 حتى TEST-R1-W1.1-08 خضراء ولا توجد استثناءات حرجة.
6. يوقع قائد التقنية وقائد SRE ومسؤول أمن المعلومات قبول البوابة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
| --- | --- | --- | --- |
| 1.2.0 | 2026-07-17 | مكتب هندسة المنصة | إثبات W11-BLD-02 محلياً بحزمة إنتاج مسبقة البناء واختبارات Unit وIntegration ورحلتي E2E |
| 1.1.0 | 2026-07-17 | مكتب هندسة المنصة | إثبات تنفيذ W11-OPS-01 المحلي وفصل نجاح CLI المحلي عن قبول Staging الخارجي |
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | تحويل فجوات W1.1 الخمس إلى سبع مهام مرتبة بعقد Unit وIntegration وE2E وبوابة إثبات نهائية |
