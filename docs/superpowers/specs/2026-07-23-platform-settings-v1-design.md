---
doc_id: DSN-PLS-001
title: Platform Settings and Operational Control Center V1 Design
type: plans
status: accepted
version: 1.0.0
date: 2026-07-23
owner: Platform Engineering Office
reviewers:
- Product Lead
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: With every change in platform-settings scope or operational contracts
sources:
- docs/architecture/module-catalog.md
- docs/domain/platform-settings.md
- docs/data-security/authorization-model.md
- docs/operations/ha-dr-backup.md
references:
- docs/engineering/delivery-workflow.md
- docs/domain/notifications-search-reporting.md
---

# Platform Settings and Operational Control Center V1 Design

## 1. Goal

The first release builds a single administration area inside the dashboard named
"Platform Settings". It lets an authorized administrator manage general and
security settings, business calendar, backup, technical logs, service health,
and alerts and maintenance mode, without transferring module data ownership or
infrastructure secrets into `PlatformSettings`.

## 2. Adopted Decisions

- Brand identity, name, logo, colors, and core copy are fixed in code.
- Arabic is the default language; `Identity` owns the user's choice between
  Arabic and English, and the interface direction follows the chosen language.
- The operational time zone is fixed to `Asia/Riyadh`, and stored timestamps are UTC.
- Each module owns its own settings and `Authorization` decides who can administer them.
- No Feature Flags, version management, or secrets editor exist in V1.
- General and security settings are a single platform-wide copy.
- The calendar inherits from platform to cluster to facility, storing only differences.
- Each day has one working-hours window, and the Ramadan window enters annually with
  a Gregorian date range and reduced hours.
- A central public holiday is not cancelled at a lower level; local closures are
  allowed, and exceptional work during a central holiday needs an independent
  capability plus a reason and audit.
- Task and approval durations are owned by the source module; `PlatformSettings`
  only exposes calendar facts and does not set any operation duration.
- Logs are never permanently deleted. They stay active 12 months by default, with
  a fixed minimum of 90 days, then move to a permanent, immutable, searchable archive.
- Restore is not a direct execution button; it is created as a justified request
  that needs an independent capability and a second confirmation, while actual
  execution stays in the operational layer.

## 3. V1 Scope

### 3.1 General Settings

- Default language `ar`.
- Fixed operational time zone `Asia/Riyadh`.
- Setting version states `draft -> validated -> published -> retired`.
- One active published version; the published version is not editable.

### 3.2 Security Policies

- Idle timeout before session termination.
- Absolute maximum session age.
- Minimum password length.
- Number of previous passwords forbidden for reuse.
- Number of failed login attempts.
- Attempts counting window.
- Temporary lockout duration.
- Hard safe floors or ceilings are fixed in code; publication does not accept a
  weaker value.

### 3.3 Business Calendar

- Default platform calendar.
- Override at cluster or facility level via inheritance.
- Working days and the start/end of the single daily window.
- Central public holidays and local closures.
- Annual Ramadan window with a Gregorian date range and reduced hours.
- Queries to resolve the effective calendar, check whether a day is a working day,
  and find the next working day.

### 3.4 Backup and Restore

- Show last success, last failure, backup components, and verification state.
- Modify the schedule and retention policy within fixed operational limits.
- Run an immediate backup with idempotency.
- Download a non-sensitive backup report, not the backup files or secrets.
- Show the result of the last restore drill.
- Create a justified restore request and confirm it with a second actor and an
  independent capability.
- Execution runs through `BackupOperationsGateway` and does not run inside a long
  HTTP transaction.

### 3.5 Logs

- Four classifications: audit, security, system, operations.
- In V1 the log source is a typed, redacted mock under `TechnicalLogSource`;
  this source does not claim to be a production audit log. It will be replaced
  later by an `Audit` adapter without changing the API or interface.
- A unified search interface aggregates results across owner contracts, with no
  direct join between module tables.
- Mask passwords, tokens, file content, and sensitive PII fields.
- Migrate old logs to a permanent archive with a manifest and hash for integrity
  verification.
- Restore is asynchronous and justified, with a bounded result lifetime.

### 3.6 Platform Health and Alerts

- Indicators for database, Redis, storage, queues, Outbox, file scanning,
  notifications, backups, and storage usage.
- Do not expose connection secrets or environment values.
- Alerts target roles or capabilities, not fixed user IDs.
- Alert supports severity level, channel, escalation, acknowledgment, and closure.
- `Notifications` owns delivery; the control center owns the technical alert
  policy.

### 3.7 Maintenance Mode

- Schedule start and end, an Arabic and English message, and a mandatory reason.
- Allow authorized operations administrators to sign in.
- Block new operations while safe background operations finish.
- End automatically, with every activation or cancellation logged.

## 4. Backend Ownership and Contracts

`PlatformSettings` owns:

- Setting versions and typed values.
- Calendar, inheritance rules, and exceptions.
- Maintenance and technical alert policies.
- Backup and restore requests and health snapshots as operational references,
  not the backups or secrets themselves.

Owning modules and services:

- `Identity` consumes the published security policy and owns sessions and passwords.
- `Authorization` decides read, manage, operate, and confirm capabilities.
- `Audit` or the current audit source owns immutable audit events.
- `Notifications` sends alerts.
- The operational layer executes backups, restores, and probes through governed
  gateways.

`PlatformSettings` does not read another module's tables or join against them.
Aggregation happens through read-only contracts or in the application layer, and
every result remains tagged with its source.

## 5. Capabilities

- `platform_settings.read`
- `platform_settings.manage`
- `platform_settings.calendar.read`
- `platform_settings.calendar.manage`
- `platform_settings.calendar.override_official_holiday`
- `platform_operations.health.read`
- `platform_operations.logs.read`
- `platform_operations.logs.restore`
- `platform_operations.backup.read`
- `platform_operations.backup.run`
- `platform_operations.backup.manage`
- `platform_operations.restore.request`
- `platform_operations.restore.confirm`
- `platform_operations.alerts.manage`
- `platform_operations.maintenance.manage`

Management and operation actions are sensitive and apply capability, scope,
explicit deny, and authorization time. No single capability grants every
action implicitly.

## 6. Interface Experience

The "Platform Settings" entry appears inside an administrative section of the
sidebar only for users who hold at least one V1 read capability. Routes:

- `/admin/platform` — control center.
- `/admin/platform/security` — language and security policies.
- `/admin/platform/calendars` — working hours, holidays, and Ramadan.
- `/admin/platform/backups` — backups and restore requests.
- `/admin/platform/logs` — search and restore.
- `/admin/platform/health` — service details and alerts.
- `/admin/platform/maintenance` — maintenance mode.

Control center order:

1. An issue or action that needs intervention.
2. Service, backup, alert, and storage indicators.
3. Service health details.
4. Safe quick actions only.
5. Recent operational activity.

The restore button and maintenance activation do not appear as immediate
actions on the opening page. Pages use the unified components from
`apps/web/src/ui/` and cover loading, empty, denied, error, success, and stale
states, with Arabic and English, RTL/LTR, and full keyboard support.

## 7. Data Flow and Failure

1. The interface carries the principal and its capabilities, then requests the
   control center snapshot.
2. The server builds each part from its own source with a bounded contract and
   short wait.
3. A single-source failure returns `partial` with that source's state and does
   not turn the whole page into an unknown error.
4. Every mutation uses CSRF and `Idempotency-Key`, and uses `If-Match` when
   editing a record vulnerable to conflict.
5. Settings changes and the Outbox event save in one transaction.
6. The interface shows the server's final decision and `allowed_actions` and
   does not infer permission.
7. Long-running operations, including backups, restores, and archiving, return
   `202` with an operation ID that can be tracked.

## 8. Acceptance Criteria

- Only authorized users can see routes or call the API.
- Publication does not accept a security policy weaker than the fixed limits.
- `Identity` consumes only the published version and continues on the last valid
  version when the draft or source read fails.
- Calendar inheritance resolves deterministically, and the central holiday
  remains unless a high-privilege, audited exception exists.
- Log, health, and backup responses contain no secret or sensitive content.
- A restore does not run from one user or one request.
- The control center shows a partial state when one service is down, with the
  last update time shown.
- The journey works in Arabic RTL and English LTR at narrow widths.
- API, unit, boundary, build, and the targeted E2E journey tests pass.

## 9. Out of Scope

- Brand identity customization.
- Feature Flags.
- Entering, displaying, or rotating secrets from the interface.
- Application release management.
- Business settings specific to other modules.
- Building a backup engine or new archive store inside Laravel.
- Permanently deleting logs.
