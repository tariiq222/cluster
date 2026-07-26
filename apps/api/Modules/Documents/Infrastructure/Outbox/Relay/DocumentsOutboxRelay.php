<?php

declare(strict_types=1);

namespace Modules\Documents\Infrastructure\Outbox\Relay;

use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Outbox\OutboxEventType;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class DocumentsOutboxRelay
{
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly RedisStreamTransport $transport,
    ) {}

    public function relayPending(int $limit = 100): int
    {
        $streams = $this->streams();
        $events = $this->outbox->pending(array_keys($streams), max(1, min($limit, self::MAX_BATCH_SIZE)));
        $published = 0;
        foreach ($events as $event) {
            $this->outbox->recordAttempt($event->eventId);
            $this->transport->xadd($streams[$event->eventType], [
                'event' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            ]);
            $published += (int) $this->outbox->markPublished($event->eventId);
        }

        return $published;
    }

    /** @return array<string, string> */
    private function streams(): array
    {
        $types = [
            OutboxEventType::DocumentCreated,
            OutboxEventType::DocumentUploadInitiated,
            OutboxEventType::DocumentVersionUploaded,
            OutboxEventType::DocumentVersionRejected,
            OutboxEventType::DocumentVersionQuarantined,
            OutboxEventType::DocumentVersionPromotionRequested,
            OutboxEventType::DocumentVersionAvailable,
            OutboxEventType::DocumentMetadataUpdated,
            OutboxEventType::DocumentLinked,
            OutboxEventType::DocumentLifecycleTransitioned,
            OutboxEventType::DocumentGrantIssued,
        ];

        $streams = [];
        foreach ($types as $type) {
            $streams[$type->value] = $type->streamName();
        }

        return $streams;
    }
}
