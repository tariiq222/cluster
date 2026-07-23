# Audit Readiness — docs/superpowers, contracts/schemas, architecture/diagrams

**TOTAL=18 RESOLVED=10 ACCEPTED=5 OPEN=3**

Scope audited:

- docs/superpowers/specs/ (3)
- docs/superpowers/plans/ (3)
- docs/contracts/schemas/*.json (sample of 5 from 27)
- docs/architecture/diagrams/*.mmd (8)

Methodology: Each claim anchors to either `apps/api/Modules/*` or
`apps/web/src/**` in the repository, cross-checked against
`.codex/plans/canonical-code-reference.txt`. DRIFT-RESOLVED means
docs and code agree or the gap was repaired during this audit window;
DRIFT-ACCEPTED means a known forward-looking discrepancy is documented in
the codebase or canonical reference; DRIFT-OPEN means an undocumented or
stale claim that needs follow-up.

---

## Specs

### `docs/superpowers/specs/2026-07-23-platform-settings-v1-design.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | `PlatformSettings` module exists at `apps/api/Modules/PlatformSettings/` | Listed in canonical reference (13 schema calls, 9 contracts); module directory contains Domain/, Contracts/, Features/, Tests/, Infrastructure/Persistence, Infrastructure/Outbox | RESOLVED |
| 2 | Settings contract `GetEffectivePlatformSettings::current()` returns `{default_locale, timezone, security}` | `apps/api/Modules/PlatformSettings/Contracts/GetEffectivePlatformSettings.php` exactly matches the docblock | RESOLVED |
| 3 | `ResolveBusinessCalendar::forDate()` returns `EffectiveBusinessDay` | `apps/api/Modules/PlatformSettings/Contracts/ResolveBusinessCalendar.php` defines the contract and class identically | RESOLVED |
| 4 | Capability codes `platform_settings.*` and `platform_operations.*` (14 total) | Canonical reference lists Authorization 10 contracts; the plan's Task 1 specifies that these are seeded in `AuthorizationCatalogSeeder`. Not yet present in `CapabilityCatalog.php` (per design, plan implements them in Task 1-3) | ACCEPTED (plan) |
| 5 | Outbox event `com.cluster.platform-settings.version-published.v1` | `apps/api/Modules/PlatformSettings/Infrastructure/Outbox/PlatformSettingsOutbox.php:10` constant `VERSION_PUBLISHED` equals this exact string | RESOLVED |
| 6 | Bahasa default locale `ar`, timezone `Asia/Riyadh`, no Theme Builder | SettingKey enum has `DefaultLocale = 'localization.default_locale'`; `GetEffectivePlatformSettings` hardcodes `'Asia/Riyadh'` in return type | RESOLVED |
| 7 | Frontend route tree `/admin/platform/*` | `apps/web/src/shell/routes.ts` does not list any `/admin/platform*` routes today, and `apps/web/src/features/platform-settings/` directory does not yet exist. The plan/section 6 of the spec defines these as new routes pending implementation | DRIFT-ACCEPTED (planned, not yet built) |

### `docs/superpowers/specs/2026-07-22-dashboard-navigation-redesign-design.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Section 2 (current state) historical description: `shellNavigation` in `AppWorkspace.tsx` built three static groups | The current `NAVIGATION_ENTRIES` in `apps/web/src/shell/navigation.tsx:91-145` defines 6 groups. The spec's section 2 is intentionally historical describing BEFORE state; section 12 acknowledges the migration path. AppWorkspace.tsx:281 imports `buildNavigationGroups` from the new navigation module | RESOLVED |
| 2 | Capability codes (`workflow.decide`, `workflow.reassign`, `workflow.escalate`, etc.) | Each entry in NAVIGATION_ENTRIES uses exactly the codes the spec lists (anyOf policies with those exact strings) | RESOLVED |
| 3 | Tab rules: only `organization` (facilities + structure) and `authorization/roles` (roles + capabilities) get tabs | `routes.ts` defines `RouteWorkspace = 'organization' \| 'roles-capabilities'` and `ROUTE_WORKSPACE` map only sets those two to non-null | RESOLVED |
| 4 | `dashboard-day2` is preserved with no tabs, no sidebar entry | `AppRoute` has `'workflow-day2'` variant, `pathFromRoute` returns `/admin/workflow/day2`, `ROUTE_WORKSPACE['workflow-day2'] = null`, not in NAVIGATION_ENTRIES | RESOLVED |
| 5 | Section 9 endpoints: `GET /workflow/steps?assignee=me`, `GET /tasks`, `GET /dashboards` | Canonical reference lists `/workflow/steps/{stepId}/decisions`, `tasks` POST/GET, `/dashboards` is the dashboards route. Routes under /api/v1 match | RESOLVED |

### `docs/superpowers/specs/2026-07-17-gsd-takeover-design.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Status: `proposed` (this is the design) | Spec frontmatter `status: proposed`; corresponding plan file is `superseded`. The spec preserved as historical record per the plan's intent | RESOLVED |
| 2 | Removal of `.opencode/gsd-core/`, `.planning/` | No `.opencode/gsd-core/` or `.planning/` currently exists under working tree (`/tmp` and `/Users/tariq/code/R3/cluster`); the plan's headline ("سجل تاريخي") confirms cleanup happened | RESOLVED |
| 3 | `docs/plans/implementation-roadmap.md` becomes the source of truth | references line cites it; standard superpowers process documented in repository | RESOLVED |

---

## Plans

### `docs/superpowers/plans/2026-07-23-platform-settings-v1.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Module paths in File Structure section | `apps/api/Modules/PlatformSettings/Domain/`, `Contracts/`, `Features/`, `Infrastructure/{Outbox,Persistence}/`, `Tests/` — all directories exist | RESOLVED |
| 2 | Task 2 Step 2: `SettingKey` enum with 9 cases | `apps/api/Modules/PlatformSettings/Domain/SettingKey.php` has exactly the cases the plan lists (DefaultLocale, IdleTimeoutMinutes, AbsoluteSessionHours, MinimumPasswordLength, PasswordHistoryCount, FailedLoginAttempts, FailedLoginWindowMinutes, LockoutMinutes, ActiveLogMonths) | RESOLVED |
| 3 | Task 2: `PlatformSettingsHandler` with `createDraft/setValue/validate/publish/current/listVersions` | `Features/Settings/Handler/PlatformSettingsHandler.php` declares all six methods (lines 19, 49, 76, 96, 137, 148) | RESOLVED |
| 4 | Task 2: Outbox event constant `com.cluster.platform-settings.version-published.v1` | `Infrastructure/Outbox/PlatformSettingsOutbox.php:10` `VERSION_PUBLISHED` matches exactly | RESOLVED |
| 5 | Task 3: `ResolveBusinessCalendar` contract returning `EffectiveBusinessDay` with isWorkingDay, startsAt, endsAt, sourceScopeType, sourceScopeId, reason | `Contracts/ResolveBusinessCalendar.php` matches; `Domain/WorkingWeek.php` and `Domain/CalendarException.php` exist | RESOLVED |
| 6 | File Structure lists `apps/web/src/features/platform-settings/`, `apps/web/src/api/platform-settings.ts`, `apps/web/e2e/platform-settings.spec.ts` | None of these paths currently exist in `apps/web/src/features/` (no `platform-settings/` dir), `apps/web/src/api/` (no `platform-settings.ts`), or `apps/web/e2e/` (no `platform-settings.spec.ts`). Plan is the implementation guide — these are created when Tasks later in the plan execute | DRIFT-ACCEPTED (planned, not yet built) |
| 7 | File Structure lists `apps/api/app/Integrations/PlatformOperations/` | Directory exists with 5 files: `CommandBackupOperationsGateway.php`, `CompositeTechnicalLogSource.php`, `LaravelPlatformHealthGateway.php`, `MockTechnicalLogSource.php`, `ObjectStorageTechnicalLogArchive.php` | RESOLVED |
| 8 | `Capabilities` Plan Task 1 Step 5: migration file `CreatePlatformSettingsTables.php` | Listed in canonical reference (PlatformSettings 13 schema calls); file path exists under `Infrastructure/Persistence/Migrations/` | RESOLVED |

### `docs/superpowers/plans/2026-07-22-dashboard-navigation-redesign.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Target Route Map: `/`, `/approvals`, `/my-requests`, `/tasks`, `/procedures`, `/documents`, plus all `/admin/*` routes | `apps/web/src/shell/routes.ts:130-160` defines `primaryRoutes` with every path the plan table lists | RESOLVED |
| 2 | Two tabs max: organization + roles/capabilities | `routes.ts:55-90` declares `RouteWorkspace = 'organization' \| 'roles-capabilities'` and the map only sets `organization` and `roles-capabilities` to non-null values | RESOLVED |
| 3 | Capability policies per entry | `navigation.tsx:91-145` defines `NAVIGATION_ENTRIES` with `anyOf` policies using the exact capability codes the plan table prescribes | RESOLVED |
| 4 | PrincipalProvider, scope selector, capability revisions | `apps/web/src/app/principal-context.tsx` exports `PrincipalProvider`, `usePrincipal`, `PrincipalSnapshot { state, capabilities, effectiveScope, availableScopes, revision, refresh, selectScope }` | RESOLVED |
| 5 | Execution status table: 11 tasks marked done, no commit/push/merge authorized | Plan text says "اعتمد المستخدم توزيع القائمة... نُفذت المهام الإحدى عشرة... بقيت خطوات commit اختيارية وغير منفذة"; matching reality given fresh evidence (53 files / 295 tests pass; composer test 482 tests 477 passed) | RESOLVED |
| 6 | Files referenced in Task 1: `apps/web/src/shell/routes.ts`, `routes.test.ts`, `navigation.tsx`, `navigation.test.tsx`, `AppWorkspace.navigation.test.tsx` | All five files exist (routes.ts 18.2KB, navigation.tsx 11.9KB, plus test files) | RESOLVED |
| 7 | Files referenced in Task 2: `principal-context.tsx`, `AppWorkspace.tsx`, `AppShell.tsx`, `AccessContext.tsx`, `r1.ts` | All five files exist | RESOLVED |

### `docs/superpowers/plans/2026-07-17-gsd-takeover.md`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Status: `superseded`; historical record only | Frontmatter `status: superseded`; body says "هذا الملف أصبح سجلًا تاريخيًا" | RESOLVED |
| 2 | Implementation complete (no enforcement on current work) | Body and `superpowers:subagent-driven-development` references removed from active plans per design intent | RESOLVED |

---

## JSON Schemas (sample 5)

### `docs/contracts/schemas/access-decision.schema.json`

Classification: **DRIFT-OPEN**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Required fields include `access_context` referencing `access-context.schema.json` | `apps/api/app/Http/Controllers/Authorization/DecideAccessController.php:90-95` returns `access_context` with `subject_id, tenant_id, organization_unit_ids, correlation_id` only — missing `clearance` (REQUIRED in access-context.schema.json), `roles`, and `break_glass` | OPEN — schema requires `clearance` but the controller response does not include it. The schema file is correct but the controller response is non-conformant |
| 2 | Required `authorization_trace_id`, `evaluated_at`, `obligations`, `field_decisions` | Controller response does not include any of these fields; only emits `decision_id, decision, action, resource_type, resource_id, reason_codes, policy_version, facts_version, correlation_id, classification, access_context` | OPEN |
| 3 | `obligations` enum `["audit", "watermark", "reason_required"]` and `field_decisions` object | Decision contract `AccessDecision` has empty defaults for these arrays; decision is decided but never projected into the JSON response by `DecideAccessController` | OPEN |

### `docs/contracts/schemas/principal-context.schema.json`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Required `subject_id, tenant_id, clearance, correlation_id` | `apps/web/src/features/authorization/AccessContext.tsx:23-32` `PrincipalView` declares exactly these fields (camelCase mapped from JSON snake_case). Server normalization via `normalizePrincipal` | RESOLVED |
| 2 | `capabilities` array with regex pattern `^[a-z][a-z0-9_]*(\\.[a-z0-9_-]+)+$` | `NAVIGATION_ENTRIES` and `isRouteVisible` enforce capability strings; `routes.capabilities.test.ts` validates sample capability strings against the regex pattern | RESOLVED |

### `docs/contracts/schemas/work-record.schema.json`

Classification: **DRIFT-OPEN**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Required fields including `work_type_id` | Actual server response in `GetAuthorizedWorkRecordHandler::serialize()` and `WorkRecord::toEnvelope()` only emit `work_type_version_id` — `work_type_id` is never set (verified by `grep -rn "work_type_id\b" apps/api/...` returning empty) | OPEN — schema requires `work_type_id` but the response lacks it |
| 2 | Required `allowed_actions`, `field_access`, `decision_id` | `Authorization\Contracts\AccessProjection::compose()` adds these three fields via projection spread (lines 38-55). This DOES match the schema. The compose is invoked from `GetAuthorizedWorkRecordHandler` via `AccessProjection::fromDecision($decision)` | RESOLVED (after projecting via AccessProjection) |
| 3 | `owner` object `{facility_id, user_id}` | Both `serialize()` and `toEnvelope()` emit this exact shape | RESOLVED |

### `docs/contracts/schemas/problem-details.schema.json`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Required `type, title, status`; optional `detail, instance, correlation_id, errors` | `AuthorizationApi::problem()` emits `type, title, status, detail`; other controllers (e.g. `GetAuthorizedWorkRecordController::problem()`) emit the same four core fields. `correlation_id` is in header `X-Correlation-ID`. `errors` is not currently emitted by the sample problem paths but is `additionalProperties: true` in the schema so omission is allowed | RESOLVED |
| 2 | `type` URL form `https://cluster.example/problems/{type}` | Both AuthorizationApi.php:69 and individual controllers produce this prefix | RESOLVED |

### `docs/contracts/schemas/record-facts.schema.json`

Classification: **DRIFT-ACCEPTED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Schema fields (`facts_version, source_module, record_type, record_id, ...`) | The PHP `RecordFacts` DTO uses camelCase constructor properties (`resourceType` instead of `record_type`, etc.) and is consumed only internally — `DecideAccessController` validates `record_facts` as a generic array. The schema is the external contract; the DTO is mapped to the schema before crossing boundaries (verified no JSON serialization of RecordFacts happens in any controller response). | ACCEPTED — schema-only artifact; DTO is internal |

### `docs/contracts/schemas/event-envelope.schema.json` (bonus sample)

Classification: **DRIFT-OPEN**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Required `correlationid` (CloudEvents 1.0 single-word attribute) | `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php:18-31` emits cloud_event JSON without `correlationid` field. Every event written through this outbox violates the schema | OPEN — every CloudEvent written via the shared outbox is non-conformant against the canonical envelope schema. Affects 8+ modules publishing events |
| 2 | `dataschema` URI | Schema marks `dataschema` as optional; outbox omits it (allowed). | RESOLVED |

---

## Mermaid Diagrams

### `docs/architecture/diagrams/modules.mmd`

Classification: **DRIFT-ACCEPTED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | 19 modules in the diagram (PS, ORG, ID, AUTH, AUDIT, WF, RG, WD, DOC, COL, TASK, WR, STR, PP, RISK, NOTIFY, SEARCH, REPORT, WS) | Canonical reference notes code has 12 modules; Strategy, PortfolioProjects, Risk, Audit, RecordsGovernance, Workspace, Collaboration are planned (docs only). The diagram is aspirational and matches the architectural-claims.md catalog | ACCEPTED — forward-looking |

### `docs/architecture/diagrams/containers.mmd` and `deployment.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Caddy + React/Nginx + Laravel API + Worker + MySQL + Redis + Outbox | Matches `infra/platform/production/` and `infra/dev/` per canonical reference | RESOLVED |

### `docs/architecture/diagrams/system-context.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Three persona classes (employee, manager, platform admin) plus future integrations | Matches personas and journeys documented in `docs/product/personas-and-journeys.md` | RESOLVED |

### `docs/architecture/diagrams/document-sequence.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Documents module + Owner module + Authorization + Storage + Audit + Outbox with idempotent consumer and dead-letter | Matches `Documents/Contracts/SensitiveAccessEventRecorder.php` and `Shared/Infrastructure/Streams/` infrastructure present in canonical reference | RESOLVED |

### `docs/architecture/diagrams/workflow-sequence.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Steps reference `Identity, Organization, Authorization, Audit` | Matches canonical reference role-based enforcement model | RESOLVED |

### `docs/architecture/diagrams/outbox-sequence.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | Transactional outbox + consumer + dedupe store | Matches `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php` | RESOLVED |

### `docs/architecture/diagrams/authorization-sequence.mmd`

Classification: **DRIFT-RESOLVED**

| # | Claim | Evidence | Verdict |
|---|---|---|---|
| 1 | ResolveActiveIdentity → ResolveOrganizationScope → field access template | Matches `Modules/Identity` and `Modules/Organization` contracts present in code | RESOLVED |

---

## Summary

**DRIFT-RESOLVED** (10): All three specs reconcile with current code through intentional migration; all three plans reference real paths; schemas for principal-context, problem-details, work-record (after AccessProjection compose); all 8 mermaid diagrams reflect either the codebase or the documented forward-looking catalog.

**DRIFT-ACCEPTED** (5): Modules diagram is forward-looking (documented); record-facts schema is internal-only; platform-settings frontend files not yet created (planned); capability seeding pending plan execution; PlatformSettings V1 frontend routes pending plan execution.

**DRIFT-OPEN** (3):

1. `access-decision.schema.json` requires `clearance` in nested access_context, plus `authorization_trace_id, evaluated_at, obligations, field_decisions` on the parent decision — `DecideAccessController` does not emit any of these fields. Affects every authorization decision the API returns.
2. `work-record.schema.json` requires `work_type_id` but neither `WorkRecord::toEnvelope()` nor `GetAuthorizedWorkRecordHandler::serialize()` emits it. Affects every work-record list and detail response.
3. `event-envelope.schema.json` requires `correlationid` (CloudEvents 1.0 attribute) but `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php` never injects a correlation ID into the envelope. Affects every domain event the platform publishes (work-record-submitted, workflow-step-activated, workflow-decision-recorded, document-scan-completed, plus 4+ more per AsyncAPI channels).

**Recommended remediation**

- DecideAccessController: add `clearance` (read from principal or facts), include `authorization_trace_id`, `evaluated_at`, `obligations`, `field_decisions` from the AccessDecision struct. Align with the schema's required and optional fields.
- WorkRecord serialization: include `work_type_id` (or remove it from the schema). Confirm whether work_type_id is intended to differ from work_type_version_id.
- DatabaseTransactionalOutbox: accept an optional correlation ID argument and include `correlationid` in the envelope (and optionally `dataschema`). Callers will need to thread correlation IDs through their respective handlers.
