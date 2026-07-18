<?php

namespace Modules\Identity\Infrastructure\Outbox;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class IdentityOutbox
{
    /** @param array<string, mixed> $cloudEvent */
    public function insert(array $cloudEvent, string $aggregateId): void
    {
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
