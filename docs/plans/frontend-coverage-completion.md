---
doc_id: PLN-FE-COV-001
title: Plan to Close Frontend Coverage of the Contracts
type: plans
status: draft
version: 1.0.0
date: 2026-07-22
owner: Software Engineering Lead
reviewers: []
classification: internal
review_cycle: After completing each wave
sources:
  - docs/contracts/api/openapi.yaml
references:
  - docs/plans/implementation-roadmap.md
  - docs/plans/active-delivery-status.md
---

# Plan to Close Frontend Coverage of the Contracts

## 1. Measured State

The measurement was run automatically on the unified client contract against
what the screens actually consume (an operation is "covered" when it has a
wrapper in `src/api/` **and** that wrapper is imported by code outside
`src/api/`).

| Indicator | Count | Percent |
|---|---:|---:|
| Operations in the unified client contract | 183 | 100% |
| Actually reached by a screen | 94 | 51% |
| Has a wrapper with no consumer | 2 | 1% |
| Has no wrapper at all | 87 | 48% |

Breakdown of the 87 uncovered:

| Classification | Count | Decision |
|---|---:|---|
| Infrastructure that needs no screen | 8 | Excluded permanently |
| Redundant contract paths | 4 | To be deleted from the contract |
| Real remaining product gaps | 75 | Scope of the following waves |

### 1.1 What Is Excluded Permanently (8)

These are inter-service operations or operational probes; having a screen for them
is a design error, not a gap:

| Operation | Path | Reason |
|---|---|---|
| `getBootstrapHealth` | `GET /up` | Operational infrastructure probe |
| `scanDocumentVersion` | `POST /internal/documents/versions/{versionId}/scan` | Internal call from the scanning service |
| `reconcileDocumentPromotion` | `POST /internal/documents/versions/{versionId}/reconcile-promotion` | Internal reconciliation |
| `validatePersonReference` | `GET /organization/people/{personId}/reference` | Internal contract between modules |
| `loginW12` | `POST /auth/login` | Development path; production uses `/identity/login` |
| `getAuthorizationBootstrap` | `GET /authorization/bootstrap` | One-time bootstrap |
| `completeAuthorizationBootstrap` | `POST /authorization/bootstrap` | One-time bootstrap |
| `bootstrapComplete` | `POST /authorization/bootstrap/complete` | One-time bootstrap |

### 1.2 What Must Be Removed from the Contract (4)

The backend serves a single templated path
`POST /work-records/{recordId}/{recordAction}` (see
`apps/api/routes/web.php`), while the contract declares four single paths tagged
`x-implementation-status: planned`. This is a documentation drift that must be
closed, not a screen to be built:

| Operation | Path |
|---|---|
| `submitWorkRecord` | `POST /work-records/{recordId}/submit` |
| `transitionWorkRecordReturn` | `POST /work-records/{recordId}/return` |
| `transitionWorkRecordComplete` | `POST /work-records/{recordId}/complete` |
| `transitionWorkRecordCompleteSubmission` | `POST /work-records/{recordId}/complete-submission` |

---

## 2. Ordering Principle

Waves are ordered by **(impact ÷ cost)**, not by the module order in the contract.
The practical rule: a gap inside a module that already has a working screen is
much cheaper than a module that has no surface area at all, because navigation,
states, and copy are already there.

- **Waves 1–5**: gaps inside existing modules (38 operations) — wiring, not design.
- **Waves 6–10**: entirely missing modules (47 operations) — need journey design.

---

## 3. Wave 1 — Document Center (10 operations)

**Why first:** document upload already works through `ImportReview`, but no screen
exists to list or manage documents. The user uploads a file and cannot see it
afterwards. This is the single largest functional gap in the product.

| Operation | Path |
|---|---|
| `listDocuments` | `GET /documents` |
| `getDocument` | `GET /documents/{documentId}` |
| `createDocument` | `POST /documents` |
| `updateDocument` | `PATCH /documents/{documentId}` |
| `transitionDocument` | `POST /documents/{documentId}/{documentAction}` |
| `listDocumentVersions` | `GET /documents/{documentId}/versions` |
| `addDocumentVersion` | `POST /documents/{documentId}/versions` |
| `listDocumentLinks` | `GET /documents/{documentId}/links` |
| `linkDocument` | `POST /documents/{documentId}/links` |
| `createDocumentAccessGrant` | `POST /documents/{documentId}/{grantType}-grant` |

**Outputs:**
1. `/documents` route and a list screen with status and classification filtering
   and `cursor` pagination.
2. A detail screen: data, versions, links, and access grants.
3. New version upload reuses the existing quarantine flow in `ImportReview`.
4. Replace the "paste document id" field in `RequestDetail` with a real picker.

**Dependency:** none. `initiateDocumentUpload`/`completeDocumentUpload` are
already covered.

**Observed contract gap:** there is no endpoint for listing or revoking grants —
only creation. This must be raised to the contract owner before designing the
grants screen.

---

## 4. Wave 2 — Audit Log (1 operation)

**Why:** a P0 item already logged in your coverage screen. Today the "Timeline"
in `RequestDetail` is one synthetic row built from `created_at`, not a real
history.

| Operation | Path |
|---|---|
| `listAuditEvents` | `GET /audit` |

**Outputs:**
1. `/audit` route and a search screen with resource, actor, and time-range filtering.
2. An "Event log" panel embedded inside any entity detail screen, filtered by
   its id.
3. Remove the synthetic timeline from `RequestDetail`.

**Dependency:** preferred after Wave 1 to reuse the "panel embedded in detail"
pattern.

---

## 5. Wave 3 — Workflow Decisions with Reason (3 operations)

**Why:** a real governance gap, not just a UI gap. Today's approvals are binary
with no reason logged at the step level, even though the contract mandates
`reason` in `Decision`.

| Operation | Path |
|---|---|
| `recordWorkflowDecision` | `POST /workflow/steps/{stepId}/decisions` |
| `actOnWorkflowStep` | `POST /workflow/steps/{stepId}/{stepAction}` |
| `cancelWorkflow` | `POST /workflow/instances/{instanceId}/cancel` |

**Outputs:**
1. A step decision form: `approve` / `reject` / `return` / `accept` / `decline`
   with a mandatory reason and length validation (1–2000).
2. Step reassignment and escalation through `actOnWorkflowStep`.
3. Cancel a workflow instance from the administration screen.

**Related note:** the same pattern exists in `AuthorizationAdmin` — role-assignment
status change happens through a direct PATCH without a reason, bypassing
`transitionAuthorizationAdminResource`. It must be fixed within this wave
because it is the same governance gap.

---

## 6. Wave 4 — Task Comments and Participants (6 operations)

| Operation | Path |
|---|---|
| `getTask` | `GET /tasks/{taskId}` |
| `createTask` | `POST /tasks` |
| `updateTask` | `PATCH /tasks/{taskId}` |
| `listTaskComments` | `GET /tasks/{taskId}/comments` |
| `addTaskComment` | `POST /tasks/{taskId}/comments` |
| `addTaskParticipant` | `POST /tasks/{taskId}/participants` |

**Outputs:**
1. A task detail screen (`/tasks/{taskId}`) — does not exist today, list only.
2. A real comment thread with add.
3. Adding a participant to the task.

---

## 7. Wave 5 — Definitions Lifecycle and Detail Pages (18 operations)

### 7.1 Work-Definition Lifecycle (9)

Today only `publish` works; the rest of the governance lifecycle is unwired.

| Operation | Path |
|---|---|
| `getWorkDefinition` | `GET /work-definitions/{definitionId}` |
| `updateWorkDefinition` | `PATCH /work-definitions/{definitionId}` |
| `getWorkDefinitionVersion` | `GET /work-definition-versions/{versionId}` |
| `updateWorkDefinitionVersionDraft` | `PATCH /work-definition-versions/{versionId}` |
| `testWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/test` |
| `approveWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/approve` |
| `signWorkDefinitionVersion` | `POST /work-definition-versions/{versionId}/sign` |
| `getWorkflowVersion` | `GET /workflow/versions/{versionId}` |
| `updateWorkflowVersionDraft` | `PATCH /workflow/versions/{versionId}` |

### 7.2 Entity Detail Pages (9)

There is no detail page for any organizational entity today — screens rely on
lists only.

| Operation | Path |
|---|---|
| `getOrganizationUnit` | `GET /organization/units/{unitId}` |
| `getPosition` | `GET /organization/positions/{positionId}` |
| `getPerson` | `GET /organization/people/{personId}` |
| `getFacility` | `GET /organization/facilities/{facilityId}` |
| `updateFacility` | `PATCH /organization/facilities/{facilityId}` |
| `updateCluster` | `PATCH /organization/cluster` |
| `getWorkspace` | `GET /workspace` |
| `updateWorkRecord` | `PATCH /work-records/{recordId}` |
| `logout` | `POST /auth/logout` |

**Note:** `logout` is a development path; production uses the covered
`identityLogout`. It is likely to be removed from the contract instead of being
built.

---

## 8. Waves 6–10 — Missing Modules (47 operations)

These are not wiring gaps but **unbuilt product**: no screen, no path, no
navigation entry. Each wave needs journey design before code, and is therefore
estimated on a different scale than Waves 1–5.

### 8.1 Wave 6 — Records Governance (12)

| Operation | Path |
|---|---|
| `listGovernedRecords` | `GET /records-governance/governed-records` |
| `registerGovernedRecord` | `POST /records-governance/governed-records` |
| `getGovernedRecordStatus` | `GET /records-governance/governed-records/{governedRecordId}` |
| `listRecordHolds` | `GET /records-governance/holds` |
| `placeRecordHold` | `POST /records-governance/holds` |
| `releaseRecordHold` | `POST /records-governance/holds/{holdId}/release` |
| `listRetentionPolicyVersions` | `GET /records-governance/retention-policy-versions` |
| `createRetentionPolicyVersion` | `POST /records-governance/retention-policy-versions` |
| `publishRetentionPolicyVersion` | `POST /records-governance/retention-policy-versions/{versionId}/publish` |
| `listDispositionReviews` | `GET /records-governance/disposition-reviews` |
| `decideDispositionEligibility` | `POST /records-governance/disposition-reviews` |
| `confirmDispositionOutcome` | `POST /records-governance/disposition-reviews/{reviewId}/confirm` |

**Dependency:** after Wave 1, because records governance works on documents.

### 8.2 Wave 7 — Portfolios and Projects (10)

| Operation | Path |
|---|---|
| `listPortfolioResources` | `GET /portfolio/{portfolioResource}` |
| `getPortfolioResource` | `GET /portfolio/{portfolioResource}/{resourceId}` |
| `createPortfolioResource` | `POST /portfolio/{portfolioResource}` |
| `updatePortfolioResource` | `PATCH /portfolio/{portfolioResource}/{resourceId}` |
| `transitionProject` | `POST /portfolio/projects/{projectId}/{projectAction}` |
| `listProjectMilestones` | `GET /portfolio/projects/{projectId}/milestones` |
| `createProjectMilestone` | `POST /portfolio/projects/{projectId}/milestones` |
| `listProjectIndicatorLinks` | `GET /portfolio/projects/{projectId}/indicator-links` |
| `createProjectIndicatorLink` | `POST /portfolio/projects/{projectId}/indicator-links` |
| `recordProjectSnapshot` | `POST /portfolio/projects/{projectId}/{snapshotType}-snapshots` |

**Dependency:** indicator links require Wave 9 (Strategy) to be meaningful.

### 8.3 Wave 8 — Platform Settings and Calendars (9)

| Operation | Path |
|---|---|
| `getCurrentPlatformSettings` | `GET /platform-settings/current` |
| `listPlatformSettingsVersions` | `GET /platform-settings/versions` |
| `createPlatformSettingsDraft` | `POST /platform-settings/versions` |
| `setPlatformSetting` | `PUT /platform-settings/versions/{versionId}/settings/{settingKey}` |
| `transitionPlatformSettingsVersion` | `POST /platform-settings/versions/{versionId}/{settingsAction}` |
| `listBusinessCalendars` | `GET /business-calendars` |
| `createBusinessCalendar` | `POST /business-calendars` |
| `setBusinessCalendarDay` | `PUT /business-calendars/{calendarId}/days/{date}` |
| `publishBusinessCalendar` | `POST /business-calendars/{calendarId}/publish` |

**Note:** calendars affect workflow deadline calculation, so their operational
value is higher than their ordering here if deadlines are actually enabled.

### 8.4 Wave 9 — Risks (9)

| Operation | Path |
|---|---|
| `listRiskResources` | `GET /risk/{riskResource}` |
| `getRisk` | `GET /risk/risks/{riskId}` |
| `createRiskResource` | `POST /risk/{riskResource}` |
| `updateRisk` | `PATCH /risk/risks/{riskId}` |
| `transitionRisk` | `POST /risk/risks/{riskId}/{riskLifecycleAction}` |
| `listRiskIndicatorReadings` | `GET /risk/risks/{riskId}/indicator-readings` |
| `createRiskIndicatorReading` | `POST /risk/risks/{riskId}/indicator-readings` |
| `getRiskHeatmap` | `GET /risk/heatmap` |
| `listDueRiskReviews` | `GET /risk/reviews/due` |

### 8.5 Wave 10 — Strategy (7)

| Operation | Path |
|---|---|
| `listStrategyResources` | `GET /strategy/{strategyResource}` |
| `getStrategyResource` | `GET /strategy/{strategyResource}/{resourceId}` |
| `createStrategyResource` | `POST /strategy/{strategyResource}` |
| `updateStrategyResource` | `PATCH /strategy/{strategyResource}/{resourceId}` |
| `transitionStrategyResource` | `POST /strategy/{strategyResource}/{resourceId}/{strategyAction}` |
| `getIndicatorScorecard` | `GET /strategy/indicators/{indicatorId}/scorecard` |
| `listPendingIndicatorMeasurements` | `GET /strategy/measurements/pending` |

---

## 9. Collateral Work Not Tied to a Wave

These items were observed during the audit and must be done in parallel:

| # | Item | Location | Impact |
|---|---|---|---|
| C-1 | Proactive hiding of navigation and buttons by `allowed_actions` instead of relying on 403 after attempting | All screens | UX and visible security |
| C-2 | `cursor` pagination in lists still on a fixed limit | `listPositions`, `listPeople`, `listAssignments`, `listUserAccounts`, `listImportJobRows` | Silent data loss |
| C-3 | Fix the `POST /organization/units/reorder` contract: it requires `ordered_unit_ids` while the controller ignores the body | `docs/contracts/api/openapi.yaml` | Contract drift |
| C-4 | Remove the four redundant work-record paths from the contract | `docs/contracts/api/openapi.yaml` | Contract drift |
| C-5 | Missing endpoints to add to the contract: list/revoke document grants, bulk create positions, position detail and edit, end supervisory relation, `unread-count` and `mark-all-read` for notifications | Contract | Block later waves |
| C-6 | Visual review of four screens after fixing broken conditional chains | `IdentityAccounts`, `ImportReview`, `OrganizationStructure`, `AuthorizationAdmin` | Verify regression is gone |
| C-7 | Verify the effect of removing the `X-Day3-Acceptance` header from `createWorkRecord` on the acceptance flows | `apps/web/src/api/work-records.ts` | Acceptance tests |

---

## 10. Definition of Done for Each Wave

A wave is not complete until all the following are true:

1. Every operation in the wave has a wrapper in `src/api/` that goes through the
   generated client — no manual `fetch`.
2. Every wrapper is consumed by a screen or component outside `src/api/`.
3. The screen covers the six states: `loading` / `ready` / `empty` / `forbidden` /
   `not-found` / `error` through the shared `stateFromError`, with `conflict` and
   `stale` for every resource that has `lock_version`.
4. No inline copy: every string lives in the screen dictionary or the central
   dictionary, in Arabic and English.
5. The route is classified in `ROUTE_WORKSPACE` inside `shell/routes.ts` and has
   a navigation entry.
6. Unit tests for the pure logic and at least one happy-path journey test.
7. The coverage is remeasured and the table in section 1 is updated.

## 11. Progress Tracking

| Wave | Operations | Status |
|---|---:|---|
| 1 — Document Center | 10 | Complete — 10 / 10 |
| 2 — Audit Log | 1 | Not started |
| 3 — Workflow Decisions | 3 | Not started |
| 4 — Tasks and Comments | 6 | Not started |
| 5 — Definitions and Detail Pages | 18 | Not started |
| 6 — Records Governance | 12 | Not started |
| 7 — Portfolios and Projects | 10 | Not started |
| 8 — Platform Settings and Calendars | 9 | Not started |
| 9 — Risks | 9 | Not started |
| 10 — Strategy | 7 | Not started |
| **Total** | **85** | **10 / 85** |

When Waves 1–5 are complete, coverage becomes **~67%**, and when all are
complete, **100%** of the operations dedicated to the frontend (183 minus 12
excluded or removed = 171).

## 12. How to Remeasure

```bash
cd apps/web && python3 - <<'PY'
import pathlib, re
gen = pathlib.Path('src/api/generated/cluster.ts').read_text()
ops = sorted(set(o for o in re.findall(r"^export const (\w+) = async \(", gen, re.M)
             if not re.match(r"^get\w+Url$", o)))
wrappers = {p: p.read_text() for p in pathlib.Path('src/api').rglob('*.ts')
            if 'generated' not in str(p) and '.test.' not in p.name}
app = '\n'.join(p.read_text() for p in pathlib.Path('src').rglob('*.ts*')
                if not str(p).startswith('src/api') and '.test.' not in p.name)
def owner(text, idx):
    c = [m for m in re.finditer(r"export (?:async )?function (\w+)|export const (\w+) = ", text[:idx])]
    return (c[-1].group(1) or c[-1].group(2)) if c else None
reach = 0
for op in ops:
    names = {owner(s, m.start()) for s in wrappers.values()
             for m in re.finditer(r"(?:generated\.)?\b%s\(" % re.escape(op), s)}
    if any(n and re.search(r"\b%s\b" % re.escape(n), app) for n in names):
        reach += 1
print(f"{reach}/{len(ops)} = {reach*100//len(ops)}%")
PY
```
