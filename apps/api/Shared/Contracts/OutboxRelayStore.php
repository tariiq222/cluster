<?php

declare(strict_types=1);

namespace Shared\Contracts;

/** Relay-side persistence primitives for the Shared-owned outbox_events table. */
interface OutboxRelayStore
{
    /**
     * @param  list<string>  $eventTypes
     * @return list<PendingOutboxEvent>
     */
    public function pending(array $eventTypes, int $limit): array;

    public function recordAttempt(string $eventId): void;

    public function markPublished(string $eventId): bool;
}
