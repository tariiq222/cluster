---
doc_id: DOM-PLS-001
title: Platform settings and operational calendar
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: PlatformSettings module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/architecture/dependency-rules.md
references:
- docs/architecture/module-catalog.md
- docs/architecture/context-map.md
---
# Platform settings and operational calendar

## Purpose and scope

`PlatformSettings` owns the platform-wide settings that no other domain owns, their published versions, and the operational calendar. It covers language, locale, and timezone; session ceilings above the static security floor; operational limits; and working days, holidays, working hours, and due-date calculation rules. It does not own work-type, workflow, project, or indicator settings.

## Entities, tables, and constraints

| Table | Reality | Constraints |
|---|---|---|
| `platform_setting_versions` | A platform-wide settings version with its status and content hash | One published row at a time, enforced by a nullable generated unique `published_slot`; a published version is immutable |
| `platform_settings` | A typed key and value within a settings version | Unique `(platform_setting_version_id, setting_key)`; allow-list for keys and types |
| `business_calendars` | A calendar scoped to a cluster or facility and its policy | Indexed on `(scope_type, scope_id, status)`; no database-level single-active-calendar-per-scope invariant; the handler treats `status = 'published'` as the active row |
| `business_calendar_weekdays` | The working/non-working flag and hours for a weekday within a calendar | Unique `(business_calendar_id, weekday)`; `weekday` is `0`–`6` |
| `business_calendar_exceptions` | A date-based working day, holiday, or override window with optional hours and reason | Indexed on `(business_calendar_id, starts_on, ends_on)` |

> **Drift correction:** The previous revision described a single `business_calendar_days` table with `calendar_id` and `calendar_date`. The actual schema (`CreatePlatformSettingsTables.php:50-77`) splits weekday rules into `business_calendar_weekdays` and date-based overrides into `business_calendar_exceptions`. The previous claim that the schema enforces "one active calendar per scope and time" is also dropped — the migration does not add a partial unique index on `(scope_type, scope_id, status)`; only the read path filters by `status = 'published'`.

## Commands, queries, events, and states

**Commands (exposed by feature handlers):**

- `PlatformSettingsHandler`: `CreateSettingsVersion`, `SetSettingsValue`, `ValidateSettingsVersion`, `PublishSettingsVersion`.
- `BusinessCalendarHandler`: `SetBusinessCalendarWeekday`, `SetBusinessCalendarException`.

> **Drift correction:** The previous revision listed commands `CreatePlatformSettingsDraft`, `SetPlatformSetting`, `CreateBusinessCalendar`, and `SetBusinessCalendarDay`. Only the four handler methods above are wired to HTTP controllers and feature entry points. `CreateBusinessCalendar` does not exist as a discrete command — calendars are created as side effects of `SetBusinessCalendarWeekday` / `SetBusinessCalendarException`.

**Queries:**

- `ResolveBusinessCalendar` — resolves the effective working window for a `(scope_type, scope_id, date)`.
- `GetEffectivePlatformSettings` — returns the currently published settings document.

> **Drift correction:** The previous revision listed `CalculateBusinessDueAt` as a query contract. There is no such contract in `apps/api/Modules/PlatformSettings/Contracts/`; due-date calculation lives inside `ResolveBusinessCalendar` / `BusinessCalendarHandler::forDate`.

**Events:** `PlatformSettingsVersionPublished`, `BusinessCalendarExceptionChanged`, `BusinessCalendarWeekdayChanged`.

```text
SettingsVersion: Draft -> Validated -> Published -> Retired
BusinessCalendar: status is a free string(16); the handler treats only 'published' as active.
```

> **Drift correction:** The previous revision stated a `BusinessCalendar: Draft -> Published -> Superseded` state machine. The migration accepts any `string(16)` value and the handler enforces only `'published'` for the read path (`DatabaseBusinessCalendars.php:165-169`). No `'superseded'` value is generated anywhere in the code.

## Constants, security, and failure modes

- A published setting never lowers the static security floor for passwords or sessions.
- Every operational timestamp is computed from the record's scope calendar in `Asia/Riyadh`; stored timestamps are UTC.
- Publishing a calendar does not silently change a fixed appointment or an in-flight SLA. Recalculation happens only via an explicit owner command and policy.
- Publication and modification are centralized administrative capabilities; their audit logging is mandatory. An admin role does not grant work approval.
- An invalid key, type, or calendar, or publishing without validation, is rejected. An outbox failure rolls back publication; every other consumer only reads the last published version.

## Tests and dependencies

- Test typed keys, the non-degradation of the security floor, and the immutability of a published version.
- Test language, locale, timezone, holidays, working hours, and due-date calculation crossing a weekend.
- Test that changing a calendar does not alter a fixed appointment, and outbox idempotency.

The module has no domain dependencies. `Identity`, `Authorization`, `WorkDefinitions`, and `RecordsGovernance` depend on it; the remaining modules consume only the calendar contract.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | PlatformSettings module owner | Initial accepted specification |
| 1.0.1 | 2026-07-23 | Domain audit pass | Calendar table names aligned to `business_calendar_weekdays` + `business_calendar_exceptions`; commands/queries list aligned to `PlatformSettingsHandler` and `BusinessCalendarHandler`; `Superseded` state removed |
