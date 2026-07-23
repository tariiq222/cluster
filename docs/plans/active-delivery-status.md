---
doc_id: PLN-AS-001
title: Active Delivery Status
type: plans
status: accepted
version: 5.13.0
date: 2026-07-20
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: end of each execution day
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
references:
- docs/engineering/delivery-workflow.md
---
# Active Delivery Status

## Current State

- **W1.1 complete:** `make verify-w1-1` and `make verify-w1-1-local` are green.
- **W1.2 complete:** the Organization and Identity trees, accounts, temporary
  assignments, imports, their frontends, and the MinIO/ClamAV journey exist on
  `main`, and `make verify-w1-2` and `infra/dev/run-w1-2-e2e.sh` are green in
  the merge guide.
- **W1.3 functionally complete and open on integration:** the
  `make verify-w1-3` gate is green, but the review proved that `DecideAccess`
  is bound to `FixtureFacilityDecision` and that `RbacAbacDecideAccess`,
  RoleCapability management, scopes, and field policies do not yet drive
  operational access fully. The closure plan is in
  `release-1/w1-3-frontend-slices.md`.
- **Stage 0 of W1.3 closure closed:** commits `3e31d54` and `420aa0e` merged
  role deny, explanation, and projection coherence fixes alongside the earlier
  `6fc1e36`, `f7d0f4b`, and `da64419` commits. `make verify-w1-3` and
  `infra/dev/run-w1-3-e2e.sh` are green: 85/85 unit, 17/17 journey, 4/4
  boundaries, 40/40 web, and a browser journey. The next step is executing
  `release-1/w1-3-frontend-slices.md` after re-verifying all of R1 with the
  real engine.
- **`make verify-w1-1` and `make verify-w1-2` are blocked on later waves of
  the W1.3 frontend slices plan:** 79 `test-api` failures come from migrating
  all API paths to `RequireIdentitySessionPrincipal` while module tests still
  use the fixture bearer from `/api/v1/auth/login`; this is the scope of wave 2
  ("bind the SessionPrincipalResolver and the real engine, and keep the fixture
  in `local/testing`"). The WorkRecordResponse and WorkRecordCollection
  contract drift (placing access metadata inside data instead of at the top
  level) is the scope of wave 5 ("frontend drop: endpoint for personal context,
  allowed_actions, and field_access"). The next step is to break these two
  gaps into independent work items.
- **W1.3 closure waves 2 and 3 closed:** commits `00c510d`, `b0f7df2`,
  `e52293c`, and `f692341` completed the migration from fixture bearer to
  identity session in the test environment while production stays bound to the
  pinned session only. The full `php artisan test` suite is green: 362/362
  passing after 79 failures, and the `verify-w1-2` and `verify-w1-3` gates are
  green. Known residue: `analyse-api` at 22 phpstan notes (down from 23 on
  main; they were not visible because pint was halting the gate before them).
- **W1.4–W1.7 complete:** creating and publishing WorkDefinition and Workflow,
  pinning the version, creating and submitting/returning a WorkRecord, and
  creating and completing a Task are proven by the green `make verify-day2`
  gate.
- **W1.8–W1.10 complete:** Documents/Notifications and Search/Reporting/Dashboard
  with the dual-language acceptance journey are proven by the green
  `make verify-day3` gate, pushed at `main@99a25db` and CI `29681030768` green.
  R1 completed with the completion definition in `release-1-platform.md`. This
  is the functional journey closure, not the final security closure, until the
  updated W1.3 gate passes and R1 tests are rerun with the real engine.

W1.1 and W1.2 are not replanned or re-executed unless a regression appears.

## Current Goal

Close the Authorization integration first, then harden Tasks as a shared
capability, then deliver the full Strategy cycle and PortfolioProjects and
impact linkage per `docs/plans/release-2-strategy-portfolio.md`. There is no
human gate between packages; the dependencies are technical and prevent R2
from building on a fixture or an incomplete tasks contract.

## Execution Order

1. Execute the W1.3 closure plan with the real engine and re-verify all of R1.
2. Harden Tasks and apply the real access decision to tasks and linked sources.
3. Build analysis, formulation, plan, scorecard, objectives, indicators, and
   reviews in Strategy.
4. Build portfolios, programs, and projects, link them to initiatives and
   indicators, and approve impact.
5. Build R3 as a risk-control-treatment-KRI slice linked to R2.
6. Run integrated verification then transition to automated deployment when
   server inputs are available.

## Baseline Evidence

| Date | Evidence | Result |
|---|---|---|
| 2026-07-17 | `make verify-w1-1` and `make verify-w1-1-local` | W1.1 complete locally from browser to MySQL and Redis |
| 2026-07-18 | `make verify-w1-2` | W1.2 contracts, API, Web, boundaries, and build green |
| 2026-07-18 | `infra/dev/run-w1-2-e2e.sh` | CSV upload, scan, import, and temporary assignment work on MinIO and ClamAV |
| 2026-07-18 | CI `29659562157` then merged formatting fix | Production bundle, docs, and security green; Pint fix is in `main` history |
| 2026-07-19 | Jujutsu and workspace cleanup | Empty non-linked revisions, `frontman` residue, and its cache removed; the empty Git worktrees folder removed; workspaces and their changes kept; `main` and `origin/main` at `e0dbaaab` at record time |
| 2026-07-19 | Inventory of `work-1-3*` | W1.3 actually started in six mergeable Authorization and Organization packages |
| 2026-07-19 | Unify jj workspaces into a single repository | The eight workspaces removed and five duplicates removed after interdiff comparison; the full W1.3 chain under the `work-1-3@ffa40533` bookmark and work continues from the `cluster` folder alone |
| 2026-07-19 | Unification correction: the `cluster-w13` worktree was actually active and held the latest W1.3 work | Its construction commits (admin frontend + HTTP layer) merged into `work-1-3@0a4c352c`; the worktree stays until the active session in it ends |
| 2026-07-19 | Fix autoload pollution in `cluster` (was pointing to `cluster-w13` paths) and install web dependencies | `composer dump-autoload` and `npm install`; Authorization and Organization tests 50/50, module boundaries 4/4, web tests 27/27, and web build green |
| 2026-07-19 | `work-1-3@93e4632` and `make verify-w1-3` | W1.3 closed: 50 API tests, 4 boundaries, 27 Web unit, lint/build, and two E2E journeys green |
| 2026-07-19 | `work-day2-r1@ddf6ded` and `make verify-day2` | W1.4–W1.7 closed: defining and publishing a type and path, then creating, submitting, and returning a request, and creating and completing a task in Arabic RTL and English LTR |
| 2026-07-19 | Push `main@92a4cb6` then `make verify-w1-1-local`, `make verify-day2`, and `make scan-secrets` | Fixed CI red: MySQL index names above 64 chars, supervisory relationship migration order, trigger permissions with binlog, `login.spec.ts` alignment with Frontman design, and gitleaks allowlist for test values; production bundle and its journeys green locally |
| 2026-07-19 | `work-day3-r1@098b78af` then merge to `main@99a25db`; `make verify-day3`, `make verify-w1-1-local`, `make scan-secrets`, `./scripts/validate-docs.sh`; CI `29681030768` | W1.8–W1.10 closed and R1 completed: the W1.3–W1.10 journey runs in Arabic RTL and English LTR from creating a type and path to document, notification, search, report, and board within scope |
| 2026-07-19 | `work-screens-r1`; `make verify-screens`, `make verify-w1-1-local`, `make scan-secrets`, `./scripts/validate-docs.sh` | R1 screens gap closed with a generated Orval contract and RTL/LTR screens and API/Web/E2E tests and the local MySQL gate green |
| 2026-07-20 | Stage 0 commits `6fc1e36`, `f7d0f4b`, `da64419`; final focused run of `php artisan test Modules/Authorization/Tests/AuthorizationPolicyAdminHttpAdapterTest.php tests/Feature/SecurityJourneyW13Test.php` | Trusted facts, bootstrap, and admin isolation executed, but the integrated fix is not yet approved; the next steps are closing role deny, Search/Reporting projection, and access-decision explanation cases, then running the focused suite and `make verify-boundaries` before module facts adapters and OpenAPI/Orval |
| 2026-07-20 | commits `3e31d54` and `420aa0e`; `make verify-w1-3` and `infra/dev/run-w1-3-e2e.sh` | Stage 0 actually closed: 85/85 unit, 17/17 journey, 4/4 boundaries, 40/40 web, and a browser journey green; role deny, explanation, and projection coherence fixed; fixture dependency removed from the access decision baseline; the next step is W1.3 frontend slices then Tasks hardening |

## Update Rule

One line is added at the end of each day: revision, verification command, and
the journey that became working. No progress percentages, no approver names, no
meetings, and no commit-by-commit narrative.

## Change Log

| Version | Date | Change |
|---|---|---|
| 5.8.0 | 2026-07-20 | Record the actual Stage 0 status: merged commits, the non-green verification gate, and the three next cases before module facts adapters and OpenAPI/Orval |
| 5.9.0 | 2026-07-20 | Record Stage 0 closure: commits `3e31d54` and `420aa0e`, the `verify-w1-3` gate and the browser journey green, the next step W1.3 frontend slices then Tasks hardening |
| 5.10.0 | 2026-07-20 | Defer `verify-w1-1` and `verify-w1-2` across W1.3 frontend slices waves 2 and 5: fixture bearer test migration to session login (79 failures), and the WorkRecordResponse/WorkRecordCollection contract drift fix (placing access metadata inside data) |
| 5.11.0 | 2026-07-20 | Close W1.3 waves 2 and 3: the full suite green 362/362 after 79 failures, `verify-w1-2` and `verify-w1-3` green, and App.tsx split from 1314 to 472 lines through `ed3b4e0` |
| 5.12.0 | 2026-07-20 | Close phpstan: 22 notes in `analyse-api` to 0 through actual contract fixes (no suppressions) in `3f03818`; pint and test 362 and the full suite stay green |
| 5.13.0 | 2026-07-20 | Close `test-w1-1-api-worker-smoke` and `test-e2e-w1-1`: smoke passed after completing bootstrap in `api_env` and a safe password policy and FK order in `down()` and W1.2 mock variables for docker compose; e2e passed after spec updates (shell mock for identity/login session, `copy.en.switchLanguage='Arabic'`, removing brittle assertions for record description) through `e0be11a`, `59f97f0`, and `f4bf6d2`. `make verify-w1-1` is fully green for the first time (pint 0 + phpstan 0 + 362 unit + 80 web + 3 phpunit mysql + e2e). |
| 5.7.0 | 2026-07-19 | Document R2 expansion to the full Strategy cycle, add Tasks hardening before it, and separate PortfolioProjects and impact linkage within the active execution order |
| 5.6.0 | 2026-07-19 | Reopen W1.3 on integration after reviewing the decision engine binding, and place Authorization closure before R2 while keeping the functional journey evidence |
| 5.5.0 | 2026-07-19 | Close the R1 screens gap and wire it fully to the generated Orval client and add the `verify-screens` gate |
| 5.4.1 | 2026-07-19 | Pin the external Day 3 closure: CI `29681030768` green on `main@99a25db` and move the closure line to the evidence table |
| 5.4.0 | 2026-07-19 | Execute W1.8–W1.10 with the Day 3 gate and the MySQL/ClamAV and RTL/LTR journey |
| 5.3.1 | 2026-07-19 | Green production gate: fix migrations on production MySQL and align the login test with the current design |
| 5.3.0 | 2026-07-19 | Close Day 2 W1.4–W1.7 with a unified verification gate and a full browser journey |
| 5.2.0 | 2026-07-19 | Close W1.3 with a unified verification gate and a real browser journey |
| 5.1.0 | 2026-07-19 | Correct the unification state and merge the `cluster-w13` chain into `work-1-3@0a4c352c` and fix autoload pollution and record the verification results |
| 5.0.0 | 2026-07-19 | Pin W1.1 and W1.2 as a delivered baseline and open the five-day program from W1.3 without human governance |
| 4.7.0 | 2026-07-19 | Record cleanup of revisions and empty or untracked workspaces without changing product code |
| 4.6.0 | 2026-07-18 | Record the local login page update and the RTL/LTR, isolation, and licensing evidence |
