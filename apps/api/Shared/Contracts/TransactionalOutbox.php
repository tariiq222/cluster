<?php

namespace Shared\Contracts;

/**
 * Single-method outbox contract.
 *
 * Implementations MUST be the only writer to the shared `outbox_events`
 * table; producer modules inject this interface (or its narrow siblings
 * {@see TransactionalOutboxReplayable} / {@see TransactionalOutboxReader})
 * and never touch the table directly. The architecture test
 * `tests/Architecture/ModuleBoundariesTest` enforces this boundary by
 * flagging any producer-module `DB::table('outbox_events')` call as a
 * cross-owner SQL reference.
 *
 * The default semantics of `append()` are **strict**: a duplicate
 * `event_id` surfaces the underlying unique-constraint violation so the
 * surrounding transaction rolls back. Producers that want replay-safe
 * behaviour inject {@see TransactionalOutboxReplayable} instead and
 * pass {@see OutboxDuplicatePolicy::Replayable} per call.
 */
interface TransactionalOutbox
{
    /** @param array<string, mixed> $payload */
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void;
}
