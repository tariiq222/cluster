# Task Core Scope Reset

**Status:** Approved in conversation on 2026-08-13

## Product boundary

Cluster is a scoped institutional task system for a healthcare cluster. The product keeps:

- `Organization`: cluster, facilities, units, people, assignments, and supervisory facts.
- `Identity`: accounts, authentication, sessions, credentials, and MFA.
- `Authorization`: roles, capabilities, organization scopes, denials, and delegations.
- `Tasks`: independent tasks, assignment, participants, comments, attachments, and lifecycle.
- `Documents`: task attachments and independently governed documents.
- `Notifications`: task and platform notifications.
- `Audit`: security and administrative evidence.
- `PlatformSettings`: platform configuration and operations.
- `Search` and `Reporting`: retained only for task and retained-core resources.

## Retired product surface

Remove the complete generic work-management subsystem:

- `WorkRecords`
- `WorkDefinitions`
- `Workflow`
- approval inbox and workflow screens
- `work_management` feature flag and `work_management.history.read`
- `work_record.*`, `work_definition.*`, and `workflow.*` capabilities
- work-record event consumers, search backfill, reporting defaults, document special cases, routes, schemas, and generated clients

## Task independence rules

- A task is created directly by a user; no workflow step may create or advance a task.
- Task persistence has no `workflow_step_id`, `source_module`, `source_type`, or `source_id` columns.
- Task API requests and responses expose no workflow or generic source reference.
- Task authorization remains based on actor, capability, organization scope, classification, and task facts.
- Task attachments, participants, comments, notifications, audit evidence, and lifecycle remain supported.

## Data retirement

- New installations never create work-management tables.
- Existing installations receive an explicit retirement migration that removes work-management tables in dependency order.
- Deployment of the retirement migration requires a database backup; this repository change does not apply it to production.
- Shared `outbox_events` remains because retained modules use it.

## API and UI rules

- Removed endpoints are absent from Laravel routes and authoritative OpenAPI, not feature-gated.
- Removed UI routes and sidebar entries do not exist.
- `/me` exposes only the retained `tasks` feature projection; no `work_management` field remains.
- The standalone product HTML explains organization-scoped tasks and permissions only.

## Acceptance

- Architecture guards prove the three retired module directories, providers, migration registrations, capabilities, and route surfaces are absent.
- Task API tests prove direct create, assign, lifecycle, comments, participants, attachments, notifications, and organization-scope isolation still work.
- OpenAPI validation and Orval generation are reproducible.
- Full API, web, architecture, lint, static analysis, intake, and documentation gates pass.
