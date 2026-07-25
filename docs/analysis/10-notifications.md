# 10 · موديول Notifications (التنبيهات)

> **المسار:** `apps/api/Modules/Notifications/`
> **Rank:** 11
> **عدد الملفات:** 15 PHP

## 1 · نبذة عامة
موديول `Notifications` يستقبل **أحداث التيار** (Work Record Submitted + Technical Alerts) ويولّد إشعارات per-recipient مع **dead-letter queue** بعد 3 محاولات.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/ResolveTechnicalAlertRecipients.php` | recipient_capability → list of UUIDv7 |
| Features (ConsumeTechnicalAlert) | `Features/ConsumeTechnicalAlert/Handler/ConsumeTechnicalAlertHandler.php`، `Worker/NotificationsTechnicalAlertWorker.php` | fan-out لتنبيهات تقنية |
| Features (ConsumeWorkRecordSubmitted) | `Features/ConsumeWorkRecordSubmitted/Handler/ConsumeWorkRecordSubmittedHandler.php`، `Worker/NotificationsStreamWorker.php` | per-recipient fan-out |
| Features (ListMyNotifications) | `Features/ListMyNotifications/Http/ListMyNotificationsController.php`، `Http/MarkNotificationReadController.php` | HTTP layer |
| Migrations | `Infrastructure/Persistence/Migrations/` (5 ملفات) | inbox + core + W13 + W18 + W20 |
| Tests | `Tests/` | 3 ملفات (handler + worker + HTTP) |

## 3 · أهم العقود
- `ResolveTechnicalAlertRecipients` (الوحيد).

## 4 · Domain / Handlers / Workers

### 4.1 Workers
- `NotificationsTechnicalAlertWorker` — التيار `platform.technical-alert.v1`، DLQ بعد 3 محاولات.
- `NotificationsStreamWorker` — التيار `platform.work-record.submitted.v1`، مع reclaim (post-commit/pre-ack) + DLQ envelope.

### 4.2 Handlers
- `ConsumeTechnicalAlertHandler` — fan-out عبر `ResolveTechnicalAlertRecipients`.
- `ConsumeWorkRecordSubmittedHandler` — fan-out per-recipient.

### 4.3 HTTP
- `ListMyNotificationsController` — `GET /api/v1/notifications` مع cursor مشفّر + DecideAccess masking.
- `MarkNotificationReadController` — `POST /api/v1/notifications/{id}/read` يطلب `Idempotency-Key` دون تخزين.

## 5 · مصادر البيانات
- `notifications` (TABLE_OWNERS)
- `notification_inbox` (event_id PK)
- `notification_recipients` (W18)
- `notification_dead_letters` (W18)
- `notifications.source_owner_facility_id` + `source_classification` (W13)
- W20: `unique([event_id, recipient_user_id])` + `recipient_capability`.

## 6 · نقاط الـ API
- `GET /api/v1/notifications` — IdentitySession + RequirePrincipal.
- `POST /api/v1/notifications/{notificationId}/read` — + IdentityCsrf.

## 7 · الوضع الحالي
- ✅ **Production-grade workers** مع reclaim + DLQ.
- ✅ **Idempotency** على inbox.
- ✅ **Cursor-based pagination** مع masking.
- ⚠️ `MarkNotificationReadController` يطلب Idempotency-Key دون تخزين.
- ⚠️ `DatabaseTechnicalAlertRecipientResolver` في `app/Integrations/`.

## 8 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| N1 | `DatabaseTechnicalAlertRecipientResolver` في `app/Integrations/Notifications/` | `ModulePlacementInventory.php:92` |
| N2 | `MarkNotificationReadController` يطلب Idempotency-Key دون تخزينه (zombie behavior) | `ListMyNotifications/Http/MarkNotificationReadController.php` |
| N3 | `ConsumeSubmittedNotification` middleware إرث في `app/` | `app/Http/Middleware/ConsumeSubmittedNotification.php` |
| N4 | `notification_inbox`, `notification_recipients`, `notification_dead_letters` غير مُسجَّلة في `TABLE_OWNERS` (فقط `notifications`) | `ModuleBoundariesTest.php:107` |
| N5 | `RecipientCapability` enum/values غير موثّقة | (gap) |
| N6 | لا تأكيد أن Workers يعملان على نفس connection (Redis predis) | (gap) |
| N7 | `NotificationsStreamWorker` لا يحذف من inbox بعد commit (idempotency risk) | `Worker/NotificationsStreamWorker.php` |

## 9 · التحسينات المقترحة

1. **نقل `DatabaseTechnicalAlertRecipientResolver`** إلى `Modules/Notifications/Infrastructure/Persistence/`.
2. **إصلاح `MarkNotificationReadController`**: تخزين Idempotency-Key أو إزالة الـ header expectation.
3. **نقل `ConsumeSubmittedNotification`** middleware إلى `Modules/Notifications/Features/ConsumeOutboxInTesting/`.
4. **تسجيل 3 جداول** (`notification_inbox`, `notification_recipients`, `notification_dead_letters`) في `TABLE_OWNERS`.
5. **تأكيد post-commit cleanup** في Workers (حذف من inbox بعد ACK).
6. **توثيق `recipient_capability` values** في `docs/architecture/`.
7. **concurrency tests** على parallel notifications.
