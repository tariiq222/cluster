---
doc_id: ADR-005
title: WorkRecords  
type: adr
status: accepted
version: 1.0.0
date: 2026-07-15
owner: Platform Architecture Council
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: semiannual
sources: []
references: []
deciders:
- Platform Architecture Council
scope:    
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-006
- ADR-021
review_by: 2027-01-15
---
# ADR-005: WorkRecords  
## Context
            .
## Drivers
     .
## Decision
   `WorkRecord`   `Requests` module/table/events  Envelope  payload      typed.
## Scope
          .
## Alternatives
 EAV  JSON    Aggregate  Requests.
## Consequences
       .
## Security
 Envelope     `RecordFacts`  .
## Operations
       `lock_version`.
## Rollback
            .
## Enforcement
  `Request*`     optimistic locking.
## Review
       .
## References
`docs/domain/work-records.md` `docs/architecture/overview.md`.
