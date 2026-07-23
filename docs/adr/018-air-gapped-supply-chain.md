---
doc_id: ADR-018
title: Air-Gapped Supply Chain
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
scope: Build, packages, images, and network
supersedes:
- ADR-010
superseded_by:
- ADR-023
related_adrs:
- ADR-012
- ADR-013
- ADR-019
review_by: 2026-10-15
---
# ADR-018:   

>    [ADR-023](023-single-host-dokploy-deployment.md)           Air-gap .
## Context
        .
## Drivers
       .
## Decision
  OCI  Composer npm AV      SBOM NetworkPolicy  egress   CDN    scripts .
## Scope
 CI/CD   DNS     .
## Alternatives
 pull      SaaS   .
## Consequences
  Offline     .
## Security
       .
## Operations
    registry    .
## Rollback
      SBOM     .
## Enforcement
`verify-airgap`  URL    NetworkPolicy default-deny.
## Review
quarterly     .
## References
`docs/data-security/threat-model.md` `docs/governance/assumptions-constraints.md`.
