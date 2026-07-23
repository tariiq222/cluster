---
doc_id: CON-CAP-ORG-001
title: Capability- and Time-Bounded Temporary Assignment Contract
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: Software Engineering Office
reviewers:
- Organization Module Owner
- Authorization Module Owner
classification: internal
review_cycle: on every change
sources:
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/domain/organization-and-people.md
- docs/data-security/logical-data-model.md
references:
- docs/contracts/api/openapi.yaml
- docs/contracts/api/w1-2.openapi.yaml
---
# Capability- and Time-Bounded Temporary Assignment Contract

## Status and Outcome

**Implementation status:** `implemented` (Phase B + D update)

This contract defines the target behavior for TemporaryAssignment; assigning a primary position does not implement this separate capability.

Organization creates a temporary authority fact for a person inside one OrganizationUnit and presents it to Authorization. Organization does not issue an Allow decision, and the assignment does not become a Role or a permanent capability.

## HTTP Surface

| Operation | Path | Controls |
|---|---|---|
| Governed list | `GET /api/v1/organization/temporary-assignments` | Pagination and filtering within the Authorization decision scope |
| Create | `POST /api/v1/organization/temporary-assignments` | `Idempotency-Key` and a backend capability decision |
| Read | `GET /api/v1/organization/temporary-assignments/{temporaryAssignmentId}` | Safe 403/404 outside scope |
| Early revoke | `POST /api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke` | `If-Match`, `Idempotency-Key`, and mandatory reason |

These paths are live and registered in `apps/api/routes/web.php`.

## Create Request and Representation

Required fields for creation:

- `person_id`: a valid, non-archived Person.
- `organization_unit_id`: an existing, non-archived OrganizationUnit; this is the only scope.
- `capability_codes`: a non-empty, unique list of capabilities published in Authorization.
- `start_at` and `end_at`: RFC 3339 UTC ending with `Z`.
- `reason`: a non-empty text explaining the administrative need.

The response returns `id` and the fields above, plus `status`, `approved_by_user_id`, `revoked_at`, `revoke_reason`, and `lock_version`. It does not return Person PII, an access decision, a derived role, or a derived field policy.

The API status `scheduled` maps to the persisted lifecycle state `pending` produced by the event factory. The HTTP representation exposes `scheduled` to clients for compatibility, while the database row and the emitted event both carry `pending`. The event schema (`temporary-assignment-event`) is the source of truth for the runtime state name; API translation is stable and does not affect Authorization or audit.

## Invariants

1. **Unit-only scope:** every capability is bound to the same `organization_unit_id` only. It does not span descendants, parent, Facility, or Cluster, and v1 does not accept scope tags, wildcards, or more than one unit.
2. **Mandatory reason:** creation or early revoke is rejected if the reason is empty after trim, and the reason, actor, and correlation are recorded in audit.
3. **No backdating:** No backdating; the server compares `start_at` against its own clock when accepting the command, and it must not precede it. The client cannot create a fact that was effective in the past.
4. **Duration limit:** `end_at` must be greater than `start_at` and the duration must not exceed 90 days. `end_at` is exclusive, and the period is half-open `[start_at, end_at)`.
5. **Overlap prevention:** for each key `(person_id, organization_unit_id, capability_code)`, no `scheduled`/`pending` or `active` period may overlap another non-revoked period. Touching `end_at` against the next `start_at` is allowed because it is not an overlap.
6. **Explicit capabilities:** no capability is inferred from a position, type, or parent. Each code is verified through the Authorization contract, and Organization stores a snapshot of the granted codes as time-bound facts.
7. **Immediate revoke:** at `end_at` or revoke, the capability is no longer among the effective facts in the next request, and the affected cache or administrative session is invalidated per Authorization and Identity policy without deleting history.
8. **Atomic and replayable:** creation or revoke, status change, and Outbox are written in a single Organization transaction; replay does not duplicate the assignment or the event.

## Reconciliation with the General Model

`docs/data-security/logical-data-model.md` describes a broader model that includes `position_id`, `authority_scope_tags`, and `authority_profile_key`. This contract narrows v1 to that model: no `position_id`, no tags, no profile — a single OrganizationUnit and explicit capabilities. Expanding scope or linking to a position requires a new contract release and Authorization review, and is not inferred from the older conceptual model.

## Failure Cases

- An unresolvable unit, Person, or capability: safe failure with no partial record.
- Start in the past or invalid duration: validation error, no rounding or quiet date repair.
- Capability overlap: `409` with a fixed code without revealing an assignment outside the actor's scope.
- Stale `If-Match` on revoke: `412` and the record is unchanged.
- Outbox or Authorization fact source failure: rollback or deny, and no optimistic grant.

## Acceptance Criteria

- A capability on a unit does not apply to a parent, child, or sibling unit.
- Empty `reason`, start in the past, and duration greater than 90 days are rejected.
- Same-capability overlap for the same key is rejected, while two touching periods or two different capabilities are allowed.
- Expiration and revoke withdraw the effect immediately while keeping the history record.
- Every API, search, report, and export uses the same Authorization decision and does not read the assignment table directly from another module.
