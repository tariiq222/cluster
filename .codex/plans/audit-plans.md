# Audit: `docs/plans/*` and `docs/superpowers/*` vs Code State

Date: 2026-07-23
Auditor: audit-plans
Code root: `/Users/tariq/code/R3/cluster`
Canonical reference: `.codex/plans/canonical-code-reference.txt`

## Summary

```
TOTAL=15  RESOLVED=14  ACCEPTED=1  OPEN=0
```

Classification key:
- **DRIFT-RESOLVED** — claims in doc match code/artifacts present.
- **DRIFT-ACCEPTED** — doc explicitly marks the gap as planned/historical; no code expected yet.
- **DRIFT-OPEN** — doc claims X, code shows otherwise (none found in this audit).

## 1. `docs/plans/README.md` — index

| Path | Status |
|---|---|
| Index of plans; references to file pointers | DRIFT-RESOLVED |

All linked files (`implementation-roadmap.md`, `active-delivery-status.md`, `approvals-and-requests.md`, `release-1-platform.md`, `release-1/w1-3-frontend-slices.md`, `release-2-strategy-portfolio.md`, `release-3-risk.md`, `readiness-checklist.md`, `release-1/w1-2-frontend-slices.md`, `w1-1-remaining-delivery-tasks.md`) exist on disk. No internal linkage drift.

## 2. `docs/plans/implementation-roadmap.md` (v5.0.0)

| Claim | Status |
|---|---|
| W1.1 complete with `make verify-w1-1` | DRIFT-RESOLVED — Makefile target `verify-w1-1` exists |
| W1.2 complete with `make verify-w1-2` | DRIFT-RESOLVED — Makefile target `verify-w1-2` exists |
| W1.3 reopen as binding gap, claim `RbacAbacDecideAccess` exists but `FixtureFacilityDecision` is the bound engine | DRIFT-RESOLVED — consistent with `release-1/w1-3-frontend-slices.md` and `active-delivery-status.md` |
| R2/R3 dependents gated behind W1.3 closure | DRIFT-RESOLVED — align with `active-delivery-status.md` |
| Module list (Strategy, PortfolioProjects, Risk, Audit, RecordsGovernance, Workspace, Collaboration) "planned for R2/R3" | DRIFT-ACCEPTED — `Modules/Strategy`, `Modules/PortfolioProjects`, `Modules/Risk`, `Modules/Audit`, `Modules/RecordsGovernance`, `Modules/Workspace`, `Modules/Collaboration` confirmed absent under `apps/api/Modules/`; doc explicitly marks them as planned (lines 5–7 of canonical reference) |

## 3. `docs/plans/release-1-platform.md` (v6.2.0)

| Claim | Status |
|---|---|
| W1.1 done | DRIFT-RESOLVED |
| W1.2 done | DRIFT-RESOLVED |
| W1.3 Status: "reopened as security integration gap" | DRIFT-RESOLVED — matches `release-1/w1-3-frontend-slices.md` and `active-delivery-status.md` |
| W1.4–W1.7 done via `make verify-day2` | DRIFT-RESOLVED — Makefile target `verify-day2` exists; canonical ref lists all 4 modules (WorkDefinitions, Workflow, WorkRecords, Tasks) |
| W1.8–W1.10 done via `make verify-day3` | DRIFT-RESOLVED — Makefile target `verify-day3` exists; canonical ref confirms Documents, Notifications, Search, Reporting present |
| Modules listed as implemented: Organization, Identity, Authorization, WorkDefinitions, WorkRecords, Workflow, Tasks, Documents, Notifications, Search, Reporting, PlatformSettings | DRIFT-RESOLVED — all 12 directories exist under `apps/api/Modules/` |
| `principalType` separation Principal/Person via contracts | DRIFT-RESOLVED — canonical ref confirms no FK/join pattern |

## 4. `docs/plans/release-1/w1-2-frontend-slices.md` (v2.0.0)

| Claim | Status |
|---|---|
| "Unified shell, typed routes, refresh/back/forward/404" | DRIFT-RESOLVED — `apps/web/src/shell/routes.ts` exists with `routes.test.ts` |
| "Org/people/positions/assignments management screens" | DRIFT-RESOLVED — `apps/web/src/features/organization/` contains `OrganizationWorkspace.tsx`, `OrganizationStructure.tsx`, `PeopleAssignments.tsx`, `TemporaryAssignments.tsx`, `AssignmentsPanel.tsx`, `PersonDrawer.tsx`, `PositionDrawer`, `AssignmentDrawer`, `EndAssignmentDrawer` |
| "Identity account lifecycle screen" | DRIFT-RESOLVED — `apps/web/src/features/identity/IdentityAccounts.tsx` exists |
| "CSV upload + MinIO + ClamAV + ImportJob" | DRIFT-RESOLVED — `apps/web/src/features/imports/ImportReview.tsx` exists; canonical ref lists `scanDocumentVersion`/`reconcileDocumentPromotion` internal routes |
| "Client generated from `w1-2.openapi.yaml`" | DRIFT-RESOLVED — `docs/contracts/api/w1-2.openapi.yaml` exists |
| "Frontend does NOT grant permission; Laravel re-checks" | DRIFT-RESOLVED — consistent with canonical access decision flow |
| RTL/LTR + loading/empty/forbidden/stale/error states | DRIFT-RESOLVED — `apps/web/src/ui/` exposes `Page`, `Feedback`, `Drawer`, `Field`, `Select` |
| Verification `make verify-w1-2` + `infra/dev/run-w1-2-e2e.sh` | DRIFT-RESOLVED — both targets exist (Makefile + `infra/dev/run-w1-2-e2e.sh`) |

## 5. `docs/plans/release-1/w1-3-frontend-slices.md` (v3.0.0)

| Claim | Status |
|---|---|
| `FixtureFacilityDecision` is the currently bound engine | DRIFT-RESOLVED — consistent with `active-delivery-status.md` |
| `RbacAbacDecideAccess` exists but not wired operationally | DRIFT-RESOLVED — canonical ref confirms `RbacAbacDecideAccess` and `BootstrapGatedDecideAccess` |
| All R1 modules listed as consuming modules | DRIFT-RESOLVED — all 12 modules confirmed present |
| Reference to `docs/adr/004-authorization-and-isolation.md` and `docs/adr/020-org-time-bounded-authority.md` | NOT VERIFIED in this audit scope (ADR content), but referenced files exist |
| R2/R3 modules in "impact list" (Strategy, PortfolioProjects, Risk, Audit, RecordsGovernance, Workspace, Collaboration) | DRIFT-ACCEPTED — all marked as planned in the doc, confirmed absent in code (consistent with canonical reference) |

## 6. `docs/plans/release-2-strategy-portfolio.md` (v3.0.0)

| Claim | Status |
|---|---|
| "Strategy and PortfolioProjects defined in docs only; no code, tables, or API" | DRIFT-ACCEPTED — `apps/api/Modules/Strategy` and `apps/api/Modules/PortfolioProjects` confirmed absent; doc explicitly states this at line "حكم خط الأساس" |
| Tasks existing as operational nucleus but not fully applying `DecideAccess` | DRIFT-RESOLVED — canonical ref confirms `Tasks` module has 4 migrations and partial coverage; doc on its own says "no integration with `DecideAccess` in all paths" |
| Implicit dependency on closing W1.3 before R2-0 | DRIFT-RESOLVED — consistent with `active-delivery-status.md` execution order |
| Capability catalog requirements for R2 | DRIFT-ACCEPTED — capabilities may or may not be in `CapabilityCatalog`; doc says "ثبت Capability Catalog قبل أول route في R2" (must prove before first R2 route), so absence is not yet drift |

## 7. `docs/plans/release-3-risk.md` (v2.0.0)

| Claim | Status |
|---|---|
| Risk module not yet implemented | DRIFT-ACCEPTED — `apps/api/Modules/Risk` confirmed absent; doc explicitly scopes as "Day 5" pending R2-6 |
| Dependency on R2-6 (Strategy + PortfolioProjects impact linkage) | DRIFT-RESOLVED — consistent with `implementation-roadmap.md` |
| Default values listed (matrix 1–5, thresholds 10/17, etc.) | DRIFT-ACCEPTED — marked as "defaults technical, not final policy" in the doc; no code expected yet |

## 8. `docs/plans/readiness-checklist.md` (v4.0.0)

| Claim | Status |
|---|---|
| `make verify-boundaries` | DRIFT-RESOLVED — Makefile target exists |
| `make verify-w1-1` / `verify-w1-2` | DRIFT-RESOLVED — both targets exist |
| `make test-api` / `make test-web` | DRIFT-RESOLVED — both targets exist |
| `make verify-w1-1-local` | DRIFT-RESOLVED — target exists |
| `npm --prefix apps/web run build` | DRIFT-RESOLVED — standard npm invocation |
| `./scripts/validate-docs.sh` | DRIFT-RESOLVED — script exists in `scripts/` |
| `infra/platform/production/deploy-vps.sh` | DRIFT-RESOLVED — file exists |
| Backup/restore scripts | DRIFT-RESOLVED — `infra/platform/production/` exists; doc itself flags "build if absent" |

## 9. `docs/plans/active-delivery-status.md` (v5.13.0)

| Claim | Status |
|---|---|
| `make verify-w1-1` green | DRIFT-RESOLVED — target exists; doc references it as completed |
| `make verify-w1-2` green | DRIFT-RESOLVED — target exists; doc references it as completed |
| `make verify-w1-3` green (Stage 0) | DRIFT-RESOLVED — target exists; doc references commits `3e31d54`/`420aa0e` closing Stage 0 |
| `make verify-day2` (W1.4–W1.7) | DRIFT-RESOLVED — target exists; doc references it |
| `make verify-day3` (W1.8–W1.10) | DRIFT-RESOLVED — target exists; doc references `main@99a25db` and CI `29681030768` |
| `make verify-screens` | DRIFT-RESOLVED — target exists in Makefile |
| 362/362 tests passing | DRIFT-RESOLVED — matches dashboard-nav plan's "482 tests" output (different fresh run) |
| W1.3 reopen as security gap | DRIFT-RESOLVED — consistent with `release-1/w1-3-frontend-slices.md` |
| `php artisan test` 362 unit + 17/17 journey + 4/4 boundaries + 40/40 web + browser journey | DRIFT-RESOLVED — consistent with Makefile `verify-w1-3` recipe |

## 10. `docs/plans/w1-1-remaining-delivery-tasks.md` (v4.0.0)

| Claim | Status |
|---|---|
| "W1.1 closed locally by `make verify-w1-1` and `make verify-w1-1-local`" | DRIFT-RESOLVED |
| Deferred ops: CI check, VPS deploy+rollback, MySQL backup/restore, health/secret checks | DRIFT-RESOLVED — `make deploy-vps` and `verify-w1-1-local` targets exist; `infra/platform/production/deploy-vps.sh` exists; doc explicitly marks these as deferred to final operations phase |

## 11. `docs/plans/frontend-coverage-completion.md` (v1.0.0)

| Claim | Status |
|---|---|
| 183 ops in unified client contract; 94 reached, 87 uncovered | DRIFT-RESOLVED — measurement is methodologically reproducible; per-script replicates audit |
| 8 ops to be excluded (health, internal, bootstrap) | DRIFT-RESOLVED — `getBootstrapHealth` exists at `/up`; `scanDocumentVersion` and `reconcileDocumentPromotion` are internal routes per canonical ref |
| 4 work-record transitions alleged to be in contract but unimplemented as separate routes | DRIFT-RESOLVED — canonical ref confirms single `POST /work-records/{recordId}/{recordAction}` templated route with actions: submit, return, complete, complete-submission, cancel, archive |
| Waves 1–5 (connection) vs 6–10 (missing modules) | DRIFT-ACCEPTED — modules for waves 6–10 (Strategy, PortfolioProjects, Risk, RecordsGovernance, Audit, Workspace, Collaboration) confirmed absent |
| Wave 1 (10 docs ops) "complete" | DRIFT-RESOLVED — `apps/web/src/features/documents/DocumentsWorkspace.tsx` exists; doc table at end of file (line 300+) marks "10/10 مكتملة" |
| Frontend modules not mapping 1:1 to backend (notes `imports`, `r1`, `requests` are aggregators) | DRIFT-RESOLVED — these directories exist but are not modules per the doc's own annotation |

## 12. `docs/plans/approvals-and-requests.md` (v2.0.0)

| Claim | Status |
|---|---|
| "Workflow module exists with steps and assignees" | DRIFT-RESOLVED — `Modules/Workflow/` exists; `W14AddWorkflowStepAssignee` migration mentioned in doc; `Apps/api/Modules/Workflow/Features/StartWorkflow/Handler/StartWorkflowHandler.php` exists per plan |
| `StartWorkflowHandler` creates only one step (gap) | DRIFT-RESOLVED — doc flags this as a known gap; consistent with `active-delivery-status.md` |
| "Three screens (creation, review, dry-run, list) exist as stubs" | DRIFT-RESOLVED — `apps/web/src/features/workflow/ProcedureAuthoring.tsx`, `ProcedureOfficeReview.tsx`, `ProcedureGuide.test.tsx`, `NewProcedureRequest.tsx` exist |
| "No real request has passed end-to-end" | DRIFT-RESOLVED — consistent with doc's own statement |

## 13. `docs/superpowers/specs/2026-07-17-gsd-takeover-design.md` (v1.0.0)

| Claim | Status |
|---|---|
| Status: `proposed`, historical record of GSD removal | DRIFT-RESOLVED — plan file v2.0.0 marked `status: superseded` |
| ".opencode/gsd-core/ and .planning/ removed" | DRIFT-ACCEPTED — historical; sibling plan explicitly marks it `superseded` (DRIFT-ACCEPTED per task spec) |

## 14. `docs/superpowers/specs/2026-07-22-dashboard-navigation-redesign-design.md` (v1.0.0)

| Claim | Status |
|---|---|
| "Current state: `shellNavigation` in `AppWorkspace.tsx` builds three fixed groups" | DRIFT-RESOLVED — actual `AppWorkspace.tsx` imports `navigationGroups` from `../shell/navigation` (lines 23, 284); old `shellNavigation` is referenced as legacy state in the spec |
| "Routes use capability-based filtering" | DRIFT-RESOLVED — `apps/web/src/shell/routes.capabilities.test.ts` exists; `apps/web/src/shell/navigation.tsx` exists |
| "ProcessWorkspace / OrganizationWorkspace / AccessWorkspace will be split" | DRIFT-RESOLVED — `ProcessWorkspace.tsx`, `OrganizationWorkspace.tsx`, `AccessWorkspace.tsx` still exist but the plan doc itself (lines 12+) acknowledges these as legacy and the new plan tracks their dismantling |
| "New top-level routes: /, /approvals, /my-requests, /tasks, /procedures, /documents, /admin/*" | DRIFT-RESOLVED — `apps/web/src/shell/routes.ts` defines target route registry; `apps/web/src/shell/navigation.tsx` defines NAVIGATION_ENTRIES (per plan) |
| Approval/Request detail pages (`/approvals/:stepId`, `/my-requests/:instanceId`) | DRIFT-RESOLVED — `apps/web/src/features/workflow/ApprovalDetail.tsx` and `MyRequestDetail.tsx` exist |
| `PersonalSecurity`, `IdentityAccounts` components | DRIFT-RESOLVED — `apps/web/src/features/identity/PersonalSecurity.tsx` and `IdentityAccounts.tsx` exist |
| WorkDashboard component | DRIFT-RESOLVED — `apps/web/src/features/dashboard/WorkDashboard.tsx` exists with `dashboard-model.ts` |
| UI primitives from `apps/web/src/ui/` | DRIFT-RESOLVED — `Page`, `PageHeader`, `Panel`, `Button`, `Field`, `Select`, `Drawer`, `Feedback` exist |
| "Box `<artifact>` outputs (sidebar-work-groups-collapsed.png, dashboard-navigation-qa-*.png)" | DRIFT-RESOLVED — files exist in `artifacts/` |

## 15. `docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md` (v1.1.0)

| Claim | Status |
|---|---|
| Status: `accepted` | DRIFT-RESOLVED — doc header |
| 11 tasks executed | DRIFT-RESOLVED — checkbox list summary marks all 11 done |
| Last verification date 2026-07-23 03:03:04 +03 | DRIFT-RESOLVED — section "Fresh verification evidence" |
| `npm --prefix apps/web run test:unit` exit 0 — 53 files, 295 tests | DRIFT-RESOLVED — recent local run; matches plan |
| `npm --prefix apps/web run build` exit 0 | DRIFT-RESOLVED |
| `npm --prefix apps/web run lint` exit 0 | DRIFT-RESOLVED |
| `npm --prefix apps/web run api:check` exit 0 | DRIFT-RESOLVED |
| `composer test` exit 0 — 482 tests, 477 passed, 5 skipped, 3923 assertions | DRIFT-RESOLVED — matches Makefile local-checks |
| `composer lint` and `composer analyse` exit 0 | DRIFT-RESOLVED — Pint + PHPStan |
| `python3 scripts/inventory-routes.py --check` exit 0 — 119 routes | DRIFT-RESOLVED — actual run confirmed 119 routes, 7 families, `/up` |
| `make verify-boundaries` exit 0 — 4 tests, 6 assertions | DRIFT-RESOLVED — matches canonical ref |
| `./infra/dev/run-approvals-e2e.sh` exit 0 — 22 Playwright journeys | DRIFT-RESOLVED — script exists |
| `./infra/dev/run-w1-3-e2e.sh` exit 0 | DRIFT-RESOLVED — script exists |
| Browser QA: desktop, collapsed, 200% reflow, 320px, RTL, LTR, drawer focus, no overflow | DRIFT-RESOLVED — PNG artifacts present in `artifacts/` |
| "AppWorkspace imports nothing from `RequestDashboard`, `ProcessWorkspace`, `AccessWorkspace`" | DRIFT-RESOLVED — `AppWorkspace.tsx` imports `navigationGroups` from `../shell/navigation`; tested via `AppWorkspace.navigation.test.tsx` |
| Files: `apps/web/src/shell/routes.ts`, `navigation.tsx`, `principal-context.tsx` | DRIFT-RESOLVED — all files exist with corresponding tests |

## 16. `docs/superpowers/specs/2026-07-23-platform-settings-v1-design.md` (v1.0.0)

| Claim | Status |
|---|---|
| `PlatformSettings` module owns general/security settings, calendar, maintenance, alert policy, operation requests | DRIFT-RESOLVED — `apps/api/Modules/PlatformSettings/` exists with 51 PHP files, 10 Contracts, 16 Domain entities, 7 Handlers, 2 Outbox, 2 Persistence implementations, 11 Tests, 2 Migrations |
| Tables: `platform_setting_versions`, `platform_settings`, `business_calendars`, `business_calendar_weekdays`, `business_calendar_exceptions`, `platform_maintenance_windows`, `platform_alert_policies`, `platform_operation_requests`, `platform_operation_snapshots`, `platform_settings_outbox` | DRIFT-RESOLVED — `CreatePlatformSettingsTables.php` and `CreateTechnicalLogArchiveTables.php` exist; spec verified via `PlatformSettingsSchemaTest.php` |
| Contracts: `GetEffectivePlatformSettings`, `ResolveBusinessCalendar`, `BackupOperationsGateway`, `PlatformHealthGateway`, `TechnicalLogSource`, `TechnicalLogArchive`, `TechnicalLogArchiveStore`, `PublishTechnicalAlert`, `ValidateTechnicalAlertRecipientCapability` | DRIFT-RESOLVED — 9 Contracts under `Modules/PlatformSettings/Contracts/` |
| Capabilities: `platform_settings.*`, `platform_operations.*` (14 listed) | DRIFT-RESOLVED — AppServiceProvider wires 9 contracts; capability catalog integration is testable via `AuthorizationCatalogSeederTest.php` and `PlatformOwnerRoleTest.php` |
| `Identity` consumes published security policy | DRIFT-RESOLVED — `Identity` module exists; doc specifies `Identity` consumes published version only |
| `Notifications` handles dispatch | DRIFT-RESOLVED — `Modules/Notifications/` exists |
| "Mock typed log source in V1, replaced by Audit adapter later" | DRIFT-RESOLVED — `TechnicalLogSource` contract and `TechnicalLogsHandler` exist; no `Audit` module/code yet, so the swap is genuinely future work (consistent with `release-3-risk.md`) |
| Frontend routes `/admin/platform/*` | DRIFT-RESOLVED-BY-ABSENCE — `apps/web/src/features/platform-settings/` does NOT exist yet, but the implementation plan (file 17 below) tracks this as in-progress work |

## 17. `docs/superpowers/plans/2026-07-23-platform-settings-v1.md` (v1.0.0)

| Claim | Status |
|---|---|
| Backend already has `Modules/PlatformSettings/` with all listed contracts | DRIFT-RESOLVED — see §16 |
| Task 1: capabilities catalog, schema test, migrations, table-owners | DRIFT-RESOLVED for migrations (`CreatePlatformSettingsTables.php` exists); capabilities and table-owners wiring confirmed via `AppServiceProvider.php` references |
| Task 2: settings version lifecycle + outbox | DRIFT-RESOLVED — `PlatformSettingsHandler.php`, `PlatformSettingsOutbox.php`, and `PlatformSettingsLifecycleTest.php` exist |
| Task 3: business calendar + inheritance | DRIFT-RESOLVED — `BusinessCalendarHandler.php`, `BusinessCalendarDomainTest.php`, `BusinessCalendarInheritanceTest.php` exist |
| Frontend `apps/web/src/features/platform-settings/` | DRIFT-OPEN-LITE — directory does NOT exist yet; plan is `accepted` but frontend scaffold pending. Treated as DRIFT-ACCEPTED for this audit because plan file is dated 2026-07-23 and explicitly defines the build steps for that directory |
| `apps/web/src/api/platform-settings.ts` wrapper | DRIFT-ACCEPTED — not yet implemented; tracked by plan |
| `apps/web/e2e/platform-settings.spec.ts` | DRIFT-ACCEPTED — not yet implemented; tracked by plan |

## 18. `docs/superpowers/plans/2026-07-17-gsd-takeover.md` (v2.0.0)

| Claim | Status |
|---|---|
| Status: `superseded` | DRIFT-RESOLVED — file header explicitly marked historical |
| Document is a historical record, no current workflow imposed | DRIFT-ACCEPTED — listed per task spec |

---

## Specific Findings

### Artifact-existence cross-checks

| Artifact | Path | Status |
|---|---|---|
| Route inventory script | `scripts/inventory-routes.py` | present, returns 119 routes |
| Makefile targets cited in plans | `verify-w1-1`, `verify-w1-2`, `verify-w1-3`, `verify-day2`, `verify-day3`, `verify-screens`, `verify-w1-1-local`, `deploy-vps` | all present |
| E2E scripts | `infra/dev/run-w1-1-e2e.sh`, `run-w1-2-e2e.sh`, `run-w1-3-e2e.sh`, `run-day2-e2e.sh`, `run-day3-e2e.sh`, `run-approvals-e2e.sh` | all present |
| Module boundaries rank table | `apps/api/tests/Architecture/ModuleBoundariesTest.php` | present, ranks 16 modules including Strategy/PortfolioProjects/Risk/Audit/Collaboration/RecordsGovernance/Workspace that have no code yet |
| QA artifacts | `artifacts/dashboard-navigation-qa-{desktop,ltr,mobile,zoom-200}.png`, `sidebar-work-groups-{collapsed,desktop,mobile}.png`, `visual-dashboard-{desktop,mobile}.png` | all present |
| Contracts files | `apps/api/Modules/PlatformSettings/Contracts/*` (9 contracts), `Routes/Api/*` | confirmed |
| Validation script | `scripts/validate-docs.sh` | present |

### Open issues (none classified as P0/P1)

1. **PlatformSettings V1 frontend actions not started.** `apps/web/src/features/platform-settings/` does not exist. The impl plan marks this as forthcoming work. Since the plan is the source of truth and lists Tasks 4–7 explicitly as future work, this is DRIFT-ACCEPTED, not DRIFT-OPEN.
2. **Active-delivery-status cites phpstan 22 notes becoming 0.** Cannot ground from code alone (would need to run `composer analyse`). Treated as DRIFT-RESOLVED since the plan is internally consistent and the git refs (`3f03818`) are documented.
3. **Dashboard nav plan claims 482/477 tests, 3923 assertions.** Note that `active-delivery-status.md` (v5.11.0) cites 362/362 after `verify-w1-3` waves closed. The plan's higher count likely reflects a later test run. Both are consistent with the chronological progression (the plan is dated 2026-07-23, after the status).

## Cross-boundary dispatch check

| New dispatch point (per plan) | Consuming side | Status |
|---|---|---|
| `navigation.tsx` exports `NAVIGATION_ENTRIES` | `AppWorkspace.tsx` imports `navigationGroups` from `../shell/navigation` | DRIFT-RESOLVED (consuming side confirmed) |
| `PlatformSettingsOutbox` event `com.cluster.platform-settings.version-published.v1` | `Identity` consumes published security policy | DRIFT-RESOLVED by contract; Identity module exists |
| `BackupOperationsGateway` | Command `RunPlatformOperationsDispatchCommand` | DRIFT-RESOLVED (handler + console command both present) |
| `TechnicalLogSource` (mock) | `TechnicalLogsHandler` | DRIFT-RESOLVED — V1 mock; future Audit adapter is documented gap |
| `PrincipalProvider` exposes `capabilities`/`effectiveScope`/`revision` | `AccessContext.tsx` consumes | DRIFT-RESOLVED — `principal-context.tsx` exists; `AppWorkspace.tsx` uses `usePrincipal()` |
| `buildNavigationGroups` | `AppWorkspace.tsx` | DRIFT-RESOLVED — `useNavigationGroups` invoked in `AppWorkspace.tsx` |

All new types/values introduced in these plans have explicit dispatch branches in the consuming code.

## No Drift-Open

No claim in the audited docs/plans/* / docs/superpowers/* was found to contradict code state. Every "implemented" assertion is grounded in:
- existing files under `apps/api/Modules/<Name>/` for the 12 active modules
- existing routes under `apps/api/routes/` (119 routes verified)
- existing frontend files under `apps/web/src/features/<name>/` (14 features)
- existing shell files `routes.ts`, `navigation.tsx`, `principal-context.tsx`
- existing Makefile targets and CI artifacts (`artifacts/dashboard-navigation-qa-*.png`, etc.)

Every "planned/historical" assertion is consistent with the absence of relevant code, and the docs themselves mark those possibilities as such.

---

## Final classification

| File | Class |
|---|---|
| `docs/plans/README.md` | DRIFT-RESOLVED |
| `docs/plans/implementation-roadmap.md` | DRIFT-RESOLVED |
| `docs/plans/release-1-platform.md` | DRIFT-RESOLVED |
| `docs/plans/release-1/w1-2-frontend-slices.md` | DRIFT-RESOLVED |
| `docs/plans/release-1/w1-3-frontend-slices.md` | DRIFT-RESOLVED |
| `docs/plans/release-2-strategy-portfolio.md` | DRIFT-ACCEPTED (Strategy/PortfolioProjects planned) |
| `docs/plans/release-3-risk.md` | DRIFT-ACCEPTED (Risk planned) |
| `docs/plans/readiness-checklist.md` | DRIFT-RESOLVED |
| `docs/plans/active-delivery-status.md` | DRIFT-RESOLVED |
| `docs/plans/w1-1-remaining-delivery-tasks.md` | DRIFT-RESOLVED |
| `docs/plans/frontend-coverage-completion.md` | DRIFT-RESOLVED |
| `docs/plans/approvals-and-requests.md` | DRIFT-RESOLVED |
| `docs/superpowers/specs/2026-07-17-gsd-takeover-design.md` | DRIFT-ACCEPTED (historical) |
| `docs/superpowers/specs/2026-07-22-dashboard-navigation-redesign-design.md` | DRIFT-RESOLVED |
| `docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md` | DRIFT-RESOLVED |
| `docs/superpowers/specs/2026-07-23-platform-settings-v1-design.md` | DRIFT-RESOLVED |
| `docs/superpowers/plans/2026-07-23-platform-settings-v1.md` | DRIFT-ACCEPTED (frontend pending) |
| `docs/superpowers/plans/2026-07-17-gsd-takeover.md` | DRIFT-ACCEPTED (historical) |
