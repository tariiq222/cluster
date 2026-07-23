---
doc_id: AUDIT-DOCS-2026-07-23
title: Phase A Audit Findings — docs/ vs Code
type: audit
status: accepted
date: 2026-07-23
owner: Platform Engineering Office
classification: internal
review_cycle: when code changes
sources:
- artifacts/current-code-module-semantics-audit-2026-07-23.md
- artifacts/current-system-enterprise-ux-audit-2026-07-23.md
- .codex/plans/canonical-code-reference.txt
references:
- .codex/plans/audit-adr.md
- .codex/plans/audit-architecture.md
- .codex/plans/audit-contracts.md
- .codex/plans/audit-data-security.md
- .codex/plans/audit-domain.md
- .codex/plans/audit-engineering.md
- .codex/plans/audit-governance.md
- .codex/plans/audit-operations.md
- .codex/plans/audit-plans.md
- .codex/plans/audit-product.md
- .codex/plans/audit-readiness.md
- .codex/plans/audit-toplevel.md
---

# Phase A Audit Findings — `docs/` vs Code

This document records every drift finding produced by 12 parallel audit
subagents. Per-file detail tables live in each section's dedicated audit
report (see references). This file is the consolidated decision surface
for Phase B.

## Tally

| Audit | Total | DRIFT-RESOLVED | DRIFT-ACCEPTED | DRIFT-OPEN |
|---|---:|---:|---:|---:|
| adr | 27 | 18 | 7 | 2 |
| architecture | 118 | 76 | 16 | 26 |
| contracts | 56 | 15 | 17 | 24 |
| data-security | 9 | 2 | 3 | 4 |
| domain | 17 | 0 | 6 | 11 |
| engineering | 19 | 11 | 2 | 6 |
| governance | 7 | 4 | 2 | 1 |
| operations | 21 | 11 | 4 | 6 |
| plans | 15 | 14 | 1 | 0 |
| product | 18 | 0 | 2 | 16 |
| readiness | 18 | 10 | 5 | 3 |
| toplevel | 8 | 0 | 4 | 4 |
| **TOTAL** | **333** | **161** | **69** | **103** |

## Canonical Code Reference (one-time truth)

Source: `.codex/plans/canonical-code-reference.txt`, produced by reading
the actual repository. The numbers in this audit are measured against
this reference, not against any prior artifact.

- 12 Laravel modules implemented: `Authorization`, `Documents`,
  `Identity`, `Notifications`, `Organization`, `PlatformSettings`,
  `Reporting`, `Search`, `Tasks`, `WorkDefinitions`, `WorkRecords`,
  `Workflow`.
- 47 migration files, 96 `Schema::create` calls, 96 tables.
- 119 routes under `/api/v1` (54 GET, 56 POST, 8 PATCH, 1 PUT).
- 49 module Contracts (PHP interfaces), 4 Shared Infrastructure files.
- 14 frontend features (do not map 1:1 to backend modules).

## Headline Drift Items (pre-Phase B)

These are the items the user resolved before Phase B (translation).
The full list of original 103 DRIFT-OPEN items lives in the per-section
audit files.

### Headline 1 — Module catalog vs implementation

`docs/architecture/module-catalog.md` declares 19 modules as the legal
boundaries. `apps/api/Modules/` holds 12. The 7 missing
(`Audit`, `RecordsGovernance`, `Collaboration`, `Workspace`, `Strategy`,
`PortfolioProjects`, `Risk`) are documented as planned for R2/R3.

- User decision: keep 19 modules in the catalog with `status: planned`
  for the 7 unimplemented — **accepted**.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php` declares the
  same 19 modules in `MODULE_RANKS` and `TABLE_OWNERS`. The test passes
  because the glob walker skips unknown modules. Code-side drift;
  out of translation scope.

### Headline 2 — `mkdocs.yml` references a non-existent path

`mkdocs.yml` line 63 referenced `docs/architecture/kubernetes-platform.md`
which did not exist. The actual file was `docs/operations/kubernetes-platform.md`.

- **Cross-flag**: audit-toplevel claimed the file is missing. Verified
  on disk: `/Users/tariq/code/R3/cluster/docs/operations/kubernetes-platform.md`
  exists (2,674 bytes, dated 2026-07-17). The drift was in the **nav**,
  not the file.
- User decision: **move + rename** to `docs/architecture/docker-compose-platform.md`
  to match the new ADR-023 reality — **applied**.

### Headline 3 — Operations cluster status mismatch

`docs/catalog.yaml` marked all 8 operations files as `proposed`, while
`mkdocs.yml` exposed them as a first-class navigation section. The
catalog and the nav disagreed on whether operations is canonical.

- Resolved: operations status updated to `accepted` in both
  `docs/catalog.yaml` and each file's frontmatter.

### Headline 4 — README and ADR owners are individual names

`docs/governance/document-control.md` §13 forbids individual names in
governance docs. `README.md`, `ADR-023`, `ADR-024`, `ADR-025` violated
this. Resolved: replaced with role labels
("Engineering Office", "Platform Engineering Office", etc.).

### Headline 5 — `document-control.md` §4 type-code list is incomplete

`document-control.md` §4 enumerated GOV allowed types as `DC, GL, AC,
RC, TR`. Actual governance docs used `GOV-IDX-001` (code `IDX`) and
`GOV-CR-001` (code `CR`). Neither was in the allowed list. Resolved:
`IDX` and `CR` added to the table.

### Headline 6 — Doc says `R2/R3` modules are present tense

`docs/product/vision-and-scope.md`, `personas-and-journeys.md`,
`releases-and-roadmap.md`, and `success-metrics.md` described Strategy,
PortfolioProjects, Risk, and Indicators as **current** capabilities. The
code has none of these. 16 DRIFT-OPEN items in `docs/product/` alone.
Resolved: all R2/R3 personas, journeys, and KPIs tagged with
`[planned-R2]` / `[planned-R3]` markers; banners added at the top of
each product doc.

### Headline 7 — RBAC matrix CSRF claims contradict routes

`docs/api/rbac-matrix.md` marked every GET route as `CSRF: yes`. The
route registry shows many GET routes in the
`IdentitySessionMiddleware + RequireIdentitySessionPrincipal` group
without `IdentityCsrfMiddleware`. Resolved: 119 RBAC rows regenerated
from middleware groups; CSRF flag now reflects the actual middleware
chain.

### Headline 8 — Notifications API contract is partial

`docs/contracts/api/notifications.md` listed only GET endpoints. The
code exposes `POST /notifications/{id}/read` (route `web.php:138-140`).
Resolved: POST endpoint added to the contract.

### Headline 9 — AsyncAPI omits two live event channels

`docs/contracts/events/asyncapi.yaml` declared 25 channels but omitted:

1. `com.cluster.platform.technical-alert.v1` — produced by
   `NotificationsTechnicalAlertHandler` and consumed by the worker.
2. Document outbox events:
   `com.cluster.documents.uploadinitiated`,
   `versionuploaded`, `versionrejected`,
   `versionpromotionrequested`, `versionquarantined`,
   `versionavailable`.

Resolved: both channels added with proper schemas
(`technical-alert.v1` and `documents-upload-events.v1`).

### Headline 10 — Domain docs claim tables and columns that do not exist

11 DRIFT-OPEN in `docs/domain/` covering 9 implemented modules. Fixes
applied inline during translation:

- `Documents` doc dropped `archived_at`, `ip_address`, `user_agent_hash`
  claims; noted `restriction_facts` and `sha256` are nullable.
- `WorkDefinitions` doc updated to state the actual 4 tables, not 7.
- `Workflow` doc updated to state the actual 7 tables, not 4; state
  machine has Pending/Active, not Draft/Tested/Approved/Signed.
- `Organization` doc dropped `clusters.settings` and `facilities.settings`
  JSON columns; updated `supervisory_relationships` column claims.
- `Authorization` doc corrected PK type from BIGINT to UUID.

### Headline 11 — `testing-strategy.md` mandates unenforced gates

`docs/engineering/testing-strategy.md` lines 27-34 mandate 80% mutation
score, 2,000 concurrent load tests, and RPO≤15 min / RTO≤2 h restore
drills. None of these are wired in `composer.json`, `Makefile`, or any
CI workflow. Resolved: NOT IMPLEMENTED banner added at top of file.

### Headline 12 — `design-system.md` invents tokens

`docs/design-system.md` lines 63-71 listed `--color-warning: #9A5B00`,
`--color-dark-canvas`, `--color-dark-surface`, `--color-dark-muted`.
None of these are in `apps/web/src/styles/tokens.css`. Resolved: token
claims removed from the doc; type scale numbers corrected to match
`base.css` (Title 20/600, Label 13/600).

### Headline 13 — `apps/api/phpunit.mysql.xml` integration suite is orphaned

The MySQL integration suite is defined (WalkingSkeleton + concurrency
tests) but no Makefile target or GitHub workflow job references it. The
suite runs only on demand. Code drift; out of translation scope.

### Headline 14 — `ModuleBoundariesTest.php` declares 19 modules but checks 12

The architecture test lists 19 modules in `MODULE_RANKS` and
`TABLE_OWNERS`. The test passes because the glob walker skips unknown
modules. The intended enforcement is silent. Code drift; out of
translation scope.

### Headline 15 — `coding-and-module-boundaries.md` lists six guards; only three exist

`docs/engineering/coding-and-module-boundaries.md` lines 35-36 list
six CI guards: forbidden imports, dependency cycle, cross-owner SQL,
derived write to business tables, contract-without-contract-test,
event-without-schema-test. Only the first three are enforced; the other
three are not implemented in `ModuleBoundariesTest`. Resolved: guard-
status banner added at top of `coding-and-module-boundaries.md`.

### Headline 16 — `ha-dr-backup.md` describes scripts that do not exist

`docs/operations/ha-dr-backup.md` references backup/PITR scripts. There
are no `scripts/backup*.sh` or `infra/*/backup*.sh` files. The
`PLATFORM_BACKUP_COMMAND` env var is read but no script implements it.
Resolved: NOT IMPLEMENTED banner added at top of file.

### Headline 17 — `observability-and-slos.md` lists signals without backends

OpenSearch, Loki, Prometheus are named as observability backends. None
are in `infra/platform/production/compose.yaml`. No `/metrics` endpoint
in `apps/api/routes/`. Resolved: NOT IMPLEMENTED banner added at top
of file.

### Headline 18 — Runbooks assume a Staging environment that does not exist

`docs/operations/runbooks.md` line 27 instructs execution in Staging
first. There is no `infra/staging/`. All 6 runbooks run against prod.
Resolved: NOT IMPLEMENTED banner added at top of file.

### Headline 19 — File moved and renamed; HA language removed

`docs/operations/kubernetes-platform.md` was moved to
`docs/architecture/docker-compose-platform.md`. The body still
referenced "HA member" failure language. ADR-023 (single-host, no
Kubernetes, no Dokploy) supersedes ADR-019. Resolved: HA-member phrasing
removed; operations status updated to `accepted` across the cluster.

### Headline 20 — `apps/api/Dockerfile` installs `scheduler-loop.sh` but no scheduler service uses it

The binary is installed at `:63-65` but no `scheduler` service exists in
`infra/platform/production/compose.yaml`. Wasted image layers. Code
drift; out of translation scope.

## Resolution rules applied in Phase B

1. **DRIFT-OPEN that is a documentation error** (e.g., wrong PK type,
   wrong table name) → fixed in place during translation.
2. **DRIFT-OPEN that is a code gap** (e.g., missing tables, missing
   backup scripts, missing CI guards) → NOT IMPLEMENTED banner added
   to the translated file with link to a tracking ADR or issue.
3. **DRIFT-OPEN in `README.md` / `*-release-*.md` / `mkdocs.yml`** →
   fixed during translation; inside the user's locked scope.
4. **DRIFT-OPEN that is a governance inconsistency** (e.g., owner names
   that are individual identities) → replaced with role labels during
   translation per `document-control.md` §13.

## Cross-flag (audit-toplevel mistake)

`audit-toplevel.md` reported `docs/operations/kubernetes-platform.md`,
`docs/api/endpoints.md`, and `docs/api/rbac-matrix.md` as missing. **They
exist on disk.** Verified via `ls` and `file`. The audit subagent
incorrectly used `fs.access` in a way that returned false for these
paths. The "missing path" findings D-1, D-2, D-3 from `audit-toplevel.md`
are **false positives**. The actual drift was in the `mkdocs.yml` nav
which pointed to `docs/architecture/kubernetes-platform.md` (a path that
did not exist). The two `docs/api/` files exist and were referenced
from `mkdocs.yml` correctly.

Phase B honored the move + rename: file moved from
`docs/operations/kubernetes-platform.md` to
`docs/architecture/docker-compose-platform.md`, and `mkdocs.yml` nav now
points at the new path.

## What Phase B did not do

- Did not invent R2/R3 modules that have no code.
- Did not silently delete the `docs/superpowers/specs/*` and
  `docs/plans/*` files.
- Did not touch JSON schema keys (they are identifiers).
- Did not rename any `apps/api/Modules/*` directory.
- Did not change the `apps/api/routes/web.php` middleware order.
- Did not delete the planned-module documentation.
- Did not write Arabic content.

## Phase B change set (applied)

1. Moved `docs/operations/kubernetes-platform.md` to
   `docs/architecture/docker-compose-platform.md`. Updated nav.
2. Updated `mkdocs.yml`:
   - `language: en`
   - Site name: `Third Health Cluster Platform`
   - All nav titles translated to English
   - Added `docs/architecture/docker-compose-platform.md` entry
3. Updated `docs/catalog.yaml`:
   - All titles translated to English
   - Added `Docker Compose Platform` entry
   - Reconciled 8 operations cluster from `proposed` to `accepted`
4. Translated every `.md` under `docs/` to English. Each translator
   applied the DRIFT-RESOLVED corrections inline.
5. Translated JSON/YAML contract descriptions (kept keys, kept
   JSON pointers).

## Acceptance criteria for Phase B

- Zero Arabic Unicode characters remain under `docs/`.
- `mkdocs build --strict` exits 0.
- Every `docs/` file referenced in `mkdocs.yml` nav exists on disk.
- Every path in `docs/catalog.yaml` exists on disk.
- `docs/audit-findings.md` (this file) is updated with `DRIFT-OPEN`
   resolution status after Phase B.
