---
doc_id: PLN-R1-W13-FE-001
title: W1.3 Closure Plan — Central Authorization and Access Experience
type: plans
status: accepted
version: 3.0.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: on every change to the access decision
sources:
- docs/plans/release-1-platform.md
- docs/plans/active-delivery-status.md
- docs/domain/authorization.md
- docs/data-security/authorization-model.md
references:
- docs/adr/004-authorization-and-isolation.md
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/architecture/module-catalog.md
- docs/contracts/api/openapi.yaml
---
# W1.3 Closure Plan — Central Authorization and Access Experience

## Current Verdict

W1.3 and R1 journeys are green functionally, but W1.3 is not closed as a fully
central access decision. The application binds the `DecideAccess` contract to
the `FixtureFacilityDecision` engine, while the `RbacAbacDecideAccess` engine
that reads roles, assignments, delegations, and explicit deny is not bound to
the runtime path. Existing tests therefore prove a fixture journey and facility
isolation, and do not prove that a role or delegation change in Authorization
administration changes the real user's access.

The resulting implementation gaps are:

- The production session parser is not used by all Authorization controllers.
- There is no management contract for `role_capabilities` or `explicit_denies`.
- `role_assignments` does not distinguish `scope_type`, and does not apply
  Cluster/Facility/Unit inheritance.
- Delegation creation does not prove that the delegator has the capability and
  scope they are delegating.
- `effect=deny` in `role_capabilities` does not enter the decision.
- `classification_policies` and `field_access_templates` are stored but do not
  drive the decision or field filtering.
- The static capability catalog does not cover every R1 action or the upcoming
  R2 and R3 surfaces.
- Resource responses do not present a unified contract for `allowed_actions`
  and `field_access` that the frontend can consume without rebuilding the policy
  in React.

Accordingly, R1 stays complete as a local product journey, and W1.3 is reopened
as a security integration gap before merging R2. R1 frontends are not rebuilt
and modules are not rewritten; the requirement is to replace the fixture
decision with a real runtime contract and pass its result through the existing
consumers.

## Required Outcome

The single access path becomes:

```text
Actual Identity session
  -> Account state, actor identity, and originator identity
  -> Scope, assignment, and relationship facts from Organization
  -> Role, capabilities, assignment, delegation, and deny from Authorization
  -> Trusted RecordFacts built by the owning module
  -> Resource and classification decision
  -> FieldAccess + allowed_actions
  -> Filtered API response then adaptive frontend
```

Laravel reapplies the decision on every sensitive read or write. React uses the
result to improve the experience only; hiding a button or a route in the browser
is not protection and is not a source of the decision.

## Contracts Established Before Implementation

### PrincipalContext

Built by Identity from an unrestricted session. Contains only `user_id`, account
state, originator identity under delegation, and active organizational scope
identifiers. The controller does not accept user facts or roles sent by the
browser.

### AuthorizationScope

Represents `cluster`, `facility`, `unit`, or `record_set` with `scope_id` and an
explicit inheritance rule. `null` is not an ambiguous shortcut for blanket
authority; any blanket scope is represented by a known type.

### RecordFacts

Built by the record owner from its database. Includes the resource identifier,
its owner, originating facility, unit, state, classification, facts version, and
`field_policy_key` when needed. The record payload is not sent to Authorization
and the browser is not allowed to decide these facts.

### AccessProjection

Product endpoints return, alongside the filtered data:

```json
{
  "decision_id": "uuidv7",
  "allowed_actions": ["view", "return"],
  "field_access": {
    "title": "editable",
    "budget": "masked",
    "private_notes": "hidden"
  }
}
```

The response exposes no name, count, or title of a forbidden resource. Lists,
search, and reports apply ScopePredicate before pagination, then reapply the
decision for the record and fields when needed.

## Module Impact

| Module | What Changes | What Does Not Change | Closure Evidence |
|---|---|---|---|
| `Identity` | Unify the actual session parser, verify an active account and unrestricted session, and invalidate affected sessions on security changes that require it | Passwords and sessions stay owned by Identity | A locked account or revoked session cannot reach any protected resource |
| `Organization` | Provide active assignment, scope, and relationship facts via contracts, and resolve Cluster/Facility/Unit inheritance | The organization tree and its tables do not move to Authorization | A user move or assignment expiration changes the decision without role changes |
| `Authorization` | Bind the real engine, complete management of RoleCapability, ExplicitDeny, scopes, delegations, and policies, and emit an explainable AccessProjection | Does not read tables of business modules | Role, deny, or delegation changes immediately reflect in the real API |
| `Audit` | Consume access change events and log sensitive decisions append-only | Does not become a decision engine | Granting, denying, or delegating and sensitive access are traceable without record mutation |
| `PlatformSettings` | Supply published classification and delegable-capability setting versions when needed | Does not store user roles | Every decision references a published `policy_version` |
| `WorkDefinitions` | Verify at publish time that the work type has a published `field_policy_key` | Schema and versions stay owned by the module | A work type with an unknown field policy cannot be published |
| `Workflow` | Use capabilities to resolve the actor and review/approve/return actions, and reapply the decision at each transition | Path definition and its state do not move to Authorization | The user sees and executes only their allowed transitions |
| `WorkRecords` | Extend RecordFacts, filter lists before pagination, and return allowed_actions and field_access | Record payload and lifecycle stay owned by the module | Title, fields, and actions outside the scope are denied |
| `Tasks` | Inspect assignment, read, update, and completion by owner, participant, and scope | Responsibility, mentions, and task lifecycle stay owned by Tasks | No task or its resource appears to an unauthorized user |
| `Documents` | Reapply the decision for the document and the linked resource on link and download, and apply classification and sensitive auditing | Storage, scanning, and versions stay owned by Documents | A short-lived download link is issued only after both decisions |
| `Search` | Build the index without raw sensitive fields, apply ScopePredicate, and do not expose the title of a forbidden result | The index is derived and rebuildable | The same user gets the same limits as WorkRecords |
| `Reporting` | Apply the same decision to report, export, download, and board | Calculations and read models stay owned by Reporting | Export does not widen the read scope or expose extra rows |
| `Notifications` | Filter the notification and its link at display if the recipient lost access to the resource | Delivery and dedup stay as they are | Does not expose a notification title for a resource that is no longer allowed |
| `Workspace` | Derive inboxes and counters only from allowed resources | Does not become a source of permissions | Counter and list agree and do not reveal forbidden items |
| `RecordsGovernance` and `Collaboration` | Add RecordFacts and reservation/comment capabilities when their slices open | No prior work outside the used path | Tests for each slice when enabled |
| `Strategy` | Define capabilities and RecordFacts for plan, objective, and indicator before R2 implementation | Authorization does not depend on Strategy tables | The R2 journey passes through the real engine from the first merge |
| `PortfolioProjects` | Define project, portfolio, budget, and impact capabilities and their scopes before R2 implementation | Linkage with Strategy stays IDs and contracts | Project, impact, and export isolation within the same scope |
| `Risk` | Define risk, control, treatment, acceptance, and KRI capabilities before R3 implementation | Risk evaluation stays owned by Risk | High/Critical, accept, and treatment use the central decision |

## What Must Appear to the End User

### Every User

- Current work scope, its active roles, and their expiration dates.
- Received and issued delegations without technical codes.
- Allowed actions on the current resource only.
- Fields hidden, masked, readonly, or editable per the server decision.
- A human-readable reason for the denial with a `decision_id` usable for support
  without exposing the resource.

### Manager Within Scope

- Scope members and their active and temporary roles.
- Delegation creation from owned capabilities only, with scope and duration not
  exceeding them.
- Pre-save warnings on duplication, conflict, or near expiration of the source
  assignment.

### Access Administrator

- A role catalog showing permissions, users, scopes, and state.
- A Role × Capability matrix editor with sensitivity and Allow/Deny.
- An assignment wizard: user, then role, then scope type, then scope, then
  duration, then impact review.
- Management of delegations, explicit deny, supervisory relationships, and
  classification and field policies.
- A decision simulator built on trusted server facts, not on browser-supplied
  facts.

### Security Auditor

- A log of role, assignment, delegation, and deny changes.
- Sensitive decisions with reasons, policy versions, and correlation IDs.
- Constrained log search without exposing payload or unnecessary PII.

## Implementation Waves and Ownership Boundaries

Code implementation does not start before these boundaries are adopted in the
plan. When starting, the waves are:

1. **Contracts and Data:** `PrincipalContext`, `AuthorizationScope`,
   `RecordFacts`, and `AccessProjection`, then a safe migration for
   `scope_type` and delegation constraints.
2. **Operational Cutover:** Bind the SessionPrincipalResolver and the real
   engine, and keep the fixture only in `local/testing` through an explicit
   binding that cannot run in production.
3. **Policy Management:** RoleCapability, ExplicitDeny, Delegation validation,
   and classification and field policies with idempotency, optimistic locking,
   and Outbox.
4. **R1 Consumers:** Organization and Identity, then WorkRecords/Tasks/Workflow,
   then Documents/Search/Reporting/Notifications/Workspace, each group with a
   targeted isolation test.
5. **Frontend Drop:** Endpoints for personal context, allowed_actions, and
   field_access, then the employee, manager, administration, and audit screens
   without a local decision in React.
6. **R1 Security Closure:** A two-user two-facility journey that proves role
   granting, its application, its expiration, explicit deny, delegation, field
   masking, and search, report, and download matching.

R2 is not merged before wave six. Its capability contracts and RecordFacts can
be prepared in parallel, but no bypass or new fixture engine is added to speed
up the slice.

## Acceptance Gates

- Boot fails in production if `FixtureFacilityDecision` or the development
  bearer parser is bound to a user path.
- Every capability used in a route or command is in the canonical catalog and
  seeded idempotently.
- A delegator cannot grant a capability, scope, or duration higher than they
  own.
- Explicit deny, RoleCapability deny, and classification override allow.
- Assignment, role, or delegation expiration changes the decision at the same
  reference hour.
- Lists, search, reports, exports, and downloads return the same resource
  limits.
- A hidden field never reaches JSON, a masked field never appears raw, and a
  readonly field is rejected on write.
- The targeted API tests, `make verify-boundaries`, the Web build, and the
  security E2E journey are green on a single revision, then the current R1
  gates are rerun to prevent regression.

## Out of Scope

- Redesigning the frontend before fixing the AccessProjection contracts.
- Moving Organization or Identity data into Authorization tables.
- Creating a standalone permissions service outside the modular monolith.
- Detailed R2/R3 policies before their modules are implemented; only
  namespaces and contracts are required now.
- Any reliance on role names or React hiding as protection.

## Change Log

| Version | Date | Change |
|---|---|---|
| 3.0.0 | 2026-07-19 | Reopen W1.3 as a security integration gap, and document the real engine cutover's impact on modules, user outputs, and pre-R2 gates |
| 2.0.0 | 2026-07-19 | Convert the proposed contract into a W1.3 execution card for Day 1, pin W1.2 completion, and begin work in `work-1-3*` |
| 1.0.0 | 2026-07-18 | Initial frontend slice contract |
