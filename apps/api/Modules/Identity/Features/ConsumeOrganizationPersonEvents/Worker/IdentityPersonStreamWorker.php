<?php

namespace Modules\Identity\Features\ConsumeOrganizationPersonEvents\Worker;

use InvalidArgumentException;
use JsonException;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Handler\ConsumeOrganizationPersonEventHandler;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Throwable;

final class IdentityPersonStreamWorker
{
    private const STREAMS = [
        'platform.organization.identity-provisioning-requested.v1',
        'platform.organization.person-access-status-changed.v1',
        'platform.organization.person-updated.v1',
    ];

    private const GROUP = 'identity.organization-person-events.v1';

    private const DLQ = 'platform.dlq.v1';

    private const RECLAIM_IDLE_MILLISECONDS = 60_000;

    private const MAX_ATTEMPTS = 3;

    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly RedisStreamTransport $transport,
        private readonly ConsumeOrganizationPersonEventHandler $handler,
    ) {}

    public function consumeOnce(string $consumer, int $limit = 10): int
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
            throw new InvalidArgumentException('A bounded stream consumer name is required.');
        }
        $remaining = max(1, min($limit, self::MAX_BATCH_SIZE));
        $processed = 0;
        foreach (self::STREAMS as $index => $stream) {
            if ($remaining === 0) {
                break;
            }
            $streamLimit = max(1, intdiv($remaining, count(self::STREAMS) - $index));
            $this->transport->createGroup($stream, self::GROUP);
            $pending = $this->transport->pending($stream, self::GROUP, $streamLimit);
            $ids = [];
            foreach ($pending as $entry) {
                if ($entry['idle_ms'] >= self::RECLAIM_IDLE_MILLISECONDS) {
                    $ids[] = $entry['id'];
                }
            }
            $messages = $ids === [] ? [] : $this->transport->reclaim(
                $stream,
                self::GROUP,
                $consumer,
                self::RECLAIM_IDLE_MILLISECONDS,
                array_slice($ids, 0, $streamLimit),
            );
            $newLimit = $streamLimit - count($messages);
            if ($newLimit > 0) {
                $messages = [...$messages, ...$this->transport->readGroup($stream, self::GROUP, $consumer, $newLimit)];
            }
            foreach ($messages as $message) {
                $this->process($stream, $message, $consumer);
                $processed++;
                $remaining--;
            }
        }

        return $processed;
    }

    /** @param array{id: string, fields: array<string, string>, deliveries: int} $message */
    private function process(string $stream, array $message, string $consumer): void
    {
        $event = null;
        try {
            $event = json_decode($message['fields']['event'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($event) || array_is_list($event)) {
                throw new JsonException('The stream event must decode to an object.');
            }
            $this->handler->handle($event);
        } catch (Throwable $exception) {
            if (max(1, $message['deliveries']) < self::MAX_ATTEMPTS) {
                throw $exception;
            }
            $this->transport->publishDlq(self::DLQ, $stream.'|'.$message['id'], [
                'original_event' => is_array($event) && ! array_is_list($event)
                    ? $event
                    : ['stream_id' => $message['id'], 'raw_payload' => substr($message['fields']['event'] ?? '', 0, 4096)],
                'failure_code' => $exception instanceof InvalidArgumentException ? 'INVALID_EVENT' : 'PROCESSING_FAILED',
                'attempts' => max(1, $message['deliveries']),
                'failed_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'consumer' => $consumer,
            ]);
        }
        $this->transport->ack($stream, self::GROUP, $message['id']);
    }
}
