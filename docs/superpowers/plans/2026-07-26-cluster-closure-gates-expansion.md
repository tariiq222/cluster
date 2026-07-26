# Cluster Closure-Gates Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: P08
status: blocked
depends_on:
  - ARCHITECTURE-CLOSURE:T13-HANDOFF
  - P01
  - P02
  - P03
  - P04
  - P05
  - P06
  - P07
  - M00
  - M01
  - M02
  - M03
  - M04
  - M05
  - M06
  - M07
blocks: []
shared_file_owner:
  - Makefile
  - .github/workflows/ci.yml
  - .github/workflows/ci-e2e.yml
conditional_shared_file_owner:
  PRODUCTION-POLICY:
    after: ARCHITECTURE-CLOSURE:T13-HANDOFF
    paths:
      - scripts/production_bundle_policy.py
  ARCHITECTURE-REGISTER:
    after: ARCHITECTURE-CLOSURE:T14-HANDOFF
    paths:
      - docs/architecture/architecture-closure-register.yaml
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
# Canonical encoding (identical in header and final manifest):
#   tree_digest = sha256( UTF-8( concat for each plan_id in ascending order:
#       plan_id + 0x00 + repo_relative_path + 0x00 + sha256 + 0x0A
#   ) )
# Scope is fully auditable: every plan_file_hashes entry carries its exact
# repo-relative path alongside the per-plan digest. P08 itself is included
# with its own path/sha256; the final manifest.yaml itself is NOT (its bytes
# are written after tree_digest is computed, so it cannot self-reference).
# Canonical plan paths under docs/superpowers/plans/ are:
#   M00..M07, P01..P07 = 2026-07-26-cluster-<topic>.md
#   P08                = 2026-07-26-cluster-closure-gates-expansion.md
tree_digest: "sha256(concat for each plan_id in ascending order of utf8(plan_id + 0x00 + docs/superpowers/plans/2026-07-26-cluster-<topic>.md + 0x00 + plan_file_hashes[plan_id].sha256 + 0x0A))"
```

**Goal:** Extend the existing architecture-closure gate with one final, failure-aggregating production and module program gate that emits an evidence-backed release `GO` or `NO-GO` decision for one recorded commit.

**Architecture:** `make verify-program-closure` is the only program-level entry point. It invokes the existing `make verify-architecture-closure` first, then a Python orchestrator executes the additional production, recovery, privacy, accessibility, performance, module-evidence, and production-E2E gates without hiding independent failures. The orchestrator binds every result to the same 40-character commit SHA and a caller-supplied unique `PROGRAM_RUN_ID`, atomically creates `artifacts/program-closure/<sha>/<program-run-id>/`, writes `manifest.yaml` and `PROGRAM-CLOSURE.md` there, and publishes `.complete.json` last; it never deploys, mutates production, or writes a run result into tracked source.

**Tech Stack:** GNU Make, GitHub Actions, Python 3.12 with PyYAML 6.0.2, PHP 8.4/Laravel 13.8/PHPUnit 12.5, MySQL, React 19/TypeScript 6/Vite 8/Vitest 4, Playwright 1.61.1, Docker Engine/Compose v2, OpenAPI 3.1, Redocly, Orval 8.22, Composer 2, Node.js 22/npm, gitleaks.

**Approved design:** `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`

---

## 1. Status header and dependency fields

P08 remains `blocked` until all of the following are recorded in `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md`:

1. Architecture Closure Task 13 has completed and granted `CLOSURE-CI` plus `PRODUCTION-POLICY` to P08; Task 14 has completed and granted `ARCHITECTURE-REGISTER` to P08. P08 retains all three tokens until its corresponding integration and targeted verification finish.
2. `P01`–`P07` and `M00`–`M07` have independently reached `completed`, with immutable completion manifests and non-null implementation/verification commits. A child never waits for P08 acceptance to complete.
3. Every earlier module registry, API route, OpenAPI/Orval, web shell, and production-topology token is `merged` and `released`. At P08 start, the only granted outstanding tokens are P08-held `CLOSURE-CI`, `PRODUCTION-POLICY`, and `ARCHITECTURE-REGISTER`.
4. Each child completion commit is an ancestor of the integrated HEAD. P08 accepts that immutable ancestor manifest for provenance, but reruns every critical verifier on the final HEAD and records fresh outputs only under the final SHA.
5. The integrated worktree is based on the recorded token grants; a stale grant is revoked and reissued rather than merged ad hoc.
6. The user has authorized implementation to proceed. This plan does not itself authorize a commit, push, tag, deployment, migration, cloud change, or release.

The dependency array expresses prerequisites, not child→P08 completion edges. P08 validates ancestor provenance and final-HEAD replay; it never requires a child manifest to have been created at the final SHA.

## 2. Goal and user-visible outcome

The user receives exactly one decision dossier for exactly one integrated commit:

- `GO`: every critical gate ran on the final SHA, no critical gate skipped, every child completion manifest commit is an ancestor of final HEAD, all fresh verifier evidence belongs to the final SHA, all required child plans completed independently, no production fake/stub/no-op remains, register reconciliation passed, and any retained risk has explicit user acceptance, scope, owner, and expiry.
- `NO-GO`: one or more gates failed, skipped, were blocked, were missing, were stale, or could not prove their required scenario. A `NO-GO` run exits non-zero and lists every known failure; it never downgrades a failure to a warning.

A `GO` decision is release eligibility evidence, not permission to deploy. Deployment remains a separately authorized operator action.

## 3. Current source evidence

The executor must re-read these exact sources before requesting the integration token and record their hashes in the final manifest:

- `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md:294-316` requires intake, contracts, `api:check`, API/web quality, boundaries, non-skipped MySQL, dependency/secret scans, production images, workload smoke, document lifecycle, recovery, accessibility, compliance, performance, and production E2E on one SHA.
- `docs/superpowers/plans/2026-07-26-cluster-complete-architecture-closure.md:1016-1101` reserves Task 13 ownership and defines `make verify-architecture-closure`; P08 invokes it and does not reproduce or weaken it.
- `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md:174-237` defines `CLOSURE-CI` ownership and the serialized token protocol; lines 274-318 define evidence and P08 authority.
- Current `Makefile` exposes `verify-intake`, `api:check`, `lint-api`, `analyse-api`, `test-api`, `test-web`, `test-web-unit`, `verify-boundaries`, `verify-mysql-integration`, `audit-dependencies`, `scan-secrets`, `validate-production-bundle`, `build-production-images`, `verify-production-images`, and `verify-w1-1-local`. Its current MySQL development target can return `SKIP` with exit 0, so the final orchestrator must treat `SKIP:` as a critical failure.
- Current `.github/workflows/ci.yml` separates intake, contracts, API/web static checks, secrets, unit tests, MySQL, npm audit, and production bundle. It currently calls `make api:check` in `contracts`, but it does not express the completed production/module program decision.
- Current `.github/workflows/ci-e2e.yml` runs on `[self-hosted, cluster-e2e]`, calls `make api:check`, and uploads Playwright results. P07 must make this runner operational before P08 owns final workflow wiring.
- `apps/web/package.json` defines `api:check` as Redocly lint plus `check-generated-api.mjs`; generated clients may change only via `npm --prefix apps/web run api:generate`.
- `docs/architecture/architecture-closure-register.yaml` currently tracks exactly the source-backed historical set `F020/F023/F030/F033/F035/F044/F046/F059/F067/F072/F076/F078/F087/F089/F112/F113/F115/F116/F117` plus sourced `C` findings. P08 must never recreate the 104 unsourced historical placeholders.
- `apps/api/tests/Feature/CiMakeSurfaceTest.php` already asserts current Make/CI surface behavior and is the repository-native location for red/green tests of final wiring.
- `scripts/production_bundle_policy.py`, `infra/platform/production/build-images.sh`, and `infra/platform/production/verify-images.sh` are the current production-bundle policy and image checks. After Task 13, P08 holds `PRODUCTION-POLICY` and may modify only `scripts/production_bundle_policy.py` to make the final policy fail closed; it composes the existing image checks rather than creating a second production topology.

Any current-state difference found at execution time is recorded as a newly validated `C` finding with source/command evidence before gate behavior is changed.

## 4. Scope and explicit non-goals

### In scope

- Add one program target that extends the existing architecture target.
- Aggregate independent failures and preserve per-command stdout/stderr, duration, exit code, skip detection, and SHA.
- Validate required child-plan completion and retained evidence.
- Replay critical production and cross-cutting gates on the integrated commit.
- Reconcile module boundaries, API contracts/generated output, architecture register, and program status.
- Wire the final target into both CI workflows only after the `CLOSURE-CI` handoff.
- Produce the machine manifest and human decision dossier.

### Explicit non-goals

- Do not create an alternative architecture-closure target or bypass `make verify-architecture-closure`.
- Do not edit `Makefile` or either workflow before Task 13 grants `CLOSURE-CI`; do not edit `scripts/production_bundle_policy.py` before Task 13 grants `PRODUCTION-POLICY`; do not edit the architecture register before Task 14 grants `ARCHITECTURE-REGISTER`.
- Do not modify production topology, worker/scheduler loops, `apps/api/routes/console.php`, module runtime code, master OpenAPI, generated clients, or module registry decisions under P08. The sole production-policy exception is `scripts/production_bundle_policy.py` under `PRODUCTION-POLICY`.
- Do not hand-edit `apps/web/src/api/generated/cluster.ts`; contract drift returns `NO-GO`.
- Do not fix a child-plan defect in the gate branch. Return the failure to its owning plan, then rerun P08 after reintegration.
- Do not allow a local missing prerequisite, an unavailable self-hosted runner, or a skipped test to count as closure success.
- Do not commit generated runtime evidence or record a commit unless the user separately authorizes it.
- Do not deploy, restore production data, roll back production, tag a release, or communicate a release externally.

## 5. Architecture and ownership boundaries

### Single entry point and execution order

`make verify-program-closure` must call `make verify-architecture-closure` as gate `G00` before any expansion gate. If `G00` fails, the orchestrator records it and may continue gates that are independent and safe; it marks dependent production scenarios `blocked` rather than running them against an invalid base. `blocked` is a critical failure, never a pass.
The gate catalog is canonical by ID; execution follows declared dependencies rather than lexical ID order. Catalog dependency semantics: a gate whose declared `dependencies` failed is marked `result=blocked` and never executed; a gate whose dependency is absent is marked `result=missing`; a gate whose evidence is older than the final SHA is marked `result=stale`. The orchestrator runs in three phases. (1) **Offline phase**: schedule every gate whose declared `dependencies` are still unblocked (initially `G00`–`G06`, `G09`, `G10`, and any other gate not requiring the live topology), aggregate their `pass`/`fail`/`blocked`/`missing`/`stale`/`timeout` results, and re-schedule after each gate completes until no runnable offline gate remains. (2) **Live phase** (only entered when every P07-dependent offline gate is `pass`): enter the single P07 lifecycle once, run `G07 → G08 → G11 → G12 → G13`; after trap cleanup, atomically copy/hash-bind the P07 retained evidence into the program root via `scripts/program-closure-hydrate.mjs`, then run `G14`. (3) **Decision phase** (unconditional finally-style): the orchestrator re-schedules any remaining runnable gate whose dependencies are now satisfied — including `G15` and `G16` even on the unhappy path, as long as their own dependencies permit — until no further gate can run. Then `G17` **always executes last** and emits an explicit `GO`/`NO-GO` decision record; only `GO` requires every `G00`–`G16` to report `pass`.

| ID | Required command or check | Required proof |
|---|---|---|
| `G00` | `make verify-architecture-closure` | Existing Task 13 gate passes; output contains no `SKIP:`. |
| `G01` | `make api:check` | Redocly passes and generated-client comparison reports no drift. |
| `G02` | `make audit-dependencies` | Composer and production npm audits exit 0 with no unresolved policy-blocking advisory. |
| `G03` | `make scan-secrets` | gitleaks exits 0. |
| `G04` | `python3 scripts/production_bundle_policy.py` (also reached by `make validate-production-bundle`) | P08-owned fail-closed production bundle policy passes on final HEAD. |
| `G05` | `make build-production-images` | All production images build from lockfiles. |
| `G06` | `make verify-production-images` | Runtime-only, non-root, healthy images pass. |
| `G07` | One merged P01/P02 verifier inside the already-live P07 topology: `./infra/platform/production/verify-workload-topology.sh --consumer P08 --commit "$SHA" --connection-manifest "$P07_CONNECTION_MANIFEST_PATH" --evidence-dir "$PROGRAM_EVIDENCE_ROOT/live/workload-topology/$P07_RUN_ID"` | One invocation proves the merged worker/scheduler plus S3/ClamAV topology, P01 signal/restart/outage/retry/recovery, and P02 clean/EICAR/outage/reconcile/migration scenarios without starting a second topology. |
| `G08` | Internal validation of the G07 workload-topology manifest | The merged manifest contains both P01 and P02 scenario sentinels, one topology/run identifier, final `$SHA`, and no skip marker. |
| `G09` | `bash infra/platform/production/test-backup-restore-release.sh`, then `infra/platform/production/recovery-drill.sh --source-env infra/platform/production/.env.production --drill-env infra/platform/production/.env.recovery --evidence-dir "$PROGRAM_EVIDENCE_ROOT/recovery" --confirm-isolated-target cluster-p08-recovery` (P08-owned fresh final-SHA evidence root; the P03-owned source `artifacts/p03-recovery/<UTC-run-id>/evidence.json` is consumed only as an ancestor-lineage reference, never written to by P08) | P03 safety tests and isolated database/object/queue restore and rollback pass on final HEAD; the recovery drill writes its fresh evidence to `$PROGRAM_EVIDENCE_ROOT/recovery/evidence.json`, `$PROGRAM_EVIDENCE_ROOT/recovery/manifest.json`, and `$PROGRAM_EVIDENCE_ROOT/recovery/cleanup.json`. The P08 manifest records `recovery_evidence_path`, `recovery_evidence_sha256`, `recovery_manifest_path`, `recovery_manifest_sha256`, the measured `rpo_seconds`, `rto_seconds`, and the exact PIT disclosure (`point_in_time_recovery_supported`, recovery window, and restore point or explicit unsupported reason), each matching `docs/operations/schemas/cluster-recovery-evidence.schema.json` and operator docs. The fresh evidence directory is atomically created, run-bound, non-overlapping with the P03 ancestor root, and rejects a pre-existing/symlinked/foreign/traversal location.
| `G10` | `bash scripts/verify-privacy-compliance.sh --commit "$SHA" --program-run-id "$PROGRAM_RUN_ID" --program-evidence-root "$PROGRAM_EVIDENCE_ROOT" --output-root "$PROGRAM_EVIDENCE_ROOT/replay/privacy"` | P04 PII/PHI inventories, threat cases, vendor boundaries, and incident rehearsal pass for final HEAD. The runner atomically creates the absent, unique program-run-scoped replay/output root `$PROGRAM_EVIDENCE_ROOT/replay/privacy/`, writes fresh final-SHA evidence beneath it (registers, dependency-manifest hashes, incident rehearsal, command outputs, manifests), and emits `$PROGRAM_EVIDENCE_ROOT/replay/privacy/manifest.json`. The P08 manifest records `privacy_replay_root`, `privacy_replay_manifest_path`, `privacy_replay_manifest_sha256`, and `privacy_replay_manifest_commit_sha` (must equal `$SHA`). The fresh evidence directory is run-bound, non-overlapping with the P04 ancestor root `artifacts/privacy-compliance/<sha>/`, and rejects a pre-existing/symlinked/foreign/traversal location. The P04-owned runner never writes under the P04 ancestor root from a P08 invocation; the ancestor root remains immutable P04 completion evidence. P04 plan's `verify-privacy-compliance.sh` adds `--program-run-id`/`--program-evidence-root`/`--output-root` options so the script can produce both its independent commit-bound manifest (for P04 completion) and a separate program-run-scoped replay manifest (for P08 closure) without conflating the two. |
| `G11` | Inside the live P07 topology, `scripts/run-program-live-gates.sh --commit "$SHA" --program-run-id "$PROGRAM_RUN_ID" --program-evidence-root "$PROGRAM_EVIDENCE_ROOT"` validates `P07_RUN_ID` and invokes `node scripts/run-accessibility-live.mjs --mode replay` with `PROGRAM_RUN_ID`, `PROGRAM_EVIDENCE_ROOT`, the two P07 manifest-pointer keys (`P07_CONNECTION_MANIFEST_PATH`, `P07_CONNECTION_MANIFEST_ENV_PATH`), and the lifecycle control key (`P07_DEPENDENT_RESULT_PATH`). The wrapper parses `$P07_CONNECTION_MANIFEST_PATH`, validates the JSON, parses `P07_COMMIT_SHA` from it, **generates** `P05_RUN_ID` internally as a UUIDv7 (never supplied by any caller), and derives `$P05_EVIDENCE_ROOT = $PROGRAM_EVIDENCE_ROOT/live/accessibility/$P05_RUN_ID` itself. The wrapper's structured result returns `p05_live_root`, `p05_live_manifest_path`, `p05_live_manifest_sha256`, and `p05_run_id` for G14 to consume. | The P05-owned live runner writes reports plus an immutable `live-manifest.json` only under `$PROGRAM_EVIDENCE_ROOT/live/accessibility/$P05_RUN_ID/` (the path the wrapper itself constructed); route inventory is non-empty, every route is covered, and no A/AA finding remains open. G11 rejects a symlink, pre-existing/wrong root, wrong mode/SHA/run ID, any caller pre-set `P05_RUN_ID` or `P05_EVIDENCE_ROOT` (the wrapper owns those values), or any write to `artifacts/accessibility/`, `artifacts/accessibility-live/`, the checked-in descriptor, the sealed static P05 child manifest at `artifacts/accessibility/$P05_CHILD_COMMIT_SHA/manifest.json`, or any ancestor P05 evidence. A same-HEAD rerun uses a new `PROGRAM_RUN_ID` and a freshly generated `P05_RUN_ID`, never this sealed root. |
| `G12` | The same live wrapper then runs `node scripts/quality/verify-p06.mjs --mode replay --commit "$SHA" --connection-manifest "$P07_CONNECTION_MANIFEST_PATH" --budgets quality/performance/p06-budgets.json --allowlist quality/performance/p06-warning-allowlist.json --api-origin "$P07_API_HTTPS_ORIGIN" --web-origin "$P07_WEB_HTTPS_ORIGIN" --output "$PROGRAM_EVIDENCE_ROOT/live/p06/$P07_RUN_ID"`. The wrapper parses the same JSON manifest for `$P07_API_HTTPS_ORIGIN`, `$P07_WEB_HTTPS_ORIGIN`, and the P06 credentials (`P06_USERNAME`, `P06_PASSWORD`, `P06_SCOPE_ID`); the calling shell never pre-sets them. | P06 receives the live connection/fixture manifest plus the parsed origins and credentials; latency, bundle, chunk, warning, static-analysis, and coverage budgets pass with no skip and no credential persistence. The replay output root is absent before G12, P07-run-scoped, and sealed without changing P06 child evidence at `test-results/p06/<p06-child-commit>/`. |
| `G13` | Internal P07 `run` phase reached only after the G07/G08/G11/G12 live wrapper exits 0. | P07 journeys pass against the same already-running topology; the enclosing lifecycle trap then stops it and proves cleanup. Before seal, P08 atomically copies (and hash-binds) the P07 retained evidence directory `artifacts/production-e2e/$P07_RUN_ID/` into `$PROGRAM_EVIDENCE_ROOT/live/p07/$P07_RUN_ID/` via the `scripts/program-closure-hydrate.mjs` step, which records a SHA-256 of every copied file into `$PROGRAM_EVIDENCE_ROOT/live/p07/$P07_RUN_ID/.copy-index.yaml`. The copy is fail-closed: any missing file, size mismatch, or digest mismatch between the source and the copy forces `NO-GO`. The copied directory is bound by SHA-256 of the entire `.copy-index.yaml` and recorded in the P08 manifest as `p07_copy_root` and `p07_copy_index_sha256`. No second `run-local-e2e.sh` invocation is permitted. |
| `G14` | Post-lifecycle `validate_plan_evidence()` reads the resolver-exported immutable P05 inputs (`P05_DESCRIPTOR_BYTES`, `P05_DESCRIPTOR_PATH`, `P05_DESCRIPTOR_SHA256`, `P05_CHILD_MANIFEST_PATH` = `artifacts/accessibility/$P05_CHILD_COMMIT_SHA/manifest.json`, `P05_CHILD_MANIFEST_SHA256`, `P05_MANIFEST_PATH` = `artifacts/accessibility-manual/$P05_CHILD_COMMIT_SHA/manifest.json`, `P05_MANIFEST_SHA256`), the G11-structured P05 live inputs (`P05_LIVE_ROOT`, `P05_LIVE_MANIFEST_PATH`, `P05_LIVE_MANIFEST_SHA256`, `P05_RUN_ID`), the G13-copied P07 manifest inputs (`P07_MANIFEST_PATH` = `$PROGRAM_EVIDENCE_ROOT/live/p07/$P07_RUN_ID/manifest.json` (the program-rooted copy, **never** the persistent-runner source `artifacts/production-e2e/$P07_RUN_ID/manifest.json`), `P07_MANIFEST_SHA256`, `P07_RUN_ID`), and the final SHA. It proves `$P05_LIVE_ROOT == "$PROGRAM_EVIDENCE_ROOT/live/accessibility/$P05_RUN_ID"`. It then **recomputes every `manual_files_digested` entry's `path` from the exact final `$SHA`** (not from `$P05_CHILD_COMMIT_SHA`) using `git show $SHA:<path>` (or the equivalent digest resolver) and compares the recomputed SHA-256 against the ancestor-recorded `sha256` value; any missing path (path not present at final SHA), any newly relevant path (a file that was not in the ancestor `manual_files_digested` but is referenced by an active `A11Y-###` finding's `remediation_files` or `evidence_files` at final SHA), or any byte mismatch forces `conformance_decision=NOT_READY` and rejects the manual matrix — the gate then requires a fresh full manual matrix at the new final SHA. Only after every `manual_files_digested` entry recomputes cleanly at final SHA does it invoke `node scripts/validate-accessibility-evidence.mjs --mode replay --commit "$SHA" --child-commit "$P05_CHILD_COMMIT_SHA" --child-manifest "$P05_CHILD_MANIFEST_PATH" --child-manifest-sha256 "$P05_CHILD_MANIFEST_SHA256" --live-manifest "$P05_LIVE_MANIFEST_PATH" --live-manifest-sha256 "$P05_LIVE_MANIFEST_SHA256" --manual-manifest "$P05_MANIFEST_PATH" --manual-manifest-sha256 "$P05_MANIFEST_SHA256" --p07-manifest "$P07_MANIFEST_PATH" --p07-manifest-sha256 "$P07_MANIFEST_SHA256" --output "$PROGRAM_EVIDENCE_ROOT/closure/accessibility.json"`. | The P08-owned closure artifact binds immutable P05 descriptor bytes/digest, immutable P05 sealed static child-manifest digest, immutable sealed manual-manifest digest, immutable final-SHA P05 live-manifest digest, exact program-rooted same-SHA/same-run P07 manifest digest, and final SHA. G14 writes only `$PROGRAM_EVIDENCE_ROOT/closure/accessibility.json` (and a paired `.complete.json` last); it never mutates the descriptor, the P05 sealed child manifest, the manual manifest, the P05 live root, the persistent-runner P07 source, or the program-rooted P07 copy. After a publication crash it may rerun only with explicit `--resume-seal` and the same exact inputs/root; it revalidates every byte, recreates only missing P08-owned finalizer output, and never reruns/fabricates live evidence. Unprovable partial evidence requires a new full lifecycle/run ID. |
| `G15` | Internal `validate_module_and_contract_closure()` | Route inventory, boundaries, tables/ranks/contracts, generated client, and module integration queues reconcile. Every M01–M07 immutable child manifest commit must be an ancestor of `$SHA`; every plan-declared API/MySQL/web sentinel must execute at final SHA with `pass` and no skip; the generated `apps/web/src/api/generated/cluster.ts` must equal the output of `npm --prefix apps/web run api:generate` at `$SHA`; and the route inventory must match the live P07 manifest's `route_inventory_sha256`. Any discrepancy yields `NO-GO` with the named module/contract/table/sentinel. |
| `G16` | Internal `validate_register()` | Only source-backed historical/C findings exist; closure statuses and evidence are valid. The 19 approved historical F IDs (`F020/F023/F030/F033/F035/F044/F046/F059/F067/F072/F076/F078/F087/F089/F112/F113/F115/F116/F117`) plus any sourced `C` ID with `sourced: true`, a non-empty exit criterion, and source/command evidence may appear; the 104 unsourced historical placeholders are forbidden. Any open or blocked P0/P1 finding, any accepted P2 risk without user approval or with expiry before run time, or any terminal finding without evidence yields `NO-GO` with the named finding/risk. |
| `G17` | Internal `decide_release()` | **Always runs** after every runnable gate completes (or after declared `blocked`/`missing`/`stale`/`timeout` for unrunnable gates). Emits an explicit decision record: `GO` only when every gate from `G00` through `G16` reports `result=pass` (no `fail`/`blocked`/`missing`/`stale`/`timeout`/`skipped`) and no unapproved risk remains; otherwise `NO-GO` with a structured `failed_gates` list (every gate with non-pass result), `blocked_gates` list, `missing_gates` list, `stale_gates` list, and `unapproved_risks` list. The catalog dependency semantics are: a gate whose declared `dependencies` failed is marked `blocked` and never executed; a gate whose dependency is absent is marked `missing`; a gate whose evidence is older than the final SHA is marked `stale`; only the catalog declares ordering, and only `GO` requires `G00`–`G16` all pass. The decision record is written to `$PROGRAM_EVIDENCE_ROOT/decision.json` and the `PROGRAM-CLOSURE.md` dossier. Exit code: `0` for `GO`, `1` for `NO-GO`. |

P02 owns the G07 verifier and G08 manifest contract, P03 owns the G09 commands, and P06 owns the G12 command. Their absence when P08 starts is `missing`, produces `NO-GO`, and is returned to that owner; P08 does not implement substitutes.

### Module boundary rule

The gate validates the invariant `module-owned controller → validation/capability → handler/service → module-owned persistence`. Cross-module use is permitted only through published `Contracts/` or `Events/`. Direct cross-owner SQL, foreign keys to another owner’s table, imports of another module’s Domain/Infrastructure, production test fakes, duplicate routes, or unprocessed integration tokens produce `NO-GO`.

## 6. Files to create, modify, move, or remove

### Create

- `scripts/program-closure-gates.yaml` — ordered, critical gate catalog with exact argv, dependencies, skip policy, and expected evidence paths.
- `scripts/run-program-closure.py` — safe subprocess orchestrator, child-plan/register validators, failure aggregation, manifest writer, and decision renderer.
- `scripts/run-program-live-gates.sh` — P08-owned dependent-gate wrapper passed as argv to the P07 lifecycle; from the safely sourced P07 environment it runs G07, validates G08, runs G11, then runs G12, and never starts or stops topology itself.
- `scripts/tests/__init__.py` — Python test package marker.
- `scripts/tests/test_run_program_closure.py` — unit tests for ordering, aggregation, stale/missing/skip handling, SHA binding, manifest schema, and decision semantics.
- Runtime output only: `artifacts/program-closure/<sha>/<program-run-id>/PROGRAM-CLOSURE.md` — generated decision dossier from that completed P08 run; `GO` or `NO-GO`, never an optimistic template or tracked source edit.

### Modify only after the named token is granted

- Under `CLOSURE-CI` after Task 13: `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, and `apps/api/tests/Feature/CiMakeSurfaceTest.php` — add the singular entry point, CI wiring, and handoff-safe contract assertions without altering `verify-architecture-closure` semantics.
- Under `PRODUCTION-POLICY` after Task 13: `scripts/production_bundle_policy.py` — add final fail-closed policy checks and no-skip/module-sentinel enforcement; do not create a second policy implementation.
- Under `ARCHITECTURE-REGISTER` after Task 14: `docs/architecture/architecture-closure-register.yaml` — reconcile statuses/evidence only from the integrated final run and add only newly sourced `C` findings.
- `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md` — after a user-authorized status transition, record all three P08 token states and the program decision summary.
- This plan file — after a user-authorized status transition, set `implementation_commit`, `last_verified_commit`, and status according to Section 15.

### Move or remove

None planned. If execution discovers a second program-level closure target, stop and return it to its owner for an explicit user-approved consolidation decision; do not silently delete it.

## 7. Public Contracts, Events, routes, schemas, and capability names

P08 publishes no application route, PHP Contract, domain Event, database schema, or capability code. It validates the final integrated surfaces frozen by M00 and applied serially by M01–M07.

The evidence manifest is the only new machine contract. `artifacts/program-closure/<sha>/<program-run-id>/manifest.yaml` must use:

```yaml
schema_version: 1
plan_id: P08
commit: 0123456789abcdef0123456789abcdef01234567
program_run_id: p08-20260726T120000Z-7f3a9c2d4e6b8a10
started_at: '2026-07-26T12:00:00Z'
finished_at: '2026-07-26T12:45:00Z'
decision: GO
commands:
  - gate_id: G00
    command: make verify-architecture-closure
    exit_code: 0
    result: pass
    started_at: '2026-07-26T12:00:01Z'
    finished_at: '2026-07-26T12:20:00Z'
    stdout_path: artifacts/program-closure/0123456789abcdef0123456789abcdef01234567/p08-20260726T120000Z-7f3a9c2d4e6b8a10/G00.stdout.log
    stderr_path: artifacts/program-closure/0123456789abcdef0123456789abcdef01234567/p08-20260726T120000Z-7f3a9c2d4e6b8a10/G00.stderr.log
    stdout_sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae
    stderr_sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
    skip_markers: []
smoke_scenarios:
  - name: workers-scheduler-restart-and-recovery
    result: pass
    evidence_path: docs/architecture/evidence/P01/0123456789abcdef0123456789abcdef01234567/manifest.json
child_plans:
  - plan_id: P01
    status: completed
    commit: 0123456789abcdef0123456789abcdef01234567
    manifest_path: docs/architecture/evidence/P01/0123456789abcdef0123456789abcdef01234567/manifest.json
open_findings: []
accepted_risks: []
register:
  path: docs/architecture/architecture-closure-register.yaml
  sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae
  unresolved_p0_p1: []
# Per-plan sha256(UTF-8 bytes) at final $SHA, paired with the exact repo-relative
# path so scope is fully auditable. Entries are ordered ascending by plan_id
# (M00..M07 then P01..P08). P08 itself is included; the final manifest.yaml
# itself is NOT (its bytes are written after tree_digest is computed, so it
# cannot self-reference). The tree_digest scalar below uses the canonical
# encoding defined in the YAML header comment.
plan_file_hashes:
  M00: { path: docs/superpowers/plans/2026-07-26-cluster-planned-module-contracts-baseline.md, sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M01: { path: docs/superpowers/plans/2026-07-26-cluster-audit-module.md,                 sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M02: { path: docs/superpowers/plans/2026-07-26-cluster-records-governance-module.md,    sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M03: { path: docs/superpowers/plans/2026-07-26-cluster-collaboration-module.md,          sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M04: { path: docs/superpowers/plans/2026-07-26-cluster-strategy-module.md,              sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M05: { path: docs/superpowers/plans/2026-07-26-cluster-portfolio-projects-module.md,    sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M06: { path: docs/superpowers/plans/2026-07-26-cluster-risk-module.md,                  sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  M07: { path: docs/superpowers/plans/2026-07-26-cluster-workspace-module.md,             sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P01: { path: docs/superpowers/plans/2026-07-26-cluster-production-workers-scheduler.md, sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P02: { path: docs/superpowers/plans/2026-07-26-cluster-documents-s3-clamav-production.md,  sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P03: { path: docs/superpowers/plans/2026-07-26-cluster-backup-restore-release-rollback.md, sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P04: { path: docs/superpowers/plans/2026-07-26-cluster-healthcare-privacy-compliance.md, sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P05: { path: docs/superpowers/plans/2026-07-26-cluster-accessibility-wcag.md,            sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P06: { path: docs/superpowers/plans/2026-07-26-cluster-quality-performance-hardening.md, sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P07: { path: docs/superpowers/plans/2026-07-26-cluster-e2e-runner-readiness.md,        sha256: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae }
  P08: { path: docs/superpowers/plans/2026-07-26-cluster-closure-gates-expansion.md,      sha256: 7d8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f }
# Canonical encoding (identical in header and final manifest):
#   tree_digest = sha256( UTF-8( concat for each plan_id in ascending order:
#       plan_id + 0x00 + plan_file_hashes[plan_id].path + 0x00 + plan_file_hashes[plan_id].sha256 + 0x0A
#   ) )
# The writer recomputes tree_digest from plan_file_hashes at seal time and
# rejects any mismatch. P08's own entry is included; the final manifest.yaml
# itself is NOT (its bytes are written after tree_digest is computed, so it
# cannot self-reference).
tree_digest: 2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae
integration_tokens:
  outstanding: []
```

The example hash values demonstrate valid types only. The writer computes all timestamps, paths, SHA-256 values, and commit values from the actual run; an executor never copies the example values.

Final API checks must prove these semantics wherever applicable: `application/problem+json`, `X-Correlation-ID`, session/CSRF enforcement, capability checks before disclosure, `Idempotency-Key`, `If-Match`/ETags/`lock_version`, bounded cursor pagination, and transactional outbox behavior. P08 does not weaken a route to satisfy the gate.

`G07`, `G08`, and `G11`–`G13` run inside one P07-owned bounded lifecycle. Before invocation, P08 validates `PROGRAM_RUN_ID` against `^p08-[a-z0-9][a-z0-9-]{7,95}$`, atomically creates the absent repository-relative `PROGRAM_EVIDENCE_ROOT=artifacts/program-closure/$SHA/$PROGRAM_RUN_ID`, and writes `.incomplete.json`; a collision fails rather than reuses evidence. P08 invokes the P07 lifecycle exactly as `./infra/platform/production/run-local-e2e.sh lifecycle -- scripts/run-program-live-gates.sh --commit "$SHA" --program-run-id "$PROGRAM_RUN_ID" --program-evidence-root "$PROGRAM_EVIDENCE_ROOT"`. The lifecycle creates and seals the connection manifest only after its internal `start` reports `topology_ready=true`, injects only `P07_CONNECTION_MANIFEST_PATH`, `P07_CONNECTION_MANIFEST_ENV_PATH`, and `P07_DEPENDENT_RESULT_PATH` into the dependent gate's child env, and the dependent gate parses the JSON manifest itself (it never receives `P07_COMMIT_SHA`, `P07_RUN_ID`, `P03_RECOVERY_MANIFEST_PATH`, `P03_RECOVERY_MANIFEST_SHA256`, `P07_WEB_HTTPS_ORIGIN`, `P07_CA_BUNDLE_PATH`, or any other P07 payload key from the caller). `P07_COMMIT_SHA` is parsed from the validated JSON by the dependent wrapper, not pre-set by P08.

P05 evidence has five ordered inputs, all bound by `G14`: (1) the immutable P05 descriptor bytes at the recorded `P05_DESCRIPTOR_PATH`, with `P05_DESCRIPTOR_SHA256` matching the recorded digest; (2) the immutable P05 sealed static child manifest at `P05_CHILD_MANIFEST_PATH=artifacts/accessibility/$P05_CHILD_COMMIT_SHA/manifest.json` with `P05_CHILD_MANIFEST_SHA256`; (3) the immutable sealed manual manifest at `P05_MANIFEST_PATH=artifacts/accessibility-manual/$P05_CHILD_COMMIT_SHA/manifest.json` with `P05_MANIFEST_SHA256`; (4) the G11-structured P05 live manifest at `P05_LIVE_ROOT=$PROGRAM_EVIDENCE_ROOT/live/accessibility/$P05_RUN_ID/`, where `$P05_RUN_ID` is the UUIDv7 the P05 wrapper itself generated during the live phase (never supplied by any caller), with `P05_LIVE_MANIFEST_SHA256`; and (5) the G13-copied P07 manifest at `P07_MANIFEST_PATH=$PROGRAM_EVIDENCE_ROOT/live/p07/$P07_RUN_ID/manifest.json` (the program-rooted copy, **never** the persistent-runner source `artifacts/production-e2e/$P07_RUN_ID/manifest.json`), with `P07_MANIFEST_SHA256` and `P07_RUN_ID`. G14 first proves `$P05_LIVE_ROOT` matches the program-rooted path above, then recomputes every `manual_files_digested` `{path, sha256}` entry from the exact **final `$SHA`** (not from `P05_CHILD_COMMIT_SHA`) — any missing path at final SHA, any newly relevant path (referenced by an active `A11Y-###` finding's `remediation_files` or `evidence_files` at final SHA), or any byte mismatch forces `conformance_decision=NOT_READY` and rejects the manual matrix (the gate then requires a fresh full manual matrix at the new final SHA). Only after every `manual_files_digested` entry recomputes cleanly at final SHA does G14 invoke the validator in `p08-replay` mode and write only `$PROGRAM_EVIDENCE_ROOT/closure/accessibility.json` plus a paired `.complete.json` last; G14 never mutates the descriptor, the sealed P05 child manifest, the manual manifest, the P05 live root, the persistent-runner P07 source, or the program-rooted P07 copy.

The child evidence resolver recognizes each plan’s immutable completion output at its recorded completion commit. It verifies `git merge-base --is-ancestor <child-commit> $SHA`, schema, hashes, terminal status, and absence of skip markers; it does not require the child commit to equal `$SHA`. P08 then reruns every critical child verifier and records fresh outputs only under the exact `$PROGRAM_EVIDENCE_ROOT`; child-owned immutable evidence remains at its canonical source path. Exactly one completion manifest per plan is accepted:

- P01: `docs/architecture/evidence/P01/<child-commit>/manifest.json`; final replay is the P01 half of merged G07.
- P02: `artifacts/production-readiness/P02/<child-commit>/manifest.yaml`; final replay is the P02 half of merged G07.
- P03: one successful `artifacts/p03-recovery/<UTC-run-id>/evidence.json` at its completion commit; G09 creates fresh final-SHA evidence and must verify exact RPO/RTO plus PIT-support disclosure fields against `docs/operations/schemas/cluster-recovery-evidence.schema.json` and operator docs.
- P04: `artifacts/privacy-compliance/<child-commit>/manifest.json`; G10 replays with `--commit "$SHA"`.
- P05: exact `artifacts/accessibility/<child-commit>/manifest.json` plus its recorded digest and descriptor bytes from that child commit; the resolver exports those exact values, G11 emits final-SHA live replay payloads, and post-lifecycle G14 writes a separate P08 closure artifact binding descriptor, child, replay, and exact completed same-SHA P07 manifest digests without discovery or input mutation.
- P06: `test-results/p06/<child-commit>/manifest.json`; G12 emits final-SHA results.
- P07: one successful `artifacts/production-e2e/<run-id>/manifest.json` at its completion commit; the bounded lifecycle and G13 emit final-SHA runtime and cleanup evidence.
- M00–M07: the exact immutable manifest path declared by each completed plan; final replay executes each module’s named API/MySQL/web test sentinel and records final-SHA output.

The fixed M00 matrix is a gate input, not a P08 design choice:

| Plan | Rank | API prefix | Web prefix | Capability contract |
|---|---:|---|---|---|
| M01 Audit | 3 | `/api/v1/audit` | `/audit` | `audit.event.read`, `audit.event.export`, `audit.integrity.verify` |
| M02 RecordsGovernance | 4 | `/api/v1/records-governance` | `/records-governance` | `records_governance.retention-policy.read`, `records_governance.retention-policy.manage`, `records_governance.retention-policy.publish`, `records_governance.record.read`, `records_governance.record.register`, `records_governance.hold.read`, `records_governance.hold.manage`, `records_governance.disposition.read`, `records_governance.disposition.review`, `records_governance.disposition.confirm` |
| M03 Collaboration | 6 | `/api/v1/collaboration` | `/collaboration` | `collaboration.thread.create`, `collaboration.thread.read`, `collaboration.thread.list`, `collaboration.thread.update`, `collaboration.thread.archive`, `collaboration.membership.manage`, `collaboration.comment.create`, `collaboration.comment.edit`, `collaboration.comment.moderate` |
| M04 Strategy | 8 | `/api/v1/strategy` | `/strategy` | `strategy.plan.read`, `strategy.plan.manage`, `strategy.indicator.read`, `strategy.indicator.manage`, `strategy.measurement.submit`, `strategy.measurement.approve`, `strategy.impact.read` |
| M05 PortfolioProjects | 9 | `/api/v1/portfolio` | `/portfolio` | `portfolio_projects.portfolio.read`, `portfolio_projects.portfolio.manage`, `portfolio_projects.project.read`, `portfolio_projects.project.manage`, `portfolio_projects.milestone.approve`, `portfolio_projects.impact.submit`, `portfolio_projects.budget.read` |
| M06 Risk | 10 | `/api/v1/risk` | `/risk` | `risk.risk.read`, `risk.risk.manage`, `risk.assess`, `risk.control.manage`, `risk.treatment.manage`, `risk.accept`, `risk.kri.manage` |
| M07 Workspace | 11 | `/api/v1/workspace` | `/workspace` | `workspace.read`, `workspace.preferences.update` |

Published read/write seams must match exactly: M01 `RecordAuditEvent::record(AuditEventInput): AuditEventReceipt` and `QueryAuditActivity::query(AuditActivityQuery): AuditActivityPage<AuditActivityItem>`; M02 `RegisterGovernedRecord::register(GovernedRecordRegistration): GovernedRecordStatus`, `ReadGovernedRecordStatus::get(RecordSourceReference): ?GovernedRecordStatus`, `GuardDispositionExecution::evaluate(RecordSourceReference): DispositionExecutionDecision`, and `QueryRecordsGovernanceSummary::forScope(RecordsGovernanceSummaryQuery): RecordsGovernanceSummary`; M03 `OpenCollaborationThread::open(CollaborationThreadRegistration): CollaborationThreadReference` and `ListVisibleCollaborationThreads::list(CollaborationThreadQuery): CollaborationThreadPage<CollaborationThreadSummary>`; M04 `ResolveStrategyReference::resolve(StrategyResourceType, string): ?StrategyReference` and `GetStrategySnapshot::forOrganizationUnit(string, ?string = null): StrategySnapshot`; M05 `ResolveAuthorizedProjectReference::resolve(ProjectAccessContext, string): ?ProjectReference` and `ListAuthorizedProjectSummaries::list(AuthorizedProjectSummaryQuery): AuthorizedProjectSummaryPage<AuthorizedProjectSummary>`; M06 `ResolveRiskReference::resolve(RiskAccessContext, string): ?RiskReference` and `QueryRiskWorkspaceItems::query(RiskWorkspaceQuery): RiskWorkspacePage<RiskWorkspaceItem>`. M07 publishes no cross-module Contract/Event and consumes only the six M01–M06 query contracts; it must not import same-rank Notifications/Search/Reporting, which the web composes separately.

Planned event types must obey `com.cluster.<module-token>.<lowercase-event-class-name-without-V1>.v1` with tokens `audit`, `recordsgovernance`, `collaboration`, `strategy`, `portfolioprojects`, `risk`, and `workspace`. Hyphenated/snake-case event segments are drift. M07 publishes no event. Registry, API route, OpenAPI/Orval, and shell queues must show the serial application order `M01 → M02 → M03 → M04 → M05 → M06 → M07`.

## 8. Database tables, indexes, constraints, migration order, and recovery

P08 creates no migration and owns no table. `G15` must validate the M00 decisions and integrated module registry:

- every migration-created table has exactly one owner;
- every `TABLE_OWNERS` entry maps to a real migration;
- every module rank and planned-module entry matches M00;
- no direct cross-owner foreign key or SQL access exists;
- unique/idempotency indexes and optimistic-concurrency predicates required by child contracts are exercised by API/MySQL tests;
- the integrated migration set applies cleanly to an empty MySQL database and child rollback/recovery evidence is tied to `$SHA`;
- P03 restore runs only into isolated disposable infrastructure, verifies database/object/queue integrity, and cleans it up;
- deployed migrations remain append-only; rollback means application/release rollback plus the P03 data-recovery procedure, not destructive down-migration on production.

Exact planned ownership is:

- M01: `audit_events`, `audit_export_jobs`, `audit_integrity_checkpoints`, `audit_idempotency_keys`; it does not own `audit_retention_policies`, and Authorization retains `sensitive_access_events` absent an explicit handoff.
- M02: `records_governance_retention_policy_versions`, `records_governance_retention_policy_rules`, `records_governance_governed_records`, `records_governance_holds`, `records_governance_disposition_reviews`, `records_governance_evidence`, `records_governance_idempotency_keys`.
- M03: `collaboration_threads`, `collaboration_thread_memberships`, `collaboration_comments`, `collaboration_mentions`, `collaboration_comment_revisions`, `collaboration_moderation_actions`, `collaboration_idempotency_keys`; it owns no outbox or attachment table.
- M04: `strategy_periods`, `strategy_plans`, `strategy_plan_versions`, `strategy_objectives`, `strategy_outcomes`, `strategy_indicators`, `strategy_indicator_periods`, `strategy_target_distributions`, `strategy_measurements`, `strategy_progress_evidence`, `strategy_approvals`, `strategy_idempotency_keys`.
- M05: `portfolio_projects_portfolios`, `portfolio_projects_programs`, `portfolio_projects_projects`, `portfolio_projects_project_templates`, `portfolio_projects_milestones`, `portfolio_projects_health_snapshots`, `portfolio_projects_budget_snapshots`, `portfolio_projects_indicator_links`, `portfolio_projects_idempotency_keys`.
- M06: `risk_registers`, `risk_policy_versions`, `risks`, `risk_assessments`, `risk_controls`, `risk_treatments`, `risk_treatment_actions`, `risk_reviews`, `risk_indicators`, `risk_indicator_readings`, `risk_idempotency_keys`.
- M07: `workspace_preferences` only; Workspace never persists or caches producer facts.

A migration, ownership, or recovery discrepancy creates a sourced `C` finding and `NO-GO`; P08 never edits a module migration to suppress it.

## 9. TDD implementation tasks

### Task 1: Freeze the gate/evidence contract before shared-file integration

**Files:**
- Create: `scripts/program-closure-gates.yaml`
- Create: `scripts/tests/__init__.py`
- Create: `scripts/tests/test_run_program_closure.py`
- Create: `scripts/run-program-closure.py`
- Read: all completed child plans and evidence manifests

**Interfaces:**
- Consumes: recorded HEAD SHA, canonical plan metadata, child evidence manifests, architecture register, exact commands `G00`–`G13`.
- Produces: `run(catalog_path: Path, repo_root: Path, commit: str) -> ClosureRun`; process exit 0 only for `GO`, exit 1 for `NO-GO`, exit 2 for malformed invocation/catalog.

- [ ] **Step 1: Prove all start gates before creating files**

Run:

```bash
python3 - <<'PY'
from pathlib import Path
required = ['P01','P02','P03','P04','P05','P06','P07','M00','M01','M02','M03','M04','M05','M06','M07']
text = Path('docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md').read_text()
missing = [plan for plan in required if f'| `{plan}` |' not in text or f'| `{plan}` |' in text and '| `completed` |' not in next(line for line in text.splitlines() if f'| `{plan}` |' in line)]
raise SystemExit('blocked: incomplete plans: ' + ', '.join(missing) if missing else 0)
PY
```

Expected before dependencies complete: non-zero with the exact incomplete IDs. Expected when P08 may start: exit 0. Separately verify `CLOSURE-CI` and `PRODUCTION-POLICY` were granted after Task 13, `ARCHITECTURE-REGISTER` after Task 14, their base commits match the integrated ancestry, and every earlier token is merged/released. These three remain granted to P08 until their integrations and targeted checks finish.

- [ ] **Step 2: Write failing Python tests**

Use temporary fixture repositories and fake executable argv. Create these exact test methods and assertions:

| Test method | Fixture/action | Required assertion |
|---|---|---|
| `test_architecture_gate_is_first_and_required` | Catalog starts with `G01` or omits `G00`. | Catalog validation exits 2 and names `G00`/index zero. |
| `test_failure_does_not_hide_independent_later_failures` | `G00` fake exits 1; independent `G10` fake exits 3. | Both execute; manifest records both failures; decision is `NO-GO`. |
| `test_dependent_gate_is_blocked_after_prerequisite_failure` | `G05` fails and `G06` depends on it. | `G06` executable is not called and result is `blocked`. |
| `test_zero_exit_with_skip_marker_is_failure` | Fake exits 0 after printing `SKIP: docker unavailable`. | Gate result is `fail`, marker is retained, decision is `NO-GO`. |
| `test_child_completion_manifest_may_be_ancestor` | Child manifest commit differs from final SHA but `merge-base --is-ancestor` succeeds. | G14 accepts provenance and still requires a fresh final-SHA verifier result. |
| `test_non_ancestor_child_manifest_is_stale` | Child completion commit is not an ancestor of final SHA. | G14 is `stale` and records both SHAs. |
| `test_missing_final_replay_is_failure` | Valid ancestor child manifests exist but one critical verifier has no final-SHA result. | Decision is `NO-GO` and names the missing verifier. |
| `test_module_sentinel_and_no_skip_are_required` | Omit one M01–M07 API/MySQL/web sentinel or mark it skipped. | G15 fails with the exact module and sentinel. |
| `test_p07_cleanup_trap_runs_after_any_live_gate_failure` | Fake G07, G08, G11, G12, and G13 fail in separate cases. | Stop runs exactly once after each case, cleanup is checked, later live gates are blocked, and each decision is `NO-GO`. |
| `test_accessibility_replay_never_mutates_source_or_child_evidence` | G14 is given a writable fake descriptor/child manifest and a valid live replay. | Source and child hashes are unchanged; only the SHA-scoped P08 closure artifact is created. |
| `test_accessibility_replay_requires_exact_ancestor_child_manifest` | Omit one P05 child input, pass a path for another commit, alter the digest, or provide a lexicographically newer valid-looking manifest. | G14 fails the named field and never scans/selects another artifact. |
| `test_same_head_rerun_uses_new_program_and_p07_run_roots` | Complete one fake run, then execute the same commit with a distinct `PROGRAM_RUN_ID` and P07 run ID. | Both immutable roots remain; the second G11/G12/G14 use only the second root and no `latest`/scan selection. Reusing either completed root fails before a child command runs. |
| `test_program_root_collision_fails_without_overwrite` | Pre-create the requested program root with sentinel bytes. | Invocation exits 2, leaves every byte unchanged, and creates no manifest elsewhere. |
| `test_accessibility_resume_seal_recovers_publication_crash` | G11 and P07 cleanup succeeded, then G14 crashed before `closure-evidence.json` or `.complete.json`. | `--resume-seal` with the exact recorded root/inputs revalidates live evidence, creates only missing P08 finalizer files, seals once, and leaves source/child/live bytes unchanged; any mismatch fails and requires a new run. |
| `test_unsourced_historical_or_c_finding_is_failure` | Add `F001` and a C entry without source/command evidence. | G16 rejects both IDs. |
| `test_open_or_blocked_p0_p1_is_failure` | Register contains one open P0 and one blocked P1. | G16 fails and reports both. |
| `test_unapproved_or_expired_risk_is_failure` | Accepted P2 risk omits user approval or has expiry before run time. | G17 returns `NO-GO`. |
| `test_manifest_paths_cannot_escape_repository` | Child manifest path is a symlink to `../outside.json`. | Resolver rejects it before reading content. |
| `test_go_manifest_contains_all_required_gates_and_plans` | Every fake gate and module sentinel passes; 15 ancestor completion manifests, final-SHA replay evidence, cleanup proof, and valid register are present. | Exit 0, decision `GO`, `G00`–`G17` appear, every plan appears once, and evidence output has one final SHA. |

Run:

```bash
python3 -m unittest scripts.tests.test_run_program_closure -v
```

Expected: FAIL because `scripts.run_program_closure` and the catalog do not exist.

- [ ] **Step 3: Implement the strict catalog**

Write `scripts/program-closure-gates.yaml` with `G00`–`G13` exactly as Section 5. Every entry has `critical: true`, argv as a YAML list (never shell text), dependencies, `forbidden_output: ['SKIP:', 'NOT RUN', 'NOT_READY']`, required output sentinels, and a final-SHA evidence contract. `G09` uses `argv_sequence`. G07 is the sole merged P01/P02 workload verifier. G07, G08, G11, and G12 are ordered sub-gates of `scripts/run-program-live-gates.sh`; G13 is the P07 lifecycle's internal journey phase, not another process invocation. The single lifecycle is `start → export/validate connection manifest → G07 → G08 → G11 → G12 → G13 → trap stop → cleanup proof → print exact P07 manifest path`; none may be optional or run in a second topology.

- [ ] **Step 4: Implement bounded subprocess execution and aggregation**

The implementation must use `subprocess.Popen(argv, cwd=repo_root, env=explicit_env, start_new_session=True)` with argv lists, never `shell=True`. Stream stdout/stderr to gate-specific files, enforce the catalog timeout, terminate the process group on timeout, hash closed files, scan both streams for forbidden markers, and continue only independent gates.

Use immutable result states:

```python
GateState = Literal['pass', 'fail', 'blocked', 'missing', 'stale', 'timeout']

@dataclass(frozen=True)
class GateResult:
    gate_id: str
    command: str
    exit_code: int | None
    result: GateState
    started_at: str
    finished_at: str
    stdout_path: str
    stderr_path: str
    stdout_sha256: str
    stderr_sha256: str
    skip_markers: tuple[str, ...]
```

Validate the HEAD with `git rev-parse HEAD` and `git status --porcelain --untracked-files=no` before execution. Tracked changes at start produce `NO-GO`; runtime files under `artifacts/` and the generated dossier are allowed only after the SHA is captured.

- [ ] **Step 5: Implement child evidence, module sentinels, and register validation**

Accept each completed child’s immutable manifest only when its recorded commit is an ancestor of `$SHA`; verify schema, hashes, terminal status, declared canonical path, no traversal/symlink, and no skip. Separately require fresh final-SHA replay results for every critical verifier. Require explicit M01–M07 API, MySQL, and web test sentinels (or a plan-declared not-applicable sentinel with executable proof), reject absent/duplicate sentinels, and reject `SKIP`, `NOT RUN`, `NOT_READY`, disabled tests, zero-test module selections, or optional critical catalog entries.

For the architecture register, accept only the 19 approved historical F IDs and newly sourced C IDs. A C entry requires `sourced: true`, a non-empty exit criterion, and source/command evidence. Reject recreated unsourced F IDs, terminal findings without evidence, open/blocked P0/P1, and accepted risk without explicit user, scope, owner, decision date, and expiry.

- [ ] **Step 6: Run unit tests green**

Run:

```bash
python3 -m unittest scripts.tests.test_run_program_closure -v
```

Expected: all named tests PASS; no external service, Docker daemon, or production gate runs in this unit suite.

### Task 2: Add red/green Make and workflow contract tests

**Files:**
- Modify: `apps/api/tests/Feature/CiMakeSurfaceTest.php`
- Later modify after handoff: `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`

**Interfaces:**
- Produces: one public `verify-program-closure` target that invokes the existing architecture closure through the orchestrator.
- Produces: ordinary CI contract/unit validation and self-hosted final execution.

- [ ] **Step 1: Add the failing surface test**

Add assertions that:

```php
public function test_program_closure_extends_architecture_closure_and_is_wired_once(): void
{
    $makefile = file_get_contents($this->repoRoot.'/Makefile');
    $ci = file_get_contents($this->repoRoot.'/.github/workflows/ci.yml');
    $e2e = file_get_contents($this->repoRoot.'/.github/workflows/ci-e2e.yml');

    self::assertSame(1, substr_count($makefile, "verify-program-closure:"));
    self::assertStringContainsString('scripts/run-program-closure.py', $makefile);
    self::assertStringContainsString('make verify-architecture-closure', file_get_contents($this->repoRoot.'/scripts/program-closure-gates.yaml'));
    self::assertStringContainsString('python3 -m unittest scripts.tests.test_run_program_closure', $ci);
    self::assertStringContainsString('make verify-program-closure', $e2e);
    self::assertStringContainsString('artifacts/program-closure/', $e2e);
    self::assertStringContainsString('if: ${{ always() }}', $e2e);
}
```


Run:

```bash
cd apps/api && php artisan test tests/Feature/CiMakeSurfaceTest.php --filter=program_closure
```

Expected: FAIL because the target/workflow wiring does not exist.

- [ ] **Step 2: Request and verify the exclusive handoff**

Do not edit any shared surface until the orchestration token records `token: CLOSURE-CI`, `state: granted`, `requesting_plan: P08`, `releasing_owner: ARCHITECTURE-CLOSURE`, and exactly the three surfaces `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`. Its `base_commit` must equal the current full SHA:

```bash
SHA="$(git rev-parse HEAD)"
test "${#SHA}" -eq 40
grep -F "base_commit: '$SHA'" docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md
```

Expected: exit 0 and one matching granted token record. If another holder is granted, the SHA differs, or Task 13 is not complete, set P08 `blocked` and stop before editing.

- [ ] **Step 3: Extend Make without replacing Task 13**

Add to `.PHONY` and add exactly:

```make
verify-program-closure:
	$(PYTHON_BINARY) scripts/run-program-closure.py --catalog scripts/program-closure-gates.yaml
```

The catalog’s first argv is `make verify-architecture-closure`. Do not alias or rename the Task 13 target, and do not duplicate its recipe into P08.

- [ ] **Step 4: Add ordinary-CI contract validation**

In `.github/workflows/ci.yml`, after dependency installation, add a job that runs only deterministic unit/contract checks:

```yaml
program-closure-contract:
  needs: [contracts, api-static]
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683
    - uses: actions/setup-python@a26af69be951a213d495a4c3e4e4022e16d87065
      with:
        python-version: '3.12'
    - run: python -m pip install --disable-pip-version-check PyYAML==6.0.2
    - run: python3 -m unittest scripts.tests.test_run_program_closure -v
```

This job does not emit `GO`; it proves the gate machinery itself. It must not use fake production evidence.

- [ ] **Step 5: Wire final execution on the configured runner**

In `.github/workflows/ci-e2e.yml`, preserve the pinned setup actions and configured runner, replace duplicated final decision commands with one final step, and retain setup/preflight steps needed by the called gates. The self-hosted runner MUST be treated as untrusted: every persistent-runner path is wiped before the gate runs, and every required child manifest/artifact is downloaded from **explicit, immutable, recorded sources** before any gate executes. Add the hydration steps before the final step:

```yaml
permissions:
  contents: read
  actions: read   # required for GitHub API and actions/download-artifact

- name: Wipe persistent runner paths
  run: |
    sudo rm -rf /run/cluster-p07 || true
    sudo rm -rf artifacts/program-closure || true
    sudo rm -rf artifacts/production-e2e || true
    sudo rm -rf artifacts/accessibility artifacts/accessibility-live artifacts/accessibility-manual artifacts/privacy-compliance artifacts/p03-recovery || true

# 1. The orchestration plan is the authoritative index: for each of P01-P08
#    and M00-M07 it records (plan_id, child_commit_sha, expected_digest,
#    source_artifact_name). The hydrator rejects the run if any entry is
#    missing, any digest mismatches, or any plan lacks a recorded source.
- name: Hydrate immutable child manifests/artifacts keyed by recorded child commit + digest
  env:
    SHA: ${{ github.sha }}
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  run: |
    set -euo pipefail
    # 1. Pull the recorded bundle index from the orchestration plan at the final SHA.
    git show "$SHA:docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md" \
      > /tmp/program-orchestration.md
    # 2. Resolve child manifest entries to (artifact_name, child_commit, expected_sha256).
    python3 scripts/program-closure-hydrate.py \
      --commit "$SHA" \
      --orchestration /tmp/program-orchestration.md \
      --output-index /tmp/hydration-index.json
    # 3. Download each recorded artifact. The recorded source is one of:
    #    (a) `actions/download-artifact@v4` for artifacts uploaded by upstream
    #        plan-completion CI runs (name = "<plan_id>-<child_commit>"); or
    #    (b) `gh api` GET /repos/:owner/:repo/contents/<path>?ref=<child_commit>
        #        with Accept: application/vnd.github.raw for tracked repository
        #        evidence files (e.g. descriptors, registers, manifests that
        #        live under docs/ or artifacts/).
    # 4. For each downloaded blob, recompute SHA-256 and compare to the recorded
    #    digest. Any mismatch, missing artifact, or extra artifact forces exit 2
    #    before any gate runs.
    # 5. Place each verified artifact at the canonical local path recorded in
    #    the hydration index (e.g. docs/architecture/accessibility/wcag-2.2-aa-evidence.json,
    #    artifacts/accessibility/<sha>/manifest.json, artifacts/production-e2e/<run-id>/manifest.json,
    #    artifacts/privacy-compliance/<sha>/manifest.json, etc.).
    # The hydrator NEVER copies from persistent-runner paths, NEVER trusts
    # actions/cache entries, and NEVER accepts evidence without a recorded
    # digest match.

- name: Run singular program closure gate
  run: make verify-program-closure
  env:
    PLAYWRIGHT_BROWSERS_PATH: ${{ runner.temp }}/ms-playwright
    PROGRAM_RUN_ID: p08-ci-${{ github.run_id }}-${{ github.run_attempt }}

- name: Upload program closure evidence
  if: ${{ always() }}
  uses: actions/upload-artifact@65c4c4a1ddee5b72f698fdd19549f0f0fb45cf08
  with:
    name: program-closure-${{ github.sha }}
    path: |
      artifacts/program-closure/${{ github.sha }}/
    if-no-files-found: error
    retention-days: 90
```

Keep `permissions: contents: read`, action SHA pinning, runner concurrency, and cleanup. Never add deployment credentials or a deploy step. The hydration step is the **only** source of child evidence the gate reads; persistent-runner leftovers, prior-run `artifacts/` trees, and any imported via `cache`/`actions/checkout` other than the tracked repository source are explicitly rejected by the hydrator before any gate starts. Every recorded source URL, artifact name, child commit, and expected SHA-256 must be present in the orchestration plan; a missing index entry is a critical hydration failure.

- [ ] **Step 6: Run targeted surface tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/CiMakeSurfaceTest.php --filter=program_closure
python3 -m unittest scripts.tests.test_run_program_closure -v
make -n verify-program-closure
```

Expected: PHP and Python tests PASS. Dry-run output contains one invocation of `scripts/run-program-closure.py`; inspecting the catalog shows `G00` invokes `make verify-architecture-closure` first.

### Task 3: Reconcile module, contract, register, and evidence state

**Files:**
- Modify only under `ARCHITECTURE-REGISTER`: `docs/architecture/architecture-closure-register.yaml`
- Modify after authorized status changes: `docs/superpowers/plans/2026-07-26-cluster-program-orchestration.md`
- Read: `apps/api/tests/Architecture/ModuleBoundariesTest.php`, every M01–M07 plan-declared API/MySQL/web command, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, and all 15 child manifests

- [ ] **Step 1: Validate completion ancestry, prior tokens, and replay inventory**

Run:

```bash
SHA="$(git rev-parse HEAD)"
python3 scripts/run-program-closure.py --catalog scripts/program-closure-gates.yaml --preflight-only --commit "$SHA"
```

Expected: exit 0 and `PASS: 15 immutable completion manifests are ancestors; every earlier token is released; P08 holds CLOSURE-CI, PRODUCTION-POLICY, and ARCHITECTURE-REGISTER; every critical final-SHA replay and module sentinel is scheduled.` Missing/non-ancestor evidence, an earlier outstanding token, or an omitted/optional/skipped critical verifier returns the complete list and exits 1.

- [ ] **Step 2: Validate module boundaries and published contracts**

Run:

```bash
make verify-boundaries
make api:inventory
make api:check
```

Expected: boundary and table ownership tests PASS; inventory and Redocly/Orval have zero drift. Execute and retain each M01–M07 module’s explicit API, MySQL, and web sentinel (or plan-defined executable not-applicable proof), require non-zero tests where applicable, and reject skip markers. Confirm integrations use only M00 Contracts/Events and generated clients came only from `npm --prefix apps/web run api:generate` under the contract token.

- [ ] **Step 3: Reconcile findings without inventing history**

Run:

```bash
python3 scripts/validate-architecture-closure.py
```

Expected: PASS for the approved 19 historical findings plus sourced C findings. Use current command/source evidence to close an entry. Raw `.minimax-flow/reports/agent-*.json` content becomes a new C ID only after revalidation; it never regains an unsourced F number. A newly found gate defect remains open and forces `NO-GO` until its owning child plan closes it.

- [ ] **Step 4: Run the contract/register unit tests again**

Run:

```bash
python3 -m unittest scripts.tests.test_run_program_closure -v
cd apps/api && php artisan test tests/Feature/CiMakeSurfaceTest.php --filter=program_closure
```

Expected: PASS after reconciliation; a fixture with a stale manifest, skip marker, cross-owner dependency, or unsourced finding still fails.

### Task 4: Execute the final aggregate gate and render the decision

**Files:**
- Runtime create: `artifacts/program-closure/<sha>/<program-run-id>/{manifest.yaml,PROGRAM-CLOSURE.md,.complete.json}` and gate logs
- Never create or replace a tracked run-result dossier; each run's human dossier is immutable beside its machine manifest.
- Modify only after user-authorized transition: P08 metadata and orchestration status/token ledger

- [ ] **Step 1: Capture the immutable verification SHA**

Run:

```bash
SHA="$(git rev-parse HEAD)"
test "$(printf '%s' "$SHA" | wc -c | tr -d ' ')" = 40
test -z "$(git status --porcelain --untracked-files=no)"
printf 'Verification commit: %s\n' "$SHA"
PROGRAM_RUN_ID="p08-$(date -u +%Y%m%dT%H%M%SZ)-$(python3 -c 'import secrets; print(secrets.token_hex(8))')"
PROGRAM_EVIDENCE_ROOT="artifacts/program-closure/$SHA/$PROGRAM_RUN_ID"
export SHA PROGRAM_RUN_ID PROGRAM_EVIDENCE_ROOT
test ! -e "$PROGRAM_EVIDENCE_ROOT"
printf 'Program run: %s\nEvidence root: %s\n' "$PROGRAM_RUN_ID" "$PROGRAM_EVIDENCE_ROOT"
```

Expected: full 40-character lowercase SHA, a clean tracked worktree, a safe unique run ID, and an absent run root. The orchestrator atomically creates that exact root. If tracked files change after this point, retain the run as `NO-GO`/stale and restart on a new SHA plus new run ID; never reuse or overwrite a root.

- [ ] **Step 2: Run the single final command**

Run:

```bash
make verify-program-closure PROGRAM_RUN_ID="$PROGRAM_RUN_ID"
```

Expected on success: every `G00`–`G17` line is `PASS`, no output contains `SKIP:`, `$PROGRAM_EVIDENCE_ROOT/manifest.yaml` says `GO`, `$PROGRAM_EVIDENCE_ROOT/PROGRAM-CLOSURE.md` matches it, `.complete.json` is written last, and exit code is 0. Expected on any problem: all safe independent gates still report, decision is `NO-GO`, each failed/blocked/missing/stale gate is listed, and exit code is 1. A same-HEAD rerun generates a new `PROGRAM_RUN_ID`; it never reuses this root.

- [ ] **Step 3: Validate the produced manifest and dossier**

Run:

```bash
: "${SHA:?run Step 1 in this shell}"
: "${PROGRAM_RUN_ID:?run Step 1 in this shell}"
: "${PROGRAM_EVIDENCE_ROOT:?run Step 1 in this shell}"
python3 scripts/run-program-closure.py --validate-manifest "$PROGRAM_EVIDENCE_ROOT/manifest.yaml" --commit "$SHA" --program-run-id "$PROGRAM_RUN_ID"
test -s "$PROGRAM_EVIDENCE_ROOT/PROGRAM-CLOSURE.md"
test -s "$PROGRAM_EVIDENCE_ROOT/.complete.json"
```

Expected: `PASS: manifest and dossier identify $SHA/$PROGRAM_RUN_ID and contain all required gates.` The immutable dossier includes scope, SHA, run ID, start/finish time, gate command/result/duration/evidence hashes, child plan table, findings summary, accepted risks, production image digests, recovery RPO/RTO, accessibility/compliance/performance decisions, integration-token state, and final `GO` or `NO-GO`.

- [ ] **Step 4: Perform observable smoke review**

Review retained artifacts for these scenarios rather than accepting command exit codes alone:

1. Worker and scheduler remain healthy, stop on TERM, restart safely, back off during Redis outage, and recover without duplicate durable effects.
2. A clean document becomes downloadable only after S3 persistence and ClamAV clean verdict; infected content is quarantined and never downloadable.
3. Backup restores database, objects, and queue/outbox state into isolation; release rollback restores the previous healthy version inside declared RTO/RPO.
4. Session/CSRF/capability/idempotency/stale-write/correlation/problem+json behavior passes production E2E.
5. Every stabilized route has WCAG 2.2 AA automated and manual evidence, including keyboard, focus, zoom/reflow, screen reader, contrast, reduced motion, and RTL.
6. PII/PHI never appears in URL, browser persistence, problem bodies, or unsanitized logs; incident/vendor controls are evidenced.
7. Performance budgets use the frozen P06 thresholds and pass without relaxing them in P08.
8. Module routes, contracts, Events, tables, ranks, capabilities, navigation, and generated clients match M00 and the serialized integration records.

Expected: every scenario points to a non-empty, hash-verified artifact in the manifest. Missing review evidence changes the decision to `NO-GO`.

- [ ] **Step 5: Record status only after authorization**

If and only if `GO` is authorized for recording, set P08 commits to `$SHA`, status `completed`, release `CLOSURE-CI`, `PRODUCTION-POLICY`, and `ARCHITECTURE-REGISTER` after their integration checks, and complete the program. On `NO-GO`, retain or explicitly revoke tokens as integration requires, record owner/evidence, and return remediation without reversing a child’s independent completion. No commit is authorized.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

- **Failure aggregation:** a failed gate never becomes success through `|| true`, `continue-on-error`, missing-artifact warnings, or `if-no-files-found: warn`. Independent gates continue; dependent gates are recorded `blocked`.
- **Skip detection:** `SKIP:`, `NOT RUN`, `NOT_READY`, gate skip fields, disabled/filtered-out tests, zero-test module selections, missing sentinels, or optional/`allow_skip` critical entries force failure even with exit 0.
- **Retry:** a gate run has no automatic whole-suite retry. Child scripts may use bounded retries only for contracts already approved by their owning plan. A manual rerun receives a new run timestamp and never overwrites the prior manifest/log directory without retaining the prior run as evidence.
- **Idempotency:** rerunning on the same SHA with unchanged inputs produces the same gate catalog and decision; runtime timestamps differ, but artifacts are written atomically via a temporary file and rename. Production smoke data uses run-scoped identifiers and guaranteed cleanup.
- **Concurrency:** CI concurrency permits one final run per ref. The production Compose project, backup namespace, object prefix, Redis stream/group, and Playwright run ID include the SHA/run ID. No two final runs share mutable test state.
- **Authorization:** P08 adds no application capability. The tested runtime must preserve capability-first authorization, session/CSRF, concealment, and correlation behavior. A detailed validation/resource response observable before authorization is a critical failure.
- **Evidence integrity:** hashes are computed after file handles close. Symlinks and paths outside the repository artifact roots are rejected. An evidence file missing at dossier render time is failure.
- **Fail closed:** no S3, ClamAV, queue, scheduler, backup, accessibility, privacy, or performance production adapter may be replaced with a fake to make closure pass.

## 11. Targeted verification commands

The future executor runs the following in this order; the drafting task does not run them:

```bash
python3 -m unittest scripts.tests.test_run_program_closure -v
cd apps/api && php artisan test tests/Feature/CiMakeSurfaceTest.php --filter=program_closure
cd ../../
make -n verify-program-closure
SHA="$(git rev-parse HEAD)"
python3 scripts/run-program-closure.py --catalog scripts/program-closure-gates.yaml --preflight-only --commit "$SHA"
make verify-boundaries
make api:inventory
make api:check
python3 scripts/validate-architecture-closure.py
PROGRAM_RUN_ID="p08-$(date -u +%Y%m%dT%H%M%SZ)-$(python3 -c 'import secrets; print(secrets.token_hex(8))')"
PROGRAM_EVIDENCE_ROOT="artifacts/program-closure/$SHA/$PROGRAM_RUN_ID"
make verify-program-closure PROGRAM_RUN_ID="$PROGRAM_RUN_ID"
python3 scripts/run-program-closure.py --validate-manifest "$PROGRAM_EVIDENCE_ROOT/manifest.yaml" --commit "$SHA" --program-run-id "$PROGRAM_RUN_ID"
```

Expected outcomes:

- Python/PHP contract tests: zero failures.
- Dry run: one program orchestrator entry point whose first catalog gate is the existing architecture gate.
- Preflight: 15 child manifests are ancestor commits; earlier tokens are released; P08 holds exactly its three tokens; all critical replays/sentinels target final SHA.
- Boundaries/inventory/API/modules: zero drift; every M01–M07 API/MySQL/web sentinel is present, non-skipped, and non-zero where applicable.
- Register: only approved historical plus sourced C findings.
- Final: every offline gate is aggregated first; one cold-start P07 lifecycle exports validated env, runs G07→G08→G11→G12→G13, trap cleanup passes, then post-lifecycle G14 validates all child evidence and seals only the exact program/P07-run-scoped closure root; no required command skips and no source file changes after `$SHA` is fixed.
- Manifest: provenance may cite ancestor commits; every decision output/artifact and dossier use one final SHA, one unique program run ID, exact child/P07 run IDs, and matching digests.

## 12. Shared-file integration token requirements

P08 conditionally owns:

- `CLOSURE-CI` after Task 13: `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`.
- `PRODUCTION-POLICY` after Task 13: `scripts/production_bundle_policy.py`.
- `ARCHITECTURE-REGISTER` after Task 14: `docs/architecture/architecture-closure-register.yaml`.

Each record names releasing task, P08, base SHA, exact surfaces, grant evidence, integration, and release. Every earlier token is released at start; these three remain granted through their checks. P08 consumes but does not own other queues. Drift returns to its owner and requires a fresh integrated SHA without creating a child→P08 completion dependency. P07 owns start/export/stop; P08 invokes that lifecycle and alone integrates Make/workflows.

## 13. Rollback procedure

1. A failed P08 run performs cleanup through each child gate and retains `NO-GO` evidence; it does not change production.
2. If the orchestrator/catalog is defective, revert only P08-owned script/test/Make/workflow changes as one unit after evidence proves the tooling defect. Restore Task 13’s existing `verify-architecture-closure` unchanged.
3. Do not delete a failing child test, relax a performance/accessibility/compliance threshold, suppress a skip, or restore a stale generated client.
4. If a child defect is found, leave P08 blocked, return the change to its owner, merge through the correct serialized token, and rerun every final gate on the new SHA.
5. If a production smoke leaves resources, execute the plan-owned cleanup evidence path for the SHA and verify containers, networks, volumes, object prefixes, temporary databases, and queue groups are absent before rerun.
6. A failed or superseded `$PROGRAM_EVIDENCE_ROOT/PROGRAM-CLOSURE.md` remains immutable `NO-GO` evidence; never relabel or overwrite it. A retry uses a new run ID/root, and only that new manifest may independently decide `GO`.
7. Application/data rollback and restore follow P03 evidence. P08 never invokes destructive production restore or migration rollback.
8. Update token/status records only after the user authorizes the rollback record; no commit, force push, tag, or deployment is implied.

## 14. Exit criteria and required retained evidence

P08 is complete only when all are true:

- Task 13 granted `CLOSURE-CI` and `PRODUCTION-POLICY`; Task 14 granted `ARCHITECTURE-REGISTER`; P08 released each after integration checks.
- All children completed independently; each immutable manifest commit is an ancestor of final HEAD.
- `make verify-program-closure` works from cold start, runs architecture closure first, reruns every critical verifier on final HEAD, and fails on omission, optional critical entries, absent sentinels, zero tests, or skips.
- API/generated-client, static, boundary, MySQL, web, dependency, secret, and every M01–M07 API/MySQL/web sentinel pass without skip.
- One merged P01/P02 verifier passes inside the sole P07 lifecycle; P07 starts once, exports inventory/origins/CA/credentials/scope/commit, G07→G08→G11→G12→G13 run, trap cleanup proves absence, and post-lifecycle G14 binds the printed P07 manifest, immutable P05 child evidence, and final-SHA live replay in a new closure artifact without mutating inputs.
- P03 reports measured RPO/RTO and exact PIT support/window/restore-point disclosure matching schema/docs.
- The committed P05 descriptor, immutable child manifest, final-SHA live replay, completed same-SHA P07 manifest, and P08 accessibility closure artifact validate with matching digests.
- Module registry/contracts/routes/tables/OpenAPI/Orval/web shell and register reconcile with no invalid finding/risk.
- Manifest and dossier report `GO`; provenance may cite ancestor child commits, but all decision outputs/artifacts identify one final SHA.
- Manifest retains image digests, merged workload/document proof, recovery RPO/RTO/PIT, WCAG artifact+docs, privacy, P06 budgets, P07 environment/browser/cleanup, child provenance, register digest, and tokens.
- Missing, stale, skipped, blocked, failed, or cleanup-less evidence prevents completion.
- The user authorized any recorded status/evidence commit; this plan authorizes none.

Minimum retention is 90 days in CI. Security-sensitive logs are redacted before upload; redaction must preserve test result, identifiers needed for correlation, and hashes without retaining PHI/PII or secrets.

## 15. Status transition rules

- `blocked → ready`: children independently completed with ancestor manifests; earlier tokens released; Task 13 granted `CLOSURE-CI`/`PRODUCTION-POLICY`; Task 14 granted `ARCHITECTURE-REGISTER`; runner credentials, CA/TLS, services, route inventory, scope, and origins pass.
- `ready → in_progress`: authorized executor, isolated worktree, base SHA, evidence root, and three grants are recorded; edits stay within token surfaces.
- `in_progress → blocked`: stale/non-ancestor evidence/token, missing prerequisite/manifest/sentinel, unavailable runner, skip, cleanup failure, or ownership conflict is recorded.
- `in_progress → verification`: scripts/tests/policy/Make/workflow/register integration is complete, no fake exists, worktree clean, final SHA captured.
- `verification → completed`: final manifest/dossier are `GO` on final SHA, all criteria pass, three tokens release, and user authorizes metadata.
- `verification → blocked`: any failed/skipped/missing/stale/timed-out/blocked gate or missing cleanup/sentinel yields `NO-GO`; remediation returns to owner without reversing child completion.
- `any → superseded`: only a later user-approved plan may replace P08, with replacement path, dependency/status updates, token ownership changes, and migration of retained evidence recorded in both this plan and the orchestration plan.

Planning completion leaves this header `status: blocked`. It does not grant `CLOSURE-CI` or start implementation.
