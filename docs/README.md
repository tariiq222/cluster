---
doc_id: GOV-DOC-001
title: Platform Documentation
type: governance
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Platform Engineering Office
reviewers:
- Governance Lead
- Information Security Lead
classification: internal
review_cycle: quarterly
sources: []
references: []
---
# Platform Documentation

This is the single entry point for accepted documentation. Use the [catalog](catalog.yaml) for metadata and the status of every file.

| Section | Purpose |
|---|---|
| [Governance](governance/README.md) | Controls, glossary, traceability, and responsibilities |
| [Product](product/README.md) | Vision, journeys, releases, and success metrics |
| [Plans](plans/README.md) | Implementation map, release plans, and readiness |
| [Data & Security](data-security/README.md) | Data model, permissions, privacy, and threats |
| [Domain](domain/README.md) | Platform module specifications |
| [Architecture](architecture/README.md) | Boundaries, dependencies, diagrams, and non-functional requirements |
| [Operations](operations/README.md) | Air-gapped platform, recovery, observability, and response |
| [Engineering](engineering/README.md) | Implementation, testing, and release rules |
| [Contracts](contracts/README.md) | HTTP, event, and schema contracts |
| [Architecture Decisions](adr/README.md) | Official ADR log |

Specialized documents are the source of truth for their subject. When documents conflict, defer to the most recent accepted ADR, then to the document the catalog marks as the source of truth.
