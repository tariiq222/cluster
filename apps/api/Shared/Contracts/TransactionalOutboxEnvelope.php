<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Envelope-passthrough write path for producers that already build the
 * CloudEvent document themselves (`correlationid`, custom extensions,
 * deterministic `time`, etc.).
 *
 * Steps 4 and 5 of the architecture plan collapse multiple module-level
 * `DB::table('outbox_events')->insert(...)` call sites onto this
 * single contract surface. The concrete adapter persists the
 * caller's envelope **byte-for-byte** (JSON-encoded under
 * JSON_THROW_ON_ERROR) and uses the caller's `occurred_at` for the
 * matching timestamp column, so producer tests that read back fields
 * like `correlationid` or assert on the stored `time` keep working
 * after the refactor. Behaviour on `event_id` collision is governed
 * by {@see OutboxDuplicatePolicy}.
 *
 * Defaults to {@see OutboxDuplicatePolicy::Strict} so transaction
 * rollback flows keyed on the underlying unique-constraint exception
 * remain unchanged.
 */
interface TransactionalOutboxEnvelope
{
    /** @param array<string, mixed> $cloudEvent */
    public function appendEnvelope(
        string $eventId,
        string $aggregateId,
        array $cloudEvent,
        string $occurredAt,
        ?string $auditAt = null,
        OutboxDuplicatePolicy $policy = OutboxDuplicatePolicy::Strict,
    ): void;
}
