<?php

namespace Shared\Infrastructure\Streams;

use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Throwable;

/**
 * Driver adapter for PhpRedis cluster connections. The underlying client is
 * \RedisCluster, which exposes the same stream command surface as \Redis but
 * routes the call to the correct node based on the cluster hashslot.
 */
final class PhpRedisClusterStreamDriver implements RedisStreamDriver
{
    public function __construct(private readonly PhpRedisClusterConnection $connection) {}

    private function client(): \RedisCluster
    {
        $client = $this->connection->client();
        if (! $client instanceof \RedisCluster) {
            throw new \RuntimeException('PhpRedis cluster connection is missing the underlying RedisCluster client.');
        }

        return $client;
    }

    public function xadd(string $stream, array $fields): string
    {
        $args = [];
        foreach ($fields as $name => $value) {
            $args[] = (string) $name;
            $args[] = (string) $value;
        }

        $messageId = $this->client()->xadd($stream, '*', $args);

        if (! is_string($messageId) || $messageId === '') {
            throw new \RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        try {
            $this->client()->xgroup('CREATE', $stream, $group, '0', true);
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
        return $this->client()->xpending($stream, $group, null, '-', '+', $limit);
    }

    public function pendingSummary(string $stream, string $group, string $messageId): array
    {
        return $this->client()->xpending($stream, $group, null, $messageId, $messageId, 1);
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
        return $this->client()->eval($script, array_merge($keys, $arguments), count($keys));
    }
}