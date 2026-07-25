# Outbox event schemas

This directory holds one JSON Schema (`*.schema.json`) per `event_type`
string produced by Cluster modules into the shared `outbox_events` table.

The single source of truth for the catalogue of event types is
`apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`; each enum
case returns the file path for its schema via `schemaPath()`. The
architecture test
`Tests\Architecture\ModuleBoundariesTest::test_every_event_type_in_outbox_has_a_matching_json_schema`
asserts that every `event_type` literal in producer code matches an enum
case and that the corresponding schema file exists here.

## Convention

Each schema file is named with the event-type value, dots replaced by
dashes. For example:

- `OutboxEventType::OrganizationClusterCreated` →
  `com-cluster-organization-clustercreated-v1.schema.json`

## Schema shape

Schemas use JSON Schema Draft 2020-12 and wrap the CloudEvents envelope
in a top-level `data` object that mirrors the event payload structure.
Each schema is a contract for consumers; producers must emit data that
matches it. When the producer and the contract disagree, the consumer
fails fast on the first malformed event.

## Adding a new event type

1. Add a new case to `OutboxEventType` in
   `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`.
2. Add a new `*.schema.json` file under this directory with the path
   returned by the new case's `schemaPath()` method.
3. Reference the case from the producer instead of an inline string.
4. The architecture test enforces both steps; the build fails until
   they line up.
