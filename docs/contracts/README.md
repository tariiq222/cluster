---
doc_id: CON-IDX-001
title: Contract Index
type: contracts
status: accepted
version: 2.0.0
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
# Platform Contracts

These versioned contracts define the platform boundary for HTTP clients and asynchronous consumers.

| File | Purpose |
|---|---|
| [api/openapi.yaml](api/openapi.yaml) | REST API, OpenAPI 3.1 |
| [api/w1-2.openapi.yaml](api/w1-2.openapi.yaml) | Frozen snapshot of W1.2 contracts prior to implementation |
| [api/r1-screens.openapi.yaml](api/r1-screens.openapi.yaml) | Executable R1 surface from which Orval generates all screen clients |
| [api/notifications.md](api/notifications.md) | Governed user notification list endpoint index |
| [events/asyncapi.yaml](events/asyncapi.yaml) | Domain event transport, AsyncAPI 3.1 |
| `schemas/` | JSON Schema Draft 2020-12 resources |
| [module-contracts.md](module-contracts.md) | Ownership, compatibility, and delivery rules |
| [capabilities/identity-credentials-and-sessions.md](capabilities/identity-credentials-and-sessions.md) | Credentials, activation, and opaque session |
| [capabilities/document-signed-direct-upload.md](capabilities/document-signed-direct-upload.md) | Signed direct upload to the Documents quarantine |
| [capabilities/organization-import-rows-v1.md](capabilities/organization-import-rows-v1.md) | facilities, units, and positions import row v1 schemas |
| [capabilities/temporary-assignment.md](capabilities/temporary-assignment.md) | Unit-scoped, capability- and time-bounded temporary assignment |

All identifiers are RFC 9562 UUID version 7 strings matching the pattern `xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx` in lowercase hex, and timestamps are RFC 3339 UTC ending exclusively with `Z`. Classification is one of `public`, `internal`, `confidential`, or `top_secret`; consumers preserve classification and apply the handling policy. API representations return an `ETag` value, and modifying an existing resource requires a matching `If-Match`. Retryable commands require `Idempotency-Key`.

HTTP errors use the RFC 7807 `application/problem+json` format. Every request requires `X-Correlation-ID` in UUIDv7 form, and every response returns it; every CloudEvent requires the `correlationid` extension with the same value. Events are transported via Redis Streams with consumer groups and at-least-once delivery. A contract change requires a backward-compatible additive release or a new endpoint, channel, and schema with an explicit version.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 2.0.0 | 2026-07-18 | Software Engineering Office | Publish the remaining W1.2 capability contracts with implementation status update |
| 1.8.0 | 2026-07-18 | Software Engineering Office | Publish revised ImportJob representations and governed import lifecycle events |
| 1.7.0 | 2026-07-18 | Software Engineering Office | Publish Identity account representations and dependent-free, PII-free lifecycle events |
| 1.6.0 | 2026-07-18 | Software Engineering Office | Publish PII- and name-free Person representations and the minimized registration/update events |
| 1.5.0 | 2026-07-18 | Software Engineering Office | Publish organization unit tree, position, and their lifecycle contracts |
| 1.4.0 | 2026-07-18 | Software Engineering Office | Publish optimistic cluster update and facility update/archive contracts |
| 1.3.0 | 2026-07-18 | Software Engineering Office | Publish cluster create and facility create event contracts |
| 1.2.0 | 2026-07-18 | Software Engineering Office | Freeze W1.2 snapshot and event contracts |
| 1.0.0 | 2026-07-15 | Software Engineering Office | Initial creation |
| 1.1.0 | 2026-07-16 | Software Engineering Office | Add the user notification list endpoint contract index |
