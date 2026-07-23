<?php

namespace Modules\Notifications\Features\ConsumeTechnicalAlert\Worker;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Handler\ConsumeTechnicalAlertHandler;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Throwable;

final class NotificationsTechnicalAlertWorker
{
    private const STREAM = 'platform.technical-alert.v1';

    private const GROUP = 'notifications.technical-alert.v1';

    private const DLQ = 'platform.dlq.v1';

    private const RECLAIM_IDLE_MILLISECONDS = 60_000;

    private const MAX_ATTEMPTS = 3;

    private const MAX_BATCH_SIZE = 100;

    private const MAX_MALFORMED_PAYLOAD_LENGTH = 4096;

    public function __construct(
        private readonly RedisStreamTransport $transport,
        private readonly ConsumeTechnicalAlertHandler $handler,
    ) {}

    public function consumeOnce(string $consumer, int $limit = 10): int
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
            throw new InvalidArgumentException('A bounded stream consumer name is required.');
        }
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $this->transport->createGroup(self::STREAM, self::GROUP);
        $pending = $this->transport->pending(self::STREAM, self::GROUP, $batchSize);
        $reclaimable = array_map(
            static fn (array $entry): string => $entry['id'],
            array_filter($pending, static fn (array $entry): bool => $entry['idle_ms'] >= self::RECLAIM_IDLE_MILLISECONDS),
        );
        $reclaimed = $reclaimable === [] ? [] : $this->transport->reclaim(
            self::STREAM,
            self::GROUP,
            $consumer,
            self::RECLAIM_IDLE_MILLISECONDS,
            array_slice($reclaimable, 0, $batchSize),
        );
        $messages = [...$reclaimed, ...$this->transport->readGroup(
            self::STREAM,
            self::GROUP,
            $consumer,
            max(0, $batchSize - count($reclaimed)),
        )];

        $processed = 0;
        foreach ($messages as $message) {
            $processed += $this->processMessage($message, $consumer);
        }

        return $processed;
    }

    /** @param array{id: string, fields: array<string, string>, deliveries: int} $message */
    private function processMessage(array $message, string $consumer): int
    {
        $event = null;
        try {
            $event = json_decode($message['fields']['event'], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($event) || array_is_list($event)) {
                throw new JsonException('The stream event must decode to an object.');
            }
            $this->handler->handle($event);
        } catch (Throwable $exception) {
            if (max(1, $message['deliveries']) < self::MAX_ATTEMPTS) {
                throw $exception;
            }
            $deadLetter = [
                'original_event' => ! is_array($event) || array_is_list($event)
                    ? ['stream_id' => $message['id'], 'raw_payload' => $this->boundedPayload($message['fields']['event'])]
                    : $event,
                'failure_code' => ! is_array($event) || array_is_list($event)
                    ? 'MALFORMED_EVENT'
                    : ($exception instanceof InvalidArgumentException ? 'INVALID_EVENT' : 'PROCESSING_FAILED'),
                'attempts' => max(1, $message['deliveries']),
                'failed_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'consumer' => $consumer,
            ];
            $this->persistDeadLetter($message['id'], $deadLetter);
            $this->transport->publishDlq(self::DLQ, self::STREAM.'|'.$message['id'], $deadLetter);
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

    /** @param array<string, mixed> $deadLetter */
    private function persistDeadLetter(string $messageId, array $deadLetter): void
    {
        if (! Schema::hasTable('notification_dead_letters')) {
            return;
        }
        DB::table('notification_dead_letters')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'source_stream' => self::STREAM,
            'source_message_id' => $messageId,
            'original_event' => json_encode($deadLetter['original_event'], JSON_THROW_ON_ERROR),
            'failure_code' => $deadLetter['failure_code'],
            'attempts' => $deadLetter['attempts'],
            'consumer' => $deadLetter['consumer'],
            'failed_at' => $deadLetter['failed_at'],
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
