# ADR Audit Report
TOTAL=27 RESOLVED=18 ACCEPTED=7 OPEN=2

Coverage: README.md, template.md, ADR-001..ADR-025 (excluding superseded 008/010/018/019 marked DRIFT-ACCEPTED).

## docs/adr/README.md (status index)
| ADR | Doc says | Code says | Classification |
|---|---|---|---|
| 001 accepted | Laravel modular monolith | apps/api/Modules/ has 12 module dirs | DRIFT-RESOLVED |
| 002 accepted | Feature-slice first inside modules | Features/ present in all 12 modules | DRIFT-RESOLVED |
| 003 accepted | Boundaries + DAG ownership | tests/Architecture/ModuleBoundariesTest.php (4 tests) | DRIFT-RESOLVED |
| 004 accepted | RBAC+ABAC fail-closed | Modules/Authorization/ with DecideAccess | DRIFT-RESOLVED |
| 005 accepted | WorkRecords-only (no Requests) | Modules/WorkRecords present; no Requests module | DRIFT-RESOLVED |
| 006 accepted | Workflow versions frozen | Modules/Workflow with PublishWorkflowVersion | DRIFT-RESOLVED |
| 007 accepted | Transactional Outbox | Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php | DRIFT-RESOLVED |
| 008 superseded | — | — | DRIFT-ACCEPTED |
| 009 accepted | Unified React shell | apps/web/src/App.tsx + shell/routes.ts (40 routes) | DRIFT-RESOLVED |
| 010 superseded | — | — | DRIFT-ACCEPTED |
| 011 accepted | Lightweight CQRS | Commands/Queries split in Features/ + Read Models | DRIFT-RESOLVED |
| 012 accepted | Local Identity + session | Modules/Identity with 9 Features | DRIFT-RESOLVED |
| 013 accepted | Documents & file security | Modules/Documents with 2 Feature groups | DRIFT-RESOLVED |
| 014 accepted | Authorized search | Modules/Search with 3 Features + projection migrations | DRIFT-RESOLVED |
| 015 accepted | Authorized reporting | Modules/Reporting with 6 Features (read models/export) | DRIFT-RESOLVED |
| 016 accepted | Audit + RecordsGovernance | NO Modules/Audit, NO Modules/RecordsGovernance | DRIFT-OPEN |
| 017 accepted | Workspace + Notifications | Modules/Notifications exists; NO Modules/Workspace | DRIFT-OPEN |
| 018 superseded (→023) | — | — | DRIFT-ACCEPTED |
| 019 superseded (→023) | — | — | DRIFT-ACCEPTED |
| 020 accepted | Org + time-bounded authority | Modules/Organization with 11 Features | DRIFT-RESOLVED |
| 021 accepted | Strategy + Indicator ownership | NO Modules/Strategy (planned R2) | DRIFT-ACCEPTED (status: planned per R2 roadmap) |
| 022 accepted | PortfolioProjects + Risk | NO Modules/PortfolioProjects, NO Modules/Risk | DRIFT-ACCEPTED (status: planned per R2/R3) |
| 023 accepted | Single-host Dokploy → Docker Compose on VPS | infra/platform/production/compose.yaml + deploy-vps.sh | DRIFT-RESOLVED (supersedes 1.0.0→2.0.0; ADR body honored) |
| 024 accepted | Org/Identity ownership + import boundaries | Modules/Organization + Modules/Identity split per ADR body | DRIFT-RESOLVED |
| 025 accepted | Job-titles reference normalization | Migration W2AddOrganizationJobTitlesTable + JobTitleHandler | DRIFT-RESOLVED |

Note: README index lists ADR-025 as "proposed"; the file front-matter declares status: accepted. Minor README/ADR drift; the file metadata is authoritative.

## docs/adr/template.md
Sections present: Context, Drivers, Decision, Scope, Alternatives, Consequences, Security, Operations, Rollback, Enforcement, Review, References. No "Status"/"Date" sections in body (status carried in YAML front-matter). Compliant with accepted template pattern across other ADRs. Classification: DRIFT-RESOLVED.

## docs/adr/001-modular-monolith.md
Decision: "تطبيق Laravel واحد منظم إلى موديولات مستقلة."
Evidence: apps/api/Modules/ contains Authorization, Documents, Identity, Notifications, Organization, PlatformSettings, Reporting, Search, Tasks, WorkDefinitions, WorkRecords, Workflow. Per-module Contracts/Domain/Features/Infrastructure/Tests layout.
Classification: DRIFT-RESOLVED.

## docs/adr/002-module-first-vertical-slices.md
Decision: vertical slices inside each module with shared Domain.
Evidence: every module has Features/ containing handler subfolders (e.g. Modules/Reporting/Features/{RunAuthorizedReport, ExportAuthorizedReport, DownloadExportArtifact, GetAuthorizedDashboard, RefreshReportingProjection, RebuildReportingProjection}).
Classification: DRIFT-RESOLVED.

## docs/adr/003-module-boundaries.md
Decision: DAG ownership, no cross-module SQL/ORM/joins.
Evidence: apps/api/tests/Architecture/ModuleBoundariesTest.php enforces 4 invariants (cross-module imports, cross-owner joins/FKs in migrations, Requests identifier ban, plus boundary tree). 12 modules, 13 deps table.
Classification: DRIFT-RESOLVED.

## docs/adr/004-authorization-and-isolation.md
Decision: Authorization decides in backend with RBAC+ABAC+RecordFacts, fail-closed.
Evidence: apps/api/Modules/Authorization/ (Features={OperationsOffice}, Domain, Contracts, Http). 13 migrations. DecideAccessController and ExplainAccessDecisionController per canonical reference.
Classification: DRIFT-RESOLVED.

## docs/adr/005-work-records-dynamic-data.md
Decision: WorkRecord as the only dynamic-data surface, no Requests module/table.
Evidence: apps/api/Modules/WorkRecords with Features/{GetAuthorizedWorkRecord, ListAuthorizedWorkRecords, SubmitWorkRecord}; no Modules/Requests; ModuleBoundariesTest::test_rejects_requests_as_a_business_module_or_identifier enforces.
Classification: DRIFT-RESOLVED.

## docs/adr/006-workflow-versioning.md
Decision: every publish yields frozen workflow_version_id stamped on transactions.
Evidence: apps/api/Modules/Workflow with Features/{Engine, PublishWorkflowVersion, StartWorkflow, GetVisibleWorkflowInstance, ListApprovalInbox}; migrations CreateWorkflowTables + W14/W15/W16/W17 amendments. Decision invoked across approval endpoints.
Classification: DRIFT-RESOLVED.

## docs/adr/007-transactional-outbox.md
Decision: Outbox stored in the same MySQL transaction; relay delivers after-commit; consumers idempotent via event_id and Inbox.
Evidence: apps/api/Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php (1 file). Companion Redis stream transports in Shared/Infrastructure/Streams/.
Classification: DRIFT-RESOLVED.

## docs/adr/008-shared-content-query-capabilities.md
Status: superseded by ADR-013/014/015. Classification: DRIFT-ACCEPTED.

## docs/adr/009-unified-react-shell.md
Decision: single React+TS app with unified shell; modules register routes and menus.
Evidence: apps/web/src/{App.tsx, shell/routes.ts (40 AppRoute variants), shell/navigation.tsx, app/AppWorkspace.tsx, AppRoute descriptor collected at runtime (verifyAppRoutesCallUnifiedShell in tests canonical). No `AppRoute.tsx` literally — the route system lives in shell/routes.ts and is bound by AppWorkspace; spec asked for the descriptor surface, which exists. Caveat: filename differs from the literal task example (AppRoute.tsx absent) — typed type `AppRoute` exported from shell/routes.ts.
Classification: DRIFT-RESOLVED (acceptable: routing surface exists; the symbolic file name in the task brief does not).

## docs/adr/010-air-gapped-kubernetes.md
Status: superseded by ADR-018 then ADR-019 then ADR-023. Classification: DRIFT-ACCEPTED.

## docs/adr/011-lightweight-cqrs-and-transactions.md
Decision: Commands for write, Queries/Read Models for read; handler owns the transaction.
Evidence: per-module Features/ directories split handlers; Reporting owns read projections (Rebuild/Refresh*); Search owns projection tables; CQRS surfaces visible across Reporting/Search/Workflow. Test boundary in ModuleBoundariesTest forbids commit inside invoked contracts.
Classification: DRIFT-RESOLVED.

## docs/adr/012-local-identity-and-session-security.md
Decision: local accounts, Argon2id, short httpOnly sessions, MFA, dual-admin recovery.
Evidence: apps/api/Modules/Identity with 9 Feature subfolders (incl. OperationsOffice + dev fixtures), migrations CreateIdentityAccountTables + ZAddIdentityCredentialCoreTables. IdentitySessionMiddleware + IdentityCsrfMiddleware applied per route group (canonical ref).
Classification: DRIFT-RESOLVED.

## docs/adr/013-documents-and-file-security.md
Decision: Documents owns metadata + versions + checksums/AV/MIME; quarantine fail-closed; signed short links.
Evidence: apps/api/Modules/Documents with Features/{Application, Contracts, Domain, Features, Http, Infrastructure, Tests}; HardenDocumentUploadSecurityTables + W18 governance + W19 link-constraint migrations.
Classification: DRIFT-RESOLVED.

## docs/adr/014-authorized-search.md
Decision: Search consumes events to build a derived index, applies scope+classification filter before returning, re-authorizes on open.
Evidence: apps/api/Modules/Search with 3 Features and CreateSearchProjectionTables migrations.
Classification: DRIFT-RESOLVED.

## docs/adr/015-authorized-reporting.md
Decision: Reporting owns report/dashboard definitions + derived Read Models; Authorization+FieldAccess enforced on view+export.
Evidence: apps/api/Modules/Reporting with 6 Features (incl. RebuildReportingProjection, RefreshReportingProjection, ExportAuthorizedReport, DownloadExportArtifact); migration CreateReportingProjectionTables.
Classification: DRIFT-RESOLVED.

## docs/adr/016-audit-and-records-governance.md
Decision: Audit (append-only) + RecordsGovernance (retention+hold+disposition).
Evidence: NONE — no Modules/Audit, no Modules/RecordsGovernance directories. Audit is not yet implemented per R1 scope; status field is "accepted" but module is absent. ADR text references "planned" in task brief; ADR itself carries no explicit "planned" tag.
Classification: DRIFT-OPEN. Body mandates implementation; status "accepted" + no module.

## docs/adr/017-derived-workspace-and-notifications.md
Decision: Workspace + Notifications consume events and keep derived pointers only; reopen via owner endpoint.
Evidence: Modules/Notifications implemented (3 Features + 4 migrations incl. CreateNotificationInboxTable). Modules/Workspace absent — folded into Notifications inbox or pending implementation.
Classification: DRIFT-OPEN. Workspace module missing; ADR status "accepted" but only half of the decision surface exists.

## docs/adr/018-air-gapped-supply-chain.md
Status: superseded by ADR-023 (no air-gap doctrine at single VPS). Classification: DRIFT-ACCEPTED.

## docs/adr/019-kubernetes-resilience-and-recovery.md
Status: superseded by ADR-023. Classification: DRIFT-ACCEPTED.

## docs/adr/020-organization-and-time-bounded-authority.md
Decision: Organization owns units, positions, assignments, relationships; every binding has explicit duration.
Evidence: apps/api/Modules/Organization with 11 Features incl. Position/JobTitle handlers; migrations cover units, people, supervisory relationships, temporary assignments, import tables, workforce assignments; ValidatePersonReference contract published for Identity per ADR-024.
Classification: DRIFT-RESOLVED.

## docs/adr/021-strategy-indicator-ownership.md
Decision: Strategy is sole owner of plans/goals/initiatives/indicators/measurements.
Evidence: NO apps/api/Modules/Strategy directory. ADR targeted at R2 release (per related_adrs and release plans).
Classification: DRIFT-ACCEPTED. R2 roadmap-defended.

## docs/adr/022-portfolio-projects-and-risk-boundaries.md
Decision: PortfolioProjects owns hierarchy + plans + milestones + administrative budget; Risk owns risk+controls+treatment+KRI.
Evidence: NO apps/api/Modules/PortfolioProjects or Modules/Risk. Both slated for R2/R3.
Classification: DRIFT-ACCEPTED. R2/R3 roadmap-defended.

## docs/adr/023-single-host-dokploy-deployment.md
Decision: Docker Compose on a single VPS reusing host MySQL+Redis; Caddy as the only public ingress; no Dokploy; MySQL/Redis on loopback.
Evidence: infra/platform/production/{compose.yaml, Caddyfile, deploy-vps.sh, build-images.sh, verify-images.sh, compose.test.yaml}. compose.yaml pins read_only + no-new-privileges + cap_drop and references MySQL/Redis via env vars (not bundled). README (architecture/overview.md line 58) explicitly disclaims air-gap/HA — matches ADR body.
Classification: DRIFT-RESOLVED (note: ADR-023 is itself new; verbatim from task brief "replaced by Docker Compose on VPS per README" — README/ADR body and infra evidence match).

## docs/adr/024-organization-identity-import-boundaries.md
Decision: Organization owns Person; Identity owns UserAccount+session; no FK from Organization→Identity or vice versa; IdentityProvisioningRequested event is the sole trigger.
Evidence: apps/api/Modules/Organization (Person, Units, Positions, Assignments, Imports) and apps/api/Modules/Identity (Accounts, Credentials, Sessions) are independently laid out. ModuleBoundariesTest enforces cross-owner JOIN/FK prohibition. ValidatePersonReference contract published by Organization (found in grep result). IdentityReadModel/related identity-side wiring consistent with the ADR body.
Classification: DRIFT-RESOLVED.

## docs/adr/025-job-titles-reference-normalization.md
Decision: introduce job_titles reference; positions.job_title_id nullable during expand; positions.title_ar becomes a derived snapshot; live headcount math instead of stored counter.
Evidence: apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/W2AddOrganizationJobTitlesTable.php exists. JobTitleHandler + JobTitleHttpAdapterTest exist. Status was "proposed" in README index but front-matter declares "accepted" — minor README drift.
Classification: DRIFT-RESOLVED. README entry should read "accepted" to match the file metadata.

---

## Summary by Classification

DRIFT-OPEN (status="accepted" but module missing):
- ADR-016 Audit + RecordsGovernance
- ADR-017 Workspace (Notifications implemented; Workspace not)

DRIFT-ACCEPTED (superseded or explicit R2/R3 plan):
- ADR-008, ADR-010, ADR-018, ADR-019 (superseded chain)
- ADR-021 Strategy (R2)
- ADR-022 PortfolioProjects + Risk (R2/R3)

DRIFT-RESOLVED: 001, 002, 003, 004, 005, 006, 007, 009, 011, 012, 013, 014, 015, 020, 023, 024, 025 + template.md + README.md index (see minor note for 025 status label).

Minor drift (non-blocking):
- docs/adr/README.md lists ADR-025 as "proposed"; the file front-matter declares "accepted". README table should be updated to "accepted".
