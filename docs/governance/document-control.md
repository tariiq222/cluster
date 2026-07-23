---
doc_id: GOV-DC-001
title: Document Control
type: governance
status: accepted
version: 1.2.0
date: 2026-07-15
owner: Platform Engineering Office
reviewers:
- Governance Lead
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: quarterly
sources: []
references:
- docs/README.md
- docs/architecture/overview.md
- docs/architecture/module-catalog.md
- docs/adr/README.md
- docs/plans/implementation-roadmap.md
- docs/governance/glossary.md
- docs/governance/raci.md
- docs/governance/assumptions-constraints.md
- docs/governance/traceability-matrix.md
- docs/product/vision-and-scope.md
- docs/product/releases-and-roadmap.md
---
# Document Control

## 1. Purpose

This document defines a unified system for managing platform documentation at both governance and execution levels. It ensures that every document has a stable identity, a clear role owner, a verifiable status, and a known review cycle, and that a reader can trace the decision source, its owner, and its approver without ambiguity.

These controls apply to `docs/`, which is the sole approved source for platform documentation, decisions, and plans. All internal links and reference fields must point to current paths under `docs/`.

## 2. Control Principles

- A document is owned by a role, not a person; documents must not be tied to individual names.
- Every document has one role owner and reviewers from specific roles in `front matter`.
- A material change is recorded and announced in the change log; historical text must not be edited silently.
- Similar documents must not duplicate content; they must refer to the canonical document.
- Accepted versions are kept in the same repository, and status is indicated through `git` as verifiable evidence.

## 3. Hierarchical Folder Classification

| Folder | Scope | Content Owner | Change Sensitivity |
|---|---|---|---|
| `docs/governance/` | Platform and documentation governance and requirements | Platform Engineering Office | Quarterly review |
| `docs/product/` | Vision, scope, releases, and metrics | Product Owner | Quarterly review |
| `docs/architecture/` | Executable architecture decisions and diagrams | Software Engineering Lead | Semiannual review |
| `docs/domain/` | Domain specifications for each module | Module Owner | On every module change |
| `docs/data-security/` | Data model, security, and permissions | Information Security Lead | Semiannual review |
| `docs/operations/` | Operations, monitoring, and recovery | Operations Lead | Semiannual review |
| `docs/engineering/` | Implementation, testing, and release rules | Software Engineering Lead | On every change |
| `docs/contracts/` | Interface, event, and schema contracts | Software Engineering Lead | On every change |
| `docs/plans/` | Implementation, release, and readiness plans | Platform Engineering Office | Quarterly review |
| `docs/adr/` | Architecture decision records | Platform Architecture Council | Semiannual review |

## 4. Document Identifier

Each file is assigned a stable `doc_id` that does not change when its version or title changes. The format is:

```text
{family}-{type}-{serial-number}
```

| Family | Allowed Types | Examples |
|---|---|---|
| `GOV` Governance | `IDX` index, `DC` controls, `GL` glossary, `AC` assumptions and constraints, `RC` RACI, `TR` traceability, `CR` consistency review | `GOV-DC-001` |
| `PRD` Product | `VS` vision and scope, `PJ` personas and journeys, `RR` releases and roadmap, `SM` success metrics | `PRD-VS-001` |
| `ARC` Architecture | `AD` ADR, `BB` blueprint, `MB` boundaries, `VS` vertical slices, `FL` flows, `RM` implementation roadmap | `ARC-AD-001` |
| `DOM` Domain | `MN` module | `DOM-ORG-001` |
| `SEC` Security | `DM` data model, `AM` authorization model, `CL` classification, `AU` audit, `RT` retention | `SEC-DM-001` |
| `OPS` Operations | `DP` deployment, `MN` monitoring, `DR` recovery, `BC` backup | `OPS-DP-001` |

When a document is deleted, its `doc_id` remains reserved and its number is not reused.

## 5. File Naming

- Use `kebab-case` for Latin letters.
- Use the `.md` extension only.
- Do not use spaces or Arabic characters in file names.
- The file name reflects the `doc_id` in shortened form without the family prefix, such as `document-control.md` for `GOV-DC-001`.
- Use numbers in a file name only when they indicate a natural sequence, such as `release-1.md`.

## 6. Standard Front Matter Fields

Every Markdown document under `docs/`, as well as the repository-root `README.md`, starts with the following twelve fields without removing any of them:

```yaml
---
doc_id: {family}-{type}-{number}
title: {document title}
type: governance | product | architecture | domain | data-security | operations | engineering | contracts | plans | adr
status: draft | proposed | accepted | rejected | superseded
version: {SemVer}
date: {YYYY-MM-DD}
owner: {owning role}
reviewers:
  - {role}
classification: public | internal | confidential | top_secret
review_cycle: quarterly | semiannual | annual | on change
sources:
  - {source file path inside docs/, or an empty list}
references:
  - {reference file path inside docs/, or an empty list}
---
```

Required rules:

- `doc_id` is unique and immutable.
- Every field shown in the template is required in every file; `sources` and `references` may be empty lists.
- `title` is the displayed document title.
- `type` must come only from the allowed list.
- Additional fields are prohibited except in `adr` documents for decision properties such as supersession and scope.
- `owner` must be a role, not an individual.
- `reviewers` must be a list of at least two distinct roles.
- `classification` uses one canonical value: `public`, `internal`, `confidential`, or `top_secret`.
- The corresponding display labels are Public, Internal, Confidential, and Top Secret, respectively.
- `sources` and `references` must point exclusively to current files under `docs/`; paths outside the approved documentation source are not accepted.

## 7. Document Statuses

| Status | Meaning | Allowed Transition |
|---|---|---|
| `draft` | Draft not submitted for approval | To `proposed` |
| `proposed` | Proposed and under review | To `accepted`, `rejected`, or `draft` |
| `accepted` | Approved and in force | To `superseded` when a replacement exists |
| `rejected` | Proposal rejected and retained for traceability | No return; create a new proposal when needed |
| `superseded` | Replaced by a newer document | No return; remains read-only |

Transition to `accepted` requires written approval from all roles in `reviewers`, or their explicit delegation.

## 8. Versioning

Use `SemVer` in the form `MAJOR.MINOR.PATCH`:

- `MAJOR`: A change to the document scope or to a binding execution decision.
- `MINOR`: New content or an expanded subordinate scope.
- `PATCH`: An editorial correction, reference update, or clarification that does not change meaning.

The version number may be changed only through a `commit` accompanied by a note in the change log.

## 9. Review Cycle

| Document Type | Default Cycle | Exceptional Trigger |
|---|---|---|
| Governance | Quarterly | Organizational or legislative change |
| Product | Quarterly | Release or scope reprioritization |
| Executable architecture | Semiannual | A new constraint threatens the decision |
| Module domain | On every change | Any change to domain rules |
| Security | Semiannual | Vulnerability or legislative update |
| Operations | Semiannual | Infrastructure change or incident |
| Engineering or contracts | On every change | Implementation or contract change |
| Plans | Quarterly | Scope or dependency change |
| ADR | Semiannual | Decision constraints change or the decision is superseded |

The reviewer is the role named in `owner`. The review outcome is recorded in the change log with the date, reviewer role, and decision.

## 10. Change Log

Every document contains a `## Change Log` section at its end, using the following model:

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Initial creation |

Rows must not be deleted. Additions only.

## 11. Cross-Document References

- Do not copy the content of one document into another; link to it and cite it.
- Use the `path:section` form when needed for auditability.
- Update references when documents are moved or renamed in the same change `commit`.
- Any document that cites an architecture decision includes the ADR number in its `references` field.

## 12. Owner and Reviewers

| Document | Owner | Reviewers |
|---|---|---|
| `docs/governance/*` | Platform Engineering Office | Governance Lead, Information Security Lead |
| `docs/product/*` | Product Owner | Platform Engineering Office, Operations Lead |
| `docs/architecture/*` | Software Engineering Lead | Platform Engineering Office, Information Security Lead |
| `docs/domain/*` | Module Owner | Software Engineering Lead, Information Security Lead |
| `docs/data-security/*` | Information Security Lead | Platform Engineering Office, Operations Lead |
| `docs/operations/*` | Operations Lead | Platform Engineering Office, Information Security Lead |
| `docs/engineering/*` | Software Engineering Lead | Platform Engineering Office, Information Security Lead |
| `docs/contracts/*` | Software Engineering Lead | Platform Engineering Office, Information Security Lead |
| `docs/plans/*` | Platform Engineering Office | Product Owner, Operations Lead |
| `docs/adr/*` | Platform Architecture Council | Software Engineering Lead, Information Security Lead |

## 13. Content Controls

- Individual names are prohibited in execution documents; use roles instead.
- Documentation is written in English, and core terms are maintained in `docs/governance/glossary.md`.
- Use tables and numbered lists rather than prose when presenting requirements and decisions.
- Every requirement has a measurable acceptance criterion on the same line or in a separate field.
- Remove promotional and repetitive paragraphs; refer to the canonical source instead.

## 14. Tool Controls

- Write documentation using `write` and `edit` tools only; shell heredocs are prohibited.
- Save changes in `git` as one `commit` per document or logically related group.
- Do not include personal or operational data in document examples.
- Binary attachments are prohibited in `git`; store them in a separate repository and link to them.

## 15. Immediate Implementation

These controls apply to all current and new Markdown files under `docs/` and to the repository-root `README.md`. `docs/catalog.yaml` records every file under `docs/` exactly once, including the catalog itself, and `mkdocs.yml` records every Markdown file under `docs/` exactly once. Automated validation rejects missing fields, unapproved values, versions that do not match SemVer, missing references, links, or sections, incompleteness markers, a singular legacy documentation folder, empty folders, and any catalog or navigation omission or duplicate.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Platform Engineering Office | Initial creation defining the document identifier, review cycle, and standard front matter |
| 1.1.0 | 2026-07-15 | Platform Engineering Office | Adopted `docs/` as the sole source and standardized classification codes and references |
| 1.2.0 | 2026-07-15 | Platform Engineering Office | Standardized fields, types, and statuses and enforced catalog, navigation, and automated-validation completeness |

## Known Drift

- **DC-3 — unresolved in the governance audit:** The audit reported that Section 4 did not enumerate `IDX` (used by `GOV-IDX-001`) or `CR` (used by `GOV-CR-001`). The correction has been applied inline in Section 4 by adding both allowed GOV type codes. The audit item remains listed as unresolved until the audit record is refreshed.
