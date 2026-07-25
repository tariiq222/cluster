<?php

namespace Shared\Infrastructure\Streams;

use Predis\Client as PredisClient;
use Throwable;

/**
 * Driver adapter for Predis cluster connections. Cluster mode requires each
 * stream key to be routed to a specific node based on the cluster keyspace,
 * so EVAL scripts and reads are dispatched via the underlying Predis cluster
 * client.
 */
final class PredisClusterStreamDriver implements RedisStreamDriver
{
    public function __construct(private readonly \Illuminate\Redis\Connections\PredisClusterConnection $connection) {}

    private function client(): PredisClient
    {
        $client = $this->connection->client();
        if (! $client instanceof PredisClient) {
            throw new \RuntimeException('Predis cluster connection is missing the underlying Predis Client.');
        }

        return $client;
    }

    public function xadd(string $stream, array $fields): string
    {
        $messageId = $this->client()->xadd($stream, $fields, '*');

        if ($messageId === '') {
            throw new \RuntimeException('Redis did not return a stream message identifier.');
        }

        return $messageId;
    }

    public function createGroup(string $stream, string $group): void
    {
        try {
            $this->client()->executeRaw(['XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM']);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'BUSYGROUP')) {
                return;
            }

            throw $exception;
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return $this->client()->xreadgroup($group, $consumer, $limit, null, false, $stream, '>');
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
        $this->client()->xack($stream, $group, $messageId);
    }

    public function eval(string $script, array $keys, array $arguments): mixed
    {
        return $this->client()->eval($script, count($keys), ...array_merge($keys, $arguments));
    }
}
