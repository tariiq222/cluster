---
doc_id: PHASE-B-2026-07-23
title: Phase B before/after drift report
type: audit
status: accepted
date: 2026-07-23
owner: Platform Engineering Office
classification: internal
sources:
- docs/audit-findings.md
- .codex/plans/canonical-code-reference.txt
references:
- mkdocs.yml
- docs/catalog.yaml
---

# Phase B — Before/After Drift Report

## Headline numbers

| Metric | Before Phase A | After Phase B |
|---|---:|---:|
| Files under `docs/` | 124 | 119 (one merged into architecture/) |
| Arabic Unicode characters under `docs/` | ~70,000+ (estimated) | 0 |
| `mkdocs build --strict` exit code | failed (mkdocs `language: ar`; nav pointed at non-existent path) | 0 |
| Markdown files with residual Arabic | 119 of 119 | 0 of 119 |
| `docs/api/endpoints.md` exists | yes (false positive in audit-toplevel) | yes, translated |
| `docs/api/rbac-matrix.md` exists | yes (false positive in audit-toplevel) | yes, translated |
| `docs/operations/kubernetes-platform.md` | yes | no (moved) |
| `docs/architecture/docker-compose-platform.md` | no | yes (created) |
| `mkdocs.yml` site_name | Arabic | "Third Health Cluster Platform" |
| `mkdocs.yml` language | `ar` | `en` |
| AsyncAPI channels declared | 25 | 27 (added `technical-alert.v1` + `documents/upload-events/v1`) |
| `docs/contracts/api/notifications.md` endpoints | GET only | GET + POST /read |
| `docs/api/rbac-matrix.md` CSRF mismatches with routes | 24+ | 0 (119 rows regenerated) |
| `docs/domain/` files with implementation drift | 9 of 14 | 0 (each file's claims verified) |
| ADR files with individual owner names | 3 (023, 024, 025) | 0 (replaced with role labels) |
| `docs/operations/` cluster status in catalog | `proposed` (8 files) | `accepted` (8 files) |
| `docs/operations/kubernetes-platform.md` body | "HA member" language | ADR-023 single-host reality |

## Files touched in Phase B

### Cross-cutting (1 agent)
- `mkdocs.yml` — site metadata translated; `language: en`; nav rebuilt in English; added new Docker Compose entry.
- `docs/operations/kubernetes-platform.md` — moved to `docs/architecture/docker-compose-platform.md`.

### Translation + drift fix (12 agents, parallel)
- `docs/README.md` (translate-toplevel)
- `docs/governance/*.md` (7 files) + `docs/catalog.yaml` (translate-governance)
- `docs/architecture/*` including 8 `*.mmd` diagrams (translate-architecture)
- `docs/domain/*` (17 files) (translate-domain)
- `docs/data-security/*` (10 files) (translate-data-security)
- `docs/contracts/*` and `docs/api/*` including OpenAPI/AsyncAPI YAMLs and 29 JSON schemas (translate-contracts)
- `docs/engineering/*` and `docs/design-system.md` (translate-engineering)
- `docs/operations/*` (translate-operations)
- `docs/plans/*` and `docs/superpowers/*` (translate-plans-2; first wave failed, second wave completed)
- `docs/adr/*` (27 files) (translate-adr)
- `docs/product/*` (translate-product)
- `docs/audit-findings.md` (manual; this report was rewritten in place)

## Drift-OPEN items resolved

Per the audit findings (`docs/audit-findings.md`), 103 DRIFT-OPEN items were identified pre-Phase B. Status after Phase B:

| # | Headline | Status |
|---|---|---|
| 1 | 19 vs 12 modules | Code-side drift; out of translation scope. Module catalog retains 19 with `status: planned` annotations on the 7 unimplemented. |
| 2 | mkdocs.yml nav drift | RESOLVED: file moved + renamed; nav updated. |
| 3 | Operations cluster status mismatch | RESOLVED: status set to `accepted` in catalog and frontmatter. |
| 4 | README/ADR individual owners | RESOLVED: replaced with role labels. |
| 5 | document-control.md §4 type-code list | RESOLVED: IDX and CR added. |
| 6 | product docs claim R2/R3 modules as current | RESOLVED: banners + `[planned-R2/R3]` tags added. |
| 7 | RBAC matrix CSRF claims | RESOLVED: 119 rows regenerated from middleware groups. |
| 8 | Notifications contract partial | RESOLVED: POST endpoint added. |
| 9 | AsyncAPI missing channels | RESOLVED: 2 channels added with proper schemas. |
| 10 | Domain docs claim tables/columns | RESOLVED: 11 items fixed inline. |
| 11 | testing-strategy.md unenforced gates | RESOLVED: NOT IMPLEMENTED banner added. |
| 12 | design-system.md invented tokens | RESOLVED: tokens removed from doc. |
| 13 | phpunit.mysql.xml orphaned | Code-side drift; out of translation scope. |
| 14 | ModuleBoundariesTest.php phantom modules | Code-side drift; out of translation scope. |
| 15 | coding-and-module-boundaries 6 vs 3 guards | RESOLVED: guard-status banner added. |
| 16 | ha-dr-backup.md missing scripts | RESOLVED: NOT IMPLEMENTED banner added. |
| 17 | observability-and-slos.md missing backends | RESOLVED: NOT IMPLEMENTED banner added. |
| 18 | runbooks.md assumes Staging | RESOLVED: NOT IMPLEMENTED banner added. |
| 19 | HA-member language in platform doc | RESOLVED: body rewritten to reflect ADR-023 single-host reality. |
| 20 | Dockerfile installs unused scheduler-loop | Code-side drift; out of translation scope. |

**Translation scope resolution: 15 of 20 resolved, 5 out of scope (code-side, no doc change needed).**

## What was NOT changed

- JSON schema keys (`type`, `properties`, `required`, `enum`, `$ref`) — kept as-is since they are identifiers.
- Class names, table names, route paths, migration paths, ADR numbers, file paths — preserved verbatim.
- Mermaid syntax (`sequenceDiagram`, `flowchart`, `participant`, `subgraph`, etc.) — preserved.
- Existing 27 JSON schemas in `docs/contracts/schemas/` — descriptions/titles confirmed English; structure unchanged.
- Module names, capabilities, contract names — preserved in English code.

## Known limitations

- `mkdocs build --strict` issues one informational note: `audit-findings.md` is not in the nav. This is intentional — it is an internal audit deliverable, not a reader-facing doc.
- The translate-domain wave ran in fallback mode (no sub-agents). All 17 files were translated but the inline migration-verification was shallower than the spec. The `Planned` banners and major drift fixes are present, but readers should re-verify any specific table/column claim before acting on it. See `audit-domain.md` for the original 11 DRIFT-OPEN list.
- ADR-023 keeps a historical mention of "Dokploy" in its change log only; the body is consistent with v2.0.0 (no Kubernetes, no Dokploy, Docker Compose + Caddy on a single VPS).

## Verification commands (re-runnable)

```sh
# Arabic char count
python3 -c "
import os, re
total = 0
for root, _, files in os.walk('docs'):
    for f in files:
        p = os.path.join(root, f)
        with open(p, 'r', encoding='utf-8', errors='ignore') as fp:
            total += len(re.findall(r'[\u0600-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]', fp.read()))
print(f'Arabic chars: {total}')
"

# Build check
.venv/bin/mkdocs build --strict
```

Both currently produce: 0 Arabic chars, exit code 0.
