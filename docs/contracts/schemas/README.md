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

## Identity security events

The Identity module produces 12 security event types (e.g.
`com.cluster.identity.session_created.v1`,
`com.cluster.identity.authentication_failed.v1`) through
`IdentityOutbox::insertSecurityEvent(string $type, ...)`. The producer
passes a short suffix (e.g. `session_created`); the Identity-side
adapter `IdentitySecurityEventRegistry` (at
`apps/api/Modules/Identity/Infrastructure/Outbox/IdentitySecurityEventRegistry.php`)
maps the suffix to the matching `OutboxEventType` case and rejects
unknown suffixes with a suffix-specific error before the assembled
literal ever reaches `OutboxEventType::from`.

Routing the security-event suffix contract through a single Identity-side
registry keeps the suffix-to-event-type contract in one file rather
than letting it leak across producer call sites. The registry
contract is tested at
`tests/Unit/Shared/Infrastructure/Outbox/IdentitySecurityEventRegistryTest.php`.


1. Add a new case to `OutboxEventType` in
   `apps/api/Shared/Infrastructure/Outbox/OutboxEventType.php`.
2. Add a new `*.schema.json` file under this directory with the path
   returned by the new case's `schemaPath()` method.
3. Reference the case from the producer instead of an inline string.
4. The architecture test enforces both steps; the build fails until
   they line up.
