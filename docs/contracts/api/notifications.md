---
doc_id: CON-API-001
title: Notifications API Contract
type: contracts
status: accepted
version: 1.1.0
date: 2026-07-16
owner: Software Engineering Office
reviewers:
- Platform Engineering Office
- Information Security Officer
classification: internal
review_cycle: on every change
sources:
- docs/contracts/api/openapi.yaml
references:
- docs/architecture/module-catalog.md
- docs/contracts/schemas/problem-details.schema.json
---
# Notifications API Contract

## Contractual Authority

The `GET /api/v1/notifications` path represents the `listMyNotifications` operation for the authenticated user. The `POST /api/v1/notifications/{notificationId}/read` path marks a single notification as read for the authenticated user and is part of the live API surface. [OpenAPI](openapi.yaml) remains the sole legal authority for the request and response shape; this page is a governed index and does not redefine the schema.

## Ownership and Privacy Boundary

- The module returns `Notifications` projections owned for the recipient derived exclusively from the authenticated Bearer identity.
- The record reference grants no permission to open the source; the owning endpoint re-evaluates the access decision when navigating to the record.
- Each notification carries the live source facts persisted by the storage layer: `source_record_id` (the typed reference to the originating record), the owner facility identifier, and the classification facts. The earlier `SourceReference` shape (`source_module`, `record_type`, `record_id`) is not the live storage representation.
- The response does not carry WorkRecords payload, facility, access context, decision reasons, or authorization trace.
- Errors use `application/problem+json` and the shared RFC 7807 schema, and every response returns the `X-Correlation-ID` required on the request.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | Software Engineering Office | Replace the single source identifier with the typed source reference aligned to the shared contract |
| 1.0.0 | 2026-07-16 | Software Engineering Office | Create the user notification list endpoint contract index |
