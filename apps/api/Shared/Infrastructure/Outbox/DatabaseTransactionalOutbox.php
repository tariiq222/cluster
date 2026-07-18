<?php

namespace Shared\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Shared\Contracts\TransactionalOutbox;

final class DatabaseTransactionalOutbox implements TransactionalOutbox
{
    /** @param array<string, mixed> $payload */
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
    {
        $occurredAt = now();
        DB::table('outbox_events')->insertOrIgnore([
            'event_id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'cloud_event' => json_encode([
                'specversion' => '1.0',
                'id' => $eventId,
                'source' => '/'.$eventType,
                'type' => $eventType,
                'subject' => '/'.$aggregateId,
                'time' => $occurredAt->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
                'datacontenttype' => 'application/json',
                'data' => $payload,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
