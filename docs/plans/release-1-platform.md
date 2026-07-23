---
doc_id: PLN-R1-001
title: R1 Fast Plan — Administrative Work Platform
type: plans
status: accepted
version: 6.2.0
date: 2026-07-19
owner: Technical Implementation
reviewers: []
classification: internal
review_cycle: daily until R1 completion
sources:
- docs/plans/active-delivery-status.md
- docs/plans/implementation-roadmap.md
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/006-workflow-versioning.md
- docs/adr/007-transactional-outbox.md
references:
- docs/plans/release-1/w1-2-frontend-slices.md
- docs/engineering/delivery-workflow.md
---
# R1 Fast Plan

## Goal

Complete the administrative work platform from W1.3 through the integrated R1
journey during the first three days of the five-day roadmap. W1.1 and W1.2 are
finished and are not included in estimation.

No approval stage, manual UAT, or package owners. Each wave is a vertical slice
that ends with a real API, frontend, and test. The review and approval actions
inside Workflow are configurable product features, not human gates on code
development.

## What Is Delivered

| Wave | Status | Evidence |
|---|---|---|
| W1.1 Walking Skeleton | Complete | `make verify-w1-1` and `make verify-w1-1-local` |
| W1.2 Organization + Identity + Import | Complete | `make verify-w1-2` and `infra/dev/run-w1-2-e2e.sh` |

W1.2 covers cluster, facilities, units, positions, Person, Identity, accounts,
temporary assignments, and the signed-and-scanned import. Its planning details
are not reopened. The R1 journey through W1.10 is functionally complete, but
the W1.3 security closure remains open per
`release-1/w1-3-frontend-slices.md` because the real role engine has not cut
over from the fixture.

## Day 1: W1.3 Authorization

### Output

A central, explainable access decision that consumes Organization and Identity
facts and applies the same outcome to resources and fields. The day starts by
merging existing work in `work-1-3*` instead of rewriting it.

### Required Scope

- Role, Capability, RoleAssignment, Delegation, and ExplicitDeny.
- SupervisoryRelationship and RelationshipCapability with time bounds.
- ClassificationPolicy, FieldAccessTemplate, and SensitiveAccessEvent.
- `DecideAccess`, `ExplainAccessDecision`, and read-scope filtering.
- Minimum API for managing assignments, relationships, and policies, plus a
  single React page for administration and explanation.

### Automated Acceptance

- A facility employee cannot read, search, or export another facility's data.
- Explicit deny and higher classification override general allow.
- Role, relationship, or delegation expiration withdraws the effect without
  deleting history.
- The hidden/readonly/editable field decision is applied in response and
  write.
- Sensitive access is logged append-only without PII.
- No FK or join between Authorization and Organization or Identity.

### Closure

The targeted Authorization and Organization tests, `make verify-boundaries`,
the Web build, and a single E2E journey that proves allow, deny, and
explanation. This closure is not final unless the journey uses the production
session and the `RbacAbacDecideAccess` engine, and proves that role granting,
revocation, delegation, explicit deny, and field policy change the API, search,
report, and download in the same way.

## Day 2: W1.4–W1.7 Work Cycle

Four independent packages run in parallel, then merge into one journey.

### W1.4 WorkDefinitions

- A work type with its fields, model, and display list.
- Draft then Published immutable, and the version pinned to in-flight records.
- Field disabling instead of data deletion, and compatibility check before
  publish.
- A definition bundle exportable and importable without data or secrets.

Acceptance: create a type from React and publish a second version without
changing a record pinned to the first version.

### W1.5 Workflow

- WorkflowDefinition, WorkflowVersion, Instance, StepInstance, and Decision.
- Minimum: start, end, review, approve, reject, return, task, wait, and
  condition.
- Resolved actor from capability or relationship, and a fallback path when
  absent.
- Immutable path version pinned to the transaction, with Outbox and idempotency.

Acceptance: submit a request, advance it, return it for edit or reject it, and
keep an old transaction on its version after a new version is published.

Advanced patterns like majority, quorum, and parallel merge do not block R1;
they are added after the core journey if time remains.

### W1.6 WorkRecords

- `request` remains a published type, not a separate request module.
- Draft, Submitted, InProgress, Returned, Rejected, and Completed.
- RecordParticipant, RecordRelation, and RecordActivity inside WorkRecords.
- A "My Requests" list, a unit inbox, and optional task and document links.

Acceptance: an intra-facility request and an inter-unit request pass from
creation to closure with isolation, activity, and Outbox in the same
transaction.

### W1.7 Tasks

- A task with owner, participants, comment, mention, and activity, standalone
  or linked to a record.
- Assignment is subject to `DecideAccess`, and mention does not change
  responsibility.
- States: Open, InProgress, Blocked, Done, and Cancelled.

Acceptance: a task created from a Workflow step appears to the owner, and its
completion closes the awaited step.

### Day 2 Closure

A single React journey: create a definition and a path, publish both, create a
request, create a task, then close the request. Tests cover version stability,
isolation, Outbox, and reassignment.

## Day 3: W1.8–W1.10 Completing R1

### W1.8 Documents + Notifications

- A file with version, checksum, and classification, passing through quarantine
  and scanning before availability.
- Document permission does not exceed the linked resource, and sensitive
  download is logged.
- Notifications derived from Outbox with inbox, DLQ, and duplicate prevention.

Acceptance: a clean file becomes available, a rejected file stays quarantined,
and a notification worker failure does not lose the event or duplicate the
notification.

### W1.9 Search + Reporting + Dashboard

- A derived index that does not copy raw sensitive fields.
- Search, report, export, and download use the same `DecideAccess`.
- Read models are rebuildable, and one board adapts to the role and scope.

Acceptance: not even the title of a forbidden resource appears, changing scope
changes search and board results, and rebuilding the read model yields the same
result.

### W1.10 Automated Acceptance {#w1-10}

No UAT environment and no reviewers. A representative seed is created and the
journey runs the following in Arabic and English:

1. Login and scope selection.
2. Create a type and a path and publish both.
3. Create a request, submit it, return it, then complete it.
4. Create a task, attach a document, and receive a notification.
5. Search for the request and see it in the report and board within the scope
   only.

Closure: API, Web, E2E, boundaries, analysis, and build are green on a single
revision.

## Outside R1 Fast

- External integrations, mail, SMS, OCR, and electronic signatures.
- Advanced no-code editors or dozens of display templates.
- Complex Workflow voting and specialized boards per role.
- User training, real data, and actual deployment; these are post-system
  operations and do not block R2.

## Definition of R1 Completion

- W1.3–W1.9 work as one journey, not separate templates.
- The integrated W1.10 test is green in Arabic RTL and English LTR.
- Authorization, search, reports, and files do not leak data across scopes.
- Contracts, boundaries, Outbox, and version stability are proven by tests.
- The revision and Day 3 result are recorded in the active delivery status.

## Change Log

| Version | Date | Change |
|---|---|---|
| 6.2.0 | 2026-07-19 | Keep the functional R1 journey closed while reopening W1.3 on security until the fixture engine is cut over and roles and policies are applied to every consumer |
| 6.1.0 | 2026-07-19 | Execute W1.8–W1.10: documents, notifications, Search/Reporting/Dashboard, and RTL/LTR acceptance journey |
| 6.0.0 | 2026-07-19 | Convert the rest of R1 to three days and drop human approvals, UAT, and detail not needed for the core journey |
| 5.2.0 | 2026-07-18 | Pin W1.2 as complete locally |
| 5.0.0 | 2026-07-17 | Close W1.1 locally |
