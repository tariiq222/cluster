<?php

namespace Shared\Infrastructure\Streams;

/**
 * Driver-port for Redis stream commands. Each Redis client (PhpRedis,
 * Predis, cluster, etc.) implements this interface so the
 * {@see LaravelRedisStreamTransport} can route stream operations through a
 * single, driver-appropriate call shape. Implementations must respect any
 * configured key prefix without double-prefixing keys.
 */
interface RedisStreamDriver
{
    public function xadd(string $stream, array $fields): string;

    public function createGroup(string $stream, string $group): void;

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array;

    public function pending(string $stream, string $group, int $limit): array;

    public function pendingSummary(string $stream, string $group, string $messageId): array;

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array;

    public function ack(string $stream, string $group, string $messageId): void;

    /** @param list<string> $keys */
    /** @param list<string> $arguments */
    public function eval(string $script, array $keys, array $arguments): mixed;
}
