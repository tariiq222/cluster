---
phase: 01-w1-1-walking-skeleton
plan: "05"
subsystem: work-records
tags: [laravel, work-definitions, work-records, mysql, transactional-outbox, cloudevents, idempotency, module-boundaries]
requires:
  - phase: 01-04
    provides: "development fixture principals, facility facts, and fail-closed authorization contract"
provides:
  - "نسخة WorkDefinition منشورة وثابتة بالرمز request وبحقلي title وdescription فقط."
  - "Envelope WorkRecord submitted يثبت نسخة النوع والمالك والتصنيف والـpayload وlock_version."
  - "حفظ ذري لسجل العمل وCloudEvent في Outbox مع إعادة idempotent للحدث نفسه."
affects: [submit-work-record, notifications, work-definitions, work-records, walking-skeleton]
tech-stack:
  added: []
  patterns: ["immutable development work type fixture", "source-owned work record envelope", "caller-owned transactional Outbox", "CloudEvent identity idempotency"]
key-files:
  created: [apps/api/Modules/WorkDefinitions/Features/PublishRequestFixture/Handler/PublishRequestFixtureHandler.php, apps/api/Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php, apps/api/Modules/WorkRecords/Domain/WorkRecord.php, apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php, apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php, apps/api/Modules/WorkRecords/Infrastructure/Outbox/Migrations/CreateOutboxTable.php]
  modified: [apps/api/app/Providers/AppServiceProvider.php, apps/api/tests/Feature/WalkingSkeletonE2ETest.php]
key-decisions:
  - "يبقى request رمز WorkDefinition منشوراً ثابتاً لا موديولاً أو جدولاً أو Aggregate مستقلاً."
  - "تثبت WorkRecords معرف نسخة WorkDefinition ومعرّفات المالك فقط بلا FK أو import عابر للموديولات."
  - "تُعاد محاولة CloudEvent المتطابق بلا أثر إضافي، بينما يفشل تعارض event_id داخل المعاملة ويعيد كتابة المصدر."
requirements-completed: [SEC-R1-009, FR-R1-013]
coverage:
  - id: D1
    description: "نشر fixture request ثابت يصف title وdescription فقط ويعيد النسخة الحية نفسها عند التكرار."
    requirement: FR-R1-013
    verification:
      - kind: integration
        ref: "apps/api/Modules/WorkDefinitions/Features/PublishRequestFixture/Tests/PublishRequestFixtureTest.php; composer test -- --filter=PublishRequestFixture"
        status: pass
    human_judgment: false
  - id: D2
    description: "Envelope WorkRecord وملكية جداول WorkRecords/Outbox بلا FK أو join عابر للموديولات."
    requirement: SEC-R1-009
    verification:
      - kind: unit
        ref: "apps/api/Modules/WorkRecords/Domain/Tests/WorkRecordEnvelopeTest.php; composer test -- --filter=WorkRecordEnvelope"
        status: pass
      - kind: integration
        ref: "make verify-boundaries"
        status: pass
    human_judgment: false
  - id: D3
    description: "حفظ مصدر WorkRecord وCloudEvent Outbox في معاملة واحدة مع rollback عند event_id متعارض وإعادة idempotent للحدث نفسه."
    requirement: FR-R1-013
    verification:
      - kind: integration
        ref: "apps/api/Modules/WorkRecords/Domain/Tests/WorkRecordEnvelopeTest.php#test_persisting_a_submitted_record_writes_its_cloudevent_to_the_outbox_in_the_same_transaction"
        status: pass
    human_judgment: false
duration: 8min
completed: 2026-07-16
status: complete
---

# Phase 01 Plan 05: Fixture الطلب وWorkRecord/Outbox Summary

**نُشرت نسخة `request` ثابتة بحقلَي العنوان والوصف، وأضيف حفظ `WorkRecord` submitted مع CloudEvent Outbox ذري وإعادة آمنة للحدث المتطابق.**

## Performance

- **Duration:** 8 min
- **Started:** 2026-07-15T21:00:11Z
- **Completed:** 2026-07-15T21:08:19Z
- **Tasks:** 2/2
- **Files modified:** 10

## Accomplishments

- أضيف fixture `request` مملوك لـ`WorkDefinitions` في بيئتي local/testing فقط، وبنسخة منشورة ثابتة تصف `title` و`description` دون إنشاء حد `Requests` محظور.
- أضيف `WorkRecord` immutable envelope يتحقق من UUIDv7 وowner facility/creator والتصنيف والـpayload، ويعرض الحالة `submitted` مع `lock_version` أولي.
- أضيفت جداول `work_records` و`outbox_events` المملوكة للمصدر بلا Foreign Keys إلى موديولات الأعمال، مع Handler يحفظ المصدر وCloudEvent معاً ويتعامل مع replay المتطابق idempotently.

## Task Commits

1. **Task 1: نشر WorkDefinition fixture بالرمز request** — `df63260` (RED test)، `2ab547c` (GREEN fixture).
2. **Task 2: إنشاء Envelope وملكية persistence وOutbox** — `7f36731` (RED envelope)، `758b2c0` و`2ef2fb1` (RED transaction/idempotency)، `ed25310` (GREEN persistence and Outbox).
3. **Rule 1 fix:** `71f5ddd` (تهيئة قاعدة fixtures لاختبار walking-skeleton).

## Validation

- `composer test -- --filter=PublishRequestFixture` — PASS: اختباران و8 assertions.
- `composer test -- --filter=WorkRecordEnvelope` — PASS: 5 اختبارات و9 assertions، بما فيها rollback تعارض `event_id` وإعادة الحدث المتطابق.
- `make verify-boundaries` — PASS: 4 اختبارات و6 assertions.
- `php -l` لملفات PHP الجديدة — PASS.
- `composer --working-dir=apps/api validate --strict` — PASS.
- `make test-e2e-w1-1-red` — PASS: المسار غير المبني يعيد 404 المقصود؛ لا يزال submit HTTP وقراءة السجل والإشعار خارج هذا الـplan.

## Files Created/Modified

- `apps/api/Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php` — جدول fixture definition المحصور محلياً واختبارياً.
- `apps/api/Modules/WorkDefinitions/Features/PublishRequestFixture/Handler/PublishRequestFixtureHandler.php` — نشر/استرجاع نسخة `request` المنشورة deterministically.
- `apps/api/Modules/WorkRecords/Domain/WorkRecord.php` — invariants وserialization الخاصان بـsubmitted envelope.
- `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php` — حقيقة السجل المصدرية ومؤشراتها بلا FK عابر.
- `apps/api/Modules/WorkRecords/Infrastructure/Outbox/Migrations/CreateOutboxTable.php` — مخزن CloudEvents durable للـOutbox.
- `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php` — معاملة المصدر والـOutbox والتحقق من CloudEvent وreplay idempotent.
- `apps/api/Modules/WorkRecords/Domain/Tests/WorkRecordEnvelopeTest.php` — تغطية envelope/ownership/atomic rollback/idempotency.
- `apps/api/app/Providers/AppServiceProvider.php` — تسجيل migrations المملوكة كي تعمل في Laravel وSQLite الاختباري.
- `apps/api/tests/Feature/WalkingSkeletonE2ETest.php` — تهيئة migrations في اختبار القبول الأحمر لتفشل الحدود غير المبنية بـ404 لا بـ500.

## Decisions Made

- `request` هو code لنوع عمل منشور، وليس تصنيفاً للبيانات أو اسم business boundary؛ لم يُضف أي `Requests` module/table/identifier/event.
- تحفظ `work_type_version_id` وowner references كمعرّفات فقط؛ لا يستورد WorkRecords Infrastructure من WorkDefinitions أو Authorization ولا يضيف FK أو join عابرين للملكية.
- يحفظ Handler الحدث كـCloudEvent كامل ويرفض envelope غير المطابق؛ وعند تكرار event_id المتطابق يرجع بلا أثر جديد، بينما يعيد التعارض transaction المصدر.

## TDD Gate Compliance

- **Task 1 RED:** `df63260` فشل لغياب `PublishRequestFixtureHandler`، ثم صار GREEN في `2ab547c`.
- **Task 2 RED:** `7f36731` فشل لغياب `WorkRecord` والجداول، ثم اختبرت `758b2c0` الذرية و`2ef2fb1` idempotency قبل تنفيذها في `ed25310`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] إضافة Handler لحفظ WorkRecord وOutbox في المعاملة نفسها**
- **Found during:** Task 2.
- **Issue:** كانت artifacts المخططة تقتصر على envelope وmigrations، فلا تثبت مسار كتابة المصدر مع Outbox رغم objective الـplan ومتطلب الذرية.
- **Fix:** أضيف `SubmitWorkRecordHandler` يحفظ envelope وCloudEvent في `DB::transaction`، ويتحقق من CloudEvent ويجعل replay المتطابق idempotent.
- **Files modified:** `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php` و`WorkRecordEnvelopeTest.php`.
- **Verification:** اختبارات rollback عند duplicate `event_id` وreplay idempotent تمر.
- **Committed in:** `ed25310` مع اختبارات RED `758b2c0` و`2ef2fb1`.

**2. [Rule 3 - Blocking] تسجيل migrations المملوكة في Laravel**
- **Found during:** Tasks 1 و2.
- **Issue:** Laravel لا يكتشف migrations ذات أسماء artifacts المملوكة للموديولات تلقائياً؛ لم تكن جداول fixtures/WorkRecords ستوجد في SQLite الاختباري.
- **Fix:** سجل `AppServiceProvider` ملفات migrations التابعة لـWorkDefinitions وWorkRecords صراحةً.
- **Files modified:** `apps/api/app/Providers/AppServiceProvider.php`.
- **Verification:** اختبارات fixture وWorkRecord تستخدم `RefreshDatabase` وتمر.
- **Committed in:** `2ab547c` و`ed25310`.

**3. [Rule 1 - Bug] تهيئة اختبار walking-skeleton بمهاجرات fixtures**
- **Found during:** التحقق الكلي بعد Task 2.
- **Issue:** كان اختبار القبول الأحمر يصل إلى login fixture بلا `RefreshDatabase`، فيفشل بـ500 لغياب الجدول بدلاً من 404 المقصود للمسار غير المبني.
- **Fix:** أضيف `RefreshDatabase` إلى اختبار القبول نفسه.
- **Files modified:** `apps/api/tests/Feature/WalkingSkeletonE2ETest.php`.
- **Verification:** `make test-e2e-w1-1-red` يمر ويثبت 404 المقصود.
- **Committed in:** `71f5ddd`.

**Total deviations:** 3 auto-fixed (1 missing critical، 1 blocking، 1 bug).
**Impact on plan:** جميعها لازمة لإثبات كتابة المصدر/Outbox وتشغيل الاختبارات بالحدود المقررة؛ لا توسع إلى route أو Notifications أو موديول أعمال جديد.

## Known Stubs

None — لا توجد قيم placeholder أو مصادر بيانات وهمية في artifacts الجديدة؛ HTTP submit/relay/Notifications مؤجلة عمداً لخطة لاحقة وليست stubs داخل هذه artifacts.

## User Setup Required

None — لا توجد خدمة خارجية أو secrets أو إعداد نشر ضمن هذا الـplan.

## Next Phase Readiness

- يتاح لمسار HTTP اللاحق استهلاك fixture `request` واستدعاء `SubmitWorkRecordHandler` بعد التحقق عبر contracts المنشورة.
- يبقى relay والـNotifications وroutes القراءة/الكتابة خارج النطاق؛ لا يزال اختبار walking-skeleton الشامل أحمر بشكل مقصود عند المسارات غير المبنية.

## Self-Check: PASSED

- تحققت كل artifacts المذكورة وملف SUMMARY على القرص.
- تحققت commits `df63260`, `2ab547c`, `7f36731`, `758b2c0`, `2ef2fb1`, `ed25310`, و`71f5ddd` في Git.
