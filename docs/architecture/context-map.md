---
doc_id: ARC-CM-001
title: Architecture Context Map
type: architecture
status: accepted
version: 1.2.0
date: '2026-07-15'
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources: []
references: []
---
# Context Map

## 1. Scope of the map

This document defines the context boundaries, dependency direction, and integration relationships between the nineteen modules defined in [Architecture Overview](overview.md). The arrow `A → B` means that `A` depends on a published contract from `B`; it does not mean that `A` owns `B`'s data.

## 2. Context layers

```mermaid
flowchart TB
    subgraph L11["Derived terminal consumers"]
        N["Notifications"]
        S["Search"]
        R["Reporting"]
        WS["Workspace"]
    end

    subgraph L10["Risk"]
        RK["Risk"]
    end

    subgraph L9["Portfolios and projects"]
        PP["PortfolioProjects"]
    end

    subgraph L8["Records and strategy"]
        WR["WorkRecords"]
        ST["Strategy\nSole owner of indicators"]
    end

    subgraph L7["Execution"]
        T["Tasks"]
    end

    subgraph L6["Collaboration"]
        C["Collaboration"]
    end

    subgraph L5["Definition and content"]
        WD["WorkDefinitions"]
        D["Documents"]
    end

    subgraph L4["Operation and governance"]
        WF["Workflow"]
        RG["RecordsGovernance"]
    end

    subgraph L3["Audit"]
        AU["Audit"]
    end

    subgraph L2["Access decision"]
        AZ["Authorization"]
    end

    subgraph L1["Identity"]
        I["Identity"]
    end

    subgraph L0["Roots"]
        O["Organization"]
        PS["PlatformSettings"]
    end

    I --> O
    I --> PS
    AZ --> I
    AZ --> O
    AZ --> PS
    AU --> AZ

    WF --> O
    WF --> AZ
    WF --> AU
    RG --> PS
    RG --> AZ
    RG --> AU

    WD --> PS
    WD --> WF
    WD --> AZ
    WD --> AU
    D --> RG
    D --> AZ
    D --> AU

    C --> D
    C --> RG
    C --> AZ
    C --> AU

    T --> I
    T --> C
    T --> D
    T --> RG
    T --> AZ
    T --> AU

    WR --> WD
    WR --> WF
    WR --> T
    WR --> C
    WR --> D
    WR --> RG
    WR --> AZ
    WR --> AU

    ST --> O
    ST --> WF
    ST --> T
    ST --> C
    ST --> D
    ST --> RG
    ST --> AZ
    ST --> AU

    PP --> O
    PP --> ST
    PP --> WF
    PP --> T
    PP --> C
    PP --> D
    PP --> RG
    PP --> AZ
    PP --> AU

    RK --> O
    RK --> ST
    RK --> PP
    RK --> WF
    RK --> T
    RK --> C
    RK --> D
    RK --> RG
    RK --> AZ
    RK --> AU

    N -.-> I
    N -.-> AZ
    S -.-> AZ
    R -.-> O
    R -.-> AZ
    WS -.-> AZ

    N -.-> WR
    N -.-> WF
    N -.-> T
    N -.-> C
    N -.-> ST
    N -.-> PP
    N -.-> RK

    S -.-> WR
    S -.-> T
    S -.-> C
    S -.-> D
    S -.-> ST
    S -.-> PP
    S -.-> RK

    R -.-> WR
    R -.-> WF
    R -.-> T
    R -.-> ST
    R -.-> PP
    R -.-> RK

    WS -.-> WR
    WS -.-> WF
    WS -.-> T
    WS -.-> C
    WS -.-> ST
    WS -.-> PP
    WS -.-> RK
```

Solid arrows are allowed synchronous contracts. Dashed arrows indicate dependency on Published Events or Projection Feeds in addition to calling `Authorization` at read time. Every arrow points to a lower rank; terminal modules do not expose contracts that source modules depend on.

## 3. Direct dependency matrix

| Module | Depends directly on |
|---|---|
| `PlatformSettings` | nothing |
| `Organization` | nothing |
| `Identity` | `Organization`, `PlatformSettings` |
| `Authorization` | `Identity`, `Organization`, `PlatformSettings` |
| `Audit` | `Authorization` |
| `Workflow` | `Organization`, `Authorization`, `Audit` |
| `RecordsGovernance` | `PlatformSettings`, `Authorization`, `Audit` |
| `WorkDefinitions` | `PlatformSettings`, `Workflow`, `Authorization`, `Audit` |
| `Documents` | `RecordsGovernance`, `Authorization`, `Audit` |
| `Collaboration` | `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Tasks` | `Identity`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `WorkRecords` | `WorkDefinitions`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Strategy` | `Organization`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `PortfolioProjects` | `Organization`, `Strategy`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Risk` | `Organization`, `Strategy`, `PortfolioProjects`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Notifications` | `Identity`, `Authorization`, and source module events |
| `Search` | `Authorization`, and indexable content events |
| `Reporting` | `Organization`, `Authorization`, and source events or Projection Feeds |
| `Workspace` | `Authorization`, and source work item events |

## 4. Relationship patterns

| Pattern | Use | Rule |
|---|---|---|
| Published Contract | immediate decision or shared invariant | The contract is owned by the offering module and returns an immutable DTO |
| Published Event | an event that occurred with deferred consumption | The producer owns the past-tense schema and persists it in the Outbox |
| Projection Feed | large reporting projection or rebuild | The owner offers a controlled, paginated feed and does not expose its table |
| Reference by ID | linking a record to a record in another context | stable identifier with verification via a contract; no cross-module Foreign Key or Join when module independence forbids it |
| Record Reference | general link to a source | `{record_type, record_id}` with re-delegation when the source is opened |
| Customer/Supplier | higher-ranked module consumes the lower-ranked module's contract | The consumer does not impose its storage model on the supplier |

## 5. Fact ownership map

| Fact | Sole owner | What others keep |
|---|---|---|
| Published platform settings | `PlatformSettings` | key and version, or invalidable cache |
| Person and basic PII | `Organization` | `person_id` and a limited display summary, no FK or join |
| Structure, units, positions, relations | `Organization` | identifiers and derived summaries |
| Account, session, operational profile | `Identity` | `user_id` and a display summary |
| Role, capability, delegation, field policy | `Authorization` | decision or temporary `decision_id`, no policy copy |
| Work type definition and version | `WorkDefinitions` | `work_type_version_id` |
| Dynamic work instance, including the request | `WorkRecords` | `record_ref` and derived projections |
| Route definition, instances, decisions | `Workflow` | `workflow_instance_id` |
| Comments, mentions, participants | `Collaboration` | `thread_id` |
| Task, state, assignee | `Tasks` | `task_id` or workspace projection |
| Document, version, classification | `Documents` | `document_id` and link reason |
| Retention, hold, and disposition policies | `RecordsGovernance` | `governance_subject_id` or decision result |
| Indicator definition, measurements, targets | `Strategy` | `indicator_id` and local planning data only |
| Portfolio, program, project | `PortfolioProjects` | identifiers, links, events |
| Risk, controls, treatment plan | `Risk` | identifiers, links, events |
| Immutable security and operational fact | `Audit` | `audit_event_id` only |
| Search result | `Search` | source modules do not rely on it for truth |
| Report and Read Model | `Reporting` | does not write to the source |
| Workspace item | `Workspace` | derived pointer to the source record |
| Notification | `Notifications` | redirect to the source re-delegated on open |

## 6. RecordFacts and Authorization without cycles

`RecordFacts` is a published language whose schema is owned by `Authorization`. The owning module builds the values from the Envelope or Aggregate it owns and passes them along with the actor and the requested capability:

```text
RecordFacts
- record_type
- record_id
- owner_organization_id
- owner_organization_unit_id
- created_by_user_id
- current_assignee_user_id optional
- participant_user_ids or share group key
- visibility_scope
- shared_organization_unit_ids
- classification
- state
- definition_version_id optional
- field_policy_key optional
- attributes limited and declared per capability
```

```mermaid
sequenceDiagram
    participant Caller as Owning Module
    participant Auth as Authorization
    participant Identity as Identity
    participant Org as Organization

    Caller->>Caller: Load non-sensitive Envelope and build RecordFacts
    Caller->>Auth: DecideAccess(actor, capability, RecordFacts)
    Auth->>Identity: ResolveActiveIdentity
    Identity-->>Auth: Account state only
    Auth->>Org: ResolveOrganizationScope
    Org-->>Auth: Active scope and relations
    Auth->>Auth: RBAC + ABAC + classification + state + fields
    Auth-->>Caller: AccessDecision + allowed_fields + explanation_code
```

Cycle-prevention rules:

- `Authorization` does not import `WorkRecords`, `Tasks`, `Documents`, or any business module.
- `Authorization` does not read record tables or request payload from them.
- The owning module is responsible for the correctness of `RecordFacts` and does not accept them from the client.
- Bulk queries ask `Authorization` for a `ScopePredicate` or `AuthorizedScopeSet` and apply it inside the owner's store.
- `Search` and `Reporting` store derived authorization facts sufficient for the initial filter, then re-check the decision when the record is opened or sensitive fields are exported.
- `Tasks`, `Documents`, and `Collaboration` do not call the source module to verify; the owner's endpoint or the orchestrating application re-passes the correct `RecordFacts`.

## 7. WorkRecords, WorkDefinitions, and Workflow

```mermaid
flowchart LR
    WD["WorkDefinitions\nSchema + immutable version"]
    WR["WorkRecords\nEnvelope + payload + business state"]
    WF["Workflow\nDefinition/version + execution state"]
    T["Tasks\nIndependent task state"]

    WR -->|"GetPublishedWorkTypeSchema"| WD
    WR -->|"StartWorkflow / RecordDecision"| WF
    WR -->|"CreateTask when atomic"| T
    WF -.->|"WorkflowCompleted"| WR
```

The last asynchronous arrow does not mutate `WorkRecords` automatically inside a hidden consumer when the business transition is binding; an explicit coordinator issues the Command to `WorkRecords`. `Workflow` does not own the meaning of completing a request or project.

## 8. Strategy, PortfolioProjects, and Risk

```mermaid
flowchart LR
    ST["Strategy\nPlans, objectives, initiatives, indicators"]
    PP["PortfolioProjects\nPortfolios, programs, projects"]
    RK["Risk\nRisks, controls, treatments"]

    PP -->|"Indicator contracts"| ST
    RK -->|"Objective and indicator references"| ST
    RK -->|"Portfolio/project references"| PP
```

- An initiative is an element inside `Strategy` and does not enter the portfolio/program/project chain.
- The only sequence in `PortfolioProjects` is: portfolio ← program ← project.
- `PortfolioProjects` does not own the indicator; it submits expected and actual impact to `Strategy` for approval.
- `Risk` does not copy an objective, indicator, or project; it uses `Tasks` for treatment plans, `Workflow` for approvals, and `Documents` for evidence.

## 9. Derived terminal contexts

`Notifications`, `Search`, `Reporting`, and `Workspace` are terminal consumers relative to business sources:

- the source does not call them synchronously inside its transaction.
- they do not write to source tables.
- they process the event more than once safely.
- they keep a progress mark or an Inbox that prevents duplicate effects.
- their projections can be rebuilt from events or Projection Feeds.
- they do not use a derived result to take a domain transition without re-checking the owner's contract.

## 10. Systems outside the context

There are no external integrations in phase one. "Mawared" and the financial and clinical systems are future entities outside the platform boundary. No Adapter or Contract is created for them before specifying the system, the data, the direction, the owner, and the security and isolation requirements.
