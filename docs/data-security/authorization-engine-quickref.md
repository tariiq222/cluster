---
doc_id: SEC-AUTHZ-002
title: محرك الصلاحيات — المرجع التشغيلي السريع
type: data-security
status: draft
version: 1.0.0
date: 2026-07-21
owner: مسؤول أمن المعلومات
reviewers:
- مكتب هندسة المنصة
classification: internal
review_cycle: مع كل تغيير في محرك القرار
sources:
- docs/data-security/authorization-model.md
- docs/domain/organization-and-people.md
references:
- docs/contracts/capabilities/temporary-assignment.md
- docs/architecture/dependency-rules.md
---

# Authorization Engine — Runtime Quick Reference

Single-page operational summary of how access decisions are produced, denied,
and audited in this platform. For the authoritative model see
[authorization-model.md](./authorization-model.md); for the Organization
domain tree see
[organization-tree-quickref.md](../domain/organization-tree-quickref.md).

## 1. Request Flow

```
React screen → Controller → ResolveDevelopmentFixturePrincipal → principal
                          → RecordFacts(ownerFacilityId, resourceType, classification)
                          → DecideAccess::decide(principal, capability, facts)
                          → isAllowed ? handler + 200 : problem+json 403
```

No authorization decision is ever taken in the frontend. A `403` on any of a
screen's API calls renders that screen's `forbidden` copy; the frontend is a
pure consumer of the engine's verdict.

## 2. Engine Chain

| Layer | Binding | Behaviour |
|---|---|---|
| `DecideAccess` (contract) | `BootstrapGatedDecideAccess` | While `authorization_bootstrap.state='pending'`, denies every capability except `organization.bootstrap`, `identity.bootstrap`, `authorization.bootstrap.complete` with reason `authorization_bootstrap_pending` |
| Inner engine | `RbacAbacDecideAccess` | The real RBAC+ABAC evaluator (`policy_version = rbac-abac-v2`) |
| Tests | `FixtureFacilityDecision` (default in `Tests\TestCase`) | Deterministic fixture engine; `bindRealAccessDecision()` rebinds the real one |

The bootstrap window is closed exactly once via
`POST /api/v1/authorization/bootstrap/complete`, which requires an active
account with `users.is_admin = true`, an `Idempotency-Key`, and a reason; the
completer is recorded in `authorization_bootstrap.completed_by_user_id`.

## 3. Evaluation Order (first failure wins)

| # | Check | Deny reason code |
|---|---|---|
| 1 | `actor.user_id` present | `actor_user_id_missing` |
| 2 | Capability exists in `CapabilityCatalog` | `capability_not_supported` |
| 3 | No active explicit deny covering actor+capability+facts | `explicit_deny` |
| 4 | `facts.classification` is a known `ClassificationLevel` | `classification_insufficient` |
| 5 | No role grant with `effect=deny` | `role_capability_denied` |
| 6 | Active grant via `role_assignments` (status active, within `start_at`/`end_at`) + `role_capabilities` with `effect=allow` | falls through to alternatives |
| 7 | Grant scope covers the facts (`AuthorizationScope::covers`) | `organization_unit_scope_mismatch` |
| 8 | Grant clearance ≥ record classification | `classification_insufficient` |
| 9 | Delegation grants (same scope+clearance logic) | `organization_unit_scope_mismatch` |
| 10 | Supervisory relationships (active window, capability in `relationship_capabilities`, target unit matches `facts.organizationUnitId`) | `supervisory_relationship_scope_mismatch` |
| 11 | Expired variants detected | `role_assignment_expired` / `delegation_expired` / `supervisory_relationship_expired` |
| 12 | Nothing matched | `active_role_assignment_not_found` |

## 4. The Clearance Model

Capability sensitivity maps to a clearance level; the record's classification
sets the floor. Allow requires `clearance ≥ classification`.

| `capabilities.sensitivity` | Clearance conveyed | Covers record classifications |
|---|---|---|
| `normal` | `INTERNAL` | `internal` only |
| `sensitive` | `CONFIDENTIAL` | `internal`, `confidential` |
| `critical` | `TOP_SECRET` | all |

Sensitivity is seeded by `AuthorizationCatalogSeeder`:

- action in `SENSITIVE_ACTIONS` (`manage`, `approve`, `publish`, `accept`,
  `grant`, `hold`) → `sensitive`
- capability starting with `identity.account.` → `sensitive`
- capability in the explicit `SENSITIVE_CAPABILITIES` list → `sensitive`
  (currently `organization.person.read`, `organization.person.reference`,
  `organization.import.read` — reads of resources their controllers classify
  as `confidential`)
- everything else → `normal`

A `classification_policies` row may only *raise* the floor via
`minimum_capability`; it can never lower it.

**Structural rule (now enforced by test):** every controller that declares
`classification: 'confidential'` in its `RecordFacts` must use a capability
seeded as `sensitive`. See
`apps/api/tests/Feature/OrganizationPersonReadClearanceTest.php`.

## 5. Roles, Grants, and Scopes

```
roles (code, role_type: system|journey, is_system_role)
  └── role_capabilities (effect: allow|deny)
        └── capabilities (capability_code, sensitivity)
users
  └── role_assignments (role_id, scope_type, scope_id, start_at, end_at, status)
  └── delegations (scope_type, scope_id, window, granted_by)
supervisory_relationships (actor unit → target unit, capabilities, valid window)
```

- Grant scope types: `cluster`, `facility`, `unit`. A grant applies only when
  its scope covers the resource facts.
- Seeded system roles: `system.access-admin` (all `authorization.*`; vacant
  until assigned through the admin API) and `system.security-auditor`
  (`authorization.*.read` only).
- Development journey roles: `journey.r1-operator` (75 capabilities, facility
  scope) and `journey.w13-authorization-admin` (14 `authorization.*`
  capabilities, facility A, account A only).
- There is **no super-admin account or role**. The highest authority is the
  `system.access-admin` role, granted only through the governed admin API.

## 6. Auditing

`DatabasePersistAccessDecision` writes, in one transaction:

- `access_decisions` — every decision (allow and deny): action, resource
  type, `reason_codes`, policy version, classification, correlation id,
  actor, sanitized access context.
- `sensitive_access_events` — additionally, for allowed decisions on
  classified records carrying a concrete `recordId`.

`persist()` returns false only when the actor `user_id` is missing, the
`decisionId` is null, or the transaction throws. When a decision requires a
sensitive audit and persistence fails, an otherwise-allowed decision is
converted into a deny with `sensitive_audit_unavailable` — allow without an
audit trail is treated as a violation.

## 7. The Admin API Is Governed by the Same Engine

`AuthorizationAdminController` maps admin resources to capabilities via
`CapabilityCatalog::adminRead` / `adminManage` (e.g. roles →
`authorization.role.read|manage`). Granting a role, assigning it, denying a
capability, or creating a delegation each passes through the same `decide()`
path — there is no privileged backdoor, including for the administrators
themselves.

## 8. Verified Live Facts (2026-07-21)

- `authorization_bootstrap.state = complete` (completed by account `…0021`).
- Dev accounts: `w13-e2e-account-a` (r1-operator + w13-authorization-admin,
  facility A), `w13-e2e-account-b` (r1-operator, facility B); both
  `is_admin = 0`.
- `system.access-admin` has 32 capabilities and zero assignments.
- The confidential-clearance gap (person/import reads seeded `normal`)
  produced permanent `classification_insufficient` denies; fixed by
  `SENSITIVE_CAPABILITIES` in `AuthorizationCatalogSeeder` plus a structural
  conformance test. Live evidence: `access_decisions` holds the old
  `classification_insufficient` deny and the post-fix
  `classification_sufficient` allows for both accounts.

## سجل التغيير

| الإصدار | التاريخ | المؤلف | ملخص التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-21 | مسؤول أمن المعلومات | توثيق تشغيلي لمحرك القرار بعد إصلاح فجوة الـclearance |
