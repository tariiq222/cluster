<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Duplicate-event policy for Shared outbox writes.
 *
 * The Shared outbox adapter exposes a single write path through
 * {@see TransactionalOutboxReplayable::appendWithPolicy()}. Producers
 * choose the duplicate-handling semantic per call instead of relying on
 * raw `DB::insert()` and hoping the database-level unique constraint
 * shapes their transaction rollback behaviour.
 *
 * - `Strict`: the default for handlers that own transaction rollback
 *   and currently key off the unique-violation SQLSTATE 23000. The
 *   underlying `QueryException` propagates unchanged so existing
 *   rollback tests remain green.
 * - `Replayable`: same `event_id` with the same payload hash is a
 *   no-op (idempotent retry). Same `event_id` with a different
 *   payload hash is treated as a domain conflict and raises
 *   {@see OutboxConflictException}; HTTP surface should map this to
 *   409 Conflict.
 */
enum OutboxDuplicatePolicy: string
{
    case Strict = 'strict';
    case Replayable = 'replayable';
}
