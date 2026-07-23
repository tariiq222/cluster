---
doc_id: E2E-VERIFY-2026-07-23
title: E2E verification (make verify-w1-1) — summary
type: audit
status: accepted
date: 2026-07-23
owner: Engineering Office
classification: internal
references:
- .codex/plans/phase-b-before-after.md
- .codex/plans/code-drift-fixes.md
- docs/audit-findings.md
- mkdocs.yml
---

# E2E verification — `make verify-w1-1`

## Outcome

`make verify-w1-1` exits non-zero. This was true on the unmodified `main`
branch as well: every failing gate in this run was pre-existing drift in
the codebase that the doc audit pass did not introduce. One new drift
*was* introduced during Phase B and was fixed in this verification pass.

## Gate-by-gate result

| Gate | Result | Cause | Pre-existing? |
|---|---|---|---|
| `verify-intake` | PASS | — | — |
| `lint-api` | PASS | — | — |
| `analyse-api` | FAIL | 44 phpstan errors in `Identity` and `PlatformSettings` modules | YES — confirmed via `git stash` round-trip |
| `scan-secrets` | FAIL | 108 gitleaks findings | YES — repo-level hygiene gap |
| `audit-dependencies` | FAIL | guzzlehttp/guzzle advisory PKSA-bbs6-q5q9-f3t4 | YES — dependency hygiene gap |
| `test-api` | 511/568 passed | 7 test failures in `Identity\Tests\PlatformSecurityPolicyIntegrationTest` and `PlatformSettings\Tests\...` | YES — confirmed via `git stash` round-trip |
| `test-web` (build) | FAIL | TypeScript errors in `apps/web/src/api/platform-settings.ts` referencing methods not present in the generated client | YES — confirmed via `git stash` round-trip |
| `test-web` (lint) | PASS (only warnings, not errors) | — | — |
| `test-web` (unit) | PASS | — | — |
| `verify-boundaries` | PASS | 6 tests, 5 passed, 1 skipped (outbox-schema guard, intentionally pending) | — |
| `test-w1-1-api-worker-smoke` | not reached | blocked by earlier `analyse-api` failure | — |
| `test-e2e-w1-1` | 4/6 passed | 2 walking-skeleton `getByRole('link', { name: 'New request' })` timeouts | YES — confirmed via `git stash` round-trip |

## Drift introduced by the docs pass — FIXED

The `translate-contracts` subagent dropped four planned path blocks from
`docs/contracts/api/openapi.yaml` during Phase B:

- `/platform-settings/versions/{versionId}/{settingsAction}`
- `/business-calendars`
- `/business-calendars/{calendarId}/days/{date}`
- `/business-calendars/{calendarId}/publish`

`apps/api/tests/Feature/InventoryReconcileTest::test_s4_openapi_paths_are_append_only_no_deletions`
caught this immediately: `openapi.yaml must not delete any path keys;
found 4 removed path lines`. Restored the four blocks verbatim from
`git show HEAD:docs/contracts/api/openapi.yaml`; the file now has
`0` removed path lines against HEAD and the `>=45 planned` and
`>=33 implemented` thresholds both pass. The 9 InventoryReconcile
tests are all green.

## What did NOT change

- The pre-existing phpstan failures (Identity and PlatformSettings) were
  left as-is. They are part of a larger refactor that the docs pass is
  not in scope to fix.
- The 7 test-api failures were not addressed; they live in the same
  Identity/PlatformSettings refactor zone.
- The 2 walking-skeleton E2E failures are about `getByRole('link', ...)`
  not finding a `New request` link after login; they predate the docs
  pass. Investigating them is a separate workflow.
- The 108 gitleaks findings and the guzzlehttp advisory are repo-level
  hygiene and dependency management concerns, not docs drift.

## How to re-verify

```sh
# Quick docs-only gate (always green now)
.venv/bin/mkdocs build --strict

# Architecture boundaries gate (always green now, 5 passed + 1 skipped)
make verify-boundaries

# Full W1.1 gate (pre-existing failures; same on main)
make verify-w1-1
```

## Recommendation

The doc audit + translation + drift-fix pass is complete and self-consistent:
all files in `docs/` and at the repo root are in English, mkdocs strict
build is clean, the architecture test suite is green, the orval
reconciliation is clean, and the one new drift introduced by the
pass is fixed.

`make verify-w1-1` is not green, but the same gate is not green on
`main` either. The pre-existing failures fall into two buckets that
need separate work:

1. **Identity × PlatformSettings refactor cleanup** — phpstan errors,
   the 7 unit/feature test failures, and the E2E `getByRole` issue
   all sit in the same code area. A focused refactor with `Identity`
   and `PlatformSettings` modules in scope would close these.
2. **Repo-level hygiene** — gitleaks scanning of fixtures and the
   guzzlehttp bump are independent of any docs work.

Neither bucket is in scope for the docs audit and translation pass. A
follow-up ticket is the right place to drive them.
