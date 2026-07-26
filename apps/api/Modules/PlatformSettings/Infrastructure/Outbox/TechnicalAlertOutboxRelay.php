<?php

declare(strict_types=1);

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Illuminate\Support\Carbon;
use Shared\Contracts\PendingOutboxStore;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class TechnicalAlertOutboxRelay
{
    public const EVENT_TYPE = 'com.cluster.platform.technical-alert.v1';

    public const STREAM = 'platform.technical-alert.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly PendingOutboxStore $outbox,
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
            /** @var array{alert_code: string, severity: string, recipient_capability: string, occurred_at: string, correlation_id: string} $payload */
            $payload = $event->payload;
            $cloudEvent = [
                'specversion' => '1.0',
                'id' => $event->eventId,
                'source' => '/platform-settings',
                'type' => self::EVENT_TYPE,
                'subject' => '/technical-alerts/'.rawurlencode($payload['alert_code']),
                'time' => Carbon::parse($payload['occurred_at'])->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
                'datacontenttype' => 'application/json',
                'data' => $payload,
            ];
            $this->transport->xadd(self::STREAM, ['event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR)]);

            if ($this->outbox->markPublished($event->eventId)) {
                $published++;
            }
        }

        return $published;
    }
}
