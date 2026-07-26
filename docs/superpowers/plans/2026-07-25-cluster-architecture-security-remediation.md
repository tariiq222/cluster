# Cluster Architecture and Security Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remediate the verified architecture, authorization, API-contract, concurrency, audit, pagination, and migration defects reported by Agents 01–25 without introducing new module-boundary or contract drift.

**Architecture:** Fix enforcement foundations first, then security/data-integrity defects, then migrate call sites and legacy controllers. Each wave must leave a working, independently testable contract. Shared contracts own cross-module interfaces; module handlers own transactions; controllers validate/authorize/delegate; generated web clients remain contract-generated.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12.5, MySQL/SQLite test profiles, React 19, TypeScript 6, Vite 8, OpenAPI/Orval.

## Current execution status — 2026-07-26

This file preserves the original task decomposition; unchecked boxes are not a
reliable live-progress signal after the large migration wave. Current evidence
is maintained in
[`docs/analysis/SUMMARY.md`](../../analysis/SUMMARY.md) and
[`docs/analysis/17-cross-cutting-risks.md`](../../analysis/17-cross-cutting-risks.md).

Observed progress includes the module-provider split, migration of the legacy
`app/Http/Controllers/` tree, complete coverage of migrated tables in
`TABLE_OWNERS`, the Documents CSRF route correction, and restored static/CI
gates. Remaining blockers include eight failing Organization drawer tests,
inventory exactness gaps, missing fresh E2E evidence, and the final full-suite
verification pass.

## Global Constraints

- Preserve module rank direction and depend only on another module's `Contracts/` or `Events/` surface.
- No direct cross-owner SQL. The owning module exposes a narrow contract and persistence adapter.
- State change, idempotency record, audit record, and outbox append commit atomically when they describe one command.
- Every stale-write contract is enforced at the write predicate (`WHERE lock_version = ?`), not only by a controller pre-read.
- Authorization precedes detailed validation/resource disclosure.
- API errors use `application/problem+json`; resources use the canonical `{data: ...}` envelope.
- Update authoritative OpenAPI first and regenerate Orval output; never hand-edit generated clients.
- Every task adds or updates focused regression coverage for the observable contract it changes.

---

## Verified scope and corrections

The audit covered all global findings 1–123 using parallel, read-only verification. Most claims are present. Corrections that change implementation scope:

- **20:** absence of `Contracts/` in Tasks, WorkRecords, Search, Reporting is not itself a rule violation; create contracts only when a module publishes a cross-module API.
- **23:** `identity/me` and `me` are distinct projections, not duplicate aliases.
- **30:** WorkDefinition show paths are enveloped; only Workflow show-instance/show-step paths are non-conformant.
- **33:** four named Organization endpoints are PATCH+If-Match-only; reorder is POST and lacks both If-Match and idempotency.
- **35:** four lifecycle mutations lack idempotency; calendar create already checks the header.
- **44:** current drift is 50 spec-only paths / 69 spec-only operations, not 50 equivalent live endpoints.
- **46:** W1.2 has 2 planned operations; R1 has 7. The defect is exact route/status governance, not proof that behavior is wholly absent.
- **59:** anonymous/worker routes intentionally omit session middleware and still validate correlation locally; document and test that boundary.
- **67:** CapabilityCatalog currently has 110 entries, not 129.
- **72:** seven `.list` codes exist; only `notifications.list` is fully dead. Others are used by backend or web route gates.
- **76:** current-identity is self/session-bound; the gap is explicit capability/audit policy, not cross-user IDOR.
- **78:** `audit_events` is stale documentation; no implementation table currently exists.
- **87:** gateway methods lack an internal transaction, but the current HTTP caller wraps them. Keep atomicity at the application-handler boundary and remove unsafe standalone assumptions.
- **89:** bootstrap role grants have no domain audit; the existing `access_decisions` row records bootstrap completion, not each assignment.
- **112:** `TransactionDocumentController` is a typo/ambiguous reference. Concrete affected file is `TransitionDocumentController` plus non-transactional Workflow command paths.
- **113:** CreateDocumentGrant performs two DB writes, not three; grant issuance is an external/read-only presign operation.
- **115:** standalone Task PATCH is an atomic CAS. The real defect is transition state commit followed by outbox append outside one transaction.
- **116:** maintenance cancel is POST, not PATCH. Both named controllers have atomic CAS updates but non-isolated update→re-read.
- **117:** reject the proposed row-lock fix. Existing conditional CAS is concurrency-correct; add a real concurrent test and address idempotency separately.

---

### Task 1: Add architecture enforcement foundations

**Files:**
- Modify: `apps/api/tests/Architecture/ModuleBoundariesTest.php`
- Modify: `apps/api/tests/Architecture/ModulePlacementInventory.php`
- Modify: `docs/architecture/module-catalog.md`

**Covers:** 3, 16, 19, 78, 123.

- [ ] Add a failing architecture test proving table ownership, outbox usage, and transaction-body checks run for all module PHP files, not only `Http/` controllers.
- [ ] Extend import parsing to reject module imports from `Shared\Infrastructure\...`; permit shared dependencies through `Shared\Contracts\...` only.
- [ ] Add migration-manifest integrity coverage: every intended migration is registered exactly once, and every inventory path exists.
- [ ] Remove the two stale Reporting inventory paths and stale `audit_events` catalog prose.
- [ ] Run `make verify-boundaries`; expected: new fixtures fail before implementation and pass after enforcement updates.

### Task 2: Establish shared HTTP and error primitives

**Files:**
- Modify: `apps/api/bootstrap/app.php`
- Create: `apps/api/app/Support/ProblemEnvelope.php`
- Modify: module `*Api.php`/HTTP support helpers and named controllers

**Covers:** 25–30, 41–42, 54–58, 61–62.

- [ ] Add focused exception-rendering tests for validation 422, authentication 401, not-found 404, correlation preservation, and `application/problem+json`.
- [ ] Register typed render callbacks in `bootstrap/app.php`.
- [ ] Introduce one problem-envelope renderer and retain module helpers only as thin typed facades.
- [ ] Replace message-string/`Throwable` mapping with typed exceptions; never expose raw exception messages.
- [ ] Add `IdentityRequestAttributes::CORRELATION_ID`, set it once in `IdentitySessionMiddleware`, and consume it in `RequireIdentitySessionPrincipal`.
- [ ] Standardize If-Match parsing on positive versions and extend `DocumentsApi::response()` with an optional version/ETag.
- [ ] Normalize resource envelopes, limited to the confirmed Identity, Search, Reporting, and Workflow sites.
- [ ] Run targeted HTTP/exception tests, then `composer --working-dir=apps/api test`.

### Task 3: Close immediate route-security gaps

**Files:**
- Modify: `apps/api/routes/web.php`
- Modify: `apps/api/bootstrap/app.php`
- Test: middleware/route feature tests

**Covers:** 21–24, 31–35, 59–60, 90–92.

- [ ] Move `PATCH /tasks/{taskId}` into the session+principal+CSRF mutation group and add a regression test that fails without `X-CSRF-Token`.
- [ ] Introduce named middleware aliases/policies for CSRF, Idempotency-Key, and If-Match; apply them explicitly by mutation contract rather than globally to reads.
- [ ] Add idempotency enforcement and durable replay storage to report export, Organization reorder, settings validate/publish, calendar publish, maintenance cancel, and other confirmed command endpoints.
- [ ] Decide and document PATCH policy: either If-Match-only by contract or Idempotency-Key+If-Match. Do not silently apply inconsistent rules.
- [ ] Add explicit route names mechanically after behavior is protected.
- [ ] Document distinct `me` projections and intentional anonymous/worker route boundaries.

### Task 4: Repair ABAC field-access and classification enforcement

**Files:**
- Modify: `apps/api/Modules/Authorization/Infrastructure/RbacAbacDecideAccess.php`
- Modify: `apps/api/Modules/Authorization/Domain/FieldAccessTemplate.php`
- Modify: WorkRecords, Search, Reporting handlers
- Modify: WorkDefinition resolver and SubmitWorkRecord path

**Covers:** 79–84.

- [ ] Write denial/masking tests for classified records through Get, List, Search, Report, Dashboard, Export, and Download.
- [ ] Enforce evaluation sequence: capability → classification policy → delegation → explicit deny → field template/projection.
- [ ] Fail closed for missing/malformed classification policies.
- [ ] Normalize template paths (`payload.foo`) and serializer lookup semantics.
- [ ] Propagate `field_policy_key` from published work definitions into submitted records.
- [ ] Require `AccessProjection` on every projection/export path; use `reporting.export` and `reporting.download` capabilities for those operations.
- [ ] Run Authorization, WorkRecords, Search, and Reporting focused suites.

### Task 5: Repair delegation and effective-capability semantics

**Files:**
- Modify: `ListEffectiveCapabilitiesForUser.php`
- Modify: `RbacAbacDecideAccess.php`
- Modify: `AuthorizationHttpGateway.php`
- Modify: delegation lifecycle tests and `CapabilityCatalog.php`

**Covers:** 68–72.

- [ ] Add HTTP lifecycle tests for delegation activate, revoke, expire, replay, and effective-capability projection.
- [ ] Subtract active explicit denies from effective capabilities and projected allowed actions.
- [ ] Make revoke expiry semantics immediate and consistent with the engine predicate.
- [ ] Remove or wire only the truly dead `notifications.list` capability; do not delete the six used `.list` codes.

### Task 6: Correct capability gates and authorization ordering

**Files:**
- Modify: `apps/web/src/shell/routes.ts`
- Modify: named web screens and API controllers
- Modify: `docs/api/rbac-matrix.md` generator/source

**Covers:** 49–53, 63–67, 73–76.

- [ ] Add endpoint-level capability checks to Search, notification mutation, Workflow inbox/versions, WorkDefinition show/version, and confirmed missing controller boundaries while retaining per-record checks.
- [ ] Move authorization before detailed validation in the eight confirmed controllers.
- [ ] Align Organization, procedure/workflow, backups, calendars, and approval-inbox route gates with actual server capabilities and per-control actions.
- [ ] Supply real `recordId` and resource facts for PII/import reads; define the explicit self-read/audit policy for current identity.
- [ ] Generate a Capability column in the RBAC matrix from runtime/controller metadata; assert all 110 catalog codes are classified as used, intentionally UI-only, or deprecated.
- [ ] Run focused API tests and web route-gate/component tests.

### Task 7: Move shared interfaces and cross-module reads behind contracts

**Files:**
- Move/split: `Shared/Infrastructure/Streams/RedisStreamTransport.php`
- Move/split: `Shared/Infrastructure/Outbox/OutboxEventType.php`
- Modify: Authorization, Documents, Tasks, WorkRecords, Organization integrations

**Covers:** 4–8, 11–19; excludes finding 20 as non-defect.

- [ ] Move `RedisStreamTransport` and the stable outbox event contract surface into `Shared\Contracts`; leave concrete implementations in Infrastructure.
- [ ] Replace Authorization reads of Organization tables with Organization contracts.
- [ ] Replace Tasks reads of Workflow tables with a Workflow contract.
- [ ] Replace WorkRecords reads of Authorization tables with an Authorization contract.
- [ ] Move sensitive-access recording ownership to Authorization behind a contract.
- [ ] Assign `outbox_events` to Shared or otherwise define one explicit owner; update table-ownership rules and migration placement.
- [ ] Verify no new cross-rank imports are introduced.

### Task 8: Make state, audit, idempotency, and outbox atomic

**Files:**
- Modify: Tasks transition/complete handlers
- Modify: WorkflowController/module handlers
- Modify: Documents grant/link/update/transition paths
- Modify: Organization/Identity outbox producers
- Modify: PlatformSettings mutation handlers

**Covers:** 11, 14–16, 85–89, 98–117 except rejected 117 locking change.

- [ ] Wrap Task transition state update and outbox append in one handler-owned transaction; pass `expectedVersion` into task completion and enforce it in the SQL predicate.
- [ ] Move Workflow versions/publish/act/cancel operations into module-owned handlers with state+outbox in one transaction; emit the decision event once.
- [ ] Move Documents access-event inserts into the same transaction as link/update/transition/idempotency writes; define compensating/durable semantics for external grant issuance.
- [ ] Route all Organization and Identity producers through the canonical transactional outbox; remove private/raw inserts.
- [ ] Make bootstrap completion and operations-office grants one unit of work, with explicit audit rows for resulting role assignments.
- [ ] Keep Authorization gateway writes under an application-service transaction; add concurrency-safe role-capability upsert semantics.
- [ ] Move SupervisoryRelationship idempotency to Organization-owned storage/service.
- [ ] Wrap maintenance/alert CAS update plus response re-read in a handler transaction; add domain audit/outbox only if the command contract requires it.
- [ ] Keep BusinessCalendar CAS; add a two-connection concurrency test proving one winner/one 412 and one child effect.

### Task 9: Standardize optimistic concurrency

**Files:**
- Modify: Documents upload, Workflow advancer/controller, Tasks completion, Authorization bootstrap, Organization unit move, WorkDefinitions

**Covers:** 36–39, 41, 98–104.

- [ ] Add failing stale-write tests at each named command boundary.
- [ ] Fix Workflow version allocation with a parent-row lock or unique `(workflow_definition_id, version_number)` constraint plus retry.
- [ ] Add expected-version predicates where optimistic locking is part of the public contract; do not add redundant row locks where an atomic CAS already suffices.
- [ ] Increment descendant/child lock versions whenever their externally observable state changes.
- [ ] Remove controller-only prechecks after handlers enforce the predicate.

### Task 10: Unify outbox event catalog and duplicate semantics

**Files:**
- Modify: `TransactionalOutbox`, `DatabaseTransactionalOutbox`, event catalog
- Modify: Tasks, Workflow, WorkDefinitions, Documents, Organization producers

**Covers:** 105–111.

- [ ] Define one producer contract: duplicate event ID either consistently replays/no-ops or consistently conflicts; document and test it.
- [ ] Add every emitted Task, Workflow, WorkDefinition, and Documents event to the authoritative catalog/schema set.
- [ ] Reconcile `document_outbox_events` with the shared outbox or explicitly document/verify a separate relay contract.
- [ ] Add rollback tests that inject outbox failure after state mutation for each producer family.

### Task 11: Standardize bounded collection contracts and cursors

**Files:**
- Modify: authoritative OpenAPI collection schemas
- Create: shared authenticated cursor codec
- Modify: Tasks, Reporting, Documents, Search, PlatformSettings, Workflow, Organization, Authorization lists

**Covers:** 40, 93–97.

- [ ] Decide the canonical collection shape. Keep `{items,next_cursor}` unless product explicitly requires redundant `has_more`; if added, derive it from `next_cursor` and update schemas first.
- [ ] Implement one authenticated opaque cursor containing version, resource key, exact sort tuple, query fingerprint, limit, and principal/scope binding.
- [ ] Fix Tasks comments to cursor on `(created_at,id)`.
- [ ] Migrate raw UUID and unsigned-base64 cursors in Reporting, Documents, Workflow, Organization supervisory relationships, and Authorization.
- [ ] Add `limit+1` pagination to Tasks, maintenance windows, alert policies, calendars, WorkDefinition/Workflow lists, and Search.
- [ ] Ensure Search advances across denied rows without leaking unauthorized totals.
- [ ] Add collection ETags only after stable pagination and representation semantics are fixed.

### Task 12: Reconcile live routes and OpenAPI

**Files:**
- Modify: `docs/contracts/api/openapi.yaml`
- Modify: route/docs generation scripts
- Regenerate: web API client

**Covers:** 43–48.

- [ ] Build an exact method/path reconciliation map for 143 live declarations and 201 spec operations.
- [ ] Resolve the 11 exact live-only operations and 50 spec-only paths/69 operations, explicitly distinguishing planned semantic equivalents from missing implementations.
- [ ] Align calendar route naming and generic/discrete lifecycle action shapes.
- [ ] Resolve W1.2/R1 frozen-vs-append-only governance and `x-implementation-status` semantics.
- [ ] Fix endpoint generator pointers to RFC 6901 fragments, resolve real schema refs, and make status extraction AST/method-aware so `json_decode(...,512)` is not treated as HTTP 512.
- [ ] Run `npm --prefix apps/web run api:check`, regenerate with `api:generate`, and verify no manual generated edits.

### Task 13: Complete controller relocation and rank repair

**Files:**
- Modify: `apps/api/routes/web.php`
- Move/delete: legacy `apps/api/app/Http/Controllers/**`
- Modify: module inventory/catalog

**Covers:** 1–3, 9–10.

- [ ] Rebind the 12 existing Organization module-controller counterparts, verify route signatures, then delete their legacy duplicates.
- [ ] Resolve Organization rank-0 upward dependencies via lower-rank-owned contracts/application boundaries; do not merely raise rank to silence the test without documenting the architectural decision.
- [ ] Migrate the remaining legacy controllers module by module, updating every caller and inventory entry in the same change.
- [ ] Run route inventory, focused feature tests, and `make verify-boundaries` after each module batch.

### Task 14: Repair migration reversibility and manifest integrity

**Files:**
- Modify: Workflow, Identity, Notifications, Organization migrations
- Modify: `AppServiceProvider.php` migration registration

**Covers:** 118–123.

- [ ] Decide the canonical `workflow_decisions` schema; remove or supersede the conflicting orphan migration without registering both.
- [ ] Resolve the two unregistered migrations intentionally: register, merge, or delete with a documented migration path.
- [ ] Implement safe downs for Identity credential tables and Organization seed rows, respecting foreign keys and data ownership.
- [ ] Make Notifications W20 down restore W18 columns/indexes and prior uniqueness exactly.
- [ ] Include `approval_status` in Workflow W17 rollback.
- [ ] Add manifest and up/down schema tests on a disposable database.

### Task 15: Final integration verification

**Files:** all changed artifacts.

- [ ] Run focused tests per task before broad gates.
- [ ] Run `make verify-boundaries`.
- [ ] Run `make lint-api` and `make analyse-api`.
- [ ] Run `make test-api`.
- [ ] Run `npm --prefix apps/web run build`, `lint`, `test:unit`, and `api:check` for web/contract changes.
- [ ] Run the relevant MySQL concurrency suite for locking/transaction changes.
- [ ] Run local browser journeys for capability-gate and CSRF changes.
- [ ] Update the architecture catalog and generated API docs only after runtime verification succeeds.

## Recommended execution waves

1. **Hotfix:** Tasks CSRF; correlation preservation; typed exception renderers; ABAC fail-closed/projection leaks; explicit-deny revoke semantics.
2. **Enforcement foundation:** architecture tests, Shared contracts rule, migration manifest, If-Match parser.
3. **Security semantics:** delegation/effective capabilities, capability gates, PII record facts, authorization ordering.
4. **Data integrity:** optimistic locking, Task/Workflow/Documents transactions, outbox/audit/idempotency atomicity, bootstrap invariants.
5. **Module boundaries:** cross-module contracts and table ownership, then controller relocation/rank cleanup.
6. **API consistency:** envelopes, errors, route names, OpenAPI reconciliation and generation.
7. **Scalability:** cursor codec, bounded pagination, collection ETags.
8. **Migration cleanup and full verification.**
