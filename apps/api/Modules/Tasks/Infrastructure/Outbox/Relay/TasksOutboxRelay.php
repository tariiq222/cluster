<?php

declare(strict_types=1);

namespace Modules\Tasks\Infrastructure\Outbox\Relay;

use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Outbox\OutboxEventType;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class TasksOutboxRelay
{
    public const STREAM = 'platform.tasks-events.v1';

    private const MAX_BATCH_SIZE = 100;

    private const EVENT_TYPES = [
        OutboxEventType::TaskCreated->value,
        OutboxEventType::TaskAssigned->value,
        OutboxEventType::TaskStarted->value,
        OutboxEventType::TaskBlocked->value,
        OutboxEventType::TaskUnblocked->value,
        OutboxEventType::TaskCompleted->value,
        OutboxEventType::TaskCancelled->value,
    ];

    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = self::MAX_BATCH_SIZE): int
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
                    'Shared Outbox event %s is not a valid Task CloudEvent.',
                    $event->eventId,
                ));
            }

            $this->outbox->recordAttempt($event->eventId);
            $this->transport->xadd(self::STREAM, [
                'event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            ]);
            if ($this->outbox->markPublished($event->eventId)) {
                $published++;
            }
        }

        return $published;
    }
}
