<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox\Relay;

use Shared\Contracts\ClaimableOutboxRelayStore;
use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Throwable;

/**
 * Single-instance relay for the shared outbox_events table.
 *
 * The relay runs XADD against the Redis stream named below, but only
 * after it has won an exclusive claim on the row. This stops two relays
 * running on the same cluster from double-publishing the same event id
 * under normal operation.
 *
 * CRASH-RECOVERY BLOCKER (documented): the existing schema has no
 * `lease_until` column, so a worker crash between `claim` and XADD
 * orphans the row at `delivery_attempts = 1`. The single-instance
 * deployment is the only safe option in this round. Adding a lease
 * timestamp (or a Redis SETNX lock) is the agreed follow-up.
 */
final class RedisOutboxRelay
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    private const MAX_BATCH_SIZE = 100;

    /**
     * The constructor narrows to the claimable contract so the relay
     * cannot be wired to a store that does not guarantee atomic claim.
     * The parent {@see OutboxRelayStore} contract is preserved on the
     * module relays; the Shared relay opts into the strict contract.
     */
    public function __construct(
        private readonly ClaimableOutboxRelayStore $store,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $rows = $this->store->pending([self::EVENT_TYPE], $batchSize);

        $published = 0;
        foreach ($rows as $row) {
            if (! $this->store->claim($row->eventId)) {
                continue;
            }

            try {
                $this->transport->xadd(self::STREAM, [
                    'event' => json_encode($row->payload, JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable $e) {
                $this->store->release($row->eventId);

                throw $e;
            }

            $published += (int) $this->store->markPublished($row->eventId);
        }

        return $published;
    }
}
