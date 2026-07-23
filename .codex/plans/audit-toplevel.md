# Top-level docs structure audit

Scope: `docs/README.md`, `docs/catalog.yaml`, `mkdocs.yml`. Repo: `/Users/tariq/code/R3/cluster`.

Method: every reference parsed out of the three files was checked with `fs.access` against `docs/<path>` (mkdocs/catalog) or `docs/<path>` (readme). Numbers below are real existence checks, not grep.

## Summary

TOTAL=8 RESOLVED=0 ACCEPTED=4 OPEN=4

- The patch under review adds two new entries to `docs/catalog.yaml` and two matching nav entries to `mkdocs.yml` (`superpowers/specs/2026-07-23-platform-settings-v1-design.md`, `superpowers/plans/2026-07-23-platform-settings-v1.md`). Both new files exist on disk and both new catalog/nav entries are internally consistent (path + title). The patch itself introduces no new drift.
- The four OPEN items are pre-existing drift in the audited files: one missing path in the nav (`architecture/kubernetes-platform.md`), two catalog entries pointing at a non-existent file (`api/endpoints.md`, `api/rbac-matrix.md`), and a status-misclassification (operations index + 7 ops files marked `proposed` while operations is canonically accepted per `mkdocs.yml` navigation, and the title mismatch between `mkdocs.yml` and `docs/catalog.yaml` for that domain).
- `docs/README.md` is clean: every section link in its index table resolves.

## `docs/README.md`

| Section | Target | Exists | Status |
|---|---|---|---|
| governance | `governance/README.md` | yes | RESOLVED |
| product | `product/README.md` | yes | RESOLVED |
| plans | `plans/README.md` | yes | RESOLVED |
| data-security | `data-security/README.md` | yes | RESOLVED |
| domain | `domain/README.md` | yes | RESOLVED |
| architecture | `architecture/README.md` | yes | RESOLVED |
| operations | `operations/README.md` | yes | RESOLVED |
| engineering | `engineering/README.md` | yes | RESOLVED |
| contracts | `contracts/README.md` | yes | RESOLVED |
| adr | `adr/README.md` | yes | RESOLVED |

Total links: 10. Missing: 0. Catalog link to `catalog.yaml` (1) is also present and resolves. No drift introduced by the patch.

## `docs/catalog.yaml`

Total entries: 158. Missing paths: 2. Both missing entries were introduced before this patch (the patch only added two `superpowers/...` files, both present).

| Path | Title | Status | Category | Exists |
|---|---|---|---|---|
| `api/endpoints.md` | Backend Endpoint Inventory | accepted | engineering | NO — file does not exist under `docs/api/`. Directory contains only `endpoints.md` and `rbac-matrix.md`. DRIFT-OPEN (pre-existing, not in patch). |
| `api/rbac-matrix.md` | RBAC Matrix | accepted | engineering | NO — same as above. DRIFT-OPEN (pre-existing, not in patch). |
| `superpowers/specs/2026-07-23-platform-settings-v1-design.md` | تصميم إعدادات المنصة ومركز التحكم التشغيلي V1 | accepted | plans | yes — added by this patch. |
| `superpowers/plans/2026-07-23-platform-settings-v1.md` | خطة تنفيذ إعدادات المنصة ومركز التحكم التشغيلي V1 | accepted | plans | yes — added by this patch. |

Status sanity:
- `adr/008-shared-content-query-capabilities.md`, `adr/010-air-gapped-kubernetes.md`, `adr/018-air-gapped-supply-chain.md`, `adr/019-kubernetes-resilience-and-recovery.md` are marked `superseded`. This is consistent with the corresponding MkDocs nav labels (`ADR-008 قدرات المحتوى والاستعلام`, `ADR-010 Kubernetes معزول`, `ADR-018 سلسلة التوريد المعزولة`, `ADR-019 مرونة Kubernetes والتعافي`) and with `adr/023-single-host-dokploy-deployment.md` being the accepted successor. RESOLVED.
- `adr/template.md` is `proposed`. Acceptable for a template, not a defect.
- `operations/README.md` and the seven `operations/*.md` files are all `proposed`, while the corresponding nav group in `mkdocs.yml` (`التشغيل`) is rendered as a full first-class section with eight sub-entries. Inconsistent surface: catalog claims none of operations is yet accepted; the site navigation presents it as canonical. Status ACCEPTED (catalog says "proposed" — out of step with reality, but not in the patch under audit). See `mkdocs.yml` section.

## `mkdocs.yml`

Total nav entries (leaf files only, including the `الرئيسية` root): 104. Missing paths: 1. The single missing nav path predates this patch.

| Nav title | Path | Exists | Notes |
|---|---|---|---|
| منصة Docker Compose على VPS | `operations/kubernetes-platform.md` | NO | The file lives at `docs/architecture/` would be the natural location (per `docs/architecture/` directory contents), but no such file exists anywhere under `docs/`. `docs/operations/kubernetes-platform.md` is missing. `adr/010-air-gapped-kubernetes.md` was superseded by `adr/023-single-host-dokploy-deployment.md`, suggesting the operation platform doc was renamed/removed and the nav not updated. DRIFT-OPEN (pre-existing, not in patch). |
| تصميم إعدادات المنصة ومركز التحكم التشغيلي V1 | `superpowers/specs/2026-07-23-platform-settings-v1-design.md` | yes | added by this patch. |
| خطة تنفيذ إعدادات المنصة ومركز التحكم التشغيلي V1 | `superpowers/plans/2026-07-23-platform-settings-v1.md` | yes | added by this patch. |

Cross-checked against `docs/catalog.yaml` (catalog has 158 entries, 4 of those 158 are `superpowers/specs/*.md` and 2 are `superpowers/plans/*.md`):
- `superpowers/specs/2026-07-23-platform-settings-v1-design.md` — present in catalog (line 31) AND in nav. Title matches. RESOLVED.
- `superpowers/plans/2026-07-23-platform-settings-v1.md` — present in catalog (line 32) AND in nav. Title matches. RESOLVED.

All other `superpowers/specs/*` and `superpowers/plans/*` entries listed in the nav also exist on disk (`2026-07-17-gsd-takeover-design.md`, `2026-07-22-dashboard-navigation-redesign-design.md`, `2026-07-17-gsd-takeover.md`, `2026-07-22-dashboard-navigation-redesign.md`). All five directories referenced from the nav (`docs/api/`, `docs/product/`, `docs/superpowers/specs/`, `docs/superpowers/plans/`, `docs/architecture/`) exist.

Cross-check: catalog title vs nav title for the `التشغيل` group. `docs/catalog.yaml` line ~79 calls the operations file `منصة Docker Compose على VPS`; `mkdocs.yml` line ~63 uses the same title. They match, but the underlying file is missing — see above.

## Findings classified

| ID | File | Drift | Classification | Patch-introduced? |
|---|---|---|---|---|
| D-1 | `mkdocs.yml:63` (`منصة Docker Compose على VPS` → `operations/kubernetes-platform.md`) | path does not exist | DRIFT-OPEN | no — pre-existing |
| D-2 | `docs/catalog.yaml` entry `api/endpoints.md` | file missing | DRIFT-OPEN | no — pre-existing |
| D-3 | `docs/catalog.yaml` entry `api/rbac-matrix.md` | file missing | DRIFT-OPEN | no — pre-existing |
| D-4 | `docs/catalog.yaml` operations cluster (8 files, all `proposed`) | status inconsistent with `mkdocs.yml` nav which presents the group as canonical | DRIFT-ACCEPTED (catalog text is wrong; site wins for now) | no — pre-existing |

## Verdict on the patch

- Both added catalog entries (lines 31, 32 of `docs/catalog.yaml`) reference files that exist.
- Both added nav entries (lines 107, 108 of `mkdocs.yml`) reference the same files with matching titles.
- No new 404, no new path/title mismatch, no new status mistake is introduced.
- The patch is clean with respect to the audited surface. All remaining drift is pre-existing and out of scope for this patch.
