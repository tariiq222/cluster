<?php

namespace Modules\Organization\Infrastructure\Outbox\Relay;

use Illuminate\Support\Facades\DB;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class OrganizationPersonOutboxRelay
{
    private const STREAMS = [
        'com.cluster.organization.identityprovisioningrequested.v1' => 'platform.organization.identity-provisioning-requested.v1',
        'com.cluster.organization.personaccessstatuschanged.v1' => 'platform.organization.person-access-status-changed.v1',
        'com.cluster.organization.personregistered.v1' => 'platform.organization.person-registered.v1',
        'com.cluster.organization.personupdated.v1' => 'platform.organization.person-updated.v1',
    ];

    private const MAX_BATCH_SIZE = 100;

    public function __construct(private readonly RedisStreamTransport $transport) {}

    public function relayPending(int $limit = 100): int
    {
        $events = DB::table('outbox_events')
            ->whereNull('published_at')
            ->whereIn('event_type', array_keys(self::STREAMS))
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit(max(1, min($limit, self::MAX_BATCH_SIZE)))
            ->get();

        $published = 0;
        foreach ($events as $event) {
            DB::table('outbox_events')->where('event_id', $event->event_id)->whereNull('published_at')->increment('delivery_attempts');
            $cloudEvent = json_decode((string) $event->cloud_event, true, 512, JSON_THROW_ON_ERROR);
            $this->transport->xadd(self::STREAMS[$event->event_type], [
                'event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            ]);
            $published += DB::table('outbox_events')->where('event_id', $event->event_id)->whereNull('published_at')->update([
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $published;
    }
}
