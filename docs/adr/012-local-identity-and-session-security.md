---
doc_id: ADR-012
title: Local Identity and Session Security
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
scope: Identity, sessions, and privileged accounts
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-018
- ADR-020
review_by: 2026-10-15
---
# ADR-012:    
## Context
         PII .
## Drivers
     .
## Decision
    Argon2id      httpOnly  MFA      Break-glass .
## Scope
     .
## Alternatives
          .
## Consequences
       .
## Security
  CSRF          .
## Operations
        .
## Rollback
            .
## Enforcement
 Argon2id  MFA   Break-glass.
## Review
quarterly    .
## References
`docs/data-security/identity-session-security.md` `docs/data-security/threat-model.md`.
