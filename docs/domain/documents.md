---
doc_id: DOM-DOC-001
title: المستندات والروابط المحكومة
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Documents
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/013-documents-and-file-security.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/retention-and-legal-hold.md
---
# المستندات

## 1. الغرض والحدود

يمتلك موديول Documents الملف المخزن مرة واحدة، بياناته الوصفية، تصنيفه، إصداراته، روابط استخدامه، قيود المستند كحقائق، ومنح التنزيل وسجل الوصول بعد قرار Authorization. تحتفظ موديولات الأعمال بمعرّف المستند وعلاقة استخدام فقط، ولا تخزن نسخة من الملف ولا تقرأ Object Storage مباشرة.

يدعم الإصدار الأول الرفع، الفحص الداخلي، الإصدارات غير القابلة للاستبدال، الربط بأكثر من سجل، حقائق قيود خاصة بالمستند عند الحاجة، وسجل العرض والتنزيل. لا يقرر Documents الوصول بصورة مستقلة؛ Authorization وحده يصدر Allow أو Deny وقرارات الحقول. الأرشفة الرسمية وOCR والتوقيع الإلكتروني وأرقام الحفظ خارج الإصدار الأول، لكن النموذج لا يمنع إضافتها لاحقاً.

## 2. المصطلحات والنماذج

| المصطلح | التعريف |
|---|---|
| Document | الهوية المنطقية والبيانات الوصفية والتصنيف وحقائق القيود عبر جميع الإصدارات. |
| DocumentVersion | ملف ثنائي غير قابل للتعديل مع hash وحالة فحص. |
| DocumentLink | علاقة استخدام بين المستند وسجل مصدر عام. |
| OwnRestrictionFacts | حقائق قيود المستند التي تدخل في قرار Authorization ولا تمنح أو تمنع بذاتها. |
| EffectiveRestrictionFacts | مجموعة حقائق المستند وحقائق جميع الروابط النشطة المقدمة إلى Authorization. |
| AccessGrant | منحة تشغيلية قصيرة العمر تصدر بعد `AccessDecision=Allow` لعملية محددة، وليست قراراً أو صلاحية دائمة. |

### 2.1 Aggregates

- `DocumentAggregate`: الهوية، المالك، التصنيف، البيانات الوصفية، حقائق القيود والحالة.
- `DocumentVersionAggregate`: رقم الإصدار، مفتاح الكائن، الحجم، النوع، البصمة وحالة الفحص.
- `DocumentLinkAggregate`: مرجع المصدر، الغرض، قيود الرابط وحالته.
- `DocumentAccessRecord`: سجل append-only للعرض والتنزيل والتصدير ومحاولات المنع الحساسة.

### 2.2 Value Objects

- `DocumentId`، `VersionNumber`، `ObjectKey`، `ContentHash`، `MimeType`، `FileSize`.
- `Classification`: `public|internal|confidential|top_secret`، وتقابلها `عام|داخلي|سري|سري للغاية`.
- `SourceReference`: `module_code` و`record_type` و`record_id`.
- `DocumentRestrictionFacts`: النطاق والتصنيف والحالة و`field_policy_key` ومتطلبات التدقيق، بلا Allow أو Deny أو خريطة حقول.

## 3. قاعدة أشد القيود

يجمع Documents حقائق أي عملية `view_metadata|preview|download|add_version|link|unlink|export` كالتالي، ثم يرسلها إلى Authorization دون تقييم محلي:

```text
AuthorizationRecordFacts = DocumentRestrictionFacts
                           + LinkedSourceAuthorizationRecordFacts(1)
                           + LinkedSourceAuthorizationRecordFacts(2)
                           + ...
```

- Authorization وحده يطبق أشد القيود ويصدر `AccessDecision` و`FieldAccessDecision`؛ لا يصدر Documents أو الموديول المرتبط Allow أو Deny.
- ربط المستند لاحقاً بسجل أشد تقييداً يقيّد الوصول إلى المستند من جميع المسارات فوراً، ولا ينشئ نسخة أوسع.
- إلغاء رابط لا يوسع الوصول تلقائياً قبل إعادة حساب القرار وتسجيل التغيير.
- الرابط لا يمنح المستخدم صلاحية إلى المستند أو السجل الآخر.
- إذا تعذر جلب `AuthorizationRecordFacts` لأي مصدر مرتبط يصدر Authorization منعاً بسبب `facts_unavailable`؛ لا يستخدم آخر قرار مخزن للسماح.
- البحث والإشعار والتقرير والتصدير تستخدم القرار نفسه ولا تعرض الاسم أو المقتطف أو عدد الروابط قبل السماح.

## 4. الجداول والقيود والفهارس

### 4.1 `documents`

- `id` BIGINT PK، `public_id` CHAR(26) UNIQUE NOT NULL.
- `owner_organization_unit_id` BIGINT NOT NULL.
- `created_by_user_id` BIGINT NOT NULL.
- `name` VARCHAR(255) NOT NULL، `description` TEXT NULL.
- `classification` VARCHAR(24) NOT NULL: `public|internal|confidential|top_secret`.
- `status` VARCHAR(24) NOT NULL: `draft|active|archived|held`.
- `restriction_facts` JSON NOT NULL؛ حقائق typed اختيارية وفق schema معلن، بلا كود حر أو Allow أو Deny.
- `current_version_id` BIGINT NULL.
- `retention_policy_key` VARCHAR(128) NULL.
- `lock_version` INT NOT NULL DEFAULT 1.
- `created_at`، `updated_at`، `archived_at` DATETIME NULL.
- فهارس: `(owner_organization_unit_id, status)`، `(classification, status)`.

### 4.2 `document_versions`

- `id` BIGINT PK، `document_id` BIGINT NOT NULL FK.
- `version_number` INT NOT NULL.
- `object_key` VARCHAR(512) UNIQUE NOT NULL؛ قيمة عشوائية لا تحتوي اسم الملف.
- `original_filename` VARCHAR(255) NOT NULL.
- `declared_mime_type` VARCHAR(128) NOT NULL، `detected_mime_type` VARCHAR(128) NULL.
- `size_bytes` BIGINT NOT NULL، `sha256` CHAR(64) NOT NULL.
- `scan_status` VARCHAR(24) NOT NULL: `pending|scanning|clean|infected|failed`.
- `availability_status` VARCHAR(24) NOT NULL: `uploading|quarantined|available|rejected|missing`.
- `scan_engine_version` VARCHAR(128) NULL، `scan_result` JSON NULL.
- `created_by_user_id` BIGINT NOT NULL، `created_at` DATETIME NOT NULL.
- قيد فريد: `(document_id, version_number)`.
- فهارس: `(document_id, version_number)`، `(scan_status, created_at)`، `(sha256)`.
- لا Update للملف أو hash بعد الانتقال إلى `available`؛ أي تغيير إصدار جديد.

### 4.3 `document_links`

- `id` BIGINT PK، `document_id` BIGINT NOT NULL FK.
- `source_module` VARCHAR(64) NOT NULL، `source_type` VARCHAR(64) NOT NULL، `source_id` VARCHAR(128) NOT NULL.
- `relation_type` VARCHAR(32) NOT NULL: `attachment|evidence|deliverable|reference`.
- `link_classification` VARCHAR(24) NULL: `public|internal|confidential|top_secret`؛ حقيقة قيد يمكنها التشديد فقط.
- `linked_by_user_id` BIGINT NOT NULL.
- `status` VARCHAR(16) NOT NULL: `active|unlinked`.
- `created_at` DATETIME NOT NULL، `unlinked_at` DATETIME NULL، `unlink_reason` VARCHAR(1000) NULL.
- قيد فريد منطقي للرابط النشط: `(document_id, source_module, source_type, source_id, relation_type)`.
- فهارس: `(source_module, source_type, source_id, status)`، `(document_id, status)`.

### 4.4 `document_restriction_facts`

- `id` BIGINT PK، `document_id` BIGINT NOT NULL FK.
- `fact_key` VARCHAR(128) NOT NULL؛ مفتاح من schema القيود المعلن.
- `fact_value` JSON NOT NULL؛ قيمة typed لا تحمل قرار وصول أو payload أعمال.
- `valid_from`، `valid_until` DATETIME NULL.
- `recorded_by_user_id` BIGINT NOT NULL، `created_at` DATETIME NOT NULL.
- قيد فريد: `(document_id, fact_key, valid_from)`.
- فهرس: `(document_id, fact_key, valid_until)`.
- لا يفسر Documents هذه الصفوف كسماح أو منع؛ يضمها إلى `AuthorizationRecordFacts` فقط.

### 4.5 `document_access_events`

- `id` BIGINT PK، `document_id` BIGINT NOT NULL، `document_version_id` BIGINT NULL.
- `actor_user_id` BIGINT NOT NULL، `acting_organization_unit_id` BIGINT NOT NULL.
- `action` VARCHAR(24) NOT NULL: `metadata_view|preview|download|export|denied`.
- `decision` VARCHAR(16) NOT NULL، `decision_reason_code` VARCHAR(64) NOT NULL.
- `source_context` JSON NULL؛ معرفات فقط دون payload أعمال.
- `ip_address` VARBINARY(16) NULL، `user_agent_hash` CHAR(64) NULL.
- `occurred_at` DATETIME NOT NULL، `event_id` CHAR(36) UNIQUE NOT NULL.
- Append-only، وفهارس `(document_id, occurred_at)`، `(actor_user_id, occurred_at)`، `(action, occurred_at)`.

## 5. العقود

### 5.1 Commands

- `CreateDocument(metadata, classification, restrictionFacts): DocumentId`.
- `InitiateDocumentUpload(documentId, filename, size, declaredMime, idempotencyKey): UploadTicket`.
- `FinalizeDocumentUpload(documentId, uploadToken, sha256)`.
- `RecordDocumentScanResult(versionId, result)`؛ لعامل الفحص الموثوق فقط.
- `AddDocumentVersion(documentId, upload)`.
- `UpdateDocumentMetadata(documentId, expectedVersion)`.
- `ChangeDocumentClassification(documentId, newClassification, reason)`.
- `LinkDocument(documentId, sourceReference, relationType)`.
- `UnlinkDocument(linkId, reason)`.
- `ArchiveDocument(documentId, reason)`.
- `PlaceDocumentOnHold(documentId, reason)` و`ReleaseDocumentHold` للمخول.

### 5.2 Queries

- `GetDocumentMetadata(documentId, accessDecision)`.
- `ListDocumentVersions(documentId, accessDecision)`.
- `GetDocumentPreviewGrant(documentId, versionId, actorContext): AccessGrant`.
- `GetDocumentDownloadGrant(documentId, versionId, actorContext): AccessGrant`.
- `ListDocumentsLinkedToSource(sourceReference, accessDecision)`.
- `GetDocumentIntegrityStatus(documentId, versionId)`.

يطلب Documents `DecideAccess` و`ResolveFieldAccess` باستخدام حقائق حديثة. لا ينشئ `AccessGrant` إلا بعد `AccessDecision=Allow` صادر من Authorization؛ والمنحة أحادية الاستخدام، مرتبطة بالمستخدم والإصدار والفعل، قصيرة العمر، ولا تصدر قبل تسجيل الوصول الحساس المطلوب.

### 5.3 عقود المصادر المطلوبة

كل موديول يسمح بالربط ينفذ عقد الوصول الوحيد التالي:

- `GetAuthorizationRecordFacts(sourceReference): AuthorizationRecordFacts`.

تثبت الحقائق وجود الهدف وتصنيفه ونطاقه وحالته و`field_policy_key` وقيوده اللازمة. لا يصدر المالك قرار وصول، ولا يستدعي Documents بنية المصدر أو جداوله.

### 5.4 العقود المقدمة للموديولات

- `CreateDocument`، `AddDocumentVersion`، `LinkDocument`.
- `GetDocumentDownloadGrant`.
- `GetAuthorizationRecordFacts(documentId)`؛ يعيد حقائق المستند وروابطه بلا قرار وصول.
- `GetDocumentReferenceSummary(reference, accessDecision)`؛ يعيد بيانات آمنة بعد قرار Allow صادر من Authorization.
- `VerifyDocumentEvidenceAvailable(documentIds[])`.

## 6. الأحداث

- `DocumentCreated`
- `DocumentUploadInitiated`
- `DocumentVersionUploaded`
- `DocumentVersionScanStarted`
- `DocumentVersionAvailable`
- `DocumentVersionQuarantined`
- `DocumentVersionRejected`
- `DocumentMetadataUpdated`
- `DocumentClassificationChanged`
- `DocumentLinked`
- `DocumentUnlinked`
- `DocumentDownloaded`
- `DocumentArchived`
- `DocumentHoldPlaced`
- `DocumentHoldReleased`

الأحداث العامة لا تحمل رابط تنزيل أو محتوى أو اسماً سرياً. أحداث الحقيقة وOutbox تحفظ في Transaction واحدة، والمستهلكات Idempotent.

## 7. الحالات

### 7.1 Document

```text
Draft -> Active: توفر أول إصدار clean وربط أو نشر مخول
Draft -> Archived: إلغاء قبل التفعيل
Active -> Archived: أرشفة منطقية
Active | Archived -> Held: حجز يمنع الإتلاف وفك الروابط المقيد
Held -> Active | Archived: رفع الحجز إلى الحالة السابقة
```

### 7.2 DocumentVersion

```text
Uploading -> Quarantined: اكتمال الرفع والتحقق من الحجم/hash
Quarantined -> Scanning
Scanning -> Available: نتيجة clean والنوع مسموح
Scanning -> Rejected: infected أو نوع محظور
Scanning -> Quarantined: فشل فني قابل لإعادة المحاولة
Available | Rejected: نهائيان؛ لا تعديل، وإعادة الرفع تنشئ إصداراً جديداً
```

### 7.3 DocumentLink

```text
Active -> Unlinked
```

لا حذف نهائياً للرابط من واجهة المستخدم.

## 8. الـInvariants

- الملف الثنائي يخزن مرة واحدة لكل إصدار ولا يستبدل بصمت.
- `version_number` متزايد بلا تكرار داخل المستند، و`current_version_id` يشير إلى إصدار `available` فقط.
- لا معاينة أو تنزيل قبل `scan_status=clean` و`availability_status=available`.
- `sha256` المحسوب من التخزين يطابق المعلن قبل الفحص.
- التصنيف لا يمكن تخفيضه دون قدرة مستقلة وسبب وتدقيق؛ تخفيضه لا يتجاوز روابط أشد.
- القرار الفعال يصدر من Authorization باستخدام حقائق المستند وجميع الروابط النشطة؛ أي رابط غير قابل للحل ينتج Fail Closed.
- إضافة رابط لا توسع الوصول أبداً، وإزالة رابط لا تمنح وصولاً دون إعادة قرار كامل.
- المستخدم الذي يرى أحد السجلات المرتبطة ولا يرى سجلاً مرتبطاً آخر لا يستطيع تنزيل المستند المشترك.
- المستند المحجوز لا يتلف ولا تزال روابطه التي يفرضها الحجز.
- لا حذف نهائياً من الواجهة، وسياسة الاحتفاظ هي وحدها التي تسمح بعملية إتلاف مستقبلية محكومة.
- لا يحمل الموديول معنى `evidence` التجاري؛ يتحقق فقط من وجود علاقة ونوعها وتوفر الإصدار.

## 9. الأمن

- Object Storage داخلي، مشفر أثناء النقل وعند التخزين، وممنوع الوصول إليه مباشرة من المتصفح بلا `AccessGrant`.
- مفاتيح الكائنات عشوائية ولا تحتوي أسماء أو وحدات أو تصنيفات.
- قائمة امتدادات وأنواع MIME وأحجام مسموحة مركزياً؛ يعتمد القرار على النوع المكتشف لا الامتداد وحده.
- كل ملف يمر بفحص داخلي في quarantine؛ العمال فقط يملكون نقل الكائن إلى مساحة available.
- الروابط الموقعة قصيرة العمر وأحادية الاستخدام قدر الإمكان، ومقيدة بالفعل والمستخدم والإصدار.
- العرض والتنزيل والتصدير للمحتوى السري يسجل قبل إرجاع النتيجة؛ فشل التدقيق الحرج يمنع العملية.
- Authorization وحده يطبق `DecideAccess` و`ResolveFieldAccess` على RBAC + ABAC والنطاق والتصنيف والحالة والتفويض وصلاحية الحقول؛ Documents يخزن ويقدم facts فقط.
- السوبر أدمن يدير الإعدادات والسياسات، لكن الوصول الإداري لا يلغي تصنيف المحتوى أو قاعدة أشد الروابط، وكل اطلاع حساس مسجل.
- لا ترسل الملفات أو metadata إلى خدمة خارج مركز البيانات.

## 10. الفشل والتعافي

- رفع ناقص أو hash غير مطابق: يحذف الجزء المؤقت وفق السياسة ويظل الإصدار غير متاح.
- ملف مصاب: ينتقل `Rejected`، يعزل الكائن، ويسجل حدث أمني دون كشف تفاصيل المحرك للمستخدم.
- تعطل محرك الفحص: يبقى الإصدار `Quarantined` وتطبق retry؛ لا إتاحة متفائلة.
- فقد الكائن أو عدم تطابق hash لاحقاً: `availability_status=missing`، يمنع التنزيل ويصدر تنبيه سلامة.
- تعطل مصدر مرتبط أثناء جلب الحقائق: يصدر Authorization منعاً آمناً بسبب `facts_unavailable`.
- تعارض إضافة إصدار: قفل تفاؤلي وترقيم ذري؛ لا إصدارين بالرقم نفسه.
- فشل إنشاء رابط بعد رفع الملف: يبقى المستند دون الرابط، ولا يعتبره المصدر دليلاً حتى نجاح `DocumentLinked`.
- فشل Outbox يرجع معاملة metadata أو الرابط؛ الفحص غير المتزامن قابل لإعادة المحاولة.
- انتهاء AccessGrant أو استخدامه ثانية: `401/403` ويطلب قرار جديد.
- تجاوز سعة التخزين: يرفض بدء الرفع قبل إنشاء إصدار غير مكتمل متروك.

## 11. الاختبارات ومعايير القبول

### 11.1 اختبارات المجال

- أول إصدار clean يفعّل المستند، والمصاب لا يفعله.
- إضافة إصدار لا تعدل الإصدار السابق.
- منع جعل إصدار quarantined هو current.
- منع تخفيض التصنيف بلا سبب وقدرة.
- منع أرشفة أو إتلاف مستند held.

### 11.2 اختبارات أشد القيود

- مستند بحقائق قيد داخلية مرتبط بـWorkRecord مصنف سرياً يصبح سرياً فعلياً بقرار Authorization.
- مستند مرتبط بسجلين، والمستخدم مصرح له بواحد فقط: لا metadata ولا تنزيل ولا نتيجة بحث.
- إضافة رابط أشد تسحب وصولاً كان مسموحاً من مسار آخر.
- إزالة الرابط الأشد لا تسمح قبل إعادة Authorization كاملة.
- تعطل عقد مصدر واحد من عدة مصادر يمنع الوصول.
- حقائق قيود المستند الأضيق تجعل Authorization يمنع رغم سماح السياقات الأخرى.

### 11.3 اختبارات الأمن والتخزين

- MIME مزور وامتداد مزدوج وملف يتجاوز الحد وhash غير صحيح.
- لا يستطيع API pod قراءة quarantine بمسار التنزيل العام.
- AccessGrant لمستخدم أو إصدار مختلف يفشل، وإعادة استخدامه تفشل.
- العرض والتنزيل السري يسجلان، وفشل Audit الحرج يمنع الرد.
- اسم الملف ومفتاح الكائن لا يظهران في حدث Outbox العام.

### 11.4 اختبارات العقود والتشغيل

- Contract test لكل مصدر يدعم `GetAuthorizationRecordFacts` ولا يعيد Allow أو Deny أو حقولاً مقررة.
- Idempotency لإعادة `DocumentVersionUploaded` و`DocumentLinked`.
- فشل عامل الفحص ثم نجاحه لا ينشئ إصداراً ثانياً.
- استعادة قاعدة البيانات وObject Storage تحافظ على تطابق hashes وروابط الإصدارات.
- البحث والتقرير والتصدير يعيدون فقط المستندات التي اجتازت قرار أشد القيود.

## 12. الاعتماديات وحدود التكامل

- يعتمد على Authorization وحده لقرار الوصول والحقول، وعلى Organization للنطاق وIdentity لهوية الفاعل وAudit للتسجيل الحساس.
- يعتمد تقنياً على Object Storage وQueue ومحرك فحص ملفات داخلي وShared/Clock وIdentifiers.
- تعتمد عليه موديولات الأعمال والمهمات عبر العقود فقط؛ لا تصل إلى `object_key` ولا جداول الإصدارات.
- تستهلك Notifications حدث توفر الإصدار دون تضمين الملف.
- يستهلك Search وReporting metadata المسموح فهرستها عبر أحداث مشتقة، ويعيدان فحص الوصول وقت القراءة.
- لا يعتمد على موديول أعمال بعينه؛ كل مصدر، ومنها WorkRecords وStrategy وPortfolioProjects وRisk، ينفذ عقد الرابط العام.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Documents | توحيد الواجهة الأمامية وتثبيت عقود الروابط |
