---
doc_id: CON-MC-001
title: عقود الموديولات
type: contracts
status: accepted
version: 1.1.0
date: 2026-07-17
owner: مسؤول هندسة البرمجيات
reviewers:
- مكتب هندسة المنصة
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources: []
references: []
---
# Module Contract Rules

## Ownership

| Module | Owns | Publishes |
|---|---|---|
| Identity | sessions and current principal | authenticated access context |
| Authorization | access decisions | `AccessDecision` |
| Work Definitions | immutable published work-type versions | definition reads |
| Work Records | record envelope, facts, payload and submission | `WorkRecordSubmitted` |
| Workflow | instances, active steps and immutable decisions | `WorkflowStepActivated`, `WorkflowDecisionRecorded` |
| Documents | document bytes, scan result and metadata | `DocumentScanCompleted` |

No consumer writes another module's persistence. Consumers use the HTTP contract for synchronous reads/commands and events only for derived state or reactions.

## HTTP Rules

- Base path: `/api/v1`; JSON media type: `application/json`.
- `X-Correlation-ID` is required on every request and returned on every response. It is a lowercase RFC 9562 UUIDv7 matching `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx`.
- A create, submit, decision, upload-finalize, or export request requires `Idempotency-Key` (1-255 visible ASCII characters). Replays with the same key and different request semantics return `409`.
- `ETag` is returned on mutable representations. `PATCH`, cancel/archive actions, submit, and workflow decisions require `If-Match`; a stale value returns `412`. User-facing APIs never hard-delete records.
- Collection pagination uses opaque `cursor` and `limit` (1-100). A next cursor is returned in `Link` with `rel="next"`; clients must not construct or decode cursors.
- Responses are filtered by authorization and field policy before serialization. `confidential` and `top_secret` reads, downloads, exports, and decisions are audit events; search never discloses `top_secret` and must not index restricted document content.

## Event Rules

- Event messages are CloudEvents JSON with `specversion: "1.0"`, UUIDv7 `id` and required `correlationid`, and UTC `time` ending in `Z`.
- The transport is Valkey-compatible Streams with consumer groups and explicit acknowledgement; Kafka topics are not part of this contract.
- Producers persist the business mutation and Outbox row atomically. The relay delivers at least once.
- Consumers persist Inbox receipt keyed by CloudEvent `id` before side effects; duplicate deliveries acknowledge without repeating effects.
- Invalid or exhausted messages go to the DLQ with the original CloudEvent, failure code, attempt count, and failure timestamp. They are not silently discarded.
- DLQ publication is idempotent by source stream message ID. The DLQ stream and its `:source-message-index` sidecar share one retention and purge lifecycle and must be removed together only after preserving review evidence.
- `data.classification` and `data.access_context` are mandatory. Consumers may reduce exposure but may never lower classification.

## Compatibility

Schemas use JSON Schema Draft 2020-12 with `additionalProperties: false` unless an explicit free-form payload is required. Additive optional fields are compatible. Removing, renaming, changing type, tightening validation, changing event meaning, or reusing a field requires a new major contract version.
