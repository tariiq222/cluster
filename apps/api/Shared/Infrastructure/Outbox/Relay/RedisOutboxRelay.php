<?php

namespace Shared\Infrastructure\Outbox\Relay;

use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class RedisOutboxRelay
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly OutboxRelayStore $store,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $rows = $this->store->pending([self::EVENT_TYPE], $batchSize);

        $published = 0;
        foreach ($rows as $row) {
            $this->store->recordAttempt($row->eventId);
            $this->transport->xadd(self::STREAM, [
                'event' => json_encode($row->payload, JSON_THROW_ON_ERROR),
            ]);
            $published += (int) $this->store->markPublished($row->eventId);
        }

        return $published;
    }
}
