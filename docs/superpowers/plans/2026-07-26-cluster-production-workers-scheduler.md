# Cluster Production Workers and Scheduler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

```yaml
plan_id: P01
status: blocked
depends_on:
  - ARCHITECTURE-CLOSURE:T6
  - ARCHITECTURE-CLOSURE:T10
blocks:
  - P02
  - P03
shared_file_owner:
  - apps/api/docker/worker-loop.sh
  - apps/api/docker/scheduler-loop.sh
  - apps/api/routes/console.php
  - infra/platform/production/compose.yaml (first token)
  - infra/platform/production/.env.example (first token)
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Make every currently implemented outbox relay, Redis stream consumer, platform-operation dispatcher, and scheduled expiration task run durably and observably in the direct-VPS production topology.

**User-visible outcome:** Events and notifications continue flowing after restarts and transient MySQL/Redis failures, temporary assignments expire on time, queued platform operations are dispatched, and operators can distinguish live from stale worker/scheduler processes. The base Compose topology exposes both health gates; P03 owns making deployment/rollback scripts enforce them.

**Architecture:** Keep the repository's existing bounded Artisan commands and at-least-once MySQL-outbox → Redis-stream → idempotent-consumer design. A dedicated `worker` service runs all continuous bounded commands in dependency order; a dedicated `scheduler` service invokes Laravel's registered schedule once per minute. The two owned POSIX loops provide signal forwarding, capped exponential failure backoff, structured operational logs, atomic readiness markers, and explicit `run`, `run-once`, and `healthcheck` modes.

**Tech stack:** Laravel 12 console scheduling, PHP 8.4, MySQL 8.4, Redis Streams with AOF on the production-like test overlay, POSIX `sh`, Docker Compose, Python 3/PyYAML production-bundle policy checks.

## Global Constraints

- This plan is blocked until Architecture Closure Tasks 6 and 10 are complete and their executor explicitly hands off outbox ownership/atomicity evidence.
- The current architecture-closure plan remains `in_progress` and retains `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, master OpenAPI, generated clients, `apps/api/tests/Architecture/ModuleBoundariesTest.php`, and any active `apps/api/routes/web.php` reservation. P01 does not edit them.
- P08 alone may integrate final `Makefile` and workflow gates after `ARCHITECTURE-CLOSURE:T13-HANDOFF`; P01 exposes standalone commands and the explicit `PRODUCTION-POLICY` packet for P08 to consume.
- P01 owns `apps/api/docker/worker-loop.sh`, `apps/api/docker/scheduler-loop.sh`, and `apps/api/routes/console.php` for this queue, receives only the first serialized token for base production Compose and production env, and permanently owns the serialized `PROD-WORKLOAD-REGISTRY` token for the worker allowlist/order pair. P02 follows P01; P03 follows P02.
- P01 does not edit deployment/rollback scripts; those are reserved to P03 after P01/P02. It does not create a competing production topology, and P08 must not rerun P01 against its disjoint smoke overlay after P02 has merged.
- Production adapters fail closed. Test-only fixture commands must reject every environment other than `testing` and must never be used as a production fallback.
- No generated client is edited. If a future public HTTP contract changes, it must go through the contract generation queue; this plan adds no HTTP route.
- No commit, push, deployment, migration, external message, or cloud change is authorized. A commit SHA may be recorded only after explicit user authorization.

---

## 1. Status, Dependencies, and Handoff Gates

P01 may perform read-only revalidation while `blocked`, but no implementation begins until both start gates below are evidenced:

1. `ARCHITECTURE-CLOSURE:T6`: `Shared\Contracts\TransactionalOutbox` ownership, event catalog, duplicate semantics, and the final Documents outbox decision are recorded; no producer performs an unauthorized raw cross-owner outbox write.
2. `ARCHITECTURE-CLOSURE:T10`: state, idempotency, audit, and outbox writes commit atomically for every producer family; failure-injection evidence is retained.

The executor records the source commit and evidence paths for both gates before moving P01 from `blocked` to `ready`. If either task is incomplete, stale, or later reopened, P01 stays or returns `blocked` without editing its owned shared files.

Downstream handoffs:

- P02 receives the runtime workload contract and the next `compose.yaml`/`.env.example` token only after P01 reaches `completed`.
- P03 consumes the same runtime/recovery contract after P01, but its topology token still waits for P02.
- After P02 merges, P02 owns the merged-topology smoke adapter described in Task 6 and hands its one topology/run manifest to P07/P08. P08 must invoke that adapter, not P01's disjoint overlay smoke or P02's verifier as separate topologies.
- M03's first downstream `PROD-WORKLOAD-REGISTRY` request is one atomic packet for `shared:relay-collaboration` immediately followed by `notifications:consume-collaboration`; M03 supplies the packet and never edits P01's loop or test.
- P08 receives P01's `PRODUCTION-POLICY` packet after P01 completion and may apply it only while holding that token after `ARCHITECTURE-CLOSURE:T13-HANDOFF`. P01 does not add Make or CI wiring.

## 2. Goal and User-Visible Outcome

The completed system must satisfy all of the following:

- `worker` continuously runs the currently implemented bounded workload commands and never silently omits technical-alert delivery or platform-operation dispatch.
- `scheduler` evaluates registered schedules once per minute and runs temporary-assignment expiration under a distributed overlap lock.
- A successful readiness probe proves that a complete workload cycle recently succeeded; process existence alone is insufficient.
- Failed cycles do not refresh readiness. They emit bounded, payload-free logs, retry with capped exponential backoff, and become unhealthy after the configured freshness window.
- `SIGTERM`/`SIGINT` removes readiness, forwards the signal to the active child, waits for it, starts no further command, and exits cleanly within Compose's grace period.
- Restarting either service recovers unpublished MySQL outbox rows and stale Redis pending entries without duplicating domain effects.
- No user-facing API semantics change: problem+json, correlation IDs, session/CSRF, capability checks, Idempotency-Key, ETag/If-Match/`lock_version`, cursor pagination, and atomic outbox rules remain owned by their existing HTTP/module layers.

## 3. Current Source Evidence

Fresh executors must re-read these exact sources before editing and record any drift in the retained evidence manifest:

1. `infra/platform/production/compose.yaml:1-58` centralizes the API environment and hardening. It currently sets `QUEUE_CONNECTION: sync`, uses Redis for cache/session, mounts durable application storage, runs read-only, enables an init process, and sets a 20-second stop grace period.
2. `infra/platform/production/compose.yaml:137-168` already declares `worker` and `scheduler`, but their health checks trust timestamp files directly. The services start after `migrate`; the scheduler is not included in deployment/local readiness scripts.
3. `apps/api/docker/worker-loop.sh:1-24` runs only Organization relay/Identity consume and WorkRecords relay/Notifications consume. It omits `platform-settings:relay-technical-alerts`, `notifications:consume-technical-alert`, and `platform-operations:dispatch`; it touches readiness only after the four commands but has no retry policy, structured failure log, child signal forwarding, or healthcheck mode.
4. `apps/api/docker/scheduler-loop.sh:1-20` calls `schedule:run`, touches readiness, and sleeps. It validates only numeric syntax, accepts zero, has no backoff/structured logs/child forwarding/healthcheck mode, and currently has no scheduled task to run.
5. `apps/api/routes/console.php:19-157` defines bounded WorkRecords, technical-alert, Organization-person, and Identity-person commands. Each requires `--once`; consumer names are bounded to `[A-Za-z0-9][A-Za-z0-9._-]{0,63}`.
6. `apps/api/Modules/Organization/Features/TemporaryAssignment/Console/ExpireTemporaryAssignmentsCommand.php:10-68` implements `organization:expire-temporary-assignments --once --limit=1..500`. Its handler uses a transaction, optimistic predicate, owner outbox, and `has_more` signal (`.../Handler/ExpireTemporaryAssignmentsHandler.php:24-72`).
7. `apps/api/Modules/PlatformSettings/Features/Operations/Console/RunPlatformOperationsDispatchCommand.php:9-47` implements bounded `platform-operations:dispatch --once --limit=1..100` and propagates failure by exit status.
8. `apps/api/Modules/WorkRecords/Infrastructure/Outbox/Relay/RedisOutboxRelay.php:18-51`, `apps/api/Modules/Organization/Infrastructure/Outbox/Relay/OrganizationPersonOutboxRelay.php:21-45`, and `apps/api/Modules/PlatformSettings/Infrastructure/Outbox/TechnicalAlertOutboxRelay.php:19-50` select committed unpublished rows, publish to Redis Streams, and mark published only after `XADD` succeeds.
9. `apps/api/Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Worker/NotificationsStreamWorker.php:35-118`, `.../ConsumeTechnicalAlert/Worker/NotificationsTechnicalAlertWorker.php:35-100`, and `apps/api/Modules/Identity/Features/ConsumeOrganizationPersonEvents/Worker/IdentityPersonStreamWorker.php:34-100` reclaim stale pending entries, leave retryable failures unacknowledged, and dead-letter exhausted failures after three deliveries.
10. `apps/api/Shared/Infrastructure/Streams/LaravelRedisStreamTransport.php:94-143` acknowledges messages explicitly and publishes DLQ records idempotently through a stream plus source-message index.
11. No `ShouldQueue`, `Queue::`, `Bus::`, dispatched Laravel jobs, or default `jobs`/`failed_jobs` migrations exist in the current API source. P01 therefore does not invent a `queue:work` lane or claim that `QUEUE_CONNECTION=sync` is durable; the durable lanes in scope are existing MySQL outboxes, Redis Streams, and platform operation request rows.
12. `scripts/production_bundle_policy.py:39-50` currently forbids `scheduler` while `compose.yaml` declares it, and requires health/hardening only for the older service set. P01 records this drift but does not edit that P08-composed closure surface; P01 creates a narrow workload validator, and P08 must integrate its rules after `ARCHITECTURE-CLOSURE:T13-HANDOFF`.
13. `infra/platform/production/deploy-vps.sh:66-81` and `run-local-e2e.sh:153-168` wait for `worker` but not `scheduler`. P01 must not edit those scripts because deployment/rollback ownership belongs to P03 and production E2E integration belongs to P07; P01's evidence must explicitly hand this gap to them.
14. `Modules\PlatformSettings\Infrastructure\LaravelPlatformHealthGateway` currently reports queue health from “a configured queue name” only (`apps/api/Modules/PlatformSettings/Infrastructure/LaravelPlatformHealthGateway.php:53-58`). It is not container readiness and must not be presented as proof that worker/scheduler cycles are running.

## 4. Scope and Explicit Non-Goals

### In scope

- Complete the command list and deterministic ordering of the `worker` service.
- Register temporary-assignment expiration with Laravel's scheduler.
- Harden both loop scripts for signals, failure retry/backoff, readiness, and structured logs.
- Make Compose/env declare and validate the workload runtime contract.
- Create a standalone P01 workload-topology validator; hand its proven rules to P08 for composition into the existing production-bundle policy.
- Add targeted unit/feature/Compose tests and one production-like workload smoke runner using a P01-owned test overlay.
- Define deployment order, failure recovery, quiesce/drain/replay rules, and evidence retention for P02/P03/P07/P08.

### Non-goals

- No P02 Documents S3/ClamAV runtime, document worker, or Documents outbox resolution.
- No P03 backup, restore, deploy, or rollback script edits.
- No P07 browser-runner/workflow integration and no P08 Make/CI integration.
- No edits to `scripts/production_bundle_policy.py` or `infra/platform/production/compose.test.yaml`; P08 and the orchestrated production-E2E queue own their later composition.
- No new public API route, request/response schema, capability, UI, or generated client.
- No new Laravel `ShouldQueue` jobs, Horizon/Supervisor, queue dashboard, queue table, or claim that synchronous Laravel dispatch is durable.
- No changes to module ranks, planned-module inventory, table ownership, module route/OpenAPI queues, or web shell aggregation.
- No direct cross-owner SQL, foreign key, or Infrastructure import introduced for operational convenience.
- No automatic DLQ purge or destructive replay of events.

## 5. Architecture and Ownership Boundaries

### 5.1 Runtime service contract

| Service | Entrypoint | Modes | Readiness marker | Default freshness | Restart |
|---|---|---|---|---:|---|
| `worker` | `/usr/local/bin/worker-loop` | `run` (default), `run-once`, `healthcheck` | `/tmp/worker.ready` | 30 seconds | `unless-stopped` |
| `scheduler` | `/usr/local/bin/scheduler-loop` | `run` (default), `run-once`, `healthcheck` | `/tmp/scheduler.ready` | 90 seconds | `unless-stopped` |

A marker contains one UTC epoch second followed by a newline. It is written to a same-directory temporary file with mode `0640`, then atomically renamed. Each loop removes its marker before startup, before retry sleep, and on signal. `healthcheck` accepts no extra arguments, validates that the marker contains only a positive integer, rejects future timestamps more than five seconds ahead, and succeeds only when `now - marker <= *_READY_MAX_AGE_SECONDS`. On `INT`/`TERM`, the loop forwards `TERM` to its active child, waits up to `WORKLOAD_SHUTDOWN_GRACE_SECONDS` (default 15), sends `KILL` only if that bound expires, reaps the child, and exits 0; the bound must remain below Compose's 20-second `stop_grace_period`.

### 5.2 Serialized worker workload registry and command order

The initial registry contains these seven bounded commands, and every `worker-loop run`/`run-once` cycle executes the allowlist in this exact order, records each exit code, and reports cycle success only when every registered command succeeded:

1. `php artisan organization:relay-person-events --once --no-interaction`
2. `php artisan identity:consume-person-events --once --consumer="$consumer" --no-interaction`
3. `php artisan work-records:relay-pending --once --no-interaction`
4. `php artisan notifications:consume-work-record-submitted --once --consumer="$consumer" --no-interaction`
5. `php artisan platform-settings:relay-technical-alerts --once --limit="$OUTBOX_RELAY_BATCH_SIZE" --no-interaction`
6. `php artisan notifications:consume-technical-alert --once --consumer="$consumer" --limit="$NOTIFICATIONS_STREAM_BATCH_SIZE" --no-interaction`
7. `php artisan platform-operations:dispatch --once --limit="$PLATFORM_OPERATIONS_DISPATCH_BATCH_SIZE" --no-interaction`

This is an initial allowlist, not a permanently fixed list. Its only extension path is the serialized P01-owned `PROD-WORKLOAD-REGISTRY` token covering `apps/api/docker/worker-loop.sh` and `apps/api/docker/tests/worker-loop-test.sh` together. A downstream owner submits a packet containing the bounded Artisan argv, dependency-order position and rationale, batch/consumer environment inputs, retry/idempotency owner evidence, privacy-safe log name, and focused module verifier. P01 grants one request at a time, adds the command and exact-order assertion in the same integration change, runs the shell order suite plus the supplied module verifier, records the merged SHA and outputs, then releases the token. No caller may append a command through Compose, an alternate loop, or an untested shell branch. The first queued packet is M03-owned `notifications:consume-collaboration`; M03 changes neither registry file.

The loop does not stop at the first nonzero status: independent lanes still make progress, while the combined cycle remains failed and does not refresh readiness. The consumer is `WORKER_CONSUMER_PREFIX` plus `-` plus the container hostname; the complete result must satisfy the existing 64-character command constraint. Compose sets a stable prefix, never a shared full consumer name.

### 5.3 Scheduler contract

`apps/api/routes/console.php` registers:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('organization:expire-temporary-assignments --once --limit=100')
    ->name('organization.expire-temporary-assignments')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);
```

The schedule uses the production Redis cache store for the distributed ownership/overlap lock. The production `scheduler` Compose service is the sole production invoker of `php artisan schedule:run --no-interaction`; API, web, worker, migrate, Caddy, operations, and replica containers must not run `schedule:run`, cron, `schedule:work`, or an equivalent scheduler supervisor. API replicas only serve requests. `scheduler-loop` calls `schedule:run` once on startup and subsequently at minute boundaries. A failed invocation follows the scheduler backoff contract and does not refresh readiness. Expiration itself remains Organization-owned controller/command → handler → Organization persistence/outbox; P01 does not move that logic into Shared or shell code.

### 5.4 Retry/backoff and observability contract

- Worker failure delay: `min(WORKER_BACKOFF_INITIAL_SECONDS * 2^(consecutive_failures-1), WORKER_BACKOFF_MAX_SECONDS)`, defaults 1 and 30.
- Scheduler failure delay: the same formula with defaults 5 and 60.
- Success resets the failure count and returns to `WORKER_POLL_SECONDS` (default 2) or the next scheduler minute boundary (`SCHEDULER_POLL_SECONDS`, fixed default 60).
- All numeric settings must match `[1-9][0-9]*`; max must be greater than or equal to initial; `WORKLOAD_SHUTDOWN_GRACE_SECONDS` must be lower than Compose's `stop_grace_period`; invalid configuration exits 2 before readiness is created.
- Each loop writes one JSON object per lifecycle/cycle/command event to stdout/stderr with keys `timestamp`, `service`, `event`, `cycle`, `command`, `exit_code`, `duration_seconds`, `consecutive_failures`, and `next_delay_seconds` as applicable. Logs contain command names and counts only, never event payloads, access context, credentials, PHI/PII, raw exception messages, or environment values.

## 6. Files to Create, Modify, Move, or Remove

### Modify

- `apps/api/docker/worker-loop.sh` — implement the token-controlled worker registry, modes, signal/backoff/log/readiness contract.
- `apps/api/docker/scheduler-loop.sh` — implement scheduler modes and aligned polling as the sole production `schedule:run` invoker, with signal/backoff/log/readiness behavior.
- `apps/api/routes/console.php` — register the one Organization-owned scheduled command; preserve all existing command behavior.
- `infra/platform/production/compose.yaml` — first-token base topology: pass validated workload env, use loop `healthcheck` modes, preserve hardening/migration dependency, and make scheduler a required production service.
- `infra/platform/production/.env.example` — first-token operator-facing defaults and explanations for workload settings; no secret values.
- No other existing file. In particular, do not edit `infra/platform/production/compose.test.yaml` or `scripts/production_bundle_policy.py` without a separately recorded integration token.

### Create

- `apps/api/docker/tests/worker-loop-test.sh` — hermetic command-stub tests for order, aggregate failure, backoff, readiness, health, and signals.
- `apps/api/docker/tests/scheduler-loop-test.sh` — hermetic command-stub/time tests for minute alignment, failure/backoff, readiness, health, and signals.
- `apps/api/tests/Feature/ProductionSchedulerWiringTest.php` — schedule registration, frequency, command options, overlap lock, and bounded command behavior.
- `scripts/validate-production-workloads.py` — P01-owned, narrow validator for required worker/scheduler commands, health, migration dependency, restart policy, hardening, and workload env.
- `scripts/tests/test_validate_production_workloads.py` — validator regression tests for missing/miswired worker/scheduler services.
- `infra/platform/production/compose.workloads.test.yaml` — P01-owned disposable MySQL/Redis endpoint overlay for the workload smoke only; it extends the canonical base topology and does not compete with it.
- `infra/platform/production/run-workers-scheduler-smoke.sh` — disposable Compose smoke, fault injection, recovery assertions, and evidence capture.

### Move/remove

None. Do not create aliases, compatibility wrappers, or a second production Compose file.

## 7. Public Contracts, Events, Routes, Schemas, and Capability Names

### Public API

No HTTP route, OpenAPI operation, capability code, schema, request/response body, Event type, or generated client changes are introduced. Existing authorization behavior remains at module-owned HTTP/application boundaries. Console access is an infrastructure boundary: the workload containers expose no host port and run under the image's non-root user.

### Operational command contract

- Existing bounded commands in the initial §5.2 registry remain module-owned public console contracts; registry membership and order are P01-owned through `PROD-WORKLOAD-REGISTRY`. The first downstream packet is the M03 Shared relay plus Notifications consumer pair and must be applied atomically.
- New shell modes are exactly `run`, `run-once`, and `healthcheck`; unknown modes exit 2 and print only usage.
- P02 must not edit P01's loop scripts. A Documents worker must be a module-owned bounded command in a dedicated `documents-worker` Compose service or a P02-owned service under its next topology token.
- Future worker-loop additions require the §5.2 request packet and serialized token; M03's `notifications:consume-collaboration` is the first named request. Future modules register schedules in their module service provider using Laravel `Schedule`; they do not take concurrent ownership of `routes/console.php`.

### Existing event/stream contracts exercised

- MySQL shared outbox event types approved by Architecture Closure T6, including `com.cluster.workrecord.submitted.v1` and Organization person/temporary-assignment events.
- Platform Settings outbox event `com.cluster.platform.technical-alert.v1`.
- Redis streams `platform.work-record.submitted.v1`, `platform.organization.identity-provisioning-requested.v1`, `platform.organization.person-access-status-changed.v1`, `platform.organization.person-updated.v1`, `platform.technical-alert.v1`, and `platform.dlq.v1`.
- Consumer groups remain module-owned constants. P01 changes only per-process consumer identity, not group or stream names.

## 8. Database Tables, Indexes, Migration Order, and Recovery

P01 adds no table, index, constraint, foreign key, migration, or migration-order entry.

### Authoritative and transport state

- Module domain tables and MySQL outbox rows are authoritative. `outbox_events` and `platform_settings_outbox` retain the published event body and publication state.
- `platform_operation_requests` is authoritative for queued/running/completed operation dispatch. P01 invokes its existing owner command only.
- Redis Streams, consumer-group pending-entry lists, and `platform.dlq.v1` are durable transport/review state. They are not cache and must be included by P03's Redis backup/restore contract. Redis AOF is required in the production-like validation topology.
- Cache keys are rebuildable. Session keys are operational state but outside event replay. Neither may be used as proof that an outbox row was consumed.
- Published MySQL rows are replay sources only through an explicitly approved, event-ID-preserving recovery procedure. Never globally set `published_at = NULL` or purge a stream/DLQ.

### Quiesce, drain, and replay handoff

P03 must use these P01 contracts after it receives its topology token:

```bash
docker compose --env-file infra/platform/production/.env.production \
  --file infra/platform/production/compose.yaml stop scheduler caddy web api worker
docker compose --env-file infra/platform/production/.env.production \
  --file infra/platform/production/compose.yaml run --rm --no-deps worker /usr/local/bin/worker-loop run-once
```

The one-shot drain command exits 0 only if every lane succeeds. Repeat it until two consecutive cycles report zero processed/relayed/dispatched work and owner-specific MySQL unpublished counts plus Redis `XPENDING` counts are zero. If a lane cannot drain, abort backup/restore rather than deleting or acknowledging data.

Recovery order is MySQL → Redis/AOF → migrations → `api` → `worker` → `scheduler` → `web`/`caddy`. When Redis transport state is unavailable but MySQL is intact, restore Redis first where possible. If replay is required, identify exact event IDs from the retained pre-failure outbox/stream evidence, requeue only those IDs through the owning module's approved replay path, preserve CloudEvent IDs, and prove consumer idempotency. P01 does not add a broad replay command because such a command would cross owner boundaries and make destructive misuse easy.

## 9. TDD Implementation Tasks

### Task 1: Establish and record the gated baseline

**Files:** Read only the gate evidence and paths in §§1 and 3.

**Interfaces:** Consumes the final T6 outbox owner/event catalog and T10 atomicity semantics; produces an execution note containing their commit SHA and evidence paths.

- [ ] **Step 1: Confirm T6 and T10 are closed on the same candidate base.**

Inspect their retained command outputs and confirm there are no unresolved outbox ownership/atomicity failures. Do not infer closure from plan checkboxes.

- [ ] **Step 2: Re-run the source inventory without editing.**

Run:

```bash
cd apps/api && php artisan list --format=json
cd apps/api && php artisan schedule:list
```

Expected before implementation: all seven bounded module commands exist; the expiration command exists; `schedule:list` does not yet show `organization.expire-temporary-assignments`.

- [ ] **Step 3: Move status only after both gates are evidenced.**

Expected: status becomes `ready`, then `in_progress` when the first test is written. If ownership or atomicity is unresolved, status remains `blocked` and implementation does not begin.

### Task 2: Test and implement the worker loop runtime contract

**Files:**
- Create: `apps/api/docker/tests/worker-loop-test.sh`
- Modify: `apps/api/docker/worker-loop.sh`

**Interfaces:** Consumes the initial seven-command `PROD-WORKLOAD-REGISTRY` in §5.2 and preserves its token-controlled extension seam. Produces `/usr/local/bin/worker-loop {run|run-once|healthcheck}`, `/tmp/worker.ready`, and the worker JSON log schema.

- [ ] **Step 1: Write failing hermetic tests.**

The test creates a temporary `php` executable earlier in `PATH`. That stub appends its arguments to a command log, returns an injected status for a named command, and sleeps when requested. The test must assert:

```text
run-once executes every initial registry command in exact §5.2 order; an added fixture command fails until the allowlist and order assertion change together
all-zero command statuses => exit 0 and a fresh atomic marker
one injected failure => remaining independent commands still run, exit 1, no marker
run retries with delays 1,2,4 capped at configured maximum; success resets delay
invalid/zero poll, backoff, max-age, batch, or overlong consumer configuration => exit 2
healthcheck rejects missing, malformed, future, and stale markers; accepts a fresh marker
TERM removes marker, reaches the active child, starts no next command, and exits 0 within `WORKLOAD_SHUTDOWN_GRACE_SECONDS`; a child that ignores TERM is killed and reaped after that bound
logs contain required keys and do not contain injected payload/secret text
```

- [ ] **Step 2: Run the test and prove red.**

Run:

```bash
sh apps/api/docker/tests/worker-loop-test.sh
```

Expected: FAIL because current script has no mode dispatcher, omits three commands, touches readiness directly, and has no health/backoff contract.

- [ ] **Step 3: Implement the minimal POSIX loop.**

Use only POSIX shell primitives available in the API Alpine image. Implement named functions `validate_positive_integer`, `json_log`, `mark_ready`, `healthcheck`, `run_child`, `run_cycle`, `next_backoff`, and `shutdown`. Use `mktemp` in `/tmp`, `chmod 0640`, and `mv` for the marker. Store the active child PID, trap `INT TERM`, remove the marker, forward `TERM` once, and wait in one-second increments up to `WORKLOAD_SHUTDOWN_GRACE_SECONDS`; if the child still exists, send `KILL` and reap it. Do not use `eval`, interpolate a command string, print environment values, or background more than the one active child/sleep.

Every child call is an argv list. Capture status with `set +e; run_child ...; status=$?; set -e`. Aggregate status without suppressing later lanes. In `run-once`, perform exactly one cycle and return its aggregate status without retry sleep. In `run`, mark ready only after a full success; on failure remove readiness, calculate capped backoff, log it, sleep interruptibly, and retry.

- [ ] **Step 4: Run the focused test and prove green.**

Run:

```bash
sh apps/api/docker/tests/worker-loop-test.sh
```

Expected: `PASS: worker loop order, readiness, backoff, logs, and signals` and exit 0.

### Task 3: Test and implement scheduler registration and loop semantics

**Files:**
- Create: `apps/api/docker/tests/scheduler-loop-test.sh`
- Create: `apps/api/tests/Feature/ProductionSchedulerWiringTest.php`
- Modify: `apps/api/docker/scheduler-loop.sh`
- Modify: `apps/api/routes/console.php`

**Interfaces:** Produces the exact schedule in §5.3 and `/usr/local/bin/scheduler-loop {run|run-once|healthcheck}` with `/tmp/scheduler.ready`.

- [ ] **Step 1: Write the failing Laravel schedule tests.**

Assert that `Schedule::events()` contains exactly one event named `organization.expire-temporary-assignments`, its command contains `organization:expire-temporary-assignments --once --limit=100`, it is due every minute, it uses one-server and overlap mutex behavior, and two concurrent invocations cannot expire/emit the same assignment twice. Also assert the command rejects missing `--once`, limit 0, and limit 501.

- [ ] **Step 2: Write the failing shell tests.**

Use the same stub approach as Task 2 and assert `run-once` invokes exactly `php artisan schedule:run --no-interaction`; success writes readiness; failure does not; `run` aligns successful repeats to 60-second boundaries; capped failure backoff is 5,10,20,…,60; health and signal behavior matches §5.1.

- [ ] **Step 3: Run both focused tests and prove red.**

Run:

```bash
cd apps/api && php artisan test tests/Feature/ProductionSchedulerWiringTest.php
sh apps/api/docker/tests/scheduler-loop-test.sh
```

Expected: Laravel test FAIL because no schedule is registered; shell test FAIL because current loop lacks modes, health/backoff, atomic state, and child forwarding.

- [ ] **Step 4: Register the schedule and implement the loop.**

Add the exact fluent registration from §5.3 after the command definitions in `routes/console.php`. Implement scheduler functions with the same security/logging rules as Task 2. After a successful run, sleep `poll_seconds - (current_epoch % poll_seconds)`, treating a full modulus as `poll_seconds`; failure uses backoff instead of minute-alignment sleep. `run-once` never sleeps.

- [ ] **Step 5: Run both focused tests and prove green.**

Run the two commands from Step 3.

Expected: all assertions PASS; `schedule:list` reports `organization.expire-temporary-assignments` every minute.

### Task 4: Test and implement the base production workload topology contract

**Files:**
- Create: `scripts/validate-production-workloads.py`
- Create: `scripts/tests/test_validate_production_workloads.py`
- Create: `infra/platform/production/compose.workloads.test.yaml`
- Modify: `infra/platform/production/compose.yaml`
- Modify: `infra/platform/production/.env.example`

**Interfaces:** Consumes loop modes from Tasks 2–3. Produces required `worker` and `scheduler` services, a narrow P01 validator, and a disposable test overlay without changing public ports, production state-service ownership, or P08's composed bundle-policy surface.

- [ ] **Step 1: Write failing policy tests.**

Create minimal valid Compose fixtures and mutations proving the validator rejects. The test owner is P01; each mutation invokes `python3 -m unittest scripts.tests.test_validate_production_workloads -v`, expects a nonzero assertion if the validator accepts it, and retains the named case output:

```text
missing worker or scheduler
wrong loop command or healthcheck mode
missing migrate service_completed_successfully dependency
restart policy other than unless-stopped
missing read_only/no-new-privileges/cap_drop/init/stop_grace_period
healthcheck that reads the marker directly instead of calling the loop
invalid or omitted workload env contract
bundled production MySQL/Redis or host/bind/privileged access
schedule invocation from any production service other than the single `scheduler` service, including an API replica, or more than one scheduler service
```

It must accept exactly the repository topology after implementation.

- [ ] **Step 2: Run policy tests and prove red.**

Run:

```bash
python3 -m unittest scripts.tests.test_validate_production_workloads -v
python3 scripts/validate-production-workloads.py
```

Expected: tests FAIL because the P01 validator does not exist and the current base service healthchecks do not call loop `healthcheck` modes.

- [ ] **Step 3: Update Compose and env.**

Preserve the `x-api-common` hardening and `migrate` dependency. Set health tests to:

```yaml
worker:
  command: ["/usr/local/bin/worker-loop", "run"]
  healthcheck:
    test: ["CMD", "/usr/local/bin/worker-loop", "healthcheck"]
scheduler:
  command: ["/usr/local/bin/scheduler-loop", "run"]
  healthcheck:
    test: ["CMD", "/usr/local/bin/scheduler-loop", "healthcheck"]
```

Add the exact non-secret settings and defaults to `.env.example` and interpolate them into the relevant service environments:

```dotenv
WORKER_CONSUMER_PREFIX=production-worker
WORKER_POLL_SECONDS=2
WORKER_BACKOFF_INITIAL_SECONDS=1
WORKER_BACKOFF_MAX_SECONDS=30
WORKER_READY_MAX_AGE_SECONDS=30
WORKLOAD_SHUTDOWN_GRACE_SECONDS=15
OUTBOX_RELAY_BATCH_SIZE=100
NOTIFICATIONS_STREAM_BATCH_SIZE=100
PLATFORM_OPERATIONS_DISPATCH_BATCH_SIZE=10
SCHEDULER_POLL_SECONDS=60
SCHEDULER_BACKOFF_INITIAL_SECONDS=5
SCHEDULER_BACKOFF_MAX_SECONDS=60
SCHEDULER_READY_MAX_AGE_SECONDS=90
```

The expiration schedule uses the fixed, tested batch of 100 shown in §5.3; changing it requires updating the schedule contract and its focused test together. Keep `QUEUE_CONNECTION=sync` with an explicit comment that no Laravel queued-job producer/table exists and synchronous dispatch is not the durability mechanism. P01 must not add `queue:work` without a real producer and migration contract.

- [ ] **Step 4: Implement and run the P01 workload validator.**

Implement `validate-production-workloads.py` to parse the canonical base Compose and reject every mutation listed in Step 1, including `schedule:run`, `schedule:work`, cron, or an equivalent scheduler command outside the sole `scheduler` service. It must validate only the workload contract and emit stable `ERROR [code] path: message` failures plus `Production workload topology validation passed.` on success. Create `compose.workloads.test.yaml` with test-only `scheduler`/`worker` MySQL and Redis host-port interpolation plus disposable MySQL 8.4/Redis 8.2 AOF services; do not copy application topology into the overlay.

Run:

```bash
python3 -m unittest scripts.tests.test_validate_production_workloads -v
python3 scripts/validate-production-workloads.py
docker compose --env-file infra/platform/production/.env.example \
  --file infra/platform/production/compose.yaml \
  --file infra/platform/production/compose.workloads.test.yaml config --quiet
```

Expected: all tests PASS, validator prints `Production workload topology validation passed.`, and Compose exits 0 with both services resolved. Retain the validator's rule-to-evidence mapping for P08; P01 does not edit or claim ownership of `scripts/production_bundle_policy.py`.

### Task 5: Build the production-like workload smoke and recovery proof

**Files:**
- Create: `infra/platform/production/run-workers-scheduler-smoke.sh`
- Modify: `apps/api/routes/console.php` — add the testing-only, bounded `production:workloads-smoke-seed {scenario}` command described below.

**Interfaces:** Produces P01's standalone child gate and reusable worker/scheduler scenario-only interface. The script exposes exactly two forms: no arguments for owned standalone lifecycle, or `--connection-manifest <path> --evidence-dir <path> --no-lifecycle` for an already-live topology. It does not edit Make, workflows, deploy scripts, rollback scripts, or claim to be P08's final merged-topology gate.

- [ ] **Step 1: Write the smoke script with deterministic standalone and scenario-only modes.**

With no arguments, use a unique Compose project, random free host ports/secrets, `compose.yaml` plus `compose.workloads.test.yaml`, and a trap that always removes containers/volumes. Start `mysql redis migrate api worker scheduler`; wait for migration success and both workload health checks. Capture resolved Compose config and image IDs before scenarios.

With `--connection-manifest <path> --evidence-dir <path> --no-lifecycle`, require all three arguments together; reject unknown/duplicate arguments, a missing manifest, a nonempty/preexisting evidence target, a non-testing environment, or manifest SHA/topology/endpoint mismatch. Read the existing project name, topology/run ID, final SHA, resolved Compose digest, image IDs, service connection data, and absolute `delegated_action_command` path from the manifest. Never invoke Compose `up`, `down`, `start`, `stop`, `restart`, `kill`, `rm`, volume/network mutation, or install an exit trap that owns P07 resources. Run the same Steps 2–3 assertions against those live services and leave the topology running. For Redis outage/recovery, worker/scheduler TERM/restart, and overlap-instance setup, execute only `"$delegated_action_command" <action> --topology-run-id "$TOPOLOGY_RUN_ID"`, where `<action>` is one of `redis-outage`, `redis-recover`, `worker-term-recover`, `scheduler-term-recover`, `scheduler-overlap-start`, or `scheduler-overlap-stop`. P07 creates this same-project command in its per-run private directory with mode `0700`, owned by the current runner user, before exporting the manifest; P01 rejects a non-absolute path, symlink, wrong owner/mode, path outside that run directory, or changed device/inode between validation and execution. The command validates the topology ID, performs the bounded action against P07's existing Compose project, waits for the action postcondition, and writes exactly one JSON object to stdout: `{"schema_version":1,"topology_run_id":"<id>","action":"<allowed-token>","outcome":"passed","started_at_utc":"<RFC3339Z>","finished_at_utc":"<RFC3339Z>"}` with no additional stdout; stderr is diagnostic only. P01 validates the exact keys/types, matching ID/action, `outcome: passed`, timestamp order, and exit 0, then stores the receipt with its assertions. No cryptographic signature or separate key/socket protocol exists; filesystem ownership plus exact path/inode and topology-ID binding are the authentication boundary. P07 retains all lifecycle ownership.

Add `production:workloads-smoke-seed {scenario}` in the already P01-owned `routes/console.php`. It must require `APP_ENV=testing`, accept a UUIDv7 scenario ID, reject an existing ID, and invoke existing owner handlers/contracts to create the WorkRecord event, technical alert, and queued platform operation. Create the temporary assignment through `TemporaryAssignmentHandler` with `start_at` equal to the current UTC millisecond and `end_at` two seconds later, then let the smoke wait until it is due; do not bypass the no-backdate rule or insert directly into owner tables. Print only the scenario ID and created aggregate IDs as JSON; never print payloads, people, access context, or secrets.

- [ ] **Step 2: Add exact happy-path scenarios.**

Assert with owner-facing commands/queries:

1. WorkRecord and Organization outbox rows become published; corresponding notification/Identity effects exist once.
2. Technical-alert outbox becomes published; notification fan-out exists once and contains no user ID in the event payload.
3. A queued platform operation reaches its existing terminal dispatch state exactly once.
4. A due temporary assignment becomes `expired`, increments `lock_version` once, and emits exactly one `com.cluster.organization.temporaryassignmentexpired.v1` event.
5. Two idle cycles leave counts unchanged and both health checks fresh.

- [ ] **Step 3: Add fault and recovery scenarios.**

Assert:

1. Stop Redis during a worker cycle: MySQL rows remain unpublished, worker readiness becomes stale/unhealthy, logs show command-level failure/backoff without payloads, and the container stays in restart policy.
2. Restart Redis: worker becomes healthy, pending rows publish, stale Redis pending entries are reclaimed after the existing 60-second threshold, and consumer effects remain single.
3. Send `TERM` to worker and scheduler while their stubbed testing command is active: readiness disappears immediately, child receives TERM, neither starts a new command, and each exits within `WORKLOAD_SHUTDOWN_GRACE_SECONDS`; separately prove a child that ignores TERM is killed/reaped at the bound. Restart services and prove readiness returns.
4. Hold the scheduler overlap lock and run two scheduler instances: at most one expiration command executes; after lock release, the next minute processes remaining due rows.
5. Inject an exhausted invalid event: retryable attempts remain unacknowledged before attempt three; attempt three creates one durable DLQ record and one source-message index entry, then acknowledges the source.

- [ ] **Step 4: Run the complete standalone smoke.**

Run:

```bash
./infra/platform/production/run-workers-scheduler-smoke.sh
```

Expected final line:

```text
PASS: production worker/scheduler order, readiness, retry, recovery, scheduling, DLQ, and signal scenarios
```

Any skipped scenario, missing dependency, stale marker, duplicate effect, leaked payload, or nonzero cleanup status is failure.

- [ ] **Step 5: Prove the scenario-only interface without transferring lifecycle ownership.**

Start the standalone topology through the script's test harness, export its connection manifest, and create a private mode-`0700` delegated-action stub at the manifest path that records only the six fixed action requests and returns the exact topology-bound JSON receipt schema. Then invoke:

```bash
./infra/platform/production/run-workers-scheduler-smoke.sh \
  --connection-manifest "$CONNECTION_MANIFEST" \
  --evidence-dir "$SCENARIO_EVIDENCE" \
  --no-lifecycle
```

Expected: exit 0 with `PASS: production worker/scheduler scenarios on existing topology`; the original topology/run ID remains live and healthy after return; no second Compose project, network, container, or volume exists; evidence records the manifest SHA, topology/run ID, and every delegated-action receipt. Shell regressions stub Compose and prove this mode invokes no lifecycle or mutation subcommand directly, rejects a symlink/wrong mode/wrong owner/path or inode change, rejects malformed/missing/mismatched receipts, and emits only the fixed action vocabulary. The outer test harness performs the only teardown and proves cleanup.

- [ ] **Step 6: Run targeted API regression tests.**

Run:

```bash
cd apps/api && php artisan test \
  tests/Feature/ProductionSchedulerWiringTest.php \
  tests/Feature/TechnicalAlertDeliveryTest.php \
  Modules/WorkRecords/Infrastructure/Outbox/Relay/Tests/RedisOutboxRelayTest.php \
  Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Tests/NotificationsStreamWorkerTest.php \
  Modules/Organization/Tests/TemporaryAssignmentTest.php \
  Modules/Organization/Tests/TemporaryAssignmentMySqlConcurrencyTest.php \
  Modules/PlatformSettings/Tests/BackupOperationsHandlerTest.php
```

Expected: PASS, zero failures, zero skipped MySQL concurrency scenarios.

### Task 6: Publish the post-P02 merged-topology smoke handoff

**Files:** Downstream P02-owned creation: `infra/platform/production/verify-workload-topology.sh`; invoke P01 `infra/platform/production/run-workers-scheduler-smoke.sh --connection-manifest … --evidence-dir … --no-lifecycle` and P02's equivalent scenario-only Documents interface without editing either owner's internals.

**Interfaces:** P01 publishes the exact scenario-only argv, fixed delegated-action vocabulary and argv, receipt JSON schema/validation, and evidence contract from Task 5; P02 owns the merged adapter after its topology has merged. P07 alone owns the bounded live topology lifecycle, exported connection manifest, and private same-project `delegated_action_command`; P08 invokes the adapter as a dependent gate inside that live lifecycle and consumes the resulting final-SHA manifest. This downstream handoff does not block P01 completion.

- [ ] **Step 1: Record the adapter packet when P01 releases the topology token.**

Record owner `P02`, source P01 completion manifest/SHA, paths above, required final Compose file order `compose.yaml`, `compose.test.yaml`, `compose.documents-smoke.yaml`, and the requirement for one P07 topology/run ID and one P07 cleanup trap. Expected: packet status is `requested`; P01's standalone overlay remains child evidence only and is not registered as P08's final smoke.

- [ ] **Step 2: After P02 merges, implement the dependent merged adapter under P02 ownership.**

The adapter accepts `--commit`, `--connection-manifest`, and `--evidence-dir`; it must reject a missing/mismatched live topology ID, final SHA, Compose digest, worker/scheduler endpoint, P02 dependency endpoint, or invalid P07 `delegated_action_command` ownership/path/mode. It must never issue Compose lifecycle/mutation commands or create its own project. It invokes P01 exactly as `run-workers-scheduler-smoke.sh --connection-manifest "$P07_CONNECTION_MANIFEST" --evidence-dir "$EVIDENCE_DIR/p01" --no-lifecycle`, then invokes P02's scenario-only verifier against the same manifest and a sibling evidence directory. All disruptive actions use the manifest's private delegated command and exact receipt schema. P07 invokes the adapter between its exported-manifest and journey phases:

```bash
./infra/platform/production/verify-workload-topology.sh \
  --commit "$FINAL_SHA" \
  --connection-manifest "$P07_CONNECTION_MANIFEST" \
  --evidence-dir "artifacts/program-closure/$FINAL_SHA/workload-topology"
```

Expected: within P07's one bounded `start → export connection manifest → run dependent gates → run P07 journeys → stop with trap → prove cleanup` lifecycle, the adapter exits 0 and prints `PASS: merged worker/scheduler and documents workload topology`. Its manifest names the live topology/run ID, `$FINAL_SHA`, P01 and P02 scenario sentinels, resolved Compose digest, and image IDs; P07's parent manifest supplies cleanup proof after stop. Any child lifecycle command, second project, overlay startup, skip, stale SHA, topology mismatch, or child evidence with a different topology/run ID fails. Retain the packet transition `requested → granted → merged → released` and manifest path for P07/P08. P08 must run only this dependent merged command for the final workload smoke.

- [ ] **Step 3: Apply the status gate.**

Expected: failure returns the adapter to P02 and blocks P07/P08 merged-topology acceptance, but never reopens or prevents immutable P01 `completed` evidence unless the failure identifies a P01 contract defect. P08 reruns the adapter on final HEAD inside P07's same live topology rather than accepting disjoint P01/P02 smoke outputs.

## 10. Failure, Retry, Idempotency, Concurrency, and Authorization Behavior

- **Outbox publish failure:** increment attempt according to owner semantics, leave `published_at` null, return nonzero, remove readiness, and retry after backoff. Never mark before successful `XADD`.
- **Consumer failure before attempt three:** leave the message pending/unacknowledged. On reclaim after 60 seconds, re-run the idempotent owner handler.
- **Exhausted consumer failure:** persist/publish one idempotent DLQ record before acknowledging. If DLQ persistence fails, do not acknowledge.
- **Partial worker-cycle failure:** run remaining independent lanes, report combined failure, and refresh no marker. Successful lanes may commit; their idempotency prevents duplicate effects on retry.
- **Platform operation failure:** rely on owner request/claim/recovery semantics and bounded `dispatch_attempts`; never run backup/restore shell commands directly from P01.
- **Scheduler overlap:** `onOneServer` plus `withoutOverlapping(10)` prevents concurrent cycles. Organization's transaction/CAS/outbox remains the final duplicate guard.
- **Crash after effect but before acknowledgement:** reclaim the pending message; preserved event ID and owner idempotency collapse the replay.
- **Restart:** marker starts absent. The service is not healthy until one complete cycle succeeds after restart.
- **Signal:** readiness is removed before forwarding TERM. No new child begins. A half-completed command relies on its owner transaction/idempotency contract; the shell never compensates with direct SQL.
- **Configuration error:** exit 2 before launching PHP. Compose restart and unhealthy state make the defect visible.
- **Authorization:** no public endpoint is introduced. Existing scheduled system subject and owner handlers retain their domain authorization/audit rules. P01 never bypasses capability checks on user-triggered HTTP operations.
- **Privacy:** logs and readiness expose no payload, actor, patient/person, token, secret, access context, SQL, or exception detail. Correlation IDs remain inside owner event/audit data unless an already-safe command count log includes one.

## 11. Targeted Verification Commands

Run these only during implementation/verification, not while drafting this plan:

```bash
sh apps/api/docker/tests/worker-loop-test.sh
sh apps/api/docker/tests/scheduler-loop-test.sh
python3 -m unittest scripts.tests.test_validate_production_workloads -v
python3 scripts/validate-production-workloads.py
cd apps/api && php artisan schedule:list
cd apps/api && php artisan test tests/Feature/ProductionSchedulerWiringTest.php
./infra/platform/production/run-workers-scheduler-smoke.sh
```

Then run the exact regression suite in Task 5 Step 5. Expected outcomes are stated beside each task. No Make target or workflow is added by P01. P01 completion publishes two explicit packets:

1. `PROD-MERGED-SMOKE` to P02/P07/P08: consume Task 6's `infra/platform/production/verify-workload-topology.sh --commit "$FINAL_SHA" --connection-manifest "$P07_CONNECTION_MANIFEST" --evidence-dir "artifacts/program-closure/$FINAL_SHA/workload-topology"` after P02 merges, inside P07's already-live topology; never register the standalone P01 overlay smoke as P08's final topology gate.
2. `PRODUCTION-POLICY` to P08: owner `P08`, conditional grant `ARCHITECTURE-CLOSURE:T13-HANDOFF`, exact path `scripts/production_bundle_policy.py`, and failing regression argv `python3 scripts/production_bundle_policy.py` (equivalently `make validate-production-bundle`). Before P08 edits, retain exit code nonzero and `ERROR [bundled_state_service] services.scheduler`; the source defect is that `scheduler` is in `FORBIDDEN_SERVICES` and absent from `REQUIRED_SERVICES`, `HEALTHCHECK_SERVICES`, `HARDENED_SERVICES`, `EXPECTED_NETWORKS`, and `_valid_runtime_image`. After the token is granted, P08 first reproduces that regression, updates the single policy implementation to require and validate the sole scheduler service, reruns the same argv expecting exit 0 and its existing stdout `Direct-VPS Dockerfiles and Compose policy validation passed.`, and records `requested → granted → merged → released` with before/after outputs. The packet does not authorize changing that success string. This packet is an owned red-to-green regression, not an unexplained P01 gate and not a reason to hold P01 below `completed`.

Manual production-like inspection after smoke startup:

```bash
docker compose --project-name "$PROJECT" \
  --file infra/platform/production/compose.yaml \
  --file infra/platform/production/compose.workloads.test.yaml ps worker scheduler
docker compose --project-name "$PROJECT" \
  --file infra/platform/production/compose.yaml \
  --file infra/platform/production/compose.workloads.test.yaml exec -T worker /usr/local/bin/worker-loop healthcheck
docker compose --project-name "$PROJECT" \
  --file infra/platform/production/compose.yaml \
  --file infra/platform/production/compose.workloads.test.yaml exec -T scheduler /usr/local/bin/scheduler-loop healthcheck
```

Expected: both services show `healthy`; both explicit probes exit 0. This proves cycle freshness, not only container liveness.

## 12. Shared-File Integration Token Requirements

1. Before editing, acquire the P01 production-topology token and verify no current architecture-closure task is editing `routes/console.php`.
2. P01 has exclusive ownership of `worker-loop.sh`, `scheduler-loop.sh`, and `routes/console.php` for this plan.
3. `PROD-WORKLOAD-REGISTRY` is permanently serialized under P01 ownership and covers `apps/api/docker/worker-loop.sh` plus `apps/api/docker/tests/worker-loop-test.sh` as one unit. Its first downstream request is M03's atomic `shared:relay-collaboration` + `notifications:consume-collaboration` packet; apply only the complete §5.2 packet and retain request/grant/merge/release evidence.
4. P01 receives the first token for base `infra/platform/production/compose.yaml` and `.env.example`. It releases both together only after the standalone child smoke passes and evidence is retained.
5. P02 receives the next topology/env token. P02 may add validated service entries and owns the post-merge `verify-workload-topology.sh` adapter, but it must not create a second competing topology or edit P01 loop scripts.
6. P03 follows P02 and alone owns selected deployment/rollback scripts. P01 hands it the quiesce/drain/recovery contract in §8 rather than modifying those scripts.
7. P07 owns the bounded `start → export connection manifest → run dependent gates → run P07 journeys → stop with trap → prove cleanup` final lifecycle. P08 owns final Make/workflow integration and, only under `PRODUCTION-POLICY` after `ARCHITECTURE-CLOSURE:T13-HANDOFF`, `scripts/production_bundle_policy.py`; P08 invokes the dependent merged Task 6 smoke inside P07's live topology rather than starting disjoint child overlays.
8. P01-created `compose.workloads.test.yaml` and `validate-production-workloads.py` remain narrow child inputs; creating them grants no ownership of existing P02/P07/P08 surfaces.
9. If a concurrent owner changes a shared file after P01 read it, stop, re-read, and reacquire the serialized token; never overwrite or mechanically merge unseen work.

## 13. Rollback Procedure

Rollback is workload-first and data-preserving:

1. Stop `scheduler`, then stop external event-producing ingress or place the API in the approved maintenance mode, then run the bounded worker drain from §8, and finally stop `worker`. Do not call the system quiesced while the API can still create new outbox rows.
2. Capture service logs, marker state, unpublished outbox counts, operation request states, Redis stream lengths, consumer-group pending summaries, and DLQ state.
3. Revert the P01 topology/env, narrow validator/overlay, and loop/schedule changes as one coherent unit. Do not retain new healthchecks against old scripts or old Compose commands against new modes.
4. Start `migrate` (no P01 schema rollback exists), then `api`, then the previously known worker topology.
5. Confirm no MySQL outbox row, platform operation request, Redis pending entry, or DLQ record was deleted. Drain through the known-good bounded commands.
6. If published-but-unconsumed IDs exist, follow the owner-specific, event-ID-preserving recovery decision from §8. Never globally clear `published_at`, `XACK`, `XDEL`, `DEL`, or purge DLQ to make health green.
7. Record the rollback reason and evidence. Return P01 to `blocked` if the defect depends on T6/T10 or an ownership decision; otherwise return it to `in_progress` for correction.

Because P01 adds no migration, database rollback commands are neither required nor permitted.

## 14. Exit Criteria and Required Retained Evidence

P01 may enter `completed` only when every criterion is true on one recorded, user-authorized commit:

- T6/T10 start-gate evidence is recorded and still valid.
- All focused shell, PHP, policy, Compose, and production-like smoke checks pass with no skipped critical scenario.
- `worker` runs every initial registry command in the tested order; future additions are possible only through `PROD-WORKLOAD-REGISTRY`, with M03's atomic Collaboration relay+consumer pair retained as the first request.
- The sole production `scheduler` service runs the exact registered expiration schedule; validator regressions prove API replicas and all other production services never invoke `schedule:run` or an equivalent scheduler.
- Failure/backoff, stale readiness, Redis outage/recovery, stale-pending reclaim, DLQ, overlap, restart, and TERM scenarios are demonstrated.
- No duplicate domain/notification effect is observed after retry or restart.
- The P01 workload validator requires both worker and the sole scheduler; its retained rule/evidence mapping is handed to P08 without editing P08's composed production-bundle policy.
- The explicit `PRODUCTION-POLICY` packet names `scripts/production_bundle_policy.py`, reproduces the scheduler-policy regression and expected failure, and assigns its post-T13 red-to-green correction to P08; P01 has no unexplained known-red completion gate.
- No public API/generated-client/module ownership drift is introduced.
- P01 releases the base topology/env token to P02 and sends the recovery contract to P03.
- P01 publishes immutable completion evidence independently of downstream acceptance. P02 records the Task 6 dependent merged-topology adapter packet, P07 later runs it inside one bounded live lifecycle and proves cleanup, and P08 records that final-SHA command rather than disjoint P01/P02 smoke commands.

Retain under `docs/architecture/evidence/P01/<commit-sha>/` after commit authorization:

```text
manifest.json
commands/*.stdout.log
commands/*.stderr.log
compose/resolved.yaml
compose/images.json
scenarios/pre-state.json
scenarios/post-state.json
scenarios/redis-recovery.json
scenarios/signal-recovery.json
scenarios/scheduler-overlap.json
```

`manifest.json` schema:

```json
{
  "plan_id": "P01",
  "commit_sha": "40 lowercase hexadecimal characters",
  "verified_at_utc": "RFC3339 UTC timestamp",
  "commands": [
    {
      "argv": ["exact", "argument", "vector"],
      "exit_code": 0,
      "started_at_utc": "RFC3339 UTC timestamp",
      "finished_at_utc": "RFC3339 UTC timestamp",
      "stdout_path": "relative evidence path",
      "stderr_path": "relative evidence path"
    }
  ],
  "scenarios": [
    {
      "id": "stable scenario identifier",
      "outcome": "passed",
      "evidence_paths": ["relative evidence path"]
    }
  ],
  "compose_config_sha256": "64 lowercase hexadecimal characters",
  "image_ids": {"api": "sha256 digest", "worker": "sha256 digest", "scheduler": "sha256 digest"}
}
```

The smoke runner must redact secrets and payloads before writing evidence. A missing file, stale SHA, skipped scenario, or output from a different commit blocks completion.

## 15. Status Transition Rules

- `blocked → ready`: T6 and T10 are complete with explicit handoff evidence, and the P01 production-topology token is available.
- `ready → in_progress`: the first failing test is added after revalidating the source inventory.
- `in_progress → blocked`: a dependency reopens, shared token is lost, a required production-like prerequisite is unavailable, or an owner contract cannot be reconciled without crossing boundaries. Record the exact blocker/evidence.
- `in_progress → verification`: all implementation tasks are complete; focused tests are green; the candidate commit is fixed for verification.
- `verification → in_progress`: any targeted command, smoke scenario, privacy assertion, or token handoff fails. Fix the source defect; do not weaken the gate.
- `verification → completed`: every P01-owned §14 criterion passes on one recorded commit, the user has authorized recording that commit, `implementation_commit` and `last_verified_commit` equal its SHA, and the `PROD-WORKLOAD-REGISTRY`, merged-smoke, recovery, and `PRODUCTION-POLICY` packets are published. P02/P03/P07/P08 acknowledgement or acceptance is downstream and must not hold an otherwise complete P01 below `completed`.
- `completed → blocked`: later evidence shows event loss, duplicate effects, stale-health false positives, signal/restart failure, or a reopened T6/T10 invariant.
- `* → superseded`: only an approved replacement plan may supersede P01; record its exact path and update the orchestration plan in the same authorized change.

No status becomes `completed` from plan text, a narrow unit test, an unrecorded working tree, or a skipped production-like scenario.
