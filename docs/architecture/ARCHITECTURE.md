# معمارية Cluster الحالية

> **آخر مطابقة مع الكود: 2026-08-13.** هذه الوثيقة تصف المنتج الحالي بعد تقليصه إلى نظام مهام مؤسسي مضبوط بنطاق المنشأة. الوحدات المتقاعدة `WorkRecords` و`WorkDefinitions` و`Workflow` ليست جزءًا من التشغيل أو الـ API.

## صورة النظام

Cluster هو Laravel modular monolith مع واجهة React/Vite. المستخدم يسجل دخوله بهوية واحدة، ويعمل داخل التجمع أو المنشأة أو الوحدة التي تغطيها تعيينات الصلاحية. Tasks هي مساحة العمل التنفيذية؛ Organization وIdentity وAuthorization هي الأساس الذي يحدد من هو المستخدم، أين يعمل، وماذا يمكنه أن يفعل.

```text
Browser (React/Vite)
        │ cookie session + CSRF + correlation id
        ▼
Laravel /api/v1
        │
        ├── Identity
        ├── Authorization (RBAC + ABAC + deny + delegation)
        ├── Organization (cluster/facility/unit/person/assignment)
        ├── Tasks
        ├── Documents
        ├── Notifications
        ├── Audit
        ├── PlatformSettings
        ├── Search
        └── Reporting
                │
                ├── MySQL
                ├── Redis streams/cache/session
                ├── private S3-compatible storage
                └── ClamAV
```

## حدود الوحدات

| الوحدة | الملكية |
|---|---|
| Identity | الحسابات، المصادقة، الجلسات، MFA وربط الحساب بالشخص |
| Authorization | الأدوار، القدرات، التعيينات، النطاقات، المنع والتفويض |
| Organization | التجمع، المنشآت، الوحدات، المناصب، الأشخاص والتعيينات |
| Tasks | المهمة، الإسناد، المشاركون، التعليقات، المرفقات ودورة الحياة |
| Documents | رفع المستند، الحجر، الفحص، الإتاحة، الربط والاحتفاظ |
| Notifications | صندوق المستخدم، إشعارات المهام والتنبيهات التقنية وDLQ |
| Audit | سجل الأدلة الأمني، HMAC chain، التحقق والتصدير والاحتفاظ |
| PlatformSettings | إعدادات المنصة، التقويم، الصحة والعمليات التشغيلية |
| Search | إسقاطات البحث المصنفة للموارد المحتفظ بها فقط |
| Reporting | تعريفات التقارير، الإسقاطات، التشغيل والتصدير المنضبط |

تمنع `ModuleBoundariesTest` الاعتماد على internals لوحدة أخرى، والوصول المباشر إلى جداول يملكها موديول آخر، ووضع controller أو transaction أو outbox خارج المالك الصحيح.

## مسار الطلب

1. `apps/api/routes/web.php` يعرّف `/api/v1`.
2. `IdentitySessionMiddleware` يحل الجلسة ويثبت correlation ID.
3. عمليات الكتابة تمر كذلك عبر `IdentityCsrfMiddleware`.
4. الـ controller يتحقق من المدخل والعقد المتزامن مثل `Idempotency-Key` و`If-Match`.
5. يبني النظام حقائق المورد من المورد المستهدف، ثم يستدعي `DecideAccess`.
6. الـ handler ينفذ قاعدة العمل داخل transaction يملكها الموديول.
7. النتيجة تعاد في envelope موحد أو `application/problem+json` مع ETag وlock version عند الحاجة.

## نموذج التنظيم

```text
Cluster
├── Cluster-owned units
├── Facility A
│   └── Facility A units
└── Facility B
    └── Facility B units
```

الوحدة التابعة لمنشأة لا يكون parent لها في منشأة أخرى أو في وحدة مملوكة مباشرة للتجمع. العلاقة الإشرافية بين إدارة التجمع وإدارة المنشأة هي governance/authorization وليست `parent_id` عابرًا للجذر.

## نموذج الصلاحيات

أنواع النطاق المعتمدة فقط:

```text
cluster
facility
unit
record_set
```

قرار الوصول:

```text
Principal
  + Capability
  + Role assignment
  + Scope coverage
  + authoritative resource facts
  + classification / relationship ABAC
  + active delegation
  - explicit deny
= Access decision
```

`cluster` يعني المورد التابع مباشرة للتجمع أو لأي منشأة/وحدة تحته. لا يوجد `selected_entities`؛ الوصول لعدة منشآت يمثل بتعيينات مستقلة لتسهيل التدقيق والسحب والانتهاء.

حقائق المورد المشتركة تتضمن عند توفرها:

```text
clusterId
ownerFacilityId
organizationUnitId
recordId
classification
ownerUserId / assignee / participant facts
```

القاعدة الحاسمة: لا يجوز استخدام منشأة المستخدم كبديل عن منشأة المورد المستهدف. عند تعذر إثبات ancestry يفشل القرار مغلقًا.

## Tasks

المهمة مستقلة وينشئها مستخدم مباشرة. لا تحتوي على workflow step أو generic source reference. تخزن ملكية تنظيمية صريحة، ويظل كون المستخدم منشئًا أو مسندًا إليه أو مشاركًا شرط ABAC إضافيًا لا بديلًا عن capability.

القدرات الأساسية تشمل القراءة والإنشاء والتحديث والإسناد والبدء والإكمال والإلغاء. كل list أو mutation يعاد تفويضه بوقته الحالي، ولذلك يسري سحب الدور والمنع الصريح فورًا.

## Documents

الملف ينتقل عبر:

```text
upload intent → quarantine → integrity check → malware scan → available
```

التنزيل يعيد تفويض المستند وكل رابط نشط. بيئة الإنتاج تتطلب صراحة تخزينين منفصلين للحجر والإتاحة، credentials وKMS منفصلة، worker identity، ClamAV وإعدادات endpoint مسموح بها. أي نقص يمنع الإقلاع أو يبقي المحول غير متاح.

## الأحداث والعمال

`outbox_events` مورد مشترك تكتب إليه الوحدات داخل نفس transaction الذي يغير aggregate. أنواع الأحداث مسجلة في catalog بأسماء `com.cluster.<module>.<event>.v1`. كل lane محدود بـ `--once` وbatch، ولا يعتبر الصف منشورًا إلا بعد نجاح النقل الفعلي.

الإنتاج يفصل بين:

- `worker-loop`: relays/consumers ذات الدورة القصيرة.
- Laravel Scheduler: عمليات المنصة، التنبيهات، DLQ، تنظيف التقارير واحتفاظ المستندات.

يجب مراقبة عمر أقدم صف queued/outbox/DLQ إضافة إلى صحة العملية.

## قاعدة البيانات والترحيلات

- `apps/api/config/module_migrations.php` هو سجل الترحيلات الحية.
- الترحيلات التي سبق نشرها لا تعدل؛ التصحيح يكون بترحيل forward جديد.
- حذف W27 غير قابل للعكس، ويعمل على قاعدة إنتاجية قديمة فقط مع معرّف backup ومعرّف restore validation.
- الترحيل ينظف الإسقاطات وروابط الموارد المتقاعدة قبل حذف المصدر.
- اختبارات SQLite السريعة لا تستبدل MySQL upgrade/concurrency gate.

## الواجهة والعقد

`docs/contracts/api/openapi.yaml` هو مصدر الحقيقة للـ API. Orval يولد العميل، ولا تعدل الملفات المولدة يدويًا. React يستعيد الجلسة من cookie، ويعرض التنقل حسب capabilities لتحسين التجربة فقط؛ الخادم هو الحارس الفعلي.

## التشغيل الإنتاجي

الحزمة في `infra/platform/production/` تستخدم خدمات Caddy وWeb وAPI وWorker وScheduler وMigrate مع صور مثبتة، مستخدمين غير root، filesystem للقراءة، وإسقاط capabilities. MySQL وRedis وخدمات التخزين والفحص خارج الحزمة وتدخل عبر متغيرات إلزامية.

قبل W27 اتبع `docs/operations/ha-dr-backup.md`. لا يعتبر نجاح `/up` وحده دليلًا على MySQL وRedis وS3 وClamAV أو relays؛ تستخدم لوحة PlatformSettings للفحص العميق وتراقب المؤشرات التشغيلية خارجيًا.

## بوابات الجودة

```sh
make verify-intake
make verify-core
make verify-mysql-integration-strict
make test-web
make docs-validate
make audit-dependencies
make validate-production-bundle
make scan-secrets
```

نجاح اختبار مركز لا يثبت الجاهزية الإنتاجية. قرار الدمج يحتاج نجاح البوابات المتأثرة، واختبار ترقية MySQL، ودليل backup/restore عند وجود ترحيل حذفي.
