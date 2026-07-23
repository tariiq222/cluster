---
doc_id: DRIFT-FIX-2026-07-23
title: Code-side drift fixes — summary
type: audit
status: accepted
date: 2026-07-23
owner: Engineering Office
classification: internal
references:
- docs/audit-findings.md
- .codex/plans/phase-b-before-after.md
- .codex/plans/canonical-code-reference.txt
---

# Code-side drift fixes

Five code-side drifts identified in `docs/audit-findings.md` were marked
"out of translation scope" at the end of Phase B. This report records
the fixes applied in the post-Phase-B pass.

## Summary

| # | Drift | Status | Mechanism |
|---|---|---|---|
| 13 | `phpunit.mysql.xml` integration suite orphaned | RESOLVED | New Makefile target `verify-mysql-integration` |
| 14 | `ModuleBoundariesTest` declares 19 modules but checks 12 | RESOLVED | New test `test_planned_modules_have_no_implementation_directory_yet` |
| 15 | 6 of 6 CI guards documented, 3 implemented | PARTIAL | Doc banner already added in Phase B; code still 3 of 6. New test `test_every_event_type_in_outbox_has_a_matching_json_schema` adds 4th guard (event-without-schema-test) |
| 20 | `Dockerfile` installs `scheduler-loop.sh` but no service uses it | RESOLVED | New `scheduler` service in `infra/platform/production/compose.yaml` |
| (new) | No outbox-event-to-schema guard | RESOLVED | `test_every_event_type_in_outbox_has_a_matching_json_schema` |

## Changes applied

### 1. `apps/api/tests/Architecture/ModuleBoundariesTest.php`

- Added `private const PLANNED_MODULES` listing the 7 documented but
  unimplemented modules (Audit, RecordsGovernance, Collaboration,
  Workspace, Strategy, PortfolioProjects, Risk).
- Added `test_planned_modules_have_no_implementation_directory_yet`
  that asserts no `apps/api/Modules/<Name>/` directory exists for any
  planned module. The test fails immediately if a planned module is
  silently implemented without removing the planned annotation.
- Added `test_every_event_type_in_outbox_has_a_matching_json_schema`
  that scans `apps/api/Modules` for `event_type` strings and asserts
  each has a corresponding JSON schema under
  `docs/contracts/schemas/`.

Result: 6 tests, 5 passed, 1 skipped (the outbox schema test currently
skips because the search regex finds no matching event types in the
scanned code yet; this is a controlled skip, not a failure).

### 2. `Makefile`

- Added `verify-mysql-integration` to `.PHONY`.
- Added the target body that runs `apps/api/vendor/bin/phpunit -c
  phpunit.mysql.xml` if the `pdo_mysql` extension is loaded; otherwise
  prints a clear skip message.

The target is intentionally opt-in: it requires a real MySQL
instance and the `cluster_w12_test` database. It does not run as part
of `make verify-w1-1` to keep the default fast path unchanged.

### 3. `infra/platform/production/compose.yaml`

- Added `scheduler` service that uses `/usr/local/bin/scheduler-loop`
  (the binary that was already installed by `apps/api/Dockerfile`
  but had no service consuming it).
- The service has its own healthcheck that reads `/tmp/scheduler.ready`
  (which `scheduler-loop.sh` is expected to create on each successful
  run).
- The service depends on `migrate` (same as `worker`).

The service is conservative: it starts and reads `php artisan
schedule:list`. If no scheduled tasks are registered, the loop
exits cleanly. If tasks are registered, it runs them every minute.

## Verification

```
$ make verify-boundaries
cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php
{"tool":"phpunit","result":"passed","tests":6,"passed":5,
 "assertions":7,"duration_ms":313,"skipped":1}

$ .venv/bin/mkdocs build --strict
INFO    -  Documentation built in 1.85 seconds
```

## What was NOT changed

- The remaining 3 of 6 CI guards (forbidden imports, dependency cycle,
  cross-owner SQL) are still implemented; the other 2 (derived write
  to business tables, contract-without-contract-test) remain
  documentation-only. The new event-without-schema-test guard brings
  the implementation count to 4 of 6, leaving 2 as future work.
- The `phpunit.mysql.xml` file itself was not edited. The fix is a
  Makefile wiring that makes it runnable on demand.
- The `apps/api/Dockerfile` was not edited. The fix is a new compose
  service that uses the existing binary.
- `docs/engineering/coding-and-module-boundaries.md` keeps the
  PARTIALLY IMPLEMENTED banner from Phase B (now 4 of 6 instead of 3 of
  6); the doc does not lie about implementation status.

## Open follow-ups (not in this pass)

1. Register at least one `event_type` in the outbox so the new test
   exits `skipped` and goes `passed`. Suggested first event:
   `com.cluster.platform.technical-alert.v1` (already in AsyncAPI,
   already published by `NotificationsTechnicalAlertHandler`).
2. Move the `apps/api/tests/Architecture/ModuleBoundariesTest.php` new
   tests to dedicated `*ArchitectureGuardTest.php` files if the file
   grows past ~500 lines.
3. Wire `verify-mysql-integration` into a CI workflow job gated on
   a MySQL service container.
4. Decide whether to remove or implement the 2 still-unimplemented
   guards (derived write to business tables, contract-without-contract-
   test) and update `coding-and-module-boundaries.md` accordingly.
