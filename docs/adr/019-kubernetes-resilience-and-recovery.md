---
doc_id: ADR-019
title:  Kubernetes 
type: adr
status: superseded
version: 1.1.0
date: 2026-07-16
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
supersedes:
- ADR-010
superseded_by:
- ADR-023
related_adrs:
- ADR-007
- ADR-013
- ADR-018
review_by: 2026-10-15
---
# ADR-019:  Kubernetes 

>    [ADR-023](023-single-host-dokploy-deployment.md)  Dokploy Docker Compose            .
## Context
   2,000         .
## Drivers
      on-premises.
## Decision
 Web/API Workers  replicas MySQL Cache/Queue Object Storage Search          Kubernetes  RPO ≤15  RTO ≤ .
## Scope
     leader-elected scheduler .
## Alternatives
         .
## Consequences
    SRE   .
## Security
       .
## Operations
      quarterly .
## Rollback
            .
## Enforcement
     RPO/RTO    .
## Review
quarterly    Kubernetes   .
## References
`docs/architecture/overview.md` `docs/data-security/threat-model.md`.
