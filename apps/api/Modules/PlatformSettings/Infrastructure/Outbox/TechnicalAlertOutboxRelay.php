<?php

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class TechnicalAlertOutboxRelay
{
    public const EVENT_TYPE = 'com.cluster.platform.technical-alert.v1';

    public const STREAM = 'platform.technical-alert.v1';

    private const MAX_BATCH_SIZE = 100;

    public function __construct(private readonly RedisStreamTransport $transport) {}

    public function relayPending(int $limit = 100): int
    {
        $events = DB::table('platform_settings_outbox')
            ->whereNull('published_at')
            ->where('event_type', self::EVENT_TYPE)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_BATCH_SIZE)))
            ->get();

        $published = 0;
        foreach ($events as $event) {
            /** @var array{alert_code: string, severity: string, recipient_capability: string, occurred_at: string, correlation_id: string} $payload */
            $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
            $cloudEvent = [
                'specversion' => '1.0',
                'id' => (string) $event->id,
                'source' => '/platform-settings',
                'type' => self::EVENT_TYPE,
                'subject' => '/technical-alerts/'.rawurlencode($payload['alert_code']),
                'time' => Carbon::parse($payload['occurred_at'])->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
                'datacontenttype' => 'application/json',
                'data' => $payload,
            ];
            $this->transport->xadd(self::STREAM, ['event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR)]);
            $published += DB::table('platform_settings_outbox')
                ->where('id', $event->id)
                ->whereNull('published_at')
                ->update(['published_at' => now(), 'updated_at' => now()]);
        }

        return $published;
    }
}
