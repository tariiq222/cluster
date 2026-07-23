---
doc_id: DOM-NSR-001
title: Notifications, Search, and Reporting
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Owner of the Notifications, Search, and Reporting modules
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: On every change
sources:
- docs/adr/014-authorized-search.md
- docs/adr/015-authorized-reporting.md
- docs/adr/017-derived-workspace-and-notifications.md
- docs/adr/007-transactional-outbox.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
- docs/data-security/authorization-model.md
---
# Notifications, Search, and Reporting

## Purpose and Scope

These are separate derived modules that share a single rule: they do not own the source of truth, do not write to it, and do not grant access to it. `Notifications` creates in-app notifications only. `Search` indexes permitted fields and texts. `Reporting` owns report and dashboard definitions, read models, and governed exports. All three consume the Outbox or Projection Feeds, and re-authorize at display, open, or export.

Out of scope: email, SMS, and WhatsApp; domain transition execution; indicator or metric definition; and any alternative source of truth.

## Entities and Tables

| Module and Tables | Owned Facts | Constraints |
|---|---|---|
| Notifications: `notifications`, `notification_inbox`, `notification_recipients`, `notification_dead_letters` | Recipient, type, `source_ref`, read state, aggregation, deduplication, dead-letter failures | Unique `(event_id, recipient_user_id)`; no sensitive payload or source title |
| Search: `search_index_entries`, `search_checkpoints`, `search_inbox` | Derived index document, source version, and preliminary filtering facts | Unique `(source_type, source_id, projection_version)`; no storage of non-indexable fields |
| Reporting: `report_definitions`, `dashboard_definitions`, `report_read_models`, `report_runs`, `export_artifacts`, `report_inbox` | Template, projection, refresh state, report run, and export batch | Indexed by scope and time; artifact is temporary and governed by retention |

Every source reference is `{source_module, source_type, source_id, source_version}`. There are no direct joins on producer tables.

## Commands, Queries, and Events

**Notifications HTTP features:** `ListMyNotifications`, `MarkNotificationRead`. **Notification workers:** `ConsumeTechnicalAlert`, `ConsumeWorkRecordSubmitted`. **Events:** `NotificationCreated`, `NotificationRead`.

**Search workers:** derived-event consumer that builds `search_index_entries` from authorized projection feeds. **Events:** `SearchIndexUpdated`, `SearchIndexFailed`.

**Reporting workers:** derived-event consumer that builds `report_read_models` and refreshes dashboards. **Events:** `ReportDefinitionPublished`, `ReportingProjectionRefreshed`, `ReportExportCreated`.

## States

```text
Notification: Unread -> Read
IndexEntry: Pending -> Indexed | Suppressed | Failed
ReportRun: Queued -> Running -> Completed | Failed | Expired
ExportArtifact: Available -> Expired -> Disposed
```

## Invariants

- A notification is always a safe link and a public summary only; opening it calls the owner's endpoint to re-authorize, and never reveals the source when access is denied.
- Search pre-filters using derived facts and then re-runs `DecideAccess` and `ResolveFieldAccess` for every result before any title, excerpt, or counter is shown.
- Reports, dashboards, and exports re-authorize at the record and field level at execution time; a stored read model or decision is not sufficient.
- `Hidden` never appears in results, suggestions, aggregations, or exports. An authorized aggregation never reveals its underlying details.
- Consumers are at-least-once and idempotent, and persist a checkpoint or inbox; rebuilding from events or a feed yields an equivalent result.
- A projection failure never mutates or rolls back the source transaction; it surfaces freshness and enters a reviewable retry/dead-letter path.
- Retention removes the export artifact after the policy period and keeps the audit trail without the file.

## Security and Failure

Every display or export uses `Authorization` with the current `AuthorizationRecordFacts` provided by the source; Search and Reporting never build a local decision. Failure in Authorization, the source, or re-authorization is a closed failure: no title, no excerpt, no report row, and no artifact. The audit log records sensitive searches, sensitive report views, and every export with fields and source identifiers. Notifications are in-app only; there is no external channel that can fail or bypass classification.

## Tests

- A user who lost scope after indexing or report build must not see a result, row, or artifact.
- A `Hidden` field must not appear in Search, dashboards, or CSV/PDF, and an aggregated result must not expose its detail.
- A notification for a record that is no longer permitted displays the public text and blocks opening without leaking.
- Replaying a producer event or rebuilding must not duplicate a notification, an index row, or a report row.
- A producer, Authorization, or consumer outage must produce deny or retry without mutating the source, and a sensitive-export audit failure must block delivery.

## Dependencies

Notifications depends on Identity and Authorization; Search depends on Authorization; Reporting depends on Organization and Authorization. All three consume the event contracts of WorkRecords, Workflow, Tasks, Collaboration, Documents, Strategy, PortfolioProjects, Risk, and RecordsGovernance as declared by each module. They do not depend on any synchronous business transaction.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Owner of the Notifications, Search, and Reporting modules | Initial accepted specification. |
