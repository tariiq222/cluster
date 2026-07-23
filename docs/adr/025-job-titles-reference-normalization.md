---
doc_id: ADR-025
title:      job_titles
type: adr
status: accepted
version: 1.0.0
date: 2026-07-22
owner: Engineering Office
reviewers: []
classification: internal
review_cycle:  
sources:
- docs/domain/organization-and-people.md
- docs/plans/active-delivery-status.md
- docs/contracts/api/openapi.yaml
references:
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/adr/024-organization-identity-import-boundaries.md
- docs/adr/011-lightweight-cqrs-and-transactions.md
- docs/engineering/database-migrations.md
deciders:
- Engineering Office
scope:  title_ar  Position   job_titles      
supersedes: []
superseded_by: []
related_adrs:
- ADR-004
- ADR-020
- ADR-024
review_by: 2027-07-21
---
# ADR-025:      job_titles
## Context
`Position`    (       
`AssignmentHandler`).      `title_ar`   `positions`
  `PositionCreate` `title`  (`minLength 1, maxLength 255`).
      (   )    
    (« »  « »)   
  .           
 `LAB-TECH-##`      .

## Drivers
            
     ADR-020.

## Decision
   `job_titles(id, code, title_ar, status)` 
`positions.job_title_id`   .  `job_titles`   
 `positions.title_ar`   (denormalized)  .  
   (`headcount`)  «//»  :
 =     =      = .

## Scope
  `job_titles`  `positions`  `GET/POST organization/job-titles`
          .  :   
   (    Role  ADR-004/ADR-020)  
        `AssignmentHandler`.

## Alternatives
- **     `title_ar`**:   (  
      ).
- **    `LAB-TECH-##`**:     .
- ** `PositionType`     **:    
   (`headcount`    )      R1–R3.

## Consequences
          
(     ).       
 .

## Security
    : `job_title_id`    `AuthorizationScope`
    Role.    `internal`  
.

## Operations
  `positions.title_ar`  `job_titles.title_ar`  . 
      « »  ADR-020.

## Rollback
 Expand-Contract: `job_title_id`  nullable   NOT NULL 
 backfill          
 `title_ar`     .

## Enforcement
:  `job_title_id`        backfill 
     .

## Review
      (establishment)     
 `PositionType` .

## References
`docs/domain/organization-and-people.md` `docs/contracts/api/openapi.yaml`
`docs/engineering/database-migrations.md`.
