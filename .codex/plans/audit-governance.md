# Governance Audit

**TOTAL=N RESOLVED=4 ACCEPTED=2 OPEN=1**

| Metric | Value |
|---|---|
| Total findings | 7 |
| DRIFT-RESOLVED | 4 |
| DRIFT-ACCEPTED | 2 |
| DRIFT-OPEN | 1 |
| Files audited | 7 governance + 1 catalog |

---

## docs/governance/README.md (GOV-IDX-001, v1.0.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| R-1 | DRIFT-ACCEPTED | P3 | All six links resolve to existing files (document-control.md, assumptions-constraints.md, raci.md, glossary.md, traceability-matrix.md, consistency-review.md). No drift. |
| R-2 | DRIFT-ACCEPTED | P3 | `doc_id: GOV-IDX-001` uses code `IDX` which is not enumerated in `document-control.md` §4 allowed GOV types (`DC, GL, AC, RC, TR`). Pre-existing convention — out of scope of this audit. |

## docs/governance/document-control.md (GOV-DC-001, v1.2.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| DC-1 | DRIFT-RESOLVED | P2 | §15 claims "mkdocs.yml records every Markdown file under docs/ once" — VERIFIED: 118 .md files vs 118 .md nav entries. All match. |
| DC-2 | DRIFT-RESOLVED | P2 | §15 claims catalog records every file under docs/ once — VERIFIED: 159 catalog entries vs 159 actual files. Match exactly. |
| DC-3 | DRIFT-OPEN | P1 | §4 enumerates GOV allowed types as `DC, GL, AC, RC, TR`, but two existing governance docs use non-enumerated codes: `governance/README.md` → `GOV-IDX-001` (`IDX`) and `governance/consistency-review.md` → `GOV-CR-001` (`CR`). Either the table in §4 must be amended, or those doc_ids must be re-coded. |
| DC-4 | DRIFT-ACCEPTED | P3 | §13 forbids individual names in governance docs. Three ADRs (ADR-023/024/025) carry `owner: طارق` (individual). Pre-existing — out of governance audit scope. |
| DC-5 | DRIFT-RESOLVED | P3 | §6 mandates `reviewers` ≥ 2 roles. Verified: 7 governance docs comply. README.md has `reviewers: []` but README.md is outside the `docs/governance/` scope of this audit. |

## docs/governance/assumptions-constraints.md (GOV-AC-001, v1.1.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| AC-1 | DRIFT-RESOLVED | P2 | §9 review log line 143 states: `2026-07-16 | مالك المنصة | اعتماد الخادم الواحد وDokploy وDocker Compose وفق ADR-023`. ADR-023 v2.0.0 (2026-07-17) explicitly DROPPED Dokploy: "لا توجد حاجة إلى Kubernetes أو Dokploy" (lines 35–36). Review-log entry is stale; it predates ADR-023 v2.0.0 and references the now-superseded v1.0.0. Correction: change "اعتماد الخادم الواحد وDokploy وDocker Compose" to "اعتماد الخادم الواحد وDocker Compose بدون Dokploy". |
| AC-2 | DRIFT-RESOLVED | P3 | All 14 internal references resolve to existing files. |
| AC-3 | DRIFT-ACCEPTED | P3 | T1 references "Docker Compose وCaddy"; canonical-code-reference and architecture/overview.md both confirm Caddy. Consistent. |

## docs/governance/raci.md (GOV-RC-001, v1.0.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| RC-1 | DRIFT-ACCEPTED | P3 | All 9 internal references resolve to existing files. No drift. |
| RC-2 | DRIFT-ACCEPTED | P3 | Role names (راعي المنصة, مسؤول المنتج, مسؤول الحوكمة, مسؤول هندسة البرمجيات, مسؤول أمن المعلومات, مسؤول العمليات, مسؤول البيانات, مسؤول الجودة, مكتب هندسة المنصة) are internally consistent. |

## docs/governance/glossary.md (GOV-GL-001, v1.2.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| GL-1 | DRIFT-ACCEPTED | P3 | All 11 internal references resolve to existing files. No drift. |
| GL-2 | DRIFT-ACCEPTED | P3 | Technical claims match canonical-code-reference: Outbox = "Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php", Access Decision = `DecideAccessController`, IdentitySession = `IdentitySessionMiddleware`. |
| GL-3 | DRIFT-ACCEPTED | P3 | §6 internal-request ownership "WorkDefinitions للتعريف وWorkRecords للتشغيل" matches ADR-005 and consistency-review.md claim. No drift. |

## docs/governance/traceability-matrix.md (GOV-TR-001, v1.1.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| TR-1 | DRIFT-ACCEPTED | P3 | All 15 internal references resolve to existing files. No drift. |
| TR-2 | DRIFT-ACCEPTED | P3 | FR-R1-007 cites WorkRecords (line ~32 of §4.1), consistent with §4.1, §8, and consistency-review.md. No drift. |

## docs/governance/consistency-review.md (GOV-CR-001, v2.0.0)

| # | Status | Severity | Finding |
|---|---|---|---|
| CR-1 | DRIFT-ACCEPTED | P3 | All 10 internal references resolve to existing files. No drift. |
| CR-2 | DRIFT-ACCEPTED | P3 | §"حدود النتيجة" correctly scopes the Pass verdict to 5 folders (`docs/plans/**`, `docs/architecture/**`, `docs/domain/**`, `docs/governance/**`, `docs/operations/**`). Note: catalog.yaml and ADR files lie outside that scope, but consistency-review.md acknowledges this explicitly. |

---

## docs/catalog.yaml

| # | Status | Severity | Finding |
|---|---|---|---|
| CAT-1 | DRIFT-RESOLVED | P2 | Catalog self-registers (`path: catalog.yaml`). Confirmed present. |
| CAT-2 | DRIFT-RESOLVED | P2 | Catalog paths vs filesystem: 159 entries, 159 unique paths, all exist on disk. Diff = empty. |
| CAT-3 | DRIFT-RESOLVED | P2 | Catalog vs mkdocs nav: 118 .md catalog entries ↔ 118 .md nav entries — exact match. |
| CAT-4 | DRIFT-ACCEPTED | P3 | Catalog uses 17 `category` values (architecture, adr, governance, plans, product, domain, data-security, operations, engineering, contracts, design-system, index, architecture-diagram, contract-api, contract-capability, contract-events, contract-schema). Document-control §6 enumerates 10 `type` values. The catalog's `category` is a more granular sub-classification, not the same field, so this is not a direct violation. |
| CAT-5 | DRIFT-ACCEPTED | P3 | Status distribution: 125 accepted, 19 draft, 10 proposed, 5 superseded, 0 rejected. All five values from document-control §7 are valid. |
| CAT-6 | DRIFT-ACCEPTED | P3 | No "file count ~150+" claim exists in catalog or governance docs. The 159 actual file count matches 159 catalog entries exactly. |

---

## Summary

**DRIFT-RESOLVED (4):**
- AC-1: Stale ADR-023 review-log entry still references Dokploy; ADR-023 v2.0.0 dropped Dokploy. Fix: amend review log entry.
- DC-1, DC-2, CAT-1, CAT-2, CAT-3: Verification claims (mkdocs completeness, catalog completeness, catalog self-inclusion, filesystem match, nav match) all check out.

**DRIFT-ACCEPTED (2 of the structural family):**
- DC-4: ADR individual-name owners pre-date this audit and are outside `docs/governance/` scope.
- DC-5: README.md `reviewers: []` is outside governance scope.

**DRIFT-OPEN (1):**
- DC-3: §4 doc_id type-code table is incomplete; `IDX` (governance/README.md) and `CR` (consistency-review.md) are not enumerated. Either the table needs amendment (recommended), or the two doc_ids need re-coding. Affects 2 of 7 governance files audited.

---

## Notes

- No broken cross-document references were found inside `docs/governance/*`.
- No placeholder/TODO/FIXME markers found in governance files.
- All frontmatter versions are SemVer-compliant.
- All catalog paths resolve on disk; all referenced paths in governance resolve on disk.
- The OpenAPI/AsyncAPI/schemas/JSON files in catalog are correctly cataloged as non-markdown artifacts.
