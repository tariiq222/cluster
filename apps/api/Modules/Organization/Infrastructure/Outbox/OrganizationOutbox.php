<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Outbox;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Shared\Infrastructure\Outbox\OutboxEventType;

final class OrganizationOutbox
{
    /** @param array<string, mixed> $cloudEvent */
    public function insert(array $cloudEvent, string $aggregateId): void
    {
        // Validate the event type at the boundary so a producer-side
        // typo cannot silently land a string that the Redis relay
        // (and the schema catalogue) does not know about. This is a
        // cheap invariant that complements the architecture test;
        // OutboxEventType::from throws ValueError on an unknown value.
        OutboxEventType::from($cloudEvent['type']);

        DB::table('outbox_events')->insert([
            'event_id' => $cloudEvent['id'],
            'aggregate_id' => $aggregateId,
            'event_type' => $cloudEvent['type'],
            'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            'occurred_at' => (new DateTimeImmutable($cloudEvent['time']))->format('Y-m-d H:i:s'),
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
