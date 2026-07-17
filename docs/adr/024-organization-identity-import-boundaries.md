---
doc_id: ADR-024
title: ملكية Organization وIdentity وحدود الاستيراد
type: adr
status: proposed
version: 1.0.0
date: 2026-07-17
owner: طارق
reviewers: []
classification: internal
review_cycle: عند الحاجة
sources:
- docs/plans/release-1-platform.md
- docs/domain/organization-and-people.md
- docs/domain/identity.md
- docs/architecture/context-map.md
- docs/architecture/module-catalog.md
- docs/governance/glossary.md
- docs/data-security/logical-data-model.md
- docs/data-security/audit-and-privacy.md
references:
- docs/adr/003-module-boundaries.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/007-transactional-outbox.md
- docs/adr/011-lightweight-cqrs-and-transactions.md
- docs/adr/012-local-identity-and-session-security.md
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/architecture/dependency-rules.md
- docs/data-security/authorization-model.md
- docs/data-security/file-security.md
- docs/data-security/identity-session-security.md
- docs/engineering/database-migrations.md
deciders:
- طارق
scope: ملكية Person والحسابات وحدود استيراد Organization وIdentity في W1.2
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-004
- ADR-007
- ADR-011
- ADR-012
- ADR-020
review_by: 2027-01-17
---
# ADR-024: ملكية Organization وIdentity وحدود الاستيراد

## Context

تضع وثيقة Organization المقبولة `Person` والتكليفات والعلاقات والاستيراد التنظيمي داخل
`Organization`، وتضع وثيقة Identity المقبولة الحساب والاعتماد والجلسة داخل `Identity`
مع `person_id` خارجي. في المقابل ما زالت وثائق أخرى تنسب `Person` إلى Identity، وتوجد
إشارات FK من Organization إلى `users.id`. يجب حسم المالك واتجاه الاعتماد قبل W12-00
حتى لا ينشأ join أو FK أو معاملة تكتب موديولين.

## Drivers

- مالك وحيد لكل حقيقة أعمال ولكل جدول.
- بقاء `Organization` بلا اعتماد على `Identity` وفق ترتيب الموديولات.
- فصل الشخص القانوني والتنظيمي عن حساب الدخول وأسراره وجلساته.
- استيراد ذري داخل مالك البيانات مع provisioning قابل للإعادة دون ازدواج.
- قرار تفويض خلفي fail-closed يعتمد على حقائق منشورة لا على وصول مباشر للجداول.

## Decision

1. يملك `Organization` حصرياً `Person` و`OrganizationUnit` و`Position` و`Assignment`
   والعضويات والعلاقات التنظيمية وحقول PII الأساسية للشخص.
2. يملك `Identity` حصرياً `UserAccount` والاعتمادات والجلسات والاستعادة والقفل والتعطيل.
   يحتفظ بـ`person_id` كمعرف خارجي فقط، بلا FK أو ORM relation أو join إلى جداول
   Organization. يقتصر snapshot العرض على `display_name_ar` و`display_name_en`، ولا
   ينسخ الهوية الوطنية أو البريد أو الهاتف. يحدث snapshot بحدث مصنف ويحذف أو يعمى عند
   فك الربط وفق سياسة احتفاظ الحساب.
3. يتحقق Identity من مرجع الشخص عبر عقد متزامن منشور يملكه Organization، ويجوز له حفظ
   snapshot العرض غير المرجعي أعلاه. عند غياب العقد أو قدم حقائقه لا يفعل الحساب الجديد،
   وينكر Authorization أي طلب يحتاج نطاقاً تنظيمياً غير قابل للحل. يضاف اتجاه
   `Identity -> Organization` إلى خريطة الاعتماد عند قبول هذا القرار، ولا ينشأ اتجاه عكسي.
4. تحمل حقول actor مثل `created_by_user_id` و`submitted_by_user_id` و`approved_by_user_id`
   معرفات من سياق المصادقة والتدقيق بلا FK إلى جداول Identity وبلا استدعاء يخلق دورة.
5. يملك Organization عملية استيراد المنشآت والوحدات والمناصب والأشخاص والتكليفات. تمر
   العملية بالاستلام والتحقق والموافقة المزدوجة ثم تطبق جداول Organization وOutbox فقط
   في معاملة واحدة.
6. يملك Identity provisioning الحسابات وتعطيلها وتسوية حالتها. المحفز الوحيد من
   Organization هو event باسم `IdentityProvisioningRequested` يصدر من Outbox في معاملة
   `ApplyImportJob` نفسها بعد إنشاء Person فعلياً، لا عند الموافقة. يحمل `event_id`
   و`person_id` و`person_version` والحالة المطلوبة وcorrelation وإصدار schema بلا PII أو
   أسرار. يتحقق Identity من الشخص بالعقد المنشور، ويكتب جداوله في معاملة مستقلة مع Inbox
   ذري. لا تستورد كلمات المرور أو MFA أو recovery أو bootstrap أو tokens أو بيانات
   الجلسة من ملف.
7. لا توجد معاملة apply واحدة أو خدمة مشتركة تكتب جداول الموديولين، ولا تعني اكتمال
   استيراد Organization أن provisioning اكتمل؛ تعرض الحالتان بشكل مستقل.
8. يبقى `Authorization` وحده مالك allow/deny. يقدم Organization الحقائق التنظيمية الزمنية،
   ويقدم Identity حالة الحساب، ولا يمنح أي منهما صلاحية أعمال مباشرة.
9. يسمح W1.2 بحساب نشط واحد لكل Person. يستخدم `IdentityProvisioningRequested` و
   `PersonAccessStatusChanged` رقم `person_version` المتزايد نفسه لكل Person. يسجل Identity
   Inbox وآخر high-water mark في المعاملة نفسها، ويتجاهل أي إصدار `<=` المطبق. حالة
   `Suspended` تعلق الحساب و`Left` تعطله، وفي الحالتين تسحب كل الجلسات idempotently.
   انتهاء تكليف وحده لا يعطل الحساب، لكنه يبطل cache التفويض ويعيد تقييم النطاق في الطلب
   التالي.

## Scope

يشمل القرار ملكية Person والحساب والتكليفات والعلاقات، واتجاه العقود بين Organization
وIdentity، وحدود الاستيراد وprovisioning والتدقيق المرتبط بها. لا يحدد payloads النهائية
ولا migrations ولا واجهات الإدارة ولا محرك RBAC + ABAC التفصيلي؛ تنفذ هذه في W1.2
وW1.3 بعد قبول القرار.

## Alternatives

- **Person داخل Identity:** رفض لأنه يجعل تسجيل الشخص والتكليف والاستيراد التنظيمي يحتاج
  اعتماداً عكسياً أو كتابة موزعة، ويخلط PII التنظيمية مع أسرار الدخول.
- **ملكية مشتركة أو نسختان مرجعيتان:** رفض لغياب مصدر حقيقة واحد واحتمال drift وتجاوز
  سياسات الحقول والاحتفاظ.
- **FK أو join مباشر بين الموديولين:** رفض لأنه يكسر استقلال الملكية واختبارات الحدود.
- **معاملة استيراد واحدة للموديولين:** رفض لأنها توسع المعاملة عبر مالكين وتمنع إعادة
  provisioning بأمان.
- **موديول Import مستقل:** رفض في W1.2 لأنه يضيف مالك أعمال جديداً من دون إزالة حاجة كل
  موديول إلى التحقق والكتابة في معاملته الخاصة.

## Consequences

- يصبح Organization المصدر المرجعي للشخص وحقائق التنظيم، ويبقى Identity المصدر المرجعي
  لأمن الحساب والجلسة.
- يحتاج context map وmodule catalog والمسرد ووثائق المجال والبيانات والعقود إلى مواءمة
  صريحة بعد قبول القرار، مع إزالة كل FK عابر للموديولات.
- قد ينجح الاستيراد قبل اكتمال provisioning؛ لذلك يلزم status مستقل وإعادة محاولة آمنة
  وتنبيه تشغيلي للفشل المتكرر.
- يجب تثبيت schema وأرقام إصدارات أحداث provisioning ودورة الحياة في عقود W1.2 قبل التنفيذ.
- تبقى `PlatformSettings` تبعية Identity لسياسات كلمات المرور والجلسات ولا يغيرها القرار.

## Security

- تخضع PII التي يملكها Organization للتصنيف والتشفير وسياسات الحقول والاحتفاظ والتدقيق.
  تحصر قوالب الاستيراد الأعمدة المسموحة، وتحفظ الملفات الخام مشفرة في quarantine، وتنقح
  أخطاء الصفوف، وتطبق مدة احتفاظ معلنة، ويجب اختلاف الرافع عن المعتمد.
- كل قراءة وتغيير واستيراد وprovisioning يمر بتفويض خلفي fail-closed وتدقيق actor وsubject
  وcorrelation والنتيجة، من دون كلمات مرور أو tokens أو payload خام في السجلات.
- انتهاء التكليف أو العلاقة يعيد تقييم التفويض. تعطيل الحساب وتغيير كلمة المرور يسحبان
  الجلسات وفق ADR-012، دون كتابة Organization في جداول Identity.
- ينشأ bootstrap الإداري بانتهاء إلزامي لا يتجاوز نافذة التهيئة، وبموافقة صريحة مسجلة وMFA
  وتدقيق كامل. لا يعتمد استيراداً أو provisioning، وتسحب جلساته عند الانتهاء أو أول استخدام
  لخيار break-glass وفق سياسة Identity.

## Operations

- يراقب التشغيل backlog وفشل وإعادة provisioning، ويعرض فرق الحالة بين الاستيراد
  التنظيمي والحسابات.
- تحمل الأحداث `event_id` و`person_id` وcorrelation مناسباً، ويمنع consumer الازدواج عبر
  Inbox أو checkpoint ذري.
- ترتبط أدلة التطبيق والرفض وإعادة المحاولة بالـrevision وإصدار العقد المستخدم.
- يسجل Audit معرف actor مع snapshot غير قابل للتغيير للاسم والنطاق وقت الفعل وsubject
  والسبب وhash للمدخل، من دون FK عابر أو payload خام.

## Rollback

قبل قبول القرار يمكن رفضه بلا تغيير في التنفيذ. بعد التنفيذ يرجع كل موديول عبر إصدار
تطبيق متوافق وأحداث تصحيحية، لا عبر كتابة مباشرة في الموديول الآخر أو down migration
هدامة. تتبع الترحيلات expand ثم migrate ثم verify ثم contract، وتبقى الأحداث القديمة
قابلة للاستهلاك خلال نافذة التوافق.

## Enforcement

- الآن: `./scripts/validate-docs.sh` و`make verify-boundaries` بعد تسجيل ADR في الفهارس.
- قبل W12-00: مواءمة ownership وdependency map والعقود وإزالة FK المتعارضة في الوثائق.
- أثناء التنفيذ: اختبارات تمنع imports وjoins وFKs العابرة، واختبارات عقد مرجع Person،
  ومعاملات Outbox، وإعادة تسليم provisioning، والتعارض في idempotency key.
- أثناء الاختبار: حالات allow وdeny وfail-closed، وموافقة الاستيراد المزدوجة، وعدم التطبيق
  الجزئي، وترتيب الأحداث القديم، وتعليق الشخص أو انتهاء التكليف وتعطيل الحساب وسحب الجلسات.

## Review

قبول القرار مشروط بمواءمة context map وmodule catalog والمسرد ووثائق المجال والنموذج
المنطقي والتدقيق والعقود في مجموعة مراجعة واحدة. يراجع القرار عند تغيير مالك Person أو
شكل الربط بالحساب أو إضافة قناة استيراد أو provisioning.

## References

`docs/domain/organization-and-people.md`، `docs/domain/identity.md`،
`docs/architecture/context-map.md`، `docs/architecture/module-catalog.md`،
`docs/governance/glossary.md`، `docs/plans/release-1-platform.md`.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | مجلس معمارية المنصة | اقتراح ملكية Person والحسابات وفصل الاستيراد عن provisioning |
