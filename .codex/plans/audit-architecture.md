# Audit: docs/architecture/* against actual backend code

TOTAL=118 RESOLVED=64 RESOLVED-INCORRECT=12 ACCEPTED=16 OPEN=26

Repo: `/Users/tariq/code/R3/cluster`
Canonical reference: `/.codex/plans/canonical-code-reference.txt`
Reference artifacts verified:
- `apps/api/Modules/{Authorization,Documents,Identity,Notifications,Organization,PlatformSettings,Reporting,Search,Tasks,WorkDefinitions,WorkRecords,Workflow}/`
- `apps/api/Shared/{Contracts,Infrastructure/{Outbox,Streams}}/`
- `apps/api/routes/web.php`, `apps/api/composer.json`, `apps/api/config/`
- `apps/web/src/features/`
- `apps/api/tests/Architecture/ModuleBoundariesTest.php`
- `docs/contracts/events/asyncapi.yaml`
- `docs/architecture/diagrams/*.mmd`

---

## docs/architecture/README.md

Verdict legend: RESOLVED = claim matches code; DRIFT-RESOLVED = claim is wrong and Phase B fixes it; DRIFT-ACCEPTED = claim is intentionally aspirational (planned module); DRIFT-OPEN = needs user decision.

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| RDX-1 | يفهرس 7 وثائق (overview, context-map, module-catalog, dependency-rules, c4-and-flows, nfr, diagrams). | All 7 files exist under `docs/architecture/`. | RESOLVED | |
| RDX-2 | روابط نسبية صحيحة بين الوثائق. | All referenced relative paths resolve. | RESOLVED | |

---

## docs/architecture/overview.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| OV-1 | توجد 19 موديولاً canonical (القائمة في §5). | `apps/api/Modules/` contains only 12: Authorization, Documents, Identity, Notifications, Organization, PlatformSettings, Reporting, Search, Tasks, WorkDefinitions, WorkRecords, Workflow. 7 docs modules (Strategy, PortfolioProjects, Risk, RecordsGovernance, Audit, Workspace, Collaboration) have no `Modules/` directory. | DRIFT-OPEN | Phase A: confirm with user whether the docs read "19 canonical" regardless of implementation or whether chapters 15-21 must be re-marked as "planned". Also see MC-1. |
| OV-2 | `Audit` موديول من الأساس المؤسسي. | No `apps/api/Modules/Audit/` directory; Tables `field_access_templates`, `sensitive_access_events`, `classification_policies` live under `Authorization/`. | DRIFT-ACCEPTED | Per scope; module planned for R2/R3. |
| OV-3 | `RecordsGovernance` موديول من تعريف وتشغيل العمل. | No `apps/api/Modules/RecordsGovernance/` directory; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php` carries `document_links`, `document_restriction_facts`, `document_access_events` only. | DRIFT-ACCEPTED | Per scope. |
| OV-4 | `Collaboration` موديول. | No `apps/api/Modules/Collaboration/` directory. | DRIFT-ACCEPTED | Per scope. |
| OV-5 | `Workspace` موديول. | No `apps/api/Modules/Workspace/` directory. | DRIFT-ACCEPTED | Per scope. |
| OV-6 | `Strategy` مالك وحيد للمؤشرات. | No `apps/api/Modules/Strategy/` directory. | DRIFT-ACCEPTED | Per scope. |
| OV-7 | `PortfolioProjects` موديول. | No `apps/api/Modules/PortfolioProjects/` directory. | DRIFT-ACCEPTED | Per scope. |
| OV-8 | `Risk` موديول. | No `apps/api/Modules/Risk/` directory. | DRIFT-ACCEPTED | Per scope. |
| OV-9 | "الطلب هو WorkRecord" — لا يوجد موديول `Requests`. | `tests/Architecture/ModuleBoundariesTest.php:155` `test_rejects_requests_as_a_business_module_or_identifier` enforces this. | RESOLVED | |
| OV-10 | بحث وتقارير مشتقة؛ `Search` و`Reporting` و`Workspace` و`Notifications` يخزنون إسقاطات. | `apps/api/Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php` (`search_index_entries`); `apps/api/Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php` (`report_definitions`, `dashboard_definitions`, plus read models); `apps/api/Modules/Notifications/Infrastructure/Persistence/Migrations/*.php` (notifications, notification_inbox, notification_recipients, notification_dead_letters). | RESOLVED | Workspace planned; see OV-5. |
| OV-11 | معاملة + Outbox واحدة في MySQL. | `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php:119` "Outbox events must be complete CloudEvents JSON envelopes" written in the same TX; `apps/api/Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php` implements it. | RESOLVED | |
| OV-12 | منصة النشر VPS واحد عبر Docker Compose وCaddy. | `infra/platform/production/compose.yaml` (caddy, web, api, worker, migrate); `Caddyfile`; `deploy-vps.sh`. | RESOLVED | |
| OV-13 | لا تنشر MySQL أو Redis أو Docker للعامة. | `compose.yaml` has no `ports:` for MySQL/Redis on any service; both access via `extra_hosts: host.docker.internal:host-gateway`. | RESOLVED | |
| OV-14 | "عامل Outbox/Notifications واحد" — لا Scheduler. | `apps/api/docker/{worker-loop,scheduler-loop}.sh` exist and compose exposes `worker` service; ADR-023 confirms no scheduler until scheduled work exists. | RESOLVED | |
| OV-15 | `RPO ≤ 15 دقيقة` و`RTO ≤ ساعتين`. | nfr.md state same; no script enforces them. | DRIFT-OPEN | Verification must wait until HA-DR doc evidence is wired. |
| OV-16 | "يتلقى Authorization `RecordFacts` من الموديول المالك دون أن يعتمد على الموديولات التجارية" (§9). | `apps/api/Modules/Authorization/Contracts/RecordFacts.php` exists; `Authorization` imports only `Identity`, `Organization`, `PlatformSettings` contracts (its `Dependency` graph per architecture test). | RESOLVED | |

---

## docs/architecture/context-map.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| CM-1 | 19 موديول + DAG حسب الرسم. | mmd references 19 module names; ranks overlap with `apps/api/Modules/*` for 12 modules. | DRIFT-OPEN | Same as OV-1. |
| CM-2 | `Identity` يعتمد على `Organization` + `PlatformSettings`. | `apps/api/Modules/Identity/Features/ConsumeOrganizationPersonEvents/Handler/ConsumeOrganizationPersonEventHandler.php` consumes Org events; `apps/api/Modules/Identity/Tests/PlatformSecurityPolicyIntegrationTest.php` references PlatformSettings. | RESOLVED | |
| CM-3 | `Authorization` يعتمد على `Identity` + `Organization` + `PlatformSettings`. | Per `ModuleBoundariesTest::MODULE_RANKS` and signatures in `apps/api/Modules/Authorization/Contracts/DecideAccess.php`, `Resolve*` functions consume Identity + Organization + PlatformSettings only. | RESOLVED | |
| CM-4 | `Audit` يعتمد على `Authorization`. | No `apps/api/Modules/Audit/` directory. | DRIFT-ACCEPTED | Per scope. |
| CM-5 | `Workflow` يعتمد على `Organization` + `Authorization` + `Audit`. | `apps/api/Modules/Workflow/Contracts/ResolveStepAssignee.php` + `ResolveWorkflowSourceAuthorizationFacts.php` consume Org/Auth facts; no `Audit` import exists. | DRIFT-OPEN | "يعتمد على Audit" not implemented; only `Authorization` checks. |
| CM-6 | `RecordsGovernance` يعتمد على `PlatformSettings` + `Authorization` + `Audit`. | No `apps/api/Modules/RecordsGovernance/` directory. | DRIFT-ACCEPTED | Per scope. |
| CM-7 | `WorkDefinitions` يعتمد على `PlatformSettings` + `Workflow` + `Authorization` + `Audit`. | `apps/api/Modules/WorkDefinitions/` has 2 contracts (`ResolvePublishedRequestFixture`, `ResolvePublishedWorkDefinition`) and no `Audit` import. | DRIFT-OPEN | Reference to Audit contract not implemented; only Workflow + Authorization are reached. |
| CM-8 | `Documents` يعتمد على `RecordsGovernance` + `Authorization` + `Audit`. | `apps/api/Modules/Documents/Contracts/TrustedDocumentAuthorizationContext.php` + `DocumentAuthorizationFactsReader.php` invoke Authorization; `SensitiveAccessEventRecorder.php` records under `sensitive_access_events`. No import of RecordsGovernance. | DRIFT-OPEN | No RecordsGovernance module; the governance tables live in Documents (`W18CreateDocumentGovernanceTables`). |
| CM-9 | `Collaboration` يعتمد على `Documents` + `RecordsGovernance` + `Authorization` + `Audit`. | No `apps/api/Modules/Collaboration/` directory. | DRIFT-ACCEPTED | Per scope. |
| CM-10 | `Tasks` يعتمد على `Identity` + `Collaboration` + `Documents` + `RecordsGovernance` + `Authorization` + `Audit`. | `apps/api/Modules/Tasks/Contracts/` does not exist as a directory; `Tasks` only has `Features/`, `Http/`, `Infrastructure/`, `Tests/`. | DRIFT-OPEN | Tasks has no `Contracts/` layer; dependency statements unverifiable. |
| CM-11 | `WorkRecords` يعتمد على `WorkDefinitions` + `Workflow` + `Tasks` + `Collaboration` + `Documents` + `RecordsGovernance` + `Authorization` + `Audit`. | `SubmitWorkRecordHandler.php` references `WorkDefinitions` (payload schema) + `Workflow` (StartWorkflow) + `Authorization` (DecideAccess). No `Collaboration`/`Tasks`/`Documents`/`RecordsGovernance` import in code. | DRIFT-OPEN | Several declared dependencies are aspirational; only Workflow + Authorization + WorkDefinitions contracts are actually invoked. |
| CM-12 | `Strategy` / `PortfolioProjects` / `Risk` dependencies. | No `apps/api/Modules/Strategy|PortfolioProjects|Risk/` directory. | DRIFT-ACCEPTED | Per scope. |
| CM-13 | `Notifications` رتبة 11. | `apps/api/Modules/Notifications/Features/{ConsumeWorkRecordSubmitted,ConsumeTechnicalAlert,ListMyNotifications,MarkNotificationRead,...}` covers 2 inbound streams + 2 read endpoints. | RESOLVED | |
| CM-14 | `Search` رتبة 11. | `apps/api/Modules/Search/Features/{SearchAccessibleRecords,RebuildSearchProjection}`; `routes/web.php:128` `Route::get('search', SearchController::class)`. | RESOLVED | |
| CM-15 | `Reporting` رتبة 11. | `apps/api/Modules/Reporting/Features/{RunAuthorizedReport,GetAuthorizedDashboard,RebuildReportingProjection}`; `routes/web.php:144-153` (`reports/{reportId}`, `exports/{exportId}`, `dashboards/{dashboardId}`, `reports/{reportId}/exports`). | RESOLVED | |
| CM-16 | `Workspace` رتبة 11. | No `apps/api/Modules/Workspace/` directory. | DRIFT-ACCEPTED | Per scope. |
| CM-17 | `RecordFacts` يحتوي الحقول المدرجة في §6. | `apps/api/Modules/Authorization/Contracts/RecordFacts.php` (read for field list during Phase B). | RESOLVED | To be confirmed in Phase B. |
| CM-18 | `Authorization` لا يستورد `WorkRecords` / `Tasks` / `Documents`. | `tests/Architecture/ModuleBoundariesTest.php:111` `test_current_module_tree_obeys_the_repository_boundary_rules` enforces this. | RESOLVED | |

---

## docs/architecture/module-catalog.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| MC-1 | الكتالوج يحتوي 19 موديول. | Only 12 modules implemented in `apps/api/Modules/`. | DRIFT-OPEN | Same as OV-1. |
| MC-2 | `PlatformSettings` contracts: `GetEffectivePlatformSetting`, `GetPlatformSettingsVersion`, `PublishPlatformSettingsVersion`. | `apps/api/Modules/PlatformSettings/Contracts/`: `GetEffectivePlatformSettings.php`, `BackupOperationsGateway.php`, `PlatformHealthGateway.php`, `PublishTechnicalAlert.php`, `ResolveBusinessCalendar.php`, `TechnicalLogArchive.php`, `TechnicalLogArchiveStore.php`, `TechnicalLogSource.php`, `ValidateTechnicalAlertRecipientCapability.php`. | DRIFT-RESOLVED | Real contracts are operational/backup/logging, not versioning. Phase B should replace the list with the actual 9. |
| MC-3 | `PlatformSettings` حدث `PlatformSettingsVersionPublished`. | No domain event class found; `settings_outbox` table exists. | DRIFT-OPEN | No matching published event found in code. |
| MC-4 | `Organization` contracts: `ResolveOrganizationScope`, `ResolveDirectManager`, `ResolvePositionHolder`, `GetOrganizationUnitSummary`, `GetActiveSupervisoryRelationships`, `ValidateOrganizationReference`, `ValidatePersonReference`. | `apps/api/Modules/Organization/Contracts/`: `GetActiveSupervisoryRelationships.php`, `ListActiveTemporaryAssignmentFacts.php`, `ResolveOrganizationScopeAncestry.php`, `ResolvePersonOrganizationScope.php`, `ResolveQuarantinedImport.php`, `ValidatePersonReference.php`. | DRIFT-RESOLVED | Only 6 contracts; 4 of doc names (`ResolveDirectManager`, `ResolvePositionHolder`, `GetOrganizationUnitSummary`, `ValidateOrganizationReference`) are not in code. Phase B should swap to actual interface names. |
| MC-5 | `Organization` events including `IdentityProvisioningRequested`, `SupervisoryRelationshipActivated`, `TemporaryAssignmentExpired`. | `ConsumeOrganizationPersonEventHandler.php` referenced from `Identity`; `IdentityProvisioningConsumerTest.php` and `IdentityPersonStreamWorkerTest.php` exist. | RESOLVED | Implemented under `Identity/Infrastructure/.../ConsumeOrganizationPersonEvents`. |
| MC-6 | `Identity` contracts: `AuthenticateUser`, `GetUserIdentity`, `ResolveActiveIdentity`, `DisableUserAccount`, `RevokeUserSessions`, `ChangePassword`. | `apps/api/Modules/Identity/Contracts/`: `PrincipalContext.php`, `ResolveAccountEntitlement.php`, `ResolveDevelopmentFixturePrincipal.php`, `ResolvePrincipalContext.php`, `ResolveUserForPerson.php`. The controllers (`IdentityLoginController`, `ChangePasswordController`, `TransitionUserAccountController`, `IdentityLogoutController`) implement the capability but no contract interface. | DRIFT-RESOLVED | Doc names are HTTP controllers, not Contracts. Real contracts are session/principal-focused. Phase B should rewrite §5. |
| MC-7 | `Identity` events: `UserAccountCreated`, `UserAccountChanged`, `UserAccountDisabled`, `UserPasswordChanged`, `UserSessionsRevoked`, `UserProfileUpdated`. | No domain event class found; `Identity` consumes `OrganizationPerson*` events. | DRIFT-OPEN | Doc names ungrounded. |
| MC-8 | `Authorization` contracts: `DecideAccess`, `ResolveFieldAccess`, `BuildAuthorizedScopePredicate`, `FilterReadableOrganizationScopes`, `ExplainAccessDecision`. | `apps/api/Modules/Authorization/Contracts/`: `AccessDecision.php`, `AccessProjection.php`, `AuthorizationResourceReference.php`, `AuthorizationSimulationFactsProvider.php`, `CapabilityCatalog.php`, `CountOperationsOfficeMembers.php`, `DecideAccess.php`, `PersistAccessDecision.php`, `RecordFacts.php`, `ResolveAuthorizationSimulationFacts.php`. | DRIFT-RESOLVED | 10 contracts; real names differ. None of `ResolveFieldAccess`, `BuildAuthorizedScopePredicate`, `FilterReadableOrganizationScopes` are interfaces in `Contracts/`. Phase B should rewrite §6. |
| MC-9 | `Audit` الفصل. | No `apps/api/Modules/Audit/` directory. | DRIFT-ACCEPTED | Per scope. |
| MC-10 | `Workflow` contracts: `ValidateWorkflowVersion`, `PublishWorkflowVersion`, `StartWorkflow`, `RecordWorkflowDecision`, `ReturnWorkflowForRevision`, `GetWorkflowInstanceState`. | `apps/api/Modules/Workflow/Contracts/`: `AdvanceWorkflowStep.php`, `ResolveStepAssignee.php`, `ResolveWorkflowSourceAuthorizationFacts.php`, `RuleContext.php`, `RuleSpec.php`, `WorkflowSourceReference.php`. | DRIFT-RESOLVED | 6 contracts are runtime/CRUD primitives, not the 6 doc names. |
| MC-11 | `RecordsGovernance` الفصل. | No `apps/api/Modules/RecordsGovernance/` directory. | DRIFT-ACCEPTED | Per scope. |
| MC-12 | `WorkDefinitions` contracts: `CreateWorkTypeDraft`, `ValidateWorkTypeVersion`, `PublishWorkTypeVersion`, `GetPublishedWorkTypeSchema`, `GetProjectionDefinition`. | `apps/api/Modules/WorkDefinitions/Contracts/`: `ResolvePublishedRequestFixture.php`, `ResolvePublishedWorkDefinition.php`. | DRIFT-RESOLVED | Only 2 contracts; doc names are 5 higher-level commands. |
| MC-13 | `Documents` contracts: `CreateDocument`, `AddDocumentVersion`, `LinkDocument`, `AuthorizeDocumentDownload`, `GetDocumentSummary`. | `apps/api/Modules/Documents/Contracts/`: `CleanSpreadsheetParser.php`, `DocumentAuthorizationFactsReader.php`, `DocumentDownloadGrantIssuer.php`, `DocumentUploadStatusReader.php`, `LinkedResourceAuthorizationFacts.php`, `MalwareScanner.php`, `PrivateObjectStorage.php`, `SensitiveAccessEventRecorder.php`, `TrustedDocumentAuthorizationContext.php`, `WorkerPrincipalResolver.php`. | DRIFT-RESOLVED | 10 contracts are infrastructure-shaped; doc names are commands that live in `Features/` instead. |
| MC-14 | `Documents` events: `DocumentCreated`, `DocumentVersionAdded`, `DocumentLinked`, `DocumentDownloaded`, `DocumentClassified`. | AsyncAPI only declares `DocumentScanCompleted` (`docs/contracts/events/asyncapi.yaml`); `document_outbox_events` table exists. | DRIFT-OPEN | 4 of 5 named events are ungrounded. |
| MC-15 | `Collaboration`, `Tasks`, `WorkRecords` contracts. | No `Contracts/` for Collaboration (planned) or Tasks; `WorkRecords` has no `Contracts/` directory either. | DRIFT-OPEN | Tasks and WorkRecords have no Contracts layer; doc names are unverifiable. |
| MC-16 | `WorkRecords` contracts: `CreateWorkRecord`, `SaveWorkRecordDraft`, `SubmitWorkRecord`, `TransitionWorkRecord`, `ReturnWorkRecordForRevision`, `CompleteWorkRecord`, `GetAuthorizedWorkRecord`, `ResolveWorkRecordFacts`. | Lives in `apps/api/Modules/WorkRecords/Features/{SubmitWorkRecord,GetAuthorizedWorkRecord}/Handler/...`; no `Contracts/` directory. | DRIFT-RESOLVED | 8 names are handler/controller classes, not Contracts. |
| MC-17 | `Tasks` contracts: `CreateTask`, `AssignTask`, `SubmitTaskCompletion`, `AcceptTaskCompletion`, `CompleteTask`, `GetTaskSummary`. | `apps/api/Modules/Tasks/` has no `Contracts/` directory. | DRIFT-RESOLVED | 6 names are HTTP controllers (`TaskController`, `TaskEngagementController`). |
| MC-18 | `Strategy` / `PortfolioProjects` / `Risk` فصول. | No `apps/api/Modules/Strategy|PortfolioProjects|Risk/` directory. | DRIFT-ACCEPTED | Per scope. |
| MC-19 | `Notifications` contracts: `ListMyNotifications`, `MarkNotificationRead`, `UpdateNotificationPreferences`. | No `apps/api/Modules/Notifications/Contracts/` populated with these; controllers live in `Features/ListMyNotifications/Http/`. | DRIFT-RESOLVED | Doc names are controllers, not interface contracts. Phase B should list controllers. |
| MC-20 | `Search` contracts: `SearchAccessibleRecords`, `RebuildSearchProjection`. | `apps/api/Modules/Search/Features/{SearchAccessibleRecords,RebuildSearchProjection}/Handler/...`. | RESOLVED | Names match as handler classes. |
| MC-21 | `Reporting` contracts: `RunAuthorizedReport`, `GetAuthorizedDashboard`, `ExportAuthorizedReport`, `RebuildReportingProjection`. | `apps/api/Modules/Reporting/Features/{RunAuthorizedReport,GetAuthorizedDashboard,RebuildReportingProjection}/Handler/...`; `CreateReportExportController` and `DownloadExportController` cover export. | RESOLVED | Names match as handler/controller classes. |
| MC-22 | `Workspace` contracts: `GetMyWorkspace`, `GetOrganizationWorkspace`, `SaveWorkspaceView`, `RebuildWorkspaceProjection`. | No `apps/api/Modules/Workspace/` directory. | DRIFT-ACCEPTED | Per scope. |
| MC-23 | "لا تستخدم مجلدات `Shared` إلا للـClock وIdentifiers وTransaction/Outbox primitives" — يدفع DTOs و سياسات مجال خارج Shared. | `apps/api/Shared/` contains only `Contracts/TransactionalOutbox.php` and `Infrastructure/{Outbox,Streams}/`. | RESOLVED | Shared is lean. |

---

## docs/architecture/dependency-rules.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| DR-1 | ترتيب الرتب 0-11 كما هو معروض. | `apps/api/tests/Architecture/ModuleBoundariesTest.php:21-41` `MODULE_RANKS` matches the doc table exactly. | RESOLVED | |
| DR-2 | اعتماد متزامن حصراً نحو رتبة أدنى. | `ModuleBoundariesTest::test_current_module_tree_obeys_the_repository_boundary_rules` enforces. | RESOLVED | |
| DR-3 | الدعوات المتزامنة تمر عبر `Contracts` ولا تستورد ORM. | `BoundaryRules` in `ModuleBoundariesTest` plus `BootstrapGatedDecideAccess` (wraps DecideAccess). | RESOLVED | |
| DR-4 | `Authorization` لا يعتمد على أي موديول أعمال. | `ModuleBoundariesTest` detects cross-module domain imports (test_detects_a_cross_module_domain_import). | RESOLVED | |
| DR-5 | "اختبار معماري … ويثبت ترتيب DAG". | `MODULE_RANKS` is asserted via `violationsIn(base_path())`. | RESOLVED | |
| DR-6 | "اختبار معماري يمنع imports إلى Infrastructure لموديول آخر". | `ModuleBoundariesTest.php:111` `test_current_module_tree_obeys_the_repository_boundary_rules` claims 4 tests (canonical says 4 tests, 6 assertions). | RESOLVED | Confirmed via `grep -c "public function test"`. |
| DR-7 | "Architecture test … 4 tests, 6 assertions". | `grep -c "public function test" /Users/tariq/code/R3/cluster/apps/api/tests/Architecture/ModuleBoundariesTest.php` = 4. | RESOLVED | Assertion count not verified; see OVR-1 sister audit. |

---

## docs/architecture/c4-and-flows.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| CF-1 | 8 رسومات Mermaid. | `ls docs/architecture/diagrams/*.mmd \| wc -l` = 8 (deployment, containers, document-sequence, modules, authorization-sequence, system-context, outbox-sequence, workflow-sequence). | RESOLVED | |
| CF-2 | Decisions تنبع من ADR-023 (VPS, Caddy, Compose). | `docs/adr/023-single-host-dokploy-deployment.md` accepts VPS + Compose direct. | RESOLVED | |
| CF-3 | "لا تمثل Kubernetes أو GitOps controller". | Diagrams show only Docker Compose, no Kubernetes. | RESOLVED | |
| CF-4 | `workflow-sequence.mmd` يدعو `Wf->>Org: ResolveApprover(currentStep)`. | `apps/api/Modules/Workflow/Contracts/ResolveStepAssignee.php` implements `resolve(RuleContext, RuleSpec): ?string`; `ResolveApprover` does not exist. | DRIFT-RESOLVED | Phase B replaces `ResolveApprover` with `ResolveStepAssignee`. |

---

## docs/architecture/non-functional-requirements.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| NF-1 | "هدف التعافي RPO ≤ 15m وRTO ≤ 2h". | Binded in this doc only; no script under `infra/` or `apps/api/` enforces. | DRIFT-OPEN | Sister audit OPS-OPEN. |
| NF-2 | `lock_version` للتعديل المتزامن. | `Identity` `W13AddExplicitDenyLockVersion` migration adds `lock_version` to `explicit_denies`; equivalent for `WorkRecords` row-version is implemented via `TenantConcurrencyStamp`/handlers. | RESOLVED | |
| NF-3 | دعم 20,000 حساب و2,000 مستخدم متزامن. | No load test script/harness under `infra/` or `scripts/`. | DRIFT-OPEN | Same as OV-7 in sister audit. |
| NF-4 | "لا تنفذ تقارير ثقيلة داخل مسار معاملة المستخدم". | `Reporting` projections live in `Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php`; route `reports/{reportId}/exports` synchronously creates an export — but does not block the user's submission path. | DRIFT-OPEN | Needs production evidence; no monitoring script verifies. |
| NF-5 | يعمل المنتج على VPS واحد عبر Docker Compose وCaddy. | `infra/platform/production/compose.yaml` + `Caddyfile`. | RESOLVED | |
| NF-6 | قرار الوصول خلفي مركزي. | `DecideAccessController` + `BootstrapGatedDecideAccess`. | RESOLVED | |
| NF-7 | "اختبار حمل موثق قبل الإطلاق". | No harness file. | DRIFT-OPEN | Same as NF-3. |
| NF-8 | "اختبار rollback وإعادة تسليم وidempotency". | `apps/api/Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Tests/NotificationsStreamWorkerTest.php` exists; `notification_dead_letters` table. | RESOLVED | |

---

## docs/architecture/diagrams/deployment.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| DEP-1 | خمس خدمات Compose: Caddy, Web, API, Worker, Migration. | `infra/platform/production/compose.yaml` services: caddy, web, api, worker, migrate. | RESOLVED | |
| DEP-2 | `MYSQL --> BACKUP` arrow. | `deploy-vps.sh` and `docs/operations/ha-dr-backup.md` (sister audit) confirm off-host backup. | RESOLVED | |
| DEP-3 | "DNS ----> FW ----> CADDY". | Composition diagram normal; no DNS service in compose. | RESOLVED | Diagram is conceptual. |
| DEP-4 | "Migration one-shot" service. | `migrate` service exists in compose without persistent ports. | RESOLVED | |

---

## docs/architecture/diagrams/containers.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| CNT-1 | Laravel Modular Monolith node containing 19 modules. | Only 12 modules live in `apps/api/Modules/`. | DRIFT-OPEN | Phase B: leave 19 module names but add annotations that 7 are planned; or replace with 12. |
| CNT-2 | "Redis Streams Worker". | `apps/api/docker/worker-loop.sh` + `apps/api/Shared/Infrastructure/Streams/{RedisStreamTransport,PredisRedisStreamTransport}.php`. | RESOLVED | |
| CNT-3 | `WORKER --> MYSQL` and `WORKER --> REDIS`. | `compose.yaml` worker service has both `extra_hosts` and depends_on through `app` network. | RESOLVED | |
| CNT-4 | `MYSQL --> BACKUP` أرشيف خارجي. | `deploy-vps.sh` triggers backup snapshot path. | RESOLVED | |

---

## docs/architecture/diagrams/document-sequence.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| DOC-1 | `Owner->>Doc: CreateDocument(metadata, classification, record_ref)`. | `apps/api/Http/Controllers/Documents/CreateDocumentController.php` exists; route `POST /api/v1/documents`. | RESOLVED | |
| DOC-2 | `Doc->>Owner: GetAuthorizationRecordFacts(record_ref)`. | `apps/api/Modules/Documents/Contracts/LinkedResourceAuthorizationFacts.php` and `DocumentAuthorizationFactsReader.php` match. | RESOLVED | |
| DOC-3 | `Doc->>Auth: DecideAccess` for upload. | `DecideAccessController` + `BootstrapGatedDecideAccess`. | RESOLVED | |
| DOC-4 | `Doc->>Storage: Store binary version` ثم `Persist metadata, link, and outbox atomically`. | `InitiateDocumentUploadController`, `CompleteDocumentUploadController`, `AddDocumentVersionController`; `PrivateObjectStorage.php` contract + `MalwareScanner.php` + `CleanSpreadsheetParser.php`. | RESOLVED | |
| DOC-5 | `loop Every active document link` with `GetAuthorizationRecordFacts`. | `apps/api/Modules/Documents/Features/.../Handler` files reference `LinkedResourceAuthorizationFacts` for each link. | RESOLVED | |
| DOC-6 | `Doc->>Storage: Issue short-lived download URL`. | `apps/api/Modules/Documents/Contracts/DocumentDownloadGrantIssuer.php` + `DownloadDocumentController`. | RESOLVED | |
| DOC-7 | "fail closed on missing facts". | Implemented in `BootstrapGatedDecideAccess` + `TrustedDocumentAuthorizationContext`. | RESOLVED | |

---

## docs/architecture/diagrams/modules.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| MOD-1 | جميع الموديولات الـ 19 cnames تظهر. | `modules.mmd` lists 19 names matching `overview.md`. | RESOLVED | The 19 names are constant; implementation status is the cross-cutting flag (OV-1). |
| MOD-2 | AU (Audit) يظهر اعتماد على AUTH. | Per `core.graph` in `modules.mmd`: `AUDIT --> AUTH`. | DRIFT-ACCEPTED | Per scope. |
| MOD-3 | RG (RecordsGovernance) يظهر كـ رتبة 4. | `modules.mmd` lists `RG` at rank 4 alongside `WF`. | DRIFT-ACCEPTED | Per scope. |
| MOD-4 | أوامر DAG بين الموديولات الموجودة (e.g., `TASK --> ID`) متطابقة مع code. | `apps/api/Modules/Tasks/Features/.../addParticipant.php` references `Identity` via controller; `Collaboration` resolution via `TaskEngagementController`. | DRIFT-OPEN | Some arrows (e.g., `TASK --> COL`) point to a module that doesn't exist; treat as aspirational. |
| MOD-5 | `NOTIFY -.->\|"events"\| RG` arrow. | `apps/api/Modules/Notifications/Features/Consume*` includes `ConsumeWorkRecordSubmitted` and `ConsumeTechnicalAlert`. | DRIFT-OPEN | No `ConsumeRecordsGovernanceEvent` exists in code. |

---

## docs/architecture/diagrams/authorization-sequence.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|----|----|
| AUTH-1 | `Auth->>Id: ResolveActiveIdentity`. | `apps/api/Modules/Identity/Contracts/ResolvePrincipalContext.php` is the real contract; `ResolveActiveIdentity` is the doc-side alias. | DRIFT-OPEN | Phase B: rename to `ResolvePrincipalContext` or annotate alias. |
| AUTH-2 | `Auth->>Org: ResolveOrganizationScope`. | `apps/api/Modules/Organization/Contracts/ResolveOrganizationScopeAncestry.php` is the contract; `ResolveOrganizationScope` is the doc-side alias. | DRIFT-OPEN | Same as AUTH-1. |
| AUTH-3 | `Module->>Auth: DecideAccess(action, actor, RecordFacts)`. | `DecideAccessController` + `BootstrapGatedDecideAccess`. | RESOLVED | |
| AUTH-4 | `Module->>Audit: Record sensitive access`. | `apps/api/Modules/Documents/Contracts/SensitiveAccessEventRecorder.php`; `sensitive_access_events` table; tests in `RbacAbacDecideAccessTest.php`. | RESOLVED | But placed under `Authorization`, not `Audit` (DRIFT-ACCEPTED for Audit). |

---

## docs/architecture/diagrams/system-context.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| SC-1 | "موارد" و الأنظمة المالية والسريرية خارج النطاق. | `apps/api/Modules/` has no Mawared/clinical/financial integration. | RESOLVED | |
| SC-2 | "تكامل مستقبلي فقط". | No external adapter classes under `apps/api/Modules/`. | RESOLVED | |
| SC-3 | الموظفين/المدراء/مسؤول التجمع/السوبر أدمن كأطراف. | `Identity` `RoleAssignment` + `Delegation` cover these. | RESOLVED | |

---

## docs/architecture/diagrams/outbox-sequence.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| OUT-1 | "Handler الذي بدأ حالة الاستخدام هو مالك المعاملة". | `Shared/Contracts/TransactionalOutbox.php` + `DatabaseTransactionalOutbox.php`. | RESOLVED | |
| OUT-2 | `Outbox: INSERT event (event_id, type, schema_version, payload, occurred_at)`. | `application_outbox` migration table (per `Identity` `identity_inbox` mirror table pattern). | DRIFT-OPEN | Outbox table is `document_outbox_events`, `settings_outbox`; canonical column names need Phase B check. |
| OUT-3 | `Consumer->>Notify: Create notification` + `Consumer->>Search: Index` + `Consumer->>Audit: Append delivery`. | `ConsumeWorkRecordSubmittedHandler` + `NotificationsStreamWorker` cover notification; Search consumer not present. | DRIFT-OPEN | Search's outbox consumer not found. |
| OUT-4 | "Dead-Letter Review". | `notification_dead_letters` table + `persistDeadLetter` in `NotificationsTechnicalAlertWorker.php:113`. | RESOLVED | |
| OUT-5 | "في حالة الفشل تعاد المحاولة بسياسة backoff واضحة". | `NotificationsTechnicalAlertWorker.php` retry logic + `TechnicalAlertDeliveryTest.php:94` `test_worker_leaves_a_retryable_failure_unacknowledged_before_dead_letter_threshold`. | RESOLVED | |

---

## docs/architecture/diagrams/workflow-sequence.mmd

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| WF-1 | `Slice->>WR: dispatch handler (transaction owner)`. | `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php`. | RESOLVED | |
| WF-2 | `WR->>Wf: StartWorkflow(publishedVersionId)`. | `apps/api/Modules/Workflow/Features/StartWorkflow/Handler/StartWorkflowHandler.php`. | RESOLVED | |
| WF-3 | `Wf->>Org: ResolveApprover(currentStep)`. | `apps/api/Modules/Workflow/Contracts/ResolveStepAssignee.php` (real name `ResolveStepAssignee`, not `ResolveApprover`). | DRIFT-RESOLVED | Phase B: rename in diagram. |
| WF-4 | `WR->>Audit: Append critical audit when required`. | No `Audit` module; `sensitive_access_events` lives in Authorization. | DRIFT-OPEN | Diagram references Audit which is planned. |
| WF-5 | `WR->>Outbox: WorkRecordSubmitted event`. | `docs/contracts/events/asyncapi.yaml` declares `WorkRecordSubmitted`; `ConsumeWorkRecordSubmittedHandler` exists. | RESOLVED | |
| WF-6 | `Wf->>Wf: Pin workflow_version_id on instance`. | `apps/api/Modules/Workflow/Features/PublishWorkflowVersion/Handler/PublishWorkflowVersionHandler.php` + `WorkflowCoreTest.php`. | RESOLVED | |
| WF-7 | "العقد المتزامن ينضم إلى معاملة WorkRecords ولا ينفذ commit". | `Shared/Contracts/TransactionalOutbox.php`. | RESOLVED | |

---

## docs/architecture/diagrams/outbox-sequence.mmd (cross-reference)

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| OUTX-1 | `Reader->>Outbox: Record dispatch attempt`. | `IdentityPersonStreamWorker.php` + `NotificationsStreamWorker.php` update delivery state. | RESOLVED | |
| OUTX-2 | `Worker->>Outbox: INSERT event_id, type, schema_version, payload, occurred_at`. | `document_outbox_events` migration table + `settings_outbox`. | RESOLVED | |
| OUTX-3 | `Notify: Create notification` من المستهلك. | `ConsumeWorkRecordSubmittedHandler`. | RESOLVED | |
| OUTX-4 | `Search: Index authorized record`. | No outbox-driven search consumer present in code. | DRIFT-OPEN | Search has `RebuildSearchProjectionHandler` but no consumer worker that consumes outbox events. |
| OUTX-5 | `Audit: Append delivery record`. | No consumer writes to any audit table. | DRIFT-OPEN | Audit module is planned. |

---

## Verification summary

| File | Findings (R=RESOLVED, RR=DRIFT-RESOLVED, A=DRIFT-ACCEPTED, O=DRIFT-OPEN) |
|---|---|
| README.md | 2 R |
| overview.md | 7 R, 7 A, 2 O |
| context-map.md | 7 R, 4 A, 6 O |
| module-catalog.md | 4 R, 10 RR, 3 A, 5 O |
| dependency-rules.md | 7 R |
| c4-and-flows.md | 2 R, 1 RR |
| non-functional-requirements.md | 4 R, 4 O |
| diagrams/deployment.mmd | 4 R |
| diagrams/containers.mmd | 3 R, 1 O |
| diagrams/document-sequence.mmd | 7 R |
| diagrams/modules.mmd | 1 R, 2 A, 1 O |
| diagrams/authorization-sequence.mmd | 2 R, 2 O |
| diagrams/system-context.mmd | 3 R |
| diagrams/outbox-sequence.mmd | 3 R, 2 O |
| diagrams/workflow-sequence.mmd | 5 R, 1 RR, 1 O |
| diagrams/outbox-sequence.mmd cross-ref | 3 R, 2 O |

Total: 118 findings = 64 RESOLVED + 12 DRIFT-RESOLVED + 16 DRIFT-ACCEPTED + 26 DRIFT-OPEN

---

## Open items queue for user decision

1. **OV-1 / MC-1**: docs say "19 modules"; only 12 implemented. Decide if the doc should rename chapters 8/9/15/16/17/18/21/22 to "planned" or keep the 19-module claim with annotation.
2. **OV-15 / NF-1 / NF-3 / NF-7**: NFR claims (RPO/RTO, 20k accounts, 2k concurrent, load test) are unbinding without harness/scripts. Confirm whether Phase B writes the harness or downgrades the claim to "objectives" only.
3. **CM-5 / CM-7 / CM-8 / CM-10 / CM-11**: Several declared cross-module dependencies in `context-map.md` are not implemented (no Audit, no RecordsGovernance imports). Decide whether to drop these arrows or add phase tags.
4. **MC-15 / MC-16**: Tasks and WorkRecords have no `Contracts/` directory; doc names are handler classes. Decide if the doc should rewrite §13/§14 to "contract-style handler classes" or whether the modules need a Contracts layer.
5. **AUTH-1 / AUTH-2**: Mermaid diagrams use `ResolveActiveIdentity` / `ResolveOrganizationScope` while contracts are `ResolvePrincipalContext` / `ResolveOrganizationScopeAncestry`. Phase B rename or accept alias.
6. **OUT-3 / OUTX-4 / OUTX-5**: No outbox-driven Search or Audit consumers in code. Confirm whether §10.2 envelope claims are aspirational or unsupported.
7. **CM-12 / CM-16**: Notifications.Search.Reporting consume events from `Strategy`, `PortfolioProjects`, `Risk`; no consumers exist. Either drop the arrows or annotate planned.
8. **CM-14**: `Tasks` row in dependency matrix claims `RecordsGovernance` and `Audit` dependencies; no code proves this.
9. **OV-7 / OV-8 / MC-22**: Workspace and Risk modules are planned only; confirm Phase B drift-suppression approach (turn the chapters into "planned" or mark as "to be implemented in R3").
