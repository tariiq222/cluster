# Domain documentation code audit

TOTAL=17 RESOLVED=0 ACCEPTED=6 OPEN=11

Scope: `docs/domain/*` was compared with the canonical reference and the implemented modules under `apps/api/Modules/*` (Contracts, Domain, Features, Infrastructure/Persistence/Migrations, Tests). `DRIFT-RESOLVED` means the documented claim matches the code; `DRIFT-ACCEPTED` is a planned/policy-only claim that is not an implementation assertion; `DRIFT-OPEN` means the document states an implemented/runtime fact contradicted by code.

Note: Strategy, PortfolioProjects, Risk, Audit, RecordsGovernance, Workspace, and Collaboration have no `apps/api/Modules/<M>/` directory. Their spec docs are classified `DRIFT-ACCEPTED`, but any wrong factual claim inside those docs is also flagged `DRIFT-OPEN`.

## docs/domain/README.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Module index lists 16 modules. The 12 implemented modules are present, and the remaining (Strategy, PortfolioProjects, Risk, Audit, RecordsGovernance) are planned for R2/R3 as stated. No module under `docs/domain/` is implemented yet for those. | `README.md:17-31`; directory listing under `apps/api/Modules/`; canonical reference "KEY GAPS" |

## docs/domain/platform-settings.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc states `business_calendar_days` table with columns `calendar_id`, `calendar_date`; the actual schema has `business_calendar_weekdays` (weekday-based) plus `business_calendar_exceptions` (date-based), not a unified `business_calendar_days`. | `platform-settings.md:31`; `apps/api/Modules/PlatformSettings/Infrastructure/Persistence/Migrations/CreatePlatformSettingsTables.php:50-77` |
| DRIFT-OPEN | Doc states `business_calendars` "active calendar one per scope at a time". The migration does not constrain a single active calendar per scope; status is a free `string(16)` and there is no partial unique index per `(scope_type, scope_id, status)`. | `platform-settings.md:30`; `CreatePlatformSettingsTables.php:37-49` |
| DRIFT-OPEN | Doc lists `CalculateBusinessDueAt` query contract. Only `ResolveBusinessCalendar` and `GetEffectivePlatformSettings` exist in `Contracts/`. | `platform-settings.md:42`; `apps/api/Modules/PlatformSettings/Contracts/` |
| DRIFT-OPEN | Doc's Commands list (`CreatePlatformSettingsDraft`, `SetPlatformSetting`, `PublishPlatformSettingsVersion`, `CreateBusinessCalendar`, `SetBusinessCalendarDay`) does not map to the only two feature handlers (`PlatformSettingsHandler`, `BusinessCalendarHandler`). Several command names in the doc have no implementation path. | `platform-settings.md:38-40`; `apps/api/Modules/PlatformSettings/Features/Settings/Handler/PlatformSettingsHandler.php`; `apps/api/Modules/PlatformSettings/Features/Calendars/Handler/BusinessCalendarHandler.php` |
| DRIFT-OPEN | Doc's `SettingsVersion` state machine is `Draft -> Validated -> Published -> Retired`; `BusinessCalendar: Draft -> Published -> Superseded`. Code accepts the same four `SettingsVersion` statuses, but for `business_calendars` no `Superseded` string is enforced; the handler treats only `'published'` as the active state (`where('status', 'published')`). | `platform-settings.md:48`; `apps/api/Modules/PlatformSettings/Domain/SettingsVersion.php:22-23`; `apps/api/Modules/PlatformSettings/Infrastructure/Persistence/DatabaseBusinessCalendars.php:165-169` |

## docs/domain/identity.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc states `users.id CHAR(36) UUIDv7 PK`. Migration uses `$table->uuid('id')` (UUID, not specifically UUIDv7) and the column type is `char(36)` only by Laravel's default `uuid()` mapping; nothing in the schema enforces UUIDv7. The doc's stronger claim of UUIDv7 cannot be verified at the migration level. | `identity.md:111`; `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php:11-15` |
| DRIFT-OPEN | Doc states `account_recovery_events` table with `requested_by_user_id CHAR(36) UUIDv7 NOT NULL FK -> users.id`. The migration set for Identity does not create an `account_recovery_events` table at all. The only adjacent tables are `identity_idempotency_keys`, `identity_inbox`, `identity_person_account_claims`, `identity_person_event_watermarks`, `identity_person_provisioning`, `credentials`, `identity_password_history`, `identity_activation_tokens`, `identity_totp`, `identity_auth_attempt_ledgers`. | `identity.md:149-159`; `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/` (whole directory) |
| DRIFT-OPEN | Doc describes `UserIdentitySummary`, `AccountRecoveryEvent`, `PasswordPolicySnapshot`, `SessionFingerprint` aggregates. The Domain/ folder only contains `PasswordPolicy` and `UserAccount`. No aggregate or value object for the recovery event, identity summary, or session fingerprint exists. | `identity.md:65-91`; `apps/api/Modules/Identity/Domain/` |
| DRIFT-OPEN | Doc claims a `credentials` table with `user_id CHAR(36) FK -> users.id ON DELETE CASCADE` and unique `(user_id)`. Actual migration creates `credentials` with `$table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete()` — the unique is on `user_id` itself, but a single FK-based unique matches a 1:1 contract; OK. The doc additionally says "فهرس: `(user_id, password_changed_at)`" but migration only declares the implicit FK index; no composite index. | `identity.md:130-138`; `apps/api/Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php:22-34` |
| DRIFT-OPEN | Doc's `Commands` list contains `CreateAccountRecoveryEvent`, `CompleteAccountRecovery`. No such command/handler exists in `Features/` (the existing feature folders are `Activation`, `Authentication`, `ConsumeOrganizationPersonEvents`, `Credentials`, `ResolveDevelopmentFixturePrincipal`, `Sessions`, `Totp`, `UserAccount`). | `identity.md:163-164`; `apps/api/Modules/Identity/Features/` |

## docs/domain/authorization.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc says `roles.id BIGINT PK`. Migration uses `$table->uuid('id')->primary()` (UUID PK, not BIGINT). Same issue for `capabilities.id`, `role_assignments.id`, `delegations.id`, `classification_policies.id` (which is `$table->id()` — BIGINT, OK), `field_access_templates.id` (BIGINT, OK). | `authorization.md:171-178, 213, 218, 222, 230, 236`; `CreateAuthorizationRbacDataTables.php:12-77`; `CreateAuthorizationFieldAuditTables.php:12-35` |
| DRIFT-OPEN | Doc says `role_assignments.scope_type VARCHAR(16) NOT NULL` and `scope_id BIGINT NOT NULL`. Actual schema has only `scope_id` (UUID, nullable) — no `scope_type` column at all. | `authorization.md:209-220`; `CreateAuthorizationRbacDataTables.php:58-69` |
| DRIFT-OPEN | Doc says `delegations` has `capability_set JSON NOT NULL`, `scope_type VARCHAR(16) NOT NULL`, `scope_id BIGINT NOT NULL`, `reason VARCHAR(500) NOT NULL`, and check constraint `delegator_user_id <> delegate_user_id`. Actual schema uses `module_code VARCHAR(64)` (not `capability_set JSON`), `scope_id` is UUID nullable (no `scope_type`), and there is no `reason` column and no delegator/delegate inequality check. | `authorization.md:222-234`; `CreateAuthorizationRbacDataTables.php:78-92` |
| DRIFT-OPEN | Doc's `Queries` list names `BuildAuthorizedScopePredicate`, `ResolveFieldAccess`, `ExplainAccessDecision`, `FilterReadableOrganizationScopes`, `GetActiveRoleAssignments`, `GetActiveDelegations`, `GetCapabilitiesForContext`, `GetClassificationPolicy`, `GetFieldAccessTemplate`. Only `DecideAccess`, `AccessDecision`, `RecordFacts`, `AccessProjection`, `PersistAccessDecision`, `CapabilityCatalog`, `CountOperationsOfficeMembers`, `AuthorizationResourceReference`, `ResolveAuthorizationSimulationFacts`, `AuthorizationSimulationFactsProvider` exist as `Contracts/`. The HTTP ExplainAccessDecision controller is present but there is no contract interface. | `authorization.md:264-280`; `apps/api/Modules/Authorization/Contracts/` |
| DRIFT-OPEN | Doc says `AccessDecision` state machine has a `Requested` state. Actual code uses binary Allow/Deny on `access_decisions.decision VARCHAR(8)`; no `Requested` status. | `authorization.md:296-303`; `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/ZAddAuthorizationHttpTables.php:30-50` |
| DRIFT-OPEN | Doc says `sensitive_access_events` is in Audit module; canonical reference places it inside Authorization. The migration file lives at `apps/api/Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php:37-57`. This is a location/ownership divergence from the doc. | `authorization.md:36` (claims access decisions only); `CreateAuthorizationFieldAuditTables.php:37-57` |

## docs/domain/audit.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Module not implemented; Audit tables (`audit_events`, `audit_hash_links`, `audit_checkpoints`) and commands do not exist in code. | `audit.md:23-26`; absence under `apps/api/Modules/Audit/`; canonical reference "KEY GAPS" |
| DRIFT-OPEN | Doc claims `sensitive_access_events` table inside Audit. The only sensitive-access persistence is in Authorization (`CreateAuthorizationFieldAuditTables.php:37-57`). If Audit is the future owner, doc should be marked as declaring a target ownership that contradicts current placement. | `audit.md:23`; `CreateAuthorizationFieldAuditTables.php:37-57` |

## docs/domain/work-definitions.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc describes six tables (`work_type_definitions`, `work_type_versions`, `field_definitions`, `definition_rules`, `relation_definitions`, `form_layouts`, `definition_test_cases`, `definition_signatures`) plus a `DefinitionPackage`. Only `work_definitions` and `work_definition_versions` exist (with a development-fixtures table). The other seven tables are entirely absent. | `work-definitions.md:144-264`; `apps/api/Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateWorkDefinitionTables.php:11-48` |
| DRIFT-OPEN | Doc's `WorkTypeVersion` state machine is `draft -> tested -> approved -> signed -> published`. Actual schema uses `status VARCHAR(16) DEFAULT 'draft'` with no `definition_state` column and no Tested/Approved/Signed status. There are no migration columns corresponding to `approved_at`, `signed_at`, `signed_by_user_id`, `signer`, `key_id`, `signature`. | `work-definitions.md:297-308`; `CreateWorkDefinitionTables.php:21-35` |
| DRIFT-OPEN | Doc claims `work_type_versions.schema_document JSON` and `schema_hash CHAR(64)`. Actual column is `schema_document JSON` (matches) and `schema_hash CHAR(64)` (matches); but the doc also says `dsl_version VARCHAR(16)` which is **missing** in the actual schema. | `work-definitions.md:155-156`; `CreateWorkDefinitionTables.php:21-35` |
| DRIFT-OPEN | Doc says `work_type_definitions.created_by_user_id BIGINT NOT NULL`. Actual uses `$table->uuid('created_by_user_id')`. Same PK type drift across this module's tables. | `work-definitions.md:144-153`; `CreateWorkDefinitionTables.php:11-19` |

## docs/domain/workflow.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc lists tables `workflow_nodes`, `workflow_transitions`, `workflow_approver_snapshots`, `workflow_failures`. None of these exist as `Schema::create` migrations. The actual module has `workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_step_instances`, `workflow_decisions`, `workflow_idempotency_keys`. | `workflow.md:267-336`; `apps/api/Modules/Workflow/Infrastructure/Persistence/Migrations/` |
| DRIFT-OPEN | Doc says `workflow_definitions` has columns `name_ar`, `name_en`, `owner_scope_type`, `owner_scope_id`, `status`, `created_by_user_id`. Actual table only has `id`, `code`, `source_record_type`, `created_by_user_id`, `timestamps`. No `name_ar`/`name_en`/`status` columns. | `workflow.md:268-275`; `CreateWorkflowTables.php:11-17` |
| DRIFT-OPEN | Doc's `WorkflowInstance` state machine `Created -> Running -> Completed/Cancelled/Failed`. Actual default state is `'running'`, terminal is `'completed'`. Code uses `state='running'` immediately on creation and only transitions to `completed`/`cancelled` via handler; no `Failed` state observed. `workflow_idempotency_keys` is also missing from the doc's table list. | `workflow.md:312-318`; `CreateWorkflowTables.php:33-47`; `StartWorkflowHandler.php:23-25`; `AdvanceAfterDecision.php:40-44` |
| DRIFT-OPEN | Doc says `workflow_steps.{stepInstance}` state machine `Pending -> Active -> Completed/Returned/Escalated`. Actual step states observed: `'waiting'`, `'active'`, `'completed'`, plus inbox states `'rejected'`, `'returned'`, `'cancelled'`. The migration default is `state VARCHAR(24)` and the runtime starts in `'waiting'`, not `'Pending'`. | `workflow.md:320-329`; `CreateWorkflowTables.php:49-63`; `ListApprovalInbox.php:13`; `StartWorkflowHandler.php:67-69` |
| DRIFT-OPEN | Doc's `WorkflowVersion` state machine `Draft -> Tested -> Approved -> Signed -> Published`. Code uses `definition_state` plus `approval_status` and `review_state` (added in W17). The lifecycle documented is not the lifecycle the migrations enforce. | `workflow.md:303-310`; `W17AddApprovalColumnsToWorkflowVersions.php:30-37`; `W15CreateWorkflowDecisionsTable.php:84-86` |
| DRIFT-OPEN | Doc says `workflow_decisions` has `decision VARCHAR(24)` with values `approve, reject, return, accept, decline` and an FK to `workflow_approver_snapshots.id`. Actual `workflow_decisions` table has `decision VARCHAR(16)` (no `accept`/`decline` documented but values constrained narrower), no FK to any snapshot table (because no snapshot table exists). | `workflow.md:325-336`; `W16CreateWorkflowDecisionsTable.php:11-26` |
| DRIFT-OPEN | Doc's `Queries` list (`GetPublishedWorkflowVersion`, `ValidateWorkflowGraph`, `GetWorkflowInstanceState`, `GetActiveWorkflowSteps`, `GetApproverSnapshot`, `ListMyPendingApprovals`, `GetWorkflowDecisions`, `GetWorkflowFailure`, `ExplainApproverResolution`, `CheckWorkflowCompatibility`) has no corresponding `Contracts/` files. Only `AdvanceWorkflowStep`, `ResolveStepAssignee`, `ResolveWorkflowSourceAuthorizationFacts`, `RuleContext`, `RuleSpec`, `WorkflowSourceReference` exist. | `workflow.md:284-294`; `apps/api/Modules/Workflow/Contracts/` |

## docs/domain/work-records.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc claims `work_records` has `work_type_id BIGINT`, `work_type_version_id BIGINT`, `owner_scope_type VARCHAR(16)`, `owner_scope_id BIGINT`, `owner_facility_id BIGINT NULL`, `owner_organization_unit_id BIGINT NULL`, `owner_user_id BIGINT NULL`, `responsible_user_id BIGINT NULL`, `created_by_user_id BIGINT NOT NULL`, `classification VARCHAR(32)`, `submitted_at`, `completed_at`, `created_at`/`updated_at`. Actual `work_records` schema has only `id`, `record_number`, `work_type_version_id` (UUID), `owner_facility_id` (UUID), `creator_user_id` (UUID), `status`, `classification`, `payload` (JSON), `lock_version`, `submitted_at`, `timestamps`. The columns `work_type_id`, `owner_scope_type`, `owner_scope_id`, `owner_organization_unit_id`, `owner_user_id`, `responsible_user_id`, `created_by_user_id`, `completed_at` are missing; the creator column is named `creator_user_id` (not `created_by_user_id`). | `work-records.md:113-127`; `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php:11-25` |
| DRIFT-OPEN | Doc says payload/relations/projections/participants/activities live in separate tables (`work_record_payloads`, `work_record_field_projections`, `work_record_relations`, `work_record_participants`, `work_record_activities`). None of these tables exist; payload is stored as a single JSON column on `work_records`. | `work-records.md:135-211`; full migration directory under `apps/api/Modules/WorkRecords/Infrastructure/Persistence/Migrations/` |
| DRIFT-OPEN | Doc says `work_record_idempotency_keys` has `facility_id`. Migration has no `facility_id` column; columns are `principal_id`, `operation`, `key_hash`, `request_hash`, `resource_id`. | `work-records.md:225-228`; `CreateWorkRecordsTable.php:27-36` |
| DRIFT-OPEN | Doc's Commands list includes `SaveWorkRecordDraft`, `UpdateWorkRecordDraft`, `AddWorkRecordRelation`, `AddWorkRecordParticipant`, `RemoveWorkRecordParticipant`, `ReturnWorkRecordForRevision`, `RejectWorkRecord`, `StartWorkRecordProcessing`, `TransferRecordOwnership`, `UpdateWorkRecordClassification`. Only `GetAuthorizedWorkRecord`, `ListAuthorizedWorkRecords`, `SubmitWorkRecord` features exist. Most of the doc's commands have no handler. | `work-records.md:213-228`; `apps/api/Modules/WorkRecords/Features/` |

## docs/domain/records-governance.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | No `apps/api/Modules/RecordsGovernance/` directory exists. All tables (`retention_policy_versions`, `retention_rules`, `governed_records`, `record_holds`, `record_hold_targets`, `disposition_reviews`, `disposition_evidence`) and commands are planned-only. | `records-governance.md:21-25`; absence in `apps/api/Modules/` |

## docs/domain/documents.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc claims `documents.restriction_facts JSON NOT NULL`. The column exists but is `nullable()` (added in `W18CreateDocumentGovernanceTables.php:11-13`). NOT NULL is contradicted. | `documents.md:148-152`; `W18CreateDocumentGovernanceTables.php:11-13` |
| DRIFT-OPEN | Doc claims `documents` has `archived_at DATETIME NULL` and indexes on `(owner_organization_unit_id, status)` and `(classification, status)`. Migration does not include `archived_at` and indexes are `(owner_organization_unit_id, status)` and `(classification, status)` — but those two indexes do exist. (Note: only `archived_at` is missing; the index claim is correct.) | `documents.md:152-154`; `CreateDocumentsCoreTables.php:11-31` |
| DRIFT-OPEN | Doc says `documents.retention_policy_key VARCHAR(128) NULL`. Migration includes the column (matches). Doc says `documents.lock_version INT NOT NULL DEFAULT 1`. Migration uses `$table->unsignedInteger('lock_version')->default(1)` (matches). These match; the previous finding stands for `restriction_facts` and `archived_at`. | `documents.md:151-152`; `CreateDocumentsCoreTables.php:21-30` |
| DRIFT-OPEN | Doc claims `document_versions.sha256 CHAR(64) NOT NULL`. Migration declares `$table->char('sha256', 64)->nullable()` — sha256 is nullable, contradicting the doc. | `documents.md:179-180`; `CreateDocumentsCoreTables.php:53` |
| DRIFT-OPEN | Doc claims `document_links.source_id VARCHAR(128) NOT NULL`. Migration uses `$table->string('source_id', 128)` without explicit `NOT NULL`, but Laravel defaults to nullable on `string()` — so source_id is technically nullable. | `documents.md:194-198`; `W18CreateDocumentGovernanceTables.php:21` |
| DRIFT-OPEN | Doc claims `document_access_events.ip_address VARBINARY(16) NULL` and `user_agent_hash CHAR(64) NULL`. Migration defines `event_id CHAR(36) UNIQUE NOT NULL` plus other columns; the actual migration does not include `ip_address` or `user_agent_hash` columns at all. | `documents.md:222-225`; `W18CreateDocumentGovernanceTables.php:52-66` |
| DRIFT-OPEN | Doc claims `documents.{documentAction}` endpoints `archive, place-hold, release-hold` and `documents/{documentId}/{documentGrantType}-grant  preview, download`. Canonical reference lists `/internal/documents/versions/{id}/scan` and `/internal/documents/versions/{id}/reconcile-promotion` plus the grant endpoints, but `archive`, `place-hold`, `release-hold` are mentioned as documentAction transitions; this is consistent with canonical but the doc says all three live on `documents/{documentId}/{documentAction}` whereas `place-hold` and `release-hold` may have separate internal paths (the migration `W18CreateDocumentGovernanceTables` adds `legal_hold` columns and `document_links` indexes but the doc's hold action wiring is not contradicted in code; flag kept as informational). | `documents.md:281-285`; `apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php:11-13, 22-24` |

## docs/domain/collaboration-tasks-workspace.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc claims `task_assignment_offers`, `task_mentions`, `task_activity`, `workspace_items`, `workspace_projection_checkpoints` tables. None of these tables exist in code. Only `tasks`, `task_idempotency_keys`, `task_participants`, `task_comments` exist. | `collaboration-tasks-workspace.md:118-218`; `apps/api/Modules/Tasks/Infrastructure/Persistence/Migrations/` |
| DRIFT-OPEN | Doc says `tasks.assignee_user_id` is nullable until first offer is accepted (`assignee_user_id BIGINT NULL`). Migration declares `$table->uuid('assignee_user_id')` with no nullable modifier, so it is NOT NULL — contradicting the doc. | `collaboration-tasks-workspace.md:128-130`; `CreateTasksTable.php:16` |
| DRIFT-OPEN | Doc says `tasks` has `assignee_organization_unit_id`, `resolved_completion_approver_user_id`, `source_policy_snapshot`, `cancelled_at`, `completion_approver_strategy`. None of these columns exist; only `workflow_step_id` is added. | `collaboration-tasks-workspace.md:131-141`; `CreateTasksTable.php:11-32` |
| DRIFT-OPEN | Doc says `task_participants.role VARCHAR(24)` with values `creator|assignee|participant|watcher` and `added_via VARCHAR(24)` with values `explicit|mention|assignment|source`. Migration uses `role VARCHAR(64) DEFAULT 'participant'` and `added_by_user_id` (no `added_via`). The richer enum is not enforced. | `collaboration-tasks-workspace.md:160-168`; `W13CreateTaskEngagementTables.php:11-19` |
| DRIFT-OPEN | Doc claims a `Workspace` module exists with `workspace_items` and `workspace_projection_checkpoints`. No such module directory exists. The `ModuleBoundariesTest.php` table owner list references `workspace_items => Workspace`, suggesting this is a planned assignment, but no migration creates it. | `collaboration-tasks-workspace.md:198-218`; absence under `apps/api/Modules/`; `apps/api/tests/Architecture/ModuleBoundariesTest.php:108` |
| DRIFT-ACCEPTED | Collaboration and Workspace modules are planned-only per canonical reference; no `apps/api/Modules/Collaboration/` or `apps/api/Modules/Workspace/`. | `collaboration-tasks-workspace.md:13-23`; canonical reference "KEY GAPS" |

## docs/domain/notifications-search-reporting.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc lists Notifications tables `notifications`, `notification_preferences`, `notification_inbox`. Actual tables are `notifications`, `notification_inbox`, `notification_recipients`, `notification_dead_letters`. There is **no `notification_preferences` table**. | `notifications-search-reporting.md:21-23`; `apps/api/Modules/Notifications/Infrastructure/Persistence/Migrations/` (full directory) |
| DRIFT-OPEN | Doc claims `Search` and `Reporting` modules have contracts listed under their `Commands/Queries/Events` sections. Search has no `Contracts/` directory at all (0 contracts per canonical reference); Reporting also has no `Contracts/` directory. The doc's `GetAuthorizedDashboard`, `RunAuthorizedReport`, `ExportAuthorizedReport`, `RefreshReportingProjection`, `ListMyNotifications`, `SearchAccessibleRecords`, `IndexSourceEvent`, `RemoveIndexEntry` are described as commands/queries but no contract interface files back them. | `notifications-search-reporting.md:26-58`; canonical reference "CONTRACTS" section; `apps/api/Modules/Search/`; `apps/api/Modules/Reporting/` |
| DRIFT-OPEN | Doc says Search checkpoint table is `search_checkpoints (consumer, checkpoint, projection_version, lag_seconds)`. Migration uses `$table->string('consumer', 96)->primary()` plus `checkpoint`, `projection_version`, `last_processed_at` — no `lag_seconds` column at the table level; the doc claims a separate `workspace_projection_checkpoints` has `lag_seconds`. | `notifications-search-reporting.md:21-23, 30-34`; `CreateSearchProjectionTables.php:34-40` |
| DRIFT-OPEN | Doc says Reporting has `report_inbox`, `report_definitions`, `dashboard_definitions`, `report_read_models`, `report_runs`, `export_artifacts`. Migration declares all six — match. Doc also says the only Tables are these six; implementation aligns with this for Reporting. (This row confirms DRIFT-RESOLVED for Reporting tables specifically.) | `notifications-search-reporting.md:21-23`; `CreateReportingProjectionTables.php:12-86` |
| DRIFT-OPEN | Doc claims `Notifications Commands: MarkNotificationRead, UpdateNotificationPreferences, RebuildNotificationProjection`. There is no `UpdateNotificationPreferences` (no preferences table), no `RebuildNotificationProjection` command, no handler under `Features/` that owns these names. Only `ListMyNotifications` HTTP features and `ConsumeTechnicalAlert`/`ConsumeWorkRecordSubmitted` workers exist. | `notifications-search-reporting.md:37-44`; `apps/api/Modules/Notifications/Features/` |

## docs/domain/organization-and-people.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Doc says `clusters.settings JSON NOT NULL`. Migration does not include any `settings` column on `clusters`. | `organization-and-people.md:160-165`; `CreateOrganizationCoreTables.php:11-20` |
| DRIFT-OPEN | Doc says `facilities.settings JSON NOT NULL`. Migration has no `settings` column on `facilities`. | `organization-and-people.md:170-180`; `CreateOrganizationCoreTables.php:30-43` |
| DRIFT-OPEN | Doc says `positions.title_en VARCHAR(255) NULL`. Migration has no `title_en` column on `positions`; only `code`, `title_ar`, `manager_position_id`, `is_active`. The doc also says `level SMALLINT NULL` — also absent. | `organization-and-people.md:215-225`; `CreateOrganizationTreeTables.php:40-55`; `W2AddOrganizationJobTitlesTable.php:26-28` |
| DRIFT-OPEN | Doc claims `supervisory_relationships.source_unit_id`, `source_person_id`, `target_unit_id`, `target_person_id`, `relationship_type`, `start_at`, `end_at`, `created_by_user_id`. Migration uses `source_organization_unit_id` and `target_organization_unit_id` (no person-side columns), `valid_from`/`valid_until` (not `start_at`/`end_at`), no `created_by_user_id`. The actual table is unit-to-unit only. | `organization-and-people.md:243-260`; `ZCreateOrganizationSupervisoryRelationshipTables.php:12-32` |
| DRIFT-OPEN | Doc says `assignments.start_at DATETIME(3) UTC NOT NULL` and `start_at < end_at` constraint. Migration uses `dateTime('start_at', 3)` (no UTC enforcement — Laravel defaults to UTC if `app.timezone` is UTC, but the migration itself does not enforce UTC at the DB level). No `start_at < end_at` check constraint is declared. | `organization-and-people.md:230-238`; `CreateOrganizationWorkforceAssignmentsTable.php:11-25` |
| DRIFT-OPEN | Doc's table `import_rows` claims `proposed_action` and `proposed_target_id` and `validation_errors`. Migration defines `encrypted_payload`, `proposed_action`, `decision`, `applied_at` — but no `proposed_target_id`, no `validation_errors`. The doc's column names do not fully match. | `organization-and-people.md:295-309`; `CreateOrganizationZImportTables.php:32-49` |
| DRIFT-OPEN | Doc's `people` schema is mostly matched, but doc says `person_version BIGINT NOT NULL DEFAULT 1` — migration uses `unsignedBigInteger('person_version')->default(1)` (matches). The doc also says `people.created_at`/`updated_at` exist, which is true. This row confirms `people` table is consistent. | `organization-and-people.md:200-214`; `CreateOrganizationPeopleTable.php:11-23` |
| DRIFT-OPEN | Doc says `id CHAR(36) UUIDv7 PK` for `clusters`, `facilities`, `organization_units`, `positions`, `people`, `assignments`, `supervisory_relationships`, `relationship_capabilities`, `import_jobs`, `import_rows`. Migration uses `$table->uuid('id')->primary()` which is UUID but not specifically UUIDv7. The doc's stronger UUIDv7 claim cannot be verified at migration level. | `organization-and-people.md:160, 169, 188, 215, 200, 230, 243, 264, 276, 298`; all migrations use `$table->uuid('id')->primary()` |

## docs/domain/organization-tree-quickref.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-OPEN | Quickref says `organization_units.parent_id` and `parent_type` together identify the father; valid `parent_type` values are `cluster|facility|unit`. Migration defines `parent_type VARCHAR(16)` and a unique `(parent_type, parent_id, code)` — matches. The quickref also says `parent_id` is `UUID` — migration uses `$table->uuid('parent_id')` (matches). | `organization-tree-quickref.md:23-31`; `CreateOrganizationTreeTables.php:19-37` |
| DRIFT-OPEN | Quickref references `manager_position_id` self-reference on `positions` with FK enforced "in domain" (no DB constraint). Migration declares `$table->foreign('manager_position_id')->references('id')->on('positions')->restrictOnDelete()` — there IS a DB constraint, contradicting the quickref's "the database does not enforce this". | `organization-tree-quickref.md:51, 70`; `CreateOrganizationTreeTables.php:51-53` |
| DRIFT-OPEN | Quickref mentions a denormalized `sort_order INTEGER DEFAULT 0` column and index `organization_units_sibling_order_index` on `(parent_type, parent_id, sort_order)`. This column lives in `ZCreateOrganizationUnitsSortOrderTable.php`, not `CreateOrganizationTreeTables.php`. The migration does exist, but the quickref calls it part of the tree core tables without naming the separate file. | `organization-tree-quickref.md:91-100`; `apps/api/Modules/Organization/Infrastructure/Persistence/Migrations/ZCreateOrganizationUnitsSortOrderTable.php` |
| DRIFT-OPEN | Quickref says "Facilities → clusters" is `restrictOnDelete`. Migration uses `$table->foreignUuid('cluster_id')->constrained('clusters')->restrictOnDelete()` (matches). It also says "organization_units → unit_types" is `restrictOnDelete`. Migration uses `$table->foreignUuid('unit_type_id')->constrained('unit_types')->restrictOnDelete()` (matches). The "polymorphic parent enforced in domain" claim is correct (no FK to `clusters`/`facilities`/`organization_units`). | `organization-tree-quickref.md:39-48`; `CreateOrganizationTreeTables.php:19-37` |
| DRIFT-RESOLVED | The doc claims `unique (organization_unit_id, code)` on positions and `unique (parent_type, parent_id, code)` on organization_units and `unit_types.code` unique — all present in migrations. | `organization-tree-quickref.md:73-79`; `CreateOrganizationTreeTables.php:34, 49` |

## docs/domain/strategy.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Module not implemented. No `apps/api/Modules/Strategy/` directory. The doc's tables, commands, queries, and events are planned-only. | `strategy.md:23-218`; absence under `apps/api/Modules/`; canonical reference "KEY GAPS" |
| DRIFT-OPEN | Doc's `id BIGINT PK` claim cannot be verified because no migration exists. The doc also claims `strategic_plans.owner_organization_unit_id BIGINT NOT NULL` — same issue, no implementation to confirm against. This is informational only because Strategy is planned. | `strategy.md:99-108` |

## docs/domain/portfolio-projects.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Module not implemented. No `apps/api/Modules/PortfolioProjects/` directory. Tables and commands are planned-only. | `portfolio-projects.md:30-50`; absence under `apps/api/Modules/`; canonical reference "KEY GAPS" |

## docs/domain/risk.md

| Classification | Finding | Evidence |
|---|---|---|
| DRIFT-ACCEPTED | Module not implemented. No `apps/api/Modules/Risk/` directory. Tables and commands are planned-only. | `risk.md:24-46`; absence under `apps/api/Modules/`; canonical reference "KEY GAPS" |

## Cross-cutting summary

- **Bigint vs UUID drift**: Identity, Authorization, WorkRecords, WorkDefinitions, Organization all use `uuid('id')` while docs claim `BIGINT PK`/`CHAR(36) UUIDv7`. The docs' UUIDv7 claim cannot be verified at the migration level.
- **Missing sub-tables**: work_records (payloads/projections/relations/participants/activities), work_definitions (field_definitions/rules/relations/layouts/tests/signatures), workflow (nodes/transitions/approver_snapshots/failures), tasks (assignment_offers/mentions/activity), collaboration (workspace_items/checkpoints) are documented but not implemented.
- **Missing contracts**: Authorization (BuildAuthorizedScopePredicate/ResolveFieldAccess/ExplainAccessDecision/etc.) and Workflow (GetPublishedWorkflowVersion/ValidateWorkflowGraph/etc.) have no `Contracts/` interface files. Search and Reporting have **zero** contracts.
- **State machine drift**: Workflow (`running`/`waiting` instead of `created`/`pending`) and BusinessCalendar (`'superseded'` documented but not used in code) show divergence.
- **Missing columns**: `documents.archived_at`, `documents.restriction_facts NOT NULL` (actually nullable), `documents.ip_address`/`user_agent_hash`, `task_assignee_user_id NULL` (actually NOT NULL), `tasks.assignee_organization_unit_id`, `positions.title_en`/`level`, `clusters.settings` JSON, `facilities.settings` JSON, `supervisory_relationships` person-side columns.
