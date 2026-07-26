<?php

declare(strict_types=1);

namespace Shared\Contracts;

interface OutboxEventLookup
{
    /** @return array<string, mixed>|null */
    public function findCloudEvent(string $aggregateId, string $eventType): ?array;
}
