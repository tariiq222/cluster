---
doc_id: CON-MC-001
title: عقود الموديولات
type: contracts
status: accepted
version: 1.7.0
date: 2026-07-18
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources: []
references:
- docs/contracts/capabilities/identity-credentials-and-sessions.md
- docs/contracts/capabilities/document-signed-direct-upload.md
- docs/contracts/capabilities/organization-import-rows-v1.md
- docs/contracts/capabilities/temporary-assignment.md
---
# Module Contract Rules

## Ownership

| Module | Owns | Publishes |
|---|---|---|
| Organization | Person, PII الأساسية، الهيكل والتكليفات والاستيراد | `ClusterCreated`, `ClusterUpdated`, `FacilityCreated`, `FacilityUpdated`, `FacilityArchived`, `OrganizationUnitCreated`, `OrganizationUnitMoved`, `OrganizationUnitUpdated`, `OrganizationUnitArchived`, `PositionCreated`, `PositionUpdated`, `PersonRegistered`, `PersonUpdated`, `AssignmentStarted`, `AssignmentEnded`, `ImportJobSubmitted`, `ImportJobValidated`, `ImportJobApproved`, `ImportJobRejected`, `ImportJobApplied`, `ImportJobCancelled`, `ImportJobFailed`, `ValidatePersonReference`, `IdentityProvisioningRequested`, `PersonAccessStatusChanged` |
| Identity | UserAccount والحسابات والجلسات وInbox provisioning وcurrent principal | `UserAccountCreated`, `UserAccountChanged`, authenticated access context |
| Authorization | access decisions | `AccessDecision` |
| Work Definitions | immutable published work-type versions | definition reads |
| Work Records | record envelope, facts, payload and submission | `WorkRecordSubmitted` |
| Workflow | instances, active steps and immutable decisions | `WorkflowStepActivated`, `WorkflowDecisionRecorded` |
| Documents | document bytes, scan result and metadata | `DocumentScanCompleted` |

No consumer writes another module's persistence. Consumers use the HTTP contract for synchronous reads/commands and events only for derived state or reactions.

## W1.2 Organization and Identity

- `Organization` يملك Person وحقول PII الأساسية ويزيد `person_version` عند كل تغيير وصول منشور.
- Person سجل على مستوى التجمع الواحد وليس مملوكاً لمنشأة؛ تحدد التكليفات نطاق المنشأة لاحقاً، لذلك لا يحمل جدول `people` مفتاح منشأة.
- `Identity` يحتفظ بـ`person_id` كمرجع خارجي بلا FK أو ORM relation أو join، ويتحقق منه
  عبر `ValidatePersonReference` قبل تفعيل الحساب، ويرسل `person_version` المتحقق منه مع
  أمر إنشاء الحساب لمنع سباق تغير حالة Person.
- حالات الحساب الوحيدة هي `pending`, `active`, `locked`, `disabled`, `archived`؛
  `archived` نهائية، وحالة Person المسماة `suspended` تحول الحساب إلى `disabled`.
- `IdentityProvisioningRequested` يصدر بعد تطبيق Person داخل معاملة Organization وOutbox
  نفسها. يطبق Identity كل `person_version` مرة واحدة مع Inbox وhigh-water mark ذريين.
- معرفات actor مثل `submitted_by_user_id` و`approved_by_user_id` حقائق تدقيق بلا FK عابر.
- ملفات الاستيراد الخام مشفرة في quarantine، وأخطاء الصفوف منقحة ولا تعيد payload خاماً.
- يستهلك Organization مصدر الاستيراد النظيف عبر عقد Documents ومعرف
  `quarantine_object_id` opaque؛ لا يصل إلى object key أو جدول أو مخزن Documents.
- صفوف `facilities` و`organization_units` و`positions` في v1 تطابق Create API الحالي
  وتبقى create-only. schemas المرجعية منشورة في عقد صفوف الاستيراد.
- Credential والجلسة الحقيقية planned: الحساب بلا Credential يبقى `pending`، والجلسة
  المستهدفة opaque server-side في Cookie محمية مع CSRF، لا Bearer token في المتصفح.
- TemporaryAssignment planned ومقيد بوحدة واحدة وقدرات صريحة ومدة لا تتجاوز 90 يوماً؛
  Organization يقدم الحقائق فقط وAuthorization يصدر القرار.
- Authorization في W1.2 مغلق افتراضياً، ومحدد النطاق لا يوسع القدرات الممنوحة.
- Audit append-only: لا يملك كاتب Audit صلاحية update أو delete، وتخزن السلسلة وactor
  وsubject وcorrelation من دون أسرار أو payload استيراد خام.

## HTTP Rules

- Base path: `/api/v1`; JSON media type: `application/json`.
- `X-Correlation-ID` is required on every request and returned on every response. It is a lowercase RFC 9562 UUIDv7 matching `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx`.
- A create, submit, decision, upload-finalize, or export request requires `Idempotency-Key` (1-255 visible ASCII characters). Replays with the same key and different request semantics return `409`.
- يعيد replay الناجح snapshot الاستجابة الأصلية وETag الأصليين، ولا يعيد الحالة الحالية للمورد بعد تعديله.
- `ETag` is returned on mutable representations. `PATCH`, cancel/archive actions, submit, and workflow decisions require `If-Match`; a stale value returns `412`. User-facing APIs never hard-delete records.
- Collection pagination uses opaque `cursor` and `limit` (1-100). A next cursor is returned in `Link` with `rel="next"`; clients must not construct or decode cursors.
- Responses are filtered by authorization and field policy before serialization. `confidential` and `top_secret` reads, downloads, exports, and decisions are audit events; search never discloses `top_secret` and must not index restricted document content.

## Event Rules

- Event messages are CloudEvents JSON with `specversion: "1.0"`, UUIDv7 `id` and required `correlationid`, and UTC `time` ending in `Z`.
- The transport is Redis Streams with consumer groups and explicit acknowledgement; Kafka topics are not part of this contract.
- Producers persist the business mutation and Outbox row atomically. The relay delivers at least once.
- Consumers persist Inbox receipt keyed by CloudEvent `id` before side effects; duplicate deliveries acknowledge without repeating effects.
- Invalid or exhausted messages go to the DLQ with the original CloudEvent, failure code, attempt count, and failure timestamp. They are not silently discarded.
- DLQ publication is idempotent by source stream message ID. The DLQ stream and its `:source-message-index` sidecar share one retention and purge lifecycle and must be removed together only after preserving review evidence.
- `data.classification` and `data.access_context` are mandatory. Consumers may reduce exposure but may never lower classification.

## Compatibility

Schemas use JSON Schema Draft 2020-12 with `additionalProperties: false` unless an explicit free-form payload is required. Additive optional fields are compatible. Removing, renaming, changing type, tightening validation, changing event meaning, or reusing a field requires a new major contract version.

## Change Log

| Version | Date | Change |
|---|---|---|
| 1.7.0 | 2026-07-18 | Publish the remaining planned W1.2 capability boundaries and import row v1 schemas |
| 1.6.0 | 2026-07-18 | Publish governed ImportJob lifecycle event contracts |
| 1.5.0 | 2026-07-18 | Publish organization unit tree and position lifecycle contracts |
| 1.4.0 | 2026-07-18 | Publish optimistic cluster/facility update and facility archive contracts |
| 1.3.0 | 2026-07-18 | Publish ClusterCreated and FacilityCreated contracts for the first Organization slice |
| 1.2.0 | 2026-07-18 | Freeze W1.2 Organization, Identity, import, bootstrap, and audit boundaries |
| 1.1.0 | 2026-07-17 | Define shared HTTP, event, and compatibility rules |
