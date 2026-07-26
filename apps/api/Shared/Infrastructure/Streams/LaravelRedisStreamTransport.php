<?php

namespace Shared\Infrastructure\Streams;

use Illuminate\Redis\Connections\Connection as LaravelConnection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client as PredisClient;
use RuntimeException;
use Throwable;

final class LaravelRedisStreamTransport implements RedisStreamTransport
{
    public function __construct(private readonly LaravelConnection $connection) {}

    public function xadd(string $stream, array $fields): string
    {
        $messageId = $this->driver()->xadd($stream, $fields);

        if ($messageId === '') {
            throw new RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        try {
            $this->driver()->createGroup($stream, $group);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'BUSYGROUP')) {
                return;
            }

            throw new RuntimeException('Redis consumer group creation failed.', 0, $exception);
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        $response = $this->driver()->readGroup($stream, $group, $consumer, $limit);

        return $this->withDeliveryCounts(
            $stream,
            $group,
            $this->normalizeReadGroupResponse($stream, $response),
        );
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        $response = $this->driver()->pending($stream, $group, $limit);
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

        $response = $this->driver()->reclaim($stream, $group, $consumer, $minimumIdleMilliseconds, $messageIds);

        return $this->withDeliveryCounts(
            $stream,
            $group,
            $this->normalizeMessages($response),
        );
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->driver()->ack($stream, $group, $messageId);
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

        try {
            $messageId = $this->driver()->eval(
                $script,
                [$stream, $stream.':source-message-index'],
                [$sourceMessageId, json_encode($deadLetter, JSON_THROW_ON_ERROR)],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Redis stream operation failed.', 0, $exception);
        }

        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Redis did not return a DLQ message identifier.');
        }

        return $messageId;
    }

    public function purgeDlq(string $stream): void
    {
        try {
            $this->driver()->eval(
                "redis.call('DEL', KEYS[1], KEYS[2]); return 1",
                [$stream, $stream.':source-message-index'],
                [],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Redis stream operation failed.', 0, $exception);
        }
    }

    private function driver(): RedisStreamDriver
    {
        return match (true) {
            $this->connection instanceof PhpRedisClusterConnection => new PhpRedisClusterStreamDriver($this->connection),
            $this->connection instanceof PhpRedisConnection => new PhpRedisStreamDriver($this->connection),
            $this->connection instanceof PredisClusterConnection => new PredisClusterStreamDriver($this->connection),
            $this->connection instanceof PredisConnection => $this->predisStreamDriver($this->connection),
            default => throw new RuntimeException('Unsupported Redis connection type: '.get_class($this->connection)),
        };
    }

    private function predisStreamDriver(PredisConnection $connection): RedisStreamDriver
    {
        $client = $connection->client();
        if (! $client instanceof PredisClient) {
            throw new RuntimeException('Predis connection is missing the underlying Predis Client.');
        }

        return new PredisStreamDriver($client);
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
        $response = $this->driver()->pendingSummary($stream, $group, $messageId);
        $entry = $response[0] ?? null;

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
        // Predis reports the configured key prefix in the response key. This
        // transport requests exactly one stream, so the response slot is authoritative.
        if (! is_array($streamEntry)) {
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
