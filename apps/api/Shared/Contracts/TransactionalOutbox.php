<?php

namespace Shared\Contracts;

interface TransactionalOutbox
{
    /** @param array<string, mixed> $payload */
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void;
}
