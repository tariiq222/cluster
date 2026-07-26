<?php

declare(strict_types=1);

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class TechnicalAlertOutboxRelay
{
    public const EVENT_TYPE = 'com.cluster.platform.technical-alert.v1';

    public const STREAM = 'platform.technical-alert.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $events = $this->outbox->pending(
            [self::EVENT_TYPE],
            max(1, min($limit, self::MAX_BATCH_SIZE)),
        );

        $published = 0;
        foreach ($events as $event) {
            $cloudEvent = $event->payload;
            if (($cloudEvent['type'] ?? null) !== self::EVENT_TYPE || ! is_array($cloudEvent['data'] ?? null)) {
                throw new \UnexpectedValueException(sprintf(
                    'Shared Outbox event %s is not a valid technical-alert CloudEvent.',
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
