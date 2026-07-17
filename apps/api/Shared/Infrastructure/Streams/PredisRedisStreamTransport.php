<?php

namespace Shared\Infrastructure\Streams;

use Predis\Client;
use RuntimeException;

final class PredisRedisStreamTransport implements RedisStreamTransport
{
    public function __construct(private readonly Client $client) {}

    public function xadd(string $stream, array $fields): string
    {
        $arguments = ['XADD', $stream, '*'];
        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
        }

        $messageId = $this->executeRaw($arguments);
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        $error = false;
        $response = $this->client->executeRaw(
            ['XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM'],
            $error,
        );

        if ($error && str_contains((string) $response, 'BUSYGROUP')) {
            return;
        }

        if ($error) {
            throw new RuntimeException('Redis consumer group creation failed.');
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        $response = $this->executeRaw([
            'XREADGROUP', 'GROUP', $group, $consumer,
            'COUNT', (string) $limit,
            'STREAMS', $stream, '>',
        ]);

        return $this->withDeliveryCounts(
            $stream,
            $group,
            $this->normalizeReadGroupResponse($stream, $response),
        );
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        $response = $this->executeRaw([
            'XPENDING', $stream, $group, '-', '+', (string) $limit,
        ]);

        if (! is_array($response)) {
            return [];
        }

        $pending = [];
        foreach ($response as $entry) {
            if (! is_array($entry) || count($entry) < 4 || ! is_string($entry[0] ?? null)) {
                continue;
            }

            $pending[] = [
                'id' => $entry[0],
                'consumer' => (string) ($entry[1] ?? ''),
                'idle_ms' => (int) ($entry[2] ?? 0),
                'deliveries' => max(1, (int) ($entry[3] ?? 1)),
            ];
        }

        return $pending;
    }

    public function reclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minimumIdleMilliseconds,
        array $messageIds,
    ): array {
        if ($messageIds === []) {
            return [];
        }

        $response = $this->executeRaw(array_merge([
            'XCLAIM', $stream, $group, $consumer, (string) $minimumIdleMilliseconds,
        ], $messageIds));

        return $this->withDeliveryCounts(
            $stream,
            $group,
            $this->normalizeMessages($response),
        );
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->executeRaw(['XACK', $stream, $group, $messageId]);
    }

    public function publishDlq(string $stream, string $sourceMessageId, array $deadLetter): string
    {
        $script = <<<'LUA'
local existing = redis.call('HGET', KEYS[2], ARGV[1])
if existing then
    local record = redis.call('XRANGE', KEYS[1], existing, existing)
    if #record > 0 then
        return existing
    end
    redis.call('HDEL', KEYS[2], ARGV[1])
end
local message_id = redis.call('XADD', KEYS[1], '*', 'source_message_id', ARGV[1], 'event', ARGV[2])
redis.call('HSET', KEYS[2], ARGV[1], message_id)
return message_id
LUA;
        $messageId = $this->executeRaw([
            'EVAL',
            $script,
            '2',
            $stream,
            $stream.':source-message-index',
            $sourceMessageId,
            json_encode($deadLetter, JSON_THROW_ON_ERROR),
        ]);
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Redis did not return a DLQ message identifier.');
        }

        return $messageId;
    }

    public function purgeDlq(string $stream): void
    {
        $this->executeRaw([
            'EVAL',
            "redis.call('DEL', KEYS[1], KEYS[2]); return 1",
            '2',
            $stream,
            $stream.':source-message-index',
        ]);
    }

    /** @param list<string> $arguments */
    private function executeRaw(array $arguments): mixed
    {
        $error = false;
        $response = $this->client->executeRaw($arguments, $error);
        if ($error) {
            throw new RuntimeException('Redis stream operation failed.');
        }

        return $response;
    }

    /**
     * @param  list<array{id: string, fields: array<string, string>}>  $messages
     * @return list<array{id: string, fields: array<string, string>, deliveries: int}>
     */
    private function withDeliveryCounts(string $stream, string $group, array $messages): array
    {
        return array_map(function (array $message) use ($stream, $group): array {
            $message['deliveries'] = $this->deliveryCount($stream, $group, $message['id']);

            return $message;
        }, $messages);
    }

    private function deliveryCount(string $stream, string $group, string $messageId): int
    {
        $response = $this->executeRaw([
            'XPENDING', $stream, $group, $messageId, $messageId, '1',
        ]);
        $entry = is_array($response) ? ($response[0] ?? null) : null;

        return is_array($entry) ? max(1, (int) ($entry[3] ?? 1)) : 1;
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    private function normalizeReadGroupResponse(string $stream, mixed $response): array
    {
        if (! is_array($response) || $response === []) {
            return [];
        }

        if (array_key_exists($stream, $response)) {
            return $this->normalizeMessages($response[$stream]);
        }

        $streamEntry = $response[0] ?? null;
        if (! is_array($streamEntry) || ($streamEntry[0] ?? null) !== $stream) {
            return [];
        }

        return $this->normalizeMessages($streamEntry[1] ?? []);
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    private function normalizeMessages(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $messages = [];
        foreach ($response as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $messages[] = ['id' => $key, 'fields' => $this->normalizeFields($entry)];

                continue;
            }

            if (! is_array($entry) || ! is_string($entry[0] ?? null)) {
                continue;
            }

            $messages[] = [
                'id' => $entry[0],
                'fields' => $this->normalizeFields($entry[1] ?? []),
            ];
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        if (! array_is_list($fields)) {
            return array_map(static fn (mixed $value): string => (string) $value, $fields);
        }

        $normalized = [];
        for ($index = 0; $index + 1 < count($fields); $index += 2) {
            $normalized[(string) $fields[$index]] = (string) $fields[$index + 1];
        }

        return $normalized;
    }
}
