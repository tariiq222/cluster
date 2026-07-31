<?php

namespace Modules\Notifications\Features\Worker;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Throwable;

/**
 * Shared bounded stream consumption loop for Notifications workers: reclaim
 * stale pending entries, read one bounded batch, process each message with
 * the owning handler, persist a reviewable dead letter after MAX_ATTEMPTS,
 * and acknowledge only after the effect is durable.
 */
abstract class AbstractStreamWorker
{
    protected const DLQ = 'platform.dlq.v1';

    private const RECLAIM_IDLE_MILLISECONDS = 60_000;

    private const MAX_ATTEMPTS = 3;

    private const MAX_BATCH_SIZE = 100;

    private const MAX_MALFORMED_PAYLOAD_LENGTH = 4096;

    public function __construct(
        protected readonly RedisStreamTransport $transport,
    ) {}

    abstract protected function stream(): string;

    abstract protected function group(): string;

    /** @param array<string, mixed> $event */
    abstract protected function handleEvent(array $event): void;

    public function consumeOnce(string $consumer, int $limit = 10): int
    {
        $this->validateConsumer($consumer);
        $batchSize = max(1, min($limit, self::MAX_BATCH_SIZE));
        $this->transport->createGroup($this->stream(), $this->group());

        $pending = $this->transport->pending($this->stream(), $this->group(), $batchSize);
        $reclaimable = array_map(
            static fn (array $entry): string => $entry['id'],
            array_filter($pending, static fn (array $entry): bool => $entry['idle_ms'] >= self::RECLAIM_IDLE_MILLISECONDS),
        );
        $reclaimed = $reclaimable === [] ? [] : $this->transport->reclaim(
            $this->stream(),
            $this->group(),
            $consumer,
            self::RECLAIM_IDLE_MILLISECONDS,
            array_slice($reclaimable, 0, $batchSize),
        );
        $messages = [...$reclaimed, ...$this->transport->readGroup(
            $this->stream(),
            $this->group(),
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
        $attempts = max(1, $message['deliveries']);

        try {
            $event = json_decode(
                $message['fields']['event'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (! is_array($event) || array_is_list($event)) {
                throw new JsonException('The stream event must decode to an object.');
            }

            $this->handleEvent($event);
        } catch (Throwable $exception) {
            if ($attempts < self::MAX_ATTEMPTS) {
                throw $exception;
            }

            $isMalformed = ! is_array($event) || array_is_list($event);
            $deadLetter = [
                'original_event' => $isMalformed
                    ? [
                        'stream_id' => $message['id'],
                        'raw_payload' => $this->boundedPayload($message['fields']['event']),
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
            $this->persistDeadLetter($this->stream(), $message['id'], $deadLetter);
            $this->transport->publishDlq(self::DLQ, $this->stream().'|'.$message['id'], $deadLetter);
        }

        $this->transport->ack($this->stream(), $this->group(), $message['id']);

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

    /** @param array<string,mixed> $deadLetter */
    private function persistDeadLetter(string $stream, string $messageId, array $deadLetter): void
    {
        if (! Schema::hasTable('notification_dead_letters')) {
            return;
        }
        DB::table('notification_dead_letters')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'source_stream' => $stream,
            'source_message_id' => $messageId,
            'original_event' => json_encode($deadLetter['original_event'], JSON_THROW_ON_ERROR),
            'failure_code' => (string) $deadLetter['failure_code'],
            'attempts' => (int) $deadLetter['attempts'],
            'consumer' => (string) $deadLetter['consumer'],
            'failed_at' => $deadLetter['failed_at'],
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
