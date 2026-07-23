---
doc_id: ADR-024
title:  Organization Identity  
type: adr
status: accepted
version: 1.1.0
date: 2026-07-17
owner: Engineering Office
reviewers: []
classification: internal
review_cycle:  
sources:
- docs/plans/release-1-platform.md
- docs/domain/organization-and-people.md
- docs/domain/identity.md
- docs/architecture/context-map.md
- docs/architecture/module-catalog.md
- docs/governance/glossary.md
- docs/data-security/logical-data-model.md
- docs/data-security/audit-and-privacy.md
references:
- docs/adr/003-module-boundaries.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/007-transactional-outbox.md
- docs/adr/011-lightweight-cqrs-and-transactions.md
- docs/adr/012-local-identity-and-session-security.md
- docs/adr/020-organization-and-time-bounded-authority.md
- docs/architecture/dependency-rules.md
- docs/data-security/authorization-model.md
- docs/data-security/file-security.md
- docs/data-security/identity-session-security.md
- docs/engineering/database-migrations.md
deciders:
- Engineering Office
scope:  Person    Organization Identity  W1.2
supersedes: []
superseded_by: []
related_adrs:
- ADR-003
- ADR-004
- ADR-007
- ADR-011
- ADR-012
- ADR-020
review_by: 2027-01-17
---
# ADR-024:  Organization Identity  

## Context

  Organization  `Person`     
`Organization`   Identity      `Identity`
 `person_id` .        `Person`  Identity 
 FK  Organization  `users.id`.       W12-00
   join  FK    .

## Drivers

-       .
-  `Organization`    `Identity`   .
-         .
-       provisioning    .
-    fail-closed         .

## Decision

1.  `Organization`  `Person` `OrganizationUnit` `Position` `Assignment`
       PII  .
2.  `Identity`  `UserAccount`     .
    `person_id`     FK  ORM relation  join  
   Organization.  snapshot   `display_name_ar` `display_name_en` 
         .  snapshot      
        .
3.  Identity         Organization   
   snapshot    .          
    Authorization        .  
   `Identity -> Organization`       .
4.   actor  `created_by_user_id` `submitted_by_user_id` `approved_by_user_id`
         FK   Identity    .
5.  Organization       . 
           Organization Outbox 
     .
6.  Identity provisioning    .   
   Organization  event  `IdentityProvisioningRequested`   Outbox  
   `ApplyImportJob`    Person    .  `event_id`
   `person_id` `person_version`   correlation  schema  PII 
   .  Identity           Inbox
   .      MFA  recovery  bootstrap  tokens  
     .
7.    apply          
    Organization  provisioning     .
8.  `Authorization`   allow/deny.  Organization   
    Identity         .
9.  W1.2     Person.    `pending` `active` `locked`
   `disabled` `archived`.   `Suspended`      
   `disabled`     `archived`   . 
   `IdentityProvisioningRequested` `PersonAccessStatusChanged`  `person_version`
      Person.  Identity
   Inbox  high-water mark       `<=` . 
   `Suspended`   `Left`       idempotently.
           cache      
   .

## Scope

   Person       Organization
Identity   provisioning   .   payloads 
 migrations      RBAC + ABAC     W1.2
W1.3   .

## Alternatives

- **Person  Identity:**         
        PII    .
- **    :**       drift 
    .
- **FK  join   :**       .
- **   :**        
  provisioning .
- ** Import :**   W1.2          
        .

## Consequences

-  Organization       Identity  
    .
-  context map module catalog       
         FK  .
-      provisioning   status    
     .
-   schema    provisioning     W1.2  .
-  `PlatformSettings`  Identity       .

## Security

-  PII   Organization      .
            quarantine 
            .
-     provisioning    fail-closed  actor subject
  correlation       tokens  payload   .
-       .      
    ADR-012   Organization   Identity.
-  bootstrap           MFA
   .     provisioning       
   break-glass   Identity.

## Operations

-   backlog   provisioning     
   .
-   `event_id` `person_id` correlation   consumer  
  Inbox  checkpoint .
-       revision   .
-  Audit  actor  snapshot        subject
   hash    FK   payload .

## Rollback

        .       
            down migration
.   expand  migrate  verify  contract   
    .

## Enforcement

- : `./scripts/validate-docs.sh` `make verify-boundaries`   ADR  .
-  W12-00:  ownership dependency map   FK   .
-  :   imports joins FKs     Person
   Outbox   provisioning   idempotency key.
-  :  allow deny fail-closed     
              .

## Review

    context map module catalog    
      .      Person 
        provisioning.

## References

`docs/domain/organization-and-people.md` `docs/domain/identity.md`
`docs/architecture/context-map.md` `docs/architecture/module-catalog.md`
`docs/governance/glossary.md` `docs/plans/release-1-platform.md`.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-18 |  |    taxonomy    W1.2 |
| 1.0.0 | 2026-07-17 |    |   Person     provisioning |
