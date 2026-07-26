# Cluster Quality and Performance Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to execute this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: P06
status: planned
depends_on: []
blocks:
  - P08
shared_file_owner: []
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Approved design:** `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`

**Goal:** Replace warning debt and unmeasured API/web performance risk with a fresh, reproducible baseline, remove every actionable warning, enforce explicit budgets on measured hot paths, and hand P08 a standalone regression gate without taking ownership of shared CI, Make, routes, contracts, or generated files.

**Architecture:** P06 is baseline-first. It records tool output and profiles stable production-like paths before allowing a code change; a remediation is permitted only when a retained trace identifies the source and a failing behavioral or budget test reproduces it. Deterministic query-count and artifact-size gates run separately from environment-sensitive latency and browser measurements. P07 owns the bounded live-topology lifecycle and hands P06 an explicit connection/fixture manifest; P06 publishes immutable completion evidence as soon as its own gates pass, while P08 later replays the standalone command and exclusively wires it into the final `Makefile`/workflow closure gate.

**Tech stack:** PHP 8.3, Laravel 13.8, PHPUnit 12.5, SQLite/MySQL, PHPStan/Larastan, Pint, React 19.2, TypeScript 6, Vite 8.1, Vitest 4.1, Oxlint, Redocly, Node.js, Playwright 1.61, OpenAPI/Orval.

## Global constraints

- The current architecture-closure plan remains `in_progress`. Broad API/web remediation waits until its active stabilization work has completed and its affected files are handed off.
- P06 owns no shared file. It must not edit `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, `apps/api/routes/web.php`, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, or `apps/api/tests/Architecture/ModuleBoundariesTest.php`.
- `CLOSURE-CI` belongs only to P08 after `ARCHITECTURE-CLOSURE:T13-HANDOFF`. P06 supplies standalone scripts/configuration and retained evidence; P08 performs final Make/workflow integration.
- Live P06 runtime gates execute only inside P07's bounded `start → export connection/fixture manifest and environment → run P05/P06/P07 dependent gates → run P07 journeys → stop with trap → prove cleanup` lifecycle. The handoff must expose every field named in §7; a locally guessed origin, credential, scope, or commit is prohibited.
- Contract warnings requiring a source change go through the serialized `OPENAPI` token. The Orval client is changed only by `npm --prefix apps/web run api:generate`, never by hand.
- Baseline collection may start immediately. No warning cleanup, hotspot remediation, index, cache, chunk split, dependency, or broad source edit is allowed until the fresh evidence identifies a violated budget and its source.
- Module-owned flow remains controller → validation/capability check → handler/service → module-owned persistence. Cross-module use is through published `Contracts/` or `Events/`; P06 does not add direct cross-owner SQL or foreign keys.
- API semantics remain `application/problem+json`, lowercase UUIDv7 correlation IDs, identity session/CSRF, capability checks before disclosure, `Idempotency-Key`, strong ETags/`If-Match`, `lock_version`, cursor pagination, and transactional outbox where applicable.
- PHI/PII must not enter benchmark URLs, output, browser persistence, error bodies, fixture labels, or logs. Benchmark credentials are environment variables and are redacted from retained command metadata.
- No commit, push, PR, deployment, migration, or cloud change is authorized by this plan. A commit is recorded only after explicit user authorization.
- Raw `.minimax-flow` material is not evidence by itself. A useful item must be freshly reproduced and registered as the next source-backed `C` finding; unsourced historical `F001`–`F123` placeholders are never recreated.

---

## 1. Status header and dependency fields

The canonical header is the YAML block above: `status: planned`, `depends_on: []`, `blocks: [P08]`, and `shared_file_owner: []`.

Execution has two gates:

1. **Ungated baseline lane:** command inventory, warning capture, artifact measurements, source inspection, and read-only profiling may begin immediately.
2. **Blocked remediation/verification lane:** changes to stabilized API/web feature code require a recorded architecture-closure handoff for the affected files and a recorded declaration that the integrated API/web feature set is stable. Final runtime performance evidence additionally requires the production-like environment prepared by the relevant runtime/E2E plans. P06 does not promote those completion gates into `depends_on` start gates.

P06 blocks P08 because the singular closure gate cannot pass without P06's zero-actionable-warning result and explicit performance-budget evidence.

This is a one-way dependency. P06 completion never waits for P08 acknowledgement or acceptance: after P06's own §14 gates pass, P06 atomically publishes its immutable commit-addressed manifest and may become `completed`. P08 later accepts an ancestor P06 manifest and reruns the critical P06 verifier against final HEAD.

## 2. Goal and user-visible outcome

The user-visible outcome is a Cluster UI and API whose stable routes do not regress silently:

- build, lint, static analysis, contract lint, and test commands have no actionable warnings;
- login/shell/dashboard/search/documents/tasks/reports routes remain responsive within declared browser and API budgets;
- large optional routes, especially API documentation, do not leak into initial-route transfer;
- independent React/API requests do not become request waterfalls;
- list endpoints retain bounded, stable pagination and serialization cost as page size grows;
- stale writes, idempotent replays, capability denial, and per-principal cache isolation remain correct under concurrency;
- query count does not grow linearly with result cardinality;
- P08 can invoke one P06-owned verification entry point and retain machine-readable evidence.

The plan optimizes no path merely because it looks expensive. It first reproduces a warning or budget failure, retains the profile, writes a failing regression, then applies the smallest source fix.

## 3. Current source evidence

Current repository evidence, which must be refreshed at execution time:

1. `apps/api/composer.json` defines `lint` as Pint and `analyse` as PHPStan/Larastan; `apps/api/phpstan.neon` is level 5 and contains two narrow ignored-error entries.
2. `apps/web/package.json` defines Vite build, Oxlint, Vitest/coverage, Redocly lint/bundle, Orval generation, and `api:check`.
3. `apps/web/.oxlintrc.json` makes `react/rules-of-hooks` an error and `react/only-export-components` a warning.
4. `apps/web/vite.config.ts` contains no bundle budget. It must not be “fixed” by raising `chunkSizeWarningLimit`.
5. `docs/analysis/SUMMARY.md` records a historical 2026-07-26 run with 60 Oxlint warnings in 16 files, Vite chunks around 1.24 MB and 1.35 MB before gzip, and Redocly ambiguity/unused-component warnings. These figures are historical context, not a current baseline.
6. `docs/analysis/17-cross-cutting-risks.md` classifies the warning/chunk debt as at risk and explicitly says to improve chunking only after measurement.
7. `apps/api/Modules/Search/Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php` currently fetches all eligible rows and invokes `DecideAccess::decide()` once per row; this is a source-level N+1 candidate, but a fresh query trace is required before remediation.
8. `apps/api/Modules/Search/Tests/SearchProjectionTest.php` verifies authorization filtering but does not bound query growth, result-scan growth, or cursor behavior.
9. `apps/web/src/app/workspace-routes.tsx` already lazy-loads `SwaggerUiScreen`; `apps/web/src/features/docs/SwaggerUiScreen.tsx` imports Swagger UI, YAML, CSS, and the bundled spec. The bundle gate must prove those bytes stay out of initial routes rather than inventing another split.
10. `apps/web/src/app/principal-context.tsx`, `apps/web/src/features/documents/DocumentsWorkspace.tsx`, `apps/web/src/features/identity/IdentityAccounts.tsx`, `apps/web/src/features/imports/ImportReview.tsx`, and `apps/web/src/features/dashboard/WorkDashboard.tsx` already use parallel requests in several paths. The network gate protects this behavior and changes code only when a trace shows a real sequential dependency.
11. `apps/web/src/app/AppWorkspaceShell.tsx` requests notifications at shell mount. The browser trace must decide whether that is critical-path work, harmless parallel work, or a measurable contention source; source appearance alone is not a remediation instruction.
12. `Makefile` and both CI workflows are reserved by the active closure plan and later by P08's exclusive `CLOSURE-CI` token.

Fresh baseline evidence is captured, without changing source, using:

```bash
mkdir -p "test-results/p06/${P06_COMMIT_SHA}/baseline"
bash -o pipefail -c 'composer --working-dir=apps/api lint 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/api-lint.log"'
bash -o pipefail -c 'composer --working-dir=apps/api analyse 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/api-analyse.log"'
bash -o pipefail -c 'cd apps/api && php artisan test 2>&1 | tee "../../test-results/p06/${P06_COMMIT_SHA}/baseline/api-test.log"'
bash -o pipefail -c 'npm --prefix apps/web run lint 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/web-lint.log"'
bash -o pipefail -c 'npm --prefix apps/web run build 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/web-build.log"'
bash -o pipefail -c 'npm --prefix apps/web run api:check 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/api-check.log"'
bash -o pipefail -c 'npm --prefix apps/web run test:unit 2>&1 | tee "test-results/p06/${P06_COMMIT_SHA}/baseline/web-unit.log"'
```

Expected baseline outcome: every command produces an unabridged log and exit code for the same supplied 40-character `P06_COMMIT_SHA`. Baseline commands are allowed to fail; failure is classified, never hidden. The baseline manifest records each diagnostic as `actionable_project`, `generated_drift`, `upstream_tool`, or `environment`, with exact command, rule/code, path, line, normalized message, tool version, count, and evidence path. A warning cannot be allowlisted merely because it existed historically.

## 4. Scope and explicit non-goals

### In scope

- Fresh warning inventory for API lint/analyse/tests and web lint/build/unit/API-contract checks.
- Exact warning fingerprints, ownership, classification, and zero-actionable-warning enforcement.
- Static artifact budgets for initial and lazy route bundles, compressed bytes, and source-map attribution.
- Production-like API latency/response-size profiling with warmup, fixed samples, concurrency, status distribution, and percentiles.
- Laravel SQL query counts, duplicate-query fingerprints, `EXPLAIN` evidence for measured slow queries, hydration/serialization time, and result cardinality.
- Browser Core Web Vitals, transfer size, request count, long tasks, and independent-request start-time spread.
- Pagination cost at limits 25 and 100, cursor continuity, serialization scaling, concurrent inserts, stale writes, and idempotent replay.
- Cache correctness and benefit measurement before any cache is added or expanded.
- Targeted regression tests and standalone P06 verification scripts for measured risks.

### Non-goals

- Broad speculative refactors, framework swaps, state-management rewrites, ORM rewrites, or blanket memoization.
- Raising warning thresholds, suppressing diagnostics, increasing Vite's chunk warning limit, lowering PHPStan level, weakening Oxlint, skipping tests, or converting failures into successful exits.
- Hand-editing generated clients or bundled `.orval` output.
- Editing shared Make/CI/routes/OpenAPI/module-boundary surfaces without their canonical owner/token.
- Adding Redis, HTTP, query, or React caches without a repeated-cost profile, explicit invalidation semantics, and cross-principal isolation test.
- Adding an index without a retained MySQL `EXPLAIN`/`EXPLAIN ANALYZE` comparison and module-owned migration/rollback decision.
- Replacing cursor pagination with offset pagination or changing API response shapes as a performance shortcut.
- Treating wall-clock PHPUnit assertions on shared CI hardware as deterministic performance tests.
- Production load testing against real customer data or unrestricted public endpoints.

## 5. Architecture and ownership boundaries

P06 uses four layers:

1. **Capture:** `scripts/quality/capture-p06-baseline.mjs` runs the canonical commands, preserves stdout/stderr/exit codes, fingerprints diagnostics, and writes JSON. It never rewrites source.
2. **Deterministic gates:** Node tests validate warning classification and bundle-byte accounting; PHPUnit tests validate query scaling and API invariants without wall-clock thresholds.
3. **Runtime profiling:** `scripts/quality/benchmark-p06-runtime.mjs` uses the live topology and fixture set exported by P07, validates its manifest against the supplied environment, and uses Playwright/API contexts to collect percentiles, resource timing, request concurrency, bytes, and Web Vitals. Credentials are read from environment and redacted.
4. **Aggregation:** `scripts/quality/verify-p06.mjs` validates commit identity, the P07 connection/fixture handoff, deterministic and runtime outputs, and every registered plan-specific sentinel; it rejects missing/skipped/stale evidence and atomically writes the immutable P06 completion manifest. It does not edit Make or workflows.

Ownership rules:

- P06-created `quality/performance/p06-*.json`, `scripts/quality/p06-*.mjs`, and their tests are P06-owned, non-shared files.
- A measured hotspot inside an existing module remains owned by that module. P06 may prepare the failing test and evidence, but a cross-module API change requires the producer to publish a Contract through its own approved plan/queue.
- Search may consume only `Modules\Authorization\Contracts`; it must not import Authorization Domain/Infrastructure or query Authorization-owned tables.
- Web changes remain inside the measured feature route. P06 does not take the `WEB-SHELL` token; any final shell/navigation change waits for that queue.
- P08 receives only the command/evidence contract and exclusively owns final `Makefile` and workflow wiring.

## 6. Files to create, modify, move, or remove

### Create during the ungated baseline/tooling lane

- `quality/performance/p06-budgets.json` — versioned numeric budgets and exact scenario definitions.
- `quality/performance/p06-warning-allowlist.json` — starts as `{"version":1,"allow":[]}`; only exact, source-backed, non-actionable fingerprints with tool version, reason, owner, and expiry may be added.
- `scripts/quality/p06-process.mjs` — spawn/timeout/redaction/output-hash utility using Node built-ins.
- `scripts/quality/capture-p06-baseline.mjs` — canonical warning/build/test capture and classification.
- `scripts/quality/check-p06-bundle.mjs` — reads Vite `dist` artifacts and build manifest, computes raw/gzip/Brotli bytes and initial-versus-lazy reachability.
- `scripts/quality/benchmark-p06-runtime.mjs` — API and Playwright runtime sampler.
- `scripts/quality/verify-p06.mjs` — aggregate P06 gate and evidence-manifest writer.
- `scripts/quality/tests/p06-process.test.mjs` — exit-code, timeout, redaction, and hash tests.
- `scripts/quality/tests/capture-p06-baseline.test.mjs` — warning parser/classification/allowlist tests.
- `scripts/quality/tests/check-p06-bundle.test.mjs` — synthetic manifest/reachability/compression budget tests.
- `scripts/quality/tests/benchmark-p06-runtime.test.mjs` — percentile, sample, preflight-cardinality, P07-manifest validation, skip rejection, required-sentinel, and credential-redaction tests.
- `apps/api/Modules/Search/Tests/SearchQueryScalingTest.php` — Search-owned deterministic candidate/query growth test, created only after current Search stabilization handoff.

### Modify only after a fresh failing test and retained profile identify the source

- `apps/api/Modules/Search/Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php` — measured unbounded scan/N+1 remediation, preserving authorization filtering; no direct Authorization persistence access.
- `apps/api/Modules/Search/Tests/SearchProjectionTest.php` — cursor/limit/denied-result contract coverage if the measured Search fix changes internal selection strategy.
- `apps/api/phpunit.mysql.xml` — after Search handoff, add exactly one `<file>Modules/Search/Tests/SearchQueryScalingTest.php</file>` entry so the existing isolated MySQL runner actually executes the new regression.
- `apps/web/src/app/AppWorkspaceShell.tsx` and conditionally create `apps/web/src/app/AppWorkspaceShell.test.tsx` — only if the authenticated-shell trace proves notification work delays critical content or duplicates requests.
- `apps/web/src/app/principal-context.tsx` and `apps/web/src/app/principal-context.test.tsx` — only if the trace proves principal/scope requests became sequential or duplicated.
- `apps/web/src/features/dashboard/WorkDashboard.tsx` and `apps/web/src/features/dashboard/WorkDashboard.test.tsx` — only if the trace proves independent dashboard calls waterfall or rerender work breaches INP/long-task budgets.
- `apps/web/src/features/documents/DocumentsWorkspace.tsx` and `apps/web/src/features/documents/DocumentsWorkspace.race.test.tsx` — only if document detail calls become sequential/duplicated or stale responses win.
- `apps/web/src/features/docs/SwaggerUiScreen.tsx` and `apps/web/src/app/workspace-routes.tsx` — only if bundle reachability proves Swagger/YAML/spec bytes enter a non-docs initial route or the `/api-docs` lazy-route budget fails.
- Exact files named by fresh Pint/PHPStan/Oxlint/Vitest diagnostics — only their actionable lines, grouped by owning stabilized feature and listed in the baseline manifest before editing.

### Shared files explicitly not modified by P06

- `Makefile`
- `.github/workflows/ci.yml`
- `.github/workflows/ci-e2e.yml`
- `apps/api/routes/web.php`
- `docs/contracts/api/openapi.yaml`
- `apps/web/src/api/generated/cluster.ts`
- `apps/api/tests/Architecture/ModuleBoundariesTest.php`

No file is moved or removed by this plan. If an actionable `react/only-export-components` warning requires separating a non-component export, the executor creates one feature-local file named after the moved symbol and updates all importers in the same stabilized feature; it does not add a barrel or compatibility re-export.

## 7. Public Contracts, Events, routes, schemas, and capability names

P06 publishes no application Contract, Event, route, database schema, or capability. Existing authorization and API contracts remain unchanged.

P06's only public artifact is the verification CLI and its immutable completion manifest:

```text
node scripts/quality/verify-p06.mjs \
  --mode child \
  --commit "$P06_COMMIT_SHA" \
  --connection-manifest "$P07_CONNECTION_FIXTURE_MANIFEST" \
  --budgets quality/performance/p06-budgets.json \
  --allowlist quality/performance/p06-warning-allowlist.json \
  --api-origin "$P06_API_ORIGIN" \
  --web-origin "$P06_WEB_ORIGIN" \
  --output "test-results/p06/${P06_COMMIT_SHA}"
```

P07 owns and exports `P07_CONNECTION_FIXTURE_MANIFEST` while its topology is live. The persisted JSON is `p07-connection-fixture-manifest/v1` and contains `commit_sha`, `api_origin`, `web_origin`, `scope_id`, and a `p06_environment` object naming all six required variables: literal non-secret values for `P06_COMMIT_SHA`, `P06_API_ORIGIN`, `P06_WEB_ORIGIN`, and `P06_SCOPE_ID`, plus `{ "source": "process-env", "present": true }` descriptors for `P06_USERNAME` and `P06_PASSWORD`. P07 exports the two credential values into the same dependent-gate process without writing them to disk. `verify-p06.mjs` requires the manifest path and all six environment variables, requires exact equality between the four non-secret manifest values and the environment/CLI values, requires credential descriptors and non-empty credential environment values, and exits `2` before login on a missing/mismatched field.

The CLI has two fail-closed publication modes. `child` requires `--output test-results/p06/$P06_COMMIT_SHA` and publishes P06's immutable completion evidence. `replay` is used only by P08 inside the same P07 lifecycle; it additionally requires safe `PROGRAM_RUN_ID`, repository-relative `PROGRAM_EVIDENCE_ROOT=artifacts/program-closure/$P06_COMMIT_SHA/$PROGRAM_RUN_ID`, and `P07_RUN_ID`, and requires `--output "$PROGRAM_EVIDENCE_ROOT/live/p06/$P07_RUN_ID"`. Both roots must be absent, non-symlinked, and atomically created with `.incomplete.json`; a collision or wrong path fails before running a command. Each mode stages/fsyncs/renames outputs, writes `manifest.json` and `manifest.sha256`, and publishes `.complete.json` last. Completed roots are immutable. Replay never changes or republishes the child completion manifest; a same-HEAD P08 rerun uses new program/P07 run IDs.

Required environment supplied by the P07 handoff:

- `P06_COMMIT_SHA`: exactly 40 lowercase hexadecimal characters and equal to the live topology commit recorded in the P07 manifest.
- `P06_API_ORIGIN` and `P06_WEB_ORIGIN`: exact isolated live origins from the P07 manifest; HTTPS is required except loopback development verification.
- `P06_USERNAME` and `P06_PASSWORD`: dedicated non-production benchmark principal exported by P07, read without printing or persisting; the manifest records only their environment-variable names and presence.
- `P06_SCOPE_ID`: exact non-sensitive fixture identifier exported in the P07 manifest and used in request bodies/headers, never PHI/PII.
- `P07_CONNECTION_FIXTURE_MANIFEST`: readable path to the live P07 manifest; its topology remains running until P06 has written evidence and P07's trap subsequently proves cleanup.
- `P07_RUN_ID`: exact safe run identifier from the live P07 manifest; replay output is bound to it.

Exit contract:

- exit `0`: every required command and budget passed; manifest has `result: "pass"` and no skip;
- exit `1`: warning, deterministic regression, budget, HTTP status, data-cardinality, or evidence-integrity failure;
- exit `2`: malformed config, missing required environment, unsafe origin, invalid commit identifier, or unavailable prerequisite; this is a blocked/failing closure gate, never a skip/pass.

`quality/performance/p06-budgets.json` uses this closed schema and exact initial budgets:

```json
{
  "version": 1,
  "samples": { "warmup": 20, "measured": 100, "concurrency": 10 },
  "quality": { "actionableWarnings": 0, "generatedDrift": 0, "criticalTestSkips": 0 },
  "requiredSentinels": {
    "node-tests.tap": ["bundle-unit", "runtime-unit"],
    "api-search-targeted.log": ["sqlite-search-query-scaling"],
    "baseline/api-test.log": ["sqlite-search-query-scaling"],
    "mysql-search-query-scaling.log": ["mysql-search-query-scaling"],
    "web-bundle.log": ["bundle-live"],
    "api-runtime.log": ["api-runtime"],
    "web-runtime.log": ["web-runtime"]
  },
  "api": {
    "readP95Ms": 300,
    "readP99Ms": 800,
    "commandP95Ms": 500,
    "errorRate": 0,
    "listResponseBytes": 262144,
    "queryGrowthMaxDelta": 2,
    "serializationGrowthRatioMax": 4.5
  },
  "web": {
    "lcpMs": 2500,
    "inpMs": 200,
    "cls": 0.1,
    "fcpMs": 1800,
    "ttfbMs": 800,
    "initialJsGzipBytes": 204800,
    "initialTransferredBytes": 1048576,
    "initialRequests": 20,
    "lazyRouteJsGzipBytes": 409600,
    "independentRequestStartSpreadMs": 100,
    "longTaskMs": 50,
    "longTaskCountMax": 4,
    "longTaskTotalBlockingMs": 200
  }
}
```

Long tasks are reported via `PerformanceObserver` entries of type `longtask`. The pass/fail limits are: no more than 4 long tasks with `duration` ≥ `longTaskMs` per measured cold-route transition, and their Total Blocking Time across that transition — `sum(max(duration - 50ms, 0))` over those entries — must stay ≤ `longTaskTotalBlockingMs`. The thresholds together with the cold-route Core Web Vitals form a single regression budget; a metric exceeding its limit fails the run.
The fixed browser values follow the program's launch budgets: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1, FCP ≤ 1.8 s, TTFB ≤ 800 ms, initial transferred bytes ≤ 1 MiB, and initial JS ≤ 200 KiB gzip. API measurements use 20 warmups plus 100 measured requests at concurrency 10; authenticated reads must meet p95 ≤ 300 ms and p99 ≤ 800 ms, and isolated mutation scenarios p95 ≤ 500 ms. A future budget change requires a new measured baseline, user approval, a plan amendment, and a version increment; the verifier rejects silent threshold relaxation.

Every P06 MySQL or performance test/check emits a success marker only after its assertions and budget checks finish: `P06_SENTINEL|P06|<scenario-id>|PASS|<P06_COMMIT_SHA>`. `requiredSentinels` is a closed retained-log-to-scenario map, not a global uniqueness set: each named log must contain exactly one correctly committed occurrence of every scenario assigned to that log, and must contain no other P06 sentinel. This deliberately requires `sqlite-search-query-scaling` once in both the targeted SQLite log and the canonical broad API-test log, proving that the broad runner discovered the plan-specific regression rather than passing by omission. The Search test derives its database-specific ID from Laravel's active connection driver: only SQLite may emit `sqlite-search-query-scaling`, and only the isolated `mysql` connection may emit `mysql-search-query-scaling`; an unexpected driver fails without a marker. Any later conditional performance regression must add its stable scenario ID and every runner/log expected to execute it to the map in the same change. `verify-p06.mjs` fails on a missing required log, missing/duplicate-in-log/malformed/stale-commit/unregistered/wrong-driver marker, or a marker in an unassigned log. A broad PHPUnit/Node/CI runner's overall exit `0` or generic suite PASS can never replace its required per-log sentinel.

## 8. Database tables, indexes, constraints, migration order, and rollback/recovery

P06 creates no table, foreign key, index, or production migration. It first measures module-owned persistence.

For each representative API scenario, retained evidence records:

- database engine/version and fixture cardinality;
- total SQL count and normalized duplicate-query fingerprints;
- rows examined/returned where the engine exposes them;
- hydration plus JSON serialization duration and response bytes;
- MySQL `EXPLAIN FORMAT=JSON` for a query responsible for at least 20% of endpoint duration or a query whose examined/returned ratio exceeds 10:1;
- query counts at list limits 25 and 100;
- query-count delta when eligible Search candidates increase from 10 to 100.

Deterministic Search budget: increasing candidates from 10 to 100 may add at most two SQL statements. The test also proves denied records remain absent from both `items` and `total`. A linear increase is a failing source-backed finding, not permission for Search to access Authorization tables.

Index decision gate:

1. Reproduce a budget failure on MySQL with the stabilized schema.
2. Retain before-change `EXPLAIN FORMAT=JSON`, bindings with sensitive values redacted, cardinality, and timing distribution.
3. Confirm the missing/ineffective index is responsible rather than authorization N+1, unbounded application scanning, serialization, or network latency.
4. Hand the evidence to the table-owning module. That owner amends its plan with the exact migration class, index name, column order, forward test, and `down()` action before implementation.
5. Run the same profile after the owner change and retain the before/after comparison.

Migration order, if a separately approved owner amendment becomes necessary, is: owner migration → isolated MySQL migration test → owner query regression → P06 runtime profile → P08 final gate. Rollback is owner migration `down()` followed by its module tests and the P06 pre-change benchmark. P06 cannot mark itself complete while relying on an unrecorded migration or an index absent from rollback evidence.

Caching decision gate:

- Default is no new cache.
- Cache work is allowed only when the profile shows the same authorized computation repeated in one request or across requests, the data has an explicit owner/invalidation event, and the change improves p95 by at least 10% without violating correctness.
- Cache keys include principal/tenant/scope/classification/version inputs that affect authorization. Sensitive values are hashed, not logged.
- Tests must prove two principals/scopes cannot receive each other's value, mutation/outbox invalidation prevents stale reads, denied responses are not turned into shared positive entries, and cache unavailability fails according to the existing route contract.
- Rollback removes the cache read/write binding and returns to authoritative persistence; no dual source of truth remains.

## 9. TDD tasks with red/green steps

### Task 1: Capture and classify a fresh baseline

**Files:**
- Create: `scripts/quality/p06-process.mjs`
- Create: `scripts/quality/capture-p06-baseline.mjs`
- Create: `scripts/quality/tests/p06-process.test.mjs`
- Create: `scripts/quality/tests/capture-p06-baseline.test.mjs`
- Create: `quality/performance/p06-warning-allowlist.json`

**Interfaces:** `runCaptured(command, args, options)` returns `{command, exitCode, durationMs, stdoutPath, stderrPath, sha256}`. `capture-p06-baseline.mjs` writes `baseline.json` and exits nonzero only in `--enforce` mode.

- [ ] **Step 1: Write failing Node tests.** Fixtures cover Pint/PHPStan/Oxlint/Vite/Redocly/Vitest output, nonzero exits, a hanging child, ANSI stripping, absolute-path normalization, credential redaction, exact fingerprint stability, expired allowlist entries, and unknown diagnostic formats.
- [ ] **Step 2: Prove red.** Run `node --test scripts/quality/tests/p06-process.test.mjs scripts/quality/tests/capture-p06-baseline.test.mjs`. Expected: FAIL because the two modules do not exist.
- [ ] **Step 3: Implement the minimum capture/parser.** Use `node:child_process`, `node:crypto`, `node:fs`, and `node:path`; do not add a package. Preserve raw logs, hash them, and reject unparsed warning-like lines instead of silently dropping them.
- [ ] **Step 4: Prove green.** Run the same Node test command. Expected: all tests pass; synthetic credentials are absent from JSON/log fixtures.
- [ ] **Step 5: Capture the seven canonical baseline commands from §3 with `--enforce=false`.** Expected: `baseline.json` contains seven command records and every warning/failure has one classification and owner; no source file changes.
- [ ] **Step 6: Review each classification.** `actionable_project` requires removal; `generated_drift` requires authoritative contract queue plus generation; `upstream_tool` requires an exact fingerprint/tool version/reason/owner/expiry; `environment` blocks runtime evidence. Unclassified diagnostics fail enforcement.

### Task 2: Remove actionable warnings without suppression

**Files:** exact stabilized project files listed in Task 1's `actionable_project` records; contract/generated/shared files remain token-controlled.

- [ ] **Step 1: For each warning class, add or select the narrowest existing test that observes its behavior.** For React export separation, import the moved symbol from its new feature-local file and render the component; for unused code, prove no public importer/side effect exists; for PHPStan types, exercise the real boundary value; for Redocly, first obtain the `OPENAPI` token owner decision.
- [ ] **Step 2: Prove red with the exact warning command and targeted behavior test.** Expected: warning fingerprint remains present or behavioral test fails for the identified defect.
- [ ] **Step 3: Apply the smallest source fix.** Do not add ignore comments, lower severity, raise chunk thresholds, weaken PHPStan, edit generated output, or keep re-export shims.
- [ ] **Step 4: Run the targeted behavior test and exact warning command.** Expected: targeted behavior passes and the fingerprint count becomes zero without new fingerprints.
- [ ] **Step 5: Repeat by stabilized feature owner, never across an active module edit.** Expected final enforcement: actionable warnings `0`, generated drift `0`, expired allowlist entries `0`, unparsed diagnostics `0`.

### Task 3: Enforce bundle reachability and compressed-byte budgets

**Files:**
- Create: `quality/performance/p06-budgets.json`
- Create: `scripts/quality/check-p06-bundle.mjs`
- Create: `scripts/quality/tests/check-p06-bundle.test.mjs`
- Modify conditionally: `apps/web/src/features/docs/SwaggerUiScreen.tsx`, `apps/web/src/app/workspace-routes.tsx`

- [ ] **Step 1: Write failing synthetic-manifest tests.** Cover entry imports, nested dynamic imports, shared chunks, duplicate reachability, gzip/Brotli accounting, a 200 KiB initial-JS violation, a 400 KiB lazy-route violation, proof that a lazy Swagger chunk is excluded from initial bytes, and absence/wrong-commit/duplication of the `bundle-unit` sentinel.
- [ ] **Step 2: Prove red.** Run `P06_COMMIT_SHA="$P06_COMMIT_SHA" node --test scripts/quality/tests/check-p06-bundle.test.mjs`. Expected: FAIL because the checker does not exist and no `P06_SENTINEL|P06|bundle-unit|PASS|${P06_COMMIT_SHA}` is emitted.
- [ ] **Step 3: Implement the checker.** It reads Vite's emitted manifest and files from a build invoked with `--manifest`, traverses static imports for each entry, traverses dynamic imports separately, and writes raw/gzip/Brotli bytes plus module attribution. Its unit and live entry points emit their distinct registered sentinels only after all assertions/budgets pass. It never changes Vite's warning threshold.
- [ ] **Step 4: Prove green on synthetic fixtures.** Expected: all reachability/accounting tests pass and the retained unit log contains exactly one commit-matched `bundle-unit` sentinel.
- [ ] **Step 5: Build and measure the stabilized web app.** Run `npm --prefix apps/web run build -- --manifest` then `node scripts/quality/check-p06-bundle.mjs --commit "$P06_COMMIT_SHA" --budgets quality/performance/p06-budgets.json --dist apps/web/dist --output "test-results/p06/${P06_COMMIT_SHA}/web-bundle.json"`. Expected: `apps/web/dist/.vite/manifest.json` exists, the checker emits a complete route/chunk graph, budgets hold, and its retained log contains exactly one commit-matched `bundle-live` sentinel.
- [ ] **Step 6: If a budget fails, inspect the emitted module attribution.** Change only modules proven reachable on the failing route. Preserve current lazy `/api-docs` behavior; do not add `manualChunks` based solely on package names or split a chunk that increases initial requests beyond 20.
- [ ] **Step 7: Add a route-level import regression before remediation.** The test asserts that Swagger UI, `js-yaml`, and the raw spec are absent from non-doc initial reachability. Prove it fails against the measured regression, apply the minimal import boundary, then rerun build/checker.

### Task 4: Prove API query and serialization scaling

**Files:**
- Create after Search handoff: `apps/api/Modules/Search/Tests/SearchQueryScalingTest.php`
- Modify conditionally: `apps/api/Modules/Search/Features/SearchAccessibleRecords/Handler/SearchAccessibleRecordsHandler.php`
- Modify conditionally: `apps/api/Modules/Search/Tests/SearchProjectionTest.php`
- Modify after Search handoff: `apps/api/phpunit.mysql.xml` — add the Search regression to the explicit MySQL testsuite.

- [ ] **Step 1: Write the failing Search query-growth test.** Seed 100 Search-owned `search_index_entries` with public, non-sensitive fixture text; use the production `DecideAccess` binding and Laravel query listener; compare total SQL for 10 versus 100 candidates; assert delta ≤ 2 and denied records absent. Only after all assertions pass, inspect the active Laravel database driver and print `P06_SENTINEL|P06|sqlite-search-query-scaling|PASS|${P06_COMMIT_SHA}` for `sqlite` or `P06_SENTINEL|P06|mysql-search-query-scaling|PASS|${P06_COMMIT_SHA}` for `mysql`; missing/invalid `P06_COMMIT_SHA` or any other driver fails the test without a marker.
- [ ] **Step 2: Prove red on SQLite and isolated MySQL.** Run `cd apps/api && P06_COMMIT_SHA="$P06_COMMIT_SHA" php artisan test Modules/Search/Tests/SearchQueryScalingTest.php`, add the exact `<file>Modules/Search/Tests/SearchQueryScalingTest.php</file>` entry to `apps/api/phpunit.mysql.xml`, then run `cd ../.. && P06_COMMIT_SHA="$P06_COMMIT_SHA" bash scripts/run-mysql-integration-tests.sh`. Expected current result: the query-growth assertion fails if per-row authorization performs SQL; absence of a failure means no N+1 code change is authorized. The targeted SQLite log must contain exactly one `sqlite-search-query-scaling` sentinel and no MySQL sentinel; the isolated MySQL log must contain exactly one `mysql-search-query-scaling` sentinel and no SQLite sentinel. A prerequisite skip, missing test name, missing/duplicate/wrong-driver/wrong-commit sentinel becomes exit `2` and a blocked result, never a pass.
- [ ] **Step 3: Retain normalized duplicate-query fingerprints and MySQL `EXPLAIN FORMAT=JSON`.** Expected: evidence attributes the violated budget to a concrete call/query; no sensitive bindings.
- [ ] **Step 4: Choose the source-correct owner path.** Search may bound/project its owned candidate query. If authorization must be evaluated in bulk, the Authorization owner must publish an approved batch Contract; Search must not import Authorization Domain/Infrastructure or query its tables. P06 remains blocked until that producer contract is integrated.
- [ ] **Step 5: Implement the minimum measured fix and preserve security semantics.** Authorization still runs before a record enters `items` or `total`; scope/classification facts remain complete; cursor/limit remains bounded at 100; response retains correlation ID and problem+json behavior.
- [ ] **Step 6: Prove green.** Rerun the two Search test files on SQLite with `P06_COMMIT_SHA` set, then run `P06_COMMIT_SHA="$P06_COMMIT_SHA" bash scripts/run-mysql-integration-tests.sh`. Expected: the SQLite output contains exactly one commit-matched `sqlite-search-query-scaling` sentinel and no MySQL sentinel; the MySQL output names `SearchQueryScalingTest`, ends with `PASS: isolated MySQL integration suite completed.`, contains no `SKIP:`, contains exactly one commit-matched `mysql-search-query-scaling` sentinel and no SQLite sentinel; query delta is ≤ 2; and authorized totals/items and stable order are correct.
- [ ] **Step 7: Measure serialization scaling.** Compare JSON encoding/response bytes at limits 25 and 100. Expected: duration ratio ≤ 4.5 and response ≤ 256 KiB; a failure must identify payload fields/transform cost before any projection change.

### Task 5: Profile API latency, pagination, concurrency, and cache correctness

**Files:**
- Create: `scripts/quality/benchmark-p06-runtime.mjs`
- Create: `scripts/quality/tests/benchmark-p06-runtime.test.mjs`

- [ ] **Step 1: Write failing sampler tests.** Cover percentile calculation, 20 warmups excluded from 100 measured samples, concurrency exactly 10, non-2xx error rate, response bytes, unsafe-origin rejection, insufficient-cardinality rejection, timeout, no-skip behavior, exact P07 manifest/environment equality, credential-descriptor/presence enforcement, credential redaction, and absence/wrong-commit/duplication of every runtime sentinel.
- [ ] **Step 2: Prove red.** Run `P06_COMMIT_SHA="$P06_COMMIT_SHA" node --test scripts/quality/tests/benchmark-p06-runtime.test.mjs`. Expected: FAIL because the sampler does not exist and no `runtime-unit` sentinel is emitted.
- [ ] **Step 3: Implement the sampler with Node built-ins and installed Playwright.** Require `P07_CONNECTION_FIXTURE_MANIFEST` and the six P06 variables from §7 before opening a browser or API context; validate equality/presence as specified there, login once through `/api/v1/identity/login`, retain cookie/CSRF in memory, generate lowercase UUIDv7 correlation IDs, and never persist password/session/CSRF. The unit runner emits `runtime-unit`, and successful live API and browser lanes emit `api-runtime` and `web-runtime`, respectively, only after every declared scenario in that lane passes.
- [ ] **Step 4: Preflight the P07-owned isolated fixture cardinality and capabilities.** Search must expose at least 100 eligible candidates before authorization; list scenarios must return a reusable cursor where applicable; the benchmark principal must have only the capabilities required by the scenario. Missing topology manifest, credential, scope, origin, commit equality, cardinality, or capability exits `2`.
- [ ] **Step 5: Measure authenticated reads.** Run Search (`limit=25` and `100`), Tasks, Documents, Reports, Dashboards, and Notifications against the stabilized production-like origin for 20 warmups plus 100 samples at concurrency 10. Expected: HTTP error rate 0, p95 ≤ 300 ms, p99 ≤ 800 ms, each list response ≤ 256 KiB.
- [ ] **Step 6: Exercise cursor regression.** Traverse two pages, assert no duplicate IDs, cursor reuse returns the same page on unchanged data, malformed cursor returns problem+json, and an allowed concurrent insert does not corrupt cursor decoding/order. Expected: all invariants pass at limits 25 and 100.
- [ ] **Step 7: Exercise isolated mutation concurrency.** Use dedicated disposable fixtures and existing route semantics: two writes with one ETag yield one success and one `412`; a valid `Idempotency-Key` replay returns the same status/body/effect; missing CSRF/capability remains denied. Expected mutation p95 ≤ 500 ms and no duplicate state/audit/outbox effect.
- [ ] **Step 8: Measure cache behavior before changing it.** Repeat authorized reads as two principals/scopes and after mutation. Expected: no cross-principal response, no stale post-mutation state, and cache headers match sensitivity. Add/expand a cache only under §8's decision gate.

### Task 6: Profile browser, React, bundle, and network paths

**Files:**
- Modify conditionally only the measured files listed in §6.
- Add targeted tests next to each changed component; no generic snapshot-only test.

- [ ] **Step 1: Add browser metric fixtures to the runtime sampler tests.** Synthetic entries cover LCP/FCP/CLS/INP, resource sizes, long tasks, and independent-request start spread.
- [ ] **Step 2: Measure cold authenticated navigation at desktop and 320×720 mobile viewports.** Routes: `/`, `/search`, `/tasks`, `/documents`, `/reports`, and `/api-docs`. Expected: LCP ≤ 2500 ms, INP ≤ 200 ms, CLS ≤ 0.1, FCP ≤ 1800 ms, TTFB ≤ 800 ms.
- [ ] **Step 3: Measure initial route transfer.** Expected on every non-doc route: initial JS ≤ 200 KiB gzip, all initial resources ≤ 1 MiB, ≤ 20 requests; every lazy route JS set ≤ 400 KiB gzip. `/api-docs` may load its lazy payload only after navigation and must not contaminate other entries.
- [ ] **Step 4: Detect request waterfalls.** Principal and scope requests, dashboard's inbox/tasks/requests, and document detail/version/link requests are independent groups. After their common prerequisite resolves, their request start-time spread must be ≤ 100 ms. A sequential trace is allowed only when the later URL/body requires data from the earlier response, recorded in evidence.
- [ ] **Step 5: Detect duplicate network work and long tasks.** A route transition issues at most one request per identical method/URL/body unless a user retry occurs; main-thread long tasks with `duration` ≥ `longTaskMs` are attributed by trace. Source changes require a failing component/browser regression proving the observed duplicate or blocking work; the budget check fails the run when `longTaskCountMax` or `longTaskTotalBlockingMs` is exceeded per cold-route transition.

- [ ] **Step 6: Apply the smallest measured React fix.** Prefer starting independent promises together, deferring optional work past critical content, narrowing serialized data, or preserving a current lazy boundary. Memoization, virtualization, and cache libraries are prohibited unless the React profiler shows the exact render/list cost breaches a budget.
- [ ] **Step 7: Rerun the same scenario and compare.** Expected: violated budget passes, behavior/accessibility test remains green, no other route exceeds request/byte budgets, and retained before/after traces use the same commit environment/data/viewport.

### Task 7: Publish immutable P06 completion evidence

**Files:**
- Create: `scripts/quality/verify-p06.mjs`

- [ ] **Step 1: Write a failing integration test around synthetic evidence directories.** Reject mismatched commit, missing P07 connection/fixture field or credential environment value, missing command, warning allowlist expiry, skip text, absent runtime scenario, failed budget, changed budget hash, missing raw-log hash, leaked secret marker, and every missing log, missing/duplicate-in-log/malformed/stale/unregistered/wrong-log/wrong-driver sentinel. Include a broad API-test log that exits `0` but omits `sqlite-search-query-scaling`; it must fail.
  Also reject an invalid mode, wrong child/replay output root, unsafe/mismatched program or P07 run ID, pre-existing/symlink root, replay mutation of child evidence, and reuse of a sealed same-HEAD replay root. Prove a second same-HEAD replay with new program/P07 run IDs succeeds into a distinct root.
- [ ] **Step 2: Prove red.** Run `P06_COMMIT_SHA="$P06_COMMIT_SHA" node --test scripts/quality/tests/*.test.mjs`. Expected: aggregate tests fail until `verify-p06.mjs` exists; any broad runner exit `0` without its assigned sentinel remains a failure.
- [ ] **Step 3: Implement aggregation and immutable publication.** Validate every required output, mode-specific exact root, the P07 manifest/environment handoff, commit/run/budget hashes, and the exact retained-log-to-sentinel map; scan for skip markers and credentials. Atomically create the absent root and `.incomplete.json`; write every retained output through same-directory temporary files plus fsync/rename. In `child` mode publish the §14 manifest/digest under `test-results/p06/${P06_COMMIT_SHA}`. In `replay` mode publish under the exact P08/P07-run-scoped root and include `program_run_id`/`p07_run_id`, without modifying child evidence. Revalidate all bytes, remove `.incomplete.json`, and write `.complete.json` last. Any collision, partial root, or mismatch fails without overwrite; retry uses a new root.
- [ ] **Step 4: Run the exact standalone gate from §7 inside P07's live topology.** Expected stdout ends with `P06 PASS: zero actionable warnings; deterministic and runtime budgets passed.` and exit `0`; every mapped log contains exactly one instance of each assigned sentinel for the same commit and no unassigned sentinel; any missing prerequisite exits `2`, never `0`.
- [ ] **Step 5: Publish the P06 completion handoff immediately after Step 4.** Owner P06 supplies P08 the immutable `manifest.json` path, `manifest.sha256`, command, budget/allowlist hashes, P07 manifest schema version, sentinel registry, and exit contract. Expected evidence: byte-stable files under the commit-addressed P06 directory; P06 does not wait for P08 acknowledgement, replay, Make/CI integration, or acceptance.
- [ ] **Step 6: Record a commit only after explicit user authorization.** Set `implementation_commit` and `last_verified_commit` to the manifest commit, transition P06 using §15 once its own criteria pass, and leave P08 to consume/replay the published evidence independently.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

- **Capture failure:** preserve raw stdout/stderr and exit code; do not retry deterministic lint/build/test failures. One retry is allowed only for a classified transient environment failure, and both attempts remain in evidence.
- **Runtime timeout:** each request has a 10-second hard timeout; any timeout counts as an error and fails the budget. The sampler cancels outstanding requests and writes partial evidence with `result: fail`.
- **Rate limiting:** benchmark only the isolated approved environment. A `429` is recorded as failure, not bypassed by increasing limits in application code.
- **Idempotency:** benchmark setup uses unique fixture IDs/keys. Replay scenarios reuse the exact same key and canonical body and assert one committed state/audit/outbox effect; a changed body with the same key must follow the route's existing conflict contract.
- **Concurrency:** stale writes use two authenticated clients holding the same strong ETag. Exactly one succeeds; the other receives `412` problem+json. Query/caching optimizations must not weaken the write predicate or `lock_version` increment.
- **Authorization:** the sampler verifies an allowed principal and a denied principal. Capability checks precede detailed validation/disclosure. Denied items never appear in list totals, cache entries, traces, or fixture output.
- **Session/CSRF:** session cookies remain HttpOnly/SameSite according to the existing contract; mutations include CSRF and reads do not manufacture bearer shortcuts. Credentials/session/CSRF never enter evidence.
- **Correlation:** every request carries a unique lowercase UUIDv7 `X-Correlation-ID`; responses and problem documents retain it.
- **Pagination:** cursors are opaque and URL-encoded, limit is capped at 100, next cursors are reusable on unchanged state, and malformed/tampered cursors fail closed without SQL/error disclosure.
- **Cache failure:** no cache optimization becomes authoritative. If a measured cache is unavailable, behavior follows the existing route's fail-open/fail-closed contract and authoritative persistence remains the source of truth.
- **Outbox:** a performance change must not move command state, audit, idempotency, or outbox effects outside their atomic transaction.

## 11. Targeted verification commands

These commands belong to future execution and were not run while drafting this plan.

### Tooling and deterministic tests

```bash
P06_COMMIT_SHA="$P06_COMMIT_SHA" node --test scripts/quality/tests/*.test.mjs
(cd apps/api && P06_COMMIT_SHA="$P06_COMMIT_SHA" php artisan test Modules/Search/Tests/SearchProjectionTest.php Modules/Search/Tests/SearchQueryScalingTest.php)
P06_COMMIT_SHA="$P06_COMMIT_SHA" bash scripts/run-mysql-integration-tests.sh
```

Expected: all Node tests pass and their retained logs contain exactly one registered `bundle-unit` and `runtime-unit` sentinel; the targeted SQLite Search output contains exactly one commit-matched `sqlite-search-query-scaling` sentinel and no MySQL sentinel; the isolated MySQL output names `SearchQueryScalingTest`, ends with `PASS: isolated MySQL integration suite completed.`, contains exactly one commit-matched `mysql-search-query-scaling` sentinel and no SQLite sentinel, and contains no `SKIP:`. `verify-p06.mjs` maps a prerequisite skip, absent test, wrong-driver marker, or other sentinel defect to exit `2` and a blocked manifest.

### Canonical zero-warning/build/test checks
```bash
(cd apps/api && composer lint)
(cd apps/api && composer analyse)
(cd apps/api && php artisan test)
npm --prefix apps/web run lint
npm --prefix apps/web run build
npm --prefix apps/web run api:check
npm --prefix apps/web run test:unit
```

Expected: exit `0`; actionable warnings `0`; generated drift `0`; test skips `0`; no unparsed diagnostics. The retained canonical broad `baseline/api-test.log` must contain exactly one commit-matched `sqlite-search-query-scaling` sentinel and no other P06 sentinel, proving discovery of `SearchQueryScalingTest`; its absence makes `verify-p06.mjs` fail even when `php artisan test` exits `0`. Redocly/OpenAPI remediation, if required, is executed by the `OPENAPI` token owner and Orval is regenerated only with `npm --prefix apps/web run api:generate`.

### Bundle gate

```bash
node scripts/quality/check-p06-bundle.mjs \
  --commit "$P06_COMMIT_SHA" \
  --budgets quality/performance/p06-budgets.json \
  --dist apps/web/dist \
  --output "test-results/p06/${P06_COMMIT_SHA}/web-bundle.json"
```

Expected: `P06 bundle PASS`; exactly one commit-matched `bundle-live` sentinel; every non-doc initial route and lazy route is within §7 budgets; and Swagger/YAML/spec modules are absent from non-doc initial reachability.

### Final standalone P06 gate

```bash
node scripts/quality/verify-p06.mjs \
  --commit "$P06_COMMIT_SHA" \
  --connection-manifest "$P07_CONNECTION_FIXTURE_MANIFEST" \
  --budgets quality/performance/p06-budgets.json \
  --allowlist quality/performance/p06-warning-allowlist.json \
  --api-origin "$P06_API_ORIGIN" \
  --web-origin "$P06_WEB_ORIGIN" \
  --output "test-results/p06/${P06_COMMIT_SHA}"
```

Expected: the P07 manifest and all six P06 environment values agree; exactly 20 warmups and 100 measured samples per runtime scenario at concurrency 10; zero HTTP errors; all API/web/query/bundle budgets pass; every retained log named by `requiredSentinels` contains exactly one commit-matched occurrence of each assigned sentinel and no unassigned sentinel; no skipped/missing/stale result; stdout ends with the P06 PASS line; and immutable `manifest.json` plus `manifest.sha256` match §14.

### Smoke-test scenarios

1. **Cold shell:** login, load `/`, principal and scope resolve in parallel, dashboard independent calls start within 100 ms, no duplicate request, non-doc initial budget passes.
2. **Search cardinality:** search 100 eligible candidates with mixed allowed/denied facts; denied records are absent, SQL delta from 10 to 100 is ≤ 2, p95/p99/bytes pass.
3. **Cursor list:** traverse Tasks/Documents/Notifications at limits 25 and 100; no duplicate/missing item on unchanged data, cursor reuse is stable, malformed cursor returns problem+json.
4. **Concurrent write:** two clients submit one current ETag; one success, one `412`, one state transition, one audit/outbox effect; idempotent replay does not duplicate effects.
5. **Cache isolation:** two principals/scopes read the same route around a mutation; no cross-principal or stale value and no PHI/PII in key/log/output.
6. **Document detail:** document, versions, and links begin within 100 ms after the shared document ID prerequisite; stale response cannot overwrite current selection.
7. **API docs isolation:** `/api-docs` loads Swagger/YAML/spec lazily; visiting `/`, `/tasks`, or `/documents` never transfers those chunks.
8. **Failure path:** a forced timeout/non-2xx/missing environment produces exit `1`/`2` and a failing manifest; it never prints PASS or records a skip.

## 12. Shared-file integration token requirements

P06 requests no shared-file ownership.

- While architecture closure is `in_progress`, it retains `Makefile`, both CI workflows, master OpenAPI, generated Orval client, module-boundary test, and any declared `apps/api/routes/web.php` work.
- An actionable OpenAPI warning is handed to the canonical `OPENAPI` queue with source, exact warning fingerprint, proposed authoritative contract correction, base commit, and targeted check. P06 does not make the edit. `ORVAL` shares that token and runs the generation command only.
- A measured shell/navigation change requires the `WEB-SHELL` queue owner; P06 may retain the failing trace but does not take the token.
- A cross-module batch-authorization need is handed to the Authorization producer owner for a published Contract; P06 cannot reach into its persistence.
- Final Make/workflow integration requires `ARCHITECTURE-CLOSURE:T13-HANDOFF` followed by P08's exclusive `CLOSURE-CI` token. Immediately after its own gates pass, P06 publishes for later P08 consumption:
  - exact `verify-p06.mjs` command, including `--connection-manifest`;
  - `quality/performance/p06-budgets.json` SHA-256 and required sentinel registry;
  - `quality/performance/p06-warning-allowlist.json` SHA-256;
  - immutable `test-results/p06/${P06_COMMIT_SHA}/manifest.json` and `manifest.sha256`;
  - P07 connection/fixture manifest schema version, required environment names, expected exit codes, and PASS line.
- P08 may accept this child manifest only when its commit is an ancestor of final HEAD, then reruns the critical P06 verifier with `--mode replay` on final HEAD inside P07's live topology and stores fresh immutable outputs at `artifacts/program-closure/$P06_COMMIT_SHA/$PROGRAM_RUN_ID/live/p06/$P07_RUN_ID`. The exact program and P07 run IDs come from the orchestrator/lifecycle handoff; no scan, `latest`, or child-manifest rewrite is permitted. P06 completion does not depend on later acceptance or replay.
- P06 cannot enter `completed` based on a local Make/CI edit or a competing closure command; it enters `completed` only from its own §14 evidence.

## 13. Rollback procedure

Rollback is by the smallest measured slice, never by weakening a budget:

1. Stop the P06 verification runner and preserve its failing/partial evidence directory.
2. Revert only the warning/hotspot slice that introduced the regression using the authorized change mechanism; do not run destructive version-control commands from this plan.
3. If a feature-local export was split, restore the original implementation and all importers together; leave no alias/re-export shim.
4. If a Search optimization was applied, restore the prior Search-owned selection path while retaining authorization checks; rerun authorization filtering and query evidence to show the known pre-change state.
5. If a cache was added, remove both read and write/invalidation paths, restore authoritative persistence reads, clear only the P06 namespaced test cache in the isolated environment, and rerun principal-isolation/stale-read tests.
6. If an owner-approved index was applied, its table owner runs the recorded `down()` migration in the isolated environment and verifies schema/data recovery before the P06 profile.
7. If a web lazy/import change was applied, restore the previous boundary and rerun route behavior plus bundle reachability; do not compensate by raising thresholds.
8. P08 removes or reverts its own `CLOSURE-CI` wiring if the standalone P06 command is withdrawn; P06 never edits those files.
9. Set P06 to `blocked`, record the failed scenario, evidence path, owner, and last safe authorized commit. A rollback is complete only when targeted functional/security tests pass; the known performance finding remains open until genuinely resolved.

## 14. Exit criteria and required retained evidence

P06 may enter `completed` only when all conditions pass on one explicitly authorized, recorded commit; P08 acknowledgement, replay, or acceptance is not a condition:

1. All seven canonical quality commands exit `0` with zero actionable warnings, zero generated drift, zero unparsed diagnostics, and no P06-targeted skip.
2. Every allowlist record is exact, source-backed, tool-version-bound, owner-assigned, unexpired, and demonstrably non-actionable; an empty allowlist is preferred.
3. Node tooling tests and Search query/pagination/security tests pass; isolated MySQL performance/concurrency execution is not skipped; every retained log in `requiredSentinels` contains exactly one commit-matched occurrence of each assigned P06 sentinel and no unassigned sentinel, including the canonical broad API-test log.
4. The P07 connection/fixture manifest supplies matching `P06_COMMIT_SHA`, API/web origins, scope, and process-environment credential descriptors; all six values are present while P07's topology is live and no secret is persisted.
5. API samples meet p95/p99/error/byte budgets with exact warmup/sample/concurrency counts and sufficient fixture cardinality.
6. Browser routes meet Core Web Vitals, initial transfer/request, lazy route, waterfall, and long-task budgets at desktop and mobile viewports.
7. Search query growth from 10 to 100 candidates is ≤ 2; serialization growth from limit 25 to 100 is ≤ 4.5; denied data remains absent.
8. Pagination, stale-write, idempotency, cache-isolation, CSRF, capability, correlation-ID, and problem+json scenarios pass.
9. Every implemented optimization has a retained failing-before/passing-after test and profile. No speculative change remains.
10. No shared file was edited by P06; any OPENAPI/ORVAL/WEB-SHELL/producer handoff is merged and released by its owner.
11. `implementation_commit` and `last_verified_commit` equal the immutable manifest's full SHA after user-authorized recording; `manifest.sha256` resolves to its exact bytes.

Required evidence directory:

```text
test-results/p06/${P06_COMMIT_SHA}/
  manifest.json
  manifest.sha256
  baseline/baseline.json
  baseline/*.log
  quality-final.json
  node-tests.tap
  api-search-targeted.log
  api-runtime.log
  web-runtime.log
  web-bundle.log
  api-tests.xml
  mysql-search-query-scaling.log
  mysql-explain/*.json
  api-runtime.json
  web-runtime.json
  web-bundle.json
  traces/*.zip
  profiles/*.json
  rollback-rehearsal.json
```

P08 replay retains the same content classes under `artifacts/program-closure/$P06_COMMIT_SHA/$PROGRAM_RUN_ID/live/p06/$P07_RUN_ID/`, plus `.incomplete.json` while unpublished and `.complete.json` only after final digest validation. The replay manifest is final-SHA/run-scoped evidence, not P06 completion evidence.

`manifest.json` schema:

```json
{
  "plan_id": "P06",
  "commit": "40-lowercase-hex",
  "mode": "child",
  "program_run_id": null,
  "p07_run_id": "safe-run-id",
  "budget_file": "quality/performance/p06-budgets.json",
  "budget_sha256": "64-lowercase-hex",
  "allowlist_sha256": "64-lowercase-hex",
  "started_at": "ISO-8601 UTC",
  "finished_at": "ISO-8601 UTC",
  "result": "pass",
  "commands": [
    {
      "command": "exact redacted command",
      "exit_code": 0,
      "duration_ms": 1,
      "output_path": "relative evidence path",
      "sha256": "64-lowercase-hex"
    }
  ],
  "sentinels": [
    {
      "log_path": "relative retained log path",
      "scenario": "assigned registered scenario id",
      "marker": "P06_SENTINEL|P06|assigned registered scenario id|PASS|40-lowercase-hex",
      "count_in_log": 1
    }
  ],
  "budgets": [
    {
      "scenario": "stable scenario name",
      "metric": "stable metric name",
      "value": 0,
      "limit": 0,
      "unit": "ms|bytes|count|ratio",
      "result": "pass",
      "evidence_path": "relative evidence path"
    }
  ],
  "smoke_scenarios": [
    {
      "name": "stable scenario name",
      "result": "pass",
      "evidence_path": "relative evidence path"
    }
  ],
  "warnings": {
    "actionable": 0,
    "generated_drift": 0,
    "allowlisted": 0,
    "unparsed": 0
  },
  "skips": [],
  "open_findings": [],
  "accepted_risks": []
}
```

Actual values replace schema examples; the verifier rejects unresolved literal schema exemplars, missing files, hash mismatch, another commit, a hidden skip, a secret pattern, or any missing log, missing/duplicate-in-log/malformed/stale/unregistered/wrong-log/wrong-driver sentinel. Publication is atomic and immutable for a commit: an existing manifest may be reused only when its bytes and `manifest.sha256` match. Any newly validated defect is registered as the next `C` finding with source/command evidence and exit criterion.

## 15. Status transition rules

- `planned → ready`: the baseline capture tooling is approved, an executor/worktree and base commit are recorded, and the ungated baseline prerequisites are available. Broad remediation may still be a named blocked phase.
- `ready → in_progress`: fresh baseline capture starts and its exact commit/evidence root is recorded.
- `in_progress → blocked`: an affected API/web file is still under architecture/module stabilization, fixture cardinality/environment is unavailable, a shared integration token is not released, a producer Contract is required, or a measured critical gate cannot execute. Record blocker, owner, evidence, and last safe commit.
- `blocked → ready`: the named owner records stabilization/handoff or environment evidence; no inferred handoff is sufficient.
- `in_progress → verification`: every actionable warning and measured violation is resolved, all producer/shared integrations are merged/released, no fake/stub remains, and the feature set is frozen for one-commit measurement inside the P07 live topology.
- `verification → completed`: every §14 P06-owned criterion passes on one user-authorized recorded commit, every retained log satisfies its exact assigned sentinel cardinality, retained evidence resolves and hashes correctly, and immutable `manifest.json` plus `manifest.sha256` are published. P08 acknowledgement, replay, Make/CI wiring, and acceptance are explicitly not prerequisites.
- `verification → blocked`: any failure, skip, stale/mixed commit, insufficient sample/cardinality, P07 manifest/environment mismatch, missing credential/scope/origin, threshold violation, sentinel defect, missing evidence, or secret leak blocks completion.
- `any → superseded`: only a later user-approved plan records the replacement, migrates P08's dependency, updates the orchestration plan/token ledger, and preserves evidence history.

Planning completion does not change the initial `planned` status. No commit is recorded until the user explicitly authorizes it.
