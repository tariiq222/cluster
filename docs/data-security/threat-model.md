---
doc_id: SEC-AU-002
title: نموذج التهديدات والثقة
type: data-security
status: draft
version: 0.2.0
date: 2026-07-15
owner: مسؤول أمن المعلومات
reviewers:
- مكتب هندسة المنصة
- مسؤول العمليات
classification: internal
review_cycle: نصف سنوي
sources: []
references:
- docs/architecture/c4-and-flows.md
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/data-security/logical-data-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/audit-and-privacy.md
- docs/data-security/file-security.md
---
# نموذج التهديدات والثقة

## 1. الغرض والنطاق

يحدد هذا المستند نموذج التهديدات للمنصة الإدارية للتجمع الصحي الثالث وفق منهجية STRIDE، ويربط كل تهديد بضابط تحكم واختبار قابل للتنفيذ، ويطابق متطلبات:

- **نظام حماية البيانات الشخصية PDPL** ولائحته التنفيذية.
- **ضوابط الأمن السيبراني الأساسية NCA ECC** بنسختها المعتمدة محلياً.
- **معايير مكتب إدارة البيانات الوطنية NDMO** لتصنيف البيانات وإدارة دورة حياتها.

### 1.1 حدود النطاق

يدخل ضمن النطاق:

- المنصة الإدارية وخدماتها الخلفية وبياناتها.
- بيانات الهوية الوظيفية للموظفين وبيانات الأعمال الإدارية.
- البنية التحتية داخل مركز بيانات التجمع (Kubernetes، MySQL، Object Storage، Queue، Search).
- قنوات الإدارة وقنوات الطوارئ Break-glass.
- قنوات التدقيق والتصدير اليومي.

لا يدخل ضمن النطاق:

- بيانات المرضى السريرية والسجل الطبي وأنظمة HIS/EMR.
- أنظمة «موارد» والنظام المالي والمشتريات.
- التكاملات الخارجية في المرحلة الأولى.
- البريد الإلكتروني للموظف وبياناته الشخصية خارج المنصة.

### 1.2 طبيعة البيانات

المنصة غير سريرية. تعالج فقط:

- بيانات الهوية الوظيفية (PII للموظف): الاسم، الهوية الوطنية، جهة العمل، المنصب، البريد المهني، الهاتف المهني.
- بيانات الأعمال الإدارية: الطلبات، المهام، المستندات الإدارية، العقود الإدارية، المشاريع، المخاطر المؤسسية، المؤشرات، اللجان، المراسلات الإدارية.
- بيانات التصنيف والصلاحيات والأدوار والعلاقات الإشرافية.
- سجلات التدقيق وسجلات الوصول والاطلاع.

### 1.3 افتراضات التهديد

- الشبكة الداخلية مفترض أنها غير موثوقة كلياً ويُعامل أي جهاز متصل بها كجهاز مرتاب.
- يوجد مستخدمون ذوو صلاحيات قد يسئون استخدام قدراتهم.
- قد يحاول مهاجم داخلي أو خارجي اختراق طبقة الإدارة أو قنوات الطوارئ.
- قد يحاول مسؤول التلاعب بسجل التدقيق.
- قد يحدث فقدان أو سرقة جهاز فعلي.

## 2. منهجية STRIDE

تُصنف التهديدات ضمن ست فئات:

| الفئة | الرمز | المعنى | أثرها الأساسي |
|---|---|---|---|
| الانتحال | S | انتحال هوية مستخدم أو خدمة أو عقدة | تجاوز حدود الوصول |
| التلاعب | T | تعديل بيانات أو كود أو إعدادات دون إذن | فقدان سلامة البيانات |
| الإنكار | R | إنكار المستخدم لفعل أو إنكار النظام لحدوث حدث | فقدان المساءلة |
| الإفصاح | I | كشف معلومات لغير المصرح لهم | خرق السرية |
| الحرمان | D | منع المستخدمين الشرعيين من الخدمة | توقف الأعمال |
| التصعيد | E | اكتساب قدرة أعلى من الممنوحة | اختراق حدود الصلاحية |

## 3. حدود الثقة Trust Boundaries

| المعرف | اسم الحد | ما يفصله | نمط العبور المسموح |
|---|---|---|---|
| TB-1 | متصفح المستخدم ↔ الشبكة الداخلية | متصفح الموظف على شبكته الداخلية وشبكة VLAN إدارة المنصة | HTTPS داخلي فقط مع mTLS اختياري للخدمات الحساسة |
| TB-2 | الشبكة الداخلية ↔ Web/API | جهاز الموظف وطبقة البوابة الداخلية وطبقة التطبيق | HTTPS مع جلسة قصيرة العمر وCSRF token |
| TB-3 | Web/API ↔ قاعدة البيانات | خدمتي API وMySQL | اتصال شبكة داخلي مغلق، حساب DB بصلاحيات أدنى، TLS داخلي |
| TB-4 | Web/API ↔ Object Storage | خدمتي API وتخزين الملفات | حساب خدمة بأذونات محددة لكل مساحة، طلبات موقعة، شبكة داخلية |
| TB-5 | Worker ↔ قاعدة البيانات | العامل وقاعدة البيانات | نفس قيود TB-3 مع فصل الحساب |
| TB-6 | Worker ↔ Object Storage | العامل والتخزين | نفس قيود TB-4 مع حساب منفصل |
| TB-7 | قناة الإدارة (سوبر أدمن) | واجهة الإدارة وحساب خاص | قناة مفصولة VLAN، صلاحيات موسعة مع تدقيق |
| TB-8 | قناة الطوارئ Break-glass | حساب طوارئ مغلق وآلية تفعيل | إجراء ثنائي الأشخاص مع توثيق وتسجيل |
| TB-9 | قناة النسخ الاحتياطي والاستعادة | الإنتاج ومخزن النسخ | حساب منفصل، تشفير، توقيع، فصل فيزيائي |
| TB-10 | قناة تصدير التدقيق اليومي | الإنتاج ومخزن التدقيق المنفصل | توقيع رقمي، حساب قراءة فقط، فصل فيزيائي |
| TB-11 | قناة CI/CD والبناء | GitHub Actions ومستودع المصدر | صلاحيات read-only، actions مثبتة، فحص أسرار واعتماديات |
| TB-12 | قناة الخروج Egress | حاويات التطبيق والإنترنت الخارجي | أقل وصول لازم مع جدار ناري ومراجعة الاتصالات |

## 4. مصفوفة STRIDE على حدود الثقة

### 4.1 TB-1 متصفح ↔ الشبكة الداخلية

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | انتحال متصفح موظف عبر سرقة الجلسة | قصر الجلسة على IP داخلي مع Refresh بعد تغيره، ربط الجلسة بالبصمة الخفيفة | `IdentitySessionTest::session_invalidated_on_ip_change` |
| S | إعادة استخدام Token مسروق | JWT قصير مع Refresh منفصل، إبطال فوري عند تغيير كلمة المرور | `IdentitySessionTest::stolen_refresh_token_rejected` |
| T | حقن محتوى عبر XSS | CSP صارم، تعقيم المدخلات في الخلفية، تشفير HTML في React | `SecurityTest::csp_blocks_inline_scripts` |
| I | كشف بيانات عبر cache المتصفح | رؤوس `Cache-Control: no-store` على المحتوى الحساس | `HttpHeaderTest::sensitive_responses_have_no_store` |
| D | إغراق تسجيل الدخول | Rate limit على `/auth/login` و`/auth/password` | `IdentitySessionTest::login_rate_limit_enforced` |
| E | مصادقة مرتفعة عبر تلاعب المتغير المحلي | قرار الصلاحية في الخلفية فقط، عدم الاعتماد على JS | `AuthorizationTest::client_hints_ignored_on_server` |

### 4.2 TB-2 الشبكة الداخلية ↔ Web/API

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تزوير طلب عبر CSRF | CSRF token على كل mutation، SameSite=Lax على الكوكي | `HttpTest::csrf_token_required_on_mutations` |
| T | تعديل الطلب في النقل | TLS داخلي، رؤوس HSTS، توقيع HMAC اختياري للأحداث الحساسة | `HttpTest::tls_required_on_internal_endpoints` |
| R | إنكار إجراء | سجل تدقيق يسبق النتيجة لكل فعل حساس | `AuditTest::audit_written_before_response` |
| I | إفصاح عبر رسائل خطأ مفصلة | تعميم رسائل الخطأ، تسجيل التفاصيل داخلياً فقط | `HttpTest::error_messages_redacted_in_response` |
| D | إغراق API | Rate limit لكل مستخدم ولكل IP، قائمة سوداء مؤقتة | `RateLimitTest::per_user_and_per_ip_enforced` |
| E | استدعاء قدرة غير ممنوحة | قرار قدرة مركزي قبل كل Controller | `AuthorizationTest::capability_check_before_controller` |

### 4.3 TB-3 Web/API ↔ قاعدة البيانات

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | انتحال حساب قاعدة بيانات | حساب DB منفصل لكل خدمة بأقل صلاحيات، تدوير كلمات المرور | `DbRoleTest::api_role_lacks_drop_and_alter` |
| T | تعديل جداول الموديولات الأخرى | مستخدم DB بـ grants على schema الخاص فقط، اختبار معماري | `BoundaryTest::module_cannot_query_other_module_tables` |
| R | إنكار كتابة في الجداول | كل عملية كتابة حساسة تكتب في Outbox + Audit داخل نفس الـTransaction | `OutboxTest::event_written_in_same_transaction` |
| I | قراءة جداول كاملة عبر تقارير | Read Model مسموح، Joins يدوية بين موديولات ممنوعة | `BoundaryTest::cross_module_join_via_readmodel_only` |
| D | استنزاف الاتصالات | Connection pool محدود، Circuit breaker عند نسخ MySQL المعطلة | `ResilienceTest::pool_does_not_exhaust_on_db_outage` |
| E | استعلام SQL مُحقن | Prepared statements إلزامية، ORM فقط، فحص استعلامات خام | `SecurityTest::raw_sql_blocked_in_module_code` |

### 4.4 TB-4 Web/API ↔ Object Storage

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تزوير طلب تحميل | رابط تحميل موقع بصلاحية قصيرة العمر (≤5 دقائق) | `DocumentsTest::presigned_url_short_ttl` |
| T | تعديل ملف بعد الرفع | الملف في الحجر حتى اجتياز الفحص، checksum يُحسب عند الإدخال ولا يُسمح بالتعديل | `DocumentsTest::storage_object_immutable_after_quarantine` |
| R | إنكار تحميل | `DocumentAccessEvent` يُكتب قبل إنشاء الرابط | `DocumentsTest::access_event_before_url_issued` |
| I | تسرب ملف عبر مشاركة الرابط | صلاحية الرابط للمستخدم المسجل فقط، فحص صلاحية عند كل GET | `DocumentsTest::url_does_not_bypass_authorization` |
| D | استنزاف التخزين | حصة لكل `OrgUnit`، رفض رفع عند تجاوز الحصة | `DocumentsTest::quota_enforced_per_orgunit` |
| E | قراءة ملف عبر صلاحية مرئية | تطبيق سياسة الحقل والمستند على كل تحميل | `DocumentsTest::download_respects_classification` |

### 4.5 TB-5 وTB-6 Worker والتخزين

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | انتحال هوية العامل | حساب خدمة مميز لكل عامل، مفاتيح في Secret داخلي | `WorkerTest::worker_uses_distinct_service_account` |
| T | تلاعب بـJob | idempotency key على كل job، إعادة تشغيل آمنة | `JobTest::duplicate_job_is_idempotent` |
| R | إنكار تنفيذ | سجل تنفيذ لكل job مرتبط بـ`event_id` | `OutboxTest::job_records_event_id` |
| I | كشف محتوى حساس في الـlogs | فلر PII في الـlogs، عدم كتابة payloads كاملة | `LoggingTest::pii_redacted_in_worker_logs` |
| D | تراكم jobs عالقة | Dead-letter queue، تنبيه السوبر أدمن، إعادة محاولة محدودة | `QueueTest::failed_job_lands_in_dlq` |
| E | عامل يصل لـnamespace أوسع | NetworkPolicy تحصر العامل في DB وObject Storage وCache فقط | `NetPolTest::worker_egress_limited` |

### 4.6 TB-7 قناة الإدارة (سوبر أدمن)

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | انتحال حساب إداري | MFA إلزامي للسوبر أدمن، فصل الحساب عن المستخدم العادي | `AdminTest::mfa_required_for_superadmin` |
| T | تعديل إعدادات محورية دون مراجعة | اعتماد مزدوج على تغييرات الحرج، تسجيل قبل وبعد | `AdminTest::dual_control_on_critical_changes` |
| R | إنكار إجراء إداري | تسجيل نص كامل للطلب والاستجابة في سجل التدقيق | `AdminTest::superadmin_actions_fully_logged` |
| I | اطلاع على محتوى حساس عبر صلاحية إدارية | تسجيل الاطلاع الحساس، فصل الاطلاع عن الإدارة حيث أمكن | `AuditTest::sensitive_view_by_admin_logged` |
| D | قفل حسابات بسبب خطأ إداري | تأكيد ثانوي قبل تعطيل حساب أو رفع صلاحية | `AdminTest::disabling_account_requires_second_factor` |
| E | تصعيد ذاتي عبر استغلال ثغرة | مراجعة فصل الصلاحيات، اختبارات حارسة شهرية | `AuthorizationTest::superadmin_cannot_self_grant_sensitive_caps` |

### 4.7 TB-8 قناة الطوارئ Break-glass

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تفعيل break-glass بهوية مسروقة | الحساب مغلق افتراضياً، التفعيل يتطلب حضور اثنين من المصرح لهم | `BreakGlassTest::activation_requires_two_authorized_people` |
| T | تعديل بيانات عبر break-glass دون ضابط | مدة الجلسة ≤60 دقيقة، كل إجراء مسجل في تدقيق منفصل | `BreakGlassTest::session_max_60_minutes` |
| R | إنكار استخدام الطوارئ | توقيع مسبق للإجراء، تقرير تلقائي للجنابة بعد الاستخدام | `BreakGlassTest::usage_produces_signed_incident_report` |
| I | استغلال الطوارئ لقراءة محتوى حساس | صلاحية الطوارئ مقيدة بقائمة بيضاء من الإجراءات، لا اطلاع عام | `BreakGlassTest::breakglass_capabilities_are_denylisted_others` |
| D | استخدام الطوارئ لتعطيل الخدمة | قائمة سوداء للإجراءات الممنوعة كلياً في break-glass | `BreakGlassTest::denied_actions_blocked` |
| E | تحويل الطوارئ لصلاحية دائمة | انتهاء تلقائي، مراجعة بعد كل استخدام | `BreakGlassTest::grant_auto_expires` |

### 4.8 TB-9 قناة النسخ الاحتياطي

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تزوير هوية النسخ | حساب منفصل في مخزن النسخ، مفاتيح منفصلة، قائمة IP | `BackupTest::backup_account_separate_from_app` |
| T | تعديل نسخة احتياطية | تشفير at-rest، توقيع، عدم قدرة على تعديل محفوظات سابقة | `BackupTest::backup_is_signed_and_immutable` |
| R | إنكار الاستعادة | سجل بكل استعادة يتضمن من وافق ومن نفّذ | `BackupTest::restore_recorded_with_dual_signoff` |
| I | كشف بيانات النسخة | تشفير بمفتاح منفصل عن الإنتاج، فصل فيزيائي | `BackupTest::backup_uses_distinct_kms_key` |
| D | عدم القدرة على الاستعادة | اختبار استعادة ربع سنوي، توثيق RPO/RTO | `DrTest::restore_meets_rpo_15_min` |
| E | قراءة بيانات حساسة عبر النسخة | استعادة ضمن شبكة معزولة، حذف النسخة بعد التحقق | `DrTest::restore_env_is_isolated` |

### 4.9 TB-10 قناة تصدير التدقيق اليومي

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تزوير طلب التصدير | حساب قراءة فقط، مصادقة ثنائية | `AuditExportTest::export_requires_mfa` |
| T | تعديل ملف التصدير بعد إنشائه | توقيع رقمي على الحزمة، حفظ hash خارجي | `AuditExportTest::export_signed_and_hash_published` |
| R | إنكار إنشاء التصدير | تسجيل بدء وانتهاء وفشل العملية | `AuditExportTest::lifecycle_logged` |
| I | إفصاح محتوى التصدير | نقل عبر قناة منفصلة، تشفير، فصل عن الإنتاج | `AuditExportTest::transfer_over_separate_channel` |
| D | تأخر التصدير | جدولة يومية، تنبيه عند الفشل، إعادة محاولة محدودة | `AuditExportTest::failure_alerts_within_15_min` |
| E | تصدير بيانات خارج النطاق | الحزمة تحتوي سجلات اليوم فقط، لا حقولاً إضافية | `AuditExportTest::export_scope_is_strict` |

### 4.10 TB-11 قناة CI/CD والبناء

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | تشغيل action معدلة | تثبيت كل GitHub Action بـcommit SHA كامل | مراجعة workflow |
| T | تعديل كود بلا تحقق | CI على كل push وpull request وبناء الصور من المصدر نفسه | فحوص CI |
| R | إنكار نشر | Git commit وسجل Compose ووقت النشر | سجل Git وDocker |
| I | تسرب أسرار | Gitleaks وملف `.env.production` خارج Git بصلاحية `600` | فحص CI وpreflight النشر |
| D | توقف النشر بسبب المصدر الخارجي | lockfiles وإبقاء آخر commit وصور سليمة على الخادم | تجربة rollback |
| E | تنفيذ تعليمات في طبقة البناء | Dockerfiles مراجعة ومستخدم runtime غير root | سياسة حزمة الإنتاج |

### 4.11 TB-12 قناة الخروج Egress

| الفئة | التهديد | الضابط | الاختبار |
|---|---|---|---|
| S | خدمة غير موثوقة تتصل خارجياً | حصر التكاملات ومراجعة وجهاتها | مراجعة الإعدادات |
| T | تحديث أثناء التشغيل | لا تثبيت حزم داخل حاوية runtime | سياسة حزمة الإنتاج |
| R | إنكار اتصال | سجلات Caddy وLaravel وDocker | مراجعة السجلات |
| I | تسرب بيانات عبر اتصال صادر | لا تكامل خارجي بلا عقد وتصنيف بيانات | اختبار التكامل |
| D | قطع الخدمة بسبب قاعدة جدار ناري | اختبار DNS وHTTPS وMySQL وRedis بعد التغيير | فحص الصحة |
| E | استخدام egress للتصعيد | مستخدم non-root ومنع privilege escalation | فحص الصور وCompose |

## 5. خريطة الامتثال

### 5.1 PDPL

| المادة/المبدأ | المتطلب | الضابط التقني | الاختبار |
|---|---|---|---|
| أساس المعالجة | معالجة البيانات بأساس نظامي أو موافقة | توثيق أساس المعالجة على كل نوع عمل وتصنيفه | `ComplianceTest::processing_basis_recorded` |
| تقليل البيانات | جمع الحد الأدنى | إخضاع كل حقل ديناميكي لقاعدة `required` ومراجعته | `WorkDefTest::fields_minimized_for_purpose` |
| دقة البيانات | ضمان دقة PII للموظف | صلاحية تعديل PII للمالك فقط، تدقيق كل تعديل | `IdentityTest::pii_edits_audited` |
| الاحتفاظ | مدة محددة بنوع العمل | `retention_until` على كل سجل، عملية إتلاف محكومة | `RetentionTest::retention_policy_enforced` |
| حقوق صاحب البيانات | الاطلاع والتصحيح والحذف ضمن الحدود | نموذج طلبات حقوق ضمن تدفق محكوم، استثناءات موثقة | `RightsTest::data_subject_request_workflow` |
| أمن البيانات | حماية PII بتدابير مناسبة | تشفير أعمدة PII، سياسات صلاحية حقل، تدقيق الاطلاع | `SecurityTest::pii_encryption_and_access_logging` |
| الإخطار بالاختراق | إخطار الجهة المختصة | آلية كشف تسرب بيانات PII وتنبيه تلقائي | `IncidentTest::pii_breach_detection_alerts` |
| نقل البيانات | عدم نقل خارج المملكة | لا وجهة بيانات خارجية بلا اعتماد وتصنيف | مراجعة التكاملات |

### 5.2 NCA ECC

| الضابط | العنوان | التطبيق على المنصة | الاختبار |
|---|---|---|---|
| 1-1-1 | حوكمة الأمن السيبراني | تعيين مسؤول أمن، اعتماد نموذج التهديد، مراجعة سنوية | `GovernanceTest::threat_model_reviewed_annually` |
| 1-2 |资产管理 | سجل أصول تلقائي لكل WorkRecord وStorageObject | `AssetTest::asset_inventory_complete` |
| 1-3 | حماية البيانات | تشفير at-rest وin-transit، إدارة مفاتيح | `CryptoTest::encryption_at_rest_and_in_transit` |
| 1-4 | إدارة الهوية والوصول | حسابات محلية، MFA للإدارة، فصل الصلاحيات | `IdentitySessionTest::*` |
| 1-5 | إدارة الحسابات المميزة | break-glass مفصول، توثيق، مراجعة | `BreakGlassTest::*` |
| 1-6 | إدارة الثغرات | audit للاعتماديات وتحديث lockfiles وصور الأساس | فحوص CI |
| 1-7 | التسجيل والمراقبة | سجلات مركزية، تنبيهات، حفظ 12 شهراً | `LoggingTest::*` |
| 1-8 | حماية البنية التحتية | جدار ناري، HTTPS، وعدم نشر MySQL وRedis وDocker | فحص المنافذ |
| 1-9 | الاستجابة للحوادث | فريق استجابة، سيناريوهات، تدريب | `IncidentTest::*` |
| 1-10 | إدارة النسخ الاحتياطي | تشفير، فصل، اختبار استعادة ربع سنوي | `BackupTest::*` |
| 2 | ضوابط الدفاع السيبراني المتقدمة | EDR، تجزئة، مراقبة على القنوات الحساسة | `DefenseTest::critical_channels_monitored` |
| 3 | ضوابط الأمن السيبراني للحوسبة السحابية | لا تنطبق، التشغيل on-prem، وثيقة استثناء موثقة | `GovernanceTest::cloud_exemption_documented` |
| 4 | ضوابط الأمن السيبراني للأطراف الخارجية | لا أطراف خارجية في المرحلة الأولى، وثيقة استثناء | `GovernanceTest::third_party_exemption_documented` |

### 5.3 NDMO

| المعيار | العنوان | التطبيق | الاختبار |
|---|---|---|---|
| تصنيف البيانات | `public` عام، `internal` داخلي، `confidential` سري، `top_secret` سري للغاية | حقول ديناميكية، سجلات، مستندات | `ClassificationTest::*` |
| جودة البيانات | دقة، اكتمال، حداثة، اتساق | قواعد تحقق على FieldDefinition، فحوص دورية | `QualityTest::data_quality_rules_enforced` |
| دورة حياة البيانات | إنشاء، استخدام، أرشفة، إتلاف | سياسات retention على كل نوع عمل | `RetentionTest::*` |
| مالك البيانات | تعيين لكل مجموعة بيانات | `owner_organization_unit_id` إلزامي | `OwnershipTest::every_record_has_owner` |
| مشاركة البيانات | أذونات مبنية على التصنيف والعلاقة | قرار صلاحية مركزي | `AuthorizationTest::*` |
| البيانات الرئيسية | تكرار محكوم وعدم تكرار مرجعي | معرفات مرجعية بين الموديولات | `BoundaryTest::cross_module_references_via_ids` |
| البيانات الوصفية | بيانات وصفية إلزامية | Envelope وAuditEvent | `MetadataTest::envelope_metadata_complete` |

## 6. اختبارات حارسة إضافية

تنفذ هذه الاختبارات دورياً وأتمتتها في CI الداخلي:

- `SecurityRegressionTest::all_passwords_are_argon2id`
- `SecurityRegressionTest::no_raw_sql_in_module_code`
- `SecurityRegressionTest::no_external_urls_in_runtime_artifacts`
- `SecurityRegressionTest::no_secrets_in_repository`
- `SecurityRegressionTest::no_employee_pii_in_logs`
- `BoundaryTest::module_cannot_import_other_module_infrastructure`
- `BoundaryTest::no_cross_module_joins`
- `AuditTest::audit_chain_validates_end_to_end`
- `AirGapTest::dns_resolution_external_returns_failure`
- `BackupTest::restore_drill_runs_quarterly`

## 7. مخرجات التهديد غير المقبولة

أي تهديد تبقى مخاطره «عالية» بعد الضوابط يجب أن يُسجل في سجل المخاطر المؤسسي (موديول المخاطر في المرحلة الثالثة) مع خطة معالجة مرتبطة بمهمة وموعد، ولا يُسمح بإطلاق الميزة قبل تخفيض الخطر إلى «متوسط» أو أقل.

## 8. دورة المراجعة

- مراجعة ربع سنوية لنموذج التهديدات.
- إعادة تقييم كامل عند إضافة موديول جديد أو تغيير حدود الثقة.
- مراجعة سنوية لمطابقة ECC وNDMO وPDPL.
- يحتفظ موديول التدقيق بنسخة موقعة من كل مراجعة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | مسؤول أمن المعلومات | إنشاء المسودة التنفيذية |
| 0.2.0 | 2026-07-15 | مسؤول أمن المعلومات | توحيد التصنيف ومراجع سلسلة التوريد والتعافي وضبط الوثيقة |
