<?php

declare(strict_types=1);

namespace Shared\Contracts;

/** Persistence primitives for a module-owned outbox without attempt tracking. */
interface PendingOutboxStore
{
    /**
     * @param  list<string>  $eventTypes
     * @return list<PendingOutboxEvent>
     */
    public function pending(array $eventTypes, int $limit): array;

    public function markPublished(string $eventId): bool;
}
