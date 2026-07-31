<?php

declare(strict_types=1);

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;

/**
 * Relays the PlatformSettings event families that must reach a stream:
 * version-published (fanout to downstream consumers) and operation
 * requests (backup / restore validation) that notify operators. Without
 * this relay the events would sit in outbox_events forever, marked neither
 * delivered nor retryable.
 */
final class PlatformSettingsOutboxRelay
{
    public const EVENT_TYPES = [
        'com.cluster.platform-settings.version-published.v1',
        'com.cluster.platform-operations.backup-requested.v1',
        'com.cluster.platform-operations.restore_validation-requested.v1',
    ];

    public const STREAM = 'platform.settings-events.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $events = $this->outbox->pending(
            self::EVENT_TYPES,
            max(1, min($limit, self::MAX_BATCH_SIZE)),
        );

        $published = 0;
        foreach ($events as $event) {
            $cloudEvent = $event->payload;
            if (! in_array($cloudEvent['type'] ?? null, self::EVENT_TYPES, true) || ! is_array($cloudEvent['data'] ?? null)) {
                throw new \UnexpectedValueException(sprintf(
                    'Shared Outbox event %s is not a valid PlatformSettings CloudEvent.',
                    $event->eventId,
                ));
            }
            $this->transport->xadd(self::STREAM, ['event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR)]);

            if ($this->outbox->markPublished($event->eventId)) {
                $published++;
            }
        }

        return $published;
    }
}
