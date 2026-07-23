---
doc_id: ADR-007
title: Transactional Outbox  
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
- ADR-011
- ADR-017
review_by: 2026-10-15
---
# ADR-007: Transactional Outbox  
## Context
         .
## Drivers
     .
## Decision
   Outbox      after-commit at-least-once  idempotent  `event_id` Inbox.
## Scope
  Read Models        .
## Alternatives
   commit    Outbox Event Sourcing.
## Consequences
      .
## Security
  schema version     payload    .
## Operations
retry  DLQ   lag   .
## Rollback
          .
## Enforcement
   idempotency schema compatibility DLQ.
## Review
quarterly    .
## References
`docs/architecture/overview.md` `docs/architecture/diagrams/outbox-sequence.mmd`.
