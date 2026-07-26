<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Typed relay view of an outbox row.
 *
 * @phpstan-type Payload array<string, mixed>
 */
final readonly class PendingOutboxEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $payload,
    ) {}
}
