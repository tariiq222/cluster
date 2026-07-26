<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Outbox\Relay;

use Shared\Contracts\OutboxRelayStore;
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

    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $events = $this->outbox->pending(
            array_keys(self::STREAMS),
            max(1, min($limit, self::MAX_BATCH_SIZE)),
        );

        $published = 0;
        foreach ($events as $event) {
            $this->outbox->recordAttempt($event->eventId);
            $this->transport->xadd(self::STREAMS[$event->eventType], [
                'event' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            ]);

            if ($this->outbox->markPublished($event->eventId)) {
                $published++;
            }
        }

        return $published;
    }
}
