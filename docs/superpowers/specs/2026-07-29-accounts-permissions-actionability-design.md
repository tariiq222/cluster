# Accounts & Permissions Actionability Design

**Status:** Approved implementation design (amendment 2026-07-30 below)  
**Date:** 2026-07-29 (original); 2026-07-30 (amendment)  
**Scope:** Accounts & Permissions workspace in `apps/web`, its authorization administration API, OpenAPI contract, and audit-safe server mutations.

## 1. Problem and repository evidence

The present Accounts & Permissions area exposes authorization facts without reliably letting an authorized administrator complete the related administration job. The current interface is therefore technically informative but operationally passive:

- `RolesCapabilitiesWorkspace.tsx:41-100` renders role/capability material as a read-only workspace and exposes raw role codes and capability identifiers rather than administrator-oriented actions and explanations.
- `AuthorizationAdmin.tsx:120-123` marks the role/capability view read-only, so an otherwise authorized user cannot perform supported role maintenance there.
- `AccessScopesScreen.tsx:78-116` presents scope information and advanced authorization concepts without a clear task-oriented route between policy, scope, and an access decision.
- `AuthorizationHttpGateway.php` and `AuthorizationAdminController.php` establish the current HTTP boundary for authorization administration; supported domain operations must be exposed through that boundary rather than invented in the client.
- `CapabilityCatalog.php` is the capability vocabulary source. Web labels, grouping, mutation forms, and decision explanations must derive from its stable public catalog representation, not from UUIDs or locally duplicated capability lists.
- `openapi.yaml` does not yet describe all required administration mutations and actionability metadata, so `apps/web/src/api/r1.ts` cannot provide typed client operations for the complete experience.

The result is avoidable administration work: a user can inspect an opaque authorization state but cannot safely create a custom role, change an eligible custom role, assign it at the necessary organizational scope, or explain an access outcome from a single coherent workspace.

### Current-to-target gap table

| Current gap | Target change |
|---|---|
| The OpenAPI authorization-action enum has no `clone` action. | Add `clone` to the published action enum for the existing roles resource. |
| Role create and patch schemas omit `capability_codes`. | Add `capability_codes` and commit role state, capability set, and audit event in one application-service transaction. |
| `AuthorizationAdminController.php` emits no mutation audit event. | Record a transactional, module-boundary-safe authorization mutation event for every mutation. |
| `AuthorizationHttpGateway.php` lacks a system-role mutation guard. | Add an authoritative server guard that rejects every system-role mutation path. |
| `ReasonAction` and `apps/web/src/api/r1.ts` require a reason for current assignment transitions. | Update the in-scope assignment `revoke`/explicit `expire` actions so their bodies require no reason while audit still records actor, time, and before/after state. |
| `canMutateAuthorizationResource` permits only role assignments. | Expand the web gate for roles and custom-role actions using the management capability plus server-provided `allowed_actions`. |

## 2. Goals and non-goals

### Goals

1. Let a principal holding the applicable management capability complete authorized account, role, permission, and assignment changes immediately.
2. Make role and assignment state understandable in plain language before exposing technical details.
3. Preserve a hybrid role model: protected system roles remain immutable; custom cluster roles are managed in the cluster.
4. Support assignment scope at **cluster**, **facility**, and **unit** levels. `record_set` remains a recognized historical `scope_type` value (no re-typing of existing data) but the assignment-scope-target catalog lists only manageable cluster/facility/unit targets; the server returns the documented 422 `scope_type_not_catalogued` problem response whenever an actor attempts to create or update an assignment with `scope_type=record_set`, because no catalog owner currently exists for record sets. The UI renders the `record_set` level as visibly disabled with a localized explanation and does not submit it.
5. Make every successful mutation attributable, reconstructable, and auditable with one transactional business event.
6. Provide advanced administrators with explicit tools for policy/scope maintenance and permission-decision investigation without making those internals the default interface.
7. Keep the OpenAPI contract and generated web client complete and typed for every supported operation.

### Non-goals

- No approval, request, pending, or review workflow.
- No mandatory reason field or impact-confirmation workflow for the clone, revoke, and explicit expire actions defined here; unrelated out-of-scope resources retain their existing contracts.
- No alteration of the authorization evaluation model beyond exposing and hardening supported administration operations.
- No replacement of the audit module or direct imports of Audit implementation internals by Authorization.
- No general identity lifecycle, authentication, employee provisioning, or organizational-structure redesign.
- No promise that policy internals, raw UUIDs, JSON, or evaluator mechanics become basic-user concepts.

## 3. Personas and jobs

| Persona | Needed outcome | Default workspace level |
|---|---|---|
| Cluster access administrator | Find an account, understand its access, assign or revoke an eligible role assignment at the correct scope. | Basic |
| Cluster role manager | Create a custom role, maintain its name, description, and selected capabilities, then assign it. | Basic for role details; advanced only when policy/scope tools are needed. |
| Facility or unit administrator | Make an assignment limited to the facility or unit they manage and verify its effective scope. | Basic |
| Security/audit reviewer | Reconstruct who changed authorization, what changed, where it applied, and which affected references were involved. | Basic audit history; advanced decision inspector when diagnosing a specific result. |
| Platform authorization specialist | Inspect policy and scope definitions and explain a permission decision without modifying protected system roles. | Advanced |

## 4. Approved information architecture

Accounts & Permissions is one workspace with five tabs in this order; the first three are daily tabs and the final two are advanced tabs:

1. **Accounts**
2. **Roles & Permissions**
3. **Role Assignments**
4. **Policies & Scopes** (advanced)
5. **Permission Decision Inspector** (advanced)

The first three tabs are daily work. The final two are advanced tools, visually separated with an “Advanced” label and available only to principals who have their dedicated capabilities. Deep links to an unavailable advanced tab must render an authorized, plain-language unavailable state rather than a blank panel or raw 403.

The tab container preserves URL-addressable state. Each tab has its own query parameters for search, filters, pagination, and selected entity. Tab changes preserve an administrator’s current workspace context but do not retain unsaved edits after navigation; forms explicitly state that changes apply immediately on save.

## 5. Detailed UX

### 5.1 Accounts

Accounts is an account directory with search and filters appropriate to the principal’s authorized visibility. Each row shows a human name, status, primary organizational context, and a concise role summary. It never uses an identifier as the primary label.

Selecting an account opens a contextual detail pane or route with:

- account identity and status;
- effective role summary grouped by cluster, facility, unit, and record-set scope;
- direct links to each assignment;
- a **Manage assignments** action when the actor can mutate assignments for that target and scope;
- audit history filtered to the account when the actor can view it.

Loading uses table/skeleton semantics, empty search results explain how to broaden search or clear filters, and errors offer retry while preserving query state. Disabled actions remain visible only where they teach an otherwise relevant boundary; their explanatory text names the missing capability or unsupported scope without leaking policy internals.

### 5.2 Roles & Permissions

This tab replaces a raw role/code/capability display with a role catalog. Role rows show a localized display name, role type, short purpose, capability count, assignment count when available, and status. System roles carry a protected “System role” label; custom roles carry a “Custom role” label.

Role details use human-readable capability groups and descriptions from the capability catalog. The default detail view does not render UUIDs, serialized payloads, raw policy expressions, or evaluator implementation details. An advanced disclosure may show a stable capability key for support and copy purposes when the user is entitled to advanced inspection.

Actions:

- **Create custom role** is shown only to role-create-capable principals.
- **Edit custom role** is shown only for custom roles and only when the actor holds the required role-management capability.
- **Clone system role** is shown for system roles only to eligible role creators. Cloning creates a new custom role prefilled from the system role; it never edits or changes the source system role.
- **Assign role** starts the scoped assignment flow for a selected account.
- **View assignments** filters the Role Assignments tab to that role.

The custom-role editor has a name, description, and grouped capability selection. It reports the selected capability count, supports search within the catalog, and validates required fields inline. Save is explicit and immediate. System-role edit controls are absent, not merely disabled.

### 5.3 Role Assignments

Role Assignments is a task table for direct access administration. Rows show account, role, scope type, scope name, effective status, and last-change time. Filters include account, role, scope type, and in-scope organization values. The table supports a row action to edit scope or revoke the assignment when authorized.

The assignment form requires:

1. account;
2. role;
3. scope level: cluster, facility, or unit (the `record_set` level is rendered disabled with a localized helper message and is never submitted; see amendment §19.6);
4. one valid scope target when the selected scope level is not cluster. The scope target is selected from the assignment-scope-target catalog (amendment §19.2) — never from raw organization data.

The scope selector is constrained by the actor’s manageable scope and the selected account/role’s permitted domain. The UI must not offer values that the server will always reject. A compact effective-access preview explains the selected role and scope in ordinary language, for example: “This role applies in Facility: North Campus.” It does not claim approval, queuing, or a future activation date.

Save, scope update, and removal apply immediately after successful server response. The page refreshes its assignment and account-role summaries from server data rather than locally guessing authorization effects.

### 5.4 Policies & Scopes (advanced)

Policies & Scopes is an advanced maintenance and discovery tool with explicit internal subnavigation for:

- classification policies;
- field-access templates; and
- access scopes.

It uses a short plain-language introduction and task-specific lists instead of a combined opaque payload. Technical policy syntax and raw values are disclosed only when necessary to edit an authorized object. Mutation controls appear only for the operations the server exposes. This tab does not imply that every policy concept is safely editable by every cluster administrator.

### 5.5 Permission Decision Inspector (advanced)

The inspector answers a concrete diagnostic question: whether a named subject can perform a named capability against a selected resource context. The form selects subject, capability, resource type, and authorized context fields. Its result is explanatory, not a mutation:

- outcome in plain language;
- applicable role assignment summaries and scopes;
- relevant policy/scope names where safely exposable;
- a correlation/reference value suitable for support and audit lookup.

It must not expose raw evaluation traces, hidden resource data, UUID-first diagnostics, or security-sensitive policy logic to a user who lacks advanced inspection capability.

## 6. Direct flows

### Create a custom role

1. The actor opens Roles & Permissions and chooses **Create custom role**.
2. The actor enters name and description, searches grouped capabilities, and selects the intended capability set.
3. Client validation identifies missing name or invalid selection before submission.
4. The client sends one typed role-create request containing the role fields and `capability_codes`.
5. The role application service authorizes, persists the custom role and its complete capability set, records the transactional audit event, and returns the canonical role representation in one transaction.
6. The UI closes the editor, focuses the created role heading, announces success, and refreshes role and related count data.

### Clone a system role

1. The actor opens a system role and selects **Clone as custom role**.
2. The editor opens prefilled with the system role’s human-readable name, description, and capabilities; the clone receives a distinct proposed custom name.
3. The actor may change the custom role fields and saves.
4. The resulting role is custom and immediately mutable by principals holding the applicable management capability. The system source remains unchanged.

### Modify a custom role

1. The actor selects a custom role and chooses **Edit role**.
2. The editor loads the canonical role state with its version/concurrency token.
3. The actor changes supported mutable fields and the complete selected `capability_codes` set, then saves through one typed update request.
4. The role application service applies the role and capability-set change atomically or returns a concurrency, authorization, or domain error; the web never composes a role save from partially successful requests.
5. On success, the UI refreshes role details and affected assignment summaries from canonical server data.

### Assign, change, revoke, or expire a role assignment

1. The actor selects **Manage assignments** from an account, role, or assignment row.
2. The actor selects account, role, and cluster/facility/unit scope (the `record_set` level is rendered disabled with a localized helper message and is never submitted; see amendment §19.6).
3. The actor saves; the server validates authority over both target and scope, applies the mutation immediately, and returns the canonical assignment.
4. The UI updates the assignment table and effective-role summary.
5. For immediate removal, the UI names the account, role, and scope in the destructive-action control and invokes `revoke`; the click applies immediately after the normal component-system action confirmation, without a reason or separate impact-confirmation workflow. An explicit immediate expiry invokes `expire` under the same no-reason rule.

### Inspect a decision

1. The actor opens Permission Decision Inspector and supplies an authorized subject, capability, and context.
2. The client requests the server decision explanation.
3. The UI presents the ordinary-language result and allowed explanation metadata; it never performs a mutation or represents an approval process.

## 7. Hybrid role invariants

1. Every role is exactly one of `system` or `custom`.
2. System roles are immutable through every authorization administration API. The server guard is authoritative; hiding web controls is not sufficient.
3. A system role may be cloned only into a new custom role. A clone never changes the source role, its capability set, or its assignments.
4. Custom roles are cluster-defined and immediately mutable by holders of the relevant role-management capability, within their authorized cluster boundary.
5. Custom-role mutation does not create pending state, approval state, request records, mandatory reasons for the in-scope clone/revoke/expire actions, or staged changes.
6. Each role assignment has exactly one scope type: cluster, facility, unit, or record set. A non-cluster assignment references one valid target of the declared scope type. The manageable-scope catalog enumerates cluster/facility/unit targets only; an assignment with `scope_type=record_set` is rejected with the documented 422 `scope_type_not_catalogued` problem response because no catalog owner for record sets currently exists.
7. Assignment authorization evaluates both the actor’s management capability and the actor’s authority over the selected assignment scope.
8. Basic presentation shows names and scope labels, not internal IDs or evaluator implementation details.

## 8. API and OpenAPI contract changes

`openapi.yaml` must define the complete public authorization-administration contract, including typed schemas, operation IDs, authorization responses, concurrency semantics, and user-facing error codes for the existing generic administration route family. `apps/web/src/api/generated/cluster.ts` is regenerated from that contract. `apps/web/src/api/r1.ts` remains the handwritten domain wrapper and is updated to wrap those generated operations; no duplicate request types or untyped escape hatches are introduced.

The action enums and request/response schemas are intentionally changed by this design to publish the approved clone, atomic role-capability, and immediate assignment revoke/explicit-expire behavior.

### Required resources and operations

Accounts continue to use the existing Identity account endpoints. This design does not introduce duplicate Authorization account routes.

Authorization administration uses the established generic route patterns:

- `GET /authorization/{adminResource}`: list an authorized administration resource, with pagination, filters, display fields, and actionability metadata.
- `POST /authorization/{adminResource}`: create a supported administration resource.
- `GET /authorization/{adminResource}/{resourceId}`: retrieve a canonical resource representation, including version token and `allowed_actions`.
- `PATCH /authorization/{adminResource}/{resourceId}`: update a supported mutable resource with its version token.
- `POST /authorization/{adminResource}/{resourceId}/{authorizationAction}`: invoke a published authorization action, including `clone`, `revoke`, and explicit `expire` where defined.

#### Final action behavior

- **Roles:** `clone` creates a custom role and requires no reason. Role archive is a `PATCH` with `status=archived`, not an action route.
- **Role assignments:** `revoke` removes an assignment immediately and `expire` applies an explicit immediate expiry; neither action body requires a reason.
- **Role capabilities:** `revoke` detaches the capability immediately and requires no reason.
- These no-reason rules are limited to the operations in this design; unrelated resources retain their published contracts.

Published `adminResource` values cover roles, assignments, capability catalog projection, and only those advanced policy, field-access-template, and access-scope resources whose backend operations exist. The role create and patch schemas include `capability_codes`; the corresponding application service commits the role state, capability set, and audit event atomically. The permission-decision inspector continues through its existing published decision/explanation endpoints, with typed safe-response schemas; it is capability-gated and context-limited.

### Cross-cutting contract rules

- Every list response defines filters, sorting, pagination, empty result semantics, and stable display fields.
- Every mutable representation defines `version` or equivalent concurrency token, and successful mutable responses return the canonical post-write resource.
- Responses include `allowed_actions` as a server-derived capability/ownership projection. This drives control visibility and disabled explanations; it never replaces server authorization.
- `401`, `403`, `404`, `409`, `422`, and `429` responses use the repository’s standard error envelope and machine-readable codes. `403` must distinguish unavailable capability from out-of-scope management without leaking protected policy logic.
- Audit-event identifiers and correlation identifiers are returned only where useful and permitted; they do not replace the normal resource response.

## 9. Server hardening and transactional audit events

`AuthorizationAdminController.php` remains the HTTP adapter: validate request shape, resolve the authenticated principal, invoke the authorization administration application service, and map typed domain/application failures to the OpenAPI error envelope. `AuthorizationHttpGateway.php` remains the appropriate transport/client boundary for web-facing authorization operations.

The current implementation does not yet satisfy these target invariants; the following hardening work closes the identified gaps.

Server hardening requirements:

1. Enforce the system-role immutability invariant in the authorization domain/application layer for every direct and indirect mutation path, including a role `PATCH` containing the complete `capability_codes` set and clone-related code. Return a conflict or domain-validation error defined by OpenAPI; never silently ignore fields.
2. Authorize each mutation server-side for action, target cluster, and selected scope. UI capability gates are advisory usability controls, not security controls.
3. Validate scope type/target consistency, cluster ownership, referential integrity, duplicate assignment constraints, and custom-role ownership.
4. Apply role/assignment mutation and its audit event in one database transaction. If the audit event cannot be recorded, the authorization mutation must not commit.
5. Capture audit fields: actor, time, action, resource, before state, after state, scope, affected references, and correlation identifier. Before/after snapshots must be structured and minimized to authorization-relevant fields; they must not duplicate unrelated sensitive profile data.
6. Emit the audit event through an Authorization-owned port/contract or a module-boundary-safe shared event/outbox abstraction. The Audit module owns its internals and consumes the published event through an allowed boundary. Authorization must **not** directly import Audit implementation internals.
7. Make mutation handling idempotent where repository conventions support idempotency keys; retries must not produce duplicate assignments or duplicate audit events.
8. Return the canonical post-transaction representation only after commit.

## 10. Web components and data flow

The implementation composes the existing workspace shell, table/filter primitives, form controls, status chips, dialogs/drawers, and error presentation patterns; it does not create a parallel authorization design system.

Recommended component responsibilities:

- `AccountsPermissionsWorkspace`: route-level tab state, query-state synchronization, capability-gated advanced tabs, and shared account/role context.
- `AccountsTab` and `AccountDetail`: directory, detail, effective access summary, and links into assignment work.
- `RolesPermissionsTab`, `RoleCatalog`, `RoleDetail`, and `CustomRoleEditor`: role list/detail and create/edit/clone flows.
- `RoleAssignmentsTab` and `RoleAssignmentEditor`: assignment list/filter and scoped create/update/revoke/explicit-expire flows.
- `PoliciesScopesTab`: advanced subnavigation and published policy/scope operations.
- `PermissionDecisionInspector`: advanced diagnostic request/result interface.
- `AuthorizationMutationFeedback`: shared success/error/concurrency handling that refetches canonical resources and announces state changes.

All server state is fetched through generated operations in `apps/web/src/api/generated/cluster.ts`, wrapped by the handwritten domain layer in `apps/web/src/api/r1.ts`. Query keys include the resource identity, active tab, filters, selected scope, and pagination. Successful mutations invalidate the exact role, account, assignment, capability-summary, and count queries affected by the response; the UI must not reconstruct effective permission outcomes from stale local state. Forms hold local draft state only while open and submit typed request payloads with the server-provided version token. The custom-role editor submits role fields and `capability_codes` as one request, never as separately recoverable role and capability writes.

## 11. Capability gates

The backend defines capabilities in `CapabilityCatalog.php`; the web receives a permitted-action projection and capability/feature data through its existing principal and resource responses.

- The Accounts tab requires account/assignment viewing authority.
- Role list/detail requires role viewing authority.
- Custom-role create/edit and system-role clone require the relevant role-management capability.
- Assignment create/update/revoke/explicit-expire requires the relevant assignment-management capability plus manageable target scope.
- Policies & Scopes requires dedicated advanced policy/scope capability.
- Permission Decision Inspector requires dedicated advanced inspection capability.
- Audit-history access requires the existing audit-view capability.

Capability absence removes unavailable mutation controls and prevents access to advanced tabs. Resource-specific `allowed_actions` controls whether an otherwise capable user can act on the selected item. The server repeats all checks.

## 12. Errors, concurrency, and recovery

- **Validation (422):** retain the form draft, attach field errors to controls, move focus to the error summary, and announce it.
- **Forbidden (403):** retain non-sensitive draft data where safe; explain that the user cannot manage that account, role, or scope. Do not expose evaluator internals.
- **Not found (404):** explain that the record may have been removed or is no longer visible; return to the relevant filtered list.
- **Conflict (409):** for immutable system roles, state that system roles cannot be edited and offer clone where authorized. For duplicate/invalid assignments, state the conflicting account/role/scope summary when safe.
- **Version conflict (409/412 as published):** keep the user’s draft, fetch the current canonical record, show changed fields in plain language, and offer reload or retry after deliberate reconciliation. Never overwrite silently.
- **Network/temporary failure:** preserve draft locally for the current open editor, show retry, and do not optimistically claim that a mutation succeeded.
- **Rate limit (429):** display the retry interval from the contract and leave the current form intact.

## 13. Accessibility and internationalization

- Use semantic tabs with `tablist`, `tab`, and `tabpanel` behavior, keyboard arrow navigation, visible focus, and URL state that does not trap focus.
- Use labeled search/filter controls, native buttons for actions, dialog focus containment/return, and semantic tables with responsive alternatives that retain row labels.
- Announce mutation success, error summaries, refreshed stale-data notices, and decision-inspector outcomes through appropriate live regions without duplicating verbose table content.
- Associate every editor control with visible label, hint, error, and required state. Capability pickers provide keyboard search, group headings, checked state, and selected-count announcements.
- Do not communicate protected/custom role type, error severity, or authorization outcome by color alone.
- Localize all display labels, pluralized counts, dates/times, scope descriptions, empty/error states, and action text. Preserve stable machine keys only in advanced support disclosure. Support Arabic RTL and English LTR layout, including bidirectional scope names and predictable icon placement.

## 14. Security and privacy

- Apply least privilege at server and UI layers; server authorization is decisive.
- Scope account discovery, role visibility, assignments, and inspector inputs/results to the actor’s authorized cluster and organizational reach.
- Treat decision-inspector input and output as sensitive authorization metadata; do not log raw browser payloads, expose broad subject enumerations, or reveal inaccessible resources.
- Audit logs contain the required authorization-change evidence while minimizing unrelated personal data. Redact secrets, tokens, raw evaluator traces, and excessive profile fields.
- Escape all server-provided display text; do not render policy or capability descriptions as trusted HTML.
- Preserve correlation IDs for investigation and audit linkage, but do not use them as authorization credentials.

## 15. Compatibility, rollout, and rollback

The rollout is additive and compatibility-aware:

1. Add hardened server operations, canonical resource projections, capability catalog projection, transactionally recorded audit events, and OpenAPI schemas before enabling new web mutation controls.
2. Regenerate `apps/web/src/api/generated/cluster.ts` from `openapi.yaml`, update `apps/web/src/api/r1.ts` to wrap the generated operations, and migrate the workspace to that domain layer.
3. Replace the existing read-only roles/capabilities presentation with the approved tab model while retaining stable deep links through explicit route/query mapping.
4. Release advanced tabs behind their dedicated server-derived capabilities; basic tabs remain available only to their existing eligible audiences.
5. Monitor mutation error rates, concurrency conflicts, audit-event write failures, and authorization denials without collecting sensitive form content.

Rollback disables newly exposed web mutation controls and advanced tabs through the existing capability/feature delivery mechanism while keeping prior read access intact. Because each authorization mutation and audit record commits atomically, rollback does not require compensating partially committed writes. Existing successful mutations remain auditable and are reversed only by a new authorized mutation, never by deleting audit history.

## 16. Acceptance criteria

1. Accounts & Permissions presents exactly the five approved tabs, with Accounts, Roles & Permissions, and Role Assignments as daily work and Policies & Scopes plus Permission Decision Inspector as advanced tools.
2. An authorized actor can create, edit, and assign a custom cluster role and receives canonical immediate results; no pending/approval/request state exists.
3. System roles cannot be mutated by any published server mutation path. An authorized actor can clone a system role into a mutable custom role.
4. An authorized actor can create, change, revoke, or explicitly expire a role assignment at cluster, facility, and unit scope, subject to server scope authority. The UI shows `record_set` as a disabled scope option with a localized explanation; the server rejects `record_set` mutations with the documented 422 `scope_type_not_catalogued` problem response until a catalog owner for record sets exists.
5. Basic role and assignment surfaces use human names, descriptions, grouped capabilities, and scope labels; they do not default to UUID, JSON, or evaluator internals.
6. Every authorization mutation writes actor, time, action, resource, before/after, scope, affected references, and correlation identifier transactionally with the mutation.
7. Authorization communicates audit events through a permitted port/contract or shared abstraction and does not import Audit module internals directly.
8. `openapi.yaml` fully describes each exposed operation and error/concurrency behavior; `apps/web/src/api/generated/cluster.ts` is regenerated from it, and `apps/web/src/api/r1.ts` wraps the generated operations.
9. Capability and `allowed_actions` gates correctly control web visibility while the server independently enforces action/target/scope authority.
10. Validation, forbidden, not-found, conflict, stale-version, network, and rate-limit states preserve safe context and provide accessible recovery.
11. Tab navigation, editors, tables, live feedback, RTL/LTR localization, and advanced disclosures meet the specified accessibility and internationalization behavior.

## 17. Verification

Verification is complete when reviewers demonstrate the following against the implemented contract and UI:

- An eligible role manager creates and then immediately edits a custom role with `capability_codes` in each single atomic role request; the server response, role detail, generated-client type, and handwritten wrapper agree.
- An eligible actor clones a system role, verifies the source is unchanged, and edits the new custom role; direct server attempts to mutate the system role fail deterministically.
- An eligible actor creates, changes, revokes, and explicitly expires assignments at cluster, facility, and unit scope; an actor outside the selected scope receives the documented forbidden result. Direct `record_set` create/update attempts receive the documented 422 `scope_type_not_catalogued` problem response. The browser verifies that the `record_set` scope option is rendered disabled with a localized explanation in both Arabic and English.
- Each successful mutation has one corresponding audit record with all required fields and no independently committed authorization write when the audit event path fails.
- Generated-client compilation uses the revised `openapi.yaml`; `apps/web/src/api/generated/cluster.ts` contains all typed operations consumed through `apps/web/src/api/r1.ts`.
- The first three daily tabs expose human-readable actionability and advanced tabs do not render for an ineligible principal, including direct URL entry.
- Keyboard-only and screen-reader passes cover tabs, forms, capability selection, error focus, mutation announcements, and dialog focus return in Arabic RTL and English LTR.
- Concurrency tests prove that a stale role or assignment update does not silently overwrite a newer server state.

## 18. Repository evidence and implementation boundary

This design is grounded in the current passive roles/capabilities presentation at `RolesCapabilitiesWorkspace.tsx:41-100` and `AuthorizationAdmin.tsx:120-123`, the advanced scope presentation at `AccessScopesScreen.tsx:78-116`, the current authorization HTTP/controller boundary in `AuthorizationHttpGateway.php` and `AuthorizationAdminController.php`, the catalog source in `CapabilityCatalog.php`, and the contract/client sources `openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, and `apps/web/src/api/r1.ts`.

Implementation changes belong in the existing Authorization module/application boundary, OpenAPI source, generated web client, handwritten web domain wrapper, and existing web authorization workspace composition. Audit persistence or dispatch is invoked through a module-boundary-safe contract or shared event/outbox abstraction owned at an allowed dependency level. The Authorization module must not acquire a direct dependency on Audit implementation internals.

## 19. Amendment 2026-07-30 — Assignment scope target catalog and `record_set` fail-closed

This amendment extends the original 2026-07-29 design with a dedicated, manageable assignment-scope-target catalog endpoint and clarifies that `record_set` is fail-closed until a catalog owner exists. It does not introduce a new record-set catalog and does not reinterpret the existing `WorkRecords` records as an authorization-scope catalog.

### 19.1 Architecture

- A new read-only endpoint enumerates **manageable** assignment-scope targets for the authenticated principal. The catalog is the single source of truth for the assignment scope picker in the web workspace; the server never trusts a scope target sent by the client without consulting this catalog during mutation.
- The catalog lives behind the **Organization Contracts seam**: `Modules\Authorization` calls into `Modules\Organization\Contracts\ListOrganizationScopeTargets` (a new generic catalogue contract) and never reaches into Organization persistence, models, or SQL. The Organization module owns the facts; the Authorization module owns the authority. The legacy `Modules\Organization\Contracts\ResolveScopeDescendants` may be retained as an internal Organization implementation detail behind `ListOrganizationScopeTargets`; it is no longer a sufficient seam on its own because it returns descendants without bilingual labels, parent filtering, or pagination.
- The endpoint is documented in `docs/contracts/api/openapi.yaml`, generated by Orval into `apps/web/src/api/generated/cluster.ts`, and wrapped by `apps/web/src/api/r1.ts`. No hand-typed duplicate request shape is introduced.
- Pagination uses a stable cursor (`cursor`, `limit`) on the catalog endpoint; responses include `next_cursor` (or `null` when exhausted) and never assume offset/page semantics. The cursor is opaque to the client, tied to the principal's manageable scope at issuance time, and issued/parsed through `Shared\Http\AuthenticatedCursorCodec`; the codec binds the cursor to the principal, the request filters, and the resolved `limit`. The default `limit` is 25; the documented maximum is 100; values above the maximum are clamped server-side.

- The contract is the source of truth for query/response fields; no field is added outside this amendment or the original spec.

### 19.2 Route, query, and response fields

- **Route:** `GET /api/v1/authorization/assignment-scope-targets`.
- **Query parameters:**
  - `scope_type` (required): one of `cluster | facility | unit | record_set`. `record_set` is published in the query enum for backward compatibility of stored rows but is **never a manageable catalog level**; clients asking for `record_set` always receive the documented 422 problem response below (spec §19.6). Exactly three values drive the catalog body and the assignment form: `cluster`, `facility`, `unit`.
  - `parent_scope_type` (optional, only when `scope_type ∈ {facility, unit}`): one of `cluster | facility`. Used to filter the catalog to descendants of a selected parent (for example, "units under facility X").
  - `parent_scope_id` (optional, paired with `parent_scope_type`): a `UUIDv7` reference to the parent.
  - `search` (optional): free-text substring matched against `label_ar`, `label_en`, and `code` (case-insensitive, locale-independent).
  - `cursor` (optional): opaque cursor returned by the previous response.
  - `limit` (optional, default 25, max 100): maximum number of targets in the response body. The Authorization HTTP layer clamps requests that exceed the documented maximum to the documented maximum.
- **Response body fields per target:**
  - `scope_type`: `cluster | facility | unit` (never `record_set`).
  - `scope_id`: `UUIDv7` of the target entity.
  - `label_ar`: localized Arabic display name (required, non-empty).
  - `label_en`: localized English display name (required, non-empty).
  - `code`: optional stable short identifier for the target (for example, the unit code); present only when the catalog owner exposes a code; omitted entirely when the target has no code.
- **Response envelope:** `{ items: Target[], next_cursor: string | null }`. The flat shape is consumed verbatim by the existing `AuthorizationApi::collection(array $page, string $correlationId, ?string $link = null)` helper — `$page` carries `{items, next_cursor}` and the helper emits that flat shape (no `data` wrapper) — and is emitted through the Orval-generated collection schema. The standard error envelope (`application/problem+json`) is used for every non-2xx response.
- **Authn/authz:** cookie-backed identity session middleware (`App\Http\Middleware\IdentitySessionMiddleware` plus `App\Http\Middleware\RequireIdentitySessionPrincipal`) is the single authentication path for the catalog; no bearer token, no `Authorization` header, and no client-supplied identity is honored. The catalog is filtered server-side to the actor's manageable cluster/facility/unit scope. An actor with no manageable scope receives an empty `items` array and `next_cursor: null` rather than a 403, so the UI can render an empty picker without leaking authority boundaries. Pagination cursors are issued and parsed through `Shared\Http\AuthenticatedCursorCodec` and are bound to the principal, the request filters, and the resolved `limit`; the codec never encodes authorization grants, role assignments, or p…

### 19.3 Authorization rules

- The catalog is **filtered by the principal's manageable scope**, not by the catalog owner's full set. A facility administrator sees only the units under facilities they manage. A cluster administrator sees every cluster, every facility in their cluster, and every unit under those facilities.
- The same authority check that gates create/update/revoke/expire is reused to filter the catalog; UI visibility does not introduce a new authorization decision.
- Returning a target in the catalog is necessary but not sufficient for the actor to assign to it: the assignment service still re-checks authority at mutation time against the resolved scope.

### 19.4 Organization Contracts seam

- The Authorization module resolves the catalog by calling a new generic **Organization contract** that takes a list of Authorization-derived **candidate scope roots** and returns bilingual labelled targets. The contract is intentionally generic over scope types; it does NOT import any `Modules\Authorization\*` type and is decoupled from the Assignment feature surface. The contract signature is:
  - `Modules\Organization\Contracts\ListOrganizationScopeTargets::labelCandidates(string $scopeType, list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}> $candidates, ?string $search): array<int, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label_ar: string, label_en: string, code?: string|null}>`
  - The contract returns a **map keyed by the original candidate index** so the Authorization adapter can re-order, drop, or expand each candidate against the catalog. Order is not guaranteed to be preserved; the adapter is the source of truth for ordering and pagination.
  - Candidates whose `scope_type` does not match the requested `$scopeType`, and candidates whose underlying Organization row does not exist, are dropped from the returned map (the adapter is the source of truth for ordering and pagination; Organization only labels what already exists).
  - The contract owns ONLY label resolution for the supplied candidate IDs; it labels each existing candidate with bilingual `label_ar`/`label_en` (and optional `code`) and drops only the candidates that do not resolve to an existing Organization row. **The manageable-scope filter lives in the Authorization adapter**, not in Organization: Authorization derives the actor's active `authorization.assignment.manage` roots directly from Authorization-owned tables and uses those roots to assemble the candidate list it forwards. **Pagination and cursor handling stay in the Authorization adapter** (`Shared\Http\AuthenticatedCursorCodec`); the Organization contract never accepts, emits, or interprets a cursor.
- The Authorization module owns a thin **port** (`Modules\Authorization\Contracts\ListAssignmentScopeTargets`) whose sole implementation lives at `Modules\Authorization\Infrastructure\Persistence\DatabaseListAssignmentScopeTargets` and adapts the Organization contract to the API shape: it derives the actor's active `authorization.assignment.manage` roots directly from Authorization-owned tables (no separate resolver), uses those roots together with `parent_scope_type`/`parent_scope_id` to build the candidate root list, calls the contract, applies the search filter, paginates via the codec, and returns the flat `{items, next_cursor}` envelope. The Authorization adapter is the sole owner of the documented `parent_scope_*` expansion rules and the `400 invalid_scope_query` / `422 scope_type_not_catalogued` mapping.
- The contract's `candidates` parameter is the Authorization-derived input that carries the resolved root set into Organization. This is necessary because Organization must label only the IDs Authorization chooses to forward — without `candidates`, the contract would have no way to know which roots to label. The descendants-only seam from the prior amendment (`ResolveScopeDescendants::descendants($scopeType, $scopeId)`) is retained as an Organization implementation detail that Authorization uses to expand a single parent root into descendants; it is not a sufficient Authorization-facing seam on its own because it returns descendants without bilingual labels or pagination. Adding a new contract entry (or widening the existing one) must not import `Modules\Authorization\Contracts\*` from Organization; the dependency direction is Organization → Authorization-as-consumer, never the reverse.
- No cross-module SQL is permitted: Authorization never executes a `DB::table('organizations.*')` query, never calls Organization's Eloquent models directly, and never reads Organization's materialized views. All scope facts flow through the contracts seam; the candidate-root list is the only input the Organization contract accepts, and pagination/cursor state never crosses the seam.

### 19.5 Descendants expansion and candidate roots

- `parent_scope_type=cluster` with a cluster `parent_scope_id` requires the Authorization adapter to expand that single root into a candidate list of every facility in that cluster (and, when `scope_type=unit`, every unit under those facilities) before calling the Organization contract. The expansion is bounded: at most one `parent_scope_*` pair is supplied per request and the expansion is computed by the Authorization adapter using the existing `Modules\Organization\Contracts\ResolveScopeDescendants::descendants(...)` helper (now a private implementation detail of the Authorization adapter, not a published seam on its own). The Organization contract then receives the candidate list and returns labelled/filtered targets keyed by the original candidate index.
- `parent_scope_type=facility` with a facility `parent_scope_id` expands to every unit under that facility when `scope_type=unit`; the request returns `400 invalid_scope_query` when `scope_type=cluster` and `parent_scope_type=facility` because the request shape is contradictory.
- `scope_type=cluster` with no `parent_scope_*` expands to one candidate per manageable cluster (the cluster itself) and the Organization contract labels and confirms the manageable subset.
- Nested expansion (cluster → facility → unit in one query) is expressed as a single descendants expansion in the Authorization adapter plus a single call to `ListOrganizationScopeTargets::labelCandidates(...)` followed by a single filtering pass in the Assignment application service; it is never expressed as recursive Authorization-side logic and never expressed as a cursor or paginated walk inside the Organization contract.

### 19.6 `record_set` fail-closed

- The OpenAPI `scope_type` enum continues to include `record_set` for backward compatibility of historical assignment rows; the value is intentionally **not re-typed** in this amendment.
- The catalog endpoint accepts `scope_type=record_set` (published for backward compatibility of stored rows) but does **not** list record-set targets. `GET …/assignment-scope-targets?scope_type=record_set` returns **422** with `type=urn:cluster:problem:scope_type_not_catalogued` and a localized detail explaining that record-set scope assignments cannot be created in this release because no catalog owner exists.
- **Mutation guard (narrow, input-only):** `POST /api/v1/authorization/role-assignments` rejects with the same 422 `scope_type_not_catalogued` problem response when the request input explicitly carries `scope_type === 'record_set'`; `PATCH /api/v1/authorization/role-assignments/{id}` rejects with the same 422 when the merge-patch body explicitly sets `scope_type === 'record_set'`. The guard fires inside the `DB::transaction(function () { ... })` `mutate()` callback, **before** the gateway `create()` / `update()` call, so the existing outer transaction opens, the guard rejects, and the transaction rolls back cleanly. The guard is intentionally **NOT gateway-wide**: the existing `revoke`, `expire`, and any future read-only or status-only flows on historical `record_set` rows remain available because their request bodies do not carry a `scope_type` field, so legacy `record_set` assignments remain revocable and expirable exactly as documented in spec §7.6.
- The guard reuses the existing `InvalidArgumentException` with message `authorization_scope_type_not_catalogued`. No new exception class is introduced; no `ProblemScopeTypeNotCataloguedException`, no custom problem class. The HTTP layer adds an explicit match arm that maps any `InvalidArgumentException` whose message equals `authorization_scope_type_not_catalogued` to the documented 422 problem envelope (`urn:cluster:problem:scope_type_not_catalogued`). The match arm lives in the existing `AuthorizationAdminController` exception filter and is the single emission path for that envelope.
- Audit emission for the rejected attempt follows the existing audit double-throw contract: the outer transaction opens, the validation guard rejects inside the `mutate()` callback, the transaction rolls back cleanly, and **no idempotency record is persisted** for the rejected request. The `Idempotency-Key` header on a rejected request is not stored; a subsequent retry with the same key behaves like a fresh attempt and must hit the guard again (i.e. the same 422 response is returned for the same input).
- The web UI renders `record_set` as a **disabled** scope option in the assignment form, with localized explanation text in Arabic and English. The option is never submitted; if a future regression sends it anyway, the server still rejects it.
- This amendment does **not** open a record-set catalog owner, does **not** reinterpret `WorkRecords` as an authorization-scope catalog, does **not** add a new capability, does **not** add a new exception class, and does **not** widen the audit/transaction contract. Record-set scope remains fail-closed until a follow-up amendment defines a catalog owner and a separate amendment retires legacy `record_set` rows.

### 19.7 UI behavior

- The Role Assignments tab scope picker queries the catalog endpoint with the actor's manageable scope and the currently selected parent (cluster or facility). The picker shows `label_ar` and `label_en` in a bilingual list, never raw UUIDs or JSON, and uses the optional `code` only as a secondary search-friendly tag.
- The `record_set` level is shown in the level selector as a disabled option with a localized helper message (`"Record-set scope is not yet available."` / `"نطاقات مجموعة السجلات غير متاحة بعد."`). The helper text does not name internal identifiers, evaluator mechanics, or other catalog internals.
- When the actor has no manageable cluster/facility/unit targets, the picker shows a localized empty state explaining how to request cluster access; it does not render the disabled `record_set` option as a substitute.
- Successful assignment create/update/revoke/expire still refresh the catalog view and the assignment table from canonical server data; the UI never claims an assignment was applied to a `record_set` scope.

### 19.8 Security and privacy

- The catalog endpoint is filtered server-side to the actor's manageable scope. It does not enumerate organization records, employees, or roles the actor cannot manage.
- No cross-module SQL: Authorization does not query Organization tables; Organization does not query Authorization. All scope facts flow through the Contracts seam.
- The catalog response carries no sensitive profile data; `label_ar`/`label_en`/`code` are the only descriptive fields and are drawn from the Organization contract.
- `scope_id` is the only identifier exposed. The UI uses `label_*` for display and never re-emits raw `scope_id` to the user.
- The 422 `scope_type_not_catalogued` response message names only the missing catalog owner at a high level ("record-set scope catalog is not configured for this release") and never leaks the absence of an internal module or future plan.
- Cursor values are opaque, principal-scoped, and short-lived; they do not encode authorization grants or personal data.

### 19.9 Tests

- **API catalog endpoint (`AuthorizationAssignmentScopeTargetsHttpAdapterTest`):** proves that an authenticated principal with manageable scope receives only their cluster/facility/unit targets in `{items, next_cursor}` form; an out-of-scope principal receives an empty `items` array; `parent_scope_type=cluster` + `parent_scope_id=<cluster>` with `scope_type=unit` returns every descendant unit in one response; `scope_type=record_set` returns 422 `scope_type_not_catalogued`; pagination with cursor (encoded/decoded via `Shared\Http\AuthenticatedCursorCodec` and bound to principal+filters+limit) returns the correct `next_cursor` and never returns duplicate rows across pages; an un-bounded `limit` is clamped to the documented maximum of 100.
- **API mutation fail-closed (`AuthorizationRoleAssignmentRecordSetFailClosedTest`):** proves that `POST /role-assignments` and `PATCH /role-assignments/{id}` with `scope_type=record_set` both return 422 `scope_type_not_catalogued`, write no `role_assignments` row, and emit no audit event beyond the documented validation outcome.
- **Architecture-boundary regression (`ModuleBoundariesTest`):** the Authorization module source set still imports zero `Modules\Organization\Models\*`/`Modules\Organization\Persistence\*` symbols; the only Organization coupling is through `Modules\Organization\Contracts\*`. The Organization module imports zero `Modules\Authorization\Contracts\*` symbols. The seam assertion is added to the existing architecture test, not widened into a new exception.
- **Organization contract unit test (`OrganizationResolveScopeDescendantsTest`):** the existing Organization contract test continues to pass; this amendment does not change its signature or behavior.
- **Web wrapper (`r1.listAssignmentScopeTargets` test):** the typed wrapper resolves `items` and `next_cursor` (the same shape consumed by `AuthorizationApi::collection`), forwards `scope_type`/`parent_scope_type`/`parent_scope_id`/`search`/`cursor`/`limit` parameters, permits all four published `scope_type` values at the generated type level, lets the server's standard `ApiError` envelope carry the 422 `scope_type_not_catalogued` response, and renders `record_set` as a disabled option with the documented localized helper copy.
- **Browser journey (Playwright spec update):** the assignment journey creates cluster/facility/unit assignments and asserts the `record_set` option is rendered disabled with localized explanation; it attempts a direct `POST …/role-assignments` with `scope_type=record_set` via `page.request` and verifies the 422 `scope_type_not_catalogued` problem response. No browser assertion claims a four-level creation flow.
- **Spec acceptance coverage:** `§16.4` and `§17` are interpreted to mean three manageable levels (cluster, facility, unit) plus the documented `record_set` fail-closed behavior; the verification matrix is updated to reflect that `record_set` is rejected, not created.

### 19.10 Compatibility with the original design

- The amendment preserves the original §4 IA, §5.3 Role Assignments UX, §7 hybrid-role invariants (with §7.6 updated above), §8 OpenAPI rules, §9 server hardening, §10 web data flow, §11 capability gates, §12 errors, §13 a11y/i18n, §14 security, §15 rollout, §16 acceptance (with §16.4 updated above), and §17 verification (with the verification bullet updated above).
- The amendment does not change the audit-emission path, the Shared `RecordAuditEvent` port, the transaction-neutral gateway rule, the system-role immutability invariant, the no-reason body rules, or the architectural boundary rules (Authorization does not import Audit implementation internals; Organization facts flow through Contracts only).
- The amendment introduces no new capability code and no new record-set catalog. Future record-set support requires a separate amendment that defines a catalog owner.

