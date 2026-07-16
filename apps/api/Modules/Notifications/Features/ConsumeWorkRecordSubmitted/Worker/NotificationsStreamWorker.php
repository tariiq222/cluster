<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker;

use InvalidArgumentException;
use JsonException;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Shared\Infrastructure\Streams\ValkeyStreamTransport;
use Throwable;

final class NotificationsStreamWorker
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const GROUP = 'notifications.work-record-submitted.v1';

    private const DLQ = 'platform.dlq.v1';

    private const RECLAIM_IDLE_MILLISECONDS = 60_000;

    private const MAX_ATTEMPTS = 3;

    private const MAX_BATCH_SIZE = 100;

    private const MAX_MALFORMED_PAYLOAD_LENGTH = 4096;

    public function __construct(
        private readonly ValkeyStreamTransport $transport,
        private readonly ConsumeWorkRecordSubmittedHandler $handler,
    ) {}

    public function consumeOnce(string $consumer, int $limit = 10): int
    {
        $this->validateConsumer($consumer);
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $this->transport->createGroup(self::STREAM, self::GROUP);

        $pending = $this->transport->pending(self::STREAM, self::GROUP, $batchSize);
        $reclaimableIds = [];
        foreach ($pending as $entry) {
            if (($entry['idle_ms'] ?? 0) >= self::RECLAIM_IDLE_MILLISECONDS
                && is_string($entry['id'] ?? null)) {
                $reclaimableIds[] = $entry['id'];
            }
        }

        $reclaimableIds = array_slice($reclaimableIds, 0, $batchSize);
        $reclaimed = $reclaimableIds === []
            ? []
            : $this->transport->reclaim(
                self::STREAM,
                self::GROUP,
                $consumer,
                self::RECLAIM_IDLE_MILLISECONDS,
                $reclaimableIds,
            );
        $remaining = max(0, $batchSize - count($reclaimed));
        $newMessages = $remaining > 0
            ? $this->transport->readGroup(self::STREAM, self::GROUP, $consumer, $remaining)
            : [];

        $processed = 0;
        foreach (array_merge($reclaimed, $newMessages) as $message) {
            $processed += $this->processMessage($message, $consumer);
        }

        return $processed;
    }

    /** @param array{id: string, fields: array<string, string>, deliveries: int} $message */
    private function processMessage(array $message, string $consumer): int
    {
        $event = null;
        $attempts = max(1, (int) ($message['deliveries'] ?? 1));

        try {
            $event = json_decode(
                $message['fields']['event'] ?? '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (! is_array($event) || array_is_list($event)) {
                throw new JsonException('The stream event must decode to an object.');
            }

            $this->handler->handle($event);
        } catch (Throwable $exception) {
            if ($attempts < self::MAX_ATTEMPTS) {
                throw $exception;
            }

            $isMalformed = ! is_array($event) || array_is_list($event);
            $deadLetter = [
                'original_event' => $isMalformed
                    ? [
                        'stream_id' => (string) ($message['id'] ?? ''),
                        'raw_payload' => $this->boundedPayload($message['fields']['event'] ?? ''),
                    ]
                    : $event,
                'failure_code' => $isMalformed
                    ? 'MALFORMED_EVENT'
                    : ($exception instanceof InvalidArgumentException ? 'INVALID_EVENT' : 'PROCESSING_FAILED'),
                'attempts' => $attempts,
                'failed_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'consumer' => $consumer,
            ];

            // Do not acknowledge until the reviewable DLQ record is durable.
            $this->transport->publishDlq(self::DLQ, $deadLetter);
        }

        $this->transport->ack(self::STREAM, self::GROUP, $message['id']);

        return 1;
    }

    private function boundedPayload(mixed $payload): string
    {
        $payload = is_string($payload) ? $payload : (string) json_encode($payload);

        return strlen($payload) > self::MAX_MALFORMED_PAYLOAD_LENGTH
            ? substr($payload, 0, self::MAX_MALFORMED_PAYLOAD_LENGTH - 3).'...'
            : $payload;
    }

    private function validateConsumer(string $consumer): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
            throw new InvalidArgumentException('A bounded stream consumer name is required.');
        }
    }
}
