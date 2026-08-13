# 10 · موديول Notifications (التنبيهات)

> **الحالة الحالية بعد تقليص المنتج إلى المهام — 2026-08-13.**

## المسؤولية

يسجل الموديول إشعارات المهام والتنبيهات التقنية لكل مستخدم، ويعرض صندوق الإشعارات، ويدعم تعليم الإشعار كمقروء. المستهلك التقني يستخدم inbox وdead-letter queue مع محاولات محدودة.

## السطح الحالي

- `GET /api/v1/notifications` يعيد إشعارات المستخدم الحالي مع cursor مشفر وإخفاء بيانات المصدر غير المصرح بها.
- `POST /api/v1/notifications/{notificationId}/read` يعلّم إشعار المستخدم الحالي كمقروء.
- `RecordTaskNotifications` يسجل إشعارات إنشاء المهمة وإسنادها وتغير حالتها.
- `NotificationsTechnicalAlertWorker` يستهلك أحداث `platform.technical-alert.v1` ويطبق DLQ بعد فشل المحاولات.

## البيانات

- `notifications`
- `notification_inbox`
- `notification_recipients`
- `notification_dead_letters`
- حقائق المصدر: `source_owner_facility_id` و`source_classification`

## قواعد الصلاحية

- لا يكفي أن يكون المستخدم مستلم الإشعار لكشف عنوان المورد أو رابطه.
- تخزن إشعارات المهام حقائق نطاق المصدر والتصنيف، ثم تعيد واجهة القراءة التحقق من الوصول الحالي.
- عند غياب حقائق المصدر أو سحب صلاحية المورد، يعاد إشعار مقنّع دون رابط قابل للفتح.

## التشغيل

- أوامر التنبيهات التقنية وDLQ مسجلة كدورات bounded باستخدام `--once` و`--limit`.
- الإنتاج يسجلها صراحة في Laravel Scheduler مع `withoutOverlapping` و`onOneServer`.
- لا توجد أي تبعية على WorkRecords أو WorkDefinitions أو Workflow.

## المخاطر المتبقية

- يجب مراقبة عمر أقدم رسالة في inbox وDLQ، وليس مجرد بقاء عملية scheduler حية.
- يجب أن يظل فحص نطاق المصدر fail-closed عند إضافة أي نوع مورد جديد.
- يحتاج `MarkNotificationReadController` إلى إبقاء دلالة idempotency متوافقة مع العقد المنشور.
