<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox\Relay;

use Illuminate\Support\Str;
use Shared\Contracts\ClaimableOutboxRelayStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Throwable;

/**
 * Single-instance relay for the shared outbox_events table.
 *
 * The relay runs XADD against the Redis stream named below, but only
 * after it has won an exclusive claim on the row. The claim carries a
 * worker id and a short lease so a crashed worker's events are
 * reclaimable by the next iteration or by the reaper, and so two relays
 * running on the same cluster cannot double-publish the same event id
 * under normal operation.
 */
final class RedisOutboxRelay
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    private const MAX_BATCH_SIZE = 100;

    private const LEASE_SECONDS = 30;

    public function __construct(
        private readonly ClaimableOutboxRelayStore $store,
        private readonly RedisStreamTransport $transport,
        private ?string $workerId = null,
    ) {}

    public function workerId(): string
    {
        if ($this->workerId === null) {
            $this->workerId = (string) Str::uuid7();
        }

        return $this->workerId;
    }

    public function reap(): int
    {
        return $this->store->reapAbandonedClaims(now());
    }

    public function relayPending(int $limit = 100): int
    {
        $this->store->reapAbandonedClaims(now());

        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $rows = $this->store->pending([self::EVENT_TYPE], $batchSize);

        $workerId = $this->workerId();
        $published = 0;
        foreach ($rows as $row) {
            if (! $this->store->claim($row->eventId, $workerId, self::LEASE_SECONDS)) {
                continue;
            }

            try {
                $this->transport->xadd(self::STREAM, [
                    'event' => json_encode($row->payload, JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable $e) {
                $this->store->release($row->eventId, $workerId);

                throw $e;
            }

            $published += (int) $this->store->markPublished($row->eventId);
        }

        return $published;
    }
}
