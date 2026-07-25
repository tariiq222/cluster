<?php

namespace Shared\Infrastructure\Streams;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Throwable;

/**
 * Driver adapter for PhpRedis connections (single-instance and cluster).
 *
 * PhpRedis does not honour a `prefix` option configured at the connection
 * level for stream commands called through `command()`, so we always invoke
 * the raw `Redis::*` API on the underlying \Redis client. The Laravel
 * connector applies {@code Redis::OPT_PREFIX} on the underlying client, so
 * all keys (including EVAL KEYS) are prefixed exactly once.
 */
final class PhpRedisStreamDriver implements RedisStreamDriver
{
    public function __construct(private readonly PhpRedisConnection $connection) {}

    /** @return \Redis|\RedisCluster */
    private function client(): object
    {
        $client = $this->connection->client();
        if (! $client instanceof \Redis && ! $client instanceof \RedisCluster) {
            throw new \RuntimeException('PhpRedis connection is missing the underlying Redis client.');
        }

        return $client;
    }

    public function xadd(string $stream, array $fields): string
    {
        $client = $this->client();
        $args = [];
        foreach ($fields as $name => $value) {
            $args[] = (string) $name;
            $args[] = (string) $value;
        }

        $messageId = $client->xadd($stream, '*', $args);

        if (! is_string($messageId) || $messageId === '') {
            throw new \RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        $client = $this->client();
        try {
            $client->xgroup('CREATE', $stream, $group, '0', true);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'BUSYGROUP')) {
                return;
            }

            throw $exception;
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return $this->client()->xreadgroup($group, $consumer, [$stream => '>'], $limit, 0);
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        return $this->client()->xpending($stream, $group, '-', '+', $limit);
    }

    public function pendingSummary(string $stream, string $group, string $messageId): array
    {
        return $this->client()->xpending($stream, $group, $messageId, $messageId, 1);
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return $this->client()->xclaim($stream, $group, $consumer, $minimumIdleMilliseconds, $messageIds);
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->client()->xack($stream, $group, [$messageId]);
    }

    public function eval(string $script, array $keys, array $arguments): mixed
    {
        $client = $this->client();
        $args = array_merge($keys, $arguments);

        return $client instanceof \RedisCluster
            ? $client->eval($script, $args, count($keys))
            : $client->eval($script, $args, count($keys));
    }
}
