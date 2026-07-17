<?php

namespace Modules\WorkRecords\Infrastructure\Outbox\Relay;

use Illuminate\Support\Facades\DB;
use Shared\Infrastructure\Streams\ValkeyStreamTransport;

final class ValkeyOutboxRelay
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(private readonly ValkeyStreamTransport $transport) {}

    public function relayPending(int $limit = 100): int
    {
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $events = DB::table('outbox_events')
            ->whereNull('published_at')
            ->where('event_type', self::EVENT_TYPE)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit($batchSize)
            ->get();

        $published = 0;
        foreach ($events as $event) {
            DB::table('outbox_events')
                ->where('event_id', $event->event_id)
                ->whereNull('published_at')
                ->increment('delivery_attempts');

            $cloudEvent = json_decode((string) $event->cloud_event, true, 512, JSON_THROW_ON_ERROR);
            $this->transport->xadd(self::STREAM, [
                'event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            ]);

            $marked = DB::table('outbox_events')
                ->where('event_id', $event->event_id)
                ->whereNull('published_at')
                ->update([
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);
            $published += $marked;
        }

        return $published;
    }
}
