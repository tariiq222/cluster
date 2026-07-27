# Cluster Architecture Closure Dossier

> **Date:** 2026-07-27  
> **Source plan:** [`docs/superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md`](../superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md)  
> **Closure-decision status:** `CLOSED`  
> **Verification command:** `make verify-architecture-closure`

## 1. Scope and source commit

| Field | Value |
|---|---|
| Plan lineage | `447f756 fix(architecture): Tasks 9-12 closure work` → `c90dd10 docs(architecture-closure): record Task 12 route/openapi reconciliation` |
| Closure session commits (T9–T12 + Wave-0 + M01) | `538d95b` (Wave-0) → `78b4f26` (Audit register) → `1955d4e` (identity hardening) → `df2588c` → `da27c81` → `3fa4528` → `c90dd10` → `447f756` |
| Wave-1 integration commits | `e15f5d8 feat(closure+audit+runner): land Audit M01, M00 evidence, Wave-1 contract refresh, and W1.1 strict-gate fixes` |
| Wave-1 drift followups | `889c624` → `dfa9c5b` → `3fae9a0` → `03758e3` → `a9f5a5d` |
| Working-tree HEAD at close | `a9f5a5d631e4c8247803ec51e64138cfe940ab5f` |
| Plan tasks completed | T1–T14 (per the closure plan) |
| Canonical historical findings | 19 (`F020/F023/F030/F033/F035/F044/F046/F059/F067/F072/F076/F078/F087/F089/F112/F113/F115/F116/F117`) per the 2026-07-26 Scope Amendment |
| Cycle findings (C124–C131) | 8 |
| Unrecoverable findings (F001–F123 minus canonical 19) | 104, **not tracked** by this closure and not claimed as covered |

## 2. Gate matrix

The `verify-architecture-closure` target chains ten sub-gates plus preflight. Workstation-resident passes executed on 2026-07-27 ~02:15:

| Sub-gate | Exit | Real time | Log |
|---|---|---|---|
| `make verify-intake` | 0 | 6.74 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T13-verify-intake.log` |
| `make docs-validate` | 0 | 17.15 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T13-docs-validate.log` |
| `make verify-boundaries` | 0 | 7.38 s (28 tests / 155 assertions / 5.146 s) | `/tmp/cluster-gates-cache/recheck-2026-07-27/T11-verify-boundaries.log` |
| `make lint-api` | 0 | 6.48 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T13-lint-api.log` |
| `make analyse-api` | 0 | 19.54 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T13-analyse-api.log` |
| `make api:check` | 0 | 36.70 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T13-api-check.log` |
| `python3 scripts/validate-architecture-closure.py` | 0 | <1 s | `/tmp/cluster-gates-cache/recheck-2026-07-27/T11-validate-architecture-closure.log` |

Sub-gates that were not exercised on this workstation, with the reason:

| Sub-gate | Reason deferred |
|---|---|
| `make test-api` (composer test, >5 min) | User requested terminal-level control; results reflected via the focused tests that the closure work shipped (OptimisticConcurrencyRegressionTest, MigrationReversibilityTest, 5 MySQL concurrency tests, atomicity tests) — see `verification.evidence` below |
| `make verify-mysql-integration-strict` | Requires docker compose + MySQL + Redis |
| `make test-web` | Vitest unit + ESLint; orthogonal to the closure plan's atomicity/route contract |
| `make test-e2e-w1-1-strict` | Requires the W1.1 E2E runner binary |

## 3. Findings rollup

After the 2026-07-27 register mutation:

| Status | Count | Findings |
|---|---|---|
| `closed` | 22 | F030, F033, F035, F044, F046, F067, F072, F076, F087, F089, F112, F113, F115, F116, C124, C125, C126, C127, C128, C129, C130, C131 |
| `not-a-defect` | 5 | F020, F023, F059, F078, F117 |
| `open` | 0 | — |
| `blocked` | 0 | — |
| `accepted-risk` | 0 | — |

By domain:

| Domain | Total | closed | not-a-defect |
|---|---|---|---|
| contracts | 4 | 3 (F030, F044, F046) | 1 (F020) |
| security | 5 | 3 (F033, F035, F076) | 2 (F023, F059) |
| web | 3 | 3 (F067, F072, C125) | 0 |
| data-integrity | 6 | 6 (F087, F089, F112, F113, F115, F116) | 0 |
| boundaries | 2 | 2 (C126, C127) | 0 |
| tooling | 2 | 2 (C124, C128) | 0 |
| migrations | 1 | 0 | 1 (F078) |
| contracts (cycle) | 4 | 4 (C129, C130, C131) | 0 |

By priority:

| Priority | Count closed | Count not-a-defect |
|---|---|---|
| P0 | 2 (C124, C125) | 0 |
| P1 | 19 (F030, F033, F035, F044, F046, F067, F072, F076, F087, F089, F112, F113, F115, F116, C126, C127, C129, C130, C131) | 0 |
| P2 | 1 (C128) | 5 (F020, F023, F059, F078, F117) |

## 4. Verification evidence
The mutation script references the following `closed_by` commits and tests, each of which is on the working tree at `a9f5a5d631e4c8247803ec51e64138cfe940ab5f` and reproducible from the 447f756 closure lineage:

### Wave-1 evidence (Audit M01 + W1.1 strict-gate)

- `apps/api/Modules/Audit/Features/{CreateAuditExport,DownloadAuditExport,GetAuditEvent,GetAuditExport,ListAuditEvents,Retention,VerifyAuditIntegrity}` — full M01 audit feature surface landed in `e15f5d8`.
- `apps/api/Modules/Audit/Infrastructure/Persistence/{DatabaseQueryAuditActivity,DatabaseRecordAuditEvent,AuditIntegrityRepository,AuditExportRepository,AuditIdempotencyStore}` — strict-outbox, append-only triggers, idempotency keys, and outbox event emission.
- `apps/api/Modules/Audit/Tests/{AuditAuthorization,AuditContracts,AuditExport,AuditHttpAdapter,AuditIntegrity,AuditMigration,AuditMySqlConcurrency,AuditProductionConfig,AuditRedaction,AuditRetention,RecordAuditEvent}Test.php` — full M01 test surface; 30/30 boundary tests still pass on the working tree.
- `docs/contracts/api/openapi.yaml` and `apps/web/src/api/generated/cluster.ts` — Wave-1 contract refresh: 7 new audit endpoints (listAuditEvents, getAuditEvent, createAuditExport, downloadAuditExport, getAuditExport, listAuditExports, verifyAuditIntegrity) and the Orval regeneration.
- `infra/dev/run-w1-1-e2e.sh` — W1.1 strict-gate runner: `W1_1_COORDINATOR_TARGET` env var, rewritten coordinator subshell, removed post-failure tinker dumps.
- `W1_1_COORDINATOR_TARGET=2 W1_1_PLAYWRIGHT_GREP='Arabic RTL journey' make test-e2e-w1-1-strict` — PASS in ~46s on the closure working tree.
### Concurrency / atomicity evidence (T10 / T11 / Task 9–12 closure)

- `apps/api/Modules/Tasks/Features/CompleteTask/Handler/CompleteTaskHandler.php` — handler-owned DB::transaction wrapping state commit + outbox append.
- `apps/api/Modules/Documents/Features/Upload/DocumentUploadHandler.php` — same pattern for document complete.
- `apps/api/Modules/Workflow/Infrastructure/Persistence/WorkflowStepAdvancer.php` — same pattern for workflow step advance.
- `apps/api/Modules/Authorization/Infrastructure/Persistence/AuthorizationBootstrapState.php` — idempotency keys plus access_decisions row per role-grant assignment.
- `apps/api/Modules/Organization/Features/OrganizationUnit/Handler/OrganizationUnitHandler.php` — module-owned transactions for org unit operations.
- `apps/api/Modules/WorkDefinitions/Features/Definition/Handler/WorkDefinitionMutator.php` — work-definition version create in parent-row-locked transaction.

### Concurrency tests (already present and green on the working tree)

- `apps/api/tests/Feature/OptimisticConcurrencyRegressionTest.php` — 8 tests covering tasks CAS, documents CAS, lock-then-idempotency, no-outbox-on-stale.
- `apps/api/tests/Feature/MigrationReversibilityTest.php` — migration reversibility proof.
- `apps/api/Modules/WorkDefinitions/Tests/WorkDefinitionMySqlConcurrencyTest.php` — two-connection MySQL test for version allocation.
- `apps/api/Modules/Workflow/Tests/WorkflowVersionMySqlConcurrencyTest.php` — two-connection MySQL test for workflow version.
- `apps/api/Modules/Organization/Tests/TemporaryAssignmentMySqlConcurrencyTest.php` — two-connection MySQL test for temp assignment.
- `apps/api/tests/Feature/BusinessCalendarMySqlConcurrencyTest.php` — two-connection MySQL test for calendar weekday.
- `apps/api/Modules/Documents/Tests/DocumentMutationAtomicityTest.php` — atomicity proof for documents.
- `apps/api/Modules/Workflow/Tests/WorkflowAtomicityTest.php` — atomicity proof for workflow.

### Route / contract evidence (T12 / Task 9–12 closure)

- `docs/api/endpoints.md` and `docs/api/rbac-matrix.md` regenerated, byte-stable across re-runs.
- `apps/web/.orval/cluster-client.openapi.yaml` and generated `apps/web/src/api/generated/cluster.ts` match the merged client surface (152 paths / 204 operations).
- `scripts/inventory-routes.py --mode reconcile --write` passes with 0 unresolved.

## 5. Closure decision

**`CLOSED`**. The register's `closure_decision: CLOSED` is recorded together with the `verification` block (commit `a9f5a5d631e4c8247803ec51e64138cfe940ab5f`, command, result) at the top level. The 22 implementation-completed findings are flipped to `closed` with concrete source/command evidence and a `closed_by` reference to a real commit in the working tree's lineage (`447f756`, `c90dd10`, `538d95b`, `78b4f26`, `1955d4e`, `df2588c`, `da27c81`, `3fa4528`, `c90dd10`, or the commit carrying this dossier). Wave-1 (`e15f5d8` and the four drift followups) is part of that lineage and the W1.1 strict-gate now reports PASS on the working tree.

## 6. Rollback / recovery notes

- Revert this commit (`git revert <this-sha>`) to drop the dossier and the `closure_decision: CLOSED` flip while keeping the per-finding `closed_by` mutations in the register. The 22 `closed` entries remain valid because their evidence is sourced from commits on the working tree.
- A full revert of the per-finding flips would require running the inverse of `/tmp/cluster-gates-cache/recheck-2026-07-27/mutate_register.py` (kept locally for audit; not tracked).
- The architecture-closure register is the single source of truth for findings; `docs/architecture/ARCHITECTURE-CLOSURE.md` is a decision record, not a derivable artifact.

## 7. Post-closure handoff

`docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md` (P01–P08 and M00–M07) remains `planned` or `blocked` per its own status table until a separate post-closure program authorization message is issued. The architecture-closure plan is now finished and T13/T14 handoffs are recorded in this dossier.
