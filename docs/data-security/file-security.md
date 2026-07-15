---
doc_id: SEC-CL-002
title: أمن الملفات
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
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/013-documents-and-file-security.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/domain/documents.md
- docs/data-security/logical-data-model.md
- docs/data-security/threat-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/audit-and-privacy.md
---
# أمن الملفات

## 1. الغرض والنطاق

تحدد هذه الوثيقة السياسة الأمنية الكاملة لإدارة الملفات داخل المنصة الإدارية للتجمع الصحي الثالث، وتشمل:

- الحجر الفوري عند الاستلام fail-closed.
- التحقق من السلامة Checksum.
- فحص البرمجيات الخبيثة Antivirus.
- كشف قنابل الضغط Zip Bomb.
- السياسة الأشد للروابط المتعددة Multi-link والـHard links والـSymlinks.
- تخزين غير قابل للتعديل.
- تطبيق سياسة الحقل على كل عملية وصول.

المنصة غير سريرية. الملفات المرفوعة ملفات إدارية مرتبطة بسجلات أعمال (طلبات، عقود، محاضر، قرارات، مستندات مشاريع). لا تستقبل ملفات طبية أو سجلات مرضى.

## 2. مبادئ أمن الملفات

- **الحجر إلزامي قبل الإتاحة.** كل ملف يُرفع يدخل الحجر ولا يصبح متاحاً إلا بعد اجتياز كل الفحوصات. الحجر fail-closed: أي فشل في الفحص يبقي الملف محجوراً ولا يُتاح أبداً.
- **عدم الثقة بالمرفوع.** كل ملف يُعامل كمرفوع غير موثوق حتى يثبت العكس.
- **التخزين غير قابل للتعديل.** بعد اجتياز الفحص، يصبح كائن التخزين غير قابل للتعديل. كل تغيير يتطلب إصداراً جديداً وسجل جديد.
- **الأشد بين السجل والمستند.** المستند المرتبط بسجل يطبق أشد قيود السجل أو المستند، ولا يرث وصولاً أوسع تلقائياً.
- **الفصل بين حسابات الخدمة.** كل حساب خدمة (رفع، تحميل، فحص، حذف) بصلاحيات مستقلة ومقيدة.
- **عدم بقاء بيانات حساسة على الأجهزة.** لا يُسمح بوضع Offline يحوي محتوى سري. التحميل يتطلب جلسة فعالة وصلاحية.
- **العزل المادي للحجر.** منطقة الحجر معزولة عن منطقة الإتاحة، ولا يقرأ منها التطبيق مباشرة.

## 3. نموذج البيانات للملفات

### 3.1 الكيانات

#### 3.1.1 `Document`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `document_id` | UUID | PK | معرف المستند |
| `owner_organization_unit_id` | UUID | إلزامي | الجهة المالكة |
| `created_by_user_id` | UUID | إلزامي | المنشئ |
| `classification` | enum | إلزامي | public, internal, confidential, top_secret |
| `current_version_id` | UUID | إلزامي | الإصدار النشط |
| `status` | enum | إلزامي | quarantine, available, rejected, archived |
| `retention_until` | timestamp | إلزامي | نهاية الصلاحية |
| `legal_hold` | boolean | إلزامي | حجز قانوني |
| `created_at` | timestamp | إلزامي | لحظة الإنشاء |

#### 3.1.2 `DocumentVersion`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `version_id` | UUID | PK | معرف الإصدار |
| `document_id` | UUID | FK | المستند |
| `version_no` | int | إلزامي، فريد ضمن المستند | رقم الإصدار |
| `mime_type` | string | إلزامي | النوع المكتشف |
| `declared_mime_type` | string | اختياري | النوع المُعلن من الرافع |
| `size_bytes` | bigint | إلزامي | الحجم الفعلي |
| `sha256` | string | إلزامي، فريد | Hash المحتوى |
| `blake3` | string | اختياري | Hash ثانوي للتحقق |
| `storage_object_id` | UUID | إلزامي | كائن التخزين |
| `uploaded_by_user_id` | UUID | إلزامي | من رفع الإصدار |
| `uploaded_at` | timestamp | إلزامي | لحظة الرفع |
| `scan_status` | enum | إلزامي | pending, scanning, clean, infected, failed, rejected |
| `scan_completed_at` | timestamp | اختياري | لحظة اكتمال الفحص |
| `available_at` | timestamp | اختياري | لحظة الإتاحة بعد الفحص |

#### 3.1.3 `StorageObject`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `storage_object_id` | UUID | PK | معرف كائن التخزين |
| `sha256` | string | إلزامي، فريد | المحتوى |
| `size_bytes` | bigint | إلزامي | الحجم |
| `storage_path` | string | إلزامي، فريد | المسار داخل Object Storage |
| `storage_class` | enum | إلزامي | quarantine, available, archive |
| `encryption_key_id` | string | إلزامي | معرف مفتاح KMS |
| `created_at` | timestamp | إلزامي | لحظة الكتابة |
| `immutable` | boolean | إلزامي | هل هو قابل للتعديل |
| `immutable_since` | timestamp | اختياري | لحظة تحوّله غير قابل للتعديل |

#### 3.1.4 `QuarantineRecord`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `quarantine_id` | UUID | PK | معرف سجل الحجر |
| `version_id` | UUID | FK | الإصدار |
| `received_at` | timestamp | إلزامي | لحظة الاستلام |
| `received_from_ip` | string | إلزامي | IP داخلي للرافع |
| `checksum_verified` | boolean | إلزامي | نتيجة فحص التطابق |
| `mime_verified` | boolean | إلزامي | نتيجة فحص النوع |
| `mime_detected` | string | إلزامي | النوع المكتشف |
| `mime_declared` | string | اختياري | النوع المُعلن |
| `av_scanner` | string | إلزامي | المحرك المستخدم |
| `av_signature_version` | string | إلزامي | إصدار التوقيعات |
| `av_result` | enum | إلزامي | clean, infected, error, timeout |
| `av_completed_at` | timestamp | اختياري | لحظة انتهاء الفحص |
| `decompression_ratio` | decimal | اختياري | نسبة الضغط |
| `uncompressed_total_bytes` | bigint | اختياري | الحجم بعد فك الضغط |
| `embedded_files_count` | int | اختياري | عدد الملفات المضمنة |
| `symlink_detected` | boolean | إلزامي | اكتشاف symlink |
| `hardlink_detected` | boolean | إلزامي | اكتشاف hardlink |
| `multi_link_score` | int | إلزامي | درجة الشك في الروابط المتعددة |
| `policy_verdict` | enum | إلزامي | allowed, blocked, quarantined_hard |
| `block_reason` | text | اختياري | سبب المنع |
| `reviewed_by_user_id` | UUID | اختياري | من راجع يدوياً |

#### 3.1.5 `DocumentLink`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `link_id` | UUID | PK | معرف الربط |
| `document_id` | UUID | FK | المستند |
| `target_type` | string | إلزامي | نوع الكيان |
| `target_id` | string | إلزامي | معرف الكيان |
| `link_type` | enum | إلزامي | attached, referenced, evidence |
| `created_by_user_id` | UUID | إلزامي | المنشئ |
| `created_at` | timestamp | إلزامي | لحظة الربط |

#### 3.1.6 `DocumentAccessEvent`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `event_id` | UUID | PK | الحدث |
| `document_id` | UUID | FK | المستند |
| `version_id` | UUID | FK | الإصدار |
| `actor_user_id` | UUID | إلزامي | الفاعل |
| `action` | enum | إلزامي | view, download, link, unlink |
| `occurred_at` | timestamp(6) | إلزامي | اللحظة |
| `actor_ip` | string | إلزامي | IP داخلي |
| `user_agent` | string | اختياري | المتصفح |
| `outcome` | enum | إلزامي | allowed, denied؛ نسخة تدقيق من نتيجة Authorization وليست قراراً يصدره Documents |

## 4. تدفق الملفات

### 4.1 الرفع

1. يبدأ المستخدم رفع ملف من خلال نقطة نهاية `POST /documents/upload`.
2. يستدعي API خدمة الرفع `UploadDocument`.
3. يُسجَّل رفع في `audit_events` بنوع `document.upload.initiated`.
4. يكتب API محتوى الملف في مخزن الحجر بمسار مؤقت `quarantine/{uuid}.blob`.
5. يحسب API `sha256` و`blake3` ويُسجَّل الحجم.
6. يكتب `DocumentVersion` بحالة `scan_status=pending`.
7. يكتب `QuarantineRecord` بنتيجة `policy_verdict=quarantined_hard` افتراضياً.
8. يُدرج الفحص في طابور `document_scan_queue` ويعود 202 Accepted للعميل.

### 4.2 الفحص

1. العامل يستهلك من `document_scan_queue`.
2. يحسب `decompression_ratio` و`uncompressed_total_bytes`.
3. يفحص نوع MIME الحقيقي (Content-Type sniffing) ويقارنه مع المُعلن.
4. يفحص الروابط المتعددة والـSymlinks والـHard links وفق السياسة الأشد.
5. يفحص المحتوى باستخدام محرك AV موقّع داخلياً.
6. يحدّث `QuarantineRecord` بنتائج الفحص.
7. يحدّث `DocumentVersion.scan_status`.

### 4.3 قرار الإتاحة

الملف يصبح متاحاً فقط عند تحقق كل الشروط:

| الشرط | التحقق |
|---|---|
| تطابق الـHash مع المحتوى | فحص ثنائي على SHA-256 وBLAKE3 |
| نوع MIME مسموح في السياسات | قائمة بيضاء بحسب `work_type_version` |
| الفحص نظيف | `av_result=clean` و`policy_verdict=allowed` |
| نسبة الضغط ضمن الحدود | `decompression_ratio ≤ 100` و`uncompressed_total_bytes ≤ 500 MB` |
| لا روابط متعددة مشبوهة | `multi_link_score ≤ 5` و`symlink_detected=false` و`hardlink_detected=false` |
| حجم الملف ضمن الحدود | `size_bytes ≤ 200 MB` افتراضي، قابل للضبط بنوع العمل |

عند تحقق كل الشروط:

1. يُنقل كائن التخزين من الحجر إلى الإتاحة (نسخ مع حذف الأصلي).
2. يُحدَّث `StorageObject.storage_class=available` و`immutable=true`.
3. يُحدَّث `DocumentVersion.scan_status=clean` و`available_at`.
4. يُحدَّث `Document.status=available`.
5. يُسجَّل الحدث `document.scan.passed` في التدقيق.

### 4.4 الفشل

عند فشل أي شرط:

1. يبقى `StorageObject.storage_class=quarantine` و`immutable=true`.
2. يُحدَّث `DocumentVersion.scan_status=infected|failed|rejected`.
3. يُحدَّث `Document.status=quarantine` أو `rejected`.
4. يُسجَّل الحدث `document.scan.failed` مع `block_reason`.
5. يُنبه السوبر أدمن عند تكرار الرفع من نفس المصدر.
6. لا يُتاح الملف أبداً عبر واجهة API.

## 5. سياسة الأنواع MIME

### 5.1 القائمة البيضاء الافتراضية

| الفئة | الأنواع المسموحة |
|---|---|
| مستندات | `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |
| جداول | `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| عروض | `application/vnd.ms-powerpoint`, `application/vnd.openxmlformats-officedocument.presentationml.presentation` |
| صور | `image/png`, `image/jpeg`, `image/tiff` |
| نصوص | `text/plain`, `text/csv`, `text/markdown` |
| أرشيفات | `application/zip`, `application/x-7z-compressed`, `application/x-rar-compressed`, `application/x-tar`, `application/gzip` |

### 5.2 الأنواع الممنوعة

- كل ما يحوي تنفيذ: `.exe`, `.bat`, `.cmd`, `.sh`, `.ps1`, `.jar`, `.msi`.
- الماكرو: `application/vnd.ms-excel.sheet.macroEnabled.12`.
- السكريبت المضمّن: `text/html`, `application/xhtml+xml`.
- الاختصارات: `.lnk`, `.url`.

### 5.3 التحقق المزدوج

- فحص النوع المُعلن في الـHeader.
- فحص النوع الحقيقي من خلال Content sniffing (أول 4096 بايت).
- إذا اختلف النوعان، يعتمد النوع المكتشف فقط.
- رفض الملف عند اختلاف النوعين وكان المكتشف خارج القائمة البيضاء.

## 6. سياسة Zip Bomb

### 6.1 حدود الفك

| البند | القيمة الافتراضية | قابل للضبط بنوع العمل |
|---|---|---|
| نسبة الضغط القصوى | 100:1 | نعم |
| الحجم الإجمالي غير المضغوط | 500 MB | نعم |
| عمق التداخل | 5 مستويات | لا |
| عدد الملفات في الأرشيف | 10,000 | نعم |
| الحجم المسموح للأرشيف الواحد | 200 MB | نعم |

### 6.2 آلية الفحص

1. يُفتح الأرشيف في وضع streaming بدون كتابة كاملة على القرص.
2. تُقرأ كل إدخال مع حساب الحجم التراكمي.
3. تُحسب نسبة الضغط مقارنة بحجم الإدخال المضغوط.
4. إذا تجاوز الحجم التراكمي أو نسبة الضغط، يُرفض الأرشيف فوراً.
5. يُسجَّل في `QuarantineRecord` القيم المرصودة قبل الرفض.

### 6.3 الاختبارات

- `FileSecurityTest::zip_bomb_with_high_ratio_is_blocked`
- `FileSecurityTest::nested_archives_with_cumulative_size_blocked`
- `FileSecurityTest::archive_with_too_many_files_blocked`
- `FileSecurityTest::decompression_does_not_exhaust_memory`

## 7. سياسة الروابط المتعددة والـSymlinks (الأشد)

### 7.1 القواعد

| النوع | الإجراء | السبب |
|---|---|---|
| Symbolic link داخل الأرشيف | ممنوع مطلقاً | يمكن أن يشير لمسارات حساسة |
| Symbolic link داخل ملف PDF/DOCX | ممنوع مطلقاً | مخاطر تنفيذ |
| Hard link داخل الأرشيف | ممنوع مطلقاً | قد يشير لملفات النظام |
| Hard link بين StorageObjects | ممنوع مطلقاً | كل كائن يجب أن يكون مستقلاً |
| Shortcut داخل ملف Office | ممنوع مطلقاً | مخاطر ماكرو |
| OLE object داخل Office | فحص خاص | مخاطر ماكرو |
| Embedded file في PDF | فحص عدد وأنواع | مخاطر تنفيذ |
| External reference في XLSX | ممنوع | تسرب بيانات |
| Macro في Office | ممنوع | تنفيذ تعليمات |

### 7.2 آلية الكشف

- `archive_read` بكشف `symlink` و`hardlink` قبل فك الضغط.
- بالنسبة لـPDF، استخدام مكتبة تحليل آمنة تتجاهل Embedded JavaScript.
- بالنسبة لـOffice، فحص ما إذا كان الملف يحوي VBA streams عبر فحص أول 8 بايت.
- للأرشيفات المتداخلة Nested، تطبيق السياسة على كل مستوى بحد أقصى للعمق.

### 7.3 درجة الشك Multi-link Score

تُحسب لكل ملف قبل الإتاحة:

| العنصر المكتشف | النقاط |
|---|---|
| Symlink واحد أو أكثر | 100 |
| Hardlink واحد أو أكثر | 100 |
| Macro في Office | 100 |
| Embedded executable | 100 |
| External reference في XLSX | 50 |
| OLE object في Office | 30 |
| Embedded file في PDF | 10 لكل ملف |
| Archive nesting depth 4 | 20 |
| Archive nesting depth 5 | 40 |

النتيجة ≤ 5 مع `symlink_detected=false` و`hardlink_detected=false` فقط هي المسموحة.

### 7.4 الاختبارات

- `FileSecurityTest::symlink_in_archive_blocked`
- `FileSecurityTest::hardlink_in_archive_blocked`
- `FileSecurityTest::macro_enabled_office_blocked`
- `FileSecurityTest::nested_archive_with_symlink_blocked_at_open`
- `FileSecurityTest::multi_link_score_threshold_enforced`

## 8. التخزين

### 8.1 الفصل بين مناطق التخزين

| المنطقة | الاستخدام | الوصول |
|---|---|---|
| الحجر | الملفات قبل الفحص | حساب `quarantine_role` فقط |
| الإتاحة | الملفات النظيفة | حساب `available_role` فقط |
| الأرشيف | الملفات بعد انتهاء الصلاحية النشطة | حساب `archive_role` فقط |
| التصدير | نسخ مخصصة للتصدير | حساب `export_role` فقط |

كل منطقة لها:

- Prefix مختلف في Object Storage.
- حساب خدمة منفصل.
- NetworkPolicy تحدد الوصول.

### 8.2 التشفير

- تشفير at-rest باستخدام SSE-KMS بمفتاح منفصل لكل منطقة.
- تشفير in-transit عبر TLS 1.3.
- تدوير المفاتيح كل 12 شهراً أو عند الاشتباه.
- فصل KMS عن الإنتاج.

### 8.3 عدم القابلية للتعديل

- بعد الإتاحة، `StorageObject.immutable=true`.
- لا عملية UPDATE على مستوى API.
- على مستوى Object Storage، سياسة `object_lock` تحظر الحذف والتعديل لمدة `retention_until`.
- الإتلاف يتطلب عملية محكومة وفق سياسة الاحتفاظ.

### 8.4 Hash كدليل

- `sha256` يُحسب عند الاستلام ولا يتغير.
- عند التحميل، يُعاد حساب الـHash على المحتوى المُسلَّم ويُقارن.
- الاختلاف يرفع تنبيهاً حرجاً ويُسجَّل الحدث `document.integrity_violation`.

## 9. التحميل والعرض

### 9.1 روابط التحميل

- روابط موقعة بصلاحية قصيرة العمر (≤ 5 دقائق).
- تتضمن معرف الجلسة والمستخدم والإصدار.
- تحقق مزدوج عند كل GET من صلاحية المستخدم على السجل والمستند.
- تسجيل `DocumentAccessEvent` بنوع `download` و`view` قبل إنشاء الرابط.

### 9.2 تطبيق سياسة الحقل

- يرسل Documents إلى `GetAuthorizationRecordFacts` تصنيف المستند وحالته و`own_policy_key` وحقائق الروابط ونسخها فقط؛ لا يعيد قراراً أو خريطة حقول.
- يفسر Authorization هذه الحقائق ويصدر وحده قرار التحميل والعرض والحقول.
- إذا كان المستند مرتبطاً بسجل، يُطبَّق أشد القيود.
- مستخدم لا يملك صلاحية رؤية حقل مرتبط بمستند محمي لا يحق له تحميل المستند حتى لو امتلك صلاحية على السجل.

### 9.3 العزل المادي عن المحتوى السري

- عند رفع محتوى سري للغاية، يتم تخزينه في منطقة مخصصة بـKMS منفصل.
- التحميل يتطلب صلاحية `documents.download.top_secret` ويتطلب حضور مراجع ثانٍ عندما تفرض سياسة Authorization ذلك.

### 9.4 الاختبارات

- `FileSecurityTest::presigned_url_has_short_ttl`
- `FileSecurityTest::download_checks_authorization_each_time`
- `FileSecurityTest::download_blocked_when_record_field_classification_higher`
- `FileSecurityTest::download_revalidates_session_on_each_request`

## 10. فحوصات AV المتقدمة

### 10.1 المحرك المستخدم

- محرك AV موقّع داخلياً متوافق مع التشغيل المعزول.
- تحديث توقيعات من مرآة داخلية موقعة.
- لا اتصال مباشر بالإنترنت لتحديث التوقيعات.

### 10.2 أنماط الفحص

| النمط | الوصف | التطبيق |
|---|---|---|
| توقيعات تقليدية | قاعدة بيانات توقيعات | نعم |
| Heuristic | كشف سلوكي | نعم |
| Sandbox داخلي | تنفيذ في بيئة معزولة | للملفات المشتبه بها فقط |
| YARA rules | قواعد YARA مخصصة | لاكتشاف أنماط مرتبطة بالمنصة |

### 10.3 الملفات المشتبه بها

- الملفات التي تفشل الفحص التقليدي تُرفع يدوياً للمراجعة.
- مدة الحجر اليدوي 30 يوماً افتراضياً، قابلة للتمديد.
- مراجعة من فريق الأمن قبل الإتاحة أو الحذف.

## 11. الربط بالسجلات

### 11.1 قواعد الربط

- ربط المستند بسجل يتطلب صلاحية على السجل وعلى نوع المستند.
- لا يسمح بربط مستند محجور.
- حذف الربط لا يحذف المستند، يفك الربط فقط.

### 11.2 تطبيق أشد القيود

- يعرض Documents قيوده وقيود الروابط كحقائق فقط، ويحسب Authorization القرار النهائي.
- مستند مرتبط بسجل عند كلاهما، المستخدم يحتاج صلاحية على الأثنين.
- إذا كانت صلاحية المستند أضيق من صلاحية السجل، تسود صلاحية المستند.
- إذا كانت صلاحية السجل أضيق من صلاحية المستند، تسود صلاحية السجل.

### 11.3 الاختبارات

- `DocumentLinkTest::linking_requires_authorization_on_record`
- `DocumentLinkTest::stricter_classification_wins`
- `DocumentLinkTest::quarantined_document_cannot_be_linked`

## 12. إدارة المخاطر والإتلاف

### 12.1 دورة حياة المستند

| المرحلة | الإجراء | التسجيل |
|---|---|---|
| نشط | متاح للتحميل ضمن الصلاحيات | كل تحميل مسجل |
| أرشفة | نقل لمنطقة الأرشيف، قراءة فقط | `document.archived` |
| حجز قانوني | منع الإتلاف | `document.legal_hold_set` |
| إتلاف | حذف من Object Storage بعد موافقة مزدوجة | `document.destroyed` |

### 12.2 الإتلاف المحكوم

- الإتلاف يتطلب موافقة مالك البيانات والسوبر أدمن.
- كل عملية إتلاف تُسجَّل في `audit_events` بنوع `document.destroyed` مع `reason`.
- الإتلاف يحذف من Object Storage فقط بعد مرور `retention_until`.
- إتلاف البيانات المشفرة يمحو المفتاح بعد فترة سماح.

### 12.3 الاختبارات

- `LifecycleTest::archived_documents_are_read_only`
- `LifecycleTest::legal_hold_blocks_destruction`
- `LifecycleTest::destruction_requires_dual_approval`
- `LifecycleTest::destruction_logged_with_reason`

## 13. مؤشرات الإنذار

- رفض ملف بسبب AV.
- رفض ملف بسبب zip bomb.
- رفض ملف بسبب symlink.
- رفض ملف بسبب macro.
- تكرار رفع من نفس المصدر.
- ارتفاع نسبة رفض الملفات خلال ساعة.
- محاولة تحميل مستند محجور.
- محاولات متعددة للوصول لرابط منتهي الصلاحية.

## 14. متطلبات البنية التحتية المعزولة

- مرآة توقيعات AV داخلية محدثة شهرياً.
- Sandbox داخلي لتنفيذ الملفات المشتبه بها.
- لا اتصال بالإنترنت من أي مكون فحص.
- سجلات الفحص منفصلة ومؤرشفة بشكل مستقل.

## 15. الامتثال

| المتطلب | التطبيق |
|---|---|
| NCA ECC 1-3 حماية البيانات | تشفير، فصل، تدقيق |
| NCA ECC 1-8 حماية البنية التحتية | فصل مناطق التخزين |
| PDPL أمن البيانات | تطبيق سياسة الحقل وتشفير |
| PDPL تقليل البيانات | رفض ملفات خارج القائمة البيضاء |
| NDMO دورة حياة البيانات | مراحل نشط/أرشفة/إتلاف |

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | مسؤول أمن المعلومات | إنشاء المسودة التنفيذية |
| 0.2.0 | 2026-07-15 | مسؤول أمن المعلومات | توحيد التصنيف والمراجع وضبط الوثيقة وتأكيد القرار المركزي للوصول |
