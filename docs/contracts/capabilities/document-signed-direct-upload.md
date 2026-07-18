---
doc_id: CON-CAP-DOC-001
title: عقد الرفع المباشر الموقع إلى الحجر
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: مسؤول هندسة البرمجيات
reviewers:
- مسؤول أمن المعلومات
- مالك موديول Documents
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/013-documents-and-file-security.md
- docs/domain/documents.md
- docs/data-security/file-security.md
- docs/data-security/retention-and-legal-hold.md
references:
- docs/contracts/api/openapi.yaml
- docs/contracts/capabilities/organization-import-rows-v1.md
---
# عقد الرفع المباشر الموقع إلى الحجر

## الحالة والحدود

**حالة التنفيذ:** `planned` — هذا العقد يثبت القدرة، لكن adapter Object Storage والرابط
الموقع والفحص الحقيقي غير منفذة. لا تصبح المسارات العامة الحالية دليلاً على التنفيذ.

يمتلك Documents bytes وسجل الحجر ونتيجة الفحص. يحتفظ Organization عند الاستيراد بمعرف
`quarantine_object_id` opaque فقط، ولا يقرأ جدولاً أو object key من Documents. لا يمنح
الرابط الموقع صلاحية قراءة أو list أو كتابة كائن آخر، ولا يمنح وصولاً إلى مساحة `available`.

## التدفق الملزم

1. يطلب العميل تذكرة رفع لغرض محدد: إصدار مستند أو مصدر استيراد Organization. يتحقق
   Documents من Authorization والسعة وسياسة النوع والحجم قبل حجز معرفات.
2. يعيد Documents تذكرة أحادية الاستخدام تحتوي `upload_id` و`quarantine_object_id`
   و`method=PUT` و`upload_url` و`required_headers` و`expires_at` و`max_size_bytes`. الرابط
   موقع لكائن عشوائي واحد داخل الحجر، ولا يحتوي اسم الملف أو التصنيف في object key.
3. يرفع العميل bytes مباشرة عبر TLS بالقيم المقيدة في التذكرة. لا يثق النظام باسم الملف أو
   الامتداد أو `Content-Type` المعلن.
4. يكمل العميل الرفع بـ`upload_id` و`sha256` و`byte_size`. يعيد Documents قراءة metadata
   والكائن، ويحسب SHA-256 على bytes المخزنة؛ قيمة العميل دليل مقارنة وليست حقيقة مرجعية.
5. ينتقل السجل إلى `quarantined` ثم `scanning`. لا يستطيع API العام أو Organization فتح
   المصدر خلالهما.
6. يفحص العامل النوع المكتشف، وقائمة MIME المسموحة، وAV الداخلي، وقنابل الضغط، والملفات
   المضمنة والروابط والماكرو وفق سياسة أمن الملفات. الخطأ أو timeout يبقيه fail-closed.
7. عند النجاح فقط تصبح النتيجة `clean`. إصدار المستند ينتقل وفق دورة Documents إلى
   `available`. مصدر الاستيراد يبقى مرجعاً محكوماً لا يفتحه إلا worker Organization عبر
   عقد قراءة Documents بعد إعادة Authorization والتحقق من الغرض والحالة.
8. عدم تطابق الحجم أو SHA-256 أو MIME، أو نتيجة AV غير clean، ينتج `rejected` أو
   `quarantined` قابلة لإعادة الفحص حسب السبب؛ لا توجد إتاحة متفائلة.

الإكمال idempotent بالمعنى نفسه. إعادة المفتاح بطلب مختلف تعيد `409`، ولا تنشئ نسخة أو
كائناً ثانياً. لا يستعمل `quarantine_object_id` في ImportJob قبل نتيجة `clean` مطابقة للغرض
والـtemplate المطلوب.

## عقد التكامل بين Documents وOrganization

ينشر Documents، عبر contract لا عبر جداول، العمليات المخططة التالية:

- `RequestSignedQuarantineUpload(purpose, filename, declaredMime, sizeBytes, context)`.
- `FinalizeQuarantineUpload(uploadId, sha256, sizeBytes)`.
- `GetQuarantineObjectStatus(quarantineObjectId)`؛ يعيد الحالة والنوع المكتشف والحجم وhash
  من دون object key أو تفاصيل AV الحساسة.
- `OpenCleanQuarantineObject(quarantineObjectId, purpose, consumerContext)`؛ stream قراءة
  داخلي قصير العمر بعد `clean` فقط، ومقيد بالمستهلك والغرض.

يستدعي Organization هذه العقود باستخدام IDs وcontext منشور. لا ينفذ join أو FK أو وصولاً
إلى Object Storage، ولا يصدر Documents قرار Allow؛ Authorization وحده يقرر، ثم يتحقق
Documents من القرار والقيود قبل إصدار التذكرة أو stream.

## القيود الأمنية والاحتفاظ

- SHA-256 إلزامي قبل الفحص وبعده وعند الاسترجاع. يحسب الخادم BLAKE3 كدليل ثانوي عندما
  تفرض سياسة أمن الملفات ذلك، ولا يقبل أي hash يحسبه العميل بديلاً عن حساب الخادم.
- النوع المكتشف هو المرجع. اختلاف MIME المعلن والمكتشف يمنع الاستهلاك حتى تصدر السياسة
  verdict صريحاً، ولا تكفي مطابقة الامتداد.
- لا ينتقل أي byte من الحجر قبل AV نظيف وبقية فحوص أمن الملفات. تحفظ نسخة الفحص والمحرك
  والتوقيعات والنتيجة اللازمة للتدقيق بلا كشف تفاصيل تمكن من تجاوز الفحص.
- المستند التشغيلي ومصدر الاستيراد المقبولان من فئة `business` يحتفظ بهما سبع سنوات على
  الأقل من نقطة بداية العد المعتمدة. تحفظ أحداث التدقيق وأدلة الوصول عشر سنوات. يعلق
  legal hold الإتلاف مهما بلغت المدة، ولا يختصر archive مدة الاحتفاظ.
- تذكرة الرفع لها `expires_at` إلزامي وقصير بحسب إعداد أمني منشور عند التنفيذ. لا يثبت هذا
  العقد مدة رقمية لتذكرة الرفع؛ حد خمس دقائق في سياسة الملفات يخص روابط التحميل.

## تسوية التعارضات القائمة

- الوصف القديم `POST /documents/upload` الذي يمرر bytes عبر API لا يحكم الشريحة الجديدة؛
  المساران `/documents/uploads` و`/complete` هما موضع توسيع OpenAPI لاحقاً بتذكرة موقعة.
- منع وصول المتصفح المباشر إلى Object Storage باق. تذكرة الرفع قدرة write-only إلى كائن
  حجر واحد، وليست رابط تنزيل أو اعتماداً عاماً.
- `UploadRequest.byte_size` في OpenAPI يضع سقف envelope قدره 1 GiB، بينما سياسة أمن
  الملفات تجعل الحد الافتراضي الفعلي 200 MB وقابلاً للتخفيض حسب نوع العمل. يطبق الخادم
  الأصغر بين envelope والسياسة والغرض، ولا تعني 1 GiB أن الملف مسموح.
- نقل إصدار مستند clean إلى `available` لا يحول مصدر استيراد خام إلى مستند قابل للتنزيل؛
  `quarantine_object_id` يبقى مرجع قدرة داخلياً والغرض جزء من قرار الاستهلاك.

## معايير القبول

- لا تستطيع تذكرة الرفع القراءة أو list أو تغيير object key أو تجاوز الحجم والرؤوس المقيدة.
- الانتهاء أو إعادة الاستخدام أو إكمال hash مختلف يرفض بلا كائن available.
- MIME مزور أو AV مصاب أو فشل scanner يبقى غير قابل للاستهلاك.
- Organization لا يطبق ImportJob قبل `clean` ولا يقرأ إلا عبر contract المالك.
- اختبارات الاحتفاظ تثبت سبع سنوات لمحتوى business وعشر سنوات لأثر التدقيق، وأن legal
  hold يمنع الإتلاف.
