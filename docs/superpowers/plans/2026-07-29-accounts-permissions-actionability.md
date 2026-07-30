# Accounts & Permissions Actionability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Accounts & Permissions a five-tab workspace (Accounts, Roles & Permissions, Role Assignments, Policies & Scopes, Permission Decision Inspector) where every approved mutation — creating/editing/cloning a role with a complete `capability_codes` set, scoped assignment create/update/revoke/explicit-expire at the three manageable scope levels (cluster, facility, unit) — executes immediately, transactionally, and visibly through a typed web client — per the approved spec `docs/superpowers/specs/2026-07-29-accounts-permissions-actionability-design.md` and its §19 amendment (assignment-scope-target catalog + `record_set` fail-closed).

**Architecture:** Laravel modular monolith (`apps/api/Modules/*`) + React/Vite SPA (`apps/web`). All authorization-administration routes flow through the existing generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` family and reuse the Identity account endpoints. Roles get atomic `capability_codes` create/patch (no separate `role-capabilities` writes) and a `clone` action living on the same generic route with `adminResource=roles` and `authorizationAction=clone`; assignments and role-capabilities get no-reason `revoke` (assignments also get no-reason `expire`); role archive is a `PATCH status=archived`. Every mutation is committed transactionally with a single audit event emitted through a single outer `DB::transaction` owned by `AuthorizationAdminService`, which calls a Shared-owned port (`Shared\Contracts\RecordAuditEvent::record(array): void`) implemented by an Audit-module adapter that wraps/aliases `Modules\Audit\Contracts\RecordAuditEvent::record(AuditEventInput): AuditEventReceipt`. The Authorization module has zero direct dependency on Audit implementation internals; the bootstrap producer-packet exemption remains narrow.

**Tech Stack:** PHP 8.3 / Laravel 13 / PHPUnit 12 · React 19 / TS ~6 / Vite 8 / Vitest 4 / Playwright 1.61 · Orval (fetch client, custom mutator `fetcher.ts`).

## Global Constraints

- `docs/contracts/api/openapi.yaml` is edited FIRST; Orval output (`apps/web/src/api/generated/cluster.ts`) and `.orval/api-reference.html` are generated, never hand-edited (spec §13).
- All authorization-administration operations MUST continue to flow through the generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` route family — no dedicated sub-routes. The `clone` action is published at `adminResource=roles` + `authorizationAction=clone` (same path shape as the existing `revoke`/`expire`/`activate`/`publish` actions). Accounts continues to use `/identity/accounts*` unchanged (spec §8).
- System roles are immutable through every published mutation path; the server guard is authoritative (spec §9.1, §7.2). Mutation attempts return 409 `urn:cluster:problem:system-role-immutable`; the clone flow is the only sanctioned alternate.
- Roles get atomic `capability_codes` on create and `PATCH`; the role `PATCH` body may include `capability_codes` and that PATCH applies role state + capability set + audit in one DB transaction (spec §8, §9.4).
- The role `clone` action CREATES a new custom role from a system role. It is invoked via `POST /authorization/roles/{resourceId}/clone` with `If-Match` required (the source system role's `lock_version`). The source role, its capability set, and its assignments remain untouched. The clone emits a single audit event (spec §6, §7.3).
- Role assignments and role-capabilities revoke, and role assignments explicit expire, take no `reason` and use empty `EmptyActionBody` (spec §8, §6). `createRoleAssignment` carries role fields + `resource_type` only; `createRole` carries role fields + `capability_codes`; `cloneRoleFromSystemRole` accepts optional `RoleCloneInput` overrides; archive is `PATCH status=archived`. In-scope wrappers do NOT carry a `reason` parameter. Other governed resources keep their existing `ReasonAction` body and contract. No `reason` field is added to `AuthorizationAdminCreate`.
- Role archive is a `PATCH status=archived` (no `…/archive` action route) (spec §8). Every role edit uses `Content-Type: application/merge-patch+json` and an `If-Match` header carrying the canonical `lock_version`.
- One `AuthorizationAdminService` owns ONE outer `DB::transaction` per mutation. The gateway mutators and helpers (`update`, `transition`, `withCapabilitySet`, `cloneRole`) are **transaction-neutral** — they never call `DB::transaction` and never commit independently. This is the contract that tests in Task 4 enforce: when the audit double throws inside the outer transaction after mutation but before commit, the role row, capability rows, and audit-event row are all absent.
- Every audit event MUST be emitted through the Shared-owned port `Shared\Contracts\RecordAuditEvent::record(array $event): void` and committed in the same outer DB transaction as the authorization mutation (spec §9.4, §9.6, §18).
- Audit adapter location: `apps/api/Modules/Audit/Infrastructure/Persistence/SharedRecordAuditEventAdapter.php`. The adapter implements `Shared\Contracts\RecordAuditEvent` (rank -1) and wraps/aliases `Modules\Audit\Contracts\RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` (rank 3). It is bound to the Shared port in `Modules/Audit/Providers/AuditServiceProvider.php`. Tests live under `Modules/Audit/Tests`. The adapter maps the full `AuditEventInput` invariants; the adapter signature is `record(array $event): void` on the Shared interface and `record(AuditEventInput $input): AuditEventReceipt` on the Audit port.
- The Authorization module MUST NOT directly import `Modules\Audit\Contracts\*`; the existing M00 producer-packet exemption (`AUTHORIZATION-AUDIT-PRODUCER`) is preserved and applies only to `Modules/Authorization/Infrastructure/Persistence/AuthorizationBootstrapState.php` (rank-2 → rank-3 boundary; `apps/api/tests/Architecture/ModuleBoundariesTest.php` constants).
- Resource `lock_version` (returned by GET) MUST be passed unchanged as `If-Match` on every PATCH / action; both `409` and `412` reuse the existing error envelope (`/components/responses/Conflict`, `PreconditionFailed`) (spec §12).
- Idempotency: `Idempotency-Key` is required on `POST /authorization/{adminResource}` and on action POSTs; retry with the same key + same hash MUST return the original canonical response without producing duplicate rows or audit events (spec §9.7).
- Web mutation controls (Create / Edit / Clone / Assign / Revoke / Expire) are capability-gated using the existing management capability codes (`authorization.role.manage`, `authorization.assignment.manage`, `authorization.policy.manage`, `authorization.capability.manage`) intersected with the per-resource `allowed_actions` array returned by GET (spec §10, §11). No new capabilities are introduced in this plan.
- The Permission Decision Inspector MUST reuse the existing published decision/explanation endpoints (`/authorization/access-decisions/{decisionId}/explanation`); entry is gated by the existing `authorization.decision.read` capability. No new mutation endpoint is added for it (spec §8, §5.5).
- The five daily/advanced tab IA in `apps/web/src/features/authorization/AccessWorkspace.tsx` is preserved exactly — five tabs in the same order; advanced tabs render a plain-language unavailable state, not a 403 (spec §4). Canonical tab key is `decision-inspector` (single token; not `permission-decision-inspector`) — the type alias, the `?tab=` query param, and the `tabAvailableFor()` switch use this exact string everywhere.
- Roles & Permissions basic view MUST NOT render raw UUIDs/JSON/evaluator payloads by default; advanced disclosure (stable capability codes) is opt-in via the existing `authorization.capability.read` capability (spec §5.2).
- All editor inputs have visible labels, hints, required state, and inline error text; successful mutations announce via a live region; advanced-tab deep-link to an inaccessible tab must not throw, render a plain-language unavailable state (spec §12, §13).
- Do not hand-edit `apps/web/src/api/generated/**`; run `npm --prefix apps/web run api:generate`.
- Do not hand-edit `docs/api/endpoints.md` / `rbac-matrix.md` (generated by `scripts/inventory-routes.py`).
- Full phpunit runs need memory: `php -d memory_limit=1G vendor/bin/phpunit` (repo convention).
- Feature tests simulate 401 with a bogus bearer token, never by omitting the token (repo convention), except for the new catalog HTTP-adapter tests under `Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php` which authenticate through the existing cookie-backed `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group (declared in `apps/api/routes/web.php`) and therefore exercise 401 by submitting a session cookie for a deleted principal or by hitting the route outside the middleware group.

- ModuleBoundaries gates: new code that touches audit must use `Shared\Contracts\RecordAuditEvent` (rank -1); importing `Modules\Audit\Contracts\*` from any new Authorization file is prohibited; the `AUTHORIZATION-AUDIT-PRODUCER` exception in `tests/Architecture/ModuleBoundariesTest::CROSS_MODULE_IMPORT_EXCEPTIONS` is NOT widened.
- **Assignment scope levels (spec §19):** exactly three manageable levels — `cluster`, `facility`, `unit` — drive the assignment scope picker, the dedicated catalog endpoint `GET /authorization/assignment-scope-targets`, and the mutation validation. `record_set` is intentionally NOT a manageable level: it is rendered as a disabled option in the UI with localized helper text and is rejected by the server with 422 `urn:cluster:problem:scope_type_not_catalogued`. The catalog endpoint is the single source of truth for scope targets and lives behind the dedicated `ListAssignmentScopeTargetsController` (NOT the generic `/authorization/{adminResource}` dispatch). The catalog resolves through the new generic `Modules\Organization\Contracts\ListOrganizationScopeTargets::labelCandidates(string $scopeType, list<array{scope_type, scope_id}> $candidates, ?string $search)` contract, which takes Authorization-derived candidate roots and returns labelled/filtered targets keyed by the original candidate index; pagination and cursor handling stay in the Authorization adapter (`Shared\Http\AuthenticatedCursorCodec`). The Authorization module MUST NOT query `Modules\Organization\Models\*` / `Modules\Organization\Persistence\*` or execute `DB::table('organizations.*')`; all scope facts flow through `Modules\Organization\Contracts\*`. The Organization module MUST NOT import `Modules\Authorization\Contracts\*`. The mutation guard is narrow and input-only: it fires inside the `DB::transaction(...)` `mutate()` callback before the gateway `create()` / `update()` call when the POST input body or PATCH merge-patch body explicitly carries `scope_type === 'record_set'`; the guard reuses the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')` (no new exception class) and the explicit controller match arm in `AuthorizationApi` converts that to the documented 422 envelope. The guard is NOT gateway-wide: `revoke` / `expire` of legacy `record_set` rows continue to succeed because their request bodies do not carry a `scope_type` field. No new capability is introduced and no record-set catalog owner is opened; record-set scope remains fail-closed until a follow-up amendment defines a catalog owner (spec §19.6, §19.10).

- Every task ends with a `Commit handoff gate` block. Do NOT run `git add`, `git commit`, or any git mutation inside the worktree. Hand the user the exact branch name, full repo-relative file list, and conventional-commit message. The final verification task is report-only; it does not produce a commit.
- PHP test paths in this plan use the real repo-relative paths from `apps/api`: `Modules/...` (not `tests/Modules/...`). The architecture-boundary test path `tests/Architecture/ModuleBoundariesTest.php` is left unchanged.

## Design decisions locked during planning

1. **Audit emission path:** `Shared\Contracts\RecordAuditEvent::record(array $event): void` is injected into the new authorization application service. The sole implementation lives at `Modules\Audit\Infrastructure\Persistence\SharedRecordAuditEventAdapter.php` and is bound by `Modules\Audit\Providers\AuditServiceProvider.php`. The adapter wraps `Modules\Audit\Contracts\RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` and aliases its return value to `void`. The Authorization service depends ONLY on the `Shared\Contracts` interface (rank -1); `Modules\Audit` (rank 3) is owned by the adapter file alone.
2. **Atomic role+capability commit:** `AuthorizationAdminService::commitRoleMutation(...)` opens ONE outer `DB::transaction` that calls `gateway->update(...)` (which performs role + capability writes without starting its own transaction) and the audit emission. Gateway mutators and helpers (including `withCapabilitySet`, `cloneRole`, `update`, `transition`) are transaction-neutral — they do not call `DB::transaction` and do not commit independently.
3. **System-role guard:** `AuthorizationAdminService::commitRoleMutation` short-circuits when the source role `is_system_role === true` for PATCH. The guard also covers the legacy `role-capabilities` PATCH path on system roles so a `role-capabilities` change cannot smuggle a capability edit into a system role.
4. **Clone action:** a new `transition` outcome `clone` is added to the gateway `transition()` allowlist for `adminResource === 'roles'`. There is NO dedicated path item; the existing generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` path handles `clone` with `adminResource: roles` and `authorizationAction: clone`. The clone requires `If-Match` (the source `lock_version`) and copies code, name, description, and capability set into a new custom role inside the outer transaction. Source role and assignments remain untouched.
5. **No-reason bodies and per-action body semantics:** the in-scope assignment `revoke`, role-assignments `expire`, and `role-capabilities` `revoke` are documented with an empty `EmptyActionBody` (no required `reason`). Role `clone` accepts an optional `RoleCloneInput` body (overrides such as `name`, `code`, `description`); no `reason` is required. Role `create` accepts role fields plus `capability_codes` (no `reason`). Role `archive` is a `PATCH` carrying `status: 'archived'` (no `reason`). All in-scope wrappers carry no `reason` parameter. `AuthorizationAdminCreate` does NOT gain a `reason` field. Out-of-scope governed resources keep their existing `ReasonAction` body. User logs provide accountability; no separate reason machinery is introduced.
6. **Allowed-action projection:** computed by the controller during serialization for `roles`, `role-assignments`, and `role-capabilities`; derived from the principal's capabilities + catalog constrained to the contract-declared action enum (spec §11).
7. **No new routes for accounts:** Accounts tab uses existing `/identity/accounts*` only (spec §8). The Accounts tab landing route in the E2E spec is `/admin/identity/accounts`.
8. **Reuse existing capability catalog:** every gate in this plan uses an existing capability: `authorization.role.read/manage`, `authorization.capability.read/manage`, `authorization.assignment.read/manage`, `authorization.policy.read/manage`, `authorization.decision.read` (inspector). No new catalog entries are introduced. Stable capability-code disclosure reuses the existing `authorization.capability.read`.
11. **Assignment-scope-target catalog + `record_set` fail-closed (spec §19):** the `GET /authorization/assignment-scope-targets` endpoint is the single source of truth for the assignment scope picker. Exactly three manageable levels are published — `cluster`, `facility`, `unit`. The catalog is filtered server-side by the principal's active `authorization.assignment.manage` roots (derived from Authorization-owned tables); pagination uses a stable opaque cursor tied to issuance; `scope_type=record_set` returns 422 `scope_type_not_catalogued`. The Authorization module reads scope facts only through `Modules\Organization\Contracts\ListOrganizationScopeTargets` (a new generic contract that takes Authorization-derived candidate roots; pagination/cursor stays in the Authorization adapter); no cross-module SQL, no direct model access. `record_set` is rendered as a disabled option in the UI with localized helper text and never submitted. The mutation `scope_type` guard is narrow and input-only: it throws the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')` inside the `mutate()` callback before the gateway create/update call. The explicit controller match arm in `AuthorizationApi` maps that exception to the documented 422 envelope. No new exception class is introduced. The dedicated catalog route lives in `apps/api/routes/web.php` immediately before the generic `authorization/{adminResource}` route, inheriting the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` group; the catalog GET does NOT flow through the generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` dispatch. The legacy `Modules\Organization\Contracts\ResolveScopeDescendants::descendants(string $scopeType, string $scopeId)` already returns facility+unit rows in a single call for `cluster` and `facility`; the Authorization adapter reuses that single call rather than performing a two-step walk.

---

### Task 1: Contract — full authorization-administration surface (clone on generic route, atomic capability_codes, no-reason actions, role archive, allowed_actions, system-role immutability, capability catalog projection)

**Files:**
- Modify: `docs/contracts/api/openapi.yaml`
- Modify: `redocly.yaml` only if needed
- Generated (never hand-edited): `apps/web/src/api/generated/cluster.ts`, `apps/web/.orval/api-reference.html`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `POST /authorization/{adminResource}/{resourceId}/{authorizationAction}` extended: `adminResource` enum gains `role-capabilities`; `authorizationAction` enum gains `clone`; the operation description explicitly limits `clone` to `adminResource=roles`.
  - `PATCH /authorization/{adminResource}/{resourceId}` extended: `AuthorizationAdminPatch` gains `capability_codes?: string[]` (only honored server-side when `adminResource === 'roles'`); `status` for roles accepts `['active','archived']`.
  - Action POST for `revoke` and `expire` on `role-assignments` and `revoke` on `role-capabilities`: `requestBody` optional, schema becomes new `EmptyActionBody` (`type: object, additionalProperties: false, properties: {}, required: []`).
  - `AuthorizationAdminCreate` gains `capability_codes?: string[]` (roles only). NO `reason` field is added to `AuthorizationAdminCreate`.
  - New schemas: `AuthorizationRole` (with `is_system_role:bool`, `role_type:'system'|'custom'`, `capability_codes?: string[]`, `assignment_count?: int`, `allowed_actions: string[]` constrained to `['create','edit','clone','archive','revoke','expire','assign','view_assignments','grant','retract']`); `AuthorizationRoleAssignment` (with `effective_status` and `allowed_actions: ['edit','revoke','expire']`); `AuthorizationCapability` (with `module_code`, `action`, `sensitivity`, `group_label`, `description`); `RoleCloneInput`; `EmptyActionBody`; `ProblemImmutableSystemRole` (`urn:cluster:problem:system-role-immutable`, status 409).
  - The existing `AccessDecisionResponse` schema (used by `POST /authorization/access-decisions` and `GET /authorization/access-decisions/{decisionId}/explanation`) is extended with `applies_in_plain_language`, `assignment_summaries`, `policy_references`, and `correlation_id` so the inspector UI can render plain-language results. No new inspector endpoint is introduced.
  - Document that the `clone` action requires `If-Match` carrying the source role `lock_version` (rejection → 412 problem envelope).

- [ ] **Step 1: Patch openapi.yaml**
  1. `/authorization/{adminResource}/{resourceId}/{authorizationAction}` path: extend the existing `adminResource` path enum to include `role-capabilities`; extend the `authorizationAction` path enum to include `clone`; document `x-clone-source-invariant: system-role-only` extension on the combined parameter constraint block.
  2. `AuthorizationAdminPatch`: add `capability_codes?: { type: array, uniqueItems: true, items: { type: string, maxLength: 128 } }`; document `x-server-constraint: capabilities-only-for-roles`; document `x-role-archive-allowed: true` on `status`.
  3. Action POST body: split body requirement — `revoke`/`expire` on `role-assignments`, `revoke` on `role-capabilities` reference the new `EmptyActionBody`; all other actions keep `ReasonAction`. The `clone` action references `RoleCloneInput` (overrides); `requestBody` is optional.
  4. `AuthorizationAdminCreate`: add `capability_codes?` only. Do NOT add a `reason` field.
  5. Define the new schemas adjacent to `AuthorizationAdminCreate` with the shapes above. The `clone` action's required `If-Match` is documented via the shared `If-Match` parameter + an `x-if-match-required-on: clone` extension.

- [ ] **Step 2: Validate contract**

Run: `npm --prefix apps/web run api:lint`
Expected: exit 0.

- [ ] **Step 3: Regenerate client + reference**

Expected: `cluster.ts` contains `cloneAuthorizationAdminResource` (operating on the shared generic route), the updated `AccessDecisionResponse` with the new inspector fields, and the new `AuthorizationRole` / `AuthorizationRoleAssignment` / `AuthorizationCapability` / `RoleCloneInput` / `EmptyActionBody` / `ProblemImmutableSystemRole` schemas; `.orval/api-reference.html` rebuilt.
(merged into line 86)

- [ ] **Step 4: Generated-client check**

Run: `npm --prefix apps/web run api:check`
Expected: exit 0.

- [ ] **Commit handoff gate**

Branch `feat/contract-accounts-permissions-actionability`, files `docs/contracts/api/openapi.yaml apps/web/src/api/generated/cluster.ts apps/web/.orval/api-reference.html`, message `feat(contract): publish clone action on generic route, atomic role capability_codes, no-reason revoke/expire/archive, role archive, inspector decision schema`. Do NOT run `git add` or `git commit`.

---

### Task 1A: OpenAPI amendment — assignment-scope-target catalog + `record_set` fail-closed (spec §19.1, §19.2, §19.6)

**Files:**
- Modify: `docs/contracts/api/openapi.yaml`
- Generated (never hand-edited): `apps/web/src/api/generated/cluster.ts`, `apps/web/.orval/api-reference.html`

**Interfaces:**
- Consumes: Task 1 (existing generic administration path family); the Shared collection envelope shape produced by `AuthorizationApi::collection($items, $nextCursor)` (referenced verbatim so the generated collection schema matches the runtime shape).
- Produces:
  - New operation `GET /authorization/assignment-scope-targets` documented with:
    - Required query `scope_type` enum: `cluster | facility | unit | record_set`. `record_set` is **published in the query enum** for backward compatibility of stored rows but always returns 422 `urn:cluster:problem:scope_type_not_catalogued` (the API filter contract). The OpenAPI path operation must NOT remove `record_set` from the query enum; it documents the four-value enum together with the 422 response for `record_set` and a localized `detail` (AR+EN) referencing the missing catalog owner.
    - Optional query `parent_scope_type` enum: `cluster | facility`; `parent_scope_id` (UUIDv7); `search` (free text); `cursor` (opaque, principal-scoped); `limit` (default 25, max 100, clamped server-side).
    - 200 response envelope `{ items: AssignmentScopeTarget[], next_cursor: string | null }`. The flat shape is the same shape consumed by `AuthorizationApi::collection` and emitted through the Orval-generated collection schema (`x-collection-shape: items-next_cursor`). Each `AssignmentScopeTarget` carries `scope_type` (`cluster|facility|unit`, never `record_set`), `scope_id` (UUIDv7), `label_ar` (required, non-empty), `label_en` (required, non-empty), optional `code` (omitted entirely when absent).
    - 400 `invalid_scope_query` documented for `scope_type=cluster & parent_scope_type=facility`.
  - The endpoint is gated by the existing cookie-backed `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group declared in `apps/api/routes/web.php`. The contract documents `application/problem+json` problem responses for 401, 403, and 422; no bearer-token or `Authorization` header contract is published.

  - Pagination cursors are encoded/decoded by `Shared\Http\AuthenticatedCursorCodec` and bound to the principal + filters + resolved `limit`. The OpenAPI cursor field is opaque (`type: string`); the contract does not publish TTL signatures.
  - The existing `AuthorizationAdminCreate` schema (used for `POST /authorization/role-assignments`) documents `scope_type` enum `cluster | facility | unit | record_set` for **historical backward compatibility** of stored rows, with `x-record-set-fail-closed: true` extension and an explicit pointer to spec §19.6. The mutation schemas add 422 `scope_type_not_catalogued` to the `POST /authorization/role-assignments` and `PATCH /authorization/role-assignments/{id}` responses when `scope_type=record_set` is submitted.
  - New `ProblemScopeTypeNotCatalogued` schema (`type: urn:cluster:problem:scope_type_not_catalogued`, status 422).

- [ ] **Step 1: Patch openapi.yaml**
  1. Add path `/authorization/assignment-scope-targets` with the query/response/error schemas above. Tag with the existing `authorization-administration` tag. The `scope_type` query parameter enumerates all four values (`cluster | facility | unit | record_set`) and documents `x-cursor-bounded: true` and `x-record-set-fail-closed: true` extensions. The 422 `ProblemScopeTypeNotCatalogued` response is documented for `scope_type=record_set`. The 200 response is documented with `x-collection-shape: items-next_cursor` and the flat `{items, next_cursor}` envelope.
  2. Extend the existing `/authorization/role-assignments` POST 422 response with `ProblemScopeTypeNotCatalogued` and add `x-record-set-rejection-problem: scope_type_not_catalogued` extension.
  3. Extend the existing `/authorization/role-assignments/{assignmentId}` PATCH 422 response with the same problem schema.
  4. Add `AssignmentScopeTarget` schema adjacent to the existing `AuthorizationRoleAssignment` schema. `scope_type` enum is `cluster | facility | unit`; `scope_id` is `UUIDv7`; `label_ar` and `label_en` are required non-empty strings; `code` is optional and `nullable`; document `x-id-display-rule: label-only` so wrappers and generators do not surface `scope_id` as primary label text. The mutation `scope_type` enum retains all four values (`cluster | facility | unit | record_set`) including the legacy `record_set`; the `x-record-set-fail-closed: true` extension names spec §19.6 as the authoritative behavior.

- [ ] **Step 2: Validate contract**

Run: `npm --prefix apps/web run api:lint`
Expected: exit 0.

- [ ] **Step 3: Regenerate client + reference**

Expected: `cluster.ts` contains `listAuthorizationAssignmentScopeTargets` (operating on `/authorization/assignment-scope-targets`); the new `AssignmentScopeTarget` and `ProblemScopeTypeNotCatalogued` schemas are present; the generated `ListAuthorizationAssignmentScopeTargetsParams` type accepts all four `scope_type` values; the response schema is the flat `items-next_cursor` collection shape. `.orval/api-reference.html` rebuilt.

- [ ] **Step 4: Generated-client check**

Run: `npm --prefix apps/web run api:check`
Expected: exit 0.

- **Commit handoff gate**

Branch `feat/contract-assignment-scope-targets`, files `docs/contracts/api/openapi.yaml apps/web/src/api/generated/cluster.ts apps/web/.orval/api-reference.html`, message `feat(contract): publish assignment-scope-targets catalog with items-next_cursor envelope and record_set fail-closed (spec §19.1, §19.2, §19.6)`. Do NOT run `git add` or `git commit`.
### Task 1B: Laravel/ports/security — Organization `ListOrganizationScopeTargets` contract + Authorization `ListAssignmentScopeTargets` port + dedicated controller + `record_set` fail-closed validation (spec §19.3, §19.4, §19.5, §19.6)
**Files:**
- Create: `apps/api/Modules/Organization/Contracts/ListOrganizationScopeTargets.php` (Organization-owned contract; takes Authorization-derived candidate roots; no Authorization imports; no cursor/pagination parameters)
- Create: `apps/api/Modules/Organization/Infrastructure/Persistence/DatabaseListOrganizationScopeTargets.php` (sole Organization implementation; reads only Organization's own tables/views/models; may internally use `ResolveScopeDescendants` as an Organization implementation helper but does not re-export it as a public Authorization-facing seam)
- Modify: `apps/api/Modules/Organization/Providers/OrganizationServiceProvider.php` (add the `$this->app->bind(ListOrganizationScopeTargets::class, DatabaseListOrganizationScopeTargets::class);` binding; do NOT widen any existing cross-module exception)
- Create: `apps/api/Modules/Authorization/Features/Administration/Contracts/ListAssignmentScopeTargets.php` (Authorization-owned port; takes the actor's `user_id` and the catalog query)
- Create: `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabaseListAssignmentScopeTargets.php` (sole Authorization implementation under the module's Infrastructure/Persistence namespace, matching the module convention; injects `ListOrganizationScopeTargets` and `Shared\Http\AuthenticatedCursorCodec`; derives the active `authorization.assignment.manage` roots directly from Authorization-owned tables itself)
- Create: `apps/api/Modules/Authorization/Features/Administration/Http/ListAssignmentScopeTargetsController.php` (dedicated controller; mounted on the dedicated route in `apps/api/routes/web.php` — NOT inside the generic `AuthorizationAdminController` `__invoke()` `adminResource` dispatch, NOT inside `AuthorizationServiceProvider`)
- Modify: `apps/api/routes/web.php` (add the dedicated `GET /api/v1/authorization/assignment-scope-targets` route immediately before the generic `authorization/{adminResource}` route definition, inheriting the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group; do NOT add the catalog path to the generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` family)
- Modify: `apps/api/Modules/Authorization/Providers/AuthorizationServiceProvider.php` (bind the Authorization port `$this->app->bind(ListAssignmentScopeTargets::class, DatabaseListAssignmentScopeTargets::class);`; do NOT register any route in this provider — the route lives in `apps/api/routes/web.php`)
- Modify: `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php` (NO branch for the catalog GET; the generic controller only maps `scope_type=record_set` mutation rejections to the documented 422 problem envelope on the existing role-assignments POST/PATCH endpoints)
- Modify: `apps/api/Modules/Authorization/Features/Administration/Application/AuthorizationAdminService.php` (replace any synchronous custom exception path with a narrow input-only guard that throws the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')` from inside the `DB::transaction(function () { ... })` `mutate()` callback, before the gateway `create()` / `update()` call; the explicit controller match arm in `AuthorizationApi` converts that to the documented 422 problem envelope)
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php`
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationRoleAssignmentRecordSetFailClosedTest.php`
- Test: `apps/api/Modules/Organization/Tests/ListOrganizationScopeTargetsContractTest.php` (new contract unit test on the Organization side; proves the Organization implementation honours the candidate-roots signature and never imports `Modules\Authorization\*`; proves the contract never accepts, emits, or interprets a cursor)
- Test: `apps/api/Modules/Authorization/Tests/SharedAuthenticatedCursorCodecTest.php` (covers the codec's principal+filters+limit binding; proves a tampered cursor is rejected)

**Interfaces:**
- Consumes: the new `Modules\Organization\Contracts\ListOrganizationScopeTargets::labelCandidates(string $scopeType, list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}> $candidates, ?string $search): array<int, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>` (candidate-roots signature: Organization labels and filters the candidate list and returns a map keyed by the original candidate index; no Authorization imports; no cursor/pagination parameters); Tasks 1, 2, 3, 4; existing `Shared\Http\AuthenticatedCursorCodec`. The existing `Modules\Organization\Contracts\ResolveScopeDescendants::descendants(string $scopeType, string $scopeId)` already returns every facility under a cluster (or every unit under a facility) in a single call; the adapter reuses that single call rather than performing a two-step walk.
- Produces:
  - Port `Modules\Authorization\Features\Administration\Contracts\ListAssignmentScopeTargets::targets(string $actorUserId, string $scopeType, ?string $parentScopeType, ?string $parentScopeId, ?string $search, ?string $cursor, int $limit): array{items: list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>, next_cursor: ?string}`. The port takes the actor's `user_id` so the sole Authorization implementation can derive the actor's active `authorization.assignment.manage` roots directly from Authorization-owned tables itself. Sole implementation `DatabaseListAssignmentScopeTargets` derives the manageable-scope roots from Authorization-owned tables, calls `ListOrganizationScopeTargets::labelCandidates(...)`, applies the `search` filter and pagination via the codec, and returns the flat `{items, next_cursor}` envelope. Authorization NEVER queries `Modules\Organization\Models\*` or `Modules\Organization\Persistence\*` or executes `DB::table('organizations.*')`.
  - The Authorization implementation enforces: server-side filtering by the actor's active `authorization.assignment.manage` roots derived from Authorization-owned tables; pagination via `Shared\Http\AuthenticatedCursorCodec` (principal+filters+limit bound) — pagination and cursor handling stay in the Authorization adapter, never cross into the Organization contract; `limit` clamped to the documented maximum of 100 with a default of 25; an actor with no manageable scope returns `{items: [], next_cursor: null}` and HTTP 200 (NOT 403); `scope_type=record_set` returns the standard 422 problem envelope with `type=urn:cluster:problem:scope_type_not_catalogued`; `scope_type=cluster & parent_scope_type=facility` returns HTTP 400 `invalid_scope_query`. The implementation does NOT introduce a new exception class; it reuses the existing `InvalidArgumentException` with message `authorization_scope_type_not_catalogued` and the HTTP layer's explicit match arm converts that exact message into the documented 422 problem envelope.
  - The Organization contract implementation accepts the candidate roots list supplied by the Authorization adapter and an optional `search` substring. It returns a **map keyed by the original candidate index** so the Authorization adapter can re-order, drop, or expand each candidate against the catalog. Order is not guaranteed; the adapter is the source of truth for ordering and pagination. The Organization contract never accepts, emits, or interprets a cursor.
  - `AuthorizationAdminService::createAssignment` validates `scope_type !== 'record_set'` INSIDE the `DB::transaction(function () use (...) { ... })` `mutate()` callback BEFORE the gateway `create()` call, and `updateAssignment` validates the same condition INSIDE the `mutate()` callback BEFORE the gateway `update()` call. The guard is **narrow and input-only**: it fires only when the POST input body or the PATCH merge-patch body explicitly supplies `scope_type === 'record_set'`. The guard is NOT gateway-wide: `revoke`, `expire`, and any read-only flow on historical `record_set` rows remain available because their request bodies do not carry a `scope_type` field. On rejection, the guard throws the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')` (NO new exception class); the existing outer `DB::transaction` rolls back cleanly, no `role_assignments` row is written, no audit row is written, and the `Idempotency-Key` header on the rejected request is NOT persisted. A subsequent retry with the same key must hit the guard again and produce the same 422 response.
  - The dedicated `ListAssignmentScopeTargetsController` handles `GET /api/v1/authorization/assignment-scope-targets`. The route is registered in `apps/api/routes/web.php` immediately before the generic `authorization/{adminResource}` route, inheriting the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group; the controller resolves the actor from that route group, builds the candidate roots via the Authorization port (which takes `string $actorUserId`), delegates to the port, and returns the page through the existing `AuthorizationApi::collection(array $page, string $correlationId, ?string $link)` helper as JSON with `Content-Type: application/json`. The generic `AuthorizationAdminController` and the `adminResource` dispatch path are NOT used for the catalog GET. The generic `AuthorizationAdminController` is permitted to map `scope_type=record_set` mutation rejections to the documented 422 problem envelope on the existing `POST /api/v1/authorization/role-assignments` and `PATCH /api/v1/authorization/role-assignments/{id}` endpoints via an explicit controller match arm: any `InvalidArgumentException` whose message equals `authorization_scope_type_not_catalogued` is converted to the documented `urn:cluster:problem:scope_type_not_catalogued` 422 envelope. The match arm lives in `AuthorizationApi`'s exception filter and is the single emission path for that envelope; the generic controller must NOT host the catalog GET branch.

- [ ] **Step 1: Failing test — catalog endpoint filters by actor's manageable scope**

`AuthorizationAssignmentScopeTargetsHttpAdapterTest` proves:
- Cluster administrator fixture → `scope_type=cluster` returns exactly one row (the cluster), `next_cursor: null`, envelope shape `{items, next_cursor}`.
- Cluster administrator fixture → `scope_type=unit` with `parent_scope_type=cluster`, `parent_scope_id=<cluster>` returns every descendant unit in ONE response (the existing `ResolveScopeDescendants::descendants('cluster', <cluster>)` returns facilities and units in a single call).
- Facility-only-admin fixture → `scope_type=unit` with `parent_scope_type=facility`, `parent_scope_id=<managed_facility>` returns units under that facility; querying a different facility returns `{items: [], next_cursor: null}`.
- Actor with no manageable scope → `{items: [], next_cursor: null}`, status 200 (NOT 403).
- `scope_type=record_set` → 422 `urn:cluster:problem:scope_type_not_catalogued` via the explicit controller match arm in `AuthorizationApi`'s exception filter (which maps `InvalidArgumentException` with message `authorization_scope_type_not_catalogued` to the documented 422 envelope); no new exception class is introduced.
- `scope_type=cluster & parent_scope_type=facility` → 400 `invalid_scope_query`.
- Pagination: first page with `limit=2` returns 2 rows + `next_cursor`; second page with that cursor returns the next rows with no duplicates and `next_cursor: null` on the last page; the cursor was issued and parsed via `Shared\Http\AuthenticatedCursorCodec` and rejects a tampered cursor; `next_cursor` is computed by the Authorization adapter, never by the Organization contract.
- `limit` larger than the documented maximum (100) is clamped; `limit` defaults to 25 when omitted.
- Architecture seam assertion: the Authorization source set still imports zero `Modules\Organization\Models\*` / `Modules\Organization\Persistence\*` symbols; the only Organization coupling is through `Modules\Organization\Contracts\*` (plural); the Organization module imports zero `Modules\Authorization\Contracts\*` symbols.

- [ ] **Step 2: Failing test — `record_set` mutation fail-closed**

`AuthorizationRoleAssignmentRecordSetFailClosedTest` proves:
- `POST /api/v1/authorization/role-assignments` with `scope_type=record_set` → 422 `scope_type_not_catalogued` through the standard problem envelope; no `role_assignments` row inserted; no audit event beyond the documented validation outcome; the `Idempotency-Key` header on the rejected request is NOT persisted.
- `PATCH /api/v1/authorization/role-assignments/{id}` with `scope_type=record_set` → same 422 through the same envelope; same no-row, no-audit, no-idempotency-record invariant.
- `POST /api/v1/authorization/role-assignments/{id}/revoke` and `POST …/expire` on a historical `record_set` row → 200 with the canonical assignment; the guard does NOT trip because the request body carries no `scope_type` field. This proves the guard is narrow and input-only; legacy `record_set` rows remain revocable/expirable.
- The audit double-throw contract is preserved: the outer transaction opens via `DB::transaction(function () { ... })`, the validation guard executes inside the `mutate()` callback BEFORE the gateway `create()` / `update()` call, the guard throws the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')`, the explicit controller match arm converts that to the documented 422 envelope, and the transaction rolls back cleanly. The guard MUST NOT move outside the `mutate()` callback; if it does, the outer transaction contract is broken and the test fails. The guard MUST NOT be promoted to a gateway-wide check; if it does, the revoke/expire happy paths above break and the test fails.

- [ ] **Step 3: Run, verify FAIL**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php Modules/Authorization/Tests/AuthorizationRoleAssignmentRecordSetFailClosedTest.php Modules/Authorization/Tests/SharedAuthenticatedCursorCodecTest.php`
Expected: FAIL — endpoints and validation guards not yet wired.

- [ ] **Step 4: Implement — Organization candidate-roots contract, Authorization port, dedicated route + controller, in-callback validation**

- Create `Modules\Organization\Contracts\ListOrganizationScopeTargets` (final class / interface) with the candidate-roots signature above. The contract does NOT import any `Modules\Authorization\*` symbol and does NOT accept, emit, or interpret a cursor.
- Create `Modules\Organization\Infrastructure\Persistence\DatabaseListOrganizationScopeTargets` (final class, implements the contract): receives the candidate roots list; for each candidate, resolves bilingual labels using only Organization's own tables/views/models; drops only candidates whose target row does not exist; applies `search` against `label_ar`/`label_en`/`code`; returns a map keyed by the original candidate index. The class may internally use `Modules\Organization\Contracts\ResolveScopeDescendants` as a private implementation helper but does not re-export it as a public Authorization-facing seam. The existing `descendants('cluster', <id>)` already returns facility+unit rows in one call, so a single resolve suffices.
- Modify `OrganizationServiceProvider::register()` to add the binding: `$this->app->bind(ListOrganizationScopeTargets::class, DatabaseListOrganizationScopeTargets::class);`. Do NOT widen any existing cross-module exception.
- Create `Modules\Authorization\Features\Administration\Contracts\ListAssignmentScopeTargets` (final class / interface) — the Authorization-owned port. The port signature is `targets(string $actorUserId, string $scopeType, ?string $parentScopeType, ?string $parentScopeId, ?string $search, ?string $cursor, int $limit): array{items: list<…>, next_cursor: ?string}`.
- Create `apps/api/Modules/Authorization/Infrastructure/Persistence/DatabaseListAssignmentScopeTargets.php` (final class, implements the Authorization port): ctor takes `ListOrganizationScopeTargets` and `Shared\Http\AuthenticatedCursorCodec`. Translates `scope_type=cluster & parent_scope_type=facility` into 400 `invalid_scope_query`; clamps `limit` to 100 with a default of 25; derives the actor's active `authorization.assignment.manage` roots directly from Authorization-owned tables itself; expands the documented `parent_scope_*` pair into the candidate roots list using the existing `ResolveScopeDescendants::descendants(...)` single call (which already returns facility+unit rows); calls `ListOrganizationScopeTargets::labelCandidates(...)`; applies `search`; issues …
- Create `Modules\Authorization\Features\Administration\Http\ListAssignmentScopeTargetsController` (final class): single `__invoke` method that resolves the actor from the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group, delegates to the Authorization port with the actor's `user_id`, and returns `AuthorizationApi::collection($page, $correlationId, $link)` as JSON with `Content-Type: application/json`. The controller does NOT use the generic `AuthorizationAdminController` and does NOT participate in the `adminResource` dispatch.
- Modify `apps/api/routes/web.php` to add the dedicated route **immediately before** the generic `authorization/{adminResource}` route definition, inheriting the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` route group:
  - Inside the existing `Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(...)` block that already contains the `Route::get('authorization/access-decisions/{decisionId}/explanation', ...)`, `Route::get('authorization/bootstrap', ...)`, and `Route::get('authorization/{adminResource}', ...)`, insert the new line **immediately before** the generic `Route::get('authorization/{adminResource}', ...)`: `Route::get('authorization/assignment-scope-targets', ListAssignmentScopeTargetsController::class);`. The route inherits the existing `IdentitySessionMiddleware` + `RequireIdentitySessionPrincipal` group via the `api/v1` prefix; no extra middleware is applied.
  - Do NOT register this route in `AuthorizationServiceProvider`; do NOT add a separate `Route::middleware(...)` line for it.
- Modify `AuthorizationServiceProvider::register()` to add the port binding: `$this->app->bind(ListAssignmentScopeTargets::class, DatabaseListAssignmentScopeTargets::class);`. Do NOT register any route in this provider.
- The existing `AuthorizationApi::collection(array $page, string $correlationId, ?string $link)` helper is reused as-is. The dedicated controller calls it with the page array the port returns; the helper is the single emission path for the catalog and is shared with the Orval-generated collection schema. Do NOT add a new helper, do NOT overload the helper, do NOT modify `AuthorizationApi`.
- Modify `AuthorizationAdminService::createAssignment` and `updateAssignment` so the `scope_type !== 'record_set'` guard runs INSIDE the `DB::transaction(function () use (...) { ... })` `mutate()` callback BEFORE the gateway `create()` / `update()` call and throws the existing `InvalidArgumentException('authorization_scope_type_not_catalogued')` (NO new exception class). The existing outer `DB::transaction` rolls back cleanly because no row was written, no audit row is written, and the `Idempotency-Key` header is not persisted. The explicit controller match arm in `AuthorizationApi` converts that exact `InvalidArgumentException` message to the documented 422 `urn:cluster:problem:scope_type_not_catalogued` envelope. The guard MUST stay inside the `mutate()` callback; moving it outside would break the outer-transaction contract. The guard MUST stay narrow and input-only; promoting it to a gateway-wide check would block `revoke`/`expire` of legacy `record_set` rows and is forbidden.

- [ ] **Step 5: Run, verify pass**

Run: same as Step 3; plus `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Organization/Tests/ListOrganizationScopeTargetsContractTest.php`.
Expected: PASS.

- [ ] **Step 6: Architecture-boundary regression**

Run: `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
Expected: PASS; the Authorization source set still imports zero `Modules\Organization\Models\*` / `Modules\Organization\Persistence\*` symbols; the only Organization coupling is through `Modules\Organization\Contracts\*` (plural — any existing or future Organization contract). The Organization module imports zero `Modules\Authorization\Contracts\*` symbols — the new Organization contract is intentionally generic over scope types and decoupled from the Assignment feature surface.

- [ ] **Step 7: Existing assignment-suite regression**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests`
Expected: PASS; no regression to existing authorization suites (cluster/facility/unit happy paths still create/update/revoke/expire assignments).

- **Commit handoff gate**

Branch `feat/api-assignment-scope-targets`, files `apps/api/Modules/Organization/Contracts/ListOrganizationScopeTargets.php apps/api/Modules/Organization/Infrastructure/Persistence/DatabaseListOrganizationScopeTargets.php apps/api/Modules/Organization/Providers/OrganizationServiceProvider.php apps/api/Modules/Authorization/Features/Administration/Contracts/ListAssignmentScopeTargets.php apps/api/Modules/Authorization/Infrastructure/Persistence/DatabaseListAssignmentScopeTargets.php apps/api/Modules/Authorization/Features/Administration/Http/ListAssignmentScopeTargetsController.php apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php apps/api/Modules/Authorization/Features/Administration/Application/AuthorizationAdminService.php apps/api/Modules/Authorization/Providers/AuthorizationServiceProvider.php apps/api/routes/web.php apps/api/Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php apps/api/Modules/Authorization/Tests/AuthorizationRoleAssignmentRecordSetFailClosedTest.php apps/api/Modules/Authorization/Tests/SharedAuthenticatedCursorCodecTest.php apps/api/Modules/Organization/Tests/ListOrganizationScopeTargetsContractTest.php`, message `feat(authorization): organization ListOrganizationScopeTargets candidate-roots contract + authorization ListAssignmentScopeTargets port with in-callback record_set guard, dedicated route in web.php, and standard ApiError 422 (spec §19.3, §19.4, §19.5, §19.6)`. Do NOT run `git add` or `git commit`.
---
---
### Task 1C: Orval/wrapper — `listAssignmentScopeTargets` typed wrapper + `record_set` surfaced through the server `ApiError` (spec §19.7, §19.9)

**Files:**
- Generated (never hand-edited): `apps/web/src/api/generated/cluster.ts` (regenerated in Task 1A)
- Modify: `apps/web/src/api/r1.ts`
- Test: `apps/web/src/api/r1.listAssignmentScopeTargets.test.ts`

**Interfaces:**
- Consumes: Task 1A generated types (`ListAuthorizationAssignmentScopeTargetsParams`, `AssignmentScopeTarget`).
- Produces typed wrapper:
  - The wrapper renders `label_ar` and `label_en` in the bilingual UI; raw `scope_id` is consumed only as a hidden form value, never as display text.
  - `listAssignmentScopeTargets(token, params: { scope_type: 'cluster'|'facility'|'unit'|'record_set', parent_scope_type?: 'cluster'|'facility', parent_scope_id?: string, search?: string, cursor?: string, limit?: number }): Promise<{ items: generated.AssignmentScopeTarget[]; next_cursor: string | null }>` — calls the Orval-generated `listAuthorizationAssignmentScopeTargets` operation for all four published `scope_type` values, GETs `/api/v1/authorization/assignment-scope-targets` with the exact query names from the contract, and returns the flat `{items, next_cursor}` envelope. The wrapper does NOT reject `scope_type=record_set` synchronously: when the caller passes `'record_set'` the request fires, the server returns the documented 422 `scope_type_not_catalogued` problem response, and the standard `ApiError` envelope surfaces that response unchanged. The `record_set` value is permitted at the generated type level (the contract publishes all four values), and the UI is responsible for rendering `record_set` as a disabled option so the wrapper never receives it from a real user flow.
- [ ] **Step 1: Failing test — wrapper types and `record_set` surfaced through the server `ApiError`**

`r1.listAssignmentScopeTargets.test.ts`:
- Stub the generated fetch via Orval mutator (mirror the pattern in `apps/web/src/api/api.test.ts`).
- `scope_type='cluster'` → calls the generated `listAuthorizationAssignmentScopeTargets`; response typed as `{ items: AssignmentScopeTarget[]; next_cursor: string | null }`.
- `scope_type='unit', parent_scope_type='cluster', parent_scope_id='<uuid>'` → call forwards all three query params.
- `scope_type='record_set'` → the fetch is observed in the stub; the mock returns a 422 response with the standard `application/problem+json` envelope (`type=urn:cluster:problem:scope_type_not_catalogued`, `status=422`, `title`, `detail`); the wrapper surfaces this rejection through the same `ApiError` flow used by every other generated operation, asserts the problem `type` and `status`, and never throws a custom `ProblemScopeTypeNotCatalogued` synchronously.
- `scope_type='facility'`, `scope_type='unit'`, `scope_type='cluster'`, and `scope_type='record_set'` accept all four values at the generated type level; the call type-checks without any `'record_set'` exclusion at compile time.
- `cursor` and `limit` round-trip exactly; `limit` larger than documented max is forwarded as-is (server clamps).
- `search` free-text forwarded as `search` query.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/api/r1.listAssignmentScopeTargets.test.ts`
Expected: FAIL — wrapper does not yet exist.

- [ ] **Step 3: Implement wrapper in `r1.ts`**
Add `listAssignmentScopeTargets` to `apps/web/src/api/r1.ts`. Reuse the existing `requestInit(token, { command: false })` plumbing from `apps/web/src/api/http.ts` (GET, no idempotency, no `mergePatch`). Forward `scope_type`/`parent_scope_type`/`parent_scope_id`/`search`/`cursor`/`limit` through the Orval-generated `params` object, accept all four `scope_type` values at the generated type level, and let the server's standard `ApiError` envelope carry the 422 `scope_type_not_catalogued` response for `'record_set'`. Do NOT add a synchronous rejection path, do NOT throw a `ProblemScopeTypeNotCatalogued`, and do NOT modify `apps/web/src/api/http.ts`.
- [ ] **Step 4: Run, verify pass**


Run: same as Step 2.
Expected: PASS.

- [ ] **Step 5: Generated-client parity**

Run: `npm --prefix apps/web run api:check`
Expected: exit 0.

- **Commit handoff gate**


Branch `feat/web-assignment-scope-targets-wrapper`, files `apps/web/src/api/r1.ts apps/web/src/api/r1.listAssignmentScopeTargets.test.ts`, message `feat(web): listAssignmentScopeTargets typed wrapper surfaces record_set through the server ApiError envelope (spec §19.7, §19.9)`. Do NOT run `git add` or `git commit`.
---

### Task 2: Audit-Side adapter — `SharedRecordAuditEventAdapter` mapping full `AuditEventInput` invariants

**Files:**
- Create: `apps/api/Modules/Audit/Infrastructure/Persistence/SharedRecordAuditEventAdapter.php`
- Modify: `apps/api/Modules/Audit/Providers/AuditServiceProvider.php` (bind the adapter to the Shared port)
- Test: `apps/api/Modules/Audit/Tests/SharedRecordAuditEventAdapterTest.php`

**Interfaces:**
- Consumes: `Modules\Audit\Contracts\RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` (rank 3).
- Produces: implementation of `Shared\Contracts\RecordAuditEvent::record(array $event): void` (rank -1) living in the Audit module. The adapter translates the Shared plain-array payload into a fully-validated `AuditEventInput`, calls the inner Audit port, and aliases the returned `AuditEventReceipt` to `void` (the Shared port does not return anything).

- [ ] **Step 1: Failing test — adapter maps fields, outcomes, and full invariants**

`SharedRecordAuditEventAdapterTest` covers:
- Adapter accepts the payload shape documented in `Shared/Contracts/RecordAuditEvent.php:20`.
- `outcome` strings `succeeded|failed|denied` map to `OUTCOME_SUCCEEDED|OUTCOME_FAILED|OUTCOME_DENIED`.
- Non-UUID `correlation_id` throws `InvalidArgumentException('audit_correlation_id_invalid')`.
- Missing required fields throw `audit_field_missing:{name}`.
- `actor_type` outside the allowed `AuditEventInput::ALLOWED_ACTOR_TYPES` throws `audit_actor_type_invalid`.
- `classification` outside `ALLOWED_CLASSIFICATIONS` throws `audit_classification_invalid`.
- `retention_class` outside `ALLOWED_RETENTION_CLASSES` throws `audit_retention_class_invalid`.
- `event_type` not matching `assertEventType` throws the documented `audit_event_type_invalid`.
- `eventId` is set to a UUIDv7 when not provided.
- The inner `RecordAuditEvent::record` is called exactly once per `record()` invocation; the returned `AuditEventReceipt` is discarded (adapter returns `void`).
- The adapter NEVER imports `Modules\Audit\Contracts\*` from any Authorization file (architecture-boundary test still passes).

- [ ] **Step 2: Run, verify fail**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Audit/Tests/SharedRecordAuditEventAdapterTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent as AuditRecordAuditEvent;
use Shared\Contracts\RecordAuditEvent;

final class SharedRecordAuditEventAdapter implements RecordAuditEvent
{
    public function __construct(private readonly AuditRecordAuditEvent $inner) {}

    public function record(array $event): void
    {
        foreach (['source_module','action','event_type','actor_type','subject_type','correlation_id','outcome','classification','retention_class','occurred_at'] as $required) {
            if (! array_key_exists($required, $event)) {
                throw new InvalidArgumentException("audit_field_missing:{$required}");
            }
        }
        $occurredAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', (string) $event['occurred_at'], new DateTimeZone('UTC'))
            ?: throw new InvalidArgumentException('audit_occurred_at_invalid');
        $input = new AuditEventInput(
            eventId: is_string($event['event_id'] ?? null) && $event['event_id'] !== '' ? $event['event_id'] : Str::uuid7()->toString(),
            sourceModule: (string) $event['source_module'],
            action: (string) $event['action'],
            eventType: (string) $event['event_type'],
            actorType: (string) $event['actor_type'],
            actorId: $event['actor_id'] ?? null,
            originalActorId: $event['original_actor_id'] ?? null,
            subjectType: (string) $event['subject_type'],
            subjectId: $event['subject_id'] ?? null,
            correlationId: (string) $event['correlation_id'],
            outcome: (string) $event['outcome'],
            classification: (string) $event['classification'],
            context: is_array($event['context'] ?? null) ? $event['context'] : [],
            occurredAt: $occurredAt,
            retentionClass: (string) $event['retention_class'],
        );
        $this->inner->record($input); // AuditEventReceipt alias discarded
    }
}
```

- [ ] **Step 4: Bind in `AuditServiceProvider::register()`**

```php
$this->app->bind(\Shared\Contracts\RecordAuditEvent::class, \Modules\Audit\Infrastructure\Persistence\SharedRecordAuditEventAdapter::class);
```

- [ ] **Step 5: Run, verify pass**

Run: same as Step 2.
Expected: PASS.

- [ ] **Step 6: Architecture boundary check**

Run: `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
Expected: PASS — the Authorization module source set still has zero `Modules\Audit\Contracts\*` imports; the adapter sits inside the Audit module and is the only allowed reverse-edge entry.

- [ ] **Commit handoff gate**

Branch `feat/audit-shared-port-adapter`, files `apps/api/Modules/Audit/Infrastructure/Persistence/SharedRecordAuditEventAdapter.php apps/api/Modules/Audit/Providers/AuditServiceProvider.php apps/api/Modules/Audit/Tests/SharedRecordAuditEventAdapterTest.php`, message `feat(audit): add SharedRecordAuditEventAdapter implementing Shared\Contracts\RecordAuditEvent over Audit implementation`. Do NOT run `git add` or `git commit`.

---

### Task 3: System-role immutability guard + atomic role/capability mutation in the gateway (transaction-neutral)

**Files:**
- Modify: `apps/api/Modules/Authorization/Infrastructure/Persistence/AuthorizationHttpGateway.php`
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationRoleSystemImmutabilityTest.php`
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationRoleAtomicCapabilityTest.php`

**Interfaces:**
- Consumes: Task 1; existing `CapabilityCatalog::supports()` (`apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php:144`).
- Produces:
  - `gateway->transition('roles', $id, 'clone', $expectedVersion, $actorUserId)` returns a canonical role row copy with `role_type='custom', is_system_role=false, status='active', lock_version=1` and inserts a complete capability set. Does NOT call `DB::transaction`; the surrounding `AuthorizationAdminService` owns the outer transaction. Throws `InvalidArgumentException('authorization_action_invalid')` for unsupported combinations.
  - `gateway->update(...)` for `adminResource === 'roles'`: when `is_system_role = 1`, throws `InvalidArgumentException('authorization_system_role_immutable')`. On a non-system role with `capability_codes`, helper `withCapabilitySet($roleId, $codes, $actorUserId)` deletes rows no longer in the set and inserts missing allow rows; invalid codes throw `authorization_code_not_in_catalog`. The helper does NOT call `DB::transaction`; the surrounding service owns the outer transaction.
  - `gateway->transition(...)` for `adminResource === 'role-capabilities'` with `revoke`: throws `authorization_system_role_immutable` when the parent role is a system role.

- [ ] **Step 1: Failing test — system-role PATCH rejected**

`AuthorizationRoleSystemImmutabilityTest`:
- Seed a system role; call `update('roles', $uuid, ['name_ar' => 'New'], 1, $principal)`; expect `InvalidArgumentException` with message `authorization_system_role_immutable`.
- Same with `['name_ar' => 'New','capability_codes' => ['work_record.read']]`; same expectation.

- [ ] **Step 2: Failing test — clone creates a new custom role with capability set**

- Insert two allow rows in `role_capabilities` for `['work_record.read','identity.account.read']`.
- Call `transition('roles', $systemId, 'clone', 1, $principal)` inside an outer `DB::transaction` (the test is responsible for the transaction; the gateway must NOT start its own).
- Assert returned row has fresh `id`, `role_type='custom'`, `is_system_role=false`, `lock_version=1`, `status='active'`; new role has two capability rows; source untouched.

- [ ] **Step 3: Failing test — atomic capability set replace on custom role PATCH**

`AuthorizationRoleAtomicCapabilityTest`:
- Seed a custom role with `['work_record.read']`.
- Inside an outer `DB::transaction`, call `update('roles', $customId, ['capability_codes' => ['work_record.submit','identity.account.read']], 1, $principal)`.
- Assert the resulting capability set is exactly the new set, no orphans.
- Repeat with `['work_record.read','not.in.catalog']`; expect `InvalidArgumentException('capability_code_not_in_catalog')`.

- [ ] **Step 4: Run, verify all FAIL**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationRoleSystemImmutabilityTest.php Modules/Authorization/Tests/AuthorizationRoleAtomicCapabilityTest.php`
Expected: FAIL.

- [ ] **Step 5: Implement — transaction-neutral gateway**

Inside `AuthorizationHttpGateway`:
- `private function assertMutableRole(string $roleId): void` — throws `authorization_system_role_immutable` when `is_system_role = 1`.
- `public function withCapabilitySet(string $roleId, array $capabilityCodes, string $actorUserId): void` — validates each code via `CapabilityCatalog::supports`; resolves `capabilities.id`; deletes non-listed rows; inserts missing ones with `effect='allow', lock_version=1`, fresh timestamps. **No `DB::transaction` call.**
- In `update()`, branch `resource === 'roles'`: call `assertMutableRole($id)`; if `capability_codes` present, normalize and call `withCapabilitySet`. **No `DB::transaction` call.**
- In `transition()`, allow `actions === 'clone'` only when `resource === 'roles'`; reject otherwise with `authorization_action_invalid`. Clone branches to `cloneRole($id, $expectedVersion, $actorUserId)`: validate `is_system_role = 1` (else `authorization_clone_source_not_system_or_immutable`); insert new `roles` row prefixed `_clone-{first8}` to preserve unique `code`, copy `name_ar`, `name_en`, `role_type='custom', is_system_role=0, status='active', lock_version=1`, fresh ids; copy allow rows into `role_capabilities` for new id; return `find('roles', $newId)`. **No `DB::transaction` call.**
- In `transition()` for `role-capabilities` `revoke`: assert mutable parent role.
- Remove any existing `DB::transaction` wrappers in `create()` and `update()` (the outer transaction is owned by the service; the gateway is transaction-neutral).

- [ ] **Step 6: Run, verify pass**

Run: same as Step 4, then `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests`
Expected: PASS; no regression to existing suites.

- [ ] **Step 7: Architecture boundary check**

Run: `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/api-accounts-permissions-actionability`, files `apps/api/Modules/Authorization/Infrastructure/Persistence/AuthorizationHttpGateway.php apps/api/Modules/Authorization/Tests/AuthorizationRoleSystemImmutabilityTest.php apps/api/Modules/Authorization/Tests/AuthorizationRoleAtomicCapabilityTest.php`, message `feat(authorization): enforce system-role immutability, atomic role capability_codes write, role clone action (transaction-neutral gateway)`. Do NOT run `git add` or `git commit`.

---

### Task 4: `AuthorizationAdminService` + controller wiring — ONE outer transaction per mutation

**Files:**
- Create: `apps/api/Modules/Authorization/Features/Administration/Application/AuthorizationAdminService.php`
- Create: `apps/api/Modules/Authorization/Features/Administration/Contracts/RoleMutationAuditContext.php`
- Modify: `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`
- Modify: `apps/api/Modules/Authorization/Http/AuthorizationApi.php`
- Modify: `apps/api/Modules/Authorization/Providers/AuthorizationServiceProvider.php`
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationAdminServiceAuditTest.php`
- Test: `apps/api/Modules/Authorization/Tests/AuthorizationAdminControllerAuditTest.php`

**Interfaces:**
- Consumes: Task 3 gateway (transaction-neutral); Task 2 `Shared\Contracts\RecordAuditEvent` (bound by the AuditServiceProvider in Task 2); existing `CapabilityCatalog::supports()`; existing `AuthorizationApi`.
- Produces:
  - `AuthorizationAdminService::{createRole, editRole, archiveRole, cloneRole, createAssignment, updateAssignment, revokeAssignment, expireAssignment, revokeRoleCapability}` each return `array{entity: array, audit: array}`; each opens ONE outer `DB::transaction` that calls the gateway mutator(s) and emits exactly one audit event with minimized before/after snapshots. If the audit double throws inside the transaction, the outer `DB::transaction` rolls back and no role row, capability row, or audit-event row is written.
  - `AuthorizationAdminController` injects the service; delegates `/authorization/roles/{resourceId}/clone` to `cloneRole` (which forwards the source `If-Match` as the `lockVersion`); for `revoke`/`expire` on `role-assignments` and `revoke` on `role-capabilities` the controller does not require a body and does not validate `reason`. The controller never opens a `DB::transaction`; the service is the sole owner.
  - `AuthorizationApi::resource(...)` extended to attach `allowed_actions` for `role`, `role_assignment`, `role_capability` responses from a per-resource matrix.

- [ ] **Step 1: Failing test — service rolls back when audit double throws after mutation**

`AuthorizationAdminServiceAuditTest` uses a `Shared\Contracts\RecordAuditEvent` double that throws ONLY after the role/capability rows have been written (the double's `record()` is invoked post-mutation, pre-commit). For each method (`createRole`, `editRole`, `archiveRole`, `cloneRole`, `createAssignment`, `updateAssignment`, `revokeAssignment`, `expireAssignment`, `revokeRoleCapability`):
- Asserts that after the throwing call, the `roles` / `role_capabilities` / `role_assignments` tables show NO new rows for the mutation's subject (full rollback).
- Asserts that without the throw, the capturing double receives exactly one event with `actor_id = principalId`, `source_module = 'authorization'`, expected `action` token (`authorization.role.created|updated|archived|cloned|assignment.created|assignment.updated|assignment.revoked|assignment.expired|role_capability.revoked`), `outcome = 'succeeded'`, minimized `context.before`/`context.after`.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAdminServiceAuditTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `AuthorizationAdminService`**

Pattern:

```php
<?php
declare(strict_types=1);
namespace Modules\Authorization\Features\Administration\Application;

use DateTimeImmutable; use DateTimeZone;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Shared\Contracts\RecordAuditEvent;

final class AuthorizationAdminService
{
    public function __construct(
        private readonly AuthorizationHttpGateway $gateway,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @return array{entity: array<string,mixed>, audit: array<string,mixed>} */
    public function createRole(array $input, string $actorUserId, string $correlationId, ?string $idempotencyKey = null): array { … }
    public function editRole(string $roleId, array $patch, int $lockVersion, string $actorUserId, string $correlationId): array { … }
    public function archiveRole(string $roleId, int $lockVersion, string $actorUserId, string $correlationId): array { … }
    public function cloneRole(string $sourceRoleId, int $expectedVersion, array $overrides, string $actorUserId, string $correlationId): array { … }
    public function createAssignment(array $input, string $actorUserId, string $correlationId): array { … }
    public function updateAssignment(string $assignmentId, array $patch, int $lockVersion, string $actorUserId, string $correlationId): array { … }
    public function revokeAssignment(string $assignmentId, int $lockVersion, string $actorUserId, string $correlationId): array { … }
    public function expireAssignment(string $assignmentId, int $lockVersion, string $actorUserId, string $correlationId): array { … }
    public function revokeRoleCapability(string $roleCapabilityId, int $lockVersion, string $actorUserId, string $correlationId): array { … }

    public function createRole(array $input, …): array {
        return DB::transaction(function () use (…) {
            $entity = $this->gateway->create('roles', $input, $actorUserId);
            $this->emit('authorization.role.created', 'com.cluster.authorization.role.created.v1', $actorUserId, 'role', $entity['id'], $correlationId, 'succeeded', ['before' => null, 'after' => $this->minimize($entity)]);
            return ['entity' => $entity, 'audit' => ['action' => 'authorization.role.created']];
        });
    }
    // … analogous for the other methods …
}
```

Rules: every public method begins with `return DB::transaction(function () { … });`. The transaction body calls the gateway mutator(s) and THEN `emit($action, $eventType, $actorUserId, $subjectType, $subjectId, $correlationId, 'succeeded', ['before' => $before, 'after' => $after])`. The audit double throwing inside the transaction causes the outer transaction to roll back; this is verified by Task 4 Step 1.

- [ ] **Step 4: Implement controller wiring**

`AuthorizationAdminController::__invoke()`:
- Recognizes the generic `/authorization/{adminResource}/{resourceId}/{authorizationAction}` route when `adminResource === 'roles' && authorizationAction === 'clone'`. The controller forwards to `$service->cloneRole($resourceId, AuthorizationApi::ifMatch($request) ?? 0, $body, $principal['user_id'], $correlationId)`. 400 returned if `If-Match` is missing.
- Existing `create`/`patch`/`transition` cases delegate to the service. No body is required for `revoke`/`expire` on `role-assignments` and `revoke` on `role-capabilities`. The controller NEVER opens a `DB::transaction`.
- `AuthorizationApi::resource(...)` accepts optional `array $allowedActions = null` and appends `allowed_actions` for `role`, `role_assignment`, `role_capability` envelopes.

- [ ] **Step 5: Bind in `AuthorizationServiceProvider::register()`**

```php
$this->app->bind(
    \Modules\Authorization\Features\Administration\Application\AuthorizationAdminService::class,
    fn ($app) => new \Modules\Authorization\Features\Administration\Application\AuthorizationAdminService(
        $app->make(\Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway::class),
        $app->make(\Shared\Contracts\RecordAuditEvent::class),
    ),
);
```

The `Shared\Contracts\RecordAuditEvent` binding is owned by `AuditServiceProvider` per Task 2. No `Modules\Audit\Contracts\*` import.

- [ ] **Step 6: Failing test — controller issues exactly one audit event per mutation**

`AuthorizationAdminControllerAuditTest`:
- Counting `Shared\Contracts\RecordAuditEvent` test double in `setUp()`.
- Exercises `postJson('/api/v1/authorization/roles', $body, $headers)` with `Idempotency-Key`; `patchJson('/api/v1/authorization/roles/{id}', $body, $mergePatchHeaders)` (uses `Content-Type: application/merge-patch+json` + `If-Match: "1"`); `postJson('/api/v1/authorization/roles/{src}/clone', $body, $cloneHeaders)` (missing `If-Match` → 400; with `If-Match: "1"` → 200); assignment transitions.
- Asserts response 201/200; capturing double has exactly one event per request; event `subject_id === entity.id`; synthetic failure (double throws after the gateway mutation) → 500 + entity tables unchanged.

- [ ] **Step 7: Run, verify pass**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAdminServiceAuditTest.php Modules/Authorization/Tests/AuthorizationAdminControllerAuditTest.php`
Expected: PASS.

- [ ] **Step 8: Architecture boundary check**

Run: `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`
Expected: PASS.

- [ ] **Step 9: Run full authorization test suite**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests`
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/api-accounts-permissions-actionability`, files `apps/api/Modules/Authorization/Features/Administration/Application/AuthorizationAdminService.php apps/api/Modules/Authorization/Features/Administration/Contracts/RoleMutationAuditContext.php apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php apps/api/Modules/Authorization/Http/AuthorizationApi.php apps/api/Modules/Authorization/Providers/AuthorizationServiceProvider.php apps/api/Modules/Authorization/Tests/AuthorizationAdminServiceAuditTest.php apps/api/Modules/Authorization/Tests/AuthorizationAdminControllerAuditTest.php`, message `feat(authorization): admin service owns one transactional audit per role/assignment mutation through Shared port`. Do NOT run `git add` or `git commit`.

---

### Task 5: API tests — full happy-path and failure matrix (clone with required If-Match, atomic transaction rollback, PATCH merge-patch + If-Match)

**Files:**
- Create: `apps/api/Modules/Authorization/Tests/AuthorizationAccountPermissionsHttpAdapterTest.php`

**Interfaces:**
- Consumes: Tasks 1–4; existing `DevelopmentJourneyAuthorizationSeeder`; existing facility-only-admin fixture.
- Produces: green test that proves the evidence listed in spec §17.

- [ ] **Step 1: Failing test — daily web-equivalent flow end-to-end**

`AuthorizationAccountPermissionsHttpAdapterTest` proves:
- `POST /api/v1/authorization/roles` with `capability_codes`, `Idempotency-Key` → 201; `role_capabilities` rows count equals 2; `audit_events` has exactly one row with `action='authorization.role.created'`, `subject_id = entity.id`, `actor_id = principal`, `outcome='succeeded'`.
- Same `Idempotency-Key` returns same response; zero new rows.
- `PATCH /api/v1/authorization/roles/{id}` with `Content-Type: application/merge-patch+json`, `If-Match: "1"`, body `{ "status": "archived" }` → 200; `lock_version=2`; one audit `authorization.role.archived`.
- `POST /api/v1/authorization/roles/{src}/clone` MISSING `If-Match` → 400; with `If-Match: "1"` (source `lock_version`) → 200; new role `role_type='custom'`, `is_system_role=0`, same capability set, fresh id; one audit `authorization.role.cloned`, `subject_id` new id; source role and source capability rows untouched.
- PATCH on a system role (with `If-Match` and `Content-Type: application/merge-patch+json`) → 409 with `Content-Type: application/problem+json` and `type=urn:cluster:problem:system-role-immutable`; zero new audit rows.
- `POST /api/v1/authorization/role-assignments` with `Idempotency-Key` → 201; one audit `authorization.assignment.created`; `role_assignment.allowed_actions` includes `revoke, expire, edit`.
- `POST /api/v1/authorization/role-assignments/{id}/revoke` with empty body → 200; one audit `authorization.assignment.revoked`; no `reason` stored on the resource.
- `POST /api/v1/authorization/role-assignments/{id}/expire` with empty body → 200; one audit `authorization.assignment.expired`.
- `PATCH /api/v1/authorization/role-assignments/{id}` with `Content-Type: application/merge-patch+json`, `If-Match: "1"`, body `{ "scope_id": "<another unit>" }` → 200; one audit `authorization.assignment.updated`.
- Out-of-scope revoke (use the facility-only-admin fixture against an assignment in `UNIT_B`) → 403 with standard envelope; URL state intact.

- [ ] **Step 2: Run, verify pass**

Run: `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAccountPermissionsHttpAdapterTest.php`
Expected: PASS.

- [ ] **Step 3: Verify-boundaries + existing adapter regression**

Run: `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php` then `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationPolicyAdminHttpAdapterTest.php`
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/api-accounts-permissions-actionability`, files `apps/api/Modules/Authorization/Tests/AuthorizationAccountPermissionsHttpAdapterTest.php`, message `test(authorization): end-to-end daily web-equivalent authorization administration flow with transactional audit assertions`. Do NOT run `git add` or `git commit`.

---

### Task 6: Web domain wrapper — typed wrappers for role/assignment reads + mutations (no new effective-capability query)

**Files:**
- Generated: `apps/web/src/api/generated/cluster.ts` (already regenerated in Task 1)
- Modify: `apps/web/src/api/r1.ts`
- Modify: `apps/web/src/api/http.ts` (only if a `mergePatch` helper does not already exist)
- Test: `apps/web/src/api/r1.test.ts`

**Interfaces:**
- Consumes: Task 1 generated types; `RequestInit` helpers in `apps/web/src/api/http.ts`; existing `parseStrongEtag`, `unwrap`, `requestInit`.
- Produces typed wrappers consumed by every later web task. The exact declared names (single source of truth) are:
  - `cloneRoleFromSystemRole(token, sourceRoleId, overrides?, lockVersion): Promise<generated.AuthorizationRole>` — POSTs to the generic `/api/v1/authorization/roles/{id}/clone` with `If-Match: "{lockVersion}"`; missing `lockVersion` throws before the request fires.
  - `createRole(token, input: generated.AuthorizationAdminCreate & {capability_codes?: string[]}): Promise<generated.AuthorizationRole>` — POST `/api/v1/authorization/roles` with `Idempotency-Key`. No `reason` field is sent.
  - `updateRole(token, roleId, patch: generated.AuthorizationAdminPatch & {capability_codes?: string[]}, lockVersion): Promise<generated.AuthorizationRole>` — PATCH `/api/v1/authorization/roles/{id}` with `Content-Type: application/merge-patch+json` and `If-Match: "{lockVersion}"`.
  - `archiveRole(token, roleId, lockVersion): Promise<generated.AuthorizationRole>` — PATCH `/api/v1/authorization/roles/{id}` with `application/merge-patch+json`, `If-Match`, body `{status:'archived'}`. No `reason` parameter.
  - `createRoleAssignment(token, input: generated.AuthorizationAdminCreate): Promise<generated.AuthorizationRoleAssignment>` — POST `/api/v1/authorization/role-assignments` with `Idempotency-Key`. No `reason` field is sent.
  - `updateRoleAssignment(token, assignmentId, patch: generated.AuthorizationAdminPatch, lockVersion): Promise<generated.AuthorizationRoleAssignment>` — PATCH `/api/v1/authorization/role-assignments/{id}` with `application/merge-patch+json` and `If-Match: "{lockVersion}"`. No `reason` parameter.
  - `revokeRoleAssignment(token, assignmentId, lockVersion): Promise<generated.AuthorizationRoleAssignment>` — POST `/api/v1/authorization/role-assignments/{id}/revoke` with no body; `If-Match: "{lockVersion}"`. No `reason` parameter.
  - `expireRoleAssignment(token, assignmentId, lockVersion): Promise<generated.AuthorizationRoleAssignment>` — POST `/api/v1/authorization/role-assignments/{id}/expire` with no body; `If-Match: "{lockVersion}"`. No `reason` parameter.
  - `revokeRoleCapability(token, roleId, capabilityId, lockVersion): Promise<R1Entity>` — POST `/api/v1/authorization/role-capabilities/{roleId}:{capabilityId}/revoke` with `If-Match: "{lockVersion}"`. No `reason` parameter.
  - `listRoles(token, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationRole[]>` — GET `/api/v1/authorization/roles` with cursor/limit/search filters; returns the typed role projection.
  - `getRole(token, roleId): Promise<generated.AuthorizationRole>` — GET `/api/v1/authorization/roles/{id}`; returns the canonical role with `lock_version` and `allowed_actions`.
  - `listCapabilities(token, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationCapability[]>` — GET `/api/v1/authorization/capabilities`; returns the typed capability catalog projection.
  - `listRoleAssignments(token, filters?: generated.ListAuthorizationAdminResourcesParams): Promise<generated.AuthorizationRoleAssignment[]>` — GET `/api/v1/authorization/role-assignments`; returns the typed role-assignment projection.
  - The inspector tab reuses the existing `simulateAccessDecision` and `explainAccessDecision` wrappers from `apps/web/src/api/r1.ts` (no new inspector wrapper). Contract response schemas for those endpoints are updated in Task 1 to expose `applies_in_plain_language`, `assignment_summaries`, `policy_references`, and `correlation_id` consumed by the inspector UI.
  - Existing `transitionAuthorizationAdminResource` is updated to support `'clone'` for `roles`, with `If-Match` forwarded.

- [ ] **Step 1: Failing test — wrapper types & no-reason transitions**
- Each of the wrappers above resolves under a 2xx response; the four read wrappers (`listRoles`, `getRole`, `listCapabilities`, `listRoleAssignments`) hit the generic `/api/v1/authorization/{adminResource}` endpoints and return the typed projections; no `reason` body is sent for create/archive/revoke/expire on role-assignments or roles; archive URL is `PATCH /api/v1/authorization/roles/{id}` with `Content-Type: application/merge-patch+json`, `If-Match`, and body `{status:'archived'}`; `revokeRoleCapability` POSTs to `…/role-capabilities/{id}/revoke`; `cloneRoleFromSystemRole` requires `If-Match` for both the source-version and the no-source-version cases; `updateRoleAssignment` sends `Content-Type: application/merge-patch+json` + `If-Match`.

`apps/web/src/api/r1.test.ts`:
- Stub the generated fetch via Orval mutator; mirror the pattern in `apps/web/src/api/api.test.ts`.
- The four read wrappers (`listRoles`, `getRole`, `listCapabilities`, `listRoleAssignments`) hit the generic `/api/v1/authorization/{adminResource}` endpoints and return the typed projections; no `reason` body is sent for create/archive/revoke/expire on role-assignments or roles; archive URL is `PATCH /api/v1/authorization/roles/{id}` with `Content-Type: application/merge-patch+json`, `If-Match`, and body `{status:'archived'}`; `revokeRoleCapability` POSTs to `…/role-capabilities/{id}/revoke`; `cloneRoleFromSystemRole` requires `If-Match` for both the source-version and the no-source-version cases; `updateRoleAssignment` sends `Content-Type: application/merge-patch+json` + `If-Match`.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/api/r1.test.ts`
Expected: FAIL.

- [ ] **Step 3: Implement wrappers in `r1.ts`**

Use `requestInit(token, { command: true, lockVersion, idempotencyKey?, mergePatch?: true })` from `apps/web/src/api/http.ts`. The `command: true` flag sets `Idempotency-Key` to a generated UUIDv7 if not provided. The `mergePatch: true` flag sets `Content-Type: application/merge-patch+json`. Both helpers exist in `apps/web/src/api/http.ts` (or are added there as part of this task). No `reason` parameter or body field is added to any in-scope wrapper.

- [ ] **Step 4: Run, verify pass**

Run: same as Step 2.
Expected: PASS.

- [ ] **Step 5: Generated-client parity**

Run: `npm --prefix apps/web run api:check`
Expected: exit 0.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/src/api/r1.ts apps/web/src/api/r1.test.ts apps/web/src/api/http.ts`, message `feat(web): typed wrappers for role/assignment reads (listRoles, getRole, listCapabilities, listRoleAssignments) and mutations with no-reason create/archive/revoke/expire and updateRoleAssignment`. Do NOT run `git add` or `git commit`.

---

### Task 7: Web IA — `AccountsPermissionsWorkspace` and the five daily/advanced tabs (canonical `decision-inspector` key)

**Files:**
- Create: `apps/web/src/features/accounts-permissions/AccountsPermissionsWorkspace.tsx`
- Create: `apps/web/src/features/accounts-permissions/AccountsTab.tsx`
- Create: `apps/web/src/features/accounts-permissions/RolesPermissionsTab.tsx`
- Create: `apps/web/src/features/accounts-permissions/RoleAssignmentsTab.tsx`
- Create: `apps/web/src/features/accounts-permissions/PoliciesScopesTab.tsx`
- Create: `apps/web/src/features/accounts-permissions/PermissionDecisionInspector.tsx`
- Create: `apps/web/src/features/accounts-permissions/canMutateAdminResource.ts`
- Create: `apps/web/src/features/accounts-permissions/AuthorizationMutationFeedback.tsx`
- Modify: `apps/web/src/features/authorization/AccessWorkspace.tsx`
- Modify: `apps/web/src/app/workspace-routes.tsx` (route import only)
- Test: `apps/web/src/features/accounts-permissions/AccountsPermissionsWorkspace.test.tsx`
- Test: `apps/web/src/features/accounts-permissions/canMutateAdminResource.test.ts`

**Interfaces:**
- Consumes: Task 6 wrappers (the four read wrappers `listRoles`, `getRole`, `listCapabilities`, `listRoleAssignments` and the mutation wrappers `cloneRoleFromSystemRole`, `createRole`, `updateRole`, `archiveRole`, `createRoleAssignment`, `updateRoleAssignment`, `revokeRoleAssignment`, `expireRoleAssignment`, `revokeRoleCapability`); existing `WorkspaceTabs` (`apps/web/src/app/WorkspaceTabs.tsx`); existing `IdentityAccounts` (`apps/web/src/features/identity/IdentityAccounts.tsx`) and `listUserAccounts` from `apps/web/src/api/identity.ts`; existing `simulateAccessDecision` and `explainAccessDecision` from `apps/web/src/api/r1.ts` for the inspector.
- Produces: a route-aware `AccountsPermissionsWorkspace` that owns tab state and renders five sections (basic: `accounts`, `roles-permissions`, `role-assignments`; advanced: `policies-scopes`, `decision-inspector`). The canonical tab-key type is:

  ```ts
  export type AccountPermissionsTabKey =
    | 'accounts' | 'roles-permissions' | 'role-assignments'
    | 'policies-scopes' | 'decision-inspector';
  ```

  The `?tab=` query param uses the same tokens. The advanced tabs:
  - visible "Advanced" eyebrow;
  - unavailable state with localized, plain-language copy; never raw 403 or blank panel;
  - deep-link to an unavailable tab renders the same unavailable state, not a router error.

- [ ] **Step 1: Failing test — `canMutateAdminResource` matrix**

`canMutateAdminResource.test.ts`:
- `canMutateAdminResource('roles-permissions', 'create', ['authorization.role.manage'])` → true; with only `authorization.role.read` → false.
- `canMutateAdminResource('roles-permissions', 'clone', ['authorization.role.manage'])` on a system role → true (respects `allowed_actions` if present).
- `canMutateAdminResource('role-assignments', 'revoke', ['authorization.assignment.manage'])` → true; only `authorization.assignment.read` → false.
- `canMutateAdminResource('role-assignments', 'expire', ['authorization.assignment.manage'])` → true.
- `canMutateAdminResource('roles-permissions', 'revoke', ['authorization.role.manage'])` → true. (The `role-capabilities` tab key was renamed to `roles-permissions` in §4; legacy keys are not accepted by `canMutateAdminResource`.)

- `canMutateAdminResource('policies-scopes', 'edit', ['authorization.policy.manage'])` → true; without it → false. `tabAvailableFor('policies-scopes', [])` → false; with `authorization.policy.read` → true.
- `canMutateAdminResource('decision-inspector', 'create', ['authorization.decision.read'])` → true; without it → false.
- `canMutateAdminResource('roles-permissions', 'advanced_disclosure', ['authorization.capability.read'])` → true.

- [ ] **Step 2: Implement `canMutateAdminResource`**

```ts
// apps/web/src/features/accounts-permissions/canMutateAdminResource.ts
export type AccountPermissionsTabKey =
  | 'accounts' | 'roles-permissions' | 'role-assignments'
  | 'policies-scopes' | 'decision-inspector';

export type AdminMutation =
  | 'create' | 'edit' | 'clone' | 'archive' | 'revoke' | 'expire'
  | 'assign' | 'view_assignments' | 'grant' | 'retract' | 'advanced_disclosure';

const REQUIREMENTS: Record<AccountPermissionsTabKey, Record<AdminMutation, string | null>> = {
  accounts:                   { create:'identity.account.manage',      edit:null,            clone:null, archive:null, revoke:null, expire:null,                                              assign:'authorization.assignment.manage',  view_assignments:'authorization.assignment.read', grant:null, retract:null, advanced_disclosure:null },
  'roles-permissions':        { create:'authorization.role.manage',   edit:'authorization.role.manage', clone:'authorization.role.manage', archive:'authorization.role.manage', revoke:null, expire:null,                                                                                                                        assign:null,                               view_assignments:'authorization.role.manage', grant:'authorization.role.manage', retract:'authorization.role.manage', advanced_disclosure:'authorization.capability.read' },
  'role-assignments':         { create:'authorization.assignment.manage', edit:'authorization.assignment.manage', clone:null, archive:null, revoke:'authorization.assignment.manage', expire:'authorization.assignment.manage', assign:null, view_assignments:'authorization.assignment.read', grant:null, retract:null, advanced_disclosure:null },
  'policies-scopes':          { create:'authorization.policy.manage', edit:'authorization.policy.manage', clone:null, archive:'authorization.policy.manage', revoke:null, expire:null,                                                                                                                          assign:null,                               view_assignments:'authorization.policy.read', grant:null, retract:null, advanced_disclosure:null },
  'decision-inspector':       { create:'authorization.decision.read', edit:null, clone:null, archive:null, revoke:null, expire:null,                                                                                                                              assign:null,                               view_assignments:null, grant:null, retract:null, advanced_disclosure:null },
};

export function canMutateAdminResource(
  tab: AccountPermissionsTabKey,
  action: AdminMutation,
  capabilities: readonly string[],
  allowedActions?: readonly string[] | null,
): boolean {
  const required = REQUIREMENTS[tab][action];
  if (required !== null && !capabilities.includes(required)) return false;
  if (allowedActions && allowedActions.length > 0 && !allowedActions.includes(action)) return false;
  return true;
}

export function tabAvailableFor(tab: AccountPermissionsTabKey, capabilities: readonly string[]): boolean {
  if (tab === 'accounts') return capabilities.includes('identity.account.read') || capabilities.includes('authorization.assignment.read');
  if (tab === 'roles-permissions') return capabilities.includes('authorization.role.read') || capabilities.includes('authorization.role.manage');
  if (tab === 'role-assignments') return capabilities.includes('authorization.assignment.read') || capabilities.includes('authorization.assignment.manage');
  if (tab === 'policies-scopes') return capabilities.includes('authorization.policy.read') || capabilities.includes('authorization.policy.manage');
  if (tab === 'decision-inspector') return capabilities.includes('authorization.decision.read');
  return false;
}
```

- [ ] **Step 3: Failing test — workspace renders five tabs + advanced-tab unavailable state**

`AccountsPermissionsWorkspace.test.tsx`:
- Default principal with the necessary capabilities: renders five tabs in spec order; click each tab → corresponding panel mounts.
- Principal without `authorization.decision.read`: clicking the inspector tab (or deep-link `?tab=decision-inspector`) renders an `EmptyState` with English text "This advanced tool is not available to your account." and AR `"هذه الأداة المتقدمة غير متاحة لحسابك."`; no UUID/JSON leak (`screen.queryByText(/[0-9a-f]{8}-/i)` is null).
- Principal without `authorization.policy.manage` and `authorization.policy.read`: clicking `policies-scopes` renders unavailable state; legacy `AccessScopesScreen` is not mounted.
- `dir` attribute is `rtl` when locale is `ar`, `ltr` when `en`.

- [ ] **Step 4: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/features/accounts-permissions/AccountsPermissionsWorkspace.test.tsx src/features/accounts-permissions/canMutateAdminResource.test.ts`
Expected: FAIL.

- [ ] **Step 5: Implement components**

`AccountsPermissionsWorkspace.tsx`:
- Props: `{ locale, activeTab, onTabChange, capabilities, allowedActionsByRole, navigate }`.
- URL state in `?tab=` query param (canonical tokens: `accounts`, `roles-permissions`, `role-assignments`, `policies-scopes`, `decision-inspector`).
- Renders `<WorkspaceTabs … />` with the five tabs in spec order.
- Advanced tabs (`policies-scopes`, `decision-inspector`) carry a visible `Advanced` eyebrow.
- Provides a shared `AuthorizationMutationFeedback` portal-bound live region (`role="status"`, `aria-live="polite"`).

`AccountsTab.tsx`: reuses `IdentityAccounts`; exposes a "Manage assignments" button when `canMutateAdminResource('accounts','assign', capabilities)` is true, routing to `RoleAssignmentsTab` with `?account=` pre-filled.

`RolesPermissionsTab.tsx`: renders a role catalog using `listRoles` and queries `getRole` for the row detail; capability set is fetched via `listCapabilities` filtered by the role's `capability_codes`; the editor uses the Task 6 mutation wrappers; per-row:
- System role row: `Clone as custom role` when capability allows; opens editor prefilled with the system role's name, description, capability set; the editor's submit handler uses `cloneRoleFromSystemRole` with the source `If-Match`.
- Custom role row: `Edit role` when capability allows.
- Basic detail MUST NOT render UUIDs / capability_id strings / JSON; only `code`, `name`, `description`, `capability_count`. Advanced disclosure (button) reveals stable `capability_code` strings only when `authorization.capability.read` is held.

`RoleAssignmentsTab.tsx`: reads via `listRoleAssignments`; mutations use `createRoleAssignment`, `updateRoleAssignment`, `revokeRoleAssignment`, `expireRoleAssignment`, `revokeRoleCapability` from Task 6; the assignment form has account / role / scope level / scope target fields, constrained by actor's manageable scope and selected role. Scope update uses `updateRoleAssignment` with `Content-Type: application/merge-patch+json` + `If-Match`. Same-row destructive-control confirmation (no separate workflow, no `reason`).

`PoliciesScopesTab.tsx`: three sub-sections via internal subnavigation (links only): "Classification policies", "Field-access templates", "Access scopes". Read controls depend on `authorization.policy.read`; write controls depend on `authorization.policy.manage` AND the server `allowed_actions`. No new capability is introduced.

`PermissionDecisionInspector.tsx`: inputs for subject / capability / resource-type / authorized context; submits via the existing `simulateAccessDecision` and `explainAccessDecision` wrappers from `apps/web/src/api/r1.ts` (no new inspector wrapper). The Contract response schemas for these endpoints are updated in Task 1 to expose `applies_in_plain_language`, `assignment_summaries`, `policy_references`, and `correlation_id`, which the inspector renders in a copyable `<output>`. Never calls any mutation route.

`AuthorizationMutationFeedback.tsx`: context provider `useAuthorizationMutationFeedback()` returning `announce({variant, scope, message, fields?})`. Writes to the `role="status"` live region; focuses it on errors only. 412/version conflict surfaces "Reload" + "Retry" and calls the typed `getRole`/`getAssignment`.

- [ ] **Step 6: Run, verify pass**

Run: same as Step 4.
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/src/features/accounts-permissions/AccountsPermissionsWorkspace.tsx apps/web/src/features/accounts-permissions/AccountsTab.tsx apps/web/src/features/accounts-permissions/RolesPermissionsTab.tsx apps/web/src/features/accounts-permissions/RoleAssignmentsTab.tsx apps/web/src/features/accounts-permissions/PoliciesScopesTab.tsx apps/web/src/features/accounts-permissions/PermissionDecisionInspector.tsx apps/web/src/features/accounts-permissions/canMutateAdminResource.ts apps/web/src/features/accounts-permissions/AuthorizationMutationFeedback.tsx apps/web/src/features/authorization/AccessWorkspace.tsx apps/web/src/app/workspace-routes.tsx apps/web/src/features/accounts-permissions/AccountsPermissionsWorkspace.test.tsx apps/web/src/features/accounts-permissions/canMutateAdminResource.test.ts`, message `feat(web): Accounts & Permissions workspace with five daily/advanced tabs and capability/allowed_actions gating`. Do NOT run `git add` or `git commit`.

---

### Task 7A: React scoped form — native `<fieldset>` radio assignment scope picker, local `useAssignmentScopeTargets` hook, `record_set` disabled with localized helper (spec §19.7)

**Files:**
- Create: `apps/web/src/features/accounts-permissions/AssignmentScopePicker.tsx`
- Create: `apps/web/src/features/accounts-permissions/useAssignmentScopeTargets.ts`
- Modify: `apps/web/src/features/accounts-permissions/RoleAssignmentsTab.tsx` (mount the picker in the create form and pre-populate it in the edit form; fix the wire payload where the discriminator was sent in the wrong hyphen-delimited form; surface the document-correct underscore-delimited discriminator; pass the generated `lock_version` for PATCH/actions)
- Modify: `apps/web/src/features/accounts-permissions/copy.ts` (add localized level labels, the disabled `record_set` helper, the empty state, and the helper-paragraph id token)
- Test: `apps/web/src/features/accounts-permissions/AssignmentScopePicker.test.tsx`
- Test: `apps/web/src/features/accounts-permissions/useAssignmentScopeTargets.test.tsx`
- Test (fix): `apps/web/src/features/accounts-permissions/RoleAssignmentsTab.test.tsx` (covers the wrong hyphen-delimited discriminator regression, the stale-response ordering invariant, and the edit-flow initial value)

**Interfaces:**
- Consumes: Task 1C wrapper `listAssignmentScopeTargets(token, params): Promise<{ items: generated.AssignmentScopeTarget[]; next_cursor: string | null }>`; Task 7 workspace tab state; existing `directionForLocale` (`apps/web/src/app/copy.ts`); existing `EmptyState` and `Field` primitives. The generated `AuthorizationRoleAssignment` exposes `lock_version` (Task 1A); the create form submits `resource_type: 'role_assignment'` (the document-correct underscore-delimited discriminator; the prior hyphen-delimited form is removed).
- Produces:
  - `useAssignmentScopeTargets` is a **local React hook** built on `useState` + `useEffect` + `useCallback` and a monotonically increasing request epoch with an `AbortController`. It does NOT import or use any external data-cache library. The hook accepts the same `{ scope_type, parent_scope_type?, parent_scope_id?, search?, cursor?, limit? }` argument shape the wrapper accepts and returns the flat `{ items, next_cursor }` envelope unchanged. Aborting the previous request when a new request fires is the only mechanism that prevents stale responses from overwriting current filters; the hook MUST advance the epoch on every effect run and MUST reject the resolved value when the controller's signal is aborted or when the local epoch no longer matches the captured one.
  - The hook deduplicates paginated rows by `(scope_type, scope_id)` across appends so a token-induced page overlap cannot produce duplicate `label_ar`/`label_en` entries in the rendered list. Initial request: hook sets `items: []`, `next_cursor: null`, `state: 'loading'`. Successful page: `state: 'ready'`, `items` replaced (first page) or appended (subsequent pages), `next_cursor` propagated. Final page (`next_cursor: null`): `state: 'ready'`, no further fetches. Network failure: `state: 'error'`, `error` populated from the `ApiError` message; the previous page's `items` stay visible until the user retries. The hook NEVER forwards `scope_type='record_set'` to the wrapper: if a caller passes it, the hook returns `{ items: [], next_cursor: null, state: 'ready' }` in the same tick without firing a fetch and without ever surfacing an alerting UI (toast, banner, or alert). The hook NEVER silently drops the request; the disabled-level branch is part of the documented contract, not a hidden guard.
  - `AssignmentScopePicker` is a **controlled** component. Its props carry `value: { scope_type, scope_id } | null`, `onChange`, `locale`, `token`, `canAssign: boolean`, and an optional `initialAncestry: { scope_type, scope_id }[]` for the edit flow. The level selector is rendered as a native `<fieldset>` with four `<input type="radio">` controls in spec order: `cluster`, `facility`, `unit`, `record_set`. The first three are enabled; `record_set` is rendered with `disabled` and `aria-describedby="assignment-scope-record-set-helper"` pointing to a visible `<p id="assignment-scope-record-set-helper">` whose text is the localized helper `"Record-set scope is not yet available."` / `"نطاقات مجموعة السجلات غير متاحة بعد."`. The helper text does not name internal identifiers, evaluator mechanics, or other catalog internals. There is no custom Select / per-option component — the picker is plain HTML `<fieldset>` / `<legend>` / `<input type="radio">` / `<label>` and renders the four radios in the same DOM order on every locale.
  - Cascade and reset/preserve invariants:
    1. The picker starts at `cluster` with no parent and no selected target. The cluster radio is the default.
    2. Selecting `cluster` narrows the next fetch to `parent_scope_type=undefined, parent_scope_id=undefined` and the radio group's saved target clears if the previous level was `facility` or `unit`.
    3. Selecting `facility` requires the cluster radio to have a saved target; the picker refuses to advance and surfaces the localized helper if the user tries. Once a cluster is chosen, selecting `facility` narrows the next fetch to `parent_scope_type='cluster', parent_scope_id=<selected cluster id>` and the saved facility target clears if the previous level was `unit`.
    4. Selecting `unit` requires both `cluster` and `facility` to be chosen; the picker refuses to advance otherwise. Once both are chosen, selecting `unit` narrows the next fetch to `parent_scope_type='facility', parent_scope_id=<selected facility id>`.
    5. Going back from `unit` to `facility` preserves the previously chosen cluster (radio group keeps the cluster target). Going back from `facility` to `cluster` preserves earlier cluster selection. Going back from `cluster` to `record_set` is impossible because the `record_set` radio is `disabled`.
    6. Initial edit values may load the current target directly without ancestry: if `initialAncestry` is provided and the assignment's `scope_type` is `cluster`/`facility`/`unit`, the picker pre-populates the correct radio(s) and the resolved `scope_id` so the user sees the existing selection and can submit a PATCH without re-clicking the cascade. The cluster radio alone is enough for a `cluster` initial; cluster + facility for `facility`; cluster + facility + unit for `unit`.
    7. The picker's emitted `value` always carries `{ scope_type, scope_id }` where `scope_type` is one of `cluster | facility | unit` — never `record_set`. The form button is `disabled` until the picker resolves a `(scope_type, scope_id)` pair.
  - Target list rendering: when the catalog returns rows, the picker renders a visible `<ul>` of bilingual labels (`label_ar` AND `label_en` on every row), and the raw `scope_id` is consumed only as a hidden form value (rendered as `<input type="hidden" name="scope_id" value={selection.scope_id} />`). Raw `scope_id` is never visible in the document text. Selecting a target calls `onChange({ scope_type, scope_id })`.
  - Loading/error/empty/retry states: the picker mirrors the hook's three states. Loading: localized "Loading scope targets…" / "جارٍ تحميل أهداف النطاق…" inside the fieldset. Error: the fieldset shows the localized retry message and a button that re-issues the catalog fetch with the same arguments; the previous page's `items` remain visible underneath. Empty (no manageable scope or empty page): the fieldset hides the four radios and renders the localized empty state `"You do not have a manageable scope to assign to. Request cluster access to continue."` / `"ليس لديك نطاق إداري لإسناد إليه. اطلب الوصول إلى المجموعة للمتابعة."`; the disabled `record_set` radio is also hidden in this empty branch (the empty branch is the only time the picker does not render it).
  - `RoleAssignmentsTab` mounts the picker in two places: (a) inside the create form, replacing the current hard-coded `scope_type: 'cluster'` assignment, and (b) inside the edit drawer, pre-populated from the assignment's existing `scope_type`/`scope_id` and `lock_version`. The create wire payload uses `resource_type: 'role_assignment'` (the document-correct identifier). The current implementation passes the wrong hyphen-delimited form of the discriminator and a redundant `code` field — that is the regression this task fixes and `RoleAssignmentsTab.test.tsx` MUST assert the document-correct identifier. The PATCH wire call uses `updateRoleAssignment(token, assignment.id, { scope_type, scope_id, end_at }, lockVersion)` with the `lock_version` returned by the generated `AuthorizationRoleAssignment`. Revoke and expire continue to use the row's `lock_version` unchanged.
  - `copy.ts` adds the following localized strings (bilingual parity asserted by `copy.test.ts`): level labels `"Cluster"` / `"المجموعة"`, `"Facility"` / `"المنشأة"`, `"Unit"` / `"الوحدة"`, `"Record set"` / `"مجموعة السجلات"`; the disabled helper `"Record-set scope is not yet available."` / `"نطاقات مجموعة السجلات غير متاحة بعد."`; the empty state `"You do not have a manageable scope to assign to. Request cluster access to continue."` / `"ليس لديك نطاق إداري لإسناد إليه. اطلب الوصول إلى المجموعة للمتابعة."`; the loading state `"Loading scope targets…"` / `"جارٍ تحميل أهداف النطاق…"`; the retry message `"Retry scope targets"` / `"إعادة تحميل أهداف النطاق"`.
  - No new `assign` action and no `canMutateAdminResource` change. The existing `create`/`edit`/`revoke`/`expire` matrix in `canMutateAdminResource.ts` already gates the assignment form on `authorization.assignment.manage`; the picker is rendered exactly when `canMutateAdminResource('role-assignments', 'create', capabilities)` is true and the edit drawer is rendered exactly when `canMutateAdminResource('role-assignments', 'edit', capabilities, allowed_actions)` is true. No `assign` row is added to the `REQUIREMENTS` matrix and no `AdminMutation` union member is added. The picker renders native `<fieldset>` / `<input type="radio">` controls and never uses the project's `Select` primitive for the scope level.

- [ ] **Step 1: Failing test — picker shows three levels, `record_set` disabled, helper localized, native fieldset radios**

`AssignmentScopePicker.test.tsx`:
- Default cluster-administrator props: the level selector is a `<fieldset>` containing four `<input type="radio">` controls in the order `cluster`, `facility`, `unit`, `record_set`. The first three are `enabled` and in spec order; the `record_set` radio carries `disabled` and `aria-describedby="assignment-scope-record-set-helper"`, and the helper `<p>` rendered in the document reads exactly the localized copy. NO custom Select / per-option component is imported by the picker.
- Bilingual parity: when `locale='en'` the helper reads `"Record-set scope is not yet available."`; when `locale='ar'` it reads `"نطاقات مجموعة السجلات غير متاحة بعد."`. The level labels follow the same pattern.
- Selecting `unit` triggers `listAssignmentScopeTargets` with `scope_type='unit'` and the selected cluster+facility as `parent_scope_type='facility', parent_scope_id=<facility>`. The picker renders `label_ar` AND `label_en` for each row; the rendered text does not contain any raw `scope_id` (UUID) substring.
- Cascade invariant: clicking `facility` before any cluster selection does NOT advance the picker (the picker remains on the cluster radio).
- Going back from `unit` to `facility` keeps the previously chosen cluster; the cluster radio stays selected.
- Edit pre-population: when `initialAncestry={cluster, facility, unit}` is supplied, the picker renders all three radios selected with the resolved `scope_id` populated, and the form is submittable without further clicks.
- Actor with no manageable targets → the picker renders a localized empty state `<p>` and does NOT render the disabled `record_set` radio in this branch.
- `dir` attribute is `rtl` when locale is `ar`.

- [ ] **Step 2: Failing test — local hook pagination, stale-response ordering, and disabled-level invariant**

`useAssignmentScopeTargets.test.tsx`:
- Hook returns `{ items, next_cursor, state, error, loadMore, retry }` and does NOT import any external data-cache library.
- First call forwards `scope_type`, `parent_scope_type?`, `parent_scope_id?`, `search?`, `cursor?`, `limit?` exactly to the wrapper; the wrapper is invoked with the documented argument shape.
- First response `{ items: [t1, t2], next_cursor: 'opaque' }` → `loadMore()` re-issues the fetch with `cursor: 'opaque'`; second response `{ items: [t3], next_cursor: null }` → `items` is `[t1, t2, t3]` and `next_cursor` is `null`. No duplicate rows across pages.
- **Stale response ordering**: start a fetch with `scope_type='cluster'`, then immediately change the argument to `scope_type='facility'` (or any other field). The first response resolves slowly while the second resolves quickly. The hook MUST discard the slow first response and only surface the second. The post-condition is `{ items: [...facility rows], next_cursor: <facility cursor> }`. Test asserts via the wrapper's deferred-promise injection that the slow first response is observed but never applied to state. The hook uses an `AbortController` per request and increases a monotonically increasing `requestEpoch` ref on every effect run; the test mock records the `AbortController` instances and asserts the slow fetch's controller is aborted (or that the resolved value is rejected by epoch comparison) before the state update.
- `scope_type='record_set'` is never forwarded to the wrapper: the hook returns `{ items: [], next_cursor: null, state: 'ready' }` in the same tick without firing a fetch. The wrapper mock confirms zero invocations for `{ scope_type: 'record_set' }`. No alerting UI (toast, banner, or alert) is rendered by the hook or its caller.
- Network failure: `state` becomes `'error'`, `error` is the `ApiError` message; `retry()` re-issues the same fetch.
- Pagination dedupe: if a subsequent page returns a row already in `items` (same `(scope_type, scope_id)`), the final `items` list contains that row exactly once.

- [ ] **Step 3: Failing test — `RoleAssignmentsTab` create/edit wire payload, stale-response, and edit initial value**

`RoleAssignmentsTab.test.tsx` (NEW file):
- Render `RoleAssignmentsTab` with `capabilities: ['authorization.assignment.manage', 'authorization.assignment.read']` and `locale: 'en'`. Fill the create form, pick a `unit` target via the picker, submit. The captured create wire payload is `{ resource_type: 'role_assignment', subject_user_id: <account>, role_id: <role>, scope_type: 'unit', scope_id: <unit>, end_at: <...> }`. The wrong hyphen-delimited form of the discriminator is NEVER sent; the test asserts the literal `'role_assignment'` (with underscore) is present in the captured payload and the hyphen-delimited form of the discriminator is absent.
- Edit flow: the existing assignment's `scope_type='cluster'`, `scope_id=<cluster>`, `lock_version=4`. Opening the edit drawer pre-populates the picker with the cluster radio selected and the cluster target visible. The PATCH wire payload is `{ scope_type: 'cluster', scope_id: <cluster>, end_at: <...> }` and the `If-Match` header carries `"4"`.
- Stale-response ordering inside `RoleAssignmentsTab`: switching the picker from `cluster` to `unit` while a fetch is in-flight discards the cluster response and the rendered list shows only unit rows. The test asserts the rendered `<ul>` does not contain the cluster label.
- No alerting UI: the test asserts the document does not contain `[role="alert"]` or `[role="status"]` containing the string `record_set` or any warning copy when `scope_type='record_set'` is programmatically selected.
- The picker is native fieldset radios: the test asserts the picker DOM contains `<fieldset>` and `<input type="radio">` and does not contain the project's `Select` primitive for the scope level.

- [ ] **Step 4: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/features/accounts-permissions/AssignmentScopePicker.test.tsx src/features/accounts-permissions/useAssignmentScopeTargets.test.tsx src/features/accounts-permissions/RoleAssignmentsTab.test.tsx`
Expected: FAIL — components, hook, and test file do not yet exist.

- [ ] **Step 5: Implement — `useAssignmentScopeTargets` + `AssignmentScopePicker` + `RoleAssignmentsTab` mount + copy**

- `useAssignmentScopeTargets.ts`: local hook. Keep state with `useState` for `{ items, next_cursor, state, error }`. Use `useRef` to track the current `AbortController` and a monotonically increasing `requestEpoch` integer. Implement `loadMore` and `retry` with `useCallback`. Effect runs on every argument change; the effect aborts the previous controller (if any), creates a new one, increments the epoch, calls `listAssignmentScopeTargets(token, { ...args, signal: controller.signal })`, and only commits the resolved value when `signal.aborted === false` AND the captured epoch equals the current epoch. The hook NEVER forwards `scope_type='record_set'`; if the caller passes it, the hook returns the document contract in the same tick without firing a fetch and without an alerting UI.
- `AssignmentScopePicker.tsx`: controlled component. The level selector is a single `<fieldset>` with `<legend>` and four `<input type="radio">` elements in spec order. The `record_set` radio is `disabled` and exposes `aria-describedby="assignment-scope-record-set-helper"`; the helper `<p>` sits inside the same `<fieldset>` for screen-reader proximity. The picker enforces the cascade and back/preserve invariants by tracking the selected cluster and facility targets in state and refusing to advance before the prerequisites are met. The picker renders the bilingual `<ul>` of labels and a hidden `<input type="hidden" name="scope_id">` for the selected target. No custom Select / per-option component is imported.
- `RoleAssignmentsTab.tsx`: mount the picker inside the create form and the edit drawer. The create form's submit handler passes `resource_type: 'role_assignment'`, the picker-resolved `scope_type` and `scope_id`, and the optional `end_at` to `createRoleAssignment`. The edit drawer pre-populates the picker from the assignment's `scope_type`/`scope_id` and submits `updateRoleAssignment(token, assignment.id, { scope_type, scope_id, end_at }, lockVersion)` with the generated `lock_version`. The wrong hyphen-delimited form of the discriminator and the redundant `code` field are removed everywhere in this file.
- `copy.ts`: add the level labels, the disabled helper, the empty state, the loading state, and the retry message documented above. Bilingual parity is asserted by `copy.test.ts`.
- `canMutateAdminResource.ts`: NO change. The picker and the create/edit handlers are gated by the existing `create`/`edit` rows for `role-assignments`; no `assign` action is added.

- [ ] **Step 6: Run, verify pass**

Run: same as Step 4.
Expected: PASS.

- [ ] **Step 7: Generated-client parity**

Run: `npm --prefix apps/web run api:check`
Expected: exit 0.

- **Commit handoff gate**

Branch `feat/web-assignment-scope-picker`, files `apps/web/src/features/accounts-permissions/AssignmentScopePicker.tsx apps/web/src/features/accounts-permissions/useAssignmentScopeTargets.ts apps/web/src/features/accounts-permissions/RoleAssignmentsTab.tsx apps/web/src/features/accounts-permissions/copy.ts apps/web/src/features/accounts-permissions/AssignmentScopePicker.test.tsx apps/web/src/features/accounts-permissions/useAssignmentScopeTargets.test.tsx apps/web/src/features/accounts-permissions/RoleAssignmentsTab.test.tsx`, message `feat(web): locale-native fieldset radio assignment scope picker with local hook and disabled record_set helper (spec §19.7)`. Do NOT run `git add` or `git commit`.

---

### Task 8: Web retirement — replace read-only `RolesCapabilitiesWorkspace` and `AccessScopesScreen` with the new tabs; keep deep-link compatibility

**Files:**
- Modify: `apps/web/src/features/authorization/AccessWorkspace.tsx`
- Modify: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx`
- Modify: `apps/web/src/features/authorization/AccessScopesScreen.tsx`
- Modify: `apps/web/src/app/WorkspaceContent.tsx`
- Test: `apps/web/src/features/authorization/AccessWorkspace.test.tsx`
- Test: `apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx`
- Test: `apps/web/src/features/authorization/AccessScopesScreen.test.tsx`

**Interfaces:**
- Consumes: Task 7 components; existing `AccessSectionKey` type and route mapping in `apps/web/src/features/authorization/AccessWorkspace.tsx:42-78`.
- Produces:
  - Shell route preserves the existing five `AppRoute` names (`identity-accounts`, `authorization` with `resource` discriminants, `access-scopes`, `access-explanation`); each maps to one of the five new tabs.
  - `screenForRoute(...)` renders the new tab components instead of legacy screens.
  - `RolesCapabilitiesWorkspace` and `AccessScopesScreen` keep their public exports; their bodies are replaced with localized `EmptyState` redirects.

- [ ] **Step 1: Failing test — five tabs route correctly**

- `accessSectionForRoute({ name:'identity-accounts' }) === 'accounts'`
- `accessSectionForRoute({ name:'authorization', resource:'roles' }) === 'roles-permissions'`
- `accessSectionForRoute({ name:'authorization', resource:'role-assignments' }) === 'role-assignments'`
- `accessSectionForRoute({ name:'access-scopes' }) === 'policies-scopes'`
- `accessSectionForRoute({ name:'access-explanation' }) === 'decision-inspector'`
- The five tabs render in order: `accounts`, `roles-permissions`, `role-assignments`, `policies-scopes`, `decision-inspector`.

- [ ] **Step 2: Failing test — legacy screens are un-mounted**

- `<RolesCapabilitiesWorkspace … />` no longer renders the prior `data-list` of `{id,name,code}` rows; renders the redirect message.
- `<AccessScopesScreen … />` no longer renders the prior subject/scope table; renders the localized intro + redirect.

- [ ] **Step 3: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/features/authorization/AccessWorkspace.test.tsx src/features/authorization/RolesCapabilitiesWorkspace.test.tsx src/features/authorization/AccessScopesScreen.test.tsx`
Expected: FAIL.

- [ ] **Step 4: Implement — replace the active mount paths**

`AccessWorkspace.tsx`:
- Import the new components from `apps/web/src/features/accounts-permissions/*`.
- Replace each `screenForRoute` case to mount `AccountsTab`, `RolesPermissionsTab`, `RoleAssignmentsTab`, `PoliciesScopesTab`, `PermissionDecisionInspector`.
- Pass `canMutateAdminResource` results and resource `allowed_actions` (from `getRole`/`getAssignment`) into the tabs.

`RolesCapabilitiesWorkspace.tsx`:
- Body becomes a single `EmptyState` with localized copy: `"Roles and permissions are now managed in the Accounts & Permissions workspace."` / AR `"تُدار الأدوار والصلاحيات الآن في مساحة الحسابات والصلاحيات."`.
- Remove direct use of `useToken`, `useEffect` for `listAuthorization`, etc.

`AccessScopesScreen.tsx`:
- Replace body with `EmptyState` whose text is `"Access scopes are now managed in the Policies & Scopes tab."` / AR `"تُدار نطاقات الصلاحيات الآن في تبويب السياسات والنطاقات."`.
- Reduce prop signature to `{ locale }`; remove `usePlatformSettingsLive`/`navigate`.

`WorkspaceContent.tsx`: keep `<AccessWorkspace …>` mounts unchanged.

- [ ] **Step 5: Run, verify pass**

Run: same as Step 3.
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/src/features/authorization/AccessWorkspace.tsx apps/web/src/features/authorization/RolesCapabilitiesWorkspace.tsx apps/web/src/features/authorization/AccessScopesScreen.tsx apps/web/src/app/WorkspaceContent.tsx apps/web/src/features/authorization/AccessWorkspace.test.tsx apps/web/src/features/authorization/RolesCapabilitiesWorkspace.test.tsx apps/web/src/features/authorization/AccessScopesScreen.test.tsx`, message `feat(web): retire read-only Roles & Capabilities + Access Scopes surfaces and route to five-tab workspace`. Do NOT run `git add` or `git commit`.

---

### Task 9: Internationalization — Arabic/English copy + RTL/LTR + a11y wiring

**Files:**
- Create: `apps/web/src/features/accounts-permissions/copy.ts`
- Create: `apps/web/src/features/accounts-permissions/AnnouncementRegion.tsx`
- Test: `apps/web/src/features/accounts-permissions/copy.test.ts`

**Interfaces:**
- Consumes: existing `directionForLocale(locale)` in `apps/web/src/app/copy.ts`.
- Produces: full bilingual parity + live-region announcements per spec §13.

- [ ] **Step 1: Failing test — bilingual parity**

`copy.test.ts`: every English label has a non-empty Arabic counterpart and vice versa; pluralization cases (capability count, assignment count) work.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/features/accounts-permissions/copy.test.ts`
Expected: FAIL.

- [ ] **Step 3: Implement `copy.ts` + `AnnouncementRegion.tsx`**

Includes at minimum:
- 5 tab labels × 2 languages
- 4 empty-state messages × 2 languages
- 9 mutation success announcements (`role.created|updated|cloned|archived` + `assignment.created|updated|revoked|expired|role_capability.revoked`) × 2 languages
- 3 destructive-control confirmations × 2 languages, naming account, role, scope (no UUID/JSON)
- 2 advanced-tab unavailability messages × 2 languages
- 2 inspector outcome templates (e.g. `"This role applies in ${scope}."`) parameterized; AR variants
- `AnnouncementRegion` renders `<output role="status" aria-live="polite" dir={directionForLocale(locale)} />` and exposes `announce(variant, message)` and `announceError(message)` methods.

- [ ] **Step 4: Run, verify pass**

Run: same as Step 2.
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/src/features/accounts-permissions/copy.ts apps/web/src/features/accounts-permissions/AnnouncementRegion.tsx apps/web/src/features/accounts-permissions/copy.test.ts`, message `feat(web): bilingual AR/EN copy and a11y announcements for Accounts & Permissions tabs`. Do NOT run `git add` or `git commit`.

---

### Task 10: Unit + component tests — `canMutateAdminResource`, mutation feedback, error/concurrency recovery

**Files:**
- Create: `apps/web/src/features/accounts-permissions/AuthorizationMutationFeedback.test.tsx`
- Modify: `apps/web/src/features/accounts-permissions/canMutateAdminResource.test.ts` (extend Task 7 matrix)

**Interfaces:** Consumes Tasks 7 & 9.

- [ ] **Step 1: Failing test — error announcement focuses live region**

`AuthorizationMutationFeedback.test.tsx`:
- vi.spyOn(global, 'fetch') responds 422 → live region gains focus.
- 409 system-role immutable: role editor closes; role list keeps focus.
- 412 stale: version-conflict surfaces "Reload" + "Retry"; clicking Reload calls `getRole(...)`.
- 400 missing `If-Match` on clone: the editor surfaces the missing-header error and announces it.
- RTL render: `dir` attribute is `rtl`; AR copy asserted.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/web && npx vitest run src/features/accounts-permissions/AuthorizationMutationFeedback.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Implement — finalize `AuthorizationMutationFeedback.tsx`**

Complete hooks, error handlers, focus management, announcement strings (some completed in Task 7).

- [ ] **Step 4: Run, verify pass**

Run: `cd apps/web && npx vitest run src/features/accounts-permissions`
Expected: PASS.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/src/features/accounts-permissions/AuthorizationMutationFeedback.test.tsx apps/web/src/features/accounts-permissions/canMutateAdminResource.test.ts`, message `test(web): a11y, focus, and recovery tests for Accounts & Permissions mutations`. Do NOT run `git add` or `git commit`.

---

### Task 11: Browser journey — Playwright happy-path + advanced-tab deep-link unavailable

**Files:**
- Create: `apps/web/e2e/accounts-permissions.spec.ts`

**Interfaces:**
- Consumes: Task 7 mounts; existing `infra/dev/run-w1-1-e2e.sh` runner; existing `apps/web/e2e/walking-skeleton.spec.ts` patterns.
- Produces: deterministic spec covering spec §17 acceptance criteria.

- [ ] **Step 1: Failing spec — comprehensive journey**

- Log in as cluster administrator fixture principal.
- Navigate to `/admin/identity/accounts`; navigate to the Accounts & Permissions workspace from there. All five tabs present; tab order; basic tabs no "Advanced" eyebrow.

- `Roles & Permissions`: clone a system role via the editor; the editor submits `POST /api/v1/authorization/roles/{src}/clone` with `Content-Type: application/json`, `If-Match: "1"`, and a required `Idempotency-Key` header. The spec intercepts the request and asserts the `Idempotency-Key` header is present and matches the documented UUIDv7 format. Replaying the same `Idempotency-Key` with the same request hash returns the same response and emits no additional audit event. Edit the resulting custom role via `PATCH /api/v1/authorization/roles/{id}` with `Content-Type: application/merge-patch+json` and `If-Match: "{lock_version}"`. The `lock_version` returned by the mutation matches the editor's next `If-Match`.
- Direct server attempt to PATCH the system role returns the documented 409 problem envelope (use `page.request.patch(...)` with `Content-Type: application/merge-patch+json` and `If-Match: "1"`); the spec asserts `Content-Type: application/problem+json`, HTTP status 409, and `type=urn:cluster:problem:system-role-immutable`; no role row is written and no audit event is emitted.

- `Role Assignments`: create assignments at cluster / facility / unit (three manageable levels only); revoke two; explicit-expire one; verify `effective_status` per row. The scope picker is driven by `GET /api/v1/authorization/assignment-scope-targets`; only `cluster | facility | unit` are selectable. `record_set` is rendered as a disabled option with localized helper text and is never submitted. The spec attempts a direct `POST /api/v1/authorization/role-assignments` with `scope_type=record_set` via `page.request.post(...)` and asserts the 422 `urn:cluster:problem:scope_type_not_catalogued` problem response; no `role_assignments` row is created. A direct `PATCH /api/v1/authorization/role-assignments/{id}` with `scope_type=record_set` returns the same 422. No browser assertion claims a four-level creation flow.
- Out-of-scope revoke (facility-only-admin) → 403 problem envelope; URL state intact.
- `Permission Decision Inspector`: submit query; read plain-language verdict.
- Deep-link `?tab=policies-scopes` as ineligible principal → localized unavailable state; no router error; no 403 banner; no blank panel.
- Keyboard nav: tabs reachable with arrows; visible focus; `aria-selected` toggled; dialog focus containment on the role editor.
- Screen-reader announcement: error summary moves focus to live region.
- RTL run: `Locale: ar`; localized Arabic text asserted on tabs, editor, inspector outcome.

- [ ] **Step 2: Run, verify FAIL**

Run: `cd apps/web && npx playwright test e2e/accounts-permissions.spec.ts`
Expected: FAIL — file does not yet exist.

- [ ] **Step 3: Implement — finalize the spec and run in the existing W1.1 e2e lane**

Wire the spec into the existing runner; no new runner changes required (it scans `apps/web/e2e/*.spec.ts`).

- [ ] **Step 4: Run, verify pass**

Run: same as Step 2.
Expected: PASS; legacy walking-skeleton spec still PASS.

- [ ] **Commit handoff gate**

Branch `feat/web-accounts-permissions-actionability`, files `apps/web/e2e/accounts-permissions.spec.ts`, message `test(web): comprehensive Accounts & Permissions journey (RTL, a11y, mutation, deep-link unavailable)`. Do NOT run `git add` or `git commit`.

---

### Task 12: Final gates — feature-specific canonical gates only (report-only, no commit)

**Files:**
- No source edits in this task (gates only); any fix-forward is committed in its respective task.

**Interfaces:**
- Consumes: every prior task's deliverables.
- Produces: all-green evidence for the feature-specific canonical gates listed below. The earlier `verify-architecture-closure` aggregate is replaced by these focused gates because the architecture-closure runner requires exact mysql/e2e prerequisites that are runner-specific; the feature-specific gates listed here are the canonical evidence for this plan and are the only commands this task gates on.

- [ ] **Step 1: Web unit tests** — `cd apps/web && npx vitest run` → exit 0.
- [ ] **Step 2: React accounts-permissions focused tests** — `cd apps/web && npx vitest run src/features/accounts-permissions` → exit 0.
- [ ] **Step 3: React scoped-picker test** — `cd apps/web && npx vitest run src/features/accounts-permissions/AssignmentScopePicker.test.tsx src/features/accounts-permissions/useAssignmentScopeTargets.test.tsx` → exit 0.
- [ ] **Step 4: React wrapper test (scope-targets)** — `cd apps/web && npx vitest run src/api/r1.listAssignmentScopeTargets.test.ts` → exit 0.
- [ ] **Step 5: API authorization tests** — `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests` → exit 0.
- [ ] **Step 6: Audit module tests** — `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Audit/Tests` → exit 0 (covers the new `SharedRecordAuditEventAdapterTest`).
- [ ] **Step 7: Feature-specific Authorization HTTP adapter test** — `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAccountPermissionsHttpAdapterTest.php` → exit 0.
- [ ] **Step 8: API scope-targets catalog test** — `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php` → exit 0.
- [ ] **Step 9: API `record_set` fail-closed test** — `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationRoleAssignmentRecordSetFailClosedTest.php` → exit 0.
- [ ] **Step 10: ModuleBoundaries architecture check** — `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php` → exit 0; `AUTHORIZATION-AUDIT-PRODUCER` exception remains narrow; the Authorization source set still imports zero `Modules\Organization\Models\*` / `Modules\Organization\Persistence\*` symbols and the only Organization coupling is through `Modules\Organization\Contracts\*`.
- [ ] **Step 11: API lint + static analysis** — `cd apps/api && composer lint && composer analyse` → exit 0.
- [ ] **Step 12: API contract lint** — `npm --prefix apps/web run api:lint` → exit 0.
- [ ] **Step 13: Generated-client parity** — `npm --prefix apps/web run api:check` → exit 0.
- [ ] **Step 14: Browser journey** — `cd apps/web && npx playwright test e2e/accounts-permissions.spec.ts` → exit 0; legacy walking-skeleton spec still PASS.

No commit handoff: verification changes no files; report evidence only.

If additional closure evidence is required by the team (e.g. `make verify-core` or `make verify-architecture-closure`), the runner must be invoked explicitly with the documented prerequisites (`preflight-mysql-integration-strict` + `preflight-e2e-w1-1-strict`); this task does not assume those prerequisites are available in the local environment and does not gate on them.

---

## Spec acceptance criteria mapping

| Spec §16 Acceptance criterion | Mapped task(s) |
| --- | --- |
| 1. Five approved tabs in order with advanced tabs labelled | 7, 8 |
| 2. Authorized actor creates, edits, assigns a custom role immediately; no pending state | 4, 5, 6, 7, 11 |
| 3. System roles immutable through every mutation; cloning allowed | 1, 3, 4, 5 |
| 4. Authorized actor manages assignments at cluster/facility/unit (three manageable levels); server scope authority; `record_set` is rendered disabled in the UI and rejected server-side with 422 `scope_type_not_catalogued`; the catalog uses the dedicated controller + route (not the generic `/authorization/{adminResource}` dispatch); the Organization contract takes Authorization-derived candidate roots; pagination/cursor stays in the Authorization adapter; mutation guard is narrow and input-only (revoke/expire of legacy `record_set` rows continue to succeed); no new exception class | 1A, 1B, 1C, 3, 4, 5, 7, 7A, 11 |
| 5. Basic surfaces use names, descriptions, grouped capabilities, scope labels | 7, 8, 9 |
| 6. Every mutation writes actor/time/action/resource/before-after/scope/correlation transactionally | 2, 3, 4, 5 |
| 7. Audit communicated through permitted port/contract (no direct Audit import) | 2, 4, 12 |
| 8. `openapi.yaml` describes each operation; `cluster.ts` regenerated; `r1.ts` wraps generated operations | 1, 1A, 1C, 6 |
| 9. Capability + `allowed_actions` gates; server repeats checks | 4, 7, 10, 11 |
| 10. Validation/forbidden/not-found/conflict/stale-version/network/rate-limit states preserve context with accessible recovery | 7, 10, 11 |
| 11. Tabs, editors, tables, live feedback, RTL/LTR localization, advanced disclosures meet a11y/i18n behavior | 7, 7A, 8, 9, 10, 11 |

## Non-goals upheld

- No approval, request, pending, or review workflow (spec §2).
- No mandatory reason for in-scope clone/revoke/expire (spec §2, §6, §8). User logs provide accountability; no `reason` field is added to `AuthorizationAdminCreate` and no `reason` parameter is added to any in-scope wrapper.
- No replacement of the audit module (spec §2, §9); only the Audit-owned adapter implements the Shared port.
- No general identity lifecycle / authentication / organizational-structure redesign (spec §2); Accounts reuses `/identity/accounts*` only.
- No new record-set scope catalog owner; `record_set` remains fail-closed (spec §19.6, §19.10). No new capability and no reinterpretation of `WorkRecords` as an authorization-scope catalog. The only Organization coupling is through `Modules\Organization\Contracts\*` (plural — any existing or future Organization contract; spec §19.4). The new `ListOrganizationScopeTargets` contract is generic over scope types and takes Authorization-derived candidate roots; `ResolveScopeDescendants` is retained as a private Organization implementation helper and is no longer a sufficient Authorization-facing seam on its own. The dedicated catalog route lives in `apps/api/routes/web.php`; no new `ManageableScopeResolver` port is introduced — the Authorization adapter derives the actor's active `authorization.assignment.manage` roots directly from Authorization-owned tables.

- No new capability catalog entries are introduced; existing capability gates are reused.

## Final verification command set (feature-specific, canonical)

Each command below is the canonical, feature-specific evidence for the plan. The earlier `verify-architecture-closure` aggregate is replaced by these focused gates because the architecture-closure runner requires exact mysql/e2e prerequisites that are runner-specific; the focused gates listed here are the only commands this plan gates on. The assignment-scope-targets commands cover spec §19.9 catalog endpoint, fail-closed record_set, and the React scoped picker.

| Scope | Command |
| --- | --- |
| Unit tests (web) | `cd apps/web && npx vitest run` |
| React accounts-permissions focused tests | `cd apps/web && npx vitest run src/features/accounts-permissions` |
| React scoped-picker test | `cd apps/web && npx vitest run src/features/accounts-permissions/AssignmentScopePicker.test.tsx src/features/accounts-permissions/useAssignmentScopeTargets.test.tsx` |
| React wrapper test (scope-targets) | `cd apps/web && npx vitest run src/api/r1.listAssignmentScopeTargets.test.ts` |
| Unit tests (api authorization) | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests` |
| Unit tests (api audit) | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Audit/Tests` |
| Feature flow test (daily web-equivalent) | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAccountPermissionsHttpAdapterTest.php` |
| API scope-targets catalog test | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationAssignmentScopeTargetsHttpAdapterTest.php` |
| API record_set fail-closed test | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/AuthorizationRoleAssignmentRecordSetFailClosedTest.php` |
| API authenticated cursor codec test | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Authorization/Tests/SharedAuthenticatedCursorCodecTest.php` |
| Organization scope-targets contract test | `cd apps/api && php -d memory_limit=1G vendor/bin/phpunit Modules/Organization/Tests/ListOrganizationScopeTargetsContractTest.php` |
| Module boundaries | `cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php` |

| API lint + analyse | `cd apps/api && composer lint && composer analyse` |
| Generated-client parity | `npm --prefix apps/web run api:check` |
| API contract lint | `npm --prefix apps/web run api:lint` |
| Browser journey | `cd apps/web && npx playwright test e2e/accounts-permissions.spec.ts` |
| Browser walking-skeleton regression | `cd apps/web && npx playwright test e2e/walking-skeleton.spec.ts` |
