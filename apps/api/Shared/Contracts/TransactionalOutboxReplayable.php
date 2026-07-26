<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Optional replay-aware write path on top of {@see TransactionalOutbox}.
 *
 * Producers that want either idempotent-replay or domain-conflict
 * semantics for outbox writes inject this narrow interface alongside
 * the base one. The concrete adapter (`Shared\Infrastructure\Outbox\
 * DatabaseTransactionalOutbox`) implements both, exposing a single
 * DB write path that branches on the per-call
 * {@see OutboxDuplicatePolicy} rather than duplicating the insert
 * logic.
 *
 * Defaults to {@see OutboxDuplicatePolicy::Strict} when omitted so
 * legacy callers stay rollback-correct.
 */
interface TransactionalOutboxReplayable
{
    /** @param array<string, mixed> $payload */
    public function appendWithPolicy(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
        OutboxDuplicatePolicy $policy = OutboxDuplicatePolicy::Strict,
    ): void;
}
