---
doc_id: CON-MC-001
title: Module Contracts
type: contracts
status: accepted
version: 1.7.0
date: 2026-07-18
owner: Software Engineering Office
reviewers:
- Platform Engineering Office
- Information Security Officer
classification: internal
review_cycle: on every change
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
| Organization | Person, core PII, structure and assignments, and imports | `ClusterCreated`, `ClusterUpdated`, `FacilityCreated`, `FacilityUpdated`, `FacilityArchived`, `OrganizationUnitCreated`, `OrganizationUnitMoved`, `OrganizationUnitUpdated`, `OrganizationUnitArchived`, `PositionCreated`, `PositionUpdated`, `PersonRegistered`, `PersonUpdated`, `AssignmentStarted`, `AssignmentEnded`, `ImportJobSubmitted`, `ImportJobValidated`, `ImportJobApproved`, `ImportJobRejected`, `ImportJobApplied`, `ImportJobCancelled`, `ImportJobFailed`, `ValidatePersonReference`, `IdentityProvisioningRequested`, `PersonAccessStatusChanged` |
| Identity | UserAccount, sessions, and Inbox provisioning, and the current principal | `UserAccountCreated`, `UserAccountChanged`, authenticated access context |
| Authorization | Access decisions | `AccessDecision` |
| Work Definitions | Immutable published work-type versions | definition reads |
| Work Records | Record envelope, facts, payload, and submission | `WorkRecordSubmitted` |
| Workflow | Instances, active steps, and immutable decisions | `WorkflowStepActivated`, `WorkflowDecisionRecorded` |
| Documents | Document bytes, scan result, and metadata | `DocumentScanCompleted` |

No consumer writes another module's persistence. Consumers use the HTTP contract for synchronous reads and commands, and events only for derived state or reactions.

## W1.2 Organization and Identity

- `Organization` owns Person and the core PII fields and increments `person_version` on every published access change.
- Person is a cluster-scoped record, not owned by a facility; assignments determine the facility scope later, so the `people` table does not carry a facility key.
- `Identity` retains `person_id` as an external reference with no FK, ORM relation, or join, and validates it through `ValidatePersonReference` before account activation, and sends the validated `person_version` with the account-create command to prevent a Person state-change race.
- The only account states are `pending`, `active`, `locked`, `disabled`, `archived`; `archived` is terminal, and a Person state named `suspended` transitions the account to `disabled`.
- `IdentityProvisioningRequested` is emitted after applying Person within the same Organization transaction and Outbox. Identity applies each `person_version` exactly once with an atomic Inbox and high-water mark.
- Actor identifiers such as `submitted_by_user_id` and `approved_by_user_id` are audit facts with no cross-module FK.
- Raw import files are encrypted in quarantine, and row errors are redacted and never return the raw payload.
- Organization consumes the clean import source via the Documents contract and the opaque `quarantine_object_id`; it does not access object keys, tables, or the Documents store.
- `facilities`, `organization_units`, and `positions` rows in v1 match the current Create API and remain create-only. Reference schemas are published in the import row contracts.
- Credential and session handling are live: an account without a Credential remains `pending`, and the targeted session is an opaque server-side cookie protected with CSRF, not a Bearer token in the browser.
- TemporaryAssignment is live and bounded to a single unit, with explicit capabilities and a duration no longer than 90 days; Organization provides the facts only, and Authorization issues the decision.
- Authorization in W1.2 is closed by default, and scope does not expand granted capabilities.
- Audit is append-only: no audit writer has update or delete permission, and the chain, actor, subject, and correlation are stored without secrets or raw import payload.

## HTTP Rules

- Base path: `/api/v1`; JSON media type: `application/json`.
- `X-Correlation-ID` is required on every request and returned on every response. It is a lowercase RFC 9562 UUIDv7 matching `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx`.
- A create, submit, decision, upload-finalize, or export request requires `Idempotency-Key` (1-255 visible ASCII characters). Replays with the same key and different request semantics return `409`.
- A successful replay returns the original response snapshot and the original ETag, and does not return the current state of the resource after modification.
- `ETag` is returned on mutable representations. `PATCH`, cancel/archive actions, submit, and workflow decisions require `If-Match`; a stale value returns `412`. User-facing APIs never hard-delete records.
- Collection pagination uses an opaque `cursor` and `limit` (1-100). A next cursor is returned in `Link` with `rel="next"`; clients must not construct or decode cursors.
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
| 1.7.0 | 2026-07-18 | Publish the remaining W1.2 capability boundaries and import row v1 schemas |
| 1.6.0 | 2026-07-18 | Publish governed ImportJob lifecycle event contracts |
| 1.5.0 | 2026-07-18 | Publish organization unit tree and position lifecycle contracts |
| 1.4.0 | 2026-07-18 | Publish optimistic cluster/facility update and facility archive contracts |
| 1.3.0 | 2026-07-18 | Publish ClusterCreated and FacilityCreated contracts for the first Organization slice |
| 1.2.0 | 2026-07-18 | Freeze W1.2 Organization, Identity, import, bootstrap, and audit boundaries |
| 1.1.0 | 2026-07-17 | Define shared HTTP, event, and compatibility rules |
